<?php
/**
 * Anthropic (Claude) provider.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Anthropic Messages API.
 */
class AnthropicProvider extends LlmProvider {

	public function id(): string {
		return 'anthropic';
	}

	public function label(): string {
		return 'Anthropic (Claude)';
	}

	protected function chat( string $prompt ): TranslationResult {
		$model = $this->model() ?: 'claude-haiku-4-5';
		$body  = wp_json_encode(
			array(
				'model'      => $model,
				'max_tokens' => 4096,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
			)
		);

		$result = $this->post(
			'https://api.anthropic.com/v1/messages',
			array(
				'x-api-key'         => $this->api_key(),
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			),
			$body,
			60
		);
		if ( isset( $result['error'] ) ) {
			return TranslationResult::fail( 'Anthropic: ' . $result['error'] );
		}

		$data = json_decode( $result['body'] ?? '', true );
		$text = $data['content'][0]['text'] ?? '';
		if ( '' === $text ) {
			return TranslationResult::fail( 'Anthropic: empty response.' );
		}
		return TranslationResult::ok( array( (string) $text ) );
	}

	public function list_models(): array {
		if ( '' === $this->api_key() ) {
			return array();
		}
		$res = $this->get(
			'https://api.anthropic.com/v1/models?limit=100',
			array(
				'x-api-key'         => $this->api_key(),
				'anthropic-version' => '2023-06-01',
			)
		);
		if ( isset( $res['error'] ) ) {
			return array();
		}
		$data = json_decode( $res['body'] ?? '', true );
		$out  = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			if ( '' !== $id ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}
