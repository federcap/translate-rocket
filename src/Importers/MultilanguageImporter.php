<?php
/**
 * Import translations from Multilanguage (BestWebSoft).
 *
 * This one keeps a table of its own, `{prefix}mltlngg_translate`, with one row
 * per post per language holding the translated title, excerpt and content. The
 * original stays in `wp_posts`, so pairing is exact: row → post.
 *
 * A second table, `{prefix}mltlngg_terms_translate`, holds category and tag
 * names. It is read too: those show up in menus and archive headings, and are
 * some of the most visible text on a site.
 *
 * Everything is read straight from the tables, so it works with the plugin
 * deactivated.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Languages;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads BestWebSoft's Multilanguage tables.
 */
class MultilanguageImporter implements ImporterInterface {

	private const BLOCCO = 200;
	// Solo per il conteggio, che e' la parte cara. Cinque minuti: abbastanza
	// per non rifare il giro a ogni ricarica, poco per non mentire a lungo.
	private const MEMORIA = 5 * MINUTE_IN_SECONDS;

	public function id(): string {
		return 'multilanguage';
	}

	public function label(): string {
		return 'Multilanguage (BestWebSoft)';
	}

	private function tabella(): string {
		global $wpdb;
		return $wpdb->prefix . 'mltlngg_translate';
	}

	private function tabella_termini(): string {
		global $wpdb;
		return $wpdb->prefix . 'mltlngg_terms_translate';
	}

	/**
	 * Whether a table is really there. Asking for rows in a table that does not
	 * exist is a database error in the log, not an empty result.
	 */
	private function c_e( string $tabella ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabella ) ); // phpcs:ignore WordPress.DB
	}

	public function is_available(): bool {
		// SHOW TABLES piu' una riga: si risponde al volo, senza ricordare.
		global $wpdb;
		if ( ! $this->c_e( $this->tabella() ) ) {
			return false;
		}
		$t = $this->tabella();
		return (bool) $wpdb->get_var( "SELECT ID FROM `{$t}` LIMIT 1" ); // phpcs:ignore WordPress.DB
	}

	public function count_available(): int {
		$chiave = 'trrocket_imp_mltlngg_n';
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
		delete_transient( 'trrocket_imp_mltlngg_c' );
		delete_transient( 'trrocket_imp_mltlngg_n' );
		return array(
			'count' => $n,
			'error' => '',
		);
	}

	private function cammina( bool $salva ): int {
		global $wpdb;

		$nostra_partenza = (string) ( Settings::get()['source_language'] ?? 'en' );
		$totale = 0;

		// --- gli articoli --------------------------------------------------
		if ( $this->c_e( $this->tabella() ) ) {
			$t     = $this->tabella();
			$salta = 0;
			do {
				$righe = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
					"SELECT post_ID, post_title, post_excerpt, post_content, language
					 FROM `{$t}` ORDER BY ID LIMIT %d OFFSET %d",
					self::BLOCCO,
					$salta
				) );
				$salta += self::BLOCCO;

				foreach ( $righe as $r ) {
					$lingua = Comune::lingua( (string) $r->language );
					if ( $lingua === $nostra_partenza || ! Languages::exists( $lingua ) ) {
						continue;
					}
					$origine = get_post( (int) $r->post_ID );
					if ( ! $origine ) {
						continue;
					}
					// La riga ha la stessa forma di un post: si passa
					// all'appaiamento come se fosse il post tradotto.
					$tradotto = (object) array(
						'post_title'   => (string) $r->post_title,
						'post_excerpt' => (string) $r->post_excerpt,
						'post_content' => (string) $r->post_content,
					);
					$totale += Comune::appaia( $origine, $tradotto, $lingua, $salva );
				}
			} while ( count( $righe ) === self::BLOCCO );
		}

		// --- categorie ed etichette ----------------------------------------
		if ( $this->c_e( $this->tabella_termini() ) ) {
			$totale += $this->termini( $nostra_partenza, $salva );
		}

		return $totale;
	}

	/**
	 * Category and tag names. The columns of this table vary between versions,
	 * so it is read defensively: whatever looks like a term id and a name is
	 * used, and anything unexpected is skipped rather than guessed at.
	 */
	private function termini( string $nostra_partenza, bool $salva ): int {
		global $wpdb;

		$t     = $this->tabella_termini();
		$righe = (array) $wpdb->get_results( "SELECT * FROM `{$t}` LIMIT 5000" ); // phpcs:ignore WordPress.DB
		$fatti = 0;

		foreach ( $righe as $r ) {
			$campi = get_object_vars( $r );

			$id     = null;
			$nome   = null;
			$lingua = null;
			foreach ( $campi as $chiave => $valore ) {
				$k = strtolower( $chiave );
				if ( null === $id && ( 'term_id' === $k || 'termid' === $k || 'term_ID' === $chiave ) ) {
					$id = (int) $valore;
				} elseif ( null === $nome && ( 'name' === $k || 'term_name' === $k ) ) {
					$nome = (string) $valore;
				} elseif ( null === $lingua && 'language' === $k ) {
					$lingua = (string) $valore;
				}
			}

			if ( ! $id || null === $nome || null === $lingua ) {
				continue;
			}
			$codice = Comune::lingua( $lingua );
			if ( $codice === $nostra_partenza || ! Languages::exists( $codice ) ) {
				continue;
			}
			$termine = get_term( $id );
			if ( ! $termine || is_wp_error( $termine ) ) {
				continue;
			}
			if ( Comune::coppia( (string) $termine->name, $codice, $nome, $salva ) ) {
				++$fatti;
			}
		}

		return $fatti;
	}
}
