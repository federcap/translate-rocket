<?php
/**
 * Text of small HTML fragments (form messages, AJAX-loaded forms, e-mail bodies).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and rewrites the visible text of an HTML fragment that never passes
 * through the page engine — a message returned by AJAX, a form loaded by AJAX,
 * an e-mail body — with the site's existing translations only (no AI call).
 * Defensive: any parse problem returns the fragment unchanged.
 */
class HtmlText {

	/**
	 * Attributes a visitor reads (never value, name, href…).
	 */
	const ATTRIBUTES = array( 'placeholder', 'title', 'aria-label', 'alt' );

	/**
	 * Translate a fragment's text nodes (and, optionally, its readable attributes)
	 * with a single batch lookup.
	 *
	 * @param string $html  HTML fragment (or plain text).
	 * @param string $lang  Target language.
	 * @param bool   $attrs Also translate placeholder/title/aria-label/alt.
	 */
	public static function translate( string $html, string $lang, bool $attrs = false ): string {
		if ( '' === trim( $html ) || '' === $lang ) {
			return $html;
		}
		if ( ! self::has_markup( $html ) ) {
			$t   = trim( $html );
			$map = Strings::translate_texts( array( $t ), $lang );
			return isset( $map[ $t ] ) ? str_replace( $t, self::safe( $map[ $t ] ), $html ) : $html;
		}

		$doc = self::open( $html );
		if ( null === $doc ) {
			return $html;
		}
		$nodes      = self::text_nodes( $doc );
		$attr_nodes = $attrs ? self::attribute_nodes( $doc ) : array();

		$candidates = array();
		foreach ( array_merge( $nodes, $attr_nodes ) as $node ) {
			$candidates[] = trim( (string) $node->nodeValue );
		}
		$map = Strings::translate_texts( $candidates, $lang );
		if ( empty( $map ) ) {
			return $html;
		}

		$changed = false;
		foreach ( $nodes as $node ) {
			$val = (string) $node->nodeValue;
			$t   = trim( $val );
			if ( '' !== $t && isset( $map[ $t ] ) && $map[ $t ] !== $t ) {
				$node->nodeValue = str_replace( $t, $map[ $t ], $val );
				$changed         = true;
			}
		}
		foreach ( $attr_nodes as $node ) {
			$t = trim( (string) $node->nodeValue );
			if ( '' !== $t && isset( $map[ $t ] ) && $map[ $t ] !== $t && $node->parentNode instanceof \DOMElement ) {
				// setAttribute keeps ampersands and quotes intact.
				$node->parentNode->setAttribute( $node->nodeName, $map[ $t ] );
				$changed = true;
			}
		}
		return $changed ? self::close( $doc, $html ) : $html;
	}

	/**
	 * Rewrite text nodes through a callback.
	 *
	 * The callback receives the trimmed text and the lower-case name of the parent
	 * element, and returns the replacement text or null to leave the node alone.
	 *
	 * @param string   $html HTML fragment.
	 * @param callable $fn   function( string $text, string $parent_tag ): ?string.
	 */
	public static function rewrite( string $html, callable $fn ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}
		if ( ! self::has_markup( $html ) ) {
			$t   = trim( $html );
			$new = $fn( $t, '' );
			return ( is_string( $new ) && $new !== $t ) ? str_replace( $t, self::safe( $new ), $html ) : $html;
		}
		$doc = self::open( $html );
		if ( null === $doc ) {
			return $html;
		}
		$changed = false;
		foreach ( self::text_nodes( $doc ) as $node ) {
			$val = (string) $node->nodeValue;
			$t   = trim( $val );
			if ( '' === $t ) {
				continue;
			}
			$tag = $node->parentNode instanceof \DOMElement ? strtolower( $node->parentNode->nodeName ) : '';
			$new = $fn( $t, $tag );
			if ( is_string( $new ) && $new !== $t ) {
				$node->nodeValue = str_replace( $t, $new, $val );
				$changed         = true;
			}
		}
		return $changed ? self::close( $doc, $html ) : $html;
	}

	/**
	 * The trimmed, readable text segments of a fragment (for string collection).
	 *
	 * @param string $html  HTML fragment or plain text.
	 * @param bool   $attrs Also return the readable attributes (placeholder, title, aria-label, alt).
	 * @return string[]
	 */
	public static function segments( string $html, bool $attrs = false ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}
		if ( ! self::has_markup( $html ) ) {
			return array( trim( $html ) );
		}
		$doc = self::open( $html );
		if ( null === $doc ) {
			return array();
		}
		$out   = array();
		$nodes = $attrs ? array_merge( self::text_nodes( $doc ), self::attribute_nodes( $doc ) ) : self::text_nodes( $doc );
		foreach ( $nodes as $node ) {
			$t = trim( (string) $node->nodeValue );
			if ( '' !== $t ) {
				$out[] = $t;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether the string needs the HTML parser at all.
	 */
	private static function has_markup( string $html ): bool {
		return false !== strpos( $html, '<' ) || false !== strpos( $html, '&' );
	}

	/**
	 * A stored translation that is written straight into an HTML sink (the
	 * plain-text path never goes through the DOM, which escapes by itself).
	 * Same rules as post content: formatting stays, scripts do not.
	 */
	private static function safe( string $text ): string {
		return function_exists( 'wp_kses_post' ) ? wp_kses_post( $text ) : htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Parse a fragment inside a wrapper element, with <script>/<style>/<textarea>
	 * bodies masked first: libxml drops any "</tag>" it finds inside them (the same
	 * protection the page engine applies — see Engine::mask_raw_text()).
	 *
	 * @return array{dom:\DOMDocument,scope:\DOMElement,raw:array<string,string>}|null
	 */
	private static function open( string $html ): ?array {
		if ( ! class_exists( '\DOMDocument' ) ) {
			return null;
		}
		$raw    = array();
		$salt   = dechex( wp_rand( 0x10000000, 0x7fffffff ) );
		$masked = preg_replace_callback(
			'#(<(script|style|textarea)\b[^>]*>)((?:[^<]++|<(?!/\2\s*+>))*+)(</\2\s*>)#i',
			function ( $m ) use ( &$raw, $salt ) {
				if ( '' === $m[3] ) {
					return $m[0];
				}
				$token         = 'TRROCKETRAW' . $salt . count( $raw ) . 'END';
				$raw[ $token ] = $m[3];
				return $m[1] . $token . $m[4];
			},
			$html
		);
		if ( ! is_string( $masked ) ) {
			return null;
		}

		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$ok   = $dom->loadHTML( '<?xml encoding="utf-8" ?><div id="trr-frag">' . $masked . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return null;
		}
		$xpath = new \DOMXPath( $dom );
		$scope = $xpath->query( '//*[@id="trr-frag"]' )->item( 0 );
		if ( ! $scope instanceof \DOMElement ) {
			return null;
		}
		// A stray "</div>" in the fragment closes the wrapper early and the rest
		// lands beside it: serializing the wrapper alone would cut the fragment.
		// Better untranslated than truncated.
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body || 1 !== $body->childNodes->length ) {
			return null;
		}
		return array(
			'dom'   => $dom,
			'xpath' => $xpath,
			'scope' => $scope,
			'raw'   => $raw,
		);
	}

	/**
	 * Readable text nodes of a parsed fragment.
	 *
	 * @param array $doc Result of open().
	 * @return \DOMNode[]
	 */
	private static function text_nodes( array $doc ): array {
		$found = $doc['xpath']->query( './/text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::textarea)]', $doc['scope'] );
		return $found ? iterator_to_array( $found ) : array();
	}

	/**
	 * Readable attribute nodes of a parsed fragment.
	 *
	 * @param array $doc Result of open().
	 * @return \DOMNode[]
	 */
	private static function attribute_nodes( array $doc ): array {
		$q = array();
		foreach ( self::ATTRIBUTES as $a ) {
			$q[] = './/@' . $a;
		}
		$found = $doc['xpath']->query( implode( '|', $q ), $doc['scope'] );
		return $found ? iterator_to_array( $found ) : array();
	}

	/**
	 * Serialize the wrapper's children back to a fragment and restore the masked bodies.
	 *
	 * @param array  $doc      Result of open().
	 * @param string $fallback Original fragment.
	 */
	private static function close( array $doc, string $fallback ): string {
		$out = '';
		foreach ( $doc['scope']->childNodes as $child ) {
			// Node serialization keeps raw UTF-8 (see the note in Engine::process).
			$out .= $doc['dom']->saveHTML( $child );
		}
		if ( '' === $out ) {
			return $fallback;
		}
		return empty( $doc['raw'] ) ? $out : strtr( $out, $doc['raw'] );
	}
}
