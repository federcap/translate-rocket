<?php
/**
 * Pages that still carry qTranslate, WPGlobus or WP Multilang language markers.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Settings;
use TranslateRocket\Importers\InlineMarkers;

defined( 'ABSPATH' ) || exit;

/**
 * Show only the source-language part of text that holds every language at once.
 *
 * qTranslate-X/XT, WPGlobus and WP Multilang keep all the languages of a post in
 * the same field: "[:en]Welcome[:it]Benvenuti[:]", "{:en}Welcome{:}{:it}…{:}".
 * Their plugin picks the right part when the page is shown. Once it is switched
 * off — which is the whole point of moving to TranslateRocket — WordPress prints
 * the field as it is: every page shows every language one after the other, with
 * the markers in plain sight, the title first (found 22/09/2026, with the real
 * qTranslate-XT 3.16.1: the import worked, and the site was unreadable).
 *
 * Here the source-language part is shown instead, and the engine translates it
 * like any other text; the imported translations are already waiting for it.
 * Nothing is written to the database: the other languages stay in the field,
 * and switching the old plugin back on brings everything back as it was.
 *
 * It stays out of the way:
 * - while any of those plugins is active, they do this job themselves;
 * - in the admin, in the editor and in REST requests, so that no editing screen
 *   ever loads the trimmed text and saves it back over the full one;
 * - on text without markers, which is checked for first, cheaply.
 */
class InlineCleanup {

	/**
	 * Whether a plugin that reads these markers itself is active (decided on first use:
	 * at boot time the other plugins may not be loaded yet).
	 *
	 * @var bool|null
	 */
	private static $altro = null;

	/**
	 * Hook into the public site.
	 */
	public function boot(): void {
		foreach ( array( 'the_title', 'single_post_title', 'the_excerpt', 'get_the_excerpt', 'wp_title', 'nav_menu_item_title', 'widget_title', 'widget_text', 'widget_text_content', 'widget_block_content', 'single_term_title', 'single_cat_title', 'single_tag_title', 'get_the_archive_title', 'option_blogname', 'option_blogdescription' ) as $hook ) {
			add_filter( $hook, array( $this, 'text' ), 0 );
		}
		// Before blocks and shortcodes, so they work on the source part only.
		add_filter( 'the_content', array( $this, 'text' ), 0 );
		add_filter( 'document_title_parts', array( $this, 'title_parts' ), 0 );
		// SEO plugins build <title>, og:title and their JSON-LD from the raw post
		// title, past every filter above (seen with Yoast on 22/09/2026). One pass on
		// the finished page covers them all, including plugins nobody has listed.
		// Priority 2: inside the engine's own buffer (priority 1), so this runs first
		// and the engine only ever sees the cleaned page.
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 2 );
	}

	/**
	 * Start cleaning the finished page.
	 */
	public function start_buffer(): void {
		if ( self::active() ) {
			ob_start( array( $this, 'clean_html' ) );
		}
	}

	/**
	 * The finished page: every marked group reduced to its source-language part.
	 *
	 * Between the markers no "<" and no '"' is allowed: the groups left at this
	 * point sit in an attribute, a JSON string or a title, never across tags (the
	 * post body, which can hold tags, is handled by the content filter). That same
	 * rule is what stops a group without its closing marker — qTranslate can leave
	 * one out — from running on across the rest of the page.
	 *
	 * @param mixed $html Page.
	 * @return mixed
	 */
	public function clean_html( $html ) {
		if ( ! is_string( $html ) || ( false === strpos( $html, '[:' ) && false === strpos( $html, '{:' ) && false === strpos( $html, '<!--:' ) ) ) {
			return $html;
		}
		$codice = '[a-z]{2,3}(?:[_-][a-z0-9]{2,4})?';
		$gruppi = array(
			'/\[:' . $codice . '\][^<"]*?(?:\[:\]|(?=["<])|$)/i',
			'/(?:\{:' . $codice . '\}[^<"]*?\{:\})+/i',
			'/(?:<!--:' . $codice . '-->[^<"]*?<!--:-->)+/i',
		);
		foreach ( $gruppi as $re ) {
			$pulito = preg_replace_callback(
				$re,
				function ( $m ) {
					return (string) $this->text( $m[0] );
				},
				$html
			);
			if ( is_string( $pulito ) ) {
				$html = $pulito;
			}
		}
		return $html;
	}

	/**
	 * Keep only the source-language part of a text.
	 *
	 * @param mixed $text Text.
	 * @return mixed
	 */
	public function text( $text ) {
		if ( ! is_string( $text ) || '' === $text || ! self::active() ) {
			return $text;
		}
		// Cheap test first: most text has no markers at all.
		if ( false === strpos( $text, '[:' ) && false === strpos( $text, '{:' ) && false === strpos( $text, '<!--:' ) ) {
			return $text;
		}
		if ( ! InlineMarkers::ha_lingue( $text ) ) {
			return $text;
		}
		$parti = InlineMarkers::dividi( $text );
		if ( empty( $parti ) ) {
			return $text;
		}
		$sorgente = (string) ( Settings::get()['source_language'] ?? 'en' );
		if ( isset( $parti[ $sorgente ] ) ) {
			return $parti[ $sorgente ];
		}
		// No part in the source language: the first one is still far better than
		// every language at once with the markers showing.
		return (string) reset( $parti );
	}

	/**
	 * The parts of the document title.
	 *
	 * @param mixed $parts Title parts.
	 * @return mixed
	 */
	public function title_parts( $parts ) {
		if ( ! is_array( $parts ) ) {
			return $parts;
		}
		foreach ( $parts as $k => $v ) {
			$parts[ $k ] = $this->text( $v );
		}
		return $parts;
	}

	/**
	 * Whether to act on this request at all.
	 */
	private static function active(): bool {
		if ( is_admin() && ! \TranslateRocket\Router::is_frontend_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( null === self::$altro ) {
			self::$altro = defined( 'QTRANSLATE_DIR' ) || function_exists( 'qtranxf_getLanguage' ) || function_exists( 'qtrans_getLanguage' )
				|| defined( 'WPGLOBUS_VERSION' ) || class_exists( 'WPGlobus', false )
				|| defined( 'WPM_VERSION' );
		}
		return ! self::$altro;
	}
}
