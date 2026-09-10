# Atlas chutí — WordPress

Vlastní WordPress projekt "Atlas chutí" postavený od nuly: moderní kulinářský
atlas kombinující profily zemí, strukturované recepty, kuchařský slovníček a
"Kulinářský pas". Žádný page builder, žádná placená závislost, běží na běžném
sdíleném PHP hostingu.

Vizuální reference: `atlas_chuti_claude_design.zip` (Claude Design export) —
theme z něj přebírá barevnou paletu (teplé světlé pozadí, terakotový akcent),
typografii (Newsreader + Work Sans) a charakter karet/zaoblení, ale je to čistý
WordPress theme + plugin, ne export designu.

## Struktura repozitáře

```
wp-content/
  themes/atlas-chuti/        Frontend: šablony, CSS, JS. Bez datové logiky.
  plugins/atlas-chuti-core/  Datový model: CPT, taxonomie, meta pole, JSON import, SEO.
schema/                      JSON Schema pro import (recipe/country/glossary/ingredient).
sample-data/                 Ukázková a testovací data (Itálie/Japonsko/Thajsko + 3 recepty).
```

Datový model je v pluginu, ne v theme — theme lze v budoucnu vyměnit beze
ztráty obsahu (viz zadání, bod 3 a 7).

## Požadavky

- PHP 7.4+ (testováno na 8.x)
- WordPress 6.x
- MySQL/MariaDB
- WP-CLI (volitelné, pro `wp atlas import`)

Žádný Node.js/Next.js/Docker/Redis/VPS není na produkci potřeba.

## Instalace

1. Zkopírujte `wp-content/themes/atlas-chuti` a
   `wp-content/plugins/atlas-chuti-core` do odpovídajících složek běžné
   instalace WordPressu (nebo tento repozitář rovnou naklonujte jako
   `wp-content`, pokud si tak hosting nastavíte).
2. V administraci aktivujte plugin **Atlas chutí – Core**
   (Pluginy → Aktivovat). Tím se zaregistrují obsahové typy, taxonomie a
   výchozí termy (6 světadílů, 3 úrovně obtížnosti, 2 diety, 4 kategorie
   slovníčku).
3. Aktivujte theme **Atlas chutí** (Vzhled → Motivy).
4. V Nastavení → Trvalé odkazy klikněte "Uložit změny" (přegeneruje
   přepisovací pravidla pro `/recepty/`, `/zeme/`, `/slovnicek/`).
5. Vytvořte dvě stránky a přiřaďte jim šablony:
   - stránka se slugem **zeme** → šablona "Země (landing)"
   - stránka se slugem **kulinarsky-pas** → šablona "Kulinářský pas"
   (Volitelně i další obecné stránky z bodu 21 zadání — O projektu, Kontakt,
   Redakční zásady, Ochrana osobních údajů, Cookies, Podmínky používání,
   Inzerce — všechny fungují s výchozí šablonou `page.php`.)
6. V Vzhled → Menu vytvořte hlavní menu (Země / Recepty / Kuchařský
   slovníček / Kulinářský pas) a přiřaďte ho pozici "Hlavní menu". Pokud menu
   nevytvoříte, header použije stejné čtyři odkazy automaticky.
7. Nahrajte testovací data (viz níže).

## Datový model

Vlastní obsahové typy (registruje `atlas-chuti-core`):

| CPT               | Účel                                              | Veřejné |
|-------------------|----------------------------------------------------|---------|
| `atlas_recipe`    | Recept                                             | ano (`/recepty/…`) |
| `atlas_country`   | Země / gastronomická destinace                     | ano (`/zeme/…`) |
| `atlas_glossary`  | Pojem kuchařského slovníčku                        | ano (`/slovnicek/…`) |
| `atlas_ingredient`| Normalizovaná ingredience ("rajče"="rajčata")      | ne (interní) |

Taxonomie:

- `atlas_continent` — 6 světadílů, na `atlas_country` i `atlas_recipe`
  (u receptu se automaticky dopočítá podle jeho země).
- `atlas_country_tax` — "zrcadlo" CPT `atlas_country` (stejný slug), navěšeno
  na recepty a slovníček kvůli rychlému a URL-friendly filtrování. Spravuje
  se automaticky při uložení Země (`class-country-sync.php`) — v adminu se
  needituje přímo.
- `atlas_meal_type`, `atlas_difficulty`, `atlas_diet` — na receptu.
- `atlas_glossary_category` — na slovníčku.

Všechna vlastní pole (perex, ingredience, postup, fakta o zemi…) jsou
definovaná na jednom místě: `includes/class-meta-fields.php`. Tenhle soubor
používají zároveň admin formuláře, JSON import i validace — jde o jediný
zdroj pravdy pro datový model (viz zadání, bod 27/29: dnešní import = zítřejší
formát pro AI).

Regiony uvnitř zemí (Itálie → Toskánsko) nejsou v první fázi obsahově
vyplněné, ale `atlas_continent`/`atlas_country_tax` jsou obyčejné WordPress
taxonomie, takže přidat hierarchický podtaxonomický term nebo novou taxonomii
"region" později nevyžaduje změnu schématu.

### Ingredience a přepočet porcí

Ingredience se ukládají strukturovaně (`ingredient_id`, `name`, `quantity`,
`unit`, `note`, `group`), ne jako volný text. `quantity` může být číslo,
desetinné číslo, jednoduchý zlomek ("1/2", "1 1/2") nebo text ("podle chuti",
"špetka") — `Atlas_Chuti_Servings::parse_quantity()` pozná, co je
matematicky přepočitatelné, a přepínač porcí (2|4|6|8, JS bez reloadu)
přepočítává jen tyto položky.

## Import obsahu (JSON)

**Atlas chutí → Import obsahu** v administraci (nebo `wp atlas import
soubor.json`) přijímá jeden JSON soubor s libovolnou kombinací klíčů
`ingredients`, `countries`, `glossary`, `recipes` — viz `/schema` (JSON
Schema, draft-07, s popisky u každého pole) a `/sample-data` (funkční
ukázky).

Zpracování:

1. Validace povinných polí (chybějící pole = chyba u toho řádku, zbytek
   dávky pokračuje dál).
2. Vazby mezi entitami (země receptu, související recepty/pojmy…) se zadávají
   **slugem**, ne číselným ID — stabilní napříč re-importy a stejný formát,
   jaký bude jednou generovat AI.
3. Dvouprůchodové zpracování: nejdřív se založí/aktualizují všechny entity,
   pak se ve druhém průchodu dopočítají vzájemné vazby (funguje i pro
   dopředné odkazy v rámci jednoho souboru).
4. Kontrola duplicit podle slugu (recept navíc podle originálního názvu +
   země) — nalezená shoda se aktualizuje (upsert), nevytváří duplicitní
   příspěvek.
5. **Zkontrolovat (dry-run)** spustí totéž bez zápisu do databáze a ukáže
   náhled výsledku.
6. Import nikdy nevytváří hotové HTML stránky — vždy jen strukturovaná
   WordPress data (CPT + meta + taxonomie).

Testovací data pro ověření datového modelu: `sample-data/batch-import-sample.json`
obsahuje Itálii, Japonsko a Thajsko, po jednom receptu z každé (Spaghetti
Carbonara, Miso ramen, Pad Thai) a pár pojmů do slovníčku — přesně rozsah
popsaný v bodě 28 zadání. Nahrajte ho v administraci, nebo:

```
wp atlas import sample-data/batch-import-sample.json
```

Prvních 50 produkčních receptů je záměrně samostatný následující krok, ne
součást tohoto importu.

## Kulinářský pas

V1 bez registrace — vše v `localStorage` prohlížeče
(`wp-content/themes/atlas-chuti/assets/js/passport.js`, objekt
`window.AtlasPassport`). Recept/země ukládá kompletní zobrazovací data (ne
jen ID), takže stránka pasu nepotřebuje žádný další dotaz do WordPressu.
Až přibude uživatelský účet, stačí v `passport.js` nahradit `load()`/`save()`
voláním na REST endpoint — zbytek webu (tlačítka na receptu/zemi, homepage
widget, stránka pasu) zůstává beze změny.

## SEO

`class-seo.php` doplňuje meta description, canonical, OpenGraph a
strukturovaná data (WebSite, Recipe, BreadcrumbList) sestavená výhradně ze
skutečných polí receptu/země — nic se nevymýšlí. Pokud je aktivní Yoast,
RankMath nebo SEOPress, vlastní výstup se automaticky vypne, aby nedocházelo
k duplicitě.

## Nasazení na sdílený hosting (např. FORPSI Easy Linux)

1. Standardní instalace WordPressu (přes rychlou instalaci hostingu, nebo
   ručním nahráním WP core přes FTP/SFTP).
2. Do `wp-content/themes/` a `wp-content/plugins/` nahrajte obsah tohoto
   repozitáře stejnojmenně.
3. Aktivujte plugin, theme, uložte trvalé odkazy — viz Instalace výše.
4. Nahrajte fotografie přes Media Library (bod 24 zadání — bez AI generování
   obrázků v této fázi; recept/země bez fotky zobrazí jednotný placeholder).
5. Volitelně self-hostujte fonty místo Google Fonts CDN — návod v
   `wp-content/themes/atlas-chuti/assets/fonts/README.md`.
6. Nastavte zálohování databáze a `wp-content/uploads` na úrovni hostingu
   (mimo git, viz `.gitignore`).

## Budoucí AI integrace

Zatím nezapojeno (bod 29 zadání). Až přibude, bude to samostatný modul
(např. `atlas-chuti-ai`), který bude generovat přesně ten samý strukturovaný
JSON, jaký dnes přijímá `Atlas chutí → Import obsahu` — `atlas-chuti-core` na
OpenAI nijak přímo nezávisí.

## Vývoj

- `wp atlas import <soubor.json> [--dry-run]` — CLI import (sdílí kód s
  administrací).
- Kódovací standardy: WordPress Coding Standards, nonces + capability checks
  na všech uloženích, sanitizace vstupu / escapování výstupu, žádné
  hardcoded přihlašovací údaje v repozitáři.
