<?php
/**
 * CSS parser for URL extraction.
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
 * Parse CSS content and extract URLs.
 */
class CSS_Parser {

	/**
	 * Prefixes that should be skipped.
	 *
	 * @var string[]
	 */
	private static $skip_prefixes = array( 'data:', 'javascript:', 'mailto:', 'tel:', '#' );

	/**
	 * Extract absolute URLs from CSS content.
	 *
	 * @param string $css      CSS content.
	 * @param string $base_url Absolute base URL.
	 * @return string[] Absolute URLs.
	 */
	public function extract_urls( $css, $base_url ) {
		$urls = array();

		if ( '' === trim( (string) $css ) ) {
			return $urls;
		}

		foreach ( $this->extract_url_function_values( $css ) as $value ) {
			$this->add_url_if_valid( $value, $base_url, $urls );
		}

		foreach ( $this->extract_import_values( $css ) as $value ) {
			$this->add_url_if_valid( $value, $base_url, $urls );
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Extract values inside CSS url(...) functions.
	 *
	 * @param string $css CSS content.
	 * @return string[]
	 */
	private function extract_url_function_values( $css ) {
		$matches = array();
		$found   = preg_match_all( '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', $css, $matches );

		if ( ! $found || empty( $matches[2] ) ) {
			return array();
		}

		return $matches[2];
	}

	/**
	 * Extract values from @import rules.
	 *
	 * @param string $css CSS content.
	 * @return string[]
	 */
	private function extract_import_values( $css ) {
		$urls = array();

		$with_url = array();
		$has_url  = preg_match_all( '/@import\s+url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', $css, $with_url );
		if ( $has_url && ! empty( $with_url[2] ) ) {
			$urls = array_merge( $urls, $with_url[2] );
		}

		$quoted = array();
		$has_q  = preg_match_all( '/@import\s+(["\'])([^"\']+)\1/i', $css, $quoted );
		if ( $has_q && ! empty( $quoted[2] ) ) {
			$urls = array_merge( $urls, $quoted[2] );
		}

		return $urls;
	}

	/**
	 * Add a normalized absolute URL if allowed.
	 *
	 * @param string   $raw_url  Raw URL.
	 * @param string   $base_url Base URL.
	 * @param string[] $urls     URL list.
	 * @return void
	 */
	private function add_url_if_valid( $raw_url, $base_url, &$urls ) {
		$normalized = $this->normalize_to_absolute( $raw_url, $base_url );
		if ( null !== $normalized ) {
			$urls[] = $normalized;
		}
	}

	/**
	 * Normalize a URL to absolute form.
	 *
	 * @param string $url      Raw URL.
	 * @param string $base_url Absolute base URL.
	 * @return string|null Absolute URL or null.
	 */
	private function normalize_to_absolute( $url, $base_url ) {
		$url = trim( $url );

		if ( $this->should_skip_url( $url ) ) {
			return null;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = wp_parse_url( $base_url, PHP_URL_SCHEME );
			return ( $scheme ? $scheme : 'https' ) . ':' . $url;
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$parsed = wp_parse_url( $base_url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return null;
		}

		$origin = $parsed['scheme'] . '://' . $parsed['host'];
		if ( ! empty( $parsed['port'] ) ) {
			$origin .= ':' . $parsed['port'];
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return $origin . $url;
		}

		$base_path  = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		$last_slash = strrpos( $base_path, '/' );
		$base_dir   = ( false !== $last_slash ) ? substr( $base_path, 0, $last_slash ) : '';
		$resolved   = $this->resolve_dot_segments( $base_dir . '/' . $url );

		return $origin . $resolved;
	}

	/**
	 * Determine whether a URL should be skipped.
	 *
	 * @param string $url URL value.
	 * @return bool
	 */
	private function should_skip_url( $url ) {
		if ( '' === $url ) {
			return true;
		}

		foreach ( self::$skip_prefixes as $prefix ) {
			if ( 0 === strpos( $url, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve dot segments in a URL path.
	 *
	 * @param string $path Path value.
	 * @return string
	 */
	private function resolve_dot_segments( $path ) {
		$segments = explode( '/', $path );
		$resolved = array();

		foreach ( $segments as $segment ) {
			if ( '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $resolved );
				continue;
			}
			$resolved[] = $segment;
		}

		$result = implode( '/', $resolved );
		if ( 0 !== strpos( $result, '/' ) ) {
			$result = '/' . $result;
		}

		return $result;
	}
}
