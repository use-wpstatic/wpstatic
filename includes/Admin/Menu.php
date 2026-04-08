<?php
/**
 * Admin menu and page rendering for WPStatic.
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
 * Admin menu handler.
 */
class Menu {

	/**
	 * Single instance of the class.
	 *
	 * @var Menu|null
	 */
	private static $instance = null;

	/**
	 * Hook suffix for the admin page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Get single instance of the class.
	 *
	 * @return Menu
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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'add_admin_body_class' ) );
	}

	/**
	 * Register the top-level admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = add_menu_page(
			esc_html__( 'WPStatic', 'wpstatic' ),
			esc_html__( 'WPStatic', 'wpstatic' ),
			'manage_options',
			WPSTATIC_SLUG,
			array( $this, 'render_page' ),
			'dashicons-admin-site',
			66
		);
	}

	/**
	 * Enqueue admin assets for this page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$handle = WPSTATIC_SLUG . '-admin';
		wp_enqueue_style(
			$handle,
			WPSTATIC_URL . 'assets/css/admin.css',
			array(),
			WPSTATIC_VERSION
		);

		wp_enqueue_style(
			WPSTATIC_SLUG . '-export',
			WPSTATIC_URL . 'assets/css/export.css',
			array( $handle ),
			WPSTATIC_VERSION
		);

		wp_enqueue_script(
			WPSTATIC_SLUG . '-export',
			WPSTATIC_URL . 'assets/js/export.js',
			array( 'jquery' ),
			WPSTATIC_VERSION,
			true
		);

		$ui_status = wpstatic_export_job()->get_ui_export_status();

		wp_localize_script(
			WPSTATIC_SLUG . '-export',
			'wpstaticExportData',
			array(
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'nonce'               => wp_create_nonce( 'wpstatic_export_nonce' ),
				'logTailNonce'        => wp_create_nonce( 'wpstatic_log_tail' ),
				'confirmStartExport'  => __( "The export process may stop if this tab is closed, refreshed, or if your internet connection drops. Please keep this tab open (and open a new tab if needed) until the export finishes.\n\nAre you ready to start the export process now?", 'wpstatic' ),
				'confirmAbort'        => __( 'Are you sure you want to abort the export?', 'wpstatic' ),
				'confirmDeleteLog'    => __( 'Are you sure you want to delete all log files?', 'wpstatic' ),
				'confirmDeleteTemp'   => __( 'Are you sure you want to delete temporary export directories?', 'wpstatic' ),
				'unloadWarning'       => __( 'If you close this tab now, the export process will stop. Please open a new tab instead.', 'wpstatic' ),
				'postZipTitle'        => __( 'Next step after downloading the ZIP', 'wpstatic' ),
				'postZipLine1'        => __( 'Upload this ZIP to the document root directory of your preferred domain (or any subdirectory) to host the static website.', 'wpstatic' ),
				'postZipLine2'        => __( 'You can host the static website on an existing web hosting server or on a free/paid static site hosting service such as Cloudflare Pages or GitHub Pages.', 'wpstatic' ),
				'postZipDomainLabel'  => __( 'Current WordPress domain:', 'wpstatic' ),
				'postZipDocRootLabel' => __( 'Document root path for this domain:', 'wpstatic' ),
				'postZipLine3'        => __( 'If you want to host the static site on this domain\'s document root, move the WordPress website to a subdomain and protect it with HTTP Basic Auth, or move it to localhost using a backup and migration plugin such as Duplicator.', 'wpstatic' ),
				'wpDomain'            => (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : home_url() ),
				'wpDocRootPath'       => (string) ( wpstatic_directories()->get_document_root_dir() ? wpstatic_directories()->get_document_root_dir() : untrailingslashit( ABSPATH ) ),
				'showPostZipInstructions' => wpstatic_driver_zip()->should_show_post_zip_instructions(),
				'hasActiveExport'       => ! empty( $ui_status ) && ! empty( $ui_status['has_active'] ),
				'activeExportStatus'    => ! empty( $ui_status ) && isset( $ui_status['status'] ) ? (string) $ui_status['status'] : '',
				'restartMessage'        => __( "The server did not respond within 30 seconds. Please click the \"Resume\" button.\n\nIf the export still doesn't resume, click \"Abort\". After the export aborted successfully, reload this page and click \"Generate/Export Static Site\" again to restart the export process.", 'wpstatic' ),
				'connectionLostMessage' => __( 'Connection lost or server did not respond. Use Pause or Abort to control the export, or click "Generate/Export Static Site" again to resume.', 'wpstatic' ),
				'msgExportResumed'      => __( 'Export resumed.', 'wpstatic' ),
				'msgAutoResumeFailed'   => __( 'Could not resume export automatically. Please click Resume or Abort.', 'wpstatic' ),
				'msgOfflineWaitingReconnect' => __( 'Your internet connection dropped. Waiting to reconnect.', 'wpstatic' ),
				'msgOnlineRestoredResuming'  => __( 'Internet connection restored. Trying to resume export. Please wait ...', 'wpstatic' ),
				'msgExportStoppedError' => __( 'Export stopped due to an error.', 'wpstatic' ),
				'msgAlreadyRunningControls' => __( 'An export is already running. Use Resume to continue or Abort to cancel.', 'wpstatic' ),
				'msgExportStopped'      => __( 'Export stopped.', 'wpstatic' ),
				'msgUnableToStartExport' => __( 'Unable to start export.', 'wpstatic' ),
				'msgExportStarted'      => __( 'Export started.', 'wpstatic' ),
				'msgExportPaused'       => __( 'Export paused.', 'wpstatic' ),
				'msgExportAborted'      => __( 'Export aborted.', 'wpstatic' ),
				'msgLogDeleted'         => __( 'Log deleted.', 'wpstatic' ),
				'msgTempDirectoriesDeleted' => __( 'Temporary directories deleted.', 'wpstatic' ),
				'msgActiveExportInProgress' => __( 'An export is in progress. Use Pause or Abort to control it, or wait for completion.', 'wpstatic' ),
				'msgActiveExportPaused' => __( 'Export is paused. Click Resume to continue or Abort to cancel.', 'wpstatic' ),
			)
		);
		}

	/**
	 * Add custom body class on WPStatic admin pages.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function add_admin_body_class( $classes ) {
		$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( is_string( $page ) && WPSTATIC_SLUG === sanitize_key( $page ) ) {
			return $classes . ' wpstatic-admin-page';
		}
		return $classes;
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		$tabs            = $this->get_tabs();
		$current_tab     = $this->get_current_tab( $tabs );
		$layout_settings = $this->get_layout_settings();
		$shell_classes   = $this->get_shell_classes( $layout_settings );

		echo '<div class="wrap">';
		echo '<div class="' . esc_attr( $shell_classes ) . '" data-sidebar-position="' . esc_attr( $layout_settings['sidebar_position'] ) . '" data-sidebar-visible="' . esc_attr( $layout_settings['sidebar_visible'] ? '1' : '0' ) . '">';

		$this->render_header();

		echo '<div class="' . esc_attr( $this->get_css_class( 'admin-main' ) ) . '">';

		if ( $layout_settings['sidebar_visible'] ) {
			$this->render_sidebar( $tabs, $current_tab );
		}

		$this->render_content( $tabs, $current_tab );

		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Get available tabs.
	 *
	 * @return array
	 */
	private function get_tabs() {
		return array(
			'make-static-site' => __( 'Make Static Site', 'wpstatic' ),
		);
	}

	/**
	 * Get the current tab key.
	 *
	 * @param array $tabs Tabs array.
	 * @return string
	 */
	private function get_current_tab( $tabs ) {
		$default = array_key_first( $tabs );
		$tab_raw = filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$tab     = is_string( $tab_raw ) ? sanitize_key( $tab_raw ) : $default;

		if ( ! array_key_exists( $tab, $tabs ) ) {
			$tab = $default;
		}

		return $tab;
	}

	/**
	 * Build a tab URL.
	 *
	 * @param string $tab_key Tab key.
	 * @return string
	 */
	private function get_tab_url( $tab_key ) {
		return add_query_arg(
			array(
				'page' => WPSTATIC_SLUG,
				'tab'  => $tab_key,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the header.
	 *
	 * @return void
	 */
	private function render_header() {
		echo '<header class="' . esc_attr( $this->get_css_class( 'admin-header' ) ) . '">';
		echo '<div class="' . esc_attr( $this->get_css_class( 'admin-header-inner' ) ) . '">';
		echo '<div class="' . esc_attr( $this->get_css_class( 'admin-brand' ) ) . '">';
		echo '<span class="' . esc_attr( $this->get_css_class( 'admin-brand-name' ) ) . '">' . esc_html( WPSTATIC_PLUGIN_NAME ) . '</span>';
		echo '<span class="' . esc_attr( $this->get_css_class( 'admin-brand-version' ) ) . '">' . esc_html( WPSTATIC_VERSION ) . '</span>';
		echo '</div>';
		echo '</div>';
		echo '</header>';
	}

	/**
	 * Render the sidebar.
	 *
	 * @param array  $tabs Tabs array.
	 * @param string $current_tab Current tab key.
	 * @return void
	 */
	private function render_sidebar( $tabs, $current_tab ) {
		$sidebar_class = $this->get_css_class( 'admin-sidebar' );
		$header_class  = $this->get_css_class( 'admin-sidebar-header' );
		$tabs_class    = $this->get_css_class( 'admin-tabs' );
		$tab_class     = $this->get_css_class( 'admin-tab' );

		echo '<aside class="' . esc_attr( $sidebar_class ) . '">';
		echo '<div class="card">';
		echo '<div class="' . esc_attr( $header_class ) . '">';
		echo '<span class="' . esc_attr( $this->get_css_class( 'admin-plugin-name' ) ) . '">' . esc_html( WPSTATIC_PLUGIN_NAME ) . '</span>';
		echo '<span class="' . esc_attr( $this->get_css_class( 'admin-plugin-version' ) ) . '">' . esc_html( WPSTATIC_VERSION ) . '</span>';
		echo '</div>';

		echo '<nav class="' . esc_attr( $tabs_class ) . '">';
		foreach ( $tabs as $tab_key => $tab_label ) {
			$classes = $tab_class;
			if ( $tab_key === $current_tab ) {
				$classes .= ' is-active';
			}

			printf(
				'<a class="%1$s" href="%2$s">%3$s</a>',
				esc_attr( $classes ),
				esc_url( $this->get_tab_url( $tab_key ) ),
				esc_html( $tab_label )
			);
		}
		echo '</nav>';

		echo '</div>';
		echo '</aside>';
	}

	/**
	 * Render the content area.
	 *
	 * @param array  $tabs Tabs array.
	 * @param string $current_tab Current tab key.
	 * @return void
	 */
	private function render_content( $tabs, $current_tab ) {
		$content_class = $this->get_css_class( 'admin-content' );
		$title_class   = $this->get_css_class( 'admin-title' );

		echo '<section class="' . esc_attr( $content_class ) . '">';
		echo '<div class="card">';
		echo '<h1 class="' . esc_attr( $title_class ) . '">' . esc_html( $tabs[ $current_tab ] ) . '</h1>';
		if ( 'make-static-site' === $current_tab ) {
			$export_page = new Export();
			$export_page->render();
		}

		echo '</div>';
		echo '</section>';
	}

/**
 * @todo delete after testing OR
 * @todo move to functions-api.php or add to Renderer class AND
 * @todo add tests for this function
 * 
 * Normalize HTML for deterministic comparison.
 *
 * Removes HTML comments, common dynamic tags (generator, shortlink,
 * admin-bar), inline nonce scripts, trims lines, and collapses whitespace.
 *
 * @param string $html Raw HTML string.
 * @return string Normalized HTML.
 */
 private function normalize_html( $html ) {
	$html = preg_replace( '/<!--.*?-->/s', '', $html );
	$html = preg_replace( '/<link[^>]+rel=["\']?shortlink["\'][^>]*>/i', '', $html );
	$html = preg_replace( '/<meta[^>]+name=["\']?generator["\'][^>]*>/i', '', $html );
	$html = preg_replace( '/<script[^>]*>.*?wp[_-](?:api|nonce).*?<\/script>/is', '', $html );

	// Strip admin-bar markup (present in HTTP when logged in).
	$html = preg_replace( '/<style[^>]*id=["\']?admin-bar[^>]*>.*?<\/style>/is', '', $html );
	$html = preg_replace( '/<link[^>]+id=["\']?(?:dashicons|wp-auth-check|admin-bar)[^>]*>/i', '', $html );
	$html = preg_replace( '/<script[^>]+id=["\']?admin-bar[^>]*>.*?<\/script>/is', '', $html );
	$html = preg_replace( '/<div[^>]+id=["\']?wpadminbar["\'][^>]*>.*?<\/div>/is', '', $html );

	$lines = array_map( 'trim', explode( "\n", $html ) );
	$html  = implode( "\n", $lines );
	$html  = preg_replace( '/\s+/s', ' ', $html );

	return trim( $html );
}

	/**
	 * Get layout settings.
	 *
	 * @return array
	 */
	private function get_layout_settings() {
		$defaults = array(
			'sidebar_position' => 'left',
			'sidebar_visible'  => true,
		);

		$settings = apply_filters( WPSTATIC_SLUG . '_admin_layout_settings', $defaults );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$position = isset( $settings['sidebar_position'] ) ? $settings['sidebar_position'] : $defaults['sidebar_position'];
		if ( ! in_array( $position, array( 'left', 'right' ), true ) ) {
			$position = $defaults['sidebar_position'];
		}

		$visible = isset( $settings['sidebar_visible'] ) ? (bool) $settings['sidebar_visible'] : $defaults['sidebar_visible'];

		return array(
			'sidebar_position' => $position,
			'sidebar_visible'  => $visible,
		);
	}

	/**
	 * Build shell classes based on layout settings.
	 *
	 * @param array $layout_settings Layout settings.
	 * @return string
	 */
	private function get_shell_classes( $layout_settings ) {
		$classes = array(
			$this->get_css_class( 'admin-shell' ),
			$this->get_css_class( 'layout' ),
			$this->get_css_class( 'layout-sidebar-' . $layout_settings['sidebar_position'] ),
		);

		if ( ! $layout_settings['sidebar_visible'] ) {
			$classes[] = $this->get_css_class( 'layout-sidebar-hidden' );
		}

		$classes = array_map( 'sanitize_html_class', $classes );

		return implode( ' ', $classes );
	}

	/**
	 * Build CSS class names based on the plugin slug.
	 *
	 * @param string $suffix Suffix for the class name.
	 * @return string
	 */
	private function get_css_class( $suffix ) {
		return WPSTATIC_SLUG . '-' . $suffix;
	}
}
