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
	const DB_VERSION = '3';

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
		foreach ( array( self::strings_table(), self::translations_table(), self::occurrences_table() ) as $table ) {
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
		self::create_tables();
		self::backfill_text_hash();
		update_option( 'trrocket_db_version', self::DB_VERSION );
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

		dbDelta( $sql_strings );
		dbDelta( $sql_translations );
		dbDelta( $sql_occurrences );

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
