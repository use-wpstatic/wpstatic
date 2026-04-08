<?php
/**
 * Admin export UI renderer.
 *
 * Copyright (C) 2026 Anindya Sundar Mandal
 *
 * This file is part of WPStatic. For full license text, see license.txt.
 *
 * @package WPStatic
 */

namespace WPStatic\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render export controls.
 */
class Export {

	/**
	 * Render "Make Static Site" controls.
	 *
	 * @return void
	 */
	public function render() {
		$can_download_zip = \wpstatic_driver_zip()->can_download_zip();
		$zip_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wpstatic_download_zip' ),
			'wpstatic_download_nonce'
		);

		$log_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wpstatic_download_log' ),
			'wpstatic_download_log_nonce'
		);

		echo '<div class="wpstatic-export-card">';
		echo '<h2>' . esc_html__( 'Export Status Log', 'wpstatic' ) . '</h2>';
		echo '<div id="wpstatic-export-log" class="wpstatic-export-log-window">';
		echo esc_html__( "No export job is currently running. Please click the 'Generate/Export Static Site' button below to start the export, i.e., start making a static version of your website.", 'wpstatic' );
		echo '</div>';
		echo '</div>';

		echo '<div class="wpstatic-export-card">';
		echo '<div class="wpstatic-export-actions">';
		echo '<button type="button" id="wpstatic-start-export" class="button button-primary"><span class="dashicons dashicons-controls-play"></span> ' . esc_html__( 'Generate/Export Static Site', 'wpstatic' ) . '</button>';
		echo '<button type="button" id="wpstatic-pause-export" class="button" style="display:none;"><span class="dashicons dashicons-controls-pause"></span> ' . esc_html__( 'Pause', 'wpstatic' ) . '</button>';
		echo '<button type="button" id="wpstatic-resume-export" class="button" style="display:none;"><span class="dashicons dashicons-controls-play"></span> ' . esc_html__( 'Resume', 'wpstatic' ) . '</button>';
		echo '<button type="button" id="wpstatic-abort-export" class="button button-secondary" style="display:none;"><span class="dashicons dashicons-no-alt"></span> ' . esc_html__( 'Abort', 'wpstatic' ) . '</button>';
		echo '<span id="wpstatic-export-spinner" class="spinner" style="float:none;display:none;"></span>';
		echo '<a id="wpstatic-download-zip" href="' . esc_url( $zip_url ) . '" class="button button-secondary"' . ( $can_download_zip ? '' : ' style="display:none;"' ) . '><span class="dashicons dashicons-download"></span> ' . esc_html__( 'Download Static Site (ZIP)', 'wpstatic' ) . '</a>';
		echo '</div>';
		echo '<div id="wpstatic-post-zip-instructions" class="wpstatic-post-zip-instructions" style="display:none;" aria-live="polite"></div>';
		echo '</div>';

		echo '<div class="wpstatic-export-card">';
		echo '<div class="wpstatic-export-actions">';
		echo '<label><input type="checkbox" id="wpstatic-include-diagnostics" value="1"> ' . esc_html__( 'Include/Append system information', 'wpstatic' ) . '</label>';
		echo '<a id="wpstatic-download-log" href="' . esc_url( $log_url ) . '" class="button"><span class="dashicons dashicons-media-text"></span> ' . esc_html__( 'Download Export Log', 'wpstatic' ) . '</a>';
		echo '</div>';
		echo '</div>';

		echo '<div class="wpstatic-export-card">';
		echo '<div class="wpstatic-export-actions">';
		echo '<button type="button" id="wpstatic-delete-log" class="button"><span class="dashicons dashicons-trash"></span> ' . esc_html__( 'Delete Log', 'wpstatic' ) . '</button>';
		echo '<button type="button" id="wpstatic-delete-temp" class="button"><span class="dashicons dashicons-trash"></span> ' . esc_html__( 'Delete Temporary Export Directories', 'wpstatic' ) . '</button>';
		echo '</div>';
		echo '</div>';
	}
}
