<?php
/**
 * Simple or Advanced view (1.8.8, 16/10/2026).
 *
 * Federico, after reading why people deactivate («too complicated», most of them on the first
 * day): «what is free, immediate and fast goes at first sight; everything else in the advanced
 * interface». Nothing is removed: the Simple view hides the advanced screens from the menus and the
 * advanced parts of a screen (they stay in the page, so saving in Simple never changes them), and
 * one click shows everything again. Remembered for each user.
 *
 * Who starts where: a site that was already set up before this version starts in Advanced (its
 * owner knows where things are, nothing must disappear); a new install starts in Simple.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The two views.
 */
class View {

	const META    = 'trrocket_view';
	const DEFAULT = 'trrocket_view_default';
	const ACTION  = 'trrocket_view';

	/**
	 * Screens shown in the Simple view (the rest is one click away, under Advanced).
	 */
	const SIMPLE_PAGES = array( 'translate-rocket', 'translate-rocket-strings', 'translate-rocket-switcher' );

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'switch_view' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * The view of the current user: simple|advanced.
	 */
	public static function mode(): string {
		$mine = (string) get_user_meta( get_current_user_id(), self::META, true );
		if ( 'simple' === $mine || 'advanced' === $mine ) {
			return $mine;
		}
		$def = (string) get_option( self::DEFAULT, '' );
		if ( '' === $def ) {
			// Decided once for the site: already set up (languages chosen) = Advanced, new = Simple.
			$def = empty( Settings::get()['target_languages'] ) ? 'simple' : 'advanced';
			add_option( self::DEFAULT, $def, '', false );
		}
		return 'simple' === $def ? 'simple' : 'advanced';
	}

	/**
	 * Whether the Simple view is on.
	 */
	public static function simple(): bool {
		return 'simple' === self::mode();
	}

	/**
	 * Switch, from the buttons at the top of every screen of the plugin.
	 */
	public function switch_view(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'translate-rocket' ) );
		}
		$to = isset( $_GET['to'] ) && 'simple' === sanitize_key( wp_unslash( $_GET['to'] ) ) ? 'simple' : 'advanced';
		update_user_meta( get_current_user_id(), self::META, $to );
		$back = isset( $_GET['back'] ) ? sanitize_key( wp_unslash( $_GET['back'] ) ) : 'translate-rocket';
		// Leaving Advanced from a screen the Simple view hides: back to the first screen.
		if ( 'simple' === $to && ! in_array( $back, self::SIMPLE_PAGES, true ) ) {
			$back = 'translate-rocket';
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . ( 0 === strpos( $back, 'translate-rocket' ) ? $back : 'translate-rocket' ) ) );
		exit;
	}

	/**
	 * «trr-simple» on our screens: the CSS hides «.trr-adv» there.
	 *
	 * @param mixed $classes Body classes.
	 * @return mixed
	 */
	public function body_class( $classes ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_string( $classes ) && 0 === strpos( $page, 'translate-rocket' ) ) {
			$classes .= self::simple() ? ' trr-simple' : ' trr-advanced';
		}
		return $classes;
	}

	/**
	 * The two buttons «Simple | Advanced», at the end of the plugin's navigation.
	 *
	 * @param string $current The open screen.
	 */
	public static function toggle( string $current ): string {
		$simple = self::simple();
		$link   = static function ( string $to ) use ( $current ): string {
			return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&to=' . $to . '&back=' . rawurlencode( $current ) ), self::ACTION );
		};
		return '<span class="trr-view" role="group" aria-label="' . esc_attr__( 'View', 'translate-rocket' ) . '">'
			. '<a class="trr-view-btn' . ( $simple ? ' is-on' : '' ) . '" href="' . esc_url( $link( 'simple' ) ) . '"' . ( $simple ? ' aria-current="true"' : '' ) . '>' . esc_html__( 'Simple', 'translate-rocket' ) . '</a>'
			. '<a class="trr-view-btn' . ( $simple ? '' : ' is-on' ) . '" href="' . esc_url( $link( 'advanced' ) ) . '"' . ( $simple ? '' : ' aria-current="true"' ) . '>' . esc_html__( 'Advanced', 'translate-rocket' ) . '</a>'
			. '</span>';
	}
}
