<?php
/**
 * «Everything you can do, free»: the map at the top of the plugin home.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * 9/10/2026 (Federico: «gli altri plugin in prima pagina ti mettono le opzioni free e a pagamento,
 * da noi non si capisce: ci sono potenzialità immense ma non è chiaro»). Almost everybody who
 * left did it on the first day, many without ever configuring an engine.
 *
 * Two columns, one line each: what TranslateRocket already does (with its state on this site and
 * the page where it is set), and what the free add-on Labs adds. Without Labs those lines carry a
 * lock and one button to get it; with Labs they show on/off and lead to its settings. Everything
 * is free: there is no paid column to hide.
 */
class Overview {

	/**
	 * Print the card.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = Settings::get();
		$targets = (array) ( $s['target_languages'] ?? array() );
		$labs    = LabsPromo::installed();
		$lab     = (array) get_option( 'trrlabs_settings', array() );
		$on      = static function ( string $k ) use ( $lab ): bool {
			return ! empty( $lab[ $k ] );
		};
		$page = static function ( string $slug ): string {
			return admin_url( 'admin.php?page=' . $slug );
		};

		// Where the switcher is, in words.
		$sw   = (array) ( $s['switcher'] ?? array() );
		$dove = '';
		foreach ( (array) ( $s['switchers'] ?? array() ) as $prof ) {
			if ( is_array( $prof ) && ! empty( $prof['in_menu'] ) ) {
				$dove = __( 'in the header menu', 'translate-rocket' );
			}
		}
		if ( '' === $dove ) {
			if ( ! empty( $sw['in_menu'] ) ) {
				$dove = __( 'in the header menu', 'translate-rocket' );
			} elseif ( ! empty( $sw['in_spot'] ) ) {
				$dove = __( 'in a spot of your page', 'translate-rocket' );
			} elseif ( ! empty( $sw['floating'] ) ) {
				$dove = __( 'floating in a corner', 'translate-rocket' );
			}
		}
		$chiave = (string) ( $s['active_provider'] ?? '' );

		$core = array(
			array(
				'ok'   => ! empty( $targets ),
				'tit'  => __( 'Unlimited languages and pages', 'translate-rocket' ),
				'info' => __( 'Add as many languages as you like and translate every page, post, menu, product and form. No word limit, no page limit, nothing to pay. A new language can stay offline, visible only to you, until you are happy with it.', 'translate-rocket' ),
				/* translators: %d: number of languages */
				'stat' => ! empty( $targets ) ? sprintf( __( 'Languages added: %d', 'translate-rocket' ), count( $targets ) ) : __( 'Add your first language below', 'translate-rocket' ),
				'url'  => '#trrocket-source',
			),
			array(
				'ok'   => '' !== $chiave,
				'tit'  => __( 'One-click AI translation with a free key', 'translate-rocket' ),
				'info' => __( 'Paste a free key from Groq, Cloudflare or OpenRouter (or a paid one: DeepL, OpenAI, Google, Anthropic) on AI Translation, then press «Translate all missing». When one provider reaches its daily limit, the next in your order takes over. On Translations you can also pick a single engine.', 'translate-rocket' ),
				/* translators: %s: name of the AI provider */
				'stat' => '' !== $chiave ? sprintf( __( 'On, with %s', 'translate-rocket' ), ucfirst( $chiave ) ) : __( 'Get a free Groq key: 2 minutes, no card', 'translate-rocket' ),
				'url'  => $page( 'translate-rocket-ai' ),
			),
			array(
				'ok'   => true,
				'tit'  => __( 'Translate in your browser, with no key', 'translate-rocket' ),
				'info' => __( 'Chrome and Edge on a computer have a translator built in: TranslateRocket uses it on the Translations screen, with no key and nothing sent to an AI company. The first time, the browser downloads its language model. On phones, use a free AI key or the copy-and-paste tool instead.', 'translate-rocket' ),
				'stat' => __( 'Ready in Chrome and Edge on a computer', 'translate-rocket' ),
				'url'  => $page( 'translate-rocket-strings' ),
			),
			array(
				'ok'   => true,
				'tit'  => __( 'Edit any sentence by hand, on the page', 'translate-rocket' ),
				'info' => __( 'Open any page of your site with «Translate visually» in the admin bar, click a sentence and type your translation. What you write by hand is kept: machine translation only fills in what is still missing.', 'translate-rocket' ),
				'stat' => __( 'Ready: «Translate visually» in the admin bar', 'translate-rocket' ),
				'url'  => $page( 'translate-rocket-strings' ),
			),
			array(
				'ok'   => '' !== $dove,
				'tit'  => __( 'Language switcher', 'translate-rocket' ),
				'info' => __( 'The menu your visitors use to change language, with flags or names. Put it in your header menu, floating in a corner, or in a spot of your page with a block; colours, size and style are set on the Switcher screen.', 'translate-rocket' ),
				/* translators: %s: where the switcher is, e.g. «in the header menu» */
				'stat' => '' !== $dove ? sprintf( __( 'On, %s', 'translate-rocket' ), $dove ) : __( 'Choose where it goes', 'translate-rocket' ),
				'url'  => $page( 'translate-rocket-switcher' ),
			),
			array(
				'ok'   => true,
				'tit'  => __( 'SEO: translated addresses, hreflang, sitemap', 'translate-rocket' ),
				'info' => __( 'Each language has its own addresses (/de/, /fr/…) and you can translate the page addresses too. Hreflang tags tell Google which page is in which language, and the translated pages go into your sitemap. Search engines get real HTML in every language.', 'translate-rocket' ),
				'stat' => __( 'On by itself', 'translate-rocket' ),
				'url'  => '',
			),
			array(
				'ok'   => true,
				'tit'  => __( 'Import from TranslatePress, Weglot, WPML, Polylang', 'translate-rocket' ),
				'info' => __( 'Moving from another plugin? Bring the translations you already have, so you do not translate them again. The import screen shows what it found before anything is saved.', 'translate-rocket' ),
				'stat' => __( 'Ready when you need it', 'translate-rocket' ),
				'url'  => $page( 'translate-rocket-import' ),
			),
		);

		$plus = array(
			array(
				'on'  => $on( 'edge_unofficial' ) || $on( 'mymemory' ) || $on( 'google_unofficial' ) || $on( 'yandex_unofficial' ),
				'tit' => __( 'Translate with no key at all, from your server', 'translate-rocket' ),
				'info' => __( 'Labs adds free engines that need no key and no account — Microsoft Edge Translator, MyMemory, Yandex — called by your server. Some are unofficial endpoints: you read and accept their conditions before they are switched on.', 'translate-rocket' ),
			),
			array(
				'on'  => $on( 'auto_translate' ) || $on( 'auto_site' ),
				'tit' => __( 'The whole site by itself, and every new page as you publish', 'translate-rocket' ),
				'info' => __( 'Labs reads every page of your site and translates it in the background, a little at a time, then translates each new or updated page when you publish it. You follow the progress on the Automatic translation screen.', 'translate-rocket' ),
			),
			array(
				'on'  => $on( 'groq_ai' ) || $on( 'openrouter_ai' ) || $on( 'cloudflare_ai' ),
				'tit' => __( 'More free AI engines, one after the other when one runs out', 'translate-rocket' ),
				'info' => __( 'Set up several free AI providers (Groq, OpenRouter, Cloudflare) and Labs chains them: when one reaches its daily limit, the next continues, so even a large site keeps moving.', 'translate-rocket' ),
			),
			array(
				'on'  => 'browser' !== (string) ( $lab['run_where'] ?? 'both' ),
				'tit' => __( 'Works from any browser, phones too', 'translate-rocket' ),
				'info' => __( 'The work runs on your server, not in the open browser tab: it goes on while you use a phone, a tablet or any browser, and when nobody is in wp-admin at all.', 'translate-rocket' ),
			),
			array(
				'on'  => (bool) array_filter( (array) get_option( 'trrlabs_domains', array() ) ),
				'tit' => __( 'A domain of its own for each language', 'translate-rocket' ),
				'info' => __( 'Give a language its own domain (for example yoursite.de for German) instead of a folder like /de/. Labs checks that the domain and its certificate point to your site, and sends the old addresses to the new ones.', 'translate-rocket' ),
			),
			// 10/10/2026 (Federico: «la traduzione remota è una funzione di Labs che possono usare tutti; nella
			// pagina iniziale non hai messo tutto»): every Labs feature, said for any site owner.
			array(
				'on'  => (bool) array_filter( (array) get_option( 'trrlabs_guest_links', array() ) ),
				'tit' => __( 'Remote collaboration: invite a translator or a reviewer with a secret link — no WordPress account, you approve their work', 'translate-rocket' ),
				'info' => __( 'Create a secret link for a translator, a colleague or a client: they translate or review your pages in the visual editor, without a WordPress account or your password. Their changes wait for your approval (unless you trust the link), and the link can expire or be revoked with one click.', 'translate-rocket' ),
			),
			array(
				'on'  => $on( 'second_opinion' ) || $on( 'polish' ),
				'tit' => __( 'A free AI rereads the machine translations, flags the doubtful ones and polishes them over time', 'translate-rocket' ),
				'info' => __( 'A second free AI reads what the machine translated, marks the sentences that look wrong for you to check and, within a daily limit you choose, rewrites them more naturally.', 'translate-rocket' ),
			),
			array(
				'on'  => true,
				'tit' => __( 'History of every change: who changed a translation, and the way back', 'translate-rocket' ),
				'info' => __( 'Every change to a translation is kept: who made it (a person, a collaborator, the AI, an import), when, and what it said before. Any earlier version can be put back with one click.', 'translate-rocket' ),
			),
			array(
				'on'  => $on( 'weekly_report' ),
				'tit' => __( 'A weekly e-mail: what was translated and what is still waiting', 'translate-rocket' ),
				'info' => __( 'Once a week an e-mail tells you what was translated on your site and what is still waiting, so you know where things stand without opening wp-admin.', 'translate-rocket' ),
			),
		);
		$labs_url = $labs ? $page( 'translate-rocket-labs' ) : $page( LabsPromo::SLUG );
		?>
		<details class="trrocket-card trr-ov" open>
			<summary class="trr-ov-head">
				<span class="trr-ov-tit"><?php esc_html_e( 'Everything you can do — all free', 'translate-rocket' ); ?></span>
				<span class="trr-ov-sub"><?php esc_html_e( 'No paid plan, no word limit. Green is working on your site; click a line to set it up.', 'translate-rocket' ); ?></span>
			</summary>
			<div class="trr-ov-cols">
				<div class="trr-ov-col">
					<h3><?php esc_html_e( 'In TranslateRocket', 'translate-rocket' ); ?> <span class="trr-ov-tag"><?php esc_html_e( 'installed', 'translate-rocket' ); ?></span></h3>
					<ul>
						<?php foreach ( $core as $r ) : ?>
							<li class="<?php echo $r['ok'] ? 'is-on' : 'is-todo'; ?>">
								<span class="trr-ov-dot" aria-hidden="true"><?php echo $r['ok'] ? '✓' : '•'; ?></span>
								<span class="trr-ov-txt">
									<strong><?php echo esc_html( $r['tit'] ); ?> <?php self::info( (string) $r['info'] ); ?></strong>
									<?php if ( '' !== $r['url'] ) : ?>
										<a href="<?php echo esc_url( $r['url'] ); ?>"><?php echo esc_html( $r['stat'] ); ?></a>
									<?php else : ?>
										<span><?php echo esc_html( $r['stat'] ); ?></span>
									<?php endif; ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<div class="trr-ov-col trr-ov-labs">
					<h3><?php esc_html_e( 'With Labs', 'translate-rocket' ); ?> <span class="trr-free-badge trr-free-big">FREE</span> <span class="trr-ov-tag trr-ov-free"><?php esc_html_e( 'free add-on: no paid plan, no card', 'translate-rocket' ); ?></span></h3>
					<ul>
						<?php foreach ( $plus as $r ) : ?>
							<?php $stato = $labs ? ( $r['on'] ? 'is-on' : 'is-off' ) : 'is-locked'; ?>
							<li class="<?php echo esc_attr( $stato ); ?>">
								<span class="trr-ov-dot" aria-hidden="true"><?php echo 'is-on' === $stato ? '✓' : ( 'is-locked' === $stato ? '🔒' : '○' ); ?></span>
								<span class="trr-ov-txt">
									<strong><?php echo esc_html( $r['tit'] ); ?> <?php self::info( (string) $r['info'] ); ?></strong>
									<a href="<?php echo esc_url( $labs_url ); ?>"><?php echo esc_html( $labs ? ( $r['on'] ? __( 'On', 'translate-rocket' ) : __( 'Off — turn it on in Labs', 'translate-rocket' ) ) : __( 'Comes with Labs', 'translate-rocket' ) ); ?></a>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( ! $labs ) : ?>
						<p class="trr-ov-cta">
							<a class="button button-primary" href="<?php echo esc_url( LabsPromo::site_url( 'overview' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Labs — free', 'translate-rocket' ); ?> ↗</a>
							<a href="<?php echo esc_url( $labs_url ); ?>"><?php esc_html_e( 'See everything Labs adds', 'translate-rocket' ); ?></a>
						</p>
					<?php endif; ?>
				</div>
			</div>
			<p class="trr-ov-help">
				<strong><?php esc_html_e( 'Fast support, free.', 'translate-rocket' ); ?></strong>
				<?php esc_html_e( 'Stuck, or something looks wrong? Write in the forum: the developer answers quickly, usually the same day.', 'translate-rocket' ); ?>
				<a href="https://wordpress.org/support/plugin/translate-rocket/#new-topic-0" target="_blank" rel="noopener"><?php esc_html_e( 'Ask for help', 'translate-rocket' ); ?> ↗</a>
				· <a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-diagnostics&setup=1#trr-tk-subject' ) ); ?>"><?php esc_html_e( 'Or ask us to set it up for you, free', 'translate-rocket' ); ?></a>
			</p>
		</details>
		<?php
	}

	/**
	 * The ⓘ button with more detail (same markup as Admin::info(), opened by admin-info.js).
	 * 10/10/2026, Federico: «for every feature a small info button with more details».
	 *
	 * @param string $text Detail shown on click.
	 */
	private static function info( string $text ): void {
		echo '<button type="button" class="trr-info" aria-label="' . esc_attr__( 'More info', 'translate-rocket' )
			. '" data-info="' . esc_attr( $text ) . '"><span class="dashicons dashicons-info-outline"></span></button>';
	}
}
