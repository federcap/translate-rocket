<?php
/**
 * Best-effort one-click translation via Google's free (unofficial) endpoint.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * A tiny wrapper around Google's public "gtx" translate endpoint. It needs no
 * API key and is meant as a convenience "fill this field for me" button in the
 * editors — not a bulk engine (that is the AI provider's job, with usage limits
 * and quality control). All calls are server-side via wp_remote_get because the
 * endpoint cannot be reached from the browser (CORS) and Google's UI cannot be
 * framed (X-Frame-Options).
 */
class GoogleFree {

	/**
	 * Whether the one-click automatic translation (Google's free, *unofficial*
	 * endpoint) is allowed to run. OFF by default: it is an undocumented endpoint
	 * and querying it programmatically is a grey area in Google's terms, so the
	 * public plugin never calls it on its own. A site owner can switch it on for
	 * their own use with the TRROCKET_AUTO_GOOGLE constant or the filter, accepting
	 * that it is best-effort and may stop working. The manual copy-paste flow and
	 * the official API providers are always available and fully sanctioned.
	 */
	public static function auto_enabled(): bool {
		$on = defined( 'TRROCKET_AUTO_GOOGLE' ) && TRROCKET_AUTO_GOOGLE;
		return (bool) apply_filters( 'trrocket_auto_google', $on );
	}

	/**
	 * A per-phrase link to Google Translate, prefilled (source -> target) — the
	 * lawful helper: the admin clicks it and translates on Google's own site.
	 *
	 * @param string $text   Source text.
	 * @param string $source Source language code.
	 * @param string $target Target language code.
	 */
	public static function link( string $text, string $source, string $target ): string {
		return 'https://translate.google.com/?sl=' . rawurlencode( self::gcode( $source ) )
			. '&tl=' . rawurlencode( self::gcode( $target ) )
			. '&text=' . rawurlencode( $text ) . '&op=translate';
	}

	/**
	 * Translate one string. Returns the translation, or false on any failure.
	 *
	 * @param string $text   Source text.
	 * @param string $source Source language code.
	 * @param string $target Target language code.
	 * @return string|false
	 */
	public static function translate( string $text, string $source, string $target ) {
		$text = trim( $text );
		if ( '' === $text || '' === $target ) {
			return false;
		}

		$url = 'https://translate.googleapis.com/translate_a/single?client=gtx'
			. '&sl=' . rawurlencode( self::gcode( $source ) )
			. '&tl=' . rawurlencode( self::gcode( $target ) )
			. '&dt=t&q=' . rawurlencode( $text );

		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'User-Agent' => 'Mozilla/5.0' ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return false;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || ! isset( $data[0] ) || ! is_array( $data[0] ) ) {
			return false;
		}
		$out = '';
		foreach ( $data[0] as $seg ) {
			if ( isset( $seg[0] ) ) {
				$out .= (string) $seg[0];
			}
		}
		$out = trim( $out );
		return '' !== $out ? $out : false;
	}

	/**
	 * Translate a string that may contain inline HTML (<a>, <strong>, <br>…).
	 * The tags are swapped for numbered markers so Google doesn't mangle them,
	 * then restored from the source afterwards. Returns false on failure.
	 *
	 * @param string $text   Source text (possibly with HTML).
	 * @param string $source Source language code.
	 * @param string $target Target language code.
	 * @return string|false
	 */
	public static function translate_html( string $text, string $source, string $target ) {
		if ( false === strpos( $text, '<' ) ) {
			return self::translate( $text, $source, $target );
		}
		$tags = array();
		$i    = 0;
		$protected = (string) preg_replace_callback(
			'/<[^>]+>/',
			static function ( $m ) use ( &$i, &$tags ) {
				$tags[ $i ] = $m[0];
				++$i;
				return '[' . $i . ']';
			},
			$text
		);
		$tr = self::translate( $protected, $source, $target );
		if ( false === $tr ) {
			return false;
		}
		// Tolerate the bracket/spacing variants Google sometimes introduces.
		return (string) preg_replace_callback(
			'/[\[\{\x{3010}]\s*(\d+)\s*[\]\}\x{3011}]/u',
			static function ( $m ) use ( $tags ) {
				$idx = (int) $m[1] - 1;
				return isset( $tags[ $idx ] ) ? $tags[ $idx ] : $m[0];
			},
			$tr
		);
	}

	/**
	 * Map our internal language codes to the ones Google expects.
	 *
	 * @param string $code Internal code.
	 */
	private static function gcode( string $code ): string {
		$code = strtolower( $code );
		$map  = array(
			'pt-br' => 'pt',
			'zh'    => 'zh-CN',
			'zh-tw' => 'zh-TW',
		);
		return $map[ $code ] ?? $code;
	}
}
