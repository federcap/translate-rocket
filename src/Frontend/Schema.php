<?php
/**
 * Structured data (JSON-LD) translation.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and rewrites the human-readable text inside <script type="application/ld+json">.
 *
 * The page engine leaves script bodies untouched (it has to: see Engine::mask_raw_text()),
 * so structured data used to stay in the source language on every translated page — an FAQ
 * visible in German was described to search engines in English, and an article on /de/ still
 * declared "inLanguage": "en". This class works on the decoded JSON only, never on the raw
 * text, so a translation can never break the script or the data around it.
 *
 * Pure functions, no WordPress calls: the engine hands in the JSON and the translation map.
 */
class Schema {

	/**
	 * Keys whose string values are prose a person reads in search results.
	 * Identifiers, URLs, dates, prices and codes are never touched.
	 */
	private const TEXT_KEYS = array(
		'name',
		'headline',
		'alternativeHeadline',
		'description',
		'text',
		'caption',
		'abstract',
		'disambiguatingDescription',
		// JobPosting's job title (WP Job Manager, 4/10/2026): read by Google for Jobs.
		'title',
	);

	/**
	 * Types that describe a media file: their language is the language of the recording
	 * itself, so "inLanguage" is left as the author set it.
	 */
	private const MEDIA_TYPES = array( 'VideoObject', 'AudioObject', 'MediaObject', 'ImageObject', 'Clip', 'MusicRecording', 'Podcast', 'PodcastEpisode' );

	/**
	 * Types whose "name" is a proper noun — a person, a company, a brand, a place, a piece
	 * of software, the site itself. Their names are never collected or translated: machine
	 * translation would happily turn a surname or a product name into a common word.
	 * Their descriptions still are.
	 */
	private const PROPER_NAME_TYPES = array(
		'Person',
		'Organization',
		'Corporation',
		'NGO',
		'EducationalOrganization',
		'GovernmentOrganization',
		'LocalBusiness',
		'Brand',
		'Place',
		'WebSite',
		'SoftwareApplication',
		'WebApplication',
		'MobileApplication',
	);

	/**
	 * Longest value handled; anything bigger is an articleBody-like dump, not a label.
	 */
	private const MAX_LENGTH = 2000;

	/**
	 * Keys holding a name or a list of names a person reads: an article's category («Hotel news»), printed by
	 * Yoast, Rank Math and WordPress' own schema as ["Hotel news"] (4/10/2026, every theme probed).
	 */
	private const LIST_KEYS = array( 'articleSection' );

	/**
	 * Tags that end a sentence in an HTML value (see markup_pieces()).
	 */
	private const BLOCK_TAG = '#</?(?:p|div|ul|ol|li|h[1-6]|br|hr|table|thead|tbody|tr|td|th|blockquote|section|article|dl|dt|dd)\b[^>]*>#i';

	/**
	 * The strings of a LIST_KEYS value, or none.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function list_strings( $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $v ) {
			if ( is_string( $v ) && '' !== trim( $v ) && mb_strlen( $v ) <= self::MAX_LENGTH && preg_match( '/\p{L}/u', $v ) ) {
				$out[] = trim( $v );
			}
		}
		return $out;
	}

	/**
	 * Translatable strings found in a JSON-LD body, de-duplicated, in document order.
	 *
	 * @param string $json Script body.
	 * @return string[]
	 */
	public static function strings( string $json ): array {
		$data = self::decode( $json );
		if ( null === $data ) {
			return array();
		}
		$found = array();
		self::walk( $data, $found );
		return array_values( array_unique( $found ) );
	}

	/**
	 * Translate a JSON-LD body.
	 *
	 * @param string               $json     Script body.
	 * @param array<string,string> $map      Source text => translation.
	 * @param string               $language Current language code, used for "inLanguage".
	 * @return string|null Re-encoded JSON, or null when nothing changed (keep the body verbatim).
	 */
	public static function translate( string $json, array $map, string $language ): ?string {
		$data = self::decode( $json );
		if ( null === $data ) {
			return null;
		}
		$changed = false;
		$data    = self::apply( $data, $map, self::language_tag( $language ), $changed );
		if ( ! $changed ) {
			return null;
		}
		// JSON_HEX_TAG turns < and > into < / >, so a translated value can never
		// close the <script> element early. Unicode and slashes stay readable.
		$out = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure class, no WP dependency.
		return is_string( $out ) ? $out : null;
	}

	/**
	 * A plain-text value worth sending to translation.
	 *
	 * @param mixed $value Value.
	 */
	public static function is_translatable( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > self::MAX_LENGTH ) {
			return false;
		}
		// Markup (some SEO plugins put HTML in FAQ answers) is left alone: the string store
		// keeps plain text, and a lookup would never match anyway.
		if ( false !== strpos( $value, '<' ) ) {
			return false;
		}
		if ( preg_match( '#^(https?:)?//#i', $value ) ) {
			return false;
		}
		return (bool) preg_match( '/\p{L}/u', $value );
	}

	/**
	 * Decode to an associative array, or null.
	 *
	 * @param string $json Body.
	 * @return array|null
	 */
	private static function decode( string $json ) {
		$json = trim( $json );
		// Some themes wrap the body in an HTML comment or CDATA for very old browsers.
		$json = (string) preg_replace( '#^(?:<!--|/\*<!\[CDATA\[\*/|<!\[CDATA\[)\s*|\s*(?:-->|/\*\]\]>\*/|\]\]>)$#', '', $json );
		if ( '' === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * The text pieces of a value written in HTML (WP Job Manager's JobPosting description,
	 * FAQ answers some SEO plugins keep with their <p> and <strong>): the words between the
	 * tags, each one a sentence the page itself shows. Empty when the value is not markup.
	 * Only block tags cut: a sentence holding <strong> or <a> is left whole and untouched,
	 * because its pieces («Yes,» «always» «for guests.») are not sentences to send anywhere.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	public static function markup_pieces( $value ): array {
		if ( ! is_string( $value ) || false === strpos( $value, '<' ) || strlen( $value ) > self::MAX_LENGTH * 4 || ! preg_match( '#</?[a-z][^>]*>#i', $value ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) preg_split( self::BLOCK_TAG, $value ) as $part ) {
			if ( false !== strpos( (string) $part, '<' ) ) {
				continue;
			}
			$t = trim( html_entity_decode( (string) $part, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $t && mb_strlen( $t ) <= self::MAX_LENGTH && preg_match( '/\p{L}/u', $t ) ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * The same value with every known piece between the tags translated, or null.
	 *
	 * @param string               $value Value.
	 * @param array<string,string> $map   Translations.
	 */
	public static function translate_markup( string $value, array $map ): ?string {
		$some  = false;
		$parts = preg_split( '#(' . substr( self::BLOCK_TAG, 1, -2 ) . ')#i', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		foreach ( $parts as $i => $part ) {
			if ( '' === $part || false !== strpos( $part, '<' ) ) {
				continue;
			}
			$t = trim( html_entity_decode( $part, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $t && isset( $map[ $t ] ) && '' !== $map[ $t ] && $map[ $t ] !== $t ) {
				// The spaces around the piece stay; the translation is escaped like the HTML it goes into.
				preg_match( '/^\s*/', $part, $a );
				preg_match( '/\s*$/', $part, $b );
				$parts[ $i ] = $a[0] . htmlspecialchars( $map[ $t ], ENT_NOQUOTES, 'UTF-8', false ) . $b[0];
				$some        = true;
			}
		}
		return $some ? implode( '', $parts ) : null;
	}

	/**
	 * Collect translatable strings.
	 *
	 * @param mixed    $node  Node.
	 * @param string[] $found Accumulator.
	 */
	private static function walk( $node, array &$found ): void {
		if ( ! is_array( $node ) ) {
			return;
		}
		foreach ( $node as $key => $value ) {
			if ( self::is_text_field( $node, $key, $value ) ) {
				$found[] = trim( $value );
			} elseif ( self::is_markup_field( $node, $key, $value ) ) {
				foreach ( self::markup_pieces( $value ) as $t ) {
					$found[] = $t;
				}
			} elseif ( in_array( $key, self::LIST_KEYS, true ) ) {
				foreach ( self::list_strings( $value ) as $t ) {
					$found[] = $t;
				}
			} elseif ( is_array( $value ) ) {
				self::walk( $value, $found );
			}
		}
	}

	/**
	 * The parts of a composed title: SEO plugins write «Product - Site name» (or
	 * with – | —) in the page's WebPage node, a text nobody ever translated whole
	 * (found 28/09/2026 on a shop imported from Polylang: the name stayed English).
	 *
	 * @param string $text Value.
	 * @return string[] The parts, or none when the text is not composed.
	 */
	public static function pieces( string $text ): array {
		if ( ! preg_match( '/\s[-–—|]\s/u', $text ) ) {
			return array();
		}
		$parts = preg_split( '/\s+[-–—|]\s+/u', $text );
		return array_values( array_filter( array_map( 'trim', (array) $parts ), 'strlen' ) );
	}

	/**
	 * Translate a composed title part by part, keeping its separators.
	 *
	 * @param string               $text Value.
	 * @param array<string,string> $map  Translations.
	 * @return string|null Null when no part has a translation.
	 */
	private static function by_pieces( string $text, array $map ): ?string {
		if ( ! self::pieces( $text ) ) {
			return null;
		}
		$some = false;
		$out  = preg_replace_callback(
			'/[^\s–—|-][^–—|]*?(?=\s+[-–—|]\s+|$)/u',
			static function ( $m ) use ( $map, &$some ) {
				$t = trim( $m[0] );
				if ( isset( $map[ $t ] ) && '' !== $map[ $t ] && $map[ $t ] !== $t ) {
					$some = true;
					return str_replace( $t, $map[ $t ], $m[0] );
				}
				return $m[0];
			},
			$text
		);
		return ( $some && is_string( $out ) ) ? $out : null;
	}

	/**
	 * Rewrite translatable strings and inLanguage.
	 *
	 * @param mixed                $node     Node.
	 * @param array<string,string> $map      Translations.
	 * @param string               $tag      BCP 47 language tag.
	 * @param bool                 $changed  Set to true on any change.
	 * @return mixed
	 */
	private static function apply( $node, array $map, string $tag, bool &$changed ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}
		$is_media = self::is_media( $node );
		foreach ( $node as $key => $value ) {
			if ( self::is_text_field( $node, $key, $value ) ) {
				$source = trim( $value );
				if ( isset( $map[ $source ] ) && '' !== $map[ $source ] && $map[ $source ] !== $source ) {
					$node[ $key ] = $map[ $source ];
					$changed      = true;
				} else {
					$joined = self::by_pieces( $source, $map );
					if ( null !== $joined ) {
						$node[ $key ] = $joined;
						$changed      = true;
					}
				}
			} elseif ( self::is_markup_field( $node, $key, $value ) ) {
				$html = self::translate_markup( $value, $map );
				if ( null !== $html ) {
					$node[ $key ] = $html;
					$changed      = true;
				}
			} elseif ( in_array( $key, self::LIST_KEYS, true ) && ( is_string( $value ) || is_array( $value ) ) ) {
				$list = is_array( $value ) ? $value : array( $value );
				foreach ( $list as $i => $v ) {
					$t = is_string( $v ) ? trim( $v ) : '';
					if ( '' !== $t && isset( $map[ $t ] ) && '' !== $map[ $t ] && $map[ $t ] !== $t ) {
						$list[ $i ] = $map[ $t ];
						$changed    = true;
					}
				}
				$node[ $key ] = is_array( $value ) ? $list : $list[0];
			} elseif ( 'inLanguage' === $key && is_string( $value ) && ! $is_media && '' !== $tag && strtolower( $value ) !== strtolower( $tag ) ) {
				// Only an existing declaration is corrected: a node that states no language is
				// not given one, because the author may have left it out on purpose.
				$node[ $key ] = $tag;
				$changed      = true;
			} elseif ( is_array( $value ) ) {
				$node[ $key ] = self::apply( $value, $map, $tag, $changed );
			}
		}
		return $node;
	}

	/**
	 * Whether a node's @type is a media file.
	 *
	 * @param array $node Node.
	 */
	private static function is_media( array $node ): bool {
		return self::has_type( $node, self::MEDIA_TYPES );
	}

	/**
	 * Whether a key/value pair of a node is prose to translate.
	 *
	 * @param array $node  Node the pair belongs to.
	 * @param mixed $key   Key.
	 * @param mixed $value Value.
	 */
	private static function is_text_field( array $node, $key, $value ): bool {
		if ( ! is_string( $key ) || ! in_array( $key, self::TEXT_KEYS, true ) || ! self::is_translatable( $value ) ) {
			return false;
		}
		return ! ( 'name' === $key && self::has_type( $node, self::PROPER_NAME_TYPES ) );
	}

	/**
	 * Whether a key/value pair holds prose written in HTML (see markup_pieces()).
	 *
	 * @param array $node  Node the pair belongs to.
	 * @param mixed $key   Key.
	 * @param mixed $value Value.
	 */
	private static function is_markup_field( array $node, $key, $value ): bool {
		if ( ! is_string( $key ) || ! in_array( $key, self::TEXT_KEYS, true ) || ! self::markup_pieces( $value ) ) {
			return false;
		}
		return ! ( 'name' === $key && self::has_type( $node, self::PROPER_NAME_TYPES ) );
	}

	/**
	 * Whether a node's @type (a string or a list) is one of the given types.
	 *
	 * @param array    $node  Node.
	 * @param string[] $types Types.
	 */
	private static function has_type( array $node, array $types ): bool {
		if ( ! isset( $node['@type'] ) ) {
			return false;
		}
		$own = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
		foreach ( $own as $type ) {
			if ( is_string( $type ) && in_array( $type, $types, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * "pt-br" -> "pt-BR", "it" -> "it" (BCP 47, as schema.org expects).
	 *
	 * @param string $code Language code.
	 */
	private static function language_tag( string $code ): string {
		$code = strtolower( trim( $code ) );
		if ( false === strpos( $code, '-' ) ) {
			return $code;
		}
		list( $lang, $region ) = explode( '-', $code, 2 );
		return $lang . '-' . strtoupper( $region );
	}
}
