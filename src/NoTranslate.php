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
	 * Prefixes of ids and classes that belong to administrator tooling shown on the
	 * front end — debug bars, page-builder helpers, comment/notes apps — never to the
	 * page itself. Text inside them is neither collected nor translated.
	 *
	 * @return string[]
	 */
	public static function tool_prefixes(): array {
		/**
		 * Id/class prefixes of front-end tooling to leave alone.
		 *
		 * @param string[] $prefixes Defaults cover Query Monitor, Debug Bar, Elementor's
		 *                           editor helpers and notes, and the block editor.
		 */
		return (array) apply_filters(
			'trrocket_tool_prefixes',
			array(
				'query-monitor', 'qm-', 'debug-bar', 'querylist',
				'elementor-notes', 'e-notes', 'elementor-admin-bar', 'elementor-editor', 'elementor-panel',
				'elementor-template-library', 'elementor-hidden', 'elementor-navigator', 'elementor-preview',
				'elementor-document-handle', 'elementor-add-section', 'elementor-add-new-section', 'elementor-first-add',
				'elementor-select-preset', 'e-con-select-', 'customize-partial-edit-shortcut',
				'wp-admin-bar', 'edit-post-', 'interface-',
			)
		);
	}

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
	 * What is never translated on any site, before the owner's own list: codes that
	 * only look like words. A product SKU such as «TSHIRT-BLUE» translated into
	 * «MAGLIETTA-BLU» is quoted to support by the customer, and the variation
	 * script writes the original back when a variant is chosen — two codes on one
	 * page (casi raccolti, negozio 9, 25/9/2026).
	 *
	 * @return string[]
	 */
	public static function factory_selectors(): array {
		/**
		 * Filters the selectors TranslateRocket never translates, whatever the settings say.
		 *
		 * @param string[] $selectors Simple selectors: tag, .class or #id.
		 */
		return self::clean_list( apply_filters( 'trrocket_factory_exclude_selectors', array( '.sku' ) ) );
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
		foreach ( array_merge( self::factory_selectors(), self::selectors() ) as $sel ) {
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
