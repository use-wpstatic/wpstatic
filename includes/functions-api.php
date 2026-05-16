<?php
/**
 * Global API functions for WPStatic plugin.
 *
 * Copyright (C) 2026 Anindya Sundar Mandal
 *
 * This file is part of WPStatic. For full license text, see license.txt.
 *
 * @package WPStatic
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the WPStatic Directories service instance.
 *
 * @return \WPStatic\Directories
 */
function wpstatic_directories() {
	return \WPStatic\Directories::instance();
}

/**
 * Get whether the web server is Apache or LiteSpeed.
 *
 * @see \WPStatic\Directories::is_apache()
 *
 * @return bool
 */
function wpstatic_is_apache() {
	return wpstatic_directories()->is_apache();
}

/**
 * Return the WPStatic Logger instance.
 *
 * @return \WPStatic\Logger
 */
function wpstatic_logger() {
	return \WPStatic\Logger::instance();
}

/**
 * Return the WPStatic Renderer instance.
 *
 * @return \WPStatic\Renderer
 */
function wpstatic_renderer() {
	return \WPStatic\Renderer::instance();
}

/**
 * Return a new WPStatic URL_Collector instance.
 *
 * @return \WPStatic\URL_Collector
 */
function wpstatic_url_collector() {
	return new \WPStatic\URL_Collector();
}

/**
 * Return a new WPStatic HTML_Parser instance.
 *
 * @return \WPStatic\HTML_Parser
 */
function wpstatic_html_parser() {
	return new \WPStatic\HTML_Parser();
}

/**
 * Return a new WPStatic CSS_Parser instance.
 *
 * @return \WPStatic\CSS_Parser
 */
function wpstatic_css_parser() {
	return new \WPStatic\CSS_Parser();
}

/**
 * Return a new WPStatic JS_Parser instance.
 *
 * @return \WPStatic\JS_Parser
 */
function wpstatic_js_parser() {
	return new \WPStatic\JS_Parser();
}

/**
 * Return a new WPStatic Rewriter instance.
 *
 * @return \WPStatic\Rewriter
 */
function wpstatic_rewriter() {
	return new \WPStatic\Rewriter();
}

/**
 * Return a new WPStatic Export_Job instance.
 *
 * @return \WPStatic\Export_Job
 */
function wpstatic_export_job() {
	return new \WPStatic\Export_Job();
}

/**
 * Return a WPStatic plugin table name from a known key.
 *
 * @param string $key Table key.
 * @return string
 */
function wpstatic_table_name( $key ) {
	global $wpdb;

	$tables = array(
		'exports'        => 'wpstatic_exports',
		'urls'           => 'wpstatic_urls',
		'url_references' => 'wpstatic_url_references',
	);

	if ( ! isset( $tables[ $key ] ) ) {
		return '';
	}

	return $wpdb->prefix . $tables[ $key ];
}

/**
 * Resolve an option value as a strict boolean.
 *
 * Accepted true-like string values: "1", "true", "yes", "on".
 * Accepted false-like string values: "0", "false", "no", "off", "".
 * Numeric values are true only when equal to 1.
 *
 * @param string $option_name Option key.
 * @param bool   $default     Default value when option is unset/unknown.
 * @return bool
 */
function wpstatic_get_option_bool( $option_name, $default = false ) {
	$value = get_option( (string) $option_name, (bool) $default );

	if ( is_bool( $value ) ) {
		return $value;
	}

	if ( is_numeric( $value ) ) {
		return (int) $value === 1;
	}

	if ( is_string( $value ) ) {
		$normalized = strtolower( trim( $value ) );
		if ( in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $normalized, array( '0', 'false', 'no', 'off', '' ), true ) ) {
			return false;
		}
	}

	return (bool) $default;
}

/**
 * Save one or more WordPress options from a name => value map.
 *
 * Values may be scalar values or arrays. The caller is responsible for
 * sanitizing and authorizing the data before it reaches this helper.
 *
 * @param array<string, mixed> $options Option values keyed by option name.
 * @return bool True when all options were saved or already had the requested value.
 */
function wpstatic_save_options( array $options ) {
	foreach ( $options as $option_name => $option_value ) {
		$option_name = (string) $option_name;
		if ( '' === $option_name ) {
			return false;
		}

		$missing = new \stdClass();
		$current = get_option( $option_name, $missing );
		$updated = update_option( $option_name, $option_value );
		if ( ! $updated && $current === $missing ) {
			$updated = add_option( $option_name, $option_value );
		}

		// Use loose inequality so equivalent scalars (e.g. bool true vs int 1) do not fail saves.
		if ( ! $updated && get_option( $option_name, $missing ) != $option_value ) {
			return false;
		}
	}

	return true;
}



/**
 * Detects whether HTTP Basic Authentication is actively protecting
 * this WordPress site from public access.
 *
 * Uses two independent methods:
 *   1. Loopback HTTP request  — sends an unauthenticated GET to home_url('/') and
 *      inspects the response for a 401 status + WWW-Authenticate: Basic header.
 *      Works on Apache AND Nginx. This is the authoritative check.
 *
 *   2. .htaccess parse (Apache fallback) — scans the root .htaccess for the
 *      three required Basic Auth directives. Used when the loopback request
 *      fails (e.g. loopback blocked by server config or firewall).
 *
 * @return array {
 *     @type bool        $protected  True if Basic Auth is detected.
 *     @type string|null $method     How it was detected: 'loopback', 'htaccess', or null.
 *     @type string|null $realm      Auth realm extracted from WWW-Authenticate header (loopback only).
 *     @type string|null $error      Error message if loopback failed, null otherwise.
 * }
 */
function wpstatic_detect_basic_auth() {

    $result = [
        'protected' => false,
        'method'    => null,
        'realm'     => null,
        'error'     => null,
    ];

    // -------------------------------------------------------------------------
    // METHOD 1: Loopback HTTP request (works on Apache and Nginx)
    //
    // We intentionally send NO Authorization header. A properly configured
    // Basic Auth setup MUST respond with:
    //   HTTP/1.1 401 Unauthorized
    //   WWW-Authenticate: Basic realm="..."
    //
    // Checking BOTH conditions prevents false positives from unrelated 401s
    // (e.g. REST API permission errors, maintenance mode plugins, etc.).
    // -------------------------------------------------------------------------
    $response = wp_remote_get(
        home_url( '/' ),
        [
            'timeout'     => 10,
            'redirection' => 0,       // Do not follow redirects — a redirect to a
                                      // login page is NOT Basic Auth.
            'sslverify'   => false,   // Allow self-signed certs in dev environments.
            'headers'     => [],      // Explicitly no Authorization header.
        ]
    );

    if ( is_wp_error( $response ) ) {
        // Loopback failed — record the error and fall through to Method 2.
        $result['error'] = sprintf(
            'Loopback request failed: %s',
            $response->get_error_message()
        );
    } else {
        $http_status   = (int) wp_remote_retrieve_response_code( $response );
        $www_auth_header = wp_remote_retrieve_header( $response, 'www-authenticate' );

        if ( 401 === $http_status && false !== stripos( $www_auth_header, 'Basic' ) ) {
            // Definitive match: 401 + "Basic" in WWW-Authenticate.
            $result['protected'] = true;
            $result['method']    = 'loopback';

            // Extract the realm name for informational use.
            if ( preg_match( '/realm\s*=\s*["\']?([^"\']+)["\']?/i', $www_auth_header, $matches ) ) {
                $result['realm'] = trim( $matches[1] );
            }

            return $result; // Conclusive — no need to check .htaccess.
        }

        // A 200 here means the site is publicly accessible.
        // Other codes (403, 503, etc.) are ambiguous — fall through to Method 2.
    }

    // -------------------------------------------------------------------------
    // METHOD 2: Parse root .htaccess (Apache only, fallback)
    //
    // A valid Basic Auth block requires all three of these directives:
    //   AuthType Basic
    //   AuthUserFile /path/to/.htpasswd
    //   Require valid-user  (or Require user <name>)
    //
    // Note: This does NOT detect Basic Auth configured in httpd.conf or a
    // virtual host file, nor Nginx auth_basic directives. Method 1 covers
    // those cases reliably.
    // -------------------------------------------------------------------------
    $htaccess_path = ABSPATH . '.htaccess';

    if ( file_exists( $htaccess_path ) && is_readable( $htaccess_path ) ) {
        $htaccess_content = file_get_contents( $htaccess_path );

        if ( false !== $htaccess_content ) {
            $has_auth_type     = (bool) preg_match( '/^\s*AuthType\s+Basic\s*$/mi',       $htaccess_content );
            $has_auth_userfile = (bool) preg_match( '/^\s*AuthUserFile\s+\S+/mi',          $htaccess_content );
            $has_require       = (bool) preg_match( '/^\s*Require\s+(valid-user|user\s+)/mi', $htaccess_content );

            if ( $has_auth_type && $has_auth_userfile && $has_require ) {
                $result['protected'] = true;
                $result['method']    = 'htaccess';
            }
        }
    }

    return $result;
}


/**
 * Simple boolean wrapper around wpstatic_detect_basic_auth().
 *
 * Use this when you only need a yes/no answer and don't care
 * about how the detection was made.
 *
 * @return bool True if Basic Auth is protecting the site.
 */
function wpstatic_is_basic_auth_enabled() {
    return wpstatic_detect_basic_auth()['protected'];
}


/**
 * Encrypts a plaintext string using AES-256-CBC.
 * Key is derived from WordPress secret keys (filesystem, not DB).
 *
 * @param string $plaintext
 * @return string  Base64-encoded IV + ciphertext, safe to store in wp_options.
 */
function wpstatic_encrypt( string $plaintext ) {
    $key    = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ); // 32 bytes → AES-256
    $iv     = random_bytes( 16 );                                  // Unique IV per encryption
    $cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

    if ( false === $cipher ) {
        // openssl_encrypt failed — log and handle gracefully in your plugin.
        return '';
    }

    return base64_encode( $iv . $cipher ); // Prepend IV so we can decrypt later.
}

/**
 * Decrypts a value previously encrypted by wpstatic_encrypt().
 *
 * @param string $stored  The base64 value from wp_options.
 * @return string  Original plaintext, or empty string on failure.
 */
function wpstatic_decrypt( string $stored ) {
    $key    = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
    $raw    = base64_decode( $stored, true );

    if ( false === $raw || strlen( $raw ) < 17 ) {
        return ''; // Corrupted or empty.
    }

    $iv         = substr( $raw, 0, 16 );
    $ciphertext = substr( $raw, 16 );
    $plaintext  = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

    return ( false !== $plaintext ) ? $plaintext : '';
}


/**
 * Return a filesystem adapter for local file operations.
 *
 * @return \WP_Filesystem_Base|false
 */
function wpstatic_get_filesystem() {
	static $filesystem = null;

	if ( $filesystem instanceof \WP_Filesystem_Base ) {
		return $filesystem;
	}

	if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	}

	if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
		return false;
	}

	$filesystem = new \WP_Filesystem_Direct( null );

	return $filesystem;
}

/**
 * Write contents to a file path.
 *
 * @param string   $path     Absolute file path.
 * @param string   $contents File contents.
 * @param int|null $mode     Optional file mode.
 * @return bool
 */
function wpstatic_fs_put_contents( $path, $contents, $mode = null ) {
	$filesystem = wpstatic_get_filesystem();
	if ( false === $filesystem ) {
		return false;
	}

	$chmod = is_int( $mode ) && $mode > 0
		? $mode
		: ( defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : 0644 );

	return (bool) $filesystem->put_contents( $path, (string) $contents, $chmod );
}

/**
 * Read file contents from a path.
 *
 * @param string $path Absolute file path.
 * @return string|false
 */
function wpstatic_fs_get_contents( $path ) {
	$filesystem = wpstatic_get_filesystem();
	if ( false === $filesystem ) {
		return false;
	}

	return $filesystem->get_contents( $path );
}

/**
 * Apply permissions to a path.
 *
 * @param string $path Absolute path.
 * @param int    $mode Chmod mode.
 * @return bool
 */
function wpstatic_fs_chmod( $path, $mode ) {
	$filesystem = wpstatic_get_filesystem();
	if ( false === $filesystem ) {
		return false;
	}

	return (bool) $filesystem->chmod( $path, (int) $mode );
}

/**
 * Move a file from source to destination.
 *
 * @param string $source      Source file path.
 * @param string $destination Destination file path.
 * @param bool   $overwrite   Whether to overwrite destination.
 * @return bool
 */
function wpstatic_fs_move( $source, $destination, $overwrite = true ) {
	$filesystem = wpstatic_get_filesystem();
	if ( false === $filesystem ) {
		return false;
	}

	if ( '' === $source || ! $filesystem->exists( $source ) ) {
		return false;
	}

	return (bool) $filesystem->move( $source, $destination, (bool) $overwrite );
}

/**
 * Copy a file from source to destination.
 *
 * @param string   $source      Source file path.
 * @param string   $destination Destination file path.
 * @param bool     $overwrite   Whether to overwrite destination.
 * @param int|null $mode        Optional destination mode.
 * @return bool
 */
function wpstatic_fs_copy( $source, $destination, $overwrite = true, $mode = null ) {
	$filesystem = wpstatic_get_filesystem();
	if ( false === $filesystem ) {
		return false;
	}

	if ( '' === $source || ! $filesystem->exists( $source ) ) {
		return false;
	}

	$chmod = is_int( $mode ) && $mode > 0
		? $mode
		: ( defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : 0644 );

	return (bool) $filesystem->copy( $source, $destination, (bool) $overwrite, $chmod );
}

/**
 * Remove a directory.
 *
 * @param string $path      Absolute directory path.
 * @param bool   $recursive Remove child paths recursively.
 * @return bool
 */
function wpstatic_fs_rmdir( $path, $recursive = false ) {
	$filesystem = wpstatic_get_filesystem();
	if ( false === $filesystem ) {
		return false;
	}

	return (bool) $filesystem->rmdir( $path, (bool) $recursive );
}

/**
 * Determine whether a URL should be skipped because it is a root query URL.
 *
 * Example skipped URL: https://example.com/?elementor_library=footer-template
 * Example skipped URL: https://example.com/?cat=2
 * Example allowed URL: https://example.com/assets/site.css?ver=1.0.0
 *
 * @param string $url Absolute or relative URL.
 * @return bool
 */
function wpstatic_should_skip_root_query_url( $url ) {
	$parsed = wp_parse_url( $url );
	if ( ! is_array( $parsed ) || empty( $parsed['query'] ) ) {
		return false;
	}

	$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';

	return ( '' === $path || '/' === $path );
}

/**
 * Determine whether a URL path contains dot-segment traversal.
 *
 * Decodes percent-encoding repeatedly to catch nested encodings such as
 * %252e%252e, normalizes slashes, and flags "."/".." segments.
 *
 * @param string $url Absolute or relative URL.
 * @return bool
 */
function wpstatic_url_has_path_traversal( $url ) {
	$parsed = wp_parse_url( $url );
	if ( ! is_array( $parsed ) ) {
		return false;
	}

	$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
	if ( '' === $path ) {
		return false;
	}

	$decoded = $path;
	for ( $i = 0; $i < 5; $i++ ) {
		$next = rawurldecode( $decoded );
		if ( $next === $decoded ) {
			break;
		}
		$decoded = $next;
	}

	$decoded  = str_replace( '\\', '/', $decoded );
	$decoded  = preg_replace( '#/+#', '/', $decoded );
	$segments = explode( '/', (string) $decoded );

	foreach ( $segments as $segment ) {
		if ( '.' === $segment || '..' === $segment ) {
			return true;
		}
	}

	return false;
}

/**
 * Normalize URL for database storage and deduplication.
 *
 * This keeps query strings, strips fragments, and standardizes trailing slash
 * behavior for non-file paths.
 *
 * @param string $url Absolute URL.
 * @return string
 */
function wpstatic_normalize_url_for_storage( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}

	$parsed = wp_parse_url( $url );
	if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
		return $url;
	}

	$scheme = isset( $parsed['scheme'] ) ? strtolower( (string) $parsed['scheme'] ) : 'http';
	$host   = strtolower( (string) $parsed['host'] );
	$port   = isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
	$path   = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';
	$query  = isset( $parsed['query'] ) ? (string) $parsed['query'] : '';

	if ( '' === $path ) {
		$path = '/';
	}

	$basename = basename( $path );
	if ( false === strpos( $basename, '.' ) ) {
		$path = untrailingslashit( $path ) . '/';
	}

	return $scheme . '://' . $host . $port . $path . ( '' !== $query ? '?' . $query : '' );
}

/**
 * Return canonical guard content for non-Apache index.php files.
 *
 * @return string
 */
function wpstatic_get_export_guard_index_php_content() {
	return "<?php\nhttp_response_code(403);\nexit('Access denied.');\n";
}

/**
 * Return exportable static file extensions.
 * 
 *  In includes/Export_Job.php, when WPStatic scans wp-content/plugins/{name} and wp-content/themes/{name} directories for static assets, it excludes any .txt files (top-level or nested). Important note: this is not a global “ban” on exporting .txt URLs. The general export policy in includes/functions-api.php allows .txt as an exportable static extension.
 *
 * .txt files are blocked only in the “plugin/theme static asset enqueue” step, not when a .txt URL is discovered/exported via the normal URL export flow.
 *
 * @return string[] Lowercase extensions without dots.
 */
function wpstatic_get_exportable_static_extensions() {
	$extensions = array(
		'css',
		'js',
		'mjs',
		'map',
		'json',
		'xml',
		'txt',
		'webmanifest',
		'png',
		'jpg',
		'jpeg',
		'gif',
		'webp',
		'avif',
		'svg',
		'ico',
		'bmp',
		'apng',
		'woff',
		'woff2',
		'ttf',
		'otf',
		'eot',
		'mp3',
		'wav',
		'ogg',
		'mp4',
		'webm',
		'ogv',
		'pdf',
		'wasm',
		'csv',
		'html',
		'htm',
		'xhtml',
		'atom',
		'rss',
		'yml',
		'yaml',
	);

	/**
	 * Filter exportable static file extensions.
	 *
	 * @param string[] $extensions Lowercase extensions without dots.
	 */
	$extensions = apply_filters( 'wpstatic_exportable_extensions', $extensions );
	if ( ! is_array( $extensions ) ) {
		return array();
	}

	$normalized = array();
	foreach ( $extensions as $extension ) {
		$extension = ltrim( strtolower( (string) $extension ), '.' );
		if ( '' !== $extension ) {
			$normalized[ $extension ] = true;
		}
	}

	return array_keys( $normalized );
}

/**
 * Return denied executable/server-side file extensions.
 *
 * @return string[] Lowercase extensions without dots.
 */
function wpstatic_get_non_exportable_server_side_extensions() {
	$extensions = array(
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'php8',
		'phtml',
		'phar',
		'inc',
		'cgi',
		'fcgi',
		'pl',
		'py',
		'rb',
		'asp',
		'aspx',
		'jsp',
		'jspx',
		'cfm',
		'cfml',
		'shtml',
		'shtm',
		'sh',
		'bash',
		'zsh',
		'ksh',
		'bat',
		'cmd',
		'exe',
		'dll',
		'so',
	);

	/**
	 * Filter denied executable/server-side file extensions.
	 *
	 * @param string[] $extensions Lowercase extensions without dots.
	 */
	$extensions = apply_filters( 'wpstatic_non_exportable_extensions', $extensions );
	if ( ! is_array( $extensions ) ) {
		return array();
	}

	$normalized = array();
	foreach ( $extensions as $extension ) {
		$extension = ltrim( strtolower( (string) $extension ), '.' );
		if ( '' !== $extension ) {
			$normalized[ $extension ] = true;
		}
	}

	return array_keys( $normalized );
}

/**
 * Determine whether PHP file content contains comments only.
 *
 * @param string $content Raw PHP file content.
 * @return bool
 */
function wpstatic_is_comment_only_php_content( $content ) {
	if ( ! function_exists( 'token_get_all' ) ) {
		return false;
	}

	$tokens       = token_get_all( (string) $content );
	$has_open_tag = false;

	foreach ( $tokens as $token ) {
		if ( is_string( $token ) ) {
			$trimmed = trim( $token );
			if ( '' === $trimmed ) {
				continue;
			}
			if ( ';' === $trimmed ) {
				continue;
			}

			return false;
		}

		$token_id    = isset( $token[0] ) ? (int) $token[0] : 0;
		$token_value = isset( $token[1] ) ? (string) $token[1] : '';

		if ( T_OPEN_TAG === $token_id ) {
			$has_open_tag = true;
			continue;
		}

		if ( T_CLOSE_TAG === $token_id || T_WHITESPACE === $token_id || T_COMMENT === $token_id || T_DOC_COMMENT === $token_id ) {
			continue;
		}

		if ( T_INLINE_HTML === $token_id && '' === trim( $token_value ) ) {
			continue;
		}

		return false;
	}

	return $has_open_tag;
}

/**
 * Determine whether a local index.php file is safe to export.
 *
 * Allowed only when:
 * - the file contains comments only and no executable PHP code; or
 * - the file content exactly matches the configured guard content.
 *
 * @param string $path Absolute file path.
 * @return bool
 */
function wpstatic_is_allowed_index_php_source( $path ) {
	$path = (string) $path;
	if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
		return false;
	}

	if ( 'index.php' !== strtolower( basename( $path ) ) ) {
		return false;
	}

	$content = wpstatic_fs_get_contents( $path );
	if ( false === $content ) {
		return false;
	}

	$content           = (string) $content;
	$normalized        = str_replace( array( "\r\n", "\r" ), "\n", $content );
	$expected          = wpstatic_get_export_guard_index_php_content();
	$expected_normalized = str_replace( array( "\r\n", "\r" ), "\n", $expected );

	if ( $normalized === $expected_normalized ) {
		return true;
	}

	return wpstatic_is_comment_only_php_content( $content );
}

/**
 * Determine whether a content type is safe to export.
 *
 * @param string $content_type Raw Content-Type header value.
 * @return bool
 */
function wpstatic_is_exportable_content_type( $content_type ) {
	$content_type = strtolower( trim( (string) $content_type ) );
	if ( '' === $content_type ) {
		return true;
	}

	$semi_pos = strpos( $content_type, ';' );
	if ( false !== $semi_pos ) {
		$content_type = trim( substr( $content_type, 0, $semi_pos ) );
	}

	$denied_exact = array(
		'application/x-httpd-php',
		'application/x-httpd-php-source',
		'application/x-php',
		'text/x-php',
		'text/php',
		'application/php',
		'application/x-cgi',
		'application/x-httpd-cgi',
		'text/x-cgi',
		'application/x-perl',
		'text/x-perl',
		'application/x-python',
		'text/x-python',
		'application/x-ruby',
		'text/x-ruby',
		'application/x-shellscript',
		'text/x-shellscript',
	);

	$denied_prefixes = array(
		'application/x-httpd-php',
		'application/x-httpd-ea-php',
		'application/x-httpd-fastphp',
	);

	$allowed = true;
	if ( in_array( $content_type, $denied_exact, true ) ) {
		$allowed = false;
	} else {
		foreach ( $denied_prefixes as $prefix ) {
			if ( 0 === strpos( $content_type, $prefix ) ) {
				$allowed = false;
				break;
			}
		}
	}

	/**
	 * Filter whether a content type is exportable.
	 *
	 * @param bool   $allowed      Whether the content type is allowed.
	 * @param string $content_type Content type without parameters.
	 */
	return (bool) apply_filters( 'wpstatic_is_exportable_content_type', $allowed, $content_type );
}

/**
 * Resolve local source path for a URL when it maps to an existing file path.
 *
 * @param string $url Absolute or relative URL.
 * @return string Absolute local source path or empty string.
 */
function wpstatic_resolve_local_source_path_for_url( $url ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return '';
	}

	$renderer = wpstatic_renderer();
	if ( ! $renderer->url_is_file( $url ) ) {
		return '';
	}

	$mapping = $renderer->url_to_path_mapping( $url );
	if ( ! is_array( $mapping ) || empty( $mapping['source_path'] ) || ! is_string( $mapping['source_path'] ) ) {
		return '';
	}

	return (string) $mapping['source_path'];
}

/**
 * Determine whether a URL is exportable under static-output policy.
 *
 * @param string $url          Absolute or relative URL.
 * @param string $source_path  Optional absolute local source path.
 * @param string $content_type Optional content type.
 * @return bool
 */
function wpstatic_is_exportable_url( $url, $source_path = '', $content_type = '' ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return false;
	}

	$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
	$basename = strtolower( basename( $path ) );
	$ext      = strtolower( (string) pathinfo( $basename, PATHINFO_EXTENSION ) );

	if ( '' !== $ext ) {
		if ( 'index.php' === $basename ) {
			$source_path = (string) $source_path;
			if ( '' === $source_path ) {
				$source_path = wpstatic_resolve_local_source_path_for_url( $url );
			}
			if ( ! wpstatic_is_allowed_index_php_source( $source_path ) ) {
				return false;
			}
		} else {
			$denied_extensions = wpstatic_get_non_exportable_server_side_extensions();
			if ( in_array( $ext, $denied_extensions, true ) ) {
				return false;
			}

			$allowed_extensions = wpstatic_get_exportable_static_extensions();
			if ( ! in_array( $ext, $allowed_extensions, true ) ) {
				return false;
			}
		}
	}

	if ( ! wpstatic_is_exportable_content_type( $content_type ) ) {
		return false;
	}

	/**
	 * Filter whether URL is exportable.
	 *
	 * @param bool   $is_exportable Whether URL is exportable.
	 * @param string $url           URL.
	 * @param string $source_path   Local source path.
	 * @param string $content_type  Content type.
	 */
	return (bool) apply_filters( 'wpstatic_is_exportable_url', true, $url, (string) $source_path, (string) $content_type );
}

/**
 * Determine whether a discovered URL should be skipped.
 *
 * @param string $url         Absolute or relative URL.
 * @param string $source_path Optional source path for file policy checks.
 * @return bool
 */
function wpstatic_should_skip_discovered_url( $url, $source_path = '' ) {
	if ( wpstatic_should_skip_root_query_url( $url ) ) {
		return true;
	}

	if ( wpstatic_url_has_path_traversal( $url ) ) {
		return true;
	}

	return ! wpstatic_is_exportable_url( $url, $source_path );
}

/**
 * Determine whether a relative export path must be excluded from ZIP output.
 *
 * @param string $relative_path Relative path inside export directory.
 * @return bool
 */
function wpstatic_should_exclude_from_zip( $relative_path ) {
	$relative_path = str_replace( '\\', '/', (string) $relative_path );
	$basename      = strtolower( basename( $relative_path ) );

	if ( '.htaccess' === $basename ) {
		return true;
	}

	$extension = strtolower( (string) pathinfo( $basename, PATHINFO_EXTENSION ) );
	$exclude   = ( 'php' === $extension );

	/**
	 * Filter whether a relative path should be excluded from ZIP output.
	 *
	 * @param bool   $exclude       Whether to exclude.
	 * @param string $relative_path Relative export path.
	 */
	return (bool) apply_filters( 'wpstatic_should_exclude_from_zip', $exclude, $relative_path );
}

/**
 * Return a new WPStatic Driver_Zip instance.
 *
 * @return \WPStatic\Driver_Zip
 */
function wpstatic_driver_zip() {
	return new \WPStatic\Driver_Zip();
}

/**
 * Collect user-configured WPStatic settings from options table.
 *
 * @return array<string, mixed>
 */
function wpstatic_get_all_settings() {
	global $wpdb;

	$settings = array();
	$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prepare(
			'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s AND option_name NOT LIKE %s',
			$wpdb->options,
			'wpstatic_%',
			'%_transient_%'
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return $settings;
	}

	foreach ( $rows as $row ) {
		if ( empty( $row['option_name'] ) ) {
			continue;
		}

		$name  = (string) $row['option_name'];
		$value = maybe_unserialize( $row['option_value'] );
		if ( 0 === strpos( $name, 'wpstatic_http_basic_auth' ) ) {
			$value = wpstatic_redact_http_basic_auth_settings( $value );
		}
		$settings[ $name ] = wpstatic_sanitize_diagnostics_value( $value );
	}

	return $settings;
}

/**
 * Redact HTTP Basic Auth credentials from diagnostics settings output.
 *
 * @param mixed $value Option value.
 * @return mixed
 */
function wpstatic_redact_http_basic_auth_settings( $value ) {
	if ( ! is_array( $value ) ) {
		return '[REDACTED]';
	}

	foreach ( array( 'username', 'password' ) as $field ) {
		if ( array_key_exists( $field, $value ) ) {
			$value[ $field ] = '[REDACTED]';
		}
	}

	return $value;
}

/**
 * Redact sensitive values recursively.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function wpstatic_sanitize_diagnostics_value( $value ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ $key ] = wpstatic_sanitize_diagnostics_value( $item );
		}
		return $out;
	}

	if ( is_string( $value ) ) {
		$replacements = array();
		if ( defined( 'DB_USER' ) && '' !== DB_USER ) {
			$replacements[ DB_USER ] = '[REDACTED]';
		}
		if ( defined( 'DB_PASSWORD' ) && '' !== DB_PASSWORD ) {
			$replacements[ DB_PASSWORD ] = '[REDACTED]';
		}

		$admin_email = get_option( 'admin_email' );
		if ( is_string( $admin_email ) && '' !== $admin_email ) {
			$replacements[ $admin_email ] = '[REDACTED]';
		}

		if ( ! empty( $replacements ) ) {
			$value = strtr( $value, $replacements );
		}
	}

	return $value;
}
