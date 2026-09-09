<?php
/**
 * Result of a batch translation request.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable-ish value object: either a list of translations (same order as the
 * input texts) or an error message. Keeps exceptions out of the UI layer.
 */
class TranslationResult {

	/**
	 * Whether the request succeeded.
	 *
	 * @var bool
	 */
	public $success;

	/**
	 * Translations, indexed the same as the input texts.
	 *
	 * @var string[]
	 */
	public $translations;

	/**
	 * Error message (empty on success).
	 *
	 * @var string
	 */
	public $error;

	/**
	 * @param string[] $translations Translations.
	 */
	private function __construct( bool $success, array $translations, string $error ) {
		$this->success      = $success;
		$this->translations = $translations;
		$this->error        = $error;
	}

	/**
	 * @param string[] $translations Translations.
	 */
	public static function ok( array $translations ): self {
		return new self( true, $translations, '' );
	}

	public static function fail( string $error ): self {
		return new self( false, array(), $error );
	}
}
