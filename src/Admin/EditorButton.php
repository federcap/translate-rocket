<?php
/**
 * "Translate" launcher in the block (Gutenberg) editor.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Plugin;
use TranslateRocket\Languages;
use TranslateRocket\Copies;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a TranslateRocket sidebar (a 🚀 icon in the editor header, pinnable) when
 * editing a public post in a multilingual site, with one-click links that open the
 * front-end visual editor for that page in each target language.
 */
class EditorButton {

	/**
	 * Hook into the block editor.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the editor plugin and hand it this page's per-language editor URLs.
	 */
	public function enqueue(): void {
		$post = get_post();
		if ( ! ( $post instanceof \WP_Post ) ) {
			return;
		}
		// Public post types only; never on our own copy posts (edited natively).
		$public = get_post_types( array( 'public' => true ), 'names' );
		if ( ! isset( $public[ $post->post_type ] ) || Copies::is_copy( (int) $post->ID ) ) {
			return;
		}
		$router    = Plugin::instance()->router();
		$secondary = $router->secondary_languages();
		if ( empty( $secondary ) ) {
			return;
		}

		wp_enqueue_script(
			'trrocket-editor-plugin',
			TRROCKET_URL . 'assets/js/editor-plugin.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			Plugin::asset_ver( 'assets/js/editor-plugin.js' ),
			true
		);

		// Build the front-end visual-editor URL for this page in each language. The
		// source slug resolves fine under a /xx/ prefix, so we don't need the
		// translated slug here.
		$home = rtrim( (string) home_url( '/' ), '/' );
		$path = (string) wp_parse_url( (string) get_permalink( $post->ID ), PHP_URL_PATH );
		$langs = array();
		foreach ( $secondary as $code ) {
			$langs[] = array(
				'code'  => $code,
				'label' => Languages::label( $code ),
				'flag'  => Languages::flag( $code ),
				'url'   => add_query_arg( 'trr-edit', '1', $home . '/' . $code . $path ),
			);
		}

		wp_localize_script(
			'trrocket-editor-plugin',
			'trrocketEditor',
			array(
				'langs' => $langs,
				'i18n'  => array(
					'title'       => __( 'TranslateRocket', 'translate-rocket' ),
					'desc'        => __( 'Open the on-page visual editor for this page in a language:', 'translate-rocket' ),
					/* translators: %s: language name. */
					'translateIn' => __( 'Translate in %s', 'translate-rocket' ),
					'opens'       => __( 'Opens in a new tab.', 'translate-rocket' ),
				),
			)
		);
	}
}
