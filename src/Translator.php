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

		$letti = count( $rows ); // before the excluded ones leave: «is there more?» is asked of what was read
		$rows  = self::keep_excluded( $rows, $lang );
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
			'more'  => $fail < 5 && ( $fermato || ( '' === $url_hash && $letti >= 500 && $count > 0 ) ),
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

	/**
	 * 1.8.48 (16/11/2026, Matjaž: «it needs like 40 passes to finish… and it still leaves untranslated text»): the
	 * sentences excluded in «Words & phrases» are never translated, so they stayed «missing» for ever. They came back at
	 * the top of every block of 1,000 read, the block looked short and «Translate all» stopped as if it had finished;
	 * and the language never reached 100%. Now they are saved as kept (the original is their translation, as the page
	 * shows it) and leave the list; the rest is returned.
	 *
	 * @param array<int,object> $rows Rows with string_id and original.
	 * @param string            $lang Language.
	 * @return array<int,object>
	 */
	private static function keep_excluded( array $rows, string $lang ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( NoTranslate::text_excluded( (string) $row->original ) ) {
				Strings::save_translation( (int) $row->string_id, $lang, (string) $row->original, 1, Strings::KEPT );
				continue;
			}
			$out[] = $row;
		}
		return $out;
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
	 * 1.8.51: an engine said «request too large»; the size to go on with (0 = none said so).
	 *
	 * @var int
	 */
	private static $too_big = 0;

	/**
	 * 26/11/2026 (ticket #39): the last first_ok() met a request that timed out.
	 *
	 * @var bool
	 */
	private static $timed_out = false;

	/**
	 * 26/11/2026 (ticket #39): an engine was set to rest because its limit per minute said how long to wait.
	 *
	 * @var bool
	 */
	private static $rate_rest = false;

	/**
	 * 1.8.51: what each engine that failed in this run said (provider id => message), for the log: the line used
	 * to carry the last engine's words only, and why the first three had stopped was nowhere.
	 *
	 * @var array<string,string>
	 */
	private static $whys = array();

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
		// 1.8.38 (10/11/2026, 42,000 sentences in 25 languages, one tab open: «Another translation is running on this
		// site», almost still): a step killed by the server's time limit never reaches the «finally» that gives the
		// lock back, and the site stayed busy for 150 s after each one. PHP still runs the shutdown functions then.
		if ( ! self::$lock_guard ) {
			self::$lock_guard = true;
			register_shutdown_function( array( __CLASS__, 'unlock_on_shutdown' ) );
		}
		return true;
	}

	/** @var bool The shutdown guard of the lock is registered (once per request). */
	private static $lock_guard = false;

	/**
	 * The request ends with the lock still taken (time limit, fatal error): give it back.
	 */
	public static function unlock_on_shutdown(): void {
		if ( self::$locks > 0 ) {
			self::$locks = 0;
			delete_option( 'trrocket_translating' );
		}
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
			// d998 (26/11/2026, nl site, 76 languages, Safari): MyMemory's free day over said «the engine you chose is not
			// ready: check it on the AI Translation screen» — a key problem for someone who has no key. Say what it is.
			if ( empty( $providers ) && \TranslateRocket\Providers\MyMemoryProvider::ID === $solo ) {
				return array(
					'ok'    => false,
					'count' => 0,
					'error' => __( 'MyMemory\'s free daily limit is reached. The rest is translated when you press the button again tomorrow — or now, in Chrome or Edge on a computer, or with a free AI key.', 'translate-rocket' ),
				);
			}
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
		// 1.8.51 (19/11/2026, razis-oldbooks.gr: free Groq, 20 book descriptions in one request = 9,450 tokens against
		// 8,000 a minute → HTTP 413 «Request too large»): an engine that said «too large» a while ago gets the size it
		// accepted, from the first request of this step.
		foreach ( $providers as $p ) {
			$sta = (int) get_transient( 'trrocket_fit_' . $p->id() );
			if ( $sta > 0 ) {
				$batch = max( 1, min( $batch, $sta ) );
			}
		}
		self::$too_big = 0;
		self::$whys    = array();
		// 26/11/2026 (ticket #39, PHP 7.4 site translating French into 8 languages with its own OpenAI key: «Translate all»
		// stuck at 20 % and then «the connection dropped»; reproduced on the bench): the size of a request was counted in
		// sentences only. Twenty long paragraphs are some 11,000 characters: the AI needs more than the 60 s the request
		// waits, cURL gives up (error 28), the request was tried three times (180 s) and the host cut the step (504) —
		// every step again on the same twenty paragraphs, so the bar never moved. An AI request now also carries at most
		// this many characters, and a request that timed out is asked again in halves (first_ok(), $too_big).
		$budget = 0;
		foreach ( $providers as $p ) {
			if ( $p instanceof \TranslateRocket\Providers\LlmProvider ) {
				/**
				 * Most characters sent to an AI in one request (0 = no limit).
				 *
				 * @param int $chars Characters.
				 */
				$budget = max( 0, (int) apply_filters( 'trrocket_ai_request_chars', 4000 ) );
				break;
			}
		}
		$fit_chars = (int) get_transient( 'trrocket_fit_chars' );
		if ( $budget > 0 && $fit_chars > 0 ) {
			$budget = min( $budget, $fit_chars );
		}

		// «Improve with AI»: not the missing sentences but the ones the browser translated.
		if ( $migliora ) {
			$rows = Strings::browser_rows( $lang, 1000 );
		} else {
			$rows = ( '' !== $url_hash )
				? Strings::untranslated_for_page_language( $url_hash, $lang )
				: Strings::untranslated_for_language( $lang, 1000 );
		}

		$letti = count( $rows ); // before the excluded ones leave: «is there more?» is asked of what was read
		if ( $migliora ) {
			// «Improve with AI» reads sentences that have a translation already: the excluded ones are only left out.
			$rows = array_values( array_filter( $rows, static fn( $row ) => ! NoTranslate::text_excluded( (string) $row->original ) ) );
		} else {
			$rows = self::keep_excluded( $rows, $lang );
			// A whole block of excluded sentences (a long «Words & phrases» list): read on, they have left the list now.
			for ( $giro = 0; $giro < 20 && empty( $rows ) && '' === $url_hash && $letti >= 1000; $giro++ ) {
				$rows  = Strings::untranslated_for_language( $lang, 1000 );
				$letti = count( $rows );
				$rows  = self::keep_excluded( $rows, $lang );
			}
		}

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
		self::$broke     = false; // a real failure in this run (not a rest): then it is logged as a failure, as before.
		self::$rate_rest = false;

		// Free pass first: the same text may already be translated under another
		// type/context (attribute vs text node vs interface string) or on another
		// page, or be forced by the glossary — copy those instead of paying for
		// them, and collapse duplicate texts into a single API item each.
		// Improving browser translations: never copy a browser translation back.
		$pending = self::reuse_pass( $rows, $lang, $count, $migliora ? ( 'none' === self::reuse_mode() ? 'none' : 'no_browser' ) : self::reuse_mode() );

		// 5/11/2026 (lungomare.ee: «lungomare.ee», the site's name, the last sentence «to translate» in 13 languages): a text
		// that is only an address — a domain, a web address, an e-mail — is the same in every language. Kept as it is,
		// never sent to the engine.
		foreach ( $pending as $text => $ids ) {
			if ( self::only_address( (string) $text ) || self::only_code( (string) $text ) ) {
				foreach ( (array) $ids as $id ) {
					if ( Strings::save_translation( (int) $id, $lang, (string) $text, 1, 'kept' ) ) {
						++$kept;
					}
				}
				unset( $pending[ $text ] );
			}
		}

		// 7/11/2026 (piflarnakupuje.top, Matjaž: Blocksy's compare table): a text already translated in pieces on one page
		// («Paket:» / «1X RC avtomobil» / «1X akumulator», one paragraph each) shown elsewhere flattened into one line.
		// The pieces' translations are joined, nothing goes to the engine.
		$fermato = false;
		$ridotto = false; // 26/11/2026 (ticket #39): a request was sent back to the queue, smaller
		$coda    = array_keys( $pending ); // a queue, not fixed chunks: the size can shrink on the way (1.8.51)
		while ( ! empty( $coda ) ) {
			$texts = array_splice( $coda, 0, max( 1, $batch ) );
			if ( $budget > 0 ) {
				// 26/11/2026 (ticket #39): no more than $budget characters in one AI request; the rest goes back in the queue.
				$somma = 0;
				foreach ( $texts as $k => $t ) {
					$somma += mb_strlen( (string) $t );
					if ( $k > 0 && $somma > $budget ) {
						$coda  = array_merge( array_slice( $texts, $k ), $coda );
						$texts = array_slice( $texts, 0, $k );
						break;
					}
				}
			}
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				$fermato = true;
				break;
			}
			if ( AiUsage::over_limit() ) {
				$error = __( 'Daily AI character limit reached.', 'translate-rocket' );
				break;
			}
			// 10/11/2026 (Matjaž: «why are the translations so slow in the last updates?»): the pieces were looked up for
			// every one of the 1,000 sentences read, at each step, though a step sends a few hundred: a thousand queries for
			// nothing, 20 % fewer sentences a minute. Now only for the sentences about to be sent.
			foreach ( $texts as $k => $text ) {
				$joined = self::from_pieces( (string) $text, $lang );
				if ( null !== $joined ) {
					self::save_group( $pending[ $text ], $lang, $joined, 'reuse', (string) $text, $count );
					unset( $texts[ $k ] );
				}
			}
			$texts = array_values( $texts );
			if ( empty( $texts ) ) {
				continue;
			}

			$hit = self::first_ok( $providers, $dead, $texts, $source, $lang, $error, $resting );
			// 1.8.51: an engine answered «request too large» for this many sentences. From here on the requests are
			// half the size; and when no other engine took the batch, the same sentences are asked again, smaller —
			// not one by one (slow, and every step again), and the engine is not given up for lost.
			if ( self::$too_big > 0 ) {
				$nuovo         = max( 1, min( $batch, self::$too_big ) );
				self::$too_big = 0;
				if ( self::$timed_out && count( $texts ) > 1 ) {
					// 26/11/2026 (ticket #39): the AI did not answer in time — half the characters too, remembered for 6 hours.
					$meta_chars = max( 500, (int) ceil( array_sum( array_map( 'mb_strlen', $texts ) ) / 2 ) );
					$budget     = $budget > 0 ? min( $budget, $meta_chars ) : $meta_chars;
					set_transient( 'trrocket_fit_chars', $budget, 6 * HOUR_IN_SECONDS );
				}
				if ( $nuovo < $batch ) {
					$batch = $nuovo;
					if ( null === $hit ) {
						$coda    = array_merge( $texts, $coda );
						$ridotto = true;
						continue;
					}
				}
			}
			if ( null !== $hit ) {
				$vuote = array();
				foreach ( $hit['translations'] as $i => $translation ) {
					$translation = (string) $translation;
					if ( isset( $texts[ $i ] ) && '' !== trim( $translation ) ) {
						// Le differenze innocue (<1></1> al posto di <1/>) si rimettono in riga
						// prima di decidere: altrimenti si butterebbe via una traduzione buona.
						$translation = \TranslateRocket\Frontend\InlineText::normalize( $texts[ $i ], $translation );
					}
					if ( isset( $texts[ $i ] ) && self::is_echo( $texts[ $i ], $translation, $source, $lang ) ) {
						$vuote[] = $texts[ $i ]; // the engine sent the sentence back unchanged: asked again on its own, then the others
					} elseif ( isset( $texts[ $i ] ) && '' !== trim( $translation ) && self::parts_kept( $texts[ $i ], $translation ) ) {
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
					$prima = $count + $kept;
					$one   = null;
					if ( $deadline > 0 && microtime( true ) >= $deadline ) {
						// 27/10/2026 (real tour on Cloudways: «Translate all» ended with 2 sentences left and no word):
						// the step ran out of time here, so there is more to do — the next step picks them up.
						$fermato = true;
						break;
					}
					$one = self::first_ok( $providers, $dead, array( $text ), $source, $lang, $error, $resting );
					$uno = ( null !== $one && isset( $one['translations'][0] ) ) ? trim( (string) $one['translations'][0] ) : '';
					if ( '' !== $uno && self::is_echo( $text, $uno, $source, $lang ) ) {
						// 26/10/2026 (translaterocket.com: Groq gave back English notes unchanged and they were saved as Japanese):
						// the other engines of the chain, one by one; if none changes it, the sentence stays to be translated —
						// never an English sentence counted as translated.
						$altro = null;
						foreach ( $providers as $pv ) {
							if ( $pv->id() === $one['provider'] || isset( $dead[ $pv->id() ] ) || isset( $resting[ $pv->id() ] ) ) {
								continue;
							}
							$r2 = $pv->translate( array( $text ), $source, $lang );
							$t2 = ( $r2->success && isset( $r2->translations[0] ) ) ? trim( (string) $r2->translations[0] ) : '';
							if ( '' !== $t2 && ! self::is_echo( $text, $t2, $source, $lang ) && self::parts_kept( $text, $t2 ) ) {
								$altro = array( $t2, $pv->id() );
								break;
							}
						}
						if ( $altro ) {
							self::save_group( $pending[ $text ], $lang, $altro[0], $altro[1], $text, $count );
							AiUsage::add( mb_strlen( $text ) );
						} elseif ( ! self::looks_like( $text, $source ) ) {
							// 3/11/2026 (lungomare.ee, «99%» in every language): guests' reviews in German, Italian, French on an
							// English site. Asked into its own language, every engine gives a review back as it is — rightly — and
							// it stayed «to translate» for ever. Not written in the site's language (almost none of its common
							// words) and unchanged by the whole chain: it is already in the right language, kept as it is.
							foreach ( $pending[ $text ] as $id ) {
								if ( Strings::save_translation( (int) $id, $lang, $text, 1, 'kept' ) ) {
									++$kept;
								}
							}
						}
					} elseif ( '' !== $uno && self::parts_kept( $text, $uno ) ) {
						self::save_group( $pending[ $text ], $lang, $uno, $one['provider'], $text, $count );
						AiUsage::add( mb_strlen( $text ) );
					} elseif ( '' !== $uno && null !== $one ) {
						// 3/11/2026 (lungomare.ee, Brizy: «…and the town<1>.</1>This provides…», a bold full stop between two
						// sentences): the engine joined the sentences and dropped the mark, every time, in every language — one
						// sentence «to translate» for ever, «99%». As the browser engine already does: the pieces between the
						// marks one by one, the marks left where they are.
						$pezzi = self::by_pieces( $providers, $dead, $text, $source, $lang, $error, $resting );
						if ( null !== $pezzi && self::parts_kept( $text, $pezzi[0] ) ) {
							self::save_group( $pending[ $text ], $lang, $pezzi[0], $pezzi[1], $text, $count );
							AiUsage::add( mb_strlen( $text ) );
						}
					} elseif ( null !== $one && '' === $uno ) {
						// 24/10/2026: only when the engine really gave nothing back. A translation that lost the marks
						// of its links stays pending (parts_kept: «tried again next time»): kept, it was an English
						// sentence counted as translated for ever (≈100 on translaterocket.com, zh/ko/ja/hi).
						foreach ( $pending[ $text ] as $id ) {
							if ( Strings::save_translation( (int) $id, $lang, $text, 1, 'kept' ) ) {
								++$kept;
							}
						}
					}
					// 7/11/2026 (1.8.34, «100% guaranteed»): an engine answered but the sentence is still to translate (sent
					// back unchanged, marks lost). After the third such try it is kept as it is and counted, with «translate
					// again» in the panel. A failed engine (quota, network) never counts as a try.
					if ( null !== $one && $count + $kept === $prima && self::tried( $text, $lang ) >= 3 ) {
						foreach ( $pending[ $text ] as $id ) {
							if ( Strings::save_translation( (int) $id, $lang, $text, 1, 'kept' ) ) {
								++$kept;
							}
						}
						self::tried( $text, $lang, true );
					}
				}
				continue;
			}

			// No provider handled the batch as a whole (LLMs occasionally drop or
			// merge an item). Retry one string at a time across the chain, so a
			// single bad response never loses the batch.
			foreach ( $texts as $text ) {
				// 26/11/2026 (ticket #39): one sentence at a time had no clock — after a request that timed out, twenty
				// more of up to 60 s each, far beyond what the host gives a step. At the deadline the rest waits for the
				// next step; a single sentence that just timed out is not sent again at once.
				if ( ( $deadline > 0 && microtime( true ) >= $deadline ) || ( 1 === count( $texts ) && self::$timed_out ) ) {
					$fermato = $fermato || ( $deadline > 0 && microtime( true ) >= $deadline );
					break;
				}
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
		} elseif ( '' !== $error && 0 === $count && ! ( $ridotto && $fermato ) ) {
			Logger::error(
				'ai',
				/* translators: %s: language code. */
				sprintf( __( 'AI translation failed (→ %s).', 'translate-rocket' ), $lang ),
				// 1.8.51: every engine's reason, in the order of the chain — not the last one's only.
				count( self::$whys + array_intersect_key( self::$resting_why, $resting ) ) > 1 ? implode( ' · ', array_unique( array_map( 'strval', array_values( self::$whys + array_intersect_key( self::$resting_why, $resting ) ) ) ) ) : $error
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
			// 1.8.48 (16/11/2026, Matjaž: «40 passes»): counted on the rows read, not on the ones left after the exclusions.
			'more'  => $count > 0 && count( $dead ) < count( $providers ) && ( $fermato || $letti >= 1000 ),
			// 26/11/2026 (ticket #39): the whole step went on one request that timed out (or was too large), now queued
			// again in halves: nothing translated yet, but nothing broken — the page comes back at once (Admin: «wait»).
			'shrunk' => $ridotto && $fermato && 0 === $count && count( $dead ) < count( $providers ),
			// 26/11/2026 (ticket #39): the rest came from a limit per minute that said how long (not a free engine's day).
			'rate_rest' => $riprende > 0 && self::$rate_rest,
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
		self::$timed_out = false;
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
			self::$whys[ $provider->id() ] = (string) $result->error;
			// 1.8.51 (19/11/2026, razis-oldbooks.gr): «HTTP 413: Request too large … please reduce your message size and
			// try again. Need more tokens? Upgrade … /settings/billing» is not an empty wallet («billing» made it one, and
			// Groq was «unavailable» for twelve hours): the request was too big. The run goes on with smaller ones.
			// 26/11/2026 (ticket #39, on the bench): OpenAI's limit per minute («Rate limit reached … Please try again in
			// 20s») outlasted the three quick tries, and with nothing translated the run stopped with «too many requests»
			// for the owner to press again. When the engine says how long, it rests that long and the page waits for it.
			$dopo = self::retry_after( (string) $result->error );
			if ( $dopo > 0 ) {
				$resting[ $provider->id() ]           = time() + $dopo;
				self::$resting_why[ $provider->id() ] = $result->error;
				self::$rate_rest                      = true;
				if ( '' === $error ) {
					$error = $result->error;
				}
				continue;
			}
			// 26/11/2026 (ticket #39): a request the AI did not answer in time (cURL error 28) is a request too big for the
			// wait, not a broken engine: asked again in halves, like «too large».
			$tardi = self::is_timeout( (string) $result->error );
			if ( $tardi ) {
				self::$timed_out = true;
			}
			if ( $tardi || self::is_too_large( (string) $result->error ) ) {
				$error = $result->error;
				if ( count( $texts ) > 1 ) {
					$meta          = (int) ceil( count( $texts ) / 2 );
					self::$too_big = self::$too_big > 0 ? min( self::$too_big, $meta ) : $meta;
					set_transient( 'trrocket_fit_' . $provider->id(), $meta, 6 * HOUR_IN_SECONDS );
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
	 * 26/11/2026 (ticket #39): the engine did not answer within the wait of the request (cURL error 28, or the 524 of
	 * Cloudflare in front of it). A smaller request is answered sooner.
	 */
	private static function is_timeout( string $error ): bool {
		return (bool) preg_match( '/curl error 28|operation timed out|timed out after|http 524/i', $error );
	}

	/**
	 * 26/11/2026 (ticket #39): seconds an engine's limit per minute asks to wait («try again in 20s», «in 1m30s»,
	 * «in 450ms»), 0 when it is not a rate limit, says nothing, or asks for more than 3 minutes.
	 */
	private static function retry_after( string $error ): int {
		if ( false !== stripos( $error, 'insufficient_quota' ) || ! preg_match( '/rate[ _-]?limit|too many requests/i', $error ) ) {
			return 0;
		}
		if ( ! preg_match( '/try again in\s+(?:(\d+)m)?\s*(\d+(?:\.\d+)?)(ms|s)\b/i', $error, $m ) ) {
			return 0;
		}
		$s = (float) $m[2] / ( 'ms' === strtolower( $m[3] ) ? 1000 : 1 ) + 60 * (int) $m[1];
		return ( $s > 0 && $s <= 180 ) ? (int) ceil( $s ) : 0;
	}

	/**
	 * 1.8.51: the engine refused the request for its size (too many sentences, or too long, for its limit per
	 * minute or its context): smaller requests pass.
	 */
	private static function is_too_large( string $error ): bool {
		return (bool) preg_match( '/http 413|request too large|payload too large|reduce your message size|reduce the length of|maximum context length|context_length_exceeded|context window/i', $error );
	}

	/**
	 * Whether an error means the provider can't be used for the rest of this run
	 * (exhausted quota / billing, or a rejected key) rather than a passing glitch.
	 */
	private static function is_permanent( string $error ): bool {
		if ( self::is_too_large( $error ) ) {
			return false; // the request was too big, the account is fine (1.8.51)
		}
		// A plain HTTP 429 can be a passing rate limit (already retried in the
		// provider), so it's NOT listed here — only quota/billing/auth errors,
		// which won't fix themselves during this run, retire a provider.
		$haystack = strtolower( $error );
		// 25/10/2026 (sito di prova su Cloudways, Groq gratuito vero: «Translate all» fermo a metà, Groq escluso per il
		// resto del lavoro): Groq's per-minute limit reads «Rate limit reached … Please try again in 1.1s. Need more tokens?
		// Upgrade to Dev Tier today at …/settings/billing» with code «rate_limit_exceeded» — «billing» and «exceeded» made
		// a one-second pause look like an empty wallet. A rate limit passes by itself; only a spent quota retires an engine.
		if ( false === strpos( $haystack, 'insufficient_quota' ) && preg_match( '/rate[ _-]?limit|try again in|too many requests/', $haystack ) ) {
			return false;
		}
		$needles = array( 'insufficient_quota', 'quota', 'billing', 'credit', 'exceeded', 'payment', 'unauthorized', 'invalid_api_key', 'invalid api key', 'forbidden', 'http 401', 'http 403', 'http 402', 'insufficient balance', 'arrearage' ); // 1.8.45: DeepSeek answers 402 «Insufficient Balance», Alibaba Cloud «Arrearage»
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

		$rows = self::keep_excluded( $rows, $lang ); // never handed to any engine

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
		// 2/11/2026: the maps are flushed once per batch, not once per sentence (60 DELETEs on the options table per
		// batch, every 40 s for a day on a 277,000-sentence shop); same as one step of «Translate all».
		Strings::defer_start();
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
		Strings::defer_end();

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
	/**
	 * 26/10/2026: a long sentence (a real sentence, not a name or a brand) that came back exactly as it was sent, into
	 * another language: the engine did not translate it. Short texts identical in both languages stay valid.
	 *
	 * @param string $original    Text sent.
	 * @param string $translation Text received.
	 * @param string $source      Source language.
	 * @param string $target      Target language.
	 */
	private static function is_echo( string $original, string $translation, string $source, string $target ): bool {
		if ( '' === trim( $translation ) || strtok( $source, '-' ) === strtok( $target, '-' ) ) {
			return false;
		}
		if ( trim( $original ) !== trim( $translation ) ) {
			return false;
		}
		$parole = preg_split( '/\s+/u', trim( (string) preg_replace( '#</?\d+/?>#', ' ', $original ) ) );
		$parole = array_filter( $parole, static fn( $w ) => preg_match( '/\p{L}{2,}/u', $w ) );
		if ( mb_strlen( $original ) <= 40 || count( $parole ) < 5 ) {
			return false;
		}
		// 27/10/2026 (real tour on Cloudways: «Aisén del General Carlos Ibañez del Campo», a region in WooCommerce's
		// list, stayed «to translate» for ever): a name, most of its words with a capital letter, is the same in
		// every language — the engine sending it back unchanged is right, and it is saved as it is. A name: every word
		// starts with a capital letter, apart from short joining words (del, de, of, von…). «Picnic basket for two –
		// Olive Grove Guest House» is not one: «basket» is a word to translate.
		foreach ( $parole as $w ) {
			if ( ! preg_match( '/^\P{L}*\p{Lu}/u', $w ) && mb_strlen( (string) preg_replace( '/\P{L}+/u', '', $w ) ) > 3 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 3/11/2026: a sentence with marks (links, bold) whose translation keeps losing them, translated piece by piece:
	 * the text between two marks on its own, the marks and the blanks left as they are. One request for all the pieces.
	 * Null when it cannot be done (no engine answered, a piece missing); else [translation, engine].
	 *
	 * @param array  $providers Engines.
	 * @param array  $dead      Engines out of use (by ref).
	 * @param string $text      Sentence with marks.
	 * @param string $source    Source language.
	 * @param string $lang      Target language.
	 * @param string $error     Last error (by ref).
	 * @param array  $resting   Engines resting (by ref).
	 */
	private static function by_pieces( array $providers, array &$dead, string $text, string $source, string $lang, string &$error, array &$resting ): ?array {
		if ( ! \TranslateRocket\Frontend\InlineText::has_parts( $text ) ) {
			return null;
		}
		$bits  = preg_split( '#(</?\d{1,3}/?>)#', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$chi   = array();
		$pezzi = array();
		foreach ( (array) $bits as $k => $bit ) {
			if ( 1 === $k % 2 || ! preg_match( '/\p{L}/u', $bit ) ) {
				continue; // a mark, or nothing to translate (blank, a full stop, a number)
			}
			$chi[]   = $k;
			$pezzi[] = trim( $bit );
		}
		if ( empty( $pezzi ) ) {
			return null;
		}
		$r = self::first_ok( $providers, $dead, $pezzi, $source, $lang, $error, $resting );
		if ( null === $r ) {
			return null;
		}
		foreach ( $chi as $j => $k ) {
			$t = isset( $r['translations'][ $j ] ) ? trim( (string) $r['translations'][ $j ] ) : '';
			if ( '' === $t || \TranslateRocket\Frontend\InlineText::has_parts( $t ) ) {
				return null;
			}
			preg_match( '/^\s*/u', $bits[ $k ], $lead );
			preg_match( '/\s*$/u', $bits[ $k ], $tail );
			$bits[ $k ] = $lead[0] . $t . $tail[0];
		}
		return array( implode( '', $bits ), (string) $r['provider'] );
	}

	/**
	 * 3/11/2026: does a text read like the given language? Only the share of its most common short words (the, and,
	 * of…): enough to tell a guest's review in French from an English sentence. Unknown language: yes (nothing changes).
	 *
	 * @param string $text Text.
	 * @param string $lang Language code.
	 */
	private static function looks_like( string $text, string $lang ): bool {
		$comuni = array(
			'en' => 'the and of to in is are was were with for you your a an on at this that it we our be from by as or not have has will can all',
			'it' => 'il lo la i gli le di da in con per su un una che e è non del della dei delle al alla sono si ci come anche',
			'de' => 'der die das und ist in zu den mit von für auf nicht ein eine es im sich dem des sie wir ihr sind auch',
			'fr' => 'le la les et est de des du un une en à au aux pour dans sur avec que qui ne pas vous nous il elle sont ce',
			'es' => 'el la los las y es de del en un una que con por para no se su al lo como más sus muy',
			'pt' => 'o a os as e é de do da dos das em um uma que com por para não se no na ao mais',
			'nl' => 'de het een en is van in op te met voor niet dat die zijn er ook aan je we bij',
			'ru' => 'и в не на я что с по это как а но из у к за он она мы вы они так',
		);
		$base = strtok( strtolower( $lang ), '-_' );
		if ( ! isset( $comuni[ $base ] ) ) {
			return true;
		}
		$parole = preg_split( '/[^\p{L}]+/u', mb_strtolower( (string) preg_replace( '#</?\d+/?>#', ' ', $text ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $parole ) < 5 ) {
			return true;
		}
		$lista = array_flip( explode( ' ', $comuni[ $base ] ) );
		$si    = 0;
		foreach ( $parole as $w ) {
			if ( isset( $lista[ $w ] ) ) {
				++$si;
			}
		}
		return $si / count( $parole ) >= 0.15; // English prose: ~40 %; a German review: «was», «an» = 10 %
	}

	/**
	 * 5/11/2026: the text is only an address (lungomare.ee, www.example.com, https://example.co.uk/page, an e-mail):
	 * the same in every language. The ending in small letters, so «St.Louis» or «Node.JS» are not taken for one.
	 *
	 * @param string $text Text.
	 */
	/**
	 * 7/11/2026 (1.8.34): the translation of a «flattened» text, from the translations of its pieces — consecutive words
	 * that are each a translated sentence, all found together on one page. Null when it does not split that way.
	 */
	private static function from_pieces( string $text, string $lang ): ?string {
		$words = preg_split( '/\s+/u', trim( $text ) );
		$n     = is_array( $words ) ? count( $words ) : 0;
		if ( $n < 2 || $n > 60 || mb_strlen( $text ) > 1500 ) {
			return null;
		}
		$spans = array();
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i; $j < $n && $j < $i + 30; $j++ ) {
				$piece = implode( ' ', array_slice( $words, $i, $j - $i + 1 ) );
				if ( $piece !== $text ) {
					$spans[ sha1( $piece ) ] = $piece;
				}
			}
		}
		global $wpdb;
		$st    = Database::strings_table();
		$tt    = Database::translations_table();
		$found = array(); // piece => [ translation, string ids ]
		foreach ( array_chunk( array_keys( $spans ), 500 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT st.id, st.original, t.translation FROM {$st} st JOIN {$tt} t ON t.string_id = st.id AND t.language = %s AND t.status > 0 WHERE st.text_hash IN ({$ph})", array_merge( array( $lang ), $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and placeholders built here.
			foreach ( (array) $rows as $r ) {
				$o = trim( (string) $r->original );
				if ( isset( $spans[ sha1( $o ) ] ) && '' !== trim( (string) $r->translation ) ) {
					$found[ $o ][0]   = (string) $r->translation;
					$found[ $o ][1][] = (int) $r->id;
				}
			}
		}
		if ( count( $found ) < 2 ) {
			return null;
		}
		// Fewest pieces covering every word.
		$best = array_fill( 0, $n + 1, null );
		$best[ $n ] = array();
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = min( $n - 1, $i + 29 ); $j >= $i; $j-- ) {
				$piece = implode( ' ', array_slice( $words, $i, $j - $i + 1 ) );
				if ( isset( $found[ $piece ] ) && null !== $best[ $j + 1 ] && ( null === $best[ $i ] || count( $best[ $j + 1 ] ) + 1 < count( $best[ $i ] ) ) ) {
					$best[ $i ] = array_merge( array( $piece ), $best[ $j + 1 ] );
				}
			}
		}
		if ( null === $best[0] || count( $best[0] ) < 2 ) {
			return null;
		}
		// All the pieces on one same page: the text they were split from.
		// 11/11/2026 (Matjaž: «from_pieces function is causing the 500 errors… more than 10 strings per round throws a 500»;
		// measured by collaudo-pezzi-taglia: pieces seen on 20,000 pages each, 1.4 s a sentence, 27 s for a step of 20):
		// every piece's whole list of pages was read, and «Black» or «Cart» are on every page of a shop. Now the pages of
		// the longest piece only, a thousand at most, and one question to the database: the first of them that has every
		// other piece too. Not found there, the sentence goes to the engine.
		$ot     = Database::occurrences_table();
		$pieces = array_values( array_unique( $best[0] ) );
		usort(
			$pieces,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$sql = '';
		foreach ( $pieces as $k => $piece ) {
			$ids = implode( ',', array_map( 'intval', $found[ $piece ][1] ) );
			if ( 0 === $k ) {
				$sql = 'SELECT o0.url_hash FROM (SELECT DISTINCT url_hash FROM ' . $ot . ' WHERE string_id IN (' . $ids . ') LIMIT 1000) o0';
			} else {
				$sql .= ' STRAIGHT_JOIN ' . $ot . ' o' . $k . ' ON o' . $k . '.url_hash = o0.url_hash AND o' . $k . '.string_id IN (' . $ids . ')';
			}
		}
		if ( null === $wpdb->get_var( $sql . ' LIMIT 1' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integers only.
			return null;
		}
		$out = array();
		foreach ( $best[0] as $piece ) {
			$out[] = trim( $found[ $piece ][0] );
		}
		return implode( ' ', $out );
	}

	/**
	 * 7/11/2026 (1.8.34): one more try of an engine that answered without translating; returns the tries so far.
	 * $forget clears the count. Kept in one option (not autoloaded), at most 5,000 sentences.
	 */
	private static function tried( string $text, string $lang, bool $forget = false ): int {
		$all = get_option( 'trrocket_tries', array() );
		$all = is_array( $all ) ? $all : array();
		$k   = md5( $lang . '|' . $text );
		if ( $forget ) {
			unset( $all[ $k ] );
			update_option( 'trrocket_tries', $all, false );
			return 0;
		}
		$all[ $k ] = (int) ( $all[ $k ] ?? 0 ) + 1;
		if ( count( $all ) > 5000 ) {
			$all = array_slice( $all, -5000, null, true );
		}
		update_option( 'trrocket_tries', $all, false );
		return $all[ $k ];
	}

	/**
	 * 7/11/2026 (1.8.34): a piece of code, not a sentence — «{{ $item->price }} => [0x1F]» — known from its shape: few
	 * letters and the signs of code. Kept as it is in every language, never sent to the engine.
	 */
	public static function only_code( string $text ): bool {
		$text = trim( $text );
		$len  = mb_strlen( $text );
		if ( $len < 3 ) {
			return false;
		}
		$letters = (int) preg_match_all( '/\p{L}/u', $text );
		if ( $letters / $len >= 0.4 ) {
			return false;
		}
		$segni = 0;
		foreach ( array( '{', '}', '[', ']', ';', '=', '$', '->', '=>', '()', '/*' ) as $g ) {
			if ( false !== strpos( $text, $g ) ) {
				++$segni;
			}
		}
		return $segni >= 3;
	}

	public static function only_address( string $text ): bool {
		$t = trim( $text );
		if ( '' === $t || mb_strlen( $t ) > 200 || preg_match( '/\s/u', $t ) ) {
			return false;
		}
		if ( is_email( $t ) ) {
			return true;
		}
		return (bool) preg_match( '#^(?:https?://)?(?:www\.)?[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.[a-z]{2,24}(?::\d+)?(?:/\S*)?$#u', $t );
	}

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
