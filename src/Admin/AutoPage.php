<?php
/**
 * «Automatic translation»: one page to turn it on, watch it work and choose the engines.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Database;
use TranslateRocket\Settings;
use TranslateRocket\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * 9/10/2026 (Federico: «questo Labs mi porta confusione»; «dove vedo che lavora?»). Setting up a
 * customer's site took four pages and fifteen choices: AI keys and priorities on one page, the
 * keyless engines, their conditions, their order, «where it runs» and the automations on the Labs
 * page, the progress split between the two with a «0 of 4379 pages» that never moved.
 *
 * Here: one switch, one bar per language that moves while you watch (and an hourglass on the one
 * being translated), the engines as lines that are on or off, one box for the conditions of the
 * unofficial ones. Labs (0.9+) plugs in through three hooks and keeps its own settings:
 *   - filter trrocket_auto_engines: its keyless engines, as rows;
 *   - filter trrocket_auto_status:  is it on, how far the whole-site job is, the last run;
 *   - action trrocket_auto_save:    what was chosen here.
 * Without Labs the page still shows what Labs would add, locked, and what you can do without it.
 */
class AutoPage {

	const SLUG   = 'translate-rocket-auto';
	const ONLINE = 'trrocket_auto_online_check';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_post_trrocket_auto_save', array( $this, 'save' ) );
		add_action( 'wp_ajax_trrocket_auto_tick', array( $this, 'tick' ) );
		add_action( self::ONLINE, array( __CLASS__, 'check_online' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( self::ONLINE ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::ONLINE );
				}
			}
		);
	}

	/**
	 * Second item of the menu, right after Languages.
	 */
	public function menu(): void {
		add_submenu_page(
			'translate-rocket',
			__( 'Automatic translation', 'translate-rocket' ),
			__( 'Automatic translation', 'translate-rocket' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			1
		);
	}

	/**
	 * Labs' engines as rows, or null when Labs is missing or too old to say.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function labs_engines(): ?array {
		$rows = apply_filters( 'trrocket_auto_engines', null );
		return is_array( $rows ) ? $rows : null;
	}

	/**
	 * Labs' state, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function labs_status(): ?array {
		$s = apply_filters( 'trrocket_auto_status', null );
		return is_array( $s ) ? $s : null;
	}

	/**
	 * Numbers for each language: what is translated, what was translated in the last day and
	 * in the last quarter of an hour (the hourglass), and a rough time left at today's pace.
	 *
	 * @return array<string,array<string,int|bool|string>>
	 */
	public static function live(): array {
		// Counting every sentence for every language on each 30-second tick weighed on big sites
		// (and on the test bench): the numbers are kept for 20 seconds.
		$memo = get_transient( 'trrocket_auto_live' );
		if ( is_array( $memo ) ) {
			return $memo;
		}
		$out = self::live_count();
		set_transient( 'trrocket_auto_live', $out, 20 );
		return $out;
	}

	/**
	 * The counting behind live().
	 *
	 * @return array<string,array<string,int|bool|string>>
	 */
	private static function live_count(): array {
		global $wpdb;
		$s       = Settings::get();
		$targets = array_map( 'strval', (array) ( $s['target_languages'] ?? array() ) );
		$offline = array_map( 'strval', (array) ( $s['offline_languages'] ?? array() ) );
		$tab     = Database::translations_table();
		$giorno  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$quarto  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 15 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$adesso  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$recenti = array();
		$ora     = array();
		$oggi    = array();
		// On a huge site (Strings::is_huge, 20,000+ sentences) the recent-activity scan of the translations table is skipped:
		// bars and percentages stay, the hourglass and the pace estimate do not (10/10/2026).
		if ( $targets && ! Strings::is_huge() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$righe = $wpdb->get_results( $wpdb->prepare( "SELECT language, SUM( updated_at >= %s ) AS q, SUM( updated_at >= %s ) AS r, COUNT(*) AS d FROM {$tab} WHERE status > 0 AND updated_at >= %s GROUP BY language", $quarto, $adesso, $giorno ), ARRAY_A );
			foreach ( (array) $righe as $r ) {
				$recenti[ (string) $r['language'] ] = (int) $r['q'];
				$ora[ (string) $r['language'] ]     = (int) $r['r'];
				$oggi[ (string) $r['language'] ]    = (int) $r['d'];
			}
		}
		$labs = self::labs_status();
		$out  = array();
		foreach ( $targets as $code ) {
			$st    = Strings::language_stats( $code );
			$det   = (int) $st['detected'];
			$fatte = min( $det, (int) $st['translated'] );
			$manca = max( 0, $det - $fatte );
			$ritmo = (int) ( $oggi[ $code ] ?? 0 );
			$out[ $code ] = array(
				'detected'   => $det,
				'translated' => $fatte,
				'missing'    => $manca,
				'pct'        => $det > 0 ? (int) floor( 100 * $fatte / $det ) : 0,
				'today'      => $ritmo,
				'busy'       => $manca > 0 && ( ( $labs && ! empty( $labs['running'] ) ) ? (int) ( $recenti[ $code ] ?? 0 ) > 0 : (int) ( $ora[ $code ] ?? 0 ) > 0 ),
				'days'       => ( $ritmo > 0 && $manca > 0 && $labs && ! empty( $labs['on'] ) && empty( $labs['running'] ) ) ? (int) ceil( $manca / $ritmo ) : 0,
				'online'     => ! in_array( $code, $offline, true ),
			);
		}
		return $out;
	}

	/**
	 * While the whole site is being read: days left for every page, at the pace so far.
	 *
	 * @param array<string,mixed> $labs Labs status.
	 */
	public static function job_days( array $labs ): int {
		$fatte  = (int) ( $labs['done'] ?? 0 );
		$totale = (int) ( $labs['total'] ?? 0 );
		$da     = (int) ( $labs['started'] ?? 0 );
		if ( empty( $labs['running'] ) || $fatte < 3 || $da <= 0 || $totale <= $fatte ) {
			return 0;
		}
		$al_giorno = $fatte / max( 1, ( time() - $da ) ) * DAY_IN_SECONDS;
		return (int) max( 1, ceil( ( $totale - $fatte ) / max( 1, $al_giorno ) ) );
	}

	/**
	 * Every 30 seconds while a page of the plugin is open: the numbers, and a nudge to WordPress'
	 * scheduler (a cached site — LiteSpeed, WP Rocket — wakes it rarely, so the work crawled).
	 */
	public function tick(): void {
		check_ajax_referer( 'trrocket_auto_tick', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		$labs = self::labs_status();
		wp_send_json_success(
			array(
				'langs' => self::live(),
				'job'   => $labs ? array(
					'running' => ! empty( $labs['running'] ),
					'done'    => (int) ( $labs['done'] ?? 0 ),
					'total'   => (int) ( $labs['total'] ?? 0 ),
					'last'    => ! empty( $labs['last_run'] ) ? human_time_diff( (int) $labs['last_run'] ) : '',
					'days'    => self::job_days( $labs ),
				) : null,
			)
		);
	}

	/**
	 * «Put a language online by itself when it is ready»: fully translated, and — when the
	 * whole-site job of Labs is running — only once it has read every page, or a language would go
	 * online at 100% of the first hundred sentences (seen on a customer's site, 9/10/2026).
	 */
	public static function check_online(): void {
		$s = Settings::get();
		if ( empty( $s['auto_online'] ) || empty( $s['offline_languages'] ) ) {
			return;
		}
		$labs = self::labs_status();
		if ( $labs && ! empty( $labs['running'] ) ) {
			return;
		}
		$live    = self::live_count(); // fresh numbers: a language goes online only on what is true now
		$offline = array_map( 'strval', (array) $s['offline_languages'] );
		$acceso  = array();
		foreach ( $offline as $code ) {
			if ( isset( $live[ $code ] ) && $live[ $code ]['detected'] > 0 && 0 === $live[ $code ]['missing'] ) {
				$acceso[] = $code;
			}
		}
		if ( ! $acceso ) {
			return;
		}
		$s['offline_languages'] = array_values( array_diff( $offline, $acceso ) );
		Settings::update( $s );
		\TranslateRocket\Logger::info( 'auto', sprintf( /* translators: %s: language codes */ __( 'Put online by itself, fully translated: %s', 'translate-rocket' ), implode( ', ', $acceso ) ), '' );
	}

	/**
	 * Save: the plugin's own choice here, Labs' through its hook.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'trrocket_auto_save' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'translate-rocket' ) );
		}
		$s                = Settings::get();
		$s['auto_online'] = ! empty( $_POST['auto_online'] ) ? 1 : 0;
		Settings::update( $s );
		$motori = array();
		foreach ( (array) ( $_POST['engine'] ?? array() ) as $id => $v ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$motori[ sanitize_key( (string) $id ) ] = ! empty( $v );
		}
		$dove = isset( $_POST['run_where'] ) ? sanitize_key( wp_unslash( $_POST['run_where'] ) ) : '';
		/**
		 * What was chosen on «Automatic translation», for Labs to save.
		 *
		 * @param array $data on (bool), engines (id => bool), ack (bool), run_where (string).
		 */
		do_action(
			'trrocket_auto_save',
			array(
				'on'        => ! empty( $_POST['auto_on'] ),
				'engines'   => $motori,
				'ack'       => ! empty( $_POST['unofficial_ack'] ),
				'run_where' => in_array( $dove, array( 'server', 'browser', 'both' ), true ) ? $dove : '',
			)
		);
		self::check_online();
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The small script that moves the bars, shared with the Languages page.
	 */
	public static function live_script(): string {
		$cfg = wp_json_encode(
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'trrocket_auto_tick' ),
				'days'  => __( 'about %d days left at today’s pace', 'translate-rocket' ),
				'day1'  => __( 'less than a day left at today’s pace', 'translate-rocket' ),
				'pages' => __( 'Pages read: %1$d of %2$d', 'translate-rocket' ),
				'site'  => __( 'the whole site in about %d days', 'translate-rocket' ),
				'site1' => __( 'the whole site in less than a day', 'translate-rocket' ),
				'last'  => __( 'last step %s ago', 'translate-rocket' ),
				'busy'  => __( 'Being translated now', 'translate-rocket' ),
			)
		);
		return '(function(){var C=' . $cfg . ';function f(n,a,b){return String(n).replace("%1$d",a).replace("%2$d",b).replace("%d",a).replace("%s",a);}'
			. 'function paint(r){var L=r.langs||{};Object.keys(L).forEach(function(c){var d=L[c];'
			. 'document.querySelectorAll("[data-trr-live=\\""+c+"\\"]").forEach(function(row){'
			. 'var fill=row.querySelector(".trr-prog-fill");if(fill){fill.style.width=d.pct+"%";}'
			. 'var pc=row.querySelector(".trr-lang-state-pc,.trr-auto-pc");if(pc){pc.textContent=d.pct+"%";}'
			. 'var n=row.querySelector(".trr-auto-n");if(n){n.textContent=d.translated+" / "+d.detected;}'
			. 'var eta=row.querySelector(".trr-auto-eta");if(eta){eta.textContent=d.days?(d.days<=1?C.day1:f(C.days,d.days)):"";}'
			. 'row.classList.toggle("is-busy",!!d.busy);var h=row.querySelector(".trr-busy");if(h){h.hidden=!d.busy;h.title=C.busy;}'
			. '});});var j=r.job,el=document.getElementById("trr-auto-job");if(el&&j&&j.total>0){el.textContent=f(C.pages,j.done,j.total)+(j.days?" · "+(j.days<=1?C.site1:f(C.site,j.days)):"")+(j.last?" · "+f(C.last,j.last):"");}}'
			. 'function tick(){var b=new URLSearchParams();b.append("action","trrocket_auto_tick");b.append("nonce",C.nonce);'
			. 'fetch(C.ajax,{method:"POST",credentials:"same-origin",body:b}).then(function(x){return x.json();}).then(function(x){if(x&&x.success){paint(x.data);}}).catch(function(){});}'
			. 'if(document.querySelector("[data-trr-live]")){tick();setInterval(function(){if(!document.hidden){tick();}},30000);}})();';
	}

	/**
	 * The page.
	 */
	public function render(): void {
		$s       = Settings::get();
		$labs_in = LabsPromo::installed();
		$rows    = self::labs_engines();
		$stato   = self::labs_status();
		$live    = self::live();
		$acceso  = $stato && ! empty( $stato['on'] );
		$chiavi     = array();
		$incompleti = array();
		foreach ( (array) ( $s['providers'] ?? array() ) as $pid => $conf ) {
			if ( is_array( $conf ) && ! empty( $conf['api_key'] ) ) {
				$prov = \TranslateRocket\Providers\Registry::get( (string) $pid );
				if ( $prov && $prov->is_configured() ) {
					$chiavi[] = (string) $pid;
				} else {
					$incompleti[] = (string) $pid; // e.g. Cloudflare with its token but no Account ID
				}
			}
		}
		$ordine = array_values( array_unique( array_merge( array_filter( array( (string) ( $s['active_provider'] ?? '' ) ) ), (array) ( $s['provider_order'] ?? array() ), $chiavi ) ) );
		$ordine = array_values( array_intersect( $ordine, $chiavi ) );
		$defs   = Admin::provider_defs();
		?>
		<div class="wrap trrocket-wrap trr-auto">
			<?php Admin::header( self::SLUG ); ?>
			<h1 class="trr-page-title"><?php esc_html_e( 'Automatic translation', 'translate-rocket' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'translate-rocket' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="trrocket_auto_save">
				<?php wp_nonce_field( 'trrocket_auto_save' ); ?>

				<div class="trrocket-card trr-auto-main">
					<?php if ( null !== $rows ) : ?>
						<label class="trr-auto-switch">
							<input type="checkbox" name="auto_on" value="1" <?php checked( $acceso ); ?>>
							<span class="trr-auto-knob" aria-hidden="true"></span>
							<span>
								<strong><?php esc_html_e( 'Translate my site by itself', 'translate-rocket' ); ?></strong>
								<span class="description"><?php esc_html_e( 'Every page, post and product, a few at a time, on your server — and every new page as you publish it. You do not need to keep anything open; while this page is open it goes faster.', 'translate-rocket' ); ?></span>
							</span>
						</label>
						<p class="trr-auto-job" id="trr-auto-job" data-trr-live="job"><?php echo ( $stato && (int) ( $stato['total'] ?? 0 ) > 0 ) ? esc_html( sprintf( /* translators: 1: pages done, 2: pages in all */ __( 'Pages read: %1$d of %2$d', 'translate-rocket' ), (int) ( $stato['done'] ?? 0 ), (int) ( $stato['total'] ?? 0 ) ) ) : ''; ?></p>
					<?php elseif ( $labs_in ) : ?>
						<p class="trr-auto-old"><strong><?php esc_html_e( 'Update TranslateRocket Labs to 0.9 or later', 'translate-rocket' ); ?></strong> — <?php esc_html_e( 'then its engines and automatic translation are switched on here. Until then they stay on the Labs page.', 'translate-rocket' ); ?> <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Plugins', 'translate-rocket' ); ?></a></p>
					<?php else : ?>
						<div class="trr-auto-locked">
							<p><strong>🔒 <?php esc_html_e( 'Translate my site by itself', 'translate-rocket' ); ?></strong> — <?php esc_html_e( 'comes with Labs, the free add-on: the whole site in the background, new pages as you publish, engines that need no key.', 'translate-rocket' ); ?></p>
							<p><a class="button button-primary" href="<?php echo esc_url( LabsPromo::site_url( 'auto' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Labs — free', 'translate-rocket' ); ?> ↗</a>
							<?php esc_html_e( 'Without it: translate what is missing with your AI key, one language at a time, from', 'translate-rocket' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-ai' ) ); ?>"><?php esc_html_e( 'AI Translation', 'translate-rocket' ); ?></a>.</p>
						</div>
					<?php endif; ?>
					<label class="trr-auto-check">
						<input type="checkbox" name="auto_online" value="1" <?php checked( ! empty( $s['auto_online'] ) ); ?>>
						<?php esc_html_e( 'Put each language online by itself when it is fully translated', 'translate-rocket' ); ?>
						<span class="description"><?php esc_html_e( 'Until then visitors and Google do not see it. With the whole site running, it waits for every page to be read first.', 'translate-rocket' ); ?></span>
					</label>
				</div>

				<div class="trrocket-card">
					<h2><?php esc_html_e( 'Progress', 'translate-rocket' ); ?> <span class="trr-auto-livebadge"><?php esc_html_e( 'live', 'translate-rocket' ); ?></span></h2>
					<?php if ( ! $live ) : ?>
						<p><?php esc_html_e( 'Add a language first.', 'translate-rocket' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket' ) ); ?>"><?php esc_html_e( 'Languages', 'translate-rocket' ); ?></a></p>
					<?php else : ?>
						<table class="trr-auto-table">
							<tbody>
							<?php foreach ( $live as $code => $d ) : ?>
								<tr data-trr-live="<?php echo esc_attr( (string) $code ); ?>" class="<?php echo $d['busy'] ? 'is-busy' : ''; ?>">
									<th scope="row">
										<?php echo wp_kses( Admin::lang_html( (string) $code ), \TranslateRocket\Kses::html_rules() ); ?>
										<span class="trr-auto-pill <?php echo $d['online'] ? 'is-online' : 'is-offline'; ?>"><?php echo $d['online'] ? esc_html__( 'online', 'translate-rocket' ) : esc_html__( 'offline', 'translate-rocket' ); ?></span>
									</th>
									<td class="trr-auto-bar">
										<div class="trr-prog"><div class="trr-prog-bar"><div class="trr-prog-fill" style="width:<?php echo (int) $d['pct']; ?>%"></div></div></div>
										<span class="trr-busy" <?php echo $d['busy'] ? '' : 'hidden'; ?> title="<?php esc_attr_e( 'Being translated now', 'translate-rocket' ); ?>" aria-label="<?php esc_attr_e( 'Being translated now', 'translate-rocket' ); ?>">⏳</span>
										<span class="trr-auto-pc"><?php echo (int) $d['pct']; ?>%</span>
									</td>
									<td class="trr-auto-n"><?php echo (int) $d['translated'] . ' / ' . (int) $d['detected']; ?></td>
									<td class="trr-auto-eta description"><?php echo $d['days'] ? esc_html( $d['days'] <= 1 ? __( 'less than a day left at today’s pace', 'translate-rocket' ) : sprintf( /* translators: %d: days */ __( 'about %d days left at today’s pace', 'translate-rocket' ), (int) $d['days'] ) ) : ''; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description"><?php esc_html_e( 'The numbers move by themselves every 30 seconds while this page is open. ⏳ marks the languages being translated right now. The total grows as more pages are read.', 'translate-rocket' ); ?></p>
					<?php endif; ?>
				</div>

				<?php
				// 9/10/2026 (seen on a customer's site): the AI keys and the keyless engines were two lists,
				// so «1 Groq» showed first while Edge went first. One list, in the order really used.
				$trr_chain = ( $stato && null !== $rows ) ? (array) ( $stato['chain'] ?? array() ) : array();
				if ( ! $trr_chain ) {
					$trr_chain = array_merge( array( 'keys' ), null !== $rows ? array_column( $rows, 'id' ) : array() );
				}
				$trr_righe = array();
				foreach ( (array) $rows as $r ) {
					$trr_righe[ (string) $r['id'] ] = $r;
				}
				?>
				<div class="trrocket-card">
					<h2><?php esc_html_e( 'Engines', 'translate-rocket' ); ?></h2>
					<p class="description"><?php esc_html_e( 'They are tried in this order: when one runs out for the day or stops, the next one takes over.', 'translate-rocket' ); ?></p>
					<ol class="trr-auto-chain">
						<?php $trr_pos = 0; ?>
						<?php foreach ( $trr_chain as $e ) : ?>
							<?php if ( 'keys' === $e ) : ?>
								<li class="<?php echo $ordine ? 'is-on' : 'is-todo'; ?>">
									<span class="trr-ov-dot" aria-hidden="true"><?php echo (int) ++$trr_pos; ?></span>
									<span class="trr-auto-chain-txt">
										<strong><?php esc_html_e( 'Your AI keys', 'translate-rocket' ); ?></strong> <span class="description"><?php esc_html_e( 'On your server', 'translate-rocket' ); ?></span><br>
										<?php if ( ! $ordine ) : ?>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-ai' ) ); ?>"><?php esc_html_e( 'Get a free Groq key: 2 minutes, no card', 'translate-rocket' ); ?></a>
										<?php endif; ?>
										<?php foreach ( $ordine as $k => $pid ) : ?>
											<?php echo $k ? '<span aria-hidden="true"> → </span>' : ''; ?>
											<span class="trr-auto-key"><?php echo esc_html( (string) ( $defs[ $pid ]['label'] ?? ucfirst( $pid ) ) ); ?><?php if ( Admin::provider_tested( $pid ) ) : ?> <span class="trr-ai-badge trr-ai-tested">&#10003; <?php esc_html_e( 'Tested', 'translate-rocket' ); ?></span><?php endif; ?></span>
										<?php endforeach; ?>
										<?php foreach ( $incompleti as $pid ) : ?>
											<span class="trr-auto-key is-todo"><?php echo esc_html( (string) ( $defs[ $pid ]['label'] ?? ucfirst( $pid ) ) ); ?>: <a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-ai' ) ); ?>"><?php esc_html_e( 'not complete, skipped', 'translate-rocket' ); ?></a></span>
										<?php endforeach; ?>
									</span>
								</li>
							<?php elseif ( isset( $trr_righe[ (string) $e ] ) ) : ?>
								<?php $r = $trr_righe[ (string) $e ]; ?>
								<li class="<?php echo ! empty( $r['on'] ) ? 'is-on' : 'is-off'; ?>">
									<span class="trr-ov-dot" aria-hidden="true"><?php echo (int) ++$trr_pos; ?></span>
									<label class="trr-auto-chain-txt">
										<input type="checkbox" name="engine[<?php echo esc_attr( (string) $r['id'] ); ?>]" value="1" <?php checked( ! empty( $r['on'] ) ); ?>>
										<strong><?php echo esc_html( (string) $r['label'] ); ?></strong>
										<span class="description"><?php echo esc_html( (string) ( $r['note'] ?? '' ) ); ?></span>
									</label>
								</li>
							<?php endif; ?>
						<?php endforeach; ?>
						<?php if ( null === $rows ) : ?>
							<?php foreach ( array( __( 'Microsoft Edge Translator — from your server', 'translate-rocket' ), __( 'MyMemory — from your server, daily limit', 'translate-rocket' ), __( 'The translator built into Chrome and Edge — in your browser', 'translate-rocket' ) ) as $l ) : ?>
								<li class="is-locked"><span class="trr-ov-dot" aria-hidden="true">🔒</span> <span class="trr-auto-chain-txt"><?php echo esc_html( $l ); ?> <span class="trr-ov-tag trr-ov-free"><?php esc_html_e( 'Labs', 'translate-rocket' ); ?></span></span></li>
							<?php endforeach; ?>
						<?php endif; ?>
					</ol>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-ai' ) ); ?>"><?php esc_html_e( 'Add or change AI keys', 'translate-rocket' ); ?></a>
						<?php if ( null !== $rows ) : ?> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-labs' ) ); ?>"><?php esc_html_e( 'Change the order', 'translate-rocket' ); ?></a><?php endif; ?>
					</p>
					<?php if ( null !== $rows ) : ?>
						<label class="trr-auto-check">
							<input type="checkbox" name="unofficial_ack" value="1" <?php checked( (bool) array_filter( array_column( $rows, 'acked' ) ) ); ?>>
							<?php esc_html_e( 'I accept the conditions of the unofficial engines I switch on', 'translate-rocket' ); ?>
						</label>
						<details class="trr-auto-terms">
							<summary><?php esc_html_e( 'Read the conditions', 'translate-rocket' ); ?></summary>
							<?php foreach ( $rows as $r ) : ?>
								<?php if ( ! empty( $r['warning'] ) ) : ?>
									<p><strong><?php echo esc_html( (string) $r['label'] ); ?></strong></p>
									<ul><?php foreach ( (array) $r['warning'] as $w ) : ?><li><?php echo esc_html( (string) $w ); ?></li><?php endforeach; ?></ul>
								<?php endif; ?>
							<?php endforeach; ?>
						</details>
					<?php endif; ?>
				</div>

				<?php if ( null !== $rows ) : ?>
					<details class="trrocket-card trr-auto-adv">
						<summary><strong><?php esc_html_e( 'Advanced', 'translate-rocket' ); ?></strong></summary>
						<p><?php esc_html_e( 'Where it runs', 'translate-rocket' ); ?></p>
						<?php $dove = (string) ( $stato['run_where'] ?? 'server' ); ?>
						<?php foreach ( array( 'server' => __( 'On my server, by itself (recommended)', 'translate-rocket' ), 'browser' => __( 'In my browser, while wp-admin is open', 'translate-rocket' ), 'both' => __( 'Both', 'translate-rocket' ) ) as $v => $l ) : ?>
							<label class="trr-auto-radio"><input type="radio" name="run_where" value="<?php echo esc_attr( $v ); ?>" <?php checked( $dove, $v ); ?>> <?php echo esc_html( $l ); ?></label>
						<?php endforeach; ?>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-labs' ) ); ?>"><?php esc_html_e( 'Engine order, external collaborators, domains per language, second opinion, weekly report', 'translate-rocket' ); ?></a></p>
					</details>
				<?php endif; ?>

				<p class="trr-auto-save"><?php submit_button( __( 'Save', 'translate-rocket' ), 'primary', 'submit', false ); ?></p>
			</form>
			<?php // 9/10/2026 (Federico: «una richiesta per fare io la traduzione su richiesta: avremmo siti reali»). ?>
			<div class="trrocket-card trr-auto-setup">
				<h2><?php esc_html_e( 'Prefer that we do it for you?', 'translate-rocket' ); ?></h2>
				<p><?php esc_html_e( 'Free setup on request: we configure TranslateRocket on your site, start the translation and tell you when it is done. You only give us a temporary administrator account, and remove it afterwards.', 'translate-rocket' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-diagnostics&setup=1#trr-tk-subject' ) ); ?>"><?php esc_html_e( 'Ask for a free setup', 'translate-rocket' ); ?></a></p>
			</div>
		</div>
		<script><?php echo self::live_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		<?php
	}
}
