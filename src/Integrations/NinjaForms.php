<?php
/**
 * Ninja Forms: the form is drawn by the browser.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

use TranslateRocket\Plugin;
use TranslateRocket\Strings;
use TranslateRocket\Settings;
use TranslateRocket\NoTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Ninja Forms prints no fields in the HTML: it writes them as JSON in an inline
 * script (`nfForms.push(form)`, includes/Display/Render.php) and its JavaScript
 * builds the form on the page. Labels, placeholders, descriptions and the options
 * of a list therefore never meet the page engine, and a visitor on /it/ saw the
 * form in the source language (read in Ninja Forms 3.8, 28/09/2026).
 *
 * The plugin offers the fields and the form settings to filters just before
 * writing the JSON: the words are swapped there, on the server, so the form is
 * born translated. On the default language they are only collected, so they
 * appear in the translation screens like any other text.
 */
class NinjaForms {

	/**
	 * Field settings that hold words the visitor reads.
	 */
	const FIELD_KEYS = array( 'label', 'placeholder', 'desc_text', 'help_text', 'processing_label', 'checked_value', 'unchecked_value' );

	/**
	 * Form settings that hold words the visitor reads (custom messages).
	 */
	const FORM_KEYS = array( 'title', 'changeEmailErrorMsg', 'changeDateErrorMsg', 'confirmFieldErrorMsg', 'fieldNumberNumMinError', 'fieldNumberNumMaxError', 'fieldNumberIncrementBy', 'formErrorsCorrectErrors', 'validateRequiredField', 'honeypotHoneypotError', 'fieldsMarkedRequired', 'currency', 'unique_field_error' );

	/**
	 * Hook the filters; they only run when Ninja Forms renders a form.
	 */
	public function boot(): void {
		add_filter( 'ninja_forms_display_fields', array( $this, 'fields' ), 20, 2 );
		add_filter( 'ninja_forms_display_form_settings', array( $this, 'form_settings' ), 20, 2 );
	}

	/**
	 * @param mixed $fields  Field settings arrays.
	 * @param mixed $form_id Form id.
	 * @return mixed
	 */
	public function fields( $fields, $form_id = 0 ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}
		$texts = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			foreach ( self::FIELD_KEYS as $key ) {
				$this->pick( $field[ $key ] ?? null, $texts );
			}
			foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
				$this->pick( is_array( $option ) ? ( $option['label'] ?? null ) : null, $texts );
			}
		}
		$map = $this->map( $texts, (int) $form_id );
		if ( empty( $map ) ) {
			return $fields;
		}
		foreach ( $fields as $i => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			foreach ( self::FIELD_KEYS as $key ) {
				$fields[ $i ][ $key ] = $this->swap( $field[ $key ] ?? null, $map );
			}
			if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( $field['options'] as $j => $option ) {
					if ( is_array( $option ) && isset( $option['label'] ) ) {
						$fields[ $i ]['options'][ $j ]['label'] = $this->swap( $option['label'], $map );
					}
				}
			}
		}
		return $fields;
	}

	/**
	 * @param mixed $settings Form settings.
	 * @param mixed $form_id  Form id.
	 * @return mixed
	 */
	public function form_settings( $settings, $form_id = 0 ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		$texts = array();
		foreach ( self::FORM_KEYS as $key ) {
			$this->pick( $settings[ $key ] ?? null, $texts );
		}
		$map = $this->map( $texts, (int) $form_id );
		foreach ( self::FORM_KEYS as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$settings[ $key ] = $this->swap( $settings[ $key ], $map );
			}
		}
		return $settings;
	}

	/**
	 * Keep a value worth translating.
	 *
	 * @param mixed    $value Setting value.
	 * @param string[] $texts Accumulator.
	 */
	private function pick( $value, array &$texts ): void {
		if ( ! is_string( $value ) ) {
			return;
		}
		$t = trim( wp_strip_all_tags( $value ) );
		if ( '' === $t || ! preg_match( '/\p{L}/u', $t ) || NoTranslate::text_excluded( $t ) ) {
			return;
		}
		$texts[ $t ] = true;
	}

	/**
	 * The translated value, when the map knows it; the value untouched otherwise.
	 *
	 * @param mixed                $value Setting value.
	 * @param array<string,string> $map   Translations.
	 * @return mixed
	 */
	private function swap( $value, array $map ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $value;
		}
		$t = trim( wp_strip_all_tags( $value ) );
		if ( isset( $map[ $t ] ) && '' !== $map[ $t ] ) {
			return str_replace( $t, $map[ $t ], $value );
		}
		return $value;
	}

	/**
	 * Remember the words (every language) and look them up for this one.
	 *
	 * @param array<string,bool> $texts   Words found in the form.
	 * @param int                $form_id Form id, for the translation screens.
	 * @return array<string,string>
	 */
	private function map( array $texts, int $form_id ): array {
		if ( empty( $texts ) ) {
			return array();
		}
		$router  = Plugin::instance()->router();
		$lang    = $router->current_language();
		$targets = array_values( (array) ( Settings::get()['target_languages'] ?? array() ) );
		if ( ! empty( $targets ) && current_user_can( 'manage_options' ) ) {
			$items = array();
			foreach ( array_keys( $texts ) as $t ) {
				$items[] = array( 'original' => $t, 'type' => 'text' );
			}
			Strings::remember_batch( $items, $targets, $router->current_clean_path(), sprintf( 'Ninja Forms #%d', $form_id ) );
		}
		if ( '' === $lang || $router->is_default( $lang ) || \TranslateRocket\Coexistence::on() ) {
			return array();
		}
		return Strings::translate_texts( array_keys( $texts ), $lang );
	}
}
