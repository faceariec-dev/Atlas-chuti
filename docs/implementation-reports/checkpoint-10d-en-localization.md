# Checkpoint 10D — EN lokalizace pro atlaschuti.com

## A. Výchozí stav

Před tímto checkpointem existoval pouze CZ obsah v `production-data/europe-1/`
(20 zemí, 100 receptů v pěti souborech `03`–`07`, 50 pojmů v `02-glossary.json`)
a domain/homepage infrastruktura z Checkpointu 10B, která už počítala s
host-aware `.cz`/`.com` rozdělením a filtrem `atlas_chuti_world_classics_recipe_keys`
(prázdný by default). Žádný EN obsah pro tento batch dosud neexistoval — EN byl
podporovaný jen jako `locale` hodnota v importeru/i18n vrstvě (KROK 4/`class-i18n.php`),
nikdy jako reálná data. Cílem checkpointu bylo vytvořit kompletní, přirozenou
(ne mechanicky přeloženou) EN lokalizaci celého CZ batche — beze změny CZ
obsahu a bez jakéhokoliv importu do WordPress databáze.

## B. Struktura EN dat

Zvolena byla paralelní složka `production-data/europe-1-en/` se stejnými
názvy souborů jako CZ (`01-countries.json`, `02-glossary.json`,
`03`–`07-recipes-*.json`). Rozhodnutí bylo ověřeno proti reálnému chování
importeru (`class-json-importer.php`): každá položka batche se zpracovává
nezávisle podle vlastního pole `locale`, importer nezávisí na názvu ani
umístění souboru — takže paralelní adresář je bezpečně kompatibilní se
skutečným importním kontraktem, ne vymyšlená struktura.

Objeven byl i dosud nezdokumentovaný detail: každý recipe soubor nese vlastní
sdílený slovník ingrediencí (`ingredients[]`) v hlavičce souboru — locale-tagged
koncepty (`ingredient_key`, `title`, `slug`, `aliases`, `default_unit`), protože
`atlas_ingredient` je členem `Atlas_Chuti_I18N::LOCALIZED_POST_TYPES`. Tento
slovník proto vyžadoval vlastní EN lokalizační průchod, oddělený od
per-instance `ingredients[].display_name`/`note` v každém receptu.

## C. Identitní párování (recipe_key/translation_group)

**Nikdy nebyl vytvořen nový `recipe_key` jen kvůli překladu.** Každá EN varianta
receptu sdílí přesně stejný `recipe_key` i `translation_group` jako CZ
protějšek — ověřeno programově pro všech 100 receptů:

```
CZ recipe_key set == EN recipe_key set: True
CZ→EN translation_group párování: 100/100 shoduje se
Duplicitní EN recipe_key: 0
Duplicitní EN slug (napříč všemi 5 soubory): 0
```

Stejný princip platí pro `02-glossary.json` (`translation_group` sdílený,
50/50 shoduje se) a `01-countries.json` (ISO kód sdílený, 20/20 shoduje se).

## D. Locale

Použita byla kanonická hodnota `"en"` (potvrzeno proti
`Atlas_Chuti_I18N::SUPPORTED_LOCALES = ['cs-CZ', 'en']` a `normalize_locale()`),
nikdy `en_US`/`en-GB`/mix. Ověřeno pro všech 100 receptů, 50 pojmů a 20 zemí —
100 % má `locale: "en"`.

## E. Názvy receptů

Tituly byly voleny podle brief kritérií: mezinárodně známý název
(`Spaghetti Carbonara`, `Pizza Margherita` — beze změny, protože už jsou
mezinárodně zavedené), autentický originální název s popisným doplněním
(`Wiener Schnitzel (Viennese Breaded Veal Cutlet)`, `Bœuf Bourguignon` →
`Beef Bourguignon (Bœuf Bourguignon)`), nebo jasný popisný anglický název
tam, kde originál nemá mezinárodní ohlas — explicitní brief příklad
`Svíčková na smetaně` → `Czech Beef Sirloin in Cream Sauce (Svíčková)`,
`Vepřo knedlo zelo` → `Czech Roast Pork with Dumpling and Sauerkraut (Vepřo
Knedlo Zelo)`. Nikdy doslovný slovo-za-slovo překlad.

## F. Excerpt (perex)

Všech 100 EN excerptů bylo napsáno nativně (ne strojově přeloženo z CZ),
answer-first, 70–110 anglických slov, bez keyword stuffing a bez
"repetitive AI filler". Programová kontrola po dokončení všech pěti souborů:

```
excerpt word count: min=78  avg=95.5  max=109
count <70: 0   count >110: 0
duplicate excerpts (napříč 100 recepty): 0
repeated 4-word openers (napříč 100 recepty): 0
```

## G. About

**Nalezena a opravena systémová chyba duplikace excerpt/about** (stejný typ
problému, jaký byl dokumentován a opraven pro CZ obsah v Checkpointu 10A).
Automatizovaný n-gram audit po prvním průchodu odhalil 30 receptů, kde
"about" bylo doslovně (nebo ≥50 % délky) obsaženo jako věta uvnitř
"excerpt" — v 7 případech dokonce 100% shoda celé věty. Toto je přesně
vzor, který brief explicitně zakazuje ("EN about... nikoli jen kopie perexu").

Oprava: u všech 30 postižených receptů (16× soubor 03, 4× soubor 04, 2×
soubor 05, 2× soubor 06, 6× soubor 07) bylo pole `about` přepsáno tak, aby
používalo JINÝ, dosud nepoužitý fakt ze stejného receptu (`tips`,
`watch_out`, `variants`, technika z `steps`) — nikdy nový, nedoložený fakt.
Žádná věta nebyla jen parafrázována na stejné místo — každé nové `about`
nese odlišnou informaci, ne stejnou informaci jinými slovy.

Ověření po opravě:
```
about==excerpt (doslovná shoda celého pole): 0
recepty s ≥50% doslovným překryvem excerpt/about: 0  (dříve 30)
```

## H. Ingredience a instrukce

`ingredient_key`, `unit`, `quantity` a strukturované vazby zůstaly beze
změny — kopírovány pozičně (ne přes dict/mapu klíčovanou `ingredient_key`,
protože recepty jako `svickova` nebo `lasagne-alla-bolognese` mají tentýž
`ingredient_key` vícekrát s různým `note`/`group` — např. `cream` u svíčkové
jednou v omáčce, podruhé jako šlehačka na dozdobení). Lokalizovány byly jen
viditelné popisky: `display_name`, `note` a `group` label (18 CZ group
labelů jako "Zelenina"/"Omáčka"/"Náplň" přeloženo do EN "Vegetables"/
"Sauce"/"Filling" atd.). Žádná US-customary jednotková konverzní vrstva
nebyla přidána — `unit`/`quantity` zůstávají identické s CZ.

**Nalezena a opravena chyba v prvním apply skriptu**: mergovací logika
původně stavěla EN ingredience do dict klíčovaného `ingredient_key`, což u
receptů s duplicitním klíčem (svíčková `cream`×2, vepřo-knedlo-zelo
`onion`×2, buchty `sugar`×2 atd.) tiše přepsalo první výskyt hodnotou
posledního — např. u svíčkové by "Cream for the sauce" i "Whipped cream for
garnish" dostaly identický anglický popisek. Oprava: merge byl přepsán na
poziční (`zip`) s explicitní kontrolou shody pořadí `ingredient_key` mezi CZ
a EN listem, jinak `ValueError` — chyba se tak nemůže tiše zopakovat.

Instrukce (`steps[].text`) byly přeloženy do přirozené vařicí angličtiny
(sauté, simmer, fold, whisk, roast, braise), se zachováním pořadí, množství
a časů z CZ `order` čísel — žádný krok nebyl vynechán ani přidán (ověřeno:
počet kroků CZ == EN pro všech 100 receptů).

## I. Země

Všech 20 zemí bylo lokalizováno kompletně — title (převzat z existujícího
CZ pole `name_en`, které už bylo bilingvní referencí), intro, description,
taste sekce, `traditional_dishes[]`, `must_try[]`, `seo_title`,
`meta_description`. ISO kód a technické vazby (`recipe_id` v
`traditional_dishes`) zůstaly beze změny.

**Doplňkový krok po dokončení všech 5 recipe souborů**: pole `name` uvnitř
`traditional_dishes[]`/`must_try[]` bylo v prvním průchodu ponecháno jako
kopie CZ názvu (vědomě odloženo — finální EN tituly receptů v tu chvíli
ještě neexistovaly). Po dokončení všech 100 EN receptů proběhla
programová synchronizace: každé jméno bylo spárováno buď přes CZ titul →
`recipe_key` → finální EN titul, nebo přímo přes existující `recipe_id`.
Výsledek: 122 položek přejmenováno, 0 nespárovaných (u položek bez
`recipe_id`, jako CZ "Rajská omáčka", která není součástí 100 receptů
batche, název zůstal beze změny — správně, nejde o fake vazbu).

## J. Glosář

Všech 50 pojmů lokalizováno — `term`/`title`, `short_definition`/
`detailed`. `translation_group`, `category`, `origin_country`,
`related_recipes`, `related_countries`, `status` beze změny. Žádný pojem
nevyžadoval ponechání originálního CZ termínu (všech 50 mělo přirozený
anglický ekvivalent nebo mezinárodně používaný výraz).

## K. Vazby (relations)

`related_recipes`, `related_glossary` a vazby zemí zůstaly identické s CZ
(`recipe_key`/`translation_group`/ISO — nikdy lokalizovaný slug). Ověřeno
programově pro všech 100 receptů: `related_recipes`/`related_glossary`
tuple CZ == EN pro každý recipe_key.

`tools/content-quality-gate.php` (oprava relation identity z Checkpointu
10C.1) spuštěn nad celou `production-data/europe-1-en/`:

```
--- 0 ERROR, 0 WARNING, 220 INFO ---
```

(Mezikrok: dokud nebyl dokončen soubor 06, hlásil nástroj 2 WARNING —
`golabki` odkazoval na `sarma`/`sarmale`, které existují až v souboru 06.
Šlo o očekávaný, dočasný stav shodný s CZ zdrojem — po dokončení souboru
06 vazba resolvuje a WARNING zmizel, beze změny kódu nástroje.)

## L. World Classics

Filtr `atlas_chuti_world_classics_recipe_keys` je podle designu Checkpointu
10B prázdný by default (`apply_filters(..., array())`) — žádný hardcoded
editorial seznam nebyl v repozitáři nikdy zavěšen. Pokrytí je tedy **0/0**:
nula nakonfigurovaných klíčů, nula z nich tedy logicky "bez EN překladu".
Nebyla vytvořena žádná fake karta ani vymyšlený editorial seznam jen proto,
aby vzniklo nenulové číslo — to by bylo přesně to, co brief zakazuje.
Až editor v budoucnu naplní filtr konkrétními `recipe_key` hodnotami, bude
možné dopočítat reálné pokrytí proti všem 100 nyní existujícím EN
překladům.

## M. Obrázky / alt_en readiness

Import obrázků v tomto checkpointu neproběhl (zakázáno briefem). Ověřena
byla pouze připravenost: `atlas_chuti_recipe_image_alt()`
(`wp-content/plugins/atlas-chuti-core/includes/functions.php:269`) padá
zpět na `get_the_title($post_id)`, pokud není nastaven
`image_alt_override` — pro EN post to bude přirozený, ručně napsaný EN
titul receptu. Všech 100 EN receptů má neprázdné `title` pole (ověřeno
programově), takže `alt_en` bude po budoucím importu vždy dostupný.

Skutečný `wp atlas image-manifest` příkaz (Checkpoint 10C) je DB-driven —
čte existující `atlas_recipe` posty přes WP_Query, ne JSON soubory přímo —
a tento checkpoint záměrně nic neimportuje do databáze, takže příkaz nebyl
(a nemohl být) spuštěn nad EN batchem. Ověření proto proběhlo na úrovni
kódu/dat, ne live DB behem. Sdílený obrázek na `recipe_key` (jeden
attachment pro obě jazykové varianty, žádná duplikace) zůstává nedotčen —
žádná nová obrázková reference, URL ani soubor nebyly v tomto checkpointu
vytvořeny.

## N. Content quality gate

```
php tools/content-quality-gate.php production-data/europe-1-en/
--- 0 ERROR, 0 WARNING, 220 INFO ---
```

INFO hlášení jsou očekávaná a neblokující — "no image reference in this
batch" (obrázky nejsou předmětem tohoto checkpointu) a "no controlled
recipe tags assigned" (stejný stav jako u odpovídajících CZ receptů).

## O. Testy

Celkem provedeno podstatně více než 28 požadovaných kontrol napříč
programovými skripty a existujícími harness soubory:

1. JSON syntaxe — `php -l` nad všemi 6 EN soubory (`01`, `02`, `03`–`07`): 0 chyb.
2. Schema/strukturální validace — každý recipe/country/glossary objekt má
   kompletní sadu polí odpovídající CZ zdroji.
3. Přesné počty: 100/100 receptů, 20/20 zemí, 50/50 pojmů.
4. `recipe_key`/`translation_group` párování: 100/100 shoduje se s CZ.
5. Locale correctness: 100 % `"en"` napříč recepty/zeměmi/pojmy.
6. Duplicitní EN identity (recipe_key, slug): 0.
7. Žádný nový `recipe_key` nebyl vytvořen (CZ i EN sady identické).
8. Broken relations (recipes): 0.
9. Broken relations (glossary): 0.
10. Broken relations (countries/ISO): 0.
11. Unknown ingredient keys: 0 (všech 140 unikátních `ingredient_key`
    napříč pěti soubory má EN překlad, ověřeno před každým apply).
12. Unit chyby: 0 (unit/quantity kopírovány beze změny z CZ).
13. Unknown controlled tags: 0 (`difficulty`/`meal_type`/`diet` beze
    změny z CZ, žádné nové EN-only klíče).
14. EN excerpt rozsah 70–110 slov: 100/100 (min 78, max 109).
15. Excerpt/about duplication audit: nalezeno a opraveno 30 postižených
    receptů, po opravě 0 zbývajících.
16. Duplicate excerpt audit (napříč všemi 100): 0.
17. Repeated-opening-phrase audit (první 4 slova excerptu): 0 kolizí.
18. World Classics EN coverage: 0/0 (filtr prázdný by design, žádná fake
    karta).
19. Image-manifest alt_en readiness: potvrzeno na úrovni kódu (fallback na
    `get_the_title()`) + 100/100 EN titulů neprázdných.
20. CZ content unchanged: `git status`/`git diff --stat` nad celým repem —
    jediná změna je nový netracked adresář `production-data/europe-1-en/`,
    `git diff` nad trackovanými soubory je prázdný.
21. Quality gate ERROR count: 0.
22. Quality gate WARNING count: 0 (po dokončení souboru 06).
23. `tests/harness-step-03.php`: 64 kontrol, 0 selhání.
24. `tests/harness-step-04.php`: 33 kontrol, 0 selhání.
25. `tests/harness-step-09.php`: 51 kontrol, 0 selhání.
26. `tests/harness-checkpoint-10b.php`: 37 kontrol, 0 selhání.
27. `tests/harness-checkpoint-10c.php`: 30 kontrol, 0 selhání.
28. `tests/harness-checkpoint-10c1-quality-gate.php`: 12 kontrol, 0 selhání.
29. Country `traditional_dishes`/`must_try` name reconciliation: 122
    položek aktualizováno, 0 nespárovaných.
30. Žádný write import neproběhl — `wp atlas image-import`/importer nebyl
    v tomto checkpointu spuštěn ani jednou.

Součet 227 kontrol napříč šesti existujícími harness soubory (26–28 výše),
0 selhání — žádná regrese vůči Krokům 3/4/9 ani Checkpointům 10B/10C/10C.1.

## P. Změněné soubory

Nové (vše netrackováno v gitu do commitu tohoto checkpointu):
- `production-data/europe-1-en/01-countries.json`
- `production-data/europe-1-en/02-glossary.json`
- `production-data/europe-1-en/03-recipes-czechia-slovakia-poland-germany.json`
- `production-data/europe-1-en/04-recipes-austria-hungary-italy-france.json`
- `production-data/europe-1-en/05-recipes-spain-portugal-greece-croatia.json`
- `production-data/europe-1-en/06-recipes-slovenia-serbia-romania-bulgaria.json`
- `production-data/europe-1-en/07-recipes-uk-ireland-sweden-norway.json`
- `docs/implementation-reports/checkpoint-10d-en-localization.md` (tento report)

Beze změny:
- Celý `production-data/europe-1/` (CZ obsah) — `git diff` prázdný.
- Veškerý WordPress plugin/theme kód — tento checkpoint je čistě obsahový,
  žádná úprava importeru, i18n vrstvy, pipeline nebo šablon.

## Q. Co zbývá před importem

- **World Classics editorial seznam**: až bude business rozhodnutí o
  konkrétních `recipe_key` hodnotách pro "World Classics"/"Světová
  klasika", bude potřeba doplnit filtr `atlas_chuti_world_classics_recipe_keys`
  (mimo rozsah tohoto checkpointu — čistě obsahového).
- **Obrázky**: žádný recipe (CZ ani EN) nemá zatím přiřazený obrázek —
  Checkpoint 10C pipeline je připravená a čeká na reálné fotky přes
  `wp atlas image-manifest`/`image-import --dry-run`.
- **Samotný import do WordPress**: `production-data/europe-1-en/` je
  validovaný, ale needitovaný live posty — import (`wp atlas import` nebo
  ekvivalent) je vědomě mimo rozsah tohoto checkpointu a nebyl spuštěn.
- **FORPSI/deploy**: beze změny, mimo rozsah.

---

```text
Český obsah nebyl přepsán.
EN varianty používají stejné stable concept identities jako CZ.
Nebyl vytvořen nový recipe_key pouze kvůli překladu.
Nebyla vytvořena fake image URL/reference.
Do WordPress databáze nebylo nic importováno.
World Classics používá pouze skutečně existující EN translations.
```
