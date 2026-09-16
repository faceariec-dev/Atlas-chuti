# Checkpoint 10C — Media pipeline pro recepty

## A. Audit

**Stav před checkpointem**: branch `claude/new-session-0gc4ib`, HEAD `d0cf856`
("Checkpoint 10B: prepare CZ COM domains and editorial homepages"), čistý
strom. Odpovídá očekávanému kontextu z briefu (10A + 10B hotové).

**Theme image sizes** (`functions.php`, `add_image_size()`): `atlas-card`
640×480 (4:3, hard crop), `atlas-card-tall` 640×512 (5:4), `atlas-hero`
1600×900 (**16:9**, hard crop), `atlas-square`/`atlas-ugc` 1200×1200 — ale
tyto poslední dvě se u receptů vůbec nepoužívají (customizer/uživatelské
fotky přes `class-photos.php`, mimo scope tohoto checkpointu).

**Skutečné použití u receptů**: `single-atlas_recipe.php`'s hero →
`atlas-hero` (1600×900), `template-parts/recipe-card.php` (karty všude —
homepage, archivy, sidebar) → `atlas-card` (640×480). Brief navrhoval
ilustrativně zdrojový rozměr 1600×1000 (8:5/16:10) "pokud current design
používá přibližně 16:10" — **skutečný design ale používá 16:9**, ne 8:5.
Zdroj 1600×900 pokryje OBĚ ostré ořezy (16:9 hero i 4:3 karta) bez
upscalování — ořez 16:9 zdroje na 4:3 potřebuje jen šířku ≥ výška×4/3 =
1200, což zdroj o šířce 1600 splňuje s rezervou. V souladu s briefovou
vlastní instrukcí "použij skutečný design, pokud je vhodnější jiný master
ratio" je tedy `required_min_width/height` v tomto checkpointu **1600×900**,
ne 1600×1000.

**Existující upload/validace vzor**: `class-photos.php` (Krok 5, uživatelské
fotky receptů) už zavedl přesně ten typ validace, který tento checkpoint
potřebuje — `ALLOWED_MIMES` (jpg/jpeg, png, webp, žádné SVG),
`getimagesize()` pro reálné dekódování typu (ne jen podle přípony),
`wp_handle_upload()` → `wp_insert_attachment()` →
`wp_update_attachment_metadata( wp_generate_attachment_metadata(...) )`.
Tento checkpoint **znovupoužívá stejný `ALLOWED_MIMES` seznam** (jedna,
ne dvě různé představy "validní obrázek" v projektu) a stejnou
`getimagesize()`-first disciplínu, ale pro dávkový import ze souborů už na
disku (ne z `$_FILES` uploadu) používá `media_handle_sideload()` — vyšší
úrovňový WP helper, který interně dělá přesně tu samou trojici volání v
jednom kroku.

**`recipe_key` identita**: potvrzeno `class-i18n.php`
(`find_by_recipe_key()`, `atlas_recipe_key` meta) a Checkpointem 10A —
stejná stabilní identita, kterou `class-json-importer.php` používá pro
`related_recipes` reference. Žádná nová identita nevymýšlena.

**Attachment title / ALT stav před checkpointem**: `class-recipe-meta-box.php`
už měl pole `photo_credit` ("Zdroj / copyright fotografie") — potvrzuje, že
projekt už má koncept fotografických metadat u receptu, jen ne obrázkový
import ani lokalizovaný ALT mechanismus. `atlas_chuti_media()`
(`inc/template-tags.php`) — centrální render helper pro recepty — dřív
nepředával žádný explicitní `alt`, takže `wp_get_attachment_image()`
padal na globální `_wp_attachment_image_alt` meta (jedna hodnota pro celý
attachment, nemůže být správně pro CZ i EN zároveň). Jedno přímé volání
`get_the_post_thumbnail()` v `front-page.php` (lead story) navíc
`atlas_chuti_media()` úplně obcházelo.

## B. Filename convention

```text
{recipe_key}.{ext}
```

`ext` ∈ `jpg`, `jpeg`, `png`, `webp` (stejný `ALLOWED_MIMES` jako
`class-photos.php`). `recipe_key` musí projít stejným regexem jako
`class-json-importer.php`'s `is_valid_stable_key()`
(`/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/`) — vlastní kopie v
`Atlas_Chuti_Recipe_Image_Pipeline::is_valid_recipe_key()`, dokumentovaná
proti stejnému kontraktu (dva nezávisle spouštěné nástroje, ne sdílené
volání do importerovy privátní metody).

**Poznámka ke briefovým příkladům**: brief ilustrativně uvádí
`svickova_na_smetane.jpg` a `pad_thai.jpg` (podtržítkový styl). Skutečné
`recipe_key` hodnoty z Checkpointu 10A ale používají pomlčky tam, kde je
originální koncept vícelovný (`svickova`, `boeuf-bourguignon`,
`wiener-schnitzel` — `pad_thai` v datasetu vůbec neexistuje). Stejně jako u
10A's `locale`/`recipe_key` rozhodnutí: **respektuje se skutečná data**, ne
briefův ilustrativní příklad — nikdy nebyl a nebude vymyšlen nový
`recipe_key` jen aby seděl na ilustrační jméno souboru.

Párování je vždy podle `recipe_key`, nikdy podle lokalizovaného title,
slugu, attachment title nebo pořadí souborů (acceptance criterion 1).

## C. Manifest format

`Atlas_Chuti_Recipe_Image_Pipeline::build_manifest( $source_dir = null )` —
jeden řádek na `recipe_key` (concept-level, NE na post/locale):

| Sloupec | Popis |
|---|---|
| `recipe_key` | stabilní identita |
| `title_cs` / `title_en` | title CZ/EN postu tohoto konceptu, `null` pokud daná lokalizace neexistuje |
| `filename` | očekávaný/nalezený název souboru |
| `role` | `featured` (jediná role v tomto checkpointu — item 16) |
| `required_min_width` / `required_min_height` | 1600 / 900 (sekce D) |
| `alt_cs` / `alt_en` | = `title_cs`/`title_en` (defaultní ALT model, sekce I) |
| `status` | `present` / `missing` / `invalid_dimensions` / `invalid_format` / `duplicate` |

Bez `$source_dir`: status je jen `present` (má aktuálně featured image ve
VŠECH publikovaných lokalizacích daného konceptu) nebo `missing`. Se
`$source_dir`: každý status ze seznamu výše, podle skutečné validace
nalezeného kandidátního souboru. Soubory, které se nespárují s žádným
známým `recipe_key`, jdou do `unmatched_files` (samostatný seznam, ne
řádek manifestu — nemají identitu, ke které by se přiřadily).

**Výstupy**:
```text
generated/image-manifests/recipe-images.csv
generated/image-manifests/recipe-images.json
generated/image-manifests/missing-recipe-images.csv   (item 17 — filtr status=missing)
```

`generated/` je nová, gitignorovaná složka (regenerovatelný, read-only
výstup — nikdy commitovaný artefakt, stejná logika jako `wp-content/uploads/`).
Deterministické: `known_recipe_keys()` třídí podle `recipe_key`
(`ksort()`), `list_candidate_files()` třídí abecedně (`sort()`) — dva běhy
proti stejnému stavu DB/adresáře produkují bajtově identický výstup
(ověřeno harness check #19).

## D. Source dimension policy

**1600 × 900** (16:9) — viz sekce A. Do manifestu se ukládá JEN tento
minimální požadovaný zdrojový rozměr, NE seznam všech odvozených WP
velikostí (`atlas-card`, `atlas-hero`, ...) — ty vytváří WordPress
automaticky při importu (sekce F), nejsou součástí manifestu podle briefu
item 5 ("Do manifestu ukládej požadovaný minimální source rozměr, nikoliv
seznam všech derived WP sizes").

## E. ZIP/import architecture

```text
content batch (Checkpoint 10A, beze změny)
  ↓
image manifest (wp atlas image-manifest) — read-only
  ↓
dávková výroba obrázků (mimo tento repozitář — fotograf/designér)
  ↓
recipe-images.zip
  ↓
wp atlas image-import <zip> --dry-run   (staging krok 1, POVINNÝ)
  ↓
wp atlas image-import <zip>             (skutečný import)
  ↓
WordPress Media Library (přes media_handle_sideload(), sekce F)
  ↓
attachment metadata + WP image sizes (generováno WordPressem)
  ↓
přiřazení k recipe_key → featured image na všechny publikované lokalizace
  ↓
report (konzole + generated/image-manifests/image-import-report.json)
```

**Zvolená implementace**: WP-CLI příkazy `wp atlas image-manifest` a
`wp atlas image-import`, rozšiřující existující `Atlas_Chuti_CLI`
(`class-cli.php`, stejná třída jako `wp atlas import` z Kroku 1). Důvod
volby (brief item 7 — "vyber nejbezpečnější a nejlépe auditovatelnou
variantu"): WP-CLI běží jen server-side, nikdy přes veřejné/admin HTTP
rozhraní (žádná nová capability/nonce/CSRF plocha), výstup je čitelný a
skriptovatelný (staging automatizace), a projekt už má přesně tento
vzor zavedený a otestovaný. Žádné frontend/admin UI nebylo přidáno
(brief: "Nemusí mít veřejné frontend UI").

## F. WordPress media API usage

**Nikdy** `cp *.jpg wp-content/uploads/` (brief item 8, explicitně
zakázáno — ověřeno i harness checkem #10, regex kontrolující absenci
`copy()`/`move_uploaded_file()` směrem do `uploads`). Místo toho:

```php
media_handle_sideload( $file_array, 0 );
```

který interně (real WordPress core) zavolá `wp_handle_sideload()`
(přesun souboru do uploads struktury), `wp_insert_attachment()` (skutečný
attachment post) a `wp_generate_attachment_metadata()` +
`wp_update_attachment_metadata()` (skutečné rozměry, MIME metadata a
VŠECHNY registrované WP image sizes — `atlas-hero`, `atlas-card`,
`atlas-card-tall`, ... — vygenerované WordPressem samotným, ne ručně,
přesně podle briefu item 12). `attachment.post_title` = sanitizovaný
filename (`sanitize_file_name()`) — stejný precedens jako
`class-photos.php`, nikdy použito pro SEO (sekce H).

## G. `recipe_key` párování

`Atlas_Chuti_Recipe_Image_Pipeline::known_recipe_keys()` sestaví mapu
`recipe_key → [locale => post_id]` napříč `Atlas_Chuti_I18N::SUPPORTED_LOCALES`
(`cs-CZ`, `en`). Filename parser (`parse_filename()`) extrahuje
`{recipe_key}.{ext}`; párování je vždy explicitní match proti této mapě —
**nikdy** fuzzy/textové hledání podle názvu receptu.

Pokud koncept existuje ve více lokalizacích (dnes: jen `cs-CZ`, jednou
bude i `en`), **jeden fyzický attachment** se nastaví jako featured image
na **VŠECHNY** publikované lokalizační varianty stejného `recipe_key`
(`import_batch()`'s `foreach ( $locale_posts as $post_id )` smyčka) —
žádná duplikace attachmentu per jazyk (acceptance criterion 19, ověřeno
harness checkem #11b).

## H. Featured image rules

- Po úspěšném importu (ne dry-run): `set_post_thumbnail()` na každou
  lokalizační variantu daného `recipe_key`, **jen pokud ta konkrétní
  varianta ještě featured image nemá** — NEBO při explicitním
  `--replace`.
- **Ručně kurátorovaný existující featured image se defaultně nikdy
  nepřepíše** (brief item 15, acceptance criterion 14) — ověřeno harness
  checkem #14 (recept s předem nastaveným thumbnail_id zůstává
  nedotčený, `set_post_thumbnail()` se pro něj vůbec nezavolá).
- Attachment `post_title` (sekce F) nikdy neslouží jako SEO pole — priorita
  je striktně: (1) správný image file, (2) správné přiřazení k
  `recipe_key`, (3) správný ALT, (4) rozměry/varianty (WP generuje
  automaticky), (5) featured image role — přesně pořadí z briefu item 2.

## I. Lokalizovaný ALT

Nový helper `atlas_chuti_recipe_image_alt( $post_id )`
(`wp-content/plugins/atlas-chuti-core/includes/functions.php`):

```php
function atlas_chuti_recipe_image_alt( $post_id ) {
	$override = get_post_meta( $post_id, Atlas_Chuti_Meta_Fields::meta_key( 'image_alt_override' ), true );
	return $override ?: get_the_title( $post_id );
}
```

- **Default**: lokalizovaný title toho KONKRÉTNÍHO postu (`.cz` → CZ
  title, `.com` → EN title) — každá lokalizace je vlastní WP post, takže
  title je už samo o sobě locale-correct, žádná speciální logika navíc
  není potřeba.
- **Explicitní override**: nové registrované pole `atlas_image_alt_override`
  (`class-meta-fields.php`'s `recipe_fields()`, vedle existujícího
  `photo_credit`) — pro tu vzácnou fotku, jejíž skutečný vizuální obsah
  potřebuje popisnější ALT než jen název receptu. Má přednost, pokud je
  vyplněné.
- **Nikdy rozměry v ALT** (acceptance criterion 6) — helper nikdy nic
  nepřidává k title, jen ho vrací beze změny nebo vrací explicitní
  override text.
- **Žádná duplikace attachmentu**: protože ALT se řeší na úrovni POSTU
  (title/override), ne na úrovni ATTACHMENTU (`_wp_attachment_image_alt`,
  která je nutně jen jedna hodnota pro celý sdílený attachment), stejný
  fyzický obrázek může vykreslit jiný ALT na `.cz` a jiný na `.com` beze
  změny dat na attachmentu samotném.

## J. Duplicate/replace policy

Dva ROZDÍLNÉ koncepty duplicity, nezaměňovat:

1. **Duplicitní soubory v jedné dávce** (`status: duplicate` v manifestu i
   importu) — víc než jeden soubor v ZIPu/adresáři se mapuje na stejný
   `recipe_key` (např. `svickova.jpg` i `svickova.webp` současně). Tohle
   se **nikdy** automaticky nerozhoduje který soubor je "ten pravý" —
   celá dvojice/skupina se nahlásí jako `duplicate` a nic se pro ten
   `recipe_key` neimportuje, dokud zdrojová dávka není opravena.
2. **Recept už má featured image v Media Library** (`skipped_existing`) —
   default chování je **skip**, nikdy tichý přepis. Explicitní `--replace`
   flag na `wp atlas image-import` je jediný způsob, jak existující
   obrázek nahradit — bez něj se nic nepřepíše (acceptance criteria 13,
   14, 16).

## K. Security

- **Path traversal**: `extract_zip_safely()` kontroluje KAŽDÝ ZIP entry
  proti `..`, absolutní cestě (`/...`) a Windows-style `C:\...` PŘED
  extrakcí — jeden nebezpečný entry = celý ZIP je odmítnut (WP_Error),
  nic se neextrahuje. Ověřeno reálným ZIP souborem (ne mockem) s entry
  `../../../etc/evil.jpg` — harness check #22.
- **Žádné spustitelné soubory / PHP**: extension allowlist (stejný
  `ALLOWED_MIMES` jako zbytek pipeline) se kontroluje PODLE JMÉNA entry
  před extrakcí — `.php`/`.exe`/cokoliv mimo `jpg/jpeg/png/webp` shodí
  celý import, nikdy se nezapíše na disk. Ověřeno reálným ZIP obsahujícím
  `shell.php` — harness check #23.
- **Limit počtu entries**: `MAX_ZIP_ENTRIES = 300`.
- **Limit celkové (rozbalené) velikosti**: `MAX_ZIP_TOTAL_BYTES = 500 MB`.
- **Limit velikosti jednoho souboru**: `MAX_BYTES = 20 MB` (`classify_file()`).
- **Bezpečný temp adresář**: `get_temp_dir() . 'atlas-chuti-image-import-' . wp_generate_password(...)` —
  náhodný název, mimo `wp-content/uploads/`, mimo webroot.
- **Cleanup**: `Atlas_Chuti_Recipe_Image_Pipeline::cleanup()` se volá vždy
  po `import_batch()` dokončení (i při chybě uprostřed validace ZIPu — viz
  `extract_zip_safely()`'s vlastní cleanup-na-chybu větve), rekurzivně
  maže celý dočasný adresář.
- **Žádný arbitrary SVG upload** (item 11) — SVG není v `ALLOWED_MIMES`,
  nikdy nebylo zvažováno přidat.
- **Capability check**: WP-CLI běží jen server-side (žádný capability
  check přes HTTP admin vůbec neexistuje k obejití — sekce E).
- **EXIF/metadata** (item 12): `media_handle_sideload()` používá stejný
  core `wp_generate_attachment_metadata()` pipeline jako každý jiný WP
  upload v tomto projektu (`class-photos.php` stejně) — žádný vlastní
  EXIF sanitizer nebyl (a nemá být) psán. Pokud server běží s Imagick,
  WordPress core standardně již řadu EXIF polí při generování odvozených
  velikostí odstraňuje; toto chování je dané instalací PHP/Imagick verze
  na produkčním serveru, ne kódem tohoto pluginu — reportováno zde jako
  známé, neřešené v kódu (přesně podle briefu item 12: "pokud ne,
  reportuj to").

## L. Dry-run/idempotence

- `--dry-run` (výchozí `dry_run: true` v `import_batch()`'s options,
  volající kód musí explicitně zapnout skutečný zápis) — validuje a
  klasifikuje VŠE (párování, formát, rozměry, duplicity), ale **nikdy**
  nevolá `media_handle_sideload()` ani `set_post_thumbnail()`. Ověřeno
  harness checky #8/#8b (nula zaznamenaných volání).
- **Idempotence**: opakovaný import stejné dávky beze `--replace` hlásí
  `skipped_existing`, nevytváří duplicitní attachmenty, nemění featured
  images. Ověřeno harness checky #12/#12b (druhý běh: 0 importů, 0
  sideload volání).
- **Replace** je striktně explicitní parametr (`--replace`), nikdy
  implicitní chování (acceptance criterion 16, ověřeno checkem #13b).

## M. Testy

`tests/harness-checkpoint-10c.php` — **30 kontrol, 0 selhání**, pokrývá
všech 25 požadovaných scénářů z briefu (číslování odpovídá brief sekci 23):

| # | Scénář | Harness check |
|---|---|---|
| 1 | filename recipe_key.jpg valid | #1 |
| 2 | unknown recipe_key → unmatched | #2 |
| 3 | duplicate recipe_key files detected | #3 |
| 4 | invalid extension rejected | #4 |
| 5 | MIME mismatch rejected | #5 |
| 6 | too-small image rejected | #6 |
| 7 | valid dimensions accepted | #7 |
| 8 | dry-run writes nothing | #8, #8b, #8c |
| 9 | actual import creates WP attachment path | #9 |
| 10 | generated WP metadata path invoked | #10 |
| 11 | first import sets featured image | #11, #11b |
| 12 | second identical import skips | #12, #12b |
| 13 | explicit replace path only when requested | #13, #13b |
| 14 | manual existing featured image preserved by default | #14 |
| 15 | CZ render uses CZ ALT | #15 |
| 16 | EN render uses EN ALT | #16 |
| 17 | attachment duplication not required for locale | #17 |
| 18 | no HTML title attribute added as SEO field | #18 |
| 19 | missing image manifest deterministic | #19 |
| 20 | manifest includes expected filename | #20 |
| 21 | manifest includes required source dimensions | #21 |
| 22 | ZIP path traversal rejected | #22 (real ZIP fixture) |
| 23 | PHP/executable payload rejected | #23 (real ZIP fixture) |
| 24 | production text batch unchanged | #24 (live `git diff`) |
| 25 | Step 3-9 + 10B regressions pass | #25 (viz níže) |

Real fixtures, ne mocky: testovací JPEG soubory generované přes GD
(`imagecreatetruecolor`/`imagejpeg`, skutečné 1600×900 a 400×300
rozměry), skutečné ZIP archivy přes `ZipArchive` (path-traversal a
PHP-payload testy běží proti opravdu existujícím, opravdu nebezpečným
archivům, ne proti simulovanému výsledku).

**Step 3–9 + Checkpoint 10B regrese** (spuštěno jako samostatné shell
příkazy, stejná konvence jako u všech předchozích kroků):

```
harness-step-03.php: 64 checks, 0 failing
harness-step-04.php: 33 checks, 0 failing
harness-step-05.php: 48 checks, 0 failing
harness-step-06.php: 44 checks, 0 failing
harness-step-07.php: 37 checks, 0 failing
harness-step-08.php: 52 checks, 0 failing
harness-step-09.php: 51 checks, 0 failing
harness-checkpoint-10b.php: 37 checks, 0 failing
```

`content-quality-gate.php`: **0 ERROR, 63 WARNING, 220 INFO** — identické
jako po Checkpointu 10A/10B, potvrzuje nulovou regresi v produkčních
datech.

`git diff --stat -- production-data/`: **prázdné.**

## N. Staging workflow

1. **Generate manifest**: `wp atlas image-manifest` → zkontrolovat
   `generated/image-manifests/recipe-images.csv` (kolik receptů má
   `status: missing`).
2. **Create 3–5 sample images**: vyrobit pár testovacích fotek ≥1600×900
   pojmenovaných podle reálných `recipe_key` hodnot z manifestu (např.
   `svickova.jpg`, `gulyas.jpg`).
3. **ZIP them**: `zip recipe-images-test.zip svickova.jpg gulyas.jpg ...`.
4. **Run dry-run**: `wp atlas image-import recipe-images-test.zip --dry-run`
   — POVINNÝ první krok (brief item 19).
5. **Verify pairing**: zkontrolovat konzolový výstup + `generated/image-manifests/image-import-report.json`
   — žádné `unmatched_files`, žádné `duplicates`, očekávaný počet `imported`.
6. **Real staging import**: `wp atlas image-import recipe-images-test.zip`
   (bez `--dry-run`).
7. **Verify Media Library attachment**: v wp-admin → Média zkontrolovat, že
   vznikl nový attachment s očekávaným `post_title` (= sanitizovaný
   filename).
8. **Verify generated WP sizes**: zkontrolovat, že existují
   `atlas-hero`/`atlas-card`/atd. odvozené soubory (WordpPress je
   vygeneroval automaticky).
9. **Verify featured image**: otevřít daný recept ve wp-admin, potvrdit
   nastavený Featured Image.
10. **Verify `.cz` ALT**: otevřít CZ recept na frontendu, zkontrolovat
    `<img alt="...">` = CZ title.
11. **Verify `.com` ALT**: (jakmile bude existovat EN pár) totéž pro EN
    post, ALT = EN title, STEJNÝ fyzický obrázek.
12. **Verify same physical image reused**: zkontrolovat, že CZ i EN
    recipe post mají stejné `_thumbnail_id`.
13. **Second import idempotent**: znovu spustit `wp atlas image-import`
    stejným ZIPem bez `--replace` — očekávat `Skipped existing`, žádné
    nové attachmenty.
14. **Replace mode test**: `wp atlas image-import ... --replace` — ověřit,
    že se featured image skutečně přepsal (nový attachment ID).
15. **No broken image on frontend**: projít pár recipe karet a hero na
    frontendu, potvrdit žádný broken `<img>` (fallback placeholder se
    zobrazí jen tam, kde recept skutečně nemá featured image).

## O. Changed files

```
wp-content/plugins/atlas-chuti-core/includes/class-recipe-image-pipeline.php  (nový)
wp-content/plugins/atlas-chuti-core/includes/class-cli.php                    (+ image-manifest, image-import subcommands)
wp-content/plugins/atlas-chuti-core/includes/class-meta-fields.php            (+ image_alt_override field)
wp-content/plugins/atlas-chuti-core/includes/functions.php                    (+ atlas_chuti_recipe_image_alt())
wp-content/plugins/atlas-chuti-core/atlas-chuti-core.php                      (require nové třídy)
wp-content/themes/atlas-chuti/inc/template-tags.php                           (atlas_chuti_media() → localized alt pro recepty)
wp-content/themes/atlas-chuti/front-page.php                                  (lead story hero → localized alt)
.gitignore                                                                    (+ /generated/)
tests/harness-checkpoint-10c.php                                              (nový, 30 checks)
docs/implementation-reports/checkpoint-10c-media-pipeline.md                  (tento report)
```

`production-data/europe-1/**`: **beze změny** (ověřeno, sekce M).

## P. Deferred extensions

- **Country/glossary/magazine obrázky** — architektura je reusable
  (stejný `recipe_key`-style identity pattern by fungoval s
  `iso_code`/`translation_group`), ale explicitně NEIMPLEMENTOVÁNO v
  tomto checkpointu (brief item 16 — mimo rozsah).
- **Více rolí obrázku** (`gallery`, `step-by-step` atd.) — datový model
  má pole `role`, ale dnes se plní jen `featured`; rozšíření by bylo
  přímočaré, ale není součástí tohoto checkpointu.
- **Admin UI pro `image_alt_override`** — pole je registrováno
  (`register_post_meta`, REST-viditelné), ale nebyl přidán vlastní UI prvek
  do recipe meta boxu nad rámec existujícího generického rendereru (pokud
  meta box iteruje `recipe_fields()` automaticky, pole se objeví samo;
  jinak je to malé následné doplnění, ne architektonická změna).
- **EXIF sanitizace nad rámec WP core** — reportováno v sekci K jako
  provozní závislost na verzi PHP/Imagick, ne řešeno vlastním kódem
  (záměrně, brief item 12).
- **Skutečné spuštění `wp atlas image-manifest`/`image-import` proti
  živému webu** — toto prostředí nemá běžící WordPress/DB (stejné
  omezení jako u Checkpointů 10A/10B) — kód je hotový a otestovaný přes
  stub harness s reálnými image/ZIP fixtures, živé spuštění je první
  krok stagingu (sekce N).

---

## Potvrzovací blok

```text
Obrázky se párují podle recipe_key, nikoli podle názvu receptu.
Rozměry nejsou součástí ALT textu.
HTML title atribut obrázku není používán jako SEO pole.
Stejný attachment lze použít pro CZ i EN variantu.
Produkční recipe texty nebyly změněny.
```
