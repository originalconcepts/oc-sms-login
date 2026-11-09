<?php
/**
 * Uninstall: the plugin's options go. Accounts stay (they are the shop's
 * customers), and so does the number each one proved (user meta ocsl_phone),
 * so a reinstall knows them again. The message balance lives on the hub.
 *
 * @package OC_SMS_Login
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'ocsl_settings', 'ocsl_hub', 'ocsl_log', 'ocsl_day', 'ocsl_alert' ) as $option ) {
	delete_option( $option );
}
wp_clear_scheduled_hook( 'ocsl_hub_refresh' );
