# TranslateRocket — Compatibility test log

Ultimo aggiornamento: 2026-07-02 · plugin v0.5.1
Ambienti: **trqa** (LocalWP, localhost:10010, WP 6.x + PHP 8.2) · **clone scaliapalermo** (LocalWP, localhost:10004, sito reale clonato) · **translaterocket.com live** (Cloudways).

Batteria standard per ogni plugin: home EN ok · /de/ tradotto (marker tedesco) · `html lang="de"` · /ar/ `dir="rtl"` · nessun PHP fatal · login raggiungibile.

## Testati — PASS

| Plugin | Dove | Cosa è stato verificato |
|---|---|---|
| **WooCommerce** | trqa | Carrello e My-account in tedesco (server-side), fragments AJAX mini-cart, block cart/checkout via JS, email nella lingua dell'ordine, pagine cart/checkout escluse dalla nostra cache |
| **Elementor** | trqa | Pagina costruita con Elementor tradotta al 100% in DE, markup intatto |
| **Contact Form 7** | trqa | Form renderizzato su /contact/ e /de/contact/, etichette tradotte |
| **Yoast SEO** | trqa | Nessun hreflang duplicato (7 = 6 lingue + x-default, solo i nostri), title/meta ok, la nostra sitemap compare nell'indice di Yoast (`wpseo_sitemap_index`) |
| **Rank Math** | clone scalia | Convive in un sito reale (con mu-plugin schema custom); og:locale non duplicato (guard `seo_plugin_active`) |
| **Autoptimize** | trqa | Aggregazione HTML+CSS+JS attiva: la traduzione viene applicata all'HTML già ottimizzato (il nostro buffer avvolge il suo — ordine LIFO corretto), asset AO presenti, zero conflitti |
| **Cache Enabler** | trqa | Doppio hit su /de/ servito da cache SEMPRE in tedesco; / (EN) mai inquinata dalla cache DE (URL-prefix = chiavi cache separate) |
| **Breeze** (Cloudways) | live | In produzione sul sito del plugin: pagine tradotte cache-ate e servite correttamente |
| **Cookie Notice** | trqa | Banner iniettato via JS tradotto al volo da dynamic.js ("Wir verwenden Cookies…") |
| **Complianz** | clone scalia | Coesistenza su sito reale (banner rilevato; testo JS coperto da dynamic.js) |
| **WPForms Lite** | trqa | Attivazione pulita, pagine EN/DE/AR ok, nessun fatal (embed form: test manuale consigliato) |
| **Astra + Spectra (UAG)** | clone scalia | Sito reale completo: 3.571 stringhe, bulk AI, /it/ perfetto; benchmark ~20ms |
| **Joinchat** | clone scalia | Stringhe gettext del widget tradotte |
| **TranslatePress / Polylang / WPML** | — | Guard anti-conflitto (avviso se attivi insieme) + importer dedicati (TP: dictionary+gettext+slug; test reale: 376 stringhe rilevate sul clone) |

## Da testare (coda)

- **Jetpack** (richiede connessione WP.com) · **Wordfence** · **Site Kit by Google**
- Cache server-specific: **LiteSpeed Cache** (serve un server LS), **WP Rocket / W3TC** (Breeze già copre il caso page-cache in produzione)
- Builder a pagamento: **Divi**, **WPBakery** (l'engine lavora sull'HTML finale: rischio basso, ma da verificare)
- **Gravity Forms** (a pagamento)
- Multisite (limitazione nota: uninstall non itera i siti)

## Note architetturali

- Il motore traduce l'**HTML finale renderizzato** (output buffer su `template_redirect` prio 1): tutto ciò che produce HTML standard è compatibile by design.
- Ogni lingua vive sotto il proprio prefisso URL → le page-cache (di qualunque marca) separano le lingue automaticamente.
- Testi iniettati via JavaScript (banner, popup, AJAX) coperti da `dynamic.js` (MutationObserver + lookup delle traduzioni esistenti).

## Importatore universale con gli altri plugin (30/9/2026, 1.7.0, commit 7f53625)

Prove: `_ssh/collaudi/collaudo-universale-altri.sh` (nel giro completo) = le prove d'import di ogni plugin con
`UNIVERSALE=1` (passo `universale-passo.php`: controlla ogni coppia contro le traduzioni vere della scena).

| Plugin | Esito | Note |
|---|---|---|
| Bogo 3.9.3 | 10/10 | 0 coppie sbagliate |
| qTranslate-XT 3.16.1 | 12/12 | acceso dalla bacheca (da riga di comando chiude: `cli_come_bacheca`); di serie serve solo en,de |
| WPGlobus 3.0.5 | 12/12 | di serie non serve it/ar/zh: la prova le abilita |
| WP Multilang 2.4.33 | 12/12 | |
| Multilanguage 1.5.2 | 10/10 | indirizzi ?lang=it_IT / /it_IT/ trovati dagli hreflang (serve «link alternativi» acceso) |
| TranslatePress 3.3.5 | 22/22 | collaudo-universale.sh |
| Polylang + WooCommerce | 17/24 (solo `POLYLANG=1`) | nota d'acquisto e varianti NON sono testo della pagina: per Polylang resta l'importatore a tabella |
| GTranslate 5.0.1 gratuito | 5/5 | «traduce solo nel browser», nulla importato. NON si puo' dire «importa da GTranslate» |
| GTranslate a pagamento | prova a secco su pagine vere (`universale-secco.php`) | medicoverhospitals.in en→fr 647 coppie ok; ingv.it it→en 249 coppie, 2 titoli di notizie ancora sbagliati (elenco in ordine diverso nella copia in cache) |

Difetti trovati e corretti: l'universale leggeva le pagine di TranslateRocket stesso quando serviva gia' le lingue
(ora aspetta «Modalita' affiancata» o lingue offline); «traduzioni trovate» con 0 coppie; coppie sbagliate da copie
tradotte vecchie (ora: numeri uguali, blocco scartato intero, forma anche del nonno).
