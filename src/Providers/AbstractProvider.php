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
	 * @param array<string,mixed> $config Provider config slice.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
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
		$response = wp_remote_get( $url, array( 'headers' => $headers, 'timeout' => $timeout ) );
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
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
			'timeout' => $timeout,
		);

		$attempts = 3;
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
			$transient = ( 0 === $code ) || in_array( $code, array( 429, 500, 502, 503, 504 ), true );
			// A 429 for exhausted quota / billing is permanent, not a rate limit —
			// retrying just delays a clear "you're out of credit" message.
			if ( 429 === $code && false !== stripos( $raw, 'insufficient_quota' ) ) {
				$transient = false;
			}
			if ( ! $transient || $i === $attempts ) {
				break;
			}
			sleep( $i ); // Linear backoff: 1s, then 2s.
		}

		if ( '' !== $err ) {
			return array( 'error' => $err );
		}
		return array( 'error' => 'HTTP ' . $code . ': ' . substr( $raw, 0, 300 ) );
	}
}
