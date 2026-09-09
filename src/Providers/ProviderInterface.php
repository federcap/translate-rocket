<?php
/**
 * Translation provider contract.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Every AI / machine-translation backend implements this.
 */
interface ProviderInterface {

	/**
	 * Stable provider id (e.g. 'deepl').
	 */
	public function id(): string;

	/**
	 * Human label (e.g. 'DeepL').
	 */
	public function label(): string;

	/**
	 * Whether the provider has the credentials it needs.
	 */
	public function is_configured(): bool;

	/**
	 * Translate a batch of strings from one language to another.
	 *
	 * @param string[] $texts  Source strings.
	 * @param string   $source Source language code (e.g. 'en').
	 * @param string   $target Target language code (e.g. 'it').
	 */
	public function translate( array $texts, string $source, string $target ): TranslationResult;

	/**
	 * Preferred maximum number of strings per API call. Deterministic engines
	 * (DeepL, Google) handle large batches fine; LLMs are more reliable on
	 * smaller arrays, where the "same length and order" contract is easier to keep.
	 */
	public function batch_size(): int;

	/**
	 * Live list of model ids the configured key can use (empty if the provider
	 * is not model-based or the request fails). Powers the "Load available
	 * models" picker so defaults never silently go stale.
	 *
	 * @return string[]
	 */
	public function list_models(): array;
}
