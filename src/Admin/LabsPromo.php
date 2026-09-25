<?php
/**
 * «Labs ✨»: what the free add-on adds, for whoever does not have it yet.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One page inside TranslateRocket that says what TranslateRocket Labs adds, and where
 * to ask for it. It opens only when clicked: no notice, no banner, nothing that comes
 * back (WordPress.org's rules on upselling). When Labs is installed the page goes away
 * and the «Labs ✨» tab leads to Labs itself.
 */
class LabsPromo {

	const SLUG = 'translate-rocket-labs-info';
	const URL  = 'https://translaterocket.com/labs/';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_post_trrocket_labs_banner', array( $this, 'toggle_banner' ) );
	}

	/**
	 * «Hide» / «Show» on the banner: per user, remembered. Hidden, it stays as one short line.
	 */
	public function toggle_banner(): void {
		check_admin_referer( 'trrocket_labs_banner' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'translate-rocket' ) );
		}
		update_user_meta( get_current_user_id(), 'trrocket_labs_banner_min', empty( $_GET['show'] ) ? 1 : 0 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=translate-rocket' ) );
		exit;
	}

	/**
	 * The Labs box on the plugin home and on AI Translation: what you gain, in four points,
	 * and a button. Only inside TranslateRocket, only while Labs is not installed, and it
	 * can be folded into one line (never removed from the pages where it helps).
	 *
	 * @param string $from Which screen it is on.
	 */
	public static function banner( string $from ): void {
		if ( self::installed() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$toggle = static function ( bool $show ): string {
			return wp_nonce_url( admin_url( 'admin-post.php?action=trrocket_labs_banner' . ( $show ? '&show=1' : '' ) ), 'trrocket_labs_banner' );
		};
		$page = admin_url( 'admin.php?page=' . self::SLUG );
		if ( get_user_meta( get_current_user_id(), 'trrocket_labs_banner_min', true ) ) {
			echo '<p class="trr-lb-min">✨ <strong>TranslateRocket Labs</strong> — ' . esc_html__( 'free: translate with no API key, from any browser, automatically.', 'translate-rocket' )
				. ' <a href="' . esc_url( $page ) . '">' . esc_html__( 'See what Labs adds', 'translate-rocket' ) . ' →</a>'
				. ' <a class="trr-lb-toggle" href="' . esc_url( $toggle( true ) ) . '">' . esc_html__( 'Show', 'translate-rocket' ) . '</a></p>';
			return;
		}
		$vantaggi = array(
			array( 'dashicons-unlock', __( 'No API key needed', 'translate-rocket' ), __( 'No account, no card: switch it on and your site gets translated.', 'translate-rocket' ) ),
			array( 'dashicons-smartphone', __( 'Any browser, even phones', 'translate-rocket' ), __( 'Firefox, Safari, a tablet: your server does the work, not Chrome.', 'translate-rocket' ) ),
			array( 'dashicons-performance', __( 'Translates by itself', 'translate-rocket' ), __( 'The whole site in one click, and every page you publish is translated automatically.', 'translate-rocket' ) ),
			array( 'dashicons-randomize', __( 'Never stuck', 'translate-rocket' ), __( 'Key out of credit or engine busy? The next one in your order takes over.', 'translate-rocket' ) ),
		);
		?>
		<div class="trr-lb" role="region" aria-label="TranslateRocket Labs">
			<a class="trr-lb-hide" href="<?php echo esc_url( $toggle( false ) ); ?>" title="<?php esc_attr_e( 'Fold into one line', 'translate-rocket' ); ?>"><?php esc_html_e( 'Hide', 'translate-rocket' ); ?> ×</a>
			<div class="trr-lb-head">
				<span class="trr-lb-gem" aria-hidden="true">✨</span>
				<div>
					<div class="trr-lb-tit">TranslateRocket Labs <span class="trr-lb-free"><?php esc_html_e( 'Free', 'translate-rocket' ); ?></span></div>
					<div class="trr-lb-sub"><?php esc_html_e( 'The free add-on that does the translating for you.', 'translate-rocket' ); ?></div>
				</div>
			</div>
			<ul class="trr-lb-list">
				<?php foreach ( $vantaggi as $v ) : ?>
					<li><span class="dashicons <?php echo esc_attr( $v[0] ); ?>" aria-hidden="true"></span><div><strong><?php echo esc_html( $v[1] ); ?></strong><span><?php echo esc_html( $v[2] ); ?></span></div></li>
				<?php endforeach; ?>
			</ul>
			<div class="trr-lb-cta">
				<a class="trr-lb-btn" href="<?php echo esc_url( self::site_url( $from ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ask for Labs — free', 'translate-rocket' ); ?> ↗</a>
				<a class="trr-lb-more" href="<?php echo esc_url( $page ); ?>"><?php esc_html_e( 'See everything Labs adds', 'translate-rocket' ); ?> →</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Is Labs installed and running?
	 */
	public static function installed(): bool {
		return defined( 'TRRLABS_VERSION' );
	}

	/**
	 * Where «Labs ✨» leads: this page, or Labs itself when installed.
	 */
	public static function slug(): string {
		return self::installed() ? 'translate-rocket-labs' : self::SLUG;
	}

	/**
	 * The link to translaterocket.com/labs/, marked as coming from the plugin.
	 *
	 * @param string $from Which screen the link is on.
	 */
	public static function site_url( string $from = 'labs-page' ): string {
		return add_query_arg( array( 'utm_source' => 'plugin', 'utm_medium' => $from ), self::URL );
	}

	/**
	 * Submenu (only while Labs is not installed: Labs has its own).
	 */
	public function menu(): void {
		if ( self::installed() ) {
			return;
		}
		add_submenu_page(
			'translate-rocket',
			__( 'TranslateRocket Labs', 'translate-rocket' ),
			__( 'Labs ✨', 'translate-rocket' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The page.
	 */
	public function render(): void {
		$tile = static function ( string $ico, string $tit, string $txt, string $c1, string $c2 ): void {
			echo '<div class="trr-lp-tile" style="background:linear-gradient(135deg,' . esc_attr( $c1 ) . ',' . esc_attr( $c2 ) . ')">'
				. '<div class="trr-lp-ico" aria-hidden="true"><span class="dashicons ' . esc_attr( $ico ) . '"></span></div>'
				. '<h3>' . esc_html( $tit ) . '</h3><p>' . esc_html( $txt ) . '</p></div>';
		};
		$feat = static function ( string $ico, string $tit, string $txt ): void {
			echo '<div class="trr-lp-feat"><span class="trr-lp-fico" aria-hidden="true">' . esc_html( $ico ) . '</span><div><strong>' . esc_html( $tit ) . '</strong><br><span>' . esc_html( $txt ) . '</span></div></div>';
		};
		?>
		<div class="wrap trrocket-wrap">
			<?php Admin::header( self::SLUG ); ?>
			<style>
				.trr-lp{max-width:1080px}
				.trr-lp-hero{text-align:center;padding:26px 16px 8px}
				.trr-lp-hero h1{font-size:34px;line-height:1.15;margin:0 0 8px;font-weight:900;background:linear-gradient(90deg,#ec4899,#a855f7 35%,#3b82f6 68%,#22d3ee);-webkit-background-clip:text;background-clip:text;color:transparent;-webkit-text-fill-color:transparent}
				.trr-lp-hero p{font-size:17px;color:#3c434a;max-width:720px;margin:0 auto}
				.trr-lp-badge{display:inline-block;margin:14px 0 4px;padding:8px 20px;border-radius:999px;color:#fff;font-weight:800;background:linear-gradient(90deg,#ec4899,#8b5cf6 45%,#3b82f6 80%,#22d3ee);box-shadow:0 10px 24px rgba(139,92,246,.3)}
				.trr-lp-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(210px,100%),1fr));gap:14px;margin:22px 0}
				.trr-lp-tile{box-sizing:border-box;border-radius:18px;padding:20px 18px;color:#fff;box-shadow:0 12px 26px rgba(79,70,229,.2)}
				.trr-lp-tile h3{color:#fff;margin:10px 0 6px;font-size:18px}
				.trr-lp-tile p{margin:0;color:rgba(255,255,255,.94);font-size:14px;line-height:1.5}
				.trr-lp-ico{width:46px;height:46px;border-radius:14px;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center}
				.trr-lp-ico .dashicons{color:#fff;font-size:26px;width:26px;height:26px}
				.trr-lp-feat strong{font-weight:700;color:#1d2327}
				@media (max-width:600px){.trr-lp-hero h1{font-size:27px}.trr-lp-hero p{font-size:15px}.trr-lp-hero{padding-top:14px}}
				.trr-lp-box{background:#fff;border:1px solid #e3e5f6;border-radius:16px;padding:20px 22px;margin:0 0 18px}
				.trr-lp-box h2{margin:0 0 12px}
				.trr-lp-feats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr));gap:12px 22px}
				.trr-lp-feat{display:flex;gap:10px;align-items:flex-start;line-height:1.45}
				.trr-lp-fico{font-size:20px;flex:0 0 26px;text-align:center}
				.trr-lp-feat span{color:#50575e}
				.trr-lp-chain{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:6px 0 4px}
				.trr-lp-step{padding:8px 12px;border-radius:10px;background:#f4f3ff;border:1px solid #d9d6fe;font-weight:600}
				.trr-lp-arrow{color:#8b5cf6;font-weight:800}
				.trr-lp-cta{text-align:center;margin:26px 0 8px}
				.trr-lp-cta a{display:inline-block;padding:14px 34px;border-radius:999px;color:#fff;font-size:17px;font-weight:800;text-decoration:none;background:linear-gradient(90deg,#ec4899,#8b5cf6 38%,#3b82f6 72%,#22d3ee);box-shadow:0 10px 26px rgba(139,92,246,.35)}
				.trr-lp-cta a:focus{box-shadow:0 0 0 3px #fff,0 0 0 5px #2271b1}
				.trr-lp-note{color:#646970;font-size:13px;max-width:760px;margin:10px auto 0;text-align:center}
			</style>
			<div class="trr-lp">
				<div class="trr-lp-hero">
					<h1><span aria-hidden="true" style="-webkit-text-fill-color:initial">✨</span> TranslateRocket Labs</h1>
					<p><?php esc_html_e( 'A free add-on for TranslateRocket that translates your site with no API key, from any browser — phones included — and does more of the work by itself.', 'translate-rocket' ); ?></p>
					<span class="trr-lp-badge"><?php esc_html_e( 'Free, on request — one key per site', 'translate-rocket' ); ?></span>
				</div>

				<div class="trr-lp-tiles">
					<?php
					$tile( 'dashicons-performance', __( 'Instant translation', 'translate-rocket' ), __( 'Your whole site in one click — and every new page translates itself the moment you publish it.', 'translate-rocket' ), '#ec4899', '#8b5cf6' );
					$tile( 'dashicons-unlock', __( 'No API key', 'translate-rocket' ), __( 'No account, no card, nothing to set up. Switch it on and it translates.', 'translate-rocket' ), '#8b5cf6', '#3b82f6' );
					$tile( 'dashicons-smartphone', __( 'Any browser, phones too', 'translate-rocket' ), __( 'Your server does the work: Firefox, Safari, a phone or a tablet — it all works.', 'translate-rocket' ), '#3b82f6', '#22d3ee' );
					$tile( 'dashicons-randomize', __( 'Always translated', 'translate-rocket' ), __( 'Your API keys, the browser, and free engines — in the order you choose. If one stops, the next takes over.', 'translate-rocket' ), '#0ea5e9', '#ec4899' );
					?>
				</div>

				<div class="trr-lp-box">
					<h2><?php esc_html_e( 'What Labs does by itself', 'translate-rocket' ); ?></h2>
					<div class="trr-lp-feats">
						<?php
						$feat( '🌍', __( 'The whole site in one click', 'translate-rocket' ), __( 'Every published page, post and product, a few at a time, while you do something else.', 'translate-rocket' ) );
						$feat( '📝', __( 'Translate on publish', 'translate-rocket' ), __( 'Publish or update a page: its new text is translated into your languages in the background.', 'translate-rocket' ) );
						$feat( '🧭', __( 'Menus, widgets, Customizer', 'translate-rocket' ), __( 'Changes that appear on every page are picked up and translated by themselves.', 'translate-rocket' ) );
						$feat( '🤖', __( 'Free engines, no key', 'translate-rocket' ), __( 'Yandex, Microsoft Edge, MyMemory and Google, each switched on only after you read and accept its conditions.', 'translate-rocket' ) );
						$feat( '💎', __( 'Polish with AI', 'translate-rocket' ), __( 'With an AI key, a little of the free engines\' work is translated again every day, so quality grows over time.', 'translate-rocket' ) );
						$feat( '📬', __( 'A weekly report', 'translate-rocket' ), __( 'Every Monday, a short e-mail: what was translated and what is still waiting.', 'translate-rocket' ) );
						$feat( '🔄', __( 'Updates itself', 'translate-rocket' ), __( 'New versions appear in Plugins, like any other update.', 'translate-rocket' ) );
						$feat( '🛟', __( 'Pauses on its own', 'translate-rocket' ), __( 'If a service asks to slow down, Labs pauses it and the next engine takes over.', 'translate-rocket' ) );
						?>
					</div>
				</div>

				<div class="trr-lp-box">
					<h2><?php esc_html_e( 'You choose the order', 'translate-rocket' ); ?></h2>
					<div class="trr-lp-chain" aria-label="<?php esc_attr_e( 'An example of priority order', 'translate-rocket' ); ?>">
						<span class="trr-lp-step">1 · <?php esc_html_e( 'Your API key', 'translate-rocket' ); ?></span><span class="trr-lp-arrow">→</span>
						<span class="trr-lp-step">2 · Chrome</span><span class="trr-lp-arrow">→</span>
						<span class="trr-lp-step">3 · Microsoft Edge</span><span class="trr-lp-arrow">→</span>
						<span class="trr-lp-step">4 · Yandex</span>
					</div>
					<p style="margin:10px 0 0;color:#50575e"><?php esc_html_e( 'Drag the engines into the order you want and switch each one on or off. If one is missing, out of credit or paused, the next one translates the page: one way or another, every page gets translated.', 'translate-rocket' ); ?></p>
				</div>

				<div class="trr-lp-cta">
					<a href="<?php echo esc_url( self::site_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Ask for Labs — free', 'translate-rocket' ); ?> ↗</a>
					<p class="trr-lp-note"><?php esc_html_e( 'Labs is a separate plugin, sent by e-mail with a key for your site. The free TranslateRocket stays complete and unchanged. Some engines in Labs are unofficial services: they are optional, each with its own conditions, and marked as experimental.', 'translate-rocket' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
