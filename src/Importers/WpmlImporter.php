<?php
/**
 * Import translations from WPML.
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
 * WPML mixes two models, and we import both — reading straight from its tables so
 * it works even while WPML is deactivated:
 *   - String translations ({prefix}icl_strings + {prefix}icl_string_translations):
 *     registered UI/widget/option strings, imported as reusable source→translation
 *     pairs.
 *   - Content translations ({prefix}icl_translations, grouped by `trid`): a separate
 *     post per language (like Polylang). We import the parts that map cleanly onto
 *     our string model — titles, excerpts, slugs, and body text paired by position
 *     when both posts share the same structure (best effort; unmatched pairs are
 *     simply never used, so they're harmless).
 */
class WpmlImporter implements ImporterInterface {

	public function id(): string {
		return 'wpml';
	}

	public function label(): string {
		return 'WPML';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function settings(): array {
		$s = get_option( 'icl_sitepress_settings' );
		return is_array( $s ) ? $s : array();
	}

	private function default_lang(): string {
		$s = $this->settings();
		return ! empty( $s['default_language'] ) ? (string) $s['default_language'] : '';
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
	}

	public function is_available(): bool {
		global $wpdb;
		if ( '' === $this->default_lang() ) {
			return false;
		}
		return $this->table_exists( $wpdb->prefix . 'icl_translations' )
			|| $this->table_exists( $wpdb->prefix . 'icl_string_translations' );
	}

	/**
	 * Map a WPML language code (en, pt-br, zh-hans…) to our short code.
	 */
	private function map_lang( string $code ): string {
		$code = strtolower( $code );
		$map  = array(
			'pt-br'   => 'pt-br',
			'pt_br'   => 'pt-br',
			'zh-hans' => 'zh',
			'zh_cn'   => 'zh',
			'zh-hant' => 'zh-tw',
			'zh_tw'   => 'zh-tw',
		);
		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}
		return strlen( $code ) > 2 ? substr( $code, 0, 2 ) : $code;
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

	public function count_available(): int {
		global $wpdb;
		if ( '' === $this->default_lang() ) {
			return 0;
		}
		$total = 0;

		$st = $wpdb->prefix . 'icl_string_translations';
		$s  = $wpdb->prefix . 'icl_strings';
		if ( $this->table_exists( $st ) && $this->table_exists( $s ) ) {
			$total += (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$st}` st INNER JOIN `{$s}` s ON s.id = st.string_id
				 WHERE st.value <> '' AND s.value <> ''"
			); // phpcs:ignore WordPress.DB
		}

		$tr = $wpdb->prefix . 'icl_translations';
		if ( $this->table_exists( $tr ) ) {
			$total += (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$tr}`
				 WHERE source_language_code IS NOT NULL AND source_language_code <> ''
				 AND element_type LIKE 'post\\_%'"
			); // phpcs:ignore WordPress.DB
		}
		return $total;
	}

	public function import(): array {
		global $wpdb;
		if ( '' === $this->default_lang() ) {
			return array(
				'count' => 0,
				'error' => __( 'WPML is not configured.', 'translate-rocket' ),
			);
		}

		$our_default = Plugin::instance()->router()->default_language();
		$count       = 0;

		$count += $this->import_strings( $our_default );
		$count += $this->import_content( $our_default );

		return array(
			'count' => $count,
			'error' => '',
		);
	}

	/**
	 * Registered UI/widget/option string translations.
	 *
	 * @param string $our_default Our default language code (never overwritten).
	 */
	private function import_strings( string $our_default ): int {
		global $wpdb;
		$st = $wpdb->prefix . 'icl_string_translations';
		$s  = $wpdb->prefix . 'icl_strings';
		if ( ! $this->table_exists( $st ) || ! $this->table_exists( $s ) ) {
			return 0;
		}

		$rows = $wpdb->get_results(
			"SELECT s.value AS source, st.language AS lang, st.value AS translation
			 FROM `{$st}` st INNER JOIN `{$s}` s ON s.id = st.string_id
			 WHERE st.value <> '' AND s.value <> ''"
		); // phpcs:ignore WordPress.DB

		$count = 0;
		foreach ( $rows as $row ) {
			$lang = $this->map_lang( (string) $row->lang );
			if ( ! Languages::exists( $lang ) || $lang === $our_default ) {
				continue;
			}
			if ( Strings::store_imported( (string) $row->source, $lang, (string) $row->translation ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Content translations: title, excerpt, slug and body (best effort), pairing
	 * each translated post with its source through the `trid` group.
	 *
	 * @param string $our_default Our default language code (never overwritten).
	 */
	private function import_content( string $our_default ): int {
		global $wpdb;
		$tr = $wpdb->prefix . 'icl_translations';
		if ( ! $this->table_exists( $tr ) ) {
			return 0;
		}

		$rows = $wpdb->get_results(
			"SELECT trid, element_type, element_id, language_code, source_language_code FROM `{$tr}`"
		); // phpcs:ignore WordPress.DB

		// Group post rows by their translation id (trid): source (no source language)
		// plus one translated post per language.
		$groups = array();
		foreach ( $rows as $row ) {
			if ( 0 !== strpos( (string) $row->element_type, 'post_' ) ) {
				continue;
			}
			$trid = (int) $row->trid;
			$src  = $row->source_language_code;
			if ( null === $src || '' === (string) $src ) {
				$groups[ $trid ]['source'] = (int) $row->element_id;
			} else {
				$groups[ $trid ]['trans'][ (string) $row->language_code ] = (int) $row->element_id;
			}
		}

		$count = 0;
		foreach ( $groups as $group ) {
			if ( empty( $group['source'] ) || empty( $group['trans'] ) ) {
				continue;
			}
			$source = get_post( (int) $group['source'] );
			if ( ! $source ) {
				continue;
			}

			foreach ( $group['trans'] as $code => $pid ) {
				$lang = $this->map_lang( (string) $code );
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
		return $count;
	}
}
