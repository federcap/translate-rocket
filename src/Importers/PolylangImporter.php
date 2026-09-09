<?php
/**
 * Import translations from Polylang.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Strings;
use TranslateRocket\Languages;
use TranslateRocket\Slugs;
use TranslateRocket\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Polylang uses a content-level model (a separate post per language) plus
 * registered string translations stored in a language term meta. We import the
 * parts that map cleanly onto our string-level model:
 *  - registered string translations (term meta _pll_strings_translations),
 *  - translated post titles, excerpts and slugs,
 *  - body text, paired by position only when both posts share the same structure
 *    (best effort; unmatched pairs are simply never used, so it's harmless).
 */
class PolylangImporter implements ImporterInterface {

	public function id(): string {
		return 'polylang';
	}

	public function label(): string {
		return 'Polylang';
	}

	/**
	 * Language slug => term id (read straight from the taxonomy tables so it
	 * works even while Polylang is deactivated).
	 *
	 * @return array<string,int>
	 */
	private function languages(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT t.term_id AS id, t.slug AS slug
			 FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE tt.taxonomy = 'language'"
		); // phpcs:ignore WordPress.DB
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row->slug ] = (int) $row->id;
		}
		return $out;
	}

	private function default_lang(): string {
		$opt = get_option( 'polylang' );
		return ( is_array( $opt ) && ! empty( $opt['default_lang'] ) ) ? (string) $opt['default_lang'] : '';
	}

	public function is_available(): bool {
		return count( $this->languages() ) >= 2 && '' !== $this->default_lang();
	}

	/**
	 * Map a Polylang language slug to our short code.
	 */
	private function map_lang( string $slug ): string {
		$slug = strtolower( $slug );
		$map  = array(
			'pt_br' => 'pt-br',
			'pt-br' => 'pt-br',
			'zh_cn' => 'zh',
			'zh_tw' => 'zh-tw',
		);
		if ( isset( $map[ $slug ] ) ) {
			return $map[ $slug ];
		}
		return strlen( $slug ) > 2 ? substr( $slug, 0, 2 ) : $slug;
	}

	/**
	 * Split HTML content into comparable plain-text chunks (one per block/line).
	 *
	 * @return string[]
	 */
	private function chunks( string $html ): array {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$html = (string) preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#</(p|h[1-6]|li|div|blockquote|figcaption|td|th)>#i', "\n", $html );
		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$text = wp_strip_all_tags( $html );

		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && preg_match( '/\p{L}/u', $line ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Unserialize a Polylang translation-group value safely — never instantiate
	 * objects (guards against PHP object injection via a tampered term row).
	 *
	 * @param mixed $value Raw term description.
	 * @return array<string,mixed>
	 */
	private function unserialize_map( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && is_serialized( $value ) ) {
			$out = unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return is_array( $out ) ? $out : array();
		}
		return array();
	}

	public function count_available(): int {
		global $wpdb;
		$default = $this->default_lang();
		$count   = 0;

		foreach ( $this->languages() as $slug => $term_id ) {
			if ( $slug === $default ) {
				continue;
			}
			$pairs = get_term_meta( $term_id, '_pll_strings_translations', true );
			if ( is_array( $pairs ) ) {
				foreach ( $pairs as $pair ) {
					if ( is_array( $pair ) && ! empty( $pair[0] ) && ! empty( $pair[1] ) ) {
						++$count;
					}
				}
			}
		}

		$groups = $wpdb->get_col( "SELECT description FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'post_translations'" ); // phpcs:ignore WordPress.DB
		foreach ( $groups as $group ) {
			$map = $this->unserialize_map( $group );
			if ( is_array( $map ) ) {
				foreach ( $map as $slug => $pid ) {
					if ( $slug !== $default && (int) $pid > 0 ) {
						++$count;
					}
				}
			}
		}
		return $count;
	}

	public function import(): array {
		global $wpdb;
		$default = $this->default_lang();
		if ( '' === $default ) {
			return array(
				'count' => 0,
				'error' => __( 'Polylang is not configured.', 'translate-rocket' ),
			);
		}

		$our_default = Plugin::instance()->router()->default_language();
		$count       = 0;

		// 1) Registered string translations.
		foreach ( $this->languages() as $slug => $term_id ) {
			if ( $slug === $default ) {
				continue;
			}
			$lang = $this->map_lang( $slug );
			if ( ! Languages::exists( $lang ) || $lang === $our_default ) {
				continue;
			}
			$pairs = get_term_meta( $term_id, '_pll_strings_translations', true );
			if ( is_array( $pairs ) ) {
				foreach ( $pairs as $pair ) {
					if ( is_array( $pair ) && isset( $pair[0], $pair[1] )
						&& Strings::store_imported( (string) $pair[0], $lang, (string) $pair[1] ) ) {
						++$count;
					}
				}
			}
		}

		// 2) Translated posts: title, excerpt, slug, and body (best effort).
		$groups = $wpdb->get_col( "SELECT description FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'post_translations'" ); // phpcs:ignore WordPress.DB
		foreach ( $groups as $group ) {
			$map = $this->unserialize_map( $group );
			if ( ! is_array( $map ) || empty( $map[ $default ] ) ) {
				continue;
			}
			$source = get_post( (int) $map[ $default ] );
			if ( ! $source ) {
				continue;
			}

			foreach ( $map as $slug => $pid ) {
				if ( $slug === $default ) {
					continue;
				}
				$lang = $this->map_lang( (string) $slug );
				if ( ! Languages::exists( $lang ) || $lang === $our_default ) {
					continue;
				}
				$tpost = get_post( (int) $pid );
				if ( ! $tpost ) {
					continue;
				}

				if ( Strings::store_imported( (string) $source->post_title, $lang, (string) $tpost->post_title ) ) {
					++$count;
				}
				if ( '' !== trim( (string) $source->post_excerpt ) && '' !== trim( (string) $tpost->post_excerpt )
					&& Strings::store_imported( (string) $source->post_excerpt, $lang, (string) $tpost->post_excerpt ) ) {
					++$count;
				}
				if ( '' !== (string) $tpost->post_name ) {
					Slugs::set( (int) $source->ID, $lang, (string) $tpost->post_name );
				}

				$src_chunks = $this->chunks( (string) $source->post_content );
				$tr_chunks  = $this->chunks( (string) $tpost->post_content );
				if ( ! empty( $src_chunks ) && count( $src_chunks ) === count( $tr_chunks ) ) {
					foreach ( $src_chunks as $i => $chunk ) {
						if ( Strings::store_imported( $chunk, $lang, $tr_chunks[ $i ] ) ) {
							++$count;
						}
					}
				}
			}
		}

		return array(
			'count' => $count,
			'error' => '',
		);
	}
}
