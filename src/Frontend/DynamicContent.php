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
		// Administrators only: text added by JavaScript after the page loaded.
		add_action( 'wp_ajax_trrocket_dyn_collect', array( $this, 'ajax_collect' ) );

		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_collector' ), 5 );
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
		// The visual editor still needs it, to make injected text clickable.
		$editing = VisualEditor::is_editing();
		if ( ! $editing && ! Strings::has_translations( $lang ) ) {
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
				'edit' => $editing,
			)
		);
		wp_enqueue_script( $handle );
	}

	/**
	 * Attributes worth collecting from injected markup (the same the lookup handles).
	 */
	private const ATTRS = array( 'alt', 'title', 'placeholder', 'aria-label' );

	/**
	 * Most strings one administrator page view may file this way.
	 */
	private const MAX_COLLECT = 300;

	/**
	 * The collector: on a source-language page an administrator is reading, watch for
	 * text that JavaScript adds after load (cookie and consent banners, pop-ups, AJAX
	 * results) and file it with the page, like the engine files server-rendered text.
	 * Without it those texts never reach the translation screens, so the lookup below
	 * has nothing to return and the banner stays in the source language.
	 */
	public function maybe_enqueue_collector(): void {
		if ( ! Engine::$collect_injected ) {
			return;
		}
		$router = Plugin::instance()->router();
		$qid    = ( function_exists( 'is_singular' ) && is_singular() ) ? (int) get_queried_object_id() : 0;
		$page   = $qid > 0 ? $router->canonical_path( $qid ) : $router->current_clean_path();
		$title  = function_exists( 'wp_get_document_title' ) ? wp_strip_all_tags( wp_get_document_title() ) : '';

		$handle = 'trrocket-dynamic-collect';
		// In the head: the observer must be listening before other plugins inject
		// their banners on DOMContentLoaded.
		wp_register_script( $handle, TRROCKET_URL . 'assets/js/dynamic-collect.js', array(), Plugin::asset_ver( 'assets/js/dynamic-collect.js' ), false );
		wp_localize_script(
			$handle,
			'trrocketDynCollect',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'trrocket_dyn_collect' ),
				'page'  => $page,
				'sig'   => wp_hash( 'trrocket_dyn_collect|' . $page ),
				'title' => $title,
				'tools' => array_values( \TranslateRocket\NoTranslate::tool_prefixes() ),
			)
		);
		wp_enqueue_script( $handle );
	}

	/**
	 * AJAX: file text added by JavaScript on a page an administrator viewed.
	 */
	public function ajax_collect(): void {
		check_ajax_referer( 'trrocket_dyn_collect', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$page = isset( $_POST['page'] ) ? sanitize_text_field( wp_unslash( $_POST['page'] ) ) : '';
		$sig  = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		// The page key comes back from the browser: it must be the one we handed out.
		if ( '' === $page || ! hash_equals( wp_hash( 'trrocket_dyn_collect|' . $page ), $sig ) ) {
			wp_send_json_error( null, 400 );
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$raw   = isset( $_POST['items'] ) ? json_decode( (string) wp_unslash( $_POST['items'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- decoded and checked item by item below.
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( null, 400 );
		}

		$items = array();
		$seen  = array();
		foreach ( array_slice( $raw, 0, self::MAX_COLLECT ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row[0] ) || ! is_string( $row[0] ) ) {
				continue;
			}
			$text = trim( wp_strip_all_tags( $row[0] ) );
			$attr = isset( $row[1] ) && is_string( $row[1] ) ? $row[1] : '';
			if ( '' === $text || strlen( $text ) > 2000 || ! preg_match( '/\p{L}/u', $text ) ) {
				continue;
			}
			if ( Engine::is_noise( $text ) || \TranslateRocket\NoTranslate::text_excluded( $text ) ) {
				continue;
			}
			if ( '' !== $attr && ! in_array( $attr, self::ATTRS, true ) ) {
				continue;
			}
			$key = $attr . '|' . $text;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$items[]      = '' === $attr
				? array(
					'original' => $text,
					'type'     => 'text',
					'context'  => null,
				)
				: array(
					'original' => $text,
					'type'     => 'attribute',
					'context'  => $attr,
				);
		}

		$secondary = Plugin::instance()->router()->secondary_languages();
		if ( ! empty( $items ) && ! empty( $secondary ) ) {
			Strings::remember_batch( $items, $secondary, $page, $title );
		}
		wp_send_json_success( array( 'filed' => count( $items ) ) );
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
