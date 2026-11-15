<?php
/**
 * Paid AI providers that speak the OpenAI chat format (DeepSeek, Qwen).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * 15/11/2026 (a site owner: «regional restrictions for GPT and Claude… any plans to add Chinese AI APIs,
 * such as DeepSeek or Qwen?»): chat completions at {base}/chat/completions and models at {base}/models,
 * with the owner's own paid key. They translate like OpenAI (LlmProvider: a JSON list in, a JSON list
 * out, house style, glossary and protected names in the prompt); unlike the free engines they have no
 * daily counter and are never asked the free questions of the tone advisor.
 */
abstract class PaidCompatProvider extends LlmProvider {

	abstract protected function base(): string;

	abstract protected function default_model(): string;

	/**
	 * Extra body fields: the switch that turns the model's «thinking» off. A translation does not
	 * need it, and it would be paid for and waited for on every batch.
	 *
	 * @return array<string,mixed>
	 */
	protected function extra(): array {
		return array();
	}

	/**
	 * Which listed models to offer.
	 */
	protected function model_ok( string $id ): bool {
		return true;
	}

	protected function chat( string $prompt ): TranslationResult {
		$model  = $this->model() ?: $this->default_model();
		$extra  = $this->extra();
		$result = $this->post_chat( $model, $prompt, $extra );
		// A model that does not know the switch answers 400: once more without it.
		if ( $extra && isset( $result['error'] ) && preg_match( '/^HTTP 4(00|22)\b/', (string) $result['error'] ) ) {
			$result = $this->post_chat( $model, $prompt, array() );
		}
		if ( isset( $result['error'] ) ) {
			return TranslationResult::fail( $this->label() . ': ' . $result['error'] );
		}

		$data = json_decode( $result['body'] ?? '', true );
		$text = is_array( $data ) ? ( $data['choices'][0]['message']['content'] ?? '' ) : '';
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			if ( is_array( $data ) && 'content_filter' === ( $data['choices'][0]['finish_reason'] ?? '' ) ) {
				return TranslationResult::fail( $this->label() . ': blocked by the safety filter (content_filter).' );
			}
			return TranslationResult::fail( $this->unreadable( (string) ( $result['body'] ?? '' ) ) );
		}
		return TranslationResult::ok( array( $text ) );
	}

	/**
	 * One call to chat completions.
	 *
	 * @param array<string,mixed> $extra Extra body fields.
	 * @return array{body?:string,error?:string}
	 */
	private function post_chat( string $model, string $prompt, array $extra ): array {
		$req = array_merge(
			array(
				'model'       => $model,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'temperature' => 0,
			),
			$extra
		);
		return $this->post(
			$this->base() . '/chat/completions',
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
		$res = $this->get( $this->base() . '/models', array( 'Authorization' => 'Bearer ' . $this->api_key() ) );
		if ( isset( $res['error'] ) ) {
			return array();
		}
		$data = json_decode( $res['body'] ?? '', true );
		$out  = array();
		foreach ( (array) ( is_array( $data ) ? ( $data['data'] ?? array() ) : array() ) as $m ) {
			$id = is_array( $m ) ? (string) ( $m['id'] ?? '' ) : '';
			if ( '' !== $id && $this->model_ok( $id ) ) {
				$out[] = $id;
			}
		}
		sort( $out );
		return $out;
	}
}
