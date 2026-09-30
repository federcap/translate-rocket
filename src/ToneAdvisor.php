<?php
/**
 * Tone from the kind of site: an AI reads the site and proposes the house style.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

use TranslateRocket\Providers\FreeAiProvider;
use TranslateRocket\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Federico's idea (30/8/2026): the plugin understands by itself whether the site is a hotel,
 * a law firm, a shop or a personal blog, and translates with the right register — without
 * the owner having to explain it. A free AI engine from the priority order reads the site's
 * name, tagline, home page and main pages, and proposes: the kind of site, the tone, the form
 * of address for every target language (tu/Lei, du/Sie, tú/usted…), the names never to
 * translate, and a house style ready to use. Nothing changes until the owner confirms.
 * The first time a free AI engine is ready and no house style is set, TranslateRocket prepares a
 * proposal by itself and says so on its screen.
 */
final class ToneAdvisor {

	const OPTION = 'trrocket_tone';
	const EVENT  = 'trrocket_tone_auto';
	const NONCE  = 'trrocket_tone';

	/** Longest house style TranslateRocket accepts. */
	const MAX = 1000;

	public static function boot(): void {
		add_action( self::EVENT, array( __CLASS__, 'run_auto' ) );
		add_action( 'update_option_trrocket_settings', array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_post' ) );
	}

	/**
	 * The proposal saved, if any.
	 *
	 * @return array<string,mixed>
	 */
	public static function proposal(): array {
		$p = get_option( self::OPTION );
		return is_array( $p ) ? $p : array();
	}

	/**
	 * The first free AI engine of the priority order that is ready, or null.
	 */
	public static function engine(): ?FreeAiProvider {
		// The fallback chain's order first, then any free AI provider that is set up.
		foreach ( array_merge( Registry::ordered(), Registry::all() ) as $p ) {
			if ( $p instanceof FreeAiProvider && $p->is_configured() ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * First free AI engine ready, no house style yet, never proposed or dismissed: prepare one by itself.
	 */
	public static function maybe_schedule(): void {
		if ( '' !== trim( (string) ( \TranslateRocket\Settings::get()['ai_guidance'] ?? '' ) ) ) {
			return;
		}
		if ( ! empty( self::proposal() ) || wp_next_scheduled( self::EVENT ) || null === self::engine() ) {
			return;
		}
		wp_schedule_single_event( time() + 20, self::EVENT );
	}

	public static function run_auto(): void {
		if ( empty( self::proposal() ) ) {
			self::analyze( true );
		}
	}

	/**
	 * Plain text of a post, short.
	 */
	private static function text_of( \WP_Post $p, int $max ): string {
		$t = wp_strip_all_tags( strip_shortcodes( (string) $p->post_content ) );
		$t = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return mb_substr( $t, 0, $max );
	}

	/**
	 * What the AI reads: name, tagline, home page, the main pages, what kind of content there is.
	 *
	 * @return array{text:string,haystack:string}
	 */
	public static function collect(): array {
		// $sito holds only what is written on the site: the names the AI proposes are checked
		// against it (not against our own framing lines such as «online shop (WooCommerce)»).
		$righe   = array();
		$sito    = array( get_bloginfo( 'name' ), get_bloginfo( 'description' ) );
		$righe[] = 'Site name: ' . get_bloginfo( 'name' );
		$righe[] = 'Tagline: ' . get_bloginfo( 'description' );
		$st      = \TranslateRocket\Settings::get();
		$righe[] = 'Written in: ' . (string) ( $st['source_language'] ?? '' );
		if ( class_exists( 'WooCommerce' ) ) {
			$righe[] = 'It is an online shop (WooCommerce), products: ' . (int) ( wp_count_posts( 'product' )->publish ?? 0 );
		}
		$righe[] = 'Blog posts: ' . (int) ( wp_count_posts( 'post' )->publish ?? 0 ) . ', pages: ' . (int) ( wp_count_posts( 'page' )->publish ?? 0 );
		$home = (int) get_option( 'page_on_front' );
		if ( $home > 0 && get_post( $home ) ) {
			$righe[] = "\nHome page:\n" . self::text_of( get_post( $home ), 1800 );
			$sito[]  = get_the_title( $home ) . ' ' . self::text_of( get_post( $home ), 1800 );
		}
		$pagine = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 12, 'orderby' => 'menu_order title', 'order' => 'ASC', 'exclude' => $home > 0 ? array( $home ) : array() ) );
		if ( $pagine ) {
			$titoli  = implode( ' · ', array_map( static function ( $p ) { return get_the_title( $p ); }, $pagine ) );
			$righe[] = "\nOther pages: " . $titoli;
			$sito[]  = $titoli;
			foreach ( array_slice( $pagine, 0, 3 ) as $p ) {
				$righe[] = "\n" . get_the_title( $p ) . ":\n" . self::text_of( $p, 500 );
				$sito[]  = self::text_of( $p, 500 );
			}
		}
		$post = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 5 ) );
		if ( $post ) {
			$titoli  = implode( ' · ', array_map( static function ( $p ) { return get_the_title( $p ); }, $post ) );
			$righe[] = "\nRecent posts: " . $titoli;
			$sito[]  = $titoli;
		}
		$testo = mb_substr( implode( "\n", $righe ), 0, 6000 );
		return array( 'text' => $testo, 'haystack' => mb_strtolower( implode( "\n", $sito ) ) );
	}

	/**
	 * The target languages, as «Italian (it)».
	 *
	 * @return array<string,string> code => English name
	 */
	private static function targets(): array {
		$all = \TranslateRocket\Languages::all();
		$out = array();
		foreach ( (array) ( \TranslateRocket\Settings::get()['target_languages'] ?? array() ) as $c ) {
			$c         = strtolower( (string) $c );
			$out[ $c ] = isset( $all[ $c ][1] ) ? (string) $all[ $c ][1] : $c;
		}
		return $out;
	}

	/**
	 * Read the site and save a proposal. Null on success, else what went wrong.
	 */
	public static function analyze( bool $auto = false ): ?string {
		$p = self::engine();
		if ( null === $p ) {
			return __( 'Set up a free AI provider first (Cloudflare Workers AI, Groq or OpenRouter) on this page.', 'translate-rocket' );
		}
		$sito    = self::collect();
		$lingue  = self::targets();
		$elenco  = array();
		foreach ( $lingue as $c => $n ) {
			$elenco[] = $n . ' (' . $c . ')';
		}
		// What the owner reads (kind of site, audience, tone) comes in the language of their wp-admin;
		// the house style itself stays in English, the language the models follow best.
		$tutte  = \TranslateRocket\Languages::all();
		$loc    = strtolower( substr( (string) ( is_user_logged_in() ? get_user_locale() : get_locale() ), 0, 2 ) );
		$admin  = isset( $tutte[ $loc ][1] ) ? (string) $tutte[ $loc ][1] : 'English';
		$system = 'You help a website owner get good translations. Read the description of a website and answer with ONLY a JSON object, no comments, no markdown fences, with these keys: '
			. '"site_type": what kind of site it is, in 2-6 words written in ' . $admin . ' (for example "boutique hotel", "law firm", "online shop for ceramics", "personal travel blog"); '
			. '"audience": who the visitors are, in a few words written in ' . $admin . '; '
			. '"tone": the tone the translations should have, in one short sentence written in ' . $admin . '; '
			. '"address": an object with one key per target language code, each value "informal" or "formal" (the form of address to use with visitors in that language, as a site of this kind would normally do in that country); '
			. '"keep": an array of at most 12 names that must never be translated (the brand, the site name, product or place names), copied exactly as they appear in the text; '
			. '"guidance": a house style for the translator, in English, at most 700 characters: the tone, the form of address for each target language with the exact pronoun (for example "Italian: informal, use tu", "German: formal, use Sie"), and anything else a translator of this site must know. '
			. 'Base everything only on the text you are given; do not invent names.';
		$user = 'Target languages: ' . ( $elenco ? implode( ', ', $elenco ) : 'none yet' ) . "\n\nThe website:\n" . $sito['text'];
		$r    = $p->complete( $system, $user, 900 );
		if ( isset( $r['error'] ) ) {
			return (string) $r['error'];
		}
		$o = self::parse_object( $r['text'] ?? '' );
		if ( null === $o || empty( $o['guidance'] ) ) {
			return __( 'The answer could not be read.', 'translate-rocket' );
		}
		// Names the AI says to keep: only the ones really written on the site (no invented brands).
		$keep = array();
		foreach ( (array) ( $o['keep'] ?? array() ) as $k ) {
			$k = trim( wp_strip_all_tags( (string) $k ) );
			if ( '' !== $k && mb_strlen( $k ) <= 60 && false !== mb_strpos( $sito['haystack'], mb_strtolower( $k ) ) && ! in_array( $k, $keep, true ) ) {
				$keep[] = $k;
			}
		}
		$address = array();
		foreach ( (array) ( $o['address'] ?? array() ) as $c => $v ) {
			$c = strtolower( (string) $c );
			if ( isset( $lingue[ $c ] ) ) {
				$address[ $c ] = 'formal' === strtolower( (string) $v ) ? 'formal' : 'informal';
			}
		}
		update_option(
			self::OPTION,
			array(
				'when'      => time(),
				'engine'    => $p->label(),
				'auto'      => $auto ? 1 : 0,
				'site_type' => mb_substr( trim( wp_strip_all_tags( (string) ( $o['site_type'] ?? '' ) ) ), 0, 80 ),
				'audience'  => mb_substr( trim( wp_strip_all_tags( (string) ( $o['audience'] ?? '' ) ) ), 0, 120 ),
				'tone'      => mb_substr( trim( wp_strip_all_tags( (string) ( $o['tone'] ?? '' ) ) ), 0, 240 ),
				'address'   => $address,
				'keep'      => array_slice( $keep, 0, 12 ),
				'guidance'  => mb_substr( trim( sanitize_textarea_field( (string) $o['guidance'] ) ), 0, self::MAX ),
				'state'     => 'new',
			),
			false
		);
		return null;
	}

	/**
	 * «Use this style»: the house style and the chosen names go into TranslateRocket.
	 *
	 * @param string   $guidance The text, as the owner left it.
	 * @param string[] $keep     Names ticked.
	 */
	public static function apply( string $guidance, array $keep ): void {
		$s                = \TranslateRocket\Settings::get();
		$s['ai_guidance'] = mb_substr( trim( sanitize_textarea_field( $guidance ) ), 0, self::MAX );
		$lista            = $s['exclude_strings'] ?? array();
		$lista            = is_array( $lista ) ? $lista : preg_split( '/\r\n|\r|\n/', (string) $lista );
		$lista            = array_values( array_filter( array_map( 'trim', (array) $lista ), 'strlen' ) );
		foreach ( $keep as $k ) {
			$k = trim( sanitize_text_field( (string) $k ) );
			if ( '' !== $k && ! in_array( $k, $lista, true ) ) {
				$lista[] = $k;
			}
		}
		$s['exclude_strings'] = $lista;
		\TranslateRocket\Settings::update( $s );
		$p          = self::proposal();
		$p['state'] = 'applied';
		update_option( self::OPTION, $p, false );
	}

	public static function dismiss(): void {
		$p          = self::proposal();
		$p['state'] = 'dismissed';
		update_option( self::OPTION, $p, false );
	}

	/**
	 * The tone form (its own nonce), then back to AI Translation.
	 */
	public static function maybe_post(): void {
		if ( empty( $_POST['trrocket_tone'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::NONCE, 'trrocket_tone_nonce' );
		$azione = sanitize_key( wp_unslash( $_POST['trrocket_tone'] ) );
		$args   = array( 'page' => 'translate-rocket-ai' );
		if ( 'analyze' === $azione ) {
			$err = self::analyze( false );
			$args = array_merge( $args, null === $err ? array( 'tone' => 'ready' ) : array( 'tone' => 'error', 'tone_msg' => rawurlencode( mb_substr( $err, 0, 300 ) ) ) );
		} elseif ( 'apply' === $azione ) {
			$keep = isset( $_POST['tone_keep'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['tone_keep'] ) ) : array();
			self::apply( isset( $_POST['tone_guidance'] ) ? (string) wp_unslash( $_POST['tone_guidance'] ) : '', $keep ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in apply().
			$args['tone'] = 'applied';
		} elseif ( 'dismiss' === $azione ) {
			self::dismiss();
			$args['tone'] = 'dismissed';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#trrocket-tone' );
		exit;
	}

	/**
	 * A JSON object out of an answer (decoded already, fenced, or with words around it).
	 *
	 * @param mixed $content The answer.
	 * @return array<string,mixed>|null
	 */
	public static function parse_object( $content ): ?array {
		if ( is_array( $content ) ) {
			return $content;
		}
		$c     = (string) $content;
		$first = strpos( $c, '{' );
		$last  = strrpos( $c, '}' );
		if ( false === $first || false === $last || $last < $first ) {
			return null;
		}
		$o = json_decode( substr( $c, $first, $last - $first + 1 ), true );
		return is_array( $o ) ? $o : null;
	}

	/**
	 * The box on AI Translation, under the house style (its own form).
	 */
	public static function render( bool $valid ): void {
		$p     = self::proposal();
		$ora   = trim( (string) ( \TranslateRocket\Settings::get()['ai_guidance'] ?? '' ) );
		$nomi  = \TranslateRocket\Languages::all();
		echo '<form method="post" action="">' . wp_nonce_field( self::NONCE, 'trrocket_tone_nonce', true, false ) . '<h2 id="trrocket-tone">' . esc_html__( 'Translation style from the kind of site', 'translate-rocket' ) . '</h2>';
		echo '<div style="max-width:820px;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #8b5cf6">';
		echo '<p style="margin:0 0 10px">' . esc_html__( 'A free AI provider set up on this page reads your site — name, tagline, home page, main pages — and understands what kind of site it is: a hotel, a law firm, a shop, a blog. Then it proposes how to translate it: the tone, whether to address visitors formally or informally in each language, and the names never to translate. Nothing changes until you press «Use this style».', 'translate-rocket' ) . '</p>';
		if ( '' !== $ora ) {
			echo '<p class="description" style="margin:0 0 10px"><strong>' . esc_html__( 'Your house style now:', 'translate-rocket' ) . '</strong> ' . esc_html( mb_strimwidth( $ora, 0, 220, '…' ) ) . '</p>';
		}
		$fresca = ! empty( $p ) && 'new' === ( $p['state'] ?? '' );
		if ( $fresca ) {
			echo '<div style="margin:0 0 12px;padding:12px 14px;background:#f6f7ff;border:1px solid #dcdcf7;border-radius:8px">';
			if ( ! empty( $p['auto'] ) ) {
				echo '<p style="margin:0 0 8px;font-weight:600">✨ ' . esc_html__( 'TranslateRocket read your site by itself and prepared this proposal.', 'translate-rocket' ) . '</p>';
			}
			echo '<table class="widefat" style="border:0;background:transparent"><tbody>';
			echo '<tr><th style="width:170px;padding:4px 8px 4px 0">' . esc_html__( 'Kind of site', 'translate-rocket' ) . '</th><td style="padding:4px 0"><strong>' . esc_html( (string) $p['site_type'] ) . '</strong>' . ( '' !== (string) $p['audience'] ? ' · ' . esc_html( (string) $p['audience'] ) : '' ) . '</td></tr>';
			echo '<tr><th style="padding:4px 8px 4px 0">' . esc_html__( 'Tone', 'translate-rocket' ) . '</th><td style="padding:4px 0">' . esc_html( (string) $p['tone'] ) . '</td></tr>';
			if ( ! empty( $p['address'] ) ) {
				$pezzi = array();
				foreach ( (array) $p['address'] as $c => $v ) {
					$pezzi[] = esc_html( (string) ( $nomi[ $c ][0] ?? $c ) ) . ': ' . ( 'formal' === $v ? esc_html__( 'formal', 'translate-rocket' ) : esc_html__( 'informal', 'translate-rocket' ) );
				}
				echo '<tr><th style="padding:4px 8px 4px 0">' . esc_html__( 'Addressing visitors', 'translate-rocket' ) . '</th><td style="padding:4px 0">' . implode( ' · ', $pezzi ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pieces escaped above.
			}
			echo '</tbody></table>';
			echo '<p style="margin:10px 0 4px"><label for="trrocket-tone-guidance" style="font-weight:600">' . esc_html__( 'Proposed house style (you can edit it before using it):', 'translate-rocket' ) . '</label></p>';
			echo '<textarea id="trrocket-tone-guidance" name="tone_guidance" rows="5" class="large-text" maxlength="' . (int) self::MAX . '" ' . disabled( ! $valid, true, false ) . '>' . esc_textarea( (string) $p['guidance'] ) . '</textarea>';
			if ( ! empty( $p['keep'] ) ) {
				echo '<p style="margin:10px 0 4px;font-weight:600">' . esc_html__( 'Never translate these names (they are added to Exclusions):', 'translate-rocket' ) . '</p><p style="margin:0">';
				foreach ( (array) $p['keep'] as $k ) {
					echo '<label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="tone_keep[]" value="' . esc_attr( (string) $k ) . '" checked> ' . esc_html( (string) $k ) . '</label>';
				}
				echo '</p>';
			}
			echo '<p style="margin:12px 0 0"><button type="submit" name="trrocket_tone" value="apply" class="button button-primary" ' . disabled( ! $valid, true, false ) . '>' . esc_html__( 'Use this style', 'translate-rocket' ) . '</button> ';
			echo '<button type="submit" name="trrocket_tone" value="dismiss" class="button" ' . disabled( ! $valid, true, false ) . '>' . esc_html__( 'Discard', 'translate-rocket' ) . '</button></p>';
			/* translators: 1: engine name, 2: date */
			echo '<p class="description" style="margin:8px 0 0">' . esc_html( sprintf( __( 'Proposed by %1$s, %2$s. Translations already done stay as they are; new ones follow the style.', 'translate-rocket' ), (string) $p['engine'], wp_date( 'j M H:i', (int) $p['when'] ) ) ) . '</p>';
			echo '</div>';
		} elseif ( ! empty( $p ) && 'applied' === ( $p['state'] ?? '' ) ) {
			echo '<p style="margin:0 0 10px;color:#00a32a;font-weight:600">✓ ' . esc_html( sprintf( /* translators: %s: kind of site */ __( 'In use: the style for a %s.', 'translate-rocket' ), (string) $p['site_type'] ) ) . '</p>';
		}
		$motore = self::engine();
		echo '<p style="margin:0"><button type="submit" name="trrocket_tone" value="analyze" class="button" ' . disabled( ! $valid || null === $motore, true, false ) . '>' . esc_html( $fresca || ! empty( $p ) ? __( 'Read my site again', 'translate-rocket' ) : __( 'Read my site and propose a style', 'translate-rocket' ) ) . '</button>';
		if ( $valid && null === $motore ) {
			echo ' <span class="description">' . esc_html__( 'Set up a free AI provider first (Cloudflare Workers AI, Groq or OpenRouter) on this page.', 'translate-rocket' ) . '</span>';
		}
		echo '</p></div></form>';
	}
}
