<?php
/**
 * Reader for the "all languages inside the text" family.
 *
 * qTranslate-X, qTranslate-XT, WPGlobus and WP Multilang all keep every
 * language inside the same `post_content` / `post_title`, separated by
 * markers. They differ only in which markers they use, so one reader serves
 * the four of them.
 *
 * The four shapes, taken from WPGlobus's own reader (it accepts all of them,
 * for compatibility with qTranslate):
 *
 *   {:en}English{:}{:it}Italiano{:}                    WPGlobus
 *   <!--:en-->English<!--:--><!--:it-->Italiano<!--:-->  qTranslate, old style
 *   &lt;!--:en--&gt;…&lt;!--:--&gt;                  the same, HTML-escaped
 *   [:en]English[:it]Italiano[:]                       qTranslate, square style
 *
 * The square style has **no closing marker**: a section ends where the next
 * one begins, or at the end of the string. `[:]` is simply an opener with an
 * empty code, which is why it works as a terminator.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Splits a multilingual string into one string per language.
 */
class InlineMarkers {

	/**
	 * The four shapes, in the order we try them.
	 *
	 * `apre` matches an opening marker and captures the language code.
	 * `chiude` matches the closing marker, where the style has one.
	 *
	 * The language code is `[a-z]{2,3}` optionally followed by a region, which
	 * covers `en`, `pt_br`, `zh-CN` and the like. Anything else is not a
	 * marker: that is what keeps `[gallery]` and `{ :not-a-marker }` out.
	 */
	private const STILI = array(
		array(
			'nome'   => 'wpglobus',
			'apre'   => '/\{:([a-z]{2,3}(?:[_-][a-z0-9]{2,4})?)\}/i',
			'chiude' => '/\{:\}/',
		),
		array(
			'nome'   => 'qtranslate-commenti',
			'apre'   => '/<!--:([a-z]{2,3}(?:[_-][a-z0-9]{2,4})?)-->/i',
			'chiude' => '/<!--:-->/',
		),
		array(
			'nome'   => 'qtranslate-commenti-escapati',
			'apre'   => '/&lt;!--:([a-z]{2,3}(?:[_-][a-z0-9]{2,4})?)--&gt;/i',
			'chiude' => '/&lt;!--:--&gt;/',
		),
		array(
			'nome'   => 'qtranslate-quadre',
			'apre'   => '/\[:([a-z]{2,3}(?:[_-][a-z0-9]{2,4})?)\]/i',
			// `[:]` non e' un'apertura (non ha codice) ma chiude: senza questa
			// riga l'ultima lingua si portava dietro il terminatore, e usciva
			// "Ciao[:]" invece di "Ciao".
			'chiude' => '/\[:\]/',
		),
	);

	/**
	 * Which of the four shapes this text uses, or '' if it uses none.
	 */
	public static function stile( string $testo ): string {
		foreach ( self::STILI as $stile ) {
			if ( preg_match( $stile['apre'], $testo ) ) {
				return $stile['nome'];
			}
		}
		return '';
	}

	/**
	 * Whether the text carries language markers at all.
	 */
	public static function ha_lingue( string $testo ): bool {
		return '' !== self::stile( $testo );
	}

	/**
	 * Split a multilingual string.
	 *
	 * A string with no markers returns an empty array: it is monolingual, and
	 * inventing a language for it would be worse than importing nothing.
	 *
	 * @return array<string,string> language code (lowercase, `-` as separator) => text
	 */
	public static function dividi( string $testo ): array {
		if ( '' === trim( $testo ) ) {
			return array();
		}

		foreach ( self::STILI as $stile ) {
			if ( ! preg_match_all( $stile['apre'], $testo, $aperture, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			// Le posizioni di ogni marcatore di chiusura, per sapere dove
			// finisce una sezione quando lo stile ne prevede uno.
			$chiusure = array();
			if ( '' !== $stile['chiude'] && preg_match_all( $stile['chiude'], $testo, $c, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $c[0] as $trovato ) {
					$chiusure[] = (int) $trovato[1];
				}
			}

			$fuori   = array();
			$quante  = count( $aperture[0] );
			$lunghezza = strlen( $testo );

			for ( $i = 0; $i < $quante; $i++ ) {
				$inizio = (int) $aperture[0][ $i ][1] + strlen( $aperture[0][ $i ][0] );
				$codice = strtolower( str_replace( '_', '-', (string) $aperture[1][ $i ][0] ) );

				// La sezione finisce al PRIMO fra: la prossima apertura, la
				// prossima chiusura, la fine della stringa. Prendere solo la
				// chiusura sarebbe fragile: capita di trovare testi a cui
				// manca una chiusura in mezzo, e li' si mangerebbe la lingua
				// successiva.
				$fine = $lunghezza;
				if ( $i + 1 < $quante ) {
					$fine = min( $fine, (int) $aperture[0][ $i + 1 ][1] );
				}
				foreach ( $chiusure as $pos ) {
					if ( $pos >= $inizio ) {
						$fine = min( $fine, $pos );
						break;
					}
				}

				$pezzo = trim( substr( $testo, $inizio, $fine - $inizio ) );
				// Una lingua dichiarata ma vuota non e' una traduzione: si
				// salta, invece di salvare una stringa vuota che poi in pagina
				// cancellerebbe il testo originale.
				if ( '' === $pezzo ) {
					continue;
				}
				// Se la stessa lingua compare due volte si tiene la prima:
				// e' quella che i plugin di origine mostrano.
				if ( ! isset( $fuori[ $codice ] ) ) {
					$fuori[ $codice ] = $pezzo;
				}
			}

			return $fuori;
		}

		return array();
	}
}
