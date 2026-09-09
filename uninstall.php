<?php
/**
 * Uninstall routine.
 *
 * Runs only when the user deletes the plugin from the WordPress admin.
 * Data is removed ONLY if the user explicitly opted in via settings, so a
 * normal delete never destroys translations by accident.
 *
 * @package TranslateRocket
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$trrocket_settings = get_option( 'trrocket_settings' );

if ( is_array( $trrocket_settings ) && ! empty( $trrocket_settings['delete_data_on_uninstall'] ) ) {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-time uninstall cleanup of the plugin's own tables/meta.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}trrocket_translations" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}trrocket_strings" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}trrocket_occurrences" );

	// Per-post meta: translated slugs (_trrocket_slug_*) and visibility rules (_trrocket_vis*).
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_trrocket_' ) . '%' ) );

	// Cached translation maps (transients).
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_trrocket_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_trrocket_' ) . '%'
	) );

	// Per-term meta: translated term slugs.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_trrocket_' ) . '%' ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery

	delete_option( 'trrocket_settings' );
	delete_option( 'trrocket_db_version' );
	delete_option( 'trrocket_installed' );
	delete_option( 'trrocket_review_dismissed' );
	delete_option( 'trrocket_welcome_dismissed' );
	delete_option( 'trrocket_donate_dismissed' );
	delete_option( 'trrocket_ai_usage' );
	delete_option( 'trrocket_savings' );
}
