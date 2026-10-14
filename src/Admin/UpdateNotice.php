<?php
/**
 * A newer TranslateRocket or Labs, said inside the plugin.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * 14/10/2026 (Federico: «can the plugin's own screens show there is a new version? Labs and plugin»), the day of a
 * security release: the Plugins screen's line is easy to miss. A pill beside the version in the plugin's header,
 * and a notice with «Update now» on its screens, red when the release says it is a security update. Read from
 * WordPress's own update check (no request of ours); only for who can update plugins.
 */
class UpdateNotice {

	public function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * The pending updates of TranslateRocket and Labs.
	 *
	 * @return array<int,array{name:string,version:string,security:bool,url:string,labs:bool}>
	 */
	public static function pending(): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return array();
		}
		$t   = get_site_transient( 'update_plugins' );
		$out = array();
		$noi = array( plugin_basename( TRROCKET_FILE ) => array( 'TranslateRocket', TRROCKET_VERSION, false ) );
		if ( defined( 'TRRLABS_FILE' ) && defined( 'TRRLABS_VERSION' ) ) {
			$noi[ plugin_basename( TRRLABS_FILE ) ] = array( 'TranslateRocket Labs', TRRLABS_VERSION, true );
		}
		foreach ( $noi as $file => $p ) {
			$r = is_object( $t ) && isset( $t->response[ $file ] ) ? $t->response[ $file ] : null;
			if ( ! is_object( $r ) || empty( $r->new_version ) || ! version_compare( (string) $r->new_version, $p[1], '>' ) ) {
				continue;
			}
			// wordpress.org sends the readme's «Upgrade Notice» of the new version; Labs keeps its release notes.
			$note = (string) ( $r->upgrade_notice ?? '' );
			if ( $p[2] ) {
				$info = get_site_transient( 'trrlabs_update_info' );
				$note .= ' ' . ( is_array( $info ) ? (string) ( $info['changelog'] ?? '' ) : '' );
			}
			$out[] = array(
				'name'     => $p[0],
				'version'  => (string) $r->new_version,
				'security' => false !== stripos( $note, 'security' ),
				'url'      => wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file ),
				'labs'     => $p[2],
			);
		}
		return $out;
	}

	/**
	 * The pill beside the version in the plugin's header.
	 */
	public static function pill(): string {
		$out = '';
		foreach ( self::pending() as $u ) {
			$out .= ' <a class="trr-hero-update' . ( $u['security'] ? ' is-security' : '' ) . '" href="' . esc_url( $u['url'] ) . '">'
				. esc_html(
					$u['labs']
						/* translators: %s: version number. */
						? sprintf( __( 'Labs %s available — update', 'translate-rocket' ), $u['version'] )
						/* translators: %s: version number. */
						: sprintf( __( '%s available — update', 'translate-rocket' ), $u['version'] )
				)
				. '</a>';
		}
		return $out;
	}

	/**
	 * The notice on the plugin's screens. Labs 0.9.3 and later say their own; for an older Labs it is said here.
	 */
	public static function notice(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$s = get_current_screen();
		if ( ! $s || false === strpos( (string) $s->id, 'translate-rocket' ) ) {
			return;
		}
		foreach ( self::pending() as $u ) {
			if ( $u['labs'] && is_callable( array( '\TranslateRocketLabs\Updater', 'notice' ) ) ) {
				continue;
			}
			printf(
				'<div class="notice %1$s"><p><strong>%2$s</strong> %3$s <a class="button button-primary" href="%4$s">%5$s</a></p></div>',
				$u['security'] ? 'notice-error' : 'notice-info',
				esc_html(
					$u['security']
						/* translators: 1: plugin name, 2: version number. */
						? sprintf( __( 'Security update: %1$s %2$s.', 'translate-rocket' ), $u['name'], $u['version'] )
						/* translators: 1: plugin name, 2: version number. */
						: sprintf( __( '%1$s %2$s is available.', 'translate-rocket' ), $u['name'], $u['version'] )
				),
				esc_html( $u['security'] ? __( 'Please update now: it fixes a security problem.', 'translate-rocket' ) : __( 'It takes a few seconds; your settings and translations stay as they are.', 'translate-rocket' ) ),
				esc_url( $u['url'] ),
				esc_html__( 'Update now', 'translate-rocket' )
			);
		}
	}
}
