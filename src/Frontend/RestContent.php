<?php
/**
 * Content served by the REST API to a translated page.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's blocks (All Products, filters, the cart), the Interactivity
 * API's client-side navigation and headless themes fetch their content from
 * /wp-json while the visitor is on /it/: product names, titles, excerpts and
 * descriptions arrived in the source language (29/09/2026). When a REST
 * request comes from a translated page, the content fields of the response are
 * translated on the way out — only fields that hold words (name, title,
 * description, excerpt, content, label, text, caption, alt…) and only their
 * value, never ids, slugs, prices or structure; permalinks get the language.
 * Requests made by an editor (context=edit, or from wp-admin) are left alone.
 */
class RestContent {

	/**
	 * Keys whose string value is content for the visitor.
	 */
	const CONTENT_KEYS = array( 'rendered', 'name', 'title', 'description', 'short_description', 'excerpt', 'content', 'label', 'text', 'message', 'caption', 'alt_text', 'alt', 'summary', 'subtitle', 'heading', 'button_text', 'price_html', 'stock_availability_text', 'availability' );

	/**
	 * Keys whose string value is a link to a page of the site.
	 */
	const LINK_KEYS = array( 'permalink', 'link' );

	/**
	 * Hook into REST responses.
	 */
	public function boot(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'response' ), 20, 3 );
	}

	/**
	 * @param mixed $response WP_REST_Response.
	 * @param mixed $server   WP_REST_Server.
	 * @param mixed $request  WP_REST_Request.
	 * @return mixed
	 */
	public function response( $response, $server = null, $request = null ) {
		unset( $server );
		if ( ! ( $response instanceof \WP_REST_Response ) || ! ( $request instanceof \WP_REST_Request ) ) {
			return $response;
		}
		$route = (string) $request->get_route();
		if ( 0 === strpos( $route, '/translate-rocket' ) || 0 === strpos( $route, '/trrocket' ) || 'edit' === $request->get_param( 'context' ) ) {
			return $response;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! in_array( $lang, $router->secondary_languages(), true ) ) {
			return $response;
		}
		if ( ! Strings::has_translations( $lang ) || \TranslateRocket\Coexistence::on() ) {
			return $response;
		}
		/**
		 * Whether to translate the content fields of a REST response asked from a
		 * translated page.
		 *
		 * @param bool   $translate Default true.
		 * @param string $route     REST route.
		 * @param string $lang      Language of the page that asked.
		 */
		if ( ! apply_filters( 'trrocket_translate_rest_response', true, $route, $lang ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		$changed = false;
		$data    = $this->walk( $data, $lang, $changed, '' );
		if ( $changed ) {
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * @param mixed  $node    Value.
	 * @param string $lang    Language.
	 * @param bool   $changed Set when something was swapped.
	 * @param string $key     Key of $node in its parent.
	 * @return mixed
	 */
	private function walk( $node, string $lang, bool &$changed, string $key ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				$node[ $k ] = $this->walk( $v, $lang, $changed, is_string( $k ) ? strtolower( $k ) : $key );
			}
			return $node;
		}
		if ( $node instanceof \stdClass ) {
			// The Store API hands some parts over as objects (add_to_cart, prices…).
			foreach ( get_object_vars( $node ) as $k => $v ) {
				$node->{$k} = $this->walk( $v, $lang, $changed, strtolower( (string) $k ) );
			}
			return $node;
		}
		if ( ! is_string( $node ) || '' === trim( $node ) ) {
			return $node;
		}
		if ( in_array( $key, self::LINK_KEYS, true ) ) {
			static $home = null;
			if ( null === $home ) {
				$raw  = (string) get_option( 'home' );
				$home = array( (string) wp_parse_url( $raw, PHP_URL_HOST ), rtrim( (string) wp_parse_url( $raw, PHP_URL_PATH ), '/' ) );
			}
			$new = Engine::localize_link( $node, $home[0], $home[1], $lang, Plugin::instance()->router()->secondary_languages() );
			if ( $new !== $node ) {
				$changed = true;
			}
			return $new;
		}
		if ( ! in_array( $key, self::CONTENT_KEYS, true ) || ! preg_match( '/\p{L}/u', $node ) ) {
			return $node;
		}
		$new = false !== strpos( $node, '<' ) ? Fragment::translate( $node, $lang ) : Fragment::text( $node, $lang );
		if ( $new !== $node ) {
			$changed = true;
		}
		return $new;
	}
}
