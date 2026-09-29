<?php
/**
 * Interface language via WordPress' own translations.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Languages;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * On a translated (secondary-language) page, switch the WordPress locale to that
 * language's full locale so WordPress loads its OWN theme/plugin/core language
 * packs — the menus, buttons and plugin labels come out translated for free and
 * officially, instead of being re-translated by AI. The engine still handles the
 * page content (which has no language pack).
 */
class Locale {

	/**
	 * While true, the locale is left alone.
	 *
	 * The plugin's own editor chrome belongs to the person using it, not to the
	 * page being translated: editing the Dutch version must not turn the toolbar
	 * Dutch. VisualEditor raises this flag while it renders its own interface.
	 *
	 * @var bool
	 */
	public static $suspended = false;

	/**
	 * True when this request renders in a language pack's locale rather than the
	 * source one. Everything WordPress prints is then already translated, so it
	 * must not be mistaken for new source text.
	 *
	 * @var bool
	 */
	public static $active = false;

	/**
	 * Whether force() switched the locale and restore() still has to switch back.
	 *
	 * @var bool
	 */
	private static $switched = false;

	/**
	 * Build something in another language than the page's: an e-mail to a customer
	 * who ordered in Italian while the shop manager clicks «Completed» in English,
	 * or the shop's own notification while the customer checks out on /it/.
	 * Always pair with restore().
	 *
	 * @param string $locale Full WordPress locale, e.g. it_IT.
	 */
	public static function force( string $locale ): void {
		self::restore();
		if ( '' !== $locale && function_exists( 'switch_to_locale' ) ) {
			self::$switched = (bool) switch_to_locale( $locale );
		}
	}

	/**
	 * Go back to the language the request had before force().
	 */
	public static function restore(): void {
		if ( self::$switched ) {
			self::$switched = false;
			restore_previous_locale();
		}
	}

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		// Front-end pages and front-end-originated AJAX (e.g. WooCommerce cart
		// fragments) should switch the locale; genuine wp-admin requests must not.
		if ( is_admin() && ! \TranslateRocket\Router::is_frontend_ajax() ) {
			return;
		}
		if ( empty( Settings::get()['translate_interface'] ) ) {
			// The interface keeps the site's language, but the DIRECTION must follow
			// the page's: on /ar/ WordPress and the theme load their -rtl.css only
			// when is_rtl() says so (29/09/2026).
			add_action( 'init', array( $this, 'direction' ), 1 );
			return;
		}
		self::$active = true;
		add_filter( 'determine_locale', array( $this, 'filter' ), 20 );
		add_filter( 'locale', array( $this, 'filter' ), 20 );
	}

	/**
	 * The language of the page a new user signed up on, saved as their locale
	 * (only when they have none): wp_new_user_notification(), retrieve_password()
	 * and WooCommerce's account e-mails switch to the user's locale by themselves.
	 *
	 * @param mixed $user_id New user's id.
	 */
	public static function remember_user_language( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || '' !== (string) get_user_meta( $user_id, 'locale', true ) ) {
			return;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) ) {
			return;
		}
		$locale = Languages::locale( $lang );
		if ( '' !== $locale ) {
			update_user_meta( $user_id, 'locale', $locale );
		}
	}

	/**
	 * Text direction of the page's language, when it differs from the site's.
	 */
	public function direction(): void {
		$router = Plugin::instance()->router();
		if ( empty( $router->secondary_languages() ) || ! isset( $GLOBALS['wp_locale'] ) || ! is_object( $GLOBALS['wp_locale'] ) ) {
			return;
		}
		$rtl = Languages::is_rtl( $router->current_language() );
		if ( $rtl !== is_rtl() ) {
			$GLOBALS['wp_locale']->text_direction = $rtl ? 'rtl' : 'ltr';
		}
		// WP_Styles copies the direction when it is created, and a plugin that
		// registers a style early (WooCommerce) creates it before this runs: its
		// -rtl.css files were chosen with the old direction.
		if ( isset( $GLOBALS['wp_styles'] ) && is_object( $GLOBALS['wp_styles'] ) ) {
			$GLOBALS['wp_styles']->text_direction = $rtl ? 'rtl' : 'ltr';
		}
	}

	/**
	 * Return the current language's full locale, so each language's pages show
	 * WordPress' interface in that language (including the default one — its
	 * pages should match the source language, not whatever the site locale is).
	 *
	 * @param string $locale Locale WordPress determined.
	 */
	public function filter( $locale ) {
		if ( self::$suspended ) {
			return $locale;
		}
		// Someone switched the language on purpose (switch_to_locale(): an e-mail in
		// the customer's language, a plugin rendering for another user): that wins
		// over the page's language, or the switch would silently do nothing here.
		if ( isset( $GLOBALS['wp_locale_switcher'] ) && $GLOBALS['wp_locale_switcher']->is_switched() ) {
			return $locale;
		}
		$router = Plugin::instance()->router();
		// No language to translate into yet (a fresh install): nothing is translated,
		// so the site keeps its own locale. Before this, activating the plugin alone
		// switched every public page to the default source language's locale.
		if ( empty( $router->secondary_languages() ) ) {
			return $locale;
		}
		$target = Languages::locale( $router->current_language() );
		return '' !== $target ? $target : $locale;
	}
}
