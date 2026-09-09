<?php
/**
 * Growth: help/feedback page, review request and welcome opt-in.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Lead-generation and engagement, all opt-in and dismissible. No data leaves the
 * site automatically: the help/feedback/subscribe actions are links the user
 * chooses to follow (with the site domain passed along).
 */
class Growth {

	const INSTALLED      = 'trrocket_installed';
	const REVIEW_DONE    = 'trrocket_review_dismissed';
	const WELCOME_DONE   = 'trrocket_welcome_dismissed';
	const DONATE_DONE    = 'trrocket_donate_dismissed';
	const SITE           = 'https://translaterocket.com/';
	const REVIEW_URL     = 'https://wordpress.org/support/plugin/translate-rocket/reviews/#new-post';

	/** Ask for a review only once the plugin has actually done something useful. */
	const REVIEW_MIN_STRINGS = 25;
	const REVIEW_MIN_DAYS    = 5;
	/** The welcome notice steps aside after a week so it can't block the rest. */
	const WELCOME_MAX_DAYS   = 7;

	/** True once one of our notices has been printed on this page load. */
	private $notice_shown = false;

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'ensure_install_time' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		// The core "x" only hides a notice for the current page load: without these
		// two it comes back forever and the later notices never get their turn.
		add_action( 'admin_footer', array( $this, 'dismiss_script' ) );
		add_action( 'wp_ajax_trrocket_dismiss_notice', array( $this, 'ajax_dismiss' ) );
	}

	public function ensure_install_time(): void {
		if ( ! get_option( self::INSTALLED ) ) {
			add_option( self::INSTALLED, time() );
		}
	}

	/**
	 * "Help & Feedback" submenu.
	 */
	public function menu(): void {
		add_submenu_page(
			'translate-rocket',
			__( 'Help & Feedback', 'translate-rocket' ),
			__( 'Help & Feedback', 'translate-rocket' ),
			'manage_options',
			'translate-rocket-help',
			array( $this, 'render_help' )
		);
	}

	/**
	 * Site domain, to pass along on outgoing links.
	 */
	private function domain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}

	/**
	 * Build an outgoing link to translaterocket.com with the site domain.
	 *
	 * @param string              $path Path on the site.
	 * @param array<string,string> $args Extra query args.
	 */
	private function link( string $path, array $args = array() ): string {
		$args = array_merge(
			array(
				'utm_source' => 'plugin',
				'site'       => $this->domain(),
			),
			$args
		);
		return add_query_arg( $args, self::SITE . ltrim( $path, '/' ) );
	}

	/**
	 * Dismiss a notice (?trrocket_dismiss=review|welcome with nonce).
	 */
	public function maybe_dismiss(): void {
		if ( ! isset( $_GET['trrocket_dismiss'], $_GET['_wpnonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'trrocket_dismiss' ) ) {
			return;
		}
		$which = sanitize_key( $_GET['trrocket_dismiss'] );
		if ( 'review' === $which ) {
			update_option( self::REVIEW_DONE, 1 );
		} elseif ( 'welcome' === $which ) {
			update_option( self::WELCOME_DONE, 1 );
		} elseif ( 'donate' === $which ) {
			update_option( self::DONATE_DONE, 1 );
		}
	}

	private function dismiss_url( string $which ): string {
		return wp_nonce_url( add_query_arg( 'trrocket_dismiss', $which ), 'trrocket_dismiss' );
	}

	/**
	 * Name of another active translation plugin, or '' if none. Detected by each
	 * plugin's own constant/class so it works regardless of the folder name.
	 */
	private function conflicting_plugin(): string {
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			return 'TranslatePress';
		}
		if ( defined( 'POLYLANG_VERSION' ) || defined( 'POLYLANG_BASENAME' ) ) {
			return 'Polylang';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'WPML';
		}
		if ( defined( 'WEGLOT_VERSION' ) ) {
			return 'Weglot';
		}
		if ( defined( 'GTRANSLATE_VERSION' ) ) {
			return 'GTranslate';
		}
		return '';
	}

	/**
	 * How many strings this site has actually translated.
	 *
	 * Asking for a review on day 14 regardless of use means asking people who
	 * never got anything out of the plugin. Asking after real work gets a real answer.
	 */
	private function translated_count(): int {
		$cached = get_transient( 'trrocket_translated_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		global $wpdb;
		$table = \TranslateRocket\Database::translations_table();
		$n     = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counting our own table, cached below.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		set_transient( 'trrocket_translated_count', $n, HOUR_IN_SECONDS );
		return $n;
	}

	/**
	 * Make the core dismiss button actually stick.
	 */
	public function dismiss_script(): void {
		if ( ! $this->notice_shown || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<script>
		document.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '.notice-dismiss' );
			if ( ! b ) { return; }
			var n = b.closest( '[data-trrocket]' );
			if ( ! n ) { return; }
			var d = new FormData();
			d.append( 'action', 'trrocket_dismiss_notice' );
			d.append( 'which', n.getAttribute( 'data-trrocket' ) );
			d.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'trrocket_dismiss' ) ); ?>' );
			fetch( ajaxurl, { method: 'POST', body: d, credentials: 'same-origin' } );
		} );
		</script>
		<?php
	}

	/**
	 * Record a dismissal coming from the core "x".
	 */
	public function ajax_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '', 403 );
		}
		check_ajax_referer( 'trrocket_dismiss', 'nonce' );
		$which = isset( $_POST['which'] ) ? sanitize_key( wp_unslash( $_POST['which'] ) ) : '';
		$mappa = array(
			'review'  => self::REVIEW_DONE,
			'welcome' => self::WELCOME_DONE,
			'donate'  => self::DONATE_DONE,
		);
		if ( isset( $mappa[ $which ] ) ) {
			update_option( $mappa[ $which ], 1 );
		}
		wp_send_json_success();
	}

	/**
	 * Welcome, review request (after real use) and donation notices.
	 */
	public function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Two translation plugins fighting over the same output cause conflicts.
		$conflict = $this->conflicting_plugin();
		if ( '' !== $conflict ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: the other translation plugin's name. */
				esc_html__( '⚠️ TranslateRocket found another translation plugin active: %s. Running two at once will conflict — please keep only one enabled.', 'translate-rocket' ),
				'<strong>' . esc_html( $conflict ) . '</strong>'
			);
			echo '</p></div>';
			return;
		}

		$installed = (int) get_option( self::INSTALLED );
		$age       = $installed > 0 ? ( time() - $installed ) : 0;

		// The welcome stands aside after a week even if nobody dismissed it, so it
		// can never keep the review request from ever appearing.
		// Non sulla procedura guidata: li' l'utente sta gia' scegliendo le lingue,
		// e un avviso che gli dice di andare da un'altra parte per farlo e' solo
		// un invito a uscire dal percorso appena iniziato.
		$schermata = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$sul_wizard = ( $schermata && false !== strpos( (string) $schermata->id, 'translate-rocket-wizard' ) );

		if ( ! $sul_wizard && ! get_option( self::WELCOME_DONE ) && $age < ( self::WELCOME_MAX_DAYS * DAY_IN_SECONDS ) ) {
			// Finche' la procedura guidata non e' stata completata, e' li' che
			// conviene mandare la gente: fa le stesse scelte, ma accompagnate.
			$primo_passo = get_option( 'trrocket_wizard_done' )
				? admin_url( 'admin.php?page=translate-rocket' )
				: admin_url( 'admin.php?page=translate-rocket-wizard' );
			$etichetta   = get_option( 'trrocket_wizard_done' )
				? __( 'TranslateRocket settings', 'translate-rocket' )
				: __( 'the 1-minute setup', 'translate-rocket' );

			$this->notice_shown = true;
			echo '<div class="notice notice-info is-dismissible" data-trrocket="welcome"><p>';
			echo '🚀 ' . esc_html__( 'Thanks for installing TranslateRocket!', 'translate-rocket' ) . ' ';
			printf(
				/* translators: %s: link to the setup wizard or the settings page. */
				esc_html__( 'Start with %s — pick your languages and you are translating.', 'translate-rocket' ),
				'<a href="' . esc_url( $primo_passo ) . '">' . esc_html( $etichetta ) . '</a>'
			);
			echo ' &middot; <a href="' . esc_url( $this->link( 'news' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get tips & updates', 'translate-rocket' ) . '</a>';
			echo ' &middot; <a href="' . esc_url( $this->dismiss_url( 'welcome' ) ) . '">' . esc_html__( 'Dismiss', 'translate-rocket' ) . '</a>';
			echo '</p></div>';
			return;
		}

		// Ask for a review once the plugin has actually earned it: real translations
		// done, not just a date on the calendar since install.
		$done = $this->translated_count();
		if ( ! get_option( self::REVIEW_DONE )
			&& $age > ( self::REVIEW_MIN_DAYS * DAY_IN_SECONDS )
			&& $done >= self::REVIEW_MIN_STRINGS ) {
			$this->notice_shown = true;
			echo '<div class="notice notice-info is-dismissible" data-trrocket="review"><p>';
			printf(
				/* translators: %s: number of strings translated on this site. */
				esc_html__( 'TranslateRocket has translated %s strings on this site, for free. It has no paid version: reviews are the only thing that helps other people find it. Would you leave one?', 'translate-rocket' ),
				'<strong>' . esc_html( number_format_i18n( $done ) ) . '</strong>'
			);
			echo ' <a class="button button-primary button-small" style="margin-left:6px" href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Write a review', 'translate-rocket' ) . '</a>';
			echo ' &middot; <a href="' . esc_url( $this->link( 'support' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Something is wrong instead', 'translate-rocket' ) . '</a>';
			echo ' &middot; <a href="' . esc_url( $this->dismiss_url( 'review' ) ) . '">' . esc_html__( 'No thanks', 'translate-rocket' ) . '</a>';
			echo '</p></div>';
			return;
		}

		// After 30 days: a gentle, one-time donation nudge (no tracking — just a link).
		if ( ! get_option( self::DONATE_DONE ) && $age > ( 30 * DAY_IN_SECONDS ) ) {
			$this->notice_shown = true;
			echo '<div class="notice notice-success is-dismissible" data-trrocket="donate"><p>';
			echo '❤️ ' . esc_html__( 'You’ve been translating with TranslateRocket for a while now. It’s free forever, and it stays maintained either way — if it saved you a paid plugin, a donation is a nice way to say thanks.', 'translate-rocket' );
			echo ' <a class="button button-primary button-small" style="margin-left:6px" href="' . esc_url( $this->link( 'donate' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Buy me a coffee ☕', 'translate-rocket' ) . '</a>';
			echo ' &middot; <a href="' . esc_url( $this->dismiss_url( 'donate' ) ) . '">' . esc_html__( 'Maybe later', 'translate-rocket' ) . '</a>';
			echo '</p></div>';
		}
	}

	/**
	 * Render the Help & Feedback page.
	 */
	public function render_help(): void {
		?>
		<div class="wrap trrocket-wrap">
			<?php \TranslateRocket\Admin\Admin::header( 'translate-rocket-help' ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'Help & Feedback', 'translate-rocket' ); ?></h1>
			<p class="trrocket-tagline"><?php esc_html_e( 'Need a hand, want a feature, or just want to say hi? Here’s how.', 'translate-rocket' ); ?></p>

			<div class="trrocket-card">
				<h2>🛠️ <?php esc_html_e( 'Want a hand with your site? I can do it for you', 'translate-rocket' ); ?></h2>
				<p><?php esc_html_e( 'I wrote this plugin, and building WordPress sites is my day job — that is how it stays free, and how I make a living. A site built from scratch, a design put right, a multilingual setup done properly, custom development, reviewed translations, or a migration away from a plugin that charges you every month: tell me what you need and I will tell you honestly whether I am the right person for it.', 'translate-rocket' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( $this->link( 'support' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ask me about your site', 'translate-rocket' ); ?></a>
			</div>

			<div class="trrocket-card">
				<h2>💬 <?php esc_html_e( 'Send feedback', 'translate-rocket' ); ?></h2>
				<p><?php esc_html_e( 'Found a bug or have an idea? Tell me — it shapes what gets built next.', 'translate-rocket' ); ?></p>
				<a class="button" href="<?php echo esc_url( $this->link( 'support' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Send feedback', 'translate-rocket' ); ?></a>
			</div>

			<div class="trrocket-card">
				<h2>⭐ <?php esc_html_e( 'Leave a review', 'translate-rocket' ); ?></h2>
				<p><?php esc_html_e( 'If TranslateRocket helps you, a review on WordPress.org means a lot.', 'translate-rocket' ); ?></p>
				<a class="button" href="<?php echo esc_url( self::REVIEW_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Write a review', 'translate-rocket' ); ?></a>
			</div>

			<div class="trrocket-card trrocket-donate">
				<h2>❤️ <?php esc_html_e( 'Support the project', 'translate-rocket' ); ?></h2>
				<p><?php esc_html_e( 'TranslateRocket is 100% free. A small donation keeps it alive and updated.', 'translate-rocket' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( $this->link( 'donate' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Make a donation', 'translate-rocket' ); ?></a>
			</div>
		</div>
		<?php
	}
}
