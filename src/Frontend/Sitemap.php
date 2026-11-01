<?php
/**
 * Multilingual XML sitemap with hreflang alternates.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Settings;
use TranslateRocket\Slugs;
use TranslateRocket\NoTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Serves /translaterocket-sitemap.xml: one <url> per page AND per public language
 * (Google's own example), each with the full set of <xhtml:link> hreflang
 * alternates (itself included) + x-default. Before 1.8.30 only the source address
 * was a <loc> and Search Console counted one page per page, the translations
 * hidden in the alternates (villamediterraneoholiday.com: 12 found for 96).
 * Over the sitemap limits (50,000 URLs / 50 MB per file) it becomes an index of
 * /translaterocket-sitemap-1.xml, -2.xml… Plugin-independent, so it works
 * alongside any SEO plugin's own sitemap.
 */
class Sitemap {

	const QV  = 'trrocket_sitemap';
	const MAX = 2000;
	/** Pages (not URLs) listed at most, over all the files. */
	const MAX_PAGES = 50000;
	/** URLs per file: under Google's 50,000. */
	const FILE_URLS = 45000;
	/** Bytes per file: under Google's 50 MB, estimated with ALT_BYTES per link. */
	const FILE_BYTES = 40000000;
	/** Generous size of one <xhtml:link> (long, percent-encoded translated slugs). */
	const ALT_BYTES = 400;

	/** @var int[]|null Page ids, worked out once per request. */
	private static $ids = null;

	/**
	 * Hook into WordPress.
	 */
	public function boot(): void {
		if ( empty( Settings::get()['target_languages'] ) ) {
			return; // No translations: nothing multilingual to list.
		}
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_output' ), 0 );
		add_filter( 'robots_txt', array( $this, 'robots' ), 10, 1 );
		// If Rank Math or Yoast is active, also list our sitemap in their sitemap
		// index, so it's discoverable from the one index users submit to Google.
		add_filter( 'rank_math/sitemap/index', array( $this, 'index_entry' ) );
		// WordPress' own wp-sitemap.xml (sites without an SEO plugin): the translated addresses too (16/10/2026).
		add_filter( 'wp_sitemaps_posts_url_list', array( $this, 'core_url_list' ), 20, 2 );
		add_filter( 'wpseo_sitemap_index', array( $this, 'index_entry' ) );
		// 21/10/2026 (The SEO Framework, banco): its /sitemap.xml is one list, not an index, so our sitemap had no way in
		// and the translated pages were in no sitemap Google is given. Its own filter takes extra addresses.
		add_filter( 'the_seo_framework_sitemap_additional_urls', array( $this, 'tsf_urls' ), 10, 2 );
	}

	/**
	 * The translated addresses for The SEO Framework's sitemap: the same pages and languages as our own sitemap.
	 *
	 * @param array $custom_urls Extra URLs so far, keyed by URL.
	 * @param array $args        Sitemap arguments (unused).
	 * @return array
	 */
	public function tsf_urls( $custom_urls, $args = array() ) {
		unset( $args );
		if ( ! is_array( $custom_urls ) || Preview::hidden() || \TranslateRocket\Coexistence::on() ) {
			return $custom_urls;
		}
		$router = Plugin::instance()->router();
		$source = $router->default_language();
		$langs  = array_values( array_diff( (array) $router->public_languages(), array( $source ) ) );
		if ( empty( $langs ) ) {
			return $custom_urls;
		}
		$home  = rtrim( (string) home_url( '/' ), '/' );
		$pagine = array( array( 0, $home . '/', '' ) );
		$front = (int) get_option( 'page_on_front' );
		foreach ( get_posts( array( 'post_type' => $this->post_types(), 'post_status' => 'publish', 'posts_per_page' => self::MAX, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true ) ) as $id ) {
			$id  = (int) $id;
			$url = (string) get_permalink( $id );
			if ( $id === $front || '' === $url || false !== strpos( $url, '?' ) || in_array( $id, self::hidden_ids(), true ) || NoTranslate::path_excluded( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
				continue;
			}
			$pagine[] = array( $id, $url, (string) get_post_field( 'post_modified_gmt', $id ) );
		}
		foreach ( $pagine as $p ) {
			foreach ( $langs as $lang ) {
				if ( $p[0] > 0 && '' !== (string) ( \TranslateRocket\Exclusions::get( $p[0], (string) $lang )['mode'] ?? '' ) ) {
					continue;
				}
				$custom_urls[ $this->lang_url( $p[0], $p[1], (string) $lang, $source, $home ) ] = array( 'lastmod' => $p[2] );
			}
		}
		return $custom_urls;
	}

	/**
	 * Append our sitemap as a <sitemap> entry in an SEO plugin's index XML.
	 *
	 * @param string $xml The index XML so far.
	 */
	public function index_entry( $xml ) {
		if ( Preview::hidden() ) {
			return $xml; // Language URLs aren't public while previewing.
		}
		foreach ( self::file_urls() as $loc ) {
			$xml .= "\t<sitemap>\n"
				. "\t\t<loc>" . esc_url( $loc ) . "</loc>\n"
				. "\t\t<lastmod>" . esc_html( gmdate( 'c' ) ) . "</lastmod>\n"
				. "\t</sitemap>\n";
		}
		return $xml;
	}

	/**
	 * The addresses to put in an SEO plugin's sitemap index: our sitemap, or its
	 * numbered files when it is an index itself (an index inside an index is not
	 * allowed by the sitemap protocol).
	 *
	 * @return string[]
	 */
	public static function file_urls(): array {
		$n = self::file_count();
		if ( $n <= 1 ) {
			return array( home_url( '/translaterocket-sitemap.xml' ) );
		}
		$out = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$out[] = home_url( '/translaterocket-sitemap-' . $i . '.xml' );
		}
		return $out;
	}

	/**
	 * Languages listed in the sitemap: the active ones minus the offline ones,
	 * whoever asks (public_languages() keeps the offline ones for an admin, and
	 * a sitemap is never a preview).
	 *
	 * @return string[] Source first.
	 */
	private static function langs(): array {
		$router = Plugin::instance()->router();
		return array_values( array_diff( (array) $router->active_languages(), (array) $router->offline_languages() ) );
	}

	/**
	 * Pages per file, so that a file stays under both limits: each page gives one
	 * <url> per language, each <url> holding one link per language + x-default.
	 */
	private static function pages_per_file( int $langs ): int {
		$langs    = max( 1, $langs );
		$by_urls  = intdiv( self::FILE_URLS, $langs );
		$by_bytes = intdiv( self::FILE_BYTES, $langs * ( $langs + 2 ) * self::ALT_BYTES );
		$per      = max( 1, min( $by_urls, $by_bytes ) );
		/**
		 * Pages per sitemap file (each page = one <url> per language). Can only go
		 * lower than the limit worked out from the sitemap protocol, e.g. for a slow server.
		 *
		 * @param int $per   Pages per file.
		 * @param int $langs Languages listed.
		 */
		$want = (int) apply_filters( 'trrocket_sitemap_pages_per_file', $per, $langs );
		return $want >= 1 ? min( $per, $want ) : $per;
	}

	/**
	 * Ids of the listed pages, newest first, the static front page left out (it is the home entry).
	 *
	 * @return int[]
	 */
	private static function page_ids(): array {
		if ( null === self::$ids ) {
			$front     = (int) get_option( 'page_on_front' );
			$ids       = get_posts(
				array(
					'post_type'              => self::post_types(),
					'post_status'            => 'publish',
					'posts_per_page'         => self::MAX_PAGES,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'suppress_filters'       => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			self::$ids = array_values( array_diff( array_map( 'intval', (array) $ids ), array( $front ), self::hidden_ids() ) );
		}
		return self::$ids;
	}

	/** @var int[]|null Pages kept out of search engines, worked out once per request. */
	private static $hidden = null;

	/**
	 * Pages the site keeps out of search engines, left out of the sitemap in every language: noindex set in Yoast,
	 * Rank Math, SEOPress, All in One SEO or The SEO Framework (for the page, or for its whole post type in Yoast and
	 * Rank Math), the pages Yoast leaves out of its own sitemap, password-protected pages, and WooCommerce's cart,
	 * checkout and account pages (WooCommerce marks them noindex itself). Ticket 27, 1/11/2026: casaramla.com had cart,
	 * checkout, account and search set to noindex in Yoast and our sitemap still listed them.
	 * A few queries for the whole site, not one per page.
	 *
	 * @return int[]
	 */
	private static function hidden_ids(): array {
		if ( null !== self::$hidden ) {
			return self::$hidden;
		}
		global $wpdb;
		$hidden = array();
		$forced = array(); // Pages set to «index» by hand, against a post type set to noindex.
		$on     = array(
			'_yoast_wpseo_meta-robots-noindex' => defined( 'WPSEO_VERSION' ),
			'_seopress_robots_index'           => defined( 'SEOPRESS_VERSION' ),
			'_genesis_noindex'                 => function_exists( 'the_seo_framework' ),
			'rank_math_robots'                 => class_exists( '\RankMath\Helper' ),
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( '_yoast_wpseo_meta-robots-noindex', '_seopress_robots_index', '_genesis_noindex', 'rank_math_robots' )"
		);
		foreach ( (array) $rows as $r ) {
			$id = (int) $r->post_id;
			$v  = (string) $r->meta_value;
			if ( ! ( $on[ $r->meta_key ] ?? false ) ) {
				continue; // Left by an SEO plugin that is no longer active: the page is indexed again.
			}
			switch ( $r->meta_key ) {
				case '_yoast_wpseo_meta-robots-noindex': // 1 = noindex, 2 = index, else the post type's default.
					if ( '1' === $v ) {
						$hidden[] = $id;
					} elseif ( '2' === $v ) {
						$forced[] = $id;
					}
					break;
				case '_seopress_robots_index':
					if ( 'yes' === $v ) {
						$hidden[] = $id;
					}
					break;
				case '_genesis_noindex': // The SEO Framework: 1 = noindex, -1 = index.
					if ( '1' === $v ) {
						$hidden[] = $id;
					} elseif ( '-1' === $v ) {
						$forced[] = $id;
					}
					break;
				case 'rank_math_robots': // Serialized list, e.g. [ 'noindex', 'nofollow' ]; set only on pages with their own robots.
					$list = maybe_unserialize( $v );
					if ( is_array( $list ) && in_array( 'noindex', $list, true ) ) {
						$hidden[] = $id;
					} elseif ( is_array( $list ) ) {
						$forced[] = $id;
					}
					break;
			}
		}
		// All in One SEO keeps its own table.
		$aio = $wpdb->prefix . 'aioseo_posts';
		if ( function_exists( 'aioseo' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aio ) ) === $aio ) {
			$hidden = array_merge( $hidden, array_map( 'intval', (array) $wpdb->get_col( "SELECT post_id FROM `{$aio}` WHERE robots_default = 0 AND robots_noindex = 1" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$hidden = array_merge( $hidden, array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password <> ''" ) ) );
		// A whole post type set to noindex (Yoast: «Show X in search results» off; Rank Math: robots of the post type).
		$types = array();
		$rm    = get_option( 'rank-math-options-titles' );
		foreach ( self::post_types() as $type ) {
			if ( defined( 'WPSEO_VERSION' ) && class_exists( 'WPSEO_Options' ) && \WPSEO_Options::get( 'noindex-' . $type, false ) ) {
				$types[] = $type;
			} elseif ( class_exists( '\RankMath\Helper' ) && is_array( $rm ) && 'on' === ( $rm[ 'pt_' . $type . '_custom_robots' ] ?? '' ) && in_array( 'noindex', (array) ( $rm[ 'pt_' . $type . '_robots' ] ?? array() ), true ) ) {
				$types[] = $type;
			}
		}
		if ( $types ) {
			$in      = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$by_type = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( {$in} )", $types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$hidden  = array_merge( $hidden, array_diff( $by_type, $forced ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( defined( 'WPSEO_VERSION' ) ) {
			$hidden = array_merge( $hidden, array_map( 'intval', (array) apply_filters( 'wpseo_exclude_from_sitemap_by_post_ids', array() ) ) );
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $p ) {
				$hidden[] = (int) wc_get_page_id( $p );
			}
		}
		/**
		 * Pages left out of the multilingual sitemap, in every language.
		 *
		 * @param int[] $hidden Post ids.
		 */
		$hidden       = (array) apply_filters( 'trrocket_sitemap_exclude_ids', $hidden );
		self::$hidden = array_values( array_filter( array_unique( array_map( 'intval', $hidden ) ) ) );
		return self::$hidden;
	}

	/**
	 * How many files the sitemap takes (1 = no index).
	 */
	private static function file_count(): int {
		$per = self::pages_per_file( count( self::langs() ) );
		return max( 1, (int) ceil( ( count( self::page_ids() ) + 1 ) / $per ) ); // +1: the home page.
	}

	public function add_rewrite(): void {
		add_rewrite_rule( '^translaterocket-sitemap\.xml$', 'index.php?' . self::QV . '=1', 'top' );
		add_rewrite_rule( '^translaterocket-sitemap-([0-9]+)\.xml$', 'index.php?' . self::QV . '=1&' . self::QV . '_n=$matches[1]', 'top' );
	}

	/**
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function query_var( $vars ) {
		$vars[] = self::QV;
		$vars[] = self::QV . '_n';
		return $vars;
	}

	/**
	 * Point crawlers at the sitemap from robots.txt.
	 *
	 * @param string $output robots.txt body.
	 */
	public function robots( $output ) {
		if ( Preview::hidden() ) {
			return $output;
		}
		return $output . "\nSitemap: " . home_url( '/translaterocket-sitemap.xml' ) . "\n";
	}

	/**
	 * Output the sitemap when our URL/query var is requested.
	 */
	public function maybe_output(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended
		$req  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) strtok( $req, '?' );
		$m    = array();
		// The path works even before the rewrite rules are flushed (-N files are new in 1.8.30).
		$is_path = (bool) preg_match( '#/translaterocket-sitemap(?:-([0-9]{1,6}))?\.xml$#', $path, $m );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === (string) get_query_var( self::QV ) && empty( $_GET[ self::QV ] ) && ! $is_path ) {
			return;
		}
		// 0 = the main address (the whole sitemap, or the index when it needs several files).
		$n = isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : (int) get_query_var( self::QV . '_n' );
		if ( Preview::hidden() || ( $n > 0 && $n > self::file_count() ) ) {
			// The multilingual sitemap doesn't exist for the public yet / no such file.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		$this->render( $n );
		exit;
	}

	/**
	 * Build and print the XML.
	 *
	 * @param int $n 0 = main address, 1… = one numbered file.
	 */
	private function render( int $n = 0 ): void {
		$router    = Plugin::instance()->router();
		$source    = $router->default_language();
		// Una lingua non ancora pubblicata non entra nella sitemap: invitare
		// Google su pagine mezze tradotte e' peggio che non invitarlo affatto.
		$langs     = self::langs();
		$home      = rtrim( (string) home_url( '/' ), '/' );
		$files     = self::file_count();

		if ( ! headers_sent() ) {
			// The request resolves to no post object, so WordPress will have set a
			// 404. Override it: this is a valid document, and crawlers ignore a
			// sitemap served with a 404 status.
			status_header( 200 );
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}

		$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

		if ( 0 === $n && $files > 1 ) {
			// Too big for one file: the main address becomes the index of the numbered files.
			$out .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
			foreach ( self::file_urls() as $loc ) {
				$out .= '<sitemap><loc>' . esc_url( $loc ) . '</loc></sitemap>' . "\n";
			}
			$out .= '</sitemapindex>';
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- XML, every value esc_url'd above.
			return;
		}

		$out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		// This file's share of the pages: the home page (0) first, then the ids.
		$items = array_merge( array( 0 ), self::page_ids() );
		if ( $n > 0 ) {
			$per   = self::pages_per_file( count( $langs ) );
			$items = array_slice( $items, ( $n - 1 ) * $per, $per );
		}
		$ids = array_values( array_filter( $items ) );
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true ); // One query for the posts and one for their meta, not one each.
		}
		foreach ( $items as $id ) {
			$id = (int) $id;
			if ( 0 === $id ) {
				$out .= $this->entry( 0, $home . '/', $langs, $source, $home, '' );
				continue;
			}
			$url = (string) get_permalink( $id );
			if ( '' === $url || false !== strpos( $url, '?' ) ) {
				continue; // Skip empty or non-pretty URLs (e.g. the Elementor kit).
			}
			// Respect path exclusions.
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( NoTranslate::path_excluded( $path ) ) {
				continue;
			}
			$out .= $this->entry( $id, $url, $langs, $source, $home, (string) get_post_modified_time( 'c', true, $id ) );
		}

		$out .= '</urlset>';
		// XML sitemap: every <loc>/<lastmod> is esc_url/esc_html and every hreflang/href
		// is esc_attr/esc_url at build time (see entry()). wp_kses() is not usable here —
		// it would strip the namespaced <xhtml:link> alternate-language nodes.
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- XML output, each value escaped at build time; wp_kses strips namespaced XML.
	}

	/**
	 * WordPress' core sitemap lists the source pages only: add every public language's address after each one,
	 * with the translated slug when there is one. A page excluded from a language is left out of it.
	 *
	 * @param mixed  $url_list  Entries of this sitemap page (['loc' => …]).
	 * @param string $post_type Post type of the page.
	 * @return mixed
	 */
	public function core_url_list( $url_list, $post_type = '' ) {
		unset( $post_type );
		if ( ! is_array( $url_list ) || \TranslateRocket\Coexistence::on() ) {
			return $url_list;
		}
		$router = Plugin::instance()->router();
		$source = $router->default_language();
		$langs  = array_values( array_diff( (array) $router->public_languages(), array( $source ) ) );
		if ( empty( $langs ) ) {
			return $url_list;
		}
		$home = rtrim( (string) get_option( 'home' ), '/' );
		$out  = array();
		foreach ( $url_list as $entry ) {
			$out[] = $entry;
			$loc   = is_array( $entry ) && isset( $entry['loc'] ) ? (string) $entry['loc'] : '';
			if ( '' === $loc || 0 !== strpos( $loc, $home ) || false !== strpos( $loc, '?' ) ) {
				continue;
			}
			$id = (int) url_to_postid( $loc );
			if ( $id > 0 && in_array( $id, self::hidden_ids(), true ) ) {
				continue; // Kept out of search engines: no language copies either.
			}
			foreach ( $langs as $lang ) {
				if ( $id > 0 && '' !== (string) ( \TranslateRocket\Exclusions::get( $id, (string) $lang )['mode'] ?? '' ) ) {
					continue;
				}
				$copia        = is_array( $entry ) ? $entry : array();
				$copia['loc'] = $this->lang_url( $id, $loc, (string) $lang, $source, $home );
				$out[]        = $copia;
			}
		}
		return $out;
	}

	/**
	 * One <url> block per language version of a page, each with the same full set
	 * of hreflang alternates (itself included) + x-default, as in Google's example.
	 *
	 * @param string[] $langs  Public language codes (source first).
	 */
	private function entry( int $post_id, string $src_url, array $langs, string $source, string $home, string $lastmod ): string {
		$alts = array();
		foreach ( $langs as $lang ) {
			$lang = (string) $lang;
			// A language this page is excluded from (redirect / 404 / custom message)
			// is not a real version — listing it would send crawlers to a
			// redirect or a 404.
			if ( $post_id > 0 && $lang !== $source
				&& '' !== (string) ( \TranslateRocket\Exclusions::get( $post_id, $lang )['mode'] ?? '' ) ) {
				continue;
			}
			$alts[ $lang ] = $this->lang_url( $post_id, $src_url, $lang, $source, $home );
		}
		$links = '';
		foreach ( $alts as $lang => $href ) {
			$links .= '<xhtml:link rel="alternate" hreflang="' . esc_attr( $this->hreflang( (string) $lang ) ) . '" href="' . esc_url( $href ) . '"/>';
		}
		$links .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . esc_url( $src_url ) . '"/>';
		$mod    = '' !== $lastmod ? '<lastmod>' . esc_html( $lastmod ) . '</lastmod>' : '';
		$rows   = '';
		foreach ( $alts as $href ) {
			$rows .= '<url><loc>' . esc_url( $href ) . '</loc>' . $mod . $links . '</url>' . "\n";
		}
		return $rows;
	}

	/**
	 * The URL of a page in one language (with a translated slug if set).
	 */
	private function lang_url( int $post_id, string $src_url, string $lang, string $source, string $home ): string {
		if ( $lang === $source ) {
			return $src_url;
		}
		$path = $src_url;
		if ( 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		$path = '/' . ltrim( $path, '/' );

		// The post's own slug and those of its parent pages, each translated if it has one (1.8.21: child pages).
		if ( $post_id > 0 ) {
			$path = \TranslateRocket\Plugin::instance()->router()->swap_hierarchy( $path, $post_id, $lang );
		}
		return \TranslateRocket\Plugin::instance()->router()->language_base( $lang ) . $path; // home/xx or the language's own domain
	}

	/**
	 * hreflang value for a language code (e.g. pt-br -> pt-BR).
	 */
	private function hreflang( string $code ): string {
		if ( false !== strpos( $code, '-' ) ) {
			$parts = explode( '-', $code );
			return $parts[0] . '-' . strtoupper( $parts[1] );
		}
		return $code;
	}

	/**
	 * Public post types to include.
	 *
	 * @return string[]
	 */
	private static function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}
}
