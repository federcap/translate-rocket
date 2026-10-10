<?php
/**
 * The language of TranslateRocket's own screens, chosen per user.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A menu in the plugin's header to read TranslateRocket in another language than the
 * WordPress profile (3/10/2026, Federico: «I read the menus in English but I am Italian»).
 * Many people never find Users → Profile → Language; this changes only the plugin's own
 * texts, so the menu says how to have the whole dashboard in that language too.
 */
class PluginLanguage {

	const META   = 'trrocket_admin_locale';
	const ACTION = 'trrocket_admin_locale';

	/**
	 * The languages the plugin ships a catalogue for, by locale, in their own name.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		return array(
			'en_US' => 'English',
			'it_IT' => 'Italiano',
			'es_ES' => 'Español',
			'fr_FR' => 'Français',
			'de_DE' => 'Deutsch',
			'pt_BR' => 'Português (Brasil)',
			'nl_NL' => 'Nederlands',
			'pl_PL' => 'Polski',
			'ru_RU' => 'Русский',
			'ja_JP' => '日本語',
		);
	}

	/**
	 * Hooks. Registered before 'init', when the catalogue is loaded (translate-rocket.php).
	 */
	public function register(): void {
		add_filter( 'plugin_locale', array( $this, 'locale' ), 20, 2 );
		// Da WordPress 6.7 load_plugin_textdomain() registra solo la cartella e il catalogo arriva
		// «al primo uso» nella lingua del profilo, senza passare da plugin_locale: si ricarica qui,
		// subito dopo (translate-rocket.php lo registra su init a priorita' 0).
		add_action( 'init', array( $this, 'load' ), 1 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	/**
	 * In the dashboard, put TranslateRocket's catalogue in the chosen language.
	 */
	public function load(): void {
		$l = self::chosen();
		if ( '' === $l ) {
			// 9/10/2026: WordPress' Japanese is «ja», our file is «ja_JP»: following the profile, the whole
			// plugin stayed in English for every Japanese site (found by collaudo-foto-italiano).
			if ( 'ja' !== determine_locale() || is_readable( TRROCKET_PATH . 'languages/translate-rocket-ja.mo' ) ) {
				return;
			}
			$l = 'ja_JP';
		} elseif ( ! is_admin() ) {
			return;
		}
		global $l10n;
		unload_textdomain( 'translate-rocket' );
		if ( 'en_US' === $l ) {
			$l10n['translate-rocket'] = new \NOOP_Translations(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- the original English, on purpose.
			return;
		}
		// Registered under the request's own locale: since WordPress 6.5 a catalogue is only read for
		// the locale in use, so an Italian file filed as it_IT stays silent in an English dashboard.
		foreach ( array(
			WP_LANG_DIR . '/plugins/translate-rocket-' . $l . '.mo',
			TRROCKET_PATH . 'languages/translate-rocket-' . $l . '.mo',
		) as $mo ) {
			if ( is_readable( $mo ) && load_textdomain( 'translate-rocket', $mo, determine_locale() ) ) {
				return;
			}
		}
	}

	/**
	 * This user's choice ('' = follow the WordPress profile).
	 */
	public static function chosen(): string {
		$id = get_current_user_id();
		if ( ! $id ) {
			return '';
		}
		$l = (string) get_user_meta( $id, self::META, true );
		return isset( self::choices()[ $l ] ) ? $l : '';
	}

	/**
	 * The locale TranslateRocket's catalogue is loaded in, in the dashboard only.
	 *
	 * @param mixed  $locale Locale WordPress would use.
	 * @param string $domain Text domain.
	 * @return mixed
	 */
	public function locale( $locale, $domain = '' ) {
		if ( 'translate-rocket' !== $domain || ! is_admin() ) {
			return $locale;
		}
		$l = self::chosen();
		return '' !== $l ? $l : $locale;
	}

	/**
	 * Save the choice from the header menu and go back to the same screen.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'translate-rocket' ) );
		}
		check_admin_referer( self::ACTION );
		$l = isset( $_POST['trrocket_admin_locale'] ) ? sanitize_text_field( wp_unslash( $_POST['trrocket_admin_locale'] ) ) : '';
		if ( '' === $l || ! isset( self::choices()[ $l ] ) ) {
			delete_user_meta( get_current_user_id(), self::META );
		} else {
			update_user_meta( get_current_user_id(), self::META, $l );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=translate-rocket' ) );
		exit;
	}

	/**
	 * The menu, for the plugin's header.
	 */
	public static function render(): void {
		$current = self::chosen();
		$help    = __( 'For the whole dashboard in your language: Users → Profile → Language.', 'translate-rocket' );
		echo '<form class="trr-plugin-lang" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" title="' . esc_attr( $help ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<label><span class="dashicons dashicons-translation" aria-hidden="true"></span> <span class="trr-plugin-lang-label">' . esc_html__( 'Plugin language', 'translate-rocket' ) . '</span> ';
		echo '<select name="trrocket_admin_locale" onchange="this.form.submit()">';
		echo '<option value=""' . selected( $current, '', false ) . '>' . esc_html__( 'Automatic (your profile)', 'translate-rocket' ) . '</option>';
		foreach ( self::choices() as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '" lang="' . esc_attr( str_replace( '_', '-', $code ) ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></label>';
		echo '<noscript><button type="submit" class="button button-small">' . esc_html__( 'Save', 'translate-rocket' ) . '</button></noscript>';
		echo '</form>';
	}
}
