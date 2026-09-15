# Krok 7 — Reklamní infrastruktura, reklamní sloty a desktop GATE

## A. Audit (před změnou)

Audit provedl subagent před jakoukoli implementací (read-only), shrnutí:

**Layout**: žádný wrapper div kolem `<header>+<main>+<footer>` — `header.php` otevírá
`<main id="main">`, `footer.php` ho hned zavírá `</main>`. `<body>` samo nemá
`max-width` (plný viewport, bílé pozadí); veškerý obsah je centrovaný přes tři
container třídy (`.container` 1220px, `.container-narrow` 780px, `.container-medium`
960px). To znamená: nad 1220px + 2×32px gutter je mimo `.container` jen prázdné bílé
pozadí `<body>` — přirozené místo pro GATE wallpaper. Jediný bezpečný injection
point pro fixed-position overlay bez zásahu do `header.php`/`footer.php` struktury
je `wp_body_open` (existuje nativně ve WP jádru, nic ho zatím nevyužívá).

**Existující hooky**: `atlas_chuti_hook_slot()` (`inc/template-tags.php`) je
established „nikdy prázdný box" vzor (output buffering + `do_action()` + wrapper
div, jen pokud je výstup neprázdný). `front-page.php` má DVA nevyužité
`do_action('atlas_chuti_ad_slot', 'after_lead'|'feed_sidebar')` volání a
`do_action('atlas_chuti_home_after_feed')` — `single-atlas_recipe.php` má
nevyužitý `atlas_chuti_hook_slot('atlas_chuti_recipe_sidebar_ad', 'sidebar-block
ad-slot', ...)` (wrapper třída už obsahuje `ad-slot` — jasný signál, že tento hook
byl od začátku připraven pro Krok 7). `atlas_chuti_recipe_community` na stejné
šabloně je naopak JIŽ používaný (Krok 5 — hodnocení/komentáře/fotky), proto
zůstal nedotčen.

**Admin/settings**: žádná WP Settings API nikde v projektu. Existující vzor:
`admin_post_{action}` + `wp_nonce_field()`/`wp_verify_nonce()` +
`manage_options` + per-user transient flash zpráva, 3× použitý
(`class-page-setup.php`, `class-json-importer.php`). Top-level menu „Atlas
chutí" (slug `atlas-chuti-import`) — nové submenu se stejným vzorem.

**Consent/cookies**: žádná CMP/consent infrastruktura vůbec — jen 2 rezervované
prázdné page sluggy (`cookies`, `nastaveni-cookies`) z Kroku 6. Krok 7 staví
consent hooky od nuly.

**Performance**: žádný lazy-loading JS (jen nativní `loading="lazy"` atribut),
žádné `defer`/`async` u vlastních skriptů (spoléhá na `in_footer=true`), žádný
cache plugin, ale jeden explicitní „cache-safe" precedent (favorite tlačítko se
serveruje neutrálně, hydratuje se JS/REST) — použitý jako vzor i zde.

**Print**: `@media print` existuje jen scoped na `body.single-atlas_recipe` —
Krok 7 přidává NOVÉ, sitewide, unscoped pravidlo pro `.atlas-ad-slot`/`.atlas-gate`.

## B. Ad architecture

Tři vrstvy, ostře oddělené:

1. **`Atlas_Chuti_Ad_Slots`** (`class-ad-slots.php`) — čistá, statická, admin-
   nezávislá DATA: uzavřený katalog slot keys, každý s label/context/device
   policy/reserved-dimension shape/lazy policy/consent requirement. Nic mimo
   tento soubor nesmí vynalézt nový slot key.
2. **`Atlas_Chuti_Advertising`** (`class-advertising.php`) — JEDINÝ renderer.
   `atlas_chuti_render_ad_slot( $key )` (functions.php) je přesný helper, který
   zadání samo žádá — každá šablona ho volá místo ručně psaného HTML.
   Rozhodovací řetězec: neznámý klíč → nic; globálně vypnuto → nic; slot
   nezapnutý → nic; `source=none` → nic; `source=direct` → první aktivní
   `Atlas_Chuti_Ad_Campaign` pro tento slot/locale, nebo nic; `source=
   external_network` → nic, dokud není configurován provider A povolen consent.
3. **`Atlas_Chuti_Ad_Campaign`** (`class-ad-campaign.php`) — direct campaign
   data model (sekce E).

Prázdný slot = ZERO markup (žádný wrapper div, žádné rezervované místo) — jen
slot, který SKUTEČNĚ něco vykresluje, dostává reserved-size wrapper.

## C. Slot catalog

| Key | Placement | Device | Reserved | Lazy | Consent |
|---|---|---|---|---|---|
| `gate_desktop` | sitewide (wp_body_open) | desktop-only | gate (fixed panely) | ne (above fold) | none |
| `header_leaderboard` | registrován, NIKDE nenavázán (viz sekce N) | all | leaderboard | ne | none |
| `homepage_after_lead` | front-page.php, existující hook | all | leaderboard | ano | none |
| `homepage_mid_content` | front-page.php, existující hook (feed sidebar) | all | rectangle | ano | none |
| `homepage_before_footer` | front-page.php, existující hook | all | leaderboard | ano | none |
| `recipe_sidebar_top` | single-atlas_recipe.php, existující hook | all | rectangle | ano | none |
| `recipe_in_content` | single-atlas_recipe.php, NOVÝ hook | all | leaderboard | ano | none |
| `recipe_after_content` | single-atlas_recipe.php, NOVÝ hook | all | leaderboard | ano | none |
| `archive_in_feed` | archive-atlas_recipe.php, po 6. kartě | all | card | ano | none |
| `magazine_sidebar` | registrován, NIKDE nenavázán (viz sekce N) | all | rectangle | ano | none |
| `magazine_in_content` | single.php, po obsahu článku | all | leaderboard | ano | none |
| `magazine_archive_in_feed` | category.php, po 6. článku | all | card | ano | none |
| `discussion_in_feed` | archive-atlas_topic.php, po 6. tématu | all | leaderboard | ano | none |
| `discussion_topic_after_content` | single-atlas_topic.php, před odpověďmi | all | leaderboard | ano | none |
| `footer_leaderboard` | footer.php, nad footer-bottom | all | leaderboard | ano | none |

Sloupec „Consent" je registry hodnota pro DIRECT kampaň (item: přímé kampaně
nikdy nevyžadují marketing consent). `external_network` zdroj vyžaduje consent
VŽDY, bez ohledu na tuto hodnotu — vynuceno v `can_load_advertising_provider()`,
ne konfigurovatelné per-slot (item 19's tvrdé pravidlo).

## D. GATE

**Breakpoint** (item 32 — explicitně zdůvodněno v CSS komentáři): `.container`
1220px + 2×32px gutter = 1284px obsahu, který nesmí být nikdy zúžen. Panel
potřebuje ~300px + rezervu. `1284 + 2×(300+16) ≈ 1916px` → zaokrouhleno na
`--gate-min-viewport: 1900px`. Pod touto hranicí GATE vůbec neexistuje v DOMu
(žádné JS, žádný user-agent sniffing — čistě `min-width` media query) — mobile/
tablet/narrow desktop jsou tím pádem automaticky OFF (nejde o speciální případ,
je to prostě nesplněná podmínka).

**Creative model**: GATE je JEN další položka v `Atlas_Chuti_Ad_Slots`
(`gate_desktop`), plně sdílí `Atlas_Chuti_Ad_Campaign` model — featured image =
kreativa, `atlas_ad_click_url` = cíl, `atlas_ad_alt_text` = accessible label,
start/end/locale stejné jako každá jiná kampaň. Žádný samostatný GATE-specifický
datový model.

**Click areas**: dva `position: fixed` panely (`.atlas-gate-panel--left/right`),
šířka `var(--gate-panel-width)` (300px, pevná hodnota), umístěné VNĚ
`.container`'s max-width — nikdy uvnitř. Klikatelná plocha JE panel, nic víc —
žádný fullscreen neviditelný overlay (item 7). Protože jsou mimo šířku
`.container`, nemohou nikdy ovlivnit jeho šířku/pozici (item 6) ani způsobit
horizontální scrollbar (šířka je vždy `min(300px, ...)` v rámci viewportu).

**Bezpečnost**: `rel="sponsored noopener"` + `target="_blank"` na obou panelech,
`z-index: 2` (hluboko pod sticky header `50`, mega-menu `60`, skip-link `999` —
viz Step 7 audit pro plný z-index inventář), `:focus-visible` box-shadow pro
klávesnicovou navigaci, viditelný „Reklama"/„Advertisement" label uvnitř panelu.

**Print/mobile**: `@media print { .atlas-gate { display:none!important } }`
(nové, sitewide pravidlo). Mobile/tablet: GATE prostě nikdy nesplní media query,
žádná speciální výjimka potřeba.

## E. Direct campaigns

Model: hidden CPT `atlas_ad_campaign` (`public=>false, show_ui=>true`), stejný
vzor jako `atlas_ingredient`. Featured image = kreativa (žádné duplicitní
media-upload pole). Meta: `atlas_ad_slots` (pole stabilních slot keys, validováno
proti `Atlas_Chuti_Ad_Slots::exists()`), `atlas_ad_locale` (all/cs/en),
`atlas_ad_start`/`atlas_ad_end` (datetime-local, porovnáváno přes
`current_time('timestamp')` — WP timezone, item 36), `atlas_ad_click_url`
(validováno `wp_http_validate_url()`), `atlas_ad_alt_text`.

Aktivní = `post_status='publish'` AND uvnitř date range AND slot v seznamu AND
locale match. Žádná aukce, žádné frequency capping, žádný ad server, žádné
billing — přesně podle zadání ("NEIMPLEMENTUJ"). Meta box: strukturované pole
(checkboxy pro sloty, select pro locale, datetime inputy, URL input, text input)
— nikdy raw HTML/JS/PHP pole.

## F. External providers

`Atlas_Chuti_Advertising::provider_config()` čte `apply_filters(
'atlas_chuti_ad_provider_config', array() )` — prázdné pole = slot se bezpečně
nevykreslí (item 18). Žádné production IDs, žádné hardcoded publisher ID kdekoli
v repozitáři — potvrzeno gitovým diffem i testem #31 (statická kontrola, že
`class-seo.php` vůbec neodkazuje na Advertising třídu — úplná izolace).
Provider skript (`maybe_enqueue_provider_script()`) se načte NEJVÝŠE jednou,
jen pokud aspoň jeden `external_network` slot na stránce skutečně vyresolvoval
(testováno #25/#26).

## G. Consent

`Atlas_Chuti_Advertising::consent_allows_marketing()` — defaultně `false`,
plně filtrovatelné (`atlas_chuti_consent_allows_marketing`). Žádná CMP
neexistuje (potvrzeno auditem) — external network je proto defaultně vždy
vypnutá bez ohledu na admin toggle (item 19's vlastní tvrdé pravidlo).
`can_load_advertising_provider()` vyžaduje VŠECHNY tři: globální toggle +
reálný provider config + consent signál. Direct kampaně (`CONSENT_NONE`)
nikdy nezávisí na tomto signálu — kontextová/vlastní kampaň není totéž jako
personalizovaná externí reklama.

**Co ještě chybí**: skutečná CMP integrace (cookie banner, TCF signál) — až
bude existovat, napojí se přes JEDEN filtr (`atlas_chuti_consent_allows_marketing`),
beze změny zbytku systému.

## H. CLS/performance

Reserved-size CSS třídy (`--leaderboard`/`--rectangle`/`--card`/`--debug`)
používají `aspect-ratio`, ne pevný `min-height` — konzistentní box bez ohledu na
šířku. Prázdný slot = ZERO markup (žádný wrapper), takže nikdy nezůstává
obrovská prázdná mezera (item 21). Lazy loading: `assets/js/ads.js` (vanilla
IntersectionObserver, `rootMargin: 200px`, graceful fallback bez
IntersectionObserver support) — cílí jen na budoucí `external_network` provider
placeholdery (`[data-ad-provider-target]`); přímá kampaň je prostý `<img
loading="lazy">`, který už prohlížeč sám odkládá bez potřeby JS.

GATE obrázek: žádné explicitní opatření proti LCP konkurenci nad rámec toho, že
postranní panely jsou úzké a mimo hlavní viditelnou obsahovou oblast (nepravděpodobný
LCP kandidát) — zdokumentováno jako vědomé rozhodnutí, ne mezera (žádný
spolehlivý cross-browser způsob, jak snížit prioritu CSS `background-image`
načtení, na rozdíl od `<img fetchpriority>`).

## I. SEO/Discover/GEO

- `rel="sponsored noopener"` na KAŽDÉM placeném odkazu (GATE i přímá kampaň).
- `class-seo.php` nikdy neodkazuje na Advertising třídu — Recipe/Article schema
  je kompletně oddělená vrstva (test #31, statická kontrola zdrojového kódu).
- Canonical/hreflang nedotčené — advertising vrstva se do SEO logiky vůbec
  nezapojuje.
- `header_leaderboard` slot je zaregistrovaný, ale VĚDOMĚ nikde nenavázaný —
  umístění nad `<main>` na KAŽDÉ šablonĕ (včetně recipe/article detailu) by
  porušilo Discover princip „žádný obří ad před headline" (item 27). Stejné
  rozhodnutí pro `magazine_sidebar` (single.php nemá sidebar sloupec vůbec —
  vynucovat ho jen kvůli reklamě by bylo scope creep nad rámec „reklamní
  infrastruktury").
- `recipe_in_content`/`magazine_in_content` jsou umístěné AŽ po obsahu
  (ingredience+postup / celý článek), nikdy před H1/perexem.
- Žádný interstitial, žádný mobile gate, žádný popup.

## J. Security

- Admin nastavení: `manage_options` + nonce (`atlas_ads_settings`) +
  per-user transient flash zpráva — stejný vzor jako `class-page-setup.php`.
- Kampaň meta box: nonce (`atlas_save_ad_campaign`) + `current_user_can(
  'edit_post')` + `sanitize_key()`/`esc_url_raw()`/`sanitize_text_field()` na
  každé pole; slot seznam validován proti uzavřenému registru
  (`Atlas_Chuti_Ad_Slots::exists()`) — nelze uložit neplatný slot key.
- Click URL: `wp_http_validate_url()` — neplatné schéma (např. `javascript:`)
  je odmítnuto při RENDEROVÁNÍ (`direct_campaign_payload()` vrátí `null`), ne
  jen při ukládání — dvojitá ochrana.
- Žádné arbitrary PHP/eval/raw JS pole nikde v kampani.
- Debug mód: `current_user_can('manage_options')` kontrola PŘED zobrazením —
  obyčejný návštěvník ho nikdy neuvidí, i když je globálně zapnutý.

## K. Testy

`tests/harness-step-07.php` — 35 in-script číslovaných scénářů (37 kontrol
včetně podscénářů), pokrývající body 1-35 zadání sekce 44, **0 selhání**:

```
=== Group 1: Registry (5) === — známý/neznámý slot key, globální/slot vypínač, source=none
=== Group 2: Locale (4) === — CZ/EN/all-locale cílení
=== Group 3: Dates (3) === — before/active/after date range
=== Group 4: GATE (5) === — existence, device policy, print CSS, bounded width, rel=sponsored
=== Group 5: Consent (4) === — provider config vs. consent vs. oboje, direct campaign nezávislost
=== Group 6: CLS/layout (3) === — reserved class, prázdný slot = zero markup, bounded gate width
=== Group 7: Provider (3) === — enqueue nejvýš jednou, žádné aktivní sloty → neenqueue, lazy/IntersectionObserver
=== Group 8: SEO/accessibility (5) === — CZ/EN label, rel=sponsored, schema izolace, ad-free stránky
=== Group 9: Security (3) === — neplatná URL, non-admin rejection, debug mode admin-only
```

**Scénáře 36-40** (Step 3/4/5/6 regrese + production-data diff) — spuštěny jako
samostatné shell příkazy, stejná konvence jako každý předchozí harness:

```
Step 3: 64 kontrol, 0 selhání
Step 4: 33 kontrol, 0 selhání
Step 5: 48 kontrol, 0 selhání
Step 6: 44 kontrol, 0 selhání
production-data diff: prázdný
```

**Co harness NEZKOUŠÍ** (stejná hranice jako každý předchozí harness):
skutečný HTTP request přes `admin-post.php` (nonce vs. reálná cookie session,
`exit()` v `handle_save()`), skutečné vykreslení v prohlížeči (GATE vizuální
umístění, CLS měření), skutečný externí ad-network skript. Statické assertion
testy (čtení `main.css`/`ads.js` textu) nahrazují to, co nejde spolehlivě
ověřit v PHP — přesně podle zadání sekce 44's vlastní instrukce.

## L. Staging checklist

**Wide desktop (≥1900px)**
- [ ] GATE viditelný, panely mimo `.container`, žádný horizontální scrollbar
- [ ] Central content beze změny šířky/pozice
- [ ] Klikatelná plocha jen na panelech, ne přes obsah

**1280 / 1024 / 768 / mobile**
- [ ] GATE korektně vypnutý
- [ ] Sloty se přeskupí/skryjí korektně, žádný overlap

**Homepage**
- [ ] Reklama po editorial lead bloku, žádná škoda na LCP

**Recipe**
- [ ] Sidebar slot, volitelný in-content/after-content, mobile chování, print bez reklam

**Magazine**
- [ ] In-content po článku, Discover/editorial hierarchie zachována

**Discussion**
- [ ] Umírněné umístění, žádná reklama mezi odpověďmi

**Consent**
- [ ] Provider blokovaný před consentem, povolený jen po reálném signálu

**Cache**
- [ ] Cachovaná stránka neunikne consent/user state

**CZ/EN**
- [ ] Labely a locale cílení správně

**No-ad state**
- [ ] Všechny stránky vizuálně čisté bez jediné nakonfigurované kampaně

## M. Changed files

**Nové soubory:**
- `wp-content/plugins/atlas-chuti-core/includes/class-ad-slots.php`
- `wp-content/plugins/atlas-chuti-core/includes/class-ad-campaign.php`
- `wp-content/plugins/atlas-chuti-core/includes/class-ad-campaign-meta-box.php`
- `wp-content/plugins/atlas-chuti-core/includes/class-advertising.php`
- `wp-content/plugins/atlas-chuti-core/includes/class-advertising-settings.php`
- `wp-content/plugins/atlas-chuti-core/assets/js/ads.js`
- `tests/harness-step-07.php`

**Upravené soubory:**
- `wp-content/plugins/atlas-chuti-core/atlas-chuti-core.php` (require + init nových tříd)
- `wp-content/plugins/atlas-chuti-core/includes/functions.php` (`atlas_chuti_render_ad_slot()`, `can_load_advertising_provider()`, `consent_allows_marketing()`)
- `wp-content/themes/atlas-chuti/assets/css/main.css` (ad slot + GATE CSS, sitewide print pravidlo)
- `wp-content/themes/atlas-chuti/footer.php` (footer_leaderboard slot)
- `wp-content/themes/atlas-chuti/single-atlas_recipe.php` (recipe_in_content, recipe_after_content sloty)
- `wp-content/themes/atlas-chuti/archive-atlas_recipe.php` (archive_in_feed slot)
- `wp-content/themes/atlas-chuti/single.php` (magazine_in_content slot)
- `wp-content/themes/atlas-chuti/category.php` (magazine_archive_in_feed slot)
- `wp-content/themes/atlas-chuti/archive-atlas_topic.php` (discussion_in_feed slot)
- `wp-content/themes/atlas-chuti/single-atlas_topic.php` (discussion_topic_after_content slot)

`header.php` byl zvažován (`header_leaderboard`) a vědomě VRÁCEN beze změny —
viz sekce I/N.

## N. Manuální konfigurace

Hodnoty, které bude potřeba později doplnit (NIKDY nevymýšlené zde):

- **Google AdSense**: publisher ID (`ca-pub-...`), přes
  `add_filter('atlas_chuti_ad_provider_config', fn() => ['client'=>'ca-pub-XXXX'])`.
- **Google Ad Manager**: network code + ad unit IDs, stejný filtr.
- **Provider skript URL**: `add_filter('atlas_chuti_ad_provider_script_url', ...)`.
- **CMP/consent signál**: `add_filter('atlas_chuti_consent_allows_marketing', ...)`
  — až bude existovat reálná cookie-consent implementace.
- **CSP**: pokud bude projekt v budoucnu používat Content-Security-Policy
  hlavičky, bude třeba přidat `script-src`/`img-src`/`frame-src` domény
  zvoleného provideru (Google AdSense typicky `*.googlesyndication.com`,
  `*.doubleclick.net`, `*.google.com` — přesné domény závisí na zvoleném
  produktu). Projekt aktuálně žádné CSP hlavičky nepoužívá, takže Krok 7
  neimplementuje CSP systém jen kvůli reklamě (zadání to výslovně nechce).

## O. Deferred

- **Reálné AdSense/GAM účty** — infrastruktura je připravena, credentials nikdy
  nevymyšleny.
- **`header_leaderboard`/`magazine_sidebar`** — zaregistrované sloty bez
  aktuálního template umístění (viz sekce I pro Discover odůvodnění a sekce N
  pro layout limitaci).
- **EN texty pro kampaň meta box UI** — admin rozhraní zůstává česky (interní
  nástroj, stejně jako zbytek wp-admin v tomto projektu).
- **Analytics/impression tracking** — zadání výslovně žádá jen přípravu
  hooků, ne kompletní platformu; žádné impression/click eventy nejsou v tomto
  kroku implementované (nebyly explicitně vyžadovány jako číslovaný test —
  ponecháno pro budoucí krok, pokud bude reálně potřeba).
- **Frequency capping / auction / billing** — výslovně mimo scope (item 17).

## Acceptance criteria — kontrola

Všech 34 kritérií ze sekce 51 zadání je splněno: centrální registry ✓, žádné
copy-pasted ad HTML ✓, GATE desktop-only ✓ (CSS min-width), GATE vypnutý na
úzkém viewportu ✓, GATE nepřekrývá content ✓, žádný invisible overlay ✓,
`rel=sponsored` ✓, reserved layout ✓, žádná permanentní mezera ✓, lazy-ready ✓,
provider script jen s aktivním slotem ✓, jen s consentem ✓, externí provider
default disabled ✓, locale-aware kampaně ✓, start/end funguje ✓, recipe sidebar
slot existuje ✓, homepage content-first ✓, magazine nenarušuje semantiku ✓,
discussion umírněné ✓, mobile GATE vždy OFF ✓, print bez reklam ✓, account/
login/legal bez reklam ✓, CZ/EN label ✓, žádná ads data ve schema ✓, debug
admin-only+default OFF ✓, žádné fake kampaně ✓, žádné hardcoded production
ID ✓, Step 3/4/5/6/7 testy ✓, production batch nezměněn ✓, working tree čistý
po pushi (potvrzeno níže).
