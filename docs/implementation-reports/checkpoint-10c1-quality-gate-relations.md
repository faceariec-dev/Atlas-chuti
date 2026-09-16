# Checkpoint 10C.1 — Oprava false-positive relation warnings v content quality gate

## A. Root cause

`tools/content-quality-gate.php` (postavený v Kroku 9) validoval
`related_recipes`/`related_glossary` reference proti mapě `slug` hodnot
sebraných z celého batche (`$known_recipe_slugs`/`$known_glossary_slugs`).
Skutečný importer (`class-json-importer.php`) ale relace resolvuje přes
STABILNÍ IDENTITY — `recipe_key` pro recepty, `translation_group` (se
slug fallbackem) pro glosář — nikdy přes `slug`. `slug` je lokalizovaný
URL segment (v produkčních datech často odlišný od `recipe_key`/
`translation_group`, viz Checkpoint 10A — např. recept "Svíčková na
smetaně" má `slug: svickova-na-smetane`, ale `recipe_key: svickova`).

Výsledek: 63 relation hodnot v `production-data/europe-1/` (53
`related_glossary` + 10 `related_recipes`), které byly z pohledu
skutečného importeru VŽDY validní (cíleny přesně na `recipe_key`/
`translation_group`), nástroj hlásil jako "unknown slug" — false
positive čistě kvůli špatné identitní logice nástroje, ne kvůli chybě v
datech. Tento nález byl poprvé zdokumentován (a data ověřena jako správná)
v Checkpointu 10A, sekce F — tento checkpoint je jeho slíbená následná
oprava samotného nástroje.

## B. Importer relation contract

Ověřeno přímo v `class-json-importer.php` (ne odhadnuto z paměti):

- **`resolve_reference( $post_type, $ref, $locale )`** (řádky ~782–807):
  - `atlas_country` → `Atlas_Chuti_I18N::find_country_by_iso()` (ISO kód).
  - `atlas_ingredient` → `find_ingredient_by_key()` (`ingredient_key`).
  - `atlas_recipe` → `find_by_recipe_key( sanitize_title($ref), $locale )`
    — **výhradně `recipe_key`**, žádný `translation_group`/slug fallback
    (komentář v kódu explicitně cituje KROK 4 fix, který tento fallback
    odstranil).
  - cokoliv jiného (glosář) → `find_by_translation_group( $post_type,
    sanitize_title($ref), $locale )`.
  - Až když stabilní klíč neresolvuje nic v DB, zkusí se `get_page_by_path()`
    (skutečný slug) jako fallback proti ŽIVÝM postům — irelevantní pro
    tento nástroj, který nikdy DB nemá (viz sekce C).
- **`reference_is_valid()`** (řádky ~915–948): to, co skutečně řídí
  validaci/warnings (ne přímý import) — zkouší `resolve_reference()`
  (živá DB), a když ta nic nevrátí, padá na `$planned` index — mapu
  `locale|stable_key` položek, které JSOU v tomto samém batchi validní
  (i když ještě nezapsané). Přesně tenhle druhý mód je to, co read-only
  quality-gate nástroj (bez DB) potřebuje replikovat celý.
- **`build_planned_index()`** (řádky ~832–908): staví `$planned['recipe']`/
  `$planned['glossary']` mapy klíčované `planned_key($locale, $stable_key)`,
  kde `$stable_key` pochází ze `stable_key_for()`:
  - recept → `sanitize_title( resolve_recipe_key($item) )` =
    `sanitize_title( trim($item['recipe_key'] ?? '') )`.
  - glosář (a cokoliv jiného) → `sanitize_title( $item['translation_group']
    ?: $slug )`.
  - Navíc počítá `_duplicate_recipe_keys` — kolikrát se stejný
    `planned_key` (recipe_key v rámci jedné lokalizace) v batchi opakuje;
    `validate_recipe()` z toho pak dělá tvrdý ERROR (KROK 3B hardening),
    nikdy jen warning.
- **`resolve_item_locale()`**: `Atlas_Chuti_I18N::normalize_locale(
  $item['locale'] )`, s fallbackem na `DEFAULT_LOCALE` — reference tedy
  vždy resolvují jen v rámci STEJNÉ lokalizace jako položka, která
  referenci nese.

Glosář nemá obdobu `_duplicate_recipe_keys` (žádná duplicitní-`translation_group`
kontrola u glosáře v importeru neexistuje) — tento checkpoint proto
duplicate-detekci přidává jen pro `recipe_key`, přesně podle skutečného
importer chování, ne symetricky "pro jistotu".

## C. Old quality-gate behavior

```php
foreach ( ( $r['related_recipes'] ?? array() ) as $rel ) {
    if ( ! isset( $known_recipe_slugs[ $rel ] ) ) {
        report( 'WARNING', ... );
    }
}
```

`$known_recipe_slugs`/`$known_glossary_slugs` byly stavěny výhradně z
`$item['slug']`, bez ohledu na `recipe_key`/`translation_group`, a bez
jakéhokoliv locale scoping. Odtud 63 false positives (sekce G).

## D. New validation behavior

Nová identitní logika v `tools/content-quality-gate.php` (funkce
`atlas_quality_gate_recipe_key()`, `atlas_quality_gate_glossary_key()`,
`atlas_quality_gate_resolve_locale()`, `atlas_quality_gate_planned_key()`,
`atlas_quality_gate_sanitize_title()`) věrně replikuje **planned-batch
polovinu** `reference_is_valid()`/`build_planned_index()` kontraktu (sekce
B) — jedinou polovinu, která se na tento bez-DB nástroj vůbec vztahuje:

1. `$known_recipe_keys[locale|sanitize_title(recipe_key)] = true` pro
   každý recept s neprázdným `recipe_key`.
2. `$known_glossary_keys[locale|sanitize_title(translation_group ?: slug)]
   = true` pro každé glosářové heslo.
3. `related_recipes` reference se testuje jako
   `locale_referencujícího_receptu|sanitize_title($rel)` proti (1).
4. `related_glossary` reference se testuje jako
   `locale_referencujícího_receptu|sanitize_title($rel)` proti (2).
5. Nová ERROR kontrola: recipe_key duplikovaný jiným receptem ve stejné
   lokalizaci v rámci batche (`$duplicate_recipe_keys`, mirror
   `_duplicate_recipe_keys`).

**Jeden zdroj pravdy, ne duplicitní logika**: `atlas_quality_gate_resolve_locale()`
volá přímo `Atlas_Chuti_I18N::normalize_locale()`/`DEFAULT_LOCALE` (nástroj
teď navíc requiruje `class-i18n.php` — ověřeno, že je na statické úrovni
používané zde bezWP-DB-závislé, stejný důvod jako u již dřív sdílených
`class-units.php`/`class-taxonomy-labels.php`). `sanitize_title()`
samotné (WordPress core, `wp-includes/formatting.php`) sdíleno **nebylo**
— vyžadovalo by vtažení širokého plátu WP core (`remove_accents()`,
`apply_filters()`, `seems_utf8()` atd.) do nástroje, který je záměrně
bez plného WP runtime. Místo toho `atlas_quality_gate_sanitize_title()` —
malá, zdokumentovaná lokální reimplementace, bezpečná právě pro TENTO
nástrojův vstup (`recipe_key`/`translation_group` hodnoty jsou už validované,
čisté ASCII lowercase-alnum-se-separátory řetězce, ne libovolný surový
title text) — s testem porovnávajícím chování proti reálným datům
(sekce E/F/G, harness check #8).

Role `recipe_key` a `translation_group` se NIKDE neměnily — nástroj jen
čte tato pole, nikdy je nezapisuje ani nepřejmenovává. Žádný nový title/slug
fallback nebyl zaveden jako identita tam, kde ho importer nemá (ověřeno
harness checkem #2b a #4 — explicitní negativní testy dokazující, že
translation_group u receptů a slug u receptů/glosáře stále NEJSOU platnou
identitou pro relace).

## E. Recipe relation tests

Viz `tests/harness-checkpoint-10c1-quality-gate.php`, scénáře #1–#4, #7:

| # | Scénář | Výsledek |
|---|---|---|
| 1 | related_recipes → skutečný `recipe_key` cíle | bez warningu |
| 2b | related_recipes → cílův `translation_group` (ne recipe_key) | STÁLE warning (translation_group není identita pro recept-recept relace) |
| 3 | related_recipes → neexistující recipe_key | warning |
| 4 | related_recipes → cílův `slug` (ne recipe_key) | STÁLE warning (slug není identita) |
| 7 | dva recepty se stejným `recipe_key` ve stejné lokalizaci | ERROR (duplicitní identita) |

## F. Glossary relation tests

| # | Scénář | Výsledek |
|---|---|---|
| 2a | related_glossary → skutečný `translation_group` cíle | bez warningu |
| 5 | related_glossary → skutečný `translation_group` (druhá nezávislá fixture) | bez warningu |
| 6 | related_glossary → neexistující term | warning |

## G. Europe batch before/after warnings

| | Před opravou | Po opravě |
|---|---|---|
| `related_recipes references unknown ...` | 10 | **0** |
| `related_glossary references unknown ...` | 53 | **0** |
| **Součet relation warnings** | **63** | **0** |
| ERROR celkem | 0 | 0 (beze změny) |
| WARNING celkem | 63 | 0 |
| INFO celkem | 220 | 220 (beze změny) |

Ověřeno živým spuštěním `php tools/content-quality-gate.php
production-data/europe-1/` (sekce H) i harness checky #8a/#8b/#8c.

## H. Regression results

```
harness-step-03.php:                 64 checks, 0 failing
harness-step-09.php:                 51 checks, 0 failing
harness-checkpoint-10b.php:          37 checks, 0 failing
harness-checkpoint-10c.php:          30 checks, 0 failing
harness-checkpoint-10c1-quality-gate.php: 12 checks, 0 failing (nový, tento checkpoint)
content-quality-gate.php nad Europe batch: 0 ERROR, 0 WARNING, 220 INFO
git diff --stat -- production-data/: prázdné
```

Žádná změna importer identity rules (`class-json-importer.php` nebyl v
tomto checkpointu vůbec upraven — `git diff --stat` proti němu je
prázdné).

## I. Changed files

```
tools/content-quality-gate.php                                     (relation validation identity logic)
tests/harness-checkpoint-10c1-quality-gate.php                      (nový, 12 checks / 9 scénářů)
docs/implementation-reports/checkpoint-10c1-quality-gate-relations.md (tento report)
```

`production-data/europe-1/**`: **beze změny** (žádný soubor v tomto
adresáři nebyl otevřen k zápisu, ověřeno `git diff --stat`).
`class-json-importer.php`: **beze změny** (jen čten pro audit, sekce B).

## J. Deferred issues

- **Country/ingredient relation validace** — `content-quality-gate.php`
  dnes nevaliduje `related_countries`/`related_recipes`/`related_countries`
  u glosářových/zemních položek vůbec (ani před, ani po tomto checkpointu —
  mimo scope, brief se soustředí výhradně na recipe→recipe a recipe→glossary
  relace, které byly zdrojem oněch 63 false positives).
- **`get_page_by_path()` slug fallback** — `resolve_reference()`'s druhá
  polovina (fallback na živý slug match proti existujícím WP postům) se
  týká jen SKUTEČNÉHO importu do běžící instalace s DB; tento read-only,
  bez-DB nástroj ji záměrně nereplikuje (nemá co by proti ní testoval) —
  zdokumentováno v sekci B/D jako vědomé, ne opomenuté rozhodnutí.
- **`atlas_quality_gate_sanitize_title()` vs. reálné `sanitize_title()`** —
  zjednodušená reimplementace je ověřeně bezpečná pro TENTO nástrojův
  vstupní tvar dat (už-validované stable keys), ale nebyla navržena jako
  univerzální náhrada WP core `sanitize_title()` pro libovolný surový text
  (diakritika, non-ASCII apod.) — pokud by nástroj v budoucnu začal
  validovat i syrový, nesanitizovaný text, tohle by potřebovalo revizi.

---

## Potvrzovací blok

```text
Produkční batch nebyl změněn.
Importer identity rules nebyly změněny.
Quality gate nyní validuje relations podle skutečných stable identifiers.
63 known false-positive relation warnings bylo odstraněno.
```
