<?php
/**
 * DeepL translation provider.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

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

		$host  = ( ':fx' === substr( $key, -3 ) ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
		$query = http_build_query(
			array(
				'source_lang' => strtoupper( substr( $source, 0, 2 ) ),
				'target_lang' => $this->deepl_target( $target ),
			)
		);
		foreach ( $texts as $text ) {
			$query .= '&text=' . rawurlencode( $text );
		}

		$result = $this->post(
			$host . '/v2/translate',
			array(
				'Authorization' => 'DeepL-Auth-Key ' . $key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			$query
		);
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
		foreach ( $data['translations'] as $item ) {
			$out[] = (string) ( $item['text'] ?? '' );
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
