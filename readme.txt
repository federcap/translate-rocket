=== TranslateRocket – Free Multilingual Translation, Unlimited Languages ===
Contributors: federicodev
Donate link: https://translaterocket.com/donate/
Tags: translate, translation, multilingual, language, woocommerce
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.12
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

= 1.7.12 =
* Fixed: a redirect set with Redirection (or by any plugin that uses WordPress' redirect) sent a visitor on /it/old-page/ to the new page in the source language. The language of the address the visitor started from is kept.
* Fixed: in lists on a translated page, the titles of password-protected and private posts («Protected: …», «Private: …») stayed in the source language: WordPress localizes that prefix on every page. The prefix is now set aside and the title translated.
* Tested with 25 more plugins (Essential Addons, Jetpack, Dokan, Ajax Load More, WPCode, Advanced Ads, SEOPress, The SEO Framework, CoBlocks, Essential Blocks, Genesis Blocks, Shortcodes Ultimate, Strong Testimonials, Easy Table of Contents, AddToAny, Everest Forms, Asgaros Forum, Restrict Content, WP Recipe Maker, Booking Calendar, Woo Variation Swatches, WP Show Posts, WP-PostRatings, Simple Lightbox, Instagram Feed…), with the language switcher in the menu of Astra, OceanWP and GeneratePress, with a right-to-left language, with a site whose source language is Spanish, and with WordPress' own Query Loop pagination, comments, password-protected posts and search on translated pages.

= 1.7.10 =
* Fixed: the excerpts of the «Latest posts» block (and any sentence ending with a «Read more» link WordPress writes itself) stayed untranslated: WordPress localizes that link on every page, so on /it/ the sentence never read like the one collected. The link now keeps its place and its words, like a date, and the sentence around it is translated.
* Improved: the «your site, live» card of the switcher customizer shows a loading indicator instead of a white box while the page is drawn.
* Tested: a site whose source language is not English (Spanish at the root with /it/ and /en/), the language switcher in every placement × layout on a block theme and on a classic theme, and every button of the plugin's admin pages clicked in a real browser.

= 1.7.9 =
* Security: a translation can never carry markup its original did not have — whatever brought it in (an AI answer, an import, a paste, another plugin). Scripts, event handlers and foreign tags are removed when the translation is saved, in one place for every channel; texts translated through gettext are escaped when their source had no markup; a translation placed inside a script is encoded so «</script>» cannot end it.
* Security: a visitor can no longer make the site collect texts from invented «AJAX» addresses, read the translations of a language that is offline, or fill the page cache with spellings of the same address; the editor side panel only saves the strings of its own page; the universal importer reads this site only.
* Fixed: the «Import pasted translations» of the editor side panel now pairs lines like the strings page (a line without a number continues the one before it).
* Improved: the switcher customizer tells you when the header menu shows another profile, with a one-click «Use this profile in the header menu».
* Fixed: a sentence with a bold or italic part that has no letters («Price <strong>25%</strong> off tonight», «<em>€</em> 1.234,56») is now collected whole; it used to be cut in two and the second half stayed untranslated.

= 1.7.8 =
* Fixed: importing translations by copy and paste (Google Translate, DeepL…) matched a line without a number to the sentence in the same position, so a long text that the translator split over two lines pushed its second half onto another sentence — a cookie banner could end up showing a blog post. A line without a number now continues the line before it, and a translation that cannot belong to its original is skipped.
* Fixed: clicking a colour preset in the switcher customizer now redraws the «your site, live» preview at once, and the preset's colours are applied to the language item in your header menu too (not only to the dropdown under it).
* Improved: the switcher customizer tells you when the header menu is showing another profile than the one you are editing.
* Fixed: windows and pop-ups that a plugin loads after a click and then shows (LatePoint's booking window, product comparison tables, quick views…) are now collected while you browse your site, so they can be translated. Their text used to be skipped as «hidden» and never looked at again.
* Tested with 39 more plugins, among them Relevanssi, Ivory Search, SearchWP Live Ajax Search, Advanced Woo Search, Kadence Blocks, Happy Addons, Modern Events Calendar, WP Event Manager, LatePoint, Amelia, Bookly, YITH and HUSKY product filters, WPC Smart Compare, TablePress, wpDataTables, Popup Maker, GiveWP, Charitable, Fluent Forms, WPForms, Mailchimp for WP, Easy Digital Downloads, Ultimate Member, Paid Memberships Pro, Tutor LMS, wpDiscuz and wpForo.

= 1.7.7 =
* Fixed: content loaded after the page (AJAX) on a translated page kept its scripts and styles whole. A script holding HTML in a string — like SupportCandy's ticket form — came back cut in half, and its «Submit» button did nothing.
* Fixed: links in content loaded by AJAX (a portfolio's next page, «load more») now stay in the visitor's language even when none of its texts is translated yet — «Read more» used to lead back to the default language.

= 1.7.6 =
* New: Switcher → «Your site, live» — with the languages in your header menu (or in a spot you chose) the Switcher screen shows your real header, drawn with the settings on the page before you save, on desktop and phone.
* New: put the languages right after any item of your header menu (Switcher → Where in the menu → «Right after:»), not only at its start or end. Classic menus and the Navigation block of block themes.
* New: a profile such as «header» can be the one in the header menu: choose «In my header menu» in that profile and the menu shows its style. One profile at a time.
* Fixed: the colours of the switcher (text, hover, dropdown background, border, corners) now apply to the languages in your header menu too — they used to be ignored there.
* New: the row of languages at the bottom of the page can be aligned left, centre or right, and shown as a table of 2, 3 or 4 columns (2 on phones); it takes the switcher's text colours. «Your site, live» shows the bottom of the page too.
* Fixed: «Your site, live» shows only the languages your visitors see (not the ones still offline).

= 1.7.5 =
* Fixed: «Translate in this browser» (no API key) now saves sentences that have a link or bold words inside. They used to come back without their link marks and stayed untranslated; now the marks are kept, and where the browser's translator drops them the sentence is translated piece by piece around its links.
* Fixed: a phrase the browser's translator refuses is tried once, not again and again — the run could go round without end and the counter passed the total («Translating 162 of 154»).
* New: «Translate in this browser» also on the screen of a single page (Translations → a page), for that page's strings only.
* Fixed: the same link-safe translation in the visual editor's «Translate in this browser».
* Fixed: in block themes (Navigation block) the languages in your header menu now show their flags, and open on click when the switcher is set to open on click — the rest of your menu keeps opening as before.
* Clearer: a switcher profile other than Default says that the header menu, the spot on your page, the floating switcher and the bottom row all use the Default profile.

= 1.7.4 =
* New: the language switcher in your theme's header menu — Switcher → «In my header menu». The languages become a real item of the menu, drawn by your theme like its other items, with its submenu and its phone menu: classic themes (Astra, GeneratePress, Kadence, OceanWP…), Elementor's Nav Menu, and block themes through the Navigation block. At the start or at the end of the menu. Where a page has no such menu, the switcher floats in the corner, so it is never missing. The setup wizard offers it when the theme has a menu.
* New: if your switcher floats in a corner, TranslateRocket offers once to move it into your header menu — one click, and you see it on your site.
* New: put the switcher exactly where you want — Switcher → «In a spot I choose on my page» opens your home page with the header, menu, footer and sidebar outlined; click a spot, choose its start or its end, and the real switcher shows there at once. Where the spot is missing or hidden (another page, the desktop menu on a phone) the switcher floats instead.
* New: a row of languages at the bottom of every page, in a line or as a table, and a «Grid» layout for the switcher block and shortcode: flags and names in as many columns as fit.
* New: 40 more languages, 77 in all — among them Catalan, Serbian, Basque, Galician, Lithuanian, Latvian, Estonian, Albanian, Macedonian, Bosnian, Georgian, Armenian, Azerbaijani, Kazakh, Urdu, Tamil, Telugu, Marathi, Gujarati, Punjabi, Nepali, Sinhala, Swahili, Afrikaans, Filipino, Icelandic, Irish, Welsh, Maltese, Belarusian, Mongolian, Khmer, Burmese, Amharic, Uzbek, Luxembourgish, Esperanto, Mexican Spanish, Canadian French and Swiss German — each with its flag, its own address and the WordPress language pack for the interface. A site written in one of them can now pick it as its own language.
* Fix: DeepL received Traditional Chinese as Simplified Chinese, and Norwegian with a code it does not know.
* Fix: on some sites the translated pages lost the theme's design — fonts, colours, the fixed header, footer menus — because a plugin printed an element in the page head (reported with The7, Elementor and the Angie assistant). The attributes of the page body are kept now, whatever another plugin prints.
* Fix: Diagnostics had «Test connection» only for DeepL, OpenAI, Claude, Gemini and Google Translate: Groq, Cloudflare Workers AI and OpenRouter — the free providers the setup wizard recommends — could not be tested there. Every provider with a key is listed now.
* Improved: when a provider refuses a key or answers with an error, «Test connection» says so in a plain sentence in your language («The provider refused this API key…») instead of the provider's technical answer, which stays in the Diagnostics log.
* Fix: saving the settings raised a PHP warning (a leftover from a limit removed long ago); on a site that shows PHP warnings it could break the page shown after saving.
* Fix: plugins that answer on WooCommerce's own AJAX address — FiboSearch's suggestions while a visitor types — answered in the source language on a translated page, and their links led back to the source language. They are translated now and the links keep the page's language (what the visitor typed is never collected). Links to pages of the site inside any AJAX answer keep the page's language too.
* Fix: sentences a plugin or theme writes by hand into the page for its scripts, in a block marked type="text/javascript" — Ultimate FAQ's list of questions suggested while a visitor types in the FAQ search, and many older themes' messages — were skipped and stayed in the source language. Script templates (text/template) are still left alone.
* Fix: on large sites (over 20,000 sentences) the block cart and checkout showed product attributes in the source language («Colour» on an Italian page), and form messages could stay untranslated: both read a group of interface sentences no longer filled since June. They now use the translations of what the cart holds and of the page the form is on.
* Fix: sentences a plugin hands to its scripts with blanks the script fills in — Shortcodes Ultimate's lightbox counter «%curr% of %total%», «Showing %1$s of %2$s» — stayed in the source language where the plugin has no language pack. They are translated now, and a translation is used only if it keeps exactly the same blanks: a counter can never come out broken.
* Fix: Social Chat — the header and footer of the WhatsApp box stayed in the source language: they are a short piece of HTML inside the plugin's settings. Words between paragraphs and headings are now translated; the HTML stays as it is.
* New: text a translated page loads through admin-ajax after it is shown — Ninja Tables rows, many themes' «load more» — becomes translatable, filed under the page that asked for it. Before, it was translated only if the same sentence had been met elsewhere: a table's rows never were. Only public answers are read (never a cart, an account, an order, a signed-in customer's request or anything a visitor sent), and codes a script passes around are left out.
* New: WP Job Manager — the job list on a translated page arrives translated, with the plugin's words in the page's language. It is loaded after the page from an address of its own (/jm-ajax/), which used to answer in the source language whatever page asked. The same holds for LearnPress' and bbPress' own AJAX addresses.
* New: Events Manager — «next month» in the calendar of a translated page shows the events translated. The month is fetched from the page itself and came back with the month name translated and the events in the source language.
* New: structured data for Google — a job's title (JobPosting, read by Google for Jobs) and descriptions written in HTML are translated; the HTML stays as it is. A sentence that holds bold or a link is left whole, never cut into pieces.
* Fix: words a plugin keeps in an attribute whose name runs together — the message Ultimate Blocks' countdown shows when the offer ends, the «This field cannot be blank» and «invalid» errors of Formidable Forms fields — stayed in the source language.
* Fix: once everything was translated, the Translations page still read «14 strings still to translate — 89%» next to a button saying «Everything is translated»: the addresses of the page's images were counted as text to translate. They are not (an image can be swapped per language, if you want), and the count now agrees with the button.
* Improved: a simpler dashboard. The side menu keeps six entries (the other screens are one click away in the buttons at the top), notices from other plugins fold into one line on TranslateRocket's screens, and the Labs box is a single line.
* Improved: with 77 languages, a search box over the language lists (setup wizard and Languages): type a language's own name, its English name or its code. The English name now shows next to the native one — «Српски Serbian».
* Improved: Translations — one main button per page, «Translate»; «Start over» and «Hide» are in a «⋯» menu and still ask before doing anything. A new «Only pages still to translate» filter.
* Improved: AI Translation — the providers you do not use fold to one line; the active one and those in your fallback chain stay open, and choosing a provider opens its card.
* Improved: Switcher — where the switcher goes comes first, then its layout; colours, sizes, font and the phone options are under «More style options».
* Fix: on Windows, Chrome and Edge showed the dashboard's emoji flags as two letters («GB»): the plugin's own flags are used instead.
* Fix: on PHP 7, searching languages or pages by a name in a non-Latin script (Cyrillic, Japanese, Greek…) found nothing.
* Fix: in Dutch, Polish, Russian and Japanese three counters of the dashboard showed in English («3 pages updated.»): their plural forms are in place now.
* Improved: the Translations screen explains <1>…</1> in a visible line where they appear: they mark a link or formatted words, which stay as they are on the site (until now only a tooltip said so).
* Fix: on the Translations and Memory screens, the «Translate with your browser» panel appeared a moment after the page and pushed it down; it is drawn in place now.
* Fix: on the Plugins screen, after the short «why are you leaving?» question, the deactivation page could open twice (the answer and a 4-second safety net both ran it).

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

Versions 0.1.0 to 1.5.9 are listed in changelog.txt, shipped with the
plugin and readable at
https://plugins.svn.wordpress.org/translate-rocket/trunk/changelog.txt

== Upgrade Notice ==

= 1.7.12 =
Redirects keep the visitor's language (Redirection and others); titles of protected posts translated in lists; 25 more plugins and 3 classic themes tested. Recommended.

= 1.7.10 =
«Latest posts» excerpts and sentences ending with a «Read more» link are now translated on every site; loading indicator in the switcher's live preview. Recommended.

= 1.7.9 =
Security hardening (translations can never carry scripts or handlers, whatever their source), pasted translations in the editor panel, sentences with a bold/italic number kept whole, clearer switcher profiles. Recommended.

= 1.7.8 =
Fixes pasted translations landing on the wrong sentence (cookie banner showing a blog post), presets in the switcher customizer and the colours of the language item in your header menu. Recommended.

= 1.7.7 =
Fixes content loaded by AJAX on translated pages: scripts stay whole (forms like SupportCandy's send again) and its links keep the visitor's language. Recommended.

= 1.7.6 =
The languages in your header menu take the colours you choose, can sit right after any menu item, and the Switcher screen shows your real header live before you save. Recommended.

= 1.7.5 =
Fixes translating in your browser without an API key (sentences with links are saved, a run always ends), and flags plus open-on-click for the languages in block themes' header menu. Recommended.

= 1.7.4 =
The language switcher in your theme's header menu, or in a spot you click on your own page; a row of languages at the bottom of pages; 40 more languages (77 in all); many popular plugins translated (WP Job Manager, Events Manager, Ninja Tables, FiboSearch, Social Chat…); a simpler dashboard. Recommended.

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
