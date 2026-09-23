<?php
/**
 * Shared reader for plugins that keep translations as meta on the ORIGINAL post.
 *
 * Falang and Sublanguage (Falang was born from Sublanguage) create no copy pages:
 * the Italian title of a page lives on that same page as `_it_IT_post_title`
 * (Falang, prefix = locale) or `_it_post_title` (Sublanguage, prefix = language
 * slug). So pairing is exact — the meta sits on its original — and everything is
 * read straight from the database, so it works with the plugin switched off.
 *
 * What each plugin decides for itself, the subclass answers: which languages it
 * has, which one the site is written in, and whether a translation is published.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Languages;
use TranslateRocket\Settings;
use TranslateRocket\Slugs;

defined( 'ABSPATH' ) || exit;

/**
 * Base for the meta-per-language importers.
 */
abstract class MetaLanguagesImporter implements ImporterInterface {

	private const BLOCCO = 200;

	/**
	 * The plugin's languages OTHER than the one the site is written in.
	 *
	 * @return array<int,array{prefisso:string,codice:string}> prefix of the meta keys, our language code
	 */
	abstract protected function lingue(): array;

	/**
	 * Our code for the language the plugin considers the original, or '' if unknown.
	 */
	abstract protected function partenza(): string;

	/**
	 * Whether the translation of this post in this language is published.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $prefisso Meta prefix of the language.
	 */
	abstract protected function pubblicata( int $post_id, string $prefisso ): bool;

	/**
	 * Cache key for the count (the expensive part).
	 */
	abstract protected function chiave(): string;

	/**
	 * Strings outside posts and terms (site title, widgets). None by default.
	 *
	 * @param string $nostra_partenza Our source language.
	 * @param bool   $salva           Store, or just count.
	 */
	protected function stringhe( string $nostra_partenza, bool $salva ): int {
		unset( $nostra_partenza, $salva );
		return 0;
	}

	public function is_available(): bool {
		return array() !== $this->lingue() && $this->ha_meta();
	}

	public function count_available(): int {
		$noto = get_transient( $this->chiave() );
		if ( false !== $noto ) {
			return (int) $noto;
		}
		$n = $this->errore() ? 0 : $this->cammina( false );
		set_transient( $this->chiave(), (string) $n, 5 * MINUTE_IN_SECONDS );
		return $n;
	}

	/**
	 * @return array{count:int,error:string}
	 */
	public function import(): array {
		$errore = $this->errore();
		if ( '' !== $errore ) {
			return array( 'count' => 0, 'error' => $errore );
		}
		$n = $this->cammina( true );
		delete_transient( $this->chiave() );
		return array( 'count' => $n, 'error' => '' );
	}

	/**
	 * Refuse, with a reason, when importing would do harm.
	 *
	 * If the plugin says the site is written in German and TranslateRocket says
	 * English, the «translations» would be paired with the wrong side: better a
	 * clear stop than a site full of pairs back to front.
	 */
	private function errore(): string {
		$loro   = $this->partenza();
		$nostra = (string) ( Settings::get()['source_language'] ?? 'en' );
		if ( '' !== $loro && $loro !== $nostra ) {
			return 'source_mismatch';
		}
		return '';
	}

	/**
	 * Whether there is at least one translated meta, for any of the languages.
	 */
	private function ha_meta(): bool {
		global $wpdb;
		foreach ( $this->lingue() as $l ) {
			$like = $wpdb->esc_like( $l['prefisso'] . 'post_' ) . '%';
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $like ) ) ) { // phpcs:ignore WordPress.DB
				return true;
			}
		}
		return false;
	}

	private function cammina( bool $salva ): int {
		$nostra = (string) ( Settings::get()['source_language'] ?? 'en' );
		$totale = 0;
		foreach ( $this->lingue() as $l ) {
			if ( $l['codice'] === $nostra || ! Languages::exists( $l['codice'] ) ) {
				continue;
			}
			$totale += $this->articoli( $l['prefisso'], $l['codice'], $salva );
			$totale += $this->termini( $l['prefisso'], $l['codice'], $salva );
			$totale += $this->menu( $l['prefisso'], $l['codice'], $salva );
		}
		return $totale + $this->stringhe( $nostra, $salva );
	}

	/**
	 * Posts and pages with a translated title, excerpt or content (menu items: menu()).
	 */
	private function articoli( string $prefisso, string $codice, bool $salva ): int {
		global $wpdb;
		$chiavi = array( $prefisso . 'post_title', $prefisso . 'post_excerpt', $prefisso . 'post_content' );
		$fatti  = 0;
		$salta  = 0;
		do {
			// Solo i post veri: le revisioni portano anche loro i meta tradotti
			// (Sublanguage li copia se le revisioni sono accese), e cestino e
			// bozze automatiche non sono pagine di nessuno.
			$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				 WHERE m.meta_key IN (%s, %s, %s)
				   AND p.post_type NOT IN ('revision', 'nav_menu_item')
				   AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')
				 ORDER BY p.ID LIMIT %d OFFSET %d",
				$chiavi[0],
				$chiavi[1],
				$chiavi[2],
				self::BLOCCO,
				$salta
			) );
			$salta += self::BLOCCO;
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( ! $this->pubblicata( $id, $prefisso ) ) {
					continue;
				}
				$origine = get_post( $id );
				if ( ! $origine ) {
					continue;
				}
				// Un campo vuoto vuol dire «usa l'originale» per entrambi i plugin:
				// si passa l'originale stesso, e l'appaiamento lo scarta come identico.
				$tradotto = (object) array(
					'post_title'   => $this->meta_o( $id, $prefisso . 'post_title', $origine->post_title ),
					'post_excerpt' => $this->meta_o( $id, $prefisso . 'post_excerpt', $origine->post_excerpt ),
					'post_content' => $this->meta_o( $id, $prefisso . 'post_content', $origine->post_content ),
				);
				$fatti += Comune::appaia( $origine, $tradotto, $codice, $salva );
				// L'indirizzo tradotto (/it/chi-siamo/): e' quello che Google ha gia'
				// indicizzato. Senza, dopo il passaggio la pagina italiana cambierebbe
				// indirizzo e i vecchi link finirebbero su una pagina che non c'e'.
				$slug = (string) get_post_meta( $id, $prefisso . 'post_name', true );
				if ( $salva && '' !== $slug && $slug !== (string) $origine->post_name ) {
					Slugs::set( $id, $codice, $slug );
				}
			}
		} while ( count( $ids ) === self::BLOCCO );
		return $fatti;
	}

	/**
	 * Menu labels written by hand.
	 *
	 * A menu item is a post of its own, and both plugins keep its translated label
	 * on it like on any post: `_it_IT_post_title`, and the title attribute in
	 * `_it_IT_post_excerpt`. Only the labels someone typed are read. An item with
	 * no label of its own shows the title of the page it points to, and that one
	 * already comes across with the page: pairing it again here would give the
	 * same sentence two different translations.
	 */
	private function menu( string $prefisso, string $codice, bool $salva ): int {
		global $wpdb;
		$fatti = 0;
		$righe = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT p.ID, p.post_title, p.post_excerpt, m.meta_key, m.meta_value
			 FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type = 'nav_menu_item' AND p.post_status NOT IN ('trash', 'auto-draft')
			   AND m.meta_key IN (%s, %s) AND m.meta_value <> '' LIMIT 20000",
			$prefisso . 'post_title',
			$prefisso . 'post_excerpt'
		) );
		foreach ( (array) $righe as $r ) {
			if ( ! $this->pubblicata( (int) $r->ID, $prefisso ) ) {
				continue;
			}
			$originale = ( $prefisso . 'post_title' === $r->meta_key ) ? (string) $r->post_title : (string) $r->post_excerpt;
			if ( '' === trim( $originale ) ) {
				continue;
			}
			if ( Comune::coppia( $originale, $codice, (string) $r->meta_value, $salva ) ) {
				++$fatti;
			}
		}
		return $fatti;
	}

	/**
	 * Category and tag names and descriptions.
	 */
	private function termini( string $prefisso, string $codice, bool $salva ): int {
		global $wpdb;
		$fatti = 0;
		$righe = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta}
			 WHERE meta_key IN (%s, %s) AND meta_value <> '' LIMIT 20000",
			$prefisso . 'name',
			$prefisso . 'description'
		) );
		foreach ( (array) $righe as $r ) {
			$termine = get_term( (int) $r->term_id );
			if ( ! $termine || is_wp_error( $termine ) ) {
				continue;
			}
			$originale = ( $prefisso . 'name' === $r->meta_key ) ? (string) $termine->name : (string) $termine->description;
			if ( '' === trim( $originale ) ) {
				continue;
			}
			if ( Comune::coppia( $originale, $codice, (string) $r->meta_value, $salva ) ) {
				++$fatti;
			}
		}
		return $fatti;
	}

	/**
	 * A translated field, or the original when the plugin left it empty.
	 */
	private function meta_o( int $id, string $chiave, string $originale ): string {
		$v = get_post_meta( $id, $chiave, true );
		return ( is_string( $v ) && '' !== trim( $v ) ) ? $v : $originale;
	}
}
