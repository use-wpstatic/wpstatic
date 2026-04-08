<?php
/**
 * Directories management class for WPStatic plugin.
 *
 * Manages upload, export, and log directory paths. Determines optimal
 * directory locations (WordPress uploads by default, above webroot via opt-in),
 * handles security measures,
 * and provides directory secret management for non-Apache servers within webroot.
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
 * Class Directories
 *
 * Singleton that resolves, creates, and secures all WPStatic working directories.
 */
class Directories {

	/**
	 * Single instance of the class.
	 *
	 * @var Directories|null
	 */
	private static $instance = null;

	/**
	 * Cached upload dir base result.
	 *
	 * @var array|false|null Null when not yet resolved.
	 */
	private $upload_dir_base_cache = null;

	/**
	 * Whether upload_dir_base has already been resolved this request.
	 *
	 * @var bool
	 */
	private $upload_dir_base_checked = false;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return Directories
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Public so the class can be instantiated directly for testing.
	 */
	public function __construct() {
		// Intentionally empty — dependencies come from WordPress globals.
	}

	/**
	 * Reset the singleton instance (for testing only).
	 *
	 * @return void
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Clear internal per-request caches.
	 *
	 * @return void
	 */
	public function reset_cache() {
		$this->upload_dir_base_cache   = null;
		$this->upload_dir_base_checked = false;
	}

	/*
	|--------------------------------------------------------------------------
	| Directory resolution
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolve and return the base upload directory for WPStatic.
	 *
	 * Priority:
	 *  1. Cached WordPress option (if path is still readable & writable).
	 *  2. Home directory above the webroot (explicit opt-in only).
	 *  3. One level above the WordPress document root (explicit opt-in only).
	 *  4. WordPress default uploads directory (default behavior).
	 *
	 * @return array{path: string, last_updated: int, above_webroot: bool}|false
	 *               The option array on success, or false when no writable
	 *               directory could be found.
	 */
	public function get_upload_dir_base() {
		if ( $this->upload_dir_base_checked ) {
			return $this->upload_dir_base_cache;
		}

		// 1. Check the persisted option.
		$prefer_above_webroot = $this->prefer_temp_storage_above_document_root();
		$option               = get_option( 'wpstatic_upload_directory' );
		$option_allowed       = true;
		if ( is_array( $option ) && ! empty( $option['above_webroot'] ) && ! $prefer_above_webroot ) {
			$option_allowed = false;
		}

		if (
			$option_allowed
			&&
			is_array( $option )
			&& ! empty( $option['path'] )
			&& is_readable( $option['path'] )
			&& wp_is_writable( $option['path'] )
		) {
			$this->set_cache( $option );
			return $option;
		}

		if ( $prefer_above_webroot ) {
			// 2. Try the server home directory (above webroot).
			$result = $this->try_home_directory();
			if ( false !== $result ) {
				return $result;
			}

			// 3. Try one level above the WordPress document root.
			$result = $this->try_document_root_parent();
			if ( false !== $result ) {
				return $result;
			}
		}

		// 4. Fall back to the WordPress uploads directory.
		$result = $this->try_wp_uploads_directory();
		if ( false !== $result ) {
			return $result;
		}

		// Nothing writable — log and return false.
		$message = 'No writable directory found for uploads. ';
		if ( $prefer_above_webroot ) {
			$message .= 'Checked home directory, document root parent, and WordPress uploads directory.';
		} else {
			$message .= 'Checked WordPress uploads directory.';
		}
		wpstatic_logger()->log_error( $message );

		$this->set_cache( false );
		return false;
	}

	/**
	 * Whether above-webroot temporary storage is explicitly enabled.
	 *
	 * @return bool
	 */
	private function prefer_temp_storage_above_document_root() {
		if ( function_exists( 'wpstatic_get_option_bool' ) ) {
			return wpstatic_get_option_bool( 'wpstatic_prefer_temp_storage_above_document_root', false );
		}

		return false;
	}

	/**
	 * Whether the upload base directory lives above the webroot.
	 *
	 * @return bool True when above webroot, false otherwise (including failures).
	 */
	public function upload_dir_is_above_webroot() {
		$base = $this->get_upload_dir_base();

		if ( false === $base ) {
			return false;
		}

		return (bool) $base['above_webroot'];
	}

	/*
	|--------------------------------------------------------------------------
	| Secret management
	|--------------------------------------------------------------------------
	*/

	/**
	 * Create and persist a new upload-directory secret.
	 *
	 * Secrets are only needed when the upload dir is inside the webroot
	 * AND the server is not Apache (Apache uses .htaccess instead).
	 *
	 * Format: {timestamp}_{32-char-hex}
	 *
	 * @param int|null $timestamp Optional Unix timestamp; defaults to time().
	 * @return string|false The newly created secret, or false when not needed
	 *                      or on save failure.
	 */
	public function set_upload_dir_secret( $timestamp = null ) {
		if ( $this->upload_dir_is_above_webroot() || $this->is_apache() ) {
			return false;
		}

		if ( null === $timestamp ) {
			$timestamp = time();
		}

		$secret = $timestamp . '_' . bin2hex( random_bytes( 16 ) );

		$secrets = get_option( 'wpstatic_upload_directory_secret' );
		if ( ! is_array( $secrets ) ) {
			$secrets = array();
		}

		if ( count( $secrets ) >= WPSTATIC_MAX_TMP_DIRS ) {
			array_shift( $secrets );
			$secrets = array_values( $secrets );
		}

		$secrets[] = $secret;

		$saved = update_option( 'wpstatic_upload_directory_secret', $secrets );

		return $saved ? end( $secrets ) : false;
	}

	/**
	 * Return the latest upload-directory secret, creating one if needed.
	 *
	 * @return string|false The secret string, or false on failure / not needed.
	 */
	public function get_upload_dir_secret() {
		$secrets = get_option( 'wpstatic_upload_directory_secret' );

		if ( is_array( $secrets ) && count( $secrets ) > 0 ) {
			return end( $secrets );
		}

		return $this->set_upload_dir_secret();
	}

	/*
	|--------------------------------------------------------------------------
	| Export, log, and file paths
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the absolute path to a timestamped export directory.
	 *
	 * Creates the directory tree (and secures it) as needed. Prunes the
	 * oldest export entry when the count reaches WPSTATIC_MAX_TMP_DIRS.
	 *
	 * @param string $start_timestamp Human-readable timestamp up to seconds
	 *                                (expected format: Y-m-d_H-i-s, e.g. 2026-02-11_15-30-45).
	 * @return string|false Absolute path (no trailing slash), or false on failure.
	 */
	public function get_export_dir( $start_timestamp ) {
		$base = $this->get_upload_dir_base();

		if ( false === $base ) {
			return false;
		}

		$base_path   = untrailingslashit( $base['path'] );
		$export_root = $base_path . '/export';

		$this->ensure_directory( $export_root );

		if ( $this->upload_dir_is_above_webroot() || $this->is_apache() ) {
			$export_parent = $export_root;
		} else {
			$secret = $this->get_upload_dir_secret();
			if ( false === $secret ) {
				return false;
			}
			$export_parent = $export_root . '/' . $secret;
			$this->ensure_directory( $export_parent );
		}

		// Keep only recent export rows when database export history reaches capacity.
		$this->prune_export_rows_for_capacity();

		// Prune the oldest export entry when at capacity.
		$this->prune_oldest_entry( $export_parent );

		$export_dir = $export_parent . '/' . WPSTATIC_SLUG . '-export-' . $start_timestamp;

		$this->ensure_directory( $export_dir );

		return $export_dir;
	}

	/**
	 * Return the absolute path to the log directory.
	 *
	 * Creates the directory (and secures it) if it does not exist.
	 *
	 * @return string|false Absolute path (no trailing slash), or false on failure.
	 */
	public function get_log_dir() {
		$base = $this->get_upload_dir_base();

		if ( false === $base ) {
			return false;
		}

		$base_path = untrailingslashit( $base['path'] );

		if ( $this->upload_dir_is_above_webroot() || $this->is_apache() ) {
			$log_dir = $base_path . '/log';
		} else {
			$secret = $this->get_upload_dir_secret();
			if ( false === $secret ) {
				return false;
			}
			$log_dir = $base_path . '/log/' . $secret;
		}

		$this->ensure_directory( $log_dir );

		return $log_dir;
	}

	/**
	 * Return the absolute path to a timestamped log file.
	 *
	 * Prunes the oldest log entry when the count reaches WPSTATIC_MAX_TMP_DIRS.
	 * Creates the file if it does not exist; returns false if creation fails.
	 *
	 * @param string $start_timestamp Human-readable timestamp up to seconds
	 *                                (expected format: Y-m-d_H-i-s, e.g. 2026-02-11_15-30-45).
	 * @return string|false Absolute file path, or false on failure.
	 */
	public function get_log_file( $start_timestamp ) {
		$log_dir = $this->get_log_dir();

		if ( false === $log_dir ) {
			return false;
		}

		// Prune the oldest log entry when at capacity.
		$this->prune_oldest_entry( $log_dir );

		$log_file = $log_dir . '/' . WPSTATIC_SLUG . '-export-log-' . $start_timestamp . '.txt';

		if ( ! file_exists( $log_file ) ) {
			if ( ! wpstatic_fs_put_contents( $log_file, '' ) ) {
				return false;
			}
		}

		return $log_file;
	}

	/**
	 * Delete all log files under the log directory.
	 *
	 * @return bool
	 */
	public function delete_log_files() {
		$log_dir = $this->get_log_dir();
		if ( false === $log_dir || ! is_dir( $log_dir ) ) {
			$this->log_delete_failure( 'delete_log_files', (string) $log_dir, 'Log directory is missing or unavailable.' );
			return false;
		}

		if ( ! $this->assert_delete_target_within_base( $log_dir, 'delete_log_files' ) ) {
			return false;
		}

		return $this->delete_directory_children( $log_dir );
	}

	/**
	 * Delete all temporary export directories under export root.
	 *
	 * @return bool
	 */
	public function delete_temporary_export_directories() {
		$base = $this->get_upload_dir_base();
		$deleted_rows = $this->delete_non_export_table_rows();
		if ( false === $base || empty( $base['path'] ) ) {
			$this->log_delete_failure( 'delete_temporary_export_directories', '', 'Upload directory base is unavailable.' );
			return $deleted_rows;
		}

		$export_root = untrailingslashit( $base['path'] ) . '/export';
		$deleted_directories = false;
		if ( is_dir( $export_root ) ) {
			if ( $this->assert_delete_target_within_base( $export_root, 'delete_temporary_export_directories' ) ) {
				$deleted_directories = $this->delete_directory_children( $export_root );
			}
		}

		return $deleted_directories || $deleted_rows;
	}

	/*
	|--------------------------------------------------------------------------
	| Security
	|--------------------------------------------------------------------------
	*/

	/**
	 * Secure a directory against direct HTTP access.
	 *
	 * - Apache / LiteSpeed: single .htaccess at the upload base dir.
	 * - Other servers: index.php guard files + restrictive permissions.
	 *
	 * Skipped entirely when the upload dir is above the webroot.
	 *
	 * @param string $path Absolute directory path to secure.
	 * @return void
	 */
	public function secure_directory( $path ) {
		if ( $this->upload_dir_is_above_webroot() ) {
			return;
		}

		if ( $this->is_apache() ) {
			$this->secure_directory_apache();
		} else {
			$this->secure_directory_non_apache( $path );
		}
	}

	/**
	 * Determine whether the web server is Apache or LiteSpeed.
	 *
	 * @return bool
	 */
	public function is_apache() {
		// WordPress core global (set in wp-includes/vars.php).
		global $is_apache;

		if ( isset( $is_apache ) ) {
			return (bool) $is_apache;
		}

		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$sw = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
			return ( false !== strpos( $sw, 'Apache' ) || false !== strpos( $sw, 'LiteSpeed' ) );
		}

		return false;
	}

	/*
	|--------------------------------------------------------------------------
	| Document root
	|--------------------------------------------------------------------------
	*/

	/**
	 * Return the document root (WordPress home path) of this installation.
	 *
	 * Uses WordPress core get_home_path() which accounts for subdirectory installs.
	 *
	 * @return string|false Absolute path without trailing slash, or false.
	 */
	public function get_document_root_dir() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home_path = get_home_path();

		if ( empty( $home_path ) ) {
			return false;
		}

		return untrailingslashit( $home_path );
	}

	/*
	|--------------------------------------------------------------------------
	| Protected helpers (overridable in test subclasses)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Detect the current user's home directory.
	 *
	 * Checks multiple sources in priority order:
	 *  1. POSIX getpwuid (most reliable on Linux).
	 *  2. $_SERVER['HOME'].
	 *  3. getenv('HOME').
	 *  4. DOCUMENT_ROOT — one level up.
	 *  5. CONTEXT_DOCUMENT_ROOT — one level up.
	 *  6. public_html detection from ABSPATH (cPanel hosts).
	 *
	 * @return string|false Absolute path (no trailing slash), or false.
	 */
	protected function get_home_directory() {
		// 1. POSIX (most reliable on Linux).
		if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_getuid' ) ) {
			$info = posix_getpwuid( posix_getuid() );
			if ( is_array( $info ) && ! empty( $info['dir'] ) ) {
				$dir = rtrim( $info['dir'], '/' );
				if ( is_dir( $dir ) && is_readable( $dir ) && wp_is_writable( $dir ) ) {
					return $dir;
				}
			}
		}

		// 2. $_SERVER['HOME'].
		$server_home = isset( $_SERVER['HOME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HOME'] ) ) : '';
		if ( '' !== $server_home
			&& is_dir( $server_home )
			&& is_readable( $server_home ) && wp_is_writable( $server_home ) ) {
			return rtrim( $server_home, '/' );
		}

		// 3. Environment variable HOME.
		$home = getenv( 'HOME' );
		if ( false !== $home && '' !== $home && is_dir( $home )
			&& is_readable( $home ) && wp_is_writable( $home ) ) {
			return rtrim( $home, '/' );
		}

		// 4. DOCUMENT_ROOT — go up one level.
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '';
		if ( ! empty( $document_root ) ) {
			$parent = dirname( rtrim( $document_root, '/' ) );
			if ( '' !== $parent && '/' !== $parent && is_dir( $parent )
				&& is_readable( $parent ) && wp_is_writable( $parent ) ) {
				return $parent;
			}
		}

		// 5. CONTEXT_DOCUMENT_ROOT — go up one level.
		$context_document_root = isset( $_SERVER['CONTEXT_DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTEXT_DOCUMENT_ROOT'] ) ) : '';
		if ( ! empty( $context_document_root ) ) {
			$parent = dirname( rtrim( $context_document_root, '/' ) );
			if ( '' !== $parent && '/' !== $parent && is_dir( $parent )
				&& is_readable( $parent ) && wp_is_writable( $parent ) ) {
				return $parent;
			}
		}

		// 6. Detect home dir from public_html in ABSPATH (common on cPanel).
		$pos = strpos( ABSPATH, DIRECTORY_SEPARATOR . 'public_html' );
		if ( false !== $pos ) {
			$above = substr( ABSPATH, 0, $pos );
				if ( '' !== $above && is_dir( $above )
					&& is_readable( $above ) && wp_is_writable( $above ) ) {
					return rtrim( $above, '/' );
				}
			}

		return false;
	}

	/*
	|--------------------------------------------------------------------------
	| Private helpers — directory resolution
	|--------------------------------------------------------------------------
	*/

	/**
	 * Attempt to set up the upload directory inside the user's home folder.
	 *
	 * @return array|false Option array on success, false otherwise.
	 */
	private function try_home_directory() {
		$home_dir = $this->get_home_directory();

		if ( false === $home_dir || ! is_readable( $home_dir ) || ! wp_is_writable( $home_dir ) ) {
			return false;
		}

		return $this->setup_upload_dir( $home_dir, true );
	}

	/**
	 * Attempt to set up the upload directory one level above the document root.
	 *
	 * @return array|false Option array on success, false otherwise.
	 */
	private function try_document_root_parent() {
		$doc_root = $this->get_document_root_dir();

		if ( empty( $doc_root ) || false === $doc_root ) {
			return false;
		}

		$parent = dirname( $doc_root );

		if ( empty( $parent ) || '/' === $parent || '.' === $parent ) {
			return false;
		}

		if ( ! is_readable( $parent ) || ! wp_is_writable( $parent ) ) {
			return false;
		}

		$above_webroot = $this->is_path_above_webroot( $parent );

		return $this->setup_upload_dir( $parent, $above_webroot );
	}

	/**
	 * Attempt to set up the upload directory inside WP uploads.
	 *
	 * @return array|false Option array on success, false otherwise.
	 */
	private function try_wp_uploads_directory() {
		$wp_uploads = wp_upload_dir();
		$basedir    = $wp_uploads['basedir'];

		if ( ! is_readable( $basedir ) || ! wp_is_writable( $basedir ) ) {
			wpstatic_logger()->log_error(
				sprintf(
					'WordPress uploads directory is not readable/writable: %s',
					$basedir
				)
			);
			return false;
		}

		return $this->setup_upload_dir( $basedir, false );
	}

	/**
	 * Create the WPStatic sub-directory inside a parent directory and persist the option.
	 *
	 * @param string $parent_dir    Absolute path to the parent directory.
	 * @param bool   $above_webroot Whether the resulting path is above the webroot.
	 * @return array|false Option array on success, false on failure.
	 */
	private function setup_upload_dir( $parent_dir, $above_webroot ) {
		$upload_path = trailingslashit( $parent_dir ) . WPSTATIC_SLUG;

		if ( ! is_dir( $upload_path ) && ! wp_mkdir_p( $upload_path ) ) {
			wpstatic_logger()->log_error( sprintf( 'Failed to create directory: %s', $upload_path ) );
			return false;
		}

		if ( ! is_readable( $upload_path ) || ! wp_is_writable( $upload_path ) ) {
			wpstatic_logger()->log_error( sprintf( 'Directory is not readable/writable: %s', $upload_path ) );
			return false;
		}

		$option = array(
			'path'          => trailingslashit( $upload_path ),
			'last_updated'  => time(),
			'above_webroot' => $above_webroot,
		);

		update_option( 'wpstatic_upload_directory', $option );
		$this->set_cache( $option );

		if ( ! $above_webroot ) {
			$this->secure_directory( $upload_path );
		}

		return $option;
	}

	/**
	 * Determine whether a filesystem path is above the web-accessible document root.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool True when the webroot is a child of $path.
	 */
	private function is_path_above_webroot( $path ) {
		$path = rtrim( $path, '/' );

		$webroot = '';
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '';
		if ( ! empty( $document_root ) ) {
			$webroot = rtrim( $document_root, '/' );
		} else {
			$webroot = rtrim( ABSPATH, '/' );
		}

		if ( empty( $webroot ) ) {
			return false;
		}

		// Path is "above webroot" when the webroot starts with "$path/".
		return ( 0 === strpos( $webroot, $path . '/' ) );
	}

	/**
	 * Delete all children from a directory, preserving the root.
	 *
	 * @param string $root Directory path.
	 * @return bool
	 */
	private function delete_directory_children( $root ) {
		if ( ! $this->assert_delete_target_within_base( $root, 'delete_directory_children root' ) ) {
			return false;
		}

		$ok    = true;
		$items = scandir( $root );

		if ( ! is_array( $items ) ) {
			$this->log_delete_failure( 'delete_directory_children', $root, 'Failed to read directory entries.' );
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $root . '/' . $item;
			if ( is_dir( $path ) ) {
				$ok = $this->delete_directory_tree( $path ) && $ok;
			} else {
				$ok = $this->delete_file_with_logging( $path, 'delete_directory_children' ) && $ok;
			}
		}

		return $ok;
	}

	/**
	 * Delete a directory tree recursively.
	 *
	 * @param string $path Directory path.
	 * @return bool
	 */
	private function delete_directory_tree( $path ) {
		if ( ! $this->assert_delete_target_within_base( $path, 'delete_directory_tree root' ) ) {
			return false;
		}

		$items = scandir( $path );
		if ( ! is_array( $items ) ) {
			$this->log_delete_failure( 'delete_directory_tree', $path, 'Failed to read directory entries.' );
			return false;
		}

		$ok = true;

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$child = $path . '/' . $item;
			if ( is_dir( $child ) ) {
				$ok = $this->delete_directory_tree( $child ) && $ok;
			} else {
				$ok = $this->delete_file_with_logging( $child, 'delete_directory_tree' ) && $ok;
			}
		}

		return $this->remove_directory_with_logging( $path, 'delete_directory_tree' ) && $ok;
	}

	/**
	 * Store the resolved value in the per-request cache.
	 *
	 * @param array|false $value Resolved option or false.
	 * @return void
	 */
	private function set_cache( $value ) {
		$this->upload_dir_base_cache   = $value;
		$this->upload_dir_base_checked = true;
	}

	/**
	 * Ensure a directory exists and is secured when inside the webroot.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private function ensure_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! $this->upload_dir_is_above_webroot() ) {
			$this->secure_directory( $dir );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Private helpers — pruning
	|--------------------------------------------------------------------------
	*/

	/**
	 * Delete the oldest timestamped entry in a directory when at capacity.
	 *
	 * Handles two naming patterns:
	 *  1. {slug}-export-{Y-m-d_H-i-s}   or   {slug}-export-log-{Y-m-d_H-i-s}.txt
	 *  2. {unix_ts}_{hex32}  (secret directories)
	 *
	 * @param string $dir Absolute directory path to scan.
	 * @return void
	 */
	private function prune_oldest_entry( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );

		if ( false === $items ) {
			return;
		}

		$total_count = 0;
		$timestamped = array();

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$total_count++;
			$ts = $this->parse_entry_timestamp( $item );

			if ( false !== $ts ) {
				$timestamped[ untrailingslashit( $dir ) . '/' . $item ] = $ts;
			}
		}

		// Prune oldest entries until under the limit (so adding the new one stays within limit).
		while ( $total_count >= WPSTATIC_MAX_TMP_DIRS && ! empty( $timestamped ) ) {
			asort( $timestamped );
			$oldest_path = array_key_first( $timestamped );
			unset( $timestamped[ $oldest_path ] );

			if ( is_dir( $oldest_path ) ) {
				$this->remove_directory_recursive( $oldest_path );
			} elseif ( is_file( $oldest_path ) ) {
				wp_delete_file( $oldest_path );
			}

			$total_count--;
		}
	}

	/**
	 * Keep only the newest (WPSTATIC_MAX_TMP_DIRS - 1) export rows.
	 *
	 * Deletes related rows from wpstatic_urls and wpstatic_url_references for
	 * the removed exports.
	 *
	 * @return void
	 */
	private function prune_export_rows_for_capacity() {
		global $wpdb;

		$exports_table = wpstatic_table_name( 'exports' );
		$limit         = (int) WPSTATIC_MAX_TMP_DIRS;
		$total_rows    = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $exports_table )
		);

		if ( $limit < 1 || $total_rows < $limit ) {
			return;
		}

		$keep = max( 0, $limit - 1 );
		$ids  = array();
		if ( $keep > 0 ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					'SELECT id FROM %i ORDER BY id DESC LIMIT %d',
					$exports_table,
					$keep
				)
			);
		}

		$delete_ids = $this->get_old_export_ids_to_delete( $ids );
		if ( empty( $delete_ids ) ) {
			return;
		}

		$this->delete_related_rows_by_export_ids( $delete_ids );
		foreach ( $delete_ids as $delete_id ) {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$exports_table,
				array( 'id' => (int) $delete_id ),
				array( '%d' )
			);
		}
	}

	/**
	 * Return export IDs that should be deleted.
	 *
	 * @param array<int, mixed> $keep_ids Export IDs to keep.
	 * @return int[]
	 */
	private function get_old_export_ids_to_delete( array $keep_ids ) {
		global $wpdb;
		$exports_table = wpstatic_table_name( 'exports' );

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( 'SELECT id FROM %i', $exports_table )
		);
		$all_ids = array_map( 'intval', is_array( $rows ) ? $rows : array() );

		if ( empty( $keep_ids ) ) {
			return $all_ids;
		}

		$keep_ids = array_values( array_map( 'intval', $keep_ids ) );
		$keep_map = array_fill_keys( $keep_ids, true );
		$delete   = array();
		foreach ( $all_ids as $id ) {
			if ( ! isset( $keep_map[ $id ] ) ) {
				$delete[] = $id;
			}
		}

		return $delete;
	}

	/**
	 * Delete URL/reference rows related to the given exports.
	 *
	 * @param int[] $export_ids Export IDs.
	 * @return void
	 */
	private function delete_related_rows_by_export_ids( array $export_ids ) {
		if ( empty( $export_ids ) ) {
			return;
		}

		global $wpdb;
		$urls_table = wpstatic_table_name( 'urls' );
		$refs_table = wpstatic_table_name( 'url_references' );
		foreach ( $export_ids as $export_id ) {
			$export_id = (int) $export_id;
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$refs_table,
				array( 'export_id' => $export_id ),
				array( '%d' )
			);
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$urls_table,
				array( 'export_id' => $export_id ),
				array( '%d' )
			);
		}
	}

	/**
	 * Delete all rows from non-export tables.
	 *
	 * @return bool
	 */
	private function delete_non_export_table_rows() {
		global $wpdb;
		$urls_table = wpstatic_table_name( 'urls' );
		$refs_table = wpstatic_table_name( 'url_references' );

		$ref_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( 'SELECT id FROM %i', $refs_table )
		);
		$url_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( 'SELECT id FROM %i', $urls_table )
		);

		$refs_deleted = true;
		$urls_deleted = true;

		if ( is_array( $ref_ids ) ) {
			foreach ( $ref_ids as $id ) {
				$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
					$refs_table,
					array( 'id' => (int) $id ),
					array( '%d' )
				);
				if ( false === $deleted ) {
					$refs_deleted = false;
				}
			}
		}

		if ( is_array( $url_ids ) ) {
			foreach ( $url_ids as $id ) {
				$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
					$urls_table,
					array( 'id' => (int) $id ),
					array( '%d' )
				);
				if ( false === $deleted ) {
					$urls_deleted = false;
				}
			}
		}

		return $refs_deleted && $urls_deleted;
	}

	/**
	 * Parse a Unix timestamp from a directory or file entry name.
	 *
	 * Recognised patterns:
	 *  - {slug}-export-{Y-m-d_H-i-s}          (export directory)
	 *  - {slug}-export-log-{Y-m-d_H-i-s}.txt  (log file)
	 *  - {unix_ts}_{32-char-hex}               (secret directory)
	 *
	 * @param string $name Entry basename.
	 * @return int|false Unix timestamp, or false if the name is not recognised.
	 */
	private function parse_entry_timestamp( $name ) {
		$export_prefix = WPSTATIC_SLUG . '-export-';

		// Pattern 1: {slug}-export-[log-]{timestamp}[.txt].
		if ( 0 === strpos( $name, $export_prefix ) ) {
			$ts_str = substr( $name, strlen( $export_prefix ) );

			// Strip optional "log-" sub-prefix.
			if ( 0 === strpos( $ts_str, 'log-' ) ) {
				$ts_str = substr( $ts_str, 4 );
			}

			// Strip optional .txt suffix.
			$ts_str = preg_replace( '/\.txt$/', '', $ts_str );

			// Try the expected format: Y-m-d_H-i-s.
			$dt = \DateTime::createFromFormat( 'Y-m-d_H-i-s', $ts_str );
			if ( $dt && $dt->format( 'Y-m-d_H-i-s' ) === $ts_str ) {
				return $dt->getTimestamp();
			}

			// Fallback: normalise to ISO 8601 and try strtotime.
			$normalised = preg_replace(
				'/^(\d{4}-\d{2}-\d{2})[_\s](\d{2})-(\d{2})-(\d{2})$/',
				'$1T$2:$3:$4',
				$ts_str
			);
			$unix_ts = strtotime( $normalised );
			if ( false !== $unix_ts && $unix_ts > 0 ) {
				return $unix_ts;
			}
		}

		// Pattern 2: {unix_ts}_{32-char-hex} (secret directory).
		if ( preg_match( '/^(\d{10,})_[0-9a-f]{32}$/', $name, $matches ) ) {
			return (int) $matches[1];
		}

		return false;
	}

	/**
	 * Recursively remove a directory and all its contents.
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool True on success, false on failure.
	 */
	private function remove_directory_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			$this->log_delete_failure( 'remove_directory_recursive', $dir, 'Directory does not exist.' );
			return false;
		}

		if ( ! $this->assert_delete_target_within_base( $dir, 'remove_directory_recursive root' ) ) {
			return false;
		}

		$items = scandir( $dir );

		if ( false === $items ) {
			$this->log_delete_failure( 'remove_directory_recursive', $dir, 'Failed to read directory entries.' );
			return false;
		}

		$ok = true;

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = untrailingslashit( $dir ) . '/' . $item;

			if ( is_dir( $path ) ) {
				$ok = $this->remove_directory_recursive( $path ) && $ok;
			} else {
				$ok = $this->delete_file_with_logging( $path, 'remove_directory_recursive' ) && $ok;
			}
		}

		return $this->remove_directory_with_logging( $dir, 'remove_directory_recursive' ) && $ok;
	}

	/**
	 * Determine if a path is contained within the configured WPStatic base directory.
	 *
	 * Checks both normalized string paths and canonical realpaths (when available)
	 * to prevent path traversal and symlink escape during destructive operations.
	 *
	 * @param string $path Absolute path to validate.
	 * @return bool
	 */
	private function is_path_within_wpstatic_base( $path ) {
		$base = $this->get_wpstatic_base_path();
		if ( false === $base ) {
			return false;
		}

		$target = $this->normalize_path( $path );
		if ( '' === $target || ! $this->is_path_within_base_prefix( $target, $base ) ) {
			return false;
		}

		$base_real   = $this->normalize_existing_path( $base );
		$target_real = $this->normalize_existing_path( $path );

		if ( false !== $base_real && false !== $target_real ) {
			return $this->is_path_within_base_prefix( $target_real, $base_real );
		}

		return true;
	}

	/**
	 * Assert that a deletion target is safe and contained.
	 *
	 * @param string $path    Candidate target path.
	 * @param string $context Deletion context for logs.
	 * @return bool
	 */
	private function assert_delete_target_within_base( $path, $context ) {
		if ( $this->is_path_within_wpstatic_base( $path ) ) {
			return true;
		}

		$this->log_delete_failure( $context, $path, 'Deletion blocked by containment check.' );
		return false;
	}

	/**
	 * Delete a file with technical logging on failure.
	 *
	 * @param string $path    Absolute file path.
	 * @param string $context Deletion context for logs.
	 * @return bool
	 */
	private function delete_file_with_logging( $path, $context ) {
		if ( ! $this->assert_delete_target_within_base( $path, $context . ' file' ) ) {
			return false;
		}

		if ( ! is_file( $path ) && ! is_link( $path ) ) {
			$this->log_delete_failure( $context, $path, 'Path is not a file.' );
			return false;
		}

		$deleted = wp_delete_file( $path );
		if ( ! $deleted ) {
			$this->log_delete_failure( $context, $path, 'wp_delete_file() returned false.' );
		}

		return (bool) $deleted;
	}

	/**
	 * Remove a directory with technical logging on failure.
	 *
	 * @param string $path    Absolute directory path.
	 * @param string $context Deletion context for logs.
	 * @return bool
	 */
	private function remove_directory_with_logging( $path, $context ) {
		if ( ! $this->assert_delete_target_within_base( $path, $context . ' directory' ) ) {
			return false;
		}

		if ( ! is_dir( $path ) ) {
			$this->log_delete_failure( $context, $path, 'Path is not a directory.' );
			return false;
		}

		$removed = wpstatic_fs_rmdir( $path );
		if ( ! $removed ) {
			$this->log_delete_failure( $context, $path, 'wpstatic_fs_rmdir() returned false.' );
		}

		return (bool) $removed;
	}

	/**
	 * Return the normalized configured WPStatic base path.
	 *
	 * @return string|false
	 */
	private function get_wpstatic_base_path() {
		$base = $this->get_upload_dir_base();
		if ( false === $base || empty( $base['path'] ) ) {
			return false;
		}

		return $this->normalize_path( $base['path'] );
	}

	/**
	 * Normalize path separators and remove trailing slash.
	 *
	 * @param string $path Filesystem path.
	 * @return string
	 */
	private function normalize_path( $path ) {
		return untrailingslashit( wp_normalize_path( (string) $path ) );
	}

	/**
	 * Normalize a path via realpath when it exists.
	 *
	 * @param string $path Filesystem path.
	 * @return string|false
	 */
	private function normalize_existing_path( $path ) {
		$resolved = realpath( $path );
		if ( false === $resolved ) {
			return false;
		}

		return $this->normalize_path( $resolved );
	}

	/**
	 * Check whether target is exactly base or within base.
	 *
	 * @param string $target Target path.
	 * @param string $base   Base path.
	 * @return bool
	 */
	private function is_path_within_base_prefix( $target, $base ) {
		return $target === $base || 0 === strpos( $target, $base . '/' );
	}

	/**
	 * Log technical details for deletion failures.
	 *
	 * @param string $context Deletion context.
	 * @param string $path    Target path.
	 * @param string $reason  Failure reason.
	 * @return void
	 */
	private function log_delete_failure( $context, $path, $reason ) {
		if ( function_exists( 'wpstatic_logger' ) ) {
			wpstatic_logger()->log_error(
				sprintf(
					'Deletion failure (%s). Target: %s. Reason: %s',
					(string) $context,
					(string) $path,
					(string) $reason
				)
			);
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Private helpers — security
	|--------------------------------------------------------------------------
	*/

	/**
	 * Secure the upload base directory for Apache / LiteSpeed via .htaccess.
	 *
	 * Places a single .htaccess at the upload base path that denies all
	 * direct HTTP access.
	 *
	 * @return void
	 */
	private function secure_directory_apache() {
		$base = $this->get_upload_dir_base();

		if ( false === $base ) {
			return;
		}

		$htaccess_path = untrailingslashit( $base['path'] ) . '/.htaccess';

		if ( file_exists( $htaccess_path ) ) {
			return;
		}

		$rules  = "# Deny all direct HTTP access to this folder and its children\n";
		$rules .= "<IfModule mod_authz_core.c>\n";
		$rules .= "  Require all denied\n";
		$rules .= "</IfModule>\n";
		$rules .= "<IfModule !mod_authz_core.c>\n";
		$rules .= "  Deny from all\n";
		$rules .= "</IfModule>\n";

		wpstatic_fs_put_contents( $htaccess_path, $rules );
	}

	/**
	 * Secure a directory for non-Apache servers.
	 *
	 * Creates index.php guard files recursively and restricts directory
	 * permissions to owner-only.
	 *
	 * @param string $path Absolute directory path.
	 * @return void
	 */
	private function secure_directory_non_apache( $path ) {
		$this->create_index_files_recursive( $path );

		// Owner-only access for the directory.
		wpstatic_fs_chmod( $path, 0700 );
	}

	/**
	 * Recursively place index.php guard files in a directory tree.
	 *
	 * Each file returns HTTP 403 and exits immediately, preventing
	 * directory listing or content exposure.
	 *
	 * @param string $path Absolute directory path.
	 * @return void
	 */
	private function create_index_files_recursive( $path ) {
		$index_file = untrailingslashit( $path ) . '/index.php';

		if ( ! file_exists( $index_file ) ) {
			$content = wpstatic_get_export_guard_index_php_content();
			wpstatic_fs_put_contents( $index_file, $content );
		}

		// Process child directories.
		$items = scandir( $path );

		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$full_path = untrailingslashit( $path ) . '/' . $item;

			if ( is_dir( $full_path ) ) {
				$this->create_index_files_recursive( $full_path );
			}
		}
	}
}
