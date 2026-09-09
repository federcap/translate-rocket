<?php
/**
 * "Language visibility" meta box on the post/page editor.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Settings;
use TranslateRocket\Languages;
use TranslateRocket\Exclusions;

defined( 'ABSPATH' ) || exit;

/**
 * Per-post control to decide, for each target language, whether the page is
 * translated or falls back (homepage / URL / 404). Server-rendered and saved on
 * save_post — independent of the AJAX translation panel.
 */
class VisibilityBox {

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ) );
	}

	/**
	 * Register the meta box on public post types.
	 */
	public function add(): void {
		if ( empty( (array) Settings::get()['target_languages'] ) ) {
			return;
		}
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			add_meta_box(
				'trrocket-visibility',
				__( 'TranslateRocket — Language visibility', 'translate-rocket' ),
				array( $this, 'render' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the control.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render( $post ): void {
		$targets = (array) Settings::get()['target_languages'];
		wp_nonce_field( 'trrocket_visibility', 'trrocket_visibility_nonce' );

		$modes = array(
			''        => __( 'Translate (default)', 'translate-rocket' ),
			'home'    => __( 'Redirect to homepage', 'translate-rocket' ),
			'url'     => __( 'Redirect to a URL', 'translate-rocket' ),
			'message' => __( 'Show a custom message', 'translate-rocket' ),
			'404'     => __( 'Show 404 (not found)', 'translate-rocket' ),
		);

		echo '<p class="description">' . esc_html__( 'What happens when this page is viewed in each language. Use this when a page has no equivalent in another language.', 'translate-rocket' ) . '</p>';

		foreach ( $targets as $lang ) {
			$rule = Exclusions::get( (int) $post->ID, $lang );
			echo '<p style="margin-bottom:4px"><strong>' . esc_html( Languages::flag( $lang ) . ' ' . Languages::label( $lang ) ) . '</strong></p>';

			echo '<select name="trrocket_vis[' . esc_attr( $lang ) . ']" class="widefat">';
			foreach ( $modes as $val => $label ) {
				echo '<option value="' . esc_attr( $val ) . '" ' . selected( $rule['mode'], $val, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';

			printf(
				'<input type="url" class="widefat" style="margin:4px 0 6px" name="trrocket_visurl[%1$s]" value="%2$s" placeholder="%3$s" />',
				esc_attr( $lang ),
				esc_attr( $rule['url'] ),
				esc_attr__( 'https://… (only for “Redirect to a URL”)', 'translate-rocket' )
			);

			printf(
				'<textarea class="widefat" rows="2" style="margin:0 0 12px" name="trrocket_vismsg[%1$s]" placeholder="%2$s">%3$s</textarea>',
				esc_attr( $lang ),
				esc_attr__( 'Custom message (only for “Show a custom message”)', 'translate-rocket' ),
				esc_textarea( $rule['msg'] )
			);
		}
	}

	/**
	 * Persist the control on save.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function save( $post_id ): void {
		if ( ! isset( $_POST['trrocket_visibility_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_visibility_nonce'] ) ), 'trrocket_visibility' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}

		$targets = (array) Settings::get()['target_languages'];
		$modes   = ( isset( $_POST['trrocket_vis'] ) && is_array( $_POST['trrocket_vis'] ) ) ? wp_unslash( $_POST['trrocket_vis'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$urls    = ( isset( $_POST['trrocket_visurl'] ) && is_array( $_POST['trrocket_visurl'] ) ) ? wp_unslash( $_POST['trrocket_visurl'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$msgs    = ( isset( $_POST['trrocket_vismsg'] ) && is_array( $_POST['trrocket_vismsg'] ) ) ? wp_unslash( $_POST['trrocket_vismsg'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		foreach ( $targets as $lang ) {
			$mode = isset( $modes[ $lang ] ) ? sanitize_text_field( $modes[ $lang ] ) : '';
			$url  = isset( $urls[ $lang ] ) ? esc_url_raw( $urls[ $lang ] ) : '';
			$msg  = isset( $msgs[ $lang ] ) ? wp_kses_post( $msgs[ $lang ] ) : '';
			Exclusions::set( (int) $post_id, $lang, $mode, $url, $msg );
		}
	}
}
