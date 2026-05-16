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

		wp_enqueue_script(
			WPSTATIC_SLUG . '-admin-settings',
			WPSTATIC_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			WPSTATIC_VERSION,
			true
		);

		$ui_status = wpstatic_export_job()->get_ui_export_status();
		$auto_start_export = filter_input( INPUT_GET, 'wpstatic_auto_start_export', FILTER_SANITIZE_NUMBER_INT );

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
				'autoStartExport'       => '1' === (string) $auto_start_export,
			)
		);

		wp_localize_script(
			WPSTATIC_SLUG . '-admin-settings',
			'wpstaticAdminSettingsData',
			array(
				'ajaxUrl'                   => admin_url( 'admin-ajax.php' ),
				'nonce'                     => wp_create_nonce( 'wpstatic_settings_nonce' ),
				'makeStaticSiteUrl'         => add_query_arg(
					array(
						'page' => WPSTATIC_SLUG,
						'tab'  => 'make-static-site',
					),
					admin_url( 'admin.php' )
				),
				'autoStartExportUrl'        => add_query_arg(
					array(
						'page'                       => WPSTATIC_SLUG,
						'tab'                        => 'make-static-site',
						'wpstatic_auto_start_export' => '1',
					),
					admin_url( 'admin.php' )
				),
				'msgSettingsSaved'          => __( 'Settings saved successfully.', 'wpstatic' ),
				'msgSettingsSaveError'      => __( 'Settings could not be saved. Please try again.', 'wpstatic' ),
				'msgGenerateQuestion'       => __( 'Do you want to Generate/Export Static Site now?', 'wpstatic' ),
				'msgGenerateYes'            => __( 'Yes', 'wpstatic' ),
				'msgGenerateNo'             => __( "No; I'll do it from the 'Make Static Site' tab later", 'wpstatic' ),
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
			'general'          => __( 'General', 'wpstatic' ),
			'security'         => __( 'Security', 'wpstatic' ),
		);
	}

	/**
	 * Get sidebar tab groups.
	 *
	 * @return array
	 */
	private function get_tab_groups() {
		return array(
			array(
				'heading' => '',
				'tabs'    => array(
					'make-static-site' => __( 'Make Static Site', 'wpstatic' ),
				),
			),
			array(
				'heading' => __( 'Settings', 'wpstatic' ),
				'tabs'    => array(
					'general'  => __( 'General', 'wpstatic' ),
					'security' => __( 'Security', 'wpstatic' ),
				),
			),
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
		if ( null === $tab_raw && isset( $_GET['tab'] ) ) {
			$tab_raw = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
		}

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
		foreach ( $this->get_tab_groups() as $group ) {
			if ( ! empty( $group['heading'] ) ) {
				printf(
					'<span class="%1$s">%2$s</span>',
					esc_attr( $this->get_css_class( 'admin-tab-heading' ) ),
					esc_html( $group['heading'] )
				);
			}

			foreach ( $group['tabs'] as $tab_key => $tab_label ) {
				if ( ! array_key_exists( $tab_key, $tabs ) ) {
					continue;
				}

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
		/*
		if ( 'security' === $current_tab ) {
			$this->render_security_settings_page();
			echo '</section>';
			return;
		}
		*/

		echo '<div class="card">';
		echo '<h1 class="' . esc_attr( $title_class ) . '">' . esc_html( $tabs[ $current_tab ] ) . '</h1>';
		if ( 'make-static-site' === $current_tab ) {
			$export_page = new Export();
			$export_page->render();
		} elseif ( 'general' === $current_tab ) {
			$this->render_general_settings_page();
		}
		elseif ( 'security' === $current_tab ) {
			$this->render_security_settings_page();			
		}

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Render the General settings admin page.
	 *
	 * @return void
	 */
	private function render_general_settings_page() {
		$allow_insecure_local_http_fetch = wpstatic_get_option_bool( 'wpstatic_allow_insecure_local_http_fetch', false );
		$prefer_temp_above_docroot       = wpstatic_get_option_bool( 'wpstatic_prefer_temp_storage_above_document_root', false );

		echo '<div class="card ' . esc_attr( $this->get_css_class( 'settings-card' ) ) . '">';
		echo '<h2>' . esc_html__( 'General Settings', 'wpstatic' ) . '</h2>';
		echo '<form id="wpstatic-general-settings-form" class="' . esc_attr( $this->get_css_class( 'settings-form' ) ) . '" data-settings-group="general">';
		echo '<div class="' . esc_attr( $this->get_css_class( 'field' ) ) . ' ' . esc_attr( $this->get_css_class( 'toggle-field' ) ) . '">';
		echo '<label class="' . esc_attr( $this->get_css_class( 'toggle-label' ) ) . '" for="wpstatic-allow-insecure-local-http-fetch">';
		echo '<span>';
		echo '<span class="' . esc_attr( $this->get_css_class( 'toggle-title' ) ) . '">' . esc_html__( 'Allow insecure local HTTP fetch', 'wpstatic' ) . '</span>';
		echo '<span class="' . esc_attr( $this->get_css_class( 'toggle-description' ) ) . '">' . esc_html__( 'Enable this option to turn off SSL verification for same-site HTTP fetches if an expired, invalid, or self-signed SSL certificate is installed on this WordPress website.', 'wpstatic' ) . '</span>';
		echo '</span>';
		echo '<input type="checkbox" id="wpstatic-allow-insecure-local-http-fetch" name="allow_insecure_local_http_fetch" value="1" role="switch"' . checked( $allow_insecure_local_http_fetch, true, false ) . '>';
		echo '<span class="' . esc_attr( $this->get_css_class( 'toggle-switch' ) ) . '" aria-hidden="true"></span>';
		echo '</label>';
		echo '</div>';
		echo '<div class="' . esc_attr( $this->get_css_class( 'field' ) ) . ' ' . esc_attr( $this->get_css_class( 'toggle-field' ) ) . '">';
		echo '<label class="' . esc_attr( $this->get_css_class( 'toggle-label' ) ) . '" for="wpstatic-prefer-temp-storage-above-document-root">';
		echo '<span>';
		echo '<span class="' . esc_attr( $this->get_css_class( 'toggle-title' ) ) . '">' . esc_html__( 'Prefer temporary storage above document root', 'wpstatic' ) . '</span>';
		echo '<span class="' . esc_attr( $this->get_css_class( 'toggle-description' ) ) . '">' . esc_html__( 'When enabled, WPStatic tries to use a writable directory above the web document root for exports and logs (home directory or parent of document root). If that is not possible, it falls back to the WordPress uploads folder. Turning this on or off clears the saved upload base path so the new choice can take effect.', 'wpstatic' ) . '</span>';
		echo '</span>';
		echo '<input type="checkbox" id="wpstatic-prefer-temp-storage-above-document-root" name="prefer_temp_storage_above_document_root" value="1" role="switch"' . checked( $prefer_temp_above_docroot, true, false ) . '>';
		echo '<span class="' . esc_attr( $this->get_css_class( 'toggle-switch' ) ) . '" aria-hidden="true"></span>';
		echo '</label>';
		echo '</div>';
		echo '<div id="wpstatic-settings-message" class="' . esc_attr( $this->get_css_class( 'settings-message' ) ) . '" aria-live="polite"></div>';
		echo '<div id="wpstatic-settings-export-question" class="' . esc_attr( $this->get_css_class( 'settings-export-question' ) ) . '" style="display:none;"></div>';
		echo '<p class="' . esc_attr( $this->get_css_class( 'settings-actions' ) ) . '">';
		echo '<button type="button" id="wpstatic-save-general-settings" class="button button-primary">' . esc_html__( 'Save Settings', 'wpstatic' ) . '</button>';
		echo '</p>';
		echo '</form>';
		echo '</div>';
	}


	/**
	 * Render the Security settings admin page.
	 *
	 * @return void
	 */
	private function render_security_settings_page() {
		$credentials = get_option( 'wpstatic_http_basic_auth', array() );
		if ( ! is_array( $credentials ) ) {
			$credentials = array();
		}

		$username = isset( $credentials['username'] ) ? wpstatic_decrypt( (string) $credentials['username'] ) : '';
		$password = isset( $credentials['password'] ) ? wpstatic_decrypt( (string) $credentials['password'] ) : '';
		//echo '<div class="card ' . esc_attr( $this->get_css_class( 'settings-card' ) ) . '">';
		//echo '<h1 class="' . esc_attr( $this->get_css_class( 'admin-title' ) ) . '">' . esc_html__( 'Security Settings', 'wpstatic' ) . '</h1>';
		echo '<div class="card ' . esc_attr( $this->get_css_class( 'settings-card' ) ) . '">';
		echo '<h2 class="' . esc_attr( $this->get_css_class( 'admin-title' ) ) . '">' . esc_html__( 'HTTP Basic Auth', 'wpstatic' ) . '</h2>';
		echo '<p>' . esc_html__( 'HTTP Basic Auth can secure your WordPress website by requiring a username and password before anyone can view it. This is useful when the WordPress website is hosted on a live web server, because you can keep the site password-protected instead of making it public. It lets you work with WPStatic from a real server while keeping visitors and search engines out.', 'wpstatic' ) . '</p>';
		echo '<p>' . esc_html__( 'If you have secured your WordPress website with HTTP Basic Auth, enter the credentials below so WPStatic can fetch the protected pages during export.', 'wpstatic' ) . '</p>';

		echo '<form id="wpstatic-security-settings-form" class="' . esc_attr( $this->get_css_class( 'settings-form' ) ) . '" data-settings-group="http_basic_auth">';
		echo '<div class="' . esc_attr( $this->get_css_class( 'field' ) ) . '">';
		echo '<label for="wpstatic-basic-auth-username">' . esc_html__( 'Username of Basic Auth', 'wpstatic' ) . '</label>';
		echo '<input type="text" id="wpstatic-basic-auth-username" name="username" value="' . esc_attr( $username ) . '" autocomplete="username">';
		echo '</div>';
		echo '<div class="' . esc_attr( $this->get_css_class( 'field' ) ) . '">';
		echo '<label for="wpstatic-basic-auth-password">' . esc_html__( 'Password of Basic Auth', 'wpstatic' ) . '</label>';
		echo '<input type="password" id="wpstatic-basic-auth-password" name="password" value="' . esc_attr( $password ) . '" autocomplete="current-password">';
		echo '</div>';
		echo '<div id="wpstatic-settings-message" class="' . esc_attr( $this->get_css_class( 'settings-message' ) ) . '" aria-live="polite"></div>';
		echo '<div id="wpstatic-settings-export-question" class="' . esc_attr( $this->get_css_class( 'settings-export-question' ) ) . '" style="display:none;"></div>';
		echo '<p class="' . esc_attr( $this->get_css_class( 'settings-actions' ) ) . '">';
		echo '<button type="button" id="wpstatic-save-security-settings" class="button button-primary">' . esc_html__( 'Save Settings', 'wpstatic' ) . '</button>';
		echo '</p>';
		echo '</form>';
		echo '</div>';
		//echo '</div>';
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
