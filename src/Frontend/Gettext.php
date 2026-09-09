<?php
/**
 * Interface-string layer: translate theme/plugin UI strings via the WordPress
 * gettext filter, so text that never reaches the HTML as a plain node (labels
 * built in PHP, JS configs, etc.) is still translated.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Strings;
use TranslateRocket\Settings;
use TranslateRocket\NoTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks WordPress' translation functions on the front end. Collected strings
 * are grouped under one virtual "page" so the existing editor can handle them.
 */
class Gettext {

	/**
	 * Virtual page URL that groups all interface strings in the editor.
	 */
	const INTERFACE_URL = '[interface]';

	/**
	 * Skip strings longer than this (whole templates, JSON blobs, etc.).
	 */
	const MAX_LEN = 800;

	/**
	 * Current language.
	 *
	 * @var string
	 */
	private $lang = '';

	/**
	 * Target languages.
	 *
	 * @var string[]
	 */
	private $secondary = array();

	/**
	 * Whether the current language is a secondary (translated) one.
	 *
	 * @var bool
	 */
	private $is_secondary = false;

	/**
	 * Whether to record strings on this request (admin browsing).
	 *
	 * @var bool
	 */
	private $do_collect = false;

	/**
	 * Cached original => translation map for the current language.
	 *
	 * @var array<string,string>
	 */
	private $map = array();

	/**
	 * Strings collected this request, keyed by hash to de-duplicate.
	 *
	 * @var array<string,array{original:string,type:string,context:string}>
	 */
	private $collected = array();

	/**
	 * Whether the document <head> is done (global styles generated). We only
	 * collect after this, to skip theme.json editor metadata (colour, font-size
	 * and spacing labels) that is translated while building the head.
	 *
	 * @var bool
	 */
	private $body_started = false;

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'maybe_hook' ), 1 );
	}

	/**
	 * Decide whether to translate/collect on this request and attach filters.
	 */
	public function maybe_hook(): void {
		// Interface-string translation can be turned off entirely in settings.
		if ( empty( Settings::get()['translate_interface'] ) ) {
			return;
		}

		$router             = Plugin::instance()->router();
		$this->lang         = $router->current_language();
		$this->secondary    = $router->secondary_languages();
		$this->is_secondary = ! $router->is_default( $this->lang );
		$this->do_collect   = current_user_can( 'manage_options' ) && ! empty( $this->secondary );

		if ( ! $this->is_secondary && ! $this->do_collect ) {
			return;
		}
		if ( $this->is_secondary ) {
			// The gettext filter fires per string, so it needs a preloaded map. On
			// huge sites the whole-language map is off the table; the interface
			// strings are grouped under one virtual page, whose map stays small.
			$this->map = Strings::is_huge()
				? Strings::map_for_page( Strings::url_hash( self::INTERFACE_URL ), $this->lang )
				: Strings::map_for_language( $this->lang );
		}

		add_filter( 'gettext', array( $this, 'filter' ), 50, 3 );
		add_filter( 'gettext_with_context', array( $this, 'filter_ctx' ), 50, 4 );

		if ( $this->do_collect ) {
			add_action( 'wp_head', array( $this, 'mark_body_started' ), PHP_INT_MAX );
			add_action( 'shutdown', array( $this, 'flush' ), 1 );
		}
	}

	/**
	 * Marks that the <head> is finished, so collection can begin on body content.
	 */
	public function mark_body_started(): void {
		$this->body_started = true;
	}

	/**
	 * Filter for __(), _e(), esc_html__()… (no context).
	 *
	 * @param string $translation Already-applied translation (the displayed text).
	 * @param string $text        Untranslated source.
	 * @param string $domain      Text domain.
	 */
	public function filter( $translation, $text, $domain ): string {
		return $this->handle( (string) $translation, (string) $domain );
	}

	/**
	 * Filter for _x(), _ex()… (with context).
	 *
	 * @param string $translation Already-applied translation.
	 * @param string $text        Untranslated source.
	 * @param string $context     Gettext context.
	 * @param string $domain      Text domain.
	 */
	public function filter_ctx( $translation, $text, $context, $domain ): string {
		return $this->handle( (string) $translation, (string) $domain );
	}

	/**
	 * Shared collect + replace logic.
	 */
	private function handle( string $translation, string $domain ): string {
		// Never touch our own strings (avoids recursion and editor noise).
		if ( 'translate-rocket' === $domain ) {
			return $translation;
		}

		// Skip WordPress core strings ('default' domain): block-editor colour and
		// gradient presets, feeds, oEmbed, the admin toolbar and other internals.
		// They're noise for a visitor-facing translator, and any that are actually
		// visible on the page are already caught by the page engine.
		if ( 'default' === $domain || '' === $domain ) {
			return $translation;
		}

		// Honor the global never-translate string list.
		if ( NoTranslate::text_excluded( trim( $translation ) ) ) {
			return $translation;
		}

		// Fast path: replacement only (normal visitor on a translated page).
		if ( ! $this->do_collect ) {
			$text = trim( $translation );
			if ( '' !== $text && isset( $this->map[ $text ] ) ) {
				return str_replace( $text, $this->map[ $text ], $translation );
			}
			return $translation;
		}

		// Collection path (administrator browsing the site).
		$text = trim( $translation );
		if ( '' === $text || strlen( $text ) > self::MAX_LEN || ! preg_match( '/\p{L}/u', $text ) || preg_match( '#^https?://#i', $text ) ) {
			return $translation;
		}

		// Only record strings rendered in the page body — skip those translated
		// while building the <head>/global styles (theme.json colour, font-size
		// and spacing labels), which are editor metadata, not page content.
		if ( $this->body_started ) {
			$hash                     = Strings::hash( $text, 'gettext', $domain );
			$this->collected[ $hash ] = array(
				'original' => $text,
				'type'     => 'gettext',
				'context'  => $domain,
			);
		}

		if ( isset( $this->map[ $text ] ) ) {
			return str_replace( $text, $this->map[ $text ], $translation );
		}
		return $translation;
	}

	/**
	 * Persist everything collected this request, under the virtual interface page.
	 */
	public function flush(): void {
		if ( empty( $this->collected ) ) {
			return;
		}
		Strings::remember_batch(
			array_values( $this->collected ),
			$this->secondary,
			self::INTERFACE_URL,
			__( 'Site interface (themes & plugins)', 'translate-rocket' )
		);
		$this->collected = array();
	}
}
