<?php
/**
 * Language catalogue.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Static catalogue of languages the plugin can translate into.
 * code => array( native name, English name, flag emoji ).
 */
class Languages {

	/**
	 * Full catalogue: built-in languages plus any the site owner added.
	 *
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function all(): array {
		return array_merge( self::builtin(), self::custom() );
	}

	/**
	 * Site-owner-defined languages, from settings.
	 * Stored as code => array( native name, English name, flag ).
	 *
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public static function custom(): array {
		$raw = Settings::get()['custom_languages'] ?? array();
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $code => $info ) {
				$code = strtolower( (string) $code );
				if ( '' === $code || ! is_array( $info ) ) {
					continue;
				}
				$out[ $code ] = array(
					(string) ( $info[0] ?? $code ),
					(string) ( $info[1] ?? ( $info[0] ?? $code ) ),
					(string) ( $info[2] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Built-in catalogue of selectable languages.
	 *
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	private static function builtin(): array {
		return array(
			'en'    => array( 'English', 'English', '🇬🇧' ),
			'it'    => array( 'Italiano', 'Italian', '🇮🇹' ),
			'es'    => array( 'Español', 'Spanish', '🇪🇸' ),
			'fr'    => array( 'Français', 'French', '🇫🇷' ),
			'de'    => array( 'Deutsch', 'German', '🇩🇪' ),
			'pt'    => array( 'Português', 'Portuguese', '🇵🇹' ),
			'pt-br' => array( 'Português (BR)', 'Portuguese (Brazil)', '🇧🇷' ),
			'nl'    => array( 'Nederlands', 'Dutch', '🇳🇱' ),
			'ru'    => array( 'Русский', 'Russian', '🇷🇺' ),
			'pl'    => array( 'Polski', 'Polish', '🇵🇱' ),
			'sv'    => array( 'Svenska', 'Swedish', '🇸🇪' ),
			'da'    => array( 'Dansk', 'Danish', '🇩🇰' ),
			'fi'    => array( 'Suomi', 'Finnish', '🇫🇮' ),
			'no'    => array( 'Norsk', 'Norwegian', '🇳🇴' ),
			'cs'    => array( 'Čeština', 'Czech', '🇨🇿' ),
			'el'    => array( 'Ελληνικά', 'Greek', '🇬🇷' ),
			'tr'    => array( 'Türkçe', 'Turkish', '🇹🇷' ),
			'ar'    => array( 'العربية', 'Arabic', '🇸🇦' ),
			'he'    => array( 'עברית', 'Hebrew', '🇮🇱' ),
			'hi'    => array( 'हिन्दी', 'Hindi', '🇮🇳' ),
			'ja'    => array( '日本語', 'Japanese', '🇯🇵' ),
			'ko'    => array( '한국어', 'Korean', '🇰🇷' ),
			'zh'    => array( '简体中文', 'Chinese (Simplified)', '🇨🇳' ),
			'zh-tw' => array( '繁體中文', 'Chinese (Traditional)', '🇹🇼' ),
			'uk'    => array( 'Українська', 'Ukrainian', '🇺🇦' ),
			'ro'    => array( 'Română', 'Romanian', '🇷🇴' ),
			'hu'    => array( 'Magyar', 'Hungarian', '🇭🇺' ),
			'bg'    => array( 'Български', 'Bulgarian', '🇧🇬' ),
			'hr'    => array( 'Hrvatski', 'Croatian', '🇭🇷' ),
			'sk'    => array( 'Slovenčina', 'Slovak', '🇸🇰' ),
			'sl'    => array( 'Slovenščina', 'Slovenian', '🇸🇮' ),
			'th'    => array( 'ไทย', 'Thai', '🇹🇭' ),
			'vi'    => array( 'Tiếng Việt', 'Vietnamese', '🇻🇳' ),
			'id'    => array( 'Bahasa Indonesia', 'Indonesian', '🇮🇩' ),
			'ms'    => array( 'Bahasa Melayu', 'Malay', '🇲🇾' ),
			'fa'    => array( 'فارسی', 'Persian', '🇮🇷' ),
			'bn'    => array( 'বাংলা', 'Bengali', '🇧🇩' ),
		);
	}

	/**
	 * Whether a language code exists in the catalogue.
	 */
	public static function exists( string $code ): bool {
		return isset( self::all()[ strtolower( $code ) ] );
	}

	/**
	 * Whether a language is written right-to-left (so the page needs dir="rtl").
	 */
	public static function is_rtl( string $code ): bool {
		$code = strtolower( $code );
		// Match on the base language (e.g. "ar-eg" -> "ar").
		$base = explode( '-', $code )[0];
		$rtl  = array( 'ar', 'arc', 'az-arab', 'ckb', 'dv', 'fa', 'ha', 'he', 'khw', 'ks', 'ku', 'ps', 'sd', 'ug', 'ur', 'uz-arab', 'yi' );
		return in_array( $code, $rtl, true ) || in_array( $base, $rtl, true );
	}

	/**
	 * Human label for a language code (native name), falling back to the code.
	 */
	public static function label( string $code ): string {
		$code = strtolower( $code );
		$all  = self::all();
		return isset( $all[ $code ] ) ? $all[ $code ][0] : $code;
	}

	/**
	 * English label for a language code, falling back to the code.
	 */
	public static function english_label( string $code ): string {
		$code = strtolower( $code );
		$all  = self::all();
		return isset( $all[ $code ] ) ? $all[ $code ][1] : $code;
	}

	/**
	 * Flag emoji for a language code (empty string if unknown).
	 */
	public static function flag( string $code ): string {
		$code = strtolower( $code );
		$all  = self::all();
		return isset( $all[ $code ] ) ? $all[ $code ][2] : '';
	}

	/**
	 * Full WordPress locale for a short language code (e.g. it => it_IT), so we
	 * can load WordPress' own theme/plugin/core translations for that language.
	 */
	public static function locale( string $code ): string {
		$code = strtolower( $code );
		$map  = array(
			'en'    => 'en_US',
			'it'    => 'it_IT',
			'es'    => 'es_ES',
			'fr'    => 'fr_FR',
			'de'    => 'de_DE',
			'pt'    => 'pt_PT',
			'pt-br' => 'pt_BR',
			'nl'    => 'nl_NL',
			'ru'    => 'ru_RU',
			'pl'    => 'pl_PL',
			'sv'    => 'sv_SE',
			'da'    => 'da_DK',
			'fi'    => 'fi',
			'no'    => 'nb_NO',
			'cs'    => 'cs_CZ',
			'el'    => 'el',
			'tr'    => 'tr_TR',
			'ar'    => 'ar',
			'he'    => 'he_IL',
			'hi'    => 'hi_IN',
			'ja'    => 'ja',
			'ko'    => 'ko_KR',
			'zh'    => 'zh_CN',
			'zh-tw' => 'zh_TW',
			'uk'    => 'uk',
			'ro'    => 'ro_RO',
			'hu'    => 'hu_HU',
			'bg'    => 'bg_BG',
			'hr'    => 'hr',
			'sk'    => 'sk_SK',
			'sl'    => 'sl_SI',
			'th'    => 'th',
			'vi'    => 'vi',
			'id'    => 'id_ID',
			'ms'    => 'ms_MY',
			'fa'    => 'fa_IR',
			'bn'    => 'bn_BD',
		);
		return $map[ $code ] ?? $code;
	}
}
