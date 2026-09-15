<?php
/**
 * Public PHP functions for developers.
 *
 * Small, stable helpers for theme and plugin code that needs to know the
 * language of the current request (the same idea as Polylang's
 * pll_current_language()). They are safe to call at any time: before
 * TranslateRocket has read the address of the request (earlier than
 * 'plugins_loaded') they return the site's source language.
 *
 * Example:
 *
 *     if ( function_exists( 'trrocket_current_language' ) && 'fr' === trrocket_current_language() ) {
 *         // French-only logic.
 *     }
 *
 * @package TranslateRocket
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'trrocket_current_language' ) ) {
	/**
	 * Language code of the current request, e.g. "en", "fr", "pt-br".
	 *
	 * On a translated address (/fr/…) this is that language; on the source
	 * addresses it is the source language. In front-end AJAX requests (forms,
	 * carts) it is the language of the page that sent the request. While
	 * TranslateRocket runs side by side with another translation plugin, it is
	 * the source language except in an administrator's ?trr-preview= page.
	 *
	 * @return string Lower-case language code ('' only if the plugin is not set up).
	 */
	function trrocket_current_language() {
		try {
			if ( ! class_exists( '\TranslateRocket\Plugin' ) ) {
				return '';
			}
			// Before the router has read the address (earlier than plugins_loaded),
			// current_language() is the source language by itself.
			return \TranslateRocket\Plugin::instance()->router()->current_language();
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}

if ( ! function_exists( 'trrocket_default_language' ) ) {
	/**
	 * Language code of the site's source language (the untranslated addresses).
	 *
	 * @return string Lower-case language code ('' only if the plugin is not set up).
	 */
	function trrocket_default_language() {
		try {
			if ( ! class_exists( '\TranslateRocket\Plugin' ) ) {
				return '';
			}
			return \TranslateRocket\Plugin::instance()->router()->default_language();
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
