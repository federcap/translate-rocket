<?php
/**
 * A short card that says what TranslateRocket Premium is.
 *
 * 24/11/2026 (Federico: «è importantissima, la devi mettere nel plugin… dicendo che è nel premium questa funzione»).
 * Premium is a separate plugin: this card only describes it and links to its page. Nothing in this plugin is locked,
 * limited or waiting for a licence, and the card is not shown where Premium is already installed.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The Premium card (AI Translation page and the overview).
 */
class PremiumNote {

	const URL = 'https://translaterocket.com/premium/';

	/**
	 * Prints the card, unless Premium is already active on this site.
	 *
	 * @param string $where Where it is shown: «ai» or «overview» (only a CSS hook).
	 */
	public static function render( string $where = 'ai' ): void {
		if ( defined( 'TRRPREM_VERSION' ) ) {
			return;
		}
		?>
		<div class="trr-premium-note trr-premium-note-<?php echo esc_attr( sanitize_html_class( $where ) ); ?>">
			<p class="trr-premium-note-tit"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'New: TranslateRocket Premium — double-checked translation', 'translate-rocket' ); ?></p>
			<p><?php esc_html_e( 'Two or three engines translate every sentence, automatic checks compare numbers, prices, links and names, an AI judge settles only the sentences in doubt, and you have the last word. It also translates a page by itself when you publish it, with the double check. It is a separate plugin that works with your own keys.', 'translate-rocket' ); ?></p>
			<p class="trr-premium-note-free"><?php esc_html_e( 'Nothing is taken away from this plugin: it stays free, with everything it has today.', 'translate-rocket' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( self::URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Premium', 'translate-rocket' ); ?> ↗</a></p>
		</div>
		<?php
	}
}
