<?php
/**
 * «Translate with ChatGPT, Gemini, Claude…» on the Import screen (1.8.21).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\ExternalAi;
use TranslateRocket\Languages;
use TranslateRocket\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The box, the downloads, the import (file or pasted answer) in steps, its outcome and its undo.
 */
class ExternalAiPage {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle' ) );
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'translate-rocket-import' ), $args ), admin_url( 'admin.php' ) ) . '#trr-external-ai';
	}

	/**
	 * Downloads, import, steps, undo.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- each branch checks its own nonce.
		if ( isset( $_GET['trrocket_ext_dl'] ) ) {
			check_admin_referer( 'trrocket_ext_dl' );
			$lang = sanitize_key( wp_unslash( $_GET['lang'] ?? '' ) );
			if ( in_array( $lang, (array) Plugin::instance()->router()->secondary_languages(), true ) ) {
				ExternalAi::download( $lang, 'all' === ( $_GET['what'] ?? '' ) ? 'all' : 'missing', max( 0, (int) ( $_GET['part'] ?? 0 ) ), max( 0, min( 2000, (int) ( $_GET['size'] ?? 0 ) ) ) );
			}
			return;
		}
		if ( isset( $_GET['trrocket_ext_undo'] ) ) {
			$b = sanitize_key( wp_unslash( $_GET['trrocket_ext_undo'] ) );
			check_admin_referer( 'trrocket_ext_undo_' . $b );
			$r = ExternalAi::undo_step( $b );
			if ( ! $r['done'] ) {
				Admin::terms_progress( add_query_arg( array( 'page' => 'translate-rocket-import', 'trrocket_ext_undo' => $b, '_wpnonce' => wp_create_nonce( 'trrocket_ext_undo_' . $b ) ), admin_url( 'admin.php' ) ), $r['percent'], $r['restored'] );
			}
			wp_safe_redirect( self::page_url( array( 'ext_undone' => (string) $r['restored'] ) ) );
			exit;
		}
		$go = isset( $_POST['trrocket_ext_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_ext_nonce'] ) ), 'trrocket_ext_import' );
		if ( $go ) {
			$tmp = '';
			if ( ! empty( $_FILES['trrocket_ext_file']['tmp_name'] ) && is_uploaded_file( $_FILES['trrocket_ext_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$tmp = (string) $_FILES['trrocket_ext_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			} elseif ( isset( $_POST['trrocket_ext_text'] ) && '' !== trim( (string) wp_unslash( $_POST['trrocket_ext_text'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$tmp = wp_tempnam( 'trrocket-ext' );
				file_put_contents( $tmp, (string) wp_unslash( $_POST['trrocket_ext_text'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.Security.ValidatedSanitizedInput -- the answer pasted from a chat, checked row by row on import.
			}
			if ( '' === $tmp ) {
				wp_safe_redirect( self::page_url( array( 'ext_error' => rawurlencode( __( 'Choose the file the AI gave back, or paste its answer.', 'translate-rocket' ) ) ) ) );
				exit;
			}
			$r = ExternalAi::start( $tmp, sanitize_key( wp_unslash( $_POST['trrocket_ext_lang'] ?? 'auto' ) ), 'replace' === ( $_POST['trrocket_ext_mode'] ?? '' ) ? 'replace' : 'missing' );
			if ( ! $r['ok'] ) {
				wp_safe_redirect( self::page_url( array( 'ext_error' => rawurlencode( $r['error'] ) ) ) );
				exit;
			}
		} elseif ( ! ( isset( $_GET['trrocket_ext_step'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['trrocket_ext_step'] ) ), 'trrocket_ext_step' ) ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification
		$r = ExternalAi::step();
		if ( ! $r['done'] ) {
			Admin::terms_progress( add_query_arg( array( 'page' => 'translate-rocket-import', 'trrocket_ext_step' => wp_create_nonce( 'trrocket_ext_step' ) ), admin_url( 'admin.php' ) ), $r['percent'], $r['saved'] );
		}
		wp_safe_redirect( self::page_url( array( 'ext_done' => '1' ) ) );
		exit;
	}

	/**
	 * The box on the Import screen.
	 */
	public static function render(): void {
		$router = Plugin::instance()->router();
		$langs  = array_values( array_filter( array_map( 'strval', (array) $router->secondary_languages() ) ) );
		if ( ! $langs ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$lang = isset( $_GET['ext_lang'] ) ? sanitize_key( wp_unslash( $_GET['ext_lang'] ) ) : $langs[0];
		$lang = in_array( $lang, $langs, true ) ? $lang : $langs[0];
		$what = ( isset( $_GET['ext_what'] ) && 'all' === $_GET['ext_what'] ) ? 'all' : 'missing';
		$size = isset( $_GET['ext_size'] ) ? max( 50, min( 1000, (int) $_GET['ext_size'] ) ) : 250;
		?>
		<h1 class="trr-page-title" id="trr-external-ai" style="margin-top:28px"><?php esc_html_e( 'Translate with ChatGPT, Gemini, Claude…', 'translate-rocket' ); ?><?php Admin::info_static( __( 'Use the chat AI you already have, with no API key: download a file of sentences, give it to the AI with the instructions below, and import the file it gives back. Only the missing sentences, to complete a language, or all of them, to have a language checked. Every row is checked on import; one link undoes the whole import.', 'translate-rocket' ) ); ?></h1>
		<div class="trrocket-card">
			<?php
			if ( ! empty( $_GET['ext_error'] ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['ext_error'] ) ) ) ) . '</p></div>';
			}
			if ( isset( $_GET['ext_undone'] ) ) {
				/* translators: %d: translations put back. */
				echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( 'Undone: %d translations are back as they were.', 'translate-rocket' ), (int) $_GET['ext_undone'] ) ) . '</p></div>';
			}
			$last = get_option( ExternalAi::LAST );
			if ( isset( $_GET['ext_done'] ) && is_array( $last ) ) {
				self::outcome( $last );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>
			<h3><?php esc_html_e( '1. Download the sentences', 'translate-rocket' ); ?></h3>
			<style>.trr-ext-pick label,.trr-ext-import label{display:block;margin:6px 0}.trr-ext-pick select,.trr-ext-import select{margin-left:6px}</style>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>#trr-external-ai" class="trr-ext-pick">
				<input type="hidden" name="page" value="translate-rocket-import" />
				<label><?php esc_html_e( 'Language', 'translate-rocket' ); ?>
					<select name="ext_lang">
						<?php foreach ( $langs as $l ) : ?>
							<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $lang, $l ); ?>><?php echo esc_html( Languages::label( $l ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><input type="radio" name="ext_what" value="missing" <?php checked( 'missing', $what ); ?> /> <?php esc_html_e( 'Only the missing sentences (to complete)', 'translate-rocket' ); ?></label>
				<label><input type="radio" name="ext_what" value="all" <?php checked( 'all', $what ); ?> /> <?php esc_html_e( 'All the sentences (to have them checked)', 'translate-rocket' ); ?></label>
				<label><?php esc_html_e( 'Sentences per file', 'translate-rocket' ); ?>
					<select name="ext_size">
						<?php foreach ( array( 100, 250, 500, 1000 ) as $n ) : ?>
							<option value="<?php echo (int) $n; ?>" <?php selected( $size, $n ); ?>><?php echo (int) $n; ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php submit_button( __( 'Show the files', 'translate-rocket' ), 'secondary', '', false ); ?>
			</form>
			<?php
			$tot   = ExternalAi::count( $lang, $what );
			$parts = (int) ceil( $tot / $size );
			$dl    = static function ( int $part, int $sz ) use ( $lang, $what ): string {
				return wp_nonce_url( add_query_arg( array( 'page' => 'translate-rocket-import', 'trrocket_ext_dl' => 1, 'lang' => $lang, 'what' => $what, 'part' => $part, 'size' => $sz ), admin_url( 'admin.php' ) ), 'trrocket_ext_dl' );
			};
			if ( 0 === $tot ) {
				echo '<p class="description">' . esc_html( 'missing' === $what ? __( 'Nothing is missing in this language.', 'translate-rocket' ) : __( 'No sentences yet: scan the site first (Translations screen).', 'translate-rocket' ) ) . '</p>';
			} else {
				echo '<p class="trr-ext-count">' . esc_html(
					1 === $parts
						/* translators: 1: number of sentences, 2: language. */
						? sprintf( __( '%1$d sentences in %2$s, in one file:', 'translate-rocket' ), $tot, Languages::label( $lang ) )
						/* translators: 1: number of sentences, 2: language, 3: number of files. */
						: sprintf( __( '%1$d sentences in %2$s, in %3$d files:', 'translate-rocket' ), $tot, Languages::label( $lang ), $parts )
				) . '</p><p class="trr-ext-files">';
				for ( $i = 0; $i < min( $parts, 200 ); $i++ ) {
					/* translators: %d: file number. */
					echo '<a class="button" href="' . esc_url( $dl( $i, $size ) ) . '">&#11015; ' . esc_html( sprintf( __( 'File %d', 'translate-rocket' ), $i + 1 ) ) . '</a> ';
				}
				echo '</p>';
				if ( $parts > 1 ) {
					echo '<p><a href="' . esc_url( $dl( 0, 0 ) ) . '">' . esc_html__( 'Or everything in one file (for AIs that work on large files)', 'translate-rocket' ) . '</a></p>';
				}
				?>
				<h3><?php esc_html_e( '2. Give each file to the AI with these instructions', 'translate-rocket' ); ?></h3>
				<textarea class="large-text code trr-ext-prompt" rows="9" readonly onclick="this.select()"><?php echo esc_textarea( ExternalAi::prompt( $lang, $what ) ); ?></textarea>
				<p><button type="button" class="button" onclick="var t=this.parentNode.previousElementSibling;t.select();navigator.clipboard?navigator.clipboard.writeText(t.value):document.execCommand('copy');this.textContent=<?php echo esc_attr( wp_json_encode( __( 'Copied ✓', 'translate-rocket' ) ) ); ?>;"><?php esc_html_e( 'Copy the instructions', 'translate-rocket' ); ?></button></p>
				<?php
			}
			?>
			<h3><?php esc_html_e( '3. Import what the AI gives back', 'translate-rocket' ); ?></h3>
			<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" enctype="multipart/form-data" class="trr-ext-import">
				<?php wp_nonce_field( 'trrocket_ext_import', 'trrocket_ext_nonce' ); ?>
				<p><input type="file" name="trrocket_ext_file" accept=".csv,.txt,text/csv,text/plain" /></p>
				<p><textarea name="trrocket_ext_text" rows="5" class="large-text code" placeholder="<?php esc_attr_e( '…or paste here the answer of the AI (the whole CSV)', 'translate-rocket' ); ?>"></textarea></p>
				<p>
					<label><?php esc_html_e( 'Language', 'translate-rocket' ); ?>
						<select name="trrocket_ext_lang">
							<option value="auto"><?php esc_html_e( 'From the file', 'translate-rocket' ); ?></option>
							<?php foreach ( $langs as $l ) : ?>
								<option value="<?php echo esc_attr( $l ); ?>"><?php echo esc_html( Languages::label( $l ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><input type="radio" name="trrocket_ext_mode" value="missing" checked /> <?php esc_html_e( 'Fill only the missing sentences', 'translate-rocket' ); ?></label>
					<label><input type="radio" name="trrocket_ext_mode" value="replace" /> <?php esc_html_e( 'Also replace the translations already there (after a check)', 'translate-rocket' ); ?></label>
				</p>
				<?php submit_button( __( 'Import the translations', 'translate-rocket' ), 'primary', 'trrocket_ext_go', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * What the last import did.
	 *
	 * @param array $last Outcome.
	 */
	private static function outcome( array $last ): void {
		$n    = (array) ( $last['n'] ?? array() );
		$lang = Languages::label( (string) ( $last['lang'] ?? '' ) );
		/* translators: 1: number of translations, 2: language. */
		echo '<div class="notice notice-success inline trr-ext-outcome"><p><strong>' . esc_html( sprintf( __( 'Imported: %1$d translations in %2$s.', 'translate-rocket' ), (int) ( $n['saved'] ?? 0 ), $lang ) ) . '</strong>';
		if ( ! empty( $last['batch'] ) ) {
			echo ' · <a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'translate-rocket-import', 'trrocket_ext_undo' => $last['batch'] ), admin_url( 'admin.php' ) ), 'trrocket_ext_undo_' . $last['batch'] ) ) . '">' . esc_html__( 'Undo', 'translate-rocket' ) . '</a>';
		}
		echo '</p>';
		$why = array(
			'already' => __( 'already translated, left as they were', 'translate-rocket' ),
			'same'    => __( 'left untranslated by the AI', 'translate-rocket' ),
			'tags'    => __( 'tags or placeholders changed by the AI', 'translate-rocket' ),
			'moved'   => __( 'rows moved or source changed by the AI', 'translate-rocket' ),
			'unknown' => __( 'ids not found on this site', 'translate-rocket' ),
			'empty'   => __( 'empty', 'translate-rocket' ),
		);
		$righe = array();
		foreach ( $why as $k => $label ) {
			if ( ! empty( $n[ $k ] ) ) {
				$righe[] = (int) $n[ $k ] . ' ' . $label;
			}
		}
		if ( $righe ) {
			echo '<p>' . esc_html__( 'Skipped:', 'translate-rocket' ) . ' ' . esc_html( implode( ' · ', $righe ) ) . '</p>';
		}
		if ( ! empty( $last['esempi'] ) ) {
			echo '<ul style="margin:0 0 8px 18px;list-style:disc">';
			foreach ( (array) $last['esempi'] as $e ) {
				echo '<li>' . esc_html( ( $why[ $e[0] ] ?? $e[0] ) . ' — #' . (int) $e[1] . ' «' . $e[2] . '»' ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}
