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
 * Nothing is sent anywhere from here. The answer is written into this site's
 * own options, and the person is offered a button that copies it so they can
 * paste it into the support forum if they feel like it. Whether the plugin
 * should ever post it on its own is a decision for the site owner to make
 * knowingly, not something a deactivation screen does quietly.
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
	 * @return array<string,array{label:string,ask:string}>
	 */
	public static function reasons(): array {
		return array(
			'not-working'  => array(
				'label' => __( 'I could not get it to work', 'translate-rocket' ),
				'ask'   => __( 'What did you try, and what happened?', 'translate-rocket' ),
			),
			'quality'      => array(
				'label' => __( 'The translations were not good enough', 'translate-rocket' ),
				'ask'   => __( 'Which language, and what was wrong with it?', 'translate-rocket' ),
			),
			'broke'        => array(
				'label' => __( 'It broke something on my site', 'translate-rocket' ),
				'ask'   => __( 'What broke? This is the one I most want to hear about.', 'translate-rocket' ),
			),
			'missing'      => array(
				'label' => __( 'Something I need is missing', 'translate-rocket' ),
				'ask'   => __( 'What were you looking for?', 'translate-rocket' ),
			),
			'complicated'  => array(
				'label' => __( 'Too complicated to set up', 'translate-rocket' ),
				'ask'   => __( 'Where did you get stuck?', 'translate-rocket' ),
			),
			'other-plugin' => array(
				'label' => __( 'I am switching to another plugin', 'translate-rocket' ),
				'ask'   => __( 'Which one, and what does it do better?', 'translate-rocket' ),
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
		$provider = '';
		foreach ( array( 'provider', 'ai_provider', 'engine' ) as $k ) {
			if ( ! empty( $settings[ $k ] ) && is_string( $settings[ $k ] ) ) {
				$provider = $settings[ $k ];
				break;
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
							<input type="radio" name="trr-bye-reason" value="<?php echo esc_attr( $key ); ?>" data-ask="<?php echo esc_attr( $r['ask'] ); ?>">
							<span><?php echo esc_html( $r['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="trr-bye-ask" style="display:none"><label for="trr-bye-text" id="trr-bye-asklabel"></label></p>
				<textarea id="trr-bye-text" rows="3" style="display:none" class="large-text"></textarea>
				<p class="trr-bye-note">
					<?php esc_html_e( 'Your answer stays on this site. The plugin does not send anything anywhere. If you would like me to see it, use the copy button and paste it in the support forum: it is the fastest way to get an answer, too.', 'translate-rocket' ); ?>
				</p>
				<details class="trr-bye-what">
					<summary><?php esc_html_e( 'What the copy button puts on your clipboard', 'translate-rocket' ); ?></summary>
					<p><?php esc_html_e( 'Your answer above, and these numbers. Nothing else: no site address, no name, no e-mail, no page or translation content.', 'translate-rocket' ); ?></p>
					<pre class="trr-bye-ctx"><?php echo esc_html( $line ); ?></pre>
				</details>
				<p class="trr-bye-buttons">
					<button type="button" class="button button-primary" id="trr-bye-copy" disabled><?php esc_html_e( 'Copy and deactivate', 'translate-rocket' ); ?></button>
					<button type="button" class="button" id="trr-bye-skip"><?php esc_html_e( 'Skip and deactivate', 'translate-rocket' ); ?></button>
					<button type="button" class="button-link trr-bye-cancel" id="trr-bye-cancel"><?php esc_html_e( 'Cancel', 'translate-rocket' ); ?></button>
				</p>
				<p class="trr-bye-forum">
					<a href="https://wordpress.org/support/plugin/translate-rocket/#new-topic-0" target="_blank" rel="noopener"><?php esc_html_e( 'Open the support forum in a new tab', 'translate-rocket' ); ?></a>
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
			.trr-bye-forum{margin:10px 0 0;font-size:13px}
			@media (prefers-color-scheme:dark){.trr-bye-card{background:#1d2327;color:#f0f0f1}.trr-bye-lead,.trr-bye-note,.trr-bye-what,.trr-bye-cancel{color:#c3c4c7}.trr-bye-ctx{background:#2c3338}}
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
			} );

			function remember( then ) {
				var picked = box.querySelector( 'input[name="trr-bye-reason"]:checked' ),
					body = new URLSearchParams();
				body.append( 'action', 'trrocket_farewell' );
				body.append( 'nonce', nonce );
				body.append( 'reason', picked ? picked.value : '' );
				body.append( 'text', text.value || '' );
				fetch( ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( then ).catch( then );
				// Nobody stays stuck on a box because a request hangs.
				setTimeout( then, 4000 );
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
				var picked = box.querySelector( 'input[name="trr-bye-reason"]:checked' ),
					note = ( picked ? picked.parentNode.textContent.trim() : '' ) + '\n\n' + ( text.value || '' ) + '\n\n' + ctx;
				copy.disabled = true;
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( note ).catch( function () {} );
				}
				remember( leave );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Write the answer into this site's options and remember the question was
	 * asked. Nothing leaves the site.
	 */
	public function ajax(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array(), 403 );
		}
		update_option( self::ASKED, time(), false );

		$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		$text   = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		if ( array_key_exists( $reason, self::reasons() ) ) {
			update_option(
				self::ANSWER,
				array(
					'reason' => $reason,
					'text'   => mb_substr( $text, 0, 2000 ),
					'when'   => time(),
				),
				false
			);
		}
		wp_send_json_success();
	}
}
