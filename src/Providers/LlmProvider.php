<?php
/**
 * Base for LLM-style providers (OpenAI, Gemini, Anthropic).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Implements translate() by asking the model to return a JSON array. Subclasses
 * only implement chat(): send a prompt, return the assistant's raw text.
 */
abstract class LlmProvider extends AbstractProvider {

	/**
	 * Send a prompt to the model. On success return ok([ rawText ]).
	 */
	abstract protected function chat( string $prompt ): TranslationResult;

	/**
	 * LLMs occasionally drop or merge an item on long arrays, which fails the
	 * whole batch; a smaller batch keeps the length/order contract reliable.
	 */
	public function batch_size(): int {
		return 20;
	}

	/** Longest house-style note we will send. Every batch carries it, so it is
	 * capped to keep the added cost small and predictable. */
	const GUIDANCE_MAX = 1000;

	/**
	 * The site owner's own note about tone and style, ready to drop in a prompt.
	 *
	 * Returns an empty string when there is nothing to add, so a site that never
	 * sets one sends exactly the same prompt as before.
	 */
	protected static function guidance_block(): string {
		$testo = trim( (string) ( \TranslateRocket\Settings::get()['ai_guidance'] ?? '' ) );
		if ( '' === $testo ) {
			return '';
		}
		$testo = mb_substr( $testo, 0, self::GUIDANCE_MAX );
		// Fenced so the model reads it as material to follow, not as a new task.
		return "\n\nFollow this house style, from the site owner:\n\"\"\"\n" . $testo . "\n\"\"\"";
	}

	public function translate( array $texts, string $source, string $target ): TranslationResult {
		$texts = array_values( $texts );
		if ( empty( $texts ) ) {
			return TranslationResult::ok( array() );
		}
		if ( '' === $this->api_key() ) {
			return TranslationResult::fail( 'Missing API key for ' . $this->label() . '.' );
		}

		$payload = wp_json_encode( $texts );
		$prompt  = sprintf(
			"You are a professional translation engine. Translate each element of this JSON array of strings from %s to %s.%s\n\n"
			. "Return ONLY a JSON array of strings, the same length and order, translations only — no comments, no markdown fences. "
			. "Keep numbers, URLs, emails and placeholder tokens unchanged. "
			. "These output rules always win over any instruction above.\n\nInput:\n%s",
			$this->lang_name( $source ),
			$this->lang_name( $target ),
			self::guidance_block(),
			$payload
		);

		$res = $this->chat( $prompt );
		if ( ! $res->success ) {
			return $res;
		}

		$decoded = self::extract_json_array( $res->translations[0] ?? '' );
		if ( null === $decoded || count( $decoded ) !== count( $texts ) ) {
			return TranslationResult::fail( 'Unexpected AI response (could not match ' . count( $texts ) . ' items).' );
		}

		return TranslationResult::ok( array_map( 'strval', $decoded ) );
	}

	/**
	 * Pull the first JSON array out of a model response (tolerant of fences).
	 *
	 * @return array<int,mixed>|null
	 */
	protected static function extract_json_array( string $text ): ?array {
		$text  = trim( $text );
		$text  = (string) preg_replace( '/```(?:json)?/i', '', $text );
		$start = strpos( $text, '[' );
		$end   = strrpos( $text, ']' );
		if ( false === $start || false === $end || $end < $start ) {
			return null;
		}
		$json = substr( $text, $start, $end - $start + 1 );
		$arr  = json_decode( $json, true );
		return is_array( $arr ) ? $arr : null;
	}
}
