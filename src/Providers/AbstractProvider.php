<?php
/**
 * Shared provider base.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

use TranslateRocket\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the provider config and a small wp_remote_post wrapper. Subclasses
 * implement id(), label() and translate().
 */
abstract class AbstractProvider implements ProviderInterface {

	/**
	 * Provider config (api_key, model, ...).
	 *
	 * @var array<string,mixed>
	 */
	protected $config;

	/**
	 * Why the last list_models() came back empty: '' | 'http-401' | 'http-429' | 'net'…
	 *
	 * @var string
	 */
	protected $models_error = '';

	/**
	 * What the last list_models() request got back, as «HTTP 200: body…» or the network error: for the
	 * plain message and the «Technical details» of the AI page (14/10/2026).
	 *
	 * @var string
	 */
	protected $models_raw = '';

	/**
	 * One attempt and a short wait: a test the owner is waiting for, not a background job (14/10/2026:
	 * three attempts of 45 s could keep «Test this text» busy past the 100 s of Cloudflare).
	 *
	 * @var bool
	 */
	protected $quick = false;

	/**
	 * @param array<string,mixed> $config Provider config slice.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Why the last list_models() came back empty ('' when it did not fail).
	 */
	public function models_error(): string {
		return $this->models_error;
	}

	/**
	 * The raw answer behind the last list_models() ('' when it was not asked).
	 */
	public function models_raw(): string {
		return $this->models_raw;
	}

	/**
	 * Make the next requests quick: one attempt, at most 25 seconds.
	 */
	public function quick(): void {
		$this->quick = true;
	}

	/**
	 * The error for a 200 that does not carry a translation (a proxy's page, an empty body): what came back
	 * goes with it, so the message and the technical details can say what it was.
	 */
	protected function unreadable( string $raw ): string {
		$raw = trim( $raw );
		return $this->label() . ': HTTP 200, unreadable answer: ' . ( '' === $raw ? '(empty)' : substr( $raw, 0, 300 ) );
	}

	public function is_configured(): bool {
		return '' !== $this->api_key();
	}

	public function batch_size(): int {
		return 50;
	}

	/**
	 * Default: no model list (DeepL / Google Translate are not model-based).
	 *
	 * @return string[]
	 */
	public function list_models(): array {
		return array();
	}

	/**
	 * Small GET wrapper used by list_models().
	 *
	 * @param array<string,string> $headers Request headers.
	 * @return array{body?:string,error?:string}
	 */
	protected function get( string $url, array $headers = array(), int $timeout = 20 ): array {
		$this->models_error = '';
		$this->models_raw   = '';
		$response           = wp_remote_get( $url, array( 'headers' => $headers, 'timeout' => $timeout ) );
		if ( is_wp_error( $response ) ) {
			$this->models_error = 'net';
			$this->models_raw   = $response->get_error_message();
			return array( 'error' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$this->models_raw = 'HTTP ' . $code . ': ' . substr( trim( $raw ), 0, 300 );
		if ( $code < 200 || $code >= 300 ) {
			// Gemini answers a wrong key with 400 «API_KEY_INVALID», not 401.
			$this->models_error = ( 400 === $code && false !== stripos( $raw, 'API_KEY_INVALID' ) ) ? 'http-401' : 'http-' . $code;
			return array( 'error' => 'HTTP ' . $code );
		}
		return array( 'body' => $raw );
	}

	protected function api_key(): string {
		return trim( (string) ( $this->config['api_key'] ?? '' ) );
	}

	protected function model(): string {
		return trim( (string) ( $this->config['model'] ?? '' ) );
	}

	/**
	 * English language name for prompts (falls back to the code).
	 */
	protected function lang_name( string $code ): string {
		$all  = Languages::all();
		$code = strtolower( $code );
		return isset( $all[ $code ] ) ? $all[ $code ][1] : $code;
	}

	/**
	 * POST and return the decoded body, or null with an error message.
	 *
	 * @param array<string,string> $headers Request headers.
	 * @param string|array         $body    Request body.
	 * @return array{body?:string,error?:string}
	 */
	protected function post( string $url, array $headers, $body, int $timeout = 45 ): array {
		$args = array(
			'headers' => $headers,
			'body'    => $body,
			'timeout' => $this->quick ? min( $timeout, 25 ) : $timeout,
		);

		$attempts = $this->quick ? 1 : 3;
		$code     = 0;
		$raw      = '';
		$err      = '';

		for ( $i = 1; $i <= $attempts; $i++ ) {
			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				$err  = $response->get_error_message();
				$code = 0;
			} else {
				$err  = '';
				$code = (int) wp_remote_retrieve_response_code( $response );
				$raw  = (string) wp_remote_retrieve_body( $response );
				if ( $code >= 200 && $code < 300 ) {
					return array( 'body' => $raw );
				}
			}

			// Retry only transient failures (network, rate limit, server overload);
			// fail fast on real errors like 400/401/404 so we don't waste calls.
			// 520, 522 and 524 are Cloudflare's «origin did not answer» codes, in front of many providers (13/10/2026 ticket).
			$transient = ( 0 === $code ) || in_array( $code, array( 429, 500, 502, 503, 504, 520, 522, 524 ), true );
			// A 429 for exhausted quota / billing is permanent, not a rate limit —
			// retrying just delays a clear "you're out of credit" message.
			if ( 429 === $code && false !== stripos( $raw, 'insufficient_quota' ) ) {
				$transient = false;
			}
			if ( ! $transient || $i === $attempts ) {
				break;
			}
			// 25/10/2026 (Groq free tier, 8,000 tokens a minute: «Please try again in 1.1325s»): when the provider says how
			// long, and it is a few seconds, wait that long — 1 s then 2 s often fell short and the whole run stopped.
			$attesa = $i;
			if ( 429 === $code ) {
				$dopo = isset( $response ) && ! is_wp_error( $response ) ? (string) wp_remote_retrieve_header( $response, 'retry-after' ) : '';
				if ( is_numeric( $dopo ) ) {
					$attesa = (float) $dopo;
				} elseif ( preg_match( '/try again in\s+(?:(\d+)m)?\s*(\d+(?:\.\d+)?)(ms|s)\b/i', $raw, $mm ) ) {
					$attesa = (float) $mm[2] / ( 'ms' === strtolower( $mm[3] ) ? 1000 : 1 ) + 60 * (int) $mm[1];
				}
				$attesa = ( $attesa > 0 && $attesa <= 12 ) ? $attesa + 0.3 : $i;
			}
			usleep( (int) ( $attesa * 1000000 ) ); // Linear backoff (1 s, then 2 s), or what the provider asked for.
		}

		if ( '' !== $err ) {
			return array( 'error' => $err );
		}
		// 15/10/2026: the provider's own trace code (DeepL: x-trace-id), the one thing its support asks for.
		$trace = isset( $response ) && ! is_wp_error( $response ) ? (string) wp_remote_retrieve_header( $response, 'x-trace-id' ) : '';
		return array( 'error' => 'HTTP ' . $code . ': ' . substr( $raw, 0, 300 ), 'trace' => $trace );
	}
}
