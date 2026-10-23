<?php
/**
 * Translate again the sentences that contain the protected names and the glossary terms (1.8.20).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * 23/10/2026 (piflarnakupuje.top, 10,000 pages: «if I add some phrases in the glossary now and rescan the pages, will
 * they be changed?» — no: a rescan collects new texts, the sentences already translated keep their translation, and
 * «Translate again» works a page at a time). This finds, in every language, the translated sentences that disagree
 * with «Words & phrases» (a name that was translated) or with the glossary (a term that does not use its fixed
 * translation), and puts only those back to «missing» — remembered, so one link undoes the lot.
 */
class TermRetranslate {

	/** Option holding the undo batches: id => when + lang => rows. */
	const BATCHES = 'trrocket_terms_undo';

	/**
	 * The sentences to translate again, per language.
	 *
	 * @return array<string,array<int,array{0:int,1:string,2:int,3:string}>> lang => rows (string id, translation, status, provider)
	 */
	public static function candidates(): array {
		global $wpdb;
		$s       = Settings::get();
		$names   = array_values( array_filter( array_map( 'trim', (array) ( $s['exclude_strings'] ?? array() ) ), 'strlen' ) );
		$source  = (string) ( $s['source_language'] ?? '' );
		$langs   = array_values( array_filter( array_map( 'strval', (array) ( $s['target_languages'] ?? array() ) ), static function ( $l ) use ( $source ) { return $l !== $source; } ) );
		$str     = Database::strings_table();
		$tra     = Database::translations_table();
		$occ     = Database::occurrences_table();
		$out     = array();
		foreach ( $langs as $lang ) {
			// what each language has to respect: the names (unchanged) and its glossary (fixed translation)
			$rules = array();
			foreach ( $names as $n ) {
				$rules[] = array( $n, $n );
			}
			foreach ( Settings::glossary( $lang ) as $src => $trg ) {
				if ( '' !== trim( (string) $src ) && '' !== trim( (string) $trg ) ) {
					$rules[] = array( trim( (string) $src ), trim( (string) $trg ) );
				}
			}
			foreach ( $rules as $rule ) {
				list( $term, $must ) = $rule;
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT tr.string_id, tr.translation, tr.status, tr.provider, s.original
						 FROM {$tra} tr INNER JOIN {$str} s ON s.id = tr.string_id
						 WHERE tr.language = %s AND tr.translation <> '' AND s.original LIKE %s
						   AND EXISTS ( SELECT 1 FROM {$occ} o WHERE o.string_id = s.id )
						 LIMIT 5000",
						$lang,
						'%' . $wpdb->esc_like( $term ) . '%'
					)
				);
				foreach ( (array) $rows as $r ) {
					$orig = (string) $r->original;
					// a text that is only the term is handled at display time already (exact match)
					if ( 0 === strcasecmp( trim( wp_strip_all_tags( $orig ) ), $term ) || false === mb_stripos( $orig, $term ) ) {
						continue;
					}
					if ( false !== mb_stripos( (string) $r->translation, $must ) ) {
						continue; // already right
					}
					$out[ $lang ][ (int) $r->string_id ] = array( (int) $r->string_id, (string) $r->translation, (int) $r->status, (string) $r->provider );
				}
			}
		}
		return array_map( 'array_values', $out );
	}

	/**
	 * Put the candidates back to «missing», remembered for undo (in an option of its own: the page «Translate again»
	 * list keeps only the last ten).
	 *
	 * @return array{batch:string,sentences:int,languages:int}
	 */
	public static function run(): array {
		global $wpdb;
		$cand = array_filter( self::candidates() );
		$tra  = Database::translations_table();
		$tot  = 0;
		foreach ( $cand as $lang => $rows ) {
			$sid = array_map( static function ( $r ) { return (int) $r[0]; }, $rows );
			foreach ( array_chunk( $sid, 500 ) as $chunk ) {
				$ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				Strings::deleting( 'tr.language = %s AND tr.string_id IN (' . $ph . ')', array_merge( array( $lang ), $chunk ), 'terms' );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$tra} WHERE language = %s AND string_id IN ({$ph})", array_merge( array( $lang ), $chunk ) ) ); // phpcs:ignore WordPress.DB
			}
			Strings::flush_maps( (string) $lang );
			$tot += count( $rows );
		}
		$batch = '';
		if ( $cand ) {
			$batch = substr( sha1( wp_json_encode( array_keys( $cand ) ) . '|' . microtime( true ) . '|' . wp_rand() ), 0, 10 );
			$all   = (array) get_option( self::BATCHES, array() );
			$all   = array( $batch => array( 'when' => time(), 'rows' => $cand ) ) + array_slice( $all, 0, 4, true );
			update_option( self::BATCHES, $all, false );
			Cache::flush();
		}
		return array( 'batch' => $batch, 'sentences' => $tot, 'languages' => count( $cand ) );
	}

	/**
	 * Undo a whole batch (every language).
	 *
	 * @param string $batch Batch id.
	 * @return int Translations put back.
	 */
	public static function undo( string $batch ): int {
		$all = (array) get_option( self::BATCHES, array() );
		$n   = 0;
		foreach ( (array) ( $all[ $batch ]['rows'] ?? array() ) as $lang => $rows ) {
			foreach ( (array) $rows as $r ) {
				if ( is_array( $r ) && count( $r ) >= 4 ) {
					Strings::save_translation( (int) $r[0], (string) $lang, (string) $r[1], (int) $r[2], '' === (string) $r[3] ? null : (string) $r[3] );
					++$n;
				}
			}
			Strings::flush_maps( (string) $lang );
		}
		unset( $all[ $batch ] );
		update_option( self::BATCHES, $all, false );
		if ( $n ) {
			Cache::flush();
		}
		return $n;
	}

	/**
	 * Glossary terms present in these texts, for the AI prompt: source term => fixed translation.
	 *
	 * @param string[] $texts  Texts about to be sent.
	 * @param string   $target Target language.
	 * @return array<string,string>
	 */
	public static function glossary_in( array $texts, string $target ): array {
		$found = array();
		$all   = implode( "\n", array_map( 'strval', $texts ) );
		foreach ( Settings::glossary( $target ) as $src => $trg ) {
			$src = trim( (string) $src );
			if ( '' !== $src && '' !== trim( (string) $trg ) && false !== mb_stripos( $all, $src ) ) {
				$found[ $src ] = trim( (string) $trg );
			}
		}
		return array_slice( $found, 0, 40, true );
	}

	/**
	 * The prompt line for those terms ('' when none).
	 *
	 * @param array<string,string> $terms Source term => fixed translation.
	 */
	public static function prompt_line( array $terms ): string {
		if ( ! $terms ) {
			return '';
		}
		$list = array();
		foreach ( $terms as $s => $t ) {
			$list[] = wp_json_encode( $s, JSON_UNESCAPED_UNICODE ) . ' → ' . wp_json_encode( $t, JSON_UNESCAPED_UNICODE );
		}
		return ' Glossary: always translate these terms exactly like this: ' . implode( ', ', $list ) . '.';
	}
}
