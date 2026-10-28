<?php
/**
 * «Automatic translation»: one page to turn it on, watch it work and choose the engines.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Database;
use TranslateRocket\Languages;
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
		// When each language's count last moved. Rows saved recently are not enough for the hourglass:
		// a job saving the same sentences again and again, never the last few, kept it on for ever
		// next to a number that did not move (10/10/2026, «690 / 693» in Arabic).
		$mosse  = (array) get_option( 'trrocket_auto_moved', array() );
		$prima  = $mosse;
		$adesso_ts = time();
		$finestra  = ( $labs && ! empty( $labs['running'] ) ) ? 15 * MINUTE_IN_SECONDS : 3 * MINUTE_IN_SECONDS;
		foreach ( $targets as $code ) {
			$st    = Strings::language_stats( $code );
			$det   = (int) $st['detected'];
			$fatte = min( $det, (int) $st['translated'] );
			$manca = max( 0, $det - $fatte );
			$ritmo = (int) ( $oggi[ $code ] ?? 0 );
			if ( ! isset( $mosse[ $code ] ) ) {
				// First look: trust the recent saves; if the number then stands still, the window closes on it.
				$mosse[ $code ] = array( $fatte, (int) ( $ora[ $code ] ?? 0 ) > 0 || ( (int) ( $recenti[ $code ] ?? 0 ) > 0 && $labs && ! empty( $labs['running'] ) ) ? $adesso_ts : 0 );
			} elseif ( (int) $mosse[ $code ][0] !== $fatte ) {
				$mosse[ $code ] = array( $fatte, $adesso_ts );
			}
			$si_muove = ( $adesso_ts - (int) $mosse[ $code ][1] ) <= $finestra;
			$out[ $code ] = array(
				'detected'   => $det,
				'translated' => $fatte,
				'missing'    => $manca,
				'pct'        => $det > 0 ? (int) floor( 100 * $fatte / $det ) : 0,
				'today'      => $ritmo,
				'busy'       => $manca > 0 && $si_muove && ( ( $labs && ! empty( $labs['running'] ) ) ? (int) ( $recenti[ $code ] ?? 0 ) > 0 : (int) ( $ora[ $code ] ?? 0 ) > 0 ),
				'days'       => ( $ritmo > 0 && $manca > 0 && $labs && ! empty( $labs['on'] ) && empty( $labs['running'] ) ) ? (int) ceil( $manca / $ritmo ) : 0,
				'online'     => ! in_array( $code, $offline, true ),
			);
		}
		$mosse = array_intersect_key( $mosse, array_flip( $targets ) );
		if ( $mosse !== $prima ) {
			update_option( 'trrocket_auto_moved', $mosse, false );
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
		// 26/10/2026 (globaltoolszone.com, 4.074 pages: «the whole site in about 3667 days» a minute after «Resume»): the
		// pace counts from the last start or resume (Labs 0.9.9 says when), not from the first start with the pause in it.
		$da     = (int) ( $labs['resumed'] ?? 0 ) ?: (int) ( $labs['started'] ?? 0 );
		$fatte0 = (int) ( $labs['done_at_resume'] ?? 0 );
		if ( empty( $labs['running'] ) || ! empty( $labs['paused'] ) || $fatte - $fatte0 < 3 || $da <= 0 || $totale <= $fatte ) {
			return 0;
		}
		$al_giorno = ( $fatte - $fatte0 ) / max( 1, ( time() - $da ) ) * DAY_IN_SECONDS;
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
				'nonce' => wp_create_nonce( 'trrocket_auto_tick' ), // a page left open for a day keeps updating
				'job'   => $labs ? array(
					'running' => ! empty( $labs['running'] ),
					'paused'  => ! empty( $labs['paused'] ),
					'done'    => (int) ( $labs['done'] ?? 0 ),
					'total'   => (int) ( $labs['total'] ?? 0 ),
					'last'    => ! empty( $labs['last_run'] ) ? human_time_diff( (int) $labs['last_run'] ) : '',
					'days'    => self::job_days( $labs ),
					'ended'   => ! empty( $labs['total'] ) && (int) ( $labs['done'] ?? 0 ) >= (int) $labs['total'],
				) : null,
				'now'   => self::now_lines( $labs ),
				'recent' => self::recent_lines( $labs ),
				'rest'  => self::rest_line( $labs ),
			)
		);
	}

	/**
	 * 1.8.24: the pages just finished by Labs 0.9.10+ (complete in every language), with a link to the first
	 * translated language, so the owner sees the result and not only the work.
	 *
	 * @param array<string,mixed>|null $labs Labs status.
	 * @return array<int,array<string,string>>
	 */
	public static function recent_lines( ?array $labs ): array {
		$out    = array();
		$router = \TranslateRocket\Plugin::instance()->router();
		$lingue = $router->secondary_languages();
		$lingua = (string) ( reset( $lingue ) ?: '' );
		$casa   = home_url( '/' );
		foreach ( ( is_array( $labs ) ? (array) ( $labs['recent'] ?? array() ) : array() ) as $r ) {
			$post = get_post( (int) ( $r['post'] ?? 0 ) );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$link = (string) get_permalink( $post );
			if ( '' !== $lingua && 0 === strpos( $link, $casa ) ) {
				$link = $router->home_for_language( $lingua ) . substr( $link, strlen( $casa ) );
			}
			$titolo = wp_strip_all_tags( (string) get_the_title( $post ) );
			$out[]  = array(
				'title' => '' !== $titolo ? $titolo : '#' . $post->ID,
				'url'   => esc_url_raw( $link ),
				/* translators: %s: time, e.g. «5 mins» */
				'ago'   => sprintf( __( '%s ago', 'translate-rocket' ), human_time_diff( (int) ( $r['t'] ?? time() ) ) ),
			);
		}
		return $out;
	}

	/**
	 * 1.8.24: «every engine is resting until 14:30» (Labs 0.9.10+), or nothing.
	 *
	 * @param array<string,mixed>|null $labs Labs status.
	 */
	public static function rest_line( ?array $labs ): string {
		$fino = is_array( $labs ) ? (int) ( $labs['resting'] ?? 0 ) : 0;
		if ( $fino <= time() ) {
			return '';
		}
		/* translators: %s: time of day */
		return sprintf( __( 'Every engine is resting until %s: the work goes on by itself.', 'translate-rocket' ), wp_date( get_option( 'time_format' ) ?: 'H:i', $fino ) );
	}

	/**
	 * 1.8.24 (27/10/2026, Federico: «always show the total and the pages left»): the published pages, posts and
	 * products a visitor can open — the ones Labs reads — counted without loading them.
	 */
	public static function site_pages(): int {
		$n = 0;
		foreach ( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) as $tipo ) {
			$c  = wp_count_posts( $tipo );
			$n += (int) ( $c->publish ?? 0 );
		}
		return $n;
	}

	/**
	 * 1.8.24 (26/10/2026, Federico: «which engine is it using, which page is it translating, the hourglass on the work in
	 * progress — that then goes away»): what is being translated right now, as Labs 0.9.10+ marks it (server engines
	 * with their page, Google in the browser) plus the translator built into Chrome, which saves through this plugin.
	 * Only fresh marks (90 seconds): a step cut short never leaves an hourglass behind.
	 *
	 * @param array<string,mixed>|null $labs Labs status.
	 * @return array<int,array<string,string>>
	 */
	public static function now_lines( ?array $labs ): array {
		$righe = array();
		$voci  = is_array( $labs ) && is_array( $labs['now'] ?? null ) ? $labs['now'] : array();
		$mio   = get_option( BrowserEngine::NOW );
		if ( is_array( $mio ) && time() - (int) ( $mio['t'] ?? 0 ) <= 90 ) {
			$voci[] = array( 'where' => 'browser', 'engine' => 'chrome', 'label' => __( 'The translator built into Chrome and Edge', 'translate-rocket' ), 'lang' => (string) ( $mio['lang'] ?? '' ), 'title' => '' );
		}
		foreach ( $voci as $v ) {
			if ( ! is_array( $v ) || '' === (string) ( $v['engine'] ?? '' ) ) {
				continue;
			}
			$lingua = '' !== (string) ( $v['lang'] ?? '' ) ? Languages::label( (string) $v['lang'] ) : '';
			$titolo = (string) ( $v['title'] ?? '' );
			$motore = (string) ( $v['label'] ?? $v['engine'] );
			if ( '' !== $titolo ) {
				/* translators: 1: engine name, 2: page title, 3: language name */
				$testo = sprintf( __( '%1$s is translating «%2$s» into %3$s', 'translate-rocket' ), $motore, $titolo, $lingua );
			} else {
				/* translators: 1: engine name, 2: language name */
				$testo = sprintf( __( '%1$s is translating into %2$s, in your browser', 'translate-rocket' ), $motore, $lingua );
			}
			$righe[] = array( 'engine' => sanitize_key( (string) $v['engine'] ), 'text' => $testo );
		}
		return $righe;
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
		// 1.8.25: a language just went online: the site's page caches (switcher, hreflang, the new pages) are emptied now,
		// since the background work no longer touches them while every language is offline.
		\TranslateRocket\Cache::flush();
		\TranslateRocket\Logger::info( 'auto', sprintf( /* translators: %s: language codes */ __( 'Put online by itself, fully translated: %s', 'translate-rocket' ), implode( ', ', $acceso ) ), '' );
	}

	/**
	 * Save: the plugin's own choice here, Labs' through its hook.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'trrocket_auto_save' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'translate-rocket' ) );
		}
		// 1.8.24: «Pause» / «Resume» — only that, the rest of the form is left as it is.
		$pausa = isset( $_POST['trr_pausa'] ) ? sanitize_key( wp_unslash( $_POST['trr_pausa'] ) ) : '';
		if ( in_array( $pausa, array( 'pause', 'resume' ), true ) ) {
			/**
			 * Pause (true) or resume (false) the whole-site work, for Labs.
			 *
			 * @param bool $pause True to pause.
			 */
			do_action( 'trrocket_auto_pause', 'pause' === $pausa );
			wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
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
				'pace'      => isset( $_POST['pace'] ) ? sanitize_key( wp_unslash( $_POST['pace'] ) ) : '', // 1.8.28: light | normal | fast (Labs 0.9.13)
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
				/* translators: %d: pages */
				'left'  => __( '%d still to read', 'translate-rocket' ),
				'site'  => __( 'the whole site in about %d days', 'translate-rocket' ),
				'site1' => __( 'the whole site in less than a day', 'translate-rocket' ),
				'last'  => __( 'last step %s ago', 'translate-rocket' ),
				'busy'  => __( 'Being translated now', 'translate-rocket' ),
				'paused' => __( 'Paused: %1$d of %2$d pages read.', 'translate-rocket' ),
				'resume' => __( 'Resume it in Labs', 'translate-rocket' ),
				'bPause' => __( 'Pause', 'translate-rocket' ),
				'bResume' => __( 'Resume', 'translate-rocket' ),
				'labs'  => admin_url( 'admin.php?page=translate-rocket-labs#trrlabs-auto' ),
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
			. '});});var j=r.job,el=document.getElementById("trr-auto-job");if(el&&j&&j.total>0){if(j.paused){el.textContent=f(C.paused,j.done,j.total)+" ";var a=document.createElement("a");a.href=C.labs;a.textContent=C.resume+" →";el.appendChild(a);el.classList.add("is-paused");}else{el.classList.remove("is-paused");el.textContent=f(C.pages,j.done,j.total)+(j.total>j.done?" · "+f(C.left,j.total-j.done):"")+(j.days?" · "+(j.days<=1?C.site1:f(C.site,j.days)):"")+(j.last?" · "+f(C.last,j.last):"");}}now(r.now||[]);extra(r);}'
			// One request at a time; after two failed ones in a row (logged out, server down) the hourglasses
			// go off instead of standing still, frozen from when the page was opened.
			// 1.8.24: «now» = what is being translated right now (engine, page): one line each with its hourglass, and the
			// hourglass on that engine's row. Rebuilt from scratch at every tick, so nothing can stay behind.
			. 'function now(N){var u=document.getElementById("trr-auto-now");if(u){u.textContent="";N.forEach(function(x){var li=document.createElement("li");li.textContent="⏳ "+x.text;u.appendChild(li);});u.hidden=!N.length;}'
			. 'var on={};N.forEach(function(x){on[x.engine]=1;});document.querySelectorAll("[data-trr-eng]").forEach(function(li){var h=li.querySelector(".trr-busy");if(h){h.hidden=!on[li.getAttribute("data-trr-eng")];}});}'
			// 1.8.24: «Pause»/«Resume» (only while there is work), «every engine is resting until…», «Just finished».
			. 'function extra(r){var j=r.job,b=document.getElementById("trr-auto-pausa");if(b){var lav=!!(j&&j.total>0&&!j.ended);b.hidden=!lav;b.value=j&&j.paused?"resume":"pause";b.textContent=j&&j.paused?C.bResume:C.bPause;}'
			. 'var p=document.getElementById("trr-auto-rest");if(p){p.textContent=r.rest?"⏸ "+r.rest:"";p.hidden=!r.rest;}'
			. 'var d=document.getElementById("trr-auto-recent");if(d){var u=d.querySelector("ul");u.textContent="";(r.recent||[]).forEach(function(x){var li=document.createElement("li"),a=document.createElement("a");a.href=x.url;a.target="_blank";a.rel="noopener";a.textContent=x.title;li.appendChild(a);li.appendChild(document.createTextNode(" · "+x.ago));u.appendChild(li);});d.hidden=!(r.recent&&r.recent.length);}}'
			. 'var busy=0,fails=0;function stale(){if(++fails>=2){now([]);extra({});document.querySelectorAll("[data-trr-live]").forEach(function(row){row.classList.remove("is-busy");var h=row.querySelector(".trr-busy");if(h){h.hidden=true;}});}}'
			. 'function tick(){if(busy){return;}busy=1;var b=new URLSearchParams();b.append("action","trrocket_auto_tick");b.append("nonce",C.nonce);'
			. 'fetch(C.ajax,{method:"POST",credentials:"same-origin",body:b}).then(function(x){return x.json();}).then(function(x){if(x&&x.success&&x.data){fails=0;if(x.data.nonce){C.nonce=x.data.nonce;}paint(x.data);}else{stale();}}).catch(stale).then(function(){busy=0;});}'
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
						<p class="trr-auto-job<?php echo ( $stato && ! empty( $stato['paused'] ) ) ? ' is-paused' : ''; ?>" id="trr-auto-job" data-trr-live="job"><?php
						if ( $stato && (int) ( $stato['total'] ?? 0 ) > 0 && ! empty( $stato['paused'] ) ) {
							// 26/10/2026 (globaltoolszone.com: paused for 6 days in Labs, while this page showed the switch on and
							// «Pages read: 0 of 4074», so the owner believed it was working): a pause is said here, with the way out.
							echo esc_html( sprintf( /* translators: 1: pages done, 2: pages in all */ __( 'Paused: %1$d of %2$d pages read.', 'translate-rocket' ), (int) ( $stato['done'] ?? 0 ), (int) ( $stato['total'] ?? 0 ) ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=translate-rocket-labs#trrlabs-auto' ) ) . '">' . esc_html__( 'Resume it in Labs', 'translate-rocket' ) . ' &rarr;</a>';
						} elseif ( $stato && (int) ( $stato['total'] ?? 0 ) > 0 ) {
							$fatte = (int) ( $stato['done'] ?? 0 );
							$tutte = (int) ( $stato['total'] ?? 0 );
							echo esc_html( sprintf( /* translators: 1: pages done, 2: pages in all */ __( 'Pages read: %1$d of %2$d', 'translate-rocket' ), $fatte, $tutte ) );
							if ( $tutte > $fatte ) {
								echo ' · ' . esc_html( sprintf( /* translators: %d: pages */ __( '%d still to read', 'translate-rocket' ), $tutte - $fatte ) );
							}
						} else {
							echo esc_html( sprintf( /* translators: %d: pages */ __( 'Your site has %d pages to translate.', 'translate-rocket' ), self::site_pages() ) );
						}
						?></p>
					<?php elseif ( $labs_in ) : ?>
						<p class="trr-auto-old"><strong><?php esc_html_e( 'Update TranslateRocket Labs to 0.9 or later', 'translate-rocket' ); ?></strong> — <?php esc_html_e( 'then its engines and automatic translation are switched on here. Until then they stay on the Labs page.', 'translate-rocket' ); ?> <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Plugins', 'translate-rocket' ); ?></a></p>
					<?php else : ?>
						<div class="trr-auto-locked">
							<p><strong>🔒 <?php esc_html_e( 'Translate my site by itself', 'translate-rocket' ); ?></strong> — <?php esc_html_e( 'comes with Labs, the free add-on: the whole site in the background, new pages as you publish, engines that need no key.', 'translate-rocket' ); ?></p>
							<p><a class="button button-primary" href="<?php echo esc_url( LabsPromo::site_url( 'auto' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Labs — free', 'translate-rocket' ); ?> ↗</a>
							<?php esc_html_e( 'Without it: translate what is missing with your AI key, one language at a time, from', 'translate-rocket' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-ai' ) ); ?>"><?php esc_html_e( 'AI Translation', 'translate-rocket' ); ?></a>.</p>
						</div>
					<?php endif; ?>
					<ul class="trr-auto-now" id="trr-auto-now" aria-live="polite" hidden></ul>
					<p class="trr-auto-rest" id="trr-auto-rest" hidden></p>
					<?php $trr_lav = $stato && (int) ( $stato['total'] ?? 0 ) > (int) ( $stato['done'] ?? 0 ); ?>
					<p class="trr-auto-ctl"><button type="submit" class="button" id="trr-auto-pausa" name="trr_pausa" value="<?php echo ( $stato && ! empty( $stato['paused'] ) ) ? 'resume' : 'pause'; ?>" <?php echo $trr_lav ? '' : 'hidden'; ?>><?php echo ( $stato && ! empty( $stato['paused'] ) ) ? esc_html__( 'Resume', 'translate-rocket' ) : esc_html__( 'Pause', 'translate-rocket' ); ?></button></p>
					<div class="trr-auto-recent" id="trr-auto-recent" hidden><strong><?php esc_html_e( 'Just finished', 'translate-rocket' ); ?></strong><ul></ul></div>
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
								<li class="<?php echo $ordine ? 'is-on' : 'is-todo'; ?>" data-trr-eng="keys">
									<span class="trr-ov-dot" aria-hidden="true"><?php echo (int) ++$trr_pos; ?></span>
									<span class="trr-auto-chain-txt">
										<strong><?php esc_html_e( 'Your AI keys', 'translate-rocket' ); ?></strong> <span class="trr-busy" hidden>⏳</span> <span class="description"><?php esc_html_e( 'On your server', 'translate-rocket' ); ?></span><br>
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
								<li class="<?php echo ! empty( $r['on'] ) ? 'is-on' : 'is-off'; ?>" data-trr-eng="<?php echo esc_attr( (string) $r['id'] ); ?>">
									<span class="trr-ov-dot" aria-hidden="true"><?php echo (int) ++$trr_pos; ?></span>
									<label class="trr-auto-chain-txt">
										<input type="checkbox" name="engine[<?php echo esc_attr( (string) $r['id'] ); ?>]" value="1" <?php checked( ! empty( $r['on'] ) ); ?> data-trr-ack="<?php echo ( ! empty( $r['warning'] ) && empty( $r['acked'] ) ) ? '1' : '0'; ?>">
										<strong><?php echo esc_html( (string) $r['label'] ); ?></strong> <span class="trr-busy" hidden>⏳</span>
										<span class="description"><?php echo esc_html( (string) ( $r['note'] ?? '' ) ); ?></span>
									<?php
									// 1.8.28 (28/10/2026, Federico: «some engines are grey and you cannot switch them on: say why and link
									// the two things»): an engine ticked but not working says so right here, with the one thing to do.
									// Labs 0.9.13 sends «why»; an older Labs only tells whether the conditions were accepted.
									$trr_why = (string) ( $r['why'] ?? '' );
									if ( '' === $trr_why && ! empty( $r['on'] ) && ! empty( $r['warning'] ) && empty( $r['acked'] ) ) {
										$trr_why = __( 'Not working yet: tick «I accept the conditions» below — then it starts.', 'translate-rocket' );
									}
									?>
									<?php if ( '' !== $trr_why ) : ?>
										<span class="trr-auto-why">⚠️ <?php echo esc_html( $trr_why ); ?> <a href="#trr-auto-ack" class="trr-auto-why-go"><?php esc_html_e( 'Take me there', 'translate-rocket' ); ?></a></span>
									<?php endif; ?>
									</label>
								</li>
							<?php endif; ?>
						<?php endforeach; ?>
						<?php if ( null === $rows ) : ?>
							<?php foreach ( array( __( 'Microsoft Edge Translator — from your server', 'translate-rocket' ), __( 'MyMemory — from your server, daily limit', 'translate-rocket' ), __( 'The translator built into Chrome and Edge — in your browser', 'translate-rocket' ) ) as $l ) : ?>
								<li class="is-locked"><span class="trr-ov-dot" aria-hidden="true">🔒</span> <span class="trr-auto-chain-txt"><?php echo esc_html( $l ); ?> <span class="trr-ov-tag trr-ov-free"><?php esc_html_e( 'Labs', 'translate-rocket' ); ?></span></span></li>
							<?php endforeach; ?>
							<li class="is-locked trr-auto-how"><span class="trr-ov-dot" aria-hidden="true">→</span> <span class="trr-auto-chain-txt"><?php esc_html_e( 'To switch these on: 1. get Labs (free, one minute) — 2. come back to this page — 3. tick them and accept their conditions.', 'translate-rocket' ); ?> <a href="<?php echo esc_url( LabsPromo::site_url( 'auto' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Labs — free', 'translate-rocket' ); ?> ↗</a></span></li>
						<?php endif; ?>
					</ol>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-ai' ) ); ?>"><?php esc_html_e( 'Add or change AI keys', 'translate-rocket' ); ?></a>
						<?php if ( null !== $rows ) : ?> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-labs' ) ); ?>"><?php esc_html_e( 'Change the order', 'translate-rocket' ); ?></a><?php endif; ?>
					</p>
					<?php if ( null !== $rows ) : ?>
						<label class="trr-auto-check" id="trr-auto-ack">
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
						<?php if ( isset( $stato['pace'] ) ) : // Labs 0.9.13+ (28/10/2026, a 4.000-page site: «depending on user and cpu») ?>
							<p><?php esc_html_e( 'How hard it works on your server', 'translate-rocket' ); ?></p>
							<?php $trr_pace = (string) $stato['pace']; ?>
							<?php foreach ( array( 'light' => __( 'Light — 1 page every 2 minutes (shared hosting, a busy shop)', 'translate-rocket' ), 'normal' => __( 'Normal — 3 pages a minute (recommended)', 'translate-rocket' ), 'fast' => __( 'Fast — 6 pages every 30 seconds (a server with CPU to spare)', 'translate-rocket' ) ) as $v => $l ) : ?>
								<label class="trr-auto-radio"><input type="radio" name="pace" value="<?php echo esc_attr( $v ); ?>" <?php checked( $trr_pace, $v ); ?>> <?php echo esc_html( $l ); ?></label>
							<?php endforeach; ?>
						<?php endif; ?>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=translate-rocket-labs' ) ); ?>"><?php esc_html_e( 'Engine order, external collaborators, domains per language, second opinion, weekly report', 'translate-rocket' ); ?></a></p>
					</details>
				<?php endif; ?>

				<p class="trr-auto-save"><?php submit_button( __( 'Save', 'translate-rocket' ), 'primary', 'submit', false ); ?></p>
			</form>
			<script>
			// 26/10/2026 (Federico su globaltoolszone.com: «voglio abilitare Yandex e mi dà disabilitato»): la spunta di un
			// motore restava grigia finché non si trovava «Save» in fondo alla pagina. Ora ogni scelta di questa pagina
			// (motori, interruttore, «online da sola», condizioni, dove lavorare) si salva appena la si cambia.
			( function () {
				// The page has two forms posting to admin-post.php (the plugin language at the top is the first): this one.
				var a = document.querySelector( '.trr-auto input[name="action"][value="trrocket_auto_save"]' );
				var f = a && a.form;
				if ( ! f ) { return; }
				f.addEventListener( 'change', function ( e ) {
					var n = ( e.target && e.target.name ) || '';
					if ( ! /^(engine\[|auto_on$|auto_online$|unofficial_ack$|run_where$|pace$)/.test( n ) ) { return; }
					var li = e.target.closest( 'li' );
					if ( li ) { li.className = e.target.checked ? 'is-on' : 'is-off'; }
					// 1.8.28: an unofficial engine ticked before its conditions were accepted would be saved and come back
					// «not working». Instead the page opens the conditions, lights up the box to tick, and saves when it is ticked.
					var ack = f.querySelector( 'input[name="unofficial_ack"]' );
					if ( /^engine\[/.test( n ) && e.target.checked && e.target.getAttribute( 'data-trr-ack' ) === '1' && ack && ! ack.checked ) {
						var det = f.querySelector( '.trr-auto-terms' );
						if ( det ) { det.open = true; }
						var box = document.getElementById( 'trr-auto-ack' );
						if ( box ) { box.classList.add( 'trr-auto-ack-now' ); box.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
						return;
					}
					f.style.opacity = '0.6';
					f.style.pointerEvents = 'none';
					var b = f.querySelector( '.trr-auto-save [type=submit]' );
					if ( b ) { b.value = '…'; }
					HTMLFormElement.prototype.submit.call( f );
				} );
			} )();
			</script>
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
