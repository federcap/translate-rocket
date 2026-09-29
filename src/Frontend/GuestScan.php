<?php
/**
 * Reading a page the way a visitor who is not logged in sees it.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Text is collected while an administrator looks at a page. But an
 * administrator is logged in, and a logged-in reader is shown a different page:
 * no login or registration form (Ultimate Member, bbPress, WooCommerce «My
 * account»), no «You must be logged in to reply», no e-mail field on the
 * review form, no guest-checkout note. Those words were never collected, so on
 * /it/ every visitor saw them in the source language — exactly the visitors
 * they were written for (found with the plugin probes, 29/09/2026).
 *
 * So after an administrator has read a page, the site reads the same page
 * once more by itself, as a guest (a server-side request with no cookies), with
 * a signed, short-lived, page-bound token that lets that one request collect.
 * Once a day per page, in the background: the administrator never waits.
 */
class GuestScan {

	/**
	 * Query parameter carrying the token.
	 */
	const PARAM = 'trr_guest';

	/**
	 * Whether this request is the site reading a page as a guest.
	 *
	 * @var bool|null
	 */
	private static $active = null;

	/**
	 * The token for a path, valid this hour and the next.
	 *
	 * @param string $path Clean path of the page ('/rooms/').
	 * @param int    $hour Hour bucket (default: now).
	 */
	private static function token( string $path, int $hour = 0 ): string {
		$hour = $hour > 0 ? $hour : (int) floor( time() / HOUR_IN_SECONDS );
		return substr( wp_hash( 'trrocket_guest|' . $path . '|' . $hour, 'nonce' ), 0, 20 );
	}

	/**
	 * Is this the site's own guest read of the current page?
	 */
	public static function active(): bool {
		if ( null !== self::$active ) {
			return self::$active;
		}
		self::$active = false;
		if ( empty( $_GET[ self::PARAM ] ) || is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification -- the value is itself a signed token.
			return false;
		}
		$given = (string) sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$path  = Plugin::instance()->router()->current_clean_path();
		$hour  = (int) floor( time() / HOUR_IN_SECONDS );
		foreach ( array( $hour, $hour - 1 ) as $h ) {
			if ( hash_equals( self::token( $path, $h ), $given ) ) {
				self::$active = true;
				break;
			}
		}
		if ( self::$active && function_exists( 'nocache_headers' ) ) {
			nocache_headers(); // Never let a host cache keep this variant.
		}
		return self::$active;
	}

	/**
	 * Book the guest read of a page an administrator has just read.
	 *
	 * @param string $path Clean path of the page.
	 */
	public static function schedule( string $path ): void {
		if ( '' === $path || self::active() || ! is_user_logged_in() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		/**
		 * Whether the site should read a page as a guest after an administrator read it.
		 *
		 * @param bool   $do   Default true.
		 * @param string $path The page.
		 */
		if ( ! apply_filters( 'trrocket_guest_scan', true, $path ) ) {
			return;
		}
		$key = 'trrocket_gs_' . md5( $path );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		$url = add_query_arg( self::PARAM, self::token( $path ), home_url( $path ) );
		wp_remote_get(
			$url,
			array(
				'timeout'    => 0.01,
				'blocking'   => false,
				'sslverify'  => false,
				'cookies'    => array(),
				'user-agent' => 'TranslateRocket guest scan',
			)
		);
	}
}
