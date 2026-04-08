<?php
/**
 * Diagnostics collection for WPStatic.
 *
 * Copyright (C) 2026 Anindya Sundar Mandal
 *
 * This file is part of WPStatic. For full license text, see license.txt.
 *
 * @package WPStatic
 */

namespace WPStatic;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collect system and plugin diagnostics.
 */
class Diagnostics {

	/**
	 * Collect diagnostics details.
	 *
	 * @return array<string, mixed>
	 */
	public static function collect() {
		$upload_base = wpstatic_directories()->get_upload_dir_base();
		$upload_path = ( is_array( $upload_base ) && ! empty( $upload_base['path'] ) ) ? $upload_base['path'] : '';

		return array(
			'system_information' => array(
				'wp_version'           => get_bloginfo( 'version' ),
				'php_version'          => PHP_VERSION,
				'basic_http_auth'      => ( isset( $_SERVER['PHP_AUTH_USER'] ) && '' !== $_SERVER['PHP_AUTH_USER'] ),
				'curl_available'       => function_exists( 'curl_version' ),
				'permalink_structure'  => get_option( 'permalink_structure' ),
				'permalink_is_plain'   => ( '' === get_option( 'permalink_structure' ) ),
				'seo_indexable'        => ( '0' !== (string) get_option( 'blog_public', '1' ) ),
				'caching_disabled'     => ! ( defined( 'WP_CACHE' ) && WP_CACHE ),
				'wp_cron_available'    => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
				'memory_limit'         => ini_get( 'memory_limit' ),
				'max_execution_time'   => ini_get( 'max_execution_time' ),
				'upload_dir_path'      => $upload_path,
				'upload_dir_readable'  => ( '' !== $upload_path && is_readable( $upload_path ) ),
					'upload_dir_writable'  => ( '' !== $upload_path && wp_is_writable( $upload_path ) ),
				'active_plugins'       => self::get_active_plugins(),
				'theme_info'           => self::get_theme_info(),
				'server_os'            => php_uname(),
				'web_server'           => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'mysql_privileges'     => self::get_mysql_privileges(),
			),
			'wpstatic_settings'   => wpstatic_get_all_settings(),
		);
	}

	/**
	 * Format diagnostics for log download.
	 *
	 * @param array<string, mixed> $diagnostics Diagnostics data.
	 * @return string
	 */
	public static function format_for_log( array $diagnostics ) {
		$system   = isset( $diagnostics['system_information'] ) && is_array( $diagnostics['system_information'] ) ? $diagnostics['system_information'] : array();
		$settings = isset( $diagnostics['wpstatic_settings'] ) && is_array( $diagnostics['wpstatic_settings'] ) ? $diagnostics['wpstatic_settings'] : array();

		$lines   = array();
		$lines[] = '================================ SYSTEM INFORMATION ================================';
		$lines[] = '';
		$lines   = array_merge( $lines, self::flatten_array_for_log( $system ) );
		$lines[] = '';
		$lines[] = '';
		$lines[] = '================================ WPSTATIC SETTINGS ================================';
		$lines[] = '';
		$lines   = array_merge( $lines, self::flatten_array_for_log( $settings ) );

		return implode( "\n", $lines );
	}

	/**
	 * Flatten multidimensional array for line-by-line output.
	 *
	 * @param array<string, mixed> $array  Data.
	 * @param string               $prefix Key prefix.
	 * @return string[]
	 */
	private static function flatten_array_for_log( array $array, $prefix = '' ) {
		$lines = array();

		foreach ( $array as $key => $value ) {
			$key = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( is_array( $value ) ) {
				$lines = array_merge( $lines, self::flatten_array_for_log( $value, $key ) );
				continue;
			}

			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}

			$lines[] = $key . ': ' . (string) wpstatic_sanitize_diagnostics_value( $value );
		}

		return $lines;
	}

	/**
	 * Get list of active plugin basenames.
	 *
	 * @return string[]
	 */
	private static function get_active_plugins() {
		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			return array();
		}

		return array_values( array_map( 'sanitize_text_field', $active ) );
	}

	/**
	 * Get active theme details.
	 *
	 * @return array<string, string>
	 */
	private static function get_theme_info() {
		$theme = wp_get_theme();

		return array(
			'name'    => (string) $theme->get( 'Name' ),
			'version' => (string) $theme->get( 'Version' ),
			'author'  => (string) $theme->get( 'Author' ),
		);
	}

	/**
	 * Get MySQL privilege booleans for key capabilities.
	 *
	 * @return array<string, bool>
	 */
	private static function get_mysql_privileges() {
		global $wpdb;

		$privileges = array(
			'DELETE' => false,
			'INSERT' => false,
			'SELECT' => false,
			'CREATE' => false,
			'ALTER'  => false,
			'DROP'   => false,
		);

		$rows = $wpdb->get_col( 'SHOW GRANTS FOR CURRENT_USER()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $rows ) ) {
			return $privileges;
		}

		$grants = strtoupper( implode( ' ', $rows ) );
		foreach ( $privileges as $privilege => $allowed ) {
			$privileges[ $privilege ] = ( false !== strpos( $grants, $privilege ) || false !== strpos( $grants, 'ALL PRIVILEGES' ) );
		}

		return $privileges;
	}
}
