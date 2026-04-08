<?php
/**
 * Bootstrap class for WPStatic plugin.
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
 * Bootstrap class.
 *
 * Handles plugin initialization, activation, and requirements checking.
 */
class Bootstrap {
	/**
	 * Option flag used for one-time post-activation redirect.
	 *
	 * @var string
	 */
	const ACTIVATION_REDIRECT_OPTION = 'wpstatic_do_activation_redirect';

	/**
	 * Single instance of the class.
	 *
	 * @var Bootstrap|null
	 */
	private static $instance = null;

	/**
	 * Cached results of requirements check.
	 *
	 * @var array|null
	 */
	private $failed_requirements = null;

	/**
	 * Get single instance of the class.
	 *
	 * @return Bootstrap
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize plugin.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_wpstatic_log_tail', array( $this, 'ajax_log_tail' ) );
		add_action( 'wp_ajax_wpstatic_start_export', array( $this, 'ajax_start_export' ) );
		add_action( 'wp_ajax_wpstatic_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_wpstatic_pause', array( $this, 'ajax_pause_export' ) );
		add_action( 'wp_ajax_wpstatic_resume', array( $this, 'ajax_resume_export' ) );
		add_action( 'wp_ajax_wpstatic_abort', array( $this, 'ajax_abort_export' ) );
		add_action( 'wp_ajax_wpstatic_get_status_messages', array( $this, 'ajax_get_status_messages' ) );
		add_action( 'wp_ajax_wpstatic_get_active_export_status', array( $this, 'ajax_get_active_export_status' ) );
		add_action( 'wp_ajax_wpstatic_delete_log', array( $this, 'ajax_delete_log' ) );
		add_action( 'wp_ajax_wpstatic_delete_temp_dirs', array( $this, 'ajax_delete_temp_dirs' ) );
		add_action( 'admin_post_wpstatic_download_zip', array( $this, 'handle_zip_download' ) );
		add_action( 'admin_post_wpstatic_download_log', array( $this, 'handle_log_download' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
		add_filter( 'plugin_action_links_' . WPSTATIC_BASENAME, array( $this, 'add_plugin_action_links' ) );

		// Check requirements on admin pages only.
		if ( is_admin() ) {
			$failed_requirements = $this->get_failed_requirements();
			if ( ! empty( $failed_requirements ) ) {
				add_action( 'admin_notices', array( $this, 'show_requirements_notice' ) );
				return;
			}

			Admin\Menu::get_instance();
		}

		// Plugin initialization code goes here.
	}

	/**
	 * One-time redirect to plugin page after activation.
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation() {
		if ( wp_doing_ajax() || ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_option( self::ACTIVATION_REDIRECT_OPTION ) ) {
			return;
		}

		delete_option( self::ACTIVATION_REDIRECT_OPTION );

		$activate_multi = filter_input( INPUT_GET, 'activate-multi', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( null !== $activate_multi && false !== $activate_multi ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . WPSTATIC_SLUG ) );
		exit;
	}

	/**
	 * Add Settings action link on plugins page before Deactivate.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . WPSTATIC_SLUG ) ) . '">' . esc_html__( 'Settings', 'wpstatic' ) . '</a>';
		$output        = array();
		$inserted      = false;

		foreach ( $links as $key => $link ) {
			if ( ! $inserted && 'deactivate' === (string) $key ) {
				$output[] = $settings_link;
				$inserted = true;
			}
			$output[] = $link;
		}

		if ( ! $inserted ) {
			array_unshift( $output, $settings_link );
		}

		return $output;
	}

	/**
	 * Admin-post handler for ZIP download.
	 *
	 * @return void
	 */
	public function handle_zip_download() {
		wpstatic_driver_zip()->handle_download();
	}

	/**
	 * Admin-post handler for log download.
	 *
	 * @return void
	 */
	public function handle_log_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download logs.', 'wpstatic' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'wpstatic_download_log_nonce' );
		wpstatic_logger()->download();
	}

	/**
	 * AJAX: start export.
	 *
	 * @return void
	 */
	public function ajax_start_export() {
		$this->validate_export_ajax_request();
		$result = wpstatic_export_job()->start();
		$this->send_export_ajax_result( $result );
	}

	/**
	 * AJAX: process export batch.
	 *
	 * @return void
	 */
	public function ajax_process_batch() {
		$this->validate_export_ajax_request();
		$after_raw = filter_input( INPUT_POST, 'after_sequence', FILTER_SANITIZE_NUMBER_INT );
		$after     = is_string( $after_raw ) ? absint( $after_raw ) : 0;
		$result = wpstatic_export_job()->process_batch( 0, $after );
		$this->send_export_ajax_result( $result );
	}

	/**
	 * AJAX: pause export.
	 *
	 * @return void
	 */
	public function ajax_pause_export() {
		$this->validate_export_ajax_request();
		$result = wpstatic_export_job()->pause();
		$this->send_export_ajax_result( $result );
	}

	/**
	 * AJAX: resume export.
	 *
	 * @return void
	 */
	public function ajax_resume_export() {
		$this->validate_export_ajax_request();
		$result = wpstatic_export_job()->resume();
		$this->send_export_ajax_result( $result );
	}

	/**
	 * AJAX: abort export.
	 *
	 * @return void
	 */
	public function ajax_abort_export() {
		$this->validate_export_ajax_request();
		$result = wpstatic_export_job()->abort();
		$this->send_export_ajax_result( $result );
	}

	/**
	 * AJAX: get status messages for live log window.
	 *
	 * @return void
	 */
	public function ajax_get_status_messages() {
		$this->validate_export_ajax_request();
		$after_raw = filter_input( INPUT_POST, 'after_sequence', FILTER_SANITIZE_NUMBER_INT );
		$after     = is_string( $after_raw ) ? absint( $after_raw ) : 0;
		$messages = wpstatic_logger()->get_status_messages( $after );
		wp_send_json_success( array( 'messages' => $messages ) );
	}

	/**
	 * AJAX: get active export status for UI recovery after connectivity issues.
	 *
	 * @return void
	 */
	public function ajax_get_active_export_status() {
		$this->validate_export_ajax_request();
		$status = wpstatic_export_job()->get_ui_export_status();
		if ( empty( $status ) || empty( $status['has_active'] ) ) {
			wp_send_json_success(
				array(
					'has_active' => false,
					'status'     => '',
				)
			);
		}

		wp_send_json_success(
			array(
				'has_active' => true,
				'status'     => isset( $status['status'] ) ? (string) $status['status'] : 'fetching',
			)
		);
	}

	/**
	 * AJAX: delete log files.
	 *
	 * @return void
	 */
	public function ajax_delete_log() {
		$this->validate_export_ajax_request();
		$deleted = wpstatic_directories()->delete_log_files();
		if ( ! $deleted ) {
			wpstatic_logger()->log_error( 'Cleanup request failed: unable to delete one or more log files.' );
			wp_send_json_error( array( 'message' => __( 'Cleanup could not be completed. Please check server logs.', 'wpstatic' ) ) );
		}
		wp_send_json_success( array( 'message' => __( 'Log files deleted.', 'wpstatic' ) ) );
	}

	/**
	 * AJAX: delete temporary export directories.
	 *
	 * @return void
	 */
	public function ajax_delete_temp_dirs() {
		$this->validate_export_ajax_request();
		$deleted = wpstatic_directories()->delete_temporary_export_directories();
		if ( ! $deleted ) {
			wpstatic_logger()->log_error( 'Cleanup request failed: unable to delete one or more temporary export directories.' );
			wp_send_json_error( array( 'message' => __( 'Cleanup could not be completed. Please check server logs.', 'wpstatic' ) ) );
		}
		wp_send_json_success( array( 'message' => __( 'Temporary export directories deleted.', 'wpstatic' ) ) );
	}

	/**
	 * Validate export AJAX nonce and capability.
	 *
	 * @return void
	 */
	private function validate_export_ajax_request() {
		if ( ! check_ajax_referer( 'wpstatic_export_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'wpstatic' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wpstatic' ) ) );
		}
	}

	/**
	 * Send standardized AJAX result.
	 *
	 * @param array $result Result.
	 * @return void
	 */
	private function send_export_ajax_result( array $result ) {
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * AJAX handler: return last N lines of the export log as JSON.
	 *
	 * @return void
	 */
	public function ajax_log_tail() {
		if ( ! check_ajax_referer( 'wpstatic_log_tail', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$lines_raw = filter_input( INPUT_GET, 'lines', FILTER_SANITIZE_NUMBER_INT );
		$lines     = is_string( $lines_raw ) ? absint( $lines_raw ) : 50;
		$lines = max( 1, min( 500, $lines ) );

		$tail = wpstatic_logger()->tail( $lines );
		wp_send_json_success( array( 'lines' => $tail ) );
	}

	/**
	 * Plugin activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		// Check requirements before activation.
		$failed_requirements = self::check_requirements();
		if ( ! empty( $failed_requirements ) ) {
			$error_message = self::format_requirements_error( $failed_requirements );
			wp_die(
				wp_kses_post( $error_message ),
				esc_html__( 'Plugin Activation Failed', 'wpstatic' ),
				array( 'back_link' => true )
			);
		}

		// Set first installation details if not already set.
		$existing_details = get_option( 'wpstatic_first_installation_details' );
		if ( false === $existing_details ) {
			$installation_details = array(
				'version'   => WPSTATIC_VERSION,
				'timestamp' => (int) current_time( 'timestamp' ),
			);
			update_option( 'wpstatic_first_installation_details', $installation_details );
		}

		if ( false === get_option( 'wpstatic_replace_website_urls', false ) ) {
			add_option( 'wpstatic_replace_website_urls', 'offline' );
		}

		if ( false === get_option( 'wpstatic_failed_url_threshold_percent', false ) ) {
			add_option( 'wpstatic_failed_url_threshold_percent', 10 );
		}

		// Ensure required database tables exist.
		self::create_database_tables();

		// Redirect once after activation to plugin settings page.
		update_option( self::ACTIVATION_REDIRECT_OPTION, 1 );
	}

	/**
	 * Create or update the WPStatic database tables.
	 *
	 * Uses dbDelta so the schema can be evolved safely over time.
	 *
	 * @return void
	 */
	private static function create_database_tables() {
		global $wpdb;

		$charset_collate   = $wpdb->get_charset_collate();
		$exports_table     = wpstatic_table_name( 'exports' );
		$urls_table        = wpstatic_table_name( 'urls' );
		$references_table  = wpstatic_table_name( 'url_references' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$exports_sql = "
CREATE TABLE {$exports_table} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal row ID (primary key for an export session)',
  export_key VARCHAR(30) NOT NULL COMMENT 'Human-readable unique key for this export (e.g. 2026-02-22_14-30-05). Replaces start_timestamp in wpstatic_export_state.',
  status VARCHAR(20) NOT NULL DEFAULT 'collecting' COMMENT 'Export lifecycle status: collecting, fetching, writing, completed, failed, cancelled.',
  started_by BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'User ID who initiated the export.',
  total_urls INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total number of distinct URLs discovered for this export. Set after URL collection completes.',
  fetched_urls INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of URLs whose HTML/content has been successfully fetched. Incremented as batches complete.',
  settings LONGTEXT NULL COMMENT 'JSON-encoded settings/arguments used for this export (post_types, batch size, include_sitemap, etc.).',
  log_file_path VARCHAR(500) DEFAULT NULL COMMENT 'Absolute filesystem path to the log file associated with this export.',
  export_dir_path VARCHAR(500) DEFAULT NULL COMMENT 'Absolute filesystem path to the export directory associated with this export.',
  upload_dir_secret VARCHAR(50) DEFAULT NULL COMMENT 'If upload_dir_is_above_webroot is FALSE and the web server is NOT Apache.',
  created_at DATETIME NOT NULL COMMENT 'When the export was initiated (site timezone).',
  updated_at DATETIME NOT NULL COMMENT 'Last time any field on this export row was updated (status, counters, etc.).',
  completed_at DATETIME DEFAULT NULL COMMENT 'When the export reached status=completed (site timezone).',
  PRIMARY KEY (id),
  UNIQUE KEY export_key (export_key),
  KEY status_idx (status)
) {$charset_collate};
";

		$urls_sql = "
CREATE TABLE {$urls_table} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal row ID for a discovered URL within an export.',
  export_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Foreign-key reference to wpstatic_exports.id (export session this URL belongs to).',
  url VARCHAR(2048) NOT NULL COMMENT 'Absolute URL discovered during collection or crawling. Deduplicated per export via (export_id, url) unique key.',
  discovery_type VARCHAR(50) NOT NULL COMMENT 'How this URL first entered the system: seed_post, seed_page, seed_home, seed_term, seed_sitemap, seed_robots, seed_feed, crawl, etc.',
  object_id BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Associated WordPress object ID when applicable (post_id for posts/pages/attachments, term_id for terms). NULL for URLs not tied to a specific object (robots.txt, external assets, etc.).',
  object_type VARCHAR(20) DEFAULT NULL COMMENT 'Type of associated object: post, term, or NULL when not tied to a specific WP object.',
  status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'Per-URL fetch status: pending, fetched, failed, skipped.',
  fetch_attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of attempts made to fetch this URL\'s HTML/content (for retry/backoff logic).',
  created_at DATETIME NOT NULL COMMENT 'When this URL was first inserted for this export (site timezone).',
  fetched_at DATETIME DEFAULT NULL COMMENT 'When this URL was successfully fetched (status transitioned to fetched).',
  PRIMARY KEY  (id),
  UNIQUE KEY export_url_uk (export_id, url(191)),
  KEY export_status_idx (export_id, status),
  KEY export_object_idx (export_id, object_type, object_id)
) {$charset_collate};
";

		$references_sql = "
CREATE TABLE {$references_table} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal row ID for a URL reference edge.',
  export_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Denormalized export ID for fast filtering (matches wpstatic_exports.id and wpstatic_urls.export_id).',
  url_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'FK-like reference to wpstatic_urls.id: the URL that was found inside another page’s HTML.',
  source_url_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'FK-like reference to wpstatic_urls.id: the page URL whose HTML contained this reference (the referrer).',
  reference_type VARCHAR(20) NOT NULL COMMENT 'How the URL was referenced: hyperlink, stylesheet, script, image, font, favicon, other, etc.',
  created_at DATETIME NOT NULL COMMENT 'When this reference relationship was recorded (site timezone).',
  PRIMARY KEY  (id),
  KEY export_source_idx (export_id, source_url_id),
  KEY export_url_idx (export_id, url_id)
) {$charset_collate};
";

		// dbDelta can accept one statement at a time.
		dbDelta( $exports_sql );
		dbDelta( $urls_sql );
		dbDelta( $references_sql );
	}

	/**
	 * Check system requirements.
	 *
	 * @return array Array of failed requirements (empty if all pass).
	 */
	public static function check_requirements() {
		$failed = array();

		// Check PHP version.
		if ( version_compare( PHP_VERSION, WPSTATIC_MIN_PHP_VERSION, '<' ) ) {
			$failed[] = array(
				'requirement' => 'PHP Version',
				'expected'    => WPSTATIC_MIN_PHP_VERSION . ' or higher',
				'current'     => PHP_VERSION,
			);
		}

		// Check WordPress version.
		global $wp_version;
		if ( version_compare( $wp_version, WPSTATIC_MIN_WP_VERSION, '<' ) ) {
			$failed[] = array(
				'requirement' => 'WordPress Version',
				'expected'    => WPSTATIC_MIN_WP_VERSION . ' or higher',
				'current'     => $wp_version,
			);
		}

		// Check cURL.
		if ( ! function_exists( 'curl_version' ) ) {
			$failed[] = array(
				'requirement' => 'cURL Extension',
				'expected'    => 'Installed and enabled',
				'current'     => 'Not installed',
			);
		}

		return $failed;
	}

	/**
	 * Get cached requirements failures.
	 *
	 * @return array Array of failed requirements (empty if all pass).
	 */
	private function get_failed_requirements() {
		if ( null === $this->failed_requirements ) {
			$this->failed_requirements = self::check_requirements();
		}

		return $this->failed_requirements;
	}

	/**
	 * Format requirements error message.
	 *
	 * @param array $failed_requirements Array of failed requirements.
	 * @return string Formatted error message.
	 */
	private static function format_requirements_error( $failed_requirements ) {
		$message  = '<h1>' . esc_html__( 'Plugin Activation Failed', 'wpstatic' ) . '</h1>';
		$message .= '<p>' . esc_html__( 'WPStatic cannot be activated because the following system requirements are not met:', 'wpstatic' ) . '</p>';
		$message .= '<ul style="list-style: disc; margin-left: 20px;">';

		foreach ( $failed_requirements as $failed ) {
			$message .= sprintf(
				'<li><strong>%s:</strong> %s %s (%s: %s)</li>',
				esc_html( $failed['requirement'] ),
				esc_html__( 'Required', 'wpstatic' ),
				esc_html( $failed['expected'] ),
				esc_html__( 'Current', 'wpstatic' ),
				esc_html( $failed['current'] )
			);
		}

		$message .= '</ul>';
		$message .= '<p>' . esc_html__( 'Please contact your hosting provider to update your server configuration.', 'wpstatic' ) . '</p>';

		return $message;
	}

	/**
	 * Show requirements notice in admin.
	 *
	 * @return void
	 */
	public function show_requirements_notice() {
		$failed_requirements = $this->get_failed_requirements();
		if ( empty( $failed_requirements ) ) {
			return;
		}

		$message = self::format_requirements_error( $failed_requirements );
		echo '<div class="notice notice-error">' . wp_kses_post( $message ) . '</div>';
	}
}
