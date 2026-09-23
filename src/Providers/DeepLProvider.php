<?php
/**
 * DeepL translation provider.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

use TranslateRocket\Frontend\InlineText;

defined( 'ABSPATH' ) || exit;

/**
 * DeepL API v2. Free keys (ending in ":fx") use the free endpoint.
 */
class DeepLProvider extends AbstractProvider {

	public function id(): string {
		return 'deepl';
	}

	public function label(): string {
		return 'DeepL';
	}

	public function translate( array $texts, string $source, string $target ): TranslationResult {
		$texts = array_values( $texts );
		if ( empty( $texts ) ) {
			return TranslationResult::ok( array() );
		}
		$key = $this->api_key();
		if ( '' === $key ) {
			return TranslationResult::fail( 'Missing DeepL API key.' );
		}

		$host   = ( ':fx' === substr( $key, -3 ) ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
		$params = array(
			'source_lang' => strtoupper( substr( $source, 0, 2 ) ),
			'target_lang' => $this->deepl_target( $target ),
		);
		// Names from "Words & phrases" inside these sentences: DeepL leaves <keep> alone
		// (tag_handling=xml, ignore_tags=keep). Without such names the request is unchanged.
		// UNA sola regola per tutto il lotto. ⚠️ Prima era: «se ci sono nomi protetti
		// accendo l'XML e proteggo QUELLE frasi». Da quando anche le frasi intere usano i
		// tag, bastava una frase col link perche' l'intera richiesta andasse in XML mentre
		// le ALTRE frasi partivano grezze e tornavano non decodificate: un «Fish & chips»
		// nello stesso lotto arrivava in pagina come «Fish &amp;amp; chips». Adesso: se si
		// va in XML, ogni testo viene escapato una volta e ogni risposta decodificata una
		// volta. Trovato in revisione il 20/9.
		$keep  = KeepTerms::in( $texts );
		$parts = array();
		foreach ( $texts as $text ) {
			if ( InlineText::has_parts( $text ) ) {
				preg_match_all( '#<(\d{1,3})#', $text, $m );
				foreach ( $m[1] as $one ) {
					$parts[ InlineText::tag_name( $one ) ] = true;
				}
			}
		}
		$xml = ! empty( $keep ) || ! empty( $parts );
		if ( $xml ) {
			$params['tag_handling'] = 'xml';
		}
		if ( ! empty( $keep ) ) {
			$params['ignore_tags'] = 'keep';
		}
		if ( ! empty( $parts ) ) {
			// I segnaposto sono parte della frase: non la interrompono.
			$params['non_splitting_tags'] = implode( ',', array_keys( $parts ) );
		}
		$query = http_build_query( $params );
		foreach ( $texts as $text ) {
			$body = $text;
			if ( $xml ) {
				$body = InlineText::has_parts( $text )
					? InlineText::to_tags( $text )        // escapa e trasforma i segnaposto in tag
					: KeepTerms::escape( $text );         // escapa e basta
				if ( ! empty( $keep ) ) {
					$body = KeepTerms::wrap_escaped( $body, $keep, '<keep>', '</keep>' );
				}
			}
			$query .= '&text=' . rawurlencode( $body );
		}

		$result = $this->post(
			$host . '/v2/translate',
			array(
				'Authorization' => 'DeepL-Auth-Key ' . $key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			$query
		);
		// The «:fx» ending is how DeepL tells a free key from a paid one, and the two
		// live on different hosts. A key that breaks the rule (DeepL has changed its
		// plans before) gets 403 «Wrong endpoint»: one more try on the other host
		// instead of a dead translator and a message nobody understands.
		if ( isset( $result['error'] ) && false !== strpos( $result['error'], 'HTTP 403' ) && false !== stripos( $result['error'], 'endpoint' ) ) {
			$altro  = ( 'https://api.deepl.com' === $host ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
			$result = $this->post(
				$altro . '/v2/translate',
				array(
					'Authorization' => 'DeepL-Auth-Key ' . $key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				$query
			);
		}
		if ( isset( $result['error'] ) ) {
			// 456 is DeepL's "quota exceeded" status. It does NOT only mean the
			// monthly characters are gone: on a free key with an unverified
			// account DeepL answers 456 while /v2/usage still reports 0 of
			// 1,000,000 used (seen in the wild). So the message names both
			// causes instead of sending people to look at a counter that says
			// zero. The word "quota" also
			// tells the Translator to retire the provider for this run.
			if ( false !== strpos( $result['error'], 'HTTP 456' ) ) {
				// Ask DeepL what the counter actually says and put it in the
				// message. A 456 with "0 of 1,000,000 used" is not a spent
				// allowance, and saying "quota exhausted" sends people to stare
				// at a counter that reads zero.
				$uso = self::usage( $this->api_key() );
				if ( is_array( $uso ) && isset( $uso['count'], $uso['limit'] ) && (int) $uso['count'] < (int) $uso['limit'] ) {
					return TranslationResult::fail(
						sprintf(
							/* translators: 1: characters used, 2: character limit. */
							__( 'DeepL refused with "quota exceeded", but your account still reports %1$s of %2$s characters used. That combination means the key itself is blocked, not the allowance: DeepL does this when the account has no verified payment method. Check your account on deepl.com/account.', 'translate-rocket' ),
							number_format_i18n( (int) $uso['count'] ),
							number_format_i18n( (int) $uso['limit'] )
						)
					);
				}
				return TranslationResult::fail( __( 'DeepL: the monthly character allowance is used up. It resets at the start of your DeepL billing cycle.', 'translate-rocket' ) );
			}
			return TranslationResult::fail( 'DeepL: ' . $result['error'] );
		}

		$data = json_decode( $result['body'] ?? '', true );
		if ( ! is_array( $data ) || empty( $data['translations'] ) ) {
			return TranslationResult::fail( 'DeepL: unexpected response.' );
		}

		$out = array();
		$i   = 0;
		foreach ( $data['translations'] as $item ) {
			$t = (string) ( $item['text'] ?? '' );
			if ( $xml ) {
				if ( ! empty( $keep ) ) {
					$t = KeepTerms::strip_tag( $t, 'keep' );
				}
				$t = ( isset( $texts[ $i ] ) && InlineText::has_parts( $texts[ $i ] ) )
					? InlineText::from_tags( $t )                                        // rimette i segnaposto e decodifica
					: html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );         // decodifica e basta
			}
			$out[] = $t;
			++$i;
		}
		if ( count( $out ) !== count( $texts ) ) {
			return TranslationResult::fail( 'DeepL: item count mismatch.' );
		}
		return TranslationResult::ok( $out );
	}

	/**
	 * Character usage for the given key from DeepL's /v2/usage endpoint,
	 * cached for 15 minutes. Returns null when the key is empty or the call
	 * fails (the settings page simply omits the meter in that case).
	 *
	 * @return array{count:int,limit:int}|null
	 */
	public static function usage( string $key ): ?array {
		$key = trim( $key );
		if ( '' === $key ) {
			return null;
		}
		$cache_key = 'trrocket_deepl_usage_' . md5( $key );
		$cached    = get_transient( $cache_key );
		if ( 'fail' === $cached ) {
			// A recent lookup failed; don't hammer a slow/unreachable endpoint
			// on every admin page load — the caller hides the meter on null.
			return null;
		}
		if ( is_array( $cached ) && isset( $cached['count'], $cached['limit'] ) ) {
			return $cached;
		}

		$fail = static function () use ( $cache_key ) {
			set_transient( $cache_key, 'fail', 5 * MINUTE_IN_SECONDS );
			return null;
		};

		$host     = ( ':fx' === substr( $key, -3 ) ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
		$response = wp_remote_get(
			$host . '/v2/usage',
			array(
				'timeout' => 3,
				'headers' => array( 'Authorization' => 'DeepL-Auth-Key ' . $key ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $fail();
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['character_count'], $data['character_limit'] ) ) {
			return $fail();
		}
		$usage = array(
			'count' => (int) $data['character_count'],
			'limit' => (int) $data['character_limit'],
		);
		set_transient( $cache_key, $usage, 15 * MINUTE_IN_SECONDS );
		return $usage;
	}

	/**
	 * Map our code to a DeepL target code.
	 */
	private function deepl_target( string $code ): string {
		$map = array(
			'en'    => 'EN-GB',
			'pt'    => 'PT-PT',
			'pt-br' => 'PT-BR',
			'zh'    => 'ZH',
		);
		$code = strtolower( $code );
		return $map[ $code ] ?? strtoupper( substr( $code, 0, 2 ) );
	}
}
