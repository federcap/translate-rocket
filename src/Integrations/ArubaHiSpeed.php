<?php
/**
 * Aruba HiSpeed Cache (1.8.8, 16/10/2026).
 *
 * The cache of Aruba's hosting (the most used host in Italy) is an nginx cache emptied by a GET
 * to a purger on the server itself (127.0.0.1:8889/purge/<path>), through the plugin's own class
 * (aruba-hispeed-cache 3.1.0: src/Purger/WpPurger.php, AHSC_PURGER in src/AHSC_Config.php).
 * Two gaps for a translated site, both read in its code:
 * - when a translation changed, nothing told Aruba: /en/… stayed old until the cache expired;
 * - when a post is saved, Aruba empties its address in the site's language only.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Empty Aruba's cache for the translated pages too.
 */
class ArubaHiSpeed {

	/**
	 * Aruba's purger, ready, or null when the plugin is not there.
	 *
	 * @return object|null
	 */
	private static function purger() {
		if ( ! class_exists( '\ArubaSPA\HiSpeedCache\Purger\WpPurger' ) || ! defined( 'AHSC_PURGER' ) ) {
			return null;
		}
		try {
			$p = new \ArubaSPA\HiSpeedCache\Purger\WpPurger();
			$p->setPurger( constant( 'AHSC_PURGER' ) );
			return $p;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Everything (from Cache::flush(): a translation changed, a language went online…).
	 */
	public static function purge_all(): void {
		$p = self::purger();
		if ( $p && method_exists( $p, 'purgeAll' ) ) {
			try {
				$p->purgeAll();
			} catch ( \Throwable $e ) {
				unset( $e ); // a purger that does not answer never breaks a save
			}
		}
	}

	/**
	 * Hook into WordPress (only when Aruba's plugin is there).
	 */
	public function boot(): void {
		add_action( 'save_post', array( $this, 'post_saved' ), 99, 2 );
	}

	/**
	 * A post saved: its translated addresses too (Aruba empties the site-language one).
	 *
	 * @param mixed $id   Post ID.
	 * @param mixed $post Post.
	 */
	public function post_saved( $id, $post ): void {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || wp_is_post_revision( (int) $id ) || wp_is_post_autosave( (int) $id ) ) {
			return;
		}
		$p = self::purger();
		if ( ! $p || ! method_exists( $p, 'purgeUrls' ) ) {
			return;
		}
		$link = get_permalink( $post );
		if ( ! $link ) {
			return;
		}
		// The same addresses WP Rocket is given: /xx/ prefix or own domain, and the translated slug.
		$tutte = ( new WpRocket() )->purge_urls( array( $link ), $post );
		$altre = array_values( array_diff( (array) $tutte, array( $link ) ) );
		if ( $altre ) {
			try {
				$p->purgeUrls( $altre );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
