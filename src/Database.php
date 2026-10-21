<?php
/**
 * Database schema and table helpers.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the custom tables used by the plugin:
 *
 *   {prefix}trrocket_strings       — every unique source string found on the site
 *   {prefix}trrocket_translations  — one row per (string, language) pair
 *   {prefix}trrocket_occurrences   — where each string appears (string ↔ page URL)
 *
 * Occurrences make the editor page-aware: we can list pages with per-language
 * progress instead of one endless flat list of strings.
 */
class Database {

	/**
	 * Schema version. Bump when the table structure changes.
	 */
	const DB_VERSION = '10'; // 10: the clean-up of schema 9 once more, with code examples made only of tags (1.8.12)
	// 6: the search index (14/10/2026); 7: visits per language (1.8.6); 8: the pages of the search index, for the light suggestions (1.8.10); 9: addresses, codes and tags collected as sentences removed (1.8.11)

	/**
	 * Source strings table name.
	 */
	public static function strings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'trrocket_strings';
	}

	/**
	 * Translations table name.
	 */
	public static function translations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'trrocket_translations';
	}

	/**
	 * Occurrences (string ↔ page) table name.
	 */
	public static function occurrences_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'trrocket_occurrences';
	}

	/**
	 * Plugin tables that are not present in the database (empty = all good).
	 * Used by the Diagnostics page.
	 *
	 * @return string[]
	 */
	public static function missing_tables(): array {
		global $wpdb;
		$missing = array();
		foreach ( array( self::strings_table(), self::translations_table(), self::occurrences_table(), \TranslateRocket\Frontend\Search::table(), \TranslateRocket\Frontend\Search::posts_table(), \TranslateRocket\Stats::table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- schema check, table name is internal.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	/**
	 * Run pending schema upgrades (cheap option compare, runs in admin).
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'trrocket_db_version' ) === self::DB_VERSION ) {
			return;
		}
		// 1.8.14 (oasicana.it on Aruba: «the update of TranslateRocket from 1.8.11 to 1.8.13 failed», and «it happens
		// often»): right after an automatic update WordPress loads the home page once to look for a fatal error, and
		// rolls the update back if that request fails or takes over 50 seconds. That request was the first one of the new
		// version, so it ran the whole schema upgrade — tables, then every sentence scanned — on a slow shared host. The
		// check is left alone: the upgrade runs on the next request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only read to recognise WordPress's own check.
		if ( isset( $_REQUEST['wp_scrape_key'], $_REQUEST['wp_scrape_nonce'] ) ) {
			return;
		}
		// One request at a time: on a visited site the first seconds after an update brought several requests, each
		// running the same upgrade. A lock older than 5 minutes (a request killed) is taken over.
		if ( ! add_option( 'trrocket_db_upgrading', time(), '', 'no' ) ) {
			if ( time() - (int) get_option( 'trrocket_db_upgrading', 0 ) < 5 * MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( 'trrocket_db_upgrading', time(), false );
		}
		try {
			self::create_tables();
			self::backfill_text_hash();
			self::remove_orphans();
			self::remove_customer_details();
			self::remove_untranslatable();
			update_option( 'trrocket_db_version', self::DB_VERSION );
		} finally {
			delete_option( 'trrocket_db_upgrading' );
		}
	}

	/**
	 * Sentences that are not sentences (schema 9, 1.8.11): addresses, paths, domains, bare tags, language codes
	 * collected before Engine::is_noise() learnt to leave them out. They can never be translated, so they kept
	 * every language short of 100%. Only the ones nobody translated go; a row somebody did translate stays.
	 */
	public static function remove_untranslatable(): void {
		global $wpdb;
		$st  = self::strings_table();
		$tr  = self::translations_table();
		$occ = self::occurrences_table();
		$ids = array();
		$da  = 0;
		do {
			$righe = $wpdb->get_results( $wpdb->prepare( "SELECT id, original FROM {$st} WHERE id > %d AND type <> 'image' ORDER BY id ASC LIMIT 2000", $da ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $righe as $r ) {
				$da = (int) $r->id;
				if ( \TranslateRocket\Frontend\Engine::is_noise( (string) $r->original ) ) {
					$ids[] = (int) $r->id;
				}
			}
		} while ( count( (array) $righe ) === 2000 );
		foreach ( array_chunk( $ids, 500 ) as $parte ) {
			$lista    = implode( ',', $parte );
			$tradotte = $wpdb->get_col( "SELECT DISTINCT string_id FROM {$tr} WHERE string_id IN ({$lista}) AND status > 0 AND translation IS NOT NULL AND translation <> ''" ); // phpcs:ignore WordPress.DB
			$via      = array_values( array_diff( $parte, array_map( 'intval', (array) $tradotte ) ) );
			if ( empty( $via ) ) {
				continue;
			}
			$lista = implode( ',', $via );
			$wpdb->query( "DELETE FROM {$occ} WHERE string_id IN ({$lista})" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM {$tr} WHERE string_id IN ({$lista})" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM {$st} WHERE id IN ({$lista})" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Customers' own details that the order e-mails collected as sentences before 1.7.1
	 * (schema v5, 1/10/2026): a name, a street, a city, an e-mail, «Hi Anna,». Only strings
	 * seen nowhere but in WooCommerce e-mails or PDFs are looked at, and one goes only when
	 * it is made of details of a real order: once they are taken out, at most two words are
	 * left. «Your order will ship soon» stays even with a customer called Will.
	 */
	public static function remove_customer_details(): void {
		global $wpdb;
		$s = self::strings_table();
		$o = self::occurrences_table();
		$t = self::translations_table();
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- one-time cleanup on the plugin's own tables and WooCommerce's order data; table names from the code, values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.original FROM {$s} s INNER JOIN {$o} o ON o.string_id = s.id
				 GROUP BY s.id, s.original HAVING SUM( o.url NOT IN ( %s, %s ) ) = 0",
				'[woocommerce emails]',
				'[woocommerce pdf]'
			)
		);
		if ( empty( $rows ) ) {
			return;
		}
		$keys = array();
		foreach ( array( 'billing', 'shipping' ) as $who ) {
			foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'state', 'email', 'phone' ) as $f ) {
				$keys[] = '_' . $who . '_' . $f;
			}
		}
		$hpos = $wpdb->prefix . 'wc_order_addresses';
		$hpos = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $hpos ) ) ) === $hpos ) ? $hpos : '';
		$cols = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'state', 'email', 'phone' );
		$low  = static function ( $v ) {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $v ) ) : strtolower( trim( (string) $v ) );
		};

		foreach ( array_chunk( $rows, 300 ) as $chunk ) {
			// What to look up: each string whole and each of its words.
			$look = array();
			foreach ( $chunk as $r ) {
				$look[ $r->original ] = true;
				foreach ( preg_split( '/[\s,;:()]+/u', (string) $r->original ) as $w ) {
					$w = trim( $w, '.!?"\'' );
					if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $w ) : strlen( $w ) ) >= 2 ) {
						$look[ $w ] = true;
					}
				}
			}
			$look  = array_keys( $look );
			$found = array();
			foreach ( array_chunk( $look, 500 ) as $part ) {
				$in   = implode( ',', array_fill( 0, count( $part ), '%s' ) );
				$kin  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
				$vals = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$kin} ) AND meta_value IN ( {$in} )", array_merge( $keys, $part ) ) );
				if ( '' !== $hpos ) {
					foreach ( $cols as $c ) {
						$vals = array_merge( $vals, (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT {$c} FROM {$hpos} WHERE {$c} IN ( {$in} )", $part ) ) );
					}
				}
				foreach ( $vals as $v ) {
					$v = $low( $v );
					if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $v ) : strlen( $v ) ) >= 2 ) {
						$found[ $v ] = true;
					}
				}
			}
			if ( empty( $found ) ) {
				continue;
			}
			// Longest first, so «Anna Maria» goes before «Anna».
			$found = array_keys( $found );
			usort(
				$found,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			$gone = array();
			foreach ( $chunk as $r ) {
				$rest = $low( $r->original );
				$hit  = false;
				foreach ( $found as $v ) {
					$re = '/(?<![\p{L}\p{N}])' . preg_quote( $v, '/' ) . '(?![\p{L}\p{N}])/u';
					if ( preg_match( $re, $rest ) ) {
						$rest = (string) preg_replace( $re, ' ', $rest );
						$hit  = true;
					}
				}
				if ( $hit && preg_match_all( '/[\p{L}\p{N}]+/u', $rest ) <= 2 ) {
					$gone[] = (int) $r->id;
				}
			}
			if ( empty( $gone ) ) {
				continue;
			}
			$in = implode( ',', $gone );
			Strings::deleting( "tr.string_id IN ( {$in} ) AND 1 = %d", array( 1 ), 'privacy' );
			$wpdb->query( "DELETE FROM {$t} WHERE string_id IN ( {$in} )" );
			$wpdb->query( "DELETE FROM {$o} WHERE string_id IN ( {$in} )" );
			$wpdb->query( "DELETE FROM {$s} WHERE id IN ( {$in} )" );
		}
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
	}

	/**
	 * Translations whose source string no longer exists (schema v4, 30/9/2026). Older versions
	 * could leave them behind; nothing can show or export them, but they were still counted, so a
	 * TMX or CSV round trip seemed to «lose» them. One query, on upgrade only.
	 */
	private static function remove_orphans(): void {
		global $wpdb;
		$t = self::translations_table();
		$s = self::strings_table();
		$wpdb->query( "DELETE t FROM {$t} t LEFT JOIN {$s} s ON s.id = t.string_id WHERE s.id IS NULL" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Fill text_hash for rows created before schema v3. MySQL's SHA1() over the
	 * stored (already trimmed) original matches PHP's sha1() on the same bytes.
	 * Batched so the upgrade never holds a huge lock on big sites.
	 */
	public static function backfill_text_hash(): void {
		global $wpdb;
		$strings = self::strings_table();
		do {
			// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- one-time schema migration on the plugin's own table; the name comes from strings_table(), no user input.
			$updated = $wpdb->query(
				"UPDATE {$strings} SET text_hash = SHA1(original)
				 WHERE text_hash IS NULL OR text_hash = ''
				 LIMIT 10000"
			);
			// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB
		} while ( $updated > 0 );
	}

	/**
	 * Create (or upgrade) the custom tables using dbDelta().
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset      = $wpdb->get_charset_collate();
		$strings      = self::strings_table();
		$translations = self::translations_table();
		$occurrences  = self::occurrences_table();

		// Source strings: each distinct piece of translatable content.
		// text_hash indexes the plain source text (sha1 of the trimmed original,
		// regardless of type/context) so translations can be looked up in batches
		// for exactly the strings present on one page — the "huge-safe" path that
		// avoids loading the whole language map on sites with 100k+ strings.
		$sql_strings = "CREATE TABLE {$strings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			string_hash CHAR(40) NOT NULL,
			text_hash CHAR(40) DEFAULT NULL,
			original LONGTEXT NOT NULL,
			context VARCHAR(191) DEFAULT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'text',
			first_seen DATETIME DEFAULT NULL,
			last_seen DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY string_hash (string_hash),
			KEY text_hash (text_hash),
			KEY type (type)
		) {$charset};";

		// Translations: one row per source string per target language.
		// status: 0 = missing, 1 = machine, 2 = human edited, 3 = reviewed.
		$sql_translations = "CREATE TABLE {$translations} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			string_id BIGINT UNSIGNED NOT NULL,
			language VARCHAR(20) NOT NULL,
			translation LONGTEXT,
			status TINYINT NOT NULL DEFAULT 0,
			provider VARCHAR(30) DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY string_lang (string_id, language),
			KEY language (language),
			KEY status (status)
		) {$charset};";

		// Occurrences: which page (URL) each string was seen on.
		$sql_occurrences = "CREATE TABLE {$occurrences} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			string_id BIGINT UNSIGNED NOT NULL,
			url_hash CHAR(40) NOT NULL,
			url VARCHAR(191) NOT NULL,
			title VARCHAR(191) DEFAULT NULL,
			last_seen DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY string_url (string_id, url_hash),
			KEY url_hash (url_hash)
		) {$charset};";

		// The search index (6, 14/10/2026): which content sentences each page has, and where (title, excerpt, body).
		$search     = \TranslateRocket\Frontend\Search::table();
		$sql_search = "CREATE TABLE {$search} (
			post_id BIGINT UNSIGNED NOT NULL,
			string_id BIGINT UNSIGNED NOT NULL,
			weight TINYINT UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY  (post_id,string_id),
			KEY string_id (string_id)
		) {$charset};";

		// The pages of the search index (8, 1.8.10): address, title and kind of every indexed page, so the light
		// suggestions endpoint (suggest.php, loaded with SHORTINIT) can answer without WordPress's permalinks.
		$posts     = \TranslateRocket\Frontend\Search::posts_table();
		$sql_posts = "CREATE TABLE {$posts} (
			post_id BIGINT UNSIGNED NOT NULL,
			url TEXT NOT NULL,
			title TEXT NOT NULL,
			ptype VARCHAR(40) NOT NULL DEFAULT '',
			label VARCHAR(100) NOT NULL DEFAULT '',
			PRIMARY KEY  (post_id)
		) {$charset};";

		// Visits per language (7, 1.8.6): one row per day, language, page and browser language — no visitor data.
		$stats     = \TranslateRocket\Stats::table();
		$sql_stats = "CREATE TABLE {$stats} (
			day DATE NOT NULL,
			lang VARCHAR(20) NOT NULL,
			path_hash CHAR(40) NOT NULL,
			path VARCHAR(191) NOT NULL,
			browser VARCHAR(3) NOT NULL DEFAULT '',
			views INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,lang,path_hash,browser),
			KEY lang_day (lang,day)
		) {$charset};";

		dbDelta( $sql_strings );
		dbDelta( $sql_translations );
		dbDelta( $sql_occurrences );
		dbDelta( $sql_search );
		dbDelta( $sql_posts );
		dbDelta( $sql_stats );

		// If a table still isn't there, the plugin can't store anything — make that
		// loud rather than letting saves fail silently later.
		$missing = self::missing_tables();
		if ( ! empty( $missing ) ) {
			Logger::error(
				'db',
				__( 'Database tables could not be created.', 'translate-rocket' ),
				implode( ', ', $missing ) . ( '' !== (string) $wpdb->last_error ? ' — ' . $wpdb->last_error : '' )
			);
		}
	}
}
