<?php
/**
 * Whole sentences, even when a link or a bold word cuts through them.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * A paragraph like
 *
 *     <p>Read the <a href="/terms/">booking conditions</a> before you confirm.</p>
 *
 * used to reach the translator as three separate strings: "Read the",
 * "booking conditions", "before you confirm.". Nobody can translate "Read the"
 * on its own, and in German or Japanese the words have to move across those
 * pieces, which is impossible once they are apart.
 *
 * This class reads the paragraph as ONE string, with the inline elements kept
 * as numbered placeholders:
 *
 *     Read the <1>booking conditions</1> before you confirm.
 *
 * and puts the translation back into the same tags afterwards. The tags, their
 * attributes and anything that is not text are never sent anywhere: only the
 * words are.
 */
class InlineText {

	/**
	 * Elements that live INSIDE a sentence. Anything else ends it.
	 */
	const INLINE = array(
		'a', 'abbr', 'b', 'bdi', 'bdo', 'big', 'cite', 'code', 'data', 'del',
		'dfn', 'em', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small',
		'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
	);

	/**
	 * Inline elements with no text of their own: they hold a place in the
	 * sentence (a line break, an icon) and come back as <N/>.
	 */
	const VOID_INLINE = array( 'br', 'img' );

	/**
	 * Highest number of placeholders in one sentence. Past this the sentence is
	 * not really a sentence any more (a menu, a list of links glued together),
	 * and a translator would only get confused.
	 */
	const MAX_PARTS = 12;

	/**
	 * Is this node an inline element?
	 *
	 * @param \DOMNode $node Node.
	 */
	public static function is_inline( \DOMNode $node ): bool {
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return false;
		}
		$tag = strtolower( $node->nodeName );
		return in_array( $tag, self::INLINE, true ) || in_array( $tag, self::VOID_INLINE, true );
	}

	/**
	 * Elements whose content is one sentence cut by inline tags.
	 *
	 * An element qualifies when it holds at least one text node with letters AND
	 * at least one inline element, and nothing else (no nested paragraphs, no
	 * divs): those are handled on their own, deeper down.
	 *
	 * @param \DOMXPath $xpath Document xpath.
	 * @param string    $skip  XPath predicate for ELEMENTS to leave alone. It must be
	 *                         the ancestor-or-self one: here an element is chosen, and
	 *                         an element is not its own ancestor.
	 * @return \DOMElement[]
	 */
	public static function units( \DOMXPath $xpath, string $skip ): array {
		$out = array();
		// Any element that directly contains both text with letters and an inline
		// child. The rest of the filtering is cheaper in PHP than in XPath.
		$els = $xpath->query( "//*[text()[normalize-space()]][*][not({$skip})]" );
		if ( false === $els ) {
			return $out;
		}
		foreach ( $els as $el ) {
			if ( ! self::is_unit( $el ) ) {
				continue;
			}
			// ⚠️ Non basta che il contenitore sia buono: se DENTRO c'e' qualcosa da non
			// toccare — un <code> in linea, uno <span translate="no">, un selettore
			// escluso dall'utente — la frase intera lo spedirebbe al traduttore insieme
			// al resto. In quel caso si lascia perdere l'unione e si torna al giro sui
			// pezzi, che quelle esclusioni le rispetta. Trovato in revisione il 20/9.
			$dentro = $xpath->query( ".//*[{$skip}]", $el );
			if ( false !== $dentro && $dentro->length > 0 ) {
				continue;
			}
			$out[] = $el;
		}
		// ⚠️ Unita' dentro un'altra unita': <div>Testo <span>a <a>b</a></span></div> le
		// prendeva tutte e due, quindi la stessa frase veniva raccolta e PAGATA due volte.
		// Si tiene solo quella piu' esterna: e' la frase che il lettore legge.
		$fuori = array();
		foreach ( $out as $el ) {
			$dentro_di_altra = false;
			foreach ( $out as $altro ) {
				if ( $altro !== $el && self::e_dentro( $el, $altro ) ) {
					$dentro_di_altra = true;
					break;
				}
			}
			if ( ! $dentro_di_altra ) {
				$fuori[] = $el;
			}
		}
		return $fuori;
	}

	/**
	 * Il primo elemento sta dentro il secondo?
	 *
	 * @param \DOMNode $figlio Possibile discendente.
	 * @param \DOMNode $padre  Possibile antenato.
	 */
	private static function e_dentro( \DOMNode $figlio, \DOMNode $padre ): bool {
		for ( $n = $figlio->parentNode; null !== $n; $n = $n->parentNode ) {
			if ( $n === $padre ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this element's children are one sentence: text and inline only,
	 * with at least one of each, and at least one inline part carrying words.
	 *
	 * @param \DOMElement $el Element.
	 */
	private static function is_unit( \DOMElement $el ): bool {
		$has_text   = false;
		$has_inline = false;
		$parts      = 0;
		foreach ( $el->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				if ( preg_match( '/\p{L}/u', (string) $child->nodeValue ) ) {
					$has_text = true;
				}
				continue;
			}
			if ( XML_COMMENT_NODE === $child->nodeType ) {
				continue;
			}
			if ( ! self::is_inline( $child ) || ! self::inline_is_simple( $child ) ) {
				return false;
			}
			$has_inline = true;
			++$parts;
		}
		return $has_text && $has_inline && $parts <= self::MAX_PARTS;
	}

	/**
	 * An inline element is "simple" when everything inside it is text or more
	 * inline elements. A link wrapped around a whole card is not.
	 *
	 * @param \DOMNode $node Node.
	 */
	private static function inline_is_simple( \DOMNode $node ): bool {
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType || XML_COMMENT_NODE === $child->nodeType ) {
				continue;
			}
			if ( ! self::is_inline( $child ) || ! self::inline_is_simple( $child ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The sentence as one string, with <1>…</1> where the inline elements were.
	 *
	 * Whitespace is collapsed the way a browser shows it, so the same sentence
	 * written on three lines in the editor is one string here.
	 *
	 * @param \DOMElement $el Element.
	 * @return string Empty when there is nothing worth translating.
	 */
	public static function source( \DOMElement $el ): string {
		$n   = 0;
		$out = self::walk( $el, $n );
		$out = trim( (string) preg_replace( '/\s+/u', ' ', $out ) );
		return preg_match( '/\p{L}/u', $out ) ? $out : '';
	}

	/**
	 * An inline element with no words inside: un'icona, il posto della rotellina.
	 * Va tenuto, ma non ha niente da tradurre, quindi diventa <N/> e quello che
	 * contiene si ricopia tale e quale.
	 *
	 * @param \DOMNode $node Node.
	 */
	private static function is_empty_inline( \DOMNode $node ): bool {
		if ( in_array( strtolower( $node->nodeName ), self::VOID_INLINE, true ) ) {
			return true;
		}
		return '' === trim( (string) $node->textContent );
	}

	/**
	 * Build the string for an element's children, numbering the inline parts.
	 *
	 * @param \DOMNode $el Element whose children to read.
	 * @param int      $n  Running placeholder number (by reference).
	 */
	private static function walk( \DOMNode $el, int &$n ): string {
		$out = '';
		foreach ( $el->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$out .= self::escape( (string) $child->nodeValue );
				continue;
			}
			if ( ! self::is_inline( $child ) ) {
				continue;
			}
			++$n;
			$k = $n;
			if ( self::is_empty_inline( $child ) ) {
				$out .= '<' . $k . '/>';
				continue;
			}
			$out .= '<' . $k . '>' . self::walk( $child, $n ) . '</' . $k . '>';
		}
		return $out;
	}

	/**
	 * The whole sentence put together from the translations of its pieces.
	 *
	 * Until the sentence was read as one (with <1>…</1> where a link or a bold word
	 * sits), every piece was a string of its own, and older sites translated them one
	 * by one: «Translate WordPress» and «into every language». Visitors still see those
	 * pieces translated, but the sentence itself has no translation, so the visual
	 * editor showed it in the source language with an empty box — 378 sentences on
	 * translaterocket.com alone (24/9/2026). This builds the sentence from the pieces,
	 * keeping the placeholders and the spaces around each piece.
	 *
	 * @param string               $src The sentence with its placeholders.
	 * @param array<string,string> $map Translations by source text (the pieces).
	 * @return string|null Null unless EVERY piece with a letter in it has a translation.
	 */
	public static function compose( string $src, array $map ): ?string {
		if ( ! self::has_parts( $src ) ) {
			return null;
		}
		$parts = preg_split( '#(</?\d{1,3}/?>)#', $src, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$out   = '';
		$fatti = 0;
		foreach ( $parts as $k => $part ) {
			if ( 1 === $k % 2 ) {
				$out .= $part; // Il segnaposto, tale e quale.
				continue;
			}
			$testo = trim( self::unescape( $part ) );
			if ( '' === $testo || ! preg_match( '/\p{L}/u', $testo ) ) {
				$out .= $part;
				continue;
			}
			if ( ! isset( $map[ $testo ] ) || '' === trim( (string) $map[ $testo ] ) ) {
				return null;
			}
			preg_match( '/^\s*/u', $part, $prima );
			preg_match( '/\s*$/u', $part, $dopo );
			$out .= ( $prima[0] ?? '' ) . self::escape( trim( (string) $map[ $testo ] ) ) . ( $dopo[0] ?? '' );
			++$fatti;
		}
		return $fatti > 0 ? $out : null;
	}

	/**
	 * The pieces of a sentence that each need a translation of their own.
	 *
	 * @return string[]
	 */
	public static function pieces( string $src ): array {
		$out = array();
		foreach ( preg_split( '#</?\d{1,3}/?>#', $src ) ?: array() as $part ) {
			$t = trim( self::unescape( $part ) );
			if ( '' !== $t && preg_match( '/\p{L}/u', $t ) ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * Does this sentence carry placeholders?
	 *
	 * @param string $text Text.
	 */
	public static function has_parts( string $text ): bool {
		return (bool) preg_match( '#<(\d{1,3})(/?)>|</(\d{1,3})>#', $text );
	}

	/**
	 * The name of the tag a placeholder becomes when it is sent to a translation
	 * service: XML tag names cannot start with a digit, so <1> travels as <trrp1>.
	 *
	 * @param int|string $n Placeholder number.
	 */
	public static function tag_name( $n ): string {
		return 'trrp' . (int) $n;
	}

	/**
	 * Get a sentence ready for a service that understands XML or HTML tags.
	 * Also escapes "&", so the whole thing is valid XML.
	 *
	 * @param string $text Sentence with placeholders.
	 */
	public static function to_tags( string $text ): string {
		$text = str_replace( '&', '&amp;', $text );
		return (string) preg_replace_callback(
			'#<(/?)(\d{1,3})(/?)>#',
			static function ( $m ) {
				$tag = self::tag_name( $m[2] );
				if ( '/' === $m[1] ) {
					return '</' . $tag . '>';
				}
				return '/' === $m[3] ? '<' . $tag . '/>' : '<' . $tag . '>';
			},
			$text
		);
	}

	/**
	 * Undo to_tags() on what comes back. Tolerant: services move the tags around,
	 * add a space before the slash, or send back an attribute we never wrote.
	 *
	 * @param string $text Translated sentence with tags.
	 */
	public static function from_tags( string $text ): string {
		$text = (string) preg_replace_callback(
			'#<\s*(/?)\s*trrp(\d{1,3})\b[^>]*?(/?)\s*>#i',
			static function ( $m ) {
				if ( '/' === $m[1] ) {
					return '</' . $m[2] . '>';
				}
				return '/' === $m[3] ? '<' . $m[2] . '/>' : '<' . $m[2] . '>';
			},
			$text
		);
		// Le entita' aggiunte o tenute dal servizio tornano lettere. Il «<» che stava
		// nel testo di partenza deve restare escapato: non e' un segnaposto.
		// ⚠️ Prima si usava «&#60;» come segnale: ma html_entity_decode decodifica ANCHE
		// i riferimenti numerici, quindi il segnale spariva e il «<» tornava grezzo, con
		// il rischio di inventare un segnaposto che non c'era. Ora si usa un carattere di
		// controllo, che nessuna decodifica tocca.
		$segno = chr( 2 ) . 'LT' . chr( 2 );
		$text  = str_replace( array( '&lt;', '&LT;' ), $segno, $text );
		$text  = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return str_replace( $segno, '&lt;', $text );
	}

	/**
	 * Did the translation come back with exactly the placeholders it was given?
	 * A translation that lost them can never be put back into the page, so it is
	 * better to throw it away than to save it.
	 *
	 * @param string $source      Original sentence.
	 * @param string $translation Translated sentence.
	 */
	public static function parts_ok( string $source, string $translation ): bool {
		// ⚠️ Prima si confrontavano i segnaposto come pezzi di testo identici. Due guai:
		// un servizio che restituisce <1></1> al posto di <1/> faceva rifiutare la frase
		// PER SEMPRE (e ripagare a ogni giro), e una traduzione con la chiusura prima
		// dell'apertura passava il controllo per poi non essere applicabile, lasciando la
		// pagina in lingua originale mentre l'elenco diceva «tradotta». Adesso si guarda
		// quali numeri ci sono e che siano annidati bene, come fa chi la rimette in pagina.
		return self::numeri( $source ) === self::numeri( self::normalize( $source, $translation ) )
			&& self::ben_formata( self::normalize( $source, $translation ) );
	}

	/**
	 * I numeri dei segnaposto presenti, in ordine.
	 *
	 * @param string $text Text.
	 * @return int[]
	 */
	private static function numeri( string $text ): array {
		preg_match_all( '#<(?:/)?(\d{1,3})(?:/)?>#', $text, $m );
		$n = array_map( 'intval', $m[1] );
		$n = array_values( array_unique( $n ) );
		sort( $n );
		return $n;
	}

	/**
	 * Annidamento corretto: ogni apertura ha la sua chiusura, nell'ordine giusto.
	 *
	 * @param string $text Text.
	 */
	private static function ben_formata( string $text ): bool {
		preg_match_all( '#<(/?)(\d{1,3})(/?)>#', $text, $m, PREG_SET_ORDER );
		$pila = array();
		foreach ( $m as $t ) {
			if ( '/' === $t[3] ) {
				continue;   // <N/>: sta in piedi da solo.
			}
			if ( '/' === $t[1] ) {
				if ( array_pop( $pila ) !== $t[2] ) {
					return false;
				}
				continue;
			}
			$pila[] = $t[2];
		}
		return empty( $pila );
	}

	/**
	 * Rimette in riga le differenze innocue: un servizio che restituisce <1></1> dove
	 * c'era <1/> (Google lo fa spesso in modalita' HTML) non deve farci buttare via la
	 * traduzione. Se dentro ci ha messo del testo, quel testo si tiene, fuori dal segno.
	 *
	 * @param string $source      Frase di partenza.
	 * @param string $translation Traduzione tornata dal servizio.
	 */
	public static function normalize( string $source, string $translation ): string {
		preg_match_all( '#<(\d{1,3})/>#', $source, $m );
		foreach ( $m[1] as $n ) {
			$translation = (string) preg_replace(
				'#<' . $n . '>(.*?)</' . $n . '>#s',
				'<' . $n . '/>$1',
				$translation
			);
		}
		return $translation;
	}

	/**
	 * WordPress, when it cleans up a submitted field, throws away anything between
	 * "<" and ">", and our placeholders go with it: a person correcting a sentence
	 * by hand would see the marks disappear and the correction refused, with no way
	 * to get it right. So they are set aside as plain letters while WordPress does
	 * its cleaning, and put back afterwards.
	 *
	 * @param string $text Text.
	 */
	public static function mask_parts( string $text ): string {
		return (string) preg_replace_callback(
			'#<(/?)(\d{1,3})(/?)>#',
			static function ( $m ) {
				$kind = '/' === $m[1] ? 'c' : ( '/' === $m[3] ? 's' : 'o' );
				return 'TRRPART' . $kind . $m[2] . 'X';
			},
			$text
		);
	}

	/**
	 * Undo mask_parts().
	 *
	 * @param string $text Text.
	 */
	public static function unmask_parts( string $text ): string {
		return (string) preg_replace_callback(
			'#TRRPART([ocs])(\d{1,3})X#',
			static function ( $m ) {
				if ( 'c' === $m[1] ) {
					return '</' . $m[2] . '>';
				}
				return 's' === $m[1] ? '<' . $m[2] . '/>' : '<' . $m[2] . '>';
			},
			$text
		);
	}

	/**
	 * sanitize_textarea_field() without losing the placeholders.
	 *
	 * @param string $text Text straight from a form or a request.
	 */
	public static function sanitize( string $text ): string {
		return self::unmask_parts( sanitize_textarea_field( self::mask_parts( $text ) ) );
	}

	/**
	 * The sentence as it should appear in the admin screens: the words as text, the
	 * placeholders as small visible marks. Without this a person correcting a
	 * translation by hand sees "<1>" in the middle of a sentence, has no idea what
	 * it is, deletes it, and the correction can never be put back into the page.
	 *
	 * @param string $text Sentence with placeholders.
	 * @return string Safe HTML.
	 */
	public static function admin_html( string $text ): string {
		// preg_replace_callback e non preg_replace: nel titolo c'e' una frase tradotta,
		// e in una traduzione ci puo' finire un "$1" che verrebbe preso per un gruppo.
		$titolo = esc_attr__( 'This mark holds the place of a link or a formatted word. Keep it, with its number: you can move it where your language needs it.', 'translate-rocket' );
		return (string) preg_replace_callback(
			'#&lt;(/?)(\d{1,3})(/?)&gt;#',
			static function ( $m ) use ( $titolo ) {
				return '<span class="trr-ph" title="' . $titolo . '">&lt;' . $m[1] . $m[2] . $m[3] . '&gt;</span>';
			},
			esc_html( $text )
		);
	}

	/**
	 * Protect a literal "<" in the text so it can't look like a placeholder.
	 *
	 * @param string $text Text.
	 */
	private static function escape( string $text ): string {
		return str_replace( '<', '&lt;', $text );
	}

	/**
	 * Put a translation back inside the element, reusing the original tags.
	 *
	 * The tags are taken from the page, never from the translation: whatever a
	 * translator (or a model) sends back, the links and their addresses stay the
	 * ones the site published. If the placeholders don't match, nothing is
	 * touched and the caller falls back to the old piece-by-piece behaviour.
	 *
	 * @param \DOMElement $el          Element to rewrite.
	 * @param string      $translation Translated sentence with placeholders.
	 * @return bool True when the element was rewritten.
	 */
	public static function apply( \DOMElement $el, string $translation ): bool {
		$originals = array();
		$n         = 0;
		self::index( $el, $n, $originals );
		if ( ! self::placeholders_match( $translation, $originals ) ) {
			return false;
		}
		$doc  = $el->ownerDocument;
		$frag = self::build( $doc, $translation, $originals );
		if ( null === $frag ) {
			return false;
		}
		// I figli tolti si mettono da parte invece di buttarli: chi li ha in mano
		// (il motore ha gia' in mano tutti i nodi di testo della pagina) altrimenti
		// si ritrova un nodo che non esiste piu' e va in errore fatale.
		$vecchi = $doc->createDocumentFragment();
		while ( $el->firstChild ) {
			$vecchi->appendChild( $el->removeChild( $el->firstChild ) );
		}
		self::$tenuti[] = $vecchi;
		$el->appendChild( $frag );
		return true;
	}

	/**
	 * Pieces taken out of the page while a sentence is rewritten. They stay here
	 * until the request ends, so nothing else is left holding a node that no
	 * longer exists.
	 *
	 * @var \DOMDocumentFragment[]
	 */
	private static $tenuti = array();

	/**
	 * Number the inline elements the same way source() did.
	 *
	 * @param \DOMNode     $el        Element.
	 * @param int          $n         Running number (by reference).
	 * @param \DOMElement[] $originals Map number => element (by reference).
	 */
	private static function index( \DOMNode $el, int &$n, array &$originals ): void {
		foreach ( $el->childNodes as $child ) {
			if ( ! self::is_inline( $child ) ) {
				continue;
			}
			++$n;
			$originals[ $n ] = $child;
			if ( ! self::is_empty_inline( $child ) ) {
				self::index( $child, $n, $originals );
			}
		}
	}

	/**
	 * Every placeholder of the original is present exactly once, and no others.
	 *
	 * @param string       $translation Translated sentence.
	 * @param \DOMElement[] $originals   Numbered originals.
	 */
	private static function placeholders_match( string $translation, array $originals ): bool {
		preg_match_all( '#<(\d+)(/?)>|</(\d+)>#', $translation, $m, PREG_SET_ORDER );
		$open  = array();
		$close = array();
		foreach ( $m as $one ) {
			if ( '' !== $one[1] ) {
				if ( '/' === $one[2] ) {
					$open[]  = (int) $one[1];
					$close[] = (int) $one[1];
				} else {
					$open[] = (int) $one[1];
				}
				continue;
			}
			$close[] = (int) $one[3];
		}
		sort( $open );
		sort( $close );
		$want = array_keys( $originals );
		sort( $want );
		return $open === $want && $close === $want;
	}

	/**
	 * Turn the translated sentence into nodes, cloning the original tags.
	 *
	 * @param \DOMDocument|null $doc         Document.
	 * @param string            $translation Translated sentence.
	 * @param \DOMElement[]     $originals   Numbered originals.
	 */
	private static function build( ?\DOMDocument $doc, string $translation, array $originals ): ?\DOMDocumentFragment {
		if ( null === $doc ) {
			return null;
		}
		$frag  = $doc->createDocumentFragment();
		$stack = array( $frag );
		$pos   = 0;
		$len   = strlen( $translation );
		while ( $pos < $len ) {
			$next = preg_match( '#<(\d+)(/?)>|</(\d+)>#', $translation, $m, PREG_OFFSET_CAPTURE, $pos );
			$at   = $next ? (int) $m[0][1] : $len;
			if ( $at > $pos ) {
				$text = substr( $translation, $pos, $at - $pos );
				end( $stack )->appendChild( $doc->createTextNode( self::unescape( $text ) ) );
			}
			if ( ! $next ) {
				break;
			}
			$pos = $at + strlen( $m[0][0] );
			if ( '' !== $m[1][0] ) {
				$k    = (int) $m[1][0];
				$node = $originals[ $k ] ?? null;
				if ( null === $node ) {
					return null;
				}
				// <N/>: l'elemento non aveva parole, si ricopia per intero con dentro
				// quello che c'era (un'icona, un'immagine). <N>…</N>: si ricopia solo
				// il tag, perche' il testo arriva dalla traduzione.
				$self = '/' === $m[2][0];
				$copy = $node->cloneNode( $self );
				end( $stack )->appendChild( $copy );
				if ( ! $self ) {
					$stack[] = $copy;
				}
				continue;
			}
			// Closing placeholder: back to the element that holds it.
			if ( count( $stack ) < 2 ) {
				return null;
			}
			array_pop( $stack );
		}
		return 1 === count( $stack ) ? $frag : null;
	}

	/**
	 * Undo escape().
	 *
	 * @param string $text Text.
	 */
	private static function unescape( string $text ): string {
		return str_replace( '&lt;', '<', $text );
	}
}
