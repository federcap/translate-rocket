<?php
/**
 * Translating with the translator built into the administrator's browser.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Languages;
use TranslateRocket\Strings;
use TranslateRocket\Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Chrome and Edge ship a translator that runs on the device itself, exposed to
 * pages as the standard Translator API. This class lets the bulk screen drive
 * it: the browser does the translating, PHP only hands out the strings that are
 * still missing and stores what comes back.
 *
 * Why this exists: until now the plugin could not translate anything at all
 * until the site owner had gone off to a third party, created an account and
 * pasted an API key back. That is where most people stopped. This removes the
 * step entirely — install, pick languages, translate — at no cost, with the
 * text never leaving the computer.
 *
 * It does not replace the API providers. It is desktop-only, it is slower
 * because it translates one phrase at a time, and its quality sits below DeepL
 * and the AI models. It is the way in, not the way up.
 */
class BrowserEngine {

	/**
	 * How many texts one round trip hands to the browser. Small enough that a
	 * run shows progress and can be stopped, big enough not to chat.
	 */
	const BATCH = 60;

	/**
	 * Wire up the two endpoints the browser talks to.
	 */
	public function register(): void {
		add_action( 'wp_ajax_trrocket_browser_pending', array( $this, 'ajax_pending' ) );
		add_action( 'wp_ajax_trrocket_browser_store', array( $this, 'ajax_store' ) );
	}

	/**
	 * Both endpoints are administrator-only and nonce-checked. Dies on failure.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'translate-rocket' ) ), 403 );
		}
		check_ajax_referer( 'trrocket_browser', 'nonce' );
	}

	/**
	 * Hand the browser the next batch of strings that still need translating.
	 */
	public function ajax_pending(): void {
		$this->guard();

		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		if ( '' === $lang ) {
			wp_send_json_error( array( 'message' => __( 'No language given.', 'translate-rocket' ) ), 400 );
		}

		$pending = Translator::pending_for_browser( $lang, '', self::BATCH );

		wp_send_json_success(
			array(
				'items'   => $pending['items'],
				'reused'  => (int) $pending['reused'],
				'missing' => Strings::untranslated_count( $lang ),
			)
		);
	}

	/**
	 * Take back what the browser translated and store it.
	 */
	public function ajax_store(): void {
		$this->guard();

		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$raw  = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		if ( '' === $lang || ! is_string( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to store.', 'translate-rocket' ) ), 400 );
		}

		$items = json_decode( $raw, true );
		if ( ! is_array( $items ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not read the translations.', 'translate-rocket' ) ), 400 );
		}

		// Translations are content, not markup: strip tags but keep the text as
		// typed. Translator::store_browser() then checks every id against the
		// text it claims to belong to before writing anything.
		$clean = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$clean[] = array(
				'text'        => (string) ( $item['text'] ?? '' ),
				'translation' => sanitize_textarea_field( (string) ( $item['translation'] ?? '' ) ),
				'ids'         => array_map( 'intval', (array) ( $item['ids'] ?? array() ) ),
			);
		}

		$res = Translator::store_browser( $lang, $clean );
		\TranslateRocket\Cache::flush();

		wp_send_json_success(
			array(
				'saved'   => (int) $res['saved'],
				'skipped' => (int) $res['skipped'],
				'missing' => Strings::untranslated_count( $lang ),
			)
		);
	}

	/**
	 * Our language codes as the Translator API wants them (BCP 47).
	 *
	 * Ours are already close — "it", "pt-br", "zh-tw" — but the region wants
	 * upper case, and Chinese is asked for by script rather than by region.
	 *
	 * @param string $code Plugin language code.
	 */
	public static function bcp47( string $code ): string {
		$code = strtolower( trim( $code ) );
		$special = array(
			'zh'    => 'zh-Hans',
			'zh-cn' => 'zh-Hans',
			'zh-tw' => 'zh-Hant',
			'zh-hk' => 'zh-Hant',
		);
		if ( isset( $special[ $code ] ) ) {
			return $special[ $code ];
		}
		$parts = explode( '-', $code );
		if ( isset( $parts[1] ) ) {
			return $parts[0] . '-' . strtoupper( $parts[1] );
		}
		return $parts[0];
	}

	/**
	 * The button and its progress area, for the bulk screen.
	 *
	 * Printed for everyone; the script hides it again when the browser turns out
	 * not to have a translator, because that is the only place the answer is
	 * known. Feature detection belongs in the browser, not in a user-agent
	 * guess on the server.
	 *
	 * @param string $lang       Target language code.
	 * @param string $source     Source language code.
	 * @param int    $missing    Strings still missing.
	 * @param bool   $no_api_key Whether the site has no provider configured.
	 */
	public static function render_panel( string $lang, string $source, int $missing, bool $no_api_key ): void {
		?>
		<div id="trr-browser-engine" hidden style="margin:10px 0;padding:14px 16px;border:1px solid #dcdcde;border-left:4px solid #2271b1;background:#fff;max-width:820px">
			<p style="margin:0 0 6px;font-weight:600">
				<span id="trr-browser-title"><?php esc_html_e( 'Translate with your browser — no API key', 'translate-rocket' ); ?></span>
				<span id="trr-browser-title-alt" hidden><?php esc_html_e( 'Translate without an API key', 'translate-rocket' ); ?></span>
			</p>
			<p class="description" style="margin:0 0 10px" id="trr-browser-intro">
				<?php
				esc_html_e( 'Chrome and Edge on a computer can translate on the device itself. It costs nothing, needs no account, and the text never leaves this machine. Quality is below DeepL and the AI models, and it works through one phrase at a time — so it is the quickest way to get a translated site today, not the best one you can have.', 'translate-rocket' );
				?>
			</p>
			<p style="margin:0 0 8px" id="trr-browser-actions">
				<button type="button" class="button button-primary" id="trr-browser-go" <?php disabled( 0 === $missing ); ?>>
					<?php
					printf(
						/* translators: %d: number of missing strings. */
						esc_html__( 'Translate %d missing in this browser', 'translate-rocket' ),
						(int) $missing
					);
					?>
				</button>
				<?php // Not the hidden attribute: WordPress gives .button display:inline-block, and an author display rule beats the browser's [hidden]. ?>
				<button type="button" class="button" id="trr-browser-stop" style="display:none"><?php esc_html_e( 'Stop', 'translate-rocket' ); ?></button>
			</p>
			<p id="trr-browser-status" style="margin:0;min-height:1.4em" aria-live="polite"></p>
			<div id="trr-browser-fallback" hidden>
				<p class="description" style="margin:0 0 10px">
					<?php esc_html_e( 'Your browser has no translator of its own — Chrome and Edge on a computer do. You can still work without a key: copy the missing strings out, run them through any translator you like, and paste the result back.', 'translate-rocket' ); ?>
				</p>
				<p style="margin:0">
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'translate-rocket', 'lang' => $lang ), admin_url( 'admin.php' ) ) ); ?>">
						<?php esc_html_e( 'Open the copy &amp; paste tool', 'translate-rocket' ); ?>
					</a>
				</p>
			</div>
			<?php if ( $no_api_key ) : ?>
				<p class="description" style="margin:8px 0 0">
					<?php esc_html_e( 'You can add an API provider later for better quality — what you translate here is kept and never translated again.', 'translate-rocket' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php

		wp_enqueue_script(
			'trrocket-browser-engine',
			TRROCKET_URL . 'assets/js/browser-engine.js',
			array(),
			\TranslateRocket\Plugin::asset_ver( 'assets/js/browser-engine.js' ),
			true
		);
		wp_localize_script(
			'trrocket-browser-engine',
			'TRRocketBrowser',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'trrocket_browser' ),
				'lang'   => $lang,
				'source' => self::bcp47( $source ),
				'target' => self::bcp47( $lang ),
				'label'  => Languages::label( $lang ),
				'i18n'   => array(
					/* translators: %s: language name. */
					'unsupported' => sprintf( __( 'Your browser has no built-in translator for %s.', 'translate-rocket' ), Languages::label( $lang ) ),
					'insecure'    => __( 'The translator built into Chrome and Edge only works over a secure connection. Your admin is on plain http, so it cannot be used here.', 'translate-rocket' ),
					// Sul telefono il pulsante resta VISIBILE ma spento: se sparisce, chi
					// amministra dal telefono non sa nemmeno che la funzione esiste.
					'phone'       => __( 'Translating in the browser needs Chrome or Edge on a computer: on phones and tablets the built-in translator does not exist. Open this page on a computer, or use the copy-and-paste route below, which works here too.', 'translate-rocket' ),
					'downloading' => __( 'Downloading the language model — this happens once…', 'translate-rocket' ),
					'preparing'   => __( 'Preparing…', 'translate-rocket' ),
					/* translators: 1: strings done, 2: strings total. */
					'progress'    => __( 'Translating %1$d of %2$d…', 'translate-rocket' ),
					/* translators: %d: number of strings translated. */
					'done'        => __( 'Done — %d strings translated in this browser.', 'translate-rocket' ),
					'nothing'     => __( 'Nothing left to translate in this language.', 'translate-rocket' ),
					'stopped'     => __( 'Stopped.', 'translate-rocket' ),
					'failed'      => __( 'The browser translator stopped with an error.', 'translate-rocket' ),
					'reload'      => __( 'Reload the page to see the new totals.', 'translate-rocket' ),
					'refreshing'  => __( 'Updating the totals…', 'translate-rocket' ),
				),
			)
		);
	}
}
