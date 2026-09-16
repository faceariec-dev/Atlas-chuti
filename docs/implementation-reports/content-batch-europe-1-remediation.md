# Obsahový checkpoint 10A — Oprava českého production batchu před importem

## A. Executive summary

Cíl checkpointu: opravit `production-data/europe-1/` (100 receptů, 20 zemí,
50 glosářových hesel) tak, aby batch byl reálně importovatelný a obsahově
kvalitní, **bez importu do WordPressu** a **beze změn kódu**. Výchozí stav
(potvrzeno Krokem 9, commit `277b1d9`): všech 100 receptů chybělo pole
`recipe_key` → importer je tvrdě odmítal (`class-json-importer.php` nemá
fallback na `translation_group`/`slug`, záměrně).

Provedeno:
1. **`recipe_key` migrace** pro všech 100 receptů (sekce C) — jediná změna,
   která batch vůbec zprovozní pro import.
2. **Přepis perex/excerpt** u všech 100 receptů na 70–110 slov, faktově
   bezpečný, bez šablonovitosti (sekce D).
3. **Audit a oprava duplicity** mezi `about` a novým perexem — nalezeno
   systémové riziko (viz Errors), opraveno u 57 receptů (sekce E).
4. **Analýza 63 relation warnings** z `content-quality-gate.php` — všech 63
   se ukázalo jako falešně pozitivní (nástrojové omezení, ne chyba dat),
   zdokumentováno s důkazem (sekce F).
5. **Audit zemí (20) a glosáře (50)** — čisté, beze změn (sekce G, H).
6. **Audit ingrediencí/jednotek/tagů** — 0 chyb, žádná regrese (sekce I).
7. **Seznam chybějících obrázků** — všech 100 receptů, žádné URL nevymýšleno
   (sekce J).

**Beze změny:** `01-countries.json`, `02-glossary.json`, žádný PHP/JS
soubor, žádný import do DB. Commit obsahuje pouze
`production-data/europe-1/0[3-7]*.json` + tento report.

## B. Pre-flight ověření stavu repozitáře

Před zahájením ověřeno:
- Branch: `claude/new-session-0gc4ib`
- HEAD před checkpointem: `277b1d9` ("Step 9: finalize SEO Discover and GEO
  audit")
- `git status --short`: čistý strom, žádné rozpracované změny.

Tento checkpoint je první, kde je změna `production-data/europe-1/`
explicitně zamýšlená (na rozdíl od Kroku 9, kde šlo o read-only audit).

## C. `recipe_key` / `translation_group` migrace (100/100 receptů)

**Kontrakt ověřen přímo ve zdrojovém kódu** `class-json-importer.php`:
- `resolve_recipe_key($item)` = `trim((string)($item['recipe_key'] ?? ''))`
  — nulový fallback, záměrně (i vlastní docblok nad metodou to potvrzuje).
- `stable_key_for()`: recipe → `resolve_recipe_key()`; country → ISO kód;
  ingredient → `ingredient_key`; jinak (včetně glosáře) → `translation_group`
  nebo `slug`.
- `resolve_reference()`: `atlas_recipe` reference se resolvují přes
  `recipe_key` (`find_by_recipe_key`), glosář přes `translation_group`
  (`find_by_translation_group`).

**Analýza před migrací** (Python, na všech 100 receptech): `translation_group`
byl už 100% unikátní, stabilní, jazykově neutrální a ve validním formátu
(`/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/`) napříč celým batchem — přesně to, co
`recipe_key` potřebuje. Proto zvolena bezpečná jednorázová migrace:

1. `recipe_key` = kopie původní hodnoty `translation_group` (beze změny
   hodnoty, jen nové pole).
2. `translation_group` přejmenován na `"recipe_" + původní hodnota` (např.
   `svickova` → `recipe_svickova`), aby obě pole nesla textově odlišné
   hodnoty odpovídající jejich odlišné roli — `recipe_key` jako stabilní
   cross-language identita receptu, `translation_group` jako vazba pro
   budoucí CZ/EN párování přes Polylang.

**Bezpečnostní analýza rename**: ověřeno tracováním `resolve_reference()`,
že žádné jiné pole v batchi aktuálně neodkazuje na recept přes jeho
`translation_group` (`related_recipes` cílí na `recipe_key`, který teprve
touto migrací vzniká) — rename je tedy bezpečný. **U glosáře stejný rename
NEPROVEDEN** — glosářová `translation_group` je skutečná identita, na
kterou cílí `related_glossary` ostatních položek přes
`find_by_translation_group()`; přejmenování by rozbilo všechny odkazy.

**`locale`**: brief navrhoval ilustrativně `cs_CZ`; skutečná hodnota v datech
i v kódu (`Atlas_Chuti_I18N::DEFAULT_LOCALE`) je `cs-CZ`, což už bylo 100%
pokryto u všech 100 receptů před checkpointem. V souladu s bodem briefu
"respektuj skutečnou implementaci, když se liší od ilustrativního příkladu"
nebyla `locale` měněna.

**Výsledek**: 100/100 receptů má nyní platný, unikátní `recipe_key`.
`content-quality-gate.php` hlásí **0 ERROR** (dříve 100 — "missing
recipe_key").

## D. Přepis perex/excerpt (100/100 receptů)

Všech 100 textů přepsáno na 70–110 slov, přirozenou češtinou, odlišných od
`about`, bez šablonovitosti a bez vymyšlených faktů.

**Faktová disciplína**: každé tvrzení v novém perexu musí být dohledatelné
v `about`/`origin_history`/`tips`/`watch_out`/`variants`/`ingredients` DANÉHO
receptu. Během psaní zachycena a opravena jedna vlastní chyba — první draft
`konigsberger-klopse` obsahoval vymyšlené tvrzení o historické kontinuitě
receptu ("recept, který se od poloviny 20. století téměř nezměnil"), které
zdrojová data nepodporovala; opraveno na větu opírající se výhradně o
existující fakt (název podle města Königsberg).

**Word-count statistiky** (finální, po všech úpravách):
- Min: 70 slov, Max: 95 slov, Průměr: 80,3 slova
- 100/100 receptů v rozmezí 70–110 slov (ověřeno `\S+` word-count skriptem)

## E. Audit duplicity `about` vs. perex

**Nález**: automatizovaný n-gram sken (nejdelší společná souvislá slovní
posloupnost mezi `about` a `excerpt`) odhalil, že první draft perexu u ~56
receptů převzal jednu větu z `about` téměř doslovně (v nejhorších případech
20–25 shodných slov v řadě, u několika receptů šlo o 100% identickou větu).
Toto je reálné porušení požadavku "perex musí být odlišný od about", ne
nevyhnutelný faktový překryv.

**Oprava**: přepsána pouze duplicitní věta v `excerpt` (57 receptů celkem,
`about` ponecháno beze změny jako existující produkční obsah) — stejný fakt,
jiná formulace. Word-count po opravě ověřen znovu (stále 70–110 u všech).

**Výsledek**:
- Před opravou: max. shoda 25 slov, 46 receptů ≥15 slov shody, 58 ≥12 slov.
- Po opravě: max. shoda 11 slov (`shopska-salata`), 0 receptů ≥12 slov.
- Zbývající shody 6–11 slov jsou krátké, nevyhnutelné faktové fráze (např.
  konkrétní číslo/jméno/technika popsaná jen jedním přirozeným způsobem) —
  ne duplicitní věty.

## F. Analýza 63 relation warnings (53 `related_glossary` + 10 `related_recipes`)

`content-quality-gate.php` hlásí 63 warnings beze změny před i po opravě
dat. **Všech 63 prověřeno ručně a jde o falešně pozitivní nálezy** —
nástrojové omezení, ne chyba obsahu:

- `content-quality-gate.php` (řádky 137–146) validuje `related_recipes` a
  `related_glossary` proti poli **`slug`** cílové položky.
- Skutečný importer (`resolve_reference()`) ale `atlas_recipe` reference
  resolvuje přes **`recipe_key`** a glosářové reference přes
  **`translation_group`** — ne přes `slug`.
- U většiny položek se `slug` a `translation_group`/`recipe_key` shodují,
  ale ne vždy (např. recept "Řecký salát" má `slug: recky-salat`, ale
  `translation_group: horiatiki`). Nástroj proto u těchto výjimek hlásí
  "unknown slug", i když reference je z pohledu skutečného importeru
  naprosto validní.

**Důkaz correctness dat**: ručně ověřeno všech 63 hodnot proti kompletnímu
seznamu `translation_group` (glosář, 50 položek) a `translation_group`
(recepty, 100 položek před rename = 100 hodnot použitých v `recipe_key`
po migraci) — **každá jedna** flagovaná hodnota (`marinating`, `roux`,
`proofing`, `braising`, `paprika-spice`, `springform-pan`, `emulsion`,
`saffron`, `arborio-rice`, `reduction`, `olive-oil`, `gratin`, `grilling`,
`mortar-and-pestle`, `pdo-pgi`, `bulgarian-yogurt`, `kitchen-thermometer`,
`dutch-oven`, `smorgasbord`, `poaching`, `svickova`, `pirohy-bryndza`,
`coq-au-vin`, `boeuf-bourguignon`, `banitsa`, `horiatiki`, `kottbullar`)
odpovídá existující, reálné cílové položce.

**Rozhodnutí**: **žádná změna dat** — hodnoty jsou už správné podle
skutečného importer kontraktu. Změna hodnot na `slug`, aby nástroj přestal
hlásit warning, by naopak DATA POKAZILA (importer by je pak nedohledal).
Oprava nástroje je mimo rozsah tohoto checkpointu (viz sekce M — žádné
změny kódu) a je zdokumentována zde jako doporučení pro budoucí krok:
`content-quality-gate.php` by měl `related_recipes`/`related_glossary`
validovat proti `recipe_key`/`translation_group`, ne proti `slug`.

## G. Audit zemí (20/20)

- `locale`: 100 % `cs-CZ` (konzistentní s recepty).
- `related_countries`: všech 20 zemí odkazuje výhradně na ISO kódy
  přítomné v batchi — 0 chybných odkazů.
- `related_glossary`: všechny hodnoty odpovídají existujícím
  `translation_group` v glosáři — 0 chybných odkazů.
- `translation_group` u zemí je `null` — očekávané a správné, protože
  stabilní klíč země je `iso_code` (`stable_key_for()`), `translation_group`
  se pro `atlas_country` nepoužívá.
- `content-quality-gate.php`: 0 ERROR, 0 WARNING u zemí (jen INFO o
  chybějících obrázcích, viz sekce J).

Žádná změna nutná.

## H. Audit glosáře (50/50)

- `locale`: 100 % `cs-CZ`.
- `short_definition`: 50/50 vyplněno.
- `related_recipes`: ověřeno proti všem 100 `recipe_key` — 0 chybných
  odkazů.
- `related_countries`: ověřeno proti všem 20 ISO kódům — 0 chybných
  odkazů.
- `origin_country`: u vyplněných hodnot 100 % platné ISO kódy; 21 hesel
  (obecné techniky/nástroje jako "blanšírování", "redukce", "julienne",
  "mandolína") nemá `origin_country` vůbec — očekávané, jde o
  mezinárodní/obecné pojmy bez jedné země původu, ne o chybějící data.

Žádná změna nutná.

## I. Audit ingrediencí / jednotek / kontrolovaných tagů

`content-quality-gate.php` reuse `Atlas_Chuti_Units::normalize()` a
`Atlas_Chuti_Taxonomy_Labels::keys('atlas_recipe_tag')` (stejné třídy jako
skutečný importer/theme). Výsledek po migraci: **0 warnings** pro
neznámé jednotky, **0 warnings** pro neznámé kontrolované tagy — beze
změny oproti Kroku 9 baseline (0 chyb jednotek). Žádná regrese, žádná
změna nutná.

## J. Seznam chybějících obrázků

Beze změny od Kroku 9 (batch neobsahuje pole `image`/`photo` u žádné
položky). **Žádné URL ani název souboru nebyly vymýšleny** — pouze
dokumentován rozsah:

- 100/100 receptů bez obrázku
- 20/20 zemí bez obrázku
- Glosář obrázky nepoužívá (mimo schéma)

Recepty i země se do doby doplnění fotografií zobrazí s fallback grafikou;
`og:image`/`Recipe`-schema `image` pole zůstanou prázdná (žádný fake
fallback obrázek), přesně podle chování popsaného v Kroku 9.

## K. `content-quality-gate.php` — souhrn před/po

| | Před checkpointem | Po checkpointu |
|---|---|---|
| ERROR | 100 (chybějící `recipe_key` u všech receptů) | **0** |
| WARNING | 63 (53 `related_glossary` + 10 `related_recipes`) | 63 (beze změny — viz sekce F, nástrojové omezení, ne chyba dat) |
| INFO | 220 | 220 |

## L. Validace a regrese

- **JSON validita**: všech 7 souborů v `production-data/europe-1/` (`python3
  json.load`) — OK.
- **`content-quality-gate.php`**: viz sekce K, read-only, žádný zápis.
- **`tests/harness-step-03.php`** (recipe_key/translation_group importer
  kontrakt, syntetický stub layer): **64/64 checks passing** — potvrzuje, že
  migrace v sekci C odpovídá přesně tomu, co importer očekává.
- **`tests/harness-step-09.php`**: **51/51 checks passing** — beze změny,
  žádný kód nebyl v tomto checkpointu upraven.
- **Žádný import do WordPress DB** neproběhl (dry-run/read-only nástroje
  only).

## M. Rozsahová disciplína — co NEBYLO měněno

- `01-countries.json`, `02-glossary.json` — auditováno, beze změny (čisté).
- Žádný soubor mimo `production-data/europe-1/` a tento report.
- Žádný PHP/JS/theme/plugin kód (`class-json-importer.php`,
  `content-quality-gate.php` apod.) — i přes nalezené nástrojové omezení
  v sekci F zůstal kód nedotčen, nález je jen zdokumentován.
- Žádný anglický (EN) překlad — mimo rozsah checkpointu 10A; nová pole
  (`recipe_key`, přejmenovaný `translation_group`) jsou ale navržena tak,
  aby budoucí EN pass mohl stabilně párovat přes stejné klíče.
- Žádné `related_glossary`/`related_recipes` hodnoty neuhodnuty ani nově
  vymyšleny — všechny už mířily na reálné existující cíle (sekce F).
- Žádné obrázky stažené/vymyšlené (sekce J).

## N. Finální stav

- **Branch**: `claude/new-session-0gc4ib`
- **HEAD před checkpointem**: `277b1d9`
- **Změněné soubory** (a pouze tyto): `production-data/europe-1/03-recipes-czechia-slovakia-poland-germany.json`, `04-recipes-austria-hungary-italy-france.json`, `05-recipes-spain-portugal-greece-croatia.json`, `06-recipes-slovenia-serbia-romania-bulgaria.json`, `07-recipes-uk-ireland-sweden-norway.json`, `docs/implementation-reports/content-batch-europe-1-remediation.md`
- **Počty**: 100/100 receptů migrováno (`recipe_key` + `translation_group`
  rename), 100/100 perex přepsáno, 57/100 perex dodatečně opraveno kvůli
  duplicitě s `about`
- **Word-count stats**: min 70, max 95, průměr 80,3 slova (rozsah 70–110
  dodržen u všech 100)
- **Relation warnings**: 63 → 63 (0 skutečných chyb; viz sekce F)
- **Quality gate**: 100 ERROR → 0 ERROR; 63 WARNING beze změny (false
  positives); 220 INFO beze změny
- **Regrese**: harness-step-03 64/64, harness-step-09 51/51, oba beze změny
  kódu
- **Push**: viz commit níže

---

## Potvrzovací blok

```
CHECKPOINT 10A DOKONČEN
Branch: claude/new-session-0gc4ib
HEAD (před): 277b1d9
Recepty migrovány (recipe_key): 100/100
Perex přepsán (70-110 slov): 100/100
About/perex duplicita opravena: 57/100
Relation warnings: 63 → 63 (0 skutečných chyb, viz sekce F)
Quality gate ERROR: 100 → 0
Quality gate WARNING: 63 → 63 (nástrojové omezení, ne data)
JSON validace: 7/7 souborů OK
Regrese: harness-step-03 64/64, harness-step-09 51/51
Import do WP DB: NEPROVEDEN
Kód změněn: NE
production-data/europe-1/{01-countries,02-glossary}.json: NEZMĚNĚNO
```
