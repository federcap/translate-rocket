<?php
/**
 * Page and post addresses from their translated titles (1.8.21).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * 24/10/2026 (Federico: «on translaterocket.com the slugs are not translated — wouldn't it be better for indexing?»).
 * Until now a page had a translated address only when someone typed it in the editor or an import brought it: the
 * setting «translate_slugs» existed but nothing read it. Now, when it is on, every published page, post and product
 * gets in each language the address made from its translated title — /it/guida/come-tradurre-wordpress/ — the links
 * of the site follow, and the original address answers with a 301 (Router, as for the addresses typed by hand).
 *
 * Rules: an address typed by hand is never touched; once made, an address never changes (search engines and shared
 * links keep working), even if the title translation is edited later; two pages never share an address in a language.
 * The work runs on WP-Cron, a few seconds at a time, and passes again every hour for the pages translated meanwhile.
 */
class AutoSlugs {

	const HOOK   = 'trrocket_auto_slugs';
	const CURSOR = 'trrocket_auto_slugs_cursor';
	const AUTO   = '_trrocket_slug_auto_'; // meta: this language's address was made here (not typed by hand)
	const BLOCK  = 40;

	public static function enabled(): bool {
		return ! empty( Settings::get()['translate_slugs'] );
	}

	public function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_changed' ), 20, 2 );
		if ( self::enabled() && ! wp_next_scheduled( self::HOOK ) && ! wp_installing() ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	/**
	 * Switched on: start right away (a minute later, on WP-Cron). Switched off: stop; the addresses already made stay,
	 * and the Router stops using them only if the owner deletes them (they are post meta, like the typed ones).
	 *
	 * @param mixed $old Old settings.
	 * @param mixed $new New settings.
	 */
	public static function settings_changed( $old, $new ): void {
		$was = is_array( $old ) && ! empty( $old['translate_slugs'] );
		$is  = is_array( $new ) && ! empty( $new['translate_slugs'] );
		if ( $is && ! $was ) {
			delete_option( self::CURSOR );
			wp_clear_scheduled_hook( self::HOOK );
			wp_schedule_single_event( time() + 5, self::HOOK );
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		} elseif ( ! $is && $was ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * WP-Cron: work for a few seconds, then come back.
	 */
	public static function run(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$r = self::step( 8.0 );
		if ( ! $r['done'] ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}

	/**
	 * Post types whose addresses carry their slug: the public ones, without attachments.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		$t = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		return (array) apply_filters( 'trrocket_auto_slug_post_types', $t );
	}

	/**
	 * Some pages, from the cursor on, for at most $budget seconds.
	 *
	 * @param float $budget Seconds.
	 * @return array{done:bool,made:int,read:int}
	 */
	public static function step( float $budget = 8.0 ): array {
		global $wpdb;
		$t0     = microtime( true );
		$router = Plugin::instance()->router();
		$langs  = array_values( array_filter( array_map( 'strval', (array) $router->secondary_languages() ) ) );
		$types  = self::types();
		$made   = 0;
		$read   = 0;
		$done   = false;
		if ( ! $langs || ! $types ) {
			return array( 'done' => true, 'made' => 0, 'read' => 0 );
		}
		$front = (int) get_option( 'page_on_front' );
		$ph    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		while ( microtime( true ) - $t0 < $budget ) {
			$after = (int) get_option( self::CURSOR, 0 );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_name, post_type FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ({$ph}) ORDER BY ID LIMIT %d", array_merge( array( $after ), $types, array( self::BLOCK ) ) ) ); // phpcs:ignore WordPress.DB
			if ( ! $rows ) {
				$done = true;
				delete_option( self::CURSOR );
				break;
			}
			update_meta_cache( 'post', array_map( 'intval', wp_list_pluck( $rows, 'ID' ) ) ); // one query for the addresses already there
			$titles = array();
			foreach ( $rows as $r ) {
				$t = trim( html_entity_decode( wp_strip_all_tags( (string) $r->post_title ), ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $t ) {
					$titles[ (int) $r->ID ] = $t;
				}
			}
			foreach ( $langs as $lang ) {
				$map = $titles ? Strings::translate_texts( array_values( $titles ), $lang ) : array();
				foreach ( $rows as $r ) {
					$id = (int) $r->ID;
					++$read;
					if ( $id === $front || '' === (string) $r->post_name || ! isset( $titles[ $id ] ) || '' !== Slugs::get( $id, $lang ) ) {
						continue; // the home has no slug in its address; a typed or already made address stays
					}
					$tr = trim( (string) ( $map[ $titles[ $id ] ] ?? '' ) );
					if ( '' === $tr ) {
						continue; // title not translated yet: next pass
					}
					$slug = self::slug_for( $tr, $id, $lang, (string) $r->post_name );
					if ( '' !== $slug ) {
						Slugs::set( $id, $lang, $slug );
						update_post_meta( $id, self::AUTO . $lang, 1 );
						++$made;
					}
				}
			}
			update_option( self::CURSOR, (int) end( $rows )->ID, false );
		}
		if ( $made ) {
			Cache::flush();
		}
		return array( 'done' => $done, 'made' => $made, 'read' => $read );
	}

	/**
	 * The address for a translated title: free in this language, never another page's own slug, '' when it would be
	 * the same as the original (nothing to translate) or empty.
	 *
	 * @param string $title    Translated title.
	 * @param int    $post_id  Page.
	 * @param string $lang     Language.
	 * @param string $original The page's own slug.
	 */
	public static function slug_for( string $title, int $post_id, string $lang, string $original ): string {
		global $wpdb;
		$base = sanitize_title( $title );
		if ( '' === $base || $base === $original || strlen( $base ) > 190 ) {
			return '';
		}
		$slug = $base;
		for ( $n = 2; $n < 50; $n++ ) {
			$other = Slugs::resolve( $slug, $lang );
			$own   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND ID <> %d LIMIT 1", $slug, $post_id ) ); // phpcs:ignore WordPress.DB
			if ( ( 0 === $other || $other === $post_id ) && 0 === $own ) {
				return $slug;
			}
			$slug = substr( $base, 0, 180 ) . '-' . $n;
		}
		return '';
	}
}
