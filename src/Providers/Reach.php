<?php
/**
 * Can this server reach each translation engine?
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * 15/11/2026 (Federico, after a site owner wrote of «regional restrictions for GPT and Claude»): tell the
 * owner, before any credit is bought, whether the hosting can use an engine. One small GET to the engine's
 * own address, from the server, with no key and no text: any answer of the engine means it can be reached;
 * an answer that names the country or region means the engine does not serve where the server is; an HTML
 * page with 403 is a firewall or a proxy on the way; no answer at all is a host that blocks the connection.
 *
 * It says nothing about the owner's account (an engine may refuse sign-ups from a country): only about the
 * road from this server to the engine. Kept for 12 hours for each engine.
 */
class Reach {

	const HOURS = 12;

	/** What an engine writes when it does not serve the place the request comes from. */
	const REGION = '/unsupported_country|country, region,? or territory|not (available|supported) in your (country|region|location|area|territory)|location is not supported|region (is )?not supported|restricted (country|region|location)/i';

	/**
	 * The address asked for each engine: one that answers without a key.
	 *
	 * @return array<string,string>
	 */
	public static function urls(): array {
		$qwen = new QwenProvider( (array) ( Settings::get()['providers']['qwen'] ?? array() ) );
		$urls = array(
			'deepl'      => 'https://api-free.deepl.com/v2/languages',
			'openai'     => 'https://api.openai.com/v1/models',
			'anthropic'  => 'https://api.anthropic.com/v1/models',
			'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/models',
			'google'     => 'https://translation.googleapis.com/language/translate/v2/languages',
			'cloudflare' => 'https://api.cloudflare.com/client/v4/user/tokens/verify',
			'groq'       => 'https://api.groq.com/openai/v1/models',
			'openrouter' => 'https://openrouter.ai/api/v1/models',
			'deepseek'   => 'https://api.deepseek.com/models',
			'qwen'       => 'https://' . QwenProvider::REGIONS[ $qwen->region() ] . '/compatible-mode/v1/models',
		);
		/**
		 * The address used to check that the server reaches an engine (no key is sent).
		 *
		 * @param array<string,string> $urls Engine id => https address.
		 */
		$urls = (array) apply_filters( 'trrocket_reach_urls', $urls );
		$out  = array();
		foreach ( $urls as $id => $url ) {
			if ( is_string( $url ) && 0 === strpos( $url, 'https://' ) ) {
				$out[ sanitize_key( (string) $id ) ] = $url;
			}
		}
		return $out;
	}

	private static function key( string $id ): string {
		return 'trrocket_reach_' . $id;
	}

	/**
	 * What is known already, without asking: the saved result, or null.
	 *
	 * @return array{state:string,code:int,detail:string,url:string,when:int}|null
	 */
	public static function known( string $id ): ?array {
		$r = get_transient( self::key( $id ) );
		if ( ! is_array( $r ) || empty( $r['state'] ) ) {
			return null;
		}
		// Asked at another address (the region of the Qwen key changed): not the answer to today's question.
		if ( (string) ( $r['url'] ?? '' ) !== (string) ( self::urls()[ $id ] ?? '' ) ) {
			return null;
		}
		return $r;
	}

	/**
	 * Ask the engine now and keep the result.
	 *
	 * @return array{state:string,code:int,detail:string,url:string,when:int}|null Null for an engine with no address.
	 */
	public static function check( string $id ): ?array {
		$url = (string) ( self::urls()[ $id ] ?? '' );
		if ( '' === $url ) {
			return null;
		}
		$r = wp_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 2,
				'limit_response_size' => 4096,
				'user-agent'          => 'TranslateRocket (+https://translaterocket.com)',
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		$out = array( 'state' => 'ok', 'code' => 0, 'detail' => '', 'url' => $url, 'when' => time() );
		if ( is_wp_error( $r ) ) {
			$out['state']  = 'net';
			$out['detail'] = substr( (string) $r->get_error_message(), 0, 200 );
		} else {
			$code        = (int) wp_remote_retrieve_response_code( $r );
			$body        = (string) wp_remote_retrieve_body( $r );
			$out['code'] = $code;
			$html        = (bool) preg_match( '/^\s*<(!doctype|html|head|body)\b/i', $body );
			if ( $code >= 400 && preg_match( self::REGION, $body ) ) {
				$out['state'] = 'region';
			} elseif ( 451 === $code ) {
				$out['state'] = 'region';
			} elseif ( $html && in_array( $code, array( 403, 406, 407, 503 ), true ) ) {
				$out['state'] = 'blocked';
			} elseif ( $code >= 500 ) {
				$out['state'] = 'down';
			}
			if ( 'ok' !== $out['state'] ) {
				$out['detail'] = 'HTTP ' . $code . ': ' . substr( trim( (string) wp_strip_all_tags( $body ) ), 0, 200 );
			}
		}
		set_transient( self::key( $id ), $out, self::HOURS * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * The result in plain words.
	 *
	 * @param array{state:string,code:int,detail:string} $r     A result of check() or known().
	 * @param string                                     $label The engine's name.
	 */
	public static function sentence( array $r, string $label ): string {
		switch ( (string) $r['state'] ) {
			case 'ok':
				/* translators: %s: engine name, e.g. DeepSeek. */
				return sprintf( __( 'Your server reaches %s: your hosting can use it.', 'translate-rocket' ), $label );
			case 'region':
				/* translators: %s: engine name, e.g. OpenAI. */
				return sprintf( __( '%s does not serve the country or region where your server is. Use another engine, or ask your host about a server in another region.', 'translate-rocket' ), $label );
			case 'blocked':
				/* translators: %s: engine name. */
				return sprintf( __( 'Something between your server and %s blocked the request (a firewall or a proxy). Ask your host to allow it, or use another engine.', 'translate-rocket' ), $label );
			case 'down':
				/* translators: 1: engine name, 2: HTTP code. */
				return sprintf( __( 'Your server reaches %1$s, but it answers with an error right now (HTTP %2$d). Try again later.', 'translate-rocket' ), $label, (int) $r['code'] );
			default:
				/* translators: %s: engine name. */
				return sprintf( __( 'Your server cannot reach %s. Your host may block outgoing connections to it: ask your host, or use another engine.', 'translate-rocket' ), $label );
		}
	}

	/**
	 * The few words for the heading of a card, '' when all is well.
	 *
	 * @param array{state:string} $r A result.
	 */
	public static function badge( array $r ): string {
		switch ( (string) $r['state'] ) {
			case 'ok':
			case 'down':
				return '';
			case 'region':
				return __( 'Region blocked', 'translate-rocket' );
			default:
				return __( 'Unreachable', 'translate-rocket' );
		}
	}
}
