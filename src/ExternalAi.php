<?php
/**
 * Translate with ChatGPT, Gemini, Claude… through a file (1.8.21).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * 24/10/2026 (Federico: «export the site as a translation, take it to ChatGPT or Gemini, rework it and import it back —
 * one language or all; and only what is missing, for whoever wants to complete»). Whoever already pays a chat AI can
 * translate the site with it, with no API key.
 *
 * Export: one language at a time, only the missing sentences or all of them (to review), in parts small enough for a
 * chat; columns id, source, translation_<lang>, page. Import: the file the AI gives back — or its answer pasted —
 * checked row by row (id, source, tags and placeholders, no code, not empty, not left as it was), filling only what is
 * missing or replacing too; in steps of a few seconds on large sites; the whole import undone with one link.
 */
class ExternalAi {

	const PROVIDER = 'external';          // who translated: «External AI (file)»
	const JOB      = 'trrocket_ext_job';  // the import in progress
	const LAST     = 'trrocket_ext_last'; // the outcome of the last import, for the screen
	const BATCHES  = 'trrocket_ext_undo'; // batch id => when, parts, count
	const UNDO     = 'trrocket_ext_undo_';
	const BLOCK    = 200;                 // rows read per round of an import step

	/**
	 * The sentences of a language: in use on the site, readable text (not image addresses), and — for «missing» —
	 * without a translation.
	 *
	 * @return array{0:string,1:array} SQL after FROM, arguments.
	 */
	private static function from( string $lang, string $what ): array {
		$str = Database::strings_table();
		$tra = Database::translations_table();
		$occ = Database::occurrences_table();
		$sql = "{$str} s LEFT JOIN {$tra} t ON t.string_id = s.id AND t.language = %s
			WHERE s.type <> 'image' AND EXISTS ( SELECT 1 FROM {$occ} o WHERE o.string_id = s.id )";
		$args = array( $lang );
		if ( 'missing' === $what ) {
			// 24/10/2026: missing = the TEXT has no translation. The same text can sit in two rows (collected on two
			// pages with a different context); visitors already see the translation of the other row, and the file must
			// not send the AI sentences that are done.
			$sql   .= " AND ( t.id IS NULL OR t.status = 0 OR t.translation IS NULL OR t.translation = '' )
				AND ( s.text_hash IS NULL OR NOT EXISTS ( SELECT 1 FROM {$str} s2 INNER JOIN {$tra} t2 ON t2.string_id = s2.id AND t2.language = %s AND t2.status > 0 AND t2.translation <> '' WHERE s2.text_hash = s.text_hash ) )";
			$args[] = $lang;
		}
		return array( $sql, $args );
	}

	public static function count( string $lang, string $what ): int {
		global $wpdb;
		list( $sql, $args ) = self::from( $lang, $what );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sql}", $args ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One part of the export (size 0 = everything).
	 *
	 * @return array<int,array{0:int,1:string,2:string,3:string}> id, source, translation, page
	 */
	public static function rows( string $lang, string $what, int $part, int $size ): array {
		global $wpdb;
		list( $sql, $args ) = self::from( $lang, $what );
		$occ   = Database::occurrences_table();
		$limit = $size > 0 ? $wpdb->prepare( ' LIMIT %d OFFSET %d', $size, max( 0, $part ) * $size ) : '';
		$res   = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.original, t.translation, t.status, ( SELECT o.url FROM {$occ} o WHERE o.string_id = s.id LIMIT 1 ) AS page FROM {$sql} ORDER BY s.id", $args ) . $limit ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( (array) $res as $r ) {
			$o = (string) $r->original;
			if ( \TranslateRocket\Frontend\Engine::is_noise( $o ) ) {
				continue;
			}
			$out[] = array( (int) $r->id, $o, (int) $r->status > 0 ? (string) $r->translation : '', (string) $r->page );
		}
		return $out;
	}

	/**
	 * Send one part as a CSV download.
	 */
	public static function download( string $lang, string $what, int $part, int $size ): void {
		$rows  = self::rows( $lang, $what, $part, $size );
		$name  = 'translaterocket-' . $lang . '-' . ( 'missing' === $what ? 'missing' : 'all' ) . ( $size > 0 ? '-part' . ( $part + 1 ) : '' ) . '.csv';
		$guard = static function ( $v ) {
			$v = (string) $v;
			return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v; // a cell a spreadsheet would run as a formula
		};
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$out = fopen( 'php://output', 'w' );
		echo "\xEF\xBB\xBF";
		fputcsv( $out, array( 'id', 'source', 'translation_' . $lang, 'page' ), ',', '"', '' );
		foreach ( $rows as $r ) {
			fputcsv( $out, array( $r[0], $guard( $r[1] ), $guard( $r[2] ), $r[3] ), ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the php://output stream of this download.
		exit;
	}

	/**
	 * The words to give the AI with the file.
	 */
	public static function prompt( string $lang, string $what ): string {
		$router = Plugin::instance()->router();
		$from   = Languages::label( (string) $router->default_language() );
		$to     = Languages::label( $lang );
		$col    = 'translation_' . $lang;
		$task   = 'missing' === $what
			/* translators: 1: source language, 2: target language, 3: column name. */
			? sprintf( __( 'The attached CSV file has the texts of my website in %1$s. Translate the «source» column into %2$s and write each translation in the «%3$s» column, on the same row.', 'translate-rocket' ), $from, $to, $col )
			/* translators: 1: source language, 2: target language, 3: column name. */
			: sprintf( __( 'The attached CSV file has the texts of my website in %1$s with their translation into %2$s in the «%3$s» column. Check every translation and correct it where it is wrong, unnatural or missing; leave it as it is when it is right.', 'translate-rocket' ), $from, $to, $col );
		return $task . "\n\n" . __( 'Rules:', 'translate-rocket' ) . "\n"
			. '- ' . __( 'Do not change the «id», «source» and «page» columns, and keep every row, in the same order.', 'translate-rocket' ) . "\n"
			. '- ' . __( 'Keep tags and placeholders exactly as they are: <1>…</1>, <2/>, {{name}}, and anything that starts with a percent sign or an ampersand.', 'translate-rocket' ) . "\n"
			. '- ' . __( 'Do not translate brand names, product codes, addresses and e-mail addresses.', 'translate-rocket' ) . "\n"
			. '- ' . __( 'Translate for a website: natural, short where the original is short, with the same tone.', 'translate-rocket' ) . "\n"
			. '- ' . __( 'Answer with the whole CSV file only (comma separated, UTF-8), nothing before or after it. If it is too long for one answer, give it to me as a file to download.', 'translate-rocket' );
	}

	/* ----------------------------------------------------------------------------------------- import */

	private static function dir(): string {
		$up  = wp_upload_dir();
		$dir = trailingslashit( (string) $up['basedir'] ) . 'trrocket-tmp';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/**
	 * Read the head of the file: delimiter, columns, language.
	 *
	 * @return array{ok:bool,error:string,delim:string,cols:array<string,int>,lang:string,offset:int}
	 */
	public static function head( string $path, string $lang_asked ): array {
		$bad = array( 'ok' => false, 'error' => '', 'delim' => ',', 'cols' => array(), 'lang' => '', 'offset' => 0 );
		$fh  = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			$bad['error'] = __( 'The file could not be read.', 'translate-rocket' );
			return $bad;
		}
		$line = '';
		$seen = 0;
		while ( false !== ( $l = fgets( $fh ) ) && $seen < 30 ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			++$seen;
			$t = trim( preg_replace( '/^\xEF\xBB\xBF/', '', $l ) );
			// an answer pasted from a chat: «Here is the file:», empty lines, the ```csv fence — the header is the line
			// that names the «id» and «translation» columns
			if ( '' === $t || 0 === strpos( $t, '```' ) || ! preg_match( '/(^|[,;\t"\s])id([,;\t"\s]|$)/i', $t ) || false === stripos( $t, 'translation' ) ) {
				continue;
			}
			$line = $t;
			break;
		}
		$offset = (int) ftell( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$delim = ',';
		foreach ( array( ';', "\t", ',' ) as $d ) {
			if ( substr_count( $line, $d ) >= 2 && substr_count( $line, $d ) >= substr_count( $line, $delim ) ) {
				$delim = $d; // Excel in many countries saves with «;»
			}
		}
		$cols = array();
		foreach ( str_getcsv( $line, $delim, '"', '' ) as $i => $c ) {
			$cols[ strtolower( trim( (string) $c, " \t\"'" ) ) ] = $i;
		}
		$targets = array_map( 'strtolower', (array) Plugin::instance()->router()->secondary_languages() );
		$lang    = '';
		$tcol    = -1;
		foreach ( $cols as $name => $i ) {
			if ( preg_match( '/^translation[\s_\-]*([a-z]{2,3}(?:[\-_][a-z]{2,4})?)$/', $name, $m ) ) {
				$lang = str_replace( '_', '-', $m[1] );
				$tcol = $i;
			} elseif ( 'translation' === $name ) {
				$tcol = $i;
			}
		}
		if ( '' !== $lang_asked && 'auto' !== $lang_asked ) {
			$lang = strtolower( $lang_asked );
		}
		if ( ! isset( $cols['id'] ) || $tcol < 0 ) {
			$bad['error'] = __( 'This is not a file exported from here: the «id» and «translation» columns are missing. Give the AI the file as it was downloaded, and ask it to keep the columns.', 'translate-rocket' );
			return $bad;
		}
		if ( '' === $lang || ! in_array( $lang, $targets, true ) ) {
			$bad['error'] = __( 'The language of the file is not one of the site\'s languages: choose it in the list.', 'translate-rocket' );
			return $bad;
		}
		$cols['_tr'] = $tcol;
		return array( 'ok' => true, 'error' => '', 'delim' => $delim, 'cols' => $cols, 'lang' => $lang, 'offset' => $offset );
	}

	/**
	 * Start an import from a file already in place (uploaded or pasted text saved).
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function start( string $tmp_path, string $lang_asked, string $mode ): array {
		$dir  = self::dir();
		$dest = $dir . '/import-' . wp_generate_password( 16, false ) . '.csv';
		if ( ! @copy( $tmp_path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return array( 'ok' => false, 'error' => __( 'The file could not be saved for the import.', 'translate-rocket' ) );
		}
		$h = self::head( $dest, $lang_asked );
		if ( ! $h['ok'] ) {
			wp_delete_file( $dest );
			return array( 'ok' => false, 'error' => $h['error'] );
		}
		$batch = substr( sha1( microtime( true ) . '|' . wp_rand() ), 0, 10 );
		update_option(
			self::JOB,
			array(
				'file'   => $dest,
				'size'   => (int) filesize( $dest ),
				'offset' => $h['offset'],
				'delim'  => $h['delim'],
				'cols'   => $h['cols'],
				'lang'   => $h['lang'],
				'mode'   => 'replace' === $mode ? 'replace' : 'missing',
				'batch'  => $batch,
				'parts'  => 0,
				'n'      => array( 'saved' => 0, 'same' => 0, 'already' => 0, 'unknown' => 0, 'moved' => 0, 'tags' => 0, 'empty' => 0 ),
				'esempi' => array(),
			),
			false
		);
		return array( 'ok' => true, 'error' => '' );
	}

	private static function clean( string $v ): string {
		$v = trim( $v );
		// the apostrophe the export put before «=», «+», «-», «@» so that a spreadsheet does not run the cell
		if ( strlen( $v ) > 1 && "'" === $v[0] && false !== strpos( '=+-@', $v[1] ) ) {
			$v = substr( $v, 1 );
		}
		return $v;
	}

	/**
	 * Work on the import for at most $budget seconds.
	 *
	 * @return array{done:bool,percent:int,saved:int}
	 */
	public static function step( float $budget = 5.0 ): array {
		global $wpdb;
		$job = get_option( self::JOB );
		if ( ! is_array( $job ) || empty( $job['file'] ) || ! file_exists( (string) $job['file'] ) ) {
			delete_option( self::JOB );
			return array( 'done' => true, 'percent' => 100, 'saved' => 0 );
		}
		$t0   = microtime( true );
		$fh   = fopen( (string) $job['file'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fseek( $fh, (int) $job['offset'] );
		$cols = (array) $job['cols'];
		$lang = (string) $job['lang'];
		$str  = Database::strings_table();
		$tra  = Database::translations_table();
		$done = false;
		while ( microtime( true ) - $t0 < $budget ) {
			$block = array();
			while ( count( $block ) < self::BLOCK && false !== ( $r = fgetcsv( $fh, 0, (string) $job['delim'], '"', '' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				if ( null === $r || array( null ) === $r || count( $r ) < 2 ) {
					continue; // empty lines, the closing ``` and a sentence of the chat after the file
				}
				$block[] = $r;
			}
			if ( ! $block ) {
				$done = true;
				break;
			}
			$ids = array();
			foreach ( $block as $r ) {
				$id = (int) self::clean( (string) ( $r[ $cols['id'] ] ?? '' ) );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			$orig = array();
			$prev = array();
			if ( $ids ) {
				$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, original FROM {$str} WHERE id IN ({$ph})", $ids ) ) as $o ) { // phpcs:ignore WordPress.DB
					$orig[ (int) $o->id ] = (string) $o->original;
				}
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT string_id, translation, status, provider FROM {$tra} WHERE language = %s AND string_id IN ({$ph})", array_merge( array( $lang ), $ids ) ) ) as $p ) { // phpcs:ignore WordPress.DB -- trr-ciclo-ok: one query per block of 200 rows, inside a step of a few seconds
					$prev[ (int) $p->string_id ] = array( (string) $p->translation, (int) $p->status, (string) $p->provider );
				}
			}
			$undo = array();
			foreach ( $block as $r ) {
				$id  = (int) self::clean( (string) ( $r[ $cols['id'] ] ?? '' ) );
				$src = isset( $cols['source'] ) ? self::clean( (string) ( $r[ $cols['source'] ] ?? '' ) ) : '';
				$tr  = self::clean( (string) ( $r[ $cols['_tr'] ] ?? '' ) );
				$why = '';
				if ( $id <= 0 || ! isset( $orig[ $id ] ) ) {
					$why = 'unknown';
				} elseif ( '' !== $src && self::norm( $src ) !== self::norm( $orig[ $id ] ) ) {
					$why = 'moved'; // the AI moved rows or changed the source: the translation would go to another sentence
				} elseif ( '' === $tr ) {
					$why = 'empty';
				} elseif ( self::norm( $tr ) === self::norm( $orig[ $id ] ) && preg_match( '/\p{Ll}{3,}/u', $orig[ $id ] ) ) {
					$why = 'same'; // left as it was (a name alone in capitals is fine)
				} else {
					$old = $prev[ $id ] ?? null;
					$has = $old && $old[1] > 0 && '' !== trim( $old[0] );
					if ( $has && 'missing' === $job['mode'] ) {
						$why = 'already';
					} elseif ( $has && self::norm( $old[0] ) === self::norm( $tr ) ) {
						$why = 'already'; // nothing changes
					} elseif ( ! Strings::save_translation( $id, $lang, $tr, 1, self::PROVIDER ) ) {
						$why = 'tags'; // tags or placeholders of the original lost, or code that was not there
					} else {
						$undo[] = array( $id, $old ? $old[0] : '', $old ? $old[1] : 0, $old ? $old[2] : '' );
						++$job['n']['saved'];
					}
				}
				if ( '' !== $why ) {
					++$job['n'][ $why ];
					if ( 'already' !== $why && 'empty' !== $why && count( $job['esempi'] ) < 12 ) {
						$job['esempi'][] = array( $why, $id, mb_substr( '' !== $src ? $src : ( $orig[ $id ] ?? '' ), 0, 80 ) );
					}
				}
			}
			if ( $undo ) {
				update_option( self::UNDO . $job['batch'] . '_' . $job['parts'], $undo, false );
				++$job['parts'];
			}
			$job['offset'] = (int) ftell( $fh );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $done ) {
			wp_delete_file( (string) $job['file'] );
			delete_option( self::JOB );
			Strings::flush_maps( $lang );
			if ( $job['n']['saved'] ) {
				Cache::flush();
				$all = (array) get_option( self::BATCHES, array() );
				$all = array( $job['batch'] => array( 'when' => time(), 'lang' => $lang, 'parts' => (int) $job['parts'], 'count' => (int) $job['n']['saved'] ) ) + array_slice( $all, 0, 4, true );
				update_option( self::BATCHES, $all, false );
			}
			update_option( self::LAST, array( 'lang' => $lang, 'mode' => $job['mode'], 'n' => $job['n'], 'esempi' => $job['esempi'], 'batch' => $job['n']['saved'] ? $job['batch'] : '', 'when' => time() ), false );
		} else {
			update_option( self::JOB, $job, false );
		}
		$size = max( 1, (int) $job['size'] );
		return array( 'done' => $done, 'percent' => $done ? 100 : min( 99, (int) floor( 100 * (int) $job['offset'] / $size ) ), 'saved' => (int) $job['n']['saved'] );
	}

	private static function norm( string $s ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $s, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Undo an import, a part at a time.
	 *
	 * @return array{done:bool,percent:int,restored:int}
	 */
	public static function undo_step( string $batch, float $budget = 5.0 ): array {
		global $wpdb;
		$all = (array) get_option( self::BATCHES, array() );
		if ( ! isset( $all[ $batch ] ) ) {
			return array( 'done' => true, 'percent' => 100, 'restored' => 0 );
		}
		$info  = $all[ $batch ];
		$lang  = (string) $info['lang'];
		$parts = (int) $info['parts'];
		$next  = (int) ( $info['undone'] ?? 0 );
		$n     = (int) ( $info['restored'] ?? 0 );
		$tra   = Database::translations_table();
		$t0    = microtime( true );
		while ( $next < $parts && microtime( true ) - $t0 < $budget ) {
			foreach ( (array) get_option( self::UNDO . $batch . '_' . $next, array() ) as $r ) {
				if ( '' === (string) $r[1] && 0 === (int) $r[2] ) {
					$wpdb->delete( $tra, array( 'string_id' => (int) $r[0], 'language' => $lang ) ); // phpcs:ignore WordPress.DB
				} else {
					$wpdb->replace( $tra, array( 'string_id' => (int) $r[0], 'language' => $lang, 'translation' => (string) $r[1], 'status' => (int) $r[2], 'provider' => (string) $r[3], 'updated_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
				}
				++$n;
			}
			delete_option( self::UNDO . $batch . '_' . $next );
			++$next;
		}
		$done = $next >= $parts;
		if ( $done ) {
			unset( $all[ $batch ] );
			Strings::flush_maps( $lang );
			Cache::flush();
			$last = get_option( self::LAST );
			if ( is_array( $last ) && ( $last['batch'] ?? '' ) === $batch ) {
				$last['batch']  = '';
				$last['undone'] = $n;
				update_option( self::LAST, $last, false );
			}
		} else {
			$all[ $batch ] = array_merge( $info, array( 'undone' => $next, 'restored' => $n ) );
		}
		update_option( self::BATCHES, $all, false );
		return array( 'done' => $done, 'percent' => $done ? 100 : (int) floor( 100 * $next / max( 1, $parts ) ), 'restored' => $n );
	}
}
