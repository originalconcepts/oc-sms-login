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
			'radius'          => 16,     // corners of the popup, px
			'btn_shape'       => 'soft', // soft | round | square: buttons and fields
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
		foreach ( array( 'myaccount_form', 'from_orders' ) as $k ) {
			if ( isset( $in['__submitted'] ) ) {
				$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			}
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
			'radius'          => array( 0, 28 ),
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
		if ( isset( $in['btn_shape'] ) ) {
			$out['btn_shape'] = in_array( $in['btn_shape'], array( 'soft', 'round', 'square' ), true ) ? $in['btn_shape'] : 'soft';
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
