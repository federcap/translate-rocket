<?php
/**
 * "Translate again": drop a page's translations for a language, keeping a copy to undo.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * When the original page has changed, or a translation went wrong, the owner
 * wants the page translated again from scratch: every translation of that page
 * in that language, and its translated address. Nothing is lost for good — what
 * was removed is kept for a while and can be put back with one click.
 */
final class PageReset {

	/**
	 * Option holding the last removals (newest first).
	 */
	const UNDO = 'trrocket_reset_undo';

	/**
	 * How many removals are kept for undo.
	 */
	const KEEP = 10;

	/**
	 * The post a tracked page belongs to, or 0 (a listing, a search page…).
	 */
	public static function post_id_of( string $url_hash ): int {
		$info = Strings::page_info( $url_hash );
		if ( ! $info || empty( $info->url ) ) {
			return 0;
		}
		$id = url_to_postid( home_url( (string) $info->url ) );
		if ( $id <= 0 && function_exists( 'get_page_by_path' ) ) {
			$page = get_page_by_path( trim( (string) $info->url, '/' ), OBJECT, get_post_types( array( 'public' => true ) ) );
			$id   = $page instanceof \WP_Post ? (int) $page->ID : 0;
		}
		return max( 0, (int) $id );
	}

	/**
	 * Remove a page's translations and translated slug for a language, remembering them.
	 *
	 * @return string The undo id ('' when there was nothing to remove).
	 */
	public static function reset( string $url_hash, string $lang ): string {
		global $wpdb;
		$translations = Database::translations_table();
		$occurrences  = Database::occurrences_table();
		// Only what belongs to this page alone: a sentence that also appears on other
		// pages keeps its translation there, so redoing one page never undoes another.
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT tr.string_id, tr.translation, tr.status, tr.provider
				 FROM {$translations} tr
				 INNER JOIN {$occurrences} o ON o.string_id = tr.string_id
				 WHERE o.url_hash = %s AND tr.language = %s
				   AND NOT EXISTS ( SELECT 1 FROM {$occurrences} o2 WHERE o2.string_id = tr.string_id AND o2.url_hash <> %s )",
				$url_hash,
				$lang,
				$url_hash
			)
		); // phpcs:ignore WordPress.DB
		$kept         = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT tr.string_id)
				 FROM {$translations} tr
				 INNER JOIN {$occurrences} o ON o.string_id = tr.string_id
				 WHERE o.url_hash = %s AND tr.language = %s AND tr.translation <> ''
				   AND EXISTS ( SELECT 1 FROM {$occurrences} o2 WHERE o2.string_id = tr.string_id AND o2.url_hash <> %s )",
				$url_hash,
				$lang,
				$url_hash
			)
		); // phpcs:ignore WordPress.DB

		$post_id = self::post_id_of( $url_hash );
		$slug    = $post_id > 0 ? Slugs::get( $post_id, $lang ) : '';
		if ( empty( $rows ) && '' === $slug ) {
			return '';
		}

		$saved = array();
		foreach ( $rows as $r ) {
			$saved[] = array( (int) $r->string_id, (string) $r->translation, (int) $r->status, (string) $r->provider );
		}
		$info = Strings::page_info( $url_hash );
		$id   = substr( sha1( $url_hash . '|' . $lang . '|' . microtime( true ) . '|' . wp_rand() ), 0, 10 );

		$list = get_option( self::UNDO, array() );
		$list = is_array( $list ) ? $list : array();
		array_unshift(
			$list,
			array(
				'id'      => $id,
				'when'    => time(),
				'lang'    => $lang,
				'page'    => $url_hash,
				'url'     => $info ? (string) $info->url : '',
				'title'   => $info ? (string) $info->title : '',
				'post'    => $post_id,
				'slug'    => $slug,
				'kept'    => $kept,
				'rows'    => $saved,
			)
		);
		update_option( self::UNDO, array_slice( $list, 0, self::KEEP ), false );

		$ids = array_map( static function ( $r ) { return (int) $r[0]; }, $saved );
		if ( ! empty( $ids ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$translations} WHERE language = %s AND string_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $lang ), $ids ) ) ); // phpcs:ignore WordPress.DB
		}
		Strings::flush_maps( $lang );
		if ( $post_id > 0 && '' !== $slug ) {
			Slugs::set( $post_id, $lang, '' );
		}
		Cache::flush();
		return $id;
	}

	/**
	 * One remembered removal, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function entry( string $id ): ?array {
		foreach ( (array) get_option( self::UNDO, array() ) as $e ) {
			if ( is_array( $e ) && ( $e['id'] ?? '' ) === $id ) {
				return $e;
			}
		}
		return null;
	}

	/**
	 * The most recent removal for a page and language, if it is still remembered.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function latest( string $url_hash, string $lang ): ?array {
		foreach ( (array) get_option( self::UNDO, array() ) as $e ) {
			if ( is_array( $e ) && ( $e['page'] ?? '' ) === $url_hash && ( $e['lang'] ?? '' ) === $lang ) {
				return $e;
			}
		}
		return null;
	}

	/**
	 * Put a removal back: every translation and the translated slug.
	 *
	 * @return int|null Translations restored, or null when the id is unknown.
	 */
	public static function undo( string $id ): ?int {
		$e = self::entry( $id );
		if ( null === $e ) {
			return null;
		}
		$lang = (string) $e['lang'];
		$n    = 0;
		foreach ( (array) $e['rows'] as $r ) {
			if ( ! is_array( $r ) || count( $r ) < 4 ) {
				continue;
			}
			Strings::save_translation( (int) $r[0], $lang, (string) $r[1], (int) $r[2], '' === (string) $r[3] ? null : (string) $r[3] );
			++$n;
		}
		if ( (int) $e['post'] > 0 && '' !== (string) $e['slug'] ) {
			Slugs::set( (int) $e['post'], $lang, (string) $e['slug'] );
		}
		$list = array_values(
			array_filter(
				(array) get_option( self::UNDO, array() ),
				static function ( $x ) use ( $id ) {
					return ! is_array( $x ) || ( $x['id'] ?? '' ) !== $id;
				}
			)
		);
		update_option( self::UNDO, $list, false );
		Strings::flush_maps( $lang );
		Cache::flush();
		return $n;
	}
}
