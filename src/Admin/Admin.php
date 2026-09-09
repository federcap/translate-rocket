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
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_translations' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_translations' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_ai_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_auto_translate' ) );
		add_action( 'admin_init', array( $this, 'maybe_delete_page_translations' ) );
		add_action( 'admin_init', array( $this, 'maybe_forget_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_external' ) );
		add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_csv' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_weglot' ) );
		add_action( 'admin_init', array( $this, 'maybe_export_tmx' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_tmx' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_switcher' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_exclusions' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_memory' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_trrocket_models', array( $this, 'ajax_models' ) );
		add_action( 'wp_ajax_trrocket_gt', array( $this, 'ajax_gt' ) );
		add_action( 'wp_ajax_trrocket_deepl_usage', array( $this, 'ajax_deepl_usage' ) );
		add_action( 'wp_ajax_trrocket_test_provider', array( $this, 'ajax_test_provider' ) );
		add_action( 'admin_init', array( $this, 'maybe_clear_log' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_wizard' ) );
		add_action( 'admin_init', array( $this, 'maybe_wizard_redirect' ) );
		// Editing a page's content can change what the engine sees -> drop the cache.
		add_action( 'save_post', array( 'TranslateRocket\\Cache', 'flush' ) );
		add_action( 'deleted_post', array( 'TranslateRocket\\Cache', 'flush' ) );
		// Standing reminder while translations are in admin-only preview mode.
		add_action( 'admin_notices', array( $this, 'preview_mode_notice' ) );
		add_action( 'admin_notices', array( $this, 'provider_down_notice' ) );
		add_action( 'admin_notices', array( $this, 'changed_pages_notice' ) );
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
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
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
		if ( ! current_user_can( 'manage_options' ) ) {
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
			'<div class="notice notice-warning"><p><strong>%s</strong> %s<br><em>%s</em> <a href="%s">%s</a></p></div>',
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
			esc_html__( 'Any new text on them has not been detected yet, so it is not in your list of strings to translate — and on the translated pages it is still showing in your source language.', 'translate-rocket' ),
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
		foreach ( Registry::all() as $provider ) {
			$reason = get_transient( 'trrocket_provider_down_' . $provider->id() );
			if ( ! is_string( $reason ) || '' === $reason ) {
				continue;
			}
			$dismiss = wp_nonce_url(
				add_query_arg( 'trrocket_dismiss_provider', $provider->id() ),
				'trrocket_dismiss_provider_' . $provider->id()
			);
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s<br><em>%s</em> <a href="%s">%s</a></p></div>',
				/* translators: %s: provider name (e.g. DeepL). */
				esc_html( sprintf( __( '%s is unavailable.', 'translate-rocket' ), $provider->label() ) ),
				esc_html__( 'Automatic translation is falling back to the next provider in your chain (or stopping if there is none).', 'translate-rocket' ),
				esc_html( $reason ),
				esc_url( $dismiss ),
				esc_html__( 'Dismiss', 'translate-rocket' )
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
						'pick'    => __( '— pick a model —', 'translate-rocket' ),
						'chars'   => __( 'characters used', 'translate-rocket' ),
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
					'urls' => $this->scannable_urls(),
					'i18n' => array(
						'confirm'  => __( 'Scan all published pages to detect translatable text? Keep this tab open until it finishes.', 'translate-rocket' ),
						'scanning' => __( 'Scanning', 'translate-rocket' ),
						'done'     => __( 'Scan complete — reloading…', 'translate-rocket' ),
						'none'     => __( 'No published pages found to scan.', 'translate-rocket' ),
					),
				)
			);
		}
	}

	/**
	 * Front-end URLs (source language) of all published pages/posts, for the
	 * one-click site scan. The browser fetches each so the engine collects its
	 * strings — no server-side self-requests (which would stall few-worker hosts).
	 *
	 * @return string[]
	 */
	private function scannable_urls(): array {
		$urls = array( home_url( '/' ) );
		$ids  = get_posts(
			array(
				'post_type'   => array( 'page', 'post' ),
				'post_status' => 'publish',
				'numberposts' => 1000,
				'fields'      => 'ids',
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
		$models = $provider->list_models();
		if ( empty( $models ) ) {
			wp_send_json_error( array( 'error' => 'no-models' ) );
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
			wp_send_json_error( array( 'error' => __( 'Add the API key first.', 'translate-rocket' ) ) );
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
		$res = $provider->translate( array( 'Hello' ), $source, $target );
		if ( $res->success && ! empty( $res->translations ) ) {
			wp_send_json_success(
				array(
					'source' => 'Hello',
					'sample' => (string) $res->translations[0],
					'target' => $target,
				)
			);
		}
		\TranslateRocket\Logger::error(
			'ai',
			/* translators: %s: provider name. */
			sprintf( __( 'Connection test failed for %s.', 'translate-rocket' ), $provider->label() ),
			$res->error
		);
		wp_send_json_error( array( 'error' => '' !== $res->error ? $res->error : __( 'The provider did not return a translation.', 'translate-rocket' ) ) );
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
		$settings['cache_pages']         = ! empty( $_POST['cache_pages'] );
		$settings['show_poweredby']      = ! empty( $_POST['show_poweredby'] );

		Settings::update( $settings );
		// Any settings change can alter the output — drop any cached pages.
		\TranslateRocket\Cache::flush();

		if ( ! empty( $settings['translate_interface'] ) ) {
			$this->ensure_language_packs( $targets );
		}

		$redirect = array(
			'page'    => 'translate-rocket',
			'updated' => '1',
		);
		if ( $over ) {
			$redirect['limit'] = '1';
		}
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

		if ( isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ) {
			foreach ( wp_unslash( $_POST['tr'] ) as $string_id => $translation ) { // phpcs:ignore
				Strings::save_translation( (int) $string_id, $lang, sanitize_text_field( $translation ) );
			}
		}

		if ( isset( $_POST['post_id'], $_POST['slug'] ) ) {
			$pid = (int) $_POST['post_id'];
			if ( $pid > 0 && current_user_can( 'edit_post', $pid ) ) {
				Slugs::set( $pid, $lang, sanitize_text_field( wp_unslash( $_POST['slug'] ) ) );
			}
		}

		$this->redirect_editor( $lang, 'saved' );
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
				Strings::save_translation( $ids[ $idx ], $lang, sanitize_text_field( $text ) );
			}
		}

		$this->redirect_editor( $lang, 'imported' );
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

		Strings::reset_page( $loc, $lang );
		$this->redirect_editor( $lang, 'reset' );
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
			$result = $importer->import();
			$count  = (int) ( $result['count'] ?? 0 );
			// Imported strings won't show on the front end until caches are cleared —
			// the CSV/TMX/Weglot paths already flush; the migration path must too.
			\TranslateRocket\Cache::flush();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'translate-rocket-import',
					'imported_n' => $count,
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
				continue;
			}
			foreach ( $segs as $lang => $text ) {
				if ( '' === trim( $text ) ) {
					continue;
				}
				$target = self::match_target( $lang, $targets );
				if ( '' === $target || $target === $source ) {
					continue;
				}
				if ( Strings::store_imported( $src_text, $target, $text ) ) {
					++$count;
				}
			}
		}
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
		$result   = \TranslateRocket\Importers\WeglotCsv::import(
			(string) $_FILES['trrocket_weglot_csv']['tmp_name'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-generated temp path, is_uploaded_file-checked above.
			$fallback
		);

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
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the fopen() stream above.

		\TranslateRocket\Cache::flush();
		$redirect( array( 'csv_imported' => $count ) );
	}

	/**
	 * Render the import (migrate from another plugin) page.
	 */
	public function render_import_page(): void {
		?>
		<div class="wrap trrocket-wrap">
			<?php self::header( 'translate-rocket-import' ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'Import translations', 'translate-rocket' ); ?><?php $this->info( __( 'Already used another translation plugin? Bring its translations into TranslateRocket so you don’t start over.', 'translate-rocket' ) ); ?></h1>

			<?php if ( isset( $_GET['imported_n'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
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
							/* translators: %d: number of strings found. */
							printf( esc_html__( '%d translatable strings found.', 'translate-rocket' ), (int) $count );
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
		if ( ! isset( $_POST['trrocket_switcher_nonce'] ) ) {
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
				$settings['switchers'][ $slug ] = $settings['switcher'];
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

		$type = isset( $_POST['sw_type'] ) ? sanitize_key( $_POST['sw_type'] ) : 'inline';
		$show = isset( $_POST['sw_show'] ) ? sanitize_key( $_POST['sw_show'] ) : 'both';
		$cur  = isset( $_POST['sw_current'] ) ? sanitize_key( $_POST['sw_current'] ) : 'show';
		$placement = isset( $_POST['sw_placement'] ) ? sanitize_key( $_POST['sw_placement'] ) : 'manual';

		$sw['type']          = in_array( $type, array( 'inline', 'list', 'dropdown', 'scroll' ), true ) ? $type : 'inline';
		$sw['show']          = in_array( $show, array( 'both', 'flag', 'name', 'code', 'flagcode' ), true ) ? $show : 'both';
		$sw['current']       = in_array( $cur, array( 'show', 'hide' ), true ) ? $cur : 'show';
		// Placement is one either/or choice: manual (shortcode/block) OR floating.
		$sw['floating']      = ( 'manual' !== $placement );
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

		if ( 'default' === $profile ) {
			$settings['switcher'] = $sw;
		} else {
			$settings['switchers'][ $profile ] = $sw;
		}
		Settings::update( $settings );
		$this->redirect_switcher( $profile );
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
	 * Server-rendered switcher for the live preview box. Built so the customizer
	 * JS can toggle layout / show / names live (flag + name in separate spans,
	 * plus a hidden <select> for the dropdown layout).
	 */
	private function switcher_preview(): string {
		$router  = \TranslateRocket\Plugin::instance()->router();
		$langs   = $router->active_languages();
		$current = $router->current_language();
		if ( count( $langs ) < 2 ) {
			return '<p class="description">' . esc_html__( 'Add target languages to see the preview.', 'translate-rocket' ) . '</p>';
		}

		$items  = '';
		$toggle = '';
		$menu   = '';
		$widest = '';
		foreach ( $langs as $code ) {
			$flag = \TranslateRocket\Flags::svg( $code );
			if ( '' === $flag ) {
				$flag = esc_html( Languages::flag( $code ) );
			}
			$native = Languages::label( $code );
			$en     = Languages::english_label( $code );
			$label  = '<span class="trrocket-flag-wrap">' . $flag . '</span> <span class="trrocket-name" data-native="' . esc_attr( $native ) . '" data-en="' . esc_attr( $en ) . '">' . esc_html( $native ) . '</span>';

			if ( $code === $current ) {
				$items .= '<li class="trrocket-current trr-prev-cur"><span>' . $label . '</span></li>';
				$toggle = $label;
			} else {
				$items .= '<li><a role="link" tabindex="0">' . $label . '</a></li>';
				$menu  .= '<li><a role="link" tabindex="0">' . $label . '</a></li>';
			}
			// The widest name decides the control's width, exactly as on the front-end.
			if ( mb_strlen( Languages::label( $code ) ) > mb_strlen( $widest ) ) {
				$widest = Languages::label( $code );
			}
		}

		$caret = '<span class="trrocket-dd-caret" aria-hidden="true"><svg viewBox="0 0 10 6" width="10" height="6"><path d="M1 1l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';

		// Same structure as render_dropdown() on the front-end: an invisible in-flow
		// ANCHOR sized to the widest item, plus the interactive OVERLAY on top. The
		// preview used to be a simplified box whose menu was nailed open with an
		// inline "display:block": you could see the menu's colours but never how it
		// opens, which is half of what a switcher does. Now it opens and closes for
		// real, on the same markup and the same CSS as the site.
		$anchor = '<div class="trrocket-dd-anchor" aria-hidden="true">'
			. '<span class="trrocket-dd-toggle"><span class="trrocket-flag-wrap">' . \TranslateRocket\Flags::svg( $current ) . '</span> <span class="trrocket-name">' . esc_html( $widest ) . '</span>' . $caret . '</span>'
			. '</div>';

		return '<ul class="trrocket-switcher trr-prev-ul">' . $items . '</ul>'
			. '<div class="trrocket-dd trr-prev-dd" style="display:none">'
			. $anchor
			. '<div class="trrocket-dd-overlay">'
			. '<button type="button" class="trrocket-dd-toggle" aria-haspopup="true" aria-expanded="false">' . $toggle . $caret . '</button>'
			. '<ul class="trrocket-dd-menu">' . $menu . '</ul>'
			. '</div>'
			. '</div>';
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
			<p class="trrocket-tagline"><?php esc_html_e( 'Design your switcher — create profiles (e.g. header, footer), each with its own style and a live preview.', 'translate-rocket' ); ?></p>

			<div class="trrocket-card" style="max-width:none">
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

			<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
				<form method="post" action="" style="flex:1 1 380px;min-width:340px">
					<?php wp_nonce_field( 'trrocket_save_switcher', 'trrocket_switcher_nonce' ); ?>
					<input type="hidden" name="sw_profile" value="<?php echo esc_attr( $profile ); ?>" />

					<div class="trrocket-card">
						<h2><?php esc_html_e( 'Style', 'translate-rocket' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr><th scope="row"><?php esc_html_e( 'Layout', 'translate-rocket' ); ?></th><td>
								<select id="sw_type" name="sw_type">
									<option value="inline" <?php selected( $g( 'type' ), 'inline' ); ?>><?php esc_html_e( 'Inline (horizontal)', 'translate-rocket' ); ?></option>
									<option value="list" <?php selected( $g( 'type' ), 'list' ); ?>><?php esc_html_e( 'List (vertical)', 'translate-rocket' ); ?></option>
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
								<p class="description"><?php esc_html_e( 'Which screen sizes show this switcher. Make one profile “Desktop only” and another “Mobile only” to design a different language menu for each (breakpoint 783px). The live preview above always shows the desktop appearance.', 'translate-rocket' ); ?></p>
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

					<div class="trrocket-card">
						<h2><?php esc_html_e( 'Placement', 'translate-rocket' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Choose one: either place the switcher yourself with the shortcode / block, or let the plugin float it for you — not both.', 'translate-rocket' ); ?></p>
						<?php $placement = empty( $g( 'floating' ) ) ? 'manual' : (string) $g( 'float_pos' ); ?>
						<p><label><?php esc_html_e( 'Where to show the switcher', 'translate-rocket' ); ?>
							<select name="sw_placement" id="sw_placement">
								<?php
								foreach ( array(
									'manual'       => __( 'Manual — I add it with the shortcode / block', 'translate-rocket' ),
									'bottom-right' => __( 'Floating — bottom right', 'translate-rocket' ),
									'bottom-left'  => __( 'Floating — bottom left', 'translate-rocket' ),
									'top-right'    => __( 'Floating — top right', 'translate-rocket' ),
									'top-left'     => __( 'Floating — top left', 'translate-rocket' ),
									'custom'       => __( 'Floating — custom position (X / Y)', 'translate-rocket' ),
								) as $v => $l ) :
									?>
									<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $placement, $v ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
						</label></p>
						<p id="sw-custom-pos">
							<label><?php esc_html_e( 'X (from left)', 'translate-rocket' ); ?> <input type="text" name="sw_float_x" value="<?php echo esc_attr( (string) $g( 'float_x' ) ); ?>" placeholder="10%" class="small-text" /></label>
							&nbsp;
							<label><?php esc_html_e( 'Y (from top)', 'translate-rocket' ); ?> <input type="text" name="sw_float_y" value="<?php echo esc_attr( (string) $g( 'float_y' ) ); ?>" placeholder="20px" class="small-text" /></label>
							<br><span class="description"><?php esc_html_e( 'Use % or px, e.g. 10% / 20px.', 'translate-rocket' ); ?></span>
						</p>
						<p id="sw-manual-hint" class="description"><?php esc_html_e( 'Add it where you want with the [translaterocket_switcher] shortcode or the “Language switcher” block.', 'translate-rocket' ); ?></p>
					</div>

					<?php submit_button( __( 'Save switcher', 'translate-rocket' ) ); ?>
				</form>

				<div class="trr-sw-side">
					<div class="trrocket-card trr-sw-sticky">
						<h2><?php esc_html_e( 'Live preview', 'translate-rocket' ); ?></h2>
						<div id="trr-sw-preview" style="padding:24px;border:1px dashed #ccd0d4;border-radius:8px;background:#fff;text-align:center">
							<?php echo wp_kses( $this->switcher_preview() , \TranslateRocket\Kses::html_rules() ); ?>
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
						<p class="description"><?php esc_html_e( 'Colours update live; layout and labels update after you save.', 'translate-rocket' ); ?></p>
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
		if ( isset( $_GET['cleaned'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of removed strings. */
					_n( 'Removed %d unused string.', 'Removed %d unused strings.', (int) $_GET['cleaned'], 'translate-rocket' ),
					(int) $_GET['cleaned']
				)
			) . '</p></div>';
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
				esc_html( Languages::flag( $code ) . ' ' . Languages::label( $code ) )
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

		$rows = Strings::search( $lang, $term, 300, $only );
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of matching strings. */
					_n( '%d string', '%d strings', count( $rows ), 'translate-rocket' ),
					count( $rows )
				)
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
		} else {
			echo '<span class="description">' . esc_html__( 'Set up an AI provider on the AI page to bulk-translate the missing strings — or use your browser below, which needs no key.', 'translate-rocket' ) . '</span>';
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

		echo '<form method="post">';
		wp_nonce_field( 'trrocket_save_memory', 'trrocket_memory_nonce' );
		echo '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '" />';
		echo '<input type="hidden" name="s" value="' . esc_attr( $term ) . '" />';
		echo '<input type="hidden" name="onlymiss" value="' . ( $only ? '1' : '' ) . '" />';
		echo '<table class="widefat striped trr-mem"><tbody>';
		foreach ( $rows as $row ) {
			$key     = $row->type . ( $row->context ? '/' . $row->context : '' );
			$missing = ! ( (int) $row->status > 0 && null !== $row->translation && '' !== $row->translation );
			echo '<tr class="' . ( $missing ? 'trr-emissing' : '' ) . '">';
			echo '<td style="width:42%">' . ( $missing ? '<span class="trr-edot">&#9679;</span> ' : '' )
				. esc_html( (string) $row->original ) . '<div class="trr-etype">' . esc_html( $key ) . '</div></td>';
			echo wp_kses( '<td class="trr-gt-cell">' . $this->gt_link( (string) $row->original, $lang ) . '</td>' , \TranslateRocket\Kses::html_rules() );
			echo '<td><textarea class="large-text trr-etr" rows="1" name="tr[' . (int) $row->string_id . ']" data-src="' . esc_attr( (string) $row->original ) . '">'
				. esc_textarea( (string) $row->translation ) . '</textarea></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
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
		if ( isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ) {
			foreach ( wp_unslash( $_POST['tr'] ) as $sid => $tr ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				Strings::save_translation( (int) $sid, $lang, sanitize_textarea_field( (string) $tr ) );
			}
		}
		\TranslateRocket\Cache::flush();

		$args = array(
			'page'  => 'translate-rocket-memory',
			'lang'  => $lang,
			'saved' => '1',
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

	private function redirect_editor( string $lang, string $flag ): void {
		// These redirects all follow a write (save / import / reset) — drop the cache.
		\TranslateRocket\Cache::flush();
		$args = array(
			'page' => 'translate-rocket-strings',
			'lang' => $lang,
			$flag  => '1',
		);
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
		$ids      = array( 'deepl', 'openai', 'google', 'gemini', 'anthropic' );

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
		$settings['ai_guidance']     = isset( $_POST['ai_guidance'] )
			? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['ai_guidance'] ) ), 0, \TranslateRocket\Providers\LlmProvider::GUIDANCE_MAX )
			: '';
		$settings['ai_reset_day']    = isset( $_POST['ai_reset_day'] ) ? max( 0, min( 31, (int) $_POST['ai_reset_day'] ) ) : 0;
		$settings['ai_block_bots']   = ! empty( $_POST['ai_block_bots'] );

		if ( ! empty( $_POST['trr_ai_reset'] ) ) {
			\TranslateRocket\AiUsage::reset_usage();
		}

		$posted = ( isset( $_POST['providers'] ) && is_array( $_POST['providers'] ) ) ? wp_unslash( $_POST['providers'] ) : array(); // phpcs:ignore
		foreach ( $ids as $pid ) {
			if ( empty( $settings['providers'][ $pid ] ) || ! is_array( $settings['providers'][ $pid ] ) ) {
				$settings['providers'][ $pid ] = array();
			}
			$settings['providers'][ $pid ]['api_key'] = isset( $posted[ $pid ]['api_key'] ) ? sanitize_text_field( $posted[ $pid ]['api_key'] ) : '';
			if ( isset( $posted[ $pid ]['model'] ) ) {
				$settings['providers'][ $pid ]['model'] = sanitize_text_field( $posted[ $pid ]['model'] );
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
					<p style="margin:0 0 9px;color:#50575e"><?php echo esc_html( $errore ); ?></p>
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
	 * Run AI auto-translation for a language (optionally a single page).
	 */
	public function maybe_auto_translate(): void {
		if ( ! isset( $_POST['trrocket_auto_nonce'] ) ) {
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

		$result = Translator::translate_missing( $lang, $loc );
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
		// Same order as the WP submenu on the left, with an icon before each name.
		$pages = array(
			'translate-rocket'            => array( __( 'Languages', 'translate-rocket' ), 'dashicons-translation' ),
			'translate-rocket-strings'    => array( __( 'Translations', 'translate-rocket' ), 'dashicons-edit' ),
			'translate-rocket-memory'     => array( __( 'Memory', 'translate-rocket' ), 'dashicons-database' ),
			'translate-rocket-ai'         => array( __( 'AI', 'translate-rocket' ), 'dashicons-superhero' ),
			'translate-rocket-import'     => array( __( 'Import', 'translate-rocket' ), 'dashicons-download' ),
			'translate-rocket-switcher'   => array( __( 'Switcher', 'translate-rocket' ), 'dashicons-flag' ),
			'translate-rocket-exclusions' => array( __( 'Exclusions', 'translate-rocket' ), 'dashicons-shield' ),
			'translate-rocket-copies'     => array( __( 'Copies', 'translate-rocket' ), 'dashicons-admin-page' ),
			'translate-rocket-diagnostics' => array( __( 'Diagnostics', 'translate-rocket' ), 'dashicons-heart' ),
			'translate-rocket-help'       => array( __( 'Help', 'translate-rocket' ), 'dashicons-sos' ),
		);

		$unseen = \TranslateRocket\Logger::count_unseen();

		echo '<div class="trr-hero">';
		echo '<img class="trr-hero-full" src="' . esc_url( TRROCKET_URL . 'assets/img/logo-white.png' ) . '" alt="TranslateRocket™" width="1200" height="226" />';
		echo '<div class="trr-hero-info">';
		echo '<div class="trr-hero-tag">' . esc_html__( 'Free, AI-powered translation for WordPress', 'translate-rocket' ) . '</div>';
		echo '<div class="trr-hero-sub">' . esc_html__( 'Unlimited languages · Visual editor · SEO-ready', 'translate-rocket' ) . ' · <span class="trr-hero-ver">v' . esc_html( TRROCKET_VERSION ) . '</span></div>';
		echo '</div>';
		echo '</div>';

		echo '<nav class="trr-nav">';
		foreach ( $pages as $slug => $p ) {
			$cls = ( $slug === $current ) ? 'trr-nav-item is-active' : 'trr-nav-item';
			$badge = '';
			if ( 'translate-rocket-diagnostics' === $slug && $unseen > 0 ) {
				$cls  .= ' has-issues';
				$badge = ' <span class="trr-nav-badge">' . esc_html( (string) $unseen ) . '</span>';
			}
			echo wp_kses(
				'<a class="' . esc_attr( $cls ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">'
				. '<span class="dashicons ' . esc_attr( $p[1] ) . '"></span> ' . esc_html( $p[0] )
				. $badge
				. '</a>',
				\TranslateRocket\Kses::html_rules()
			);
		}
		// Optional donation link: keeps the plugin free + supporters get priority help.
		echo '<a class="trr-nav-item trr-nav-support" href="https://translaterocket.com/donate/" target="_blank" rel="noopener" title="'
			. esc_attr__( 'TranslateRocket is 100% free. Support development to keep it free — supporters get priority support & custom help.', 'translate-rocket' ) . '">'
			. '<span class="dashicons dashicons-heart"></span> ' . esc_html__( 'Support the project', 'translate-rocket' )
			. '</a>';
		echo '</nav>';
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
			echo '<a class="' . esc_attr( $cls ) . '" style="margin-right:6px" href="' . esc_url( $url ) . '">' . esc_html( trim( \TranslateRocket\Languages::flag( $code ) . ' ' . \TranslateRocket\Languages::label( $code ) ) ) . '</a>';
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

		$defs = array(
			'deepl'     => array(
				'label'  => 'DeepL',
				'model'  => false,
				'help'   => __( 'Free keys end in “:fx”. Use “Check usage” below for your live monthly quota.', 'translate-rocket' ),
				'signup' => 'https://www.deepl.com/en/pro',
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
		?>
		<div class="wrap trrocket-wrap">
			<?php self::header( 'translate-rocket-ai' ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'AI Translation', 'translate-rocket' ); ?></h1>

			<?php if ( isset( $_GET['ai_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'AI settings saved.', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>

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
						?>
						<div class="trr-ai-provider<?php echo $is_active ? ' is-active' : ''; ?>">
							<div class="trr-ai-phead">
								<strong class="trr-ai-pname"><?php echo esc_html( $def['label'] ); ?></strong>
								<?php if ( $is_active ) : ?><span class="trr-ai-badge is-on"><?php esc_html_e( 'Active', 'translate-rocket' ); ?></span><?php elseif ( $has_key ) : ?><span class="trr-ai-badge"><?php esc_html_e( 'Key set', 'translate-rocket' ); ?></span><?php endif; ?>
							</div>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'API key', 'translate-rocket' ); ?> <?php $this->info( __( 'Your secret key from this provider. Stored on your site only, and sent to the provider when translating.', 'translate-rocket' ) ); ?></th>
								<td>
									<input type="password" class="regular-text" autocomplete="off"
										name="providers[<?php echo esc_attr( $pid ); ?>][api_key]"
										value="<?php echo esc_attr( (string) ( $providers[ $pid ]['api_key'] ?? '' ) ); ?>" />
									<?php if ( '' !== $def['help'] ) : ?>
										<p class="description"><?php echo esc_html( $def['help'] ); ?></p>
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
												printf( esc_html__( 'Get a %s API key ↗', 'translate-rocket' ), esc_html( $def['label'] ) );
												?>
											</a>
										</p>
									<?php endif; ?>
								</td>
							</tr>
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
										<p class="description"><?php esc_html_e( 'Queries this provider with your key and lists the exact models it can use right now.', 'translate-rocket' ); ?></p>
									</td>
								</tr>
							<?php endif; ?>
						</table>
							<?php if ( 'deepl' === $pid ) : ?>
								<p class="trr-ai-pfoot"><button type="button" class="button button-small trr-deepl-usage">&#128202; <?php esc_html_e( 'Check usage', 'translate-rocket' ); ?></button> <span class="trr-deepl-usage-out description"></span></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					</div>

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
									<td><?php echo esc_html( Languages::flag( $code ) . ' ' . Languages::label( $code ) ); ?></td>
									<td><?php echo wp_kses( $this->progress_bar( $done, $det, true ) , \TranslateRocket\Kses::html_rules() ); ?></td>
									<td><strong><?php echo (int) $miss; ?></strong></td>
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
					<h2><?php esc_html_e( 'Words &amp; phrases', 'translate-rocket' ); ?> <?php $this->info( __( 'Exact strings that must stay as-is — brand or product names, etc. Never collected, translated or sent to an AI.', 'translate-rocket' ) ); ?></h2>
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
		$prov_defs = array(
			'deepl'     => 'DeepL',
			'openai'    => 'OpenAI',
			'anthropic' => 'Anthropic (Claude)',
			'gemini'    => 'Google Gemini',
			'google'    => 'Google Translate',
		);
		$configured = array();
		foreach ( $prov_defs as $pid => $plabel ) {
			if ( '' !== (string) ( $settings['providers'][ $pid ]['api_key'] ?? '' ) ) {
				$configured[ $pid ] = $plabel;
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
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				btn.disabled = false;
				if ( res && res.success ) {
					out.className = 'trr-test-result is-ok';
					out.textContent = ( res.data && res.data.sample ? ( '✓ “' + ( res.data.source || 'Hello' ) + '” → “' + res.data.sample + '”' ) : '✓ OK' );
				} else {
					out.className = 'trr-test-result is-fail';
					out.textContent = '✗ ' + ( res && res.data && res.data.error ? res.data.error : 'Error' );
				}
			} )
			.catch( function () { btn.disabled = false; out.className = 'trr-test-result is-fail'; out.textContent = '✗'; } );
	} );
}() );
JS;
	}

	/**
	 * Save the setup wizard (languages, optional AI key, switcher placement),
	 * then send the admin to the "done" step.
	 */
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

		$place                          = isset( $_POST['wiz_switcher'] ) ? sanitize_key( $_POST['wiz_switcher'] ) : 'floating';
		$settings['switcher']['floating'] = ( 'floating' === $place );

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
			$edit_url = ( '' !== $first )
				? add_query_arg( \TranslateRocket\Frontend\VisualEditor::PARAM, '1', $router->home_for_language( $first ) )
				: home_url( '/' );
			?>
			<div class="wrap trrocket-wrap trrocket-wizard">
				<div class="trr-wiz-card trr-wiz-done">
					<div class="trr-wiz-check">✓</div>
					<h1><?php esc_html_e( 'You’re all set!', 'translate-rocket' ); ?></h1>
					<p class="trr-wiz-lead"><?php esc_html_e( 'Your languages are configured. Now translate your first page — it takes a minute.', 'translate-rocket' ); ?></p>
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
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $source ); ?>><?php echo esc_html( $info[0] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p class="trr-wiz-sub"><?php esc_html_e( 'Translate into:', 'translate-rocket' ); ?></p>
					<div class="trrocket-lang-grid trr-wiz-grid">
						<?php foreach ( $builtins as $code => $info ) : ?>
							<label class="trrocket-lang-item trr-wiz-target" data-code="<?php echo esc_attr( $code ); ?>"<?php echo ( $code === $source ) ? ' style="display:none"' : ''; ?>>
								<input type="checkbox" name="target_languages[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $targets, true ) ); ?> />
								<span class="trrocket-flag"><?php echo wp_kses( \TranslateRocket\Flags::html( (string) $code, (string) ( $info[2] ?? '' ) ) , \TranslateRocket\Kses::html_rules() ); ?></span>
								<span><?php echo esc_html( $info[0] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</section>

				<!-- Step 2: translation method -->
				<section class="trr-wiz-step" data-step="2" hidden>
					<h2><?php esc_html_e( 'How do you want to translate?', 'translate-rocket' ); ?></h2>
					<p class="trr-wiz-lead"><?php esc_html_e( 'You can let an AI do a first pass automatically, or translate by hand — you can always change this later.', 'translate-rocket' ); ?></p>
					<label class="trr-wiz-radio">
						<input type="radio" name="trr_method" value="ai" />
						<span><strong><?php esc_html_e( 'Use an AI provider', 'translate-rocket' ); ?></strong><br><?php esc_html_e( 'Paste your own API key. You pay the provider directly — TranslateRocket takes nothing.', 'translate-rocket' ); ?></span>
					</label>
					<div class="trr-wiz-ai" hidden>
						<select name="wiz_provider" id="trr-wiz-provider">
							<?php foreach ( $prov as $pid => $plabel ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $plabel ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="password" name="wiz_key" id="trr-wiz-key" class="regular-text" placeholder="<?php esc_attr_e( 'Paste your API key', 'translate-rocket' ); ?>" autocomplete="off" />
					</div>
					<label class="trr-wiz-radio">
						<input type="radio" name="trr_method" value="manual" checked />
						<span><strong><?php esc_html_e( 'No API key for now', 'translate-rocket' ); ?></strong><br><?php esc_html_e( 'Translate by hand, or let Chrome and Edge translate the whole site for free from the Translations page — the text never leaves your computer. You can add a key later at any time.', 'translate-rocket' ); ?></span>
					</label>
				</section>

				<!-- Step 3: switcher -->
				<section class="trr-wiz-step" data-step="3" hidden>
					<h2><?php esc_html_e( 'Show the language switcher', 'translate-rocket' ); ?></h2>
					<p class="trr-wiz-lead"><?php esc_html_e( 'How should visitors switch language? You can fine-tune the look later on the Switcher tab.', 'translate-rocket' ); ?></p>
					<label class="trr-wiz-radio">
						<input type="radio" name="wiz_switcher" value="floating" checked />
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
	next.addEventListener( 'click', function () { show( i + 1 ); } );
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
	document.querySelectorAll( 'input[name="trr_method"]' ).forEach( function ( r ) {
		r.addEventListener( 'change', function () { if ( aiBox ) { aiBox.hidden = ( document.querySelector( 'input[name=trr_method]:checked' ).value !== 'ai' ); } } );
	} );
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

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'trrocket_save_settings', 'trrocket_settings_nonce' ); ?>
				<div class="trrocket-card">
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
											<?php echo esc_html( $info[2] . ' ' . $info[0] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'The language your content is written in. It stays at the site root (no URL prefix).', 'translate-rocket' ); ?></p>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Translate into', 'translate-rocket' ); ?> <?php $this->info( __( 'The languages visitors can switch to. Each gets its own URL prefix (e.g. /it/) and its own set of translations.', 'translate-rocket' ) ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Pick the languages you want to add. Each gets its own URL prefix, e.g. /en/.', 'translate-rocket' ); ?>
						<br>
						<?php
						printf(
							/* translators: %s: the source language name (flag + label). */
							esc_html__( 'Your source language (%s) isn’t listed here — it’s the language you translate from. Change it in “Site language (source)” above.', 'translate-rocket' ),
							esc_html( trim( Languages::flag( $source ) . ' ' . Languages::label( $source ) ) )
						);
						?>
						<br>
						<?php esc_html_e( 'A language you add now starts offline: nobody sees it while you translate it. Flip its switch to green when it is ready.', 'translate-rocket' ); ?>
					</p>

					<?php
					// Ogni lingua scelta si porta accanto il suo interruttore: verde
					// quando i visitatori la vedono, grigio finche' la si traduce.
					$offline_ora = (array) ( $settings['offline_languages'] ?? array() );
					$testo_on    = __( 'Online: your visitors see this language. Click to hide it while you work on it.', 'translate-rocket' );
					$testo_off   = __( 'Offline: only you see this language. Click to publish it.', 'translate-rocket' );
					?>
					<div class="trrocket-lang-grid trr-lang-grid">
						<?php
						foreach ( Languages::all() as $code => $info ) :
							if ( $code === $source ) {
								continue;
							}
							$scelta = in_array( $code, $targets, true );
							$spenta = in_array( (string) $code, $offline_ora, true );
							?>
							<div class="trrocket-lang-item<?php echo $scelta ? ' is-chosen' : ''; ?><?php echo $spenta ? ' is-offline' : ''; ?>" data-code="<?php echo esc_attr( (string) $code ); ?>"<?php echo $scelta ? ' data-era-scelta="1"' : ''; ?> data-era-spenta="<?php echo $spenta ? '1' : '0'; ?>">
								<label class="trrocket-lang-pick">
									<input type="checkbox" name="target_languages[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( $scelta ); ?> />
									<span class="trrocket-flag"><?php echo wp_kses( \TranslateRocket\Flags::html( (string) $code, (string) ( $info[2] ?? '' ) ) , \TranslateRocket\Kses::html_rules() ); ?></span>
									<span class="trrocket-lang-name"><?php echo esc_html( $info[0] ); ?></span>
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
						<table class="trr-lang-state-table">
							<tbody>
							<?php
							foreach ( $scelte as $code ) :
								$nome    = Languages::label( (string) $code );
								$manca   = \TranslateRocket\Strings::untranslated_count( (string) $code );
								$fatte   = \TranslateRocket\Strings::translated_count( (string) $code );
								$totale  = $manca + $fatte;
								$perc    = $totale > 0 ? (int) round( 100 * $fatte / $totale ) : 0;
								$is_off  = in_array( (string) $code, $offline_ora, true );
								?>
								<tr class="<?php echo $is_off ? 'is-offline' : ''; ?>" data-code="<?php echo esc_attr( (string) $code ); ?>">
									<th scope="row">
										<span class="trrocket-flag"><?php echo wp_kses( \TranslateRocket\Flags::html( (string) $code, '' ), \TranslateRocket\Kses::html_rules() ); ?></span>
										<strong><?php echo esc_html( $nome ); ?></strong>
										<code>/<?php echo esc_html( (string) $code ); ?>/</code>
									</th>
									<td class="trr-lang-state-prog">
										<?php if ( $totale > 0 ) : ?>
											<div class="trr-prog" title="<?php echo esc_attr( sprintf( /* translators: 1: translated strings, 2: total strings. */ __( '%1$d of %2$d translated', 'translate-rocket' ), $fatte, $totale ) ); ?>">
												<?php
												// Il colore va scritto qui: .trr-prog-fill non ne ha uno suo, e
												// senza questo la barra restava una vaschetta grigia vuota anche
												// al 98%. Rosso -> giallo -> verde, come le altre barre.
												$tinta = self::hsl_to_hex( (int) round( $perc * 1.2 ), 72, 45 );
												?>
												<div class="trr-prog-bar"><div class="trr-prog-fill" style="width:<?php echo (int) $perc; ?>%;background:<?php echo esc_attr( $tinta ); ?>"></div></div>
											</div>
											<span class="trr-lang-state-pc"><?php echo (int) $perc; ?>%</span>
										<?php else : ?>
											<span class="description"><?php esc_html_e( 'nothing collected yet', 'translate-rocket' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="trr-lang-state-switch">
										<span class="trr-state-pill trr-state-yes"><?php esc_html_e( 'online', 'translate-rocket' ); ?></span>
										<span class="trr-state-pill trr-state-no"><?php esc_html_e( 'offline', 'translate-rocket' ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
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
						<tr>
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
							<th scope="row"><?php esc_html_e( 'Page cache', 'translate-rocket' ); ?> <?php $this->info( __( 'Store the finished translated HTML of each page so repeat visits skip the on-the-fly translation — about 2× faster on repeat visits (e.g. ~0.55s → ~0.29s in our test). Only anonymous visitors are served from the cache, it skips dynamic pages (cart, checkout, account), and it clears automatically whenever you change a translation, edit a page, or update settings. On by default — turn it off only if you already use a page-cache plugin (e.g. WP Rocket), which already caches each language’s URL.', 'translate-rocket' ) ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="cache_pages" value="1" <?php checked( ! empty( $settings['cache_pages'] ) ); ?> />
									<?php esc_html_e( 'Cache translated pages for anonymous visitors', 'translate-rocket' ); ?>
								</label>
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

			<?php if ( ! empty( $targets ) ) : ?>
				<div class="trrocket-card">
					<h2><?php esc_html_e( 'Progress', 'translate-rocket' ); ?></h2>
					<table class="widefat striped trr-progress-table" style="margin-top:8px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Language', 'translate-rocket' ); ?></th>
								<th style="width:40%"><?php esc_html_e( 'Progress', 'translate-rocket' ); ?></th>
								<th><?php esc_html_e( 'Detected', 'translate-rocket' ); ?></th>
								<th><?php esc_html_e( 'Translated', 'translate-rocket' ); ?></th>
								<th><?php esc_html_e( 'Missing', 'translate-rocket' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $targets as $code ) :
								$stats = Strings::language_stats( $code );
								$det   = (int) $stats['detected'];
								$done  = $det - (int) $stats['missing'];
								?>
								<tr>
									<td><?php echo esc_html( Languages::flag( $code ) . ' ' . Languages::label( $code ) ); ?></td>
									<td><?php echo wp_kses( $this->progress_bar( $done, $det, true ) , \TranslateRocket\Kses::html_rules() ); ?></td>
									<td><?php echo (int) $det; ?></td>
									<td><?php echo (int) $stats['translated']; ?></td>
									<td><strong><?php echo (int) $stats['missing']; ?></strong></td>
									<td><a href="<?php echo esc_url( $this->editor_url( $code ) ); ?>"><?php esc_html_e( 'Open editor', 'translate-rocket' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
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
		if ( isset( $_GET['imported'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Pasted translations imported.', 'translate-rocket' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['noimport'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Nothing imported — the paste box was empty, so existing translations were kept.', 'translate-rocket' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['reset'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( "This page's translations were deleted.", 'translate-rocket' ) . '</p></div>';
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
		echo '<p class="trr-langbar"><strong>' . esc_html__( 'Language:', 'translate-rocket' ) . '</strong> ';
		foreach ( $targets as $code ) {
			printf(
				'<a class="button %1$s" href="%2$s">%3$s</a> ',
				$code === $lang ? 'button-primary' : '',
				esc_url( $this->editor_url( $code, $loc ) ),
				esc_html( Languages::flag( $code ) . ' ' . Languages::label( $code ) )
			);
		}
		echo '</p>';

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
		$pct   = $total > 0 ? (int) round( $done / $total * 100 ) : 100;
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
		$pages = Strings::pages_with_stats( $lang );
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

			<form method="post" action="" style="margin:0;display:inline">
				<?php wp_nonce_field( 'trrocket_auto_translate', 'trrocket_auto_nonce' ); ?>
				<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
				<input type="hidden" name="loc" value="" />
				<button type="submit" class="button button-primary" <?php disabled( null === $fornitore || 0 === $miss_lingua ); ?>>
					&#10024;
					<?php
					if ( $miss_lingua > 0 ) {
						printf(
							/* translators: %d: number of strings still missing. */
							esc_html__( 'Translate all %d missing with AI', 'translate-rocket' ),
							(int) $miss_lingua
						);
					} else {
						esc_html_e( 'Everything is translated', 'translate-rocket' );
					}
					?>
				</button>
			</form>
			<?php if ( null === $fornitore ) : ?>
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
			<span class="description">
				<?php
				printf(
					/* translators: 1: number of visible pages, 2: total pages. */
					esc_html__( 'Showing %1$s of %2$d pages', 'translate-rocket' ),
					'<strong id="trr-pcount">' . (int) $count . '</strong>',
					(int) $count
				);
				?>
			</span>
		</div>
		<form id="trr-ai-onepage" method="post" action="" style="display:none">
			<?php wp_nonce_field( 'trrocket_auto_translate', 'trrocket_auto_nonce' ); ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
		</form>
		<table class="widefat striped" id="trr-ptable">
			<thead>
				<tr>
					<th class="trr-sortable" data-key="title" data-type="text"><?php esc_html_e( 'Page', 'translate-rocket' ); ?><span class="trr-arrow"></span></th>
					<th class="trr-sortable" data-key="strings" data-type="num"><?php esc_html_e( 'Strings', 'translate-rocket' ); ?><span class="trr-arrow"></span></th>
					<th class="trr-sortable" data-key="translated" data-type="num"><?php esc_html_e( 'Translated', 'translate-rocket' ); ?><span class="trr-arrow"></span></th>
					<th class="trr-sortable" data-key="missing" data-type="num"><?php esc_html_e( 'Missing', 'translate-rocket' ); ?><span class="trr-arrow"></span></th>
					<th class="trr-sortable" data-key="pct" data-type="num"><?php esc_html_e( 'Progress', 'translate-rocket' ); ?><span class="trr-arrow"></span></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $pages as $page ) :
					$total      = (int) $page->total;
					$translated = (int) $page->translated;
					$missing    = max( 0, $total - $translated );
					$pct        = $total > 0 ? (int) round( $translated / $total * 100 ) : 100;
					$label      = $page->title ? $page->title : $page->url;
					?>
					<tr
						data-title="<?php echo esc_attr( strtolower( $label ) ); ?>"
						data-strings="<?php echo (int) $total; ?>"
						data-translated="<?php echo (int) $translated; ?>"
						data-missing="<?php echo (int) $missing; ?>"
						data-pct="<?php echo (int) $pct; ?>"
						data-search="<?php echo esc_attr( strtolower( $label . ' ' . $page->url ) ); ?>">
						<td>
							<strong><?php echo esc_html( $label ); ?></strong><br>
							<span class="description"><?php echo esc_html( $page->url ); ?></span>
						</td>
						<td><?php echo (int) $total; ?></td>
						<td><?php echo (int) $translated; ?></td>
						<td><strong><?php echo (int) $missing; ?></strong></td>
						<td><?php echo wp_kses( $this->progress_bar( $translated, $total ) , \TranslateRocket\Kses::html_rules() ); ?></td>
						<td class="trr-rowactions">
							<a class="button button-small" href="<?php echo esc_url( $this->editor_url( $lang, $page->url_hash ) ); ?>"><?php esc_html_e( 'Translate', 'translate-rocket' ); ?></a>
							<?php if ( null !== $fornitore && $missing > 0 ) : ?>
								<?php // Il valore del pulsante e' il "loc" che finisce nel POST: un solo modulo per tutta la tabella invece di uno per riga. ?>
								<button type="submit" form="trr-ai-onepage" class="button button-small" name="loc"
									value="<?php echo esc_attr( $page->url_hash ); ?>"
									title="<?php esc_attr_e( 'Translate only this page with AI', 'translate-rocket' ); ?>">&#10024; <?php esc_html_e( 'AI', 'translate-rocket' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		wp_add_inline_script( 'trrocket-admin-info', self::pages_list_js() );
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
	var rows    = Array.prototype.slice.call( tbody.rows );

	function norm( s ) { return ( s || '' ).toLowerCase().replace( /\s+/g, '-' ); }

	function applyFilter() {
		var q = norm( input.value.trim() );
		var shown = 0;
		rows.forEach( function ( r ) {
			var ok = ! q || norm( r.getAttribute( 'data-search' ) ).indexOf( q ) !== -1;
			r.style.display = ok ? '' : 'none';
			if ( ok ) { shown++; }
		} );
		if ( counter ) { counter.textContent = shown; }
	}
	input.addEventListener( 'input', applyFilter );

	var ths = table.querySelectorAll( 'th.trr-sortable' );
	Array.prototype.forEach.call( ths, function ( th ) {
		var a0 = th.querySelector( '.trr-arrow' );
		if ( a0 ) { a0.textContent = ' ↕'; }
	} );
	Array.prototype.forEach.call( ths, function ( th ) {
		th.addEventListener( 'click', function () {
			var key  = th.getAttribute( 'data-key' );
			var type = th.getAttribute( 'data-type' );
			var dir  = 'asc' === th.getAttribute( 'data-dir' ) ? 'desc' : 'asc';
			Array.prototype.forEach.call( ths, function ( o ) {
				o.removeAttribute( 'data-dir' );
				var a = o.querySelector( '.trr-arrow' );
				if ( a ) { a.textContent = ' ↕'; a.classList.remove( 'is-active' ); }
			} );
			th.setAttribute( 'data-dir', dir );
			var arrow = th.querySelector( '.trr-arrow' );
			if ( arrow ) { arrow.textContent = 'asc' === dir ? ' ▲' : ' ▼'; arrow.classList.add( 'is-active' ); }
			var mult = 'asc' === dir ? 1 : -1;
			rows.sort( function ( a, b ) {
				var va = a.getAttribute( 'data-' + key ) || '';
				var vb = b.getAttribute( 'data-' + key ) || '';
				if ( 'num' === type ) { return ( parseFloat( va ) - parseFloat( vb ) ) * mult; }
				return va.localeCompare( vb ) * mult;
			} );
			rows.forEach( function ( r ) { tbody.appendChild( r ); } );
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
			echo '<h2>' . esc_html( $info->title ? $info->title : $info->url ) . '</h2>';
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
		$this->render_copy_paste( $lang, $cp_rows, $loc, $show_all ? 'all' : 'missing', count( $untranslated ) );

		$this->render_string_table( $lang, $all_rows, $loc, $post_id );
		?>
		<form method="post" action="" style="margin-top:18px"
			onsubmit="return confirm('<?php echo esc_js( __( 'Delete all translations of this page for this language?', 'translate-rocket' ) ); ?>');">
			<?php wp_nonce_field( 'trrocket_delete_page', 'trrocket_delete_nonce' ); ?>
			<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
			<input type="hidden" name="loc" value="<?php echo esc_attr( $loc ); ?>" />
			<button type="submit" class="button button-link-delete" style="color:#b32d2e">
				&#128465; <?php esc_html_e( "Delete this page's translation", 'translate-rocket' ); ?>
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
			$export .= $i . '. ' . $row->original . "\n";
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
		?>
		<div class="trr-eblockwrap">
			<h3><?php echo esc_html( $title ); ?></h3>
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
								<?php echo esc_html( $row->original ); ?>
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
		echo '<button type="button" class="trr-info" aria-label="' . esc_attr__( 'More info', 'translate-rocket' )
			. '" data-info="' . esc_attr( $text ) . '"><span class="dashicons dashicons-info-outline"></span></button>';
	}
}
