<?php
/**
 * Tell caching / optimization plugins to leave our scripts alone.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Optimization plugins are supposed to make a site faster by combining, deferring
 * or delaying JavaScript. For most scripts that is harmless. For ours it is not:
 *
 *  - the inline snippet in <head> (see Redirect) decides the visitor's language
 *    BEFORE the page paints. "Delay JavaScript until interaction" postpones it to
 *    the first tap, so the visitor reads the wrong language until they touch the
 *    screen — or forever, if they just read and leave;
 *  - the switcher's script opens the dropdown. Delayed, the first click does
 *    nothing and the visitor concludes the language picker is broken.
 *
 * Every plugin below publishes a filter for exactly this, so the fix is to answer
 * those filters rather than to fight them. Each one is a public, documented hook;
 * adding our handles to the list changes nothing for any other script on the page.
 * Reported as "it works on my machine but not in production" — the difference
 * being that optimization is usually off while developing (23/09/2026).
 */
class Optimizers {

	/**
	 * Our script handles and the file names that identify them.
	 *
	 * @var string[]
	 */
	private const NOSTRI = array(
		'trrocket-redirect',
		'trrocket-switcher',
		'trrocket-dynamic',
		'trrocket-dynamic-collect',
		'trrocket-forms',
		'trrocket-form-messages',
		'trrocket-woo-blocks',
		'translate-rocket/assets/js/',
	);

	/**
	 * Register with every optimizer that offers a way to be told.
	 */
	public function boot(): void {
		// Autoptimize, LiteSpeed Cache, WP Rocket, SiteGround Optimizer, WP-Optimize:
		// each takes a list of fragments; a script whose tag contains one is skipped.
		foreach ( array(
			'autoptimize_filter_js_exclude',
			'litespeed_optimize_js_excludes',
			'litespeed_optm_js_defer_exc',
			'litespeed_optm_gm_js_exc',
			'rocket_exclude_js',
			'rocket_minify_excluded_external_js',
			'rocket_delay_js_exclusions',
			'rocket_defer_inline_exclusions',
			'sgo_js_minify_exclude',
			'sgo_javascript_combine_exclude',
			'sgo_javascript_combine_excluded_inline_content',
			'wp-optimize-minify-default-exclusions',
		) as $filtro ) {
			add_filter( $filtro, array( $this, 'aggiungi' ), 20 );
		}

		// W3 Total Cache asks the other way round: it hands over the script tag and
		// expects true/false for "may I minify this one?".
		add_filter( 'w3tc_minify_js_do_tag_minification', array( $this, 'w3tc' ), 20, 2 );
	}

	/**
	 * Add our handles to a list of exclusions, whatever shape that list has.
	 *
	 * Autoptimize hands over a comma-separated string, the others an array; both
	 * are in use across versions, so both are handled.
	 *
	 * @param string|string[] $lista Current exclusions.
	 * @return string|string[] The same shape, with ours added.
	 */
	public function aggiungi( $lista ) {
		if ( is_string( $lista ) ) {
			$pezzi = array_filter( array_map( 'trim', explode( ',', $lista ) ) );
			return implode( ',', array_unique( array_merge( $pezzi, self::NOSTRI ) ) );
		}
		if ( is_array( $lista ) ) {
			return array_values( array_unique( array_merge( $lista, self::NOSTRI ) ) );
		}
		return $lista;
	}

	/**
	 * W3 Total Cache: false means "do not minify this script tag".
	 *
	 * @param bool   $do_tag_minification Whether W3TC would minify it.
	 * @param string $script_tag          The full <script> tag.
	 */
	public function w3tc( $do_tag_minification, $script_tag = '' ) {
		foreach ( self::NOSTRI as $nostro ) {
			if ( false !== strpos( (string) $script_tag, $nostro ) ) {
				return false;
			}
		}
		return $do_tag_minification;
	}
}
