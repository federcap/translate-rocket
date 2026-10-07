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
	 * The AJAX action (or endpoint) answered.
	 *
	 * @var string
	 */
	private $action = '';

	/**
	 * Actions whose answer is about a person — never collected (see collects()).
	 */
	const PERSONAL_ACTION = '/(cart|checkout|order|account|user|login|logout|regist|profile|customer|wishlist|password|session|nonce|token|my_|_my)/i';

	/**
	 * WooCommerce's own wc-ajax endpoints (class-wc-ajax.php), translated by the WooCommerce integration.
	 */
	const WC_OWN = array( 'get_refreshed_fragments', 'apply_coupon', 'remove_coupon', 'update_shipping_method', 'get_cart_totals', 'update_order_review', 'add_to_cart', 'remove_from_cart', 'checkout', 'get_variation', 'get_customer_location' );

	/**
	 * Request arguments that carry what a visitor typed in a search box: such an answer is translated,
	 * never collected (it can echo the words typed; its products are collected on their own pages).
	 */
	const QUERY_ARGS = array( 's', 'q', 'search', 'keyword', 'keywords', 'term', 'phrase', 'query' );

	/**
	 * Keys whose value is a link to a page of the site: it gets the page's language.
	 */
	const LINK_KEYS = array( 'url', 'link', 'permalink', 'href' );

	/**
	 * Hook into a front-end AJAX request.
	 */
	public function boot(): void {
		if ( ! Router::is_frontend_ajax() && ! self::is_page_request_flag() ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		// WooCommerce's own wc-ajax endpoints (cart fragments, checkout…) are handled by the WooCommerce
		// integration. Other plugins answer on wc-ajax too — FiboSearch's suggestions while the visitor
		// types (?wc-ajax=dgwt_wcas_ajax_search) came back in English with links out of /it/ (5/10/2026).
		$wc = isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === strpos( $action, 'trrocket_' ) || 0 === strpos( $action, 'woocommerce_' ) || ( '' !== $wc && in_array( $wc, self::WC_OWN, true ) ) ) {
			return;
		}
		if ( '' !== $wc ) {
			$action = 'wc-ajax:' . $wc;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! in_array( $lang, $router->secondary_languages(), true ) || ! in_array( $lang, $router->public_languages(), true ) ) {
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
		$this->lang   = $lang;
		$this->action = '' !== $action ? $action : ( Router::is_ajax_endpoint() ? 'endpoint' : 'page' );
		// After every plugin has registered its handlers, before any of them prints.
		add_action( 'init', array( $this, 'start' ), PHP_INT_MAX );
	}

	/**
	 * Request arguments with which a plugin asks a PAGE address (with its /it/) for a piece of
	 * itself, answered early and cut short: Events Manager's calendar posts em_ajax=1 to the page
	 * for the next month and prints the grid at wp_loaded, before the page engine starts; the
	 * events came back in English on /it/ (4/10/2026).
	 */
	const PAGE_REQUEST_FLAGS = array( 'em_ajax' );

	/**
	 * Whether the request carries one of PAGE_REQUEST_FLAGS (filter: trrocket_ajax_request_flags).
	 */
	public static function is_page_request_flag(): bool {
		foreach ( (array) apply_filters( 'trrocket_ajax_request_flags', self::PAGE_REQUEST_FLAGS ) as $flag ) {
			if ( '' !== (string) $flag && ! empty( $_REQUEST[ (string) $flag ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return true;
			}
		}
		return false;
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
		$first   = $body[0];
		$collect = $this->collects();
		if ( $collect ) {
			Fragment::$seen = array();
		}
		$result = $out;
		if ( '{' === $first || '[' === $first ) {
			$data = json_decode( $body, true );
			if ( is_array( $data ) ) {
				$changed = false;
				$data    = $this->walk( $data, $changed );
				if ( $changed ) {
					$json   = wp_json_encode( $data );
					$result = is_string( $json ) ? $json : $out;
				}
			}
		} elseif ( '<' === $first ) {
			$result = Fragment::translate( $out, $this->lang );
		}
		if ( $collect ) {
			$seen           = (array) Fragment::$seen;
			Fragment::$seen = null;
			RestContent::remember( array_values( array_filter( $seen, array( __CLASS__, 'is_words' ) ) ), 'ajax:' . $this->action );
		}
		return $result;
	}

	/**
	 * Whether the texts of this answer become translatable. Ninja Tables loads its rows, many themes
	 * their «load more», from admin-ajax after the page is shown: those texts were translated once
	 * known, but nothing ever made them known — they stayed in the source language for good
	 * (4/10/2026). Collected like the REST answers: only a GET (a POST can carry what a visitor
	 * typed), never an action about a person (cart, account, order…), never for a signed-in
	 * visitor who is not an administrator.
	 */
	private function collects(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'GET' !== $method || preg_match( self::PERSONAL_ACTION, $this->action ) ) {
			return false;
		}
		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		// Security audit 7/10/2026: a visitor's request only collects when it is a real admin-ajax
		// action the site registers for guests (a map's markers) — never a page address dressed up
		// with an «ajax» parameter (?em_ajax=1 on an invented search: its 404 text would be filed).
		if ( ! is_user_logged_in() && ! GuestScan::active() ) {
			if ( ! wp_doing_ajax() || 'endpoint' === $this->action || 'page' === $this->action || 0 === strpos( $this->action, 'wc-ajax:' ) || ! has_action( 'wp_ajax_nopriv_' . $this->action ) ) {
				return false;
			}
		}
		foreach ( self::QUERY_ARGS as $arg ) {
			if ( isset( $_GET[ $arg ] ) && '' !== trim( (string) wp_unslash( $_GET[ $arg ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return false;
			}
		}
		/**
		 * Whether the texts of a front-end AJAX answer are collected as translatable.
		 *
		 * @param bool   $collect Default per the rules above.
		 * @param string $action  The AJAX action.
		 */
		return (bool) apply_filters( 'trrocket_collect_ajax_response', true, $this->action );
	}

	/**
	 * A text that reads as words, not as a code a script passes around («get-all-data», «default»,
	 * «text-left»): it has a space, or starts with a capital letter, and is not one bare identifier.
	 *
	 * @param mixed $t Text.
	 */
	public static function is_words( $t ): bool {
		$t = trim( (string) $t );
		if ( '' === $t || ! preg_match( '/\p{L}/u', $t ) ) {
			return false;
		}
		if ( preg_match( '/^[\d.,:]+\s*(ms|s|sec|secs|seconds|min)$/i', $t ) ) {
			return false; // a timing («0.01 sec», FiboSearch)
		}
		if ( preg_match( '/^[A-Za-z0-9_.:\-]+$/', $t ) && ! preg_match( '/^\p{Lu}\p{Ll}+$/u', $t ) ) {
			return false; // one identifier («get-all-data», «btn_primary», «GET»), but «Breakfast» stays
		}
		// A list of CSS classes («ninja_table_row_0 nt_row_id_1»): every piece an identifier, one with _ or a digit.
		$pieces = (array) preg_split( '/\s+/u', $t );
		$ids    = preg_grep( '/^[a-z0-9_\-]+$/', $pieces );
		if ( count( $ids ) === count( $pieces ) && preg_match( '/[_0-9]/', $t ) ) {
			return false;
		}
		return (bool) preg_match( '/\s/u', $t ) || (bool) preg_match( '/^\p{Lu}/u', $t );
	}

	/**
	 * Translate the strings of a decoded JSON value.
	 *
	 * @param mixed $node    Value.
	 * @param bool  $changed Set when something was swapped.
	 * @return mixed
	 */
	private function walk( $node, bool &$changed, $key = '' ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				$node[ $k ] = $this->walk( $v, $changed, $k );
			}
			return $node;
		}
		if ( ! is_string( $node ) || '' === trim( $node ) || ! preg_match( '/\p{L}/u', $node ) ) {
			return $node;
		}
		if ( preg_match( '~^(https?:)?//|^[/#?]~', trim( $node ) ) ) {
			// A link to a page of the site, under a key that says so, takes the page's language (as in REST
			// answers): FiboSearch's suggestions led from /it/ to the English product (5/10/2026).
			if ( is_string( $key ) && in_array( strtolower( $key ), self::LINK_KEYS, true ) ) {
				$raw = (string) get_option( 'home' );
				$new = Engine::localize_link( $node, (string) wp_parse_url( $raw, PHP_URL_HOST ), rtrim( (string) wp_parse_url( $raw, PHP_URL_PATH ), '/' ), $this->lang, Plugin::instance()->router()->secondary_languages() );
				if ( $new !== $node ) {
					$changed = true;
				}
				return $new;
			}
			return $node; // An address.
		}
		$new = false !== strpos( $node, '<' ) ? Fragment::translate( $node, $this->lang ) : Fragment::text( $node, $this->lang );
		if ( $new !== $node ) {
			$changed = true;
		}
		return $new;
	}
}
