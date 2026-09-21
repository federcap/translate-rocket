<?php
/**
 * Old addresses of tidied-up copies lead to the translated page.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Importers\CopyCleanup;
use TranslateRocket\Importers\Comune;

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
		$vecchio = self::other_plugin_prefix_url();
		if ( '' !== $vecchio ) {
			wp_safe_redirect( $vecchio, 301, 'TranslateRocket' );
			exit;
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

	/**
	 * A 404 under the other plugin's language prefix, when that prefix is not
	 * ours: WPML serves Simplified Chinese under /zh-hans/, we serve it under
	 * /zh/ (likewise zh-hant, pt-pt…). Every address of those languages — in
	 * Google, in bookmarks, in links from other sites — answered 404 once the
	 * copies were tidied up (found 21/09/2026, collaudo-wpml-affiancata-http).
	 * Here /zh-hans/rest becomes /zh/rest; the same request for a prefix that
	 * was the other plugin's main language drops the prefix.
	 *
	 * Only codes the other plugin really used on this site are considered,
	 * read from its own tables (they stay after it is switched off), so a
	 * page slug such as /de-luxe/ is never mistaken for a language.
	 */
	private static function other_plugin_prefix_url(): string {
		$router = Plugin::instance()->router();
		$path   = ltrim( $router->current_clean_path(), '/' );
		if ( '' === $path ) {
			return '';
		}
		$pezzi = explode( '/', $path, 2 );
		$seg   = strtolower( rawurldecode( $pezzi[0] ) );
		$resto = $pezzi[1] ?? '';
		if ( '' === $seg || ! in_array( $seg, self::other_plugin_codes(), true ) ) {
			return '';
		}
		$nostra = Comune::lingua( $seg );
		if ( $nostra === $seg && ! $router->is_default( $nostra ) ) {
			return ''; // Same prefix as ours: the request is already ours.
		}
		if ( ! in_array( $nostra, $router->public_languages(), true ) ) {
			return '';
		}
		$url   = $router->home_for_language( $nostra ) . $resto;
		$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $query ) {
			$url .= '?' . $query;
		}
		return $url;
	}

	/**
	 * Language codes WPML and Polylang used on this site, lower case.
	 *
	 * @return string[]
	 */
	private static function other_plugin_codes(): array {
		static $codici = null;
		if ( null !== $codici ) {
			return $codici;
		}
		global $wpdb;
		$codici = array();
		$wpml   = $wpdb->prefix . 'icl_languages';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpml ) ) ) { // phpcs:ignore WordPress.DB
			foreach ( (array) $wpdb->get_col( "SELECT code FROM `{$wpml}` WHERE active = 1" ) as $c ) { // phpcs:ignore WordPress.DB
				$codici[] = strtolower( (string) $c );
			}
		}
		$pll = $wpdb->get_col( // phpcs:ignore WordPress.DB
			"SELECT t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'language'"
		);
		foreach ( (array) $pll as $c ) {
			$codici[] = strtolower( (string) $c );
		}
		$codici = array_values( array_unique( array_filter( $codici ) ) );
		return $codici;
	}
}
