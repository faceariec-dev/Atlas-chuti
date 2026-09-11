# Atlas chutí — WordPress

Vlastní WordPress projekt "Atlas chutí" postavený od nuly: moderní kulinářský
atlas kombinující profily zemí, strukturované recepty, kuchařský slovníček a
"Kulinářský pas". Žádný page builder, žádná placená závislost, běží na běžném
sdíleném PHP hostingu (např. FORPSI Easy).

Vizuální reference: `atlas_chuti_claude_design.zip` (Claude Design export) —
theme z něj přebírá barevnou paletu (teplé světlé pozadí, terakotový akcent),
typografii (Newsreader + Work Sans) a charakter karet/zaoblení, ale je to čistý
WordPress theme + plugin, ne export designu.

## Architektura

```
wp-content/
  themes/atlas-chuti/        Frontend: šablony, CSS, JS, responzivita, komponenty.
  plugins/atlas-chuti-core/  Datový model: CPT, taxonomie, meta pole, vazby,
                              import, Kulinářský pas, budoucí integrační rozhraní.
schema/                      JSON Schema pro import (recipe/country/glossary/ingredient).
sample-data/                 Ukázková a testovací data.
```

Theme řeší výhradně prezentaci; **datový model je celý v pluginu** a theme na
něj jen čte přes hotové funkce (`atlas_chuti_*`, `Atlas_Chuti_*`) — theme lze
v budoucnu vyměnit beze ztráty obsahu. Žádný Elementor, ACF Pro, Next.js jako
runtime, povinný Node server, Redis, Docker ani VPS — jen WordPress, PHP,
MySQL/MariaDB a nezbytný vanilla JS.

## Požadavky

- PHP 7.4+ (testováno na 8.x)
- WordPress 6.x
- MySQL/MariaDB
- WP-CLI (volitelné, pro `wp atlas import`)

## Instalace

1. Zkopírujte `wp-content/themes/atlas-chuti` a
   `wp-content/plugins/atlas-chuti-core` do odpovídajících složek běžné
   instalace WordPressu.
2. V administraci aktivujte plugin **Atlas chutí – Core**
   (Pluginy → Aktivovat). Zaregistrují se obsahové typy, taxonomie, výchozí
   termy (6 světadílů, 3 úrovně obtížnosti, 2 diety, 4 kategorie slovníčku) a
   všechna meta pole přes `register_post_meta()`.
3. Aktivujte theme **Atlas chutí** (Vzhled → Motivy).
4. V Nastavení → Trvalé odkazy klikněte "Uložit změny".
5. Otevřete **Atlas chutí → Nastavení stránek** a klikněte "Vytvořit chybějící
   stránky" — vytvoří (jako koncepty) Zemi, Kulinářský pas a základní obecné
   stránky se správnými slugy/šablonami; opakované spuštění nic
   nezduplikuje. Recepty a Slovníček jsou CPT archivy, žádnou stránku
   nepotřebují — nástroj to jen potvrdí.
6. V Vzhled → Menu vytvořte hlavní menu (Země / Recepty / Kuchařský
   slovníček / Kulinářský pas) a přiřaďte pozici "Hlavní menu". Bez menu
   header použije stejné čtyři odkazy automaticky.
7. Volitelně: Vzhled → Přizpůsobit → **Atlas chutí – Homepage** pro hero
   fotografii, a Příspěvky → Světadíly pro fotografii každého světadílu.
8. Nahrajte testovací data (viz níže).

## Datový model

| CPT               | Účel                                               | Veřejné |
|-------------------|-----------------------------------------------------|---------|
| `atlas_recipe`    | Recept                                              | ano (`/recepty/…`) |
| `atlas_country`   | Země / gastronomická destinace                      | ano (`/zeme/…`) |
| `atlas_glossary`  | Pojem kuchařského slovníčku                         | ano (`/slovnicek/…`) |
| `atlas_ingredient`| Normalizovaná ingredience ("rajče"="rajčata"), identita = `atlas_ingredient_key` (např. `tomato`), ne slug | ne (interní) |

Taxonomie — **veřejná je jen** `atlas_continent` (vlastní landing page,
`taxonomy-atlas_continent.php`). Všechny ostatní jsou technické
(`public: false`, žádný vlastní rewrite/archiv) — existují jen kvůli rychlému
`tax_query` filtrování a vyhledávání, ne jako indexovatelné stránky duplikující
`/zeme/{slug}/` nebo archiv `/recepty/?obtiznost=…`:

- `atlas_country_tax` — "zrcadlo" CPT `atlas_country`, navěšeno na
  recepty/slovníček. Spravuje se automaticky při uložení Země
  (`class-country-sync.php`) — v adminu se needituje přímo. **Jeden
  kanonický term na ISO kód**, ne na příspěvek: až vedle `IT+cs-CZ`
  (Itálie) vznikne i `IT+en` (Italy) ve STEJNÉ databázi, obě jazykové verze
  sdílejí tentýž term (term meta `iso_code`), takže recept zůstává
  filtrovatelný podle "Itálie"/"Italy" jako jednoho konceptu bez ohledu na
  jazyk. Term meta `locale_post_map` (`{"cs-CZ": 123, "en": 456}`) drží,
  který příspěvek term zastupuje v kterém jazyce
  (`Atlas_Chuti_Country_Sync::get_country_post_for_term( $term_id, $locale )`).
- `atlas_ingredient_tax` — obdobné zrcadlo CPT `atlas_ingredient`, navěšeno na
  recepty (`class-ingredient-sync.php`) — pohání vyhledávání podle suroviny.
  Stejný princip: jeden kanonický term na `ingredient_key`, sdílený napříč
  jazykovými verzemi ingredience.
- `atlas_meal_type`, `atlas_difficulty`, `atlas_diet`, `atlas_glossary_category`
  — identita termu je stabilní anglický klíč (slug), ne české jméno: `easy`,
  ne `Snadné`; `technique`, ne `Kuchařské techniky`. `atlas_continent` stejně
  (`europe`, ne `Evropa`). Term `name` zůstává dnešní český popisek;
  `includes/class-taxonomy-labels.php` drží mapu klíč→popisek pro obě
  jazykové verze, takže budoucí anglická stránka umí zobrazit "Easy" pro
  tentýž term, aniž by vznikal druhý duplicitní term. `atlas_difficulty` a
  `atlas_glossary_category` jsou uzavřené slovníky (3, resp. 4 hodnoty);
  `atlas_meal_type`/`atlas_diet` zůstávají otevřené — nový klíč z importu se
  prostě vytvoří.

Regiony uvnitř zemí (Itálie → Toskánsko) nejsou zatím obsahově vyplněné, ale
jde o obyčejné WordPress taxonomie, takže přidat podtaxonomii "region" později
nevyžaduje změnu schématu.

Všechna vlastní pole (perex, ingredience, postup, fakta o zemi…) jsou
definovaná na jednom místě — `includes/class-meta-fields.php` — a
zaregistrovaná přes `register_post_meta()`
(`includes/class-register-meta.php`, typ/sanitizace/`auth_callback`/
`show_in_rest`). Admin formuláře, JSON importer i REST API tak vždy čtou/píšou
stejný kontrakt; totéž bude jednou používat OpenAI modul.

### Synchronizace Země → Světadíl

Při uložení země (import i admin) se nejdřív vytvoří/aktualizuje profil,
teprve pak se přiřadí světadíl a uloží zbylá metadata, a až nakonec proběhne
explicitní synchronizace s `atlas_country_tax` (`import_country()` v
`class-json-importer.php`, kroky 1–4 přímo okomentované v kódu). Kontinent se
navíc na recept **nikdy nečte z cache** — `Atlas_Chuti_Country_Sync::get_continent_ids_for_country_term()`
si ho pokaždé zjistí přímo z CPT příspěvku dané země, takže pořadí uložení
polí nikdy nemůže rozbít vazbu Itálie → Evropa → Spaghetti Carbonara, ani když
se kontinent země později v adminu změní (`resync_recipes_for_country_term()`
tehdy přetáhne správný kontinent na všechny recepty té země).

### Stabilní identita = klíč + jazyk (poslední stabilizační fáze)

WordPress post ID není nikdy jediný identifikátor v JSON datech — a od
poslední stabilizační fáze platí navíc: **samotný stabilní klíč sám o sobě
už taky není jedinečná identita příspěvku.** Jde o JEDNU databázi — až bude
`IT+en` (Italy) existovat vedle `IT+cs-CZ` (Itálie), půjde o DVA různé
příspěvky ve stejné tabulce `wp_posts`. Identita příspěvku je proto vždy
dvojice **(stabilní klíč, `atlas_locale`)**:

- **Země** — ISO 3166-1 kód (`atlas_iso_code`), např. `IT`, + locale.
  `Atlas_Chuti_I18N::find_country_by_iso( 'IT', 'cs-CZ' )` vrátí Itálii,
  `find_country_by_iso( 'IT', 'en' )` vrátí Italy — dva různé příspěvky.
- **Recept/slovníček** — `atlas_translation_group` (`recipe_key`), stabilní
  napříč jazyky, + locale (`find_by_translation_group( $post_type, $group,
  $locale )`); odvodí se ze slugu, pokud není zadán.
- **Ingredience** — `atlas_ingredient_key` (`ingredient_key`), např.
  `tomato`, + locale — nikdy český slug. "Rajče"/"rajčata"/"rajčat" jsou
  aliasy JEDNÉ entity V ČEŠTINĚ (`find_ingredient_by_key( 'tomato', 'cs-CZ'
  )`); anglická entita `tomato`+`en` ("Tomato") je samostatný příspěvek se
  svými vlastními aliasy.

Locale se pro danou JSON položku spočítá jednou (`$item['locale']`, chybí-li
→ `cs-CZ`) a předává se do každého vyhledání/rozřešení reference —
`class-json-importer.php` nikdy nehledá "naslepo". Díky tomu import
`{ "locale": "en", "iso_code": "IT", "title": "Italy" }` NIKDY neaktualizuje
českou Itálii, ale založí/aktualizuje anglickou variantu — a opakovaný
import stejných českých dat pořád jen aktualizuje týž český příspěvek
(žádná duplicita). `Atlas_Chuti_I18N::current_locale()` je jediné místo,
které řekne "v jakém jazyce běží tento request" (dnes vždy `cs-CZ`); jakýkoli
budoucí vícejazyčný plugin se napojí tam, ne na desítky míst v kódu.

### Ingredience, jednotky a přepočet porcí

Ingredience se ukládají strukturovaně:

```json
{
  "ingredient_key": "spaghetti",
  "display_name": "Spaghetti",
  "quantity": 400,
  "unit": "g",
  "note": "",
  "group": "Hlavní",
  "scalable": true
}
```

`quantity` přijímá číslo, zlomek ("1/2", "1 1/2") i text ("podle chuti",
"špetka") — `Atlas_Chuti_Servings::parse_quantity()` pozná, co je matematicky
přepočitatelné. `scalable` je volitelný ruční override (např. vynutit
"nepřepočítávat", i kdyby množství vypadalo jako číslo). Přepínač porcí
(2|4|6|8, `assets/js/servings.js`) přepočítá jen škálovatelné položky a bez
reloadu aktualizuje množství, aktivní volbu i údaj "Porce" v informačním
panelu; tlačítko "Přejít na recept" vede na sekci Ingredience, ne rovnou na
Postup.

Jednotky nejsou jen volný text — `Atlas_Chuti_Units` (`class-units.php`)
normalizuje běžný český zápis ("g", "ks", "lžíce"…) na jazykově neutrální
klíč (`g`, `pcs`, `tbsp`…) s popiskem pro cs/en, aniž by bylo nutné měnit už
napsaná data. Nic to dnes nepřepočítává (žádné US jednotky) — jen to
neblokuje budoucnost.

Postup je pole kroků `{ "order": 1, "text": "…" }`, ne jeden WYSIWYG text;
datový model (repeater shape) lze později rozšířit o obrázek/čas/časovač
kroku beze změny existujících dat.

## Vyhledávání podle ingredience

Hledání na homepage ("Hledat recept, zemi, jídlo nebo surovinu…") najde
recept i podle normalizované ingredience, ne jen podle názvu/textu — dotaz
"kuře" najde recept obsahující ingredienci "chicken", protože "kuře" je
uložené jako alias této ingredience. Mechanismus (`class-ingredient-sync.php`)
zrcadlí ingredience do skryté taxonomie `atlas_ingredient_tax` na receptech
(stejný vzor jako země), takže samotné hledání je jeden indexovaný
`tax_query` — škáluje na tisíce receptů, protože prohledávaný LIKE dotaz běží
jen nad malým slovníkem ingrediencí, ne nad každým receptem.

## Import obsahu (JSON)

**Atlas chutí → Import obsahu** (nebo `wp atlas import soubor.json`)
přijímá jeden JSON soubor s libovolnou kombinací klíčů `ingredients`,
`countries`, `glossary`, `recipes` — viz `/schema` (JSON Schema, draft-07) a
`/sample-data`.

**Validace** (`class-json-importer.php`, `validate_recipe()`/
`validate_country()`) před zápisem kontroluje: povinná pole, typy hodnot
(kladné porce, nezáporné časy), validní `status`/`locale`/světadíl/obtížnost,
strukturu každé ingredience i kroku, a hlavně — že hlavní země receptu
skutečně existuje. Pokud ne, recept se **vůbec nevytvoří** a chyba je vidět
v reportu (dry-run i ostrý import), místo aby tiše vznikl osiřelý recept bez
země.

**Duplicity** (jen recepty, protože "stejný název jídla v jiné zemi" je
skutečné riziko) se kontrolují v tomto pořadí: (1) stabilní `translation_group`,
(2) normalizovaný název + hlavní země, (3) originální název + hlavní země,
(4) slug — všechno kromě (1) je navíc svázané se stejnou zemí, takže italské
a japonské "Curry" nikdy neaktualizují jedno druhé.

**Dávkové zpracování** (bod 11 zadání): ostrý import (ne dry-run) neběží jako
jeden dlouhý request. Postaví se plochá fronta úkolů (nejdřív vytvořit
všechny položky, pak dořešit vzájemné vazby) a zpracovává se po 8 položkách
na jeden HTTP request přes vlastní stránku s auto-refreshem — 50 receptů tak
bezpečně proběhne i na běžném shared hostingu s krátkým `max_execution_time`,
stránku lze zavřít a vrátit se k ní, import pokračuje tam, kde skončil
(`atlas_chuti_import_batch_*` transient). `wp atlas import` (CLI) dávkování
nepotřebuje a běží vše v jednom volání.

Vazby mezi entitami (země receptu, související recepty/pojmy…) se zadávají
stabilním klíčem — ISO kódem, `translation_group`, `ingredient_key` — nikdy
číselným WordPress ID; slug je přijímán jako fallback jen dokud existuje
jediná jazyková verze.

Testovací data pro ověření datového modelu: `sample-data/batch-import-sample.json`
obsahuje Itálii, Japonsko a Thajsko, po jednom receptu z každé (Spaghetti
Carbonara, Miso ramen, Pad Thai) a pár pojmů do slovníčku:

```
wp atlas import sample-data/batch-import-sample.json
```

`sample-data/countries-master-minimal-sample.json` navíc ukazuje vzor pro
bod 15 zadání — zemi lze naimportovat i jen s faktografickými poli (ISO,
název, světadíl, hlavní město…) bez hotového gastronomického profilu; taková
země existuje v databázi jako koncept a počítá se do jmenovatele Kulinářského
pasu, ale nemá zatím veřejnou stránku. Import celého ~195zemního datasetu a
prvních 50 produkčních receptů je záměrně samostatný následující krok.

## Kulinářský pas

Bez registrace — vše v `localStorage` (`assets/js/passport.js`,
`window.AtlasPassport`). Ukládá se ale podle **stabilních identifikátorů**,
ne českých názvů: země podle ISO kódu, recepty podle `recipe_key`
(`atlas_translation_group`) — takže lokální data zůstanou smysluplná, i když
se stránka jednou přesune na jiný slug nebo jazyk.

Jmenovatel "12 / 195 zemí" vychází z master datasetu (`atlas_chuti_total_countries()`
počítá `atlas_country` v obou stavech, publish i draft), ne jen z počtu
veřejně publikovaných gastronomických profilů — takže dovezení kompletního
seznamu zemí bez okamžitého psaní 195 článků čítač neposune špatným směrem.

## Homepage a fotografie

Hero fotografie homepage a fotografie jednotlivých světadílů se nastavují
přes WordPress (Přizpůsobit → Atlas chutí – Homepage; Příspěvky → Světadíly →
upravit term), nejsou hardcoded — bez nastavení web dál vypadá dobře
(gradient pozadí / elegantní placeholder). Typické suroviny země zůstávají
textové chips bez fotografií (žádných šest zbytečných obrázků na zemi).
Recept/země bez vlastní fotky zobrazí jednotný placeholder; fotografie se
nyní nahrávají ručně přes Media Library — žádné AI generování obrázků.

## Multilingual architektura

Budoucí anglická verze **nebude samostatná WordPress instalace.** Cílový a
dnes už datově hotový model:

```
JEDEN WordPress
JEDNA databáze
JEDEN theme
JEDEN core plugin

atlaschuti.cz   → cs-CZ (aktivní dnes)
atlaschuti.com  → en (později, ve STEJNÉ instalaci)
```

Angličtina se dnes NEZAPÍNÁ — žádný `/en/`, žádný přepínač jazyka, žádný
`hreflang`, žádná anglická stránka, žádné anglické produkční recepty. Datový
model, importer i frontendové dotazy jsou ale na to připravené beze změny
architektury, až přijde čas:

- **Identita = stabilní klíč + jazyk**, ne klíč samotný — viz sekce výše.
  Nutné, protože jedna databáze bude jednou držet dva příspěvky pro "totéž"
  (`IT`+`cs-CZ` a `IT`+`en`), takže samotné `IT` už nestačí.
- **`Atlas_Chuti_I18N::current_locale()`** — jediné místo v kódu, které říká
  "jaký je aktuální jazyk požadavku" (dnes vždy `cs-CZ`, filtrovatelné přes
  `atlas_chuti_current_locale`). Nic jiného v theme ani pluginu si jazyk
  nezjišťuje jinak.
- **Centrální locale filtrování** (`class-i18n.php`, `pre_get_posts` na
  frontendu) — každý dotaz na `atlas_recipe`/`atlas_country`/
  `atlas_glossary`/`atlas_ingredient` se automaticky omezí na aktuální
  jazyk, pokud si dotaz locale nezadal sám (importer to dělá explicitně).
  Homepage sekce, archivy, related recepty, vyhledávání (`class-search.php`,
  včetně aliasů ingrediencí) — jedno místo, ne desítky upravovaných šablon.
- **Sdílené technické taxonomie** — `atlas_country_tax`/`atlas_ingredient_tax`
  mají jeden kanonický term na ISO/`ingredient_key` napříč jazyky (term meta
  `locale_post_map`); `atlas_continent`/`atlas_difficulty`/`atlas_diet`/
  `atlas_meal_type`/`atlas_glossary_category` mají identitu ve stabilním
  anglickém slugu, populárně dostupnou přes `class-taxonomy-labels.php`. Viz
  sekce "Datový model" výše.
- **Kulinářský pas zůstává jazykově nezávislý** — `localStorage` ukládá ISO
  kód země a `recipe_key`, nikdy lokalizovaný slug; `atlas_chuti_total_countries()`
  a `atlas_chuti_continent_totals()` počítají **unikátní ISO kódy**, ne počet
  příspěvků, takže přidání anglické Itálie časem neposune "12 / 195 zemí" na
  "12 / 196".
- **`class-polylang-bridge.php`** — volitelný, plně neaktivní bez Polylang
  (`function_exists()` guardy všude). Pokud a až bude Polylang nainstalován,
  propojí `current_locale()` s reálným jazykem návštěvníka a umí označit
  jazyk příspěvku / propojit překlady podle `translation_group`/ISO/
  `ingredient_key` — bez toho zůstává web funkčně identický jako dnes.
  Doména na jazyk (`atlaschuti.com` → `en`) se nastavuje v administraci až
  při skutečném zapnutí angličtiny, nikdy natvrdo v kódu.
- `atlas_translation_group` v JSON kontraktu: `{ "locale": "cs-CZ",
  "translation_group": "spaghetti-carbonara" }` — anglická verze později
  použije `"locale": "en"` (nebo `en-US`/`en-GB`) se stejným
  `translation_group`, ale VLASTNÍM slugem (dva příspěvky nemůžou sdílet
  slug ve stejném post type).
- URL/slugy jsou nezávislé na jazyce — `/recepty/` dnes, `/recipes/` později
  na `.com`, bez zásahu do datového modelu. Systémové stránky (`/zeme/`,
  `/kulinarsky-pas/`, právní stránky…) se linkují přes
  `atlas_chuti_system_url( $key )` (`includes/functions.php`), ne přes
  desítky roztroušených `home_url('/zeme/')` volání.
- `__()`/`_e()`/`_x()`/`esc_html__()`/`esc_attr__()` důsledně v celém theme i
  pluginu (text domain `atlas-chuti`, `Domain Path: /languages`), připraveno
  na `.po`/`.mo` překlad. JS texty (`passport.js`, admin `repeater.js`,
  `continent-image.js`) jdou přes `wp_localize_script()`, ne natvrdo v `.js`.
- `class-seo.php` dnes nevkládá `hreflang` ani odkazy na neexistující
  `.com` — až bude anglická verze reálně spuštěná, `hreflang="cs"`/`"en"` a
  odpovídající canonical URL lze doplnit bez zásahu do datového modelu.

`sample-data/multilingual-test-dataset.json` je interní testovací dataset
(ne pro produkci/publikaci) ověřující přesně tohle: `IT`+`cs-CZ` vs.
`IT`+`en`, `spaghetti-carbonara`+`cs-CZ` vs. +`en`, `tomato`+`cs-CZ` vs.
+`en` — import anglické varianty nikdy nepřepíše/nezdvojí českou.

## SEO

`class-seo.php` doplňuje **skutečný HTML `<title>`** (přes
`document_title_parts` — ne zastaralé `wp_title()`; když `atlas_seo_title`
není vyplněný, použije se běžný WordPress title beze změny), meta
description (s fallbackem přes existující obsah, vždy zbavenou HTML i
shortcodes), canonical, `robots`, OpenGraph a strukturovaná data (WebSite,
Recipe, BreadcrumbList) sestavená výhradně ze skutečných polí receptu/země —
žádná fake ratings/reviews/nutrition/calories. Pokud je aktivní Yoast,
RankMath nebo SEOPress, vlastní výstup se automaticky vypne (`class-seo.php`
zůstává jinak aktivní i bez SEO pluginu); lze ho i ručně vypnout přes
`add_filter( 'atlas_chuti_disable_builtin_seo', '__return_true' )`, kdyby se
projekt někdy přesunul na plnohodnotný SEO plugin. Technické taxonomie
nejsou publicly queryable, takže nevznikají duplicitní indexovatelné
archivy typu `/kuchyne/italie/` vedle `/zeme/italie/`, ani ve veřejném XML
sitemapu (`/wp-sitemap.xml` — vestavěný WordPress Core sitemap, žádný
vlastní systém; `class-seo.php` jen explicitně potvrzuje přes
`wp_sitemaps_post_types`/`wp_sitemaps_taxonomies`, že do něj vstupují
publikované recepty/země/pojmy slovníčku a veřejný `atlas_continent`, ne
interní `atlas_ingredient` ani technické taxonomie).

**Recipe schema** teď navíc obsahuje `author` (autor příspěvku ve
WordPressu), `datePublished`/`dateModified`, `recipeCategory` (z reálného
`atlas_meal_type`) a `recipeCuisine` (lokalizovaný název země pro aktuální
locale — dohledaný přes příslušný `atlas_country` příspěvek, ne přes
technický název sdíleného taxonomy termu). `image` nabízí až tři reálně
existující velikosti stejné fotky — 16:9 (`atlas-hero`), 4:3 (`atlas-card`),
1:1 (`atlas-square`) — každou jen pokud pro ni WordPress skutečně vygeneroval
soubor.

**Filtrované archivy receptů** (`?zeme=`, `?svetadil=`, `?typ=`,
`?obtiznost=`, `?dieta=`, `?cas=`) dostanou `noindex,follow` a canonical na
základní `/recepty/` — filtrování zůstává pro návštěvníky plně funkční,
jen se (zatím) nemá masově indexovat každá kombinace. Interní vyhledávání
(`/?s=…`) je vždy `noindex,follow`. Základní archivy (`/recepty/`,
`/slovnicek/`, kontinent, homepage) mají self-canonical včetně stránkování.

**Angličtina/hreflang**: dokud není aktivní, `class-seo.php` nevkládá žádný
`hreflang`, žádnou `.com` URL ani přepínač jazyka — jen zachovává
multilingual/Polylang bridge připravený z předchozí fáze (viz sekce
"Multilingual architektura"). `hreflang` se doplní, až bude anglická verze
reálně propojená přes `translation_group`/ISO/`ingredient_key`.

## Nasazení na sdílený hosting (např. FORPSI Easy Linux)

1. Standardní instalace WordPressu (rychlá instalace hostingu, nebo ruční
   nahrání WP core přes FTP/SFTP).
2. Do `wp-content/themes/` a `wp-content/plugins/` nahrajte obsah tohoto
   repozitáře stejnojmenně.
3. Aktivujte plugin, theme, uložte trvalé odkazy — viz Instalace výše.
4. Nahrajte fotografie přes Media Library.
5. Volitelně self-hostujte fonty místo Google Fonts CDN — návod v
   `wp-content/themes/atlas-chuti/assets/fonts/README.md`.
6. Nastavte zálohování databáze a `wp-content/uploads` na úrovni hostingu
   (mimo git, viz `.gitignore`).

## First deployment to FORPSI

Repozitář je **privátní** a obsahuje jen `wp-content/themes/atlas-chuti/` a
`wp-content/plugins/atlas-chuti-core/` — WordPress core, databáze, uploads,
`wp-config.php`, cache a žádné secrets do gitu nepatří (viz `.gitignore`).
Nasazení jede přes `.github/workflows/deploy.yml`, ruční
[`workflow_dispatch`](https://docs.github.com/en/actions/using-workflows/manually-running-a-workflow) —
**ne** automaticky při push do `main` (to zapneme až po pár ověřených
ručních spuštěních). Workflow nejdřív zkontroluje PHP i JS syntax v theme a
pluginu; při jakékoli chybě se deploy vůbec nespustí. Nasazuje se výhradně
přes `rsync` nad SSH, a to jen dovnitř přesně
`wp-content/themes/atlas-chuti/` a `wp-content/plugins/atlas-chuti-core/` na
serveru — nikdy se nesahá na `wp-content/uploads`, WordPress core, jiné
pluginy/themes, `wp-config.php`, `.htaccess` ani databázi.

Postup prvního nasazení:

1. Na FORPSI přes Softaculous (nebo ručně) nainstalujte čistý WordPress.
2. Ve WordPress administraci (Nastavení → Čtení) hned zapněte **"Zakázat
   prohledávání webu vyhledávači"** — zůstane zapnuté po celou dobu vývoje a
   testování (viz sekce "Indexace" níže).
3. Na FORPSI aktivujte SSH přístup k hostingu (v administraci hostingu).
4. Ověřte přístup přes SFTP/SSH (libovolný klient, nebo `sftp -P 2222
   uzivatel@host`) a zjistěte přesnou cestu k webrootu (typicky
   `/<domena>/home/www`).
5. Vytvořte **privátní** GitHub repozitář a nahrajte do něj tento obsah
   (`wp-content/themes/atlas-chuti`, `wp-content/plugins/atlas-chuti-core`,
   `schema`, `sample-data`, `.github`, `README.md`, `.gitignore` — nikdy
   WordPress core/uploads/`wp-config.php`).
6. V repozitáři nastavte GitHub Secrets (Settings → Secrets and variables →
   Actions): `FORPSI_HOST`, `FORPSI_USER`, `FORPSI_PORT` (dnes `2222`),
   `FORPSI_SSH_PRIVATE_KEY` (samostatný deployment klíč, ne osobní), a
   `FORPSI_WEBROOT` (cesta zjištěná v kroku 4, např.
   `/atlaschuti.cz/home/www`) — **žádná z těchto hodnot není a nesmí být
   natvrdo v repozitáři nebo ve workflow souboru**.
7. Spusťte ruční deployment: GitHub → Actions → **Deploy Atlas chutí to
   FORPSI** → Run workflow.
8. Ve WordPressu aktivujte plugin **Atlas chutí – Core** a theme **Atlas
   chutí**.
9. Nastavení → Trvalé odkazy → jednou uložit (bez toho nefungují CPT
   rewrite pravidla).
10. Spusťte **Atlas chutí → Nastavení stránek** (vytvoří chybějící
    systémové stránky, viz sekce "Instalace" výše).
11. Naimportujte **pouze testovací data** (viz "Testovací data" níže) —
    `sample-data/batch-import-sample.json`, ideálně přes `wp atlas import`,
    nebo přes **Atlas chutí → Import obsahu** v adminu.
12. Zkontrolujte frontend (homepage, recept, země, slovníček, filtry,
    vyhledávání, Kulinářský pas).
13. Zkontrolujte zobrazení na mobilu.
14. Zkontrolujte Recipe schema (Google Rich Results Test nebo Schema
    Markup Validator na URL testovacího receptu).
15. Zkontrolujte SEO metadata (`<title>`, meta description, canonical,
    OpenGraph — zobrazení zdrojového kódu stránky).
16. Indexaci nechte **stále vypnutou** (krok 2) — teprve po úspěšném
    ověření všeho výše jdeme na produkční obsah.

Teprve po tomto ověření pokračujeme: 3 testovací recepty na živém serveru →
kontrola → master dataset zemí → 50 produkčních receptů + slovníček (viz
"Co nyní neměnit" / cílový stav v poslední fázi zadání — tahle fáze to ještě
negeneruje).

## Budoucí OpenAI integrace (textová, bez obrázků)

Zatím nezapojeno — žádné volání OpenAI/DeepL API v tomto kódu. Budoucí modul
(např. `atlas-chuti-ai`, samostatný od `atlas-chuti-core`) bude sloužit pro
generování/kontrolu receptu a SEO dat, případně anglickou lokalizaci, a bude
produkovat přesně ten samý JSON kontrakt, jaký dnes přijímá
**Atlas chutí → Import obsahu**. Předpokládaný budoucí workflow:

```
OpenAI vytvoří recept (JSON)
→ levnější model provede kontrolu
→ recept se uloží jako koncept (status: "draft" — importer to už umí)
→ ručně se doplní fotografie přes Media Library
→ publikace
```

Automatické publikování zatím není součástí návrhu.

## Vývoj

- `wp atlas import <soubor.json> [--dry-run]` — CLI import (sdílí
  `run_import_sync()` s administrací; dávkování z webového adminu tu není
  potřeba, protože WP-CLI nemá `max_execution_time`).
- Kódovací standardy: WordPress Coding Standards, nonces + capability checks
  na všech uloženích, sanitizace vstupu / escapování výstupu, žádné
  hardcoded přihlašovací údaje v repozitáři.
