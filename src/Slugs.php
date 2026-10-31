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
		// The post's meta already in memory (the page being shown, the editor): read it there.
		if ( false !== wp_cache_get( $post_id, 'post_meta' ) ) {
			return (string) get_post_meta( $post_id, self::META_PREFIX . $lang, true );
		}
		// 1.8.30 (29/10/2026): otherwise every translated slug of the language at once. A translated page whose menu
		// lists 5.000 pages asked once per link, and each get_post_meta() loaded ALL the meta of that page (Elementor's
		// included): 5.000 queries, 3 s. Now one query of small rows, kept for the request.
		if ( ! isset( self::$per_lingua[ $lang ] ) ) {
			global $wpdb;
			self::$per_lingua[ $lang ] = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_PREFIX . $lang ) ) as $r ) { // phpcs:ignore WordPress.DB
				self::$per_lingua[ $lang ][ (int) $r->post_id ] = (string) $r->meta_value;
			}
			self::ascolta();
		}
		return self::$per_lingua[ $lang ][ $post_id ] ?? '';
	}

	/**
	 * 31/10/2026 (Garlen: «Single Page Slug Translation: OFF… /products/ inside href still translated, 404»): the
	 * translated slug the site's addresses use — the one typed by hand always, the one made from the translated title
	 * (AutoSlugs) only while «translate slugs» is on. Switched off, those addresses stay saved (switched on again they
	 * come back, the same ones) but links, menus, breadcrumbs, hreflang and the sitemap go back to the original slug.
	 */
	public static function active( int $post_id, string $lang ): string {
		$slug = self::get( $post_id, $lang );
		if ( '' === $slug || AutoSlugs::enabled() ) {
			return $slug;
		}
		return isset( self::auto_ids( $lang )[ $post_id ] ) ? '' : $slug;
	}

	/**
	 * Was this page's address in the language made from its title (not typed)?
	 */
	public static function is_auto( int $post_id, string $lang ): bool {
		return isset( self::auto_ids( $lang )[ $post_id ] );
	}

	/**
	 * Pages whose address in a language was made by AutoSlugs: one query per language and request.
	 *
	 * @return array<int,bool>
	 */
	private static function auto_ids( string $lang ): array {
		if ( ! isset( self::$auto[ $lang ] ) ) {
			global $wpdb;
			self::$auto[ $lang ] = array();
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", AutoSlugs::AUTO . $lang ) ) as $id ) { // phpcs:ignore WordPress.DB
				self::$auto[ $lang ][ (int) $id ] = true;
			}
			self::ascolta();
		}
		return self::$auto[ $lang ];
	}

	/**
	 * Pages with an address made from the title, per language.
	 *
	 * @var array<string,array<int,bool>>
	 */
	private static $auto = array();

	/**
	 * Translated slugs read in this request, per language: [lang][post_id] = slug.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $per_lingua = array();

	/**
	 * A translated slug written in this request (by anyone): read again next time.
	 */
	private static function ascolta(): void {
		static $fatto = false;
		if ( $fatto ) {
			return;
		}
		$fatto  = true;
		$scorda = static function ( $meta_id, $object_id, $meta_key ) {
			unset( $meta_id, $object_id );
			if ( is_string( $meta_key ) && ( 0 === strpos( $meta_key, self::META_PREFIX ) || 0 === strpos( $meta_key, AutoSlugs::AUTO ) ) ) {
				self::$per_lingua = array();
				self::$auto       = array();
			}
		};
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $h ) {
			add_action( $h, $scorda, 10, 3 );
		}
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
