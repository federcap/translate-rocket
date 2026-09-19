<?php
/**
 * Optional page cache for translated output.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the finished, translated HTML of a page so repeat visits by anonymous
 * visitors skip the on-the-fly DOM translation. Invalidation is version-based: a
 * single option holds a version number that is part of every cache key, so
 * bumping it (on any translation/content/settings change) instantly retires every
 * cached entry without having to enumerate transients. A TTL is a final backstop.
 */
class Cache {

	private const VER_OPTION = 'trrocket_cache_ver';
	private const PREFIX     = 'trrocket_pc_';
	private const TTL        = 21600; // 6 hours.

	/**
	 * Whether caching should run for the current request.
	 */
	public static function enabled(): bool {
		if ( is_user_logged_in() ) {
			return false; // Never serve a shared cache to a logged-in user.
		}
		$on = ! empty( Settings::get()['cache_pages'] );
		return (bool) apply_filters( 'trrocket_enable_cache', $on );
	}

	/**
	 * Current cache version (part of every key).
	 */
	public static function version(): int {
		return (int) get_option( self::VER_OPTION, 1 );
	}

	/**
	 * A post was saved, trashed or deleted: what the engine sees may have changed,
	 * so every cached translated page goes. Revisions and autosaves are skipped —
	 * the block editor autosaves every minute while someone is typing, and each
	 * flush also asks the host cache and CDN plugins to purge.
	 *
	 * Hooked from the plugin bootstrap, not the admin branch: the block editor
	 * saves over the REST API, where is_admin() is false.
	 *
	 * Only posts a visitor can see, plus the templates that shape every page
	 * (Site Editor parts, Elementor and HFE templates). Now that the hook runs on
	 * every request, an order placed at checkout or a form entry stored as a post
	 * must not purge the whole site.
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    The post, when the hook provides it.
	 */
	public static function post_changed( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}
		$type = $post instanceof \WP_Post ? (string) $post->post_type : '';
		if ( '' === $type ) {
			return;
		}
		$templates = apply_filters( 'trrocket_cache_template_types', array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block', 'wp_global_styles', 'elementor_library', 'elementor-hf' ) );
		$tipo      = get_post_type_object( $type );
		if ( ! in_array( $type, (array) $templates, true ) && ( ! $tipo || empty( $tipo->public ) ) ) {
			return;
		}
		self::content_changed();
	}

	/**
	 * Something shown on every page changed (a widget, a menu, the customizer…):
	 * flush once per request, however many hooks fire — saving the widgets screen
	 * fires one per widget, and every flush also purges the host cache.
	 */
	public static function content_changed(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		self::flush();
	}

	/**
	 * Invalidate every cached page. The version bump retires all keys instantly
	 * (and is the only thing that works with a persistent object cache); we also
	 * delete the stored transients so the options table doesn't accumulate garbage
	 * on sites without an object cache.
	 */
	public static function flush(): void {
		update_option( self::VER_OPTION, self::version() + 1 );

		if ( ! wp_using_ext_object_cache() ) {
			global $wpdb;
			$like = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
			$tout = $wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%';
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $tout ) ); // phpcs:ignore WordPress.DB
		}

		self::purge_external();
	}

	/**
	 * Ask the HTTP-layer caches (host page cache, cache plugins, CDN plugins) to
	 * drop their copies too, so an edited translation shows up immediately instead
	 * of lingering behind their own TTL. Bumping our version only retires OUR
	 * cache; these layers store the finished HTML and don't know a translation
	 * changed. Called from flush(), which fires at coarse moments (a bulk run
	 * finishes, a translation is reset, a copy is toggled) — never once per string.
	 *
	 * Site owners can bolt on their own CDN purge (or silence this) via the two
	 * hooks below.
	 */
	private static function purge_external(): void {
		/**
		 * Fires when TranslateRocket clears its page cache — the place to purge a
		 * CDN or any cache not covered by the built-in integrations below.
		 */
		do_action( 'trrocket_flush_caches' );

		if ( ! apply_filters( 'trrocket_purge_external_caches', true ) ) {
			return;
		}

		// Breeze (Cloudways), LiteSpeed, Cache Enabler, Nginx Helper: these listen
		// on their own action.
		do_action( 'breeze_clear_all_cache' );
		do_action( 'litespeed_purge_all' );
		do_action( 'cache_enabler_clear_complete_cache' );
		do_action( 'rt_nginx_helper_purge_all' );

		// Plugins that expose a function instead of an action.
		$callables = array(
			'rocket_clean_domain',       // WP Rocket.
			'w3tc_flush_all',            // W3 Total Cache.
			'wp_cache_clear_cache',      // WP Super Cache.
			'wpfc_clear_all_cache',      // WP Fastest Cache.
			'sg_cachepress_purge_cache', // SiteGround Optimizer.
			'wpo_cache_flush',           // WP-Optimize.
		);
		foreach ( $callables as $fn ) {
			if ( function_exists( $fn ) ) {
				$fn();
			}
		}
	}

	/**
	 * Read a cached page, or false on a miss.
	 *
	 * @param string $url  Request URI.
	 * @param string $lang Language code.
	 * @return string|false
	 */
	public static function get( string $url, string $lang ) {
		$v = get_transient( self::key( $url, $lang ) );
		return is_string( $v ) ? $v : false;
	}

	/**
	 * Store a rendered translated page.
	 *
	 * @param string $url  Request URI.
	 * @param string $lang Language code.
	 * @param string $html Finished HTML.
	 */
	public static function set( string $url, string $lang, string $html ): void {
		if ( '' !== $html ) {
			set_transient( self::key( $url, $lang ), $html, self::TTL );
		}
	}

	/**
	 * Build the version-stamped cache key for a URL + language.
	 */
	private static function key( string $url, string $lang ): string {
		return self::PREFIX . md5( self::version() . '|' . $lang . '|' . $url );
	}
}
