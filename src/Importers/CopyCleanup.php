<?php
/**
 * Tidy up the per-language pages left behind by Polylang, WPML or Bogo.
 *
 * Those plugins keep one post per language. After their translations are imported,
 * TranslateRocket translates the original page itself, so every copy becomes a
 * second place to edit the same page — and a second page Google can find. This
 * class looks at each copy and says what is safe to do with it:
 *
 *  - ready        everything the copy says is already in TranslateRocket, so it
 *                 can go to the Trash;
 *  - partial      its body is laid out differently from the original, so it was
 *                 never paired line by line: keep it as an independent copy;
 *  - not_imported some of its text is not in TranslateRocket yet: import first;
 *  - lang_off     its language is not active in TranslateRocket: trashing it
 *                 would take that language off the site.
 *
 * Nothing is ever deleted. Copies go to the Trash (restorable), and their old
 * address is remembered so visitors and search engines are sent, with a 301, to
 * the translated page (see Frontend\MovedCopies).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Cache;
use TranslateRocket\Copies;
use TranslateRocket\Plugin;
use TranslateRocket\Slugs;
use TranslateRocket\Strings;

defined( 'ABSPATH' ) || exit;

/**
 * Review and apply the clean-up of another plugin's per-language copies.
 */
class CopyCleanup {

	/** Old copy path (base-relative, no slashes around) => array( source id, language ). */
	const OPTION_MOVED = 'trrocket_moved_copies';

	/** Oldest addresses are forgotten past this many, so the option stays small. */
	const MAX_MOVED = 5000;

	/** Statuses a copy can be in and still be worth listing. */
	const LISTED_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * How many copies are still there to review (cheap: no text comparison).
	 */
	public static function open_count( ProvidesCopies $importer ): int {
		$n = 0;
		foreach ( self::pairs( $importer ) as $pair ) {
			if ( null !== $pair ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * One row per copy still to review.
	 *
	 * @param bool $details Also look for menus and links pointing at the copy
	 *                      (for the review screen; not needed to apply choices).
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( ProvidesCopies $importer, bool $details = false ): array {
		$pairs  = array_values( array_filter( self::pairs( $importer ) ) );
		$active = Plugin::instance()->router()->secondary_languages();

		// Links between copies don't count: those pages are being reviewed too.
		$copy_ids = array();
		foreach ( $pairs as $pair ) {
			$copy_ids[ (int) $pair['copy']->ID ] = true;
		}

		$rows = array();
		foreach ( $pairs as $pair ) {
			$source = $pair['source'];
			$copy   = $pair['copy'];
			$lang   = $pair['lang'];

			$check = self::compare( $source, $copy, $lang );
			if ( ! in_array( $lang, $active, true ) ) {
				$status = 'lang_off';
			} elseif ( $check['missing'] > 0 ) {
				$status = 'not_imported';
			} elseif ( ! $check['paired'] ) {
				$status = 'partial';
			} else {
				$status = 'ready';
			}

			$rows[] = array(
				'source'   => (int) $source->ID,
				'copy'     => (int) $copy->ID,
				'lang'     => $lang,
				'status'   => $status,
				'missing'  => $check['missing'],
				'has_copy' => Copies::copy_id( (int) $source->ID, $lang ) > 0,
				'menus'    => $details ? self::menus( (int) $copy->ID ) : array(),
				'links'    => $details ? self::links( $copy, $copy_ids ) : 0,
			);
		}
		return $rows;
	}

	/**
	 * Apply the owner's choices. Every choice is checked again against a fresh
	 * review, so a copy that changed since the screen was loaded is skipped rather
	 * than trashed on stale information.
	 *
	 * @param array<int,string> $choices Copy id => 'trash' | 'adopt'.
	 * @return array{trashed:int,adopted:int,skipped:int}
	 */
	public static function apply( ProvidesCopies $importer, array $choices ): array {
		$done = array(
			'trashed' => 0,
			'adopted' => 0,
			'skipped' => 0,
		);
		if ( empty( $choices ) ) {
			return $done;
		}

		$rows = array();
		foreach ( self::rows( $importer ) as $row ) {
			$rows[ $row['copy'] ] = $row;
		}

		$moved = get_option( self::OPTION_MOVED, array() );
		$moved = is_array( $moved ) ? $moved : array();

		foreach ( $choices as $copy_id => $choice ) {
			$copy_id = (int) $copy_id;
			$row     = $rows[ $copy_id ] ?? null;
			if ( null === $row ) {
				++$done['skipped'];
				continue;
			}

			$was_public = 'publish' === get_post_status( $copy_id );
			$path       = $was_public ? self::path( $copy_id ) : '';

			if ( 'trash' === $choice ) {
				if ( 'ready' !== $row['status'] || ! current_user_can( 'delete_post', $copy_id ) || ! wp_trash_post( $copy_id ) ) {
					++$done['skipped'];
					continue;
				}
				++$done['trashed'];
			} elseif ( 'adopt' === $choice ) {
				if ( 'lang_off' === $row['status'] || $row['has_copy'] || ! current_user_can( 'edit_post', $copy_id )
					|| ! Copies::adopt( $row['source'], $copy_id, $row['lang'] ) ) {
					++$done['skipped'];
					continue;
				}
				++$done['adopted'];
			} else {
				continue;
			}

			if ( '' !== $path ) {
				unset( $moved[ $path ] );   // re-added last, so it's the newest
				$moved[ $path ] = array( $row['source'], $row['lang'] );
			}
		}

		if ( count( $moved ) > self::MAX_MOVED ) {
			$moved = array_slice( $moved, -self::MAX_MOVED, null, true );
		}
		update_option( self::OPTION_MOVED, $moved, false );
		Cache::flush();

		return $done;
	}

	/**
	 * Where a remembered old address should now lead: the original page in that
	 * language, with its translated slug. '' when there is nowhere sensible to go.
	 */
	public static function translated_url( int $source_id, string $lang ): string {
		$post = get_post( $source_id );
		if ( ! ( $post instanceof \WP_Post ) || 'publish' !== $post->post_status ) {
			return '';
		}
		$router = Plugin::instance()->router();
		if ( ! in_array( $lang, $router->public_languages(), true ) || $router->is_default( $lang ) ) {
			return '';
		}

		$path = $router->canonical_path( $source_id );
		$slug = Slugs::get( $source_id, $lang );
		if ( '' !== $slug && '' !== (string) $post->post_name ) {
			$path = (string) preg_replace( '#/' . preg_quote( (string) $post->post_name, '#' ) . '(/?)$#', '/' . $slug . '$1', $path );
		}
		return rtrim( $router->home_for_language( $lang ), '/' ) . $path;
	}

	/**
	 * Source/copy post pairs, with null for copies that are gone, already in the
	 * Trash, already one of our independent copies, or not a page-like post.
	 *
	 * @return array<int,array{source:\WP_Post,copy:\WP_Post,lang:string}|null>
	 */
	private static function pairs( ProvidesCopies $importer ): array {
		$groups = $importer->copy_groups();

		$ids = array();
		foreach ( $groups as $group ) {
			$ids[] = (int) $group['source'];
			foreach ( $group['copies'] as $copy_id ) {
				$ids[] = (int) $copy_id;
			}
		}
		if ( ! empty( $ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_unique( $ids ), false, true );
		}

		$out = array();
		foreach ( $groups as $group ) {
			$source = get_post( (int) $group['source'] );
			$usable = $source instanceof \WP_Post && self::listable( $source );
			foreach ( $group['copies'] as $lang => $copy_id ) {
				$copy = get_post( (int) $copy_id );
				if ( ! $usable || ! ( $copy instanceof \WP_Post ) || (int) $copy->ID === (int) $source->ID
					|| ! self::listable( $copy ) || Copies::is_copy( (int) $copy->ID ) ) {
					$out[] = null;
					continue;
				}
				$out[] = array(
					'source' => $source,
					'copy'   => $copy,
					'lang'   => (string) $lang,
				);
			}
		}
		return $out;
	}

	/**
	 * A post we are willing to list: a real, viewable page or post — never media
	 * (Polylang and WPML can translate attachments too), never something in the Trash.
	 */
	private static function listable( \WP_Post $post ): bool {
		return 'attachment' !== $post->post_type
			&& in_array( $post->post_status, self::LISTED_STATUSES, true )
			&& is_post_type_viewable( $post->post_type );
	}

	/**
	 * Is everything the copy says already in TranslateRocket?
	 *
	 * Compares exactly what the importers pair — title, excerpt, and the body
	 * line by line when both sides have the same number of lines — and asks the
	 * translation memory for each original whose copy says something different.
	 *
	 * @return array{missing:int,paired:bool}
	 */
	private static function compare( \WP_Post $source, \WP_Post $copy, string $lang ): array {
		$pairs = array(
			array( (string) $source->post_title, (string) $copy->post_title ),
			array( (string) $source->post_excerpt, (string) $copy->post_excerpt ),
		);

		$paired = true;
		$a      = Comune::righe( (string) $source->post_content );
		$b      = Comune::righe( (string) $copy->post_content );
		if ( count( $a ) === count( $b ) ) {
			foreach ( $a as $i => $line ) {
				$pairs[] = array( $line, $b[ $i ] );
			}
		} elseif ( ! empty( $b ) ) {
			// The copy has text we could not match to the original: trashing it
			// would lose that text.
			$paired = false;
		}

		$wanted = array();
		foreach ( $pairs as $pair ) {
			$original = trim( $pair[0] );
			$text     = trim( $pair[1] );
			if ( '' !== $original && '' !== $text && $original !== $text ) {
				$wanted[ $original ] = true;
			}
		}

		$missing = 0;
		if ( ! empty( $wanted ) ) {
			$found   = Strings::translate_texts( array_keys( $wanted ), $lang );
			$missing = count( array_diff_key( $wanted, $found ) );
		}

		return array(
			'missing' => $missing,
			'paired'  => $paired,
		);
	}

	/**
	 * Names of the menus with an item pointing at this post.
	 *
	 * @return string[]
	 */
	private static function menus( int $post_id ): array {
		global $wpdb;
		$items = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT o.post_id FROM {$wpdb->postmeta} o
				 INNER JOIN {$wpdb->postmeta} t ON t.post_id = o.post_id AND t.meta_key = '_menu_item_type' AND t.meta_value = 'post_type'
				 WHERE o.meta_key = '_menu_item_object_id' AND o.meta_value = %s",
				(string) $post_id
			)
		); // phpcs:ignore WordPress.DB

		$names = array();
		foreach ( $items as $item ) {
			$terms = wp_get_object_terms( (int) $item, 'nav_menu', array( 'fields' => 'names' ) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $name ) {
					$names[ (string) $name ] = true;
				}
			}
		}
		return array_keys( $names );
	}

	/**
	 * How many published posts (other than the copies under review) link to the
	 * copy's address. Only a hint for the owner: those links keep working through
	 * the redirect.
	 *
	 * @param array<int,bool> $copy_ids Ids of every copy under review.
	 */
	private static function links( \WP_Post $copy, array $copy_ids ): int {
		if ( '' === (string) $copy->post_name ) {
			return 0;
		}
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND post_type NOT IN ('revision','nav_menu_item','attachment')
				   AND post_content LIKE %s",
				'%' . $wpdb->esc_like( '/' . $copy->post_name . '/' ) . '%'
			)
		); // phpcs:ignore WordPress.DB

		$n = 0;
		foreach ( $ids as $id ) {
			if ( ! isset( $copy_ids[ (int) $id ] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * A post's current address, base-relative, without the slashes around it
	 * ('' for the front page or plain ?page_id= links, which we don't redirect).
	 */
	private static function path( int $post_id ): string {
		$path = trim( Plugin::instance()->router()->canonical_path( $post_id ), '/' );
		return false === strpos( $path, '?' ) ? $path : '';
	}
}
