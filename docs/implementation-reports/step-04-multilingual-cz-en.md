# Krok 4 — vícejazyčná infrastruktura CZ + EN

## A. Audit (před změnou)

**Locale infrastruktura** (`class-i18n.php`): `Atlas_Chuti_I18N` už od Kroku 1
existovala jako „language-readiness" vrstva — `atlas_locale`/`atlas_translation_group`/
`atlas_translation_status` meta, `current_locale()` jako jediný zdroj pravdy o
aktuálním jazyce, `ensure_i18n_meta()` jako backfill při každém uložení,
`find_country_by_iso()`/`find_by_translation_group()`/`find_ingredient_by_key()` jako
locale-scoped lookupy. Chybělo: skutečné napojení na Polylang (bridge existoval jako
soubor, ale nikdy nebyl instancován — `Atlas_Chuti_Polylang_Bridge::instance()` se
nikde nevolalo), uzavřená množina podporovaných locale (validace byla jen tvarová
regex, ne skutečný closed-set), `post` (budoucí Magazín) nebyl v `LOCALIZED_POST_TYPES`,
a `recipe_key` (Krok 3) sdílel storage s `translation_group` místo aby byl skutečně
samostatné pole (viz sekce D).

**Polylang bridge** (`class-polylang-bridge.php`): existoval s `is_active()`,
`current_locale()`, `assign_language()`, `link_translations()`,
`locale_to_slug()`/`slug_to_locale()` — vše správně `function_exists()`-guardované, ale
**nikdy nepoužívané** (importer nikdy nevolal `assign_language()`, žádný CPT/taxonomy
nebyl registrovaný jako Polylang-translatable, žádný template neřešil switcher).

**CPT/taxonomy registrace** (`class-post-types.php`, `class-taxonomies.php`): žádná
z nich nebyla napojená na `pll_get_post_types`/`pll_get_taxonomies` filtry — kdyby se
Polylang nainstaloval tak, jak byl kód předtím, spravoval by jen `post`/`page`
nativně a admin by musel ručně zaškrtnout `atlas_recipe`/`atlas_country`/
`atlas_glossary` v Nastavení → Jazyky (a stejně by nevěděl, že `atlas_ingredient` a dvě
technické taxonomie tam záměrně NEPATŘÍ — viz sekce D).

**Header/nav** (`header.php`, `inc/template-tags.php`): žádný language switcher
neexistoval.

**SEO** (`class-seo.php`): žádný hreflang, žádný `og:locale`, žádné `inLanguage`
ve schema. Canonical/breadcrumbs/sitemap jsou postavené na `get_permalink()`/
`get_post_type_archive_link()`/WP core funkcích, které Polylang transparentně
filtruje, jakmile je CPT registrovaný jako translatable — **žádná změna zde nebyla
potřeba** (ověřeno čtením celého souboru).

**Search** (`class-search.php`): už plně locale-scoped — jak vlastním raw-SQL JOINem na
`atlas_locale` v `search()`, tak generickým `pre_get_posts` omezením v
`restrict_search_post_types()`. Žádná změna nebyla potřeba.

**Fallback images** (`inc/fallback-images.php`, `functions.php`): řeší se čistě podle
`$context` řetězce + `_atlas_country_continent` meta, nikdy podle lokalizovaného
titulku. Žádná změna nebyla potřeba.

**Passport** (`assets/js/passport.js`): localStorage klíčovaný `recipe_key||slug` /
`iso||slug` — identita je locale-independentní od začátku; jediná změna byla ve
zdroji, odkud `single-atlas_recipe.php` tento `recipe_key` čte (viz sekce H).

**Šablony** (`single-*.php`, `archive-*.php`): žádný natvrdo zapsaný český text — vše
přes `__()`/`_e()`/`esc_html_e()`/`esc_attr_e()`. Žádná změna nebyla potřeba.

**Sitemap**: žádný vlastní sitemap kód neexistuje nikde — web se spoléhá výhradně na
WP core `wp-sitemap.xml`, filtrovaný jen `class-seo.php`'s
`filter_sitemap_post_types()`/`filter_sitemap_taxonomies()`. Žádná změna nebyla
potřeba.

**Importer** (`class-json-importer.php`, ~1900 řádků): `resolve_item_locale()` a
`is_valid_locale()` používaly volnou BCP-47-tvarovou regex (`/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/`),
která by **chybně přijala** např. `"de-DE"` jako platné. `apply_i18n_meta()` zapisoval
`atlas_locale` bez normalizace — JSON s `"locale": "cs_CZ"` (podtržítko) by se uložil
doslovně a nikdy by se neshodl s `current_locale()`'s `"cs-CZ"` v žádném pozdějším
`meta_query` lookupu (latentní bug, opraveno — viz sekce E). `recipe_key` a
`translation_group` sdílely jedno meta pole (`atlas_translation_group`) místo dvou
oddělených — přesně to, co zadání v příkladu `recipe_key: spaghetti_carbonara` vs.
`translation_group: recipe_spaghetti_carbonara` explicitně žádá oddělit.

## B. Zvolená multilingual architektura

**Jedna instalace, jedna databáze, jeden theme, jeden core plugin** — beze změny od
`class-i18n.php`'s stávající dokumentace. atlaschuti.cz (`cs-CZ`) zůstává výchozí bez
`/cs/` prefixu; `atlaschuti.cz/en/...` (nebo budoucí `atlaschuti.com`) je druhá
locale ve STEJNÉ instalaci. Polylang (Free) je jediný multilingual engine, integrovaný
výhradně přes `class-polylang-bridge.php` — každé volání Polylang funkce je
`function_exists()`-guardované, web tedy funguje identicky s Polylang i bez něj.

**Interní reprezentace locale — vědomé rozhodnutí, NEpřejmenováno na `cs_CZ`/`en_US`:**
zadání ve svém příkladu píše `cs_CZ`/`en_US` (podtržítko, WP-core styl), ale tento
kód už od Kroku 1 všude interně používá `cs-CZ`/`en` (pomlčka) —
`Atlas_Chuti_I18N::DEFAULT_LOCALE`, celá `Atlas_Chuti_Taxonomy_Labels::LABELS` tabulka,
`Atlas_Chuti_Units::canonical_units()`/`label()`, a **úplně všechna** existující
sample-data i produkční JSON data používají `"locale": "cs-CZ"`. Přejmenování by byla
široká, čistě kosmetická změna s reálným rizikem (dotkla by se mnoha už otestovaných
souborů Kroku 1–3) bez funkčního přínosu — Polylang samo se totiž vždy oslovuje jen
přes svoje vlastní dvoupísmenné slugy (`cs`/`en`, viz `LOCALE_TO_SLUG` v bridge), nikdy
přes tento interní tag, takže žádná reálná interoperabilita přejmenování nevyžaduje.

Místo přejmenování byla přidána normalizační vrstva:
`Atlas_Chuti_I18N::normalize_locale( $raw )`, která přijímá OBĚ varianty zápisu
(`cs_CZ`/`cs-CZ`/`cs` → `'cs-CZ'`; `en_US`/`en-US`/`en_GB`/`en-GB`/`en` → `'en'`) jako
platný JSON vstup a normalizuje je na kanonickou interní hodnotu ještě před jakýmkoli
uložením/porovnáním. Zadání je tak splněno doslovně (jeho přesné JSON příklady
fungují), bez rizika přejmenování. `Atlas_Chuti_I18N::SUPPORTED_LOCALES = array( 'cs-CZ', 'en' )`
je nová uzavřená množina — cokoli jiného je tvrdá validační chyba.

## C. URL model

| Typ stránky | CZ URL (výchozí, beze změny) | EN URL |
|---|---|---|
| Homepage | `/` | `/en/` |
| Detail receptu | `/recepty/{slug}/` | `/en/recepty/{slug}/` |
| Archiv receptů | `/recepty/` | `/en/recepty/` |
| Detail země | `/zeme/{slug}/` | `/en/zeme/{slug}/` |
| Slovníček | `/slovnik/{slug}/` | `/en/slovnik/{slug}/` |
| Systémové stránky (pas, o projektu…) | dle `atlas_chuti_system_url()` | reálný Polylang překlad dané WP Page, jinak CZ fallback |

**Omezení (Polylang Free), zdokumentováno přesně, ne obcházeno hackem:** Polylang Free
neumí přeložit REWRITE/ARCHIVE BASE SLUG vlastního CPT (`/recepty/` by se na anglické
straně nezměnilo na `/recipes/`) bez Polylang Pro nebo křehkého ručního rewrite-rule
hacku. Zadání takový hack výslovně zakazuje. **Zvolené řešení:** anglické URL
receptů/zemí/slovníčku si ponechávají český základní slug, jen s `/en/` prefixem
(`/en/recepty/{slug}/`) — stabilní, funkční, nikdy rozbité URL, přesně to, co zadání
v bodě 2 dovoluje („zachovej stabilní funkční URL, v reportu přesně popiš omezení").
Kosmeticky přeložený base slug (`/en/recipes/`) by vyžadoval Polylang Pro nebo
vlastní rewrite engine — mimo rozsah tohoto kroku.

## D. Identity model

| Pole | Role | Sdílené napříč CZ/EN? | Odvozeno z titulku? |
|---|---|---|---|
| `atlas_recipe_key` | **Stabilní identita KONCEPTU JÍDLA** (např. `spaghetti_carbonara`) — dedup, Kulinářský pas, budoucí CZ↔EN párování | ✅ ano, záměrně stejná hodnota | ❌ nikdy, tvrdě validováno (Krok 3B) |
| `atlas_translation_group` | **Samostatné, volitelné** pole pro křížovou kontrolu s Polylang vlastní translation relací (viz níže) | zpravidla ano, ale NENÍ vynucováno | ne, ale defaultuje na `recipe_key` když chybí |
| `atlas_iso_code` | Identita ZEMĚ | ✅ ano (`IT` = Itálie i Italy) | ne |
| `atlas_ingredient_key` | Identita ingredience ve slovníku | ✅ ano (`tomato` = rajče i tomato) | ne |
| Řízené štítky (`atlas_recipe_tag` aj.) | technický klíč termu (`traditional`) | ✅ ano, klíč sdílený | popisek (label) je lokalizovaný přes `Atlas_Chuti_Taxonomy_Labels` |
| `atlas_locale` | Která konkrétní post-verze | ❌ ne, per-post | — |

**`recipe_key` vs. `translation_group` — teď skutečně dvě oddělená meta pole**
(dřív sdílela storage, viz sekce A). `class-json-importer.php`'s `import_recipe()`
teď zapisuje obě zvlášť, už v `meta_input` (ne až po insertu — viz sekce E, proč na
tom pořadí záleží). Nová `Atlas_Chuti_I18N::find_by_recipe_key( $key, $locale )`
zrcadlí `find_by_translation_group()`, jen čte `atlas_recipe_key`; importer teď
recepty vyhledává/dedupuje výhradně přes ni (`find_existing_recipe()`,
`resolve_reference()`, `resolve_recipe_refs()`).

**Křížová kontrola s Polylang** — nová `link_recipe_translations( $recipe_key )`
(volaná z `import_recipe()` po každém úspěšném zápisu/no-op): když v dávce (nebo
v DB) existují OBĚ locale verze stejného `recipe_key` a Polylang je aktivní,
porovná se jejich `atlas_translation_group`. Shodují-li se, zavolá se
`Atlas_Chuti_Polylang_Bridge::link_translations()` (propojí je jako Polylang
překlady). Liší-li se, vrátí se česká warning zpráva do reportu řádku ("translation_group
se liší mezi jazykovými verzemi… oprav ručně") — **nikdy se potichu nepřepíše ani
nevybere jedna hodnota** (přesně bod 5 zadání).

## E. Importer

- `SUPPORTED_LOCALES` closed-set validace (`Atlas_Chuti_I18N::normalize_locale()`)
  nahradila volnou regex — `"cs_CZ"`/`"cs-CZ"`/`"cs"` i `"en_US"`/`"en-US"`/`"en_GB"`/
  `"en"` se normalizují na tutéž interní hodnotu; cokoli jiné (např. `"de-DE"`) je
  tvrdá chyba `"locale (podporováno: cs_CZ / cs-CZ, en_US / en)"`.
- **Opravený latentní bug**: `apply_i18n_meta()` teď normalizuje `$item['locale']`
  před zápisem (dřív ukládal syrovou hodnotu — `"cs_CZ"` by se uložilo doslovně a
  nikdy by neprošlo pozdějším `meta_query` lookupem).
- **`atlas_recipe_key` musí být v `meta_input` už při `wp_insert_post()`, ne až po
  něm** — `Atlas_Chuti_I18N::ensure_i18n_meta()` běží synchronně na
  `save_post_atlas_recipe` hooku, který TENTO `wp_insert_post()` vyvolá; kdyby pole
  bylo prázdné v tu chvíli, vlastní backfill `ensure_i18n_meta()` by ho nastavil na
  slug (špatný default) dřív, než by k tomu dostal šanci jakýkoli kód po insertu.
  Totéž pro `atlas_translation_group` — proto obě pole (s `!empty($item[...]) ? … : $stable_key`
  fallback logikou) jsou teď přímo v `meta_input`, ne v dodatečném kroku.
- **`assign_language()`** — `import_recipe()`/`import_country()`/`import_glossary()`
  teď po zápisu volají `Atlas_Chuti_Polylang_Bridge::assign_language( $post_id, $locale )`
  (no-op bez Polylang). `atlas_ingredient` záměrně NE — má vlastní cross-locale
  mechanismus (`ingredient_key` + locale), zdvojení identity by konfliktovalo (viz
  `class-polylang-bridge.php`'s `register_post_types()` docblock).
- **Same-batch CZ+EN párování** — beze změny v mechanismu (`build_planned_index()`,
  dependency-ordered create → resolve pass), ale nyní ověřeno testy (harness-step-04.php,
  scénář 10): recept v jedné locale se v téže dávce správně naváže na zemi VYTVOŘENOU
  VE STEJNÉ DÁVCE pro TUTÉŽ locale, nikdy ne na druhou jazykovou verzi.
- **Idempotence beze změny principu**: `wp_insert_post()` (a tedy `post_modified`)
  se volá jen když `$unchanged === false`; CZ update nikdy nezapíše do EN postu (jsou
  to different post ID od začátku) — ověřeno testem 15.

## F. Frontend

- **Language switcher** (`inc/template-tags.php`'s `atlas_chuti_language_switcher()`,
  vykreslovaný z `header.php` v `.mobile-nav-account` i `.header-tools`): vrátí prázdno,
  pokud `!Atlas_Chuti_Polylang_Bridge::is_active()` nebo je jen jedna locale k
  nabídnutí — konzistentní s existujícím vzorem "brzy" placeholderů z Kroku 1. Aktivní
  locale je non-interaktivní `<span aria-current="true">`, ostatní jsou skutečné
  `<a href>` odkazy — nikdy disabled/fake control. Když pro aktuální singulární
  post/term neexistuje PŘESNÝ překlad, odkaz vede na domovskou stránku cílové locale
  (`pll_home_url()`) s `title` atributem vysvětlujícím fallback — vždy reálná,
  klávesnicí/čtečkou obrazovky použitelná adresa, nikdy rozbitý/fake odkaz (bod 8
  zadání to výslovně povoluje).
- **`atlas_chuti_system_url()`** (Kulinářský pas, Země, právní stránky…) — na výchozí
  locale nebo bez Polylang vrací přesně stejnou českou URL jako dřív (nulová změna
  chování). Na jiné locale s aktivním Polylang zjistí, zda existuje reálný Polylang
  překlad dané WP Page (vytvořené přes „Atlas chutí → Nastavení stránek",
  `class-page-setup.php`), a pokud je publikovaný, vrátí jeho permalink — jinak
  spadne zpět na českou URL. Nikdy neuhodne/nevytvoří URL.
- Ostatní šablony beze změny — audit (sekce A) potvrdil, že žádný natvrdo zapsaný
  český text v nich není.

## G. SEO

- **hreflang** (`class-seo.php`'s nová `get_locale_urls()` + `output_hreflang()`):
  emitováno jen když je Polylang aktivní A aktuální stránka má aspoň jeden SKUTEČNÝ,
  publikovaný Polylang překlad (nikdy pro osamocenou stránku bez páru — to by nebyl
  multilingual signál, jen šum). Reciproční ze své podstaty (obě strany čtou ze
  STEJNÉ Polylang translation relace). `x-default` ukazuje na výchozí (cs-CZ) URL.
- **`og:locale`** — vždy emitováno (popisuje aktuální request, funguje i bez
  Polylang). **`og:locale:alternate`** — jen pro reálně existující překlad (stejný
  zdroj dat jako hreflang). Nová `og_locale_tag()` převádí náš interní tag
  (`cs-CZ`/`en`) na OpenGraph's VLASTNÍ konvenci (`cs_CZ`/`en_US`, podtržítko) —
  **jiná konvence než naše interní**, nikdy prosté str_replace bez mapy.
  Ozkoušeno (harness scénář 22): `cs-CZ` → `cs_CZ`, `en` → `en_US`.
- **`inLanguage`** v Recipe schema — locale DANÉHO postu (`Atlas_Chuti_I18N::get_locale( $post_id )`),
  ne aktuálního requestu, takže schema fetchnuté napříč locale je vždy správně.
- **Canonical/breadcrumbs/sitemap** — beze změny kódu (audit v sekci A potvrdil, že
  jsou postavené na WP core funkcích, které Polylang transparentně filtruje, jakmile
  jsou CPT/taxonomy registrované jako translatable, viz sekce B).

## H. Kulinářský pas / servings / fallback images

- **`single-atlas_recipe.php`**: `passport_data['recipe_key']` teď čte
  `get_post_meta( $post_id, 'atlas_recipe_key', true )` místo dřívějšího
  `atlas_translation_group` — Kulinářský pas teď používá SPRÁVNOU, skutečně
  jazykově-nezávislou identitu (viz sekce D), ne pole, které je teď volitelné a může
  se mezi CZ/EN verzemi lišit. `passport.js`'s localStorage kontrakt (klíčovaný
  `recipe_key||slug`) je beze změny — jen zdroj, odkud PHP tento klíč bere.
- **Servings/unit-conversion** (`class-units.php`) — beze změny, ověřeno testem 30:
  kanonický KLÍČ jednotky (`pcs`) je sdílený, jen LABEL se liší (`ks` vs. `pcs`) —
  logika konverze nikdy nemusí větvit podle jazyka.
- **Fallback images** — beze změny (audit v sekci A), ověřeno testem 29: funkce
  nebere locale parametr vůbec, tedy nemůže podle něj větvit ze své podstaty.

## I. Testy

Nový `tests/harness-step-04.php` (rozšiřuje stejný přístup jako
`harness-step-03.php` — stubuje jen tolik WP API, aby proti němu běžel SKUTEČNÝ,
neupravený kód pluginu) + minimální fake Polylang runtime
(`pll_get_post`/`pll_save_post_translations`/`pll_set_post_language`/
`pll_current_language`/`pll_languages_list`/`pll_home_url`) — dost na to, aby
`class-polylang-bridge.php`'s skutečný kód běžel doopravdy, ne jako mock bridge.
30 číslovaných scénářů + 3 pomocné sub-checky, **0 selhání**:

| Skupina | Počet | Pokrývá |
|---|---|---|
| 1. Validace locale | 4 | `cs_CZ`→`cs-CZ`, `en_US`→`en`, neplatná locale → chyba, chybějící locale → default |
| 2. CZ/EN pár v jedné dávce | 6 | dva samostatné posty, sdílený `recipe_key`, vlastní `locale`, `find_by_recipe_key()`, nezávislý obsah, same-batch resolution zemí |
| 3. Duplicity/identita | 4 | stejný `recipe_key` napříč locale ≠ duplicita, stejný `recipe_key` + STEJNÁ locale = chyba, `translation_group` konflikt → warning (ne přepsání), shoda → Polylang propojení |
| 4. Idempotence | 4 | CZ update se nedotkne EN postu, beze-změny reimport, opakovaný import nevytvoří druhou Polylang skupinu, `recipe_key`/`translation_group` stabilní napříč reimporty |
| 5. Izolace dotazů | 2 | výchozí locale zahrnuje legacy obsah bez `atlas_locale`, jiná locale vyžaduje přesnou shodu |
| 6. SEO data | 4 | `locale_to_slug()`, `og_locale_tag()`, reálný Polylang translation link obousměrně, `inLanguage` zdroj per-post |
| 7. Štítky/ingredience CZ+EN | 3 | sdílený klíč štítku + odlišný label, dva samostatné slovníkové posty pro stejný `ingredient_key`, správné locale-scoped tagování |
| 8. Pas/fallback | 3 | sdílený `recipe_key` napříč CZ/EN, fallback image nezávislý na locale, sdílený unit klíč |

Zpětná kompatibilita: `tests/harness-step-03.php` (64 kontrol, Krok 3/3B) běží beze
změny chování — jen nový `require class-polylang-bridge.php` + jeho `instance()` v
bootstrap sekvenci (Polylang bridge teď MUSÍ být instancovaný, protože má konstruktor
s `add_filter()` voláními — dřív se nikde nevolal `instance()`). **0 selhání.**

**Co tento harness záměrně NEtestuje** (viz sekce M): skutečný HTML výstup
hreflang/canonical (potřebuje `is_singular()`/`get_queried_object()`/WP smyčku),
vykreslení language switcheru, skutečné chování sitemap — všechno potřebuje reálný
WP request kontext, který headless harness nemůže bezpečně předstírat bez
reimplementace `WP_Query`.

**Testovací fixture pár** (`tests/fixtures/step-04-cz-en-pair.json`) — CZ „Špagety
Carbonara" + EN „Spaghetti Carbonara", stejný `recipe_key`/`translation_group` přesně
podle příkladu ze zadání, oddělené `tests/fixtures/`, **produkční batch se nikdy
nedotýká**.

## J. Read-only audit produkčního batche (bez importu, bez úprav)

| Metrika | Zjištění |
|---|---|
| Počet receptů | 100 |
| `recipe_key` přítomen | **0/100** |
| `translation_group` přítomen | 100/100 |
| `locale` hodnota | 100 % `"cs-CZ"` |
| Počet zemí | 20, 100 % `"cs-CZ"` |
| Počet glosář hesel | 50, 100 % `"cs-CZ"` |

**Kritické zjištění:** batch vznikl PŘED Krokem 3B (tvrdý požadavek na `recipe_key`
bez jakéhokoli fallbacku) — žádný z 100 JSON receptů `recipe_key` pole vůbec nemá.
Kdyby se tento batch dnes zkusil (znovu) importovat současným importerem, **všech
100 položek by tvrdě selhalo validací** (`resolve_recipe_key()`'s docblock: „There is
no fallback of any kind left in this method"). Toto NENÍ chyba Kroku 4 — je to
očekávaný důsledek Kroku 3B, teď jen formálně zdokumentovaný.

**Migrační plán (pro budoucí, NE tento krok):** než se batch případně (znovu)
importuje, musí redakční/datový tým doplnit `recipe_key` do všech 100 JSON záznamů
(typicky odvozením ze `slug`, stejně jako by to udělal `ensure_i18n_meta()`'s
wp-admin backfill pro obsah vytvořený přímo v adminu — ale JSON importer sám žádný
takový fallback nemá a mít nebude, to je záměr Kroku 3B). Toto je čistě editorial/data
úkol, mimo rozsah tohoto kroku — production-data adresář zůstal v tomto kroku
nedotčený (viz `git diff --stat -- production-data/` níže).

## K. Změněné soubory

```
wp-content/plugins/atlas-chuti-core/atlas-chuti-core.php           (+1)
wp-content/plugins/atlas-chuti-core/includes/class-i18n.php        (+104/-…)
wp-content/plugins/atlas-chuti-core/includes/class-json-importer.php (+169/-…)
wp-content/plugins/atlas-chuti-core/includes/class-polylang-bridge.php (+136)
wp-content/plugins/atlas-chuti-core/includes/class-register-meta.php (+18)
wp-content/plugins/atlas-chuti-core/includes/class-seo.php         (+105)
wp-content/plugins/atlas-chuti-core/includes/functions.php         (+33/-…)
wp-content/themes/atlas-chuti/assets/css/main.css                  (+14/-…)
wp-content/themes/atlas-chuti/header.php                           (+2)
wp-content/themes/atlas-chuti/inc/template-tags.php                 (+43)
wp-content/themes/atlas-chuti/single-atlas_recipe.php               (+10/-…)
tests/harness-step-03.php                                          (+2, bootstrap only)
tests/harness-step-04.php                                          (nový)
tests/fixtures/step-04-cz-en-pair.json                             (nový)
docs/implementation-reports/step-04-multilingual-cz-en.md          (nový, tento soubor)
```

`production-data/` — **beze změny** (ověřeno `git diff --stat -- production-data/`
před commitem, viz finální výstup).

## L. Manuální kroky pro reálné nasazení Polylang (mimo tento kód)

Tento krok NEINSTALUJE Polylang (soubory pluginu se do tohoto repozitáře nikdy
nesmí commitnout). Až bude anglický obsah skutečně potřeba:

1. `wp-admin → Pluginy → Přidat nový` → nainstalovat a aktivovat **Polylang** (Free).
2. Nastavení → Jazyky → přidat `Čeština` (cs, výchozí, BEZ `/cs/` prefixu — nastavit
   "Skrýt URL jazyka pro výchozí jazyk") a `English` (en).
3. Nastavení → Jazyky → Nastavení URL → zvolit variantu s prefixem `/en/` (ne
   samostatnou doménou, pokud web zůstává na jedné doméně).
4. V Nastavení → Jazyky → Vlastní typy příspěvků a taxonomie by `atlas_recipe`,
   `atlas_country`, `atlas_glossary`, `atlas_continent`, `atlas_meal_type`,
   `atlas_difficulty`, `atlas_diet`, `atlas_recipe_tag`, `atlas_glossary_category`
   měly být **automaticky předvyplněné jako translatable** díky
   `class-polylang-bridge.php`'s `pll_get_post_types`/`pll_get_taxonomies` filtrům —
   ověřit, že `atlas_ingredient`/`atlas_country_tax`/`atlas_ingredient_tax` NEJSOU
   zaškrtnuté (záměrně, viz sekce D).
5. Znovu uložit permalinky (Nastavení → Trvalé odkazy → Uložit změny) po aktivaci,
   aby se Polylang rewrite pravidla správně zaregistrovala.
6. Vytvořit anglické verze systémových stránek (Kulinářský pas, Země, právní stránky…)
   přes standardní Polylang „+ přidat překlad" tlačítko na existujících českých
   stránkách vytvořených `class-page-setup.php`.
7. Před importem jakéhokoli anglického obsahu ověřit staging verifikaci ze sekce M.

## M. Omezení a rizika (staging verifikace nutná)

- **CPT base slug** (`/recepty/` → `/en/recepty/`, ne `/en/recipes/`) — Polylang Free
  limitace, zdokumentováno v sekci C. Řešitelné jen Polylang Pro nebo vlastním rewrite
  enginem (mimo rozsah).
- **hreflang/canonical/og:locale:alternate HTML výstup** — kód existuje a jednotky
  (`get_locale_urls()`'s datový zdroj) jsou pokryté testy (harness scénáře 21–24), ale
  SAMOTNÉ vykreslení na `wp_head()` vyžaduje reálný WP request s `is_singular()`/
  `get_queried_object()`/skutečnými permalinky — **nutná staging verifikace** po
  instalaci Polylang a vytvoření alespoň jednoho reálného CZ/EN páru.
- **Language switcher vykreslení** — `atlas_chuti_language_switcher()` je
  syntakticky ověřená (`php -l`) a logika `Atlas_Chuti_Polylang_Bridge::switcher_data()`
  je pokrytá testy nepřímo (přes `get_post_translation_id()`, scénář 23), ale vizuální
  vykreslení v `header.php` (desktop i mobil, klávesnice, čtečka obrazovky) **nutná
  staging verifikace** v prohlížeči.
- **Sitemap chování** — žádný kód se neměnil (spoléhá na WP core + Polylang), ale
  skutečné chování (dvě jazykové sitemapy, žádné neúplné/draft EN překlady v indexu)
  **nutná staging verifikace** po instalaci Polylang.
- **Produkční batch nemá `recipe_key`** — viz sekce J, migrační plán je editorial úkol
  mimo tento krok.
- **`translation_group` konflikt detekce** (`link_recipe_translations()`) běží jen
  při live importu (ne v dry-run) a jen když jsou OBĚ locale verze už v DB/dávce —
  pokud se CZ a EN verze importují ve zcela odlišných dávkách s velkým odstupem,
  propojení proběhne až při druhém importu (očekávané chování, ne bug — zdokumentováno
  zde pro jasnost).
- **Žádný hromadný překlad, žádné AI/DeepL/Google Translate API, žádná úprava
  produkčního batche** — striktně dodrženo, viz finální potvrzení níže.

---

**Produkční batch nebyl importován.**
**Produkční batch nebyl změněn.**
**Hromadný překlad obsahu nebyl proveden.**
