<?php
/**
 * Runs on plugin activation.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Creates database tables and seeds default options.
 */
class Activator {

	/**
	 * Activation routine.
	 */
	public static function activate(): void {
		Database::create_tables();
		Settings::install_defaults();

		update_option( 'trrocket_db_version', Database::DB_VERSION );

		// Send the admin to the setup wizard on first activation only.
		if ( ! get_option( 'trrocket_wizard_done' ) ) {
			set_transient( 'trrocket_activation_redirect', 1, 60 );
		}

		// Rewrite rules for language routing are registered in a later step;
		// flushing here keeps things clean once they exist.
		flush_rewrite_rules();
	}
}
