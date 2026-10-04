<?php
/**
 * A piece of HTML translated on its own.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * The page engine works on whole pages. A fragment — the HTML a «Load more»
 * button fetches, a filtered product grid, a mini-cart, an e-mail body — has no
 * page around it: its text nodes and the same visible attributes are looked up
 * in the language's memory and swapped, nothing else is touched, and on any
 * parse problem the fragment comes back exactly as it was.
 */
class Fragment {

	/**
	 * Attributes swapped in a fragment (the page engine's short list).
	 */
	const ATTRIBUTES = array( 'alt', 'title', 'placeholder', 'aria-label', 'data-title', 'data-text' );

	/**
	 * When an array, the texts met while translating are added to it (RestContent collects what a translated
	 * page loads from the REST API); null otherwise.
	 *
	 * @var string[]|null
	 */
	public static $seen = null;

	/**
	 * @param string $html Fragment or whole document.
	 * @param string $lang Target language.
	 */
	public static function translate( string $html, string $lang ): string {
		if ( '' === trim( $html ) || '' === $lang || ! class_exists( '\DOMDocument' ) || false === strpos( $html, '<' ) ) {
			return self::text( $html, $lang );
		}
		$is_doc = ( false !== stripos( $html, '<body' ) || false !== stripos( $html, '<html' ) );

		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$load = '<?xml encoding="utf-8" ?>' . ( $is_doc ? $html : '<div id="trr-frag">' . $html . '</div>' );
		$ok   = $dom->loadHTML( $load, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return $html;
		}

		$xpath = new \DOMXPath( $dom );
		$scope = $is_doc ? $xpath->query( '//body' )->item( 0 ) : $xpath->query( '//*[@id="trr-frag"]' )->item( 0 );
		if ( null === $scope ) {
			return $html;
		}

		$texts = $xpath->query( './/text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::textarea)]', $scope );
		$nodes = $texts ? iterator_to_array( $texts ) : array();
		$attrs = array();
		foreach ( self::ATTRIBUTES as $attr ) {
			$found = $xpath->query( './/*[@' . $attr . ']', $scope );
			foreach ( $found ? $found : array() as $el ) {
				$attrs[] = array( $el, $attr );
			}
		}
		$inputs = $xpath->query( ".//input[@value][translate(@type,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='submit' or translate(@type,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='button']", $scope );
		foreach ( $inputs ? $inputs : array() as $el ) {
			$attrs[] = array( $el, 'value' );
		}

		$candidates = array();
		foreach ( $nodes as $node ) {
			$candidates[] = trim( (string) $node->nodeValue );
			// «Add to cart: “Blue mug”»: the quoted name may be known when the sentence is not.
			foreach ( Engine::quoted( (string) $node->nodeValue ) as $q ) {
				$candidates[] = $q;
			}
		}
		foreach ( $attrs as $pair ) {
			$candidates[] = trim( (string) $pair[0]->getAttribute( $pair[1] ) );
			foreach ( Engine::quoted( (string) $pair[0]->getAttribute( $pair[1] ) ) as $q ) {
				$candidates[] = $q;
			}
		}
		$candidates = array_values( array_unique( array_filter( $candidates, 'strlen' ) ) );
		if ( empty( $candidates ) ) {
			return $html;
		}
		if ( is_array( self::$seen ) ) {
			foreach ( $nodes as $node ) {
				self::$seen[] = trim( (string) $node->nodeValue );
			}
			foreach ( $attrs as $pair ) {
				self::$seen[] = trim( (string) $pair[0]->getAttribute( $pair[1] ) );
			}
		}
		$map = Strings::translate_texts( $candidates, $lang );
		if ( empty( $map ) ) {
			return $html;
		}

		$changed = false;
		foreach ( $nodes as $node ) {
			$val = (string) $node->nodeValue;
			$t   = trim( $val );
			if ( '' === $t ) {
				continue;
			}
			$new = ( isset( $map[ $t ] ) && '' !== $map[ $t ] ) ? $map[ $t ] : Engine::with_quoted( $t, $map );
			if ( null !== $new && $new !== $t ) {
				$node->nodeValue = str_replace( $t, $new, $val );
				$changed         = true;
			}
		}
		foreach ( $attrs as $pair ) {
			list( $el, $attr ) = $pair;
			$t = trim( (string) $el->getAttribute( $attr ) );
			if ( '' === $t ) {
				continue;
			}
			$new = ( isset( $map[ $t ] ) && '' !== $map[ $t ] ) ? $map[ $t ] : Engine::with_quoted( $t, $map );
			if ( null !== $new && $new !== $t ) {
				$el->setAttribute( $attr, $new );
				$changed = true;
			}
		}
		// Internal links: to the same language as the page that asked (the home_url
		// filter stays off on admin-ajax, so they arrived in the source language).
		$links = $xpath->query( ".//a[@href][not(ancestor-or-self::*[contains(@class,'trrocket')])]", $scope );
		if ( $links && $links->length ) {
			$router    = \TranslateRocket\Plugin::instance()->router();
			$raw_home  = (string) get_option( 'home' );
			$home_host = (string) wp_parse_url( $raw_home, PHP_URL_HOST );
			$home_path = rtrim( (string) wp_parse_url( $raw_home, PHP_URL_PATH ), '/' );
			foreach ( $links as $a ) {
				$href = (string) $a->getAttribute( 'href' );
				$new  = Engine::localize_link( $href, $home_host, $home_path, $lang, $router->secondary_languages() );
				if ( $new !== $href ) {
					$a->setAttribute( 'href', $new );
					$changed = true;
				}
			}
		}
		if ( ! $changed ) {
			return $html;
		}

		if ( $is_doc ) {
			$out = (string) $dom->saveHTML( $dom->documentElement );
			if ( null !== $dom->doctype && '' !== $out ) {
				$out = '<!DOCTYPE ' . $dom->doctype->name . '>' . "\n" . $out;
			}
			return '' !== $out ? $out : $html;
		}
		$out = '';
		foreach ( $scope->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		return '' !== $out ? $out : $html;
	}

	/**
	 * A plain string (no markup): the whole of it, when the memory knows it.
	 *
	 * @param string $text Text.
	 * @param string $lang Target language.
	 */
	public static function text( string $text, string $lang ): string {
		$t = trim( $text );
		if ( '' === $t || '' === $lang || ! preg_match( '/\p{L}/u', $t ) ) {
			return $text;
		}
		if ( is_array( self::$seen ) ) {
			self::$seen[] = $t;
		}
		$map = Strings::translate_texts( array( $t ), $lang );
		return ( isset( $map[ $t ] ) && '' !== $map[ $t ] ) ? str_replace( $t, $map[ $t ], $text ) : $text;
	}
}
