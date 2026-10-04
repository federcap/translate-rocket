<?php
/**
 * The e-mails contact forms send back to the visitor, in the visitor's language.
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
 * Contact Form 7 (10M+ sites) sends «Mail (2)» — the automatic reply — to whoever wrote, in the site's language
 * even when they wrote from /it/ (reproduced 3/10/2026, collaudo-cf7-mail.sh). Its wording is the shop's own text,
 * never seen on a page, so it never became translatable either.
 *
 * The template is translated BEFORE Contact Form 7 puts the visitor's data in («Dear [your-name],»): the [tags]
 * travel with the sentence and are filled in afterwards. A translation that lost or changed a tag is not used
 * for that line — an e-mail saying «Dear [tuo-nome],» would be worse than an English one. The mail to the site's
 * owner (Mail) keeps the site's language.
 */
class FormMails {

	/**
	 * Where the e-mails' wording is filed in the translation screens.
	 */
	const CF7_URL = '[contact form 7 e-mails]';

	/**
	 * Where WPForms' notification wording is filed.
	 */
	const WPFORMS_URL = '[wpforms e-mails]';

	/**
	 * Where Fluent Forms' notification wording is filed.
	 */
	const FLUENT_URL = '[fluent forms e-mails]';

	/**
	 * Where Ninja Forms' e-mail wording is filed.
	 */
	const NINJA_URL = '[ninja forms e-mails]';

	/**
	 * Where Formidable's e-mail wording is filed.
	 */
	const FORMIDABLE_URL = '[formidable e-mails]';

	/**
	 * Where Everest Forms' notification wording is filed.
	 */
	const EVEREST_URL = '[everest forms e-mails]';

	/**
	 * Where SureForms' notification wording is filed.
	 */
	const SUREFORMS_URL = '[sureforms e-mails]';

	/**
	 * Where GiveWP's e-mails to the donor are filed.
	 */
	const GIVE_URL = '[givewp e-mails]';

	/**
	 * The forms' tags: Contact Form 7's [your-name], WPForms' {field_id="1"} and {all_fields}.
	 */
	const TAGS = '/\[[^\]]*\]|\{[^}]*\}/';

	/**
	 * Hooks.
	 */
	public function boot(): void {
		if ( empty( Settings::get()['translate_interface'] ) ) {
			return;
		}
		add_filter( 'wpcf7_contact_form_property_mail_2', array( $this, 'cf7_reply' ), 20, 2 );
		// WPForms (6M sites): the notification sent to the address the visitor typed in the form.
		add_filter( 'wpforms_entry_email_atts', array( $this, 'wpforms_notification' ), 20, 5 );
		// Fluent Forms (600k sites): its notifications, before the {inputs.name} shortcodes are filled in.
		add_filter( 'fluentform/integration_feed_before_parse', array( $this, 'fluent_notification' ), 20, 4 );
		// Ninja Forms (800k sites): its «Email» actions, before its merge tags (priority 10) fill in {field:name}.
		add_filter( 'ninja_forms_run_action_settings', array( $this, 'ninja_email' ), 5 );
		// Formidable (300k sites): its «Email» action, before FrmNotification (priority 10) builds the e-mail.
		add_action( 'frm_trigger_email_action', array( $this, 'formidable_email' ), 1 );
		add_action( 'frm_trigger_email_action', array( $this, 'formidable_restore' ), 999 );
		// Everest Forms (100k sites): which notification is next is only known here; its wording arrives just after.
		add_filter( 'everest_forms_entry_email_process', array( $this, 'everest_which' ), 20, 5 );
		add_filter( 'everest_forms_entry_email_atts', array( $this, 'everest_notification' ), 20, 4 );
		// SureForms (300k sites): every notification of the form, as stored, before its smart tags are filled in.
		add_filter( 'srfm_email_notification_should_send', array( $this, 'sureforms_notifications' ), 20 );

		// The message shown after sending («Thank you, we will be in touch.»). It never appears on a page, so it
		// never became translatable; it is read here from the form's settings — the owner's wording, never what
		// the visitor typed — before the plugin fills in its tags, and shown in the page's language.
		add_filter( 'wpforms_process_before_form_data', array( $this, 'wpforms_confirmation' ), 20 );
		add_filter( 'fluentform/form_submission_confirmation', array( $this, 'fluent_confirmation' ), 20 );
		add_filter( 'everest_forms_process_before_form_data', array( $this, 'everest_confirmation' ), 20 );
		add_filter( 'srfm_form_confirmation_data', array( $this, 'sureforms_confirmation' ), 20 );
		add_filter( 'wpcf7_display_message', array( $this, 'cf7_message' ), 20 );
		// Otter Blocks (300k sites): its form's success and error messages, answered through the REST API.
		add_filter( 'otter_form_data_preparation', array( $this, 'otter_messages' ), 20 );
		// GiveWP (100k sites): the e-mails to the donor, before their {tags} are filled in.
		foreach ( array( 'donation-receipt', 'offline-donation-instruction' ) as $id ) {
			add_filter( "give_{$id}_get_email_subject", array( $this, 'give_text' ), 20 );
			add_filter( "give_{$id}_get_email_message", array( $this, 'give_text' ), 20 );
			add_filter( "give_{$id}_get_email_header", array( $this, 'give_text' ), 20 );
		}
	}

	/**
	 * A GiveWP e-mail to the donor (subject, heading or message), in the language of the page the donation
	 * was made from. The ones to the site's owner are not hooked.
	 *
	 * @param mixed $text Wording as the owner wrote it.
	 * @return mixed
	 */
	public function give_text( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $text;
		}
		self::remember( self::GIVE_URL, self::lines( $text ) );
		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! Strings::has_translations( $lang ) ) {
			return $text;
		}
		return self::put_body( $text, Strings::translate_texts( self::lines( $text ), $lang ) );
	}

	/**
	 * Otter Blocks' form messages («submitMessage», «errorMessage»), in the form's settings while it is sent.
	 *
	 * @param mixed $form_data Otter's Form_Data_Request.
	 * @return mixed
	 */
	public function otter_messages( $form_data ) {
		if ( ! is_object( $form_data ) || ! method_exists( $form_data, 'get_wp_options' ) ) {
			return $form_data;
		}
		$options = $form_data->get_wp_options();
		if ( ! is_object( $options ) ) {
			return $form_data;
		}
		foreach ( array( 'submit_message', 'error_message' ) as $what ) {
			if ( method_exists( $options, 'get_' . $what ) && method_exists( $options, 'set_' . $what ) ) {
				$text = $options->{'get_' . $what}();
				if ( is_string( $text ) && '' !== $text ) {
					$options->{'set_' . $what}( self::message( $text ) );
				}
			}
		}
		return $form_data;
	}

	/**
	 * A message the visitor reads after sending a form: made translatable, and translated for a translated page.
	 *
	 * @param string $text The form's own wording, tags not yet filled in.
	 */
	private static function message( string $text ): string {
		if ( '' === trim( $text ) ) {
			return $text;
		}
		self::remember( Forminator::URL, self::lines( $text ) );
		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! Strings::has_translations( $lang ) ) {
			return $text;
		}
		return self::put_body( $text, Strings::translate_texts( self::lines( $text ), $lang ) );
	}

	/**
	 * WPForms' confirmation messages, in the form's settings while it is processed.
	 *
	 * @param mixed $form_data Form settings.
	 * @return mixed
	 */
	public function wpforms_confirmation( $form_data ) {
		if ( ! is_array( $form_data ) || empty( $form_data['settings']['confirmations'] ) || ! is_array( $form_data['settings']['confirmations'] ) ) {
			return $form_data;
		}
		foreach ( $form_data['settings']['confirmations'] as $i => $c ) {
			if ( is_array( $c ) && isset( $c['message'] ) && is_string( $c['message'] ) && 'message' === ( $c['type'] ?? 'message' ) ) {
				$form_data['settings']['confirmations'][ $i ]['message'] = self::message( $c['message'] );
			}
		}
		return $form_data;
	}

	/**
	 * Fluent Forms' confirmation («Message to show»).
	 *
	 * @param mixed $confirmation Confirmation settings.
	 * @return mixed
	 */
	public function fluent_confirmation( $confirmation ) {
		if ( is_array( $confirmation ) && isset( $confirmation['messageToShow'] ) && is_string( $confirmation['messageToShow'] ) ) {
			$confirmation['messageToShow'] = self::message( $confirmation['messageToShow'] );
		}
		return $confirmation;
	}

	/**
	 * Everest Forms' success message, in the form's settings while it is processed.
	 *
	 * @param mixed $form_data Form settings.
	 * @return mixed
	 */
	public function everest_confirmation( $form_data ) {
		if ( is_array( $form_data ) && isset( $form_data['settings']['successful_form_submission_message'] ) && is_string( $form_data['settings']['successful_form_submission_message'] ) ) {
			$form_data['settings']['successful_form_submission_message'] = self::message( $form_data['settings']['successful_form_submission_message'] );
		}
		return $form_data;
	}

	/**
	 * SureForms' confirmation, read when a submission is answered.
	 *
	 * @param mixed $confirmations The «_srfm_form_confirmation» values.
	 * @return mixed
	 */
	public function sureforms_confirmation( $confirmations ) {
		if ( ! is_array( $confirmations ) ) {
			return $confirmations;
		}
		foreach ( $confirmations as $i => $list ) {
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $j => $c ) {
				if ( is_array( $c ) && isset( $c['message'] ) && is_string( $c['message'] ) ) {
					$confirmations[ $i ][ $j ]['message'] = self::message( $c['message'] );
				}
			}
		}
		return $confirmations;
	}

	/**
	 * Contact Form 7's messages (sent, failed, a field to correct…) as the form's owner wrote them.
	 *
	 * @param mixed $message Message.
	 * @return mixed
	 */
	public function cf7_message( $message ) {
		return is_string( $message ) ? self::message( $message ) : $message;
	}

	/**
	 * SureForms' notifications: the ones sent to a field of the form ({form:email}) in the visitor's language.
	 *
	 * @param mixed $notifications The «_srfm_email_notification» values (lists of notifications).
	 * @return mixed
	 */
	public function sureforms_notifications( $notifications ) {
		if ( ! is_array( $notifications ) ) {
			return $notifications;
		}
		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		$live   = '' !== $lang && ! $router->is_default( $lang ) && Strings::has_translations( $lang );
		foreach ( $notifications as $i => $list ) {
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $j => $item ) {
				if ( ! is_array( $item ) || false === strpos( (string) ( $item['email_to'] ?? '' ), '{form:' ) ) {
					continue; // Not to the visitor.
				}
				$subject = (string) ( $item['subject'] ?? '' );
				$body    = (string) ( $item['email_body'] ?? '' );
				self::remember( self::SUREFORMS_URL, array_merge( array( $subject ), self::lines( $body ) ) );
				if ( ! $live ) {
					continue;
				}
				$map                                      = Strings::translate_texts( array_merge( array( trim( $subject ) ), self::lines( $body ) ), $lang );
				$notifications[ $i ][ $j ]['subject']    = self::put( $subject, $map );
				$notifications[ $i ][ $j ]['email_body'] = self::put_body( $body, $map );
			}
		}
		return $notifications;
	}

	/**
	 * Whether the Everest Forms notification about to be built goes to the visitor.
	 *
	 * @var bool
	 */
	private $evf_to_visitor = false;

	/**
	 * Note whether the next Everest Forms notification is sent to a field of the form ({field_id="…"}).
	 *
	 * @param mixed $process       Whether it is sent.
	 * @param mixed $fields        Submitted fields.
	 * @param mixed $form_data     Form settings.
	 * @param mixed $context       Context.
	 * @param mixed $connection_id The notification.
	 * @return mixed
	 */
	public function everest_which( $process, $fields = null, $form_data = null, $context = null, $connection_id = null ) {
		unset( $fields, $context );
		$to                   = is_array( $form_data ) ? (string) ( $form_data['settings']['email'][ $connection_id ]['evf_to_email'] ?? '' ) : '';
		$this->evf_to_visitor = false !== strpos( $to, '{field_id=' );
		return $process;
	}

	/**
	 * An Everest Forms notification to the visitor, before its smart tags are filled in.
	 *
	 * @param mixed $email Subject, message, addresses…
	 * @return mixed
	 */
	public function everest_notification( $email ) {
		if ( ! $this->evf_to_visitor || ! is_array( $email ) ) {
			return $email;
		}
		$this->evf_to_visitor = false;
		$subject              = (string) ( $email['subject'] ?? '' );
		$message              = (string) ( $email['message'] ?? '' );
		self::remember( self::EVEREST_URL, array_merge( array( $subject ), self::lines( $message ) ) );

		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! Strings::has_translations( $lang ) ) {
			return $email;
		}
		$map              = Strings::translate_texts( array_merge( array( trim( $subject ) ), self::lines( $message ) ), $lang );
		$email['subject'] = self::put( $subject, $map );
		$email['message'] = self::put_body( $message, $map );
		return $email;
	}

	/**
	 * Formidable's email action as stored, while its translation is being sent (the action object is shared).
	 *
	 * @var array<int,array{0:object,1:mixed}>
	 */
	private $frm_saved = array();

	/**
	 * A Formidable «Email» action sent to a field of the form ([25] or [email-key], the visitor's e-mail):
	 * subject and message in the visitor's language, the [shortcodes] untouched.
	 *
	 * @param mixed $action The action (a post object; its post_content holds the settings).
	 */
	public function formidable_email( $action ): void {
		if ( ! is_object( $action ) || ! isset( $action->post_content ) || ! is_array( $action->post_content ) ) {
			return;
		}
		$settings = $action->post_content;
		preg_match_all( '/\[([^\]\s]+)/', (string) ( $settings['email_to'] ?? '' ), $m );
		if ( ! array_diff( $m[1], array( 'admin_email', 'default-email', 'default-from-email', 'sitename' ) ) ) {
			return; // Not to the visitor.
		}
		$subject = (string) ( $settings['email_subject'] ?? '' );
		$message = (string) ( $settings['email_message'] ?? '' );
		self::remember( self::FORMIDABLE_URL, array_merge( array( $subject ), self::lines( $message ) ) );

		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! Strings::has_translations( $lang ) ) {
			return;
		}
		$map                       = Strings::translate_texts( array_merge( array( trim( $subject ) ), self::lines( $message ) ), $lang );
		$this->frm_saved[]         = array( $action, $action->post_content );
		$settings['email_subject'] = self::put( $subject, $map );
		$settings['email_message'] = self::put_body( $message, $map );
		$action->post_content      = $settings;
	}

	/**
	 * Put Formidable's action back as stored once its e-mail has been sent.
	 */
	public function formidable_restore(): void {
		foreach ( $this->frm_saved as $saved ) {
			$saved[0]->post_content = $saved[1];
		}
		$this->frm_saved = array();
	}

	/**
	 * A Ninja Forms «Email» action sent to a field of the form (the visitor's e-mail): subject and message
	 * (HTML or plain text) in the visitor's language.
	 *
	 * @param mixed $settings The action's settings.
	 * @return mixed
	 */
	public function ninja_email( $settings ) {
		// The «Success Message» action: what the visitor reads once the form is sent.
		if ( is_array( $settings ) && 'successmessage' === ( $settings['type'] ?? '' ) && isset( $settings['success_msg'] ) && is_string( $settings['success_msg'] ) ) {
			$settings['success_msg'] = self::message( $settings['success_msg'] );
			return $settings;
		}
		if ( ! is_array( $settings ) || 'email' !== ( $settings['type'] ?? '' ) || false === strpos( (string) ( $settings['to'] ?? '' ), '{field:' ) ) {
			return $settings;
		}
		$keys  = array( 'email_message', 'email_message_plain' );
		$texts = array( (string) ( $settings['email_subject'] ?? '' ) );
		foreach ( $keys as $k ) {
			$texts = array_merge( $texts, self::lines( (string) ( $settings[ $k ] ?? '' ) ) );
		}
		self::remember( self::NINJA_URL, $texts );

		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! Strings::has_translations( $lang ) ) {
			return $settings;
		}
		$map = Strings::translate_texts( array_map( 'trim', $texts ), $lang );
		if ( isset( $settings['email_subject'] ) ) {
			$settings['email_subject'] = self::put( (string) $settings['email_subject'], $map );
		}
		foreach ( $keys as $k ) {
			if ( isset( $settings[ $k ] ) && is_string( $settings[ $k ] ) ) {
				$settings[ $k ] = self::put_body( $settings[ $k ], $map );
			}
		}
		return $settings;
	}

	/**
	 * A Fluent Forms e-mail notification. Only the one whose «Send To» is a field of the form (the visitor's e-mail)
	 * changes language. Its message is HTML from the editor («<p>Dear {inputs.names},</p>»): only the text between
	 * the tags is translated.
	 *
	 * @param mixed $feed      The notification.
	 * @param mixed $insert_id Entry.
	 * @param mixed $form_data Submitted data.
	 * @param mixed $form      Form.
	 * @return mixed
	 */
	public function fluent_notification( $feed, $insert_id = null, $form_data = null, $form = null ) {
		unset( $insert_id, $form_data, $form );
		if ( ! is_array( $feed ) || 'notifications' !== ( $feed['meta_key'] ?? '' ) || ! isset( $feed['settings'] ) || ! is_array( $feed['settings'] ) ) {
			return $feed;
		}
		$settings = $feed['settings'];
		if ( 'field' !== ( $settings['sendTo']['type'] ?? '' ) ) {
			return $feed; // Not to the visitor.
		}
		$subject = isset( $settings['subject'] ) ? (string) $settings['subject'] : '';
		$message = isset( $settings['message'] ) ? (string) $settings['message'] : '';
		self::remember( self::FLUENT_URL, array_merge( array( $subject ), self::lines( $message ) ) );

		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! Strings::has_translations( $lang ) ) {
			return $feed;
		}
		$map                         = Strings::translate_texts( array_merge( array( trim( $subject ) ), self::lines( $message ) ), $lang );
		$feed['settings']['subject'] = self::put( $subject, $map );
		$feed['settings']['message'] = self::put_body( $message, $map );
		return $feed;
	}

	/**
	 * A WPForms notification, before its smart tags ({field_id="1"}, {all_fields}) are filled in.
	 * Only the one sent to the visitor — its «Send To» is a field of the form — changes language;
	 * the ones to the site's owner keep the site's language.
	 *
	 * @param mixed $email           Subject, message, addresses…
	 * @param mixed $fields          Submitted fields.
	 * @param mixed $entry           Entry.
	 * @param mixed $form_data       Form settings.
	 * @param mixed $notification_id Notification.
	 * @return mixed
	 */
	public function wpforms_notification( $email, $fields = null, $entry = null, $form_data = null, $notification_id = null ) {
		unset( $fields, $entry );
		if ( ! is_array( $email ) || ! is_array( $form_data ) ) {
			return $email;
		}
		$send_to = (string) ( $form_data['settings']['notifications'][ $notification_id ]['email'] ?? '' );
		if ( false === strpos( $send_to, '{field_id=' ) ) {
			return $email; // Not to the visitor.
		}
		$subject = isset( $email['subject'] ) ? (string) $email['subject'] : '';
		$message = isset( $email['message'] ) ? (string) $email['message'] : '';
		self::remember( self::WPFORMS_URL, array_merge( array( $subject ), self::lines( $message ) ) );

		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! Strings::has_translations( $lang ) ) {
			return $email;
		}
		$map              = Strings::translate_texts( array_merge( array( trim( $subject ) ), self::lines( $message ) ), $lang );
		$email['subject'] = self::put( $subject, $map );
		$email['message'] = self::put_body( $message, $map );
		return $email;
	}

	/**
	 * Contact Form 7's automatic reply, read while a form is being sent.
	 *
	 * @param mixed $mail The «mail_2» property.
	 * @param mixed $form The contact form.
	 * @return mixed
	 */
	public function cf7_reply( $mail, $form = null ) {
		unset( $form );
		// Only while a visitor's submission is processed (the property is read in the editor too).
		if ( ! is_array( $mail ) || empty( $mail['active'] ) || ! isset( $_POST['_wpcf7'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only check of who is sending; Contact Form 7 verifies the submission.
			return $mail;
		}
		$subject = isset( $mail['subject'] ) ? (string) $mail['subject'] : '';
		$body    = isset( $mail['body'] ) ? (string) $mail['body'] : '';
		self::remember( self::CF7_URL, array_merge( array( $subject ), self::lines( $body ) ) );

		$router = Plugin::instance()->router();
		$lang   = $router->request_language();
		if ( '' === $lang || $router->is_default( $lang ) || ! Strings::has_translations( $lang ) ) {
			return $mail;
		}
		$map             = Strings::translate_texts( array_merge( array( trim( $subject ) ), self::lines( $body ) ), $lang );
		$mail['subject'] = self::put( $subject, $map );
		$mail['body']    = self::put_body( $body, $map );
		return $mail;
	}

	/**
	 * A template cut into lines and, when it is HTML, into the text between its tags.
	 *
	 * @param string $body Template body.
	 * @return string[] Text at even keys; the tags and line breaks between them at odd keys.
	 */
	private static function pieces( string $body ): array {
		return (array) preg_split( '/(<[^>]*>|\r?\n)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE );
	}

	/**
	 * The template with each sentence replaced by its translation; tags and line breaks untouched.
	 *
	 * @param string               $body Template body.
	 * @param array<string,string> $map  Translations.
	 */
	private static function put_body( string $body, array $map ): string {
		$out = '';
		foreach ( self::pieces( $body ) as $i => $piece ) {
			$out .= ( $i % 2 ) ? $piece : self::put( $piece, $map );
		}
		return $out;
	}

	/**
	 * The wording of a template, one sentence per line («Dear [your-name],», «We received…»).
	 *
	 * @param string $body Template body.
	 * @return string[]
	 */
	private static function lines( string $body ): array {
		$out = array();
		foreach ( self::pieces( $body ) as $i => $line ) {
			if ( $i % 2 ) {
				continue; // A tag or a line break.
			}
			$t = trim( $line );
			// A line made only of tags («[your-message]») or without words is not a sentence.
			if ( '' === $t || ! preg_match( '/\p{L}/u', (string) preg_replace( self::TAGS, '', $t ) ) ) {
				continue;
			}
			$out[] = $t;
		}
		return $out;
	}

	/**
	 * A line with its translation, if the translation kept every [tag] of the original.
	 *
	 * @param string               $line Original line (untrimmed).
	 * @param array<string,string> $map  Translations.
	 */
	private static function put( string $line, array $map ): string {
		$t = trim( $line );
		if ( '' === $t || ! isset( $map[ $t ] ) || '' === $map[ $t ] ) {
			return $line;
		}
		preg_match_all( self::TAGS, $t, $prima );
		preg_match_all( self::TAGS, $map[ $t ], $dopo );
		$a = $prima[0];
		$b = $dopo[0];
		sort( $a );
		sort( $b );
		if ( $a !== $b ) {
			return $line; // A tag lost or renamed by the translation: the original line is safer.
		}
		return str_replace( $t, $map[ $t ], $line );
	}

	/**
	 * Make the wording translatable (filed under its own heading), at most once a day per template.
	 *
	 * @param string   $url   Heading in the translation screens.
	 * @param string[] $texts Sentences.
	 */
	private static function remember( string $url, array $texts ): void {
		$targets = array_values( (array) ( Settings::get()['target_languages'] ?? array() ) );
		$items   = array();
		foreach ( $texts as $t ) {
			$t = trim( (string) $t );
			if ( '' === $t || ! preg_match( '/\p{L}/u', $t ) || NoTranslate::text_excluded( $t ) ) {
				continue;
			}
			$items[ $t ] = array( 'original' => $t, 'type' => 'text' );
		}
		if ( empty( $targets ) || empty( $items ) ) {
			return;
		}
		$key = 'trrocket_formmail_' . md5( $url . implode( "\n", array_keys( $items ) ) );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		// The heading's name is given again in the reader's language when shown (Strings::heading_name).
		Strings::remember_batch( array_values( $items ), $targets, $url, Strings::heading_name( $url, '' ) );
	}
}
