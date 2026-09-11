<?php
/**
 * Menu items shown only in some languages.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * A "Show in" row of language checkboxes on every item in Appearance → Menus.
 *
 * Nothing ticked means every language, which is also how every existing menu
 * item starts: installing an update never hides anything. On the front end,
 * an item whose languages do not include the current one is taken out of the
 * menu together with everything nested under it.
 *
 * Block themes build their navigation with blocks, not with these menus; for
 * them the same result comes from the trrocket-only-xx / trrocket-hide-xx
 * classes handled by Frontend\LanguageOnly.
 */
class MenuLanguages {

	const META = '_trrocket_langs';

	/**
	 * Hook the menu screen (admin) and the menu output (front end).
	 */
	public function register(): void {
		if ( is_admin() ) {
			add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'fields' ), 10, 2 );
			add_action( 'wp_update_nav_menu_item', array( $this, 'save' ), 10, 2 );
			return;
		}
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter' ), 10, 2 );
	}

	/**
	 * Every configured language, default first, offline ones included: a menu
	 * is often prepared before the language it is for goes online.
	 *
	 * @return string[]
	 */
	private static function languages(): array {
		return Plugin::instance()->router()->active_languages();
	}

	/**
	 * The languages an item is limited to (empty: all).
	 *
	 * @param int $item_id Menu item ID.
	 * @return string[]
	 */
	public static function item_languages( int $item_id ): array {
		$saved = get_post_meta( $item_id, self::META, true );
		return is_array( $saved ) ? array_values( array_filter( array_map( 'strval', $saved ) ) ) : array();
	}

	/**
	 * The checkboxes, under the fields WordPress shows for each item.
	 *
	 * @param int      $item_id Menu item ID.
	 * @param \WP_Post $item    Menu item.
	 */
	public function fields( $item_id, $item ): void {
		unset( $item );
		$item_id = (int) $item_id;
		$langs   = self::languages();
		if ( count( $langs ) < 2 ) {
			return;
		}
		$chosen = self::item_languages( $item_id );
		echo '<fieldset class="field-trrocket-langs description description-wide" style="margin:6px 0 10px;">';
		echo '<legend style="margin-bottom:4px;">' . esc_html__( 'Show in', 'translate-rocket' ) . '</legend>';
		// The marker tells save() that this form really carried the checkboxes:
		// menu items are also saved by the Customizer, imports and code, and
		// there "nothing ticked" must not be read as "reset to all languages".
		echo '<input type="hidden" name="trrocket_menu_langs_present[' . esc_attr( (string) $item_id ) . ']" value="1">';
		foreach ( $langs as $code ) {
			printf(
				'<label style="display:inline-block;margin:0 12px 4px 0;"><input type="checkbox" name="trrocket_menu_langs[%1$s][]" value="%2$s"%3$s> %4$s</label>',
				esc_attr( (string) $item_id ),
				esc_attr( $code ),
				checked( in_array( $code, $chosen, true ), true, false ),
				esc_html( Languages::label( $code ) )
			);
		}
		echo '<span class="description" style="display:block;">' . esc_html__( 'None ticked: the item appears in every language.', 'translate-rocket' ) . '</span>';
		echo '</fieldset>';
	}

	/**
	 * Save the checkboxes of one item.
	 *
	 * WordPress has already checked the menu form's nonce before this runs
	 * (check_admin_referer( 'update-nav_menu' ) in nav-menus.php); the
	 * capability is checked again here because the hook fires for every caller.
	 *
	 * @param int $menu_id Menu ID.
	 * @param int $item_id Menu item ID.
	 */
	public function save( $menu_id, $item_id ): void {
		unset( $menu_id );
		$item_id = (int) $item_id;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by core, see above.
		if ( empty( $_POST['trrocket_menu_langs_present'][ $item_id ] ) || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by core, see above.
		$posted = isset( $_POST['trrocket_menu_langs'][ $item_id ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['trrocket_menu_langs'][ $item_id ] ) ) : array();
		$keep   = array_values( array_intersect( self::languages(), $posted ) );

		// Every language ticked is the same as none: store nothing.
		if ( empty( $keep ) || count( $keep ) === count( self::languages() ) ) {
			delete_post_meta( $item_id, self::META );
		} else {
			update_post_meta( $item_id, self::META, $keep );
		}
	}

	/**
	 * Drop the items that do not belong to this language.
	 *
	 * @param array $items Sorted menu items.
	 * @param mixed $args  wp_nav_menu() arguments.
	 * @return array
	 */
	public function filter( $items, $args ) {
		unset( $args );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return $items;
		}
		$current = strtolower( Plugin::instance()->router()->current_language() );
		$gone    = array();

		// Parents usually come before their children, but nothing guarantees it:
		// repeat until a pass removes nothing.
		do {
			$removed = 0;
			foreach ( $items as $k => $item ) {
				$id     = (int) $item->ID;
				$parent = (int) $item->menu_item_parent;
				$langs  = self::item_languages( $id );
				if ( isset( $gone[ $parent ] ) || ( ! empty( $langs ) && ! in_array( $current, $langs, true ) ) ) {
					$gone[ $id ] = true;
					unset( $items[ $k ] );
					++$removed;
				}
			}
		} while ( $removed > 0 );

		if ( empty( $gone ) ) {
			return $items;
		}

		// WordPress added "menu-item-has-children" before this filter ran: a
		// parent whose children are all gone would keep its dropdown arrow.
		$with_children = array();
		foreach ( $items as $item ) {
			$with_children[ (int) $item->menu_item_parent ] = true;
		}
		foreach ( $items as $item ) {
			if ( ! isset( $with_children[ (int) $item->ID ] ) && is_array( $item->classes ) ) {
				$item->classes = array_values( array_diff( $item->classes, array( 'menu-item-has-children' ) ) );
			}
		}
		return $items;
	}
}
