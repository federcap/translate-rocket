<?php
/**
 * Site search in every language.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Database;
use TranslateRocket\Plugin;
use TranslateRocket\Settings;
use TranslateRocket\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * The search of a translated page finds pages by their translated text (14/10/2026, Federico: «a powerful, smart
 * search, that tells the text apart from buttons and cookie-consent scripts»).
 *
 * WordPress searches the database, where the text is in the source language: on /it/ a visitor typing «colazione»
 * found nothing on a site written in English. Every sentence of the site is already here in every language, with
 * the pages it was seen on; but those include menus, buttons, footers, cookie banners and chat widgets, the same on
 * every page. So an index keeps only the sentences that are in the text the author wrote for that page — its title,
 * excerpt and content (Elementor's saved data and builder shortcodes' text included) — weighted by where they are.
 * A search on a translated page looks each word up in that language's translations within the index, keeps the
 * pages that have every word (even in different sentences), ranks them, and hands them to the normal WordPress
 * search: the theme's search box, Elementor's search widget and the Search block work unchanged.
 */
class Search {

	const CRON      = 'trrocket_search_index';
	const CURSOR    = 'trrocket_search_cursor';
	const BATCH     = 40;
	const W_TITLE   = 10;
	const W_EXCERPT = 4;
	const W_CONTENT = 1;

	/**
	 * Set while our own native-search query runs, so it is not rewritten again.
	 *
	 * @var bool
	 */
	private static $inner = false;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'trrocket_search';
	}

	/**
	 * On unless switched off in the settings (or by the filter).
	 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'trrocket_search_enabled', ! isset( Settings::get()['translated_search'] ) || ! empty( Settings::get()['translated_search'] ) );
	}

	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'query' ), 20 );
		add_action( 'trrocket_page_collected', array( __CLASS__, 'on_collected' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'forget' ) );
		add_action( 'trashed_post', array( __CLASS__, 'forget' ) );
		add_action( self::CRON, array( __CLASS__, 'build_batch' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}

	/* ------------------------------------------------------------------ index */

	/**
	 * The first build, in small batches in the background, until every published page is in.
	 */
	public static function schedule(): void {
		if ( 'done' === get_option( self::CURSOR ) || wp_next_scheduled( self::CRON ) ) {
			return;
		}
		wp_schedule_single_event( time() + 30, self::CRON );
	}

	/**
	 * One batch of the build: the next published pages after the cursor.
	 */
	public static function build_batch(): void {
		global $wpdb;
		$dopo = (int) get_option( self::CURSOR, 0 );
		$tipi = self::post_types();
		if ( empty( $tipi ) ) {
			update_option( self::CURSOR, 'done', false );
			return;
		}
		$segnaposti = implode( ',', array_fill( 0, count( $tipi ), '%s' ) );
		$ids        = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ({$segnaposti}) ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( $dopo ), $tipi, array( self::BATCH ) )
			)
		); // phpcs:ignore WordPress.DB
		foreach ( (array) $ids as $id ) {
			self::index_post( (int) $id );
		}
		if ( count( (array) $ids ) < self::BATCH ) {
			update_option( self::CURSOR, 'done', false );
			return;
		}
		update_option( self::CURSOR, (int) end( $ids ), false );
		wp_schedule_single_event( time() + 10, self::CRON );
	}

	/**
	 * A page was read again: its sentences may have changed.
	 *
	 * @param mixed  $secondary Target languages (unused).
	 * @param string $url       The page's path.
	 */
	public static function on_collected( $secondary, $url = '' ): void {
		unset( $secondary );
		$url = (string) $url;
		if ( '' === $url || '[' === substr( $url, 0, 1 ) ) {
			return;
		}
		$id = url_to_postid( home_url( $url ) );
		if ( 0 === $id && '/' === $url ) {
			$id = (int) get_option( 'page_on_front' );
		}
		if ( $id > 0 ) {
			self::index_post( $id );
		}
	}

	/**
	 * A page was saved: its text may have changed.
	 *
	 * @param int      $id   Post ID.
	 * @param \WP_Post $post Post.
	 */
	public static function on_save( $id, $post = null ): void {
		if ( wp_is_post_revision( (int) $id ) || wp_is_post_autosave( (int) $id ) ) {
			return;
		}
		self::index_post( (int) $id );
	}

	/**
	 * A page gone from the site leaves the index.
	 *
	 * @param int $id Post ID.
	 */
	public static function forget( $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The searchable post types (public, not excluded from search).
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		return array_values( get_post_types( array( 'public' => true, 'exclude_from_search' => false ) ) );
	}

	/**
	 * Put one page's content sentences in the index (and only those).
	 */
	public static function index_post( int $id ): int {
		global $wpdb;
		self::forget( $id );
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, self::post_types(), true ) || post_password_required( $post ) ) {
			return 0;
		}
		$link = get_permalink( $post );
		if ( ! $link ) {
			return 0;
		}
		$paths = array_unique( array_filter( array( wp_make_link_relative( $link ), (int) get_option( 'page_on_front' ) === $id ? '/' : '' ) ) );
		$occ   = Database::occurrences_table();
		$st    = Database::strings_table();
		$righe = array();
		foreach ( $paths as $path ) {
			$righe = array_merge(
				$righe,
				(array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT DISTINCT s.id, s.original FROM {$occ} o JOIN {$st} s ON s.id = o.string_id AND s.type = 'text' WHERE o.url_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						Strings::url_hash( $path )
					)
				)
			); // phpcs:ignore WordPress.DB
		}
		if ( empty( $righe ) ) {
			return 0;
		}
		$titolo  = self::norm( (string) $post->post_title );
		$estratto = self::norm( (string) $post->post_excerpt );
		$corpo   = self::norm( self::author_text( $post ) );
		$valori  = array();
		$visti   = array();
		foreach ( $righe as $r ) {
			$sid = (int) $r->id;
			if ( isset( $visti[ $sid ] ) ) {
				continue;
			}
			$visti[ $sid ] = true;
			$frase         = self::norm( (string) preg_replace( '#</?\d+/?>#', ' ', (string) $r->original ) );
			if ( mb_strlen( $frase ) < 2 ) {
				continue;
			}
			// The page's title is its heading: the whole sentence is the title (or the title holds it).
			$peso = 0;
			if ( '' !== $titolo && ( $frase === $titolo || false !== mb_strpos( $titolo, $frase ) ) ) {
				$peso = self::W_TITLE;
			} elseif ( '' !== $estratto && false !== mb_strpos( $estratto, $frase ) ) {
				$peso = self::W_EXCERPT;
			} elseif ( '' !== $corpo && false !== mb_strpos( $corpo, $frase ) ) {
				$peso = self::W_CONTENT;
			}
			if ( $peso > 0 ) {
				$valori[] = $wpdb->prepare( '(%d,%d,%d)', $id, $sid, $peso );
			}
		}
		if ( empty( $valori ) ) {
			return 0;
		}
		foreach ( array_chunk( $valori, 200 ) as $parte ) {
			$wpdb->query( 'INSERT IGNORE INTO ' . self::table() . ' (post_id, string_id, weight) VALUES ' . implode( ',', $parte ) ); // phpcs:ignore WordPress.DB
		}
		return count( $valori );
	}

	/**
	 * The text the author wrote for a page: its content, and the texts saved by page builders that keep them
	 * outside it (Elementor). Shortcode brackets go, the text inside them stays (Divi, WPBakery).
	 */
	public static function author_text( \WP_Post $post ): string {
		$testo = (string) $post->post_content;
		$dati  = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $dati ) && '' !== $dati ) {
			$albero = json_decode( $dati, true );
			if ( is_array( $albero ) ) {
				$foglie = array();
				array_walk_recursive(
					$albero,
					static function ( $v, $k ) use ( &$foglie ) {
						// Settings with words in them; not ids, colours, sizes, urls or class names.
						if ( is_string( $v ) && ! in_array( (string) $k, array( 'id', 'elType', 'widgetType', '_element_id', 'url', 'link', 'css_classes', '_css_classes' ), true ) && preg_match( '/\pL{2,}/u', $v ) && ! preg_match( '#^(https?:|/|\#[0-9a-f]{3,8}$)#i', $v ) ) {
							$foglie[] = $v;
						}
					}
				);
				$testo .= ' ' . implode( ' ', $foglie );
			}
		}
		return (string) preg_replace( '/\[\/?[a-zA-Z0-9_\-]+(?:\s[^\]]*)?\]/', ' ', $testo );
	}

	/**
	 * Text compared the way the page shows it: no tags, entities decoded, the typographic marks WordPress adds
	 * when it prints (dashes, quotes, ellipsis) made plain, one space, lower case.
	 */
	public static function norm( string $s ): string {
		$s = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = strtr(
			$s,
			array(
				"\xc2\xa0"     => ' ',
				"\xe2\x80\x93" => '-',
				"\xe2\x80\x94" => '-',
				"\xe2\x80\x98" => "'",
				"\xe2\x80\x99" => "'",
				"\xe2\x80\x9c" => '"',
				"\xe2\x80\x9d" => '"',
				"\xe2\x80\xa6" => '...',
			)
		);
		return trim( mb_strtolower( (string) preg_replace( '/\s+/u', ' ', $s ) ) );
	}

	/* ------------------------------------------------------------------ search */

	/**
	 * The search of a translated page: the pages whose content has every word, best first.
	 *
	 * @return int[] Post IDs.
	 */
	public static function find( string $terms, string $lang, int $limit = 300 ): array {
		global $wpdb;
		$parole = array();
		foreach ( preg_split( '/[\s,;.!?¿¡:"()]+/u', mb_strtolower( trim( $terms ) ) ) as $p ) {
			// Two letters at least (one is enough for Chinese and Japanese: a character is a word).
			if ( '' !== $p && ( mb_strlen( $p ) >= 2 || preg_match( '/\p{Han}|\p{Hiragana}|\p{Katakana}/u', $p ) ) ) {
				$parole[ $p ] = true;
			}
		}
		$parole = array_slice( array_keys( $parole ), 0, 6 );
		if ( empty( $parole ) ) {
			return array();
		}
		$idx = self::table();
		$tr  = Database::translations_table();
		$st  = Database::strings_table();
		// 16/10/2026 (big-site probe, 5.000 pages): one reading of the sentences for all the words and the whole phrase
		// together. A query per word read every sentence again: 2,6 s a word, 15 s for a search of three words that any
		// visitor, or a bot, can start. Same rules and the same scores as before (checked page by page):
		//   - a page is found when every word is on it, in this language's translation or in the source text;
		//   - its score is the weight of the sentences that have each word, word by word;
		//   - the words together, as typed, in one translated sentence: +20.
		$colonne_t = array();
		$colonne_s = array();
		$dove_t    = array();
		$dove_s    = array();
		$args_t    = array();
		$args_s    = array();
		foreach ( $parole as $k => $p ) {
			$like        = '%' . $wpdb->esc_like( $p ) . '%';
			$colonne_t[] = "( t.translation LIKE %s ) IS TRUE AS p{$k}";
			$colonne_s[] = "( s.original LIKE %s ) IS TRUE AS p{$k}";
			$dove_t[]    = 't.translation LIKE %s';
			$dove_s[]    = 's.original LIKE %s';
			$args_t[]    = $like;
			$args_s[]    = $like;
		}
		$frase       = count( $parole ) > 1 ? '%' . $wpdb->esc_like( implode( ' ', $parole ) ) . '%' : '';
		$colonne_t[] = '' !== $frase ? '( t.translation LIKE %s ) IS TRUE AS fr' : '0 AS fr';
		$colonne_s[] = '0 AS fr';
		$somma       = array();
		$tutte       = array();
		$per_id      = array();
		foreach ( array_keys( $parole ) as $k ) {
			$per_id[] = "MAX(p{$k}) AS p{$k}";
			$somma[]  = "m.p{$k}";
			$tutte[]  = "MAX(m.p{$k}) = 1";
		}
		$sql = "SELECT i.post_id, SUM( i.weight * ( " . implode( ' + ', $somma ) . " ) ) AS w, MAX(m.fr) AS fr
			FROM {$idx} i
			JOIN (
				SELECT id, " . implode( ', ', $per_id ) . ", MAX(fr) AS fr FROM (
					SELECT t.string_id AS id, " . implode( ', ', $colonne_t ) . "
					FROM {$tr} t WHERE t.language = %s AND t.status > 0 AND ( " . implode( ' OR ', $dove_t ) . " )
					UNION ALL
					SELECT s.id, " . implode( ', ', $colonne_s ) . "
					FROM {$st} s WHERE ( " . implode( ' OR ', $dove_s ) . " )
				) u GROUP BY id
			) m ON m.id = i.string_id
			GROUP BY i.post_id
			HAVING " . implode( ' AND ', $tutte );
		$valori = array_merge( $args_t, '' !== $frase ? array( $frase ) : array(), array( $lang ), $args_t, $args_s, $args_s );
		$righe  = $wpdb->get_results( $wpdb->prepare( $sql, $valori ) ); // phpcs:ignore WordPress.DB
		$punti  = array();
		foreach ( (array) $righe as $r ) {
			$punti[ (int) $r->post_id ] = (int) $r->w + ( (int) $r->fr ? 20 : 0 );
		}
		if ( empty( $punti ) ) {
			return array();
		}
		arsort( $punti );
		return array_slice( array_keys( $punti ), 0, $limit );
	}

	/**
	 * The main search of a translated page: our pages first, then whatever WordPress's own search finds in the
	 * source text (someone typing in the site's language), nothing else.
	 *
	 * @param \WP_Query $q The query.
	 */
	public function query( $q ): void {
		if ( self::$inner || is_admin() || ! $q instanceof \WP_Query || ! $q->is_main_query() || ! $q->is_search() || ! self::enabled() ) {
			return;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( '' === $lang || $router->is_default( $lang ) ) {
			return;
		}
		$terms = trim( (string) $q->get( 's' ) );
		if ( '' === $terms ) {
			return;
		}
		$nostri = self::find( $terms, $lang );
		// WordPress's own search, in the source text, for whoever types in the site's language.
		self::$inner = true;
		$nativi      = get_posts(
			array(
				's'                => $terms,
				'post_type'        => $q->get( 'post_type' ) ? $q->get( 'post_type' ) : 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 100,
				'suppress_filters' => false,
			)
		);
		self::$inner = false;
		$ids         = array_values( array_unique( array_merge( $nostri, array_map( 'intval', (array) $nativi ) ) ) );
		$q->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
		$q->set( 'orderby', 'post__in' );
		// The words stay in «s» (the theme shows «Results for …»), but WordPress must not search them again in the
		// source text: that would keep only the pages that have them in English.
		add_filter(
			'posts_search',
			static function ( $search, $query ) use ( $q ) {
				return $query === $q ? '' : $search;
			},
			10,
			2
		);
	}
}
