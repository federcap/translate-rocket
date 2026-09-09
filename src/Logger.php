<?php
/**
 * Lightweight error/event log.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * A small ring buffer of recent events stored in an option, so failures that
 * happen in the background (cron auto-translate, a refused AI call, a missing
 * table) are recorded and shown on the Diagnostics page instead of vanishing.
 *
 * Kept deliberately tiny: capped entries, autoload off, errors mirrored to the
 * PHP error log only when WP_DEBUG is on.
 */
class Logger {

	const OPTION = 'trrocket_log';
	const SEEN   = 'trrocket_log_seen';
	const MAX    = 60;

	/**
	 * Record an event.
	 *
	 * @param string $level   error|warning|info.
	 * @param string $context Short area tag (ai, cron, db, import, cache…).
	 * @param string $message Human message.
	 * @param string $detail  Optional extra detail (kept short).
	 */
	public static function log( string $level, string $context, string $message, string $detail = '' ): void {
		$message = trim( wp_strip_all_tags( $message ) );
		if ( '' === $message ) {
			return;
		}
		$entry = array(
			't'      => time(),
			'level'  => in_array( $level, array( 'error', 'warning', 'info' ), true ) ? $level : 'info',
			'ctx'    => substr( sanitize_key( $context ), 0, 20 ),
			'msg'    => self::clip( $message, 300 ),
			'detail' => self::clip( trim( wp_strip_all_tags( $detail ) ), 500 ),
		);

		$log = get_option( self::OPTION );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		// Collapse identical bursts (same context + message within 10s) so a
		// looping failure doesn't flood the buffer.
		$last = end( $log );
		if ( $last && $last['msg'] === $entry['msg'] && $last['ctx'] === $entry['ctx'] && ( $entry['t'] - (int) $last['t'] ) < 10 ) {
			return;
		}

		$log[] = $entry;
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( self::OPTION, $log, false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && 'error' === $entry['level'] ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG.
			error_log( 'TranslateRocket [' . $entry['ctx'] . '] ' . $entry['msg'] . ( '' !== $entry['detail'] ? ' — ' . $entry['detail'] : '' ) );
		}
	}

	public static function error( string $context, string $message, string $detail = '' ): void {
		self::log( 'error', $context, $message, $detail );
	}

	public static function warning( string $context, string $message, string $detail = '' ): void {
		self::log( 'warning', $context, $message, $detail );
	}

	public static function info( string $context, string $message, string $detail = '' ): void {
		self::log( 'info', $context, $message, $detail );
	}

	/**
	 * All events, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$log = get_option( self::OPTION );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	/**
	 * Empty the log and mark everything as seen.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
		update_option( self::SEEN, time(), false );
	}

	/**
	 * Count error/warning entries newer than the last "seen" timestamp.
	 */
	public static function count_unseen(): int {
		$seen = (int) get_option( self::SEEN, 0 );
		$n    = 0;
		foreach ( self::all() as $e ) {
			if ( (int) ( $e['t'] ?? 0 ) > $seen && 'info' !== ( $e['level'] ?? '' ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Mark the current moment as "seen" (clears the unseen badge).
	 */
	public static function mark_seen(): void {
		update_option( self::SEEN, time(), false );
	}

	private static function clip( string $s, int $len ): string {
		return ( function_exists( 'mb_substr' ) ) ? mb_substr( $s, 0, $len ) : substr( $s, 0, $len );
	}
}
