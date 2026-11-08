<?php
/**
 * reCAPTCHA / hCaptcha in the page language.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Languages;
use TranslateRocket\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * 8/11/2026 (WPML 5.3: «reCAPTCHA in page language» for Elementor Pro forms): the «I'm not a robot» box and its
 * challenge speak the language Google guesses from the browser, not the page's. Every form plugin (Contact Form 7,
 * WPForms, Elementor Pro, Gravity Forms, Forminator…) loads the same script from Google or hCaptcha: on a translated
 * page its address gets hl=<language>, whoever enqueued it.
 */
class CaptchaLanguage {

	/**
	 * Script addresses that take hl=.
	 */
	const HOSTS = '#^(?:https?:)?//(?:www\.google\.com/recaptcha/|www\.recaptcha\.net/recaptcha/|recaptcha\.google\.com/recaptcha/|(?:js\.)?hcaptcha\.com/1/api\.js)#i';

	/**
	 * Regional codes Google and hCaptcha know; any other language uses its base code (de-LU → de).
	 */
	const REGIONAL = array( 'zh-CN', 'zh-HK', 'zh-TW', 'pt-BR', 'pt-PT', 'de-AT', 'de-CH', 'en-GB', 'fr-CA', 'es-419' );

	public function boot(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_filter( 'script_loader_src', array( $this, 'src' ), 20, 1 );
	}

	/**
	 * @param string|false $src Script address.
	 * @return string|false
	 */
	public function src( $src ) {
		if ( ! is_string( $src ) || '' === $src || ! preg_match( self::HOSTS, $src ) ) {
			return $src;
		}
		$hl = self::hl();
		return '' === $hl ? $src : add_query_arg( 'hl', rawurlencode( $hl ), $src );
	}

	/**
	 * The hl code of the current language, '' in the site's own language (the script is left as it is).
	 */
	public static function hl(): string {
		$router = Plugin::instance()->router();
		$lang   = (string) $router->current_language();
		if ( '' === $lang || $router->is_default( $lang ) ) {
			return '';
		}
		$loc = str_replace( '_', '-', (string) Languages::locale( $lang ) );
		if ( '' === $loc ) {
			$loc = $lang;
		}
		if ( 0 === stripos( $loc, 'zh' ) && ! preg_match( '/-(?:HK|TW)$/i', $loc ) ) {
			$loc = 'zh-CN'; // Chinese without a region: simplified
		}
		foreach ( self::REGIONAL as $r ) {
			if ( 0 === strcasecmp( $r, $loc ) ) {
				return $r;
			}
		}
		$base = strtolower( (string) strtok( $loc, '-' ) );
		return preg_match( '/^[a-z]{2,3}$/', $base ) ? $base : '';
	}
}
