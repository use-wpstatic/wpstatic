<?php
/**
 * JavaScript parser for URL extraction.
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
 * Parse JavaScript content to discover referenced URLs.
 */
class JS_Parser {

	/**
	 * Extract absolute same-origin URLs from JavaScript.
	 *
	 * @param string $js       JavaScript source.
	 * @param string $base_url Base URL for origin matching.
	 * @return string[] Absolute URLs.
	 */
	public function extract_urls( $js, $base_url ) {
		$js = (string) $js;
		if ( '' === trim( $js ) ) {
			return array();
		}

		$origin = $this->get_origin( $base_url );
		$urls   = array();

		$urls = array_merge( $urls, $this->extract_literal_urls( $js ) );
		$urls = array_merge( $urls, $this->extract_concatenated_urls( $js ) );
		$urls = array_merge( $urls, $this->extract_template_urls( $js ) );

		return $this->filter_and_log_urls( $urls, $origin );
	}

	/**
	 * Extract absolute URLs found in quoted string literals.
	 *
	 * @param string $js JavaScript source.
	 * @return string[]
	 */
	private function extract_literal_urls( $js ) {
		$matches = array();
		$pattern = '/(?:["\'])([^"\']+)(?:["\'])|(?:`)([^`]+)(?:`)/i';
		preg_match_all( $pattern, $js, $matches, PREG_SET_ORDER );

		$urls = array();
		foreach ( $matches as $match ) {
			$url = ! empty( $match[1] ) ? $match[1] : $match[2];
			$url = $this->normalize_candidate_url( $url );
			if ( '' === $url ) {
				continue;
			}

			if ( preg_match( '#^https?://#i', $url ) ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Extract URLs built with static string concatenation.
	 *
	 * @param string $js JavaScript source.
	 * @return string[]
	 */
	private function extract_concatenated_urls( $js ) {
		$matches = array();
		$pattern = '/(["\'])(https?:\/\/[^"\']+)\1\s*\+\s*(["\'])(\/[^"\']*)\3/i';
		preg_match_all( $pattern, $js, $matches, PREG_SET_ORDER );

		$urls = array();
		foreach ( $matches as $match ) {
			$combined = rtrim( $match[2], '/' ) . $match[4];
			$combined = $this->normalize_candidate_url( $combined );
			if ( '' !== $combined ) {
				$urls[] = $combined;
			}
		}

		return $urls;
	}

	/**
	 * Extract best-effort URLs from template literals with interpolation.
	 *
	 * @param string $js JavaScript source.
	 * @return string[]
	 */
	private function extract_template_urls( $js ) {
		$matches = array();
		$pattern = '/`(https?:\/\/[^`$]+)\$\{[^}]+\}([^`]*)`/i';
		preg_match_all( $pattern, $js, $matches, PREG_SET_ORDER );

		$urls = array();
		foreach ( $matches as $match ) {
			$candidate = $this->normalize_candidate_url( $match[1] . $match[2] );
			if ( '' !== $candidate ) {
				$urls[] = $candidate;
			}

			$fallback = $this->normalize_candidate_url( $match[1] );
			if ( '' !== $fallback ) {
				$urls[] = $fallback;
			}
		}

		return $urls;
	}

	/**
	 * Filter discovered URLs and log accepted ones.
	 *
	 * @param string[] $urls   Candidate URLs.
	 * @param string   $origin Site origin.
	 * @return string[] Absolute same-origin URLs.
	 */
	private function filter_and_log_urls( $urls, $origin ) {
		$accepted = array();

		foreach ( $urls as $url ) {
			if ( '' === $url || ! $this->is_same_origin_url( $url, $origin ) ) {
				continue;
			}

			$allow = apply_filters( 'wpstatic_js_rewrite_allow', true, $url );
			if ( ! $allow ) {
				continue;
			}

			$accepted[] = $url;
			wpstatic_logger()->log_info( 'JS parser discovered URL: ' . $url );
		}

		return array_values( array_unique( $accepted ) );
	}

	/**
	 * Normalize candidate URL values.
	 *
	 * @param string $url Raw URL.
	 * @return string Normalized URL or empty string.
	 */
	private function normalize_candidate_url( $url ) {
		$url = trim( (string) $url );
		$url = str_replace( array( '\/', '\\"', "\\'" ), array( '/', '"', "'" ), $url );
		$url = preg_replace( '/[\s"\']+$/', '', $url );

		if ( 0 === strpos( $url, 'data:' ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Build origin string from a URL.
	 *
	 * @param string $url URL.
	 * @return string Origin.
	 */
	private function get_origin( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return strtolower( $origin );
	}

	/**
	 * Check whether URL is absolute and same-origin.
	 *
	 * @param string $url    URL.
	 * @param string $origin Origin.
	 * @return bool
	 */
	private function is_same_origin_url( $url, $origin ) {
		if ( '' === $origin ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$url_origin = strtolower( $parts['scheme'] . '://' . $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$url_origin .= ':' . $parts['port'];
		}

		return $url_origin === $origin;
	}
}
