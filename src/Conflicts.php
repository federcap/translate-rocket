<?php
/**
 * Other plugins and services doing the same job (1.8.6, Federico 14/10/2026: «se ci sono plugin che fanno lo stesso
 * lavoro li devi rilevare e dare un avviso»).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Page caches and JavaScript optimizers already active on the site.
 *
 * Why it matters: TranslateRocket's own cache of translated pages, under another page cache, keeps showing an old
 * translation even after that cache is cleared — the other cache rebuilds the page from ours. So with a page cache
 * already there, ours turns itself off (the saved setting does not change). Two JavaScript optimizers at once can
 * break menus, forms and the language switcher: that one is only said, the settings are theirs.
 *
 * Detection, cheapest first: (A) the plugin is active AND its page cache is on; (B) the advanced-cache.php drop-in
 * says who wrote it. Only constants, options already loaded and the first 2 KB of one file.
 */
class Conflicts {

	/**
	 * Page caches in use: id => name.
	 *
	 * @return array<string,string>
	 */
	public static function page_caches(): array {
		static $done = null;
		if ( null !== $done ) {
			return $done;
		}
		$out = array();
		$opt = static function ( string $name ) {
			$v = get_option( $name );
			return is_array( $v ) ? $v : array();
		};
		// 15/10/2026 (collaudo-conflitti-cache, real plugins): a cache that serves pages through advanced-cache.php
		// caches nothing when that file belongs to another plugin (on the bench: Cache Enabler on, WP Rocket's
		// drop-in). Such a cache counts only when the drop-in is its own — the signatures are the ones each plugin
		// writes in it (read in their official zips).
		$dropin = '';
		if ( defined( 'WP_CACHE' ) && WP_CACHE && is_readable( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$dropin = (string) file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php', false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file, first 4 KB.
		}
		$suo = static function ( array $firme ) use ( $dropin ): bool {
			foreach ( $firme as $f ) {
				if ( '' !== $dropin && false !== stripos( $dropin, $f ) ) {
					return true;
				}
			}
			return false;
		};
		// 15/10/2026 (full check on the bench): WP Rocket switched on but without a valid licence caches nothing —
		// counting it made TranslateRocket put its own cache aside and the site was left with no cache at all.
		// It counts only when its page cache can work: WP_CACHE, its drop-in loaded, a valid licence (checked here
		// without rocket_valid_key(), which writes a transient on every call when the licence is not valid).
		if ( defined( 'WP_ROCKET_VERSION' ) && function_exists( 'get_rocket_option' ) && defined( 'WP_CACHE' ) && WP_CACHE && defined( 'WP_ROCKET_ADVANCED_CACHE' ) ) {
			$segreto = (string) get_rocket_option( 'secret_key', '' );
			if ( '' !== $segreto && 8 === strlen( (string) get_rocket_option( 'consumer_key', '' ) ) && hash_equals( $segreto, hash( 'crc32', (string) get_rocket_option( 'consumer_email', '' ) ) ) ) {
				$out['wp-rocket'] = 'WP Rocket';
			}
		}
		if ( defined( 'LSCWP_V' ) && defined( 'LITESPEED_SERVER_TYPE' ) && 'NONE' !== LITESPEED_SERVER_TYPE && apply_filters( 'litespeed_conf', 'cache' ) ) {
			$out['litespeed'] = 'LiteSpeed Cache';
		}
		if ( defined( 'W3TC' ) && function_exists( 'w3tc_config' ) ) {
			$c = w3tc_config();
			// «Disk: Enhanced» serves through rewrite rules, the other engines through W3TC's own drop-in.
			$motore = ( is_object( $c ) && method_exists( $c, 'get_string' ) ) ? (string) $c->get_string( 'pgcache.engine' ) : '';
			if ( is_object( $c ) && method_exists( $c, 'get_boolean' ) && $c->get_boolean( 'pgcache.enabled' ) && ( 'file_generic' === $motore || $suo( array( 'W3TC', 'W3 Total Cache' ) ) ) ) {
				$out['w3tc'] = 'W3 Total Cache';
			}
		}
		if ( defined( 'WPCACHEHOME' ) && function_exists( 'wp_cache_is_enabled' ) && wp_cache_is_enabled() && $suo( array( 'WP SUPER CACHE', 'wp-cache-phase1', 'WPCACHEHOME' ) ) ) {
			$out['wpsc'] = 'WP Super Cache';
		}
		if ( class_exists( 'WpFastestCache' ) ) {
			$w = json_decode( (string) get_option( 'WpFastestCache' ), true );
			if ( is_array( $w ) && 'on' === ( $w['wpFastestCacheStatus'] ?? '' ) ) {
				$out['wpfc'] = 'WP Fastest Cache';
			}
		}
		if ( defined( 'CACHE_ENABLER_VERSION' ) && $suo( array( 'cache_enabler', 'Cache Enabler' ) ) ) {
			$out['cache-enabler'] = 'Cache Enabler';
		}
		if ( defined( '\SiteGround_Optimizer\VERSION' ) && ( get_option( 'siteground_optimizer_enable_cache' ) || get_option( 'siteground_optimizer_file_caching' ) ) ) {
			$out['sg'] = 'SiteGround Speed Optimizer';
		}
		if ( defined( 'BREEZE_VERSION' ) && ! empty( $opt( 'breeze_basic_settings' )['breeze-active'] ) && $suo( array( 'BREEZE_ADVANCED_CACHE', 'breeze' ) ) ) {
			$out['breeze'] = 'Breeze';
		}
		if ( defined( 'FLYING_PRESS_VERSION' ) ) {
			$out['flyingpress'] = 'FlyingPress';
		}
		if ( defined( 'NITROPACK_VERSION' ) && function_exists( 'nitropack_is_connected' ) && nitropack_is_connected() ) {
			$out['nitropack'] = 'NitroPack';
		}
		if ( defined( 'WPO_VERSION' ) && class_exists( 'WPO_Cache_Config' ) ) {
			$cfg = \WPO_Cache_Config::instance();
			if ( is_object( $cfg ) && method_exists( $cfg, 'get_option' ) && $cfg->get_option( 'enable_page_caching' ) && $suo( array( 'WP-Optimize advanced-cache.php', 'WP-Optimize' ) ) ) {
				$out['wpo'] = 'WP-Optimize';
			}
		}
		if ( defined( 'WPHB_VERSION' ) ) {
			$h = $opt( 'wphb_settings' );
			if ( ! empty( $h['page_cache']['enabled'] ) && $suo( array( 'WPHB', 'Hummingbird' ) ) ) {
				$out['hummingbird'] = 'Hummingbird';
			}
		}
		if ( defined( 'CLOUDFLARE_PLUGIN_DIR' ) ) {
			$apo = $opt( 'automatic_platform_optimization' );
			$psc = $opt( 'plugin_specific_cache' );
			if ( ! empty( $apo['value'] ) && 'off' !== $apo['value'] ) {
				$out['cloudflare-apo'] = 'Cloudflare APO';
			} elseif ( ! empty( $psc['value'] ) && 'off' !== $psc['value'] ) {
				$out['cloudflare'] = 'Cloudflare';
			}
		}
		// Managed hosts whose page cache is always there.
		if ( defined( 'KINSTAMU_VERSION' ) ) {
			$out['kinsta'] = 'Kinsta';
		}
		if ( class_exists( 'WpeCommon' ) || getenv( 'IS_WPE' ) ) {
			$out['wpengine'] = 'WP Engine';
		}
		if ( defined( 'IS_PRESSABLE' ) ) {
			$out['pressable'] = 'Pressable';
		}
		if ( class_exists( '\WPaaS\Plugin' ) ) {
			$out['godaddy'] = 'GoDaddy';
		}
		// (B) A drop-in written by a page cache whose plugin is not recognised above.
		if ( empty( $out ) && defined( 'WP_CACHE' ) && WP_CACHE ) {
			$f = WP_CONTENT_DIR . '/advanced-cache.php';
			if ( is_readable( $f ) ) {
				$testa = (string) file_get_contents( $f, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file, first 2 KB.
				foreach ( array( 'WP Rocket' => 'WP Rocket', 'W3 Total Cache' => 'W3 Total Cache', 'WP SUPER CACHE' => 'WP Super Cache', 'Cache Enabler' => 'Cache Enabler', 'SiteGround' => 'SiteGround Speed Optimizer', 'Hummingbird' => 'Hummingbird', 'Breeze' => 'Breeze', 'FlyingPress' => 'FlyingPress', 'NitroPack' => 'NitroPack', 'WP-Optimize' => 'WP-Optimize', 'batcache' => 'Batcache' ) as $firma => $nome ) {
					// A drop-in left by a plugin that is switched on: that plugin's own settings decided above (W3TC with
					// its page cache off keeps its drop-in, and caches nothing). Only a plugin no longer active counts here.
					$acceso = array( 'WP Rocket' => 'WP_ROCKET_VERSION', 'W3 Total Cache' => 'W3TC', 'WP SUPER CACHE' => 'WPCACHEHOME', 'Cache Enabler' => 'CACHE_ENABLER_VERSION', 'Breeze' => 'BREEZE_VERSION', 'Hummingbird' => 'WPHB_VERSION', 'WP-Optimize' => 'WPO_VERSION', 'FlyingPress' => 'FLYING_PRESS_VERSION', 'NitroPack' => 'NITROPACK_VERSION' );
					if ( isset( $acceso[ $firma ] ) && defined( $acceso[ $firma ] ) ) {
						continue;
					}
					if ( false !== stripos( $testa, $firma ) ) {
						$out[ 'drop-in' ] = $nome;
						break;
					}
				}
			}
		}
		/**
		 * The page caches TranslateRocket sees on this site (id => name). Return an empty array to keep
		 * TranslateRocket's own cache on anyway.
		 *
		 * @param array<string,string> $out Detected caches.
		 */
		$done = (array) apply_filters( 'trrocket_detected_page_caches', $out );
		return $done;
	}

	/**
	 * JavaScript optimizers that combine, defer or delay scripts: id => name.
	 *
	 * @return array<string,string>
	 */
	public static function js_optimizers(): array {
		$out = array();
		$opt = static function ( string $name ) {
			$v = get_option( $name );
			return is_array( $v ) ? $v : array();
		};
		if ( defined( 'WP_ROCKET_VERSION' ) && function_exists( 'get_rocket_option' ) && ( get_rocket_option( 'minify_concatenate_js' ) || get_rocket_option( 'defer_all_js' ) || get_rocket_option( 'delay_js' ) ) ) {
			$out['wp-rocket'] = 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) && ( apply_filters( 'litespeed_conf', 'optm-js_comb' ) || apply_filters( 'litespeed_conf', 'optm-js_defer' ) ) ) {
			$out['litespeed'] = 'LiteSpeed Cache';
		}
		if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) && 'on' === get_option( 'autoptimize_js' ) ) {
			$out['autoptimize'] = 'Autoptimize';
		}
		if ( defined( 'W3TC' ) && function_exists( 'w3tc_config' ) ) {
			$c = w3tc_config();
			if ( is_object( $c ) && method_exists( $c, 'get_boolean' ) && $c->get_boolean( 'minify.enabled' ) ) {
				$out['w3tc'] = 'W3 Total Cache';
			}
		}
		if ( defined( '\SiteGround_Optimizer\VERSION' ) && ( get_option( 'siteground_optimizer_combine_javascript' ) || get_option( 'siteground_optimizer_optimize_javascript_async' ) ) ) {
			$out['sg'] = 'SiteGround Speed Optimizer';
		}
		if ( defined( 'BREEZE_VERSION' ) ) {
			$b = $opt( 'breeze_basic_settings' );
			if ( ! empty( $b['breeze-group-js'] ) || ! empty( $b['breeze-delay-all-js'] ) ) {
				$out['breeze'] = 'Breeze';
			}
		}
		if ( defined( 'PERFMATTERS_VERSION' ) ) {
			$p = $opt( 'perfmatters_options' );
			if ( ! empty( $p['assets']['delay_js'] ) || ! empty( $p['assets']['defer_js'] ) ) {
				$out['perfmatters'] = 'Perfmatters';
			}
		}
		if ( defined( 'FLYING_PRESS_VERSION' ) ) {
			$out['flyingpress'] = 'FlyingPress';
		}
		if ( defined( 'NITROPACK_VERSION' ) ) {
			$out['nitropack'] = 'NitroPack';
		}
		return (array) apply_filters( 'trrocket_detected_js_optimizers', $out );
	}
}
