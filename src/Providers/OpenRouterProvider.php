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
	// 5/11/2026: minimax-m3.1-flash:free is gone from OpenRouter (every step first met a 404). Gemma 4 first; the live
	// catalogue (live_free()) now drops any model OpenRouter removes, so the next removal does not cost a request.
	const FREE_MODELS = array( 'google/gemma-4-31b-it:free', 'nvidia/nemotron-3-ultra-550b-a55b:free', 'google/gemma-4-26b-a4b-it:free' );

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
		$tutti  = array_values( array_unique( array_merge( '' !== $scelto ? array( $scelto ) : array(), self::FREE_MODELS ) ) );
		$vivi   = self::live_free();
		if ( empty( $vivi ) ) {
			return $tutti; // the catalogue did not answer: try them all, as before
		}
		$restano = array_values( array_filter( $tutti, static fn( $m ) => ':free' !== substr( $m, -5 ) || in_array( $m, $vivi, true ) ) );
		return $restano ? $restano : $tutti;
	}

	/**
	 * The free models OpenRouter lists today (public catalogue, no key), kept for a day; [] when it cannot be read.
	 *
	 * @return string[]
	 */
	public static function live_free(): array {
		$c = get_transient( 'trrocket_or_free' );
		if ( is_array( $c ) ) {
			return $c;
		}
		$c = array();
		$r = wp_remote_get( 'https://openrouter.ai/api/v1/models', array( 'timeout' => 8, 'user-agent' => 'TranslateRocket (+https://translaterocket.com)' ) );
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			$d = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			foreach ( (array) ( $d['data'] ?? array() ) as $m ) {
				$id = is_array( $m ) ? (string) ( $m['id'] ?? '' ) : '';
				if ( ':free' === substr( $id, -5 ) ) {
					$c[] = $id;
				}
			}
		}
		set_transient( 'trrocket_or_free', $c, $c ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		return $c;
	}

	protected function model_ok( string $id ): bool {
		return ':free' === substr( $id, -5 );
	}
}
