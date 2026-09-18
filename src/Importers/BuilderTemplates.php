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
	const KINDS = array( 'header', 'footer', 'single', 'archive', 'popup', 'type_header', 'type_footer' );

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
			$posts = get_posts(
				array(
					'post_type'        => $tipo,
					'post_status'      => array( 'publish', 'draft' ),
					'numberposts'      => 60,
					'fields'           => 'ids',
					'suppress_filters' => true,   // all languages, not just the current one.
				)
			);
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
			__( 'You have %1$s, one per language — %2$s needs them, because it serves a different page for each language. TranslateRocket does not: it translates the header and footer you already have, in every language. After the import, keep the ones in your source language, put a single TranslateRocket switcher in the header, and the copies can go.', 'translate-rocket' ),
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
