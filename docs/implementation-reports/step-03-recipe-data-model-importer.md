# Krok 3 — datový model receptu, řízené štítky a importer

## A. Audit (před změnou)

**Recipe CPT a pole** (`class-post-types.php`, `class-meta-fields.php`): `atlas_recipe`
už má kompletní strukturovaný model — `original_title`, `excerpt` (krátký perex,
required), `about` (richtext, „O receptu"), `photo_credit`, `servings_default`,
`prep_minutes`/`cook_minutes`/`total_minutes`, `ingredients`/`steps` (repeatery s
pevným shape), `tips`/`variants`/`watch_out`, `origin_history`,
`related_glossary`/`related_recipes` (post_ref_list), `seo_title`/`meta_description`.
Žádné duplicitní pole pro perex/about neexistovalo — **oba koncepty už měly svoje
finální pole**, jen nebyly formálně zdokumentované jako dvě odlišné redakční role
(krátký perex vs. delší kontext) — to je přesně to, co tento krok doplňuje (sekce B/D
zadání), beze změny schématu.

**Taxonomie** (`class-taxonomies.php`): `atlas_country_tax` (technická, syncovaná ze
CPT Země), `atlas_ingredient_tax` (technická, syncovaná ze slovníku ingrediencí),
`atlas_meal_type`/`atlas_difficulty`/`atlas_diet` (technické, `public=>false`,
otevřené slovníky s doporučeným startovním seedem přes
`Atlas_Chuti_Taxonomy_Labels`), `atlas_glossary_category` (technická, uzavřená),
`atlas_continent` (jediná veřejná s vlastní archivní šablonou). **Žádná WP Tags
taxonomie ani vlastní recipe-tag taxonomy dosud neexistovala** — to je přesně mezera,
kterou tento krok zaplňuje (sekce C/D).

**Importer** (`class-json-importer.php`, 1727 řádků): dvouprůchodový (create → resolve
refs), resumable přes transient-backed batch runner (admin UI) i synchronně
(`run_import_sync()`, používá CLI i tento report). JSON schema existuje
(`/schema/*.schema.json`, draft-07, bez `additionalProperties:false` → přidání pole je
vždy zpětně kompatibilní). Duplicitní detekce: stable key (`translation_group`
scoped na locale) → normalizovaný title+země → normalizovaný original_title+země →
slug. Same-batch dependency resolution (`build_planned_index()`) už funguje pro
země/ingredience/glosář/recepty. Comparison: `post_matches_item()` — centralizovaná,
jedna sada pravidel pro dry-run i live, s `array_key_exists()` gate ("pole v JSON
chybí = ignoruj, nikdy nepřepisuj"). Warning kanál (`warnings_for_recipe_refs()`) už
existoval a je **oddělený od hard errors** — přesně ten mechanismus, který sekce 21/22
zadání chce; nikdy netrigguje update sám o sobě.

**Idempotence**: `wp_insert_post()` (a tím pádem `post_modified`) se volá **pouze**
když `$unchanged === false`. Taxonomie porovnává `term_keys_differ()` (sanitizuje +
řadí obě strany → pořadí nehraje roli). Pole s významovým pořadím (ingredience, kroky)
se **neřadí** před porovnáním (poziční loose compare, viz `post_matches_item()`'s
docblock) — už správně implementováno, tento krok to nemění.

**SEO** (`class-seo.php`): `description` ← `atlas_meta_description` → `atlas_excerpt`
(fallback řetězec, beze změny), `recipeCategory` ← `atlas_meal_type` termy,
`recipeCuisine` ← `atlas_country_tax` → lokalizovaný název země. Žádné
`aggregateRating`/nutrition — potvrzeno, žádná data taková neexistují. Žádná práce s
tagy ve stávajícím schema.

## B. Finální recipe data model

Beze změny schématu (žádné pole nebylo přejmenováno ani odstraněno) — jediná nová
věc je taxonomie `atlas_recipe_tag` (viz sekce C) a JSON klíč `tags`, který ji plní.

| Pole (`atlas_*`) | Význam | Typ | Povinné | Kdo používá |
|---|---|---|---|---|
| `original_title` | Originální/cizojazyčný název | text | ne | šablona detailu, schema |
| `excerpt` | **Krátký editorial perex** (finální role, viz sekce D) | textarea | **ano** | šablona detailu (perex), meta description fallback, Recipe schema `description` |
| `about` | **„O receptu"** — původ/historie/charakter/technika (finální role, viz sekce D) | richtext | ne | šablona detailu (sekce „O receptu") |
| `photo_credit` | Zdroj fotografie | text | ne | šablona detailu (caption) |
| `servings_default` | Výchozí porce | int | ano | šablona, servings.js, schema `recipeYield` |
| `prep_minutes`/`cook_minutes`/`total_minutes` | Časy | int | prep ano, ostatní ne | šablona, schema `prepTime`/`cookTime`/`totalTime` |
| `ingredients` | Repeater (`ingredient_key`,`display_name`,`quantity`,`unit`,`note`,`group`,`scalable`) | repeater | ano | šablona, servings.js, ingredient search index, schema `recipeIngredient` |
| `steps` | Repeater (`order`,`text`) | repeater | ano | šablona, schema `recipeInstructions` |
| `tips`/`variants`/`watch_out` | Doplňkový redakční obsah | string_list/repeater/textarea | ne | šablona detailu |
| `origin_history` | Delší historický kontext | richtext | ne | šablona detailu |
| `related_glossary`/`related_recipes` | Vztahy | post_ref_list | ne | šablona detailu, importer pass 2 |
| `seo_title`/`meta_description` | SEO override | text/textarea | ne | `class-seo.php` |
| *(taxonomie)* `atlas_recipe_tag` | **Nové**: řízené veřejné štítky | taxonomy relation | ne | importer, admin, budoucí frontend (Krok 9) |

## C. Taxonomy map (povinný výstup)

| Koncept | Kam patří | Proč |
|---|---|---|
| Země | `atlas_country` CPT + syncovaná `atlas_country_tax` relation | first-class entita s vlastní stránkou (`/zeme/{slug}/`), ISO kód je identita |
| Kuchyně / cuisine | Odvozeno ze země (`atlas_country_tax` → `Country_Sync::get_country_post_for_term()`) | žádný samostatný koncept — recept nemá „cuisine" nezávislou na zemi, dublovalo by to zemi |
| Ingredience | `atlas_ingredient` CPT (normalizovaný slovník) + syncovaná `atlas_ingredient_tax` | structured, `ingredient_key` je identita, viz `class-ingredient-sync.php` |
| Obtížnost | `atlas_difficulty` taxonomy (uzavřená: easy/medium/hard) | structured pole s pevným seznamem, ne cross-cutting |
| Typ jídla / kurz | `atlas_meal_type` taxonomy (otevřený slovník) | structured, ale záměrně otevřený — nové kurzy vznikají obsahem |
| Dieta | `atlas_diet` taxonomy (otevřený slovník) | structured, otevřený stejně jako meal_type |
| Slovníček — kategorie | `atlas_glossary_category` taxonomy (uzavřená) | structured, jen pro `atlas_glossary` CPT |
| **Řízené veřejné štítky** | **Nová `atlas_recipe_tag` taxonomy (uzavřená, KROK 3)** | cross-cutting editorial discovery vrstva — čas/praktičnost, charakter, příležitost, sezóna, technika; viz katalog v sekci D |
| Doba přípravy „pod 30 min"/„rychlé" | **Odvozeno z `prep_minutes`/`total_minutes`**, NE tag | pole už existuje strukturovaně, tag by ho zbytečně dubloval (item 5 zadání) |
| Světadíl | `atlas_continent` taxonomy (veřejná, uzavřená, vlastní archiv) | jediná taxonomie s reálnou landing page dnes |

Cíl (zabránit duplicitám typu `vegetarian`/`vegetariánske`/`bez_masa`) je splněn: dieta
zůstává výhradně v `atlas_diet`, `atlas_recipe_tag` katalog obsahuje **jen** koncepty,
které tam nikde jinde nejsou (viz sekce D, sloupec „proč není duplikát").

## D. Controlled tag catalog (`Atlas_Chuti_Taxonomy_Labels::LABELS['atlas_recipe_tag']`)

23 klíčů, hyphen-separated (ne underscore — viz níže), zdokumentováno přímo v kódu
(`class-taxonomy-labels.php`). Katalog je **uzavřený**: `resolve_known_tag_term()`
v importeru nikdy nevytváří nový term, jen vyhledává existující.

| Key | cs-CZ label | Skupina | Proč není duplikát jiné taxonomy |
|---|---|---|---|
| `one-pot` | Jedna nádoba | Čas/praktičnost | žádné jiné pole nepopisuje počet nádobí |
| `make-ahead` | Lze připravit dopředu | Čas/praktičnost | — |
| `freezer-friendly` | Vhodné na zamrazení | Čas/praktičnost | — |
| `traditional` | Tradiční | Charakter | — |
| `budget` | Úsporné | Charakter | — |
| `family` | Rodinné | Charakter | — |
| `comfort-food` | Jídlo pro pohodu | Charakter | — |
| `street-food` | Pouliční jídlo | Charakter | — |
| `christmas` | Vánoce | Příležitost | — |
| `easter` | Velikonoce | Příležitost | — |
| `grilling` | Grilování | Příležitost | (technika grilování = `grilled` níže; toto je *okamžik*, ne technika) |
| `celebration` | Oslava | Příležitost | — |
| `picnic` | Piknik | Příležitost | — |
| `spring`/`summer`/`autumn`/`winter` | Jaro/Léto/Podzim/Zima | Sezóna | — |
| `no-bake` | Bez pečení | Technika/forma | — |
| `baked` | Pečené | Technika/forma | — |
| `grilled` | Grilované | Technika/forma | — |
| `slow-cooked` | Dlouhé vaření | Technika/forma | — |
| `fermented` | Kvašené | Technika/forma | — |

**Vědomě vynecháno** oproti zadání's inspirativnímu seznamu: `under_30_min`/`quick`
(odvozeno z `prep_minutes`/`total_minutes`, viz sekce C), jakékoliv
zdravotní/subjektivní labely typu „zdravý" (explicitní zákaz zadání).

**Proč hyphens, ne underscores** (zadání příklad používá `under_30_min`):
WordPressovo skutečné `wp_insert_term()`/`sanitize_title()` VŽDY normalizuje term slug
na pomlčky — `one_pot` by se reálně uložilo jako `one-pot` bez ohledu na to, jak je
zapsané v katalogu. Tento kód navíc už má zavedenou konvenci pomlček
(`main-course`, `side-dish`, `north-america`) pro klíče ostatních taxonomií — použití
stejné konvence je "evoluce existující architektury", ne nový vzor. Test #6 v
harness-step-03.php ověřuje, že vstup s podtržítkem (`one_pot`) se stejně správně
normalizuje na uloženou hodnotu (`one-pot`) — takže i kdyby někdo v budoucím JSONu
omylem napsal podtržítko, import neselže.

## E. Importer — změny

- **`class-taxonomies.php`**: registrace `atlas_recipe_tag` (`public=>false`,
  `rewrite=>false` — stejný vzor jako `atlas_meal_type`/`atlas_difficulty`/`atlas_diet`,
  viz sekce H pro zdůvodnění a budoucí indexační strategii); `capabilities` omezují
  vytváření/přejmenování/mazání termů na `manage_options`, `assign_terms` zůstává
  `edit_posts` (kdokoliv, kdo může upravovat recepty, může existující štítek
  přiřadit — nemůže vymyslet nový). Seed-once flag bumpnut `_v2` → `_v3` (stejný
  mechanismus, jaký kódová základna už používala při předchozím rozšíření seedu),
  jinak by nový katalog nikdy neseedoval na už inicializované instalaci.
- **`class-taxonomy-labels.php`**: přidán `atlas_recipe_tag` katalog (sekce D).
- **`class-json-importer.php`**:
  - `validate_recipe()`: nová kontrola — každý klíč v `tags` musí existovat jako
    reálný `atlas_recipe_tag` term (`get_term_by('slug', …)`), jinak **hard error**
    (na rozdíl od `meal_type`/`difficulty`/`diet`, které se stejným vzorem
    auto-vytvářejí — to je záměrný, zdokumentovaný rozdíl, přesně jak zadání
    v položce 8 žádá).
  - `resolve_known_tag_term()`: nová metoda — vyhledá term podle klíče, **nikdy
    nevytváří**. Použita v live-write cestě i (nepřímo, přes `validate_recipe()`)
    ve validaci.
  - `quality_warnings_for_recipe()`: nová metoda — WARNING (ne error) pro perex mimo
    50–140 slov (ideál 70–110, viz sekce 3 zadání) a pro chybějící/legacy
    `translation_group` (viz sekce L — **`recipe_key` samotný je od opravného kroku
    hard error, ne warning**). Nikdy neovlivňuje `$unchanged`/status — čistě
    informační text připojený k `ok`/`skip` řádku.
  - `import_recipe()`: `$taxonomy_plan['atlas_recipe_tag']` (pro idempotence-check) a
    samotný zápis termů — oba gatované `array_key_exists('tags', $item)`, ne
    `!empty()` jako u meal_type/difficulty/diet, protože explicitní `"tags": []`
    musí korektně vymazat všechny štítky (položka 12 zadání) — s `!empty()` by se
    prázdné pole tiše ignorovalo.
  - `$warnings` v `import_recipe()` teď skládá `warnings_for_recipe_refs()` (beze
    změny) **a** `quality_warnings_for_recipe()` (nové) do jednoho zobrazeného textu.
- **`schema/recipe.schema.json`**: přidána `tags` property (bez
  `additionalProperties:false` v souboru už předtím, takže jde o čistě aditivní,
  zpětně kompatibilní změnu).

## F. Idempotence

**Order-sensitive** (nikdy se neřadí před porovnáním): `ingredients`, `steps` — jsou to
autorsky seřazené sekvence, změna pořadí kroku JE reálná změna receptu.
`traditional_dishes` (země) — také autorský seznam.

**Order-insensitive** (řadí se/porovnává jako množina): `atlas_meal_type`,
`atlas_difficulty`, `atlas_diet`, **nové `atlas_recipe_tag`** — přes
`term_keys_differ()`, který obě strany sanitizuje (`sanitize_title`) a řadí
(`sort()`) před porovnáním. `related_recipes`/`related_glossary`/
`related_countries` (post_ref_list) — porovnávané v pass 2 přes `meta_differs()`,
který taky řadí obě strany před loose-compare.

**Proč identický reimport nic nezapisuje**: `post_matches_item()` je jediné místo,
které rozhoduje `$unchanged`; `import_recipe()`/`import_country()`/`import_glossary()`
volají `wp_insert_post()` (a tím `update_post_meta()`/`wp_set_post_terms()`) **pouze**
uvnitř `if (!$unchanged)` větve. Nová logika (tags) se do tohoto vzoru zapojuje přesně
stejně — žádná paralelní cesta k zápisu nebyla přidána. Ověřeno end-to-end reálným
importerem (ne jen čtením kódu) — viz sekce I, testy #2/#9/#16/#17.

## G. Produkční batch — read-only audit (bez importu/přepsání)

Zdroj: `production-data/europe-1/` (20 zemí, 100 receptů, 50 pojmů) — **nebyl
importován, nebyl přepsán, nebyla přidána žádná pole**; kontrola provedena čtením
JSON souborů nezávislým Python skriptem a přímým voláním
`Atlas_Chuti_Units::normalize()` (reálná třída, ne reimplementace).

| Kontrola | Výsledek |
|---|---|
| Počet receptů | 100 |
| Mají `about` | **100/100** |
| Mají `translation_group` | **100/100**, všech 100 unikátních (žádná kolize) — ale od opravy KROK 3B už toto pole **nemá žádný vliv na import** (viz sekce L/N) |
| Mají `recipe_key` | **0/100** → **všech 100/100 by dnes skončilo chybou importu** (recipe_key je bez výjimky povinný, `translation_group` ho nenahradí — viz sekce L/N) |
| Perex (`excerpt`) délka (slov) | min 9, max 20, průměr 13,2 |
| Perex v ideálním pásmu 70–110 slov | **0/100** |
| Perex pod 50 slov (nová quality warning by se spustila) | **100/100** |
| `tags` pole už použito | 0/100 (očekávané — pole je nové) |
| Ingredience celkem | 880 řádků |
| Ingredience bez `ingredient_key` | **0/880** |
| Ingredience s neznámou jednotkou (`Atlas_Chuti_Units::normalize()` vrací null) | **0/880** |
| ISO kódy zemí | 20/20 validních, unikátních |
| `difficulty` hodnoty použité | `easy`, `medium`, `hard` — všechny v uzavřeném seznamu |
| `meal_type` hodnoty použité | `breakfast`, `appetizer`, `soup`, `main-course`, `side-dish`, `salad`, `dessert`, `bakery`, `sauce` — všechny už seedované |
| `diet` hodnoty použité | `vegetarian` |
| `glossary` kategorie použité | `technique`, `ingredient`, `gastronomy`, `equipment` — všechny v uzavřeném seznamu |

**Co batch už splňuje**: kompletní `about`, 100 % pokrytí `ingredient_key`, 100 %
rozpoznatelné jednotky, validní ISO kódy, žádné hodnoty mimo uzavřené taxonomie.
`translation_group` je sice u všech 100 receptů vyplněný, unikátní a ve validním
technickém formátu (ověřeno proti `is_valid_stable_key()`) — ale to už **nestačí**:
od opravy KROK 3B (sekce L/N) `translation_group` recipe_key v žádném případě
nenahrazuje.

**Co bude potřeba doplnit před ostrým importem** (bez zásahu v tomto kroku —
produkční batch nebyl importován ani přepsán):
1. **`recipe_key` — POVINNÉ, blokující.** Batch nemá pole `recipe_key` u
   žádného z evidovaných 100 receptů → **při reálném pokusu o import by všech
   100/100 skončilo chybou** ("recipe_key … chybí"). Doplnění je mechanicky
   jednoduché (existující `translation_group` hodnoty jsou už ve správném
   technickém formátu, takže je lze použít jako VZOR pro `recipe_key` — ale musí
   jít o skutečně přidané, samostatné pole `recipe_key` v JSONu, ne o
   přejmenování `translation_group`, viz sekce L/N), ale je to nutný krok, ne
   volitelná kvalitativní vylepšení jako body 2–3 níže.
2. **Perex** — žádný ze 100 receptů nedosahuje cílových 70–110 slov (současný
   průměr 13,2); před ostrým importem je bude třeba redakčně rozšířit. Importer to
   nezablokuje (hard-required je jen neprázdný perex), ale quality-warning kanál
   to u každého z nich viditelně nahlásí.
3. **Tagy** — batch zatím `tags` vůbec nepoužívá; přiřazení štítků ze schváleného
   katalogu (sekce D) je čistě redakční práce nad hotovým obsahem, ne technická
   překážka — pole je nepovinné, takže import projde i bez nich.
4. Mimo tyto tři body je batch technicky plně kompatibilní se změnami tohoto
   kroku beze zásahu.

## H. SEO / structured data / GEO-AIO

- **Recipe schema** (`class-seo.php`) nebylo měněno a dál čte `atlas_excerpt`/
  `atlas_ingredients`/`atlas_steps`/`atlas_meal_type`/`atlas_country_tax` přímo z
  metadat — nezávisle na DOM, nezávisle na nové taxonomii. `dateModified` zůstává
  správný, protože idempotence (sekce F) zaručuje, že identický reimport nikdy
  nezavolá `wp_insert_post()`, takže `post_modified` se nemění.
- **Tagy ve schématu**: záměrně **nepřidány**. Schema.org `Recipe` nemá vlastní
  property pro "tagy" jako koncept; nejbližší legitimní property by byla `keywords`,
  ale mechanické naskládání všech štítků do ní bez reálného obsahu za nimi (batch
  zatím žádné tagy nemá, viz sekce G) by bylo přesně to, před čím zadání varuje
  („nevkládej mechanicky tagy jen proto, že existují"). Až bude existovat reálně
  otagovaný obsah, `keywords` je správné místo pro to v budoucím kroku.
- **Indexační strategie pro `atlas_recipe_tag`** (zadání, položka 9 a sekce K):
  taxonomie je dnes `public=>false` (stejně jako meal_type/difficulty/diet) — žádný
  archiv, žádné URL, žádné riziko "thin pages". Existující `get_robots_directive()`
  v `class-seo.php` má už dnes přesně tento typ cíleného, malého pravidla (noindex
  pro filtrovaný archiv receptů) — rozšířit ho o "noindex pro `atlas_recipe_tag`
  archiv s < N recepty" by bylo technicky malé, ale vyžadovalo by to zároveň
  postavit skutečnou frontendovou archivní šablonu (žádná dnes needexistuje pro
  žádnou technickou taxonomii) — to je frontend/šablonová práce mimo rozsah "datový
  model + importer" tohoto kroku. Doporučený postup pro Krok 9: (1) přepnout
  `public=>true` + `rewrite` na např. `/recepty/stitek/{slug}/`, (2) postavit
  `taxonomy-atlas_recipe_tag.php` reuse-ující existující `card-grid`/`recipe-card`
  komponenty ze Step 1/2, (3) v `get_robots_directive()` přidat `noindex,follow` pro
  `is_tax('atlas_recipe_tag')` s méně než např. 4 recepty, `index,follow` jinak.
- **Žádné rozbité URL, žádná změna existujících recipe URLs** — potvrzeno, žádná
  šablona ani rewrite pravidlo nebylo měněno.

## I. Testy

Bez živého WordPressu/databáze (tento environment žádný neposkytuje) — maximální
statická kontrola + plnohodnotný funkční test harness proti REÁLNÝM, neupraveným
plugin třídám (ne proti reimplementaci jejich logiky):

1. **`php -l`** na všech změněných/nových PHP souborech a kontrolně na celém
   `wp-content/themes/atlas-chuti/` + `wp-content/plugins/atlas-chuti-core/` — bez
   chyby.
2. **`python3 -m json.tool` / `json.load()`** na `schema/recipe.schema.json` po
   úpravě — validní JSON.
3. **`tests/harness-step-03.php`** (nový, committed) — stubuje jen WordPress
   API vrstvu (posty/meta/termy/term-meta/hooky s reálným `accepted_args`
   dispatchem), a spouští **skutečné** `Atlas_Chuti_I18N`, `Atlas_Chuti_Country_Sync`,
   `Atlas_Chuti_Ingredient_Sync`, `Atlas_Chuti_Taxonomies`, `Atlas_Chuti_Meta_Fields`,
   `Atlas_Chuti_JSON_Importer` — stejné třídy, které běží v produkci — nad in-memory
   databází. `php tests/harness-step-03.php` (`HARNESS_DEBUG=1` pro detailní výpisy):

   ```
   --- 64 checks, 0 failing ---
   ```

   Pokrývá všech 21 scénářů z původního zadání + 3 navíc (empty-tags-clear-all,
   quality-warning-never-forces-update dvakrát) + 7 scénářů z prvního opravného
   kroku (sekce L, TEST 1–7) + 2 doplňkové (duplicate-recipe_key-b i
   duplicate-nikdy-napříč-locale) + 6 scénářů z DRUHÉHO opravného kroku, KROK 3B
   (sekce N, scénáře A–H ze zadání, kde A/B/E/G/H jsou pokryty testy výše a C/D/F
   mají vlastní nové fixtures):

   | # | Scénář | Výsledek |
   |---|---|---|
   | 1 | nový recept → bude vytvořeno | PASS |
   | 2 | stejný recept podruhé → beze změny | PASS |
   | 3 | změna perexu → bude aktualizováno | PASS |
   | 4 | změna „O receptu" → bude aktualizováno | PASS |
   | 5 | změna title při stejném recipe_key → update, ne nový recept | PASS |
   | 6 | validní controlled tag keys → OK | PASS |
   | 7 | neznámý tag key → error | PASS |
   | 8 | duplicate tag → deterministická normalizace | PASS |
   | 9 | stejné tagy v jiném pořadí → beze změny | PASS |
   | 10 | odebrání tagu → update | PASS |
   | 11 | přidání tagu → update | PASS |
   | 12 | same-batch ingredient → rozpoznán | PASS |
   | 13 | neznámý/nerozpoznatelný ingredient → warning (ne error, viz sekce E) | PASS |
   | 14 | validní country ISO → OK | PASS |
   | 15 | neplatný country ISO → chyba | PASS |
   | 16 | identický import nemění post_modified | PASS |
   | 17 | identický import nepřepisuje taxonomy relationships | PASS |
   | 18 | identický import nepřepisuje metadata | PASS |
   | 19 | identický import nevytváří duplicates | PASS |
   | 20 | fixture s pouze `translation_group` (bez `recipe_key`) → **chyba** (KROK 3B: `translation_group` recipe_key nikdy nenahradí) | PASS |
   | 21 | dry-run nikdy nezapisuje | PASS |

   Fixture pro scénář 20: `tests/fixtures/step-03-legacy-recipe.json` (samostatný
   testovací soubor, žádná produkční data nebyla měněna; nyní ověřuje přesně opačné
   chování, než na jaké byl původně navržen — viz sekce N, proč).

   **První opravný krok — `recipe_key` hardening (sekce L), TEST 1–7 ze zadání
   opravy (pozn.: v době psaní tento krok ještě povoloval `translation_group`
   fallback pro TEST 1/7 — druhý opravný krok, sekce N, tento fallback úplně
   odstranil; tabulka níže odráží AKTUÁLNÍ, opravené chování):**

   | TEST | Scénář | Výsledek |
   |---|---|---|
   | 1 | chybějící `recipe_key` (a žádný `translation_group`) → chyba | PASS |
   | 2 | prázdný/whitespace-only `recipe_key` (bez `translation_group`) → chyba | PASS |
   | 3 | neplatný formát `recipe_key` (mezery, velká písmena, diakritika, zdvojené/okrajové pomlčky — 6 variant, bez `translation_group`) → chyba | PASS |
   | 4 | duplicitní `recipe_key` ve stejném batchi (2 různé recepty) → chyba pro OBA | PASS |
   | 4b | stejný `recipe_key` napříč DVĚMA různými locale → NENÍ duplicita (multilingual-test-dataset.json vzor) | PASS |
   | 5 | změna title při stejném `recipe_key` → aktualizováno, ne nový recept | PASS |
   | 6 | identický recept + stejný `recipe_key` → beze změny, `post_modified` beze změny | PASS |
   | 7 | validní `recipe_key`, chybějící `translation_group` → warning, ne chyba | PASS |

   **Druhý opravný krok — odstranění `translation_group` fallbacku (sekce N),
   scénáře A–H ze zadání opravy (A/B/E/G/H mapují na testy výše, C/D/F mají
   vlastní nové fixtures):**

   | Scénář | Popis | Test | Výsledek |
   |---|---|---|---|
   | A | chybějící `recipe_key` + chybějící `translation_group` → chyba | TEST 1 výše | PASS |
   | B | chybějící `recipe_key` + VALIDNÍ `translation_group` → **chyba** (klíčový regresní test) | scénář 20 výše | PASS |
   | C | prázdný `recipe_key` + validní `translation_group` → chyba | TEST C (nový) | PASS |
   | D | neplatný formát `recipe_key` + validní `translation_group` → chyba | TEST D (nový) | PASS |
   | E | validní `recipe_key` + chybějící `translation_group` → warning | TEST 7 výše | PASS |
   | F | validní `recipe_key` + validní `translation_group` → validní import | TEST F (nový) | PASS |
   | G | stejný `recipe_key` + změna title → update | TEST 5 výše | PASS |
   | H | identický import → beze změny, žádný write, `post_modified` beze změny | TEST 6 výše | PASS |

4. **Read-only audit produkčního batche** (sekce G) — nezávislý Python skript nad
   JSON soubory + přímé volání `Atlas_Chuti_Units::normalize()`; batch nebyl
   importován ani zapsán.

## J. Změněné soubory

**Původní Step 3:**
- `wp-content/plugins/atlas-chuti-core/includes/class-taxonomies.php`
- `wp-content/plugins/atlas-chuti-core/includes/class-taxonomy-labels.php`
- `wp-content/plugins/atlas-chuti-core/includes/class-json-importer.php`
- `schema/recipe.schema.json`
- `tests/harness-step-03.php` (nový)
- `tests/fixtures/step-03-legacy-recipe.json` (nový)
- `docs/implementation-reports/step-03-recipe-data-model-importer.md` (tento report)

**První opravný krok (`Step 3 fix: enforce recipe key identity`) — viz sekce L:**
- `wp-content/plugins/atlas-chuti-core/includes/class-json-importer.php` (další úprava)
- `schema/recipe.schema.json` (přidáno pole `recipe_key`)
- `tests/harness-step-03.php` (rozšířen o TEST 1–7)
- `tests/fixtures/step-03-legacy-recipe.json` (doplněn `translation_group`, aby
  reprezentoval správný "legacy fallback" scénář pod novými pravidly)
- `docs/implementation-reports/step-03-recipe-data-model-importer.md` (tento report)

**Druhý opravný krok (`Step 3 fix: remove recipe key import fallback`) — viz
sekce N:**
- `wp-content/plugins/atlas-chuti-core/includes/class-json-importer.php` (další
  úprava — odstranění `translation_group` fallbacku z `resolve_recipe_key()`)
- `schema/recipe.schema.json` (odstraněn `anyOf` blok, `recipe_key` je nyní přímo
  v top-level `required`)
- `tests/harness-step-03.php` (přepracován scénář 20, přidány TEST C/D/F)
- `docs/implementation-reports/step-03-recipe-data-model-importer.md` (tento report)

## K. Co bylo záměrně odloženo

CZ/EN implementace (Polylang, překlady) — datový model je připraven (stable
keys jsou jazykově neutrální, viz sekce K zadání), ale multilingual logika nebyla
implementována. Uživatelské interakce (Oblíbené/Uvařeno/Hodnocení backend,
komentáře, fotky) — beze změny, žádné fake rating data nebylo přidáno. Magazín,
video model — beze změny, schéma jim nebrání v budoucím doplnění. Veřejný archiv
štítků + indexační strategie — připraveno na úrovni taxonomie, samotná frontend
šablona a `noindex`-pro-slabé-archivy logika odloženy na Krok 9 (viz sekce H).
Hromadné přidání perexů/štítků do produkčního batche — záměrně neprovedeno (mimo
rozsah, viz sekce G).

## L. Oprava: `recipe_key` jako hard requirement (`Step 3 fix: enforce recipe key identity`)

> **Pozn. (sekce N níže):** tato sekce popisuje PRVNÍ opravný krok, který zavedl
> `recipe_key` jako hard requirement, ale zároveň jako přechodné řešení povolil
> `translation_group` jako WARNED fallback pro chybějící `recipe_key`. Druhý
> opravný krok (sekce N) tento fallback zcela odstranil — `translation_group` už
> dnes recipe_key v žádném případě nenahrazuje. Text níže je ponechán jako
> historický záznam prvního kroku; AKTUÁLNÍ, platné chování popisuje sekce N.

Po dokončení a pushnutí Step 3 přišla oprava: report výše původně řadil chybějící
`recipe_key`/`translation_group` mezi **quality warnings**. To bylo u `recipe_key`
špatně — je to stabilní technická identita receptu, na kterou naváže Kulinářský pas,
budoucí CZ/EN, Oblíbené/Uvařeno a další relation vrstvy, a nesmí být degradována na
volitelnou kontrolu kvality. Tato sekce popisuje opravu.

### Stabilní identita receptu

- **`recipe_key`** je od této opravy **hard required identita** pro každý
  importovaný recept — nikoli jen doporučené pole.
  - **Chybějící** `recipe_key` (a žádný platný `translation_group` fallback,
    viz níže) → **ERROR**, recept se neimportuje.
  - **Prázdný/whitespace-only** `recipe_key` → **ERROR**.
  - **Neplatný formát** → **ERROR**. Validní formát: jazykově neutrální technický
    slug, `^[a-z0-9]+([_-][a-z0-9]+)*$` — malá písmena/číslice ve
    slovech oddělených `_` nebo `-`, bez mezer, diakritiky, velkých písmen,
    zdvojených nebo okrajových oddělovačů. Přijímá OBĚ oddělovací konvence
    (`spaghetti_carbonara` i `svickova-na-smetane`), protože reálný produkční
    batch už dnes používá obě (viz sekce G) a obě jsou to, co by
    `sanitize_title()` stejně vyrobilo.
  - **Duplicitní `recipe_key`** ve STEJNÉM batchi a STEJNÉM jazyce (`locale`) →
    **ERROR pro OBA/všechny** kolidující recepty — `build_planned_index()` nyní
    počítá, kolikrát se který `(locale, recipe_key)` pár v batchi objeví, a
    `validate_recipe()` odmítne každý recept, jehož klíč se opakuje. Stejný
    `recipe_key` u DVOU RŮZNÝCH `locale` hodnot **není** kolize — to je přesně
    budoucí multilingual vzor (`sample-data/multilingual-test-dataset.json` už
    dnes obsahuje cs-CZ i en verzi téhož receptu se stejným klíčem) a musí dál
    fungovat i po Kroku 4.
  - **Kolize s existující databází** (stejný `recipe_key` jako už importovaný
    recept) → beze změny existující logiky: `stable_key_for()` teď pro
    `atlas_recipe` interně volá novou `resolve_recipe_key()` místo přímého čtení
    `translation_group`, takže `find_existing_recipe()`/idempotence beze změny
    správně najdou a AKTUALIZUJÍ existující post, nikdy nevytvoří duplicitu.
  - **`title`/`original_title`/`slug` nejsou a nikdy nebyly identita** — potvrzeno
    beze změny (žádný z nich se v `stable_key_for()`/`find_existing_recipe()`
    nepoužívá jako primární klíč). **Změna `title` při stejném `recipe_key` →
    UPDATE, nikdy nový recept** — ověřeno end-to-end (TEST 5/6, sekce I).
  - `recipe_key` se **nikdy tiše needovozuje** z title/slug v cestě zápisu —
    `resolve_recipe_key()` čte jen `recipe_key` nebo (jako zdokumentovaný,
    vždy-viditelný fallback, viz níže) `translation_group`; `stable_key_for()`
    má sice pořád `?: $slug` jako úplně poslední pojistku, ale k ní se reálně
    dostane jen `build_planned_index()`'s lehčí pre-check pro položku, která už
    stejně skončí chybou ve `validate_recipe()` — živá zápisová cesta
    (`import_recipe()`) se sem s prázdným/neplatným klíčem nikdy nedostane.

### Translation group

- **`translation_group`** zůstává, jak zadání opravy žádá, na úrovni **WARNING**:
  - Pokud `recipe_key` chybí, ale `translation_group` je vyplněný a validní,
    importer ho použije JAKO fallback identitu — recept se importuje, ale
    s viditelným warningem ("recipe_key chybí, dočasně použit fallback z
    translation_group"). Toto je přesně mechanismus, který drží zpětnou
    kompatibilitu s existujícím produkčním batchem (100/100 receptů má
    `translation_group`, 0/100 má `recipe_key`, viz sekce G) i se všemi
    sample-data soubory, aniž by je bylo nutné cokoliv v tomto kroku přepisovat.
  - Pokud `recipe_key` je vyplněný a validní, ale `translation_group` chybí →
    samostatný WARNING ("chybí translation_group... bude potřeba pro budoucí
    CZ/EN provázání"), recept se importuje bez problému (TEST 7).
  - Ani jeden z těchto warningů nikdy neovlivňuje `$unchanged`/status —
    beze změny oproti původní architektuře (sekce F).
  - Plná multilingul role `translation_group` (sdílení napříč jazykovými
    verzemi) zůstává úkolem Kroku 4 — Polylang nebyl instalován, žádné
    CZ/EN překlady nebyly vytvořeny.
- **Žádný nový meta klíč**: `recipe_key` (ať zadaný přímo, nebo odvozený
  z `translation_group` fallbacku) se ukládá do STEJNÉHO `atlas_translation_group`
  post meta, jaké už čte `single-atlas_recipe.php`/`passport.js` (Kulinářský pas) —
  **žádná šablona ani JS soubor nebyl změněn**, přesně jak oprava vyžaduje.
  Opravena byla jen jedna latentní chyba objevená při implementaci:
  `apply_i18n_meta()` by jinak mohla přepsat správně vyřešenou hodnotu zpět na
  syrový (a případně jiný) `$item['translation_group']` — `import_recipe()` teď
  před tímto voláním nastaví lokální kopii `$item['translation_group']` na už
  vyřešený `$stable_key`, takže obě zápisová místa vždy souhlasí.

### Quality warnings

- Perex mimo doporučený rozsah (ideál 70–110, warning mimo 50–140 slov) zůstává
  beze změny **WARNING**, nikdy ERROR — obsahová kvalita perexů nebyla touto
  opravou nijak měněna.
- Warning **nikdy** nemění idempotence verdikt (`$unchanged`/`beze změny` vs.
  `aktualizováno`) — ověřeno explicitně (viz "Extra" testy v sekci I): identický
  recept se stejným krátkým perexem se reimportuje jako `beze změny` a warning se
  přesto zobrazí znovu.

### Testy a regrese

Rozšířený `tests/harness-step-03.php` — **58/58 kontrol prochází** (37 původních +
21 nových/opravných), viz plná tabulka v sekci I. Explicitně ověřeno, že žádná
z původních Step 3 kontrol (řízené tagy, unknown-tag error, order-insensitive
porovnání tagů, normalizace duplicitních tagů, přidání/odebrání tagu, same-batch
ingredience, unknown-ingredient warning, validní/neplatné country ISO, true
idempotence, legacy fixture, read-only audit batche) touto opravou nepřestala
fungovat.

### Produkční batch

Beze změny — `git diff --stat -- production-data/` je prázdný. (Pozn.: tvrzení
níže o průchodu "přes fallback + warning" bylo touto větou v sekci L popsáno v
době PRVNÍHO opravného kroku — druhý opravný krok, sekce N, tento fallback
odstranil; aktuální stav batche popisuje sekce G/N.)

## N. Druhá oprava: odstranění importního fallbacku `translation_group` → `recipe_key` (`Step 3 fix: remove recipe key import fallback`)

Závěrečný report prvního opravného kroku (sekce L) uváděl:

> Missing `recipe_key` (no `translation_group` fallback) → ERROR

a současně:

> old `translation_group` field name is kept as a documented, always-visible-warning
> fallback … the current production batch would pass the fixed importer via the
> fallback path

To byl reálný rozpor s požadovanou architekturou — `translation_group` fungoval
jako TICHÁ (byť warningem doprovázená) náhrada za chybějící `recipe_key`, což
přesně to, co mělo být hard-required, degradovalo zpátky na volitelné. Tato
sekce popisuje definitivní opravu.

### Zásadní pravidlo (nyní platí bez výjimky)

Pro **každý nový/importovaný recipe objekt** musí být explicitní `recipe_key`.
`translation_group` už NIKDY nenahrazuje chybějící `recipe_key` — ani při
validaci, ani při zápisu, ani v schema kontraktu:

- chybějící `recipe_key` → **ERROR**, i když `translation_group` je vyplněný a
  validní,
- prázdný/whitespace-only `recipe_key` → **ERROR**, i když `translation_group`
  je vyplněný a validní,
- neplatný formát `recipe_key` → **ERROR**, i když `translation_group` je
  vyplněný a validní.

### Co smí zůstat backward compatible — a co ne

Backward compatibility zůstává, ale pouze pro:
- čtení existujících legacy dat z databáze (`single-atlas_recipe.php`,
  `passport.js` dál čtou `atlas_translation_group` postmeta jako recipe_key
  identitu existujících postů — beze změny, nebyly dotčeny),
- bezpečnou budoucí migraci/audit existujících dat.

**Není povolená pro nový JSON import.** `resolve_recipe_key()`
(`class-json-importer.php`) teď čte **výhradně** `$item['recipe_key']`:

```php
private function resolve_recipe_key( $item ) {
    return trim( (string) ( $item['recipe_key'] ?? '' ) );
}
```

Žádný fallback na `translation_group`, žádné odvození ze slugu/title —
`stable_key_for()`'s větev pro `atlas_recipe` teď taky nemá `?: $slug` pojistku,
kterou měla po prvním opravném kroku (i když byla v živé zápisové cestě
nedosažitelná, odstranění jí uzavírá i teoretickou možnost).

### `recipe_key` a `translation_group` mají jinou roli

Nejsou to dvě jména téhož vstupního pole — mají koncepčně odlišné role:

| Pole | Role | Příklad |
|---|---|---|
| `recipe_key` | Stabilní technická identita KONCEPTU receptu | `spaghetti_carbonara` |
| `translation_group` | Identita PŘEKLADOVÉ RODINY (Krok 4) | `recipe_spaghetti_carbonara` |

V Kroku 4 budou dvě jazykové varianty téhož receptu sdílet stejný
`translation_group`, ale každá bude mít VLASTNÍ `recipe_key` a jiné `locale`.
Multilingual implementace v tomto kroku záměrně NEVZNIKLA (žádný Polylang, žádné
překlady) — pouze byl datový model připraven tak, aby jí nic nebránilo:
`recipe_key` a `translation_group` jsou od této opravy validovány a čteny jako
dvě nezávislá pole (`quality_warnings_for_recipe()` řeší jejich absenci
odděleně), byť dnešní implementace (vědomě, viz citace zadání níže) obě stále
ukládá do stejného `atlas_translation_group` postmeta mechanismu — plné
oddělení úložiště je až úkol Kroku 4, ne tohoto opravného kroku:

> Proto prosím připrav datový model tak, aby tato pole byla koncepčně oddělená,
> i pokud historická implementace dnes ukládá některá data do stejného meta
> mechanismu.

### Schema

`schema/recipe.schema.json`: odstraněn `anyOf: [{required: [recipe_key]},
{required: [translation_group]}]` blok, který dřív povoloval `translation_group`
jako náhradu. `recipe_key` je teď přímo v top-level `"required"` poli, bez
výjimky. Popisky `recipe_key`/`translation_group` polí přepsány tak, aby jasně
odrážely jejich oddělenou roli (tabulka výše) a NEODKAZOVALY na žádný fallback.

### Produkční batch

Beze změny — `git diff --stat -- production-data/` je prázdný, batch nebyl
importován ani přepsán. Read-only audit (aktualizovaná sekce G) potvrzuje
očekávaný, žádoucí výsledek zadání opravy:

> Batch před ostrým importem vyžaduje explicitní doplnění `recipe_key`.

Konkrétně: **0/100** receptů má pole `recipe_key` → **100/100** by dnes skončilo
chybou importu. To je správně — validační pravidla nebyla kvůli starému batchi
nijak zmírněna.

### Testy

`tests/harness-step-03.php` rozšířen o klíčový regresní test (scénář B: chybějící
`recipe_key` + VALIDNÍ `translation_group` → chyba) a o scénáře C/D/F — viz plná
tabulka v sekci I. Scénář 20 (dříve "legacy fixture importuje se přes fallback")
byl přepracován na test přesně opačného chování — teď dokazuje, že tatáž fixture
(`translation_group` bez `recipe_key`) je **odmítnuta**. Všechny předchozí testy
(řízené tagy, relationships, idempotence) byly znovu spuštěny a dál procházejí —
**64/64 kontrol**, 0 selhání.

## O. Manuální kroky

Žádné.
