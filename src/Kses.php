<?php
/**
 * Late-escaping helpers.
 *
 * The plugin builds its own trusted admin/front-end markup (SVG flags, icons,
 * the logo, the switcher, the visual-editor toolbar, hreflang <link>s) where the
 * dynamic values are already escaped at build time. To satisfy "escape late" with
 * real wp_kses() — instead of phpcs:ignore comments — without stripping legitimate
 * SVG elements, form controls or data-* hooks, these allowlists cover exactly the
 * tags/attributes the plugin emits.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

class Kses {

	/**
	 * Escape trusted plugin HTML that may embed inline SVG, form controls and
	 * data-* hooks. Values inside are already escaped where they are built.
	 *
	 * @param string $markup Assembled markup.
	 * @return string
	 */
	public static function html( $markup ) {
		return wp_kses( (string) $markup, self::html_rules() );
	}

	/**
	 * The HTML+SVG allowlist.
	 *
	 * @return array
	 */
	public static function html_rules() {
		$common = array(
			'class'          => true,
			'id'             => true,
			'style'          => true,
			'title'          => true,
			'hidden'         => true,
			'translate'      => true,
			'dir'            => true,
			'role'           => true,
			'tabindex'       => true,
			'aria-hidden'    => true,
			'aria-label'     => true,
			'aria-expanded'  => true,
			'aria-haspopup'  => true,
			'aria-pressed'   => true,
			'aria-controls'  => true,
			'aria-current'   => true,
			// data-* hooks used by the switcher, visual editor and AI table.
			'data-src'       => true,
			'data-preset'    => true,
			'data-text'      => true,
			'data-bg'        => true,
			'data-border'    => true,
			'data-hover'     => true,
			'data-hoverbg'   => true,
			'data-radius'    => true,
			'data-shadow'    => true,
			'data-opacity'   => true,
			'data-anim'      => true,
			'data-hoverfx'   => true,
			'data-lang'      => true,
			'data-native'    => true,
			'data-en'        => true,
			'data-post'      => true,
			'data-source'    => true,
			'data-val'       => true,
			'data-code'      => true,
			'data-ctx'       => true,
			'data-type'      => true,
			'data-key'       => true,
			'data-nonce'     => true,
			'data-attrs'     => true,
		);
		$svg = array(
			'fill'           => true,
			'stroke'         => true,
			'stroke-width'   => true,
			'stroke-linecap' => true,
			'stroke-linejoin'=> true,
			'stroke-dasharray' => true,
			'opacity'        => true,
			'transform'      => true,
			'style'          => true,
			'class'          => true,
		);
		return array(
			'div'      => $common,
			'span'     => $common,
			'p'        => $common,
			'ul'       => $common,
			'ol'       => $common,
			'li'       => $common,
			'strong'   => $common,
			'b'        => $common,
			'em'       => $common,
			'i'        => $common,
			'small'    => $common,
			'code'     => $common,
			'br'       => array(),
			'hr'       => $common,
			'table'    => $common,
			'thead'    => $common,
			'tbody'    => $common,
			'tr'       => $common,
			'th'       => $common,
			'td'       => $common,
			'h1'       => $common,
			'h2'       => $common,
			'h3'       => $common,
			'h4'       => $common,
			'a'        => $common + array( 'href' => true, 'target' => true, 'rel' => true ),
			'button'   => $common + array( 'type' => true, 'disabled' => true, 'value' => true, 'name' => true ),
			'img'      => $common + array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true, 'decoding' => true, 'srcset' => true ),
			'label'    => $common + array( 'for' => true ),
			'input'    => $common + array( 'type' => true, 'name' => true, 'value' => true, 'checked' => true, 'disabled' => true, 'placeholder' => true, 'min' => true, 'max' => true, 'step' => true, 'readonly' => true ),
			'select'   => $common + array( 'name' => true, 'multiple' => true, 'disabled' => true ),
			'option'   => $common + array( 'value' => true, 'selected' => true, 'disabled' => true ),
			'textarea' => $common + array( 'name' => true, 'rows' => true, 'cols' => true, 'placeholder' => true, 'readonly' => true ),
			// Inline SVG (flags, icons, logo). Attribute names are compared lower-case by wp_kses.
			'svg'            => $common + array( 'width' => true, 'height' => true, 'viewbox' => true, 'xmlns' => true, 'xmlns:xlink' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-dasharray' => true, 'focusable' => true, 'preserveaspectratio' => true ),
			'g'              => $svg,
			'path'           => $svg + array( 'd' => true, 'fill-rule' => true, 'clip-rule' => true ),
			'rect'           => $svg + array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true ),
			'circle'         => $svg + array( 'cx' => true, 'cy' => true, 'r' => true ),
			'ellipse'        => $svg + array( 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true ),
			'line'           => $svg + array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ),
			'polyline'       => $svg + array( 'points' => true ),
			'polygon'        => $svg + array( 'points' => true ),
			'defs'           => array(),
			'clippath'       => array( 'id' => true ),
			'lineargradient' => array( 'id' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'gradientunits' => true, 'gradienttransform' => true ),
			'radialgradient' => array( 'id' => true, 'cx' => true, 'cy' => true, 'r' => true, 'fx' => true, 'fy' => true, 'gradientunits' => true ),
			'stop'           => array( 'offset' => true, 'stop-color' => true, 'stop-opacity' => true, 'style' => true ),
			'text'           => $svg + array( 'x' => true, 'y' => true, 'dx' => true, 'dy' => true, 'text-anchor' => true, 'dominant-baseline' => true, 'font-family' => true, 'font-weight' => true, 'font-size' => true, 'letter-spacing' => true ),
			'tspan'          => $svg + array( 'x' => true, 'y' => true, 'dx' => true, 'dy' => true ),
			'use'            => array( 'href' => true, 'xlink:href' => true, 'x' => true, 'y' => true, 'width' => true, 'height' => true ),
		);
	}

	/**
	 * Sanitize an imported translation string. Imported values (a Weglot CSV,
	 * another plugin's tables) are not authored by hand and are later fed to
	 * the gettext layer raw, so they must never carry executable markup. Only
	 * the inline formatting a real translation needs is kept — scripts, form
	 * controls, SVG, iframes, the style attribute and event handlers are all
	 * removed (wp_kses always strips on* handlers and javascript: URLs too).
	 *
	 * @param string $translation Untrusted translation text.
	 * @return string
	 */
	public static function translation( $translation ) {
		return wp_kses( (string) $translation, self::translation_rules() );
	}

	/**
	 * The inline-only allowlist for imported translation content.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function translation_rules() {
		$attrs = array(
			'class'     => true,
			'dir'       => true,
			'translate' => true,
			'lang'      => true,
			'title'     => true,
		);
		return array(
			'a'      => $attrs + array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'span'   => $attrs,
			'strong' => $attrs,
			'b'      => $attrs,
			'em'     => $attrs,
			'i'      => $attrs,
			'u'      => $attrs,
			'small'  => $attrs,
			'sub'    => $attrs,
			'sup'    => $attrs,
			'code'   => $attrs,
			'br'     => array(),
		);
	}

	/**
	 * Escape the <head> hreflang <link> and og:locale <meta> output.
	 *
	 * @param string $markup Assembled <link>/<meta> markup.
	 * @return string
	 */
	public static function head( $markup ) {
		return wp_kses( (string) $markup, self::head_rules() );
	}

	/**
	 * The <head> link/meta allowlist.
	 *
	 * @return array
	 */
	public static function head_rules() {
		return array(
			'link' => array(
				'rel'      => true,
				'hreflang' => true,
				'href'     => true,
			),
			'meta' => array(
				'property' => true,
				'content'  => true,
				'name'     => true,
			),
		);
	}
}
