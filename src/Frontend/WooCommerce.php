<?php
/**
 * WooCommerce glue.
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
 * Translate the parts WooCommerce renders outside the page HTML, which the page
 * engine can't reach, reusing the same translation map (no extra AI calls):
 *
 *  - JS-localised script strings (e.g. the "View cart" link added after an AJAX
 *    add-to-cart) and AJAX cart fragments (mini-cart markup);
 *  - transactional customer emails, sent in the language the order was placed in.
 */
class WooCommerce {

	/**
	 * Current request's secondary language ('' when browsing the default one).
	 *
	 * @var string
	 */
	private $lang = '';

	/**
	 * Language of the email currently being built ('' = don't translate).
	 *
	 * @var string
	 */
	private $email_lang = '';

	/**
	 * Whether the email being assembled is a customer-facing one, regardless of
	 * the language the order was placed in. See collect_email_strings().
	 *
	 * @var bool
	 */
	private $email_is_customer = false;

	/**
	 * True while an e-mail body is being written: its product names follow the
	 * e-mail's reader, never the page the request came from.
	 *
	 * @var bool
	 */
	private $in_email = false;

	/**
	 * Language of the email whose body has just been translated, kept until the
	 * subject is built.
	 *
	 * WooCommerce assembles the body first and only then hands over the subject
	 * (class-wc-email.php:1234 and :1236), so the subject arrives after
	 * email_content() has already reset $email_lang.
	 *
	 * @var string
	 */
	private $subject_lang = '';

	/**
	 * Where email-only wording is filed, so it appears in the translation screens
	 * under its own heading instead of being mixed into a real page.
	 */
	const EMAIL_URL = '[woocommerce emails]';

	/**
	 * Hook into WooCommerce.
	 */
	public function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( empty( Settings::get()['translate_interface'] ) ) {
			return;
		}

		// Remember the language each order was placed in (classic + block checkout).
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_order_language' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'store_order_language' ), 10, 1 );

		// Translate transactional customer emails into the order's language.
		add_action( 'woocommerce_email_order_details', array( $this, 'capture_email_language' ), 1, 4 );
		add_filter( 'woocommerce_mail_content', array( $this, 'email_content' ), 20 );
		// L'oggetto NON passa da woocommerce_mail_content: viene costruito a parte e
		// consegnato qui (class-wc-email.php:1236). Senza questo filtro il cliente
		// tedesco riceveva il messaggio in tedesco con l'oggetto in italiano.
		add_filter( 'woocommerce_mail_callback_params', array( $this, 'email_subject' ), 20, 2 );

		// Each e-mail is BUILT in its reader's language, so WooCommerce's own language
		// pack writes it: the customer's in the order's language even when the shop
		// manager clicks «Completed» in an English admin (the subject stayed English),
		// the shop's own notice in the site language even while the customer checks out
		// on /it/ (it arrived in Italian). 28/09/2026, from the competitors' forums.
		if ( did_action( 'woocommerce_email' ) && function_exists( 'WC' ) ) {
			$this->hook_emails( WC()->mailer() );
		} else {
			add_action( 'woocommerce_email', array( $this, 'hook_emails' ) );
		}
		add_filter( 'woocommerce_allow_restoring_email_locale', array( $this, 'email_done' ), 1 );
		add_action( 'woocommerce_email_sent', array( $this, 'email_done' ), 1 );

		// Product names that WooCommerce puts inside a sentence or glues together
		// («“Blue mug” has been added to your cart», «Linen shirt - Blue»): the page
		// engine only knows whole texts, so they stayed in the source language in the
		// cart, on the thank-you page and in the e-mails.
		add_filter( 'woocommerce_order_item_name', array( $this, 'order_item_name' ), 20, 2 );

		// Send the customer back to the thank-you page in the language they ordered in.
		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'order_received_url' ), 20, 2 );

		// Per-request extras (translated page or front-end AJAX): JS strings +
		// cart fragments. These need the current request to be a secondary language.
		$router = Plugin::instance()->router();
		$lang   = $router->current_language();
		if ( $router->is_default( $lang ) ) {
			return;
		}
		if ( ! Strings::has_translations( $lang ) ) {
			return;
		}
		$this->lang = $lang;
		add_filter( 'woocommerce_get_script_data', array( $this, 'script_data' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'fragments' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_blocks' ), 20 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'cart_item_name' ), 20 );
		add_filter( 'wc_add_to_cart_message_html', array( $this, 'add_to_cart_message' ), 20, 2 );
		// The quantity box's label for screen readers: «Blue mug quantità».
		add_filter( 'woocommerce_quantity_input_args', array( $this, 'quantity_args' ), 20 );
	}

	/**
	 * Product name in the quantity box's accessible label.
	 *
	 * @param mixed $args Quantity input arguments.
	 * @return mixed
	 */
	public function quantity_args( $args ) {
		if ( is_array( $args ) && isset( $args['product_name'] ) && is_string( $args['product_name'] ) && '' !== $this->lang ) {
			$args['product_name'] = self::translate_name( $args['product_name'], $this->lang );
		}
		return $args;
	}

	/**
	 * Watch every WooCommerce e-mail: its recipient is asked for right after the
	 * order is known and before the subject is written, the one moment to switch.
	 *
	 * @param mixed $mailer WC_Emails.
	 */
	public function hook_emails( $mailer ): void {
		if ( ! is_object( $mailer ) || ! method_exists( $mailer, 'get_emails' ) ) {
			return;
		}
		foreach ( (array) $mailer->get_emails() as $email ) {
			if ( is_object( $email ) && ! empty( $email->id ) ) {
				add_filter( 'woocommerce_email_recipient_' . $email->id, array( $this, 'email_language' ), 1, 3 );
			}
		}
	}

	/**
	 * Switch to the reader's language while this e-mail is built.
	 *
	 * @param mixed $recipient Recipient(s), returned untouched.
	 * @param mixed $item      The order (or other object) the e-mail is about.
	 * @param mixed $email     WC_Email.
	 * @return mixed
	 */
	public function email_language( $recipient, $item = null, $email = null ) {
		if ( ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) ) {
			return $recipient;
		}
		if ( $item instanceof \WP_User ) {
			// «New account», «reset password»: the language of the page they signed up
			// on (saved on their profile by Locale::remember_user_language), else the
			// page they are on right now.
			$locale = (string) get_user_meta( $item->ID, 'locale', true );
			if ( '' === $locale ) {
				$lang   = Plugin::instance()->router()->request_language();
				$locale = ( '' !== $lang && ! Plugin::instance()->router()->is_default( $lang ) ) ? \TranslateRocket\Languages::locale( $lang ) : '';
			}
			if ( '' !== $locale && $locale !== determine_locale() ) {
				Locale::force( $locale );
			}
			return $recipient;
		}
		if ( ! ( $item instanceof \WC_Order ) ) {
			return $recipient;
		}
		if ( $email->is_customer_email() ) {
			$lang   = (string) $item->get_meta( '_trrocket_lang' );
			$locale = '' !== $lang ? \TranslateRocket\Languages::locale( $lang ) : '';
		} else {
			$locale = (string) get_option( 'WPLANG' );
			$locale = '' !== $locale ? $locale : 'en_US';
		}
		if ( '' !== $locale && $locale !== determine_locale() ) {
			Locale::force( $locale );
		}
		return $recipient;
	}

	/**
	 * The e-mail is out (or given up): back to the request's own language.
	 *
	 * @param mixed $pass Returned untouched (also used as an action).
	 * @return mixed
	 */
	public function email_done( $pass = true ) {
		Locale::restore();
		$this->in_email = false;
		return $pass;
	}

	/**
	 * A product or order-line name in a language, including the names WooCommerce
	 * builds itself: «Linen shirt - Blue» is the product name and the attribute
	 * value joined, a text nobody ever translated as a whole.
	 *
	 * @param string $name Name as WooCommerce prints it (plain text).
	 * @param string $lang Target language.
	 */
	public static function translate_name( string $name, string $lang ): string {
		$name = trim( $name );
		if ( '' === $name || '' === $lang ) {
			return $name;
		}
		// Only a name WooCommerce glued («Shirt - Red, Large») is taken apart; a plain
		// name with a comma in it («Salt, pepper and oil») is translated whole or not at all.
		$pieces = preg_match( '/\s[-–]\s/u', $name ) ? preg_split( '/(\s+[-–]\s+|,\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE ) : array( $name );
		$texts  = array( $name );
		foreach ( (array) $pieces as $i => $piece ) {
			if ( 0 === $i % 2 ) {
				$texts[] = trim( html_entity_decode( (string) $piece, ENT_QUOTES, 'UTF-8' ) );
			}
		}
		$map = Strings::translate_texts( array_values( array_unique( array_filter( $texts ) ) ), $lang );
		if ( isset( $map[ $name ] ) && '' !== $map[ $name ] ) {
			return $map[ $name ];
		}
		if ( ! is_array( $pieces ) || count( $pieces ) < 3 ) {
			return $name;
		}
		$out  = '';
		$some = false;
		foreach ( $pieces as $i => $piece ) {
			$t = trim( html_entity_decode( (string) $piece, ENT_QUOTES, 'UTF-8' ) );
			if ( 0 === $i % 2 && isset( $map[ $t ] ) && '' !== $map[ $t ] ) {
				$out .= $map[ $t ];
				$some = true;
			} else {
				$out .= $piece;
			}
		}
		return $some ? $out : $name;
	}

	/**
	 * Replace the visible text of a name, keeping the link around it.
	 *
	 * @param string $html Name, possibly wrapped in <a>.
	 * @param string $lang Target language.
	 */
	private static function translate_name_html( string $html, string $lang ): string {
		return (string) preg_replace_callback(
			'/(^|>)([^<>]+)(<|$)/u',
			static function ( $m ) use ( $lang ) {
				$t = trim( $m[2] );
				if ( '' === $t || ! preg_match( '/\p{L}/u', $t ) ) {
					return $m[0];
				}
				$tr = self::translate_name( html_entity_decode( $t, ENT_QUOTES, 'UTF-8' ), $lang );
				return $m[1] . str_replace( $t, esc_html( $tr ), $m[2] ) . $m[3];
			},
			$html
		);
	}

	/**
	 * Name of a line in the classic cart and mini-cart.
	 *
	 * @param mixed $name Name HTML.
	 * @return mixed
	 */
	public function cart_item_name( $name ) {
		if ( ! is_string( $name ) || '' === $this->lang ) {
			return $name;
		}
		return self::translate_name_html( $name, $this->lang );
	}

	/**
	 * Name of an order line: thank-you page, «My account», and customer e-mails
	 * (in the order's language, whatever page sent them).
	 *
	 * @param mixed $name Name HTML.
	 * @param mixed $item WC_Order_Item.
	 * @return mixed
	 */
	public function order_item_name( $name, $item = null ) {
		if ( ! is_string( $name ) ) {
			return $name;
		}
		$lang = $this->email_lang;
		if ( '' === $lang && ! $this->in_email && '' !== $this->lang && ! is_admin() ) {
			$lang = $this->lang;
		}
		if ( '' === $lang || Plugin::instance()->router()->is_default( $lang ) ) {
			return $name;
		}
		unset( $item );
		return self::translate_name_html( $name, $lang );
	}

	/**
	 * «“Blue mug” has been added to your cart»: WooCommerce slips the name into the
	 * sentence, so the page engine sees one text it has never met.
	 *
	 * @param mixed $message  Notice HTML.
	 * @param mixed $products Product id => quantity.
	 * @return mixed
	 */
	public function add_to_cart_message( $message, $products = array() ) {
		if ( ! is_string( $message ) || '' === $this->lang || ! is_array( $products ) ) {
			return $message;
		}
		foreach ( array_keys( $products ) as $id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				continue;
			}
			foreach ( array_unique( array( $product->get_name(), wp_strip_all_tags( (string) get_the_title( $id ) ) ) ) as $orig ) {
				$orig = trim( (string) $orig );
				if ( '' === $orig ) {
					continue;
				}
				$tr = self::translate_name( html_entity_decode( $orig, ENT_QUOTES, 'UTF-8' ), $this->lang );
				if ( $tr !== $orig ) {
					$message = str_replace( array( $orig, esc_html( $orig ) ), esc_html( $tr ), $message );
				}
			}
		}
		return $message;
	}

	/**
	 * The block Cart/Checkout render client-side, so the page engine can't reach
	 * their text. Ship a tiny translator that swaps text in the block containers
	 * (best-effort, re-applied after React re-renders). Loaded only where a block
	 * cart/checkout/mini-cart can appear.
	 */
	public function enqueue_blocks(): void {
		if ( '' === $this->lang ) {
			return;
		}
		$here = ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) )
			|| ( function_exists( 'has_block' ) && (
				has_block( 'woocommerce/cart' ) || has_block( 'woocommerce/checkout' ) || has_block( 'woocommerce/mini-cart' )
			) );
		if ( ! $here ) {
			return;
		}
		// The block translator needs a map in the browser. On huge sites shipping
		// the whole language map is impossible — the interface map (where Woo's
		// UI strings live) is the bounded, relevant subset.
		$map = Strings::is_huge()
			? Strings::map_for_page( Strings::url_hash( \TranslateRocket\Frontend\Gettext::INTERFACE_URL ), $this->lang )
			: Strings::map_for_language( $this->lang );
		if ( empty( $map ) ) {
			return;
		}
		$handle = 'trrocket-woo-blocks';
		wp_register_script(
			$handle,
			TRROCKET_URL . 'assets/js/woo-blocks.js',
			array(),
			Plugin::asset_ver( 'assets/js/woo-blocks.js' ),
			true
		);
		wp_localize_script( $handle, 'trrocketWooBlocks', array( 'map' => $map ) );
		wp_enqueue_script( $handle );
	}

	/**
	 * Tag a new order with the language the customer was browsing in.
	 *
	 * @param mixed $order WC_Order.
	 */
	public function store_order_language( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$lang = Plugin::instance()->router()->request_language();
		if ( '' === $lang ) {
			return;
		}
		$order->update_meta_data( '_trrocket_lang', $lang );
		// The Store API has already created the order, so persist the meta now;
		// the classic checkout saves the order itself right after this hook.
		if ( 'woocommerce_store_api_checkout_order_processed' === current_action() && method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * Put the order's language prefix on the "Order received" URL.
	 *
	 * The block checkout places the order through the Store API, whose own URL has
	 * no language prefix, so WooCommerce builds the thank-you link in the default
	 * language: a customer who shopped in Italian landed on an English page.
	 *
	 * @param mixed $url   Thank-you page URL.
	 * @param mixed $order WC_Order.
	 * @return mixed
	 */
	public function order_received_url( $url, $order = null ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		$router = Plugin::instance()->router();
		$lang   = ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) ? (string) $order->get_meta( '_trrocket_lang' ) : '';
		if ( '' === $lang ) {
			$lang = $router->request_language();
		}
		if ( '' === $lang || $router->is_default( $lang ) || ! in_array( $lang, $router->secondary_languages(), true ) ) {
			return $url;
		}
		$home = rtrim( (string) get_option( 'home' ), '/' );
		if ( 0 !== strpos( $url, $home . '/' ) ) {
			return $url;
		}
		$rest = substr( $url, strlen( $home ) );
		if ( preg_match( '#^/' . preg_quote( $lang, '#' ) . '(/|$)#', $rest ) ) {
			return $url; // Already localised (e.g. built on a translated page).
		}
		return $home . '/' . $lang . $rest;
	}

	/**
	 * Remember the order's language while a CUSTOMER email is being built. Admin
	 * notifications are left in the site language.
	 *
	 * @param mixed $order         WC_Order.
	 * @param bool  $sent_to_admin Whether this email goes to the admin.
	 */
	public function capture_email_language( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		unset( $plain_text, $email );
		$this->email_lang        = '';
		$this->email_is_customer = false;
		$this->in_email          = true;
		if ( $sent_to_admin ) {
			return;
		}
		$this->email_is_customer = true;
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
			$this->email_lang = (string) $order->get_meta( '_trrocket_lang' );
		}
	}


	/**
	 * Translate the subject of a customer email into the order's language.
	 *
	 * The subject is a single line of text, not HTML: it is collected like any
	 * other phrase (nobody ever browses an email, so the page collector can never
	 * reach it) and swapped when a translation exists.
	 *
	 * @param array $params Arguments for wp_mail(): to, subject, message, headers, attachments.
	 * @param mixed $email  The WC_Email being sent.
	 * @return array
	 */
	public function email_subject( $params, $email = null ) {
		unset( $email );
		$lang               = $this->subject_lang;
		$this->subject_lang = '';

		if ( ! is_array( $params ) || ! isset( $params[1] ) || ! is_string( $params[1] ) ) {
			return $params;
		}
		$subject = trim( $params[1] );
		if ( '' === $subject || ! preg_match( '/\p{L}/u', $subject ) || NoTranslate::text_excluded( $subject ) ) {
			return $params;
		}

		$settings = Settings::get();
		$targets  = array_values( (array) ( $settings['target_languages'] ?? array() ) );
		if ( ! empty( $targets ) ) {
			Strings::remember_batch(
				array( array( 'original' => $subject, 'type' => 'text' ) ),
				$targets,
				self::EMAIL_URL,
				__( 'WooCommerce emails', 'translate-rocket' )
			);
		}

		if ( '' === $lang ) {
			return $params;
		}
		$map = Strings::translate_texts( array( $subject ), $lang );
		if ( isset( $map[ $subject ] ) && '' !== $map[ $subject ] ) {
			$params[1] = $map[ $subject ];
		}
		return $params;
	}

	/**
	 * Translate the assembled email HTML into the order's language.
	 *
	 * @param mixed $content Email HTML.
	 * @return mixed
	 */
	public function email_content( $content ) {
		$lang                    = $this->email_lang;
		$is_customer             = $this->email_is_customer;
		$this->subject_lang      = $is_customer ? $lang : '';
		$this->email_lang        = ''; // Reset for the next email in this request.
		$this->email_is_customer = false;
		$this->in_email          = false;

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return $content;
		}

		// Learn the wording first, and do it for every customer email including
		// the ones placed in the default language. Email templates are the one
		// place the browsing collector can never reach — nobody visits an email —
		// so without this the phrases WooCommerce only prints in emails ("Thank
		// you for your order", "Quantity", "Price") would never become
		// translatable at all.
		if ( $is_customer ) {
			$this->collect_email_strings( $content );
		}

		if ( '' === $lang ) {
			return $content;
		}
		if ( Plugin::instance()->router()->is_default( $lang ) ) {
			return $content; // Order placed in the source language: nothing to do.
		}
		if ( ! Strings::has_translations( $lang ) ) {
			return $content;
		}
		return $this->translate_html( $content, $lang );
	}

	/**
	 * Record the translatable wording of a customer email so it shows up in the
	 * translation screens like any other string.
	 *
	 * Runs at most once per email type per day: the templates barely change, and
	 * an order confirmation is not a good moment to do avoidable work.
	 *
	 * @param string $html Assembled email HTML.
	 */
	private function collect_email_strings( string $html ): void {
		$settings = Settings::get();
		$targets  = array_values( (array) ( $settings['target_languages'] ?? array() ) );
		if ( empty( $targets ) || ! class_exists( '\DOMDocument' ) ) {
			return;
		}

		// One template's wording is the same for every order; a transient keyed on
		// the content keeps repeat orders from re-running this.
		$key = 'trrocket_wcmail_' . md5( $html );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );

		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$ok   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return;
		}

		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::title)]' );
		if ( ! $nodes ) {
			return;
		}

		$items = array();
		foreach ( $nodes as $node ) {
			$text = trim( (string) $node->nodeValue );
			if ( '' === $text || ! preg_match( '/\p{L}/u', $text ) ) {
				continue; // prices, quantities, punctuation: nothing to translate
			}
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) > 400 : strlen( $text ) > 400 ) {
				continue;
			}
			if ( NoTranslate::text_excluded( $text ) ) {
				continue;
			}
			$items[ $text ] = array( 'original' => $text, 'type' => 'text' );
		}
		if ( empty( $items ) ) {
			return;
		}

		Strings::remember_batch(
			array_values( $items ),
			$targets,
			self::EMAIL_URL,
			__( 'WooCommerce emails', 'translate-rocket' )
		);
	}

	/**
	 * Translate the user-facing strings in a Woo script's localised data
	 * (the i18n_* keys, e.g. i18n_view_cart).
	 *
	 * @param mixed  $data   Localised data array.
	 * @param string $handle Script handle.
	 * @return mixed
	 */
	public function script_data( $data, $handle ) {
		unset( $handle );
		if ( ! is_array( $data ) || '' === $this->lang ) {
			return $data;
		}
		$texts = array();
		foreach ( $data as $key => $val ) {
			if ( is_string( $val ) && 0 === strpos( (string) $key, 'i18n_' ) ) {
				$texts[] = trim( $val );
			}
		}
		$map = Strings::translate_texts( $texts, $this->lang );
		if ( empty( $map ) ) {
			return $data;
		}
		foreach ( $data as $key => $val ) {
			if ( is_string( $val ) && 0 === strpos( (string) $key, 'i18n_' ) ) {
				$t = trim( $val );
				if ( '' !== $t && isset( $map[ $t ] ) ) {
					$data[ $key ] = str_replace( $t, $map[ $t ], $val );
				}
			}
		}
		return $data;
	}

	/**
	 * Translate the visible text inside AJAX cart fragments.
	 *
	 * @param mixed $fragments Fragment HTML keyed by selector.
	 * @return mixed
	 */
	public function fragments( $fragments ) {
		if ( ! is_array( $fragments ) || '' === $this->lang ) {
			return $fragments;
		}
		foreach ( $fragments as $key => $html ) {
			if ( is_string( $html ) && '' !== trim( $html ) ) {
				$fragments[ $key ] = $this->translate_html( $html, $this->lang );
			}
		}
		return $fragments;
	}

	/**
	 * Translate the text nodes of an HTML blob into a language, resolving the
	 * translations for exactly the texts it contains (two-phase: collect, batch
	 * lookup, replace — huge-safe). Handles both full HTML documents (emails)
	 * and bare fragments. Defensive: returns the original markup unchanged on
	 * any parse problem or when nothing matched.
	 */
	private function translate_html( string $html, string $lang ): string {
		if ( '' === trim( $html ) || '' === $lang || ! class_exists( '\DOMDocument' ) ) {
			return $html;
		}
		$is_doc = ( false !== stripos( $html, '<body' ) || false !== stripos( $html, '<html' ) );

		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$load = '<?xml encoding="utf-8" ?>' . ( $is_doc ? $html : '<div id="trr-frag">' . $html . '</div>' );
		$ok   = $dom->loadHTML( $load, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return $html;
		}

		$xpath = new \DOMXPath( $dom );
		$scope = $is_doc
			? $xpath->query( '//body' )->item( 0 )
			: $xpath->query( '//*[@id="trr-frag"]' )->item( 0 );
		if ( null === $scope ) {
			return $html;
		}

		$changed = false;
		$texts   = $xpath->query( './/text()', $scope );
		$nodes   = $texts ? iterator_to_array( $texts ) : array();

		$candidates = array();
		foreach ( $nodes as $node ) {
			$candidates[] = trim( (string) $node->nodeValue );
		}
		$map = Strings::translate_texts( $candidates, $lang );

		foreach ( $nodes as $node ) {
			$val = (string) $node->nodeValue;
			$t   = trim( $val );
			if ( '' !== $t && isset( $map[ $t ] ) ) {
				$node->nodeValue = str_replace( $t, $map[ $t ], $val );
				$changed         = true;
			}
		}
		if ( ! $changed ) {
			return $html;
		}

		if ( $is_doc ) {
			// Node serialization keeps raw UTF-8 — whole-document saveHTML() would
			// entity-encode non-ASCII characters (see the note in Engine::process).
			$out = (string) $dom->saveHTML( $dom->documentElement );
			if ( null !== $dom->doctype && '' !== $out ) {
				$out = '<!DOCTYPE ' . $dom->doctype->name . '>' . "\n" . $out;
			}
			return '' !== $out ? $out : $html;
		}

		$out = '';
		foreach ( $scope->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}
		return '' !== $out ? $out : $html;
	}
}
