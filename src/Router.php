<?php
/**
 * Language routing.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the current language from the URL, rewrites links and outputs
 * hreflang tags.
 *
 * Strategy: the default (source) language lives at the site root, every other
 * language sits under a sub-directory prefix, e.g. /en/, /de/. We detect the
 * prefix early and strip it from REQUEST_URI before WordPress parses the
 * request, then re-add it to generated links via the home_url filter.
 */
class Router {

	/**
	 * Resolved current language code.
	 *
	 * @var string
	 */
	private $current = '';

	/**
	 * Request path relative to the WordPress base, language prefix removed.
	 *
	 * @var string
	 */
	private $clean_path = '/';

	/**
	 * Current request query string (without the leading '?').
	 *
	 * @var string
	 */
	private $query = '';

	/**
	 * Hook into WordPress.
	 */
	public function boot(): void {
		$this->detect_and_strip();
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 2 );

		if ( ! is_admin() ) {
			add_action( 'wp_head', array( $this, 'hreflang_tags' ) );
			add_action( 'wp_head', array( $this, 'og_locale_tags' ), 6 );
			add_filter( 'page_link', array( $this, 'filter_page_link' ), 20, 2 );
			add_filter( 'post_link', array( $this, 'filter_post_link' ), 20, 2 );
			add_filter( 'term_link', array( $this, 'filter_term_link' ), 20, 3 );
			add_filter( 'request', array( $this, 'resolve_request' ) );
			add_filter( 'redirect_canonical', array( $this, 'filter_canonical' ) );

			// While WP core parses the request it strips the home-URL path from the
			// requested path with an UNANCHORED-to-segment regex (preg_replace
			// '|^de|i'). With our /de prefix in home_url, a slug that merely STARTS
			// with the language code lost those letters too: /de/demo/ became "mo"
			// and 404ed (same for /it/items/, /es/estimate/, …). detect_and_strip()
			// has already removed the prefix from REQUEST_URI, so during parsing
			// home_url must stay unprefixed; the filter goes back on right after.
			add_filter(
				'do_parse_request',
				function ( $do ) {
					remove_filter( 'home_url', array( $this, 'filter_home_url' ), 10 );
					return $do;
				}
			);
			add_action(
				'parse_request',
				function () {
					add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 2 );
				},
				0
			);
		}
	}

	/**
	 * Default (source) language code.
	 */
	public function default_language(): string {
		return strtolower( Settings::get()['source_language'] );
	}

	/**
	 * Secondary languages (targets minus the default).
	 *
	 * @return string[]
	 */
	public function secondary_languages(): array {
		$default = $this->default_language();
		$out     = array();
		foreach ( (array) Settings::get()['target_languages'] as $code ) {
			$code = strtolower( $code );
			if ( $code !== $default && Languages::exists( $code ) ) {
				$out[] = $code;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Languages the owner has added but not published yet.
	 *
	 * A language can be switched off while it is being translated: it stays
	 * fully usable in the admin (so you can work on it and preview it on the
	 * real pages) but visitors never see it.
	 *
	 * @return string[]
	 */
	public function offline_languages(): array {
		$default = $this->default_language();
		$out     = array();
		foreach ( (array) ( Settings::get()['offline_languages'] ?? array() ) as $code ) {
			$code = strtolower( (string) $code );
			// The source language can never be offline: it is the site itself.
			if ( $code !== $default && in_array( $code, $this->secondary_languages(), true ) ) {
				$out[] = $code;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Is this language still being worked on, and therefore hidden?
	 *
	 * @param string $code Language code.
	 */
	public function is_offline( string $code ): bool {
		return in_array( strtolower( $code ), $this->offline_languages(), true );
	}

	/**
	 * The languages visitors are allowed to see (default first).
	 *
	 * Everything public goes through this one - the switcher, the sitemap,
	 * hreflang and og:locale - so a language that is still being translated is
	 * never advertised anywhere. Whoever can preview (administrators by
	 * default) keeps seeing them all, otherwise there would be no way to check
	 * the work before publishing it.
	 *
	 * @return string[]
	 */
	public function public_languages(): array {
		$tutte = $this->active_languages();
		$giu   = $this->offline_languages();
		if ( empty( $giu ) ) {
			return $tutte;
		}
		/** This filter is documented in src/Frontend/Preview.php */
		$cap = apply_filters( 'trrocket_preview_capability', 'manage_options' );
		if ( function_exists( 'current_user_can' ) && current_user_can( $cap ) ) {
			return $tutte;
		}
		return array_values( array_diff( $tutte, $giu ) );
	}

	/**
	 * All active languages (default first).
	 *
	 * @return string[]
	 */
	public function active_languages(): array {
		return array_merge( array( $this->default_language() ), $this->secondary_languages() );
	}

	/**
	 * The language for the current request.
	 */
	public function current_language(): string {
		return '' !== $this->current ? $this->current : $this->default_language();
	}

	/**
	 * Whether a code is the default language.
	 */
	public function is_default( string $code ): bool {
		return strtolower( $code ) === $this->default_language();
	}

	/**
	 * Current request path with the language prefix removed (relative to base).
	 * Used to key a page across languages (/it/about and /about share it).
	 */
	public function current_clean_path(): string {
		return $this->clean_path;
	}

	/**
	 * Canonical (default-language, real-slug) path of a post, base-relative.
	 * Used so a page is keyed the same however it was reached (/it/chi-siamo/
	 * and /it/about-us/ both map to /about-us/).
	 */
	public function canonical_path( int $post_id ): string {
		remove_filter( 'home_url', array( $this, 'filter_home_url' ), 10 );
		remove_filter( 'page_link', array( $this, 'filter_page_link' ), 20 );
		remove_filter( 'post_link', array( $this, 'filter_post_link' ), 20 );

		$permalink = (string) get_permalink( $post_id );

		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 20, 2 );
		add_filter( 'post_link', array( $this, 'filter_post_link' ), 20, 2 );

		$path = wp_parse_url( $permalink, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';

		$base = wp_parse_url( get_option( 'home' ), PHP_URL_PATH );
		$base = is_string( $base ) ? rtrim( $base, '/' ) : '';
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return '/' . ltrim( (string) $path, '/' );
	}

	/**
	 * Build the URL of the current page in a given language.
	 */
	public function url_for_language( string $lang ): string {
		$lang   = strtolower( $lang );
		$base   = rtrim( (string) get_option( 'home' ), '/' );
		$prefix = $this->is_default( $lang ) ? '' : '/' . $lang;
		$path   = '' === $this->clean_path ? '/' : $this->clean_path;
		// On a term archive, use that language's translated term slug so the switcher,
		// hreflang and canonical all agree on the same URL.
		$path = $this->maybe_translate_term_path( $path, $lang );

		$url = $base . $prefix . $path;
		if ( '' !== $this->query ) {
			$url .= '?' . $this->query;
		}
		return $url;
	}

	/**
	 * Homepage URL for a language (built directly, bypassing the home_url filter).
	 */
	public function home_for_language( string $lang ): string {
		$lang   = strtolower( $lang );
		$base   = rtrim( (string) get_option( 'home' ), '/' );
		$prefix = $this->is_default( $lang ) ? '' : '/' . $lang;
		return $base . $prefix . '/';
	}

	/**
	 * Detect the language prefix on the front end and strip it from REQUEST_URI.
	 */
	private function detect_and_strip(): void {
		// Front-end AJAX (WooCommerce cart fragments, wc-ajax, etc.) carries no
		// language prefix in its own URL — take the language from the page that
		// triggered it (the referer), so the locale/translations still apply.
		if ( self::is_frontend_ajax() ) {
			$this->current    = $this->referer_language();
			$this->clean_path = '/';
			return;
		}
		if ( is_admin() ) {
			$this->current = $this->default_language();
			return;
		}

		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$request_path = is_string( $request_path ) ? $request_path : '/';

		$query       = wp_parse_url( $request_uri, PHP_URL_QUERY );
		$this->query = is_string( $query ) ? $query : '';

		// Path WordPress is installed under (e.g. '' for root, '/blog' for a sub-dir).
		$base_path = wp_parse_url( get_option( 'home' ), PHP_URL_PATH );
		$base_path = is_string( $base_path ) ? rtrim( $base_path, '/' ) : '';

		// Path relative to the WordPress base.
		$relative = $request_path;
		if ( '' !== $base_path && 0 === strpos( $request_path, $base_path ) ) {
			$relative = substr( $request_path, strlen( $base_path ) );
		}
		$relative = '/' . ltrim( (string) $relative, '/' );

		// First path segment as a secondary language code?
		$secondary = $this->secondary_languages();
		if ( ! empty( $secondary ) && preg_match( '#^/([a-z]{2}(?:-[a-z]{2})?)(/|$)#i', $relative, $m ) ) {
			$candidate = strtolower( $m[1] );
			if ( in_array( $candidate, $secondary, true ) ) {
				$this->current = $candidate;

				$stripped         = substr( $relative, strlen( '/' . $candidate ) );
				$stripped         = '' === $stripped ? '/' : $stripped;
				$this->clean_path = $stripped;

				$_SERVER['REQUEST_URI'] = $base_path . $stripped . ( $this->query ? '?' . $this->query : '' );
				return;
			}
		}

		$this->current    = $this->default_language();
		$this->clean_path = $relative;
	}

	/**
	 * Is this a front-end-originated AJAX request (e.g. a WooCommerce cart
	 * fragment), as opposed to a genuine wp-admin AJAX call? Such requests have no
	 * language prefix in their URL, so we read the language from the referer — but
	 * only when the referer is a front-end page, never for wp-admin AJAX.
	 */
	public static function is_frontend_ajax(): bool {
		$is_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| isset( $_GET['wc-ajax'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $is_ajax ) {
			return false;
		}
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $ref ) {
			return false;
		}
		$path = (string) wp_parse_url( $ref, PHP_URL_PATH );
		return false === strpos( $path, '/wp-admin/' );
	}

	/**
	 * Best guess of the visitor's language for the current request: the URL prefix
	 * if present, otherwise the referer (covers AJAX and Store-API/REST checkout,
	 * whose own URL carries no prefix). Used to tag orders with their language.
	 */
	public function request_language(): string {
		$lang = $this->current_language();
		if ( $this->is_default( $lang ) ) {
			$ref = $this->referer_language();
			if ( ! $this->is_default( $ref ) ) {
				$lang = $ref;
			}
		}
		return $lang;
	}

	/**
	 * The secondary language of the referring page, or the default language.
	 */
	private function referer_language(): string {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $ref ) {
			return $this->default_language();
		}
		$path = (string) wp_parse_url( $ref, PHP_URL_PATH );

		$base_path = wp_parse_url( get_option( 'home' ), PHP_URL_PATH );
		$base_path = is_string( $base_path ) ? rtrim( $base_path, '/' ) : '';

		$relative = $path;
		if ( '' !== $base_path && 0 === strpos( $path, $base_path ) ) {
			$relative = substr( $path, strlen( $base_path ) );
		}
		$relative = '/' . ltrim( (string) $relative, '/' );

		$secondary = $this->secondary_languages();
		if ( ! empty( $secondary ) && preg_match( '#^/([a-z]{2}(?:-[a-z]{2})?)(/|$)#i', $relative, $m ) ) {
			$candidate = strtolower( $m[1] );
			if ( in_array( $candidate, $secondary, true ) ) {
				return $candidate;
			}
		}
		return $this->default_language();
	}

	/**
	 * Add the language prefix to internal links generated via home_url().
	 *
	 * @param string $url  The complete home URL.
	 * @param string $path Path relative to the home URL.
	 * @return string
	 */
	public function filter_home_url( $url, $path ) {
		unset( $path );

		// Never touch admin, AJAX or REST URLs.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $url;
		}

		$lang = $this->current_language();
		if ( $this->is_default( $lang ) ) {
			return $url;
		}

		$base = get_option( 'home' );
		if ( ! is_string( $base ) || 0 !== strpos( $url, $base ) ) {
			return $url;
		}

		$rest = substr( $url, strlen( $base ) ); // e.g. '/about/' or ''.
		$rest = '/' . ltrim( (string) $rest, '/' );

		// Re-entrancy guard: canonical/oEmbed/feed builders often pass an
		// already-localized URL through home_url() again. If the path already
		// carries our language prefix, adding it twice would yield /it/it/.
		if ( '/' . $lang === $rest || 0 === strpos( $rest, '/' . $lang . '/' ) ) {
			return $url;
		}

		return rtrim( $base, '/' ) . '/' . $lang . $rest;
	}

	/**
	 * Output hreflang alternate links in <head> for SEO.
	 */
	public function hreflang_tags(): void {
		// While translations are in admin-only preview, don't advertise the
		// language URLs to crawlers — they redirect to the default language.
		if ( \TranslateRocket\Frontend\Preview::hidden() ) {
			return;
		}
		// Solo le lingue pubblicate: una lingua ancora in lavorazione non va
		// annunciata ai motori di ricerca, se no la indicizzano mezza tradotta.
		$langs = $this->public_languages();
		if ( count( $langs ) < 2 ) {
			return;
		}

		$post_id = ( function_exists( 'is_singular' ) && is_singular() ) ? (int) get_queried_object_id() : 0;

		$out = '';
		foreach ( $langs as $code ) {
			// Don't advertise a language where this page is not a real translation
			// (excluded, redirected or 404) — that would be a broken hreflang.
			if ( $post_id > 0 && ! $this->is_default( $code ) && Exclusions::is_excluded( $post_id, $code ) ) {
				continue;
			}
			$out .= sprintf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( self::hreflang_code( $code ) ),
				esc_url( $this->url_for_language( $code ) )
			);
		}
		$out .= sprintf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( $this->url_for_language( $this->default_language() ) )
		);

		echo wp_kses( $out , \TranslateRocket\Kses::head_rules() );
	}

	/**
	 * Format a language code as a valid hreflang value: lowercase language with an
	 * uppercase region (e.g. "pt-br" -> "pt-BR", "zh-tw" -> "zh-TW", "it" -> "it").
	 */
	private static function hreflang_code( string $code ): string {
		$code = strtolower( $code );
		if ( false === strpos( $code, '-' ) ) {
			return $code;
		}
		list( $lang, $region ) = explode( '-', $code, 2 );
		return $lang . '-' . strtoupper( $region );
	}

	/**
	 * Open Graph locale tags (og:locale + og:locale:alternate) for social sharing,
	 * unless a major SEO plugin already outputs them.
	 */
	public function og_locale_tags(): void {
		if ( \TranslateRocket\Frontend\Preview::hidden() ) {
			return;
		}
		$langs = $this->public_languages();
		if ( count( $langs ) < 2 ) {
			return;
		}
		$current = $this->current_language();
		$out     = '';
		// og:locale is output by SEO plugins (correctly, thanks to our per-request
		// locale); only add it ourselves when none is present.
		if ( ! self::seo_plugin_active() ) {
			$out .= '<meta property="og:locale" content="' . esc_attr( Languages::locale( $current ) ) . '" />' . "\n";
		}
		// og:locale:alternate (the multilingual part) is rarely output by SEO
		// plugins, so we always add one per other language.
		foreach ( $langs as $code ) {
			if ( $code === $current ) {
				continue;
			}
			$out .= '<meta property="og:locale:alternate" content="' . esc_attr( Languages::locale( $code ) ) . '" />' . "\n";
		}
		echo wp_kses( $out , \TranslateRocket\Kses::head_rules() );
	}

	/**
	 * Whether a major SEO plugin is active, so we don't duplicate its OG tags.
	 */
	private static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || class_exists( 'RankMath' ) || class_exists( 'RankMath\Helper' );
	}

	/**
	 * Swap a page's real slug for its translated one in permalinks.
	 *
	 * @param string $link    Permalink.
	 * @param int    $post_id Post id.
	 */
	public function filter_page_link( $link, $post_id ) {
		return $this->swap_slug( (string) $link, (int) $post_id );
	}

	/**
	 * Swap a post's real slug for its translated one in permalinks.
	 *
	 * @param string       $permalink Permalink.
	 * @param \WP_Post|int  $post      Post or id.
	 */
	public function filter_post_link( $permalink, $post ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $this->swap_slug( (string) $permalink, $id );
	}

	/**
	 * Replace the post's real slug segment with the translated slug in a URL.
	 */
	private function swap_slug( string $url, int $post_id ): string {
		if ( $post_id <= 0 || $this->is_default( $this->current_language() ) ) {
			return $url;
		}
		$translated = Slugs::get( $post_id, $this->current_language() );
		if ( '' === $translated ) {
			return $url;
		}
		$post = get_post( $post_id );
		if ( ! $post || '' === (string) $post->post_name ) {
			return $url;
		}

		$needle = '/' . $post->post_name . '/';
		$pos    = strrpos( $url, $needle );
		if ( false !== $pos ) {
			return substr( $url, 0, $pos ) . '/' . $translated . '/' . substr( $url, $pos + strlen( $needle ) );
		}
		$tail = '/' . $post->post_name;
		if ( strlen( $url ) >= strlen( $tail ) && substr( $url, -strlen( $tail ) ) === $tail ) {
			return substr( $url, 0, -strlen( $tail ) ) . '/' . $translated;
		}
		return $url;
	}

	/**
	 * Swap a taxonomy term's slug for its translated one in archive links (the home_url
	 * filter has already added the /xx/ prefix). Auto-derived from the term's translated
	 * name; falls back to the source slug when there's no name translation.
	 *
	 * @param string $url      Term archive URL.
	 * @param object $term     Term object.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function filter_term_link( $url, $term, $taxonomy ) {
		unset( $taxonomy );
		if ( $this->is_default( $this->current_language() ) || ! is_object( $term ) || empty( $term->slug ) ) {
			return $url;
		}
		$tslug = TermSlugs::ensure( (int) $term->term_id, (string) $term->name, $this->current_language() );
		if ( '' === $tslug || $tslug === $term->slug ) {
			return $url;
		}
		return $this->replace_last_segment( (string) $url, (string) $term->slug, $tslug );
	}

	/**
	 * On a term archive, swap the queried term's slug for its translation in a path, so
	 * url_for_language() (hreflang, switcher) matches the translated links.
	 */
	private function maybe_translate_term_path( string $path, string $lang ): string {
		if ( ! function_exists( 'is_category' ) || ! ( is_category() || is_tag() || is_tax() ) ) {
			return $path;
		}
		$term = get_queried_object();
		if ( ! ( $term instanceof \WP_Term ) || empty( $term->slug ) ) {
			return $path;
		}
		// Target slug for this language: the source slug for the default language, the
		// translated slug for a secondary one. The current path's last segment is
		// whichever slug the request used, so we rebuild it to the target.
		$target = (string) $term->slug;
		if ( ! $this->is_default( $lang ) ) {
			$t = TermSlugs::ensure( (int) $term->term_id, (string) $term->name, $lang );
			if ( '' !== $t ) {
				$target = $t;
			}
		}
		$trimmed = rtrim( $path, '/' );
		$cut     = strrpos( $trimmed, '/' );
		$prefix  = ( false !== $cut ) ? substr( $trimmed, 0, $cut + 1 ) : '/';
		return $prefix . $target . '/';
	}

	/**
	 * Replace the last "/from/" (or trailing "/from") path segment with "/to/".
	 */
	private function replace_last_segment( string $url, string $from, string $to ): string {
		$needle = '/' . $from . '/';
		$pos    = strrpos( $url, $needle );
		if ( false !== $pos ) {
			return substr( $url, 0, $pos ) . '/' . $to . '/' . substr( $url, $pos + strlen( $needle ) );
		}
		$tail = '/' . $from;
		if ( strlen( $url ) >= strlen( $tail ) && substr( $url, -strlen( $tail ) ) === $tail ) {
			return substr( $url, 0, -strlen( $tail ) ) . '/' . $to;
		}
		return $url;
	}

	/**
	 * Disable WordPress canonical redirects on secondary languages, where our
	 * own prefix/slug routing owns the URL.
	 *
	 * @param string|false $redirect_url Proposed redirect URL.
	 * @return string|false
	 */
	public function filter_canonical( $redirect_url ) {
		// On secondary languages our prefix/slug routing owns the URL.
		return $this->is_default( $this->current_language() ) ? $redirect_url : false;
	}

	/**
	 * Resolve an incoming translated slug back to the real post.
	 *
	 * @param array $query_vars Parsed query vars.
	 * @return array
	 */
	public function resolve_request( $query_vars ) {
		if ( ! is_array( $query_vars ) || $this->is_default( $this->current_language() ) ) {
			return $query_vars;
		}

		// Term archives: map a translated category/tag slug back to the real one (the
		// source slug still resolves on its own, so no URL ever 404s).
		foreach ( array(
			'category_name' => 'category',
			'tag'           => 'post_tag',
		) as $qv => $tax ) {
			if ( empty( $query_vars[ $qv ] ) ) {
				continue;
			}
			$val  = (string) $query_vars[ $qv ];
			$cut  = strrpos( $val, '/' );
			$last = ( false !== $cut ) ? substr( $val, $cut + 1 ) : $val;
			$real = TermSlugs::resolve( $last, $this->current_language(), $tax );
			if ( '' !== $real && $real !== $last ) {
				$query_vars[ $qv ] = ( false !== $cut ) ? substr( $val, 0, $cut + 1 ) . $real : $real;
			}
		}

		$slug = '';
		if ( ! empty( $query_vars['pagename'] ) ) {
			$slug = basename( (string) $query_vars['pagename'] );
		} elseif ( ! empty( $query_vars['name'] ) ) {
			$slug = (string) $query_vars['name'];
		}
		if ( '' === $slug ) {
			return $query_vars;
		}

		$id = Slugs::resolve( $slug, $this->current_language() );
		if ( ! $id ) {
			return $query_vars;
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return $query_vars;
		}

		// Point the query at the real post, using the right var for its type.
		if ( 'page' === $post->post_type ) {
			$query_vars['pagename'] = get_page_uri( $id );
			unset( $query_vars['name'] );
		} else {
			$query_vars['name']      = $post->post_name;
			$query_vars['post_type'] = $post->post_type;
			unset( $query_vars['pagename'] );
		}
		return $query_vars;
	}
}
