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
	 * Hook into WordPress.
	 */
	public function boot(): void {
		add_shortcode( 'translaterocket_switcher', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_footer', array( $this, 'floating' ) );
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
		$sw = Settings::get()['switcher'] ?? array();
		return is_array( $sw ) ? $sw : array();
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
			$out .= '@media(max-width:782px){' . $scope . ' .trrocket-name{display:none;}}';
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
		if ( Preview::hidden() ) {
			return;
		}
		$s = self::settings();
		if ( empty( $s['floating'] ) ) {
			return;
		}
		$this->divider = (string) ( $s['divider'] ?? 'none' );
		$pos  = in_array( $s['float_pos'] ?? '', array( 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'custom' ), true ) ? $s['float_pos'] : 'bottom-right';
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
	 * Core builder shared by the shortcode, block and floating switcher.
	 *
	 * @param array{type:string,show:string,current:string} $atts    Resolved options.
	 * @param bool                                           $english Use English language names.
	 */
	private function build( array $atts, bool $english ): string {
		$router = Plugin::instance()->router();
		// Le lingue ancora in lavorazione non compaiono nel selettore: chi le
		// sta traducendo (di norma l'amministratore) le vede lo stesso.
		$langs  = $router->public_languages();
		if ( count( $langs ) < 2 ) {
			return '';
		}

		$current = $router->current_language();
		$post_id = ( function_exists( 'is_singular' ) && is_singular() ) ? (int) get_queried_object_id() : 0;

		$entries = array();
		foreach ( $langs as $code ) {
			$is_current = ( $code === $current );
			// A dropdown always needs the current language for its toggle button.
			if ( $is_current && 'hide' === $atts['current'] && 'dropdown' !== $atts['type'] ) {
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

		if ( empty( $entries ) ) {
			return '';
		}

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
		return $this->render_list( $entries, 'list' === $atts['type'], (string) $atts['show'] );
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
	private function render_list( array $entries, bool $vertical, string $show ): string {
		$class = 'trrocket-switcher' . ( $vertical ? ' trrocket-vertical' : '' );
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
		$anchor = '<div class="trrocket-dd-anchor" aria-hidden="true">'
			. '<span class="trrocket-dd-toggle">' . $this->label_html( $widest, $show ) . $caret_html . '</span>'
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
