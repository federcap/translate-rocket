<?php
/**
 * Groq — free tier with the owner's own key.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * About 1,000 requests a day and 8,000 tokens a minute on gpt-oss-120b, which translates
 * very well. Its «reasoning» is kept short (fewer tokens a minute); when Groq asks to slow
 * down for a few seconds, the request waits and is tried once more.
 */
class GroqProvider extends OpenAiCompatProvider {

	public function id(): string {
		return 'groq';
	}

	public function label(): string {
		return 'Groq';
	}

	protected function base(): string {
		return 'https://api.groq.com/openai/v1';
	}

	protected function default_model(): string {
		return 'openai/gpt-oss-120b';
	}

	protected function extra( string $model ): array {
		return false !== strpos( $model, 'gpt-oss' ) ? array( 'reasoning_effort' => 'low' ) : array();
	}

	protected function model_ok( string $id ): bool {
		return ! preg_match( '/(whisper|tts|guard|playai|distil)/i', $id );
	}
}
