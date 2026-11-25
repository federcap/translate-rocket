<?php
/**
 * Addresses that answer 404 but belong to a page of the site (1.8.59).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * 25/11/2026 (Search Console of translaterocket.com, 23 languages: 3,126 pages «Not found (404)»). Two families:
 *
 * A. the prefix of one language with the translated slug of another — /es/polylang-适合-seo-吗/, /uk/الأدلة/, and
 *    /功能/ with no prefix at all. They were written by the switcher and hreflang until 12/11/2026 (see
 *    Router::url_for_language) and search engines keep asking for them;
 * B. an address a page or a category had before in a language, inside a longer path (/de/old-parent/child/): the
 *    Router already moves an old address when it is the last word of the path, in the language of the request.
 *
 * On a 404 each word of the path is looked up among the slugs the site knows — the original ones, the translated
 * ones of every language, the ones kept as «old» (Slugs::OLD_PREFIX, TermSlugs::OLD_PREFIX) and the translated URL
 * bases. When the whole path, word by word, is the address of exactly ONE published page, post or term archive, the
 * visitor moves with a 301 to that content's address in the language of the prefix. Anything less than that — a
 * word nobody knows, two contents that fit, a draft, a private or password-protected page — stays a 404.
 *
 * After that, an add-on can give an address of its own (filter trrocket_404_redirect) and is told about the 404s
 * that remain (action trrocket_404).
 */
class NotFound {

	/** Option (not autoloaded): addresses looked up and not found, «key => expiry». Used without an object cache. */
	const MISS_OPTION = 'trrocket_404_miss';

	/** Object cache group for the same thing, when the site has a persistent object cache. */
	const MISS_GROUP = 'trrocket_404';

	/** Seconds an address that was not found is not looked up again. */
	const MISS_TTL = 600;

	/** Addresses kept in the option at most. */
	const MISS_MAX = 200;

	/** Database queries one 404 may cost (filter trrocket_404_max_queries). */
	const MAX_QUERIES = 12;

	/** Longer paths are not looked up. */
	const MAX_SEGMENTS = 6;

	/** More contents than this answering to the last word: too ambiguous to check one by one. */
	const MAX_CANDIDATES = 8;

	/**
	 * The 404 being served, for the action at the end: array( lang, path, url ), or null.
	 *
	 * @var array|null
	 */
	private $seen = null;

	public function register(): void {
		// After the Router's own redirects (1), before WordPress' old-slug redirect and its guess by similar name (10):
		// an exact match comes first.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 2 );
		add_action( 'template_redirect', array( $this, 'still_not_found' ), PHP_INT_MAX );
	}

	/**
	 * A 404 for a page address: the automatic corrections, then the add-on's own address.
	 */
	public function maybe_redirect(): void {
		if ( ! is_404() ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore
		$router = Plugin::instance()->router();
		$path   = $router->visited_path();
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! self::page_request( $path ) ) {
			return;
		}
		$lang = $router->current_language();
		$url  = self::requested_url( $router );
		if ( 'GET' === $method ) {
			$this->seen = array( $lang, $path, $url );
		}

		/**
		 * Whether TranslateRocket looks for the page a 404 address belongs to (mixed languages, old addresses) and
		 * moves the visitor there. Return false to switch it off, for the whole site or for one address.
		 *
		 * @param bool   $on   True.
		 * @param string $lang Language of the request.
		 * @param string $path Requested path without the language prefix, starting with «/».
		 */
		if ( apply_filters( 'trrocket_resolve_404', true, $lang, $path ) ) {
			$to = $this->resolve( $router, $lang );
			if ( '' !== $to ) {
				wp_safe_redirect( $to, 301, 'TranslateRocket' );
				exit;
			}
		}

		/**
		 * An address for a 404 that TranslateRocket could not correct by itself. A non-empty address of this site
		 * (or of a host WordPress allows) is answered with a 301.
		 *
		 * @param string $to   ''.
		 * @param string $lang Language of the request.
		 * @param string $path Requested path without the language prefix, starting with «/».
		 * @param string $url  The full address requested.
		 */
		$custom = apply_filters( 'trrocket_404_redirect', '', $lang, $path, $url );
		if ( is_string( $custom ) && '' !== trim( $custom ) ) {
			$to = (string) wp_validate_redirect( wp_sanitize_redirect( trim( $custom ) ), '' );
			// As it will leave: the Router puts the language back in front of an address of this site without one.
			$final = '' !== $to ? (string) $router->keep_language_on_redirect( $to ) : '';
			if ( '' !== $final && ! self::is_request( $final, $router, true ) ) {
				wp_safe_redirect( $to, 301, 'TranslateRocket' );
				exit;
			}
		}
	}

	/**
	 * Nobody redirected: the request really ends as a 404.
	 */
	public function still_not_found(): void {
		if ( null === $this->seen || ! is_404() ) {
			return;
		}
		/**
		 * A GET request for a page address ends as a 404 (no file, REST, feed or AJAX address).
		 *
		 * @param string $lang Language of the request.
		 * @param string $path Requested path without the language prefix, starting with «/».
		 * @param string $url  The full address requested.
		 */
		do_action( 'trrocket_404', $this->seen[0], $this->seen[1], $this->seen[2] );
	}

	/**
	 * Is this the address of a page (not a file, the admin, REST, a feed, AJAX, a preview)?
	 *
	 * @param string $path Path without the language prefix.
	 */
	private static function page_request( string $path ): bool {
		global $wp_rewrite;
		if ( is_admin() || Coexistence::on() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| wp_doing_ajax() || wp_doing_cron() || Router::is_frontend_ajax() || Router::is_ajax_endpoint()
			|| isset( $_GET['wc-ajax'] ) || isset( $_GET['rest_route'] ) // phpcs:ignore WordPress.Security.NonceVerification
			|| is_feed() || is_trackback() || is_robots() || is_preview()
			|| ( function_exists( 'is_favicon' ) && is_favicon() )
			|| ( function_exists( 'is_customize_preview' ) && is_customize_preview() )
			|| \TranslateRocket\Frontend\Preview::language_blocked() ) {
			return false;
		}
		$segs = array_values( array_filter( explode( '/', strtolower( $path ) ), 'strlen' ) );
		if ( empty( $segs ) ) {
			return false; // the home of a language is never a 404 of ours
		}
		$last = (string) end( $segs );
		if ( 0 === strpos( $segs[0], 'wp-' ) || in_array( 'wp-json', $segs, true ) || false !== strpos( $last, '.' ) ) {
			return false; // WordPress' own folders, REST, a file
		}
		$feed = ( is_object( $wp_rewrite ) && '' !== (string) $wp_rewrite->feed_base ) ? strtolower( (string) $wp_rewrite->feed_base ) : 'feed';
		if ( in_array( $feed, $segs, true ) || in_array( $last, array( 'embed', 'trackback' ), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * The full address of the request, as the visitor sent it.
	 *
	 * @param Router $router Router.
	 */
	private static function requested_url( Router $router ): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : ''; // phpcs:ignore
		if ( '' === $host || ! preg_match( '/^[a-z0-9.\-]+(:\d+)?$/i', $host ) ) {
			$host = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
		}
		return esc_url_raw( ( is_ssl() ? 'https' : 'http' ) . '://' . $host . $router->original_request_uri() );
	}

	/**
	 * Is an address the one being requested (a redirect there would never end)?
	 *
	 * @param string $to         Absolute address or path.
	 * @param Router $router     Router.
	 * @param bool   $with_query Compare the query string too.
	 */
	private static function is_request( string $to, Router $router, bool $with_query ): bool {
		$asked = $router->original_request_uri();
		$host  = strtolower( (string) wp_parse_url( $to, PHP_URL_HOST ) );
		$here  = strtolower( (string) preg_replace( '/:\d+$/', '', isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) ); // phpcs:ignore
		if ( '' !== $host && '' !== $here && $host !== $here ) {
			return false; // another host (a language on a domain of its own)
		}
		if ( ! Router::same_path( (string) wp_parse_url( $to, PHP_URL_PATH ), (string) wp_parse_url( $asked, PHP_URL_PATH ) ) ) {
			return false;
		}
		return ! $with_query || (string) wp_parse_url( $to, PHP_URL_QUERY ) === (string) wp_parse_url( $asked, PHP_URL_QUERY );
	}

	/**
	 * The address this 404 belongs to in the language of the request, or ''.
	 *
	 * @param Router $router Router.
	 * @param string $lang   Language of the request.
	 */
	private function resolve( Router $router, string $lang ): string {
		global $wpdb, $wp_rewrite;
		if ( ! is_object( $wp_rewrite ) || ! $wp_rewrite->using_permalinks() || empty( $router->secondary_languages() ) ) {
			return '';
		}
		// The path WordPress read: the first word already back to the original one for this language (UrlBases).
		$clean = $router->current_clean_path();
		$segs  = array_values( array_filter( explode( '/', $clean ), 'strlen' ) );
		$n     = count( $segs );
		if ( 0 === $n || $n > self::MAX_SEGMENTS ) {
			return '';
		}
		$req = array_map( array( Router::class, 'fold' ), $segs );
		// A page of a list (/page/3/) is not moved: nobody knows whether that page exists at the new address.
		$pag = '' !== (string) $wp_rewrite->pagination_base ? strtolower( (string) $wp_rewrite->pagination_base ) : 'page';
		if ( $n >= 2 && ctype_digit( $req[ $n - 1 ] ) && $req[ $n - 2 ] === $pag ) {
			return '';
		}
		$key = md5( $lang . '|' . implode( '/', $req ) );
		if ( self::missed( $key ) ) {
			return '';
		}
		/**
		 * How many database queries TranslateRocket may spend looking for the page of one 404 address.
		 *
		 * @param int    $max  12.
		 * @param string $lang Language of the request.
		 */
		$cap   = max( 4, (int) apply_filters( 'trrocket_404_max_queries', self::MAX_QUERIES, $lang ) );
		$start = (int) $wpdb->num_queries;
		$to    = $this->lookup( $router, $lang, $req, $start + $cap );
		if ( '' !== $to ) {
			$q = $router->request_query();
			if ( '' !== $q ) {
				$to .= ( false === strpos( $to, '?' ) ? '?' : '&' ) . $q;
			}
			// The right address of that content is the one that has just answered 404: nothing to do.
			if ( self::is_request( $to, $router, false ) ) {
				$to = '';
			}
		}
		if ( '' === $to ) {
			self::remember_miss( $key );
		}
		return $to;
	}

	/**
	 * The one content whose address, word by word, is the requested path; its address in the language of the request.
	 *
	 * @param Router   $router Router.
	 * @param string   $lang   Language of the request.
	 * @param string[] $req    Requested path: its words, decoded and lower case.
	 * @param int      $limit  Value of $wpdb->num_queries past which no other candidate is examined.
	 */
	private function lookup( Router $router, string $lang, array $req, int $limit ): string {
		global $wpdb;
		$n    = count( $req );
		$last = $req[ $n - 1 ];
		// As WordPress writes a slug in the database (%e5%8a%9f%e8%83%bd for 功能): the lookups use these, then every
		// row found is compared with the word of the address, so «über» never passes for «uber».
		$norm = array();
		foreach ( $req as $seg ) {
			$s = sanitize_title( $seg );
			if ( '' === $s ) {
				return '';
			}
			$norm[] = $s;
		}
		$last_norm = $norm[ $n - 1 ];
		$norm      = array_values( array_unique( $norm ) );
		$in_v      = implode( ',', array_fill( 0, count( $norm ), '%s' ) );
		$old_posts = (bool) get_option( Slugs::OLD_ANY );
		$pk        = array();
		$tk        = array();
		foreach ( $router->secondary_languages() as $l ) {
			$pk[] = Slugs::META_PREFIX . $l;
			$tk[] = TermSlugs::META_PREFIX . $l;
			$tk[] = TermSlugs::OLD_PREFIX . $l;
			if ( $old_posts ) {
				$pk[] = Slugs::OLD_PREFIX . $l;
			}
		}

		// 1. Pages: translated slugs of any language, today's and old ones, for every word of the path (the meta_key
		// index, one range per key); then the pages whose own slug is the last word (the post_name index).
		$post_alias = array(); // word => post id => true
		$in_k       = implode( ',', array_fill( 0, count( $pk ), '%s' ) );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$in_k}) AND meta_value IN ({$in_v}) LIMIT 60", array_merge( $pk, $norm ) ) ) as $r ) { // phpcs:ignore WordPress.DB
			$post_alias[ Router::fold( (string) $r->meta_value ) ][ (int) $r->post_id ] = true;
		}
		$post_ids = array_keys( $post_alias[ $last ] ?? array() );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type NOT IN ( 'attachment', 'revision', 'nav_menu_item' ) LIMIT %d", $last_norm, self::MAX_CANDIDATES + 1 ) ) as $id ) { // phpcs:ignore WordPress.DB
			$post_ids[] = (int) $id;
		}
		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );

		// 2. Terms, the same way.
		$term_alias = array(); // word => term id => true
		$in_k       = implode( ',', array_fill( 0, count( $tk ), '%s' ) );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key IN ({$in_k}) AND meta_value IN ({$in_v}) LIMIT 60", array_merge( $tk, $norm ) ) ) as $r ) { // phpcs:ignore WordPress.DB
			$term_alias[ Router::fold( (string) $r->meta_value ) ][ (int) $r->term_id ] = true;
		}
		$term_ids = array_keys( $term_alias[ $last ] ?? array() );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE slug = %s LIMIT %d", $last_norm, self::MAX_CANDIDATES + 1 ) ) as $id ) { // phpcs:ignore WordPress.DB
			$term_ids[] = (int) $id;
		}
		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

		if ( ( empty( $post_ids ) && empty( $term_ids ) ) || count( $post_ids ) > self::MAX_CANDIDATES || count( $term_ids ) > self::MAX_CANDIDATES ) {
			return '';
		}

		// 3. Each candidate: public, and its own address made of exactly the words of the request.
		$hits = array(); // «post|12», «term|7» => true
		if ( $post_ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $post_ids, false, true ); // the candidates and their meta in two queries, not two each
		}
		foreach ( $post_ids as $id ) {
			if ( (int) $wpdb->num_queries >= $limit ) {
				return '';
			}
			$post = get_post( $id );
			if ( ! ( $post instanceof \WP_Post ) || 'publish' !== $post->post_status || '' !== (string) $post->post_password || '' === (string) $post->post_name
				|| 'attachment' === $post->post_type || ! is_post_type_viewable( $post->post_type ) || Copies::is_copy( $id ) ) {
				continue;
			}
			// The owner keeps this page out of this language (Exclusions: home, another address, a message, a 404).
			if ( ! $router->is_default( $lang ) && Exclusions::is_excluded( $id, $lang ) ) {
				continue;
			}
			$chain = array( array( $id, (string) $post->post_name ) );
			if ( is_post_type_hierarchical( $post->post_type ) ) {
				foreach ( get_post_ancestors( $post ) as $aid ) {
					$a = get_post( (int) $aid );
					if ( ! ( $a instanceof \WP_Post ) ) {
						break;
					}
					$chain[] = array( (int) $a->ID, (string) $a->post_name );
				}
			}
			$orig = array_values( array_filter( explode( '/', $router->canonical_path( $id ) ), 'strlen' ) );
			if ( self::path_is( $req, $orig, $chain, $post_alias ) ) {
				$hits[ 'post|' . $id ] = true;
			}
		}
		foreach ( $term_ids as $id ) {
			if ( (int) $wpdb->num_queries >= $limit ) {
				return '';
			}
			$term = get_term( $id );
			if ( ! ( $term instanceof \WP_Term ) || ! is_taxonomy_viewable( $term->taxonomy ) || 'post_format' === $term->taxonomy ) {
				continue;
			}
			$chain = array( array( $id, (string) $term->slug ) );
			if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
				foreach ( get_ancestors( $id, $term->taxonomy, 'taxonomy' ) as $aid ) {
					$a = get_term( (int) $aid, $term->taxonomy );
					if ( ! ( $a instanceof \WP_Term ) ) {
						break;
					}
					$chain[] = array( (int) $a->term_id, (string) $a->slug );
				}
			}
			$orig = array_values( array_filter( explode( '/', $router->original_term_path( $term ) ), 'strlen' ) );
			if ( self::path_is( $req, $orig, $chain, $term_alias ) ) {
				$hits[ 'term|' . $id ] = true;
			}
		}
		if ( 1 !== count( $hits ) ) {
			return ''; // nothing, or two contents that fit: not ours to choose
		}

		// 4. Its address in the language of the request: the same functions every link of the site goes through.
		list( $kind, $id ) = explode( '|', (string) key( $hits ) );
		if ( 'post' === $kind ) {
			$to = get_permalink( (int) $id );
		} else {
			$to = get_term_link( (int) $id );
		}
		return ( is_string( $to ) && '' !== $to ) ? $to : '';
	}

	/**
	 * Is a requested path the address of a content, word by word? Each word must be the one of the content's own
	 * address at that place, or — for the content and its parents — a translated or old slug of that very page or
	 * term, or — for the first word — a translation of that URL base in some language. Same number of words.
	 *
	 * @param string[] $req   Requested words, decoded and lower case.
	 * @param string[] $orig  Words of the content's address in the original language.
	 * @param array[]  $chain The content and its parents, nearest first: array( id, own slug ).
	 * @param array    $alias Word => id => true: whose translated or old slug each requested word is.
	 */
	private static function path_is( array $req, array $orig, array $chain, array $alias ): bool {
		$m = count( $orig );
		if ( 0 === $m || count( $req ) !== $m ) {
			return false;
		}
		$orig = array_map( array( Router::class, 'fold' ), $orig );
		// Which word of the address belongs to the content, which to its parent, and so on going left.
		$owner = array();
		$j     = $m - 1;
		foreach ( $chain as $c ) {
			if ( $j < 0 || Router::fold( (string) $c[1] ) !== $orig[ $j ] ) {
				break;
			}
			$owner[ $j ] = (int) $c[0];
			--$j;
		}
		if ( ! isset( $owner[ $m - 1 ] ) ) {
			return false; // an address that does not end with the content's slug (/%postname%.html, ?p=12)
		}
		for ( $i = 0; $i < $m; $i++ ) {
			if ( $req[ $i ] === $orig[ $i ] ) {
				continue;
			}
			if ( isset( $owner[ $i ] ) && isset( $alias[ $req[ $i ] ][ $owner[ $i ] ] ) ) {
				continue;
			}
			if ( 0 === $i && $m > 1 && ! isset( $owner[0] ) && in_array( $orig[0], array_map( array( Router::class, 'fold' ), UrlBases::originals_of( $req[0] ) ), true ) ) {
				continue;
			}
			return false;
		}
		return true;
	}

	/**
	 * Was this address looked up, and not found, a short time ago? Only a saving: a wrong «no» lasts MISS_TTL at
	 * most, a lost entry costs one more lookup.
	 *
	 * @param string $key Key of language + path.
	 */
	private static function missed( string $key ): bool {
		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_get( $key, self::MISS_GROUP );
		}
		$d = get_option( self::MISS_OPTION );
		return is_array( $d ) && isset( $d['k'][ $key ] ) && (int) $d['k'][ $key ] > time();
	}

	/**
	 * Remember an address that was not found.
	 *
	 * @param string $key Key of language + path.
	 */
	private static function remember_miss( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, 1, self::MISS_GROUP, self::MISS_TTL );
			return;
		}
		$d   = get_option( self::MISS_OPTION );
		$d   = is_array( $d ) ? $d : array();
		$now = time();
		// A crawler asking for a different address each time: the list would be written at every request and help
		// nobody. One write every two seconds at most.
		if ( $now - (int) ( $d['t'] ?? 0 ) < 2 ) {
			return;
		}
		$k = array();
		foreach ( (array) ( $d['k'] ?? array() ) as $was => $until ) {
			if ( (int) $until > $now ) {
				$k[ (string) $was ] = (int) $until;
			}
		}
		$k[ $key ] = $now + self::MISS_TTL;
		if ( count( $k ) > self::MISS_MAX ) {
			$k = array_slice( $k, -self::MISS_MAX, null, true );
		}
		update_option(
			self::MISS_OPTION,
			array(
				't' => $now,
				'k' => $k,
			),
			false
		);
	}
}
