<?php
/**
 * Google Cloud Translation (v2) provider.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

use TranslateRocket\Frontend\InlineText;

defined( 'ABSPATH' ) || exit;

/**
 * Google Cloud Translation API v2 (API-key auth).
 */
class GoogleProvider extends AbstractProvider {

	public function id(): string {
		return 'google';
	}

	public function label(): string {
		return 'Google Translate';
	}

	public function translate( array $texts, string $source, string $target ): TranslationResult {
		$texts = array_values( $texts );
		if ( empty( $texts ) ) {
			return TranslationResult::ok( array() );
		}
		$key = $this->api_key();
		if ( '' === $key ) {
			return TranslationResult::fail( 'Missing Google API key.' );
		}

		// Names from "Words & phrases" inside these sentences: Google leaves translate="no"
		// alone, but only in HTML mode. Without such names the request is unchanged.
		// Stessa regola sola di DeepL: se si va in HTML, ogni testo viene escapato una
		// volta e ogni risposta decodificata una volta. (Vedi il commento in DeepLProvider.)
		$keep  = KeepTerms::in( $texts );
		$parts = false;
		foreach ( $texts as $text ) {
			if ( InlineText::has_parts( $text ) ) {
				$parts = true;
				break;
			}
		}
		$html  = ! empty( $keep ) || $parts;
		$query = http_build_query(
			array(
				'source' => $this->google_code( $source ),
				'target' => $this->google_code( $target ),
				'format' => $html ? 'html' : 'text',
			)
		);
		foreach ( $texts as $text ) {
			$body = $text;
			if ( $html ) {
				$body = InlineText::has_parts( $text ) ? InlineText::to_tags( $text ) : KeepTerms::escape( $text );
				if ( ! empty( $keep ) ) {
					$body = KeepTerms::wrap_escaped( $body, $keep, '<span translate="no">', '</span>' );
				}
			}
			$query .= '&q=' . rawurlencode( $body );
		}

		$result = $this->post(
			'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode( $key ),
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			$query
		);
		if ( isset( $result['error'] ) ) {
			return TranslationResult::fail( 'Google: ' . $result['error'] );
		}

		$data = json_decode( $result['body'] ?? '', true );
		if ( ! is_array( $data ) || empty( $data['data']['translations'] ) ) {
			return TranslationResult::fail( 'Google: unexpected response.' );
		}

		$out = array();
		$i   = 0;
		foreach ( $data['data']['translations'] as $item ) {
			$t = (string) ( $item['translatedText'] ?? '' );
			if ( ! empty( $keep ) ) {
				$t = KeepTerms::strip_tag( $t, 'span' );
			}
			// v2 restituisce testo con le entita' anche in modalita' «text»: si decodifica
			// sempre, ma UNA volta sola (from_tags decodifica gia' per conto suo).
			$t     = ( isset( $texts[ $i ] ) && InlineText::has_parts( $texts[ $i ] ) )
				? InlineText::from_tags( $t )
				: html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$out[] = $t;
			++$i;
		}
		if ( count( $out ) !== count( $texts ) ) {
			return TranslationResult::fail( 'Google: item count mismatch.' );
		}
		return TranslationResult::ok( $out );
	}

	/**
	 * Map our code to a Google code.
	 */
	private function google_code( string $code ): string {
		$map = array(
			'pt-br' => 'pt',
			'zh'    => 'zh-CN',
			'zh-tw' => 'zh-TW',
		);
		$code = strtolower( $code );
		return $map[ $code ] ?? substr( $code, 0, 2 );
	}
}
