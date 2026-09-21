<?php
/**
 * Names that must stay as they are, even inside a sentence.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

use TranslateRocket\NoTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * "Words & phrases" (Exclusions screen) used to protect a text only when the whole
 * text was the name: "LifeStyle-Shop" alone stayed, but "Willkommen im
 * LifeStyle-Shop" came back from DeepL as "Benvenuti nel negozio LifeStyle".
 * This marks those names inside the sentences sent to a provider, in the way each
 * provider understands:
 *   - DeepL: <keep>…</keep> with tag_handling=xml and ignore_tags=keep;
 *   - Google Cloud: <span translate="no">…</span> with format=html;
 *   - AI models: a line in the prompt listing the names to copy as they are.
 * When no protected name appears in a batch, nothing changes: the request is
 * exactly what it was before.
 */
final class KeepTerms {

	/**
	 * The protected names that appear in these texts, longest first.
	 *
	 * @param string[] $texts Texts about to be sent.
	 * @return string[]
	 */
	public static function in( array $texts ): array {
		$found = array();
		foreach ( NoTranslate::strings() as $term ) {
			$term = trim( (string) $term );
			if ( mb_strlen( $term ) < 2 ) {
				continue;
			}
			foreach ( $texts as $text ) {
				if ( false !== strpos( (string) $text, $term ) && trim( (string) $text ) !== $term ) {
					$found[ $term ] = true;
					break;
				}
			}
		}
		$terms = array_keys( $found );
		usort(
			$terms,
			static function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);
		return $terms;
	}

	/**
	 * Text escaped for an XML/HTML request, with every protected name wrapped.
	 *
	 * @param string   $text  Plain text.
	 * @param string[] $terms Names, longest first.
	 * @param string   $open  Opening tag.
	 * @param string   $close Closing tag.
	 */
	public static function wrap( string $text, array $terms, string $open, string $close ): string {
		return self::wrap_escaped( self::escape( $text ), $terms, $open, $close );
	}

	/**
	 * Escape a text for a request in XML or HTML mode. One place, one rule.
	 *
	 * @param string $text Text.
	 */
	public static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_NOQUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Wrap the protected names in a text that is ALREADY escaped.
	 *
	 * ⚠️ Esiste perche' una frase con dentro un link viaggia gia' escapata e con i suoi
	 * segnaposto trasformati in tag: passarla di nuovo da wrap() la escapava due volte e
	 * i servizi non vedevano piu' i tag. Una frase escapata due volte torna indietro per
	 * combinazione, ma nel mezzo DeepL e Google non possono piu' spostare i tag: li
	 * storpiano, e la traduzione viene rifiutata e ripagata a ogni giro.
	 *
	 * @param string   $escaped Text already escaped.
	 * @param string[] $terms   Protected names.
	 * @param string   $open    Opening tag.
	 * @param string   $close   Closing tag.
	 */
	public static function wrap_escaped( string $escaped, array $terms, string $open, string $close ): string {
		if ( empty( $terms ) ) {
			return $escaped;
		}
		$alts = array();
		foreach ( $terms as $t ) {
			$alts[] = preg_quote( htmlspecialchars( $t, ENT_NOQUOTES | ENT_XML1, 'UTF-8' ), '/' );
		}
		// One pass with every name at once (longest first), so names never nest.
		return (string) preg_replace_callback(
			'/' . implode( '|', $alts ) . '/u',
			static function ( $m ) use ( $open, $close ) {
				return $open . $m[0] . $close;
			},
			$escaped
		);
	}

	/**
	 * Back to plain text: the wrapping tags go, the escaping is undone.
	 *
	 * @param string $text     What the provider returned.
	 * @param string $tag_name Wrapping element name ('keep', 'span').
	 */
	public static function unwrap( string $text, string $tag_name ): string {
		return html_entity_decode( self::strip_tag( $text, $tag_name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Togliere SOLO il tag, senza decodificare: la decodifica la fa chi chiama, una volta
	 * sola, perche' una frase con segnaposto ha gia' il suo giro di decodifica.
	 *
	 * @param string $text     Text from the service.
	 * @param string $tag_name Tag to remove.
	 */
	public static function strip_tag( string $text, string $tag_name ): string {
		return (string) preg_replace(
			'#<' . preg_quote( $tag_name, '#' ) . '(\s[^>]*)?>|</' . preg_quote( $tag_name, '#' ) . '\s*>#i',
			'',
			$text
		);
	}

	/**
	 * The line for an AI prompt, or '' when there is nothing to protect.
	 *
	 * @param string[] $terms Names.
	 */
	public static function prompt_line( array $terms ): string {
		if ( empty( $terms ) ) {
			return '';
		}
		$list = array();
		foreach ( array_slice( $terms, 0, 40 ) as $t ) {
			$list[] = wp_json_encode( $t, JSON_UNESCAPED_UNICODE );
		}
		return ' These are names: copy them exactly as they are, never translate or change them: ' . implode( ', ', $list ) . '.';
	}
}
