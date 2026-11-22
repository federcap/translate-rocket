<?php
/**
 * Brizy and Brizy Pro (1.8.24).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

defined( 'ABSPATH' ) || exit;

use TranslateRocket\Coexistence;
use TranslateRocket\Plugin;

/**
 * 27/10/2026 (lungomare.ee, a Brizy site: «the photos disappeared», plugin deactivated after 33 minutes; reproduced with
 * collaudo-brizy): Brizy saves every address of a page with a placeholder instead of the site's address and puts it back
 * with home_url() while it prints the page (Brizy_Editor_Asset_DomainProcessor → restoreSiteUrl). On a translated page
 * home_url() carries the language, so every photo became /it//wp-content/uploads/… — a 404 — and every link
 * /it//page/; side by side with another plugin the preview turned it into /?trr-preview=it/page/. TranslatePress
 * leaves home_url() alone inside Brizy's restoreSiteUrl/process (read in its compatibility-functions.php); here the
 * same, for as long as Brizy's own filter runs. The links are given their language afterwards, with the rest of the page.
 */
class Brizy {

	/**
	 * How deep we are inside brizy_content (Brizy nests it for global blocks and popups).
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Our home_url filters taken off while Brizy works: [callback, priority].
	 *
	 * @var array<int,array{0:callable,1:int}>
	 */
	private static $paused = array();

	/**
	 * Hooks.
	 */
	public static function boot(): void {
		add_filter( 'brizy_content', array( __CLASS__, 'enter' ), PHP_INT_MIN );
		add_filter( 'brizy_content', array( __CLASS__, 'leave' ), PHP_INT_MAX );
		add_filter( 'get_post_metadata', array( __CLASS__, 'rules_for_copy' ), 10, 4 );
	}

	/**
	 * Re-entrancy guard of rules_for_copy().
	 *
	 * @var bool
	 */
	private static $reading = false;

	/**
	 * 22/11/2026 (lungomare.ee: «при переходе на русский пропала верхушка сайта», reproduced with collaudo-copia-brizy):
	 * Brizy shows its global blocks — the site header, the footer — by rules tied to the page's ID («only on page
	 * 1220»). An independent copy is another post, so the Russian page lost its header. While a copy is served, a rule
	 * that names the source page names its copy too. Nothing is written: only what Brizy reads for this request.
	 *
	 * @param mixed  $value    Short-circuit value (null = read the database).
	 * @param int    $post_id  Post the meta belongs to (a Brizy global block or template).
	 * @param string $meta_key Meta key.
	 * @param bool   $single   Single value asked.
	 * @return mixed
	 */
	public static function rules_for_copy( $value, $post_id, $meta_key, $single ) {
		if ( self::$reading || ( 'brizy-rules' !== $meta_key && 'brizy-template-rules' !== $meta_key ) || ! \TranslateRocket\Frontend\CopyServer::is_serving_copy() ) {
			return $value;
		}
		$source = \TranslateRocket\Frontend\CopyServer::source_id();
		$copy   = \TranslateRocket\Frontend\CopyServer::serving_id();
		self::$reading = true;
		$rules         = get_post_meta( (int) $post_id, $meta_key, true );
		self::$reading = false;
		if ( ! is_array( $rules ) || $source <= 0 || $copy <= 0 ) {
			return $value;
		}
		$changed = false;
		foreach ( $rules as $i => $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['entityValues'] ) || ! is_array( $rule['entityValues'] ) ) {
				continue;
			}
			$ids = array_map( 'strval', $rule['entityValues'] );
			if ( in_array( (string) $source, $ids, true ) && ! in_array( (string) $copy, $ids, true ) ) {
				$rules[ $i ]['entityValues'][] = $copy;
				$changed                      = true;
			}
		}
		if ( ! $changed ) {
			return $value;
		}
		// get_metadata() takes [0] of what we return when a single value is asked.
		unset( $single );
		return array( $rules );
	}

	/**
	 * Brizy starts putting the page together: home_url() goes back to the site's address.
	 *
	 * @param mixed $content Page content.
	 * @return mixed
	 */
	public static function enter( $content ) {
		if ( 0 === self::$depth++ ) {
			$ours = array(
				array( Plugin::instance()->router(), 'filter_home_url' ),
				array( Coexistence::class, 'preview_link' ),
			);
			foreach ( $ours as $cb ) {
				$prio = has_filter( 'home_url', $cb );
				if ( false !== $prio ) {
					remove_filter( 'home_url', $cb, (int) $prio );
					self::$paused[] = array( $cb, (int) $prio );
				}
			}
		}
		return $content;
	}

	/**
	 * Brizy is done: our filters go back.
	 *
	 * @param mixed $content Page content.
	 * @return mixed
	 */
	public static function leave( $content ) {
		if ( self::$depth > 0 && 0 === --self::$depth ) {
			foreach ( self::$paused as $p ) {
				add_filter( 'home_url', $p[0], $p[1], 2 );
			}
			self::$paused = array();
		}
		return $content;
	}
}
