<?php
/**
 * Optional first-visit redirect to the visitor's browser language.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Settings;
use TranslateRocket\AiUsage;

defined( 'ABSPATH' ) || exit;

/**
 * On a visitor's first visit, send them to the version of the page that matches
 * their browser language, then remember it so it never fires again. Opt-in;
 * never sends bots, logged-in users, feeds or REST anywhere.
 *
 * The decision is taken in the browser, not in PHP. It used to be a PHP redirect
 * that remembered the visitor with a cookie, and that cannot work in front of a
 * page cache:
 *
 *  - Varnish (the default on Cloudways and many other hosts) strips cookies it
 *    does not recognise from incoming requests, so the "already decided" cookie
 *    never reached PHP. The redirect fired again on every page, and a language
 *    picked from the switcher was undone immediately.
 *  - On a cached page PHP does not run at all, so the redirect did not happen
 *    there — the same site behaved differently from one page to the next.
 *
 * Reading the choice from localStorage sidesteps both: it survives any cache in
 * front of the site, and it works on pages served without touching PHP.
 */
class Redirect {

	/** Where the browser remembers the visitor's language. */
	const STORE = 'trrocket_lang';

	/**
	 * Hook into the front end.
	 */
	public function boot(): void {
		if ( is_admin() ) {
			return;
		}
		// Priority 1: decide before the page paints, so there is no flash of the
		// wrong language on the way out.
		add_action( 'wp_head', array( $this, 'script' ), 1 );
	}

	/**
	 * Print the decision script, when it makes sense to print it at all.
	 */
	public function script(): void {
		if ( empty( Settings::get()['auto_redirect'] ) ) {
			return;
		}
		if ( Preview::hidden() ) {
			return; // Translations aren't public yet — don't send visitors there.
		}
		if ( is_user_logged_in() || VisualEditor::is_editing() ) {
			return; // editors testing the site shouldn't be bounced around
		}
		if ( is_feed() || is_robots() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( AiUsage::is_bot() ) {
			return; // let crawlers index every language version
		}

		$router = Plugin::instance()->router();
		$active = $router->active_languages();
		if ( count( $active ) < 2 ) {
			return;
		}

		$urls = array();
		foreach ( $active as $code ) {
			$urls[ $code ] = $router->url_for_language( $code );
		}

		$dati = wp_json_encode(
			array(
				'store'   => self::STORE,
				'current' => $router->current_language(),
				'urls'    => $urls,
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::JS is a fixed literal, the data is JSON-encoded above.
		echo '<script id="trrocket-redirect">(' . self::JS . ')(' . $dati . ');</script>' . "\n";
	}

	/**
	 * The decision, as it runs in the browser.
	 *
	 * One small self-calling function, printed inline: it has to run before the
	 * page paints, so it cannot wait for an external file to download.
	 */
	const JS = <<<'JS'
function(d){try{
var store=d.store,urls=d.urls,cur=d.current;
/* Crawlers run JavaScript too, and they should see every language rather than
   being funnelled into one. hreflang already tells them how the versions relate. */
if(/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly/i.test(navigator.userAgent)){return;}

var scelto=null;
try{scelto=window.localStorage.getItem(store);}catch(e){return;}/* private mode: leave the visitor alone */

/* Picking a language in the switcher is a decision: remember it and never
   second-guess it. Delegated, so it covers every switcher on the page whatever
   markup it uses, and captured so it runs before the browser follows the link. */
document.addEventListener('click',function(ev){
var a=ev.target&&ev.target.closest?ev.target.closest('a[href]'):null;
if(!a){return;}
for(var code in urls){if(urls[code]===a.href){try{window.localStorage.setItem(store,code);}catch(e){}return;}}
},true);

if(scelto){return;}/* already decided, on this visit or an earlier one */

/* Best match between what the browser asks for and what the site offers. */
var chieste=(navigator.languages&&navigator.languages.length)?navigator.languages:[navigator.language||''];
var best='';
for(var i=0;i<chieste.length&&!best;i++){
var l=String(chieste[i]).toLowerCase();
if(urls[l]){best=l;break;}
var base=l.split('-')[0];
if(urls[base]){best=base;}
}

/* Decide once, whichever way it goes, so this never runs again. */
try{window.localStorage.setItem(store,best||cur);}catch(e){}
if(best&&best!==cur&&urls[best]){
/* replace(), not assign(): nobody should have to press Back twice to leave a
   page they never asked for. */
window.location.replace(urls[best]);
}
}catch(e){}}
JS;
}
