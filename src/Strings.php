<?php
/**
 * Source-string repository.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and queries detected strings, their translations and the pages they
 * appear on. Writes happen in batches (a few queries per page, not one per
 * string) and the per-language replacement map is cached in a transient.
 */
class Strings {

	/**
	 * Transient TTL for the replacement map.
	 */
	const MAP_TTL = 43200; // 12 hours.

	/**
	 * Virtual page URL that groups theme/plugin interface (gettext) strings.
	 */
	const INTERFACE_URL = '[interface]';

	/**
	 * Stable hash identifying a unique source string.
	 *
	 * @param string      $original Original text.
	 * @param string      $type     text|attribute|meta|slug.
	 * @param string|null $context  Extra qualifier (e.g. attribute name).
	 */
	public static function hash( string $original, string $type, ?string $context ): string {
		return sha1( $type . '|' . (string) $context . '|' . $original );
	}

	/**
	 * Stable hash for a page URL.
	 */
	public static function url_hash( string $url ): string {
		return sha1( $url );
	}

	/**
	 * Record a batch of strings found on one page, in a handful of queries:
	 * upsert sources, ensure (missing) translation rows, record occurrences.
	 *
	 * @param array<int,array{original:string,type:string,context:?string}> $items     Detected strings.
	 * @param string[]                                                       $secondary Target languages.
	 * @param string                                                         $url       Source page path.
	 * @param string                                                         $title     Source page title.
	 */
	public static function remember_batch( array $items, array $secondary, string $url = '', string $title = '' ): void {
		global $wpdb;

		// De-duplicate within this page by hash.
		$by_hash = array();
		foreach ( $items as $item ) {
			$original = trim( (string) ( $item['original'] ?? '' ) );
			if ( '' === $original ) {
				continue;
			}
			$type    = (string) ( $item['type'] ?? 'text' );
			$context = isset( $item['context'] ) ? (string) $item['context'] : '';
			$hash    = self::hash( $original, $type, $context );

			$by_hash[ $hash ] = array(
				'hash'     => $hash,
				'original' => $original,
				'context'  => $context,
				'type'     => $type,
			);
		}
		if ( empty( $by_hash ) ) {
			return;
		}

		$strings = Database::strings_table();
		$now     = current_time( 'mysql' );

		// 1) Upsert all source strings in one statement.
		$place = array();
		$vals  = array();
		foreach ( $by_hash as $row ) {
			$place[] = '(%s,%s,%s,%s,%s,%s,%s)';
			array_push( $vals, $row['hash'], sha1( $row['original'] ), $row['original'], $row['context'], $row['type'], $now, $now );
		}
		$sql = "INSERT INTO {$strings} (string_hash, text_hash, original, context, type, first_seen, last_seen) VALUES "
			. implode( ',', $place )
			. ' ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), text_hash = VALUES(text_hash)';
		$wpdb->query( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore WordPress.DB

		// 2) Resolve ids for these hashes in one query.
		$hashes      = array_keys( $by_hash );
		$placeholder = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows        = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, string_hash FROM {$strings} WHERE string_hash IN ({$placeholder})", $hashes ) // phpcs:ignore WordPress.DB -- dynamic %s list for IN(), one placeholder per value.
		); // phpcs:ignore WordPress.DB

		$id_by_hash = array();
		foreach ( $rows as $row ) {
			$id_by_hash[ $row->string_hash ] = (int) $row->id;
		}
		if ( empty( $id_by_hash ) ) {
			return;
		}

		// 3) Ensure a (missing) translation row per target language.
		if ( ! empty( $secondary ) ) {
			$translations = Database::translations_table();
			$place        = array();
			$vals         = array();
			foreach ( $id_by_hash as $id ) {
				foreach ( $secondary as $lang ) {
					$place[] = '(%d,%s,%d,%s)';
					array_push( $vals, $id, $lang, 0, $now );
				}
			}
			if ( ! empty( $place ) ) {
				$sql = "INSERT IGNORE INTO {$translations} (string_id, language, status, updated_at) VALUES " . implode( ',', $place );
				$wpdb->query( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore WordPress.DB
			}
		}

		// 4) Record occurrences (string ↔ page).
		if ( '' !== $url ) {
			$occurrences = Database::occurrences_table();
			$url_hash    = self::url_hash( $url );
			$url_short   = mb_substr( $url, 0, 191 );
			$title_short = mb_substr( $title, 0, 191 );

			$place = array();
			$vals  = array();
			foreach ( $id_by_hash as $id ) {
				$place[] = '(%d,%s,%s,%s,%s)';
				array_push( $vals, $id, $url_hash, $url_short, $title_short, $now );
			}
			$sql = "INSERT INTO {$occurrences} (string_id, url_hash, url, title, last_seen) VALUES "
				. implode( ',', $place )
				. ' ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), title = VALUES(title)';
			$wpdb->query( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore WordPress.DB
		}

		/**
		 * Fires after a page's translatable strings have been recorded.
		 *
		 * Extension point: an add-on can hook this to translate the page's new
		 * strings in the background (e.g. via Translator::translate_missing()).
		 *
		 * @param string[] $secondary Target languages.
		 * @param string   $url       Source page path ('' if none).
		 */
		do_action( 'trrocket_page_collected', $secondary, $url );
	}

	/**
	 * Total number of distinct source strings found.
	 */
	public static function total_sources(): int {
		global $wpdb;
		$strings = Database::strings_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$strings}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Counts for one language: detected / translated / missing.
	 *
	 * @return array{detected:int,translated:int,missing:int}
	 */
	public static function language_stats( string $lang ): array {
		global $wpdb;
		$translations = Database::translations_table();

		// Il totale sono TUTTE le stringhe sorgente conosciute, non solo quelle
		// gia' accodate per questa lingua: contando solo le righe della tabella
		// traduzioni, una lingua appena aggiunta risultava "0 su 0 rilevate" e
		// il pannello sembrava dire che non c'era niente da tradurre.
		$detected = self::total_sources();

		$translated = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$translations}
				 WHERE language = %s AND status > 0 AND translation IS NOT NULL AND translation <> ''",
				$lang
			)
		); // phpcs:ignore WordPress.DB

		return array(
			'detected'   => $detected,
			'translated' => $translated,
			'missing'    => max( 0, $detected - $translated ),
		);
	}

	/**
	 * Pages (distinct URLs) with per-language progress.
	 *
	 * @return array<int,object>
	 */
	public static function pages_with_stats( string $lang ): array {
		global $wpdb;
		$occurrences  = Database::occurrences_table();
		$translations = Database::translations_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.url_hash AS url_hash,
						MAX(o.url) AS url,
						MAX(o.title) AS title,
						COUNT(DISTINCT o.string_id) AS total,
						SUM( CASE WHEN tr.status > 0 AND tr.translation IS NOT NULL AND tr.translation <> '' THEN 1 ELSE 0 END ) AS translated
				 FROM {$occurrences} o
				 LEFT JOIN {$translations} tr ON tr.string_id = o.string_id AND tr.language = %s
				 WHERE o.url <> %s
				 GROUP BY o.url_hash
				 ORDER BY url ASC",
				$lang,
				self::INTERFACE_URL
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Translation progress for the interface-strings page in a language.
	 *
	 * @return array{total:int,translated:int}
	 */
	public static function interface_stats( string $lang ): array {
		$rows  = self::for_page_language( self::url_hash( self::INTERFACE_URL ), $lang );
		$total = count( $rows );
		$done  = 0;
		foreach ( $rows as $row ) {
			if ( (int) $row->status > 0 && null !== $row->translation && '' !== $row->translation ) {
				++$done;
			}
		}
		return array(
			'total'      => $total,
			'translated' => $done,
		);
	}

	/**
	 * URL + title for a page, looked up by its url hash.
	 */
	public static function page_info( string $url_hash ): ?object {
		global $wpdb;
		$occurrences = Database::occurrences_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT MAX(url) AS url, MAX(title) AS title FROM {$occurrences} WHERE url_hash = %s",
				$url_hash
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Strings on one page joined with their translation for the editor.
	 *
	 * @return array<int,object>
	 */
	public static function for_page_language( string $url_hash, string $lang ): array {
		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();
		$occurrences  = Database::occurrences_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT st.id AS string_id, st.original, st.type, st.context, tr.translation, tr.status
				 FROM {$occurrences} o
				 INNER JOIN {$strings} st ON st.id = o.string_id
				 LEFT JOIN {$translations} tr ON tr.string_id = st.id AND tr.language = %s
				 WHERE o.url_hash = %s
				 ORDER BY st.id ASC",
				$lang,
				$url_hash
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Untranslated strings on one page (for the copy-paste export).
	 *
	 * @return array<int,object>
	 */
	public static function untranslated_for_page_language( string $url_hash, string $lang ): array {
		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();
		$occurrences  = Database::occurrences_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT st.id AS string_id, st.original
				 FROM {$occurrences} o
				 INNER JOIN {$strings} st ON st.id = o.string_id
				 LEFT JOIN {$translations} tr ON tr.string_id = st.id AND tr.language = %s
				 WHERE o.url_hash = %s
				   AND ( tr.id IS NULL OR tr.status = 0 OR tr.translation IS NULL OR tr.translation = '' )
				 ORDER BY st.id ASC",
				$lang,
				$url_hash
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * All strings joined with their translation for a language (flat editor).
	 *
	 * @return array<int,object>
	 */
	public static function for_language( string $lang, int $limit = 300, int $offset = 0 ): array {
		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT st.id AS string_id, st.original, st.type, st.context, tr.translation, tr.status
				 FROM {$strings} st
				 LEFT JOIN {$translations} tr ON tr.string_id = st.id AND tr.language = %s
				 ORDER BY st.id ASC
				 LIMIT %d OFFSET %d",
				$lang,
				$limit,
				$offset
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Search every string (and its translation) for a language — the Translation
	 * Memory. Matches the source or the translation text; optionally only the
	 * still-missing ones.
	 *
	 * @param string $lang         Language code.
	 * @param string $term         Search term (empty = everything).
	 * @param int    $limit        Max rows.
	 * @param bool   $only_missing Only rows without a translation yet.
	 * @return array<int,object>
	 */
	public static function search( string $lang, string $term = '', int $limit = 300, bool $only_missing = false ): array {
		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();

		$where = array();
		$args  = array( $lang );
		if ( '' !== $term ) {
			$like    = '%' . $wpdb->esc_like( $term ) . '%';
			$where[] = '( st.original LIKE %s OR tr.translation LIKE %s )';
			$args[]  = $like;
			$args[]  = $like;
		}
		if ( $only_missing ) {
			$where[] = "( tr.id IS NULL OR tr.status = 0 OR tr.translation IS NULL OR tr.translation = '' )";
		}

		$sql = "SELECT st.id AS string_id, st.original, st.type, st.context, tr.translation, tr.status
				FROM {$strings} st
				LEFT JOIN {$translations} tr ON tr.string_id = st.id AND tr.language = %s";
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY st.id ASC LIMIT %d';
		$args[] = $limit;

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * How many source strings still have no usable translation in this language.
	 *
	 * Counts from the strings table with a LEFT JOIN, so a string that has never
	 * been queued for this language is included. language_stats() cannot do that:
	 * it counts rows in the translations table, so anything never touched for the
	 * language is invisible to it and the total comes out short (186 instead of
	 * 270 on a test site).
	 *
	 * @param string $lang Language code.
	 */
	public static function untranslated_count( string $lang ): int {
		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$strings} st
				 LEFT JOIN {$translations} tr ON tr.string_id = st.id AND tr.language = %s
				 WHERE st.type <> 'image'
				   AND ( tr.id IS NULL OR tr.status = 0 OR tr.translation IS NULL OR tr.translation = '' )",
				$lang
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * How many strings already have a usable translation in this language.
	 *
	 * The exact mirror of untranslated_count(): same exclusions, same idea of
	 * "usable", so the two add up to the total and a percentage built from them
	 * cannot go over 100.
	 *
	 * @param string $lang Language code.
	 */
	public static function translated_count( string $lang ): int {
		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$strings} st
				 INNER JOIN {$translations} tr ON tr.string_id = st.id AND tr.language = %s
				 WHERE st.type <> 'image'
				   AND tr.status <> 0
				   AND tr.translation IS NOT NULL
				   AND tr.translation <> ''",
				$lang
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Source rows still without a usable translation in this language.
	 *
	 * @param string $lang  Language code.
	 * @param int    $limit How many rows at most.
	 * @return array<int,object>
	 */
	public static function untranslated_for_language( string $lang, int $limit = 200 ): array {
		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT st.id AS string_id, st.original
				 FROM {$strings} st
				 LEFT JOIN {$translations} tr ON tr.string_id = st.id AND tr.language = %s
				 -- Le immagini restano fuori: il loro 'testo' e' un indirizzo di
				 -- file, e mandarlo a un traduttore lo storpierebbe facendo
				 -- sparire l'immagine dalla pagina. Si cambiano a mano, con il
				 -- selettore nell'editor visuale.
				 WHERE st.type <> 'image'
				   AND ( tr.id IS NULL OR tr.status = 0 OR tr.translation IS NULL OR tr.translation = '' )
				 ORDER BY st.id ASC
				 LIMIT %d",
				$lang,
				$limit
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Original => translation map for a language (cached in a transient).
	 *
	 * @return array<string,string>
	 */
	public static function map_for_language( string $lang ): array {
		$key    = 'trrocket_map_' . $lang;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT st.original AS o, tr.translation AS t
				 FROM {$translations} tr
				 INNER JOIN {$strings} st ON st.id = tr.string_id
				 WHERE tr.language = %s AND tr.status > 0 AND tr.translation IS NOT NULL AND tr.translation <> ''",
				$lang
			)
		); // phpcs:ignore WordPress.DB

		$map = array();
		foreach ( $rows as $row ) {
			$map[ trim( $row->o ) ] = $row->t;
		}

		// Glossary terms force a fixed translation for an exact string, overriding
		// (or supplying) the stored one — for consistent brand/product wording.
		foreach ( Settings::glossary( $lang ) as $term => $forced ) {
			$term = trim( (string) $term );
			if ( '' !== $term && '' !== (string) $forced ) {
				$map[ $term ] = (string) $forced;
			}
		}

		set_transient( $key, $map, self::MAP_TTL );
		return $map;
	}

	/**
	 * Above this many distinct source strings the full language map is too big to
	 * keep loading on every request, and the plugin switches to per-page batch
	 * lookups ("huge-safe" mode). Filterable for testing / edge setups.
	 */
	const HUGE_THRESHOLD = 20000;

	/**
	 * Per-request cache of the huge-mode decision (null = not decided yet).
	 *
	 * @var bool|null
	 */
	private static $is_huge = null;

	/**
	 * Per-request lookup cache: lang => [ text => translation|null ].
	 * Null marks "asked, no translation" so repeat lookups skip the query too.
	 *
	 * @var array<string,array<string,?string>>
	 */
	private static $lookup_cache = array();

	/**
	 * Whether the site has enough strings that loading a whole language map per
	 * request would hurt. The count is cached briefly — crossing the threshold is
	 * not time-critical and both modes are always correct.
	 */
	public static function is_huge(): bool {
		if ( null !== self::$is_huge ) {
			return self::$is_huge;
		}
		$threshold = (int) apply_filters( 'trrocket_huge_threshold', self::HUGE_THRESHOLD );
		$count     = get_transient( 'trrocket_source_count' );
		if ( false === $count ) {
			$count = self::total_sources();
			set_transient( 'trrocket_source_count', $count, 5 * MINUTE_IN_SECONDS );
		}
		self::$is_huge = (int) $count > $threshold;
		return self::$is_huge;
	}

	/**
	 * Cheap "is there anything translated for this language?" check — replaces
	 * loading the whole map just to see whether it's empty.
	 */
	public static function has_translations( string $lang ): bool {
		static $known = array();
		if ( isset( $known[ $lang ] ) ) {
			return $known[ $lang ];
		}
		global $wpdb;
		$translations   = Database::translations_table();
		$known[ $lang ] = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$translations}
				 WHERE language = %s AND status > 0 AND translation IS NOT NULL AND translation <> ''
				 LIMIT 1",
				$lang
			)
		); // phpcs:ignore WordPress.DB
		return $known[ $lang ];
	}

	/**
	 * Translations for exactly the given source texts — the mode-aware entry
	 * point every runtime consumer should use. Returns text => translation for
	 * the texts that have one (subset map: existing isset() replacement loops
	 * work unchanged).
	 *
	 * Under the threshold it filters the cached full map (identical behaviour
	 * and cost to before); on huge sites it batch-queries only these texts via
	 * the text_hash index, so memory stays proportional to the page, not the
	 * site. Glossary terms override in both modes, like in the full map.
	 *
	 * @param string[] $texts Source texts (will be trimmed).
	 * @return array<string,string>
	 */
	public static function translate_texts( array $texts, string $lang ): array {
		$wanted = array();
		foreach ( $texts as $text ) {
			$text = trim( (string) $text );
			if ( '' !== $text ) {
				$wanted[ $text ] = true;
			}
		}
		if ( empty( $wanted ) ) {
			return array();
		}

		if ( ! self::is_huge() ) {
			$map = self::map_for_language( $lang );
			$out = array();
			foreach ( $wanted as $text => $unused ) {
				if ( isset( $map[ $text ] ) ) {
					$out[ $text ] = $map[ $text ];
				}
			}
			return $out;
		}

		if ( ! isset( self::$lookup_cache[ $lang ] ) ) {
			self::$lookup_cache[ $lang ] = array();
		}
		$cache =& self::$lookup_cache[ $lang ];

		// Query only what this request hasn't already asked for.
		$missing = array();
		foreach ( $wanted as $text => $unused ) {
			if ( ! array_key_exists( $text, $cache ) ) {
				$missing[ sha1( $text ) ] = $text;
			}
		}

		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();

		foreach ( array_chunk( array_keys( $missing ), 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT st.original AS o, tr.translation AS t
					 FROM {$strings} st
					 INNER JOIN {$translations} tr ON tr.string_id = st.id AND tr.language = %s
					 WHERE st.text_hash IN ({$placeholders})
					   AND tr.status > 0 AND tr.translation IS NOT NULL AND tr.translation <> ''",
					array_merge( array( $lang ), $chunk )
				)
			); // phpcs:ignore WordPress.DB
			foreach ( $rows as $row ) {
				$cache[ trim( $row->o ) ] = $row->t;
			}
		}
		// Remember the misses too, so they aren't re-queried this request.
		foreach ( $missing as $text ) {
			if ( ! array_key_exists( $text, $cache ) ) {
				$cache[ $text ] = null;
			}
		}

		$out = array();
		foreach ( $wanted as $text => $unused ) {
			if ( isset( $cache[ $text ] ) ) {
				$out[ $text ] = (string) $cache[ $text ];
			}
		}
		// Glossary terms force a fixed translation for an exact string, exactly
		// like they do in the full map.
		foreach ( Settings::glossary( $lang ) as $term => $forced ) {
			$term = trim( (string) $term );
			if ( '' !== $term && '' !== (string) $forced && isset( $wanted[ $term ] ) ) {
				$out[ $term ] = (string) $forced;
			}
		}
		return $out;
	}

	/**
	 * Original => translation map for ONE page (by url hash) — used in huge mode
	 * where a whole-language map is off the table but a page-sized one is fine
	 * (e.g. the [interface] strings for the gettext layer). Cached per language
	 * and invalidated together with the full map.
	 *
	 * @return array<string,string>
	 */
	public static function map_for_page( string $url_hash, string $lang ): array {
		$key    = 'trrocket_pmap_' . substr( $url_hash, 0, 12 ) . '_' . $lang;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$map = array();
		foreach ( self::for_page_language( $url_hash, $lang ) as $row ) {
			if ( (int) $row->status > 0 && null !== $row->translation && '' !== $row->translation ) {
				$map[ trim( $row->original ) ] = $row->translation;
			}
		}
		foreach ( Settings::glossary( $lang ) as $term => $forced ) {
			$term = trim( (string) $term );
			if ( '' !== $term && '' !== (string) $forced ) {
				$map[ $term ] = (string) $forced;
			}
		}
		set_transient( $key, $map, self::MAP_TTL );
		return $map;
	}

	/**
	 * Drop every cached map/lookup artefact for a language (full map + page maps).
	 */
	public static function flush_maps( string $lang ): void {
		global $wpdb;
		delete_transient( 'trrocket_map_' . $lang );
		$suffix = '%' . $wpdb->esc_like( '_' . $lang );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_trrocket_pmap_' ) . $suffix,
				$wpdb->esc_like( '_transient_timeout_trrocket_pmap_' ) . $suffix
			)
		); // phpcs:ignore WordPress.DB
		unset( self::$lookup_cache[ $lang ] );
	}

	/**
	 * The source text behind each of these string ids, as id => original.
	 *
	 * Used to check translations that arrive from outside PHP: the caller says
	 * "this id holds this text", and this is how that claim gets verified.
	 *
	 * @param int[] $ids String ids.
	 * @return array<int,string>
	 */
	public static function originals_by_id( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_unique( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$strings = Database::strings_table();
		$holes   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is ours, the rest are %d placeholders.
				"SELECT id, original FROM {$strings} WHERE id IN ({$holes})",
				$ids
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->id ] = (string) $row->original;
		}
		return $out;
	}

	/**
	 * Save (upsert) one human translation and invalidate the cached map.
	 */
	public static function save_translation( int $string_id, string $lang, string $translation, int $status = 2, ?string $provider = null ): void {
		global $wpdb;
		$translations = Database::translations_table();

		$translation = trim( $translation );
		$status      = ( '' === $translation ) ? 0 : $status; // 1 = machine, 2 = human.

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$translations} (string_id, language, translation, status, provider, updated_at)
				 VALUES (%d, %s, %s, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE translation = VALUES(translation), status = VALUES(status), provider = VALUES(provider), updated_at = VALUES(updated_at)",
				$string_id,
				$lang,
				$translation,
				$status,
				(string) $provider,
				current_time( 'mysql' )
			)
		); // phpcs:ignore WordPress.DB

		self::flush_maps( $lang );
	}

	/**
	 * Delete every translation of one page for a language (intentional reset).
	 */
	public static function reset_page( string $url_hash, string $lang ): void {
		global $wpdb;
		$translations = Database::translations_table();
		$occurrences  = Database::occurrences_table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE tr FROM {$translations} tr
				 INNER JOIN {$occurrences} o ON o.string_id = tr.string_id
				 WHERE o.url_hash = %s AND tr.language = %s",
				$url_hash,
				$lang
			)
		); // phpcs:ignore WordPress.DB
		self::flush_maps( $lang );
	}

	/**
	 * Remove a page from tracking entirely (its string↔page occurrences), to
	 * clear a stale or accidentally-collected (404) page from the list. Shared
	 * source strings/translations are left untouched.
	 */
	public static function forget_page( string $url_hash ): void {
		global $wpdb;
		$occurrences = Database::occurrences_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$occurrences} WHERE url_hash = %s", $url_hash ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Cutoff datetime (UTC) for "not seen in the last N days".
	 */
	private static function stale_cutoff( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
	}

	/**
	 * How many strings haven't been seen on any page in the last N days (their
	 * newest occurrence is older than the cutoff, or they have none at all).
	 */
	public static function count_stale( int $days = 30 ): int {
		global $wpdb;
		$strings     = Database::strings_table();
		$occurrences = Database::occurrences_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT st.id FROM {$strings} st
					INNER JOIN {$occurrences} o ON o.string_id = st.id
					GROUP BY st.id
					HAVING MAX(o.last_seen) < %s
				) t",
				self::stale_cutoff( $days )
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete strings not seen on any page in the last N days, with their
	 * translations and occurrences — frees the list of content that no longer
	 * exists. Returns how many strings were removed.
	 */
	public static function delete_stale( int $days = 30 ): int {
		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();
		$occurrences  = Database::occurrences_table();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT st.id FROM {$strings} st
				 INNER JOIN {$occurrences} o ON o.string_id = st.id
				 GROUP BY st.id
				 HAVING MAX(o.last_seen) < %s",
				self::stale_cutoff( $days )
			)
		); // phpcs:ignore WordPress.DB

		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$translations} WHERE string_id IN ({$ph})", $ids ) ); // phpcs:ignore WordPress.DB -- dynamic %d list for IN(), one placeholder per value.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$occurrences} WHERE string_id IN ({$ph})", $ids ) ); // phpcs:ignore WordPress.DB -- dynamic %d list for IN(), one placeholder per value.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$strings} WHERE id IN ({$ph})", $ids ) ); // phpcs:ignore WordPress.DB -- dynamic %d list for IN(), one placeholder per value.
		// The cached language/page maps may reference deleted strings — drop them.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_trrocket_map_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_trrocket_map_' ) . '%',
			$wpdb->esc_like( '_transient_trrocket_pmap_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_trrocket_pmap_' ) . '%'
		) );
		return count( $ids );
	}

	/**
	 * Create a source string (if new) and store its translation in one call.
	 * Used by migration importers. Returns true if a translation was stored.
	 */
	public static function store_imported( string $original, string $lang, string $translation, string $type = 'text', ?string $context = null ): bool {
		$original    = trim( $original );
		$translation = trim( $translation );
		if ( '' === $original || '' === $translation ) {
			return false;
		}

		// Imported strings come from files/tables the site owner did not author
		// by hand (a Weglot export, another plugin's tables). The engine only
		// ever re-injects them as text nodes and attribute values, but the
		// gettext layer substitutes them raw into __() output, so an untrusted
		// "<script>…" translation would be stored XSS. Strip disallowed markup
		// with the plugin's own ruleset — legitimate translations keep their
		// inline formatting; scripts and event handlers are removed. Site
		// administrators with the unfiltered_html capability keep full freedom.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$translation = trim( Kses::translation( $translation ) );
			if ( '' === $translation ) {
				return false;
			}
		}

		global $wpdb;
		$strings = Database::strings_table();
		$hash    = self::hash( $original, $type, $context );
		$now     = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$strings} (string_hash, text_hash, original, context, type, first_seen, last_seen)
				 VALUES (%s, %s, %s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), last_seen = VALUES(last_seen)",
				$hash,
				sha1( $original ),
				$original,
				(string) $context,
				$type,
				$now,
				$now
			)
		); // phpcs:ignore WordPress.DB

		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return false;
		}
		self::save_translation( $id, $lang, $translation, 1, 'import' );
		return true;
	}

	/**
	 * Every source string with its translations, for CSV export.
	 *
	 * @param string[] $langs Target language codes.
	 * @return array<int,array<string,mixed>> Rows: o, type, ctx, tr[lang]=translation.
	 */
	public static function export_rows( array $langs ): array {
		global $wpdb;
		$strings      = Database::strings_table();
		$translations = Database::translations_table();

		if ( empty( $langs ) ) {
			$rows = $wpdb->get_results( "SELECT original AS o, type, context AS ctx FROM {$strings} ORDER BY id" ); // phpcs:ignore WordPress.DB
			return array_map(
				function ( $r ) {
					return array(
						'o'    => (string) $r->o,
						'type' => (string) $r->type,
						'ctx'  => (string) $r->ctx,
						'tr'   => array(),
					);
				},
				$rows
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $langs ), '%s' ) );
		$rows         = $wpdb->get_results(
			// phpcs:disable WordPress.DB -- dynamic %s list for IN(), one placeholder per language.
			$wpdb->prepare(
				"SELECT s.id, s.original AS o, s.type, s.context AS ctx, t.language AS lang, t.translation AS tr
				 FROM {$strings} s
				 LEFT JOIN {$translations} t ON t.string_id = s.id AND t.language IN ($placeholders)
				 ORDER BY s.id",
				...$langs
			)
			// phpcs:enable WordPress.DB
		);

		$map = array();
		foreach ( $rows as $r ) {
			if ( ! isset( $map[ $r->id ] ) ) {
				$map[ $r->id ] = array(
					'o'    => (string) $r->o,
					'type' => (string) $r->type,
					'ctx'  => (string) $r->ctx,
					'tr'   => array(),
				);
			}
			if ( null !== $r->lang && null !== $r->tr ) {
				$map[ $r->id ]['tr'][ $r->lang ] = (string) $r->tr;
			}
		}
		return array_values( $map );
	}

	/**
	 * Save a translation by its source text (find-or-create the string), marked as
	 * a human edit. Used by the front-end visual editor. Returns true on success.
	 */
	public static function save_by_source( string $original, string $lang, string $translation, string $type = 'text', ?string $context = null ): bool {
		$original = trim( $original );
		if ( '' === $original ) {
			return false;
		}
		global $wpdb;
		$strings = Database::strings_table();
		$hash    = self::hash( $original, $type, $context );
		$now     = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$strings} (string_hash, text_hash, original, context, type, first_seen, last_seen)
				 VALUES (%s, %s, %s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), last_seen = VALUES(last_seen)",
				$hash,
				sha1( $original ),
				$original,
				(string) $context,
				$type,
				$now,
				$now
			)
		); // phpcs:ignore WordPress.DB
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return false;
		}
		self::save_translation( $id, $lang, $translation, 2, 'visual' );
		return true;
	}
}
