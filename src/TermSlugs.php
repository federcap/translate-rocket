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

	/** The translated name a slug was made from (22/10/2026): when it changes, the slug follows. */
	const FROM_PREFIX = '_trrocket_term_slug_from_';

	/** Slugs a term had before (one row each): their addresses send the visitor on with a 301. */
	const OLD_PREFIX = '_trrocket_term_slug_old_';

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
		$key      = trim( $name );
		$map      = Strings::translate_texts( array( $key ), $lang );
		$tname    = isset( $map[ $key ] ) ? (string) $map[ $key ] : '';
		$slug     = '' !== $tname ? sanitize_title( $tname ) : '';
		if ( '' !== $existing ) {
			// 22/10/2026 (Federico, after the piflarnakupuje.top case: «se cambi il nome della categoria, l'indirizzo
			// tradotto non lo segue… perché non farlo in questa?»): the slug was made once and kept for ever. Now it
			// follows the translated name — the category renamed, or its translation corrected — and the old address
			// stays as a 301 to the new one. A slug that did not come from the name (imported from another plugin)
			// is never touched.
			if ( '' === $slug || $slug === $existing ) {
				if ( '' !== $slug && '' === (string) get_term_meta( $term_id, self::FROM_PREFIX . $lang, true ) ) {
					update_term_meta( $term_id, self::FROM_PREFIX . $lang, $tname ); // made before 1.8.16 from this name
				}
				return $existing;
			}
			$from = (string) get_term_meta( $term_id, self::FROM_PREFIX . $lang, true );
			if ( '' === $from || $from === $tname ) {
				return $existing;
			}
			self::remember_old( $term_id, $lang, $existing );
			$term = get_term( $term_id );
			if ( $term instanceof \WP_Term && $slug === $term->slug ) {
				// the new name translates to the term's own slug: the plain address serves this language
				self::set( $term_id, $lang, '' );
				update_term_meta( $term_id, self::FROM_PREFIX . $lang, $tname );
				return '';
			}
			self::set( $term_id, $lang, $slug );
			update_term_meta( $term_id, self::FROM_PREFIX . $lang, $tname );
			delete_term_meta( $term_id, self::OLD_PREFIX . $lang, $slug ); // renamed back: no longer an old one
			return $slug;
		}
		if ( '' === $slug ) {
			return '';
		}
		$term = get_term( $term_id );
		if ( $term instanceof \WP_Term && $slug === $term->slug ) {
			return '';
		}
		self::set( $term_id, $lang, $slug );
		update_term_meta( $term_id, self::FROM_PREFIX . $lang, $tname );
		return $slug;
	}

	/**
	 * Keep a slug the term no longer uses, so its address still leads somewhere.
	 *
	 * @param int    $term_id Term.
	 * @param string $lang    Language.
	 * @param string $slug    The slug it had.
	 */
	private static function remember_old( int $term_id, string $lang, string $slug ): void {
		$old = (array) get_term_meta( $term_id, self::OLD_PREFIX . $lang, false );
		if ( '' !== $slug && ! in_array( $slug, $old, true ) ) {
			add_term_meta( $term_id, self::OLD_PREFIX . $lang, $slug );
		}
	}

	/**
	 * The term that once had this translated slug (for a language + taxonomy), 0 if none.
	 *
	 * @param string $old_slug Slug from the address.
	 * @param string $lang     Language.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function resolve_old( string $old_slug, string $lang, string $taxonomy ): int {
		$old_slug = sanitize_title( $old_slug );
		if ( '' === $old_slug ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT tm.term_id FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = %s
				WHERE tm.meta_key = %s AND tm.meta_value = %s
				ORDER BY tm.meta_id DESC LIMIT 1",
				$taxonomy,
				self::OLD_PREFIX . $lang,
				$old_slug
			)
		);
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
		// 22/10/2026 (piflarnakupuje.top, product categories in 404): a plain query, not get_terms(). WooCommerce
		// sorts product_cat by its own «order» meta through the get_terms filters, and with our meta_key the
		// lookup came back empty: every translated product category address answered 404.
		global $wpdb;
		$real = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT t.slug FROM {$wpdb->termmeta} tm
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = %s
				INNER JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
				WHERE tm.meta_key = %s AND tm.meta_value = %s
				ORDER BY tm.term_id ASC LIMIT 1",
				$taxonomy,
				self::META_PREFIX . $lang,
				$translated_slug
			)
		);
		return null === $real ? '' : (string) $real;
	}
}
