<?php
/**
 * Phone numbers: one canonical form (Israeli mobile, local: 05XXXXXXXX), every
 * spelling a store may already hold it under, and who it belongs to — an
 * account, or only past guest orders.
 *
 * @package OC_SMS_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Phone {

	/** User meta: the number this account proved by SMS, canonical form. */
	const META = 'ocsl_phone';

	/**
	 * The number as the visitor typed it, in canonical local form; '' when it is not an Israeli mobile.
	 *
	 * @param string $raw Whatever was typed.
	 * @return string
	 */
	public static function normalize( $raw ) {
		$d = (string) preg_replace( '/\D+/', '', (string) $raw );
		if ( 0 === strpos( $d, '00' ) ) {
			$d = substr( $d, 2 );
		}
		if ( 0 === strpos( $d, '972' ) ) {
			$d = '0' . substr( $d, 3 );
		} elseif ( 9 === strlen( $d ) && 0 === strpos( $d, '5' ) ) {
			$d = '0' . $d; // Typed without the leading zero.
		}
		return 1 === preg_match( '/^05\d{8}$/', $d ) ? $d : '';
	}

	/**
	 * The number as people read it: 052-1234567.
	 *
	 * @param string $local Canonical form.
	 * @return string
	 */
	public static function pretty( $local ) {
		return substr( $local, 0, 3 ) . '-' . substr( $local, 3 );
	}

	/**
	 * Every spelling the number may be stored under in billing_phone.
	 *
	 * @param string $local Canonical form.
	 * @return string[]
	 */
	public static function variants( $local ) {
		$intl = '972' . substr( $local, 1 );
		return array_values(
			array_unique(
				array(
					$local,
					substr( $local, 0, 3 ) . '-' . substr( $local, 3 ),
					substr( $local, 0, 3 ) . '-' . substr( $local, 3, 3 ) . '-' . substr( $local, 6 ),
					substr( $local, 0, 3 ) . ' ' . substr( $local, 3 ),
					$intl,
					'+' . $intl,
					'+972-' . substr( $local, 1, 2 ) . '-' . substr( $local, 3 ),
					'+972 ' . substr( $local, 1, 2 ) . '-' . substr( $local, 3 ),
					'+972 ' . substr( $local, 1 ),
				)
			)
		);
	}

	/**
	 * The account this number belongs to, if any. The number the account proved by SMS wins; then
	 * the billing phone, newest account first.
	 *
	 * @param string $local Canonical form.
	 * @return WP_User|null
	 */
	public static function user( $local ) {
		if ( '' === $local ) {
			return null;
		}
		$proven = get_users(
			array(
				'meta_key'    => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $local, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'      => 1,
				'count_total' => false,
				'blog_id'     => is_multisite() ? 0 : get_current_blog_id(),
			)
		);
		if ( ! empty( $proven ) ) {
			return $proven[0];
		}
		$billing = get_users(
			array(
				'meta_key'     => 'billing_phone', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'   => self::variants( $local ), // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'IN',
				'number'       => 1,
				'orderby'      => 'ID',
				'order'        => 'DESC',
				'count_total'  => false,
				'blog_id'      => is_multisite() ? 0 : get_current_blog_id(),
			)
		);
		return empty( $billing ) ? null : $billing[0];
	}

	/**
	 * The newest order placed with this number, account or guest.
	 *
	 * @param string $local Canonical form.
	 * @return WC_Order|null
	 */
	public static function last_order( $local ) {
		if ( '' === $local || ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$best = null;
		foreach ( self::variants( $local ) as $v ) {
			$found = wc_get_orders(
				array(
					'billing_phone' => $v,
					'limit'         => 1,
					'orderby'       => 'date',
					'order'         => 'DESC',
					'type'          => 'shop_order',
				)
			);
			if ( empty( $found ) ) {
				continue;
			}
			// Never trust the query alone: an order store that ignored the filter would hand back
			// somebody else's order. The number on the order must really be this one.
			if ( self::normalize( $found[0]->get_billing_phone() ) !== $local ) {
				continue;
			}
			if ( null === $best || $found[0]->get_date_created() > $best->get_date_created() ) {
				$best = $found[0];
			}
		}
		return $best;
	}
}
