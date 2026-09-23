<?php
/**
 * Import translations from Falang.
 *
 * Falang keeps each translation as meta on the original post, keyed by locale:
 * `_it_IT_post_title`, `_it_IT_post_content`, and a `_it_IT_published` flag that
 * must be '1' — with '0' Falang itself shows the original. Terms carry
 * `_it_IT_name`; the site's own strings (title, tagline, widgets) sit in private
 * `falang_mo` posts in the same shape Polylang uses.
 *
 * A language is a `language` term with a `falang_<slug>` twin in `term_language`.
 * Polylang uses the same `language` taxonomy with `pll_<slug>` twins: those are
 * not Falang's and are left to the Polylang importer.
 *
 * Read from the database directly, so it works with Falang switched off.
 * Formats verified on Falang 1.4.5 (`_ssh/ricerca/import-nuovi/FORMATI.md`).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Falang's meta.
 */
class FalangImporter extends MetaLanguagesImporter {

	/**
	 * Formats of dates and times are stored among Falang's strings: they are
	 * settings, not text, and would come back «translated» into nonsense.
	 */
	private const NON_TESTO = array( 'F j, Y', 'g:i a', 'Y-m-d', 'H:i', 'd/m/Y', 'j F Y', 'G:i' );

	public function id(): string {
		return 'falang';
	}

	public function label(): string {
		return 'Falang';
	}

	protected function chiave(): string {
		return 'trrocket_imp_falang_n';
	}

	/**
	 * Falang's languages: `language` terms with a `falang_` twin.
	 *
	 * @return array<int,array{prefisso:string,codice:string,locale:string}>
	 */
	private function tutte(): array {
		global $wpdb;
		$righe = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT t.slug, tt.description FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE tt.taxonomy = 'language'
			   AND EXISTS ( SELECT 1 FROM {$wpdb->terms} t2
			                INNER JOIN {$wpdb->term_taxonomy} tt2 ON tt2.term_id = t2.term_id
			                WHERE tt2.taxonomy = 'term_language' AND t2.slug = CONCAT( 'falang_', t.slug ) )"
		);
		$out = array();
		foreach ( (array) $righe as $r ) {
			$d      = maybe_unserialize( (string) $r->description );
			$locale = is_array( $d ) && ! empty( $d['locale'] ) ? (string) $d['locale'] : '';
			if ( '' === $locale || ! preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?$/', $locale ) ) {
				continue;
			}
			$out[] = array(
				'prefisso' => '_' . $locale . '_',
				'codice'   => Comune::lingua( $locale ),
				'locale'   => $locale,
			);
		}
		return $out;
	}

	protected function lingue(): array {
		$partenza = $this->locale_partenza();
		return array_values( array_filter( $this->tutte(), static function ( $l ) use ( $partenza ) {
			return $l['locale'] !== $partenza;
		} ) );
	}

	private function locale_partenza(): string {
		$o = get_option( 'falang' );
		return ( is_array( $o ) && ! empty( $o['default_language'] ) ) ? (string) $o['default_language'] : 'en_US';
	}

	protected function partenza(): string {
		return Comune::lingua( $this->locale_partenza() );
	}

	protected function pubblicata( int $post_id, string $prefisso ): bool {
		return '1' === (string) get_post_meta( $post_id, $prefisso . 'published', true );
	}

	/**
	 * Site title, tagline, widget texts: one private `falang_mo` post per language,
	 * whose meta `_falang_strings_translations` is a list of (original, translation).
	 */
	protected function stringhe( string $nostra_partenza, bool $salva ): int {
		global $wpdb;
		$fatti = 0;
		foreach ( $this->lingue() as $l ) {
			if ( $l['codice'] === $nostra_partenza ) {
				continue;
			}
			// Il post e' «falang_mo_<id del termine della lingua>»: si cerca il termine.
			$term_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				 WHERE tt.taxonomy = 'language' AND tt.description LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( '"' . $l['locale'] . '"' ) . '%'
			) );
			if ( ! $term_id ) {
				continue;
			}
			$post_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'falang_mo' AND post_title = %s LIMIT 1",
				'falang_mo_' . $term_id
			) );
			if ( ! $post_id ) {
				continue;
			}
			$coppie = get_post_meta( $post_id, '_falang_strings_translations', true );
			foreach ( is_array( $coppie ) ? $coppie : array() as $c ) {
				if ( ! is_array( $c ) || count( $c ) < 2 ) {
					continue;
				}
				$orig = trim( (string) $c[0] );
				$trad = trim( (string) $c[1] );
				if ( '' === $orig || '' === $trad || in_array( $orig, self::NON_TESTO, true ) ) {
					continue;
				}
				if ( Comune::coppia( $orig, $l['codice'], $trad, $salva ) ) {
					++$fatti;
				}
			}
		}
		return $fatti;
	}
}
