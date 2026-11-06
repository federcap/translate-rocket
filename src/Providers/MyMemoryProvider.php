<?php
/**
 * MyMemory (Translated.net): official, free, no key, with a daily limit.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

use TranslateRocket\Frontend\InlineText;

defined( 'ABSPATH' ) || exit;

/**
 * 5/11/2026 (Federico: «the user arrives, makes one click and translates, stop»): the last engine of the one-click
 * translation, for a browser without a built-in translator (Firefox, Safari, a phone) and no key and no Labs.
 * The public MyMemory API is official and documented (https://mymemory.translated.net/doc/spec.php): no key, about
 * 5,000 characters a day per address. One sentence per request, as its terms ask.
 *
 * Not part of the fallback chain (Registry::all() leaves it out): only the one-click translation asks for it by id,
 * so a site with its own keys never sends a word here.
 */
class MyMemoryProvider extends AbstractProvider {

	const ID    = 'mymemory-free';
	const TODAY = 'trrocket_mm_today';
	const LIMIT = 5000;
	/** With an e-mail address MyMemory allows ten times more (its own documentation). */
	const LIMIT_EMAIL = 50000;
	/** The address the owner agreed to send (one click, only when the free day ran out). */
	const EMAIL = 'trrocket_mm_email';

	public function __construct( array $config = array() ) {
		parent::__construct( $config );
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'MyMemory (free)';
	}

	public function is_configured(): bool {
		return ! self::used_up();
	}

	public function batch_size(): int {
		return 8;
	}

	/**
	 * Today's characters, and whether MyMemory said the day is over.
	 *
	 * @return array{day:string,chars:int,full:int}
	 */
	public static function today(): array {
		$t = get_option( self::TODAY );
		if ( ! is_array( $t ) || (string) ( $t['day'] ?? '' ) !== gmdate( 'Ymd' ) ) {
			return array( 'day' => gmdate( 'Ymd' ), 'chars' => 0, 'full' => 0 );
		}
		return array( 'day' => (string) $t['day'], 'chars' => (int) $t['chars'], 'full' => (int) ( $t['full'] ?? 0 ) );
	}

	public static function used_up(): bool {
		$t = self::today();
		$base = '' !== self::email() ? self::LIMIT_EMAIL : self::LIMIT;
		return $t['full'] > 0 || $t['chars'] >= (int) apply_filters( 'trrocket_mymemory_limit', $base );
	}

	public static function email(): string {
		return sanitize_email( (string) get_option( self::EMAIL, '' ) );
	}

	private static function count( int $chars, bool $full = false ): void {
		$t           = self::today();
		$t['chars'] += max( 0, $chars );
		if ( $full ) {
			$t['full'] = 1;
		}
		update_option( self::TODAY, $t, false );
	}

	private static function code( string $code ): string {
		$code = strtolower( $code );
		$map  = array( 'pt-br' => 'pt-BR', 'pt-pt' => 'pt-PT', 'zh' => 'zh-CN', 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW' );
		return $map[ $code ] ?? substr( $code, 0, 2 );
	}

	public function translate( array $texts, string $source, string $target ): TranslationResult {
		$texts = array_values( $texts );
		if ( empty( $texts ) ) {
			return TranslationResult::ok( array() );
		}
		if ( self::used_up() ) {
			return TranslationResult::rest( 'MyMemory: today\'s free limit is used up.', strtotime( 'tomorrow' ) ?: time() + DAY_IN_SECONDS );
		}
		$out = array();
		foreach ( $texts as $i => $text ) {
			$text = (string) $text;
			$h    = InlineText::has_parts( $text ) ? InlineText::to_tags( $text ) : esc_html( $text );
			if ( $i > 0 ) {
				usleep( 250000 );
			}
			$r = wp_remote_get(
				add_query_arg(
					array(
						'langpair' => self::code( $source ) . '|' . self::code( $target ),
						'q'        => rawurlencode( $h ),
						'de'       => '' !== self::email() ? rawurlencode( self::email() ) : false,
					),
					'https://api.mymemory.translated.net/get'
				),
				array(
					'timeout'    => 20,
					'user-agent' => 'TranslateRocket (+https://translaterocket.com)',
				)
			);
			if ( is_wp_error( $r ) ) {
				if ( $out ) {
					break; // keep what came back; the rest on the next step
				}
				return TranslationResult::fail( 'MyMemory: ' . $r->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			if ( 429 === $code || ( is_array( $data ) && ! empty( $data['quotaFinished'] ) ) ) {
				self::count( 0, true );
				if ( $out ) {
					break;
				}
				return TranslationResult::rest( 'MyMemory: today\'s free limit is used up.', strtotime( 'tomorrow' ) ?: time() + DAY_IN_SECONDS );
			}
			if ( 200 !== $code || ! is_array( $data ) || 200 !== (int) ( $data['responseStatus'] ?? 0 ) ) {
				if ( $out ) {
					break;
				}
				return TranslationResult::fail( 'MyMemory: ' . (string) ( is_array( $data ) ? ( $data['responseDetails'] ?? 'HTTP ' . $code ) : 'HTTP ' . $code ) );
			}
			self::count( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) );
			$t = (string) ( $data['responseData']['translatedText'] ?? '' );
			if ( InlineText::has_parts( $text ) ) {
				$t = InlineText::from_tags( $t );
				if ( ! InlineText::parts_ok( $text, $t ) ) {
					$t = ''; // links that did not come back as they left: not saved
				}
			} else {
				$t = html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			// An «answer» identical to the question is no translation.
			if ( '' !== $t && trim( $t ) === trim( $text ) && ! preg_match( '/^[\d\s\W]+$/u', $text ) ) {
				$t = '';
			}
			$out[] = $t;
		}
		// Sentences not reached in this step stay empty: they are still missing and come back in the next one.
		return TranslationResult::ok( array_pad( $out, count( $texts ), '' ) );
	}
}
