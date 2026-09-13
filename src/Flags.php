<?php
/**
 * Original SVG flags for the language switcher.
 *
 * Emoji flags don't render on Windows (they show as "IT", "GB"…), so we ship
 * our own simple SVGs. National flag designs are public domain; this SVG code is
 * original. Languages without an SVG here fall back to the emoji.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Returns inline SVG markup for a language's flag (viewBox 0 0 30 20).
 */
class Flags {

	/**
	 * Inline SVG for a code, or '' if we don't have one (caller uses the emoji).
	 */
	public static function svg( string $code ): string {
		$code  = strtolower( $code );
		$inner = self::map()[ $code ] ?? '';
		if ( '' === $inner ) {
			return '';
		}
		return '<svg class="trrocket-flag-svg" width="21" height="14" viewBox="0 0 30 20" role="img" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">' . $inner . '</svg>';
	}

	/**
	 * The flag codes we ship an SVG for — used to build the custom-language picker.
	 *
	 * @return string[]
	 */
	public static function codes(): array {
		return array_keys( self::map() );
	}

	/**
	 * Ready-to-echo flag HTML for a language: our SVG if we have one for the code,
	 * else the custom value (an "svg:xx" reference picks one of our SVGs). Returns
	 * '' when there is no SVG, so the caller can fall back to an (escaped) emoji.
	 *
	 * @param string $code   Language code (e.g. "it", "la").
	 * @param string $custom Stored custom flag value ("svg:fr", an emoji, or "").
	 */
	public static function markup( string $code, string $custom = '' ): string {
		$svg = self::svg( $code );
		if ( '' !== $svg ) {
			return $svg;
		}
		if ( 0 === strpos( $custom, 'svg:' ) ) {
			return self::svg( substr( $custom, 4 ) );
		}
		if ( 0 === strpos( $custom, 'url:' ) ) {
			$url = substr( $custom, 4 );
			if ( '' !== $url ) {
				return '<img class="trrocket-flag-svg trrocket-flag-img" src="' . esc_url( $url ) . '" width="21" height="14" alt="" loading="lazy" />';
			}
		}
		return '';
	}

	/**
	 * Fully escaped, ready-to-echo flag: SVG markup if we have one, otherwise the
	 * custom emoji (escaped) or a globe. Safe to print directly.
	 *
	 * @param string $code   Language code.
	 * @param string $custom Stored custom flag value.
	 */
	public static function html( string $code, string $custom = '' ): string {
		$m = self::markup( $code, $custom );
		if ( '' !== $m ) {
			return $m;
		}
		$emoji = ( '' !== $custom && 0 !== strpos( $custom, 'svg:' ) ) ? $custom : "\xF0\x9F\x8C\x90";
		return esc_html( $emoji );
	}

	/**
	 * code => inner SVG markup (local coordinates 0..30 wide, 0..20 tall).
	 *
	 * @return array<string,string>
	 */
	private static function map(): array {
		return array(
			'en'    => '<rect width="30" height="20" fill="#012169"/><path d="M0,0 L30,20 M30,0 L0,20" stroke="#fff" stroke-width="4" fill="none"/><path d="M0,0 L30,20 M30,0 L0,20" stroke="#C8102E" stroke-width="1.6" fill="none"/><rect x="12" width="6" height="20" fill="#fff"/><rect y="7" width="30" height="6" fill="#fff"/><rect x="13.2" width="3.6" height="20" fill="#C8102E"/><rect y="8.2" width="30" height="3.6" fill="#C8102E"/>',
			'it'    => '<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#009246"/><rect x="20" width="10" height="20" fill="#ce2b37"/>',
			'es'    => '<rect width="30" height="20" fill="#AA151B"/><rect y="5" width="30" height="10" fill="#F1BF00"/>',
			'fr'    => '<rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#002395"/><rect x="20" width="10" height="20" fill="#ED2939"/>',
			'de'    => '<rect width="30" height="20" fill="#FFCE00"/><rect width="30" height="6.67" fill="#000"/><rect y="6.67" width="30" height="6.67" fill="#DD0000"/>',
			'pt'    => '<rect width="30" height="20" fill="#DA291C"/><rect width="12" height="20" fill="#046A38"/><circle cx="12" cy="10" r="2.6" fill="#FFE000"/>',
			'pt-br' => '<rect width="30" height="20" fill="#009C3B"/><polygon points="15,2.5 27,10 15,17.5 3,10" fill="#FFDF00"/><circle cx="15" cy="10" r="3.6" fill="#002776"/>',
			'nl'    => '<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#AE1C28"/><rect y="13.33" width="30" height="6.67" fill="#21468B"/>',
			'ru'    => '<rect width="30" height="20" fill="#fff"/><rect y="6.67" width="30" height="6.67" fill="#0039A6"/><rect y="13.33" width="30" height="6.67" fill="#D52B1E"/>',
			'pl'    => '<rect width="30" height="20" fill="#fff"/><rect y="10" width="30" height="10" fill="#DC143C"/>',
			'uk'    => '<rect width="30" height="20" fill="#FFD500"/><rect width="30" height="10" fill="#005BBB"/>',
			'ja'    => '<rect width="30" height="20" fill="#fff"/><circle cx="15" cy="10" r="5.5" fill="#BC002D"/>',
			'zh'    => '<rect width="30" height="20" fill="#EE1C25"/><polygon points="5.00,2.00 5.67,4.07 7.85,4.07 6.09,5.35 6.76,7.43 5.00,6.15 3.24,7.43 3.91,5.35 2.15,4.07 4.33,4.07" fill="#FFDE00"/><polygon points="9.14,2.51 9.62,1.97 9.25,1.34 9.91,1.63 10.39,1.08 10.33,1.80 11.00,2.09 10.29,2.25 10.22,2.97 9.85,2.35" fill="#FFDE00"/><polygon points="11.01,4.14 11.66,3.82 11.56,3.10 12.07,3.62 12.72,3.30 12.38,3.95 12.88,4.47 12.17,4.34 11.83,4.99 11.73,4.27" fill="#FFDE00"/><polygon points="11.04,6.73 11.76,6.70 11.96,6.00 12.21,6.68 12.94,6.66 12.37,7.10 12.62,7.79 12.01,7.38 11.44,7.83 11.64,7.13" fill="#FFDE00"/><polygon points="9.22,8.38 9.90,8.63 10.35,8.06 10.32,8.79 11.00,9.05 10.30,9.24 10.26,9.96 9.87,9.36 9.16,9.55 9.62,8.98" fill="#FFDE00"/>',
			'sv'    => '<rect width="30" height="20" fill="#006AA7"/><rect x="9" width="4" height="20" fill="#FECC00"/><rect y="8" width="30" height="4" fill="#FECC00"/>',
			'da'    => '<rect width="30" height="20" fill="#C8102E"/><rect x="9" width="4" height="20" fill="#fff"/><rect y="8" width="30" height="4" fill="#fff"/>',
			'no'    => '<rect width="30" height="20" fill="#BA0C2F"/><rect x="8" width="6" height="20" fill="#fff"/><rect y="7" width="30" height="6" fill="#fff"/><rect x="9.5" width="3" height="20" fill="#00205B"/><rect y="8.5" width="30" height="3" fill="#00205B"/>',
			'fi'    => '<rect width="30" height="20" fill="#fff"/><rect x="9" width="4" height="20" fill="#003580"/><rect y="8" width="30" height="4" fill="#003580"/>',
			'ro'    => '<rect width="10" height="20" fill="#002B7F"/><rect x="10" width="10" height="20" fill="#FCD116"/><rect x="20" width="10" height="20" fill="#CE1126"/>',
			'hu'    => '<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#CD2A3E"/><rect y="13.33" width="30" height="6.67" fill="#436F4D"/>',
			'bg'    => '<rect width="30" height="20" fill="#fff"/><rect y="6.67" width="30" height="6.67" fill="#00966E"/><rect y="13.33" width="30" height="6.67" fill="#D62612"/>',
			'id'    => '<rect width="30" height="20" fill="#fff"/><rect width="30" height="10" fill="#CE1126"/>',
			'th'    => '<rect width="30" height="20" fill="#A51931"/><rect y="3.33" width="30" height="3.34" fill="#fff"/><rect y="6.67" width="30" height="6.66" fill="#2D2A4A"/><rect y="13.33" width="30" height="3.34" fill="#fff"/>',
			'cs'    => '<rect width="30" height="20" fill="#fff"/><rect y="10" width="30" height="10" fill="#D7141A"/><polygon points="0,0 15,10 0,20" fill="#11457E"/>',
			'el'    => '<rect width="30" height="20" fill="#0D5EAF"/><rect y="2.22" width="30" height="2.22" fill="#fff"/><rect y="6.67" width="30" height="2.22" fill="#fff"/><rect y="11.11" width="30" height="2.22" fill="#fff"/><rect y="15.56" width="30" height="2.22" fill="#fff"/><rect width="11.11" height="11.11" fill="#0D5EAF"/><rect x="4.44" width="2.22" height="11.11" fill="#fff"/><rect y="4.44" width="11.11" height="2.22" fill="#fff"/>',
			'tr'    => '<rect width="30" height="20" fill="#E30A17"/><circle cx="11" cy="10" r="5" fill="#fff"/><circle cx="12.6" cy="10" r="4" fill="#E30A17"/><polygon points="18,7.7 18.54,9.26 20.19,9.29 18.87,10.28 19.35,11.86 18,10.92 16.65,11.86 17.12,10.28 15.81,9.29 17.46,9.26" fill="#fff"/>',
			// Arabic deliberately has NO national flag. It is official in more than twenty
			// countries, so no single flag is right; and the one usually chosen for it, Saudi
			// Arabia's, carries the shahada — the Islamic declaration of faith — which should
			// not be redrawn, simplified or used as decoration. A neutral code chip is honest
			// and offends nobody. Site owners who do want a country flag can still set one per
			// language (custom flag: "svg:xx", an emoji, or "url:…").
			'ar'    => '<rect width="30" height="20" rx="3" fill="#334155"/><text x="15" y="14.3" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif" font-size="11.5" font-weight="700" letter-spacing="0.5" fill="#f1f5f9">AR</text>',
			'he'    => '<rect width="30" height="20" fill="#fff"/><rect y="3" width="30" height="2.3" fill="#0038B8"/><rect y="14.7" width="30" height="2.3" fill="#0038B8"/><polygon points="15,6.4 12.1,11.4 17.9,11.4" fill="none" stroke="#0038B8" stroke-width="0.7"/><polygon points="15,13.6 12.1,8.6 17.9,8.6" fill="none" stroke="#0038B8" stroke-width="0.7"/>',
			'hi'    => '<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#FF9933"/><rect y="13.33" width="30" height="6.67" fill="#138808"/><circle cx="15" cy="10" r="2.3" fill="none" stroke="#000080" stroke-width="0.5"/><circle cx="15" cy="10" r="0.4" fill="#000080"/><path d="M15,7.7V12.3M12.7,10H17.3M13.4,8.4 16.6,11.6M16.6,8.4 13.4,11.6" stroke="#000080" stroke-width="0.3"/>',
			'ko'    => '<rect width="30" height="20" fill="#fff"/><path d="M15,5 a5,5 0 0,1 0,10 a2.5,2.5 0 0,1 0,-5 a2.5,2.5 0 0,0 0,-5 z" fill="#CD2E3A"/><path d="M15,5 a5,5 0 0,0 0,10 a2.5,2.5 0 0,0 0,-5 a2.5,2.5 0 0,1 0,-5 z" fill="#0047A0"/>',
			'zh-tw' => '<rect width="30" height="20" fill="#FE0000"/><rect width="15" height="10" fill="#000095"/><circle cx="7.5" cy="5" r="2.7" fill="#fff"/><circle cx="7.5" cy="5" r="1.8" fill="#000095"/>',
			'hr'    => '<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#FF0000"/><rect y="13.33" width="30" height="6.67" fill="#171796"/><rect x="12.8" y="6.3" width="4.4" height="4.4" fill="#fff" stroke="#FF0000" stroke-width="0.3"/><rect x="12.8" y="6.3" width="2.2" height="2.2" fill="#FF0000"/><rect x="15" y="8.5" width="2.2" height="2.2" fill="#FF0000"/>',
			'sk'    => '<rect width="30" height="20" fill="#fff"/><rect y="6.67" width="30" height="6.67" fill="#0B4EA2"/><rect y="13.33" width="30" height="6.67" fill="#EE1C25"/><path d="M5,5 h6 v4.5 q0,2.8 -3,3.8 q-3,-1 -3,-3.8 z" fill="#EE1C25" stroke="#fff" stroke-width="0.5"/><path d="M8,6.3V11M6.3,8H9.7" stroke="#fff" stroke-width="0.7"/>',
			'sl'    => '<rect width="30" height="20" fill="#fff"/><rect y="6.67" width="30" height="6.67" fill="#0000C6"/><rect y="13.33" width="30" height="6.67" fill="#FF0000"/><path d="M4,4.5 h6 v4 q0,2.5 -3,3.5 q-3,-1 -3,-3.5 z" fill="#0000C6" stroke="#fff" stroke-width="0.5"/>',
			'vi'    => '<rect width="30" height="20" fill="#DA251D"/><polygon points="15,5 16.23,8.30 19.76,8.46 17.0,10.65 17.94,14.05 15,12.1 12.06,14.05 13.0,10.65 10.24,8.46 13.77,8.30" fill="#FFFF00"/>',
			'ms'    => '<rect width="30" height="20" fill="#CC0001"/><rect y="1.43" width="30" height="1.43" fill="#fff"/><rect y="4.29" width="30" height="1.43" fill="#fff"/><rect y="7.14" width="30" height="1.43" fill="#fff"/><rect y="10" width="30" height="1.43" fill="#fff"/><rect y="12.86" width="30" height="1.43" fill="#fff"/><rect y="15.71" width="30" height="1.43" fill="#fff"/><rect y="18.57" width="30" height="1.43" fill="#fff"/><rect width="15" height="11.43" fill="#010066"/><circle cx="6" cy="5.7" r="3" fill="#FFCC00"/><circle cx="7.3" cy="5.7" r="2.4" fill="#010066"/><polygon points="10.8,4.1 11.18,5.17 12.32,5.21 11.42,5.9 11.74,6.99 10.8,6.35 9.86,6.99 10.18,5.9 9.28,5.21 10.42,5.17" fill="#FFCC00"/>',
			'fa'    => '<rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#239F40"/><rect y="13.33" width="30" height="6.67" fill="#DA0000"/><circle cx="15" cy="10" r="1.3" fill="none" stroke="#DA0000" stroke-width="0.5"/><rect x="14.7" y="8.4" width="0.6" height="3.2" fill="#DA0000"/>',
			'bn'    => '<rect width="30" height="20" fill="#006A4E"/><circle cx="13.5" cy="10" r="5" fill="#F42A41"/>',
		);
	}
}
