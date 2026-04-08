<?php
/**
 * Renderer class for WPStatic plugin.
 *
 * Renders WordPress pages/posts to static HTML and copies asset files
 * for static site export. Fetches page/post content over HTTP.
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
 * Class Renderer
 *
 * Handles URL analysis, internal page rendering, HTTP fetching,
 * and filesystem operations for the WPStatic export pipeline.
 */
class Renderer {

	/**
	 * Single instance of the class.
	 *
	 * @var Renderer|null
	 */
	private static $instance = null;

	/**
	 * Absolute path to the export directory (no trailing slash).
	 *
	 * @var string|null
	 */
	private $export_dir = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return Renderer
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

	/*
	|--------------------------------------------------------------------------
	| Configuration
	|--------------------------------------------------------------------------
	*/

	/**
	 * Set the export directory path.
	 *
	 * @param string $dir Absolute path to the export directory.
	 * @return void
	 */
	public function set_export_dir( $dir ) {
		$this->export_dir = untrailingslashit( $dir );
	}

	/**
	 * Get the current export directory.
	 *
	 * @return string|null
	 */
	public function get_export_dir_path() {
		return $this->export_dir;
	}

	/*
	|--------------------------------------------------------------------------
	| URL analysis
	|--------------------------------------------------------------------------
	*/

	/**
	 * Check if a URL belongs to this WordPress site.
	 *
	 * Compares the URL host and port with site_url() and home_url().
	 * Relative URLs (without host) are treated as belonging to the site.
	 *
	 * @param string $url Absolute or relative URL.
	 * @return bool True if the URL is within this WordPress site.
	 */
	public function url_within_site( $url ) {
		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['host'] ) ) {
			return true;
		}

		$url_host = strtolower( $parsed['host'] );
		$url_port = isset( $parsed['port'] ) ? (int) $parsed['port'] : 0;

		foreach ( array( site_url(), home_url() ) as $base_url ) {
			$base      = wp_parse_url( $base_url );
			$base_host = strtolower( $base['host'] );
			$base_port = isset( $base['port'] ) ? (int) $base['port'] : 0;

			if ( $url_host === $base_host && $url_port === $base_port ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a URL points to a file (has a file extension).
	 *
	 * Checks the basename of the URL path for a dot-separated extension
	 * of 1–10 alphanumeric characters at the end.
	 *
	 * @param string $url URL to check.
	 * @return bool True if the URL path ends with a file extension.
	 */
	public function url_is_file( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( empty( $path ) || '/' === $path ) {
			return false;
		}

		$basename = basename( $path );

		return (bool) preg_match( '/\.\w{1,10}$/', $basename );
	}

	/**
	 * Map a URL to source and export filesystem paths.
	 *
	 * source_path is non-null only when url_is_file() returns true.
	 * For non-file URLs, export_path appends /index.html.
	 *
	 * @param string $url Absolute URL to map.
	 * @return array{source_path: string|null, export_path: string|null}
	 */
	public function url_to_path_mapping( $url ) {
		$relative = $this->url_to_relative_path( $url );
		if ( null === $relative ) {
			return array(
				'source_path' => null,
				'export_path' => null,
			);
		}

		$source_path = null;
		if ( $this->url_is_file( $url ) ) {
			$doc_root    = $this->get_document_root();
			$candidate   = untrailingslashit( $doc_root ) . '/' . $relative;
			$source_path = $this->validated_source_path( $candidate, $doc_root );
		}

		$export_path = null;
		if ( null !== $this->export_dir ) {
			if ( '' === $relative ) {
				$candidate = $this->export_dir . '/index.html';
			} elseif ( $this->url_is_file( $url ) ) {
				$candidate = $this->export_dir . '/' . $relative;
			} else {
				$candidate = rtrim( $this->export_dir . '/' . $relative, '/' ) . '/index.html';
			}
			$export_path = $this->validated_export_path( $candidate );
		}

		return array(
			'source_path' => $source_path,
			'export_path' => $export_path,
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Main render
	|--------------------------------------------------------------------------
	*/

	/**
	 * Render or fetch content for a given URL.
	 *
	 * Dispatches to the appropriate handler based on URL type:
	 * - External URLs are skipped (not fetched).
	 * - File URLs are copied from filesystem or fetched over HTTP.
	 * - Page/post URLs are fetched over HTTP.
	 *
	 * @param string $url       Absolute URL to render.
	 * @param int    $object_id Optional. Associated WordPress object ID.
	 * @return array{success: bool, content: string, content_type: string,
	 *               http_status_code: int|null, error: string|null,
	 *               temp_export_path?: string}
	 */
	public function render( $url, $object_id = 0 ) {
		if ( ! $this->url_within_site( $url ) ) {
			return $this->render_error( 'External URL — skipped.' );
		}

		$mapping = $this->url_to_path_mapping( $url );

		if ( $this->url_is_file( $url ) ) {
			$source_path = ( is_array( $mapping ) && ! empty( $mapping['source_path'] ) )
				? (string) $mapping['source_path']
				: '';
			if ( function_exists( 'wpstatic_is_exportable_url' ) && ! wpstatic_is_exportable_url( $url, $source_path ) ) {
				return $this->render_error( 'Blocked by static export policy.' );
			}

			return $this->handle_file_url( $url, $mapping );
		}

		$result = $this->fetch_over_http( $url );
		if (
			! empty( $result['success'] )
			&& function_exists( 'wpstatic_is_exportable_url' )
			&& ! wpstatic_is_exportable_url( $url, '', isset( $result['content_type'] ) ? (string) $result['content_type'] : '' )
		) {
			return $this->render_error( 'Blocked by static export policy.' );
		}

		if ( $result['success'] && null !== $mapping['export_path'] ) {
			$this->save_content( $result['content'], $mapping['export_path'] );
		}

		return $result;
	}

	/*
	|--------------------------------------------------------------------------
	| HTTP fetch
	|--------------------------------------------------------------------------
	*/

	/**
	 * Fetch URL content over HTTP using wp_remote_get.
	 *
	 * @param string $url Absolute URL to fetch.
	 * @return array{success: bool, content: string, content_type: string,
	 *               http_status_code: int|null, error: string|null}
	 */
	public function fetch_over_http( $url ) {
		$timeout = $this->resolve_fetch_timeout( $url );
		$sslverify = true;

		if ( $this->url_within_site( $url ) && $this->allow_insecure_local_http_fetch_enabled() ) {
			$sslverify = false;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => $timeout,
				'sslverify' => $sslverify,
				'headers'   => array(
					'User-Agent' => 'WPStatic/' . WPSTATIC_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();

			if ( $sslverify && $this->is_tls_verification_failure( $error_message ) ) {
				$guidance = 'If this is a local/self-signed certificate, set option wpstatic_allow_insecure_local_http_fetch to true.';

				if ( function_exists( 'wpstatic_logger' ) ) {
					wpstatic_logger()->log_error(
						sprintf(
							'TLS verification failed while fetching URL: %s. %s Original error: %s',
							$url,
							$guidance,
							$error_message
						)
					);
				}

				return $this->render_error( $error_message . ' ' . $guidance );
			}

			return $this->render_error( $error_message );
		}

		$status       = wp_remote_retrieve_response_code( $response );
		$body         = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		return array(
			'success'          => ( $status >= 200 && $status < 400 ),
			'content'          => $body,
			'content_type'     => $content_type ? $content_type : '',
			'http_status_code' => (int) $status,
			'error'            => ( $status >= 400 ) ? sprintf( 'HTTP %d', $status ) : null,
		);
	}

	/*
	|--------------------------------------------------------------------------
	| File operations
	|--------------------------------------------------------------------------
	*/

	/**
	 * Copy a file from source to export path using stream copy.
	 *
	 * Creates the target directory tree if it does not exist.
	 * Uses stream_copy_to_stream to avoid loading entire files into memory.
	 *
	 * @param string $source_path Absolute source file path.
	 * @param string $export_path Absolute destination file path.
	 * @return bool True on success, false on failure.
	 */
	public function filesystem_copier( $source_path, $export_path ) {
		$this->ensure_directory( dirname( $export_path ) );

		return wpstatic_fs_copy( $source_path, $export_path, true, 0640 );
	}

	/**
	 * Save string content to an export path on disk.
	 *
	 * Creates the target directory tree if it does not exist.
	 *
	 * @param string $content     Content to write.
	 * @param string $export_path Absolute destination file path.
	 * @return bool True on success, false on failure.
	 */
	public function save_content( $content, $export_path ) {
		$this->ensure_directory( dirname( $export_path ) );

		return wpstatic_fs_put_contents( $export_path, $content, 0640 );
	}

	/**
	 * Save content to a temporary export file and move to final destination.
	 *
	 * Writes rewritten content to the provided temporary file path, then
	 * atomically renames to the final export path when possible. If rename
	 * is not possible (e.g., cross-filesystem), falls back to stream copy.
	 *
	 * @param string $content     Content to write.
	 * @param string $temp_path   Absolute temporary file path.
	 * @param string $export_path Absolute destination file path.
	 * @return bool True on success, false on failure.
	 */
	public function save_content_via_temp( $content, $temp_path, $export_path ) {
		$this->ensure_directory( dirname( $export_path ) );
		$this->ensure_directory( dirname( $temp_path ) );

		$written = wpstatic_fs_put_contents( $temp_path, $content, 0640 );
		if ( ! $written ) {
			return false;
		}

		if ( wpstatic_fs_move( $temp_path, $export_path, true ) ) {
			wpstatic_fs_chmod( $export_path, 0640 );
			return true;
		}

		$copied = $this->filesystem_copier( $temp_path, $export_path );
		if ( $copied && file_exists( $temp_path ) ) {
			wp_delete_file( $temp_path );
		}

		return (bool) $copied;
	}

	/**
	 * Ensure a directory exists, creating it recursively if needed.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool True if the directory exists or was created.
	 */
	public function ensure_directory( $path ) {
		if ( is_dir( $path ) ) {
			return true;
		}

		return wp_mkdir_p( $path );
	}

	/*
	|--------------------------------------------------------------------------
	| Private helpers — file handling
	|--------------------------------------------------------------------------
	*/

	/**
	 * Handle a file-type URL: copy from filesystem or fetch over HTTP.
	 *
	 * @param string $url     Absolute URL.
	 * @param array  $mapping Path mapping from url_to_path_mapping().
	 * @return array Render result array.
	 */
	private function handle_file_url( $url, $mapping ) {
		$source_path = ( is_array( $mapping ) && ! empty( $mapping['source_path'] ) )
			? (string) $mapping['source_path']
			: '';
		if ( function_exists( 'wpstatic_is_exportable_url' ) && ! wpstatic_is_exportable_url( $url, $source_path ) ) {
			return $this->render_error( 'Blocked by static export policy.' );
		}

		if ( null !== $mapping['source_path'] && file_exists( $mapping['source_path'] ) ) {
			if ( null !== $mapping['export_path'] ) {
				$mime_type = $this->get_mime_type( $mapping['source_path'] );

				if ( $this->is_non_binary_file( $mapping['source_path'], $mime_type ) ) {
					$temp_export_path = $this->build_temp_export_path( $mapping['export_path'] );
					$copied           = $this->filesystem_copier( $mapping['source_path'], $temp_export_path );

					if ( $copied ) {
						$content = wpstatic_fs_get_contents( $temp_export_path );
						if ( false === $content ) {
							if ( file_exists( $temp_export_path ) ) {
								wp_delete_file( $temp_export_path );
							}
							return $this->render_error( 'Failed to read temporary file for rewrite.' );
						}

						return array(
							'success'          => true,
							'content'          => (string) $content,
							'content_type'     => $mime_type,
							'http_status_code' => null,
							'error'            => null,
							'temp_export_path' => $temp_export_path,
						);
					}

					return $this->render_error( 'Failed to copy non-binary file to temporary path for rewrite.' );
				}

				$copied = $this->filesystem_copier( $mapping['source_path'], $mapping['export_path'] );

				if ( $copied ) {
					return array(
						'success'          => true,
						'content'          => '',
						//'content'          => file_get_contents( $mapping['source_path'] ), //File content loaded into memory after already being streamed copied efficiently
						'content_type'     => $mime_type,
						'http_status_code' => null,
						'error'            => null,
					);
				}
			}
		}

		$result = $this->fetch_over_http( $url );
		if (
			! empty( $result['success'] )
			&& function_exists( 'wpstatic_is_exportable_url' )
			&& ! wpstatic_is_exportable_url( $url, '', isset( $result['content_type'] ) ? (string) $result['content_type'] : '' )
		) {
			return $this->render_error( 'Blocked by static export policy.' );
		}

		if ( $result['success'] && null !== $mapping['export_path'] ) {
			$this->save_content( $result['content'], $mapping['export_path'] );
		}

		return $result;
	}

	/*
	|--------------------------------------------------------------------------
	| Private helpers — URL and path
	|--------------------------------------------------------------------------
	*/

	/**
	 * Convert an absolute URL to a path relative to home_url().
	 *
	 * @param string $url Absolute URL.
	 * @return string|null Relative path without leading slash, or null when rejected.
	 */
	private function url_to_relative_path( $url ) {
		$url_path = $this->decoded_url_path( wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $url_path ) {
			return '';
		}

		if ( $this->path_has_dot_segments( $url_path ) ) {
			return null;
		}

		$home_url_path = $this->decoded_url_path( wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $home_url_path ) {
			$home_url_path = untrailingslashit( $home_url_path );
		}

		if ( ! empty( $home_url_path ) && 0 === strpos( $url_path, $home_url_path ) ) {
			$relative = substr( $url_path, strlen( $home_url_path ) );
		} else {
			$relative = $url_path;
		}

		if ( $this->path_has_dot_segments( $relative ) ) {
			return null;
		}

		return ltrim( $relative, '/' );
	}

	/**
	 * Decode and normalize a URL path for secure path mapping.
	 *
	 * @param string|false|null $path URL path.
	 * @return string Normalized path using forward slashes.
	 */
	private function decoded_url_path( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return '';
		}

		$decoded = $path;
		for ( $i = 0; $i < 5; $i++ ) {
			$next = rawurldecode( $decoded );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
		}

		$decoded = str_replace( '\\', '/', $decoded );
		$decoded = preg_replace( '#/+#', '/', $decoded );

		return (string) $decoded;
	}

	/**
	 * Determine whether a path contains dot-segment traversal.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	private function path_has_dot_segments( $path ) {
		$segments = explode( '/', (string) $path );
		foreach ( $segments as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate local source path stays within the document root.
	 *
	 * @param string $candidate Candidate source file path.
	 * @param string $doc_root  Document root path.
	 * @return string|null
	 */
	private function validated_source_path( $candidate, $doc_root ) {
		$candidate = wp_normalize_path( (string) $candidate );
		$doc_root  = wp_normalize_path( untrailingslashit( (string) $doc_root ) );

		if ( ! $this->path_is_within_base( $candidate, $doc_root ) ) {
			return null;
		}

		$candidate_real = realpath( $candidate );
		if ( false === $candidate_real ) {
			return $candidate;
		}

		$doc_root_real = realpath( $doc_root );
		if ( false === $doc_root_real ) {
			return $candidate;
		}

		$candidate_real = wp_normalize_path( (string) $candidate_real );
		$doc_root_real  = wp_normalize_path( untrailingslashit( (string) $doc_root_real ) );

		if ( ! $this->path_is_within_base( $candidate_real, $doc_root_real ) ) {
			return null;
		}

		return $candidate;
	}

	/**
	 * Validate export destination path stays within active export directory.
	 *
	 * @param string $candidate Candidate destination path.
	 * @return string|null
	 */
	private function validated_export_path( $candidate ) {
		if ( null === $this->export_dir || '' === $this->export_dir ) {
			return null;
		}

		$candidate = wp_normalize_path( (string) $candidate );
		$base      = wp_normalize_path( untrailingslashit( (string) $this->export_dir ) );

		if ( ! $this->path_is_within_base( $candidate, $base ) ) {
			return null;
		}

		$base_real = realpath( $base );
		if ( false === $base_real ) {
			return $candidate;
		}

		$parent_real = $this->realpath_of_existing_parent( dirname( $candidate ) );
		if ( false === $parent_real ) {
			return $candidate;
		}

		$base_real   = wp_normalize_path( untrailingslashit( (string) $base_real ) );
		$parent_real = wp_normalize_path( untrailingslashit( (string) $parent_real ) );

		if ( ! $this->path_is_within_base( $parent_real, $base_real ) ) {
			return null;
		}

		return $candidate;
	}

	/**
	 * Return realpath for the nearest existing parent directory.
	 *
	 * @param string $path Path that may not exist.
	 * @return string|false
	 */
	private function realpath_of_existing_parent( $path ) {
		$current = wp_normalize_path( (string) $path );
		while ( '' !== $current && ! is_dir( $current ) ) {
			$parent = dirname( $current );
			if ( $parent === $current ) {
				break;
			}
			$current = $parent;
		}

		if ( '' === $current || ! is_dir( $current ) ) {
			return false;
		}

		return realpath( $current );
	}

	/**
	 * Check whether a normalized path is under a normalized base directory.
	 *
	 * @param string $path Candidate path.
	 * @param string $base Base directory path.
	 * @return bool
	 */
	private function path_is_within_base( $path, $base ) {
		$path = untrailingslashit( wp_normalize_path( (string) $path ) );
		$base = untrailingslashit( wp_normalize_path( (string) $base ) );

		return ( $path === $base || 0 === strpos( $path, $base . '/' ) );
	}

	/**
	 * Get the filesystem document root for this site.
	 *
	 * @return string Absolute path without trailing slash.
	 */
	protected function get_document_root() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home_path = get_home_path();
		if ( ! empty( $home_path ) ) {
			return untrailingslashit( $home_path );
		}

		return untrailingslashit( ABSPATH );
	}

	/*
	|--------------------------------------------------------------------------
	| Private helpers — result building
	|--------------------------------------------------------------------------
	*/

	/**
	 * Build a failure result array.
	 *
	 * @param string $message Error description.
	 * @return array Render result with success=false.
	 */
	private function render_error( $message ) {
		return array(
			'success'          => false,
			'content'          => '',
			'content_type'     => '',
			'http_status_code' => null,
			'error'            => $message,
		);
	}

	/**
	 * Resolve HTTP timeout for a single fetch request.
	 *
	 * @param string $url URL being fetched.
	 * @return int Timeout in seconds.
	 */
	private function resolve_fetch_timeout( $url ) {
		$max_execution = (int) ini_get( 'max_execution_time' );
		$timeout       = 20;

		if ( $max_execution > 0 ) {
			$timeout = max( 5, min( 20, $max_execution - 10 ) );
		}

		/**
		 * Filter HTTP timeout per URL.
		 *
		 * @param int    $timeout Timeout in seconds.
		 * @param string $url     URL being fetched.
		 */
		$timeout = (int) apply_filters( 'wpstatic_fetch_timeout', $timeout, $url );

		return max( 5, $timeout );
	}

	/**
	 * Determine whether an HTTP error message indicates TLS verification failure.
	 *
	 * @param string $error_message Error message from WP HTTP API.
	 * @return bool
	 */
	private function is_tls_verification_failure( $error_message ) {
		$needle = strtolower( (string) $error_message );
		$hints  = array(
			'ssl certificate',
			'certificate verify failed',
			'certificate has expired',
			'certificate',
			'x509',
			'curl error 60',
			'unable to get local issuer',
			'peer certificate',
		);

		foreach ( $hints as $hint ) {
			if ( false !== strpos( $needle, $hint ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine the MIME type of a file.
	 *
	 * @param string $path Absolute file path.
	 * @return string MIME type string.
	 */
	private function get_mime_type( $path ) {
		$mime = wp_check_filetype( basename( $path ) );

		return ! empty( $mime['type'] ) ? $mime['type'] : 'application/octet-stream';
	}

	/**
	 * Determine whether a local source file should be treated as non-binary.
	 *
	 * @param string $path      Absolute source path.
	 * @param string $mime_type MIME type.
	 * @return bool
	 */
	private function is_non_binary_file( $path, $mime_type ) {
		$mime_type = strtolower( (string) $mime_type );
		if ( '' !== $mime_type ) {
			if ( 0 === strpos( $mime_type, 'text/' ) ) {
				return true;
			}

			$text_app_mimes = array(
				'application/javascript',
				'text/javascript',
				'application/x-javascript',
				'application/ecmascript',
				'application/json',
				'application/ld+json',
				'application/xml',
				'application/rss+xml',
				'application/atom+xml',
				'image/svg+xml',
			);
			if ( in_array( $mime_type, $text_app_mimes, true ) ) {
				return true;
			}
		}

		$extension = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
		$text_exts = array(
			'css',
			'js',
			'mjs',
			'json',
			'map',
			'html',
			'htm',
			'txt',
			'xml',
			'svg',
			'csv',
			'webmanifest',
		);

		return in_array( $extension, $text_exts, true );
	}

	/**
	 * Build a temporary export path for staged non-binary file writes.
	 *
	 * @param string $export_path Final export file path.
	 * @return string Temporary file path.
	 */
	private function build_temp_export_path( $export_path ) {
		return $export_path . '.wpstatic-tmp-' . uniqid( '', true );
	}

	/**
	 * Whether insecure same-site HTTP fetch is explicitly enabled.
	 *
	 * @return bool
	 */
	private function allow_insecure_local_http_fetch_enabled() {
		if ( function_exists( 'wpstatic_get_option_bool' ) ) {
			return wpstatic_get_option_bool( 'wpstatic_allow_insecure_local_http_fetch', false );
		}

		return false;
	}
}
