<?php
/**
 * Notices on TranslateRocket's own screens.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's screens opened under a stack of notices: three of ours (pages changed,
 * review request, the welcome) and whatever other plugins print — a cache plugin alone
 * put three there. On a phone the work started after a screen and a half of them
 * (5/10/2026, visual check). Here, only on the plugin's own screens:
 *  - at most ONE of ours, the most important (the others wait for the next screen);
 *  - other plugins' notices are kept, all of them, folded under one line that says how
 *    many there are and whether one is an error. Nothing is hidden: one click opens them.
 * Other screens of the site are not touched.
 */
class NoticeTidy {

	/**
	 * Our notices, most important first (data-trrocket values).
	 */
	const ORDER = array( 'provider-down', 'preview', 'copy', 'copy-cleanup', 'changed', 'welcome', 'review', 'donate' );

	/**
	 * Whether output is being captured.
	 *
	 * @var bool
	 */
	private $on = false;

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'start' ), PHP_INT_MIN );
		add_action( 'all_admin_notices', array( $this, 'stop' ), PHP_INT_MAX );
	}

	/**
	 * Whether this is one of the plugin's screens (the setup wizard clears notices on its own).
	 */
	public static function ours(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		return false !== strpos( $id, 'translate-rocket' ) && false === strpos( $id, 'translate-rocket-wizard' );
	}

	/**
	 * Start capturing what the notice hooks print.
	 */
	public function start(): void {
		if ( ! self::ours() || ! class_exists( '\DOMDocument' ) ) {
			return;
		}
		$this->on = true;
		ob_start();
	}

	/**
	 * Print the notices, tidied.
	 */
	public function stop(): void {
		if ( ! $this->on ) {
			return;
		}
		$this->on = false;
		// Until the header script moves them under the title they stay out of the flow and unseen:
		// on a busy server the browser could paint them above the page first, and then everything
		// jumped up 200-300 px (6/10/2026). Without JavaScript nothing moves them, so they show.
		$sel = '#wpbody-content>div.notice:not(.inline):not(.below-h2),#wpbody-content>div.updated:not(.inline):not(.below-h2),#wpbody-content>div.error:not(.inline):not(.below-h2)';
		echo '<style>' . $sel . '{visibility:hidden;position:absolute}</style><noscript><style>' . $sel . '{visibility:visible;position:static}</style></noscript>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed string.
		echo self::tidy( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- other plugins' own markup, already escaped by them.
	}

	/**
	 * The notices' HTML, with one notice of ours and the others folded.
	 *
	 * @param string $html Everything the notice hooks printed.
	 */
	public static function tidy( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="trr-avvisi-radice">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$root = $doc->getElementById( 'trr-avvisi-radice' );
		if ( ! $root ) {
			return $html;
		}
		$sempre  = array();
		$own     = array();
		$foreign = array();
		$rest    = '';
		$errors  = 0;
		foreach ( iterator_to_array( $root->childNodes ) as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				$rest .= $doc->saveHTML( $node );
				continue;
			}
			$tag = strtolower( $node->nodeName );
			if ( in_array( $tag, array( 'script', 'style', 'template' ), true ) ) {
				$rest .= $doc->saveHTML( $node );
				continue;
			}
			// 9/10/2026: a notice marked «trr-always» (who else can enter the site: Labs support access)
			// is never folded nor replaced by another one.
			if ( false !== strpos( ' ' . $node->getAttribute( 'class' ) . ' ', ' trr-always ' ) ) {
				$sempre[] = $doc->saveHTML( $node );
				continue;
			}
			if ( $node->hasAttribute( 'data-trrocket' ) ) {
				$own[ $node->getAttribute( 'data-trrocket' ) ] = $doc->saveHTML( $node );
				continue;
			}
			$class = ' ' . $node->getAttribute( 'class' ) . ' ';
			// Hidden placeholders other plugins keep for later stay as they are.
			if ( false !== strpos( $class, ' hidden ' ) ) {
				$rest .= $doc->saveHTML( $node );
				continue;
			}
			if ( false !== strpos( $class, 'notice-error' ) || false !== strpos( $class, ' error ' ) ) {
				++$errors;
			}
			// «inline» keeps WordPress' common.js from moving it out of the folded box.
			$node->setAttribute( 'class', trim( $node->getAttribute( 'class' ) . ' inline' ) );
			$foreign[] = $doc->saveHTML( $node );
		}
		$out = '';
		foreach ( self::ORDER as $key ) {
			if ( isset( $own[ $key ] ) ) {
				$out .= $own[ $key ];
				break;
			}
		}
		if ( '' === $out && $own ) {
			$out = reset( $own );
		}
		$out = implode( '', $sempre ) . $out;
		// «Put the languages in your menu» is shown once, and must not hide the notice that matters
		// on that screen (changed pages, the review on Next steps): it comes next to it.
		if ( isset( $own['menu-news'] ) && false === strpos( $out, 'data-trrocket="menu-news"' ) ) {
			$out .= $own['menu-news'];
		}
		if ( $foreign ) {
			$label = sprintf(
				/* translators: %d: how many notices other plugins printed. */
				_n( '%d notice from another plugin', '%d notices from other plugins', count( $foreign ), 'translate-rocket' ),
				count( $foreign )
			);
			if ( $errors > 0 ) {
				$label .= ' — ' . sprintf(
					/* translators: %d: how many of them are errors. */
					_n( '%d is an error', '%d are errors', $errors, 'translate-rocket' ),
					$errors
				);
			}
			$out .= '<div class="notice trr-altri-avvisi' . ( $errors > 0 ? ' ha-errori' : '' ) . '"><details><summary>' . esc_html( $label ) . '</summary><div class="trr-altri-avvisi-dentro">' . implode( '', $foreign ) . '</div></details></div>';
		}
		return $out . $rest;
	}
}
