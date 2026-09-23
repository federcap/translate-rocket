<?php
/**
 * Google Gemini provider.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Google Gemini (Generative Language API, API-key auth).
 */
class GeminiProvider extends LlmProvider {

	public function id(): string {
		return 'gemini';
	}

	public function label(): string {
		return 'Google Gemini';
	}

	protected function chat( string $prompt ): TranslationResult {
		$model = $this->model() ?: 'gemini-flash-lite-latest';
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/'
			. rawurlencode( $model ) . ':generateContent';

		$body = wp_json_encode(
			array(
				'contents'         => array(
					array( 'parts' => array( array( 'text' => $prompt ) ) ),
				),
				'generationConfig' => array( 'temperature' => 0 ),
			)
		);

		$result = $this->post(
			$url,
			// The key goes in a header, as Google's documentation now shows, not in
			// the address: an address ends up in server logs and error messages.
			array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $this->api_key(),
			),
			$body,
			60
		);
		if ( isset( $result['error'] ) ) {
			return TranslationResult::fail( 'Gemini: ' . $result['error'] );
		}

		$data = json_decode( $result['body'] ?? '', true );
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if ( '' === $text ) {
			return TranslationResult::fail( 'Gemini: empty response.' );
		}
		return TranslationResult::ok( array( (string) $text ) );
	}

	public function list_models(): array {
		if ( '' === $this->api_key() ) {
			return array();
		}
		$res = $this->get( 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000', array( 'x-goog-api-key' => $this->api_key() ) );
		if ( isset( $res['error'] ) ) {
			return array();
		}
		$data = json_decode( $res['body'] ?? '', true );
		$out  = array();
		foreach ( (array) ( $data['models'] ?? array() ) as $m ) {
			$methods = (array) ( $m['supportedGenerationMethods'] ?? array() );
			if ( ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}
			$name = preg_replace( '#^models/#', '', (string) ( $m['name'] ?? '' ) );
			// Text models only — skip image/tts variants that can't translate.
			if ( '' !== $name && false !== strpos( $name, 'gemini' ) && ! preg_match( '/(image|tts|vision|embedding)/i', $name ) ) {
				$out[] = $name;
			}
		}
		sort( $out );
		return $out;
	}
}
