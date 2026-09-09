<?php
/**
 * Preview mode (soft launch): serve translations to administrators only.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * When the "Who sees the translations" setting is "admins", the translated
 * site stays fully browsable for administrators (so an import or a batch of
 * new translations can be reviewed on the real rendered pages) while every
 * other visitor keeps seeing the default language only: language URLs
 * redirect to their default-language equivalent, the switcher and the
 * hreflang/sitemap signals are withheld. Flipping the setting back to
 * "everyone" publishes the translations instantly.
 *
 * The same machinery serves a second, narrower case: a language the owner has
 * added but not published yet. That one language behaves exactly like preview
 * mode - hidden from the switcher, the sitemap and hreflang, its URLs sending
 * visitors to the default language - while the rest of the site is public as
 * usual.
 */
class Preview {

	/**
	 * Are translations hidden from the current viewer?
	 *
	 * True only when preview mode is on AND the viewer lacks the preview
	 * capability (filterable, so e.g. editors can be allowed to review too).
	 */
	public static function hidden(): bool {
		$settings = Settings::get();
		if ( 'admins' !== ( $settings['serve_mode'] ?? 'everyone' ) ) {
			return false;
		}
		/**
		 * Capability required to see translations while preview mode is on.
		 *
		 * @param string $capability Defaults to 'manage_options'.
		 */
		$cap = apply_filters( 'trrocket_preview_capability', 'manage_options' );
		return ! current_user_can( $cap );
	}

	/**
	 * Is the language of THIS request one the viewer is not allowed to see?
	 *
	 * Two different things end up here:
	 *  - preview mode, which hides every translation from everyone but the
	 *    reviewer;
	 *  - a single language switched off while it is being translated.
	 *
	 * Both mean the same for a visitor: this page is not published yet.
	 */
	public static function language_blocked(): bool {
		if ( self::hidden() ) {
			return true;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( $router->is_default( $lang ) || ! $router->is_offline( $lang ) ) {
			return false;
		}
		/** This filter is documented above. */
		$cap = apply_filters( 'trrocket_preview_capability', 'manage_options' );
		return ! current_user_can( $cap );
	}

	/**
	 * Whether preview mode is enabled at all (regardless of viewer).
	 */
	public static function enabled(): bool {
		return 'admins' === ( Settings::get()['serve_mode'] ?? 'everyone' );
	}

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		if ( is_admin() ) {
			return;
		}
		// Priority -1: before Visibility (0), the Engine and its page cache (1)
		// and the browser-language Redirect (1), so a hidden viewer never
		// receives translated output from any of them.
		add_action( 'template_redirect', array( $this, 'enforce' ), -1 );
	}

	/**
	 * Send hidden viewers from a language URL to its default-language page.
	 */
	public function enforce(): void {
		// Non solo la modalita' anteprima: anche una singola lingua tenuta
		// offline mentre la si traduce.
		if ( ! self::language_blocked() ) {
			return;
		}
		$router = Plugin::instance()->router();
		if ( $router->is_default( $router->current_language() ) ) {
			return;
		}

		// Singular pages may have been reached via a translated slug: build the
		// destination from the post's canonical (default-language) permalink so
		// the redirect never lands on a 404.
		$target = '';
		if ( function_exists( 'is_singular' ) && is_singular() ) {
			$post_id = (int) get_queried_object_id();
			if ( $post_id > 0 ) {
				$target = rtrim( (string) get_option( 'home' ), '/' ) . $router->canonical_path( $post_id );
			}
		}
		if ( '' === $target ) {
			$target = $router->url_for_language( $router->default_language() );
		}

		wp_safe_redirect( $target, 302 );
		exit;
	}
}
