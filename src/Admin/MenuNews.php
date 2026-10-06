<?php
/**
 * One notice, once: «your languages can now sit in your theme's menu».
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * 6/10/2026 (Federico): the sites that asked for Labs all had the switcher floating in a corner, because
 * there was nowhere else to put it in two clicks. 1.7.4 can put it in the theme's header menu: whoever
 * still has the floating one is told once, on TranslateRocket's own screens, and one click moves it.
 */
class MenuNews {

	const META   = 'trrocket_menu_news';
	const ACTION = 'trrocket_menu_news';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Does this site qualify: the floating switcher, not yet answered, and a menu to go to?
	 */
	private static function wanted(): bool {
		if ( ! current_user_can( 'manage_options' ) || get_user_meta( get_current_user_id(), self::META, true ) ) {
			return false;
		}
		$sw = (array) ( Settings::get()['switcher'] ?? array() );
		if ( empty( $sw['floating'] ) || ! empty( $sw['in_menu'] ) || ! empty( $sw['in_spot'] ) ) {
			return false;
		}
		return array() !== Admin::switcher_menu_choices();
	}

	/**
	 * The notice (TranslateRocket's screens only; the wizard has its own question).
	 */
	public function notice(): void {
		if ( ! NoticeTidy::ours() || ! self::wanted() ) {
			return;
		}
		$base = admin_url( 'admin-post.php?action=' . self::ACTION );
		$si   = wp_nonce_url( add_query_arg( 'do', 'menu', $base ), self::ACTION );
		$no   = wp_nonce_url( add_query_arg( 'do', 'no', $base ), self::ACTION );
		echo '<div class="notice notice-info" data-trrocket="menu-news"><p>';
		echo '<strong>' . esc_html__( 'New:', 'translate-rocket' ) . '</strong> ';
		echo esc_html__( 'your languages can sit in your theme\'s header menu, drawn like its other items — on phones too — instead of floating in a corner.', 'translate-rocket' );
		echo '</p><p>';
		echo '<a class="button button-primary" href="' . esc_url( $si ) . '">' . esc_html__( 'Put them in my menu', 'translate-rocket' ) . '</a> ';
		echo '<a class="button-link" href="' . esc_url( $no ) . '">' . esc_html__( 'Keep the floating switcher', 'translate-rocket' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * The two buttons.
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'translate-rocket' ) );
		}
		update_user_meta( get_current_user_id(), self::META, 1 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		if ( 'menu' === $do ) {
			$s = Settings::get();
			$s['switcher'] = array_merge(
				(array) $s['switcher'],
				array(
					'in_menu'       => true,
					'floating'      => false,
					'in_spot'       => false,
					'menu_location' => 'auto',
					'menu_pos'      => 'end',
				)
			);
			Settings::update( $s );
			\TranslateRocket\Cache::flush();
			wp_safe_redirect( admin_url( 'admin.php?page=translate-rocket-switcher&menu_moved=1' ) );
			exit;
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=translate-rocket' ) );
		exit;
	}
}
