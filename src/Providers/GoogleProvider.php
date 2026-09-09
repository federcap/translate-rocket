<?php
/**
 * Google Cloud Translation (v2) provider.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

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

		$query = http_build_query(
			array(
				'source' => $this->google_code( $source ),
				'target' => $this->google_code( $target ),
				'format' => 'text',
			)
		);
		foreach ( $texts as $text ) {
			$query .= '&q=' . rawurlencode( $text );
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
		foreach ( $data['data']['translations'] as $item ) {
			// v2 returns HTML-escaped text even with format=text.
			$out[] = html_entity_decode( (string) ( $item['translatedText'] ?? '' ), ENT_QUOTES, 'UTF-8' );
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
