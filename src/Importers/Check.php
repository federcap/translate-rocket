<?php
/**
 * The check after an import.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Database;
use TranslateRocket\Frontend\InlineText;

defined( 'ABSPATH' ) || exit;

/**
 * Rereads the translations that came from another plugin and lists the ones that are plainly wrong.
 *
 * No AI and no key: it cannot tell a good translation from a mediocre one, only spot the evident
 * mistakes an import brings along — seen on real sites on 30/09/2026: a Spanish block in the English
 * copy, «A�adir» and «????? ???» left by a broken encoding, a sentence still in the source language,
 * a link lost on the way. Nothing is changed by itself: the owner keeps or removes each one.
 *
 * Every importer goes through Strings::store_imported(), which marks the rows «import»; the check
 * reads those, as long as nobody has edited them by hand since (status 1).
 */
final class Check {

	const REPORT    = 'trrocket_import_check';
	const DUE       = 'trrocket_import_check_due';
	const KEPT      = 'trrocket_import_check_kept';
	const ACTION    = 'trrocket_import_check';
	const MAX_ROWS  = 50000;
	const MAX_SHOWN = 300;

	/**
	 * The writing system of languages that have their own: a translation into one of them with none
	 * of its letters is not a translation into it.
	 */
	const SCRIPTS = array(
		'ar' => '\p{Arabic}',
		'fa' => '\p{Arabic}',
		'ur' => '\p{Arabic}',
		'ps' => '\p{Arabic}',
		'he' => '\p{Hebrew}',
		'yi' => '\p{Hebrew}',
		'zh' => '\p{Han}',
		'zh-tw' => '\p{Han}',
		'ja' => '[\p{Hiragana}\p{Katakana}\p{Han}]',
		'ko' => '\p{Hangul}',
		'ru' => '\p{Cyrillic}',
		'uk' => '\p{Cyrillic}',
		'bg' => '\p{Cyrillic}',
		'sr' => '\p{Cyrillic}',
		'mk' => '\p{Cyrillic}',
		'be' => '\p{Cyrillic}',
		'kk' => '\p{Cyrillic}',
		'el' => '\p{Greek}',
		'hi' => '\p{Devanagari}',
		'mr' => '\p{Devanagari}',
		'ne' => '\p{Devanagari}',
		'bn' => '\p{Bengali}',
		'th' => '\p{Thai}',
		'ka' => '\p{Georgian}',
		'hy' => '\p{Armenian}',
		'ta' => '\p{Tamil}',
		'te' => '\p{Telugu}',
		'gu' => '\p{Gujarati}',
		'pa' => '\p{Gurmukhi}',
		'km' => '\p{Khmer}',
		'am' => '\p{Ethiopic}',
	);

	/** Set when this request stored imported translations (see mark()). */
	private static $imported = false;

	/**
	 * Hook into WordPress.
	 */
	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Strings::store_imported() calls this: once the request is over, the next visit to the Import
	 * screen runs the check (one option write per import, not one per row).
	 */
	public static function mark(): void {
		if ( self::$imported ) {
			return;
		}
		self::$imported = true;
		add_action(
			'shutdown',
			static function () {
				update_option( self::DUE, 1, false );
			}
		);
	}

	/* ------------------------------------------------------------- the checks */

	/**
	 * Why a translation is plainly wrong, or '' when nothing evident is.
	 *
	 * @param string $o    Source text.
	 * @param string $t    Translation.
	 * @param string $lang Target language code.
	 */
	public static function why( string $o, string $t, string $lang ): string {
		$lang = strtolower( $lang );
		$tt   = wp_strip_all_tags( InlineText::has_parts( $t ) ? (string) preg_replace( '#</?\d+/?>#', ' ', $t ) : $t );
		// Characters broken on the way: a UTF-8 text read as Latin-1 («Ã©»), replacement marks, a
		// language the database could not store turned into question marks, Google's «XNUMX».
		if ( preg_match( '/\x{00C3}[\x{0080}-\x{00BF}]|\x{00C2}[\x{0080}-\x{00BF}]|\x{FFFD}|\?{3,}|XNUMX/u', $tt ) ) {
			return 'broken';
		}
		$lettere = preg_match_all( '/\p{L}/u', $tt );
		if ( isset( self::SCRIPTS[ $lang ] ) || isset( self::SCRIPTS[ substr( $lang, 0, 2 ) ] ) ) {
			$re = self::SCRIPTS[ $lang ] ?? self::SCRIPTS[ substr( $lang, 0, 2 ) ];
			if ( $lettere >= 3 && ! preg_match( '/' . $re . '/u', $tt ) && preg_match( '/\p{L}{3}/u', $o ) ) {
				// Latin letters only: a brand name alone (one or two words) is fine, a sentence is not.
				if ( str_word_count( remove_accents( $tt ) ) >= 3 ) {
					return 'script';
				}
			}
		}
		// Still the source sentence (four words or more: a name or a label may stay as it is).
		if ( self::norm( $o ) === self::norm( $t ) && str_word_count( remove_accents( $tt ) ) >= 4 ) {
			return 'same';
		}
		if ( InlineText::has_parts( $o ) && ! InlineText::parts_ok( $o, $t ) ) {
			return 'links';
		}
		if ( self::numbers( $o ) !== self::numbers( $t ) ) {
			return 'numbers';
		}
		$lo = mb_strlen( $o );
		$lt = mb_strlen( $t );
		if ( $lo > 12 && ( $lt > 4 * $lo || $lt * 4 < $lo ) ) {
			return 'length';
		}
		$scritta = self::guess_language( $t );
		if ( '' !== $scritta && $scritta !== substr( $lang, 0, 2 ) ) {
			return 'language';
		}
		return '';
	}

	/**
	 * The language a sentence of five words or more is plainly written in, from its commonest words;
	 * '' when unsure (short text, other scripts, no clear winner).
	 */
	public static function guess_language( string $t ): string {
		$parole = preg_split( '/[^\p{L}\']+/u', mb_strtolower( wp_strip_all_tags( $t ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $parole ) < 5 ) {
			return '';
		}
		static $comuni = array(
			'en' => 'the and of to in is for with that you your are on this from our be it as at by',
			'it' => 'il di che la per non una sono della del le con un gli nel alla anche questo delle più',
			'fr' => 'le la les des et est pour une dans du que en vous sur avec pas nous au sont votre',
			'de' => 'der die und das ist nicht mit den für sie auf ein eine zu von dem sich auch wir ihre',
			'es' => 'el la los las de que y en para por una con es del su se al como más tu',
			'pt' => 'o os as de que e em para com uma um do da no na por não se mais seu',
			'nl' => 'de het een en van in is op te voor met zijn niet dat je wij ook naar bij',
			'pl' => 'i w nie na się z do że jest to o jak dla od po przez są oraz czy',
		);
		$punti = array();
		foreach ( $comuni as $l => $lista ) {
			$set = array_flip( explode( ' ', $lista ) );
			$n   = 0;
			foreach ( $parole as $p ) {
				if ( isset( $set[ $p ] ) ) {
					++$n;
				}
			}
			$punti[ $l ] = $n;
		}
		arsort( $punti );
		$primi = array_slice( $punti, 0, 2, true );
		$l1    = (string) key( $primi );
		$n1    = (int) reset( $primi );
		$n2    = (int) next( $primi );
		return $n1 >= 3 && $n1 >= 2 * max( 1, $n2 ) ? $l1 : '';
	}

	/**
	 * The numbers in a text (dates, prices, codes), in order of size: «1.000» and «1,000» alike.
	 *
	 * @return string[]
	 */
	public static function numbers( string $t ): array {
		preg_match_all( '/\d+/u', $t, $m );
		$n = $m[0];
		sort( $n );
		return $n;
	}

	private static function norm( string $x ): string {
		$x = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '#</?\d+/?>#', '', $x ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $x ) ) );
	}

	/* ------------------------------------------------------------- the run */

	/**
	 * Check every imported translation nobody has edited since; the result is kept for the screen.
	 *
	 * @return array{when:int,checked:int,items:array<int,array<string,string|int>>,more:int}
	 */
	public static function run(): array {
		global $wpdb;
		$t     = Database::translations_table();
		$s     = Database::strings_table();
		$kept  = array_flip( array_map( 'intval', (array) get_option( self::KEPT, array() ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT tr.id, tr.language, tr.translation, st.original FROM {$t} tr INNER JOIN {$s} st ON st.id = tr.string_id WHERE tr.provider = 'import' AND tr.status = 1 AND tr.translation <> '' ORDER BY tr.id LIMIT %d", self::MAX_ROWS ) ); // phpcs:ignore WordPress.DB
		$items = array();
		$more  = 0;
		foreach ( (array) $rows as $r ) {
			if ( isset( $kept[ (int) $r->id ] ) ) {
				continue;
			}
			$why = self::why( (string) $r->original, (string) $r->translation, (string) $r->language );
			if ( '' === $why ) {
				continue;
			}
			if ( count( $items ) >= self::MAX_SHOWN ) {
				++$more;
				continue;
			}
			$items[] = array(
				'id'  => (int) $r->id,
				'l'   => (string) $r->language,
				'o'   => mb_substr( (string) $r->original, 0, 300 ),
				't'   => mb_substr( (string) $r->translation, 0, 300 ),
				'why' => $why,
			);
		}
		$rep = array(
			'when'    => time(),
			'checked' => count( (array) $rows ),
			'items'   => $items,
			'more'    => $more,
		);
		update_option( self::REPORT, $rep, false );
		delete_option( self::DUE );
		return $rep;
	}

	private static function why_text( string $w ): string {
		switch ( $w ) {
			case 'broken':
				return __( 'broken characters', 'translate-rocket' );
			case 'script':
				return __( 'not written in this language’s alphabet', 'translate-rocket' );
			case 'same':
				return __( 'still in the original language', 'translate-rocket' );
			case 'links':
				return __( 'links or bold words lost', 'translate-rocket' );
			case 'numbers':
				return __( 'different numbers from the original', 'translate-rocket' );
			case 'length':
				return __( 'far longer or shorter than the original', 'translate-rocket' );
			case 'language':
				return __( 'written in another language', 'translate-rocket' );
		}
		return $w;
	}

	/* ------------------------------------------------------------- the owner */

	/**
	 * Keep, remove, remove all, check again (a form on the Import screen).
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'translate-rocket' ) );
		}
		check_admin_referer( self::ACTION );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$rep = get_option( self::REPORT );
		$rep = is_array( $rep ) ? $rep : array( 'items' => array() );
		global $wpdb;
		if ( 'keep' === $do && $id > 0 ) {
			$kept   = (array) get_option( self::KEPT, array() );
			$kept[] = $id;
			update_option( self::KEPT, array_values( array_unique( array_map( 'intval', $kept ) ) ), false );
			$rep['items'] = array_values( array_filter( (array) $rep['items'], static function ( $x ) use ( $id ) {
				return (int) $x['id'] !== $id;
			} ) );
			update_option( self::REPORT, $rep, false );
		} elseif ( ( 'remove' === $do && $id > 0 ) || 'remove_all' === $do ) {
			$ids = 'remove_all' === $do ? array_map( 'intval', wp_list_pluck( (array) $rep['items'], 'id' ) ) : array( $id );
			if ( $ids ) {
				// Only rows that are still an untouched import: a hand edit made meanwhile stays.
				$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				\TranslateRocket\Strings::deleting( "tr.provider = 'import' AND tr.status = 1 AND tr.id IN ({$ph})", $ids, 'import-check' );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::translations_table() . " WHERE provider = 'import' AND status = 1 AND id IN ({$ph})", $ids ) ); // phpcs:ignore WordPress.DB
				$lingue = array();
				foreach ( (array) $rep['items'] as $x ) {
					if ( in_array( (int) $x['id'], $ids, true ) ) {
						$lingue[ (string) $x['l'] ] = true;
					}
				}
				foreach ( array_keys( $lingue ) as $l ) {
					\TranslateRocket\Strings::flush_maps( $l );
				}
				if ( class_exists( '\\TranslateRocket\\Cache' ) ) {
					\TranslateRocket\Cache::flush();
				}
			}
			$rep['items'] = array_values( array_filter( (array) $rep['items'], static function ( $x ) use ( $ids ) {
				return ! in_array( (int) $x['id'], $ids, true );
			} ) );
			update_option( self::REPORT, $rep, false );
		} elseif ( 'run' === $do ) {
			self::run();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=translate-rocket-import#trrocket-check' ) );
		exit;
	}

	/**
	 * The box on the Import screen: runs the check first when an import happened since the last one.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::DUE ) ) {
			self::run();
		}
		$rep = get_option( self::REPORT );
		if ( ! is_array( $rep ) ) {
			return; // nothing imported yet: nothing to say
		}
		$items = (array) ( $rep['items'] ?? array() );
		$form  = static function ( string $do, string $label, int $id = 0, string $class = 'button button-small' ): string {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
				. '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
				. ( $id ? '<input type="hidden" name="id" value="' . (int) $id . '">' : '' )
				. wp_nonce_field( self::ACTION, '_wpnonce', true, false )
				. '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
		};
		$ok = empty( $items ) && empty( $rep['more'] );
		echo '<div id="trrocket-check" class="trrocket-card" style="border-left:4px solid ' . ( $ok ? '#00a32a' : '#dba617' ) . '">';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Check after import', 'translate-rocket' ) . '</h2>';
		/* translators: 1: date and time, 2: number of imported translations checked */
		echo '<p>' . esc_html( sprintf( __( '%1$s: %2$d imported translations reread, without AI — wrong language or alphabet, broken characters, sentences still in the original, lost links, different numbers.', 'translate-rocket' ), wp_date( 'j M H:i', (int) $rep['when'] ), (int) $rep['checked'] ) ) . '</p>';
		if ( $ok ) {
			echo '<p style="color:#00a32a;font-weight:600">' . esc_html__( 'Nothing evidently wrong.', 'translate-rocket' ) . '</p>';
		} else {
			$n = count( $items ) + (int) ( $rep['more'] ?? 0 );
			/* translators: %d: number of suspicious translations */
			echo '<p style="font-weight:600">' . esc_html( sprintf( _n( '%d translation looks wrong. Keep it if it is fine, or remove it: the sentence goes back to «missing» and is translated again like any other.', '%d translations look wrong. Keep the ones that are fine, remove the others: those sentences go back to «missing» and are translated again like any other.', $n, 'translate-rocket' ), $n ) ) . '</p>';
			// On a phone each row becomes a small card, with its two buttons underneath.
			echo '<style>@media (max-width:782px){#trrocket-check table,#trrocket-check tbody,#trrocket-check tr,#trrocket-check td{display:block;width:auto}#trrocket-check thead{display:none}#trrocket-check tr{padding:8px 0;border-bottom:1px solid #dcdcde}#trrocket-check td{border:0;padding:2px 10px}#trrocket-check td:first-child{font-weight:700}#trrocket-check td.trr-chk-why{color:#8a6d00}#trrocket-check td.trr-chk-do{padding-top:6px}}</style>';
			echo '<table class="widefat striped" style="margin:8px 0"><thead><tr><th>' . esc_html__( 'Language', 'translate-rocket' ) . '</th><th>' . esc_html__( 'Original', 'translate-rocket' ) . '</th><th>' . esc_html__( 'Imported translation', 'translate-rocket' ) . '</th><th>' . esc_html__( 'Why', 'translate-rocket' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $items as $x ) {
				echo '<tr><td translate="no">' . esc_html( strtoupper( (string) $x['l'] ) ) . '</td><td>' . esc_html( (string) $x['o'] ) . '</td><td translate="no">' . esc_html( (string) $x['t'] ) . '</td><td class="trr-chk-why">' . esc_html( self::why_text( (string) $x['why'] ) ) . '</td><td class="trr-chk-do" style="white-space:nowrap">'
					. $form( 'keep', __( 'Keep', 'translate-rocket' ), (int) $x['id'] ) . ' '
					. $form( 'remove', __( 'Remove', 'translate-rocket' ), (int) $x['id'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
					. '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
			if ( ! empty( $rep['more'] ) ) {
				/* translators: %d: suspicious translations not listed */
				echo '<p class="description">' . esc_html( sprintf( __( '…and %d more: remove or keep these, then check again.', 'translate-rocket' ), (int) $rep['more'] ) ) . '</p>';
			}
			echo '<p>' . $form( 'remove_all', __( 'Remove all the listed ones', 'translate-rocket' ), 0, 'button' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<p style="margin-bottom:0">' . $form( 'run', __( 'Check again', 'translate-rocket' ), 0, 'button button-small' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}
}
