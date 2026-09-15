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
 *
 * Whether the page "contains a form" is decided in two steps, so a form placed with
 * a page builder widget, a block, a widget area or a template is found too:
 *  1. early, from the post content (shortcodes, blocks) and Elementor's data;
 *  2. late, just before the footer scripts are printed, from what actually rendered
 *     on this request — a form plugin's render hook fired, or its front-end script
 *     (one that is only enqueued when a form is shown) is in the queue.
 */
class Forms {

	/**
	 * Form shortcodes.
	 */
	const SHORTCODES = array( 'sureforms', 'contact-form-7', 'contact-form', 'wpforms', 'gravityform', 'gravityforms', 'forminator_form', 'formidable', 'ninja_form', 'ninja_forms' );

	/**
	 * Block name prefixes (Forminator's block is forminator/forms).
	 */
	const BLOCK_PREFIXES = array( 'srfm/', 'sureforms/', 'contact-form-7/', 'gravityforms/', 'formidable/', 'wpforms/', 'forminator/', 'ninja-forms/' );

	/**
	 * Front-end scripts the form plugins enqueue only when a form is rendered.
	 * (Contact Form 7 and WPForms can load theirs on every page, so they are
	 * detected by their render hooks instead.)
	 */
	const HANDLES = array(
		'forminator-front-scripts', // Forminator.
		'srfm-form-submit',         // SureForms.
		'gform_gravityforms',       // Gravity Forms.
		'formidable',               // Formidable Forms.
		'nf-front-end',             // Ninja Forms.
	);

	/**
	 * Elementor widget types that show a form: the plugins' own widgets and their
	 * WordPress widgets placed through Elementor ("wp-widget-" + widget id).
	 */
	const ELEMENTOR_WIDGETS = '#"widgetType":"(?:wpforms|formidable|sureforms_form|wp-widget-(?:forminator_widget|wpforms-widget|gform_widget|frm_show_form|ninja_forms_widget))"#';

	/**
	 * A form plugin rendered a form on this request.
	 *
	 * @var bool
	 */
	private $seen = false;

	/**
	 * The message translator is already enqueued.
	 *
	 * @var bool
	 */
	private $enqueued = false;

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		if ( is_admin() ) {
			return;
		}
		if ( empty( Settings::get()['translate_interface'] ) ) {
			return;
		}
		// Only translated pages ever get the script: on the source language nothing
		// is hooked at all.
		$router = Plugin::instance()->router();
		if ( $router->is_default( $router->current_language() ) ) {
			return;
		}

		// Render signals. Hooked now, not on wp_enqueue_scripts: block themes render
		// the whole template (and its forms) before wp_head.
		add_filter( 'do_shortcode_tag', array( $this, 'seen_shortcode' ), 10, 2 );
		add_filter( 'render_block', array( $this, 'seen_block' ), 10, 2 );
		foreach ( array( 'forminator_render_form_markup', 'forminator_render_form_placeholder_markup', 'wpcf7_form_elements', 'gform_get_form_filter', 'frm_filter_final_form', 'ninja_forms_display_before_form' ) as $filter ) {
			add_filter( $filter, array( $this, 'seen_passthrough' ), 1 );
		}
		add_action( 'wpforms_frontend_output_before', array( $this, 'mark_seen' ), 1, 0 );

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
		// Runs after every wp_footer callback before priority 20 (WPForms enqueues at
		// 15, Formidable at 1) and before the footer queue is printed (priority 10).
		add_action( 'wp_print_footer_scripts', array( $this, 'maybe_enqueue_late' ), 5 );
	}

	/**
	 * Early decision: the post content or Elementor data embeds a known form.
	 */
	public function maybe_enqueue(): void {
		if ( $this->content_has_form() ) {
			$this->enqueue();
		}
	}

	/**
	 * Late decision: a form actually rendered on this request.
	 */
	public function maybe_enqueue_late(): void {
		if ( $this->enqueued ) {
			return;
		}
		if ( $this->seen || $this->form_script_queued() ) {
			$this->enqueue();
		}
	}

	/**
	 * A form shortcode ran (post content, Elementor's Shortcode widget, widgets…).
	 *
	 * @param mixed  $output Shortcode output.
	 * @param string $tag    Shortcode tag.
	 * @return mixed
	 */
	public function seen_shortcode( $output, $tag ) {
		if ( in_array( (string) $tag, self::SHORTCODES, true ) ) {
			$this->seen = true;
		}
		return $output;
	}

	/**
	 * A form block rendered.
	 *
	 * @param mixed $content Block HTML.
	 * @param mixed $block   Parsed block.
	 * @return mixed
	 */
	public function seen_block( $content, $block ) {
		if ( ! $this->seen && is_array( $block ) && ! empty( $block['blockName'] ) ) {
			foreach ( self::BLOCK_PREFIXES as $prefix ) {
				if ( 0 === strpos( (string) $block['blockName'], $prefix ) ) {
					$this->seen = true;
					break;
				}
			}
		}
		return $content;
	}

	/**
	 * A form plugin's render filter fired; the value passes through untouched.
	 *
	 * @param mixed $value Filtered value.
	 * @return mixed
	 */
	public function seen_passthrough( $value ) {
		$this->seen = true;
		return $value;
	}

	/**
	 * A form plugin's render action fired.
	 */
	public function mark_seen(): void {
		$this->seen = true;
	}

	/**
	 * Enqueue the message translator (once) with the short entries of the map.
	 */
	private function enqueue(): void {
		if ( $this->enqueued ) {
			return;
		}
		$this->enqueued = true;
		$lang           = Plugin::instance()->router()->current_language();

		// Validation messages are short — ship only short map entries to keep the
		// payload tiny. On huge sites the whole-language map can't be loaded at
		// all; validation messages are interface (gettext) strings, so the
		// bounded interface map covers them, plus the form messages collected
		// under their own heading.
		if ( Strings::is_huge() ) {
			$source = array_merge(
				Strings::map_for_page( Strings::url_hash( Gettext::INTERFACE_URL ), $lang ),
				Strings::map_for_page( Strings::url_hash( Forminator::URL ), $lang )
			);
		} else {
			$source = Strings::map_for_language( $lang );
		}
		$small = array();
		foreach ( $source as $orig => $trans ) {
			if ( strlen( (string) $orig ) <= 120 ) {
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
	 * Whether a form plugin's render-only front-end script is queued or printed.
	 */
	private function form_script_queued(): bool {
		foreach ( self::HANDLES as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'done' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current single page embeds a known form in its content
	 * (shortcode or block) or in its Elementor layout.
	 */
	private function content_has_form(): bool {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		$c = (string) $post->post_content;

		foreach ( self::SHORTCODES as $sc ) {
			if ( has_shortcode( $c, $sc ) ) {
				return true;
			}
		}
		foreach ( self::BLOCK_PREFIXES as $prefix ) {
			if ( false !== strpos( $c, '<!-- wp:' . $prefix ) ) {
				return true;
			}
		}

		// Elementor keeps the layout in post meta (JSON), not in post_content.
		$data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $data ) && '' !== $data ) {
			if ( preg_match( self::ELEMENTOR_WIDGETS, $data ) ) {
				return true;
			}
			foreach ( self::SHORTCODES as $sc ) {
				if ( false !== strpos( $data, '[' . $sc . ' ' ) || false !== strpos( $data, '[' . $sc . ']' ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
