<?php
/**
 * Review screen for the per-language pages left by Polylang, WPML or Bogo.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Importers\CopyCleanup;
use TranslateRocket\Importers\ImporterInterface;
use TranslateRocket\Importers\Importers;
use TranslateRocket\Importers\ProvidesCopies;
use TranslateRocket\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every copy with what was found and a choice — leave it, move it to the
 * Trash, or keep it as an independent copy — and applies the choices. The list
 * itself is the preview: nothing happens until the owner presses the button.
 */
class CopyCleanupAdmin {

	/**
	 * Hook into wp-admin.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_apply' ) );
		// The copies a deactivated plugin left behind look like any other page in the
		// Pages list: label them, and say where to tidy them up.
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'leftovers_notice' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'forget_cache' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'forget_cache' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'forget_cache' ) );
	}

	/**
	 * A page changed status (trashed, restored, published): the cached map is stale.
	 */
	public static function forget_cache(): void {
		CopyCleanup::forget_leftovers();
	}

	/**
	 * "Left by Polylang · Português — original: About us" next to a leftover copy.
	 *
	 * @param mixed $states Post states.
	 * @param mixed $post   The row's post.
	 * @return mixed
	 */
	public function post_states( $states, $post ) {
		if ( ! is_array( $states ) || ! $post instanceof \WP_Post || ! current_user_can( 'manage_options' ) ) {
			return $states;
		}
		$map = CopyCleanup::leftovers();
		if ( ! isset( $map[ (int) $post->ID ] ) ) {
			return $states;
		}
		list( $source_id, $lang, $label ) = $map[ (int) $post->ID ];
		$title = get_the_title( $source_id );
		$states['trrocket_copy'] = sprintf(
			/* translators: 1: plugin name, 2: language name, 3: title of the original page. */
			__( 'Left by %1$s · %2$s — original: %3$s', 'translate-rocket' ),
			$label,
			Languages::label( $lang ),
			'' !== $title ? $title : '#' . (int) $source_id
		);
		return $states;
	}

	/**
	 * On the Pages/Posts lists and the Dashboard: how many leftover copies there are,
	 * and the button that leads to the review.
	 */
	public function leftovers_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'edit', 'dashboard' ), true ) ) {
			return;
		}
		$map = CopyCleanup::leftovers();
		if ( empty( $map ) ) {
			return;
		}
		$by = array();
		foreach ( $map as $row ) {
			$by[ $row[3] ] = array( $row[2], ( $by[ $row[3] ][1] ?? 0 ) + 1 );
		}
		echo '<div class="notice notice-info"><p><strong>TranslateRocket</strong> — ';
		$parts = array();
		foreach ( $by as $id => $info ) {
			$parts[] = sprintf(
				/* translators: 1: number of pages, 2: plugin name. */
				esc_html( _n( '%1$d page left by %2$s is still published in another language', '%1$d pages left by %2$s are still published in other languages', (int) $info[1], 'translate-rocket' ) ),
				(int) $info[1],
				esc_html( $info[0] )
			);
		}
		echo implode( '; ', $parts ) . '. '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		esc_html_e( 'They are marked in the list. TranslateRocket translates the original pages itself, so these copies can go to the Trash (never deleted, restorable) or be kept as independent copies.', 'translate-rocket' );
		echo '</p><p>';
		foreach ( $by as $id => $info ) {
			echo '<a class="button button-primary" style="margin-right:8px" href="' . esc_url( self::review_url( (string) $id ) ) . '">';
			printf(
				/* translators: %s: plugin name. */
				esc_html__( 'Review the pages left by %s', 'translate-rocket' ),
				esc_html( $info[0] )
			);
			echo '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Address of the review screen for an importer.
	 */
	public static function review_url( string $importer_id ): string {
		return add_query_arg(
			array(
				'page'       => 'translate-rocket-import',
				'trr_copies' => $importer_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The importer whose copies were asked for on this request, or null.
	 */
	public static function requested(): ?ProvidesCopies {
		$id = isset( $_GET['trr_copies'] ) ? sanitize_key( wp_unslash( $_GET['trr_copies'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $id ) {
			return null;
		}
		$importer = Importers::get( $id );
		return ( $importer instanceof ProvidesCopies && $importer->is_available() ) ? $importer : null;
	}

	/**
	 * On the Import page, under an importer: how many copies are left, and the way in.
	 */
	public static function intro( ImporterInterface $importer ): void {
		if ( ! ( $importer instanceof ProvidesCopies ) ) {
			return;
		}
		$n = CopyCleanup::open_count( $importer );
		if ( $n <= 0 ) {
			return;
		}
		if ( \TranslateRocket\Coexistence::importer_active( $importer->id() ) ) {
			self::still_active( $importer->label() );
			return;
		}
		echo '<p>';
		printf(
			/* translators: 1: source plugin name, 2: number of pages. */
			esc_html( _n( '%1$s also keeps %2$d separate page for another language. Once imported, TranslateRocket translates the original page itself, so you can tidy it up.', '%1$s also keeps %2$d separate pages for other languages. Once imported, TranslateRocket translates the original pages themselves, so you can tidy them up.', $n, 'translate-rocket' ) ),
			esc_html( $importer->label() ),
			(int) $n
		);
		echo '</p><p><a class="button" href="' . esc_url( self::review_url( $importer->id() ) ) . '">';
		esc_html_e( 'Review the separate pages', 'translate-rocket' );
		echo '</a></p>';
	}

	/**
	 * Why the separate pages cannot be tidied up yet.
	 *
	 * @param string $label Source plugin name.
	 */
	private static function still_active( string $label ): void {
		echo '<p>';
		printf(
			/* translators: %1$s: source plugin name, e.g. "Polylang". */
			esc_html__( '%1$s is still active, so its separate pages are still the ones your visitors see in the other languages. Deactivate %1$s first, then come back here to tidy them up.', 'translate-rocket' ),
			esc_html( $label )
		);
		echo '</p>';
	}

	/**
	 * The review screen.
	 */
	public static function render( ProvidesCopies $importer ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- disabled on some hosts; failing silently is fine.
		}
		$rows  = CopyCleanup::rows( $importer, true );
		$label = $importer->label();
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-import' ) ); ?>">&larr; <?php esc_html_e( 'Back to Import', 'translate-rocket' ); ?></a></p>
		<h1 class="trr-page-title">
			<?php
			/* translators: %s: source plugin name. */
			printf( esc_html__( 'Separate pages left by %s', 'translate-rocket' ), esc_html( $label ) );
			?>
		</h1>

		<?php self::done_notice(); ?>

		<?php
		if ( \TranslateRocket\Coexistence::importer_active( $importer->id() ) ) {
			echo '<div class="trrocket-card">';
			self::still_active( $label );
			echo '</div>';
			return;
		}
		?>

		<div class="trrocket-card">
			<p>
				<?php
				/* translators: %s: source plugin name. */
				printf( esc_html__( '%s kept a separate page for each language. TranslateRocket translates the original page itself, so each of these is now a second place to edit the same page — and a second page for search engines to find.', 'translate-rocket' ), esc_html( $label ) );
				?>
			</p>
			<p><?php esc_html_e( 'Nothing is deleted: pages go to the Trash, where you can restore them, and their old address sends visitors and search engines to the translated page.', 'translate-rocket' ); ?>
			<?php esc_html_e( 'Changed your mind? Restore a page from the Trash and it is back at its old address at once, exactly as before.', 'translate-rocket' ); ?></p>

			<?php if ( empty( $rows ) ) : ?>
				<p><strong><?php esc_html_e( 'There are no separate pages left to review.', 'translate-rocket' ); ?></strong></p>
			</div>
				<?php
				return;
			endif;

			$ready = count( array_filter( $rows, static function ( $r ) { return 'ready' === $r['status']; } ) );
			?>
			<p>
				<?php
				/* translators: 1: pages that can go to the Trash, 2: all pages listed. */
				printf( esc_html__( '%1$d of %2$d can go to the Trash right away. The others are set to be kept — read why next to each one.', 'translate-rocket' ), (int) $ready, count( $rows ) );
				?>
			</p>

			<form method="post" action="" onsubmit="return window.confirm('<?php echo esc_js( __( 'Apply these choices? Pages set to "Move to Trash" can be restored from the Trash.', 'translate-rocket' ) ); ?>');">
				<?php wp_nonce_field( 'trrocket_copies', 'trrocket_copies_nonce' ); ?>
				<input type="hidden" name="importer" value="<?php echo esc_attr( $importer->id() ); ?>" />
				<div style="overflow-x:auto">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Original page', 'translate-rocket' ); ?></th>
							<th><?php esc_html_e( 'Language', 'translate-rocket' ); ?></th>
							<th><?php esc_html_e( 'Separate page', 'translate-rocket' ); ?></th>
							<th><?php esc_html_e( 'What we found', 'translate-rocket' ); ?></th>
							<th><?php esc_html_e( 'What to do', 'translate-rocket' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php self::row( $row ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p><?php submit_button( __( 'Apply', 'translate-rocket' ), 'primary', 'submit', false ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * One table row.
	 *
	 * @param array<string,mixed> $row A CopyCleanup row.
	 */
	private static function row( array $row ): void {
		$copy_id = (int) $row['copy'];
		$status  = (string) $row['status'];

		$can_trash = 'ready' === $status;
		$can_adopt = 'lang_off' !== $status && ! $row['has_copy'];
		if ( $can_trash ) {
			$default = 'trash';
		} elseif ( 'partial' === $status && $can_adopt ) {
			$default = 'adopt';
		} else {
			$default = 'leave';
		}

		$name    = 'trr_copy[' . $copy_id . ']';
		$choices = array(
			'leave' => array( __( 'Leave it as it is', 'translate-rocket' ), true ),
			'trash' => array( __( 'Move to Trash', 'translate-rocket' ), $can_trash ),
			'adopt' => array( __( 'Keep as independent copy', 'translate-rocket' ), $can_adopt ),
		);
		?>
		<tr>
			<td><?php self::post_link( (int) $row['source'] ); ?></td>
			<td><?php echo esc_html( Languages::label( (string) $row['lang'] ) ); ?></td>
			<td><?php self::post_link( $copy_id ); ?></td>
			<td>
				<?php
				if ( 'ready' === $status ) {
					echo '&#10003; ' . esc_html__( 'Everything it says is already in TranslateRocket.', 'translate-rocket' );
				} elseif ( 'partial' === $status ) {
					esc_html_e( 'Its text is laid out differently from the original, so it could not be matched line by line. Keep it as an independent copy to keep that text.', 'translate-rocket' );
				} elseif ( 'not_imported' === $status ) {
					printf(
						/* translators: %d: number of texts. */
						esc_html( _n( '%d text is not in TranslateRocket yet. Run the import first.', '%d texts are not in TranslateRocket yet. Run the import first.', (int) $row['missing'], 'translate-rocket' ) ),
						(int) $row['missing']
					);
				} else {
					printf(
						/* translators: %s: language name. */
						esc_html__( '%s is not one of your TranslateRocket languages: moving this page to the Trash would take it off the site. Add the language in Settings first.', 'translate-rocket' ),
						esc_html( Languages::label( (string) $row['lang'] ) )
					);
				}
				if ( $row['has_copy'] ) {
					echo '<br>' . esc_html__( 'This page already has an independent copy in this language.', 'translate-rocket' );
				}
				if ( ! empty( $row['menus'] ) ) {
					echo '<br>';
					printf(
						/* translators: %s: menu names. */
						esc_html__( 'In the menu: %s. The item disappears from that menu if the page goes to the Trash.', 'translate-rocket' ),
						esc_html( implode( ', ', (array) $row['menus'] ) )
					);
				}
				if ( (int) $row['links'] > 0 ) {
					echo '<br>';
					printf(
						/* translators: %d: number of pages. */
						esc_html( _n( 'Linked from %d page: the link will lead to the translated page.', 'Linked from %d pages: the links will lead to the translated page.', (int) $row['links'], 'translate-rocket' ) ),
						(int) $row['links']
					);
				}
				?>
			</td>
			<td>
				<?php foreach ( $choices as $value => $choice ) : ?>
					<label style="display:block;white-space:nowrap<?php echo $choice[1] ? '' : ';opacity:.5'; ?>">
						<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $default, $value ); ?> <?php disabled( ! $choice[1] ); ?> />
						<?php echo esc_html( $choice[0] ); ?>
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A post title linked to its edit screen, plus a "view" link when it is public.
	 */
	private static function post_link( int $post_id ): void {
		$title = get_the_title( $post_id );
		$title = '' !== $title ? $title : __( '(no title)', 'translate-rocket' );
		$edit  = get_edit_post_link( $post_id );
		if ( $edit ) {
			echo '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $title ) . '</strong></a>';
		} else {
			echo '<strong>' . esc_html( $title ) . '</strong>';
		}
		echo ' <span class="description">#' . (int) $post_id . '</span>';
		if ( 'publish' === get_post_status( $post_id ) ) {
			echo '<br><a href="' . esc_url( (string) get_permalink( $post_id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'translate-rocket' ) . '</a>';
		}
	}

	/**
	 * What the last "Apply" did.
	 */
	private static function done_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['trr_trashed'], $_GET['trr_adopted'], $_GET['trr_skipped'] ) ) {
			return;
		}
		$trashed = (int) $_GET['trr_trashed'];
		$adopted = (int) $_GET['trr_adopted'];
		$skipped = (int) $_GET['trr_skipped'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: 1: pages moved to the Trash, 2: pages kept as independent copies. */
			esc_html__( 'Moved to the Trash: %1$d. Kept as independent copies: %2$d.', 'translate-rocket' ),
			(int) $trashed,
			(int) $adopted
		);
		if ( $trashed > 0 ) {
			echo ' ' . esc_html__( 'You can restore them from the Trash of Pages or Posts; their old addresses now lead to the translated pages.', 'translate-rocket' );
		}
		if ( $skipped > 0 ) {
			echo ' ';
			printf(
				/* translators: %d: number of pages. */
				esc_html( _n( '%d page was left alone because it changed in the meantime — check it below.', '%d pages were left alone because they changed in the meantime — check them below.', $skipped, 'translate-rocket' ) ),
				(int) $skipped
			);
		}
		echo '</p></div>';
	}

	/**
	 * Apply the choices posted from the review screen.
	 */
	public function maybe_apply(): void {
		if ( ! isset( $_POST['trrocket_copies_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_copies_nonce'] ) ), 'trrocket_copies' ) ) {
			return;
		}

		$id       = isset( $_POST['importer'] ) ? sanitize_key( wp_unslash( $_POST['importer'] ) ) : '';
		$importer = Importers::get( $id );
		if ( ! ( $importer instanceof ProvidesCopies ) || ! $importer->is_available() ) {
			return;
		}
		// While that plugin runs, its separate pages are still the ones visitors see.
		if ( \TranslateRocket\Coexistence::importer_active( $id ) ) {
			return;
		}

		$choices = array();
		$posted  = isset( $_POST['trr_copy'] ) && is_array( $_POST['trr_copy'] ) ? wp_unslash( $_POST['trr_copy'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitize_key'd and whitelisted below.
		foreach ( $posted as $copy_id => $choice ) {
			$choice = sanitize_key( (string) $choice );
			if ( (int) $copy_id > 0 && in_array( $choice, array( 'trash', 'adopt' ), true ) ) {
				$choices[ (int) $copy_id ] = $choice;
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- disabled on some hosts; failing silently is fine.
		}
		$done = CopyCleanup::apply( $importer, $choices );

		wp_safe_redirect(
			add_query_arg(
				array(
					'trr_trashed' => $done['trashed'],
					'trr_adopted' => $done['adopted'],
					'trr_skipped' => $done['skipped'],
				),
				self::review_url( $id )
			)
		);
		exit;
	}
}
