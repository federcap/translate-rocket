<?php
/**
 * Translated slugs for taxonomy terms (stored as term meta).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Translated slug for a taxonomy term in a language, e.g. a category
 * "palermo-guide" -> "guida-palermo". Mirrors Slugs (which does posts) but for terms,
 * and auto-derives the slug from the term's translated name the first time it's needed.
 */
class TermSlugs {

	const META_PREFIX = '_trrocket_term_slug_';

	/**
	 * Translated slug for a term + language ('' if none).
	 */
	public static function get( int $term_id, string $lang ): string {
		if ( $term_id <= 0 ) {
			return '';
		}
		return (string) get_term_meta( $term_id, self::META_PREFIX . $lang, true );
	}

	/**
	 * Save (or clear) the translated slug for a term + language.
	 */
	public static function set( int $term_id, string $lang, string $slug ): void {
		if ( $term_id <= 0 ) {
			return;
		}
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			delete_term_meta( $term_id, self::META_PREFIX . $lang );
		} else {
			update_term_meta( $term_id, self::META_PREFIX . $lang, $slug );
		}
	}

	/**
	 * The translated slug for a term, deriving it from the term's translated name the
	 * first time (and caching it). Returns '' when the name has no translation yet (so
	 * the URL keeps the source slug) or when it would equal the source slug.
	 */
	public static function ensure( int $term_id, string $name, string $lang ): string {
		$existing = self::get( $term_id, $lang );
		if ( '' !== $existing ) {
			return $existing;
		}
		$key   = trim( $name );
		$map   = Strings::translate_texts( array( $key ), $lang );
		$tname = isset( $map[ $key ] ) ? (string) $map[ $key ] : '';
		$slug  = '' !== $tname ? sanitize_title( $tname ) : '';
		if ( '' === $slug ) {
			return '';
		}
		$term = get_term( $term_id );
		if ( $term instanceof \WP_Term && $slug === $term->slug ) {
			return '';
		}
		self::set( $term_id, $lang, $slug );
		return $slug;
	}

	/**
	 * The real slug of the term whose translated slug (for a language + taxonomy)
	 * matches, or '' if none.
	 */
	public static function resolve( string $translated_slug, string $lang, string $taxonomy ): string {
		$translated_slug = sanitize_title( $translated_slug );
		if ( '' === $translated_slug ) {
			return '';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'all',
				'meta_key'   => self::META_PREFIX . $lang, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $translated_slug,           // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) && isset( $terms[0]->slug ) ) {
			return (string) $terms[0]->slug;
		}
		return '';
	}
}
