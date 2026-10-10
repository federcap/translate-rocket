<?php
/**
 * WP Rocket: tell it the site has languages.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

use TranslateRocket\Plugin;
use TranslateRocket\Slugs;

defined( 'ABSPATH' ) || exit;

/**
 * WP Rocket knows WPML, Polylang and qTranslate by name and asks everyone else
 * through its i18n filters (read in WP Rocket 3.23.4, inc/functions/i18n.php,
 * 28/09/2026). Without an answer it takes the site for single-language, and:
 *
 *  - the empty mini-cart it keeps to answer WooCommerce's cart refresh without
 *    loading WordPress is ONE for all languages: the first visitor's language
 *    was served to everybody (WooCommerceSubscriber::get_cache_empty_cart());
 *  - the pages it never caches (cart, checkout, account) are listed by their
 *    default-language address only — WooCommerce's DONOTCACHEPAGE still stops
 *    /it/checkout/ from being cached, this is the second lock on the door;
 *  - when a post changes it purges that post's address, not its /it/ one.
 *
 * Only reached when no other multilingual plugin is active: WP Rocket checks
 * WPML, Polylang and qTranslate first and never asks the filters then.
 */
class WpRocket {

	/**
	 * Hook the filters. Cheap: they only run when WP Rocket calls them.
	 */
	public function boot(): void {
		add_filter( 'rocket_has_i18n', array( $this, 'has_i18n' ) );
		add_filter( 'rocket_get_i18n_code', array( $this, 'codes' ) );
		add_filter( 'rocket_i18n_home_url', array( $this, 'home_url' ), 10, 2 );
		add_filter( 'rocket_get_i18n_uri', array( $this, 'homes' ) );
		add_filter( 'rocket_i18n_current_language', array( $this, 'current' ) );
		add_filter( 'rocket_i18n_translated_post_urls', array( $this, 'post_paths' ), 10, 4 );
		add_filter( 'rocket_post_purge_urls', array( $this, 'purge_urls' ), 10, 2 );
		// Same two hooks WP Rocket wires for TranslatePress, which works like us
		// (ThirdParty/Plugins/I18n/TranslatePress.php): /it/ is a home page for its
		// optimisation service, and «Clear cache» in the admin bar offers each language.
		add_filter( 'rocket_saas_is_home_url', array( $this, 'is_home' ), 10, 2 );
		add_filter( 'rocket_i18n_admin_bar_menu', array( $this, 'admin_bar' ) );
		// «Never cache this page» (the box on the edit screen) and the «Never cache
		// URL(s)» list hold default-language paths only: the /it/ copy of an excluded
		// page — a members' area, a page with personal data — was cached anyway.
		add_filter( 'rocket_cache_reject_uri', array( $this, 'reject_uri' ), 20 );
		// WP Rocket writes that list into its config file and .htaccess only when its
		// own settings are saved: a language added in TranslateRocket later, or this
		// integration arriving with an update, would not be in there.
		add_action( 'admin_init', array( $this, 'maybe_refresh' ) );
	}

	/**
	 * Rewrite WP Rocket's config file and .htaccess when our languages changed.
	 */
	public function maybe_refresh(): void {
		if ( ! function_exists( 'rocket_generate_config_file' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$firma = md5( wp_json_encode( array( $this->languages(), TRROCKET_VERSION ) ) );
		if ( get_option( 'trrocket_wprocket_sig' ) === $firma ) {
			return;
		}
		update_option( 'trrocket_wprocket_sig', $firma, false );
		rocket_generate_config_file();
		if ( function_exists( 'flush_rocket_htaccess' ) ) {
			flush_rocket_htaccess();
		}
	}

	/**
	 * Every never-cache path also in the other languages.
	 *
	 * @param mixed $uris Path patterns (regular-expression fragments).
	 * @return mixed
	 */
	public function reject_uri( $uris ) {
		$langs = $this->languages();
		if ( ! is_array( $uris ) || ! $langs ) {
			return $uris;
		}
		$router = Plugin::instance()->router();
		$home   = rtrim( (string) get_option( 'home' ), '/' );
		$extra  = array();
		foreach ( $uris as $uri ) {
			$uri = (string) $uri;
			// Patterns that already allow any prefix (feeds, embeds) or aren't paths.
			if ( '' === $uri || '/' !== $uri[0] || 0 === strpos( $uri, '/(?:' ) || 0 === strpos( $uri, '/(.*)' ) || 0 === strpos( $uri, '/.*' ) ) {
				continue;
			}
			foreach ( $langs as $lang ) {
				if ( $router->is_default( $lang ) || 0 === strpos( $uri, '/' . $lang . '/' ) ) {
					continue;
				}
				$extra[] = '/' . $lang . $uri;
			}
			// A plain path to a page: its translated slug too (/it/pagina-riservata/).
			if ( ! preg_match( '/[()\[\]*+?.|\\\\^$]/', $uri ) ) {
				$id = url_to_postid( $home . $uri );
				if ( $id ) {
					foreach ( $this->translated_urls( $home . $uri, $id ) as $url ) {
						$path = wp_parse_url( $url, PHP_URL_PATH );
						if ( is_string( $path ) && '' !== $path ) {
							$extra[] = $path;
						}
					}
				}
			}
		}
		return array_values( array_unique( array_merge( $uris, $extra ) ) );
	}

	/**
	 * A language's home page is a home page.
	 *
	 * @param mixed  $home_url The home URL WP Rocket compares with.
	 * @param string $url      The page being optimised.
	 * @return mixed
	 */
	public function is_home( $home_url, $url = '' ) {
		$router = Plugin::instance()->router();
		foreach ( $this->languages() as $lang ) {
			$home = $router->home_for_language( $lang );
			if ( untrailingslashit( (string) $url ) === untrailingslashit( $home ) ) {
				return $url;
			}
		}
		return $home_url;
	}

	/**
	 * One «Clear cache» entry per language in the admin bar.
	 *
	 * @param mixed $links Entries so far.
	 * @return mixed
	 */
	public function admin_bar( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		foreach ( $this->languages() as $lang ) {
			$links[ $lang ] = array(
				'code'   => $lang,
				'flag'   => '',
				'anchor' => esc_html( \TranslateRocket\Languages::label( $lang ) ),
			);
		}
		return $links;
	}

	/**
	 * Languages visitors can see, default first ('' list when there is nothing
	 * translated yet: then the site IS single-language for WP Rocket).
	 *
	 * @return string[]
	 */
	private function languages(): array {
		$router = Plugin::instance()->router();
		$others = array_values( array_diff( (array) $router->active_languages(), array( $router->default_language() ) ) );
		if ( empty( $others ) || \TranslateRocket\Coexistence::on() ) {
			return array();
		}
		return array_merge( array( $router->default_language() ), $others );
	}

	/**
	 * «Which multilingual plugin is this?»
	 *
	 * @param mixed $identifier Answer so far.
	 * @return mixed
	 */
	public function has_i18n( $identifier ) {
		if ( is_string( $identifier ) && '' !== $identifier ) {
			return $identifier;
		}
		return $this->languages() ? 'translaterocket' : $identifier;
	}

	/**
	 * Language codes = the folder names under the cache directory (/it/, /de/).
	 *
	 * @param mixed $codes Codes so far.
	 * @return mixed
	 */
	public function codes( $codes ) {
		$mine = $this->languages();
		return $mine ? $mine : $codes;
	}

	/**
	 * Home page of one language.
	 *
	 * @param mixed  $url  Home URL so far.
	 * @param string $lang Language code.
	 * @return mixed
	 */
	public function home_url( $url, $lang = '' ) {
		$lang = (string) $lang;
		if ( '' === $lang || ! in_array( $lang, $this->languages(), true ) ) {
			return $url;
		}
		return Plugin::instance()->router()->home_for_language( $lang );
	}

	/**
	 * Home pages of every language.
	 *
	 * @param mixed $urls URLs so far.
	 * @return mixed
	 */
	public function homes( $urls ) {
		$langs = $this->languages();
		if ( ! $langs ) {
			return $urls;
		}
		$router = Plugin::instance()->router();
		return array_map( array( $router, 'home_for_language' ), $langs );
	}

	/**
	 * Language of this request.
	 *
	 * @param mixed $lang Answer so far.
	 * @return mixed
	 */
	public function current( $lang ) {
		if ( ! $this->languages() ) {
			return $lang;
		}
		return Plugin::instance()->router()->current_language();
	}

	/**
	 * The address path of a post in every translated language, with WP Rocket's
	 * pattern appended (used for the pages it must never cache).
	 *
	 * @param mixed  $urls      Paths so far.
	 * @param string $url       The post's default-language URL.
	 * @param string $post_type Post type.
	 * @param string $regex     Pattern to append.
	 * @return mixed
	 */
	public function post_paths( $urls, $url = '', $post_type = 'page', $regex = '' ) {
		unset( $post_type );
		if ( ! is_array( $urls ) ) {
			return $urls;
		}
		$id = url_to_postid( (string) $url );
		foreach ( $this->translated_urls( (string) $url, $id ) as $translated ) {
			$path = wp_parse_url( $translated, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				$urls[] = $path . $regex;
			}
		}
		return $urls;
	}

	/**
	 * When a post changes, its translated addresses go too.
	 *
	 * @param mixed $urls URLs WP Rocket is about to purge.
	 * @param mixed $post WP_Post.
	 * @return mixed
	 */
	public function purge_urls( $urls, $post = null ) {
		if ( ! is_array( $urls ) || ! $this->languages() ) {
			return $urls;
		}
		$id    = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$extra = array();
		foreach ( $urls as $url ) {
			$extra = array_merge( $extra, $this->translated_urls( (string) $url, (int) url_to_postid( (string) $url ) === $id ? $id : 0 ) );
		}
		return array_values( array_unique( array_merge( $urls, $extra ) ) );
	}

	/**
	 * A default-language URL in every other language: /it/ prefix, and the
	 * translated slug when the post has one.
	 *
	 * @param string $url     Default-language URL.
	 * @param int    $post_id Post it belongs to (0 = unknown, prefix only).
	 * @return string[]
	 */
	private function translated_urls( string $url, int $post_id ): array {
		$home = rtrim( (string) get_option( 'home' ), '/' );
		if ( '' === $url || 0 !== strpos( $url, $home ) ) {
			return array();
		}
		$rest   = '/' . ltrim( substr( $url, strlen( $home ) ), '/' );
		$router = Plugin::instance()->router();
		$post   = $post_id ? get_post( $post_id ) : null;
		$out    = array();
		foreach ( $this->languages() as $lang ) {
			if ( $router->is_default( $lang ) ) {
				continue;
			}
			$path = $rest;
			if ( $post && '' !== (string) $post->post_name ) {
				$slug = Slugs::get( $post_id, $lang );
				if ( '' !== $slug ) {
					$path = preg_replace( '#/' . preg_quote( (string) $post->post_name, '#' ) . '(/?)$#', '/' . $slug . '$1', $path );
				}
			}
			$out[] = $router->language_base( $lang ) . $path; // home/xx or the language's own domain
		}
		return $out;
	}
}
