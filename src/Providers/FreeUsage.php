<?php
/**
 * Daily counters of the free AI engines (free share of the owner's own account).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Cloudflare Workers AI gives 10,000 «neurons» a day, Groq about 1,000 requests, OpenRouter's
 * free models about 50 requests. The services reset at midnight UTC. The counter is read from
 * the answers themselves; a little before the share is gone the engine steps aside for the day,
 * so the next provider of the fallback chain carries on without waiting for a refusal.
 */
final class FreeUsage {

	/** Provider id => the day's free share and its unit. */
	const LIMITS = array(
		'cloudflare' => array( 10000, 'neurons' ),
		'groq'       => array( 1000, 'requests' ),
		'openrouter' => array( 50, 'requests' ),
	);

	const STOP_AT = 0.95;

	private static function key( string $id ): string {
		return 'trrocket_uso_' . sanitize_key( $id );
	}

	/**
	 * Today's counter.
	 *
	 * @return array{day:string,requests:int,units:float,sentences:int}
	 */
	public static function today( string $id ): array {
		$u = get_option( self::key( $id ) );
		$u = is_array( $u ) ? $u : array();
		if ( (string) ( $u['day'] ?? '' ) !== gmdate( 'Ymd' ) ) {
			return array( 'day' => gmdate( 'Ymd' ), 'requests' => 0, 'units' => 0.0, 'sentences' => 0 );
		}
		return array( 'day' => (string) $u['day'], 'requests' => (int) $u['requests'], 'units' => (float) $u['units'], 'sentences' => (int) $u['sentences'] );
	}

	public static function add( string $id, float $units, int $sentences ): void {
		$u = self::today( $id );
		++$u['requests'];
		$u['units']     += max( 0, $units );
		$u['sentences'] += max( 0, $sentences );
		update_option( self::key( $id ), $u, false );
	}

	/**
	 * Sentences translated (the request itself was counted by add()).
	 */
	public static function sentences( string $id, int $n ): void {
		$u               = self::today( $id );
		$u['sentences'] += max( 0, $n );
		update_option( self::key( $id ), $u, false );
	}

	public static function limit( string $id ): int {
		return (int) apply_filters( 'trrocket_free_daily_limit', (int) ( self::LIMITS[ $id ][0] ?? 0 ), $id );
	}

	public static function used( string $id ): float {
		$u = self::today( $id );
		return 'neurons' === ( self::LIMITS[ $id ][1] ?? '' ) ? $u['units'] : (float) $u['requests'];
	}

	public static function share( string $id ): float {
		$l = self::limit( $id );
		return $l > 0 ? min( 1, self::used( $id ) / $l ) : 0;
	}

	public static function exhausted( string $id ): bool {
		return self::limit( $id ) > 0 && self::share( $id ) >= self::STOP_AT;
	}

	/**
	 * «1,240 of 10,000 neurons today (230 sentences)».
	 */
	public static function line( string $id ): string {
		$u = self::today( $id );
		if ( 'neurons' === ( self::LIMITS[ $id ][1] ?? '' ) ) {
			/* translators: 1: neurons used, 2: neurons a day, 3: sentences */
			$r = sprintf( __( '%1$s of %2$s free neurons today (%3$s sentences)', 'translate-rocket' ), number_format_i18n( self::used( $id ) ), number_format_i18n( self::limit( $id ) ), number_format_i18n( $u['sentences'] ) );
		} else {
			/* translators: 1: requests used, 2: requests a day, 3: sentences */
			$r = sprintf( __( '%1$s of %2$s free requests today (%3$s sentences)', 'translate-rocket' ), number_format_i18n( self::used( $id ) ), number_format_i18n( self::limit( $id ) ), number_format_i18n( $u['sentences'] ) );
		}
		if ( self::exhausted( $id ) ) {
			$r .= ' · ' . __( 'today\'s free share is used: the next provider in your chain carries on until tomorrow', 'translate-rocket' );
		}
		return $r;
	}
}
