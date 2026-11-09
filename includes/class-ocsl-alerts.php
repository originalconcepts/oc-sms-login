<?php
/**
 * Balance mails: the shop hears before the messages run out, not after the codes stop.
 *
 *   low    the balance went below the low_balance setting (0 turns it off)
 *   empty  the balance reached 0: SMS sign-in stops, the checkout opens to guests as usual
 *
 * One mail per step down (ok → low → empty), never the same step twice in a row; a top-up resets
 * it quietly. A site that never had messages gets nothing (a fresh licence is not "ran out").
 * Hub mode only: test mode and agency mode have no balance.
 *
 * The balance arrives with every hub answer (each send, the hourly status, the packs screen), all
 * of which update the option OCSL_Hub::OPTION, so the check rides on that option's update.
 *
 * @package OC_SMS_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Alerts {

	const OPTION = 'ocsl_alert';

	const RANK = array(
		'ok'    => 0,
		'low'   => 1,
		'empty' => 2,
	);

	public static function boot() {
		add_action( 'add_option_' . OCSL_Hub::OPTION, array( __CLASS__, 'check' ), 10, 0 );
		add_action( 'update_option_' . OCSL_Hub::OPTION, array( __CLASS__, 'check' ), 10, 0 );
	}

	/**
	 * Where a balance stands.
	 *
	 * @param int $balance Messages left.
	 * @return string ok | low | empty
	 */
	public static function level( $balance ) {
		if ( $balance <= 0 ) {
			return 'empty';
		}
		$low = (int) OCSL_Settings::get( 'low_balance' );
		return ( $low > 0 && $balance < $low ) ? 'low' : 'ok';
	}

	/** Compare the balance with the last level written about, and mail on a step down. */
	public static function check() {
		if ( ( defined( 'OCSL_TEST_MODE' ) && OCSL_TEST_MODE ) || OCSL_Hub::agency() || '' === OCSL_Hub::key() ) {
			return;
		}
		$balance = OCSL_Hub::balance();
		if ( null === $balance ) {
			return;
		}
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$last  = ( isset( $state['level'] ) && array_key_exists( $state['level'], self::RANK ) ) ? $state['level'] : 'ok';
		$had   = ! empty( $state['had'] ) || $balance > 0;
		$now   = self::level( (int) $balance );

		if ( $now === $last && $had === ! empty( $state['had'] ) ) {
			return;
		}
		update_option(
			self::OPTION,
			array(
				'level' => $now,
				'had'   => $had,
				'at'    => time(),
			),
			false
		);
		if ( $had && self::RANK[ $now ] > self::RANK[ $last ] ) {
			self::mail( $now, (int) $balance );
		}
	}

	/** Where the mails go: the setting, or the site's admin address. */
	public static function recipient() {
		$to = (string) OCSL_Settings::get( 'alert_email' );
		return is_email( $to ) ? $to : (string) get_option( 'admin_email' );
	}

	/**
	 * @param string $level   low | empty.
	 * @param int    $balance Messages left.
	 */
	private static function mail( $level, $balance ) {
		$to = self::recipient();
		if ( ! is_email( $to ) ) {
			return;
		}
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$color = (string) OCSL_Settings::get( 'brand_color' );
		$color = '' !== $color ? $color : '#1f2937';
		if ( 'empty' === $level ) {
			$subject = sprintf( '%s: הודעות ה-SMS נגמרו והכניסה עם SMS נעצרה', $site );
			$lines   = array(
				'יתרת הודעות ה-SMS של OC SMS Login באתר נגמרה.',
				'עד שתטענו חבילה, הכניסה עם SMS לא פעילה והקופה פתוחה לאורחים כרגיל, כך שהלקוחות ממשיכים להזמין.',
			);
		} else {
			$subject = sprintf( '%s: נשארו %s הודעות SMS', $site, number_format_i18n( $balance ) );
			$lines   = array(
				sprintf( 'ביתרה של OC SMS Login באתר נשארו %s הודעות SMS.', number_format_i18n( $balance ) ),
				'כשהיתרה תיגמר, הכניסה עם SMS תיעצר והקופה תיפתח לאורחים כרגיל. כדאי לטעון חבילה עכשיו.',
			);
		}
		$html = '<div dir="rtl" style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;text-align:right">';
		foreach ( $lines as $line ) {
			$html .= '<p style="margin:0 0 12px">' . esc_html( $line ) . '</p>';
		}
		$html .= '<p style="margin:20px 0"><a href="' . esc_url( OCSL_Admin::url( 'credits' ) ) . '" style="display:inline-block;background:' . esc_attr( $color ) . ';color:#ffffff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:bold">לרכישת חבילת הודעות</a></p>';
		$html .= '<p style="margin:0;color:#6b7280;font-size:13px">' . esc_html( 'את הסף ואת כתובת המייל משנים בהגדרות התוסף: OC Pluginz ← OC SMS Login.' ) . '</p></div>';

		wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
