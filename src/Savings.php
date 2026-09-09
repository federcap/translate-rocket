<?php
/**
 * Translation-reuse savings counter.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

use TranslateRocket\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Every character the translation memory keeps away from the AI provider is
 * money the user doesn't spend: the same text already translated on another
 * page, as an attribute, as an interface string — or forced by the glossary —
 * is copied for free instead of being sent to the API again. This class keeps
 * the cumulative tally and turns it into a rough cost estimate.
 */
class Savings {

	private const OPTION = 'trrocket_savings';

	/**
	 * Rough public list prices per ONE MILLION characters, in EUR, used for the
	 * estimate shown in the admin. Filterable via trrocket_savings_cost_per_million.
	 */
	private const COST_PER_MILLION = array(
		'deepl'     => 20.0,
		'google'    => 18.0,
		'openai'    => 3.0,
		'anthropic' => 4.0,
		'gemini'    => 2.0,
	);

	/**
	 * Record characters (and one string) that were translated by reuse instead
	 * of an API call.
	 */
	public static function add( int $chars ): void {
		if ( $chars <= 0 ) {
			return;
		}
		$t            = self::totals();
		$t['chars']   = (int) $t['chars'] + $chars;
		$t['strings'] = (int) $t['strings'] + 1;
		update_option( self::OPTION, $t, false );
	}

	/**
	 * Cumulative savings so far.
	 *
	 * @return array{chars:int,strings:int,since:string}
	 */
	public static function totals(): array {
		$t = get_option( self::OPTION, array() );
		return array(
			'chars'   => (int) ( $t['chars'] ?? 0 ),
			'strings' => (int) ( $t['strings'] ?? 0 ),
			'since'   => (string) ( $t['since'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
	}

	/**
	 * EUR estimate of what the saved characters would have cost, priced against
	 * the active provider (or DeepL as the benchmark when none / a free one is
	 * active). Returns the amount and the provider label the price refers to.
	 *
	 * @return array{eur:float,provider:string}
	 */
	public static function estimate( int $chars ): array {
		$provider = Registry::active();
		$pid      = null !== $provider ? $provider->id() : 'deepl';
		if ( ! isset( self::COST_PER_MILLION[ $pid ] ) ) {
			$pid = 'deepl';
		}
		$per_million = (float) apply_filters( 'trrocket_savings_cost_per_million', self::COST_PER_MILLION[ $pid ], $pid );
		return array(
			'eur'      => $chars / 1000000 * $per_million,
			'provider' => ( null !== $provider && $provider->id() === $pid ) ? $provider->label() : 'DeepL',
		);
	}
}
