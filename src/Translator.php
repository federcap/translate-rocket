<?php
/**
 * Auto-translation orchestrator.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

use TranslateRocket\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Translates the missing strings for a language (whole site or one page) using
 * the active AI provider, in batches, and stores the results as machine
 * translations (status = 1).
 */
class Translator {

	/**
	 * Translate the strings still missing for a language using the free Google
	 * endpoint (no API key, no usage limit). One request per string, so this is
	 * meant for a single page; it stops early if Google starts refusing.
	 *
	 * @param string $lang     Target language.
	 * @param string $url_hash Limit to one page (empty = whole site, capped).
	 * @return array{ok:bool,count:int,error:string}
	 */
	public static function google_missing( string $lang, string $url_hash = '' ): array {
		if ( ! GoogleFree::auto_enabled() ) {
			return array(
				'ok'    => false,
				'count' => 0,
				'error' => __( 'Automatic Google translation is turned off.', 'translate-rocket' ),
			);
		}
		$rows = ( '' !== $url_hash )
			? Strings::untranslated_for_page_language( $url_hash, $lang )
			: Strings::untranslated_for_language( $lang, 500 );

		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return ! NoTranslate::text_excluded( (string) $row->original );
				}
			)
		);
		if ( empty( $rows ) ) {
			return array(
				'ok'    => true,
				'count' => 0,
				'error' => '',
			);
		}

		$source = Plugin::instance()->router()->default_language();
		$count  = 0;
		$fail   = 0; // Consecutive failures (Google rate-limiting / down).

		// Free pass first: reuse existing translations of the same text and
		// glossary terms, and collapse duplicate texts into one request each.
		$pending = self::reuse_pass( $rows, $lang, $count );

		foreach ( $pending as $text => $ids ) {
			$tr = GoogleFree::translate_html( $text, $source, $lang );
			if ( false !== $tr && '' !== trim( (string) $tr ) ) {
				self::save_group( $ids, $lang, (string) $tr, 'google-free', $text, $count );
				$fail = 0;
			} elseif ( ++$fail >= 5 ) {
				break;
			}
		}

		if ( $fail >= 5 ) {
			Logger::warning(
				'google',
				/* translators: %s: language code. */
				sprintf( __( 'Google Translate stopped responding while translating into %s.', 'translate-rocket' ), $lang )
			);
		}

		return array(
			'ok'    => ( $fail < 5 ),
			'count' => $count,
			'error' => ( $fail >= 5 )
				? __( 'Google Translate stopped responding (it may be rate-limiting). Some strings were translated — try again in a moment for the rest.', 'translate-rocket' )
				: '',
		);
	}

	/**
	 * Translate the strings still missing for a language.
	 *
	 * @param string $lang     Target language.
	 * @param string $url_hash Limit to one page (empty = whole site).
	 * @param int    $batch    Strings per API call.
	 * @return array{ok:bool,count:int,error:string}
	 */
	public static function translate_missing( string $lang, string $url_hash = '', int $batch = 0 ): array {
		// The whole configured fallback chain, in priority order. If the first
		// provider runs out of credit (or its key is rejected) mid-job, the next
		// one takes over — a big translation can span several accounts.
		$providers = Registry::ordered();
		if ( empty( $providers ) ) {
			return array(
				'ok'    => false,
				'count' => 0,
				'error' => __( 'No AI provider is configured. Add an API key first.', 'translate-rocket' ),
			);
		}

		// Use the smallest preferred batch size in the chain, so every provider in
		// it (LLMs included) gets an array it can handle reliably.
		if ( $batch <= 0 ) {
			$batch = min( array_map( static fn( $p ) => $p->batch_size(), $providers ) );
		}

		$rows = ( '' !== $url_hash )
			? Strings::untranslated_for_page_language( $url_hash, $lang )
			: Strings::untranslated_for_language( $lang, 1000 );

		// Never send user-excluded strings to the AI.
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return ! NoTranslate::text_excluded( (string) $row->original );
				}
			)
		);

		if ( empty( $rows ) ) {
			return array(
				'ok'    => true,
				'count' => 0,
				'error' => '',
			);
		}

		if ( AiUsage::over_limit() ) {
			return array(
				'ok'    => false,
				'count' => 0,
				'error' => __( 'Daily AI character limit reached. Raise it in AI settings or wait until tomorrow.', 'translate-rocket' ),
			);
		}

		$source = Plugin::instance()->router()->default_language();
		$count  = 0;
		$error  = '';
		$dead   = array(); // provider id => permanent-error message (out of credit / bad key).

		// Free pass first: the same text may already be translated under another
		// type/context (attribute vs text node vs interface string) or on another
		// page, or be forced by the glossary — copy those instead of paying for
		// them, and collapse duplicate texts into a single API item each.
		$pending = self::reuse_pass( $rows, $lang, $count );

		foreach ( array_chunk( array_keys( $pending ), max( 1, $batch ) ) as $texts ) {
			if ( AiUsage::over_limit() ) {
				$error = __( 'Daily AI character limit reached.', 'translate-rocket' );
				break;
			}

			$hit = self::first_ok( $providers, $dead, $texts, $source, $lang, $error );
			if ( null !== $hit ) {
				foreach ( $hit['translations'] as $i => $translation ) {
					$translation = (string) $translation;
					if ( isset( $texts[ $i ] ) && '' !== trim( $translation ) ) {
						self::save_group( $pending[ $texts[ $i ] ], $lang, $translation, $hit['provider'], $texts[ $i ], $count );
					}
				}
				AiUsage::add( array_sum( array_map( 'mb_strlen', $texts ) ) );
				continue;
			}

			// No provider handled the batch as a whole (LLMs occasionally drop or
			// merge an item). Retry one string at a time across the chain, so a
			// single bad response never loses the batch.
			foreach ( $texts as $text ) {
				$one = self::first_ok( $providers, $dead, array( $text ), $source, $lang, $error );
				if ( null !== $one && isset( $one['translations'][0] ) && '' !== trim( (string) $one['translations'][0] ) ) {
					self::save_group( $pending[ $text ], $lang, (string) $one['translations'][0], $one['provider'], $text, $count );
					AiUsage::add( mb_strlen( $text ) );
				}
			}

			// Every provider is out of credit / rejected: stop, nothing left to try.
			if ( count( $dead ) >= count( $providers ) ) {
				break;
			}
		}

		if ( '' !== $error && 0 === $count ) {
			Logger::error(
				'ai',
				/* translators: %s: language code. */
				sprintf( __( 'AI translation failed (→ %s).', 'translate-rocket' ), $lang ),
				$error
			);
		}

		return array(
			'ok'    => ( count( $dead ) < count( $providers ) ),
			'count' => $count,
			'error' => $error,
		);
	}

	/**
	 * Try each live provider in the chain until one returns a well-formed batch.
	 * A provider that reports a permanent failure (out of credit, invalid key) is
	 * marked dead so the rest of the run skips it; transient failures just fall
	 * through to the next provider.
	 *
	 * @param ProviderInterface[]  $providers Ordered chain.
	 * @param array<string,string> $dead      Dead provider ids (by ref).
	 * @param string[]             $texts     Texts to translate.
	 * @param string               $error     Last error message (by ref).
	 * @return array{translations:string[],provider:string}|null
	 */
	private static function first_ok( array $providers, array &$dead, array $texts, string $source, string $target, string &$error ): ?array {
		foreach ( $providers as $provider ) {
			if ( isset( $dead[ $provider->id() ] ) ) {
				continue;
			}
			$result = $provider->translate( $texts, $source, $target );
			if ( $result->success && count( $result->translations ) === count( $texts ) ) {
				$error = '';
				return array(
					'translations' => $result->translations,
					'provider'     => $provider->id(),
				);
			}
			$error = $result->error;
			if ( self::is_permanent( $result->error ) ) {
				$dead[ $provider->id() ] = $result->error;
				// Surface the outage in wp-admin: a quota that runs out mid-run is
				// otherwise invisible (the fallback silently takes over). Skip if
				// the admin already dismissed this provider's notice, so Dismiss
				// stays dismissed instead of returning on the next run.
				if ( ! get_transient( 'trrocket_provider_down_ack_' . $provider->id() ) ) {
					set_transient( 'trrocket_provider_down_' . $provider->id(), $result->error, 12 * HOUR_IN_SECONDS );
				}
				Logger::warning(
					'ai',
					/* translators: 1: provider name, 2: error. */
					sprintf( __( '%1$s is unavailable (%2$s) — falling back to the next provider.', 'translate-rocket' ), $provider->label(), $result->error )
				);
			}
		}
		return null;
	}

	/**
	 * Whether an error means the provider can't be used for the rest of this run
	 * (exhausted quota / billing, or a rejected key) rather than a passing glitch.
	 */
	private static function is_permanent( string $error ): bool {
		// A plain HTTP 429 can be a passing rate limit (already retried in the
		// provider), so it's NOT listed here — only quota/billing/auth errors,
		// which won't fix themselves during this run, retire a provider.
		$needles = array( 'insufficient_quota', 'quota', 'billing', 'credit', 'exceeded', 'payment', 'unauthorized', 'invalid_api_key', 'invalid api key', 'forbidden', 'http 401', 'http 403' );
		$haystack = strtolower( $error );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The free pass shared by both providers: rows whose text already has a
	 * translation somewhere (another string row of any type/context, or the
	 * glossary) are stored as reuse — no API call, savings recorded. The rest
	 * come back grouped by unique text, so duplicates cost one API item.
	 *
	 * @param array<int,object> $rows  Untranslated rows (string_id, original).
	 * @param string            $lang  Target language.
	 * @param int               $count Running translated-strings counter (by ref).
	 * @return array<string,int[]> text => string ids still needing the API.
	 */
	private static function reuse_pass( array $rows, string $lang, int &$count ): array {
		$texts = array();
		foreach ( $rows as $row ) {
			$texts[] = (string) $row->original;
		}
		// These rows have no translation under their own string id, so any hit
		// here comes from a different row with the same text — or the glossary.
		$existing = Strings::translate_texts( $texts, $lang );

		$pending = array();
		foreach ( $rows as $row ) {
			$text = trim( (string) $row->original );
			if ( '' === $text ) {
				continue;
			}
			if ( isset( $existing[ $text ] ) ) {
				Strings::save_translation( (int) $row->string_id, $lang, (string) $existing[ $text ], 1, 'reuse' );
				Savings::add( mb_strlen( $text ) );
				++$count;
				continue;
			}
			$pending[ $text ][] = (int) $row->string_id;
		}
		return $pending;
	}

	/**
	 * The strings a browser-side engine still has to translate for a language.
	 *
	 * This is the first half of translate_missing(), reused as it stands: the
	 * same row selection, the same exclusions, and the same free reuse pass. So
	 * a text already known from another page, or forced by the glossary, is
	 * copied here and never reaches the browser at all. What comes back is only
	 * what genuinely needs translating, grouped by text — a phrase repeated on
	 * twenty pages is translated once and stored twenty times.
	 *
	 * @param string $lang     Target language code.
	 * @param string $url_hash Limit to one page, or '' for the whole site.
	 * @param int    $limit    How many source rows to consider in one pass.
	 * @return array{reused:int,items:array<int,array{text:string,ids:int[]}>}
	 */
	public static function pending_for_browser( string $lang, string $url_hash = '', int $limit = 400 ): array {
		$rows = ( '' !== $url_hash )
			? Strings::untranslated_for_page_language( $url_hash, $lang )
			: Strings::untranslated_for_language( $lang, $limit );

		// Never hand user-excluded strings to any engine.
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return ! NoTranslate::text_excluded( (string) $row->original );
				}
			)
		);

		$reused  = 0;
		$pending = self::reuse_pass( $rows, $lang, $reused );

		$items = array();
		foreach ( $pending as $text => $ids ) {
			$items[] = array(
				'text' => (string) $text,
				'ids'  => array_map( 'intval', $ids ),
			);
		}

		return array(
			'reused' => $reused,
			'items'  => $items,
		);
	}

	/**
	 * Store translations produced outside PHP — in the administrator's own
	 * browser, by the translator built into Chrome and Edge.
	 *
	 * The ids come back from the browser, so they are not trusted: each one is
	 * checked against the text it claims to belong to before anything is
	 * written. A pair that does not match is dropped rather than allowed to
	 * overwrite a good translation.
	 *
	 * @param string                                                    $lang  Target language.
	 * @param array<int,array{text:string,ids:int[],translation:string}> $items Translated groups.
	 * @return array{saved:int,skipped:int}
	 */
	public static function store_browser( string $lang, array $items ): array {
		$ids = array();
		foreach ( $items as $item ) {
			foreach ( (array) ( $item['ids'] ?? array() ) as $id ) {
				$ids[] = (int) $id;
			}
		}
		$originals = Strings::originals_by_id( $ids );

		$saved   = 0;
		$skipped = 0;
		foreach ( $items as $item ) {
			$text        = trim( (string) ( $item['text'] ?? '' ) );
			$translation = trim( (string) ( $item['translation'] ?? '' ) );
			$group       = array();
			foreach ( (array) ( $item['ids'] ?? array() ) as $id ) {
				$id = (int) $id;
				if ( isset( $originals[ $id ] ) && trim( $originals[ $id ] ) === $text ) {
					$group[] = $id;
				} else {
					++$skipped;
				}
			}
			if ( '' === $text || '' === $translation || empty( $group ) ) {
				continue;
			}
			self::save_group( $group, $lang, $translation, 'browser', $text, $saved );
		}

		return array(
			'saved'   => $saved,
			'skipped' => $skipped,
		);
	}

	/**
	 * Store one translated text on every string row that carries that text. The
	 * first row is the one the API call paid for; the others are free copies and
	 * count as savings.
	 *
	 * @param int[]  $ids   String ids sharing the text.
	 * @param string $lang  Target language.
	 * @param string $translation Translated text.
	 * @param string $provider    Provider id for the paid row.
	 * @param string $text  Source text (for savings length).
	 * @param int    $count Running translated-strings counter (by ref).
	 */
	private static function save_group( array $ids, string $lang, string $translation, string $provider, string $text, int &$count ): void {
		foreach ( $ids as $i => $id ) {
			Strings::save_translation( (int) $id, $lang, $translation, 1, 0 === $i ? $provider : 'reuse' );
			++$count;
			if ( $i > 0 ) {
				Savings::add( mb_strlen( $text ) );
			}
		}
	}
}
