<?php
/**
 * Import translations from Bogo.
 *
 * Bogo keeps one post per language, like Polylang, but the link between them is
 * far simpler: each translated post carries two meta values — `_locale` with the
 * WordPress locale (`it_IT`), and `_original_post` with the id of the post it
 * was translated from. No taxonomy, no term relationships, nothing to unpick.
 *
 * That directness is why this importer is short: find every post that points at
 * an original, and pair the two.
 *
 * It reads the meta straight from the database, so it works with Bogo
 * deactivated — the usual state of a site that is moving away from it.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Languages;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Bogo's one-post-per-locale layout.
 */
class BogoImporter implements ImporterInterface {

	private const BLOCCO = 200;
	// Solo per il conteggio, che e' la parte cara. Cinque minuti: abbastanza
	// per non rifare il giro a ogni ricarica, poco per non mentire a lungo.
	private const MEMORIA = 5 * MINUTE_IN_SECONDS;

	public function id(): string {
		return 'bogo';
	}

	public function label(): string {
		return 'Bogo';
	}

	/**
	 * Every post that belongs to a translation group, with its group key.
	 *
	 * ⚠️ `_original_post` NON e' il numero del post originale: e' la CHIAVE
	 * DEL GRUPPO, e ce l'hanno tutte le versioni, originale compreso. Bogo ci
	 * mette il GUID del primo post (un indirizzo), e nelle versioni vecchie un
	 * numero. Trattarla come un puntatore al genitore — come sembra naturale
	 * dal nome — vuol dire non importare NIENTE da un sito Bogo vero: e'
	 * esattamente quello che succedeva finche' non l'ho installato per davvero.
	 *
	 * Il modo giusto: raggruppare per quella chiave, e dentro ogni gruppo
	 * prendere come originale il post nella nostra lingua di partenza.
	 *
	 * @return object[]
	 */
	private function membri( int $limite = 0, int $salta = 0 ): array {
		global $wpdb;

		$sql = "SELECT o.post_id AS post, o.meta_value AS gruppo, l.meta_value AS locale
			FROM {$wpdb->postmeta} o
			INNER JOIN {$wpdb->postmeta} l
			        ON l.post_id = o.post_id AND l.meta_key = '_locale'
			WHERE o.meta_key = '_original_post'
			  AND o.meta_value <> ''
			ORDER BY o.meta_value, o.post_id";

		if ( $limite > 0 ) {
			return (array) $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', $limite, $salta ) ); // phpcs:ignore WordPress.DB
		}
		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB
	}

	public function is_available(): bool {
		// Una query su postmeta con indice: si risponde al volo. Ricordarla
		// vorrebbe dire dire "non trovato" a chi ha appena finito di tradurre.
		return ! empty( $this->membri( 1 ) );
	}

	public function count_available(): int {
		$chiave = 'trrocket_imp_bogo_n';
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
		delete_transient( 'trrocket_imp_bogo_c' );
		delete_transient( 'trrocket_imp_bogo_n' );
		return array(
			'count' => $n,
			'error' => '',
		);
	}

	private function cammina( bool $salva ): int {
		$nostra_partenza = (string) ( Settings::get()['source_language'] ?? 'en' );

		// Si legge tutto e si raggruppa: i gruppi sono piccoli (una manciata di
		// lingue per contenuto) e vanno tenuti interi, perche' l'originale puo'
		// trovarsi in qualunque posizione.
		$gruppi = array();
		foreach ( $this->membri() as $r ) {
			$gruppi[ (string) $r->gruppo ][] = $r;
		}

		$totale = 0;
		foreach ( $gruppi as $membri ) {
			if ( count( $membri ) < 2 ) {
				continue;   // un gruppo con un solo post non ha traduzioni
			}

			// L'originale e' il membro nella NOSTRA lingua di partenza.
			$origine = null;
			foreach ( $membri as $m ) {
				if ( Comune::lingua( (string) $m->locale ) === $nostra_partenza ) {
					$origine = get_post( (int) $m->post );
					break;
				}
			}
			if ( ! $origine ) {
				continue;   // senza l'originale non c'e' niente a cui appaiare
			}

			foreach ( $membri as $m ) {
				$lingua = Comune::lingua( (string) $m->locale );
				if ( $lingua === $nostra_partenza || ! Languages::exists( $lingua ) ) {
					continue;
				}
				$tradotto = get_post( (int) $m->post );
				if ( ! $tradotto || (int) $tradotto->ID === (int) $origine->ID ) {
					continue;
				}
				$totale += Comune::appaia( $origine, $tradotto, $lingua, $salva );
			}
		}

		return $totale;
	}
}
