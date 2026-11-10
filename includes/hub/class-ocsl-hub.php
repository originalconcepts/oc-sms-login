<?php
/**
 * The link to OC Pluginz (my.ocpluginz.com): the licence that brings updates
 * from the hub, lists this install among the hub's sites, and carries the
 * site's SMS bank.
 *
 * OC SMS Login is free. The login codes are text messages, and those are paid:
 * they go out from OC Pluginz' own ActiveTrail account, under this shop's
 * sender name, and every message comes out of the site's message balance on
 * the hub (packs bought in OC Pluginz, docs/hub-contract.md). The ActiveTrail
 * key never reaches a shop: the hub keeps it, charges the balance and sends.
 *
 * One option (ocsl_hub) holds the key, a copy of the entitlement the hub last
 * answered with (including the SMS balance and the packs on sale), and the
 * hub's notices for the admin (OCSL_Notices). The hub is asked again hourly
 * (WP-Cron) and when the account screen opens; an unreachable hub is survived
 * on the cached copy, a hub that refuses the key is not.
 *
 * The licence decides whether codes can be sent through the hub (sends()): an
 * active licence, or one past its end within the grace days. Without it the
 * login still works for anyone already signed in, and the checkout falls back
 * to the shop's normal guest form, so a lapsed licence never blocks a sale.
 *
 * Agency mode (OC_PLUGINZ_AGENCY = true in wp-config.php) skips the hub
 * entirely: no calls, no notices, and the codes go out directly through an
 * ActiveTrail key kept in wp-config.php (OCSL_ACTIVETRAIL_KEY).
 *
 * What goes to the hub: the key, the product, this site's domain and address,
 * the plugin and WordPress versions, and for every code: the mobile number,
 * the message text and the sender name — the hub needs them to send it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Hub {

	const URL         = 'https://my.ocpluginz.com';
	const PRODUCT     = 'oc-sms-login';
	const OPTION      = 'ocsl_hub';
	const CRON        = 'ocsl_hub_refresh';
	const ACCOUNT_URL = 'https://my.ocpluginz.com/account/';
	const PRICING_URL = 'https://ocpluginz.com/oc-sms-login#pricing';
	const CAP         = 'manage_options';

	/** Codes the hub answers a send with when the balance cannot cover it. */
	const NO_CREDITS = 'ocpz_no_credits';

	/** The hub's thresholds (OCPZS_Entitlements), mirrored so a cached copy ages the same way. */
	const EXPIRING_DAYS = 14;
	const GRACE_DAYS    = 7;

	/** Codes that mean "this key buys nothing here": the cached entitlement goes. An outage keeps it. */
	const REFUSALS = array( 'ocpzs_bad_key', 'ocpzs_inactive', 'ocpzs_seats_full', 'ocpzs_bad_product', 'ocpzs_bad_request' );

	/** @var bool|null Per-request memo of allows(). */
	private static $allowed = null;

	public static function boot() {
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		}
	}

	/* ---------------- The rules, without WordPress ---------------- */

	/**
	 * valid | expiring | grace | expired, from expires_at as the hub ages it. No end date never expires.
	 *
	 * @param array|null $ent The entitlement.
	 * @param int        $now Unix time.
	 * @return string
	 */
	public static function lifecycle( $ent, $now ) {
		if ( ! is_array( $ent ) ) {
			return 'expired';
		}
		$end = isset( $ent['expires_at'] ) ? (string) $ent['expires_at'] : '';
		if ( '' === $end ) {
			return 'valid';
		}
		$exp = strtotime( $end );
		if ( false === $exp ) {
			return isset( $ent['state'] ) ? (string) $ent['state'] : 'valid';
		}
		if ( $exp > $now ) {
			return ( $exp - $now < self::EXPIRING_DAYS * DAY_IN_SECONDS ) ? 'expiring' : 'valid';
		}
		return ( $now - $exp < self::GRACE_DAYS * DAY_IN_SECONDS ) ? 'grace' : 'expired';
	}

	/**
	 * Are updates offered? The hub's own rule for a download (OCPZS_Packages::license_for): an active
	 * licence, or one past due within its grace days.
	 *
	 * @param array  $state The option.
	 * @param string $key   The licence key.
	 * @param int    $now   Unix time.
	 * @return bool
	 */
	public static function decide( array $state, $key, $now ) {
		$ent = ( isset( $state['entitlement'] ) && is_array( $state['entitlement'] ) ) ? $state['entitlement'] : null;
		if ( '' === $key || null === $ent ) {
			return false;
		}
		$status = isset( $ent['status'] ) ? (string) $ent['status'] : '';
		$life   = self::lifecycle( $ent, $now );
		if ( in_array( $status, array( 'suspended', 'deleted' ), true ) || 'expired' === $life ) {
			return false;
		}
		return 'active' === $status || 'grace' === $life;
	}

	/**
	 * Where the licence stands, for the screen and the notice: '' (fine), no_key, refused, unverified,
	 * suspended, grace, expired.
	 *
	 * @param array  $state The option.
	 * @param string $key   The licence key.
	 * @param int    $now   Unix time.
	 * @return string
	 */
	public static function reason( array $state, $key, $now ) {
		if ( '' === $key ) {
			return 'no_key';
		}
		$ent = ( isset( $state['entitlement'] ) && is_array( $state['entitlement'] ) ) ? $state['entitlement'] : null;
		if ( null === $ent ) {
			return ! empty( $state['refused'] ) ? 'refused' : 'unverified';
		}
		if ( in_array( isset( $ent['status'] ) ? (string) $ent['status'] : '', array( 'suspended', 'deleted' ), true ) ) {
			return 'suspended';
		}
		if ( ! self::decide( $state, $key, $now ) ) {
			return 'expired';
		}
		return 'grace' === self::lifecycle( $ent, $now ) ? 'grace' : '';
	}

	/** An OC Pluginz key: ocpz_ and hex. */
	public static function looks_like_key( $key ) {
		return 1 === preg_match( '/^ocpz_[a-f0-9]{20,64}$/i', (string) $key );
	}

	/** The domain the hub binds a seat to: the host, lower case, without www. */
	public static function domain_of( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return (string) preg_replace( '/^www\./', '', $host );
	}

	/** The key as the screen shows it: the start and the end. */
	public static function masked( $key ) {
		$key = (string) $key;
		return strlen( $key ) > 14 ? substr( $key, 0, 9 ) . '…' . substr( $key, -4 ) : $key;
	}

	/* ---------------- The site's state ---------------- */

	public static function url() {
		return rtrim( defined( 'OCSL_HUB_URL' ) ? (string) OCSL_HUB_URL : self::URL, '/' );
	}

	/** Agency mode (our own sites): no licence, no hub calls, no notices. */
	public static function agency() {
		return defined( 'OC_PLUGINZ_AGENCY' ) && OC_PLUGINZ_AGENCY;
	}

	/** The key fixed in wp-config.php. */
	public static function key_from_constant() {
		return defined( 'OCSL_LICENSE_KEY' ) && '' !== trim( (string) OCSL_LICENSE_KEY );
	}

	/** @return array */
	public static function state() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function patch( array $patch ) {
		update_option( self::OPTION, array_merge( self::state(), $patch ), false );
		self::$allowed = null;
	}

	public static function key() {
		if ( self::key_from_constant() ) {
			return trim( (string) OCSL_LICENSE_KEY );
		}
		$s = self::state();
		return isset( $s['key'] ) ? (string) $s['key'] : '';
	}

	public static function domain() {
		return self::domain_of( home_url( '/' ) );
	}

	/** @return array|null the entitlement the hub last answered with */
	public static function entitlement() {
		$s = self::state();
		return ( isset( $s['entitlement'] ) && is_array( $s['entitlement'] ) ) ? $s['entitlement'] : null;
	}

	/**
	 * Is this site in the hub's hands? A key is set (not agency mode) and the hub has answered for it
	 * at least once — accepted it (entitlement cached) or refused it. From then on OCSL_Hub_Updater owns
	 * updates and the GitHub updater stays silent, whatever the licence's state: an expired or refused
	 * licence means no updates, not GitHub updates. Disconnecting (or removing the key) hands updates
	 * back to GitHub. A wp-config key the hub has never been reached for does not count yet.
	 *
	 * @return bool
	 */
	public static function connected() {
		if ( self::agency() || '' === self::key() ) {
			return false;
		}
		$s = self::state();
		return ( isset( $s['entitlement'] ) && is_array( $s['entitlement'] ) ) || ! empty( $s['refused'] );
	}

	public static function updates_allowed() {
		return ! self::agency() && self::decide( self::state(), self::key(), time() );
	}

	/**
	 * May login codes go out through the hub right now? A key the hub accepted, and a licence that is
	 * active or within its grace days (decide()). Agency mode never sends through the hub.
	 *
	 * @return bool
	 */
	public static function allows() {
		if ( null === self::$allowed ) {
			$ok            = ! self::agency() && '' !== self::key() && self::decide( self::state(), self::key(), time() );
			self::$allowed = (bool) apply_filters( 'ocsl_hub_allows', $ok );
		}
		return self::$allowed;
	}

	/**
	 * The SMS part of the entitlement as last cached: unit, balance, sender, packs, buy_url.
	 *
	 * @return array
	 */
	public static function credits() {
		$ent = self::entitlement();
		$c   = ( is_array( $ent ) && isset( $ent['credits'] ) && is_array( $ent['credits'] ) ) ? $ent['credits'] : array();
		$s   = self::state();
		if ( isset( $s['balance'] ) && isset( $s['balance_at'] ) && (int) $s['balance_at'] >= (int) ( isset( $s['checked_at'] ) ? $s['checked_at'] : 0 ) ) {
			$c['balance'] = (int) $s['balance']; // A send answered more recently than the last status.
		}
		return $c;
	}

	/** The cached message balance, or null when the hub has never told. */
	public static function balance() {
		$c = self::credits();
		return isset( $c['balance'] ) ? (int) $c['balance'] : null;
	}

	/**
	 * Ask the hub for the balance and the packs now (the credits screen).
	 *
	 * @return array|WP_Error
	 */
	public static function fetch_balance() {
		if ( ! self::allows() ) {
			return new WP_Error( 'ocsl_hub_off', self::off_text() );
		}
		$res = self::request( 'GET', '/sms/balance' );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( $res->get_error_code(), self::friendly( $res ) );
		}
		if ( isset( $res['balance'] ) ) {
			self::patch(
				array(
					'balance'    => (int) $res['balance'],
					'balance_at' => time(),
				)
			);
		}
		return $res;
	}

	/**
	 * Send one login code through the hub, which charges the site's message balance.
	 *
	 * @param string $to     The mobile number in local form (05XXXXXXXX).
	 * @param string $text   The message.
	 * @param string $sender The sender name (up to 11 Latin letters and digits).
	 * @return true|WP_Error
	 */
	public static function send_sms( $to, $text, $sender ) {
		if ( ! self::allows() ) {
			return new WP_Error( 'ocsl_hub_off', self::off_text() );
		}
		$res = self::request(
			'POST',
			'/sms/send',
			array(
				'to'      => (string) $to,
				'text'    => (string) $text,
				'sender'  => (string) $sender,
				'purpose' => 'login',
			)
		);
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			if ( is_array( $data ) && isset( $data['balance'] ) ) {
				self::patch(
					array(
						'balance'    => (int) $data['balance'],
						'balance_at' => time(),
					)
				);
			}
			return new WP_Error( $res->get_error_code(), self::friendly( $res ) );
		}
		if ( isset( $res['balance'] ) ) {
			self::patch(
				array(
					'balance'    => (int) $res['balance'],
					'balance_at' => time(),
				)
			);
		}
		return true;
	}

	public static function status() {
		return self::reason( self::state(), self::key(), time() );
	}

	/** The account screen in this site's admin: a hidden page of the suite's shared OC Pluginz menu. */
	public static function settings_url() {
		return admin_url( 'admin.php?page=' . OCSL_Hub_Admin::PAGE );
	}

	/* ---------------- The hub ---------------- */

	/** @return array */
	private static function identity() {
		return array(
			'key'            => self::key(),
			'product'        => self::PRODUCT,
			'domain'         => self::domain(),
			'site_url'       => home_url( '/' ),
			'version'        => OCSL_VERSION,
			'plugin_version' => OCSL_VERSION,
			'wp_version'     => (string) get_bloginfo( 'version' ),
		);
	}

	/** The headers every call to the hub carries. */
	public static function headers() {
		return array(
			'X-OCPZ-Key'     => self::key(),
			'X-OCPZ-Site'    => self::domain(),
			'X-OCPZ-Version' => OCSL_VERSION,
			'Accept'         => 'application/json',
			'User-Agent'     => 'OC-SMS-Login/' . OCSL_VERSION . '; ' . home_url( '/' ),
		);
	}

	/**
	 * A call to /wp-json/ocpz/v1{$path}.
	 *
	 * @param string $method GET|POST.
	 * @param string $path   Under /wp-json/ocpz/v1.
	 * @param array  $body   Extra parameters.
	 * @return array|WP_Error the decoded answer
	 */
	private static function request( $method, $path, array $body = array() ) {
		$body = array_merge( self::identity(), $body );
		$args = array(
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => self::headers(),
		);
		$url  = self::url() . '/wp-json/ocpz/v1' . $path;
		if ( 'GET' === $method ) {
			$response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $body ), $url ), $args );
		} else {
			$args['body'] = $body;
			$response     = wp_remote_post( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ocsl_hub_http', sprintf( 'OC Pluginz ענה בסטטוס %d.', $code ) );
		}
		if ( $code >= 400 || ( isset( $data['ok'] ) && false === $data['ok'] ) ) {
			$msg = isset( $data['message'] ) ? (string) $data['message'] : sprintf( 'OC Pluginz ענה בסטטוס %d.', $code );
			return new WP_Error( isset( $data['code'] ) ? (string) $data['code'] : 'ocsl_hub_http', $msg, isset( $data['data'] ) ? $data['data'] : null );
		}
		return $data;
	}

	/** The hub's refusals in words the store owner can act on. */
	private static function friendly( WP_Error $error ) {
		switch ( $error->get_error_code() ) {
			case 'ocpzs_bad_key':
				return 'OC Pluginz לא מזהה את המפתח הזה עבור OC SMS Login. בדקו אותו בחשבון OC Pluginz שלכם.';
			case 'ocpzs_inactive':
				return 'הרישיון הזה לא פעיל. אפשר לחדש אותו בחשבון OC Pluginz.';
			case 'ocpzs_seats_full':
				return 'כל האתרים שהרישיון מאפשר כבר מחוברים. נתקו קודם אתר אחר (במסך החשבון שלו, או בחשבון OC Pluginz).';
			case 'ocpzs_bad_product':
				return 'OC Pluginz עוד לא מציע את OC SMS Login. פנו ל-Original Concepts.';
			case self::NO_CREDITS:
				return 'נגמרה יתרת ההודעות. אפשר לרכוש חבילה במסך "חבילות SMS".';
			case 'ocpzs_sender':
				return 'שם השולח לא אושר ב-OC Pluginz. שם שולח הוא עד 11 אותיות באנגלית וספרות.';
			case 'ocpzs_upstream':
				return 'שירות ה-SMS לא ענה כרגע. נסו שוב בעוד דקה.';
			case 'ocpzs_rate':
				return 'יותר מדי בקשות בדקה האחרונה. נסו שוב בעוד דקה.';
			case 'rest_forbidden':
				return 'OC Pluginz דחה את הבקשה כרגע. נסו שוב בעוד דקה.';
		}
		return $error->get_error_message();
	}

	/**
	 * Keep what the hub answered.
	 *
	 * @param array $data The decoded answer.
	 * @return true|WP_Error
	 */
	private static function accept( array $data ) {
		$ent = ( isset( $data['entitlement'] ) && is_array( $data['entitlement'] ) ) ? $data['entitlement'] : null;
		if ( null === $ent ) {
			return new WP_Error( 'ocsl_hub_shape', 'OC Pluginz ענה בלי רישיון.' );
		}
		$s = self::state();
		self::patch(
			array(
				'entitlement' => $ent,
				'notices'     => OCSL_Notices::from_answer( $data ),
				'latest'      => isset( $data['latest'] ) ? (string) $data['latest'] : ( isset( $s['latest'] ) ? (string) $s['latest'] : '' ),
				'checked_at'  => time(),
				'error'       => '',
				'error_code'  => '',
				'error_at'    => 0,
				'refused'     => false,
			)
		);
		return true;
	}

	/** A failure: a refusal drops the cached entitlement, an outage does not. */
	private static function note_error( WP_Error $error ) {
		$patch = array(
			'error'      => self::friendly( $error ),
			'error_code' => $error->get_error_code(),
			'error_at'   => time(),
		);
		if ( in_array( $error->get_error_code(), self::REFUSALS, true ) ) {
			$patch['entitlement'] = null;
			$patch['refused']     = true;
		}
		self::patch( $patch );
	}

	/**
	 * Take a seat for this site. The key is kept only once the hub accepts it, so a typo never leaves
	 * the site half connected.
	 *
	 * @param string $key The licence key.
	 * @return true|WP_Error
	 */
	public static function connect( $key ) {
		$key = trim( (string) $key );
		if ( ! self::looks_like_key( $key ) ) {
			return new WP_Error( 'ocsl_hub_key', 'זה לא נראה כמו מפתח רישיון של OC Pluginz (ocpz_…).' );
		}
		$previous = self::state();
		update_option( self::OPTION, array( 'key' => $key ), false );
		self::$allowed = null;
		$res = self::request( 'POST', '/sdk/activate' );
		$ok  = is_wp_error( $res ) ? $res : self::accept( $res );
		if ( is_wp_error( $ok ) ) {
			update_option( self::OPTION, $previous, false );
			self::$allowed = null;
			return new WP_Error( $ok->get_error_code(), self::friendly( $ok ) );
		}
		self::patch( array( 'activated_at' => time() ) );
		self::maybe_schedule();
		return true;
	}

	/**
	 * Ask the hub again; a wp-config key that never took its seat takes it first.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh() {
		if ( self::agency() || '' === self::key() ) {
			return new WP_Error( 'ocsl_hub_no_key', 'אין מפתח רישיון.' );
		}
		$s     = self::state();
		$fresh = self::key_from_constant() && null === self::entitlement() && empty( $s['refused'] );
		$res   = self::request( $fresh ? 'POST' : 'GET', $fresh ? '/sdk/activate' : '/sdk/status' );
		if ( is_wp_error( $res ) ) {
			self::note_error( $res );
			return new WP_Error( $res->get_error_code(), self::friendly( $res ) );
		}
		$ok = self::accept( $res );
		if ( true === $ok && $fresh ) {
			self::patch( array( 'activated_at' => time() ) );
		}
		return $ok;
	}

	/** The hourly check (WP-Cron). */
	public static function cron() {
		self::refresh();
	}

	/**
	 * Ask again when the last contact is older than $max_age seconds (the account screen).
	 *
	 * @param int $max_age Seconds.
	 */
	public static function refresh_if_stale( $max_age = 600 ) {
		if ( self::agency() || '' === self::key() ) {
			return;
		}
		$s    = self::state();
		$last = max( isset( $s['checked_at'] ) ? (int) $s['checked_at'] : 0, isset( $s['error_at'] ) ? (int) $s['error_at'] : 0 );
		if ( time() - $last >= (int) $max_age ) {
			self::refresh();
		}
	}

	/** Free the seat at the hub and forget the key. Updates go back to GitHub. */
	public static function disconnect() {
		if ( '' !== self::key() && ! self::agency() ) {
			self::request( 'POST', '/sdk/deactivate' );
		}
		delete_option( self::OPTION );
		self::$allowed = null;
		wp_clear_scheduled_hook( self::CRON );
		return true;
	}

	/** The hourly check runs while a key is set, and only then. */
	public static function maybe_schedule() {
		$want = '' !== self::key() && ! self::agency();
		$next = wp_next_scheduled( self::CRON );
		if ( $want && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		} elseif ( ! $want && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/* ---------------- The notice ---------------- */

	/** Why codes cannot go out through the hub, in words the store owner can act on; '' when they can. */
	public static function off_text() {
		if ( self::allows() ) {
			return '';
		}
		if ( self::agency() ) {
			return 'מצב סוכנות (OC_PLUGINZ_AGENCY): הקודים נשלחים ישירות דרך ActiveTrail ולא דרך OC Pluginz.';
		}
		switch ( self::status() ) {
			case 'no_key':
				return 'קודי ה-SMS נשלחים דרך OC Pluginz. חברו את מפתח הרישיון (קיבלתם אותו במייל, בחינם) כדי שהכניסה עם SMS תעבוד. עד אז הקופה פתוחה כרגיל לאורחים.';
			case 'refused':
				return 'OC Pluginz לא קיבל את מפתח הרישיון של OC SMS Login, ולכן קודים לא נשלחים. הקופה פתוחה כרגיל לאורחים.';
			case 'unverified':
				return 'מפתח הרישיון של OC SMS Login עוד לא אושר מול OC Pluginz, ולכן קודים לא נשלחים עדיין.';
			case 'suspended':
				return 'הרישיון של OC SMS Login מושהה, ולכן קודים לא נשלחים. הקופה פתוחה כרגיל לאורחים עד שהרישיון יחודש.';
		}
		return 'הרישיון של OC SMS Login לא בתוקף, ולכן קודים לא נשלחים. הקופה פתוחה כרגיל לאורחים עד שהרישיון יחודש.';
	}

	/**
	 * On the plugin's own screens (the settings and the packs; the account page says it itself): why
	 * codes do not go out, a grace-days warning, or a low balance.
	 */
	public static function notice() {
		if ( ! current_user_can( self::CAP ) || ! class_exists( 'OCSL_Admin' ) ) {
			return;
		}
		if ( ! OCSL_Admin::is_own_screen() ) {
			// Fresh from the Plugins screen: say what is left to do, where the shop owner is looking.
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$fresh  = $screen && in_array( $screen->id, array( 'plugins', 'dashboard' ), true );
			if ( $fresh && ! self::agency() && 'none' === OCSL_Sender::transport() && 'no_key' === self::status() ) {
				echo '<div class="notice notice-info"><p><strong>OC SMS Login מותקן.</strong> נשאר לחבר את מפתח הרישיון שקיבלתם במייל, ולרכוש חבילת הודעות מתוך התוסף. עד אז הקופה פתוחה לאורחים כרגיל. <a href="'
					. esc_url( self::settings_url() ) . '">לחיבור המפתח</a></p></div>';
			}
			return;
		}
		if ( 'test' === OCSL_Sender::transport() ) {
			echo '<div class="notice notice-warning"><p><strong>מצב בדיקה פעיל (OCSL_TEST_MODE).</strong> קודים לא נשלחים ב-SMS, הקוד מוצג על המסך. כל אחד יכול להיכנס לכל חשבון לפי מספר טלפון, ולכן זה רק לסביבת פיתוח.</p></div>';
			return;
		}
		if ( self::agency() ) {
			return;
		}
		$text = self::off_text();
		$kind = 'error';
		if ( '' === $text ) {
			$balance = self::balance();
			if ( 'grace' === self::status() ) {
				$text = 'תוקף הרישיון של OC SMS Login הסתיים והוא בימי חסד. חדשו עכשיו, אחרת הקודים יפסיקו להישלח.';
				$kind = 'warning';
			} elseif ( null !== $balance && $balance <= 0 ) {
				echo '<div class="notice notice-error"><p>' . esc_html( 'אין יתרת הודעות SMS, ולכן הכניסה עם SMS לא פעילה והקופה פתוחה לאורחים כרגיל.' )
					. ' <a href="' . esc_url( OCSL_Admin::url( 'credits' ) ) . '">לרכישת חבילה</a></p></div>';
				return;
			} elseif ( null !== $balance && $balance < (int) OCSL_Settings::get( 'low_balance' ) ) {
				$text = sprintf( 'נשארו %s הודעות SMS ביתרה. כשהיתרה תיגמר, הכניסה עם SMS תיעצר והקופה תיפתח לאורחים כרגיל.', number_format_i18n( $balance ) );
				$kind = 'warning';
				echo '<div class="notice notice-' . $kind . '"><p>' . esc_html( $text )
					. ' <a href="' . esc_url( OCSL_Admin::url( 'credits' ) ) . '">לרכישת חבילה</a></p></div>';
				return;
			} else {
				return;
			}
		}
		echo '<div class="notice notice-' . $kind . '"><p>' . esc_html( $text )
			. ' <a href="' . esc_url( self::settings_url() ) . '">חשבון OC Pluginz</a></p></div>';
	}
}
