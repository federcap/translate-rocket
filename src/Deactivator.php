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
	}
}
