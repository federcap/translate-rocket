<?php
/**
 * Enforces per-page language visibility rules on the front end.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Exclusions;

defined( 'ABSPATH' ) || exit;

/**
 * When a singular page is excluded from the current (secondary) language, this
 * runs before the translation engine and applies the chosen fallback:
 * redirect to the language home, redirect to a URL, or serve a 404.
 */
class Visibility {

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		if ( is_admin() ) {
			return;
		}
		// Priority 0: before the Engine (priority 1) starts buffering.
		add_action( 'template_redirect', array( $this, 'enforce' ), 0 );
	}

	/**
	 * Apply the rule for the current request, if any.
	 */
	public function enforce(): void {
		// In the visual editor the admin must be able to reach any page to change its
		// visibility rule — otherwise a "redirect/404" page could never be re-edited.
		if ( VisualEditor::is_editing() ) {
			return;
		}

		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( $router->is_default( $lang ) || ! is_singular() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$rule = Exclusions::get( $post_id, $lang );
		switch ( $rule['mode'] ) {
			case 'home':
				wp_safe_redirect( $router->home_for_language( $lang ), 302 );
				exit;

			case 'url':
				if ( '' !== $rule['url'] ) {
					wp_redirect( $rule['url'], 302 ); // phpcs:ignore WordPress.Security.SafeRedirect -- admin-set destination, may be external.
					exit;
				}
				return;

			case 'message':
				if ( '' !== trim( $rule['msg'] ) ) {
					$msg = $rule['msg'];
					add_filter(
						'the_content',
						static function () use ( $msg ) {
							return wpautop( wp_kses_post( $msg ) );
						},
						99
					);
				}
				return;

			case '404':
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
				return;
		}
	}
}
