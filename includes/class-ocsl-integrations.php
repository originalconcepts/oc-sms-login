<?php
/**
 * Other plugins that can use the phone the shopper proved here, so nobody asks for a second code.
 *
 *   OC ValueCard  the club identifies the shopper by this number (filter ocvc_verified_phone,
 *                 ValueCard 0.4.2+): points and benefits show at once, no ValueCard OTP.
 *
 * Only a number verified with our code counts: the meta is written by OCSL_Auth::verify() alone,
 * never from the editable billing phone.
 *
 * @package OC_SMS_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Integrations {

	public static function boot() {
		add_filter( 'ocvc_verified_phone', array( __CLASS__, 'verified_phone' ), 10, 2 );
	}

	/**
	 * The phone this account proved with our code, or what the filter already had.
	 *
	 * @param string $phone   Earlier answer.
	 * @param int    $user_id The signed-in shopper.
	 * @return string
	 */
	public static function verified_phone( $phone, $user_id = 0 ) {
		$user_id = (int) $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return $phone;
		}
		$mine = OCSL_Phone::normalize( (string) get_user_meta( $user_id, OCSL_Phone::META, true ) );
		return '' !== $mine ? $mine : $phone;
	}
}
