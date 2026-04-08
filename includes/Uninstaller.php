<?php
/**
 * Uninstall service for WPStatic.
 *
 * Copyright (C) 2026 Anindya Sundar Mandal
 *
 * This file is part of WPStatic. For full license text, see license.txt.
 *
 * @package WPStatic
 */

namespace WPStatic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin uninstall cleanup.
 */
class Uninstaller {

	/**
	 * Run uninstall cleanup.
	 *
	 * When $require_uninstall_constant is true, cleanup runs only when
	 * WP_UNINSTALL_PLUGIN is defined.
	 *
	 * @param bool $require_uninstall_constant Require WP_UNINSTALL_PLUGIN check.
	 * @return bool True when cleanup ran, false when skipped.
	 */
	public static function uninstall( $require_uninstall_constant = true ) {
		if ( $require_uninstall_constant && ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return false;
		}

		self::drop_tables();
		self::delete_directories();
		self::delete_options_and_transients();

		return true;
	}

	/**
	 * Drop plugin tables.
	 *
	 * @return void
	 */
	private static function drop_tables() {
		global $wpdb;

		$tables = array(
			wpstatic_table_name( 'url_references' ),
			wpstatic_table_name( 'urls' ),
			wpstatic_table_name( 'exports' ),
		);

		foreach ( $tables as $table ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					'DROP TABLE IF EXISTS %i',
					$table
				)
			);
		}
	}

	/**
	 * Delete export/log directories under configured upload path.
	 *
	 * @return void
	 */
	private static function delete_directories() {
		$wp_uploads = wp_upload_dir();
		$upload_base = is_array( $wp_uploads ) && ! empty( $wp_uploads['basedir'] )
			? wp_normalize_path( untrailingslashit( (string) $wp_uploads['basedir'] ) )
			: '';
		$paths = array();
		if ( '' !== $upload_base ) {
			$paths[] = $upload_base . '/' . WPSTATIC_SLUG;
		}

		$upload_option = get_option( 'wpstatic_upload_directory' );
		if ( is_array( $upload_option ) && ! empty( $upload_option['path'] ) && is_string( $upload_option['path'] ) ) {
			$configured_base = wp_normalize_path( untrailingslashit( $upload_option['path'] ) );
			$allow_outside   = (bool) get_option( 'wpstatic_prefer_temp_storage_above_document_root', false );

			if (
				$allow_outside
				|| ( '' !== $upload_base && self::path_is_within_base( $configured_base, $upload_base ) )
			) {
				$paths[] = $configured_base;
			}
		}

		$paths = array_values( array_unique( array_filter( $paths ) ) );
		foreach ( $paths as $base_path ) {
			self::delete_dir_recursive( $base_path . '/export' );
			self::delete_dir_recursive( $base_path . '/log' );
		}
	}

	/**
	 * Check whether path is within a base directory.
	 *
	 * @param string $path Absolute path.
	 * @param string $base Base absolute path.
	 * @return bool
	 */
	private static function path_is_within_base( $path, $base ) {
		$path = wp_normalize_path( untrailingslashit( (string) $path ) );
		$base = wp_normalize_path( untrailingslashit( (string) $base ) );

		if ( '' === $path || '' === $base ) {
			return false;
		}

		return ( $path === $base || 0 === strpos( $path, $base . '/' ) );
	}

	/**
	 * Delete plugin options and transients.
	 *
	 * @return void
	 */
	private static function delete_options_and_transients() {
		global $wpdb;

		$like_patterns = array(
			'wpstatic_%',
			'_transient_wpstatic_%',
			'_transient_timeout_wpstatic_%',
		);

		foreach ( $like_patterns as $pattern ) {
			$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$pattern
				)
			);

			if ( is_array( $rows ) ) {
				foreach ( $rows as $option_name ) {
					delete_option( (string) $option_name );
				}
			}
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $path Absolute path.
	 * @return void
	 */
	private static function delete_dir_recursive( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				wpstatic_fs_rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		wpstatic_fs_rmdir( $path );
	}
}
