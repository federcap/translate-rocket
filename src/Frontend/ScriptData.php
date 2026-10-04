<?php
/**
 * Words handed to JavaScript through wp_localize_script().
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * A theme or plugin gives its scripts the sentences they will show later —
 * «Product was successfully added to your cart.», «View cart», «Loading…» —
 * through wp_localize_script(), which prints them as
 *
 *     <script id="handle-js-extra">var handle_settings = {"added":"…"};</script>
 *
 * They pass through gettext, so a language pack translates them; a paid theme
 * (WoodMart, Flatsome, Avada…) ships no packs, and the sentence stays in the
 * source language on every translated page (seen on the WoodMart demo,
 * 29/09/2026). The engine reads those blocks like the structured data: the
 * decoded values, never the raw text, and writes back only what changed.
 */
class ScriptData {

	/**
	 * Keys whose value is never a sentence for the visitor.
	 */
	const SKIP_KEYS = array( 'ajaxurl', 'ajax_url', 'url', 'urls', 'nonce', 'key', 'id', 'ids', 'version', 'ver', 'locale', 'lang', 'language', 'currency', 'symbol', 'format', 'date_format', 'time_format', 'template', 'selector', 'class', 'classes', 'hash', 'token', 'rest_url', 'root', 'home', 'home_url', 'site_url', 'path', 'src', 'href', 'action', 'method', 'type', 'name', 'slug', 'code', 'regex', 'pattern', 'mask', 'decimal', 'thousand', 'thousand_sep', 'decimal_sep', 'user_agent', 'cookie', 'storage_key' );

	/**
	 * Translatable strings in a block, in page order.
	 *
	 * @param string $js Script body.
	 * @return string[]
	 */
	public static function strings( string $js ): array {
		$out = array();
		foreach ( self::vars( $js ) as $var ) {
			self::walk( $var['data'], $out );
		}
		foreach ( self::literals( $js ) as $lit ) {
			$out[] = $lit['text'];
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Slider and carousel options written as a JavaScript object, not JSON: MetaSlider (900k sites) prints
	 * «prevText:"Previous", nextText:"Next"» for FlexSlider in its own inline block, so the arrows kept the
	 * source language on every translated page (3/10/2026). Only these known keys are read — a script is
	 * code, and a value under any other name could be anything.
	 */
	const LITERAL_KEYS = 'prevText|nextText|prevSlideMessage|nextSlideMessage|firstSlideMessage|lastSlideMessage|paginationBulletMessage';

	/**
	 * The known option values in a script, with their byte range (quotes excluded).
	 *
	 * @param string $js Script body.
	 * @return array<int,array{start:int,end:int,text:string,quote:string}>
	 */
	private static function literals( string $js ): array {
		$found = array();
		$re = '/(?<![\w$])["\']?(?:' . self::LITERAL_KEYS . ')["\']?\s*:\s*(?:"((?:[^"\\\\\n]|\\\\.)*)"|\'((?:[^\'\\\\\n]|\\\\.)*)\')/';
		if ( ! preg_match_all( $re, $js, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $found;
		}
		foreach ( $m as $set ) {
			$single = isset( $set[2] ) && -1 !== $set[2][1];
			$hit    = $single ? $set[2] : $set[1];
			$quote  = $single ? "'" : '"';
			$raw    = $hit[0];
			$text  = '"' === $quote ? json_decode( '"' . $raw . '"' ) : stripcslashes( $raw );
			if ( ! is_string( $text ) || ! preg_match( '/\p{L}/u', $text ) || ! self::is_text( 'text', $text ) ) {
				continue;
			}
			$found[] = array(
				'start' => $hit[1],
				'end'   => $hit[1] + strlen( $raw ),
				'text'  => $text,
				'quote' => $quote,
			);
		}
		return $found;
	}

	/**
	 * The script with its known option values translated, or null when nothing changed.
	 *
	 * @param string               $js  Script body.
	 * @param array<string,string> $map Source text => translation.
	 */
	private static function translate_literals( string $js, array $map ): ?string {
		$out    = '';
		$cursor = 0;
		$any    = false;
		foreach ( self::literals( $js ) as $lit ) {
			$t = $lit['text'];
			if ( ! isset( $map[ $t ] ) || '' === $map[ $t ] || $map[ $t ] === $t ) {
				continue;
			}
			$enc  = '"' === $lit['quote'] ? substr( (string) wp_json_encode( $map[ $t ] ), 1, -1 ) : addcslashes( $map[ $t ], "'\\\n\r" );
			$out .= substr( $js, $cursor, $lit['start'] - $cursor ) . $enc;
			$cursor = $lit['end'];
			$any    = true;
		}
		return $any ? $out . substr( $js, $cursor ) : null;
	}

	/**
	 * Translatable strings in an already decoded document (an Elementor widget's
	 * «data-settings» attribute), with the same rules as a script's data.
	 *
	 * @param array $data Decoded JSON.
	 * @return string[]
	 */
	public static function json_strings( array $data ): array {
		$out = array();
		self::walk( $data, $out );
		return array_values( array_unique( $out ) );
	}

	/**
	 * The decoded document with its translatable strings swapped, or null when nothing changed.
	 *
	 * @param array                $data Decoded JSON.
	 * @param array<string,string> $map  Source text => translation.
	 */
	public static function json_translate( array $data, array $map ): ?array {
		$changed = false;
		$out     = self::apply( $data, $map, $changed );
		return $changed && is_array( $out ) ? $out : null;
	}

	/**
	 * The block with its translatable strings swapped, or null when nothing changed.
	 *
	 * @param string               $js  Script body.
	 * @param array<string,string> $map Source text => translation.
	 */
	public static function translate( string $js, array $map ): ?string {
		$done = self::translate_vars( $js, $map );
		$lit  = self::translate_literals( null === $done ? $js : $done, $map );
		return null !== $lit ? $lit : $done;
	}

	/**
	 * The block with the strings of its «var x = {…}» data swapped, or null when nothing changed.
	 *
	 * @param string               $js  Script body.
	 * @param array<string,string> $map Source text => translation.
	 */
	private static function translate_vars( string $js, array $map ): ?string {
		$vars = self::vars( $js );
		if ( empty( $vars ) ) {
			return null;
		}
		$out    = '';
		$cursor = 0;
		$any    = false;
		foreach ( $vars as $var ) {
			$changed = false;
			$data    = self::apply( $var['data'], $map, $changed );
			$out    .= substr( $js, $cursor, $var['start'] - $cursor );
			if ( $changed ) {
				// Same encoder WP_Scripts::localize() used: the value stays valid JavaScript.
				$json = wp_json_encode( $data );
				$out .= is_string( $json ) ? $json : substr( $js, $var['start'], $var['end'] - $var['start'] );
				$any  = $any || is_string( $json );
			} else {
				$out .= substr( $js, $var['start'], $var['end'] - $var['start'] );
			}
			$cursor = $var['end'];
		}
		$out .= substr( $js, $cursor );
		return $any ? $out : null;
	}

	/**
	 * Every «var name = {…};» / «[…]» in the block, with the JSON's byte range.
	 *
	 * The closing brace is found by walking the text (strings and escapes
	 * respected), never with a regular expression: a value can hold «};».
	 *
	 * @param string $js Script body.
	 * @return array<int,array{start:int,end:int,data:mixed}>
	 */
	private static function vars( string $js ): array {
		$found = array();
		// «var» is what wp_localize_script() prints; a theme's own inline block says
		// «const», «let» or «window.name = {…}» just as often (29/09/2026).
		if ( ! preg_match_all( '/(?:\b(?:var|let|const)\s+|\bwindow\.)([A-Za-z_$][\w$]*)\s*=\s*(?=[\[{])/', $js, $m, PREG_OFFSET_CAPTURE ) ) {
			return $found;
		}
		foreach ( $m[0] as $i => $hit ) {
			// TranslateRocket's own objects (trrocketVE: the visual editor's labels) are the
			// administrator's interface, in the administrator's language — never page content.
			// On /it/ the editor's «Save» had become «Salva» (30/09/2026).
			if ( 0 === stripos( (string) $m[1][ $i ][0], 'trrocket' ) ) {
				continue;
			}
			$start = $hit[1] + strlen( $hit[0] );
			$end   = self::json_end( $js, $start );
			if ( null === $end ) {
				continue;
			}
			$data = json_decode( substr( $js, $start, $end - $start ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$found[] = array(
				'start' => $start,
				'end'   => $end,
				'data'  => $data,
			);
		}
		return $found;
	}

	/**
	 * Byte position just after the JSON value that starts at $pos, or null.
	 *
	 * @param string $js  Script body.
	 * @param int    $pos Position of the opening { or [.
	 */
	public static function json_end( string $js, int $pos ): ?int {
		$len   = strlen( $js );
		$depth = 0;
		$in    = false;
		for ( $i = $pos; $i < $len; $i++ ) {
			$c = $js[ $i ];
			if ( $in ) {
				if ( '\\' === $c ) {
					$i++;
				} elseif ( '"' === $c ) {
					$in = false;
				}
				continue;
			}
			if ( '"' === $c ) {
				$in = true;
			} elseif ( '{' === $c || '[' === $c ) {
				$depth++;
			} elseif ( '}' === $c || ']' === $c ) {
				$depth--;
				if ( 0 === $depth ) {
					return $i + 1;
				}
			}
		}
		return null;
	}

	/**
	 * A value the visitor could read: letters, more than one word or a sentence
	 * end, no address, no markup.
	 *
	 * @param mixed $key   The key it sits under.
	 * @param mixed $value The value.
	 */
	/**
	 * Keys under which every string is a label, one-word ones included:
	 * «i18n»:{«close»:«Close»,«next»:«Next»} (Elementor, WooCommerce, sliders).
	 */
	const LABEL_KEYS = array( 'i18n', 'l10n', 'strings', 'messages', 'labels', 'texts', 'translations', 'lang', 'label', 'placeholder', 'message', 'msg', 'text', 'tooltip', 'button', 'error' );

	/**
	 * A JSON document handed over as a string: WooCommerce's address form
	 * («wc_address_i18n_params.locale», where «Town / City» and «Postcode / ZIP»
	 * live), Kadence's countdown timers with their «Days»/«Hours» labels. It is
	 * decoded a second time in the browser, so the words inside are read like
	 * any other; here it is opened the same way (read in their code, 29/09/2026).
	 *
	 * @param mixed $value A value found in the data.
	 * @return array|null The decoded document, or null when it is not one.
	 */
	private static function nested( $value ): ?array {
		if ( ! is_string( $value ) || strlen( $value ) < 4 || strlen( $value ) > 200000 ) {
			return null;
		}
		$first = ltrim( $value )[0] ?? '';
		if ( '{' !== $first && '[' !== $first ) {
			return null;
		}
		$data = json_decode( $value, true );
		return is_array( $data ) && ! empty( $data ) ? $data : null;
	}

	/**
	 * Keys whose whole subtree is machinery, never words for the visitor:
	 * Elementor's Finder «keywords» («regenerate css», «safe mode»), breakpoints,
	 * fonts, icons, selectors, routes.
	 */
	const SKIP_SUBTREE = array( 'keywords', 'breakpoints', 'fonts', 'icons', 'classes', 'css', 'styles', 'selectors', 'urls', 'routes', 'nonces', 'ajax', 'rest', 'endpoints', 'hooks', 'settings_keys' );

	private static function is_text( $key, $value, bool $lax = false ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$k = strtolower( (string) $key );
		// «i18n_messages_url» (Everest Forms: «Please enter a valid URL.») is a sentence
		// ABOUT an address, not an address: a key that says «message» wins over its
		// last word (plugin probes, 29/09/2026).
		$parla = (bool) preg_match( '/(^|_)(i18n|l10n|msg|message|messages|label|labels|text|texts|error|errors|notice|tooltip)(_|$)/', $k );
		if ( ! $parla && ( in_array( $k, self::SKIP_KEYS, true ) || preg_match( '/(^|_)(url|nonce|key|id|selector|class|format|regex|pattern)(s|_[a-z]+)?$/', $k ) ) ) {
			return false;
		}
		$v = trim( $value );
		if ( '' === $v || strlen( $v ) > 300 || ! preg_match( '/\p{L}/u', $v ) ) {
			return false;
		}
		if ( preg_match( '~^(https?:)?//|^[/#.]|[<>{}]|^%|%[sd]~', $v ) ) {
			return false; // An address, markup or a template.
		}
		if ( $lax && preg_match( "/^\\p{Lu}[\\p{L}'’-]{2,}$/u", $v ) ) {
			return true; // «Close», «Next», «Zoom» under an i18n key.
		}
		if ( preg_match( '/^[\w-]+$/', $v ) ) {
			return false; // One bare word or key («Loading...» passes: a word with its dots).
		}
		return (bool) preg_match( '/\s|[.!?…:]$/u', $v );
	}

	/**
	 * @param mixed    $node  Decoded value.
	 * @param string[] $found Accumulator.
	 * @param mixed    $key   Key of $node in its parent.
	 */
	private static function walk( $node, array &$found, $key = '', bool $lax = false ): void {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				if ( in_array( strtolower( (string) $k ), self::SKIP_SUBTREE, true ) ) {
					continue;
				}
				self::walk( $v, $found, $k, $lax || in_array( strtolower( (string) $k ), self::LABEL_KEYS, true ) );
			}
		} elseif ( self::is_text( $key, $node, $lax ) ) {
			$found[] = trim( $node );
		} elseif ( null !== ( $inner = self::nested( $node ) ) ) {
			self::walk( $inner, $found, $key, $lax );
		}
	}

	/**
	 * @param mixed                $node    Decoded value.
	 * @param array<string,string> $map     Translations.
	 * @param bool                 $changed Set when something was swapped.
	 * @param mixed                $key     Key of $node in its parent.
	 * @return mixed
	 */
	private static function apply( $node, array $map, bool &$changed, $key = '', bool $lax = false ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				if ( in_array( strtolower( (string) $k ), self::SKIP_SUBTREE, true ) ) {
					continue;
				}
				$node[ $k ] = self::apply( $v, $map, $changed, $k, $lax || in_array( strtolower( (string) $k ), self::LABEL_KEYS, true ) );
			}
			return $node;
		}
		if ( self::is_text( $key, $node, $lax ) ) {
			$t = trim( $node );
			if ( isset( $map[ $t ] ) && '' !== $map[ $t ] && $map[ $t ] !== $t ) {
				$changed = true;
				return str_replace( $t, $map[ $t ], $node );
			}
			return $node;
		}
		$inner = self::nested( $node );
		if ( null !== $inner ) {
			$inner_changed = false;
			$inner         = self::apply( $inner, $map, $inner_changed, $key, $lax );
			if ( $inner_changed ) {
				$json = wp_json_encode( $inner );
				if ( is_string( $json ) ) {
					$changed = true;
					return $json;
				}
			}
		}
		return $node;
	}
}
