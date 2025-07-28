<?php
/**
 * Plugin Name: Omnisend for Gravity Forms Add-On
 * Description: A gravity forms add-on to sync contacts with Omnisend. In collaboration with Omnisnnd for WooCommerce plugin it enables better customer tracking
 * Version: 9.9.9
 * Author: Omnisend
 * Author URI: https://www.omnisend.com
 * Developer: Omnisend
 * Developer URI: https://developers.omnisend.com
 * Text Domain: omnisend-for-gravity-forms
 * ------------------------------------------------------------------------
 * Copyright 2023 Omnisend
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html

 * @package OmnisendGravityFormsPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OMNISEND_GRAVITY_ADDON_NAME    = 'Omnisend for Gravity Forms Add-On';
const OMNISEND_GRAVITY_ADDON_VERSION = '1.2.5';

add_action( 'gform_loaded', array( 'Omnisend_AddOn_Bootstrap', 'load' ), 5 );

class Omnisend_AddOn_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
			return;
		}

		require_once 'class-omnisendaddon.php';

		GFAddOn::register( 'OmnisendAddOn' );
	}
}
