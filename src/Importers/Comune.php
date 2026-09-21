<?php
/**
 * The bits every importer needs.
 *
 * Two things kept coming out identical in each new importer: turning another
 * plugin's language code into ours, and cutting a body of HTML into the same
 * lines the rest of the plugin works with. Copied a sixth time they would drift
 * apart, so they live here.
 *
 * Note: PolylangImporter and WpmlImporter carry their own copies of the mapping,
 * written before this class existed. They are shipped and working, and changing
 * them buys nothing today — but if that mapping ever needs a new entry, add it
 * here **and** there until they are unified.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for the importers.
 */
class Comune {

	/**
	 * Another plugin's language code (or a WordPress locale) into ours.
	 *
	 * Handles `it_IT`, `pt-BR`, `zh_CN` and friends. Everything unknown falls
	 * back to the first two letters, which is right far more often than not.
	 */
	public static function lingua( string $codice ): string {
		$codice = strtolower( str_replace( '-', '_', trim( $codice ) ) );

		$mappa = array(
			'pt_br'   => 'pt-br',
			'pt_pt'   => 'pt',
			'zh_cn'   => 'zh',
			'zh_hans' => 'zh',
			'zh_tw'   => 'zh-tw',
			'zh_hant' => 'zh-tw',
			'sr_rs'   => 'sr',
			'nb_no'   => 'nb',
			'nn_no'   => 'nn',
		);
		if ( isset( $mappa[ $codice ] ) ) {
			return $mappa[ $codice ];
		}

		return strlen( $codice ) > 2 ? substr( $codice, 0, 2 ) : $codice;
	}

	/**
	 * A body of HTML, one line per translatable block.
	 *
	 * The same shape every importer uses, so a site arriving from any of them
	 * ends up with strings of the same granularity — and so the same sentence
	 * imported twice from two places lands on the same row.
	 *
	 * @return string[]
	 */
	public static function righe( string $html ): array {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$html = (string) preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#</(p|h[1-6]|li|div|blockquote|figcaption|td|th)>#i', "\n", $html );
		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$testo = wp_strip_all_tags( $html );

		$fuori = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $testo ) as $riga ) {
			$riga = trim( $riga );
			// Una riga senza nemmeno una lettera (numeri, simboli, separatori)
			// non e' una frase da tradurre.
			if ( '' !== $riga && preg_match( '/\p{L}/u', $riga ) ) {
				$fuori[] = $riga;
			}
		}
		return $fuori;
	}

	/**
	 * Pair two versions of the same post: title, excerpt and body.
	 *
	 * The body is only paired when both sides cut into the **same number of
	 * lines**. Anything else would pair a paragraph with the wrong one, which
	 * is worse than importing nothing: a wrong translation is invisible until
	 * a reader trips over it.
	 *
	 * @param object|\WP_Post $origine   The post in the source language.
	 * @param object|\WP_Post $tradotto  The same post in another language.
	 * @param string          $lingua    Our language code.
	 * @param bool            $salva     Whether to store, or only count.
	 */
	public static function appaia( $origine, $tradotto, string $lingua, bool $salva ): int {
		$fatti = 0;

		$coppie = array(
			array( (string) $origine->post_title, (string) $tradotto->post_title ),
			array( (string) $origine->post_excerpt, (string) $tradotto->post_excerpt ),
		);
		foreach ( $coppie as $c ) {
			if ( self::coppia( $c[0], $lingua, $c[1], $salva ) ) {
				++$fatti;
			}
		}

		$a = self::righe( (string) $origine->post_content );
		$b = self::righe( (string) $tradotto->post_content );
		if ( ! empty( $a ) && count( $a ) === count( $b ) ) {
			foreach ( $a as $i => $riga ) {
				if ( self::coppia( $riga, $lingua, $b[ $i ], $salva ) ) {
					++$fatti;
				}
			}
		}

		// A page built with a page builder has an empty post_content: its words
		// are in a meta. Without this, such a site imports nothing of its pages.
		$fatti += self::builder( $origine, $tradotto, $lingua, $salva );

		return $fatti;
	}

	/**
	 * The page builders that keep the page text OUTSIDE `post_content`.
	 *
	 * A page built with Elementor has an empty `post_content`: every word lives
	 * in the `_elementor_data` meta, as JSON. Until 21/09/2026 the importers
	 * read only title, excerpt and `post_content`, so a site arriving from WPML
	 * or Polylang with an Elementor-built site brought over **nothing** of its
	 * pages — while the readme promised "one click imports everything you have
	 * already paid for or typed by hand". Polylang/WPML + Elementor is one of
	 * the most common combinations there is, and it is what Alain came from.
	 *
	 * Only formats that are plain JSON are read. Beaver Builder keeps PHP
	 * objects in a serialized blob; unserializing that safely is a different
	 * job, and half-doing it would be worse than not doing it.
	 *
	 * @var array<string,string>
	 */
	const BUILDER_META = array(
		'_elementor_data'        => 'Elementor',
		'_bricks_page_content_2' => 'Bricks',
	);

	/**
	 * Keys inside `settings` that are never page text, whatever their value.
	 *
	 * @var string[]
	 */
	const CHIAVI_TECNICHE = array( '_id', 'id', '_element_id', '_css_classes', 'elType', 'widgetType', 'spectraId', 'block_id', 'uniqueId', 'blockId', 'className', 'anchor', 'tagName', 'align' );

	/**
	 * Pair the text of a page built with a page builder.
	 *
	 * WPML and Polylang translate a page by **duplicating** it, so the two
	 * copies carry the same tree with the same widgets in the same places. That
	 * is what makes this safe: every value is paired with the one at the very
	 * same path, never by position in a flat list — pairing a paragraph with
	 * the wrong one is worse than importing nothing, because a wrong
	 * translation stays invisible until a reader trips over it.
	 *
	 * Anything identical in both languages is dropped by `coppia()`: that alone
	 * removes every colour, size and setting, with no list of which widget
	 * field is text — so widgets from plugins nobody has heard of work too.
	 *
	 * @param object|\WP_Post $origine  The post in the source language.
	 * @param object|\WP_Post $tradotto The same post in another language.
	 * @param string          $lingua   Our language code.
	 * @param bool            $salva    Whether to store, or only count.
	 */
	public static function builder( $origine, $tradotto, string $lingua, bool $salva ): int {
		$fatti = 0;
		if ( empty( $origine->ID ) || empty( $tradotto->ID ) ) {
			return 0;
		}

		foreach ( array_keys( self::BUILDER_META ) as $chiave ) {
			$a = self::campi( (int) $origine->ID, $chiave );
			$b = self::campi( (int) $tradotto->ID, $chiave );
			if ( empty( $a ) || empty( $b ) ) {
				continue;
			}
			foreach ( $a as $percorso => $testo ) {
				if ( ! isset( $b[ $percorso ] ) ) {
					continue;
				}
				$fatti += self::testo_o_righe( (string) $testo, (string) $b[ $percorso ], $lingua, $salva );
			}
		}

		// Whole sentences of the page content (a link or a bold word inside).
		// The Polylang and WPML importers pair the content line by line with their
		// own code, so this is where those sentences are added for everyone.
		$fatti += self::unite( (string) $origine->post_content, (string) $tradotto->post_content, $lingua, $salva );

		// Blocks that keep their words in their attributes, not in their HTML.
		// Spectra 3 is the case that surfaced it (21/09/2026): every text of
		// `<!-- wp:spectra/content {"text":"…"} /-->` lives in the comment, the
		// block has no HTML at all, and 26,642 characters of page came out as 4
		// lines. The HTML of ordinary blocks is already read by `righe()`; here
		// only the attributes are, so nothing is counted twice.
		$a = self::attributi_blocchi( (string) $origine->post_content );
		$b = self::attributi_blocchi( (string) $tradotto->post_content );
		foreach ( $a as $percorso => $testo ) {
			if ( isset( $b[ $percorso ] ) ) {
				$fatti += self::testo_o_righe( (string) $testo, (string) $b[ $percorso ], $lingua, $salva );
			}
		}

		return $fatti;
	}

	/**
	 * The text held in block attributes, as path => text.
	 *
	 * A block is addressed by its own id when it has one — Spectra's
	 * `spectraId`, the `block_id` / `uniqueId` of other block libraries — for
	 * the same reason as Elementor widgets: a block added in one language only
	 * must not shift every pairing after it.
	 *
	 * @return array<string,string>
	 */
	private static function attributi_blocchi( string $contenuto ): array {
		if ( false === strpos( $contenuto, '<!-- wp:' ) || ! function_exists( 'parse_blocks' ) ) {
			return array();
		}
		if ( strlen( $contenuto ) > 4194304 ) {
			return array();
		}
		$fuori = array();
		self::scendi_blocchi( parse_blocks( $contenuto ), '', $fuori );
		return $fuori;
	}

	/**
	 * @param array<int,array<string,mixed>> $blocchi  Parsed blocks.
	 * @param string                         $percorso Path so far.
	 * @param array<string,string>           $fuori    Collected values, by path.
	 */
	private static function scendi_blocchi( array $blocchi, string $percorso, array &$fuori ): void {
		if ( substr_count( $percorso, '/' ) > 30 ) {
			return;
		}
		foreach ( $blocchi as $i => $blocco ) {
			if ( ! is_array( $blocco ) || empty( $blocco['blockName'] ) ) {
				continue;
			}
			$attr = isset( $blocco['attrs'] ) && is_array( $blocco['attrs'] ) ? $blocco['attrs'] : array();
			$id   = '';
			foreach ( array( 'spectraId', 'block_id', 'uniqueId', 'blockId' ) as $k ) {
				if ( isset( $attr[ $k ] ) && is_string( $attr[ $k ] ) && '' !== $attr[ $k ] ) {
					$id = $attr[ $k ];
					break;
				}
			}
			$qui = $percorso . '/' . (string) $blocco['blockName'] . ( '' !== $id ? '#' . $id : '@' . $i );
			if ( ! empty( $attr ) ) {
				self::scendi( $attr, $qui, true, $fuori );
			}
			if ( ! empty( $blocco['innerBlocks'] ) && is_array( $blocco['innerBlocks'] ) ) {
				self::scendi_blocchi( $blocco['innerBlocks'], $qui, $fuori );
			}
		}
	}

	/**
	 * The translatable values of one builder meta, as path => text.
	 *
	 * Only values under a `settings` node are taken: in Elementor and in Bricks
	 * that is where the words are, while ids and element types sit outside it.
	 *
	 * @return array<string,string>
	 */
	private static function campi( int $post_id, string $chiave ): array {
		$grezzo = get_post_meta( $post_id, $chiave, true );
		if ( ! is_string( $grezzo ) || '' === $grezzo ) {
			return array();
		}
		// A page's builder data is measured in kilobytes. Something in the
		// megabytes is broken or hostile, and decoding it could exhaust the
		// memory of a shared host halfway through an import — which would
		// leave the site with a job half done and no way to tell.
		if ( strlen( $grezzo ) > 4194304 ) {
			return array();
		}
		$dati = json_decode( $grezzo, true );
		if ( ! is_array( $dati ) ) {
			return array();
		}
		$fuori = array();
		self::scendi( $dati, '', false, $fuori );
		return $fuori;
	}

	/**
	 * Walk the tree, collecting the text found under `settings`.
	 *
	 * @param mixed                $nodo     Current node.
	 * @param string               $percorso Path so far.
	 * @param bool                 $dentro   Whether we are inside a `settings` node.
	 * @param array<string,string> $fuori    Collected values, by path.
	 */
	private static function scendi( $nodo, string $percorso, bool $dentro, array &$fuori ): void {
		if ( is_string( $nodo ) ) {
			if ( $dentro && self::e_testo( $nodo ) ) {
				$fuori[ $percorso ] = $nodo;
			}
			return;
		}
		if ( ! is_array( $nodo ) ) {
			return;
		}
		// A corrupted or hostile meta must not recurse for ever.
		if ( substr_count( $percorso, '.' ) > 40 ) {
			return;
		}
		// A pattern's own label and description ("Single column layout with ...")
		// sit next to its category: they describe the pattern to the editor and
		// never appear on the page.
		$schema = isset( $nodo['categorySlug'] ) || isset( $nodo['category'] );
		foreach ( $nodo as $k => $v ) {
			$k = (string) $k;
			if ( $dentro && in_array( $k, self::CHIAVI_TECNICHE, true ) ) {
				continue;
			}
			if ( 'metadata' === $k || ( $schema && in_array( $k, array( 'description', 'title', 'name', 'category', 'categorySlug' ), true ) ) ) {
				continue;
			}
			// An element is addressed by its own id, not by where it sits in the
			// list: the translated copy keeps the same widget ids, but a widget
			// added in one language only shifts everything after it. Addressing
			// by position would then stop pairing at the first such widget —
			// and every real translation after it would be lost.
			$segmento = $k;
			if ( is_array( $v ) && isset( $v['id'] ) && is_string( $v['id'] ) && '' !== $v['id'] ) {
				$segmento = '#' . $v['id'];
			}
			self::scendi( $v, '' === $percorso ? $segmento : $percorso . '.' . $segmento, $dentro || 'settings' === $k, $fuori );
		}
	}

	/**
	 * Is this value page text, rather than a link or an id?
	 *
	 * Links are excluded because the other plugin translates those too, and an
	 * address stored as the translation of another address would then be put
	 * into the page as if it were text.
	 */
	private static function e_testo( string $v ): bool {
		$v = trim( $v );
		if ( '' === $v || ! preg_match( '/\p{L}/u', $v ) ) {
			return false;
		}
		if ( ! preg_match( '/\s/', $v ) && preg_match( '#^(https?://|//|/|\#|mailto:|tel:|data:)#i', $v ) ) {
			return false;
		}
		// An id such as "a1b2c3" or "4f8e91d": letters and digits, no spaces.
		if ( preg_match( '/^[0-9a-f]{4,10}$/i', $v ) && preg_match( '/\d/', $v ) ) {
			return false;
		}
		// A generated id such as "spectra-mgt744fe-33i4ak": one word of letters,
		// digits and dashes, with at least one digit. A sentence has spaces.
		if ( preg_match( '/^[A-Za-z0-9_-]+$/', $v ) && preg_match( '/\d/', $v ) && preg_match( '/[-_]/', $v ) ) {
			return false;
		}
		// A style, not a sentence: a block keeps gradients, colours and sizes in
		// its settings too ("linear-gradient(180deg, var(--x) 0%, ...)"). Found in
		// the 21/09 WPML test, imported as if it were text.
		if ( preg_match( '/(^|[\s,(])(var|calc|rgba?|hsla?|linear-gradient|radial-gradient|conic-gradient|url)\s*\(/i', $v ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Pair one value: whole when it is a line, line by line when it is HTML.
	 */
	private static function testo_o_righe( string $a, string $b, string $lingua, bool $salva ): int {
		if ( false === strpos( $a, '<' ) && false === strpos( $b, '<' ) ) {
			return self::coppia( $a, $lingua, $b, $salva ) ? 1 : 0;
		}
		$fatti = self::unite( $a, $b, $lingua, $salva );
		$ra    = self::righe( $a );
		$rb    = self::righe( $b );
		if ( empty( $ra ) || count( $ra ) !== count( $rb ) ) {
			return $fatti;
		}
		foreach ( $ra as $i => $riga ) {
			if ( self::coppia( $riga, $lingua, $rb[ $i ], $salva ) ) {
				++$fatti;
			}
		}
		return $fatti;
	}

	/**
	 * Pair the sentences the engine reads WHOLE: text with a link or a bold word
	 * inside, which since 1.5.0 travels as "Read the <1>booking conditions</1>
	 * before you confirm." `righe()` strips the tags and stores "Read the booking
	 * conditions before you confirm.", which the engine never looks up — so every
	 * imported paragraph with a link or a bold word stayed in the source language
	 * (found 21/09/2026; before 1.5.0 it failed too, the engine cut such a
	 * sentence into pieces).
	 *
	 * Paired only when both sides hold the same number of such sentences and each
	 * pair carries the same placeholders: anything else would put a link on the
	 * wrong words.
	 */
	private static function unite( string $a, string $b, string $lingua, bool $salva ): int {
		$ua = self::frasi_unite( $a );
		$ub = self::frasi_unite( $b );
		if ( empty( $ua ) || count( $ua ) !== count( $ub ) ) {
			return 0;
		}
		$fatti = 0;
		foreach ( $ua as $i => $frase ) {
			if ( \TranslateRocket\Frontend\InlineText::parts_ok( $frase, $ub[ $i ] )
				&& self::coppia( $frase, $lingua, $ub[ $i ], $salva ) ) {
				++$fatti;
			}
		}
		return $fatti;
	}

	/**
	 * The whole sentences of a piece of HTML, in the engine's own shape.
	 *
	 * Built with the engine's own `InlineText::units()` and `source()`, so the
	 * shape cannot drift from what the page is looked up with. Code, preformatted
	 * text, scripts, styles, text areas and anything marked translate="no" are
	 * left out, as the engine leaves them out.
	 *
	 * @return string[] In document order.
	 */
	public static function frasi_unite( string $html ): array {
		if ( false === strpos( $html, '<' ) || strlen( $html ) > 4194304
			|| ! class_exists( '\TranslateRocket\Frontend\InlineText' ) ) {
			return array();
		}
		$doc  = new \DOMDocument();
		$prec = libxml_use_internal_errors( true );
		$ok   = $doc->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prec );
		if ( ! $ok ) {
			return array();
		}
		$skip = 'ancestor-or-self::script or ancestor-or-self::style or ancestor-or-self::code'
			. ' or ancestor-or-self::pre or ancestor-or-self::textarea'
			. " or ancestor-or-self::*[@translate='no']";
		$fuori = array();
		foreach ( \TranslateRocket\Frontend\InlineText::units( new \DOMXPath( $doc ), $skip ) as $el ) {
			$frase = \TranslateRocket\Frontend\InlineText::source( $el );
			if ( '' !== $frase ) {
				$fuori[] = $frase;
			}
		}
		return $fuori;
	}

	/**
	 * Store one pair, or just say whether it would count.
	 */
	public static function coppia( string $origine, string $lingua, string $testo, bool $salva ): bool {
		$forme_o = self::forme( $origine );
		$forme_t = self::forme( $testo );
		if ( empty( $forme_o ) || empty( $forme_t ) ) {
			return false;
		}
		// Identiche non e' una traduzione: e' una copia. Salvarla sarebbe peggio che
		// inutile: la frase risulterebbe tradotta, resterebbe nella lingua di partenza
		// sulla pagina tradotta, e la traduzione automatica non la toccherebbe piu'.
		// (Visto il 21/9 nel collaudo con WPML: titoli di pagine mai tradotte importati
		// come «traduzioni» identiche.)
		if ( $forme_o[0] === $forme_t[0] ) {
			return false;
		}
		// "u003cstrongu003e", "u0026amp;": JSON escapes that lost their backslash
		// when the other copy was saved without slashing. Such a "translation" is
		// damaged text, and importing it would put the damage on the page.
		$residuo = '/u00(3c|3e|26|22|27|2d|2f)/i';
		if ( preg_match( $residuo, $forme_t[0] ) && ! preg_match( $residuo, $forme_o[0] ) ) {
			return false;
		}
		if ( ! $salva ) {
			return true;
		}
		$fatto = false;
		foreach ( $forme_o as $i => $forma ) {
			$tradotta = $forme_t[ $i ] ?? $forme_t[0];
			if ( \TranslateRocket\Strings::store_imported( $forma, $lingua, $tradotta ) ) {
				$fatto = true;
			}
		}
		return $fatto;
	}

	/**
	 * The shapes a piece of imported text can take on the page.
	 *
	 * The engine looks a sentence up exactly as it reads it in the page, after
	 * WordPress has dressed it: `&amp;` is `&`, `don't` is `don’t`, ` - ` is
	 * ` – `. The other plugin's tables hold it as it was typed. Stored only in
	 * that raw shape, an imported translation of any sentence with an
	 * apostrophe, a quote, a dash or an ampersand was never used — the page
	 * stayed in the source language and nothing said why (found 21/09/2026:
	 * two rows for the same sentence, the imported one never read).
	 *
	 * Both shapes are returned, raw first, because not every text goes through
	 * WordPress' dressing: titles and post content do, while a page builder's
	 * heading widget prints its text as it is. Storing both costs one row when
	 * they differ and nothing when they do not.
	 *
	 * @return string[] Distinct non-empty shapes, raw (decoded) first.
	 */
	public static function forme( string $testo ): array {
		$testo = trim( $testo );
		if ( '' === $testo ) {
			return array();
		}
		$grezza  = trim( html_entity_decode( $testo, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$vestita = trim( html_entity_decode( wptexturize( $testo ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$fuori   = array();
		foreach ( array( $grezza, $vestita ) as $f ) {
			if ( '' !== $f && ! in_array( $f, $fuori, true ) ) {
				$fuori[] = $f;
			}
		}
		return $fuori;
	}
}
