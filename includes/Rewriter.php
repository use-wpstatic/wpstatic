<?php
/**
 * Rewriter for HTML, CSS and JavaScript content.
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
 * Rewrite URLs for export output modes.
 */
class Rewriter {

	/**
	 * Rewrite URLs in HTML markup.
	 *
	 * @param string $html        HTML content.
	 * @param string $mode        Rewrite mode.
	 * @param string $origin      Site origin.
	 * @param string $export_base Export page path context.
	 * @return string Rewritten HTML.
	 */
	public function rewrite_html( $html, $mode, $origin, $export_base ) {
		$dom = new \DOMDocument();

		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . (string) $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		foreach ( $dom->childNodes as $node ) {
			if ( XML_PI_NODE === $node->nodeType ) {
				$dom->removeChild( $node );
				break;
			}
		}

		$xpath = new \DOMXPath( $dom );
		$this->rewrite_attribute_query( $xpath, '//*[@href]', 'href', $mode, $origin, $export_base );
		$this->rewrite_attribute_query( $xpath, '//*[@src]', 'src', $mode, $origin, $export_base );
		$this->rewrite_attribute_query( $xpath, '//*[@poster]', 'poster', $mode, $origin, $export_base );
		$this->rewrite_attribute_query( $xpath, '//*[@data]', 'data', $mode, $origin, $export_base );
		$this->rewrite_attribute_query( $xpath, '//*[@action]', 'action', $mode, $origin, $export_base );
		$this->rewrite_attribute_query( $xpath, '//*[@content]', 'content', $mode, $origin, $export_base );

		$srcset_nodes = $xpath->query( '//*[@srcset]' );
		if ( false !== $srcset_nodes ) {
			foreach ( $srcset_nodes as $node ) {
				$node->setAttribute(
					'srcset',
					$this->rewrite_srcset( $node->getAttribute( 'srcset' ), $mode, $origin, $export_base )
				);
			}
		}

		$style_nodes = $xpath->query( '//*[@style]' );
		if ( false !== $style_nodes ) {
			foreach ( $style_nodes as $node ) {
				$node->setAttribute(
					'style',
					$this->rewrite_css( $node->getAttribute( 'style' ), $mode, $origin, $export_base )
				);
			}
		}

		$css_nodes = $dom->getElementsByTagName( 'style' );
		foreach ( $css_nodes as $css_node ) {
			$css_node->textContent = $this->rewrite_css( $css_node->textContent, $mode, $origin, $export_base );
		}

		$script_nodes = $dom->getElementsByTagName( 'script' );
		foreach ( $script_nodes as $script_node ) {
			if ( $script_node->hasAttribute( 'src' ) ) {
				continue;
			}

			$type = strtolower( trim( (string) $script_node->getAttribute( 'type' ) ) );
			if ( ! $this->is_rewritable_inline_script_type( $type ) ) {
				if ( $this->is_rewritable_json_script_node( $script_node, $type ) ) {
					$script_node->textContent = $this->strip_same_origin_domains( $script_node->textContent, $origin );
				}
				continue;
			}

			$script_node->textContent = $this->rewrite_js( $script_node->textContent, $mode, $origin, $export_base );
		}

		return $dom->saveHTML();
	}

	/**
	 * Rewrite URLs in CSS content.
	 *
	 * @param string $css         CSS content.
	 * @param string $mode        Rewrite mode.
	 * @param string $origin      Site origin.
	 * @param string $export_base Export page path context.
	 * @return string Rewritten CSS.
	 */
	public function rewrite_css( $css, $mode, $origin, $export_base ) {
		$css = $this->strip_same_origin_domains( (string) $css, $origin );
		$pattern = '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i';

		return (string) preg_replace_callback(
			$pattern,
			function ( $match ) use ( $mode, $origin, $export_base ) {
				$rewritten = $this->rewrite_single_url( $match[2], $mode, $origin, $export_base );
				if ( $match[2] === $rewritten ) {
					return $match[0];
				}

				return 'url(' . $match[1] . $rewritten . $match[1] . ')';
			},
			(string) $css
		);
	}

	/**
	 * Rewrite literal absolute URLs in JavaScript content.
	 *
	 * @param string $js          JavaScript content.
	 * @param string $mode        Rewrite mode.
	 * @param string $origin      Site origin.
	 * @param string $export_base Export page path context.
	 * @return string Rewritten JavaScript.
	 */
	public function rewrite_js( $js, $mode, $origin, $export_base ) {
		if ( get_option( 'wpstatic_disable_js_rewrite', false ) ) {
			return (string) $js;
		}

		$pattern = '/(["\'])((?:\\\\.|(?!\1).)*)\1/s';
		$rewrote = false;

		$output = (string) preg_replace_callback(
			$pattern,
			function ( $match ) use ( $mode, $origin, $export_base, &$rewrote ) {
				$literal   = $match[2];
				$candidate = str_replace( '\/', '/', $literal );

				if ( ! preg_match( '#^https?://#i', $candidate ) ) {
					return $match[0];
				}

				$allow = apply_filters( 'wpstatic_js_rewrite_allow', true, $candidate );
				if ( ! $allow ) {
					return $match[0];
				}

				$rewritten = $this->strip_same_origin_domains( $candidate, $origin );
				if ( $rewritten === $candidate ) {
					$rewritten = $this->rewrite_single_url( $candidate, $mode, $origin, $export_base );
				}
				if ( $rewritten === $candidate ) {
					return $match[0];
				}

				$rewrote = true;
				if ( false !== strpos( $literal, '\/' ) ) {
					$rewritten = str_replace( '/', '\/', $rewritten );
				}

				return $match[1] . $rewritten . $match[1];
			},
			(string) $js
		);

		$output = (string) preg_replace( '/\s*\/\/# sourceURL=[^\r\n]*/i', '', $output );

		if ( $rewrote ) {
			$context_url = (string) $export_base;
			if ( '' === $context_url ) {
				$context_url = '(unknown)';
			}
			wpstatic_logger()->log_info( sprintf( 'Rewriter updated JS URLs in current file context. URL: %s', $context_url ) );
		}

		return $output;
	}

	/**
	 * Rewrite URLs in a queried HTML attribute.
	 *
	 * @param \DOMXPath $xpath       XPath object.
	 * @param string    $query       XPath query.
	 * @param string    $attribute   Attribute name.
	 * @param string    $mode        Rewrite mode.
	 * @param string    $origin      Site origin.
	 * @param string    $export_base Export page path context.
	 * @return void
	 */
	private function rewrite_attribute_query( $xpath, $query, $attribute, $mode, $origin, $export_base ) {
		$nodes = $xpath->query( $query );
		if ( false === $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			$value = $node->getAttribute( $attribute );
			if ( '' === $value ) {
				continue;
			}

			$node->setAttribute( $attribute, $this->rewrite_single_url( $value, $mode, $origin, $export_base ) );
		}
	}

	/**
	 * Whether an inline script type should be rewritten as JavaScript.
	 *
	 * @param string $type Script type attribute value.
	 * @return bool
	 */
	private function is_rewritable_inline_script_type( $type ) {
		if ( '' === $type ) {
			return true;
		}

		$allowed_types = array(
			'text/javascript',
			'application/javascript',
			'text/ecmascript',
			'application/ecmascript',
			'module',
		);

		return in_array( $type, $allowed_types, true );
	}

	/**
	 * Whether a JSON inline script node should have same-origin domains stripped.
	 *
	 * Currently limited to WordPress core emoji settings payload.
	 *
	 * @param \DOMElement $script_node Script node.
	 * @param string      $type        Script type attribute value.
	 * @return bool
	 */
	private function is_rewritable_json_script_node( $script_node, $type ) {
		if ( 'application/json' !== $type ) {
			return false;
		}

		$id = trim( (string) $script_node->getAttribute( 'id' ) );

		return 'wp-emoji-settings' === $id;
	}

	/**
	 * Rewrite srcset values entry by entry.
	 *
	 * @param string $srcset      Srcset string.
	 * @param string $mode        Rewrite mode.
	 * @param string $origin      Site origin.
	 * @param string $export_base Export page path context.
	 * @return string Rewritten srcset.
	 */
	private function rewrite_srcset( $srcset, $mode, $origin, $export_base ) {
		$parts = preg_split( '/\s*,\s*/', trim( (string) $srcset ) );
		if ( ! is_array( $parts ) ) {
			return (string) $srcset;
		}

		$out = array();
		foreach ( $parts as $part ) {
			$entry = preg_split( '/\s+/', trim( $part ), 2 );
			if ( empty( $entry[0] ) ) {
				continue;
			}

			$url  = $this->rewrite_single_url( $entry[0], $mode, $origin, $export_base );
			$desc = isset( $entry[1] ) ? ' ' . $entry[1] : '';
			$out[] = $url . $desc;
		}

		return implode( ', ', $out );
	}

	/**
	 * Rewrite a single URL value according to mode.
	 *
	 * @param string $url         URL to rewrite.
	 * @param string $mode        Rewrite mode.
	 * @param string $origin      Site origin.
	 * @param string $export_base Export page path context.
	 * @return string Rewritten URL.
	 */
	private function rewrite_single_url( $url, $mode, $origin, $export_base ) {
		$url = trim( (string) $url );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, '#' ) ) {
			return $url;
		}

		$normalized = $this->normalize_absolute_url( $url, $origin );
		if ( '' === $normalized ) {
			return $url;
		}

		if ( 'absolute' === $mode ) {
			return $this->to_absolute_mode( $normalized, $export_base );
		}

		if ( 'relative' === $mode ) {
			return $this->to_relative_mode( $normalized );
		}

		return $this->to_offline_mode( $normalized, $export_base );
	}

	/**
	 * Normalize URL to absolute same-origin URL when possible.
	 *
	 * @param string $url    URL.
	 * @param string $origin Site origin.
	 * @return string Absolute URL or empty string.
	 */
	private function normalize_absolute_url( $url, $origin ) {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $this->is_same_origin_url( $url, $origin ) ? $url : '';
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = wp_parse_url( $origin, PHP_URL_SCHEME );
			$fixed  = ( $scheme ? $scheme : 'https' ) . ':' . $url;
			return $this->is_same_origin_url( $fixed, $origin ) ? $fixed : '';
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return rtrim( $origin, '/' ) . $url;
		}

		return '';
	}

	/**
	 * Convert absolute URL into root-relative mode.
	 *
	 * @param string $absolute_url Absolute URL.
	 * @return string
	 */
	private function to_relative_mode( $absolute_url ) {
		$parts = wp_parse_url( $absolute_url );
		if ( ! is_array( $parts ) ) {
			return $absolute_url;
		}

		$path     = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		return $path . $query . $fragment;
	}

	/**
	 * Convert absolute URL into absolute mode with target base.
	 *
	 * @param string $absolute_url Absolute URL.
	 * @param string $export_base  Target base URL.
	 * @return string
	 */
	private function to_absolute_mode( $absolute_url, $export_base ) {
		$parts = wp_parse_url( $absolute_url );
		if ( ! is_array( $parts ) ) {
			return $absolute_url;
		}

		$path     = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		return rtrim( (string) $export_base, '/' ) . $path . $query . $fragment;
	}

	/**
	 * Convert absolute URL into filesystem-friendly relative mode.
	 *
	 * @param string $absolute_url Absolute URL.
	 * @param string $export_base  Current export page context.
	 * @return string
	 */
	private function to_offline_mode( $absolute_url, $export_base ) {
		$target = wp_parse_url( $absolute_url, PHP_URL_PATH );
		$query  = wp_parse_url( $absolute_url, PHP_URL_QUERY );
		$fragment = wp_parse_url( $absolute_url, PHP_URL_FRAGMENT );

		$current = (string) wp_parse_url( (string) $export_base, PHP_URL_PATH );
		$current = trim( $current, '/' );
		if ( '' !== $current && false !== strrpos( $current, '.' ) ) {
			$current = dirname( $current );
		}

		$depth = 0;
		if ( '' !== trim( $current, '/' ) ) {
			$depth = count( array_filter( explode( '/', trim( $current, '/' ) ) ) );
		}

		$prefix = str_repeat( '../', $depth );
		$path   = ltrim( (string) $target, '/' );
		if ( '' === $path ) {
			$url = '/';
		} else {
			$url = $prefix . $path;
		}
		if ( null !== $query && '' !== $query ) {
			$url .= '?' . $query;
		}
		if ( null !== $fragment && '' !== $fragment ) {
			$url .= '#' . $fragment;
		}

		return $url;
	}

	/**
	 * Strip same-origin domain prefixes from plain/escaped absolute URLs.
	 *
	 * Replaces:
	 * - https://example.com
	 * - http://example.com
	 * - https:\/\/example.com
	 * - http:\/\/example.com
	 *
	 * with empty string, so resulting paths become root-relative.
	 *
	 * @param string $content Content to rewrite.
	 * @param string $origin  Site origin.
	 * @return string
	 */
	private function strip_same_origin_domains( $content, $origin ) {
		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return (string) $content;
		}

		$host_port = strtolower( (string) $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$host_port .= ':' . (int) $parts['port'];
		}

		$needles = array(
			'https://' . $host_port,
			'http://' . $host_port,
			'https:\/\/' . $host_port,
			'http:\/\/' . $host_port,
		);

		return str_ireplace( $needles, '', (string) $content );
	}

	/**
	 * Check whether two URLs share the same origin host/port.
	 *
	 * @param string $url    URL to validate.
	 * @param string $origin Base origin URL.
	 * @return bool
	 */
	private function is_same_origin_url( $url, $origin ) {
		$url_parts    = wp_parse_url( $url );
		$origin_parts = wp_parse_url( $origin );

		if ( ! is_array( $url_parts ) || ! is_array( $origin_parts ) ) {
			return false;
		}

		if ( empty( $url_parts['host'] ) || empty( $origin_parts['host'] ) ) {
			return false;
		}

		$url_host    = strtolower( (string) $url_parts['host'] );
		$origin_host = strtolower( (string) $origin_parts['host'] );
		if ( $url_host !== $origin_host ) {
			return false;
		}

		$url_port    = isset( $url_parts['port'] ) ? (int) $url_parts['port'] : 0;
		$origin_port = isset( $origin_parts['port'] ) ? (int) $origin_parts['port'] : 0;

		return $url_port === $origin_port;
	}
}
