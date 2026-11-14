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
	 * 1.8.38: the addresses a page had before in a language (one meta row each), so that they move to the
	 * present one with a 301 instead of answering 404.
	 */
	const OLD_PREFIX = '_trrocket_slugold_';

	/** Option: at least one old address has been kept (until then nobody looks for them). */
	const OLD_ANY = 'trrocket_slug_old_any';

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
	 * An address made from a text in a language, with the letters of that language: German «Über uns» → «ueber-uns»,
	 * «Straße» → «strasse», Danish «å» → «aa». sanitize_title() alone follows the site's language, so on an Italian
	 * or English site «Über uns» became «uber-uns» (14/11/2026, LATW 2.8 corrected the same; found on the 8.5 bench
	 * for addresses typed by hand and for the automatic addresses of categories). An address already made (ASCII or
	 * percent-encoded) comes out as it was.
	 *
	 * @param string $text Title, name or address.
	 * @param string $lang Language of the address.
	 */
	public static function clean( string $text, string $lang ): string {
		$loc = '' !== $lang ? Languages::locale( $lang ) : '';
		if ( '' !== $loc && preg_match( '/[^\x00-\x7F]/', $text ) ) {
			$text = remove_accents( $text, $loc );
		}
		return sanitize_title( $text );
	}

	/**
	 * Save (or clear) the translated slug for a post + language.
	 */
	public static function set( int $post_id, string $lang, string $slug ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		$slug = self::clean( $slug, $lang );
		// 1.8.38 (10/11/2026, an address changed by hand in the visual editor: the one of before answered 404):
		// the address it had until now is kept, and leads to the new one with a 301.
		$before = (string) get_post_meta( $post_id, self::META_PREFIX . $lang, true );
		if ( '' !== $before && $before !== $slug ) {
			if ( ! in_array( $before, array_map( 'strval', (array) get_post_meta( $post_id, self::OLD_PREFIX . $lang, false ) ), true ) ) {
				add_post_meta( $post_id, self::OLD_PREFIX . $lang, $before );
			}
			if ( ! get_option( self::OLD_ANY ) ) {
				update_option( self::OLD_ANY, 1, true );
			}
		}
		if ( '' === $slug ) {
			delete_post_meta( $post_id, self::META_PREFIX . $lang );
		} else {
			update_post_meta( $post_id, self::META_PREFIX . $lang, $slug );
			delete_post_meta( $post_id, self::OLD_PREFIX . $lang, $slug ); // back to an address it had before
		}
	}

	/**
	 * An address typed by somebody (visual editor, the box in the page editor, the Translations screen): it is
	 * no longer one «made from the title», so switching the automatic addresses off will not take it away.
	 */
	public static function set_by_hand( int $post_id, string $lang, string $slug ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		if ( self::clean( $slug, $lang ) === self::get( $post_id, $lang ) ) {
			return;
		}
		self::set( $post_id, $lang, $slug );
		delete_post_meta( $post_id, AutoSlugs::AUTO . $lang );
	}

	/**
	 * The published post that had this address in a language before (and no post has it now), or 0.
	 */
	public static function resolve_old( string $slug, string $lang ): int {
		$slug = sanitize_title( $slug );
		if ( '' === $slug || ! get_option( self::OLD_ANY ) ) {
			return 0;
		}
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_status = 'publish' ORDER BY pm.meta_id DESC LIMIT 1", self::OLD_PREFIX . $lang, $slug ) ); // phpcs:ignore WordPress.DB
		if ( $id <= 0 ) {
			return 0;
		}
		// a page of the site is called like that: the address is its own
		$own = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type NOT IN ( 'attachment', 'revision', 'nav_menu_item' ) AND ID <> %d LIMIT 1", $slug, $id ) ); // phpcs:ignore WordPress.DB
		return $own > 0 ? 0 : $id;
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
