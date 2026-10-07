<?php
/**
 * Language switcher (shortcode, block, floating).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Languages;
use TranslateRocket\Flags;
use TranslateRocket\Exclusions;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders language links via [translaterocket_switcher], the Gutenberg block,
 * or a floating widget. Look & feel come from the Switcher settings page;
 * shortcode/block attributes (type/show/current) override the defaults.
 */
class Switcher {

	/**
	 * Divider character key shown between flag and name (set per render).
	 *
	 * @var string
	 */
	private $divider = 'none';

	/**
	 * Draw only the languages visitors can see (the live preview).
	 *
	 * @var bool
	 */
	private $solo_pubbliche = false;

	/**
	 * Hook into WordPress.
	 */
	/** IDs of the language items added to a menu: far above any real post ID. */
	const MENU_ID_BASE = 2100000000;

	/** Was the switcher drawn in a menu on this page (else it floats, see floating()). */
	private $in_menu_done = false;

	/** The phone menu of the theme got the languages on this page. */
	private $in_mobile_done = false;

	public function boot(): void {
		add_shortcode( 'translaterocket_switcher', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_footer', array( $this, 'floating' ) );
		add_action( 'wp_footer', array( $this, 'footer_row' ), 5 );
		// A spot chosen on the page itself (6/10/2026), and the picker that chooses it.
		add_action( 'wp_footer', array( $this, 'spot' ), 6 );
		add_action( 'wp_enqueue_scripts', array( $this, 'picker_assets' ), 20 );
		add_filter( 'show_admin_bar', array( $this, 'picker_no_bar' ), 99 );
		// In the theme's menu (6/10/2026): real menu items, so the theme draws them like its
		// own — its submenu, its mobile menu. Priority 20: after MenuLanguages hid what it hides.
		add_filter( 'wp_nav_menu_objects', array( $this, 'menu_items' ), 20, 2 );
		// Block themes: the Navigation block gets a submenu through WordPress's own
		// «hooked blocks», drawn by the block itself.
		add_filter( 'hooked_block_types', array( $this, 'hook_navigation' ), 10, 4 );
		// The Switcher page shows the real site with the settings not saved yet (see trying()).
		add_filter(
			'show_admin_bar',
			static function ( $show ) {
				return null !== self::trying() ? false : $show;
			}
		);
		add_filter( 'hooked_block_core/navigation-submenu', array( $this, 'hooked_submenu' ), 10, 5 );
		// 6/10/2026 (Federico): in the Navigation block the languages had no flag and opened on hover
		// whatever the switcher said. The block keeps only plain text in a label, so the flag travels
		// as a mark and is drawn after; «On click» is handed to our submenu alone.
		add_filter( 'render_block_core/navigation-link', array( $this, 'nav_flags' ), 10, 2 );
		add_filter( 'render_block_core/navigation-submenu', array( $this, 'nav_flags' ), 10, 2 );
		add_filter( 'render_block_core/navigation', array( $this, 'nav_no_hover' ), PHP_INT_MAX );
		// Migration nicety: keep a leftover TranslatePress [language-switcher]
		// working, but only when TranslatePress isn't the one handling it.
		add_action( 'init', array( $this, 'register_compat_shortcodes' ), 99 );
	}

	/**
	 * Render TranslateRocket's switcher for other plugins' switcher shortcodes
	 * (e.g. TranslatePress's [language-switcher]) when they aren't registered,
	 * so a migrated site keeps showing a switcher with no manual edits.
	 */
	public function register_compat_shortcodes(): void {
		foreach ( array( 'language-switcher' ) as $tag ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, array( $this, 'render' ) );
			}
		}
	}

	/**
	 * Enqueue the front-end stylesheet + the user's custom colours.
	 */
	public function assets(): void {
		// No switcher for this viewer while previewing -> no assets either.
		if ( Preview::hidden() ) {
			return;
		}
		wp_enqueue_style(
			'trrocket-frontend',
			TRROCKET_URL . 'assets/css/frontend.css',
			array(),
			Plugin::asset_ver( 'assets/css/frontend.css' )
		);
		$css = self::css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'trrocket-frontend', $css );
		}

		wp_enqueue_script(
			'trrocket-switcher',
			TRROCKET_URL . 'assets/js/switcher.js',
			array(),
			Plugin::asset_ver( 'assets/js/switcher.js' ),
			true
		);
	}

	/**
	 * Switcher settings (with defaults).
	 *
	 * @return array<string,mixed>
	 */
	private static function settings(): array {
		$t = self::trying();
		if ( null !== $t && 'default' === $t['profile'] ) {
			return $t['sw'];
		}
		$sw = Settings::get()['switcher'] ?? array();
		$sw = is_array( $sw ) ? $sw : array();
		if ( null !== $t && ! empty( $t['sw']['in_menu'] ) ) {
			// Saving a profile «in my header menu» takes the menu from Default and stops it floating.
			$sw = array_merge(
				$sw,
				array(
					'in_menu'  => false,
					'floating' => false,
					'in_spot'  => false,
				)
			);
		}
		return $sw;
	}

	/**
	 * The settings being tried on the Switcher page, not saved yet (6/10/2026, Federico: «the live
	 * preview never matched what was published»). Only for the administrator who is trying them,
	 * with the token the page gave, for a quarter of an hour.
	 *
	 * @return array{profile:string,sw:array<string,mixed>}|null
	 */
	public static function trying(): ?array {
		static $done = false, $t = null;
		if ( $done ) {
			return $t;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is checked here.
		if ( empty( $_GET['trrocket_sw_try'] ) || ! did_action( 'init' ) || ! function_exists( 'wp_verify_nonce' ) ) {
			return null;
		}
		$done = true;
		$uid  = get_current_user_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $uid && current_user_can( 'manage_options' ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['trrocket_sw_try'] ) ), 'trrocket_sw_try_' . $uid ) ) {
			$v = get_transient( 'trrocket_sw_try_' . $uid );
			if ( is_array( $v ) && isset( $v['profile'], $v['sw'] ) && is_array( $v['sw'] ) ) {
				$t = array(
					'profile' => (string) $v['profile'],
					'sw'      => $v['sw'],
				);
			}
		}
		return $t;
	}

	/**
	 * The settings the header menu is drawn with: those of the profile where «In my header
	 * menu» was chosen — Default, or a profile like «header» (6/10/2026, Federico styled
	 * «header» and the menu kept showing Default).
	 *
	 * @return array<string,mixed>
	 */
	public static function menu_settings(): array {
		$d = self::settings();
		$t = self::trying();
		if ( null !== $t && 'default' !== $t['profile'] && ! empty( $t['sw']['in_menu'] ) ) {
			return array_merge( $d, $t['sw'] );
		}
		if ( ! empty( $d['in_menu'] ) ) {
			return $d;
		}
		foreach ( (array) ( Settings::get()['switchers'] ?? array() ) as $p ) {
			if ( is_array( $p ) && ! empty( $p['in_menu'] ) ) {
				return array_merge( $d, $p );
			}
		}
		return $d;
	}

	/**
	 * Settings for a named profile ('default' or a custom slug), falling back to
	 * the default profile.
	 *
	 * @return array<string,mixed>
	 */
	public static function profile_settings( string $id ): array {
		$all = Settings::get();
		if ( '' === $id || 'default' === $id ) {
			$sw = $all['switcher'] ?? array();
		} else {
			$extras = $all['switchers'] ?? array();
			$sw     = ( is_array( $extras ) && isset( $extras[ $id ] ) && is_array( $extras[ $id ] ) ) ? $extras[ $id ] : ( $all['switcher'] ?? array() );
		}
		return is_array( $sw ) ? $sw : array();
	}

	/**
	 * Build the front-end CSS from the colour settings (+ custom CSS).
	 */
	public static function css(): string {
		$out    = self::css_for( 'default', self::settings() );
		$extras = Settings::get()['switchers'] ?? array();
		if ( is_array( $extras ) ) {
			foreach ( $extras as $id => $s ) {
				if ( is_array( $s ) ) {
					$out .= self::css_for( (string) $id, $s );
				}
			}
		}
		if ( self::in_menu() ) {
			$out .= self::menu_css( self::menu_settings() );
		}
		// The bottom row takes the Default profile's text colours (6/10/2026: the preview showed them, the site did not).
		$d = self::settings();
		if ( ! empty( $d['footer_row'] ) ) {
			foreach ( array( 'text_color' => '.trrocket-footer-row a,.trrocket-footer-row span', 'hover_color' => '.trrocket-footer-row a:hover,.trrocket-footer-row a:focus' ) as $k => $sel ) {
				$c = trim( (string) ( $d[ $k ] ?? '' ) );
				if ( '' !== $c && preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([0-9.,\s%]+\)|[a-z]+)$/i', $c ) ) {
					$out .= $sel . '{color:' . $c . '}';
				}
			}
		}
		return $out;
	}

	/**
	 * The profile's colours on the languages in the header menu (6/10/2026, Federico: «I chose a
	 * colour and the menu never showed it»). The theme draws the items; text, hover, the box of
	 * the open list and its corners come from the switcher, the rest stays the theme's.
	 *
	 * @param array<string,mixed> $s Settings of the profile in the menu.
	 */
	private static function menu_css( array $s ): string {
		$text   = trim( (string) ( $s['text_color'] ?? '' ) );
		$hover  = trim( (string) ( $s['hover_color'] ?? '' ) );
		$bg     = trim( (string) ( $s['menu_bg'] ?? '' ) );
		$bg     = '' !== $bg ? $bg : trim( (string) ( $s['bg_color'] ?? '' ) );
		$hbg    = trim( (string) ( $s['hover_bg'] ?? '' ) );
		$border = trim( (string) ( $s['border_color'] ?? '' ) );
		$radius = trim( (string) ( $s['radius'] ?? '' ) );
		$ok     = static function ( string $c ): bool {
			return (bool) preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([0-9.,\s%]+\)|[a-z]+)$/i', $c );
		};
		$items = '.trrocket-lang-item>a,.trrocket-lang-item>button,.trrocket-lang-item .wp-block-navigation-item__content,.trrocket-lang-item .trrocket-menu-lang';
		$open  = '.trrocket-lang-current>.sub-menu,.trrocket-lang-current>ul.children,.trrocket-lang-current>.wp-block-navigation__submenu-container';
		$out   = '';
		if ( '' !== $text && $ok( $text ) ) {
			$out .= $items . '{color:' . $text . '!important}';
		}
		if ( '' !== $hover && $ok( $hover ) ) {
			$out .= '.trrocket-lang-item>a:hover,.trrocket-lang-item>a:focus,.trrocket-lang-item>button:hover,.trrocket-lang-item .wp-block-navigation-item__content:hover{color:' . $hover . '!important}';
		}
		if ( '' !== $hbg && $ok( $hbg ) ) {
			$out .= '.trrocket-lang-current .trrocket-lang-item>a:hover,.trrocket-lang-current .trrocket-lang-item .wp-block-navigation-item__content:hover{background:' . $hbg . '!important}';
		}
		$box = '';
		if ( '' !== $bg && $ok( $bg ) ) {
			$box .= 'background:' . $bg . '!important;';
		}
		if ( ! empty( $s['no_border'] ) ) {
			$box .= 'border:0!important;';
		} elseif ( '' !== $border && $ok( $border ) ) {
			$box .= 'border:1px solid ' . $border . '!important;';
		}
		if ( '' !== $radius ) {
			$box .= 'border-radius:' . self::css_len( $radius ) . '!important;overflow:hidden;';
		}
		if ( '' !== $box ) {
			$out .= $open . '{' . $box . '}';
		}
		// 8/10/2026 (Federico: «the preset was red and the header shows no red»): the colours of the
		// preset go on the language item in the menu too, not only on the box that opens under it.
		$top_bg = trim( (string) ( $s['bg_color'] ?? '' ) );
		$top    = '';
		if ( '' !== $top_bg && $ok( $top_bg ) ) {
			$top .= 'background:' . $top_bg . '!important;';
		}
		if ( empty( $s['no_border'] ) && '' !== $border && $ok( $border ) ) {
			$top .= 'border:1px solid ' . $border . '!important;';
		}
		if ( '' !== $radius ) {
			$top .= 'border-radius:' . self::css_len( $radius ) . '!important;';
		}
		if ( '' !== $top ) {
			$out .= '.trrocket-lang-current>a,.trrocket-lang-current>button,.trrocket-lang-current>.wp-block-navigation-item__content{' . $top . '}';
		}
		return $out;
	}

	/**
	 * Convert a hex colour (#rgb or #rrggbb) to an rgba() string with the given
	 * alpha. Used to make only the BACKGROUND translucent, leaving the flag and
	 * text fully opaque. Returns '' for colours we cannot parse (named/rgba).
	 *
	 * @param string $color Hex colour.
	 * @param float  $alpha Alpha 0..1.
	 */
	public static function to_rgba( string $color, float $alpha ): string {
		$color = trim( $color );
		if ( ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ) {
			return '';
		}
		$hex = ltrim( $color, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$a = rtrim( rtrim( number_format( max( 0, min( 1, $alpha ) ), 2, '.', '' ), '0' ), '.' );
		return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . ( '' === $a ? '0' : $a ) . ')';
	}

	/**
	 * Scoped CSS for one switcher profile. Each instance carries a
	 * .trrocket-sw-{id} wrapper class, so profiles never bleed into each other.
	 *
	 * @param string              $id Profile id.
	 * @param array<string,mixed> $s  Profile settings.
	 */
	/**
	 * Web-safe font stacks the switcher can use (no external fonts loaded).
	 * key => CSS font-family stack.
	 *
	 * @return array<string,string>
	 */
	public static function font_stacks(): array {
		return array(
			'system'    => '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif',
			'arial'     => 'Arial,Helvetica,sans-serif',
			'helvetica' => '"Helvetica Neue",Helvetica,Arial,sans-serif',
			'segoe'     => '"Segoe UI",Roboto,Helvetica,Arial,sans-serif',
			'verdana'   => 'Verdana,Geneva,sans-serif',
			'tahoma'    => 'Tahoma,Geneva,sans-serif',
			'trebuchet' => '"Trebuchet MS",Helvetica,sans-serif',
			'lucida'    => '"Lucida Sans Unicode","Lucida Grande",sans-serif',
			'century'   => '"Century Gothic","Apple Gothic",sans-serif',
			'impact'    => 'Impact,Charcoal,sans-serif',
			'georgia'   => 'Georgia,"Times New Roman",serif',
			'times'     => '"Times New Roman",Times,serif',
			'palatino'  => '"Palatino Linotype","Book Antiqua",Palatino,serif',
			'garamond'  => 'Garamond,Baskerville,"Times New Roman",serif',
			'courier'   => '"Courier New",Courier,monospace',
			'consolas'  => 'Consolas,Monaco,"Courier New",monospace',
		);
	}

	private static function css_for( string $id, array $s ): string {
		$scope  = '.trrocket-sw-' . preg_replace( '/[^a-z0-9_-]/i', '', $id );
		$text   = trim( (string) ( $s['text_color'] ?? '' ) );
		$bg     = trim( (string) ( $s['bg_color'] ?? '' ) );
		$bg_op  = max( 0, min( 100, (int) ( $s['bg_opacity'] ?? 100 ) ) );
		if ( $bg_op < 100 ) {
			// Apply the opacity to the chosen colour, or to the default white surface
			// when none was set — otherwise "background opacity" would do nothing.
			$rgba = self::to_rgba( '' !== $bg ? $bg : '#ffffff', $bg_op / 100 );
			if ( '' !== $rgba ) {
				$bg = $rgba;
			}
		}
		$border = trim( (string) ( $s['border_color'] ?? '' ) );
		$hover  = trim( (string) ( $s['hover_color'] ?? '' ) );
		$radius = trim( (string) ( $s['radius'] ?? '' ) );

		$box = '';
		if ( '' !== $bg ) {
			$box .= 'background:' . $bg . ';';
		}
		if ( ! empty( $s['no_border'] ) ) {
			// Explicitly asked for no border at all. Without this the only way to
			// get rid of it was to paint it the same colour as the background,
			// which stops working the moment the background is not plain.
			$box .= 'border:0;';
		} elseif ( '' !== $border ) {
			$box .= 'border:1px solid ' . $border . ';';
		}
		if ( '' !== $radius ) {
			$box .= 'border-radius:' . self::css_len( (string) $radius ) . ';';
		}
		$shadows = array(
			'light'  => '0 1px 3px rgba(0,0,0,0.12)',
			'medium' => '0 2px 8px rgba(0,0,0,0.15)',
			'strong' => '0 4px 16px rgba(0,0,0,0.2)',
		);
		$shadow = (string) ( $s['shadow'] ?? 'none' );
		if ( isset( $shadows[ $shadow ] ) ) {
			$box .= 'box-shadow:' . $shadows[ $shadow ] . ';';
		}

		$wmap  = array(
			'small'  => '120px',
			'medium' => '180px',
			'large'  => '260px',
		);
		$width = (string) ( $s['width'] ?? 'auto' );
		$wpx   = trim( (string) ( $s['width_px'] ?? '' ) );
		$wval  = '';
		if ( 'custom' === $width && '' !== $wpx ) {
			$wval = self::css_len( $wpx );
		} elseif ( isset( $wmap[ $width ] ) ) {
			$wval = $wmap[ $width ];
		}
		if ( '' !== $wval ) {
			$box .= 'min-width:' . $wval . ';';
		}

		$out = '';
		if ( '' !== $box ) {
			$out .= $scope . ' .trrocket-switcher,' . $scope . ' .trrocket-dd-toggle,' . $scope . '.trrocket-floating:not(.trrocket-floating-dd){' . $box . 'padding:6px 12px;}';
			// For an inline floating switcher the box lives on the wrapper, so reset
			// the inner list to avoid a second, nested border. A floating DROPDOWN
			// instead keeps its box on the toggle (so toggle + menu match), so it is
			// intentionally excluded here.
			$out .= $scope . '.trrocket-floating:not(.trrocket-floating-dd) .trrocket-switcher{background:none;border:0;box-shadow:none;padding:0;}';
			// Carry the same background/border into the dropdown menu so the toggle
			// and the open menu read as one continuous surface.
			$menu = '';
			if ( '' !== $bg ) {
				$menu .= 'background:' . $bg . ';';
			}
			if ( '' !== $border ) {
				$menu .= 'border-color:' . $border . ';';
			}
			if ( '' !== $menu ) {
				$out .= $scope . ' .trrocket-dd-menu{' . $menu . '}';
			}
		}
		// Dedicated dropdown-menu background (supports rgba for translucent panels);
		// emitted after the carry-over so it wins, and independently of the box.
		$menu_bg = trim( (string) ( $s['menu_bg'] ?? '' ) );
		if ( '' !== $menu_bg ) {
			$out .= $scope . ' .trrocket-dd-menu{background:' . $menu_bg . ';backdrop-filter:blur(8px);}';
		}
		if ( '' !== $text ) {
			// !important: an explicit customizer color must beat the theme's own
			// link color. Menu items are plain <a> elements, and header/footer
			// palettes (e.g. Astra "white header links") target anchors with
			// higher specificity than our scoped class — without !important the
			// dropdown text can end up white-on-white inside colored headers.
			$out .= $scope . ' .trrocket-switcher a,' . $scope . ' .trrocket-switcher .trrocket-current span,' . $scope . ' .trrocket-dd-toggle,' . $scope . ' .trrocket-dd-menu a,' . $scope . ' .trrocket-scroll-prev,' . $scope . ' .trrocket-scroll-next{color:' . $text . ' !important;}';
		}
		if ( '' !== $hover ) {
			$out .= $scope . ' .trrocket-switcher a:hover,' . $scope . ' .trrocket-dd-menu a:hover,' . $scope . ' .trrocket-dd-toggle:hover{color:' . $hover . ' !important;}';
		}

		$hover_bg = trim( (string) ( $s['hover_bg'] ?? '' ) );
		if ( '' !== $hover_bg ) {
			$out .= $scope . ' .trrocket-dd-menu a:hover,' . $scope . ' .trrocket-switcher a:hover,' . $scope . ' .trrocket-dd-toggle:hover{background:' . $hover_bg . ';}';
		}
		// Optional font for the whole switcher (toggle + menu + inline list).
		$fontmap = self::font_stacks();
		$font = (string) ( $s['font_family'] ?? '' );
		if ( isset( $fontmap[ $font ] ) ) {
			$out .= $scope . ' .trrocket-switcher,' . $scope . ' .trrocket-dd-toggle,' . $scope . ' .trrocket-dd-menu{font-family:' . $fontmap[ $font ] . ';}';
		}
		if ( 'bold' === (string) ( $s['font_weight'] ?? 'normal' ) ) {
			$out .= $scope . ' .trrocket-switcher a,' . $scope . ' .trrocket-switcher .trrocket-current span,' . $scope . ' .trrocket-dd-toggle,' . $scope . ' .trrocket-dd-menu a{font-weight:700;}';
		}
		$fsize = self::css_len( (string) ( $s['font_size'] ?? '' ) );
		if ( '' !== $fsize ) {
			$out .= $scope . ' .trrocket-switcher,' . $scope . ' .trrocket-dd-toggle,' . $scope . ' .trrocket-dd-menu{font-size:' . $fsize . ';}';
		}
		if ( ! empty( $s['uppercase'] ) ) {
			$out .= $scope . ' .trrocket-switcher,' . $scope . ' .trrocket-dd-toggle,' . $scope . ' .trrocket-dd-menu{text-transform:uppercase;letter-spacing:.02em;}';
		}
		// The menu connects under the toggle, so round only its bottom corners.
		if ( '' !== $radius ) {
			$rv   = self::css_len( (string) $radius );
			$out .= $scope . ' .trrocket-dd-menu{border-radius:0 0 ' . $rv . ' ' . $rv . ';}';
			// Opening upwards the two surfaces meet the other way round.
			$out .= $scope . '.trrocket-dd-up .trrocket-dd-menu{border-radius:' . $rv . ' ' . $rv . ' 0 0;}';
			$out .= $scope . '.trrocket-dd-up .trrocket-dd.is-open .trrocket-dd-toggle{border-radius:0 0 ' . $rv . ' ' . $rv . ';}';
		}

		// Per-profile device visibility: hide this switcher entirely on one
		// viewport so a site can run a desktop-only profile and a mobile-only
		// profile side by side. The wrapper carries the scope class in every
		// layout (inline, block, floating), so hiding $scope hides all of it.
		$device = (string) ( $s['device'] ?? 'both' );
		// The two ranges meet exactly at 783px with no gap: a desktop-only and a
		// mobile-only profile are never both visible (nor both hidden) at any
		// width, including fractional widths during a browser zoom.
		if ( 'desktop' === $device ) {
			$out .= '@media(max-width:782.98px){' . $scope . '{display:none !important;}}';
		} elseif ( 'mobile' === $device ) {
			$out .= '@media(min-width:783px){' . $scope . '{display:none !important;}}';
		}

		$mobile = (string) ( $s['mobile'] ?? 'same' );
		if ( 'flags' === $mobile ) {
			// !important: «.trrocket-switcher .trrocket-current span» (frontend.css) is more
			// specific and kept the CURRENT language's name on phones (found 24/9/2026 by the
			// new live preview, which draws the switcher with the site's own CSS).
			$out .= '@media(max-width:782px){' . $scope . ' .trrocket-name{display:none !important;}}';
		} elseif ( 'hide' === $mobile ) {
			$out .= '@media(max-width:782px){' . $scope . ' .trrocket-switcher,' . $scope . ' .trrocket-dd,' . $scope . '.trrocket-floating{display:none;}}';
		}

		$tl = max( 0, (int) ( $s['flag_tl'] ?? 0 ) );
		$tr = max( 0, (int) ( $s['flag_tr'] ?? 0 ) );
		$br = max( 0, (int) ( $s['flag_br'] ?? 0 ) );
		$bl = max( 0, (int) ( $s['flag_bl'] ?? 0 ) );
		if ( $tl || $tr || $br || $bl ) {
			$out .= $scope . ' .trrocket-flag-svg{border-radius:' . $tl . 'px ' . $tr . 'px ' . $br . 'px ' . $bl . 'px;overflow:hidden;}';
		}

		// Entrance animation for the dropdown menu when it opens. Keyframes live in
		// frontend.css; here we just attach the chosen one to the revealed menu.
		$anim = (string) ( $s['animation'] ?? 'fade' );
		if ( in_array( $anim, array( 'fade', 'slide', 'scale' ), true ) ) {
			$out .= $scope . ' .trrocket-dd.is-open .trrocket-dd-menu,'
				. $scope . ' .trrocket-dd:focus-within .trrocket-dd-menu,'
				. $scope . ' .trrocket-dd-hover:hover .trrocket-dd-menu{animation:trr-anim-' . $anim . ' .2s ease;}';
		}

		// Hover effect for the language links (inline list + dropdown items).
		$hfx   = (string) ( $s['hover_fx'] ?? 'none' );
		$links = $scope . ' .trrocket-switcher a,' . $scope . ' .trrocket-dd-menu a';
		$hov   = $scope . ' .trrocket-switcher a:hover,' . $scope . ' .trrocket-dd-menu a:hover';
		if ( 'lift' === $hfx ) {
			$out .= $links . '{transition:transform .15s ease;}' . $hov . '{transform:translateY(-2px);}';
		} elseif ( 'grow' === $hfx ) {
			$out .= $links . '{transition:transform .15s ease;display:inline-block;}' . $hov . '{transform:scale(1.08);}';
		} elseif ( 'underline' === $hfx ) {
			$out .= $links . '{background-image:linear-gradient(currentColor,currentColor);background-position:0 100%;background-repeat:no-repeat;background-size:0 2px;transition:background-size .2s ease;}' . $hov . '{background-size:100% 2px;}';
		}

		// Scrollable switcher: the chosen width bounds the viewport, so the languages
		// overflow it and the ‹ › arrows scroll through them.
		if ( '' !== $wval ) {
			$out .= $scope . ' .trrocket-scroll-viewport{width:' . $wval . ';max-width:100%;}';
		}

		return $out;
	}

	/**
	 * Shortcode output.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		// In admin-only preview the switcher would only lead visitors to
		// redirects — hide it from viewers who can't see the translations.
		if ( Preview::hidden() ) {
			return '';
		}
		$atts = is_array( $atts ) ? $atts : array();
		$id   = isset( $atts['id'] ) ? sanitize_key( $atts['id'] ) : 'default';
		$s    = self::profile_settings( $id );
		$this->divider = (string) ( $s['divider'] ?? 'none' );
		// Placement is one either/or choice: if the default switcher is floating,
		// don't also output its inline shortcode/block, so the page never shows two.
		if ( 'default' === $id && ! empty( $s['floating'] ) ) {
			return '';
		}
		$atts = shortcode_atts(
			array(
				'id'      => 'default',
				'type'    => $s['type'] ?? 'inline',
				'show'    => $s['show'] ?? 'both',
				'current' => $s['current'] ?? 'show',
				'trigger' => $s['dd_trigger'] ?? 'click',
				'caret'   => empty( $s['dd_caret'] ) ? '0' : '1',
			),
			$atts,
			'translaterocket_switcher'
		);
		$html = $this->build( $atts, ! empty( $s['english_names'] ) );
		if ( '' === $html ) {
			return '';
		}
		// translate="no" keeps the language names (Italiano, English…) out of the
		// translation engine — they must never be collected or translated.
		return '<div class="trrocket-sw trrocket-sw-' . esc_attr( $id ) . '" translate="no">' . $html . '</div>';
	}

	/**
	 * The switcher exactly as the site would draw it with these settings (saved or not),
	 * for the live preview in the dashboard: same markup (build()), same CSS (css_for()).
	 *
	 * The preview used to be a copy drawn by hand in the admin, and it drifted from the
	 * real thing: truncated names in the dropdown, layout changes shown only after saving
	 * (Federico, 24/9/2026). Now there is one renderer.
	 *
	 * The preview frame is narrower than a desktop screen, so the desktop/phone rules
	 * (breakpoint 783px) are resolved here for the view that was asked for, instead of
	 * letting the frame's own width decide.
	 *
	 * @param array<string,mixed> $s    Switcher settings (one profile).
	 * @param string              $view desktop|phone.
	 * @return array{html:string,css:string,type:string}
	 */
	public function preview_parts( array $s, string $view ): array {
		$this->divider = (string) ( $s['divider'] ?? 'none' );
		$atts          = array(
			'id'      => 'preview',
			'type'    => (string) ( $s['type'] ?? 'inline' ),
			'show'    => (string) ( $s['show'] ?? 'both' ),
			'current' => (string) ( $s['current'] ?? 'show' ),
			'trigger' => (string) ( $s['dd_trigger'] ?? 'click' ),
			'caret'   => empty( $s['dd_caret'] ) ? '0' : '1',
		);
		// The preview is «the switcher your visitors will see»: a language still offline is
		// not in it, even though the administrator looking at it could open that language
		// (the preview listed Deutsch while visitors never saw it — 30/09/2026).
		$this->solo_pubbliche = true;
		$html                 = $this->build( $atts, ! empty( $s['english_names'] ) );
		$this->solo_pubbliche = false;
		$css                  = self::css_for( 'preview', $s );
		$router               = Plugin::instance()->router();
		$nascoste             = array();
		foreach ( $router->offline_languages() as $code ) {
			$nascoste[] = Languages::label( $code );
		}
		$css  = (string) preg_replace_callback(
			'/@media\s*\(\s*(max|min)-width\s*:\s*(782(?:\.98)?|783)px\s*\)\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/',
			function ( $m ) use ( $view ) {
				$per_telefono = ( 'max' === $m[1] );
				return ( ( 'phone' === $view ) === $per_telefono ) ? $m[3] : '';
			},
			$css
		);
		return array(
			'html' => ( '' === $html ? '' : '<div class="trrocket-sw trrocket-sw-preview" translate="no">' . $html . '</div>' )
				. ( empty( $nascoste ) ? '' : '<p class="trr-sw-offline-note" style="margin:10px 0 0;font:12px/1.4 system-ui,sans-serif;color:#6b7089;text-align:center">'
					/* translators: %s: comma-separated language names. */
					. esc_html( sprintf( __( 'Not shown to visitors yet (offline): %s.', 'translate-rocket' ), implode( ', ', array_filter( $nascoste ) ) ) ) . '</p>' ),
			'css'  => $css,
			'type' => $atts['type'],
		);
	}

	/**
	 * A whole page for the preview frame: the site's switcher stylesheet and script, the
	 * switcher, and nothing from the dashboard's own styles around it.
	 *
	 * @param array{html:string,css:string,type:string} $parts From preview_parts().
	 * @param string                                    $empty Shown when there is nothing to switch.
	 */
	public static function preview_document( array $parts, string $empty ): string {
		$body = '' !== $parts['html'] ? $parts['html'] : '<p class="trr-empty">' . esc_html( $empty ) . '</p>';
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<link rel="stylesheet" href="' . esc_url( TRROCKET_URL . 'assets/css/frontend.css?ver=' . Plugin::asset_ver( 'assets/css/frontend.css' ) ) . '">'
			. '<style>html,body{margin:0}body{padding:24px 16px;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2937;background:#fff;text-align:center}a{color:inherit}#trr-root{display:inline-block;text-align:left;max-width:100%}.trr-empty{color:#646970;margin:0}</style>'
			. '<style id="trr-live">' . wp_strip_all_tags( $parts['css'] ) . '</style>'
			. '</head><body><div id="trr-root" data-type="' . esc_attr( $parts['type'] ) . '">' . $body . '</div>'
			. '<script src="' . esc_url( TRROCKET_URL . 'assets/js/switcher.js?ver=' . Plugin::asset_ver( 'assets/js/switcher.js' ) ) . '"></script>'
			// A language link leads nowhere in here: it only closes the menu, like the site would.
			. '<script>document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a");if(a){e.preventDefault();}},true);</script>'
			. '</body></html>';
	}

	/**
	 * Validate a CSS length (number + unit) for the custom floating position;
	 * returns '' if it isn't a safe length, so it can't inject arbitrary CSS.
	 */
	private static function css_len( string $v ): string {
		$v = trim( $v );
		if ( ! preg_match( '/^(-?\d+(?:\.\d+)?)\s*(px|%|em|rem|vw|vh)?$/', $v, $m ) ) {
			return '';
		}
		$num  = (float) $m[1];
		$unit = ( isset( $m[2] ) && '' !== $m[2] ) ? $m[2] : 'px'; // bare number => px
		if ( $num < 0 ) {
			$num = 0; // no negatives — they'd push the switcher off-screen
		}
		if ( '%' === $unit && $num > 100 ) {
			$num = 100; // a percentage can't exceed the viewport
		}
		return rtrim( rtrim( number_format( $num, 2, '.', '' ), '0' ), '.' ) . $unit;
	}

	/**
	 * Floating switcher in a fixed corner (enabled from settings).
	 */
	public function floating(): void {
		if ( Preview::hidden() || \TranslateRocket\BuilderMode::active() ) {
			return;
		}
		$s = self::settings();
		// In the menu: floating only on a page where that menu was not drawn (a landing page
		// without header, a template that leaves the menu out), so the switcher never vanishes.
		$ripiego = ! empty( $s['in_menu'] ) && ! $this->in_menu_done;
		// The header menu got the languages, but the theme draws another menu on phones and that
		// one did not (its location is empty: the theme lists the pages there). On phones only, float.
		$solo_telefono = ! empty( $s['in_menu'] ) && $this->in_menu_done && ! $this->in_mobile_done
			&& ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) && array() !== self::mobile_locations();
		if ( empty( $s['floating'] ) && ! $ripiego && ! $solo_telefono ) {
			return;
		}
		$ripiego = $ripiego || $solo_telefono;
		$this->divider = (string) ( $s['divider'] ?? 'none' );
		$pos  = in_array( $s['float_pos'] ?? '', array( 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'custom' ), true ) ? $s['float_pos'] : 'bottom-right';
		if ( $ripiego ) {
			$pos = 'bottom-right';
		}
		$html = $this->build(
			array(
				'type'    => $s['type'] ?? 'inline',
				'show'    => $s['show'] ?? 'both',
				'current' => $s['current'] ?? 'show',
				'trigger' => $s['dd_trigger'] ?? 'click',
				'caret'   => empty( $s['dd_caret'] ) ? '0' : '1',
			),
			! empty( $s['english_names'] )
		);
		if ( '' === $html ) {
			return;
		}
		$style = '';
		if ( 'custom' === $pos ) {
			$x = self::css_len( (string) ( $s['float_x'] ?? '' ) );
			$y = self::css_len( (string) ( $s['float_y'] ?? '' ) );
			if ( '' !== $x ) {
				// Anchor from the nearer edge: a large % from the left would crop off
				// the right on small screens, so flip to right-anchored past 50%.
				if ( preg_match( '/^([\d.]+)%$/', $x, $mx ) && (float) $mx[1] > 50 ) {
					$style .= 'right:' . ( 100 - (float) $mx[1] ) . '%;left:auto;';
				} else {
					$style .= 'left:' . $x . ';right:auto;';
				}
			}
			if ( '' !== $y ) {
				if ( preg_match( '/^([\d.]+)%$/', $y, $my ) && (float) $my[1] > 50 ) {
					$style .= 'bottom:' . ( 100 - (float) $my[1] ) . '%;top:auto;';
				} else {
					$style .= 'top:' . $y . ';bottom:auto;';
				}
			}
		}
		$dd_extra = ( 'dropdown' === ( $s['type'] ?? '' ) ) ? ' trrocket-floating-dd' : '';
		if ( ! empty( $solo_telefono ) ) {
			$dd_extra .= ' trrocket-only-phone';
		}
		// Anchored to the bottom of the screen, a dropdown that opens downwards
		// puts the whole list below the fold, where no visitor can reach it: it
		// has to open upwards. Custom positions count when anchored from the bottom.
		$in_basso = in_array( $pos, array( 'bottom-right', 'bottom-left' ), true ) || ( 'custom' === $pos && false !== strpos( $style, 'bottom:' ) );
		if ( '' !== $dd_extra && $in_basso ) {
			$dd_extra .= ' trrocket-dd-up';
		}
		$attr     = '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '';
		echo wp_kses( '<div class="trrocket-floating' . $dd_extra . ' trrocket-sw-default trrocket-pos-' . esc_attr( $pos ) . '" translate="no"' . $attr . '>' . $html . '</div>' , \TranslateRocket\Kses::html_rules() );
	}

	/**
	 * A row of languages at the bottom of every page (6/10/2026): flags and names side by side,
	 * centred, wrapping on phones. Independent of where the main switcher is.
	 */
	public function footer_row(): void {
		$s = self::settings();
		if ( empty( $s['footer_row'] ) || Preview::hidden() || \TranslateRocket\BuilderMode::active() ) {
			return;
		}
		$this->divider = 'none';
		$entries       = $this->entries( array( 'type' => 'inline', 'current' => 'show' ), ! empty( $s['english_names'] ) );
		if ( count( $entries ) < 2 ) {
			return;
		}
		$grid  = 'grid' === ( $s['footer_layout'] ?? 'row' );
		$html  = $this->render_list( $entries, false, (string) ( $s['show'] ?? 'both' ), $grid );
		$align = in_array( (string) ( $s['footer_align'] ?? 'center' ), array( 'left', 'right' ), true ) ? (string) $s['footer_align'] : 'center';
		$cols  = (int) ( $s['footer_cols'] ?? 0 );
		$extra = ' trrocket-footer-' . $align . ( $grid && $cols >= 2 && $cols <= 6 ? ' trrocket-footer-cols-' . $cols : '' );
		echo wp_kses( '<div class="trrocket-footer-row' . ( $grid ? ' trrocket-footer-grid' : '' ) . $extra . '" role="navigation" aria-label="' . esc_attr__( 'Languages', 'translate-rocket' ) . '" translate="no">' . $html . '</div>', \TranslateRocket\Kses::html_rules() );
	}

	/**
	 * Is this page open in the spot picker (Switcher screen → «Choose on my page»)?
	 */
	public static function picking(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: it only draws the picker for an administrator.
		return isset( $_GET['trrocket_pick'] ) && is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * A CSS selector as the picker writes it (tags, #id, .class, >, :nth-of-type): nothing else survives.
	 *
	 * @param string $sel Selector.
	 */
	public static function clean_selector( string $sel ): string {
		$sel = (string) preg_replace( '/[^A-Za-z0-9_\-#.\s>:()]/', '', $sel );
		return trim( substr( (string) preg_replace( '/\s+/', ' ', $sel ), 0, 300 ) );
	}

	/**
	 * The picker's script, only in the picker.
	 */
	public function picker_assets(): void {
		if ( ! self::picking() ) {
			return;
		}
		wp_enqueue_script( 'trrocket-switcher-picker', TRROCKET_URL . 'assets/js/switcher-picker.js', array(), Plugin::asset_ver( 'assets/js/switcher-picker.js' ), true );
		wp_localize_script(
			'trrocket-switcher-picker',
			'trrocketPicker',
			array(
				'hint'    => __( 'Click the place where the switcher should go.', 'translate-rocket' ),
				'start'   => __( 'At the start', 'translate-rocket' ),
				'end'     => __( 'At the end', 'translate-rocket' ),
				'use'     => __( 'Use this spot', 'translate-rocket' ),
				'cancel'  => __( 'Cancel', 'translate-rocket' ),
				'done'    => __( 'Chosen. Save the switcher to keep it.', 'translate-rocket' ),
				'header'  => __( 'Header', 'translate-rocket' ),
				'menu'    => __( 'Menu', 'translate-rocket' ),
				'footer'  => __( 'Footer', 'translate-rocket' ),
				'sidebar' => __( 'Sidebar', 'translate-rocket' ),
			)
		);
	}

	/**
	 * No admin bar in the picker: it is not part of the page the visitor sees.
	 *
	 * @param bool $show Show the bar.
	 */
	public function picker_no_bar( $show ) {
		return self::picking() ? false : $show;
	}

	/**
	 * The switcher in the spot chosen on the page. Drawn here, at the end of the page, and moved there
	 * by a few lines of script; where the spot is missing or hidden (a page without that header, the
	 * desktop menu on a phone) it floats in the corner instead, and it follows the screen when resized.
	 */
	public function spot(): void {
		$s       = self::settings();
		$picking = self::picking();
		if ( ( empty( $s['in_spot'] ) && ! $picking ) || Preview::hidden() || \TranslateRocket\BuilderMode::active() ) {
			return;
		}
		$this->divider = (string) ( $s['divider'] ?? 'none' );
		$type          = (string) ( $s['type'] ?? 'inline' );
		$html          = $this->build(
			array(
				'type'    => $type,
				'show'    => $s['show'] ?? 'both',
				'current' => $s['current'] ?? 'show',
				'trigger' => $s['dd_trigger'] ?? 'click',
				'caret'   => empty( $s['dd_caret'] ) ? '0' : '1',
			),
			! empty( $s['english_names'] )
		);
		if ( '' === $html ) {
			return;
		}
		$html = wp_kses( $html, \TranslateRocket\Kses::html_rules() );
		if ( $picking ) {
			// the picker shows the real switcher where the owner clicks
			echo '<template id="trrocket-spot-tpl" class="trrocket-spot-tpl">' . $html . '</template>'; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- filtered by wp_kses above.
			return;
		}
		$float = 'trrocket-floating trrocket-pos-bottom-right' . ( 'dropdown' === $type ? ' trrocket-floating-dd trrocket-dd-up' : '' );
		echo '<div id="trrocket-spot" class="trrocket-sw trrocket-sw-default trrocket-spot" translate="no" hidden data-sel="' . esc_attr( self::clean_selector( (string) ( $s['spot_selector'] ?? '' ) ) ) . '" data-where="' . ( 'start' === ( $s['spot_where'] ?? 'end' ) ? 'start' : 'end' ) . '" data-float="' . esc_attr( $float ) . '">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- $html filtered by wp_kses above.
		?>
<script>( function () { var w = document.getElementById( 'trrocket-spot' ); if ( ! w ) { return; } var base = w.className, sel = w.getAttribute( 'data-sel' ), start = 'start' === w.getAttribute( 'data-where' ), fl = w.getAttribute( 'data-float' ); function place() { var t = null; try { t = sel ? document.querySelector( sel ) : null; } catch ( e ) { t = null; } var ok = t && t.getClientRects().length && 'hidden' !== getComputedStyle( t ).visibility; if ( ok ) { if ( w.parentNode !== t ) { if ( start ) { t.insertBefore( w, t.firstChild ); } else { t.appendChild( w ); } } w.className = base; } else { if ( w.parentNode !== document.body ) { document.body.appendChild( w ); } w.className = base + ' ' + fl; } w.hidden = false; } place(); var r; window.addEventListener( 'resize', function () { clearTimeout( r ); r = setTimeout( place, 200 ); } ); }() );</script>
		<?php
	}

	/**
	 * Is the default switcher placed in the theme's menu?
	 */
	public static function in_menu(): bool {
		return ! empty( self::menu_settings()['in_menu'] );
	}

	/**
	 * Menu locations of the theme that have a menu in them: location => «Location name — Menu name».
	 *
	 * @return array<string,string>
	 */
	public static function menu_locations(): array {
		$out      = array();
		$assigned = get_nav_menu_locations();
		foreach ( get_registered_nav_menus() as $loc => $label ) {
			$menu_id = (int) ( $assigned[ $loc ] ?? 0 );
			$menu    = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;
			if ( $menu ) {
				$out[ (string) $loc ] = $label . ' — ' . $menu->name;
			}
		}
		return $out;
	}

	/**
	 * The location «Automatic» means: the theme's main menu by its usual names, else the first one used.
	 */
	public static function auto_location(): string {
		$locs = self::menu_locations();
		foreach ( array( 'primary', 'main', 'header', 'menu-1', 'main-menu', 'primary-menu', 'primary_navigation', 'main_menu', 'header-menu', 'top' ) as $want ) {
			if ( isset( $locs[ $want ] ) ) {
				return $want;
			}
		}
		foreach ( array_keys( $locs ) as $loc ) {
			if ( false === strpos( (string) $loc, 'footer' ) && false === strpos( (string) $loc, 'social' ) ) {
				return (string) $loc;
			}
		}
		return '';
	}

	/**
	 * The location chosen in the settings (or the automatic one).
	 */
	private static function wanted_location(): string {
		$loc = (string) ( self::menu_settings()['menu_location'] ?? 'auto' );
		return ( '' === $loc || 'auto' === $loc ) ? self::auto_location() : $loc;
	}

	/**
	 * The theme's own phone menu locations (Astra «mobile_menu», Storefront «handheld»…): on a
	 * phone they replace the header menu, so the languages go there too.
	 *
	 * @return string[]
	 */
	public static function mobile_locations(): array {
		$out = array();
		foreach ( array_keys( get_registered_nav_menus() ) as $loc ) {
			if ( preg_match( '/mobile|handheld|off-?canvas|responsive/i', (string) $loc ) ) {
				$out[] = (string) $loc;
			}
		}
		return $out;
	}

	/**
	 * Does this wp_nav_menu() call draw the chosen menu? By location, or by the menu itself
	 * (Elementor's Nav Menu widget and many headers name the menu, not the location).
	 *
	 * @param object $args wp_nav_menu() arguments.
	 */
	private static function is_wanted_menu( $args ): bool {
		$loc = self::wanted_location();
		if ( '' === $loc || ! is_object( $args ) ) {
			return false;
		}
		if ( isset( $args->theme_location ) && '' !== (string) $args->theme_location ) {
			return (string) $args->theme_location === $loc || in_array( (string) $args->theme_location, self::mobile_locations(), true );
		}
		$assigned = get_nav_menu_locations();
		$want_id  = (int) ( $assigned[ $loc ] ?? 0 );
		if ( $want_id <= 0 || empty( $args->menu ) ) {
			return false;
		}
		$menu = wp_get_nav_menu_object( $args->menu );
		return $menu && (int) $menu->term_id === $want_id;
	}

	/**
	 * The languages as menu items, at the end of the chosen menu: the current one with the
	 * others in its submenu (dropdown), or one item per language (the other layouts).
	 *
	 * @param array<int,mixed> $items Menu items.
	 * @param object           $args  wp_nav_menu() arguments.
	 * @return array<int,mixed>
	 */
	public function menu_items( $items, $args ) {
		if ( ! is_array( $items ) || ! self::in_menu() || is_admin() || Preview::hidden() || \TranslateRocket\BuilderMode::active() || ! self::is_wanted_menu( $args ) ) {
			return $items;
		}
		$s             = self::menu_settings();
		$this->divider = (string) ( $s['divider'] ?? 'none' );
		$show          = (string) ( $s['show'] ?? 'both' );
		$dropdown      = 'dropdown' === ( $s['type'] ?? '' );
		$entries       = $this->entries( array( 'type' => $dropdown ? 'dropdown' : 'inline', 'current' => $s['current'] ?? 'show' ), ! empty( $s['english_names'] ) );
		if ( count( $entries ) < ( $dropdown ? 2 : 1 ) ) {
			return $items;
		}
		$order = 0;
		foreach ( $items as $it ) {
			$order = max( $order, (int) ( $it->menu_order ?? 0 ) );
		}
		$id   = self::MENU_ID_BASE;
		$make = function ( array $entry, int $parent, array $extra ) use ( &$id, &$order, $show ) {
			++$id;
			++$order;
			$classes = array_merge( array( 'menu-item', 'menu-item-type-custom', 'menu-item-object-custom', 'trrocket-lang-item', 'trrocket-lang-' . $entry['code'] ), $extra );
			return new \WP_Post(
				(object) array(
					'ID'                    => $id,
					'db_id'                 => $id,
					'post_type'             => 'nav_menu_item',
					'post_status'           => 'publish',
					'post_title'            => '',
					'post_name'             => 'trrocket-lang-' . $entry['code'],
					'post_parent'           => 0,
					'menu_order'            => $order,
					'menu_item_parent'      => (string) $parent,
					'object_id'             => (string) $id,
					'object'                => 'custom',
					'type'                  => 'custom',
					'type_label'            => 'Custom Link',
					// HTML is what the walkers expect here (the_title is not escaped in menus):
					// flag + name, kept away from the translation engine.
					'title'                 => '<span class="trrocket-menu-lang" translate="no" lang="' . esc_attr( $entry['code'] ) . '">' . $this->label_html( $entry, $show ) . '</span>',
					'url'                   => (string) $entry['href'],
					'target'                => '',
					'attr_title'            => (string) $entry['name'],
					'description'           => '',
					'classes'               => $classes,
					'xfn'                   => '',
					'current'               => false,
					'current_item_ancestor' => false,
					'current_item_parent'   => false,
					'filter'                => 'raw',
				)
			);
		};
		$nuove = array();
		if ( $dropdown ) {
			$current = null;
			foreach ( $entries as $e ) {
				if ( $e['current'] ) {
					$current = $e;
				}
			}
			$current = $current ?? $entries[0];
			$parent  = $make( $current, 0, array( 'menu-item-has-children', 'trrocket-lang-current' ) );
			$nuove[] = $parent;
			foreach ( $entries as $e ) {
				if ( $e['code'] !== $current['code'] ) {
					$nuove[] = $make( $e, (int) $parent->db_id, array() );
				}
			}
		} else {
			foreach ( $entries as $e ) {
				$nuove[] = $make( $e, 0, $e['current'] ? array( 'trrocket-lang-current' ) : array() );
			}
		}
		// The walkers draw the items in the order of this array: first or last in the menu (and in the
		// phone menu, which is the same menu or the theme's own one).
		$items = array_values( $items );
		$pos   = (string) ( $s['menu_pos'] ?? 'end' );
		$cut   = 'start' === $pos ? 0 : count( $items );
		// 6/10/2026 (Federico): «right after this item» — after the chosen top-level item and its submenu.
		if ( 'after' === $pos ) {
			foreach ( $items as $k => $it ) {
				if ( 0 === (int) ( $it->menu_item_parent ?? 0 ) && self::same_label( (string) ( $it->title ?? '' ), (string) ( $s['menu_after'] ?? '' ) ) ) {
					for ( $cut = $k + 1; $cut < count( $items ) && 0 !== (int) ( $items[ $cut ]->menu_item_parent ?? 0 ); $cut++ ) {
						continue;
					}
					break;
				}
			}
		}
		$items = array_merge( array_slice( $items, 0, $cut ), $nuove, array_slice( $items, $cut ) );
		$this->in_menu_done = true;
		if ( isset( $args->theme_location ) && in_array( (string) $args->theme_location, self::mobile_locations(), true ) ) {
			$this->in_mobile_done = true;
		}
		return $items;
	}

	/**
	 * Block themes: «the header menu» is the Navigation block. Every Navigation block of the
	 * page gets the languages unless one menu was chosen (nav:<id>), then only that one.
	 *
	 * @param string[]                                                $hooked   Hooked block types.
	 * @param string                                                  $position Relative position.
	 * @param string                                                  $anchor   Anchor block type.
	 * @param \WP_Block_Template|\WP_Post|array<string,mixed>|null $context  Where the anchor is.
	 * @return string[]
	 */
	public function hook_navigation( $hooked, $position, $anchor, $context ) {
		$pos = (string) ( self::menu_settings()['menu_pos'] ?? 'end' );
		// «After this item»: hooked after every top-level link, kept only after the chosen one (hooked_submenu).
		$ok = 'after' === $pos
			? ( 'after' === $position && in_array( $anchor, array( 'core/navigation-link', 'core/navigation-submenu', 'core/home-link' ), true ) )
			: ( ( 'start' === $pos ? 'first_child' : 'last_child' ) === $position && 'core/navigation' === $anchor );
		if ( ! is_array( $hooked ) || ! $ok || is_admin() || ! self::in_menu() || ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return $hooked;
		}
		$loc = (string) ( self::menu_settings()['menu_location'] ?? 'auto' );
		if ( 0 === strpos( $loc, 'nav:' ) && ! ( $context instanceof \WP_Post && (int) substr( $loc, 4 ) === (int) $context->ID ) ) {
			return $hooked;
		}
		$hooked[] = 'core/navigation-submenu';
		return $hooked;
	}

	/**
	 * The hooked submenu: the current language, the others inside. Labels are plain text
	 * (the Navigation block does not take SVG in a label).
	 *
	 * @param array<string,mixed>|null $parsed   The hooked block.
	 * @param string                   $type     Its type.
	 * @param string                   $position Relative position.
	 * @param array<string,mixed>      $anchor   The anchor block.
	 * @param mixed                    $context  Where the anchor is.
	 * @return array<string,mixed>|null
	 */
	public function hooked_submenu( $parsed, $type, $position, $anchor, $context ) {
		unset( $type, $context );
		if ( null === $parsed || Preview::hidden() ) {
			return $parsed;
		}
		$s = self::menu_settings();
		if ( 'after' === $position ) {
			if ( 'after' !== ( $s['menu_pos'] ?? 'end' ) || false !== strpos( (string) ( $anchor['attrs']['className'] ?? '' ), 'trrocket-' )
				|| ! self::same_label( (string) ( $anchor['attrs']['label'] ?? ( 'core/home-link' === ( $anchor['blockName'] ?? '' ) ? __( 'Home' ) : '' ) ), (string) ( $s['menu_after'] ?? '' ) ) ) { // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- the core label.
				return null;
			}
		} elseif ( ! in_array( $position, array( 'first_child', 'last_child' ), true ) || 'core/navigation' !== ( $anchor['blockName'] ?? '' ) ) {
			return $parsed;
		}
		$entries = $this->entries( array( 'type' => 'dropdown', 'current' => 'show' ), ! empty( $s['english_names'] ) );
		if ( count( $entries ) < 2 ) {
			return null;
		}
		$show = (string) ( $s['show'] ?? 'both' );
		// The flag marks are for the site only: the block editor (which shows the header around the
		// page too) would keep them as text in the label, and stalled on them.
		$sito  = ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! wp_doing_ajax();
		$label = function ( array $e ) use ( $show, $sito ) { // phpcs:ignore -- $this is bound.
			$t    = self::label_text( $e, $show );
			$this->nav_svg[ $e['code'] ] = (string) $e['svg'];
			$flag = $sito && in_array( $show, array( 'both', 'flag', 'flagcode' ), true ) ? self::FLAG_MARK_OPEN . $e['code'] . self::FLAG_MARK_CLOSE . ( '' !== $t ? ' ' : '' ) : '';
			return $flag . ( '' !== $t || '' !== $flag ? $t : strtoupper( explode( '-', (string) $e['code'] )[0] ) );
		};
		$current = $entries[0];
		foreach ( $entries as $e ) {
			if ( $e['current'] ) {
				$current = $e;
			}
		}
		$inner = array();
		foreach ( $entries as $e ) {
			if ( $e['code'] === $current['code'] ) {
				continue;
			}
			$inner[] = array(
				'blockName'    => 'core/navigation-link',
				'attrs'        => array( 'label' => esc_html( $label( $e ) ), 'url' => (string) $e['href'], 'kind' => 'custom', 'isTopLevelLink' => false, 'className' => 'trrocket-lang-item trrocket-lang-' . $e['code'] ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		}
		$this->in_menu_done = true;
		return array(
			'blockName'    => 'core/navigation-submenu',
			'attrs'        => array( 'label' => esc_html( $label( $current ) ), 'url' => (string) $current['href'], 'kind' => 'custom', 'className' => 'trrocket-lang-item trrocket-lang-current' ),
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $inner ), null ),
		);
	}

	/**
	 * Two menu labels name the same item (tags, entities, case and spaces aside).
	 *
	 * @param string $a Label.
	 * @param string $b Label.
	 */
	public static function same_label( string $a, string $b ): bool {
		$n = static function ( string $x ): string {
			$x = html_entity_decode( wp_strip_all_tags( $x ), ENT_QUOTES, 'UTF-8' );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $x ) ), 'UTF-8' ) : strtolower( trim( $x ) );
		};
		return '' !== $n( $b ) && $n( $a ) === $n( $b );
	}

	const FLAG_MARK_OPEN  = '[[trrflag:';

	/**
	 * Flags (custom ones included) of the languages put in a Navigation block.
	 *
	 * @var array<string,string>
	 */
	private $nav_svg = array();

	const FLAG_MARK_CLOSE = ']]';

	/**
	 * Our Navigation-block items: the flag marks become flags in the text, and are
	 * dropped from attributes (aria-label «English submenu» must stay plain words).
	 *
	 * @param string              $html  Rendered block.
	 * @param array<string,mixed> $block Parsed block.
	 */
	public function nav_flags( $html, $block ) {
		if ( ! is_string( $html ) || false === strpos( (string) ( $block['attrs']['className'] ?? '' ), 'trrocket-lang-' ) ) {
			return $html;
		}
		if ( false !== strpos( (string) ( $block['attrs']['className'] ?? '' ), 'trrocket-lang-current' ) && 'click' === ( self::menu_settings()['dd_trigger'] ?? '' ) ) {
			$html = self::nav_on_click( $html );
		}
		if ( false === strpos( $html, self::FLAG_MARK_OPEN ) ) {
			return $html;
		}
		$mark = '/' . preg_quote( self::FLAG_MARK_OPEN, '/' ) . '([a-z0-9-]{2,12})' . preg_quote( self::FLAG_MARK_CLOSE, '/' ) . '\s*/';
		$html = (string) preg_replace_callback(
			'/="[^"]*"/',
			static function ( $m ) use ( $mark ) {
				return (string) preg_replace( $mark, '', $m[0] );
			},
			$html
		);
		return (string) preg_replace_callback(
			$mark,
			function ( $m ) {
				$svg = $this->nav_svg[ $m[1] ] ?? \TranslateRocket\Flags::markup( $m[1] );
				return '' !== $svg ? '<span class="trrocket-flag-wrap" aria-hidden="true">' . $svg . '</span> ' : '';
			},
			$html
		);
	}

	/**
	 * WordPress adds the «open on hover» handlers to every submenu once the whole
	 * Navigation block is drawn: ours, when it opens on click, loses them again.
	 *
	 * @param string $html Rendered Navigation block.
	 */
	public function nav_no_hover( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, 'trrocket-lang-current' ) || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$p = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag( array( 'tag_name' => 'LI', 'class_name' => 'trrocket-lang-current' ) ) ) {
			if ( $p->has_class( 'open-on-click' ) ) {
				$p->remove_attribute( 'data-wp-on--pointerenter' );
				$p->remove_attribute( 'data-wp-on--pointerleave' );
			}
		}
		return $p->get_updated_html();
	}

	/**
	 * «Open: on click» for our languages submenu in a Navigation block, without
	 * changing how the theme's other submenus open. The block hands this choice
	 * only to the whole menu, so our item is drawn the way WordPress draws an
	 * on-click submenu: the label is the button, no opening on hover.
	 *
	 * @param string $html Rendered submenu.
	 */
	private static function nav_on_click( string $html ): string {
		$first = strpos( $html, '<li' );
		$end   = false !== $first ? strpos( $html, '>', $first ) : false;
		if ( false === $end ) {
			return $html;
		}
		$li   = substr( $html, $first, $end - $first + 1 );
		$li2  = str_replace( 'open-on-hover-click', 'open-on-click', $li );
		$li2  = (string) preg_replace( '/\s+data-wp-on--pointer(?:enter|leave)="[^"]*"/', '', $li2 );
		$html = substr( $html, 0, $first ) . $li2 . substr( $html, $end + 1 );
		return (string) preg_replace(
			'#<a class="wp-block-navigation-item__content"[^>]*>(.*?)</a>\s*<button([^>]*?)aria-label="([^"]*)"([^>]*?)class="wp-block-navigation__submenu-icon wp-block-navigation-submenu__toggle"[^>]*>(.*?)</button>#s',
			'<button$2aria-label="$3"$4class="wp-block-navigation-item__content wp-block-navigation-submenu__toggle" aria-expanded="false">$1</button><span class="wp-block-navigation__submenu-icon">$5</span>',
			$html,
			1
		);
	}

	/**
	 * Core builder shared by the shortcode, block and floating switcher.
	 *
	 * @param array{type:string,show:string,current:string} $atts    Resolved options.
	 * @param bool                                           $english Use English language names.
	 */
	private function build( array $atts, bool $english ): string {
		$entries = $this->entries( $atts, $english );
		if ( empty( $entries ) ) {
			return '';
		}
		return $this->draw( $entries, $atts );
	}

	/**
	 * The languages to show, each with its address for the page being viewed.
	 *
	 * @param array<string,mixed> $atts    Resolved options (type, current).
	 * @param bool                $english Use English language names.
	 * @return array<int,array<string,mixed>>
	 */
	private function entries( array $atts, bool $english ): array {
		$router = Plugin::instance()->router();
		// Le lingue ancora in lavorazione non compaiono nel selettore: chi le
		// sta traducendo (di norma l'amministratore) le vede lo stesso.
		$langs  = $router->public_languages();
		// The Switcher page's «Your site, live» is the visitors' view: no offline languages there either.
		if ( $this->solo_pubbliche || null !== self::trying() ) {
			$langs = array_values( array_diff( $langs, $router->offline_languages() ) );
		}
		if ( count( $langs ) < 2 ) {
			return array();
		}

		$current = $router->current_language();
		$post_id = ( function_exists( 'is_singular' ) && is_singular() ) ? (int) get_queried_object_id() : 0;

		$entries = array();
		foreach ( $langs as $code ) {
			$is_current = ( $code === $current );
			// A dropdown always needs the current language for its toggle button.
			if ( $is_current && 'hide' === ( $atts['current'] ?? 'show' ) && 'dropdown' !== ( $atts['type'] ?? '' ) ) {
				continue;
			}

			$href = $router->url_for_language( $code );
			if ( ! $is_current && $post_id > 0 && ! $router->is_default( $code ) ) {
				$rule = Exclusions::get( $post_id, $code );
				if ( '404' === $rule['mode'] ) {
					continue;
				}
				if ( 'home' === $rule['mode'] ) {
					$href = $router->home_for_language( $code );
				} elseif ( 'url' === $rule['mode'] && '' !== $rule['url'] ) {
					$href = $rule['url'];
				}
			}

			$cust  = Languages::flag( $code );
			$emoji = ( '' !== $cust && 0 !== strpos( $cust, 'svg:' ) ) ? $cust : "\xF0\x9F\x8C\x90";
			$entries[] = array(
				'code'    => $code,
				'name'    => $english ? Languages::english_label( $code ) : Languages::label( $code ),
				'emoji'   => $emoji,
				'svg'     => Flags::markup( $code, $cust ),
				'href'    => $href,
				'current' => $is_current,
			);
		}

		return $entries;
	}

	/**
	 * Draw the entries in the chosen layout.
	 *
	 * @param array<int,array<string,mixed>> $entries Entries.
	 * @param array<string,mixed>            $atts    Resolved options.
	 */
	private function draw( array $entries, array $atts ): string {
		if ( 'dropdown' === $atts['type'] ) {
			return $this->render_dropdown( $entries, (string) $atts['show'], (string) ( $atts['trigger'] ?? 'click' ), '0' !== (string) ( $atts['caret'] ?? '1' ) );
		}
		if ( 'scroll' === $atts['type'] ) {
			$items = '';
			foreach ( $entries as $entry ) {
				$label   = $this->label_html( $entry, (string) $atts['show'] );
				$items  .= $entry['current']
					? '<li class="trrocket-current"><span>' . $label . '</span></li>'
					: '<li><a href="' . esc_url( $entry['href'] ) . '">' . $label . '</a></li>';
			}
			$pv = '<button type="button" class="trrocket-scroll-prev" aria-label="' . esc_attr__( 'Previous', 'translate-rocket' ) . '" disabled><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg></button>';
			$nx = '<button type="button" class="trrocket-scroll-next" aria-label="' . esc_attr__( 'More', 'translate-rocket' ) . '"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg></button>';
			return '<div class="trrocket-scroll">' . $pv . '<div class="trrocket-scroll-viewport"><ul class="trrocket-switcher trrocket-scroll-track">' . $items . '</ul></div>' . $nx . '</div>';
		}
		return $this->render_list( $entries, 'list' === $atts['type'], (string) $atts['show'], 'grid' === $atts['type'] );
	}

	/**
	 * Safe HTML label for one entry: SVG flag (or escaped emoji) + escaped name.
	 *
	 * @param array{name:string,emoji:string,svg:string} $entry Entry.
	 * @param string                                      $show  both|flag|name.
	 */
	private function label_html( array $entry, string $show ): string {
		$con_bandiera = in_array( $show, array( 'both', 'flag', 'flagcode' ), true );
		$testo        = self::label_text( $entry, $show );

		$parts = array();
		if ( $con_bandiera ) {
			$flag    = ( '' !== $entry['svg'] ) ? $entry['svg'] : esc_html( $entry['emoji'] );
			$parts[] = '<span class="trrocket-flag-wrap">' . $flag . '</span>';
		}
		if ( $con_bandiera && '' !== $testo ) {
			$divmap = array(
				'pipe'   => '|',
				'bullet' => '•',
				'slash'  => '/',
				'dash'   => '–',
				'dot'    => '·',
			);
			if ( isset( $divmap[ $this->divider ] ) ) {
				$parts[] = '<span class="trrocket-divider" aria-hidden="true">' . esc_html( $divmap[ $this->divider ] ) . '</span>';
			}
		}
		if ( '' !== $testo ) {
			$parts[] = '<span class="trrocket-name">' . esc_html( $testo ) . '</span>';
		}
		return implode( ' ', $parts );
	}

	/**
	 * The words next to the flag, if any: full name, short code, or nothing.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @param string              $show  both|flag|name|code|flagcode.
	 */
	private static function label_text( array $entry, string $show ): string {
		if ( 'flag' === $show ) {
			return '';
		}
		if ( 'code' === $show || 'flagcode' === $show ) {
			// The bit before any region suffix, upper-cased: pt-br becomes PT.
			$code = (string) ( $entry['code'] ?? '' );
			return strtoupper( explode( '-', $code )[0] );
		}
		return (string) $entry['name'];
	}

	/**
	 * Render the switcher as a list (horizontal by default, vertical if asked).
	 *
	 * @param array<int,array<string,mixed>> $entries  Entries.
	 * @param bool                           $vertical Stack vertically.
	 * @param string                         $show     both|flag|name.
	 */
	private function render_list( array $entries, bool $vertical, string $show, bool $grid = false ): string {
		$class = 'trrocket-switcher' . ( $vertical ? ' trrocket-vertical' : '' ) . ( $grid ? ' trrocket-grid' : '' );
		$items = '';
		foreach ( $entries as $entry ) {
			$label = $this->label_html( $entry, $show );
			if ( $entry['current'] ) {
				$items .= '<li class="trrocket-current"><span>' . $label . '</span></li>';
			} else {
				$items .= '<li><a href="' . esc_url( $entry['href'] ) . '">' . $label . '</a></li>';
			}
		}
		return '<ul class="' . esc_attr( $class ) . '">' . $items . '</ul>';
	}

	/**
	 * Render a custom dropdown: a toggle button showing the current language and
	 * a pop-up list of the others. Unlike a native <select>, this can show the
	 * SVG flags. Opens on click (JS) or focus/hover (CSS fallback).
	 *
	 * @param array<int,array<string,mixed>> $entries Entries.
	 * @param string                         $show    both|flag|name.
	 */
	private function render_dropdown( array $entries, string $show, string $trigger = 'click', bool $caret = true ): string {
		$current = null;
		$others  = array();
		foreach ( $entries as $entry ) {
			if ( $entry['current'] && null === $current ) {
				$current = $entry;
			} else {
				$others[] = $entry;
			}
		}
		if ( null === $current ) {
			$current = array_shift( $others );
		}
		if ( null === $current ) {
			return '';
		}

		$menu   = '';
		$widest = $current;
		foreach ( $others as $entry ) {
			$menu .= '<li><a href="' . esc_url( $entry['href'] ) . '">' . $this->label_html( $entry, $show ) . '</a></li>';
			if ( mb_strlen( self::label_text( $entry, $show ) ) > mb_strlen( self::label_text( $widest, $show ) ) ) {
				$widest = $entry;
			}
		}

		$dd_class   = 'trrocket-dd' . ( 'hover' === $trigger ? ' trrocket-dd-hover' : '' );
		$caret_html = $caret
			? '<span class="trrocket-dd-caret" aria-hidden="true"><svg viewBox="0 0 10 6" width="10" height="6"><path d="M1 1l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>'
			: '';

		// Width technique borrowed from switchers that work well (e.g. TranslatePress):
		// an invisible, in-flow ANCHOR sized to the WIDEST item fixes the control's
		// width; the interactive OVERLAY (real toggle + menu) sits on top, both at
		// width:100% of that anchor. So the toggle and the open menu are ALWAYS the
		// exact same width, with no JavaScript measuring.
		// The anchor gives the toggle and the menu their width. It held only the name with
		// the most LETTERS, which is not always the widest on screen (Ш, W, 語 against i, l):
		// a wider name was cut off in the open menu. Now every name is stacked in the same
		// spot, invisible, and the widest one really decides (24/9/2026).
		$anchor_label = $this->label_html( $widest, $show );
		$tutti        = '';
		foreach ( array_merge( array( $current ), $others ) as $entry ) {
			$tutti .= '<span>' . esc_html( self::label_text( $entry, $show ) ) . '</span>';
		}
		$nome_largo = '<span class="trrocket-name">' . esc_html( self::label_text( $widest, $show ) ) . '</span>';
		if ( '' !== self::label_text( $widest, $show ) && false !== strpos( $anchor_label, $nome_largo ) ) {
			$anchor_label = str_replace( $nome_largo, '<span class="trrocket-name trrocket-dd-names">' . $tutti . '</span>', $anchor_label );
		}
		$anchor = '<div class="trrocket-dd-anchor" aria-hidden="true">'
			. '<span class="trrocket-dd-toggle">' . $anchor_label . $caret_html . '</span>'
			. '</div>';

		return '<div class="' . esc_attr( $dd_class ) . '">'
			. $anchor
			. '<div class="trrocket-dd-overlay">'
			. '<button type="button" class="trrocket-dd-toggle" aria-haspopup="true" aria-expanded="false">'
			. $this->label_html( $current, $show )
			. $caret_html
			. '</button>'
			. '<ul class="trrocket-dd-menu">' . $menu . '</ul>'
			. '</div>'
			. '</div>';
	}
}
