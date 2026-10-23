<?php
/**
 * Settings storage and defaults.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the single options array stored in wp_options.
 */
class Settings {

	/**
	 * Option key in wp_options.
	 */
	const OPTION = 'trrocket_settings';

	/**
	 * The site's own language as a TranslateRocket code, for a fresh install.
	 *
	 * Read straight from the WPLANG option: get_locale() goes through the locale
	 * filter, and Languages reads these very settings — either would loop back here.
	 * Before this the default was Italian whatever the site spoke.
	 */
	private static function site_language(): string {
		$wplang = strtolower( str_replace( '_', '-', (string) get_option( 'WPLANG', '' ) ) );
		if ( '' === $wplang ) {
			return 'en';
		}
		// Locales whose short code is not the catalogue's, or would be the wrong variant.
		$special = array(
			'pt-br' => 'pt-br',
			'nb-no' => 'no',
			'nn-no' => 'no',
			'zh-tw' => 'zh-tw',
			'zh-hk' => 'zh-tw',
		);
		if ( isset( $special[ $wplang ] ) ) {
			return $special[ $wplang ];
		}
		$code = (string) strtok( $wplang, '-' );
		// A locale the catalogue does not have (sr_RS, ca, eu, …): English, which is
		// at least selectable in the wizard, rather than a code nothing can match.
		// Built-in list only: the custom languages live in these very settings.
		return isset( Languages::builtin()[ $code ] ) ? $code : 'en';
	}

	/**
	 * Default settings shape.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'source_language'           => self::site_language(),
			'target_languages'         => array(),
			// Languages added but not published yet: hidden from the switcher,
			// the sitemap and hreflang, and their URLs send visitors to the
			// default language, while the owner keeps translating them.
			'offline_languages'        => array(),
			// 9/10/2026: put a language online by itself when it is fully translated (Automatic translation).
			'auto_online'              => 0,
			'custom_languages'         => array(),
			'active_provider'          => '',
			'provider_order'           => array(),
			'providers'                => array(
				'openai'    => array(
					'api_key' => '',
					'model'   => 'gpt-4o-mini',
				),
				'anthropic' => array(
					'api_key' => '',
					'model'   => 'claude-haiku-4-5',
				),
				'gemini'    => array(
					'api_key' => '',
					'model'   => 'gemini-flash-lite-latest',
				),
				'deepl'     => array(
					'api_key' => '',
				),
				'google'    => array(
					'api_key' => '',
				),
			),
			'serve_mode'               => 'everyone',
			'translate_meta'           => true,
			'translate_term_slugs'     => true,
			'cache_warmup'             => true,
			'translate_interface'      => true,
			'translate_slugs'          => false,
			'auto_redirect'            => false,
			'cache_pages'              => true,
			'show_poweredby'           => false,
			'exclude_paths'            => array(),
			'exclude_selectors'        => array(),
			'exclude_strings'          => array(),
			'ai_daily_limit'           => 0,
			'ai_batch'                 => 0, // sentences per request: 0 = each provider's own size.
			'translated_search'        => true, // the site search finds pages by their translated text (14/10/2026)
			'search_box'               => array( 'menu' => false, 'style' => 'icon' ), // 1.8.8: the search box in the menu
			'iubenda_policies'         => array(), // 1.8.8: language => iubenda cookie policy ID
			'stats'                    => false, // visits per language, counted without cookies (1.8.6)
			'menus_per_language'       => array(), // location or nav:ID => lang => menu id (1.8.6)
			'reuse_mode'               => 'all',
			'ai_guidance'              => '',
			'ai_reset_day'             => 0,
			'ai_block_bots'            => true,
			'switcher'                 => array(
				'type'          => 'inline',
				'show'          => 'both',
				'current'       => 'show',
				'english_names' => false,
				'text_color'    => '',
				'bg_color'      => '',
				'border_color'  => '',
				'hover_color'   => '',
				'radius'        => '8',
				'shadow'        => 'none',
				'bg_opacity'    => 100,
				'floating'      => false,
				'float_pos'     => 'bottom-right',
				'in_menu'       => false,      // in the theme's menu (6/10/2026)
				'menu_location' => 'auto',
				'menu_pos'      => 'end',      // start | end | after (menu_after) of the menu (desktop and phone)
				'menu_phone'    => true,       // 23/10/2026: also in the theme's phone menu location(s)
				'menu_also'     => array(),    // 23/10/2026: more menus — theme locations, or «menu:<id>» for a menu drawn by name
				'menu_after'    => '',         // label of the top-level item the languages follow
				'footer_row'    => false,      // a row of languages at the bottom of every page
				'in_spot'       => false,      // in a spot chosen on the page (picker)
				'spot_selector' => '',
				'spot_where'    => 'end',      // start | end of the chosen element
				'spot_label'    => '',
				'footer_align'  => 'center',   // left | center | right
				'footer_cols'   => 0,          // table columns: 0 = as many as fit, or 2..4
				'footer_layout' => 'row',      // row | grid     // a menu location, «auto», or nav:<id> for a block theme's Navigation menu
				'device'        => 'both',
				'mobile'        => 'same',
				'width'         => 'auto',
				'width_px'      => '',
				'dd_trigger'    => 'click',
				'dd_caret'      => true,
				'flag_tl'       => 0,
				'flag_tr'       => 0,
				'flag_br'       => 0,
				'flag_bl'       => 0,
				'font_family'   => '',
				'font_weight'   => 'normal',
				'font_size'     => '',
				'uppercase'     => false,
				'divider'       => 'none',
				'hover_bg'      => '',
				'menu_bg'       => '',
				'float_x'       => '',
				'float_y'       => '',
				'animation'     => 'fade',
				'hover_fx'      => 'none',
			),
			'switchers'                => array(),
			'glossary'                 => array(),
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Seed defaults on activation (without clobbering existing settings).
	 */
	public static function install_defaults(): void {
		$existing = get_option( self::OPTION, null );
		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	/**
	 * Get the full settings array, merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		// 1.8.14 (ticket #22, slow log: Router → Languages → Settings::get → get_option → maybe_unserialize, dozens of
		// times a request, 67 in one translation step): read and merged with the defaults once per request and per site,
		// read again as soon as the option changes in any way.
		static $hooked = false;
		if ( ! $hooked ) {
			$hooked = true;
			foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $h ) {
				add_action( $h . self::OPTION, array( __CLASS__, 'forget' ), 0 ); // before anyone reacting to the change reads it
			}
			add_action( 'switch_blog', array( __CLASS__, 'forget' ) );
		}
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( ! isset( self::$memo[ $blog ] ) ) {
			$opts               = get_option( self::OPTION, array() );
			self::$memo[ $blog ] = wp_parse_args( is_array( $opts ) ? $opts : array(), self::defaults() );
		}
		return self::$memo[ $blog ];
	}

	/**
	 * The settings read in this request, per site (see get()).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $memo = array();

	/**
	 * Forget what was read: the option changed, or another site of the network is now current.
	 */
	public static function forget(): void {
		self::$memo = array();
	}

	/**
	 * Persist the full settings array.
	 *
	 * @param array<string, mixed> $opts Settings.
	 */
	public static function update( array $opts ): void {
		update_option( self::OPTION, $opts );
	}

	/**
	 * Glossary terms for a language: source string => forced translation.
	 *
	 * @return array<string,string>
	 */
	public static function glossary( string $lang ): array {
		$all = self::get()['glossary'] ?? array();
		$one = ( is_array( $all ) && isset( $all[ $lang ] ) && is_array( $all[ $lang ] ) ) ? $all[ $lang ] : array();
		return $one;
	}
}
