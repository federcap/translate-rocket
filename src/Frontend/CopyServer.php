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
		// 22/11/2026 (lungomare.ee, Brizy): page builders choose the page they draw on `wp` too. Hooked at the default
		// priority, a builder loaded before us kept drawing the original page — the edits made in the copy never
		// showed. The swap now runs first, so everyone sees the same page.
		add_action( 'wp', array( $this, 'maybe_swap' ), -1000 );
		// The served copy is a draft in the database (so it never leaks as a page of its own), but for this
		// request it IS the public page: WordPress printed no canonical for it and SEO plugins /xx/?page_id=N.
		add_filter( 'get_post_status', array( $this, 'served_status' ), 10, 2 );
		foreach ( array( 'page_link', 'post_link', 'post_type_link' ) as $hook ) {
			add_filter( $hook, array( $this, 'served_link' ), PHP_INT_MAX, 2 );
		}
	}

	/**
	 * The source post id of the copy being served (0 = none).
	 *
	 * @var int
	 */
	private static $source = 0;

	/**
	 * The source post id of the copy being served (0 if none).
	 */
	public static function source_id(): int {
		return self::$source;
	}

	/**
	 * While it is served, the copy counts as published.
	 *
	 * @param mixed $status Post status.
	 * @param mixed $post   Post object.
	 * @return mixed
	 */
	public function served_status( $status, $post ) {
		if ( self::$serving > 0 && 'draft' === $status && $post instanceof \WP_Post && (int) $post->ID === self::$serving ) {
			return 'publish';
		}
		return $status;
	}

	/**
	 * The address of the served copy is the address of its source in this language
	 * (/it/guida/), never the draft's own ?page_id=N.
	 *
	 * @param mixed $link Permalink.
	 * @param mixed $post Post id or object.
	 * @return mixed
	 */
	public function served_link( $link, $post ) {
		if ( self::$serving <= 0 || self::$source <= 0 ) {
			return $link;
		}
		$id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		if ( $id !== self::$serving ) {
			return $link;
		}
		$real = get_permalink( self::$source );
		return is_string( $real ) && '' !== $real ? $real : $link;
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
		self::$source                = $source_id;
	}
}
