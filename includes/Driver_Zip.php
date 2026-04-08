<?php
/**
 * ZIP driver for WPStatic exports.
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
 * Create and download ZIP archives for exports.
 */
class Driver_Zip {

	/**
	 * Handle admin-post download action.
	 *
	 * @return void
	 */
	public function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download ZIP.', 'wpstatic' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'wpstatic_download_nonce' );

		$export_dir = $this->get_latest_export_dir();
		if ( '' === $export_dir || ! is_dir( $export_dir ) ) {
			wp_die( esc_html__( 'Export directory not found.', 'wpstatic' ), '', array( 'response' => 404 ) );
		}

		$zip_filename = $this->get_zip_filename_for_export_dir( $export_dir );
		$zip_path     = trailingslashit( sys_get_temp_dir() ) . $zip_filename;
		if ( ! $this->create_zip( $export_dir, $zip_path ) ) {
			wp_die( esc_html__( 'Failed to create ZIP file.', 'wpstatic' ), '', array( 'response' => 500 ) );
		}

		$this->mark_zip_downloaded();
		$this->stream_zip( $zip_path );
	}

	/**
	 * Create ZIP archive from export directory.
	 *
	 * @param string $export_dir Source export directory.
	 * @param string $zip_path   ZIP output path.
	 * @return bool
	 */
	public function create_zip( $export_dir, $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $export_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		$base_len = strlen( rtrim( $export_dir, '/\\' ) ) + 1;
		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			$rel  = substr( $path, $base_len );
			if ( false === $rel || '' === $rel ) {
				continue;
			}
			$rel = str_replace( '\\', '/', $rel );

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $rel );
			} else {
				if ( function_exists( 'wpstatic_should_exclude_from_zip' ) && wpstatic_should_exclude_from_zip( $rel ) ) {
					continue;
				}

				$zip->addFile( $path, $rel );
			}
		}

		return $zip->close();
	}

	/**
	 * Return the last completed export directory path.
	 *
	 * @return string
	 */
	public function get_latest_export_dir() {
		global $wpdb;
		$table = wpstatic_table_name( 'exports' );
		$rows  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT export_dir_path FROM %i WHERE status = 'completed' AND export_dir_path IS NOT NULL AND export_dir_path != '' ORDER BY id DESC",
				$table
			)
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return '';
		}

		foreach ( $rows as $path ) {
			if ( is_string( $path ) && '' !== $path && is_dir( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * Get whether ZIP download should be visible/enabled.
	 *
	 * Requires at least one existing export directory and at least one completed
	 * export job in history.
	 *
	 * @return bool
	 */
	public function can_download_zip() {
		return '' !== $this->get_latest_export_dir();
	}

	/**
	 * Mark ZIP as downloaded for current (or provided) admin user.
	 *
	 * @param int $user_id Optional user ID.
	 * @return void
	 */
	public function mark_zip_downloaded( $user_id = 0 ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			$user_id = (int) get_current_user_id();
		}
		if ( $user_id <= 0 ) {
			return;
		}

		set_transient(
			$this->get_recent_download_transient_key( $user_id ),
			(int) current_time( 'timestamp' ),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Get latest ZIP download timestamp for current (or provided) user.
	 *
	 * @param int $user_id Optional user ID.
	 * @return int
	 */
	public function get_last_zip_download_timestamp( $user_id = 0 ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			$user_id = (int) get_current_user_id();
		}
		if ( $user_id <= 0 ) {
			return 0;
		}

		$ts = get_transient( $this->get_recent_download_transient_key( $user_id ) );
		return is_numeric( $ts ) ? (int) $ts : 0;
	}

	/**
	 * Determine whether post-download instructions should remain visible.
	 *
	 * @param int $user_id Optional user ID.
	 * @return bool
	 */
	public function should_show_post_zip_instructions( $user_id = 0 ) {
		$ts = $this->get_last_zip_download_timestamp( $user_id );
		if ( $ts <= 0 ) {
			return false;
		}

		return ( (int) current_time( 'timestamp' ) - $ts ) < DAY_IN_SECONDS;
	}

	/**
	 * Build transient key for recent ZIP download marker.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function get_recent_download_transient_key( $user_id ) {
		return WPSTATIC_SLUG . '_zip_downloaded_' . (int) $user_id;
	}

	/**
	 * Build the ZIP filename from an export directory path.
	 *
	 * @param string $export_dir Export directory path.
	 * @return string
	 */
	private function get_zip_filename_for_export_dir( $export_dir ) {
		$dir_name = basename( untrailingslashit( $export_dir ) );
		$dir_name = sanitize_file_name( $dir_name );
		if ( '' === $dir_name ) {
			$dir_name = WPSTATIC_SLUG . '-export';
		}

		return $dir_name . '.zip';
	}

	/**
	 * Stream ZIP to browser.
	 *
	 * @param string $zip_path ZIP file path.
	 * @return void
	 */
	private function stream_zip( $zip_path ) {
		if ( ! is_readable( $zip_path ) ) {
			wp_die( esc_html__( 'ZIP file could not be read.', 'wpstatic' ), '', array( 'response' => 500 ) );
		}

		try {
			$zip_file = new \SplFileObject( $zip_path, 'rb' );
		} catch ( \RuntimeException $exception ) {
			wp_die( esc_html__( 'ZIP file could not be read.', 'wpstatic' ), '', array( 'response' => 500 ) );
		}

		$filename = basename( $zip_path );
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $zip_path ) );
		header( 'Cache-Control: no-cache, must-revalidate' );

		$zip_file->fpassthru();
		wp_delete_file( $zip_path );
		exit;
	}
}
