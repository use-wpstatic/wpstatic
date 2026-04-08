<?php
/**
 * Autoloader for WPStatic plugin classes.
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

spl_autoload_register(
	function ( $class ) {
		// Project-specific namespace prefix.
		$prefix = 'WPStatic\\';

		// Base directory for the namespace prefix.
		$base_dir = WPSTATIC_PATH . 'includes/';

		// Does the class use the namespace prefix?
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			// No, move to the next registered autoloader.
			return;
		}

		// Get the relative class name.
		$relative_class = substr( $class, $len );

		// PSR-4 style path.
		$psr4_file = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';
		if ( file_exists( $psr4_file ) ) {
			require $psr4_file;
			return;
		}

		// Legacy class- prefix path.
		$legacy_file = $base_dir . 'class-' . strtolower( str_replace( '\\', '-', $relative_class ) ) . '.php';
		if ( file_exists( $legacy_file ) ) {
			require $legacy_file;
		}
	}
);