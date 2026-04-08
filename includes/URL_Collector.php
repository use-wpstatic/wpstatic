<?php
/**
 * URL Collector for WPStatic plugin.
 *
 * Discovers all public URLs on the WordPress site for static export.
 * Uses batched WP_Query to keep memory low.
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
 * Class URL_Collector
 *
 * Collects deduplicated absolute URLs from posts, pages, attachments,
 * public CPTs, taxonomy terms, sitemaps, robots.txt, and feeds.
 */
class URL_Collector {

	/**
	 * Option name where collected URLs are persisted.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wpstatic_collected_urls';

	/**
	 * Collected URLs keyed by URL for fast deduplication.
	 *
	 * @var array<string, true>
	 */
	private $url_map = array();

	/**
	 * URLs grouped by source label.
	 *
	 * @var array<string, string[]>
	 */
	private $by_source = array();

	/**
	 * Active export ID for the current collection run.
	 *
	 * @var int
	 */
	private $export_id = 0;

	/**
	 * Collect URLs from the site for static export.
	 *
	 * @param array $args {
	 *     Optional. Collection arguments.
	 *
	 *     @type string[] $post_types      Post type slugs to include. Empty = all public.
	 *     @type int      $batch           Posts per WP_Query batch. Default 50.
	 *     @type bool     $include_sitemap Whether to add sitemap index URL. Default true.
	 *     @type bool     $include_static_assets Whether to include plugin/theme static files. Default false.
	 * }
	 * @param int|null $export_id Optional export ID. When null, collector
	 *                            uses the latest export ID for backward compatibility.
	 * @return array{
	 *     urls: string[],
	 *     by_source: array<string, string[]>,
	 *     total: int,
	 *     last_collected: int
	 * }
	 */
	public function collect( array $args = array(), $export_id = null ) {
		$defaults = array(
			'post_types'      => array(),
			'batch'           => 50,
			'include_sitemap' => true,
			'include_static_assets' => false,
		);

		$args = wp_parse_args( $args, $defaults );
		$args['batch'] = max( 1, absint( $args['batch'] ) );
		$args['include_static_assets'] = ! empty( $args['include_static_assets'] );

		$this->url_map   = array();
		$this->by_source = array();
		$this->export_id = ( null !== $export_id ) ? (int) $export_id : $this->get_active_export_id();

		/**
		 * Filter whether collector should include plugin/theme static assets.
		 *
		 * @param bool  $include_static_assets Whether static asset directory scan is enabled.
		 * @param int   $export_id              Active export ID.
		 * @param array $args                   Collector args.
		 */
		$args['include_static_assets'] = (bool) apply_filters(
			'wpstatic_collect_include_static_assets',
			$args['include_static_assets'],
			$this->export_id,
			$args
		);

		$post_types = $this->resolve_post_types( $args['post_types'] );

		$this->collect_home_and_front();
		$this->collect_posts_batched( $post_types, $args['batch'] );
		$this->collect_public_terms( $post_types );
		$this->collect_sitemap_and_robots( $args['include_sitemap'] );
		$this->collect_feeds();
		if ( $this->export_id > 0 && ! empty( $args['include_static_assets'] ) ) {
			$this->collect_static_assets_from_detected_directories();
		}

		$urls = array_keys( $this->url_map );

		$result = array(
			'urls'           => $urls,
			'by_source'      => $this->by_source,
			'total'          => count( $urls ),
			'last_collected' => current_time( 'timestamp' ),
		);

		// Persist URL counts back to the export record when available.
		if ( $this->export_id > 0 ) {
			global $wpdb;
			$exports_table = wpstatic_table_name( 'exports' );

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$exports_table,
				array(
					'total_urls' => count( $urls ),
					'updated_at' => current_time( 'mysql' ),
				),
				array(
					'id' => $this->export_id,
				),
				array(
					'%d',
					'%s',
				),
				array(
					'%d',
				)
			);
		}

		return $result;
	}

	/**
	 * Get or create the active export ID.
	 *
	 * Uses the most recent export row when one exists, otherwise creates
	 * a new export with status "collecting".
	 *
	 * @return int Export ID or 0 on failure.
	 */
	private function get_active_export_id() {
		global $wpdb;

		$exports_table = wpstatic_table_name( 'exports' );

		// Prefer the most recent export.
		$export_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1', $exports_table )
		);
		if ( $export_id > 0 ) {
			return $export_id;
		}

		/*
		
		// only `WPStatic\Export_Job` with start() method can create a new export row.
		
		// Create a new export row when none exist.
		$export_key = current_time( 'Y-m-d_H-i-s' );
		$now        = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$exports_table,
			array(
				'export_key'   => $export_key,
				'status'       => 'collecting',
				'total_urls'   => 0,
				'fetched_urls' => 0,
				'settings'     => null,
				'log_file_path'=> null,
				'created_at'   => $now,
				'updated_at'   => $now,
				'completed_at' => null,
			),
			array(
				'%s',
				'%s',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
		*/
		return 0;
	}

	/**
	 * Resolve which post types to query.
	 *
	 * @param array $requested Post type slugs from args. Empty = all public.
	 * @return string[] Post type slugs.
	 */
	private function resolve_post_types( array $requested ) {
		if ( ! empty( $requested ) ) {
			return array_map( 'sanitize_key', $requested );
		}

		$public = get_post_types( array( 'public' => true ), 'names' );

		return array_values( $public );
	}

	/**
	 * Add home URL and front page URL (if different).
	 *
	 * @return void
	 */
	private function collect_home_and_front() {
		// Add front page with object_id first when it exists, so we persist object info when home === front.
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id > 0 && 'page' === get_option( 'show_on_front' ) ) {
			$front_url = get_permalink( $front_page_id );
			if ( $front_url ) {
				$this->add_url( $front_url, 'home', $front_page_id, 'page' );
			}
		}

		$home_url = home_url( '/' );
		$this->add_url( $home_url, 'home' );
	}

	/**
	 * Batch-query published posts for the given post types.
	 *
	 * Uses WP_Query with fields => 'ids' to minimize memory.
	 *
	 * @param string[] $post_types Post type slugs.
	 * @param int      $batch      Posts per page.
	 * @return void
	 */
	private function collect_posts_batched( array $post_types, $batch ) {
		if ( empty( $post_types ) ) {
			return;
		}

		foreach ( $post_types as $post_type ) {
			$this->batch_query_post_type( $post_type, $batch );
		}
	}

	/**
	 * Run paginated WP_Query for a single post type.
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $batch     Posts per page.
	 * @return void
	 */
	private function batch_query_post_type( $post_type, $batch ) {
		$source = $this->post_type_source_label( $post_type );
		$page   = 1;

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => ( 'attachment' === $post_type ) ? 'inherit' : 'publish',
			'posts_per_page' => $batch,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'paged'          => $page,
		);

		do {
			$query_args['paged'] = $page;
			$query = new \WP_Query( $query_args );

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $post_id ) {
				$url = ( 'attachment' === $post_type )
					? wp_get_attachment_url( $post_id )
					: get_permalink( $post_id );

				if ( $url ) {
					$this->add_url( $url, $source, $post_id, $post_type );
				}
			}

			$fetched = count( $query->posts );
			wp_reset_postdata();
			++$page;
		} while ( $fetched >= $batch );
	}

	/**
	 * Map a post type slug to a human-friendly source label.
	 *
	 * @param string $post_type Post type slug.
	 * @return string Source label.
	 */
	private function post_type_source_label( $post_type ) {
		$map = array(
			'post'       => 'post',
			'page'       => 'page',
			'attachment' => 'attachment',
		);

		return isset( $map[ $post_type ] ) ? $map[ $post_type ] : 'cpt_' . $post_type;
	}

	/**
	 * Collect public taxonomy term archive URLs.
	 *
	 * Only includes terms that belong to at least one of the collected
	 * post types to keep the URL list relevant.
	 *
	 * @param string[] $post_types Post types being collected.
	 * @return void
	 */
	private function collect_public_terms( array $post_types ) {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $this->taxonomy_applies( $taxonomy, $post_types ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => true,
					'fields'     => 'all',
				)
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$this->add_url( $link, 'term', $term->term_id, 'term' );
				}
			}
		}
	}

	/**
	 * Check whether a taxonomy is associated with any of the collected post types.
	 *
	 * @param \WP_Taxonomy $taxonomy  Taxonomy object.
	 * @param string[]     $post_types Post type slugs.
	 * @return bool
	 */
	private function taxonomy_applies( $taxonomy, array $post_types ) {
		return ! empty( array_intersect( $taxonomy->object_type, $post_types ) );
	}

	/**
	 * Add sitemap index and robots.txt URLs.
	 *
	 * @param bool $include_sitemap Whether to include the sitemap index.
	 * @return void
	 */
	private function collect_sitemap_and_robots( $include_sitemap ) {
		if ( $include_sitemap && $this->is_sitemap_enabled() ) {
			$this->add_url( home_url( '/wp-sitemap.xml' ), 'sitemap' );
		}

		$this->add_url( home_url( '/robots.txt' ), 'robots' );
	}

	/**
	 * Whether WordPress core sitemaps are enabled (WP 5.5+).
	 *
	 * @return bool
	 */
	private function is_sitemap_enabled() {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return false;
		}

		$server = wp_sitemaps_get_server();

		return $server instanceof \WP_Sitemaps && $server->sitemaps_enabled();
	}

	/**
	 * Add main feed and comments feed URLs.
	 *
	 * @return void
	 */
	private function collect_feeds() {
		$this->add_url( home_url( '/feed/' ), 'feed' );

		$comments_feed = home_url( '/comments/feed/' );
		$this->add_url( $comments_feed, 'feed' );
	}

	/**
	 * Add a URL to the deduplicated collection.
	 *
	 * @param string   $url         Absolute URL.
	 * @param string   $source      Source label for by_source grouping.
	 * @param int|null $object_id   Optional. Associated WordPress object ID (post_id, term_id).
	 * @param string|null $object_type Optional. Type of object: post, page, attachment, term.
	 * @param string $source_path Optional absolute source path for file-policy checks.
	 * @return void
	 */
	private function add_url( $url, $source, $object_id = null, $object_type = null, $source_path = '' ) {
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return;
		}

		$url = $this->normalize_url( $url );
		$source_path = (string) $source_path;
		if ( '' === $source_path && function_exists( 'wpstatic_resolve_local_source_path_for_url' ) ) {
			$source_path = wpstatic_resolve_local_source_path_for_url( $url );
		}

		if ( function_exists( 'wpstatic_should_skip_discovered_url' ) ) {
			if ( wpstatic_should_skip_discovered_url( $url, $source_path ) ) {
				return;
			}
		} else {
			if ( function_exists( 'wpstatic_should_skip_root_query_url' ) && wpstatic_should_skip_root_query_url( $url ) ) {
				return;
			}
			if ( function_exists( 'wpstatic_url_has_path_traversal' ) && wpstatic_url_has_path_traversal( $url ) ) {
				return;
			}
		}

		if ( function_exists( 'wpstatic_is_exportable_url' ) && ! wpstatic_is_exportable_url( $url, $source_path ) ) {
			return;
		}

		if ( ! isset( $this->by_source[ $source ] ) ) {
			$this->by_source[ $source ] = array();
		}

		if ( ! in_array( $url, $this->by_source[ $source ], true ) ) {
			$this->by_source[ $source ][] = $url;
		}

		$obj_id   = ( null !== $object_id && $object_id > 0 ) ? (int) $object_id : null;
		$obj_type = ( null !== $object_type && '' !== $object_type ) ? sanitize_key( $object_type ) : null;

		// Do not insert duplicates into url_map or the database.
		if ( isset( $this->url_map[ $url ] ) ) {
			// Backfill object_id/object_type when existing row has none and we have object info.
			if ( $this->export_id > 0 && null !== $obj_id && null !== $obj_type ) {
				global $wpdb;
				$urls_table = wpstatic_table_name( 'urls' );
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->prepare(
						'UPDATE %i SET object_id = %d, object_type = %s WHERE export_id = %d AND url = %s AND object_id IS NULL',
						$urls_table,
						$obj_id,
						$obj_type,
						$this->export_id,
						$url
					)
				);
			}
			return;
		}

		$this->url_map[ $url ] = true;

		// Persist to wpstatic_urls when an active export is available.
		if ( $this->export_id > 0 ) {
			global $wpdb;

			$urls_table     = wpstatic_table_name( 'urls' );
			$discovery_type = $this->map_discovery_type( $source );

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$urls_table,
				array(
					'export_id'      => $this->export_id,
					'url'            => $url,
					'discovery_type' => $discovery_type,
					'object_id'      => $obj_id,
					'object_type'    => $obj_type,
					'status'         => 'pending',
					'fetch_attempts' => 0,
					'created_at'     => current_time( 'mysql' ),
					'fetched_at'     => null,
				),
				array(
					'%d',
					'%s',
					'%s',
					'%d',
					'%s',
					'%s',
					'%d',
					'%s',
					'%s',
				)
			);
		}
	}

	/**
	 * Map a source label to a discovery type string for persistence.
	 *
	 * @param string $source Source label (home, post, page, attachment, term, sitemap, robots, feed, cpt_*).
	 * @return string Discovery type for wpstatic_urls.discover
	 */
	private function map_discovery_type( $source ) {
		switch ( $source ) {
			case 'crawl':
				return 'crawl';
			case 'home':
				return 'seed_home';
			case 'post':
				return 'seed_post';
			case 'page':
				return 'seed_page';
			case 'attachment':
				return 'seed_attachment';
			case 'term':
				return 'seed_term';
			case 'sitemap':
				return 'seed_sitemap';
			case 'robots':
				return 'seed_robots';
			case 'feed':
				return 'seed_feed';
			default:
				// cpt_* and any future sources.
				return 'seed_other';
		}
	}

	/**
	 * Normalize a URL for consistent deduplication.
	 *
	 * Delegates to the shared storage normalization helper.
	 * Keeps query strings, strips fragments, and normalizes non-file paths.
	 *
	 * @param string $url Absolute URL.
	 * @return string Normalized URL.
	 */
	private function normalize_url( $url ) {
		if ( function_exists( 'wpstatic_normalize_url_for_storage' ) ) {
			return wpstatic_normalize_url_for_storage( $url );
		}

		return $url;
	}

	/**
	 * Collect static files from active plugin and theme directories.
	 *
	 * @return void
	 */
	private function collect_static_assets_from_detected_directories() {
		$directories = $this->detect_static_asset_directories();
		$extensions  = $this->get_static_asset_extensions();

		if ( empty( $directories ) || empty( $extensions ) ) {
			return;
		}

		$extension_map = array_fill_keys( $extensions, true );

		foreach ( $directories as $directory ) {
			$this->collect_static_assets_from_directory( $directory, $extension_map );
		}
	}

	/**
	 * Detect plugin/theme directories to scan for static assets.
	 *
	 * @return string[] Absolute directory paths.
	 */
	private function detect_static_asset_directories() {
		$directories = array();

		$active_plugins = get_option( 'active_plugins', array() );
		if ( ! is_array( $active_plugins ) ) {
			$active_plugins = array();
		}

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_plugins ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
			}
		}

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			foreach ( $active_plugins as $plugin_basename ) {
				$plugin_basename = (string) $plugin_basename;
				if ( '' === $plugin_basename || false !== strpos( $plugin_basename, '..' ) ) {
					continue;
				}

				$plugin_root = strtok( $plugin_basename, '/' );
				if ( false === $plugin_root || '' === $plugin_root ) {
					continue;
				}

				$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_root );
				if ( is_dir( $plugin_dir ) ) {
					$directories[] = $plugin_dir;
				}
			}
		}

		$stylesheet_dir = wp_normalize_path( get_stylesheet_directory() );
		if ( '' !== $stylesheet_dir && is_dir( $stylesheet_dir ) ) {
			$directories[] = $stylesheet_dir;
		}

		$template_dir = wp_normalize_path( get_template_directory() );
		if ( '' !== $template_dir && is_dir( $template_dir ) ) {
			$directories[] = $template_dir;
		}

		$directories = array_values( array_unique( $directories ) );

		/**
		 * Filter static asset directories scanned during collection.
		 *
		 * @param string[] $directories Absolute directory paths.
		 * @param int      $export_id   Export ID.
		 */
		$directories = apply_filters( 'wpstatic_collect_static_asset_directories', $directories, $this->export_id );

		if ( ! is_array( $directories ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $directories as $directory ) {
			if ( ! is_string( $directory ) || '' === $directory ) {
				continue;
			}

			$directory = wp_normalize_path( $directory );
			if ( is_dir( $directory ) ) {
				$sanitized[] = $directory;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Get allowed static asset file extensions.
	 *
	 * @return string[] Lowercase extensions without dots.
	 */
	private function get_static_asset_extensions() {
		$extensions = function_exists( 'wpstatic_get_exportable_static_extensions' )
			? wpstatic_get_exportable_static_extensions()
			: array();

		/**
		 * Filter static asset file extensions scanned during collection.
		 *
		 * @param string[] $extensions Lowercase extensions without dots.
		 * @param int      $export_id  Export ID.
		 */
		$extensions = apply_filters( 'wpstatic_collect_static_asset_extensions', $extensions, $this->export_id );
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
	 * Scan one directory and enqueue matching files as crawl-discovered URLs.
	 *
	 * @param string            $directory     Absolute directory path.
	 * @param array<string,bool> $extension_map Extension lookup map.
	 * @return void
	 */
	private function collect_static_assets_from_directory( $directory, array $extension_map ) {
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
			);
		} catch ( \UnexpectedValueException $exception ) {
			return;
		}

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() ) {
				continue;
			}

			$path = wp_normalize_path( $file_info->getPathname() );
			$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( '' === $ext || ! isset( $extension_map[ $ext ] ) ) {
				continue;
			}

			$url = $this->content_path_to_url( $path );
			if ( '' !== $url ) {
				$this->add_url( $url, 'crawl', null, null, $path );
			}
		}
	}

	/**
	 * Convert a wp-content file path into a public site URL.
	 *
	 * @param string $path Absolute file path.
	 * @return string Absolute URL or empty string.
	 */
	private function content_path_to_url( $path ) {
		$path = wp_normalize_path( (string) $path );
		$url  = '';

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$content_dir = wp_normalize_path( WP_CONTENT_DIR );
			$prefix      = trailingslashit( $content_dir );

			if ( 0 === strpos( $path, $prefix ) ) {
				$relative = ltrim( substr( $path, strlen( $prefix ) ), '/' );
				$url      = content_url( $relative );
			}
		}

		/**
		 * Filter computed static asset URL from an absolute path.
		 *
		 * @param string $url       Absolute URL or empty string.
		 * @param string $path      Absolute file path.
		 * @param int    $export_id Export ID.
		 */
		$url = apply_filters( 'wpstatic_collect_static_asset_url', $url, $path, $this->export_id );

		return is_string( $url ) ? $url : '';
	}
}
