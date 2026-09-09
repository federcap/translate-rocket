<?php
/**
 * Serves independent page copies on the front end.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Copies;

defined( 'ABSPATH' ) || exit;

/**
 * When a singular page in a secondary language has an active independent copy
 * (see TranslateRocket\Copies), this swaps the queried post for that copy so the
 * theme renders it natively — same URL (/it/<slug>/), real post, fully cacheable,
 * with no runtime translation. The runtime translation engine is told to stand
 * down for the request (it checks is_serving_copy()).
 */
class CopyServer {

	/**
	 * The copy post id being served this request (0 = none).
	 *
	 * @var int
	 */
	private static $serving = 0;

	/**
	 * Hook into the front end. Runs on `wp`, right after the main query is built and
	 * before the template loads, so the swap is in place for template selection,
	 * the loop and the engine's stand-down check.
	 */
	public function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp', array( $this, 'maybe_swap' ) );
	}

	/**
	 * Whether an independent copy is being served for the current request.
	 */
	public static function is_serving_copy(): bool {
		return self::$serving > 0;
	}

	/**
	 * The copy post id being served (0 if none).
	 */
	public static function serving_id(): int {
		return self::$serving;
	}

	/**
	 * Swap the queried source post for its active copy, if any.
	 */
	public function maybe_swap(): void {
		if ( self::$serving > 0 || is_admin() || ! is_singular() ) {
			return;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( $router->is_default( $lang ) ) {
			return;
		}

		global $wp_query;
		$source_id = (int) $wp_query->get_queried_object_id();
		if ( $source_id <= 0 || Copies::is_copy( $source_id ) ) {
			return;
		}
		$copy_id = Copies::active_copy_id( $source_id, $lang );
		if ( $copy_id <= 0 ) {
			return;
		}
		// A password-protected source must show its password form first. Old copies
		// may not carry the password, so never swap while the source is still locked
		// (once the visitor unlocks it, the swap resumes on the next request).
		if ( post_password_required( $source_id ) ) {
			return;
		}
		$copy = get_post( $copy_id );
		if ( ! ( $copy instanceof \WP_Post ) ) {
			return;
		}

		// Point the main query (and the global post) at the copy, keeping every other
		// conditional (is_page/is_single/template) exactly as resolved for the source.
		$wp_query->posts             = array( $copy );
		$wp_query->post              = $copy;
		$wp_query->queried_object    = $copy;
		$wp_query->queried_object_id = $copy_id;
		$wp_query->post_count        = 1;
		$GLOBALS['post']             = $copy;
		self::$serving               = $copy_id;
	}
}
