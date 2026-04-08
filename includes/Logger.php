<?php
/**
 * Logger class for WPStatic plugin.
 *
 * Writes export logs with context (timestamp, file, line). Integrates with
 * wpstatic_export_state for session-scoped log file path.
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
 * Class Logger
 *
 * Singleton that writes to a timestamped log file for the current export session.
 */
class Logger {
	/**
	 * Transient key for status messages.
	 *
	 * @var string
	 */
	const STATUS_MESSAGES_TRANSIENT = 'wpstatic_status_messages';

	/**
	 * Max number of buffered status messages.
	 *
	 * @var int
	 */
	const STATUS_BUFFER_LIMIT = 200;

	/**
	 * Single instance of the class.
	 *
	 * @var Logger|null
	 */
	private static $instance = null;

	/**
	 * Cached log file path for the current session.
	 *
	 * @var string|false|null Null when not yet resolved.
	 */
	private $log_file_path = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return Logger
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
	 * Resolve and cache the log file path from the active export.
	 *
	 * Reads the latest export row from wpstatic_exports, calls
	 * wpstatic_directories()->get_log_file() using export_key, saves
	 * log_file_path back to the exports table, and caches it in the instance.
	 *
	 * @return string|false Absolute log file path, or false on failure.
	 */
	private function get_log_file_path() {
		if ( null !== $this->log_file_path ) {
			return $this->log_file_path;
		}

		$export = $this->get_active_export();
		if ( ! $export || empty( $export['id'] ) || empty( $export['export_key'] ) ) {
			return false;
		}

		// Use cached path from the export row if already set and file exists.
		if ( ! empty( $export['log_file_path'] ) && is_file( $export['log_file_path'] ) ) {
			$this->log_file_path = $export['log_file_path'];
			return $this->log_file_path;
		}

		$path = wpstatic_directories()->get_log_file( $export['export_key'] );
		if ( false === $path ) {
			return false;
		}

		global $wpdb;
		$exports_table = wpstatic_table_name( 'exports' );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$exports_table,
			array(
				'log_file_path' => $path,
				'updated_at'    => current_time( 'mysql' ),
			),
			array(
				'id' => (int) $export['id'],
			),
			array(
				'%s',
				'%s',
			),
			array(
				'%d',
			)
		);

		$this->log_file_path = $path;

		return $path;
	}

	/**
	 * Get the active export row for logging.
	 *
	 * Returns the most recent export row when one exists. Does not create
	 * a new export; export creation is done by the collection/export flow
	 * (e.g. URL_Collector::collect() or the admin "Start export" action).
	 *
	 * @return array|null Export row as associative array or null when no export exists.
	 */
	private function get_active_export() {
		global $wpdb;

		$exports_table = wpstatic_table_name( 'exports' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT 1',
				$exports_table
			),
			ARRAY_A
		);

		if ( is_array( $row ) && ! empty( $row['id'] ) ) {
			return $row;
		}

		return null;
	}

	/**
	 * Validate that a path is within allowed boundaries.
	 *
	 * Ensures path is under a base by checking the character after the base
	 * is a directory separator, preventing bypasses like public_html_backup
	 * matching public_html.
	 *
	 * @param string $path Absolute file path.
	 * @return bool True if path is allowed.
	 */
	private function is_path_allowed( $path ) {
		$wpstatic_base = wpstatic_directories()->get_upload_dir_base();
		if ( false === $wpstatic_base || empty( $wpstatic_base['path'] ) ) {
			return false;
		}

		return $this->is_path_under_base( wp_normalize_path( $path ), $wpstatic_base['path'] );
	}

	/**
	 * Check if path is under base directory, respecting directory boundaries.
	 *
	 * @param string $path Absolute file path.
	 * @param string $base Absolute base directory path.
	 * @return bool True if path is under base.
	 */
	private function is_path_under_base( $path, $base ) {
		$base = untrailingslashit( $base ) . '/';

		if ( 0 !== strpos( $path, $base ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Append a raw string to the log file.
	 *
	 * @param string $string Raw string to append (no newline added).
	 * @return bool True on success, false on failure.
	 */
	public function write_log( $string ) {
		$path = $this->get_log_file_path();
		if ( false === $path || ! $this->is_path_allowed( $path ) ) {
			return false;
		}

		try {
			$file = new \SplFileObject( $path, 'ab' );
			$bytes = $file->fwrite( $string . "\n" );
		} catch ( \RuntimeException $exception ) {
			return false;
		}

		return false !== $bytes;
	}

	/**
	 * Format a structured log entry with context.
	 *
	 * @param string $level   Log level (info, error, notice, status).
	 * @param string $message Log message.
	 * @return string Formatted log line.
	 */
	private function format_log_entry( $level, $message ) {
		$timestamp = current_time( 'mysql' );
		$context   = '';

		$summary = wp_debug_backtrace_summary( __CLASS__, 2, false );
		if ( is_array( $summary ) && ! empty( $summary ) ) {
			$frames          = array_reverse( $summary );
			$wpstatic_frames = array();

			foreach ( $frames as $frame ) {
				if ( false !== strpos( $frame, 'WPStatic\\' ) ) {
					$wpstatic_frames[] = $frame;
				}
			}

			$context_frames = ! empty( $wpstatic_frames ) ? $wpstatic_frames : $frames;
			$context        = ' (' . implode( ', ', $context_frames ) . ')';
		}

		return sprintf( '[%s] [%s] %s%s', $timestamp, $level, $message, $context );
	}

	/**
	 * Log an info message with context.
	 *
	 * @param string $message Log message.
	 * @return bool True on success, false on failure.
	 */
	public function log_info( $message ) {
		return $this->write_log( $this->format_log_entry( 'info', $message ) );
	}

	/**
	 * Log an error message with context.
	 *
	 * @param string $message Log message.
	 * @return bool True on success, false on failure.
	 */
	public function log_error( $message ) {
		return $this->write_log_with_status( 'error', $message, true );
	}

	/**
	 * Log a notice message with context.
	 *
	 * @param string $message Log message.
	 * @return bool True on success, false on failure.
	 */
	public function log_notice( $message ) {
		return $this->write_log_with_status( 'notice', $message, true );
	}

	/**
	 * Log a status message with context.
	 *
	 * @param string $message Log message.
	 * @return bool True on success, false on failure.
	 */
	public function log_status( $message ) {
		return $this->write_log_with_status( 'status', $message, true );
	}

	/**
	 * Return buffered status messages.
	 *
	 * @param int $after_sequence Return messages after this sequence ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_status_messages( $after_sequence = 0 ) {
		$state = get_transient( self::STATUS_MESSAGES_TRANSIENT );
		if ( ! is_array( $state ) || empty( $state['messages'] ) || ! is_array( $state['messages'] ) ) {
			return array();
		}

		$messages = array();
		foreach ( $state['messages'] as $message ) {
			if ( ! isset( $message['sequence'] ) || (int) $message['sequence'] <= (int) $after_sequence ) {
				continue;
			}
			$messages[] = $message;
		}

		return $messages;
	}

	/**
	 * Return the last N lines of the log file.
	 *
	 * @param int $lines Number of lines to return (default 50).
	 * @return array Array of lines (empty if path invalid or file unreadable).
	 */
	public function tail( $lines = 50 ) {
		$path = $this->get_log_file_path();
		if ( false === $path || ! $this->is_path_allowed( $path ) || ! is_readable( $path ) ) {
			return array();
		}

		$content = '';
		if ( is_file( $path ) ) {
			$content = wpstatic_fs_get_contents( $path );
		}

		if ( false === $content || '' === $content ) {
			return array();
		}

		$all_lines = explode( "\n", $content );
		$all_lines = array_map( 'trim', $all_lines );
		$all_lines = array_filter( $all_lines, function ( $line ) {
			return '' !== $line;
		} );
		$all_lines = array_values( $all_lines );
		$lines     = max( 1, (int) $lines );
		$count     = count( $all_lines );

		if ( $count <= $lines ) {
			return $all_lines;
		}

		return array_slice( $all_lines, -$lines );
	}

	/**
	 * Truncate the log file to empty.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function clear() {
		$path = $this->get_log_file_path();
		if ( false === $path || ! $this->is_path_allowed( $path ) ) {
			return false;
		}

		return wpstatic_fs_put_contents( $path, '' );
	}

	/**
	 * Filter secrets from log content before download.
	 *
	 * Excludes database username, password, and admin email per workspace rules.
	 *
	 * @param string $content Raw log content.
	 * @return string Filtered content.
	 */
	private function filter_secrets( $content ) {
		$admin_email = get_option( 'admin_email' );
		if ( ! empty( $admin_email ) ) {
			$content = str_replace( $admin_email, '[REDACTED]', $content );
		}

		if ( defined( 'DB_USER' ) && '' !== DB_USER ) {
			$content = str_replace( DB_USER, '[REDACTED]', $content );
		}

		if ( defined( 'DB_PASSWORD' ) && '' !== DB_PASSWORD ) {
			$content = str_replace( DB_PASSWORD, '[REDACTED]', $content );
		}

		return $content;
	}

	/**
	 * Output the log file for download.
	 *
	 * Filters secrets (database username, password, admin email) from output.
	 * Includes full file paths.
	 *
	 * @return void
	 */
	public function download() {
		$path = $this->get_log_file_path();
		if ( false === $path || ! $this->is_path_allowed( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Log file not available.', 'wpstatic' ), '', array( 'response' => 404 ) );
		}

		$content = wpstatic_fs_get_contents( $path );
		if ( false === $content ) {
			wp_die( esc_html__( 'Log file could not be read.', 'wpstatic' ), '', array( 'response' => 500 ) );
		}

		$content = $this->filter_secrets( $content );
		$diagnostics_flag = filter_input( INPUT_GET, 'diagnostics', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$include          = is_string( $diagnostics_flag ) ? sanitize_text_field( $diagnostics_flag ) : '';
		$content          = $this->append_diagnostics_when_requested( $content, $include );
		
		$filename = basename( $path );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( esc_html( $content ) ) );
		header( 'Cache-Control: no-cache, must-revalidate' );

		echo esc_html( $content );
		exit;
	}

	/**
	 * Write a formatted log entry and optionally broadcast it.
	 *
	 * @param string $level     Log level.
	 * @param string $message   Log message.
	 * @param bool   $broadcast Whether to queue for AJAX status display.
	 * @return bool
	 */
	private function write_log_with_status( $level, $message, $broadcast ) {
		$entry = $this->format_log_entry( $level, $message );
		$wrote = $this->write_log( $entry );

		if ( $broadcast && $this->should_broadcast_status() ) {
			$this->queue_status_message( $level, $message, $entry );
		}

		return $wrote;
	}

	/**
	 * Determine whether runtime context should broadcast status messages.
	 *
	 * @return bool
	 */
	private function should_broadcast_status() {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}

		return ( 'cli' !== PHP_SAPI && 'phpdbg' !== PHP_SAPI );
	}

	/**
	 * Push a message into the transient status queue.
	 *
	 * @param string $level   Message level.
	 * @param string $message Message text.
	 * @param string $entry   Full log entry.
	 * @return void
	 */
	private function queue_status_message( $level, $message, $entry ) {
		$state = get_transient( self::STATUS_MESSAGES_TRANSIENT );
		if ( ! is_array( $state ) ) {
			$state = array(
				'sequence' => 0,
				'messages' => array(),
			);
		}

		$state['sequence'] = isset( $state['sequence'] ) ? (int) $state['sequence'] + 1 : 1;
		$state['messages'][] = array(
			'sequence' => $state['sequence'],
			'level'    => $level,
			'message'  => $message,
			'entry'    => $entry,
			'time'     => current_time( 'mysql' ),
		);

		if ( count( $state['messages'] ) > self::STATUS_BUFFER_LIMIT ) {
			$state['messages'] = array_slice( $state['messages'], -self::STATUS_BUFFER_LIMIT );
		}

		set_transient( self::STATUS_MESSAGES_TRANSIENT, $state, HOUR_IN_SECONDS );
	}

	/**
	 * Append diagnostics data to downloaded content when requested.
	 *
	 * @param string $content Log content.
	 * @param string $include Diagnostics include flag.
	 * @return string
	 */
	private function append_diagnostics_when_requested( $content, $include ) {
		if ( 'yes' !== $include ) {
			return $content;
		}

		$class_name = 'WPStatic\\Diagnostics';
		if ( ! class_exists( $class_name ) ) {
			return $content;
		}

		if ( ! is_callable( array( $class_name, 'collect' ) ) ) {
			return $content;
		}

		$diagnostics = call_user_func( array( $class_name, 'collect' ) );
		$formatted   = '';

		if ( is_callable( array( $class_name, 'format_for_log' ) ) ) {
			$formatted = (string) call_user_func( array( $class_name, 'format_for_log' ), $diagnostics );
		}

		if ( '' === trim( $formatted ) ) {
			$encoded = wp_json_encode( $diagnostics, JSON_PRETTY_PRINT );
			$formatted = is_string( $encoded ) ? $encoded : '';
		}

		return $content . "\n\n" . $formatted;
	}
}
