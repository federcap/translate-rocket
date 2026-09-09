<?php
/**
 * Admin-side tidiness for independent page copies.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Copies;
use TranslateRocket\Settings;
use TranslateRocket\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps independent copies (see TranslateRocket\Copies) well-behaved in wp-admin:
 *  - they stay drafts, so they never leak as stand-alone public pages even if the
 *    owner hits "Publish" while editing (they're served automatically at /xx/);
 *  - they're hidden from the Posts/Pages list so they don't clutter it;
 *  - deleting a source removes its copies, and deleting a copy unlinks it;
 *  - editing a copy shows a notice explaining what it is, with a link to its source.
 */
class CopyAdmin {

	/**
	 * Hook into wp-admin.
	 */
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'keep_draft' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
		add_action( 'pre_get_posts', array( $this, 'hide_from_list' ) );
		add_action( 'admin_notices', array( $this, 'edit_notice' ) );
	}

	/**
	 * Force copies to stay drafts: they're served automatically under their /xx/ URL,
	 * so they must never become public pages of their own.
	 *
	 * @param array $data    Sanitised post data heading for the DB.
	 * @param array $postarr Raw post array (has the ID on updates).
	 * @return array
	 */
	public function keep_draft( $data, $postarr ) {
		$id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $id <= 0 || ! isset( $data['post_status'] ) ) {
			return $data;
		}
		if ( in_array( $data['post_status'], array( 'publish', 'pending', 'future', 'private' ), true ) && Copies::is_copy( $id ) ) {
			$data['post_status'] = 'draft';
		}
		return $data;
	}

	/**
	 * Cascade: deleting a source removes its copies; deleting a copy unlinks it from
	 * its source so no dangling link is left behind.
	 *
	 * @param int $post_id Post being permanently deleted.
	 */
	public function on_delete( $post_id ): void {
		$post_id = (int) $post_id;

		// Source deleted -> drop its copies (unlink first to avoid re-entrancy here).
		foreach ( (array) Settings::get()['target_languages'] as $lang ) {
			$lang = (string) $lang;
			$cid  = Copies::copy_id( $post_id, $lang );
			if ( $cid > 0 && $cid !== $post_id ) {
				delete_post_meta( $post_id, Copies::META_COPY . $lang );
				delete_post_meta( $post_id, Copies::META_ACTIVE . $lang );
				delete_post_meta( $post_id, Copies::META_SYNCED . $lang );
				wp_delete_post( $cid, true );
			}
		}

		// Copy deleted -> remove its source's link to it.
		if ( Copies::is_copy( $post_id ) ) {
			$src  = Copies::source_of( $post_id );
			$lang = Copies::lang_of( $post_id );
			if ( $src > 0 && '' !== $lang ) {
				delete_post_meta( $src, Copies::META_COPY . $lang );
				delete_post_meta( $src, Copies::META_ACTIVE . $lang );
				delete_post_meta( $src, Copies::META_SYNCED . $lang );
			}
		}
	}

	/**
	 * Hide copies from the main Posts/Pages list so it isn't cluttered with our
	 * drafts (they're managed from the visual editor's toolbar).
	 *
	 * @param \WP_Query $query Current query.
	 */
	public function hide_from_list( $query ): void {
		if ( ! is_admin() || ! ( $query instanceof \WP_Query ) || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'     => Copies::META_SOURCE,
			'compare' => 'NOT EXISTS',
		);
		$query->set( 'meta_query', $meta );
	}

	/**
	 * On the copy's edit screen, explain what it is and link back to the source.
	 */
	public function edit_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( $id <= 0 || ! Copies::is_copy( $id ) ) {
			return;
		}
		$src  = Copies::source_of( $id );
		$lang = Copies::lang_of( $id );
		$name = $src > 0 ? get_the_title( $src ) : '';

		echo '<div class="notice notice-info"><p>';
		printf(
			/* translators: 1: language name, 2: source page title (linked). */
			esc_html__( '🚀 This is an independent %1$s copy of %2$s, managed by TranslateRocket. It is served automatically at its %1$s URL — you don\'t need to publish it.', 'translate-rocket' ),
			'<strong>' . esc_html( Languages::label( $lang ) ) . '</strong>',
			$src > 0
				? '<a href="' . esc_url( (string) get_edit_post_link( $src ) ) . '">' . esc_html( $name ) . '</a>'
				: esc_html__( '(the original page)', 'translate-rocket' )
		);
		echo '</p></div>';
	}
}
