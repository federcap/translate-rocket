<div align="center">

# TranslateRocket 🚀

**Free, AI-powered translation for WordPress — make your whole site multilingual without a subscription.**

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)

[Website](https://translaterocket.com) · [Author](https://federicocaputo.dev)

</div>

---

TranslateRocket translates **everything visitors actually see** — page content, menus,
theme & plugin interface text, image `alt` tags, link titles, placeholders, ARIA
labels, page titles and SEO meta — and serves each language under its own clean URL
prefix (`/it/`, `/de/`, …) that plays nicely with SEO and page caches.

No string limits, no paid tier inside the plugin, no lock-in: every translation lives
in **your** database and can be edited by hand at any time.

## ✨ Why it's different

- **🖱️ On-page visual editor.** Flip a switch and your live site becomes editable —
  click any text and translate it in a small **draggable popup** with Previous/Next,
  a progress bar, "skip already-translated" mode, auto-save on navigation, and a
  close button — plus raw **HTML editing** of any string and a one-click **Translate
  page** button that auto-translates the whole page at once. It even translates image
  `alt`/`title` attributes.
- **⚡ Free with Google Translate (no API key).** Every field has a link that opens
  Google Translate prefilled — translate for free on Google's own site and paste it back.
- **🤖 Bring-your-own AI.** Optional batch auto-translation with **your own** key for
  DeepL, OpenAI, Google Gemini, Anthropic (Claude) or Google Cloud Translate. Text is
  sent only to the provider you pick, only when you ask, with a daily usage cap.
- **🔎 Detects strings automatically** as an admin browses — no manual string
  registration, works with page builders (Gutenberg, Spectra…).
- **🌍 36 built-in languages** including RTL (Arabic, Hebrew, Persian).

## 🛠️ Four ways to translate

| Method | Key needed? | Best for |
|--------|:-----------:|----------|
| **Visual editor** (click text on the page) | no | quick fixes, seeing context |
| **Google Translate link** (per phrase) | no | fast, free, decent quality |
| **By hand / copy & paste** | no | full control, bulk paste |
| **AI auto-translate** (batch) | yes (yours) | translating a whole site at once |

Every machine translation is just a starting point — review and edit anything in the
per-page editor, where missing strings are highlighted in red.

## 🔍 SEO & multilingual done right

- Clean per-language URLs (`/it/chi-siamo/`) with **translated slugs**.
- Automatic `hreflang` tags and a correct `<html lang>` per language.
- Translatable SEO **title** and **meta description**.
- **Language switcher** as a shortcode `[translaterocket_switcher]`, a Gutenberg
  block, or a widget — with original SVG flags that render everywhere (Windows
  included) — inline, dropdown, or a **scrollable bar with ‹ › arrows** for many
  languages — plus presets, a flag-dropdown, and full styling controls.
- **Per-page visibility:** if a page has no equivalent in a language, choose to
  redirect it, show a custom message, or return a 404 — per page, per language.

## 🔁 Migrating from another plugin?

Import your existing translations from **TranslatePress**, **Polylang** or **WPML**
in one screen (`TranslateRocket → Import`). The WPML import reads straight from
its tables — string translations *and* post-based translations — so it works even
after WPML has been deactivated.

## 🚀 Installation

1. Copy the `translate-rocket` folder to `/wp-content/plugins/` (or install the ZIP).
2. Activate it from **Plugins**.
3. In **TranslateRocket → Settings**, pick your source language and target languages.
4. Browse the site to detect content, then translate from **TranslateRocket →
   Translations** (visual editor, Google Translate links, by hand, or AI).
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
├── languages/                  # translation catalog (.pot/.po/.mo) — Italian included
├── assets/                     # admin + visual-editor CSS/JS, SVG flags
└── src/
    ├── Plugin.php              # orchestrator (singleton)
    ├── Languages.php           # 36-language catalogue
    ├── Settings.php            # options wrapper
    ├── Strings.php             # source-string repository (all SQL parameterized)
    ├── Router.php              # language routing (URL prefixes)
    ├── Translator.php          # AI batch auto-translate orchestrator
    ├── GoogleFree.php          # Google Translate links (+ opt-in auto-fill helper)
    ├── Frontend/Engine.php     # detect + replace strings on the front end
    ├── Frontend/VisualEditor.php # on-page click-to-edit popup
    ├── Frontend/Switcher.php   # [translaterocket_switcher] + block + widget
    ├── Providers/              # AI adapters: DeepL, OpenAI, Google, Gemini, Anthropic
    ├── Importers/              # TranslatePress + Polylang importers
    └── Admin/                  # settings, page editor, in-post metabox panel
```

## 🔐 Privacy & security

- Translations are stored in **your** WordPress database.
- Text leaves your site only when **you** trigger it: clicking the Google button
  (one phrase, to Google's free endpoint) or running an AI translation (to the
  provider whose key you added). Nothing is sent automatically or in bulk in the
  background.
- Every admin action is protected by WordPress nonces **and** capability checks; all
  database access is parameterized; all output is escaped. See the **External
  services** section of `readme.txt` for the full disclosure.

## 📦 License

Original code, written from scratch and licensed under the **GPLv2 or later**.

---

<div align="center">
Made with care by <a href="https://federicocaputo.dev">federico_dev</a> · <a href="https://translaterocket.com">translaterocket.com</a>
</div>
