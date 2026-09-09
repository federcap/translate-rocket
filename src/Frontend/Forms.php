<?php
/**
 * Form plugins glue.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Strings;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Form field labels/placeholders/buttons are server-rendered and already handled
 * by the page engine. Their validation/notice messages, though, are inserted by
 * JavaScript after the page loads, so the engine can't reach them. On a translated
 * page that contains a form, this ships a tiny translator that swaps the text in
 * the known message containers (only those — never the inputs) using the same map.
 */
class Forms {

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
	}

	/**
	 * Enqueue the message translator only on a translated, single page that
	 * actually embeds a known form.
	 */
	public function maybe_enqueue(): void {
		if ( empty( Settings::get()['translate_interface'] ) ) {
			return;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( $router->is_default( $lang ) || ! $this->page_has_form() ) {
			return;
		}

		// Validation messages are short — ship only short map entries to keep the
		// payload tiny. On huge sites the whole-language map can't be loaded at
		// all; validation messages are interface (gettext) strings, so the
		// bounded interface map covers them.
		$source = Strings::is_huge()
			? Strings::map_for_page( Strings::url_hash( Gettext::INTERFACE_URL ), $lang )
			: Strings::map_for_language( $lang );
		$small  = array();
		foreach ( $source as $orig => $trans ) {
			if ( strlen( $orig ) <= 120 ) {
				$small[ $orig ] = $trans;
			}
		}
		if ( empty( $small ) ) {
			return;
		}

		$handle = 'trrocket-form-messages';
		wp_register_script(
			$handle,
			TRROCKET_URL . 'assets/js/form-messages.js',
			array(),
			Plugin::asset_ver( 'assets/js/form-messages.js' ),
			true
		);
		wp_localize_script( $handle, 'trrocketForms', array( 'map' => $small ) );
		wp_enqueue_script( $handle );
	}

	/**
	 * Whether the current single page embeds a known form (shortcode or block).
	 */
	private function page_has_form(): bool {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		$c = (string) $post->post_content;

		foreach ( array( 'sureforms', 'contact-form-7', 'wpforms', 'gravityform', 'forminator_form', 'formidable', 'ninja_form' ) as $sc ) {
			if ( has_shortcode( $c, $sc ) ) {
				return true;
			}
		}
		foreach ( array( 'wp:srfm/', 'wp:sureforms/', 'wp:contact-form-7/', 'wp:gravityforms/', 'wp:formidable/', 'wp:wpforms/' ) as $blk ) {
			if ( false !== strpos( $c, $blk ) ) {
				return true;
			}
		}
		return false;
	}
}
