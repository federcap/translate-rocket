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
	 * 21/10/2026 (translaterocket.com, sentinel: 60 «AI translation failed» rows a day that were only DeepL resting and
	 * Edge waiting for the next hour): when the engine did not try at all because it is resting, the time it comes back.
	 * 0 = a real failure.
	 *
	 * @var int
	 */
	public $resting = 0;

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

	/**
	 * The engine is resting (a pause it chose, an hourly share used up) and comes back by itself at $until.
	 *
	 * @param string $error What to tell the owner.
	 * @param int    $until Unix time it is tried again.
	 */
	public static function rest( string $error, int $until ): self {
		$r          = new self( false, array(), $error );
		$r->resting = max( time() + 1, $until );
		return $r;
	}
}
