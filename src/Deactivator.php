<?php
/**
 * Runs on plugin deactivation.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Light cleanup on deactivation. Data is preserved (removed only on uninstall,
 * and only if the user opted in).
 */
class Deactivator {

	/**
	 * Deactivation routine.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
		// The search index builds in the background: nothing left scheduled for a plugin that is off.
		wp_clear_scheduled_hook( 'trrocket_search_index' );
		wp_clear_scheduled_hook( 'trrocket_stats_prune' );
	}
}
