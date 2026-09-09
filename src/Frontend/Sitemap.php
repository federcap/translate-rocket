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
 * Serves /translaterocket-sitemap.xml: one entry per public page/post, each with
 * <xhtml:link> hreflang alternates for every language (+ x-default), so search
 * engines discover and correctly pair every language version. Plugin-independent,
 * so it works alongside any SEO plugin's own sitemap.
 */
class Sitemap {

	const QV  = 'trrocket_sitemap';
	const MAX = 2000;

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
		add_filter( 'wpseo_sitemap_index', array( $this, 'index_entry' ) );
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
		return $xml
			. "\t<sitemap>\n"
			. "\t\t<loc>" . esc_url( home_url( '/translaterocket-sitemap.xml' ) ) . "</loc>\n"
			. "\t\t<lastmod>" . esc_html( gmdate( 'c' ) ) . "</lastmod>\n"
			. "\t</sitemap>\n";
	}

	public function add_rewrite(): void {
		add_rewrite_rule( '^translaterocket-sitemap\.xml$', 'index.php?' . self::QV . '=1', 'top' );
	}

	/**
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function query_var( $vars ) {
		$vars[] = self::QV;
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
		$req     = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$is_path = ( false !== strpos( (string) strtok( $req, '?' ), 'translaterocket-sitemap.xml' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === (string) get_query_var( self::QV ) && empty( $_GET[ self::QV ] ) && ! $is_path ) {
			return;
		}
		if ( Preview::hidden() ) {
			// The multilingual sitemap doesn't exist for the public yet.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		$this->render();
		exit;
	}

	/**
	 * Build and print the XML.
	 */
	private function render(): void {
		$router    = Plugin::instance()->router();
		$source    = $router->default_language();
		// Una lingua non ancora pubblicata non entra nella sitemap: invitare
		// Google su pagine mezze tradotte e' peggio che non invitarlo affatto.
		$langs     = $router->public_languages();
		$home      = rtrim( (string) home_url( '/' ), '/' );

		if ( ! headers_sent() ) {
			// The request resolves to no post object, so WordPress will have set a
			// 404. Override it: this is a valid document, and crawlers ignore a
			// sitemap served with a 404 status.
			status_header( 200 );
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}

		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		// Home page first.
		$out .= $this->entry( 0, $home . '/', $langs, $source, $home, '' );

		$ids = get_posts(
			array(
				'post_type'      => $this->post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$front = (int) get_option( 'page_on_front' );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id === $front ) {
				continue; // Already covered by the home entry.
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
	 * One <url> block: source loc + hreflang alternates for every language.
	 *
	 * @param string[] $langs  All language codes (source first).
	 */
	private function entry( int $post_id, string $src_url, array $langs, string $source, string $home, string $lastmod ): string {
		$row = '<url><loc>' . esc_url( $src_url ) . '</loc>';
		if ( '' !== $lastmod ) {
			$row .= '<lastmod>' . esc_html( $lastmod ) . '</lastmod>';
		}
		foreach ( $langs as $lang ) {
			// A language this page is excluded from (redirect / 404 / custom message)
			// is not a real alternate — advertising it would send crawlers to a
			// redirect or a 404.
			if ( $post_id > 0 && $lang !== $source
				&& '' !== (string) ( \TranslateRocket\Exclusions::get( $post_id, (string) $lang )['mode'] ?? '' ) ) {
				continue;
			}
			$href = $this->lang_url( $post_id, $src_url, (string) $lang, $source, $home );
			$row .= '<xhtml:link rel="alternate" hreflang="' . esc_attr( $this->hreflang( (string) $lang ) ) . '" href="' . esc_url( $href ) . '"/>';
		}
		$row .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . esc_url( $src_url ) . '"/>';
		$row .= '</url>' . "\n";
		return $row;
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

		// Swap the post's own slug for its translated one, if any.
		if ( $post_id > 0 ) {
			$tslug = Slugs::get( $post_id, $lang );
			if ( '' !== $tslug ) {
				$trimmed = rtrim( $path, '/' );
				$pos     = strrpos( $trimmed, '/' );
				if ( false !== $pos ) {
					$path = substr( $trimmed, 0, $pos + 1 ) . $tslug . '/';
				}
			}
		}
		return $home . '/' . $lang . $path;
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
	private function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}
}
