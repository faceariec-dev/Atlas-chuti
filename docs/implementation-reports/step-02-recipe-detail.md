# Krok 2 — redesign detailu receptu a tiskové sestavy

## A. Audit před změnou

Před jakoukoliv úpravou byl proveden audit stávajícího stavu:

- **`single-atlas_recipe.php`** (233 řádků) — funkční, ale bez jasné 2/3 +
  sidebar hierarchie; akční lišta obsahovala jen tlačítko „Uvařil/a jsem";
  žádný tisk, žádné sdílení, žádný QR kód.
- **Datový model** (`class-meta-fields.php`, `class-servings.php`) —
  kompletní a dostatečný pro tento krok; žádná změna schématu nebyla
  potřeba ani provedena.
- **`Atlas_Chuti_SEO::recipe_schema()`** (`class-seo.php`) — Recipe JSON-LD
  je již kompletní a čte metadata přímo (ne z DOM), takže je vůči redesignu
  šablony bezpečné; **žádné `aggregateRating`** nikde v kódu — potvrzeno.
- **`atlas_chuti_get_breadcrumbs()`** — správná posloupnost Domů → Recepty →
  [země] → název; renderuje se globálně v `header.php`, netřeba měnit.
- **`assets/js/servings.js`** — funkční přepínač porcí; kontrakt
  `[data-servings-switcher][data-default]` / `[data-ingredient-list]
  [data-ingredients]` / `[data-index]` / `.amount` — zachován beze změny.
- **`assets/js/passport.js`** — funkční tlačítko „Uvařil/a jsem"
  (Kulinářský pas, localStorage); kontrakt `[data-passport-recipe-toggle]`
  + `data-recipe` JSON + `.label` span + `.is-active`/`aria-pressed` —
  zachován beze změny.
- **Tisk** — v projektu neexistoval žádný `@media print` blok (ověřeno
  grepem přes celé `main.css`).
- **Sdílení / QR** — v projektu neexistovala žádná pomocná třída pro sdílení
  ani QR kód.
- **Řízený tag systém** — `atlas_meal_type`/`atlas_difficulty`/`atlas_diet`/
  `atlas_ingredient_tax` jsou všechny `public => false`; žádná veřejná
  taxonomie „tagů" neexistuje (potvrzeno v `class-taxonomies.php`) → bod
  13.3 je tedy skutečně jen připravený hák, ne skrytá funkčnost.
- **Recenze/hodnocení** — v datovém modelu ani v kódu neexistuje žádné pole
  ani systém pro hodnocení receptů → „Nejlépe hodnocené" v sidebaru i
  `aggregateRating` ve schématu musí zůstat vynechány.
- **Reuse** — `atlas_chuti_home_new_recipes()`, `atlas_chuti_home_featured_
  recipe()`, `atlas_chuti_home_magazine_posts()` (`inc/homepage.php`) a
  `template-parts/recipe-card.php` jsou přímo použitelné pro sidebar a
  související obsah beze změny jejich definic.

## B. Co se změnilo

**Layout (desktop 2/3 + sidebar, položka 2 zadání):** nová třída
`.recipe-layout` (`display:grid; grid-template-columns:1fr 320px; gap:
var(--space-10)`) uvnitř stávajícího `.container` (max-width 1220px). Při
gutteru 32 px vychází hlavní sloupec na ~796 px (cíl 780–820 px splněn bez
nutnosti dalšího `max-width`). Kolabuje na jeden sloupec při 1099 px — stejný
breakpoint, jaký web už používá pro ostatní velké dvousloupcové layouty
(`hero-grid`, `continent-grid`), takže se chová konzistentně se zbytkem webu.
Na mobilu je vše jeden sloupec, sidebar pod hlavním obsahem, gutter řízen
existujícím tokenem `--gutter` (20 px / 16 px pod 359 px) — žádná nová
mobilní logika nebyla potřeba, protože `.container` už tento kontrakt
zajišťuje globálně.

**Editorialní úvod (položka 4):** pořadí přesně podle zadání — `<h1>` název
→ `<p class="recipe-original-title">` originální název (nikdy druhý
nadpis) → `.recipe-origin-meta` (vlajka + odkaz na zemi) → `.recipe-perex`
(existující `atlas_excerpt`, beze změny obsahu) → `.recipe-hero-media`
(fotka, poměr stran 16:9, `aspect-ratio` v inline stylu proti CLS). „O
receptu" (`atlas_about`) zůstává samostatná sekce níže v hlavním sloupci —
šablona nic negeneruje ani nepřepisuje, zobrazuje existující (třeba krátký)
obsah tak, jak je.

**Fotka a LCP (položka 5):** `atlas_chuti_media()` (`inc/template-tags.php`)
dostala nový nepovinný parametr `$eager` (default `false`, zpětně
kompatibilní — všech 13 stávajících volání beze změny). Recept ho nastavuje
na `true`, takže hero fotka dostane `loading="eager" fetchpriority="high"`
místo dřívějšího tvrdého `loading="lazy"` — to platí i pro fallback SVG
větev (`atlas_chuti_fallback_image_html()` už uměla `$extra_attrs`
přebíjet výchozí `loading`, jen to dosud nikdo nevyužíval).
`atlas_photo_credit` (existující, dosud nikde nezobrazované pole) se nyní
zobrazuje jako malá poznámka pod fotkou, pouze pokud existuje.

**Praktická metadata a akční lišta (položky 6–7):** metabar zůstal beze
změny obsahu (příprava/vaření/celkem/porce/obtížnost, jen reálná data). Nová
`.recipe-action-bar` v pořadí ze zadání — Oblíbené / Uvařil jsem / Ohodnotit
/ Komentáře / Tisk / Sdílet:
- **Tisk** — nové `data-print-trigger` tlačítko, `assets/js/recipe-
  actions.js` volá `window.print()`.
- **Sdílet** — nové `data-share-trigger` tlačítko: Web Share API
  (`navigator.share`), fallback `navigator.clipboard.writeText` s krátkou
  vizuální odezvou v labelu, a `window.prompt()` jako poslední záchrana bez
  Clipboard API. Žádný SDK, žádné trackování.
- **Uvařil/a jsem** — **beze změny funkčnosti**, jen přestylováno do
  jednotné třídy `.recipe-action-btn` (stejné `data-passport-recipe-toggle`,
  `data-recipe`, `.label`, `.is-active`).
- **Oblíbené / Ohodnotit / Komentáře** — vykresleny jako neaktivní `<span
  aria-disabled="true">` se štítkem „brzy", stejný vzor jako již existující
  „brzy" položky v hlavní navigaci (`nav-link.is-soon`) — konzistentní se
  zavedeným vzorem webu, nikdy neklikatelné, nikdy nepředstírající stav.

**Ingredience a postup (položky 9–10):** stejná data, stejný
`Atlas_Chuti_Servings::get_scalable_ingredients()`, žádná změna dat ani
skupin. Markup přepsán na skutečné sémantické seznamy — `<ul
class="ingredient-list">`/`<li class="ingredient-row">` a `<ol
class="steps-list">`/`<li class="step-row">` — beze změny atributového
kontraktu (`data-ingredient-list`, `data-ingredients`, `data-index`,
`.amount`), takže `servings.js` funguje beze změny (ověřeno, viz sekce E).

**Tipy / varianty / na co si dát pozor (položka 11):** zachovány tři vizuálně
odlišené, ale konzistentní komponenty (sage podklad pro tipy, terakotový
`.callout` pro upozornění, jednoduchý seznam pro varianty) — žádná sekce se
nevykresluje, pokud pro ni nejsou reálná data.

**Sidebar (položka 12):** `.recipe-sidebar` obsahuje po řadě:
1. hák na reklamu (`atlas_chuti_recipe_sidebar_ad`) — bez zavěšeného
   callbacku se nevykreslí vůbec nic (žádný prázdný box);
2. „Nové na Atlasu" — `atlas_chuti_home_new_recipes()`, aktuální recept a
   cokoliv už zobrazené v „Podobné/Další recepty" níže je vyfiltrováno;
3. „Nejlépe hodnocené" — **záměrně vynecháno**, žádná data o hodnocení
   neexistují;
4. „Ochutnejte dnes" — `atlas_chuti_home_featured_recipe()`, s fallbackem na
   jiný náhodný recept, pokud by vyšel stejný jako aktuální recept nebo
   nějaký už zobrazený níže na stránce.

**Obsah pod receptem (položka 13):** místo jedné sekce „Další recepty" nyní
tři nezávislé, samostatně mizející sekce — Podobné recepty (13.1, z
`atlas_related_recipes`, s fallbackem na stejnou zemi), Další recepty z
[země] (13.2, vylučuje vše z 13.1), Přečtěte si (13.4,
`atlas_chuti_home_magazine_posts()`, mizí bez publikovaných příspěvků).
13.3 (tagy) a 13.5 (komunita) jsou jen připravené háky
(`atlas_chuti_hook_slot()`) — bez zavěšeného callbacku nevykreslí nic.
Blok „další recepty od autora" nebyl přidán (autor je vždy jen Atlas chutí,
žádná informační hodnota — přesně jak zadání žádá).

**Tisk (položka 8):** nový `@media print` blok v `main.css`, důsledně
podmíněný na `body.single-atlas_recipe`, aby se chování tisku na žádné jiné
šabloně nezměnilo (dřív `@media print` neexistoval vůbec — jde tedy o čistě
aditivní změnu). Skryje hlavičku, patičku, drobečkovou navigaci, akční
lištu, sidebar, sekce s souvisejícím obsahem a komunitní hák. Nechá
vytištěné: název, originální název, perex, praktická metadata, ingredience,
postup, tipy (pokud existují) — to vše je v DOM už při běžném zobrazení,
tisk jen odstraní okolní chrome. Nově přidán jen `.print-only` blok
(`display:none` na obrazovce, `display:block` při tisku) s názvem webu,
plnou kanonickou URL adresou jako textem a QR kódem vedle sebe — QR tedy
nikdy není jedinou cestou zpět (bod 18 zadání).

**QR kód:** vendorovaná MIT knihovna `kazuhikoarase/qrcode-generator`
(`includes/lib/class-qrcode-vendor.php`) + tenký wrapper
`Atlas_Chuti_QRCode::svg()` (`includes/class-qrcode.php`), oba v pluginu
(datově/URL řízená logika, ne prezentace). Žádná externí služba, žádné
síťové volání za běhu. Podrobná verifikace viz sekce E.

## C. Změněné/nové soubory

- `wp-content/themes/atlas-chuti/single-atlas_recipe.php` — kompletně
  přepsáno (layout, akční lišta, sidebar, související obsah, tiskový blok).
- `wp-content/themes/atlas-chuti/assets/css/main.css` — nové třídy
  (`.recipe-intro`, `.recipe-action-bar`, `.recipe-layout`, `.recipe-
  sidebar`, `.sidebar-recipe-*`, `.sidebar-featured-card`), `@media print`
  blok, sémantické seznamy `.ingredient-list`/`.steps-list` (ul/ol reset).
  Žádná existující třída použitá mimo tento soubor nebyla odstraněna ani
  přejmenována (ověřeno, sekce E).
- `wp-content/themes/atlas-chuti/assets/js/recipe-actions.js` — **nový**,
  tisk + sdílení.
- `wp-content/themes/atlas-chuti/inc/template-tags.php` — `atlas_chuti_
  media()` dostala nepovinný parametr `$eager`; nová `atlas_chuti_hook_
  slot()` (sdílená pomůcka pro reklamu/tagy/komunitu).
- `wp-content/themes/atlas-chuti/functions.php` — enqueue nového skriptu +
  vlastní lokalizační objekt `AtlasChutiShareL10n` (záměrně jiné jméno než
  `AtlasChutiL10n`, aby se nepřepisoval objekt, který na téže šabloně
  zakládá `passport.js`).
- `wp-content/plugins/atlas-chuti-core/includes/class-qrcode.php` — **nový**.
- `wp-content/plugins/atlas-chuti-core/includes/lib/class-qrcode-vendor.php`
  — **nový** (vendorovaná MIT knihovna).
- `wp-content/plugins/atlas-chuti-core/atlas-chuti-core.php` — přidán
  `require` nového `class-qrcode.php`.

## D. Co bylo záměrně vynecháno (mimo rozsah tohoto kroku)

Přesně dle zadání: žádný nový import/úprava dávky 20 zemí/100 receptů,
žádná změna importéru ani schématu, žádné odbourávání/rozšiřování polí,
žádné účty, žádný backend pro Oblíbené/Uvařeno/Hodnocení (Uvařeno zůstává
jen to, co už fungovalo), žádný komentářový systém nad rámec stávajícího
(post type `atlas_recipe` nepodporuje komentáře — ověřeno v
`class-post-types.php`), žádný upload fotek, žádná Diskuze, žádný plný
Magazín (jen náhled ze standardních `post`ů), žádný Cook Mode/časovač/nový
multiplikátor porcí (stávající přepínač zachován), žádný AdSense/GAM,
žádné OpenAI/YouTube API, žádná falešná čísla/hodnocení/počty. Řízený tag
systém (13.3) má jen připravený hák bez viditelné prázdné plochy.

## E. Testy

Bez živého WordPressu/databáze/prohlížeče (tento environment žádný
neposkytuje) — proveden maximální statický test:

1. **`php -l`** na všech změněných/nových PHP souborech a kontrolně na
   celém `wp-content/themes/atlas-chuti/` + `wp-content/plugins/
   atlas-chuti-core/` — bez chyby.
2. **`node --check`** na `recipe-actions.js` — bez chyby.
3. **CSS** — kontrola vyváženosti složených/kulatých závorek v `main.css`
   (487/487, 628/628) — bez chyby.
4. **Funkční statický harness** (`/tmp/…/scratchpad/static_check.php`, do
   repozitáře nezahrnuto) — stubuje WordPress API a **spouští skutečný,
   neupravený `single-atlas_recipe.php`** proti dvěma scénářům (plně
   vyplněný recept vs. minimální/prázdný recept, včetně úmyslně
   kolidujících ID pro test deduplikace) a skutečné `Atlas_Chuti_QRCode::
   svg()`, `Atlas_Chuti_Servings`, `atlas_chuti_hook_slot()` a `template-
   parts/recipe-card.php`. 40/40 kontrol prošlo, mimo jiné:
   - HTML z obou scénářů je bez strukturálních chyb (`DOMDocument` parse,
     libxml).
   - přesně jeden `<h1>`; originální název nikdy není v `<h1>`/`<h2>`;
   - tiskový blok obsahuje přesnou kanonickou URL jako text i jako QR;
   - `atlas_chuti_hook_slot()` skutečně nic nevykreslí bez zavěšeného
     callbacku a skutečně vykreslí obsah, jakmile je něco zavěšeno;
   - všechny prázdné stavy (bez `atlas_about`, bez ingrediencí/postupu, bez
     tipů, bez země, …) korektně skryjí příslušnou sekci;
   - hero fotka je v reálném i fallback případě `eager`+`fetchpriority=
     high`;
   - „Nové na Atlasu" nikdy nenabízí aktuální recept; „Ochutnejte dnes" se
     korektně přesměruje na jiný recept, pokud by kolidoval s aktuálním
     nebo s tím, co je už zobrazené níže na stránce; recept z „Podobné
     recepty" se neopakuje v „Další recepty z…";
   - `data-ingredient-list`/`data-ingredients`/`data-index`/`.amount`
     kontrakt zachován (nyní na `<ul>`/`<li>`), `data-servings-switcher`
     respektuje nestandardní `atlas_servings_default`;
   - přesně jedno `[data-passport-recipe-toggle]` se zachovaným `.label`
     textem „Uvařil/a jsem";
   - Oblíbené/Ohodnotit/Komentáře jsou vykresleny jako neaktivní prvky, ne
     jako klikatelná tlačítka; nikde se nevyskytuje žádné falešné číslo.
5. **QR — end-to-end verifikace přes skutečně vykreslený `<svg>` ze
   scénáře A** (ne jen izolovaně přes vendorovanou knihovnu): `<svg>`
   extrahováno ze skutečného výstupu šablony, převedeno `cairosvg` na PNG
   (400×400, bez prohlížeče) a dekódováno `pyzbar` — **výsledek přesně
   odpovídá kanonické URL** (`https://atlaschuti.cz/entity/100/`).
   Dřívější neúspěšný pokus dekódovat screenshot z headless Chromia byl
   diagnostikován jako artefakt tehdejšího testovacího postupu (ořez
   viewportu při screenshotu), ne chyba v `Atlas_Chuti_QRCode::svg()` —
   stejné, beze změny SVG markup nyní prochází nezávislou dekódovací
   knihovnou bez prohlížeče na 300×300, 400×400 i tiskově realistických
   140×140 px.
6. **Regrese mimo detail receptu** — `grep` přes celý theme potvrdil, že
   třídy `.ingredient-list`, `.steps-list`, `.ingredient-row`, `.step-row`,
   `.recipe-body-grid`, `.meta-bar`, `.callout`, `.variant-row` se
   nepoužívají v žádném jiném souboru než `single-atlas_recipe.php` — jejich
   úprava tedy nemůže ovlivnit žádnou jinou šablonu. Všech 13 stávajících
   volání `atlas_chuti_media()` mimo tento krok zůstává funkčních beze
   změny (nový parametr je nepovinný, na konci signatury).
7. **SEO/schéma** — `class-seo.php` čte metadata přímo, ne z DOM; audit
   (sekce A) potvrdil, že přepis šablony se ho netýká. Ručně ověřeno, že
   žádné `aggregateRating` nebylo přidáno a že šablona neobsahuje druhý
   `<h1>` ani `<h2>` s originálním názvem.

## F. SEO / Discover / schema / GEO — kontrola

- Jeden `<h1>` = název receptu; originální název je `<p>`, nikdy nadpis.
- Logická hierarchie H2 (sekce hlavního sloupce a sidebaru) / H3 (upozornění,
  varianty jednotlivě nejsou nadpisy — jen `<strong>`, karty v sidebaru a v
  „Přečtěte si").
- Kanonická URL, meta title/description, breadcrumb, OpenGraph — beze
  změny, řízeno `class-seo.php`, nezávisle na šabloně.
- Žádné nové JS-only odkazy — všechny odkazy (země, glosář, podobné recepty,
  magazín) jsou standardní `<a href>` bez JS závislosti na vykreslení.
- Recipe JSON-LD beze změny, žádné `aggregateRating`.
- Obrázek — hero fotka má nyní explicitní `aspect-ratio` (CLS), je-li
  reálná i fallback, není agresivně lazy-loaded (LCP fix), OG obrázek
  (`class-seo.php`) beze změny.
- GEO/AIO — jasně oddělené sekce (ingredience/postup/tipy), explicitní
  odkaz na zemi/kuchyni, sémantické `<ul>`/`<ol>`/`<li>`, žádný skrytý text.

## G. Rizika a omezení

- Testováno **staticky**, ne v živém prohlížeči — vizuální ověření layoutu
  (skutečné zalomení na 1099 px, skutečné chování tiskového náhledu v
  reálném prohlížeči) nebylo možné provést a nebylo předstíráno.
- Harness pro statické testy zjednodušuje `tax_query` (chová se jako prostý
  seznam bez reálné filtrace podle taxonomie) — logika PHP šablony
  (výluky/deduplikace) je tím ověřena spolehlivě, ale reálné výsledky
  `WP_Query` s taxonomiemi ověřeny nejsou (na živém WP by je bylo vhodné
  zkontrolovat vizuálně).
- Sidebar bloky `.feed-sidebar-block` mají v základu `position:sticky`;
  v tomto kroku je to přebito inline `position:static`, aby se dva bloky
  pod sebou nepřekrývaly při scrollování — funkční, ale je to řešeno inline
  stylem, ne novou CSS třídou (odpovídá existující konvenci šablony, která
  inline styly na řadě míst používá).

## H. Kroky, které je třeba provést ručně v administraci WordPressu

Žádné.
