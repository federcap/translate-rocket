<?php
/**
 * OpenRouter — the free models, with the owner's own key.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Models whose name ends in «:free» cost nothing (about 50 requests a day without credit)
 * and are often busy: the chosen one is tried first, then a few others.
 */
class OpenRouterProvider extends OpenAiCompatProvider {

	const FREE_MODELS = array( 'nvidia/nemotron-3-super-120b-a12b:free', 'google/gemma-4-31b-it:free', 'meta-llama/llama-3.3-70b-instruct:free' );

	public function id(): string {
		return 'openrouter';
	}

	public function label(): string {
		return 'OpenRouter';
	}

	protected function base(): string {
		return 'https://openrouter.ai/api/v1';
	}

	protected function default_model(): string {
		return self::FREE_MODELS[0];
	}

	protected function headers(): array {
		return array( 'Authorization' => 'Bearer ' . $this->api_key(), 'HTTP-Referer' => 'https://translaterocket.com', 'X-Title' => 'TranslateRocket' );
	}

	protected function models_to_try(): array {
		$scelto = $this->model();
		return array_values( array_unique( array_merge( '' !== $scelto ? array( $scelto ) : array(), self::FREE_MODELS ) ) );
	}

	protected function model_ok( string $id ): bool {
		return ':free' === substr( $id, -5 );
	}
}
