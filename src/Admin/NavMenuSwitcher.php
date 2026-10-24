<?php
/**
 * The language switcher as an item of Appearance → Menus (1.8.21).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * 23/10/2026 (piflarnakupuje.top: «if I were you I would make it so that you put the language switcher as option in
 * the WordPress visual menus»). A «Languages» box next to Pages and Custom Links: «Language switcher» goes into any
 * menu, anywhere — top level or inside a submenu, in one menu or in several. On the site the item becomes the languages
 * (Frontend\Switcher::menu_items, URL PLACEHOLDER), drawn like the theme's other items, in the style of the Switcher screen.
 */
class NavMenuSwitcher {

	/** The address the item carries: the site replaces it with the languages. */
	const PLACEHOLDER = '#trrocket-switcher';

	/** The meta box id. */
	const BOX = 'trrocket-nav-languages';

	public function register(): void {
		add_action( 'load-nav-menus.php', array( $this, 'box' ) );
		// WordPress hides new boxes of this screen for whoever never chose its Screen Options: this one is shown.
		add_filter( 'get_user_option_metaboxhidden_nav-menus', array( $this, 'not_hidden' ) );
		// In the menu editor the item says what it is, instead of the «Custom Link» type label.
		add_filter( 'wp_setup_nav_menu_item', array( $this, 'label' ) );
	}

	public function box(): void {
		add_meta_box( self::BOX, __( 'Languages', 'translate-rocket' ), array( $this, 'render' ), 'nav-menus', 'side', 'default' );
	}

	/**
	 * @param mixed $hidden Hidden boxes of the screen.
	 * @return mixed
	 */
	public function not_hidden( $hidden ) {
		return is_array( $hidden ) ? array_values( array_diff( $hidden, array( self::BOX ) ) ) : $hidden;
	}

	/**
	 * @param object $item Menu item.
	 * @return object
	 */
	public function label( $item ) {
		if ( is_object( $item ) && isset( $item->url ) && self::PLACEHOLDER === $item->url && is_admin() ) {
			$item->type_label = __( 'Language switcher', 'translate-rocket' );
		}
		return $item;
	}

	/**
	 * The box: the markup WordPress's menu editor knows (like «Custom Links»), so «Add to Menu» works as for any item.
	 */
	public function render(): void {
		?>
		<div id="trrocket-nav-languages-div" class="posttypediv">
			<p class="description"><?php esc_html_e( 'Put the languages anywhere in this menu: add the item, then drag it where you want it — at the top level or inside a submenu. On the site it becomes your languages, in the style chosen on the Switcher screen.', 'translate-rocket' ); ?></p>
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<li>
						<label class="menu-item-title">
							<input type="checkbox" class="menu-item-checkbox" name="menu-item[-1][menu-item-object-id]" value="-1" checked="checked" /> <?php esc_html_e( 'Language switcher', 'translate-rocket' ); ?>
						</label>
						<input type="hidden" class="menu-item-type" name="menu-item[-1][menu-item-type]" value="custom" />
						<input type="hidden" class="menu-item-title" name="menu-item[-1][menu-item-title]" value="<?php esc_attr_e( 'Languages', 'translate-rocket' ); ?>" />
						<input type="hidden" class="menu-item-url" name="menu-item[-1][menu-item-url]" value="<?php echo esc_attr( self::PLACEHOLDER ); ?>" />
						<input type="hidden" class="menu-item-classes" name="menu-item[-1][menu-item-classes]" value="trrocket-switcher-item" />
					</li>
				</ul>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="add-to-menu">
					<input type="submit" class="button submit-add-to-menu right" value="<?php esc_attr_e( 'Add to Menu' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- the core label. ?>" name="add-trrocket-languages" id="submit-trrocket-nav-languages-div" />
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}
}
