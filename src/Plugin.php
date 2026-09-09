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

		// Language routing runs on both front end and admin (admin uses helpers).
		$this->router = new Router();
		$this->router->boot();

		// Language switcher shortcode [translaterocket_switcher].
		( new \TranslateRocket\Frontend\Switcher() )->boot();
		// Language switcher Gutenberg block + block-based widget.
		( new \TranslateRocket\Frontend\SwitcherBlock() )->register();

		// Front-end visual editor (also registers its AJAX endpoints on admin).
		( new \TranslateRocket\Frontend\VisualEditor() )->boot();

		// Dynamic (JS-injected) content: cookie banners, popups, AJAX-loaded markup.
		// Registers its AJAX endpoint always; enqueues the observer only on the front end.
		( new \TranslateRocket\Frontend\DynamicContent() )->boot();

		// Optional "Powered by TranslateRocket" badge (shortcode + opt-in footer).
		( new \TranslateRocket\Frontend\PoweredBy() )->boot();

		// Which pages changed after the engine last read them. Booted here and
		// not in the admin branch on purpose: the block editor saves over the
		// REST API, where is_admin() is false — hooking it there would miss
		// exactly the edits people make most.
		\TranslateRocket\Rescan::boot();

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
			if ( Router::is_frontend_ajax() ) {
				( new \TranslateRocket\Frontend\Locale() )->boot();
				( new \TranslateRocket\Frontend\WooCommerce() )->boot();
			}
			$this->admin = new Admin();
			$this->admin->register();
			( new \TranslateRocket\Admin\MetaBox() )->register();
			( new \TranslateRocket\Admin\VisibilityBox() )->register();
			( new \TranslateRocket\Admin\CopyAdmin() )->register();
			( new \TranslateRocket\Admin\EditorButton() )->register();
			( new \TranslateRocket\Admin\Growth() )->register();
			( new \TranslateRocket\Admin\BrowserEngine() )->register();
		} else {
			// Preview mode: if translations are for administrators only, bounce
			// other visitors off language URLs before anything else runs.
			( new \TranslateRocket\Frontend\Preview() )->boot();
			// Front end: enforce per-page language visibility before anything else.
			( new \TranslateRocket\Frontend\Visibility() )->boot();
			// Serve an independent page copy (if one is active) in place of runtime
			// translation — must boot before the Engine so its stand-down check holds.
			( new \TranslateRocket\Frontend\CopyServer() )->boot();
			// Detect strings (admin) and replace them per language.
			( new \TranslateRocket\Frontend\Engine() )->boot();
			// Interface (menus, buttons, theme/plugin labels): use WordPress' own
			// translations by switching the locale on translated pages.
			( new \TranslateRocket\Frontend\Locale() )->boot();
			// WooCommerce: translate JS-localised strings + AJAX cart fragments.
			( new \TranslateRocket\Frontend\WooCommerce() )->boot();
			// Form plugins: translate JS-inserted validation/notice messages.
			( new \TranslateRocket\Frontend\Forms() )->boot();
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
