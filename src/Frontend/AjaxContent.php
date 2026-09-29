<?php
/**
 * Content fetched after the page has loaded.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Strings;
use TranslateRocket\Router;

defined( 'ABSPATH' ) || exit;

/**
 * «Load more», a filtered product grid, infinite scroll, a quick view: themes
 * and plugins fetch such pieces through admin-ajax, as HTML or as JSON holding
 * HTML, and write them into the page. The page engine never sees them — it
 * works on whole pages — so on /it/ everything loaded after the first screen
 * came back in the source language (29/09/2026). The interface words inside
 * were already right (Locale switches the language pack on front-end AJAX);
 * the content — titles, excerpts, product names, buttons — was not.
 *
 * When a front-end AJAX request comes from a translated page, the response is
 * translated on its way out: HTML through Fragment, JSON string by string (a
 * string holding markup as a fragment, a plain one whole). Only the plugin's
 * own actions and WooCommerce's, handled elsewhere, are left alone. A response
 * that is not HTML nor JSON, or that the JSON parser cannot read, is untouched.
 */
class AjaxContent {

	/**
	 * Language of the page that asked ('' = nothing to do).
	 *
	 * @var string
	 */
	private $lang = '';

	/**
	 * Hook into a front-end AJAX request.
	 */
	public function boot(): void {
		if ( ! Router::is_frontend_ajax() ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === strpos( $action, 'trrocket_' ) || 0 === strpos( $action, 'woocommerce_' ) || isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! in_array( $lang, $router->secondary_languages(), true ) ) {
			return;
		}
		if ( ! Strings::has_translations( $lang ) || \TranslateRocket\Coexistence::on() ) {
			return;
		}
		/**
		 * Whether to translate the HTML/JSON a front-end AJAX action returns.
		 *
		 * @param bool   $translate Default true.
		 * @param string $action    The AJAX action.
		 * @param string $lang      Language of the page that asked.
		 */
		if ( ! apply_filters( 'trrocket_translate_ajax_response', true, $action, $lang ) ) {
			return;
		}
		$this->lang = $lang;
		// After every plugin has registered its handlers, before any of them prints.
		add_action( 'init', array( $this, 'start' ), PHP_INT_MAX );
	}

	/**
	 * Open the buffer whose callback translates the response.
	 */
	public function start(): void {
		ob_start( array( $this, 'translate' ) );
	}

	/**
	 * @param string $out The whole response.
	 * @return string
	 */
	public function translate( $out ) {
		if ( ! is_string( $out ) || '' === $this->lang ) {
			return $out;
		}
		$body = trim( $out );
		if ( '' === $body || strlen( $body ) > 2000000 ) {
			return $out;
		}
		$first = $body[0];
		if ( '{' === $first || '[' === $first ) {
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) {
				return $out;
			}
			$changed = false;
			$data    = $this->walk( $data, $changed );
			if ( ! $changed ) {
				return $out;
			}
			$json = wp_json_encode( $data );
			return is_string( $json ) ? $json : $out;
		}
		if ( '<' === $first ) {
			return Fragment::translate( $out, $this->lang );
		}
		return $out;
	}

	/**
	 * Translate the strings of a decoded JSON value.
	 *
	 * @param mixed $node    Value.
	 * @param bool  $changed Set when something was swapped.
	 * @return mixed
	 */
	private function walk( $node, bool &$changed ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				$node[ $k ] = $this->walk( $v, $changed );
			}
			return $node;
		}
		if ( ! is_string( $node ) || '' === trim( $node ) || ! preg_match( '/\p{L}/u', $node ) ) {
			return $node;
		}
		if ( preg_match( '~^(https?:)?//|^[/#?]~', trim( $node ) ) ) {
			return $node; // An address.
		}
		$new = false !== strpos( $node, '<' ) ? Fragment::translate( $node, $this->lang ) : Fragment::text( $node, $this->lang );
		if ( $new !== $node ) {
			$changed = true;
		}
		return $new;
	}
}
