<?php
/**
 * Settings: one option (ocsl_settings), defaults merged in on read, so a site
 * that never saved the screen still has a complete, working set.
 *
 * @package OC_SMS_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Settings {

	const OPTION = 'ocsl_settings';

	/** @var array|null Per-request copy of the merged settings. */
	private static $cache = null;

	/**
	 * Every setting with its default.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Where the phone sign-in is required.
			'gate'            => 'required', // required | optional | off.
			'myaccount_form'  => 1,
			// My Account: WooCommerce's email and password form under the phone sign-in. Off: the phone only.
			'email_login'     => 1,
			'open_selectors'  => '',

			// The message.
			'sender'          => '',
			'sms_text'        => '{site}: קוד הכניסה שלך הוא {code}',

			// The rails. A message is money: every send is rate-limited.
			'code_expiry'     => 300,
			'max_attempts'    => 5,
			'resend_cooldown' => 60,
			'phone_hourly'    => 4,
			'ip_hourly'       => 10,
			'daily_cap'       => 500,
			'low_balance'     => 200,
			'alert_email'     => '', // '' = the site's admin address (OCSL_Alerts)

			// Returning customers who ordered as guests: their next sign-in opens an
			// account from their last order, so the checkout arrives filled in.
			'from_orders'     => 1,

			// A number the shop has never seen, on its way to the checkout:
			//   details   the details step, then a code, and an account opens (the number is proven).
			//   register  the details step, and the account opens at once, with no code; then the
			//             checkout. The number stays unproven until its first sign-in with a code.
			'new_customer'    => 'details',

			// Shop staff (administrators, shop managers) may sign in with a code too. Off: they
			// sign in through the private login only.
			'staff_sms'       => 0,

			// The popup's words.
			'title'           => 'כניסה מהירה עם הטלפון',
			'subtitle'        => 'נשלח אליכם קוד בן 6 ספרות ב-SMS, ואחרי זה כל הפרטים כבר יחכו לכם בקופה.',
			'gate_title'      => 'הזינו מספר טלפון כדי להמשיך להזמנה',
			'register_title'  => 'נעים להכיר! רק כמה פרטים',
			'register_text'   => 'זו הפעם הראשונה שלכם כאן. נשמור את הפרטים, ובפעם הבאה תיכנסו רק עם הטלפון.',
			'consent_text'    => 'אני מאשר/ת את {privacy}',
			'brand_color'     => '',

			// The popup's look ('' = the plugin's default).
			'btn_text_color'  => '',
			'popup_bg'        => '',
			'text_color'      => '',
			// The shape of buttons and boxes (windows, fields, buttons): square | soft | round.
			// "custom" is never chosen: it is the look a shop set before 0.1.6 (see keep_look()).
			'corners'         => 'square',
			'overlay'         => 55,     // how dark the page gets behind the popup, %

			// The theme's sign-in drawer ("שלום, אורח" in the header).
			'drawer_head'       => 'התחברות',
			'drawer_title'      => "מס' טלפון ומתחילים :)",
			'drawer_email'      => 'כניסה עם מייל וסיסמה',
			'drawer_join_title' => 'עדיין לא רשומים?',
			'drawer_join_text'  => 'פותחים חשבון בדקה, וכל הזמנה הבאה מהירה יותר.',
			'drawer_join_btn'   => 'הרשמה',
		);
	}

	/**
	 * The merged settings.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key The key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * What a new customer on the way to the checkout gets: details | register. "checkout" (0.1.3,
	 * straight on as a guest) is read as "register".
	 *
	 * @return string
	 */
	public static function new_customer() {
		$v = (string) self::get( 'new_customer' );
		if ( 'checkout' === $v ) {
			return 'register';
		}
		return 'register' === $v ? 'register' : 'details';
	}

	/** The three shapes a shop can choose, with the words of the settings screen. */
	public static function corner_choices() {
		return array(
			'square' => 'פינות ישרות',
			'soft'   => 'מעט מעוגלות',
			'round'  => 'מעוגלות לגמרי',
		);
	}

	/**
	 * Until 0.1.6 the look was two settings (the popup's corners in px, and the shape of buttons and
	 * fields), rounded by default. Now it is one choice, square by default. A shop that already ran the
	 * plugin must not change its look by updating: its old look is written down once, as one of the
	 * three choices when it matches one exactly, and as "custom" (the old two values, kept as they
	 * are) when it does not. A new install gets the default.
	 *
	 * @return void
	 */
	public static function keep_look() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( isset( $saved['corners'] ) ) {
			return;
		}
		// A shop that never saved the settings screen still has its licence, or its send log.
		$ran_before = $saved || false !== get_option( 'ocsl_hub' ) || false !== get_option( 'ocsl_log' );
		if ( ! $ran_before ) {
			$saved['corners'] = 'square';
		} else {
			$radius = isset( $saved['radius'] ) ? (int) $saved['radius'] : 16;
			$shape  = isset( $saved['btn_shape'] ) ? (string) $saved['btn_shape'] : 'soft';
			$known  = array(
				'0|square' => 'square',
				'16|soft'  => 'soft',
				'24|round' => 'round',
			);
			$key    = $radius . '|' . $shape;
			if ( isset( $known[ $key ] ) ) {
				$saved['corners'] = $known[ $key ];
			} else {
				$saved['corners']   = 'custom';
				$saved['radius']    = $radius;
				$saved['btn_shape'] = $shape;
			}
		}
		update_option( self::OPTION, $saved, false );
		self::flush();
	}

	/** Forget the per-request copy (after a save). */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * A sender name the carriers accept: Latin letters, digits and spaces, up to 11.
	 *
	 * @param string $raw What was typed.
	 * @return string
	 */
	public static function clean_sender( $raw ) {
		$s = preg_replace( '/[^A-Za-z0-9 ]+/', '', (string) $raw );
		$s = trim( preg_replace( '/\s+/', ' ', (string) $s ) );
		return substr( $s, 0, 11 );
	}

	/**
	 * Sanitise a posted settings array against the defaults. Fields that were not posted keep their
	 * stored value, so an old tab with a partial form never wipes anything.
	 *
	 * @param array $in Posted values.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$in    = is_array( $in ) ? $in : array();
		$saved = self::all();
		$out   = $saved;
		$def   = self::defaults();

		if ( isset( $in['gate'] ) ) {
			$out['gate'] = in_array( $in['gate'], array( 'required', 'optional', 'off' ), true ) ? $in['gate'] : 'required';
		}
		foreach ( array( 'myaccount_form', 'from_orders', 'staff_sms' ) as $k ) {
			if ( isset( $in['__submitted'] ) ) {
				$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			}
		}
		// A hidden "0" travels with this checkbox, so a settings screen left open from an older version
		// (which has no such field) never switches the email form off by being saved.
		if ( isset( $in['email_login'] ) ) {
			$out['email_login'] = empty( $in['email_login'] ) ? 0 : 1;
		}
		if ( isset( $in['new_customer'] ) ) {
			$out['new_customer'] = in_array( $in['new_customer'], array( 'details', 'register' ), true ) ? $in['new_customer'] : 'details';
		}
		if ( isset( $in['open_selectors'] ) ) {
			$out['open_selectors'] = sanitize_text_field( wp_unslash( $in['open_selectors'] ) );
		}
		if ( isset( $in['sender'] ) ) {
			$out['sender'] = self::clean_sender( wp_unslash( $in['sender'] ) );
		}
		if ( isset( $in['sms_text'] ) ) {
			$text            = sanitize_text_field( wp_unslash( $in['sms_text'] ) );
			$out['sms_text'] = false === strpos( $text, '{code}' ) ? $def['sms_text'] : $text;
		}
		$ints = array(
			'code_expiry'     => array( 60, 900 ),
			'max_attempts'    => array( 3, 10 ),
			'resend_cooldown' => array( 30, 300 ),
			'phone_hourly'    => array( 1, 20 ),
			'ip_hourly'       => array( 1, 100 ),
			'daily_cap'       => array( 10, 100000 ),
			'low_balance'     => array( 0, 100000 ),
			'overlay'         => array( 0, 90 ),
		);
		foreach ( $ints as $k => $range ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = max( $range[0], min( $range[1], absint( $in[ $k ] ) ) );
			}
		}
		foreach ( array( 'title', 'subtitle', 'gate_title', 'register_title', 'register_text', 'consent_text', 'drawer_head', 'drawer_title', 'drawer_email', 'drawer_join_title', 'drawer_join_text', 'drawer_join_btn' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$v         = sanitize_text_field( wp_unslash( $in[ $k ] ) );
				$out[ $k ] = '' === $v ? $def[ $k ] : $v;
			}
		}
		if ( isset( $in['alert_email'] ) ) {
			$e                  = sanitize_email( wp_unslash( $in['alert_email'] ) );
			$out['alert_email'] = is_email( $e ) ? $e : '';
		}
		foreach ( array( 'brand_color', 'btn_text_color', 'popup_bg', 'text_color' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$c         = sanitize_hex_color( wp_unslash( $in[ $k ] ) );
				$out[ $k ] = $c ? $c : '';
			}
		}
		// Anything but one of the three choices (the kept "custom" look included) leaves the stored value alone.
		if ( isset( $in['corners'] ) && is_string( $in['corners'] ) && isset( self::corner_choices()[ $in['corners'] ] ) ) {
			$out['corners'] = $in['corners'];
		}
		return $out;
	}

	/**
	 * Save posted values.
	 *
	 * @param array $in Posted values.
	 * @return void
	 */
	public static function save( $in ) {
		update_option( self::OPTION, self::sanitize( $in ), false );
		self::flush();
	}
}
