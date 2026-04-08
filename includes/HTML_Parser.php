<?php
/**
 * HTML Parser & Asset Extractor for WPStatic plugin.
 *
 * Parses HTML content using DOMDocument/DOMXPath to extract all referenced
 * asset URLs (links, images, scripts, stylesheets, srcset, inline CSS urls,
 * media, and more). Normalizes extracted URLs to absolute form. Persists
 * newly discovered URLs into the wpstatic_urls and wpstatic_url_references
 * tables.
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
 * Class HTML_Parser
 *
 * Extracts asset URLs from HTML content and optionally persists newly
 * discovered URLs into the database for the static export pipeline.
 */
class HTML_Parser {

	/**
	 * Map of asset categories to wpstatic_url_references.reference_type values.
	 *
	 * @var array<string, string>
	 */
	const CATEGORY_REFERENCE_MAP = array(
		'links'       => 'hyperlink',
		'images'      => 'image',
		'scripts'     => 'script',
		'stylesheets' => 'stylesheet',
		'srcset'      => 'image',
		'inline_urls' => 'other',
		'media'       => 'other',
		'other'       => 'other',
	);

	/**
	 * URL prefixes that indicate non-fetchable resources.
	 *
	 * @var string[]
	 */
	private static $skip_prefixes = array( 'data:', 'javascript:', 'mailto:', 'tel:', '#', '{' );

	/*
	|--------------------------------------------------------------------------
	| Public API
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract all asset URLs from HTML content.
	 *
	 * Parses the provided HTML with DOMDocument and DOMXPath, extracting
	 * every referenced URL grouped by asset category. All URLs are
	 * normalized to absolute form using the given base URL.
	 *
	 * @param string $html     Raw HTML string to parse.
	 * @param string $base_url Absolute URL of the page (used to resolve relative references).
	 * @return array<string, string[]> {
	 *     Categorized arrays of deduplicated absolute URLs.
	 *
	 *     @type string[] $links       Anchor tag hrefs.
	 *     @type string[] $images      Image sources (img src, video poster, input type=image).
	 *     @type string[] $scripts     Script tag sources.
	 *     @type string[] $stylesheets Stylesheet link hrefs.
	 *     @type string[] $srcset      URLs parsed from srcset attributes.
	 *     @type string[] $inline_urls URLs from CSS url() in style attributes and style tags.
	 *     @type string[] $media       Media element sources (video, audio, source, embed, track, object).
	 *     @type string[] $other       Favicons, preloads, manifests, meta OG/Twitter images, etc.
	 * }
	 */
	public function extract_assets( $html, $base_url ) {
		$result = $this->empty_result();

		if ( empty( trim( $html ) ) ) {
			return $result;
		}

		$dom   = $this->create_dom_document( $html );
		$xpath = new \DOMXPath( $dom );

		$base_url = $this->resolve_base_href( $xpath, $base_url );

		$this->extract_anchor_links( $xpath, $base_url, $result );
		$this->extract_image_sources( $xpath, $base_url, $result );
		$this->extract_script_sources( $xpath, $base_url, $result );
		$this->extract_stylesheet_links( $xpath, $base_url, $result );
		$this->extract_srcset_urls( $xpath, $base_url, $result );
		$this->extract_media_sources( $xpath, $base_url, $result );
		$this->extract_misc_link_urls( $xpath, $base_url, $result );
		$this->extract_meta_content_urls( $xpath, $base_url, $result );
		$this->extract_inline_style_urls( $xpath, $base_url, $result );
		$this->extract_style_block_urls( $dom, $base_url, $result );

		foreach ( $result as $key => $urls ) {
			$result[ $key ] = array_values( array_unique( $urls ) );
		}

		return $result;
	}

	/**
	 * Persist discovered URLs into the database.
	 *
	 * For each same-origin URL in the extracted arrays, checks whether it
	 * already exists in the wpstatic_urls table for the given export. Inserts
	 * new URLs with discovery_type 'crawl'. Always records a source reference
	 * in wpstatic_url_references regardless of whether the URL is new.
	 *
	 * @param array $extracted     Output from extract_assets().
	 * @param int   $export_id     Export session ID (wpstatic_exports.id).
	 * @param int   $source_url_id Row ID in wpstatic_urls for the page that was parsed.
	 * @return array{new_urls: int, existing_urls: int, references_added: int, skipped_external: int}
	 */
	public function persist_discovered_urls( $extracted, $export_id, $source_url_id ) {
		$site_origin = $this->get_site_origin();

		$stats = array(
			'new_urls'         => 0,
			'existing_urls'    => 0,
			'references_added' => 0,
			'skipped_external' => 0,
		);

		foreach ( self::CATEGORY_REFERENCE_MAP as $category => $reference_type ) {
			if ( empty( $extracted[ $category ] ) ) {
				continue;
			}

			foreach ( $extracted[ $category ] as $url ) {
				$this->persist_single_url(
					$url,
					$export_id,
					$source_url_id,
					$reference_type,
					$site_origin,
					$stats
				);
			}
		}

		return $stats;
	}

	/*
	|--------------------------------------------------------------------------
	| DOM extraction — anchor tags
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract URLs from anchor tags.
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $base_url Base URL for normalization.
	 * @param array     $result   Result array (passed by reference).
	 * @return void
	 */
	private function extract_anchor_links( $xpath, $base_url, &$result ) {
		$nodes = $xpath->query( '//a[@href]' );
		if ( false === $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			$url = $this->normalize_to_absolute( $node->getAttribute( 'href' ), $base_url );
			if ( null !== $url ) {
				$result['links'][] = $url;
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| DOM extraction — images
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract URLs from image-related elements.
	 *
	 * Covers img[src], input[type=image][src], and video[poster].
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $base_url Base URL for normalization.
	 * @param array     $result   Result array (passed by reference).
	 * @return void
	 */
	private function extract_image_sources( $xpath, $base_url, &$result ) {
		$queries = array(
			'//img[@src]'                  => 'src',
			'//input[@type="image"][@src]' => 'src',
			'//video[@poster]'             => 'poster',
		);

		$this->extract_from_queries( $xpath, $queries, $base_url, $result, 'images' );
	}

	/*
	|--------------------------------------------------------------------------
	| DOM extraction — scripts
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract URLs from script tags with a src attribute.
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $base_url Base URL for normalization.
	 * @param array     $result   Result array (passed by reference).
	 * @return void
	 */
	private function extract_script_sources( $xpath, $base_url, &$result ) {
		$nodes = $xpath->query( '//script[@src]' );
		if ( false === $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			$url = $this->normalize_to_absolute( $node->getAttribute( 'src' ), $base_url );
			if ( null !== $url ) {
				$result['scripts'][] = $url;
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| DOM extraction — stylesheets
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract URLs from stylesheet link tags.
	 *
	 * Uses XPath word-matching to handle multi-value rel attributes
	 * such as "alternate stylesheet".
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $base_url Base URL for normalization.
	 * @param array     $result   Result array (passed by reference).
	 * @return void
	 */
	private function extract_stylesheet_links( $xpath, $base_url, &$result ) {
		$query = '//link[contains(concat(" ", normalize-space(@rel), " "), " stylesheet ")][@href]';
		$nodes = $xpath->query( $query );

		if ( false === $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			$url = $this->normalize_to_absolute( $node->getAttribute( 'href' ), $base_url );
			if ( null !== $url ) {
				$result['stylesheets'][] = $url;
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| DOM extraction — srcset
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract URLs from srcset attributes on any element (img, source).
	 *
	 * Parses the comma-separated srcset format, extracting just the URL
	 * portion (before the width/density descriptor).
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $base_url Base URL for normalization.
	 * @param array     $result   Result array (passed by reference).
	 * @return void
	 */
	private function extract_srcset_urls( $xpath, $base_url, &$result ) {
		$nodes = $xpath->query( '//*[@srcset]' );
		if ( false === $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			$parsed = $this->parse_srcset( $node->getAttribute( 'srcset' ), $base_url );
			foreach ( $parsed as $url ) {
				$result['srcset'][] = $url;
			}
		}
	}

	/**
	 * Parse a srcset attribute value into an array of absolute URLs.
	 *
	 * @param string $srcset   Raw srcset attribute value.
	 * @param string $base_url Base URL for normalization.
	 * @return string[] Absolute URLs.
	 */
	private function parse_srcset( $srcset, $base_url ) {
		$urls    = array();
		$entries = preg_split( '/\s*,\s*/', trim( $srcset ) );

		if ( ! is_array( $entries ) ) {
			return $urls;
		}

		foreach ( $entries as $entry ) {
			$parts = preg_split( '/\s+/', trim( $entry ) );
			if ( empty( $parts[0] ) ) {
				continue;
			}

			$url = $this->normalize_to_absolute( $parts[0], $base_url );
			if ( null !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/*
	|--------------------------------------------------------------------------
	| DOM extraction — media elements
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract URLs from media elements.
	 *
	 * Covers video[src], audio[src], source[src], embed[src],
	 * track[src], and object[data].
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $base_url Base URL for normalization.
	 * @param array     $result   Result array (passed by reference).
	 * @return void
	 */
	private function extract_media_sources( $xpath, $base_url, &$result ) {
		$queries = array(
			'//video[@src]'   => 'src',
			'//audio[@src]'   => 'src',
			'//source[@src]'  => 'src',
			'//embed[@src]'   => 'src',
			'//track[@src]'   => 'src',
			'//object[@data]' => 'data',
		);

		$this->extract_from_queries( $xpath, $queries, $base_url, $result, 'media' );
	}

	/*
	|--------------------------------------------------------------------------
	| DOM extraction — miscellaneous link elements
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract URLs from non-stylesheet link elements.
	 *
	 * Covers favicons, preloads, prefetches, manifests, and related
	 * link[rel] types not handled by extract_stylesheet_links().
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $base_url Base URL for normalization.
	 * @param array     $result   Result array (passed by reference).
	 * @return void
	 */
	private function extract_misc_link_urls( $xpath, $base_url, &$result ) {
		$rel_values = array(
			'icon',
			'shortcut icon',
			'apple-touch-icon',
			'apple-touch-icon-precomposed',
			'preload',
			'prefetch',
			'modulepreload',
			'manifest',
		);

		foreach ( $rel_values as $rel ) {
			$query = sprintf(
				'//link[@rel="%s"][@href]',
				$rel
			);

			$nodes = $xpath->query( $query );
			if ( false === $nodes ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				$url = $this->normalize_to_absolute( $node->getAttribute( 'href' ), $base_url );
				if ( null !== $url ) {
					$result['other'][] = $url;
				}
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| DOM extraction — meta tags with URL content
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract URLs from Open Graph and Twitter Card meta tags.
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $base_url Base URL for normalization.
	 * @param array     $result   Result array (passed by reference).
	 * @return void
	 */
	private function extract_meta_content_urls( $xpath, $base_url, &$result ) {
		$properties = array( 'og:image', 'og:video', 'og:audio', 'og:url', 'twitter:image' );

		foreach ( $properties as $prop ) {
			$query = sprintf(
				'//meta[@property="%1$s"][@content] | //meta[@name="%1$s"][@content]',
				$prop
			);

			$nodes = $xpath->query( $query );
			if ( false === $nodes ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				$url = $this->normalize_to_absolute( $node->getAttribute( 'content' ), $base_url );
				if ( null !== $url ) {
					$result['other'][] = $url;
				}
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| DOM extraction — inline CSS url()
	|--------------------------------------------------------------------------
	*/

	/**
	 * Extract URLs from inline style attributes via CSS url() regex.
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $base_url Base URL for normalization.
	 * @param array     $result   Result array (passed by reference).
	 * @return void
	 */
	private function extract_inline_style_urls( $xpath, $base_url, &$result ) {
		$nodes = $xpath->query( '//*[@style]' );
		if ( false === $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			$urls = $this->parse_css_urls( $node->getAttribute( 'style' ), $base_url );
			foreach ( $urls as $url ) {
				$result['inline_urls'][] = $url;
			}
		}
	}

	/**
	 * Extract URLs from style tag contents via CSS url() regex.
	 *
	 * @param \DOMDocument $dom      DOM document instance.
	 * @param string       $base_url Base URL for normalization.
	 * @param array        $result   Result array (passed by reference).
	 * @return void
	 */
	private function extract_style_block_urls( $dom, $base_url, &$result ) {
		$tags = $dom->getElementsByTagName( 'style' );

		foreach ( $tags as $tag ) {
			$urls = $this->parse_css_urls( $tag->textContent, $base_url );
			foreach ( $urls as $url ) {
				$result['inline_urls'][] = $url;
			}
		}
	}

	/**
	 * Parse CSS content for url() references and return absolute URLs.
	 *
	 * Skips data: URIs and fragment-only references.
	 *
	 * @param string $css      CSS content to scan.
	 * @param string $base_url Base URL for resolving relative references.
	 * @return string[] Absolute URLs found in url() references.
	 */
	private function parse_css_urls( $css, $base_url ) {
		$parser = new CSS_Parser();
		return $parser->extract_urls( $css, $base_url );
	}

	/*
	|--------------------------------------------------------------------------
	| URL normalization
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalize a URL to absolute form.
	 *
	 * Handles protocol-relative (//), root-relative (/path), and
	 * path-relative (path, ../path) URLs. Returns null for non-fetchable
	 * URLs such as data:, javascript:, mailto:, tel:, and fragment-only.
	 *
	 * @param string $url      Raw URL from an HTML attribute or CSS value.
	 * @param string $base_url Absolute URL for resolving relative references.
	 * @return string|null Absolute URL, or null if the URL should be skipped.
	 */
	private function normalize_to_absolute( $url, $base_url ) {
		$url = trim( $url );

		if ( $this->should_skip_url( $url ) ) {
			return null;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = wp_parse_url( $base_url, PHP_URL_SCHEME );
			$absolute = ( $scheme ? $scheme : 'https' ) . ':' . $url;
			return $this->should_skip_absolute_url( $absolute ) ? null : $absolute;
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return $this->should_skip_absolute_url( $url ) ? null : $url;
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
			$absolute = $origin . $url;
			return $this->should_skip_absolute_url( $absolute ) ? null : $absolute;
		}

		$base_path  = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		$last_slash = strrpos( $base_path, '/' );
		$base_dir   = ( false !== $last_slash ) ? substr( $base_path, 0, $last_slash ) : '';
		$resolved   = $this->resolve_dot_segments( $base_dir . '/' . $url );

		$absolute = $origin . $resolved;
		return $this->should_skip_absolute_url( $absolute ) ? null : $absolute;
	}

	/**
	 * Check whether an absolute URL should be skipped by policy.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private function should_skip_absolute_url( $url ) {
		return function_exists( 'wpstatic_should_skip_root_query_url' ) && wpstatic_should_skip_root_query_url( $url );
	}

	/**
	 * Check whether a URL should be skipped (non-fetchable).
	 *
	 * @param string $url URL to check.
	 * @return bool True if the URL should be skipped.
	 */
	private function should_skip_url( $url ) {
		if ( empty( $url ) ) {
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
	 * Resolve dot segments (. and ..) in a URL path per RFC 3986.
	 *
	 * @param string $path URL path with potential dot segments.
	 * @return string Resolved path starting with /.
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

	/*
	|--------------------------------------------------------------------------
	| DOM helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Create a DOMDocument from HTML content with UTF-8 encoding.
	 *
	 * @param string $html Raw HTML content.
	 * @return \DOMDocument Parsed DOM document.
	 */
	private function create_dom_document( $html ) {
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$dom->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		foreach ( $dom->childNodes as $node ) {
			if ( XML_PI_NODE === $node->nodeType ) {
				$dom->removeChild( $node );
				break;
			}
		}

		return $dom;
	}

	/**
	 * Resolve a base href tag if present in the document.
	 *
	 * If the HTML contains a <base href="..."> tag, that value overrides
	 * the provided base URL for all relative URL resolution.
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param string    $base_url Default base URL.
	 * @return string Effective base URL.
	 */
	private function resolve_base_href( $xpath, $base_url ) {
		$nodes = $xpath->query( '//base[@href]' );
		if ( false === $nodes || 0 === $nodes->length ) {
			return $base_url;
		}

		$href = trim( $nodes->item( 0 )->getAttribute( 'href' ) );
		if ( empty( $href ) ) {
			return $base_url;
		}

		$resolved = $this->normalize_to_absolute( $href, $base_url );

		return null !== $resolved ? $resolved : $base_url;
	}

	/**
	 * Run multiple XPath queries and collect normalized URLs into a category.
	 *
	 * @param \DOMXPath $xpath    XPath instance.
	 * @param array     $queries  Map of XPath query => attribute name.
	 * @param string    $base_url Base URL for normalization.
	 * @param array     $result   Result array (passed by reference).
	 * @param string    $category Result category key.
	 * @return void
	 */
	private function extract_from_queries( $xpath, $queries, $base_url, &$result, $category ) {
		foreach ( $queries as $query => $attr ) {
			$nodes = $xpath->query( $query );
			if ( false === $nodes ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				$url = $this->normalize_to_absolute( $node->getAttribute( $attr ), $base_url );
				if ( null !== $url ) {
					$result[ $category ][] = $url;
				}
			}
		}
	}

	/**
	 * Return the empty result structure for extract_assets().
	 *
	 * @return array<string, array>
	 */
	private function empty_result() {
		return array(
			'links'       => array(),
			'images'      => array(),
			'scripts'     => array(),
			'stylesheets' => array(),
			'srcset'      => array(),
			'inline_urls' => array(),
			'media'       => array(),
			'other'       => array(),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Database persistence
	|--------------------------------------------------------------------------
	*/

	/**
	 * Process a single URL: insert into wpstatic_urls if new, always
	 * record a reference in wpstatic_url_references.
	 *
	 * External (different-origin) URLs are skipped entirely.
	 *
	 * @param string $url            Absolute URL.
	 * @param int    $export_id      Export session ID.
	 * @param int    $source_url_id  Source URL row ID in wpstatic_urls.
	 * @param string $reference_type Reference type (hyperlink, image, etc.).
	 * @param string $site_origin    Site origin for same-origin filtering.
	 * @param array  $stats          Stats counters (passed by reference).
	 * @return void
	 */
	private function persist_single_url( $url, $export_id, $source_url_id, $reference_type, $site_origin, &$stats ) {
		if ( ! $this->is_same_origin( $url, $site_origin ) ) {
			++$stats['skipped_external'];
			return;
		}

		$url_id = $this->get_or_insert_url_id( $url, $export_id, $stats );
		if ( 0 === $url_id ) {
			return;
		}

		$this->insert_reference( $export_id, $url_id, $source_url_id, $reference_type, $stats );
	}

	/**
	 * Look up or insert a URL row in wpstatic_urls.
	 *
	 * @param string $url       Absolute URL.
	 * @param int    $export_id Export session ID.
	 * @param array  $stats     Stats counters (passed by reference).
	 * @return int Row ID in wpstatic_urls, or 0 on failure.
	 */
	private function get_or_insert_url_id( $url, $export_id, &$stats ) {
		global $wpdb;

		$urls_table = wpstatic_table_name( 'urls' );
		if ( function_exists( 'wpstatic_normalize_url_for_storage' ) ) {
			$url = wpstatic_normalize_url_for_storage( $url );
		}
		if ( function_exists( 'wpstatic_should_skip_discovered_url' ) && wpstatic_should_skip_discovered_url( $url ) ) {
			return 0;
		}
		if ( $this->should_skip_absolute_url( $url ) ) {
			return 0;
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
			++$stats['existing_urls'];
			return $existing_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
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

		if ( false === $inserted ) {
			return 0;
		}

		++$stats['new_urls'];
		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a reference row into wpstatic_url_references.
	 *
	 * Avoids inserting duplicate references for the same combination
	 * of export_id, url_id, source_url_id, and reference_type.
	 *
	 * @param int    $export_id      Export session ID.
	 * @param int    $url_id         Referenced URL row ID.
	 * @param int    $source_url_id  Source page URL row ID.
	 * @param string $reference_type Reference type string.
	 * @param array  $stats          Stats counters (passed by reference).
	 * @return void
	 */
	private function insert_reference( $export_id, $url_id, $source_url_id, $reference_type, &$stats ) {
		global $wpdb;

		$refs_table = wpstatic_table_name( 'url_references' );

		$exists = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT id FROM %i WHERE export_id = %d AND url_id = %d AND source_url_id = %d AND reference_type = %s LIMIT 1',
				$refs_table,
				$export_id,
				$url_id,
				$source_url_id,
				$reference_type
			)
		);

		if ( $exists > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$refs_table,
			array(
				'export_id'      => $export_id,
				'url_id'         => $url_id,
				'source_url_id'  => $source_url_id,
				'reference_type' => $reference_type,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);

		if ( false !== $inserted ) {
			++$stats['references_added'];
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Origin helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get the origin (scheme://host[:port]) of the WordPress site.
	 *
	 * @return string Site origin, e.g. "https://example.com".
	 */
	private function get_site_origin() {
		$parsed = wp_parse_url( home_url() );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return '';
		}

		$origin = $parsed['scheme'] . '://' . $parsed['host'];
		if ( ! empty( $parsed['port'] ) ) {
			$origin .= ':' . $parsed['port'];
		}

		return $origin;
	}

	/**
	 * Check whether a URL shares the same origin as the site.
	 *
	 * @param string $url         Absolute URL to check.
	 * @param string $site_origin Origin string from get_site_origin().
	 * @return bool True if the URL is same-origin.
	 */
	private function is_same_origin( $url, $site_origin ) {
		if ( '' === $site_origin ) {
			return true;
		}

		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return true;
		}

		if ( empty( $parsed['scheme'] ) ) {
			return false;
		}

		$url_origin = $parsed['scheme'] . '://' . $parsed['host'];
		if ( ! empty( $parsed['port'] ) ) {
			$url_origin .= ':' . $parsed['port'];
		}

		return strtolower( $url_origin ) === strtolower( $site_origin );
	}
}
