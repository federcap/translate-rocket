<?php
/**
 * Import translations from Sublanguage.
 *
 * Sublanguage keeps each translation as meta on the original post, keyed by the
 * language slug: `_it_post_title`, `_it_post_content`. A language is a post of
 * type `language`: its slug (`post_name`) is the meta prefix, its locale sits in
 * `post_content`. Option `sublanguage['main']` is the id of the language the posts
 * are written in.
 *
 * There is no «published» flag per translation: an empty field means «use the
 * original», and a language still in draft is not online, so it is not imported.
 *
 * Read from the database directly, so it works with Sublanguage switched off.
 * Formats verified on Sublanguage 2.12 (`_ssh/ricerca/import-nuovi/FORMATI.md`).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Sublanguage's meta.
 */
class SublanguageImporter extends MetaLanguagesImporter {

	public function id(): string {
		return 'sublanguage';
	}

	public function label(): string {
		return 'Sublanguage';
	}

	protected function chiave(): string {
		return 'trrocket_imp_sublanguage_n';
	}

	private function id_partenza(): int {
		$o = get_option( 'sublanguage' );
		return ( is_array( $o ) && ! empty( $o['main'] ) ) ? (int) $o['main'] : 0;
	}

	/**
	 * Published languages other than the main one.
	 *
	 * @return array<int,array{prefisso:string,codice:string}>
	 */
	protected function lingue(): array {
		global $wpdb;
		$main  = $this->id_partenza();
		$righe = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT ID, post_name, post_content FROM {$wpdb->posts}
			 WHERE post_type = 'language' AND post_status = 'publish' ORDER BY menu_order, ID"
		);
		$out = array();
		foreach ( (array) $righe as $r ) {
			if ( (int) $r->ID === $main || '' === (string) $r->post_name ) {
				continue;
			}
			$locale = trim( (string) $r->post_content );
			$out[]  = array(
				'prefisso' => '_' . $r->post_name . '_',
				'codice'   => Comune::lingua( '' !== $locale ? $locale : (string) $r->post_name ),
			);
		}
		return $out;
	}

	protected function partenza(): string {
		$main = $this->id_partenza();
		if ( ! $main ) {
			return '';
		}
		$p = get_post( $main );
		if ( ! $p || 'language' !== $p->post_type ) {
			return '';
		}
		$locale = trim( (string) $p->post_content );
		return Comune::lingua( '' !== $locale ? $locale : (string) $p->post_name );
	}

	/**
	 * No flag per translation in Sublanguage: whatever is filled in is live.
	 */
	protected function pubblicata( int $post_id, string $prefisso ): bool {
		unset( $post_id, $prefisso );
		return true;
	}
}
