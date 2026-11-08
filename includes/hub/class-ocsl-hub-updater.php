<?php
/**
 * Updates from the OC Pluginz package store: WordPress learns about a new
 * version from the hub, with the licence key as the ticket.
 *
 * GET /products/oc-popup/info answers {version, slug, package, url, tested,
 * requires}; the package URL streams the zip (top folder simple-popup/) for
 * an active licence or one in its grace days. The answer is kept 12 hours
 * (a failure, one hour); a site whose licence brings no updates never asks.
 *
 * Division of labour with OCSL_GitHub_Updater:
 *   - OCSL_Hub::connected() false (no key, agency mode, or a wp-config key the
 *     hub has never answered for): this class touches nothing and the GitHub
 *     updater works exactly as before.
 *   - OCSL_Hub::connected() true: this class owns the plugin's entry in the
 *     update_plugins transient and the "View details" window; the GitHub
 *     updater returns early. Whether a newer version is actually offered then
 *     depends on the licence (OCSL_Hub::updates_allowed()).
 *
 * Updates are offered, not forced: the store updates from the Plugins screen
 * as with any plugin (WordPress's own auto-update toggle works too).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCSL_Hub_Updater {

	const TRANSIENT = 'ocsl_hub_pkg';
	const TTL       = 12 * HOUR_IN_SECONDS;
	const TTL_FAIL  = HOUR_IN_SECONDS;
	const ACTION    = 'ocsl_hub_check_updates';

	public static function boot() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ), 10, 0 );
		if ( is_admin() ) {
			add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
			add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'check_now' ) );
		}
	}

	/**
	 * Is the hub's version newer than the one running here?
	 *
	 * @param array|null $info      The hub's answer.
	 * @param string     $installed The running version.
	 * @return bool
	 */
	public static function newer( $info, $installed ) {
		return is_array( $info ) && '' !== ( isset( $info['version'] ) ? (string) $info['version'] : '' ) && version_compare( (string) $info['version'], (string) $installed, '>' );
	}

	public static function basename() {
		return OCSL_BASENAME;
	}

	public static function slug() {
		return dirname( OCSL_BASENAME );
	}

	/** Forget the kept answer: the next check asks the hub again. */
	public static function forget() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The hub's package, kept 12 hours (a failure, one hour). Null without a licence that brings updates,
	 * when the hub is unreachable, or before the hub has a package.
	 *
	 * @param bool $force Ask the hub even if an answer is kept.
	 * @return array|null
	 */
	public static function info( $force = false ) {
		if ( ! OCSL_Hub::updates_allowed() ) {
			return null;
		}
		if ( ! $force ) {
			$kept = get_transient( self::TRANSIENT );
			if ( is_array( $kept ) ) {
				return '' === ( isset( $kept['version'] ) ? (string) $kept['version'] : '' ) ? null : $kept;
			}
		}
		$response = wp_remote_get(
			OCSL_Hub::url() . '/wp-json/ocpz/v1/products/' . OCSL_Hub::PRODUCT . '/info',
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'headers'   => OCSL_Hub::headers(),
			)
		);
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$data = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) || empty( $data['version'] ) || empty( $data['package'] ) ) {
			set_transient( self::TRANSIENT, array( 'version' => '' ), self::TTL_FAIL );
			return null;
		}
		$info = array(
			'version'  => (string) $data['version'],
			'package'  => (string) $data['package'],
			'url'      => isset( $data['url'] ) ? (string) $data['url'] : OCSL_Hub::url(),
			'tested'   => isset( $data['tested'] ) ? (string) $data['tested'] : '',
			'requires' => isset( $data['requires'] ) ? (string) $data['requires'] : '6.0',
		);
		set_transient( self::TRANSIENT, $info, self::TTL );
		return $info;
	}

	/**
	 * Tell WordPress about a newer package (or list the plugin under no_update so its auto-update
	 * toggle stays visible). Nothing happens while the site is not in the hub's hands.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || ! OCSL_Hub::connected() ) {
			return $transient;
		}
		$info = self::info();
		$item = (object) array(
			'id'            => 'ocpluginz/' . OCSL_Hub::PRODUCT,
			'slug'          => self::slug(),
			'plugin'        => self::basename(),
			'new_version'   => OCSL_VERSION,
			'url'           => is_array( $info ) ? $info['url'] : OCSL_Hub::url(),
			'package'       => '',
			'tested'        => is_array( $info ) ? $info['tested'] : '',
			'requires'      => is_array( $info ) ? $info['requires'] : '6.0',
			'requires_php'  => '7.4',
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'compatibility' => new stdClass(),
		);
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}
		if ( self::newer( $info, OCSL_VERSION ) ) {
			$item->new_version = $info['version'];
			$item->package     = $info['package'];

			$transient->response[ self::basename() ] = $item;
			unset( $transient->no_update[ self::basename() ] );
		} else {
			$transient->no_update[ self::basename() ] = $item;
			unset( $transient->response[ self::basename() ] );
		}
		return $transient;
	}

	/**
	 * The "View details" window on the Plugins screen.
	 *
	 * @param mixed  $result Result object or false.
	 * @param string $action The API action requested.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::slug() !== $args->slug || ! OCSL_Hub::connected() ) {
			return $result;
		}
		$info = self::info();
		if ( null === $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'OC SMS Login',
			'slug'          => self::slug(),
			'version'       => $info['version'],
			'author'        => '<a href="https://ocpluginz.com">OC Pluginz</a>',
			'homepage'      => $info['url'],
			'requires'      => $info['requires'],
			'tested'        => $info['tested'],
			'requires_php'  => '7.4',
			'download_link' => $info['package'],
			'sections'      => array(
				'description' => 'כניסה והרשמה עם קוד SMS לחנויות WooCommerce, כולל שער טלפון לפני הקופה. התוסף חינמי, ההודעות נשלחות מחשבון ה-SMS של OC Pluginz ויורדות מיתרת ההודעות של האתר.',
			),
		);
	}

	/**
	 * A "Check for updates" link in the plugin's row on the Plugins screen.
	 *
	 * @param array  $links Row meta links.
	 * @param string $file  Plugin basename.
	 * @return array
	 */
	public static function row_meta( $links, $file ) {
		if ( self::basename() !== $file || ! OCSL_Hub::connected() || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION ) ) . '">בדיקת עדכונים</a>';
		return $links;
	}

	/** The link: ask the hub now, then back to where the click came from (or the Plugins screen). */
	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'אין הרשאה.' );
		}
		check_admin_referer( self::ACTION );
		self::forget();
		OCSL_Hub::refresh();
		self::info( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$back = wp_get_referer();
		if ( ! $back || false === strpos( (string) $back, 'page=' . OCSL_Hub_Admin::PAGE ) ) {
			$back = admin_url( 'plugins.php' );
		} else {
			$back = add_query_arg( 'ocsl_hub', 'updates', remove_query_arg( array( 'ocsl_hub', 'ocsl_err' ), $back ) );
		}
		wp_safe_redirect( $back );
		exit;
	}
}
