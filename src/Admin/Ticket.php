<?php
/**
 * Open a support ticket from inside the plugin.
 *
 * Nothing is ever sent on its own. The whole message — the text and the
 * technical report — is shown on screen before it leaves, and it only leaves
 * when the person clicks Send. That is the deal, and this file has to keep it.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The "open a ticket" form on the Diagnostics page.
 */
class Ticket {

	/**
	 * Where the ticket goes.
	 */
	const ENDPOINT = 'https://translaterocket.com/wp-json/translaterocket/v1/ticket';

	/**
	 * One ticket every few minutes from this site, so a stuck button cannot
	 * turn into a flood.
	 */
	const PAUSA = 180;

	/**
	 * Transient holding the one-time token the support site calls back for.
	 */
	const TOKEN = 'trrocket_ticket_token';

	public function register(): void {
		add_action( 'wp_ajax_trrocket_ticket', array( $this, 'ajax_invia' ) );
		// Public, on purpose: it is how the support site checks this really is a
		// WordPress site running the plugin, and it answers only to a token that
		// this site itself has just generated.
		//
		// Two doors for the same answer. admin-ajax is the one that matters: no
		// page cache ever touches it. The home-URL one is the spare wheel, for
		// the sites where something blocks admin-ajax from outside — there a
		// cache plugin may answer with the cached homepage instead, which is
		// exactly why it is second in line and not first.
		add_action( 'wp_ajax_nopriv_trrocket_verify', array( $this, 'rispondi_ajax' ) );
		add_action( 'wp_ajax_trrocket_verify', array( $this, 'rispondi_ajax' ) );
		add_action( 'init', array( $this, 'rispondi_alla_verifica' ) );
	}

	/**
	 * Same answer as rispondi_alla_verifica(), over admin-ajax.
	 */
	public function rispondi_ajax(): void {
		// phpcs:ignore WordPress.Security.NonceVerification
		$chiesto = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$this->rispondi( $chiesto );
	}

	/**
	 * The support site fetches ?trrocket_verify=<token> and expects the token
	 * back. Only a site that just asked to open a ticket has that token, so a
	 * forged request from anywhere else fails here.
	 */
	public function rispondi_alla_verifica(): void {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_GET['trrocket_verify'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$chiesto = sanitize_text_field( wp_unslash( $_GET['trrocket_verify'] ) );
		$this->rispondi( $chiesto );
	}

	/**
	 * The answer itself: yes only if the token is the one this site just made.
	 *
	 * @param string $chiesto The token the support site is asking about.
	 */
	private function rispondi( string $chiesto ): void {
		$atteso = (string) get_transient( self::TOKEN );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );

		// hash_equals: nessuna differenza di tempo fra un gettone sbagliato al
		// primo carattere e uno sbagliato all'ultimo.
		if ( '' === $atteso || '' === $chiesto || ! hash_equals( $atteso, $chiesto ) ) {
			status_header( 403 );
			echo 'TRROCKET-NO';
			exit;
		}
		status_header( 200 );
		echo 'TRROCKET-OK:' . esc_html( $atteso );
		exit;
	}

	/**
	 * Send the ticket. Runs only on an explicit click.
	 */
	public function ajax_invia(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'translate-rocket' ) ), 403 );
		}
		check_ajax_referer( 'trrocket_ticket', 'nonce' );

		$ultimo = (int) get_transient( 'trrocket_ticket_last' );
		if ( $ultimo > 0 ) {
			wp_send_json_error(
				array( 'message' => __( 'You just sent one. Give it a few minutes before sending another — I read them all.', 'translate-rocket' ) ),
				429
			);
		}

		$nome      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$oggetto   = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$messaggio = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$rapporto  = isset( $_POST['report'] ) ? sanitize_textarea_field( wp_unslash( $_POST['report'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'That email address does not look right — without it I cannot answer you.', 'translate-rocket' ) ), 400 );
		}
		if ( strlen( trim( $oggetto ) ) < 3 ) {
			wp_send_json_error( array( 'message' => __( 'Give the message a subject, even a short one.', 'translate-rocket' ) ), 400 );
		}
		if ( strlen( trim( $messaggio ) ) < 20 ) {
			wp_send_json_error( array( 'message' => __( 'Tell me a little more — what you did, and what happened instead.', 'translate-rocket' ) ), 400 );
		}

		// Un sito che vive su localhost, su .local o dentro una rete privata non
		// e' raggiungibile da fuori: la verifica non potrebbe mai riuscire. Meglio
		// dirlo subito e per bene, invece di far partire una richiesta destinata
		// a tornare indietro con un errore oscuro.
		if ( self::sito_privato( home_url( '/' ) ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This site is not reachable from the internet — it looks like a local or staging install. To be sure a ticket is genuine, the support site has to call your site back once, and it cannot do that here. Copy the report and write from the support page instead.', 'translate-rocket' ),
				),
				400
			);
		}

		// Il gettone che il sito di supporto verra' a ricontrollare.
		$token = wp_generate_password( 32, false );
		set_transient( self::TOKEN, $token, 5 * MINUTE_IN_SECONDS );

		$corpo = array(
			'token'   => $token,
			'site'    => home_url( '/' ),
			// La porta buona: admin-ajax non finisce mai in una cache di pagina.
			'verify'  => add_query_arg(
				array(
					'action' => 'trrocket_verify',
					'token'  => $token,
				),
				admin_url( 'admin-ajax.php' )
			),
			'name'    => $nome,
			'email'   => $email,
			'subject' => $oggetto,
			'message' => $messaggio,
			'report'  => $rapporto,
			'version' => TRROCKET_VERSION,
			'website' => '', // Trappola per i robot: un umano non la vede e non la riempie.
		);

		$risposta = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $corpo ),
			)
		);

		if ( is_wp_error( $risposta ) ) {
			delete_transient( self::TOKEN );
			Logger::error( 'support', 'Ticket not sent: ' . $risposta->get_error_message() );
			wp_send_json_error(
				array(
					'message' => __( 'Could not reach the support site from here. Your host may be blocking outgoing requests — you can still copy the report and write from the browser.', 'translate-rocket' ),
				),
				502
			);
		}

		$codice = (int) wp_remote_retrieve_response_code( $risposta );
		$dati   = json_decode( (string) wp_remote_retrieve_body( $risposta ), true );

		if ( 200 !== $codice || empty( $dati['ticket'] ) ) {
			delete_transient( self::TOKEN );
			$perche = isset( $dati['message'] ) ? (string) $dati['message'] : '';
			Logger::error( 'support', 'Ticket refused (' . $codice . '): ' . $perche );
			wp_send_json_error(
				array(
					'message' => '' !== $perche
						? $perche
						: __( 'The support site refused the message. Please copy the report and write from the browser instead.', 'translate-rocket' ),
				),
				$codice >= 400 ? $codice : 502
			);
		}

		set_transient( 'trrocket_ticket_last', time(), self::PAUSA );
		delete_transient( self::TOKEN );
		Logger::info( 'support', 'Ticket #' . (int) $dati['ticket'] . ' opened from this site.' );

		wp_send_json_success(
			array(
				'ticket' => (int) $dati['ticket'],
				'url'    => isset( $dati['url'] ) ? esc_url_raw( (string) $dati['url'] ) : '',
			)
		);
	}


	/**
	 * Whether this site lives somewhere the outside world cannot reach it:
	 * localhost, a made-up development suffix, or a private network address.
	 *
	 * @param string $url The site's home URL.
	 */
	private static function sito_privato( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return true;
		}
		$host = strtolower( $host );

		// Un nome senza punti (localhost, "sitoweb") non e' un dominio pubblico.
		if ( 'localhost' === $host || false === strpos( $host, '.' ) ) {
			return true;
		}
		foreach ( array( '.local', '.localhost', '.test', '.example', '.invalid', '.internal' ) as $coda ) {
			if ( substr( $host, -strlen( $coda ) ) === $coda ) {
				return true;
			}
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}

	/**
	 * The form, on the Diagnostics page.
	 *
	 * @param string $rapporto The technical report, already stripped of keys.
	 */
	public static function render_form( string $rapporto ): void {
		// Su un sito che il mondo esterno non puo' raggiungere il modulo non
		// potrebbe funzionare: meglio dirlo prima, non dopo un clic a vuoto.
		if ( self::sito_privato( home_url( '/' ) ) ) {
			?>
			<div class="notice notice-info inline" style="margin:0">
				<p>
					<strong><?php esc_html_e( 'Sending from here is not available on this install.', 'translate-rocket' ); ?></strong><br>
					<?php esc_html_e( 'To be sure a ticket really comes from a WordPress site and not from a spam robot, the support site calls your site back once. This install is local or private, so it cannot be reached from the outside. Copy the report above and write from the support page — it gets to the same place.', 'translate-rocket' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		$utente = wp_get_current_user();
		?>
		<div class="trr-ticket">
			<p class="description" style="margin:0 0 12px">
				<?php esc_html_e( 'Or write to me from here. Nothing leaves your site until you press Send, and what gets sent is exactly what you can read on this page — no keys, no page content, no visitor data.', 'translate-rocket' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="trr-tk-name"><?php esc_html_e( 'Your name', 'translate-rocket' ); ?></label></th>
					<td><input type="text" id="trr-tk-name" class="regular-text" value="<?php echo esc_attr( $utente->display_name ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="trr-tk-email"><?php esc_html_e( 'Your email', 'translate-rocket' ); ?></label></th>
					<td>
						<input type="email" id="trr-tk-email" class="regular-text" value="<?php echo esc_attr( $utente->user_email ); ?>" />
						<p class="description"><?php esc_html_e( 'This is where my answer goes. It is used for nothing else.', 'translate-rocket' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="trr-tk-subject"><?php esc_html_e( 'Subject', 'translate-rocket' ); ?></label></th>
					<td><input type="text" id="trr-tk-subject" class="large-text" placeholder="<?php esc_attr_e( 'In a few words, what is wrong?', 'translate-rocket' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="trr-tk-message"><?php esc_html_e( 'What happened', 'translate-rocket' ); ?></label></th>
					<td>
						<textarea id="trr-tk-message" rows="6" class="large-text" placeholder="<?php esc_attr_e( 'What you were doing, what you expected, and what happened instead.', 'translate-rocket' ); ?>"></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Technical report', 'translate-rocket' ); ?></th>
					<td>
						<label><input type="checkbox" id="trr-tk-report" checked /> <?php esc_html_e( 'Attach the report above (recommended — it usually saves a round of questions)', 'translate-rocket' ); ?></label>
					</td>
				</tr>
			</table>
			<?php // Trappola per i robot: nascosta a chi guarda e a chi legge con lo schermo, irresistibile per chi compila tutto. ?>
			<div class="trr-hp" aria-hidden="true">
				<label><?php esc_html_e( 'Leave this field empty', 'translate-rocket' ); ?>
					<input type="text" id="trr-tk-hp" tabindex="-1" autocomplete="off" />
				</label>
			</div>
			<p>
				<button type="button" class="button button-primary" id="trr-tk-send"><?php esc_html_e( 'Send to TranslateRocket support', 'translate-rocket' ); ?></button>
				<span id="trr-tk-status" class="description" style="margin-left:8px" aria-live="polite"></span>
			</p>
			<textarea id="trr-tk-report-src" hidden><?php echo esc_textarea( $rapporto ); ?></textarea>
		</div>
		<?php
		wp_localize_script(
			'trrocket-admin-info',
			'TRRocketTicket',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'trrocket_ticket' ),
				'i18n'  => array(
					'sending' => __( 'Sending…', 'translate-rocket' ),
					'noemail' => __( 'I need an email address to answer you.', 'translate-rocket' ),
					'nosubj'  => __( 'Give it a subject first.', 'translate-rocket' ),
					'nomsg'   => __( 'Write a little more about what happened.', 'translate-rocket' ),
					/* translators: %d: ticket number. */
					'done'    => __( 'Sent — your ticket is #%d. My answer arrives by email.', 'translate-rocket' ),
					'failed'  => __( 'It did not go through.', 'translate-rocket' ),
				),
			)
		);
	}
}
