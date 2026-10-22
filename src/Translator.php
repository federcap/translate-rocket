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
	 * @param float  $deadline microtime(true) after which no new sentence starts (0 = none): one request per sentence,
	 *                         a big page would outlast the 60 s of many servers (15/10/2026, time probe).
	 * @return array{ok:bool,count:int,error:string,more:bool}
	 */
	public static function google_missing( string $lang, string $url_hash = '', float $deadline = 0.0 ): array {
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
		$pending = self::reuse_pass( $rows, $lang, $count, self::reuse_mode() );

		$fermato = false;
		foreach ( $pending as $text => $ids ) {
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				$fermato = true;
				break;
			}
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
			'more'  => $fermato && $fail < 5,
		);
	}

	/**
	 * Translate the strings still missing for a language.
	 *
	 * @param string $lang     Target language.
	 * @param string $url_hash Limit to one page (empty = whole site).
	 * @param int    $batch    Strings per API call.
	 * @param string $solo     One provider id chosen by hand on the Translations screen (empty = the chain).
	 * @param float  $deadline microtime(true) after which no new batch starts (0 = none). The caller runs it again
	 *                         while «more» is true: short steps stay under the 100 s of Cloudflare and of many hosts.
	 * @return array{ok:bool,count:int,error:string,more:bool}
	 */
	public static function translate_missing( string $lang, string $url_hash = '', int $batch = 0, bool $migliora = false, string $solo = '', float $deadline = 0.0 ): array {
		// 1.8.14 (ticket #22, a 2-core server: three PHP processes translating at once, load 9.6): one translation at a time
		// on the site. «Translate all» in the browser, Labs in the background, a second tab: whoever finds it busy gets
		// «busy» and comes back in a few seconds. A lock older than 150 s (a process killed) is taken over.
		if ( ! self::lock() ) {
			return array( 'ok' => true, 'count' => 0, 'error' => '', 'more' => true, 'busy' => true );
		}
		// The maps are flushed once at the end of the step, not after every sentence saved.
		Strings::defer_start();
		try {
			return self::translate_missing_now( $lang, $url_hash, $batch, $migliora, $solo, $deadline );
		} finally {
			Strings::defer_end();
			self::unlock();
		}
	}

	/** @var int Nested translate_missing() calls in this request: the lock is taken by the first. */
	private static $locks = 0;

	/**
	 * What each resting engine said, for the one log line (provider id => message).
	 *
	 * @var array<string,string>
	 */
	private static $resting_why = array();

	/**
	 * A real failure (not a rest) happened in this run: an engine that broke, then went resting, is still a failure.
	 *
	 * @var bool
	 */
	private static $broke = false;

	/**
	 * Take the site's translation lock (see translate_missing).
	 */
	private static function lock(): bool {
		if ( self::$locks > 0 ) {
			++self::$locks;
			return true;
		}
		$now = time();
		if ( ! add_option( 'trrocket_translating', $now, '', 'no' ) ) {
			$preso = (int) get_option( 'trrocket_translating', 0 );
			if ( $now - $preso < 150 ) {
				return false;
			}
			update_option( 'trrocket_translating', $now, false );
		}
		self::$locks = 1;
		return true;
	}

	/**
	 * Give the lock back.
	 */
	private static function unlock(): void {
		if ( self::$locks <= 0 ) {
			return;
		}
		if ( --self::$locks === 0 ) {
			delete_option( 'trrocket_translating' );
		}
	}

	/**
	 * translate_missing() itself, inside one deferred flush.
	 *
	 * @return array
	 */
	private static function translate_missing_now( string $lang, string $url_hash = '', int $batch = 0, bool $migliora = false, string $solo = '', float $deadline = 0.0 ): array {
		// The whole configured fallback chain, in priority order. If the first
		// provider runs out of credit (or its key is rejected) mid-job, the next
		// one takes over — a big translation can span several accounts.
		$providers = Registry::ordered();
		if ( '' !== $solo ) {
			$scelto    = Registry::get( $solo );
			$providers = ( $scelto && $scelto->is_configured() ) ? array( $scelto ) : array();
			if ( empty( $providers ) ) {
				return array(
					'ok'    => false,
					'count' => 0,
					'error' => __( 'The engine you chose is not ready: check it on the AI Translation screen, or choose another one.', 'translate-rocket' ),
				);
			}
		}
		if ( empty( $providers ) ) {
			return array(
				'ok'    => false,
				'count' => 0,
				'error' => __( 'No AI provider is configured. Add an API key first.', 'translate-rocket' ),
			);
		}

		// Use the smallest preferred batch size in the chain, so every provider in
		// it (LLMs included) gets an array it can handle reliably.
		// «Sentences per request» in the AI settings, when chosen, wins: a slow or distant provider wants smaller ones.
		if ( $batch <= 0 ) {
			$scelta = (int) ( Settings::get()['ai_batch'] ?? 0 );
			$batch  = $scelta > 0 ? $scelta : min( array_map( static fn( $p ) => $p->batch_size(), $providers ) );
		}

		// «Improve with AI»: not the missing sentences but the ones the browser translated.
		if ( $migliora ) {
			$rows = Strings::browser_rows( $lang, 1000 );
		} else {
			$rows = ( '' !== $url_hash )
				? Strings::untranslated_for_page_language( $url_hash, $lang )
				: Strings::untranslated_for_language( $lang, 1000 );
		}

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
		$kept   = 0; // sentences the engine answered nothing for, kept as they were (16/10/2026)
		$error  = '';
		$dead   = array(); // provider id => permanent-error message (out of credit / bad key).
		$resting = array(); // provider id => unix time it comes back (it did not try: a pause, an hourly share used up).
		self::$broke = false; // a real failure in this run (not a rest): then it is logged as a failure, as before.

		// Free pass first: the same text may already be translated under another
		// type/context (attribute vs text node vs interface string) or on another
		// page, or be forced by the glossary — copy those instead of paying for
		// them, and collapse duplicate texts into a single API item each.
		// Improving browser translations: never copy a browser translation back.
		$pending = self::reuse_pass( $rows, $lang, $count, $migliora ? ( 'none' === self::reuse_mode() ? 'none' : 'no_browser' ) : self::reuse_mode() );

		$fermato = false;
		foreach ( array_chunk( array_keys( $pending ), max( 1, $batch ) ) as $texts ) {
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				$fermato = true;
				break;
			}
			if ( AiUsage::over_limit() ) {
				$error = __( 'Daily AI character limit reached.', 'translate-rocket' );
				break;
			}

			$hit = self::first_ok( $providers, $dead, $texts, $source, $lang, $error, $resting );
			if ( null !== $hit ) {
				$vuote = array();
				foreach ( $hit['translations'] as $i => $translation ) {
					$translation = (string) $translation;
					if ( isset( $texts[ $i ] ) && '' !== trim( $translation ) ) {
						// Le differenze innocue (<1></1> al posto di <1/>) si rimettono in riga
						// prima di decidere: altrimenti si butterebbe via una traduzione buona.
						$translation = \TranslateRocket\Frontend\InlineText::normalize( $texts[ $i ], $translation );
					}
					if ( isset( $texts[ $i ] ) && '' !== trim( $translation ) && self::parts_kept( $texts[ $i ], $translation ) ) {
						self::save_group( $pending[ $texts[ $i ] ], $lang, $translation, $hit['provider'], $texts[ $i ], $count );
					} elseif ( isset( $texts[ $i ] ) ) {
						$vuote[] = $texts[ $i ];
					}
				}
				AiUsage::add( array_sum( array_map( 'mb_strlen', $texts ) ) );
				// 16/10/2026 (Federico: a page stuck at 89 % «looks like the plugin cannot do it»): a sentence the
				// engine answered nothing for — a name, or text not in the site's language, that Labs' engines return
				// unchanged and drop — is asked once more on its own; if it still gives nothing, the sentence is kept
				// as it was and counted as done («kept»): the page is complete, and the list says how many were kept.
				// Only after an engine that answered: a failed batch keeps everything pending.
				foreach ( $vuote as $text ) {
					if ( $deadline > 0 && microtime( true ) >= $deadline ) {
						break;
					}
					$one = self::first_ok( $providers, $dead, array( $text ), $source, $lang, $error, $resting );
					$uno = ( null !== $one && isset( $one['translations'][0] ) ) ? trim( (string) $one['translations'][0] ) : '';
					if ( '' !== $uno && self::parts_kept( $text, $uno ) ) {
						self::save_group( $pending[ $text ], $lang, $uno, $one['provider'], $text, $count );
						AiUsage::add( mb_strlen( $text ) );
					} elseif ( null !== $one ) {
						foreach ( $pending[ $text ] as $id ) {
							if ( Strings::save_translation( (int) $id, $lang, $text, 1, 'kept' ) ) {
								++$kept;
							}
						}
					}
				}
				continue;
			}

			// No provider handled the batch as a whole (LLMs occasionally drop or
			// merge an item). Retry one string at a time across the chain, so a
			// single bad response never loses the batch.
			foreach ( $texts as $text ) {
				$one = self::first_ok( $providers, $dead, array( $text ), $source, $lang, $error, $resting );
				if ( null !== $one && isset( $one['translations'][0] ) && '' !== trim( (string) $one['translations'][0] ) ) {
					self::save_group( $pending[ $text ], $lang, (string) $one['translations'][0], $one['provider'], $text, $count );
					AiUsage::add( mb_strlen( $text ) );
				}
			}

			// Every provider is out of credit / rejected / resting: stop, nothing left to try.
			if ( count( $dead ) + count( array_diff_key( $resting, $dead ) ) >= count( $providers ) ) {
				break;
			}
		}

		// 21/10/2026 (translaterocket.com, sentinel): every engine resting is a wait, not a failure. One line in the
		// log for each pause (not one «failed» per language and per step: 60 a day on a site with nothing broken).
		$tutti_fermi = ! empty( $resting ) && ! self::$broke && count( $dead ) + count( array_diff_key( $resting, $dead ) ) >= count( $providers );
		$riprende    = $tutti_fermi ? (int) min( $resting ) : 0;
		if ( '' !== $error && 0 === $count && $tutti_fermi ) {
			// At most one line an hour: Labs asks the engines one at a time (own keys, then Edge…), each with its own
			// «back at», and a line for each would fill the log again (seen on the bench: 7 lines in a minute).
			if ( (int) get_option( 'trrocket_rest_logged' ) < time() - HOUR_IN_SECONDS ) {
				update_option( 'trrocket_rest_logged', time(), false );
				Logger::info(
					'ai',
					/* translators: %s: time. */
					sprintf( __( 'Translation is waiting: every engine is resting. It goes on by itself after %s.', 'translate-rocket' ), wp_date( 'j M H:i', $riprende ) ),
					implode( ' · ', array_unique( array_map( 'strval', array_intersect_key( self::$resting_why, $resting ) ) ) )
				);
			}
		} elseif ( '' !== $error && 0 === $count ) {
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
			'kept'  => $kept,
			'error' => $error,
			// Unix time the first engine comes back when every engine is resting; 0 otherwise.
			'resting_until' => $riprende,
			// Something is left: the step stopped at its deadline, or the 1000 read were all there was room for.
			'more'  => $count > 0 && count( $dead ) < count( $providers ) && ( $fermato || count( $rows ) >= 1000 ),
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
	private static function first_ok( array $providers, array &$dead, array $texts, string $source, string $target, string &$error, array &$resting = array() ): ?array {
		foreach ( $providers as $provider ) {
			if ( isset( $dead[ $provider->id() ] ) || isset( $resting[ $provider->id() ] ) ) {
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
			// Resting: not asked again in this run (it said when it comes back), and not an error of its own.
			if ( ! empty( $result->resting ) ) {
				$resting[ $provider->id() ]      = (int) $result->resting;
				self::$resting_why[ $provider->id() ] = $result->error;
				if ( '' === $error ) {
					$error = $result->error;
				}
				continue;
			}
			self::$broke = true;
			$error       = $result->error;
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
	 * How much of the translation memory a paid engine may reuse (setting «reuse_mode»).
	 *
	 * all        everything already translated (the cheapest; the default)
	 * no_browser everything except what the browser translator wrote: whoever moves from
	 *            the free Chrome translation to an AI wants the AI quality, and reusing
	 *            the Chrome text for every repeated sentence (menus, footer, buttons)
	 *            would keep the Chrome quality exactly where it shows most
	 * none       nothing: the AI gets every sentence it is asked for
	 *
	 * The browser translator itself always reuses everything: it costs nothing.
	 */
	public static function reuse_mode(): string {
		$m = (string) ( Settings::get()['reuse_mode'] ?? 'all' );
		return in_array( $m, array( 'all', 'no_browser', 'none' ), true ) ? $m : 'all';
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
	private static function reuse_pass( array $rows, string $lang, int &$count, string $modo = 'all' ): array {
		$texts = array();
		foreach ( $rows as $row ) {
			$texts[] = (string) $row->original;
		}
		// These rows have no translation under their own string id, so any hit
		// here comes from a different row with the same text — or the glossary.
		if ( 'none' === $modo ) {
			// «Nessuna»: all'AI va tutto. Il glossario resta: e' una regola, non una traduzione.
			$existing = Strings::glossary_texts( $texts, $lang );
		} elseif ( 'no_browser' === $modo ) {
			/**
			 * Which translations «All except free machine translations» does not reuse.
			 * Plugins that bring free engines add theirs (TranslateRocket Labs does):
			 * their quality is the browser's, not the AI's.
			 *
			 * @param string[] $providers Provider ids.
			 */
			$fuori    = (array) apply_filters( 'trrocket_reuse_skip_providers', array( 'browser', 'reuse' ) );
			$existing = Strings::reusable_texts( $texts, $lang, array_values( array_unique( array_map( 'strval', $fuori ) ) ) );
		} else {
			$existing = Strings::translate_texts( $texts, $lang );
		}

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
	 * A whole sentence travels with numbered placeholders where its links and bold
	 * words are (see InlineText). If a service sends back a sentence that lost one,
	 * that translation can never be put back into the page: better to leave the
	 * string untranslated and try again later than to save something unusable.
	 *
	 * @param string $original    The text that was sent.
	 * @param string $translation What came back.
	 */
	private static function parts_kept( string $original, string $translation ): bool {
		if ( ! \TranslateRocket\Frontend\InlineText::has_parts( $original ) ) {
			return true;
		}
		if ( \TranslateRocket\Frontend\InlineText::parts_ok( $original, $translation ) ) {
			return true;
		}
		Logger::warning( 'translation', __( 'A sentence came back from the translation service without the marks that say where its links go, so it was not saved. It will be translated again next time.', 'translate-rocket' ) );
		return false;
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
