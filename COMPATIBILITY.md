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

## 1.7.8 — import copia‑incolla, preset switcher, menu (7/10/2026, wp.org r3732394, sorgente 8fcafe9)
- Segnalazione di un utente (sito ES con /it/ e /en/): il banner Complianz mostrava testi di articoli. Causa: l'import
  copia‑incolla abbinava una riga SENZA numero alla frase nella stessa posizione; una frase lunga spezzata da Google
  Translate su due righe faceva slittare tutto il seguito. Ora `Admin::paste_pairs` (la riga senza numero continua la
  precedente; elenco senza numeri accettato solo se le righe tornano) + `paste_plausible` (traduzione impossibile per
  lunghezza → scartata); stesso criterio in `visual-editor.js applyPaste`. I motori AI già rifiutano risposte col
  conteggio sbagliato: il copia‑incolla era l'unico canale posizionale. Suite `collaudo-incolla.sh` (con la 1.7.7
  fallisce, con la 1.7.8 5/5).
- Personalizzatore switcher: il preset non ridisegnava la scheda «il tuo sito, dal vivo» (valori messi da script, nessun
  evento) → `dispatchEvent('change')`; `menu_css` colora anche la voce lingua nel menu (prima solo il riquadro sotto);
  avviso in pagina quando il menu mostra un altro profilo (caso del banco tre: «header» non era in_menu, lo era
  Default). Suite `collaudo-preset.sh` 10/10, `collaudo-hover.sh` 8/8 (apertura al passaggio/clic nel blocco
  navigazione di Twenty Twenty‑Five).
- Gotcha suite: i preset stanno in un `<details>` chiuso (aprirli prima del clic); la scheda del sito tiene la pagina
  vecchia finché la nuova non è arrivata (aspettare il colore, non solo il cambio di `src`).
- Aperti dello stesso utente, NON riproducibili senza dati: link «Vedi di più» di Smart Post Show **Pro** (la free non
  ha il carica‑altri AJAX; `AjaxContent` localizza i link via referer), switcher di un altro sito (serve l'URL),
  «5 stringhe rimaste» (serve screenshot).

## Sicurezza 1.7.9 — audit chiuso sul banco (7/10/2026, commit locali, NON rilasciata)
- `collaudo-sicurezza.sh` + `sicurezza.js`: 11 attacchi veri (XSS da traduzione «AI» in gettext testo/attributo, uscita
  da uno script, titolo REST, collaboratore che scrive fuori pagina, crescita del DB da indirizzi «AJAX» inventati,
  varianti di cache, lingua offline letta da anonimo). Con la 1.7.8 pubblicata: 6 riusciti → dopo le correzioni 11/11.
- Correzioni: `Kses::like_original` (al salvataggio, per OGNI canale: solo i tag passano da kses, uno per uno, il testo
  intorno resta byte per byte — `&`, «5 < 7 > 3», segnaposto `<1>`), `Kses::plain_like` solo in Gettext (il motore
  escapa già i testi: in Fragment::text raddoppiava), ScriptData `JSON_HEX_TAG` + `\x3C`/U+2028, MetaBox limitato alle
  frasi della pagina + stesso `paste_pairs` dell'1.7.8 (il riquadro editor aveva ancora l'abbinamento posizionale!),
  dyn/AJAX/REST rifiutano le lingue offline agli anonimi (`public_languages`), AjaxContent raccoglie per gli anonimi solo
  da azioni admin-ajax registrate nopriv, chiave di cache normalizzata (minuscole + decodifica), importatore universale
  solo sul proprio host, Falang `unserialize` senza classi.
- Gotcha: un sondaggio con `tr_sorgente` fuori da `banco_inizio` lascia la cartella del plugin diversa da
  `tr-plugin-prima` e la suite successiva si ferma con «FERMO»: rimettere `cp -r $S/tr-plugin-prima`.
- `collaudo-testi-casuali.sh`: lo script coi dati JSON va stampato da un mu-plugin (nel contenuto WordPress texturizza
  le virgolette già nell'originale); aria-label sempre con lettere.

## 1.7.9 — pubblicata 7/10/2026 ~14:30 (wp.org r3732627, GitHub 16c2558, sorgente 4ab71a7)
Sicurezza (audit 11/11), incolla nel riquadro editor con `paste_pairs`, frasi intere con parti senza lettere (InlineText),
avviso + pulsante «Usa questo profilo nel menu» (9 lingue). Prova di aggiornamento 1.7.8→1.7.9: 8/8. Banco generico: +Rank
Math, Ninja Forms, The Events Calendar, All in One SEO, MailPoet (tutti verdi). Sito: /download/ con due pulsanti (wp.org +
`/get/translaterocket/`), versione letta dal plugin a ogni `rilascio sito` (`_ssh/pagina-download.js`), 4 frasi tradotte
in 21 lingue (`_ssh/trad-download/`).

## 1.7.10 — pubblicata 7/10/2026 ~17:35 (wp.org r3733020, GitHub d557897, sorgente c5981b0)
- Estratti di «Ultimi articoli» non tradotti (trovato con lo scenario «sorgente spagnola», vale per ogni sito): la frase
  finisce con il link «Read more» che WordPress localizza da sé (msgid = l'intero frammento HTML del link!), quindi su
  /it/ la frase-unità non coincideva mai con quella raccolta. Tentata prima una mappa «etichetta → forma sorgente»
  (tre iterazioni, scartata: la classe Gettext non è più avviata dal commit 0036e82 e il msgid non è l'etichetta);
  soluzione finale: in `InlineText::is_empty_inline` un `<a>` con classe more-link / read-more /
  wp-block-latest-posts__read-more / wp-block-post-excerpt__more-link è come `<time>`: `<N/>`, resta a WordPress.
  Suite `collaudo-leggi-tutto.sh` 5/5, `collaudo-sorgente-spagnola.sh` 15/15 (ES alla radice, /it/ /en/, banner
  cookie, incolla alla Google, switcher, hreflang, sitemap).
- Scheda «il tuo sito, dal vivo»: indicatore di caricamento (`.is-loading` su `.trr-sw-site-wrap`).
- Suite nuove: `collaudo-switcher-matrice.sh` + `matrice.js` 164/164 (TT5 + Hello Elementor × menu/punto/shortcode/
  fluttuante × tendina/in linea/elenco/scorrimento/griglia + 3 posizioni nel menu); `collaudo-pulsanti-pagina.sh`
  (ricostruita, 17/17); `collaudo-pulsanti.sh` + `pulsanti.js` (walker «ogni pulsante admin», NON ancora affidabile:
  clic che vanno in timeout «visible, enabled and stable» pur con pulsanti fermi — da capire, non è un difetto del plugin).
- Gotcha sonde: la tendina ha DUE `.trrocket-dd-toggle` (span-misura + button): cliccare `button.trrocket-dd-toggle`.
- Aperti per la .11: compatibilità Essential Addons, Elementor Pro, Redirection, Jetpack (zip manca), temi classici
  (Astra, OceanWP, GeneratePress) con lo switcher nel menu.

## Serata 7/10/2026 — compatibilità (dopo la 1.7.10), verso la 1.7.11
- 🐞 **Redirection** (2M): /it/vecchia/ → /nuova/ perdeva la lingua. Corretto: `Router::keep_language_on_redirect` su
  `wp_redirect` a priorità **0** (Redirection aggancia a 1 e su nginx+php-cgi manda l'header ed esce), con
  `get_option('home')` (home_url() è già prefissata dal Router: col filtro usciva /it/it/). `collaudo-redirection.sh` 9/9;
  regressioni: canonico 8/8, Woo cliente 61/61. Commit 77545fd.
- ✅ 16 plugin nuovi nel banco generico, tutti verdi: Essential Addons (pagina Elementor vera 26/26), CoBlocks,
  Asgaros Forum, Restrict Content, Jetpack, Dokan, WP Recipe Maker 15/15, Shortcodes Ultimate, Strong Testimonials,
  Easy TOC, AddToAny 20/20, Everest Forms, The SEO Framework, SEOPress, Pojo Accessibility, Genesis Blocks,
  Essential Blocks, Booking Calendar. Kali Forms e WP User Frontend: senza modulo predefinito, da completare.
- ✅ Temi classici col menu (Astra, OceanWP, GeneratePress) 25/25; RTL ricostruita 16/16.
- Walker «ogni pulsante admin»: i timeout di Playwright erano clic RIUSCITI che facevano navigare la pagina (il
  controllo «stabile» perdeva la gara con la navigazione): ora un clic seguito da un cambio di indirizzo conta come
  riuscito. In prova.
- ⚠️ Due volte ho sovrascritto suite esistenti con Write (collaudo-pulsanti.sh → ricostruita come
  collaudo-pulsanti-pagina.sh; collaudo-rtl.sh → ricostruita dal log). Da ora i file in collaudi si creano solo con
  `[ -e F ] && echo ESISTE || cat > F`.
- Walker admin funzionante: 17/19 al penultimo giro (restava solo il chip «White» gia' selezionato: ora i comandi
  gia' selezionati si saltano). Entra nel giro completo (collaudo-tutto, ~25 min).

## Piano 1.7.12 (8/10/2026) — nuove dinamiche e nuovi plugin (la 1.7.11 = Redirection esce oggi)
- `collaudo-core-dinamiche.sh` + `core-dinamiche.js`: Query Loop con paginazione avanzata (fetch pagina 2), blocco
  Dettagli, commenti su /it/ (invio + redirect + comparsa), 404 e articolo con password su /it/, ricerca con e senza
  risultati.
- Banco generico, zip in `ricerca/ecosistema/plugin/nuovi-0810/`: Ajax Load More (con clic su «carica altri»),
  WP Show Posts, Advanced Ads, WPCode, WP-PostRatings, Simple Lightbox, Nextend Social Login, Instagram Feed.
- Da fare poi: Kali Forms / WP User Frontend / Events Manager con contenuto creato a mano; sito in sottocartella.
- Notte 8/10: `collaudo-core-dinamiche.sh` 19/19 (Query Loop paginazione avanzata, Dettagli, commenti, 404, password,
  ricerca). 🐞 corretto per la 1.7.11 (a591081): «Protetto: Titolo» nelle liste su /it/ → `Engine::without_title_prefix`
  (prefissi di protected_title_format/private_title_format nella lingua della pagina messi da parte, titolo tradotto;
  alla raccolta si memorizza il titolo senza prefisso). Plugin: Ajax Load More 17/17, WP Show Posts, Advanced Ads,
  WPCode verdi.

## 1.7.12 — pubblicata 8/10/2026 mattina (wp.org r3733777, GitHub 4505a57, sorgente ef9e0db; la .11 è stata saltata)
Redirect che mantengono la lingua (Redirection), titoli protetti/privati nelle liste, 25 plugin + 3 temi classici + RTL
+ sorgente spagnola + dinamiche core collaudati. Regressioni prima di uscire: sicurezza 11, redirection 9, canonico 8,
Woo 61, matrice 164, preset 10, RTL 16, banner 17, cookie 20, dinamiche core 19, spagnola 15, leggi-tutto 5,
parte-senza-lettere 4, testi casuali 51, incolla 5, temi classici 25, pulsanti-pagina 17, inline 33, aggiornamento 8.
Federico: «se è tutto ok rilascia direttamente» → pubblicata senza OK finale.

## Un dominio per lingua (8/10/2026, richiesta forum «multi-domain») — NON rilasciato
- Plugin gratuito (commit 495013f): `Router::language_base()` / `has_own_domain()` / `language_of_host()` + filtri
  `trrocket_language_base` e `trrocket_language_from_host`. Switcher, hreflang, sitemap, link nel contenuto
  (`Engine::localize_link`), Woo, WP Rocket passano tutti da `language_base`. Senza Labs nessuna differenza:
  matrice 164/164, redirection 9, canonico 8, Woo 61, spagnola 15, dinamiche core 19, testi casuali 51, inline 33.
- Labs (commit 877120d, e8d9215 + frasi): `src/Domains.php`, pagina TranslateRocket > Domains per language (tabella
  lingua → dominio, pulizia dell'indirizzo, controllo «il dominio arriva a questo sito» con ping firmato, SSL), filtri
  registrati AL CARICAMENTO del file Labs (il Router si avvia a plugins_loaded prima di Labs), 301 da /de/ al dominio
  per gli anonimi (i loggati restano sul principale: login per dominio), allowed_redirect_hosts.
  `collaudo-labs-domini.sh` 17/17 (dominio finto mandato al banco con l'intestazione Host). Pagina ok desktop/telefono.
- Limiti noti: login e carrello WooCommerce sono per dominio (passando da .com a .de il carrello non segue);
  DNS/SSL a carico dell'utente. Per uscire: TR 1.7.13 (agganci) + Labs 0.8.0 con TRRLABS_NEEDS_TR = 1.7.13.
- **Labs 0.8.0 PUBBLICATA 8/10/2026 ~14** («Pubblica labs»; zip 627 KB su private_html, endpoint labs-update → 0.8.0;
  Labs 6e91454). Gira con TR 1.7.12: `Domains::supported()` controlla che il Router abbia `language_base` (arriva con
  la 1.7.13), altrimenti la pagina avvisa «serve 1.7.13» e i filtri restano spenti; TRRLABS_NEEDS_TR resta 1.7.1.
  Prove prima: con la 1.7.12 pubblicata dove 41, gentile 27, catena 36, pagina domini 3/3; col sorgente domini 17,
  ospiti 50. (collaudo-labs-ai-gratis è SUPERATA: lanciata per errore, rossi attesi.)

## Prove «modi e lingue diverse» (8/10/2026 pomeriggio, prima del giro completo)
- `collaudo-lingue-varie.sh` + `lingue-varie.js` (nuova): 4 siti sullo stesso banco — A inglese → ja, zh-tw, he (RTL),
  pt-br; B tedesco (WPLANG de_DE) → en, fr; C giapponese → en, it; D francese (fr_FR) → es OFFLINE + it. 100% verdi
  (A tutto + B/C/D 90/90). Gotcha suite: nel comando che cambia la lingua sorgente il plugin ha ancora in memoria le
  lingue di prima → creare i contenuti in un secondo WPR; `S` e `SRC` sono variabili del banco (S di sola lettura).
- PHP 7.4/Apache: testi casuali 51, dinamiche core 19, spagnola 15, temi classici 25, leggi-tutto 5, incolla 5.
- Bacheca in italiano (`ADMIN_LOCALE=it_IT`, nuovo in import-comune `accedi`): walker 19, preset 10, anteprima switcher
  13, traduzione nel browser 9 (sonda resa indipendente dalla lingua: «Sto traducendo X di Y», «Fatto»).
- Nessun difetto del plugin trovato in questa fase. Aggiunte 13 suite nuove a `collaudo-tutto.sh`.
