<?php
/**
 * Front-end engine: detects strings (admin) and replaces them per language.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Strings;
use TranslateRocket\NoTranslate;
use TranslateRocket\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * A single output-buffer pass over the page that:
 *  - records translatable strings in one batch (only while an admin browses), and
 *  - replaces them with their translation when the URL is a secondary language.
 */
class Engine {

	/**
	 * Attributes whose value is text a visitor reads.
	 *
	 * The data-* ones come from a scan of 103,887 PHP files in 138 packages (plugins and
	 * themes, `_ssh/ricerca/scan-data-attributi.py`): the attributes that receive a
	 * string the plugin itself translates, in its PUBLIC templates, are text somebody
	 * reads. data-wait-text is what Beaver Builder shows on the button while a form is
	 * sending; data-title and data-th are the column headings of WooCommerce and Tutor
	 * tables on a phone; data-bp-tooltip is BuddyPress. Any other data-* is left alone
	 * on purpose: most of them are ids, keys and field names that JavaScript matches on.
	 * The noise filter still applies, so a data-title holding a file name stays out.
	 */
	private const ATTRIBUTES   = array(
		'alt',
		'title',
		'placeholder',
		'aria-label',
		'value',
		'data-title',
		'data-th',
		'data-wait-text',
		'data-loading-text',
		'data-text',
		'data-tooltip',
		'data-bp-tooltip',
		'data-placeholder',
	);
	private const SKIP_PARENTS = array( 'script', 'style', 'code', 'pre', 'textarea', 'title' );

	/**
	 * Media whose file can have a per-language version, as tag => attributes.
	 *
	 * An <img> is rarely alone any more. A WebP plugin wraps it in a <picture> with
	 * a <source srcset> beside it, and the browser picks the <source>, so swapping
	 * only the <img src> changes nothing on screen (reproduced 23/09/2026 with
	 * `_ssh/collaudi/collaudo-immagini.sh`). Lazy loading does the same through
	 * `data-src`: the script writes it back over our `src` half a second later.
	 * A video has its own poster — usually the frame with the title written on it —
	 * and its own soundtrack, which is the whole point of having a per-language file.
	 */
	private const MEDIA_ATTRS = array(
		// `srcset` anche sull'<img>, non solo sul <source>: il logo «retina» dei temi
		// ha l'immagine normale nel `src` e quella doppia nel `srcset`, e su un
		// telefono o su un Mac il browser sceglie la seconda. Chi aveva mappato la
		// versione doppia se la vedeva ignorare (Astra, markup-extras.php:2144).
		'img'    => array( 'src', 'data-src', 'srcset', 'data-srcset' ),
		'source' => array( 'src', 'srcset', 'data-srcset' ),
		'video'  => array( 'src', 'poster' ),
		'audio'  => array( 'src' ),
	);

	/**
	 * Every media node to look at, as pairs of element and attribute.
	 *
	 * @param \DOMXPath $xpath   Document XPath.
	 * @param string    $skip_el Predicate listing the elements to stay out of.
	 * @return array<int,array{0:\DOMElement,1:string}>
	 */
	private static function media_nodes( \DOMXPath $xpath, string $skip_el ): array {
		$out = array();
		foreach ( self::MEDIA_ATTRS as $tag => $attrs ) {
			foreach ( $attrs as $attr ) {
				foreach ( $xpath->query( "//{$tag}[@{$attr}][not({$skip_el})]" ) as $el ) {
					$out[] = array( $el, $attr );
				}
			}
		}
		return $out;
	}

	/**
	 * The pieces of a composed title: "About our farmhouse - Olive Grove Stays".
	 *
	 * SEO plugins and themes glue page title and site name with a separator. The
	 * whole string is a new one that nobody has translated yet, while its pieces
	 * often are — after an import, always: the page title and the site name came
	 * over, the glued version never existed in the old plugin. Split on the
	 * separator (spaces around it, so "e-mail" or "10-12" stay whole).
	 *
	 * @return array{0:string[],1:string[]}|null pieces and the separators between them, or null
	 */
	private static function title_pieces( string $value ): ?array {
		$parts = preg_split( '/(\s+[-|–—·•»:]{1,2}\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) || count( $parts ) < 3 ) {
			return null;
		}
		$pieces = array();
		$seps   = array();
		foreach ( $parts as $i => $p ) {
			if ( 0 === $i % 2 ) {
				$pieces[] = $p;
			} else {
				$seps[] = $p;
			}
		}
		return array( $pieces, $seps );
	}

	/**
	 * A composed title put together from its translated pieces.
	 *
	 * Only used when the whole title has no translation of its own: once it has
	 * one (the AI or a person translated it), that wins. Pieces without a
	 * translation stay as they are — usually the brand name.
	 *
	 * @param array<string,string> $map Translations of this page.
	 * @return string|null the title, or null when no piece changed.
	 */
	private static function composed_title( string $value, array $map ): ?string {
		$split = self::title_pieces( $value );
		if ( null === $split ) {
			return null;
		}
		list( $pieces, $seps ) = $split;
		$out     = '';
		$changed = false;
		foreach ( $pieces as $i => $p ) {
			$k = trim( $p );
			if ( '' !== $k && isset( $map[ $k ] ) ) {
				$p       = $map[ $k ];
				$changed = true;
			}
			$out .= $p . ( $seps[ $i ] ?? '' );
		}
		return $changed ? $out : null;
	}

	/**
	 * The file addresses inside one attribute.
	 *
	 * `srcset` is not one address but a list with a descriptor each
	 * ("small.webp 480w, big.webp 1200w"), so each address is looked up on its own
	 * and its descriptor kept as it is.
	 *
	 * @return string[]
	 */
	private static function media_urls( string $value, string $attr ): array {
		if ( 'srcset' !== $attr && 'data-srcset' !== $attr ) {
			return array( trim( $value ) );
		}
		$out = array();
		foreach ( explode( ',', $value ) as $piece ) {
			$piece = trim( $piece );
			if ( '' === $piece ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $piece, 2 );
			if ( isset( $parts[0] ) && '' !== $parts[0] ) {
				$out[] = $parts[0];
			}
		}
		return $out;
	}

	/**
	 * Put the translated addresses back, keeping each descriptor.
	 *
	 * @param array<string,string> $map Original address => address for this language.
	 */
	private static function media_replace( string $value, string $attr, array $map ): string {
		if ( 'srcset' !== $attr && 'data-srcset' !== $attr ) {
			return $map[ trim( $value ) ] ?? $value;
		}
		$pezzi = array();
		foreach ( explode( ',', $value ) as $piece ) {
			$piece = trim( $piece );
			if ( '' === $piece ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $piece, 2 );
			$url   = $parts[0] ?? '';
			$resto = isset( $parts[1] ) ? ' ' . $parts[1] : '';
			$pezzi[] = ( $map[ $url ] ?? $url ) . $resto;
		}
		return implode( ', ', $pezzi );
	}

	/**
	 * Collect and translate the per-device text Divi 4 keeps in data-et-multi-view.
	 *
	 * The attribute holds {"schema":{"content":{"desktop":"…","tablet":"…","phone":"…"}}}.
	 * Each value is plain text or a small HTML fragment (a heading, a line break), so it
	 * goes through HtmlText, which knows how to read and rewrite just the text in it.
	 *
	 * @param \DOMXPath $xpath     Document XPath.
	 * @param string    $skip_el   Predicate listing the elements to stay out of.
	 * @param array     $collected Strings collected on this request (by reference).
	 * @param bool      $changed   Whether the document changed (by reference).
	 */
	private function divi_multi_view( \DOMXPath $xpath, string $skip_el, array &$collected, bool &$changed ): void {
		$nodi = $xpath->query( "//*[@data-et-multi-view][not({$skip_el})]" );
		if ( ! $nodi || 0 === $nodi->length ) {
			return;
		}
		foreach ( iterator_to_array( $nodi ) as $el ) {
			$dati = json_decode( (string) $el->getAttribute( 'data-et-multi-view' ), true );
			if ( ! is_array( $dati ) || empty( $dati['schema']['content'] ) || ! is_array( $dati['schema']['content'] ) ) {
				continue;
			}
			$toccato = false;
			foreach ( $dati['schema']['content'] as $dispositivo => $testo ) {
				if ( ! is_string( $testo ) || '' === trim( $testo ) ) {
					continue;
				}
				if ( $this->do_collect ) {
					foreach ( HtmlText::segments( $testo ) as $pezzo ) {
						if ( ! preg_match( '/\p{L}/u', $pezzo ) || self::is_noise( $pezzo ) || NoTranslate::text_excluded( $pezzo ) ) {
							continue;
						}
						$collected[] = array(
							'original' => $pezzo,
							'type'     => 'text',
							'context'  => null,
						);
					}
				}
				if ( $this->is_secondary ) {
					$tradotto = HtmlText::translate( $testo, $this->current );
					if ( $tradotto !== $testo ) {
						$dati['schema']['content'][ $dispositivo ] = $tradotto;
						$toccato = true;
					}
				}
			}
			if ( $toccato ) {
				$el->setAttribute( 'data-et-multi-view', (string) wp_json_encode( $dati ) );
				$changed = true;
			}
		}
	}

	/**
	 * Which elements carry a translatable text in that attribute.
	 *
	 * `value` is the odd one out. On a text field, a checkbox or a hidden field it
	 * holds DATA — the answer that gets submitted, a key the form logic matches on —
	 * so translating it would break the form (real Gravity Forms exports pair a
	 * visible `text` with a code-like `value` such as `website-design`). On a button
	 * it is the opposite: it is the label the visitor reads. WordPress itself prints
	 * the comment form button that way — `<input type="submit" value="Post Comment">`
	 * in comment-template.php — and so do the Divi login and search modules, which is
	 * why those buttons used to stay in the source language (found 22/09/2026 on real
	 * sites, reproduced with `_ssh/collaudi/prova-value.sh`).
	 *
	 * @param string $attr    Attribute name.
	 * @param string $skip_el XPath predicate listing the elements to stay out of.
	 */
	private static function attr_xpath( string $attr, string $skip_el ): string {
		if ( 'value' === $attr ) {
			$lower = "translate(@type,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')";
			return "//input[@value][@type][not({$skip_el})]"
				. "[contains(' submit button reset ', concat(' ', {$lower}, ' '))]";
		}
		return "//*[@{$attr}][not({$skip_el})][not(self::link)]";
	}

	/**
	 * Current request language.
	 *
	 * @var string
	 */
	private $current = '';

	/**
	 * Target languages.
	 *
	 * @var string[]
	 */
	private $secondary = array();

	/**
	 * Whether the current language is a secondary (translated) one.
	 *
	 * @var bool
	 */
	private $is_secondary = false;

	/**
	 * Whether to record strings on this request.
	 *
	 * @var bool
	 */
	private $do_collect = false;

	/**
	 * This request collects source text, on a source-language page. Read by the
	 * collector for text that JavaScript adds after the page has loaded (cookie
	 * banners, pop-ups), which the engine itself never sees.
	 *
	 * @var bool
	 */
	public static $collect_injected = false;

	/**
	 * Whether to store this request's output in the page cache on the way out.
	 *
	 * @var bool
	 */
	private $serve_cache = false;

	/**
	 * The output-buffer nesting level we opened, so we can close it explicitly on
	 * shutdown (0 = we never started a buffer on this request).
	 *
	 * @var int
	 */
	private $buffer_level = 0;

	/**
	 * Verbatim <script>/<style>/<textarea> bodies, keyed by the placeholder that
	 * stands in for them while the page goes through DOMDocument.
	 *
	 * @var array<string,string>
	 */
	private $raw_blocks = array();

	/**
	 * Placeholders (keys of $raw_blocks) that hold a JSON-LD body, in page order.
	 * Those are the only script bodies the engine reads — through Schema, on the
	 * decoded data, never on the raw text.
	 *
	 * @var string[]
	 */
	private $schema_tokens = array();

	/**
	 * Elements whose body the HTML spec treats as raw text / RCDATA: the browser
	 * reads their content as text, never as markup. libxml does not, and quietly
	 * DROPS any "</name>" sequence it finds inside them — so a perfectly valid
	 *     document.write('<span><i></i></span>')
	 * came back out of the parser as
	 *     document.write('<span><i>')
	 * breaking the script (and, in <style>, any content:'</b>' rule). Their bodies
	 * are therefore swapped for an inert placeholder before parsing and restored
	 * verbatim afterwards. <title> is deliberately absent: it is RCDATA too, but
	 * it holds real content the engine must translate, and it cannot contain
	 * markup in the first place.
	 */
	private const RAW_TEXT_ELEMENTS = array( 'script', 'style', 'textarea' );

	/**
	 * Replace raw-text element bodies with placeholders so libxml cannot mangle
	 * them. Returns the masked HTML; call unmask_raw_text() on the way out.
	 *
	 * @param string $html Page HTML.
	 */
	private function mask_raw_text( string $html ): string {
		$this->raw_blocks    = array();
		$this->schema_tokens = array();

		// One random salt per request: a placeholder must never collide with text
		// that legitimately appears on the page.
		$salt = dechex( wp_rand( 0x10000000, 0x7fffffff ) );

		// The body is matched with an unrolled, fully possessive loop rather than a
		// lazy ".*?": possessive quantifiers never backtrack, so this stays linear
		// and cannot blow pcre.backtrack_limit on the megabyte-sized inline scripts
		// that "combine JS" optimizers produce (a lazy match silently gave up there).
		$pattern = '#(<(' . implode( '|', self::RAW_TEXT_ELEMENTS ) . ')\b[^>]*>)'
			. '((?:[^<]++|<(?!/\2\s*+>))*+)'
			. '(</\2\s*>)#i';

		$masked = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $salt ) {
				// Nothing to protect in <script src="…"></script>, and an empty body
				// keeps the DOM identical either way.
				if ( '' === $m[3] ) {
					return $m[0];
				}
				$token                      = 'TRROCKETRAW' . $salt . count( $this->raw_blocks ) . 'END';
				$this->raw_blocks[ $token ] = $m[3];
				// Structured data is the one script body worth reading (see Schema).
				if ( 'script' === strtolower( $m[2] ) && preg_match( '#\btype\s*=\s*(["\']?)application/ld\+json\1#i', $m[1] ) ) {
					$this->schema_tokens[] = $token;
				}
				return $m[1] . $token . $m[4];
			},
			$html
		);

		// A catastrophic backtrack or PCRE limit returns null; keep the original
		// page rather than serving a truncated one.
		if ( ! is_string( $masked ) ) {
			$this->raw_blocks = array();
			return $html;
		}
		return $masked;
	}

	/**
	 * Put the untouched raw-text bodies back after serialization.
	 *
	 * @param string $html Serialized HTML holding the placeholders.
	 */
	private function unmask_raw_text( string $html ): string {
		if ( empty( $this->raw_blocks ) ) {
			return $html;
		}
		// strtr() replaces in a single pass and never rescans what it just wrote,
		// so a restored script body can't be re-substituted.
		$restored         = strtr( $html, $this->raw_blocks );
		$this->raw_blocks = array();
		return $restored;
	}

	/**
	 * Text that is never content, and must never be collected or translated.
	 *
	 * Two reasons to be strict here. The obvious one: a phrase in the list is a
	 * phrase the site owner pays an AI to translate. The serious one: these come
	 * back changed. A file name comes back translated and the image disappears; a
	 * `${blog.title}` placeholder comes back with a space inside and the widget
	 * that reads it breaks; an email address comes back "localized" and stops being
	 * clickable.
	 *
	 * Every pattern below was seen on a real site while comparing 22 multilingual
	 * pages against their translations (23/09/2026, `_ssh/ricerca/casi/confronto-siti.md`):
	 * `BANNER_ET.png`, `${blog.title}`, `w:normal;s:50,50,37,22;l:60,60,45,27;`.
	 */
	public static function is_noise( string $text ): bool {
		$text = trim( $text );
		if ( '' === $text ) {
			return true;
		}
		// Misure e parole chiave del CSS: "16px", "1.4em", "100%", "inherit".
		if ( preg_match( '/^[\d.,\s]+(px|em|rem|pt|ex|ch|vh|vw|vmin|vmax|fr|%)$/i', $text ) ) {
			return true;
		}
		if ( in_array( strtolower( $text ), array( 'inherit', 'initial', 'unset', 'revert' ), true ) ) {
			return true;
		}
		// Un nome di file da solo: "BANNER_ET.png", "listino-2026.pdf".
		if ( preg_match( '/^[\w .()\-]{1,80}\.(png|jpe?g|gif|webp|avif|svg|ico|pdf|mp4|webm|mp3|wav|docx?|xlsx?|pptx?|zip|rar|css|js|json|xml)$/i', $text ) ) {
			return true;
		}
		// Un segnaposto di modello, da solo: "${blog.title}", "{{ nome }}", "%1$s".
		if ( preg_match( '/^(\$\{[^}]*\}|\{\{[^}]*\}\}|\{[a-z0-9_.\-]+\}|%\d*\$?[bcdeEfFgGosuxX])$/i', $text ) ) {
			return true;
		}
		// Un elenco di coppie chiave:valore come le scrivono i temi nei data-*:
		// "w:normal;s:50,50,37,22;l:60,60,45,27;fw:500;".
		if ( preg_match( '/^([a-z-]{1,20}\s*:\s*[^;:]{1,40};\s*){2,}$/i', $text ) ) {
			return true;
		}
		// Un indirizzo e-mail da solo: e' un contatto, non una frase.
		if ( preg_match( '/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i', $text ) ) {
			return true;
		}
		// Un colore, un identificativo, una sigla senza spazi: "#1a2b3c",
		// "550e8400-e29b-41d4-a716-446655440000", "SKU-A100", "IMG_2043".
		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $text )
			|| preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $text ) ) {
			return true;
		}
		// Parole unite dal trattino basso e senza spazi: e' un nome di file o una chiave,
		// mai una frase («Louvre_Window_Terminology», un alt lasciato col nome del file).
		if ( ! preg_match( '/\s/', $text ) && false !== strpos( $text, '_' ) && preg_match( '/^[\w.\-]+$/u', $text ) ) {
			return true;
		}
		if ( ! preg_match( '/\s/', $text ) && preg_match( '/\d/', $text ) && preg_match( '/^[\w.\-]+$/', $text ) ) {
			return true; // una sola parola con dentro delle cifre: e' un codice
		}
		return false;
	}

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'maybe_start' ), 1 );
	}

	/**
	 * Decide whether to buffer this request and start it.
	 */
	public function maybe_start(): void {
		// A page builder editing this page (Elementor, Beaver Builder, Divi...): the
		// page is its canvas, full of its own tools. Leave it exactly as it is.
		if ( \TranslateRocket\BuilderMode::active() ) {
			return;
		}
		$router             = Plugin::instance()->router();
		$this->current      = $router->current_language();
		$this->secondary    = $router->secondary_languages();
		$this->is_secondary = ! $router->is_default( $this->current );
		// Collect only where the text on the page really is the source language.
		// With the interface option on, a secondary-language page has WordPress'
		// own menus, buttons and dialogs already rendered from that language's
		// pack — collecting there files Arabic or Dutch as if it were English to
		// be translated. Source pages still discover everything.
		$this->do_collect   = current_user_can( 'manage_options' ) && ! empty( $this->secondary );
		if ( $this->do_collect && $this->is_secondary && Locale::$active ) {
			$this->do_collect = false;
		}
		// A header, footer or popup template opened on its own: its text is collected
		// on the pages that use it, not filed as a page of its own.
		if ( $this->do_collect && \TranslateRocket\BuilderMode::template_view() ) {
			$this->do_collect = false;
		}
		// Side by side, the other plugin serves its own translated pages on its
		// own addresses (/en/about/): their text is not the source language. Collect
		// only where WordPress still runs in the source language.
		if ( $this->do_collect && \TranslateRocket\Coexistence::on() && '' === \TranslateRocket\Coexistence::preview_language()
			&& function_exists( 'determine_locale' )
			&& determine_locale() !== \TranslateRocket\Languages::locale( $router->default_language() ) ) {
			$this->do_collect = false;
		}

		// An independent copy is being served for this page: it's already a real,
		// target-language post, so the engine must not translate or collect it.
		if ( CopyServer::is_serving_copy() ) {
			return;
		}

		if ( ! $this->is_secondary && ! $this->do_collect ) {
			return;
		}
		// Leave excluded paths entirely untouched (no translating, no collecting).
		if ( NoTranslate::path_excluded( $router->current_clean_path() ) ) {
			return;
		}
		// Only on source pages: on a translated page the observer would see our own
		// translations being written in, and file them as source text.
		self::$collect_injected = $this->do_collect && ! $this->is_secondary;

		// Page cache: serve a stored translated page to anonymous visitors, or mark
		// this request to be stored once it has been built.
		if ( $this->is_secondary && ! $this->do_collect && Cache::enabled() && $this->cacheable_request() ) {
			$cached = Cache::get( $this->request_uri(), $this->current );
			if ( false !== $cached ) {
				// The cached value is a complete, already-rendered HTML document (the whole
				// page response), not an output fragment — there is nothing to escape and
				// escaping it would corrupt the page.
				echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput -- complete pre-rendered page document served from cache.
				exit;
			}
			$this->serve_cache = true;
		}

		// The engine must transform the whole page, so it buffers the entire render
		// with a callback and closes it explicitly on shutdown (see close_buffer()).
		ob_start( array( $this, 'process' ) );
		$this->buffer_level = ob_get_level();
		add_action( 'shutdown', array( $this, 'close_buffer' ), 0 );
	}

	/**
	 * Close the page buffer we opened, deterministically, at shutdown.
	 *
	 * A whole-page translation pass can only work by buffering the complete render
	 * (ob_start with the process() callback), which by design spans many function
	 * calls and cannot be paired with ob_get_clean() in the same scope. To avoid
	 * relying on PHP's implicit teardown — and any buffer-stack misalignment with
	 * other components — we flush down to (and including) our own level here.
	 */
	public function close_buffer(): void {
		if ( $this->buffer_level < 1 ) {
			return;
		}
		$target             = $this->buffer_level;
		$this->buffer_level = 0;
		while ( ob_get_level() >= $target ) {
			if ( false === ob_end_flush() ) {
				break;
			}
		}
	}

	/**
	 * Whether this request is safe to cache: a plain anonymous GET of a real page
	 * (no query string, not a 404 / search / feed).
	 */
	private function cacheable_request(): bool {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}
		// Una pagina con parametri veri (una ricerca, un filtro, una paginazione a
		// query) non si mette in cache. I parametri di sola provenienza invece non
		// cambiano nulla di cio' che si legge, quindi non devono impedirla.
		if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only cache gate.
			$veri = array_diff_key( $_GET, array_flip( self::tracking_params() ) ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only cache gate.
			if ( ! empty( $veri ) ) {
				return false;
			}
		}
		if ( function_exists( 'is_404' ) && is_404() ) {
			return false;
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			return false;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}
		// Respect the standard "don't cache this page" flag (WooCommerce sets it on
		// cart/checkout/account and whenever the cart has items; so do cache plugins).
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		// WooCommerce dynamic pages, even before that flag is set.
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Current request URI (path + query), sanitized.
	 */
	private function request_uri(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		// I parametri di tracciamento cambiano l'indirizzo ma non la pagina: se
		// entrassero nella chiave, ogni visita da una campagna, da un social o da un
		// QR sarebbe una voce di cache diversa — cioe' il traffico che costa di piu'
		// non userebbe mai la cache.
		$pezzi = explode( '?', $uri, 2 );
		if ( isset( $pezzi[1] ) ) {
			parse_str( $pezzi[1], $par );
			$resta = array_diff_key( $par, array_flip( self::tracking_params() ) );
			$uri   = $pezzi[0] . ( empty( $resta ) ? '' : '?' . http_build_query( $resta ) );
		}
		return $uri;
	}

	/**
	 * Query parameters that only say where the visitor came from.
	 *
	 * They are dropped from the cache key and ignored when deciding whether a
	 * request can be cached at all. The list can be extended by a site that uses
	 * its own tracking parameter.
	 *
	 * @return string[]
	 */
	private static function tracking_params(): array {
		$lista = array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'utm_id',
			'gclid',
			'gbraid',
			'wbraid',
			'fbclid',
			'msclkid',
			'ttclid',
			'twclid',
			'igshid',
			'mc_cid',
			'mc_eid',
			'_gl',
			'yclid',
		);
		/**
		 * Filters the query parameters treated as tracking-only.
		 *
		 * @param string[] $lista Parameter names.
		 */
		return (array) apply_filters( 'trrocket_tracking_params', $lista );
	}

	/**
	 * Localize one content href to the current secondary language.
	 *
	 * Returns the href unchanged unless it is an internal page URL (root-relative
	 * or absolute on this site's host) that routes and carries no language prefix.
	 *
	 * @param string $href      Raw href attribute value.
	 * @param string $home_host Host of home_url().
	 * @param string $home_path Path prefix of home_url() ('' unless WP lives in a sub-directory).
	 * @return string Localized (or original) href.
	 */
	private function localize_href( string $href, string $home_host, string $home_path ): string {
		if ( '' === $href || '#' === $href[0] || '?' === $href[0] ) {
			return $href;
		}
		if ( '/' === $href[0] ) {
			// Protocol-relative ("//host/…") is treated as external.
			if ( isset( $href[1] ) && '/' === $href[1] ) {
				return $href;
			}
		} elseif ( preg_match( '#^https?://#i', $href ) ) {
			$host = (string) wp_parse_url( $href, PHP_URL_HOST );
			if ( 0 !== strcasecmp( $host, $home_host ) ) {
				return $href;
			}
		} else {
			// mailto:, tel:, javascript:, data:, and rare document-relative paths.
			return $href;
		}

		$parts = wp_parse_url( $href );
		if ( false === $parts ) {
			return $href;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';

		// In a sub-directory install only the part after the home path is routable.
		$rest = $path;
		if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$rest = (string) substr( $path, strlen( $home_path ) );
		}
		if ( '' === $rest ) {
			$rest = '/';
		}
		if ( '/' !== $rest[0] ) {
			return $href;
		}
		// System paths and direct file links (PDFs, images…) are never language-routed.
		if ( 0 === strpos( $rest, '/wp-' ) || preg_match( '/\.[a-z0-9]{2,5}$/i', $rest ) ) {
			return $href;
		}
		// Already carrying a language prefix — ours or another configured language's
		// (e.g. switcher links) — including via a nulled-prefix exact match.
		foreach ( array_merge( array( $this->current ), $this->secondary ) as $code ) {
			if ( $rest === '/' . $code || 0 === strpos( $rest, '/' . $code . '/' ) ) {
				return $href;
			}
		}

		$new_path = $home_path . '/' . $this->current . $rest;
		$suffix   = ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
		if ( isset( $parts['host'] ) ) {
			$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//';
			$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
			return $scheme . $parts['host'] . $port . $new_path . $suffix;
		}
		return $new_path . $suffix;
	}

	/**
	 * Output-buffer callback.
	 *
	 * @param string $html Buffered page HTML.
	 */
	public function process( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<html' ) ) {
			return $html;
		}

		// Shield <script>/<style>/<textarea> bodies from the parser before it can
		// eat closing tags inside them (see mask_raw_text()). $html itself stays
		// untouched so the early-return paths below still serve the original page.
		$masked = $this->mask_raw_text( $html );

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $masked, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new \DOMXPath( $dom );

		$skip = '';
		foreach ( self::SKIP_PARENTS as $tag ) {
			// `title` sta in questo elenco per il titolo del documento, che si
			// traduce per conto suo piu' sotto. Ma <title> e' anche il NOME di
			// un'icona SVG, quello che legge un lettore di schermo: escludendolo
			// per tag restavano inglesi tutte le icone dei temi, e non comparivano
			// nemmeno nell'elenco delle frasi (23/09/2026). Si salta quindi solo
			// il <title> che sta nella testata del documento.
			$skip .= ( 'title' === $tag ) ? "ancestor::title[parent::head] or " : "ancestor::{$tag} or ";
		}
		$skip   .= "ancestor::*[@id='wpadminbar'] or ancestor::*[@id='trrocket-ve-bar'] or ancestor::*[@translate='no']";
		$skip_el = "ancestor-or-self::*[@id='wpadminbar'] or ancestor-or-self::*[@id='trrocket-ve-bar'] or ancestor-or-self::*[@translate='no']";
		// Tooling that only administrators see (debug panels, page-builder helpers): not
		// page content, so never collected — and never translated either.
		foreach ( NoTranslate::tool_prefixes() as $prefix ) {
			$p        = str_replace( "'", '', (string) $prefix );
			$skip    .= " or ancestor::*[starts-with(@id,'{$p}')] or ancestor::*[contains(concat(' ',normalize-space(@class),' '),' {$p}')] or ancestor::*[starts-with(@class,'{$p}')]";
			$skip_el .= " or ancestor-or-self::*[starts-with(@id,'{$p}')] or ancestor-or-self::*[contains(concat(' ',normalize-space(@class),' '),' {$p}')] or ancestor-or-self::*[starts-with(@class,'{$p}')]";
		}

		// User-configured CSS selectors to leave untranslated.
		$extra = NoTranslate::xpath_skip();
		if ( '' !== $extra ) {
			$skip    .= ' or ' . $extra;
			$skip_el .= ' or ' . $extra;
		}

		// Il predicato per gli ELEMENTI interi (le frasi unite): serve sia la lista dei
		// tag da non toccare (code, pre, script...) sia le condizioni di $skip_el, e tutte
		// con «ancestor-or-self», perche' qui si sceglie un elemento e un elemento non e'
		// antenato di se stesso. $skip ha i tag ma solo come antenati; $skip_el ha le
		// condizioni giuste ma non i tag. Da qui il terzo.
		$skip_unit = '';
		foreach ( self::SKIP_PARENTS as $tag ) {
			$skip_unit .= ( 'title' === $tag ) ? "ancestor-or-self::title[parent::head] or " : "ancestor-or-self::{$tag} or ";
		}
		$skip_unit .= $skip_el;

		$collected = array();
		$changed   = false;

		// Materialize every node the engine will touch, then resolve translations
		// for exactly the texts present on THIS page in one batch. On small sites
		// that filters the cached full map (same cost as before); on huge sites
		// (100k+ strings) it becomes an indexed batch lookup, so memory stays
		// proportional to the page instead of the whole site.
		$text_nodes = iterator_to_array( $xpath->query( "//text()[not({$skip})]" ) );

		// Frasi spezzate da un link o da una parola in grassetto: si leggono intere,
		// con i tag al posto loro (vedi InlineText). I nodi di testo che stanno
		// dentro una di queste frasi non si raccolgono piu' uno per uno: sarebbero
		// pezzi senza senso («Read the», «or»). Filtro disattivabile per chi ha un
		// tema che ne soffre.
		$units      = array();
		$unit_texts = array();
		// Gli oggetti dei nodi vanno tenuti vivi finche' si usa spl_object_id: quando
		// PHP libera un DOMNode, lo stesso numero puo' finire a un altro nodo.
		$unit_refs  = array();
		if ( apply_filters( 'trrocket_merge_inline', true ) ) {
			// ⚠️ $skip_el, non $skip: il primo dice «l'elemento stesso o un suo antenato»,
			// il secondo solo «un antenato». Qui si sceglie un ELEMENTO, e un elemento non
			// e' antenato di se stesso: con $skip un <p translate="no"> o un <code> che
			// contiene del testo veniva preso lo stesso. Trovato in revisione il 20/9.
			foreach ( InlineText::units( $xpath, $skip_unit ) as $unit_el ) {
				$src = InlineText::source( $unit_el );
				if ( '' === $src || self::is_noise( $src ) || NoTranslate::text_excluded( $src ) ) {
					continue;
				}
				$units[] = array( 'el' => $unit_el, 'src' => $src );
				foreach ( $xpath->query( './/text()', $unit_el ) as $inner ) {
					$unit_refs[]                          = $inner;
					$unit_texts[ spl_object_id( $inner ) ] = true;
				}
			}
		}
		$attr_nodes = array();
		foreach ( self::ATTRIBUTES as $attr ) {
			$attr_nodes[ $attr ] = iterator_to_array( $xpath->query( self::attr_xpath( $attr, $skip_el ) ) );
		}
		// Le immagini: il loro `src` si tratta come tutto il resto, cioe' come una
		// cosa che puo' avere una versione per lingua. Serve a chi ha una foto con
		// del testo dentro, una locandina, un banner: tradurre la didascalia non
		// basta se l'immagine stessa parla un'altra lingua.
		$img_nodes = self::media_nodes( $xpath, $skip_el );

		$desc_nodes   = iterator_to_array( $xpath->query( '//meta[@name="description"]/@content' ) );
		// Social e parole chiave. ⚠️ Prima questi venivano SOLO tradotti se il
		// testo capitava di essere gia' in mappa, e non venivano mai raccolti: su
		// un sito con Yoast o Rank Math, che mettono un titolo social diverso da
		// quello della pagina, l'anteprima su Facebook e LinkedIn restava nella
		// lingua di partenza per sempre. Adesso si raccolgono come tutto il resto.
		$social_nodes = iterator_to_array( $xpath->query( '//meta[@property="og:title" or @property="og:description" or @property="og:site_name" or @property="og:image:alt" or @name="twitter:title" or @name="twitter:description" or @name="twitter:image:alt" or @name="keywords"]/@content' ) );
		$title_nodes  = iterator_to_array( $xpath->query( '//head/title' ) );

		// Structured data: the prose inside JSON-LD (FAQ questions and answers, article
		// headlines, descriptions). Read from the decoded data, per placeholder.
		$schema_texts = array();
		foreach ( $this->schema_tokens as $token ) {
			if ( isset( $this->raw_blocks[ $token ] ) ) {
				$schema_texts[ $token ] = Schema::strings( $this->raw_blocks[ $token ] );
			}
		}

		$map = array();
		if ( $this->is_secondary ) {
			$candidates = array();
			foreach ( $schema_texts as $texts ) {
				foreach ( $texts as $text ) {
					$candidates[] = $text;
				}
			}
			foreach ( $units as $unit ) {
				$candidates[] = $unit['src'];
			}
			foreach ( $text_nodes as $node ) {
				$candidates[] = trim( (string) $node->nodeValue );
			}
			foreach ( $attr_nodes as $attr => $els ) {
				foreach ( $els as $el ) {
					$candidates[] = trim( (string) $el->getAttribute( $attr ) );
				}
			}
			foreach ( $img_nodes as $media ) {
				list( $el, $attr ) = $media;
				foreach ( self::media_urls( (string) $el->getAttribute( $attr ), $attr ) as $url ) {
					$candidates[] = $url;
				}
			}
			foreach ( $desc_nodes as $meta ) {
				$candidates[] = trim( (string) $meta->nodeValue );
			}
			foreach ( $social_nodes as $social ) {
				$candidates[] = trim( (string) $social->nodeValue );
				$split        = self::title_pieces( trim( (string) $social->nodeValue ) );
				foreach ( null === $split ? array() : $split[0] as $piece ) {
					$candidates[] = trim( $piece );
				}
			}
			foreach ( $title_nodes as $title_el ) {
				$candidates[] = trim( (string) $title_el->textContent );
				$split        = self::title_pieces( trim( (string) $title_el->textContent ) );
				foreach ( null === $split ? array() : $split[0] as $piece ) {
					$candidates[] = trim( $piece );
				}
			}
			$map = Strings::translate_texts( $candidates, $this->current );
		}

		// On a translated page, set the document language for SEO/accessibility, and
		// the text direction so right-to-left languages (Arabic, Hebrew, Persian…)
		// render correctly instead of left-to-right.
		if ( $this->is_secondary && null !== $dom->documentElement ) {
			$dom->documentElement->setAttribute( 'lang', $this->current );
			if ( \TranslateRocket\Languages::is_rtl( $this->current ) ) {
				$dom->documentElement->setAttribute( 'dir', 'rtl' );
			}
			$changed = true;
		}

		// Text nodes. In visual-edit mode we also wrap each one with its source so
		// the editor can map a clicked element back to its string — collect into an
		// array first so replacing nodes mid-loop is safe.
		// Only enable visual-edit wrapping on a SECONDARY language — you can't
		// translate the source language into itself.
		$editing = VisualEditor::is_editing() && $this->is_secondary;

		// Le frasi intere: si raccolgono cosi' come sono e, se c'e' la traduzione, si
		// riscrive il paragrafo riusando i tag della pagina (mai quelli che arrivano
		// dalla traduzione). Se la traduzione non c'e' ancora, o i segnaposto non
		// tornano, non si tocca niente: i pezzi vecchi restano e il visitatore vede
		// quello che vedeva prima.
		$unit_done = array();
		foreach ( $units as $unit ) {
			$src = $unit['src'];
			if ( $this->do_collect ) {
				$collected[] = array(
					'original' => $src,
					'type'     => 'text',
					'context'  => null,
				);
			}
			$inner = iterator_to_array( $xpath->query( './/text()', $unit['el'] ) );
			foreach ( $inner as $node ) {
				$unit_refs[] = $node;
			}
			if ( isset( $map[ $src ] ) && InlineText::apply( $unit['el'], $map[ $src ] ) ) {
				foreach ( $inner as $node ) {
					$unit_done[ spl_object_id( $node ) ] = true;
				}
				$changed = true;
			}
			if ( $editing ) {
				// Frase intera senza traduzione, ma coi pezzi tradotti da una versione
				// vecchia: il visitatore la vede tradotta (pezzo per pezzo), l'editor la
				// mostrava in inglese con la casella vuota. Si mostra come la vede il
				// visitatore e la casella si riempie con la frase ricomposta (24/9/2026).
				$composto = isset( $map[ $src ] ) ? null : InlineText::compose( $src, $map );
				if ( null !== $composto && InlineText::apply( $unit['el'], $composto ) ) {
					$changed = true;
				}
				// Nell'editor visivo la frase si clicca tutta insieme: il pezzo
				// dentro il link non ha vita propria.
				$cls = (string) $unit['el']->getAttribute( 'class' );
				$add = ( isset( $map[ $src ] ) || null !== $composto ) ? 'trrocket-ed' : 'trrocket-ed trrocket-ed-untr';
				$unit['el']->setAttribute( 'class', '' === $cls ? $add : $cls . ' ' . $add );
				$unit['el']->setAttribute( 'data-trr-src', rawurlencode( $src ) );
				// La frase intera ha dei segnaposto: nel riquadro dell'editor non si puo'
				// mettere il testo come si vede sulla pagina, perche' li' i segnaposto non
				// ci sono e chi corregge a mano si vedrebbe rifiutare il salvataggio senza
				// capire perche'. Si passa la traduzione COSI' COM'E' (o la frase di
				// partenza, se non e' ancora tradotta). Trovato in revisione il 20/9.
				$unit['el']->setAttribute( 'data-trr-parts', '1' );
				$unit['el']->setAttribute( 'data-trr-cur', rawurlencode( isset( $map[ $src ] ) ? $map[ $src ] : (string) $composto ) );
				foreach ( iterator_to_array( $xpath->query( './/text()', $unit['el'] ) ) as $node ) {
					$unit_refs[]                        = $node;
					$unit_done[ spl_object_id( $node ) ] = true;
				}
				$changed = true;
			}
		}
		foreach ( $text_nodes as $node ) {
			// Pezzo di una frase gia' riscritta per intero: quel nodo non e' piu'
			// nella pagina, e anche solo leggerlo farebbe saltare tutto. Il
			// controllo va PRIMA di toccare il nodo.
			if ( isset( $unit_done[ spl_object_id( $node ) ] ) ) {
				continue;
			}
			$text = trim( (string) $node->nodeValue );
			if ( '' === $text || ! preg_match( '/\p{L}/u', $text ) || self::is_noise( $text ) || NoTranslate::text_excluded( $text ) ) {
				continue;
			}
			// Pezzo di una frase che leggiamo intera: la frase e' gia' stata raccolta,
			// il pezzo no. Resta pero' la traduzione vecchia, se c'e', cosi' i siti che
			// hanno gia' tradotto a pezzi non peggiorano da un aggiornamento all'altro.
			$is_piece = isset( $unit_texts[ spl_object_id( $node ) ] );
			if ( $this->do_collect && ! $is_piece ) {
				$collected[] = array(
					'original' => $text,
					'type'     => 'text',
					'context'  => null,
				);
			}
			if ( isset( $map[ $text ] ) ) {
				$node->nodeValue = str_replace( $text, $map[ $text ], (string) $node->nodeValue );
				$changed         = true;
			}
			if ( $editing && null !== $node->parentNode ) {
				$span = $dom->createElement( 'span' );
				// Flag nodes still showing the source (no translation yet) so the
				// editor can mark them as "to translate".
				$span->setAttribute( 'class', isset( $map[ $text ] ) ? 'trrocket-ed' : 'trrocket-ed trrocket-ed-untr' );
				$span->setAttribute( 'data-trr-src', rawurlencode( $text ) );
				$span->appendChild( $dom->createTextNode( (string) $node->nodeValue ) );
				$node->parentNode->replaceChild( $span, $node );
				$changed = true;
			}
		}

		// ⚠️ Le frasi riscritte hanno sostituito i loro figli con delle COPIE: i nodi
		// degli attributi e delle immagini raccolti prima sono ormai fuori dalla pagina,
		// e scriverci sopra non cambierebbe niente (il titolo di un link e il testo
		// alternativo di un'immagine dentro una frase col grassetto restavano nella
		// lingua di partenza). Si riprendono dalla pagina com'e' adesso.
		if ( ! empty( $unit_done ) ) {
			foreach ( self::ATTRIBUTES as $attr ) {
				$attr_nodes[ $attr ] = iterator_to_array( $xpath->query( self::attr_xpath( $attr, $skip_el ) ) );
			}
			$img_nodes = self::media_nodes( $xpath, $skip_el );
		}

		// Translatable attributes. <link> is skipped: its title attributes are the
		// machine-facing feed/oEmbed/RSD labels in <head> ("Site » Feed", "JSON"…) —
		// never user-visible, they would only pollute every page's string list.
		foreach ( self::ATTRIBUTES as $attr ) {
			foreach ( $attr_nodes[ $attr ] as $el ) {
				$value = trim( (string) $el->getAttribute( $attr ) );
				// Il filtro del rumore vale anche qui: negli attributi finiscono nomi di
				// file, segnaposto di modello e stringhe di stile piu' che nel testo.
				if ( '' === $value || ! preg_match( '/\p{L}/u', $value ) || self::is_noise( $value ) || NoTranslate::text_excluded( $value ) ) {
					continue;
				}
				if ( $this->do_collect ) {
					$collected[] = array(
						'original' => $value,
						'type'     => 'attribute',
						'context'  => $attr,
					);
				}
				if ( isset( $map[ $value ] ) ) {
					$el->setAttribute( $attr, $map[ $value ] );
					$changed = true;
				}
				// In edit mode, tag the element so the visual editor can edit this
				// attribute (alt/title/…) — keeps its source alongside the live value.
				if ( $editing ) {
					$key = (string) preg_replace( '/[^a-z]/', '', $attr );
					$el->setAttribute( 'data-trr-a-' . $key, rawurlencode( $value ) );
					$list = (string) $el->getAttribute( 'data-trr-attrs' );
					$el->setAttribute( 'data-trr-attrs', '' === $list ? $attr : $list . ',' . $attr );
					$cls = (string) $el->getAttribute( 'class' );
					if ( false === strpos( ' ' . $cls . ' ', ' trrocket-ed-attr ' ) ) {
						$el->setAttribute( 'class', '' === $cls ? 'trrocket-ed-attr' : $cls . ' trrocket-ed-attr' );
					}
					$changed = true;
				}
			}
		}

		// Immagini. Il "testo" qui e' l'indirizzo del file, e si comporta come una
		// stringa qualunque: si raccoglie, si cerca una versione per questa lingua,
		// e se c'e' si sostituisce.
		foreach ( $img_nodes as $media ) {
			list( $img, $attr ) = $media;
			$valore = trim( (string) $img->getAttribute( $attr ) );
			// Un'immagine incorporata nella pagina (data:) non ha un indirizzo da
			// sostituire, e ficcarla nell'elenco delle stringhe lo riempirebbe di
			// migliaia di caratteri illeggibili.
			if ( '' === $valore || 0 === stripos( $valore, 'data:' ) ) {
				continue;
			}
			$urls = self::media_urls( $valore, $attr );

			if ( $this->do_collect ) {
				foreach ( $urls as $url ) {
					if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
						continue;
					}
					$collected[] = array(
						// Il tipo e' 'image' e non 'attribute' apposta: cosi' la
						// traduzione automatica sa che qui non c'e' niente da tradurre.
						// Mandare un indirizzo a DeepL o all'AI non e' solo inutile:
						// tornerebbe indietro storpiato, e l'immagine sparirebbe.
						'original' => $url,
						'type'     => 'image',
						'context'  => $attr,
					);
				}
			}

			$nuovo = self::media_replace( $valore, $attr, $map );
			if ( $nuovo !== $valore ) {
				$img->setAttribute( $attr, $nuovo );
				if ( 'src' === $attr && 'img' === strtolower( $img->nodeName ) ) {
					// Il `srcset` batte il `src`: se resta quello dell'immagine di
					// partenza, il browser sceglie da li' e la sostituzione sembra
					// non funzionare. Ma buttarlo via e' un rimedio peggiore quando
					// il proprietario ha mappato anche le versioni piu' grandi: si
					// perderebbe l'alta definizione. Quindi lo si toglie SOLO se
					// resta indietro, cioe' se non tutte le sue voci hanno una
					// versione in questa lingua (il caso del logo «retina»).
					$set = trim( (string) $img->getAttribute( 'srcset' ) );
					if ( '' !== $set && self::media_replace( $set, 'srcset', $map ) === $set ) {
						$img->removeAttribute( 'srcset' );
						$img->removeAttribute( 'sizes' );
					}
				}
				$changed = true;
			}

			if ( $editing && 'src' === $attr && 'img' === strtolower( $img->nodeName ) ) {
				$img->setAttribute( 'data-trr-img', rawurlencode( $valore ) );
				$cls = (string) $img->getAttribute( 'class' );
				if ( false === strpos( ' ' . $cls . ' ', ' trrocket-ed-img ' ) ) {
					$img->setAttribute( 'class', '' === $cls ? 'trrocket-ed-img' : $cls . ' trrocket-ed-img' );
				}
				$changed = true;
			}
		}

		// Divi 4: the text a module shows on tablets and phones is not in the page but
		// in a JSON attribute, and Divi's own script writes it over the element when the
		// screen is small. Left alone, a translated page turns back into the source
		// language on every phone (reproduced 23/09/2026 with HTML from real Divi sites,
		// `_ssh/collaudi/collaudo-divi.sh`). Only the schema's "content" strings are
		// text; everything else in that JSON is layout and stays as it is.
		$this->divi_multi_view( $xpath, $skip_el, $collected, $changed );

		// SEO meta description. Use setAttribute() (not nodeValue) so values with an
		// ampersand — "Bed & Breakfast", "B&B" — aren't mangled into an empty string
		// by libxml's entity parsing.
		foreach ( $desc_nodes as $meta ) {
			$value = trim( (string) $meta->nodeValue );
			if ( '' === $value ) {
				continue;
			}
			if ( $this->do_collect ) {
				$collected[] = array(
					'original' => $value,
					'type'     => 'meta',
					'context'  => 'description',
				);
			}
			if ( isset( $map[ $value ] ) && $meta->ownerElement instanceof \DOMElement ) {
				$meta->ownerElement->setAttribute( $meta->nodeName, $map[ $value ] );
				$changed = true;
			}
		}

		// Social meta (Open Graph / Twitter) and keywords. Often these repeat the
		// page title and description, and then they are already in the map; but an
		// SEO plugin can set a social title of its own, and that one has to be
		// collected like any other string or it never gets translated at all.
		foreach ( $social_nodes as $social ) {
			$value = trim( (string) $social->nodeValue );
			if ( '' === $value || ! ( $social->ownerElement instanceof \DOMElement ) ) {
				continue;
			}
			if ( $this->do_collect ) {
				$el   = $social->ownerElement;
				$quale = $el->getAttribute( 'property' );
				if ( '' === $quale ) {
					$quale = $el->getAttribute( 'name' );
				}
				$collected[] = array(
					'original' => $value,
					'type'     => 'meta',
					// Il contesto e' il nome del tag ("og:title", "keywords"...):
					// serve a mostrarli uno per uno nel pannello SEO.
					'context'  => (string) $quale,
				);
			}
			$tradotto = $map[ $value ] ?? ( $this->is_secondary ? self::composed_title( $value, $map ) : null );
			if ( null !== $tradotto ) {
				$social->ownerElement->setAttribute( $social->nodeName, $tradotto );
				$changed = true;
			}
		}

		// SEO page title (<title>), kept as an indexing string. Replace the child text
		// node (not nodeValue) so ampersands survive — see the description note above.
		foreach ( $title_nodes as $title_el ) {
			$value = trim( (string) $title_el->textContent );
			if ( '' === $value ) {
				continue;
			}
			if ( $this->do_collect ) {
				$collected[] = array(
					'original' => $value,
					'type'     => 'meta',
					'context'  => 'title',
				);
			}
			$tradotto = $map[ $value ] ?? ( $this->is_secondary ? self::composed_title( $value, $map ) : null );
			if ( null !== $tradotto ) {
				while ( $title_el->firstChild ) {
					$title_el->removeChild( $title_el->firstChild );
				}
				$title_el->appendChild( $dom->createTextNode( $tradotto ) );
				$changed = true;
			}
		}

		// Structured data (JSON-LD). Collected as meta strings with the "schema" context,
		// so they are listed and machine-translated like the title and description; on a
		// translated page the decoded data is rewritten and "inLanguage" corrected. The
		// body is only replaced when something actually changed.
		foreach ( $schema_texts as $token => $texts ) {
			if ( $this->do_collect ) {
				foreach ( $texts as $text ) {
					if ( NoTranslate::text_excluded( $text ) ) {
						continue;
					}
					$collected[] = array(
						'original' => $text,
						'type'     => 'meta',
						'context'  => 'schema',
					);
				}
			}
			if ( $this->is_secondary && isset( $this->raw_blocks[ $token ] ) ) {
				$translated = Schema::translate( $this->raw_blocks[ $token ], $map, $this->current );
				if ( null !== $translated ) {
					$this->raw_blocks[ $token ] = $translated;
					$changed                    = true;
				}
			}
		}

		// Internal links in the page body. Menu items and WP-generated permalinks
		// arrive already localized through the home_url() filter, but links
		// hardcoded in post content — root-relative ("/about/") or absolute with
		// the site's own host, both common on migrated sites — bypass every URL
		// filter, so on a translated page they would drop the visitor back into
		// the source language on the first click. Localize them in the same DOM
		// pass, skipping /wp-* system paths, direct file links (PDF, images…)
		// and URLs that already carry a language prefix (e.g. the switcher's).
		if ( $this->is_secondary && apply_filters( 'trrocket_localize_content_links', true ) ) {
			// The RAW home URL (option, not home_url(): the router filters home_url()
			// on translated pages, which would smuggle the language prefix into the
			// base path here and double-prefix every link).
			$raw_home   = (string) get_option( 'home' );
			$home_host  = (string) wp_parse_url( $raw_home, PHP_URL_HOST );
			$home_path  = rtrim( (string) wp_parse_url( $raw_home, PHP_URL_PATH ), '/' );
			// The plugin's own UI (switcher, floating bar, badge) must NEVER be
			// rewritten: its default-language link is unprefixed BY DESIGN — the
			// 0.7.6 pass rewrote it too, trapping visitors in the translated
			// language. Same for explicit translate="no" regions (hand-made
			// language menus can opt out the same way content does).
			$link_nodes = iterator_to_array( $xpath->query( "//a[@href][not(ancestor-or-self::*[@id='wpadminbar'] or ancestor-or-self::*[@id='trrocket-ve-bar'] or ancestor-or-self::*[contains(@class,'trrocket')] or ancestor-or-self::*[@translate='no'])]" ) );
			foreach ( $link_nodes as $a ) {
				if ( ! $a instanceof \DOMElement ) {
					continue;
				}
				$href = (string) $a->getAttribute( 'href' );
				$new  = $this->localize_href( $href, $home_host, $home_path );
				if ( $new !== $href ) {
					$a->setAttribute( 'href', $new );
					$changed = true;
				}
			}
		}

		// Persist everything found on this page in one batch — but never record
		// 404 pages (browsing a stale/non-existent translated URL would otherwise
		// create a phantom "Page not found" entry in the list).
		if ( $this->do_collect && ! empty( $collected ) && ! ( function_exists( 'is_404' ) && is_404() ) ) {
			$router = Plugin::instance()->router();
			$qid    = ( function_exists( 'is_singular' ) && is_singular() ) ? (int) get_queried_object_id() : 0;
			$url    = $qid > 0 ? $router->canonical_path( $qid ) : $router->current_clean_path();
			$title  = function_exists( 'wp_get_document_title' ) ? wp_strip_all_tags( wp_get_document_title() ) : '';
			Strings::remember_batch( $collected, $this->secondary, $url, $title );
			// This page has just been read: it is no longer waiting for a scan.
			if ( $qid > 0 ) {
				\TranslateRocket\Rescan::forget( $qid );
			}
		}

		if ( ! $changed ) {
			return $html;
		}

		// Serialize from the root NODE, not the document: whole-document saveHTML()
		// entity-encodes every non-ASCII character (è → &egrave;, ▾ → &#9662;,
		// Cyrillic → NCRs). In text that's just bloat, but inside <style>/<script>
		// entities are NOT decoded by the browser, so a ▾ in a CSS content rule
		// came out as the literal text "&#9662;" on translated pages. Node
		// serialization keeps raw UTF-8; the doctype must be re-added by hand.
		$out = $dom->saveHTML( $dom->documentElement );
		if ( ! is_string( $out ) || '' === $out ) {
			return $html;
		}
		if ( null !== $dom->doctype ) {
			$out = '<!DOCTYPE ' . $dom->doctype->name . '>' . "\n" . $out;
		}

		// Scripts, styles and textareas go back exactly as the page authored them.
		$out = $this->unmask_raw_text( $out );

		// Store the finished translated page for the next anonymous visitor.
		if ( $this->serve_cache && $this->is_secondary ) {
			Cache::set( $this->request_uri(), $this->current, $out );
		}

		return $out;
	}
}
