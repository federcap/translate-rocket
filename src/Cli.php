<?php
/**
 * WP-CLI: translate what is missing from the server's command line.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

use TranslateRocket\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * 1.8.14 (ticket #22, a 2-core server: «the web PHP workers have timeouts and a small pool; give us a WP-CLI command, so we
 * can translate the backlog in one low-priority process, away from the visitors»):
 *
 *   wp translaterocket translate --lang=zh
 *   nice -n 19 wp translaterocket translate --all --batch=10 --pause=2
 *
 * One process, short steps (the same ones as «Translate all»), the site's translation lock respected.
 */
final class Cli {

	/**
	 * Register the command (only when WP-CLI runs).
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'translaterocket translate', array( __CLASS__, 'translate' ) );
		}
	}

	/**
	 * Translate the sentences still missing.
	 *
	 * ## OPTIONS
	 *
	 * [--lang=<code>]
	 * : One target language, e.g. «zh» or «pt-br».
	 *
	 * [--all]
	 * : Every target language of the site, one after the other.
	 *
	 * [--engine=<id>]
	 * : One engine only (deepl, openai, anthropic, gemini, google, groq, cloudflare, openrouter, or one added by Labs).
	 * Without it, your priority order on the AI screen.
	 *
	 * [--batch=<sentences>]
	 * : Sentences per request to the engine. Default: your setting on the AI screen.
	 *
	 * [--pause=<seconds>]
	 * : Rest between two steps, to keep the server light. Default 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp translaterocket translate --lang=zh
	 *     nice -n 19 wp translaterocket translate --all --engine=groq --batch=10 --pause=2
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 */
	public static function translate( $args, $assoc_args ): void {
		unset( $args );
		$targets = (array) ( Settings::get()['target_languages'] ?? array() );
		if ( ! empty( $assoc_args['all'] ) ) {
			$langs = $targets;
		} elseif ( ! empty( $assoc_args['lang'] ) ) {
			$langs = array( strtolower( sanitize_text_field( (string) $assoc_args['lang'] ) ) );
		} else {
			\WP_CLI::error( 'Say which language: --lang=<code>, or --all. Target languages: ' . implode( ', ', $targets ) );
			return;
		}
		foreach ( $langs as $l ) {
			if ( ! in_array( $l, $targets, true ) ) {
				\WP_CLI::error( sprintf( '«%s» is not a target language of this site. Target languages: %s', $l, implode( ', ', $targets ) ) );
				return;
			}
		}
		$engine = isset( $assoc_args['engine'] ) ? sanitize_key( (string) $assoc_args['engine'] ) : '';
		if ( '' !== $engine ) {
			$p = Registry::get( $engine );
			if ( null === $p || ! $p->is_configured() ) {
				\WP_CLI::error( sprintf( 'The engine «%s» is not set up on this site.', $engine ) );
				return;
			}
		}
		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 0;
		$pause = isset( $assoc_args['pause'] ) ? max( 0, (int) $assoc_args['pause'] ) : 0;

		foreach ( $langs as $lang ) {
			$prima = Strings::untranslated_count( $lang );
			\WP_CLI::log( sprintf( '%s: %d sentences to translate.', $lang, $prima ) );
			$fatte = 0;
			$vuoti = 0;
			while ( true ) {
				$r = Translator::translate_missing( $lang, '', $batch, false, $engine, microtime( true ) + 25 );
				if ( ! empty( $r['busy'] ) ) {
					\WP_CLI::log( 'Another translation is running on this site: waiting 10 seconds.' );
					sleep( 10 );
					continue;
				}
				$n      = (int) ( $r['count'] ?? 0 );
				$fatte += $n;
				$resta  = Strings::untranslated_count( $lang );
				\WP_CLI::log( sprintf( '%s: +%d, %d left.', $lang, $n, $resta ) );
				if ( ! empty( $r['error'] ) && 0 === $n ) {
					\WP_CLI::warning( (string) $r['error'] );
				}
				if ( 0 === $resta || empty( $r['more'] ) ) {
					break;
				}
				$vuoti = 0 === $n ? $vuoti + 1 : 0;
				if ( $vuoti >= 3 ) {
					\WP_CLI::warning( sprintf( '%s: three steps in a row translated nothing; stopping. Run the command again later.', $lang ) );
					break;
				}
				if ( $pause > 0 ) {
					sleep( $pause );
				}
			}
			Cache::flush();
			\WP_CLI::success( sprintf( '%s: %d translated, %d left.', $lang, $fatte, Strings::untranslated_count( $lang ) ) );
		}
	}
}
