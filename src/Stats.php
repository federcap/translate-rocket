<?php
/**
 * Visits per language (1.8.6, 14/10/2026, Federico: «statistiche per lingua: ottima idea»).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * How many visits each language gets, which pages, and which languages the visitors' browsers speak — the
 * number that says which language is worth adding next.
 *
 * Counted by a small signal the browser sends after the page has loaded: with a page cache (LiteSpeed,
 * Cloudflare, WP Rocket…) PHP does not run for a visitor, so a counter on the server would see nothing.
 * No cookie, no IP address, nothing personal is kept: one row per day, language, page and browser
 * language, with a number. Robots, the site's administrators and headless browsers are not counted.
 */
class Stats {

	const ACTION = 'trrocket_stat';
	const PRUNE  = 'trrocket_stats_prune';
	const KEEP   = 400; // days
	const MAX_DAY_ROWS = 5000; // distinct rows a day; beyond, new pages count under «(other)»

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'trrocket_stats';
	}

	public static function enabled(): bool {
		return ! empty( Settings::get()['stats'] );
	}

	/**
	 * Hooks: the signal on every public page when switched on, and its receiver.
	 */
	public function register(): void {
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'record' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'record' ) );
		add_action( self::PRUNE, array( __CLASS__, 'prune' ) );
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'wp_footer', array( $this, 'signal' ), 99 );
		if ( ! wp_next_scheduled( self::PRUNE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE );
		}
	}

	/**
	 * The signal: a few bytes sent once the page has loaded (sendBeacon), so it never slows the page.
	 */
	public function signal(): void {
		if ( is_admin() || is_feed() || is_404() || current_user_can( 'manage_options' ) || \TranslateRocket\Frontend\Preview::hidden() ) {
			return;
		}
		$router = Plugin::instance()->router();
		$lang   = (string) $router->current_language();
		if ( '' === $lang ) {
			return;
		}
		$url = admin_url( 'admin-ajax.php' );
		// Built by hand and printed as one small inline script: it must also run on pages served by a page cache.
		echo '<script>(function(){try{if(navigator.webdriver){return;}var d=new FormData();d.append("action",' . wp_json_encode( self::ACTION ) . ');d.append("l",' . wp_json_encode( $lang ) . ');d.append("p",location.pathname);d.append("b",(navigator.language||"").slice(0,2).toLowerCase());var u=' . wp_json_encode( $url ) . ';if(navigator.sendBeacon){navigator.sendBeacon(u,d);}else{fetch(u,{method:"POST",body:d,keepalive:true,credentials:"omit"});}}catch(e){}})();</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is wp_json_encode()d.
	}

	/**
	 * Is this request a robot? Search engines, AI crawlers, monitors and scripts do not count as visits.
	 */
	public static function is_robot(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only matched.
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|httpclient|axios|node-fetch|go-http|java\/|scrapy|phantom|selenium|puppeteer|playwright/i', $ua );
	}

	/**
	 * AJAX (also for visitors): count one visit. Never writes anything for a robot or an administrator, and
	 * accepts only the site's languages and a page address.
	 */
	public function record(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- a counter for anonymous, page-cached visits: a nonce would be stale; it only ever adds 1 to a known language.
		if ( ! self::enabled() || self::is_robot() || current_user_can( 'manage_options' ) ) {
			wp_send_json_success();
		}
		$lang = isset( $_POST['l'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['l'] ) ) ) : '';
		$path = isset( $_POST['p'] ) ? (string) wp_unslash( $_POST['p'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$bl   = isset( $_POST['b'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['b'] ) ) ) : '';
		// phpcs:enable
		$router = Plugin::instance()->router();
		$known  = array_merge( array( (string) $router->default_language() ), (array) $router->public_languages() );
		if ( ! in_array( $lang, $known, true ) ) {
			wp_send_json_success();
		}
		$path = '/' . ltrim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' );
		$path = substr( (string) preg_replace( '/[^\p{L}\p{N}\/\-_.~%]/u', '', $path ), 0, 190 );
		$bl   = preg_match( '/^[a-z]{2,3}$/', $bl ) ? $bl : '';
		self::add( $lang, $path, $bl );
		wp_send_json_success();
	}

	/**
	 * One visit more, in one query.
	 */
	private static function add( string $lang, string $path, string $bl ): void {
		global $wpdb;
		$t   = self::table();
		$day = gmdate( 'Y-m-d' );
		$h   = sha1( $path );
		// A day can hold only so many different pages: a flood of invented addresses would otherwise fill the table.
		$c = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE day = %s", $day ) ); // phpcs:ignore WordPress.DB
		if ( $c >= self::MAX_DAY_ROWS ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE day = %s AND lang = %s AND path_hash = %s AND browser = %s", $day, $lang, $h, $bl ) ); // phpcs:ignore WordPress.DB
			if ( null === $exists ) {
				$path = '(other)';
				$h    = sha1( $path );
			}
		}
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (day, lang, path_hash, path, browser, views) VALUES (%s, %s, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1", $day, $lang, $h, $path, $bl ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Old days go (cron, once a day).
	 */
	public static function prune(): void {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE day < %s", gmdate( 'Y-m-d', time() - self::KEEP * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Visits per language in the last days.
	 *
	 * @return array<string,int> lang => visits, most first.
	 */
	public static function by_language( int $days = 30 ): array {
		global $wpdb;
		$t   = self::table();
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT lang, SUM(views) AS n FROM {$t} WHERE day >= %s GROUP BY lang ORDER BY n DESC", gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ) ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (string) $r->lang ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * The most visited pages of a language.
	 *
	 * @return array<string,int> path => visits.
	 */
	public static function top_pages( string $lang, int $days = 30, int $limit = 5 ): array {
		global $wpdb;
		$t   = self::table();
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT path, SUM(views) AS n FROM {$t} WHERE day >= %s AND lang = %s GROUP BY path_hash, path ORDER BY n DESC LIMIT %d", gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ), $lang, $limit ) ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (string) $r->path ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * The languages the visitors' browsers speak, on every version of the site.
	 *
	 * @return array<string,int> browser language => visits, most first.
	 */
	public static function browser_languages( int $days = 30, int $limit = 10 ): array {
		global $wpdb;
		$t   = self::table();
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT browser, SUM(views) AS n FROM {$t} WHERE day >= %s AND browser <> '' GROUP BY browser ORDER BY n DESC LIMIT %d", gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ), $limit ) ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (string) $r->browser ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * Visits per day for one language (or all), oldest first, every day present (0 when none).
	 *
	 * @return array<string,int> Y-m-d => visits.
	 */
	public static function per_day( int $days = 30, string $lang = '' ): array {
		global $wpdb;
		$t    = self::table();
		$from = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$rows = '' === $lang
			? $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(views) AS n FROM {$t} WHERE day >= %s GROUP BY day", $from ) ) // phpcs:ignore WordPress.DB
			: $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(views) AS n FROM {$t} WHERE day >= %s AND lang = %s GROUP BY day", $from, $lang ) ); // phpcs:ignore WordPress.DB
		$got = array();
		foreach ( (array) $rows as $r ) {
			$got[ (string) $r->day ] = (int) $r->n;
		}
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d         = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$out[ $d ] = $got[ $d ] ?? 0;
		}
		return $out;
	}
}
