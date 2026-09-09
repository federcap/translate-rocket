<?php
/**
 * Independent page copies (the "detach a translation into its own editable page"
 * feature). The hybrid that sits next to the runtime string translation: by
 * default a page is translated on the fly (TranslatePress-style); on request the
 * owner can "detach" a language into a real, independent WordPress post —
 * pre-filled with the current translation — that they then edit freely
 * (Polylang-style). It can be paused back to string mode without losing the
 * independent edits.
 *
 * This class is the data model + creation/baking logic only. Serving the copy on
 * the front end (the query swap) and the UI live in separate, later steps, so
 * this file is inert until wired in.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket;

defined( 'ABSPATH' ) || exit;

/**
 * Links a source post to a per-language copy and bakes the translated content.
 *
 * Meta layout:
 *  - on the SOURCE post: `_trrocket_copy_<lang>`        = copy post id
 *                        `_trrocket_copy_active_<lang>` = '1' while the copy is served
 *  - on the COPY post:   `_trrocket_copy_source`        = source post id
 *                        `_trrocket_copy_lang`          = language code
 *
 * "Paused" = the link/copy still exist (edits preserved) but the active flag is
 * off, so the front end falls back to runtime string translation.
 */
class Copies {

	const META_COPY   = '_trrocket_copy_';
	const META_ACTIVE = '_trrocket_copy_active_';
	const META_SYNCED = '_trrocket_copy_synced_';
	const META_SOURCE = '_trrocket_copy_source';
	const META_LANG   = '_trrocket_copy_lang';

	/**
	 * The copy post id for a source + language (0 if none exists).
	 */
	public static function copy_id( int $source_id, string $lang ): int {
		if ( $source_id <= 0 || '' === $lang ) {
			return 0;
		}
		return (int) get_post_meta( $source_id, self::META_COPY . $lang, true );
	}

	/**
	 * Whether an independent copy is currently active (served) for source + lang.
	 */
	public static function is_active( int $source_id, string $lang ): bool {
		$cid = self::copy_id( $source_id, $lang );
		if ( $cid <= 0 || ! ( get_post( $cid ) instanceof \WP_Post ) ) {
			return false;
		}
		return '1' === (string) get_post_meta( $source_id, self::META_ACTIVE . $lang, true );
	}

	/**
	 * The copy post id to serve for source + lang, or 0 when there is none active.
	 */
	public static function active_copy_id( int $source_id, string $lang ): int {
		return self::is_active( $source_id, $lang ) ? self::copy_id( $source_id, $lang ) : 0;
	}

	/**
	 * Is this post one of our independent copies?
	 */
	public static function is_copy( int $post_id ): bool {
		return $post_id > 0 && '' !== (string) get_post_meta( $post_id, self::META_SOURCE, true );
	}

	/**
	 * The source post id a copy was made from (0 if not a copy).
	 */
	public static function source_of( int $copy_id ): int {
		return (int) get_post_meta( $copy_id, self::META_SOURCE, true );
	}

	/**
	 * The language a copy represents.
	 */
	public static function lang_of( int $copy_id ): string {
		return (string) get_post_meta( $copy_id, self::META_LANG, true );
	}

	/**
	 * Create an independent copy of a source post in a language (or re-activate the
	 * existing, paused one — keeping its edits). Returns the copy post id, or 0 on
	 * failure. The copy starts as a draft so it never leaks as a stand-alone public
	 * page; the front end serves its content under the source's translated URL.
	 */
	public static function create( int $source_id, string $lang ): int {
		$source = get_post( $source_id );
		if ( ! ( $source instanceof \WP_Post ) || ! Languages::exists( $lang ) ) {
			return 0;
		}

		// Re-activate an existing (paused) copy without rebuilding it.
		$existing = self::copy_id( $source_id, $lang );
		if ( $existing > 0 && ( get_post( $existing ) instanceof \WP_Post ) ) {
			self::set_active( $source_id, $lang, true );
			return $existing;
		}

		$copy_id = wp_insert_post(
			array(
				'post_type'    => $source->post_type,
				'post_status'  => 'draft',
				// wp_slash: wp_insert_post() unslashes its input, so source text (which can
				// contain backslashes, e.g. < in block markup) must be re-slashed or
				// the backslashes are stripped and the markup leaks as literal text.
				'post_title'   => wp_slash( self::translate_text( (string) $source->post_title, $lang ) ),
				'post_content' => wp_slash( self::bake_content( (string) $source->post_content, $lang ) ),
				'post_excerpt' => wp_slash( self::translate_text( (string) $source->post_excerpt, $lang ) ),
				'post_parent'  => (int) $source->post_parent,
				'menu_order'   => (int) $source->menu_order,
				'post_name'    => $source->post_name . '-' . $lang,
				// A password-protected source must stay protected when its copy is
				// served in its place — carry the password over.
				'post_password' => (string) $source->post_password,
			),
			true
		);
		if ( is_wp_error( $copy_id ) || (int) $copy_id <= 0 ) {
			return 0;
		}
		$copy_id = (int) $copy_id;

		// Carry over the source's post meta — featured image, page template, and
		// crucially the theme / page-builder layout settings (a hidden or transparent
		// header, sidebar/template choices, Elementor/Divi data…). Without these the
		// copy renders with the wrong layout — e.g. a header that was hidden on the
		// source reappears. Internal/editor meta and our own linking meta are skipped.
		self::copy_meta( $source_id, $copy_id );

		// Link both ways and mark active. Record the source's modified time at bake so
		// we can later warn if the source drifts ahead of the copy.
		update_post_meta( $source_id, self::META_COPY . $lang, $copy_id );
		update_post_meta( $source_id, self::META_SYNCED . $lang, (int) get_post_modified_time( 'U', true, $source_id ) );
		update_post_meta( $copy_id, self::META_SOURCE, $source_id );
		update_post_meta( $copy_id, self::META_LANG, $lang );
		self::set_active( $source_id, $lang, true );

		return $copy_id;
	}

	/**
	 * Copy a source post's meta onto a fresh copy so it keeps the same layout —
	 * featured image, page template, theme header/footer/sidebar choices and
	 * page-builder data. Skips internal/editor meta and our own copy/visibility keys.
	 */
	private static function copy_meta( int $source_id, int $copy_id ): void {
		$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_pingme', '_encloseme' );
		$all  = get_post_meta( $source_id );
		if ( ! is_array( $all ) ) {
			return;
		}
		foreach ( $all as $key => $values ) {
			if ( in_array( $key, $skip, true ) || 0 === strpos( (string) $key, '_trrocket' ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				// get_post_meta( $id ) (all keys) returns RAW, still-serialized values.
				// Unserialize so add_post_meta() stores the correct type and so other
				// plugins' sanitize_meta filters (which may expect an array, etc.) don't
				// choke on a serialized string. Re-slash, as the meta API expects.
				add_post_meta( $copy_id, (string) $key, wp_slash( maybe_unserialize( (string) $value ) ) );
			}
		}
	}

	/**
	 * Whether the source page has been modified since this copy was baked — a hint
	 * that the copy may be missing recent changes.
	 */
	public static function source_changed( int $source_id, string $lang ): bool {
		if ( self::copy_id( $source_id, $lang ) <= 0 ) {
			return false;
		}
		$synced = (int) get_post_meta( $source_id, self::META_SYNCED . $lang, true );
		if ( $synced <= 0 ) {
			return false;
		}
		return (int) get_post_modified_time( 'U', true, $source_id ) > $synced;
	}

	/**
	 * Delete an independent copy for good and unlink it (its edits are lost; the page
	 * goes back to runtime string translation).
	 */
	public static function delete( int $source_id, string $lang ): void {
		$cid = self::copy_id( $source_id, $lang );
		if ( $cid > 0 ) {
			wp_delete_post( $cid, true );
		}
		delete_post_meta( $source_id, self::META_COPY . $lang );
		delete_post_meta( $source_id, self::META_ACTIVE . $lang );
		delete_post_meta( $source_id, self::META_SYNCED . $lang );
		Cache::flush();
	}

	/**
	 * Turn serving of a copy on or off (off = "paused", edits kept).
	 */
	public static function set_active( int $source_id, string $lang, bool $active ): void {
		if ( $source_id <= 0 || '' === $lang ) {
			return;
		}
		if ( $active ) {
			update_post_meta( $source_id, self::META_ACTIVE . $lang, '1' );
		} else {
			delete_post_meta( $source_id, self::META_ACTIVE . $lang );
		}
		Cache::flush();
	}

	/**
	 * Pause back to runtime string translation, keeping the copy (and its edits).
	 */
	public static function pause( int $source_id, string $lang ): void {
		self::set_active( $source_id, $lang, false );
	}

	/**
	 * Translate a short plain-text value by exact match against the saved strings.
	 */
	private static function translate_text( string $text, string $lang ): string {
		$key = trim( $text );
		if ( '' === $key ) {
			return $text;
		}
		$map = Strings::translate_texts( array( $key ), $lang );
		return isset( $map[ $key ] ) ? $map[ $key ] : $text;
	}

	/**
	 * Decide how to bake the post content. The DOM round-trip can corrupt content that
	 * carries escaped HTML inside block JSON/attributes — e.g. "<" (a Gutenberg or
	 * third-party block storing a link/button), where the parser strips the backslashes
	 * and the markup leaks as literal "u003c…" text. When such escapes are present we
	 * copy the content VERBATIM and let the owner translate it in the editor: a correct
	 * (untranslated) copy beats a pre-translated but broken one.
	 */
	private static function bake_content( string $html, string $lang ): string {
		// Content with escaped HTML in block JSON (page builders like Spectra/Elementor)
		// is copied VERBATIM. A DOM round-trip strips the backslashes of < and breaks
		// it; a string-replace translation is unsafe too — a translatable word can also
		// appear inside an image URL, a class or an ID (e.g. "apartment" → "appartamento"
		// inside .../apartment-…​.jpg breaks the image). So we keep the structure and
		// media byte-perfect and let the owner translate the texts in the builder's
		// editor, where the builder keeps its own data/CSS intact.
		if ( '' === trim( $html ) || false !== strpos( $html, '\\u' ) ) {
			return $html;
		}
		return self::translate_html( $html, $lang );
	}

	/**
	 * Best-effort bake: translate the text nodes of an HTML blob (e.g. Gutenberg
	 * post_content) using the saved string map, leaving markup — including block
	 * comments — intact. Whatever isn't matched stays in the source language for the
	 * owner to finish in the editor. Block delimiters and attributes are preserved.
	 */
	private static function translate_html( string $html, string $lang ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$ok = $dom->loadHTML(
			'<?xml encoding="utf-8"?><div id="trr-bake">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		if ( ! $ok ) {
			return $html;
		}

		$xpath = new \DOMXPath( $dom );
		$skip  = 'ancestor::script or ancestor::style or ancestor::pre or ancestor::code or ancestor::textarea';
		$nodes = iterator_to_array( $xpath->query( "//text()[not({$skip})]" ) );

		// Resolve translations for exactly the texts in this post (huge-safe).
		$candidates = array();
		foreach ( $nodes as $node ) {
			$candidates[] = trim( (string) $node->nodeValue );
		}
		$map = Strings::translate_texts( $candidates, $lang );
		if ( empty( $map ) ) {
			return $html;
		}

		foreach ( $nodes as $node ) {
			$val = (string) $node->nodeValue;
			$key = trim( $val );
			if ( '' === $key || ! isset( $map[ $key ] ) ) {
				continue;
			}
			// Keep the original leading/trailing whitespace around the translation.
			$lead  = preg_match( '/^\s*/u', $val, $m1 ) ? $m1[0] : '';
			$trail = preg_match( '/\s*$/u', $val, $m2 ) ? $m2[0] : '';
			// Text-node nodeValue is safe with '&' (unlike element/attribute nodes).
			$node->nodeValue = $lead . $map[ $key ] . $trail;
		}

		$wrap = $dom->getElementById( 'trr-bake' );
		if ( ! $wrap ) {
			return $html;
		}
		$out = '';
		foreach ( $wrap->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		return $out;
	}
}
