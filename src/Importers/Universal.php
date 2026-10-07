<?php
/**
 * Universal importer: reads the pages as visitors see them and pairs the sentences.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Frontend\InlineText;

defined( 'ABSPATH' ) || exit;

/**
 * (Built in Labs 0.5, moved to the free plugin in 1.7 on 30/9/2026.) TranslateRocket imports from the
 * plugins it knows by reading their tables; for every other one — or a table it cannot read —
 * this reads the site itself, while the old plugin is still on: each page in the source
 * language and the same page in every language the old plugin serves (found from the page's
 * hreflang links, else /xx/, ?lang=xx or xx.domain), and pairs the sentences.
 *
 * A pair is taken only when it is certain: the two pages must have the same structure where
 * the sentence sits (same place in the page, same kind of element, same children), the
 * sentence must be the same in two readings of the original (so a date, a counter or a
 * random product is left out), it must have letters on both sides and not be a copy, and a
 * sentence with links or bold words must keep them. Sentences are read the way the page
 * engine reads them (InlineText for sentences with links), so an imported pair is found
 * again when the page is translated. Nothing is saved before the owner has seen the scan.
 *
 * Translators that work only inside the visitor's browser (a Google widget) leave the
 * server's copy of the page in the source language: they are recognised (every «translated»
 * page is identical to the original) and said to be out of reach.
 */
final class Universal {

	const REPORT = 'trrocket_universal_report';
	const NONCE  = 'trrocket_universal';
	const STEP   = 2; // pages per request

	/** Elements whose text is never read. */
	const SKIP = "ancestor-or-self::script or ancestor-or-self::style or ancestor-or-self::noscript or ancestor-or-self::svg or ancestor-or-self::code or ancestor-or-self::pre or ancestor-or-self::template or ancestor-or-self::textarea or ancestor-or-self::select or ancestor-or-self::*[@translate='no'] or ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' notranslate ')]"
		// The language switcher of any plugin: on the translated page another language is the current one,
		// so «English» sits where «Français» was (seen on 14 of 20 real sites, 30/9/2026).
		. " or ancestor-or-self::a[@hreflang] or ancestor-or-self::*[not(self::body) and not(self::html) and (contains(@class, 'wpml-ls') or contains(@class, 'lang-item') or contains(@class, 'pll-switcher') or contains(@class, 'trp-language') or contains(@class, 'trp-ls') or contains(@class, 'gt_switcher') or contains(@class, 'gtranslate_wrapper') or contains(@class, 'menu-item-gtranslate') or contains(@class, 'glink') or contains(@class, 'weglot') or contains(@class, 'linguise_switcher') or contains(@class, 'conveythis') or contains(@class, 'qtranxs') or contains(@class, 'wpglobus-selector') or contains(@class, 'wpglobus_flag') or contains(@class, 'wpm-language') or contains(@class, 'mltlngg') or contains(@class, 'bogo-language') or contains(@class, 'trrocket-switcher') or contains(@class, 'language-switch') or contains(@class, 'lang-switch') or contains(@class, 'language-selector') or contains(@class, 'lang-selector'))]";

	const ATTRS = array( 'alt', 'title', 'placeholder', 'aria-label' );

	public static function boot(): void {
		add_action( 'wp_ajax_trrocket_universal_step', array( __CLASS__, 'ajax_step' ) );
	}

	/**
	 * Every published page, post and product a visitor can open, the home page first.
	 *
	 * @return int[]
	 */
	public static function all_pages(): array {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$ids   = array_map( 'intval', (array) get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => -1, 'has_password' => false, 'orderby' => 'menu_order date', 'order' => 'ASC', 'suppress_filters' => true ) ) );
		$front = (int) get_option( 'page_on_front' );
		return $front > 0 ? array_values( array_unique( array_merge( array( $front ), $ids ) ) ) : $ids;
	}

	/* ------------------------------------------------------------ reading pages */

	/**
	 * A page as a visitor gets it (no cookies), or null.
	 */
	public static function fetch( string $url ): ?string {
		if ( ! self::own_url( $url ) ) {
			return null; // security audit 7/10/2026: the importer reads this site, never another host
		}
		$r = wp_remote_get(
			$url,
			array(
				'timeout'     => 25,
				'redirection' => 3,
				'sslverify'   => false, // a site calling itself: certificates fail on many local set-ups.
				'headers'     => array( 'Accept-Language' => '', 'User-Agent' => 'TranslateRocket-Universal-Importer' ),
				'cookies'     => array(),
			)
		);
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return null;
		}
		$h = (string) wp_remote_retrieve_body( $r );
		return '' !== $h ? $h : null;
	}

	/**
	 * Whether an address belongs to this site (same host as the home address).
	 */
	public static function own_url( string $url ): bool {
		$h = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$s = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		$u = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return '' !== $h && $h === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) && in_array( $u, array( 'http', 'https', $s ), true );
	}

	/**
	 * Several pages at once (WordPress' Requests library), each as fetch() would get it.
	 *
	 * @param array<string,string> $urls key => url.
	 * @return array<string,string|null>
	 */
	public static function fetch_many( array $urls ): array {
		$out = array();
		$cls = class_exists( '\\WpOrg\\Requests\\Requests' ) ? '\\WpOrg\\Requests\\Requests' : ( class_exists( '\\Requests' ) ? '\\Requests' : '' );
		$urls = array_filter( $urls, array( __CLASS__, 'own_url' ) );
		if ( '' === $cls || count( $urls ) < 2 ) {
			foreach ( $urls as $k => $u ) {
				$out[ $k ] = self::fetch( $u );
			}
			return $out;
		}
		$req = array();
		foreach ( $urls as $k => $u ) {
			$req[ $k ] = array(
				'url'     => $u,
				'type'    => 'GET',
				'headers' => array( 'User-Agent' => 'TranslateRocket-Universal-Importer' ),
				'options' => array( 'timeout' => 25, 'verify' => false, 'redirects' => 3 ),
			);
		}
		try {
			$res = call_user_func( array( $cls, 'request_multiple' ), $req, array( 'timeout' => 25, 'verify' => false ) );
		} catch ( \Throwable $e ) {
			$res = array();
		}
		foreach ( $urls as $k => $u ) {
			$r         = $res[ $k ] ?? null;
			$ok        = is_object( $r ) && ! ( $r instanceof \Exception ) && 200 === (int) ( $r->status_code ?? 0 ) && '' !== (string) $r->body;
			$out[ $k ] = $ok ? (string) $r->body : self::fetch( $u ); // one that failed in the group: try it alone
		}
		return $out;
	}

	private static function dom( string $html ): ?\DOMXPath {
		$d = new \DOMDocument();
		$v = libxml_use_internal_errors( true );
		$ok = $d->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $v );
		return $ok ? new \DOMXPath( $d ) : null;
	}

	/**
	 * <html lang>, two letters (or pt-br), lower case.
	 */
	public static function page_lang( \DOMXPath $x ): string {
		$l = $x->query( '/html/@lang' );
		$v = $l && $l->length ? strtolower( trim( (string) $l->item( 0 )->nodeValue ) ) : '';
		return self::code( $v );
	}

	/**
	 * A language tag (it-IT, pt_BR, zh-Hans) to TranslateRocket's code, if the site has it.
	 */
	public static function code( string $tag ): string {
		$t = str_replace( '_', '-', strtolower( trim( $tag ) ) );
		if ( '' === $t ) {
			return '';
		}
		$targets = array_map( 'strtolower', (array) ( \TranslateRocket\Settings::get()['target_languages'] ?? array() ) );
		$src     = strtolower( (string) ( \TranslateRocket\Settings::get()['source_language'] ?? '' ) );
		foreach ( array_merge( $targets, array( $src ) ) as $c ) {
			if ( $c === $t ) {
				return $c;
			}
		}
		$due = substr( $t, 0, 2 );
		if ( 'pt' === $due && in_array( 'pt-br', $targets, true ) && 'pt-br' === $t ) {
			return 'pt-br';
		}
		foreach ( array_merge( $targets, array( $src ) ) as $c ) {
			if ( substr( $c, 0, 2 ) === $due ) {
				return $c;
			}
		}
		return $due;
	}

	/**
	 * The addresses of this page in the other languages: its hreflang links first.
	 *
	 * @return array<string,string> language => url
	 */
	public static function alternates( \DOMXPath $x, string $url ): array {
		$targets = array_map( 'strtolower', (array) ( \TranslateRocket\Settings::get()['target_languages'] ?? array() ) );
		$out     = array();
		$links   = $x->query( "//link[@rel='alternate'][@hreflang][@href]" );
		foreach ( $links ? $links : array() as $l ) {
			$c = self::code( (string) $l->getAttribute( 'hreflang' ) );
			if ( in_array( $c, $targets, true ) && ! isset( $out[ $c ] ) ) {
				$out[ $c ] = (string) $l->getAttribute( 'href' );
			}
		}
		if ( ! empty( $out ) ) {
			return $out;
		}
		// No hreflang: the usual addresses.
		$p    = wp_parse_url( $url );
		$path = (string) ( $p['path'] ?? '/' );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$rel  = '' !== $home && 0 === strpos( $path, $home ) ? substr( $path, strlen( $home ) ) : $path;
		foreach ( $targets as $c ) {
			$out[ $c ] = home_url( '/' . $c . ( '' !== $rel ? $rel : '/' ) );
		}
		return $out;
	}

	/**
	 * DOM path of a node: /html/body/div[2]/p[1], with #text[n] for a text node.
	 */
	private static function path( \DOMNode $n ): string {
		$parts = array();
		while ( $n && XML_DOCUMENT_NODE !== $n->nodeType ) {
			$i = 1;
			for ( $s = $n->previousSibling; $s; $s = $s->previousSibling ) {
				if ( $s->nodeType === $n->nodeType && $s->nodeName === $n->nodeName && ( XML_TEXT_NODE !== $n->nodeType || '' !== trim( (string) $s->nodeValue ) ) ) {
					++$i;
				}
			}
			$parts[] = ( XML_TEXT_NODE === $n->nodeType ? '#text' : $n->nodeName ) . '[' . $i . ']';
			$n = $n->parentNode;
		}
		return '/' . implode( '/', array_reverse( $parts ) );
	}

	/**
	 * The shape of an element: its tag and its children's tags, in order.
	 */
	private static function shape( ?\DOMNode $el ): string {
		if ( ! $el ) {
			return '';
		}
		$k = array();
		foreach ( $el->childNodes as $c ) {
			if ( XML_ELEMENT_NODE === $c->nodeType ) {
				if ( in_array( $c->nodeName, array( 'script', 'style', 'template', 'noscript', 'link', 'meta' ), true ) ) {
					continue; // not content: one more script on a translated page is not another structure
				}
				$k[] = $c->nodeName;
			} elseif ( XML_TEXT_NODE === $c->nodeType && '' !== trim( (string) $c->nodeValue ) ) {
				$k[] = '#t';
			}
		}
		return $el->nodeName . '(' . implode( ',', $k ) . ')';
	}

	/**
	 * The block a piece belongs to (a news card, a list item with its date): two levels up.
	 */
	private static function block( ?\DOMNode $n ): string {
		$b = $n && $n->parentNode ? $n->parentNode->parentNode : null;
		return $b && XML_ELEMENT_NODE === $b->nodeType ? self::path( $b ) : '';
	}

	/**
	 * Is this text only a language's name or code (English, Français, EN, en_GB)?
	 */
	private static function language_name( string $t ): bool {
		$t = strtolower( trim( $t ) );
		if ( preg_match( '/^[a-z]{2}([_-][a-z]{2,4})?$/', $t ) ) {
			return true;
		}
		static $nomi = null;
		if ( null === $nomi ) {
			$nomi = array();
			foreach ( \TranslateRocket\Languages::all() as $l ) {
				$nomi[ mb_strtolower( (string) $l[0] ) ] = true;
				$nomi[ mb_strtolower( (string) $l[1] ) ] = true;
			}
		}
		return isset( $nomi[ mb_strtolower( $t ) ] );
	}

	/**
	 * The language a sentence of five words or more is plainly written in, from its commonest words;
	 * '' when unsure (short text, other scripts, no clear winner).
	 */
	private static function guess_language( string $t ): string {
		return Check::guess_language( $t );
	}

	/**
	 * The numbers in a text (dates, prices, codes), in order of size: «1.000» and «1,000» alike.
	 */
	private static function numbers( string $t ): array {
		return Check::numbers( $t );
	}

	private static function clean( string $t ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Every readable piece of a page: path => [text, shape], the way the page engine reads it.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function pieces( \DOMXPath $x ): array {
		$out   = array();
		$units = InlineText::units( $x, self::SKIP );
		$dentro = array();
		foreach ( $units as $u ) {
			$t = InlineText::source( $u );
			if ( '' !== $t ) {
				$out[ self::path( $u ) ] = array( $t, self::shape( $u ) . '<' . self::shape( $u->parentNode ) . '<' . self::shape( $u->parentNode ? $u->parentNode->parentNode : null ), self::block( $u ) );
			}
			$dentro[] = $u;
		}
		$testi = $x->query( '//body//text()[normalize-space()][not(' . str_replace( 'ancestor-or-self::', 'ancestor::', self::SKIP ) . ')]' );
		foreach ( $testi ? $testi : array() as $t ) {
			foreach ( $dentro as $u ) {
				for ( $p = $t->parentNode; $p; $p = $p->parentNode ) {
					if ( $p === $u ) {
						continue 3; // part of a sentence with links, already read whole
					}
				}
			}
			$v = self::clean( (string) $t->nodeValue );
			if ( '' !== $v ) {
				$su = $t->parentNode ? $t->parentNode->parentNode : null;
				$out[ self::path( $t ) ] = array( $v, self::shape( $t->parentNode ) . '<' . self::shape( $su ) . '<' . self::shape( $su ? $su->parentNode : null ), self::block( $t->parentNode ) );
			}
		}
		foreach ( self::ATTRS as $a ) {
			$els = $x->query( '//body//*[@' . $a . '][not(' . self::SKIP . ')]' );
			foreach ( $els ? $els : array() as $e ) {
				$v = self::clean( (string) $e->getAttribute( $a ) );
				if ( '' !== $v ) {
					$out[ self::path( $e ) . '@' . $a ] = array( $v, self::shape( $e ), self::block( $e ) );
				}
			}
		}
		$tit = $x->query( '/html/head/title' );
		if ( $tit && $tit->length ) {
			$out['title'] = array( self::clean( (string) $tit->item( 0 )->textContent ), 'title' );
		}
		foreach ( array( 'description', 'og:title', 'og:description', 'twitter:title', 'twitter:description' ) as $m ) {
			$q = $x->query( "/html/head/meta[@name='{$m}' or @property='{$m}']/@content" );
			if ( $q && $q->length ) {
				$out[ 'meta:' . $m ] = array( self::clean( (string) $q->item( 0 )->nodeValue ), 'meta' );
			}
		}
		return $out;
	}

	/**
	 * Is it worth a pair? Letters on both sides, not a copy, lengths that make sense, links kept.
	 */
	private static function good( string $o, string $t, string $lang = '' ): string {
		if ( ! preg_match( '/\p{L}/u', $o ) || ! preg_match( '/\p{L}/u', $t ) ) {
			return 'noletters';
		}
		if ( $o === $t ) {
			return 'same';
		}
		$lo = mb_strlen( $o );
		$lt = mb_strlen( $t );
		if ( $lo > 12 && ( $lt > 4 * $lo || $lt * 4 < $lo ) ) {
			return 'length';
		}
		if ( InlineText::has_parts( $o ) && ! InlineText::parts_ok( $o, $t ) ) {
			return 'links';
		}
		// A date, a price, a code is the same in every language. Different numbers mean the other
		// page shows other content at this place (a cached copy with older news: seen 30/9/2026 on a
		// real site with GTranslate, «21 Settembre 2026» next to «17 September 2026»).
		if ( self::numbers( $o ) !== self::numbers( $t ) ) {
			return 'numbers';
		}
		// «English» → «Français», «French» → «Anglais»: a language's name alone is a switcher entry, where the
		// translated page shows another language. TranslateRocket writes its own switcher's names.
		if ( self::language_name( $o ) || self::language_name( $t ) ) {
			return 'language-name';
		}
		// A sentence plainly written in another language than the page's (a Spanish block left in the
		// English copy of a real Polylang site): not this language's translation.
		if ( '' !== $lang ) {
			$scritta = self::guess_language( $t );
			if ( '' !== $scritta && $scritta !== substr( strtolower( $lang ), 0, 2 ) ) {
				return 'language';
			}
		}
		return '';
	}

	/**
	 * One page: read it, find its translations, pair. With $save, store the pairs.
	 *
	 * @return array<string,mixed> id, url, title, status, langs => [lang => [pairs, skipped, url, why]]
	 */
	public static function page( int $post_id, bool $save ): array {
		$url = (string) get_permalink( $post_id );
		$row = array( 'id' => $post_id, 'url' => $url, 'title' => get_the_title( $post_id ), 'status' => '', 'langs' => array() );
		$src = strtolower( (string) ( \TranslateRocket\Settings::get()['source_language'] ?? '' ) );
		$h1  = self::fetch( $url );
		$x1  = null !== $h1 ? self::dom( $h1 ) : null;
		if ( null === $x1 ) {
			$row['status'] = 'unreachable';
			return $row;
		}
		$lang = self::page_lang( $x1 );
		if ( '' !== $lang && '' !== $src && substr( $lang, 0, 2 ) !== substr( $src, 0, 2 ) ) {
			$row['status'] = 'other-language'; // a translated copy (Polylang, WPML): read from its original.
			return $row;
		}
		$h2 = self::fetch( $url );
		$x2 = null !== $h2 ? self::dom( $h2 ) : null;
		$a  = self::pieces( $x1 );
		$b  = $x2 ? self::pieces( $x2 ) : $a;
		// What changes between two readings (a date, a counter, a random product) is left out.
		foreach ( $a as $k => $v ) {
			if ( ! isset( $b[ $k ] ) || $b[ $k ][0] !== $v[0] ) {
				unset( $a[ $k ] );
			}
		}
		$tutte_uguali = true;
		$alt          = self::alternates( $x1, $url );
		foreach ( $alt as $c => $turl ) {
			if ( untrailingslashit( $turl ) === untrailingslashit( $url ) ) {
				unset( $alt[ $c ] );
			}
		}
		$lette = self::fetch_many( $alt ); // every language at once, not one after the other
		foreach ( $alt as $c => $turl ) {
			$info = array( 'url' => $turl, 'pairs' => 0, 'skipped' => 0, 'why' => '' );
			$th   = $lette[ $c ] ?? null;
			$tx = null !== $th ? self::dom( $th ) : null;
			if ( null === $tx ) {
				$info['why'] = 'unreachable';
				$row['langs'][ $c ] = $info;
				continue;
			}
			// TranslateRocket prints its switcher's CSS even side by side: what tells is the switcher itself.
			$nostro = $tx->query( "//body//*[contains(concat(' ', normalize-space(@class), ' '), ' trrocket-switcher ')]" );
			if ( $nostro && $nostro->length > 0 ) {
				$info['why'] = 'translaterocket'; // our own translation, not the old plugin's
				$row['langs'][ $c ] = $info;
				continue;
			}
			$t = self::pieces( $tx );
			$diverse = 0;
			$trovate = array();
			// A block (a news card) where one piece has other numbers is other content: none of it pairs.
			$altri = array();
			foreach ( $a as $k => $v ) {
				if ( isset( $t[ $k ] ) && $t[ $k ][1] === $v[1] && '' !== ( $v[2] ?? '' ) && 'numbers' === self::good( $v[0], $t[ $k ][0], $c ) ) {
					$altri[ $v[2] ] = true;
				}
			}
			foreach ( $a as $k => $v ) {
				if ( '' !== ( $v[2] ?? '' ) && isset( $altri[ $v[2] ] ) ) {
					++$info['skipped'];
					$info['reasons']['block'] = ( $info['reasons']['block'] ?? 0 ) + 1;
					continue;
				}
				if ( ! isset( $t[ $k ] ) ) {
					++$info['skipped'];
					$info['reasons']['missing'] = ( $info['reasons']['missing'] ?? 0 ) + 1;
					continue;
				}
				if ( $t[ $k ][1] !== $v[1] ) {
					++$info['skipped']; // not the same structure here: no pair
					$info['reasons']['shape'] = ( $info['reasons']['shape'] ?? 0 ) + 1;
					continue;
				}
				$why = self::good( $v[0], $t[ $k ][0], $c );
				if ( 'same' !== $why && 'noletters' !== $why ) {
					++$diverse; // a number or a symbol is the same everywhere: it tells nothing
				}
				if ( '' !== $why ) {
					if ( 'same' !== $why && 'noletters' !== $why ) {
						++$info['skipped'];
						$info['reasons'][ $why ] = ( $info['reasons'][ $why ] ?? 0 ) + 1; // why each one was left out
					}
					continue;
				}
				$trovate[ $k ] = array( $v[0], $t[ $k ][0] );
			}
			// Every pair already in the catalogue, word for word: this language is TranslateRocket's own
			// (it serves the address because the old plugin does not, or is off), or it was imported
			// before. Nothing new to take, and it is not the old plugin's translation (30/9/2026: with
			// qTranslate-XT switched off, 28 «pairs» were our own menu read back).
			if ( $trovate ) {
				$noti = \TranslateRocket\Strings::translate_texts( array_column( $trovate, 0 ), $c );
				$gia  = 0;
				foreach ( $trovate as $cp ) {
					if ( isset( $noti[ trim( $cp[0] ) ] ) && trim( (string) $noti[ trim( $cp[0] ) ] ) === trim( $cp[1] ) ) {
						++$gia;
					}
				}
				$info['known'] = $gia;
				if ( count( $trovate ) === $gia ) {
					$info['why']        = 'known';
					$info['skipped']    = 0;
					$row['langs'][ $c ] = $info;
					continue;
				}
			}
			foreach ( $trovate as $k => $cp ) {
				if ( $save ) {
					// Text is stored as page text; alt, title, meta also on the kinds of string the
					// catalogue already has for those words (an alt is not page text).
					if ( false !== strpos( $k, '@' ) || 0 === strpos( $k, 'meta:' ) || 'title' === $k ) {
						Comune::ovunque( $cp[0], $c, $cp[1] );
					} else {
						Comune::coppia( $cp[0], $c, $cp[1], true );
					}
				}
				++$info['pairs'];
			}
			if ( $diverse > 0 ) {
				$tutte_uguali = false;
			} else {
				$info['why'] = 'same-as-original';
			}
			$row['langs'][ $c ] = $info;
		}
		$nostre = array_filter( $row['langs'], function ( $l ) {
			return 'known' === $l['why'];
		} );
		$coppie = array_sum( array_map( function ( $l ) {
			return (int) $l['pairs'];
		}, $row['langs'] ) );
		if ( empty( $row['langs'] ) ) {
			$row['status'] = 'no-translations';
		} elseif ( count( $nostre ) === count( $row['langs'] ) ) {
			$row['status'] = 'known';
		} elseif ( $tutte_uguali ) {
			$row['status'] = 'browser-only'; // every «translation» is the original: a translator that works in the browser
		} elseif ( 0 === $coppie ) {
			$row['status'] = 'no-translations'; // «translations found» only when there is something to take (30/9/2026, GTranslate)
		} else {
			$row['status'] = 'ok';
		}
		return $row;
	}

	/* ------------------------------------------------------------- the owner */

	/**
	 * One step of a scan or an import (AJAX from the Import screen).
	 */
	public static function ajax_step(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Not allowed.', 'translate-rocket' ) );
		}
		// Another translation plugin is on and TranslateRocket still serves the languages too: both
		// answer /it/…, and what the importer reads may be our own translation (30/9/2026, WP Multilang,
		// Multilanguage, WPGlobus: our menu read back as «pairs»). Side by side first.
		if ( \TranslateRocket\Coexistence::serving() ) {
			wp_send_json_error( self::serving_text() );
		}
		$mode = isset( $_POST['mode'] ) && 'import' === $_POST['mode'] ? 'import' : 'scan';
		$from = max( 0, (int) ( $_POST['from'] ?? 0 ) );
		wp_send_json_success( self::step( $mode, $from ) );
	}

	/**
	 * Work on the next pages; the report grows as it goes.
	 *
	 * @return array{done:int,total:int,finished:bool,pairs:int}
	 */
	public static function step( string $mode, int $from ): array {
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		/**
		 * The pages the universal importer reads (every published page, post and product by default).
		 *
		 * @param int[] $ids Post ids.
		 */
		$ids = array_values( array_map( 'intval', (array) apply_filters( 'trrocket_universal_pages', self::all_pages() ) ) );
		$rep = get_option( self::REPORT );
		$rep = is_array( $rep ) && 0 !== $from && ( $rep['mode'] ?? '' ) === $mode ? $rep : array( 'mode' => $mode, 'when' => time(), 'pages' => array() );
		if ( 'import' === $mode ) {
			\TranslateRocket\Strings::batch_start(); // one flush of maps and cache per step, not one per pair
		}
		foreach ( array_slice( $ids, $from, self::STEP ) as $id ) {
			$rep['pages'][ $id ] = self::page( (int) $id, 'import' === $mode );
		}
		$done           = min( count( $ids ), $from + self::STEP );
		$rep['done']    = $done;
		$rep['total']   = count( $ids );
		$rep['when']    = time();
		$fine           = $done >= count( $ids );
		if ( 'import' === $mode ) {
			\TranslateRocket\Strings::batch_end();
			if ( $fine && class_exists( '\\TranslateRocket\\Cache' ) ) {
				\TranslateRocket\Cache::flush();
			}
		}
		update_option( self::REPORT, $rep, false );
		$coppie = 0;
		foreach ( $rep['pages'] as $p ) {
			foreach ( (array) $p['langs'] as $l ) {
				$coppie += (int) $l['pairs'];
			}
		}
		return array( 'done' => $done, 'total' => count( $ids ), 'finished' => $fine, 'pairs' => $coppie );
	}

	/**
	 * Why the importer waits while TranslateRocket serves the languages itself.
	 */
	private static function serving_text(): string {
		if ( \TranslateRocket\Coexistence::undecided() ) {
			return __( 'First choose «Run side by side»: while TranslateRocket also serves the languages, the pages the importer reads may be its own.', 'translate-rocket' );
		}
		return __( 'TranslateRocket is serving the languages itself, so the importer would read its own pages. Switch the old plugin on and choose «Run side by side», or take the languages offline while you import.', 'translate-rocket' );
	}

	private static function status_text( string $s ): string {
		switch ( $s ) {
			case 'ok':
				return __( 'translations found', 'translate-rocket' );
			case 'other-language':
				return __( 'a translated copy: read from its original', 'translate-rocket' );
			case 'no-translations':
				return __( 'no translated version found', 'translate-rocket' );
			case 'browser-only':
				return __( 'translated only inside the visitor\'s browser: out of reach from the server', 'translate-rocket' );
			case 'unreachable':
				return __( 'the page did not open', 'translate-rocket' );
			case 'known':
				return __( 'already in TranslateRocket: nothing new to import', 'translate-rocket' );
		}
		return $s;
	}

	/**
	 * The section on the Import screen.
	 */
	public static function render( bool $valid ): void {
		$rep   = get_option( self::REPORT );
		$rep   = is_array( $rep ) ? $rep : array();
		$altri = class_exists( '\\TranslateRocket\\Coexistence' ) ? \TranslateRocket\Coexistence::active_names() : '';
		echo '<h2 id="trrocket-universal">' . esc_html__( 'Universal importer', 'translate-rocket' ) . '</h2>';
		echo '<div style="max-width:820px;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #8b5cf6">';
		echo '<p style="margin:0 0 8px">' . esc_html__( 'Coming from a translation plugin with no importer in TranslateRocket, or one whose tables cannot be read? Keep it switched on: TranslateRocket reads each page as visitors see it, in your language and in every language the old plugin shows, and pairs each sentence with its translation. It takes a pair only where the two pages have the same structure, leaves out what changes on every visit (dates, counters) and copies, and keeps links and bold words in place.', 'translate-rocket' ) . '</p>';
		if ( '' !== $altri ) {
			/* translators: %s: names of the translation plugins found */
			echo '<p style="margin:0 0 8px;color:#00a32a;font-weight:600">' . esc_html( sprintf( __( 'Found and still on: %s. Good: its pages can be read now.', 'translate-rocket' ), $altri ) ) . '</p>';
			// TranslateRocket was already serving languages when the old plugin came back: the preview
			// needs the owner's choice to run side by side (the free plugin's own button).
			if ( \TranslateRocket\Coexistence::undecided() ) {
				$scelta = wp_nonce_url( add_query_arg( 'trrocket_coexist', '1', admin_url( 'admin.php?page=translate-rocket-import' ) ), 'trrocket_coexist' );
				echo '<p style="margin:0 0 8px;padding:8px 10px;background:#fcf9e8;border:1px solid #f0e2a8;border-radius:6px">' . esc_html__( 'To see the previews, TranslateRocket must run side by side with it: the old plugin keeps the site, TranslateRocket shows its translations only to you.', 'translate-rocket' ) . ' <a class="button button-small" href="' . esc_url( $scelta ) . '">' . esc_html__( 'Run side by side', 'translate-rocket' ) . '</a></p>';
			}
		}
		echo '<p class="description" style="margin:0 0 10px">' . esc_html__( 'It works side by side, like TranslateRocket: the old plugin keeps serving your site while you import. After the import, each page has a «preview» link per language: the page with TranslateRocket\'s translations, visible only to you, next to the old plugin\'s version to compare. When you are happy, switch the old plugin off and put the languages online.', 'translate-rocket' ) . '</p>';
		echo '<p class="description" style="margin:0 0 10px">' . esc_html__( 'First a scan: nothing is saved, you see what would be imported page by page. Then the import. Translations you already have in TranslateRocket are kept. Translators that work only inside the visitor\'s browser (a Google widget) cannot be read from the server: the scan says so.', 'translate-rocket' ) . '</p>';
		$valid = $valid && ! \TranslateRocket\Coexistence::serving();
		if ( '' === $altri && \TranslateRocket\Coexistence::serving() ) {
			echo '<p style="margin:0 0 8px;padding:8px 10px;background:#fcf9e8;border:1px solid #f0e2a8;border-radius:6px">' . esc_html( self::serving_text() ) . '</p>';
		}
		echo '<p style="margin:0 0 10px"><button type="button" class="button button-primary trrocket-uni" data-mode="scan" ' . disabled( ! $valid, true, false ) . '>' . esc_html__( 'Scan the site', 'translate-rocket' ) . '</button> ';
		$scansione = ! empty( $rep ) && 'scan' === ( $rep['mode'] ?? '' ) && (int) ( $rep['done'] ?? 0 ) >= (int) ( $rep['total'] ?? 1 );
		echo '<button type="button" class="button trrocket-uni" data-mode="import" ' . disabled( ! $valid || ! $scansione, true, false ) . '>' . esc_html__( 'Import what the scan found', 'translate-rocket' ) . '</button> ';
		echo '<span class="trrocket-uni-out" aria-live="polite" style="margin-left:6px"></span></p>';
		if ( ! empty( $rep['pages'] ) ) {
			$coppie = 0;
			foreach ( $rep['pages'] as $p ) {
				foreach ( (array) $p['langs'] as $l ) {
					$coppie += (int) $l['pairs'];
				}
			}
			$fatto = 'import' === $rep['mode'] ? __( 'Imported', 'translate-rocket' ) : __( 'Last scan', 'translate-rocket' );
			/* translators: 1: «Imported» or «Last scan», 2: date, 3: pairs, 4: pages done, 5: pages */
			echo '<p style="margin:0 0 6px;font-weight:600">' . esc_html( sprintf( __( '%1$s, %2$s: %3$d sentence pairs, %4$d of %5$d pages read.', 'translate-rocket' ), $fatto, wp_date( 'j M H:i', (int) $rep['when'] ), $coppie, (int) $rep['done'], (int) $rep['total'] ) ) . '</p>';
			echo '<table class="widefat striped" style="margin:6px 0 0"><thead><tr><th>' . esc_html__( 'Page', 'translate-rocket' ) . '</th><th>' . esc_html__( 'Languages: pairs', 'translate-rocket' ) . '</th><th>' . esc_html__( 'Note', 'translate-rocket' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $rep['pages'], 0, 60, true ) as $p ) {
				$pezzi = array();
				foreach ( (array) $p['langs'] as $c => $l ) {
					$pezzo = esc_html( strtoupper( (string) $c ) ) . ': ' . (int) $l['pairs'] . ( (int) $l['skipped'] > 0 ? ' <span class="description">(' . esc_html( sprintf( /* translators: %d: sentences left out */ __( '%d left out', 'translate-rocket' ), (int) $l['skipped'] ) ) . ')</span>' : '' );
					// Side by side, like the free plugin: after the import, see the page in TranslateRocket's
					// own translation while the old plugin still serves the site, and compare.
					if ( 'import' === $rep['mode'] && (int) $l['pairs'] > 0 && \TranslateRocket\Coexistence::on() ) {
						$pezzo .= ' <a class="trrocket-uni-prev" href="' . esc_url( \TranslateRocket\Coexistence::preview_url( (string) $c, (string) $p['url'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'preview', 'translate-rocket' ) . ' ↗</a>';
						$pezzo .= ' <a href="' . esc_url( (string) $l['url'] ) . '" target="_blank" rel="noopener" class="description">' . esc_html__( 'old plugin', 'translate-rocket' ) . ' ↗</a>';
					}
					$pezzi[] = $pezzo;
				}
				echo '<tr><td>' . esc_html( (string) $p['title'] ) . '</td><td>' . ( $pezzi ? implode( ' · ', $pezzi ) : '—' ) . '</td><td>' . esc_html( self::status_text( (string) $p['status'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pieces escaped above.
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		?>
		<script>
		( function () {
			var out = document.querySelector( '.trrocket-uni-out' );
			function passo( mode, from ) {
				var d = new FormData();
				d.append( 'action', 'trrocket_universal_step' );
				d.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?> );
				d.append( 'mode', mode );
				d.append( 'from', from );
				return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: d } ).then( function ( r ) { return r.json(); } ).then( function ( j ) {
					if ( ! j || ! j.success ) { out.textContent = '✗ ' + ( j && j.data ? j.data : '' ); return; }
					out.textContent = j.data.done + ' / ' + j.data.total + ' — ' + j.data.pairs + ' ' + <?php echo wp_json_encode( __( 'pairs', 'translate-rocket' ) ); ?>;
					if ( j.data.finished ) { window.location.hash = 'trrocket-universal'; window.location.reload(); return; }
					return passo( mode, j.data.done );
				} );
			}
			document.addEventListener( 'click', function ( e ) {
				var b = e.target.closest ? e.target.closest( '.trrocket-uni' ) : null;
				if ( ! b ) { return; }
				Array.prototype.forEach.call( document.querySelectorAll( '.trrocket-uni' ), function ( x ) { x.disabled = true; } );
				out.textContent = <?php echo wp_json_encode( __( 'Reading the pages…', 'translate-rocket' ) ); ?>;
				passo( b.getAttribute( 'data-mode' ), 0 );
			} );
		}() );
		</script>
		<?php
	}
}
