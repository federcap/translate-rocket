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
		$escaped = htmlspecialchars( $text, ENT_NOQUOTES | ENT_XML1, 'UTF-8' );
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
		$text = (string) preg_replace( '#<' . preg_quote( $tag_name, '#' ) . '(\s[^>]*)?>|</' . preg_quote( $tag_name, '#' ) . '\s*>#i', '', $text );
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
