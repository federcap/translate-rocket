<?php
/**
 * Per-page translated slugs (stored as post meta).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Translated slug for a post in a language, e.g. "our-services" -> "i-nostri-servizi".
 */
class Slugs {

	const META_PREFIX = '_trrocket_slug_';

	/**
	 * Get the translated slug for a post + language ('' if none).
	 */
	public static function get( int $post_id, string $lang ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		return (string) get_post_meta( $post_id, self::META_PREFIX . $lang, true );
	}

	/**
	 * Save (or clear) the translated slug for a post + language.
	 */
	public static function set( int $post_id, string $lang, string $slug ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			delete_post_meta( $post_id, self::META_PREFIX . $lang );
		} else {
			update_post_meta( $post_id, self::META_PREFIX . $lang, $slug );
		}
	}

	/**
	 * Find the post whose translated slug (for a language) matches, or 0.
	 */
	public static function resolve( string $slug, string $lang ): int {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'     => 'any',
				'post_status'   => 'publish',
				'numberposts'   => 1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_key'      => self::META_PREFIX . $lang, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'    => $slug,                      // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}
}
