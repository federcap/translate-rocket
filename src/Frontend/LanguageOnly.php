<?php
/**
 * Content shown only in some languages.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Show a piece of a page only in some languages, or hide it in some.
 *
 * Two ways in, one rule:
 *
 * - the shortcode, anywhere shortcodes work:
 *   [translaterocket_language lang="it,de"]…[/translaterocket_language]
 *   [translaterocket_language not="it"]…[/translaterocket_language]
 * - an extra CSS class on any block, navigation links included:
 *   trrocket-only-it (shown only in Italian), trrocket-hide-it (hidden in it).
 *
 * Content limited with lang= / trrocket-only-* is taken to be written in the
 * language it is shown in, so it is marked translate="no" and left exactly as
 * written: translating Italian text "into Italian" can only reword it.
 * Content limited with not= / trrocket-hide-* is ordinary page content shown
 * everywhere else, and is translated as usual.
 */
class LanguageOnly {

	const TAG        = 'translaterocket_language';
	const CLASS_ONLY = 'trrocket-only-';
	const CLASS_HIDE = 'trrocket-hide-';

	/**
	 * Register the shortcode and, on the front end, the block rule.
	 */
	public function boot(): void {
		// Shortcode names are case-sensitive: accept the upper-case form too,
		// as the other shortcodes of the plugin do.
		add_shortcode( self::TAG, array( $this, 'shortcode' ) );
		add_shortcode( strtoupper( self::TAG ), array( $this, 'shortcode' ) );

		if ( ! is_admin() ) {
			add_filter( 'render_block', array( $this, 'render_block' ), 10, 2 );
		}
	}

	/**
	 * Turn "it, de pt_BR" into array( 'it', 'de', 'pt-br' ).
	 *
	 * @param string $list Codes separated by commas, spaces or semicolons.
	 * @return string[]
	 */
	public static function codes( string $list ): array {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', strtolower( $list ) ) as $code ) {
			$code = str_replace( '_', '-', preg_replace( '/[^a-z0-9_-]/', '', (string) $code ) );
			if ( '' !== $code ) {
				$out[] = $code;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether the language of this request passes both lists.
	 *
	 * @param string[] $only Languages it may appear in (empty: all).
	 * @param string[] $hide Languages it must not appear in.
	 */
	public static function visible( array $only, array $hide ): bool {
		$current = str_replace( '_', '-', strtolower( Plugin::instance()->router()->current_language() ) );
		if ( ! empty( $only ) && ! in_array( $current, $only, true ) ) {
			return false;
		}
		return ! in_array( $current, $hide, true );
	}

	/**
	 * [translaterocket_language lang="it"]…[/translaterocket_language]
	 *
	 * @param mixed  $atts    Shortcode attributes.
	 * @param string $content Enclosed content.
	 */
	public function shortcode( $atts, $content = '' ): string {
		$atts = shortcode_atts(
			array(
				'lang' => '',
				'not'  => '',
			),
			(array) $atts,
			self::TAG
		);
		$only = self::codes( (string) $atts['lang'] );
		$hide = self::codes( (string) $atts['not'] );

		// Decide first, render after: a hidden contact form must not even load
		// its scripts.
		if ( ! self::visible( $only, $hide ) ) {
			return '';
		}
		$inner = do_shortcode( (string) $content );
		if ( empty( $only ) ) {
			// Nothing to keep as written: only "not=" (or nothing at all) was
			// asked, so this is ordinary content and the engine translates it.
			return $inner;
		}
		// A wrapper that matches the content: a <span> would be invalid around
		// paragraphs, a <div> would break a sentence written inline.
		$tag = preg_match( '/<(p|div|h[1-6]|ul|ol|li|table|figure|section|blockquote|form|img|iframe|video)\b/i', $inner ) ? 'div' : 'span';
		return '<' . $tag . ' class="trrocket-lang-only" translate="no">' . $inner . '</' . $tag . '>';
	}

	/**
	 * The same rule for blocks, read from their "Additional CSS class(es)".
	 *
	 * @param string $html  Rendered block.
	 * @param array  $block Parsed block.
	 */
	public function render_block( $html, $block ) {
		$classes = isset( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '';
		if ( '' === $classes || false === strpos( $classes, 'trrocket-' ) ) {
			return $html;
		}
		$only = array();
		$hide = array();
		foreach ( preg_split( '/\s+/', strtolower( $classes ) ) as $class ) {
			if ( 0 === strpos( $class, self::CLASS_ONLY ) ) {
				$only[] = substr( $class, strlen( self::CLASS_ONLY ) );
			} elseif ( 0 === strpos( $class, self::CLASS_HIDE ) ) {
				$hide[] = substr( $class, strlen( self::CLASS_HIDE ) );
			}
		}
		$only = self::codes( implode( ' ', $only ) );
		$hide = self::codes( implode( ' ', $hide ) );
		if ( empty( $only ) && empty( $hide ) ) {
			return $html;
		}
		if ( ! self::visible( $only, $hide ) ) {
			return '';
		}
		return empty( $only ) ? $html : self::keep_as_written( (string) $html );
	}

	/**
	 * Mark a block's outer element translate="no".
	 *
	 * @param string $html Rendered block.
	 */
	private static function keep_as_written( string $html ): string {
		// WP_HTML_Tag_Processor (WordPress 6.2+) edits the first tag safely; on
		// older versions a wrapper does the same job.
		if ( class_exists( '\WP_HTML_Tag_Processor' ) ) {
			$tags = new \WP_HTML_Tag_Processor( $html );
			if ( $tags->next_tag() ) {
				$tags->set_attribute( 'translate', 'no' );
				return $tags->get_updated_html();
			}
			return $html;
		}
		return '<div translate="no">' . $html . '</div>';
	}
}
