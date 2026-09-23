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
		// temperature 0 keeps the translation steady from one call to the next, but
		// the reasoning models (o1, o3, o4-mini, gpt-5…) refuse any value but the
		// default and answer 400 «Unsupported value: 'temperature'»: whoever picked
		// one of them from the list saw every page fail. Sent only to the models
		// known to take it; and if a model we do not know yet refuses it too, one
		// more try without it.
		$temperatura = (bool) preg_match( '/^(gpt-4|gpt-3\.5|chatgpt)/i', $model );
		$result      = $this->post_chat( $model, $prompt, $temperatura );
		if ( $temperatura && isset( $result['error'] ) && false !== stripos( $result['error'], 'temperature' ) ) {
			$result = $this->post_chat( $model, $prompt, false );
		}
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

	/**
	 * One call to Chat Completions.
	 *
	 * @return array{body?:string,error?:string}
	 */
	private function post_chat( string $model, string $prompt, bool $temperatura ): array {
		$req = array(
			'model'    => $model,
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);
		if ( $temperatura ) {
			$req['temperature'] = 0;
		}
		return $this->post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'Authorization' => 'Bearer ' . $this->api_key(),
				'Content-Type'  => 'application/json',
			),
			wp_json_encode( $req ),
			60
		);
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
