<?php
/**
 * MetForm's forms, printed as a JavaScript template.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * MetForm (600,000 sites) prints every form inside
 * `<script type="text" class="mf-template">return html`…`</script>` and builds it in the
 * browser (widgets/text/text.php and siblings). The page engine never reads a script body,
 * so labels, placeholders, the button and the error messages stayed in the source
 * language (3/10/2026, plugin round with the sentence check). Only two places of the
 * template are words for the visitor, and only those are read and replaced; every other
 * byte of the template stays as it was:
 *
 *  - `parent.decodeEntities(`Your name`)` — labels, placeholders, the button text;
 *  - the JSON given to `parent.activateValidation({"message":"…"})` — the error messages.
 */
class MetFormTemplate {

	/**
	 * A template literal's body: no backtick, no «${» expression inside.
	 */
	const DECODE = '/decodeEntities\(\s*`((?:[^`\\\\$]|\\\\.|\$(?!\{))*)`\s*\)/s';

	/**
	 * Whether a script tag is a MetForm template.
	 *
	 * @param string $open_tag The opening <script …> tag.
	 */
	public static function is_template( string $open_tag ): bool {
		return (bool) preg_match( '#\bclass\s*=\s*(["\'])[^"\']*\bmf-template\b#i', $open_tag );
	}

	/**
	 * The visitor's words in a template, in order.
	 *
	 * @param string $js Template body.
	 * @return string[]
	 */
	public static function strings( string $js ): array {
		$out = array();
		if ( preg_match_all( self::DECODE, $js, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$t = trim( self::unescape( $raw ) );
				if ( '' !== $t && preg_match( '/\p{L}/u', $t ) ) {
					$out[] = $t;
				}
			}
		}
		foreach ( self::validations( $js ) as $v ) {
			foreach ( ScriptData::json_strings( $v['data'] ) as $t ) {
				$out[] = $t;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The template with its words translated, or null when nothing changed.
	 *
	 * @param string               $js  Template body.
	 * @param array<string,string> $map Source text => translation.
	 */
	public static function translate( string $js, array $map ): ?string {
		$changed = false;
		$js      = (string) preg_replace_callback(
			self::DECODE,
			function ( $m ) use ( $map, &$changed ) {
				$t = trim( self::unescape( $m[1] ) );
				if ( '' === $t || ! isset( $map[ $t ] ) || '' === $map[ $t ] || $map[ $t ] === $t ) {
					return $m[0];
				}
				$changed = true;
				$nuovo   = str_replace( $t, $map[ $t ], self::unescape( $m[1] ) );
				return str_replace( $m[1], self::escape( $nuovo ), $m[0] );
			},
			$js
		);
		// From the last one back, so the offsets of the earlier ones stay valid.
		foreach ( array_reverse( self::validations( $js ) ) as $v ) {
			$nuovo = ScriptData::json_translate( $v['data'], $map );
			if ( null === $nuovo ) {
				continue;
			}
			$json = wp_json_encode( $nuovo );
			if ( ! is_string( $json ) ) {
				continue;
			}
			// Inside a template literal: a backtick or «${» in a translation would end it.
			$json    = str_replace( array( '`', '${' ), array( '\\u0060', '$\\u007b' ), $json );
			$js      = substr( $js, 0, $v['start'] ) . $json . substr( $js, $v['end'] );
			$changed = true;
		}
		return $changed ? $js : null;
	}

	/**
	 * Every JSON object handed to activateValidation(), with its byte range.
	 *
	 * @param string $js Template body.
	 * @return array<int,array{start:int,end:int,data:array}>
	 */
	private static function validations( string $js ): array {
		$out = array();
		if ( ! preg_match_all( '/activateValidation\(\s*(?=\{)/', $js, $m, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		foreach ( $m[0] as $hit ) {
			$start = $hit[1] + strlen( $hit[0] );
			$end   = ScriptData::json_end( $js, $start );
			if ( null === $end ) {
				continue;
			}
			$data = json_decode( substr( $js, $start, $end - $start ), true );
			if ( is_array( $data ) ) {
				$out[] = array(
					'start' => $start,
					'end'   => $end,
					'data'  => $data,
				);
			}
		}
		return $out;
	}

	/**
	 * A template literal's text as the browser reads it.
	 *
	 * @param string $raw Body between the backticks.
	 */
	private static function unescape( string $raw ): string {
		return str_replace( array( '\\`', '\\$', '\\\\' ), array( '`', '$', '\\' ), $raw );
	}

	/**
	 * Text made safe for a template literal: backslashes, backticks and «${».
	 *
	 * @param string $text Plain text.
	 */
	private static function escape( string $text ): string {
		return str_replace( array( '\\', '`', '${' ), array( '\\\\', '\\`', '\\${' ), $text );
	}
}
