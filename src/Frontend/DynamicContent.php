<?php
/**
 * Dynamic (JavaScript-injected) content translator.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Strings;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The page engine only sees the HTML the server renders. Content that JavaScript
 * injects *after* load — cookie/consent banners (Complianz, CookieYes, Borlabs…),
 * popups (Popup Maker, OptinMonster…), AJAX-loaded results ("load more", live
 * search, product filters) — never passes through it and stays in the source
 * language.
 *
 * This ships a tiny client-side observer that watches for injected text and asks
 * the server to translate it, reusing the site's EXISTING translations only
 * (a map lookup — never an AI call). Translations are cached per tab so a
 * re-opened popup swaps instantly. Loaded only on a translated page.
 */
class DynamicContent {

	/**
	 * Max strings accepted per AJAX request (matches the JS batch cap).
	 */
	private const MAX_ITEMS = 200;

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		// The AJAX handler must be registered on admin-ajax requests too, so wire it
		// up regardless of context; the enqueue side only runs on the front end.
		add_action( 'wp_ajax_trrocket_dyn', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_trrocket_dyn', array( $this, 'ajax' ) );

		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
	}

	/**
	 * Enqueue the observer only on a secondary-language page that actually has
	 * translations to serve.
	 */
	public function maybe_enqueue(): void {
		// Shares the "translate interface / JS-inserted strings" switch with the
		// WooCommerce and Forms glue.
		if ( empty( Settings::get()['translate_interface'] ) ) {
			return;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( $router->is_default( $lang ) ) {
			return;
		}
		// Nothing translated for this language yet: don't chatter with the server.
		if ( ! Strings::has_translations( $lang ) ) {
			return;
		}

		$handle = 'trrocket-dynamic';
		wp_register_script(
			$handle,
			TRROCKET_URL . 'assets/js/dynamic.js',
			array(),
			Plugin::asset_ver( 'assets/js/dynamic.js' ),
			true
		);
		wp_localize_script(
			$handle,
			'trrocketDyn',
			array(
				'ajax' => admin_url( 'admin-ajax.php' ),
				'lang' => $lang,
			)
		);
		wp_enqueue_script( $handle );
	}

	/**
	 * AJAX: return this site's existing translations for the requested strings.
	 *
	 * Safe to expose without a nonce: it only ever returns translations that are
	 * already public elsewhere on the site (a map lookup for a valid target
	 * language) and never triggers a translation/AI call. No nonce also keeps it
	 * compatible with fully page-cached anonymous requests.
	 */
	public function ajax(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- deliberately nonce-less read-only endpoint (see the docblock above): it only returns translations already public on the site, never writes, and must work on fully page-cached anonymous requests where a nonce would be stale.
		$lang  = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		$items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each item sanitized below.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$router = Plugin::instance()->router();
		if ( '' === $lang || ! is_array( $items ) || $router->is_default( $lang )
			|| ! in_array( $lang, $router->secondary_languages(), true ) ) {
			wp_send_json_error();
		}

		// The request already carries the exact strings to translate — the ideal
		// shape for a batch lookup, no full map needed in any mode.
		$texts = array();
		$n     = 0;
		foreach ( $items as $item ) {
			if ( ++$n > self::MAX_ITEMS ) {
				break;
			}
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$texts[] = trim( $item );
			}
		}
		wp_send_json_success( Strings::translate_texts( $texts, $lang ) );
	}
}
