<?php
/**
 * One question on the way out.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * When somebody deactivates the plugin, ask once why.
 *
 * People who open a support ticket or leave a review are the ones who stayed.
 * Everybody else leaves in silence, and a plugin that never hears from them
 * keeps making the same mistake. So: one small box, on the way out.
 *
 * The answer is always written into this site's own options. It reaches the
 * developer only if the person presses «Send and deactivate»: then the reason,
 * what they typed and the numbers shown in the box (versions, how many
 * languages and sentences, days since install) go to translaterocket.com. No
 * site address, no name, no e-mail. «Skip and deactivate» sends nothing. Until
 * 1.5.5 nothing was ever sent, and 20 active sites out of ~400 downloads a month
 * left without anybody knowing why (24/9/2026).
 *
 * The rest of the rules, which are also the plugin directory's rules:
 *  - it never blocks anything: "Skip and deactivate" is right there, Escape and
 *    a click outside close the box, and the plugin deactivates either way;
 *  - it is asked once, ever;
 *  - the site owner can turn it off with a filter.
 */
class Farewell {

	const ASKED  = 'trrocket_farewell_asked';
	const ANSWER = 'trrocket_farewell_answer';
	const NONCE  = 'trrocket_farewell';
	const ENDPOINT = 'https://translaterocket.com/wp-json/translaterocket/v1/farewell';

	/**
	 * Hook into the Plugins screen only.
	 */
	public function register(): void {
		add_action( 'load-plugins.php', array( $this, 'maybe_prepare' ) );
		add_action( 'wp_ajax_trrocket_farewell', array( $this, 'ajax' ) );
	}

	/**
	 * Print the box on the Plugins screen, unless the question was already asked
	 * or the site owner turned it off.
	 */
	public function maybe_prepare(): void {
		if ( get_option( self::ASKED ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		/**
		 * Filter: return false to never ask why, on deactivation.
		 *
		 * @param bool $show Whether to ask.
		 */
		if ( ! apply_filters( 'trrocket_ask_on_deactivate', true ) ) {
			return;
		}
		add_action( 'admin_footer', array( $this, 'box' ) );
	}

	/**
	 * The reasons offered, in the order they are shown.
	 *
	 * @return array<string,array{label:string,ask:string,chips?:array<string,string>}>
	 */
	public static function reasons(): array {
		return array(
			'not-working'  => array(
				'label' => __( 'I could not get it to work', 'translate-rocket' ),
				'ask'   => __( 'What did you try, and what happened?', 'translate-rocket' ),
				// 9/10/2026: one click, for who does not want to type (most answers had no text).
				'chips' => array(
					'nothing-translated' => __( 'Nothing got translated', 'translate-rocket' ),
					'no-switcher' => __( 'The language switcher did not show up', 'translate-rocket' ),
					'key-failed' => __( 'The AI key did not work', 'translate-rocket' ),
					'page-error' => __( 'A translated page was blank or showed an error', 'translate-rocket' ),
					'no-start' => __( 'I could not find where to start', 'translate-rocket' ),
				),
			),
			'quality'      => array(
				'label' => __( 'The translations were not good enough', 'translate-rocket' ),
				'ask'   => __( 'Which language, and what was wrong with it?', 'translate-rocket' ),
				// 9/10/2026: one click, for who does not want to type (most answers had no text).
				'chips' => array(
					'stayed-original' => __( 'Some text stayed in the original language', 'translate-rocket' ),
					'wrong-meaning' => __( 'The meaning was wrong', 'translate-rocket' ),
					'layout' => __( 'The layout or formatting broke', 'translate-rocket' ),
					'names' => __( 'Names or brands got translated', 'translate-rocket' ),
				),
			),
			'broke'        => array(
				'label' => __( 'It broke something on my site', 'translate-rocket' ),
				'ask'   => __( 'What broke? This is the one I most want to hear about.', 'translate-rocket' ),
				// 9/10/2026: one click, for who does not want to type (most answers had no text).
				'chips' => array(
					'design' => __( 'The design or layout', 'translate-rocket' ),
					'builder' => __( 'My page builder (Elementor, Divi…)', 'translate-rocket' ),
					'shop' => __( 'The shop or the checkout', 'translate-rocket' ),
					'seo' => __( 'SEO, addresses or redirects', 'translate-rocket' ),
					'slow' => __( 'The site got slow', 'translate-rocket' ),
					'errors' => __( 'Errors or a blank page', 'translate-rocket' ),
				),
			),
			'missing'      => array(
				'label' => __( 'Something I need is missing', 'translate-rocket' ),
				'ask'   => __( 'What were you looking for?', 'translate-rocket' ),
				// 9/10/2026: one click, for who does not want to type (most answers had no text).
				'chips' => array(
					'language' => __( 'A language', 'translate-rocket' ),
					'auto' => __( 'Automatic translation of new content', 'translate-rocket' ),
					'domains' => __( 'A domain per language', 'translate-rocket' ),
					'woo' => __( 'WooCommerce', 'translate-rocket' ),
					'import' => __( 'Import from another plugin', 'translate-rocket' ),
					'media' => __( 'Translated images', 'translate-rocket' ),
				),
			),
			'complicated'  => array(
				'label' => __( 'Too complicated to set up', 'translate-rocket' ),
				'ask'   => __( 'Where did you get stuck?', 'translate-rocket' ),
				// 9/10/2026: one click, for who does not want to type (most answers had no text).
				'chips' => array(
					'too-many' => __( 'Too many settings', 'translate-rocket' ),
					'keys' => __( 'The AI keys', 'translate-rocket' ),
					'start' => __( 'Where to start', 'translate-rocket' ),
					'switcher' => __( 'Placing the language switcher', 'translate-rocket' ),
					'words' => __( 'The wording was unclear', 'translate-rocket' ),
				),
			),
			'other-plugin' => array(
				'label' => __( 'I am switching to another plugin', 'translate-rocket' ),
				'ask'   => __( 'Which one, and what does it do better?', 'translate-rocket' ),
				// 9/10/2026: one click, for who does not want to type (most answers had no text).
				'chips' => array(
					'translatepress' => 'TranslatePress',
					'weglot' => 'Weglot',
					'wpml' => 'WPML',
					'polylang' => 'Polylang',
					'gtranslate' => 'GTranslate',
					'another' => __( 'Another one', 'translate-rocket' ),
				),
			),
			'temporary'    => array(
				'label' => __( 'Only turning it off for a moment', 'translate-rocket' ),
				'ask'   => '',
			),
			'other'        => array(
				'label' => __( 'Something else', 'translate-rocket' ),
				'ask'   => __( 'Tell me in your own words.', 'translate-rocket' ),
			),
		);
	}

	/**
	 * The plugin's own numbers, the ones that make a report reproducible. No site
	 * address, no name, no e-mail, no page or translation content.
	 *
	 * @return array<string,string|int>
	 */
	public static function context(): array {
		global $wpdb;
		$settings = (array) get_option( 'trrocket_settings', array() );
		$table = $wpdb->prefix . 'trrocket_strings';
		// ⚠️ Qui c'era un COUNT(*) sull'intera tabella delle frasi, eseguito a OGNI
		// apertura della pagina Plugin: su un sito con mezzo milione di frasi e' una
		// lettura completa, e la finestrella e' solo un contorno. Si tiene da parte
		// per un giorno. Trovato in revisione il 20/9.
		$strings = get_transient( 'trrocket_conta_frasi' );
		if ( false === $strings ) {
			$strings = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$strings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			}
			set_transient( 'trrocket_conta_frasi', $strings, DAY_IN_SECONDS );
		}
		$strings = (int) $strings;
		// 9/10/2026: the engine lives in «active_provider»; the old keys read here never existed, so
		// every answer said «provider: (empty)» and we could not tell who left without a key. Only
		// the NAMES of the engines that have a key are sent, never a key.
		$provider = (string) ( $settings['active_provider'] ?? '' );
		$con_chiave = array();
		foreach ( (array) ( $settings['providers'] ?? array() ) as $pid => $conf ) {
			if ( is_array( $conf ) && ! empty( $conf['api_key'] ) ) {
				$con_chiave[] = sanitize_key( (string) $pid );
			}
		}
		$provider = '' !== $provider ? $provider : 'none';
		// 9/10/2026: what the first day looked like. Almost everybody leaves on day 0, so minutes,
		// not days; where the switcher went; how much got translated; the theme and the other
		// active plugins (folder names only), to reproduce a conflict. Shown in full in the box.
		$tradotte = get_transient( 'trrocket_conta_tradotte' );
		if ( false === $tradotte ) {
			$tradotte = 0;
			foreach ( (array) ( $settings['target_languages'] ?? array() ) as $code ) {
				$tradotte += \TranslateRocket\Strings::translated_count( (string) $code );
			}
			set_transient( 'trrocket_conta_tradotte', $tradotte, HOUR_IN_SECONDS );
		}
		$sw   = (array) ( $settings['switcher'] ?? array() );
		$dove = ! empty( $sw['in_menu'] ) ? 'menu' : ( ! empty( $sw['in_spot'] ) ? 'spot' : ( ! empty( $sw['floating'] ) ? 'floating' : 'manual' ) );
		foreach ( (array) ( $settings['switchers'] ?? array() ) as $prof ) {
			if ( is_array( $prof ) && ! empty( $prof['in_menu'] ) ) {
				$dove = 'menu';
			}
		}
		if ( ! empty( $sw['footer_row'] ) ) {
			$dove .= '+footer';
		}
		$altri = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $pl ) {
			$cartella = strtok( (string) $pl, '/' );
			if ( 'translate-rocket' !== $cartella ) {
				$altri[] = sanitize_key( (string) $cartella );
			}
		}
		return array(
			'plugin'    => defined( 'TRROCKET_VERSION' ) ? TRROCKET_VERSION : '',
			'wp'        => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'languages' => count( (array) ( $settings['target_languages'] ?? array() ) ),
			'strings'   => $strings,
			'provider'  => $provider,
			'wizard'    => get_option( 'trrocket_wizard_done' ) ? 'done' : 'not done',
			'days'      => (int) floor( ( time() - (int) get_option( Growth::INSTALLED, time() ) ) / DAY_IN_SECONDS ),
			'locale'    => get_locale(),
			'keys'      => $con_chiave ? implode( ',', $con_chiave ) : 'none',
			'translated' => (int) $tradotte,
			'switcher'  => $dove,
			'minutes'   => (int) floor( ( time() - (int) get_option( Growth::INSTALLED, time() ) ) / MINUTE_IN_SECONDS ),
			'theme'     => sanitize_key( (string) get_stylesheet() ),
			'plugins'   => implode( ',', array_slice( $altri, 0, 40 ) ),
		);
	}

	/**
	 * The box, and the small script that opens it when Deactivate is clicked on
	 * our row and nowhere else.
	 */
	public function box(): void {
		$ctx  = self::context();
		$file = plugin_basename( TRROCKET_FILE );
		$line = '';
		foreach ( $ctx as $k => $v ) {
			$line .= $k . ': ' . $v . "\n";
		}
		?>
		<div class="trr-bye" id="trr-bye" style="display:none" role="dialog" aria-modal="true" aria-labelledby="trr-bye-t">
			<div class="trr-bye-card">
				<h2 id="trr-bye-t"><?php esc_html_e( 'Before you go: what went wrong?', 'translate-rocket' ); ?></h2>
				<p class="trr-bye-lead">
					<?php esc_html_e( 'TranslateRocket is written by one person. Almost everybody who leaves does it in silence, so the same mistake stays in the plugin. One line here would help a lot. Answering is optional, and the plugin deactivates either way.', 'translate-rocket' ); ?>
				</p>
				<div class="trr-bye-list">
					<?php foreach ( self::reasons() as $key => $r ) : ?>
						<label class="trr-bye-item">
							<input type="radio" name="trr-bye-reason" value="<?php echo esc_attr( $key ); ?>" data-ask="<?php echo esc_attr( $r['ask'] ); ?>" data-chips="<?php echo esc_attr( (string) wp_json_encode( $r['chips'] ?? array() ) ); ?>">
							<span><?php echo esc_html( $r['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="trr-bye-chips" style="display:none"></div>
				<p class="trr-bye-ask" style="display:none"><label for="trr-bye-text" id="trr-bye-asklabel"></label></p>
				<textarea id="trr-bye-text" rows="3" style="display:none" class="large-text"></textarea>
				<p class="trr-bye-mail" style="display:none">
					<label for="trr-bye-email"><?php esc_html_e( 'Want an answer? Your e-mail (optional):', 'translate-rocket' ); ?></label>
					<input type="email" id="trr-bye-email" class="regular-text" autocomplete="email" maxlength="120">
				</p>
				<?php // 9/10/2026 (Federico: «scrivi supporto rapido, spieghiamo che il supporto è tempestivo»). ?>
				<p class="trr-bye-help">
					<strong><?php esc_html_e( 'Fast support, free.', 'translate-rocket' ); ?></strong>
					<?php esc_html_e( 'If something does not work, the developer answers quickly, usually the same day, and fixes it in the next update.', 'translate-rocket' ); ?>
					<a href="https://wordpress.org/support/plugin/translate-rocket/#new-topic-0" target="_blank" rel="noopener"><?php esc_html_e( 'Ask for help instead', 'translate-rocket' ); ?></a>
				</p>
				<p class="trr-bye-note">
					<?php esc_html_e( '«Send and deactivate» sends your answer to the developer at translaterocket.com, anonymously. «Skip and deactivate» sends nothing.', 'translate-rocket' ); ?>
				</p>
				<details class="trr-bye-what">
					<summary><?php esc_html_e( 'Exactly what is sent', 'translate-rocket' ); ?></summary>
					<p><?php esc_html_e( 'The reason and the quick answers you picked, what you wrote, your e-mail only if you typed it, your browser type, and these numbers. Nothing else: no site address, no name, no page or translation content. Your IP address is not stored.', 'translate-rocket' ); ?></p>
					<pre class="trr-bye-ctx"><?php echo esc_html( $line ); ?></pre>
				</details>
				<p class="trr-bye-buttons">
					<button type="button" class="button button-primary" id="trr-bye-copy" disabled><?php esc_html_e( 'Send and deactivate', 'translate-rocket' ); ?></button>
					<button type="button" class="button" id="trr-bye-skip"><?php esc_html_e( 'Skip and deactivate', 'translate-rocket' ); ?></button>
					<button type="button" class="button-link trr-bye-cancel" id="trr-bye-cancel"><?php esc_html_e( 'Cancel', 'translate-rocket' ); ?></button>
				</p>
			</div>
		</div>
		<style>
			.trr-bye{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100050;display:flex;align-items:center;justify-content:center;padding:20px}
			.trr-bye-card{background:#fff;border-radius:8px;max-width:520px;width:100%;max-height:90vh;overflow:auto;padding:22px 24px;box-shadow:0 10px 40px rgba(0,0,0,.3)}
			.trr-bye-card h2{margin:0 0 8px;font-size:18px}
			.trr-bye-lead,.trr-bye-note,.trr-bye-what{color:#50575e}
			.trr-bye-lead{margin:0 0 14px}
			.trr-bye-item{display:block;padding:5px 0}
			.trr-bye-item span{margin-left:6px}
			.trr-bye-note{margin:14px 0 0;font-size:13px}
			.trr-bye-what{margin:10px 0}
			.trr-bye-ctx{background:#f6f7f7;padding:8px;border-radius:4px;font-size:12px;white-space:pre-wrap;margin:8px 0 0}
			.trr-bye-buttons{margin:16px 0 0;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
			.trr-bye-cancel{color:#50575e;text-decoration:underline}
			.trr-bye-chips{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0 4px}
			.trr-bye-chip{display:inline-flex;align-items:center;gap:5px;border:1px solid #c3c4c7;border-radius:16px;padding:4px 10px;cursor:pointer;font-size:13px;line-height:1.4}
			.trr-bye-chip input{margin:0}
			.trr-bye-chip:has(input:checked){background:#f0f6fc;border-color:#2271b1}
			.trr-bye-mail{margin:10px 0 0}
			.trr-bye-mail label{display:block;margin-bottom:4px}
			.trr-bye-mail input{width:100%;max-width:100%}
			.trr-bye-help{margin:14px 0 0;padding:8px 12px;background:#edfaef;border-left:4px solid #00a32a;font-size:13px}
			.trr-bye-help a{font-weight:600}
			@media (prefers-color-scheme:dark){.trr-bye-card{background:#1d2327;color:#f0f0f1}.trr-bye-lead,.trr-bye-note,.trr-bye-what,.trr-bye-cancel{color:#c3c4c7}.trr-bye-ctx{background:#2c3338}.trr-bye-help{background:#1e3a26}.trr-bye-chip:has(input:checked){background:#1d3a52}}
		</style>
		<script>
		( function () {
			var file = <?php echo wp_json_encode( $file ); ?>,
				ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>,
				ctx = <?php echo wp_json_encode( $line ); ?>,
				box = document.getElementById( 'trr-bye' ),
				copy = document.getElementById( 'trr-bye-copy' ),
				text = document.getElementById( 'trr-bye-text' ),
				ask = document.querySelector( '.trr-bye-ask' ),
				askLabel = document.getElementById( 'trr-bye-asklabel' ),
				chipsBox = box.querySelector( '.trr-bye-chips' ),
				mail = box.querySelector( '.trr-bye-mail' ),
				email = document.getElementById( 'trr-bye-email' ),
				go = '';

			// Our row's Deactivate link, and only ours.
			document.addEventListener( 'click', function ( e ) {
				var a = e.target.closest ? e.target.closest( 'a' ) : null;
				if ( ! a || a.href.indexOf( 'action=deactivate' ) === -1 || a.href.indexOf( encodeURIComponent( file ) ) === -1 ) {
					return;
				}
				e.preventDefault();
				go = a.href;
				box.style.display = 'flex';
			} );

			box.addEventListener( 'change', function ( e ) {
				if ( 'trr-bye-reason' !== e.target.name ) {
					return;
				}
				copy.disabled = false;
				var q = e.target.getAttribute( 'data-ask' );
				ask.style.display = q ? 'block' : 'none';
				text.style.display = q ? 'block' : 'none';
				askLabel.textContent = q || '';
				// the quick answers of this reason, as small boxes to tick
				var chips = {};
				try { chips = JSON.parse( e.target.getAttribute( 'data-chips' ) || '{}' ) || {}; } catch ( err ) {}
				chipsBox.innerHTML = '';
				Object.keys( chips ).forEach( function ( k ) {
					var l = document.createElement( 'label' ), c = document.createElement( 'input' );
					l.className = 'trr-bye-chip';
					c.type = 'checkbox';
					c.value = k;
					l.appendChild( c );
					l.appendChild( document.createTextNode( chips[ k ] ) );
					chipsBox.appendChild( l );
				} );
				chipsBox.style.display = Object.keys( chips ).length ? 'flex' : 'none';
				mail.style.display = q ? 'block' : 'none';
			} );

			function remember( then, send ) {
				var picked = box.querySelector( 'input[name="trr-bye-reason"]:checked' ),
					body = new URLSearchParams();
				body.append( 'action', 'trrocket_farewell' );
				body.append( 'nonce', nonce );
				body.append( 'reason', picked ? picked.value : '' );
				body.append( 'text', text.value || '' );
				body.append( 'send', send ? '1' : '0' );
				body.append( 'details', Array.prototype.map.call( chipsBox.querySelectorAll( 'input:checked' ), function ( c ) { return c.value; } ).join( ',' ) );
				body.append( 'email', email.value || '' );
				// Once only: the answer and the 4-second safety net used to BOTH run it, so the
				// deactivation page opened twice (6/10/2026, seen by collaudo-addio).
				var fatto = false;
				function once() {
					if ( fatto ) {
						return;
					}
					fatto = true;
					then();
				}
				fetch( ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( once ).catch( once );
				// Nobody stays stuck on a box because a request hangs.
				setTimeout( once, 4000 );
			}
			function leave() {
				window.location.href = go;
			}
			document.getElementById( 'trr-bye-skip' ).addEventListener( 'click', function () {
				// ⚠️ Anche saltando, la domanda si considera fatta: altrimenti ricompariva a
				// ogni disattivazione, contro quello che promette il riquadro e contro le
				// regole di wordpress.org sugli avvisi insistenti. Trovato in revisione.
				remember( leave );
			} );
			document.getElementById( 'trr-bye-cancel' ).addEventListener( 'click', function () {
				box.style.display = 'none';
			} );
			box.addEventListener( 'click', function ( e ) {
				if ( e.target === box ) {
					box.style.display = 'none';
				}
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && 'none' !== box.style.display ) {
					box.style.display = 'none';
				}
			} );
			copy.addEventListener( 'click', function () {
				copy.disabled = true;
				remember( leave, true );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Write the answer into this site's options and remember the question was
	 * asked. With «Send and deactivate», also post it to translaterocket.com.
	 */
	public function ajax(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array(), 403 );
		}
		update_option( self::ASKED, time(), false );

		$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		$text   = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$motivi = self::reasons();
		if ( array_key_exists( $reason, $motivi ) ) {
			// 9/10/2026: the quick answers (only the ones this reason offers), the e-mail if typed,
			// the browser family and the first-day numbers go at the top of the text, so even the
			// receiver on translaterocket.com that only knows «text» keeps them.
			$scelte = array();
			$dati   = isset( $_POST['details'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['details'] ) ) ) : array();
			foreach ( $dati as $d ) {
				$d = sanitize_key( $d );
				if ( isset( $motivi[ $reason ]['chips'][ $d ] ) ) {
					$scelte[] = $d;
				}
			}
			$mail   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$ctx    = self::context();
			$testa  = '[details: ' . ( $scelte ? implode( ', ', $scelte ) : '-' ) . ']'
				. ' [browser: ' . self::browser( $ua ) . ']'
				. ' [minutes: ' . $ctx['minutes'] . ' | translated: ' . $ctx['translated'] . ' | switcher: ' . $ctx['switcher'] . ' | keys: ' . $ctx['keys'] . ' | theme: ' . $ctx['theme'] . ']'
				. ( '' !== $mail && is_email( $mail ) ? ' [reply to: ' . $mail . ']' : '' )
				. "\n[plugins: " . mb_substr( (string) $ctx['plugins'], 0, 600 ) . ']';
			$inviato = $testa . ( '' !== $text ? "\n\n" . $text : '' );
			update_option(
				self::ANSWER,
				array(
					'reason' => $reason,
					'text'   => mb_substr( $text, 0, 2000 ),
					'when'   => time(),
				),
				false
			);
			/**
			 * Whether «Send and deactivate» may post the answer to translaterocket.com.
			 * Return false to keep every answer on this site.
			 *
			 * @param bool $send Default true (the person pressed Send).
			 */
			$manda = isset( $_POST['send'] ) ? sanitize_key( wp_unslash( $_POST['send'] ) ) : '';
			if ( '1' === $manda && apply_filters( 'trrocket_farewell_send', true ) ) {
				wp_remote_post(
					self::ENDPOINT,
					array(
						'timeout' => 5,
						'headers' => array( 'Content-Type' => 'application/json' ),
						'body'    => wp_json_encode(
							array(
								'reason'  => $reason,
								'text'    => mb_substr( $inviato, 0, 2000 ),
								'context' => $ctx,
								'details' => $scelte,
								'email'   => is_email( $mail ) ? $mail : '',
								'browser' => self::browser( $ua ),
							)
						),
					)
				);
			}
		}
		wp_send_json_success();
	}

	/**
	 * The browser family only, never the whole user agent (9/10/2026: «J'utilise firefox» — was
	 * it the browser?).
	 *
	 * @param string $ua User agent.
	 */
	public static function browser( string $ua ): string {
		$fam = 'other';
		if ( false !== strpos( $ua, 'Edg/' ) ) {
			$fam = 'edge';
		} elseif ( false !== strpos( $ua, 'Firefox/' ) ) {
			$fam = 'firefox';
		} elseif ( false !== strpos( $ua, 'Chrome/' ) ) {
			$fam = 'chrome';
		} elseif ( false !== strpos( $ua, 'Safari/' ) ) {
			$fam = 'safari';
		}
		return $fam . ( preg_match( '/Mobile|Android|iPhone|iPad/', $ua ) ? '-mobile' : '' );
	}
}
