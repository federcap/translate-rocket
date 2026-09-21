<?php
/**
 * "Where you are, and what to do next."
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Coexistence;
use TranslateRocket\Languages;
use TranslateRocket\Settings;
use TranslateRocket\Strings;
use TranslateRocket\Importers\Importers;
use TranslateRocket\Importers\CopyCleanup;
use TranslateRocket\Importers\BuilderTemplates;
use TranslateRocket\Importers\ProvidesCopies;

defined( 'ABSPATH' ) || exit;

/**
 * A plugin can be right and still be useless.
 *
 * Somebody installed TranslateRocket next to Polylang, looked at their site,
 * saw nothing change, and spent hours hunting a fault that was not there: the
 * plugin was standing aside on purpose. Everything needed to explain that was
 * in the code, spread over six screens and a dozen notices. Nowhere did one
 * place say: this is where you are, this is what is left, press here.
 *
 * That is what this panel is. It reads the real state of the site every time it
 * is drawn (no stored progress to go stale), and turns it into a short list of
 * steps, each one either done, waiting for you with a button, or explicitly
 * nothing to worry about. The last kind matters as much as the others: a step
 * that says "you can leave this alone" stops somebody deleting their own menus.
 */
class NextSteps {

	/** Where the number next to the menu entry is kept. */
	const PENDING = 'trrocket_passi_da_fare';

	/** L'elenco dei passi tenuto da parte per cinque minuti. */
	const CACHE = 'trrocket_passi_elenco';

	/**
	 * Draw the panel. Safe to call anywhere in the admin.
	 */
	public static function render( bool $con_titolo = true, ?array $pronti = null ): void {
		try {
			// ⚠️ Il cassetto si disegna su OGNI schermata: se ricalcolasse qui, la cache
			// non servirebbe a niente (due COUNT per lingua, l'importatore, le copie...).
			$passi = null !== $pronti ? $pronti : self::steps();
		} catch ( \Throwable $e ) {
			// Questo pannello legge anche le opzioni di ALTRI plugin: se uno di loro
			// cambia forma, il pannello puo' saltare, ma la bacheca no. Mai un errore
			// fatale in faccia a chi sta solo cercando di capire cosa fare.
			\TranslateRocket\Logger::warning( 'panel', __( 'The "what to do next" panel could not be built this time. Everything else works as usual.', 'translate-rocket' ), $e->getMessage() );
			return;
		}
		if ( empty( $passi ) ) {
			return;
		}
		$fatti = 0;
		$da    = 0;
		foreach ( $passi as $p ) {
			if ( 'fatto' === $p['stato'] ) {
				++$fatti;
			} elseif ( 'da-fare' === $p['stato'] ) {
				++$da;
			}
		}
		$totale = count( $passi ) - self::count_state( $passi, 'nota' );
		?>
		<div class="trrocket-card trr-passi">
			<div class="trr-passi-testa">
				<?php if ( $con_titolo ) : ?>
					<h2><?php esc_html_e( 'Where you are, and what to do next', 'translate-rocket' ); ?></h2>
				<?php else : ?>
					<p class="trr-passi-dove trr-passi-dove-sola"><?php echo esc_html( self::where() ); ?></p>
				<?php endif; ?>
				<span class="trr-passi-conta">
					<?php
					printf(
						/* translators: 1: steps done, 2: steps in total. */
						esc_html__( '%1$d of %2$d done', 'translate-rocket' ),
						(int) $fatti,
						(int) $totale
					);
					?>
				</span>
			</div>
			<?php if ( $con_titolo ) : ?>
				<p class="trr-passi-dove"><?php echo esc_html( self::where() ); ?></p>
			<?php endif; ?>
			<div class="trr-passi-barra" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: steps done, 2: steps in total. */ __( '%1$d of %2$d done', 'translate-rocket' ), (int) $fatti, (int) $totale ) ); ?>">
				<span style="width:<?php echo (int) ( $totale > 0 ? round( 100 * $fatti / $totale ) : 0 ); ?>%"></span>
			</div>
			<ol class="trr-passi-elenco">
				<?php foreach ( $passi as $p ) : ?>
					<li class="trr-passo trr-passo-<?php echo esc_attr( $p['stato'] ); ?>">
						<span class="trr-passo-segno" aria-hidden="true"><?php echo esc_html( self::sign( $p['stato'] ) ); ?></span>
						<div class="trr-passo-corpo">
							<strong><?php echo esc_html( $p['titolo'] ); ?></strong>
							<span class="trr-passo-d"><?php echo esc_html( $p['testo'] ); ?></span>
							<?php if ( ! empty( $p['azione'] ) && ! empty( $p['url'] ) ) : ?>
								<a class="button <?php echo 'da-fare' === $p['stato'] ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( $p['azione'] ); ?></a>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php if ( 0 === $da ) : ?>
				<p class="trr-passi-fine"><?php esc_html_e( 'Nothing is waiting for you. Add or change content and come back: new text shows up here.', 'translate-rocket' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The steps, kept for five minutes.
	 *
	 * The drawer is drawn on every screen of the plugin, so working the list out from
	 * the database each time would make our own screens the slow ones. The dedicated
	 * page never uses this: there the list is always recomputed, because that is the
	 * page you open when you want the truth of this exact moment.
	 *
	 * @return array[]
	 */
	public static function steps_cached(): array {
		// ⚠️ La cache porta con se' un'IMPRONTA delle impostazioni. Senza, bastava
		// cambiare le lingue per vedere per cinque minuti un elenco che diceva ancora
		// «scegli le lingue», mentre la riga sopra diceva gia' il contrario: due pezzi
		// dello stesso pannello che si contraddicono. Con l'impronta non serve sperare
		// che qualcuno si ricordi di svuotare la cache.
		$firma = md5( (string) wp_json_encode( Settings::get() ) );
		$c     = get_transient( self::CACHE );
		if ( is_array( $c ) && isset( $c['firma'], $c['passi'] ) && $firma === $c['firma'] ) {
			return (array) $c['passi'];
		}
		try {
			$passi = self::steps();
		} catch ( \Throwable $e ) {
			$passi = array();
		}
		set_transient( self::CACHE, array( 'firma' => $firma, 'passi' => $passi ), 5 * MINUTE_IN_SECONDS );
		return $passi;
	}

	/**
	 * The drawer under the tab bar: open it where you are, without losing the page
	 * you were working on. Printed on every screen of the plugin except the one that
	 * already shows the whole list.
	 */
	public static function drawer(): void {
		// Questa riga si disegna su OGNI schermata del plugin: se qualcosa qui
		// saltasse, non si romperebbe una pagina nostra, si romperebbe
		// l'amministrazione di chi lo usa. Nel dubbio, il cassetto non compare.
		try {
			$passi = self::steps_cached();
		} catch ( \Throwable $e ) {
			return;
		}
		if ( empty( $passi ) ) {
			return;
		}
		$da = 0;
		foreach ( $passi as $p ) {
			if ( 'da-fare' === $p['stato'] ) {
				++$da;
			}
		}
		?>
		<details class="trr-cassetto<?php echo $da > 0 ? ' ha-passi' : ''; ?>" id="trr-cassetto">
			<summary>
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php
				if ( $da > 0 ) {
					echo esc_html(
						sprintf(
							/* translators: %d: how many steps are waiting. */
							_n( '%d thing is waiting for you', '%d things are waiting for you', $da, 'translate-rocket' ),
							$da
						)
					);
				} else {
					esc_html_e( 'Nothing is waiting for you', 'translate-rocket' );
				}
				?>
				<span class="trr-cassetto-apri"><?php esc_html_e( 'see what is left', 'translate-rocket' ); ?></span>
			</summary>
			<div class="trr-cassetto-dentro"><?php self::render( false, $passi ); ?></div>
		</details>
		<script>
		( function () {
			var d = document.getElementById( 'trr-cassetto' );
			if ( ! d ) { return; }
			// Aperto o chiuso resta come l'hai lasciato, anche cambiando schermata.
			try { if ( 'si' === localStorage.getItem( 'trrCassetto' ) ) { d.open = true; } } catch ( e ) {}
			d.addEventListener( 'toggle', function () {
				try { localStorage.setItem( 'trrCassetto', d.open ? 'si' : 'no' ); } catch ( e ) {}
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * The full page behind its own menu entry: the same list, with room around it.
	 */
	public static function page(): void {
		?>
		<div class="wrap trrocket-wrap">
			<?php Admin::header( 'translate-rocket-next' ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'What to do next', 'translate-rocket' ); ?></h1>
			<?php self::render( false ); ?>
			<p class="description" style="margin-top:14px">
				<?php esc_html_e( 'This list is worked out from your site every time you open it, so it is always the truth of this moment. Come back whenever you are not sure what is left.', 'translate-rocket' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * How many steps are waiting for the site owner, for the little number next to
	 * the menu entry. Kept in a transient for five minutes: this runs on every
	 * single admin page, and it must never be the reason wp-admin feels slow.
	 */
	public static function pending(): int {
		$n = get_transient( self::PENDING );
		if ( false !== $n ) {
			return (int) $n;
		}
		$n = 0;
		try {
			foreach ( self::steps_cached() as $p ) {
				if ( 'da-fare' === $p['stato'] ) {
					++$n;
				}
			}
		} catch ( \Throwable $e ) {
			$n = 0;   // nel dubbio nessun numero: meglio niente che un numero sbagliato.
		}
		set_transient( self::PENDING, $n, 5 * MINUTE_IN_SECONDS );
		return (int) $n;
	}

	/**
	 * Forget the count: something changed that could have finished a step.
	 */
	public static function forget(): void {
		// ⚠️ Chiamata a OGNI traduzione salvata: senza questo, una traduzione AI da 2.000
		// frasi faceva migliaia di query in piu' solo per svuotare due volte lo stesso
		// transient. Una volta per richiesta basta.
		static $fatto = false;
		if ( $fatto ) {
			return;
		}
		$fatto = true;
		delete_transient( self::PENDING );
		delete_transient( self::CACHE );
	}

	/**
	 * How many steps are in a given state.
	 *
	 * @param array[] $passi Steps.
	 * @param string  $stato State.
	 */
	private static function count_state( array $passi, string $stato ): int {
		$n = 0;
		foreach ( $passi as $p ) {
			if ( $stato === $p['stato'] ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * The mark in front of a step.
	 *
	 * @param string $stato fatto | da-fare | nota.
	 */
	private static function sign( string $stato ): string {
		if ( 'fatto' === $stato ) {
			return '✓';
		}
		return 'da-fare' === $stato ? '›' : '·';
	}

	/**
	 * One sentence naming the situation the site is in right now.
	 */
	public static function where(): string {
		$s = Settings::get();
		if ( empty( $s['target_languages'] ) ) {
			return __( 'You have not chosen your languages yet. Everything else waits for that.', 'translate-rocket' );
		}
		if ( Coexistence::on() ) {
			return sprintf(
				/* translators: %s: the other translation plugin, e.g. "Polylang". */
				__( '%s is running the languages on your site. TranslateRocket is standing aside: your visitors see exactly what they saw before, and nothing you do here touches the live site until you say so.', 'translate-rocket' ),
				Coexistence::active_names()
			);
		}
		$offline = (array) ( $s['offline_languages'] ?? array() );
		$targets = (array) $s['target_languages'];
		$online  = array_values( array_diff( $targets, $offline ) );
		if ( empty( $online ) ) {
			return __( 'TranslateRocket is in charge of the languages, but none of them is online yet: only you can see them.', 'translate-rocket' );
		}
		return sprintf(
			/* translators: 1: how many languages are online, 2: how many were set up. */
			_n( 'Your site is live in %1$d language out of the %2$d you set up.', 'Your site is live in %1$d languages out of the %2$d you set up.', count( $online ), 'translate-rocket' ),
			count( $online ),
			count( $targets )
		);
	}

	/**
	 * The steps, computed from the site as it is now.
	 *
	 * @return array<int,array{stato:string,titolo:string,testo:string,azione:string,url:string}>
	 */
	public static function steps(): array {
		$s       = Settings::get();
		$targets = (array) ( $s['target_languages'] ?? array() );
		$passi   = array();

		// 1. Le lingue.
		$passi[] = empty( $targets )
			? self::step( 'da-fare', __( 'Choose your languages', 'translate-rocket' ), __( 'Which language your site is written in, and which ones you want it to speak.', 'translate-rocket' ), __( 'Open the setup', 'translate-rocket' ), admin_url( 'admin.php?page=translate-rocket-wizard' ) )
			: self::step( 'fatto', __( 'Your languages', 'translate-rocket' ), self::lingue_testo( $targets ), __( 'Change them', 'translate-rocket' ), admin_url( 'admin.php?page=translate-rocket' ) );
		if ( empty( $targets ) ) {
			return $passi;   // senza lingue il resto non ha senso.
		}

		// 2. L'altro plugin di traduzione, se c'e'.
		$importatore = self::importer_in_uso();
		if ( Coexistence::on() ) {
			$passi[] = self::step(
				'nota',
				sprintf( /* translators: %s: the other plugin. */ __( 'You are running side by side with %s', 'translate-rocket' ), Coexistence::active_names() ),
				__( 'This is on purpose, and nothing is broken: your public site is untouched. Look at the translated pages with Preview. When you are happy, deactivate the other plugin and a button here will put TranslateRocket online.', 'translate-rocket' ),
				__( 'Preview a page', 'translate-rocket' ),
				home_url( '/' )
			);
		}

		// 3. Importare quello che e' gia' tradotto.
		if ( null !== $importatore ) {
			$quante = (int) $importatore->count_available();
			$passi[] = $quante > 0
				? self::step( 'da-fare', sprintf( /* translators: 1: how many, 2: plugin name. */ __( 'Bring in the %1$d translations you already have in %2$s', 'translate-rocket' ), $quante, $importatore->label() ), __( 'They are yours: importing copies them here, and the other plugin keeps its own.', 'translate-rocket' ), __( 'Import them', 'translate-rocket' ), admin_url( 'admin.php?page=translate-rocket-import' ) )
				: self::step( 'fatto', sprintf( /* translators: %s: plugin name. */ __( 'Translations imported from %s', 'translate-rocket' ), $importatore->label() ), __( 'There is nothing left to bring over.', 'translate-rocket' ), '', '' );
		}

		// 4. Tradurre quello che manca.
		$mancano = 0;
		foreach ( $targets as $lang ) {
			$stat     = Strings::language_stats( (string) $lang );
			$mancano += (int) $stat['missing'];
		}
		$passi[] = $mancano > 0
			? self::step( 'da-fare', sprintf( /* translators: %d: how many phrases. */ _n( 'Translate the %d phrase that is still missing', 'Translate the %d phrases that are still missing', $mancano, 'translate-rocket' ), $mancano ), __( 'With your browser, with an AI key, or by hand. You can do a page at a time.', 'translate-rocket' ), __( 'Go to the translations', 'translate-rocket' ), admin_url( 'admin.php?page=translate-rocket-strings' ) )
			: self::step( 'fatto', __( 'Everything is translated', 'translate-rocket' ), __( 'Every phrase found on your site has a translation in every language.', 'translate-rocket' ), '', '' );

		// 5. Il selettore di lingua.
		$sw      = (array) ( $s['switcher'] ?? array() );
		$acceso  = ! isset( $sw['enabled'] ) || ! empty( $sw['enabled'] );
		$passi[] = $acceso
			? self::step( 'fatto', __( 'The language switcher is on', 'translate-rocket' ), __( 'Visitors can change language. You can move it or restyle it whenever you like.', 'translate-rocket' ), __( 'Change how it looks', 'translate-rocket' ), admin_url( 'admin.php?page=translate-rocket-switcher' ) )
			: self::step( 'da-fare', __( 'Put a language switcher on your site', 'translate-rocket' ), __( 'Without it, a visitor has no way to reach the other languages except by guessing the address.', 'translate-rocket' ), __( 'Set it up', 'translate-rocket' ), admin_url( 'admin.php?page=translate-rocket-switcher' ) );

		// 6. Online.
		if ( ! Coexistence::on() ) {
			$offline = array_values( array_intersect( (array) ( $s['offline_languages'] ?? array() ), $targets ) );
			$passi[] = ! empty( $offline )
				? self::step( 'da-fare', sprintf( /* translators: %s: language names. */ __( 'Put %s online', 'translate-rocket' ), self::nomi_lingue( $offline ) ), __( 'Ready and waiting: only you can see these languages at the moment.', 'translate-rocket' ), __( 'Choose what is online', 'translate-rocket' ), admin_url( 'admin.php?page=translate-rocket' ) )
				: self::step( 'fatto', __( 'All your languages are online', 'translate-rocket' ), __( 'Visitors and search engines can reach every one of them.', 'translate-rocket' ), '', '' );
		}

		// 7. Cosa lascia indietro l'altro plugin: pagine, modelli, menu.
		// ⚠️ Da qui in poi si leggono i dati di un plugin che non e' nostro: se cambia
		// forma, si perde questo pezzo dell'elenco, non tutto il pannello.
		try {
		if ( null !== $importatore && $importatore instanceof ProvidesCopies ) {
			$copie = (int) CopyCleanup::open_count( $importatore );
			if ( $copie > 0 ) {
				$passi[] = self::step(
					'da-fare',
					sprintf( /* translators: 1: how many pages, 2: plugin name. */ _n( 'Look over the %1$d page %2$s left behind', 'Look over the %1$d pages %2$s left behind', $copie, 'translate-rocket' ), $copie, $importatore->label() ),
					__( 'One page per language is how the other plugin worked. Each one can go to the Trash, where it stays recoverable and its old address keeps working, or you can keep it. Nothing is ever deleted.', 'translate-rocket' ),
					__( 'Look them over', 'translate-rocket' ),
					admin_url( 'admin.php?page=translate-rocket-import&trr_copies=' . rawurlencode( $importatore->id() ) )
				);
			}
			$menu = BuilderTemplates::menus_per_language( $importatore->label() );
			if ( ! empty( $menu ) ) {
				$passi[] = self::step( 'nota', __( 'Your menus in other languages: nothing to do', 'translate-rocket' ), BuilderTemplates::menus_notice( $importatore->label() ), '', '' );
			}
			$modelli = BuilderTemplates::notice( $importatore->label() );
			if ( '' !== $modelli ) {
				$passi[] = self::step( 'nota', __( 'Your headers and footers per language: nothing to do', 'translate-rocket' ), $modelli, '', '' );
			}
		}
		} catch ( \Throwable $e ) {
			\TranslateRocket\Logger::warning( 'panel', __( 'Could not read what the other translation plugin left behind. The rest of the list is fine.', 'translate-rocket' ), $e->getMessage() );
		}

		return $passi;
	}

	/**
	 * Build one step.
	 */
	private static function step( string $stato, string $titolo, string $testo, string $azione, string $url ): array {
		return array(
			'stato'  => $stato,
			'titolo' => $titolo,
			'testo'  => $testo,
			'azione' => $azione,
			'url'    => $url,
		);
	}

	/**
	 * "From English into Português and Français."
	 *
	 * @param string[] $targets Target language codes.
	 */
	private static function lingue_testo( array $targets ): string {
		$source = (string) ( Settings::get()['source_language'] ?? 'en' );
		return sprintf(
			/* translators: 1: source language, 2: list of target languages. */
			__( 'From %1$s into %2$s.', 'translate-rocket' ),
			Languages::label( $source ),
			self::nomi_lingue( $targets )
		);
	}

	/**
	 * Language names in a readable list.
	 *
	 * @param string[] $codes Language codes.
	 */
	private static function nomi_lingue( array $codes ): string {
		$nomi = array();
		foreach ( $codes as $c ) {
			$nomi[] = Languages::label( (string) $c );
		}
		if ( count( $nomi ) < 2 ) {
			return (string) ( $nomi[0] ?? '' );
		}
		$ultimo = array_pop( $nomi );
		return implode( ', ', $nomi ) . ' ' . __( 'and', 'translate-rocket' ) . ' ' . $ultimo;
	}

	/**
	 * The other translation plugin we can read, if any is installed.
	 */
	private static function importer_in_uso(): ?\TranslateRocket\Importers\ImporterInterface {
		foreach ( Importers::all() as $imp ) {
			try {
				if ( $imp->is_available() ) {
					return $imp;
				}
			} catch ( \Throwable $e ) {
				continue;   // un importatore rotto non deve nascondere gli altri.
			}
		}
		return null;
	}
}
