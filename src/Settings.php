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
	 * Default settings shape.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'source_language'           => 'it',
			'target_languages'         => array(),
			// Languages added but not published yet: hidden from the switcher,
			// the sitemap and hreflang, and their URLs send visitors to the
			// default language, while the owner keeps translating them.
			'offline_languages'        => array(),
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
			'translate_interface'      => true,
			'translate_slugs'          => false,
			'auto_redirect'            => false,
			'cache_pages'              => true,
			'show_poweredby'           => false,
			'exclude_paths'            => array(),
			'exclude_selectors'        => array(),
			'exclude_strings'          => array(),
			'ai_daily_limit'           => 0,
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
		$opts = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $opts ) ? $opts : array(), self::defaults() );
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
