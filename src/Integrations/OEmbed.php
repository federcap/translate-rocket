<?php
/**
 * oEmbed of translated addresses.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

defined( 'ABSPATH' ) || exit;

use TranslateRocket\Plugin;
use TranslateRocket\Slugs;
use TranslateRocket\Frontend\Fragment;

/**
 * 1.8.24 (27/10/2026, read in TranslatePress — oembed_response_data — and Polylang — oembed_request_post_id — while
 * comparing, then reproduced with collaudo-oembed): a translated page pasted into another WordPress site (the «Embed»
 * block) or read through the discovery link every /it/ page carries, got «404 oembed_invalid_url»: WordPress's
 * url_to_postid() knows neither the /it/ prefix nor the translated addresses. Now the address is recognised, and the
 * answer carries the page's title in that language and links to the translated address.
 */
class OEmbed {

	/**
	 * Hooks.
	 */
	public static function boot(): void {
		add_filter( 'oembed_request_post_id', array( __CLASS__, 'post_id' ), 10, 2 );
		// Late: SEO plugins (Yoast) write their own title into the answer at the default priority.
		add_filter( 'oembed_response_data', array( __CLASS__, 'data' ), 99, 2 );
	}

	/**
	 * Language of an address, when it is one of the site's other languages ('' otherwise).
	 *
	 * @param string $url Address.
	 */
	private static function lang_of( string $url ): string {
		$router = Plugin::instance()->router();
		$lang   = $router->language_of_url( $url );
		return ( '' !== $lang && ! $router->is_default( $lang ) && in_array( $lang, $router->secondary_languages(), true ) && in_array( $lang, $router->public_languages(), true ) ) ? $lang : ''; // an offline language stays private
	}

	/**
	 * The page behind a translated address.
	 *
	 * @param mixed $post_id Post id WordPress found (0: none).
	 * @param mixed $url     The address asked for.
	 * @return mixed
	 */
	public static function post_id( $post_id, $url ) {
		if ( (int) $post_id > 0 || ! is_string( $url ) || '' === $url ) {
			return $post_id;
		}
		$lang = self::lang_of( $url );
		if ( '' === $lang ) {
			return $post_id;
		}
		$router = Plugin::instance()->router();
		$base   = untrailingslashit( $router->language_base( $lang ) );
		$home   = untrailingslashit( home_url() );
		$path   = 0 === strpos( $url, $base ) ? substr( $url, strlen( $base ) ) : (string) wp_parse_url( $url, PHP_URL_PATH );
		$path   = '/' . ltrim( (string) strtok( $path, '?#' ), '/' );
		// The original address under the language (/it/garden-room/) …
		$id = url_to_postid( $home . $path );
		if ( $id > 0 ) {
			return $id;
		}
		// … or the translated one (/it/camera-giardino/): its last part.
		$slug = basename( untrailingslashit( $path ) );
		if ( '' !== $slug ) {
			$id = Slugs::resolve( rawurldecode( $slug ), $lang );
		}
		return $id > 0 ? $id : $post_id;
	}

	/**
	 * The answer in the language of the address asked for: title, and the links inside the HTML to embed.
	 *
	 * @param mixed $data Response data.
	 * @param mixed $post The page.
	 * @return mixed
	 */
	public static function data( $data, $post ) {
		$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( (string) $_GET['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! is_array( $data ) || ! ( $post instanceof \WP_Post ) || '' === $url ) {
			return $data;
		}
		$lang = self::lang_of( $url );
		if ( '' === $lang ) {
			return $data;
		}
		$titolo = (string) $post->post_title;
		$tr     = '' !== trim( $titolo ) ? Fragment::text( $titolo, $lang ) : $titolo;
		if ( isset( $data['title'] ) && is_string( $data['title'] ) && $tr !== $titolo ) {
			$data['title'] = str_replace( $titolo, $tr, $data['title'] );
		}
		if ( isset( $data['html'] ) && is_string( $data['html'] ) ) {
			$html = $data['html'];
			if ( $tr !== $titolo ) {
				$html = str_replace( array( esc_html( $titolo ), esc_attr( $titolo ) ), array( esc_html( $tr ), esc_attr( $tr ) ), $html );
			}
			// The page and its /embed/ view, at the translated address.
			$orig = (string) get_permalink( $post );
			$dest = trailingslashit( strtok( $url, '?#' ) );
			if ( '' !== $orig && $orig !== $dest ) {
				$html = str_replace( array( esc_url( $orig ), $orig ), array( esc_url( $dest ), $dest ), $html );
			}
			$data['html'] = $html;
		}
		return $data;
	}
}
