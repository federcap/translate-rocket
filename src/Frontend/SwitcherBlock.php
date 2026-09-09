<?php
/**
 * Gutenberg block (and block-based widget) for the language switcher.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a dynamic block "translaterocket/switcher" rendered by the existing
 * Switcher. Because it's a block, it shows up both in the post editor and in the
 * (block-based) Widgets screen — no shortcode needed.
 */
class SwitcherBlock {

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the editor script and the block.
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'trrocket-switcher-block',
			TRROCKET_URL . 'assets/js/switcher-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			Plugin::asset_ver( 'assets/js/switcher-block.js' ),
			true
		);

		register_block_type(
			'translaterocket/switcher',
			array(
				'api_version'     => 3,
				'editor_script'   => 'trrocket-switcher-block',
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'type'    => array(
						'type'    => 'string',
						'default' => 'inline',
					),
					'show'    => array(
						'type'    => 'string',
						'default' => 'both',
					),
					'current' => array(
						'type'    => 'string',
						'default' => 'show',
					),
				),
			)
		);
	}

	/**
	 * Server-side render: delegate to the shortcode switcher.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public function render( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		return ( new Switcher() )->render(
			array(
				'type'    => isset( $attributes['type'] ) ? (string) $attributes['type'] : 'inline',
				'show'    => isset( $attributes['show'] ) ? (string) $attributes['show'] : 'both',
				'current' => isset( $attributes['current'] ) ? (string) $attributes['current'] : 'show',
			)
		);
	}
}
