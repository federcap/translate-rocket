<?php
/**
 * Themify (Ultra and the other Themify themes, Themify Builder): its page cache and the translated addresses.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

use TranslateRocket\Plugin;

/**
 * 1.8.26 (28/10/2026, ticket 25, a Polish site on Themify Ultra 8.2.2: «in a private window the language changes at
 * random from one page to the next»). Themify's page cache (themify/cache/class-themify-cache.php, loaded from
 * advanced-cache.php) saves every page in a file named after $_SERVER['REQUEST_URI'], read at `init` priority 0.
 * TranslateRocket strips the language from that address much earlier (Router::detect_and_strip, so WordPress serves
 * the page): Themify saw «/» for /, /de/ and /en/ alike, kept ONE file for every language, and served whichever
 * language had been rendered first to everybody. Here Themify gets the address with the language back for the moment
 * it names its file, and its cache is emptied whenever TranslateRocket empties its own.
 */
class Themify {

	/**
	 * The stripped address, while Themify is given the original one.
	 *
	 * @var string|null
	 */
	private static $stripped = null;

	/**
	 * Hooks: only around Themify's `init` 0 (TFCache::run), on the front end.
	 */
	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'before_themify' ), -1 );
		add_action( 'init', array( __CLASS__, 'after_themify' ), 1 );
	}

	/**
	 * Is Themify's page cache in this request? Its drop-in defines themify_cache_loaded(); its class may be loaded
	 * by the theme or the Builder plugin even when the drop-in is not.
	 */
	public static function active(): bool {
		return function_exists( 'themify_cache_loaded' ) || class_exists( '\TFCache' );
	}

	/**
	 * Just before Themify names its cache file: the address with the language.
	 */
	public static function before_themify(): void {
		if ( ! self::active() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$router   = Plugin::instance()->router();
		$original = $router->original_request_uri();
		if ( $router->current_language() === $router->default_language() || '' === $original ) {
			return;
		}
		self::$stripped         = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REQUEST_URI'] = $original;
	}

	/**
	 * Right after: the stripped address again, so WordPress routes the page as before.
	 */
	public static function after_themify(): void {
		if ( null !== self::$stripped ) {
			$_SERVER['REQUEST_URI'] = self::$stripped;
			self::$stripped         = null;
		}
	}

	/**
	 * Empty Themify's page cache (called from Cache::purge_external).
	 */
	public static function purge_all(): void {
		if ( class_exists( '\TFCache' ) && method_exists( '\TFCache', 'remove_cache' ) ) {
			try {
				\TFCache::remove_cache( 'all' );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// Themify's file system helper is missing on an odd set-up: nothing to empty.
			}
		}
	}
}
