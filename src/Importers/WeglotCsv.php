<?php
/**
 * Import translations from a Weglot CSV export.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Languages;
use TranslateRocket\Plugin;
use TranslateRocket\Settings;
use TranslateRocket\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * Weglot is a SaaS: translations live in their cloud, not in local tables, so
 * unlike the other importers this one reads the CSV file the user exports from
 * the Weglot dashboard (Translations → Export). The export has a header row
 * with at least `word_from` / `word_to` columns; depending on the export
 * options it may also carry a language column. Rows for languages that are not
 * configured as targets here are counted and skipped, never guessed.
 */
class WeglotCsv {

	/**
	 * Column aliases accepted for each role, lowercased.
	 *
	 * @var array<string,string[]>
	 */
	private const COLUMNS = array(
		'from' => array( 'word_from', 'from', 'original', 'source' ),
		'to'   => array( 'word_to', 'to', 'translation', 'translated' ),
		'lang' => array( 'language_to', 'language', 'lang', 'target_language', 'to_language' ),
	);

	/**
	 * Map a Weglot language code to ours.
	 */
	private static function map_lang( string $code ): string {
		$code = str_replace( '_', '-', strtolower( trim( $code ) ) );
		$map  = array(
			'zh-cn' => 'zh',    // Weglot emits 'zh' for Simplified; tolerate a resaved 'zh-cn'.
			'pt-pt' => 'pt',    // our 'pt' is European Portuguese.
			'nb'    => 'no',    // Weglot has a distinct Bokmål code; our 'no' uses the nb_NO locale.
		);
		return $map[ $code ] ?? $code;
	}

	/**
	 * Sniff the delimiter from the header line: Weglot uses commas, but
	 * spreadsheet round-trips often re-save with semicolons (or tabs).
	 */
	private static function sniff_delimiter( string $header_line ): string {
		$best  = ',';
		$count = substr_count( $header_line, ',' );
		foreach ( array( ';', "\t" ) as $candidate ) {
			$c = substr_count( $header_line, $candidate );
			if ( $c > $count ) {
				$count = $c;
				$best  = $candidate;
			}
		}
		return $best;
	}

	/**
	 * Find the index of the first matching column alias, or null.
	 *
	 * @param string[] $header  Lowercased header cells.
	 * @param string[] $aliases Accepted names for the role.
	 */
	private static function find_column( array $header, array $aliases ): ?int {
		foreach ( $aliases as $alias ) {
			$i = array_search( $alias, $header, true );
			if ( false !== $i ) {
				return (int) $i;
			}
		}
		return null;
	}

	/**
	 * Run the import.
	 *
	 * @param string $path          Path of the (already validated) uploaded file.
	 * @param string $fallback_lang Target language used when the file has no language column.
	 * @return array{count:int,skipped_lang:int,error:string}
	 */
	public static function import( string $path, string $fallback_lang ): array {
		$out = array(
			'count'        => 0,
			'skipped_lang' => 0,
			'error'        => '',
		);

		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming an uploaded temp file; WP_Filesystem cannot fgetcsv().
		if ( ! $fh ) {
			$out['error'] = 'open';
			return $out;
		}

		$first_line = (string) fgets( $fh );
		$first_line = preg_replace( '/^\xEF\xBB\xBF/', '', $first_line );
		$delimiter  = self::sniff_delimiter( $first_line );
		// Pass enclosure + an empty escape: the empty escape follows RFC 4180
		// (Weglot/Excel double their quotes, they never backslash-escape) and
		// silences PHP 8.4's deprecation of the implicit $escape parameter.
		$header     = str_getcsv( $first_line, $delimiter, '"', '' );
		$header     = array_map(
			static function ( $cell ) {
				return strtolower( trim( (string) $cell ) );
			},
			$header
		);

		$col_from = self::find_column( $header, self::COLUMNS['from'] );
		$col_to   = self::find_column( $header, self::COLUMNS['to'] );
		$col_lang = self::find_column( $header, self::COLUMNS['lang'] );

		if ( null === $col_from || null === $col_to ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the fopen() stream above.
			// Machine code, not prose: the caller renders the localized message.
			$out['error'] = 'columns';
			return $out;
		}

		$targets     = array_map( 'strval', (array) ( Settings::get()['target_languages'] ?? array() ) );
		$our_default = Plugin::instance()->router()->default_language();

		while ( ( $row = fgetcsv( $fh, 0, $delimiter, '"', '' ) ) !== false ) {
			// Weglot stores rendered text: decode entities on both sides so the
			// rows match the decoded DOM text our engine works with.
			$original    = html_entity_decode( trim( (string) ( $row[ $col_from ] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$translation = html_entity_decode( trim( (string) ( $row[ $col_to ] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( '' === $original || '' === $translation || $original === $translation ) {
				continue;
			}

			$lang = $fallback_lang;
			if ( null !== $col_lang && '' !== trim( (string) ( $row[ $col_lang ] ?? '' ) ) ) {
				$lang = self::map_lang( (string) $row[ $col_lang ] );
			}
			if ( '' === $lang || $lang === $our_default || ! Languages::exists( $lang ) || ! in_array( $lang, $targets, true ) ) {
				++$out['skipped_lang'];
				continue;
			}

			if ( Strings::store_imported( $original, $lang, $translation ) ) {
				++$out['count'];
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the fopen() stream above.

		return $out;
	}
}
