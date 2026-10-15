<?php
/**
 * Admin area controller.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Settings;
use TranslateRocket\Languages;
use TranslateRocket\Strings;
use TranslateRocket\Slugs;
use TranslateRocket\Translator;
use TranslateRocket\Providers\Registry;
use TranslateRocket\Importers\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin menu, settings form and the page-by-page translation editor.
 */
class Admin {

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_menu', array( $this, 'tidy_menu' ), 999 );
		add_action( 'admin_head', array( $this, 'menu_style' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_translations' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_translations' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_ai_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_auto_translate' ) );
		add_action( 'admin_init', array( $this, 'maybe_delete_page_translations' ) );
		add_action( 'admin_init', array( $this, 'maybe_forget_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_undo_reset' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_external' ) );
		add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_csv' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_weglot' ) );
		add_action( 'admin_init', array( $this, 'maybe_export_tmx' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_tmx' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_switcher' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_exclusions' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_memory' ) );
		add_action( 'admin_post_trrocket_auto_updates', array( NextSteps::class, 'auto_updates' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_trrocket_models', array( $this, 'ajax_models' ) );
		add_action( 'wp_ajax_trrocket_translate_step', array( $this, 'ajax_translate_step' ) );
		add_action( 'wp_ajax_trrocket_mm_email', array( $this, 'ajax_mm_email' ) );
		add_action( 'wp_ajax_trrocket_sw_preview', array( $this, 'ajax_switcher_preview' ) );
		add_action( 'wp_ajax_trrocket_scan_urls', array( $this, 'ajax_scan_urls' ) );
		add_action( 'wp_ajax_trrocket_pages_rows', array( $this, 'ajax_pages_rows' ) );
		add_action( 'wp_ajax_trrocket_sw_try', array( $this, 'ajax_switcher_try' ) );
		add_action( 'wp_ajax_trrocket_gt', array( $this, 'ajax_gt' ) );
		add_action( 'wp_ajax_trrocket_deepl_usage', array( $this, 'ajax_deepl_usage' ) );
		add_action( 'wp_ajax_trrocket_test_provider', array( $this, 'ajax_test_provider' ) );
		add_action( 'wp_ajax_trrocket_try_free_key', array( $this, 'ajax_try_free_key' ) );
		add_action( 'admin_init', array( $this, 'maybe_clear_log' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_wizard' ) );
		add_action( 'admin_init', array( $this, 'maybe_wizard_redirect' ) );
		// Editing a page's content drops the page cache: hooked in Plugin::boot()
		// (Cache::post_changed), because the block editor saves over REST.
		// Standing reminder while translations are in admin-only preview mode.
		add_action( 'admin_notices', array( $this, 'preview_mode_notice' ) );
		add_action( 'admin_notices', array( $this, 'provider_down_notice' ) );
		add_action( 'admin_notices', array( $this, 'changed_pages_notice' ) );
		add_action( 'in_admin_header', array( $this, 'quiet_wizard' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_provider_notice' ) );
	}

	/**
	 * Site-wide admin reminder that preview mode is on — without it, a site
	 * could silently stay "translated for admins only" forever.
	 */
	public function preview_mode_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! \TranslateRocket\Frontend\Preview::enabled() ) {
			return;
		}
		// Just after the other translation plugin was deactivated, Coexistence shows
		// the "go online" step instead: one banner, not two saying the same thing.
		if ( get_option( \TranslateRocket\Coexistence::PENDING ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning" data-trrocket="preview"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'TranslateRocket preview mode is on.', 'translate-rocket' ),
			esc_html__( 'Only administrators can see the translations — visitors get the default language. When you are happy with the result, switch visibility back to "Everyone" to publish.', 'translate-rocket' ),
			esc_url( admin_url( 'admin.php?page=translate-rocket' ) ),
			esc_html__( 'Open settings', 'translate-rocket' )
		);
	}

	/**
	 * Pages edited after the engine last read them.
	 *
	 * Text is detected while a logged-in administrator looks at a page. Edit a
	 * page in the block editor, never open it again, and its new sentences are
	 * never collected: they do not show up among the strings to translate, and
	 * on the translated pages that text quietly stays in the source language.
	 * Nothing used to say so. Now it does.
	 */
	public function changed_pages_notice(): void {
		// Only on the plugin's screens: it used to follow the person into Posts, WooCommerce and the
		// Dashboard after every edit (5/10/2026). «Next steps» and the Translations page say it too.
		if ( ! current_user_can( 'manage_options' ) || ! NoticeTidy::ours() ) {
			return;
		}
		// 14/10/2026 (visual check: three notices before every page, on a phone the title only half-way down):
		// the changed pages are a thing to do, counted in «N things waiting for you» on every screen and listed
		// in «Next steps». The whole notice stays only on Translations, where «Scan site» is.
		$schermata = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $schermata || false === strpos( (string) $schermata->id, 'translate-rocket-strings' ) ) {
			return;
		}
		$quante = \TranslateRocket\Rescan::count();
		if ( $quante < 1 ) {
			return;
		}
		$nomi = array();
		foreach ( array_slice( \TranslateRocket\Rescan::pending_alive(), 0, 3 ) as $id ) {
			$nomi[] = get_the_title( $id );
		}
		$elenco = implode( ', ', array_filter( $nomi ) );
		if ( $quante > count( $nomi ) ) {
			$elenco .= ' …';
		}
		printf(
			'<div class="notice notice-warning" data-trrocket="changed"><p><strong>%s</strong> %s<br><em>%s</em> <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: how many pages were edited. */
					_n(
						'%d page changed since TranslateRocket last read it.',
						'%d pages changed since TranslateRocket last read them.',
						$quante,
						'translate-rocket'
					),
					$quante
				)
			),
			// 5/10/2026: one sentence, not three lines (on a phone the notice took six).
			esc_html__( 'Their new text is not in your translations yet.', 'translate-rocket' ),
			esc_html( $elenco ),
			esc_url( admin_url( 'admin.php?page=translate-rocket-strings' ) ),
			esc_html__( 'Scan them now', 'translate-rocket' )
		);
	}

	/**
	 * Warn when an AI provider was retired mid-run (exhausted quota, refused
	 * key): the fallback chain keeps translating silently, so without this
	 * notice nobody would notice the switch. Set in Translator::first_ok().
	 */
	public function provider_down_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// 14/10/2026 (globaltoolszone): only on TranslateRocket's own screens, not on Plugins, Posts and every
		// other page of the dashboard.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'translate-rocket' ) ) {
			return;
		}
		foreach ( Registry::all() as $provider ) {
			$reason = get_transient( 'trrocket_provider_down_' . $provider->id() );
			if ( ! is_string( $reason ) || '' === $reason ) {
				continue;
			}
			$dismiss = wp_nonce_url(
				add_query_arg( 'trrocket_dismiss_provider', $provider->id() ),
				'trrocket_dismiss_provider_' . $provider->id()
			);
			// The provider's own words (in English, with its account codes) go under «Technical details».
			$plain = self::shown_error( $reason );
			printf(
				'<div class="notice notice-warning" data-trrocket="provider-down"><p><strong>%s</strong> %s<br>%s <a href="%s">%s</a></p>%s</div>',
				/* translators: %s: provider name (e.g. DeepL). */
				esc_html( sprintf( __( '%s is unavailable.', 'translate-rocket' ), $provider->label() ) ),
				esc_html__( 'Automatic translation is falling back to the next provider in your chain (or stopping if there is none).', 'translate-rocket' ),
				esc_html( $plain ),
				esc_url( $dismiss ),
				esc_html__( 'Dismiss', 'translate-rocket' ),
				$plain !== $reason ? '<details class="trr-err-details"><summary>' . esc_html__( 'Technical details', 'translate-rocket' ) . '</summary><code>' . esc_html( $reason ) . '</code></details>' : ''
			);
		}
	}

	/**
	 * Clear a provider-down notice when its Dismiss link is clicked.
	 */
	public function maybe_dismiss_provider_notice(): void {
		if ( ! isset( $_GET['trrocket_dismiss_provider'], $_GET['_wpnonce'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pid = sanitize_key( wp_unslash( $_GET['trrocket_dismiss_provider'] ) );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'trrocket_dismiss_provider_' . $pid ) ) {
			return;
		}
		delete_transient( 'trrocket_provider_down_' . $pid );
		// Remember the dismissal for 12h so an in-progress job that keeps hitting
		// the dead provider doesn't re-raise the notice on its next run.
		set_transient( 'trrocket_provider_down_ack_' . $pid, 1, 12 * HOUR_IN_SECONDS );
		wp_safe_redirect( remove_query_arg( array( 'trrocket_dismiss_provider', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * AJAX: quick one-click Google translation for the dashboard editor (free,
	 * server-side). Returns the translation for the browser to drop into a field.
	 */
	public function ajax_gt(): void {
		check_ajax_referer( 'trrocket_gt', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		}
		if ( ! \TranslateRocket\GoogleFree::auto_enabled() ) {
			wp_send_json_error( array( 'error' => 'disabled' ) );
		}
		$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$lang = isset( $_POST['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		$targets = (array) Settings::get()['target_languages'];
		if ( '' === $text || ! in_array( $lang, $targets, true ) ) {
			wp_send_json_error( array( 'error' => 'invalid' ) );
		}
		$source = (string) ( Settings::get()['source_language'] ?? 'en' );
		$tr     = \TranslateRocket\GoogleFree::translate( $text, $source, $lang );
		if ( false !== $tr ) {
			wp_send_json_success( array( 'translation' => $tr ) );
		}
		wp_send_json_error( array( 'error' => __( 'Google Translate is unavailable right now.', 'translate-rocket' ) ) );
	}

	/**
	 * The side menu keeps the screens used most (5/10/2026): it listed thirteen items that only
	 * repeated the buttons at the top of every screen of the plugin. The other screens stay where
	 * they were, same address, one click away from those buttons.
	 */
	public function tidy_menu(): void {
		global $submenu;
		// Hidden, not removed: WordPress refuses a page whose menu item is gone («Sorry, you are not
		// allowed to access this page» — seen by collaudo-menu-laterale before shipping). The items stay
		// registered and a style keeps them out of sight (see menu_style()).
		// The first item repeated the plugin's name: it is the Languages screen.
		if ( isset( $submenu['translate-rocket'] ) && is_array( $submenu['translate-rocket'] ) ) {
			foreach ( $submenu['translate-rocket'] as $i => $item ) {
				if ( isset( $item[2] ) && 'translate-rocket' === $item[2] ) {
					$submenu['translate-rocket'][ $i ][0] = __( 'Languages', 'translate-rocket' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
			}
		}
	}

	/**
	 * Flag + name as HTML. The flag is the plugin's own SVG: an emoji flag shows as two
	 * letters («GB») in Chrome and Edge on Windows, where most of our users are.
	 *
	 * @param string $code Language code.
	 */
	public static function lang_html( string $code ): string {
		return '<span class="trrocket-flag">' . \TranslateRocket\Flags::html( $code, Languages::flag( $code ) ) . '</span> ' . esc_html( Languages::label( $code ) );
	}

	/**
	 * Lower case that never breaks UTF-8. On PHP 7, strtolower() follows the server's locale and,
	 * with a single-byte one, rewrites the bytes of «Српски» or «日本語»: esc_attr() then drops the
	 * broken text and the search finds nothing (seen on the PHP 7.4 bench, 5/10/2026).
	 *
	 * @param string $s Text.
	 */
	public static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtr( $s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * The top-level items of the site's menus (classic menus in a location, Navigation
	 * menus of a block theme): the languages can go right after one of them.
	 *
	 * @return string[]
	 */
	public static function switcher_menu_labels(): array {
		$out = array();
		foreach ( (array) get_nav_menu_locations() as $menu_id ) {
			foreach ( (array) wp_get_nav_menu_items( (int) $menu_id ) as $it ) {
				if ( is_object( $it ) && 0 === (int) $it->menu_item_parent ) {
					$out[] = (string) $it->title;
				}
			}
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			foreach ( get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 20 ) ) as $nav ) {
				foreach ( parse_blocks( (string) $nav->post_content ) as $b ) {
					if ( in_array( $b['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && ! empty( $b['attrs']['label'] ) ) {
						$out[] = (string) $b['attrs']['label'];
					} elseif ( 'core/home-link' === ( $b['blockName'] ?? '' ) ) {
						$out[] = (string) ( $b['attrs']['label'] ?? __( 'Home' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- the core label.
					}
				}
			}
		}
		$out = array_map(
			static function ( $x ) {
				return trim( html_entity_decode( wp_strip_all_tags( $x ), ENT_QUOTES, 'UTF-8' ) );
			},
			$out
		);
		return array_values( array_unique( array_filter( $out, 'strlen' ) ) );
	}

	/**
	 * The menus the switcher can go in, for the Switcher page: «Automatic» first, then the
	 * theme's menu locations, then (block themes) the Navigation menus.
	 *
	 * @return array<string,string>
	 */
	public static function switcher_menu_choices(): array {
		$locs = \TranslateRocket\Frontend\Switcher::menu_locations();
		$navs = array();
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			foreach ( get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 20, 'orderby' => 'date', 'order' => 'ASC' ) ) as $nav ) {
				/* translators: %s: name of a Navigation menu of a block theme. */
				$navs[ 'nav:' . $nav->ID ] = sprintf( __( 'Navigation menu «%s»', 'translate-rocket' ), get_the_title( $nav ) ?: '#' . $nav->ID );
			}
		}
		$out  = array();
		$auto = \TranslateRocket\Frontend\Switcher::auto_location();
		if ( '' !== $auto ) {
			/* translators: %s: the menu chosen automatically. */
			$out['auto'] = sprintf( __( 'Automatic: %s', 'translate-rocket' ), $locs[ $auto ] );
		} elseif ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$out['auto'] = __( 'Automatic: the Navigation block of your header', 'translate-rocket' );
		}
		return $out ? array_merge( $out, $locs, $navs ) : array();
	}

	/**
	 * The words a language is found by: its own name, its English name, its code.
	 *
	 * @param string $code Language code.
	 * @param array  $info Native name, English name, flag.
	 */
	public static function lang_search_text( string $code, array $info ): string {
		return self::lower( trim( (string) ( $info[0] ?? '' ) . ' ' . (string) ( $info[1] ?? '' ) . ' ' . $code ) );
	}

	/**
	 * The English name next to the native one, when they differ: «Српски» alone tells
	 * nothing to whoever does not read Cyrillic (77 languages since 5/10/2026).
	 *
	 * @param array $info Native name, English name, flag.
	 */
	public static function lang_english( array $info ): void {
		$en = (string) ( $info[1] ?? '' );
		if ( '' !== $en && 0 !== strcasecmp( $en, (string) ( $info[0] ?? '' ) ) ) {
			echo ' <span class="trr-lang-en">' . esc_html( $en ) . '</span>';
		}
	}

	/**
	 * «Српски (Serbian)» for a drop-down, where markup cannot go.
	 *
	 * @param array $info Native name, English name, flag.
	 */
	public static function lang_option_label( array $info ): string {
		$en = (string) ( $info[1] ?? '' );
		return ( '' !== $en && 0 !== strcasecmp( $en, (string) ( $info[0] ?? '' ) ) ) ? $info[0] . ' (' . $en . ')' : (string) $info[0];
	}

	/**
	 * A box that filters the language grid that follows it, as you type.
	 */
	public static function lang_search_box(): void {
		echo '<p class="trr-lang-cerca"><input type="search" class="trr-lang-cerca-in" placeholder="' . esc_attr__( 'Find a language…', 'translate-rocket' ) . '" aria-label="' . esc_attr__( 'Find a language…', 'translate-rocket' ) . '" autocomplete="off" />'
			. ' <span class="trr-lang-cerca-nulla" hidden>' . esc_html__( 'No language matches.', 'translate-rocket' ) . '</span></p>';
		static $script = false;
		if ( $script ) {
			return;
		}
		$script = true;
		?>
		<script>
		( function () {
			document.addEventListener( 'input', function ( ev ) {
				var box = ev.target;
				if ( ! box.classList || ! box.classList.contains( 'trr-lang-cerca-in' ) ) { return; }
				var grid = box.closest( 'p' ).nextElementSibling;
				while ( grid && ! grid.classList.contains( 'trrocket-lang-grid' ) ) { grid = grid.nextElementSibling; }
				if ( ! grid ) { return; }
				var q = box.value.trim().toLowerCase(), shown = 0;
				grid.querySelectorAll( '.trrocket-lang-item' ).forEach( function ( it ) {
					// the site's own language stays hidden in the wizard grid whatever is typed
					if ( it.style.display === 'none' && ! it.hasAttribute( 'data-cerca-nascosta' ) ) { return; }
					var ok = ! q || ( it.getAttribute( 'data-cerca' ) || '' ).indexOf( q ) !== -1;
					if ( ok ) { it.removeAttribute( 'data-cerca-nascosta' ); it.style.display = ''; shown++; }
					else { it.setAttribute( 'data-cerca-nascosta', '1' ); it.style.display = 'none'; }
				} );
				var none = box.closest( 'p' ).querySelector( '.trr-lang-cerca-nulla' );
				if ( none ) { none.hidden = shown > 0; }
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Screens kept out of the side menu (still open from the buttons at the top of the plugin).
	 */
	const MENU_HIDDEN = array( 'translate-rocket-memory', 'translate-rocket-import', 'translate-rocket-exclusions', 'translate-rocket-copies', 'translate-rocket-diagnostics', 'translate-rocket-wizard', 'translate-rocket-help' );

	/**
	 * The style that keeps MENU_HIDDEN out of the side menu, on every admin screen (the menu is
	 * on every one). The screen being shown stays visible, so the menu never loses its place.
	 */
	public function menu_style(): void {
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$rules   = array();
		foreach ( self::MENU_HIDDEN as $slug ) {
			if ( $slug !== $current ) {
				$rules[] = '#toplevel_page_translate-rocket .wp-submenu a[href$="page=' . $slug . '"]';
			}
		}
		if ( $rules ) {
			echo '<style id="trr-menu">' . implode( ',', $rules ) . '{display:none!important}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed slugs.
		}
	}

	/**
	 * Admin menu + submenu.
	 */
	public function menu(): void {
		add_menu_page(
			__( 'TranslateRocket', 'translate-rocket' ),
			__( 'TranslateRocket', 'translate-rocket' ),
			'manage_options',
			'translate-rocket',
			array( $this, 'render_settings_page' ),
			'dashicons-translation',
			58
		);

		// Per prima, con un numero quando qualcosa aspetta: e' il faro per chi si e'
		// perso. Il conto sta in un transient, questa riga gira su OGNI pagina admin.
		try {
			$in_attesa = \TranslateRocket\Admin\NextSteps::pending();
		} catch ( \Throwable $e ) {
			$in_attesa = 0;   // senza numero, non senza menu.
		}
		add_submenu_page(
			'translate-rocket',
			__( 'What to do next', 'translate-rocket' ),
			__( 'Next steps', 'translate-rocket' ) . ( $in_attesa > 0 ? ' <span class="update-plugins count-' . (int) $in_attesa . '"><span class="plugin-count">' . (int) $in_attesa . '</span></span>' : '' ),
			'manage_options',
			'translate-rocket-next',
			array( '\\TranslateRocket\\Admin\\NextSteps', 'page' )
		);

		add_submenu_page(
			'translate-rocket',
			__( 'Translations', 'translate-rocket' ),
			__( 'Translations', 'translate-rocket' ),
			'manage_options',
			'translate-rocket-strings',
			array( $this, 'render_editor' )
		);

		add_submenu_page(
			'translate-rocket',
			__( 'Translation Memory', 'translate-rocket' ),
			__( 'Memory', 'translate-rocket' ),
			'manage_options',
			'translate-rocket-memory',
			array( $this, 'render_memory_page' )
		);

		add_submenu_page(
			'translate-rocket',
			__( 'AI Translation', 'translate-rocket' ),
			__( 'AI Translation', 'translate-rocket' ),
			'manage_options',
			'translate-rocket-ai',
			array( $this, 'render_ai_page' )
		);

		add_submenu_page(
			'translate-rocket',
			__( 'Import', 'translate-rocket' ),
			__( 'Import', 'translate-rocket' ),
			'manage_options',
			'translate-rocket-import',
			array( $this, 'render_import_page' )
		);

		add_submenu_page(
			'translate-rocket',
			__( 'Switcher', 'translate-rocket' ),
			__( 'Switcher', 'translate-rocket' ),
			'manage_options',
			'translate-rocket-switcher',
			array( $this, 'render_switcher_page' )
		);

		add_submenu_page(
			'translate-rocket',
			__( 'Exclusions', 'translate-rocket' ),
			__( 'Exclusions', 'translate-rocket' ),
			'manage_options',
			'translate-rocket-exclusions',
			array( $this, 'render_exclusions_page' )
		);

		add_submenu_page(
			'translate-rocket',
			__( 'Independent copies', 'translate-rocket' ),
			__( 'Independent copies', 'translate-rocket' ),
			'manage_options',
			'translate-rocket-copies',
			array( $this, 'render_copies_page' )
		);

		add_submenu_page(
			'translate-rocket',
			__( 'Diagnostics', 'translate-rocket' ),
			__( 'Diagnostics', 'translate-rocket' ),
			'manage_options',
			'translate-rocket-diagnostics',
			array( $this, 'render_diagnostics_page' )
		);

		add_submenu_page(
			'translate-rocket',
			__( 'Setup wizard', 'translate-rocket' ),
			__( 'Setup wizard', 'translate-rocket' ),
			'manage_options',
			'translate-rocket-wizard',
			array( $this, 'render_wizard_page' )
		);
	}

	/**
	 * After activation, send the admin to the setup wizard once.
	 */
	public function maybe_wizard_redirect(): void {
		if ( ! get_transient( 'trrocket_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'trrocket_activation_redirect' );
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=translate-rocket-wizard' ) );
		exit;
	}

	/**
	 * Enqueue admin CSS on our pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( string $hook ): void {
		if ( false === strpos( $hook, 'translate-rocket' ) ) {
			return;
		}
		wp_enqueue_style(
			'trrocket-admin',
			TRROCKET_URL . 'assets/css/admin.css',
			array(),
			\TranslateRocket\Plugin::asset_ver( 'assets/css/admin.css' )
		);
		wp_enqueue_script(
			'trrocket-admin-info',
			TRROCKET_URL . 'assets/js/admin-info.js',
			array(),
			\TranslateRocket\Plugin::asset_ver( 'assets/js/admin-info.js' ),
			true
		);
		// 14/10/2026: every provider test shows the plain sentence and, folded, what the provider really answered;
		// when our own site does not answer (a gateway, the host's time limit) it says that, with the code.
		wp_add_inline_script(
			'trrocket-admin-info',
			'window.TRRocketSito=' . wp_json_encode( __( 'Your own site did not answer the request (%s): reload the page and try again; if it repeats, ask your host.', 'translate-rocket' ) ) . ';'
			. 'window.TRRocketDettagli=function(out,det){if(!out||!det){return;}var d=document.createElement("details"),s=document.createElement("summary"),c=document.createElement("code");'
			. 's.textContent=' . wp_json_encode( __( 'Technical details', 'translate-rocket' ) ) . ';c.textContent=det;c.style.cssText="display:block;white-space:pre-wrap;word-break:break-all;margin-top:4px;font-size:11px";'
			. 'd.style.marginTop="4px";d.appendChild(s);d.appendChild(c);out.appendChild(d);};',
			'before'
		);

		// The Languages page lets you upload a custom flag image (wp.media), and
		// carries the green/grey online switch beside every language.
		if ( false !== strpos( $hook, 'toplevel_page_translate-rocket' ) ) {
			wp_enqueue_media();
			wp_enqueue_script(
				'trrocket-lang-online',
				TRROCKET_URL . 'assets/js/lang-online.js',
				array(),
				\TranslateRocket\Plugin::asset_ver( 'assets/js/lang-online.js' ),
				true
			);
		}

		if ( false !== strpos( $hook, 'translate-rocket-switcher' ) ) {
			wp_enqueue_style(
				'trrocket-frontend',
				TRROCKET_URL . 'assets/css/frontend.css',
				array(),
				\TranslateRocket\Plugin::asset_ver( 'assets/css/frontend.css' )
			);
			wp_enqueue_script(
				'trrocket-switcher-admin',
				TRROCKET_URL . 'assets/js/switcher-admin.js',
				array(),
				\TranslateRocket\Plugin::asset_ver( 'assets/js/switcher-admin.js' ),
				true
			);
			wp_localize_script(
				'trrocket-switcher-admin',
				'TRRocketSwPreview',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'trrocket_sw_preview' ),
					'empty'   => __( 'Add target languages to see the preview.', 'translate-rocket' ),
				)
			);
		}

		if ( false !== strpos( $hook, 'translate-rocket-ai' ) ) {
			wp_enqueue_script(
				'trrocket-ai-models',
				TRROCKET_URL . 'assets/js/ai-models.js',
				array(),
				\TranslateRocket\Plugin::asset_ver( 'assets/js/ai-models.js' ),
				true
			);
			wp_localize_script(
				'trrocket-ai-models',
				'TRRocketModels',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'trrocket_models' ),
					'i18n'    => array(
						'loading' => __( 'Loading…', 'translate-rocket' ),
						'none'    => __( 'No models found — check the API key for this provider.', 'translate-rocket' ),
						'noKey'   => __( 'Paste your API key in the field above first: the list comes from the provider, and it only answers with a key.', 'translate-rocket' ),
						'refused' => __( 'The provider refused this API key. Check that you copied it whole, with no spaces, and that it is still active.', 'translate-rocket' ),
						'busy'    => __( 'The provider says too many requests right now. Wait a minute and try again.', 'translate-rocket' ),
						'net'     => __( 'Your site could not reach the provider (network or firewall). Try again later, or ask your host.', 'translate-rocket' ),
						'provErr' => __( 'The provider answered with an error. Try again in a few minutes.', 'translate-rocket' ),
						/* translators: %d: number of AI models found. */
						'found'   => __( '%d models available — pick one from the list.', 'translate-rocket' ),
						'pick'    => __( '— pick a model —', 'translate-rocket' ),
						'chars'   => __( 'characters used', 'translate-rocket' ),
						'details' => __( 'Technical details', 'translate-rocket' ),
						/* translators: %s: the technical reason, e.g. HTTP 524. */
						'site'    => __( 'Your own site did not answer the request (%s): reload the page and try again; if it repeats, ask your host.', 'translate-rocket' ),
					),
				)
			);
		}

		if ( false !== strpos( $hook, 'translate-rocket-strings' ) ) {
			wp_enqueue_script(
				'trrocket-scan',
				TRROCKET_URL . 'assets/js/scan.js',
				array(),
				\TranslateRocket\Plugin::asset_ver( 'assets/js/scan.js' ),
				true
			);
			wp_localize_script(
				'trrocket-scan',
				'TRRocketScan',
				array(
					// 14/10/2026 (a customer with 5,600 pages saw 1,001): the first block comes with the page,
					// the rest is asked for block by block while the scan runs (ajax_scan_urls).
					'urls'  => array_merge( array( home_url( '/' ) ), self::scannable_urls( 0 ) ),
					'total' => self::scannable_total(),
					'block' => self::SCAN_BLOCK,
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'trrocket_scan_urls' ),
					'i18n'  => array(
						'confirm'  => __( 'Scan all published pages to detect translatable text? Keep this tab open until it finishes.', 'translate-rocket' ),
						'scanning' => __( 'Scanning', 'translate-rocket' ),
						'done'     => __( 'Scan complete — reloading…', 'translate-rocket' ),
						'none'     => __( 'No published pages found to scan.', 'translate-rocket' ),
					),
				)
			);
		}
	}

	/** Addresses handed to the scan at a time. */
	const SCAN_BLOCK = 500;

	/**
	 * The content types the scan visits: every public one with its own pages (posts, pages,
	 * WooCommerce products, events…), not media files.
	 *
	 * @return string[]
	 */
	private static function scannable_types(): array {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		return $types ? $types : array( 'page', 'post' );
	}

	/**
	 * How many published items the scan visits (the home page not counted).
	 */
	private static function scannable_total(): int {
		$n = 0;
		foreach ( self::scannable_types() as $t ) {
			$c  = wp_count_posts( $t );
			$n += (int) ( $c->publish ?? 0 );
		}
		return $n;
	}

	/**
	 * Front-end URLs (source language) of one block of published items, for the
	 * one-click site scan. The browser fetches each so the engine collects its
	 * strings — no server-side self-requests (which would stall few-worker hosts).
	 * Before 14/10/2026 it stopped at 1,000: a site with 5,600 pages saw 1,001 and
	 * the rest stayed at 0/0.
	 *
	 * @param int $offset Items already handed out.
	 * @return string[]
	 */
	private static function scannable_urls( int $offset ): array {
		$urls = array();
		$ids  = get_posts(
			array(
				'post_type'        => self::scannable_types(),
				'post_status'      => 'publish',
				'numberposts'      => self::SCAN_BLOCK,
				'offset'           => max( 0, $offset ),
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( (array) $ids as $id ) {
			$u = get_permalink( (int) $id );
			if ( is_string( $u ) && '' !== $u ) {
				$urls[] = $u;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * AJAX: the next block of addresses for the site scan.
	 */
	public function ajax_scan_urls(): void {
		check_ajax_referer( 'trrocket_scan_urls' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ) );
		}
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		wp_send_json_success( array( 'urls' => self::scannable_urls( $offset ) ) );
	}

	/**
	 * AJAX: return the live list of models a provider's key can use.
	 */
	public function ajax_models(): void {
		check_ajax_referer( 'trrocket_models' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ) );
		}
		$id  = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : '';
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

		$provider = ( '' !== $key )
			? \TranslateRocket\Providers\Registry::build( $id, array( 'api_key' => $key ) )
			: \TranslateRocket\Providers\Registry::get( $id );

		if ( null === $provider ) {
			wp_send_json_error( array( 'error' => 'unknown-provider' ) );
		}
		// Nothing typed and nothing saved: there is no key to ask the provider with.
		if ( ! $provider->is_configured() ) {
			wp_send_json_error( array( 'error' => 'no-key' ) );
		}
		$models = $provider->list_models();
		if ( empty( $models ) ) {
			$why = method_exists( $provider, 'models_error' ) ? (string) $provider->models_error() : '';
			$raw = method_exists( $provider, 'models_raw' ) ? (string) $provider->models_raw() : '';
			// 14/10/2026 (Federico: «show the available models, check the servers' answers, timeouts…»): the same
			// plain sentence as everywhere else, with its HTTP code, and the answer itself for «Technical details».
			// A 200 whose body is not JSON (a proxy's page) is not «no models»: it is an unreadable answer.
			if ( '' === $why && '' !== $raw && null === json_decode( (string) preg_replace( '/^HTTP 200: /', '', $raw ), true ) ) {
				$raw = (string) preg_replace( '/^HTTP 200: /', 'HTTP 200, unreadable answer: ', $raw );
				$why = 'provider-error';
			}
			$frase = '' !== $raw && '' !== $why ? self::plain_provider_error( $raw ) : '';
			if ( in_array( $why, array( 'http-401', 'http-403' ), true ) && 0 === strpos( $frase, __( 'The provider refused this API key. Check that you copied it whole, with no spaces, and that it is still active.', 'translate-rocket' ) ) ) {
				$why = 'key-refused';
			} elseif ( 'http-429' === $why ) {
				$why = 'busy';
			} elseif ( '' === $why ) {
				$why = 'no-models';
			} elseif ( 'net' !== $why ) {
				$why = 'provider-error';
			}
			wp_send_json_error(
				array(
					'error'   => $why,
					'message' => $frase,
					'detail'  => $raw,
				)
			);
		}
		wp_send_json_success( array( 'models' => array_values( $models ) ) );
	}

	/**
	 * AJAX: DeepL is the one provider that reports remaining quota — fetch its
	 * /v2/usage so the AI page can show characters used / limit for this period.
	 */
	public function ajax_deepl_usage(): void {
		check_ajax_referer( 'trrocket_models' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ) );
		}
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' === $key ) {
			$key = (string) ( Settings::get()['providers']['deepl']['api_key'] ?? '' );
		}
		if ( '' === $key ) {
			wp_send_json_error( array( 'error' => __( 'Add your DeepL key first.', 'translate-rocket' ) ) );
		}
		$base = ( ':fx' === substr( $key, -3 ) ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
		$res  = wp_remote_get(
			$base . '/v2/usage',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'DeepL-Auth-Key ' . $key ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			wp_send_json_error( array( 'error' => __( 'Could not reach DeepL — check the key.', 'translate-rocket' ) ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || ! isset( $data['character_count'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Unexpected response from DeepL.', 'translate-rocket' ) ) );
		}
		wp_send_json_success(
			array(
				'used'  => (int) $data['character_count'],
				'limit' => (int) ( $data['character_limit'] ?? 0 ),
			)
		);
	}

	/**
	 * AJAX: live "Test connection" for a provider — translates one short word so
	 * the Diagnostics page can confirm the key actually works end to end.
	 */
	public function ajax_test_provider(): void {
		check_ajax_referer( 'trrocket_models' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ) );
		}
		$id       = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : '';
		$provider = ( '' !== $id )
			? \TranslateRocket\Providers\Registry::get( $id )
			: \TranslateRocket\Providers\Registry::active();
		if ( null === $provider ) {
			wp_send_json_error( array( 'error' => __( 'No AI provider is configured.', 'translate-rocket' ) ) );
		}
		if ( ! $provider->is_configured() ) {
			// 9/10/2026 (a customer's site): OpenRouter had its key, but today's 50 free requests were
			// used up, and the test said «Add the API key first».
			if ( '' !== (string) ( Settings::get()['providers'][ $id ]['api_key'] ?? '' ) && \TranslateRocket\Providers\FreeUsage::exhausted( $id ) ) {
				wp_send_json_error( array( 'error' => __( 'The key is there, but today\'s free requests are used up. It works again tomorrow; meanwhile the next engine in the order takes over.', 'translate-rocket' ) ) );
			}
			wp_send_json_error( array( 'error' => __( 'Add the API key first.', 'translate-rocket' ) ) );
		}
		if ( 'cloudflare' === $id ) {
			$tutto = Settings::get();
			$acc   = (string) ( $tutto['providers']['cloudflare']['account'] ?? '' );
			if ( ! \TranslateRocket\Providers\CloudflareProvider::valid_account( $acc ) ) {
				// Try to find it from the token; if that fails, no translation request at all.
				$trovato = \TranslateRocket\Providers\CloudflareProvider::find_account( (string) ( $tutto['providers']['cloudflare']['api_key'] ?? '' ) );
				if ( '' === $trovato ) {
					wp_send_json_error( array( 'error' => self::plain_provider_error( 'Could not route' ) ) );
				}
				$tutto['providers']['cloudflare']['account'] = $trovato;
				Settings::update( $tutto );
				$provider = \TranslateRocket\Providers\Registry::build( 'cloudflare', (array) $tutto['providers']['cloudflare'] );
			}
		}
		$source  = \TranslateRocket\Plugin::instance()->router()->default_language();
		$target  = '';
		foreach ( (array) Settings::get()['target_languages'] as $t ) {
			if ( (string) $t !== $source ) {
				$target = (string) $t;
				break;
			}
		}
		if ( '' === $target ) {
			$target = ( 'en' === $source ) ? 'it' : 'en';
		}
		// 9/10/2026 (Federico: «ci vorrebbe un testo test api»): a short text of your choice, so you
		// see the quality too, not only that the key works. Never more than 200 characters.
		$prova = isset( $_POST['text'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['text'] ) ) ) : '';
		$prova = '' !== $prova ? mb_substr( $prova, 0, 200 ) : 'Hello';
		if ( method_exists( $provider, 'quick' ) ) {
			$provider->quick(); // the owner is waiting: one attempt, 25 s at most (14/10/2026)
		}
		$res   = $provider->translate( array( $prova ), $source, $target );
		$prove = (array) get_option( 'trrocket_provider_tests', array() );
		if ( $res->success && ! empty( $res->translations ) ) {
			// «dove è testata rimane uno spunto verde»: remembered with a fingerprint of key and
			// model, so the tick disappears by itself when either changes.
			$prove[ $id ] = array(
				'ok'   => 1,
				'when' => time(),
				'fp'   => self::provider_fingerprint( $id ),
			);
			update_option( 'trrocket_provider_tests', $prove, false );
			wp_send_json_success(
				array(
					'source' => $prova,
					'sample' => (string) $res->translations[0],
					'target' => $target,
					'when'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
				)
			);
		}
		unset( $prove[ $id ] );
		update_option( 'trrocket_provider_tests', $prove, false );
		\TranslateRocket\Logger::error(
			'ai',
			/* translators: %s: provider name. */
			sprintf( __( 'Connection test failed for %s.', 'translate-rocket' ), $provider->label() ),
			$res->error
		);
		wp_send_json_error(
			array(
				'error'  => '' !== $res->error ? self::shown_error( $res->error ) : __( 'The provider did not return a translation.', 'translate-rocket' ),
				'detail' => (string) $res->error,
			)
		);
	}

	/**
	 * Key, model and account of a provider, hashed: the green tick holds only for these.
	 *
	 * @param string $id Provider id.
	 */
	public static function provider_fingerprint( string $id ): string {
		$conf = (array) ( Settings::get()['providers'][ $id ] ?? array() );
		return md5( (string) ( $conf['api_key'] ?? '' ) . '|' . (string) ( $conf['model'] ?? '' ) . '|' . (string) ( $conf['account'] ?? '' ) );
	}

	/**
	 * Was this provider tested successfully with its current key and model? Returns the time, or 0.
	 *
	 * @param string $id Provider id.
	 */
	public static function provider_tested( string $id ): int {
		$t = (array) ( get_option( 'trrocket_provider_tests', array() )[ $id ] ?? array() );
		return ( ! empty( $t['ok'] ) && ( $t['fp'] ?? '' ) === self::provider_fingerprint( $id ) ) ? (int) $t['when'] : 0;
	}

	/**
	 * A provider's error put in words a site owner understands (see below).
	 *
	 * @param string $raw The provider's error.
	 */
	public static function test_sentence( string $lang ): string {
		$frasi = array(
			'en' => 'Welcome to our website: book your stay today.',
			'it' => 'Benvenuti nel nostro sito: prenota oggi il tuo soggiorno.',
			'es' => 'Bienvenido a nuestra web: reserva hoy tu estancia.',
			'fr' => 'Bienvenue sur notre site : réservez votre séjour dès aujourd’hui.',
			'de' => 'Willkommen auf unserer Website: buchen Sie Ihren Aufenthalt noch heute.',
			'pt' => 'Bem-vindo ao nosso site: reserve hoje a sua estadia.',
			'nl' => 'Welkom op onze website: boek vandaag nog je verblijf.',
			'pl' => 'Witamy na naszej stronie: zarezerwuj pobyt już dziś.',
			'ru' => 'Добро пожаловать на наш сайт: забронируйте отдых уже сегодня.',
			'ja' => '私たちのサイトへようこそ。今すぐご宿泊をご予約ください。',
		);
		$base = strtolower( substr( $lang, 0, 2 ) );
		return $frasi[ $base ] ?? $frasi['en'];
	}

	/**
	 * A provider's error put in words a site owner understands. The raw answer («HTTP 401: { "error":
	 * { "message": "Incorrect API key…» in English and JSON) used to be shown as it was (5/10/2026); it
	 * stays in Diagnostics → log, written just before. Same sentences as «Load available models».
	 *
	 * @param string $raw The provider's error.
	 */
	public static function plain_provider_error( string $raw ): string {
		// 9/10/2026 (a customer's site: the e-mail address typed as Cloudflare Account ID → «HTTP 404: Could
		// not route to /client/v4/accounts/…», shown as «try again in a few minutes», which was wrong).
		if ( preg_match( '/Could not route|object identifier is invalid|accounts\/[^\/]*\/ai\/run.*404|HTTP 404.*accounts/i', $raw ) ) {
			return __( 'The Cloudflare Account ID is not right. It is a code of 32 letters and numbers, shown on the right of the Workers AI page in Cloudflare (not your e-mail address).', 'translate-rocket' );
		}
		// 13/10/2026 (ticket from a server in China): the words of the answer used to decide, so a 403 «country not
		// supported», a firewall's 403 page or a 500 mentioning «authentication» all read «the provider refused this
		// API key». Now the HTTP code decides first, and is shown, so the real cause can be found.
		$codice = preg_match( '/HTTP (\d{3})\b/', $raw, $m ) ? (int) $m[1] : 0;
		$corpo  = trim( (string) preg_replace( '/^.*?HTTP \d{3}:?/s', '', $raw ) );
		$html   = (bool) preg_match( '/<(!doctype|html|head|body|title)\b/i', $corpo );
		$chiave = __( 'The provider refused this API key. Check that you copied it whole, with no spaces, and that it is still active.', 'translate-rocket' );
		$con    = static function ( string $frase ) use ( $codice ): string {
			return $codice > 0 ? $frase . ' (HTTP ' . $codice . ')' : $frase;
		};
		$cerca  = static function ( string $re ) use ( $raw ): bool {
			return (bool) preg_match( $re, $raw );
		};
		// 14/10/2026 (Federico: «every answer of the servers handled well»): what a 200 can hide first.
		if ( $cerca( '/safety filter|content_filter|blockReason|PROHIBITED_CONTENT|\(refusal\)/i' ) ) {
			return $con( __( 'The provider refused to translate this text because of its safety filter (it judged the content sensitive). Translate this sentence by hand, or with another provider.', 'translate-rocket' ) );
		}
		if ( $cerca( '/unreadable answer|empty response|unexpected response|could not match \d+ items|item count mismatch/i' ) ) {
			if ( $html ) {
				return $con( __( 'Instead of the provider\'s answer, your server received a web page (often a proxy, a firewall or a login page of the network). Ask your host to let your site reach the provider directly.', 'translate-rocket' ) );
			}
			return $con( __( 'The provider answered, but not with a translation in the expected form. Try again; if it happens again, choose another model or another provider.', 'translate-rocket' ) );
		}
		$credito = __( 'The provider\'s account has no credit left, or its free share is used up. Add credit or check the plan on the provider\'s site, or let the next engine take over.', 'translate-rocket' );
		$modello = __( 'The chosen model does not exist or this key cannot use it. Press «Load available models» and pick one from the list.', 'translate-rocket' );
		$lungo   = __( 'The text sent was too long for this model. Choose fewer sentences per request (AI Translation → Usage & limits), or a model with a larger context.', 'translate-rocket' );
		$tempo   = __( 'The provider did not answer within the time limit (timeout). It may be slow or far from your server: try again, or choose fewer sentences per request (AI Translation → Usage & limits).', 'translate-rocket' );
		if ( 401 === $codice ) {
			return $con( $chiave );
		}
		if ( 402 === $codice ) {
			return $con( $credito );
		}
		if ( 403 === $codice ) {
			if ( preg_match( '/country|region|territor|unsupported_country|not available in your (location|area)|location is not supported/i', $corpo ) ) {
				return $con( __( 'The provider is not available from the country or region where your server is. Use another provider, or ask your host about a server in another region.', 'translate-rocket' ) );
			}
			if ( $html ) {
				return $con( __( 'Something between your server and the provider blocked the request (a firewall or a proxy) before it reached the provider. Ask your host to allow it, or use another provider.', 'translate-rocket' ) );
			}
			// DeepL answers a wrong key with a bare JSON «Forbidden» pointing to its /auth docs.
			if ( preg_match( '/api[_ ]?key|invalid[_ ]?key|auth[_ ]?key|authori[sz]ation|credential|unauthenticated|permission[_ ]denied.*key|\bauth\b|"forbidden/i', $corpo ) ) {
				return $con( $chiave );
			}
			return $con( __( 'The provider refused the request. The key may not have access to this model, or the account needs attention: check it on the provider\'s site.', 'translate-rocket' ) );
		}
		if ( 404 === $codice || ( $codice >= 400 && $codice < 500 && $cerca( '/model[_ ]?not[_ ]?found|does not exist|no such model|unknown model|invalid model|model.{0,40}(not found|deprecated|decommissioned|not supported)/i' ) ) ) {
			return $con( $modello );
		}
		if ( 408 === $codice ) {
			return $con( $tempo );
		}
		if ( 413 === $codice || ( $codice >= 400 && $codice < 500 && $cerca( '/context.?length|too long|too many tokens|maximum.{0,20}tokens|request too large|payload too large/i' ) ) ) {
			return $con( $lungo );
		}
		if ( ( 429 === $codice || ( $codice >= 400 && $codice < 500 ) ) && $cerca( '/insufficient_quota|quota|credit|billing|payment|balance|exceeded your current/i' ) && ! $cerca( '/rate.?limit|per minute|requests per/i' ) ) {
			return $con( $credito );
		}
		// Google and Gemini answer a wrong key with 400 «API key not valid» / API_KEY_INVALID.
		if ( ( 0 === $codice || ( $codice >= 400 && $codice < 500 && 429 !== $codice ) ) && preg_match( '/api key not valid|api_key_invalid|invalid[_ ]?(api[_ ]?)?key|incorrect api key|invalid x-api-key/i', $raw ) ) {
			return $con( $chiave );
		}
		// 14/10/2026 (globaltoolszone: Groq «Rate limit reached … tokens per day (TPD): Limit 200000», shown raw on every
		// screen as if a minute would do): a DAILY limit is not «wait a minute».
		if ( ( 429 === $codice || 0 === $codice ) && $cerca( '/per day|\bTPD\b|\bRPD\b|daily (limit|quota)|limit.{0,20}per.?day|requests? per day|tokens? per day/i' ) ) {
			return $con( __( 'The provider\'s daily limit is used up (on its free plan, or the one of your account). It starts again within 24 hours; meanwhile the next engine in your chain carries on, if there is one.', 'translate-rocket' ) );
		}
		if ( 429 === $codice || ( 0 === $codice && preg_match( '/rate.?limit|too many requests/i', $raw ) ) ) {
			return $con( __( 'The provider says too many requests right now. Wait a minute and try again.', 'translate-rocket' ) );
		}
		if ( $codice >= 500 ) {
			return $con( __( 'The provider, or a gateway on the way, did not answer in time or is overloaded. Try again in a few minutes; if it keeps happening, send fewer sentences per request (AI Translation → Usage & limits).', 'translate-rocket' ) );
		}
		if ( 0 === $codice ) {
			$curl = preg_match( '/cURL error (\d+)/i', $raw, $c ) ? ' (cURL ' . $c[1] . ')' : '';
			if ( $cerca( '/cURL error 28\b|timed? ?out|timeout/i' ) ) {
				return $tempo . $curl;
			}
			if ( $cerca( '/cURL error 6\b|resolve host|could not resolve|name or service not known|getaddrinfo/i' ) ) {
				return __( 'Your server could not find the provider\'s address (a DNS problem on your server). Ask your host to check the server\'s DNS.', 'translate-rocket' ) . $curl;
			}
			if ( $cerca( '/cURL error (35|51|58|60|77)\b|SSL|certificate/i' ) ) {
				return __( 'The secure connection to the provider failed (SSL certificate). Ask your host to update the server\'s certificates (CA bundle) and OpenSSL.', 'translate-rocket' ) . $curl;
			}
			if ( $cerca( '/cURL error 7\b|failed to connect|connection refused|couldn\'t connect|network is unreachable/i' ) ) {
				return __( 'Your server could not connect to the provider: the connection was refused or blocked (often an outgoing firewall). Ask your host to allow it.', 'translate-rocket' ) . $curl;
			}
			if ( $cerca( '/curl|connect/i' ) ) {
				return __( 'Your site could not reach the provider (network or firewall). Try again later, or ask your host.', 'translate-rocket' ) . $curl;
			}
		}
		return $con( __( 'The provider answered with an error. Try again in a few minutes.', 'translate-rocket' ) );
	}

	/**
	 * Handle the settings form submission.
	 */
	public function maybe_save(): void {
		if ( ! isset( $_POST['trrocket_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['trrocket_settings_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'trrocket_save_settings' ) ) {
			return;
		}

		$settings = Settings::get();

		if ( isset( $_POST['source_language'] ) ) {
			$source = strtolower( sanitize_text_field( wp_unslash( $_POST['source_language'] ) ) );
			if ( Languages::exists( $source ) ) {
				$settings['source_language'] = $source;
			}
		}

		// Custom languages: remove any ticked, then add a new one if provided.
		$custom = ( isset( $settings['custom_languages'] ) && is_array( $settings['custom_languages'] ) ) ? $settings['custom_languages'] : array();
		if ( isset( $_POST['remove_custom'] ) && is_array( $_POST['remove_custom'] ) ) {
			foreach ( wp_unslash( $_POST['remove_custom'] ) as $rc ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				unset( $custom[ strtolower( sanitize_text_field( $rc ) ) ] );
			}
		}
		if ( ! empty( $_POST['custom_lang_code'] ) ) {
			$cc = (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( sanitize_text_field( wp_unslash( $_POST['custom_lang_code'] ) ) ) );
			$cn = isset( $_POST['custom_lang_name'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_lang_name'] ) ) : '';
			$cf = isset( $_POST['custom_lang_flag'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_lang_flag'] ) ) : '';
			// An "svg:xx" reference is only valid if we actually ship that flag.
			if ( 0 === strpos( $cf, 'svg:' ) && ! in_array( substr( $cf, 4 ), \TranslateRocket\Flags::codes(), true ) ) {
				$cf = '';
			}
			// An uploaded "url:" flag must be a valid URL.
			if ( 0 === strpos( $cf, 'url:' ) ) {
				$u  = esc_url_raw( substr( $cf, 4 ) );
				$cf = ( '' !== $u ) ? 'url:' . $u : '';
			}
			if ( '' !== $cc && '' !== $cn && strlen( $cc ) <= 8 && ! Languages::exists( $cc ) ) {
				$custom[ $cc ] = array( $cn, $cn, '' !== $cf ? $cf : '🌐' );
			}
		}
		$settings['custom_languages'] = $custom;

		$targets = array();
		if ( isset( $_POST['target_languages'] ) && is_array( $_POST['target_languages'] ) ) {
			foreach ( wp_unslash( $_POST['target_languages'] ) as $code ) { // phpcs:ignore
				$code = strtolower( sanitize_text_field( $code ) );
				if ( ( Languages::exists( $code ) || isset( $custom[ $code ] ) ) && $code !== $settings['source_language'] ) {
					$targets[] = $code;
				}
			}
		}

		$settings['target_languages'] = array_values( array_unique( $targets ) );

		// Lingue tenute offline mentre le si traduce. Si accettano solo codici
		// che sono davvero fra le lingue scelte: togliendo una lingua, smette
		// da sola di essere "offline" e non resta a sporcare le impostazioni.
		$offline = array();
		if ( isset( $_POST['offline_languages'] ) && is_array( $_POST['offline_languages'] ) ) {
			foreach ( wp_unslash( $_POST['offline_languages'] ) as $code ) { // phpcs:ignore
				$code = strtolower( sanitize_text_field( $code ) );
				if ( in_array( $code, $settings['target_languages'], true ) ) {
					$offline[] = $code;
				}
			}
		}
		$settings['offline_languages'] = array_values( array_unique( $offline ) );

		$settings['serve_mode']          = ( isset( $_POST['serve_mode'] ) && 'admins' === sanitize_text_field( wp_unslash( $_POST['serve_mode'] ) ) ) ? 'admins' : 'everyone';
		$settings['translate_meta']      = ! empty( $_POST['translate_meta'] );
		$settings['translate_interface'] = ! empty( $_POST['translate_interface'] );
		$settings['auto_redirect']       = ! empty( $_POST['auto_redirect'] );
		$settings['translated_search']   = ! empty( $_POST['translated_search'] );
		$settings['stats']               = ! empty( $_POST['stats'] );
		$settings['menus_per_language']  = \TranslateRocket\MenuPerLanguage::clean( isset( $_POST['menus_per_language'] ) ? wp_unslash( $_POST['menus_per_language'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked value by value in clean().
		$settings['cache_pages']         = ! empty( $_POST['cache_pages'] );
		$settings['show_poweredby']      = ! empty( $_POST['show_poweredby'] );

		Settings::update( $settings );
		// The visibility just chosen here supersedes a pending "Go online" step.
		if ( get_option( \TranslateRocket\Coexistence::PENDING ) ) {
			delete_option( \TranslateRocket\Coexistence::PENDING );
		}
		// Any settings change can alter the output — drop any cached pages.
		\TranslateRocket\Cache::flush();

		if ( ! empty( $settings['translate_interface'] ) ) {
			$this->ensure_language_packs( $targets );
		}

		// (A check on an old language cap, gone since 0.7.0, was left here and raised «Undefined variable
		// $over» on every save: on a site showing PHP warnings it could break the redirect — 5/10/2026.)
		$redirect = array(
			'page'    => 'translate-rocket',
			'updated' => '1',
		);
		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Download the WordPress language packs (core + themes + plugins) for the
	 * target languages, so the interface shows up translated on their pages.
	 * Only fetches what is missing; safe to call on every save.
	 *
	 * @param string[] $targets Target language codes.
	 */
	private function ensure_language_packs( array $targets ): void {
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		if ( ! wp_can_install_language_pack() ) {
			return;
		}
		$installed = get_available_languages();
		$added     = false;
		foreach ( $targets as $code ) {
			$loc = Languages::locale( $code );
			if ( '' === $loc || 'en_US' === $loc || in_array( $loc, $installed, true ) ) {
				continue;
			}
			if ( wp_download_language_pack( $loc ) ) {
				$added = true;
			}
		}
		if ( ! $added ) {
			return;
		}
		// Refresh and pull theme/plugin translations for the new locales too.
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		wp_update_plugins();
		wp_update_themes();
		$updates = wp_get_translation_updates();
		if ( ! empty( $updates ) ) {
			$upgrader = new \Language_Pack_Upgrader( new \Automatic_Upgrader_Skin() );
			$upgrader->bulk_upgrade( $updates );
		}
	}

	/**
	 * Handle the manual translation editor submission.
	 */
	public function maybe_save_translations(): void {
		if ( ! isset( $_POST['trrocket_translations_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['trrocket_translations_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'trrocket_save_translations' ) ) {
			return;
		}

		$lang    = isset( $_POST['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		$targets = (array) Settings::get()['target_languages'];
		if ( ! in_array( $lang, $targets, true ) ) {
			return;
		}

		$rifiutate = 0;
		if ( isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ) {
			foreach ( wp_unslash( $_POST['tr'] ) as $string_id => $translation ) { // phpcs:ignore
				if ( ! Strings::save_translation( (int) $string_id, $lang, \TranslateRocket\Frontend\InlineText::sanitize( (string) $translation ) ) ) {
					++$rifiutate;
				}
			}
		}

		if ( isset( $_POST['post_id'], $_POST['slug'] ) ) {
			$pid = (int) $_POST['post_id'];
			if ( $pid > 0 && current_user_can( 'edit_post', $pid ) ) {
				Slugs::set( $pid, $lang, sanitize_text_field( wp_unslash( $_POST['slug'] ) ) );
			}
		}

		$this->redirect_editor( $lang, 'saved', $rifiutate );
	}

	/**
	 * Handle the copy-paste (Google Translate) import.
	 */
	public function maybe_import_translations(): void {
		if ( ! isset( $_POST['trrocket_import_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['trrocket_import_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'trrocket_import_translations' ) ) {
			return;
		}

		$lang    = isset( $_POST['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		$targets = (array) Settings::get()['target_languages'];
		if ( ! in_array( $lang, $targets, true ) ) {
			return;
		}

		$raw = isset( $_POST['cp_import'] ) ? (string) wp_unslash( $_POST['cp_import'] ) : ''; // phpcs:ignore WordPress.Security

		// Empty paste: never touch existing translations.
		if ( '' === trim( $raw ) ) {
			$this->redirect_editor( $lang, 'noimport' );
		}

		$ids = array();
		if ( isset( $_POST['cp_ids'] ) ) {
			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_POST['cp_ids'] ) ) ) as $id ) {
				$ids[] = (int) $id;
			}
		}

		$pairs = self::paste_pairs( $raw, count( $ids ) );
		if ( null === $pairs ) {
			$this->redirect_editor( $lang, 'noimport' );
		}
		$originals = Strings::originals_by_id( array_values( array_filter( $ids ) ) );
		foreach ( $pairs as $idx => $text ) {
			if ( ! isset( $ids[ $idx ] ) || $ids[ $idx ] <= 0 ) {
				continue;
			}
			$orig = (string) ( $originals[ $ids[ $idx ] ] ?? '' );
			if ( '' === $orig || ! self::paste_plausible( $orig, $text ) ) {
				continue;
			}
			Strings::save_translation( $ids[ $idx ], $lang, sanitize_text_field( $text ) );
		}

		$this->redirect_editor( $lang, 'imported' );
	}

	/**
	 * The pasted translations, by position in the list (0-based).
	 *
	 * 8/10/2026 (a user's site: the cookie banner showed a blog post): a line WITHOUT a number used to
	 * take the place of the item in the same position, so a long text that Google Translate split over
	 * two lines pushed its second half onto another sentence. Now a line without a number continues
	 * the numbered line before it. Only a paste with no numbers at all is matched by order, and only
	 * when it has exactly as many lines as the list.
	 *
	 * @param string $raw   Pasted text.
	 * @param int    $count Items in the list.
	 * @return array<int,string>|null Null when the paste cannot be matched safely.
	 */
	public static function paste_pairs( string $raw, int $count ): ?array {
		$lines = array();
		foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $l ) {
			$l = trim( (string) $l );
			if ( '' !== $l && 0 !== strpos( $l, '```' ) ) {
				$lines[] = $l;
			}
		}
		$numbered = false;
		foreach ( $lines as $l ) {
			if ( preg_match( '/^\d+[\.\)]\s*/u', $l ) ) {
				$numbered = true;
				break;
			}
		}
		if ( ! $numbered ) {
			return count( $lines ) === $count ? $lines : null;
		}
		$out = array();
		$cur = null;
		foreach ( $lines as $l ) {
			if ( preg_match( '/^(\d+)[\.\)]\s*(.*)$/u', $l, $m ) ) {
				$idx = (int) $m[1] - 1;
				if ( $idx < 0 || $idx >= $count || isset( $out[ $idx ] ) ) {
					$cur = null; // out of range or repeated: never guess
					continue;
				}
				$out[ $idx ] = trim( $m[2] );
				$cur         = $idx;
			} elseif ( null !== $cur ) {
				$out[ $cur ] .= ' ' . $l; // the rest of the same item, broken over lines
			}
		}
		return array_filter( $out, 'strlen' );
	}

	/**
	 * A translation that cannot belong to its original: a short label that comes back as a
	 * paragraph, or a paragraph that comes back as two words (the sign of a list that slipped).
	 *
	 * @param string $orig Original.
	 * @param string $tr   Translation.
	 */
	public static function paste_plausible( string $orig, string $tr ): bool {
		$lo = function_exists( 'mb_strlen' ) ? mb_strlen( $orig ) : strlen( $orig );
		$lt = function_exists( 'mb_strlen' ) ? mb_strlen( $tr ) : strlen( $tr );
		return $lt <= max( 60, 4 * $lo + 40 ) && $lt * 6 + 10 >= $lo;
	}

	/**
	 * Delete a page's translations for a language (intentional reset).
	 */
	public function maybe_delete_page_translations(): void {
		if ( ! isset( $_POST['trrocket_delete_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_delete_nonce'] ) ), 'trrocket_delete_page' ) ) {
			return;
		}

		$lang    = isset( $_POST['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		$targets = (array) Settings::get()['target_languages'];
		if ( ! in_array( $lang, $targets, true ) ) {
			return;
		}
		$loc = isset( $_POST['loc'] ) ? sanitize_text_field( wp_unslash( $_POST['loc'] ) ) : '';
		if ( '' === $loc ) {
			return;
		}

		\TranslateRocket\PageReset::reset( $loc, $lang );
		$this->redirect_editor( $lang, 'reset' );
	}

	/**
	 * Put back the translations removed by "Translate again".
	 */
	public function maybe_undo_reset(): void {
		if ( ! isset( $_GET['trrocket_undo'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked right below.
			return;
		}
		$id = sanitize_key( wp_unslash( $_GET['trrocket_undo'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'trrocket_undo_' . $id );
		$n    = \TranslateRocket\PageReset::undo( $id );
		$lang = isset( $_GET['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['lang'] ) ) ) : '';
		$args = array(
			'page'   => 'translate-rocket-strings',
			'lang'   => $lang,
			'undone' => null === $n ? '0' : (string) (int) $n,
		);
		if ( isset( $_GET['loc'] ) ) {
			$args['loc'] = sanitize_text_field( wp_unslash( $_GET['loc'] ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Remove a page from tracking entirely (stale / accidental 404 cleanup).
	 */
	public function maybe_forget_page(): void {
		if ( ! isset( $_POST['trrocket_forget_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_forget_nonce'] ) ), 'trrocket_forget_page' ) ) {
			return;
		}
		$loc = isset( $_POST['loc'] ) ? sanitize_text_field( wp_unslash( $_POST['loc'] ) ) : '';
		if ( '' !== $loc ) {
			Strings::forget_page( $loc );
		}
		$lang = isset( $_POST['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'translate-rocket-strings',
					'lang'      => $lang,
					'forgotten' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Run a migration import from another plugin.
	 */
	public function maybe_import_external(): void {
		if ( ! isset( $_POST['trrocket_import_ext_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_import_ext_nonce'] ) ), 'trrocket_import_ext' ) ) {
			return;
		}

		$id       = isset( $_POST['importer'] ) ? sanitize_key( $_POST['importer'] ) : '';
		$importer = Importers::get( $id );
		$count    = 0;
		if ( $importer && $importer->is_available() ) {
			// A WPML/Polylang site can hold hundreds of translated posts, each split
			// into many body chunks — thousands of writes in one request. Give it room
			// on hosts with a tight default execution time (30s on much shared hosting),
			// or the request is killed mid-import and the redirect below never runs, so
			// the user just sees "nothing happens". The import is idempotent, so a retry
			// after a timeout is safe anyway.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- disabled on some hosts; failing silently is fine.
			}
			Strings::batch_start();
			$result = $importer->import();
			Strings::batch_end();
			$count  = (int) ( $result['count'] ?? 0 );
			// Un importatore puo' rifiutarsi con un motivo (per esempio: il vecchio
			// plugin dice che il sito e' scritto in un'altra lingua). Prima la bacheca
			// mostrava solo «0 importate», e l'utente non sapeva perche'.
			$errore = (string) ( $result['error'] ?? '' );
			// Imported strings won't show on the front end until caches are cleared —
			// the CSV/TMX/Weglot paths already flush; the migration path must too.
			\TranslateRocket\Cache::flush();
		}

		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'page'         => 'translate-rocket-import',
						'imported_n'   => $count,
						'import_error' => isset( $errore ) ? $errore : '',
					),
					static function ( $v ) {
						return '' !== $v;
					}
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Stream the translation memory as a TMX 1.4 file (the standard translation-memory
	 * format — portable to CAT tools and to other TranslateRocket sites).
	 */
	public function maybe_export_tmx(): void {
		if ( ! isset( $_GET['trrocket_export_tmx'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'trrocket_export_tmx' );

		$source = strtolower( (string) Settings::get()['source_language'] );
		$langs  = array_values( (array) Settings::get()['target_languages'] );
		$rows   = Strings::export_rows( $langs );

		nocache_headers();
		header( 'Content-Type: application/x-tmx+xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="translaterocket-' . gmdate( 'Y-m-d' ) . '.tmx"' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<tmx version="1.4">' . "\n";
		echo '<header creationtool="TranslateRocket" creationtoolversion="' . self::xml( TRROCKET_VERSION ) . '" datatype="PlainText" segtype="sentence" adminlang="' . self::xml( self::bcp47( $source ) ) . '" srclang="' . self::xml( self::bcp47( $source ) ) . '" o-tmf="TranslateRocket"/>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML export; every value escaped via self::xml().
		echo '<body>' . "\n";
		foreach ( $rows as $r ) {
			$src = (string) $r['o'];
			if ( '' === trim( $src ) ) {
				continue;
			}
			$tuvs = '';
			foreach ( $langs as $l ) {
				$t = isset( $r['tr'][ $l ] ) ? (string) $r['tr'][ $l ] : '';
				if ( '' === trim( $t ) ) {
					continue;
				}
				$tuvs .= "\t\t<tuv xml:lang=\"" . self::xml( self::bcp47( (string) $l ) ) . "\"><seg>" . self::xml( $t ) . "</seg></tuv>\n";
			}
			if ( '' === $tuvs ) {
				continue; // No translations for this source — skip it.
			}
			echo "\t<tu>\n";
			// Type and context travel with the unit (TMX 1.4 <prop>, before the <tuv>s). The
			// same words can be page text, an aria-label and an og:site_name at once; without
			// these, re-importing our own file restored only one of them (22/09/2026).
			echo "\t\t<prop type=\"x-trrocket-type\">" . self::xml( (string) ( $r['type'] ?? 'text' ) ) . "</prop>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML export; escaped via self::xml().
			if ( '' !== (string) ( $r['ctx'] ?? '' ) ) {
				echo "\t\t<prop type=\"x-trrocket-context\">" . self::xml( (string) $r['ctx'] ) . "</prop>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML export; escaped via self::xml().
			}
			echo "\t\t<tuv xml:lang=\"" . self::xml( self::bcp47( $source ) ) . "\"><seg>" . self::xml( $src ) . "</seg></tuv>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML export; values escaped via self::xml().
			echo $tuvs; // phpcs:ignore WordPress.Security.EscapeOutput -- each <seg> escaped via self::xml().
			echo "\t</tu>\n";
		}
		echo '</body>' . "\n" . '</tmx>' . "\n";
		exit;
	}

	/**
	 * XML-escape a value for TMX output.
	 */
	private static function xml( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Format a language code as a BCP-47 tag for xml:lang (e.g. pt-br -> pt-BR).
	 */
	private static function bcp47( string $code ): string {
		$code = strtolower( $code );
		if ( false === strpos( $code, '-' ) ) {
			return $code;
		}
		list( $l, $r ) = explode( '-', $code, 2 );
		return $l . '-' . strtoupper( $r );
	}

	/**
	 * Map a TMX language tag onto one of our target codes (exact, then base language).
	 *
	 * @param string[] $targets Lowercased target language codes.
	 */
	private static function match_target( string $lang, array $targets ): string {
		$lang = strtolower( $lang );
		if ( in_array( $lang, $targets, true ) ) {
			return $lang;
		}
		$base = (string) strtok( $lang, '-' );
		return in_array( $base, $targets, true ) ? $base : '';
	}

	/**
	 * Import a TMX file: each translation unit's source-language segment is the key and
	 * every matching target-language segment is stored. Parsed with no DTD/entity
	 * expansion and no network access (XXE / billion-laughs safe).
	 */
	public function maybe_import_tmx(): void {
		if ( ! isset( $_POST['trrocket_tmx_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_tmx_nonce'] ) ), 'trrocket_import_tmx' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'translate-rocket' ) );
		}
		$redirect = static function ( array $args ) {
			wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'translate-rocket-import' ), $args ), admin_url( 'admin.php' ) ) );
			exit;
		};
		if ( empty( $_FILES['trrocket_tmx']['tmp_name'] ) || ! is_uploaded_file( $_FILES['trrocket_tmx']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is a server-generated path, guarded by is_uploaded_file().
			$redirect( array( 'tmx_error' => '1' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- reading the uploaded temp file.
		$xml = file_get_contents( sanitize_text_field( $_FILES['trrocket_tmx']['tmp_name'] ) );
		if ( false === $xml || '' === trim( (string) $xml ) ) {
			$redirect( array( 'tmx_error' => '1' ) );
		}
		// Refuse a DTD outright — TMX needs none, and it's the simplest XXE defence.
		if ( false !== stripos( (string) $xml, '<!DOCTYPE' ) ) {
			$redirect( array( 'tmx_error' => '1' ) );
		}

		// XXE protection: LIBXML_NONET blocks network entities, and since libxml
		// 2.9 external entity loading is disabled by default (so the deprecated
		// libxml_disable_entity_loader() is no longer needed).
		$use = libxml_use_internal_errors( true );
		$doc = simplexml_load_string( (string) $xml, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $use );
		if ( false === $doc || ! isset( $doc->body ) ) {
			$redirect( array( 'tmx_error' => '1' ) );
		}

		$source  = strtolower( (string) Settings::get()['source_language'] );
		$targets = array_map( 'strtolower', array_values( (array) Settings::get()['target_languages'] ) );
		$xmlns   = 'http://www.w3.org/XML/1998/namespace';
		$count   = 0;

		// Una sola transazione per tutto il file: vedi Strings::batch_start().
		Strings::batch_start();
		foreach ( $doc->body->tu as $tu ) {
			$segs = array();
			foreach ( $tu->tuv as $tuv ) {
				$attrs = $tuv->attributes( $xmlns );
				$lang  = ( $attrs && isset( $attrs['lang'] ) ) ? strtolower( (string) $attrs['lang'] ) : '';
				if ( '' === $lang ) {
					continue;
				}
				$segs[ $lang ] = isset( $tuv->seg ) ? (string) $tuv->seg : '';
			}
			$src_text = isset( $segs[ $source ] ) ? $segs[ $source ] : '';
			if ( '' === trim( $src_text ) ) {
				// Trados, memoQ, OmegaT write the full code (en-US, en-GB): the same
				// fallback match_target() uses for the target side. Before, such a file
				// loaded fine and imported zero (casi raccolti, importatori 26, 25/9/2026).
				foreach ( $segs as $l => $t ) {
					if ( strtok( str_replace( '_', '-', (string) $l ), '-' ) === $source && '' !== trim( $t ) ) {
						$src_text = $t;
						break;
					}
				}
			}
			if ( '' === trim( $src_text ) ) {
				continue;
			}
			// Our own export says which kind of string each unit is; a file from any
			// other tool does not.
			$tipo = null;
			$ctx  = null;
			foreach ( $tu->prop as $prop ) {
				$nome = (string) $prop['type'];
				if ( 'x-trrocket-type' === $nome ) {
					$tipo = (string) $prop;
				} elseif ( 'x-trrocket-context' === $nome ) {
					$ctx = (string) $prop;
				}
			}
			foreach ( $segs as $lang => $text ) {
				if ( '' === trim( $text ) ) {
					continue;
				}
				$target = self::match_target( $lang, $targets );
				if ( '' === $target || $target === $source ) {
					continue;
				}
				if ( null !== $tipo ) {
					// Ours: restored exactly as it was exported, identical ones included.
					$fatto = Strings::store_imported( $src_text, $target, $text, '' !== $tipo ? $tipo : 'text', ( null !== $ctx && '' !== $ctx ) ? $ctx : null );
				} else {
					// Another tool's: in the shape the page shows, and on every kind of
					// string with those words (text, aria-label, og:title...).
					$fatto = \TranslateRocket\Importers\Comune::ovunque( $src_text, $target, $text );
				}
				if ( $fatto ) {
					++$count;
				}
			}
		}
		Strings::batch_end();
		\TranslateRocket\Cache::flush();
		$redirect( array( 'tmx_imported' => $count ) );
	}

	/**
	 * Stream every translation as a UTF-8 CSV (one column per target language).
	 */
	public function maybe_export_csv(): void {
		if ( ! isset( $_GET['trrocket_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'trrocket_export_csv' );

		$langs = array_values( (array) Settings::get()['target_languages'] );
		$rows  = Strings::export_rows( $langs );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="translaterocket-' . gmdate( 'Y-m-d' ) . '.csv"' );

		// A cell starting with = + - @ (or a tab/CR) is executed as a formula by
		// Excel/Sheets — a translation crafted that way could run on the admin's
		// machine when they open the export. Neutralise with a leading apostrophe
		// (the spreadsheet convention for "this is text").
		$guard = static function ( $v ) {
			$v = (string) $v;
			return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
		};

		$out = fopen( 'php://output', 'w' );
		echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel shows accents correctly.
		// Explicit enclosure + empty escape: RFC 4180 round-trip (quotes doubled,
		// never backslash-escaped) and no PHP 8.4 implicit-$escape deprecation.
		fputcsv( $out, array_merge( array( 'original', 'type', 'context' ), $langs ), ',', '"', '' );
		foreach ( $rows as $r ) {
			$line = array( $guard( $r['o'] ), $guard( $r['type'] ), $guard( $r['ctx'] ) );
			foreach ( $langs as $l ) {
				$line[] = isset( $r['tr'][ $l ] ) ? $guard( $r['tr'][ $l ] ) : '';
			}
			fputcsv( $out, $line, ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream of this CSV download; WP_Filesystem has no equivalent.
		exit;
	}

	/**
	 * Import translations from an uploaded Weglot CSV export.
	 */
	public function maybe_import_weglot(): void {
		if ( ! isset( $_POST['trrocket_weglot_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_weglot_nonce'] ) ), 'trrocket_import_weglot' ) ) {
			return;
		}

		$redirect = function ( $args ) {
			wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'translate-rocket-import' ), $args ), admin_url( 'admin.php' ) ) );
			exit;
		};

		if ( empty( $_FILES['trrocket_weglot_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['trrocket_weglot_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is a server-generated path, guarded by is_uploaded_file().
			$redirect( array( 'weglot_error' => 'nofile' ) );
		}
		if ( (int) ( $_FILES['trrocket_weglot_csv']['size'] ?? 0 ) > 10 * 1024 * 1024 ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- integer-cast file size, not string input.
			$redirect( array( 'weglot_error' => 'toobig' ) );
		}

		// A near-limit file can be tens of thousands of rows; give it room on
		// hosts with a tight default execution time (the import is idempotent).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- set_time_limit is disabled on some hosts; failing silently is fine.
		}

		$fallback = isset( $_POST['trrocket_weglot_lang'] ) ? sanitize_key( wp_unslash( $_POST['trrocket_weglot_lang'] ) ) : '';
		Strings::batch_start();
		$result = \TranslateRocket\Importers\WeglotCsv::import(
			(string) $_FILES['trrocket_weglot_csv']['tmp_name'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-generated temp path, is_uploaded_file-checked above.
			$fallback
		);
		Strings::batch_end();

		if ( '' !== $result['error'] ) {
			// WeglotCsv::import() returns a stable machine code ('columns' | 'open');
			// the notice renderer owns the localized wording.
			$redirect( array( 'weglot_error' => $result['error'] ) );
		}

		\TranslateRocket\Cache::flush();
		$redirect(
			array(
				'weglot_imported' => (int) $result['count'],
				'weglot_skipped'  => (int) $result['skipped_lang'],
			)
		);
	}

	/**
	 * Import translations from an uploaded CSV produced by the export above.
	 */
	public function maybe_import_csv(): void {
		if ( ! isset( $_POST['trrocket_csv_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_csv_nonce'] ) ), 'trrocket_import_csv' ) ) {
			return;
		}

		$redirect = function ( $args ) {
			wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'translate-rocket-import' ), $args ), admin_url( 'admin.php' ) ) );
			exit;
		};

		if ( empty( $_FILES['trrocket_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['trrocket_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is a server-generated path, guarded by is_uploaded_file().
			$redirect( array( 'csv_error' => 'nofile' ) );
		}

		$fh = fopen( $_FILES['trrocket_csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- streaming an uploaded temp file (is_uploaded_file-checked); WP_Filesystem cannot fgetcsv().
		if ( ! $fh ) {
			$redirect( array( 'csv_error' => 'open' ) );
		}

		$header = fgetcsv( $fh, 0, ',', '"', '' );
		if ( ! $header ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the fopen() stream above.
			$redirect( array( 'csv_error' => 'empty' ) );
		}
		// Strip a UTF-8 BOM from the first header cell if present.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );

		// Map column index -> language code (only known target languages).
		$targets   = (array) Settings::get()['target_languages'];
		$lang_cols = array();
		foreach ( $header as $i => $name ) {
			$name = strtolower( trim( (string) $name ) );
			if ( $i >= 3 && in_array( $name, $targets, true ) ) {
				$lang_cols[ $i ] = $name;
			}
		}

		// Undo the export's formula-injection guard: a leading apostrophe that
		// shields a = + - @ (or tab/CR) is transport armour, not content.
		$unguard = static function ( string $v ): string {
			return ( strlen( $v ) > 1 && "'" === $v[0] && false !== strpos( "=+-@\t\r", $v[1] ) ) ? substr( $v, 1 ) : $v;
		};

		$count = 0;
		Strings::batch_start();
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
			$original = isset( $row[0] ) ? $unguard( (string) $row[0] ) : '';
			$type     = isset( $row[1] ) && '' !== $row[1] ? $unguard( (string) $row[1] ) : 'text';
			$context  = isset( $row[2] ) && '' !== $row[2] ? $unguard( (string) $row[2] ) : null;
			if ( '' === trim( $original ) ) {
				continue;
			}
			foreach ( $lang_cols as $i => $lang ) {
				$tr = isset( $row[ $i ] ) ? $unguard( (string) $row[ $i ] ) : '';
				if ( '' !== trim( $tr ) && Strings::save_by_source( $original, $lang, $tr, $type, $context ) ) {
					++$count;
				}
			}
		}
		Strings::batch_end();
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the fopen() stream above.

		\TranslateRocket\Cache::flush();
		$redirect( array( 'csv_imported' => $count ) );
	}

	/**
	 * Render the import (migrate from another plugin) page.
	 */
	public function render_import_page(): void {
		$copies_of = CopyCleanupAdmin::requested();
		if ( $copies_of ) {
			echo '<div class="wrap trrocket-wrap">';
			self::header( 'translate-rocket-import' );
			CopyCleanupAdmin::render( $copies_of );
			echo '</div>';
			return;
		}
		?>
		<div class="wrap trrocket-wrap">
			<?php self::header( 'translate-rocket-import' ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'Import translations', 'translate-rocket' ); ?><?php $this->info( __( 'Already used another translation plugin? Bring its translations into TranslateRocket so you don’t start over.', 'translate-rocket' ) ); ?></h1>

			<?php if ( isset( $_GET['import_error'] ) && 'source_mismatch' === $_GET['import_error'] ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-error">
					<p><strong><?php esc_html_e( 'Nothing was imported, on purpose.', 'translate-rocket' ); ?></strong>
					<?php esc_html_e( 'The plugin you are coming from says your site is written in a different language from the one set in TranslateRocket. Importing now would pair every sentence with the wrong side. Set the source language in TranslateRocket to the language your pages are written in, then import again.', 'translate-rocket' ); ?></p>
				</div>
			<?php elseif ( isset( $_GET['imported_n'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						/* translators: %d: number of imported translations. */
						printf( esc_html__( 'Imported %d translations.', 'translate-rocket' ), (int) $_GET['imported_n'] ); // phpcs:ignore WordPress.Security.NonceVerification
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['csv_imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of translations imported from the CSV. */
					printf( esc_html__( 'Imported %d translations from CSV.', 'translate-rocket' ), (int) $_GET['csv_imported'] ); // phpcs:ignore WordPress.Security.NonceVerification
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['csv_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not read the CSV file. Please upload a valid .csv exported from TranslateRocket.', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['tmx_imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of translations imported from TMX. */
					printf( esc_html__( 'Imported %d translations from TMX.', 'translate-rocket' ), (int) $_GET['tmx_imported'] ); // phpcs:ignore WordPress.Security.NonceVerification
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['tmx_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not read the TMX file. Please upload a valid .tmx (without a DTD).', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['weglot_imported'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of translations imported from the Weglot export. */
					printf( esc_html__( 'Imported %d translations from your Weglot export.', 'translate-rocket' ), (int) $_GET['weglot_imported'] ); // phpcs:ignore WordPress.Security.NonceVerification
					if ( ! empty( $_GET['weglot_skipped'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
						echo ' ';
						/* translators: %d: number of rows skipped because their language is not configured. */
						printf( esc_html__( '%d rows were skipped because their language is not configured as a target here.', 'translate-rocket' ), (int) $_GET['weglot_skipped'] ); // phpcs:ignore WordPress.Security.NonceVerification
					}
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['weglot_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-error is-dismissible"><p>
					<?php
					$weglot_error = sanitize_key( wp_unslash( $_GET['weglot_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
					if ( 'columns' === $weglot_error ) {
						esc_html_e( 'This does not look like a Weglot export: the header has no word_from / word_to columns.', 'translate-rocket' );
					} elseif ( 'toobig' === $weglot_error ) {
						esc_html_e( 'The file is too large (10 MB maximum). Export from Weglot in smaller batches, e.g. one language at a time.', 'translate-rocket' );
					} else {
						esc_html_e( 'Could not read the file. Please upload the .csv exported from your Weglot dashboard.', 'translate-rocket' );
					}
					?>
				</p></div>
			<?php endif; ?>

			<p class="trrocket-tagline"><?php esc_html_e( 'Bring your translations over from another plugin — no need to start from scratch.', 'translate-rocket' ); ?></p>

			<?php \TranslateRocket\Importers\Check::render(); ?>

			<div class="trrocket-card">
				<p class="description"><?php esc_html_e( 'Existing TranslateRocket translations are kept; imported strings are added or filled in.', 'translate-rocket' ); ?></p>
				<?php
				foreach ( Importers::all() as $importer ) :
					$available = $importer->is_available();
					$count     = $available ? $importer->count_available() : 0;
					?>
					<h3><?php echo esc_html( $importer->label() ); ?></h3>
					<?php if ( $available && $count > 0 ) : ?>
						<p>
							<?php
							/* translators: %d: number of translated pages, terms or strings found. */
							// Polylang and WPML count translated pages and terms, TranslatePress counts
							// strings: «5 found» then «Imported 33 translations» read as a contradiction
							// (seen recording the wordpress.org screenshots, 30/09/2026).
							printf( esc_html__( '%d translated items found (pages, terms or strings).', 'translate-rocket' ), (int) $count );
							?>
						</p>
						<form method="post" action="">
							<?php wp_nonce_field( 'trrocket_import_ext', 'trrocket_import_ext_nonce' ); ?>
							<input type="hidden" name="importer" value="<?php echo esc_attr( $importer->id() ); ?>" />
							<?php
							submit_button(
								/* translators: %s: source plugin name. */
								sprintf( __( 'Import from %s', 'translate-rocket' ), $importer->label() ),
								'primary',
								'submit',
								false
							);
							?>
						</form>
					<?php elseif ( $available ) : ?>
						<p class="description"><?php esc_html_e( 'Detected, but there are no translations to import yet.', 'translate-rocket' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Not detected (the plugin is not installed or configured).', 'translate-rocket' ); ?></p>
					<?php endif; ?>
					<?php
					if ( $available ) {
						CopyCleanupAdmin::intro( $importer );
						$avviso_modelli = \TranslateRocket\Importers\BuilderTemplates::notice( $importer->label() );
						if ( '' !== $avviso_modelli ) {
							echo '<p class="description trr-modelli-lingua">' . esc_html( $avviso_modelli ) . '</p>';
						}
						// I menu per lingua: finora non li nominava nessuno, e chi si trovava
						// «Header PT» in Aspetto cancellava a caso. Qui si dice che non serve.
						$avviso_menu = \TranslateRocket\Importers\BuilderTemplates::menus_notice( $importer->label() );
						if ( '' !== $avviso_menu ) {
							echo '<p class="description trr-menu-lingua">' . esc_html( $avviso_menu ) . '</p>';
						}
					}
					?>
				<?php endforeach; ?>

				<h3>Weglot</h3>
				<p class="description"><?php esc_html_e( 'Weglot keeps translations in its cloud, so there is nothing to detect locally. Export them from your Weglot dashboard (Translations → Export, CSV format) and upload the file here.', 'translate-rocket' ); ?></p>
				<?php if ( empty( Settings::get()['target_languages'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the plugin settings. */
							esc_html__( 'Add your target languages in %s first, then upload the export.', 'translate-rocket' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=translate-rocket' ) ) . '">' . esc_html__( 'Settings', 'translate-rocket' ) . '</a>'
						);
						?>
					</p>
				<?php else : ?>
				<form method="post" action="" enctype="multipart/form-data">
					<?php wp_nonce_field( 'trrocket_import_weglot', 'trrocket_weglot_nonce' ); ?>
					<p>
						<input type="file" name="trrocket_weglot_csv" accept=".csv,text/csv" required />
					</p>
					<p>
						<label for="trrocket-weglot-lang"><?php esc_html_e( 'Language of the file (used only when the export has no language column):', 'translate-rocket' ); ?></label>
						<select name="trrocket_weglot_lang" id="trrocket-weglot-lang">
							<?php foreach ( (array) ( Settings::get()['target_languages'] ?? array() ) as $weglot_target ) : ?>
								<option value="<?php echo esc_attr( (string) $weglot_target ); ?>"><?php echo esc_html( Languages::label( (string) $weglot_target ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<?php submit_button( __( 'Import from Weglot', 'translate-rocket' ), 'primary', 'submit', false ); ?>
				</form>
				<?php endif; ?>

				<?php \TranslateRocket\Importers\Universal::render( current_user_can( 'manage_options' ) ); ?>

				<hr>
				<p class="description"><?php esc_html_e( 'Note: Polylang and WPML store translations as separate posts, so their import brings over string translations, titles, slugs and matching body text (best effort).', 'translate-rocket' ); ?></p>
			</div>

			<h1 class="trr-page-title" style="margin-top:28px"><?php esc_html_e( 'Export & import (CSV)', 'translate-rocket' ); ?><?php $this->info( __( 'Download all your translations as a spreadsheet (one column per language) for backup or offline editing, then upload it back.', 'translate-rocket' ) ); ?></h1>
			<div class="trrocket-card">
				<h3><?php esc_html_e( 'Export all translations', 'translate-rocket' ); ?></h3>
				<p class="description"><?php esc_html_e( 'A UTF-8 CSV with columns: original, type, context, and one column per language. Opens in Excel, Google Sheets or Numbers.', 'translate-rocket' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'translate-rocket-import', 'trrocket_export' => 'csv' ), admin_url( 'admin.php' ) ), 'trrocket_export_csv' ) ); ?>">
						&#11015; <?php esc_html_e( 'Download CSV', 'translate-rocket' ); ?>
					</a>
				</p>

				<hr>

				<h3><?php esc_html_e( 'Import from CSV', 'translate-rocket' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Upload a CSV in the same format. Non-empty cells update the matching translation; blank cells are left untouched.', 'translate-rocket' ); ?></p>
				<form method="post" action="" enctype="multipart/form-data">
					<?php wp_nonce_field( 'trrocket_import_csv', 'trrocket_csv_nonce' ); ?>
					<input type="file" name="trrocket_csv" accept=".csv,text/csv" required />
					<?php submit_button( __( 'Import CSV', 'translate-rocket' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<h1 class="trr-page-title" style="margin-top:28px"><?php esc_html_e( 'Export & import (TMX)', 'translate-rocket' ); ?><?php $this->info( __( 'TMX is the standard translation-memory format. Export to back up your memory, move it to another TranslateRocket site, or open it in a CAT tool; import a TMX to load translations.', 'translate-rocket' ) ); ?></h1>
			<div class="trrocket-card">
				<h3><?php esc_html_e( 'Export translation memory', 'translate-rocket' ); ?></h3>
				<p class="description"><?php esc_html_e( 'A standard TMX 1.4 file: one translation unit per source string, one segment per language.', 'translate-rocket' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'translate-rocket-import', 'trrocket_export_tmx' => '1' ), admin_url( 'admin.php' ) ), 'trrocket_export_tmx' ) ); ?>">
						&#11015; <?php esc_html_e( 'Download TMX', 'translate-rocket' ); ?>
					</a>
				</p>

				<hr>

				<h3><?php esc_html_e( 'Import TMX', 'translate-rocket' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Upload a TMX file. Segments are matched by their source-language text, then added or updated.', 'translate-rocket' ); ?></p>
				<form method="post" action="" enctype="multipart/form-data">
					<?php wp_nonce_field( 'trrocket_import_tmx', 'trrocket_tmx_nonce' ); ?>
					<input type="file" name="trrocket_tmx" accept=".tmx,application/xml,text/xml" required />
					<?php submit_button( __( 'Import TMX', 'translate-rocket' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Save the switcher customization settings.
	 */
	public function maybe_save_switcher(): void {
		// Only the customizer's own submit saves. The live preview posts the same form to
		// admin-ajax.php, and must never save what is only being tried out.
		if ( ! isset( $_POST['trrocket_switcher_nonce'] ) || wp_doing_ajax() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_switcher_nonce'] ) ), 'trrocket_save_switcher' ) ) {
			return;
		}

		\TranslateRocket\Cache::flush(); // The switcher appears on every page.

		$settings = Settings::get();
		if ( ! is_array( $settings['switchers'] ?? null ) ) {
			$settings['switchers'] = array();
		}

		// Add a new profile (starts as a copy of the default).
		if ( ! empty( $_POST['sw_new_profile'] ) ) {
			$slug = sanitize_key( wp_unslash( $_POST['sw_new_profile'] ) );
			if ( '' !== $slug && 'default' !== $slug && ! isset( $settings['switchers'][ $slug ] ) ) {
				// A copy of Default's look, in no place yet: header and footer stay where they are.
				$settings['switchers'][ $slug ] = array_merge(
					(array) $settings['switcher'],
					array(
						'in_menu'    => false,
						'floating'   => false,
						'in_spot'    => false,
						'footer_row' => false,
					)
				);
				Settings::update( $settings );
			}
			$this->redirect_switcher( $slug );
		}

		// Delete a profile.
		if ( ! empty( $_POST['sw_delete_profile'] ) ) {
			$slug = sanitize_key( wp_unslash( $_POST['sw_delete_profile'] ) );
			unset( $settings['switchers'][ $slug ] );
			Settings::update( $settings );
			$this->redirect_switcher( 'default' );
		}

		// Save the selected profile.
		$profile = isset( $_POST['sw_profile'] ) ? sanitize_key( wp_unslash( $_POST['sw_profile'] ) ) : 'default';
		$sw      = ( 'default' === $profile )
			? ( is_array( $settings['switcher'] ?? null ) ? $settings['switcher'] : array() )
			: ( is_array( $settings['switchers'][ $profile ] ?? null ) ? $settings['switchers'][ $profile ] : $settings['switcher'] );

		$sw = $this->switcher_from_post( $sw );

		// One profile in the header menu (6/10/2026): the one saved with «In my header menu» takes it.
		if ( ! empty( $sw['in_menu'] ) ) {
			foreach ( (array) ( $settings['switchers'] ?? array() ) as $id => $p ) {
				if ( (string) $id !== $profile && is_array( $p ) ) {
					$settings['switchers'][ $id ]['in_menu'] = false;
				}
			}
			if ( 'default' !== $profile && is_array( $settings['switcher'] ?? null ) ) {
				$settings['switcher']['in_menu']  = false;
				$settings['switcher']['floating'] = false;
				$settings['switcher']['in_spot']  = false;
			}
		}
		// One profile in the footer (1.8.1): the one saved with «Footer» takes it.
		if ( ! empty( $sw['footer_row'] ) ) {
			foreach ( (array) ( $settings['switchers'] ?? array() ) as $id => $p ) {
				if ( (string) $id !== $profile && is_array( $p ) ) {
					$settings['switchers'][ $id ]['footer_row'] = false;
				}
			}
			if ( 'default' !== $profile && is_array( $settings['switcher'] ?? null ) ) {
				$settings['switcher']['footer_row'] = false;
			}
		}
		if ( 'default' === $profile ) {
			$settings['switcher'] = $sw;
		} else {
			$sw['floating'] = false;
			$sw['in_spot']  = false;
			$settings['switchers'][ $profile ] = $sw;
		}
		Settings::update( $settings );
		$this->redirect_switcher( $profile );
	}

	/**
	 * The switcher settings posted by the customizer form, checked one by one. Used to
	 * save, and by the live preview to draw what has not been saved yet.
	 *
	 * @param array<string,mixed> $sw Current settings of the profile (kept for keys the form does not send).
	 * @return array<string,mixed>
	 */
	private function switcher_from_post( array $sw ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- both callers verify the nonce first.
		$type = isset( $_POST['sw_type'] ) ? sanitize_key( $_POST['sw_type'] ) : 'inline';
		$show = isset( $_POST['sw_show'] ) ? sanitize_key( $_POST['sw_show'] ) : 'both';
		$cur  = isset( $_POST['sw_current'] ) ? sanitize_key( $_POST['sw_current'] ) : 'show';
		$placement = isset( $_POST['sw_placement'] ) ? sanitize_key( $_POST['sw_placement'] ) : 'manual';

		$sw['type']          = in_array( $type, array( 'inline', 'list', 'dropdown', 'scroll', 'grid' ), true ) ? $type : 'inline';
		$sw['show']          = in_array( $show, array( 'both', 'flag', 'name', 'code', 'flagcode' ), true ) ? $show : 'both';
		$sw['current']       = in_array( $cur, array( 'show', 'hide' ), true ) ? $cur : 'show';
		// Placement is one either/or choice: manual (shortcode/block), in the theme's menu, OR floating.
		$sw['in_menu']       = ( 'menu' === $placement );
		$sw['in_spot']       = ( 'spot' === $placement );
		$sw['spot_selector'] = isset( $_POST['sw_spot_selector'] ) ? \TranslateRocket\Frontend\Switcher::clean_selector( wp_unslash( (string) $_POST['sw_spot_selector'] ) ) : (string) ( $sw['spot_selector'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_selector() keeps only selector characters.
		$sw['spot_where']    = ( isset( $_POST['sw_spot_where'] ) && 'start' === $_POST['sw_spot_where'] ) ? 'start' : 'end';
		$sw['spot_label']    = isset( $_POST['sw_spot_label'] ) ? sanitize_text_field( wp_unslash( $_POST['sw_spot_label'] ) ) : (string) ( $sw['spot_label'] ?? '' );
		$sw['menu_pos']      = ( isset( $_POST['sw_menu_pos'] ) && in_array( $_POST['sw_menu_pos'], array( 'start', 'after' ), true ) ) ? sanitize_key( $_POST['sw_menu_pos'] ) : 'end';
		$sw['menu_after']    = isset( $_POST['sw_menu_after'] ) ? sanitize_text_field( wp_unslash( $_POST['sw_menu_after'] ) ) : '';
		if ( 'after' === $sw['menu_pos'] && '' === $sw['menu_after'] ) {
			$sw['menu_pos'] = 'end';
		}
		$sw['footer_row']    = ( 'footer' === $placement ); // 1.8.1: a place of its own, no longer a box in Default
		$sw['footer_layout'] = ( isset( $_POST['sw_footer_layout'] ) && 'grid' === $_POST['sw_footer_layout'] ) ? 'grid' : 'row';
		$sw['footer_align']  = ( isset( $_POST['sw_footer_align'] ) && in_array( $_POST['sw_footer_align'], array( 'left', 'right' ), true ) ) ? sanitize_key( $_POST['sw_footer_align'] ) : 'center';
		$sw['footer_cols']   = isset( $_POST['sw_footer_cols'] ) ? max( 0, min( 6, (int) $_POST['sw_footer_cols'] ) ) : 0;
		$sw['floating']      = ( ! in_array( $placement, array( 'manual', 'menu', 'spot', 'footer' ), true ) );
		$menu_loc            = isset( $_POST['sw_menu_location'] ) ? sanitize_text_field( wp_unslash( $_POST['sw_menu_location'] ) ) : 'auto';
		$sw['menu_location'] = ( 'auto' === $menu_loc || isset( \TranslateRocket\Frontend\Switcher::menu_locations()[ $menu_loc ] ) || preg_match( '/^nav:\d+$/', $menu_loc ) ) ? $menu_loc : 'auto';
		$sw['float_pos']     = in_array( $placement, array( 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'custom' ), true ) ? $placement : 'bottom-right';
		$sw['float_x']       = isset( $_POST['sw_float_x'] ) ? sanitize_text_field( wp_unslash( $_POST['sw_float_x'] ) ) : '';
		$sw['float_y']       = isset( $_POST['sw_float_y'] ) ? sanitize_text_field( wp_unslash( $_POST['sw_float_y'] ) ) : '';
		$sw['english_names'] = ! empty( $_POST['sw_english'] );
		$sw['no_border']     = ! empty( $_POST['sw_no_border'] );

		$device       = isset( $_POST['sw_device'] ) ? sanitize_key( $_POST['sw_device'] ) : 'both';
		$sw['device'] = in_array( $device, array( 'both', 'desktop', 'mobile' ), true ) ? $device : 'both';

		$mobile       = isset( $_POST['sw_mobile'] ) ? sanitize_key( $_POST['sw_mobile'] ) : 'same';
		$sw['mobile'] = in_array( $mobile, array( 'same', 'flags', 'hide' ), true ) ? $mobile : 'same';

		$swidth         = isset( $_POST['sw_width'] ) ? sanitize_key( $_POST['sw_width'] ) : 'auto';
		$sw['width']    = in_array( $swidth, array( 'auto', 'small', 'medium', 'large', 'custom' ), true ) ? $swidth : 'auto';
		$wpx            = isset( $_POST['sw_width_px'] ) ? sanitize_text_field( wp_unslash( $_POST['sw_width_px'] ) ) : '';
		$sw['width_px'] = preg_match( '/^\d+(?:\.\d+)?$/', $wpx ) ? $wpx : '';

		// Colours must be real hex values: an unvalidated colour could close the CSS
		// rule and inject arbitrary styles when interpolated into the switcher CSS.
		foreach ( array( 'text_color', 'bg_color', 'border_color', 'hover_color', 'hover_bg', 'menu_bg' ) as $key ) {
			$sw[ $key ] = isset( $_POST[ 'sw_' . $key ] )
				? (string) sanitize_hex_color( (string) wp_unslash( $_POST[ 'sw_' . $key ] ) )
				: '';
		}
		$rad          = isset( $_POST['sw_radius'] ) ? sanitize_text_field( wp_unslash( $_POST['sw_radius'] ) ) : '';
		$sw['radius'] = preg_match( '/^\d+(?:\.\d+)?$/', $rad ) ? $rad : '';
		$font              = isset( $_POST['sw_font_family'] ) ? sanitize_key( $_POST['sw_font_family'] ) : '';
		$sw['font_family'] = isset( \TranslateRocket\Frontend\Switcher::font_stacks()[ $font ] ) ? $font : '';
		$sw['font_weight'] = ( isset( $_POST['sw_font_weight'] ) && 'bold' === $_POST['sw_font_weight'] ) ? 'bold' : 'normal';
		$fsz               = isset( $_POST['sw_font_size'] ) ? sanitize_text_field( wp_unslash( $_POST['sw_font_size'] ) ) : '';
		$sw['font_size']   = preg_match( '/^\d+(?:\.\d+)?$/', $fsz ) ? $fsz : '';
		$sw['uppercase']   = ! empty( $_POST['sw_uppercase'] );
		$div               = isset( $_POST['sw_divider'] ) ? sanitize_key( $_POST['sw_divider'] ) : 'none';
		$sw['divider']     = in_array( $div, array( 'none', 'pipe', 'bullet', 'dot', 'slash', 'dash' ), true ) ? $div : 'none';
		$shadow           = isset( $_POST['sw_shadow'] ) ? sanitize_key( $_POST['sw_shadow'] ) : 'none';
		$sw['shadow']     = in_array( $shadow, array( 'none', 'light', 'medium', 'strong' ), true ) ? $shadow : 'none';
		$anim             = isset( $_POST['sw_animation'] ) ? sanitize_key( $_POST['sw_animation'] ) : 'fade';
		$sw['animation']  = in_array( $anim, array( 'none', 'fade', 'slide', 'scale' ), true ) ? $anim : 'fade';
		$hfx              = isset( $_POST['sw_hover_fx'] ) ? sanitize_key( $_POST['sw_hover_fx'] ) : 'none';
		$sw['hover_fx']   = in_array( $hfx, array( 'none', 'lift', 'grow', 'underline' ), true ) ? $hfx : 'none';
		$sw['bg_opacity'] = isset( $_POST['sw_bg_opacity'] ) ? max( 0, min( 100, (int) $_POST['sw_bg_opacity'] ) ) : 100;

		$trigger           = isset( $_POST['sw_dd_trigger'] ) ? sanitize_key( $_POST['sw_dd_trigger'] ) : 'click';
		$sw['dd_trigger']  = in_array( $trigger, array( 'click', 'hover' ), true ) ? $trigger : 'click';
		$sw['dd_caret']    = ! empty( $_POST['sw_dd_caret'] );
		foreach ( array( 'flag_tl', 'flag_tr', 'flag_br', 'flag_bl' ) as $ck ) {
			$sw[ $ck ] = isset( $_POST[ 'sw_' . $ck ] ) ? min( 50, max( 0, (int) $_POST[ 'sw_' . $ck ] ) ) : 0;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $sw;
	}

	/**
	 * AJAX: the live preview, drawn by the site's own switcher code with the settings
	 * currently in the form (saved or not).
	 */
	public function ajax_switcher_preview(): void {
		check_ajax_referer( 'trrocket_sw_preview' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ) );
		}
		$view = ( isset( $_POST['view'] ) && 'phone' === $_POST['view'] ) ? 'phone' : 'desktop';
		wp_send_json_success( ( new \TranslateRocket\Frontend\Switcher() )->preview_parts( $this->switcher_from_post( array() ), $view ) );
	}

	/**
	 * AJAX: keep the settings in the form for a quarter of an hour and give the address of the
	 * home page drawn with them — the real header, before saving (see Switcher::trying()).
	 */
	public function ajax_switcher_try(): void {
		check_ajax_referer( 'trrocket_sw_preview' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ) );
		}
		$profile  = isset( $_POST['sw_profile'] ) ? sanitize_key( wp_unslash( $_POST['sw_profile'] ) ) : 'default';
		$settings = Settings::get();
		$base     = ( 'default' === $profile || '' === $profile )
			? ( is_array( $settings['switcher'] ?? null ) ? $settings['switcher'] : array() )
			: ( is_array( $settings['switchers'][ $profile ] ?? null ) ? $settings['switchers'][ $profile ] : (array) ( $settings['switcher'] ?? array() ) );
		$uid      = get_current_user_id();
		set_transient(
			'trrocket_sw_try_' . $uid,
			array(
				'profile' => '' === $profile ? 'default' : $profile,
				'sw'      => $this->switcher_from_post( $base ),
			),
			15 * MINUTE_IN_SECONDS
		);
		wp_send_json_success(
			array(
				'url' => add_query_arg(
					array(
						'trrocket_sw_try' => wp_create_nonce( 'trrocket_sw_try_' . $uid ),
						't'               => time(),
					),
					home_url( '/' )
				),
			)
		);
	}

	/**
	 * Redirect back to the switcher customizer for a given profile.
	 */
	private function redirect_switcher( string $profile ): void {
		$args = array(
			'page'    => 'translate-rocket-switcher',
			'updated' => '1',
		);
		if ( 'default' !== $profile && '' !== $profile ) {
			$args['profile'] = $profile;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * One colour input row.
	 */
	private function color_row( string $id, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td class="trr-color-row">
				<input type="color" class="trr-color-pick" value="<?php echo esc_attr( preg_match( '/^#[0-9a-f]{6}$/i', $value ) ? $value : '#ffffff' ); ?>" data-for="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" />
				<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="#333333" class="regular-text trr-color" />
			</td>
		</tr>
		<?php
	}

	/**
	 * Switcher customization page: style, colours, presets, floating, live preview.
	 */
	public function render_switcher_page(): void {
		$all    = Settings::get();
		$extras = is_array( $all['switchers'] ?? null ) ? $all['switchers'] : array();
		// phpcs:ignore WordPress.Security.NonceVerification
		$profile = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : 'default';
		if ( 'default' !== $profile && ! isset( $extras[ $profile ] ) ) {
			$profile = 'default';
		}
		$sw   = ( 'default' === $profile ) ? (array) $all['switcher'] : (array) $extras[ $profile ];
		$base = admin_url( 'admin.php?page=translate-rocket-switcher' );
		$g    = function ( $k, $d = '' ) use ( $sw ) {
			return isset( $sw[ $k ] ) ? $sw[ $k ] : $d;
		};
		?>
		<div class="wrap trrocket-wrap trrocket-wide">
			<?php self::header( 'translate-rocket-switcher' ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'Language switcher', 'translate-rocket' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Switcher saved.', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['menu_moved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Done: the languages are now an item of your header menu.', 'translate-rocket' ); ?> <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See your site', 'translate-rocket' ); ?> ↗</a></p></div>
			<?php endif; ?>
			<p class="trrocket-tagline"><?php esc_html_e( 'Design your switcher — create profiles (e.g. header, footer), each with its own style and a live preview.', 'translate-rocket' ); ?></p>

			<div id="trr-sw-profiles" class="trrocket-card" style="max-width:none">
				<strong><?php esc_html_e( 'Profile:', 'translate-rocket' ); ?></strong>
				<a class="button <?php echo ( 'default' === $profile ) ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Default', 'translate-rocket' ); ?></a>
				<?php foreach ( $extras as $pid => $pdata ) : ?>
					<a class="button <?php echo ( (string) $pid === $profile ) ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'profile', $pid, $base ) ); ?>"><?php echo esc_html( $pid ); ?></a>
				<?php endforeach; ?>
				<form method="post" action="" style="display:inline-block;margin-left:10px">
					<?php wp_nonce_field( 'trrocket_save_switcher', 'trrocket_switcher_nonce' ); ?>
					<input type="text" name="sw_new_profile" class="regular-text" style="width:140px" placeholder="<?php esc_attr_e( 'header, footer…', 'translate-rocket' ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( '+ Add profile', 'translate-rocket' ); ?></button>
				</form>
				<p class="description" style="margin:10px 0 0">
					<?php esc_html_e( 'Insert with:', 'translate-rocket' ); ?>
					<code><?php echo ( 'default' === $profile ) ? '[translaterocket_switcher]' : '[translaterocket_switcher id=&quot;' . esc_html( $profile ) . '&quot;]'; ?></code>
					<?php if ( 'default' !== $profile ) : ?>
						&nbsp;
						<form method="post" action="" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this profile?', 'translate-rocket' ) ); ?>');">
							<?php wp_nonce_field( 'trrocket_save_switcher', 'trrocket_switcher_nonce' ); ?>
							<input type="hidden" name="sw_delete_profile" value="<?php echo esc_attr( $profile ); ?>" />
							<button type="submit" class="button-link" style="color:#b32d2e"><?php esc_html_e( 'Delete profile', 'translate-rocket' ); ?></button>
						</form>
					<?php endif; ?>
				</p>
			</div>

			<?php // 6/10/2026 (Federico): with the languages in the header menu the theme draws them — the preview is the real site. ?>
			<div class="trrocket-card" id="trr-sw-site" <?php echo ( ! empty( $sw['in_menu'] ) || ! empty( $sw['footer_row'] ) || ( 'default' === $profile && ! empty( $sw['in_spot'] ) ) ) ? '' : 'hidden'; ?>>
				<h2><?php esc_html_e( 'Your site, live', 'translate-rocket' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Your real header, as visitors will see it with the settings on this page — before you save. In the menu your theme draws the languages; the colours and the opening you choose here are applied to them.', 'translate-rocket' ); ?></p>
				<div class="trr-sw-site-wrap" id="trr-sw-site-top" data-wait="<?php esc_attr_e( 'Loading the preview…', 'translate-rocket' ); ?>" <?php echo ( ! empty( $sw['in_menu'] ) || ( 'default' === $profile && ! empty( $sw['in_spot'] ) ) ) ? '' : 'hidden'; ?>><iframe id="trr-sw-site-frame" title="<?php esc_attr_e( 'Your site, live', 'translate-rocket' ); ?>"></iframe></div>
				<h3 id="trr-sw-site-foot-h" <?php echo ! empty( $sw['footer_row'] ) ? '' : 'hidden'; ?>><?php esc_html_e( 'Bottom of the page', 'translate-rocket' ); ?></h3>
				<div class="trr-sw-site-wrap" id="trr-sw-site-foot" data-wait="<?php esc_attr_e( 'Loading the preview…', 'translate-rocket' ); ?>" <?php echo ! empty( $sw['footer_row'] ) ? '' : 'hidden'; ?>><iframe id="trr-sw-site-frame2" title="<?php esc_attr_e( 'Bottom of the page', 'translate-rocket' ); ?>"></iframe></div>
			</div>
			<?php // 9/10/2026 (Federico: «si vede solo su Predefinito?»): when the live view is not there, say why. ?>
			<p class="description trrocket-card" id="trr-sw-site-none" <?php echo ( ! empty( $sw['in_menu'] ) || ! empty( $sw['footer_row'] ) || ( 'default' === $profile && ! empty( $sw['in_spot'] ) ) ) ? 'hidden' : ''; ?>>
				<?php
				printf(
					/* translators: %s: the shortcode of this profile */
					esc_html__( '«Your site, live» appears when TranslateRocket places this profile by itself: in the header menu, in a spot of your page or as the row at the bottom of every page. Here it is placed by you, with %s or the «Language switcher» block, so the preview below shows it on its own.', 'translate-rocket' ),
					'<code>' . ( 'default' === $profile ? '[translaterocket_switcher]' : '[translaterocket_switcher id=&quot;' . esc_html( $profile ) . '&quot;]' ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
			</p>
			<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
				<form method="post" action="" style="flex:1 1 380px;min-width:340px">
					<?php wp_nonce_field( 'trrocket_save_switcher', 'trrocket_switcher_nonce' ); ?>
					<input type="hidden" name="sw_profile" value="<?php echo esc_attr( $profile ); ?>" />

					<?php // 5/10/2026: prima dove va il selettore, poi come appare; colori e rifiniture in «More style options». ?>
					<div class="trrocket-card">
						<h2><?php esc_html_e( 'Placement', 'translate-rocket' ); ?></h2>
						<?php $trr_extra = 'default' !== $profile; // 6/10/2026 (Federico): a profile like «header» can be the one in the header menu. ?>
						<?php if ( $trr_extra ) : ?>
							<p class="description"><?php esc_html_e( 'Each profile is one switcher in one place. Choose «Header» or «Footer» and this profile goes there; only one profile can be in the header and one in the footer. Floating and a spot on your page are set in the Default profile.', 'translate-rocket' ); ?> <?php $this->info( __( 'For example: a profile «header» with small flags in the header menu, and a profile «footer» with a table of languages at the bottom of every page, each with its own look. Choosing «Header» or «Footer» here moves that place to this profile. «Only where I add it» shows the profile only where you put the [translaterocket_switcher id="name"] shortcode or the «Language switcher» block.', 'translate-rocket' ) ); ?></p>
						<?php else : ?>
						<p class="description"><?php esc_html_e( 'Each profile is one switcher in one place: choose where this one goes. Only one profile can be in the header and one in the footer.', 'translate-rocket' ); ?> <?php $this->info( __( 'For example: a profile «header» with small flags in the header menu, and a profile «footer» with a table of languages at the bottom of every page, each with its own look. Create more profiles with «Add profile» above. «Only where I add it» shows the profile only where you put the [translaterocket_switcher] shortcode or the «Language switcher» block.', 'translate-rocket' ) ); ?></p>
						<?php endif; ?>
						<?php
						$placement = ! empty( $g( 'in_spot' ) ) ? 'spot' : ( ! empty( $g( 'in_menu' ) ) ? 'menu' : ( ! empty( $g( 'footer_row' ) ) && empty( $g( 'floating' ) ) ? 'footer' : ( empty( $g( 'floating' ) ) ? 'manual' : (string) $g( 'float_pos' ) ) ) );
						$trr_menus = self::switcher_menu_choices();
						?>
						<?php
						// 8/10/2026 (Federico styled a profile called «header» and the header kept showing Default): only
						// one profile can sit in the header menu, and the page says which one has it right now.
						$trr_menu_owner = '';
						if ( empty( $g( 'in_menu' ) ) ) {
							$trr_all = Settings::get();
							if ( ! empty( $trr_all['switcher']['in_menu'] ) ) {
								$trr_menu_owner = __( 'Default', 'translate-rocket' );
							}
							foreach ( (array) ( $trr_all['switchers'] ?? array() ) as $trr_pid => $trr_p ) {
								if ( is_array( $trr_p ) && ! empty( $trr_p['in_menu'] ) ) {
									$trr_menu_owner = (string) $trr_pid;
								}
							}
						}
						if ( '' !== $trr_menu_owner ) :
							?>
							<p class="description" style="background:#fff8e5;border-left:4px solid #dba617;padding:8px 12px;margin:0 0 10px">
								<?php
								printf(
									/* translators: %s: name of the profile shown in the header menu */
									esc_html__( 'Your header menu shows the «%s» profile, not this one. To style the header with this profile, choose «In my header menu» below and save.', 'translate-rocket' ),
									esc_html( $trr_menu_owner )
								);
								?>
								<button type="button" class="button button-small trr-sw-take-menu" style="margin-left:8px"><?php esc_html_e( 'Use this profile in the header menu', 'translate-rocket' ); ?></button>
							</p>
						<?php endif; ?>
						<p><label><?php esc_html_e( 'Where to show the switcher', 'translate-rocket' ); ?>
							<select name="sw_placement" id="sw_placement">
								<?php
								foreach ( array(
									'menu'         => __( 'Header — in my header menu (recommended)', 'translate-rocket' ),
									'footer'       => __( 'Footer — a row of languages at the bottom of every page', 'translate-rocket' ),
									'spot'         => __( 'In a spot I choose on my page', 'translate-rocket' ),
									'manual'       => __( 'Only where I add it — shortcode / block', 'translate-rocket' ),
									'bottom-right' => __( 'Floating — bottom right', 'translate-rocket' ),
									'bottom-left'  => __( 'Floating — bottom left', 'translate-rocket' ),
									'top-right'    => __( 'Floating — top right', 'translate-rocket' ),
									'top-left'     => __( 'Floating — top left', 'translate-rocket' ),
									'custom'       => __( 'Floating — custom position (X / Y)', 'translate-rocket' ),
								) as $v => $l ) :
									if ( $trr_extra && ! in_array( $v, array( 'menu', 'footer', 'manual' ), true ) ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $placement, $v ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
						</label></p>
						<?php
						// 1.8.1: which profile has the footer, when it is not this one.
						$trr_foot_owner = '';
						if ( empty( $g( 'footer_row' ) ) ) {
							$trr_all = Settings::get();
							if ( ! empty( $trr_all['switcher']['footer_row'] ) && $trr_extra ) {
								$trr_foot_owner = __( 'Default', 'translate-rocket' );
							}
							foreach ( (array) ( $trr_all['switchers'] ?? array() ) as $trr_pid => $trr_p ) {
								if ( (string) $trr_pid !== $profile && is_array( $trr_p ) && ! empty( $trr_p['footer_row'] ) ) {
									$trr_foot_owner = (string) $trr_pid;
								}
							}
						}
						if ( '' !== $trr_foot_owner ) :
							?>
							<p class="description trr-sw-foot-owner" style="background:#f0f6fc;border-left:4px solid #72aee6;padding:8px 12px">
								<?php
								printf(
									/* translators: %s: name of the profile at the bottom of every page */
									esc_html__( 'The row of languages at the bottom of every page uses the «%s» profile. Choose «Footer» here to give it this profile instead.', 'translate-rocket' ),
									esc_html( $trr_foot_owner )
								);
								?>
							</p>
						<?php endif; ?>
						<p id="sw-custom-pos">
							<label><?php esc_html_e( 'X (from left)', 'translate-rocket' ); ?> <input type="text" name="sw_float_x" value="<?php echo esc_attr( (string) $g( 'float_x' ) ); ?>" placeholder="10%" class="small-text" /></label>
							&nbsp;
							<label><?php esc_html_e( 'Y (from top)', 'translate-rocket' ); ?> <input type="text" name="sw_float_y" value="<?php echo esc_attr( (string) $g( 'float_y' ) ); ?>" placeholder="20px" class="small-text" /></label>
							<br><span class="description"><?php esc_html_e( 'Use % or px, e.g. 10% / 20px.', 'translate-rocket' ); ?></span>
						</p>
						<div id="sw-menu-pick">
							<?php if ( $trr_menus ) : ?>
								<p><label><?php esc_html_e( 'Which menu', 'translate-rocket' ); ?>
									<select name="sw_menu_location" id="sw_menu_location">
										<?php foreach ( $trr_menus as $v => $l ) : ?>
											<option value="<?php echo esc_attr( $v ); ?>" <?php selected( (string) $g( 'menu_location', 'auto' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
										<?php endforeach; ?>
									</select>
								</label></p>
								<p class="trr-sw-menupos"><span><?php esc_html_e( 'Where in the menu', 'translate-rocket' ); ?></span>
									<label><input type="radio" name="sw_menu_pos" value="end" <?php checked( 'start' !== (string) $g( 'menu_pos', 'end' ) ); ?> /> <?php esc_html_e( 'At the end', 'translate-rocket' ); ?></label>
									<label><input type="radio" name="sw_menu_pos" value="start" <?php checked( 'start', (string) $g( 'menu_pos', 'end' ) ); ?> /> <?php esc_html_e( 'At the start', 'translate-rocket' ); ?></label>
									<?php $trr_labels = self::switcher_menu_labels(); ?>
									<?php if ( $trr_labels ) : // 6/10/2026 (Federico): between the items of the header, not only first or last. ?>
										<label><input type="radio" name="sw_menu_pos" value="after" id="sw_menu_pos_after" <?php checked( 'after', (string) $g( 'menu_pos', 'end' ) ); ?> /> <?php esc_html_e( 'Right after:', 'translate-rocket' ); ?></label>
										<select name="sw_menu_after" id="sw_menu_after" onchange="document.getElementById('sw_menu_pos_after').checked=true;">
											<?php foreach ( $trr_labels as $trr_l ) : ?>
												<option value="<?php echo esc_attr( $trr_l ); ?>" <?php selected( (string) $g( 'menu_after', '' ), $trr_l ); ?>><?php echo esc_html( $trr_l ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php endif; ?>
								</p>
								<p class="description"><?php esc_html_e( 'The languages become an item of that menu, drawn by your theme like its other items — in the phone menu too, at the top or at the bottom as chosen. With «Dropdown» the other languages open under the current one. On a page without that menu the switcher floats in the corner, so it is never missing.', 'translate-rocket' ); ?></p>
							<?php else : ?>
								<p class="description trr-sw-nomenu"><?php esc_html_e( 'Your theme shows no menu yet. Create one in Appearance → Menus (or in the Site Editor) and put it in the header; until then the switcher floats in the corner.', 'translate-rocket' ); ?></p>
							<?php endif; ?>
						</div>
						<?php if ( ! $trr_extra ) : ?>
						<div id="sw-spot-pick">
							<input type="hidden" name="sw_spot_selector" id="sw_spot_selector" value="<?php echo esc_attr( (string) $g( 'spot_selector' ) ); ?>" />
							<input type="hidden" name="sw_spot_where" id="sw_spot_where" value="<?php echo esc_attr( (string) $g( 'spot_where', 'end' ) ); ?>" />
							<input type="hidden" name="sw_spot_label" id="sw_spot_label" value="<?php echo esc_attr( (string) $g( 'spot_label' ) ); ?>" />
							<p class="trr-sw-spot-now"><strong><?php esc_html_e( 'Spot:', 'translate-rocket' ); ?></strong>
								<span id="sw-spot-text" data-none="<?php esc_attr_e( 'not chosen yet — until then the switcher floats in the corner', 'translate-rocket' ); ?>" data-start="<?php esc_attr_e( 'at the start', 'translate-rocket' ); ?>" data-end="<?php esc_attr_e( 'at the end', 'translate-rocket' ); ?>"></span></p>
							<p>
								<button type="button" class="button button-primary" id="sw-spot-open" data-url="<?php echo esc_url( add_query_arg( 'trrocket_pick', '1', home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Choose on my page', 'translate-rocket' ); ?></button>
								<a href="<?php echo esc_url( add_query_arg( 'trrocket_pick', '1', home_url( '/' ) ) ); ?>" target="trrocket-pick" id="sw-spot-tab"><?php esc_html_e( 'or open it in a new tab', 'translate-rocket' ); ?></a>
							</p>
							<p class="description"><?php esc_html_e( 'Your home page opens with the header, menu and footer outlined: click where the switcher should go, choose the start or the end of it, and you see it there at once. Where that spot is missing or hidden — another page, or the desktop menu on a phone — the switcher floats in the corner, so it is never lost.', 'translate-rocket' ); ?></p>
						</div>
						<div class="trr-sw-picker" id="sw-picker" hidden>
							<div class="trr-sw-picker-box">
								<div class="trr-sw-picker-head"><strong><?php esc_html_e( 'Choose the spot', 'translate-rocket' ); ?></strong>
									<span class="trr-sw-picker-views"><button type="button" class="button button-small is-on" data-w="100%"><?php esc_html_e( 'Desktop', 'translate-rocket' ); ?></button><button type="button" class="button button-small" data-w="390px"><?php esc_html_e( 'Phone', 'translate-rocket' ); ?></button></span>
									<button type="button" class="button" id="sw-picker-close"><?php esc_html_e( 'Close', 'translate-rocket' ); ?></button></div>
								<iframe id="sw-picker-frame" title="<?php esc_attr_e( 'Choose the spot', 'translate-rocket' ); ?>"></iframe>
							</div>
						</div>
						<?php endif; ?>
						<div class="trr-sw-footer" id="sw-footer-pick">
							<p class="trr-sw-footer-lay">
								<label><input type="radio" name="sw_footer_layout" value="row" <?php checked( 'grid' !== (string) $g( 'footer_layout', 'row' ) ); ?> /> <?php esc_html_e( 'In a line', 'translate-rocket' ); ?></label>
								<label><input type="radio" name="sw_footer_layout" value="grid" <?php checked( 'grid', (string) $g( 'footer_layout', 'row' ) ); ?> /> <?php esc_html_e( 'As a table', 'translate-rocket' ); ?></label>
								<?php // 6/10/2026 (Federico): centred or not, and a table of 2, 3 or 4 columns. ?>
								<select name="sw_footer_cols" aria-label="<?php esc_attr_e( 'Columns', 'translate-rocket' ); ?>">
									<option value="0" <?php selected( 0, (int) $g( 'footer_cols', 0 ) ); ?>><?php esc_html_e( 'Columns: as many as fit', 'translate-rocket' ); ?></option>
									<?php foreach ( array( 2, 3, 4 ) as $trr_c ) : ?>
										<option value="<?php echo (int) $trr_c; ?>" <?php selected( $trr_c, (int) $g( 'footer_cols', 0 ) ); ?>><?php echo esc_html( sprintf( /* translators: %d: number of columns. */ __( '%d columns', 'translate-rocket' ), $trr_c ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p class="trr-sw-footer-align"><span><?php esc_html_e( 'Alignment', 'translate-rocket' ); ?></span>
								<label><input type="radio" name="sw_footer_align" value="left" <?php checked( 'left', (string) $g( 'footer_align', 'center' ) ); ?> /> <?php esc_html_e( 'Left', 'translate-rocket' ); ?></label>
								<label><input type="radio" name="sw_footer_align" value="center" <?php checked( ! in_array( (string) $g( 'footer_align', 'center' ), array( 'left', 'right' ), true ) ); ?> /> <?php esc_html_e( 'Centre', 'translate-rocket' ); ?></label>
								<label><input type="radio" name="sw_footer_align" value="right" <?php checked( 'right', (string) $g( 'footer_align', 'center' ) ); ?> /> <?php esc_html_e( 'Right', 'translate-rocket' ); ?></label>
							</p>
							<p class="description"><?php esc_html_e( 'Flags and names side by side under your footer, as many sites do: handy with many languages, and visitors find them where they look for them.', 'translate-rocket' ); ?></p>
						</div>
						<p id="sw-manual-hint" class="description"><?php echo esc_html( 'default' === $profile ? __( 'Add it where you want with the [translaterocket_switcher] shortcode or the “Language switcher” block.', 'translate-rocket' ) : sprintf( /* translators: %s: the shortcode with this profile's name */ __( 'Add it where you want with %s or the “Language switcher” block.', 'translate-rocket' ), '[translaterocket_switcher id="' . $profile . '"]' ) ); ?></p>
					</div>

					<div class="trrocket-card">
						<h2><?php esc_html_e( 'Style', 'translate-rocket' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr><th scope="row"><?php esc_html_e( 'Layout', 'translate-rocket' ); ?></th><td>
								<select id="sw_type" name="sw_type">
									<option value="inline" <?php selected( $g( 'type' ), 'inline' ); ?>><?php esc_html_e( 'Inline (horizontal)', 'translate-rocket' ); ?></option>
									<option value="list" <?php selected( $g( 'type' ), 'list' ); ?>><?php esc_html_e( 'List (vertical)', 'translate-rocket' ); ?></option>
									<option value="grid" <?php selected( $g( 'type' ), 'grid' ); ?>><?php esc_html_e( 'Grid (a table of languages)', 'translate-rocket' ); ?></option>
									<option value="dropdown" <?php selected( $g( 'type' ), 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'translate-rocket' ); ?></option>
									<option value="scroll" <?php selected( $g( 'type' ), 'scroll' ); ?>><?php esc_html_e( 'Scrollable bar (arrows, for many languages)', 'translate-rocket' ); ?></option>
								</select>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Show', 'translate-rocket' ); ?></th><td>
								<select id="sw_show" name="sw_show">
									<option value="both" <?php selected( $g( 'show' ), 'both' ); ?>><?php esc_html_e( 'Flag + name', 'translate-rocket' ); ?></option>
									<option value="flag" <?php selected( $g( 'show' ), 'flag' ); ?>><?php esc_html_e( 'Flag only', 'translate-rocket' ); ?></option>
									<option value="name" <?php selected( $g( 'show' ), 'name' ); ?>><?php esc_html_e( 'Name only', 'translate-rocket' ); ?></option>
									<option value="flagcode" <?php selected( $g( 'show' ), 'flagcode' ); ?>><?php esc_html_e( 'Flag + code (EN, IT…)', 'translate-rocket' ); ?></option>
									<option value="code" <?php selected( $g( 'show' ), 'code' ); ?>><?php esc_html_e( 'Code only (EN, IT…)', 'translate-rocket' ); ?></option>
								</select>
							</td></tr>
						</table>
					</div>

					<details class="trr-sw-more">
						<summary><?php esc_html_e( 'More style options', 'translate-rocket' ); ?> <span class="description"><?php esc_html_e( 'colours, size, font, mobile…', 'translate-rocket' ); ?></span></summary>
					<div class="trrocket-card">
						<table class="form-table" role="presentation">
							<tr><th scope="row"><?php esc_html_e( 'Current language', 'translate-rocket' ); ?></th><td>
								<select id="sw_current" name="sw_current">
									<option value="show" <?php selected( $g( 'current' ), 'show' ); ?>><?php esc_html_e( 'Show', 'translate-rocket' ); ?></option>
									<option value="hide" <?php selected( $g( 'current' ), 'hide' ); ?>><?php esc_html_e( 'Hide', 'translate-rocket' ); ?></option>
								</select>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Language names', 'translate-rocket' ); ?></th><td>
								<label><input type="checkbox" name="sw_english" value="1" <?php checked( ! empty( $g( 'english_names' ) ) ); ?> /> <?php esc_html_e( 'Show in English (otherwise native)', 'translate-rocket' ); ?></label>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Show on', 'translate-rocket' ); ?></th><td>
								<select id="sw_device" name="sw_device">
									<option value="both" <?php selected( $g( 'device', 'both' ), 'both' ); ?>><?php esc_html_e( 'Desktop and mobile', 'translate-rocket' ); ?></option>
									<option value="desktop" <?php selected( $g( 'device', 'both' ), 'desktop' ); ?>><?php esc_html_e( 'Desktop only', 'translate-rocket' ); ?></option>
									<option value="mobile" <?php selected( $g( 'device', 'both' ), 'mobile' ); ?>><?php esc_html_e( 'Mobile only', 'translate-rocket' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Which screen sizes show this switcher. Make one profile “Desktop only” and another “Mobile only” to design a different language menu for each (breakpoint 783px). The Desktop and Phone buttons of the live preview show both.', 'translate-rocket' ); ?></p>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'On mobile', 'translate-rocket' ); ?></th><td>
								<select id="sw_mobile" name="sw_mobile">
									<option value="same" <?php selected( $g( 'mobile' ), 'same' ); ?>><?php esc_html_e( 'Same as desktop', 'translate-rocket' ); ?></option>
									<option value="flags" <?php selected( $g( 'mobile' ), 'flags' ); ?>><?php esc_html_e( 'Flags only', 'translate-rocket' ); ?></option>
									<option value="hide" <?php selected( $g( 'mobile' ), 'hide' ); ?>><?php esc_html_e( 'Hidden', 'translate-rocket' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Appearance on small screens (when shown): show only flags, or hide it.', 'translate-rocket' ); ?></p>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Open dropdown', 'translate-rocket' ); ?></th><td>
								<select id="sw_dd_trigger" name="sw_dd_trigger">
									<option value="click" <?php selected( $g( 'dd_trigger' ), 'click' ); ?>><?php esc_html_e( 'On click', 'translate-rocket' ); ?></option>
									<option value="hover" <?php selected( $g( 'dd_trigger' ), 'hover' ); ?>><?php esc_html_e( 'On mouse over', 'translate-rocket' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Only for the “Dropdown” layout.', 'translate-rocket' ); ?></p>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Arrow', 'translate-rocket' ); ?></th><td>
								<label><input type="checkbox" id="sw_dd_caret" name="sw_dd_caret" value="1" <?php checked( ! empty( $g( 'dd_caret' ) ) ); ?> /> <?php esc_html_e( 'Show an arrow that flips when the dropdown opens', 'translate-rocket' ); ?></label>
								<p class="description"><?php esc_html_e( 'Only for the “Dropdown” layout.', 'translate-rocket' ); ?></p>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Flag corners', 'translate-rocket' ); ?></th><td>
								<div class="trr-corner-pad">
									<input type="number" min="0" max="50" class="trr-corner" data-c="tl" id="sw_flag_tl" name="sw_flag_tl" value="<?php echo (int) $g( 'flag_tl' ); ?>" aria-label="<?php esc_attr_e( 'Top-left corner', 'translate-rocket' ); ?>" />
									<input type="number" min="0" max="50" class="trr-corner" data-c="tr" id="sw_flag_tr" name="sw_flag_tr" value="<?php echo (int) $g( 'flag_tr' ); ?>" aria-label="<?php esc_attr_e( 'Top-right corner', 'translate-rocket' ); ?>" />
									<button type="button" class="trr-corner-link is-linked" id="sw_flag_link" aria-pressed="true" title="<?php esc_attr_e( 'Same value for all corners', 'translate-rocket' ); ?>"><span class="dashicons dashicons-admin-links"></span></button>
									<input type="number" min="0" max="50" class="trr-corner" data-c="bl" id="sw_flag_bl" name="sw_flag_bl" value="<?php echo (int) $g( 'flag_bl' ); ?>" aria-label="<?php esc_attr_e( 'Bottom-left corner', 'translate-rocket' ); ?>" />
									<input type="number" min="0" max="50" class="trr-corner" data-c="br" id="sw_flag_br" name="sw_flag_br" value="<?php echo (int) $g( 'flag_br' ); ?>" aria-label="<?php esc_attr_e( 'Bottom-right corner', 'translate-rocket' ); ?>" />
								</div>
								<p class="description"><?php esc_html_e( 'Rounding (px) per corner. The centre link keeps them equal.', 'translate-rocket' ); ?></p>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Width', 'translate-rocket' ); ?></th><td>
								<select id="sw_width" name="sw_width">
									<option value="auto" <?php selected( $g( 'width' ), 'auto' ); ?>><?php esc_html_e( 'Auto', 'translate-rocket' ); ?></option>
									<option value="small" <?php selected( $g( 'width' ), 'small' ); ?>><?php esc_html_e( 'Small', 'translate-rocket' ); ?></option>
									<option value="medium" <?php selected( $g( 'width' ), 'medium' ); ?>><?php esc_html_e( 'Medium', 'translate-rocket' ); ?></option>
									<option value="large" <?php selected( $g( 'width' ), 'large' ); ?>><?php esc_html_e( 'Large', 'translate-rocket' ); ?></option>
									<option value="custom" <?php selected( $g( 'width' ), 'custom' ); ?>><?php esc_html_e( 'Custom (px)', 'translate-rocket' ); ?></option>
								</select>
								<input type="text" id="sw_width_px" name="sw_width_px" value="<?php echo esc_attr( (string) $g( 'width_px' ) ); ?>" placeholder="200px" class="small-text" />
							</td></tr>
						</table>
					</div>

					<div class="trrocket-card">
						<h2><?php esc_html_e( 'Colours', 'translate-rocket' ); ?></h2>
												<p><strong><?php esc_html_e( 'Presets:', 'translate-rocket' ); ?></strong></p>
						<div class="trr-presets">
							<?php
							$presets = array(
								'default' => array( 'label' => __( 'Default', 'translate-rocket' ), 'text' => '#2271b1', 'bg' => '#ffffff', 'border' => '#e2e2e2', 'hover' => '#135e96', 'hoverbg' => '#f0f0f1', 'radius' => '8', 'shadow' => 'light', 'opacity' => '100' ),
								'minimal' => array( 'label' => __( 'Minimal', 'translate-rocket' ), 'text' => '#333333', 'bg' => '#ffffff', 'border' => '#ffffff', 'hover' => '#000000', 'hoverbg' => '#f2f2f2', 'radius' => '0', 'shadow' => 'none', 'opacity' => '100' ),
								'dark' => array( 'label' => __( 'Dark', 'translate-rocket' ), 'text' => '#ffffff', 'bg' => '#1e1e1e', 'border' => '#111111', 'hover' => '#ffd700', 'hoverbg' => '#333333', 'radius' => '8', 'shadow' => 'medium', 'opacity' => '100' ),
								'gold' => array( 'label' => __( 'Gold', 'translate-rocket' ), 'text' => '#e6c558', 'bg' => '#141414', 'border' => '#c9a227', 'hover' => '#ffffff', 'hoverbg' => '#262017', 'radius' => '8', 'shadow' => 'medium', 'opacity' => '100' ),
								'ocean' => array( 'label' => __( 'Ocean', 'translate-rocket' ), 'text' => '#ffffff', 'bg' => '#1e88e5', 'border' => '#1565c0', 'hover' => '#e3f2fd', 'hoverbg' => '#1976d2', 'radius' => '10', 'shadow' => 'medium', 'opacity' => '100' ),
								'glass' => array( 'label' => __( 'Glass', 'translate-rocket' ), 'text' => '#111111', 'bg' => '#ffffff', 'border' => '#ffffff', 'hover' => '#000000', 'hoverbg' => '#ffffff', 'radius' => '12', 'shadow' => 'light', 'opacity' => '55' ),
								'pill' => array( 'label' => __( 'Pill', 'translate-rocket' ), 'text' => '#ffffff', 'bg' => '#4f46e5', 'border' => '#4338ca', 'hover' => '#ffffff', 'hoverbg' => '#4338ca', 'radius' => '999', 'shadow' => 'medium', 'opacity' => '100' ),
								'soft' => array( 'label' => __( 'Soft', 'translate-rocket' ), 'text' => '#475569', 'bg' => '#f8fafc', 'border' => '#e2e8f0', 'hover' => '#1e293b', 'hoverbg' => '#eef2f7', 'radius' => '999', 'shadow' => 'light', 'opacity' => '100' ),
								'sunset' => array( 'label' => __( 'Sunset', 'translate-rocket' ), 'text' => '#ffffff', 'bg' => '#ff6b6b', 'border' => '#ee5a52', 'hover' => '#ffffff', 'hoverbg' => '#e85d5d', 'radius' => '10', 'shadow' => 'medium', 'opacity' => '100' ),
								'forest' => array( 'label' => __( 'Forest', 'translate-rocket' ), 'text' => '#ffffff', 'bg' => '#2e7d32', 'border' => '#1b5e20', 'hover' => '#c8e6c9', 'hoverbg' => '#388e3c', 'radius' => '8', 'shadow' => 'medium', 'opacity' => '100' ),
								'midnight' => array( 'label' => __( 'Midnight', 'translate-rocket' ), 'text' => '#cbd5e1', 'bg' => '#0f172a', 'border' => '#1e293b', 'hover' => '#ffffff', 'hoverbg' => '#1e293b', 'radius' => '10', 'shadow' => 'medium', 'opacity' => '100' ),
								'candy' => array( 'label' => __( 'Candy', 'translate-rocket' ), 'text' => '#9d174d', 'bg' => '#fce7f3', 'border' => '#fbcfe8', 'hover' => '#831843', 'hoverbg' => '#fbcfe8', 'radius' => '999', 'shadow' => 'light', 'opacity' => '100' ),
								'outline' => array( 'label' => __( 'Outline', 'translate-rocket' ), 'text' => '#111111', 'bg' => '#ffffff', 'border' => '#111111', 'hover' => '#ffffff', 'hoverbg' => '#111111', 'radius' => '6', 'shadow' => 'none', 'opacity' => '100' ),
								'paper' => array( 'label' => __( 'Paper', 'translate-rocket' ), 'text' => '#44403c', 'bg' => '#faf8f3', 'border' => '#e7e2d6', 'hover' => '#1c1917', 'hoverbg' => '#f0ece0', 'radius' => '6', 'shadow' => 'light', 'opacity' => '100' ),
								'neon' => array( 'label' => __( 'Neon', 'translate-rocket' ), 'text' => '#e0f2fe', 'bg' => '#0b1020', 'border' => '#38bdf8', 'hover' => '#ffffff', 'hoverbg' => '#13203b', 'radius' => '10', 'shadow' => 'strong', 'opacity' => '100', 'anim' => 'scale', 'hoverfx' => 'grow' ),
								'frost' => array( 'label' => __( 'Frost', 'translate-rocket' ), 'text' => '#0f172a', 'bg' => '#ffffff', 'border' => '#dbeafe', 'hover' => '#1d4ed8', 'hoverbg' => '#eff6ff', 'radius' => '14', 'shadow' => 'light', 'opacity' => '70', 'anim' => 'fade', 'hoverfx' => 'underline' ),
								'smooth' => array( 'label' => __( 'Smooth', 'translate-rocket' ), 'text' => '#1f2937', 'bg' => '#ffffff', 'border' => '#e5e7eb', 'hover' => '#111827', 'hoverbg' => '#f3f4f6', 'radius' => '10', 'shadow' => 'medium', 'opacity' => '100', 'anim' => 'slide', 'hoverfx' => 'lift' ),
							);
							foreach ( $presets as $pkey => $p ) {
								$palpha   = (int) $p['opacity'] / 100;
								$pchip_bg = $palpha < 1 ? \TranslateRocket\Frontend\Switcher::to_rgba( $p['bg'], $palpha ) : $p['bg'];
								$pchip    = 'background:' . $pchip_bg . ';color:' . $p['text'] . ';border:1px solid ' . $p['border'] . ';border-radius:' . (int) $p['radius'] . 'px;';
								?>
								<button type="button" class="trr-preset" data-preset="<?php echo esc_attr( $pkey ); ?>" data-text="<?php echo esc_attr( $p['text'] ); ?>" data-bg="<?php echo esc_attr( $p['bg'] ); ?>" data-border="<?php echo esc_attr( $p['border'] ); ?>" data-hover="<?php echo esc_attr( $p['hover'] ); ?>" data-hoverbg="<?php echo esc_attr( $p['hoverbg'] ); ?>" data-radius="<?php echo esc_attr( $p['radius'] ); ?>" data-shadow="<?php echo esc_attr( $p['shadow'] ); ?>" data-opacity="<?php echo esc_attr( $p['opacity'] ); ?>" data-anim="<?php echo esc_attr( $p['anim'] ?? '' ); ?>" data-hoverfx="<?php echo esc_attr( $p['hoverfx'] ?? '' ); ?>">
									<span class="trr-preset-chip" style="<?php echo esc_attr( $pchip ); ?>"><?php echo wp_kses( \TranslateRocket\Flags::svg( 'it' ) , \TranslateRocket\Kses::html_rules() ); ?> Italiano</span>
									<span class="trr-preset-label"><?php echo esc_html( $p['label'] ); ?></span>
								</button>
								<?php
							}
							?>
						</div>
						<table class="form-table" role="presentation">
							<?php
							$this->color_row( 'sw_text_color', __( 'Text colour', 'translate-rocket' ), (string) $g( 'text_color' ) );
							$this->color_row( 'sw_hover_color', __( 'Text colour (hover)', 'translate-rocket' ), (string) $g( 'hover_color' ) );
							$this->color_row( 'sw_bg_color', __( 'Background colour', 'translate-rocket' ), (string) $g( 'bg_color' ) );
							$this->color_row( 'sw_menu_bg', __( 'Menu background (dropdown)', 'translate-rocket' ), (string) $g( 'menu_bg' ) );
							$this->color_row( 'sw_hover_bg', __( 'Background colour (hover)', 'translate-rocket' ), (string) $g( 'hover_bg' ) );
							$this->color_row( 'sw_border_color', __( 'Border colour', 'translate-rocket' ), (string) $g( 'border_color' ) );
							?>
							<tr><th scope="row"><?php esc_html_e( 'Border', 'translate-rocket' ); ?></th><td>
								<label><input type="checkbox" id="sw_no_border" name="sw_no_border" value="1" <?php checked( (bool) $g( 'no_border' ) ); ?> /> <?php esc_html_e( 'No border at all', 'translate-rocket' ); ?></label>
								<p class="description"><?php esc_html_e( 'Removes the border instead of colouring it. The colour above is then ignored.', 'translate-rocket' ); ?></p>
							</td></tr>
							<?php
							?>
							<tr><th scope="row"><?php esc_html_e( 'Corner radius', 'translate-rocket' ); ?></th><td><input type="text" id="sw_radius" name="sw_radius" value="<?php echo esc_attr( (string) $g( 'radius' ) ); ?>" placeholder="8px" class="small-text" /></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Shadow', 'translate-rocket' ); ?></th><td>
								<select id="sw_shadow" name="sw_shadow">
									<option value="none" <?php selected( $g( 'shadow' ), 'none' ); ?>><?php esc_html_e( 'None', 'translate-rocket' ); ?></option>
									<option value="light" <?php selected( $g( 'shadow' ), 'light' ); ?>><?php esc_html_e( 'Light', 'translate-rocket' ); ?></option>
									<option value="medium" <?php selected( $g( 'shadow' ), 'medium' ); ?>><?php esc_html_e( 'Medium', 'translate-rocket' ); ?></option>
									<option value="strong" <?php selected( $g( 'shadow' ), 'strong' ); ?>><?php esc_html_e( 'Strong', 'translate-rocket' ); ?></option>
								</select>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Open animation', 'translate-rocket' ); ?> <?php $this->info( __( 'How the dropdown menu appears when opened.', 'translate-rocket' ) ); ?></th><td>
								<select id="sw_animation" name="sw_animation">
									<option value="none" <?php selected( $g( 'animation' ), 'none' ); ?>><?php esc_html_e( 'None', 'translate-rocket' ); ?></option>
									<option value="fade" <?php selected( $g( 'animation' ), 'fade' ); ?>><?php esc_html_e( 'Fade', 'translate-rocket' ); ?></option>
									<option value="slide" <?php selected( $g( 'animation' ), 'slide' ); ?>><?php esc_html_e( 'Slide down', 'translate-rocket' ); ?></option>
									<option value="scale" <?php selected( $g( 'animation' ), 'scale' ); ?>><?php esc_html_e( 'Scale', 'translate-rocket' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Only for the “Dropdown” layout.', 'translate-rocket' ); ?></p>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Hover effect', 'translate-rocket' ); ?> <?php $this->info( __( 'A subtle effect on each language when the visitor hovers it.', 'translate-rocket' ) ); ?></th><td>
								<select id="sw_hover_fx" name="sw_hover_fx">
									<option value="none" <?php selected( $g( 'hover_fx' ), 'none' ); ?>><?php esc_html_e( 'None', 'translate-rocket' ); ?></option>
									<option value="lift" <?php selected( $g( 'hover_fx' ), 'lift' ); ?>><?php esc_html_e( 'Lift', 'translate-rocket' ); ?></option>
									<option value="grow" <?php selected( $g( 'hover_fx' ), 'grow' ); ?>><?php esc_html_e( 'Grow', 'translate-rocket' ); ?></option>
									<option value="underline" <?php selected( $g( 'hover_fx' ), 'underline' ); ?>><?php esc_html_e( 'Underline', 'translate-rocket' ); ?></option>
								</select>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Font', 'translate-rocket' ); ?></th><td>
								<select id="sw_font_family" name="sw_font_family">
									<option value="" <?php selected( $g( 'font_family' ), '' ); ?>><?php esc_html_e( 'Theme default', 'translate-rocket' ); ?></option>
									<?php
									$font_labels = array(
										'system'    => 'System (sans-serif)',
										'arial'     => 'Arial',
										'helvetica' => 'Helvetica',
										'segoe'     => 'Segoe UI',
										'verdana'   => 'Verdana',
										'tahoma'    => 'Tahoma',
										'trebuchet' => 'Trebuchet MS',
										'lucida'    => 'Lucida Sans',
										'century'   => 'Century Gothic',
										'impact'    => 'Impact',
										'georgia'   => 'Georgia (serif)',
										'times'     => 'Times New Roman (serif)',
										'palatino'  => 'Palatino (serif)',
										'garamond'  => 'Garamond (serif)',
										'courier'   => 'Courier (monospace)',
										'consolas'  => 'Consolas (monospace)',
									);
									$font_stacks = \TranslateRocket\Frontend\Switcher::font_stacks();
									foreach ( $font_labels as $fk => $fl ) {
										printf(
											'<option value="%1$s" %2$s style="font-family:%3$s">%4$s</option>',
											esc_attr( $fk ),
											selected( $g( 'font_family' ), $fk, false ),
											esc_attr( $font_stacks[ $fk ] ?? '' ),
											esc_html( $fl )
										);
									}
									?>
								</select>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Text style', 'translate-rocket' ); ?></th><td>
								<label><input type="checkbox" id="sw_font_weight" name="sw_font_weight" value="bold" <?php checked( 'bold' === $g( 'font_weight' ) ); ?> /> <?php esc_html_e( 'Bold', 'translate-rocket' ); ?></label>
								&nbsp; <label><input type="checkbox" id="sw_uppercase" name="sw_uppercase" value="1" <?php checked( ! empty( $g( 'uppercase' ) ) ); ?> /> <?php esc_html_e( 'UPPERCASE', 'translate-rocket' ); ?></label>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Font size', 'translate-rocket' ); ?></th><td>
								<input type="text" id="sw_font_size" name="sw_font_size" value="<?php echo esc_attr( (string) $g( 'font_size' ) ); ?>" placeholder="14px" class="small-text" />
								<p class="description"><?php esc_html_e( 'e.g. 14px. Leave empty to inherit from your theme.', 'translate-rocket' ); ?></p>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Flag / text divider', 'translate-rocket' ); ?></th><td>
								<select id="sw_divider" name="sw_divider">
									<option value="none" <?php selected( $g( 'divider' ), 'none' ); ?>><?php esc_html_e( 'None', 'translate-rocket' ); ?></option>
									<option value="pipe" <?php selected( $g( 'divider' ), 'pipe' ); ?>>|</option>
									<option value="bullet" <?php selected( $g( 'divider' ), 'bullet' ); ?>>&#8226;</option>
									<option value="dot" <?php selected( $g( 'divider' ), 'dot' ); ?>>&#183;</option>
									<option value="slash" <?php selected( $g( 'divider' ), 'slash' ); ?>>/</option>
									<option value="dash" <?php selected( $g( 'divider' ), 'dash' ); ?>>&#8211;</option>
								</select>
								<p class="description"><?php esc_html_e( 'Shown only when both flag and name are visible.', 'translate-rocket' ); ?></p>
							</td></tr>
							<tr><th scope="row"><?php esc_html_e( 'Background opacity', 'translate-rocket' ); ?></th><td>
								<input type="range" id="sw_bg_opacity" name="sw_bg_opacity" min="0" max="100" step="5" value="<?php echo esc_attr( (string) $g( 'bg_opacity' ) ); ?>" style="vertical-align:middle;width:160px" />
								<span id="sw_bg_opacity_val" style="margin-left:8px"><?php echo (int) $g( 'bg_opacity' ); ?>%</span>
								<p class="description"><?php esc_html_e( 'Affects only the background — the flag and text stay fully visible.', 'translate-rocket' ); ?></p>
							</td></tr>
						</table>
					</div>

					</details>

					<?php submit_button( __( 'Save switcher', 'translate-rocket' ) ); ?>
				</form>

				<div class="trr-sw-side">
					<div class="trrocket-card trr-sw-sticky">
						<h2><?php esc_html_e( 'Live preview', 'translate-rocket' ); ?></h2>
						<div class="trr-sw-views" role="group" aria-label="<?php esc_attr_e( 'Preview size', 'translate-rocket' ); ?>">
							<button type="button" class="button trr-sw-view is-on" data-view="desktop" aria-pressed="true"><span class="dashicons dashicons-desktop" aria-hidden="true"></span> <?php esc_html_e( 'Desktop', 'translate-rocket' ); ?></button>
							<button type="button" class="button trr-sw-view" data-view="phone" aria-pressed="false"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span> <?php esc_html_e( 'Phone', 'translate-rocket' ); ?></button>
						</div>
						<div id="trr-sw-preview">
							<?php
							// The switcher of the site itself, in a frame of its own: same markup, same
							// CSS, and none of the dashboard's styles leaking into it.
							$trr_doc = \TranslateRocket\Frontend\Switcher::preview_document(
								( new \TranslateRocket\Frontend\Switcher() )->preview_parts( $sw, 'desktop' ),
								__( 'Add target languages to see the preview.', 'translate-rocket' )
							);
							?>
							<iframe id="trr-sw-frame" title="<?php esc_attr_e( 'Live preview', 'translate-rocket' ); ?>" srcdoc="<?php echo esc_attr( $trr_doc ); ?>"></iframe>
						</div>
						<div class="trr-sw-bgrow">
							<span class="description"><?php esc_html_e( 'Preview on:', 'translate-rocket' ); ?></span>
							<button type="button" class="trr-sw-bg is-on" data-bg="#ffffff" style="background:#ffffff" title="<?php esc_attr_e( 'White', 'translate-rocket' ); ?>"></button>
							<button type="button" class="trr-sw-bg" data-bg="#f3f4f6" style="background:#f3f4f6" title="<?php esc_attr_e( 'Light grey', 'translate-rocket' ); ?>"></button>
							<button type="button" class="trr-sw-bg" data-bg="#1f2937" style="background:#1f2937" title="<?php esc_attr_e( 'Dark', 'translate-rocket' ); ?>"></button>
							<button type="button" class="trr-sw-bg" data-bg="#4f46e5" style="background:#4f46e5" title="<?php esc_attr_e( 'Brand', 'translate-rocket' ); ?>"></button>
							<button type="button" class="trr-sw-bg" data-bg="#0f766e" style="background:#0f766e" title="<?php esc_attr_e( 'Teal', 'translate-rocket' ); ?>"></button>
							<button type="button" class="trr-sw-bg trr-sw-bg-img" data-bg="img" title="<?php esc_attr_e( 'Photo', 'translate-rocket' ); ?>"></button>
							<input type="color" class="trr-sw-bg-pick" value="#ffffff" title="<?php esc_attr_e( 'Custom background', 'translate-rocket' ); ?>" />
						</div>
						<p class="description"><?php esc_html_e( 'This is the switcher your visitors will see, drawn by the same code as your site, and it follows every change before you save. Click it to try it. Only the font may differ: on your site it takes the theme’s font, unless you choose one above.', 'translate-rocket' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Redirect back to the editor, preserving language and current page (loc).
	 */
	/**
	 * Translation Memory: every detected string across the whole site in one
	 * searchable place, so a term can be found and fixed everywhere at once.
	 */
	public function render_memory_page(): void {
		$targets = (array) Settings::get()['target_languages'];
		echo '<div class="wrap trrocket-wrap">';
		self::header( 'translate-rocket-memory' );
		echo '<h1 class="trr-page-title">' . esc_html__( 'Translation memory', 'translate-rocket' ) . ' ';
		$this->info( __( 'Every string detected across your whole site, in one place. Search a word to find — and fix — it everywhere at once.', 'translate-rocket' ) );
		echo '</h1>';

		if ( empty( $targets ) ) {
			echo '<p>' . esc_html__( 'Add a target language first in TranslateRocket settings.', 'translate-rocket' ) . '</p></div>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$lang = isset( $_GET['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['lang'] ) ) ) : '';
		if ( ! in_array( $lang, $targets, true ) ) {
			$lang = (string) $targets[0];
		}
		$term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$only = ! empty( $_GET['onlymiss'] );
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'translate-rocket' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $_GET['badparts'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of translations that were not saved. */
					_n(
						'%d translation was not saved: the marks that hold the place of a link or a bold word were missing or changed. Open that line again and keep every mark, with its number, anywhere in the sentence.',
						'%d translations were not saved: the marks that hold the place of a link or a bold word were missing or changed. Open those lines again and keep every mark, with its number, anywhere in the sentence.',
						(int) $_GET['badparts'],
						'translate-rocket'
					),
					(int) $_GET['badparts']
				)
			) . '</p></div>';
		}
		if ( isset( $_GET['cleaned'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of removed strings. */
					_n( 'Removed %d unused string.', 'Removed %d unused strings.', (int) $_GET['cleaned'], 'translate-rocket' ),
					(int) $_GET['cleaned']
				)
			) . '</p></div>';
		}
		if ( isset( $_GET['aiimproved'] ) ) {
			$n = (int) $_GET['aiimproved'];
			if ( $n < 0 ) {
				self::avviso_ai_fallita( '' );
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
					sprintf(
						/* translators: %d: number of strings. */
						_n( '%d sentence translated by the browser is now translated by the AI.', '%d sentences translated by the browser are now translated by the AI.', $n, 'translate-rocket' ),
						$n
					)
				) . '</p></div>';
			}
		}
		if ( isset( $_GET['aidone'] ) ) {
			$n = (int) $_GET['aidone'];
			if ( $n < 0 ) {
				self::avviso_ai_fallita( '' );
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
					sprintf(
						/* translators: %d: number of strings translated. */
						esc_html__( 'AI translated %d strings.', 'translate-rocket' ),
						$n
					)
				) . '</p></div>';
			}
		}
		// phpcs:enable

		echo '<p class="trr-langbar"><strong>' . esc_html__( 'Language:', 'translate-rocket' ) . '</strong> ';
		foreach ( $targets as $code ) {
			printf(
				'<a class="button %1$s" href="%2$s">%3$s</a> ',
				$code === $lang ? 'button-primary' : '',
				esc_url( add_query_arg( array( 'page' => 'translate-rocket-memory', 'lang' => $code ), admin_url( 'admin.php' ) ) ),
				wp_kses( self::lang_html( (string) $code ), \TranslateRocket\Kses::html_rules() )
			);
		}
		echo '</p>';

		// Clean-up: remove strings that no longer appear on any page.
		$stale = Strings::count_stale( 30 );
		echo '<p>';
		printf(
			'<button type="submit" form="trr-clean-form" name="trr_clean_unused" value="1" class="button" %1$s onclick="return confirm(%2$s)">%3$s</button>',
			$stale > 0 ? '' : 'disabled',
			esc_attr( (string) wp_json_encode( sprintf( /* translators: %d: number of strings. */ __( 'Delete %d strings (and their translations) not seen on any page in the last 30 days? Run “Scan site for text” first so the pages you still have count as seen.', 'translate-rocket' ), $stale ) ) ),
			esc_html( sprintf( /* translators: %d: number of strings. */ __( '🧹 Remove %d unused strings', 'translate-rocket' ), $stale ) )
		);
		$this->info( __( 'Removes strings from pages that no longer exist (deleted or changed). Tip: run the site scan first, otherwise pages you simply haven’t opened recently could be counted as unused.', 'translate-rocket' ) );
		echo '</p>';
		echo '<form id="trr-clean-form" method="post">';
		wp_nonce_field( 'trrocket_save_memory', 'trrocket_memory_nonce' );
		echo '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '" /></form>';

		echo '<form method="get" class="trr-ptoolbar">';
		echo '<input type="hidden" name="page" value="translate-rocket-memory" />';
		echo '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '" />';
		echo '<input type="search" name="s" value="' . esc_attr( $term ) . '" placeholder="' . esc_attr__( 'Search source or translation…', 'translate-rocket' ) . '" class="regular-text" />';
		echo ' <label><input type="checkbox" name="onlymiss" value="1" ' . checked( $only, true, false ) . '> ' . esc_html__( 'Only missing', 'translate-rocket' ) . '</label> ';
		submit_button( __( 'Search', 'translate-rocket' ), 'secondary', '', false );
		echo '</form>';

		// 14/10/2026: 50 at a time, with the real total and the pages (it was the first 300, said «300 strings»).
		$per    = 50;
		$totale = Strings::search_count( $lang, $term, $only );
		$pagine = max( 1, (int) ceil( $totale / $per ) );
		$pg     = isset( $_GET['pg'] ) ? min( $pagine, max( 1, (int) $_GET['pg'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = Strings::search( $lang, $term, $per, $only, ( $pg - 1 ) * $per );
		$pager  = self::memory_pager( $lang, $term, $only, $pg, $pagine );
		printf(
			'<p class="description">%s</p>',
			esc_html(
				$totale > $per
					/* translators: 1: first shown, 2: last shown, 3: all matching strings. */
					? sprintf( __( '%1$s–%2$s of %3$s strings', 'translate-rocket' ), number_format_i18n( ( $pg - 1 ) * $per + 1 ), number_format_i18n( min( $totale, $pg * $per ) ), number_format_i18n( $totale ) )
					/* translators: %d: number of matching strings. */
					: sprintf( _n( '%d string', '%d strings', $totale, 'translate-rocket' ), $totale )
			)
		);

		// Bulk AI for the missing strings of this language.
		// Non language_stats(): quello conta le righe gia' presenti nella tabella
		// delle traduzioni e ignora le stringhe mai accodate per questa lingua,
		// quindi il totale usciva corto.
		$miss_total = Strings::untranslated_count( $lang );
		echo '<p style="margin:8px 0">';
		if ( null !== \TranslateRocket\Providers\Registry::active() ) {
			printf(
				'<button type="submit" form="trr-mem-ai" class="button button-primary" %1$s>%2$s</button> ',
				$miss_total > 0 ? '' : 'disabled',
				esc_html( sprintf( /* translators: %d: number of missing strings. */ __( 'Translate %d missing with AI', 'translate-rocket' ), $miss_total ) )
			);
			$this->info( __( 'Fills every still-missing string for this language using the active AI provider.', 'translate-rocket' ) );
			$dal_browser = Strings::browser_count( $lang );
			if ( $dal_browser > 0 ) {
				printf(
					' <button type="submit" form="trr-mem-improve" class="button">%s</button> ',
					esc_html( sprintf( /* translators: %d: number of sentences translated by the browser. */ _n( 'Improve %d browser translation with AI', 'Improve %d browser translations with AI', $dal_browser, 'translate-rocket' ), $dal_browser ) )
				);
				$this->info( __( 'Sends the sentences the free browser translator translated to the active AI provider, for better quality. Translations you edited yourself are never touched. It uses your AI credit and counts towards the daily character limit.', 'translate-rocket' ) );
			}
		} elseif ( $miss_total > 0 ) {
			echo '<span class="description">' . esc_html__( 'Set up an AI provider on the AI page to bulk-translate the missing strings — or use your browser below, which needs no key.', 'translate-rocket' ) . '</span>';
		} else {
			echo '<span class="description">&#10003; ' . esc_html__( 'Everything is translated in this language.', 'translate-rocket' ) . '</span>';
		}
		echo '</p>';

		// The browser's own translator: no key, no cost, nothing leaves the
		// machine. Prints hidden and shows itself only where it actually works.
		\TranslateRocket\Admin\BrowserEngine::render_panel(
			$lang,
			\TranslateRocket\Plugin::instance()->router()->default_language(),
			$miss_total,
			null === \TranslateRocket\Providers\Registry::active()
		);
		echo '<form id="trr-mem-ai" method="post">';
		wp_nonce_field( 'trrocket_save_memory', 'trrocket_memory_nonce' );
		echo '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '" /><input type="hidden" name="trr_ai_all" value="1" /><input type="hidden" name="s" value="' . esc_attr( $term ) . '" /><input type="hidden" name="onlymiss" value="' . ( $only ? '1' : '' ) . '" /></form>';
		echo '<form id="trr-mem-improve" method="post">';
		wp_nonce_field( 'trrocket_save_memory', 'trrocket_memory_nonce' );
		echo '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '" /><input type="hidden" name="trr_ai_improve" value="1" /></form>';

		echo '<form method="post">';
		wp_nonce_field( 'trrocket_save_memory', 'trrocket_memory_nonce' );
		echo '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '" />';
		echo '<input type="hidden" name="s" value="' . esc_attr( $term ) . '" />';
		echo '<input type="hidden" name="onlymiss" value="' . ( $only ? '1' : '' ) . '" />';
		echo '<input type="hidden" name="pg" value="' . (int) $pg . '" />';
		echo $pager; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in memory_pager().
		echo '<table class="widefat striped trr-mem"><tbody>';
		foreach ( $rows as $row ) {
			$key     = $row->type . ( $row->context ? '/' . $row->context : '' );
			$missing = ! ( (int) $row->status > 0 && null !== $row->translation && '' !== $row->translation );
			echo '<tr class="' . ( $missing ? 'trr-emissing' : '' ) . '">';
			echo '<td style="width:42%">' . ( $missing ? '<span class="trr-edot">&#9679;</span> ' : '' )
				. wp_kses_post( \TranslateRocket\Frontend\InlineText::admin_html( (string) $row->original ) )
				. '<div class="trr-etype">' . esc_html( $key ) . '</div></td>';
			echo wp_kses( '<td class="trr-gt-cell">' . $this->gt_link( (string) $row->original, $lang ) . '</td>' , \TranslateRocket\Kses::html_rules() );
			echo '<td><textarea class="large-text trr-etr" rows="1" name="tr[' . (int) $row->string_id . ']" data-src="' . esc_attr( (string) $row->original ) . '">'
				. esc_textarea( (string) $row->translation ) . '</textarea></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo $pager; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in memory_pager().
		echo '<div class="trr-esavebar"><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'translate-rocket' ) . '</button></div>';
		echo '</form>';

		$gt_data = 'window.TRRocketGT=' . wp_json_encode(
			array(
				'nonce' => wp_create_nonce( 'trrocket_gt' ),
				'lang'  => $lang,
				'err'   => __( 'Google Translate is unavailable right now.', 'translate-rocket' ),
			)
		) . ';';
		wp_add_inline_script( 'trrocket-admin-info', $gt_data . self::memory_editor_js() );
		echo '</div>';
	}

	/**
	 * Behaviour for the translation-memory editor (auto-grow textareas + inline
	 * Google-translate buttons). Reads its config from window.TRRocketGT. Returned
	 * as a string so it can be attached with wp_add_inline_script().
	 */
	private static function memory_editor_js(): string {
		return <<<'JS'
( function () {
	var GT = window.TRRocketGT || {};
	function grow( el ) { if ( ! el || 'TEXTAREA' !== el.tagName ) { return; } el.style.height = 'auto'; el.style.height = ( el.scrollHeight + 2 ) + 'px'; }
	Array.prototype.forEach.call( document.querySelectorAll( 'textarea.trr-etr' ), grow );
	document.addEventListener( 'input', function ( e ) { if ( e.target && e.target.classList && e.target.classList.contains( 'trr-etr' ) ) { grow( e.target ); } } );
	function protectTags( t ) { var i = 0; return String( t == null ? '' : t ).replace( /<[^>]+>/g, function () { i++; return '[' + i + ']'; } ); }
	function restoreTags( tr, src ) { var tags = String( src || '' ).match( /<[^>]+>/g ); if ( ! tags || ! tags.length ) { return tr; } return String( tr || '' ).replace( /[\[\{【]\s*(\d+)\s*[\]\}】]/g, function ( m, n ) { var idx = parseInt( n, 10 ) - 1; return ( idx >= 0 && idx < tags.length ) ? tags[ idx ] : m; } ); }
	document.addEventListener( 'click', function ( e ) {
		var gt = e.target.closest ? e.target.closest( 'button.trr-gt' ) : null;
		if ( ! gt ) { return; }
		e.preventDefault();
		var row = gt.closest( 'tr' );
		var ta = row ? row.querySelector( '.trr-etr' ) : null;
		var src = gt.getAttribute( 'data-src' ) || ( ta && ta.getAttribute( 'data-src' ) ) || '';
		if ( ! ta || ! src ) { return; }
		gt.disabled = true; gt.classList.add( 'trr-gt-busy' );
		var fd = new FormData();
		fd.append( 'action', 'trrocket_gt' ); fd.append( 'nonce', GT.nonce ); fd.append( 'lang', GT.lang ); fd.append( 'text', protectTags( src ) );
		fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				gt.disabled = false; gt.classList.remove( 'trr-gt-busy' );
				if ( r && r.success && r.data && r.data.translation ) { ta.value = restoreTags( r.data.translation, src ); grow( ta ); }
				else { gt.title = ( r && r.data && r.data.error ) ? r.data.error : GT.err; }
			} )
			.catch( function () { gt.disabled = false; gt.classList.remove( 'trr-gt-busy' ); gt.title = GT.err; } );
	} );
}() );
JS;
	}

	/**
	 * Save inline edits from the Translation Memory page.
	 */
	public function maybe_save_memory(): void {
		if ( ! isset( $_POST['trrocket_memory_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_memory_nonce'] ) ), 'trrocket_save_memory' ) ) {
			return;
		}
		$lang    = isset( $_POST['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		$targets = (array) Settings::get()['target_languages'];
		if ( ! in_array( $lang, $targets, true ) ) {
			return;
		}
		// Clean-up: remove strings not seen on any page in the last 30 days.
		if ( isset( $_POST['trr_clean_unused'] ) ) {
			$removed = Strings::delete_stale( 30 );
			\TranslateRocket\Cache::flush();
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'translate-rocket-memory',
						'lang'    => $lang,
						'cleaned' => $removed,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// «Improve with AI»: send the sentences the browser translated to the AI.
		if ( isset( $_POST['trr_ai_improve'] ) ) {
			$res = Translator::translate_missing( $lang, '', 0, true );
			\TranslateRocket\Cache::flush();
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'translate-rocket-memory',
						'lang'       => $lang,
						'aiimproved' => $res['ok'] ? (int) $res['count'] : -1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Bulk AI: translate every still-missing string for this language.
		if ( isset( $_POST['trr_ai_all'] ) ) {
			$res = Translator::translate_missing( $lang );
			\TranslateRocket\Cache::flush();
			$args = array(
				'page'   => 'translate-rocket-memory',
				'lang'   => $lang,
				'aidone' => $res['ok'] ? (int) $res['count'] : -1,
			);
			if ( isset( $_POST['s'] ) && '' !== $_POST['s'] ) {
				$args['s'] = sanitize_text_field( wp_unslash( $_POST['s'] ) );
			}
			if ( ! empty( $_POST['onlymiss'] ) ) {
				$args['onlymiss'] = '1';
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}
		$rifiutate = 0;
		if ( isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ) {
			foreach ( wp_unslash( $_POST['tr'] ) as $sid => $tr ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( ! Strings::save_translation( (int) $sid, $lang, \TranslateRocket\Frontend\InlineText::sanitize( (string) $tr ) ) ) {
					++$rifiutate;
				}
			}
		}
		\TranslateRocket\Cache::flush();

		$args = array(
			'page'  => 'translate-rocket-memory',
			'lang'  => $lang,
			'saved' => '1',
		);
		if ( $rifiutate > 0 ) {
			$args['badparts'] = $rifiutate;
		}
		if ( ! empty( $_POST['pg'] ) && (int) $_POST['pg'] > 1 ) {
			$args['pg'] = (int) $_POST['pg'];
		}
		if ( isset( $_POST['s'] ) && '' !== $_POST['s'] ) {
			$args['s'] = sanitize_text_field( wp_unslash( $_POST['s'] ) );
		}
		if ( ! empty( $_POST['onlymiss'] ) ) {
			$args['onlymiss'] = '1';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * «‹ Previous · Page 2 of 17 · Next ›» for the Memory list, keeping the search and «Only missing».
	 */
	private static function memory_pager( string $lang, string $term, bool $only, int $pg, int $pagine ): string {
		if ( $pagine <= 1 ) {
			return '';
		}
		$url = static function ( int $n ) use ( $lang, $term, $only ): string {
			$a = array( 'page' => 'translate-rocket-memory', 'lang' => $lang, 'pg' => $n );
			if ( '' !== $term ) {
				$a['s'] = $term;
			}
			if ( $only ) {
				$a['onlymiss'] = '1';
			}
			return esc_url( add_query_arg( $a, admin_url( 'admin.php' ) ) );
		};
		$out  = '<p class="trr-mem-pager" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0">';
		$out .= $pg > 1 ? '<a class="button" href="' . $url( $pg - 1 ) . '">&lsaquo; ' . esc_html__( 'Previous', 'translate-rocket' ) . '</a>' : '<span class="button disabled" aria-disabled="true">&lsaquo; ' . esc_html__( 'Previous', 'translate-rocket' ) . '</span>';
		/* translators: 1: this page, 2: all pages. */
		$out .= '<span>' . esc_html( sprintf( __( 'Page %1$s of %2$s', 'translate-rocket' ), number_format_i18n( $pg ), number_format_i18n( $pagine ) ) ) . '</span>';
		$out .= $pg < $pagine ? '<a class="button" href="' . $url( $pg + 1 ) . '">' . esc_html__( 'Next', 'translate-rocket' ) . ' &rsaquo;</a>' : '<span class="button disabled" aria-disabled="true">' . esc_html__( 'Next', 'translate-rocket' ) . ' &rsaquo;</span>';
		return $out . '</p>';
	}

	private function redirect_editor( string $lang, string $flag, int $rifiutate = 0 ): void {
		// These redirects all follow a write (save / import / reset) — drop the cache.
		\TranslateRocket\Cache::flush();
		$args = array(
			'page' => 'translate-rocket-strings',
			'lang' => $lang,
			$flag  => '1',
		);
		if ( $rifiutate > 0 ) {
			$args['badparts'] = $rifiutate;
		}
		$loc = isset( $_POST['loc'] ) ? sanitize_text_field( wp_unslash( $_POST['loc'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $loc ) {
			$args['loc'] = $loc;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Build an editor URL.
	 */
	/**
	 * The setup wizard shows nothing but its three steps.
	 *
	 * On the phone, the «pages changed» notice and the review request filled half the screen
	 * INSIDE the first-run setup (24/9/2026, visual check), and other plugins' notices land
	 * there too. Someone setting up for the first time needs one thing to do, not five.
	 */
	public function quiet_wizard(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'translate-rocket-wizard' ) ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
		}
	}

	private function editor_url( string $lang, string $loc = '' ): string {
		$args = array(
			'page' => 'translate-rocket-strings',
			'lang' => $lang,
		);
		if ( '' !== $loc ) {
			$args['loc'] = $loc;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The providers on the AI Translation screen: the built-in ones, plus any another
	 * plugin adds with the trrocket_provider_defs filter ('nokey' => true when it needs
	 * no API key; its provider object comes from trrocket_providers).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function provider_defs(): array {
		$defs = array(
			// Free with the owner's own account (official APIs): first, since they cost nothing.
			'cloudflare' => array(
				'label'  => 'Cloudflare Workers AI',
				'model'  => false,
				'free'   => true,
				'help'   => __( 'Free with your own Cloudflare account: 10,000 «neurons» a day, about 1,900 sentences with Llama 3.3 70B or 11,000 with the plain translation model. No card needed.', 'translate-rocket' ),
				'signup' => 'https://dash.cloudflare.com/sign-up',
				'guide'  => array(
					/* translators: %s: link to Cloudflare */
					sprintf( __( 'Open %s and sign up (a Google account is enough, no card).', 'translate-rocket' ), '<a href="https://dash.cloudflare.com/sign-up" target="_blank" rel="noopener">dash.cloudflare.com</a>' ),
					__( 'In the menu on the left choose <strong>AI → Workers AI</strong>, then <strong>Use REST API</strong>.', 'translate-rocket' ),
					__( 'Press <strong>Create a Workers AI API Token</strong>, then <strong>Create API Token</strong>: copy the token into «API key» below.', 'translate-rocket' ),
					__( 'On the same page copy the <strong>Account ID</strong> (it is also in the address bar, after dash.cloudflare.com/) into «Account ID».', 'translate-rocket' ),
					__( 'Save, then press <strong>Load available models</strong>: if the two models appear, the key works.', 'translate-rocket' ),
				),
				'fields' => array(
					'account' => array( 'text', __( 'Account ID', 'translate-rocket' ), '' ),
					'quality' => array(
						'select',
						__( 'Quality', 'translate-rocket' ),
						array(
							'auto'   => array( __( 'Automatic (recommended)', 'translate-rocket' ), __( 'The best model first; when 80% of the day\'s neurons are used, the volume model finishes the day.', 'translate-rocket' ) ),
							'best'   => array( __( 'Best', 'translate-rocket' ), __( 'Always Llama 3.3 70B: natural sentences, keeps links and bold text, about 1,900 sentences a day.', 'translate-rocket' ) ),
							'volume' => array( __( 'Volume', 'translate-rocket' ), __( 'Always the plain translation model (m2m100): about 11,000 sentences a day, more literal; sentences with links are left to the next provider.', 'translate-rocket' ) ),
						),
					),
				),
			),
			'groq'       => array(
				'label'  => 'Groq',
				'model'  => true,
				'free'   => true,
				'help'   => __( 'Free with your own Groq key: about 1,000 requests a day (twenty sentences each) with gpt-oss-120b, which translates very well.', 'translate-rocket' ),
				'models' => array( 'openai/gpt-oss-120b', 'openai/gpt-oss-20b', 'llama-3.3-70b-versatile' ),
				'signup' => 'https://console.groq.com/keys',
				'guide'  => array(
					/* translators: %s: link to the Groq console */
					sprintf( __( 'Open %s and sign in (a Google account is enough, no card).', 'translate-rocket' ), '<a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com/keys</a>' ),
					__( 'Press <strong>Create API Key</strong>, give it a name, copy the key — it starts with <code>gsk_</code> — into «API key» below.', 'translate-rocket' ),
					__( 'Save, then press <strong>Load available models</strong>: if models appear, the key works.', 'translate-rocket' ),
				),
			),
			'openrouter' => array(
				'label'  => 'OpenRouter',
				'model'  => true,
				'free'   => true,
				'help'   => __( 'Its free models (names ending in «:free») cost nothing: about 50 requests a day, often busy. Good as the last provider of your chain.', 'translate-rocket' ),
				'models' => \TranslateRocket\Providers\OpenRouterProvider::FREE_MODELS,
				'signup' => 'https://openrouter.ai/settings/keys',
				'guide'  => array(
					/* translators: %s: link to OpenRouter */
					sprintf( __( 'Open %s and sign in (a Google account is enough).', 'translate-rocket' ), '<a href="https://openrouter.ai/settings/keys" target="_blank" rel="noopener">openrouter.ai/settings/keys</a>' ),
					__( 'Press <strong>Create API Key</strong>, copy it — it starts with <code>sk-or-</code> — into «API key» below. No credit is needed for the free models.', 'translate-rocket' ),
					__( 'Save, then press <strong>Load available models</strong>: the free models appear.', 'translate-rocket' ),
				),
			),
			'deepl'     => array(
				'label'  => 'DeepL',
				'model'  => false,
				'help'   => __( 'Free keys end in “:fx”. Use “Check usage” below for your live monthly quota.', 'translate-rocket' ),
				'signup' => 'https://www.deepl.com/your-account/keys',
			),
			'openai'    => array(
				'label'  => 'OpenAI',
				'model'  => true,
				'help'   => __( 'Pay-as-you-go (needs a few dollars of credit). gpt-4o-mini is cheap and good.', 'translate-rocket' ),
				'models' => array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1' ),
				'signup' => 'https://platform.openai.com/api-keys',
			),
			'google'    => array(
				'label'  => 'Google Translate',
				'model'  => false,
				'help'   => __( 'Google Cloud Translation API key (requires a Google Cloud project with billing).', 'translate-rocket' ),
				'signup' => 'https://console.cloud.google.com/apis/credentials',
			),
			'gemini'    => array(
				'label'  => 'Google Gemini',
				'model'  => true,
				'help'   => __( 'Has a free tier — create a key in Google AI Studio at no cost.', 'translate-rocket' ),
				'models' => array( 'gemini-flash-lite-latest', 'gemini-flash-latest', 'gemini-2.5-flash', 'gemini-2.5-flash-lite' ),
				'signup' => 'https://aistudio.google.com/apikey',
			),
			'anthropic' => array(
				'label'  => 'Anthropic (Claude)',
				'model'  => true,
				'help'   => __( 'Pay-as-you-go on the Anthropic API (separate from a Claude.ai subscription).', 'translate-rocket' ),
				'models' => array( 'claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-opus-4-8' ),
				'signup' => 'https://console.anthropic.com/settings/keys',
			),
		);
		/**
		 * Extra providers for the AI Translation screen.
		 *
		 * @param array $extra id => array( label, help, model, models, signup, nokey ).
		 */
		$extra = apply_filters( 'trrocket_provider_defs', array() );
		foreach ( (array) $extra as $id => $def ) {
			$id = sanitize_key( (string) $id );
			if ( '' !== $id && ! isset( $defs[ $id ] ) && is_array( $def ) && ! empty( $def['label'] ) ) {
				$defs[ $id ] = array_merge( array( 'model' => false, 'help' => '', 'signup' => '', 'nokey' => false ), $def );
			}
		}
		return $defs;
	}

	/**
	 * Save AI provider settings (keys, models, active provider).
	 */
	public function maybe_save_ai_settings(): void {
		if ( ! isset( $_POST['trrocket_ai_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_ai_nonce'] ) ), 'trrocket_save_ai' ) ) {
			return;
		}

		$settings = Settings::get();
		$ids      = array_keys( self::provider_defs() );

		$active                      = isset( $_POST['active_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['active_provider'] ) ) : '';
		$settings['active_provider'] = in_array( $active, $ids, true ) ? $active : '';

		// Fallback chain: each provider can carry a priority number (1 = first).
		// Build an ordered list of the ones that have one; the Translator tries
		// them in this order and skips to the next when a provider is out of credit.
		$priorities = array();
		$posted_p   = ( isset( $_POST['providers'] ) && is_array( $_POST['providers'] ) ) ? wp_unslash( $_POST['providers'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $ids as $pid ) {
			$prio = isset( $posted_p[ $pid ]['priority'] ) ? (int) $posted_p[ $pid ]['priority'] : 0;
			if ( $prio > 0 ) {
				$priorities[ $pid ] = $prio;
			}
		}
		asort( $priorities );
		$settings['provider_order'] = array_keys( $priorities );
		$settings['ai_daily_limit']  = isset( $_POST['ai_daily_limit'] ) ? max( 0, (int) $_POST['ai_daily_limit'] ) : 0;
		$settings['ai_batch']        = isset( $_POST['ai_batch'] ) && in_array( (int) $_POST['ai_batch'], array( 5, 10, 20, 50 ), true ) ? (int) $_POST['ai_batch'] : 0;
		$settings['ai_guidance']     = isset( $_POST['ai_guidance'] )
			? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['ai_guidance'] ) ), 0, \TranslateRocket\Providers\LlmProvider::GUIDANCE_MAX )
			: '';
		$settings['ai_reset_day']    = isset( $_POST['ai_reset_day'] ) ? max( 0, min( 31, (int) $_POST['ai_reset_day'] ) ) : 0;
		$settings['ai_block_bots']   = ! empty( $_POST['ai_block_bots'] );
		$riuso                       = isset( $_POST['reuse_mode'] ) ? sanitize_key( wp_unslash( $_POST['reuse_mode'] ) ) : 'all';
		$settings['reuse_mode']      = in_array( $riuso, array( 'all', 'no_browser', 'none' ), true ) ? $riuso : 'all';

		if ( ! empty( $_POST['trr_ai_reset'] ) ) {
			\TranslateRocket\AiUsage::reset_usage();
		}

		$posted = ( isset( $_POST['providers'] ) && is_array( $_POST['providers'] ) ) ? wp_unslash( $_POST['providers'] ) : array(); // phpcs:ignore
		foreach ( $ids as $pid ) {
			if ( empty( $settings['providers'][ $pid ] ) || ! is_array( $settings['providers'][ $pid ] ) ) {
				$settings['providers'][ $pid ] = array();
			}
			if ( ! empty( self::provider_defs()[ $pid ]['nokey'] ) ) {
				continue; // A provider without a key (added by another plugin) has none to save.
			}
			$settings['providers'][ $pid ]['api_key'] = isset( $posted[ $pid ]['api_key'] ) ? sanitize_text_field( $posted[ $pid ]['api_key'] ) : '';
			if ( isset( $posted[ $pid ]['model'] ) ) {
				$settings['providers'][ $pid ]['model'] = sanitize_text_field( $posted[ $pid ]['model'] );
			}
			foreach ( (array) ( self::provider_defs()[ $pid ]['fields'] ?? array() ) as $fk => $fdef ) {
				if ( isset( $posted[ $pid ][ $fk ] ) ) {
					$v = sanitize_text_field( (string) $posted[ $pid ][ $fk ] );
					if ( 'select' === $fdef[0] && ! isset( $fdef[2][ $v ] ) ) {
						continue;
					}
					$settings['providers'][ $pid ][ $fk ] = $v;
				}
			}
			// 9/10/2026: Cloudflare token pasted, Account ID empty or wrong (an e-mail, a name): ask
			// Cloudflare which account the token belongs to, and fill it in.
			if ( 'cloudflare' === $pid && '' !== (string) $settings['providers'][ $pid ]['api_key']
				&& ! \TranslateRocket\Providers\CloudflareProvider::valid_account( (string) ( $settings['providers'][ $pid ]['account'] ?? '' ) ) ) {
				$trovato = \TranslateRocket\Providers\CloudflareProvider::find_account( (string) $settings['providers'][ $pid ]['api_key'] );
				if ( '' !== $trovato ) {
					$settings['providers'][ $pid ]['account'] = $trovato;
				}
			}
		}

		Settings::update( $settings );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'translate-rocket-ai',
					'ai_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The "it did not work" box, when AI translation fails.
	 *
	 * It used to be a bare one-line WordPress notice with the raw provider
	 * message: easy to miss entirely, and it never said what to do next. This
	 * says what happened, why, and offers the way out that costs nothing.
	 *
	 * @param string $errore The provider's own message.
	 */
	public static function avviso_ai_fallita( string $errore ): void {
		$memoria = admin_url( 'admin.php?page=translate-rocket-memory' );
		$ai      = admin_url( 'admin.php?page=translate-rocket-ai' );
		?>
		<div class="notice notice-error" style="display:flex;gap:13px;align-items:flex-start;padding:14px 16px;border-left-width:4px">
			<span aria-hidden="true" style="font-size:22px;line-height:1.1">&#9888;&#65039;</span>
			<div>
				<p style="margin:0 0 6px;font-weight:600;font-size:14px">
					<?php esc_html_e( 'The AI translation could not run', 'translate-rocket' ); ?>
				</p>
				<?php if ( '' !== trim( $errore ) ) : ?>
					<p style="margin:0 0 9px;color:#50575e"><?php echo esc_html( self::shown_error( $errore ) ); ?></p>
					<?php if ( self::shown_error( $errore ) !== $errore ) : // 13/10/2026: the provider's own answer, for whoever helps ?>
						<details style="margin:0 0 9px"><summary style="cursor:pointer;color:#50575e"><?php esc_html_e( 'Technical details', 'translate-rocket' ); ?></summary><code style="display:block;white-space:pre-wrap;word-break:break-all;margin-top:6px"><?php echo esc_html( $errore ); ?></code></details>
					<?php endif; ?>
				<?php endif; ?>
				<p style="margin:0">
					<a href="<?php echo esc_url( $ai ); ?>"><?php esc_html_e( 'Check your provider and key', 'translate-rocket' ); ?></a>
					<span style="color:#c3c4c7"> &nbsp;&middot;&nbsp; </span>
					<a href="<?php echo esc_url( $memoria ); ?>"><?php esc_html_e( 'or translate in this browser, free and without a key', 'translate-rocket' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * The engine menu on the Translations screen: the acceptance box for a Labs engine that needs one,
	 * the per-page buttons following the choice, «This browser» handing over to the browser panel.
	 * Returned as a string for wp_add_inline_script().
	 */
	private static function engine_js(): string {
		return <<<'JS'
			(function(){
				var f=document.getElementById('trr-engine-form'),s=document.getElementById('trr-engine');if(!f||!s){return;}
				var W=window.TRRocketEngineAck||{},ack=document.getElementById('trr-engine-ack'),nob=document.getElementById('trr-engine-nobrowser');
				var one=document.getElementById('trr-ai-onepage');
				function sync(){
					var v=s.value;
					if(ack){var w=W[v];ack.hidden=!w;var cb=ack.querySelector('input');cb.required=!!w;if(w){ack.querySelector('.trr-engine-ack-lines').textContent=w.join(' ');}}
					if(nob){nob.hidden=true;}
					var ln=document.getElementById('trr-engine-labsnote'),N=window.TRRocketEngineNote||{};if(ln){ln.hidden=!N[v];ln.textContent=N[v]||'';ln.classList.toggle('is-unofficial',/labs:(edge|yandex)/.test(v));}
					var mm=document.getElementById('trr-engine-mm');if(mm){mm.hidden=('labs:mymemory'!==v);}
					// The per-page «AI» buttons follow the choice (the browser works on the whole language: they keep the priority order).
					if(one){var h=one.querySelector('input[name="engine"]');if(!h){h=document.createElement('input');h.type='hidden';h.name='engine';one.appendChild(h);}h.value=('browser'===v||W[v])?'':v;var auto=s.querySelector('option[value=""]'),no=!h.value&&(!auto||auto.disabled);document.querySelectorAll('button[form="trr-ai-onepage"]').forEach(function(x){x.disabled=no;});}
				}
				s.addEventListener('change',sync);sync();
				// 14/10/2026: one menu. The list shows each engine with its tier on the left (FREE in green, FREE with a
				// daily limit in amber, a paid key in blue), the quality on the right and the limit under the name.
				var I=document.getElementById('trr-engine-info'),pk=document.getElementById('trr-epick'),pb=document.getElementById('trr-epick-btn'),pl=document.getElementById('trr-epick-list');
				function a(o,k){return o?(o.getAttribute('data-'+k)||''):'';}
				function el(tag,cls,txt){var e=document.createElement(tag);if(cls){e.className=cls;}if(txt){e.textContent=txt;}return e;}
				function badge(t){return el('span','trr-etier trr-etier-'+(t||'auto'),t?a(I,'t-'+t):'');}
				function riga(o,dove){
					var t=a(o,'tier');dove.appendChild(badge(t));
					var c=el('span','trr-ename');c.appendChild(el('strong','',a(o,'name')||o.textContent));
					var sub=o.disabled?a(o,'why'):a(o,'limit');if(sub){c.appendChild(el('small','',sub));}
					dove.appendChild(c);
					if(a(o,'quality')){dove.appendChild(el('span','trr-equal trr-equal-'+a(o,'qkey'),a(o,'quality')));}
				}
				function info(){
					if(!I){return;}var o=s.options[s.selectedIndex];I.textContent='';
					if(!o||!a(o,'tier')&&!a(o,'needs')){I.hidden=true;return;}
					[['quality','quality'],['limit','limit'],['needs','needs'],['where','where'],['goes','goes']].forEach(function(k){
						var v=a(o,k[1]);if(!v){return;}var r=el('span','trr-einfo-row');r.appendChild(el('b','',a(I,'l-'+k[0])+': '));r.appendChild(document.createTextNode(v));I.appendChild(r);
					});
					I.className='trr-engine-info trr-engine-info-'+(a(o,'tier')||'auto');I.hidden=!I.firstChild;
				}
				function pannello(){
					var bx=document.getElementById('trr-browser-engine'),bg=document.getElementById('trr-browser-go');
					if(bx){bx.style.display=('browser'===s.value)?'':'none';}
					if(bg){bg.style.display='none';}
				}
				function bottone(){if(!pb){return;}pb.textContent='';var o=s.options[s.selectedIndex];if(o){riga(o,pb);}pb.appendChild(el('span','trr-epick-caret','\u25BE'));}
				function chiudi(f){if(!pl||pl.hidden){return;}pl.hidden=true;pb.setAttribute('aria-expanded','false');if(f){pb.focus();}}
				function scegli(v){if(s.value!==v){s.value=v;s.dispatchEvent(new Event('change',{bubbles:true}));}chiudi(true);}
				function apri(){
					pl.textContent='';var gr='';
					Array.prototype.forEach.call(s.options,function(o,i){
						var g=o.parentNode&&'OPTGROUP'===o.parentNode.tagName?o.parentNode.label:'';
						if(g!==gr){gr=g;if(g){pl.appendChild(el('span','trr-epick-group trr-epick-group-'+a(o,'tier'),g));}}
						var it=el('span','trr-epick-item'+(o.disabled?' is-off':'')+(i===s.selectedIndex?' is-on':''));
						it.id='trr-epick-o'+i;it.setAttribute('role','option');it.setAttribute('aria-selected',i===s.selectedIndex?'true':'false');if(o.disabled){it.setAttribute('aria-disabled','true');}
						it.setAttribute('data-v',o.value);riga(o,it);
						it.addEventListener('click',function(){if(!o.disabled){scegli(o.value);}});
						pl.appendChild(it);
					});
					pl.hidden=false;pb.setAttribute('aria-expanded','true');pl.focus();
					var on=pl.querySelector('.is-on');if(on){pl.setAttribute('aria-activedescendant',on.id);on.scrollIntoView({block:'nearest'});}
				}
				function muovi(d){
					var tutti=Array.prototype.slice.call(pl.querySelectorAll('.trr-epick-item:not(.is-off)'));if(!tutti.length){return;}
					var cur=pl.querySelector('.is-focus')||pl.querySelector('.is-on'),i=tutti.indexOf(cur);i=Math.max(0,Math.min(tutti.length-1,(i<0?0:i+d)));
					tutti.forEach(function(x){x.classList.remove('is-focus');});tutti[i].classList.add('is-focus');pl.setAttribute('aria-activedescendant',tutti[i].id);tutti[i].scrollIntoView({block:'nearest'});
				}
				if(pk&&pb&&pl){
					s.classList.add('is-replaced');pk.hidden=false;bottone();
					pb.addEventListener('click',function(){if(pl.hidden){apri();}else{chiudi(true);}});
					pb.addEventListener('keydown',function(e){if('ArrowDown'===e.key||'ArrowUp'===e.key){e.preventDefault();apri();}});
					pl.addEventListener('keydown',function(e){
						if('ArrowDown'===e.key){e.preventDefault();muovi(1);}else if('ArrowUp'===e.key){e.preventDefault();muovi(-1);}
						else if('Enter'===e.key||' '===e.key){e.preventDefault();var x=pl.querySelector('.is-focus');if(x){scegli(x.getAttribute('data-v'));}else{chiudi(true);}}
						else if('Escape'===e.key||'Tab'===e.key){chiudi('Escape'===e.key);}
					});
					document.addEventListener('click',function(e){if(!pk.contains(e.target)){chiudi(false);}});
				}
				s.addEventListener('change',function(){bottone();info();pannello();});info();pannello();
				// browser-engine.js shows its panel after it has asked the browser: hide it again when another engine is chosen.
				window.setTimeout(pannello,0);window.addEventListener('load',pannello);
				// MyMemory's e-mail: the site's address in one click; Enter in the box must not start translating everything.
				var mmb=document.getElementById('trr-engine-mm-site'),mme=document.getElementById('trr-engine-mm-email');
				if(mmb&&mme){mmb.addEventListener('click',function(){mme.value=mmb.getAttribute('data-email')||'';mme.focus();});}
				// 13/10/2026 (Federico: «how is the e-mail saved? there is no send button»): «Save» saves it at once.
				var mms=document.getElementById('trr-engine-mm-save'),mmt=document.getElementById('trr-engine-mm-state');
				// 14/10/2026 (found by the full run, on a slow bench): the answer of an earlier save arrived after a newer
				// action and wrote «Saved» over «not a valid address». Each action has a number; only the last one speaks.
				var mmSeq=0;
				function salvaMm(){
					if(!mme||!mmt||!window.fetch){return;}
					var mio=++mmSeq,v=mme.value.trim();
					if(v&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)){mmt.textContent=mmt.getAttribute('data-bad');return;}
					var d=new FormData();d.append('action','trrocket_mm_email');d.append('email',v);d.append('trrocket_auto_nonce',(f.querySelector('[name="trrocket_auto_nonce"]')||{}).value||'');
					mmt.textContent='…';
					fetch(window.ajaxurl,{method:'POST',body:d,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
						if(mio!==mmSeq){return;}
						mmt.textContent=(j&&j.success)?mmt.getAttribute(j.data&&j.data.email?'data-on':'data-off'):mmt.getAttribute('data-err');
						if(j&&j.success&&mme.value.trim()===v){mme.value=j.data.email||'';}
					}).catch(function(){if(mio===mmSeq){mmt.textContent=mmt.getAttribute('data-err');}});
				}
				if(mms){mms.addEventListener('click',salvaMm);}
				if(mme){mme.addEventListener('keydown',function(e){if('Enter'===e.key){e.preventDefault();salvaMm();}});}
				// 13/10/2026: «Translate all missing» in short steps (about 25 s each) with a bar, so no request meets
				// the 100 s of Cloudflare or of the host. Without fetch, the form posts as before.
				var T=window.TRRocketStep||{};
				function passi(){
					var go=document.getElementById('trr-engine-go'),box=document.getElementById('trr-step');
					if(!box){box=document.createElement('div');box.id='trr-step';box.className='trr-step';box.setAttribute('role','status');box.innerHTML='<span class="trr-prog-bar"><span class="trr-prog-fill" style="width:0"></span></span> <span class="trr-step-txt"></span>';f.parentNode.insertBefore(box,f.nextSibling);}
					var fill=box.querySelector('.trr-prog-fill'),txt=box.querySelector('.trr-step-txt'),fatte=0,giri=0;
					box.hidden=false;box.classList.remove('is-error');if(go){go.disabled=true;}
					function scrivi(left){txt.textContent=(left>=0?(T.busy||'').replace('%1$s',fatte).replace('%2$s',left):(T.busyPage||'').replace('%s',fatte));if(left>=0){fill.style.width=Math.max(3,Math.round(100*fatte/Math.max(1,fatte+left)))+'%';}}
					function ferma(msg){box.classList.add('is-error');txt.textContent=msg;if(go){go.disabled=false;}}
					function giro(){
						var d=new FormData(f);d.append('action','trrocket_translate_step');d.append('done',fatte);
						fetch(window.ajaxurl,{method:'POST',body:d,credentials:'same-origin'}).then(function(r){
							if(!r.ok){throw new Error('HTTP '+r.status);}return r.json();
						}).then(function(j){
							if(!j||!j.success){throw new Error((j&&j.data&&j.data.error)||'?');}
							var x=j.data;fatte=x.total;giri++;scrivi(x.left);
							if(x.error&&!x.count){ferma(x.error);return;}
							if(x.more&&x.count>0&&giri<400){giro();return;}
							fill.style.width='100%';location.reload();
						}).catch(function(err){ferma((T.lost||'').replace('%s',err&&err.message?err.message:'?'));});
					}
					scrivi(-1);giro();
				}
				f.addEventListener('submit',function(e){
					if('browser'!==s.value){
						if(window.fetch&&window.FormData&&window.ajaxurl&&T.busy){e.preventDefault();passi();}
						return;
					}
					e.preventDefault();
					var go=document.getElementById('trr-browser-go'),box=document.getElementById('trr-browser-engine');
					if(go&&box&&box.offsetParent!==null&&!go.disabled&&!box.querySelector('#trr-browser-fallback:not([hidden])')){box.scrollIntoView({behavior:'smooth',block:'center'});go.click();}
					else if(nob){nob.hidden=false;}
				});
			})();
JS;
	}

	/**
	 * The engines offered on the Translations screen (10/10/2026, Federico: «let people choose which
	 * one translates; with Labs, every engine»). Every engine is listed, the ones not usable here too,
	 * greyed out with the reason: an AI without its key, a Labs engine without Labs.
	 *
	 * @return array<int,array{value:string,label:string,group:string,ok:bool,why:string,provider:string,labs:string,switch_on:bool,warning:array}>
	 */
	public static function engine_choices(): array {
		$defs  = self::provider_defs();
		$catena = Registry::ordered();
		$out   = array(
			array(
				'value'     => '',
				'label'     => __( 'Automatic: your priority order', 'translate-rocket' ),
				'group'     => '',
				'ok'        => ! empty( $catena ),
				'why'       => empty( $catena ) ? __( 'no AI key yet', 'translate-rocket' ) : '',
				'provider'  => '',
				'labs'      => '',
				'switch_on' => false,
				'warning'   => array(),
			),
		);
		// Labs' server engines: their id on the Automatic page => their provider id.
		$labs_ids = array(
			'mymemory' => 'mymemory',
			'edge'     => 'edge-unofficial',
			'yandex'   => 'yandex-unofficial',
		);
		// 11/10/2026 (Federico: «choose the engine, an official endpoint or not, and warn when it is from Labs»).
		$ufficiale = array(
			'mymemory' => true,
			'edge'     => false,
			'yandex'   => false,
		);
		$per_id = array();
		foreach ( Registry::all() as $p ) {
			$per_id[ $p->id() ] = $p;
		}
		foreach ( $defs as $id => $def ) {
			if ( in_array( $id, $labs_ids, true ) ) {
				continue;
			}
			$p     = $per_id[ $id ] ?? null;
			$resto = ( $p && $p->is_configured() ) ? \TranslateRocket\Providers\FreeUsage::left_note( (string) $id ) : '';
			$out[] = array(
				'value'     => (string) $id,
				'label'     => (string) ( $def['label'] ?? $id ) . ( '' !== $resto ? ' — ' . $resto : '' ),
				'group'     => 'ai',
				'ok'        => $p && $p->is_configured(),
				'why'       => ( $p && $p->is_configured() ) ? '' : __( 'no key yet', 'translate-rocket' ),
				'provider'  => (string) $id,
				'labs'      => '',
				'switch_on' => false,
				'warning'   => array(),
			);
		}
		$righe   = AutoPage::labs_engines();
		$accende = has_action( 'trrocket_engine_switch_on' );
		$nomi    = array(
			'edge'     => 'Microsoft Edge Translator',
			'mymemory' => 'MyMemory',
			'yandex'   => 'Yandex Translate',
		);
		foreach ( $labs_ids as $lid => $pid ) {
			$riga = null;
			foreach ( (array) $righe as $r ) {
				if ( ( $r['id'] ?? '' ) === $lid ) {
					$riga = $r;
				}
			}
			// Our own words, in the admin's language (Labs' labels came in English), and said plainly.
			$label = $ufficiale[ $lid ]
				/* translators: %s: name of a free translation service, e.g. MyMemory */
				? sprintf( __( '%s — official service, free daily limit', 'translate-rocket' ), $nomi[ $lid ] )
				/* translators: %s: name of a translator, e.g. Microsoft Edge Translator */
				: sprintf( __( '%s — unofficial endpoint', 'translate-rocket' ), $nomi[ $lid ] );
			if ( null === $righe ) {
				$ok  = false;
				$why = __( 'needs TranslateRocket Labs', 'translate-rocket' );
			} elseif ( ! empty( $riga['on'] ) ) {
				$ok  = isset( $per_id[ $pid ] ) && $per_id[ $pid ]->is_configured();
				$why = $ok ? '' : __( 'not available on this server', 'translate-rocket' );
			} elseif ( $accende && $riga ) {
				$ok  = true;
				$why = __( 'switched on when you use it', 'translate-rocket' );
			} else {
				$ok  = false;
				$why = __( 'off: switch it on in Automatic translation', 'translate-rocket' );
			}
			/**
			 * What is left today of a Labs engine (characters of MyMemory, a pause of Edge or Yandex), said by Labs.
			 *
			 * @param string $note        Words for the menu, '' = nothing.
			 * @param string $provider_id The engine's provider id.
			 */
			$resto = $ok ? (string) apply_filters( 'trrocket_engine_left', '', $pid ) : '';
			$out[] = array(
				'value'     => 'labs:' . $lid,
				'label'     => $label . ( '' !== $resto ? ' — ' . $resto : '' ),
				'group'     => $ufficiale[ $lid ] ? 'labs-ok' : 'labs-no',
				'ok'        => $ok,
				'why'       => $why,
				'provider'  => $pid,
				'labs'      => $lid,
				'switch_on' => $ok && $riga && empty( $riga['on'] ),
				'warning'   => ( $ok && $riga && empty( $riga['on'] ) && empty( $riga['acked'] ) ) ? array_map( 'strval', (array) ( $riga['warning'] ?? array() ) ) : array(),
			);
		}
		// 14/10/2026 (Federico: «one menu, the browser in it as the default, free and unlimited on the left in green,
		// the limits said with full details, and the quality»): every engine with its tier and what it means.
		$out[] = array(
			'value'     => 'browser',
			'label'     => __( 'This browser (Chrome or Edge on a computer)', 'translate-rocket' ),
			'group'     => 'free',
			'ok'        => true,
			'why'       => '',
			'provider'  => '',
			'labs'      => '',
			'switch_on' => false,
			'warning'   => array(),
		);
		$q    = array(
			'fair'      => __( 'Fair', 'translate-rocket' ),
			'good'      => __( 'Good', 'translate-rocket' ),
			'very-good' => __( 'Very good', 'translate-rocket' ),
			'excellent' => __( 'Excellent', 'translate-rocket' ),
		);
		$srv  = __( 'On your server, in the background', 'translate-rocket' );
		$meta = array(
			''              => array( '', '', __( 'Each provider\'s own limit', 'translate-rocket' ), __( 'At least one AI key on AI Translation', 'translate-rocket' ), $srv, __( 'The providers in your priority order', 'translate-rocket' ) ),
			'browser'       => array( 'free', 'good', __( 'No limit', 'translate-rocket' ), __( 'Nothing: Chrome or Edge on a computer (the first time it downloads its language model)', 'translate-rocket' ), __( 'In this browser, while this page is open', 'translate-rocket' ), __( 'Nowhere: the text stays on this computer', 'translate-rocket' ) ),
			'labs:edge'     => array( 'free', 'good', __( 'No fixed daily limit', 'translate-rocket' ), __( 'TranslateRocket Labs (free); you accept its conditions once', 'translate-rocket' ), $srv, __( 'Microsoft, through an unofficial endpoint', 'translate-rocket' ) ),
			'labs:yandex'   => array( 'free', 'fair', __( 'No fixed daily limit', 'translate-rocket' ), __( 'TranslateRocket Labs (free); you accept its conditions once', 'translate-rocket' ), $srv, __( 'Yandex (Russia), through an unofficial endpoint', 'translate-rocket' ) ),
			'groq'          => array( 'limit', 'very-good', __( 'About 1,000 requests a day', 'translate-rocket' ), __( 'A free Groq key: 2 minutes, no card', 'translate-rocket' ), $srv, __( 'Groq (USA)', 'translate-rocket' ) ),
			'cloudflare'    => array( 'limit', 'good', __( '10,000 neurons a day (a few thousand sentences)', 'translate-rocket' ), __( 'A free Cloudflare account and its API token', 'translate-rocket' ), $srv, __( 'Cloudflare', 'translate-rocket' ) ),
			'openrouter'    => array( 'limit', 'very-good', __( '50 requests a day with the free models (1,000 after a one-off $10 of credit)', 'translate-rocket' ), __( 'A free OpenRouter key', 'translate-rocket' ), $srv, __( 'OpenRouter and the provider of the model', 'translate-rocket' ) ),
			'labs:mymemory' => array( 'limit', 'fair', __( 'About 5,000 characters a day, 50,000 with an e-mail address', 'translate-rocket' ), __( 'TranslateRocket Labs (free)', 'translate-rocket' ), $srv, __( 'MyMemory / Translated (it may keep it in its public translation memory)', 'translate-rocket' ) ),
			'deepl'         => array( 'paid', 'excellent', __( 'Free plan with a monthly character allowance, then paid', 'translate-rocket' ), __( 'A DeepL key', 'translate-rocket' ), $srv, __( 'DeepL (EU)', 'translate-rocket' ) ),
			'openai'        => array( 'paid', 'excellent', __( 'Paid per use', 'translate-rocket' ), __( 'An OpenAI key with credit', 'translate-rocket' ), $srv, __( 'OpenAI', 'translate-rocket' ) ),
			'anthropic'     => array( 'paid', 'excellent', __( 'Paid per use', 'translate-rocket' ), __( 'An Anthropic key with credit', 'translate-rocket' ), $srv, __( 'Anthropic', 'translate-rocket' ) ),
			'gemini'        => array( 'paid', 'excellent', __( 'Free tier with limits (not for sites aimed at the EU, Switzerland or the UK), then paid per use', 'translate-rocket' ), __( 'A Gemini key', 'translate-rocket' ), $srv, __( 'Google', 'translate-rocket' ) ),
			'google'        => array( 'paid', 'very-good', __( 'About 500,000 characters a month free, then paid per use', 'translate-rocket' ), __( 'A Google Cloud key', 'translate-rocket' ), $srv, __( 'Google Cloud', 'translate-rocket' ) ),
		);
		$ordine = array( 'free' => 1, 'limit' => 2, 'paid' => 3 );
		foreach ( $out as $k => $c ) {
			$m = $meta[ $c['value'] ] ?? array( 'paid', '', __( 'Paid per use', 'translate-rocket' ), __( 'A key', 'translate-rocket' ), $srv, '' );
			$out[ $k ]['labsg']   = in_array( $c['group'], array( 'labs-ok', 'labs-no' ), true ) ? $c['group'] : '';
			$out[ $k ]['group']   = '' === $c['value'] ? '' : $m[0];
			$out[ $k ]['quality'] = '' !== $m[1] ? $q[ $m[1] ] : '';
			$out[ $k ]['qkey']    = $m[1];
			$out[ $k ]['limit']   = $m[2];
			$out[ $k ]['needs']   = $m[3];
			$out[ $k ]['where']   = $m[4];
			$out[ $k ]['goes']    = $m[5];
		}
		// Automatic first, then free and unlimited, free with a daily limit, paid; the ready ones first in each.
		$pos = array_flip( array_keys( $meta ) );
		usort(
			$out,
			static function ( $a, $b ) use ( $ordine, $pos ) {
				$ga = '' === $a['value'] ? 0 : ( $ordine[ $a['group'] ] ?? 4 );
				$gb = '' === $b['value'] ? 0 : ( $ordine[ $b['group'] ] ?? 4 );
				if ( $ga !== $gb ) {
					return $ga <=> $gb;
				}
				return ( $pos[ $a['value'] ] ?? 99 ) <=> ( $pos[ $b['value'] ] ?? 99 );
			}
		);
		return $out;
	}

	/**
	 * The provider id for the engine chosen on the Translations screen: '' = the priority order,
	 * null = not ready (no key, or a Labs engine whose conditions were not accepted).
	 *
	 * @param string $motore The menu value.
	 * @param bool   $ack    The conditions box was ticked.
	 */
	private static function solo_engine( string $motore, bool $ack ): ?string {
		if ( '' === $motore || 'browser' === $motore ) {
			return '';
		}
		// MyMemory's e-mail, written in its box under the menu (13/10/2026): kept in Labs' own setting.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- both callers checked the nonce.
		if ( 'labs:mymemory' === $motore && isset( $_POST['engine_mm_email'] ) && is_callable( array( '\TranslateRocketLabs\Labs', 'save' ) ) ) {
			\TranslateRocketLabs\Labs::save( array( 'mymemory_email' => sanitize_email( wp_unslash( (string) $_POST['engine_mm_email'] ) ) ) );
		}
		foreach ( self::engine_choices() as $c ) {
			if ( $c['value'] !== $motore ) {
				continue;
			}
			if ( ! empty( $c['switch_on'] ) ) {
				// A Labs engine still off: switched on here, the owner chose it by name.
				if ( ! empty( $c['warning'] ) && ! $ack ) {
					return null;
				}
				do_action( 'trrocket_engine_switch_on', (string) $c['labs'] );
			}
			return (string) $c['provider'];
		}
		return null;
	}

	/**
	 * One short step of «Translate all missing» (13/10/2026, ticket from a server behind Cloudflare in China:
	 * one request of 115 s ended in a 524). Each step stops starting new batches after about 25 s, saves, and
	 * says whether there is more: the page calls it again and moves a bar. The notice is kept up to date as
	 * the steps go, so a closed tab still shows what was done.
	 */
	public function ajax_translate_step(): void {
		check_ajax_referer( 'trrocket_auto_translate', 'trrocket_auto_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'You are not allowed to do this.', 'translate-rocket' ) ), 403 );
		}
		$lang = isset( $_POST['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		if ( ! in_array( $lang, (array) Settings::get()['target_languages'], true ) ) {
			wp_send_json_error( array( 'error' => __( 'You are not allowed to do this.', 'translate-rocket' ) ) );
		}
		$loc    = isset( $_POST['loc'] ) ? sanitize_text_field( wp_unslash( $_POST['loc'] ) ) : '';
		$motore = isset( $_POST['engine'] ) ? sanitize_text_field( wp_unslash( $_POST['engine'] ) ) : '';
		$prima  = isset( $_POST['done'] ) ? max( 0, (int) $_POST['done'] ) : 0;
		if ( ! empty( $_POST['engine_main'] ) && 0 === $prima ) {
			update_user_meta( get_current_user_id(), 'trrocket_strings_engine', $motore );
		}
		$solo = self::solo_engine( $motore, ! empty( $_POST['engine_ack'] ) );
		if ( null === $solo ) {
			$r = array(
				'ok'    => false,
				'count' => 0,
				'error' => __( 'The engine you chose is not ready: check it on the AI Translation screen, or choose another one.', 'translate-rocket' ),
				'more'  => false,
			);
		} else {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$r = Translator::translate_missing( $lang, $loc, 0, false, $solo, microtime( true ) + 25 );
			\TranslateRocket\Cache::flush();
		}
		$totale = $prima + (int) $r['count'];
		// The notice after the last step: the total, or the error when nothing at all was translated.
		set_transient(
			'trrocket_ai_notice_' . get_current_user_id(),
			array(
				'ok'    => $totale > 0 || ( ! empty( $r['ok'] ) && '' === (string) $r['error'] ),
				'count' => $totale,
				'error' => (string) $r['error'],
			),
			300
		);
		wp_send_json_success(
			array(
				'count' => (int) $r['count'],
				'total' => $totale,
				'more'  => ! empty( $r['more'] ),
				'left'  => '' === $loc ? Strings::untranslated_count( $lang ) : -1,
				'error' => '' !== (string) $r['error'] ? self::shown_error( (string) $r['error'] ) : '',
			)
		);
	}

	/**
	 * MyMemory's e-mail from its box under «Translate with», saved at once with «Save» (13/10/2026): kept in Labs'
	 * own setting, the same one Labs → Automatic translation shows.
	 */
	public function ajax_mm_email(): void {
		check_ajax_referer( 'trrocket_auto_translate', 'trrocket_auto_nonce' );
		if ( ! current_user_can( 'manage_options' ) || ! is_callable( array( '\TranslateRocketLabs\Labs', 'save' ) ) ) {
			wp_send_json_error( null, 403 );
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
		\TranslateRocketLabs\Labs::save( array( 'mymemory_email' => $email ) );
		wp_send_json_success( array( 'email' => (string) ( \TranslateRocketLabs\Labs::settings()['mymemory_email'] ?? '' ) ) );
	}

	/**
	 * A provider's raw error («HTTP 403: …», «cURL error 28: …») in plain words with its code; our own
	 * messages (limit reached, no key yet) stay as they are.
	 */
	public static function shown_error( string $errore ): string {
		return preg_match( '/HTTP \d{3}\b|curl error|unreadable answer|safety filter|could not match \d+ items|item count mismatch/i', $errore ) ? self::plain_provider_error( $errore ) : $errore;
	}

	/**
	 * The readable name of what translated a sentence, from the provider id saved with it.
	 */
	public static function engine_name( string $id ): string {
		$fissi = array(
			'browser'           => __( 'This browser', 'translate-rocket' ),
			'visual'            => __( 'Visual editor', 'translate-rocket' ),
			'manual'            => __( 'By hand', 'translate-rocket' ),
			'import'            => __( 'Imported', 'translate-rocket' ),
			'reuse'             => __( 'Reused', 'translate-rocket' ),
			'pieces'            => __( 'Put together from translated parts', 'translate-rocket' ),
			'glossary'          => __( 'Glossary', 'translate-rocket' ),
			'unknown'           => __( 'Not recorded', 'translate-rocket' ),
			'mymemory'          => 'MyMemory (Labs)',
			'edge-unofficial'   => 'Microsoft Edge (Labs)',
			'yandex-unofficial' => 'Yandex (Labs)',
			'google-unofficial' => 'Google (Labs)',
		);
		if ( isset( $fissi[ $id ] ) ) {
			return $fissi[ $id ];
		}
		$defs = self::provider_defs();
		return isset( $defs[ $id ]['label'] ) ? (string) $defs[ $id ]['label'] : ucfirst( str_replace( array( '-', '_' ), ' ', $id ) );
	}

	/**
	 * The labels under a page's name: who translated it, and how many sentences each.
	 *
	 * @param array<string,int> $per provider id => sentences.
	 */
	private static function engine_tags( array $per ): string {
		if ( empty( $per ) ) {
			return '';
		}
		$out = '<span class="trr-etags">';
		foreach ( $per as $id => $n ) {
			$nome = self::engine_name( (string) $id );
			/* translators: 1: who translated (e.g. DeepL), 2: number of sentences. */
			$tit  = sprintf( _n( 'Translated by %1$s: %2$s sentence', 'Translated by %1$s: %2$s sentences', (int) $n, 'translate-rocket' ), $nome, number_format_i18n( (int) $n ) );
			$out .= '<span class="trr-etag trr-etag-' . esc_attr( sanitize_html_class( (string) $id ) ) . '" title="' . esc_attr( $tit ) . '">' . esc_html( $nome ) . ' <b>' . esc_html( number_format_i18n( (int) $n ) ) . '</b></span>';
		}
		return $out . '</span>';
	}

	/**
	 * Run AI auto-translation for a language (optionally a single page).
	 */
	public function maybe_auto_translate(): void {
		// admin_init runs inside admin-ajax.php too: the short steps (same fields) are ajax_translate_step's.
		if ( ! isset( $_POST['trrocket_auto_nonce'] ) || wp_doing_ajax() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_auto_nonce'] ) ), 'trrocket_auto_translate' ) ) {
			return;
		}

		$lang    = isset( $_POST['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		$targets = (array) Settings::get()['target_languages'];
		if ( ! in_array( $lang, $targets, true ) ) {
			return;
		}
		$loc = isset( $_POST['loc'] ) ? sanitize_text_field( wp_unslash( $_POST['loc'] ) ) : '';

		// The engine chosen on the Translations screen (empty = the priority order).
		$motore = isset( $_POST['engine'] ) ? sanitize_text_field( wp_unslash( $_POST['engine'] ) ) : '';
		if ( isset( $_POST['engine_main'] ) ) { // the menu itself, not a per-page button: remembered
			update_user_meta( get_current_user_id(), 'trrocket_strings_engine', $motore );
		}
		$solo = self::solo_engine( $motore, ! empty( $_POST['engine_ack'] ) );
		if ( null === $solo ) {
			set_transient(
				'trrocket_ai_notice_' . get_current_user_id(),
				array(
					'ok'    => false,
					'count' => 0,
					'error' => __( 'The engine you chose is not ready: check it on the AI Translation screen, or choose another one.', 'translate-rocket' ),
				),
				60
			);
			wp_safe_redirect( add_query_arg( array( 'page' => 'translate-rocket-strings', 'lang' => $lang ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$result = Translator::translate_missing( $lang, $loc, 0, false, $solo );
		if ( 0 === (int) $result['count'] && '' !== (string) $result['error'] ) {
			$result['ok'] = false; // nothing done and an error: say the error, not «AI translated 0 strings» (13/10/2026)
		}
		set_transient( 'trrocket_ai_notice_' . get_current_user_id(), $result, 60 );
		\TranslateRocket\Cache::flush();

		$args = array(
			'page' => 'translate-rocket-strings',
			'lang' => $lang,
		);
		if ( '' !== $loc ) {
			$args['loc'] = $loc;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * "Translate this page with AI" button.
	 */
	private function render_ai_button( string $lang, string $loc ): void {
		$active = Registry::active();
		?>
		<form method="post" action="" style="margin:12px 0">
			<?php wp_nonce_field( 'trrocket_auto_translate', 'trrocket_auto_nonce' ); ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
			<input type="hidden" name="loc" value="<?php echo esc_attr( $loc ); ?>" />
			<button type="submit" class="button button-primary" <?php disabled( null === $active ); ?>>
				&#10024; <?php esc_html_e( 'Translate this page with AI', 'translate-rocket' ); ?>
			</button>
			<?php if ( null === $active ) : ?>
				<span class="description" style="margin-left:8px">
					<?php
					printf(
						/* translators: %s: link to the AI settings page. */
						esc_html__( 'Configure an AI provider first in %s.', 'translate-rocket' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=translate-rocket-ai' ) ) . '">' . esc_html__( 'AI Translation', 'translate-rocket' ) . '</a>'
					);
					?>
				</span>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * AI provider settings + bulk auto-translate page.
	 */
	/**
	 * Branded header with pill navigation, shared by every plugin screen.
	 */
	public static function header( string $current ): void {
		// 14/10/2026 (Federico, 11/10: «in order, at the top: Languages, Translations, Automatic, AI, Switcher,
		// Memory, then the rest»): the pages used every day first; the rest under «More», which on a phone (one
		// row that scrolls sideways, where a drop-down would be cut off) stays as plain buttons at the end.
		$pages = array(
			'translate-rocket'            => array( __( 'Languages', 'translate-rocket' ), 'dashicons-translation' ),
			'translate-rocket-strings'    => array( __( 'Translations', 'translate-rocket' ), 'dashicons-edit' ),
			'translate-rocket-auto'       => array( __( 'Automatic', 'translate-rocket' ), 'dashicons-controls-play' ),
			'translate-rocket-ai'         => array( __( 'AI', 'translate-rocket' ), 'dashicons-superhero' ),
			'translate-rocket-switcher'   => array( __( 'Switcher', 'translate-rocket' ), 'dashicons-flag' ),
			'translate-rocket-memory'     => array( __( 'Memory', 'translate-rocket' ), 'dashicons-database' ),
			'translate-rocket-next'       => array( __( 'Next steps', 'translate-rocket' ), 'dashicons-yes-alt' ),
		);
		$altre = array(
			'translate-rocket-import'      => array( __( 'Import', 'translate-rocket' ), 'dashicons-download' ),
			'translate-rocket-exclusions'  => array( __( 'Exclusions', 'translate-rocket' ), 'dashicons-shield' ),
			'translate-rocket-copies'      => array( __( 'Copies', 'translate-rocket' ), 'dashicons-admin-page' ),
			'translate-rocket-diagnostics' => array( __( 'Diagnostics', 'translate-rocket' ), 'dashicons-heart' ),
			'translate-rocket-help'        => array( __( 'Help', 'translate-rocket' ), 'dashicons-sos' ),
		);

		$unseen = \TranslateRocket\Logger::count_unseen();
		// Il numero dei passi in attesa si vede da OGNI schermata del plugin, sulla
		// scheda «Next steps»: la guida e' raggiungibile da ovunque senza ripetere lo
		// stesso elenco in dieci pagine. Ripetuto ovunque diventerebbe carta da parati
		// e smetterebbe di essere letto, che e' quello che e' successo con gli avvisi sparsi.
		$in_attesa = \TranslateRocket\Admin\NextSteps::pending();

		echo '<div class="trr-hero">';
		echo '<img class="trr-hero-full" src="' . esc_url( TRROCKET_URL . 'assets/img/logo-white.png' ) . '" alt="TranslateRocket™" width="1200" height="226" />';
		echo '<div class="trr-hero-info">';
		echo '<div class="trr-hero-tag">' . esc_html__( 'Free, AI-powered translation for WordPress', 'translate-rocket' ) . '</div>';
		echo '<div class="trr-hero-sub">' . esc_html__( 'Unlimited languages · Visual editor · SEO-ready', 'translate-rocket' ) . ' · <span class="trr-hero-ver">v' . esc_html( TRROCKET_VERSION ) . '</span>' . UpdateNotice::pill() . '</div>';
		\TranslateRocket\Admin\PluginLanguage::render();
		echo '</div>';
		echo '</div>';

		echo '<nav class="trr-nav">';
		$pillola = static function ( string $slug, array $p, string $extra = '' ) use ( $current, $unseen, $in_attesa ): string {
			$cls   = ( $slug === $current ) ? 'trr-nav-item is-active' : 'trr-nav-item';
			$badge = '';
			if ( 'translate-rocket-diagnostics' === $slug && $unseen > 0 ) {
				$cls  .= ' has-issues';
				$badge = ' <span class="trr-nav-badge">' . esc_html( (string) $unseen ) . '</span>';
			}
			if ( 'translate-rocket-next' === $slug && $in_attesa > 0 ) {
				$cls  .= ' has-steps';
				$badge = ' <span class="trr-nav-badge trr-nav-steps">' . esc_html( (string) $in_attesa ) . '</span>';
			}
			return '<a class="' . esc_attr( $cls . $extra ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">'
				. '<span class="dashicons ' . esc_attr( $p[1] ) . '"></span> ' . esc_html( $p[0] )
				. $badge
				. '</a>';
		};
		foreach ( $pages as $slug => $p ) {
			echo wp_kses( $pillola( $slug, $p ), \TranslateRocket\Kses::html_rules() );
		}
		// «More ▾»: on a computer a drop-down; it shows which page is open in it, and the problems badge of Diagnostics.
		$dentro = isset( $altre[ $current ] );
		$menu   = '';
		foreach ( $altre as $slug => $p ) {
			$menu .= $pillola( $slug, $p );
		}
		echo wp_kses(
			'<details class="trr-nav-more' . ( $dentro ? ' is-current' : '' ) . '"><summary class="trr-nav-item' . ( $dentro ? ' is-active' : '' ) . ( $unseen > 0 ? ' has-issues' : '' ) . '">'
			. '<span class="dashicons dashicons-ellipsis"></span> ' . esc_html( $dentro ? sprintf( /* translators: %s: name of the open page, e.g. Diagnostics. */ __( 'More: %s', 'translate-rocket' ), $altre[ $current ][0] ) : __( 'More', 'translate-rocket' ) )
			. ( $unseen > 0 ? ' <span class="trr-nav-badge">' . esc_html( (string) $unseen ) . '</span>' : '' )
			. ' <span aria-hidden="true">&#9662;</span></summary><div class="trr-nav-more-menu">' . $menu . '</div></details>',
			\TranslateRocket\Kses::html_rules()
		);
		foreach ( $altre as $slug => $p ) {
			echo wp_kses( $pillola( $slug, $p, ' trr-nav-phone' ), \TranslateRocket\Kses::html_rules() );
		}
		// «Labs ✨»: what the free add-on adds (or Labs itself, once installed). Opens only when clicked.
		$labs = \TranslateRocket\Admin\LabsPromo::slug();
		echo '<a class="trr-nav-item trr-nav-labs' . ( $labs === $current ? ' is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $labs ) ) . '">'
			. '<span class="dashicons dashicons-star-filled"></span> ' . esc_html__( 'Labs ✨', 'translate-rocket' )
			. ' <span class="trr-free-badge">FREE</span>' // 14/10/2026 (Federico: «in green FREE, make it clear it is really free»)
			. '</a>';
		// Optional donation link: keeps the plugin free + supporters get priority help.
		echo '<a class="trr-nav-item trr-nav-support" href="https://translaterocket.com/donate/" target="_blank" rel="noopener" title="'
			. esc_attr__( 'TranslateRocket is 100% free. Support development to keep it free — supporters get priority support & custom help.', 'translate-rocket' ) . '">'
			. '<span class="dashicons dashicons-heart"></span> ' . esc_html__( 'Support the project', 'translate-rocket' )
			. '</a>';
		echo '</nav>';
		// On a phone the buttons are one row that scrolls sideways: bring the current one into view.
		echo '<script>(function(){var n=document.querySelector(".trr-nav"),a=n&&n.querySelector(":scope > a.is-active:not(.trr-nav-phone),:scope > a.trr-nav-phone.is-active");if(a&&n.scrollWidth>n.clientWidth){n.scrollLeft=Math.max(0,a.offsetLeft-n.offsetLeft-16);}'
			. 'var m=n&&n.querySelector(".trr-nav-more");if(m){document.addEventListener("click",function(e){if(m.open&&!m.contains(e.target)){m.open=false;}});document.addEventListener("keydown",function(e){if("Escape"===e.key){m.open=false;}});}})();</script>';

		// Gli avvisi (nostri e di altri plugin) WordPress li stampa SOPRA la schermata, e il
		// suo common.js li sposta qui sotto solo a caricamento finito: su un sito lento anche
		// dopo 2-3 secondi, e tutta la pagina saltava di 170-300 px a ogni clic su una scheda
		// (Federico, 24/9/2026). Si fa lo stesso spostamento subito, mentre la pagina si
		// disegna: common.js poi li trova gia' al loro posto e non si muove piu' niente.
		echo '<hr class="wp-header-end">';
		$sposta = '(function(){var e=document.currentScript&&document.currentScript.previousElementSibling;var c=document.getElementById("wpbody-content");if(!e||!c){return;}'
			. 'Array.prototype.slice.call(c.children).forEach(function(n){if(n.matches&&n.matches("div.updated,div.error,div.notice")&&!n.matches(".inline,.below-h2")){e.parentNode.insertBefore(n,e.nextSibling);e=n;}});})();';
		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $sposta );
		} else {
			echo '<script>' . $sposta . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed string.
		}

		// Il cassetto: da qualunque schermata si vede cosa manca senza perdere la
		// pagina su cui si sta lavorando. Non sulla schermata dedicata, che ha gia'
		// l'elenco per intero.
		if ( 'translate-rocket-next' !== $current ) {
			try {
				\TranslateRocket\Admin\NextSteps::drawer();
			} catch ( \Throwable $e ) {
				unset( $e );   // la barra delle schede non salta mai per colpa del cassetto.
			}
		}
	}

	/**
	 * Original inline rocket logo for the header.
	 */
	private static function logo_svg(): string {
		// TranslateRocket mark: white rocket, "A あ" window, rainbow-gradient flame/fins.
		return '<svg class="trr-hero-logo" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
			. '<defs><linearGradient id="trrHeroFlame" gradientUnits="userSpaceOnUse" x1="24" y1="29" x2="24" y2="46">'
			. '<stop offset="0" stop-color="#ec4899"/><stop offset=".4" stop-color="#8b5cf6"/>'
			. '<stop offset=".72" stop-color="#3b82f6"/><stop offset="1" stop-color="#22d3ee"/></linearGradient></defs>'
			. '<path d="M15 30 L9 35 L15 33 Z" fill="url(#trrHeroFlame)"/>'
			. '<path d="M33 30 L39 35 L33 33 Z" fill="url(#trrHeroFlame)"/>'
			. '<path d="M20 33 C19 37 21 41 24 45 C27 41 29 37 28 33 C26 35 22 35 20 33 Z" fill="url(#trrHeroFlame)"/>'
			. '<path d="M24 4 C30 8 33 15 33 22 L33 29 C33 31 31 32 29 32 L19 32 C17 32 15 31 15 29 L15 22 C15 15 18 8 24 4 Z" fill="#ffffff"/>'
			. '<circle cx="24" cy="17" r="5" fill="#1e1b4b"/>'
			. '<text x="24" y="19.1" text-anchor="middle" font-family="-apple-system,Segoe UI,sans-serif" font-weight="700" font-size="5.7" letter-spacing="-0.25" fill="#ffffff">Aあ</text>'
			. '</svg>';
	}

	/**
	 * Public post types eligible for independent copies.
	 *
	 * @return string[]
	 */
	private function copy_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Bulk-manage independent page copies: per target language, create copies, reattach
	 * (back to strings, kept paused) or delete — for the pages you select.
	 */
	public function render_copies_page(): void {
		$targets = (array) Settings::get()['target_languages'];
		echo '<div class="wrap trrocket-wrap">';
		self::header( 'translate-rocket-copies' );
		echo '<h1 class="trr-page-title">' . esc_html__( 'Independent copies', 'translate-rocket' ) . '</h1>';

		if ( empty( $targets ) ) {
			echo '<div class="trrocket-card"><p>' . esc_html__( 'Add at least one target language first.', 'translate-rocket' ) . '</p></div></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang = isset( $_GET['trr_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['trr_lang'] ) ) : (string) reset( $targets );
		if ( ! in_array( $lang, $targets, true ) ) {
			$lang = (string) reset( $targets );
		}

		// Process a bulk action.
		$done = -1;
		if ( isset( $_POST['trr_copies_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trr_copies_nonce'] ) ), 'trr_copies' ) && current_user_can( 'manage_options' ) ) {
			$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
			$plang  = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : $lang;
			if ( in_array( $plang, $targets, true ) ) {
				$lang = $plang;
			}
			$ids  = ( isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ) ? array_map( 'intval', wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$done = 0;
			foreach ( $ids as $pid ) {
				if ( $pid <= 0 || ! current_user_can( 'edit_post', $pid ) ) {
					continue;
				}
				if ( 'create' === $action ) {
					\TranslateRocket\Copies::create( $pid, $lang );
				} elseif ( 'pause' === $action ) {
					\TranslateRocket\Copies::pause( $pid, $lang );
				} elseif ( 'delete' === $action ) {
					\TranslateRocket\Copies::delete( $pid, $lang );
				} else {
					continue;
				}
				++$done;
			}
		}
		if ( $done >= 0 ) {
			/* translators: %d: number of pages. */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d page updated.', '%d pages updated.', $done, 'translate-rocket' ), $done ) ) . '</p></div>';
		}

		echo '<div class="trrocket-card">';
		echo '<p class="description" style="margin-top:0">' . esc_html__( 'Detaching a page makes a fast, independent copy (a real, cache-friendly page you edit in WordPress). “Back to strings” reattaches it to the source — the copy is kept (paused), not deleted — so you can re-detach later and keep your edits.', 'translate-rocket' ) . '</p>';

		echo '<div style="margin:10px 0">';
		foreach ( $targets as $code ) {
			$url = add_query_arg(
				array(
					'page'     => 'translate-rocket-copies',
					'trr_lang' => $code,
				),
				admin_url( 'admin.php' )
			);
			$cls = ( $code === $lang ) ? 'button button-primary' : 'button';
			echo '<a class="' . esc_attr( $cls ) . '" style="margin-right:6px" href="' . esc_url( $url ) . '">' . wp_kses( self::lang_html( (string) $code ), \TranslateRocket\Kses::html_rules() ) . '</a>';
		}
		echo '</div>';

		$pages = get_posts(
			array(
				'post_type'   => $this->copy_post_types(),
				'post_status' => 'publish',
				'numberposts' => 500,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		?>
		<form method="post">
			<?php wp_nonce_field( 'trr_copies', 'trr_copies_nonce' ); ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
			<div style="display:flex;gap:8px;align-items:center;margin:8px 0 12px">
				<select name="bulk_action">
					<option value="create"><?php esc_html_e( 'Create independent copy', 'translate-rocket' ); ?></option>
					<option value="pause"><?php esc_html_e( 'Back to strings (reattach)', 'translate-rocket' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete copy', 'translate-rocket' ); ?></option>
				</select>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Apply to selected', 'translate-rocket' ); ?></button>
			</div>
			<table class="widefat striped">
				<thead><tr>
					<td class="check-column"><input type="checkbox" id="trr-copies-all" /></td>
					<th><?php esc_html_e( 'Page', 'translate-rocket' ); ?></th>
					<th>
						<?php
						/* translators: %s: language name. */
						echo esc_html( sprintf( __( 'Status (%s)', 'translate-rocket' ), \TranslateRocket\Languages::label( $lang ) ) );
						?>
					</th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $pages as $pg ) :
					$pid    = (int) $pg->ID;
					$cid    = \TranslateRocket\Copies::copy_id( $pid, $lang );
					if ( $cid > 0 && ! ( get_post( $cid ) instanceof \WP_Post ) ) {
						$cid = 0;
					}
					$active = $cid > 0 && \TranslateRocket\Copies::is_active( $pid, $lang );
					?>
					<tr>
						<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $pid ); ?>" /></th>
						<td><strong><?php echo esc_html( '' !== $pg->post_title ? $pg->post_title : __( '(no title)', 'translate-rocket' ) ); ?></strong></td>
						<td>
							<?php if ( $active ) : ?>
								<span style="color:#1a7f37;font-weight:600">&#9679; <?php esc_html_e( 'Independent copy active', 'translate-rocket' ); ?></span>
								&middot; <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $cid . '&action=edit' ) ); ?>"><?php esc_html_e( 'Edit copy', 'translate-rocket' ); ?></a>
							<?php elseif ( $cid > 0 ) : ?>
								<span style="color:#996800;font-weight:600">&#9675; <?php esc_html_e( 'Copy paused (reattached)', 'translate-rocket' ); ?></span>
							<?php else : ?>
								<span style="color:#646970">&mdash; <?php esc_html_e( 'String translation', 'translate-rocket' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		</div>
		</div>
		<?php
		wp_add_inline_script(
			'trrocket-admin-info',
			<<<'JS'
( function () {
	var all = document.getElementById( 'trr-copies-all' );
	if ( ! all ) { return; }
	all.addEventListener( 'change', function () {
		Array.prototype.forEach.call( document.querySelectorAll( 'input[name="ids[]"]' ), function ( c ) { c.checked = all.checked; } );
	} );
}() );
JS
		);
	}

	public function render_ai_page(): void {
		$settings  = Settings::get();
		$providers = (array) $settings['providers'];
		$active    = (string) $settings['active_provider'];
		$targets   = (array) $settings['target_languages'];
		// Saved fallback order → provider id => priority number (1-based), to
		// pre-select each card's priority picker.
		$order_prio = array();
		foreach ( array_values( (array) ( $settings['provider_order'] ?? array() ) ) as $i => $pid ) {
			$order_prio[ (string) $pid ] = $i + 1;
		}

		$defs = self::provider_defs();
		?>
		<div class="wrap trrocket-wrap">
			<?php self::header( 'translate-rocket-ai' ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'AI Translation', 'translate-rocket' ); ?></h1>

			<?php if ( isset( $_GET['ai_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'AI settings saved.', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>

			<?php \TranslateRocket\Admin\LabsPromo::banner( 'ai' ); ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'trrocket_save_ai', 'trrocket_ai_nonce' ); ?>
				<div class="trrocket-card">
					<h2><?php esc_html_e( 'Provider &amp; keys', 'translate-rocket' ); ?> <?php $this->info( __( 'Choose which AI service does the translating and paste its API key. You pay the provider directly — TranslateRocket takes nothing.', 'translate-rocket' ) ); ?></h2>
					<p class="description"><?php esc_html_e( 'You bring your own API key. Text is sent only to the provider you choose, only when you ask to translate.', 'translate-rocket' ); ?></p>
					<div class="trr-ai-activerow">
						<label for="trrocket-active"><?php esc_html_e( 'Active provider', 'translate-rocket' ); ?></label>
						<?php $this->info( __( 'The service used when you click “Translate with AI”. Leave on “None” to translate only by hand or copy-paste.', 'translate-rocket' ) ); ?>
						<select id="trrocket-active" name="active_provider">
							<option value="" <?php selected( '', $active ); ?>><?php esc_html_e( '— None —', 'translate-rocket' ); ?></option>
							<?php foreach ( $defs as $pid => $def ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $pid, $active ); ?>><?php echo esc_html( $def['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="trr-ai-providers">
					<?php
					foreach ( $defs as $pid => $def ) :
						$is_active = ( $pid === $active );
						$has_key   = '' !== (string) ( $providers[ $pid ]['api_key'] ?? '' );
						$senza_chiave = ! empty( $def['nokey'] );
						if ( $senza_chiave ) {
							$trr_p   = \TranslateRocket\Providers\Registry::get( $pid );
							$has_key = $trr_p && $trr_p->is_configured();
						}
						?>
						<?php
						// 5/10/2026: dieci riquadri aperti erano un muro di campi. Aperti solo quello attivo e
						// quelli nella catena di riserva; gli altri su una riga, «Show» li apre.
						$aperto = $is_active || ! empty( $order_prio[ $pid ] );
						?>
						<div class="trr-ai-provider<?php echo $is_active ? ' is-active' : ''; ?><?php echo $aperto ? '' : ' is-folded'; ?>" data-provider="<?php echo esc_attr( $pid ); ?>">
							<div class="trr-ai-phead">
								<strong class="trr-ai-pname"><?php echo esc_html( $def['label'] ); ?></strong>
								<?php if ( ! empty( $def['free'] ) ) : ?><span class="trr-ai-badge" style="background:#00a32a;color:#fff;border:0"><?php esc_html_e( 'Free', 'translate-rocket' ); ?></span><?php endif; ?>
								<?php $provato = self::provider_tested( (string) $pid ); ?>
								<span class="trr-ai-badge trr-ai-tested" <?php echo $provato ? '' : 'hidden'; ?> title="<?php echo esc_attr( $provato ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $provato ) : '' ); ?>">&#10003; <?php esc_html_e( 'Tested', 'translate-rocket' ); ?></span>
								<?php if ( $is_active ) : ?><span class="trr-ai-badge is-on"><?php esc_html_e( 'Active', 'translate-rocket' ); ?></span><?php elseif ( $has_key ) : ?><span class="trr-ai-badge"><?php echo $senza_chiave ? esc_html__( 'Ready', 'translate-rocket' ) : esc_html__( 'Key set', 'translate-rocket' ); ?></span><?php endif; ?>
								<button type="button" class="button-link trr-ai-fold" aria-expanded="<?php echo $aperto ? 'true' : 'false'; ?>" data-show="<?php esc_attr_e( 'Show', 'translate-rocket' ); ?>" data-hide="<?php esc_attr_e( 'Hide', 'translate-rocket' ); ?>"><?php echo $aperto ? esc_html__( 'Hide', 'translate-rocket' ) : esc_html__( 'Show', 'translate-rocket' ); ?></button>
							</div>
							<div class="trr-ai-pbody">
						<table class="form-table" role="presentation">
							<?php if ( $senza_chiave ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'How it works', 'translate-rocket' ); ?></th>
								<td>
									<p class="description"><?php echo esc_html( (string) $def['help'] ); ?></p>
									<?php if ( ! empty( $def['settings_url'] ) ) : ?>
										<p><a class="button button-small" href="<?php echo esc_url( (string) $def['settings_url'] ); ?>"><?php esc_html_e( 'Settings', 'translate-rocket' ); ?></a></p>
									<?php endif; ?>
								</td>
							</tr>
							<?php else : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'API key', 'translate-rocket' ); ?> <?php $this->info( __( 'Your secret key from this provider. Stored on your site only, and sent to the provider when translating.', 'translate-rocket' ) ); ?></th>
								<td>
									<input type="password" class="regular-text" autocomplete="off"
										name="providers[<?php echo esc_attr( $pid ); ?>][api_key]"
										value="<?php echo esc_attr( (string) ( $providers[ $pid ]['api_key'] ?? '' ) ); ?>" />
									<?php if ( '' !== $def['help'] ) : ?>
										<p class="description"><?php echo esc_html( $def['help'] ); ?></p>
									<?php endif; ?>
									<?php if ( ! empty( $def['guide'] ) ) : ?>
										<details class="trr-ai-guide" style="margin:8px 0;padding:6px 10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px"<?php echo $has_key ? '' : ' open'; ?>>
											<summary style="cursor:pointer;font-weight:600">&#128273; <?php esc_html_e( 'Get your free key in 2 minutes', 'translate-rocket' ); ?></summary>
											<ol style="margin:6px 0 4px 20px">
												<?php foreach ( (array) $def['guide'] as $passo ) : ?>
													<li><?php echo wp_kses( (string) $passo, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'strong' => array(), 'code' => array() ) ); ?></li>
												<?php endforeach; ?>
											</ol>
										</details>
									<?php endif; ?>
									<?php foreach ( (array) ( $def['fields'] ?? array() ) as $fk => $fdef ) : ?>
										<?php $fval = (string) ( $providers[ $pid ][ $fk ] ?? '' ); ?>
										<?php if ( 'text' === $fdef[0] ) : ?>
											<p style="margin:8px 0 0"><label style="display:block;font-weight:600"><?php echo esc_html( $fdef[1] ); ?></label>
											<input type="text" class="regular-text code" autocomplete="off" name="providers[<?php echo esc_attr( $pid ); ?>][<?php echo esc_attr( $fk ); ?>]" value="<?php echo esc_attr( $fval ); ?>" /></p>
										<?php else : ?>
											<fieldset style="margin:10px 0 0"><legend style="font-weight:600"><?php echo esc_html( $fdef[1] ); ?></legend>
												<?php $fval = '' !== $fval ? $fval : (string) array_key_first( $fdef[2] ); ?>
												<?php foreach ( $fdef[2] as $ov => $ot ) : ?>
													<label style="display:block;margin:4px 0"><input type="radio" name="providers[<?php echo esc_attr( $pid ); ?>][<?php echo esc_attr( $fk ); ?>]" value="<?php echo esc_attr( $ov ); ?>" <?php checked( $fval, $ov ); ?> /> <strong><?php echo esc_html( $ot[0] ); ?></strong> <span class="description">— <?php echo esc_html( $ot[1] ); ?></span></label>
												<?php endforeach; ?>
											</fieldset>
										<?php endif; ?>
									<?php endforeach; ?>
									<?php if ( ! empty( $def['free'] ) ) : ?>
										<p class="description" style="margin-top:8px">&#128202; <?php echo esc_html( \TranslateRocket\Providers\FreeUsage::line( $pid ) ); ?></p>
									<?php endif; ?>
									<?php
									// DeepL exposes its character quota: show a live meter under the key.
									if ( 'deepl' === $pid && $has_key ) :
										$deepl_usage = \TranslateRocket\Providers\DeepLProvider::usage( (string) ( $providers['deepl']['api_key'] ?? '' ) );
										if ( null !== $deepl_usage && $deepl_usage['limit'] > 0 ) :
											$deepl_pct = min( 100, (int) round( $deepl_usage['count'] / $deepl_usage['limit'] * 100 ) );
											?>
											<p class="description" style="margin-top:8px">
												<?php
												/* translators: 1: characters used, 2: character limit, 3: percentage. */
												$deepl_meter = esc_html__( 'Quota this cycle: %1$s of %2$s characters (%3$d%%).', 'translate-rocket' );
												printf(
													$deepl_meter, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format string escaped above; args escaped below.
													esc_html( number_format_i18n( $deepl_usage['count'] ) ),
													esc_html( number_format_i18n( $deepl_usage['limit'] ) ),
													(int) $deepl_pct
												);
												if ( $deepl_pct >= 100 ) {
													echo ' <strong style="color:#b32d2e">' . esc_html__( 'Exhausted — translations fall back to the next provider in your chain.', 'translate-rocket' ) . '</strong>';
												} elseif ( $deepl_pct >= 90 ) {
													echo ' <strong style="color:#996800">' . esc_html__( 'Almost used up.', 'translate-rocket' ) . '</strong>';
												}
												?>
											</p>
											<div style="max-width:340px;height:6px;border-radius:3px;background:#e2e4ee;overflow:hidden">
												<div style="height:100%;width:<?php echo (int) max( 2, $deepl_pct ); ?>%;background:<?php echo esc_attr( $deepl_pct >= 100 ? '#b32d2e' : ( $deepl_pct >= 90 ? '#dba617' : '#4f8dff' ) ); ?>"></div>
											</div>
											<?php
										endif;
									endif;
									?>
									<?php if ( ! empty( $def['signup'] ) ) : ?>
										<p>
											<a class="button button-small" href="<?php echo esc_url( $def['signup'] ); ?>" target="_blank" rel="noopener">
												&#128273;
												<?php
												/* translators: %s: provider name (e.g. OpenAI). */
												printf( esc_html__( 'Get your %s API key ↗', 'translate-rocket' ), esc_html( $def['label'] ) );
												?>
											</a>
										</p>
									<?php endif; ?>
								</td>
							</tr>
							<?php endif; ?>
							<tr>
									<th scope="row"><?php esc_html_e( 'Fallback priority', 'translate-rocket' ); ?> <?php $this->info( __( 'Optional. Give two or more providers a priority to build a fallback chain: bulk and automatic translation try priority 1 first, and switch to priority 2, 3… whenever one runs out of credit or its key is refused. Leave on “—” to keep a provider out of the chain.', 'translate-rocket' ) ); ?></th>
									<td>
										<?php $cur_prio = (int) ( $order_prio[ $pid ] ?? 0 ); ?>
										<select name="providers[<?php echo esc_attr( $pid ); ?>][priority]">
											<option value="0" <?php selected( 0, $cur_prio ); ?>><?php esc_html_e( '—', 'translate-rocket' ); ?></option>
											<?php for ( $p = 1; $p <= 5; $p++ ) : ?>
												<option value="<?php echo (int) $p; ?>" <?php selected( $p, $cur_prio ); ?>><?php echo (int) $p; ?></option>
											<?php endfor; ?>
										</select>
										<p class="description"><?php esc_html_e( '1 = try first. Providers with no priority are used only as the active one above.', 'translate-rocket' ); ?></p>
									</td>
								</tr>
							<?php if ( $def['model'] ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Model', 'translate-rocket' ); ?> <?php $this->info( __( 'Optional. The specific AI model to use (e.g. gpt-4o-mini). Leave empty for a sensible default.', 'translate-rocket' ) ); ?></th>
									<td>
										<input type="text" class="regular-text" list="trrocket-models-<?php echo esc_attr( $pid ); ?>"
											name="providers[<?php echo esc_attr( $pid ); ?>][model]"
											value="<?php echo esc_attr( (string) ( $providers[ $pid ]['model'] ?? '' ) ); ?>" />
										<?php if ( ! empty( $def['models'] ) ) : ?>
											<datalist id="trrocket-models-<?php echo esc_attr( $pid ); ?>">
												<?php foreach ( $def['models'] as $suggested ) : ?>
													<option value="<?php echo esc_attr( $suggested ); ?>"></option>
												<?php endforeach; ?>
											</datalist>
											<p class="description"><?php esc_html_e( 'Pick a suggested model or type your own.', 'translate-rocket' ); ?></p>
										<?php endif; ?>
										<p style="margin-top:6px">
											<button type="button" class="button trr-load-models" data-provider="<?php echo esc_attr( $pid ); ?>">&#8635; <?php esc_html_e( 'Load available models', 'translate-rocket' ); ?></button>
											<select class="trr-models-select" data-provider="<?php echo esc_attr( $pid ); ?>" style="display:none;margin-left:6px;max-width:280px"></select>
										</p>
										<p class="trr-models-msg" data-provider="<?php echo esc_attr( $pid ); ?>" role="status" aria-live="polite" hidden></p>
										<p class="description"><?php esc_html_e( 'Queries this provider with your key and lists the exact models it can use right now.', 'translate-rocket' ); ?></p>
									</td>
								</tr>
							<?php endif; ?>
						</table>
							<?php // 9/10/2026 (Federico: «per ogni AI un test, senza consumare crediti o pochissimi»): one word, with the saved key. ?>
							<p class="trr-ai-pfoot trr-ai-try-row">
								<input type="text" class="regular-text trr-ai-try-text" maxlength="200" value="<?php echo esc_attr( self::test_sentence( (string) Settings::get()['source_language'] ) ); ?>" aria-label="<?php esc_attr_e( 'Test text', 'translate-rocket' ); ?>">
								<button type="button" class="button button-small trr-ai-try" data-provider="<?php echo esc_attr( $pid ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'trrocket_models' ) ); ?>">&#9654; <?php esc_html_e( 'Test this text', 'translate-rocket' ); ?></button>
								<span class="trr-ai-try-out" role="status" aria-live="polite"></span>
								<span class="description trr-ai-try-tip"><?php esc_html_e( 'Uses the saved key and translates only this short text: it costs almost nothing. Save first if you just pasted the key. When it works, the provider gets a green «Tested» tick.', 'translate-rocket' ); ?></span>
							</p>
							<?php if ( 'deepl' === $pid ) : ?>
								<p class="trr-ai-pfoot"><button type="button" class="button button-small trr-deepl-usage">&#128202; <?php esc_html_e( 'Check usage', 'translate-rocket' ); ?></button> <span class="trr-deepl-usage-out description"></span></p>
							<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
					</div>
					<script>
					( function () {
						// One-word test for each provider (the same check as Diagnostics, where nobody found it).
						document.addEventListener( 'click', function ( ev ) {
							var btn = ev.target.closest ? ev.target.closest( '.trr-ai-try' ) : null;
							if ( ! btn ) { return; }
							var out = btn.parentNode.querySelector( '.trr-ai-try-out' );
							btn.disabled = true;
							out.className = 'trr-ai-try-out is-pending';
							out.textContent = <?php echo wp_json_encode( __( 'Testing…', 'translate-rocket' ) ); ?>;
							var body = 'action=trrocket_test_provider&_wpnonce=' + encodeURIComponent( btn.getAttribute( 'data-nonce' ) ) + '&provider=' + encodeURIComponent( btn.getAttribute( 'data-provider' ) ) + '&text=' + encodeURIComponent( ( btn.parentNode.querySelector( '.trr-ai-try-text' ) || {} ).value || '' );
							var spunta = btn.closest( '.trr-ai-provider' ) ? btn.closest( '.trr-ai-provider' ).querySelector( '.trr-ai-tested' ) : null;
							fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body } )
								.then( function ( r ) { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } )
								.then( function ( res ) {
									btn.disabled = false;
									if ( res && res.success && res.data && res.data.sample ) {
										out.className = 'trr-ai-try-out is-ok';
										if ( spunta ) { spunta.hidden = false; spunta.title = res.data.when || ''; }
										out.textContent = '✓ «' + ( res.data.source || 'Hello' ) + '» → «' + res.data.sample + '» (' + ( res.data.target || '' ) + ')';
									} else {
										out.className = 'trr-ai-try-out is-fail';
										out.textContent = '✗ ' + ( res && res.data && res.data.error ? res.data.error : 'Error' );
										window.TRRocketDettagli( out, res && res.data ? res.data.detail : '' );
										if ( spunta ) { spunta.hidden = true; }
									}
								} )
								.catch( function ( err ) { btn.disabled = false; out.className = 'trr-ai-try-out is-fail'; out.textContent = '✗ ' + window.TRRocketSito.replace( '%s', err && err.message ? err.message : '?' ); } );
						} );
						function apri( card, si ) {
							card.classList.toggle( 'is-folded', ! si );
							var b = card.querySelector( '.trr-ai-fold' );
							if ( b ) { b.setAttribute( 'aria-expanded', si ? 'true' : 'false' ); b.textContent = b.getAttribute( si ? 'data-hide' : 'data-show' ); }
						}
						document.querySelectorAll( '.trr-ai-provider' ).forEach( function ( card ) {
							var b = card.querySelector( '.trr-ai-fold' );
							if ( b ) { b.addEventListener( 'click', function () { apri( card, card.classList.contains( 'is-folded' ) ); } ); }
						} );
						// choosing a provider in «Active provider» opens its card, where its key goes
						var sel = document.getElementById( 'trrocket-active' );
						if ( sel ) {
							sel.addEventListener( 'change', function () {
								var card = document.querySelector( '.trr-ai-provider[data-provider="' + sel.value + '"]' );
								if ( card ) { apri( card, true ); card.scrollIntoView( { block: 'nearest', behavior: 'smooth' } ); }
							} );
						}
					} )();
					</script>

					<h3><?php esc_html_e( 'House style', 'translate-rocket' ); ?> <?php $this->info( __( 'An extra instruction sent with every AI translation, so the wording sounds like you rather than like a dictionary. Tone, formality, words to keep in the original — anything the model should know about your brand.', 'translate-rocket' ) ); ?></h3>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="trr-ai-guidance"><?php esc_html_e( 'Extra instruction for the AI', 'translate-rocket' ); ?></label></th>
								<td>
									<textarea id="trr-ai-guidance" name="ai_guidance" rows="4" class="large-text" maxlength="<?php echo (int) \TranslateRocket\Providers\LlmProvider::GUIDANCE_MAX; ?>" placeholder="<?php esc_attr_e( 'e.g. We are a family-run guesthouse. Keep it warm and informal, use "tu" in Italian, and never translate the name Villa Aurora.', 'translate-rocket' ); ?>"><?php echo esc_textarea( (string) ( $settings['ai_guidance'] ?? '' ) ); ?></textarea>
									<p class="description"><?php
										printf(
											/* translators: %d: maximum number of characters. */
											esc_html__( 'Optional, up to %d characters. Sent with every batch, so keep it short. It never overrides the rules that keep translations lined up with your text.', 'translate-rocket' ),
											(int) \TranslateRocket\Providers\LlmProvider::GUIDANCE_MAX
										);
									?></p>
									<p class="description"><?php esc_html_e( 'Only the AI models can follow this: OpenAI, Claude and Gemini. DeepL and Google Translate are translation APIs with no prompt of their own, so they ignore it.', 'translate-rocket' ); ?></p>
									<?php
									// Better to say it here than to let someone write a house style
									// for DeepL and wonder for a week why nothing changed. The whole
									// fallback chain counts, not just the active provider: with DeepL
									// in front and an AI model behind it, the instruction still gets
									// used on the legs that model handles, so warning would be wrong.
									$attivo = \TranslateRocket\Providers\Registry::active();
									$con_llm = false;
									foreach ( \TranslateRocket\Providers\Registry::ordered() as $uno ) {
										if ( $uno instanceof \TranslateRocket\Providers\LlmProvider ) {
											$con_llm = true;
											break;
										}
									}
									if ( null !== $attivo && ! $con_llm ) {
										?>
										<p class="description" style="color:#8a6100;"><strong><?php
											printf(
												/* translators: %s: name of the translation service in use, e.g. DeepL. */
												esc_html__( 'Your provider right now is %s, so this instruction will be ignored until you switch to one of those.', 'translate-rocket' ),
												esc_html( $attivo->label() )
											);
										?></strong></p>
										<?php
									}
									?>
								</td>
							</tr>
						</table>

					<h3><?php esc_html_e( 'Translation memory', 'translate-rocket' ); ?> <?php $this->info( __( 'Every translation is kept. Before sending a sentence to the AI, TranslateRocket looks for the same sentence already translated elsewhere on your site and reuses it for free. Here you choose which of those saved translations the AI may reuse.', 'translate-rocket' ) ); ?></h3>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Reuse saved translations', 'translate-rocket' ); ?></th>
								<td>
									<?php
									$riuso = \TranslateRocket\Translator::reuse_mode();
									$scelte = array(
										'all'        => array( __( 'All of them', 'translate-rocket' ), __( 'The cheapest: a sentence already translated is never paid for twice.', 'translate-rocket' ) ),
										'no_browser' => array( __( 'All except free machine translations (browser translator and free engines)', 'translate-rocket' ), __( 'Recommended when you move from free machine translation (the Chrome/Edge translator, or the free engines of TranslateRocket Labs) to an AI: repeated sentences (menus, footer, buttons) get the AI quality too. Your own edits and imported translations are still reused.', 'translate-rocket' ) ),
										'none'       => array( __( 'None', 'translate-rocket' ), __( 'The AI translates every sentence it is asked for. Glossary terms still apply.', 'translate-rocket' ) ),
									);
									foreach ( $scelte as $val => $testi ) :
										?>
										<p><label><input type="radio" name="reuse_mode" value="<?php echo esc_attr( $val ); ?>" <?php checked( $riuso, $val ); ?> /> <strong><?php echo esc_html( $testi[0] ); ?></strong></label><br /><span class="description"><?php echo esc_html( $testi[1] ); ?></span></p>
										<?php
									endforeach;
									?>
									<p class="description"><?php esc_html_e( 'This only decides what the AI may copy instead of translating. Translations already on your pages stay as they are, and the free browser translator always reuses everything.', 'translate-rocket' ); ?></p>
								</td>
							</tr>
						</table>

					<h3><?php esc_html_e( 'Usage &amp; limits', 'translate-rocket' ); ?> <?php $this->info( __( 'Protect your API spend: cap how many characters are sent to the AI each day, and keep crawlers from triggering paid translation.', 'translate-rocket' ) ); ?></h3>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="trr-ai-limit"><?php esc_html_e( 'Daily character limit', 'translate-rocket' ); ?></label> <?php $this->info( __( 'A safety cap on how many characters can be sent to the AI per day. When the cap is reached, AI translation stops until tomorrow (manual and copy-paste still work). 0 means no limit.', 'translate-rocket' ) ); ?></th>
								<td>
									<input type="number" min="0" step="1000" id="trr-ai-limit" name="ai_daily_limit" value="<?php echo (int) ( $settings['ai_daily_limit'] ?? 0 ); ?>" class="regular-text" />
									<p class="description">
										<?php
										printf(
											/* translators: %s: number of characters used today. */
											esc_html__( '0 = unlimited. Used today: %s characters.', 'translate-rocket' ),
											'<strong>' . esc_html( number_format_i18n( \TranslateRocket\AiUsage::used_today() ) ) . '</strong>'
										);
										?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="trr-ai-batch"><?php esc_html_e( 'Sentences per request', 'translate-rocket' ); ?></label> <?php $this->info( __( 'How many sentences go to the AI in one request. Automatic uses the size that suits each provider. Choose fewer when the provider is slow or far from your server, or when you see timeouts or gateway errors (502, 504, 524): each request is then shorter. More sentences per request is faster on a quick connection.', 'translate-rocket' ) ); ?></th>
								<td>
									<select id="trr-ai-batch" name="ai_batch">
										<option value="0" <?php selected( (int) ( $settings['ai_batch'] ?? 0 ), 0 ); ?>><?php esc_html_e( 'Automatic', 'translate-rocket' ); ?></option>
										<?php foreach ( array( 5, 10, 20, 50 ) as $trr_n ) : ?>
											<option value="<?php echo (int) $trr_n; ?>" <?php selected( (int) ( $settings['ai_batch'] ?? 0 ), $trr_n ); ?>><?php echo (int) $trr_n; ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Usage this period', 'translate-rocket' ); ?> <?php $this->info( __( 'How much AI translation you’ve used since the counter started — to keep an eye on your provider spend. Reset it whenever you like, or set a monthly reset day below to match your billing.', 'translate-rocket' ) ); ?></th>
								<td>
									<?php $u = \TranslateRocket\AiUsage::usage(); ?>
									<p style="margin:0 0 8px;font-size:14px">
										<strong><?php echo esc_html( number_format_i18n( (int) $u['chars'] ) ); ?></strong> <?php esc_html_e( 'characters', 'translate-rocket' ); ?>
										&nbsp;·&nbsp; <strong><?php echo esc_html( number_format_i18n( (int) $u['requests'] ) ); ?></strong> <?php esc_html_e( 'requests', 'translate-rocket' ); ?>
										&nbsp;<span class="description"><?php
										printf(
											/* translators: %s: start date of the current usage period. */
											esc_html__( '(since %s)', 'translate-rocket' ),
											esc_html( date_i18n( (string) get_option( 'date_format' ), strtotime( $u['since'] . ' UTC' ) ) )
										);
										?></span>
									</p>
									<button type="submit" name="trr_ai_reset" value="1" class="button" onclick="return confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Reset the usage counter to zero?', 'translate-rocket' ) ) ); ?>)"><?php esc_html_e( 'Reset counter', 'translate-rocket' ); ?></button>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Saved by reuse', 'translate-rocket' ); ?> <?php $this->info( __( 'When the same text appears again — on another page, as an image alt, as an interface string, or as a glossary term — TranslateRocket copies the translation you already have instead of paying the AI to translate it again. This is what that translation memory has kept in your pocket.', 'translate-rocket' ) ); ?></th>
								<td>
									<?php
									$sv  = \TranslateRocket\Savings::totals();
									$est = \TranslateRocket\Savings::estimate( (int) $sv['chars'] );
									?>
									<p style="margin:0;font-size:14px">
										<strong style="color:#1a7a33">&asymp; &euro;<?php echo esc_html( number_format_i18n( $est['eur'], 2 ) ); ?></strong>
										&nbsp;·&nbsp; <strong><?php echo esc_html( number_format_i18n( (int) $sv['chars'] ) ); ?></strong> <?php esc_html_e( 'characters', 'translate-rocket' ); ?>
										&nbsp;·&nbsp; <strong><?php echo esc_html( number_format_i18n( (int) $sv['strings'] ) ); ?></strong> <?php esc_html_e( 'strings translated for free', 'translate-rocket' ); ?>
									</p>
									<p class="description">
										<?php
										printf(
											/* translators: %s: AI provider name the price estimate refers to. */
											esc_html__( 'Estimate at %s list prices — your real saving depends on your plan.', 'translate-rocket' ),
											esc_html( $est['provider'] )
										);
										?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="trr-ai-reset-day"><?php esc_html_e( 'Monthly reset day', 'translate-rocket' ); ?></label> <?php $this->info( __( 'Day of the month the usage counter restarts on its own (e.g. your provider’s billing date). 0 = never reset automatically.', 'translate-rocket' ) ); ?></th>
								<td>
									<input type="number" min="0" max="31" id="trr-ai-reset-day" name="ai_reset_day" value="<?php echo (int) ( $settings['ai_reset_day'] ?? 0 ); ?>" class="small-text" />
									<p class="description"><?php esc_html_e( '0 = manual only. 1–31 = reset on that day each month (shorter months use their last day).', 'translate-rocket' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Crawlers', 'translate-rocket' ); ?> <?php $this->info( __( 'Search engines and other bots visit your pages constantly. With auto-translation on, those visits could trigger paid AI calls for pages no real visitor asked for. Turn this on to ignore known bots, so only real visitors (and you) ever trigger AI translation.', 'translate-rocket' ) ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="ai_block_bots" value="1" <?php checked( ! empty( $settings['ai_block_bots'] ) ); ?> />
										<?php esc_html_e( 'Don’t let bots / crawlers trigger AI translation', 'translate-rocket' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Only matters with auto-translation enabled; recommended on.', 'translate-rocket' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save AI settings', 'translate-rocket' ) ); ?>
				</div>
			</form>

			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only which message to show.
			$trr_tone = isset( $_GET['tone'] ) ? sanitize_key( wp_unslash( $_GET['tone'] ) ) : '';
			if ( 'error' === $trr_tone ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The site could not be read:', 'translate-rocket' ) . ' ' . esc_html( isset( $_GET['tone_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['tone_msg'] ) ) ) : '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			} elseif ( 'applied' === $trr_tone ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'The new style is in use: it is in House style above, the names in Exclusions.', 'translate-rocket' ) . '</p></div>';
			}
			// phpcs:enable
			?>
			<div class="trrocket-card" style="max-width:none">
				<?php \TranslateRocket\ToneAdvisor::render( current_user_can( 'manage_options' ) ); ?>
			</div>

			<?php if ( ! empty( $targets ) ) : ?>
				<div class="trrocket-card" style="max-width:none">
					<h2><?php esc_html_e( 'Bulk auto-translate', 'translate-rocket' ); ?> <?php $this->info( __( 'Fill in every missing string for a language at once with the active AI provider. You can refine any result by hand afterwards.', 'translate-rocket' ) ); ?></h2>
					<p class="description"><?php esc_html_e( 'Translate every missing string for a language with the active provider.', 'translate-rocket' ); ?></p>
					<table class="widefat striped trr-progress-table" style="margin-top:8px">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Language', 'translate-rocket' ); ?></th>
								<th style="width:45%"><?php esc_html_e( 'Progress', 'translate-rocket' ); ?></th>
								<th><?php esc_html_e( 'Missing', 'translate-rocket' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $targets as $code ) :
								$stats = Strings::language_stats( $code );
								$det   = (int) $stats['detected'];
								$miss  = (int) $stats['missing'];
								$done  = $det - $miss;
								?>
								<tr>
									<td><?php echo wp_kses( self::lang_html( (string) $code ), \TranslateRocket\Kses::html_rules() ); ?></td>
									<td><?php echo wp_kses( $this->progress_bar( $done, $det, true ) , \TranslateRocket\Kses::html_rules() ); ?></td>
									<td data-label="<?php esc_attr_e( 'Missing', 'translate-rocket' ); ?>"><strong><?php echo (int) $miss; ?></strong></td>
									<td>
										<form method="post" action="" style="margin:0">
											<?php wp_nonce_field( 'trrocket_auto_translate', 'trrocket_auto_nonce' ); ?>
											<input type="hidden" name="lang" value="<?php echo esc_attr( $code ); ?>" />
											<button type="submit" class="button button-primary" <?php disabled( 0 === $miss ); ?>>
												<?php
												printf(
													/* translators: %d: missing count. */
													esc_html__( 'Translate %d missing', 'translate-rocket' ),
													(int) $miss
												);
												?>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save the exclusion rules (paths, selectors, strings).
	 */
	public function maybe_save_exclusions(): void {
		if ( ! isset( $_POST['trrocket_exclusions_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_exclusions_nonce'] ) ), 'trrocket_save_exclusions' ) ) {
			return;
		}

		\TranslateRocket\Cache::flush(); // Exclusions change what gets translated.

		$lines = static function ( string $field ): array {
			$raw = isset( $_POST[ $field ] ) ? (string) wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by the caller; each line sanitized below.
			$out = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = trim( sanitize_text_field( $line ) );
				if ( '' !== $line ) {
					$out[] = $line;
				}
			}
			return array_values( array_unique( $out ) );
		};

		$settings                      = Settings::get();
		$settings['exclude_paths']     = $lines( 'exclude_paths' );
		$settings['exclude_selectors'] = $lines( 'exclude_selectors' );
		$settings['exclude_strings']   = $lines( 'exclude_strings' );

		// Glossary: one "source = translation" list per target language.
		$glossary = array();
		foreach ( (array) ( $settings['target_languages'] ?? array() ) as $lang ) {
			$lang = sanitize_key( $lang );
			$raw  = isset( $_POST[ 'glossary_' . $lang ] ) ? (string) wp_unslash( $_POST[ 'glossary_' . $lang ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$terms = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = trim( $line );
				$pos  = strpos( $line, '=' );
				if ( '' === $line || false === $pos ) {
					continue;
				}
				$src = trim( sanitize_text_field( substr( $line, 0, $pos ) ) );
				$trg = trim( sanitize_text_field( substr( $line, $pos + 1 ) ) );
				if ( '' !== $src && '' !== $trg ) {
					$terms[ $src ] = $trg;
				}
			}
			if ( ! empty( $terms ) ) {
				$glossary[ $lang ] = $terms;
			}
			delete_transient( 'trrocket_map_' . $lang ); // refresh the cached map.
		}
		$settings['glossary'] = $glossary;
		Settings::update( $settings );
		\TranslateRocket\Cache::flush();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'translate-rocket-exclusions',
					'saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Exclusions page.
	 */
	public function render_exclusions_page(): void {
		$settings = Settings::get();
		$box      = static function ( $list ): string {
			return implode( "\n", (array) $list );
		};
		?>
		<div class="wrap trrocket-wrap">
			<?php self::header( 'translate-rocket-exclusions' ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'Exclusions', 'translate-rocket' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Exclusions saved.', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>
			<p class="trrocket-tagline"><?php esc_html_e( 'Keep parts of your site in the original language. One entry per line.', 'translate-rocket' ); ?></p>

			<form method="post" action="">
				<?php wp_nonce_field( 'trrocket_save_exclusions', 'trrocket_exclusions_nonce' ); ?>

				<div class="trrocket-card">
					<h2><?php esc_html_e( 'Pages (URL paths)', 'translate-rocket' ); ?> <?php $this->info( __( 'Paths that are never translated. Use * as a wildcard, e.g. /checkout/* or /my-account/*.', 'translate-rocket' ) ); ?></h2>
					<textarea name="exclude_paths" rows="5" class="large-text code" placeholder="/checkout/*&#10;/cart/&#10;/my-account/*"><?php echo esc_textarea( $box( $settings['exclude_paths'] ?? array() ) ); ?></textarea>
				</div>

				<div class="trrocket-card">
					<h2><?php esc_html_e( 'Elements (CSS selectors)', 'translate-rocket' ); ?> <?php $this->info( __( 'Elements left untranslated. Simple selectors only: a tag (code), a class (.notranslate) or an id (#brand).', 'translate-rocket' ) ); ?></h2>
					<textarea name="exclude_selectors" rows="5" class="large-text code" placeholder=".notranslate&#10;#site-title&#10;code"><?php echo esc_textarea( $box( $settings['exclude_selectors'] ?? array() ) ); ?></textarea>
				</div>

				<div class="trrocket-card">
					<h2><?php esc_html_e( 'Words &amp; phrases', 'translate-rocket' ); ?> <?php $this->info( __( 'Names that must stay as they are, such as your brand or product names. A text that is only the name is never translated; inside a sentence, DeepL, Google Cloud and the AI models are told to leave the name unchanged.', 'translate-rocket' ) ); ?></h2>
					<textarea name="exclude_strings" rows="6" class="large-text code" placeholder="TranslateRocket&#10;WordPress&#10;WooCommerce"><?php echo esc_textarea( $box( $settings['exclude_strings'] ?? array() ) ); ?></textarea>
				</div>

				<?php
				$gloss   = (array) ( $settings['glossary'] ?? array() );
				$targets = array_values( array_filter( (array) ( $settings['target_languages'] ?? array() ), static function ( $t ) use ( $settings ) {
					return (string) $t !== (string) ( $settings['source_language'] ?? '' );
				} ) );
				?>
				<div class="trrocket-card">
					<h2><?php esc_html_e( 'Glossary', 'translate-rocket' ); ?> <?php $this->info( __( 'Force a fixed translation for a term, everywhere it appears. One “source = translation” per line. Overrides AI/manual translations for an exact match — great for brand wording, product names or terms that must read the same across the whole site.', 'translate-rocket' ) ); ?></h2>
					<?php if ( empty( $targets ) ) : ?>
						<p class="description"><?php esc_html_e( 'Add a target language first (Languages tab) to define glossary terms.', 'translate-rocket' ); ?></p>
					<?php else : ?>
						<?php foreach ( $targets as $lang ) :
							$one = ( isset( $gloss[ $lang ] ) && is_array( $gloss[ $lang ] ) ) ? $gloss[ $lang ] : array();
							$txt = '';
							foreach ( $one as $src => $trg ) {
								$txt .= $src . ' = ' . $trg . "\n";
							}
							?>
							<p class="trr-gloss-lang"><strong><?php echo esc_html( \TranslateRocket\Languages::label( (string) $lang ) ); ?></strong> <code>/<?php echo esc_html( (string) $lang ); ?>/</code></p>
							<textarea name="glossary_<?php echo esc_attr( (string) $lang ); ?>" rows="4" class="large-text code" placeholder="Book now = <?php echo esc_attr( 'it' === $lang ? 'Prenota ora' : '…' ); ?>&#10;Checkout = <?php echo esc_attr( 'it' === $lang ? 'Cassa' : '…' ); ?>"><?php echo esc_textarea( rtrim( $txt ) ); ?></textarea>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<?php submit_button( __( 'Save exclusions', 'translate-rocket' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Clear the diagnostics log (its own tiny form on the Diagnostics page).
	 */
	public function maybe_clear_log(): void {
		if ( ! isset( $_POST['trrocket_clearlog_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_clearlog_nonce'] ) ), 'trrocket_clear_log' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		\TranslateRocket\Logger::clear();
		wp_safe_redirect( add_query_arg( 'cleared', '1', admin_url( 'admin.php?page=translate-rocket-diagnostics' ) ) );
		exit;
	}

	/**
	 * Diagnostics: system health checks, per-provider connection test, and the
	 * recent issue log — so nothing fails silently.
	 */
	public function render_diagnostics_page(): void {
		\TranslateRocket\Logger::mark_seen();
		$settings = Settings::get();
		global $wp_version;

		$checks = array();

		$checks[] = array(
			version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'fail',
			__( 'PHP version', 'translate-rocket' ),
			/* translators: %s: PHP version. */
			sprintf( __( 'Running PHP %s (7.4 or newer required).', 'translate-rocket' ), PHP_VERSION ),
		);

		$ext_missing = array_values( array_filter( array( 'dom', 'mbstring', 'libxml', 'json' ), static function ( $e ) {
			return ! extension_loaded( $e );
		} ) );
		$checks[] = array(
			empty( $ext_missing ) ? 'ok' : 'fail',
			__( 'PHP extensions', 'translate-rocket' ),
			empty( $ext_missing )
				? __( 'All required extensions are present (dom, mbstring, libxml, json).', 'translate-rocket' )
				/* translators: %s: list of missing extensions. */
				: sprintf( __( 'Missing extensions: %s. Ask your host to enable them.', 'translate-rocket' ), implode( ', ', $ext_missing ) ),
		);

		$checks[] = array(
			version_compare( (string) $wp_version, '5.6', '>=' ) ? 'ok' : 'warn',
			__( 'WordPress version', 'translate-rocket' ),
			/* translators: %s: WordPress version. */
			sprintf( __( 'Running WordPress %s.', 'translate-rocket' ), $wp_version ),
		);

		$missing_t = \TranslateRocket\Database::missing_tables();
		$checks[]  = array(
			empty( $missing_t ) ? 'ok' : 'fail',
			__( 'Database tables', 'translate-rocket' ),
			empty( $missing_t )
				? __( 'All TranslateRocket tables exist.', 'translate-rocket' )
				/* translators: %s: list of missing tables. */
				: sprintf( __( 'Missing: %s. Deactivate and reactivate TranslateRocket to recreate them.', 'translate-rocket' ), implode( ', ', $missing_t ) ),
		);

		$src = (string) $settings['source_language'];
		$tgs = array_values( array_filter( (array) $settings['target_languages'], static function ( $t ) use ( $src ) {
			return (string) $t !== $src;
		} ) );
		$checks[] = array(
			! empty( $tgs ) ? 'ok' : 'warn',
			__( 'Languages', 'translate-rocket' ),
			! empty( $tgs )
				/* translators: 1: source language code, 2: number of target languages. */
				? sprintf( __( 'Source: %1$s · %2$d target language(s) configured.', 'translate-rocket' ), $src, count( $tgs ) )
				: __( 'No target languages yet. Add at least one on the Languages tab.', 'translate-rocket' ),
		);

		$pretty   = '' !== (string) get_option( 'permalink_structure' );
		$checks[] = array(
			$pretty ? 'ok' : 'fail',
			__( 'Permalinks', 'translate-rocket' ),
			$pretty
				? __( 'Pretty permalinks are on — language URLs like /it/ will work.', 'translate-rocket' )
				: __( 'Plain permalinks are on. Language URLs like /it/ need pretty permalinks: go to Settings → Permalinks and choose anything but “Plain”.', 'translate-rocket' ),
		);

		$active   = Registry::active();
		$checks[] = array(
			( null !== $active ) ? 'ok' : 'warn',
			__( 'AI provider', 'translate-rocket' ),
			( null !== $active )
				/* translators: %s: provider name. */
				? sprintf( __( 'Active provider: %s.', 'translate-rocket' ), $active->label() )
				: __( 'No AI provider configured (optional — you can still translate by hand or with the Google links). Add a key on the AI tab.', 'translate-rocket' ),
		);

		$icons = array(
			'ok'   => '<span class="dashicons dashicons-yes-alt"></span>',
			'warn' => '<span class="dashicons dashicons-warning"></span>',
			'fail' => '<span class="dashicons dashicons-dismiss"></span>',
		);

		// Configured providers, for the connection test.
		// Every provider with a key, from the registry: the list written here by hand stopped at the
		// five paid ones, and Groq, Cloudflare and OpenRouter — the free ones the setup wizard
		// recommends — had no «Test connection» (5/10/2026).
		$configured = array();
		foreach ( \TranslateRocket\Providers\Registry::all() as $prov ) {
			if ( $prov->is_configured() ) {
				$configured[ $prov->id() ] = $prov->label();
			}
		}

		$log = \TranslateRocket\Logger::all();
		?>
		<div class="wrap trrocket-wrap">
			<?php self::header( 'translate-rocket-diagnostics' ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'Diagnostics', 'translate-rocket' ); ?></h1>
			<?php if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log cleared.', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>
			<p class="trrocket-tagline"><?php esc_html_e( 'A quick health check of your setup, plus anything TranslateRocket has flagged — so nothing fails silently.', 'translate-rocket' ); ?></p>

			<div class="trrocket-card">
				<h2><?php esc_html_e( 'System checks', 'translate-rocket' ); ?></h2>
				<ul class="trr-diag-list">
					<?php foreach ( $checks as $c ) : ?>
						<li class="trr-diag trr-diag-<?php echo esc_attr( $c[0] ); ?>">
							<?php echo wp_kses( $icons[ $c[0] ] , \TranslateRocket\Kses::html_rules() ); ?>
							<span class="trr-diag-body"><strong><?php echo esc_html( $c[1] ); ?></strong><span class="trr-diag-detail"><?php echo esc_html( $c[2] ); ?></span></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="trrocket-card">
				<h2><?php esc_html_e( 'Provider connection test', 'translate-rocket' ); ?> <?php $this->info( __( 'Sends one short word to each configured provider to confirm the key works right now.', 'translate-rocket' ) ); ?></h2>
				<?php if ( empty( $configured ) ) : ?>
					<p class="description"><?php esc_html_e( 'No provider keys saved yet. Add one on the AI tab.', 'translate-rocket' ); ?></p>
				<?php else : ?>
					<ul class="trr-diag-tests" data-nonce="<?php echo esc_attr( wp_create_nonce( 'trrocket_models' ) ); ?>">
						<?php foreach ( $configured as $pid => $plabel ) : ?>
							<li>
								<span class="trr-test-name"><?php echo esc_html( $plabel ); ?></span>
								<button type="button" class="button trr-test-btn" data-provider="<?php echo esc_attr( $pid ); ?>"><?php esc_html_e( 'Test connection', 'translate-rocket' ); ?></button>
								<span class="trr-test-result" aria-live="polite"></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="trrocket-card">
				<h2><?php esc_html_e( 'Recent issues', 'translate-rocket' ); ?> <?php $this->info( __( 'Errors and warnings TranslateRocket recorded, including ones from background tasks.', 'translate-rocket' ) ); ?></h2>
				<?php if ( empty( $log ) ) : ?>
					<p class="trr-diag-empty"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Nothing to report — no issues logged.', 'translate-rocket' ); ?></p>
				<?php else : ?>
					<table class="widefat striped trr-log-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'When', 'translate-rocket' ); ?></th>
								<th><?php esc_html_e( 'Level', 'translate-rocket' ); ?></th>
								<th><?php esc_html_e( 'Area', 'translate-rocket' ); ?></th>
								<th><?php esc_html_e( 'Message', 'translate-rocket' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $log as $e ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $e['t'] ?? 0 ) ) ); ?></td>
									<td><span class="trr-log-level trr-log-<?php echo esc_attr( (string) ( $e['level'] ?? 'info' ) ); ?>"><?php echo esc_html( (string) ( $e['level'] ?? 'info' ) ); ?></span></td>
									<td><code><?php echo esc_html( (string) ( $e['ctx'] ?? '' ) ); ?></code></td>
									<td><?php echo esc_html( (string) ( $e['msg'] ?? '' ) ); ?><?php if ( '' !== (string) ( $e['detail'] ?? '' ) ) : ?><br><span class="trr-log-detail"><?php echo esc_html( (string) $e['detail'] ); ?></span><?php endif; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<form method="post" action="" class="trr-log-clear">
						<?php wp_nonce_field( 'trrocket_clear_log', 'trrocket_clearlog_nonce' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Clear log', 'translate-rocket' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div class="trrocket-card">
				<h2><?php esc_html_e( 'Ask for help', 'translate-rocket' ); ?></h2>
				<p class="description" style="margin-bottom:10px">
					<?php esc_html_e( 'Copy the report below and paste it into your message: it says which versions you are on and what these checks found. Nothing is sent anywhere on its own — you copy it, you decide where it goes. It never contains your API keys.', 'translate-rocket' ); ?>
				</p>
				<textarea id="trr-diag-report" readonly rows="9" class="large-text code" style="font-size:12px;line-height:1.5"><?php echo esc_textarea( self::rapporto_supporto( $checks ) ); ?></textarea>
				<p style="margin-top:10px">
					<button type="button" class="button button-primary" id="trr-diag-copy"><?php esc_html_e( 'Copy the report', 'translate-rocket' ); ?></button>
					<span id="trr-diag-copied" class="description" style="margin-left:8px" aria-live="polite"></span>
				</p>
				<details class="trr-diag-piu" style="margin-top:14px">
					<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'Three more things that turn days into hours', 'translate-rocket' ); ?></summary>
					<p class="description" style="margin-top:8px">
						<?php esc_html_e( 'The report above says what your site is running. It cannot say what you are seeing. Send what you can, a screenshot on its own is already a lot, but when a message has these, the answer is usually a fix instead of a question.', 'translate-rocket' ); ?>
					</p>
					<ol class="description" style="margin-left:18px">
						<li><?php esc_html_e( 'The address of a page where it happens. A test or staging site is perfect. If the site is not public, a screenshot instead.', 'translate-rocket' ); ?></li>
						<li><?php esc_html_e( 'The exact words that stay untranslated or come back wrong, copied and pasted. Two words are enough to find them.', 'translate-rocket' ); ?></li>
						<li>
							<?php esc_html_e( 'The piece of the page that misbehaves, as code. In Chrome, Edge or Firefox: right-click the text, choose Inspect, then right-click the highlighted line in the panel and pick Copy, then Copy outer HTML. It looks cryptic and it is the single most useful thing you can send: it shows exactly what your theme or plugin builds, which cannot be guessed from another site.', 'translate-rocket' ); ?>
						</li>
					</ol>
					<p class="description">
						<?php esc_html_e( 'If a "Fatal error" line appeared anywhere, copy that whole line too, with the file name and the number at the end.', 'translate-rocket' ); ?>
					</p>
				</details>
				<p style="margin:12px 0 0">
					<a class="button" href="https://wordpress.org/support/plugin/translate-rocket/" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open a topic on WordPress.org', 'translate-rocket' ); ?> &#8599;
					</a>
					<a class="button" href="https://translaterocket.com/support/" target="_blank" rel="noopener" style="margin-left:6px">
						<?php esc_html_e( 'Or open the support site', 'translate-rocket' ); ?> &#8599;
					</a>
				</p>
				<hr style="margin:20px 0;border:0;border-top:1px solid #e0e0e6">
				<?php \TranslateRocket\Admin\Ticket::render_form( self::rapporto_supporto( $checks ) ); ?>
			</div>
		</div>
		<?php
		$diag_data = 'window.TRRocketDiag=' . wp_json_encode( array(
			'testing' => __( 'Testing…', 'translate-rocket' ),
			'copied'  => __( 'Copied — now paste it into your message.', 'translate-rocket' ),
		) ) . ';';
		wp_add_inline_script( 'trrocket-admin-info', $diag_data . self::diagnostics_js() . self::ticket_js() );
	}

	/**
	 * A plain-text report to paste into a support message.
	 *
	 * Deliberately built here and shown in full rather than posted anywhere:
	 * the person sees exactly what they are sharing before they share it. API
	 * keys are never included — only whether one is present.
	 *
	 * @param array<int,array<int,string>> $checks The system checks already computed.
	 */
	private static function rapporto_supporto( array $checks ): string {
		global $wp_version, $wpdb;
		$s = Settings::get();

		$r   = array();
		$r[] = '--- TranslateRocket support report ---';
		$r[] = 'Plugin: ' . TRROCKET_VERSION;
		$r[] = 'WordPress: ' . $wp_version . ' | PHP: ' . PHP_VERSION . ' | MySQL: ' . $wpdb->db_version();
		$tema = wp_get_theme();
		$r[] = 'Theme: ' . $tema->get( 'Name' ) . ' ' . $tema->get( 'Version' );
		$r[] = 'Multisite: ' . ( is_multisite() ? 'yes' : 'no' ) . ' | HTTPS: ' . ( is_ssl() ? 'yes' : 'no' );
		$r[] = 'Permalinks: ' . ( get_option( 'permalink_structure' ) ? 'pretty' : 'PLAIN (language URLs need pretty permalinks)' );
		$r[] = '';
		$r[] = 'Source language: ' . (string) ( $s['source_language'] ?? '?' );
		$r[] = 'Target languages: ' . ( implode( ', ', (array) ( $s['target_languages'] ?? array() ) ) ?: '(none)' );
		$r[] = '';
		$r[] = 'Providers (keys are never included):';
		foreach ( (array) ( $s['providers'] ?? array() ) as $id => $cfg ) {
			$r[] = '  ' . $id . ': ' . ( '' !== (string) ( $cfg['api_key'] ?? '' ) ? 'key present' : 'no key' );
		}
		$attivo = Registry::active();
		$r[] = '  active: ' . ( $attivo ? $attivo->id() : 'none configured' );
		$r[] = '';
		$r[] = 'Checks:';
		foreach ( $checks as $c ) {
			$r[] = '  [' . strtoupper( (string) $c[0] ) . '] ' . (string) $c[1] . ' — ' . (string) $c[2];
		}

		// Logger::all() torna tutto: per il rapporto bastano le ultime voci.
		$righe = array_slice( (array) \TranslateRocket\Logger::all(), 0, 8 );
		if ( ! empty( $righe ) ) {
			$r[] = '';
			$r[] = 'Recent log:';
			foreach ( (array) $righe as $l ) {
				if ( ! is_array( $l ) ) {
					continue;
				}
				$r[] = sprintf(
					'  %s [%s/%s] %s',
					gmdate( 'Y-m-d H:i', (int) ( $l['t'] ?? 0 ) ),
					(string) ( $l['level'] ?? '?' ),
					(string) ( $l['ctx'] ?? '' ),
					(string) ( $l['msg'] ?? '' )
				);
			}
		}
		$r[] = '--- end of report ---';

		$testo = implode( "
", $r );

		// Rete di sicurezza: nel registro finiscono i corpi di errore grezzi dei
		// provider, e l'interfaccia promette che le chiavi non ci sono. Quindi
		// tolgo le chiavi vere e qualunque cosa somigli a una.
		foreach ( (array) ( $s['providers'] ?? array() ) as $cfg ) {
			$k = (string) ( $cfg['api_key'] ?? '' );
			if ( strlen( $k ) > 7 ) {
				$testo = str_replace( $k, '[key removed]', $testo );
			}
		}
		$testo = (string) preg_replace( '/(sk|rk|xai|gsk)-[A-Za-z0-9_\-]{12,}/', '[key removed]', $testo );
		$testo = (string) preg_replace( '/AIza[A-Za-z0-9_\-]{20,}/', '[key removed]', $testo );
		$testo = (string) preg_replace( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:fx/i', '[key removed]', $testo );

		return $testo;
	}

	/**
	 * Behaviour for the "open a ticket" form. Config from window.TRRocketTicket.
	 */
	private static function ticket_js(): string {
		return <<<'JS'
( function () {
	var C = window.TRRocketTicket;
	var invia = document.getElementById( 'trr-tk-send' );
	if ( ! C || ! invia ) { return; }

	var nome    = document.getElementById( 'trr-tk-name' );
	var email   = document.getElementById( 'trr-tk-email' );
	var oggetto = document.getElementById( 'trr-tk-subject' );
	var testo   = document.getElementById( 'trr-tk-message' );
	var allega  = document.getElementById( 'trr-tk-report' );
	var fonte   = document.getElementById( 'trr-tk-report-src' );
	var trappola = document.getElementById( 'trr-tk-hp' );
	var stato   = document.getElementById( 'trr-tk-status' );

	function di( t, classe ) {
		stato.textContent = t;
		stato.className = 'description trr-tk-' + ( classe || 'info' );
	}

	invia.addEventListener( 'click', function () {
		// I robot riempiono tutto, compreso il campo che nessuno vede.
		if ( trappola && trappola.value ) { return; }

		if ( ! email.value || email.value.indexOf( '@' ) === -1 ) { di( C.i18n.noemail, 'bad' ); email.focus(); return; }
		if ( oggetto.value.trim().length < 3 ) { di( C.i18n.nosubj, 'bad' ); oggetto.focus(); return; }
		if ( testo.value.trim().length < 20 ) { di( C.i18n.nomsg, 'bad' ); testo.focus(); return; }

		invia.disabled = true;
		di( C.i18n.sending );

		var body = new FormData();
		body.append( 'action', 'trrocket_ticket' );
		body.append( 'nonce', C.nonce );
		body.append( 'name', nome ? nome.value : '' );
		body.append( 'email', email.value );
		body.append( 'subject', oggetto.value );
		body.append( 'message', testo.value );
		body.append( 'report', ( allega && allega.checked && fonte ) ? fonte.value : '' );

		fetch( C.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				invia.disabled = false;
				if ( j && j.success ) {
					di( String( C.i18n.done ).replace( '%d', j.data.ticket ), 'ok' );
					testo.value = '';
					oggetto.value = '';
					return;
				}
				var m = ( j && j.data && j.data.message ) || C.i18n.failed;
				di( m, 'bad' );
			} )
			.catch( function () { invia.disabled = false; di( C.i18n.failed, 'bad' ); } );
	} );
}() );
JS;
	}

	/**
	 * Behaviour for the Diagnostics page provider tests. Config from
	 * window.TRRocketDiag. Returned as a string for wp_add_inline_script().
	 */
	private static function diagnostics_js(): string {
		return <<<'JS'
/* Copia del rapporto: sta in un blocco suo perche' il codice delle prove piu'
   sotto esce subito se non ci sono provider configurati, e il rapporto deve
   restare copiabile comunque. */
( function () {
	var bottone = document.getElementById( 'trr-diag-copy' );
	var campo = document.getElementById( 'trr-diag-report' );
	var esito = document.getElementById( 'trr-diag-copied' );
	if ( ! bottone || ! campo ) { return; }
	bottone.addEventListener( 'click', function () {
		var fatto = function () {
			if ( esito ) { esito.textContent = ( window.TRRocketDiag && window.TRRocketDiag.copied ) || 'Copied'; }
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( campo.value ).then( fatto, function () {
				campo.select(); document.execCommand( 'copy' ); fatto();
			} );
		} else {
			campo.select(); document.execCommand( 'copy' ); fatto();
		}
	} );
}() );

( function () {
	var wrap = document.querySelector( '.trr-diag-tests' );
	if ( ! wrap ) { return; }
	var nonce = wrap.getAttribute( 'data-nonce' );
	wrap.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest ? ev.target.closest( '.trr-test-btn' ) : null;
		if ( ! btn ) { return; }
		var out = btn.parentNode.querySelector( '.trr-test-result' );
		btn.disabled = true;
		out.className = 'trr-test-result is-pending';
		out.textContent = ( window.TRRocketDiag || {} ).testing || 'Testing…';
		var body = 'action=trrocket_test_provider&_wpnonce=' + encodeURIComponent( nonce ) + '&provider=' + encodeURIComponent( btn.getAttribute( 'data-provider' ) );
		fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body } )
			.then( function ( r ) { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } )
			.then( function ( res ) {
				btn.disabled = false;
				if ( res && res.success ) {
					out.className = 'trr-test-result is-ok';
					out.textContent = ( res.data && res.data.sample ? ( '✓ “' + ( res.data.source || 'Hello' ) + '” → “' + res.data.sample + '”' ) : '✓ OK' );
				} else {
					out.className = 'trr-test-result is-fail';
					out.textContent = '✗ ' + ( res && res.data && res.data.error ? res.data.error : 'Error' );
					if ( window.TRRocketDettagli ) { window.TRRocketDettagli( out, res && res.data ? res.data.detail : '' ); }
				}
			} )
			.catch( function ( err ) { btn.disabled = false; out.className = 'trr-test-result is-fail'; out.textContent = '✗ ' + ( window.TRRocketSito || '%s' ).replace( '%s', err && err.message ? err.message : '?' ); } );
	} );
}() );
JS;
	}

	/**
	 * Save the setup wizard (languages, optional AI key, switcher placement),
	 * then send the admin to the "done" step.
	 */
	/**
	 * Setup wizard, «Try the key»: asks Groq for its models with the key just pasted (nothing is saved).
	 */
	public function ajax_try_free_key(): void {
		check_ajax_referer( 'trrocket_try_free_key', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Not allowed.', 'translate-rocket' ) );
		}
		$key = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( __( 'Paste the key first.', 'translate-rocket' ) );
		}
		$modelli = ( new \TranslateRocket\Providers\GroqProvider( array( 'api_key' => $key ) ) )->list_models();
		if ( empty( $modelli ) ) {
			wp_send_json_error( __( 'Groq did not accept this key: copy it again, whole.', 'translate-rocket' ) );
		}
		wp_send_json_success( __( 'The key works. Press Next.', 'translate-rocket' ) );
	}

	public function maybe_save_wizard(): void {
		if ( ! isset( $_POST['trrocket_wizard_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_wizard_nonce'] ) ), 'trrocket_wizard' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::get();

		if ( isset( $_POST['source_language'] ) ) {
			$src = strtolower( sanitize_text_field( wp_unslash( $_POST['source_language'] ) ) );
			if ( Languages::exists( $src ) ) {
				$settings['source_language'] = $src;
			}
		}
		$source  = (string) ( $settings['source_language'] ?? 'en' );
		$prima   = (array) ( $settings['target_languages'] ?? array() );
		$targets = array();
		if ( isset( $_POST['target_languages'] ) && is_array( $_POST['target_languages'] ) ) {
			foreach ( wp_unslash( $_POST['target_languages'] ) as $t ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$t = strtolower( sanitize_text_field( $t ) );
				if ( Languages::exists( $t ) && $t !== $source ) {
					$targets[] = $t;
				}
			}
		}
		$settings['target_languages'] = array_values( array_unique( $targets ) );

		// Come nella pagina Lingue (assets/js/lang-online.js): una lingua aggiunta
		// adesso parte OFFLINE, altrimenti appena salvato una lingua ancora tutta da
		// tradurre e' gia' nel selettore, nella sitemap e negli hreflang. Una lingua
		// che c'era gia' tiene lo stato che aveva; una lingua tolta smette di essere
		// offline.
		$offline = array();
		foreach ( (array) ( $settings['offline_languages'] ?? array() ) as $code ) {
			if ( in_array( (string) $code, $settings['target_languages'], true ) ) {
				$offline[] = (string) $code;
			}
		}
		foreach ( $settings['target_languages'] as $code ) {
			if ( ! in_array( $code, $prima, true ) ) {
				$offline[] = $code;
			}
		}
		$settings['offline_languages'] = array_values( array_unique( $offline ) );

		if ( 'ai' === ( isset( $_POST['trr_method'] ) ? sanitize_key( $_POST['trr_method'] ) : 'manual' ) ) {
			$pid = isset( $_POST['wiz_provider'] ) ? sanitize_key( $_POST['wiz_provider'] ) : '';
			$key = isset( $_POST['wiz_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wiz_key'] ) ) : '';
			if ( in_array( $pid, array( 'deepl', 'openai', 'anthropic', 'gemini', 'google' ), true ) && '' !== $key ) {
				if ( ! isset( $settings['providers'][ $pid ] ) || ! is_array( $settings['providers'][ $pid ] ) ) {
					$settings['providers'][ $pid ] = array();
				}
				$settings['providers'][ $pid ]['api_key'] = $key;
				$settings['active_provider']               = $pid;
			}
		}

		// Free AI with the owner's own free account (Groq): the recommended way in, works in any browser.
		if ( 'free' === ( isset( $_POST['trr_method'] ) ? sanitize_key( $_POST['trr_method'] ) : '' ) ) {
			$gsk = isset( $_POST['wiz_free_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wiz_free_key'] ) ) ) : '';
			if ( '' !== $gsk ) {
				$settings['providers']['groq']            = array_merge( (array) ( $settings['providers']['groq'] ?? array() ), array( 'api_key' => $gsk ) );
				$settings['active_provider']              = 'groq';
				$settings['provider_order']               = array_values( array_unique( array_merge( array( 'groq' ), (array) ( $settings['provider_order'] ?? array() ) ) ) );
			}
		}

		$place                          = isset( $_POST['wiz_switcher'] ) ? sanitize_key( $_POST['wiz_switcher'] ) : 'floating';
		$settings['switcher']['floating'] = ( 'floating' === $place );
		$settings['switcher']['in_menu']  = ( 'menu' === $place );
		if ( 'menu' === $place ) {
			$settings['switcher']['menu_location'] = 'auto';
		}

		Settings::update( $settings );
		update_option( 'trrocket_wizard_done', 1 );
		\TranslateRocket\Cache::flush();
		wp_safe_redirect( add_query_arg( 'done', '1', admin_url( 'admin.php?page=translate-rocket-wizard' ) ) );
		exit;
	}

	/**
	 * First-run setup wizard: a guided, 3-step path to a translated site.
	 */
	public function render_wizard_page(): void {
		$settings = Settings::get();
		$source   = (string) ( $settings['source_language'] ?? 'en' );
		$targets  = (array) ( $settings['target_languages'] ?? array() );
		$builtins = Languages::all();

		// "Done" screen, shown after saving.
		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$router    = \TranslateRocket\Plugin::instance()->router();
			$first     = '';
			foreach ( (array) $settings['target_languages'] as $t ) {
				if ( (string) $t !== $source ) {
					$first = (string) $t;
					break;
				}
			}
			if ( '' === $first ) {
				$edit_url = home_url( '/' );
			} elseif ( \TranslateRocket\Coexistence::on() ) {
				// Side by side: /xx/ belongs to the other plugin — preview on the source address.
				$edit_url = add_query_arg( \TranslateRocket\Coexistence::PARAM, $first, home_url( '/' ) );
				$edit_url = add_query_arg( \TranslateRocket\Frontend\VisualEditor::PARAM, '1', $edit_url );
			} else {
				$edit_url = add_query_arg( \TranslateRocket\Frontend\VisualEditor::PARAM, '1', $router->home_for_language( $first ) );
			}
			?>
			<div class="wrap trrocket-wrap trrocket-wizard">
				<div class="trr-wiz-card trr-wiz-done">
					<div class="trr-wiz-check">✓</div>
					<h1><?php esc_html_e( 'You’re all set!', 'translate-rocket' ); ?></h1>
					<p class="trr-wiz-lead"><?php esc_html_e( 'Your languages are configured. Now translate your first page — it takes a minute.', 'translate-rocket' ); ?></p>
					<?php if ( ! empty( $router->offline_languages() ) ) : ?>
						<p><?php esc_html_e( 'New languages start offline: only you see them while you translate. When a language is ready, switch it online on the Languages page.', 'translate-rocket' ); ?></p>
					<?php endif; ?>
					<div class="trr-wiz-actions">
						<a class="button button-primary button-hero" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Translate my homepage now', 'translate-rocket' ); ?></a>
						<a class="button button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-strings' ) ); ?>"><?php esc_html_e( 'Open the translations list', 'translate-rocket' ); ?></a>
					</div>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket' ) ); ?>"><?php esc_html_e( 'Go to settings', 'translate-rocket' ); ?></a></p>
				</div>
			</div>
			<?php
			return;
		}

		$prov = array(
			'deepl'     => 'DeepL',
			'openai'    => 'OpenAI',
			'anthropic' => 'Anthropic (Claude)',
			'gemini'    => 'Google Gemini',
			'google'    => 'Google Translate',
		);
		?>
		<div class="wrap trrocket-wrap trrocket-wizard">
			<form method="post" action="" class="trr-wiz">
				<?php wp_nonce_field( 'trrocket_wizard', 'trrocket_wizard_nonce' ); ?>
				<div class="trr-wiz-head">
					<div class="trr-wiz-brand"><?php echo wp_kses( self::logo_svg(), \TranslateRocket\Kses::html_rules() ); ?><span><?php esc_html_e( 'TranslateRocket™ setup', 'translate-rocket' ); ?></span></div>
					<ol class="trr-wiz-steps">
						<li class="is-current" data-dot="1"><?php esc_html_e( 'Languages', 'translate-rocket' ); ?></li>
						<li data-dot="2"><?php esc_html_e( 'Translation', 'translate-rocket' ); ?></li>
						<li data-dot="3"><?php esc_html_e( 'Switcher', 'translate-rocket' ); ?></li>
					</ol>
				</div>

				<!-- Step 1: languages -->
				<section class="trr-wiz-step is-current" data-step="1">
					<h2><?php esc_html_e( 'What languages?', 'translate-rocket' ); ?></h2>
					<p class="trr-wiz-lead"><?php esc_html_e( 'Pick the language your site is written in, then the languages to translate it into.', 'translate-rocket' ); ?></p>
					<p>
						<label class="trr-wiz-srclabel"><?php esc_html_e( 'My site is written in:', 'translate-rocket' ); ?>
							<select name="source_language" id="trr-wiz-source">
								<?php foreach ( $builtins as $code => $info ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $source ); ?>><?php echo esc_html( self::lang_option_label( $info ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p class="trr-wiz-sub"><?php esc_html_e( 'Translate into:', 'translate-rocket' ); ?></p>
					<?php self::lang_search_box(); ?>
					<div class="trrocket-lang-grid trr-wiz-grid">
						<?php foreach ( $builtins as $code => $info ) : ?>
							<label class="trrocket-lang-item trr-wiz-target" data-code="<?php echo esc_attr( $code ); ?>" data-cerca="<?php echo esc_attr( self::lang_search_text( (string) $code, $info ) ); ?>"<?php echo ( $code === $source ) ? ' style="display:none"' : ''; ?>>
								<input type="checkbox" name="target_languages[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $targets, true ) ); ?> />
								<span class="trrocket-flag"><?php echo wp_kses( \TranslateRocket\Flags::html( (string) $code, (string) ( $info[2] ?? '' ) ) , \TranslateRocket\Kses::html_rules() ); ?></span>
								<span><?php echo esc_html( $info[0] ); ?><?php self::lang_english( $info ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</section>

				<!-- Step 2: translation method -->
				<section class="trr-wiz-step" data-step="2" hidden>
					<h2><?php esc_html_e( 'How do you want to translate?', 'translate-rocket' ); ?></h2>
					<p class="trr-wiz-lead"><?php esc_html_e( 'You can let an AI do a first pass automatically, or translate by hand — you can always change this later.', 'translate-rocket' ); ?></p>
					<?php
					// 30/9/2026: the answers of people who left the plugin on day one — «too complicated», «I use
					// Firefox» — none with an AI key. A free AI with a free account works in any browser: first here.
					?>
					<label class="trr-wiz-radio">
						<input type="radio" name="trr_method" value="free" checked />
						<span><strong><?php esc_html_e( 'Free AI, with your own free account (recommended)', 'translate-rocket' ); ?></strong><br><?php esc_html_e( 'A Groq key: 2 minutes with a Google account, no card. About 1,000 translations a day, good quality, in any browser — phones and Firefox included.', 'translate-rocket' ); ?></span>
					</label>
					<div class="trr-wiz-free">
						<ol style="margin:6px 0 8px 22px">
							<li><?php echo wp_kses( sprintf( /* translators: %s: link to the Groq console */ __( 'Open %s and sign in with Google.', 'translate-rocket' ), '<a href="https://console.groq.com/keys" target="_blank" rel="noopener">console.groq.com/keys ↗</a>' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></li>
							<li><?php echo wp_kses( __( 'Press <strong>Create API Key</strong>, give it any name, and copy the key (it starts with <code>gsk_</code>).', 'translate-rocket' ), array( 'strong' => array(), 'code' => array() ) ); ?></li>
							<li><?php esc_html_e( 'Paste it here:', 'translate-rocket' ); ?></li>
						</ol>
						<input type="password" name="wiz_free_key" id="trr-wiz-free-key" class="regular-text" placeholder="gsk_…" autocomplete="off" />
						<button type="button" class="button" id="trr-wiz-free-try" data-nonce="<?php echo esc_attr( wp_create_nonce( 'trrocket_try_free_key' ) ); ?>"><?php esc_html_e( 'Try the key', 'translate-rocket' ); ?></button>
						<span id="trr-wiz-free-out" aria-live="polite" style="margin-left:6px"></span>
						<p class="description" style="margin:6px 0 0"><?php esc_html_e( 'More translations a day with Cloudflare Workers AI, also free: set it up later in AI Translation.', 'translate-rocket' ); ?></p>
					</div>
					<label class="trr-wiz-radio">
						<input type="radio" name="trr_method" value="ai" />
						<span><strong><?php esc_html_e( 'Use an AI provider', 'translate-rocket' ); ?></strong><br><?php esc_html_e( 'Paste your own API key. You pay the provider directly — TranslateRocket takes nothing.', 'translate-rocket' ); ?></span>
					</label>
					<div class="trr-wiz-ai" hidden>
						<?php
						// Where to get each key, one click away (a user's suggestion on the support forum, 4/10/2026).
						$wiz_defs = self::provider_defs();
						/* translators: %s: provider name (e.g. OpenAI). */
						$wiz_get = __( 'Get your %s API key ↗', 'translate-rocket' );
						?>
						<select name="wiz_provider" id="trr-wiz-provider">
							<?php foreach ( $prov as $pid => $plabel ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>" data-signup="<?php echo esc_url( (string) ( $wiz_defs[ $pid ]['signup'] ?? '' ) ); ?>" data-get="<?php echo esc_attr( sprintf( $wiz_get, $plabel ) ); ?>"><?php echo esc_html( $plabel ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="password" name="wiz_key" id="trr-wiz-key" class="regular-text" placeholder="<?php esc_attr_e( 'Paste your API key', 'translate-rocket' ); ?>" autocomplete="off" />
						<?php $wiz_first = (string) array_key_first( $prov ); ?>
						<p class="trr-wiz-keylink"><a id="trr-wiz-keylink" class="button button-small" href="<?php echo esc_url( (string) ( $wiz_defs[ $wiz_first ]['signup'] ?? '' ) ); ?>" target="_blank" rel="noopener">&#128273; <span><?php echo esc_html( sprintf( $wiz_get, $prov[ $wiz_first ] ) ); ?></span></a></p>
					</div>
					<label class="trr-wiz-radio">
						<input type="radio" name="trr_method" value="manual" />
						<span><strong><?php esc_html_e( 'No API key for now', 'translate-rocket' ); ?></strong><br><?php esc_html_e( 'Translate by hand, or let Chrome and Edge translate the whole site for free from the Translations page — the text never leaves your computer. You can add a key later at any time.', 'translate-rocket' ); ?></span>
					</label>
					<?php // Se questo browser non ha il traduttore integrato, la strada «senza chiave» qui non funziona: lo si dice subito (prova «WordPress nuovo», 24/9/2026). ?>
					<div class="trr-wiz-nobr notice notice-warning inline" id="trr-wiz-nobr" hidden>
						<p><strong><?php esc_html_e( 'This browser cannot translate by itself.', 'translate-rocket' ); ?></strong> <?php esc_html_e( 'The browser translator runs only in recent Chrome and Edge on a computer. From here, choose «Free AI, with your own free account» above: it works in this browser too.', 'translate-rocket' ); ?></p>
						<?php if ( ! defined( 'TRRLABS_VERSION' ) ) : ?>
							<p id="trr-wiz-labs"><?php esc_html_e( 'Or ask for TranslateRocket Labs, free: an add-on that translates from your server with no key, in any browser — phones included.', 'translate-rocket' ); ?>
							<a href="https://translaterocket.com/labs/?utm_source=plugin" target="_blank" rel="noopener"><?php esc_html_e( 'About Labs ↗', 'translate-rocket' ); ?></a></p>
						<?php endif; ?>
					</div>
				</section>

				<!-- Step 3: switcher -->
				<section class="trr-wiz-step" data-step="3" hidden>
					<h2><?php esc_html_e( 'Show the language switcher', 'translate-rocket' ); ?></h2>
					<p class="trr-wiz-lead"><?php esc_html_e( 'How should visitors switch language? You can fine-tune the look later on the Switcher tab.', 'translate-rocket' ); ?></p>
					<?php $trr_ha_menu = array() !== self::switcher_menu_choices(); ?>
					<?php if ( $trr_ha_menu ) : ?>
					<label class="trr-wiz-radio">
						<input type="radio" name="wiz_switcher" value="menu" checked />
						<span><strong><?php esc_html_e( 'In my header menu', 'translate-rocket' ); ?></strong><br><?php esc_html_e( 'The languages become the last item of your theme\'s menu, in its own style — on phones too. Recommended.', 'translate-rocket' ); ?></span>
					</label>
					<?php endif; ?>
					<label class="trr-wiz-radio">
						<input type="radio" name="wiz_switcher" value="floating" <?php checked( ! $trr_ha_menu ); ?> />
						<span><strong><?php esc_html_e( 'Floating button', 'translate-rocket' ); ?></strong><br><?php esc_html_e( 'A small switcher that floats in a corner on every page. Easiest — nothing else to do.', 'translate-rocket' ); ?></span>
					</label>
					<label class="trr-wiz-radio">
						<input type="radio" name="wiz_switcher" value="shortcode" />
						<span><strong><?php esc_html_e( 'I’ll place it myself', 'translate-rocket' ); ?></strong><br><?php
						/* translators: %s: shortcode. */
						echo esc_html( sprintf( __( 'Use the block “Language Switcher”, or the shortcode %s, wherever you like.', 'translate-rocket' ), '[translaterocket_switcher]' ) ); ?></span>
					</label>
				</section>

				<div class="trr-wiz-nav">
					<button type="button" class="button trr-wiz-back" hidden><?php esc_html_e( '← Back', 'translate-rocket' ); ?></button>
					<span class="trr-wiz-spacer"></span>
					<button type="button" class="button button-primary trr-wiz-next"><?php esc_html_e( 'Next →', 'translate-rocket' ); ?></button>
					<button type="submit" class="button button-primary trr-wiz-finish" hidden><?php esc_html_e( 'Finish & start translating', 'translate-rocket' ); ?></button>
				</div>
			</form>
		</div>
		<?php
		wp_add_inline_script( 'trrocket-admin-info', self::wizard_js() );
	}

	/**
	 * Behaviour for the setup wizard (step navigation + conditional fields).
	 * Returned as a string for wp_add_inline_script().
	 */
	private static function wizard_js(): string {
		return <<<'JS'
( function () {
	var steps = Array.prototype.slice.call( document.querySelectorAll( '.trr-wiz-step' ) );
	var dots  = Array.prototype.slice.call( document.querySelectorAll( '.trr-wiz-steps li' ) );
	var back  = document.querySelector( '.trr-wiz-back' );
	var next  = document.querySelector( '.trr-wiz-next' );
	var finish = document.querySelector( '.trr-wiz-finish' );
	var i = 0;
	function show( n ) {
		i = Math.max( 0, Math.min( steps.length - 1, n ) );
		steps.forEach( function ( s, k ) { s.hidden = ( k !== i ); s.classList.toggle( 'is-current', k === i ); } );
		dots.forEach( function ( d, k ) { d.classList.toggle( 'is-current', k === i ); d.classList.toggle( 'is-done', k < i ); } );
		back.hidden = ( i === 0 );
		next.hidden = ( i === steps.length - 1 );
		finish.hidden = ( i !== steps.length - 1 );
	}
	// Il traduttore del browser c'e'? Solo sui computer, in Chrome ed Edge recenti. Se no, la
	// nota sotto «No API key for now» dice subito che qui quella strada non funziona.
	var nobr = document.getElementById( 'trr-wiz-nobr' );
	function controllaBrowser() {
		if ( ! nobr ) { return; }
		var uad = navigator.userAgentData;
		var telefono = ( uad && typeof uad.mobile === 'boolean' ) ? uad.mobile : /Android|iPhone|iPad|iPod|Mobile/i.test( navigator.userAgent || '' );
		if ( telefono || typeof Translator === 'undefined' || ! Translator.availability || ! window.isSecureContext ) { nobr.hidden = false; return; }
		var da = ( document.getElementById( 'trr-wiz-source' ) || {} ).value || 'en';
		var a = document.querySelector( '.trr-wiz-target input:checked' );
		Translator.availability( { sourceLanguage: da.split( '-' )[ 0 ], targetLanguage: ( a ? a.value : 'it' ).split( '-' )[ 0 ] } )
			.then( function ( st ) { nobr.hidden = !! st && 'unavailable' !== st; } )
			.catch( function () { nobr.hidden = false; } );
	}
	next.addEventListener( 'click', function () { show( i + 1 ); if ( 1 === i ) { controllaBrowser(); } } );
	back.addEventListener( 'click', function () { show( i - 1 ); } );
	// Hide the chosen source language from the targets grid.
	var src = document.getElementById( 'trr-wiz-source' );
	function syncTargets() {
		document.querySelectorAll( '.trr-wiz-target' ).forEach( function ( el ) {
			var isSrc = ( el.getAttribute( 'data-code' ) === src.value );
			el.style.display = isSrc ? 'none' : '';
			if ( isSrc ) { var cb = el.querySelector( 'input' ); if ( cb ) { cb.checked = false; } }
		} );
	}
	if ( src ) { src.addEventListener( 'change', syncTargets ); }
	// Reveal the AI key fields only when "Use an AI provider" is chosen.
	var aiBox = document.querySelector( '.trr-wiz-ai' );
	var freeBox = document.querySelector( '.trr-wiz-free' );
	document.querySelectorAll( 'input[name="trr_method"]' ).forEach( function ( r ) {
		r.addEventListener( 'change', function () {
			var v = document.querySelector( 'input[name=trr_method]:checked' ).value;
			if ( aiBox ) { aiBox.hidden = ( v !== 'ai' ); }
			if ( freeBox ) { freeBox.hidden = ( v !== 'free' ); }
		} );
	} );
	// The «Get your … API key» link follows the provider chosen.
	var wizProv = document.getElementById( 'trr-wiz-provider' );
	var wizLink = document.getElementById( 'trr-wiz-keylink' );
	if ( wizProv && wizLink ) {
		wizProv.addEventListener( 'change', function () {
			var o = wizProv.options[ wizProv.selectedIndex ];
			var url = o ? o.getAttribute( 'data-signup' ) : '';
			wizLink.parentNode.hidden = ! url;
			if ( url ) {
				wizLink.href = url;
				wizLink.querySelector( 'span' ).textContent = o.getAttribute( 'data-get' );
			}
		} );
	}
	// «Try the key»: the server asks Groq for its models with the key just pasted.
	var tryBtn = document.getElementById( 'trr-wiz-free-try' );
	if ( tryBtn ) {
		tryBtn.addEventListener( 'click', function () {
			var out = document.getElementById( 'trr-wiz-free-out' );
			var d = new FormData();
			d.append( 'action', 'trrocket_try_free_key' );
			d.append( 'nonce', tryBtn.getAttribute( 'data-nonce' ) );
			d.append( 'key', document.getElementById( 'trr-wiz-free-key' ).value );
			tryBtn.disabled = true; out.textContent = '…';
			fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: d } ).then( function ( r ) { return r.json(); } ).then( function ( j ) {
				out.style.color = j && j.success ? '#00a32a' : '#b32d2e';
				out.textContent = ( j && j.success ? '✓ ' : '✗ ' ) + ( j && j.data ? j.data : '' );
			} ).catch( function () { out.textContent = '✗'; } ).then( function () { tryBtn.disabled = false; } );
		} );
	}
	show( 0 );
}() );
JS;
	}

	/**
	 * Behaviour for the custom-flag picker on the Languages page (palette toggle +
	 * wp.media upload). Config from window.TRRocketFlag. Returned as a string for
	 * wp_add_inline_script().
	 */
	private static function flag_picker_js(): string {
		return <<<'JS'
( function () {
	var btn  = document.querySelector( '.trr-flagpick-btn' );
	var pal  = document.querySelector( '.trr-flagpick' );
	var inp  = document.getElementById( 'trr-custom-flag' );
	var prev = document.querySelector( '.trr-flagpick-preview' );
	if ( ! btn || ! pal || ! inp ) { return; }
	btn.addEventListener( 'click', function ( e ) { e.preventDefault(); pal.hidden = ! pal.hidden; } );
	pal.addEventListener( 'click', function ( e ) {
		var t = e.target.closest ? e.target.closest( '.trr-flagpick-item' ) : null;
		if ( ! t ) { return; }
		e.preventDefault();
		inp.value = t.getAttribute( 'data-val' ) || '';
		if ( prev ) { prev.innerHTML = t.innerHTML; }
		pal.hidden = true;
	} );
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest || ! e.target.closest( '.trr-flagpick-wrap' ) ) { pal.hidden = true; }
	} );
	// Upload a custom flag image (for languages with no matching country flag).
	var up = pal.querySelector( '.trr-flagpick-upload' );
	if ( up ) {
		var frame;
		var cfg = window.TRRocketFlag || {};
		up.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( ! ( window.wp && wp.media ) ) { return; }
			if ( ! frame ) {
				frame = wp.media( { title: cfg.title, library: { type: 'image' }, multiple: false, button: { text: cfg.useBtn } } );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					if ( att && att.url ) {
						inp.value = 'url:' + att.url;
						if ( prev ) { prev.innerHTML = '<img src="' + att.url + '" style="width:18px;height:12px;object-fit:cover;border-radius:2px;vertical-align:middle" alt="" />'; }
						pal.hidden = true;
					}
				} );
			}
			frame.open();
		} );
	}
}() );
JS;
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		$settings = Settings::get();
		$source   = $settings['source_language'];
		$targets  = (array) $settings['target_languages'];
		?>
		<div class="wrap trrocket-wrap">
			<?php self::header( 'translate-rocket' ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'Languages &amp; general', 'translate-rocket' ); ?></h1>
			<?php // 9/10/2026: the map of what is free (core and Labs) replaces the one-line Labs banner here. ?>
			<?php \TranslateRocket\Admin\Overview::render(); ?>


			<?php if ( empty( $targets ) ) : ?>
				<div class="trrocket-card" style="border-left:4px solid #4f46e5">
					<h2>🚀 <?php esc_html_e( 'Quick start', 'translate-rocket' ); ?></h2>
					<ol style="margin:0 0 4px 18px;line-height:1.9">
						<li><?php esc_html_e( 'Choose your site language and the languages to translate into — right below.', 'translate-rocket' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: AI Translation page link. */
								esc_html__( 'Translate: add an AI key in %s for one-click translation, or translate by hand on each page.', 'translate-rocket' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=translate-rocket-ai' ) ) . '">' . esc_html__( 'AI Translation', 'translate-rocket' ) . '</a>'
							);
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: %s: Switcher page link. */
								esc_html__( 'Add a language switcher: place the [translaterocket_switcher] shortcode or block, or enable the floating one in %s.', 'translate-rocket' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=translate-rocket-switcher' ) ) . '">' . esc_html__( 'Switcher', 'translate-rocket' ) . '</a>'
							);
							?>
						</li>
					</ol>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $targets ) && \TranslateRocket\Coexistence::on() && \TranslateRocket\Coexistence::can_preview() ) : ?>
				<div class="trrocket-card trr-coex-preview" style="border-left:4px solid #0ea5e9">
					<h2>👁 <?php esc_html_e( 'Preview', 'translate-rocket' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %s: the other translation plugin(s), e.g. "Polylang". */
							esc_html__( '%s is still serving your public site. Open it in a language to see the translations TranslateRocket will show when it goes online: only administrators see these previews, while visitors and search engines keep the site as it is.', 'translate-rocket' ),
							esc_html( \TranslateRocket\Coexistence::active_names() )
						);
						?>
					</p>
					<p style="display:flex;flex-wrap:wrap;gap:8px">
						<?php foreach ( \TranslateRocket\Coexistence::preview_targets() as $code ) : ?>
							<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( \TranslateRocket\Coexistence::preview_url( $code ) ); ?>">
								<?php echo wp_kses( self::lang_html( (string) $code ), \TranslateRocket\Kses::html_rules() ); ?> ↗
							</a>
						<?php endforeach; ?>
					</p>
					<p class="description"><?php esc_html_e( 'To preview a specific page, open it on your site and use Preview in the admin bar, or the Preview link under each page in Pages and Posts.', 'translate-rocket' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'trrocket_save_settings', 'trrocket_settings_nonce' ); ?>
				<div class="trrocket-card trr-card-langs">
					<h2><?php esc_html_e( 'Languages', 'translate-rocket' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="trrocket-source"><?php esc_html_e( 'Site language (source)', 'translate-rocket' ); ?></label> <?php $this->info( __( 'The language your existing content is written in. It stays at the site root with no URL prefix and is never translated.', 'translate-rocket' ) ); ?>
							</th>
							<td>
								<select id="trrocket-source" name="source_language">
									<?php foreach ( Languages::all() as $code => $info ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $source, $code ); ?>>
											<?php echo esc_html( self::lang_option_label( $info ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'The language your content is written in. It stays at the site root (no URL prefix).', 'translate-rocket' ); ?></p>
							</td>
						</tr>
					</table>

					<?php // 14/10/2026 (Federico: «può essere allineato in una maniera più efficace»): the same two columns as «Site language» above. ?>
					<div class="trr-into-row">
						<h3 class="trr-into-th"><?php esc_html_e( 'Translate into', 'translate-rocket' ); ?>
							<?php
							$this->info(
								sprintf(
									/* translators: 1: the source language name, 2: the name of the field above, e.g. Site language (source). */
									__( 'The languages visitors can switch to. Each gets its own URL prefix (e.g. /it/) and its own set of translations. Your source language (%1$s) is not in this list: it is the language you translate from — change it in «%2$s» above.', 'translate-rocket' ),
									Languages::label( $source ),
									__( 'Site language (source)', 'translate-rocket' )
								)
							);
							?>
						</h3>
						<p class="description trr-into-td"><?php esc_html_e( 'A language you add starts offline: only you see it while you translate it. Flip its switch to green when it is ready.', 'translate-rocket' ); ?></p>
					</div>

					<?php
					// Ogni lingua scelta si porta accanto il suo interruttore: verde
					// quando i visitatori la vedono, grigio finche' la si traduce.
					$offline_ora = (array) ( $settings['offline_languages'] ?? array() );
					$testo_on    = __( 'Online: your visitors see this language. Click to hide it while you work on it.', 'translate-rocket' );
					$testo_off   = __( 'Offline: only you see this language. Click to publish it.', 'translate-rocket' );
					?>
					<?php
					self::lang_search_box();
					// 14/10/2026 (visual check: 80 languages always open made the page 3,900 px, 7,400 on a phone): with
					// languages chosen, only those show; typing in the search box shows every match; one button opens all.
					$trr_chiusa = ! empty( array_diff( (array) $targets, array( $source ) ) );
					$trr_tutte  = count( Languages::all() ) - 1;
					if ( $trr_chiusa ) :
						?>
						<p class="trr-lang-allp" style="margin:-4px 0 10px"><button type="button" class="button button-small" id="trr-lang-all"
							data-open="<?php echo esc_attr( sprintf( /* translators: %d: how many languages there are. */ __( 'Show all languages (%d)', 'translate-rocket' ), $trr_tutte ) ); ?>"
							data-close="<?php esc_attr_e( 'Show only my languages', 'translate-rocket' ); ?>"><?php echo esc_html( sprintf( /* translators: %d: how many languages there are. */ __( 'Show all languages (%d)', 'translate-rocket' ), $trr_tutte ) ); ?></button></p>
						<script>
						// The grid comes after this script: wait for the page (found by collaudo-cerca-lingue, 14/10/2026).
						document.addEventListener( 'DOMContentLoaded', function () {
							var b = document.getElementById( 'trr-lang-all' ), g = document.querySelector( '.trr-lang-grid' ), q = document.querySelector( '.trr-lang-cerca-in' );
							if ( ! b || ! g ) { return; }
							b.addEventListener( 'click', function () {
								var chiusa = g.classList.toggle( 'is-folded' );
								b.textContent = b.getAttribute( chiusa ? 'data-open' : 'data-close' );
							} );
							if ( q ) { q.addEventListener( 'input', function () { g.classList.toggle( 'is-searching', '' !== q.value.trim() ); } ); }
							// a language ticked while searching stays in view after the search is cleared
							g.addEventListener( 'change', function ( e ) {
								var it = e.target.closest && e.target.closest( '.trrocket-lang-item' );
								if ( it && 'target_languages[]' === e.target.name ) { it.classList.toggle( 'is-chosen', e.target.checked ); }
							} );
						} );
						</script>
					<?php endif; ?>
					<div class="trrocket-lang-grid trr-lang-grid<?php echo $trr_chiusa ? ' is-folded' : ''; ?>">
						<?php
						foreach ( Languages::all() as $code => $info ) :
							if ( $code === $source ) {
								continue;
							}
							$scelta = in_array( $code, $targets, true );
							$spenta = in_array( (string) $code, $offline_ora, true );
							?>
							<div class="trrocket-lang-item<?php echo $scelta ? ' is-chosen' : ''; ?><?php echo $spenta ? ' is-offline' : ''; ?>" data-code="<?php echo esc_attr( (string) $code ); ?>" data-cerca="<?php echo esc_attr( self::lang_search_text( (string) $code, $info ) ); ?>"<?php echo $scelta ? ' data-era-scelta="1"' : ''; ?> data-era-spenta="<?php echo $spenta ? '1' : '0'; ?>">
								<label class="trrocket-lang-pick">
									<input type="checkbox" name="target_languages[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( $scelta ); ?> />
									<span class="trrocket-flag"><?php echo wp_kses( \TranslateRocket\Flags::html( (string) $code, (string) ( $info[2] ?? '' ) ) , \TranslateRocket\Kses::html_rules() ); ?></span>
									<span class="trrocket-lang-name"><?php echo esc_html( $info[0] ); ?><?php self::lang_english( $info ); ?></span>
								</label>
								<label class="trr-onoff" data-on="<?php echo esc_attr( $testo_on ); ?>" data-off="<?php echo esc_attr( $testo_off ); ?>" title="<?php echo esc_attr( $spenta ? $testo_off : $testo_on ); ?>">
									<input type="checkbox" class="trr-onoff-in" name="offline_languages[]" value="<?php echo esc_attr( (string) $code ); ?>" <?php checked( $spenta ); ?> />
									<span class="trr-onoff-track" aria-hidden="true"><span class="trr-onoff-dot"></span></span>
									<span class="trr-onoff-tag trr-onoff-yes"><?php esc_html_e( 'online', 'translate-rocket' ); ?></span>
									<span class="trr-onoff-tag trr-onoff-no"><?php esc_html_e( 'offline', 'translate-rocket' ); ?></span>
								</label>
							</div>
						<?php endforeach; ?>
					</div>

					<?php
					// Quali fra le lingue scelte sono ancora in lavorazione.
					// La sezione compare solo se una lingua c'e' davvero: su un
					// sito appena installato non serve a niente. L'interruttore vero
					// sta sopra, accanto alla lingua: qui si legge soltanto com'e'
					// messo, insieme a quanto manca da tradurre.
					$scelte = array_values( array_filter( (array) $targets, static function ( $c ) use ( $source ) {
						return $c !== $source;
					} ) );
					if ( ! empty( $scelte ) ) :
						?>
					<div class="trr-lang-state">
						<h3><?php esc_html_e( 'Published, or still being translated?', 'translate-rocket' ); ?>
							<?php $this->info( __( 'A language kept offline is invisible to visitors: it is left out of the language switcher, out of your sitemap and out of the hreflang tags, and anyone landing on one of its URLs is sent to your default language. You keep working on it normally — and you can see it on the real pages, because whoever can preview still sees everything. Put it online when you are happy with it.', 'translate-rocket' ) ); ?>
						</h3>
						<p class="description"><?php esc_html_e( 'Add a language today, publish it when it is ready. Nothing half-translated ever reaches your visitors or Google.', 'translate-rocket' ); ?></p>
						<?php $trr_live_now = AutoPage::live(); // the hourglass is right from the first paint ?>
						<table class="trr-lang-state-table">
							<tbody>
							<?php
							foreach ( $scelte as $code ) :
								$nome    = Languages::label( (string) $code );
								$manca   = \TranslateRocket\Strings::untranslated_count( (string) $code );
								$fatte   = \TranslateRocket\Strings::translated_count( (string) $code );
								$totale  = $manca + $fatte;
								$perc    = $totale > 0 ? (int) floor( 100 * $fatte / $totale ) : 0; // floor: 99.6% is not «100%»
								$is_off  = in_array( (string) $code, $offline_ora, true );
								?>
								<tr class="<?php echo $is_off ? 'is-offline' : ''; ?>" data-code="<?php echo esc_attr( (string) $code ); ?>" data-trr-live="<?php echo esc_attr( (string) $code ); ?>">
									<th scope="row">
										<span class="trrocket-flag"><?php echo wp_kses( \TranslateRocket\Flags::html( (string) $code, '' ), \TranslateRocket\Kses::html_rules() ); ?></span>
										<strong><?php echo esc_html( $nome ); ?></strong>
										<code>/<?php echo esc_html( (string) $code ); ?>/</code>
									</th>
									<td class="trr-lang-state-prog">
										<?php
											// 9/10/2026 (Federico: «cliccando ti porta alla pagina traduzioni della corrispondente lingua»):
											// the bar and the percentage open the Strings page of that language, and a button beside them says so.
											$vai = $this->editor_url( (string) $code );
											?>
											<?php if ( $totale > 0 ) : ?>
											<a class="trr-lang-state-link" href="<?php echo esc_url( $vai ); ?>">
											<div class="trr-prog" title="<?php echo esc_attr( sprintf( /* translators: 1: translated strings, 2: total strings. */ __( '%1$d of %2$d translated', 'translate-rocket' ), $fatte, $totale ) ); ?>">
												<?php
												// Il colore va scritto qui: .trr-prog-fill non ne ha uno suo, e
												// senza questo la barra restava una vaschetta grigia vuota anche
												// al 98%. Rosso -> giallo -> verde, come le altre barre.
												$tinta = self::hsl_to_hex( (int) round( $perc * 1.2 ), 72, 45 );
												?>
												<div class="trr-prog-bar"><div class="trr-prog-fill" style="width:<?php echo (int) $perc; ?>%;background:<?php echo esc_attr( $tinta ); ?>"></div></div>
											</div>
											<span class="trr-busy" <?php echo empty( $trr_live_now[ (string) $code ]['busy'] ) ? 'hidden' : ''; ?> aria-label="<?php esc_attr_e( 'Being translated now', 'translate-rocket' ); ?>">⏳</span><span class="trr-lang-state-pc"><?php echo (int) $perc; ?>%</span>
												<span class="trr-auto-n description"><?php echo (int) $fatte . ' / ' . (int) $totale; ?></span>
											</a>
										<?php else : ?>
											<span class="description"><?php esc_html_e( 'nothing collected yet', 'translate-rocket' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="trr-lang-state-go">
										<a class="button button-small<?php echo $manca > 0 ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $vai ); ?>"><?php esc_html_e( 'Translate', 'translate-rocket' ); ?></a>
									</td>
									<td class="trr-lang-state-switch">
										<span class="trr-state-pill trr-state-yes"><?php esc_html_e( 'online', 'translate-rocket' ); ?></span>
										<span class="trr-state-pill trr-state-no"><?php esc_html_e( 'offline', 'translate-rocket' ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<script><?php echo AutoPage::live_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
					</div>
					<?php endif; ?>

					<?php
					$customs = ( isset( $settings['custom_languages'] ) && is_array( $settings['custom_languages'] ) ) ? $settings['custom_languages'] : array();
					?>
					<div class="trr-custom-langs">
						<h3><?php esc_html_e( 'Custom language', 'translate-rocket' ); ?> <?php $this->info( __( 'Not in the list? Add your own: a short code (used in the URL, e.g. /la/), a name, and a flag picked from the list. After saving it appears among the languages above — tick it to enable.', 'translate-rocket' ) ); ?></h3>
						<?php if ( ! empty( $customs ) ) : ?>
							<ul class="trr-custom-list">
								<?php foreach ( $customs as $code => $info ) : ?>
									<li>
										<span class="trrocket-flag"><?php echo wp_kses( \TranslateRocket\Flags::html( (string) $code, (string) ( $info[2] ?? '' ) ) , \TranslateRocket\Kses::html_rules() ); ?></span>
										<strong><?php echo esc_html( (string) ( $info[0] ?? $code ) ); ?></strong>
										<code>/<?php echo esc_html( (string) $code ); ?>/</code>
										<label class="trr-custom-remove"><input type="checkbox" name="remove_custom[]" value="<?php echo esc_attr( (string) $code ); ?>"> <?php esc_html_e( 'remove', 'translate-rocket' ); ?></label>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php $flag_codes = \TranslateRocket\Flags::codes(); ?>
						<div class="trr-custom-add">
							<label class="trr-custom-field">
								<span class="trr-custom-flabel"><?php esc_html_e( 'Code', 'translate-rocket' ); ?></span>
								<input type="text" name="custom_lang_code" value="" placeholder="<?php esc_attr_e( 'e.g. la', 'translate-rocket' ); ?>" size="8" maxlength="8" />
							</label>
							<label class="trr-custom-field">
								<span class="trr-custom-flabel"><?php esc_html_e( 'Name', 'translate-rocket' ); ?></span>
								<input type="text" name="custom_lang_name" value="" placeholder="<?php esc_attr_e( 'e.g. Latina', 'translate-rocket' ); ?>" />
							</label>
							<span class="trr-custom-field trr-flagpick-wrap">
								<span class="trr-custom-flabel"><?php esc_html_e( 'Flag', 'translate-rocket' ); ?></span>
								<input type="hidden" name="custom_lang_flag" id="trr-custom-flag" value="" />
								<button type="button" class="button trr-flagpick-btn"><span class="trr-flagpick-preview" aria-hidden="true">&#127760;</span> <?php esc_html_e( 'Choose…', 'translate-rocket' ); ?> &#9662;</button>
								<span class="trr-flagpick" hidden>
									<button type="button" class="trr-flagpick-item" data-val="" title="<?php esc_attr_e( 'No flag (globe)', 'translate-rocket' ); ?>"><span class="trr-flagpick-globe">&#127760;</span></button>
									<?php foreach ( $flag_codes as $fc ) : ?>
										<button type="button" class="trr-flagpick-item" data-val="svg:<?php echo esc_attr( $fc ); ?>" title="<?php echo esc_attr( strtoupper( (string) $fc ) ); ?>"><?php echo wp_kses( \TranslateRocket\Flags::svg( (string) $fc ) , \TranslateRocket\Kses::html_rules() ); ?></button>
									<?php endforeach; ?>
									<button type="button" class="trr-flagpick-upload"><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Upload your own…', 'translate-rocket' ); ?></button>
								</span>
							</span>
							<button type="submit" class="button button-secondary trr-custom-addbtn"><?php esc_html_e( '+ Add language', 'translate-rocket' ); ?></button>
						</div>
						<p class="description"><?php esc_html_e( 'Enter a code (it becomes the URL prefix, e.g. /la/) and a name, then pick a flag: choose one from the list, upload your own image (for a region or language with no country flag), or leave the 🌐 globe.', 'translate-rocket' ); ?></p>
						<?php
						$flag_data = 'window.TRRocketFlag=' . wp_json_encode(
							array(
								'title'  => __( 'Choose a flag image', 'translate-rocket' ),
								'useBtn' => __( 'Use this image', 'translate-rocket' ),
							)
						) . ';';
						wp_add_inline_script( 'trrocket-admin-info', $flag_data . self::flag_picker_js() );
						?>
					</div>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Who sees the translations', 'translate-rocket' ); ?> <?php $this->info( __( 'Preview mode: keep the translated site visible to administrators only, e.g. to review an import from another plugin on the real pages before going live. Visitors keep seeing the default language (language URLs redirect, the switcher is hidden and no hreflang/sitemap signals are sent). Switch back to "Everyone" to publish instantly.', 'translate-rocket' ) ); ?></th>
							<td>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="serve_mode" value="everyone" <?php checked( 'admins' !== ( $settings['serve_mode'] ?? 'everyone' ) ); ?> />
									<?php esc_html_e( 'Everyone — translations are live', 'translate-rocket' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="serve_mode" value="admins" <?php checked( 'admins' === ( $settings['serve_mode'] ?? 'everyone' ) ); ?> />
									<?php esc_html_e( 'Administrators only — preview mode, visitors keep seeing the default language', 'translate-rocket' ); ?>
								</label>
							</td>
						</tr>
						<tr id="trr-set-seo">
							<th scope="row"><?php esc_html_e( 'SEO meta', 'translate-rocket' ); ?> <?php $this->info( __( 'Also translate the SEO title and meta description — the text shown in Google results and the browser tab.', 'translate-rocket' ) ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="translate_meta" value="1" <?php checked( ! empty( $settings['translate_meta'] ) ); ?> />
									<?php esc_html_e( 'Translate SEO title and meta description tags', 'translate-rocket' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Interface language', 'translate-rocket' ); ?> <?php $this->info( __( 'Show menus, buttons and theme/plugin labels in the visitor\'s language using WordPress\' own official translations — no AI, nothing to manage. The needed language packs are downloaded automatically. Turn off to leave the interface in the site language.', 'translate-rocket' ) ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="translate_interface" value="1" <?php checked( ! empty( $settings['translate_interface'] ) ); ?> />
									<?php esc_html_e( 'Use WordPress\' own translations for the interface', 'translate-rocket' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Auto-redirect', 'translate-rocket' ); ?> <?php $this->info( __( 'On their first visit, send visitors to the version matching their browser language (remembered with a cookie, only once). Never redirects search engines or logged-in users.', 'translate-rocket' ) ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="auto_redirect" value="1" <?php checked( ! empty( $settings['auto_redirect'] ) ); ?> />
									<?php esc_html_e( 'Redirect new visitors to their browser language', 'translate-rocket' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Search in every language', 'translate-rocket' ); ?> <?php $this->info( __( 'Without this, the search box of a translated page finds nothing: WordPress searches the text in your site\'s own language. With it, a visitor on /it/ who types «colazione» finds your page about breakfast. Only the text you wrote for each page counts — its title, excerpt and content — never menus, buttons, footers or cookie banners, which are on every page. Every word must be on the page, the title weighs most, and the theme\'s own search box, Elementor\'s search widget and the Search block work as they are. The index builds by itself in the background and follows every change.', 'translate-rocket' ) ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="translated_search" value="1" <?php checked( ! isset( $settings['translated_search'] ) || ! empty( $settings['translated_search'] ) ); ?> />
									<?php esc_html_e( 'The site search finds pages by their translated text', 'translate-rocket' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Visits per language', 'translate-rocket' ); ?> <?php $this->info( __( 'How many visits each language gets, its most read pages, and the languages your visitors\' browsers speak — so you see which language is worth adding next. Counted by a small signal the browser sends after the page has loaded (it works with page caches and CDNs too): no cookie, no IP address, nothing personal, so no consent banner is needed for it. Robots, search engines and administrators are not counted. The numbers appear on this screen, below.', 'translate-rocket' ) ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="stats" value="1" <?php checked( ! empty( $settings['stats'] ) ); ?> />
									<?php esc_html_e( 'Count the visits of each language (no cookies)', 'translate-rocket' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'A different menu per language', 'translate-rocket' ); ?> <?php $this->info( __( 'Usually each language shows your menu, translated. Here a language can have a whole menu of its own instead — other pages, other order, written in that language (it is not translated). Create it first in Appearance → Menus (or in the Site Editor for block themes), then choose it here. To hide only some items in some languages, use «Show in» on each item in Appearance → Menus.', 'translate-rocket' ) ); ?></th>
							<td><?php \TranslateRocket\MenuPerLanguage::render_rows(); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Page cache', 'translate-rocket' ); ?> <?php $this->info( __( 'Store the finished translated HTML of each page so repeat visits skip the on-the-fly translation — about 2× faster on repeat visits (e.g. ~0.55s → ~0.29s in our test). Only anonymous visitors are served from the cache, it skips dynamic pages (cart, checkout, account), and it clears automatically whenever you change a translation, edit a page, or update settings. On by default — turn it off only if you already use a page-cache plugin (e.g. WP Rocket), which already caches each language’s URL.', 'translate-rocket' ) ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="cache_pages" value="1" <?php checked( ! empty( $settings['cache_pages'] ) ); ?> />
									<?php esc_html_e( 'Cache translated pages for anonymous visitors', 'translate-rocket' ); ?>
								</label>
								<?php
								// 1.8.6: another page cache is already there — ours steps aside by itself, and says so.
								$trr_altre = \TranslateRocket\Conflicts::page_caches();
								if ( $trr_altre && ! empty( $settings['cache_pages'] ) ) :
									?>
									<p class="trr-conflict-note">
										<?php
										printf(
											/* translators: %s: names of the page caches found on the site. */
											esc_html__( 'Off by itself: %s already caches your pages. Two caches on top of each other keep showing an old translation even after you clear them; translated pages stay fast, served by that cache, and when you change a translation TranslateRocket clears it too.', 'translate-rocket' ),
											'<strong>' . esc_html( implode( ', ', $trr_altre ) ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here.
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Powered by badge', 'translate-rocket' ); ?> <?php $this->info( __( 'Show a small "Powered by TranslateRocket" badge with the logo at the bottom of your site. Off by default and entirely optional — a nice way to support the free plugin. You can also place it anywhere with the [translaterocket_poweredby] shortcode.', 'translate-rocket' ) ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="show_poweredby" value="1" <?php checked( ! empty( $settings['show_poweredby'] ) ); ?> />
									<?php esc_html_e( 'Show a "Powered by TranslateRocket" badge in the footer', 'translate-rocket' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save changes', 'translate-rocket' ) ); ?>
				</div>
			</form>

			<?php // 9/10/2026: «Progress» removed — the same numbers are in «Published, or still being translated?», live. ?>
			<?php \TranslateRocket\Admin\StatsCard::render(); // 1.8.6: visits per language, when switched on ?>
			<?php
			// 1.8.6 (Federico: «rilevare i plugin che fanno lo stesso lavoro»): two JavaScript optimizers at once.
			$trr_js = \TranslateRocket\Conflicts::js_optimizers();
			if ( count( $trr_js ) >= 2 ) :
				?>
				<div class="notice notice-warning inline trr-conflict-js"><p>
					<?php
					printf(
						/* translators: %s: names of two or more plugins that optimize JavaScript. */
						esc_html__( '%s all optimize JavaScript (combine, defer or delay it). Together they can break menus, forms and the language switcher: keep these features on in only one of them.', 'translate-rocket' ),
						'<strong>' . esc_html( implode( ', ', $trr_js ) ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here.
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="trrocket-card">
				<h2>🚀 <?php esc_html_e( 'Need a hand?', 'translate-rocket' ); ?></h2>
				<p><?php esc_html_e( 'Want a professional multilingual setup, reviewed translations or a custom feature? I can help — as a service.', 'translate-rocket' ); ?></p>
				<a class="button" href="https://translaterocket.com/support/" target="_blank" rel="noopener"><?php esc_html_e( 'Get expert help', 'translate-rocket' ); ?></a>
			</div>

			<div class="trrocket-card trrocket-donate">
				<h2>❤️ <?php esc_html_e( 'Support the project', 'translate-rocket' ); ?></h2>
				<p><?php esc_html_e( 'TranslateRocket is 100% free. If it helps you, a small donation keeps it alive and updated.', 'translate-rocket' ); ?></p>
				<a class="button button-primary" href="https://translaterocket.com/donate" target="_blank" rel="noopener"><?php esc_html_e( 'Make a donation', 'translate-rocket' ); ?></a>
				<a class="button" href="https://github.com/sponsors/federcap" target="_blank" rel="noopener">💜 <?php esc_html_e( 'Sponsor on GitHub', 'translate-rocket' ); ?></a>
				<?php
				// One line, not a screen of its own: everyone who helps - a bug report,
				// a translation, a donation - is named on the site. A whole admin page
				// for this would cost every user a menu entry forever, and the list
				// would either be frozen in the code until the next release or fetched
				// from a server nobody asked us to call.
				?>
				<p class="description trr-thanks-line">
					<?php esc_html_e( 'Everyone who helps out is named on the Contributors page.', 'translate-rocket' ); ?>
					<a href="https://translaterocket.com/contributors/" target="_blank" rel="noopener"><?php esc_html_e( 'See who they are', 'translate-rocket' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Translation editor: a list of pages, or a single page's strings.
	 */
	public function render_editor(): void {
		$targets = (array) Settings::get()['target_languages'];

		echo '<div class="wrap trrocket-wrap">';
		self::header( 'translate-rocket-strings' );
		echo '<h1 class="trr-page-title">' . esc_html__( 'Translations', 'translate-rocket' ) . '</h1>';
		// Chiesto da chi migra: i testi che il JavaScript aggiunge (banner dei cookie, pop-up,
		// menu dentro un pop-up) non esistono finche' non si aprono, quindi qui non compaiono
		// e sembra che il plugin non li veda.
		echo '<p class="trrocket-tagline">' . esc_html__( 'Text inside pop-ups and cookie banners appears here once you have opened it yourself, as an administrator, on a page in your source language.', 'translate-rocket' ) . '</p>';

		if ( empty( $targets ) ) {
			echo '<p>' . esc_html__( 'Add a target language first in TranslateRocket settings.', 'translate-rocket' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		$lang = isset( $_GET['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['lang'] ) ) ) : '';
		if ( ! in_array( $lang, $targets, true ) ) {
			$lang = $targets[0];
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$loc = isset( $_GET['loc'] ) ? sanitize_text_field( wp_unslash( $_GET['loc'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Translations saved.', 'translate-rocket' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $_GET['badparts'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of translations that were not saved. */
					_n(
						'%d translation was not saved: the marks that hold the place of a link or a bold word were missing or changed. Open that line again and keep every mark, with its number, anywhere in the sentence.',
						'%d translations were not saved: the marks that hold the place of a link or a bold word were missing or changed. Open those lines again and keep every mark, with its number, anywhere in the sentence.',
						(int) $_GET['badparts'],
						'translate-rocket'
					),
					(int) $_GET['badparts']
				)
			) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['imported'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Pasted translations imported.', 'translate-rocket' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['noimport'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Nothing imported — the paste box was empty, so existing translations were kept.', 'translate-rocket' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['reset'] ) ) {
			$loc_r  = isset( $_GET['loc'] ) ? sanitize_text_field( wp_unslash( $_GET['loc'] ) ) : '';
			$lang_r = isset( $_GET['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['lang'] ) ) ) : '';
			$ultimo = '' !== $loc_r ? \TranslateRocket\PageReset::latest( $loc_r, $lang_r ) : null;
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( "This page's translations and translated address were removed: translate it again below, with AI, in your browser or by hand.", 'translate-rocket' );
			if ( $ultimo && (int) ( $ultimo['kept'] ?? 0 ) > 0 ) {
				echo ' ' . esc_html( sprintf(
					/* translators: %d: number of sentences. */
					_n( '%d sentence also used on other pages kept its translation.', '%d sentences also used on other pages kept their translation.', (int) $ultimo['kept'], 'translate-rocket' ),
					(int) $ultimo['kept']
				) );
			}
			if ( $ultimo ) {
				$undo = wp_nonce_url( add_query_arg( array( 'page' => 'translate-rocket-strings', 'lang' => $lang_r, 'loc' => $loc_r, 'trrocket_undo' => $ultimo['id'] ), admin_url( 'admin.php' ) ), 'trrocket_undo_' . $ultimo['id'] );
				echo ' <a href="' . esc_url( $undo ) . '"><strong>' . esc_html__( 'Undo', 'translate-rocket' ) . '</strong></a> <span class="description">' . esc_html__( '(puts back the translations you just removed)', 'translate-rocket' ) . '</span>';
			}
			echo '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['undone'] ) ) {
			$n_u = (int) $_GET['undone']; // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf(
				/* translators: %d: number of translations put back. */
				_n( 'Put back: %d translation, and the translated address.', 'Put back: %d translations, and the translated address.', $n_u, 'translate-rocket' ),
				$n_u
			) ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['forgotten'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Page removed from the list.', 'translate-rocket' ) . '</p></div>';
		}
		$ai_notice = get_transient( 'trrocket_ai_notice_' . get_current_user_id() );
		if ( is_array( $ai_notice ) ) {
			delete_transient( 'trrocket_ai_notice_' . get_current_user_id() );
			if ( ! empty( $ai_notice['ok'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>'
					. sprintf(
						/* translators: %d: number of strings translated. */
						esc_html__( 'AI translated %d strings.', 'translate-rocket' ),
						(int) $ai_notice['count']
					) . '</p></div>';
			} else {
				self::avviso_ai_fallita( (string) $ai_notice['error'] );
			}
		}

		// Language switcher (keeps the current page when set).
		// 9/10/2026 (Federico: «sotto la bandiera la barra con la percentuale tradotta, così a vista si
		// vede»; «se c'è un processo su quella lingua fallo capire»): bar, percentage and hourglass in
		// each button, moving by themselves.
		$trr_live = AutoPage::live();
		echo '<p class="trr-langbar trr-langbar-live"><strong>' . esc_html__( 'Language:', 'translate-rocket' ) . '</strong> ';
		foreach ( $targets as $code ) {
			$d = $trr_live[ (string) $code ] ?? array( 'pct' => 0, 'busy' => false, 'detected' => 0 );
			printf(
				'<a class="button trr-langbtn %1$s%5$s" href="%2$s" data-trr-live="%6$s"><span class="trr-langbtn-top">%3$s</span><span class="trr-langbtn-prog"><span class="trr-prog"><span class="trr-prog-bar"><span class="trr-prog-fill" style="width:%4$d%%"></span></span></span><span class="trr-busy" %7$s>⏳</span><span class="trr-auto-pc">%4$d%%</span></span></a> ',
				$code === $lang ? 'button-primary' : '',
				esc_url( $this->editor_url( $code, $loc ) ),
				wp_kses( self::lang_html( (string) $code ), \TranslateRocket\Kses::html_rules() ),
				(int) $d['pct'],
				! empty( $d['busy'] ) ? ' is-busy' : '',
				esc_attr( (string) $code ),
				! empty( $d['busy'] ) ? '' : 'hidden'
			);
		}
		echo '</p>';
		echo '<script>' . AutoPage::live_script() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( '' !== $loc ) {
			$this->render_page_editor( $lang, $loc );
		} else {
			$this->render_pages_list( $lang );
		}

		echo '</div>';
	}


	/**
	 * List of pages with per-language progress.
	 */
	/**
	 * A translation-progress bar whose fill runs red -> yellow -> green with the %.
	 */
	private function progress_bar( int $done, int $total, bool $show_count = false ): string {
		// 9/10/2026 (a customer's site, 13 languages and nothing collected yet): 0 of 0 showed a green
		// «100%», as if it was all done. Nothing collected is not finished.
		if ( $total <= 0 ) {
			return '<div class="trr-prog"><span class="description">' . esc_html__( 'nothing collected yet', 'translate-rocket' ) . '</span></div>';
		}
		$pct   = (int) floor( $done / $total * 100 ); // as the language buttons: 525/693 is 75%, and 99.6% is not «100%»
		$hue   = (int) round( $pct * 1.2 ); // 0% = red(0), 100% = green(120).
		$color = self::hsl_to_hex( $hue, 72, 45 ); // wp_kses (safecss) drops hsl() from inline styles — emit hex.
		$label = $show_count ? $pct . '% · ' . $done . '/' . $total : $pct . '%';
		return '<div class="trr-prog">'
			. '<div class="trr-prog-bar"><div class="trr-prog-fill" style="width:' . $pct . '%;background:' . $color . '"></div></div>'
			. '<div class="trr-prog-label">' . esc_html( $label ) . '</div></div>';
	}

	/**
	 * HSL to #rrggbb. wp_kses's safecss_filter_attr() drops hsl()/rgb() from inline
	 * styles, so the progress bar emits a hex colour instead.
	 */
	private static function hsl_to_hex( int $h, int $s, int $l ): string {
		$sf = $s / 100;
		$lf = $l / 100;
		$c  = ( 1 - abs( 2 * $lf - 1 ) ) * $sf;
		$x  = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
		$m  = $lf - $c / 2;
		$r  = 0;
		$g  = 0;
		$b  = 0;
		if ( $h < 60 ) {
			$r = $c;
			$g = $x;
		} elseif ( $h < 120 ) {
			$r = $x;
			$g = $c;
		} elseif ( $h < 180 ) {
			$g = $c;
			$b = $x;
		} elseif ( $h < 240 ) {
			$g = $x;
			$b = $c;
		} elseif ( $h < 300 ) {
			$r = $x;
			$b = $c;
		} else {
			$r = $c;
			$b = $x;
		}
		return sprintf(
			'#%02x%02x%02x',
			(int) round( ( $r + $m ) * 255 ),
			(int) round( ( $g + $m ) * 255 ),
			(int) round( ( $b + $m ) * 255 )
		);
	}

	private function render_pages_list( string $lang ): void {
		$pages      = Strings::pages_with_stats( $lang );
		$per_motore = Strings::engines_by_page( $lang ); // who translated each page: the labels under its name
		$stats = Strings::language_stats( $lang );

		printf(
			'<p class="description">%s</p>',
			sprintf(
				/* translators: 1: missing count, 2: total count. */
				esc_html__( '%1$d strings still to translate out of %2$d detected.', 'translate-rocket' ),
				(int) $stats['missing'],
				(int) $stats['detected']
			)
		);
		$detected_total = (int) $stats['detected'];
		echo wp_kses(
			'<div style="max-width:360px;margin:10px 0 16px">'
			. $this->progress_bar( $detected_total - (int) $stats['missing'], $detected_total, true )
			. '</div>',
			\TranslateRocket\Kses::html_rules()
		);
		?>
		<?php
		// Le tre strade per tradurre, una accanto all'altra: a mano (il pulsante
		// "Translate" di ogni riga), tutto in una volta con l'AI, oppure con il
		// traduttore del browser, che non chiede nessuna chiave.
		$miss_lingua = Strings::untranslated_count( $lang );
		$fornitore   = Registry::active();
		?>
		<div class="trr-actionbar">
			<button type="button" class="button button-secondary" id="trr-scan-btn">&#128269; <?php esc_html_e( 'Scan site for text', 'translate-rocket' ); ?></button>
			<?php $this->info( __( 'Automatically visits every published page to detect translatable text — no need to open them one by one.', 'translate-rocket' ) ); ?>

			<span class="trr-actionbar-sep" aria-hidden="true"></span>

			<?php
			// Which engine translates: the priority order, one AI, a Labs engine, or this browser.
			$scelte  = self::engine_choices();
			// 1.8.1: the per-page «AI» buttons are there when any engine of the menu works (a Labs engine, no AI key, too).
			$riga_ok = (bool) array_filter( $scelte, static function ( $c ) { return $c['ok']; } );
			$scelto  = (string) get_user_meta( get_current_user_id(), 'trrocket_strings_engine', true );
			// Nothing chosen and no AI key: the browser, free and with no limit, is the default (14/10/2026).
			if ( '' === $scelto && empty( Registry::ordered() ) ) {
				$scelto = 'browser';
			}
			$valido  = false;
			foreach ( $scelte as $c ) {
				$valido = $valido || ( $c['ok'] && $c['value'] === $scelto );
			}
			if ( ! $valido ) {
				$scelto = '';
				if ( empty( $scelte[0]['ok'] ) ) {
					foreach ( $scelte as $c ) {
						if ( $c['ok'] ) {
							$scelto = $c['value'];
							break;
						}
					}
					if ( '' === $scelto ) {
						$scelto = 'browser';
					}
				}
			}
			$gruppi = array(
				'free'  => __( 'FREE — no limits', 'translate-rocket' ),
				'limit' => __( 'FREE — with a daily limit', 'translate-rocket' ),
				'paid'  => __( 'With your paid key', 'translate-rocket' ),
			);
			// The note shown under the menu when a Labs engine is chosen, on or off.
			$note_labs = array(
				'labs-ok' => __( 'An engine of TranslateRocket Labs: an official, free service with a daily limit. When the limit is used up, go on tomorrow or choose another engine. These are machine translations: review your important pages.', 'translate-rocket' ),
				'labs-no' => __( 'An engine of TranslateRocket Labs: an unofficial endpoint, not a public service of its owner. It can change or stop working at any time, and using it is your choice and your responsibility. These are machine translations: review your important pages.', 'translate-rocket' ),
			);
			$per_note = array();
			$avvisi = array();
			?>
			<form method="post" action="" id="trr-engine-form">
				<?php wp_nonce_field( 'trrocket_auto_translate', 'trrocket_auto_nonce' ); ?>
				<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
				<input type="hidden" name="loc" value="" />
				<input type="hidden" name="engine_main" value="1" />
				<label for="trr-engine" class="trr-engine-label" id="trr-engine-label-txt"><?php esc_html_e( 'Translate with', 'translate-rocket' ); ?></label>
				<?php $this->info( __( 'Automatic uses your AI providers in the order set on AI Translation: when one reaches its daily limit, the next one continues. Choose one engine to use only that one — also for the «AI» button of each page. The free engines of Labs need no key: each one is switched on the first time you use it, after you accept its conditions. «This browser» uses the translator built into Chrome or Edge on this computer. Greyed-out engines show what they still need.', 'translate-rocket' ) ); ?>
				<select name="engine" id="trr-engine" class="trr-engine trr-engine-native">
					<?php
					$aperto = '';
					foreach ( $scelte as $c ) {
						if ( $c['group'] !== $aperto ) {
							if ( '' !== $aperto ) {
								echo '</optgroup>';
							}
							$aperto = $c['group'];
							if ( '' !== $aperto ) {
								echo '<optgroup label="' . esc_attr( $gruppi[ $aperto ] ?? $aperto ) . '">';
							}
						}
						if ( $c['warning'] ) {
							$avvisi[ $c['value'] ] = $c['warning'];
						}
						if ( isset( $note_labs[ $c['labsg'] ] ) ) {
							$per_note[ $c['value'] ] = $note_labs[ $c['labsg'] ];
						}
						printf(
							'<option value="%1$s"%2$s%3$s data-tier="%5$s" data-quality="%6$s" data-qkey="%7$s" data-limit="%8$s" data-needs="%9$s" data-where="%10$s" data-goes="%11$s" data-why="%12$s" data-name="%13$s">%4$s</option>',
							esc_attr( $c['value'] ),
							selected( $scelto, $c['value'], false ),
							$c['ok'] ? '' : ' disabled',
							esc_html( $c['label'] . ( '' !== $c['why'] ? ' — ' . $c['why'] : '' ) ),
							esc_attr( (string) $c['group'] ),
							esc_attr( (string) $c['quality'] ),
							esc_attr( (string) $c['qkey'] ),
							esc_attr( (string) $c['limit'] ),
							esc_attr( (string) $c['needs'] ),
							esc_attr( (string) $c['where'] ),
							esc_attr( (string) $c['goes'] ),
							esc_attr( (string) $c['why'] ),
							esc_attr( (string) $c['label'] )
						);
					}
					if ( '' !== $aperto ) {
						echo '</optgroup>';
					}
					?>
				</select>
				<?php // 14/10/2026 (Federico: «one menu, the browser in it as the default, free on the left in green, the limits and the quality»): drawn by the script from the menu above. ?>
				<span class="trr-epick" id="trr-epick" hidden>
					<button type="button" class="trr-epick-btn" id="trr-epick-btn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="trr-engine-label-txt trr-epick-btn"></button>
					<span class="trr-epick-list" id="trr-epick-list" role="listbox" tabindex="-1" hidden aria-labelledby="trr-engine-label-txt"></span>
				</span>
				<button type="submit" class="button button-primary" id="trr-engine-go" <?php disabled( 0 === $miss_lingua ); ?>>
					&#10024;
					<?php
					if ( $miss_lingua > 0 ) {
						printf(
							/* translators: %d: number of strings still missing. */
							esc_html__( 'Translate all %d missing', 'translate-rocket' ),
							(int) $miss_lingua
						);
					} else {
						esc_html_e( 'Everything is translated', 'translate-rocket' );
					}
					?>
				</button>
				<?php if ( $avvisi ) : ?>
					<span class="trr-engine-ack" id="trr-engine-ack" hidden>
						<span class="trr-engine-ack-lines"></span>
						<label><input type="checkbox" name="engine_ack" value="1" /> <?php esc_html_e( 'I have read this and accept it.', 'translate-rocket' ); ?></label>
					</span>
				<?php endif; ?>
				<span class="trr-engine-info" id="trr-engine-info" role="note" hidden
					data-l-quality="<?php esc_attr_e( 'Quality', 'translate-rocket' ); ?>"
					data-l-limit="<?php esc_attr_e( 'Limit', 'translate-rocket' ); ?>"
					data-l-needs="<?php esc_attr_e( 'Needs', 'translate-rocket' ); ?>"
					data-l-where="<?php esc_attr_e( 'Runs', 'translate-rocket' ); ?>"
					data-l-goes="<?php esc_attr_e( 'Your text goes to', 'translate-rocket' ); ?>"
					data-t-free="<?php esc_attr_e( 'FREE', 'translate-rocket' ); ?>"
					data-t-limit="<?php esc_attr_e( 'FREE · daily limit', 'translate-rocket' ); ?>"
					data-t-paid="<?php esc_attr_e( 'Your paid key', 'translate-rocket' ); ?>"></span>
				<span class="trr-engine-labsnote" id="trr-engine-labsnote" role="note" hidden></span>
				<?php
				// 13/10/2026 (Federico: «50,000 with an e-mail address… and how is the e-mail entered?»): the box
				// is here, where MyMemory is chosen, not only on Labs' own page.
				if ( is_callable( array( '\TranslateRocketLabs\Labs', 'settings' ) ) ) :
					$trr_mm = (string) ( \TranslateRocketLabs\Labs::settings()['mymemory_email'] ?? '' );
					?>
					<span class="trr-engine-labsnote trr-engine-mmbox" id="trr-engine-mm" hidden>
						<strong><?php esc_html_e( 'MyMemory: raise the free limit', 'translate-rocket' ); ?></strong><br />
						<?php esc_html_e( 'Without an e-mail address MyMemory translates about 5,000 characters a day from your server; with one, about 50,000. Write an address you read (yours or the site\'s): it goes to MyMemory (Translated) with each request, so they can reach you about your use. Nothing else is needed: no account, no key. Press Save; you can also change it on Labs → Automatic translation.', 'translate-rocket' ); ?>
						<span class="trr-engine-mm">
							<label for="trr-engine-mm-email" class="screen-reader-text"><?php esc_html_e( 'E-mail for MyMemory', 'translate-rocket' ); ?></label>
							<input type="email" id="trr-engine-mm-email" name="engine_mm_email" value="<?php echo esc_attr( $trr_mm ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" autocomplete="email" />
							<button type="button" class="button button-small" id="trr-engine-mm-site" data-email="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"><?php esc_html_e( 'Use the site\'s e-mail', 'translate-rocket' ); ?></button>
							<button type="button" class="button button-primary button-small" id="trr-engine-mm-save"><?php esc_html_e( 'Save', 'translate-rocket' ); ?></button>
							<span class="description" id="trr-engine-mm-state" role="status"
								data-on="<?php esc_attr_e( 'Saved: the higher limit is on.', 'translate-rocket' ); ?>"
								data-off="<?php esc_attr_e( 'Empty: the lower limit.', 'translate-rocket' ); ?>"
								data-bad="<?php esc_attr_e( 'This is not a valid e-mail address.', 'translate-rocket' ); ?>"
								data-err="<?php esc_attr_e( 'Not saved: reload the page and try again.', 'translate-rocket' ); ?>"><?php echo '' !== $trr_mm ? esc_html__( 'Saved: the higher limit is on.', 'translate-rocket' ) : esc_html__( 'Empty: the lower limit.', 'translate-rocket' ); ?></span>
						</span>
					</span>
				<?php endif; ?>
				<span class="description" id="trr-engine-nobrowser" hidden><?php esc_html_e( 'This browser cannot translate by itself: choose another engine.', 'translate-rocket' ); ?></span>
			</form>
			<?php
			$trr_step = array(
				/* translators: 1: sentences translated so far, 2: sentences still missing. Keep %1$s and %2$s. */
				'busy'     => __( 'Translating… %1$s done, %2$s still missing. Keep this page open.', 'translate-rocket' ),
				/* translators: %s: sentences translated so far. */
				'busyPage' => __( 'Translating… %s done. Keep this page open.', 'translate-rocket' ),
				/* translators: %s: the technical reason, e.g. HTTP 524. */
				'lost'     => __( 'The connection to your site dropped (%s). What was translated is saved: press the button again to continue.', 'translate-rocket' ),
			);
			wp_add_inline_script( 'trrocket-admin-info', 'window.TRRocketEngineAck=' . wp_json_encode( (object) $avvisi ) . ';window.TRRocketEngineNote=' . wp_json_encode( (object) $per_note ) . ';window.TRRocketStep=' . wp_json_encode( $trr_step ) . ';' . self::engine_js() );
			?>
			<?php if ( null === $fornitore && ! $riga_ok ) : // 11/10/2026: not when a Labs engine works without a key ?>
				<span class="description">
					<?php
					printf(
						/* translators: %s: link to the AI settings page. */
						wp_kses( __( 'Needs an API key — set one up in %s, or use your browser below (free).', 'translate-rocket' ), array( 'a' => array( 'href' => array() ) ) ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=translate-rocket-ai' ) ) . '">' . esc_html__( 'AI Translation', 'translate-rocket' ) . '</a>'
					);
					?>
				</span>
			<?php endif; ?>

			<span id="trr-scan-label" class="description"></span>
		</div>
		<div id="trr-scan-prog" class="trr-prog" style="display:none;max-width:360px;margin:0 0 12px"><div class="trr-prog-bar"><div class="trr-prog-fill" id="trr-scan-fill" style="width:0;background:#4f46e5"></div></div></div>
		<?php
		// Si mostra da solo se il browser sa davvero farlo: la risposta esiste
		// solo a video, non qui.
		\TranslateRocket\Admin\BrowserEngine::render_panel(
			$lang,
			\TranslateRocket\Plugin::instance()->router()->default_language(),
			$miss_lingua,
			null === $fornitore
		);
		?>
		<?php

		if ( empty( $pages ) ) {
			echo '<p>' . esc_html__( 'No pages detected yet. Click “Scan site for text” above, or just browse your site as administrator.', 'translate-rocket' ) . '</p>';
			return;
		}
		$count = count( $pages );
		?>
		<div class="trr-ptoolbar">
			<input type="search" id="trr-psearch" class="regular-text" autocomplete="off"
				placeholder="<?php esc_attr_e( 'Search pages by name or slug… (spaces count as hyphens)', 'translate-rocket' ); ?>" />
			<label class="trr-ptodo"><input type="checkbox" id="trr-ptodo" /> <?php esc_html_e( 'Only pages still to translate', 'translate-rocket' ); ?></label>
			<span class="description">
				<?php
				printf(
					/* translators: 1: number of visible pages, 2: total pages. */
					esc_html__( 'Showing %1$s of %2$s pages', 'translate-rocket' ),
					'<strong id="trr-pcount">' . (int) min( $count, self::PAGES_BLOCK ) . '</strong>',
					'<strong id="trr-ptotal">' . (int) $count . '</strong>'
				);
				?>
			</span>
		</div>
		<form id="trr-ai-onepage" method="post" action="" style="display:none">
			<?php wp_nonce_field( 'trrocket_auto_translate', 'trrocket_auto_nonce' ); ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
		</form>
		<?php // «Translate again» e «Remove from the list» per ogni riga: due moduli per tutta la tabella, il pulsante porta il «loc». ?>
		<form id="trr-reset-onepage" method="post" action="" style="display:none">
			<?php wp_nonce_field( 'trrocket_delete_page', 'trrocket_delete_nonce' ); ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
		</form>
		<form id="trr-forget-onepage" method="post" action="" style="display:none">
			<?php wp_nonce_field( 'trrocket_forget_page', 'trrocket_forget_nonce' ); ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
		</form>
		<table class="widefat striped" id="trr-ptable" data-lang="<?php echo esc_attr( $lang ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'trrocket_pages_rows' ) ); ?>" data-total="<?php echo (int) $count; ?>" data-block="<?php echo (int) self::PAGES_BLOCK; ?>">
			<thead>
				<tr>
					<th class="trr-sortable" data-key="title" data-type="text"><?php esc_html_e( 'Page', 'translate-rocket' ); ?><span class="trr-arrow"></span></th>
					<th class="trr-sortable" data-key="strings" data-type="num"><?php esc_html_e( 'Strings', 'translate-rocket' ); ?><span class="trr-arrow"></span></th>
					<th class="trr-sortable" data-key="translated" data-type="num"><?php esc_html_e( 'Translated', 'translate-rocket' ); ?><span class="trr-arrow"></span> <?php $this->info( __( 'Under each page, small labels say who translated it in this language and how many sentences each: an AI provider, a free engine of Labs, this browser, the visual editor, by hand, an import, or a copy of a sentence already translated elsewhere (reuse). A page translated in several ways shows several labels, the largest first. Hover a label for the full name.', 'translate-rocket' ) ); ?></th>
					<th class="trr-sortable" data-key="missing" data-type="num"><?php esc_html_e( 'Missing', 'translate-rocket' ); ?><span class="trr-arrow"></span></th>
					<th class="trr-sortable" data-key="pct" data-type="num"><?php esc_html_e( 'Progress', 'translate-rocket' ); ?><span class="trr-arrow"></span></th>
					<th><?php esc_html_e( 'Actions', 'translate-rocket' ); ?> <?php $this->info( __( 'Translate: open the page and translate it sentence by sentence. AI: translate what is missing on this page with your AI provider. Start over: remove this page’s translations and translated address in this language to translate it again from scratch — sentences also used on other pages keep their translation, and an Undo link puts everything back. Hide: take the page off this list (for old or deleted pages); nothing is deleted, and the page comes back if it is visited again.', 'translate-rocket' ) ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				// 14/10/2026 (Federico: «2,051 pages make the screen very heavy»): the first block only; search, filter,
				// sort and «Show more» ask the server for the next ones (ajax_pages_rows).
				foreach ( array_slice( $pages, 0, self::PAGES_BLOCK ) as $page ) {
					echo $this->page_row( $page, $lang, $per_motore, $riga_ok ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* in page_row().
				}
				?>
			</tbody>
		</table>
		<p class="trr-pmore-wrap"<?php echo $count > self::PAGES_BLOCK ? '' : ' hidden'; ?>><button type="button" class="button" id="trr-pmore"><?php echo esc_html( sprintf( /* translators: %d: how many more pages are shown. */ __( 'Show %d more', 'translate-rocket' ), self::PAGES_BLOCK ) ); ?></button> <span class="spinner" id="trr-pspin"></span></p>

		<?php
		wp_add_inline_script( 'trrocket-admin-info', self::pages_list_js() );
	}


	/** Pages drawn at a time on the Translations screen. */
	const PAGES_BLOCK = 50;

	/**
	 * One row of the pages table (also drawn for «Show more», search and sort, through ajax_pages_rows()).
	 *
	 * @param object $page       Row of Strings::pages_with_stats().
	 * @param string $lang       Language.
	 * @param array  $per_motore Strings::engines_by_page().
	 * @param bool   $riga_ok    An engine works, so the per-page «AI» button is there.
	 */
	private function page_row( $page, string $lang, array $per_motore, bool $riga_ok ): string {
		ob_start();
		?>
				<?php
					$total      = (int) $page->total;
					$translated = (int) $page->translated;
					$missing    = max( 0, $total - $translated );
					$pct        = $total > 0 ? (int) round( $translated / $total * 100 ) : 100;
					$label      = \TranslateRocket\Strings::heading_name( (string) $page->url, (string) $page->title );
					?>
					<tr
						data-title="<?php echo esc_attr( self::lower( $label ) ); ?>"
						data-strings="<?php echo (int) $total; ?>"
						data-translated="<?php echo (int) $translated; ?>"
						data-missing="<?php echo (int) $missing; ?>"
						data-pct="<?php echo (int) $pct; ?>"
						data-search="<?php echo esc_attr( self::lower( $label . ' ' . $page->url ) ); ?>">
						<td>
							<strong><?php echo esc_html( $label ); ?></strong><br>
							<span class="description"><?php echo esc_html( $page->url ); ?></span>
							<?php echo wp_kses_post( self::engine_tags( $per_motore[ (string) $page->url_hash ] ?? array() ) ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Strings', 'translate-rocket' ); ?>"><?php echo (int) $total; ?></td>
						<td data-label="<?php esc_attr_e( 'Translated', 'translate-rocket' ); ?>"><?php echo (int) $translated; ?></td>
						<td data-label="<?php esc_attr_e( 'Missing', 'translate-rocket' ); ?>"><strong><?php echo (int) $missing; ?></strong></td>
						<td class="trr-pcell"><?php echo wp_kses( $this->progress_bar( $translated, $total ) , \TranslateRocket\Kses::html_rules() ); ?></td>
						<td class="trr-rowactions">
							<a class="button button-small<?php echo $missing > 0 ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $this->editor_url( $lang, $page->url_hash ) ); ?>"><?php esc_html_e( 'Translate', 'translate-rocket' ); ?></a>
							<?php if ( $riga_ok && $missing > 0 ) : ?>
								<?php // Il valore del pulsante e' il "loc" che finisce nel POST: un solo modulo per tutta la tabella invece di uno per riga. ?>
								<button type="submit" form="trr-ai-onepage" class="button button-small" name="loc"
									value="<?php echo esc_attr( $page->url_hash ); ?>"
									title="<?php esc_attr_e( 'Translate only this page with AI', 'translate-rocket' ); ?>">&#10024; <?php esc_html_e( 'AI', 'translate-rocket' ); ?></button>
							<?php endif; ?>
							<?php // 5/10/2026: «Start over» e «Hide» tolgono qualcosa: stanno dietro «⋯», non accanto a «Translate». ?>
							<details class="trr-more">
								<summary class="button button-small" title="<?php esc_attr_e( 'More actions', 'translate-rocket' ); ?>" aria-label="<?php esc_attr_e( 'More actions', 'translate-rocket' ); ?>">&#8943;</summary>
								<div class="trr-more-menu">
							<?php if ( $translated > 0 ) : ?>
								<button type="submit" form="trr-reset-onepage" class="button button-small" name="loc"
									value="<?php echo esc_attr( $page->url_hash ); ?>"
									onclick="return confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Translate this page again? Its translations and translated address for this language are removed, so it can be translated from scratch. You can undo this.', 'translate-rocket' ) ) ); ?>);"
									title="<?php esc_attr_e( 'Start over: remove the translations and the translated address of this page, to translate it again from scratch (you can undo this)', 'translate-rocket' ); ?>">&#8635; <?php esc_html_e( 'Start over', 'translate-rocket' ); ?></button>
							<?php endif; ?>
							<button type="submit" form="trr-forget-onepage" class="button button-small" name="loc"
								value="<?php echo esc_attr( $page->url_hash ); ?>"
								onclick="return confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Remove this page from the list? Its translations stay in the database; the page comes back the next time it is read.', 'translate-rocket' ) ) ); ?>);"
								title="<?php esc_attr_e( 'Hide this page from the list: nothing is deleted, and it comes back if the page is visited again', 'translate-rocket' ); ?>"><span class="dashicons dashicons-hidden" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span> <?php esc_html_e( 'Hide', 'translate-rocket' ); ?></button>
								</div>
							</details>
						</td>
					</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The pages that match the search and the filter, in the order asked.
	 *
	 * @param object[] $pages Strings::pages_with_stats().
	 * @param string   $q     Search (name or address; spaces count as hyphens).
	 * @param bool     $todo  Only the pages with something missing.
	 * @param string   $key   title|strings|translated|missing|pct, or '' for the list's own order.
	 * @param string   $dir   asc|desc.
	 * @return object[]
	 */
	private static function pages_filtered( array $pages, string $q, bool $todo, string $key, string $dir ): array {
		$q   = str_replace( ' ', '-', self::lower( trim( $q ) ) );
		$out = array();
		foreach ( $pages as $page ) {
			$total   = (int) $page->total;
			$missing = max( 0, $total - (int) $page->translated );
			if ( $todo && $missing < 1 ) {
				continue;
			}
			if ( '' !== $q ) {
				$label = \TranslateRocket\Strings::heading_name( (string) $page->url, (string) $page->title );
				if ( false === strpos( str_replace( ' ', '-', self::lower( $label . ' ' . $page->url ) ), $q ) ) {
					continue;
				}
			}
			$out[] = $page;
		}
		if ( in_array( $key, array( 'title', 'strings', 'translated', 'missing', 'pct' ), true ) ) {
			$val = static function ( $p ) use ( $key ) {
				$t = (int) $p->total;
				$d = (int) $p->translated;
				switch ( $key ) {
					case 'title':
						return self::lower( \TranslateRocket\Strings::heading_name( (string) $p->url, (string) $p->title ) );
					case 'strings':
						return $t;
					case 'translated':
						return $d;
					case 'missing':
						return max( 0, $t - $d );
					default:
						return $t > 0 ? (int) round( $d / $t * 100 ) : 100;
				}
			};
			usort(
				$out,
				static function ( $a, $b ) use ( $val, $dir ) {
					$r = $val( $a ) <=> $val( $b );
					return 'desc' === $dir ? -$r : $r;
				}
			);
		}
		return $out;
	}

	/**
	 * AJAX: the next block of the pages table — for «Show more», the search box, the filter and the sort.
	 */
	public function ajax_pages_rows(): void {
		check_ajax_referer( 'trrocket_pages_rows' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$lang = isset( $_POST['lang'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['lang'] ) ) ) : '';
		if ( ! in_array( $lang, (array) Settings::get()['target_languages'], true ) ) {
			wp_send_json_error( array( 'error' => 'bad-language' ) );
		}
		$q      = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$todo   = ! empty( $_POST['todo'] );
		$key    = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$dir    = ( isset( $_POST['dir'] ) && 'desc' === $_POST['dir'] ) ? 'desc' : 'asc';
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		// phpcs:enable
		$pages   = self::pages_filtered( Strings::pages_with_stats( $lang ), $q, $todo, $key, $dir );
		$scelte  = self::engine_choices();
		$riga_ok = (bool) array_filter( $scelte, static function ( $c ) { return $c['ok']; } );
		$html    = '';
		$per     = Strings::engines_by_page( $lang );
		foreach ( array_slice( $pages, $offset, self::PAGES_BLOCK ) as $page ) {
			$html .= $this->page_row( $page, $lang, $per, $riga_ok );
		}
		wp_send_json_success(
			array(
				'html'  => $html,
				'total' => count( $pages ),
				'next'  => min( count( $pages ), $offset + self::PAGES_BLOCK ),
			)
		);
	}

	/**
	 * Behaviour for the pages list (client-side search + column sort). Returned as
	 * a string for wp_add_inline_script().
	 */
	private static function pages_list_js(): string {
		return <<<'JS'
( function () {
	var input   = document.getElementById( 'trr-psearch' );
	var table   = document.getElementById( 'trr-ptable' );
	if ( ! input || ! table ) { return; }
	var tbody   = table.tBodies[0];
	var counter = document.getElementById( 'trr-pcount' );
	var totale  = document.getElementById( 'trr-ptotal' );
	var todo    = document.getElementById( 'trr-ptodo' );
	var more    = document.getElementById( 'trr-pmore' );
	var spin    = document.getElementById( 'trr-pspin' );
	var block   = parseInt( table.getAttribute( 'data-block' ), 10 ) || 50;
	var stato   = { key: '', dir: 'asc', next: Math.min( block, parseInt( table.getAttribute( 'data-total' ), 10 ) || 0 ), seq: 0 };

	// 14/10/2026 (Federico: «with every page loaded the screen gets very heavy»): the server sends 50 rows at a
	// time; the search box, «Only pages still to translate» and the column sort ask it again from the start.
	function carica( daCapo ) {
		var mio = ++stato.seq;
		var body = new URLSearchParams();
		body.append( 'action', 'trrocket_pages_rows' );
		body.append( '_ajax_nonce', table.getAttribute( 'data-nonce' ) );
		body.append( 'lang', table.getAttribute( 'data-lang' ) );
		body.append( 'q', input.value.trim() );
		body.append( 'todo', todo && todo.checked ? '1' : '' );
		body.append( 'key', stato.key );
		body.append( 'dir', stato.dir );
		body.append( 'offset', daCapo ? '0' : String( stato.next ) );
		if ( spin ) { spin.classList.add( 'is-active' ); }
		fetch( table.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( mio !== stato.seq || ! j || ! j.success ) { return; }
				if ( daCapo ) { tbody.innerHTML = ''; }
				tbody.insertAdjacentHTML( 'beforeend', j.data.html );
				stato.next = j.data.next;
				if ( counter ) { counter.textContent = tbody.rows.length; }
				if ( totale ) { totale.textContent = j.data.total; }
				if ( more ) { more.parentNode.hidden = stato.next >= j.data.total; }
			} )
			.catch( function () {} )
			.then( function () { if ( spin && mio === stato.seq ) { spin.classList.remove( 'is-active' ); } } );
	}
	var attesa;
	input.addEventListener( 'input', function () { clearTimeout( attesa ); attesa = setTimeout( function () { carica( true ); }, 300 ); } );
	input.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); clearTimeout( attesa ); carica( true ); } } );
	if ( todo ) { todo.addEventListener( 'change', function () { carica( true ); } ); }
	if ( more ) { more.addEventListener( 'click', function () { carica( false ); } ); }
	document.addEventListener( 'click', function ( ev ) {
		Array.prototype.forEach.call( table.querySelectorAll( 'details.trr-more[open]' ), function ( d ) {
			if ( ! d.contains( ev.target ) ) { d.removeAttribute( 'open' ); }
		} );
	} );

	var ths = table.querySelectorAll( 'th.trr-sortable' );
	Array.prototype.forEach.call( ths, function ( th ) {
		var a0 = th.querySelector( '.trr-arrow' );
		if ( a0 ) { a0.textContent = ' ↕'; }
		th.addEventListener( 'click', function ( ev ) {
			if ( ev.target.closest && ev.target.closest( '.trr-info, .trr-tip' ) ) { return; } // its ⓘ opens, it does not sort
			var dir = 'asc' === th.getAttribute( 'data-dir' ) ? 'desc' : 'asc';
			Array.prototype.forEach.call( ths, function ( o ) {
				o.removeAttribute( 'data-dir' );
				var a = o.querySelector( '.trr-arrow' );
				if ( a ) { a.textContent = ' ↕'; a.classList.remove( 'is-active' ); }
			} );
			th.setAttribute( 'data-dir', dir );
			var arrow = th.querySelector( '.trr-arrow' );
			if ( arrow ) { arrow.textContent = 'asc' === dir ? ' ▲' : ' ▼'; arrow.classList.add( 'is-active' ); }
			stato.key = th.getAttribute( 'data-key' );
			stato.dir = dir;
			carica( true );
		} );
	} );
}() );
JS;
	}

	/**
	 * Editor for a single page: copy-paste tool + manual table.
	 */
	private function render_page_editor( string $lang, string $loc ): void {
		$info = Strings::page_info( $loc );
		$back = $this->editor_url( $lang );

		printf(
			'<p style="margin:6px 0 14px"><a class="button button-small trr-back" href="%s"><span class="dashicons dashicons-arrow-left-alt2"></span> %s</a></p>',
			esc_url( $back ),
			esc_html__( 'Back to all pages', 'translate-rocket' )
		);

		if ( $info ) {
			echo '<h2>' . esc_html( \TranslateRocket\Strings::heading_name( (string) $info->url, (string) $info->title ) ) . '</h2>';
			echo '<p class="description">' . esc_html( $info->url ) . '</p>';
		}

		$post_id = ( $info && $info->url ) ? (int) url_to_postid( home_url( $info->url ) ) : 0;

		// If this page is set to anything other than "Translate" for this
		// language, hide the whole editor — there is nothing to translate here.
		if ( $post_id > 0 && \TranslateRocket\Exclusions::is_excluded( $post_id, $lang ) ) {
			$rule   = \TranslateRocket\Exclusions::get( $post_id, $lang );
			$labels = array(
				'home'    => __( 'redirect to the homepage', 'translate-rocket' ),
				'url'     => __( 'redirect to a custom URL', 'translate-rocket' ),
				'message' => __( 'show a custom message', 'translate-rocket' ),
				'404'     => __( 'show a 404 (not found)', 'translate-rocket' ),
			);
			$what = isset( $labels[ $rule['mode'] ] ) ? $labels[ $rule['mode'] ] : $rule['mode'];
			$edit = get_edit_post_link( $post_id );
			echo '<div class="notice notice-info inline" style="margin:12px 0"><p>';
			printf(
				/* translators: 1: language name, 2: chosen behaviour. */
				esc_html__( 'This page is set to %2$s in %1$s, so it is not translated and the editor is hidden.', 'translate-rocket' ),
				'<strong>' . esc_html( Languages::label( $lang ) ) . '</strong>',
				'<strong>' . esc_html( $what ) . '</strong>'
			);
			if ( $edit ) {
				printf(
					' <a href="%s">%s</a>',
					esc_url( $edit ),
					esc_html__( 'Change it in the page’s “Language visibility” box.', 'translate-rocket' )
				);
			}
			echo '</p></div>';
			return;
		}

		$this->render_ai_button( $lang, $loc );

		$untranslated = Strings::untranslated_for_page_language( $loc, $lang );
		$all_rows     = Strings::for_page_language( $loc, $lang );

		// Copy-paste defaults to the untranslated strings, but always lets you
		// redo everything (cp=all, or automatically when nothing is missing).
		// phpcs:ignore WordPress.Security.NonceVerification
		$show_all = ( isset( $_GET['cp'] ) && 'all' === $_GET['cp'] ) || empty( $untranslated );
		$cp_rows  = $show_all ? $all_rows : $untranslated;
		// The browser's own translator for this page too: no key needed (6/10/2026, Federico).
		\TranslateRocket\Admin\BrowserEngine::render_panel(
			$lang,
			\TranslateRocket\Plugin::instance()->router()->default_language(),
			count( $untranslated ),
			null === Registry::active(),
			$loc
		);
		$this->render_copy_paste( $lang, $cp_rows, $loc, $show_all ? 'all' : 'missing', count( $untranslated ) );

		$this->render_string_table( $lang, $all_rows, $loc, $post_id );
		?>
		<form method="post" action="" style="margin-top:18px"
			onsubmit="return confirm('<?php echo esc_js( __( 'Translate this page again? Its translations and translated address for this language are removed, so it can be translated from scratch. You can undo this.', 'translate-rocket' ) ); ?>');">
			<?php wp_nonce_field( 'trrocket_delete_page', 'trrocket_delete_nonce' ); ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
			<input type="hidden" name="loc" value="<?php echo esc_attr( $loc ); ?>" />
			<button type="submit" class="button button-link-delete" style="color:#b32d2e">
				&#8635; <?php esc_html_e( "Translate again: remove this page's translations and translated address (undo available)", 'translate-rocket' ); ?>
			</button>
		</form>
		<form method="post" action="" style="margin-top:6px"
			onsubmit="return confirm('<?php echo esc_js( __( 'Remove this page from the list? Useful for stale or 404 pages — its translations stay in the database.', 'translate-rocket' ) ); ?>');">
			<?php wp_nonce_field( 'trrocket_forget_page', 'trrocket_forget_nonce' ); ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
			<input type="hidden" name="loc" value="<?php echo esc_attr( $loc ); ?>" />
			<button type="submit" class="button">
				&#10006; <?php esc_html_e( 'Remove this page from the list', 'translate-rocket' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Copy-paste (Google Translate) tool for a set of untranslated strings.
	 *
	 * @param string            $lang         Target language.
	 * @param array<int,object> $untranslated Rows with string_id + original.
	 * @param string            $loc          Current page hash (to return to).
	 */
	private function render_copy_paste( string $lang, array $rows, string $loc = '', string $mode = 'missing', int $missing = 0 ): void {
		$ids    = array();
		$export = '';
		$i      = 1;
		foreach ( $rows as $row ) {
			$ids[]   = (int) $row->string_id;
			$export .= $i . '. ' . preg_replace( '/\s*[\r\n]+\s*/', ' ', (string) $row->original ) . "\n"; // one line per item
			++$i;
		}

		$base = $this->editor_url( $lang, $loc );
		?>
		<div class="trrocket-card">
			<h2>✂️ <?php esc_html_e( 'Copy &amp; paste with Google Translate (free)', 'translate-rocket' ); ?></h2>

			<?php if ( empty( $ids ) ) : ?>
				<p><?php esc_html_e( 'No strings on this page yet. Browse it on the front end (as admin) first.', 'translate-rocket' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( '1) Copy the list. 2) Paste it into Google Translate. 3) Paste the result back below — keep the numbers, one item per line.', 'translate-rocket' ); ?>
				</p>
				<?php $src_lang = (string) ( Settings::get()['source_language'] ?? 'en' ); ?>
				<p>
					<a class="button button-secondary trr-gopen" href="<?php echo esc_url( \TranslateRocket\GoogleFree::link( '', $src_lang, $lang ) ); ?>" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-translation"></span> <?php esc_html_e( 'Open Google Translate', 'translate-rocket' ); ?>
					</a>
				</p>

				<?php if ( 'all' === $mode ) : ?>
					<p class="description"><em>
						<?php
						if ( 0 === $missing ) {
							esc_html_e( '✅ Everything here is translated — paste a new list below to redo it.', 'translate-rocket' );
						} else {
							esc_html_e( 'Showing ALL strings — importing will overwrite existing translations.', 'translate-rocket' );
						}
						?>
						<?php if ( $missing > 0 ) : ?>
							&nbsp;<a href="<?php echo esc_url( $base ); ?>"><?php
								/* translators: %d: number of untranslated strings. */
								printf( esc_html__( 'Show only the %d untranslated', 'translate-rocket' ), (int) $missing );
							?></a>
						<?php endif; ?>
					</em></p>
				<?php else : ?>
					<p class="description">
						<a href="<?php echo esc_url( add_query_arg( 'cp', 'all', $base ) ); ?>">↻ <?php esc_html_e( 'Re-translate everything (include already translated)', 'translate-rocket' ); ?></a>
					</p>
				<?php endif; ?>

				<p><strong><?php esc_html_e( 'Strings to copy:', 'translate-rocket' ); ?></strong></p>
				<textarea class="large-text code" rows="8" readonly onclick="this.select()"><?php echo esc_textarea( $export ); ?></textarea>
				<form method="post" action="">
					<?php wp_nonce_field( 'trrocket_import_translations', 'trrocket_import_nonce' ); ?>
					<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
					<input type="hidden" name="loc" value="<?php echo esc_attr( $loc ); ?>" />
					<input type="hidden" name="cp_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
					<p style="margin-top:12px"><strong><?php esc_html_e( 'Paste the translated list here:', 'translate-rocket' ); ?></strong></p>
					<textarea class="large-text code" rows="8" name="cp_import" placeholder="1. ...&#10;2. ..."></textarea>
					<?php submit_button( __( 'Import pasted translations', 'translate-rocket' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Manual translation table for a set of strings.
	 *
	 * @param string            $lang Target language.
	 * @param array<int,object> $rows Strings joined with their translation.
	 * @param string            $loc  Current page hash (to return to).
	 */
	private function render_string_table( string $lang, array $rows, string $loc = '', int $post_id = 0 ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No strings detected for this page yet.', 'translate-rocket' ) . '</p>';
			return;
		}

		$seo  = array();
		$body = array();
		foreach ( $rows as $row ) {
			if ( 'meta' === $row->type ) {
				$seo[] = $row;
			} else {
				$body[] = $row;
			}
		}
		$types = array();
		foreach ( $body as $row ) {
			$types[ $row->type . ( $row->context ? '/' . $row->context : '' ) ] = true;
		}
		?>
		<h2><?php esc_html_e( 'Manual editor', 'translate-rocket' ); ?></h2>

		<p>
			<label><?php esc_html_e( 'Filter by type:', 'translate-rocket' ); ?> <?php $this->info( __( 'Narrow the list to one kind of string — visible text, link titles, image alt text, ARIA labels, etc.', 'translate-rocket' ) ); ?>
				<select id="trr-efilter">
					<option value=""><?php esc_html_e( 'All types', 'translate-rocket' ); ?></option>
					<?php foreach ( array_keys( $types ) as $key ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $key ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			&nbsp;
			<label><input type="checkbox" id="trr-eonlymiss" /> <?php esc_html_e( 'Show only missing strings', 'translate-rocket' ); ?></label> <?php $this->info( __( 'Hide strings that already have a translation, so you can focus on what is left.', 'translate-rocket' ) ); ?>
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'trrocket_save_translations', 'trrocket_translations_nonce' ); ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
			<input type="hidden" name="loc" value="<?php echo esc_attr( $loc ); ?>" />
			<?php
			if ( $post_id > 0 ) {
				echo '<input type="hidden" name="post_id" value="' . (int) $post_id . '" />';
			}
			$this->render_seo_section( $lang, $seo, $post_id );
			$this->render_block( __( 'Page content', 'translate-rocket' ), $body, $lang );
			?>
			<div class="trr-esavebar">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save translations', 'translate-rocket' ); ?></button>
			</div>
		</form>

		<?php
		$gt_data = 'window.TRRocketGT=' . wp_json_encode(
			array(
				'nonce' => wp_create_nonce( 'trrocket_gt' ),
				'err'   => __( 'Google Translate is unavailable right now.', 'translate-rocket' ),
			)
		) . ';';
		wp_add_inline_script( 'trrocket-admin-info', $gt_data . self::page_editor_js() );
	}

	/**
	 * Behaviour for the per-page manual editor (type filter, auto-grow textareas,
	 * tag restore, inline Google-translate). Config from window.TRRocketGT.
	 * Returned as a string for wp_add_inline_script().
	 */
	private static function page_editor_js(): string {
		return <<<'JS'
( function () {
	var f = document.getElementById( 'trr-efilter' );
	var m = document.getElementById( 'trr-eonlymiss' );
	function apply() {
		var v    = f ? f.value : '';
		var only = m ? m.checked : false;
		Array.prototype.forEach.call( document.querySelectorAll( '.trr-eblockwrap' ), function ( wrap ) {
			var any = false;
			Array.prototype.forEach.call( wrap.querySelectorAll( 'tr.trr-erow' ), function ( tr ) {
				var t    = tr.getAttribute( 'data-type' );
				var show = ( ! v || t === v ) && ( ! only || tr.className.indexOf( 'trr-emissing' ) !== -1 );
				tr.style.display = show ? '' : 'none';
				if ( show ) { any = true; }
			} );
			wrap.style.display = any ? '' : 'none';
		} );
	}
	if ( f ) { f.addEventListener( 'change', apply ); }
	if ( m ) { m.addEventListener( 'change', apply ); }

	// Turn the numbered markers [1],[2]… back into the original HTML tags when
	// a Google-translated phrase is pasted in (or just before saving).
	function restoreTags( translated, source ) {
		var tags = String( source || '' ).match( /<[^>]+>/g );
		if ( ! tags || ! tags.length ) { return translated; }
		return String( translated || '' ).replace( /[\[\{【]\s*(\d+)\s*[\]\}】]/g, function ( mm, n ) {
			var idx = parseInt( n, 10 ) - 1;
			return ( idx >= 0 && idx < tags.length ) ? tags[ idx ] : mm;
		} );
	}
	// Grow each translation textarea to fit its content.
	function autoGrow( el ) {
		if ( ! el || 'TEXTAREA' !== el.tagName ) { return; }
		el.style.height = 'auto';
		el.style.height = ( el.scrollHeight + 2 ) + 'px';
	}
	Array.prototype.forEach.call( document.querySelectorAll( 'textarea.trr-etr' ), autoGrow );

	document.addEventListener( 'paste', function ( e ) {
		var inp = e.target;
		if ( ! inp || ! inp.classList || ! inp.classList.contains( 'trr-etr' ) ) { return; }
		var src = inp.getAttribute( 'data-src' ) || '';
		if ( ! /<[^>]+>/.test( src ) ) { return; }
		setTimeout( function () { inp.value = restoreTags( inp.value, src ); autoGrow( inp ); }, 0 );
	} );
	document.addEventListener( 'input', function ( e ) {
		if ( e.target && e.target.classList && e.target.classList.contains( 'trr-etr' ) ) { autoGrow( e.target ); }
	} );
	document.addEventListener( 'submit', function ( e ) {
		if ( ! e.target || ! e.target.querySelectorAll ) { return; }
		Array.prototype.forEach.call( e.target.querySelectorAll( '.trr-etr' ), function ( inp ) {
			inp.value = restoreTags( inp.value, inp.getAttribute( 'data-src' ) || '' );
		} );
	}, true );

	// Google icon -> fill this row's field inline (free, server-side, same page).
	var GT = window.TRRocketGT || {};
	function protectTags( text ) {
		var i = 0;
		return String( text == null ? '' : text ).replace( /<[^>]+>/g, function () { i++; return '[' + i + ']'; } );
	}
	var langInput = document.querySelector( 'input[name="lang"]' );
	document.addEventListener( 'click', function ( e ) {
		var gt = e.target.closest ? e.target.closest( 'button.trr-gt' ) : null;
		if ( ! gt ) { return; }
		e.preventDefault();
		var row = gt.closest( 'tr' );
		var ta  = row ? row.querySelector( '.trr-etr' ) : null;
		var src = gt.getAttribute( 'data-src' ) || '';
		if ( ! ta || ! src ) { return; }
		gt.disabled = true;
		gt.classList.add( 'trr-gt-busy' );
		var fd = new FormData();
		fd.append( 'action', 'trrocket_gt' );
		fd.append( 'nonce', GT.nonce );
		fd.append( 'lang', langInput ? langInput.value : '' );
		fd.append( 'text', protectTags( src ) );
		fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				gt.disabled = false;
				gt.classList.remove( 'trr-gt-busy' );
				if ( r && r.success && r.data && r.data.translation ) {
					ta.value = restoreTags( r.data.translation, src );
					autoGrow( ta );
				} else {
					gt.title = ( r && r.data && r.data.error ) ? r.data.error : GT.err;
				}
			} )
			.catch( function () {
				gt.disabled = false;
				gt.classList.remove( 'trr-gt-busy' );
				gt.title = GT.err;
			} );
	} );
}() );
JS;
	}

	/**
	 * A per-phrase Google Translate button that fills the row's field inline with
	 * the free Google translation (no new tab). The target language is read from
	 * the editor's hidden field when clicked.
	 *
	 * @param string $original Source string.
	 * @param string $lang     Target language (unused here; kept for the call site).
	 */
	private function gt_link( string $original, string $lang ): string {
		if ( \TranslateRocket\GoogleFree::auto_enabled() ) {
			return '<button type="button" class="trr-gt" data-src="' . esc_attr( $original ) . '" title="'
				. esc_attr__( 'Translate this phrase with Google (free)', 'translate-rocket' )
				. '"><span class="dashicons dashicons-translation"></span></button>';
		}
		$src = (string) ( Settings::get()['source_language'] ?? 'en' );
		$url = \TranslateRocket\GoogleFree::link( $original, $src, $lang );
		return '<a class="trr-gt" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" title="'
			. esc_attr__( 'Open this phrase in Google Translate ↗', 'translate-rocket' )
			. '"><span class="dashicons dashicons-translation"></span></a>';
	}

	/**
	 * The narrow middle column holding the Google Translate icon (kept aligned).
	 */
	private function gt_cell( string $original, string $lang ): string {
		return '<td class="trr-gt-cell">' . $this->gt_link( $original, $lang ) . '</td>';
	}

	/**
	 * Render one titled block (table) of strings in the editor.
	 *
	 * @param string            $title Block title.
	 * @param array<int,object> $rows  Strings.
	 * @param string            $lang  Target language (for the Google Translate link).
	 */
	private function render_block( string $title, array $rows, string $lang = '' ): void {
		if ( empty( $rows ) ) {
			return;
		}
		// 6/10/2026: «the plugin changed my footer link into <1>ALP Web Design</1>» (a user, looking at this
		// screen). The marks were explained only in a tooltip: one visible line, where they appear.
		$con_segni = false;
		foreach ( $rows as $r ) {
			if ( \TranslateRocket\Frontend\InlineText::has_parts( (string) $r->original ) ) {
				$con_segni = true;
				break;
			}
		}
		?>
		<div class="trr-eblockwrap">
			<h3><?php echo esc_html( $title ); ?></h3>
			<?php if ( $con_segni ) : ?>
				<p class="description trr-ph-hint"><span class="trr-ph">&lt;1&gt;</span>…<span class="trr-ph">&lt;/1&gt;</span> <?php esc_html_e( 'marks a link or formatted words. On your site they stay as they are — same link, same address. Keep the marks, with their numbers, around the words that belong to them.', 'translate-rocket' ); ?></p>
			<?php endif; ?>
			<table class="widefat striped">
				<tbody>
					<?php
					foreach ( $rows as $row ) :
						$key     = $row->type . ( $row->context ? '/' . $row->context : '' );
						$missing = ! ( (int) $row->status > 0 && null !== $row->translation && '' !== $row->translation );
						?>
						<tr class="trr-erow<?php echo $missing ? ' trr-emissing' : ''; ?>" data-type="<?php echo esc_attr( $key ); ?>">
							<td style="width:50%">
								<?php if ( $missing ) : ?><span class="trr-edot">&#9679;</span> <?php endif; ?>
								<?php echo wp_kses_post( \TranslateRocket\Frontend\InlineText::admin_html( (string) $row->original ) ); ?>
								<div class="trr-etype"><?php echo esc_html( $key ); ?></div>
							</td>
							<?php echo wp_kses( $this->gt_cell( (string) $row->original, $lang ) , \TranslateRocket\Kses::html_rules() ); ?>
							<td>
								<textarea class="large-text trr-etr" rows="1" name="tr[<?php echo (int) $row->string_id; ?>]" data-src="<?php echo esc_attr( (string) $row->original ); ?>"><?php echo esc_textarea( (string) $row->translation ); ?></textarea>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the structured SEO/indexing block: slug, title, description.
	 *
	 * @param string            $lang    Language.
	 * @param array<int,object> $rows    Meta-type strings for the page.
	 * @param int               $post_id Post id (0 if not a single post/page).
	 */
	private function render_seo_section( string $lang, array $rows, int $post_id ): void {
		$title = null;
		$desc  = null;
		foreach ( $rows as $row ) {
			if ( 'title' === $row->context ) {
				$title = $row;
			} elseif ( 'description' === $row->context ) {
				$desc = $row;
			}
		}
		?>
		<div class="trr-eblockwrap">
			<h3><?php esc_html_e( 'SEO & indexing', 'translate-rocket' ); ?></h3>
			<table class="widefat striped">
				<tbody>
					<tr>
						<td style="width:50%">
							<strong><?php esc_html_e( 'Slug (URL)', 'translate-rocket' ); ?></strong>
							<?php $this->info( __( 'The page web address in this language, e.g. /it/chi-siamo/. Leave empty to keep the original.', 'translate-rocket' ) ); ?>
							<div class="trr-etype">/<?php echo esc_html( $lang ); ?>/…</div>
						</td>
						<td class="trr-gt-cell"></td>
						<td>
							<?php if ( $post_id > 0 ) : ?>
								<input type="text" class="large-text" name="slug" value="<?php echo esc_attr( Slugs::get( $post_id, $lang ) ); ?>" />
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Available only on single pages and posts (not the home or archives).', 'translate-rocket' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php
					$this->seo_row( __( 'SEO title', 'translate-rocket' ), __( 'Shown in the browser tab and as the clickable title in Google results.', 'translate-rocket' ), $title, $lang );
					$this->seo_row( __( 'Meta description', 'translate-rocket' ), __( 'The short summary under the title in Google results (only if your theme or SEO plugin outputs one).', 'translate-rocket' ), $desc, $lang );
					?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'Meta keywords are intentionally not shown.', 'translate-rocket' ); ?>
				<?php $this->info( __( 'Search engines (Google included) dropped meta keywords as a ranking signal years ago, so a keywords field would only add clutter.', 'translate-rocket' ) ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * One labelled SEO row (title / description).
	 *
	 * @param string      $label Field label.
	 * @param string      $info  Tooltip text.
	 * @param object|null $row   The detected string row, or null.
	 * @param string      $lang  Target language (for the Google Translate link).
	 */
	private function seo_row( string $label, string $info, $row, string $lang = '' ): void {
		$missing = ! $row || ! ( (int) $row->status > 0 && null !== $row->translation && '' !== $row->translation );
		?>
		<tr class="<?php echo $missing && $row ? 'trr-emissing' : ''; ?>">
			<td style="width:50%">
				<?php if ( $missing && $row ) : ?><span class="trr-edot">&#9679;</span> <?php endif; ?>
				<strong><?php echo esc_html( $label ); ?></strong>
				<?php $this->info( $info ); ?>
				<?php if ( $row ) : ?><div class="trr-etype"><?php echo esc_html( $row->original ); ?></div><?php endif; ?>
			</td>
			<?php if ( $row ) : ?>
				<?php echo wp_kses( $this->gt_cell( (string) $row->original, $lang ) , \TranslateRocket\Kses::html_rules() ); ?>
				<td>
					<textarea class="large-text trr-etr" rows="1" name="tr[<?php echo (int) $row->string_id; ?>]" data-src="<?php echo esc_attr( (string) $row->original ); ?>"><?php echo esc_textarea( (string) $row->translation ); ?></textarea>
				</td>
			<?php else : ?>
				<td class="trr-gt-cell"></td>
				<td>
					<span class="description"><?php esc_html_e( 'Not detected on this page.', 'translate-rocket' ); ?></span>
				</td>
			<?php endif; ?>
		</tr>
		<?php
	}

	/**
	 * A small "i" info icon. Click to open a popover that stays open (handled by
	 * assets/js/admin-info.js) — easier to read than a hover tooltip.
	 */
	private function info( string $text ): void {
		self::info_static( $text );
	}

	/**
	 * The same «i», for the screens drawn by other classes (StatsCard, 1.8.6).
	 *
	 * @param string $text What the popover says.
	 */
	public static function info_static( string $text ): void {
		echo '<button type="button" class="trr-info" aria-label="' . esc_attr__( 'More info', 'translate-rocket' )
			. '" data-info="' . esc_attr( $text ) . '"><span class="dashicons dashicons-info-outline"></span></button>';
	}
}
