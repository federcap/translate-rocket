<?php
/**
 * Page-builder templates duplicated one per language.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * A site coming from Polylang or WPML usually has one header and one footer PER LANGUAGE,
 * built with Elementor's theme builder (or Header Footer Elementor): that is the only way
 * those plugins can serve a translated header, because they serve a different page for
 * every language.
 *
 * TranslateRocket translates the page the site actually sends to the browser, so one
 * header and one footer are enough — the same ones, translated. People migrating do not
 * know this, keep the three copies, and then wonder why the text inside the French footer
 * cannot be translated: they are looking at a second template that was never in the source
 * language at all.
 *
 * This finds those duplicates so the import screen can say it in one sentence.
 */
class BuilderTemplates {

	/**
	 * Template post types worth looking at, and where their kind is stored.
	 *
	 * @var array<string,string>
	 */
	const TYPES = array(
		'elementor_library' => '_elementor_template_type',
		'elementor-hf'      => 'ehf_template_type',
	);

	/**
	 * Kinds that are shown on every page, so one translated copy per language is the
	 * usual Polylang/WPML workaround.
	 *
	 * @var string[]
	 */
	// 'product' e' il modello della scheda prodotto di Elementor Pro: su un negozio
	// multilingua e' una delle copie per lingua come le altre. Aggiunto il 20/9 dopo
	// aver LETTO (non eseguito) l'elenco dei tipi in modules/theme-builder: header,
	// footer, single, archive, popup, product; 'section' e 'page' sono blocchi
	// riusabili, non si mostrano su ogni pagina, e restano fuori.
	const KINDS = array( 'header', 'footer', 'single', 'archive', 'popup', 'product', 'type_header', 'type_footer' );

	/**
	 * Templates that exist in more than one language, grouped by kind.
	 *
	 * @return array<string,int> kind => how many copies, e.g. array( 'header' => 3 )
	 */
	public static function per_language(): array {
		static $out = null;
		if ( null !== $out ) {
			return $out;
		}
		$out = array();
		if ( ! function_exists( 'get_posts' ) ) {
			return $out;
		}
		$lingua = self::lingua_di();
		if ( null === $lingua ) {
			return $out;  // no Polylang or WPML: nothing to say.
		}
		foreach ( self::TYPES as $tipo => $meta ) {
			if ( ! post_type_exists( $tipo ) ) {
				continue;
			}
			// ⚠️ Qui c'era get_posts( ..., 'suppress_filters' => true ) con il commento
			// «tutte le lingue». Non e' vero: suppress_filters zittisce i filtri SQL,
			// ma Polylang e WPML filtrano per lingua da parse_query, che resta acceso.
			// Risultato: su un sito Polylang vero si vedeva UNA lingua sola, si contava
			// «una lingua» e l'avviso sulle testate per lingua non compariva mai — cioe'
			// proprio a chi ne aveva bisogno. Trovato il 20/9 col plugin dei modelli vero.
			// Si legge dal database, che nessuno dei due puo' filtrare.
			global $wpdb;
			$posts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = %s AND post_status IN ( 'publish', 'draft' )
					 ORDER BY ID LIMIT 60",
					$tipo
				)
			); // phpcs:ignore WordPress.DB
			$per_kind = array();
			foreach ( $posts as $id ) {
				$kind = strtolower( (string) get_post_meta( $id, $meta, true ) );
				$kind = str_replace( 'type_', '', $kind );
				if ( '' === $kind || ! in_array( $kind, self::KINDS, true ) ) {
					continue;
				}
				$lang = $lingua( $id );
				if ( '' === $lang ) {
					continue;
				}
				$per_kind[ $kind ][ $lang ] = true;
			}
			foreach ( $per_kind as $kind => $lingue ) {
				if ( count( $lingue ) > 1 ) {
					$out[ $kind ] = max( $out[ $kind ] ?? 0, count( $lingue ) );
				}
			}
		}
		// Sempre nello stesso ordine (testata, pie' di pagina, ...): la frase dell'avviso non
		// deve cambiare a seconda di come il database restituisce i modelli.
		$ordinato = array();
		foreach ( self::KINDS as $kind ) {
			if ( isset( $out[ $kind ] ) ) {
				$ordinato[ $kind ] = $out[ $kind ];
			}
		}
		$out = $ordinato;
		return $out;
	}

	/**
	 * How to ask a post its language, or null when no multilingual plugin answers.
	 *
	 * @return callable|null
	 */
	private static function lingua_di(): ?callable {
		if ( function_exists( 'pll_get_post_language' ) ) {
			return static function ( $id ) {
				return (string) pll_get_post_language( (int) $id, 'slug' );
			};
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return static function ( $id ) {
				$d = apply_filters( 'wpml_post_language_details', null, (int) $id );
				return is_array( $d ) ? (string) ( $d['language_code'] ?? '' ) : '';
			};
		}
		return null;
	}

	/**
	 * Menus that the other plugin keeps one per language.
	 *
	 * Polylang stores them in its own option, one menu per theme position per
	 * language (see its src/admin/admin-nav-menu.php). Nobody ever explains what
	 * happens to them, and somebody who has just switched goes hunting in
	 * Appearance and deletes the wrong one. Verified on a test site: when Polylang
	 * is switched off, WordPress keeps the menu of the main language in each
	 * position and the others simply stop being used. Nothing disappears.
	 *
	 * WPML does the same with translated menus (one nav_menu term per language,
	 * linked in its icl_translations table). Verified on a test site too
	 * (collaudo-wpml-affiancata-http.sh): with WPML switched off, the main
	 * language keeps its menu and the translated copies simply stop being used.
	 *
	 * @param string $plugin Name of the other plugin ('Polylang', 'WPML'); '' for both.
	 * @return array<string,string[]> language slug => menu names
	 */
	public static function menus_per_language( string $plugin = '' ): array {
		$chi = strtolower( $plugin );
		$out = array();
		if ( '' === $chi || false !== strpos( $chi, 'wpml' ) ) {
			$out = self::wpml_menus();
		}
		if ( '' !== $chi && false === strpos( $chi, 'polylang' ) ) {
			return $out;
		}
		$opz = get_option( 'polylang' );
		if ( ! is_array( $opz ) || empty( $opz['nav_menus'] ) || ! is_array( $opz['nav_menus'] ) ) {
			return $out;
		}
		$tema      = (string) get_option( 'stylesheet' );
		$posizioni = $opz['nav_menus'][ $tema ] ?? array();
		if ( ! is_array( $posizioni ) ) {
			return $out;
		}
		$predefinita = isset( $opz['default_lang'] ) ? (string) $opz['default_lang'] : '';
		foreach ( $posizioni as $per_lingua ) {
			if ( ! is_array( $per_lingua ) ) {
				continue;
			}
			foreach ( $per_lingua as $lang => $menu_id ) {
				$lang = (string) $lang;
				if ( '' === $lang || $lang === $predefinita || ! $menu_id ) {
					continue;
				}
				$menu = wp_get_nav_menu_object( (int) $menu_id );
				if ( ! $menu ) {
					continue;
				}
				$out[ $lang ][ (int) $menu_id ] = (string) $menu->name;
			}
		}
		foreach ( $out as $lang => $nomi ) {
			$out[ $lang ] = array_values( $nomi );
		}
		return $out;
	}

	/**
	 * WPML's translated menus: nav_menu terms in a language other than WPML's
	 * default. Read from the tables, never through get_term()/wp_get_nav_menu_object():
	 * while WPML is active those are filtered by the current language and would
	 * hand back the main-language menu instead of the copy.
	 *
	 * @return array<string,array<int,string>> language => [ term id => menu name ]
	 */
	private static function wpml_menus(): array {
		global $wpdb;
		$out = array();
		$s   = get_option( 'icl_sitepress_settings' );
		$def = is_array( $s ) ? (string) ( $s['default_language'] ?? '' ) : '';
		if ( '' === $def || '_default_' === $def ) {
			return $out;
		}
		$tr = $wpdb->prefix . 'icl_translations';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tr ) ) ) { // phpcs:ignore WordPress.DB
			return $out;
		}
		$righe = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.language_code AS lang, te.term_id AS id, te.name AS name
				 FROM `{$tr}` t
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = t.element_id AND tt.taxonomy = 'nav_menu'
				 INNER JOIN {$wpdb->terms} te ON te.term_id = tt.term_id
				 WHERE t.element_type = 'tax_nav_menu' AND t.language_code <> %s
				 ORDER BY te.term_id",
				$def
			)
		); // phpcs:ignore WordPress.DB
		foreach ( (array) $righe as $r ) {
			$lang = (string) $r->lang;
			if ( '' === $lang ) {
				continue;
			}
			$out[ $lang ][ (int) $r->id ] = (string) $r->name;
		}
		return $out;
	}

	/**
	 * The sentence about those menus, or '' when there are none.
	 *
	 * @param string $plugin Name of the other plugin.
	 */
	public static function menus_notice( string $plugin ): string {
		$trovati = self::menus_per_language( $plugin );
		if ( empty( $trovati ) ) {
			return '';
		}
		$quanti = 0;
		$nomi   = array();
		foreach ( $trovati as $elenco ) {
			$quanti += count( $elenco );
			foreach ( $elenco as $n ) {
				$nomi[] = $n;
			}
		}
		$nomi = array_slice( array_unique( $nomi ), 0, 6 );
		return sprintf(
			/* translators: 1: how many menus, 2: the other plugin's name, 3: the menu names. */
			_n(
				'You also have %1$d menu in another language (%3$s), kept by %2$s. You can leave it exactly where it is: when %2$s is switched off, WordPress keeps the menu of your main language in every position and this one simply stops being used. Nothing disappears from your site, and nothing needs deleting.',
				'You also have %1$d menus in other languages (%3$s), kept by %2$s. You can leave them exactly where they are: when %2$s is switched off, WordPress keeps the menu of your main language in every position and these simply stop being used. Nothing disappears from your site, and nothing needs deleting.',
				$quanti,
				'translate-rocket'
			),
			$quanti,
			$plugin,
			implode( ', ', $nomi )
		);
	}

	/**
	 * The sentence for the import screen, or '' when there is nothing to say.
	 */
	public static function notice( string $plugin ): string {
		$trovati = self::per_language();
		if ( empty( $trovati ) ) {
			return '';
		}
		$pezzi = array();
		foreach ( $trovati as $kind => $n ) {
			/* translators: 1: how many copies, 2: kind of template (header, footer, popup...). */
			$pezzi[] = sprintf( _n( '%1$d %2$s', '%1$d %2$s', $n, 'translate-rocket' ), $n, self::etichetta( $kind, $n ) );
		}
		return sprintf(
			/* translators: 1: list like "3 headers and 3 footers", 2: source plugin name (Polylang, WPML). */
			__( 'You have %1$s, one per language — %2$s needs them, because it serves a different page for each language. TranslateRocket does not: it translates the one header and the one footer you already have, into every language. You do not have to change anything: leaving them as they are works. If one day you want a single header, do it one step at a time — put a TranslateRocket switcher in the one in your source language, look at the site in every language, and only then move one extra copy to the Trash and look again. From the Trash they come straight back. When in doubt, leave them: they do no harm.', 'translate-rocket' ),
			self::elenco( $pezzi ),
			$plugin
		);
	}

	/**
	 * Plural, readable name of a template kind.
	 */
	private static function etichetta( string $kind, int $n ): string {
		$nomi = array(
			'header'  => array( __( 'header', 'translate-rocket' ), __( 'headers', 'translate-rocket' ) ),
			'footer'  => array( __( 'footer', 'translate-rocket' ), __( 'footers', 'translate-rocket' ) ),
			'single'  => array( __( 'single-post template', 'translate-rocket' ), __( 'single-post templates', 'translate-rocket' ) ),
			'archive' => array( __( 'archive template', 'translate-rocket' ), __( 'archive templates', 'translate-rocket' ) ),
			'popup'   => array( __( 'pop-up', 'translate-rocket' ), __( 'pop-ups', 'translate-rocket' ) ),
			'product' => array( __( 'product template', 'translate-rocket' ), __( 'product templates', 'translate-rocket' ) ),
		);
		$coppia = $nomi[ $kind ] ?? array( $kind, $kind );
		return 1 === $n ? $coppia[0] : $coppia[1];
	}

	/**
	 * "3 headers and 3 footers" — joined the way the language joins a list.
	 *
	 * @param string[] $pezzi Ready-made pieces.
	 */
	private static function elenco( array $pezzi ): string {
		if ( count( $pezzi ) < 2 ) {
			return (string) ( $pezzi[0] ?? '' );
		}
		$ultimo = array_pop( $pezzi );
		return sprintf(
			/* translators: 1: all items but the last, comma separated, 2: the last item. */
			__( '%1$s and %2$s', 'translate-rocket' ),
			implode( ', ', $pezzi ),
			$ultimo
		);
	}
}
