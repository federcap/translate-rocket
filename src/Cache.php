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
	/** Cron event of the CDN purge (see purge_remote_later()). */
	public const REMOTE_EVENT = 'trrocket_purge_remote';
	/** Cron event of the flush booked by background work (see flush_background()). */
	public const QUIET_EVENT = 'trrocket_flush_quiet';
	/** Option: when background work last emptied the caches. */
	private const QUIET_LAST = 'trrocket_flush_quiet_last';
	private const TTL        = 21600; // 6 hours.

	/**
	 * Whether caching should run for the current request.
	 */
	public static function enabled(): bool {
		if ( is_user_logged_in() ) {
			return false; // Never serve a shared cache to a logged-in user.
		}
		$on = ! empty( Settings::get()['cache_pages'] );
		// 1.8.6: with another page cache on the site, ours steps aside by itself — two caches on top of each
		// other keep serving an old translation even after the outer one is cleared (Conflicts). The saved
		// setting does not change: without that cache, ours is back.
		if ( $on && array() !== Conflicts::page_caches() ) {
			$on = false;
		}
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
	/**
	 * Whether a flush has already been booked for the end of this request.
	 *
	 * @var bool
	 */
	private static $da_svuotare = false;

	/**
	 * Book one flush for the end of the request.
	 *
	 * flush() is deliberately expensive — it deletes every cached page and asks the
	 * host cache, the cache plugins and the CDN to drop their copies — so it must not
	 * run once per string. But leaving each caller to remember it is how a stale page
	 * survives an edit: the call was repeated by hand in a dozen places in the admin
	 * screens and missing from the REST save, which is exactly how ticket #18 reached
	 * a customer (a corrected translation that kept showing the old text).
	 *
	 * Booking it instead makes it automatic and cheap: a loop that saves ten thousand
	 * translations books it ten thousand times and pays for it once, when the request
	 * ends. Reproduced and covered by `_ssh/collaudi/collaudo-cache.sh` (23/09/2026).
	 */
	public static function flush_later(): void {
		if ( self::$da_svuotare ) {
			return;
		}
		self::$da_svuotare = true;
		// On WP-CLI and inside `php -r` there is no shutdown action to lean on, so
		// register with PHP itself: the cache must be dropped there too.
		if ( function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_booked' ), 1 );
		}
		register_shutdown_function( array( __CLASS__, 'flush_booked' ) );
	}

	/**
	 * Run the booked flush, once.
	 */
	public static function flush_booked(): void {
		if ( ! self::$da_svuotare ) {
			return;
		}
		self::$da_svuotare = false;
		self::flush();
	}

	/**
	 * 21/10/2026 (translaterocket.com, a server with one CPU: the home page was a cache MISS three times in a minute,
	 * visitors waited 4-7 s): background work — Labs translating the whole site, the hourly rounds, WP-CLI — emptied
	 * EVERY cache (ours, Breeze, Varnish, the object cache) after each page, every two minutes for hours. Background
	 * work now empties them at most once every ten minutes, and books the last flush so its last translation still
	 * shows within ten minutes. What the owner does by hand keeps flushing at once.
	 *
	 * @param int $gap Seconds between two flushes.
	 */
	public static function flush_background( int $gap = 600 ): void {
		$gap  = (int) apply_filters( 'trrocket_background_flush_gap', $gap );
		$last = (int) get_option( self::QUIET_LAST, 0 );
		if ( $gap <= 0 || time() - $last >= $gap ) {
			self::flush_quiet_now();
			return;
		}
		if ( ! wp_next_scheduled( self::QUIET_EVENT ) ) {
			wp_schedule_single_event( $last + $gap, self::QUIET_EVENT );
		}
	}

	/**
	 * The booked background flush (cron event QUIET_EVENT), or one due now.
	 */
	public static function flush_quiet_now(): void {
		update_option( self::QUIET_LAST, time(), false );
		wp_clear_scheduled_hook( self::QUIET_EVENT );
		// 1.8.25 (28/10/2026, globaltoolszone: 4.000 pages, 13 languages all OFFLINE while Labs translated them, «65% CPU
		// with spikes» even after 1.8.24): every ten minutes this emptied the site's whole page cache (LiteSpeed, WP Rocket…)
		// and the warm-up rebuilt 4.000 pages — for languages no visitor can see. While every language is offline, the
		// background work only retires OUR copies (the owner's preview stays fresh); the site's caches are left alone.
		self::flush( ! self::every_language_offline() );
	}

	/**
	 * No translated page is visible to visitors: every secondary language is offline (or there is none).
	 */
	public static function every_language_offline(): bool {
		$router = Plugin::instance()->router();
		$lingue = $router->secondary_languages();
		return empty( $lingue ) || ! array_diff( $lingue, $router->offline_languages() );
	}

	/**
	 * Is this request background work (WP-Cron, WP-CLI)?
	 */
	public static function in_background(): bool {
		return ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * @param bool $anche_esterne Also empty the site's own page caches (cache plugins, host cache, CDN). False only for
	 *                            background work while every language is offline: nothing a visitor sees has changed.
	 */
	public static function flush( bool $anche_esterne = true ): void {
		// Se cambia il contenuto, puo' essere cambiato anche cosa resta da fare.
		if ( class_exists( '\\TranslateRocket\\Admin\\NextSteps' ) ) {
			\TranslateRocket\Admin\NextSteps::forget();
		}
		update_option( self::VER_OPTION, self::version() + 1 );

		if ( ! wp_using_ext_object_cache() ) {
			global $wpdb;
			$like = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
			$tout = $wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%';
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $tout ) ); // phpcs:ignore WordPress.DB
		}

		if ( $anche_esterne ) {
			self::purge_external();
		}
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
		// WP Fastest Cache: `wpfc_clear_all_cache` sembra una funzione ma e' un
		// METODO della sua classe (wpFastestCache.php:2791), quindi il controllo
		// function_exists() piu' sotto non lo trovava mai e la sua cache non veniva
		// svuotata: la pagina vecchia restava servita. L'azione invece esiste
		// (wpFastestCache.php:174). Verificato sul plugin vero il 23/09/2026.
		do_action( 'wpfc_clear_all_cache' );

		// Plugins that expose a function instead of an action.
		$callables = array(
			'rocket_clean_domain',       // WP Rocket.
			'w3tc_flush_all',            // W3 Total Cache.
			'wp_cache_clear_cache',      // WP Super Cache.
			'sg_cachepress_purge_cache', // SiteGround Optimizer.
			'wpo_cache_flush',           // WP-Optimize.
		);
		foreach ( $callables as $fn ) {
			if ( function_exists( $fn ) ) {
				$fn();
			}
		}

		// 1.8.8 (Aruba HiSpeed Cache 3.1.0, read from wordpress.org; a real deactivation on Aruba): its nginx cache
		// is cleared by a request to the server's local purger, which only the plugin's own classes know.
		\TranslateRocket\Integrations\ArubaHiSpeed::purge_all();
		\TranslateRocket\Integrations\Themify::purge_all(); // 1.8.26: Themify's page cache (its files, one per language)

		// 1.8.6 (cache research 14/10): the caches that were never cleared when a translation changed.
		do_action( 'wphb_clear_page_cache' ); // Hummingbird.
		if ( class_exists( '\FlyingPress\Purge' ) && method_exists( '\FlyingPress\Purge', 'purge_everything' ) ) {
			\FlyingPress\Purge::purge_everything();
		}
		if ( class_exists( 'WpeCommon' ) ) { // WP Engine.
			if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
				\WpeCommon::purge_varnish_cache();
			}
			if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
				\WpeCommon::purge_memcached();
			}
		}
		if ( isset( $GLOBALS['kinsta_cache'] ) && is_object( $GLOBALS['kinsta_cache'] ) && isset( $GLOBALS['kinsta_cache']->kinsta_cache_purge ) && method_exists( $GLOBALS['kinsta_cache']->kinsta_cache_purge, 'purge_complete_caches' ) ) {
			$GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_complete_caches(); // Kinsta.
		}
		if ( class_exists( 'VarnishPurger' ) ) { // Proxy Cache Purge: it purges everything on its own «full» events.
			do_action( 'trrocket_varnish_purge_all' );
		}

		self::purge_remote_later();
	}

	/**
	 * CDNs in front of the site (NitroPack, Cloudflare) are purged through their API,
	 * which counts every call: a bulk translation that saves for ten minutes must not
	 * call it ten minutes long. One purge is booked a minute after the change, and
	 * further changes in that minute ride on it (casi raccolti, cache 20-21, 25/9/2026).
	 */
	private static function purge_remote_later(): void {
		$nitro = function_exists( 'nitropack_sdk_invalidate' );
		$cf    = isset( $GLOBALS['cloudflareHooks'] ) && is_object( $GLOBALS['cloudflareHooks'] ) && method_exists( $GLOBALS['cloudflareHooks'], 'purgeCacheEverything' );
		if ( ! $nitro && ! $cf && ! has_action( 'trrocket_purge_remote_caches' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::REMOTE_EVENT ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REMOTE_EVENT );
		}
	}

	/**
	 * The booked CDN purge.
	 *
	 * NitroPack: invalidate, the call its own integrations use (Elementor, WPBakery):
	 * pages are marked stale and rebuilt, nothing is thrown away at once.
	 * Cloudflare: its plugin's own «purge everything» (only acts when its page cache or
	 * APO is on). Its list of purge actions is read when it loads, before us, so a
	 * filter on it would arrive too late: the call is direct.
	 */
	public static function purge_remote(): void {
		if ( ! apply_filters( 'trrocket_purge_external_caches', true ) ) {
			return;
		}
		if ( function_exists( 'nitropack_sdk_invalidate' ) ) {
			nitropack_sdk_invalidate( null, null, 'TranslateRocket: translations changed' );
		}
		$cf = $GLOBALS['cloudflareHooks'] ?? null;
		if ( is_object( $cf ) && method_exists( $cf, 'purgeCacheEverything' ) ) {
			$cf->purgeCacheEverything();
		}
		/**
		 * Fires when TranslateRocket purges the CDNs, a minute after translations changed.
		 */
		do_action( 'trrocket_purge_remote_caches' );
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
