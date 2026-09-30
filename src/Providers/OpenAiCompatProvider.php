<?php
/**
 * Free AI providers that speak the OpenAI chat format (Groq, OpenRouter).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Chat completions at {base}/chat/completions, models at {base}/models.
 */
abstract class OpenAiCompatProvider extends FreeAiProvider {

	abstract protected function base(): string;

	abstract protected function default_model(): string;

	/**
	 * Extra body fields for a model (e.g. short reasoning for a reasoning model).
	 *
	 * @return array<string,mixed>
	 */
	protected function extra( string $model ): array {
		return array();
	}

	/**
	 * @return array<string,string>
	 */
	protected function headers(): array {
		return array( 'Authorization' => 'Bearer ' . $this->api_key() );
	}

	/**
	 * The models to try, the chosen one first.
	 *
	 * @return string[]
	 */
	protected function models_to_try(): array {
		return array( $this->model() ?: $this->default_model() );
	}

	private function ask( array $messages, int $max_tokens ): array {
		$ultimo = array( 'error' => $this->label() . ': no model answered.' );
		foreach ( $this->models_to_try() as $model ) {
			$r = $this->post_patient(
				$this->base() . '/chat/completions',
				array_merge( array( 'model' => $model, 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => $max_tokens ), $this->extra( $model ) ),
				$this->headers()
			);
			if ( isset( $r['error'] ) ) {
				$ultimo = array( 'error' => $this->label() . ': ' . $r['error'] );
				if ( in_array( $r['code'], array( 401, 403 ), true ) ) {
					break; // the key: another model would not help
				}
				continue;
			}
			FreeUsage::add( $this->id(), (float) ( $r['data']['usage']['total_tokens'] ?? 0 ), 0 );
			return array( 'text' => (string) ( $r['data']['choices'][0]['message']['content'] ?? '' ) );
		}
		return $ultimo;
	}

	protected function chat( string $prompt ): TranslationResult {
		$r = $this->ask( array( array( 'role' => 'user', 'content' => $prompt ) ), 4000 );
		if ( isset( $r['error'] ) ) {
			return TranslationResult::fail( $r['error'] );
		}
		if ( '' === trim( $r['text'] ) ) {
			return TranslationResult::fail( $this->label() . ': empty response.' );
		}
		return TranslationResult::ok( array( $r['text'] ) );
	}

	public function translate( array $texts, string $source, string $target ): TranslationResult {
		$res = parent::translate( $texts, $source, $target );
		if ( $res->success ) {
			FreeUsage::sentences( $this->id(), count( $texts ) );
		}
		return $res;
	}

	public function complete( string $system, string $user, int $max_tokens = 900 ): array {
		return $this->ask( array( array( 'role' => 'system', 'content' => $system ), array( 'role' => 'user', 'content' => $user ) ), $max_tokens );
	}

	public function list_models(): array {
		if ( '' === $this->api_key() ) {
			return array();
		}
		$res = $this->get( $this->base() . '/models', $this->headers() );
		if ( isset( $res['error'] ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) ( json_decode( $res['body'] ?? '', true )['data'] ?? array() ) as $m ) {
			$id = (string) ( $m['id'] ?? '' );
			if ( '' !== $id && $this->model_ok( $id ) ) {
				$out[] = $id;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Which listed models to offer.
	 */
	protected function model_ok( string $id ): bool {
		return true;
	}
}
