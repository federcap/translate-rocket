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

	private const ATTRIBUTES   = array( 'alt', 'title', 'placeholder', 'aria-label' );
	private const SKIP_PARENTS = array( 'script', 'style', 'code', 'pre', 'textarea', 'title' );

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
		$this->raw_blocks = array();

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
	 * Pure CSS values / measurements that are never real content and must never be
	 * collected or translated: "16px", "1.4em", "100%", "1.5rem", "inherit"…
	 * (these typically leak in from typography / style-guide demo blocks).
	 */
	private static function is_noise( string $text ): bool {
		if ( preg_match( '/^[\d.,\s]+(px|em|rem|pt|ex|ch|vh|vw|vmin|vmax|fr|%)$/i', $text ) ) {
			return true;
		}
		return in_array( strtolower( $text ), array( 'inherit', 'initial', 'unset', 'revert' ), true );
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
		if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only cache gate.
			return false;
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
		return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
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
			$skip .= "ancestor::{$tag} or ";
		}
		$skip   .= "ancestor::*[@id='wpadminbar'] or ancestor::*[@id='trrocket-ve-bar'] or ancestor::*[@translate='no']";
		$skip_el = "ancestor-or-self::*[@id='wpadminbar'] or ancestor-or-self::*[@id='trrocket-ve-bar'] or ancestor-or-self::*[@translate='no']";

		// User-configured CSS selectors to leave untranslated.
		$extra = NoTranslate::xpath_skip();
		if ( '' !== $extra ) {
			$skip    .= ' or ' . $extra;
			$skip_el .= ' or ' . $extra;
		}

		$collected = array();
		$changed   = false;

		// Materialize every node the engine will touch, then resolve translations
		// for exactly the texts present on THIS page in one batch. On small sites
		// that filters the cached full map (same cost as before); on huge sites
		// (100k+ strings) it becomes an indexed batch lookup, so memory stays
		// proportional to the page instead of the whole site.
		$text_nodes = iterator_to_array( $xpath->query( "//text()[not({$skip})]" ) );
		$attr_nodes = array();
		foreach ( self::ATTRIBUTES as $attr ) {
			$attr_nodes[ $attr ] = iterator_to_array( $xpath->query( "//*[@{$attr}][not({$skip_el})][not(self::link)]" ) );
		}
		// Le immagini: il loro `src` si tratta come tutto il resto, cioe' come una
		// cosa che puo' avere una versione per lingua. Serve a chi ha una foto con
		// del testo dentro, una locandina, un banner: tradurre la didascalia non
		// basta se l'immagine stessa parla un'altra lingua.
		$img_nodes = iterator_to_array( $xpath->query( "//img[@src][not({$skip_el})]" ) );

		$desc_nodes   = iterator_to_array( $xpath->query( '//meta[@name="description"]/@content' ) );
		// Social e parole chiave. ⚠️ Prima questi venivano SOLO tradotti se il
		// testo capitava di essere gia' in mappa, e non venivano mai raccolti: su
		// un sito con Yoast o Rank Math, che mettono un titolo social diverso da
		// quello della pagina, l'anteprima su Facebook e LinkedIn restava nella
		// lingua di partenza per sempre. Adesso si raccolgono come tutto il resto.
		$social_nodes = iterator_to_array( $xpath->query( '//meta[@property="og:title" or @property="og:description" or @property="og:site_name" or @property="og:image:alt" or @name="twitter:title" or @name="twitter:description" or @name="twitter:image:alt" or @name="keywords"]/@content' ) );
		$title_nodes  = iterator_to_array( $xpath->query( '//head/title' ) );

		$map = array();
		if ( $this->is_secondary ) {
			$candidates = array();
			foreach ( $text_nodes as $node ) {
				$candidates[] = trim( (string) $node->nodeValue );
			}
			foreach ( $attr_nodes as $attr => $els ) {
				foreach ( $els as $el ) {
					$candidates[] = trim( (string) $el->getAttribute( $attr ) );
				}
			}
			foreach ( $img_nodes as $img ) {
				$candidates[] = trim( (string) $img->getAttribute( 'src' ) );
			}
			foreach ( $desc_nodes as $meta ) {
				$candidates[] = trim( (string) $meta->nodeValue );
			}
			foreach ( $social_nodes as $social ) {
				$candidates[] = trim( (string) $social->nodeValue );
			}
			foreach ( $title_nodes as $title_el ) {
				$candidates[] = trim( (string) $title_el->textContent );
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
		foreach ( $text_nodes as $node ) {
			$text = trim( (string) $node->nodeValue );
			if ( '' === $text || ! preg_match( '/\p{L}/u', $text ) || self::is_noise( $text ) || NoTranslate::text_excluded( $text ) ) {
				continue;
			}
			if ( $this->do_collect ) {
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

		// Translatable attributes. <link> is skipped: its title attributes are the
		// machine-facing feed/oEmbed/RSD labels in <head> ("Site » Feed", "JSON"…) —
		// never user-visible, they would only pollute every page's string list.
		foreach ( self::ATTRIBUTES as $attr ) {
			foreach ( $attr_nodes[ $attr ] as $el ) {
				$value = trim( (string) $el->getAttribute( $attr ) );
				if ( '' === $value || ! preg_match( '/\p{L}/u', $value ) || NoTranslate::text_excluded( $value ) ) {
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
		foreach ( $img_nodes as $img ) {
			$src = trim( (string) $img->getAttribute( 'src' ) );
			// Un'immagine incorporata nella pagina (data:) non ha un indirizzo da
			// sostituire, e ficcarla nell'elenco delle stringhe lo riempirebbe di
			// migliaia di caratteri illeggibili.
			if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
				continue;
			}

			if ( $this->do_collect ) {
				$collected[] = array(
					// Il tipo e' 'image' e non 'attribute' apposta: cosi' la
					// traduzione automatica sa che qui non c'e' niente da tradurre.
					// Mandare un indirizzo a DeepL o all'AI non e' solo inutile:
					// tornerebbe indietro storpiato, e l'immagine sparirebbe.
					'original' => $src,
					'type'     => 'image',
					'context'  => 'src',
				);
			}

			if ( isset( $map[ $src ] ) ) {
				$img->setAttribute( 'src', $map[ $src ] );
				// srcset e sizes vanno tolti: restano quelli dell'immagine di
				// partenza, e il browser sceglierebbe da li' ignorando il src
				// appena messo. Sembrerebbe che la sostituzione non funzioni.
				$img->removeAttribute( 'srcset' );
				$img->removeAttribute( 'sizes' );
				$changed = true;
			}

			if ( $editing ) {
				$img->setAttribute( 'data-trr-img', rawurlencode( $src ) );
				$cls = (string) $img->getAttribute( 'class' );
				if ( false === strpos( ' ' . $cls . ' ', ' trrocket-ed-img ' ) ) {
					$img->setAttribute( 'class', '' === $cls ? 'trrocket-ed-img' : $cls . ' trrocket-ed-img' );
				}
				$changed = true;
			}
		}

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
			if ( isset( $map[ $value ] ) ) {
				$social->ownerElement->setAttribute( $social->nodeName, $map[ $value ] );
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
			if ( isset( $map[ $value ] ) ) {
				while ( $title_el->firstChild ) {
					$title_el->removeChild( $title_el->firstChild );
				}
				$title_el->appendChild( $dom->createTextNode( $map[ $value ] ) );
				$changed = true;
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
