<?php
/**
 * Slider Revolution 7 (1.8.8, 16/10/2026).
 *
 * SR7 prints each text twice: in the HTML (<sr7-txt>, translated like any text) and in a JSON
 * object inside an inline script, «SR7.JSON['id'] = {…}». Its runtime (sr7.js, public) draws
 * the layers from the JSON — `innerHTML = …(u.content.text)` — so on a translated page the
 * slider went back to the source language a moment after loading, while the list of sentences
 * said it was translated. Button links («actions» → «link», often without a scheme) led back to
 * the source pages too.
 *
 * Here: the texts of the slides read from that JSON (for collection and translation), and only
 * the changed values written back; the rest of the script stays byte for byte.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * SR7.JSON blocks.
 */
class SliderRevolution {

	/**
	 * Where an SR7 module starts: «SR7.JSON['alias'] = {».
	 */
	const START = '#SR7\.JSON\[\s*([\'"])[\w-]+\1\s*\]\s*=\s*(?=\{)#';

	/**
	 * Largest block read (the official site has 185 KB blocks; a page of big sliders a few MB).
	 */
	const MAX = 4000000;

	/**
	 * Is this script body an SR7 module?
	 */
	public static function is_block( string $js ): bool {
		return strlen( $js ) <= self::MAX && false !== strpos( $js, 'SR7.JSON[' ) && (bool) preg_match( self::START, $js );
	}

	/**
	 * Every SR7.JSON object in the block, with its byte range.
	 *
	 * @return array<int,array{start:int,end:int,data:object}>
	 */
	private static function objects( string $js ): array {
		$out = array();
		if ( ! preg_match_all( self::START, $js, $m, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		foreach ( $m[0] as $hit ) {
			$start = $hit[1] + strlen( $hit[0] );
			$end   = \TranslateRocket\Frontend\ScriptData::json_end( $js, $start );
			if ( null === $end ) {
				continue;
			}
			// Objects, not arrays: layers keyed «0», «1»… must stay {…} when written back (an array
			// would become […] and the slider would not start).
			$data = json_decode( substr( $js, $start, $end - $start ) );
			if ( is_object( $data ) ) {
				$out[] = array( 'start' => $start, 'end' => $end, 'data' => $data );
			}
		}
		return $out;
	}

	/**
	 * The texts people read in the module: layer texts, toggle texts, slide titles and
	 * descriptions (arrows and thumbnails show them), accessibility labels.
	 *
	 * @return string[]
	 */
	public static function strings( string $js ): array {
		$found = array();
		foreach ( self::objects( $js ) as $o ) {
			self::visit(
				$o['data'],
				static function ( string $text ) use ( &$found ) {
					$found[ $text ] = true;
					return null;
				},
				null
			);
		}
		return array_keys( $found );
	}

	/**
	 * The block with the texts translated and the links in the page's language, or null when nothing changed.
	 *
	 * @param string               $js        Script body.
	 * @param array<string,string> $map       Source text => translation.
	 * @param string               $current   Language of the page.
	 * @param string[]             $secondary The site's other languages.
	 */
	public static function translate( string $js, array $map, string $current, array $secondary ): ?string {
		$objects = self::objects( $js );
		if ( ! $objects ) {
			return null;
		}
		$home      = (string) get_option( 'home' );
		$home_host = (string) wp_parse_url( $home, PHP_URL_HOST );
		$home_path = rtrim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
		$out       = '';
		$cursor    = 0;
		$any       = false;
		foreach ( $objects as $o ) {
			$changed = false;
			$data    = self::visit(
				$o['data'],
				static function ( string $text ) use ( $map ) {
					$key = trim( $text );
					return ( isset( $map[ $key ] ) && '' !== trim( (string) $map[ $key ] ) ) ? (string) $map[ $key ] : null;
				},
				static function ( string $link ) use ( $home_host, $home_path, $current, $secondary ) {
					if ( '' === $home_host ) {
						return null;
					}
					// SR writes «example.com/page/» without a scheme: give it one for the check, then take it off again.
					$senza = 0 === stripos( $link, $home_host ) || 0 === stripos( $link, 'www.' . $home_host );
					$prova = $senza ? 'https://' . $link : $link;
					$nuovo = \TranslateRocket\Frontend\Engine::localize_link( $prova, $home_host, $home_path, $current, $secondary );
					if ( $nuovo === $prova ) {
						return null;
					}
					return $senza ? (string) preg_replace( '#^https?://#i', '', $nuovo ) : $nuovo;
				},
				$changed
			);
			$out .= substr( $js, $cursor, $o['start'] - $cursor );
			$json = $changed ? wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : false;
			if ( is_string( $json ) ) {
				$out .= $json;
				$any  = true;
			} else {
				$out .= substr( $js, $o['start'], $o['end'] - $o['start'] );
			}
			$cursor = $o['end'];
		}
		$out .= substr( $js, $cursor );
		return $any ? $out : null;
	}

	/**
	 * Walk a module: call $text on every readable text and $link on every action link; a non-null
	 * answer replaces the value.
	 *
	 * @param mixed         $node    Part of the module.
	 * @param callable      $text    fn( string ): ?string.
	 * @param callable|null $link    fn( string ): ?string.
	 * @param bool          $changed Set when something was replaced.
	 * @param string        $key     Key of $node in its parent.
	 * @param string        $parent  Key of the parent.
	 * @return mixed
	 */
	private static function visit( $node, callable $text, ?callable $link, &$changed = false, string $key = '', string $parent = '' ) {
		if ( is_object( $node ) ) {
			foreach ( get_object_vars( $node ) as $k => $v ) {
				$node->{$k} = self::visit( $v, $text, $link, $changed, (string) $k, $key );
			}
			return $node;
		}
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				// a list inherits its parent's key: «actions»: [ {…} ] — the items are actions
				$node[ $k ] = self::visit( $v, $text, $link, $changed, $key, $parent );
			}
			return $node;
		}
		if ( ! is_string( $node ) || '' === trim( $node ) ) {
			return $node;
		}
		$leggibile = ( 'text' === $key && 'content' === $parent )   // layers.*.content.text
			|| ( 't' === $key && 'tog' === $parent )                // layers.*.tog.t (toggle text)
			|| ( 'label' === $key && 'acc' === $parent )            // layers.*.acc.label (accessibility)
			|| ( ( 'title' === $key || 'description' === $key ) && 'slide' === $parent ); // slides.*.slide.title
		if ( $leggibile && preg_match( '/\p{L}/u', wp_strip_all_tags( $node ) ) ) {
			$nuovo = $text( $node );
			if ( null !== $nuovo && $nuovo !== $node ) {
				$changed = true;
				return $nuovo;
			}
			return $node;
		}
		if ( null !== $link && 'link' === $key ) {
			$nuovo = $link( $node );
			if ( null !== $nuovo && $nuovo !== $node ) {
				$changed = true;
				return $nuovo;
			}
		}
		return $node;
	}
}
