<?php
/**
 * Plugin Name: Freya Omnisend
 * Description: A gravity forms add-on to sync contacts with Omnisend. In collaboration with Omnisnnd for WooCommerce plugin it enables better customer tracking
 * Version: 9.9.9
 * Author: JES WEB
 * Author URI: https://jeswebdevelopment.com
 * Developer: JES WEB
 * Developer URI: https://developers.omnisend.com
 * Text Domain: freya-omnisend
 * ------------------------------------------------------------------------
 * Copyright 2023 Freya
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html

 * @package OmnisendGravityFormsPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OMNISEND_GRAVITY_ADDON_NAME    = 'Omnisend for Gravity Forms Add-On';
const OMNISEND_GRAVITY_ADDON_VERSION = '9.9.9';

add_action( 'gform_loaded', array( 'Omnisend_AddOn_Bootstrap', 'load' ), 5 );

// Initialize WooCommerce subscription manager
add_action( 'init', array( 'Omnisend_AddOn_Bootstrap', 'init_wc_subscription_manager' ) );

// Show admin notice if Gravity Forms Partial Entries plugin is not active
add_action('admin_notices', function () {
	if (
		is_admin() &&
		current_user_can('activate_plugins') &&
		!is_plugin_active('gravityformspartialentries/partialentries.php') &&
		!class_exists('GF_Partial_Entries')
	) {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__('The Omnisend for Gravity Forms Add-On requires the Gravity Forms Partial Entries Add-On to be installed and activated. Please install and activate it to enable partial entry syncing with Omnisend.', 'omnisend-for-gravity-forms');
		echo '</p></div>';
	}
});


class Omnisend_AddOn_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
			return;
		}

		require_once 'class-omnisendaddon.php';

		GFAddOn::register( 'OmnisendAddOn' );
	}

	/**
	 * Initialize WooCommerce subscription manager
	 */
	public static function init_wc_subscription_manager() {		
		// Only run on live site (freyameds.com)
		$site_url = get_site_url();
		$parsed_url = parse_url($site_url);
		$domain = isset($parsed_url['host']) ? $parsed_url['host'] : '';
		
		if ($domain !== 'freyameds.com') {
			return;
		}
		
		// Check if WooCommerce Subscriptions is active
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return;
		}

		// Include the subscription manager class
		require_once 'class-omnisend-wc-subscription-manager.php';

		// Initialize the subscription manager
		new Omnisend_WC_Subscription_Manager();
	}
}
