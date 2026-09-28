<?php
/**
 * Search in the visitor's language.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Database;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress searches the database, and the database holds the source language
 * only: a visitor on /it/ typing «tazza» found nothing, because the product is
 * stored as «mug». It is the first complaint in the TranslatePress forum
 * («Cannot find products when searching their translated titles»), 28/09/2026.
 *
 * On a translated page the search also looks for the ORIGINAL of every
 * translation that contains the words typed, so the same posts come up as in
 * the source language. Nothing changes on the default language.
 */
class Search {

	/**
	 * Language of this request.
	 *
	 * @var string
	 */
	private $lang = '';

	/**
	 * Most originals added to one search, to keep the query small.
	 */
	const MAX = 20;

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( '' === $lang || $router->is_default( $lang ) ) {
			return;
		}
		$this->lang = $lang;
		add_filter( 'posts_search', array( $this, 'widen' ), 20, 2 );
	}

	/**
	 * Add «OR (the original text)» to the search clause of a search query.
	 *
	 * @param mixed $search SQL clause WordPress built (starts with " AND ").
	 * @param mixed $query  WP_Query.
	 * @return mixed
	 */
	public function widen( $search, $query ) {
		global $wpdb;
		if ( ! is_string( $search ) || '' === trim( $search ) || ! ( $query instanceof \WP_Query ) || ! $query->is_search() ) {
			return $search;
		}
		$typed = trim( (string) $query->get( 's' ) );
		if ( '' === $typed || ( function_exists( 'mb_strlen' ) ? mb_strlen( $typed ) : strlen( $typed ) ) < 3 ) {
			return $search;
		}
		$originals = $this->originals( $typed );
		if ( empty( $originals ) ) {
			return $search;
		}
		$or = array();
		foreach ( $originals as $original ) {
			$like = '%' . $wpdb->esc_like( $original ) . '%';
			$or[] = $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s)", $like, $like, $like );
		}
		$inner = preg_replace( '/^\s*AND\s+/i', '', $search );
		return ' AND ( ' . $inner . ' OR ' . implode( ' OR ', $or ) . ' ) ';
	}

	/**
	 * Source texts whose translation into this language contains what was typed.
	 *
	 * @param string $typed Search words.
	 * @return string[]
	 */
	private function originals( string $typed ): array {
		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are ours.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.original FROM {$translations} t INNER JOIN {$strings} s ON s.id = t.string_id
				 WHERE t.language = %s AND t.status > 0 AND t.translation LIKE %s AND CHAR_LENGTH(s.original) <= 200
				 LIMIT %d",
				$this->lang,
				'%' . $wpdb->esc_like( $typed ) . '%',
				self::MAX
			)
		); // phpcs:ignore WordPress.DB
		$out = array();
		foreach ( (array) $rows as $original ) {
			$original = trim( wp_strip_all_tags( html_entity_decode( (string) $original, ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' !== $original ) {
				$out[ $original ] = true;
			}
		}
		return array_keys( $out );
	}
}
