<?php
/**
 * AI usage controls: a per-day character budget and crawler detection.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Caps how many characters are sent to the AI provider each day (to protect the
 * user's API spend) and recognises bots so they can't trigger paid translation.
 * The daily tally lives in a transient keyed by date, so it expires on its own.
 */
class AiUsage {

	/**
	 * Configured daily character limit (0 = unlimited).
	 */
	public static function limit(): int {
		return max( 0, (int) ( Settings::get()['ai_daily_limit'] ?? 0 ) );
	}

	/**
	 * Characters translated so far today.
	 */
	public static function used_today(): int {
		return (int) get_transient( self::key() );
	}

	/**
	 * Whether today's budget is spent.
	 */
	public static function over_limit(): bool {
		$limit = self::limit();
		return $limit > 0 && self::used_today() >= $limit;
	}

	/**
	 * Characters still available today (PHP_INT_MAX when unlimited).
	 */
	public static function remaining(): int {
		$limit = self::limit();
		return $limit <= 0 ? PHP_INT_MAX : max( 0, $limit - self::used_today() );
	}

	/**
	 * Add to today's tally, and to the persistent usage counter shown on the AI
	 * page (characters + one "request" per call).
	 */
	public static function add( int $chars ): void {
		if ( $chars <= 0 ) {
			return;
		}
		set_transient( self::key(), self::used_today() + $chars, 36 * HOUR_IN_SECONDS );

		$u             = self::usage(); // rolls the cycle over first if it's a new month.
		$u['chars']    = (int) $u['chars'] + $chars;
		$u['requests'] = (int) $u['requests'] + 1;
		update_option( self::USAGE_OPTION, $u, false );
	}

	private const USAGE_OPTION = 'trrocket_ai_usage';

	/**
	 * Day of the month the usage counter rolls over (1–28, 0 = never / manual).
	 */
	public static function reset_day(): int {
		return max( 0, min( 31, (int) ( Settings::get()['ai_reset_day'] ?? 0 ) ) );
	}

	/**
	 * Raw persistent usage record (no cycle check).
	 *
	 * @return array{chars:int,requests:int,since:string}
	 */
	private static function raw_usage(): array {
		$u = get_option( self::USAGE_OPTION, array() );
		return array(
			'chars'    => (int) ( $u['chars'] ?? 0 ),
			'requests' => (int) ( $u['requests'] ?? 0 ),
			'since'    => (string) ( $u['since'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
	}

	/**
	 * Persistent usage for the current billing cycle (auto-resets when the
	 * monthly reset day has passed since the counter last started).
	 *
	 * @return array{chars:int,requests:int,since:string}
	 */
	public static function usage(): array {
		$u   = self::raw_usage();
		$day = self::reset_day();
		if ( $day > 0 ) {
			$start = self::cycle_start( $day );
			if ( strtotime( $u['since'] . ' UTC' ) < $start ) {
				$u = array(
					'chars'    => 0,
					'requests' => 0,
					'since'    => gmdate( 'Y-m-d H:i:s', $start ),
				);
				update_option( self::USAGE_OPTION, $u, false );
			}
		}
		return $u;
	}

	/**
	 * Zero the usage counter and start a new period now.
	 */
	public static function reset_usage(): void {
		update_option(
			self::USAGE_OPTION,
			array(
				'chars'    => 0,
				'requests' => 0,
				'since'    => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);
	}

	/**
	 * UTC timestamp of the most recent reset-day boundary on or before today.
	 */
	private static function cycle_start( int $day ): int {
		$y = (int) gmdate( 'Y' );
		$m = (int) gmdate( 'n' );
		// The effective day this month: a shorter month uses its last day, so a
		// reset day of 31 still fires on 30 April / 28-29 February.
		$eff_now = min( $day, (int) gmdate( 't' ) );
		if ( (int) gmdate( 'j' ) < $eff_now ) {
			--$m;
			if ( $m < 1 ) {
				$m = 12;
				--$y;
			}
		}
		$dim = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) ); // days in that month.
		return gmmktime( 0, 0, 0, $m, min( $day, $dim ), $y );
	}

	/**
	 * Whether the current request looks like a crawler/bot.
	 */
	public static function is_bot(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $ua ) {
			return false;
		}
		$needles = array( 'bot', 'crawl', 'spider', 'slurp', 'bingpreview', 'facebookexternalhit', 'embedly', 'quora link', 'pinterest', 'vkshare', 'w3c_validator', 'headlesschrome', 'lighthouse' );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function key(): string {
		return 'trrocket_ai_used_' . gmdate( 'Ymd' );
	}
}
