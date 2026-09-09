<?php
/**
 * "Powered by TranslateRocket" attribution badge.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * An optional, opt-in attribution badge with the TranslateRocket logo.
 *
 * WordPress.org forbids a mandatory "powered by" link, so this is OFF by default:
 * it only appears if the site owner enables it in Settings (auto-placed in the
 * footer) or drops the [translaterocket_poweredby] shortcode somewhere. The badge
 * is marked translate="no" so the engine leaves the brand line untranslated.
 */
class PoweredBy {

	/**
	 * Register the shortcode(s) and, if enabled, the automatic footer badge.
	 */
	public function boot(): void {
		// Shortcode names are matched case-sensitively, so register both the
		// canonical lower-case tag and the upper-case form.
		add_shortcode( 'translaterocket_poweredby', array( $this, 'shortcode' ) );
		add_shortcode( 'TRANSLATEROCKET_POWEREDBY', array( $this, 'shortcode' ) );

		if ( is_admin() ) {
			return;
		}
		if ( ! empty( Settings::get()['show_poweredby'] ) ) {
			add_action( 'wp_footer', array( $this, 'render_footer' ), 99 );
		}
	}

	/**
	 * Shortcode handler: returns the inline badge for manual placement.
	 *
	 * @param mixed $atts Shortcode attributes (unused).
	 */
	public function shortcode( $atts = array() ): string {
		unset( $atts );
		return self::badge();
	}

	/**
	 * Automatic placement: a centered badge at the bottom of the page.
	 */
	public function render_footer(): void {
		echo wp_kses( '<div class="trrocket-poweredby-wrap" style="text-align:center;padding:14px 10px;">' . self::badge() . '</div>' , \TranslateRocket\Kses::html_rules() );
	}

	/**
	 * The badge markup: logo + "Powered by TranslateRocket", linking to the site.
	 */
	public static function badge(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$url  = 'https://translaterocket.com/?utm_source=poweredby&utm_medium=badge&site=' . rawurlencode( $host );

		return sprintf(
			'<a class="trrocket-poweredby" translate="no" href="%1$s" target="_blank" rel="noopener nofollow"'
			. ' style="display:inline-flex;align-items:center;gap:6px;font-size:12px;line-height:1.2;'
			. 'color:inherit;opacity:.8;text-decoration:none;vertical-align:middle;">%2$s'
			. '<span>%3$s <strong>TranslateRocket™</strong></span></a>',
			esc_url( $url ),
			self::logo(),
			esc_html__( 'Powered by', 'translate-rocket' )
		);
	}

	/**
	 * The brand mark, from the icon the plugin already ships.
	 *
	 * It used to draw a trimmed navy rocket inline: recognisable to nobody, and
	 * a different mark from the one on the plugin page and the site. This is the
	 * real icon, so the badge finally looks like the product it points at.
	 */
	private static function logo(): string {
		return sprintf(
			'<img src="%s" width="16" height="16" alt="" loading="lazy" decoding="async"'
			. ' style="border-radius:4px;display:block;flex:0 0 auto;" />',
			esc_url( TRROCKET_URL . 'assets/img/icon.svg' )
		);
	}
}
