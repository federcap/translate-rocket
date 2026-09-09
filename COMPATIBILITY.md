# TranslateRocket — compatibility test log

Last full run: **2 July 2026**, plugin v0.5.1.

Test environments:

- **QA site** — a clean local WordPress 6.x install on PHP 8.2, used for isolated
  plugin-by-plugin testing.
- **Production clone** — a full copy of a real, content-heavy production site
  (Astra + Spectra, ~3.500 strings, several plugins already installed), used to
  check that TranslateRocket behaves in a site it did not grow up in.
- **Live** — translaterocket.com itself, in production.

Standard battery for every plugin: English home page renders · `/de/` is actually
translated (checked against a known German marker) · `html lang="de"` is correct ·
`/ar/` sets `dir="rtl"` · no PHP fatal · wp-login stays reachable.

## Tested — pass

| Plugin | Where | What was verified |
|---|---|---|
| **WooCommerce** | QA site | Cart and My Account in German (server-side), AJAX mini-cart fragments, block cart/checkout via JS, order e-mails in the order's language, cart/checkout excluded from our cache |
| **Elementor** | QA site | An Elementor-built page fully translated into German, markup intact |
| **Contact Form 7** | QA site | Form rendered on `/contact/` and `/de/contact/`, labels translated |
| **Yoast SEO** | QA site | No duplicate hreflang (7 tags = 6 languages + x-default, ours only), title/meta correct, our sitemap appears inside Yoast's index (`wpseo_sitemap_index`) |
| **Rank Math** | Production clone | Coexists on a real site running a custom schema mu-plugin; `og:locale` not duplicated (guarded by `seo_plugin_active`) |
| **Autoptimize** | QA site | With HTML+CSS+JS aggregation on: translation is applied to the already-optimised HTML (our buffer wraps theirs — correct LIFO order), AO assets present, no conflicts |
| **Cache Enabler** | QA site | Second hit on `/de/` served from cache is *always* German; `/` (English) never poisoned by the German cache — the URL prefix keeps cache keys separate |
| **Breeze** (Cloudways) | Live | In production on the plugin's own site: translated pages cached and served correctly |
| **Cookie Notice** | QA site | Banner injected via JavaScript translated on the fly by `dynamic.js` |
| **Complianz** | Production clone | Coexistence on a real site (banner detected, its JS text covered by `dynamic.js`) |
| **WPForms Lite** | QA site | Clean activation, EN/DE/AR pages fine, no fatal (embedded forms: manual test recommended) |
| **Astra + Spectra (UAG)** | Production clone | A complete real site: ~3.500 strings, bulk AI translation, `/it/` perfect; ~20 ms overhead |
| **Joinchat** | Production clone | The widget's gettext strings are translated |
| **TranslatePress / Polylang / WPML** | — | Anti-conflict guard (warns if they run at the same time) plus dedicated importers (TranslatePress: dictionary + gettext + slugs; real test: 376 strings detected) |

## Queued for testing

- **Jetpack** (needs a WP.com connection) · **Wordfence** · **Site Kit by Google**
- Server-specific caches: **LiteSpeed Cache** (needs a LiteSpeed server),
  **WP Rocket / W3TC** (Breeze already covers the page-cache case in production)
- Commercial builders: **Divi**, **WPBakery** — the engine works on the final HTML,
  so the risk is low, but it has not been verified
- **Gravity Forms** (commercial)
- Multisite (known limitation: uninstall does not iterate over sites)

## Why most things just work

- The engine translates the **final rendered HTML** (an output buffer opened on
  `template_redirect`, priority 1). Anything that produces standard HTML is
  compatible by design.
- Each language lives under its own URL prefix, so page caches of any brand
  separate the languages automatically.
- Text injected by JavaScript (banners, popups, AJAX) is covered by `dynamic.js`
  (a MutationObserver that looks up translations that already exist).
