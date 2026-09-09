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
	 * Hook into the front end.
	 */
	public function boot(): void {
		// Front-end pages and front-end-originated AJAX (e.g. WooCommerce cart
		// fragments) should switch the locale; genuine wp-admin requests must not.
		if ( is_admin() && ! \TranslateRocket\Router::is_frontend_ajax() ) {
			return;
		}
		if ( empty( Settings::get()['translate_interface'] ) ) {
			return;
		}
		self::$active = true;
		add_filter( 'determine_locale', array( $this, 'filter' ), 20 );
		add_filter( 'locale', array( $this, 'filter' ), 20 );
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
		$router = Plugin::instance()->router();
		$target = Languages::locale( $router->current_language() );
		return '' !== $target ? $target : $locale;
	}
}
