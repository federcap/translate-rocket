<?php
/**
 * Old addresses of tidied-up copies lead to the translated page.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Importers\CopyCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * When a Polylang/WPML/Bogo copy is moved to the Trash (or kept as an independent
 * copy, which is a draft), its old address would answer 404 — and it may be in
 * Google, in bookmarks, in links on other sites. CopyCleanup remembers each of
 * those addresses; here a 404 on one of them becomes a 301 to the original page in
 * that language.
 *
 * Only 404s are looked at, so a page that is published at that address again
 * (a copy restored from the Trash and published, a new page with the same slug)
 * simply wins.
 */
class MovedCopies {

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Redirect a 404 on a remembered copy address.
	 */
	public function maybe_redirect(): void {
		if ( ! is_404() ) {
			return;
		}
		$moved = get_option( CopyCleanup::OPTION_MOVED );
		if ( ! is_array( $moved ) || empty( $moved ) ) {
			return;
		}

		$key = trim( Plugin::instance()->router()->current_clean_path(), '/' );
		if ( '' === $key || ! isset( $moved[ $key ] ) || ! is_array( $moved[ $key ] ) ) {
			return;
		}

		$url = CopyCleanup::translated_url( (int) ( $moved[ $key ][0] ?? 0 ), (string) ( $moved[ $key ][1] ?? '' ) );
		if ( '' === $url ) {
			return;
		}
		wp_safe_redirect( $url, 301, 'TranslateRocket' );
		exit;
	}
}
