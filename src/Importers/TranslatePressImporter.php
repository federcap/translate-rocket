<?php
/**
 * Import translations from TranslatePress.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

use TranslateRocket\Strings;
use TranslateRocket\Languages;
use TranslateRocket\Plugin;
use TranslateRocket\Slugs;

defined( 'ABSPATH' ) || exit;

/**
 * TranslatePress keeps translations in three tables. We import the two that hold
 * real, reusable content:
 *   - {prefix}trp_dictionary_{source}_{target} : page-content strings (imported).
 *   - {prefix}trp_slug_translations            : translated post slugs (imported).
 * The {prefix}trp_gettext_{target} table (theme/plugin interface) is intentionally
 * skipped: the interface is translated via WordPress' own language packs, and that
 * table is mostly noise (Font Awesome icon names, widget labels…).
 */
class TranslatePressImporter implements ImporterInterface {

	public function id(): string {
		return 'translatepress';
	}

	public function label(): string {
		return 'TranslatePress';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function settings(): array {
		$s = get_option( 'trp_settings' );
		return is_array( $s ) ? $s : array();
	}

	public function is_available(): bool {
		$s = $this->settings();
		return ! empty( $s['default-language'] ) && ! empty( $s['translation-languages'] );
	}

	/**
	 * Map a TranslatePress locale (en_US) to our short code (en).
	 */
	private function map_lang( string $tp ): string {
		$tp  = strtolower( $tp );
		$map = array(
			'pt_br' => 'pt-br',
			'zh_cn' => 'zh',
			'zh_tw' => 'zh-tw',
		);
		return $map[ $tp ] ?? substr( $tp, 0, 2 );
	}

	private function dictionary_table( string $default, string $target ): string {
		global $wpdb;
		// Sanitize identifiers: locale codes can only safely contain [a-z0-9_].
		// This neutralizes any SQL injection via a tampered trp_settings option,
		// since a table name cannot be passed through $wpdb->prepare().
		$clean = static function ( string $code ): string {
			return (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $code ) );
		};
		return $wpdb->prefix . 'trp_dictionary_' . $clean( $default ) . '_' . $clean( $target );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
	}

	public function count_available(): int {
		global $wpdb;
		$s = $this->settings();
		if ( empty( $s['default-language'] ) ) {
			return 0;
		}
		$total = 0;
		foreach ( (array) ( $s['translation-languages'] ?? array() ) as $target ) {
			if ( $target === $s['default-language'] ) {
				continue;
			}
			$table = $this->dictionary_table( $s['default-language'], $target );
			if ( $this->table_exists( $table ) ) {
				$total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status > 0 AND translated <> ''" ); // phpcs:ignore WordPress.DB
			}
			// The gettext (theme/plugin interface) table is intentionally NOT imported:
			// the interface is translated via WordPress' own language packs, and that
			// table is mostly noise (Font Awesome icon names, widget labels, etc.).
		}
		$slugs = $wpdb->prefix . 'trp_slug_translations';
		if ( $this->table_exists( $slugs ) ) {
			$total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$slugs}` WHERE translated <> ''" ); // phpcs:ignore WordPress.DB
		}
		return $total;
	}

	public function import(): array {
		global $wpdb;
		$s = $this->settings();
		if ( empty( $s['default-language'] ) ) {
			return array(
				'count' => 0,
				'error' => __( 'TranslatePress is not configured.', 'translate-rocket' ),
			);
		}

		$our_default = Plugin::instance()->router()->default_language();
		$count       = 0;

		foreach ( (array) ( $s['translation-languages'] ?? array() ) as $target ) {
			if ( $target === $s['default-language'] ) {
				continue;
			}
			$lang = $this->map_lang( $target );
			if ( ! Languages::exists( $lang ) || $lang === $our_default ) {
				continue;
			}

			// 1) Page-content dictionary.
			$table = $this->dictionary_table( $s['default-language'], $target );
			if ( $this->table_exists( $table ) ) {
				$rows = $wpdb->get_results( "SELECT original, translated FROM `{$table}` WHERE status > 0 AND translated <> ''" ); // phpcs:ignore WordPress.DB
				foreach ( $rows as $row ) {
					// TranslatePress stores its segments in RENDERED form — HTML
					// entities included ("Bed &amp; Breakfast", "pi&ugrave;"). Our
					// engine matches DECODED DOM text ("Bed & Breakfast", "più"),
					// so entity-form rows would never match (and an entity-form
					// translation would print "&amp;" literally). Decode both.
					$original   = html_entity_decode( (string) $row->original, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$translated = html_entity_decode( (string) $row->translated, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( Strings::store_imported( $original, $lang, $translated ) ) {
						++$count;
					}
				}
			}

			// Note: the gettext (theme/plugin interface) table is deliberately skipped.
			// TranslateRocket translates the interface through WordPress' own language
			// packs (see Frontend\Locale), so importing it would only add noise
			// (Font Awesome icon names, widget labels…) that is never used.
		}

		// 3) Translated post slugs (one shared table for all target languages).
		$count += $this->import_slugs( $our_default );

		return array(
			'count' => $count,
			'error' => '',
		);
	}

	/**
	 * Import TranslatePress slug translations into our per-post slug meta.
	 * Matches each original slug to a published post by post_name.
	 *
	 * @param string $our_default Our default language code (never overwritten).
	 * @return int Number of slugs imported.
	 */
	private function import_slugs( string $our_default ): int {
		global $wpdb;
		$originals    = $wpdb->prefix . 'trp_slug_originals';
		$translations = $wpdb->prefix . 'trp_slug_translations';
		if ( ! $this->table_exists( $originals ) || ! $this->table_exists( $translations ) ) {
			return 0;
		}

		$rows = $wpdb->get_results(
			"SELECT o.original AS orig, t.translated AS trad, t.language AS lang
			 FROM `{$translations}` t INNER JOIN `{$originals}` o ON o.id = t.original_id
			 WHERE t.translated <> '' AND o.original <> ''"
		); // phpcs:ignore WordPress.DB

		$count = 0;
		foreach ( $rows as $row ) {
			$lang = $this->map_lang( (string) $row->lang );
			if ( ! Languages::exists( $lang ) || $lang === $our_default ) {
				continue;
			}
			$post_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' LIMIT 1",
					$row->orig
				)
			); // phpcs:ignore WordPress.DB
			if ( $post_id > 0 ) {
				Slugs::set( $post_id, $lang, (string) $row->trad );
				++$count;
			}
		}
		return $count;
	}
}
