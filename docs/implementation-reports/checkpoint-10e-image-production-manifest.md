# Checkpoint 10E — Finální image production manifest a příprava asset batchu

## A. Starting state

Ověřeno na začátku checkpointu:

```text
branch: claude/new-session-0gc4ib
HEAD:   91f9990 — Content: add English localization for Europe batch
git status: nothing to commit, working tree clean
```

Audit commitu `91f9990` (Checkpoint 10D): přesně 8 souborů — 7 nových
`production-data/europe-1-en/*.json` + `docs/implementation-reports/
checkpoint-10d-en-localization.md`. Žádný trvalý one-off skript mimo
zamýšlený scope (Python skripty použité při přípravě EN obsahu žily v
session scratchpad mimo repozitář a nikdy nebyly commitnuty). Ověřeno
znovu programově:

```text
production-data/ diff (proti CZ): prázdný
EN recipes: 100/100
EN countries: 20/20
EN glossary: 50/50
World Classics EN coverage: 0/0 (filtr je prázdný by design od Checkpointu 10B)
quality gate nad production-data/europe-1-en/: 0 ERROR, 0 WARNING, 220 INFO
```

Žádná nesrovnalost nalezena, checkpoint mohl pokračovat bez zastavení.

## B. Recipe concept count

100 `recipe_key` concepts (CZ i EN varianta stejného konceptu sdílí jeden
fyzický obrázek — **ne** 200 obrázků). Ověřeno, že CZ i EN recipe_key
množiny jsou identické a shodují se s manifestem (`tests/harness-
checkpoint-10e-image-production-manifest.php`, check #4).

## C. Filename convention

```text
{recipe_key}.jpg
```

`recipe_key` je stejná stabilní identita, kterou `class-json-importer.php`
používá pro `related_recipes` (viz `class-recipe-image-pipeline.php`'s
`parse_filename()`/`is_valid_recipe_key()` z Checkpointu 10C, beze změny).
Nikdy lokalizovaný titul, nikdy slug, nikdy pořadí souborů.

## D. CZ/EN shared-image model

Manifest je stavěný per `recipe_key`, ne per lokalizovaný post — jeden řádek
pro oba jazykové posty stejného konceptu. `Atlas_Chuti_Recipe_Image_Pipeline::
known_recipe_keys()` (nezměněná metoda z 10C) mapuje `recipe_key => [locale
=> post_id]`; `build_manifest()` z toho staví jeden řádek na klíč. Ověřeno,
že žádný obrázek/filename se nezdvojuje per locale (check #3, #4).

## E. ALT coverage

`alt_cs` = `title_cs` (lokalizovaný CZ titul receptu), `alt_en` = `title_en`
(lokalizovaný EN titul) — přímo z `build_manifest()`'s vlastního mapování
(`'alt_cs' => $title_cs, 'alt_en' => $title_en`), beze změny logiky z 10C.
100/100 obojí neprázdné. Rozměry (`1600`/`900`/vzor `ČÍSLO×ČÍSLO`) nikde v
ALT textu — ověřeno regexem nad oběma poli u všech 100 řádků. HTML
`title=""` není a nikdy nebyl použit jako SEO/ALT pole (žádná změna v
`atlas_chuti_recipe_image_alt()` proběhla).

## F. Source dimensions

`REQUIRED_MIN_WIDTH`/`REQUIRED_MIN_HEIGHT` v `class-recipe-image-pipeline.php`
zůstávají nezměněné: **1600 × 900** (16:9 — potvrzeno, že tento contract
nebyl v tomto checkpointu upravován, viz sekce M). Všech 100 řádků
manifestu nese tuto stejnou požadovanou minimální velikost — žádná
dokumentovaná výjimka nebyla zavedena.

## G. CSV/JSON formats

- `docs/image-production/europe-1-recipe-images.csv` — 100 datových řádků
  + hlavička, sloupce: `recipe_key, filename, title_cs, title_en, alt_cs,
  alt_en, required_width, required_height, country_iso, status, notes`.
- `docs/image-production/europe-1-recipe-images.json` — 100 objektů,
  deterministické pořadí (seřazeno podle `recipe_key`, ověřeno spuštěním
  generátoru dvakrát a bajtovým porovnáním výstupu — identické).
- CSV i JSON reprezentují přesně stejných 100 `recipe_key` (ověřeno
  programově, check #11).
- `status` hodnoty: `TO_CREATE` (100), `READY` (0), `MISSING` (0) — žádný
  fake `READY` stav bez reálného souboru (v repozitáři neexistuje ani jeden
  obrázkový soubor, ověřeno `find . -iname "*.jpg" -o ...` před psaním
  generátoru — 0 výsledků).

## H. World Classics / Czech Classics priority if available

Filtr `atlas_chuti_world_classics_recipe_keys` zůstává prázdný by default
(žádný `add_filter()` s reálným seznamem nikde v repozitáři — ověřeno
grepem před napsáním generátoru). Pole `world_classic` proto existuje v
JSON výstupu (pro budoucí použití), ale je `false` pro všech 100 řádků —
žádná karta nebyla označena podle názvu ani subjektivního odhadu slávy.
Jakmile bude filtr v budoucnu reálně naplněn, stačí manifest znovu
vygenerovat (`php tools/generate-image-production-manifest.php`) a pole se
automaticky correctly promítne.

## I. ZIP contract

Zdokumentováno v `docs/image-production/europe-1-recipe-images.md`:
budoucí archiv `europe-1-recipe-images.zip`, flat struktura, pouze
`{recipe_key}.{ext}` soubory. Výslovně vyloučeno: CSV, JSON, README,
executables, thumbnaily, duplicitní CZ/EN kopie stejné fotky.

## J. Dry-run/import workflow

Zdokumentován existující, nezměněný workflow z Checkpointu 10C:
`wp atlas image-manifest` → porovnání očekávaných filenames → ZIP →
`wp atlas image-import <zip> --dry-run` → oprava missing/unmatched/invalid
→ `wp atlas image-import <zip>` (případně `--replace`). Žádný druhý
paralelní validátor nebyl vytvořen — veškerá párovací/validační logika
(`parse_filename`, `classify_file`, `extract_zip_safely`, `import_batch`)
zůstává v `class-recipe-image-pipeline.php` nezměněná; nový generátor
(`tools/generate-image-production-manifest.php`) pouze volá `build_manifest()`
a výsledek obohacuje o `country_iso`/`world_classic` a přeformátovává do
nového výstupního schématu a umístění, který brief explicitně žádá.

## K. Missing image status

Protože v repozitáři neexistuje jediný obrázkový soubor a databáze
neexistuje vůbec (žádný `wp` binary, žádný `wp-config.php` — ověřeno na
začátku checkpointu), `status = TO_CREATE` je pro všech 100 konceptů
správný a očekávaný výstup, ne chyba. `docs/image-production/
europe-1-recipe-images.md` toto explicitně vysvětluje a dává přesný návod,
jak stav změnit (nahrát reálné fotky, spustit dry-run, pak reálný import).

## L. Tests

18 požadovaných scénářů implementováno v `tests/harness-checkpoint-10e-
image-production-manifest.php`, všech 18 PASS:

1. Manifest reprezentuje přesně 100 recipe concepts.
2. 100 unique `recipe_key`.
3. 100 unique expected filenames.
4. CZ/EN varianty mapují na stejný image filename (ověřeno cross-checkem
   proti reálným `production-data/europe-1{,-en}/` JSON souborům).
5. 100 non-empty `alt_cs`.
6. 100 non-empty `alt_en`.
7. Žádné rozměry v ALT (regex nad oběma poli, 0 zásahů).
8. Požadované rozměry 1600×900 pro všech 100 řádků.
9. CSV = 100 řádků.
10. JSON = 100 objektů.
11. CSV/JSON `recipe_key` množiny identické.
12. Manifest je deterministický (dva běhy generátoru → bajtově identický
    CSV i JSON).
13. Žádná fake image cesta/reference v produkčním obsahu (`git diff --stat
    -- production-data/` prázdný).
14. World Classics flag pouze z reálné konfigurace — 0/100 dnes (filtr
    prázdný).
15. `tests/harness-checkpoint-10c.php` (media pipeline) — PASS, 30 kontrol.
16. `tests/harness-checkpoint-10c1-quality-gate.php` — PASS, 12 kontrol.
17. Checkpoint 10D obsah beze změny (`git diff --stat --
    production-data/europe-1-en/` prázdný).
18. `tests/harness-step-09.php` (Step 9 regrese) — PASS, 51 kontrol.

Doplňková plná regrese spuštěna nad celou existující sadou:

```text
harness-step-03.php:                             64 checks, 0 failing
harness-step-04.php:                             33 checks, 0 failing
harness-step-09.php:                             51 checks, 0 failing
harness-checkpoint-10b.php:                       37 checks, 0 failing
harness-checkpoint-10c.php:                       30 checks, 0 failing
harness-checkpoint-10c1-quality-gate.php:         12 checks, 0 failing
harness-checkpoint-10e-image-production-manifest.php: 18 checks, 0 failing
-------------------------------------------------------------------------
Celkem: 245 checks, 0 failing
```

`php tools/content-quality-gate.php production-data/europe-1/` — beze
změny oproti předchozímu checkpointu: `0 ERROR, 0 WARNING, 220 INFO`.

## M. Changed files

Nové (žádný trackovaný soubor nebyl upraven — `git diff --stat` nad celým
existujícím obsahem repozitáře je prázdný):

- `tools/generate-image-production-manifest.php` — generátor manifestu;
  stubuje minimální in-memory WP post store (stejný vzor jako `tests/
  harness-checkpoint-10c.php`) sestavený z reálných `production-data/`
  JSON dat, a volá REÁLNOU, nezměněnou `Atlas_Chuti_Recipe_Image_Pipeline::
  build_manifest()`. Nemění `class-recipe-image-pipeline.php`,
  `class-cli.php` ani žádnou jinou media-pipeline logiku.
- `docs/image-production/europe-1-recipe-images.csv` (nový)
- `docs/image-production/europe-1-recipe-images.json` (nový)
- `docs/image-production/europe-1-recipe-images.md` (nový)
- `tests/harness-checkpoint-10e-image-production-manifest.php` (nový, 18
  kontrol)
- `docs/implementation-reports/checkpoint-10e-image-production-manifest.md`
  (tento report)

Beze změny:
- Celý `production-data/` (CZ i EN) — `git diff --stat` prázdný.
- `wp-content/plugins/atlas-chuti-core/includes/class-recipe-image-pipeline.php`
  a veškerá další media pipeline/importer/i18n logika.
- Žádný obrázkový soubor nebyl vytvořen, stažen ani importován kamkoliv.

## N. Next step

- **Skutečné focení/commissioning**: 100 fotek podle `docs/image-production/
  europe-1-recipe-images.md` (min. 1600×900, jpg/png/webp).
- **Archivace do ZIPu**: `europe-1-recipe-images.zip`, flat, jen
  `{recipe_key}.{ext}` soubory.
- **Dry-run**: `wp atlas image-import europe-1-recipe-images.zip --dry-run`
  na reálné WordPress instanci (mimo scope tohoto checkpointu — v tomto
  repu žádná neexistuje).
- **Reálný import**: až dry-run vyjde čistý, `wp atlas image-import
  europe-1-recipe-images.zip`.
- **World Classics**: jakmile bude existovat reálná redakční konfigurace
  `atlas_chuti_world_classics_recipe_keys`, manifest znovu vygenerovat, aby
  se pole `world_classic` promítlo správně.

---

```text
Image manifest obsahuje 100 unikátních recipe concepts.
CZ a EN varianta stejného recipe_key používají jeden společný obrázek.
ALT je připraven zvlášť pro CZ a EN.
Rozměry nejsou součástí ALT textu.
Nebyl vytvořen ani importován žádný fake obrázek.
Produkční recipe texty nebyly změněny.
```
