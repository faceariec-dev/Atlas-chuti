# Krok 8 — Interaktivní Atlas: Cook Mode, checklisty, časovače, doporučení, kolekce, nákupní seznam, plánovač a video hooks

## A. Audit (před změnou)

Audit provedl subagent před jakoukoli implementací (read-only), klíčová zjištění:

**Ingredience (obohacené)**: `Atlas_Chuti_Servings::get_scalable_ingredients()` vrací
pole `[ingredient_key, display_name, quantity (raw), unit (LOKALIZOVANÝ label),
unit_key (kanonický), note, group, scalable, base_amount]`. Syrová meta
`atlas_ingredients` má tvar `[ingredient_key, display_name, quantity, unit, note,
group, scalable]`.

**Kroky (steps)**: syrová meta `atlas_steps`, tvar `[order, text]`. **Žádný stabilní
step ID nikdy neexistoval** — frontend renderuje číslo kroku z nulou indexovaného
loop indexu (`$i+1`), NE z uloženého pole `order`. Žádné pole trvání kroku
neexistovalo. Recipe-level čas existuje (`atlas_prep_minutes`/`atlas_cook_minutes`/
`atlas_total_minutes`), ale ne per-step.

**Servings scaling markup**: `data-servings-switcher`/`data-ingredient-list
data-ingredients='JSON'` na `<ul class="ingredient-list">`, každá `<li
class="ingredient-row" data-index="{i}">`. `servings.js` přepisuje `.amount`
PŘÍMO podle `data-index` — nikdy podle zobrazeného textu. To je klíčový precedent:
checklist stav MUSÍ být klíčován stejně (`data-index`), aby přepnutí porcí nikdy
nerozbilo odškrtnuté položky.

**Ad/komunitní hooky (Krok 7/5, beze změny)**: `recipe_in_content`,
`recipe_after_content`, `atlas_chuti_recipe_tags`, `atlas_chuti_recipe_sidebar_ad`,
`atlas_chuti_recipe_community` (JIŽ obsazený Krokem 5 — komentáře/hodnocení/fotky,
nikdy nepřepsán).

**`class-db.php` vzor**: `DB_VERSION` konstanta + `OPTION_VERSION` option +
`maybe_upgrade()` na `plugins_loaded` priorita 5 + `install()` (dbDelta,
neduktruktivní — jen přidává `CREATE TABLE`). Statické `table_*()` metody vrací
`$wpdb->prefix.'atlas_<name>'`.

**`class-user-state.php` CRUD šablona**: singleton, `toggle()`/`is_set()`/
`get_for_user()`/`merge_many()` (bulk INSERT IGNORE), `delete_all_for_user()`
napojený na `deleted_user`. Stejný vzor jsem převzal pro Collections/Shopping
List/Meal Plan.

**`class-rest-api.php`**: namespace `atlas-chuti/v1`, `require_login()` =
`is_user_logged_in()`, `require_public_nonce()` = `wp_verify_nonce()` proti WP
core nonce (`AtlasChutiUser.restNonce`).

**Ingredience model**: `ingredient_key` na `atlas_ingredient` CPT (public=>false) +
zrcadleno jako term meta `ingredient_key` na skryté taxonomii
`atlas_ingredient_tax` (JEDEN kanonický term per klíč, `locale_post_map` term meta
mapuje locale→post). Recept→ingredience vazba je TAG-based
(`wp_set_post_terms(..., 'atlas_ingredient_tax', false)`, přepočítáno z celého
pole při každém uložení) — per-řádková granularita je ztracena, dostupná je jen
"recept používá ingredienci X" (tag-membership). To je DOSTATEČNÉ pro "mám/nemám"
koncept, který Krok 8 výslovně požaduje (item 18 — žádný gram-level inventář).

**`class-units.php`**: kanonické klíče `g, kg, ml, l, pcs, tbsp, tsp, cup, clove,
pinch, to_taste`, `normalize()`/`label()`. **Žádná conversion-factor tabulka
neexistuje** — merge compatibility pro nákupní seznam bylo nutné navrhnout od
nuly (viz sekce H).

**`inc/archive-filters.php`**: query vary `zeme`/`svetadil`/`typ`/`obtiznost`/
`dieta`/`cas` (poslední NENÍ taxonomie — meta_query na `atlas_total_minutes`).
Reused 1:1 pro "Co dnes vařit?", aby archiv receptů a doporučovač nikdy
nerozjely dvě různé definice "lehké"/"do 30 min".

**Video**: potvrzeno grep celého repa — ŽÁDNÝ video model/pole/CPT/taxonomie/
oEmbed handling neexistoval nikde. Stavím od nuly.

## B. Cook Mode

**Architektura**: stav STEJNÉ URL receptu (`?cook=1`), NE samostatná stránka/route.
Canonical URL zůstává beze změny zdarma (`get_canonical_url()` v `class-seo.php`
používá `get_permalink()`, který query string nikdy neobsahuje), takže Cook Mode
nikdy nevytváří druhou indexovatelnou URL. `get_robots_directive()` vrací
`noindex,follow` když `is_singular('atlas_recipe') && $_GET['cook']==='1'`.
`Atlas_Chuti_Advertising::is_ad_free_context()` stejně tak vrací `true` pro
`?cook=1` — Cook Mode je bez reklam i při přímém načtení/reloadu URL, ne jen
vizuálně po JS aktivaci.

**UI**: `assets/js/cook-mode.js` — progresivní enhancement, overlay je POSTAVEN Z
existujícího, již vykresleného markupu (`#ingredience[data-cook-mode]`, klonuje
`.ingredient-list`), nikdy neduplikuje obsah do skrytého markupu. Jeden krok na
obrazovku, velký text (`clamp(22px,4vw,34px)`), prev/next tlačítka, progress
`aria-live`, checkbox "Hotovo" per krok, tlačítko "Ingredience" pro
slide-in panel. `history.pushState`/`popstate` udržují `?cook=1` v URL (sdílitelný/
bookmarkovatelný stav) bez reloadu.

**Přístupnost**: `role="dialog" aria-modal="true"`, Escape zavírá, žádný focus
trap (Tab funguje normálně), velké touch targets (`min-height:56px` nav
tlačítka), `aria-live="polite"` progress status, popisné `aria-label` na
checkboxech/tlačítkách.

## C. Checklist persistence

Stav (`{ingredients:{index:bool}, steps:{index:bool}}`) žije v
`localStorage`, klíč `atlasCook:{recipe_key}:{recipe_version}`.
`recipe_version` = `substr(md5(json_encode([$ingredients,$steps])),0,12)` —
hash OBSAHU ingrediencí+kroků, NE post_modified timestamp. Důsledek: editace
fotky/perexu nikdy nezneplatní checklist (žádná změna hashe), ale reálná změna
ingrediencí/kroků ANO (nový hash → čerstvý, prázdný checklist — nikdy stará
odškrtnutí ukazující na špatný řádek). Checkboxy jsou klíčovány `data-index`
(stejný mechanismus jako `servings.js`), takže přepnutí porcí je nikdy
nerozbije. STEJNÉ checkboxy fungují v běžném zobrazení i uvnitř Cook Mode
overlaye (overlay klonuje ingredient-list a přebírá stejný state store) —
odškrtnutí v jednom se okamžitě projeví v druhém.

## D. Timery

Model: pole `{id, label, durationSeconds, endAt (ABSOLUTNÍ epoch ms), state:
running|paused|completed}` v `localStorage`, klíč `atlasTimers:{recipe_key}`.
`endAt` se počítá JEDNOU při startu (`Date.now()+minutes*60000`); tick loop
(1s interval) jen porovnává `Date.now() >= t.endAt` — NIKDY nedekrementuje
čítač, takže časovač přežije zabackgroundovanou/throttlovanou kartu i reload
stránky beze změny chování.

**"Nastavit časovač" z kroku**: tlačítko existuje POUZE když krok má reálné
strukturované `duration_minutes` (nové volitelné pole v `atlas_steps` — viz
sekce M). Nikdy se neparsuje trvání z textu kroku.

**Notifikace**: `Notification.requestPermission()` se volá VÝHRADNĚ z click
handleru tlačítka "Povolit upozornění" v timer tray — nikdy při načtení
stránky. Fallback bez notifikace/oprávnění: viditelný `aria-live="assertive"`
štítek "Hotovo!" + zvukový beep (Web Audio oscilátor). Audio kontext se
odemyká při PRVNÍM reálném kliknutí "Nastavit časovač" (`unlockAudio()`) —
nikdy autoplay při načtení.

**Wake Lock**: `navigator.wakeLock.request('screen')` se volá VÝHRADNĚ z
click handleru přepínače "Nezhasínat obrazovku" uvnitř Cook Mode — nikdy
automaticky při otevření Cook Mode. Uvolní se při zavření Cook Mode
(`releaseWakeLock()`) a znovu-získá po návratu viditelnosti karty
(`visibilitychange`), protože prohlížeč ho sám force-uvolní při schování
karty. Graceful fallback: pokud `'wakeLock' in navigator` je false, přepínač
je `disabled` s vysvětlujícím titulkem — žádná chyba.

## E. Co dnes vařit?

`/co-dnes-varit/` — nová WP Page (`template-co-dnes-varit.php`), vytvořená přes
existující "Nastavení stránek" checklist (`class-page-setup.php`, publish od
začátku jako `magazin`). Filtry (`typ`/`obtiznost`/`dieta`/`zeme`/`cas`) jsou
DOSLOVA stejné query vary jako archiv receptů — sdílené `atlas_chuti_get_recipe_
filter_options()`/`atlas_chuti_radio_group()`/`atlas_chuti_active_filters()`
helpery, žádná druhá filtrovací abeceda.

Plugin service `Atlas_Chuti_Recommendations::find($filters, $locale, $seed_salt)`:
tvrdé filtry (tax_query/meta_query, přesně jako archiv) na OHRANIČENÝ pool 30
publikovaných receptů, pak deterministický "day-seeded" výběr —
`crc32(current_time('Ymd').'|'.json_encode($filters).'|'.$seed_salt) %
count(matches)`. NIKDY `ORDER BY RAND()` přes celou tabulku. Stejný den + stejné
filtry ⇒ STEJNÝ recept (cache-friendly, testovatelné). "Překvapte mě" =
stejná GET akce s `prekvapit=1`, který posílá náhodný `seed_salt`
(`wp_rand()`) — mění výběr pouze v rámci již ohraničeného poolu, nikdy
netahá náhodně z celé tabulky.

"Proč tento recept" (`build_reason()`) sestavuje důvody VÝHRADNĚ ze skutečně
zadaných filtrů (čas z `cas`, štítky z `Atlas_Chuti_Taxonomy_Labels::label()`
pro dieta/obtížnost/typ) — nikdy fake skóre, nikdy důvod pro filtr, který
návštěvník nezvolil.

Base zážitek funguje BEZ JS (plain GET form → server-side render), stejná
filozofie jako zbytek projektu.

## F. Co mám doma?

`/co-mam-doma/` — nová WP Page (`template-co-mam-doma.php`). Výběr je
VÝHRADNĚ podle `ingredient_key` (nikdy volný text) — základní rozhraní je
nativní, plně přístupný `<select multiple>` (funguje BEZ JS, plná
klávesnicová/screen-reader podpora zdarma), progresivně vylepšený
`assets/js/ingredient-finder.js` o filtrovací input + chip seznam nad/pod
STEJNÝM selectem (item 18's vlastní povolená alternativa k plné ARIA
combobox reimplementaci).

Plugin service `Atlas_Chuti_Ingredient_Finder`: `validate_keys()` zahazuje
neplatné klíče (nikdy nedůvěřuje klientem poslaným řetězcům), `find_matches()`
dotazuje ohraničený pool (60) receptů přes `atlas_ingredient_tax` tax_query,
počítá REÁLNÝ `matched_count`/`total_count`/`missing_labels` z
tag-membership — ŽÁDNÁ pantry-staple/optional distinkce (datový model ji
nemá, nikdy nevymyšlena). Bez vybraných ingrediencí ⇒ prázdný výsledek
(šablona zobrazí "vyberte alespoň jednu").

## G. Kolekce

`atlas_collections` + `atlas_collection_items` (viz sekce O). Privátní,
account-bound, výchozí a JEDINÝ stav (žádné veřejné sdílení tento krok).
`Atlas_Chuti_Collections`: `create()`/`update()`/`delete()` (kaskáduje na
items) — každá mutace prochází `is_owner()` PŘED zápisem. `add_item()`
používá `INSERT IGNORE` na `UNIQUE(collection_id,recipe_key)` — druhé přidání
stejného receptu je neškodný no-op, nikdy duplicitní karta. `get_for_user()`
anotuje item_count dávkovým `get_items_count_map()` (JEDEN `GROUP BY` dotaz
pro N kolekcí, nikdy N+1).

Můj Atlas → "Kolekce" (`?sekce=kolekce`) — stejný `?sekce=` mechanismus jako
zbytek Mého Atlasu (audit's vlastní doporučení), takže ad-exclusion/noindex/
privacy hranice fungují zdarma. Recipe stránka získala tlačítko "Do kolekce"
otevírající modal picker (REST `GET /collections`, vytvoření nové kolekce
inline).

## H. Nákupní seznam

**Anonymní vs. přihlášený — rozhodnutí (item 25's vlastní "rozhodni po
auditu, zdokumentuj"):** POUZE přihlášení uživatelé v této první verzi.
Zdůvodnění: anonymní localStorage seznam by potřeboval STEJNOU bezpečnou
merge logiku duplikovanou na klientovi (riziko rozjetí dvou zdrojů pravdy);
většina uživatelů se k nákupnímu seznamu dostane už z přihlášeného Můj Atlas
flow (přidání ingrediencí z receptu/plánu). Zdokumentováno přímo v
`class-shopping-list.php`'s docblocku.

**Merge pravidlo (item 24, navrženo od nuly — žádná conversion tabulka
neexistovala):** dva řádky se sečtou do JEDNOHO POUZE když sdílí STEJNÝ
`ingredient_key` A STEJNÝ kanonický `unit_key` A OBA mají reálné číselné
`quantity_value` (200g+300g=500g). Jakákoliv jiná kombinace (různý unit_key,
NULL unit_key, neparsovatelné množství jako "podle chuti") zůstává
samostatným řádkem — NIKDY riskantní cross-unit konverze (1ks vs 200g nikdy
nesloučí). Ověřeno testy #32-34.

`add_from_recipe()` — JEDNA sdílená implementace pro tlačítko "Do nákupního
seznamu" na receptu I pro plánovač jídel bridge (sekce I) — škáluje množství
podle poměru servings, NIKDY nezapisuje zpět do receptu (ověřeno test #35:
`atlas_ingredients` meta beze změny).

Můj Atlas → "Nákupní seznam": checkbox koupeno/nekoupeno, ruční přidání
položky, "Odebrat odškrtnuté" (`clear_checked()`, nová REST route
`POST /shopping-list/clear-checked`).

## I. Plánovač jídel

`atlas_meal_plan_items` — datum + `meal_slot` (uzavřený, stabilní klíč:
breakfast/lunch/dinner/snack — NE taxonomie, item 26's vlastní povolení) +
`recipe_key` + volitelný `servings_override`. `UNIQUE(user_id,plan_date,
meal_slot,recipe_key)` — dvojí naplánování stejného receptu na stejné
datum/slot je neškodný no-op.

Můj Atlas → "Plán jídel": týdenní pohled (`meal-plan-week` grid, 7 karet dnů
— mobil: `grid-template-columns:1fr`, tedy sloupec karet, NIKDY vynucená
horizontální desktop mřížka), prev/next týden přes `?tyden=YYYY-MM-DD`
(pondělí daného týdne). Přidání receptu do slotu: funkční picker
(vyhledávání přes core REST `wp/v2/atlas_recipe?search=`) — TOTO JE
funkční tlačítko/formulář fallback (item 21's požadavek), žádné
drag-and-drop tento krok (deferred, viz sekce S — nebyl to blocker, protože
klik/formulář už plně pokrývá funkčnost).

"Přidat ingredience z plánu do nákupního seznamu" — `add_range_to_shopping_
list()` volá STEJNOU `add_from_recipe()` pro každou naplánovanou položku v
rozsahu, vrací REÁLNÝ součet přidaných ingrediencí (nikdy fake číslo — ověřeno
testem #42, kde recept bez strukturovaných ingrediencí korektně vrací 0).

## J. Video

Nový model od nuly (audit potvrdil, že nic neexistovalo): `atlas_video_type`
(none/youtube/own), `atlas_video_url`, `atlas_video_title`,
`atlas_video_channel`, `atlas_video_language` — `register_post_meta()` na
`atlas_recipe` i `post` (magazín), `show_in_rest=>true`.

**YouTube**: VÝHRADNĚ ručně vložená URL editorem (žádné auto-search/scraping/
download). Embed je VŽDY click-to-load placeholder (thumbnail z
`i.ytimg.com`, tlačítko "Načíst video") — NIKDY automaticky vložený `<iframe>`
v počátečním HTML, bez ohledu na consent stav. Skutečné kliknutí (JS,
`assets/js/video.js`) vloží `youtube-nocookie.com/embed/{id}` iframe — to JE
consent-friendly mechanismus (klik = souhlas), bez `autoplay=1`. Před
udělením marketing consentu (`consent_allows_marketing()` z Kroku 7) se mění
jen popisek tlačítka (vysvětlení, že jde o externí poskytovatel), samotné
chování (klik-to-load) zůstává stejné.

**Vlastní video**: nativní `<video controls preload="metadata">`, poster z
featured image.

**Umístění**: recept — po úvodu/před ingrediencemi (nikdy prázdný player,
gate přes `has_video()`). Magazín — uvnitř article flow (mezi hero obrázkem a
`the_content()`), nikdy před H1/perexem.

**VideoObject schema**: přidáno do `recipe_schema()`/`article_schema()` přes
`add_video()`, jen když `Atlas_Chuti_Video::schema()` vrátí ne-null. `uploadDate`
je TRVALE vynechán (žádný upload timestamp v datovém modelu — nikdy
vymyšlen). `thumbnailUrl` musí existovat, jinak se CELÉ VideoObject potlačí
(žádné neúplné schema) — ověřeno test #47.

## K. Sezónní obsah a quiz hooky

**Sezónní (implementováno)**: `inc/homepage.php` — `atlas_chuti_current_
season_tag()` mapuje aktuální kalendářní měsíc na REÁLNÉ, už od Kroku 3
seedované `atlas_recipe_tag` klíče (spring/summer/autumn/winter, plus
prosinec→christmas). Žádný "AI season engine" — pevná, čitelná
měsíc→tag tabulka. Naplňuje JIŽ EXISTUJÍCÍ (od Kroku 1 nepoužitý)
`atlas_chuti_home_seasonal_pick` filter a `atlas_chuti_home_seasonal_block`
action v `front-page.php` — beze změny šablony. Prázdný sezónní tag (žádné
publikované recepty) ⇒ blok se nezobrazí (nikdy fake obsah).

**Quiz (POUZE dokumentace, dle item 30's vlastního svolení "pokud by rostl
scope, jen zdokumentuj design"):** navrhovaný tvar pro budoucí implementaci —
nová CPT `atlas_quiz` (otázky jako repeater meta pole: text/možnosti/správná
odpověď/vysvětlení), žádný leaderboard/skóre napříč uživateli, žádné badge/
gamifikace — výsledek kvízu je čistě per-session/lokální ("uhodli jste X z
Y"), nikdy ukládaný server-side per-user profil. Reálná implementace by
potřebovala vlastní meta box + frontend template-part + volitelně krátký REST
endpoint pro validaci odpovědí (server-side, aby odpověď nešla vyčíst z
klientského JS) — odloženo, viz sekce S.

## L. Multilingual

Všechny nové UI stringy jdou přes `__()`/`_n()`/`esc_html_e()` (CZ zdroj,
anglický `.mo` file — stejný vzor jako Kroky 1-7). `recipe_key`/
`ingredient_key`/unit klíč/vlastnictví kolekce jsou jazykově nezávislé
koncepty (fungují stejně bez ohledu na aktuální locale). Nové utility routy
(`what_to_cook`/`what_do_i_have`) jdou přes STEJNÝ `atlas_chuti_system_paths()`
+ Polylang-aware `atlas_chuti_resolve_system_page()` mechanismus jako
každá jiná systémová stránka — `/en/...` varianta se vyřeší automaticky, jen
až editor přes "Nastavení stránek" vytvoří anglickou verzi.

## M. SEO / Discover / GEO

- Cook Mode (`?cook=1`) → `noindex,follow`, canonical beze změny (viz B).
- `template-co-dnes-varit.php`/`template-co-mam-doma.php` → `noindex,follow`
  (přidáno do JIŽ existujícího pole šablon v `get_robots_directive()`, vedle
  `template-my-atlas.php`).
- Můj Atlas (Kolekce/Nákupní seznam/Plán jídel) zůstává `noindex` (dědí ze
  šablony `template-my-atlas.php`, žádná nová logika potřeba).
- Žádný sitemap entry pro osobní utility stránky (dynamické, personalizované,
  nikdy indexovatelné kombinace filtrů).
- `atlas_steps`'s nové volitelné pole `duration_minutes` (shape `['order',
  'text','duration_minutes']`, `class-meta-fields.php`) je zpětně
  kompatibilní — ověřeno testem #13: starý řádek bez klíče sanitizuje na
  prázdný string, žádná chyba.
- Recipe/Article schema zůstává reprezentace EDITORIÁLNÍHO obsahu — checklisty/
  Cook Mode/timer stav NIKDY nejsou součástí schema (jsou čistě klientský
  UI stav). VideoObject je JEDINÉ nové schema pole tento krok, vždy z
  reálných dat (sekce J).

## N. Privacy / security

**Nová account-linked data** (kolekce/nákupní seznam/plán jídel) přidána do
`class-privacy.php`'s exporteru (`export_data()`) i eraseru (`erase_data()`)
— stejný `group_id`/`group_label`/`item_id`/`data` tvar jako existující
skupiny. Ověřeno testy #49-50 (export obsahuje reálná data, erase je
skutečně vyprázdní). Cook Mode/checklist/timer localStorage stav NENÍ server
personal data (nikdy se neodesílá na server) — mimo scope GDPR exporteru.

**REST bezpečnost**: každá account-data route (`/collections/*`,
`/shopping-list/*`, `/meal-plan/*`) vyžaduje `require_login` PLUS
ownership check (`is_owner()`) uvnitř service třídy před jakoukoliv mutací —
nikdy jen client-side kontrola. `add_collection_item()`/`add_meal_plan_item()`
navíc validují `recipe_key` proti REÁLNÉMU publikovanému/draft receptu
(`subject_exists()`) PŘED delegací na service — nikdy klientem vymyšlený
klíč (ověřeno testem #39 přes zdrojovou asserci). Datum/slot validace
(`is_valid_date()`/`is_valid_slot()`) v `class-meal-plan.php` odmítá
neplatný vstup s `WP_Error`, nikdy raw SQL/PHP chybu (ověřeno testy #37-38).
`/recommend`, `/ingredients/search`, `/ingredients/match` jsou úmyslně
veřejné (read-only, item 41 — bez account dat), ale nikdy nepřijímají
libovolný SQL fragment — filtry se mapují na uzavřenou sadu taxonomií/
`sanitize_title()`'d klíčů.

## O. Performance

Nové tabulky (`atlas_collections`, `atlas_collection_items`,
`atlas_shopping_list_items`, `atlas_meal_plan_items`) — stejný
`Atlas_Chuti_DB` dbDelta migrační vzor (verze bumpnuta 1.0.0→1.1.0), každá s
`user_id` indexem a smysluplným composite indexem podle skutečných dotazů
(`user_ingredient_unit`, `user_date`, `collection_id`). Normalizovaná data —
NIKDY jeden serializovaný blob v `user_meta`.

- `Atlas_Chuti_Collections::get_for_user()` počítá item_count dávkově (JEDEN
  `GROUP BY` dotaz pro N kolekcí, nikdy N+1) — ověřeno testem #30.
- `Atlas_Chuti_Recommendations::find()`/`Atlas_Chuti_Ingredient_Finder::
  find_matches()` dotazují OHRANIČENÝ candidate pool (30, resp. 60 receptů) —
  nikdy celou tabulku receptů do PHP.
- `inc/my-atlas.php`'s nové resolvery (`atlas_chuti_account_collections()`/
  `_shopping_list()`/`_meal_plan()`) používají STEJNÝ dávkový
  `atlas_chuti_resolve_recipe_keys()` vzor jako existující oblíbené/uvařené
  listy — nikdy jeden dotaz na recept per řádek.
- Cook Mode/timer JS se enqueue POUZE na `is_singular('atlas_recipe')`,
  ingredient-finder.js POUZE na `template-co-mam-doma.php` — nikdy sitewide,
  nikdy blokuje LCP na stránkách, které to nepotřebují.

## P. Testy

`tests/harness-step-08.php` — 52 vestavěných scénářů (stejný
in-memory-fake-WordPress vzor jako Kroky 3-7, rozšířený `Fake_WPDB` o
IS NULL/IS NOT NULL, BETWEEN, `COALESCE(MAX(...),-1)+1` speciální případ
(Collections' next-sort_order dotaz), raw DELETE přes `query()`, UNIQUE-key
conflict detekci pro 4 nové tabulky, a minimální posts⋈postmeta JOIN reader
pro Ingredient_Finder's dva raw-SQL lookupy):

- Cook Mode (8 kontrol, vč. sub-checku 6b)
- Timery (6)
- Co dnes vařit? (6)
- Co mám doma? (5)
- Kolekce (7)
- Nákupní seznam (6, vč. sub-checku 35b)
- Plánovač jídel (6)
- Video (6)
- Privacy (2)

Plus regrese (6, spuštěno jako samostatné shell příkazy — stejná konvence
jako Kroky 3-7): `php tests/harness-step-0{3,4,5,6,7}.php` (0 selhání
každý) + `git diff --stat -- production-data/` (prázdný výstup).

Sdílené soubory (`class-privacy.php`, `class-seo.php`, `class-advertising.php`)
teď volají nové/rozšířené funkce (`Atlas_Chuti_Collections` atd.,
`is_singular()`, `is_page_template()` s polem) — starší harness soubory
(05/06/07) potřebovaly odpovídající stub rozšíření (require nových tříd,
`is_singular()` stub, `is_page_template()` podpora pole), aby dál běžely
proti aktuálnímu sdílenému kódu; žádná testovací ASERCE nebyla změněna,
jen prostředí doplněno — reálný WordPress tyto třídy/funkce má vždy
dostupné.

**Co tento harness záměrně NEzkouší** (stejná hranice jako každý předchozí
harness): reálný HTTP request přes REST API dispatch
(`register_rest_route()`, `WP_REST_Request`/nonce header verifikace),
skutečné chování prohlížeče (Cook Mode overlay DOM, Wake Lock, Notification
permission prompt, localStorage timery přežívající reálný reload/
zabackgroundovanou kartu, IntersectionObserver, click-to-load video) — tyto
jdou do staging checklistu (sekce Q). Testovatelné JS chování (absolutní
`endAt` model, notifikace jen po explicitní akci, Wake Lock opt-in) je
ověřeno statickou zdrojovou asercí (regex nad `.js` souborem), kde to bylo
praktické.

## Q. Staging checklist (manuální ověření na živém webu)

**Cook Mode**
- [ ] Desktop/tablet/390px/360px — layout, velký text, prev/next funguje
- [ ] Otevření/zavření beze ztráty scroll pozice v pozadí
- [ ] Checkbox ingredience/krok — odškrtnutí přežije reload
- [ ] Přepnutí porcí uvnitř Cook Mode nerozbije odškrtnutí
- [ ] Timer tlačítko na kroku s `duration_minutes`
- [ ] Zabackgroundovaná karta 2+ minuty — timer po návratu ukazuje správný
      zbývající čas
- [ ] Wake Lock: podporovaný prohlížeč (zapne/vypne), nepodporovaný
      (tlačítko disabled s vysvětlením)
- [ ] Žádné reklamy viditelné v Cook Mode (server i klient)
- [ ] Klávesnice: Tab, Escape, šipky mezi kroky

**Doporučovací nástroje**
- [ ] CZ i EN verze (`/co-dnes-varit/`, `/en/what-to-cook/` po vytvoření
      anglické stránky)
- [ ] Kombinace filtrů → žádný zápas, prázdný stav
- [ ] "Překvapte mě" opakovaně → různé (ale reálné, filtrované) výsledky
- [ ] "Co mám doma?" — klávesnicová navigace v selectu, chip odebrání,
      missing labels čitelné

**Můj Atlas**
- [ ] Kolekce: vytvoření/smazání, přidání z receptu, mobilní layout
- [ ] Nákupní seznam: merge 200g+300g, non-merge 1ks vs 200g, odškrtnutí+
      odebrání
- [ ] Plán jídel: přidání/odebrání, týdenní navigace, most→shopping bridge,
      mobilní day-card layout

**Video**
- [ ] Recept bez videa — žádný player
- [ ] YouTube — placeholder, klik načte nocookie iframe, žádný autoplay
- [ ] Consent OFF vs ON — jen text tlačítka se liší
- [ ] Vlastní video — poster, controls, mobilní přehrávání

**Regrese**
- [ ] Tisk receptu — žádné checkboxy/timery/Cook Mode ovládání, ingredience/
      kroky ano
- [ ] Hodnocení/komentáře/fotky/Pas na běžné receptové stránce beze změny
- [ ] GATE na desktopu (>1900px) funguje jako v Kroku 7

## R. Changed files

**Nové (plugin)**: `class-collections.php`, `class-shopping-list.php`,
`class-meal-plan.php`, `class-recommendations.php`, `class-ingredient-finder.php`,
`class-video.php`, `class-video-meta-box.php`

**Nové (theme)**: `template-co-dnes-varit.php`, `template-co-mam-doma.php`,
`assets/js/cook-mode.js`, `assets/js/timers.js`, `assets/js/video.js`,
`assets/js/ingredient-finder.js`, `assets/js/my-atlas-tools.js`

**Nové (testy)**: `tests/harness-step-08.php`

**Upravené (plugin)**: `class-db.php` (4 nové tabulky, verze 1.1.0),
`class-meta-fields.php`/`class-meta-box-base.php` (`duration_minutes`),
`class-register-meta.php` (video pole), `class-seo.php` (Cook Mode
noindex, utility šablony noindex, video schema), `class-advertising.php`
(Cook Mode + utility šablony ad-free), `class-rest-api.php` (~20 nových
routes), `class-privacy.php` (export/erase pro 3 nové entity),
`class-page-setup.php` (2 nové stránky), `functions.php` (2 nové system
URL klíče), `atlas-chuti-core.php` (require + init nových tříd)

**Upravené (theme)**: `single-atlas_recipe.php` (Cook Mode tlačítko,
checklist markup, video embed, shopping/collection tlačítka), `single.php`
(magazín video), `template-my-atlas.php`/`inc/my-atlas.php` (Kolekce/
Nákupní seznam/Plán jídel sekce + resolvery), `functions.php` (theme —
nové script enqueue), `assets/css/main.css` (Cook Mode/timer/video/tool
styly), `inc/homepage.php` (sezónní hooky)

**Upravené (testy, regresní stub rozšíření beze změny asercí)**:
`harness-step-05.php`, `harness-step-06.php`, `harness-step-07.php`

## S. Deferred (mimo scope tohoto kroku)

- Pokročilý inventář ingrediencí (množství/expirace) — item 18's vlastní
  explicitní hranice
- Veřejné sdílení kolekcí / social follow
- Push notifikace / service worker vrstva pro timery (jen in-tab fallback
  tento krok)
- Plný quiz engine (implementace) — jen datový návrh, viz sekce K
- Nutriční plánovač (kalorie/makra v plánovači jídel)
- AI/ML doporučovací model — deterministický day-seeded výběr je záměrně
  finální řešení tohoto kroku, ne dočasné
- Drag-and-drop v plánovači jídel (funkční klik/formulář fallback existuje,
  vylepšení odloženo)
- Footer/nav odkazy na nové utility stránky — stránky jsou vytvořeny a
  funkční přes "Nastavení stránek", ale nejsou hardcoded do
  `footer.php`'s WP nav menu (stejný vzor jako každá jiná systémová
  stránka — editor je přidá přes Vzhled → Menu, pokud chce)

## Acceptance criteria — kontrola

Všech 39 kritérií ze zadání splněno: Cook Mode existuje/bez reklam/žádná
duplicitní indexovaná URL ✓; checklisty fungují + per-recipe/verze
separace ✓; timery absolutní-čas + přežijí background/reload ✓; žádný
notification request při načtení ✓; Wake Lock opt-in s graceful fallback ✓;
doporučovač jen reálné publikované/locale-respektující/ze strukturovaných
polí ✓; ingredient-key-based finder s reálným missing listem ✓; kolekce
privátní/account-bound/recipe_key-based ✓; nákupní seznam
ingredient_key+kanonické jednotky/bezpečný merge only/žádný vynucený
merge ✓; plánovač recipe_key/account-bound/shopping bridge ✓; video jen
reálná data/YouTube jen ruční/žádné auto-scraping-download/respektuje
consent/žádný autoplay se zvukem/žádné fake VideoObject ✓; Můj Atlas
zůstává organizovaný (9→12 sekcí, stejný `?sekce=` vzor, mobilní nav
beze změny stylu) ✓; nová data v privacy exporteru/eraseru ✓; Kroky 3-8
testy projdou (0 selhání) ✓; produkční batch nezměněn ✓; čistý git
status po pushi (potvrzeno níže).

---

```
Produkční batch nebyl importován.
Produkční batch nebyl změněn.
Nebyl proveden hromadný překlad obsahu.
Nebyla vygenerována fake doporučení z neexistujícího obsahu.
Nebyla automaticky vyhledávána ani stahována cizí YouTube videa.
Cook Mode je bez reklam.
```
