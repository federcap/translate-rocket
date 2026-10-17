<?php
/**
 * Numbers, money and dates in the language of the plugin's screens (1.8.8, 16/10/2026).
 *
 * The plugin brings its own translations, so its screens can be in Italian while WordPress has no
 * Italian pack installed (or speaks another language): WordPress's number_format_i18n() and
 * date_i18n() then answer in English — «25,981 caratteri», «€0.23», «dal 1 July 2026» on an Italian
 * screen (Federico, 16/10/2026). When WordPress already speaks the plugin's language its own formats
 * are used; otherwise these.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Formats of the plugin's screens.
 */
class Formati {

	/**
	 * Thousands and decimal separators by language (the first two letters of the locale).
	 */
	const SEP = array(
		'it' => array( '.', ',' ), 'es' => array( '.', ',' ), 'de' => array( '.', ',' ), 'pt' => array( '.', ',' ),
		'nl' => array( '.', ',' ), 'id' => array( '.', ',' ), 'tr' => array( '.', ',' ), 'el' => array( '.', ',' ),
		'da' => array( '.', ',' ), 'ro' => array( '.', ',' ), 'hr' => array( '.', ',' ), 'sl' => array( '.', ',' ),
		'fr' => array( "\u{202F}", ',' ), 'ru' => array( "\u{00A0}", ',' ), 'pl' => array( "\u{00A0}", ',' ), 'uk' => array( "\u{00A0}", ',' ),
		'cs' => array( "\u{00A0}", ',' ), 'sk' => array( "\u{00A0}", ',' ), 'sv' => array( "\u{00A0}", ',' ), 'fi' => array( "\u{00A0}", ',' ),
		'nb' => array( "\u{00A0}", ',' ), 'hu' => array( "\u{00A0}", ',' ), 'bg' => array( "\u{00A0}", ',' ),
	);

	/**
	 * How a date is written when WordPress cannot name the months in that language.
	 */
	const DATA = array(
		'de' => 'd.m.Y', 'ru' => 'd.m.Y', 'pl' => 'd.m.Y', 'tr' => 'd.m.Y', 'uk' => 'd.m.Y', 'cs' => 'd.m.Y', 'fi' => 'd.m.Y',
		'nl' => 'd-m-Y', 'ja' => 'Y/m/d', 'zh' => 'Y/m/d', 'ko' => 'Y.m.d', 'hu' => 'Y.m.d',
	);

	/**
	 * The language of the plugin's screens (its own choice, or the profile's).
	 */
	public static function locale(): string {
		$l = PluginLanguage::chosen();
		return '' !== $l ? $l : (string) determine_locale();
	}

	/**
	 * Does WordPress itself speak that language (its formats are right)?
	 */
	private static function wordpress_speaks( string $locale ): bool {
		if ( 0 === strpos( $locale, 'en' ) ) {
			return true;
		}
		global $wp_locale;
		// Without its language pack WordPress names the months in English.
		return isset( $wp_locale ) && is_object( $wp_locale ) && 'January' !== (string) $wp_locale->get_month( 1 ) && 0 === strpos( (string) determine_locale(), substr( $locale, 0, 2 ) );
	}

	/**
	 * A number, «25.981» in Italian.
	 *
	 * @param int|float $n        Number.
	 * @param int       $decimals Decimals.
	 */
	public static function number( $n, int $decimals = 0 ): string {
		$loc = self::locale();
		if ( self::wordpress_speaks( $loc ) ) {
			return number_format_i18n( $n, $decimals );
		}
		$sep = self::SEP[ substr( strtolower( $loc ), 0, 2 ) ] ?? array( ',', '.' );
		return number_format( (float) $n, $decimals, $sep[1], $sep[0] );
	}

	/**
	 * An amount in euro: «0,23 €» where the comma is the decimal mark, «€0.23» elsewhere.
	 *
	 * @param float $eur Amount.
	 */
	public static function euro( float $eur ): string {
		$n   = self::number( $eur, 2 );
		$sep = self::SEP[ substr( strtolower( self::locale() ), 0, 2 ) ] ?? null;
		return null !== $sep ? $n . "\u{00A0}€" : '€' . $n;
	}

	/**
	 * A date from a timestamp.
	 *
	 * @param int $ts Unix timestamp.
	 */
	public static function date( int $ts ): string {
		$loc = self::locale();
		if ( self::wordpress_speaks( $loc ) ) {
			return date_i18n( (string) get_option( 'date_format' ), $ts );
		}
		return wp_date( self::DATA[ substr( strtolower( $loc ), 0, 2 ) ] ?? 'd/m/Y', $ts );
	}
}
