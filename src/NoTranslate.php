<?php
/**
 * Global "do not translate" rules.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide exclusions that keep certain content in the original language:
 *  - URL paths (with "*" wildcards), e.g. /checkout/*  — the page is served untouched;
 *  - CSS selectors (simple tag / .class / #id) — matching elements are skipped;
 *  - exact source strings — never collected, translated or sent to an AI.
 *
 * This is separate from {@see Exclusions}, which controls per-page visibility.
 */
class NoTranslate {

	/**
	 * @return string[]
	 */
	public static function paths(): array {
		return self::clean_list( Settings::get()['exclude_paths'] ?? array() );
	}

	/**
	 * @return string[]
	 */
	public static function selectors(): array {
		return self::clean_list( Settings::get()['exclude_selectors'] ?? array() );
	}

	/**
	 * @return string[]
	 */
	public static function strings(): array {
		return self::clean_list( Settings::get()['exclude_strings'] ?? array() );
	}

	/**
	 * Whether a clean request path (no language prefix) matches an exclusion
	 * pattern. "*" is a wildcard; matching is case-insensitive.
	 */
	public static function path_excluded( string $path ): bool {
		$path = '/' . trim( $path, '/' );
		foreach ( self::paths() as $pattern ) {
			$pattern = '/' . trim( $pattern, '/' );
			$regex   = preg_quote( $pattern, '#' );
			$regex   = str_replace( '/\*', '(?:/.*)?', $regex ); // "/foo/*" also matches "/foo".
			$regex   = str_replace( '\*', '.*', $regex );        // any other "*".
			if ( preg_match( '#^' . $regex . '$#i', $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an exact source string is on the never-translate list.
	 */
	public static function text_excluded( string $text ): bool {
		$set = self::string_set();
		return array() !== $set && isset( $set[ trim( $text ) ] );
	}

	/**
	 * XPath predicate fragment matching any element covered by the configured
	 * selectors (for skipping in the engine), or '' if there are none. Selector
	 * names are sanitized to [A-Za-z0-9_-] so they are safe to interpolate.
	 */
	public static function xpath_skip(): string {
		$parts = array();
		foreach ( self::selectors() as $sel ) {
			$type = $sel[0] ?? '';
			$name = preg_replace( '/[^A-Za-z0-9_-]/', '', substr( $sel, ( '.' === $type || '#' === $type ) ? 1 : 0 ) );
			if ( '' === $name ) {
				continue;
			}
			if ( '.' === $type ) {
				$parts[] = "ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' {$name} ')]";
			} elseif ( '#' === $type ) {
				$parts[] = "ancestor-or-self::*[@id='{$name}']";
			} else {
				$parts[] = 'ancestor-or-self::' . $name;
			}
		}
		return implode( ' or ', $parts );
	}

	/**
	 * Normalize an array (or newline string) into a trimmed, non-empty list.
	 *
	 * @param mixed $value Stored value.
	 * @return string[]
	 */
	private static function clean_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		return array_values( array_filter( array_map( 'trim', (array) $value ), 'strlen' ) );
	}

	/**
	 * @return array<string,true>
	 */
	private static function string_set(): array {
		static $cache = null;
		if ( null === $cache ) {
			$cache = array();
			foreach ( self::strings() as $s ) {
				$cache[ $s ] = true;
			}
		}
		return $cache;
	}
}
