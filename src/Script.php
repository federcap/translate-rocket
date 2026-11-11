<?php
/**
 * The alphabet of a language written in two (1.8.36).
 *
 * Serbian is written in Cyrillic and in Latin letters. The engines were only told «Serbian», so each
 * one picked its own: on a real shop (8/11/2026, Matjaž, piflarnakupuje.top/sr/) «Детектор инсталација»
 * and «Detektor metala» sat on the same page. The site owner now chooses the alphabet once
 * (Languages screen) and every translation follows it: the AI is told which one, whatever an engine
 * returns is converted when it is saved, the translations already there are converted in the
 * background, and the page itself is converted on the way out (WordPress' and WooCommerce's own
 * Serbian, sr_RS, is Cyrillic).
 *
 * Cyrillic → Latin is exact, letter by letter. Latin → Cyrillic leaves alone what must stay in Latin:
 * markup, addresses, codes, words with letters Serbian does not have (q, w, x, y), short acronyms and
 * every word that is also in the original (brand and model names).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

class Script {

	/** Languages with a choice, and the scripts they can use. */
	const LANGS = array( 'sr' );

	const LATIN    = 'latn';
	const CYRILLIC = 'cyrl';

	/** Background conversion of the translations already saved. */
	const JOB  = 'trrocket_script_convert';
	const DONE = 'trrocket_script_done';

	/** @var array<string,string> */
	const C2L = array(
		'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Ђ' => 'Đ', 'Е' => 'E', 'Ж' => 'Ž', 'З' => 'Z', 'И' => 'I',
		'Ј' => 'J', 'К' => 'K', 'Л' => 'L', 'Љ' => 'Lj', 'М' => 'M', 'Н' => 'N', 'Њ' => 'Nj', 'О' => 'O', 'П' => 'P', 'Р' => 'R',
		'С' => 'S', 'Т' => 'T', 'Ћ' => 'Ć', 'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C', 'Ч' => 'Č', 'Џ' => 'Dž', 'Ш' => 'Š',
		'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ђ' => 'đ', 'е' => 'e', 'ж' => 'ž', 'з' => 'z', 'и' => 'i',
		'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'lj', 'м' => 'm', 'н' => 'n', 'њ' => 'nj', 'о' => 'o', 'п' => 'p', 'р' => 'r',
		'с' => 's', 'т' => 't', 'ћ' => 'ć', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'č', 'џ' => 'dž', 'ш' => 'š',
	);

	/**
	 * The alphabet chosen for a language: 'latn', 'cyrl' or '' (as the engine writes it).
	 */
	public static function choice( string $lang ): string {
		$lang = strtolower( $lang );
		if ( ! in_array( $lang, self::LANGS, true ) ) {
			return '';
		}
		$all = Settings::get()['scripts'] ?? array();
		$c   = is_array( $all ) ? (string) ( $all[ $lang ] ?? '' ) : '';
		return in_array( $c, array( self::LATIN, self::CYRILLIC ), true ) ? $c : '';
	}

	/**
	 * Hooks: the background job, and the language's own WordPress texts on translated pages.
	 */
	public static function boot(): void {
		add_action( self::JOB, array( __CLASS__, 'convert_step' ) );
		add_action( 'admin_init', array( __CLASS__, 'resume' ) );
		foreach ( self::LANGS as $lang ) {
			if ( '' !== self::choice( $lang ) ) {
				// WordPress, WooCommerce and theme strings in sr_RS are Cyrillic: e-mails and AJAX answers too.
				add_filter( 'gettext', array( __CLASS__, 'gettext' ), 99, 1 );
				add_filter( 'ngettext', array( __CLASS__, 'gettext' ), 99, 1 );
				add_filter( 'gettext_with_context', array( __CLASS__, 'gettext' ), 99, 1 );
				break;
			}
		}
	}

	/**
	 * A WordPress text in the current locale, in the chosen alphabet.
	 *
	 * @param mixed $text Translated text.
	 * @return mixed
	 */
	public static function gettext( $text ) {
		if ( ! is_string( $text ) || '' === $text || ! preg_match( '/[\x{0400}-\x{04FF}]/u', $text ) ) {
			return $text;
		}
		$lang = self::locale_lang();
		return '' !== $lang && self::LATIN === self::choice( $lang ) ? self::to_latin( $text ) : $text;
	}

	/**
	 * The language of the current WordPress locale, if it is one with a choice.
	 */
	private static function locale_lang(): string {
		static $cache = array();
		$loc = function_exists( 'determine_locale' ) ? (string) determine_locale() : '';
		if ( ! isset( $cache[ $loc ] ) ) {
			$base          = strtolower( substr( $loc, 0, 2 ) );
			$cache[ $loc ] = in_array( $base, self::LANGS, true ) ? $base : '';
		}
		return $cache[ $loc ];
	}

	/**
	 * The language's name for the AI: «Serbian (Latin script)».
	 */
	public static function prompt_name( string $lang, string $name ): string {
		$c = self::choice( $lang );
		if ( self::LATIN === $c ) {
			return $name . ' (Latin script, latinica — never Cyrillic)';
		}
		if ( self::CYRILLIC === $c ) {
			return $name . ' (Cyrillic script, ћирилица — never Latin)';
		}
		return $name;
	}

	/**
	 * A translation in the chosen alphabet.
	 *
	 * @param string $original The source text (its words stay as they are when converting to Cyrillic).
	 */
	public static function apply( string $lang, string $text, string $original = '' ): string {
		$c = self::choice( $lang );
		if ( '' === $c || '' === $text ) {
			return $text;
		}
		return self::LATIN === $c ? self::to_latin( $text ) : self::to_cyrillic( $text, $original );
	}

	/**
	 * Cyrillic → Latin, letter by letter. Љ/Њ/Џ become «LJ/NJ/DŽ» inside a word in capitals.
	 */
	public static function to_latin( string $text ): string {
		if ( ! preg_match( '/[\x{0400}-\x{04FF}]/u', $text ) ) {
			return $text;
		}
		// The two-letter capitals: all caps when the next letter is a capital too («ЉУБАВ» → «LJUBAV»).
		$text = (string) preg_replace_callback(
			'/([ЉЊЏ])(?=[\x{0400}-\x{042F}A-ZČĆŠŽĐ])/u',
			static function ( $m ) {
				return mb_strtoupper( self::C2L[ $m[1] ] );
			},
			$text
		);
		return strtr( $text, self::C2L );
	}

	/**
	 * Latin → Cyrillic, only in the text between tags, word by word.
	 *
	 * @param string $original Source text: a word that is also there is a name and stays in Latin.
	 */
	public static function to_cyrillic( string $text, string $original = '' ): string {
		if ( ! preg_match( '/[A-Za-zČĆŠŽĐčćšžđ]/u', $text ) ) {
			return $text;
		}
		// Kept as it is (a brand, a model): the same text as the original stays the same.
		if ( '' !== $original && trim( $text ) === trim( $original ) ) {
			return $text;
		}
		// Names in the original: a capital in the middle of a sentence («… the Explorer X …»), a capital inside
		// the word («iPhone»), all capitals. Every word in common would be too much: from Slovenian or
		// Croatian, «Detektor» or «kocka» are in the original and are Serbian words to convert.
		$keep = array();
		$plain = '' !== $original ? (string) preg_replace( '/<[^>]*>/', ' ', $original ) : '';
		if ( '' !== $plain && preg_match_all( '/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u', $plain, $mm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm[0] as $m ) {
				$w      = (string) $m[0];
				$prima  = rtrim( substr( $plain, 0, (int) $m[1] ) ); // byte offset: substr, not mb_substr
				$inizio = '' === $prima || (bool) preg_match( '/[.!?:]$/', $prima );
				$maiusc = (bool) preg_match( '/^\p{Lu}/u', $w );
				$dentro = (bool) preg_match( '/^.+\p{Lu}/u', $w );
				if ( $dentro || ( $maiusc && ! $inizio ) ) {
					$keep[ mb_strtolower( $w ) ] = true;
				}
			}
		}
		// Tags, entities, placeholders, addresses and e-mails go back untouched.
		$parts = preg_split( '/(<[^>]*>|&[#a-zA-Z0-9]+;|%\d*\$?[sd]|\{[^}]*\}|\[[^\]]*\]|https?:\/\/\S+|www\.\S+|\S+@\S+\.\S+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $text;
		}
		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 ) {
				continue;
			}
			$parts[ $i ] = (string) preg_replace_callback(
				'/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u', // «Wi-Fi» is one word
				static function ( $m ) use ( $keep ) {
					return self::word_to_cyrillic( $m[0], $keep );
				},
				$part
			);
		}
		return implode( '', $parts );
	}

	/**
	 * One word, unless it has to stay in Latin.
	 *
	 * @param array<string,bool> $keep Words of the original.
	 */
	private static function word_to_cyrillic( string $w, array $keep ): string {
		if ( preg_match( '/[\p{N}]/u', $w )                        // codes, models: «RC12», «4K»
			|| preg_match( '/[qwxyQWXY]/', $w )                      // letters Serbian does not have
			|| preg_match( '/[^A-Za-zČĆŠŽĐčćšžđ-]/u', $w )           // other alphabets, other accents
			|| isset( $keep[ mb_strtolower( $w ) ] )                 // in the original: a name
			|| ( mb_strlen( $w ) <= 4 && mb_strtoupper( $w ) === $w && mb_strlen( $w ) > 1 ) // acronyms: «USB», «LED»
		) {
			return $w;
		}
		static $l2c = null;
		if ( null === $l2c ) {
			$l2c = array_flip( self::C2L );
			// Two-letter forms first (strtr prefers the longest), every capitalisation.
			$l2c += array( 'LJ' => 'Љ', 'NJ' => 'Њ', 'DŽ' => 'Џ', 'Lj' => 'Љ', 'Nj' => 'Њ', 'Dž' => 'Џ' );
		}
		return strtr( $w, $l2c );
	}

	/**
	 * The whole page, on the way out: what is still in the other alphabet (the theme's and WooCommerce's
	 * own sr_RS texts, a translation saved before the choice). Cyrillic → Latin only: it is exact, and a
	 * page holds no Cyrillic that must stay Cyrillic. Raw text inside an address is left alone.
	 */
	public static function page( string $lang, string $html ): string {
		if ( self::LATIN !== self::choice( $lang ) || ! preg_match( '/[\x{0400}-\x{04FF}]/u', $html ) ) {
			return $html;
		}
		// href/src/action values (a Cyrillic slug written raw) keep their letters: changing them breaks the link.
		$parts = preg_split( '/(\s(?:href|src|srcset|action|data-src)\s*=\s*(?:"[^"]*"|\'[^\']*\'))/iu', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html;
		}
		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) {
				$parts[ $i ] = self::to_latin( $part );
			}
		}
		return implode( '', $parts );
	}

	/**
	 * The choice changed: convert the translations already saved, in the background, a slice at a time.
	 */
	public static function start_conversion( string $lang ): void {
		if ( '' === self::choice( $lang ) ) {
			return;
		}
		update_option( self::DONE . '_' . $lang, array( 'last' => 0, 'changed' => 0, 'done' => false ), false );
		wp_clear_scheduled_hook( self::JOB, array( $lang ) );
		wp_schedule_single_event( time() + 5, self::JOB, array( $lang ) );
	}

	/**
	 * 1.8.38 (Matjaž: one paragraph still in Latin on a Cyrillic page). The conversion lived on a chain of
	 * single WP-Cron events, each booked by the one before: one step that never ran or died (cron switched
	 * off, a time limit) and it stopped for ever, with the screen still saying «converting». Now every
	 * visit to the admin area books the step again if it is gone and does a short slice itself.
	 */
	public static function resume(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Once, on sites that chose an alphabet before 1.8.38: until then «Undo» of «Translate again» (and of the
		// external AI) put the old sentences back as they were, in the other alphabet, after the conversion had
		// finished. The saved translations are read one more time; what is already right is left alone.
		if ( '1838' !== get_option( 'trrocket_script_redo' ) ) {
			update_option( 'trrocket_script_redo', '1838', false );
			foreach ( self::LANGS as $lang ) {
				if ( '' !== self::choice( $lang ) && false !== get_option( self::DONE . '_' . $lang, false ) ) {
					self::start_conversion( $lang );
				}
			}
		}
		foreach ( self::LANGS as $lang ) {
			if ( '' === self::choice( $lang ) || self::progress( $lang )['done'] || false === get_option( self::DONE . '_' . $lang, false ) ) {
				continue;
			}
			if ( ! wp_next_scheduled( self::JOB, array( $lang ) ) ) {
				wp_schedule_single_event( time() + 2, self::JOB, array( $lang ) );
			}
			if ( ! get_transient( 'trrocket_script_busy_' . $lang ) ) {
				self::convert_step( $lang, 2.0 );
			}
		}
	}

	/**
	 * Progress of the conversion: last id seen, rows changed, finished.
	 *
	 * @return array{last:int,changed:int,done:bool}
	 */
	public static function progress( string $lang ): array {
		$p = get_option( self::DONE . '_' . $lang, array() );
		return array(
			'last'    => (int) ( $p['last'] ?? 0 ),
			'changed' => (int) ( $p['changed'] ?? 0 ),
			'done'    => ! empty( $p['done'] ),
		);
	}

	/**
	 * One slice of the conversion (about 8 seconds), then the next one is booked.
	 *
	 * @param string $lang Language.
	 * @param float  $budget Seconds.
	 * @return array{last:int,changed:int,done:bool}
	 */
	public static function convert_step( $lang = 'sr', float $budget = 8.0 ): array {
		global $wpdb;
		$lang = strtolower( (string) $lang );
		$p    = self::progress( $lang );
		if ( '' === self::choice( $lang ) || $p['done'] ) {
			return $p;
		}
		$tr = Database::translations_table();
		$st = Database::strings_table();
		$t0 = microtime( true );
		$changed_any = false;
		// Somebody is at work (the admin slice waits), and the next step is booked before starting:
		// if this one dies half-way, the chain goes on from the last slice saved.
		set_transient( 'trrocket_script_busy_' . $lang, 1, 20 );
		if ( ! wp_next_scheduled( self::JOB, array( $lang ) ) ) {
			wp_schedule_single_event( time() + 60, self::JOB, array( $lang ) );
		}
		while ( microtime( true ) - $t0 < $budget ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT tr.string_id AS id, tr.translation AS t, st.original AS o FROM {$tr} tr INNER JOIN {$st} st ON st.id = tr.string_id WHERE tr.language = %s AND tr.string_id > %d AND tr.translation IS NOT NULL AND tr.translation <> '' ORDER BY tr.string_id ASC LIMIT 500", $lang, $p['last'] ) ); // phpcs:ignore WordPress.DB
			if ( empty( $rows ) ) {
				$p['done'] = true;
				break;
			}
			foreach ( $rows as $r ) {
				$p['last'] = (int) $r->id;
				$new       = self::apply( $lang, (string) $r->t, (string) $r->o );
				if ( $new !== (string) $r->t ) {
					$wpdb->update( $tr, array( 'translation' => $new ), array( 'string_id' => (int) $r->id, 'language' => $lang ) ); // phpcs:ignore WordPress.DB
					++$p['changed'];
					$changed_any = true;
				}
			}
			update_option( self::DONE . '_' . $lang, $p, false );
		}
		update_option( self::DONE . '_' . $lang, $p, false );
		delete_transient( 'trrocket_script_busy_' . $lang );
		if ( $changed_any || $p['done'] ) {
			Strings::flush_maps( $lang );
			Cache::flush_later();
		}
		wp_clear_scheduled_hook( self::JOB, array( $lang ) );
		if ( ! $p['done'] ) {
			wp_schedule_single_event( time() + 2, self::JOB, array( $lang ) );
		}
		return $p;
	}
}
