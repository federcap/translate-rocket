<?php
/**
 * Forminator glue.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Frontend;

use TranslateRocket\Plugin;
use TranslateRocket\Strings;
use TranslateRocket\Settings;
use TranslateRocket\NoTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Translates the parts of a Forminator form that never pass through the page
 * engine, with the site's existing translations only (no AI call):
 *
 *  - the messages the server sends back after a submission (the "thank you"
 *    message, the invalid-form message, the per-field errors);
 *  - a form loaded with "Load form using AJAX";
 *  - the e-mail notifications, sent in the language of the page the form was
 *    submitted from.
 *
 * The language of a submission is written into the form as a hidden field when
 * the form is rendered (so it survives page caches and missing referers); the
 * page address Forminator posts, and the referer, are fallbacks.
 *
 * The wording Forminator keeps in the form settings (messages, e-mail subject and
 * body) is not on any page a visitor sees, so it is collected under its own
 * heading in the translation screens: when an administrator views a page with the
 * form, and when a notification is sent.
 *
 * Every callback returns its input unchanged on any problem: a submission must
 * never fail because of a translation.
 */
class Forminator {

	/**
	 * Where form wording is filed in the translation screens.
	 */
	const URL = '[forms]';

	/**
	 * Hidden field carrying the page language.
	 */
	const FIELD = 'trrocket_lang';

	/**
	 * Merge tags such as {name-1}, {all_fields}, {form_name}.
	 */
	const TAG = '/\{[A-Za-z0-9_\-]+\}/';

	/**
	 * Resolved visitor language for this request (null = not resolved yet).
	 *
	 * @var string|null
	 */
	private $lang = null;

	/**
	 * Hook into Forminator.
	 */
	public function boot(): void {
		if ( ! defined( 'FORMINATOR_VERSION' ) && ! class_exists( 'Forminator' ) ) {
			return;
		}
		// Shares the "translate interface / JS-inserted strings" switch with the
		// WooCommerce and Forms glue.
		if ( empty( Settings::get()['translate_interface'] ) ) {
			return;
		}
		if ( empty( Plugin::instance()->router()->secondary_languages() ) ) {
			return;
		}

		add_filter( 'forminator_render_form_submit_markup', array( $this, 'hidden_field' ), 20, 2 );
		add_filter( 'forminator_render_form_markup', array( $this, 'ajax_markup' ), 20 );
		add_filter( 'forminator_custom_form_thankyou_message', array( $this, 'message' ), 20 );
		add_filter( 'forminator_custom_form_invalid_form_message', array( $this, 'message' ), 20 );
		add_filter( 'forminator_custom_form_submit_errors', array( $this, 'errors' ), 20 );
		add_filter( 'forminator_form_ajax_submit_response', array( $this, 'response' ), 20 );
		add_filter( 'forminator_form_submit_response', array( $this, 'response' ), 20 );
		// Status-suffixed responses (Forminator's own spam / draft / abandoned messages).
		foreach ( array( 'spam', 'draft', 'abandoned' ) as $status ) {
			add_filter( 'forminator_form_' . $status . '_ajax_submit_response', array( $this, 'response' ), 20 );
		}
		add_action( 'forminator_custom_form_mail_before_send_mail', array( $this, 'collect_on_mail' ), 20, 2 );
		add_filter( 'forminator_custom_form_mail_admin_subject', array( $this, 'mail_subject' ), 20, 2 );
		add_filter( 'forminator_custom_form_mail_admin_message', array( $this, 'mail_message' ), 20, 6 );
	}

	/**
	 * Add the page language to the form, and collect the form's wording when an
	 * administrator views it.
	 *
	 * @param mixed $html    Hidden fields + submit button markup.
	 * @param mixed $form_id Form id.
	 * @return mixed
	 */
	public function hidden_field( $html, $form_id = 0 ) {
		try {
			if ( ! is_string( $html ) ) {
				return $html;
			}
			$router = Plugin::instance()->router();
			// A form loaded by AJAX is rendered inside admin-ajax: its page is the referer.
			$lang  = wp_doing_ajax() ? $router->request_language() : $router->current_language();
			$html .= '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="' . esc_attr( $lang ) . '">';

			// Also when the form arrives by AJAX: that is the only time an
			// administrator ever "views" a form loaded with "Load form using AJAX".
			if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
				$this->collect( self::model( (int) $form_id ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $html;
	}

	/**
	 * A form loaded with "Load form using AJAX" comes back from admin-ajax and
	 * never passes through the page engine: translate its text and placeholders.
	 *
	 * @param mixed $html Form markup.
	 * @return mixed
	 */
	public function ajax_markup( $html ) {
		try {
			if ( ! is_string( $html ) || ! wp_doing_ajax() ) {
				return $html;
			}
			// The page engine never sees this markup: an administrator loading the
			// form collects its labels, placeholders, options and button here.
			if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
				$this->collect_markup( $html );
			}
			$lang = $this->visitor_language();
			return $this->translatable( $lang ) ? HtmlText::translate( $html, $lang, true ) : $html;
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	/**
	 * Thank-you message (before its merge tags are filled) and invalid-form message.
	 *
	 * @param mixed $message Message HTML or text.
	 * @return mixed
	 */
	public function message( $message ) {
		try {
			if ( ! is_string( $message ) || '' === trim( $message ) ) {
				return $message;
			}
			$lang = $this->visitor_language();
			return $this->translatable( $lang ) ? HtmlText::translate( $message, $lang ) : $message;
		} catch ( \Throwable $e ) {
			return $message;
		}
	}

	/**
	 * Per-field errors returned by the server: a list of [ field id => message ].
	 *
	 * @param mixed $errors Submission errors.
	 * @return mixed
	 */
	public function errors( $errors ) {
		try {
			if ( ! is_array( $errors ) || empty( $errors ) ) {
				return $errors;
			}
			$lang = $this->visitor_language();
			if ( ! $this->translatable( $lang ) ) {
				return $errors;
			}
			// One lookup for every message, then the substitution.
			$texts = array();
			foreach ( $errors as $row ) {
				foreach ( (array) $row as $msg ) {
					if ( is_string( $msg ) && '' !== trim( $msg ) ) {
						$texts[] = trim( $msg );
					}
				}
			}
			$map = Strings::translate_texts( $texts, $lang );
			if ( empty( $map ) ) {
				return $errors;
			}
			foreach ( $errors as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				foreach ( $row as $field => $msg ) {
					$t = is_string( $msg ) ? trim( $msg ) : '';
					if ( '' !== $t && isset( $map[ $t ] ) ) {
						$errors[ $i ][ $field ] = str_replace( $t, self::plain( $map[ $t ] ), $msg );
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $errors;
	}

	/**
	 * The final submission response: any message still in the source language
	 * (Forminator's own defaults, for example) gets a last lookup.
	 *
	 * @param mixed $response Response array.
	 * @return mixed
	 */
	public function response( $response ) {
		try {
			if ( ! is_array( $response ) || empty( $response['message'] ) || ! is_string( $response['message'] ) ) {
				return $response;
			}
			$lang = $this->visitor_language();
			if ( $this->translatable( $lang ) ) {
				$response['message'] = HtmlText::translate( $response['message'], $lang );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $response;
	}

	/**
	 * Collect the form's wording when a notification goes out (any language).
	 *
	 * @param mixed $mail Mail sender.
	 * @param mixed $form Form model.
	 */
	public function collect_on_mail( $mail, $form = null ): void {
		unset( $mail );
		try {
			$this->collect( $form );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * E-mail subject, in the language of the page the form was sent from.
	 *
	 * The subject arrives with its merge tags already filled in, so it is matched
	 * against each notification's subject template, and the captured values are
	 * put into the translated template.
	 *
	 * @param mixed $subject Subject.
	 * @param mixed $form    Form model.
	 * @return mixed
	 */
	public function mail_subject( $subject, $form = null ) {
		try {
			if ( ! is_string( $subject ) || '' === trim( $subject ) || ! is_object( $form ) ) {
				return $subject;
			}
			$lang = $this->visitor_language();
			if ( ! $this->translatable( $lang ) ) {
				return $subject;
			}
			$final   = trim( $subject );
			$decoded = html_entity_decode( $final, ENT_QUOTES, 'UTF-8' );
			// Forminator does not say which notification this subject belongs to: it
			// is matched against every subject template. If a template of a
			// notification kept in the source language matches too, the subject is
			// left alone rather than translated by mistake.
			$candidates = array();
			foreach ( (array) $form->notifications as $notification ) {
				$raw = is_array( $notification ) && isset( $notification['email-subject'] ) ? trim( (string) $notification['email-subject'] ) : '';
				if ( '' === $raw ) {
					continue;
				}
				$matches = null !== self::fill( $raw, $raw, $final ) || null !== self::fill( $raw, $raw, $decoded );
				if ( ! $matches ) {
					continue;
				}
				if ( ! $this->should_translate( $notification, $lang, $form ) ) {
					return $subject;
				}
				$candidates[ $raw ] = true;
			}
			if ( empty( $candidates ) ) {
				return $subject;
			}
			$map = Strings::translate_texts( array_keys( $candidates ), $lang );
			foreach ( array_keys( $candidates ) as $raw ) {
				if ( ! isset( $map[ $raw ] ) ) {
					continue;
				}
				$filled = self::fill( $raw, $map[ $raw ], $final );
				if ( null === $filled ) {
					$filled = self::fill( $raw, $map[ $raw ], $decoded );
				}
				if ( null !== $filled ) {
					return str_replace( $final, $filled, $subject );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $subject;
	}

	/**
	 * E-mail body, in the language of the page the form was sent from.
	 *
	 * Each text node of the sent body is matched against the text of the body
	 * template (merge tags filled in); the field labels Forminator prints for
	 * {all_fields} are translated too. Values typed by the visitor are never
	 * looked up.
	 *
	 * @param mixed $message      Body HTML.
	 * @param mixed $form         Form model.
	 * @param mixed $data         Posted data.
	 * @param mixed $entry        Entry.
	 * @param mixed $mail         Mail sender.
	 * @param mixed $notification Notification settings (Forminator 1.57+).
	 * @return mixed
	 */
	public function mail_message( $message, $form = null, $data = null, $entry = null, $mail = null, $notification = null ) {
		unset( $data, $entry, $mail );
		try {
			if ( ! is_string( $message ) || '' === trim( $message ) || ! is_object( $form ) ) {
				return $message;
			}
			$lang = $this->visitor_language();
			if ( ! $this->translatable( $lang ) ) {
				return $message;
			}
			$notifications = is_array( $notification ) ? array( $notification ) : (array) $form->notifications;

			$segments = array();
			foreach ( $notifications as $n ) {
				if ( ! is_array( $n ) || empty( $n['email-editor'] ) || ! $this->should_translate( $n, $lang, $form ) ) {
					continue;
				}
				foreach ( HtmlText::segments( (string) $n['email-editor'] ) as $seg ) {
					if ( self::has_words( $seg ) ) {
						$segments[ $seg ] = true;
					}
				}
			}
			if ( empty( $segments ) ) {
				return $message;
			}
			$seg_map   = Strings::translate_texts( array_keys( $segments ), $lang );
			$label_map = Strings::translate_texts( self::field_labels( $form ), $lang );
			if ( empty( $seg_map ) && empty( $label_map ) ) {
				return $message;
			}

			return HtmlText::rewrite(
				$message,
				function ( $text, $tag ) use ( $seg_map, $label_map ) {
					foreach ( $seg_map as $raw => $translated ) {
						$filled = self::fill( (string) $raw, (string) $translated, $text );
						if ( null !== $filled ) {
							return $filled;
						}
					}
					// {all_fields} prints each label in <b> (headings in <h4><b>),
					// sub-fields of an address or a name block with a trailing colon.
					if ( in_array( $tag, array( 'b', 'strong', 'h4' ), true ) ) {
						if ( isset( $label_map[ $text ] ) ) {
							return $label_map[ $text ];
						}
						$bare = rtrim( $text, ':' );
						if ( $bare !== $text && isset( $label_map[ $bare ] ) ) {
							return $label_map[ $bare ] . ':';
						}
					}
					return null;
				}
			);
		} catch ( \Throwable $e ) {
			return $message;
		}
	}

	/**
	 * Put a translated template's merge tags back with the values captured from the
	 * sent text. Null when the sent text does not come from this template, or when
	 * the translation uses a merge tag the template doesn't have.
	 *
	 * @param string $raw        Template text (source language).
	 * @param string $translated Its translation.
	 * @param string $sent       Text as sent, merge tags filled in.
	 */
	public static function fill( string $raw, string $translated, string $sent ): ?string {
		$raw  = trim( $raw );
		$sent = trim( $sent );
		if ( '' === $raw || '' === $translated ) {
			return null;
		}
		if ( $raw === $sent ) {
			return preg_match( self::TAG, $translated ) ? null : $translated;
		}
		$parts = preg_split( '/(\{[A-Za-z0-9_\-]+\})/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
			return null;
		}
		// Two tags side by side ("{first-1}{last-1}") can only be split by
		// preferring non-empty values; an empty field is still accepted second.
		$names = array();
		$m     = array();
		foreach ( array( '(.+?)', '(.*?)' ) as $capture ) {
			$re    = '';
			$names = array();
			foreach ( $parts as $i => $part ) {
				if ( $i % 2 ) {
					$re     .= $capture;
					$names[] = $part;
				} else {
					$re .= preg_quote( $part, '/' );
				}
			}
			if ( preg_match( '/^' . $re . '$/su', $sent, $m ) ) {
				break;
			}
			$m = array();
		}
		if ( empty( $m ) ) {
			return null;
		}
		$values = array();
		foreach ( $names as $k => $name ) {
			if ( ! isset( $values[ $name ] ) ) {
				$values[ $name ] = (string) $m[ $k + 1 ];
			}
		}
		if ( preg_match_all( self::TAG, $translated, $used ) ) {
			foreach ( $used[0] as $name ) {
				if ( ! isset( $values[ $name ] ) ) {
					return null;
				}
			}
		}
		$out = preg_replace_callback(
			self::TAG,
			function ( $x ) use ( $values ) {
				return $values[ $x[0] ];
			},
			$translated
		);
		return is_string( $out ) ? $out : null;
	}

	/**
	 * Language of the visitor who sent (or loaded) the form: the hidden field,
	 * else the URL prefix or the referer, else the page address Forminator posts.
	 */
	private function visitor_language(): string {
		if ( null !== $this->lang ) {
			return $this->lang;
		}
		$router = Plugin::instance()->router();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only language code, checked against the configured languages; Forminator verifies its own nonce.
		$posted = isset( $_POST[ self::FIELD ] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) ) : '';
		// Only a language visitors may see: an offline one stays private to the admin.
		if ( '' !== $posted && ( $router->is_default( $posted ) || in_array( $posted, $router->public_languages(), true ) ) ) {
			$this->lang = $posted;
			return $this->lang;
		}
		$lang = $router->request_language();
		if ( $router->is_default( $lang ) && isset( $_POST['current_url'] ) ) {
			$lang = $router->language_of_url( esc_url_raw( wp_unslash( $_POST['current_url'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->lang = $lang;
		return $this->lang;
	}

	/**
	 * A secondary language with translations to use.
	 */
	private function translatable( string $lang ): bool {
		$router = Plugin::instance()->router();
		return '' !== $lang
			&& ! $router->is_default( $lang )
			&& in_array( $lang, $router->public_languages(), true )
			&& Strings::has_translations( $lang );
	}

	/**
	 * A stored translation written into a plain-text slot of a JSON response that
	 * Forminator's script inserts as HTML.
	 */
	private static function plain( string $text ): string {
		return function_exists( 'wp_kses_post' ) ? wp_kses_post( $text ) : $text;
	}

	/**
	 * Record the readable text of a form loaded by AJAX (labels, placeholders,
	 * options, button) under the forms heading, like collect() does for the
	 * wording of the form settings.
	 *
	 * @param string $html Form markup.
	 */
	private function collect_markup( string $html ): void {
		$items = array();
		foreach ( HtmlText::segments( $html, true ) as $seg ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $seg ) : strlen( $seg );
			if ( $len > 800 || ! self::has_words( $seg ) || NoTranslate::text_excluded( $seg ) ) {
				continue;
			}
			$items[ $seg ] = array(
				'original' => $seg,
				'type'     => 'text',
			);
		}
		$this->remember( $items );
	}

	/**
	 * Store collected form texts, at most once a day per set of texts.
	 *
	 * @param array<string,array{original:string,type:string}> $items Texts.
	 */
	private function remember( array $items ): void {
		if ( empty( $items ) ) {
			return;
		}
		$key = 'trrocket_frm_' . md5( implode( "\n", array_keys( $items ) ) );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );

		// The heading is stored as written here and shown as is: a request in another
		// language (a translated page, an AJAX call from it) must not file it translated.
		$was               = Locale::$suspended;
		Locale::$suspended = true;
		Strings::remember_batch(
			array_values( $items ),
			Plugin::instance()->router()->secondary_languages(),
			self::URL,
			__( 'Forms: messages and e-mails', 'translate-rocket' )
		);
		Locale::$suspended = $was;
	}

	/**
	 * Whether a notification goes out translated.
	 *
	 * @param mixed  $notification Notification settings.
	 * @param string $lang         Language of the submission.
	 * @param mixed  $form         Form model.
	 */
	private function should_translate( $notification, string $lang, $form ): bool {
		/**
		 * Filters whether a Forminator e-mail notification is sent in the language
		 * of the page the form was submitted from. Return false for a notification
		 * that should stay in the site's source language (for example the copy that
		 * goes to the site owner).
		 *
		 * @param bool   $translate    Default true.
		 * @param array  $notification Forminator notification settings.
		 * @param string $lang         Language code of the submission.
		 * @param object $form         Forminator form model.
		 */
		return (bool) apply_filters( 'trrocket_forminator_translate_notification', true, $notification, $lang, $form );
	}

	/**
	 * Load a Forminator form model.
	 *
	 * @param int $form_id Form id.
	 * @return object|null
	 */
	private static function model( int $form_id ) {
		if ( $form_id <= 0 || ! class_exists( '\Forminator_Base_Form_Model' ) ) {
			return null;
		}
		$model = \Forminator_Base_Form_Model::get_model( $form_id );
		return is_object( $model ) ? $model : null;
	}

	/**
	 * Field labels of a form.
	 *
	 * @param object $form Form model.
	 * @return string[]
	 */
	private static function field_labels( $form ): array {
		$out = array();
		foreach ( (array) ( $form->fields ?? array() ) as $field ) {
			$arr = is_object( $field ) && method_exists( $field, 'to_array' ) ? (array) $field->to_array() : ( is_array( $field ) ? $field : array() );
			if ( ! empty( $arr['field_label'] ) && is_string( $arr['field_label'] ) ) {
				$out[] = trim( wp_strip_all_tags( $arr['field_label'] ) );
			}
		}
		return array_values( array_filter( $out ) );
	}

	/**
	 * Text worth translating: it has letters besides its merge tags.
	 */
	private static function has_words( string $text ): bool {
		$bare = preg_replace( self::TAG, '', $text );
		return is_string( $bare ) && (bool) preg_match( '/\p{L}/u', $bare );
	}

	/**
	 * Record a form's wording (messages, validation messages, e-mail subject and
	 * body) so it can be translated like any other string. At most once a day per
	 * version of the wording.
	 *
	 * @param mixed $form Form model.
	 */
	private function collect( $form ): void {
		if ( ! is_object( $form ) ) {
			return;
		}
		$texts = array();

		if ( method_exists( $form, 'get_behavior_array' ) ) {
			foreach ( (array) $form->get_behavior_array() as $behavior ) {
				foreach ( (array) $behavior as $key => $val ) {
					if ( is_string( $val ) && preg_match( '/thankyou-message$/', (string) $key ) ) {
						$texts[] = $val;
					}
				}
			}
		}
		$settings = (array) ( $form->settings ?? array() );
		if ( ! empty( $settings['submitData']['custom-invalid-form-message'] ) && is_string( $settings['submitData']['custom-invalid-form-message'] ) ) {
			$texts[] = $settings['submitData']['custom-invalid-form-message'];
		}
		foreach ( (array) ( $form->fields ?? array() ) as $field ) {
			$arr = is_object( $field ) && method_exists( $field, 'to_array' ) ? (array) $field->to_array() : array();
			foreach ( $arr as $key => $val ) {
				if ( is_string( $val ) && preg_match( '/(^|[_-])message$/', (string) $key ) ) {
					$texts[] = $val;
				}
			}
		}
		foreach ( (array) ( $form->notifications ?? array() ) as $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			foreach ( array( 'email-subject', 'email-editor' ) as $key ) {
				if ( ! empty( $n[ $key ] ) && is_string( $n[ $key ] ) ) {
					$texts[] = $n[ $key ];
				}
			}
		}

		if ( empty( $texts ) ) {
			return;
		}
		// Seen this wording today already (most submissions): stop before parsing it.
		$key = 'trrocket_frm_' . md5( 'raw' . implode( "\n", $texts ) );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );

		$items = array();
		foreach ( $texts as $text ) {
			foreach ( HtmlText::segments( $text ) as $seg ) {
				$len = function_exists( 'mb_strlen' ) ? mb_strlen( $seg ) : strlen( $seg );
				if ( $len > 800 || ! self::has_words( $seg ) || NoTranslate::text_excluded( $seg ) ) {
					continue;
				}
				$items[ $seg ] = array(
					'original' => $seg,
					'type'     => 'text',
				);
			}
		}
		$this->remember( $items );
	}
}
