<?php
/**
 * Export job orchestrator for WPStatic.
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
 * Manage export lifecycle and batch processing.
 */
class Export_Job {

	/**
	 * Lock transient key.
	 *
	 * @var string
	 */
	const LOCK_KEY = 'wpstatic_export_lock';

	/**
	 * Prefix for per-export batch lock transient keys.
	 *
	 * @var string
	 */
	const BATCH_LOCK_KEY_PREFIX = 'wpstatic_batch_lock_';

	/**
	 * Batch lock TTL in seconds.
	 *
	 * @var int
	 */
	const BATCH_LOCK_TTL = 45;

	/**
	 * Maximum fetch attempts per URL.
	 *
	 * @var int
	 */
	const MAX_FETCH_ATTEMPTS = 3;

	/**
	 * Default delay between chunks in milliseconds.
	 *
	 * @var int
	 */
	const DEFAULT_NEXT_WAIT_MS = 500;

	/**
	 * Option key for failed URL percentage threshold.
	 *
	 * @var string
	 */
	const FAILED_URL_THRESHOLD_OPTION = 'wpstatic_failed_url_threshold_percent';

	/**
	 * Default failed URL threshold percentage.
	 *
	 * @var int
	 */
	const DEFAULT_FAILED_URL_THRESHOLD_PERCENT = 10;

	/**
	 * Start a new export job.
	 *
	 * @param array $args Export arguments.
	 * @return array<string, mixed>
	 */
	public function start( array $args = array() ) {
		if ( ! $this->can_manage() ) {
			return $this->error_result( 'You do not have permission to start export.' );
		}

		if ( '' === get_option( 'permalink_structure' ) ) {
			wpstatic_logger()->log_error( 'Export aborted: permalink structure is Plain.' );
			return $this->error_result( 'Permalink structure is set to Plain. Please change it before exporting.' );
		}

		$upload_base = wpstatic_directories()->get_upload_dir_base();
		if ( false === $upload_base ) {
			wpstatic_logger()->log_error( 'Export aborted: upload directory base is not writable.' );
			return $this->error_result( 'Upload directory is not writable. Please check filesystem permissions.' );
		}

		$lock_error = $this->ensure_single_job_lock();
		if ( '' !== $lock_error ) {
			wpstatic_logger()->log_notice( $lock_error );
			return $this->error_result( $lock_error );
		}

		$latest = $this->get_latest_export();
		if ( $latest && ! in_array( $latest['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return array(
				'success'       => false,
				'status'        => isset( $latest['status'] ) ? (string) $latest['status'] : 'fetching',
				'error_code'    => 'already_running',
				'already_running' => true,
				'message'       => 'An export job is already in progress. Please pause, abort, or wait for completion.',
				'next_wait'     => self::DEFAULT_NEXT_WAIT_MS,
			);
		}

		$export = $this->create_export_row();
		if ( empty( $export['id'] ) ) {
			return $this->error_result( 'Failed to initialize export job.' );
		}

		$export_key = $export['export_key'];
		$timestamp  = (int) current_time( 'timestamp' );
		$secret     = null;

		if ( ! wpstatic_directories()->upload_dir_is_above_webroot() && ! wpstatic_directories()->is_apache() ) {
			$secret = wpstatic_directories()->set_upload_dir_secret( $timestamp );
		}

		$export_dir = wpstatic_directories()->get_export_dir( $export_key );
		if ( false === $export_dir ) {
			$this->abort_by_id( (int) $export['id'], 'Failed to create export directory.' );
			return $this->error_result( 'Export directory could not be created.' );
		}

		$this->update_export_row(
			(int) $export['id'],
			array(
				'status'            => 'collecting',
				'upload_dir_secret' => $secret,
				'export_dir_path'   => $export_dir,
				'updated_at'        => current_time( 'mysql' ),
			)
		);

		// Static assets from plugin/theme directories are enqueued at the end,
		// based on what was actually detected in exported wp-content.
		$args['include_static_assets'] = false;
		$collector_result = wpstatic_url_collector()->collect( $args, (int) $export['id'] );
		$total_urls       = isset( $collector_result['total'] ) ? (int) $collector_result['total'] : 0;
		$root_static_urls = $this->enqueue_document_root_static_assets( (int) $export['id'] );
		if ( $root_static_urls > 0 ) {
			wpstatic_logger()->log_info( sprintf( 'Queued %d document-root static asset URL(s).', $root_static_urls ) );
		}
		$total_urls = $this->count_export_urls( (int) $export['id'] );

			$this->update_export_row(
				(int) $export['id'],
				array(
					'status'     => 'fetching',
				'total_urls' => $total_urls,
				'updated_at' => current_time( 'mysql' ),
				)
			);

			delete_transient( Logger::STATUS_MESSAGES_TRANSIENT );
			$this->refresh_lock( (int) $export['id'] );
			wpstatic_logger()->log_status( sprintf( 'Export started. Export ID: %d', (int) $export['id'] ) );

			return array(
				'success'       => true,
				'status'        => 'fetching',
				'export_id'     => (int) $export['id'],
				'total_urls'    => $total_urls,
				'fetched_urls'  => 0,
				'message'       => 'Export job started.',
				'status_message'=> 'Collecting and processing URLs.',
				'next_wait'     => self::DEFAULT_NEXT_WAIT_MS,
			);
	}

	/**
	 * Process a single URL batch.
	 *
	 * @param int $batch_size     Batch size.
	 * @param int $after_sequence Return status messages after this sequence.
	 * @return array<string, mixed>
	 */
	public function process_batch( $batch_size = 0, $after_sequence = 0 ) {
		if ( ! $this->can_manage() ) {
			return $this->error_result( 'You do not have permission to process export batches.' );
		}

		$export = $this->get_active_export();
		if ( ! $export ) {
			return $this->error_result( 'No active export found.' );
		}

		if ( 'paused' === $export['status'] ) {
			return $this->with_status_messages(
				array(
					'success' => true,
					'status'  => 'paused',
					'message' => 'Export is paused.',
				),
				$after_sequence
			);
		}

		$export_id = (int) $export['id'];
		$this->refresh_lock( $export_id );

		if ( ! $this->acquire_batch_lock( $export_id ) ) {
			return $this->with_status_messages(
				array(
					'success'        => true,
					'status'         => 'fetching',
					'message'        => 'A previous chunk is still running. Waiting before next request.',
					'status_message' => 'A previous chunk is still running.',
					'next_wait'      => self::DEFAULT_NEXT_WAIT_MS,
				),
				$after_sequence
			);
		}

		try {
			$seed_limit      = max( 20, max( 1, (int) $batch_size ) );
			$seed_rows       = $this->get_retryable_batch_urls( $export_id, $seed_limit );
			$effective_batch = $this->resolve_batch_size( $batch_size, $seed_rows );
			$batch           = array_slice( $seed_rows, 0, $effective_batch );

			if ( $effective_batch > count( $seed_rows ) ) {
				$batch = $this->get_retryable_batch_urls( $export_id, $effective_batch );
			}

			if ( empty( $batch ) ) {
				return $this->maybe_continue_with_detected_plugin_theme_assets( $export, $after_sequence );
			}

			wpstatic_renderer()->set_export_dir( $export['export_dir_path'] );
			$fetched = 0;

			foreach ( $batch as $row ) {
				$processed = $this->process_single_url_row( $export_id, $row );
				if ( $processed ) {
					++$fetched;
				}
			}

			$this->increment_export_fetched_urls( $export_id, $fetched );
			$this->sync_export_total_urls( $export_id );
			$remaining_count = $this->count_remaining_retryable_urls( $export_id );

			if ( 0 === $remaining_count ) {
				return $this->maybe_continue_with_detected_plugin_theme_assets( $export, $after_sequence );
			}

			$status_message = sprintf( 'Fetched %d URL(s). Continuing export process.', $fetched );
			wpstatic_logger()->log_status( $status_message );

			return $this->with_status_messages(
				array(
					'success'        => true,
					'status'         => 'fetching',
					'message'        => $status_message,
					'status_message' => $status_message,
					'next_wait'      => self::DEFAULT_NEXT_WAIT_MS,
				),
				$after_sequence
			);
		} finally {
			$this->release_batch_lock( $export_id );
		}
	}

	/**
	 * Pause current export.
	 *
	 * @return array<string, mixed>
	 */
	public function pause() {
		if ( ! $this->can_manage() ) {
			return $this->error_result( 'You do not have permission to pause export.' );
		}

		$export = $this->get_active_export();
		if ( ! $export ) {
			return $this->error_result( 'No active export to pause.' );
		}

		$this->update_export_row( (int) $export['id'], array( 'status' => 'paused', 'updated_at' => current_time( 'mysql' ) ) );
		wpstatic_logger()->log_status( 'Export paused.' );

		return array( 'success' => true, 'status' => 'paused', 'message' => 'Export paused.' );
	}

	/**
	 * Resume paused export.
	 *
	 * @return array<string, mixed>
	 */
	public function resume() {
		if ( ! $this->can_manage() ) {
			return $this->error_result( 'You do not have permission to resume export.' );
		}

		$export = $this->get_active_export();
		if ( ! $export ) {
			return $this->error_result( 'No active export to resume.' );
		}

		$this->update_export_row( (int) $export['id'], array( 'status' => 'fetching', 'updated_at' => current_time( 'mysql' ) ) );
		wpstatic_logger()->log_status( 'Export resumed.' );

		return array( 'success' => true, 'status' => 'fetching', 'message' => 'Export resumed.' );
	}

	/**
	 * Abort active export.
	 *
	 * @return array<string, mixed>
	 */
	public function abort() {
		if ( ! $this->can_manage() ) {
			return $this->error_result( 'You do not have permission to abort export.' );
		}

		$export = $this->get_active_export();
		if ( ! $export ) {
			return $this->error_result( 'No active export to abort.' );
		}

		$this->abort_by_id( (int) $export['id'], 'Export aborted by user.' );
		return array( 'success' => true, 'status' => 'cancelled', 'message' => 'Export aborted.' );
	}

	/**
	 * Process one URL row from wpstatic_urls.
	 *
	 * @param int   $export_id Export ID.
	 * @param array $row       URL row.
	 * @return bool True on fetched state.
	 */
	private function process_single_url_row( $export_id, array $row ) {
		$url    = $row['url'];
		$url_id = (int) $row['id'];
		$attempt = isset( $row['fetch_attempts'] ) ? (int) $row['fetch_attempts'] + 1 : 1;

		$source_path = function_exists( 'wpstatic_resolve_local_source_path_for_url' )
			? wpstatic_resolve_local_source_path_for_url( $url )
			: '';
		if ( function_exists( 'wpstatic_should_skip_discovered_url' ) && wpstatic_should_skip_discovered_url( $url, $source_path ) ) {
			$this->mark_url_skipped( $url_id );
			wpstatic_logger()->log_notice( sprintf( 'Skipped non-exportable URL by policy: %s', $url ) );
			return false;
		}

		$result = wpstatic_renderer()->render( $url, isset( $row['object_id'] ) ? (int) $row['object_id'] : 0 );
		if ( empty( $result['success'] ) ) {
			$error = isset( $result['error'] ) ? (string) $result['error'] : '';
			if ( 'External URL — skipped.' === $error ) {
				$this->mark_url_skipped( $url_id );
				wpstatic_logger()->log_notice( sprintf( 'Skipped external URL: %s', $url ) );
				return false;
			}
			if ( 'Blocked by static export policy.' === $error ) {
				$this->mark_url_skipped( $url_id );
				wpstatic_logger()->log_notice( sprintf( 'Skipped non-exportable URL by policy: %s', $url ) );
				return false;
			}

			$this->mark_url_failed( $url_id, $attempt );
			wpstatic_logger()->log_error(
				sprintf(
					'Failed to fetch URL (attempt %d/%d): %s. Reason: %s',
					$attempt,
					self::MAX_FETCH_ATTEMPTS,
					$url,
					$this->format_fetch_failure_reason( $result )
				)
			);
			return false;
		}

		$this->rewrite_and_discover_text_assets( $export_id, $url_id, $url, $result );
		$this->mark_url_fetched( $url_id );
		wpstatic_logger()->log_info( sprintf( 'Fetched URL: %s', $url ) );
		return true;
	}

	/**
	 * Rewrite content and discover nested URLs for text assets.
	 *
	 * @param int   $export_id Export ID.
	 * @param int   $url_id    URL row ID.
	 * @param string $url      URL.
	 * @param array $result    Renderer result.
	 * @return void
	 */
	private function rewrite_and_discover_text_assets( $export_id, $url_id, $url, array $result ) {
		$content_type = isset( $result['content_type'] ) ? strtolower( (string) $result['content_type'] ) : '';
		$content      = isset( $result['content'] ) ? (string) $result['content'] : '';
		if ( '' === $content ) {
			return;
		}

		$mode     = get_option( 'wpstatic_replace_website_urls', 'offline' );
		$origin   = home_url();
		$rewriter = wpstatic_rewriter();
		$mapping  = wpstatic_renderer()->url_to_path_mapping( $url );
		$temp_path = isset( $result['temp_export_path'] ) ? (string) $result['temp_export_path'] : '';

		if ( false !== strpos( $content_type, 'text/html' ) ) {
			$extracted = wpstatic_html_parser()->extract_assets( $content, $url );
			wpstatic_html_parser()->persist_discovered_urls( $extracted, $export_id, $url_id );
			$content = $rewriter->rewrite_html( $content, $mode, $origin, $url );
		} elseif ( false !== strpos( $content_type, 'text/css' ) ) {
			$this->persist_plain_discovered_urls( wpstatic_css_parser()->extract_urls( $content, $url ), $export_id, $url_id );
			$content = $rewriter->rewrite_css( $content, $mode, $origin, $url );
		} elseif ( false !== strpos( $content_type, 'javascript' ) || false !== strpos( $content_type, 'ecmascript' ) ) {
			$this->persist_plain_discovered_urls( wpstatic_js_parser()->extract_urls( $content, $url ), $export_id, $url_id );
			$content = $rewriter->rewrite_js( $content, $mode, $origin, $url );
		}

		if ( ! empty( $mapping['export_path'] ) ) {
			if ( '' !== $temp_path ) {
				wpstatic_renderer()->save_content_via_temp( $content, $temp_path, $mapping['export_path'] );
				return;
			}

			wpstatic_renderer()->save_content( $content, $mapping['export_path'] );
		}
	}

	/**
	 * Persist discovered URLs from non-HTML parsers.
	 *
	 * @param string[] $urls      URLs.
	 * @param int      $export_id Export ID.
	 * @param int      $source_id Source URL ID.
	 * @return void
	 */
	private function persist_plain_discovered_urls( array $urls, $export_id, $source_id ) {
		global $wpdb;
		$urls_table = wpstatic_table_name( 'urls' );
		$refs_table = wpstatic_table_name( 'url_references' );

		foreach ( $urls as $url ) {
			if ( function_exists( 'wpstatic_normalize_url_for_storage' ) ) {
				$url = wpstatic_normalize_url_for_storage( $url );
			}
			$source_path = function_exists( 'wpstatic_resolve_local_source_path_for_url' )
				? wpstatic_resolve_local_source_path_for_url( $url )
				: '';
			if ( function_exists( 'wpstatic_should_skip_discovered_url' ) ) {
				if ( wpstatic_should_skip_discovered_url( $url, $source_path ) ) {
					continue;
				}
			} else {
				if ( function_exists( 'wpstatic_should_skip_root_query_url' ) && wpstatic_should_skip_root_query_url( $url ) ) {
					continue;
				}
				if ( function_exists( 'wpstatic_url_has_path_traversal' ) && wpstatic_url_has_path_traversal( $url ) ) {
					continue;
				}
			}

			$existing_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					'SELECT id FROM %i WHERE export_id = %d AND url = %s LIMIT 1',
					$urls_table,
					$export_id,
					$url
				)
			);

			if ( $existing_id <= 0 ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$urls_table,
					array(
						'export_id'      => $export_id,
						'url'            => $url,
						'discovery_type' => 'crawl',
						'status'         => 'pending',
						'fetch_attempts' => 0,
						'created_at'     => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%s', '%s', '%d', '%s' )
				);
				$existing_id = (int) $wpdb->insert_id;
			}

			if ( $existing_id > 0 ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$refs_table,
					array(
						'export_id'      => $export_id,
						'url_id'         => $existing_id,
						'source_url_id'  => $source_id,
						'reference_type' => 'other',
						'created_at'     => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%d', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * Get active export status for the admin UI.
	 *
	 * Used on page load to show Pause/Abort when an export is in progress
	 * (e.g. after page reload or when returning to the tab).
	 *
	 * @return array{has_active: bool, status: string}|null Null when no active export.
	 */
	public function get_ui_export_status() {
		$export = $this->get_active_export();
		if ( ! $export || empty( $export['status'] ) ) {
			return null;
		}

		return array(
			'has_active' => true,
			'status'    => (string) $export['status'],
		);
	}

	/**
	 * Get active export row in working statuses.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_active_export() {
		global $wpdb;
		$table = wpstatic_table_name( 'exports' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status IN ('collecting','fetching','paused') ORDER BY id DESC LIMIT 1",
				$table
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get latest export row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_latest_export() {
		global $wpdb;
		$table = wpstatic_table_name( 'exports' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT 1',
				$table
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create export row.
	 *
	 * @return array<string, mixed>
	 */
	private function create_export_row() {
		global $wpdb;
		$table      = wpstatic_table_name( 'exports' );
		$export_key = current_time( 'Y-m-d_H-i-s' );
		$now        = current_time( 'mysql' );
		$user_id    = get_current_user_id();

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'export_key'   => $export_key,
				'status'       => 'collecting',
				'started_by'   => $user_id ? $user_id : null,
				'total_urls'   => 0,
				'fetched_urls' => 0,
				'settings'     => wp_json_encode( array() ),
				'log_file_path'=> null,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return array();
		}

		return array(
			'id'         => (int) $wpdb->insert_id,
			'export_key' => $export_key,
		);
	}

	/**
	 * Select retryable URLs for a batch.
	 *
	 * Includes pending URLs first, then failed URLs that are still below the
	 * retry limit.
	 *
	 * @param int $export_id Export ID.
	 * @param int $limit     Limit.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_retryable_batch_urls( $export_id, $limit ) {
		global $wpdb;
		$table = wpstatic_table_name( 'urls' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT * FROM %i
				WHERE export_id = %d
					AND (
						status = 'pending'
						OR (status = 'failed' AND fetch_attempts < %d)
					)
				ORDER BY CASE WHEN status = 'pending' THEN 0 ELSE 1 END, id ASC
				LIMIT %d",
				$table,
				$export_id,
				self::MAX_FETCH_ATTEMPTS,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark URL as fetched.
	 *
	 * @param int $url_id URL row ID.
	 * @return void
	 */
	private function mark_url_fetched( $url_id ) {
		global $wpdb;
		$table = wpstatic_table_name( 'urls' );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'status'    => 'fetched',
				'fetched_at'=> current_time( 'mysql' ),
			),
			array( 'id' => $url_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark URL as failed and increment attempts.
	 *
	 * URLs remain retryable while attempts are below MAX_FETCH_ATTEMPTS.
	 *
	 * @param int $url_id   URL row ID.
	 * @param int $attempts Attempts after this failure.
	 * @return void
	 */
	private function mark_url_failed( $url_id, $attempts ) {
		global $wpdb;
		$table = wpstatic_table_name( 'urls' );
		$attempts = max( 1, (int) $attempts );
		$status   = ( $attempts >= self::MAX_FETCH_ATTEMPTS ) ? 'failed' : 'pending';

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'status'         => $status,
				'fetch_attempts' => $attempts,
			),
			array( 'id' => $url_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Mark URL as skipped.
	 *
	 * @param int $url_id URL row ID.
	 * @return void
	 */
	private function mark_url_skipped( $url_id ) {
		global $wpdb;
		$table = wpstatic_table_name( 'urls' );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'status'    => 'skipped',
				'fetched_at'=> current_time( 'mysql' ),
			),
			array( 'id' => $url_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Increment fetched URL counter.
	 *
	 * @param int $export_id Export ID.
	 * @param int $count     Fetched count.
	 * @return void
	 */
	private function increment_export_fetched_urls( $export_id, $count ) {
		global $wpdb;
		$table = wpstatic_table_name( 'exports' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE %i SET fetched_urls = fetched_urls + %d, status = 'fetching', updated_at = %s WHERE id = %d",
				$table,
				$count,
				current_time( 'mysql' ),
				$export_id
			)
		);
	}

	/**
	 * Sync export total_urls value with current URL table count.
	 *
	 * @param int $export_id Export ID.
	 * @return void
	 */
	private function sync_export_total_urls( $export_id ) {
		global $wpdb;

		$export_id = (int) $export_id;
		if ( $export_id <= 0 ) {
			return;
		}

		$urls_table = wpstatic_table_name( 'urls' );
		$total      = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE export_id = %d',
				$urls_table,
				$export_id
			)
		);

		$this->update_export_row(
			$export_id,
			array(
				'total_urls' => $total,
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Count URLs still eligible for processing.
	 *
	 * @param int $export_id Export ID.
	 * @return int
	 */
	private function count_remaining_retryable_urls( $export_id ) {
		global $wpdb;
		$table = wpstatic_table_name( 'urls' );
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE export_id = %d
					AND (
						status = 'pending'
						OR (status = 'failed' AND fetch_attempts < %d)
					)",
				$table,
				$export_id,
				self::MAX_FETCH_ATTEMPTS
			)
		);
	}

	/**
	 * Count permanently failed URLs.
	 *
	 * @param int $export_id Export ID.
	 * @return int
	 */
	private function count_unrecoverable_failed_urls( $export_id ) {
		global $wpdb;
		$table = wpstatic_table_name( 'urls' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE export_id = %d AND status = 'failed' AND fetch_attempts >= %d",
				$table,
				$export_id,
				self::MAX_FETCH_ATTEMPTS
			)
		);
	}

	/**
	 * Count successfully fetched URLs.
	 *
	 * @param int $export_id Export ID.
	 * @return int
	 */
	private function count_fetched_urls( $export_id ) {
		global $wpdb;
		$table = wpstatic_table_name( 'urls' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE export_id = %d AND status = 'fetched'",
				$table,
				$export_id
			)
		);
	}

	/**
	 * Finalize export as completed or failed based on failed URL threshold.
	 *
	 * @param int $export_id       Export ID.
	 * @param int $after_sequence  Status sequence cursor.
	 * @return array<string, mixed>
	 */
	private function finalize_export_status( $export_id, $after_sequence ) {
		$failed_count  = $this->count_unrecoverable_failed_urls( $export_id );
		$fetched_count = $this->count_fetched_urls( $export_id );
		$threshold     = $this->get_failed_url_threshold_percent();
		$processed     = max( 1, $fetched_count + $failed_count );
		$failure_rate  = ( $failed_count / $processed ) * 100;

		if ( $failed_count > 1 && $failure_rate >= $threshold ) {
			$message = sprintf(
				'Export failed. %d URL(s) failed (%.2f%%, threshold %.2f%%).',
				$failed_count,
				$failure_rate,
				$threshold
			);
			$this->mark_export_failed( $export_id, $message );
			return $this->with_status_messages(
				array(
					'success'        => false,
					'status'         => 'failed',
					'message'        => $message,
					'status_message' => $message,
					'next_wait'      => 0,
				),
				$after_sequence
			);
		}

		$this->mark_export_completed( $export_id );
		$message = 'Export complete.';
		$status_message = 'Export finished successfully.';

		if ( $failed_count > 0 ) {
			$message = sprintf(
				'Export completed with warnings: %d URL(s) failed (%.2f%%, threshold %.2f%%).',
				$failed_count,
				$failure_rate,
				$threshold
			);
			$status_message = $message;
			wpstatic_logger()->log_notice( $message );
		}

		return $this->with_status_messages(
			array(
				'success'        => true,
				'status'         => 'completed',
				'message'        => $message,
				'status_message' => $status_message,
				'next_wait'      => 0,
			),
			$after_sequence
		);
	}

	/**
	 * Enqueue detected plugin/theme static assets and continue, or finalize.
	 *
	 * @param array $export         Active export row.
	 * @param int   $after_sequence Status sequence cursor.
	 * @return array<string, mixed>
	 */
	private function maybe_continue_with_detected_plugin_theme_assets( array $export, $after_sequence ) {
		$export_id  = isset( $export['id'] ) ? (int) $export['id'] : 0;
		$export_dir = isset( $export['export_dir_path'] ) ? (string) $export['export_dir_path'] : '';
		$added      = $this->enqueue_detected_plugin_theme_static_assets( $export_id, $export_dir );

		if ( $added > 0 ) {
			$this->sync_export_total_urls( $export_id );
			$status_message = sprintf( 'Detected and queued %d plugin/theme static asset URL(s). Continuing export process.', $added );
			wpstatic_logger()->log_status( $status_message );

			return $this->with_status_messages(
				array(
					'success'        => true,
					'status'         => 'fetching',
					'message'        => $status_message,
					'status_message' => $status_message,
					'next_wait'      => self::DEFAULT_NEXT_WAIT_MS,
				),
				$after_sequence
			);
		}

		$this->sync_export_total_urls( $export_id );
		return $this->finalize_export_status( $export_id, $after_sequence );
	}

	/**
	 * Enqueue static assets for detected plugin/theme names from export output.
	 *
	 * Detection source: export_dir/wp-content/plugins and export_dir/wp-content/themes.
	 * Copy source:       WP_CONTENT_DIR/plugins/{name} and WP_CONTENT_DIR/themes/{name}.
	 *
	 * Included:
	 * - top-level dirs: assets, js, css, fonts, images (recursive).
	 * - top-level files: *.css, *.js.
	 *
	 * Excluded:
	 * - any .txt file (top-level or nested).
	 *
	 * @param int    $export_id  Export ID.
	 * @param string $export_dir Export directory path.
	 * @return int Number of new pending URLs inserted.
	 */
	private function enqueue_detected_plugin_theme_static_assets( $export_id, $export_dir ) {
		if ( $export_id <= 0 || '' === $export_dir || ! is_dir( $export_dir ) || ! defined( 'WP_CONTENT_DIR' ) ) {
			return 0;
		}

		$detected_plugins = $this->detect_exported_wp_content_names( $export_dir, 'plugins' );
		$detected_themes  = $this->detect_exported_wp_content_names( $export_dir, 'themes' );
		if ( empty( $detected_plugins ) && empty( $detected_themes ) ) {
			return 0;
		}

		$source_roots = array();
		foreach ( $detected_plugins as $name ) {
			$source_roots[] = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'plugins/' . $name );
		}
		foreach ( $detected_themes as $name ) {
			$source_roots[] = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $name );
		}

		$source_roots = array_values( array_unique( $source_roots ) );
		$urls         = array();
		foreach ( $source_roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			$urls = array_merge( $urls, $this->collect_static_asset_urls_from_source_root( $root ) );
		}

		if ( empty( $urls ) ) {
			return 0;
		}

		$urls  = array_values( array_unique( $urls ) );
		$added = 0;
		foreach ( $urls as $url ) {
			if ( $this->insert_pending_crawl_url_if_missing( $export_id, $url ) ) {
				++$added;
			}
		}

		return $added;
	}

	/**
	 * Enqueue allowed static files found in the site document root.
	 *
	 * Includes top-level files matching the global static export policy.
	 * Excludes robots.txt here because it is already handled by regular collection.
	 *
	 * @param int $export_id Export ID.
	 * @return int Number of URLs inserted.
	 */
	private function enqueue_document_root_static_assets( $export_id ) {
		$export_id = (int) $export_id;
		if ( $export_id <= 0 ) {
			return 0;
		}

		$document_root = wpstatic_directories()->get_document_root_dir();
		if ( ! is_string( $document_root ) || '' === $document_root || ! is_dir( $document_root ) ) {
			return 0;
		}

		$document_root = wp_normalize_path( untrailingslashit( $document_root ) );
		$items         = scandir( $document_root );
		if ( ! is_array( $items ) ) {
			return 0;
		}

		$added = 0;
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = wp_normalize_path( $document_root . '/' . $item );
			if ( ! is_file( $path ) ) {
				continue;
			}

			if ( 'robots.txt' === strtolower( basename( $path ) ) ) {
				continue;
			}

			if ( ! $this->is_document_root_static_file_exportable( $path ) ) {
				continue;
			}

			$url = $this->document_root_path_to_url( $path, $document_root );
			if ( '' === $url ) {
				continue;
			}

			if ( $this->insert_pending_crawl_url_if_missing( $export_id, $url ) ) {
				++$added;
			}
		}

		return $added;
	}

	/**
	 * Detect top-level names under exported wp-content/{plugins|themes}.
	 *
	 * @param string $export_dir Export directory.
	 * @param string $section    Either plugins or themes.
	 * @return string[] Detected directory names.
	 */
	private function detect_exported_wp_content_names( $export_dir, $section ) {
		$section = sanitize_key( (string) $section );
		if ( ! in_array( $section, array( 'plugins', 'themes' ), true ) ) {
			return array();
		}

		$base = wp_normalize_path( untrailingslashit( $export_dir ) . '/wp-content/' . $section );
		if ( ! is_dir( $base ) ) {
			return array();
		}

		$items = scandir( $base );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$names = array();
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = wp_normalize_path( $base . '/' . $item );
			if ( is_dir( $path ) ) {
				$names[] = sanitize_key( $item );
			}
		}

		return array_values( array_unique( array_filter( $names ) ) );
	}

	/**
	 * Collect static asset URLs from one detected plugin/theme source root.
	 *
	 * @param string $source_root Absolute source root path.
	 * @return string[] URLs.
	 */
	private function collect_static_asset_urls_from_source_root( $source_root ) {
		$source_root = wp_normalize_path( (string) $source_root );
		if ( '' === $source_root || ! is_dir( $source_root ) ) {
			return array();
		}

		$urls = array();

		// Top-level *.css and *.js files.
		$top_items = scandir( $source_root );
		if ( is_array( $top_items ) ) {
			foreach ( $top_items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$path = wp_normalize_path( $source_root . '/' . $item );
				if ( ! is_file( $path ) ) {
					continue;
				}

				$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, array( 'css', 'js' ), true ) && ! $this->should_skip_static_asset_path( $path ) ) {
					$url = $this->wp_content_path_to_url( $path );
					if ( '' !== $url ) {
						$urls[] = $url;
					}
				}
			}
		}

		$top_dirs = array( 'assets', 'js', 'css', 'fonts', 'images' );
		foreach ( $top_dirs as $dir_name ) {
			$dir = wp_normalize_path( $source_root . '/' . $dir_name );
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
				);
			} catch ( \UnexpectedValueException $exception ) {
				continue;
			}

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() ) {
					continue;
				}

				$path = wp_normalize_path( $file_info->getPathname() );
				if ( $this->should_skip_static_asset_path( $path ) ) {
					continue;
				}

				$url = $this->wp_content_path_to_url( $path );
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
		}

		return $urls;
	}

	/**
	 * Whether a static asset file path should be skipped.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	private function should_skip_static_asset_path( $path ) {
		$ext = strtolower( (string) pathinfo( (string) $path, PATHINFO_EXTENSION ) );
		if ( 'txt' === $ext ) {
			return true;
		}

		$url = $this->wp_content_path_to_url( $path );
		if ( '' === $url ) {
			return true;
		}

		if ( function_exists( 'wpstatic_should_skip_discovered_url' ) ) {
			return wpstatic_should_skip_discovered_url( $url, (string) $path );
		}

		if ( function_exists( 'wpstatic_is_exportable_url' ) ) {
			return ! wpstatic_is_exportable_url( $url, (string) $path );
		}

		return false;
	}

	/**
	 * Check whether a document-root file is allowed by static extension policy.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	private function is_document_root_static_file_exportable( $path ) {
		$ext = strtolower( (string) pathinfo( (string) $path, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			return false;
		}

		if ( function_exists( 'wpstatic_get_exportable_static_extensions' ) ) {
			return in_array( $ext, wpstatic_get_exportable_static_extensions(), true );
		}

		return false;
	}

	/**
	 * Convert an absolute document-root path into a site URL.
	 *
	 * @param string $path          Absolute file path.
	 * @param string $document_root Absolute document root.
	 * @return string
	 */
	private function document_root_path_to_url( $path, $document_root ) {
		$path          = wp_normalize_path( (string) $path );
		$document_root = trailingslashit( wp_normalize_path( (string) $document_root ) );
		if ( 0 !== strpos( $path, $document_root ) ) {
			return '';
		}

		$relative = ltrim( substr( $path, strlen( $document_root ) ), '/' );
		if ( '' === $relative ) {
			return '';
		}

		return home_url( '/' . $relative );
	}

	/**
	 * Convert a wp-content file path into a site URL.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	private function wp_content_path_to_url( $path ) {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return '';
		}

		$path        = wp_normalize_path( (string) $path );
		$content_dir = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		if ( 0 !== strpos( $path, $content_dir ) ) {
			return '';
		}

		$relative = ltrim( substr( $path, strlen( $content_dir ) ), '/' );
		if ( '' === $relative ) {
			return '';
		}

		return content_url( $relative );
	}

	/**
	 * Insert pending crawl URL row when missing.
	 *
	 * @param int    $export_id Export ID.
	 * @param string $url       URL.
	 * @return bool True when inserted.
	 */
	private function insert_pending_crawl_url_if_missing( $export_id, $url ) {
		global $wpdb;
		$urls_table = wpstatic_table_name( 'urls' );

		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return false;
		}

		if ( function_exists( 'wpstatic_normalize_url_for_storage' ) ) {
			$url = wpstatic_normalize_url_for_storage( $url );
		}
		$source_path = function_exists( 'wpstatic_resolve_local_source_path_for_url' )
			? wpstatic_resolve_local_source_path_for_url( $url )
			: '';
		if ( function_exists( 'wpstatic_should_skip_discovered_url' ) ) {
			if ( wpstatic_should_skip_discovered_url( $url, $source_path ) ) {
				return false;
			}
		} else {
			if ( function_exists( 'wpstatic_should_skip_root_query_url' ) && wpstatic_should_skip_root_query_url( $url ) ) {
				return false;
			}
			if ( function_exists( 'wpstatic_url_has_path_traversal' ) && wpstatic_url_has_path_traversal( $url ) ) {
				return false;
			}
		}

		$existing_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT id FROM %i WHERE export_id = %d AND url = %s LIMIT 1',
				$urls_table,
				$export_id,
				$url
			)
		);
		if ( $existing_id > 0 ) {
			return false;
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$urls_table,
			array(
				'export_id'      => $export_id,
				'url'            => $url,
				'discovery_type' => 'crawl',
				'status'         => 'pending',
				'fetch_attempts' => 0,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Get failed URL threshold percentage from options.
	 *
	 * @return float
	 */
	private function get_failed_url_threshold_percent() {
		$value = get_option( self::FAILED_URL_THRESHOLD_OPTION, self::DEFAULT_FAILED_URL_THRESHOLD_PERCENT );
		if ( false === get_option( self::FAILED_URL_THRESHOLD_OPTION, false ) ) {
			add_option( self::FAILED_URL_THRESHOLD_OPTION, self::DEFAULT_FAILED_URL_THRESHOLD_PERCENT );
			$value = self::DEFAULT_FAILED_URL_THRESHOLD_PERCENT;
		}

		$threshold = is_numeric( $value ) ? (float) $value : (float) self::DEFAULT_FAILED_URL_THRESHOLD_PERCENT;
		$threshold = max( 0, min( 100, $threshold ) );

		return $threshold;
	}

	/**
	 * Count URL rows for an export.
	 *
	 * @param int $export_id Export ID.
	 * @return int
	 */
	private function count_export_urls( $export_id ) {
		global $wpdb;
		$urls_table = wpstatic_table_name( 'urls' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE export_id = %d',
				$urls_table,
				(int) $export_id
			)
		);
	}

	/**
	 * Mark export as completed.
	 *
	 * @param int $export_id Export ID.
	 * @return void
	 */
	private function mark_export_completed( $export_id ) {
		$this->update_export_row(
			$export_id,
			array(
				'status'       => 'completed',
				'updated_at'   => current_time( 'mysql' ),
				'completed_at' => current_time( 'mysql' ),
			)
		);
		$this->release_lock();
		$this->cleanup_temp_root();
		wpstatic_logger()->log_status( 'Export completed.' );
	}

	/**
	 * Mark export as failed and log reason.
	 *
	 * @param int    $export_id Export ID.
	 * @param string $reason    Failure reason.
	 * @return void
	 */
	private function mark_export_failed( $export_id, $reason ) {
		$this->update_export_row(
			$export_id,
			array(
				'status'     => 'failed',
				'updated_at' => current_time( 'mysql' ),
			)
		);
		$this->release_lock();
		$this->cleanup_temp_root();
		wpstatic_logger()->log_error( $reason );
	}

	/**
	 * Abort export by ID with reason.
	 *
	 * @param int    $export_id Export ID.
	 * @param string $reason    Reason.
	 * @return void
	 */
	private function abort_by_id( $export_id, $reason ) {
		$export_dir = $this->get_export_dir_path( $export_id );
		if ( '' !== $export_dir ) {
			$this->delete_export_directory( $export_dir );
		}

		$this->update_export_row(
			$export_id,
			array(
				'status'     => 'cancelled',
				'updated_at' => current_time( 'mysql' ),
			)
		);
		$this->release_lock();
		$this->cleanup_temp_root();
		wpstatic_logger()->log_error( $reason );
	}

	/**
	 * Fetch export directory path by export ID.
	 *
	 * @param int $export_id Export ID.
	 * @return string
	 */
	private function get_export_dir_path( $export_id ) {
		global $wpdb;
		$table = wpstatic_table_name( 'exports' );
		$path  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT export_dir_path FROM %i WHERE id = %d',
				$table,
				$export_id
			)
		);

		return is_string( $path ) ? $path : '';
	}

	/**
	 * Delete one export directory recursively when it is inside upload/export.
	 *
	 * @param string $export_dir Absolute export directory path.
	 * @return void
	 */
	private function delete_export_directory( $export_dir ) {
		if ( '' === $export_dir || ! is_dir( $export_dir ) ) {
			return;
		}

		$base = wpstatic_directories()->get_upload_dir_base();
		if ( false === $base || empty( $base['path'] ) ) {
			return;
		}

		$allowed_root = wp_normalize_path( untrailingslashit( $base['path'] ) . '/export/' );
		$target_path  = wp_normalize_path( untrailingslashit( $export_dir ) . '/' );

		if ( 0 !== strpos( $target_path, $allowed_root ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $export_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				wpstatic_fs_rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		wpstatic_fs_rmdir( $export_dir );
	}

	/**
	 * Update export row fields.
	 *
	 * @param int   $export_id Export ID.
	 * @param array $data      Data.
	 * @return void
	 */
	private function update_export_row( $export_id, array $data ) {
		global $wpdb;
		$table = wpstatic_table_name( 'exports' );

		$formats = array();
		foreach ( $data as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			$data,
			array( 'id' => $export_id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Ensure one export lock at a time.
	 *
	 * @return string Empty when lock is fine, otherwise error message.
	 */
	private function ensure_single_job_lock() {
		$lock = get_transient( self::LOCK_KEY );
		if ( ! is_array( $lock ) || empty( $lock['export_id'] ) ) {
			return '';
		}

		$user_id = isset( $lock['user_id'] ) ? (int) $lock['user_id'] : 0;
		if ( $user_id <= 0 || $user_id === get_current_user_id() ) {
			return '';
		}

		$user = get_userdata( $user_id );
		$by   = $user ? ( $user->user_email ? $user->user_email : $user->display_name ) : 'another user';

		return sprintf( 'An export job is already running and was started by %s.', $by );
	}

	/**
	 * Refresh lock TTL.
	 *
	 * @param int $export_id Export ID.
	 * @return void
	 */
	private function refresh_lock( $export_id ) {
		set_transient(
			self::LOCK_KEY,
			array(
				'export_id' => $export_id,
				'user_id'   => get_current_user_id(),
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Release lock.
	 *
	 * @return void
	 */
	private function release_lock() {
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Acquire per-export batch lock.
	 *
	 * @param int $export_id Export ID.
	 * @return bool True when lock is acquired.
	 */
	private function acquire_batch_lock( $export_id ) {
		$key      = self::BATCH_LOCK_KEY_PREFIX . (int) $export_id;
		$existing = get_transient( $key );

		if ( is_array( $existing ) && ! empty( $existing['started_at'] ) ) {
			$age = time() - (int) $existing['started_at'];
			if ( $age < self::BATCH_LOCK_TTL ) {
				return false;
			}
		}

		set_transient(
			$key,
			array(
				'started_at' => time(),
				'user_id'    => get_current_user_id(),
			),
			self::BATCH_LOCK_TTL
		);

		return true;
	}

	/**
	 * Release per-export batch lock.
	 *
	 * @param int $export_id Export ID.
	 * @return void
	 */
	private function release_batch_lock( $export_id ) {
		delete_transient( self::BATCH_LOCK_KEY_PREFIX . (int) $export_id );
	}

	/**
	 * Check whether current user can manage export.
	 *
	 * @return bool
	 */
	private function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Standard error result.
	 *
	 * @param string $message Message.
	 * @return array<string, mixed>
	 */
	private function error_result( $message ) {
		return array(
			'success' => false,
			'status'  => 'failed',
			'message' => $message,
		);
	}

	/**
	 * Clean temporary root directory.
	 *
	 * @return void
	 */
	private function cleanup_temp_root() {
		$root = rtrim( sys_get_temp_dir(), '/' ) . '/wpstatic';
		if ( ! is_dir( $root ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				wpstatic_fs_rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		wpstatic_fs_rmdir( $root );
	}

	/**
	 * Resolve effective batch size for this environment.
	 *
	 * @param int   $batch_size     Requested batch size.
	 * @param array $upcoming_rows  Upcoming retryable rows (cached).
	 * @return int
	 */
	private function resolve_batch_size( $batch_size, array $upcoming_rows = array() ) {
		$requested = (int) $batch_size;
		if ( $requested > 0 ) {
			return $requested;
		}

		$max_execution = (int) ini_get( 'max_execution_time' );
		$default       = 4;

		if ( $this->has_upcoming_existing_file_urls( $upcoming_rows ) ) {
			$default = $this->batch_size_for_execution_profile( $max_execution, 40, 80, 60 );
			//$default = $this->batch_size_for_execution_profile( $max_execution, 20, 40, 30 );
		} elseif ( $this->has_upcoming_non_file_urls( $upcoming_rows ) ) {
			$default = $this->batch_size_for_execution_profile( $max_execution, 6, 12, 9 );
			//$default = $this->batch_size_for_execution_profile( $max_execution, 4, 8, 6 );
		}

		$configured    = (int) apply_filters( 'wpstatic_export_batch_size', $default );

		return max( 1, $configured );
	}

	/**
	 * Choose batch size from execution-time profile.
	 *
	 * @param int $max_execution Value from ini_get('max_execution_time').
	 * @param int $low           Batch size for <=30s.
	 * @param int $medium        Batch size for 60s.
	 * @param int $high          Batch size for all other values.
	 * @return int
	 */
	private function batch_size_for_execution_profile( $max_execution, $low, $medium, $high ) {
		if ( $max_execution > 0 && $max_execution <= 30 ) {
			return (int) $low;
		}

		if ( 60 === $max_execution ) {
			return (int) $medium;
		}

		return (int) $high;
	}

	/**
	 * Check if first 4 pending rows are all non-file URLs.
	 *
	 * @param array $rows Upcoming retryable rows.
	 * @return bool
	 */
	private function has_upcoming_non_file_urls( array $rows ) {
		$pending_rows = $this->get_upcoming_pending_rows( $rows, 4 );
		if ( count( $pending_rows ) < 4 ) {
			return false;
		}

		$renderer = wpstatic_renderer();
		foreach ( $pending_rows as $row ) {
			$url = isset( $row['url'] ) ? (string) $row['url'] : '';
			if ( '' === $url || $renderer->url_is_file( $url ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if first 20 pending rows are file URLs with existing source paths.
	 *
	 * @param array $rows Upcoming retryable rows.
	 * @return bool
	 */
	private function has_upcoming_existing_file_urls( array $rows ) {
		$pending_rows = $this->get_upcoming_pending_rows( $rows, 20 );
		if ( count( $pending_rows ) < 20 ) {
			return false;
		}

		$renderer = wpstatic_renderer();
		foreach ( $pending_rows as $row ) {
			$url = isset( $row['url'] ) ? (string) $row['url'] : '';
			if ( '' === $url || ! $renderer->url_is_file( $url ) ) {
				return false;
			}

			$mapping = $renderer->url_to_path_mapping( $url );
			if ( empty( $mapping['source_path'] ) || ! file_exists( $mapping['source_path'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return first N pending rows from a retryable row list.
	 *
	 * @param array $rows  Rows from get_retryable_batch_urls().
	 * @param int   $limit Number of pending rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_upcoming_pending_rows( array $rows, $limit ) {
		$limit   = max( 1, (int) $limit );
		$pending = array();

		foreach ( $rows as $row ) {
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( 'pending' !== $status ) {
				continue;
			}

			$pending[] = $row;
			if ( count( $pending ) >= $limit ) {
				break;
			}
		}

		return $pending;
	}

	/**
	 * Attach status messages since the provided sequence.
	 *
	 * @param array $result         Result payload.
	 * @param int   $after_sequence Sequence to start after.
	 * @return array<string, mixed>
	 */
	private function with_status_messages( array $result, $after_sequence ) {
		$after_sequence           = max( 0, (int) $after_sequence );
		$messages                 = wpstatic_logger()->get_status_messages( $after_sequence );
		$result['status_messages'] = $messages;
		$result['last_sequence']  = $this->get_last_sequence_from_messages( $messages, $after_sequence );

		return $result;
	}

	/**
	 * Get the last sequence number from status messages.
	 *
	 * @param array<int, array<string, mixed>> $messages Messages.
	 * @param int                              $fallback Fallback sequence.
	 * @return int
	 */
	private function get_last_sequence_from_messages( array $messages, $fallback ) {
		$last = max( 0, (int) $fallback );
		foreach ( $messages as $message ) {
			if ( ! isset( $message['sequence'] ) ) {
				continue;
			}

			$seq = (int) $message['sequence'];
			if ( $seq > $last ) {
				$last = $seq;
			}
		}

		return $last;
	}

	/**
	 * Format detailed fetch failure information.
	 *
	 * @param array $result Renderer result.
	 * @return string
	 */
	private function format_fetch_failure_reason( array $result ) {
		$parts = array();

		if ( isset( $result['http_status_code'] ) && null !== $result['http_status_code'] ) {
			$parts[] = 'HTTP ' . (int) $result['http_status_code'];
		}

		if ( ! empty( $result['error'] ) ) {
			$parts[] = (string) $result['error'];
		}

		if ( empty( $parts ) ) {
			return 'Unknown fetch error.';
		}

		return implode( ' | ', $parts );
	}
}
