<div align="center">

# TranslateRocket 🚀

**Free, AI-powered translation for WordPress — make your whole site multilingual without a subscription.**

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/translate-rocket.svg)](https://wordpress.org/plugins/translate-rocket/)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)

[Plugin on WordPress.org](https://wordpress.org/plugins/translate-rocket/) · [Website](https://translaterocket.com) · [Author](https://federicocaputo.dev)

</div>

---

TranslateRocket translates **everything visitors actually see** — page content, menus,
theme & plugin interface text, image `alt` tags, link titles, placeholders, ARIA
labels, page titles and SEO meta — and serves each language under its own clean URL
prefix (`/it/`, `/de/`, …) that plays nicely with SEO and page caches.

No string limits, no paid tier inside the plugin, no lock-in: every translation lives
in **your** database and can be edited by hand at any time.

## ✨ Why it's different

- **🔑 No API key required at all.** Chrome and Edge on a desktop ship a translator
  that runs **on the device itself**, and TranslateRocket drives it: pick your
  languages, press one button, and the site is translated. Nothing to sign up for,
  nothing to pay, and the text never leaves the machine. Quality sits below DeepL and
  the AI models, so it is the fastest route to a translated site rather than the
  finest one — and everything it produces is kept, so adding a provider later never
  repeats the work.
- **🖱️ On-page visual editor.** Flip a switch and your live site becomes editable —
  click any text and translate it in a small **draggable popup** with Previous/Next,
  a progress bar, "skip already-translated" mode, auto-save on navigation, raw
  **HTML editing**, and a one-click **Translate page** button.
- **⚡ Free with Google Translate.** Every field has a link that opens Google
  Translate prefilled — translate on Google's own site and paste it back.
- **🤖 Bring-your-own AI.** Optional batch auto-translation with **your own** key for
  DeepL, OpenAI, Google Gemini, Anthropic (Claude) or Google Cloud Translate. Text is
  sent only to the provider you pick, only when you ask, with a daily usage cap.
- **🔎 Detects strings automatically** as an admin browses — no manual string
  registration; works with page builders (Gutenberg, Elementor, Spectra…).
- **📈 Built for big sites.** Past ~20,000 strings the engine switches by itself to
  indexed per-page lookups: flat memory and the same render time at 500 or 500,000
  strings, where map-loading plugins hit the PHP memory limit.
- **🌍 36 built-in languages** including RTL (Arabic, Hebrew, Persian), plus unlimited
  custom language codes.

## 🛠️ Four ways to translate

| Method | Key needed? | Best for |
|--------|:-----------:|----------|
| **In the browser** (Chrome/Edge, on-device) | no | translating a whole site with nothing to set up |
| **Visual editor** (click text on the page) | no | quick fixes, seeing context |
| **Google Translate link** (per phrase) | no | one-off phrases, decent quality |
| **AI auto-translate** (batch) | yes (yours) | the best quality, a whole site at once |

Every machine translation is just a starting point — review and edit anything in the
per-page editor, where missing strings are highlighted in red.

## 🔍 SEO & multilingual done right

- Clean per-language URLs (`/it/chi-siamo/`) with **translated slugs**.
- Automatic `hreflang` tags and a correct `<html lang>` per language.
- Translatable SEO **title**, **meta description** and social preview tags
  (Open Graph, X) — including the ones a SEO plugin adds.
- **One SEO panel** listing, in order, everything a search engine reads on the page,
  each line linking straight to that element in the editor.
- **Language switcher** as a shortcode `[translaterocket_switcher]`, a Gutenberg
  block, or a widget — with original SVG flags that render everywhere (Windows
  included) — inline, dropdown, or a **scrollable bar with ‹ › arrows** for many
  languages.
- **Per-page visibility:** if a page has no equivalent in a language, choose to
  redirect it, show a custom message, or return a 404 — per page, per language.
- **Publish a language when it's ready:** each language has an online/offline switch.
  While it is offline it stays out of the switcher, the sitemap and the `hreflang`
  tags, and its URLs send visitors to the default language — so nothing
  half-translated ever reaches a visitor or Google.

## 🔁 Migrating from another plugin?

One screen (`TranslateRocket → Import`) brings across what you have already paid for
or typed by hand, from **TranslatePress, Polylang, WPML, Weglot, qTranslate (X and
XT), WPGlobus, WP Multilang, Bogo, Multilanguage by BestWebSoft and Loco Translate**,
plus CSV and TMX for anything else. Most are read straight from the tables, the posts
or the `.po` files — so the import still works after the other plugin has been
deactivated or deleted, which is the usual state of a site that is moving on.

## 🚀 Installation

1. Install from [WordPress.org](https://wordpress.org/plugins/translate-rocket/), or
   copy this repository into `/wp-content/plugins/translate-rocket/`.
2. Activate it from **Plugins**.
3. In **TranslateRocket → Settings**, pick your source language and target languages.
4. Browse the site to detect content, then translate from **TranslateRocket →
   Translations** (in the browser, the visual editor, Google Translate links, by hand,
   or AI).
5. Drop the switcher anywhere with `[translaterocket_switcher]`, the block, or the widget.

## 🏗️ Architecture

- **Namespace** `TranslateRocket\` with a PSR-4 autoloader (`/src`).
- **Storage:** two normalized tables — `trrocket_strings` (every unique source
  string) and `trrocket_translations` (one row per string per language, with a
  `status` flag so "what's missing" is a trivial query). Settings live in a single
  `trrocket_settings` option. Everything stays in your database.
- **Engine:** a single output-buffer pass per request parses the HTML with
  `DOMDocument`/`XPath`, replaces strings on secondary-language URLs, and (only while
  an admin browses) records newly-seen strings in one batch.

```
translate-rocket/
├── translate-rocket.php        # plugin header + bootstrap + autoloader
├── uninstall.php               # opt-in data removal
├── readme.txt                  # WordPress.org readme
├── languages/                  # translation catalog (.pot/.po/.mo) — 9 languages
├── assets/                     # admin + visual-editor CSS/JS, SVG flags
└── src/
    ├── Plugin.php              # orchestrator (singleton)
    ├── Languages.php           # 36-language catalogue
    ├── Settings.php            # options wrapper
    ├── Strings.php             # source-string repository (all SQL parameterized)
    ├── Router.php              # language routing (URL prefixes)
    ├── Translator.php          # AI batch auto-translate orchestrator
    ├── GoogleFree.php          # Google Translate links (+ opt-in auto-fill helper)
    ├── Copies.php              # per-language images and media swaps
    ├── Savings.php             # counts the API calls reuse avoided
    ├── Frontend/
    │   ├── Engine.php          # detect + replace strings on the front end
    │   ├── VisualEditor.php    # on-page click-to-edit popup + SEO panel
    │   ├── Switcher.php        # [translaterocket_switcher] + block + widget
    │   ├── DynamicContent.php  # JS-injected text (banners, popups, AJAX)
    │   ├── WooCommerce.php     # cart, checkout, order e-mails, product slugs
    │   ├── Sitemap.php         # per-language sitemaps and hreflang
    │   └── Visibility.php      # per-page, per-language redirect / message / 404
    ├── Providers/              # AI adapters: DeepL, OpenAI, Google, Gemini, Anthropic
    ├── Importers/              # ten plugin importers + CSV/TMX
    └── Admin/                  # settings, page editor, in-post metabox, browser engine
```

Compatibility with other plugins is tracked in [COMPATIBILITY.md](COMPATIBILITY.md).

## 🔐 Privacy & security

- Translations are stored in **your** WordPress database.
- Text leaves your site only when **you** trigger it: clicking the Google button
  (one phrase, to Google's free endpoint) or running an AI translation (to the
  provider whose key you added). The browser translator does not send anything
  anywhere — it runs on the device. Nothing is sent automatically or in bulk in the
  background.
- Every admin action is protected by WordPress nonces **and** capability checks; all
  database access is parameterized; all output is escaped. See the **External
  services** section of `readme.txt` for the full disclosure.

## 🤝 Contributing

Bug reports and pull requests are welcome. For support questions, the
[WordPress.org support forum](https://wordpress.org/support/plugin/translate-rocket/)
is the better place — answers there help the next person with the same problem.

## 📦 License

Original code, written from scratch and licensed under the **GPLv2 or later**.

---

<div align="center">
Made with care by <a href="https://federicocaputo.dev">federico_dev</a> · <a href="https://translaterocket.com">translaterocket.com</a>
</div>
