<?php
/**
 * The «Search» screen (1.8.8, 16/10/2026, Federico: «a tab of its own after Switcher, with styles and a preview, the
 * width, the placeholder, several profiles — header, footer, shortcode, burger menu — shown on phone, desktop or both;
 * complete and done well»).
 *
 * Like the switcher: profiles (default + the ones you add), each one search box in one place, with its own look,
 * its own search options and a live preview at desktop and phone width.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Frontend\SearchBox;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Search box screen.
 */
class SearchPage {

	const SLUG   = 'translate-rocket-search';
	const NONCE  = 'trrocket_search_page';

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_init', array( $this, 'save' ) );
	}

	/**
	 * Menu entry, right after Switcher.
	 */
	public function menu(): void {
		add_submenu_page( 'translate-rocket', __( 'Search box', 'translate-rocket' ), __( 'Search', 'translate-rocket' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * The places a box can go, with the words a person reads.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function places(): array {
		return array(
			'none'   => array( __( 'Only where I add it — shortcode / block', 'translate-rocket' ), __( 'Paste the shortcode in a page, a widget or your builder, or add the block «TranslateRocket search».', 'translate-rocket' ) ),
			'menu'   => array( __( 'In my header menu', 'translate-rocket' ), __( 'At the end of the menu the language switcher uses (Appearance → Menus decides which).', 'translate-rocket' ) ),
			'mobile' => array( __( 'In my phone menu (burger)', 'translate-rocket' ), __( 'Only in the theme\'s phone menu, the one behind the ☰ button.', 'translate-rocket' ) ),
			'footer' => array( __( 'At the bottom of every page', 'translate-rocket' ), __( 'A row at the end of every page, before the theme\'s footer.', 'translate-rocket' ) ),
		);
	}

	/**
	 * Save, add or delete a profile (POST from this screen).
	 */
	public function save(): void {
		if ( ! isset( $_POST['trrocket_search_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trrocket_search_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		$all    = Settings::get();
		$extras = isset( $all['search_boxes'] ) && is_array( $all['search_boxes'] ) ? $all['search_boxes'] : array();
		$back   = admin_url( 'admin.php?page=' . self::SLUG );
		if ( ! empty( $_POST['sb_new_profile'] ) ) {
			$id = sanitize_key( wp_unslash( $_POST['sb_new_profile'] ) );
			if ( '' !== $id && 'default' !== $id && ! isset( $extras[ $id ] ) ) {
				$extras[ $id ]       = SearchBox::clean( array( 'style' => 'icon', 'autocomplete' => 1, 'filter_types' => 1, 'filter_languages' => 1 ) );
				$all['search_boxes'] = $extras;
				Settings::update( $all );
				$back = add_query_arg( 'profile', $id, $back );
			}
		} elseif ( ! empty( $_POST['sb_delete_profile'] ) ) {
			$id = sanitize_key( wp_unslash( $_POST['sb_delete_profile'] ) );
			unset( $extras[ $id ] );
			$all['search_boxes'] = $extras;
			Settings::update( $all );
		} elseif ( isset( $_POST['sb_profile'] ) ) {
			$id    = sanitize_key( wp_unslash( $_POST['sb_profile'] ) );
			$pulito = SearchBox::clean( isset( $_POST['search_box'] ) ? wp_unslash( $_POST['search_box'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned field by field.
			if ( 'default' === $id || '' === $id ) {
				$all['search_box'] = $pulito;
			} elseif ( isset( $extras[ $id ] ) ) {
				$extras[ $id ]       = $pulito;
				$all['search_boxes'] = $extras;
			}
			// Only one profile in the header menu, one in the phone menu, one at the bottom: the saved one takes the place.
			foreach ( array( 'menu', 'mobile', 'footer' ) as $posto ) {
				if ( $pulito['place'] !== $posto ) {
					continue;
				}
				if ( 'default' !== $id && ( $all['search_box']['place'] ?? '' ) === $posto ) {
					$all['search_box']['place'] = 'none';
				}
				foreach ( $all['search_boxes'] ?? array() as $pid => $p ) {
					if ( (string) $pid !== $id && ( $p['place'] ?? '' ) === $posto ) {
						$all['search_boxes'][ $pid ]['place'] = 'none';
					}
				}
			}
			Settings::update( $all );
			\TranslateRocket\Cache::flush();
			$back = add_query_arg( array( 'profile' => $id, 'saved' => 1 ), $back );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * The screen.
	 */
	public function render(): void {
		$all     = Settings::get();
		$extras  = isset( $all['search_boxes'] ) && is_array( $all['search_boxes'] ) ? $all['search_boxes'] : array();
		$profile = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : 'default'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'default' !== $profile && ! isset( $extras[ $profile ] ) ) {
			$profile = 'default';
		}
		$s     = SearchBox::settings( $profile );
		$base  = admin_url( 'admin.php?page=' . self::SLUG );
		$short = 'default' === $profile ? '[translaterocket_search]' : '[translaterocket_search id="' . $profile . '"]';
		$tipi  = SearchBox::type_choices();
		$info  = function ( string $t ): void {
			Admin::info_static( $t );
		};
		?>
		<div class="wrap trrocket-wrap">
			<?php Admin::header( self::SLUG ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'Search box', 'translate-rocket' ); ?></h1>
			<p class="trrocket-tagline"><?php esc_html_e( 'A search box that always searches in the language of the page. Make profiles (header, footer, phone menu…), each with its own look, its own options and a live preview.', 'translate-rocket' ); ?></p>
			<?php if ( ! \TranslateRocket\Frontend\Search::enabled() ) : ?>
				<div class="notice notice-warning inline"><p><?php
					printf(
						/* translators: %s: link to the Languages screen. */
						wp_kses( __( '«Search in every language» is off on %s: the box would search only the site\'s own language.', 'translate-rocket' ), array( 'a' => array( 'href' => array() ) ) ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=translate-rocket' ) ) . '">' . esc_html__( 'Languages & general', 'translate-rocket' ) . '</a>'
					);
				?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved. The preview below shows it as it is now.', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>

			<div class="trrocket-card" style="max-width:none">
				<p class="trr-sw-profilebar">
					<strong><?php esc_html_e( 'Profiles', 'translate-rocket' ); ?></strong>
					<a class="button <?php echo 'default' === $profile ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Default', 'translate-rocket' ); ?></a>
					<?php foreach ( array_keys( $extras ) as $pid ) : ?>
						<a class="button <?php echo (string) $pid === $profile ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'profile', $pid, $base ) ); ?>"><?php echo esc_html( (string) $pid ); ?></a>
					<?php endforeach; ?>
					<form method="post" action="" style="display:inline-block;margin-left:8px">
						<?php wp_nonce_field( self::NONCE, 'trrocket_search_nonce' ); ?>
						<input type="text" name="sb_new_profile" class="regular-text" style="width:140px" placeholder="<?php esc_attr_e( 'header, footer…', 'translate-rocket' ); ?>" />
						<button type="submit" class="button"><?php esc_html_e( '+ Add profile', 'translate-rocket' ); ?></button>
					</form>
				</p>
				<p class="description">
					<code><?php echo esc_html( $short ); ?></code>
					<?php esc_html_e( '— the shortcode of this profile; the block «TranslateRocket search» has a profile choice too.', 'translate-rocket' ); ?>
					<?php if ( 'default' !== $profile ) : ?>
						<form method="post" action="" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this profile?', 'translate-rocket' ) ); ?>');">
							<?php wp_nonce_field( self::NONCE, 'trrocket_search_nonce' ); ?>
							<input type="hidden" name="sb_delete_profile" value="<?php echo esc_attr( $profile ); ?>" />
							<button type="submit" class="button-link" style="color:#b32d2e"><?php esc_html_e( 'Delete profile', 'translate-rocket' ); ?></button>
						</form>
					<?php endif; ?>
				</p>
			</div>

			<div class="trrocket-card" style="max-width:none">
				<h2><?php esc_html_e( 'Preview', 'translate-rocket' ); ?> <?php $info( __( 'The box as your visitors see it, with the look and the options of this profile, at computer and phone width. It follows your changes as you make them; save to keep them.', 'translate-rocket' ) ); ?></h2>
				<div class="trr-sbp">
					<div class="trr-sbp-one"><div class="trr-sbp-label"><?php esc_html_e( 'Computer', 'translate-rocket' ); ?></div><div class="trr-sbp-frame trr-sbp-desktop" id="trr-sbp-desktop"><?php echo ( new SearchBox() )->render( array( 'id' => $profile, 'preview' => '1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped by render(). ?></div></div>
					<div class="trr-sbp-one"><div class="trr-sbp-label"><?php esc_html_e( 'Phone', 'translate-rocket' ); ?></div><div class="trr-sbp-frame trr-sbp-phone" id="trr-sbp-phone"><?php echo ( new SearchBox() )->render( array( 'id' => $profile, 'preview' => '1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
				</div>
			</div>

			<form method="post" action="" id="trr-sb-form">
				<?php wp_nonce_field( self::NONCE, 'trrocket_search_nonce' ); ?>
				<input type="hidden" name="sb_profile" value="<?php echo esc_attr( $profile ); ?>" />
				<div class="trrocket-card" style="max-width:none">
					<h2><?php esc_html_e( 'Placement', 'translate-rocket' ); ?> <?php $info( __( 'Each profile is one search box in one place. Only one profile can be in the header menu, one in the phone menu and one at the bottom: choosing a place takes it from the profile that had it.', 'translate-rocket' ) ); ?></h2>
					<?php foreach ( self::places() as $k => $p ) : ?>
						<p><label><input type="radio" name="search_box[place]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['place'], $k ); ?> /> <strong><?php echo esc_html( $p[0] ); ?></strong> <span class="description" style="display:block;margin:2px 0 0 24px"><?php echo esc_html( $p[1] ); ?></span></label></p>
					<?php endforeach; ?>
					<p class="description"><?php
						printf(
							/* translators: %s: link to Appearance → Menus. */
							wp_kses( __( 'Which menu is the header menu, and which the phone menu, is decided in %s (the menu assigned to the theme\'s main place, and to its phone place when it has one).', 'translate-rocket' ), array( 'a' => array( 'href' => array() ) ) ),
							'<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '">' . esc_html__( 'Appearance → Menus', 'translate-rocket' ) . '</a>'
						);
					?></p>
					<p>
						<label><?php esc_html_e( 'Shown on', 'translate-rocket' ); ?>
							<select name="search_box[visibility]" id="trr-sb-visibility">
								<option value="both" <?php selected( $s['visibility'], 'both' ); ?>><?php esc_html_e( 'Computer and phone', 'translate-rocket' ); ?></option>
								<option value="desktop" <?php selected( $s['visibility'], 'desktop' ); ?>><?php esc_html_e( 'Computer only', 'translate-rocket' ); ?></option>
								<option value="mobile" <?php selected( $s['visibility'], 'mobile' ); ?>><?php esc_html_e( 'Phone only', 'translate-rocket' ); ?></option>
							</select>
						</label>
						<?php $info( __( 'Make one profile «Computer only» (a field in the header) and another «Phone only» (a magnifier in the burger menu) to have the right box on each screen.', 'translate-rocket' ) ); ?>
					</p>
				</div>

				<div class="trrocket-card" style="max-width:none">
					<h2><?php esc_html_e( 'Look', 'translate-rocket' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Style', 'translate-rocket' ); ?></th>
							<td>
								<?php
								$stili = array(
									'field'   => __( 'Field', 'translate-rocket' ),
									'pill'    => __( 'Rounded field', 'translate-rocket' ),
									'icon'    => __( 'Magnifier that opens a field', 'translate-rocket' ),
									'overlay' => __( 'Magnifier that opens the search over the page', 'translate-rocket' ),
								);
								foreach ( $stili as $k => $nome ) :
									?>
									<label class="trr-sb-style"><input type="radio" name="search_box[style]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['style'], $k ); ?> /> <?php echo esc_html( $nome ); ?></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'In a menu, a magnifier takes the least room; at the bottom of the page, a field is clearer.', 'translate-rocket' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trr-sb-width"><?php esc_html_e( 'Width of the field', 'translate-rocket' ); ?></label></th>
							<td>
								<input type="text" id="trr-sb-width" name="search_box[width]" value="<?php echo esc_attr( $s['width'] ); ?>" class="small-text" style="width:7em" placeholder="14em" />
								<span class="description"><?php esc_html_e( 'Empty = the theme\'s size. Examples: 14em, 240px, 100%.', 'translate-rocket' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="trr-sb-placeholder"><?php esc_html_e( 'Text in the empty field', 'translate-rocket' ); ?></label></th>
							<td>
								<input type="text" id="trr-sb-placeholder" name="search_box[placeholder]" value="<?php echo esc_attr( $s['placeholder'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Search…', 'translate-rocket' ); ?>" />
								<span class="description"><?php esc_html_e( 'Empty = «Search…» already in the language of each page. Your own text is translated like the rest of the site.', 'translate-rocket' ); ?></span>
							</td>
						</tr>
					</table>
				</div>

				<div class="trrocket-card" style="max-width:none">
					<h2><?php esc_html_e( 'What it searches', 'translate-rocket' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Suggestions while typing', 'translate-rocket' ); ?> <?php $info( __( 'From the third letter the matching pages appear under the box, with their title in the page\'s language. Answers are kept ten minutes, so a busy site asks the database once per word.', 'translate-rocket' ) ); ?></th>
							<td>
								<label><input type="checkbox" name="search_box[autocomplete]" value="1" <?php checked( $s['autocomplete'] ); ?> /> <?php esc_html_e( 'Show suggestions', 'translate-rocket' ); ?></label>
								<label style="margin-left:14px"><?php esc_html_e( 'how many:', 'translate-rocket' ); ?> <input type="number" name="search_box[count]" min="3" max="12" value="<?php echo (int) $s['count']; ?>" class="small-text" /></label>
								<p class="trr-consigliato"><?php printf( /* translators: %s: the value to choose. */ esc_html__( 'Recommended: %s', 'translate-rocket' ), '<strong>' . esc_html__( 'on, 6', 'translate-rocket' ) . '</strong>' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Look in', 'translate-rocket' ); ?> <?php $info( __( 'Which kinds of content the box searches. None ticked: everything the site search can find.', 'translate-rocket' ) ); ?></th>
							<td>
								<?php foreach ( $tipi as $tipo => $nome ) : ?>
									<label class="trr-sbox-type"><input type="checkbox" name="search_box[types][]" value="<?php echo esc_attr( $tipo ); ?>" <?php checked( in_array( $tipo, $s['types'], true ) ); ?> /> <?php echo esc_html( $nome ); ?></label>
								<?php endforeach; ?>
								<p class="trr-consigliato"><?php printf( /* translators: %s: the value to choose. */ esc_html__( 'Recommended: %s', 'translate-rocket' ), '<strong>' . esc_html__( 'none ticked (everything)', 'translate-rocket' ) . '</strong>' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Languages', 'translate-rocket' ); ?> <?php $info( __( 'Normally the box searches the translations of the page\'s language: on /de/ a visitor types in German and finds the German pages. «Every language» also looks in the other languages, for a site whose visitors mix them.', 'translate-rocket' ) ); ?></th>
							<td>
								<label><input type="checkbox" name="search_box[all_languages]" value="1" <?php checked( $s['all_languages'] ); ?> /> <?php esc_html_e( 'Search the translations of every language', 'translate-rocket' ); ?></label>
								<p class="trr-consigliato"><?php printf( /* translators: %s: the value to choose. */ esc_html__( 'Recommended: %s', 'translate-rocket' ), '<strong>' . esc_html__( 'off: the language of the page', 'translate-rocket' ) . '</strong>' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Filters for the visitor', 'translate-rocket' ); ?> <?php $info( __( 'Small buttons the visitor can use: the kind of content (All · Posts · Pages · Products…) and «Every language». Always in sight under the box, or behind a «Filters» button.', 'translate-rocket' ) ); ?></th>
							<td>
								<select name="search_box[filters]" id="trr-sb-filters">
									<option value="none" <?php selected( $s['filters'], 'none' ); ?>><?php esc_html_e( 'None', 'translate-rocket' ); ?></option>
									<option value="chips" <?php selected( $s['filters'], 'chips' ); ?>><?php esc_html_e( 'Always in sight, under the box', 'translate-rocket' ); ?></option>
									<option value="dropdown" <?php selected( $s['filters'], 'dropdown' ); ?>><?php esc_html_e( 'Behind a «Filters» button', 'translate-rocket' ); ?></option>
								</select>
								<label style="margin-left:10px"><input type="checkbox" name="search_box[filter_types]" value="1" <?php checked( $s['filter_types'] ); ?> /> <?php esc_html_e( 'by type', 'translate-rocket' ); ?></label>
								<label style="margin-left:10px"><input type="checkbox" name="search_box[filter_languages]" value="1" <?php checked( $s['filter_languages'] ); ?> /> <?php esc_html_e( '«Every language»', 'translate-rocket' ); ?></label>
								<p class="trr-consigliato"><?php printf( /* translators: %s: the value to choose. */ esc_html__( 'Recommended: %s', 'translate-rocket' ), '<strong>' . esc_html__( 'none in a menu; «always in sight» on a search page', 'translate-rocket' ) . '</strong>' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save this profile', 'translate-rocket' ) ); ?>
				</div>
			</form>
		</div>
		<script>
		( function () {
			// the preview follows the form as it changes: style, width, placeholder (the options need a save)
			var f = document.getElementById( 'trr-sb-form' );
			if ( ! f ) { return; }
			function aggiorna() {
				var st = ( f.querySelector( 'input[name="search_box[style]"]:checked' ) || {} ).value || 'field';
				var w = ( document.getElementById( 'trr-sb-width' ) || {} ).value || '';
				var ph = ( document.getElementById( 'trr-sb-placeholder' ) || {} ).value || '';
				document.querySelectorAll( '.trr-sbp-frame .trrocket-search' ).forEach( function ( b ) {
					b.className = b.className.replace( /trrocket-search--\w+/, 'trrocket-search--' + st );
					b.setAttribute( 'data-style', st );
					var p = b.querySelector( '.trrocket-search-panel' ), o = b.querySelector( '.trrocket-search-open' ), form = b.querySelector( '.trrocket-search-form' );
					var apre = 'icon' === st || 'overlay' === st;
					if ( apre && ! o ) {
						o = document.createElement( 'button' ); o.type = 'button'; o.className = 'trrocket-search-open'; o.innerHTML = b.querySelector( '.trrocket-search-go' ).innerHTML;
						p = document.createElement( 'div' ); p.className = 'trrocket-search-panel'; p.hidden = true; p.appendChild( form ); b.appendChild( o ); b.appendChild( p );
					} else if ( ! apre && o ) {
						b.appendChild( form ); o.remove(); p.remove();
					}
					var i = b.querySelector( 'input[type=search]' );
					if ( i ) { i.style.width = w; if ( ph ) { i.placeholder = ph; } }
				} );
			}
			f.addEventListener( 'input', aggiorna );
			f.addEventListener( 'change', aggiorna );
		}() );
		</script>
		<?php
	}
}
