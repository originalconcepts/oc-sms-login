<?php
/**
 * OC Pluginz admin library — loader.
 *
 * Every plugin of the OC Pluginz suite bundles this folder (loader.php +
 * class-oc-pluginz.php) and includes THIS file at the top level of its main
 * plugin file. Each copy registers its own version here; when plugins_loaded
 * fires, the newest copy present on the site is the one that gets loaded,
 * so a plugin that was not updated yet can never pin the whole suite to an
 * old version of the shared chrome.
 *
 * Plugins talk to the library through oc_pluginz_register() (safe to call at
 * any time, even before the class exists) or OC_Pluginz::register() once
 * plugins_loaded has run.
 *
 * Canonical copy: originalconcepts/ocpluginz-hub, lib/oc-pluginz/.
 * Do not edit the copy inside a plugin — update the canonical one and copy it.
 *
 * @package OC_Pluginz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'oc_pluginz_lib' ) ) {

	/**
	 * Register one bundled copy of the library.
	 *
	 * @param string $version Library version of that copy (class-oc-pluginz.php's OC_Pluginz::VERSION).
	 * @param string $file    Absolute path of that copy's class-oc-pluginz.php.
	 */
	function oc_pluginz_lib( $version, $file ) {
		global $oc_pluginz_lib;
		if ( ! is_array( $oc_pluginz_lib ) ) {
			$oc_pluginz_lib = array();
		}
		$oc_pluginz_lib[ (string) $version ] = (string) $file;

		if ( did_action( 'plugins_loaded' ) ) {
			// Registered late (included from a plugins_loaded callback): boot with the best copy known so far.
			oc_pluginz_lib_boot();
		} elseif ( ! has_action( 'plugins_loaded', 'oc_pluginz_lib_boot' ) ) {
			add_action( 'plugins_loaded', 'oc_pluginz_lib_boot', -1000 );
		}
	}

	/**
	 * Load the newest registered copy (admin only) and replay queued registrations.
	 */
	function oc_pluginz_lib_boot() {
		global $oc_pluginz_lib, $oc_pluginz_queue;
		if ( ! is_admin() || class_exists( 'OC_Pluginz', false ) || empty( $oc_pluginz_lib ) ) {
			return;
		}
		uksort( $oc_pluginz_lib, 'version_compare' );
		$file = end( $oc_pluginz_lib );
		if ( ! is_readable( $file ) ) {
			return;
		}
		require_once $file;
		if ( ! class_exists( 'OC_Pluginz', false ) ) {
			return;
		}
		OC_Pluginz::init();
		if ( ! empty( $oc_pluginz_queue ) && is_array( $oc_pluginz_queue ) ) {
			foreach ( $oc_pluginz_queue as $q ) {
				OC_Pluginz::register( $q[0], $q[1] );
			}
			$oc_pluginz_queue = array();
		}
	}

	/**
	 * Register a plugin of the suite (its menu entry, pages and dashboard card).
	 * Safe at any time: queued until the library is loaded.
	 *
	 * @param string $slug Plugin slug, e.g. 'oc-rma'.
	 * @param array  $args See OC_Pluginz::register().
	 */
	function oc_pluginz_register( $slug, $args ) {
		global $oc_pluginz_queue;
		if ( class_exists( 'OC_Pluginz', false ) ) {
			OC_Pluginz::register( $slug, $args );
			return;
		}
		if ( ! is_array( $oc_pluginz_queue ) ) {
			$oc_pluginz_queue = array();
		}
		$oc_pluginz_queue[] = array( (string) $slug, (array) $args );
	}
}

oc_pluginz_lib( '1.0.0', __DIR__ . '/class-oc-pluginz.php' );
