<?php
/**
 * Cookie banners in the language of the page (1.8.8, 16/10/2026).
 *
 * iubenda, Cookiebot and Usercentrics have their banner and their policies in every language
 * already, written by lawyers: TranslateRocket only tells them which language the page is in,
 * and never sends that legal text to a translation engine.
 *
 * - iubenda, classic snippet (the one its WordPress plugin writes, `"lang":"it"` fixed): the
 *   language becomes the page's; with «iubenda policy per language» filled in, the policy ID
 *   and the policy links too. The new multilingual snippet (`csLangConfiguration`) already
 *   follows <html lang>, which the engine sets: left exactly as it is.
 * - Cookiebot: `data-culture` on its script and on the cookie declaration.
 * - Usercentrics: `data-language` on its loader.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Consent banners follow the page language.
 */
class ConsentBanners {

	/**
	 * Our codes that iubenda writes differently.
	 */
	const IUBENDA = array( 'pt-br' => 'pt-BR', 'zh' => 'zh-CN', 'zh-tw' => 'zh-TW', 'es-mx' => 'es', 'fr-ca' => 'fr', 'de-ch' => 'de', 'no' => 'nb' );

	/**
	 * The translated page, with the banners told its language.
	 *
	 * @param string $html Finished HTML of a page in a secondary language.
	 * @param string $lang Its language (our code).
	 */
	public static function adapt( string $html, string $lang ): string {
		/**
		 * Whether the cookie banners (iubenda, Cookiebot, Usercentrics) follow the page language.
		 *
		 * @param bool   $on   Default true.
		 * @param string $lang Language of the page.
		 */
		if ( '' === $lang || ! apply_filters( 'trrocket_consent_banner_language', true, $lang ) ) {
			return $html;
		}
		if ( false !== strpos( $html, '_iub' ) ) {
			$html = self::iubenda( $html, $lang );
		}
		if ( false !== stripos( $html, 'cookiebot' ) || false !== stripos( $html, 'CookieDeclaration' ) ) {
			$html = self::attribute( $html, '/<script\b[^>]*\bid=["\'](?:Cookiebot|CookieDeclaration)["\'][^>]*>/i', 'data-culture', strtoupper( substr( $lang, 0, 2 ) ) );
		}
		if ( false !== strpos( $html, 'usercentrics-cmp' ) ) {
			$html = self::attribute( $html, '/<script\b[^>]*\bid=["\']usercentrics-cmp["\'][^>]*>/i', 'data-language', strtolower( substr( $lang, 0, 2 ) ) );
		}
		return $html;
	}

	/**
	 * iubenda's language code for one of ours.
	 */
	public static function iubenda_lang( string $lang ): string {
		$lang = strtolower( $lang );
		return self::IUBENDA[ $lang ] ?? substr( $lang, 0, 2 );
	}

	/**
	 * The policy ID for each language the owner wrote in the settings («en=12345678»).
	 *
	 * @return array<string,string>
	 */
	public static function policies(): array {
		$out = array();
		foreach ( (array) ( Settings::get()['iubenda_policies'] ?? array() ) as $l => $id ) {
			$id = preg_replace( '/\D/', '', (string) $id );
			if ( '' !== $id ) {
				$out[ strtolower( (string) $l ) ] = $id;
			}
		}
		return $out;
	}

	/**
	 * Clean the «language=ID» lines sent by the settings form.
	 *
	 * @param mixed $text Posted text.
	 * @return array<string,string>
	 */
	public static function clean_policies( $text ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n,;]+/', is_string( $text ) ? $text : '' ) as $riga ) {
			if ( preg_match( '/^\s*([a-z]{2,3}(?:-[a-z]{2,4})?)\s*[=:]\s*(\d{4,})\s*$/i', (string) $riga, $m ) ) {
				$out[ strtolower( $m[1] ) ] = $m[2];
			}
		}
		return $out;
	}

	/**
	 * iubenda: the classic snippet's language, and the policy ID with its links when known.
	 */
	private static function iubenda( string $html, string $lang ): string {
		$pos = strpos( $html, '_iub.csConfiguration' );
		if ( false === $pos ) {
			return $html;
		}
		// The new multilingual snippet chooses by <html lang> on its own.
		if ( false !== strpos( $html, 'csLangConfiguration' ) ) {
			return $html;
		}
		$fine = stripos( $html, '</script>', $pos );
		if ( false === $fine ) {
			return $html;
		}
		$conf     = substr( $html, $pos, $fine - $pos );
		$nuova    = preg_replace( '/(["\']?lang["\']?\s*:\s*)(["\'])[A-Za-z_-]{2,8}\2/', '${1}${2}' . self::iubenda_lang( $lang ) . '${2}', $conf, 1 );
		$politica = self::policies()[ strtolower( $lang ) ] ?? '';
		$vecchia  = '';
		if ( '' !== $politica && preg_match( '/["\']?cookiePolicyId["\']?\s*:\s*["\']?(\d+)/', (string) $nuova, $m ) ) {
			$vecchia = $m[1];
			$nuova   = preg_replace( '/(["\']?cookiePolicyId["\']?\s*:\s*["\']?)\d+/', '${1}' . $politica, (string) $nuova, 1 );
		}
		if ( ! is_string( $nuova ) || $nuova === $conf ) {
			return $html;
		}
		$html = substr( $html, 0, $pos ) . $nuova . substr( $html, $fine );
		// The links to the policy (privacy-policy/<id>, privacy-policy/<id>/cookie-policy) to the language's own.
		if ( '' !== $vecchia && $vecchia !== $politica ) {
			$html = preg_replace( '#(iubenda\.com/(?:[a-z-]+/)?privacy-policy/)' . preg_quote( $vecchia, '#' ) . '\b#', '${1}' . $politica, $html );
		}
		return (string) $html;
	}

	/**
	 * Set (or replace) an attribute on the opening tags matched by $pattern.
	 */
	private static function attribute( string $html, string $pattern, string $name, string $value ): string {
		$out = preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $name, $value ) {
				$tag = $m[0];
				$re  = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])[^"\']*\1/i';
				if ( preg_match( $re, $tag ) ) {
					return (string) preg_replace( $re, ' ' . $name . '="' . esc_attr( $value ) . '"', $tag, 1 );
				}
				return substr( $tag, 0, -1 ) . ' ' . $name . '="' . esc_attr( $value ) . '">';
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}
}
