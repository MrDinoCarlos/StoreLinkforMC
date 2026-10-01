<?php
/*
Plugin Name: StoreLink for Minecraft by MrDino
Plugin URI: https://mrdino.es/woostorelink-plugin/
Description: Connects WooCommerce to Minecraft to deliver items after purchase.
Version: 2.0.2
Requires PHP: 8.1
Requires at least: 6.0
Requires Plugins: woocommerce
Author: MrDinoCarlos
Author URI: https://discord.gg/ddyfucfZpy
License: GPL2
Text Domain: storelinkformc
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Legacy releases declared global functions directly in their entry file.
 * WordPress can keep that file loaded while replacing/reactivating an uploaded
 * ZIP. Returning before requiring the runtime prevents a second declaration.
 */
if (defined('STORELINKFORMC_LOADED') || function_exists('storelinkformc_install')) {
    return;
}

if (!defined('STORELINKFORMC_PLUGIN_FILE')) {
    define('STORELINKFORMC_PLUGIN_FILE', __FILE__);
}
if (!defined('STORELINKFORMC_PATH')) {
    define('STORELINKFORMC_PATH', plugin_dir_path(__FILE__));
}
if (!defined('STORELINKFORMC_URL')) {
    define('STORELINKFORMC_URL', plugin_dir_url(__FILE__));
}

require_once STORELINKFORMC_PATH . 'includes/plugin-runtime.php';
