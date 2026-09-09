<?php
/**
 * The bits every importer needs.
 *
 * Two things kept coming out identical in each new importer: turning another
 * plugin's language code into ours, and cutting a body of HTML into the same
 * lines the rest of the plugin works with. Copied a sixth time they would drift
 * apart, so they live here.
 *
 * Note: PolylangImporter and WpmlImporter carry their own copies of the mapping,
 * written before this class existed. They are shipped and working, and changing
 * them buys nothing today — but if that mapping ever needs a new entry, add it
 * here **and** there until they are unified.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for the importers.
 */
class Comune {

	/**
	 * Another plugin's language code (or a WordPress locale) into ours.
	 *
	 * Handles `it_IT`, `pt-BR`, `zh_CN` and friends. Everything unknown falls
	 * back to the first two letters, which is right far more often than not.
	 */
	public static function lingua( string $codice ): string {
		$codice = strtolower( str_replace( '-', '_', trim( $codice ) ) );

		$mappa = array(
			'pt_br'   => 'pt-br',
			'pt_pt'   => 'pt',
			'zh_cn'   => 'zh',
			'zh_hans' => 'zh',
			'zh_tw'   => 'zh-tw',
			'zh_hant' => 'zh-tw',
			'sr_rs'   => 'sr',
			'nb_no'   => 'nb',
			'nn_no'   => 'nn',
		);
		if ( isset( $mappa[ $codice ] ) ) {
			return $mappa[ $codice ];
		}

		return strlen( $codice ) > 2 ? substr( $codice, 0, 2 ) : $codice;
	}

	/**
	 * A body of HTML, one line per translatable block.
	 *
	 * The same shape every importer uses, so a site arriving from any of them
	 * ends up with strings of the same granularity — and so the same sentence
	 * imported twice from two places lands on the same row.
	 *
	 * @return string[]
	 */
	public static function righe( string $html ): array {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$html = (string) preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#</(p|h[1-6]|li|div|blockquote|figcaption|td|th)>#i', "\n", $html );
		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$testo = wp_strip_all_tags( $html );

		$fuori = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $testo ) as $riga ) {
			$riga = trim( $riga );
			// Una riga senza nemmeno una lettera (numeri, simboli, separatori)
			// non e' una frase da tradurre.
			if ( '' !== $riga && preg_match( '/\p{L}/u', $riga ) ) {
				$fuori[] = $riga;
			}
		}
		return $fuori;
	}

	/**
	 * Pair two versions of the same post: title, excerpt and body.
	 *
	 * The body is only paired when both sides cut into the **same number of
	 * lines**. Anything else would pair a paragraph with the wrong one, which
	 * is worse than importing nothing: a wrong translation is invisible until
	 * a reader trips over it.
	 *
	 * @param object|\WP_Post $origine   The post in the source language.
	 * @param object|\WP_Post $tradotto  The same post in another language.
	 * @param string          $lingua    Our language code.
	 * @param bool            $salva     Whether to store, or only count.
	 */
	public static function appaia( $origine, $tradotto, string $lingua, bool $salva ): int {
		$fatti = 0;

		$coppie = array(
			array( (string) $origine->post_title, (string) $tradotto->post_title ),
			array( (string) $origine->post_excerpt, (string) $tradotto->post_excerpt ),
		);
		foreach ( $coppie as $c ) {
			if ( self::coppia( $c[0], $lingua, $c[1], $salva ) ) {
				++$fatti;
			}
		}

		$a = self::righe( (string) $origine->post_content );
		$b = self::righe( (string) $tradotto->post_content );
		if ( ! empty( $a ) && count( $a ) === count( $b ) ) {
			foreach ( $a as $i => $riga ) {
				if ( self::coppia( $riga, $lingua, $b[ $i ], $salva ) ) {
					++$fatti;
				}
			}
		}

		return $fatti;
	}

	/**
	 * Store one pair, or just say whether it would count.
	 */
	public static function coppia( string $origine, string $lingua, string $testo, bool $salva ): bool {
		$origine = trim( $origine );
		$testo   = trim( $testo );
		// Identiche non e' una traduzione: e' una copia, e riempirebbe la
		// memoria di righe che non servono a niente.
		if ( '' === $origine || '' === $testo || $origine === $testo ) {
			return false;
		}
		if ( ! $salva ) {
			return true;
		}
		return \TranslateRocket\Strings::store_imported( $origine, $lingua, $testo );
	}
}
