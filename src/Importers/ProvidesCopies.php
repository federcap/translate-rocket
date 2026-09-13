<?php
/**
 * Importers whose source plugin keeps one post per language.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Polylang, WPML and Bogo store each translation as a separate post. Once their
 * translations are imported those posts are no longer needed, and CopyCleanup
 * offers to tidy them up — this is how it learns which post belongs to which.
 */
interface ProvidesCopies extends ImporterInterface {

	/**
	 * Every original post with its per-language copies.
	 *
	 * Only languages TranslateRocket knows, never our default language, and never
	 * the original itself among its copies.
	 *
	 * @return array<int,array{source:int,copies:array<string,int>}>
	 */
	public function copy_groups(): array;
}
