<?php
/**
 * The search box (1.8.8, 16/10/2026, Federico: «una sorta di switcher… abilitato per la casella di testo»).
 *
 * A search box that always searches in the language of the page it is on: the shortcode
 * [translaterocket_search], the block «TranslateRocket search», and — with one tick in the
 * settings — an entry in the menu the language switcher uses. Four looks: a field, a pill, a
 * magnifier that opens into a field, a magnifier that opens the search over the page.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Search box: shortcode, block, menu entry.
 */
class SearchBox {

	/**
	 * The looks, in the order the settings offer them.
	 */
	const STYLES = array( 'field', 'pill', 'icon', 'overlay' );

	/**
	 * Fake menu item ID (far from real ones, next to the switcher's).
	 */
	const MENU_ID = 990000;

	/**
	 * «Search» in each language: the box speaks the page's language from the first visit, without
	 * waiting for its sentence to be translated. Missing ones fall back to the normal translation.
	 *
	 * @var array<string,string>
	 */
	const PAROLA = array(
		'en' => 'Search', 'it' => 'Cerca', 'es' => 'Buscar', 'fr' => 'Rechercher', 'de' => 'Suchen', 'pt' => 'Pesquisar',
		'pt-br' => 'Pesquisar', 'nl' => 'Zoeken', 'ru' => 'Поиск', 'pl' => 'Szukaj', 'sv' => 'Sök', 'da' => 'Søg',
		'fi' => 'Hae', 'no' => 'Søk', 'cs' => 'Hledat', 'el' => 'Αναζήτηση', 'tr' => 'Ara', 'ar' => 'بحث', 'he' => 'חיפוש',
		'hi' => 'खोजें', 'ja' => '検索', 'ko' => '검색', 'zh' => '搜索', 'zh-tw' => '搜尋', 'uk' => 'Пошук', 'ro' => 'Caută',
		'hu' => 'Keresés', 'bg' => 'Търсене', 'hr' => 'Traži', 'sk' => 'Hľadať', 'sl' => 'Iskanje', 'th' => 'ค้นหา',
		'vi' => 'Tìm kiếm', 'id' => 'Cari', 'ms' => 'Cari', 'fa' => 'جستجو', 'bn' => 'অনুসন্ধান', 'ca' => 'Cerca',
		'sr' => 'Претрага', 'eu' => 'Bilatu', 'gl' => 'Buscar', 'lt' => 'Ieškoti', 'lv' => 'Meklēt', 'et' => 'Otsi',
		'sq' => 'Kërko', 'mk' => 'Пребарај', 'bs' => 'Pretraga', 'ka' => 'ძიება', 'hy' => 'Որոնել', 'az' => 'Axtar',
		'kk' => 'Іздеу', 'ur' => 'تلاش', 'ta' => 'தேடு', 'te' => 'వెతకండి', 'mr' => 'शोधा', 'gu' => 'શોધો', 'pa' => 'ਖੋਜੋ',
		'ne' => 'खोज्नुहोस्', 'si' => 'සොයන්න', 'sw' => 'Tafuta', 'af' => 'Soek', 'tl' => 'Maghanap', 'is' => 'Leita',
		'ga' => 'Cuardaigh', 'cy' => 'Chwilio', 'mt' => 'Fittex', 'be' => 'Пошук', 'mn' => 'Хайх', 'km' => 'ស្វែងរក',
		'my' => 'ရှာဖွေရန်', 'am' => 'ፈልግ', 'uz' => 'Qidirish', 'lb' => 'Sichen', 'eo' => 'Serĉi', 'es-mx' => 'Buscar',
		'fr-ca' => 'Rechercher', 'de-ch' => 'Suchen',
	);

	/**
	 * «Close» in each language, for the overlay's button.
	 *
	 * @var array<string,string>
	 */
	const CHIUDI = array(
		'en' => 'Close', 'it' => 'Chiudi', 'es' => 'Cerrar', 'fr' => 'Fermer', 'de' => 'Schließen', 'pt' => 'Fechar',
		'pt-br' => 'Fechar', 'nl' => 'Sluiten', 'ru' => 'Закрыть', 'pl' => 'Zamknij', 'sv' => 'Stäng', 'da' => 'Luk',
		'fi' => 'Sulje', 'no' => 'Lukk', 'cs' => 'Zavřít', 'el' => 'Κλείσιμο', 'tr' => 'Kapat', 'ar' => 'إغلاق', 'he' => 'סגירה',
		'hi' => 'बंद करें', 'ja' => '閉じる', 'ko' => '닫기', 'zh' => '关闭', 'zh-tw' => '關閉', 'uk' => 'Закрити', 'ro' => 'Închide',
		'hu' => 'Bezárás', 'bg' => 'Затвори', 'hr' => 'Zatvori', 'sk' => 'Zavrieť', 'sl' => 'Zapri', 'th' => 'ปิด',
		'vi' => 'Đóng', 'id' => 'Tutup', 'ms' => 'Tutup', 'fa' => 'بستن', 'ca' => 'Tanca', 'sr' => 'Затвори', 'es-mx' => 'Cerrar',
		'fr-ca' => 'Fermer', 'de-ch' => 'Schliessen',
	);

	/**
	 * Whether the inline script and style are already on the page.
	 *
	 * @var bool
	 */
	private static $printed = false;

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_shortcode( 'translaterocket_search', array( $this, 'render' ) );
		add_shortcode( 'TRANSLATEROCKET_SEARCH', array( $this, 'render' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_filter( 'wp_nav_menu_objects', array( $this, 'menu_item' ), 21, 2 );
		add_action( 'wp_footer', array( $this, 'footer' ), 4 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'pre_get_posts', array( $this, 'type_filter' ), 15 );
	}

	/**
	 * The type chosen by the visitor (works without JavaScript too: «trr_type» in the address).
	 *
	 * @param mixed $q Query.
	 */
	public function type_filter( $q ): void {
		if ( is_admin() || ! $q instanceof \WP_Query || ! $q->is_main_query() || ! $q->is_search() || empty( $_GET['trr_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$t = sanitize_key( wp_unslash( $_GET['trr_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( self::type_choices()[ $t ] ) ) {
			$q->set( 'post_type', $t );
		}
	}

	/**
	 * GET /wp-json/translaterocket/v1/suggest?q=…&lang=…&types=…&all=0|1&n=6 — the suggestions.
	 */
	public function routes(): void {
		register_rest_route(
			'translaterocket/v1',
			'/suggest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => '__return_true', // the same pages anyone can find with the site's search
			)
		);
	}

	/**
	 * The pages that match what is being typed: title in the page's language, address in it, kind.
	 *
	 * @param \WP_REST_Request $req Request.
	 */
	public function suggest( $req ) {
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( mb_strlen( $q ) < 3 || mb_strlen( $q ) > 80 || ! Search::enabled() ) {
			return rest_ensure_response( array() );
		}
		$router = Plugin::instance()->router();
		$lang   = sanitize_key( (string) $req->get_param( 'lang' ) );
		$public = $router->public_languages();
		if ( ! in_array( $lang, $public, true ) ) {
			$lang = (string) $router->default_language();
		}
		$n     = max( 3, min( 12, (int) $req->get_param( 'n' ) ) );
		$tipi  = self::clean_types( (string) $req->get_param( 'types' ) );
		$tutte = '1' === (string) $req->get_param( 'all' );
		// Kept 10 minutes: the same letters typed by many visitors cost one search (renewed when translations change).
		$gen    = get_option( 'trrocket_map_gen', array() );
		$chiave = 'trrocket_sug_' . md5( strtolower( $q ) . '|' . $lang . '|' . implode( ',', $tipi ) . '|' . ( $tutte ? 1 : 0 ) . '|' . $n . '|' . wp_json_encode( $gen ) . '|' . \TranslateRocket\Cache::version() );
		$tenuti = get_transient( $chiave );
		if ( is_array( $tenuti ) ) {
			return rest_ensure_response( $tenuti );
		}
		// A visitor typing fast asks often: at most 30 fresh searches a minute from one address.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$lim = 'trrocket_sug_ip_' . md5( wp_salt() . $ip );
		$fat = (int) get_transient( $lim );
		if ( $fat >= 30 ) {
			return rest_ensure_response( array() );
		}
		set_transient( $lim, $fat + 1, MINUTE_IN_SECONDS );

		$lingue = $tutte ? Search::search_languages() : ( $router->is_default( $lang ) ? array() : array( $lang ) );
		$ids    = Search::ids_for( $q, $lingue, $tipi ? $tipi : 'any', $n );
		$home   = (string) get_option( 'home' );
		$titoli = array();
		$righe  = array();
		foreach ( $ids as $id ) {
			$t = wp_strip_all_tags( html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $t ) {
				continue;
			}
			$titoli[] = $t;
			$tipo     = get_post_type_object( (string) get_post_type( $id ) );
			$righe[]  = array( 'id' => $id, 't' => $t, 'k' => $tipo ? (string) $tipo->labels->singular_name : '' );
		}
		$mappa = ( '' !== $lang && ! $router->is_default( $lang ) ) ? \TranslateRocket\Strings::translate_texts( $titoli, $lang ) : array();
		$out   = array();
		foreach ( $righe as $r ) {
			$link = (string) get_permalink( $r['id'] );
			if ( ! $router->is_default( $lang ) ) {
				$link = Engine::localize_link( $link, (string) wp_parse_url( $home, PHP_URL_HOST ), rtrim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' ), $lang, $router->secondary_languages() );
			}
			$out[] = array(
				't' => isset( $mappa[ $r['t'] ] ) && '' !== trim( (string) $mappa[ $r['t'] ] ) ? (string) $mappa[ $r['t'] ] : $r['t'],
				'u' => $link,
				'k' => $r['k'],
			);
		}
		set_transient( $chiave, $out, 10 * MINUTE_IN_SECONDS );
		return rest_ensure_response( $out );
	}

	/**
	 * How the visitor's filters are shown: not at all, as buttons always in sight, or behind a «Filters» button.
	 */
	const FILTERS = array( 'none', 'chips', 'dropdown' );

	/**
	 * The owner's choices (Settings → Search box). Shortcode and block attributes override them.
	 *
	 * @return array{menu:bool,style:string,autocomplete:bool,count:int,types:string[],all_languages:bool,filters:string,filter_types:bool,filter_languages:bool}
	 */
	public static function settings( string $id = 'default' ): array {
		$all = Settings::get();
		$s   = (array) ( $all['search_box'] ?? array() );
		if ( '' !== $id && 'default' !== $id ) {
			$extra = $all['search_boxes'] ?? array();
			$s     = ( is_array( $extra ) && isset( $extra[ $id ] ) && is_array( $extra[ $id ] ) ) ? $extra[ $id ] : $s;
		}
		$style   = (string) ( $s['style'] ?? 'icon' );
		$filters = (string) ( $s['filters'] ?? 'none' );
		$place   = (string) ( $s['place'] ?? ( ! empty( $s['menu'] ) ? 'menu' : 'none' ) );
		$vis     = (string) ( $s['visibility'] ?? 'both' );
		return array(
			'place'            => in_array( $place, self::PLACES, true ) ? $place : 'none',
			'menu'             => 'menu' === $place,
			'style'            => in_array( $style, self::STYLES, true ) ? $style : 'icon',
			'width'            => self::clean_width( (string) ( $s['width'] ?? '' ) ),
			'placeholder'      => sanitize_text_field( (string) ( $s['placeholder'] ?? '' ) ),
			'visibility'       => in_array( $vis, self::VISIBILITY, true ) ? $vis : 'both',
			'autocomplete'     => ! isset( $s['autocomplete'] ) || ! empty( $s['autocomplete'] ),
			'count'            => max( 3, min( 12, (int) ( $s['count'] ?? 6 ) ) ),
			'types'            => self::clean_types( $s['types'] ?? array() ),
			'all_languages'    => ! empty( $s['all_languages'] ),
			'filters'          => in_array( $filters, self::FILTERS, true ) ? $filters : 'none',
			'filter_types'     => ! isset( $s['filter_types'] ) || ! empty( $s['filter_types'] ),
			'filter_languages' => ! isset( $s['filter_languages'] ) || ! empty( $s['filter_languages'] ),
		);
	}

	/**
	 * Where a box can be placed by itself (1.8.8): nowhere (shortcode / block), the header menu, the phone menu, the bottom.
	 */
	const PLACES = array( 'none', 'menu', 'mobile', 'footer' );

	/**
	 * Which screens show it.
	 */
	const VISIBILITY = array( 'both', 'desktop', 'mobile' );

	/**
	 * A CSS length for the field's width («14em», «240px», «100%»), or nothing.
	 */
	public static function clean_width( string $w ): string {
		$w = trim( $w );
		return preg_match( '/^\d{1,4}(\.\d+)?(px|em|rem|%|ch|vw)$/', $w ) ? $w : '';
	}

	/**
	 * Every profile: «default» and the ones added on the Search screen.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function profiles(): array {
		$out = array( 'default' => self::settings( 'default' ) );
		foreach ( (array) ( Settings::get()['search_boxes'] ?? array() ) as $id => $p ) {
			if ( is_array( $p ) ) {
				$out[ (string) $id ] = self::settings( (string) $id );
			}
		}
		return $out;
	}
	/**
	 * The searchable content types (public, not excluded from search), as name => label.
	 *
	 * @return array<string,string>
	 */
	public static function type_choices(): array {
		$out = array();
		foreach ( Search::post_types() as $t ) {
			$o = get_post_type_object( $t );
			if ( $o && 'attachment' !== $t ) {
				$out[ $t ] = (string) $o->labels->name;
			}
		}
		return $out;
	}

	/**
	 * Only real searchable types (empty = all of them).
	 *
	 * @param mixed $types Names.
	 * @return string[]
	 */
	private static function clean_types( $types ): array {
		$types = is_string( $types ) ? explode( ',', $types ) : (array) $types;
		$ok    = did_action( 'init' ) ? array_keys( self::type_choices() ) : null;
		$out   = array();
		foreach ( $types as $t ) {
			$t = sanitize_key( (string) $t );
			if ( '' !== $t && ( null === $ok || in_array( $t, $ok, true ) ) ) {
				$out[] = $t;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Clean the settings sent by the form.
	 *
	 * @param mixed $in Posted values.
	 * @return array<string,mixed>
	 */
	public static function clean( $in ): array {
		$in      = is_array( $in ) ? $in : array();
		$style   = isset( $in['style'] ) ? sanitize_key( (string) $in['style'] ) : 'icon';
		$filters = isset( $in['filters'] ) ? sanitize_key( (string) $in['filters'] ) : 'none';
		$place   = isset( $in['place'] ) ? sanitize_key( (string) $in['place'] ) : ( ! empty( $in['menu'] ) ? 'menu' : 'none' );
		$vis     = isset( $in['visibility'] ) ? sanitize_key( (string) $in['visibility'] ) : 'both';
		return array(
			'place'            => in_array( $place, self::PLACES, true ) ? $place : 'none',
			'style'            => in_array( $style, self::STYLES, true ) ? $style : 'icon',
			'width'            => self::clean_width( isset( $in['width'] ) ? (string) $in['width'] : '' ),
			'placeholder'      => isset( $in['placeholder'] ) ? sanitize_text_field( (string) $in['placeholder'] ) : '',
			'visibility'       => in_array( $vis, self::VISIBILITY, true ) ? $vis : 'both',
			'autocomplete'     => ! empty( $in['autocomplete'] ),
			'count'            => max( 3, min( 12, (int) ( $in['count'] ?? 6 ) ) ),
			'types'            => self::clean_types( $in['types'] ?? array() ),
			'all_languages'    => ! empty( $in['all_languages'] ),
			'filters'          => in_array( $filters, self::FILTERS, true ) ? $filters : 'none',
			'filter_types'     => ! empty( $in['filter_types'] ),
			'filter_languages' => ! empty( $in['filter_languages'] ),
		);
	}
	/**
	 * The language of the page, as the box should speak it.
	 */
	private static function lang(): string {
		$router = Plugin::instance()->router();
		$lang   = (string) $router->current_language();
		return '' !== $lang ? $lang : (string) $router->default_language();
	}

	/**
	 * A word in the page's language: our own list first, then the plugin's text (which the normal
	 * translation of the page translates).
	 *
	 * @param array<string,string> $lista  PAROLA or CHIUDI.
	 * @param string               $altro  The English text.
	 * @return array{0:string,1:bool} The word, and whether it is already in the page's language.
	 */
	private static function word( array $lista, string $altro ): array {
		$lang = strtolower( self::lang() );
		if ( isset( $lista[ $lang ] ) ) {
			return array( $lista[ $lang ], true );
		}
		$corta = substr( $lang, 0, 2 );
		if ( isset( $lista[ $corta ] ) ) {
			return array( $lista[ $corta ], true );
		}
		return array( $altro, false );
	}

	/**
	 * The box. Shortcode attributes: style (field|pill|icon|overlay), placeholder.
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		$atts = is_array( $atts ) ? $atts : array();
		// «id»: the profile set up on the Search screen; its choices are the defaults of this box.
		$pid  = isset( $atts['id'] ) ? sanitize_key( (string) $atts['id'] ) : 'default';
		$s    = self::settings( '' !== $pid ? $pid : 'default' );
		$atts = shortcode_atts(
			array(
				'id'           => $pid,
				'style'        => $s['style'],
				'placeholder'  => $s['placeholder'],
				'width'        => $s['width'],
				'visibility'   => $s['visibility'],
				'preview'      => '',
				'autocomplete' => $s['autocomplete'] ? 'yes' : 'no',
				'types'        => implode( ',', $s['types'] ),
				'all'          => $s['all_languages'] ? 'yes' : 'no',
				'filters'      => $s['filters'],
			),
			$atts,
			'translaterocket_search'
		);
		$style   = in_array( (string) $atts['style'], self::STYLES, true ) ? (string) $atts['style'] : 'field';
		$larg    = self::clean_width( (string) $atts['width'] );
		$vis     = in_array( (string) $atts['visibility'], self::VISIBILITY, true ) ? (string) $atts['visibility'] : 'both';
		$filtri  = in_array( (string) $atts['filters'], self::FILTERS, true ) ? (string) $atts['filters'] : 'none';
		$tipi    = self::clean_types( (string) $atts['types'] );
		$tutte   = 'yes' === (string) $atts['all'] || '1' === (string) $atts['all'];
		$suggest = ( 'yes' === (string) $atts['autocomplete'] || '1' === (string) $atts['autocomplete'] ) && Search::enabled();
		list( $parola, $gia ) = self::word( self::PAROLA, __( 'Search', 'translate-rocket' ) );
		list( $chiudi )       = self::word( self::CHIUDI, __( 'Close', 'translate-rocket' ) );
		$segnaposto = '' !== trim( (string) $atts['placeholder'] ) ? (string) $atts['placeholder'] : $parola . '…';
		// Our own words are already in the page's language: the translation engine leaves them alone.
		$no  = $gia && '' === trim( (string) $atts['placeholder'] ) ? ' translate="no"' : '';
		$id  = 'trrocket-search-' . wp_unique_id();
		// home_url() already carries the language of the page (/it/, its own domain…): the results stay in it.
		$act = home_url( '/' );
		$val = function_exists( 'get_search_query' ) ? get_search_query() : '';
		$ico = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10 2a8 8 0 0 1 6.32 12.9l5.39 5.39-1.42 1.42-5.39-5.39A8 8 0 1 1 10 2zm0 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12z"/></svg>';

		// The visitor's filters (when the owner shows them): the content type, and «every language».
		$scelte = '';
		if ( 'none' !== $filtri ) {
			$elenco = self::type_choices();
			if ( $tipi ) {
				$elenco = array_intersect_key( $elenco, array_flip( $tipi ) );
			}
			$adesso = isset( $_GET['post_type'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['post_type'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $s['filter_types'] && count( $elenco ) > 1 ) {
				$scelte .= '<label class="trrocket-search-chip"><input type="radio" name="trr_type" value=""' . checked( empty( $adesso ), true, false ) . ' /><span>' . esc_html__( 'All', 'translate-rocket' ) . '</span></label>';
				foreach ( $elenco as $t => $nome ) {
					$scelte .= '<label class="trrocket-search-chip"><input type="radio" name="trr_type" value="' . esc_attr( $t ) . '"' . checked( in_array( $t, $adesso, true ), true, false ) . ' /><span>' . esc_html( $nome ) . '</span></label>';
				}
			}
			if ( $s['filter_languages'] && count( Search::search_languages() ) > 0 ) {
				$on      = $tutte ? empty( $_GET['trr_one'] ) : ! empty( $_GET['trr_all'] ); // phpcs:ignore WordPress.Security.NonceVerification
				$scelte .= '<label class="trrocket-search-chip trrocket-search-alllang"><input type="checkbox" class="trrocket-search-all" value="1"' . checked( $on, true, false ) . ' /><span>' . esc_html__( 'Every language', 'translate-rocket' ) . '</span></label>';
			}
		}
		$filtri_html = '';
		if ( '' !== $scelte ) {
			$filtri_html = 'dropdown' === $filtri
				? '<details class="trrocket-search-filters trrocket-search-filters--dropdown"><summary>' . esc_html__( 'Filters', 'translate-rocket' ) . '</summary><div class="trrocket-search-filterbox">' . $scelte . '</div></details>'
				: '<div class="trrocket-search-filters trrocket-search-filters--chips">' . $scelte . '</div>';
		}
		// The owner's choices, sent with the search (the type filter, when shown, replaces them).
		$nascosti = '';
		foreach ( $tipi as $t ) {
			$nascosti .= '<input type="hidden" name="post_type[]" value="' . esc_attr( $t ) . '" class="trrocket-search-owner-type" />';
		}
		$nascosti .= '<input type="hidden" name="' . ( $tutte ? 'trr_one' : 'trr_all' ) . '" value="1" class="trrocket-search-lang" disabled />';

		$dati = $suggest ? ' data-suggest="' . esc_url( rest_url( 'translaterocket/v1/suggest' ) ) . '" data-lang="' . esc_attr( self::lang() ) . '" data-count="' . (int) $s['count'] . '" data-types="' . esc_attr( implode( ',', $tipi ) ) . '" data-all="' . ( $tutte ? '1' : '0' ) . '"' : '';
		$form = '<form role="search" method="get" class="trrocket-search-form" action="' . esc_url( $act ) . '">'
			. '<div class="trrocket-search-row">'
			. '<label class="trrocket-search-label" for="' . esc_attr( $id ) . '"' . $no . '>' . esc_html( $parola ) . '</label>'
			. '<input type="search" id="' . esc_attr( $id ) . '" name="s" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $segnaposto ) . '"' . ( '' !== $larg ? ' style="width:' . esc_attr( $larg ) . '"' : '' ) . ' autocomplete="off"' . $no
			. ( $suggest ? ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '-list"' : '' ) . ' />'
			. '<button type="submit" class="trrocket-search-go" aria-label="' . esc_attr( $parola ) . '"' . $no . '>' . $ico . '</button>'
			. '</div>'
			. ( $suggest ? '<ul class="trrocket-search-suggest" id="' . esc_attr( $id ) . '-list" role="listbox" hidden></ul>' : '' )
			. $filtri_html . $nascosti
			. '</form>';

		$html = '<div class="trrocket-search trrocket-search--' . esc_attr( $style ) . ( 'both' !== $vis && '' === (string) $atts['preview'] ? ' trrocket-search--vis-' . esc_attr( $vis ) : '' ) . '" data-style="' . esc_attr( $style ) . '" data-profile="' . esc_attr( $pid ) . '"' . $dati . '>';
		if ( 'icon' === $style || 'overlay' === $style ) {
			$html .= '<button type="button" class="trrocket-search-open" aria-expanded="false" aria-label="' . esc_attr( $parola ) . '"' . $no . '>' . $ico . '</button>';
			$html .= '<div class="trrocket-search-panel" hidden>' . $form
				. ( 'overlay' === $style ? '<button type="button" class="trrocket-search-close" aria-label="' . esc_attr( $chiudi ) . '"' . $no . '>&times;</button>' : '' )
				. '</div>';
		} else {
			$html .= $form;
		}
		$html .= '</div>';
		return $html . self::assets();
	}

	/**
	 * The look and the open/close, once per page, inline: a few hundred bytes, nothing to load
	 * on pages without the box.
	 */
	private static function assets(): string {
		if ( self::$printed ) {
			return '';
		}
		self::$printed = true;
		$css = '.trrocket-search{position:relative;display:inline-flex;align-items:center;max-width:100%;font:inherit}'
			. '.trrocket-search-label{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px);white-space:nowrap}'
			. '.trrocket-search-form{display:flex;align-items:center;margin:0;max-width:100%}'
			. '.trrocket-search input[type=search]{font:inherit;color:inherit;min-width:0;width:14em;max-width:100%;margin:0;padding:.45em .7em;border:1px solid currentColor;border-color:rgba(127,127,127,.45);border-radius:4px 0 0 4px;background:#fff;color:#1d2327;box-sizing:border-box;-webkit-appearance:none;appearance:none}'
			. '.trrocket-search-go,.trrocket-search-open,.trrocket-search-close{display:inline-flex;align-items:center;justify-content:center;font:inherit;line-height:1;cursor:pointer;margin:0}'
			. '.trrocket-search-go{padding:.45em .7em;border:1px solid rgba(127,127,127,.45);border-left:0;border-radius:0 4px 4px 0;background:#f0f0f1;color:#1d2327;align-self:stretch}'
			. '.trrocket-search--pill input[type=search]{border-radius:999px 0 0 999px;padding-left:1em}'
			. '.trrocket-search--pill .trrocket-search-go{border-radius:0 999px 999px 0;padding-right:1em}'
			. '.trrocket-search-open{background:none;border:0;padding:.35em;color:inherit}'
			. '.trrocket-search--icon .trrocket-search-panel{position:absolute;top:100%;right:0;z-index:99999;margin-top:6px;background:#fff;padding:6px;border-radius:6px;box-shadow:0 6px 24px rgba(0,0,0,.18)}'
			. '[dir=rtl] .trrocket-search--icon .trrocket-search-panel{right:auto;left:0}'
			. '.trrocket-search--overlay .trrocket-search-panel{position:fixed;inset:0;z-index:999999;background:rgba(15,15,20,.88);display:flex;align-items:flex-start;justify-content:center;padding:18vh 16px 16px}'
			. '.trrocket-search--overlay .trrocket-search-panel[hidden],.trrocket-search--icon .trrocket-search-panel[hidden]{display:none}'
			. '.trrocket-search--overlay .trrocket-search-form{width:min(640px,100%)}'
			. '.trrocket-search--overlay input[type=search]{width:100%;font-size:1.25em;padding:.7em 1em}'
			. '.trrocket-search--overlay .trrocket-search-go{font-size:1.25em}'
			. '.trrocket-search-close{position:absolute;top:12px;right:16px;background:none;border:0;color:#fff;font-size:2.4em;padding:.1em .3em}'
			. '[dir=rtl] .trrocket-search-close{right:auto;left:16px}'
			. '@media (max-width:600px){.trrocket-search input[type=search]{width:100%}.trrocket-search--icon .trrocket-search-panel{position:fixed;top:auto;left:8px;right:8px;margin-top:8px}.trrocket-search--icon .trrocket-search-form{width:100%}}'
			. '.trrocket-menu-search{list-style:none;display:flex;align-items:center}'
			. '@media (max-width:782px){.trrocket-search--vis-desktop,li.trrocket-menu-search:has(.trrocket-search--vis-desktop){display:none!important}}'
			. '@media (min-width:783px){.trrocket-search--vis-mobile,li.trrocket-menu-search:has(.trrocket-search--vis-mobile){display:none!important}}'
			. '.trrocket-search-footer{padding:14px 16px;text-align:center}.trrocket-search-footer .trrocket-search{display:inline-flex}'
			// 1.8.8: the form holds a row (field + button), the suggestions and the filters
			. '.trrocket-search-form{flex-direction:column;align-items:stretch;position:relative}'
			. '.trrocket-search-row{display:flex;align-items:stretch;max-width:100%}'
			. '.trrocket-search-suggest{position:absolute;top:100%;left:0;right:0;z-index:100000;margin:4px 0 0;padding:4px 0;list-style:none;background:#fff;color:#1d2327;border:1px solid rgba(127,127,127,.3);border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.15);max-height:60vh;overflow:auto;min-width:16em}'
			. '.trrocket-search-suggest[hidden]{display:none}'
			. '.trrocket-search-suggest li{margin:0;padding:0}'
			. '.trrocket-search-suggest a{display:flex;justify-content:space-between;gap:12px;padding:.5em .8em;color:inherit;text-decoration:none;line-height:1.3}'
			. '.trrocket-search-suggest li.is-active a,.trrocket-search-suggest a:hover{background:#f0f0f1}'
			. '.trrocket-search-kind{font-size:.8em;opacity:.65;white-space:nowrap}'
			. '.trrocket-search-filters{margin-top:6px;font-size:.9em}'
			. '.trrocket-search-filters--chips,.trrocket-search-filterbox{display:flex;flex-wrap:wrap;gap:6px}'
			. '.trrocket-search-chip{display:inline-flex;align-items:center;cursor:pointer;margin:0}'
			. '.trrocket-search-chip input{position:absolute;opacity:0;width:1px;height:1px}'
			. '.trrocket-search-chip span{display:inline-block;padding:.25em .75em;border:1px solid rgba(127,127,127,.45);border-radius:999px;line-height:1.4}'
			. '.trrocket-search-chip input:checked+span{background:#1d2327;color:#fff;border-color:#1d2327}'
			. '.trrocket-search-chip input:focus-visible+span{outline:2px solid currentColor;outline-offset:2px}'
			. '.trrocket-search-filters--dropdown{position:relative}'
			. '.trrocket-search-filters--dropdown summary{cursor:pointer;display:inline-block;padding:.25em .2em}'
			. '.trrocket-search-filters--dropdown .trrocket-search-filterbox{position:absolute;z-index:100001;top:100%;left:0;background:#fff;color:#1d2327;padding:8px;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.15);min-width:14em}'
			. '[dir=rtl] .trrocket-search-filters--dropdown .trrocket-search-filterbox,[dir=rtl] .trrocket-search-suggest{left:auto;right:0}'
			. '.trrocket-search--overlay .trrocket-search-filters{color:#fff}.trrocket-search--overlay .trrocket-search-chip input:checked+span{background:#fff;color:#1d2327;border-color:#fff}';
		// The behaviour (open/close, suggestions, filters) from its file, once per page, in the footer.
		wp_enqueue_script( 'trrocket-search-box', TRROCKET_URL . 'assets/js/search-box.js', array(), Plugin::asset_ver( 'assets/js/search-box.js' ), true );
		return '<style id="trrocket-search-css">' . $css . '</style>';
	}

	/**
	 * Register the block (rendered by PHP, like the switcher's).
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'trrocket-search-block',
			TRROCKET_URL . 'assets/js/search-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			Plugin::asset_ver( 'assets/js/search-block.js' ),
			true
		);
		wp_localize_script( 'trrocket-search-block', 'TRRocketSearchProfiles', array_keys( self::profiles() ) );
		wp_set_script_translations( 'trrocket-search-block', 'translate-rocket', TRROCKET_PATH . 'languages' ); // 1.8.8: the editor's texts in the plugin's language (JSON made by genera-json-js.py)
		register_block_type(
			'translaterocket/search',
			array(
				'api_version'     => 3,
				'editor_script'   => 'trrocket-search-block',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'profile'      => array(
						'type'    => 'string',
						'default' => 'default',
					),
					'style'        => array(
						'type'    => 'string',
						'default' => '',
					),
					// '' = the choice made in the settings
					'filters'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'autocomplete' => array(
						'type'    => 'string',
						'default' => '',
					),
					'all'          => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Block render.
	 *
	 * @param mixed $attributes Block attributes.
	 */
	public function render_block( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$atts       = array( 'id' => isset( $attributes['profile'] ) ? (string) $attributes['profile'] : 'default' );
		foreach ( array( 'style', 'filters', 'autocomplete', 'all' ) as $k ) {
			if ( isset( $attributes[ $k ] ) && '' !== (string) $attributes[ $k ] ) {
				$atts[ $k ] = (string) $attributes[ $k ];
			}
		}
		return $this->render( $atts );
	}

	/**
	 * «Add the search box to the menu»: one more entry, last, in the menu the language switcher
	 * goes in (the same rule picks it), on the front end only.
	 *
	 * @param mixed $items Menu items.
	 * @param mixed $args  wp_nav_menu() arguments.
	 * @return mixed
	 */
	public function menu_item( $items, $args ) {
		if ( ! is_array( $items ) || is_admin() || Preview::hidden() || \TranslateRocket\BuilderMode::active() || ! is_object( $args ) ) {
			return $items;
		}
		// The profile that goes in the phone menu (the theme's «mobile» place), and the one in the header menu.
		$telefono = isset( $args->theme_location ) && in_array( (string) $args->theme_location, Switcher::mobile_locations(), true );
		$scelto   = '';
		foreach ( self::profiles() as $pid => $p ) {
			if ( $telefono && 'mobile' === $p['place'] ) {
				$scelto = (string) $pid;
				break;
			}
		}
		if ( '' === $scelto ) {
			if ( ! Switcher::is_wanted_menu( $args ) ) {
				return $items;
			}
			foreach ( self::profiles() as $pid => $p ) {
				if ( 'menu' === $p['place'] ) {
					$scelto = (string) $pid;
					break;
				}
			}
		}
		if ( '' === $scelto ) {
			return $items;
		}
		$this->menu_profile = $scelto;
		$order = 0;
		foreach ( $items as $it ) {
			$order = max( $order, (int) ( $it->menu_order ?? 0 ) );
		}
		$items[] = new \WP_Post(
			(object) array(
				'ID'                    => self::MENU_ID,
				'db_id'                 => self::MENU_ID,
				'post_type'             => 'nav_menu_item',
				'post_status'           => 'publish',
				'post_title'            => '',
				'post_name'             => 'trrocket-search',
				'post_parent'           => 0,
				'menu_order'            => $order + 1,
				'menu_item_parent'      => '0',
				'object_id'             => (string) self::MENU_ID,
				'object'                => 'custom',
				'type'                  => 'custom',
				'type_label'            => 'Custom Link',
				'title'                 => '',
				'url'                   => '#',
				'target'                => '',
				'attr_title'            => '',
				'description'           => '',
				'classes'               => array( 'menu-item', 'menu-item-type-custom', 'trrocket-menu-search' ),
				'xfn'                   => '',
				'current'               => false,
				'current_item_ancestor' => false,
				'current_item_parent'   => false,
				'filter'                => 'raw',
			)
		);
		add_filter( 'walker_nav_menu_start_el', array( $this, 'menu_item_html' ), 20, 2 );
		return $items;
	}

	/**
	 * The profile the menu entry draws (set while the menu is built).
	 *
	 * @var string
	 */
	private $menu_profile = 'default';

	/**
	 * «At the bottom of every page»: the profile placed there, before the theme's footer.
	 */
	public function footer(): void {
		if ( Preview::hidden() || \TranslateRocket\BuilderMode::active() ) {
			return;
		}
		foreach ( self::profiles() as $pid => $p ) {
			if ( 'footer' === $p['place'] ) {
				echo '<div class="trrocket-search-footer">' . $this->render( array( 'id' => (string) $pid ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped by render().
				return;
			}
		}
	}
	/**
	 * The menu entry's link becomes the box.
	 *
	 * @param mixed $output Item HTML.
	 * @param mixed $item   Menu item.
	 * @return mixed
	 */
	public function menu_item_html( $output, $item ) {
		if ( is_object( $item ) && (int) ( $item->ID ?? 0 ) === self::MENU_ID ) {
			return $this->render( array( 'id' => $this->menu_profile ) );
		}
		return $output;
	}
}
