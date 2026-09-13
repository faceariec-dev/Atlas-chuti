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
  - `quality_warnings_for_recipe()`: nová metoda — WARNING (ne error) pro chybějící
    `translation_group` (recipe_key) a pro perex mimo 50–140 slov (ideál 70–110, viz
    sekce 3 zadání). Nikdy neovlivňuje `$unchanged`/status — čistě informační text
    připojený k `ok`/`skip` řádku.
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
| Mají `translation_group` | **100/100** — nová quality-warning „chybí recipe_key" se u tohoto batche neuplatní |
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

**Co batch už splňuje**: kompletní `about`, kompletní `translation_group`
(recipe_key), 100 % pokrytí `ingredient_key`, 100 % rozpoznatelné jednotky, validní
ISO kódy, žádné hodnoty mimo uzavřené taxonomie.

**Co bude potřeba doplnit před ostrým importem** (bez zásahu v tomto kroku):
1. **Perex** — žádný ze 100 receptů nedosahuje cílových 70–110 slov (současný
   průměr 13,2); před ostrým importem je bude třeba redakčně rozšířit. Importer to
   nezablokuje (hard-required je jen neprázdný perex), ale nový quality-warning
   kanál to nyní u každého z nich viditelně nahlásí.
2. **Tagy** — batch zatím `tags` vůbec nepoužívá; přiřazení štítků ze schváleného
   katalogu (sekce D) je čistě redakční práce nad hotovým obsahem, ne technická
   překážka — pole je nepovinné, takže import projde i bez nich.
3. Mimo to je batch technicky plně kompatibilní se změnami tohoto kroku beze
   zásahu.

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
   --- 37 checks, 0 failing ---
   ```

   Pokrývá všech 21 scénářů ze zadání + 3 navíc (empty-tags-clear-all,
   quality-warning-never-forces-update dvakrát):

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
   | 20 | starší validní fixture bez tagů se nerozbije | PASS |
   | 21 | dry-run nikdy nezapisuje | PASS |

   Fixture pro scénář 20: `tests/fixtures/step-03-legacy-recipe.json` (samostatný
   testovací soubor, žádná produkční data nebyla měněna).
4. **Read-only audit produkčního batche** (sekce G) — nezávislý Python skript nad
   JSON soubory + přímé volání `Atlas_Chuti_Units::normalize()`; batch nebyl
   importován ani zapsán.

## J. Změněné soubory

- `wp-content/plugins/atlas-chuti-core/includes/class-taxonomies.php`
- `wp-content/plugins/atlas-chuti-core/includes/class-taxonomy-labels.php`
- `wp-content/plugins/atlas-chuti-core/includes/class-json-importer.php`
- `schema/recipe.schema.json`
- `tests/harness-step-03.php` (nový)
- `tests/fixtures/step-03-legacy-recipe.json` (nový)
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

## L. Manuální kroky

Žádné.
