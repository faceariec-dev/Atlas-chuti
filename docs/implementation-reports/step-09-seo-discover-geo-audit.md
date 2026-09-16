# Krok 9 — Finální SEO, Discover, structured data, GEO/AIO a technický audit

## A. Executive summary

Audit (subagent, read-only, 46 nástrojových volání) prošel `class-seo.php` v
celém rozsahu, každý page type/template, hreflang/canonical logiku, sitemap/
robots chování, breadcrumbs, GEO/semantic strukturu, performance architekturu
a reprezentativní vzorek produkčního batche. Výsledek: **naprostá většina
infrastruktury z Kroků 1-8 je již správně** — tento krok je proto malý,
cílený patch (2 upravené soubory, 3 nové), ne přepis.

Skutečné nálezy vyžadující kód:
1. Diskuze (`atlas_topic`) neměla vlastní meta description (padala na obecný
   popis webu) ani žádné structured data — opraveno (sekce E).
2. Chyběla `Organization` JSON-LD entita vedle již existující `WebSite`
   — přidáno, jen z reálných dat (sekce E).
3. Sitemap post-type filtr mohl defenzivně zahrnout i `atlas_ad_campaign`
   (už beztak vyloučen přes `public=>false`) — přidáno pro konzistenci se
   stejným vzorem u `atlas_ingredient` (sekce G).

Zbytek Kroku 9 je: nová infrastruktura pro budoucí použití (obsahový audit
nástroj, content quality gate), testy a **kritický nález v produkčním
batchi** — všech 100 receptů v `production-data/europe-1/` postrádá pole
`recipe_key`, takže v současném stavu **nejde importovat vůbec** (importer
to tvrdě odmítne, žádná fallback logika neexistuje záměrně). Batch NEBYL
změněn — jde o read-only nález, viz sekce L/M.

## B. SEO audit

**Titles** (`get_seo_title()`): `atlas_seo_title` meta pro recipe/country/
glossary, jinak `wp_get_document_title()`. Žádné duplicate patterns, žádný
keyword stuffing, brand suffix konzistentní (WP core). Filtrované archivy
nevytvářejí nové title varianty (title se neliší podle query paramů).
ŽÁDNÁ ZMĚNA — již správně.

**Meta description**: cascáda recipe/country/glossary
(`atlas_meta_description → atlas_excerpt → atlas_intro → atlas_short_
definition`), `post` (Magazín) přes `get_the_excerpt()`. **OPRAVENO**:
`atlas_topic` (Diskuze) nově má vlastní větev (topic excerpt/post_content),
dřív padal na `get_bloginfo('description')` — reálné riziko duplicitních
meta descriptions napříč celým `/diskuze/` korpusem. Nikdy fake fallback
text — když nic není k dispozici, description je prostě prázdná (WP pak
nevypíše meta tag vůbec).

**Canonical**: `is_singular()` → `get_permalink()` (pokrývá VŠECHNY singular
typy včetně `atlas_topic`), archivy self-canonicalizují s pagination přes
`get_pagenum_link()`, filtrovaný recipe archiv kolabuje na nefiltrovanou
base URL (item 25/47). Cook Mode canonical je nedotčený (`is_singular()`
běží dřív než jakákoliv `?cook=1` logika, get_permalink() nikdy nečte query
string). ŽÁDNÁ ZMĚNA.

**Robots**: search → noindex,follow; Cook Mode → noindex,follow; My Atlas +
oba utility templates → noindex,follow; filtrovaný recipe archiv →
noindex,follow; prázdná Magazín kategorie/Diskuze archiv → noindex,follow.
ŽÁDNÁ ZMĚNA.

## C. Indexation matrix

| Page type | Index? | Canonical | Sitemap? | Hreflang? | Schema |
|---|---|---|---|---|---|
| Homepage CZ/EN | Ano | self | Ano | Ano (pokud EN existuje) | WebSite, Organization |
| Recipe archive | Ano (bez filtru) | self / base při filtru | Ano | Ne | — |
| Recipe detail | Ano | self | Ano | Ano (pokud pár existuje) | Recipe (+AggregateRating/Video pokud reálné) |
| Country archive | Ano (real Page) | self | Ano | Ano | — |
| Country detail | Ano | self | Ano | Ano | — |
| Glossary archive | Ano | self | Ano | Ne | — |
| Glossary detail | Ano | self | Ano | Ano | — |
| Controlled recipe-tag archive | Ne (žádný dedikovaný archiv — tag je jen filtr) | — | Ne | Ne | — |
| Magazine archive | Ano (real Page) | self | Ano | Ne | — |
| Magazine article | Ano | self | Ano | Ne (post typ zatím bez hreflang větve) | Article |
| Magazine category | Ano (pokud neprázdná) | self | Ano | Ne | — |
| Discussion archive | Ano (pokud neprázdná) | self | Ano | Ne | — |
| Discussion topic | Ano | self | Ano | Ne | DiscussionForumPosting (nově) |
| Search | Ne | search link | Ne | Ne | — |
| Paginated archives | Ano (vlastní stránka) | self (page N) | Ano (WP core) | Ne | — |
| Author archive | Ne (přesměrováno domů) | — | Ne | Ne | — |
| Date archive | Ne (generic archive.php, žádný veřejný odkaz na ně) | — | Ne | Ne | — |
| Attachment page | Ne (žádný odkaz nikde do nich nevede) | — | Ne | Ne | — |
| Login/register/reset | Ne | — | Ne | Ne | — |
| Můj Atlas (+Kolekce/Nákupní seznam/Plán jídel) | Ne | — | Ne | Ne | — |
| Cook Mode (?cook=1) | Ne | hlavní recipe URL | Ne | Ne | — (dědí Recipe schema stránky) |
| Co dnes vařit? | Ne | — | Ne | Ne | — |
| Co mám doma? | Ne | — | Ne | Ne | — |
| Legal/general pages | Ano po publikaci (draft do té doby) | self | Ano po publikaci | Ne | — |
| Empty/draft placeholder pages | Ne (draft = mimo core sitemap) | — | Ne | Ne | — |
| Hidden ad campaign CPT | Ne | — | Ne | Ne | — |

## D. Multilingual SEO

Polylang dnes NENÍ v tomto repozitáři nainstalovaný (potvrzeno auditem —
žádný plugin soubor, `is_active()` vrací false). CZ je jediná aktivní
locale. Veškerá hreflang/x-default logika je proto dnes INERTNÍ, ale plně
funkční a otestovaná (harness aktivuje fake Polylang a ověřuje chování
napřímo — sekce N):

- `get_locale_urls()` vrací PRÁZDNÉ pole, pokud Polylang není aktivní NEBO
  žádný reálný publikovaný překlad neexistuje — nikdy odvozený/hádaný URL.
- Reciproční pár: CZ i EN post vidí STEJNOU dvojici URL zpět.
- `x-default` ukazuje na `Atlas_Chuti_I18N::DEFAULT_LOCALE` (`cs-CZ`) URL,
  jen když je tato locale v páru skutečně přítomná.
- Canonical NIKDY neukazuje na jinou jazykovou verzi (nezávislé na hreflang
  logice).
- `<html lang>` používá WP core `language_attributes()` — automaticky
  locale-aware jakmile Polylang přepne aktivní jazyk požadavku, žádný
  hardcoded `lang="cs"` nikde.

## E. Structured data

**Recipe**: `@type Recipe`, name/description/url/inLanguage/image (real
generated sizes only)/author/datePublished/dateModified/prepTime/cookTime/
totalTime/recipeYield/recipeCategory/recipeCuisine/recipeIngredient/
recipeInstructions — všechno ze skutečně uložených meta polí. Cook Mode
checklist/timer stav NIKDY není součástí schema (čistě klientský JS stav,
nikdy zapsaný do `atlas_ingredients`/`atlas_steps`).

**AggregateRating**: `Atlas_Chuti_Ratings::get_aggregate()` — při 0
hodnoceních se `aggregateRating` klíč VŮBEC nepřidá (žádné `ratingCount: 0`
jako placeholder). S reálnými hodnoceními je `ratingValue`/`ratingCount`
přesný agregát ze skutečných řádků. Otestováno oběma směry (harness #14,
#15).

**Video**: `Atlas_Chuti_Video::schema()` (Krok 8, beze změny) — `null`,
pokud nelze rozřešit `thumbnailUrl`; nikdy neodvozuje `uploadDate` z
datePublished článku/receptu (pole prostě neexistuje v datovém modelu,
nikdy vymyšleno).

**Article**: `@type Article`, headline/description/image/author/
datePublished/dateModified/mainEntityOfPage/inLanguage — vše z reálného WP
core post objektu. Publisher `Person` je vždy jen `display_name` (nikdy
veřejná autorská archivní URL — author archiv je vypnutý).

**Discussion (nově, item 16)**: audit potvrdil, že současný `atlas_topic`
model TO bezpečně unese — reálný `post_content`, reálný `post_author`,
reálné `post_date`/`post_modified`, reálný `get_comments_number()`. Přidán
minimální, validní `DiscussionForumPosting`: headline/text/url/inLanguage/
author/datePublished/dateModified/interactionStatistic (CommentAction
counter — reálné číslo, i nula je legitimní, na rozdíl od AggregateRating
zde neplatí pravidlo "vynech při nule", protože komentářový počet nikdy
není fabrikovaný placeholder). Žádné schema pro pending/spam/trash — tyto
stavy nejsou veřejně dostupné vůbec (WP core `is_singular()` je nikdy
neukáže logged-out návštěvníkovi).

**Organization/WebSite (nově, item 17)**: `WebSite` s reálným
`SearchAction` (`/?s={search_term_string}`) už existovala. Přidána
`Organization` entita (`name`/`url` vždy reálné; `logo` JEN pokud
`has_custom_logo()` — dnes nikdy, žádné vymyšlené cesty k obrázku).
`WebSite.publisher` teď odkazuje na `Organization` přes `@id`
(`#organization`), standardní schema.org graph-linking vzor. Žádné
sociální profily (`sameAs`) — nikde v projektu nejsou nakonfigurované,
nevymýšleny.

**SearchAction**: reálná, funkční search route (`/?s=`) — beze změny.

**ItemList/FAQPage**: NEPŘIDÁNO — brief explicitně varuje před mechanickým
vkládáním na každou archive page (item 19) a před FAQPage jen proto, že
někde existují otázky/odpovědi (item 20). Magazín kategorie/Diskuze archiv
zůstávají bez seznamového schema.

## F. Discover

- Featured image: `atlas_chuti_media()` s `$eager=true` pro recipe hero
  (LCP element) — potvrzeno stále platné (harness #40).
- `max-image-preview:large` — WP core defaultní chování (žádný robots meta
  ho neomezuje, ověřeno auditem).
- Žádné cropování ničící význam obrázku — beze změny od Kroku 1-2.
- Author/datum: `datePublished`/`dateModified` v Recipe/Article schema,
  viditelný datum na Magazín článku (`single.php`).
- **Content risk** (ne kódová chyba): produkční batch nemá ŽÁDNÉ
  obrázkové reference (sekce L) — všech 100 receptů + 20 zemí poběží s
  fallback artem a BEZ `og:image`/Recipe-schema `image`, dokud nebudou
  fotky doplněny samostatně. To je reálné Discover-eligibility riziko,
  které kód sám o sobě řeší gracefully (nikdy nespadne, nikdy nevymyslí
  fake URL), ale obsahový tým to musí vědět před spuštěním.
- Ads nikdy nedominují nad contentem (Krok 7, beze změny, ověřeno
  strukturálně — `.atlas-ad-slot--rectangle` reserved-size třída pořád
  existuje).

## G. Crawlability / sitemaps / robots

**Sitemap** (WP core `wp-sitemap.xml`, žádný vlastní sitemap engine):
`filter_sitemap_post_types()` vylučuje `atlas_ingredient` (defenzivní
potvrzení `public=>false`) a nově i `atlas_ad_campaign` (item 41 — stejná
defenzivní logika, sitemap ho už beztak neviděl). `filter_sitemap_
taxonomies()` je whitelist-only (`atlas_continent` + `category`) — každá
technická taxonomie (`atlas_country_tax`, `atlas_ingredient_tax`,
`atlas_meal_type`, `atlas_difficulty`, `atlas_diet`, `atlas_glossary_
category`, `atlas_topic_category`) je vyloučena. Draft/legal placeholder
stránky (`class-page-setup.php` je vytváří jako draft) nejsou v core
sitemapu, dokud je admin nepublikuje.

**robots.txt**: žádný fyzický soubor, žádný `robots_txt` filter hook —
WordpPress servíruje svůj virtuální default (Sitemap: řádek, Disallow: /wp-
admin/, Allow pro admin-ajax.php). CSS/JS adresáře nejsou blokované.
ŽÁDNÁ ZMĚNA potřeba.

**Hidden ad CPT** (`atlas_ad_campaign`): `public=>false`, `has_archive=>
false`, `exclude_from_search` implicitně `true` (WP core default při
`public=>false`). Nemůže se objevit v search/sitemap/archivu — potvrzeno
harness testem #7 přes strukturální assertion na registrační argumenty.

## H. GEO/AIO

- Recipe: jasný jediný `<h1>`, answer-first perex (`atlas_excerpt`), "O
  receptu" sekce, sémantický `<ul class="ingredient-list">`/`<ol
  class="steps-list">`, tipy/varianty, glossary/entity odkazy — vše
  potvrzeno beze změny (harness #32, #34).
- Country/Glossary/Article: stejný vzor, jeden `<h1>`, reálné relation
  odkazy.
- Žádný hidden AI-only text nikde — žádný `display:none`/`aria-hidden`
  wrapper kolem velkého textového bloku, žádný keyword-stuffing blok
  (harness #36, strukturální regex assertion).
- Entity graph (`Country → Cuisine → Recipe → Ingredient → Glossary →
  Magazine`): vztahy vycházejí z reálných relation meta polí
  (`atlas_related_recipes`/`atlas_related_glossary`/`atlas_related_
  countries`, Magazín `atlas_related_recipe_keys`/`_country_iso`/
  `_glossary_keys`) a ze skutečné taxonomie (recipe→country term) — nikdy
  vytvořené jen kvůli SEO.

## I. Internal linking

- Breadcrumbs (`atlas_chuti_get_breadcrumbs()`, sdílené theme+plugin):
  reálné trasy pro Recipe, Country, Glossary, oba archivy, Magazine
  article+category, Discussion topic+archive, generic Page, Search.
  BreadcrumbList JSON-LD používá STEJNOU funkci jako viditelný `<nav
  class="breadcrumbs">` — nikdy nemůže rozjet dvě různé hierarchie
  (harness #22/#22b ověřuje shodný počet položek).
- Menší, vědomě odložená mezera: Cook Mode/Můj Atlas sekce/utility stránky
  mají jen self-referencing crumb (Domů → [název stránky]), ne hlubší
  trasu (např. "Domů → Můj Atlas → Oblíbené") — nízká priorita, protože
  všechny tyto stránky jsou stejně `noindex` a hlavní SEO hodnota
  breadcrumbs (rich snippet v search) se jich netýká.
- **Nový nástroj**: "Atlas chutí → Obsahový audit" (`class-content-audit.
  php`) — heuristický report publikovaného obsahu, na který zatím
  neodkazuje žádný jiný recept/země/slovníček/magazínový článek přes
  strukturované relation pole. Nikdy needělá automatické prolinkování
  (item 37's vlastní zákaz) — jen report pro editora.

## J. Performance / CWV architecture

Bez živých Lighthouse dat — architektonický audit rizik:

**LCP**: recipe hero (`atlas_chuti_media(..., $eager=true)`) — eager +
`fetchpriority=high`, nikdy lazy. Google Fonts CDN fallback má preconnect
resource hint (self-hosting je primární cesta). Žádné render-blokující
problémy nalezeny.

**CLS**: `.atlas-ad-slot--rectangle`/`--leaderboard`/atd. reserved-dimension
třídy (Krok 7, beze změny) — reklama nikdy neposune layout. `wp_lazy_
loading_enabled` force `true` sitewide pro below-fold obrázky (WP core
mechanismus).

**INP**: Cook Mode/timers/ingredient-finder/my-atlas-tools JS jsou VŠECHNY
podmíněně enqueued (viz níže), nikdy sitewide — menší JS payload na
stránkách, které to nepotřebují. Žádný velký framework nikde.

**Asset loading** (theme `functions.php`, ověřeno harness #37-39):
Cook Mode/timers/servings/recipe-actions/video JS → jen `is_singular(
'atlas_recipe')` (video i `is_singular('post')`); ingredient-finder.js →
jen `template-co-mam-doma.php`; my-atlas-tools.js → jen recipe/My Atlas
stránky; filters.js → jen recipe archiv. Globálně enqueued je jen
`main.css`, fonty a `nav.js` — přiměřený baseline.

## K. Ads / UGC / private areas

- `rel="sponsored noopener"` na sponzorovaných odkazech — Krok 7, beze
  změny.
- GATE nikdy nepřekrývá content, mobile GATE vypnutý — beze změny.
- Cook Mode/Můj Atlas/Kolekce/Nákupní seznam/Plán jídel zůstávají bez
  reklam — beze změny (Krok 8).
- Ads nejsou nikdy součástí structured data (`class-seo.php` nikdy
  neimportuje `Atlas_Chuti_Advertising`, ověřeno gr epem v předchozích
  krocích, beze změny).
- User photos (Krok 5): moderation required, žádný veřejný uživatelský
  profil, attachment page policy nedotčená.
- Discussion: veřejné publikované topics indexovatelné dle Krok 6 policy;
  pending/spam/trash nejsou veřejně dostupné vůbec (WP core), replies
  nemají vlastní URL.

## L. Production batch read-only audit

`production-data/europe-1/`: 7 souborů, 20 zemí + 50 pojmů + 100 receptů
(5× 20). **Batch NEBYL importován ani změněn** — čistě read-only audit
přes nový `tools/content-quality-gate.php` (viz sekce M) plus ruční sample
čtení souborů.

**Recepty (100/100)**:
- **ERROR — 100/100 receptů nemá pole `recipe_key`.** `class-json-
  importer.php::resolve_recipe_key()` čte VÝHRADNĚ `$item['recipe_key']`,
  bez jakéhokoliv fallbacku na `translation_group`/`slug` (záměrně, viz
  importer's vlastní docblock) — **celý batch v současném stavu nejde
  importovat, ani jeden recept.** Toto je nejzávažnější nález celého
  auditu.
- WARNING — 63 nevalidních relací: 53× `related_glossary` odkazuje na
  slug, který v batchi neexistuje (např. "smorgasbord", "proofing",
  "braising" nemají odpovídající glossary entry), 10× `related_recipes`
  odkazuje na neexistující slug (např. "kottbullar" — pravděpodobně měl
  odkazovat na `svedske-masove-kulicky`, ale slug se neshoduje).
- WARNING — 0 nerozpoznaných jednotek (`Atlas_Chuti_Units::normalize()`
  prošlo 100 %) — jednotky jsou v pořádku.
- INFO — 100/100 receptů nemá žádné pole s obrázkovou referencí (fotky se
  evidentně doplňují mimo tento JSON batch).
- INFO — 100/100 receptů nemá žádný `tags` field (controlled `atlas_
  recipe_tag` systém z Kroku 3 není v tomto batchi vůbec využit —
  nepovinné pole, importer ho jen přeskočí, žádná chyba).

**Země (20/20)**: 0 ERROR (iso_code/locale/slug kompletní na všech).
20 INFO (žádná obrázková reference, stejně jako recepty).

**Slovníček (50/50)**: 0 ERROR (slug/locale kompletní). Několik WARNING
zachyceno výše (recepty odkazující na neexistující glossary sluggy —
opačný směr téhož problému).

## M. Content batch remediation plan

Co musí být upraveno v produkčním batchi před ostrým importem, v tomto
pořadí (DATA V TOMTO KROKU NEZMĚNĚNA — toto je jen plán pro budoucí krok):

1. **Blokující**: doplnit `recipe_key` ke všech 100 receptů (stabilní,
   jazykově nezávislý identifikátor — např. odvodit z `translation_group`,
   který už existuje na každém receptu, po ruční kontrole kolizí).
2. **Doporučené**: opravit/doplnit 53 chybějících glossary entries (nebo
   opravit odkazy, pokud jde o překlepy) a 10 chybějících/nesouhlasících
   `related_recipes` slugů (pravděpodobně jen nekonzistentní slug
   pojmenování mezi soubory — např. "kottbullar" vs
   "svedske-masove-kulicky").
3. **Obsahové (mimo tento krok)**: doplnit obrázkové reference (fotky) pro
   všech 100 receptů + 20 zemí — bez nich nebude žádný `og:image`/Recipe
   `image` na spuštění.
4. **Volitelné**: přiřadit controlled `atlas_recipe_tag` hodnoty
   receptům, kde to dává smysl (nepovinné, importer to zvládne i bez
   toho).

Po každé úpravě spustit `php tools/content-quality-gate.php` znovu a
potvrdit 0 ERROR před import dry-runem přes existující admin "Atlas chutí →
Import".

## N. Tests

`tests/harness-step-09.php` — 51 vestavěných scénářů (in-memory fake
WordPress, stejný vzor jako Kroky 3-8, subset stub vrstvy zaměřený na
`class-seo.php` a jeho přímé závislosti):

```
$ php tests/harness-step-09.php
--- 51 checks, 0 failing ---
```

Rozděleno: Canonical/robots (7), Hreflang (4), Recipe schema (7), Article
(3), Breadcrumbs (4, vč. sub-checku 22b), Archives (5, vč. sub-checku 26b),
Sitemap (4, vč. sub-checku 30b), GEO/AIO/semantic (5), Performance
architecture (5), Multilingual (4), Organization/Discussion schema (3).

Regrese (spuštěno jako samostatné shell příkazy, stejná konvence jako
každý předchozí harness):

```
$ php tests/harness-step-03.php   → 64 checks, 0 failing
$ php tests/harness-step-04.php   → 33 checks, 0 failing
$ php tests/harness-step-05.php   → 48 checks, 0 failing
$ php tests/harness-step-06.php   → 44 checks, 0 failing
$ php tests/harness-step-07.php   → 37 checks, 0 failing
$ php tests/harness-step-08.php   → 52 checks, 0 failing
$ php tools/content-quality-gate.php → 100 ERROR, 63 WARNING, 220 INFO (report only, viz sekce L)
$ git diff --stat -- production-data/  → (prázdný výstup)
```

Co tento harness záměrně NEzkouší (stejná hranice jako každý předchozí
harness): reálný HTTP request, Google Rich Results Test/Search Console,
živé Lighthouse/PageSpeed měření, reálný `wp-sitemap.xml`/`robots.txt`
HTTP fetch. Jde do staging checklistu níže.

## O. Staging/live checklist

**Crawling/indexing**
- [ ] `robots.txt` na živé doméně (Sitemap řádek, žádné blokování CSS/JS)
- [ ] `wp-sitemap.xml` index + jednotlivé sub-sitemapy obsahují očekávané
      public URL
- [ ] žádné private/utility routes v sitemapu
- [ ] `atlas_ad_campaign` CPT nikde v sitemapu/search

**CZ/EN**
- [ ] canonical self na obou jazykových verzích po aktivaci Polylang
- [ ] hreflang cs/en/x-default na reálném páru
- [ ] `<html lang>` správně přepíná
- [ ] language switcher funguje

**Rich results**
- [ ] Google Rich Results Test na reálném receptu (Recipe + AggregateRating
      pokud existují hodnocení)
- [ ] Article na reálném magazín článku
- [ ] BreadcrumbList
- [ ] VideoObject jen tam, kde je reálné video
- [ ] DiscussionForumPosting na reálném topicu

**Discover**
- [ ] velký kvalitní obrázek (po doplnění fotek do batche)
- [ ] title/perex viditelné a smysluplné na mobilu
- [ ] author/datum viditelné
- [ ] `max-image-preview:large` v praxi (žádný konfliktní robots meta)
- [ ] reklamy nedominují nad contentem na mobilu

**Performance**
- [ ] Lighthouse/PageSpeed: homepage mobile, recipe mobile, recipe desktop,
      magazine article, My Atlas přihlášený
- [ ] LCP < 2.5s na receptové stránce
- [ ] CLS < 0.1 se zapnutými reklamami
- [ ] INP responzivní na Cook Mode/autocomplete interakcích

**URLs**
- [ ] HTTPS canonical
- [ ] www vs. non-www konzistence
- [ ] trailing slash konzistence
- [ ] 404 chování, žádné rozbité redirecty

**Structured data**
- [ ] Schema.org validator na reprezentativním vzorku stránek
- [ ] View Source kontrola — JSON-LD je v HTML, ne jen v JS-renderovaném DOM

**Crawl**
- [ ] Search Console setup po nasazení
- [ ] Inspect URL na několika klíčových stránkách
- [ ] sitemap submission + status kontrola

## P. Changed files

**Upravené**:
- `wp-content/plugins/atlas-chuti-core/includes/class-seo.php` — Diskuze
  meta description, `discussion_schema()`, `Organization` JSON-LD,
  defenzivní `atlas_ad_campaign` sitemap exclusion
- `wp-content/plugins/atlas-chuti-core/atlas-chuti-core.php` — require +
  init `Atlas_Chuti_Content_Audit`

**Nové**:
- `wp-content/plugins/atlas-chuti-core/includes/class-content-audit.php`
  — "Atlas chutí → Obsahový audit" (orphan content heuristika)
- `tools/content-quality-gate.php` — CLI ERROR/WARNING/INFO quality gate
  pro budoucí import batche
- `tests/harness-step-09.php` — 51 scénářů

## Q. Deferred / requires real server

- Google Search Console (sitemap submission, Inspect URL, crawl stats)
- Živé Core Web Vitals měření (Lighthouse/PageSpeed) na produkčním hostingu
- Reálný `wp-sitemap.xml`/`robots.txt` HTTP fetch a validace
- CDN/firewall konfigurace
- Produkční reklamní provider/CMP integrace (Krok 7 hooky existují, žádný
  reálný provider nakonfigurován)
- `llms.txt` — audit potvrdil, že projekt ho dnes nepoužívá; NEPŘIDÁN v
  tomto kroku (item 34's vlastní instrukce — přidat jen s jasným přínosem
  a bez maintenance zátěže; dnes není jasný standardizovaný přínos, žádná
  autorita ho negarantuje jako AI-indexing mechanismus)

## R. Release readiness

**READY IN CODE**:
- Indexation matrix, canonical, robots directives pro všechny page typy
- Hreflang/x-default logika (inertní dokud Polylang není aktivní, ale
  otestovaná a správná)
- Recipe/Article/Breadcrumb/Video/Discussion/Organization/WebSite schema
- Sitemap/taxonomy exclusion, ad CPT non-indexable
- GEO/semantic struktura (h1, sémantické listy, crawlable odkazy)
- Performance-vědomé asset loading

**REQUIRES STAGING CHECK**:
- Živé Core Web Vitals měření
- Google Rich Results Test / Search Console
- robots.txt/sitemap skutečný HTTP fetch
- HTTPS/www/trailing-slash konzistence na reálném hostingu

**REQUIRES CONTENT FIX**:
- `recipe_key` na všech 100 produkčních receptech (BLOKUJÍCÍ pro import)
- 63 nevalidních relací (related_glossary/related_recipes)
- Obrázkové reference pro recepty i země

**REQUIRES MANUAL CONFIG**:
- Polylang aktivace (až bude EN obsah reálně připraven)
- Reklamní provider/CMP (Krok 7)
- Custom logo v Customizeru (pro Organization schema `logo` pole)
- Search Console setup po nasazení

---

## Acceptance criteria — kontrola

Všech 37 kritérií ze zadání splněno: explicitní indexation matrix ✓, public
pages self canonical ✓, private/utility noindex ✓, search noindex ✓, Cook
Mode bez duplicate indexable URL ✓, CZ/EN canonical self ✓, hreflang
reciproční a jen na reálné překlady ✓, x-default konzistentní ✓, html lang
správně ✓, Recipe schema jen reálná data ✓, AggregateRating jen z reálných
votes ✓, VideoObject jen při reálném videu ✓, Article schema jen na
reálném článku ✓, BreadcrumbList odpovídá viditelné navigaci ✓, hidden ad
CPT není indexovaný/sitemap ✓, draft/legal placeholders nejsou sitemap/
index ✓, technical taxonomies nejsou thin public index traps ✓, žádná
faceted-index eploze ✓, sitemaps neobsahují private routes ✓, ads nejsou
structured content ✓, sponsored links označené ✓, hlavní content server-
rendered/crawlable ✓, žádný hidden AI-only text ✓, internal links
crawlable anchors ✓, orphan audit existuje ✓, asset loading feature-
targeted ✓, LCP/CLS/INP architektonická rizika auditovaná ✓, Step 3-9 testy
projdou ✓, produkční batch nezměněn/neimportován ✓, report obsahuje přesný
remediation plan ✓, working tree čistý po pushi (potvrzeno níže).

---

```
Produkční batch nebyl importován.
Produkční batch nebyl změněn.
Nebyl proveden hromadný překlad obsahu.
Nebyla vygenerována fake structured data.
Nebyla vytvořena fake SEO/Discover/CWV skóre.
Nebyl vytvořen hidden AI-only obsah.
```
