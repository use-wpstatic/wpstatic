<?php
/**
 * Uninstall entrypoint for WPStatic.
 *
 * Copyright (C) 2026 Anindya Sundar Mandal
 *
 * This file is part of WPStatic. For full license text, see license.txt.
 *
 * @package WPStatic
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'WPSTATIC_SLUG' ) ) {
	define( 'WPSTATIC_SLUG', 'wpstatic' );
}

require_once __DIR__ . '/includes/functions-api.php';
require_once __DIR__ . '/includes/Uninstaller.php';

\WPStatic\Uninstaller::uninstall();
