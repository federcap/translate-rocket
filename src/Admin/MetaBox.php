<?php
/**
 * Interactive per-page translation panel in the post/page editor (AJAX).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Settings;
use TranslateRocket\Languages;
use TranslateRocket\Strings;
use TranslateRocket\Slugs;
use TranslateRocket\Translator;
use TranslateRocket\Exclusions;
use TranslateRocket\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * A self-contained panel inside the editor: pick a language, copy-paste with
 * Google, or edit each string by hand — all saved instantly via AJAX, without
 * leaving the page or nesting a form inside the post form.
 */
class MetaBox {

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_trrocket_panel', array( $this, 'ajax' ) );
	}

	/**
	 * Register the meta box on public post types.
	 */
	public function add(): void {
		foreach ( array( 'post', 'page' ) as $type ) {
			add_meta_box(
				'trrocket-metabox',
				__( 'TranslateRocket', 'translate-rocket' ),
				array( $this, 'render' ),
				$type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Enqueue the panel script on the editor screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'trrocket-metabox',
			TRROCKET_URL . 'assets/css/metabox.css',
			array( 'dashicons' ),
			\TranslateRocket\Plugin::asset_ver( 'assets/css/metabox.css' )
		);
		wp_enqueue_script(
			'trrocket-metabox',
			TRROCKET_URL . 'assets/js/metabox.js',
			array(),
			\TranslateRocket\Plugin::asset_ver( 'assets/js/metabox.js' ),
			true
		);
		wp_enqueue_script(
			'trrocket-admin-info',
			TRROCKET_URL . 'assets/js/admin-info.js',
			array(),
			\TranslateRocket\Plugin::asset_ver( 'assets/js/admin-info.js' ),
			true
		);
		wp_localize_script(
			'trrocket-metabox',
			'TRRocketPanel',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'trrocket_panel' ),
				'source'  => (string) ( Settings::get()['source_language'] ?? 'en' ),
				'autoG'   => \TranslateRocket\GoogleFree::auto_enabled(),
				'i18n'    => array(
						'gt_tip'        => __( 'Translate this phrase with Google (free)', 'translate-rocket' ),
						'gt_open'       => __( 'Open this phrase in Google Translate ↗', 'translate-rocket' ),
					'loading'       => __( 'Loading…', 'translate-rocket' ),
					'error'         => __( 'Something went wrong.', 'translate-rocket' ),
					'done'          => __( 'translated', 'translate-rocket' ),
					'missing'       => __( 'to translate', 'translate-rocket' ),
					'ai'            => __( 'Translate with AI', 'translate-rocket' ),
						'gtall'         => __( 'Translate with Google', 'translate-rocket' ),
					'visual'        => __( 'Visual editor', 'translate-rocket' ),
					'reset'         => __( 'Delete translations', 'translate-rocket' ),
					'reset_confirm' => __( 'Delete all translations of this page for this language?', 'translate-rocket' ),
					'cp_title'      => __( 'Copy & paste with Google Translate:', 'translate-rocket' ),
					'open_g'        => __( 'Open Google Translate ↗', 'translate-rocket' ),
					'redo_all'      => __( 'Show all strings (re-translate)', 'translate-rocket' ),
					'import'        => __( 'Import pasted translations', 'translate-rocket' ),
					'original'      => __( 'Original', 'translate-rocket' ),
					'translation'   => __( 'Translation', 'translate-rocket' ),
					'save'          => __( 'Save changes', 'translate-rocket' ),
					'working'       => __( 'Working…', 'translate-rocket' ),
					'saved'         => __( 'Saved.', 'translate-rocket' ),
					'unsaved'       => __( 'Unsaved changes — click “Save changes”.', 'translate-rocket' ),
					'empty'         => __( 'Paste box is empty — nothing changed.', 'translate-rocket' ),
					'slug_label'     => __( 'Slug (URL)', 'translate-rocket' ),
						'slug_suggest'   => __( 'Suggest from title', 'translate-rocket' ),
					'slug_info'      => __( 'The page web address in this language, e.g. /it/chi-siamo/. Leave empty to keep the original.', 'translate-rocket' ),
					'seo_block'      => __( 'SEO & indexing', 'translate-rocket' ),
					'seo_title'      => __( 'SEO title', 'translate-rocket' ),
					'seo_title_info' => __( 'Shown in the browser tab and as the clickable title in Google results.', 'translate-rocket' ),
					'seo_desc'       => __( 'Meta description', 'translate-rocket' ),
					'seo_desc_info'  => __( 'The short summary under the title in Google results (only if your theme or SEO plugin outputs one).', 'translate-rocket' ),
					'kw_note'        => __( 'Meta keywords are intentionally not shown.', 'translate-rocket' ),
					'kw_info'        => __( 'Search engines dropped meta keywords as a ranking signal years ago, so a keywords field would only add clutter.', 'translate-rocket' ),
					'not_detected'   => __( 'Not detected on this page.', 'translate-rocket' ),
					'content_block' => __( 'Page content', 'translate-rocket' ),
					'filter_label'  => __( 'Filter by type:', 'translate-rocket' ),
					'all_types'     => __( 'All types', 'translate-rocket' ),
					'only_missing'  => __( 'Show only missing strings', 'translate-rocket' ),
				),
			)
		);
	}

	/**
	 * Render the panel shell (the JS fills it in).
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function render( $post ): void {
		$targets = (array) Settings::get()['target_languages'];
		if ( empty( $targets ) ) {
			echo '<p class="description">' . esc_html__( 'Add target languages in TranslateRocket settings to start.', 'translate-rocket' ) . '</p>';
			return;
		}

		$langs     = array();
		$edit_urls = array();
		$router    = \TranslateRocket\Plugin::instance()->router();
		$path      = $router->canonical_path( (int) $post->ID );
		$base      = rtrim( (string) get_option( 'home' ), '/' );
		foreach ( $targets as $code ) {
			$langs[]            = array(
				'code'  => $code,
				'label' => trim( Languages::flag( $code ) . ' ' . Languages::label( $code ) ),
			);
			$edit_urls[ $code ] = add_query_arg( 'trr-edit', '1', $base . '/' . $code . $path );
		}

		echo '<div id="trrocket-panel" data-post="' . (int) $post->ID . '"'
			. ' data-ai="' . ( null !== Registry::active() ? '1' : '0' ) . '"'
			. ' data-langs="' . esc_attr( (string) wp_json_encode( $langs ) ) . '"'
			. ' data-edit-urls="' . esc_attr( (string) wp_json_encode( $edit_urls ) ) . '">'
			. '<p class="description">' . esc_html__( 'Loading…', 'translate-rocket' ) . '</p>'
			. '</div>';
	}

	/**
	 * AJAX endpoint for all panel actions.
	 */
	public function ajax(): void {
		check_ajax_referer( 'trrocket_panel' );

		$post_id = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ) );
		}

		$lang    = isset( $_POST['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		$targets = (array) Settings::get()['target_languages'];
		if ( ! in_array( $lang, $targets, true ) ) {
			wp_send_json_error( array( 'error' => 'bad-language' ) );
		}

		$action = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : 'load';
		$hash   = Strings::url_hash( $this->post_clean_path( get_post( $post_id ) ) );
		$msg    = '';

		switch ( $action ) {
			case 'save':
				$items = json_decode( isset( $_POST['items'] ) ? (string) wp_unslash( $_POST['items'] ) : '[]', true ); // phpcs:ignore WordPress.Security
				if ( is_array( $items ) ) {
					foreach ( $items as $item ) {
						if ( isset( $item['id'] ) ) {
							// Manual editor: save exactly what the user typed —
							// clearing a field intentionally empties that translation.
							$text = isset( $item['text'] ) ? sanitize_text_field( (string) $item['text'] ) : '';
							Strings::save_translation( (int) $item['id'], $lang, $text, 2 );
						}
					}
				}
				if ( isset( $_POST['slug'] ) ) {
					Slugs::set( $post_id, $lang, sanitize_text_field( wp_unslash( $_POST['slug'] ) ) );
				}
				$msg = 'saved';
				break;

			case 'import':
				$raw = isset( $_POST['text'] ) ? (string) wp_unslash( $_POST['text'] ) : ''; // phpcs:ignore WordPress.Security
				if ( '' !== trim( $raw ) ) {
					$ids = array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['cp_ids'] ?? '' ) ) ) );
					$this->import_paste( $ids, $raw, $lang );
					$msg = 'imported';
				} else {
					$msg = 'empty';
				}
				break;

			case 'ai':
				$result = Translator::translate_missing( $lang, $hash );
				$msg    = $result['ok'] ? ( 'ai:' . (int) $result['count'] ) : ( 'err:' . $result['error'] );
				break;

			case 'gt':
				if ( ! \TranslateRocket\GoogleFree::auto_enabled() ) {
					wp_send_json_error( array( 'error' => 'disabled' ) );
				}
				$text   = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
				$source = (string) ( Settings::get()['source_language'] ?? 'en' );
				$tr     = \TranslateRocket\GoogleFree::translate( $text, $source, $lang );
				if ( false !== $tr ) {
					wp_send_json_success( array( 'translation' => $tr ) );
				}
				wp_send_json_error( array( 'error' => __( 'Google Translate is unavailable right now.', 'translate-rocket' ) ) );
				break;

			case 'gt_all':
				if ( ! \TranslateRocket\GoogleFree::auto_enabled() ) {
					wp_send_json_error( array( 'error' => 'disabled' ) );
				}
				$result = Translator::google_missing( $lang, $hash );
				$msg    = $result['ok'] ? ( 'gt:' . (int) $result['count'] ) : ( 'err:' . $result['error'] );
				break;

			case 'reset':
				Strings::reset_page( $hash, $lang );
				$msg = 'reset';
				break;
		}

		\TranslateRocket\Cache::flush();
		wp_send_json_success( array_merge( array( 'msg' => $msg ), $this->payload( $hash, $lang, $post_id ) ) );
	}

	/**
	 * Build the strings + stats payload for a page+language.
	 *
	 * @return array{stats:array,strings:array}
	 */
	private function payload( string $hash, string $lang, int $post_id = 0 ): array {
		$rows    = Strings::for_page_language( $hash, $lang );
		$strings = array();
		$done    = 0;
		foreach ( $rows as $row ) {
			$translation = (string) $row->translation;
			$is_done     = ( (int) $row->status > 0 && '' !== $translation );
			if ( $is_done ) {
				++$done;
			}
			$strings[] = array(
				'id'   => (int) $row->string_id,
				'o'    => $row->original,
				't'    => $translation,
				'done' => $is_done,
				'type' => (string) $row->type,
				'ctx'  => (string) $row->context,
			);
		}
		$total = count( $strings );
		$rule  = Exclusions::get( $post_id, $lang );

		$mode_labels  = array(
			'home'    => __( 'redirect to the homepage', 'translate-rocket' ),
			'url'     => __( 'redirect to a custom URL', 'translate-rocket' ),
			'message' => __( 'show a custom message', 'translate-rocket' ),
			'404'     => __( 'show a 404 (not found)', 'translate-rocket' ),
		);
		$excluded_msg = '';
		if ( '' !== $rule['mode'] ) {
			$what = isset( $mode_labels[ $rule['mode'] ] ) ? $mode_labels[ $rule['mode'] ] : $rule['mode'];
			/* translators: %s: chosen visibility behaviour. */
			$excluded_msg = sprintf( __( 'This page is set to %s in this language, so there is nothing to translate. Change it in the “Language visibility” box.', 'translate-rocket' ), $what );
		}

		return array(
			'stats'        => array(
				'total'   => $total,
				'done'    => $done,
				'missing' => $total - $done,
			),
			'strings'      => $strings,
			'slug'         => Slugs::get( $post_id, $lang ),
			'excluded'     => '' !== $rule['mode'],
			'excluded_msg' => $excluded_msg,
		);
	}

	/**
	 * Parse a pasted Google list (numbered, order-tolerant) and save it.
	 *
	 * @param int[]  $ids  Ordered string ids that were exported.
	 * @param string $raw  Pasted text.
	 * @param string $lang Target language.
	 */
	private function import_paste( array $ids, string $raw, string $lang ): void {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$pos   = 0;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^(\d+)[\.\)]\s*(.*)$/u', $line, $m ) ) {
				$idx  = (int) $m[1] - 1;
				$text = trim( $m[2] );
			} else {
				$idx  = $pos;
				$text = $line;
			}
			++$pos;
			if ( '' !== $text && isset( $ids[ $idx ] ) && $ids[ $idx ] > 0 ) {
				Strings::save_translation( $ids[ $idx ], $lang, sanitize_text_field( $text ), 2 );
			}
		}
	}

	/**
	 * Permalink path of a post, relative to the WordPress base.
	 *
	 * @param \WP_Post|null $post Post.
	 */
	private function post_clean_path( $post ): string {
		$permalink = $post ? (string) get_permalink( $post ) : '';
		$path      = wp_parse_url( $permalink, PHP_URL_PATH );
		$path      = is_string( $path ) ? $path : '/';

		$base = wp_parse_url( get_option( 'home' ), PHP_URL_PATH );
		$base = is_string( $base ) ? rtrim( $base, '/' ) : '';
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return '/' . ltrim( (string) $path, '/' );
	}
}
