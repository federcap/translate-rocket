<?php
/**
 * Translated URL bases: /it/prodotto/borsa/ instead of /it/product/borsa/ (1.8.37).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * 9/11/2026 (Federico: «Translated URL bases non lo facciamo?» — the last SEO point where WPML, TranslatePress Pro and
 * Polylang Pro were ahead). The fixed first word of an archive or post-type address — /category/, /tag/, /product/,
 * /product-category/, /portfolio/ — is translated in each language: /de/kategorie/reisen/, /it/prodotto/borsa/.
 *
 * How it works:
 * - the bases are read from WordPress' own permalink structures (one fixed word at the start, nothing else);
 * - the word for each language comes from what the owner typed, else from a built-in list for the common bases
 *   (category, tag, product, product category, product tag) in 33 languages, else from the translation of the base's
 *   name already saved by TranslateRocket (custom post types: «Portfolio», «Event»…). No engine is called for it;
 * - links, hreflang, the switcher and the sitemap use the translated word; an incoming translated word is put back
 *   to the original before WordPress reads the address, so every rule of the site keeps working;
 * - the address with the original word, and with a word used before, answers with a 301 to the translated one;
 * - a base whose word is also the address of a page (WooCommerce shops with /shop/ as product base) is left alone.
 *
 * Off by default, like the translated page addresses (search engines already know the plain ones).
 */
class UrlBases {

	const OPTION = 'trrocket_url_bases'; // array( 'sig' => string, 'at' => int, 'map' => [lang][orig] => slug, 'old' => [lang][slug] => orig )

	/** Built-in words: base key => language => slug. Keys: category, tag, product, product-category, product-tag. */
	const WORDS = array(
		'it' => array( 'categoria', 'tag', 'prodotto', 'categoria-prodotto', 'tag-prodotto' ),
		'de' => array( 'kategorie', 'schlagwort', 'produkt', 'produkt-kategorie', 'produkt-schlagwort' ),
		'fr' => array( 'categorie', 'etiquette', 'produit', 'categorie-produit', 'etiquette-produit' ),
		'es' => array( 'categoria', 'etiqueta', 'producto', 'categoria-producto', 'etiqueta-producto' ),
		'pt' => array( 'categoria', 'etiqueta', 'produto', 'categoria-produto', 'etiqueta-produto' ),
		'nl' => array( 'categorie', 'tag', 'product', 'productcategorie', 'producttag' ),
		'pl' => array( 'kategoria', 'tag', 'produkt', 'kategoria-produktu', 'tag-produktu' ),
		'cs' => array( 'kategorie', 'stitek', 'produkt', 'kategorie-produktu', 'stitek-produktu' ),
		'sk' => array( 'kategoria', 'stitok', 'produkt', 'kategoria-produktu', 'stitok-produktu' ),
		'sv' => array( 'kategori', 'etikett', 'produkt', 'produktkategori', 'produktetikett' ),
		'da' => array( 'kategori', 'tag', 'produkt', 'produktkategori', 'produkt-tag' ),
		'nb' => array( 'kategori', 'stikkord', 'produkt', 'produktkategori', 'produktstikkord' ),
		'fi' => array( 'kategoria', 'avainsana', 'tuote', 'tuotekategoria', 'tuoteavainsana' ),
		'ro' => array( 'categorie', 'eticheta', 'produs', 'categorie-produs', 'eticheta-produs' ),
		'hu' => array( 'kategoria', 'cimke', 'termek', 'termekkategoria', 'termekcimke' ),
		'tr' => array( 'kategori', 'etiket', 'urun', 'urun-kategorisi', 'urun-etiketi' ),
		'hr' => array( 'kategorija', 'oznaka', 'proizvod', 'kategorija-proizvoda', 'oznaka-proizvoda' ),
		'sl' => array( 'kategorija', 'oznaka', 'izdelek', 'kategorija-izdelka', 'oznaka-izdelka' ),
		'sr' => array( 'kategorija', 'oznaka', 'proizvod', 'kategorija-proizvoda', 'oznaka-proizvoda' ),
		'bs' => array( 'kategorija', 'oznaka', 'proizvod', 'kategorija-proizvoda', 'oznaka-proizvoda' ),
		'ca' => array( 'categoria', 'etiqueta', 'producte', 'categoria-producte', 'etiqueta-producte' ),
		'lt' => array( 'kategorija', 'zyma', 'produktas', 'produkto-kategorija', 'produkto-zyma' ),
		'lv' => array( 'kategorija', 'birka', 'produkts', 'produkta-kategorija', 'produkta-birka' ),
		'et' => array( 'kategooria', 'silt', 'toode', 'tootekategooria', 'tootesilt' ),
		'ru' => array( 'категория', 'метка', 'товар', 'категория-товара', 'метка-товара' ),
		'uk' => array( 'категорія', 'мітка', 'товар', 'категорія-товару', 'мітка-товару' ),
		'bg' => array( 'категория', 'етикет', 'продукт', 'категория-продукт', 'етикет-продукт' ),
		'el' => array( 'κατηγορία', 'ετικέτα', 'προϊόν', 'κατηγορία-προϊόντος', 'ετικέτα-προϊόντος' ),
		'ja' => array( 'カテゴリー', 'タグ', '商品', '商品カテゴリー', '商品タグ' ),
		'zh' => array( '分类', '标签', '产品', '产品分类', '产品标签' ),
		'ko' => array( '카테고리', '태그', '상품', '상품-카테고리', '상품-태그' ),
		'ar' => array( 'تصنيف', 'وسم', 'منتج', 'تصنيف-المنتج', 'وسم-المنتج' ),
		'he' => array( 'קטגוריה', 'תגית', 'מוצר', 'קטגוריית-מוצר', 'תגית-מוצר' ),
	);

	/** Which built-in word goes with which permalink structure. */
	const KEYS = array(
		'category'    => 0,
		'post_tag'    => 1,
		'product'     => 2,
		'product_cat' => 3,
		'product_tag' => 4,
	);

	/** @var array|null This request's copy of the option. */
	private static $data = null;

	public static function enabled(): bool {
		return ! empty( Settings::get()['translate_url_bases'] );
	}

	public function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'refresh' ), 20 );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_changed' ), 30 );
	}

	/** Settings saved: the words are worked out again on the next admin page. */
	public static function settings_changed(): void {
		$d = self::data();
		$d['sig'] = '';
		update_option( self::OPTION, $d, true );
		self::$data = $d;
	}

	private static function data(): array {
		if ( null === self::$data ) {
			$d          = get_option( self::OPTION, array() );
			self::$data = is_array( $d ) ? $d : array();
		}
		return self::$data;
	}

	/**
	 * The translated words in use: language => original base => translated slug. Empty when the setting is off.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function map(): array {
		if ( ! self::enabled() ) {
			return array();
		}
		$d = self::data();
		return isset( $d['map'] ) && is_array( $d['map'] ) ? $d['map'] : array();
	}

	/**
	 * The bases of this site: original word => array( 'label' => name, 'key' => permastruct name ).
	 * Only a structure that starts with exactly one fixed word: /product/%product%, /category/%category%.
	 *
	 * @return array<string,array{label:string,key:string}>
	 */
	public static function bases(): array {
		global $wp_rewrite;
		$out = array();
		if ( ! ( $wp_rewrite instanceof \WP_Rewrite ) || ! $wp_rewrite->using_permalinks() ) {
			return $out;
		}
		foreach ( (array) $wp_rewrite->extra_permastructs as $name => $ps ) {
			$struct = is_array( $ps ) ? (string) ( $ps['struct'] ?? '' ) : (string) $ps;
			if ( ! preg_match( '#^/?([^/%]+)/%[^/]+%/?$#', $struct, $m ) ) {
				continue;
			}
			$label = '';
			$tx    = taxonomy_exists( (string) $name ) ? get_taxonomy( (string) $name ) : null;
			$pt    = ! $tx && post_type_exists( (string) $name ) ? get_post_type_object( (string) $name ) : null;
			if ( $tx && ( ! $tx->public || ! $tx->publicly_queryable ) ) {
				continue;
			}
			if ( $pt && ( ! $pt->public || ! $pt->publicly_queryable ) ) {
				continue;
			}
			if ( ! $tx && ! $pt ) {
				continue; // post formats, feeds and the like
			}
			if ( 'post_format' === $name ) {
				continue;
			}
			$label = $tx ? (string) ( $tx->labels->singular_name ?? $tx->label ) : (string) ( $pt->labels->singular_name ?? $pt->label );
			$base  = strtolower( (string) $m[1] );
			if ( isset( $out[ $base ] ) ) {
				continue; // two structures on the same word: leave it as it is
			}
			// The same word is a page's address (WooCommerce product base «shop» + the Shop page): left alone.
			if ( get_page_by_path( $base ) ) {
				continue;
			}
			$out[ $base ] = array(
				'label' => html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES, 'UTF-8' ),
				'key'   => (string) $name,
			);
		}
		return $out;
	}

	/**
	 * The suggested word for a base in a language ('' = keep the original).
	 *
	 * @param string $base  Original base.
	 * @param array  $info  From bases().
	 * @param string $lang  Language.
	 */
	public static function suggestion( string $base, array $info, string $lang ): string {
		$lang = strtolower( $lang );
		$key  = (string) ( $info['key'] ?? '' );
		$prim = (string) preg_replace( '/[-_].*$/', '', $lang );
		$alias = array( 'no' => 'nb', 'nn' => 'nb', 'pt-br' => 'pt' );
		$code = isset( self::WORDS[ $lang ] ) ? $lang : ( isset( $alias[ $lang ] ) ? $alias[ $lang ] : $prim );
		// The built-in words fit only the default bases (a shop that renamed /product/ to /item/ chose its own word).
		$defaults = array( 'category' => 'category', 'post_tag' => 'tag', 'product' => 'product', 'product_cat' => 'product-category', 'product_tag' => 'product-tag' );
		if ( isset( self::KEYS[ $key ], self::WORDS[ $code ] ) && isset( $defaults[ $key ] ) && $defaults[ $key ] === $base ) {
			return self::WORDS[ $code ][ self::KEYS[ $key ] ];
		}
		$label = trim( (string) ( $info['label'] ?? '' ) );
		if ( '' !== $label && class_exists( Strings::class ) ) {
			$t = Strings::translate_texts( array( $label ), $lang );
			if ( isset( $t[ $label ] ) && '' !== trim( (string) $t[ $label ] ) ) {
				return (string) $t[ $label ];
			}
		}
		return '';
	}

	/**
	 * Work the words out again when something they depend on changed (setting, languages, bases, the owner's words),
	 * and once a day for the names translated meanwhile. Admin pages only: the front end only reads the option.
	 */
	public static function refresh(): void {
		$s      = Settings::get();
		$on     = ! empty( $s['translate_url_bases'] );
		$langs  = array_values( array_map( 'strtolower', (array) ( $s['target_languages'] ?? array() ) ) );
		$custom = isset( $s['url_bases_custom'] ) && is_array( $s['url_bases_custom'] ) ? $s['url_bases_custom'] : array();
		$bases  = $on ? self::bases() : array();
		$sig    = md5( wp_json_encode( array( $on, $langs, array_keys( $bases ), $custom ) ) );
		$d      = self::data();
		if ( ( $d['sig'] ?? '' ) === $sig && (int) ( $d['at'] ?? 0 ) > time() - DAY_IN_SECONDS ) {
			return;
		}
		$old_map = isset( $d['map'] ) && is_array( $d['map'] ) ? $d['map'] : array();
		$old     = isset( $d['old'] ) && is_array( $d['old'] ) ? $d['old'] : array();
		$map     = array();
		foreach ( $langs as $lang ) {
			$used = array();
			foreach ( $bases as $base => $info ) {
				$word = isset( $custom[ $lang ][ $base ] ) ? (string) $custom[ $lang ][ $base ] : self::suggestion( $base, $info, $lang );
				$slug = Slugs::clean( $word, (string) $lang ); // 14/11/2026: «Größe» → «groesse»
				// Not the original, not another base's original or translation, not a language code.
				if ( '' === $slug || $slug === $base || isset( $bases[ $slug ] ) || isset( $used[ $slug ] ) || in_array( $slug, $langs, true ) ) {
					continue;
				}
				$used[ $slug ]          = true;
				$map[ $lang ][ $base ] = $slug;
			}
			// A word used before keeps answering, with a 301 to today's.
			foreach ( (array) ( $old_map[ $lang ] ?? array() ) as $base => $was ) {
				if ( ( $map[ $lang ][ $base ] ?? '' ) !== $was ) {
					$old[ $lang ][ $was ] = $base;
				}
			}
			foreach ( (array) ( $map[ $lang ] ?? array() ) as $base => $slug ) {
				unset( $old[ $lang ][ $slug ] );
			}
		}
		$d = array( 'sig' => $sig, 'at' => time(), 'map' => $map, 'old' => $old );
		update_option( self::OPTION, $d, true );
		self::$data = $d;
	}

	/** Same segment? Addresses can carry a word encoded (%d0%ba…) or not. */
	private static function same( string $a, string $b ): bool {
		$a = rawurldecode( $a );
		$b = rawurldecode( $b );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $a, 'UTF-8' ) === mb_strtolower( $b, 'UTF-8' ) : strtolower( $a ) === strtolower( $b );
	}

	/**
	 * A path with its first word translated for a language (or as it was).
	 *
	 * @param string $path Path starting with «/», no language prefix (query allowed).
	 * @param string $lang Language.
	 */
	public static function to_translated( string $path, string $lang ): string {
		$map = self::map();
		$lang = strtolower( $lang );
		if ( empty( $map[ $lang ] ) || ! preg_match( '~^/([^/?#]+)(/.*)?$~s', $path, $m ) ) {
			return $path;
		}
		$first = strtolower( (string) $m[1] );
		if ( isset( $map[ $lang ][ $first ] ) && ( isset( $m[2] ) && '' !== $m[2] && '/' !== $m[2] ) ) {
			return '/' . $map[ $lang ][ $first ] . $m[2];
		}
		return $path;
	}

	/**
	 * An incoming path with its first word put back to the original base.
	 *
	 * @param string $path  Path starting with «/», no language prefix.
	 * @param string $lang  Language of the request.
	 * @param string $how   Set to 'translated' (today's word), 'old' (a word used before), 'original' (the original word
	 *                      while a translation exists) or '' (nothing to do).
	 */
	public static function to_original( string $path, string $lang, string &$how = '' ): string {
		$how  = '';
		$lang = strtolower( $lang );
		$map  = self::map();
		$d    = self::data();
		// Switched off: today's words are gone from the map but sit among the old ones, so the addresses search
		// engines know still lead to the page, with a 301 to the plain address.
		if ( ( empty( $map[ $lang ] ) && empty( $d['old'][ $lang ] ) ) || ! preg_match( '~^/([^/?#]+)(/.*)?$~s', $path, $m ) || empty( $m[2] ) || '/' === $m[2] ) {
			return $path;
		}
		$first = (string) $m[1];
		foreach ( (array) ( $map[ $lang ] ?? array() ) as $base => $slug ) {
			if ( self::same( $first, $slug ) ) {
				$how = 'translated';
				return '/' . $base . $m[2];
			}
			if ( self::same( $first, $base ) ) {
				$how = 'original';
				return $path;
			}
		}
		foreach ( (array) ( $d['old'][ $lang ] ?? array() ) as $was => $base ) {
			if ( self::same( $first, (string) $was ) ) {
				$how = 'old';
				return '/' . $base . $m[2];
			}
		}
		return $path;
	}
}
