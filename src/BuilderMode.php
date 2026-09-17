<?php
/**
 * Page-builder editing requests.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Tells when a front-end request is really a page builder at work.
 *
 * Elementor, Beaver Builder, Divi, Bricks and the others edit a page by loading
 * the real page in a frame, as the logged-in user, and drawing their own tools
 * into it: "Edit Header" handles, "Drag widget here" areas, structure pickers.
 * To the engine that looks like any page an administrator opens, so their
 * interface text ended up among the site's strings. On these requests the page
 * is left exactly as the builder expects it: nothing collected, nothing
 * translated, no switcher on top of the editor.
 */
class BuilderMode {

	/**
	 * Query arguments each builder adds to the page it edits or previews.
	 * A value of true means "present with any value".
	 *
	 * @var array<string, true|string>
	 */
	const MARKERS = array(
		'elementor-preview'             => true,        // Elementor.
		'fl_builder'                    => true,        // Beaver Builder.
		'et_fb'                         => true,        // Divi.
		'bricks'                        => 'run',       // Bricks.
		'ct_builder'                    => true,        // Oxygen.
		'oxygen_iframe'                 => true,        // Oxygen.
		'breakdance'                    => 'builder',   // Breakdance.
		'breakdance_iframe'             => true,        // Breakdance.
		'brizy-edit'                    => true,        // Brizy.
		'brizy-edit-iframe'             => true,        // Brizy.
		'is-editor-iframe'              => true,        // Brizy.
		'vc_editable'                   => true,        // WPBakery.
		'vcv-editable'                  => true,        // Visual Composer.
		'tve'                           => 'true',      // Thrive Architect.
		'so_live_editor'                => true,        // SiteOrigin.
		'siteorigin_panels_live_editor' => true,        // SiteOrigin.
		'zn_pb_edit'                    => true,        // Zion.
		'zionbuilder-preview'           => true,        // Zion.
		'tb-preview'                    => true,        // Themify.
		'fb-edit'                       => true,        // Avada.
		'load_for'                      => 'wppb_editor_iframe', // WP Page Builder.
	);

	/**
	 * Post types that hold builder templates (headers, footers, popups, layouts).
	 * Opened on their own they are a design surface, not a page of the site; their
	 * text is collected where the template is actually used.
	 *
	 * @var string[]
	 */
	const TEMPLATE_TYPES = array(
		'elementor_library',
		'elementor-hf',
		'fl-builder-template',
		'fl-theme-layout',
		'et_pb_layout',
		'et_template',
		'bricks_template',
		'ct_template',
		'breakdance_template',
		'breakdance_header',
		'breakdance_footer',
		'breakdance_popup',
		'brizy_template',
		'templatera',
	);

	/**
	 * Whether this request is a page builder editing or previewing the page.
	 * Only for logged-in users who can edit: a visitor adding ?fl_builder to an
	 * address gets the normal, translated page.
	 */
	public static function active(): bool {
		static $val = null;
		if ( null !== $val ) {
			return $val;
		}
		$val = false;
		if ( is_admin() || ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return $val;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only reads which editor loaded the page; nothing is changed.
		foreach ( self::MARKERS as $arg => $want ) {
			if ( ! isset( $_GET[ $arg ] ) ) {
				continue;
			}
			if ( true === $want || sanitize_key( wp_unslash( (string) $_GET[ $arg ] ) ) === $want ) {
				$val = true;
				break;
			}
		}
		// phpcs:enable
		if ( ! $val && function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			$val = true;
		}
		/**
		 * Whether the current front-end request is a page builder at work.
		 *
		 * @param bool $active True to leave the page untouched: no collecting, no translating.
		 */
		$val = (bool) apply_filters( 'trrocket_builder_request', $val );
		return $val;
	}

	/**
	 * Whether the page being viewed is a builder template opened on its own.
	 */
	public static function template_view(): bool {
		if ( ! function_exists( 'is_singular' ) ) {
			return false;
		}
		/**
		 * Post types of page-builder templates, never collected as pages of their own.
		 *
		 * @param string[] $types Post type names.
		 */
		$types = (array) apply_filters( 'trrocket_builder_template_types', self::TEMPLATE_TYPES );
		return ! empty( $types ) && is_singular( $types );
	}
}
