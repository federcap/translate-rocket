<?php
/**
 * Search in the visitor's language: the secondary searches (widgets, live product searches).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Router;
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
 *
 * 14/10/2026: the main search of a results page is Search's (an index of each page's own text, every word,
 * ranked); this one keeps widening the other searches of the page (a widget's, a live product search), as
 * before. It was Frontend\Search until then.
 */
class SearchWiden {

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
		// On admin-ajax (a theme's live search) the language is the one of the page that asked.
		// (wc-ajax too — FiboSearch, 16/10/2026: its address has no /it/, the page that asked has)
		$lang = Router::is_frontend_ajax() ? $router->request_language() : $router->current_language();
		// «Search in every language» switched off: WordPress's own search, untouched (14/10/2026).
		if ( '' === $lang || $router->is_default( $lang ) || ! Search::enabled() ) {
			return;
		}
		$this->lang = $lang;
		add_filter( 'posts_search', array( $this, 'widen' ), 20, 2 );
		// 16/10/2026 (competitors' code): Ajax Search Lite (100k sites) runs its own SQL, posts_search never sees it:
		// it is given the source wording of what was typed, and its answer is translated like any fragment.
		add_filter( 'asl_query_args', array( $this, 'asl_args' ), 20 );
		add_filter( 'asl_before_ajax_output', array( $this, 'asl_output' ), 20 );
		// FiboSearch (100k shops) builds its own SQL too: the phrase typed in Italian becomes the source wording
		// it can find; its answer is translated on the way back (wc-ajax, AjaxContent). Proven on the bench 16/10/2026.
		add_filter( 'dgwt/wcas/phrase', array( $this, 'fibo_phrase' ), 20 );
	}

	/**
	 * FiboSearch: the source wording of what was typed, when a translation contains it.
	 *
	 * @param mixed $phrase What the visitor typed.
	 * @return mixed
	 */
	public function fibo_phrase( $phrase ) {
		if ( ! is_string( $phrase ) || mb_strlen( trim( $phrase ) ) < 3 ) {
			return $phrase;
		}
		$best = $this->best_original( trim( $phrase ) );
		return '' !== $best ? $best : $phrase;
	}

	/**
	 * For a search engine that takes ONE phrase: among the source texts whose translation contains what was
	 * typed, the one that is (part of) a published title — the product or page the visitor is after — else
	 * the shortest; '' when there is none.
	 *
	 * @param string $typed What was typed.
	 */
	private function best_original( string $typed ): string {
		global $wpdb;
		if ( mb_strlen( $typed ) < 3 ) {
			return '';
		}
		$originals = $this->originals( $typed );
		if ( empty( $originals ) ) {
			return '';
		}
		usort( $originals, static function ( $a, $b ) { return mb_strlen( $a ) <=> mb_strlen( $b ); } );
		foreach ( array_slice( $originals, 0, 12 ) as $original ) {
			if ( mb_strlen( $original ) > 80 ) {
				continue;
			}
			$hit = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_title LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $original ) . '%' ) ); // phpcs:ignore WordPress.DB
			if ( $hit ) {
				return $original;
			}
		}
		return (string) $originals[0];
	}

	/**
	 * Ajax Search Lite: search for the source text whose translation contains what was typed.
	 *
	 * @param mixed $args Its query arguments ('s' = what was typed).
	 * @return mixed
	 */
	public function asl_args( $args ) {
		if ( ! is_array( $args ) || empty( $args['s'] ) || ! is_string( $args['s'] ) ) {
			return $args;
		}
		$best = $this->best_original( trim( $args['s'] ) );
		if ( '' !== $best ) {
			$args['s'] = $best;
		}
		return $args;
	}

	/**
	 * Ajax Search Lite: its results, in the page's language.
	 *
	 * @param mixed $html The answer.
	 * @return mixed
	 */
	public function asl_output( $html ) {
		return is_string( $html ) && '' !== $html ? Fragment::translate( $html, $this->lang ) : $html;
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
		if ( $query->is_main_query() && Search::enabled() ) {
			return $search; // Search answers it, with its index
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
