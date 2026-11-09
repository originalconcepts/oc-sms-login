<?php
/**
 * Plugin Name: OC SMS Login (כניסה עם SMS)
 * Plugin URI: https://github.com/originalconcepts/oc-sms-login
 * Description: כניסה והרשמה עם קוד SMS לחנויות WooCommerce, ושער טלפון לפני הקופה: אורח מזין טלפון, מקבל קוד, ומגיע לקופה עם כל הפרטים. חינמי; ההודעות נשלחות מחשבון ה-SMS של OC Pluginz ויורדות מיתרת ההודעות של האתר.
 * Version: 0.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: OC Pluginz
 * Author URI: https://ocpluginz.com
 * Text Domain: oc-sms-login
 * WC requires at least: 6.0
 * Update URI: https://github.com/originalconcepts/oc-sms-login
 *
 * @package OC_SMS_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The suite's shared admin chrome (one "OC Pluginz" menu for every OC plugin on the site); the newest bundled copy wins.
require_once __DIR__ . '/oc-pluginz/loader.php';

define( 'OCSL_VERSION', '0.1.1' );
define( 'OCSL_FILE', __FILE__ );
define( 'OCSL_BASENAME', plugin_basename( __FILE__ ) );

require_once __DIR__ . '/includes/hub/class-ocsl-hub.php';
require_once __DIR__ . '/includes/hub/class-ocsl-notices.php';
require_once __DIR__ . '/includes/hub/class-ocsl-hub-updater.php';
require_once __DIR__ . '/includes/hub/class-ocsl-hub-admin.php';
require_once __DIR__ . '/includes/class-ocsl-settings.php';
require_once __DIR__ . '/includes/class-ocsl-phone.php';
require_once __DIR__ . '/includes/class-ocsl-sender.php';
require_once __DIR__ . '/includes/class-ocsl-auth.php';
require_once __DIR__ . '/includes/class-ocsl-gate.php';
require_once __DIR__ . '/includes/class-ocsl-integrations.php';
require_once __DIR__ . '/includes/class-ocsl-alerts.php';
require_once __DIR__ . '/includes/class-ocsl-admin.php';

/**
 * WooCommerce: the gate, the checkout fill-in and the order lookups need it. Without it only the
 * admin loads, with a notice.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OCSL_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		OCSL_Hub::boot();
		OCSL_Notices::boot();
		OCSL_Hub_Updater::boot();
		OCSL_Hub_Admin::boot();
		OCSL_Admin::boot();
		OCSL_Alerts::boot();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>OC SMS Login צריך את WooCommerce כדי לעבוד.</p></div>';
				}
			);
			return;
		}
		OCSL_Auth::boot();
		OCSL_Gate::boot();
		OCSL_Integrations::boot();
	},
	20
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( OCSL_Hub::CRON );
	}
);
