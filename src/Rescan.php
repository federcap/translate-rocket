<?php
/**
 * Pages edited after their last scan.
 *
 * The engine only detects text while a logged-in administrator looks at the
 * page. So a very quiet failure was possible, and it happened on real sites:
 * you edit a page in the block editor, you never open it again while logged
 * in, and the new sentences are never even collected. They do not appear
 * among the strings to translate, nothing warns you, and on the translated
 * pages that text simply stays in the source language — for months.
 *
 * This class writes down which pages changed after they were last read, so
 * the dashboard can say it out loud. It stores post ids only, never content.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the list of pages whose text has not been read since they changed.
 */
class Rescan {

	/**
	 * Where the list lives.
	 */
	private const OPTION = 'trrocket_pending_scan';

	/**
	 * A cap, so a bulk edit of thousands of posts cannot grow an option
	 * without end. Past this the message simply says "many".
	 */
	private const MAX = 300;

	/**
	 * Hooks. Called from the plugin bootstrap.
	 */
	public static function boot(): void {
		add_action( 'save_post', array( __CLASS__, 'changed' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'forget' ) );
	}

	/**
	 * A post was saved: note it down, unless it is one of the many saves that
	 * are not a real content change.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    The post.
	 */
	public static function changed( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		// Only what a visitor could actually read.
		$tipo = get_post_type_object( $post->post_type );
		if ( ! $tipo || empty( $tipo->public ) ) {
			return;
		}

		$lista = self::pending();
		if ( in_array( $post_id, $lista, true ) ) {
			return;
		}
		$lista[] = $post_id;
		if ( count( $lista ) > self::MAX ) {
			$lista = array_slice( $lista, -self::MAX );
		}
		update_option( self::OPTION, $lista, false );
	}

	/**
	 * The engine has just read this page: it is up to date again.
	 *
	 * @param int $post_id Post id.
	 */
	public static function forget( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}
		$lista = self::pending();
		$dopo  = array_values( array_diff( $lista, array( $post_id ) ) );
		if ( count( $dopo ) !== count( $lista ) ) {
			update_option( self::OPTION, $dopo, false );
		}
	}

	/**
	 * The pages still waiting to be read, newest last.
	 *
	 * Deleted or unpublished pages are dropped as we go: a stale id would
	 * otherwise keep the warning up for a page that no longer exists.
	 *
	 * @return int[]
	 */
	public static function pending(): array {
		$grezzo = get_option( self::OPTION, array() );
		if ( ! is_array( $grezzo ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'intval', array_filter( $grezzo ) ) ) );
	}

	/**
	 * The same list, cleaned of what is no longer publicly readable.
	 *
	 * @return int[]
	 */
	public static function pending_alive(): array {
		$vivi = array();
		foreach ( self::pending() as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
				$vivi[] = $id;
			}
		}
		return $vivi;
	}

	/**
	 * How many pages are waiting.
	 */
	public static function count(): int {
		return count( self::pending_alive() );
	}

	/**
	 * Forget everything (after a full scan).
	 */
	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}
}
