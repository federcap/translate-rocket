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
	 * Where texts loaded from the REST API are filed when the page that asked is not known.
	 */
	const LOADED_URL = '[loaded content]';

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
		// 22/10/2026 (compatibility pass: LiteSpeed Cache caches REST answers by default, «cache-rest» on): the answer a
		// page of the site asks for depends on that page's language (the Referer), the cache key only on the address —
		// the first language to ask was served to every other. Answers asked by a page of a multilingual site are
		// therefore kept out of page caches; calls from elsewhere (apps, other sites) are cached as before.
		if ( 'GET' === $request->get_method() && self::from_site_page() && $router->secondary_languages() && ! \TranslateRocket\Coexistence::on() ) {
			do_action( 'litespeed_control_set_nocache', 'TranslateRocket: the answer depends on the language of the page that asked' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}
		if ( '' === $lang || $router->is_default( $lang ) || ! in_array( $lang, $router->secondary_languages(), true ) || ! in_array( $lang, $router->public_languages(), true ) ) {
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
		// A GET answer is public content (a map's markers, a slider's slides…) that the page loads after it
		// has been shown: it never passes through the page engine, so it never became translatable — the
		// marker descriptions of WP Go Maps stayed in English for good (3/10/2026). Its texts are collected
		// here. Never for other methods: a POST answer can carry what a visitor typed.
		// Nor for answers about a person: a cart, an order, an account, a user — nor for a signed-in customer.
		// 18/10/2026 (translaterocket.com: 1,977 «sentences» in Greek, Korean and German that no visitor ever saw —
		// the descriptions of the REST routes, read by the dashboard while the admin's browser had those languages):
		// nothing asked from wp-admin, nothing from the route index or a schema, is what a page shows.
		$collect = 'GET' === $request->get_method()
			&& '/' !== $route && '' !== $route
			&& self::from_site_page()
			&& ! preg_match( '#/(users|cart|checkout|orders?|customers?|account|me|batch)(/|$)#i', $route )
			&& ( ! is_user_logged_in() || current_user_can( 'manage_options' ) );
		if ( $collect ) {
			Fragment::$seen = array();
		}
		$data = $this->walk( $data, $lang, $changed, '' );
		if ( $collect ) {
			$seen           = Fragment::$seen;
			Fragment::$seen = null;
			self::remember( (array) $seen, $route );
		}
		if ( $changed ) {
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Was this answer asked by a dashboard screen (the block editor, a plugin's settings page), not by a
	 * page of the site? Its texts are the dashboard's own, in the admin's language.
	 */
	public static function from_admin(): bool {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return '' !== $ref && false !== strpos( (string) wp_parse_url( $ref, PHP_URL_PATH ), '/wp-admin/' );
	}

	/**
	 * Was this answer asked by a page of this site (its script loading a map, a slider, more posts)? 20/10/2026
	 * (translaterocket.com, the day after it reached 99%): crawlers reading /it/wp-json/wp/v2/pages directly
	 * filed whole pages, cut into pieces, as «content loaded by the page» — 43 new «missing» sentences in two days
	 * that no visitor ever sees. A request with no page of this site behind it is not what a visitor reads.
	 */
	public static function from_site_page(): bool {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $ref || self::from_admin() ) {
			return false;
		}
		return 0 === strcasecmp( (string) wp_parse_url( $ref, PHP_URL_HOST ), (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) );
	}

	/**
	 * Make the texts of an answer loaded after the page (REST route, admin-ajax action)
	 * translatable, filed under the page that asked for them.
	 *
	 * @param string[] $texts Texts met in the answer.
	 * @param string   $route REST route or AJAX action (only used to tell answers apart).
	 */
	public static function remember( array $texts, string $route ): void {
		$items = array();
		foreach ( $texts as $t ) {
			$t = trim( (string) $t );
			if ( '' === $t || mb_strlen( $t ) > 2000 || ! preg_match( '/\p{L}/u', $t ) || \TranslateRocket\NoTranslate::text_excluded( $t ) ) {
				continue;
			}
			$items[ $t ] = array( 'original' => $t, 'type' => 'text' );
			if ( count( $items ) >= 300 ) {
				break;
			}
		}
		$secondary = Plugin::instance()->router()->secondary_languages();
		if ( empty( $items ) || empty( $secondary ) ) {
			return;
		}
		// At most once a day for the same answer.
		$key = 'trrocket_rest_' . md5( $route . "\n" . implode( "\n", array_keys( $items ) ) );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		// Filed under the page that asked (its address without the language), or under a heading of their own.
		$url   = self::LOADED_URL;
		$title = '';
		$ref   = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path  = (string) wp_parse_url( $ref, PHP_URL_PATH );
		$base  = rtrim( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$path = (string) preg_replace( '#^/[a-z]{2}(?:-[a-z]{2})?(?=/|$)#i', '', $path, 1 );
		$pid  = '' !== $path ? url_to_postid( home_url( $path ) ) : 0;
		if ( $pid > 0 ) {
			$url   = Plugin::instance()->router()->canonical_path( $pid );
			$title = wp_strip_all_tags( get_the_title( $pid ) );
		}
		Strings::remember_batch( array_values( $items ), $secondary, $url, $title );
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
		if ( in_array( $key, self::LINK_KEYS, true ) || ( is_string( $key ) && preg_match( '/_(?:url|link|permalink)$/', $key ) && preg_match( '~^(https?:)?//|^/~', trim( $node ) ) ) ) {
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
