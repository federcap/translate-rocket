<?php
/**
 * What the free AI providers (Cloudflare Workers AI, Groq, OpenRouter) have in common.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Official APIs used with the free share of the owner's own account. They translate like the
 * other AI providers (LlmProvider: a JSON list in, a JSON list out, house style and protected
 * names in the prompt) and add: a daily counter (FreeUsage) with which the provider steps
 * aside before the free share runs out, and complete(), a free question used by the tone
 * advisor. User-Agent says who is asking.
 */
abstract class FreeAiProvider extends LlmProvider {

	const USER_AGENT = 'TranslateRocket (+https://translaterocket.com)';

	public function is_configured(): bool {
		return parent::is_configured() && ! FreeUsage::exhausted( $this->id() );
	}

	/**
	 * One free question (system + user): the answer as it came, or an error.
	 *
	 * @return array{text?:mixed,error?:string}
	 */
	abstract public function complete( string $system, string $user, int $max_tokens = 900 ): array;

	/**
	 * POST JSON; the decoded answer and headers, or an error.
	 *
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Extra headers.
	 * @return array{code:int,data:array,raw:string,retry:int,error?:string}
	 */
	protected function post_json( string $url, array $body, array $headers ): array {
		$r = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'headers' => array_merge( array( 'Content-Type' => 'application/json', 'User-Agent' => self::USER_AGENT ), $headers ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return array( 'code' => 0, 'data' => array(), 'raw' => '', 'retry' => 0, 'error' => $r->get_error_message() );
		}
		$code  = (int) wp_remote_retrieve_response_code( $r );
		$raw   = (string) wp_remote_retrieve_body( $r );
		$data  = json_decode( $raw, true );
		$retry = (int) wp_remote_retrieve_header( $r, 'retry-after' );
		if ( 0 === $retry && preg_match( '/try again in\s+([\d.]+)\s*s\b/i', $raw, $m ) ) {
			$retry = (int) ceil( (float) $m[1] );
		}
		$out = array( 'code' => $code, 'data' => is_array( $data ) ? $data : array(), 'raw' => $raw, 'retry' => $retry );
		if ( $code < 200 || $code >= 300 ) {
			$msg = '';
			if ( isset( $out['data']['error'] ) ) {
				$msg = is_array( $out['data']['error'] ) ? (string) ( $out['data']['error']['message'] ?? '' ) : (string) $out['data']['error'];
			} elseif ( isset( $out['data']['errors'][0]['message'] ) ) {
				$msg = (string) $out['data']['errors'][0]['message'];
			}
			$out['error'] = 'HTTP ' . $code . ( '' !== $msg ? ': ' . $msg : '' );
		}
		return $out;
	}

	/**
	 * POST, and when the service says «slow down, try again in a few seconds», wait and try once more.
	 *
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Extra headers.
	 */
	protected function post_patient( string $url, array $body, array $headers ): array {
		$r = $this->post_json( $url, $body, $headers );
		if ( 429 === $r['code'] && $r['retry'] > 0 && $r['retry'] <= 25 ) {
			sleep( $r['retry'] + 1 );
			$r = $this->post_json( $url, $body, $headers );
		}
		return $r;
	}
}
