<?php
/**
 * Import hand-written translations from Loco Translate (and any `.po`/`.mo`).
 *
 * Loco is the most installed translation plugin on the directory, but it is a
 * different animal: it edits the `.po`/`.mo` files of themes and plugins, not
 * the content of posts. What it produces is still worth having — those are
 * interface strings the site owner translated **by hand**, in their own wording.
 *
 * Only Loco's own protected directory is read: `wp-content/languages/loco/`.
 * That is deliberate. The rest of `wp-content/languages/` holds the language
 * packs WordPress downloads by itself — thousands of strings nobody on this
 * site chose, from screens no visitor ever sees. The TranslatePress importer
 * skips its gettext table for exactly the same reason, and importing the packs
 * here would contradict that decision and bury the useful strings in noise.
 *
 * Reading is done with WordPress's own `PO` and `MO` classes rather than a
 * parser of ours: they are what WordPress itself uses for these files, so we
 * cannot read a file differently from the site that produced it. Writing a
 * `.po` parser by hand means getting multi-line strings, escapes, contexts,
 * plurals and fuzzy flags right — all of which those classes already handle.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Languages;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `.po` / `.mo` files written by hand.
 */
class LocoImporter implements ImporterInterface {

	// Solo per il conteggio, che e' la parte cara. Cinque minuti: abbastanza
	// per non rifare il giro a ogni ricarica, poco per non mentire a lungo.
	private const MEMORIA = 5 * MINUTE_IN_SECONDS;

	/**
	 * Ceiling on the files read in one run. A site with hundreds of translated
	 * plugins is possible; hanging on the Import screen is not.
	 */
	private const MAX_FILE = 200;

	public function id(): string {
		return 'loco';
	}

	public function label(): string {
		return 'Loco Translate (.po / .mo written by hand)';
	}

	/**
	 * Loco's protected directory.
	 */
	private function cartella(): string {
		$base = defined( 'WP_LANG_DIR' ) ? WP_LANG_DIR : WP_CONTENT_DIR . '/languages';
		return rtrim( $base, '/\\' ) . '/loco';
	}

	/**
	 * The translation files in there, `.po` preferred over the `.mo` twin.
	 *
	 * @return string[]
	 */
	private function file(): array {
		$dir = $this->cartella();
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$trovati = array();
		$giro    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $giro as $f ) {
			if ( ! $f->isFile() ) {
				continue;
			}
			$est = strtolower( $f->getExtension() );
			if ( 'po' !== $est && 'mo' !== $est ) {
				continue;
			}
			$trovati[] = $f->getPathname();
			if ( count( $trovati ) >= self::MAX_FILE * 2 ) {
				break;
			}
		}

		// Loco scrive .po e .mo con lo stesso nome: si tiene il .po, che porta
		// piu' informazioni (contesti, note, il marchio "da rivedere").
		$per_base = array();
		foreach ( $trovati as $percorso ) {
			$base = preg_replace( '/\.(po|mo)$/i', '', $percorso );
			if ( ! isset( $per_base[ $base ] ) || preg_match( '/\.po$/i', $percorso ) ) {
				$per_base[ $base ] = $percorso;
			}
		}

		return array_slice( array_values( $per_base ), 0, self::MAX_FILE );
	}

	/**
	 * The locale a file is for, from its name.
	 *
	 * Loco names files `dominio-it_IT.po` (and `it_IT.po` for core), so the
	 * locale is what follows the last dash — or the whole name when there is
	 * no dash.
	 */
	private function locale_del_file( string $percorso ): string {
		$nome = preg_replace( '/\.(po|mo)$/i', '', basename( $percorso ) );
		$taglio = strrpos( $nome, '-' );
		$pezzo  = ( false === $taglio ) ? $nome : substr( $nome, $taglio + 1 );
		// Deve somigliare a un locale: `it`, `it_IT`, `pt_BR`.
		return preg_match( '/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $pezzo ) ? $pezzo : '';
	}

	public function is_available(): bool {
		// Una lettura di cartella: si risponde al volo. Qui ricordarla sarebbe
		// il caso peggiore — chi carica dei .po vuole vederli subito.
		return ! empty( $this->file() );
	}

	public function count_available(): int {
		$chiave = 'trrocket_imp_loco_n';
		$noto   = get_transient( $chiave );
		if ( false !== $noto ) {
			return (int) $noto;
		}
		$n = $this->cammina( false );
		set_transient( $chiave, (string) $n, self::MEMORIA );
		return $n;
	}

	/**
	 * @return array{count:int,error:string}
	 */
	public function import(): array {
		$n = $this->cammina( true );
		delete_transient( 'trrocket_imp_loco_c' );
		delete_transient( 'trrocket_imp_loco_n' );
		return array(
			'count' => $n,
			'error' => '',
		);
	}

	private function cammina( bool $salva ): int {
		$nostra_partenza = (string) ( Settings::get()['source_language'] ?? 'en' );
		$totale = 0;

		foreach ( $this->file() as $percorso ) {
			$locale = $this->locale_del_file( $percorso );
			if ( '' === $locale ) {
				continue;
			}
			$lingua = Comune::lingua( $locale );
			if ( $lingua === $nostra_partenza || ! Languages::exists( $lingua ) ) {
				continue;
			}
			$totale += $this->un_file( $percorso, $lingua, $salva );
		}

		return $totale;
	}

	/**
	 * One `.po` or `.mo`.
	 */
	private function un_file( string $percorso, string $lingua, bool $salva ): int {
		$voci = self::leggi( $percorso );
		$fatti = 0;

		foreach ( $voci as $originale => $tradotto ) {
			if ( Comune::coppia( (string) $originale, $lingua, (string) $tradotto, $salva ) ) {
				++$fatti;
			}
		}

		return $fatti;
	}

	/**
	 * Read a `.po`/`.mo` into `original => translation`.
	 *
	 * Public and static so the tests can call it on a file without a site.
	 *
	 * Skipped on purpose:
	 *  - the header entry (an empty `msgid` holding the file's metadata);
	 *  - entries marked **fuzzy**, which mean "a machine guessed this, nobody
	 *    has checked it" — importing them would spread unreviewed text;
	 *  - empty translations.
	 *
	 * For a plural entry only the singular is taken: our model pairs one string
	 * with one string, and the plural forms belong to gettext, not to a page.
	 *
	 * @return array<string,string>
	 */
	public static function leggi( string $percorso ): array {
		if ( ! is_readable( $percorso ) ) {
			return array();
		}

		require_once ABSPATH . WPINC . '/pomo/po.php';
		require_once ABSPATH . WPINC . '/pomo/mo.php';

		$po = preg_match( '/\.po$/i', $percorso ) ? new \PO() : new \MO();
		if ( ! $po->import_from_file( $percorso ) ) {
			return array();
		}

		$fuori = array();
		foreach ( (array) $po->entries as $voce ) {
			$originale = (string) $voce->singular;
			if ( '' === trim( $originale ) ) {
				continue;
			}
			if ( ! empty( $voce->flags ) && in_array( 'fuzzy', (array) $voce->flags, true ) ) {
				continue;
			}
			$trad = isset( $voce->translations[0] ) ? (string) $voce->translations[0] : '';
			if ( '' === trim( $trad ) ) {
				continue;
			}
			// Se la stessa frase compare con contesti diversi si tiene la prima:
			// il nostro modello e' una frase, una traduzione.
			if ( ! isset( $fuori[ $originale ] ) ) {
				$fuori[ $originale ] = $trad;
			}
		}

		return $fuori;
	}
}
