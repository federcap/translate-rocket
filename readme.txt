=== TranslateRocket – Free Multilingual Translation, Unlimited Languages ===
Contributors: federicodev
Donate link: https://translaterocket.com/donate/
Tags: translate, translation, multilingual, language, woocommerce
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate your whole site with no API key — Chrome and Edge do it on your own computer. Unlimited languages, free, no paid tier.

== Description ==

https://www.youtube.com/watch?v=WsLpAISB_vo

**TranslateRocket makes your site multilingual, and everything it does is free.** No word limits, no charge per language, and nothing switched off to push you towards an upgrade — it is not a trimmed-down version of a paid plugin.

**Already using WPML, Polylang, TranslatePress or Weglot? Try TranslateRocket next to it — your site does not change.** Activate it alongside your current translation plugin: visitors keep seeing exactly the same site, while you import your translations, complete them and preview any page in any language as an administrator. When you are convinced, deactivate the old plugin and put TranslateRocket online with one click. Nothing of the other plugin is deleted, and switching it back on takes you straight back to where you started.

**You do not need an API key at all.** Chrome and Edge on a computer carry a translator that runs on the device itself, and TranslateRocket can drive it: install the plugin, pick your languages, press one button and the site is translated. It costs nothing, needs no account, and the text never leaves your machine. It is the fastest way to a translated site. When you want fluent, idiomatic copy, the same pages can go through DeepL or an AI model with your own key (OpenAI, Anthropic, Gemini — you pay the provider directly), through ChatGPT or Gemini with a ready-made prompt at no cost, or through your own hands, line by line — and everything already translated is kept, so nothing is ever done twice.

**Switching from another translation plugin? Your existing translations come with you.** One click imports everything you have already paid for or typed by hand — from **TranslatePress, Polylang, WPML, Weglot, qTranslate (X and XT), WPGlobus, WP Multilang, Bogo, Multilanguage by BestWebSoft, Falang, Sublanguage, Loco Translate and Autoglot**, plus CSV and TMX for anything else. Most are read straight from the tables, the posts or the files, so the import still works after the other plugin has been deactivated or even deleted — which is the usual state of a site that is moving on. Pages built with Elementor or Bricks come across too, widget by widget — their words live in a meta field, not in the page content, and most importers never look there.

It detects translatable text as you browse — visible text, link titles, image alt, placeholders, ARIA labels, page titles, meta descriptions and the social preview tags a SEO plugin adds (Open Graph, X) — and serves each language under its own clean URL prefix (`/it/`, `/de/`), friendly to SEO and to page caches.

You translate however you like:

* 🆓 **In your browser, with no key** — Chrome and Edge translate the whole page, or the whole site, on the device itself. Nothing to sign up for, nothing to pay, nothing sent anywhere. Available both on the live page and on the bulk screen.
* 🖱️ **On the live page** — switch on the visual editor and click any text to translate it in a small, draggable popup, with Previous/Next, a progress bar and a "skip already-translated" mode.
* 🆓 **Free with Google Translate (no key)** — every field has a link that opens Google Translate prefilled, so you translate for free on Google's own site and paste the result back.
* ✍️ **By hand**, in a clear per-page editor (also inside the post/page screen) with red highlighting for what's still missing — plus a copy & paste box for bulk Google translation.
* 💬 **With ChatGPT or Gemini, no key** — one button copies the whole page with a translation prompt written for your site; paste the answer back and every line goes to its place.
* 🆓 **With free AI, on your own free account** — Cloudflare Workers AI (about 1,900 sentences a day with Llama 3.3 70B, or 11,000 with a plain translation model), Groq (gpt-oss-120b) or OpenRouter's free models. A two-minute guide gets you the key, no card needed; a counter shows today's free share, and when it runs out the next provider in your chain carries on.
* 🤖 **With AI** — bring your own key for DeepL, OpenAI, Google Gemini, Anthropic (Claude) or Google Cloud Translate. Text is sent only to the provider you choose, only when you ask.

= Highlights =

* 🔑 **No API key required — and not just "by hand".** Chrome and Edge translate the whole site automatically, on your own computer, for free. Or do it by hand in the visual editor, or free with Google Translate, and edit any translation later. There is no signup, no account and no key to create — AI providers are optional and only used if you add one yourself.
* 🌍 **Unlimited languages** (36 built in), 100% free. Donations welcome, never required.
* ⚡ **Built for huge sites**: past ~20,000 strings the engine switches by itself to indexed per-page lookups — flat memory and the same render time at 500 or 500,000 strings (load-tested), where map-loading translation plugins hit the PHP memory limit.
* 🖱️ **On-page visual editor**: click text to translate it in a draggable popup — no admin round-trips.
* 🧩 **Interface too**: translates theme & plugin interface strings, not just page content.
* 🔗 **SEO-ready URLs**: translated slugs (e.g. `/it/chi-siamo/`) and `hreflang` tags.
* 🔍 **One SEO panel with everything in it**: open a page in the visual editor and the SEO panel lists, in order, the translated address, the page title, the meta description, the social preview (Open Graph and X), the keywords, then every image on the page and every translatable attribute — titles, placeholders, ARIA labels. Each line takes you straight to that element on the page and opens the right editor, so nothing search engines read is left in the source language by accident.
* 🚦 **Per-page visibility**: if a page has no equivalent in a language, redirect it, show a custom message, or 404 — per page, per language.
* 🎌 **Flexible language switcher** as a shortcode `[translaterocket_switcher]`, a Gutenberg block, or a widget — inline, dropdown, or a **scrollable bar with ‹ › arrows** for many languages — with original SVG flags that render everywhere (including Windows). Show a switcher on desktop only, on mobile only, or both, to style a different menu for each.
* 📥 **One-click import from thirteen other plugins** — TranslatePress, Polylang, WPML, Weglot, qTranslate (X and XT), WPGlobus, WP Multilang, Bogo, Multilanguage by BestWebSoft, Falang, Sublanguage, Loco Translate and Autoglot — plus a **universal importer** for any other plugin: it reads your pages as visitors see them, in every language, pairs each sentence with its translation only where the pages match, and lets you preview every page side by side before you switch the old plugin off — plus CSV and TMX for everything else. Read straight from the tables, the posts or the `.po` files, so it works even when the other plugin is gone. Pages built with Elementor or Bricks come across too, widget by widget.
* 🖼️ **A different image for each language** — swap any picture for a translated one: switch on the visual editor, click the button on the image and pick another from your media library. For text baked into a picture, a screenshot of your own interface, or a photo that only makes sense in one country.
* 🎯 **Different content and menus per language** — show a paragraph, a block or a menu item only in some languages: with a shortcode, a class on any block, or a checkbox in Appearance → Menus.
* 🛒 **WooCommerce aware**: product and category slugs per language, the AJAX mini-cart and JS strings translated too, and customer emails rendered in the language the order was placed in.
* 👀 **Preview mode**: review new translations or a fresh import on the real pages while visitors still see the default language — publish with one click when ready.
* 🚦 **Add a language now, publish it when it is ready**: each language has a switch — green and your visitors see it, grey and only you do. While it is offline it stays out of the switcher, out of your sitemap and out of the `hreflang` tags, and its URLs send visitors to your default language, while you keep working on the real pages. Nothing half-translated ever reaches your visitors or Google.
* 📂 **Built for scale**: searchable, sortable page list for sites with hundreds of pages.
* ✅ **You stay in charge** — every machine translation can be reviewed and edited by hand. Translations live in your own database.

= Languages =

Translate your site into the languages your visitors actually speak: Spanish, French, German, Italian, Portuguese and Brazilian Portuguese, Dutch, Polish, Russian, Ukrainian, Turkish, Romanian, Greek, Arabic, Hindi, Indonesian, Vietnamese, Thai, Chinese, Japanese, Korean — 36 languages ship built in, and you can add unlimited custom ones (any language code works). The plugin's own admin interface is translated into nine languages besides English: Japanese, Spanish, German, French, Brazilian Portuguese, Italian, Dutch, Russian and Polish.

= Works with =

TranslateRocket translates the page your site actually sends to the browser, so it is not tied to a theme or a page builder. Every release is tested on a real site with the plugins below (tested means installed, configured and checked from the visitor's side, not only read about):

* **Page builders:** Elementor and Elementor Pro (theme builder headers and footers included), the block editor. While a builder is editing a page - Elementor, Beaver Builder, Divi, Bricks, Oxygen, Breakdance, Brizy, WPBakery, Thrive Architect, the Customizer - TranslateRocket stays out of its way.
* **Shops:** WooCommerce - product pages, cart, checkout and the order e-mails, in the customer's language.
* **Forms:** Forminator, Contact Form 7, WPForms, Gravity Forms, SureForms, Formidable, Ninja Forms.
* **Cookie and consent banners:** Complianz, CookieYes, GDPR Cookie Compliance, Cookie Notice - the banner text is collected, can be clicked in the visual editor and reaches visitors translated.
* **SEO:** Yoast SEO, Rank Math - translated titles, descriptions and social tags.
* **Caching and optimisation:** Cache Enabler, Autoptimize, WP Fastest Cache.
* **Side by side with, and importing from:** WPML, Polylang, TranslatePress, Weglot, Bogo and others.

The full list, with what was checked and the date of the last test: https://translaterocket.com/compatibility/

Something missing, or not behaving? Tell me from https://translaterocket.com/support/ and I will test it.

= Need a hand with your site? =

**I can do it for you.** I am the developer of TranslateRocket, and I also build and translate WordPress sites for a living: a new multilingual site from scratch, an existing site taken from one language to ten, a migration away from a paid plugin, or a stubborn theme that will not translate. You get the person who wrote the engine, not a support queue.

The plugin stays free either way — nothing here is locked behind that. Write to me from the "Help & Feedback" screen inside the plugin, or from https://translaterocket.com/support/ .

== External services ==

This plugin can connect to third-party services, but only with your involvement:

* **AI translation providers (optional).** If you add an API key and ask to translate, the relevant text is sent to the provider you selected so it can return a translation. No text is sent until you configure a provider and trigger a translation. Providers and policies:
  * DeepL — service: https://www.deepl.com/en/pro , privacy: https://www.deepl.com/en/privacy
  * OpenAI — service: https://platform.openai.com , privacy: https://openai.com/policies/privacy-policy/
  * Google Cloud Translation / Gemini — service: https://cloud.google.com/translate , privacy: https://policies.google.com/privacy
  * Anthropic (Claude) — service: https://www.anthropic.com , privacy: https://www.anthropic.com/legal/privacy
  * Cloudflare Workers AI — service: https://developers.cloudflare.com/workers-ai/ , privacy: https://www.cloudflare.com/privacypolicy/
  * Groq — service: https://console.groq.com , privacy: https://groq.com/privacy-policy/
  * OpenRouter (and the provider of the model you pick there) — service: https://openrouter.ai , privacy: https://openrouter.ai/privacy
* **Google Translate (manual, always available).** Every editor offers a per-phrase link and a copy-paste box that open Google Translate (translate.google.com) prefilled, so you translate on Google's own site and paste the result back. This is just a convenience link — your browser, not the plugin, contacts Google. Service: https://translate.google.com , terms: https://policies.google.com/terms , privacy: https://policies.google.com/privacy
* **Optional one-click Google auto-fill (off by default).** A site owner can opt in (the `TRROCKET_AUTO_GOOGLE` constant / the `trrocket_auto_google` filter) to let the server fetch a translation from Google's free endpoint with one click. This uses an undocumented endpoint and is best-effort, so it is disabled out of the box and the plugin never calls it on its own. For dependable automatic translation use the official AI providers below.
* **translaterocket.com support ticket (optional, on request).** The Diagnostics screen has a form that opens a support ticket for you. It sends nothing until you fill it in and press Send, and what it sends is exactly what the page shows you: your name, your email address (so the answer can reach you), your subject and message, your site address and the plugin version, plus the technical report if you leave that box ticked. The report never contains API keys. To prove the request really comes from your site and not from a spam robot, the support site then makes one request back to your site's home URL with a one-time token. Nothing else is transmitted, nothing is stored on your site, and if you would rather not use it the same page still offers the copy-and-paste route. Service: https://translaterocket.com , privacy: https://translaterocket.com/privacy-policy/
* **translaterocket.com deactivation feedback (optional, only if you press Send).** When you deactivate the plugin, a box asks why. The answer is saved on your own site. Only if you press «Send and deactivate» is it sent to https://translaterocket.com/wp-json/translaterocket/v1/farewell : the reason you picked, what you typed, and the numbers shown in the box (plugin, WordPress and PHP versions, number of languages and of detected sentences, the translation provider in use, whether the setup wizard was completed, days since install, your admin language). No site address, no name, no e-mail, no page or translation content; the IP address is not stored. «Skip and deactivate» sends nothing, and the `trrocket_farewell_send` filter turns sending off entirely. Service: https://translaterocket.com , privacy: https://translaterocket.com/privacy-policy/
* **translaterocket.com (optional).** The "Help & Feedback", "Request customization", "Send feedback" and "Get tips & updates" links open translaterocket.com in your browser with your site's domain in the URL. Nothing is sent automatically — these are links you choose to click. Service: https://translaterocket.com , privacy: https://translaterocket.com/privacy-policy/

== Installation ==

1. Upload the `translate-rocket` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **TranslateRocket → Settings** and pick your source language and the languages to translate into.
4. Browse your site to detect content, then translate from **TranslateRocket → Translations** (by hand, copy & paste, or AI).
5. Add the language switcher anywhere with the `[translaterocket_switcher]` shortcode, the block, or the widget.

First steps, filmed from installation to the first translated page:

https://www.youtube.com/watch?v=FgrO7Fqun4U

== Frequently Asked Questions ==

= Is it really free? =

Yes. All translation features and unlimited languages are free, with no paid tier inside the plugin. You only ever pay your own AI provider, directly, if you choose to use AI.

= How do you keep it free with no income from it? =

I build websites for a living, and I use TranslateRocket on every multilingual site I make. Every fix reaches those sites the day I release it, so the plugin gets maintained because I need it maintained — publishing it for everyone costs me nothing on top of that. What comes back is worth more to me than a licence fee would be: bug reports from setups I could never reproduce on my own, which end up improving my own clients' sites too. Donations are welcome and change nothing about what the plugin does.

**And if you want that work done on your own site, you can hire me.** Building and fixing WordPress sites is the day job: a site built from scratch, a design put right, a multilingual setup done properly, custom development, a migration off a plugin that charges you every month. That is where the money comes from — not from crippling this plugin. Ask me from the "Help & Feedback" screen inside the plugin, or at https://translaterocket.com/support/ .

= Do I need an AI key? =

No — and without one you are not limited to typing by hand. **Chrome and Edge on a computer carry a translator that runs on the device itself, and one button puts it to work on your whole site.** It costs nothing, needs no account and no signup, and the text never leaves your machine. Quality sits below DeepL and the AI models, so it is the quickest way to a translated site rather than the finest one — but everything it translates is kept, so adding a provider later never repeats the work.

It needs Chrome, or Edge 148 or later, on a computer that can run the built-in model: Windows 10 or 11, macOS 13 or later, Linux or ChromeOS (not Windows Server), 16 GB of RAM or a graphics card with more than 4 GB, and 22 GB of free disk space, with the admin on https. Anywhere else, the copy-and-paste route works in any browser and still needs no key.

Beyond that you can click any text on the live page and type its translation, edit it again later, and publish it. Every field also offers a free Google Translate link you can paste back from. AI keys are optional and only used at the moment you ask for one.

Two minutes, filmed on a real site: translating a page with no key at all.

https://www.youtube.com/watch?v=J0TxlZBKaWc

= Do my translations stay on my site? =

Yes. Translations are stored in your own WordPress database. Text is only sent to the AI provider you configure, at the moment you ask for a translation.

= Does it work with caching plugins? =

Yes. Each language lives under its own URL (e.g. `/it/`), so page caches store each language separately and automatically.

= Does it work with WooCommerce? =

Yes. Product pages, categories and attributes are translated like any other content, and slugs are translated per language. It also covers the parts WooCommerce renders outside the page HTML, which a page-only engine misses: the AJAX mini-cart fragments, strings passed to JavaScript (such as the "View cart" link), and transactional emails — the order's language is recorded at checkout (classic and block/Store API) and the customer email is rendered in that language. Wording that only ever appears inside an email (WooCommerce's own "Thank you for your order", "Quantity", "Price") is picked up automatically the first time such an email is sent and filed under "WooCommerce emails" in the translation screens, so you can translate it like anything else. Multi-currency is out of scope: TranslateRocket translates, it does not convert prices.

The whole shop translated, from the product page to the order e-mail:

https://www.youtube.com/watch?v=kU0TConiT20

= Does it translate forms (Forminator, Contact Form 7, WPForms…)? =

Yes. Labels, placeholders, options and buttons are translated with the rest of the page, whether the form is added with a shortcode, a block, a widget or a page builder such as Elementor. The messages the form plugin adds with JavaScript — validation errors, "message sent" — are translated too, on Forminator, Contact Form 7, WPForms, Gravity Forms, SureForms, Formidable and Ninja Forms. With Forminator, the thank-you message, forms loaded with "Load form using AJAX" and the e-mail notifications follow the language of the page the form was sent from; the wording of the form settings and e-mails appears under "Forms: messages and e-mails" in the translation screens once an administrator has viewed the form or a notification has been sent. To keep a notification (for example the copy for the site owner) in the source language, return false from the `trrocket_forminator_translate_notification` filter.

= Is there a PHP function that returns the current language? =

Yes, for theme and plugin code that needs it (the equivalent of Polylang's `pll_current_language()`): `trrocket_current_language()` returns the code of the language of the current request, such as `en`, `fr` or `pt-br` — also in AJAX requests sent from a translated page — and `trrocket_default_language()` returns the site's source language. Wrap calls in `function_exists()` so your code keeps working if the plugin is deactivated.

= Which plugins can I import my translations from? =

Yes — use **TranslateRocket → Import** to bring over your existing translations. The WPML import covers both its string translations and its post-based translations (titles, slugs and matching body text), and reads directly from WPML's tables, so it works even when WPML is already deactivated. Coming from Weglot (a hosted service)? Export your translations from the Weglot dashboard as a CSV and upload the file on the same Import screen — the columns are detected automatically and mapped to your target languages.

To review the result safely, turn on **Preview mode** ("Who sees the translations" in Settings): you browse the fully translated site while visitors keep seeing the default language, and one click publishes it when you are happy.

Safer still: run TranslateRocket next to your current plugin, import, and switch only when you are happy. Two minutes:

https://www.youtube.com/watch?v=K5qmxn3_4po

= What if a page shouldn't exist in another language? =

Open the page and use the "Language visibility" box to redirect it, show a custom message, or return a 404 — per language.

= Can I show something in one language only? =

Yes. Wrap it in `[translaterocket_language lang="it"]…[/translaterocket_language]` — several languages work too, as `lang="it,de"` — or use `not="it"` to show it everywhere except Italian. In the block editor you do not need the shortcode: add the class `trrocket-only-it` or `trrocket-hide-it` in "Advanced → Additional CSS class(es)" of any block, navigation links included. Content limited with `lang=` or `trrocket-only-` is taken to be written in that language, and is shown exactly as you wrote it.

= Can a menu item appear only in some languages? =

Yes. In Appearance → Menus every item has a "Show in" row with one checkbox per language; nothing ticked means every language. Hiding an item hides its sub-items too. Block themes build menus with the Navigation block instead: there, give a link the class `trrocket-only-it` or `trrocket-hide-it`.

= Can I translate with ChatGPT or Gemini without an API key? =

Yes. Open a page in the visual editor, click "Translate page" and use "Copy with a translation prompt" — or the ChatGPT and Gemini links, which copy the same thing. The prompt carries your languages, the words you never translate, your glossary and your house style, and asks for the reply in a code block with the line numbers intact. Paste the reply in box 2 and apply it.

And when a machine translation gets a word wrong, fixing it takes ten seconds:

https://www.youtube.com/watch?v=Bc83GXh1NvI

= My browser isn't Chrome or Edge, or I work from a phone. Can I still translate without a key? =

The translator built into the browser exists only in Chrome and Edge on a computer. TranslateRocket Labs, a separate free add-on you can ask for at https://translaterocket.com/labs/, adds free translation engines that work from any browser, phones included — each one is switched on only after you read and accept its conditions. Labs is not needed: everything described on this page works without it.

Both, in one minute — a page translated in the browser with no key, then Labs translating a new page the moment it is published:

https://www.youtube.com/watch?v=Zsf1PXWFhVI

== Screenshots ==

1. Your whole site in every language: each language on its own address (/it/, /de/, /fr/), ready for Google, with a language switcher for your visitors.
2. Free AI translation with your own free account: a Groq key in two minutes, no card, in any browser — phones and Firefox too. Or bring your own OpenAI, Claude, Gemini or DeepL key.
3. Click any sentence on your page to fix it: type it yourself, or ask the browser translator, Google Translate or your AI.
4. Coming from another plugin? WPML, Polylang, TranslatePress and ten more have their own importer; the universal importer reads your pages as visitors see them, with a preview before you switch.
5. Design the language switcher — dropdown or list, flags, colours — with a live preview.

== Changelog ==

= 1.7.3 =
* New: e-mails sent to the visitor by contact forms reach them in the language of the page they wrote from, with their name and answers in place; e-mails to you keep the site's language. Contact Form 7 (the «Mail (2)» automatic reply), WPForms, Fluent Forms, Ninja Forms, Formidable Forms, Everest Forms and SureForms. Their sentences appear in the translation screens under their own heading («Contact Form 7 e-mails», «WPForms e-mails»…); in an HTML message only the text is translated, and a translation that lost one of the [tags] or {tags} is not used for that line.
* New: GiveWP's e-mails to the donor (donation receipt, offline donation instructions) follow the language of the page the donation was made from. Sentences under «GiveWP e-mails».
* New: the message shown once a form is sent (WPForms, Fluent Forms, Ninja Forms, Everest Forms, SureForms, Contact Form 7, Otter Blocks) becomes translatable the first time a visitor sends the form, under «Forms: messages and e-mails», and is then shown in the page's language. Before, it never appeared on a page, so it was never collected. Only the form's own wording is read, never what the visitor typed.
* New: text a translated page loads from the REST API after it is shown — WP Go Maps marker titles and descriptions, and any other content fetched the same way — becomes translatable, filed under the page that asked for it (or under «Content loaded by the page»). Only public answers are read: never a cart, an order, an account, a signed-in customer's request or anything a visitor sent.
* Fix: sentences with a date inside — «Posted on <date>», used by most themes — stayed in the source language on every translated post: WordPress writes the date in the page's language, so the sentence never matched the one collected. A date now keeps its place and only the words around it are translated.
* Fix: GiveWP 3 donation forms (shown in a frame that GiveWP prints without <html>) stayed entirely in the source language on translated pages — title, amounts, labels, payment methods. The form and receipt views are now translated like any page; the `trrocket_translate_frame_view` filter covers similar frames.
* Fix: MetaSlider's arrow labels (and other slider options written as JavaScript rather than JSON: FlexSlider's prevText/nextText, Swiper's accessibility messages) stayed in the source language.
* Fix: sentences WordPress builds around a title — the «Continue reading …» label of the more link, a composed feed title — are translated with the title in them; a post's category in structured data (articleSection) is translated.
* Fix: headings of texts that are not on a page («Forms: messages and e-mails», «WooCommerce emails», the form e-mails…) are shown in the administrator's language; collected while a visitor used a translated page, they could be stored in that page's language.
* Compatibility: Kadence Blocks' icon tooltips and Fluent Forms' payment item labels are translated. Checked with more popular plugins and themes: Chaty, Popup Builder, Otter Blocks, Strong Testimonials, Modula, WP Simple Booking Calendar, and the themes Twenty Twenty-Five, Neve, Sydney, Zakra, Botiga and Hestia.

= 1.7.2 =
* New: a «Plugin language» menu at the top of every TranslateRocket screen. Read the plugin in English, Italian, Spanish, French, German, Portuguese (Brazil), Dutch, Polish, Russian or Japanese whatever your WordPress profile says — each user chooses for themselves, and «Automatic» keeps following the profile. It changes only TranslateRocket's own texts; the tooltip says where to switch the whole dashboard.
* Compatibility: rotating «Fancy Text» phrases are translated phrase by phrase — Essential Addons keeps them in one attribute separated by bars, Premium Addons in the widget's JSON settings — and so are ElementsKit's countdown labels (Days, Hours…) and its Before/After image comparison labels. On/off switches that some blocks keep in attributes named like text (data-show-label="true") are never taken for words to translate. Checked with ten more popular plugins: Easy Table of Contents, Breadcrumb NavXT, AddToAny, Click to Chat (the pre-filled WhatsApp message too), Ally, CookieAdmin, FiboSearch, MC4WP, Essential Addons and ElementsKit.
* Compatibility: words inside the JSON settings that widgets and chat boxes keep in data-* attributes are translated, keys and options untouched — Social Chat's WhatsApp button, Premium Addons' typing text — and Variation Swatches' colour and size tooltips. Plural labels kept in attributes ending in «-plural» are translated too (Qi Addons' countdown shows «Days» from data-day-label-plural).
* Compatibility: MetForm forms (600,000 sites) are translated — labels, placeholders, the button and the error messages. MetForm prints each form as a JavaScript template that the page translation never read; now only its words are replaced and the template works as before.
* Fix: on phones, the language list under «Published, or still being translated?» no longer pushes the ONLINE label out of the table, and the AI provider cards keep their fields, guide and buttons inside the card.

= 1.7.1 =
* New: invoices from PDF Invoices & Packing Slips in the language of the order. An invoice, credit note, proforma or receipt is built in the language the customer ordered in — from the download link, attached to the e-mail or resent from the order screen — and your own footer is translatable under «WooCommerce PDF documents». The customer's details, the invoice number and product codes stay as they are; packing slips stay in the site's language for the warehouse.
* Privacy: the customer's own details — names, company, addresses, e-mail, phone, order note — are no longer collected from WooCommerce e-mails as sentences to translate, nor ever translated. Before, every order e-mail could add them to the list under «WooCommerce emails»: those already there are removed when you update — only details of real orders, seen nowhere but in e-mails, so your shop's own sentences and their translations stay.
* New: a check after every import. The imported translations are reread without AI: broken markup, scripts, a translation identical to the original, changed links or numbers, an odd length or the wrong language are listed on the Import page, to keep or remove one by one or all together.
* Compatibility: the «do not translate» marks of other translation tools are respected — Google's notranslate and skiptranslate classes (GTranslate and many themes), TranslatePress's data-no-translation, Weglot's data-wg-notranslate — so a site that moves here keeps what it had excluded on purpose. On the html or body tag they still only mean «no browser popup».
* Compatibility: text inside open shadow DOMs (cookie banners and widgets built as web components) is read and translated in the browser.
* Compatibility: the social image (og:image, twitter:image) can have a different file per language like any other image; Yoast's «Written by» / «Est. reading time» labels for X (Twitter) are translated; template placeholders such as {{{ data.name }}} or %s alone are never treated as sentences.
* Fix: in the visual editor, its own labels stay in the administrator's language on a translated page.
* For developers: actions trrocket_translation_changed and trrocket_translation_deleted, fired when a translation is saved or deliberately removed (TranslateRocket Labs uses them for its History).
* Tested with: GDPR Cookie Compliance, Say What?, Getwid, Stackable, Visual Portfolio, PDF Invoices & Packing Slips.

= 1.7.0 =
* New: free AI translation with your own free account. Cloudflare Workers AI (10,000 «neurons» a day: about 1,900 sentences with Llama 3.3 70B, or 11,000 with the plain translation model, with a Quality setting — Best, Volume or Automatic), Groq (about 1,000 requests a day with gpt-oss-120b) and OpenRouter's free models are providers like DeepL or OpenAI: in the fallback chain, in bulk translation, in the visual editor. Each has a two-minute guide to get the key, «Load available models» to check it, and today's counter; a little before the free share runs out, the next provider of your chain carries on. Official APIs, no card needed.
* New: the setup wizard offers free AI first. «Free AI, with your own free account»: a Groq key in 2 minutes with a Google account, a «Try the key» button, and the site translates from the first minute — in any browser, phones and Firefox included, where the browser translator does not exist.
* New: the universal importer, on Import. Coming from a translation plugin with no importer here, or one whose tables cannot be read? Keep it on: TranslateRocket reads each page as visitors see it, in every language, finds its translations from the hreflang links, and pairs each sentence with its translation only where the two pages have the same structure — leaving out what changes on every visit and copies, keeping links and bold words. A scan first (nothing saved), then the import, then a side-by-side preview of every page per language while the old plugin still serves your site. Tested on the bench with TranslatePress, Bogo, qTranslate-XT, WPGlobus, WP Multilang, Multilanguage and Polylang, and on pages of 20 real multilingual sites (8 systems): language switchers, cached copies with other content and sentences in the wrong language are left out. It waits while TranslateRocket itself serves the languages, so it never reads its own pages back. Translators that work only in the visitor's browser (GTranslate free, Weglot's JavaScript) cannot be read from the server: the scan says so.
* New: translation style from the kind of site, on AI Translation. A free AI reads your site's name, tagline and main pages and proposes the tone, formal or informal address for each of your languages, and the names never to translate; nothing changes until you press «Use this style». The house style now reaches every AI provider.
* New: a «Sponsor on GitHub» button next to the donation link.
* Improved: German admin screens address you consistently; the Labs page lists what Labs adds for teams.
* Fixed: translations whose source string no longer existed were counted but never shown or exported; they are removed on update.

= 1.5.9 =
* New: the words only a visitor who is not logged in can see — login and registration forms (Ultimate Member, bbPress, WooCommerce «My account»), «You must be logged in to reply», the e-mail field of the review form, guest-checkout notes — are collected too. Text was collected while an administrator looked at a page, and an administrator is logged in: those words were never collected, and every visitor saw them in the source language. Now, after an administrator has read a page, the site reads it once more by itself as a guest, in the background, once a day per page.
* New: content loaded after the page — «Load more» buttons, product filters, quick views, infinite scroll — comes back translated, with its links in the language. Themes and plugins fetch those pieces through admin-ajax; the page engine never saw them, so on /it/ everything loaded after the first screen was in the source language.
* New: the REST API answers a translated page in its language: WooCommerce's blocks (All Products, filters, the cart), client-side navigation and headless themes get product names, titles, excerpts and descriptions translated, permalinks in the language. Ids, slugs, prices and the editor's own requests are untouched.
* New: the sentences a theme or plugin hands to its scripts — «Product was successfully added to your cart», «View cart», «Loading…», Elementor's «Close», «Next», «Share on Facebook» — are translated and collected like any other text. A paid theme without language packs (WoodMart, Flatsome, Avada) showed them in English on every language. This reads a JSON handed over as a string too (WooCommerce's address labels «Town / City», «Postcode / ZIP», Kadence's countdown labels), a theme's own `const` or `window.name = {…}` block, and a block printed by hand in the page head.
* New: feeds of a translated language (/it/feed/, Atom) carry translated titles, excerpts and bodies, with links in the language. Feed readers, newsletter tools and Google Discover read that file.
* New: any `data-*` attribute whose name says it holds words (`data-product-title`, `data-none-results-text`, `data-toast-cta`, `data-tooltip-message`…) is translated, as are `data-alt` on sliders, Bootstrap's `data-bs-title` tooltips, bare names like `data-label` or `data-caption` and numbered series like `data-button-transition-text-1`; analytics labels are left alone.
* New: import from Autoglot. Its table of paid translations — page sentences and the owner's own replacements, in every language it served — is read straight from the database, with Autoglot switched off; translated addresses and sentences it never finished are left out.
* New: Ninja Forms fields — labels, placeholders, options, the submit button — are translated before the form is drawn. Fluent Forms is recognised (its messages too).
* New: a visitor who signs up on /it/ keeps that language: WordPress' password-reset and new-account e-mails, and WooCommerce's account e-mails, go out in it.
* Improved: SEO plugins. `og:locale` now names the page's language whatever the site locale is (Yoast, Rank Math, All in One SEO, SEOPress); no second `og:locale` with All in One SEO; our multilingual sitemap is listed in All in One SEO's and SEOPress's sitemap index too.
* Improved: a breadcrumb («Home / Rooms / Sea view»), a list of tags or a pagination is no longer taken for one sentence: each link is translated on its own, and the product's name there is the same one as on its page. An icon-only link inside a sentence no longer turns the sentence into a unit.
* Improved: the switcher's live preview shows exactly what visitors will see: a language still offline is left out of it and named underneath, instead of being drawn as if it were online.
* Improved: right-to-left languages keep their direction — and load the theme's and WooCommerce's `-rtl.css` — even when «Translate the interface» is off.
* Improved: imports from Polylang and WPML bring the translations of categories, tags, product categories and attribute values, the purchase note, product attributes, variation descriptions and Advanced Custom Fields; the copy cleanup checks them before offering to trash a copy.
* Improved: imports from Polylang and WPML read pages built with SiteOrigin's Page Builder and Beaver Builder, whose text lives in a serialized field, not in the page content — as Elementor and Bricks already were.
* Fix: a TMX or CSV brought back into the site keeps the pairs that read the same in both languages («Agrigento» stays «Agrigento»): they are decisions, and the round trip export → import lost them. The «What to do next» guide no longer says «Everything is translated» while a new phrase is waiting, when a deleted string left a translation behind.
* Fix: a picture chosen per language is also swapped where ShortPixel, EWWW and a3 Lazy Load park the real address (`data-lazy-src`).
* Fix: WooCommerce e-mails sent from the dashboard («Completed», a note to the customer) are translated; variations («Linen shirt – Blue») and product names inside WooCommerce's sentences too; searching on /it/ finds products by their translated name.

= 1.5.8 =
* Fix: WooCommerce e-mails sent from the dashboard — «Completed», a note to the customer, a resent invoice — were never translated: the customer who ordered in Italian got them entirely in English. Each e-mail is now written in its reader's language: the customer's in the language of the order (subject included, from WooCommerce's own language pack), the shop's own notifications in the site language — before, the shop received them in the customer's language.
* Fix: variations and product names inside WooCommerce sentences stayed in the source language — «Linen shirt - Blue» in the cart, on the thank-you page and in the e-mails, «“Blue mug” has been added to your cart», «Be the first to review “Blue mug”», the breadcrumb's last step and the quantity label read by screen readers. Now they are translated like the product itself; this works for any theme or plugin that quotes a name inside its own wording.
* New: searching on a translated page finds what the visitor typed in their language — «tazza» on /it/ finds the product stored as «mug». It was the most reported problem in the forums of other translation plugins.
* New: WP Rocket integration. WP Rocket now knows your languages, as it does with WPML, Polylang and TranslatePress: a page excluded with «Never cache this URL» (or its box on the edit screen) is also excluded in every language — the /it/ copy of a members' page was cached before; the empty mini-cart it keeps is kept per language instead of one for everybody; cart, checkout and account are excluded in every language; «Clear cache» offers each language; «Remove Unused CSS» keeps the language switcher's styles.
* Fix: imports from Polylang and WPML now bring the translations of categories, tags, product categories and attribute values (Red → Rosso in the variation choices), the purchase note, the attributes typed in a product, variation descriptions and Advanced Custom Fields filled in the translated copy. The copy cleanup no longer offers to trash a copy whose fields were not imported yet.
* Fix: a big TMX or CSV import (2,000 translations) could stop halfway with a server error on hosts that limit a request to 40 seconds. It now runs in one database transaction: the same file takes a few seconds.
* Fix: WooCommerce checkout: the address labels (Town / City, Postcode…) no longer show in English for a moment when the page opens or the country changes.
* Fix: in the block cart, attribute labels («Colour:») are translated.
* Fix: structured data for Google: a title composed as «Product - Site name» is translated.
* Fix: with themes that restyle every button (Hello Elementor), the open language menu kept the current language white on white and narrower than the menu.

= Earlier releases =

Versions 0.1.0 to 1.5.7 are listed in changelog.txt, shipped with the
plugin and readable at
https://plugins.svn.wordpress.org/translate-rocket/trunk/changelog.txt

== Upgrade Notice ==

= 1.7.3 =
E-mails from seven contact form plugins and GiveWP in the visitor's language; form confirmation messages, map markers and GiveWP donation forms translated; dates inside sentences fixed on most themes. Recommended.

= 1.7.2 =
MetForm forms, rotating «Fancy Text», countdown labels and chat buttons of popular Elementor add-ons now translated; a «Plugin language» menu; better on phones. Recommended.

= 1.7.1 =
Invoices from PDF Invoices & Packing Slips in the customer's language; customers' names and addresses no longer collected from order e-mails; a check after every import; more plugins and markup understood. Recommended.

= 1.7.0 =
Free AI translation with your own free Cloudflare, Groq or OpenRouter account; a universal importer for plugins with no importer here, with side-by-side preview; a translation style proposed from your site. Recommended.

= 1.5.9 =
Content loaded after the page («Load more», filters), REST responses, feeds and script strings are now translated; Ninja/Fluent Forms; SEO og:locale and sitemaps; RTL; sign-up language. Recommended for every site.

= 1.5.8 =
Recommended for WooCommerce shops: e-mails sent from the dashboard are translated, variations and product names in the cart and e-mails too, search works in every language. New WP Rocket integration. Imports from Polylang and WPML bring categories, variations and ACF fields.

= 1.5.7 =
Wording of the Labs page only. Nothing else changes.

= 1.5.6 =
Imports keep your hand corrections and skip drafts and trash; pages with translate="no" on <html> are translated; better caching (Google Shopping links, NitroPack, Cloudflare). New Labs page; optional anonymous reason on deactivation.

= 1.5.5 =
Recommended if you translated your site with an older version: sentences with links or bold words show up translated in the visual editor again. Without an API key, a browser that cannot translate now explains why and offers free alternatives. The switcher preview is now the real switcher, and admin screens no longer jump.

= 1.5.4 =
TranslateRocket now works on a phone: every admin screen fits, and the translation panel under the editor is readable. The language switcher no longer covers cookie banner buttons. New: choose what the AI reuses from memory, and improve browser translations with AI.

= 1.5.3 =
Import from Falang and Sublanguage; pages no longer end up on the home page after a translation plugin is switched off; OpenAI reasoning models work; composed page titles are translated.

= 1.5.2 =
Submit buttons, pictures inside <picture>, video posters and retina images are translated; an edited translation shows immediately; visitors arriving with tracking parameters read from the cache.

= 1.5.1 =
Switching from qTranslate, WPGlobus or WP Multilang no longer leaves every language on every page, and imported sentences with links are used everywhere. Recommended if you are moving from another translation plugin.

= 1.5.0 =
Sentences broken by a link or a bold word are now translated whole, so the words can move where your language needs them. Your existing translations stay, but the count of phrases to translate will go up: the whole sentences are new. A new panel tells you where your site is and what is left to do.

= 1.4.6 =
Brand and product names in "Words & phrases" now stay as they are inside sentences too, with DeepL, Google Cloud and the AI models.

= 1.4.5 =
A page edited in the block editor, a product renamed through the store API or a footer edited in the Site Editor now reaches visitors at once, instead of up to six hours later.

= 1.4.4 =
The two links at the bottom of a Complianz banner can be translated at last, plus two things the screens never explained: what to do with the header and footer Polylang or WPML duplicated per language, and where the text of pop-ups comes from.

= 1.4.3 =
Cookie and consent banners (Complianz, CookieYes and others) are now collected and can be clicked in the visual editor; page builders are left alone while you edit; "Translate again" for a single page, with undo; pages left behind by Polylang, WPML or Bogo are labelled.

= 1.4.2 =
Side by side, the preview is now one click away: a Preview panel on the Languages page, a Preview menu in the admin bar and a Preview link in Pages and Posts.

= 1.4.1 =
Forms in the visitor’s language wherever they are placed, Forminator e-mails in the language of the page, and side-by-side fixes: the mode no longer switches itself on over a site TranslateRocket is already serving.

= 1.4.0 =
Side-by-side mode: activate TranslateRocket next to WPML, Polylang, TranslatePress or another translation plugin without changing your public site, preview the translations as an administrator and go online when you are ready.

= 1.2.0 =
Content and menu items for some languages only, and whole-page translation in ChatGPT or Gemini with a prompt made for your site. Existing pages and menus are unchanged until you use the new options.
