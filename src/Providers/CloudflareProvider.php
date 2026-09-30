<?php
/**
 * Cloudflare Workers AI — free daily allowance of the owner's own account.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

use TranslateRocket\Frontend\InlineText;

defined( 'ABSPATH' ) || exit;

/**
 * Every Cloudflare account gets 10,000 «neurons» a day for free. Two models: Llama 3.3 70B
 * (about 1,900 sentences a day, natural and keeps links) and m2m100 (plain translation,
 * about 11,000 a day). Quality: best, volume, or auto — the best one until 80% of the day's
 * neurons are used, then the volume one. The key field holds the API token; the account ID
 * has its own field. Neurons are read from every answer.
 */
class CloudflareProvider extends FreeAiProvider {

	const BEST   = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
	const VOLUME = '@cf/meta/m2m100-1.2b';

	public function id(): string {
		return 'cloudflare';
	}

	public function label(): string {
		return 'Cloudflare Workers AI';
	}

	private function account(): string {
		return (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $this->config['account'] ?? '' ) );
	}

	public function is_configured(): bool {
		return parent::is_configured() && '' !== $this->account();
	}

	public function quality(): string {
		$q = (string) ( $this->config['quality'] ?? 'auto' );
		return in_array( $q, array( 'best', 'volume', 'auto' ), true ) ? $q : 'auto';
	}

	public function current_model(): string {
		$q = $this->quality();
		if ( 'best' === $q ) {
			return self::BEST;
		}
		if ( 'volume' === $q ) {
			return self::VOLUME;
		}
		return FreeUsage::share( $this->id() ) >= 0.8 ? self::VOLUME : self::BEST;
	}

	private function url( string $model ): string {
		return 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $this->account() ) . '/ai/run/' . $model;
	}

	private function headers(): array {
		return array( 'Authorization' => 'Bearer ' . $this->api_key() );
	}

	private function run( string $model, array $body ): array {
		$r = $this->post_patient( $this->url( $model ), $body, $this->headers() );
		if ( isset( $r['error'] ) || empty( $r['data']['success'] ) ) {
			return array( 'error' => $this->label() . ': ' . ( $r['error'] ?? ( 'HTTP ' . $r['code'] ) ) );
		}
		FreeUsage::add( $this->id(), (float) ( $r['data']['result']['usage']['neurons'] ?? 0 ), 0 );
		return array( 'result' => (array) $r['data']['result'] );
	}

	protected function chat( string $prompt ): TranslationResult {
		$r = $this->run( self::BEST, array( 'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ), 'max_tokens' => 4000, 'temperature' => 0.2 ) );
		if ( isset( $r['error'] ) ) {
			return TranslationResult::fail( $r['error'] );
		}
		$resp = $r['result']['response'] ?? '';
		// Cloudflare may hand the JSON list already decoded: give it back as JSON text.
		$testo = is_array( $resp ) ? (string) wp_json_encode( $resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : (string) $resp;
		return '' === trim( $testo ) ? TranslationResult::fail( $this->label() . ': empty response.' ) : TranslationResult::ok( array( $testo ) );
	}

	public function translate( array $texts, string $source, string $target ): TranslationResult {
		$texts = array_values( $texts );
		if ( self::VOLUME !== $this->current_model() ) {
			$res = parent::translate( $texts, $source, $target );
			if ( $res->success ) {
				FreeUsage::sentences( $this->id(), count( $texts ) );
			}
			return $res;
		}
		// The plain translation model: one sentence per request, plain text only. A sentence
		// with links or bold words goes to the best model instead (m2m100 would break the links,
		// and a sentence left empty would never reach another provider: the batch succeeded).
		$con_parti = array();
		foreach ( $texts as $i => $t ) {
			if ( InlineText::has_parts( (string) $t ) ) {
				$con_parti[ $i ] = (string) $t;
			}
		}
		$tradotte_parti = array();
		if ( ! empty( $con_parti ) ) {
			$rp = parent::translate( array_values( $con_parti ), $source, $target );
			if ( $rp->success ) {
				$tradotte_parti = array_combine( array_keys( $con_parti ), array_values( (array) $rp->translations ) );
			}
		}
		$out = array();
		foreach ( $texts as $i => $t ) {
			if ( isset( $con_parti[ $i ] ) ) {
				$out[] = (string) ( $tradotte_parti[ $i ] ?? '' );
				continue;
			}
			$r = $this->run( self::VOLUME, array( 'text' => (string) $t, 'source_lang' => substr( strtolower( $source ), 0, 2 ), 'target_lang' => substr( strtolower( $target ), 0, 2 ) ) );
			if ( isset( $r['error'] ) ) {
				if ( empty( $out ) ) {
					return TranslationResult::fail( $r['error'] );
				}
				break;
			}
			$out[] = (string) ( $r['result']['translated_text'] ?? '' );
		}
		FreeUsage::sentences( $this->id(), count( array_filter( $out ) ) );
		return TranslationResult::ok( array_pad( $out, count( $texts ), '' ) );
	}

	public function complete( string $system, string $user, int $max_tokens = 900 ): array {
		$r = $this->run( self::BEST, array( 'messages' => array( array( 'role' => 'system', 'content' => $system ), array( 'role' => 'user', 'content' => $user ) ), 'max_tokens' => $max_tokens, 'temperature' => 0.2 ) );
		return isset( $r['error'] ) ? $r : array( 'text' => $r['result']['response'] ?? '' );
	}

	/**
	 * «Load available models» doubles as the key check: the token is verified, the account read.
	 */
	public function list_models(): array {
		if ( '' === $this->api_key() || '' === $this->account() ) {
			$this->models_error = 'http-401';
			return array();
		}
		$res = $this->get( 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $this->account() ) . '/ai/models/search?search=llama-3.3-70b', $this->headers() );
		if ( isset( $res['error'] ) ) {
			return array();
		}
		return array( self::BEST, self::VOLUME );
	}
}
