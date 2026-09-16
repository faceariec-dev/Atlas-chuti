# Checkpoint 10E.1 — Verzovaný image manifest + skutečná kurace World Classics / Česká klasika

## A. Why 10E.1 was needed

Checkpoint 10E vyprodukoval funkční manifest tooling (generátor + harness),
ale nechal dvě otevřené věci:

1. Domněnka, že `docs/image-production/europe-1-recipe-images.{csv,json,md}`
   nejsou vidět mezi committed files (viz sekce B — audit ukázal, že
   domněnka byla nesprávná).
2. `world_classic: false` u všech 100 receptů, protože World Classics filtr
   z Checkpointu 10B (`atlas_chuti_world_classics_recipe_keys`) byl
   záměrně prázdný — čekal na reálné editorial rozhodnutí.

Uživatel mezitím schválil editorial koncept (CZ: "Světová klasika", EN:
"World Classics") a Czech-first/.com global-first prioritu homepage. Tento
checkpoint kurátoruje reálný seznam a manifest o něj obohacuje.

## B. Manifest versioning issue

Audit (`git ls-files`, `git check-ignore -v`, `git diff` proti fetchnuté
`origin/claude/new-session-0gc4ib`) ukázal, že **domněnka z briefu
neplatila**:

- `.gitignore` obsahuje jen `/generated/` (regenerovatelný, záměrně
  ignorovaný výstup Checkpointu 10C — `wp atlas image-manifest`'s vlastní
  `generated/image-manifests/`), NIKDY `docs/image-production/*`.
- `git check-ignore -v` na všech třech souborech vrátil exit code 1
  (neignorováno).
- Všechny tři soubory už byly commitnuté v `4c9058f` (Checkpoint 10E) a
  pushnuté na `origin` — `git diff origin/claude/new-session-0gc4ib --
  docs/image-production/` byl prázdný.
- Generátor (`tools/generate-image-production-manifest.php`) je
  deterministický — ověřeno opětovně (dva běhy, bajtově identický výstup).

**Žádná úprava `.gitignore` nebyla potřeba** — artefakty už byly správně
verzované. Toto zjištění je zdokumentováno zde místo předstírání opravy,
která by nic neměnila.

## C. World Classics source/config

Mechanismus (nezměněný od Checkpointu 10B):

- `wp-content/themes/atlas-chuti/inc/homepage.php`'s
  `atlas_chuti_world_classics_recipe_keys()` volá
  `apply_filters( 'atlas_chuti_world_classics_recipe_keys', array() )` —
  prázdné pole by default.
- Očekává pole `recipe_key` stringů (stejná identita jako
  `class-json-importer.php`'s `related_recipes` resolving).
- Byl prázdný, protože dokumentace v kódu sama říkala: "a later step (or a
  site-specific mu-plugin/theme filter) supplies the real, reviewed list...
  once an editor has actually curated one" — tímto krokem je právě tento
  checkpoint.

Nový soubor `wp-content/themes/atlas-chuti/inc/editorial-curation.php`
registruje reálný seznam přes `add_filter()` na STEJNÝ filtr — žádná
paralelní infrastruktura, jen naplnění existujícího, do teď prázdného
hooku.

## D. Selected World Classics recipe_keys

14 `recipe_key` napříč 10 zeměmi, vybraných podle skutečné mezinárodní
známosti jména pokrmu (ne "typický pro zemi", ne žádné tvrzení o
popularitě/hodnocení):

| recipe_key | country_iso | title_cs | title_en |
|---|---|---|---|
| svickova | CZ | Svíčková na smetaně | Czech Beef Sirloin in Cream Sauce (Svíčková) |
| wiener-schnitzel | AT | Vídeňský řízek | Wiener Schnitzel (Viennese Breaded Veal Cutlet) |
| gulyas | HU | Gulyás | Hungarian Goulash Soup (Gulyás) |
| spaghetti-carbonara | IT | Spaghetti Carbonara | Spaghetti Carbonara |
| pizza-margherita | IT | Pizza Margherita | Pizza Margherita |
| boeuf-bourguignon | FR | Hovězí po burgundsku | Beef Bourguignon (Bœuf Bourguignon) |
| ratatouille | FR | Ratatouille | Ratatouille (Provençal Stewed Vegetables) |
| paella-valenciana | ES | Paella Valenciana | Paella Valenciana |
| gazpacho | ES | Gazpacho | Gazpacho |
| moussaka | GR | Moussaka | Moussaka |
| tzatziki | GR | Tzatziki | Tzatziki |
| fish-and-chips | GB | Fish and chips | Fish and Chips |
| pierogi-ruskie | PL | Pierogi ruskie | Pierogi Ruskie (Polish Potato and Curd Cheese Dumplings) |
| kottbullar | SE | Švédské masové kuličky | Swedish Meatballs (Köttbullar) |

14 je v doporučeném rozsahu 12–16. Rozložení zemí: CZ, AT, HU, PL, GB, SE
po jednom, IT/FR/ES/GR po dvou — dvojice jsou vždy dva skutečně
celosvětově známé pokrmy stejné země (např. Carbonara + Margherita), ne
umělé vyplnění kvóty. 10 z 20 zemí batche je zastoupeno; zbylých 10 nemá v
tomto batchi pokrm, který by byl srovnatelně mezinárodně známý jménem
samotným (viz sekce E pro Českou klasiku samostatně).

Žádný `title`/`slug` nebyl použit jako identita — jen `recipe_key`. Žádné
nové recepty nebyly vytvořeny.

## E. Czech homepage classics policy

Auditován mechanismus Czech-first `.cz` homepage
(`wp-content/themes/atlas-chuti/front-page.php` +
`inc/homepage.php`'s `atlas_chuti_home_czech_country()`): resolvuje se
živě přes ISO lookup (`Atlas_Chuti_Country_Sync::find_term_id_by_iso('CZ')`
→ `get_country_post_for_term()`), ne přes kurátorovaný seznam. Česká
banner sekce na `front-page.php` tak automaticky zobrazuje recepty
aktuální české země, bez ohledu na to, co je nebo není ve World Classics.

**Rozhodnutí: žádný paralelní "Czech Classics" `recipe_key[]` mechanismus
nebyl přidán.** Přesně podle instrukce briefu ("Pokud projekt místo
curated listu bezpečně používá `country_iso = CZ`, nepřidávej paralelní
mechanismus jen kvůli tomu") — tento bezpečný, živý mechanismus už
existuje a dělá přesně to, co je potřeba (`.cz` Czech-first, český banner
vždy viditelný). Manifest proto **neobsahuje** sloupec `czech_classic` —
přidání takového sloupce bez odpovídajícího reálného configu by bylo
přesně to fake pole, které brief zakazuje.

## F. Translation coverage

Pro všech 14 World Classics keys ověřeno programově
(`tests/harness-checkpoint-10e1-editorial-curation.php`, checks #2–4):

```text
World Classics keys existující mezi 100 recipe concepts: 14/14
World Classics CZ translation coverage:  14/14 (100%)
World Classics EN translation coverage:  14/14 (100%)
Duplicitní klíče: 0
```

Žádný key nebyl do seznamu zařazen bez ověřené CZ i EN varianty —
verifikace proběhla před zápisem seznamu do `inc/editorial-curation.php`,
ne až dodatečně.

## G. Image manifest flags/priorities

Manifest znovu vygenerován (`php tools/generate-image-production-manifest.php`)
po zapojení reálné kurace. Nové sloupce v CSV i JSON: `world_classic`
(bool), `priority` (`1` = World Classics/homepage lead content, `2` =
ostatní launch recepty — žádné jiné úrovně, žádné popularity skóre).
`czech_classic` sloupec neexistuje (viz sekce E).

```text
World Classics images: 14
Czech Classics images: N/A (žádný paralelní mechanismus — viz sekce E)
Priority-1 unique image concepts: 14
Remaining image concepts (priority 2): 86
```

`world_classic=true` v manifestu odpovídá přesně a jen konfiguraci v
`inc/editorial-curation.php` (ověřeno programově, `tests/harness-
checkpoint-10e1-editorial-curation.php` check #11 — množinová shoda, ne
jen počet).

## H. Tests

21 požadovaných scénářů implementováno v `tests/harness-checkpoint-10e1-
editorial-curation.php`, všech 21 PASS:

1. World Classics list není prázdný.
2. Všechny World Classics keys existují v 100 recipe concepts.
3. Každý World Classics key má CZ translation.
4. Každý World Classics key má EN translation.
5. Žádný duplicate key.
6. Shared list renderuje `Světová klasika` CZ a `World Classics` EN (ze
   STEJNÉ proměnné `$world_classics` ve `front-page.php`).
7. Žádný popularity/trending claim (ověřeno nad uživatelsky viditelným
   textem — MD checklist + manifest field values — ne nad PHP komentáři).
8. Czech homepage zůstává Czech-first.
9. EN homepage zůstává global-first.
10. Manifest = 100 concepts.
11. Manifest `world_classic=true` přesně odpovídá config keys (množinová
    shoda).
12. CSV/JSON/MD jsou verzované files (`git ls-files --error-unmatch`).
13. CSV/JSON key sets identické.
14. ALT coverage zůstává 100/100 CZ+EN.
15. Source dimensions zůstávají 1600×900.
16. CZ/EN production content diff = empty.
17. Checkpoint 10B regression (`harness-checkpoint-10b.php`) passes — 37 checks.
18. Checkpoint 10C regression (`harness-checkpoint-10c.php`) passes — 30 checks.
19. Checkpoint 10C.1 quality gate (`harness-checkpoint-10c1-quality-gate.php`)
    passes — 12 checks.
20. Checkpoint 10D integrity passes (`production-data/europe-1-en/` diff
    empty).
21. Checkpoint 10E harness (`harness-checkpoint-10e-image-production-
    manifest.php`) passes — 18 checks (check #14 there updated to assert
    against the real curated set instead of the old "always empty"
    assumption — see section I).

Doplňková plná regrese (celá existující sada, po dokončení této práce):

```text
harness-step-03.php:                                    64 checks, 0 failing
harness-step-04.php:                                    33 checks, 0 failing
harness-step-09.php:                                    51 checks, 0 failing
harness-checkpoint-10b.php:                              37 checks, 0 failing
harness-checkpoint-10c.php:                              30 checks, 0 failing
harness-checkpoint-10c1-quality-gate.php:                12 checks, 0 failing
harness-checkpoint-10e-image-production-manifest.php:    18 checks, 0 failing
harness-checkpoint-10e1-editorial-curation.php:          21 checks, 0 failing
----------------------------------------------------------------------------
Celkem: 266 checks, 0 failing
```

`php tools/content-quality-gate.php` nad `production-data/europe-1/` i
`production-data/europe-1-en/`: `0 ERROR, 0 WARNING, 220 INFO` (beze
změny oproti předchozím checkpointům).

## I. Changed files

Nové:
- `wp-content/themes/atlas-chuti/inc/editorial-curation.php` — reálný,
  reviewovaný World Classics `recipe_key[]` seznam, zavěšený přes
  `add_filter()` na existující Checkpoint 10B hook.
- `tests/harness-checkpoint-10e1-editorial-curation.php` — 21 kontrol
  tohoto checkpointu.
- `docs/implementation-reports/checkpoint-10e1-editorial-curation-manifest.md`
  (tento report).

Upravené:
- `wp-content/themes/atlas-chuti/functions.php` — jeden nový `require`
  řádek pro `inc/editorial-curation.php` (za `inc/homepage.php`, stejná
  konvence jako všechny ostatní `inc/` require).
- `tools/generate-image-production-manifest.php` — stub `add_filter()`/
  `apply_filters()` byl no-op (10E) → nyní REÁLNÝ minimální filter
  registry, aby `inc/editorial-curation.php`'s `add_filter()` volání
  skutečně fungovalo; přidán sloupec `priority`; require pro
  `editorial-curation.php`; MD checklist nyní reportuje World
  Classics/priority čísla.
- `docs/image-production/europe-1-recipe-images.{csv,json,md}` —
  regenerováno se skutečnými `world_classic`/`priority` hodnotami (100
  concepts beze změny).
- `tests/harness-checkpoint-10e-image-production-manifest.php` — check
  #14 přepsán z "world_classic je vždy false" (platilo v 10E) na
  "world_classic odpovídá přesně reálné konfiguraci" (platí trvale, i
  když se kurace v budoucnu změní); přidána chybějící `ABSPATH` definice
  (bez ní `require` do `inc/homepage.php` tiše ukončil skript přes jeho
  vlastní `exit;` guard — nalezeno a opraveno při psaní tohoto
  checkpointu).

Beze změny:
- Celý `production-data/` (CZ i EN) — `git diff --stat` prázdný.
- ALT logika, media pipeline (`class-recipe-image-pipeline.php`),
  importer, `front-page.php` (čteno, ne upraveno — Czech-first/global-first
  pořadí i `$is_en` heading logika jsou přesně ty, které Checkpoint 10B
  už implementoval).

## J. Next image-production step

- Regenerovaný manifest (`docs/image-production/europe-1-recipe-images.
  {csv,json,md}`) nyní správně řadí **14 World Classics konceptů jako
  prioritu 1** — doporučeno je vyfotit/vykomisovat nejdřív, pokrývají
  vše, co se skutečně zobrazí na CZ i EN homepage.
- Zbylých 86 konceptů (priorita 2) může následovat v libovolném pořadí.
- Jakmile budou reálné fotky k dispozici, postup zůstává stejný jako v
  Checkpointu 10E: `europe-1-recipe-images.zip` → `wp atlas image-import
  --dry-run` → oprava nálezů → reálný import.
- Pokud v budoucnu vznikne potřeba samostatné "Czech Classics" kurace
  (nad rámec živého `country_iso = CZ` mechanismu), měla by se řešit jako
  samostatný, výslovně schválený checkpoint — ne přidána mimochodem sem.

---

```text
World Classics používá pouze skutečné recipe_key z Europe batchu.
World Classics má skutečné CZ i EN translations.
Světová klasika a World Classics sdílejí stejnou concept-level kuraci.
Image production manifest je verzovaný v Git.
Nebyla vytvořena fake popularity data.
Produkční recipe texty nebyly změněny.
```
