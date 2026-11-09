<?php
/**
 * The sign-in engine: four AJAX steps.
 *
 *   start    a phone arrives. An account (or past orders) on it: a code goes
 *            out. A number the shop has never seen: the details step.
 *   register first name, last name, email, consent. The details wait while a
 *            code goes out; the account opens only when the code comes back,
 *            so nobody can open an account on somebody else's number.
 *   verify   the six digits. Right: signed in (the account opened if it was
 *            waiting), and the page goes on to the checkout filled in.
 *   resend   another code, after the cooldown.
 *
 * Codes are random_int, kept only as a hash, die after code_expiry seconds or
 * max_attempts wrong tries. Every send is rate-limited per phone, per IP and
 * per day (a message is money), and counted only once it really went out.
 *
 * Ported from the oc-theme Auth engine (PHP 8.1) to PHP 7.4, with the Deliz
 * checkout flow's behaviour: returning guest customers are recognised by their
 * past orders and their account opens from the last one.
 *
 * @package OC_SMS_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Auth {

	const NONCE = 'ocsl';

	public static function boot() {
		foreach ( array( 'start', 'register', 'verify', 'resend' ) as $step ) {
			add_action( 'wp_ajax_nopriv_ocsl_' . $step, array( __CLASS__, $step ) );
			add_action( 'wp_ajax_ocsl_' . $step, array( __CLASS__, $step ) );
		}
		add_action( 'init', array( __CLASS__, 'handoff_landing' ), 1 );
	}

	/* ---------------- Guards ---------------- */

	private static function guard() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			self::fail( 'הדף לא עודכן זמן רב. רעננו אותו ונסו שוב.' );
		}
		if ( is_user_logged_in() ) {
			wp_send_json_success(
				array(
					'step' => 'done',
					'to'   => self::target(),
				)
			);
		}
	}

	/**
	 * @param string $msg  The message for the visitor.
	 * @param array  $more Extra fields.
	 */
	private static function fail( $msg, array $more = array() ) {
		wp_send_json_error( array_merge( array( 'msg' => $msg ), $more ) );
	}

	/** Where to go once signed in: the page the visitor asked for, if it is ours. */
	private static function target() {
		$to = isset( $_POST['to'] ) ? esc_url_raw( wp_unslash( $_POST['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		return '' !== $to ? wp_validate_redirect( $to, '' ) : '';
	}

	private static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	private static function phone_from_post() {
		return OCSL_Phone::normalize( isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() checked it; normalize() keeps digits only.
	}

	/* ---------------- Rate limits ---------------- */

	/**
	 * May a code go to this phone now? '' when yes, the reason when no. A check only: nothing is
	 * spent until the message really went out (note_send()).
	 *
	 * @param string $phone Canonical number.
	 * @return string
	 */
	private static function may_send( $phone ) {
		if ( get_transient( 'ocsl_cool_' . $phone ) ) {
			return 'הקוד נשלח הרגע. חכו כמה שניות לפני שמבקשים שוב.';
		}
		if ( 'test' === OCSL_Sender::transport() ) {
			return ''; // No message leaves the site and the code is on screen: the caps would only block testing.
		}
		$per_phone = self::counted( 'ocsl_ph_' . $phone );
		$per_ip    = self::counted( 'ocsl_ip_' . md5( self::ip() ) );
		if ( $per_phone >= (int) OCSL_Settings::get( 'phone_hourly' ) || $per_ip >= (int) OCSL_Settings::get( 'ip_hourly' ) ) {
			return 'יותר מדי ניסיונות. נסו שוב בעוד שעה בערך.';
		}
		$day = self::day();
		if ( $day['n'] >= (int) OCSL_Settings::get( 'daily_cap' ) ) {
			if ( empty( $day['told'] ) ) {
				$day['told'] = 1;
				update_option( 'ocsl_day', $day, false );
				wp_mail(
					(string) get_option( 'admin_email' ),
					'OC SMS Login: הגעתם לתקרה היומית של הודעות',
					sprintf( 'תקרת ההודעות היומית (%d) הושגה, ושליחת קודים נעצרה עד חצות. אם זו תנועה אמיתית, אפשר להעלות את התקרה בהגדרות התוסף.', (int) OCSL_Settings::get( 'daily_cap' ) )
				);
			}
			return 'הכניסה עם SMS לא זמינה כרגע. נסו שוב מאוחר יותר.';
		}
		return '';
	}

	/** @return array{d:string,n:int} today's counter */
	private static function day() {
		$day = get_option( 'ocsl_day', array() );
		$now = current_time( 'Y-m-d' );
		if ( ! is_array( $day ) || ! isset( $day['d'] ) || $day['d'] !== $now ) {
			$day = array(
				'd' => $now,
				'n' => 0,
			);
		}
		return $day;
	}

	/**
	 * Count one message that really went out.
	 *
	 * @param string $phone Canonical number.
	 */
	private static function note_send( $phone ) {
		set_transient( 'ocsl_cool_' . $phone, 1, (int) OCSL_Settings::get( 'resend_cooldown' ) );
		if ( 'test' === OCSL_Sender::transport() ) {
			return; // Test codes are not messages: they count toward no cap.
		}

		$day = self::day();
		++$day['n'];
		update_option( 'ocsl_day', $day, false );

		self::bump( 'ocsl_ph_' . $phone );
		self::bump( 'ocsl_ip_' . md5( self::ip() ) );
	}

	/**
	 * Sends counted under this key in its current hour.
	 *
	 * @param string $key Transient name.
	 * @return int
	 */
	private static function counted( $key ) {
		$v = get_transient( $key );
		return is_array( $v ) ? (int) $v['n'] : (int) $v;
	}

	/**
	 * Count one more send. The hour starts at the first send and does not move with later ones,
	 * so a blocked number opens again an hour after its first code, not after its last.
	 *
	 * @param string $key Transient name.
	 */
	private static function bump( $key ) {
		$now = time();
		$v   = get_transient( $key );
		if ( ! is_array( $v ) || empty( $v['until'] ) || (int) $v['until'] <= $now ) {
			$v = array(
				'n'     => 0,
				'until' => $now + HOUR_IN_SECONDS,
			);
		}
		++$v['n'];
		set_transient( $key, $v, max( 1, (int) $v['until'] - $now ) );
	}

	/* ---------------- The code ---------------- */

	/**
	 * Mint a code for this phone, keep only its hash, and send it.
	 *
	 * @param string $phone Canonical number.
	 * @return array|WP_Error The answer fields on success.
	 */
	private static function issue( $phone ) {
		$why = self::may_send( $phone );
		if ( '' !== $why ) {
			return new WP_Error( 'ocsl_rate', $why );
		}
		$code = (string) random_int( 100000, 999999 );
		$sent = OCSL_Sender::send( $phone, $code );
		if ( is_wp_error( $sent ) ) {
			return new WP_Error( 'ocsl_send', 'ההודעה לא נשלחה. נסו שוב בעוד רגע.' );
		}
		set_transient(
			'ocsl_code_' . $phone,
			array(
				'h' => wp_hash( $code . '|' . $phone ),
				'a' => 0,
			),
			(int) OCSL_Settings::get( 'code_expiry' )
		);
		self::note_send( $phone );

		$out = array(
			'step'   => 'code',
			'pretty' => OCSL_Phone::pretty( $phone ),
			'wait'   => (int) OCSL_Settings::get( 'resend_cooldown' ),
		);
		if ( 'test' === OCSL_Sender::transport() ) {
			$out['test_code'] = $code;
		}
		return $out;
	}

	/* ---------------- Steps ---------------- */

	public static function start() {
		self::guard();
		$phone = self::phone_from_post();
		if ( '' === $phone ) {
			self::fail( 'זה לא נראה כמו מספר נייד ישראלי. נסו שוב, למשל 052-1234567.' );
		}
		if ( ! OCSL_Sender::available() ) {
			self::fail( 'הכניסה עם SMS לא זמינה כרגע.', array( 'unavailable' => 1 ) );
		}

		$known = (bool) OCSL_Phone::user( $phone );
		if ( ! $known && OCSL_Settings::get( 'from_orders' ) ) {
			$known = (bool) OCSL_Phone::last_order( $phone );
		}
		if ( ! $known && ! is_array( get_transient( 'ocsl_pend_' . $phone ) ) ) {
			wp_send_json_success(
				array(
					'step'   => 'register',
					'pretty' => OCSL_Phone::pretty( $phone ),
				)
			);
		}

		$res = self::issue( $phone );
		if ( is_wp_error( $res ) ) {
			self::fail( $res->get_error_message() );
		}
		wp_send_json_success( $res );
	}

	public static function register() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$hp = isset( $_POST['website'] ) ? trim( (string) wp_unslash( $_POST['website'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared to ''.
		$ts = isset( $_POST['ts'] ) ? absint( $_POST['ts'] ) : 0;
		if ( '' !== $hp || ( time() - $ts ) < 3 ) {
			self::fail( 'משהו השתבש. רעננו את הדף ונסו שוב.' );
		}

		$phone = self::phone_from_post();
		$first = isset( $_POST['first'] ) ? sanitize_text_field( wp_unslash( $_POST['first'] ) ) : '';
		$last  = isset( $_POST['last'] ) ? sanitize_text_field( wp_unslash( $_POST['last'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$agree = ! empty( $_POST['consent'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $phone ) {
			self::fail( 'זה לא נראה כמו מספר נייד ישראלי.' );
		}
		if ( '' === $first || '' === $last || ! is_email( $email ) ) {
			self::fail( 'צריך שם פרטי, שם משפחה ואימייל תקין.' );
		}
		if ( ! $agree ) {
			self::fail( 'צריך לאשר את מדיניות הפרטיות כדי להמשיך.' );
		}
		if ( OCSL_Phone::user( $phone ) ) {
			self::fail( 'המספר הזה כבר רשום. התחברו איתו במקום להירשם.', array( 'step' => 'phone' ) );
		}
		if ( email_exists( $email ) ) {
			// Linking a new number to an existing account by its email would let anyone take that
			// account over with their own phone. The owner signs in the way the account knows.
			self::fail( 'האימייל הזה כבר שייך לחשבון קיים. התחברו עם מספר הטלפון ששמור בו, או עם האימייל והסיסמה בעמוד החשבון שלי.' );
		}
		if ( ! OCSL_Sender::available() ) {
			self::fail( 'הכניסה עם SMS לא זמינה כרגע.', array( 'unavailable' => 1 ) );
		}

		$res = self::issue( $phone );
		if ( is_wp_error( $res ) ) {
			self::fail( $res->get_error_message() );
		}
		set_transient(
			'ocsl_pend_' . $phone,
			array(
				'first' => $first,
				'last'  => $last,
				'email' => $email,
			),
			max( 15 * MINUTE_IN_SECONDS, (int) OCSL_Settings::get( 'code_expiry' ) )
		);
		wp_send_json_success( $res );
	}

	public static function resend() {
		self::guard();
		$phone = self::phone_from_post();
		if ( '' === $phone ) {
			self::fail( 'זה לא נראה כמו מספר נייד ישראלי.' );
		}
		$known = OCSL_Phone::user( $phone ) || is_array( get_transient( 'ocsl_pend_' . $phone ) )
			|| ( OCSL_Settings::get( 'from_orders' ) && OCSL_Phone::last_order( $phone ) );
		if ( ! $known ) {
			self::fail( 'נתחיל מההתחלה.', array( 'step' => 'phone' ) );
		}
		$res = self::issue( $phone );
		if ( is_wp_error( $res ) ) {
			self::fail( $res->get_error_message() );
		}
		wp_send_json_success( $res );
	}

	public static function verify() {
		self::guard();
		$phone = self::phone_from_post();
		$code  = isset( $_POST['code'] ) ? preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() checked it; digits only.
		$kept  = '' === $phone ? false : get_transient( 'ocsl_code_' . $phone );

		if ( ! is_array( $kept ) ) {
			self::fail( 'פג תוקף הקוד. בקשו קוד חדש.', array( 'expired' => 1 ) );
		}
		if ( ! hash_equals( (string) $kept['h'], wp_hash( $code . '|' . $phone ) ) ) {
			++$kept['a'];
			if ( $kept['a'] >= (int) OCSL_Settings::get( 'max_attempts' ) ) {
				delete_transient( 'ocsl_code_' . $phone );
				self::fail( 'יותר מדי ניסיונות. בקשו קוד חדש.', array( 'expired' => 1 ) );
			}
			set_transient( 'ocsl_code_' . $phone, $kept, (int) OCSL_Settings::get( 'code_expiry' ) );
			self::fail( 'הקוד לא נכון. בדקו שוב את ההודעה.' );
		}
		delete_transient( 'ocsl_code_' . $phone );

		$user_id = 0;
		$user    = OCSL_Phone::user( $phone );
		if ( $user ) {
			$user_id = (int) $user->ID;
			if ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) ) {
				// Shop staff sign in through the private login, never through a number anyone may type.
				self::fail( 'לחשבון הזה נכנסים מעמוד הכניסה של הניהול.' );
			}
		} else {
			$pending = get_transient( 'ocsl_pend_' . $phone );
			$order   = OCSL_Settings::get( 'from_orders' ) ? OCSL_Phone::last_order( $phone ) : null;
			if ( is_array( $pending ) || $order ) {
				$user_id = self::open_account( $phone, is_array( $pending ) ? $pending : array(), $order );
				delete_transient( 'ocsl_pend_' . $phone );
				if ( ! $user_id ) {
					self::fail( 'לא הצלחנו לפתוח חשבון. נסו שוב, או השלימו את ההזמנה כאורחים.' );
				}
			} else {
				self::fail( 'נתחיל מההתחלה.', array( 'step' => 'phone' ) );
			}
		}

		update_user_meta( $user_id, OCSL_Phone::META, $phone );
		self::fill_from_last_order( $user_id, $phone );
		self::sign_in( $user_id );

		/**
		 * A shopper proved this phone with a code and is signed in.
		 *
		 * @param int    $user_id The account.
		 * @param string $phone   The verified number, 05XXXXXXXX.
		 */
		do_action( 'ocsl_phone_verified', $user_id, $phone );

		wp_send_json_success(
			array(
				'step'    => 'done',
				'to'      => self::target(),
				'handoff' => self::handoff( $user_id ),
			)
		);
	}

	/* ---------------- The handoff ---------------- */

	const HANDOFF     = 'ocsl_login';
	const HANDOFF_TTL = 120;

	/**
	 * A one-time sign-in token for the browser that just proved the code. The AJAX answer already
	 * carries the login cookie, but a cookie set by a background request is the one some browsers
	 * and privacy settings drop: the page the browser goes to next sets it again, in a normal page
	 * load. Single use, two minutes, kept only as a hash.
	 *
	 * @param int $user_id The account.
	 * @return string
	 */
	private static function handoff( $user_id ) {
		$token = bin2hex( random_bytes( 20 ) );
		set_transient( 'ocsl_ho_' . hash( 'sha256', $token ), (int) $user_id, self::HANDOFF_TTL );
		return $token;
	}

	/**
	 * A page load that carries ?ocsl_login=<token>: sign that account in (once) and send the browser
	 * to the same address without the token. A spent or unknown token just drops off the address.
	 */
	public static function handoff_landing() {
		if ( ! isset( $_GET[ self::HANDOFF ] ) || wp_doing_ajax() || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the proof.
			return;
		}
		$token = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET[ self::HANDOFF ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key   = 'ocsl_ho_' . hash( 'sha256', $token );
		$uid   = 40 === strlen( $token ) ? (int) get_transient( $key ) : 0;
		if ( $uid ) {
			delete_transient( $key );
			$user = get_user_by( 'id', $uid );
			$staff = $user instanceof WP_User && ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) );
			if ( $user instanceof WP_User && ! $staff && get_current_user_id() !== $uid ) {
				wp_set_current_user( $uid );
				wp_set_auth_cookie( $uid, true, is_ssl() );
			}
		}
		nocache_headers();
		$scheme = is_ssl() ? 'https://' : 'http://';
		$here   = $scheme . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' );
		wp_safe_redirect( remove_query_arg( self::HANDOFF, $here ), 302 );
		exit;
	}

	/* ---------------- Accounts ---------------- */

	/**
	 * Sign the user in the way WordPress does, so everything listening to a login hears it (the
	 * cart merges, loyalty plugins pull the member's points).
	 *
	 * @param int $user_id The user.
	 */
	private static function sign_in( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		if ( $user instanceof WP_User ) {
			do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobalHooks -- core hook.
		}
	}

	/**
	 * Open an account for a proven number: from the details typed in, and from the last order placed
	 * with that number when there is one (so a returning guest arrives at a filled-in checkout).
	 *
	 * @param string        $phone   Canonical number.
	 * @param array         $details first, last, email (may be empty for a returning guest).
	 * @param WC_Order|null $order   The last order with this number.
	 * @return int The new user's id, 0 on failure.
	 */
	private static function open_account( $phone, array $details, $order ) {
		$first = isset( $details['first'] ) ? (string) $details['first'] : '';
		$last  = isset( $details['last'] ) ? (string) $details['last'] : '';
		$email = isset( $details['email'] ) ? (string) $details['email'] : '';
		if ( $order instanceof WC_Order ) {
			$first = '' !== $first ? $first : $order->get_billing_first_name();
			$last  = '' !== $last ? $last : $order->get_billing_last_name();
			$email = '' !== $email ? $email : $order->get_billing_email();
		}
		if ( ! is_email( $email ) || email_exists( $email ) ) {
			// A guest order whose email already has an account cannot become a second account with
			// the same address. A placeholder keeps the phone account working; the customer fills a
			// real address at the checkout.
			$email = 'phone-' . $phone . '@' . preg_replace( '/^www\./', '', (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			if ( email_exists( $email ) ) {
				return 0;
			}
		}

		$login = 'u' . $phone;
		if ( username_exists( $login ) ) {
			$login .= '_' . wp_rand( 100, 999 );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24 ),
				'user_email'   => $email,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ) !== '' ? trim( $first . ' ' . $last ) : OCSL_Phone::pretty( $phone ),
				'role'         => get_role( 'customer' ) ? 'customer' : get_option( 'default_role', 'subscriber' ),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return 0;
		}
		update_user_meta( $user_id, 'billing_phone', $phone );
		update_user_meta( $user_id, 'billing_first_name', $first );
		update_user_meta( $user_id, 'billing_last_name', $last );
		if ( 0 !== strpos( $email, 'phone-' ) ) {
			update_user_meta( $user_id, 'billing_email', $email );
		}
		update_user_meta( $user_id, 'ocsl_privacy_consent', gmdate( 'c' ) );
		update_user_meta( $user_id, 'ocsl_opened', $order instanceof WC_Order ? 'order:' . $order->get_id() : 'register' );

		// Let the shop know a customer arrived, the way a WooCommerce registration does (its welcome
		// email included) — but never toward a placeholder address that nobody reads.
		if ( 0 !== strpos( $email, 'phone-' ) ) {
			do_action( 'woocommerce_created_customer', $user_id, array( 'user_login' => $login ), false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobalHooks -- WooCommerce hook.
		}
		return (int) $user_id;
	}

	/**
	 * Fill the account's empty billing and shipping fields from the last order with this number:
	 * the address, the city, and the shop's own extra fields (floor, apartment, entry code…). Fields
	 * the customer already has are never overwritten.
	 *
	 * @param int    $user_id The user.
	 * @param string $phone   Canonical number.
	 */
	private static function fill_from_last_order( $user_id, $phone ) {
		if ( ! OCSL_Settings::get( 'from_orders' ) ) {
			return;
		}
		$order = OCSL_Phone::last_order( $phone );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$fields = array();
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( (array) $order->get_address( $type ) as $k => $v ) {
				$fields[ $type . '_' . $k ] = $v;
			}
		}
		foreach ( $order->get_meta_data() as $meta ) {
			$k = (string) $meta->key;
			if ( preg_match( '/^_?(billing|shipping)_[a-z0-9_]+$/', $k ) && is_scalar( $meta->value ) ) {
				$fields[ ltrim( $k, '_' ) ] = (string) $meta->value;
			}
		}
		unset( $fields['billing_email'], $fields['billing_phone'] );
		foreach ( (array) apply_filters( 'ocsl_fill_fields', $fields, $user_id, $order ) as $k => $v ) {
			if ( '' === (string) $v ) {
				continue;
			}
			if ( '' === (string) get_user_meta( $user_id, $k, true ) ) {
				update_user_meta( $user_id, $k, $v );
			}
		}
	}
}
