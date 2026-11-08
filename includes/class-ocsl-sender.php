<?php
/**
 * Getting the code to the phone.
 *
 * Three ways out, chosen per site (transport()):
 *   - hub:    the normal case. OC Pluginz sends it from its ActiveTrail account
 *             under this shop's sender name and charges the site's balance.
 *   - direct: agency mode (OC_PLUGINZ_AGENCY) with OCSL_ACTIVETRAIL_KEY in
 *             wp-config.php: our own sites call ActiveTrail themselves.
 *   - test:   OCSL_TEST_MODE in wp-config.php (development copies only): no
 *             message goes out, the code is handed back to the popup. Only a
 *             constant can switch it on, never a setting, because in test mode
 *             anyone can sign in to any account by its phone number.
 * When none of them can send (no licence, no balance, no key), sending is
 * unavailable() and the checkout falls back to the shop's own guest form.
 *
 * @package OC_SMS_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Sender {

	const LOG     = 'ocsl_log';
	const LOG_MAX = 100;

	const ACTIVETRAIL_URL = 'https://webapi.mymarketing.co.il/api/smscampaign/OperationalMessage';

	/** @return string hub | direct | test | none */
	public static function transport() {
		if ( defined( 'OCSL_TEST_MODE' ) && OCSL_TEST_MODE ) {
			return 'test';
		}
		if ( OCSL_Hub::agency() ) {
			return self::direct_key() ? 'direct' : 'none';
		}
		return OCSL_Hub::allows() ? 'hub' : 'none';
	}

	/** The ActiveTrail key for agency mode, from wp-config.php only. */
	private static function direct_key() {
		return defined( 'OCSL_ACTIVETRAIL_KEY' ) ? trim( (string) OCSL_ACTIVETRAIL_KEY ) : '';
	}

	/**
	 * Can a code go out right now? The gate only stands when it can; otherwise guests check out as
	 * before. A hub site with a known empty balance cannot.
	 *
	 * @return bool
	 */
	public static function available() {
		$t = self::transport();
		if ( 'none' === $t ) {
			return false;
		}
		if ( 'hub' === $t ) {
			$balance = OCSL_Hub::balance();
			if ( null !== $balance && $balance <= 0 ) {
				return false;
			}
		}
		return (bool) apply_filters( 'ocsl_sending_available', true, $t );
	}

	/**
	 * The sender name: the setting, or one made from the site's address.
	 *
	 * @return string
	 */
	public static function sender() {
		$s = OCSL_Settings::clean_sender( OCSL_Settings::get( 'sender' ) );
		if ( '' !== $s ) {
			return $s;
		}
		$host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$label = preg_replace( '/^www\./', '', strtolower( $host ) );
		$label = (string) strtok( $label, '.' );
		$label = OCSL_Settings::clean_sender( ucfirst( $label ) );
		return '' !== $label ? $label : 'Shop';
	}

	/**
	 * The message for a code.
	 *
	 * @param string $code The six digits.
	 * @return string
	 */
	public static function message( $code ) {
		$text = (string) OCSL_Settings::get( 'sms_text' );
		if ( false === strpos( $text, '{code}' ) ) {
			$text .= ' {code}';
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return strtr(
			$text,
			array(
				'{code}'    => $code,
				'{site}'    => $site,
				'{minutes}' => (string) max( 1, (int) ceil( (int) OCSL_Settings::get( 'code_expiry' ) / 60 ) ),
			)
		);
	}

	/**
	 * Send a code.
	 *
	 * @param string $local Canonical number (05XXXXXXXX).
	 * @param string $code  The six digits.
	 * @return true|WP_Error
	 */
	public static function send( $local, $code ) {
		$t    = self::transport();
		$text = self::message( $code );
		$from = self::sender();

		switch ( $t ) {
			case 'test':
				$res = true;
				break;
			case 'direct':
				$res = self::activetrail( $local, $text, $from, self::direct_key() );
				break;
			case 'hub':
				$res = OCSL_Hub::send_sms( $local, $text, $from );
				break;
			default:
				$res = new WP_Error( 'ocsl_unavailable', 'שליחת SMS לא זמינה באתר כרגע.' );
		}

		self::log( $local, $t, $res );
		return $res;
	}

	/**
	 * ActiveTrail's operational-message endpoint (agency mode).
	 *
	 * @param string $to   Local-form number.
	 * @param string $text The message.
	 * @param string $from Sender name.
	 * @param string $key  API key.
	 * @return true|WP_Error
	 */
	private static function activetrail( $to, $text, $from, $key ) {
		$response = wp_remote_post(
			self::ACTIVETRAIL_URL,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'details'    => array(
							'unsubscribe_text' => '',
							'can_unsubscribe'  => false,
							'name'             => 'login',
							'from_name'        => $from,
							'content'          => $text,
						),
						'scheduling' => array( 'send_now' => true ),
						'mobiles'    => array( array( 'phone_number' => $to ) ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ocsl_activetrail', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'ocsl_activetrail', sprintf( 'ActiveTrail ענה בסטטוס %d.', $code ) );
		}
		return true;
	}

	/**
	 * Keep the last sends for the log screen: when, a masked number, how, and how it went.
	 *
	 * @param string        $local Canonical number.
	 * @param string        $t     Transport.
	 * @param true|WP_Error $res   Result.
	 * @return void
	 */
	private static function log( $local, $t, $res ) {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				't'     => time(),
				'phone' => substr( $local, 0, 3 ) . '-***' . substr( $local, -4 ),
				'via'   => $t,
				'ok'    => true === $res,
				'err'   => is_wp_error( $res ) ? $res->get_error_message() : '',
			)
		);
		update_option( self::LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/** @return array The log, newest first. */
	public static function entries() {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}
}
