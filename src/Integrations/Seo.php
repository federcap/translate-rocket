<?php
/**
 * SEO plugins: the address of a translated page.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

use TranslateRocket\Plugin;
use TranslateRocket\Languages;
use TranslateRocket\Frontend\Preview;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast and Rank Math build canonical and og:url from the permalink, which we
 * already rewrite (/it/ prefix, translated slug). SEOPress builds both from the
 * raw request — `$wp->request`, the SOURCE slug once we resolved it — so a page
 * at /it/albergo-aurora/ declared /it/hotel-aurora/ as its canonical: Google was
 * told the translated address is a duplicate of one that does not exist. All in
 * One SEO does the same on archives and term pages. Read in SEOPress 8.x
 * (inc/functions/options-titles-metas.php, options-social.php) and AIOSEO 4.9
 * (app/Common/Traits/Helpers/WpUri.php), 28/09/2026.
 *
 * Also lists our multilingual sitemap in their sitemap index (Yoast and Rank
 * Math already had it, see Frontend\Sitemap).
 */
class Seo {

	/**
	 * Hook the filters that exist; each runs only when its plugin calls it.
	 */
	public function boot(): void {
		// SEOPress hands over the whole <link> / <meta> tag.
		add_filter( 'seopress_titles_canonical', array( $this, 'seopress_canonical' ), 20 );
		add_filter( 'seopress_social_og_url', array( $this, 'seopress_og_url' ), 20 );
		add_filter( 'seopress_sitemaps_xml_index', array( $this, 'seopress_index' ), 20 );
		// All in One SEO: '' means «compute it yourself».
		add_filter( 'aioseo_canonical_url', array( $this, 'aioseo_canonical' ), 20 );
		add_filter( 'aioseo_facebook_tags', array( $this, 'aioseo_facebook' ), 20 );
		add_filter( 'aioseo_og_locale', array( $this, 'locale' ), 20 );
		add_filter( 'aioseo_sitemap_indexes', array( $this, 'aioseo_index' ), 20 );
	}

	/**
	 * This page's address in the language being shown, or '' on the default
	 * language (the plugin's own value is right there).
	 */
	private function here(): string {
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( '' === $lang || $router->is_default( $lang ) || \TranslateRocket\Coexistence::on() ) {
			return '';
		}
		return $router->url_for_language( $lang, false );
	}

	/**
	 * Replace the address inside a tag SEOPress built (href or content).
	 *
	 * @param mixed  $tag  The tag HTML.
	 * @param string $attr href|content.
	 * @return mixed
	 */
	private function swap( $tag, string $attr ) {
		$url = $this->here();
		if ( '' === $url || ! is_string( $tag ) || '' === $tag ) {
			return $tag;
		}
		$out = preg_replace( '/(\s' . $attr . '=["\'])[^"\']*(["\'])/i', '${1}' . esc_url( $url ) . '$2', $tag, 1 );
		return is_string( $out ) ? $out : $tag;
	}

	/**
	 * @param mixed $tag <link rel="canonical" href="…">.
	 * @return mixed
	 */
	public function seopress_canonical( $tag ) {
		if ( is_string( $tag ) && false === stripos( $tag, 'rel="canonical"' ) && false === stripos( $tag, "rel='canonical'" ) ) {
			return $tag;
		}
		return $this->swap( $tag, 'href' );
	}

	/**
	 * @param mixed $tag <meta property="og:url" content="…">.
	 * @return mixed
	 */
	public function seopress_og_url( $tag ) {
		if ( is_string( $tag ) && false === stripos( $tag, 'og:url' ) ) {
			return $tag;
		}
		return $this->swap( $tag, 'content' );
	}

	/**
	 * @param mixed $url Canonical URL AIOSEO has so far ('' = none set by hand).
	 * @return mixed
	 */
	public function aioseo_canonical( $url ) {
		if ( ! is_string( $url ) || '' !== $url ) {
			return $url; // A canonical typed by the site owner is theirs.
		}
		$here = $this->here();
		return '' !== $here ? $here : $url;
	}

	/**
	 * @param mixed $tags Open Graph tags, property => content.
	 * @return mixed
	 */
	public function aioseo_facebook( $tags ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}
		$here = $this->here();
		if ( '' !== $here && isset( $tags['og:url'] ) ) {
			$tags['og:url'] = $here;
		}
		if ( isset( $tags['og:locale'] ) ) {
			$tags['og:locale'] = $this->locale( $tags['og:locale'] );
		}
		return $tags;
	}

	/**
	 * og:locale of the language being shown, whatever the site locale is.
	 *
	 * @param mixed $locale Locale so far.
	 * @return mixed
	 */
	public function locale( $locale ) {
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( '' === $lang || Preview::hidden() ) {
			return $locale;
		}
		$mine = Languages::locale( $lang );
		return '' !== $mine ? $mine : $locale;
	}

	/**
	 * Our sitemap in All in One SEO's index.
	 *
	 * @param mixed $indexes Entries, each ['loc' => …, 'lastmod' => …].
	 * @return mixed
	 */
	public function aioseo_index( $indexes ) {
		if ( ! is_array( $indexes ) || Preview::hidden() || empty( \TranslateRocket\Settings::get()['target_languages'] ) ) {
			return $indexes;
		}
		$indexes[] = array(
			'loc'     => home_url( '/translaterocket-sitemap.xml' ),
			'lastmod' => gmdate( 'c' ),
		);
		return $indexes;
	}

	/**
	 * Our sitemap in SEOPress's index XML.
	 *
	 * @param mixed $xml The whole <sitemapindex>.
	 * @return mixed
	 */
	public function seopress_index( $xml ) {
		if ( ! is_string( $xml ) || Preview::hidden() || empty( \TranslateRocket\Settings::get()['target_languages'] ) || false !== strpos( $xml, 'translaterocket-sitemap.xml' ) ) {
			return $xml;
		}
		$entry = "\n<sitemap>\n<loc>" . esc_url( home_url( '/translaterocket-sitemap.xml' ) ) . "</loc>\n<lastmod>" . esc_html( gmdate( 'c' ) ) . "</lastmod>\n</sitemap>";
		$out   = str_replace( '</sitemapindex>', $entry . "\n</sitemapindex>", $xml );
		return $out;
	}
}
