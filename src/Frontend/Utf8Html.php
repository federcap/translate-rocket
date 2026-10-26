<?php
/**
 * Reads HTML into a DOMDocument as UTF-8 on every libxml version.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * 26/10/2026 (oghazi.com, Arabic source site): on newer libxml versions the `<?xml encoding="utf-8" ?>` prefix the
 * plugin relied on no longer sets the encoding, so pages were read as ISO-8859-1 and every non-Latin letter came
 * out garbled («Ø / Ù» for Arabic) in the translated pages and in the visual editor. Here every character above
 * ASCII becomes a numeric reference (&#1606;) before parsing: the parser then sees plain ASCII, the declared
 * encoding no longer matters, and the DOM holds the same text. The document is declared UTF-8 afterwards, so
 * saveHTML() writes the letters back as they were. Script, style and textarea bodies are masked by the callers
 * before they get here, so their raw text is never touched.
 */
final class Utf8Html {

	/**
	 * Parse $html into $dom.
	 *
	 * @param \DOMDocument $dom   Target document.
	 * @param string       $html  HTML in UTF-8.
	 * @param int          $flags libxml options.
	 */
	public static function load( \DOMDocument $dom, string $html, int $flags = 0 ): bool {
		$ok            = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . self::ascii( $html ), $flags );
		$dom->encoding = 'UTF-8';
		return (bool) $ok;
	}

	/**
	 * Every character above ASCII as a numeric reference; plain ASCII comes back untouched (and fast).
	 *
	 * @param string $html HTML in UTF-8.
	 */
	public static function ascii( string $html ): string {
		if ( ! preg_match( '/[\x80-\xFF]/', $html ) || ! function_exists( 'mb_encode_numericentity' ) ) {
			return $html;
		}
		return mb_encode_numericentity( $html, array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' );
	}
}
