<?php
/**
 * Import translations from Autoglot.
 *
 * Autoglot translates the rendered page and keeps every sentence it paid for in
 * one table of its own, `{prefix}autoglot_translations`: `original`, `translated`,
 * `lang` (its own code: `it`, `pt-br`, `zh-cn`), `type` (`content` for page text,
 * `manual` for the owner's own replacements, `url` for translated addresses) and
 * a `texthash`. The site is written in the WordPress locale; the languages it
 * serves sit in the option `autoglot_translation_active_languages`.
 *
 * A row is either a plain sentence (whitespace collapsed) or the inner HTML of a
 * block whose inline tags were stripped of their attributes and marked with
 * `agtr="n"` — the same sentence-with-links shape the other importers pair line
 * by line. Addresses are not text and are left out; a row still marked
 * «autoglot_translation_inprogress» never got its translation.
 *
 * Read straight from the table, so it works with Autoglot switched off.
 * Format read in Autoglot 2.11.17 (`utils/autoglot_dom.php`, `autoglot.php`).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Languages;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Autoglot's table.
 */
class AutoglotImporter implements ImporterInterface {

	private const BLOCCO  = 500;
	private const MEMORIA = 5 * MINUTE_IN_SECONDS;
	private const IN_CORSO = 'autoglot_translation_inprogress';

	public function id(): string {
		return 'autoglot';
	}

	public function label(): string {
		return 'Autoglot';
	}

	private function tabella(): string {
		global $wpdb;
		return $wpdb->prefix . 'autoglot_translations';
	}

	private function c_e(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tabella() ) ); // phpcs:ignore WordPress.DB
	}

	public function is_available(): bool {
		global $wpdb;
		if ( ! $this->c_e() ) {
			return false;
		}
		$t = $this->tabella();
		return (bool) $wpdb->get_var( "SELECT id FROM `{$t}` WHERE type <> 'url' LIMIT 1" ); // phpcs:ignore WordPress.DB
	}

	public function count_available(): int {
		$chiave = 'trrocket_imp_autoglot_n';
		$noto   = get_transient( $chiave );
		if ( false !== $noto ) {
			return (int) $noto;
		}
		$n = $this->cammina( false );
		set_transient( $chiave, (string) $n, self::MEMORIA );
		return $n;
	}

	/**
	 * @return array{count:int,error:string}
	 */
	public function import(): array {
		$n = $this->cammina( true );
		delete_transient( 'trrocket_imp_autoglot_n' );
		return array(
			'count' => $n,
			'error' => '',
		);
	}

	/**
	 * Autoglot's language codes mapped to ours, minus the source language.
	 *
	 * @return array<string,string> Autoglot code => our code.
	 */
	private function lingue(): array {
		$nostra_partenza = (string) ( Settings::get()['source_language'] ?? 'en' );
		$attive          = get_option( 'autoglot_translation_active_languages', array() );
		$out             = array();
		foreach ( (array) $attive as $codice ) {
			$codice = strtolower( trim( (string) $codice ) );
			if ( '' === $codice ) {
				continue;
			}
			$nostro = Comune::lingua( $codice );
			if ( $nostro === $nostra_partenza || ! Languages::exists( $nostro ) ) {
				continue;
			}
			$out[ $codice ] = $nostro;
		}
		return $out;
	}

	private function cammina( bool $salva ): int {
		global $wpdb;
		$lingue = $this->lingue();
		if ( empty( $lingue ) || ! $this->c_e() ) {
			return 0;
		}
		$t      = $this->tabella();
		$totale = 0;
		$ultimo = 0;
		$in     = implode( ',', array_map( static function ( $c ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $c );
		}, array_keys( $lingue ) ) );
		do {
			$righe = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT id, lang, original, translated FROM `{$t}`
				 WHERE id > %d AND type <> 'url' AND lang IN ({$in})
				   AND original IS NOT NULL AND translated IS NOT NULL AND translated <> '' AND translated <> %s
				 ORDER BY id ASC LIMIT %d",
				$ultimo,
				self::IN_CORSO,
				self::BLOCCO
			) );
			foreach ( $righe as $r ) {
				$ultimo = (int) $r->id;
				$lingua = $lingue[ strtolower( (string) $r->lang ) ] ?? '';
				if ( '' === $lingua ) {
					continue;
				}
				$totale += $this->coppia( (string) $r->original, (string) $r->translated, $lingua, $salva );
			}
		} while ( count( $righe ) === self::BLOCCO );
		return $totale;
	}

	/**
	 * One row: a sentence, or a block with its inline tags.
	 */
	private function coppia( string $originale, string $tradotto, string $lingua, bool $salva ): int {
		// Autoglot's placeholders for the attributes it took away («<a agtr="0">»):
		// the pairing looks at the words and the tags, not at the attributes.
		$originale = (string) preg_replace( '/\s+agtr="\d+"/', '', $originale );
		$tradotto  = (string) preg_replace( '/\s+agtr="\d+"/', '', $tradotto );
		// Rows come from the rendered page, entities included («Bed &amp; Breakfast»);
		// the engine matches decoded text.
		$originale = html_entity_decode( $originale, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$tradotto  = html_entity_decode( $tradotto, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$originale = trim( (string) preg_replace( '/\s+/u', ' ', $originale ) );
		$tradotto  = trim( (string) preg_replace( '/\s+/u', ' ', $tradotto ) );
		if ( '' === $originale || '' === $tradotto ) {
			return 0;
		}
		return Comune::testo_o_righe( $originale, $tradotto, $lingua, $salva );
	}
}
