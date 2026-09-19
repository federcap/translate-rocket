<?php
/**
 * Main plugin orchestrator.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

use TranslateRocket\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires together the moving parts of the plugin.
 */
final class Plugin {

	/**
	 * Single shared instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin controller (only loaded in wp-admin).
	 *
	 * @var Admin|null
	 */
	private $admin = null;

	/**
	 * Language router.
	 *
	 * @var Router|null
	 */
	private $router = null;

	/**
	 * Get the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Boot the plugin.
	 */
	public function boot(): void {
		// Translations are registered on 'init' in the main plugin file: the
		// automatic loading WordPress does for wordpress.org plugins only reads
		// the language-pack folder, never the catalogues we ship ourselves.

		// Another translation plugin active (WPML, Polylang, TranslatePress…)? Then
		// TranslateRocket runs side by side: the public site stays exactly as that
		// plugin serves it until it is deactivated. See Coexistence.
		$coexist = Coexistence::on();
		Coexistence::boot();

		// Language routing runs on both front end and admin (admin uses helpers).
		// Side by side it reads no language from the address (see Router::boot).
		$this->router = new Router();
		$this->router->boot();

		// Language switcher shortcode [translaterocket_switcher]. Side by side it
		// renders nothing (Preview::hidden), but the shortcode stays registered.
		( new \TranslateRocket\Frontend\Switcher() )->boot();
		// Language switcher Gutenberg block + block-based widget.
		( new \TranslateRocket\Frontend\SwitcherBlock() )->register();

		// Front-end visual editor (also registers its AJAX endpoints on admin).
		( new \TranslateRocket\Frontend\VisualEditor() )->boot();

		if ( ! $coexist ) {
			// Dynamic (JS-injected) content: cookie banners, popups, AJAX-loaded markup.
			// Registers its AJAX endpoint always; enqueues the observer only on the front end.
			( new \TranslateRocket\Frontend\DynamicContent() )->boot();

			// Optional "Powered by TranslateRocket" badge (shortcode + opt-in footer).
			( new \TranslateRocket\Frontend\PoweredBy() )->boot();
		}

		// Content shown only in some languages: the [translaterocket_language]
		// shortcode, and the trrocket-only-xx / trrocket-hide-xx classes on blocks.
		( new \TranslateRocket\Frontend\LanguageOnly() )->boot();
		// Menu items limited to some languages (checkboxes in Appearance > Menus).
		// Side by side the menus are the other plugin's: only the settings screen.
		if ( ! $coexist || is_admin() ) {
			( new \TranslateRocket\MenuLanguages() )->register();
		}

		// Which pages changed after the engine last read them. Booted here and
		// not in the admin branch on purpose: the block editor saves over the
		// REST API, where is_admin() is false — hooking it there would miss
		// exactly the edits people make most.
		\TranslateRocket\Rescan::boot();
		// Same reason for the page cache: a page edited in the block editor is
		// saved over REST, and until 1.4.4 the cached translated page survived
		// the edit (visitors kept seeing the old text for up to six hours).
		add_action( 'save_post', array( 'TranslateRocket\\Cache', 'post_changed' ), 10, 2 );
		add_action( 'deleted_post', array( 'TranslateRocket\\Cache', 'post_changed' ), 10, 2 );
		add_action( 'trashed_post', array( 'TranslateRocket\\Cache', 'post_changed' ) );
		add_action( 'untrashed_post', array( 'TranslateRocket\\Cache', 'post_changed' ) );
		// Text that is on every page and is saved outside the post screens: block
		// widgets (REST), the customizer, menus, products edited through the store API.
		foreach ( array( 'rest_after_save_widget', 'customize_save_after', 'wp_update_nav_menu', 'woocommerce_update_product', 'woocommerce_new_product', 'switch_theme' ) as $gancio ) {
			add_action( $gancio, array( 'TranslateRocket\\Cache', 'content_changed' ) );
		}

		// Schema upgrades must run before any code path that writes strings — the
		// front-end collection pass included (an admin can browse the site before
		// ever opening wp-admin after an update). The check is one autoloaded
		// option compare, so it's safe to run on every request.
		Database::maybe_upgrade();

		// Sempre, non solo in bacheca: il modulo dei ticket risponde alla verifica
		// del sito di supporto su una richiesta pubblica, e i suoi ganci wp_ajax_
		// scattano comunque solo su admin-ajax.
		( new \TranslateRocket\Admin\Ticket() )->register();

		if ( is_admin() ) {
			// Front-end-originated AJAX (e.g. WooCommerce cart fragments) runs through
			// admin-ajax; switch its locale and translate Woo fragments so they come
			// back in the visitor's language.
			if ( ! $coexist && Router::is_frontend_ajax() ) {
				( new \TranslateRocket\Frontend\Locale() )->boot();
				( new \TranslateRocket\Frontend\WooCommerce() )->boot();
			}
			// Forminator submits and loads forms through admin-ajax: its messages and
			// e-mail notifications follow the language of the page the form is on
			// (read from a hidden field, so a missing referer doesn't matter).
			if ( ! $coexist && wp_doing_ajax() ) {
				( new \TranslateRocket\Frontend\Forminator() )->boot();
			}
			$this->admin = new Admin();
			$this->admin->register();
			( new \TranslateRocket\Admin\MetaBox() )->register();
			( new \TranslateRocket\Admin\VisibilityBox() )->register();
			( new \TranslateRocket\Admin\CopyAdmin() )->register();
			( new \TranslateRocket\Admin\CopyCleanupAdmin() )->register();
			( new \TranslateRocket\Admin\EditorButton() )->register();
			( new \TranslateRocket\Admin\Growth() )->register();
			( new \TranslateRocket\Admin\BrowserEngine() )->register();
		} elseif ( $coexist ) {
			// Side by side: nothing reaches visitors. Only the administrator's
			// string collection while browsing, and ?trr-preview=xx pages.
			( new \TranslateRocket\Frontend\Engine() )->boot();
		} else {
			// Preview mode: if translations are for administrators only, bounce
			// other visitors off language URLs before anything else runs.
			( new \TranslateRocket\Frontend\Preview() )->boot();
			// Front end: enforce per-page language visibility before anything else.
			( new \TranslateRocket\Frontend\Visibility() )->boot();
			// Serve an independent page copy (if one is active) in place of runtime
			// translation — must boot before the Engine so its stand-down check holds.
			( new \TranslateRocket\Frontend\CopyServer() )->boot();
			// Old addresses of Polylang/WPML/Bogo pages tidied up after an import -> 301.
			( new \TranslateRocket\Frontend\MovedCopies() )->boot();
			// Detect strings (admin) and replace them per language.
			( new \TranslateRocket\Frontend\Engine() )->boot();
			// Interface (menus, buttons, theme/plugin labels): use WordPress' own
			// translations by switching the locale on translated pages.
			( new \TranslateRocket\Frontend\Locale() )->boot();
			// WooCommerce: translate JS-localised strings + AJAX cart fragments.
			( new \TranslateRocket\Frontend\WooCommerce() )->boot();
			// Form plugins: translate JS-inserted validation/notice messages.
			( new \TranslateRocket\Frontend\Forms() )->boot();
			// Forminator: hidden language field, server messages, e-mail notifications.
			( new \TranslateRocket\Frontend\Forminator() )->boot();
			// Multilingual XML sitemap (hreflang alternates for every language).
			( new \TranslateRocket\Frontend\Sitemap() )->boot();
			// Optional first-visit redirect to the visitor's browser language.
			( new \TranslateRocket\Frontend\Redirect() )->boot();
		}
	}

	/**
	 * Access the language router.
	 */
	public function router(): Router {
		if ( null === $this->router ) {
			$this->router = new Router();
		}
		return $this->router;
	}

	/**
	 * Cache-busting version for an asset (its file modification time), so
	 * updated CSS/JS is always picked up instead of a stale cached copy.
	 */
	public static function asset_ver( string $relative ): string {
		$file = TRROCKET_PATH . $relative;
		return file_exists( $file ) ? (string) filemtime( $file ) : TRROCKET_VERSION;
	}
}
