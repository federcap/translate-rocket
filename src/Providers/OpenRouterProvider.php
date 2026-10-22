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

	// 22/10/2026 (prova con 12 frasi in it/de/ja): minimax flash risponde in 6-24 s e traduce bene; nemotron ultra è il
	// migliore ma lento (20-60 s); nemotron super dava 404 dal fornitore, llama 3.3 :free non c'è più.
	const FREE_MODELS = array( 'minimax/minimax-m3.1-flash:free', 'nvidia/nemotron-3-ultra-550b-a55b:free', 'google/gemma-4-31b-it:free' );

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
