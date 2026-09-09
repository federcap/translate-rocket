<?php
/**
 * Per-page, per-language visibility rules.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a page opt out of being translated into a given language and choose what
 * happens instead: go to that language's homepage, redirect to a URL, show a
 * custom message, or 404. Stored as post meta so it works for any post type.
 *
 * Modes: '' (translate, default) | 'home' | 'url' | '404' | 'message'.
 */
class Exclusions {

	const META_PREFIX = '_trrocket_vis_';
	const URL_PREFIX  = '_trrocket_visurl_';
	const MSG_PREFIX  = '_trrocket_vismsg_';

	/**
	 * Allowed non-default modes.
	 *
	 * @var string[]
	 */
	const MODES = array( 'home', 'url', '404', 'message' );

	/**
	 * Rule for a post + language.
	 *
	 * @return array{mode:string,url:string,msg:string}
	 */
	public static function get( int $post_id, string $lang ): array {
		if ( $post_id <= 0 ) {
			return array(
				'mode' => '',
				'url'  => '',
				'msg'  => '',
			);
		}
		return array(
			'mode' => (string) get_post_meta( $post_id, self::META_PREFIX . $lang, true ),
			'url'  => (string) get_post_meta( $post_id, self::URL_PREFIX . $lang, true ),
			'msg'  => (string) get_post_meta( $post_id, self::MSG_PREFIX . $lang, true ),
		);
	}

	/**
	 * Whether this post+language is excluded from normal translation.
	 */
	public static function is_excluded( int $post_id, string $lang ): bool {
		return '' !== self::get( $post_id, $lang )['mode'];
	}

	/**
	 * Save (or clear) a rule for a post + language.
	 */
	public static function set( int $post_id, string $lang, string $mode, string $url = '', string $msg = '' ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		if ( ! in_array( $mode, self::MODES, true ) ) {
			delete_post_meta( $post_id, self::META_PREFIX . $lang );
			delete_post_meta( $post_id, self::URL_PREFIX . $lang );
			delete_post_meta( $post_id, self::MSG_PREFIX . $lang );
			return;
		}

		update_post_meta( $post_id, self::META_PREFIX . $lang, $mode );

		if ( 'url' === $mode && '' !== $url ) {
			update_post_meta( $post_id, self::URL_PREFIX . $lang, esc_url_raw( $url ) );
		} else {
			delete_post_meta( $post_id, self::URL_PREFIX . $lang );
		}

		if ( 'message' === $mode && '' !== trim( $msg ) ) {
			update_post_meta( $post_id, self::MSG_PREFIX . $lang, wp_kses_post( $msg ) );
		} else {
			delete_post_meta( $post_id, self::MSG_PREFIX . $lang );
		}
	}
}
