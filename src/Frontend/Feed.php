<?php
/**
 * RSS and Atom feeds of a translated language.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * /it/feed/ said «it-IT» and linked /it/ pages, but every title, excerpt and
 * body in it was still the source text: the page engine reads HTML documents,
 * and a feed is XML (29/09/2026). Feed readers, newsletter tools (Mailchimp
 * RSS campaigns), Google Discover and podcast apps read that file.
 *
 * The XML is left as it is; only the text of the elements that hold words is
 * swapped: title, description/summary, content:encoded and Atom's content —
 * HTML ones through Fragment (links get the language too), plain ones whole.
 */
class Feed {

	/**
	 * Language of the feed ('' = source: nothing to do).
	 *
	 * @var string
	 */
	private $lang = '';

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'maybe_start' ), 2 );
	}

	/**
	 * Start the buffer on a feed of a secondary language.
	 */
	public function maybe_start(): void {
		if ( ! function_exists( 'is_feed' ) || ! is_feed() || \TranslateRocket\Coexistence::on() ) {
			return;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! Strings::has_translations( $lang ) || Preview::hidden() ) {
			return;
		}
		$this->lang = $lang;
		ob_start( array( $this, 'translate' ) );
	}

	/**
	 * @param string $xml The feed.
	 * @return string
	 */
	public function translate( $xml ) {
		if ( ! is_string( $xml ) || '' === $this->lang || false === strpos( $xml, '<' ) ) {
			return $xml;
		}
		$lang = $this->lang;
		$out  = preg_replace_callback(
			'#<(title|description|summary|content:encoded|content|itunes:title|itunes:summary|media:title|media:description)(\s[^>]*)?>(.*?)</\1>#s',
			function ( $m ) use ( $lang ) {
				$inner = $m[3];
				if ( '' === trim( $inner ) ) {
					return $m[0];
				}
				if ( 0 === strpos( trim( $inner ), '<![CDATA[' ) ) {
					// HTML inside CDATA (content:encoded, some titles).
					$html = (string) preg_replace( '/^\s*<!\[CDATA\[|\]\]>\s*$/', '', $inner );
					$new  = false !== strpos( $html, '<' ) ? Fragment::translate( $html, $lang ) : Fragment::text( $html, $lang );
					return $new === $html ? $m[0] : '<' . $m[1] . ( $m[2] ?? '' ) . '><![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $new ) . ']]></' . $m[1] . '>';
				}
				// Escaped text or escaped HTML (RSS description = the excerpt).
				$text = html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$new  = false !== strpos( $text, '<' ) ? Fragment::translate( $text, $lang ) : Fragment::text( $text, $lang );
				if ( $new === $text ) {
					return $m[0];
				}
				return '<' . $m[1] . ( $m[2] ?? '' ) . '>' . htmlspecialchars( $new, ENT_QUOTES | ENT_XML1, 'UTF-8', false ) . '</' . $m[1] . '>';
			},
			$xml
		);
		return is_string( $out ) ? $out : $xml;
	}
}
