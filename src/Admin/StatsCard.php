<?php
/**
 * «Visits per language» on the Languages screen (1.8.6).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Admin;

use TranslateRocket\Languages;
use TranslateRocket\Settings;
use TranslateRocket\Stats;
use TranslateRocket\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Which language is read, which pages, and which languages the visitors' browsers speak — with the one
 * hint that matters: «12% of your visitors read German and you have no German yet».
 */
class StatsCard {

	/**
	 * The card, only when the counting is on.
	 */
	public static function render(): void {
		if ( ! Stats::enabled() ) {
			return;
		}
		$giorni = 30;
		$lingue = Stats::by_language( $giorni );
		$tot    = array_sum( $lingue );
		echo '<div class="trrocket-card trr-stats" id="trr-stats">';
		echo '<h2>📊 ' . esc_html__( 'Visits per language', 'translate-rocket' ) . ' <span class="description">' . esc_html__( 'last 30 days', 'translate-rocket' ) . '</span> ';
		Admin::info_static( __( 'Counted by a small signal the browser sends after the page has loaded, so it works with page caches and CDNs too. No cookie, no IP address and nothing personal is kept: only how many visits each language and page had each day, and the language of the visitor\'s browser. Robots, search engines and you (the administrators) are not counted. Days older than 400 are deleted by themselves.', 'translate-rocket' ) );
		echo '</h2>';
		if ( $tot < 1 ) {
			echo '<p class="description">' . esc_html__( 'No visits counted yet: they appear here from the next visit on.', 'translate-rocket' ) . '</p></div>';
			return;
		}

		// Visits per day, all languages: a small line.
		$giorno = Stats::per_day( $giorni );
		$max    = max( 1, max( $giorno ) );
		$punti  = array();
		$i      = 0;
		$n      = count( $giorno );
		foreach ( $giorno as $v ) {
			$punti[] = round( 600 * $i / max( 1, $n - 1 ), 1 ) . ',' . round( 58 - 54 * $v / $max, 1 );
			++$i;
		}
		echo '<svg class="trr-stats-line" viewBox="0 0 600 60" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Visits per day', 'translate-rocket' ) . '"><polyline fill="none" stroke="#4f46e5" stroke-width="2" points="' . esc_attr( implode( ' ', $punti ) ) . '"/></svg>';

		// Per language, with its most read pages.
		echo '<table class="trr-stats-table"><tbody>';
		$router = Plugin::instance()->router();
		foreach ( $lingue as $code => $v ) {
			$pct = (int) round( 100 * $v / $tot );
			echo '<tr><th scope="row">' . wp_kses( \TranslateRocket\Flags::svg( $code ), \TranslateRocket\Kses::html_rules() ) . ' ' . esc_html( Languages::label( $code ) ) . '</th>';
			echo '<td class="trr-stats-bar"><span style="width:' . (int) max( 2, $pct ) . '%"></span></td>';
			echo '<td class="trr-stats-n">' . esc_html( Formati::number( $v ) ) . ' <span class="description">(' . (int) $pct . '%)</span></td></tr>';
			$pagine = Stats::top_pages( $code, $giorni, 3 );
			if ( $pagine ) {
				echo '<tr class="trr-stats-pages"><td></td><td colspan="2">';
				$parti = array();
				foreach ( $pagine as $path => $nv ) {
					$parti[] = '<code>' . esc_html( $path ) . '</code> ' . esc_html( Formati::number( $nv ) );
				}
				echo wp_kses_post( implode( ' · ', $parti ) );
				echo '</td></tr>';
			}
		}
		echo '</tbody></table>';

		// The visitors' browsers: which languages they speak, and which of them the site does not offer yet.
		$browser = Stats::browser_languages( $giorni, 8 );
		if ( $browser ) {
			$tot_b   = max( 1, array_sum( $browser ) );
			$offerte = array_merge( array( (string) $router->default_language() ), array_map( 'strtolower', (array) ( Settings::get()['target_languages'] ?? array() ) ) );
			$offerte = array_map( static function ( $c ) { return substr( (string) $c, 0, 2 ); }, $offerte );
			echo '<h3>' . esc_html__( 'The languages of your visitors\' browsers', 'translate-rocket' ) . '</h3><ul class="trr-stats-browser">';
			$manca = '';
			foreach ( $browser as $bl => $v ) {
				$pct = (int) round( 100 * $v / $tot_b );
				$ha  = in_array( $bl, $offerte, true );
				$nom = Languages::exists( $bl ) ? Languages::label( $bl ) : strtoupper( $bl );
				echo '<li>' . ( Languages::exists( $bl ) ? wp_kses( \TranslateRocket\Flags::svg( $bl ), \TranslateRocket\Kses::html_rules() ) . ' ' : '' ) . esc_html( $nom ) . ' <strong>' . (int) $pct . '%</strong>'
					. ( $ha ? '' : ' <span class="trr-stats-new">' . esc_html__( 'not on your site yet', 'translate-rocket' ) . '</span>' ) . '</li>';
				if ( ! $ha && '' === $manca && $pct >= 5 && Languages::exists( $bl ) ) {
					$manca = sprintf(
						/* translators: 1: percentage, 2: language name. */
						__( '%1$d%% of your visitors read %2$s, and your site has no %2$s yet: adding it is one click in the list above.', 'translate-rocket' ),
						$pct,
						$nom
					);
				}
			}
			echo '</ul>';
			if ( '' !== $manca ) {
				echo '<p class="trr-stats-hint">💡 ' . esc_html( $manca ) . '</p>';
			}
		}
		echo '</div>';
	}
}
