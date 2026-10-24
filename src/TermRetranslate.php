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

	/** Option holding the undo batches: id => when, sentences, languages, parts (rows kept in options of their own). */
	const BATCHES = 'trrocket_terms_undo';

	/** The run in progress: batch, cursor (last string id read), last id, found, languages. */
	const JOB = 'trrocket_terms_job';

	/** One option per step with the rows taken away: trrocket_terms_undo_<batch>_<n>. */
	const PART = 'trrocket_terms_undo_';

	/** Sentences read from the table per query. */
	const CHUNK = 2500;

	/**
	 * 23/10/2026 (piflarnakupuje.top, 14 languages, ~10,000 pages, a full glossary: «Request Timeout»): the first
	 * version ran one LIKE '%term%' over the whole table for every term of every language — on the bench with 60,000
	 * sentences and 150 terms, 180 seconds in one request. Now the table is read ONCE, a block at a time by id, every
	 * term is looked for in PHP with one pattern, and the work goes in steps of a few seconds.
	 *
	 * @return array<string,array<int,array{0:string,1:string}>> lang => rules (term, what the translation must contain)
	 */
	private static function rules(): array {
		$s      = Settings::get();
		$names  = array_values( array_filter( array_map( 'trim', (array) ( $s['exclude_strings'] ?? array() ) ), 'strlen' ) );
		$source = (string) ( $s['source_language'] ?? '' );
		$out    = array();
		foreach ( (array) ( $s['target_languages'] ?? array() ) as $lang ) {
			$lang = (string) $lang;
			if ( '' === $lang || $lang === $source ) {
				continue;
			}
			$rules = array();
			foreach ( $names as $n ) {
				$rules[] = array( $n, $n );
			}
			foreach ( Settings::glossary( $lang ) as $src => $trg ) {
				if ( '' !== trim( (string) $src ) && '' !== trim( (string) $trg ) ) {
					$rules[] = array( trim( (string) $src ), trim( (string) $trg ) );
				}
			}
			if ( $rules ) {
				$out[ $lang ] = $rules;
			}
		}
		return $out;
	}

	/**
	 * Patterns that find any of the terms (a few hundred per pattern, so none grows too long).
	 *
	 * @param array<string,array> $rules Rules per language.
	 * @return string[]
	 */
	private static function patterns( array $rules ): array {
		$terms = array();
		foreach ( $rules as $list ) {
			foreach ( $list as $r ) {
				$terms[ mb_strtolower( $r[0] ) ] = preg_quote( $r[0], '/' );
			}
		}
		$out = array();
		foreach ( array_chunk( array_values( $terms ), 300 ) as $group ) {
			$out[] = '/' . implode( '|', $group ) . '/iu';
		}
		return $out;
	}

	/**
	 * One block of sentences after $after: the translated sentences that disagree with the rules.
	 *
	 * @param int   $after    Last string id already read.
	 * @param array $rules    Rules per language.
	 * @param array $patterns Patterns from patterns().
	 * @return array{0:array<string,array<int,array>>,1:int,2:int} found per lang (string id => row), last id, rows read
	 */
	private static function scan_block( int $after, array $rules, array $patterns ): array {
		global $wpdb;
		$str  = Database::strings_table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, original FROM {$str} WHERE id > %d ORDER BY id LIMIT %d", $after, self::CHUNK ) ); // phpcs:ignore WordPress.DB
		$last = $after;
		$hit  = array();
		foreach ( (array) $rows as $r ) {
			$last = (int) $r->id;
			$orig = (string) $r->original;
			foreach ( $patterns as $p ) {
				if ( preg_match( $p, $orig ) ) {
					$hit[ (int) $r->id ] = $orig;
					break;
				}
			}
		}
		$found = array();
		if ( $hit ) {
			$occ = Database::occurrences_table();
			$tra = Database::translations_table();
			foreach ( array_chunk( array_keys( $hit ), 500 ) as $ids ) {
				$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$seen = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT string_id FROM {$occ} WHERE string_id IN ({$ph})", $ids ) ) ) ); // phpcs:ignore WordPress.DB
				if ( ! $seen ) {
					continue;
				}
				$ids = array_keys( $seen );
				$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$trs = $wpdb->get_results( $wpdb->prepare( "SELECT string_id, language, translation, status, provider FROM {$tra} WHERE string_id IN ({$ph}) AND translation <> ''", $ids ) ); // phpcs:ignore WordPress.DB
				foreach ( (array) $trs as $t ) {
					$lang = (string) $t->language;
					if ( empty( $rules[ $lang ] ) ) {
						continue;
					}
					$orig = $hit[ (int) $t->string_id ];
					foreach ( $rules[ $lang ] as $rule ) {
						list( $term, $must ) = $rule;
						// a text that is only the term is handled at display time already (exact match)
						if ( false === mb_stripos( $orig, $term ) || 0 === strcasecmp( trim( wp_strip_all_tags( $orig ) ), $term ) ) {
							continue;
						}
						if ( false === mb_stripos( (string) $t->translation, $must ) ) {
							$found[ $lang ][ (int) $t->string_id ] = array( (int) $t->string_id, (string) $t->translation, (int) $t->status, (string) $t->provider );
							break;
						}
					}
				}
			}
		}
		return array( $found, $last, count( (array) $rows ) );
	}

	/**
	 * The sentences to translate again, per language (the whole table at once: for checks and small sites).
	 *
	 * @return array<string,array<int,array{0:int,1:string,2:int,3:string}>> lang => rows (string id, translation, status, provider)
	 */
	public static function candidates(): array {
		$rules = self::rules();
		if ( ! $rules ) {
			return array();
		}
		$pat   = self::patterns( $rules );
		$out   = array();
		$after = 0;
		do {
			list( $found, $after, $read ) = self::scan_block( $after, $rules, $pat );
			foreach ( $found as $lang => $rows ) {
				$out[ $lang ] = ( $out[ $lang ] ?? array() ) + $rows;
			}
		} while ( $read >= self::CHUNK );
		return array_map( 'array_values', $out );
	}

	/**
	 * Start a new run (an unfinished one is dropped: what it already put back stays in its undo batch).
	 */
	public static function start(): void {
		global $wpdb;
		$batch = substr( sha1( microtime( true ) . '|' . wp_rand() ), 0, 10 );
		$max   = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Database::strings_table() ); // phpcs:ignore WordPress.DB
		update_option( self::JOB, array( 'batch' => $batch, 'after' => 0, 'max' => $max, 'parts' => 0, 'found' => 0, 'langs' => array() ), false );
		$all           = (array) get_option( self::BATCHES, array() );
		$all           = array( $batch => array( 'when' => time(), 'parts' => 0, 'sentences' => 0, 'languages' => 0 ) ) + $all;
		foreach ( array_slice( $all, 5, null, true ) as $old => $info ) { // keep five batches; the parts of older ones go
			self::drop_parts( (string) $old, (int) ( $info['parts'] ?? 0 ) );
			unset( $all[ $old ] );
		}
		update_option( self::BATCHES, $all, false );
	}

	/**
	 * Work for at most $budget seconds.
	 *
	 * @param float $budget Seconds.
	 * @return array{done:bool,batch:string,percent:int,sentences:int,languages:int}
	 */
	public static function step( float $budget = 5.0 ): array {
		global $wpdb;
		$job = get_option( self::JOB );
		if ( ! is_array( $job ) || empty( $job['batch'] ) ) {
			return array( 'done' => true, 'batch' => '', 'percent' => 100, 'sentences' => 0, 'languages' => 0 );
		}
		$t0    = microtime( true );
		$rules = self::rules();
		$pat   = $rules ? self::patterns( $rules ) : array();
		$tra   = Database::translations_table();
		$done  = ! $rules;
		while ( ! $done && microtime( true ) - $t0 < $budget ) {
			list( $found, $after, $read ) = self::scan_block( (int) $job['after'], $rules, $pat );
			$job['after'] = $after;
			if ( $found ) {
				foreach ( $found as $lang => $rows ) {
					foreach ( array_chunk( array_keys( $rows ), 500 ) as $chunk ) {
						$ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
						Strings::deleting( 'tr.language = %s AND tr.string_id IN (' . $ph . ')', array_merge( array( $lang ), $chunk ), 'terms' );
						$wpdb->query( $wpdb->prepare( "DELETE FROM {$tra} WHERE language = %s AND string_id IN ({$ph})", array_merge( array( $lang ), $chunk ) ) ); // phpcs:ignore WordPress.DB
					}
					$job['found']          += count( $rows );
					$job['langs'][ $lang ] = 1;
				}
				update_option( self::PART . $job['batch'] . '_' . $job['parts'], array_map( 'array_values', $found ), false );
				++$job['parts'];
			}
			$done = $read < self::CHUNK;
		}
		$all = (array) get_option( self::BATCHES, array() );
		if ( isset( $all[ $job['batch'] ] ) ) {
			$all[ $job['batch'] ]['parts']     = (int) $job['parts'];
			$all[ $job['batch'] ]['sentences'] = (int) $job['found'];
			$all[ $job['batch'] ]['languages'] = count( (array) $job['langs'] );
			update_option( self::BATCHES, $all, false );
		}
		if ( $done ) {
			delete_option( self::JOB );
			foreach ( array_keys( (array) $job['langs'] ) as $lang ) {
				Strings::flush_maps( (string) $lang );
			}
			if ( $job['found'] ) {
				Cache::flush();
			} else {
				unset( $all[ $job['batch'] ] );
				update_option( self::BATCHES, $all, false );
			}
		} else {
			update_option( self::JOB, $job, false );
		}
		$max = max( 1, (int) $job['max'] );
		return array(
			'done'      => $done,
			'batch'     => $job['found'] ? (string) $job['batch'] : '',
			'percent'   => $done ? 100 : min( 99, (int) floor( 100 * (int) $job['after'] / $max ) ),
			'sentences' => (int) $job['found'],
			'languages' => count( (array) $job['langs'] ),
		);
	}

	/**
	 * Everything in one go (WP-CLI, checks): start and step until done.
	 *
	 * @return array{batch:string,sentences:int,languages:int}
	 */
	public static function run(): array {
		self::start();
		do {
			$r = self::step( 3600 );
		} while ( ! $r['done'] );
		return array( 'batch' => $r['batch'], 'sentences' => $r['sentences'], 'languages' => $r['languages'] );
	}

	/**
	 * Put a batch back, a part at a time, for at most $budget seconds.
	 *
	 * @param string $batch  Batch id.
	 * @param float  $budget Seconds.
	 * @return array{done:bool,restored:int,percent:int}
	 */
	public static function undo_step( string $batch, float $budget = 5.0 ): array {
		global $wpdb;
		$all = (array) get_option( self::BATCHES, array() );
		if ( ! isset( $all[ $batch ] ) ) {
			return array( 'done' => true, 'restored' => 0, 'percent' => 100 );
		}
		$info  = $all[ $batch ];
		$parts = (int) ( $info['parts'] ?? 0 );
		$next  = (int) ( $info['undone'] ?? 0 );
		$n     = (int) ( $info['restored'] ?? 0 );
		$tra   = Database::translations_table();
		$langs = array();
		$t0    = microtime( true );
		// before 1.8.20.1 the rows were kept in the batch itself
		if ( isset( $info['rows'] ) && is_array( $info['rows'] ) ) {
			update_option( self::PART . $batch . '_0', $info['rows'], false );
			unset( $info['rows'] );
			$parts = max( 1, $parts );
		}
		while ( $next < $parts && microtime( true ) - $t0 < $budget ) {
			foreach ( (array) get_option( self::PART . $batch . '_' . $next, array() ) as $lang => $rows ) {
				foreach ( array_chunk( (array) $rows, 300 ) as $chunk ) {
					$vals = array();
					foreach ( $chunk as $r ) {
						if ( is_array( $r ) && count( $r ) >= 4 ) {
							$vals[] = $wpdb->prepare( '(%d,%s,%s,%d,%s,%s)', (int) $r[0], (string) $lang, (string) $r[1], (int) $r[2], (string) $r[3], current_time( 'mysql' ) );
						}
					}
					if ( $vals ) {
						$wpdb->query( "INSERT INTO {$tra} (string_id, language, translation, status, provider, updated_at) VALUES " . implode( ',', $vals ) . ' ON DUPLICATE KEY UPDATE translation = VALUES(translation), status = VALUES(status), provider = VALUES(provider), updated_at = VALUES(updated_at)' ); // phpcs:ignore WordPress.DB
						$n += count( $vals );
					}
				}
				$langs[ (string) $lang ] = 1;
			}
			delete_option( self::PART . $batch . '_' . $next );
			++$next;
		}
		foreach ( array_keys( $langs ) as $lang ) {
			Strings::flush_maps( (string) $lang );
		}
		$done = $next >= $parts;
		if ( $done ) {
			unset( $all[ $batch ] );
			if ( $n ) {
				Cache::flush();
			}
		} else {
			$all[ $batch ] = array_merge( $info, array( 'parts' => $parts, 'undone' => $next, 'restored' => $n ) );
		}
		update_option( self::BATCHES, $all, false );
		return array( 'done' => $done, 'restored' => $n, 'percent' => $done ? 100 : (int) floor( 100 * $next / max( 1, $parts ) ) );
	}

	/**
	 * Undo a whole batch in one go (checks, WP-CLI).
	 *
	 * @return int Translations put back.
	 */
	public static function undo( string $batch ): int {
		do {
			$r = self::undo_step( $batch, 3600 );
		} while ( ! $r['done'] );
		return (int) $r['restored'];
	}

	/**
	 * Remove the stored parts of a batch.
	 */
	private static function drop_parts( string $batch, int $parts ): void {
		for ( $i = 0; $i < $parts; $i++ ) {
			delete_option( self::PART . $batch . '_' . $i );
		}
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
