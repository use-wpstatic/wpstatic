<?php
/**
 * Plugin Name: WPStatic
 * Description: Generate a fast, secure, static HTML version of your WordPress website. Export to ZIP.
 * Version: 1.0.2
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Anindya Sundar Mandal
 * Author URI: https://profiles.wordpress.org/speedify
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpstatic
 * Domain Path: /languages
 *
 * Copyright (C) 2026 Anindya Sundar Mandal
 *
 * This file is part of WPStatic. For full license text, see license.txt.
 *
 * @package WPStatic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'get_plugin_data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$wpstatic_plugin_data = get_plugin_data( __FILE__ );

// Define plugin constants.
define( 'WPSTATIC_PLUGIN_NAME', $wpstatic_plugin_data['Name'] );
define( 'WPSTATIC_VERSION', $wpstatic_plugin_data['Version'] );
define( 'WPSTATIC_SLUG', $wpstatic_plugin_data['TextDomain'] );
define( 'WPSTATIC_MIN_WP_VERSION', $wpstatic_plugin_data['RequiresWP'] );
define( 'WPSTATIC_MIN_PHP_VERSION', $wpstatic_plugin_data['RequiresPHP'] );
define( 'WPSTATIC_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPSTATIC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPSTATIC_URL', plugin_dir_url( __FILE__ ) );
// Maximum number of temporary export/log directories (overridable in wp-config.php).
if ( ! defined( 'WPSTATIC_MAX_TMP_DIRS' ) ) {
	define( 'WPSTATIC_MAX_TMP_DIRS', 3 );
}

// Require autoloader.
require_once WPSTATIC_PATH . 'includes/autoloader.php';

// Require global wrapper API functions.
require_once WPSTATIC_PATH . 'includes/functions-api.php';

// Require bootstrap.
require_once WPSTATIC_PATH . 'includes/bootstrap.php';

// Initialize plugin.
WPStatic\Bootstrap::get_instance();

// Activation hook.
register_activation_hook( __FILE__, array( 'WPStatic\Bootstrap', 'activate' ) );
