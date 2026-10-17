<?php
/**
 * WoodMart (1.8.8, 16/10/2026: its 8.5 code read; a shop on WoodMart left after 10 minutes).
 *
 * The mega menus are loaded with AJAX and kept in the visitor's localStorage under one key for
 * the whole site (inc/enqueue.php: «menu_storage_key»): whoever saw them in English kept seeing
 * them in English on /it/, and a translation changed later never reached them. The key gets the
 * language and our cache version, through the filter WoodMart offers for it.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

use TranslateRocket\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * WoodMart.
 */
class WoodMart {

	/**
	 * Hook into WordPress (the filters exist only with WoodMart).
	 */
	public function boot(): void {
		add_filter( 'woodmart_menu_storage_key', array( $this, 'storage_key' ), 20 );
		add_filter( 'woodmart_localized_string_array', array( $this, 'words' ), 20 );
	}

	/**
	 * The theme's interface words handed to its scripts (inc/enqueue.php, «woodmart_settings»): single
	 * words like «Close», «Checkout», «Clear» are not taken from script data on their own (they could be
	 * codes), so here they are named one by one.
	 */
	const WORD_KEYS = array(
		'adding_to_cart', 'added_to_cart', 'continue_shopping', 'view_cart', 'go_to_checkout', 'loading',
		'countdown_days', 'countdown_hours', 'countdown_mins', 'countdown_sec', 'all_results', 'close', 'close_markup',
		'share_fb', 'pin_it', 'tweet', 'download_image', 'off_canvas_column_close_btn_text',
		'comment_images_upload_size_text', 'comment_images_count_text', 'comment_required_images_error_text',
		'comment_images_upload_mimes_text', 'comment_images_added_count_text', 'product_categories_placeholder',
		'product_categories_no_results', 'add_to_cart_text', 'mobile_navigation_drilldown_back_to',
		'mobile_navigation_drilldown_back_to_main_menu', 'mobile_navigation_drilldown_back_to_categories',
		'search_history_title', 'search_history_clear_all', 'swiper_prev_slide_msg', 'swiper_next_slide_msg',
		'swiper_first_slide_msg', 'swiper_last_slide_msg', 'on_this_page',
	);

	/**
	 * Those words filed for translation (administrator, site language) and translated on a translated page.
	 *
	 * @param mixed $settings WoodMart's script settings.
	 * @return mixed
	 */
	public function words( $settings ) {
		if ( ! is_array( $settings ) || is_admin() ) {
			return $settings;
		}
		$testi = array();
		foreach ( self::WORD_KEYS as $k ) {
			if ( isset( $settings[ $k ] ) && is_string( $settings[ $k ] ) && '' !== trim( $settings[ $k ] ) && false === strpos( $settings[ $k ], '<' ) ) {
				$testi[ $k ] = trim( $settings[ $k ] );
			}
		}
		if ( ! $testi ) {
			return $settings;
		}
		$router = Plugin::instance()->router();
		$lang   = (string) $router->current_language();
		if ( '' === $lang || $router->is_default( $lang ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				\TranslateRocket\Frontend\RestContent::remember( array_values( $testi ), '[woodmart]' );
			}
			return $settings;
		}
		$map = \TranslateRocket\Strings::translate_texts( array_values( $testi ), $lang );
		foreach ( $testi as $k => $t ) {
			if ( isset( $map[ $t ] ) && '' !== trim( (string) $map[ $t ] ) && \TranslateRocket\Frontend\ScriptData::same_placeholders( $t, (string) $map[ $t ] ) ) {
				$settings[ $k ] = (string) $map[ $t ];
			}
		}
		return $settings;
	}

	/**
	 * The mega menus' cache: one per language, renewed when the translations change.
	 *
	 * @param mixed $key WoodMart's key.
	 * @return mixed
	 */
	public function storage_key( $key ) {
		if ( ! is_string( $key ) || is_admin() ) {
			return $key;
		}
		$lang = (string) Plugin::instance()->router()->current_language();
		return $key . '_' . sanitize_key( $lang ) . '_' . (int) \TranslateRocket\Cache::version();
	}
}
