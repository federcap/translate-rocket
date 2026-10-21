<?php
/**
 * A value that must last until it expires, whatever the cache does.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * 21/10/2026 (translaterocket.com, Cloudways): with a persistent object cache, transients live only in that cache, and
 * every «purge all» of the host or of a cache plugin (Breeze, WP Rocket, LiteSpeed…) throws them away. A state that has
 * to last — DeepL resting for a week — vanished at the next purge and DeepL was asked again on every page. Transients
 * stay for caches (anything that can be rebuilt); what must last goes here: a small non-autoloaded option with its
 * expiry, which a cache purge does not touch.
 */
final class Persistent {

	const PREFIX = 'trrocket_keep_';

	/**
	 * The value, or false when missing or expired (an expired one is removed on the way).
	 *
	 * @param string $key Short key (letters, digits, underscores; an md5 is fine).
	 * @return mixed
	 */
	public static function get( string $key ) {
		$v = get_option( self::PREFIX . $key, null );
		if ( ! is_array( $v ) || ! array_key_exists( 'value', $v ) ) {
			return false;
		}
		if ( (int) ( $v['until'] ?? 0 ) < time() ) {
			delete_option( self::PREFIX . $key );
			return false;
		}
		return $v['value'];
	}

	/**
	 * Keep a value for $seconds.
	 *
	 * @param string $key     Short key.
	 * @param mixed  $value   Value (not false).
	 * @param int    $seconds Lifetime.
	 */
	public static function set( string $key, $value, int $seconds ): void {
		update_option( self::PREFIX . $key, array( 'until' => time() + max( 1, $seconds ), 'value' => $value ), false );
	}

	/**
	 * When the value expires (0 = not there).
	 *
	 * @param string $key Short key.
	 */
	public static function until( string $key ): int {
		$v = get_option( self::PREFIX . $key, null );
		return is_array( $v ) ? (int) ( $v['until'] ?? 0 ) : 0;
	}

	/**
	 * Forget it.
	 *
	 * @param string $key Short key.
	 */
	public static function delete( string $key ): void {
		delete_option( self::PREFIX . $key );
	}
}
