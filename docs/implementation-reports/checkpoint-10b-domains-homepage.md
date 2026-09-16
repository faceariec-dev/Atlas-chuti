# Checkpoint 10B — atlaschuti.cz + atlaschuti.com a rozdílná homepage

## A. Audit

**Stav před checkpointem**: branch `claude/new-session-0gc4ib`, HEAD `048fce6`
("Content: remediate Europe batch for production import"), čistý strom.
Odpovídá očekávanému HEAD z briefu.

**`class-i18n.php`**: `Atlas_Chuti_I18N::current_locale()` byla jediné
místo, které cokoliv v projektu smí použít pro "jaký je aktuální jazyk"
(dokumentováno přímo v kódu). Před checkpointem: Polylang bridge → filtr
`atlas_chuti_current_locale` → `DEFAULT_LOCALE` (`cs-CZ`). Žádná znalost
domény/hostu nikde.

**`class-polylang-bridge.php`**: plně volitelná integrace
(`function_exists()` guardy všude, Polylang není v repozitáři vendorováno).
Vlastní docblok už předem počítal s "Domain-per-language mapping... je
Polylang's own site configuration, done in wp-admin" — ale fallback URL pro
chybějící překlad byl `home_url( 'en' === $target_locale ? '/en/' : '/' )`,
tedy natvrdo starý `/en/` model, který brief explicitně ruší.

**Podpora Polylang pro language-per-domain**: **nelze ověřit ze zdrojového
kódu tohoto repozitáře** — Polylang je externí plugin, nikdy sem
nevendorovaný, veškerá integrace jde přes `function_exists()` volání.
Domain-mapping ("different domains for each language") je navíc funkce,
jejíž přesná dostupnost/edice (a zda je v produkci vůbec nakonfigurovaná)
nelze z kódu zjistit. Proto **checkpoint nezávisí na této Polylang funkci
vůbec** — staví vlastní, explicitní host↔locale vrstvu (sekce B), která
funguje korektně bez ohledu na to, jestli Polylang doménové mapování má
nebo nemá zapnuté. Pokud ho admin i tak zapne, obě vrstvy se nebijí (obě
vedou ke stejnému výsledku pro `atlaschuti.cz`/`atlaschuti.com`).

**`class-seo.php`**: canonical/robots/hreflang/OG/schema stavěné výhradně
na `get_permalink()`/`home_url()`/`get_pagenum_link()`/`get_term_link()` —
tedy VŠECHNY procházejí přes WordPress core `home_url()`. To je klíčový
nález: jeden filtr na `home_url` stačí k tomu, aby byly host-aware úplně
všechny (canonical, hreflang, OG, sitemap, REST) najednou, beze změny
jejich vlastního kódu. Nález k opravě: `Organization` JSON-LD `@id`/`url`
používaly `home_url()` — jakmile se `home_url()` stane host-aware, každá
doména by si vyrobila JINÉ `@id`, tedy dvě různé Organizations pro jednu
značku (sekce E).

**`header.php`**: `atlas_chuti_language_switcher()` už existuje a
gracefully řeší "žádný překlad → fallback", žádná změna šablony nutná.

**`front-page.php`**: jedna sdílená šablona, žádná paralelní EN varianta
— přesně to, co brief chce zachovat.

**URL helpery**: `atlas_chuti_system_url()`/`atlas_chuti_resolve_system_page()`
staví na `home_url()` a `get_permalink()` — host-aware zdarma.

**Sitemap filtry** (`filter_sitemap_post_types`/`filter_sitemap_taxonomies`):
žádný host-specifický kód, žádná změna nutná — host-awareness přichází
kombinací (1) host-aware `current_locale()`, (2) existujícího
`scope_query_to_locale()` (běží i uvnitř WP Core sitemap providerova
vlastního `WP_Query`, protože `pre_get_posts` se spouští pro každý
`WP_Query`), (3) host-aware `home_url()`.

**My Atlas REST/auth**: `class-rest-api.php` — žádný existující CORS kód
(žádný `Access-Control-Allow-Origin`, žádný `rest_send_cors_headers`
override). `class-account.php`: standardní `wp_set_auth_cookie()`, žádný
`COOKIE_DOMAIN` override, žádný cross-domain token hack. JS lokalizace
(`functions.php`) staví `restUrl` na `rest_url('atlas-chuti/v1')`, což
interně volá `home_url()` — jakmile je host-aware, REST volání automaticky
míří na AKTUÁLNÍ host (same-origin), takže žádný CORS vůbec není potřeba.

**Step 7 ads**: žádný `home_url()`/`HTTP_HOST` v `class-advertising.php`
apod. mimo obecné WP volání — beze změny.

**Step 9 canonical/hreflang**: audit potvrdil (sekce výše), architektura
je připravená na jeden `home_url` filtr.

**Existující `/en/` URLs**: `grep -r "/en/"` napříč `wp-content/` našel
POUZE komentáře/docblocky (nikdy skutečnou rewrite rule, registrovanou
stránku nebo publikovaný routing) — potvrzeno v `functions.php`,
`class-i18n.php`, `class-polylang-bridge.php` (opravený fallback),
`archive-atlas_topic.php`. **V kódu neexistuje žádná živá `/en/` URL**
(sekce L).

## B. Cílová architektura

Nová vrstva: `Atlas_Chuti_Domain_Map`
(`wp-content/plugins/atlas-chuti-core/includes/class-domain-map.php`) —
explicitní, kódem vlastněná mapa:

```php
const HOST_FOR_LOCALE = array(
    'cs-CZ' => 'atlaschuti.cz',
    'en'    => 'atlaschuti.com',
);
```

Dvě směry:
- **host → locale** (`locale_from_host()`): jen pro hosty v explicitním
  allowlistu (`host_locale_map()`, filtrovatelná přes
  `atlas_chuti_domain_map` pro staging). Neznámý host (localhost, staging,
  CLI bez `HTTP_HOST`) vrací `null` → volající vždy spadne zpět na
  původní (pre-10B) chování. Nula regresí pro testy/CLI.
- **locale → host** (`host_for_locale()`/`home_url_for_locale()`): pro
  generování URL druhé jazykové verze (switcher, hreflang).

Jeden filtr `add_filter('home_url', ...)` přepisuje jen HOST (a vynucuje
`https`) v URL, kterou WordPress core `home_url()` postavil — nikdy
`site_url`/`admin_url` (viz sekce C). To samo o sobě zajišťuje host-aware
canonical, OG, sitemap i REST, beze změny logiky v `class-seo.php`,
sitemap filtrech nebo `functions.php`.

`Atlas_Chuti_I18N::current_locale()` teď zkouší nejdřív
`Atlas_Chuti_Domain_Map::locale_from_host()` (explicitní host match),
pak Polylang bridge, pak filtr/default — přesně v tomto pořadí, protože
cílová architektura je host-per-language, ne path-per-language.

## C. Auth/session omezení

`atlaschuti.cz` a `atlaschuti.com` jsou dvě různé registrable domains —
`COOKIE_DOMAIN` cookie nelze bezpečně sdílet přes ně. V tomto checkpointu:

- **Nevytvořen žádný vlastní SSO.**
- **Žádný auth token v query stringu.**
- **Žádný cross-domain cookie hack.**
- `class-account.php` beze změny — `wp_set_auth_cookie()` zůstává
  standardní, jednodoménová.

**První verze (podle briefu)**: stejný WP účet a stejná DB identita
(uživatel, oblíbené, uvařené, hodnocení, kolekce...) na obou doménách —
stejné přihlašovací údaje fungují na `.cz` i `.com` — ale **session je
oddělená** (přihlášení na `.cz` nepřihlásí automaticky na `.com` a naopak,
dokud neexistuje bezpečnější mechanismus). To je přesně to, co
`Atlas_Chuti_Domain_Map`'s návrh nedělá — nedotýká se `site_url`/
`admin_url`/auth cookie vůbec, takže tohle omezení platí automaticky, bez
jakéhokoliv nového kódu.

`site_url()`/`admin_url()` zůstávají VŽDY na skutečném, jediném
nakonfigurovaném hostu (WP `siteurl` option) — `home_url` filtr se jich
nedotýká a navíc je `is_admin()`-guarded — takže wp-admin nikdy
nepřeskakuje mezi hosty.

## D. Host mapping

| Locale | Host | Zdroj pravdy |
|---|---|---|
| cs-CZ | atlaschuti.cz | `Atlas_Chuti_Domain_Map::HOST_FOR_LOCALE` |
| en | atlaschuti.com | `Atlas_Chuti_Domain_Map::HOST_FOR_LOCALE` |

Nikdy odvozeno z libovolného `$_SERVER['HTTP_HOST']` — pouze explicitní
allowlist match (`locale_from_host()`). Neznámý/staging host: bezpečný
fallback na existující (pre-10B) chování, žádná chyba, žádný guess.
Staging override: filtr `atlas_chuti_domain_map` (přidá další
host→locale páry) a `atlas_chuti_host_for_locale` (přepíše, jaký host se
použije pro danou locale při generování URL) — obojí beze změny kódu,
z `functions.php` mu-pluginu nebo theme `functions.php`.

## E. Canonical/hreflang/x-default

**Canonical**: `get_canonical_url()` (class-seo.php) beze změny logiky —
staví se na `get_permalink()`/`home_url()`, které jsou teď host-aware.
Otestováno: CZ request → `.cz` canonical, EN request → `.com` canonical,
libovolný neznámý `HTTP_HOST` → canonical na DEFAULT_LOCALE (`.cz`), nikdy
na ten neznámý host. Cook Mode canonical (query-string-free permalink)
zůstává na správném hostu. Žádný finální canonical neobsahuje `/en/`.

**hreflang**: `output_hreflang()`/`get_locale_urls()` beze změny logiky —
reciproční ze stejné mapy, nikdy fake alternate pro chybějící překlad
(existující "empty array" pravidlo z Kroku 4/9 zůstává). Host-aware
automaticky.

**x-default**: **ponechán na `cs-CZ`/`atlaschuti.cz`** (žádná změna kódu
nutná — `Atlas_Chuti_I18N::DEFAULT_LOCALE` už byl `cs-CZ` a
`get_locale_urls()` teď vrací host-aware URL). Zdůvodnění: `DEFAULT_LOCALE`
je provlečený napříč celým kódem jako "locale bez explicitního signálu"
(legacy-content OR-fallback v `scope_query_to_locale()`, importer default,
každá existující testová fixture) — přepnutí x-default na `.com` bez
přejmenování `DEFAULT_LOCALE` samotného by rozpojilo "SEO default" od
"skutečného obsahového defaultu" a riskovalo přesně tu nekonzistenci, před
kterou brief varuje. Otestováno (harness check #10) a zdokumentováno přímo
v `class-seo.php`'s docblocku nad `output_hreflang()`.

## F. Switcher

`class-polylang-bridge.php`'s `switcher_data()`/`url_for_locale()` beze
změny logiky pro EXAKTNÍ překlad (`get_permalink()`/`get_term_link()`,
host-aware automaticky). Změněn jen fallback (žádný skutečný překlad):
`pll_home_url()` (pokud Polylang sám doménové mapování má) →
`Atlas_Chuti_Domain_Map::home_url_for_locale()` jako zdroj pravdy —
nahrazuje starý natvrdo zapsaný `home_url( 'en' === $target_locale ? '/en/' : '/' )`.
Žádný slug nikdy neodhadnut — buď reálný `get_permalink()`/`get_term_link()`
výsledek, nebo cílové locale domovská stránka.

- CZ → EN switch: `https://atlaschuti.com/...` (exaktní překlad) nebo
  `https://atlaschuti.com/` (fallback).
- EN → CZ switch: `https://atlaschuti.cz/...` nebo `https://atlaschuti.cz/`.

## G. Homepage — editorial split

**Jedna šablona** (`front-page.php`), žádná paralelní EN varianta.
Editorial PRIORITA (ne obsah komponent) se liší podle `$is_en = 'en' ===
Atlas_Chuti_I18N::current_locale()` (host-aware, viz sekce B):

- **`.cz`** (Czech-first): existující pořadí zachováno — lead/hero →
  Co dnes vařit? → **Česká kuchyně** (banner) → **Světová klasika** →
  Co se vaří ve světě → nejnovější recepty → Magazín/Tipy → komunita →
  My Atlas panel.
- **`.com`** (global-first): stejné sekce, ale **World Classics** se
  vykresluje PŘED českým bannerem (schválený hlavní kurátorovaný blok),
  český banner zůstává viditelný (nikdy skrytý — "neschovávej"), jen ne
  jako první/dominantní blok.

Implementováno přes output buffering (`ob_start()`/`ob_get_clean()`) —
obě sekce jsou STEJNÝ PHP/markup blok, jen jejich pořadí `echo` volání se
liší podle `$is_en`. Žádný druhý template soubor, žádná duplicitní
komponenta.

## H. World Classics

Nová funkce `atlas_chuti_home_world_classics( $limit )`
(`wp-content/themes/atlas-chuti/inc/homepage.php`):

1. `atlas_chuti_world_classics_recipe_keys()` — **prázdné pole ve
   výchozím stavu**, filtrovatelné přes `atlas_chuti_world_classics_recipe_keys`.
   Checkpoint 10B dodává jen INFRASTRUKTURU, ne redakční rozhodnutí —
   žádný recipe_key není v tomto commitu natvrdo zvolen jako "světová
   klasika" (to je redakční rozhodnutí mimo rozsah tohoto checkpointu).
2. Každý klíč se resolvuje přes `Atlas_Chuti_I18N::find_by_recipe_key(
   $key, $locale )` — STEJNÝ mechanismus, který `class-json-importer.php`
   používá pro `related_recipes` reference (Checkpoint 10A) — nikdy podle
   title/slug.
3. Jen skutečně nalezené a `publish` posty se přidávají do výsledku —
   žádná fake/placeholder karta. Dokud neexistuje žádný EN recipe_key
   pár (přesný stav dnes — Checkpoint 10A vytvořil jen CZ obsah), blok
   se s prázdným polem jednoduše nevykreslí na `.com` ani `.cz`, dokud
   editor nedodá kurátorovaný seznam.
4. Žádné `Most Popular`/`Trending`/`Top Rated` popisky — pouze "Redakční
   výběr" / "Světová klasika" / "World Classics" (žádné tvrzení o
   návštěvnosti).

## I. Česká homepage

Existující blok `$czech_country`/`$czech_recipes` už BYL identity-based
(`atlas_chuti_home_czech_country()` → `Atlas_Chuti_Country_Sync::
find_term_id_by_iso( 'CZ' )` — skutečné ISO CZ, nikdy textové hledání
názvu) — žádná změna datové logiky nebyla potřeba, jen jeho POZICE v
šabloně (sekce G).

## J. REST/account implikace

- `rest_url('atlas-chuti/v1')` (lokalizováno do `AtlasChutiUser.restUrl` v
  `functions.php`) interně staví na `home_url()` — teď host-aware, takže
  JS na `atlaschuti.cz` volá REST na `atlaschuti.cz`, JS na
  `atlaschuti.com` volá REST na `atlaschuti.com` — vždy same-origin,
  **žádný CORS není potřeba** (a žádný nebyl přidán — `class-rest-api.php`
  beze změny).
- Žádný wildcard `Access-Control-Allow-Origin` nikde v pluginu (ověřeno
  gregrepem přes celý `includes/`, i testem v harnessi).
- My Atlas funguje na obou doménách — stejný účet, stejná DB identita
  (recipe_key/translation_group/country ISO/ingredient_key/ratings/
  favorites/cooked/collections/shopping/meal plan — nic z toho není
  host-specifické, vše je uloženo proti `user_id`, ne proti hostu).
- Session je oddělená (sekce C) — po přihlášení na `.cz` je potřeba se
  přihlásit znovu na `.com` (stejnými údaji), dokud neexistuje auditovaný
  cross-domain mechanismus.

## K. Sitemap/OG/schema

- **Sitemap**: `filter_sitemap_post_types`/`filter_sitemap_taxonomies`
  beze změny — host-awareness přichází z kombinace host-aware
  `current_locale()` + existující `scope_query_to_locale()` (platí i pro
  sitemap providerův vlastní `WP_Query`) + host-aware `home_url()`. CZ
  sitemap request (`atlaschuti.cz/wp-sitemap.xml`) vrátí jen CZ obsah s
  `.cz` URL, EN request jen EN obsah s `.com` URL.
- **`og:url`**: stejný `get_canonical_url()`/`home_url()` řetězec, žádná
  samostatná OG-host logika — host-aware automaticky.
- **Organization/WebSite schema**: **oprava** — `Organization`'s `@id`/
  `url` byly `home_url(...)`, což by po zavedení host-aware `home_url`
  vyrobilo DVĚ různé Organizations (jednu na `.cz`, jednu na `.com`).
  Opraveno na `Atlas_Chuti_Domain_Map::brand_url()` — FIXNÍ URL
  (`https://atlaschuti.cz/`, `BRAND_HOST` konstanta), stejná bez ohledu
  na to, který host request obsluhuje. `WebSite`'s `@id`/`url` zůstává
  per-host (legitimně DVĚ různé WebSite entity, jedna na doménu), obě
  s `publisher` odkazujícím na stejné fixní `Organization` `@id`. Jedna
  značka, ne dvě falešně odlišné Organizations.

## L. Redirect plán

Audit (grep `/en/` napříč celým `wp-content/`) nenašel ŽÁDNOU živou,
publikovanou `/en/` URL ani rewrite rule v kódu — pouze dokumentační
komentáře popisující budoucí model, který teď brief ruší. Dlouhodobý cíl
(`atlaschuti.cz/en/... → 301 → atlaschuti.com/...`) proto **nemá co
redirectovat dnes** a žádný redirect kód nebyl v tomto checkpointu
napsán — psát ho bez skutečného, ověřeného mapování by znamenalo hádat
slugy, což brief explicitně zakazuje ("Nehádej slug"). Až (pokud) bude
Polylang v produkci skutečně nakonfigurován s `/en/` cestami a vzniknou
publikované stránky pod touto cestou, další checkpoint může postavit
mapování na REÁLNÝCH datech (translation pairs) místo dohadu. Tato
sekce je proto dokumentační audit, ne kód.

## M. Testy

Nový harness `tests/harness-checkpoint-10b.php` (37 kontrol, 0 selhání),
pokrývá všech 27 požadovaných scénářů z briefu:

| # | Scénář | Harness check |
|---|---|---|
| 1 | cs → atlaschuti.cz | #1, #1b |
| 2 | en → atlaschuti.com | #2, #2b |
| 3 | arbitrary HTTP_HOST neovlivní canonical | #3, #3b, #3c, #3d, #3e |
| 4 | CZ self-canonical `.cz` | #4 |
| 5 | EN self-canonical `.com` | #5 |
| 6 | Cook Mode canonical správný host | #6 |
| 7 | žádný finální EN canonical `/en/` | #7 |
| 8 | cross-domain reciprocal hreflang | #8 |
| 9 | missing translation → no fake alternate | #9 |
| 10 | x-default policy | #10 |
| 11 | switcher CZ→EN `.com` | #11, #11b |
| 12 | switcher EN→CZ `.cz` | #12 |
| 13 | žádný guessed slug | #13 |
| 14 | jedna shared homepage architecture | #14 |
| 15 | CZ Czech-first | #15 |
| 16 | CZ `Světová klasika` | #16 |
| 17 | EN `World Classics` | #17 |
| 18 | World Classics přes stable identity | #18 |
| 19 | no fake cards | #19, #19b |
| 20 | no unsupported popularity claim | #20 |
| 21 | žádné cross-domain auth cookie hacky | #21 |
| 22 | žádný wildcard authenticated CORS | #22 |
| 23 | host-aware sitemap URLs | #23 |
| 24 | host-aware `og:url` | #24 |
| 25 | jedna Organization brand entity | #25a, #25b, #25c |
| 26 | Step 3–9 regrese všechny projdou | #26 (viz níže) |
| 27 | production-data diff empty | #27 (viz níže) |

**Step 3–9 regrese** (spuštěno jako samostatné shell příkazy, stejná
konvence jako u všech předchozích kroků):

```
harness-step-03.php: 64 checks, 0 failing
harness-step-04.php: 33 checks, 0 failing
harness-step-05.php: 48 checks, 0 failing
harness-step-06.php: 44 checks, 0 failing
harness-step-07.php: 37 checks, 0 failing
harness-step-08.php: 52 checks, 0 failing
harness-step-09.php: 51 checks, 0 failing
```

Poznámka k `harness-step-09.php`: check #45 (dřív "class-i18n.php nebylo
Krokem 9 upraveno", ověřováno přes `git diff --stat`) byl aktualizován —
tento checkpoint LEGITIMNĚ upravuje `class-i18n.php` (host-aware
`current_locale()`), takže byt-level "žádný diff" už neplatí a nikdy znovu
platit nebude u žádného budoucího checkpointu, který soubor rozšíří ze
stejně dobrého důvodu. Check teď ověřuje skutečnou přetrvávající invariantu
— že `scope_query_to_locale()` metoda a její meta_query kontrakt z Kroku 4
stále existují beze změny — místo křehkého "soubor se nikdy nedotkl"
kontrolního bodu. Také byl přidán `require` na `class-domain-map.php` do
harnesse (class-seo.php teď na ní závisí přes `Atlas_Chuti_Domain_Map::
brand_url()`).

`content-quality-gate.php`: **0 ERROR, 63 WARNING, 220 INFO** — identické
číslo jako po Checkpointu 10A (viz `content-batch-europe-1-remediation.md`
sekce K) — potvrzuje, že tento checkpoint se produkčních dat vůbec
nedotkl.

`git diff --stat -- production-data/`: **prázdné** (ověřeno před commitem).

## N. Staging checklist

Následující kroky vyžadují manuální/infra akci mimo tento repozitář (kód
je připraven, ale nemůže je provést sám):

- [ ] **DNS `.com`**: nasměrovat `atlaschuti.com` (+ `www.atlaschuti.com`)
      na stejnou infrastrukturu jako `atlaschuti.cz`.
- [ ] **TLS `.com`**: vydat/nasadit certifikát pro `atlaschuti.com`
      (Let's Encrypt/jiná CA) — kód force-uje `https://` v
      `Atlas_Chuti_Domain_Map::home_url_for_locale()`/`filter_home_url()`,
      takže bez platného TLS na `.com` by canonical/OG odkazovaly na
      neexistující zabezpečené URL.
- [ ] **vhost/webroot**: jeden webroot (stejný WP install) obsluhující
      OBĚ domény — žádný druhý WP, žádná druhá DB.
- [ ] **WordPress/Polylang language-domain mapping**: pokud admin chce i
      Polylang samotný nakonfigurovat na doménové mapování (Nastavení →
      Jazyky → URL úpravy), je to bezpečné VEDLE této vrstvy — obě by
      měly dojít ke stejnému výsledku pro tyto dvě domény. Není to ale
      vyžadováno — `Atlas_Chuti_Domain_Map` funguje i bez toho.
- [ ] **`.cz` CZ / `.com` EN**: potvrdit po DNS+TLS, že
      `curl -I https://atlaschuti.cz/` a `curl -I https://atlaschuti.com/`
      vrací 200 a správný `<html lang>`.
- [ ] **canonical/hreflang/x-default**: po nasazení ověřit `view-source:`
      na obou doménách, že `<link rel="canonical">` a
      `<link rel="alternate" hreflang="...">` odpovídají této zprávě
      (sekce E).
- [ ] **sitemap/robots**: ověřit `https://atlaschuti.cz/wp-sitemap.xml` a
      `https://atlaschuti.com/wp-sitemap.xml` (jednou EN obsah skutečně
      vznikne) vrací správný host ve všech `<loc>`.
- [ ] **language switcher**: manuálně proklikat CZ→EN a EN→CZ na
      reálném nasazení.
- [ ] **My Atlas login na obou hostech**: přihlásit se na `.cz`, ověřit
      že session NENÍ automaticky platná na `.com` (očekávané dle sekce
      C), přihlásit se na `.com` stejnými údaji, ověřit že funguje.
- [ ] **potvrzení stejného účtu, ale oddělených sessions**: totéž jako
      výše — jeden účet, dvě nezávislá přihlášení.
- [ ] **REST actions na obou hostech**: v DevTools ověřit, že požadavky
      z `atlaschuti.cz` jdou na `atlaschuti.cz/wp-json/...` a z
      `atlaschuti.com` na `atlaschuti.com/wp-json/...` (nikdy cross-origin).
- [ ] **`.cz` Czech-first homepage**: vizuální QA.
- [ ] **`.com` World Classics homepage**: vizuální QA — dnes prázdné/
      neviditelné, dokud editor nedodá `atlas_chuti_world_classics_recipe_keys`
      filtr s reálnými recipe_key hodnotami MAJÍCÍMI EN překlad.
- [ ] **no fake content**: potvrzeno kódem (sekce H) — manuální QA jen
      pro jistotu po reálném nasazení.

## O. Changed files

```
wp-content/plugins/atlas-chuti-core/includes/class-domain-map.php   (nový)
wp-content/plugins/atlas-chuti-core/atlas-chuti-core.php            (require + init)
wp-content/plugins/atlas-chuti-core/includes/class-i18n.php         (current_locale() host-first)
wp-content/plugins/atlas-chuti-core/includes/class-polylang-bridge.php (fallback URL, žádný /en/)
wp-content/plugins/atlas-chuti-core/includes/class-seo.php          (Organization @id fix + x-default docs)
wp-content/themes/atlas-chuti/inc/homepage.php                      (atlas_chuti_home_world_classics())
wp-content/themes/atlas-chuti/front-page.php                        (editorial split + World Classics blok)
tests/harness-checkpoint-10b.php                                    (nový, 37 checks)
tests/harness-step-09.php                                           (require class-domain-map.php + updated check #45)
docs/implementation-reports/checkpoint-10b-domains-homepage.md      (tento report)
```

`production-data/europe-1/**`: **beze změny** (ověřeno, sekce M).

## P. Deferred

- Skutečný **EN content batch** — explicitně mimo rozsah tohoto
  checkpointu (brief item 10).
- Redakční naplnění `atlas_chuti_world_classics_recipe_keys` filtru
  skutečným kurátorovaným seznamem — dnes záměrně prázdné (sekce H).
- Skutečná implementace `/en/ → .com` redirectu — dnes není co
  redirectovat (sekce L); počká na reálná data.
- Bezpečný cross-domain SSO (jedna session pro obě domény) — první verze
  je záměrně "stejný účet, oddělené session" (sekce C); auditovaný SSO by
  byl samostatný, bezpečnostně citlivý budoucí checkpoint.
- Volitelné budoucí `Discover Czech Cuisine` blok na `.com` — jen pokud a
  až bude existovat reálný EN obsah (brief item "Budoucí blok může být
  Discover Czech Cuisine, pouze pokud existuje reálný EN obsah").
- Staging DNS/TLS/vhost kroky ze sekce N — mimo dosah kódové změny.

---

## Potvrzovací blok

```text
Produkční batch nebyl změněn.
Nebyl vytvořen anglický content batch.
Nebyla vytvořena fake World Classics data.
Nebyl implementován neauditovaný cross-domain SSO.
CZ canonical host je atlaschuti.cz.
EN canonical host je atlaschuti.com.
```
