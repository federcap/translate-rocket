<?php
/**
 * «Translate my site»: one button, the plugin chooses everything else (Simple view).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Languages;
use TranslateRocket\Settings;
use TranslateRocket\Providers\MyMemoryProvider;
use TranslateRocket\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * 5/11/2026 (Federico: «the user arrives, makes one click and translates, stop»; 39 deactivations, most of them after
 * a few minutes with 0 sentences translated: «too many choices, keys, where to start»).
 *
 * One button does, in a row: reads every page of the site (the scan), picks the engine by itself — the owner's key if
 * there is one, else the translator built into Chrome/Edge for the languages it has, else MyMemory (official, free) —
 * translates every language, and puts the languages online. Nothing to choose: the engine menu, the keys, the switches
 * stay under Advanced.
 */
class OneClick {

	const NONCE = 'trrocket_oneclick';

	public function register(): void {
		add_action( 'wp_ajax_trrocket_oneclick_done', array( $this, 'ajax_done' ) );
		add_action( 'wp_ajax_trrocket_oneclick_mm', array( $this, 'ajax_mm' ) );
	}

	/**
	 * The languages to translate into (the site's own left out).
	 *
	 * @return string[]
	 */
	private static function targets(): array {
		$s   = Settings::get();
		$src = (string) ( $s['source_language'] ?? 'en' );
		return array_values( array_filter( array_map( 'strval', (array) ( $s['target_languages'] ?? array() ) ), static fn( $l ) => '' !== $l && $l !== $src ) );
	}

	/**
	 * The card. Called on the Languages screen in the Simple view, when there are languages to translate into.
	 */
	public static function render(): void {
		$targets = self::targets();
		if ( empty( $targets ) ) {
			return;
		}
		$s       = Settings::get();
		$source  = (string) ( $s['source_language'] ?? 'en' );
		$offline = array_map( 'strval', (array) ( $s['offline_languages'] ?? array() ) );
		$missing = 0;
		$langs   = array();
		foreach ( $targets as $l ) {
			$m        = \TranslateRocket\Strings::untranslated_count( $l, true );
			$missing += $m;
			$langs[]  = array(
				'code'    => $l,
				'label'   => Languages::label( $l ),
				'bcp'     => BrowserEngine::bcp47( $l ),
				'missing' => $m,
				'online'  => ! in_array( $l, $offline, true ),
				'home'    => \TranslateRocket\Plugin::instance()->router()->home_for_language( $l ),
			);
		}
		$names = implode( ', ', wp_list_pluck( $langs, 'label' ) );
		?>
		<div class="trrocket-card trr-oneclick" id="trr-oneclick">
			<?php // 6/11/2026: no second «Translate your site» here, the page title above already says it ?>
			<p class="trr-oneclick-langs">
				<?php
				/* translators: %s: list of languages. */
				printf( esc_html__( 'Into: %s', 'translate-rocket' ), '<strong>' . esc_html( $names ) . '</strong>' );
				?>
				· <a href="#trr-languages"><?php esc_html_e( 'change', 'translate-rocket' ); ?></a>
			</p>
			<p>
				<button type="button" class="button button-primary button-hero" id="trr-oneclick-go" disabled>🚀 <?php esc_html_e( 'Translate my site', 'translate-rocket' ); ?></button>
			</p>
			<p class="description trr-oneclick-free"><?php esc_html_e( 'Free. No key, no account. Keep this tab open while it works.', 'translate-rocket' ); ?></p>
			<?php
			// 5/11/2026 (Federico): MyMemory with the owner's e-mail from the start, ten times the free day — only if they tick
			// it (never pre-ticked). one-click.js shows it only when MyMemory is the engine that will work.
			$me = sanitize_email( (string) wp_get_current_user()->user_email );
			if ( '' !== $me && '' === MyMemoryProvider::email() && empty( Registry::ordered() ) ) :
				?>
				<p class="trr-oneclick-consent" id="trr-oneclick-consent" hidden>
					<label><input type="checkbox" id="trr-oneclick-mm-ok"><span>
					<?php
					/* translators: %s: the administrator's e-mail address. */
					printf( esc_html__( 'Translate ten times more each day: send my e-mail (%s) to MyMemory with each sentence.', 'translate-rocket' ), '<strong>' . esc_html( $me ) . '</strong>' );
					?>
					</span></label>
					<span class="description"><?php esc_html_e( 'Optional. MyMemory (Translated.net) allows about 50,000 characters a day instead of 5,000 when it knows who is asking. Nothing else is sent, and you can remove it here at any time.', 'translate-rocket' ); ?> <a href="https://mymemory.translated.net/terms-and-conditions" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms', 'translate-rocket' ); ?></a></span>
				</p>
			<?php elseif ( '' !== MyMemoryProvider::email() ) : ?>
				<p class="description trr-oneclick-mm-on" id="trr-oneclick-mm-on">
					<?php
					/* translators: %s: e-mail address. */
					printf( esc_html__( 'MyMemory knows your e-mail (%s): about 50,000 characters a day.', 'translate-rocket' ), esc_html( MyMemoryProvider::email() ) );
					?>
					<button type="button" class="button-link" id="trr-oneclick-mm-off"><?php esc_html_e( 'Remove it', 'translate-rocket' ); ?></button>
				</p>
			<?php endif; ?>
			<div class="trr-oneclick-run" id="trr-oneclick-run" hidden>
				<div class="trr-prog"><div class="trr-prog-bar"><div class="trr-prog-fill" id="trr-oneclick-fill" style="width:0"></div></div></div>
				<p id="trr-oneclick-step" role="status" aria-live="polite"></p>
				<p class="description" id="trr-oneclick-engine"></p>
			</div>
			<div class="trr-oneclick-end" id="trr-oneclick-end" hidden></div>
		</div>
		<?php
		$scan = Admin::scan_data();
		wp_enqueue_script( 'trrocket-oneclick', TRROCKET_URL . 'assets/js/one-click.js', array(), \TranslateRocket\Plugin::asset_ver( 'assets/js/one-click.js' ), true );
		wp_localize_script(
			'trrocket-oneclick',
			'TRRocketOneClick',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'stepNonce' => wp_create_nonce( 'trrocket_auto_translate' ),
				'brNonce'   => wp_create_nonce( 'trrocket_browser' ),
				'scan'      => $scan,
				'source'    => BrowserEngine::bcp47( $source ),
				'langs'     => $langs,
				'missing'   => $missing,
				// The owner's own key (or Labs engines in the chain): it stays the engine, nothing changes for them.
				'hasKey'    => ! empty( Registry::ordered() ),
				'free'      => MyMemoryProvider::ID,
				'freeLeft'  => ! MyMemoryProvider::used_up(),
				// 5/11/2026: when MyMemory's free day runs out, one click lets it use the owner's e-mail (ten times more).
				'mmEmail'   => '' !== MyMemoryProvider::email(),
				'myEmail'   => (string) wp_get_current_user()->user_email,
				'i18n'      => array(
					'reading'    => __( 'Reading your pages… %1$s of %2$s', 'translate-rocket' ),
					/* translators: 1: language, 2: done, 3: total. */
					'translating' => __( '%1$s: %2$s of %3$s sentences', 'translate-rocket' ),
					'preparing'  => __( 'Getting the translator ready…', 'translate-rocket' ),
					/* translators: %s: percent. */
					'download'   => __( 'Downloading the language model, once: %s', 'translate-rocket' ),
					'withKey'    => __( 'Translating with your AI key.', 'translate-rocket' ),
					'withBrowser' => __( 'Translating with the translator built into your browser — free, on this computer.', 'translate-rocket' ),
					'withFree'   => __( 'Translating with MyMemory — free, about 5,000 characters a day.', 'translate-rocket' ),
					'withFreeMail' => __( 'Translating with MyMemory — free, about 50,000 characters a day with your e-mail.', 'translate-rocket' ),
					'finishing'  => __( 'Putting the languages online…', 'translate-rocket' ),
					/* translators: %s: list of languages. */
					'done'       => __( 'Done! Your site is now in %s.', 'translate-rocket' ),
					'see'        => __( 'See it', 'translate-rocket' ),
					/* translators: %s: number of sentences. */
					'partial'    => __( '%s sentences are still waiting. Press the button again tomorrow to finish them — or open this page in Chrome or Edge on a computer, which translates without a daily limit.', 'translate-rocket' ),
					'limit'      => __( 'The free daily limit is reached for today.', 'translate-rocket' ),
					'nothing'    => __( 'Nothing to translate yet: no text found on your pages.', 'translate-rocket' ),
					/* translators: %s: error message. */
					'error'      => __( 'Something went wrong: %s. Press the button again to continue where it stopped.', 'translate-rocket' ),
					/* translators: %s: e-mail address. */
					'mmAsk'      => __( 'Get ten times more for free: let MyMemory know my e-mail (%s)', 'translate-rocket' ),
					'mmWhy'      => __( 'MyMemory allows about 50,000 characters a day instead of 5,000 when it knows who is asking. Only the address is sent, with each sentence; you can remove it from this card at any time.', 'translate-rocket' ),
					'mmOff'      => __( 'Removed: MyMemory no longer receives your e-mail.', 'translate-rocket' ),
					'mmOk'       => __( 'Done. Press «Translate my site» again to continue.', 'translate-rocket' ),
					'leave'      => __( 'The translation is still running. Leave anyway?', 'translate-rocket' ),
				),
			)
		);
	}

	/**
	 * The owner agreed (one click) to send their e-mail to MyMemory: ten times the free day.
	 */
	public function ajax_mm(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}
		// «Remove it»: the address is no longer sent (the day goes back to the smaller limit).
		if ( ! empty( $_POST['off'] ) ) {
			delete_option( MyMemoryProvider::EMAIL );
			wp_send_json_success( array( 'email' => '' ) );
		}
		$e = sanitize_email( (string) wp_get_current_user()->user_email );
		if ( '' === $e ) {
			wp_send_json_error( array() );
		}
		update_option( MyMemoryProvider::EMAIL, $e, false );
		// the day starts again with the larger limit (MyMemory counts by address and e-mail)
		delete_option( MyMemoryProvider::TODAY );
		wp_send_json_success( array( 'email' => $e ) );
	}

	/**
	 * The last step: the languages go online (they were added offline), the caches are emptied.
	 */
	public function ajax_done(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'You are not allowed to do this.', 'translate-rocket' ) ), 403 );
		}
		$s       = Settings::get();
		$targets = self::targets();
		$s['offline_languages'] = array_values( array_diff( array_map( 'strval', (array) ( $s['offline_languages'] ?? array() ) ), $targets ) );
		Settings::update( $s );
		\TranslateRocket\Cache::flush();
		$out = array();
		foreach ( $targets as $l ) {
			$out[] = array(
				'label'   => Languages::label( $l ),
				'home'    => \TranslateRocket\Plugin::instance()->router()->home_for_language( $l ),
				'missing' => \TranslateRocket\Strings::untranslated_count( $l ),
			);
		}
		wp_send_json_success( array( 'langs' => $out ) );
	}
}
