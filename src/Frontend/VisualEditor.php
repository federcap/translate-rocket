<?php
/**
 * Front-end visual editor: translate by clicking text on the live page.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Settings;
use TranslateRocket\Strings;
use TranslateRocket\Languages;
use TranslateRocket\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * When an authorised user adds ?trr-edit to a translated page, every text becomes
 * clickable and editable in place, with the result shown live. The engine wraps
 * each translatable text node with its source (data-trr-src) so this can map a
 * clicked element back to its string.
 */
class VisualEditor {

	const PARAM = 'trr-edit';
	const NONCE = 'trrocket_ve';

	/**
	 * Whether the current front-end request is in visual-edit mode.
	 */
	public static function is_editing(): bool {
		static $val = null;
		if ( null !== $val ) {
			return $val;
		}
		$val = ! is_admin()
			&& isset( $_GET[ self::PARAM ] ) // phpcs:ignore WordPress.Security.NonceVerification
			&& current_user_can( 'manage_options' );
		return $val;
	}

	/**
	 * Hook into WordPress.
	 */
	public function boot(): void {
		add_action( 'wp_ajax_trrocket_ve_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_trrocket_ve_save_block', array( $this, 'ajax_save_block' ) );
		add_action( 'wp_ajax_trrocket_ve_ai', array( $this, 'ajax_ai' ) );
		add_action( 'wp_ajax_trrocket_ve_gt', array( $this, 'ajax_gt' ) );
		add_action( 'wp_ajax_trrocket_ve_visibility', array( $this, 'ajax_visibility' ) );
		add_action( 'wp_ajax_trrocket_ve_seo', array( $this, 'ajax_seo' ) );
		add_action( 'wp_ajax_trrocket_ve_copy_create', array( $this, 'ajax_copy_create' ) );
		add_action( 'wp_ajax_trrocket_ve_copy_pause', array( $this, 'ajax_copy_pause' ) );
		add_action( 'wp_ajax_trrocket_ve_copy_delete', array( $this, 'ajax_copy_delete' ) );

		if ( is_admin() ) {
			return;
		}
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		if ( ! self::is_editing() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', function () { $this->in_user_locale( array( $this, 'assets' ) ); } );
		add_action( 'wp_footer', function () { $this->in_user_locale( array( $this, 'toolbar' ) ); } );
	}

	/**
	 * Run a callback with the plugin's interface in the user's own language.
	 *
	 * On a translated page the whole request runs in that page's locale, so the
	 * theme and other plugins come out translated — which is the point. The
	 * editor toolbar is not part of the page though: it belongs to the person
	 * editing. Someone working in Italian should keep an Italian toolbar while
	 * translating into Dutch. Falls back to English on its own when there is no
	 * catalogue for the user's language, which is what gettext does anyway.
	 *
	 * @param callable $fn What to render.
	 */
	private function in_user_locale( callable $fn ): void {
		global $l10n;
		$dominio           = 'translate-rocket';
		$prima             = isset( $l10n[ $dominio ] ) ? $l10n[ $dominio ] : null;
		$suspended         = Locale::$suspended;
		Locale::$suspended = true;

		// Read the person's own setting and the site option directly. Neither
		// get_user_locale() nor get_locale() can be trusted here: by the time the
		// toolbar renders, the global locale already holds the page's language, and
		// get_locale() returns it whatever our filter does. Order: the language
		// this person chose, then the site's, then English.
		$uid    = get_current_user_id();
		$wanted = $uid ? (string) get_user_meta( $uid, 'locale', true ) : '';
		if ( '' === $wanted ) {
			$wanted = (string) get_option( 'WPLANG' );
		}
		if ( '' === $wanted ) {
			$wanted = 'en_US';
		}
		$switched = switch_to_locale( $wanted );

		// The catalogue is swapped by hand rather than left to switch_to_locale():
		// that returns false whenever the site has no WordPress translations for
		// the locale at all — a Japanese admin on a German-only site keeps German —
		// and the just-in-time loader would then pull the page's language back in.
		unset( $l10n[ $dominio ] );
		$caricato = false;
		foreach ( array(
			WP_LANG_DIR . '/plugins/' . $dominio . '-' . $wanted . '.mo',
			TRROCKET_PATH . 'languages/' . $dominio . '-' . $wanted . '.mo',
		) as $mo ) {
			if ( is_readable( $mo ) ) {
				$caricato = load_textdomain( $dominio, $mo, $wanted );
				break;
			}
		}
		if ( ! $caricato ) {
			// Nothing for this person's language: show the original English.
			$l10n[ $dominio ] = new \NOOP_Translations();
		}

		try {
			$fn();
		} finally {
			if ( null !== $prima ) {
				$l10n[ $dominio ] = $prima;
			} else {
				unset( $l10n[ $dominio ] );
			}
			if ( $switched ) {
				restore_previous_locale();
			}
			Locale::$suspended = $suspended;
		}
	}

	/**
	 * Add a "Translate visually" launcher to the admin bar, with one entry per
	 * secondary language pointing at this page in that language, in edit mode.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function admin_bar( $bar ): void {
		if ( ! current_user_can( 'manage_options' ) || is_admin() ) {
			return;
		}
		$router    = Plugin::instance()->router();
		$secondary = $router->secondary_languages();
		if ( empty( $secondary ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'trrocket-ve',
				'title' => '🚀 ' . __( 'Translate visually', 'translate-rocket' ),
				'href'  => add_query_arg( self::PARAM, '1', $router->url_for_language( $secondary[0] ) ),
			)
		);
		foreach ( $secondary as $code ) {
			$bar->add_node(
				array(
					'parent' => 'trrocket-ve',
					'id'     => 'trrocket-ve-' . $code,
					'title'  => Languages::label( $code ),
					'href'   => add_query_arg( self::PARAM, '1', $router->url_for_language( $code ) ),
				)
			);
		}
	}

	/**
	 * Editor assets + config.
	 */
	public function assets(): void {
		// Il selettore di immagini di WordPress: serve per scegliere una foto
		// diversa per una lingua. Si accoda solo in modalita' editor, che gia'
		// e' riservata a chi amministra: i visitatori non se lo portano dietro.
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_style(
			'trrocket-visual-editor',
			TRROCKET_URL . 'assets/css/visual-editor.css',
			array(),
			Plugin::asset_ver( 'assets/css/visual-editor.css' )
		);
		wp_enqueue_script(
			'trrocket-visual-editor',
			TRROCKET_URL . 'assets/js/visual-editor.js',
			array(),
			Plugin::asset_ver( 'assets/js/visual-editor.js' ),
			true
		);
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		wp_localize_script(
			'trrocket-visual-editor',
			'trrocketVE',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'lang'     => $lang,
				'label'    => Languages::label( $lang ),
				'editable' => ! $router->is_default( $lang ), // source language can't be translated into itself.
				'srclang'  => (string) ( Settings::get()['source_language'] ?? 'en' ),
				'srcFlag'  => \TranslateRocket\Flags::html( $router->default_language(), Languages::flag( $router->default_language() ) ),
				'curFlag'  => \TranslateRocket\Flags::html( $lang, Languages::flag( $lang ) ),
				'autoG'    => \TranslateRocket\GoogleFree::auto_enabled(),
				'aiReady'  => (bool) ( ( $trr_ap = Registry::active() ) && $trr_ap->is_configured() ),
				// Per il traduttore dentro Chrome ed Edge, che lavora sul dispositivo e
				// non chiede nessuna chiave. Se questo browser ce l'abbia si sa solo a
				// video: qui passiamo solo i codici lingua nel formato che vuole lui.
				'srcBcp'   => \TranslateRocket\Admin\BrowserEngine::bcp47( (string) ( Settings::get()['source_language'] ?? 'en' ) ),
				'dstBcp'   => \TranslateRocket\Admin\BrowserEngine::bcp47( $lang ),
				// Per il prompt da incollare in ChatGPT o Gemini: le stesse cose che l'AI
				// integrata riceve gia' (lo stile scritto dal proprietario) piu' le parole da
				// non tradurre e il glossario di questa lingua. L'editor si carica solo per
				// chi ha manage_options, quindi non escono dalle mani dell'amministratore.
				// Nomi inglesi: il prompt e' in inglese, e 'into Italian' si legge meglio di 'into Italiano'.
				'pSrc'     => Languages::english_label( (string) ( Settings::get()['source_language'] ?? 'en' ) ),
				'pDst'     => Languages::english_label( $lang ),
				'pGuide'   => mb_substr( trim( (string) ( Settings::get()['ai_guidance'] ?? '' ) ), 0, \TranslateRocket\Providers\LlmProvider::GUIDANCE_MAX ),
				'pKeep'    => array_slice( array_values( array_filter( array_map( 'strval', (array) ( Settings::get()['exclude_strings'] ?? array() ) ), 'strlen' ) ), 0, 60 ),
				'pGloss'   => array_slice( Settings::glossary( $lang ), 0, 60, true ),
				'i18n'    => array(
					'editing' => __( 'Editing', 'translate-rocket' ),
					'modeBlocks'    => __( 'Blocks', 'translate-rocket' ),
					'modeBlocksTip' => __( 'Show formatting as numbered [1] [2] markers — the simplest view.', 'translate-rocket' ),
					'modeHtml'      => __( 'HTML', 'translate-rocket' ),
					'modeHtmlTip'   => __( 'Edit the raw HTML for full control — you see the real <strong>, <a>… tags.', 'translate-rocket' ),
					'htmlHint'      => __( '⚠ Keep the HTML tags (<strong>, <a>…) intact so the formatting and links survive.', 'translate-rocket' ),
					'bulkNothing'   => __( 'Everything on this page is already translated.', 'translate-rocket' ),
					/* translators: %d: number of strings just translated. */
					'bulkDone'      => __( '%d translated', 'translate-rocket' ),
					'bulkCountWarn' => __( '⚠ The number of lines does not match the source — check the result.', 'translate-rocket' ),
					'bulkCopied'    => __( 'Copied!', 'translate-rocket' ),
					'bulkBtn'        => __( 'Translate page', 'translate-rocket' ),
					'bulkHead'       => __( 'Translate the whole page', 'translate-rocket' ),
					'bulkDesc'       => __( 'Translate every text on this page at once — automatically, or by pasting into any translator.', 'translate-rocket' ),
					'bulkHtml'       => __( 'Show HTML tags (bold, links…) instead of [1] [2]', 'translate-rocket' ),
					'bulkAi'         => __( 'Auto-translate everything with AI', 'translate-rocket' ),
					'bulkGt'         => __( 'Auto-translate everything with Google', 'translate-rocket' ),
					'bulkNoProvider' => __( 'Add an AI key (or enable free Google) in TranslateRocket settings to auto-translate. Meanwhile, use copy-paste below.', 'translate-rocket' ),
					'bulkBrowser'    => __( 'Translate everything in this browser — no key', 'translate-rocket' ),
					'bulkBrowserTip' => __( 'Chrome and Edge on a computer can translate on the device itself: it costs nothing and the text never leaves your machine. Quality is below the AI models.', 'translate-rocket' ),
					'bulkBrowserDl'  => __( 'Downloading the language model — this happens once…', 'translate-rocket' ),
					'bulkBrowserErr' => __( 'The browser translator stopped with an error.', 'translate-rocket' ),
					// Sul telefono il pulsante si mostra SPENTO invece di sparire: se sparisce,
					// nessuno sa che la funzione esiste. Queste due righe spiegano dove usarla.
					'soloPcTit'      => __( 'This one needs a computer', 'translate-rocket' ),
					'soloPcTxt'      => __( 'Chrome and Edge carry a translator that runs on the device itself, but only on a desktop computer — on phones and tablets it does not exist. Open this page on a computer to translate with no key. Everything else here works on a phone as usual.', 'translate-rocket' ),
					'soloPcOk'       => __( 'Got it', 'translate-rocket' ),
					// Un'immagine per lingua: una locandina, una foto col testo
					// dentro, un banner. Tradurre la didascalia non basta se
					// l'immagine stessa parla un'altra lingua.
					'imgTit'         => __( 'Image for this language', 'translate-rocket' ),
					'imgTxt'         => __( 'Show a different image to readers of this language. The original is untouched, and every other language keeps it.', 'translate-rocket' ),
					'imgPick'        => __( 'Choose image', 'translate-rocket' ),
					'imgPickTit'     => __( 'Choose the image for this language', 'translate-rocket' ),
					'imgPickUse'     => __( 'Use this image', 'translate-rocket' ),
					'imgReset'       => __( 'Back to the original', 'translate-rocket' ),
					'imgOriginal'    => __( 'Original', 'translate-rocket' ),
					'imgNow'         => __( 'Shown in this language', 'translate-rocket' ),
					'imgNoMedia'     => __( 'The media library did not load, so paste an image address instead.', 'translate-rocket' ),
					'bulkPasteHead'  => __( 'Or: copy → translate → paste back', 'translate-rocket' ),
					'bulkStep1'      => __( '1. Copy the source text', 'translate-rocket' ),
					'bulkStep2'      => __( '2. Paste it back, keeping the 1. 2. 3. numbers on each line', 'translate-rocket' ),
					'bulkCopy'       => __( 'Copy', 'translate-rocket' ),
					'bulkAiHead'     => __( 'Or translate it in ChatGPT or Gemini', 'translate-rocket' ),
					'bulkAiCopy'     => __( 'Copy with a translation prompt', 'translate-rocket' ),
					'bulkAiCopied'   => __( 'Copied — paste it into the chat', 'translate-rocket' ),
					'bulkAiInfoBtn'  => __( 'How does this work?', 'translate-rocket' ),
					'bulkAiInfo'     => __( 'The button copies the text of this page together with a translation prompt written for your site: your two languages, the words you never translate, your glossary and your house style. The prompt asks the AI to give every numbered line back with the same number and to leave the [1] [2] markers exactly where they are, which is what lets the translation be put back in place. Paste it into the chat, copy the reply with the copy button of its code block, and paste it in box 2 below.', 'translate-rocket' ),
					'bulkPastePh'    => __( 'Paste the translated text here…', 'translate-rocket' ),
					'bulkApply'      => __( 'Apply translation', 'translate-rocket' ),
					'bulkRun'       => __( 'Translating…', 'translate-rocket' ),
					'source'  => __( 'Original', 'translate-rocket' ),
					'trans'   => __( 'Translation', 'translate-rocket' ),
					'save'    => __( 'Save', 'translate-rocket' ),
					'savenext' => __( 'Save & next', 'translate-rocket' ),
					'ai'      => __( 'Translate with AI', 'translate-rocket' ),
					'gt'      => __( 'Google Translate', 'translate-rocket' ),
					'br'      => __( 'Translate in this browser', 'translate-rocket' ),
					'prev'    => __( 'Previous', 'translate-rocket' ),
					'next'    => __( 'Next', 'translate-rocket' ),
					'selall'  => __( 'Select all', 'translate-rocket' ),
				'back'    => __( 'Back', 'translate-rocket' ),
					'onlytodo'    => __( 'Skip translated texts', 'translate-rocket' ),
					'onlytodoTip' => __( 'With the ‹ › arrows, skip texts that already have a translation and stop only on the ones still to translate.', 'translate-rocket' ),
					'attrLabels' => array(
						'alt'         => __( 'Image alt text', 'translate-rocket' ),
						'title'       => __( 'Title', 'translate-rocket' ),
						'placeholder' => __( 'Placeholder', 'translate-rocket' ),
						'aria-label'  => __( 'ARIA label', 'translate-rocket' ),
					),
					// L'elenco SEO dentro il pannello: immagini e attributi della pagina.
					'seoNoAlt'   => __( '(no alt text)', 'translate-rocket' ),
					'seoNoText'  => __( '(empty)', 'translate-rocket' ),
					'seoEditAlt' => __( 'Alt text', 'translate-rocket' ),
					'seoEditImg' => __( 'Change the photo', 'translate-rocket' ),
					'seoEdit'    => __( 'Edit', 'translate-rocket' ),
					'seoDone'    => __( 'translated', 'translate-rocket' ),
					'seoTodo'    => __( 'to translate', 'translate-rocket' ),
					'close'   => __( 'Close', 'translate-rocket' ),
					'lock'    => __( 'Lock the window here (it stays put while you navigate)', 'translate-rocket' ),
					'unlock'  => __( 'Unlock — follow the selected text again', 'translate-rocket' ),
					'restore'      => __( 'Restore original', 'translate-rocket' ),
				'restoreTip'   => __( 'Put the original text back (e.g. if the [1] [2] markers got broken), then translate again.', 'translate-rocket' ),
				'blockHint'    => __( 'Markers like [1] [2] are formatting (bold, links). Keep them where they are.', 'translate-rocket' ),
					'blockMarkers' => __( '⚠ Keep all the [1] [2] markers so the bold/links can be restored.', 'translate-rocket' ),
					'allDone' => __( 'Already translated', 'translate-rocket' ),
					'saved'   => __( 'Saved', 'translate-rocket' ),
					// Quando un salvataggio non va: un simbolo muto non dice se il
					// sito ha risposto male o non ha risposto affatto.
					'saveFailed' => __( '⚠ Not saved — your site refused the request. Your text is still here: try again.', 'translate-rocket' ),
					'noAnswer'   => __( '⚠ Your site did not answer. The text is still here — check your connection, then try again.', 'translate-rocket' ),
					'done'    => __( 'Done', 'translate-rocket' ),
					'hint'    => __( 'Click any text to translate it', 'translate-rocket' ),
					'working' => __( 'Working…', 'translate-rocket' ),
					'copyRevertConfirm' => __( 'Switch this page back to runtime translation? Your independent copy is kept (paused), not deleted — you can re-create it later to get your edits back.', 'translate-rocket' ),
					'copyDeleteConfirm' => __( 'Delete this independent copy for good? Its edits will be lost and the page goes back to runtime translation.', 'translate-rocket' ),
				),
			)
		);
	}

	/**
	 * The top toolbar shown in edit mode.
	 */
	public function toolbar(): void {
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		$exit   = remove_query_arg( self::PARAM );

		// On the source language there is nothing to translate (it would be
		// source→source). Show a clear message + buttons to the target languages.
		if ( $router->is_default( $lang ) ) {
			$picks = '';
			foreach ( $router->secondary_languages() as $code ) {
				$picks .= '<a class="trrocket-ve-pick" href="' . esc_url( add_query_arg( self::PARAM, '1', $router->url_for_language( $code ) ) ) . '">'
					. esc_html( trim( Languages::flag( $code ) . ' ' . Languages::label( $code ) ) ) . '</a>';
			}
			echo wp_kses(
				'<div id="trrocket-ve-bar" class="trrocket-ve-bar-source"><span class="trrocket-ve-brand">🚀 TranslateRocket™</span>'
				. '<span class="trrocket-ve-mode">' . sprintf(
					/* translators: %s: source language name. */
					esc_html__( '%s is your source language — pick a language to translate into:', 'translate-rocket' ),
					'<strong>' . esc_html( Languages::label( $lang ) ) . '</strong>'
				) . '</span>'
				. $picks
				. '<a class="trrocket-ve-exit" href="' . esc_url( $exit ) . '">' . esc_html__( 'Done', 'translate-rocket' ) . '</a></div>',
				\TranslateRocket\Kses::html_rules()
			);
			return;
		}

		// "From → to" flags (icons only). The source flag, an arrow, then every target
		// flag: the current one is highlighted, the others link to that language's
		// editor so you can switch the page you're translating with one click.
		$source   = $router->default_language();
		$src_flag = \TranslateRocket\Flags::html( $source, Languages::flag( $source ) );
		$tos      = '';
		foreach ( $router->secondary_languages() as $code ) {
			$flag = \TranslateRocket\Flags::html( $code, Languages::flag( $code ) );
			if ( $code === $lang ) {
				$tos .= '<span class="trrocket-ve-lang is-current" title="' . esc_attr( Languages::label( $code ) ) . '">' . $flag . '</span>';
			} else {
				$url  = add_query_arg( self::PARAM, '1', $router->url_for_language( $code ) );
				/* translators: %s: language name. */
				$tos .= '<a class="trrocket-ve-lang" href="' . esc_url( $url ) . '" title="' . esc_attr( sprintf( __( 'Switch to %s', 'translate-rocket' ), Languages::label( $code ) ) ) . '">' . $flag . '</a>';
			}
		}
		$langs = '<span class="trrocket-ve-langs" title="' . esc_attr( Languages::label( $source ) . ' → ' . Languages::label( $lang ) ) . '">'
			. '<span class="trrocket-ve-lang trrocket-ve-lang-src">' . $src_flag . '</span>'
			. '<span class="trrocket-ve-langarrow" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>'
			. $tos
			. '</span>';

		// Per-page controls (visibility + independent copy). When an independent copy is
		// being served the queried object IS the copy, so resolve back to the source so
		// the controls always act on the original page.
		$qid       = (int) get_queried_object_id();
		$source_id = \TranslateRocket\Copies::is_copy( $qid ) ? \TranslateRocket\Copies::source_of( $qid ) : $qid;
		$can_page  = $source_id > 0
			&& ( is_singular() || is_front_page() || is_home() )
			&& ( get_post( $source_id ) instanceof \WP_Post );
		$visbtn   = '';
		$panel    = '';
		$copyctl  = '';
		$seobtn   = '';
		$seopanel = '';
		if ( $can_page ) {
			$panel = $this->visibility_panel( $source_id );
			if ( '' !== $panel ) {
				$eye = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
				$visbtn = '<button type="button" class="trrocket-ve-visbtn" aria-expanded="false">' . $eye . '<span>' . esc_html__( 'Visibility', 'translate-rocket' ) . '</span></button>';
			}
			$copyctl  = $this->copy_control( $source_id, $lang );
			$seopanel = $this->seo_panel( $source_id, $lang );
			if ( '' !== $seopanel ) {
				$glass  = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>';
				$seobtn = '<button type="button" class="trrocket-ve-seobtn" aria-expanded="false">' . $glass . '<span>' . esc_html__( 'SEO', 'translate-rocket' ) . '</span></button>';
			}
		}

		echo wp_kses(
			'<div id="trrocket-ve-bar"><span class="trrocket-ve-brand">🚀 TranslateRocket™</span>'
			. $langs
			. '<span class="trrocket-ve-hint">' . esc_html__( 'Click any text to translate it', 'translate-rocket' ) . '</span>'
			. $copyctl
			. $seobtn
			. $visbtn
			. '<a class="trrocket-ve-exit" href="' . esc_url( $exit ) . '">' . esc_html__( 'Done', 'translate-rocket' ) . '</a></div>'
			. $panel
			. $seopanel,
			\TranslateRocket\Kses::html_rules()
		);
	}

	/**
	 * The independent-copy control in the toolbar. In string mode it offers to detach
	 * the page into an editable copy; when a copy is active it shows an "Edit copy"
	 * link and a "Back to strings" (pause) button.
	 *
	 * @param int    $source_id The original page id.
	 * @param string $lang      Current language.
	 */
	private function copy_control( int $source_id, string $lang ): string {
		$doc  = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4h10a2 2 0 0 1 2 2v12M16 8H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2z"/></svg>';
		$warn = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>';
		$src  = esc_attr( (string) $source_id );

		$copy_id = \TranslateRocket\Copies::copy_id( $source_id, $lang );
		$exists  = $copy_id > 0 && ( get_post( $copy_id ) instanceof \WP_Post );

		$delete = '<button type="button" class="trrocket-ve-copy-delete" data-source="' . $src . '" title="' . esc_attr__( 'Delete this independent copy', 'translate-rocket' ) . '">' . esc_html__( 'Delete copy', 'translate-rocket' ) . '</button>';

		// Active: badge + (drift warning) + edit + back-to-strings + delete.
		if ( $exists && \TranslateRocket\Copies::is_active( $source_id, $lang ) ) {
			$edit  = admin_url( 'post.php?post=' . $copy_id . '&action=edit' );
			$drift = \TranslateRocket\Copies::source_changed( $source_id, $lang )
				? '<span class="trrocket-ve-copy-drift" title="' . esc_attr__( 'The source page changed after this copy was made — re-create it to pull in the changes (the copy\'s edits are lost), or keep editing the copy.', 'translate-rocket' ) . '">' . $warn . '<span>' . esc_html__( 'Source changed', 'translate-rocket' ) . '</span></span>'
				: '';
			return '<span class="trrocket-ve-copy is-active">'
				. '<span class="trrocket-ve-copy-badge" title="' . esc_attr__( 'This page is served from an independent copy.', 'translate-rocket' ) . '">' . $doc . '<span>' . esc_html__( 'Independent copy', 'translate-rocket' ) . '</span></span>'
				. $drift
				. '<a class="trrocket-ve-copy-edit" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit copy', 'translate-rocket' ) . '</a>'
				. '<button type="button" class="trrocket-ve-copy-revert" data-source="' . $src . '">' . esc_html__( 'Back to strings', 'translate-rocket' ) . '</button>'
				. $delete
				. '</span>';
		}

		// Paused copy exists: offer to reactivate (keeps edits) or delete.
		if ( $exists ) {
			return '<span class="trrocket-ve-copy is-paused">'
				. '<button type="button" class="trrocket-ve-copy-create" data-source="' . $src . '" title="' . esc_attr__( 'Reactivate the independent copy (your edits are restored).', 'translate-rocket' ) . '">' . $doc . '<span>' . esc_html__( 'Reactivate copy', 'translate-rocket' ) . '</span></button>'
				. $delete
				. '</span>';
		}

		// No copy yet: offer to create one.
		return '<button type="button" class="trrocket-ve-copy-create" data-source="' . $src . '" title="' . esc_attr__( 'Detach this page into an independent, fully-editable copy, pre-filled with the current translation.', 'translate-rocket' ) . '">'
			. $doc . '<span>' . esc_html__( 'Independent copy', 'translate-rocket' ) . '</span></button>';
	}

	/**
	 * Build the collapsible per-language visibility panel for a page. Mirrors the
	 * post-editor meta box (VisibilityBox): for each target language, pick whether the
	 * page is translated or falls back (homepage / URL / custom message / 404). Saved
	 * over AJAX (ajax_visibility) so it works right from the live page.
	 *
	 * @param int $post_id The current page/post.
	 */
	private function visibility_panel( int $post_id ): string {
		$targets = (array) Settings::get()['target_languages'];
		if ( empty( $targets ) ) {
			return '';
		}
		$modes = array(
			''        => __( 'Translate (default)', 'translate-rocket' ),
			'home'    => __( 'Redirect to homepage', 'translate-rocket' ),
			'url'     => __( 'Redirect to a URL', 'translate-rocket' ),
			'message' => __( 'Show a custom message', 'translate-rocket' ),
			'404'     => __( 'Show 404 (not found)', 'translate-rocket' ),
		);

		$rows = '';
		foreach ( $targets as $lang ) {
			$lang = (string) $lang;
			$rule = \TranslateRocket\Exclusions::get( $post_id, $lang );
			$opts = '';
			foreach ( $modes as $val => $label ) {
				$opts .= '<option value="' . esc_attr( $val ) . '" ' . selected( $rule['mode'], $val, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$flag  = \TranslateRocket\Flags::html( $lang, Languages::flag( $lang ) );
			$rows .= '<div class="trrocket-ve-visrow" data-lang="' . esc_attr( $lang ) . '">'
				. '<div class="trrocket-ve-vislabel">' . $flag . '<span>' . esc_html( Languages::label( $lang ) ) . '</span></div>'
				. '<select class="trrocket-ve-vismode">' . $opts . '</select>'
				. '<input type="url" class="trrocket-ve-visurl" value="' . esc_attr( $rule['url'] ) . '" placeholder="' . esc_attr__( 'https://…', 'translate-rocket' ) . '" hidden />'
				. '<textarea class="trrocket-ve-vismsg" rows="2" placeholder="' . esc_attr__( 'Custom message shown instead of the page.', 'translate-rocket' ) . '" hidden>' . esc_textarea( $rule['msg'] ) . '</textarea>'
				. '</div>';
		}

		// translate="no" keeps the translation engine out of the editor's own chrome —
		// otherwise it would wrap/translate the language names (e.g. "Italiano" → "English").
		return '<div id="trrocket-ve-vispanel" data-post="' . esc_attr( (string) $post_id ) . '" translate="no" hidden>'
			. '<div class="trrocket-ve-vishead">' . esc_html__( 'Language visibility for this page', 'translate-rocket' ) . '</div>'
			. '<div class="trrocket-ve-visdesc">' . esc_html__( 'Choose what happens when this page is viewed in each language — translate it, redirect, show a message, or 404.', 'translate-rocket' ) . '</div>'
			. $rows
			. '<div class="trrocket-ve-visfoot"><span class="trrocket-ve-vismsgout"></span>'
			. '<button type="button" class="trrocket-ve-vissave">' . esc_html__( 'Save visibility', 'translate-rocket' ) . '</button></div>'
			. '</div>';
	}

	/**
	 * Build the collapsible SEO panel for the toolbar: the translated slug, the SEO
	 * title and the meta description for the current language — the same fields as
	 * the dashboard "SEO & indexing" block, editable right from the live page and
	 * saved over AJAX (ajax_seo).
	 *
	 * @param int    $post_id The source page/post.
	 * @param string $lang    Current (target) language.
	 */
	private function seo_panel( int $post_id, string $lang ): string {
		$router = Plugin::instance()->router();
		$hash   = Strings::url_hash( $router->canonical_path( $post_id ) );
		$title  = null;
		$desc   = null;
		$altri = array();
		foreach ( Strings::for_page_language( $hash, $lang ) as $row ) {
			if ( 'meta' !== $row->type ) {
				continue;
			}
			if ( 'title' === $row->context ) {
				$title = $row;
			} elseif ( 'description' === $row->context ) {
				$desc = $row;
			} else {
				// Social e parole chiave: og:title, og:description, twitter:*,
				// keywords. Si tengono per contesto, cosi' il pannello li mostra
				// uno per uno invece di lasciarli invisibili.
				$altri[ (string) $row->context ] = $row;
			}
		}

		// The homepage URL has no slug segment (it is just /lang/), so no slug field there.
		$is_home = (int) get_option( 'page_on_front' ) === $post_id || (int) get_option( 'page_for_posts' ) === $post_id;
		$slug    = '<div class="trrocket-ve-seorow">'
			. '<label class="trrocket-ve-seolabel">' . esc_html__( 'Slug (URL)', 'translate-rocket' ) . '</label>';
		if ( $is_home ) {
			$slug .= '<div class="trrocket-ve-seohint">' . esc_html__( 'The homepage has no slug — its address is just the language prefix.', 'translate-rocket' ) . '</div>'
				. '<div class="trrocket-ve-seosrc">/' . esc_html( $lang ) . '/</div>';
		} else {
			$slug .= '<div class="trrocket-ve-seohint">' . esc_html( sprintf(
				/* translators: %s: language code, e.g. "it". */
				__( 'The page address in this language, e.g. /%s/about-us/. Leave empty to keep the original.', 'translate-rocket' ),
				$lang
			) ) . '</div>'
			. '<div class="trrocket-ve-seoslugwrap"><span>/' . esc_html( $lang ) . '/</span>'
			. '<input type="text" class="trrocket-ve-seoslug" value="' . esc_attr( \TranslateRocket\Slugs::get( $post_id, $lang ) ) . '" placeholder="' . esc_attr( (string) get_post_field( 'post_name', $post_id ) ) . '" />'
			. '</div>';
		}
		$slug .= '</div>';

		$rows = $slug
			. $this->seo_panel_row( __( 'SEO title', 'translate-rocket' ), __( 'Shown in the browser tab and as the clickable title in Google results.', 'translate-rocket' ), $title, 'title' )
			. $this->seo_panel_row( __( 'Meta description', 'translate-rocket' ), __( 'The short summary under the title in Google results.', 'translate-rocket' ), $desc, 'description' );

		// Quello che mette un plugin SEO: il titolo e il testo che si vedono
		// quando la pagina viene condivisa, e le parole chiave. Compaiono solo se
		// la pagina li ha davvero — su un sito senza plugin SEO il pannello resta
		// come prima invece di riempirsi di caselle vuote.
		$etichette = array(
			'og:title'          => array( __( 'Social title (Open Graph)', 'translate-rocket' ), __( 'The headline people see when the page is shared on Facebook, LinkedIn or WhatsApp.', 'translate-rocket' ) ),
			'og:description'    => array( __( 'Social description (Open Graph)', 'translate-rocket' ), __( 'The text under that headline in the shared preview.', 'translate-rocket' ) ),
			'twitter:title'     => array( __( 'Title for X / Twitter', 'translate-rocket' ), __( 'Used when the page is shared on X.', 'translate-rocket' ) ),
			'twitter:description' => array( __( 'Description for X / Twitter', 'translate-rocket' ), __( 'The summary in the X preview card.', 'translate-rocket' ) ),
			'og:image:alt'      => array( __( 'Alt text of the shared image', 'translate-rocket' ), __( 'Describes the preview image for people using a screen reader.', 'translate-rocket' ) ),
			'twitter:image:alt' => array( __( 'Alt text of the X image', 'translate-rocket' ), __( 'Describes the preview image on X.', 'translate-rocket' ) ),
			'og:site_name'      => array( __( 'Site name in shares', 'translate-rocket' ), __( 'The name of your site as it appears in the shared preview.', 'translate-rocket' ) ),
			'keywords'          => array( __( 'Meta keywords', 'translate-rocket' ), __( 'Google has ignored these since 2009, but some other engines and directories still read them.', 'translate-rocket' ) ),
		);
		foreach ( $etichette as $ctx => $testi ) {
			if ( isset( $altri[ $ctx ] ) ) {
				$rows .= $this->seo_panel_row( $testi[0], $testi[1], $altri[ $ctx ], $ctx );
			}
		}

		// Il resto della SEO non sta nella testata ma dentro la pagina: il testo
		// alternativo delle immagini e gli attributi tradotti. L'elenco lo riempie
		// il JS leggendo la pagina vera, cosi' rispecchia sempre cio' che si vede,
		// e ogni voce ha il collegamento che porta all'elemento giusto.
		$elenco = '<div class="trrocket-ve-seolist">'
			. '<div class="trrocket-ve-seorow trrocket-ve-seoimgrow" hidden>'
			. '<label class="trrocket-ve-seolabel">' . esc_html__( 'Images on this page', 'translate-rocket' ) . ' <span class="trrocket-ve-seocount"></span></label>'
			. '<div class="trrocket-ve-seohint">' . esc_html__( 'The alt text is what search engines read instead of the picture. Click a line to jump to that photo in the page and edit it.', 'translate-rocket' ) . '</div>'
			. '<div class="trrocket-ve-seoimgs"></div>'
			. '</div>'
			. '<div class="trrocket-ve-seorow trrocket-ve-seoattrrow" hidden>'
			. '<label class="trrocket-ve-seolabel">' . esc_html__( 'Other HTML attributes', 'translate-rocket' ) . ' <span class="trrocket-ve-seocount"></span></label>'
			. '<div class="trrocket-ve-seohint">' . esc_html__( 'Titles, placeholders and ARIA labels: invisible on screen, but read by search engines and screen readers.', 'translate-rocket' ) . '</div>'
			. '<div class="trrocket-ve-seoattrs"></div>'
			. '</div>'
			. '</div>';

		// translate="no" keeps the engine out of the panel's own chrome (see visibility_panel).
		return '<div id="trrocket-ve-seopanel" data-post="' . esc_attr( (string) $post_id ) . '" translate="no" hidden>'
			. '<div class="trrocket-ve-vishead">' . esc_html__( 'SEO for this page', 'translate-rocket' ) . '</div>'
			. '<div class="trrocket-ve-visdesc">' . esc_html( sprintf(
				/* translators: %s: language name, e.g. "Italiano". */
				__( 'Everything search engines read in %s, in order: the address, the title and description, the social preview, then the images and attributes inside the page.', 'translate-rocket' ),
				Languages::label( $lang )
			) ) . '</div>'
			. $rows
			. $elenco
			. '<div class="trrocket-ve-visfoot"><span class="trrocket-ve-vismsgout"></span>'
			. '<button type="button" class="trrocket-ve-vissave trrocket-ve-seosave">' . esc_html__( 'Save SEO', 'translate-rocket' ) . '</button></div>'
			. '</div>';
	}

	/**
	 * One field of the SEO panel (title / description): original text + textarea
	 * with the current translation, or a note when the page has not been scanned yet.
	 *
	 * @param string      $label Field label.
	 * @param string      $hint  Short explanation under the label.
	 * @param object|null $row   The detected meta string row, or null.
	 * @param string      $ctx   String context ('title' or 'description').
	 */
	private function seo_panel_row( string $label, string $hint, $row, string $ctx ): string {
		$out = '<div class="trrocket-ve-seorow">'
			. '<label class="trrocket-ve-seolabel">' . esc_html( $label ) . '</label>'
			. '<div class="trrocket-ve-seohint">' . esc_html( $hint ) . '</div>';
		if ( $row ) {
			$out .= '<div class="trrocket-ve-seosrc">' . esc_html( (string) $row->original ) . '</div>'
				. '<textarea class="trrocket-ve-seotr" rows="2" data-ctx="' . esc_attr( $ctx ) . '" data-src="' . esc_attr( (string) $row->original ) . '">' . esc_textarea( (string) $row->translation ) . '</textarea>';
		} else {
			$out .= '<div class="trrocket-ve-seosrc is-missing">' . esc_html__( 'Not detected on this page yet.', 'translate-rocket' ) . '</div>';
		}
		return $out . '</div>';
	}

	/**
	 * Save the SEO fields (translated slug + SEO title + meta description) for one
	 * page/language from the visual editor's SEO panel.
	 */
	public function ajax_seo(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$post_id = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		$lang    = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		if ( $post_id <= 0 || ! ( get_post( $post_id ) instanceof \WP_Post ) || '' === $lang || ! Languages::exists( $lang ) ) {
			wp_send_json_error( 'invalid' );
		}
		// Editing post meta (the slug): require edit rights on this specific post too.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		if ( isset( $_POST['slug'] ) ) {
			\TranslateRocket\Slugs::set( $post_id, $lang, sanitize_text_field( wp_unslash( $_POST['slug'] ) ) );
		}
		$items = json_decode( isset( $_POST['items'] ) ? (string) wp_unslash( $_POST['items'] ) : '[]', true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitized below.
		if ( is_array( $items ) ) {
			foreach ( $items as $it ) {
				if ( ! is_array( $it ) ) {
					continue;
				}
				$src = isset( $it['src'] ) ? sanitize_textarea_field( (string) $it['src'] ) : '';
				$tr  = isset( $it['translation'] ) ? sanitize_textarea_field( (string) $it['translation'] ) : '';
				// ⚠️ L'elenco dei contesti ammessi deve stare al passo con le righe
				// che il pannello disegna: un campo che si vede ma che il
				// salvataggio scarta in silenzio e' peggio di un campo assente.
				$ammessi = array(
					'title',
					'description',
					'og:title',
					'og:description',
					'og:site_name',
					'og:image:alt',
					'twitter:title',
					'twitter:description',
					'twitter:image:alt',
					'keywords',
				);
				$ctx = ( isset( $it['ctx'] ) && in_array( $it['ctx'], $ammessi, true ) ) ? (string) $it['ctx'] : '';
				if ( '' === $src || '' === $ctx ) {
					continue;
				}
				Strings::save_by_source( $src, $lang, $tr, 'meta', $ctx );
			}
		}
		\TranslateRocket\Cache::flush();
		// Send back the slug as actually saved (sanitize_title may have changed it)
		// so the editor can follow the page to its new address.
		wp_send_json_success( array( 'slug' => \TranslateRocket\Slugs::get( $post_id, $lang ) ) );
	}

	/**
	 * Save one translation (human edit) from the visual editor.
	 */
	public function ajax_save(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$src   = isset( $_POST['src'] ) ? sanitize_textarea_field( wp_unslash( $_POST['src'] ) ) : '';
		$trans = isset( $_POST['translation'] ) ? sanitize_textarea_field( wp_unslash( $_POST['translation'] ) ) : '';
		$lang  = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		// Optional: editing an attribute (alt/title/…) instead of page text.
		// 'image' e' un tipo a se': serve a tenere gli indirizzi delle immagini
		// FUORI dalla traduzione automatica. Salvarli come 'text' vanificherebbe
		// l'esclusione, e un traduttore restituirebbe un indirizzo storpiato
		// facendo sparire l'immagine dalla pagina.
		$type = 'text';
		if ( isset( $_POST['type'] ) ) {
			$chiesto = sanitize_key( wp_unslash( $_POST['type'] ) );
			if ( in_array( $chiesto, array( 'attribute', 'image' ), true ) ) {
				$type = $chiesto;
			}
		}
		$ctx = null;
		if ( 'attribute' === $type && isset( $_POST['ctx'] ) ) {
			$ctx = sanitize_key( wp_unslash( $_POST['ctx'] ) );
		} elseif ( 'image' === $type ) {
			$ctx = 'src';
		}
		if ( '' === $src || '' === $lang || ! Languages::exists( $lang ) ) {
			wp_send_json_error( 'invalid' );
		}
		Strings::save_by_source( $src, $lang, $trans, $type, $ctx );
		\TranslateRocket\Cache::flush();
		wp_send_json_success( array( 'translation' => $trans ) );
	}

	/**
	 * Save a whole rich-text block in one go: the editor maps the paragraph back to
	 * its individual fragment strings (text split by inline <strong>/<a>/… tags) and
	 * sends them all here, so the user can translate the paragraph as one piece.
	 */
	public function ajax_save_block(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		if ( '' === $lang || ! Languages::exists( $lang ) ) {
			wp_send_json_error( 'invalid' );
		}
		$items = json_decode( isset( $_POST['items'] ) ? (string) wp_unslash( $_POST['items'] ) : '[]', true ); // phpcs:ignore WordPress.Security
		if ( ! is_array( $items ) ) {
			wp_send_json_error( 'invalid' );
		}
		$count = 0;
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$src = isset( $it['src'] ) ? sanitize_textarea_field( (string) $it['src'] ) : '';
			$tr  = isset( $it['translation'] ) ? sanitize_textarea_field( (string) $it['translation'] ) : '';
			if ( '' === $src ) {
				continue;
			}
			Strings::save_by_source( $src, $lang, $tr, 'text', null );
			++$count;
		}
		\TranslateRocket\Cache::flush();
		wp_send_json_success( array( 'count' => $count ) );
	}

	/**
	 * Translate one string with the active AI provider.
	 */
	public function ajax_ai(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$src  = isset( $_POST['src'] ) ? sanitize_textarea_field( wp_unslash( $_POST['src'] ) ) : '';
		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		if ( '' === $src || '' === $lang ) {
			wp_send_json_error( 'invalid' );
		}
		$provider = Registry::active();
		if ( ! $provider || ! $provider->is_configured() ) {
			wp_send_json_error( __( 'No AI provider configured.', 'translate-rocket' ) );
		}
		$source = (string) ( Settings::get()['source_language'] ?? 'en' );
		$res    = $provider->translate( array( $src ), $source, $lang );
		if ( $res->success && isset( $res->translations[0] ) && '' !== trim( (string) $res->translations[0] ) ) {
			wp_send_json_success( array( 'translation' => (string) $res->translations[0] ) );
		}
		wp_send_json_error( $res->error ? $res->error : __( 'Translation failed.', 'translate-rocket' ) );
	}

	/**
	 * Quick translation via Google's free endpoint (no API key). Best-effort,
	 * server-side so it works in the same tab without framing Google.
	 */
	public function ajax_gt(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		if ( ! \TranslateRocket\GoogleFree::auto_enabled() ) {
			wp_send_json_error( 'disabled' );
		}
		$src  = isset( $_POST['src'] ) ? sanitize_textarea_field( wp_unslash( $_POST['src'] ) ) : '';
		$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		if ( '' === $src || '' === $lang ) {
			wp_send_json_error( 'invalid' );
		}
		$source = (string) ( Settings::get()['source_language'] ?? 'en' );
		$out    = \TranslateRocket\GoogleFree::translate( $src, $source, $lang );
		if ( false !== $out ) {
			wp_send_json_success( array( 'translation' => $out ) );
		}
		wp_send_json_error( __( 'Google Translate is unavailable right now.', 'translate-rocket' ) );
	}

	/**
	 * Save the per-language visibility rules for a page from the visual editor panel.
	 * Same model as the post-editor meta box, persisted via Exclusions (post meta).
	 */
	public function ajax_visibility(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$post_id = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		if ( $post_id <= 0 || ! ( get_post( $post_id ) instanceof \WP_Post ) ) {
			wp_send_json_error( 'invalid' );
		}
		// Editing post meta: require edit rights on this specific post too.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$rules = json_decode( isset( $_POST['rules'] ) ? (string) wp_unslash( $_POST['rules'] ) : '[]', true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitized below.
		if ( ! is_array( $rules ) ) {
			wp_send_json_error( 'invalid' );
		}
		$targets = (array) Settings::get()['target_languages'];
		foreach ( $targets as $lang ) {
			$lang = (string) $lang;
			if ( ! isset( $rules[ $lang ] ) || ! is_array( $rules[ $lang ] ) ) {
				continue;
			}
			$mode = isset( $rules[ $lang ]['mode'] ) ? sanitize_text_field( (string) $rules[ $lang ]['mode'] ) : '';
			$url  = isset( $rules[ $lang ]['url'] ) ? esc_url_raw( (string) $rules[ $lang ]['url'] ) : '';
			$msg  = isset( $rules[ $lang ]['msg'] ) ? wp_kses_post( (string) $rules[ $lang ]['msg'] ) : '';
			\TranslateRocket\Exclusions::set( $post_id, $lang, $mode, $url, $msg );
		}
		\TranslateRocket\Cache::flush();
		wp_send_json_success( array( 'saved' => true ) );
	}

	/**
	 * Detach the current page into an independent, editable copy in a language
	 * (pre-filled with the current translation). Returns the copy's edit URL.
	 */
	public function ajax_copy_create(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$source = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		$lang   = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		if ( $source <= 0 || ! ( get_post( $source ) instanceof \WP_Post ) || '' === $lang || ! Languages::exists( $lang ) ) {
			wp_send_json_error( 'invalid' );
		}
		if ( ! current_user_can( 'edit_post', $source ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$copy_id = \TranslateRocket\Copies::create( $source, $lang );
		if ( $copy_id <= 0 ) {
			wp_send_json_error( __( 'Could not create the copy.', 'translate-rocket' ) );
		}
		wp_send_json_success( array( 'edit' => admin_url( 'post.php?post=' . $copy_id . '&action=edit' ) ) );
	}

	/**
	 * Pause an independent copy (back to runtime string translation). The copy and
	 * its edits are kept, only no longer served.
	 */
	public function ajax_copy_pause(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$source = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		$lang   = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		if ( $source <= 0 || '' === $lang ) {
			wp_send_json_error( 'invalid' );
		}
		if ( ! current_user_can( 'edit_post', $source ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		\TranslateRocket\Copies::pause( $source, $lang );
		wp_send_json_success( array( 'paused' => true ) );
	}

	/**
	 * Delete an independent copy for good (edits lost; page reverts to runtime
	 * translation).
	 */
	public function ajax_copy_delete(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$source = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		$lang   = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		if ( $source <= 0 || '' === $lang ) {
			wp_send_json_error( 'invalid' );
		}
		if ( ! current_user_can( 'edit_post', $source ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		\TranslateRocket\Copies::delete( $source, $lang );
		wp_send_json_success( array( 'deleted' => true ) );
	}
}
