<?php
/**
 * Sentences read as one, translated long ago piece by piece.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

use TranslateRocket\Frontend\InlineText;

defined( 'ABSPATH' ) || exit;

/**
 * Give every whole sentence the translation its pieces already have.
 *
 * Older versions collected a sentence with a link or a bold word in it as separate
 * pieces («Translate WordPress», «into every language»), and sites translated those.
 * Newer versions read the sentence as one («Translate WordPress<1/><2>into every
 * language</2>»). Visitors still see it translated — the pieces are used when the
 * sentence has no translation — but the visual editor, the per-page editor, the list
 * of translations and the percentages all saw an untranslated sentence: on
 * translaterocket.com, 378 of them (24/9/2026). A site owner would think the work was
 * lost, and translating again could overwrite a good translation.
 *
 * So, once per plugin version and in small batches while an administrator is in the
 * dashboard: every sentence without a translation whose pieces are ALL translated gets
 * the sentence put together from them — exactly what visitors already read. Saved as
 * a machine translation (status 1, «pieces»), so it can be edited like any other.
 * The pieces keep their own translations; nothing is deleted.
 */
final class PiecesMigration {

	const DONE   = 'trrocket_pieces_done';
	const CURSOR = 'trrocket_pieces_cursor';
	const BATCH  = 200;

	/**
	 * Hook into the dashboard.
	 */
	public static function boot(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
	}

	/**
	 * One batch, if this version has not finished yet.
	 */
	public static function maybe_run(): void {
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || get_option( self::DONE ) === TRROCKET_VERSION ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::run_batch();
	}

	/**
	 * Process the next batch of sentences with placeholders.
	 *
	 * @return int Translations written in this batch.
	 */
	public static function run_batch(): int {
		global $wpdb;
		$st      = Database::strings_table();
		$tr      = Database::translations_table();
		$targets = array_values( array_filter( (array) ( Settings::get()['target_languages'] ?? array() ) ) );
		$cursor  = (int) get_option( self::CURSOR, 0 );

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT id, original FROM {$st} WHERE id > %d AND original REGEXP '<[0-9]{1,3}/?>' ORDER BY id LIMIT %d",
			$cursor,
			self::BATCH
		) );
		if ( empty( $rows ) || empty( $targets ) ) {
			update_option( self::DONE, TRROCKET_VERSION, false );
			delete_option( self::CURSOR );
			return 0;
		}

		$pieces = array();
		$ids    = array();
		foreach ( $rows as $r ) {
			$ids[] = (int) $r->id;
			foreach ( InlineText::pieces( (string) $r->original ) as $p ) {
				$pieces[ $p ] = true;
			}
		}
		$in    = implode( ',', $ids );
		$fatte = 0;
		foreach ( $targets as $lang ) {
			$lang = (string) $lang;
			// Chi ha gia' una traduzione vera non si tocca.
			$gia = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT string_id FROM {$tr} WHERE language = %s AND string_id IN ({$in}) AND status > 0 AND translation IS NOT NULL AND translation <> ''",
				$lang
			) );
			$gia  = array_flip( array_map( 'intval', (array) $gia ) );
			$tmap = Strings::translate_texts( array_keys( $pieces ), $lang );
			foreach ( $rows as $r ) {
				if ( isset( $gia[ (int) $r->id ] ) ) {
					continue;
				}
				$frase = InlineText::compose( trim( (string) $r->original ), $tmap );
				if ( null !== $frase && Strings::save_translation( (int) $r->id, $lang, $frase, 1, 'pieces' ) ) {
					++$fatte;
				}
			}
		}
		update_option( self::CURSOR, (int) end( $ids ), false );
		return $fatte;
	}
}
