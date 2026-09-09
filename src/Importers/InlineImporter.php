<?php
/**
 * Import translations from the "all languages inside the text" family.
 *
 * qTranslate-X, qTranslate-XT, WPGlobus and WP Multilang keep every language
 * inside the same `post_title` / `post_content` / `post_excerpt`, separated by
 * markers. One importer serves all four: the markers differ, the idea does not.
 *
 * This family is the easiest of all to import from, and the most worth
 * importing. Easiest because both languages sit in the same field, so pairing
 * is exact instead of best-effort — unlike Polylang, where the two versions are
 * separate posts and the body has to be matched line by line and hoped for.
 * Most worth it because qTranslate was removed from the plugin directory years
 * ago: those sites are stranded, and nobody has offered them a way out.
 *
 * What is imported: titles, excerpts and the body, split into the same lines
 * the rest of the plugin works with. The source language is whatever
 * TranslateRocket is configured with; a post that has no section in that
 * language is skipped, because without the original there is nothing to pair a
 * translation to.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Strings;
use TranslateRocket\Languages;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads posts whose fields carry every language at once.
 */
class InlineImporter implements ImporterInterface {

	/**
	 * Posts to look at in one query. Whole `post_content` values are heavy, so
	 * they are read in slices rather than all at once.
	 */
	private const BLOCCO = 200;

	/**
	 * How long the "is there anything here?" answer is remembered.
	 *
	 * The Import screen asks every importer, on every page load. The others
	 * query their own small tables; this one has to look through the posts,
	 * with leading-wildcard LIKEs — which on a site with no such content means
	 * a full scan every time. The answer changes only when someone migrates a
	 * site, so an hour of memory costs nothing and spares the scan.
	 */
	private const MEMORIA = HOUR_IN_SECONDS;

	/**
	 * Ceiling for the preview count only.
	 *
	 * Counting means reading and splitting every post that carries markers.
	 * On a large archive that is real work for a number nobody acts on, so the
	 * preview stops here. The import itself is never capped: it does the lot.
	 */
	private const TETTO_ANTEPRIMA = 3000;

	public function id(): string {
		return 'inline';
	}

	public function label(): string {
		return 'qTranslate, WPGlobus or WP Multilang';
	}

	/**
	 * Post types worth looking at. Revisions are excluded on purpose: they
	 * carry the same text again and would only slow the run down.
	 *
	 * @return string[]
	 */
	private function tipi(): array {
		$tipi = get_post_types( array( 'public' => true ), 'names' );
		unset( $tipi['attachment'] );
		return array_values( $tipi );
	}

	/**
	 * Posts whose title or body carries language markers.
	 *
	 * The `LIKE` narrows the field cheaply; the real decision is taken by the
	 * reader, which knows a marker from a shortcode.
	 *
	 * @return object[]
	 */
	private function candidati( int $limite = 0, int $salta = 0 ): array {
		global $wpdb;

		$tipi = $this->tipi();
		if ( empty( $tipi ) ) {
			return array();
		}
		$segna = implode( ',', array_fill( 0, count( $tipi ), '%s' ) );

		// I quattro stili, ridotti al pezzo che si puo' cercare con LIKE.
		$spie = array( '{:', '[:', '<!--:', '&lt;!--:' );
		$dove = array();
		$arg  = $tipi;
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $campo ) {
			foreach ( $spie as $spia ) {
				$dove[] = "{$campo} LIKE %s";
				$arg[]  = '%' . $wpdb->esc_like( $spia ) . '%';
			}
		}

		$sql = "SELECT ID, post_title, post_content, post_excerpt
			FROM {$wpdb->posts}
			WHERE post_type IN ({$segna})
			  AND post_status NOT IN ('auto-draft','trash','inherit')
			  AND ( " . implode( ' OR ', $dove ) . ' )
			ORDER BY ID';

		if ( $limite > 0 ) {
			$sql  .= ' LIMIT %d OFFSET %d';
			$arg[] = $limite;
			$arg[] = $salta;
		}

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $arg ) ); // phpcs:ignore WordPress.DB
	}

	public function is_available(): bool {
		$chiave = 'trrocket_imp_inline_c';
		$noto   = get_transient( $chiave );
		if ( false !== $noto ) {
			return '1' === $noto;
		}
		$ce = ! empty( $this->candidati( 1 ) );
		set_transient( $chiave, $ce ? '1' : '0', self::MEMORIA );
		return $ce;
	}

	/**
	 * How many pairs could come in.
	 *
	 * The same walk the import does, without writing anything — so on any
	 * ordinary site the number is exact. On a very large archive the walk stops
	 * at TETTO_ANTEPRIMA posts, and then the number is a floor: the import will
	 * bring in that many or more, never fewer.
	 */
	public function count_available(): int {
		$chiave = 'trrocket_imp_inline_n';
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
		// Dopo un'importazione i due numeri ricordati non valgono piu'.
		delete_transient( 'trrocket_imp_inline_c' );
		delete_transient( 'trrocket_imp_inline_n' );
		return array(
			'count' => $n,
			'error' => '',
		);
	}

	/**
	 * The one walk, used both to count and to import.
	 *
	 * @param bool $salva Whether to actually store what is found.
	 */
	private function cammina( bool $salva ): int {
		$sorgente = (string) ( Settings::get()['source_language'] ?? 'en' );
		$totale   = 0;
		$salta    = 0;
		$visti    = 0;

		do {
			$posts = $this->candidati( self::BLOCCO, $salta );
			$salta += self::BLOCCO;

			foreach ( $posts as $post ) {
				$totale += $this->un_post( $post, $sorgente, $salva );
				++$visti;
			}

			// Solo l'anteprima si ferma: l'importazione vera va fino in fondo.
			if ( ! $salva && $visti >= self::TETTO_ANTEPRIMA ) {
				break;
			}
		} while ( count( $posts ) === self::BLOCCO );

		return $totale;
	}

	/**
	 * One post: title, excerpt and body.
	 */
	private function un_post( object $post, string $sorgente, bool $salva ): int {
		$fatti = 0;

		foreach ( array( 'post_title', 'post_excerpt' ) as $campo ) {
			$fatti += $this->un_campo( (string) $post->$campo, $sorgente, $salva, false );
		}
		$fatti += $this->un_campo( (string) $post->post_content, $sorgente, $salva, true );

		return $fatti;
	}

	/**
	 * One field.
	 *
	 * @param bool $a_righe Whether to split into lines (the body) or keep it
	 *                      whole (a title).
	 */
	private function un_campo( string $valore, string $sorgente, bool $salva, bool $a_righe ): int {
		if ( '' === trim( $valore ) ) {
			return 0;
		}

		$lingue = InlineMarkers::dividi( $valore );
		// Senza la lingua di partenza non c'e' niente a cui appaiare una
		// traduzione: si lascia stare, invece di indovinare.
		if ( ! isset( $lingue[ $sorgente ] ) ) {
			return 0;
		}

		$origine = $lingue[ $sorgente ];
		$fatti   = 0;

		foreach ( $lingue as $codice => $testo ) {
			if ( $codice === $sorgente || ! Languages::exists( $codice ) ) {
				continue;
			}

			if ( ! $a_righe ) {
				if ( $this->coppia( $origine, $codice, $testo, $salva ) ) {
					++$fatti;
				}
				continue;
			}

			// Il corpo si spezza in righe come fa il resto del plugin. Le due
			// versioni si appaiano solo se hanno lo STESSO numero di righe: se
			// no si accoppierebbe un paragrafo con quello sbagliato, che e'
			// peggio del non importare niente.
			$a = $this->righe( $origine );
			$b = $this->righe( $testo );
			if ( empty( $a ) || count( $a ) !== count( $b ) ) {
				continue;
			}
			foreach ( $a as $i => $riga ) {
				if ( $this->coppia( $riga, $codice, $b[ $i ], $salva ) ) {
					++$fatti;
				}
			}
		}

		return $fatti;
	}

	/**
	 * Store one pair, or just say it would count.
	 */
	private function coppia( string $origine, string $lingua, string $testo, bool $salva ): bool {
		$origine = trim( $origine );
		$testo   = trim( $testo );
		if ( '' === $origine || '' === $testo || $origine === $testo ) {
			return false;
		}
		if ( ! $salva ) {
			return true;
		}
		return Strings::store_imported( $origine, $lingua, $testo );
	}

	/**
	 * Body text, one line per translatable block.
	 *
	 * Same shape as the Polylang importer uses, so a site coming from either
	 * plugin ends up with strings of the same granularity.
	 *
	 * @return string[]
	 */
	private function righe( string $html ): array {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$html = (string) preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#</(p|h[1-6]|li|div|blockquote|figcaption|td|th)>#i', "\n", $html );
		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$testo = wp_strip_all_tags( $html );

		$fuori = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $testo ) as $riga ) {
			$riga = trim( $riga );
			if ( '' !== $riga && preg_match( '/\p{L}/u', $riga ) ) {
				$fuori[] = $riga;
			}
		}
		return $fuori;
	}
}
