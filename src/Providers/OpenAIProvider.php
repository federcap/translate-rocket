<?php
/**
 * OpenAI chat-completions provider.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI Chat Completions API.
 */
class OpenAIProvider extends LlmProvider {

	public function id(): string {
		return 'openai';
	}

	public function label(): string {
		return 'OpenAI';
	}

	protected function chat( string $prompt ): TranslationResult {
		$model = $this->model() ?: 'gpt-4o-mini';
		$body  = wp_json_encode(
			array(
				'model'       => $model,
				'temperature' => 0,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
			)
		);

		$result = $this->post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'Authorization' => 'Bearer ' . $this->api_key(),
				'Content-Type'  => 'application/json',
			),
			$body,
			60
		);
		if ( isset( $result['error'] ) ) {
			return TranslationResult::fail( 'OpenAI: ' . $result['error'] );
		}

		$data = json_decode( $result['body'] ?? '', true );
		$text = $data['choices'][0]['message']['content'] ?? '';
		if ( '' === $text ) {
			return TranslationResult::fail( 'OpenAI: empty response.' );
		}
		return TranslationResult::ok( array( (string) $text ) );
	}

	public function list_models(): array {
		if ( '' === $this->api_key() ) {
			return array();
		}
		$res = $this->get( 'https://api.openai.com/v1/models', array( 'Authorization' => 'Bearer ' . $this->api_key() ) );
		if ( isset( $res['error'] ) ) {
			return array();
		}
		$data = json_decode( $res['body'] ?? '', true );
		$out  = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			// Keep chat/reasoning models; skip embeddings, audio, image, moderation.
			if ( '' !== $id && preg_match( '/^(gpt|o\d|chatgpt)/i', $id ) && ! preg_match( '/(embedding|audio|tts|whisper|image|moderation|realtime|transcribe)/i', $id ) ) {
				$out[] = $id;
			}
		}
		sort( $out );
		return $out;
	}
}
