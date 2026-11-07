<?php
/**
 * Who is translating right now, and «Stop» for all of them (proposta 7/11/2026).
 *
 * Federico (7/11/2026): «nessun pulsante interrompi quando c'è la clessidra… se rifaccio start cosa fa?».
 * Until now each run lived in its own tab: the Stop of the browser panel and of «Translate my site» stopped
 * only that tab, «Translate all» on the server had no Stop at all, and a second tab started a second run
 * (server steps wait on the site lock and take turns; browser runs had no lock and translated the same
 * sentences twice). Here:
 *  - every run started from a screen (server steps, the browser panel, one click) sends a tab id and leaves
 *    a heartbeat in one small option: who, which language, since when;
 *  - a new run while another tab's heartbeat is fresh is refused with «already running elsewhere»;
 *  - «Stop» writes the time of the request: every run started before it stops at its next step, in any
 *    tab, Labs' whole-site job is paused (resumable), and the hourglass goes off at once.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Heartbeats and the stop request.
 */
final class RunGuard {
	const RUNS = 'trrocket_runs';     // tab id => array( kind, lang, start, t )
	const STOP = 'trrocket_stop_at';  // time of the last «Stop»
	const FRESH = 45;                 // a heartbeat older than this is a closed tab
	const QUIET = 600;                // after «Stop», tab-less workers (Labs' browser worker) rest this long

	/**
	 * A tab id as sent by the page (random, per tab), or ''.
	 */
	public static function tab_from_request(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the callers checked their nonce already.
		$t = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		return substr( $t, 0, 32 );
	}

	/**
	 * Fresh heartbeats.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function runs(): array {
		return array_filter(
			self::raw(),
			static function ( $r ) {
				return empty( $r['end'] );
			}
		);
	}

	/**
	 * Fresh heartbeats, the ones just ended too (a run going from one language to the next keeps its start time,
	 * so a «Stop» pressed in between still reaches it).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function all(): array {
		wp_cache_delete( self::RUNS, 'options' );
		$now = time();
		return array_filter(
			(array) get_option( self::RUNS, array() ),
			static function ( $r ) use ( $now ) {
				return is_array( $r ) && $now - (int) ( $r['t'] ?? 0 ) <= 6 * HOUR_IN_SECONDS;
			}
		);
	}

	/**
	 * Fresh heartbeats, the ones just ended too.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function raw(): array {
		wp_cache_delete( self::RUNS, 'options' ); // another PHP process may have written it a second ago
		$all = (array) get_option( self::RUNS, array() );
		$now = time();
		return array_filter(
			$all,
			static function ( $r ) use ( $now ) {
				return is_array( $r ) && $now - (int) ( $r['t'] ?? 0 ) <= self::FRESH;
			}
		);
	}

	/**
	 * Another tab's run, still alive (or null).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function other( string $tab ): ?array {
		foreach ( self::runs() as $id => $r ) {
			if ( (string) $id !== $tab ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * One step of a run: false when it must stop (a «Stop» came after it started).
	 *
	 * @param string $tab   Tab id ('' = a worker without tab: Labs' browser worker, WP-CLI).
	 * @param string $kind  server | browser | oneclick.
	 * @param string $lang  Language.
	 */
	public static function beat( string $tab, string $kind, string $lang, bool $first = false ): bool {
		wp_cache_delete( self::STOP, 'options' );
		$stop = (int) get_option( self::STOP, 0 );
		if ( '' === $tab ) {
			return time() - $stop > self::QUIET;
		}
		// 7/11/2026 (tried on the bench): the start is looked up among all the runs of the last hours, not only the
		// fresh ones — a browser batch of 60 sentences in a background tab can take longer than FRESH, and a run
		// whose start was forgotten looked «started after the Stop» and went on. Only a new start («first») resets it.
		$runs  = self::all();
		// fresh start ( «first» ) only when this tab's last run had ended or was stopped: «Translate my site» goes from one
		// language to the next with a new «first» each time, and a Stop pressed meanwhile must still reach it.
		$start = isset( $runs[ $tab ]['start'] ) && ! ( $first && ! empty( $runs[ $tab ]['end'] ) ) ? (int) $runs[ $tab ]['start'] : time();
		if ( $stop >= $start ) {
			unset( $runs[ $tab ] );
			update_option( self::RUNS, $runs, false );
			return false;
		}
		$runs[ $tab ] = array( 'kind' => $kind, 'lang' => $lang, 'start' => $start, 't' => time() );
		update_option( self::RUNS, $runs, false );
		return true;
	}

	/**
	 * A run ended by itself: its heartbeat goes.
	 */
	public static function done( string $tab ): void {
		if ( '' === $tab ) {
			return;
		}
		$runs = self::all(); // all: the others' starts must survive this write
		if ( isset( $runs[ $tab ] ) ) {
			$runs[ $tab ]['end'] = time(); // no hourglass, no «already running»; its start stays for 45 s
			$runs[ $tab ]['t']   = time();
			update_option( self::RUNS, $runs, false );
		}
	}

	/**
	 * «Stop»: every run, every tab, Labs' job paused; the hourglass off now.
	 */
	public static function stop_all(): void {
		update_option( self::STOP, time(), false );
		// not deleted: each run keeps its start, so its next step sees the Stop came after it (7/11/2026)
		$runs = self::all();
		foreach ( $runs as $id => $r ) {
			$runs[ $id ]['end'] = time();
		}
		update_option( self::RUNS, $runs, false );
		do_action( 'trrocket_auto_pause', true ); // Labs 0.9.10+: Automation::pause() — «Resume» goes on from there
		delete_option( 'trrocket_now_browser' );
		$mosse = (array) get_option( 'trrocket_auto_moved', array() );
		foreach ( $mosse as $code => $m ) {
			if ( is_array( $m ) ) {
				$mosse[ $code ][1] = 0;
			}
		}
		update_option( 'trrocket_auto_moved', $mosse, false );
		delete_transient( 'trrocket_auto_live' );
	}
}
