<?php
/**
 * Side-by-side mode: TranslateRocket installed next to another translation plugin.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * While WPML, Polylang, TranslatePress or another translation plugin is active,
 * TranslateRocket leaves the public site alone: no language addresses, no
 * interface language, no switcher, hreflang, sitemap or redirects. Visitors keep
 * seeing the site the other plugin serves. The administrator can meanwhile import
 * that plugin's translations, complete them and preview any page with
 * ?trr-preview=xx.
 *
 * When the other plugin is deactivated, TranslateRocket takes over the language
 * addresses in preview mode (translations visible to administrators only) and
 * offers one button to put them online for everyone.
 */
final class Coexistence {

	/**
	 * Query argument an administrator uses to preview a language side by side.
	 */
	const PARAM = 'trr-preview';

	/**
	 * Translation plugins seen active on the last admin request.
	 */
	const SEEN = 'trrocket_coexist_seen';

	/**
	 * Names of the plugin(s) just deactivated, while the "go online" step is open.
	 */
	const PENDING = 'trrocket_golive_pending';

	/**
	 * Rewrite rules to rebuild on the next request (the sitemap rule).
	 */
	const FLUSH = 'trrocket_flush_rewrites';

	/**
	 * Active plugins for this request.
	 *
	 * @var array<string,string>|null
	 */
	private static $active = null;

	/**
	 * Every translation plugin TranslateRocket knows, with how to tell it is running.
	 *
	 * Detected by each plugin's own constant, class or function, so it works whatever
	 * its folder is called. Checked on plugins_loaded, when every plugin file is loaded.
	 *
	 * @return array<string,array{0:string,1:bool}> id => [name, active].
	 */
	private static function known(): array {
		return array(
			'wpml'           => array( 'WPML', defined( 'ICL_SITEPRESS_VERSION' ) ),
			'polylang'       => array( 'Polylang', defined( 'POLYLANG_VERSION' ) || defined( 'POLYLANG_BASENAME' ) ),
			'translatepress' => array( 'TranslatePress', class_exists( 'TRP_Translate_Press', false ) ),
			'weglot'         => array( 'Weglot', defined( 'WEGLOT_VERSION' ) ),
			'gtranslate'     => array( 'GTranslate', defined( 'GTRANSLATE_VERSION' ) || class_exists( 'GTranslate', false ) ),
			'qtranslate'     => array( 'qTranslate-XT', defined( 'QTX_VERSION' ) ),
			'wpglobus'       => array( 'WPGlobus', defined( 'WPGLOBUS_VERSION' ) ),
			'wpm'            => array( 'WP Multilang', defined( 'WPM_PLUGIN_FILE' ) || class_exists( 'WP_Multilang', false ) ),
			'bogo'           => array( 'Bogo', defined( 'BOGO_VERSION' ) ),
			'multilanguage'  => array( 'Multilanguage', function_exists( 'mltlngg_init' ) ),
		);
	}

	/**
	 * Translation plugins active right now.
	 *
	 * @return array<string,string> id => name.
	 */
	public static function active(): array {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$found = array();
		foreach ( self::known() as $id => $plugin ) {
			if ( $plugin[1] ) {
				$found[ $id ] = $plugin[0];
			}
		}
		/**
		 * Translation plugins TranslateRocket should run side by side with.
		 *
		 * Return an empty array to let TranslateRocket serve the site anyway, for
		 * example when the other plugin is only kept for something unrelated.
		 *
		 * @param array<string,string> $found id => name of the plugins detected.
		 */
		self::$active = (array) apply_filters( 'trrocket_coexistence_plugins', $found );
		return self::$active;
	}

	/**
	 * Is TranslateRocket running side by side with another translation plugin?
	 */
	public static function on(): bool {
		return ! empty( self::active() );
	}

	/**
	 * Is the plugin behind this importer still active? (Its separate pages are then
	 * still the ones visitors see, so they must not be tidied up yet.)
	 */
	public static function importer_active( string $importer_id ): bool {
		return isset( self::active()[ $importer_id ] );
	}

	/**
	 * Language an administrator is previewing with ?trr-preview=xx, or ''.
	 */
	public static function preview_language(): string {
		if ( ! self::on() || is_admin() || empty( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview switch.
			return '';
		}
		/** This filter is documented in src/Frontend/Preview.php */
		if ( ! current_user_can( apply_filters( 'trrocket_preview_capability', 'manage_options' ) ) ) {
			return '';
		}
		$lang    = strtolower( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$targets = array_map( 'strtolower', (array) ( Settings::get()['target_languages'] ?? array() ) );
		return in_array( $lang, $targets, true ) ? $lang : '';
	}

	/**
	 * Hooks.
	 */
	public static function boot(): void {
		if ( self::on() ) {
			// Links inside a previewed page must not be rewritten to /xx/: those
			// addresses belong to the other plugin.
			add_filter( 'trrocket_localize_content_links', '__return_false' );
			if ( ! is_admin() ) {
				add_action( 'wp_head', array( __CLASS__, 'noindex_preview' ), 1 );
			}
		}
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'track' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_go_live' ) );
			add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		}
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
	}

	/**
	 * A preview page is for the administrator only: never index it.
	 */
	public static function noindex_preview(): void {
		if ( '' !== self::preview_language() ) {
			echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
		}
	}

	/**
	 * Notice the moment the other plugin goes away.
	 *
	 * WordPress deactivates a plugin and then reloads the Plugins screen, where the
	 * plugin's code is no longer loaded: that is the first request that can tell.
	 */
	public static function track(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active = self::active();
		if ( ! empty( $active ) ) {
			if ( get_option( self::SEEN ) !== $active ) {
				update_option( self::SEEN, $active, false );
			}
			delete_option( self::PENDING );
			return;
		}

		$seen = get_option( self::SEEN );
		if ( empty( $seen ) || ! is_array( $seen ) ) {
			return;
		}
		delete_option( self::SEEN );

		$settings = Settings::get();
		if ( empty( $settings['target_languages'] ) ) {
			return; // Nothing prepared in TranslateRocket: nothing to put online.
		}
		// Take over the language addresses, but show the translations to
		// administrators only until someone has looked at them.
		if ( 'admins' !== ( $settings['serve_mode'] ?? 'everyone' ) ) {
			$settings['serve_mode'] = 'admins';
			update_option( Settings::OPTION, $settings );
		}
		update_option( self::PENDING, array_values( $seen ), false );
		update_option( self::FLUSH, 1, false );
		Cache::flush();
	}

	/**
	 * The "go online" button.
	 */
	public static function maybe_go_live(): void {
		if ( empty( $_GET['trrocket_golive'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'trrocket_golive' );

		$settings               = Settings::get();
		$settings['serve_mode'] = 'everyone';
		update_option( Settings::OPTION, $settings );
		delete_option( self::PENDING );
		update_option( self::FLUSH, 1, false );
		Cache::flush();

		wp_safe_redirect( add_query_arg( 'trrocket_live', '1', remove_query_arg( array( 'trrocket_golive', '_wpnonce' ) ) ) );
		exit;
	}

	/**
	 * Rebuild rewrite rules once, after the plugin took over the language addresses.
	 */
	public static function maybe_flush_rewrites(): void {
		if ( ! get_option( self::FLUSH ) ) {
			return;
		}
		delete_option( self::FLUSH );
		flush_rewrite_rules( false );
	}

	/**
	 * First language to preview, or '' if none is set up yet.
	 */
	private static function first_target(): string {
		$targets = (array) ( Settings::get()['target_languages'] ?? array() );
		return empty( $targets ) ? '' : strtolower( (string) reset( $targets ) );
	}

	/**
	 * Names joined for a sentence ("Polylang", "WPML and Polylang").
	 *
	 * @param string[] $names Plugin names.
	 */
	private static function join_names( array $names ): string {
		$names = array_values( array_map( 'strval', $names ) );
		if ( count( $names ) < 2 ) {
			return (string) reset( $names );
		}
		$last = array_pop( $names );
		/* translators: 1: comma-separated plugin names, 2: the last plugin name. */
		return sprintf( __( '%1$s and %2$s', 'translate-rocket' ), implode( ', ', $names ), $last );
	}

	/**
	 * Admin notices for the three moments: side by side, just deactivated, online.
	 */
	public static function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['trrocket_live'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'TranslateRocket is online.', 'translate-rocket' ),
				esc_html__( 'Visitors now see the translations, the language switcher and the translated addresses.', 'translate-rocket' )
			);
		}

		if ( self::on() ) {
			$names  = self::join_names( self::active() );
			$target = self::first_target();
			echo '<div class="notice notice-info"><p><strong>';
			esc_html_e( 'TranslateRocket is running side by side — your site is not affected.', 'translate-rocket' );
			echo '</strong> ';
			printf(
				/* translators: %s: the other translation plugin(s), e.g. "Polylang". */
				esc_html__( '%s is active, so your visitors keep seeing the site exactly as before: TranslateRocket does not change addresses, languages, menus or the language switcher while it is on.', 'translate-rocket' ),
				esc_html( $names )
			);
			echo ' ';
			esc_html_e( 'Meanwhile you can import its translations, complete them and preview any page. When you deactivate it, you can put TranslateRocket online with one click.', 'translate-rocket' );
			echo '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=translate-rocket-import' ) ) . '">';
			esc_html_e( 'Import translations', 'translate-rocket' );
			echo '</a> ';
			if ( '' !== $target ) {
				echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( add_query_arg( self::PARAM, $target, home_url( '/' ) ) ) . '">';
				esc_html_e( 'Preview the translated site', 'translate-rocket' );
				echo '</a>';
			} else {
				echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=translate-rocket' ) ) . '">';
				esc_html_e( 'Choose your languages', 'translate-rocket' );
				echo '</a>';
			}
			echo '</p></div>';
			return;
		}

		$pending = get_option( self::PENDING );
		if ( ! empty( $pending ) && is_array( $pending ) ) {
			$target = self::first_target();
			echo '<div class="notice notice-warning"><p><strong>';
			printf(
				/* translators: %s: the translation plugin(s) just deactivated. */
				esc_html__( '%s is deactivated. TranslateRocket has taken over the translated pages — visible only to administrators for now.', 'translate-rocket' ),
				esc_html( self::join_names( $pending ) )
			);
			echo '</strong> ';
			esc_html_e( 'Visitors still see the original language. Look at a few pages on their real addresses, then put the translations online for everyone.', 'translate-rocket' );
			echo '</p><p><a class="button button-primary" href="' . esc_url( wp_nonce_url( add_query_arg( 'trrocket_golive', '1' ), 'trrocket_golive' ) ) . '">';
			esc_html_e( 'Go online', 'translate-rocket' );
			echo '</a> ';
			if ( '' !== $target ) {
				echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( rtrim( (string) get_option( 'home' ), '/' ) . '/' . $target . '/' ) . '">';
				esc_html_e( 'Check the translated pages', 'translate-rocket' );
				echo '</a>';
			}
			echo '</p></div>';
		}
	}
}
