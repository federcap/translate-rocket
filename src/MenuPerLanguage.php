<?php
/**
 * A whole different menu per language (1.8.6).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * «On /de/ use the menu "Hauptmenü"»: for each menu location of the theme (and for the Navigation blocks of
 * block themes) a language can have a menu of its own instead of the translated one. Items of that menu are
 * written in that language, so they are left as they are (not translated). Nothing chosen = the usual menu,
 * translated, with the per-item «Show in» of MenuLanguages still working.
 *
 * Setting «menus_per_language»: array( 'location' => array( 'de' => menu_id ), 'nav:123' => array( 'de' => 456 ) ).
 */
class MenuPerLanguage {

	public function register(): void {
		if ( is_admin() ) {
			return;
		}
		add_filter( 'theme_mod_nav_menu_locations', array( $this, 'locations' ) );
		add_filter( 'render_block_data', array( $this, 'navigation_block' ), 10, 1 );
		add_filter( 'wp_nav_menu_args', array( $this, 'keep_as_written' ) );
	}

	/**
	 * @return array<string,array<string,int>>
	 */
	public static function map(): array {
		$m = Settings::get()['menus_per_language'] ?? array();
		return is_array( $m ) ? $m : array();
	}

	private static function lang(): string {
		$router = Plugin::instance()->router();
		$lang   = (string) $router->current_language();
		return $router->is_default( $lang ) ? '' : $lang;
	}

	/**
	 * Classic menus: the location points to the language's menu.
	 *
	 * @param mixed $locations location => menu id.
	 * @return mixed
	 */
	public function locations( $locations ) {
		$lang = self::lang();
		if ( '' === $lang || ! is_array( $locations ) || Coexistence::on() ) {
			return $locations;
		}
		foreach ( self::map() as $loc => $per ) {
			if ( 0 === strpos( (string) $loc, 'nav:' ) || ! is_array( $per ) ) {
				continue;
			}
			$id = (int) ( $per[ $lang ] ?? 0 );
			if ( $id > 0 && wp_get_nav_menu_object( $id ) ) {
				$locations[ $loc ] = $id;
			}
		}
		return $locations;
	}

	/**
	 * Block themes: a Navigation block that shows navigation N shows the language's navigation instead.
	 *
	 * @param array $block Parsed block.
	 * @return array
	 */
	public function navigation_block( $block ) {
		if ( ! is_array( $block ) || 'core/navigation' !== ( $block['blockName'] ?? '' ) || empty( $block['attrs']['ref'] ) ) {
			return $block;
		}
		$lang = self::lang();
		if ( '' === $lang || Coexistence::on() ) {
			return $block;
		}
		$per = self::map()[ 'nav:' . (int) $block['attrs']['ref'] ] ?? array();
		$id  = is_array( $per ) ? (int) ( $per[ $lang ] ?? 0 ) : 0;
		if ( $id > 0 && 'wp_navigation' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
			$block['attrs']['ref']       = $id;
			$block['attrs']['className'] = trim( (string) ( $block['attrs']['className'] ?? '' ) . ' trrocket-menu-own' );
		}
		return $block;
	}

	/**
	 * A menu chosen for a language is written in it: marked translate="no" so the engine leaves it alone.
	 *
	 * @param array $args wp_nav_menu() arguments.
	 * @return array
	 */
	public function keep_as_written( $args ) {
		$lang = self::lang();
		if ( '' === $lang || empty( $args['theme_location'] ) ) {
			return $args;
		}
		$per = self::map()[ (string) $args['theme_location'] ] ?? array();
		if ( is_array( $per ) && (int) ( $per[ $lang ] ?? 0 ) > 0 ) {
			$wrap               = (string) ( $args['items_wrap'] ?? '<ul id="%1$s" class="%2$s">%3$s</ul>' );
			$args['items_wrap'] = (string) preg_replace( '/^<(\w+)/', '<$1 translate="no"', $wrap, 1 );
		}
		return $args;
	}

	/**
	 * What can be chosen on the settings screen: the theme's menu locations and the site's Navigation menus.
	 *
	 * @return array{places:array<string,string>,menus:array<int,string>,navs:array<int,string>}
	 */
	public static function choices(): array {
		$places = array();
		foreach ( get_registered_nav_menus() as $loc => $label ) {
			$places[ (string) $loc ] = (string) $label;
		}
		$menus = array();
		foreach ( wp_get_nav_menus() as $m ) {
			$menus[ (int) $m->term_id ] = (string) $m->name;
		}
		// Block themes: the Navigation menus (posts), kept apart — their numbers can be the same as a classic menu's.
		$navs = array();
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			foreach ( get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 50 ) ) as $n ) {
				$nome                      = $n->post_title ? (string) $n->post_title : '#' . $n->ID;
				/* translators: %s: name of a Navigation menu of a block theme. */
				$places[ 'nav:' . $n->ID ] = sprintf( __( 'Navigation «%s»', 'translate-rocket' ), $nome );
				$navs[ (int) $n->ID ]      = $nome;
			}
		}
		return array( 'places' => $places, 'menus' => $menus, 'navs' => $navs );
	}

	/**
	 * The posted table, checked: only real places, the site's languages and existing menus.
	 *
	 * @param mixed $posted $_POST['menus_per_language'].
	 * @return array<string,array<string,int>>
	 */
	public static function clean( $posted ): array {
		$out = array();
		if ( ! is_array( $posted ) ) {
			return $out;
		}
		$c      = self::choices();
		$router = Plugin::instance()->router();
		$langs  = (array) $router->secondary_languages();
		foreach ( $posted as $place => $per ) {
			$place = (string) $place;
			if ( ! isset( $c['places'][ $place ] ) || ! is_array( $per ) ) {
				continue;
			}
			$lista = 0 === strpos( $place, 'nav:' ) ? $c['navs'] : $c['menus'];
			foreach ( $per as $lang => $id ) {
				$id = (int) $id;
				if ( $id > 0 && in_array( (string) $lang, $langs, true ) && isset( $lista[ $id ] ) ) {
					$out[ $place ][ (string) $lang ] = $id;
				}
			}
		}
		return $out;
	}

	/**
	 * The table on the Languages screen: one row per language, one column per menu place.
	 */
	public static function render_rows(): void {
		$c      = self::choices();
		$router = Plugin::instance()->router();
		$langs  = (array) $router->secondary_languages();
		if ( empty( $c['places'] ) || empty( $langs ) ) {
			echo '<p class="description">' . esc_html__( 'Your theme has no menu places yet.', 'translate-rocket' ) . '</p>';
			return;
		}
		$map = self::map();
		// 15/10/2026 (full check): one column per language made a table wider than the screen with many languages.
		// One row per language, one column per menu place: menus are one to three, languages up to twenty and more.
		echo '<div class="trr-menu-lang-wrap"><table class="widefat striped trr-menu-lang"><thead><tr><th>' . esc_html__( 'Language', 'translate-rocket' ) . '</th>';
		foreach ( $c['places'] as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $langs as $l ) {
			echo '<tr><th scope="row">' . esc_html( Languages::label( $l ) ) . '</th>';
			foreach ( $c['places'] as $place => $label ) {
				$lista = 0 === strpos( $place, 'nav:' ) ? $c['navs'] : $c['menus'];
				$sel   = (int) ( $map[ $place ][ $l ] ?? 0 );
				echo '<td data-label="' . esc_attr( $label ) . '"><select name="menus_per_language[' . esc_attr( $place ) . '][' . esc_attr( $l ) . ']" aria-label="' . esc_attr( $label . ' — ' . Languages::label( $l ) ) . '">';
				echo '<option value="0">' . esc_html__( 'The same, translated', 'translate-rocket' ) . '</option>';
				foreach ( $lista as $id => $nome ) {
					echo '<option value="' . (int) $id . '"' . selected( $sel, (int) $id, false ) . '>' . esc_html( $nome ) . '</option>';
				}
				echo '</select></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}
