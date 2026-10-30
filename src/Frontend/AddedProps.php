<?php
/**
 * Properties added for one language (1.8.30, 29/10/2026, Federico: «in the photos I asked for alt, title…, and every
 * element, buttons too, must have its properties»).
 *
 * The normal translation needs a text to start from: an image without alt, a link without title had nothing to
 * translate, and the editor showed nothing to fill. Here a language gets its own alt, title or ARIA label for an
 * element that has none in the original. They are kept apart from the sentences (one option), so they never count as
 * «sentences to translate» and no engine ever rewrites them.
 *
 * The element is recognised by what stays the same in every language: an image by its file (without the size the
 * theme asks for), a link or a button by its original text (or, with no text, by where the link goes).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Per-language alt / title / aria-label for elements that have none in the original.
 */
class AddedProps {

	const OPTION = 'trrocket_added_props';

	/**
	 * The properties a person can add.
	 */
	const ATTRS = array( 'alt', 'title', 'aria-label' );

	/**
	 * Temporary mark put on the elements while the page is translated.
	 */
	const MARK = 'data-trr-anc';

	/**
	 * Read once per request.
	 *
	 * @var array<string,array<string,array<string,string>>>|null
	 */
	private static $all = null;

	/**
	 * Everything saved: [lang][anchor][attr] = value.
	 *
	 * @return array<string,array<string,array<string,string>>>
	 */
	public static function all(): array {
		if ( null === self::$all ) {
			$v         = get_option( self::OPTION, array() );
			self::$all = is_array( $v ) ? $v : array();
		}
		return self::$all;
	}

	/**
	 * The properties of one language.
	 *
	 * @param string $lang Language.
	 * @return array<string,array<string,string>>
	 */
	public static function for_lang( string $lang ): array {
		$all = self::all();
		return isset( $all[ $lang ] ) && is_array( $all[ $lang ] ) ? $all[ $lang ] : array();
	}

	/**
	 * Save the properties of one element in one language (empty value = removed).
	 *
	 * @param string               $lang   Language.
	 * @param string               $anchor The element.
	 * @param array<string,string> $props  attr => value.
	 * @return array<string,string> What is now saved for the element.
	 */
	public static function save( string $lang, string $anchor, array $props ): array {
		$all = self::all();
		$cur = isset( $all[ $lang ][ $anchor ] ) && is_array( $all[ $lang ][ $anchor ] ) ? $all[ $lang ][ $anchor ] : array();
		foreach ( self::ATTRS as $a ) {
			if ( ! array_key_exists( $a, $props ) ) {
				continue;
			}
			$v = trim( (string) $props[ $a ] );
			if ( '' === $v ) {
				unset( $cur[ $a ] );
			} else {
				$cur[ $a ] = $v;
			}
		}
		if ( empty( $cur ) ) {
			unset( $all[ $lang ][ $anchor ] );
			if ( empty( $all[ $lang ] ) ) {
				unset( $all[ $lang ] );
			}
		} else {
			$all[ $lang ][ $anchor ] = $cur;
		}
		update_option( self::OPTION, $all, false );
		self::$all = $all;
		return $cur;
	}

	/**
	 * Clean a value typed in the editor.
	 *
	 * @param mixed $v Value.
	 */
	public static function clean_value( $v ): string {
		return mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, 300 );
	}

	/**
	 * Clean an anchor sent back by the editor.
	 *
	 * @param mixed $v Value.
	 */
	public static function clean_anchor( $v ): string {
		$v = trim( wp_strip_all_tags( (string) $v ) );
		return preg_match( '/^(img:|a:|a@|button:|iframe:)./u', $v ) ? mb_substr( $v, 0, 300 ) : '';
	}

	/**
	 * How the element is recognised in every language ('' = it cannot be).
	 *
	 * @param \DOMElement $el Element, still with its original text and address.
	 */
	public static function anchor( \DOMElement $el ): string {
		$tag = strtolower( $el->tagName );
		if ( 'img' === $tag ) {
			$src = trim( (string) $el->getAttribute( 'src' ) );
			if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
				foreach ( array( 'data-src', 'data-lazy-src', 'data-orig-src' ) as $alt ) {
					if ( '' !== trim( (string) $el->getAttribute( $alt ) ) ) {
						$src = trim( (string) $el->getAttribute( $alt ) );
						break;
					}
				}
			}
			$path = (string) wp_parse_url( $src, PHP_URL_PATH );
			if ( '' === $path ) {
				return '';
			}
			// The same photo at another size, or the «-scaled» copy WordPress makes, is the same photo.
			$path = (string) preg_replace( '/(-\d{2,5}x\d{2,5}|-scaled)(?=\.[a-z0-9]{2,5}$)/i', '', $path );
			return 'img:' . strtolower( $path );
		}
		if ( 'iframe' === $tag ) {
			$src = (string) $el->getAttribute( 'src' );
			$h   = (string) wp_parse_url( $src, PHP_URL_HOST );
			$p   = (string) wp_parse_url( $src, PHP_URL_PATH );
			return '' === $h . $p ? '' : 'iframe:' . strtolower( $h . $p );
		}
		$text = 'input' === $tag ? (string) $el->getAttribute( 'value' ) : (string) $el->textContent;
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' !== $text ) {
			return ( 'a' === $tag ? 'a:' : 'button:' ) . mb_substr( $text, 0, 160 );
		}
		if ( 'a' === $tag ) {
			$href = trim( (string) $el->getAttribute( 'href' ) );
			$path = (string) wp_parse_url( $href, PHP_URL_PATH );
			$host = (string) wp_parse_url( $href, PHP_URL_HOST );
			$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$dove = ( '' !== $host && $host !== $home ? $host : '' ) . $path;
			return '' === $dove ? '' : 'a@' . mb_substr( $dove, 0, 200 );
		}
		return '';
	}

	/**
	 * Before anything is translated: mark the elements that can have properties (all of them while editing, so the
	 * editor can add one; otherwise only the ones that have some in this language).
	 *
	 * @param \DOMXPath $xpath   The page.
	 * @param string    $skip_el XPath predicate of the elements to leave alone.
	 * @param string    $lang    Language of the page.
	 * @param bool      $editing The visual editor is open.
	 * @return bool Something was marked.
	 */
	public static function mark( \DOMXPath $xpath, string $skip_el, string $lang, bool $editing ): bool {
		$mine = self::for_lang( $lang );
		if ( ! $editing && empty( $mine ) ) {
			return false;
		}
		$q     = "//*[self::img or self::a[@href] or self::button or self::iframe or self::input[@type='submit' or @type='button']][not({$skip_el})]";
		$nodes = $xpath->query( $q );
		$any   = false;
		foreach ( $nodes ? $nodes : array() as $el ) {
			if ( ! $el instanceof \DOMElement ) {
				continue;
			}
			$a = self::anchor( $el );
			if ( '' === $a || ( ! $editing && ! isset( $mine[ $a ] ) ) ) {
				continue;
			}
			$el->setAttribute( self::MARK, rawurlencode( $a ) );
			$any = true;
		}
		return $any;
	}

	/**
	 * After the translation: put the properties on the marked elements. An attribute the element already has (the
	 * original's, translated) is never overwritten. While editing, the element keeps its anchor and what was added,
	 * for the editor.
	 *
	 * @param \DOMXPath $xpath   The page.
	 * @param string    $lang    Language.
	 * @param bool      $editing The visual editor is open.
	 * @return bool The page changed.
	 */
	public static function apply( \DOMXPath $xpath, string $lang, bool $editing ): bool {
		$mine  = self::for_lang( $lang );
		$nodes = $xpath->query( '//*[@' . self::MARK . ']' );
		$done  = false;
		foreach ( $nodes ? $nodes : array() as $el ) {
			if ( ! $el instanceof \DOMElement ) {
				continue;
			}
			$a = rawurldecode( (string) $el->getAttribute( self::MARK ) );
			$el->removeAttribute( self::MARK );
			$done  = true;
			$props = isset( $mine[ $a ] ) && is_array( $mine[ $a ] ) ? $mine[ $a ] : array();
			$added = array();
			foreach ( $props as $attr => $val ) {
				if ( ! in_array( $attr, self::ATTRS, true ) || '' === trim( (string) $val ) ) {
					continue;
				}
				if ( '' !== trim( (string) $el->getAttribute( $attr ) ) && ! $editing ) {
					continue;
				}
				// While editing, an attribute that came from the original stays (the editor edits it as a translation).
				if ( $editing && '' !== trim( (string) $el->getAttribute( $attr ) ) && false !== strpos( ',' . $el->getAttribute( 'data-trr-attrs' ) . ',', ',' . $attr . ',' ) ) {
					continue;
				}
				$el->setAttribute( $attr, (string) $val );
				$added[ $attr ] = (string) $val;
			}
			if ( $editing ) {
				$el->setAttribute( 'data-trr-anchor', $a );
				$el->setAttribute( 'data-trr-props', (string) wp_json_encode( (object) $added ) );
			}
		}
		return $done;
	}
}
