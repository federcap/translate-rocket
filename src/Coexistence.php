<?php
/**
 * Side-by-side mode: TranslateRocket installed next to another translation plugin.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * While WPML, Polylang, TranslatePress or another translation plugin is active,
 * TranslateRocket leaves the public site alone: no language addresses, no
 * interface language, no switcher, hreflang, sitemap or redirects. Visitors keep
 * seeing the site the other plugin serves. The administrator can meanwhile import
 * that plugin's translations, complete them and preview any page with
 * ?trr-preview=xx.
 *
 * When the other plugin is deactivated, TranslateRocket takes over the language
 * addresses in preview mode (translations visible to administrators only) and
 * offers one button to put them online for everyone.
 *
 * The other way round — TranslateRocket already serving translations, and
 * another translation plugin activated afterwards — nothing changes by itself:
 * the site keeps its addresses and a notice offers to run side by side.
 */
final class Coexistence {

	/**
	 * Query argument an administrator uses to preview a language side by side.
	 */
	const PARAM = 'trr-preview';

	/**
	 * Translation plugins seen active while running side by side.
	 */
	const SEEN = 'trrocket_coexist_seen';

	/**
	 * Names of the plugin(s) just deactivated, while the "go online" step is open.
	 */
	const PENDING = 'trrocket_golive_pending';

	/**
	 * Rewrite rules to rebuild on the next request (the sitemap rule).
	 */
	const FLUSH = 'trrocket_flush_rewrites';

	/**
	 * The administrator chose side by side although TranslateRocket was already
	 * serving translations when the other plugin appeared.
	 */
	const ACCEPTED = 'trrocket_coexist_accepted';

	/**
	 * Active plugins for this request.
	 *
	 * @var array<string,string>|null
	 */
	private static $active = null;

	/**
	 * on() for this request.
	 *
	 * @var bool|null
	 */
	private static $on = null;

	/**
	 * preview_language() for this request.
	 *
	 * @var string|null
	 */
	private static $preview = null;

	/**
	 * Every translation plugin TranslateRocket knows, with how to tell it is running.
	 *
	 * Detected by each plugin's own constant, class or function, so it works whatever
	 * its folder is called. Checked on plugins_loaded, when every plugin file is loaded.
	 *
	 * @return array<string,array{0:string,1:bool}> id => [name, active].
	 */
	private static function known(): array {
		return array(
			'wpml'           => array( 'WPML', defined( 'ICL_SITEPRESS_VERSION' ) ),
			'polylang'       => array( 'Polylang', defined( 'POLYLANG_VERSION' ) || defined( 'POLYLANG_BASENAME' ) ),
			'translatepress' => array( 'TranslatePress', class_exists( 'TRP_Translate_Press', false ) ),
			'weglot'         => array( 'Weglot', defined( 'WEGLOT_VERSION' ) ),
			'gtranslate'     => array( 'GTranslate', defined( 'GTRANSLATE_VERSION' ) || class_exists( 'GTranslate', false ) ),
			'qtranslate'     => array( 'qTranslate-XT', defined( 'QTX_VERSION' ) ),
			'wpglobus'       => array( 'WPGlobus', defined( 'WPGLOBUS_VERSION' ) ),
			'wpm'            => array( 'WP Multilang', defined( 'WPM_PLUGIN_FILE' ) || class_exists( 'WPM\Includes\WP_Multilang', false ) ),
			'bogo'           => array( 'Bogo', defined( 'BOGO_VERSION' ) ),
			'multilanguage'  => array( 'Multilanguage', function_exists( 'mltlngg_init' ) ),
			'falang'         => array( 'Falang', defined( 'FALANG_VERSION' ) ),
			'sublanguage'    => array( 'Sublanguage', class_exists( 'Sublanguage_core', false ) ),
		);
	}

	/**
	 * Translation plugins active right now.
	 *
	 * @return array<string,string> id => name.
	 */
	public static function active(): array {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$found = array();
		foreach ( self::known() as $id => $plugin ) {
			if ( $plugin[1] ) {
				$found[ $id ] = $plugin[0];
			}
		}
		/**
		 * Translation plugins TranslateRocket should run side by side with.
		 *
		 * Return an empty array to let TranslateRocket serve the site anyway, for
		 * example when the other plugin is only kept for something unrelated.
		 *
		 * @param array<string,string> $found id => name of the plugins detected.
		 */
		self::$active = (array) apply_filters( 'trrocket_coexistence_plugins', $found );
		return self::$active;
	}

	/**
	 * Is TranslateRocket running side by side with another translation plugin?
	 *
	 * Yes when one is active and TranslateRocket was not already serving
	 * translations to visitors — a fresh install next to Polylang — or was, and the
	 * administrator chose side by side. A site live on TranslateRocket that tries
	 * another plugin must not lose its language addresses on the spot.
	 */
	public static function on(): bool {
		if ( null !== self::$on ) {
			return self::$on;
		}
		if ( empty( self::active() ) ) {
			self::$on = false;
		} elseif ( get_option( self::SEEN ) || get_option( self::ACCEPTED ) ) {
			self::$on = true;
		} else {
			self::$on = ! self::is_public();
		}
		return self::$on;
	}

	/**
	 * Another translation plugin is active while TranslateRocket keeps serving
	 * the site: the administrator has not chosen side by side (yet).
	 */
	public static function undecided(): bool {
		return ! empty( self::active() ) && ! self::on();
	}

	/**
	 * TranslateRocket shows at least one language to visitors.
	 */
	private static function is_public(): bool {
		$settings = Settings::get();
		if ( 'admins' === ( $settings['serve_mode'] ?? 'everyone' ) ) {
			return false;
		}
		$targets = array_map( 'strtolower', (array) ( $settings['target_languages'] ?? array() ) );
		$offline = array_map( 'strtolower', (array) ( $settings['offline_languages'] ?? array() ) );
		return ! empty( array_diff( $targets, $offline ) );
	}

	/**
	 * Is the plugin behind this importer still active? (Its separate pages are then
	 * still the ones visitors see, so they must not be tidied up yet.)
	 */
	public static function importer_active( string $importer_id ): bool {
		return isset( self::active()[ $importer_id ] );
	}

	/**
	 * Language an administrator is previewing with ?trr-preview=xx, or ''.
	 */
	public static function preview_language(): string {
		if ( null !== self::$preview ) {
			return self::$preview;
		}
		self::$preview = '';
		if ( ! self::on() || is_admin() || empty( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview switch.
			return '';
		}
		/** This filter is documented in src/Frontend/Preview.php */
		if ( ! current_user_can( apply_filters( 'trrocket_preview_capability', 'manage_options' ) ) ) {
			return '';
		}
		$lang    = strtolower( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$targets = array_map( 'strtolower', (array) ( Settings::get()['target_languages'] ?? array() ) );
		if ( in_array( $lang, $targets, true ) ) {
			self::$preview = $lang;
		}
		return self::$preview;
	}

	/**
	 * Hooks.
	 */
	public static function boot(): void {
		if ( self::on() ) {
			// Links inside a previewed page must not be rewritten to /xx/: those
			// addresses belong to the other plugin.
			add_filter( 'trrocket_localize_content_links', '__return_false' );
			// The preview must be one click away, not an address to type.
			add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
			add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
			add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
			if ( ! is_admin() ) {
				// Chi guarda il proprio sito pubblico mentre siamo affiancati non vede
				// nessun cambiamento: ed e' giusto. Ma «funziona come deve» e «e' rotto»
				// si assomigliano troppo, e si perdono ore a cercare un guasto che non
				// c'e'. Una riga, visibile SOLO all'amministratore, lo dice.
				add_action( 'wp_footer', array( __CLASS__, 'front_notice' ), 99 );
				add_action( 'wp_head', array( __CLASS__, 'noindex_preview' ), 1 );
				add_action( 'template_redirect', array( __CLASS__, 'preview_headers' ), 1 );
				// Links on a previewed page keep the preview: menus, permalinks and
				// content links all go through home_url.
				add_filter( 'home_url', array( __CLASS__, 'preview_link' ), 20, 2 );
			}
		}
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'track' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_go_live' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_accept' ) );
			add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		} else {
			// The other plugin may go away without anyone opening wp-admin (WP-CLI,
			// a folder removed by FTP, another site of a network): take over here too.
			add_action( 'init', array( __CLASS__, 'takeover' ), 98 );
		}
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'after_deactivation' ) );
	}

	/**
	 * A plugin was switched off: rebuild the rewrite rules on the next request.
	 *
	 * Translation plugins add their own rules (Falang and Sublanguage one per
	 * language and page) and leave them behind when switched off: WordPress then
	 * reads /our-farmhouse/ with the dead plugin's rules and sends it to the home
	 * page. Rebuilding in THIS request would not help — the plugin is still loaded
	 * and its filters would write the same rules again — so it is booked for the
	 * next one, when the plugin is gone. Any plugin, not only the ones we know: a
	 * rebuild costs one query, a page sent to the home page costs the visitor.
	 */
	public static function after_deactivation(): void {
		update_option( self::FLUSH, 1, true );
	}

	/**
	 * The line an administrator sees at the foot of the public site while another
	 * translation plugin is running the languages. Visitors never see it, and it
	 * is not printed on a preview: there the admin bar already says where they are.
	 */
	public static function front_notice(): void {
		if ( ! self::can_preview() || '' !== self::preview_language() ) {
			return;
		}
		$targets = self::preview_targets();
		if ( empty( $targets ) ) {
			return;
		}
		$altro   = self::active_names();
		$vedi    = Plugin::instance()->router()->url_for_language( $targets[0] );
		$bacheca = admin_url( 'admin.php?page=translate-rocket' );
		?>
		<div class="trr-affiancato" id="trr-affiancato" role="status">
			<span class="trr-affiancato-t">
				<?php
				printf(
					/* translators: %s: the other translation plugin, e.g. "Polylang". */
					esc_html__( 'Only you can see this. TranslateRocket is standing aside while %s runs the languages here, so this page looks exactly as your visitors see it. Nothing is broken.', 'translate-rocket' ),
					esc_html( $altro )
				);
				?>
			</span>
			<a class="trr-affiancato-b" href="<?php echo esc_url( $vedi ); ?>">
				<?php
				printf(
					/* translators: %s: language name, e.g. "Português". */
					esc_html__( 'See this page in %s', 'translate-rocket' ),
					esc_html( Languages::label( $targets[0] ) )
				);
				?>
			</a>
			<a class="trr-affiancato-l" href="<?php echo esc_url( $bacheca ); ?>"><?php esc_html_e( 'How this works', 'translate-rocket' ); ?></a>
			<button type="button" class="trr-affiancato-x" aria-label="<?php esc_attr_e( 'Hide until I come back', 'translate-rocket' ); ?>">&times;</button>
		</div>
		<style>
			#trr-affiancato{position:fixed;left:16px;right:16px;bottom:16px;z-index:99998;display:flex;gap:12px;
				align-items:center;flex-wrap:wrap;background:#14243d;color:#eaf0fb;border-radius:10px;
				padding:12px 16px;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
				box-shadow:0 8px 28px rgba(0,0,0,.28);max-width:980px;margin:0 auto}
			#trr-affiancato .trr-affiancato-t{flex:1 1 320px}
			#trr-affiancato a{color:#fff;text-decoration:none}
			#trr-affiancato .trr-affiancato-b{background:#2f6df6;padding:7px 14px;border-radius:7px;font-weight:600;white-space:nowrap}
			#trr-affiancato .trr-affiancato-l{text-decoration:underline;opacity:.85;white-space:nowrap}
			#trr-affiancato .trr-affiancato-x{background:none;border:0;color:#eaf0fb;font-size:20px;line-height:1;
				cursor:pointer;padding:0 2px;opacity:.7}
			@media (max-width:600px){#trr-affiancato{left:8px;right:8px;bottom:8px;font-size:13px}}
		</style>
		<script>
		( function () {
			var b = document.getElementById( 'trr-affiancato' );
			if ( ! b ) { return; }
			try {
				if ( 'si' === sessionStorage.getItem( 'trrAffiancatoVia' ) ) { b.style.display = 'none'; }
			} catch ( e ) {}
			b.querySelector( '.trr-affiancato-x' ).addEventListener( 'click', function () {
				b.style.display = 'none';
				// Solo per questa visita: alla prossima torna, perche' e' un'informazione
				// che serve finche' la situazione e' quella.
				try { sessionStorage.setItem( 'trrAffiancatoVia', 'si' ); } catch ( e ) {}
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * May the current user open side-by-side previews?
	 */
	public static function can_preview(): bool {
		/** This filter is documented in src/Frontend/Preview.php */
		return current_user_can( apply_filters( 'trrocket_preview_capability', 'manage_options' ) );
	}

	/**
	 * The languages that can be previewed (every target language, online or not).
	 *
	 * @return string[]
	 */
	public static function preview_targets(): array {
		$targets = array_map( 'strtolower', array_map( 'strval', (array) ( Settings::get()['target_languages'] ?? array() ) ) );
		return array_values( array_unique( array_filter( $targets ) ) );
	}

	/**
	 * An address opened as a preview in a language (the home page by default).
	 *
	 * @param string $lang Language code.
	 * @param string $url  Address to preview.
	 */
	public static function preview_url( string $lang, string $url = '' ): string {
		$url = '' === $url ? home_url( '/' ) : $url;
		return add_query_arg( self::PARAM, rawurlencode( $lang ), remove_query_arg( self::PARAM, $url ) );
	}

	/**
	 * The other translation plugin(s), named for a sentence.
	 */
	public static function active_names(): string {
		return self::join_names( self::active() );
	}

	/**
	 * Admin bar: "Preview" with one entry per language. On the site it opens the
	 * page being viewed; in wp-admin, the home page in a new tab.
	 *
	 * @param mixed $bar WP_Admin_Bar.
	 */
	public static function admin_bar( $bar ): void {
		if ( ! is_object( $bar ) || ! method_exists( $bar, 'add_node' ) || ! self::can_preview() ) {
			return;
		}
		$targets = self::preview_targets();
		if ( empty( $targets ) ) {
			return;
		}
		$current = self::preview_language();
		$router  = is_admin() ? null : Plugin::instance()->router();
		$meta    = is_admin() ? array( 'target' => '_blank', 'rel' => 'noopener' ) : array();
		$link    = static function ( string $code ) use ( $router ): string {
			return $router ? $router->url_for_language( $code ) : self::preview_url( $code );
		};
		$bar->add_node(
			array(
				'id'    => 'trrocket-preview',
				/* translators: %s: language name, e.g. "Português". */
				'title' => '👁 ' . esc_html( '' !== $current ? sprintf( __( 'Preview: %s', 'translate-rocket' ), Languages::label( $current ) ) : __( 'Preview translations', 'translate-rocket' ) ),
				'href'   => $link( '' !== $current ? $current : $targets[0] ),
				'meta'   => $meta,
			)
		);
		foreach ( $targets as $code ) {
			$bar->add_node(
				array(
					'parent' => 'trrocket-preview',
					'id'     => 'trrocket-preview-' . sanitize_key( $code ),
					'title'  => esc_html( trim( Languages::flag( $code ) . ' ' . Languages::label( $code ) ) . ( $code === $current ? ' ✓' : '' ) ),
					'href'   => $link( $code ),
					'meta'   => $meta,
				)
			);
		}
		if ( '' !== $current ) {
			$bar->add_node(
				array(
					'parent' => 'trrocket-preview',
					'id'     => 'trrocket-preview-exit',
					'title'  => esc_html__( 'Back to the live site', 'translate-rocket' ),
					'href'   => remove_query_arg( self::PARAM, $link( $current ) ),
				)
			);
		}
	}

	/**
	 * Pages and Posts lists: "Preview: PT ES" on each published row.
	 *
	 * @param mixed $actions Row actions.
	 * @param mixed $post    The row's post.
	 * @return mixed
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) || ! self::can_preview() ) {
			return $actions;
		}
		$targets = self::preview_targets();
		$url     = get_permalink( $post );
		if ( empty( $targets ) || ! is_string( $url ) || '' === $url || self::other_plugin_copy( $url, $targets ) ) {
			return $actions;
		}
		$links = array();
		foreach ( array_slice( $targets, 0, 8 ) as $code ) {
			$links[] = '<a href="' . esc_url( self::preview_url( $code, $url ) ) . '" target="_blank" rel="noopener" title="' . esc_attr( Languages::label( $code ) ) . '">' . esc_html( strtoupper( $code ) ) . '</a>';
		}
		$actions['trrocket_preview'] = esc_html__( 'Preview:', 'translate-rocket' ) . ' ' . implode( ' ', $links );
		return $actions;
	}

	/**
	 * Is this address one of the other plugin's own language copies (/pt/…, ?lang=pt)?
	 * Previewing it would show the other plugin's page, not TranslateRocket's.
	 *
	 * @param string   $url     Permalink.
	 * @param string[] $targets Target language codes.
	 */
	private static function other_plugin_copy( string $url, array $targets ): bool {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $args );
		if ( isset( $args['lang'] ) && is_string( $args['lang'] ) && in_array( strtolower( $args['lang'] ), $targets, true ) ) {
			return true;
		}
		$base = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $base ) && '/' !== $base && 0 === strpos( $path, rtrim( $base, '/' ) ) ) {
			$path = substr( $path, strlen( rtrim( $base, '/' ) ) );
		}
		return (bool) preg_match( '#^/([a-z]{2}(?:-[a-z]{2,4})?)(/|$)#i', '/' . ltrim( $path, '/' ), $m ) && in_array( strtolower( $m[1] ), $targets, true );
	}

	/**
	 * A preview page is for the administrator only: never index it.
	 */
	public static function noindex_preview(): void {
		if ( '' !== self::preview_language() ) {
			echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
		}
	}

	/**
	 * Same for crawlers that read headers, and no page cache for a preview.
	 */
	public static function preview_headers(): void {
		if ( '' !== self::preview_language() && ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			nocache_headers();
		}
	}

	/**
	 * Keep ?trr-preview=xx on the links of a previewed page.
	 *
	 * @param mixed $url  Home URL.
	 * @param mixed $path Path appended to it.
	 * @return mixed
	 */
	public static function preview_link( $url, $path = '' ) {
		$lang = self::preview_language();
		if ( '' === $lang || ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		// REST, feeds and files are not pages to preview.
		if ( is_string( $path ) && preg_match( '#^/?(wp-json|feed|wp-content|wp-includes|wp-admin)(/|$)#', ltrim( $path, '/' ) ) ) {
			return $url;
		}
		if ( preg_match( '#\.(xml|json|js|css|png|jpe?g|gif|svg|webp|ico|pdf)(\?|$)#i', $url ) ) {
			return $url;
		}
		return add_query_arg( self::PARAM, $lang, $url );
	}

	/**
	 * Record the plugins TranslateRocket runs beside; notice the moment they go away.
	 *
	 * WordPress deactivates a plugin and then reloads the Plugins screen, where the
	 * plugin's code is no longer loaded: that is the first request that can tell.
	 */
	public static function track(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active = self::active();
		if ( ! empty( $active ) ) {
			if ( self::on() && get_option( self::SEEN ) !== $active ) {
				update_option( self::SEEN, $active, true );
			}
			if ( get_option( self::PENDING ) ) {
				delete_option( self::PENDING );
			}
			return;
		}
		self::takeover();
	}

	/**
	 * The other plugin is gone: take over the language addresses, but show the
	 * translations to administrators only until someone has looked at them.
	 */
	public static function takeover(): void {
		if ( ! empty( self::active() ) ) {
			return;
		}
		$seen = get_option( self::SEEN );
		if ( empty( $seen ) || ! is_array( $seen ) ) {
			return;
		}
		delete_option( self::SEEN );
		if ( get_option( self::ACCEPTED ) ) {
			delete_option( self::ACCEPTED );
		}

		$settings = Settings::get();
		if ( empty( $settings['target_languages'] ) ) {
			return; // Nothing prepared in TranslateRocket: nothing to put online.
		}
		if ( 'admins' !== ( $settings['serve_mode'] ?? 'everyone' ) ) {
			$settings['serve_mode'] = 'admins';
			update_option( Settings::OPTION, $settings );
		}
		update_option( self::PENDING, array_values( $seen ), true );
		update_option( self::FLUSH, 1, true );
		Cache::flush();
	}

	/**
	 * The "go online" button.
	 */
	public static function maybe_go_live(): void {
		if ( empty( $_GET['trrocket_golive'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'trrocket_golive' );

		$settings               = Settings::get();
		$settings['serve_mode'] = 'everyone';
		update_option( Settings::OPTION, $settings );
		delete_option( self::PENDING );
		update_option( self::FLUSH, 1, true );
		Cache::flush();

		wp_safe_redirect( add_query_arg( 'trrocket_live', '1', remove_query_arg( array( 'trrocket_golive', '_wpnonce' ) ) ) );
		exit;
	}

	/**
	 * The "run side by side" button, for a site that was already live on
	 * TranslateRocket when the other plugin was activated.
	 */
	public static function maybe_accept(): void {
		if ( empty( $_GET['trrocket_coexist'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'trrocket_coexist' );

		update_option( self::ACCEPTED, 1, true );
		update_option( self::SEEN, self::active(), true );
		Cache::flush();

		wp_safe_redirect( remove_query_arg( array( 'trrocket_coexist', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Rebuild rewrite rules once, after the plugin took over the language addresses.
	 */
	public static function maybe_flush_rewrites(): void {
		if ( ! get_option( self::FLUSH ) ) {
			return;
		}
		delete_option( self::FLUSH );
		flush_rewrite_rules( false );
	}

	/**
	 * First language to preview, or '' if none is set up yet.
	 */
	private static function first_target(): string {
		$targets = (array) ( Settings::get()['target_languages'] ?? array() );
		return empty( $targets ) ? '' : strtolower( (string) reset( $targets ) );
	}

	/**
	 * Names joined for a sentence ("Polylang", "WPML and Polylang").
	 *
	 * @param string[] $names Plugin names.
	 */
	private static function join_names( array $names ): string {
		$names = array_values( array_map( 'strval', $names ) );
		if ( count( $names ) < 2 ) {
			return (string) reset( $names );
		}
		$last = array_pop( $names );
		/* translators: 1: comma-separated plugin names, 2: the last plugin name. */
		return sprintf( __( '%1$s and %2$s', 'translate-rocket' ), implode( ', ', $names ), $last );
	}

	/**
	 * Admin notices: side by side, another plugin found on a live site, just
	 * deactivated, online.
	 */
	public static function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['trrocket_live'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$offline = Plugin::instance()->router()->offline_languages();
			echo '<div class="notice notice-success is-dismissible"><p><strong>';
			esc_html_e( 'TranslateRocket is online.', 'translate-rocket' );
			echo '</strong> ';
			esc_html_e( 'Visitors now see the translations, the language switcher and the translated addresses.', 'translate-rocket' );
			if ( ! empty( $offline ) ) {
				$labels = array();
				foreach ( $offline as $code ) {
					$labels[] = Languages::label( $code );
				}
				echo ' ';
				printf(
					/* translators: %s: language names, e.g. "Italiano, Español". */
					esc_html__( 'Still offline, so not visible yet: %s. Switch each one on when it is ready, on the Languages page.', 'translate-rocket' ),
					esc_html( implode( ', ', $labels ) )
				);
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=translate-rocket' ) ) . '">' . esc_html__( 'Open the Languages page', 'translate-rocket' ) . '</a>';
			}
			echo '</p></div>';
		}

		if ( self::undecided() ) {
			$names = self::join_names( self::active() );
			echo '<div class="notice notice-warning"><p><strong>';
			printf(
				/* translators: %s: the other translation plugin(s), e.g. "Polylang". */
				esc_html__( '%s is active, and TranslateRocket keeps serving your translated pages as before.', 'translate-rocket' ),
				esc_html( $names )
			);
			echo '</strong> ';
			esc_html_e( 'Two translation plugins on the same addresses will get in each other’s way. To try the other plugin safely, run TranslateRocket side by side: it steps back from the public site, your translations stay, and you can put it back online with one click when you deactivate the other plugin.', 'translate-rocket' );
			echo '</p><p><a class="button button-primary" href="' . esc_url( wp_nonce_url( add_query_arg( 'trrocket_coexist', '1' ), 'trrocket_coexist' ) ) . '">';
			esc_html_e( 'Run side by side', 'translate-rocket' );
			echo '</a></p></div>';
			return;
		}

		if ( self::on() ) {
			$names  = self::join_names( self::active() );
			$target = self::first_target();
			echo '<div class="notice notice-info"><p><strong>';
			esc_html_e( 'TranslateRocket is running side by side — your site is not affected.', 'translate-rocket' );
			echo '</strong> ';
			printf(
				/* translators: %s: the other translation plugin(s), e.g. "Polylang". */
				esc_html__( '%s is active, so your visitors keep seeing the site exactly as before: TranslateRocket does not change addresses, languages, menus or the language switcher while it is on.', 'translate-rocket' ),
				esc_html( $names )
			);
			echo ' ';
			esc_html_e( 'Meanwhile you can import its translations, complete them and preview any page. When you deactivate it, you can put TranslateRocket online with one click.', 'translate-rocket' );
			echo '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=translate-rocket-import' ) ) . '">';
			esc_html_e( 'Import translations', 'translate-rocket' );
			echo '</a> ';
			if ( '' !== $target ) {
				echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( add_query_arg( self::PARAM, $target, home_url( '/' ) ) ) . '">';
				esc_html_e( 'Preview the translated site', 'translate-rocket' );
				echo '</a>';
			} else {
				echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=translate-rocket' ) ) . '">';
				esc_html_e( 'Choose your languages', 'translate-rocket' );
				echo '</a>';
			}
			echo '</p></div>';
			return;
		}

		$pending = get_option( self::PENDING );
		if ( ! empty( $pending ) && is_array( $pending ) ) {
			$target = self::first_target();
			echo '<div class="notice notice-warning"><p><strong>';
			printf(
				/* translators: %s: the translation plugin(s) just deactivated. */
				esc_html__( '%s is deactivated. TranslateRocket has taken over the translated pages — visible only to administrators for now.', 'translate-rocket' ),
				esc_html( self::join_names( $pending ) )
			);
			echo '</strong> ';
			esc_html_e( 'Visitors still see the original language. Look at a few pages on their real addresses, then put the translations online for everyone.', 'translate-rocket' );
			echo '</p><p><a class="button button-primary" href="' . esc_url( wp_nonce_url( add_query_arg( 'trrocket_golive', '1' ), 'trrocket_golive' ) ) . '">';
			esc_html_e( 'Go online', 'translate-rocket' );
			echo '</a> ';
			if ( '' !== $target ) {
				echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( rtrim( (string) get_option( 'home' ), '/' ) . '/' . $target . '/' ) . '">';
				esc_html_e( 'Check the translated pages', 'translate-rocket' );
				echo '</a>';
			}
			echo '</p></div>';
		}
	}
}
