<?php
/**
 * Cache warm-up after a flush (1.8.18).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * 22/10/2026 (Federico: «riscaldare la cache dopo ogni svuotamento è un'opzione che potremmo mettere nel plugin?»):
 * after TranslateRocket clears the caches, the first visitor of every page waited for it to be built again (0.7-6 s
 * on translaterocket.com, one CPU). A minute after a flush, the most visited pages of each language — from the
 * plugin's own statistics, or the menu when they are off — are visited again in the background, one at a time, as a
 * computer and, when the cache keeps phones apart, as a phone. Only when a page cache is there to be filled.
 */
class CacheWarmup {

	const EVENT = 'trrocket_cache_warmup';
	const QUEUE = 'trrocket_warmup_queue';
	const LAST  = 'trrocket_warmup_last';

	/** Pages per language besides its home. */
	const PER_LANG = 8;

	/**
	 * Visits per cron run; the next run follows GAP seconds later. 22/10/2026 (collaudo-visivo on the test bench): twelve
	 * visits in one request, with pauses, kept a PHP worker busy for half a minute while its own visits needed another —
	 * on a server with few workers, real visitors waited past 60 s. Three at most, never longer than BUDGET seconds.
	 */
	const PER_RUN = 3;

	/** Seconds between two runs. */
	const GAP = 20;

	/** A run stops after this many seconds, whatever is left. */
	const BUDGET = 8;

	/** Never more than this many visits after one flush. */
	const MAX = 300;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'trrocket_flush_caches', array( __CLASS__, 'schedule' ) );
		add_action( self::EVENT, array( __CLASS__, 'run' ) );
	}

	/**
	 * The setting: on unless switched off.
	 */
	public static function enabled(): bool {
		$s = Settings::get();
		return ! array_key_exists( 'cache_warmup', $s ) || ! empty( $s['cache_warmup'] );
	}

	/**
	 * Is there a page cache to fill? Ours, or one of the known page-cache plugins / host caches.
	 */
	public static function has_page_cache(): bool {
		return Cache::enabled() || (bool) Conflicts::page_caches();
	}

	/**
	 * After a flush: start again from the top a minute later (a second flush meanwhile restarts the list).
	 */
	public static function schedule(): void {
		if ( ! self::enabled() || ! self::has_page_cache() || Coexistence::on() ) {
			return;
		}
		delete_option( self::QUEUE );
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_single_event( time() + 60, self::EVENT );
		}
	}

	/**
	 * The addresses to visit: each public language's home, then its most visited pages.
	 *
	 * @return string[]
	 */
	public static function urls(): array {
		$router = Plugin::instance()->router();
		$langs  = array_values( array_unique( array_merge( array( (string) $router->default_language() ), (array) $router->public_languages() ) ) );
		$menu   = null;
		$out    = array();
		foreach ( $langs as $lang ) {
			$base = $router->language_base( $lang );
			$out[] = $base . '/';
			$host  = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', $base );
			// «(other)» is where the statistics put the pages past their daily limit: not an address
			$paths = Stats::enabled() ? array_filter( array_map( 'strval', array_keys( Stats::top_pages( $lang, 30, self::PER_LANG + 1 ) ) ), static function ( $p ) { return 0 === strpos( $p, '/' ); } ) : array();
			$paths = array_slice( array_values( $paths ), 0, self::PER_LANG );
			if ( $paths ) {
				foreach ( $paths as $p ) {
					$out[] = $host . '/' . ltrim( (string) $p, '/' );
				}
				continue;
			}
			// No statistics: the pages of the menu, in this language's own addresses.
			if ( null === $menu ) {
				$menu = self::menu_paths();
			}
			foreach ( array_slice( $menu, 0, self::PER_LANG ) as $p ) {
				$out[] = $router->is_default( $lang ) ? $base . $p : $base . $router->translated_link_path( $p, $lang );
			}
		}
		/**
		 * The addresses visited after a cache flush.
		 *
		 * @param string[] $urls Homes and most visited pages of every public language.
		 */
		$out = (array) apply_filters( 'trrocket_warmup_urls', array_values( array_unique( $out ) ) );
		return array_slice( $out, 0, self::MAX );
	}

	/**
	 * Paths (no language prefix) of the pages in the site's menus, in menu order.
	 *
	 * @return string[]
	 */
	private static function menu_paths(): array {
		$home  = (string) get_option( 'home' );
		$hpath = rtrim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
		$host  = (string) wp_parse_url( $home, PHP_URL_HOST );
		$out   = array();
		foreach ( array_unique( array_filter( (array) get_nav_menu_locations() ) ) as $menu_id ) {
			foreach ( (array) wp_get_nav_menu_items( (int) $menu_id ) as $item ) {
				$url = isset( $item->url ) ? (string) $item->url : '';
				if ( '' === $url || ( wp_parse_url( $url, PHP_URL_HOST ) && 0 !== strcasecmp( (string) wp_parse_url( $url, PHP_URL_HOST ), $host ) ) ) {
					continue;
				}
				$p = (string) wp_parse_url( $url, PHP_URL_PATH );
				if ( '' !== $hpath && 0 === strpos( $p, $hpath ) ) {
					$p = substr( $p, strlen( $hpath ) );
				}
				$p = '/' . ltrim( $p, '/' );
				if ( '/' !== $p && ! in_array( $p, $out, true ) ) {
					$out[] = $p;
				}
			}
		}
		return $out;
	}

	/**
	 * Browsers to visit as: a computer, and a phone when the cache keeps phones apart.
	 *
	 * @return string[]
	 */
	public static function agents(): array {
		$pc     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36 TranslateRocket-warmup';
		$phone  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 TranslateRocket-warmup';
		$rocket = (array) get_option( 'wp_rocket_settings', array() );
		$mobile = ! empty( $rocket['do_caching_mobile_files'] )
			|| ! empty( get_option( 'litespeed.conf.cache-mobile' ) )
			|| function_exists( 'is_breeze_mobile_cache' ); // Breeze on Cloudways: Varnish keeps phones apart
		/**
		 * The browsers the warm-up visits as.
		 *
		 * @param string[] $agents User agents.
		 */
		return (array) apply_filters( 'trrocket_warmup_user_agents', $mobile ? array( $pc, $phone ) : array( $pc ) );
	}

	/**
	 * One step: a few visits, then the next step GAP seconds later.
	 */
	public static function run(): void {
		if ( ! self::enabled() || ! self::has_page_cache() ) {
			delete_option( self::QUEUE );
			return;
		}
		$queue = get_option( self::QUEUE, null );
		if ( ! is_array( $queue ) ) {
			$queue = array();
			foreach ( self::urls() as $u ) {
				foreach ( self::agents() as $a ) {
					$queue[] = array( $u, $a );
				}
			}
			$queue = array_slice( $queue, 0, self::MAX );
			update_option( self::LAST, array( 'started' => time(), 'total' => count( $queue ), 'done' => 0, 'slow' => 0, 'errors' => 0 ), false );
		}
		$last = (array) get_option( self::LAST, array() );
		// A busy server first: with the load above the number of CPUs, the step waits for the next turn.
		if ( function_exists( 'sys_getloadavg' ) ) {
			$load = sys_getloadavg();
			$cpus = (int) apply_filters( 'trrocket_warmup_cpus', 1 );
			if ( is_array( $load ) && isset( $load[0] ) && (float) $load[0] > max( 1, $cpus ) ) {
				update_option( self::QUEUE, $queue, false );
				wp_schedule_single_event( time() + self::GAP * 3, self::EVENT );
				return;
			}
		}
		$start = microtime( true );
		$step  = array();
		while ( $queue && count( $step ) < self::PER_RUN && microtime( true ) - $start < self::BUDGET ) {
			$pair   = array_shift( $queue );
			$step[] = $pair;
			$t0 = microtime( true );
			$r  = wp_remote_get(
				(string) $pair[0],
				array(
					'timeout'     => self::BUDGET + 7,
					'redirection' => 0,
					'user-agent'  => (string) $pair[1],
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
					'headers'     => array( 'Accept' => 'text/html' ),
				)
			);
			$last['done'] = (int) ( $last['done'] ?? 0 ) + 1;
			if ( is_wp_error( $r ) ) {
				// 25/10/2026 (bench and small hosts: «cURL error 28 … 15000 ms» on every visit, editor pages at 24 s): the site
				// cannot call itself right now — every PHP worker busy, or loopback blocked. Insisting kept the workers
				// taken; the rest of the round waits 30 minutes instead.
				$last['errors'] = (int) ( $last['errors'] ?? 0 ) + 1;
				$last['paused'] = time();
				update_option( self::QUEUE, $queue, false );
				update_option( self::LAST, $last, false );
				wp_clear_scheduled_hook( self::EVENT );
				wp_schedule_single_event( time() + 30 * MINUTE_IN_SECONDS, self::EVENT );
				return;
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
				$last['errors'] = (int) ( $last['errors'] ?? 0 ) + 1;
			} elseif ( microtime( true ) - $t0 > 3 ) {
				$last['slow'] = (int) ( $last['slow'] ?? 0 ) + 1;
			}
		}
		if ( $queue ) {
			update_option( self::QUEUE, $queue, false );
			wp_schedule_single_event( time() + self::GAP, self::EVENT );
		} else {
			delete_option( self::QUEUE );
			$last['ended'] = time();
		}
		update_option( self::LAST, $last, false );
	}

	/**
	 * «Last warm-up: …» for the settings screen ('' before the first one).
	 */
	public static function status_line(): string {
		$l = (array) get_option( self::LAST, array() );
		if ( empty( $l['started'] ) ) {
			return '';
		}
		$quando = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $l['ended'] ?? $l['started'] ) );
		return empty( $l['ended'] )
			/* translators: 1: pages visited so far, 2: pages in the list. */
			? sprintf( __( 'Warming up now: %1$d of %2$d pages.', 'translate-rocket' ), (int) $l['done'], (int) $l['total'] )
			/* translators: 1: date and time, 2: pages visited, 3: of those, how many answered with an error. */
			: sprintf( __( 'Last warm-up: %1$s, %2$d pages ready (%3$d with an error).', 'translate-rocket' ), $quando, (int) $l['done'], (int) ( $l['errors'] ?? 0 ) );
	}
}
