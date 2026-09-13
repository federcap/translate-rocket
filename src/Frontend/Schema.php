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
			} elseif ( is_array( $value ) ) {
				self::walk( $value, $found );
			}
		}
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
				}
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
