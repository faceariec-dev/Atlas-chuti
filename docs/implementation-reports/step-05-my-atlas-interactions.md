# Krok 5 — Registrace, Můj Atlas a interakce u receptů

## A. Audit (před změnou)

Kompletní read-only audit provedl subagent před jakoukoli implementací. Shrnutí (viz
také commit historie — audit se do repozitáře nezapisoval):

**Uživatelská vrstva**: neexistovala vůbec — jen `is_user_logged_in()`/`wp_login_url()`
v `header.php` (duplicitně v mobilním i desktopovém bloku), s placeholderem „Můj Atlas
(brzy)" a odkazem přihlášeného uživatele na `admin_url('profile.php')` (wp-admin, ne
frontend). Žádná registrace, žádné role/capability úpravy, žádný frontend account.

**Recipe action bar** (`single-atlas_recipe.php`): šest slotů — Oblíbené/Ohodnotit/
Komentáře byly statické `<span class="is-soon" aria-disabled="true">` placeholdery;
Uvařeno (passport.js/localStorage), Tisk a Sdílet už plně funkční. `$passport_data`
už obsahoval `recipe_key` (fallback na slug) — přesně to, co Krok 5 potřebuje.
Existující hook `atlas_chuti_recipe_community` (Krok 2, dosud nevyužitý, umístěný
pod „Přečtěte si" sekcí, nad print-only patičkou) je přesný, už připravený bod pro
hodnocení/fotky/komentáře.

**Komentáře**: `atlas_recipe`'s `supports` pole comments NEMĚLO. Žádný `comments.php`
template, žádné volání `comments_template()`. `add_theme_support('html5', [...,
'comment-form','comment-list',...])` existovalo, ale bylo mrtvé (nic ho nevyužívalo).

**Kulinářský pas** (`assets/js/passport.js`): čistě localStorage (`AtlasPassport`
objekt), identita recept=`recipe_key||slug`, země=`iso||slug` — už jazykově nezávislá
od začátku, žádná změna Kroku 4 to nerozbila. Modul byl explicitně navržen tak, aby
„budoucí account sync stačilo nahradit load()/save() REST voláními" (vlastní
docblock) — přesně to, co tento krok teď dělá, jen jinou cestou (viz sekce F).

**Multilingual**: `Atlas_Chuti_I18N::find_by_recipe_key()`/`find_country_by_iso()`
a `Atlas_Chuti_Polylang_Bridge` už poskytují přesně to, co Krok 5 potřebuje pro
cross-locale identitu. `atlas_chuti_system_url()` nemělo klíč `account` — doplněno.

**Cache/AJAX/REST**: nulový precedens — žádný `admin-ajax.php`, žádný
`register_rest_route()`, žádný nonce vzor mimo klasické wp-admin POST formuláře.
Krok 5 je první kód v projektu vyžadující frontendový (a anonymní) zápisový přístup.

**SEO** (`class-seo.php`): `recipe_schema()` záměrně bez `aggregateRating`/`review`
(zdokumentováno i v Kroku 2 reportu) — čistý bod pro rozšíření.

**DB/plugin konvence**: žádné vlastní DB tabulky, žádný `dbDelta()`, žádný schema
version tracking, `register_activation_hook()` jen flushuje rewrite rules. Krok 5 je
první kód vyžadující vlastní tabulky.

**Media/upload**: žádný `wp_handle_upload()`/`wp_insert_attachment()` mimo
standardní media library flow pro post thumbnaily.

## B. Account architecture

Standardní **WordPress Users** jako jediný zdroj identity (item 3 zadání) —
`wp_insert_user()`, `wp_signon()`, `get_password_reset_key()`/
`check_password_reset_key()`/`reset_password()` (stejné nízkoúrovňové core funkce,
které používá `wp-login.php`), `wp_hash_password()` (přes ně), standardní auth
cookies (`wp_set_auth_cookie()`). Žádný vlastní session/token systém, žádné vlastní
plaintext heslo.

**Nová role**: nově registrovaný uživatel dostává `subscriber` (nejnižší bezpečná
role) — explicitně nastavováno v `wp_insert_user(['role'=>'subscriber',...])`, ne
spoléháno na `default_role` option webu. Žádné `edit_posts`/`upload_files`/
`publish_posts`/`moderate_comments`/`manage_options`.

**Nová třída `Atlas_Chuti_Account`** (`class-account.php`): admin-post.php handlery
(`admin_post_nopriv_atlas_register`, `..._atlas_login`, `..._atlas_lost_password`,
`..._atlas_reset_password`, `admin_post_atlas_update_profile`,
`admin_post_atlas_delete_account`). Každý handler je tenká HTTP vrstva (nonce check
→ rate limit → redirect) obalující SKUTEČNOU logiku ve dvou samostatných,
testovatelných metodách bez `exit()`: `register_user()` a `authenticate_user()` —
tento rozdíl (glue vs. logika) je to, co vůbec umožňuje `tests/harness-step-05.php`
otestovat registraci/login bez živého HTTP round-tripu.

**Rate limiting** (item 7): krátký transient counter na hash(IP+salt), max 5 pokusů
/ 10 minut na bucket (register/login/lost_password/reset_password zvlášť) — nikdy
dlouhodobá identita.

**Bez enumeration leaku**: login vrací JEDNU obecnou chybu bez ohledu na to, zda
WP core rozlišil „uživatel neexistuje" vs. „špatné heslo". Password reset vždy vrací
stejnou zprávu („pokud tento e-mail existuje…") bez ohledu na existenci účtu. Jediná
záměrná výjimka: registrace MUSÍ oznámit obsazený e-mail (jinak by se uživatel nemohl
zaregistrovat) — zdokumentováno v kódu jako vědomé rozhodnutí.

**`login_redirect` filtr**: uživatel bez `edit_posts` capability (běžný subscriber)
je po standardním WP loginu přesměrován do Mého Atlasu místo wp-admin dashboardu —
oprava zjištění z auditu (odkaz „Můj Atlas" dřív vedl na `admin_url('profile.php')`).

**URL routing** (item 6): JEDNA stránka `/muj-atlas/` (`template-my-atlas.php`),
sekce přes prostý `?sekce=` query parametr (`oblibene`, `uvarene`, `pas`,
`hodnoceni`, `komentare`, `fotografie`, `nastaveni`; nepřihlášen: `prihlaseni`,
`registrace`, `zapomenute-heslo`, `nove-heslo`). Reload/back/forward fungují
triviálně (je to skutečná URL, ne JS routing); bez JS je účet plně použitelný
(žádná sekce nevyžaduje JS pro základní funkci — jen progresivní vylepšení).

## C. DB model

Tři nové tabulky (`class-db.php`, `Atlas_Chuti_DB`), první vlastní schema v tomto
projektu — `dbDelta()`, `$wpdb->prefix`, schema version option
(`atlas_chuti_db_version`), idempotentní (`maybe_upgrade()` porovná verzi, spustí
`install()` jen při rozdílu), nikdy destruktivní drop/recreate.

### `{$prefix}atlas_user_state` — favorite/cooked/tasted, jedna normalizovaná tabulka

| Sloupec | Typ | Poznámka |
|---|---|---|
| id | BIGINT UNSIGNED AI PK | |
| user_id | BIGINT UNSIGNED | |
| subject_type | VARCHAR(20) | `recipe` \| `country` |
| subject_key | VARCHAR(191) | `recipe_key` nebo ISO kód — NIKDY post ID/slug |
| state | VARCHAR(20) | `favorite` \| `cooked` \| `tasted` |
| created_at / updated_at | DATETIME | |

`UNIQUE KEY (user_id, subject_type, subject_key, state)` — brání duplicitám, dělá
toggle bezpečný i při souběžném dvojkliku (`INSERT IGNORE`). `KEY (user_id, state)`,
`KEY (subject_type, subject_key)` pro rychlé „moje favorites"/„kdo si oblíbil X".

### `{$prefix}atlas_ratings` — registered i anonymous v jedné tabulce

| Sloupec | Typ | Poznámka |
|---|---|---|
| id | BIGINT UNSIGNED AI PK | |
| recipe_key | VARCHAR(191) | |
| user_id | BIGINT UNSIGNED **NULL** | NULL u anonymous řádků |
| anon_token_hash | CHAR(64) **NULL** | SHA-256, NULL u registered řádků |
| rating | TINYINT UNSIGNED | 1–5 |
| created_at / updated_at | DATETIME | |

`UNIQUE KEY recipe_user (recipe_key, user_id)` + `UNIQUE KEY recipe_anon (recipe_key,
anon_token_hash)`. MySQL traktuje NULL v UNIQUE klíči jako vždy odlišný od jiného
NULL — takže mnoho anonymních řádků (`user_id=NULL`) pro stejný recept nikdy
nekoliduje na `recipe_user` klíči, a mnoho registered řádků (`anon_token_hash=NULL`)
nikdy nekoliduje na `recipe_anon` klíči. Aplikace sama hlídá, že nikdy nejsou
vyplněná obě pole zároveň (nikdy DB CHECK constraint kvůli přenositelnosti napříč
MySQL/MariaDB verzemi).

### `{$prefix}atlas_recipe_photos` — moderovaná UGC fotogalerie

| Sloupec | Typ | Poznámka |
|---|---|---|
| id | BIGINT UNSIGNED AI PK | |
| user_id | BIGINT UNSIGNED | |
| recipe_key | VARCHAR(191) | |
| attachment_id | BIGINT UNSIGNED | odkaz na `wp_posts` (attachment) |
| status | VARCHAR(20) | `pending` \| `approved` \| `rejected` |
| caption | TEXT NULL | |
| created_at / moderated_at / moderated_by | | |

`KEY (user_id)`, `KEY (recipe_key, status)`. Moderation stav je VÝHRADNĚ v této
tabulce, nikdy na attachmentu (viz sekce I).

## D. Stabilní identita napříč CZ+EN

Všechny concept-level interakce (favorite/cooked/rating/photo) používají výhradně
`recipe_key` (recept) nebo ISO kód (země) — nikdy title/slug/post ID. `atlas_recipe_key`
meta (Krok 3/4) je jediný zdroj. `Atlas_Chuti_I18N::find_by_recipe_key()`/
`find_country_by_iso()` resolvují identitu → konkrétní lokalizovaný post; nová
theme funkce `atlas_chuti_resolve_recipe_key()`/`atlas_chuti_resolve_country_iso()`
(`inc/my-atlas.php`) přidávají bezpečný fallback (aktuální locale → default locale →
libovolná dostupná locale → `null`, nikdy fake URL) pro zobrazení v Mém Atlasu.
Ověřeno testy (harness scénáře 9, 13, 37–40): CZ i EN post stejného receptu vždy
vrací STEJNÝ favorite/cooked/rating/photo stav.

## E. Favorites + Cooked

`Atlas_Chuti_User_State` (`class-user-state.php`) — `toggle()` je idempotentní
(existuje→DELETE→false; neexistuje→INSERT IGNORE→true), race-bezpečný přes UNIQUE
klíč (dvojklik nikdy nevytvoří duplicitu ani nefatální chybu). REST endpoint
`POST /state/toggle` (`class-rest-api.php`) validuje `recipe_key`/ISO proti
SKUTEČNÝM postům (`subject_exists()`) před jakýmkoli zápisem. Bez-JS fallback:
`admin_post_atlas_state_toggle` (stejná service metoda, formulářový POST +
redirect). Cache-safe (viz sekce K): action bar se vždy renderuje v NEUTRÁLNÍM stavu,
skutečný stav se dotáhne JS požadavkem `GET /state` až po načtení stránky.

## F. Passport

Kulinářský pas je nyní integrovaný do Mého Atlasu (sekce `pas`), ne paralelní systém
(item 14). `passport.js`'s `AtlasPassport` (localStorage) zůstává BEZE ZMĚNY —
pořád jediný mechanismus pro anonymní návštěvníky a zdroj pro merge. Nová větev v
`wireRecipeButton()`: pokud `AtlasChutiUser.loggedIn`, passport.js se pro tlačítko
„Uvařil/a jsem" odhlásí (`return`) a `assets/js/my-atlas.js`'s
`wireCookedButtonAccount()` převezme STEJNÉ tlačítko/markup přes REST — nikdy
zdvojené wiring, nikdy dva posluchače na jednom tlačítku. Standalone `/kulinarsky-pas/`
stránka (`template-passport.php`) zůstává beze změny — zpětná kompatibilita
zachována doslovně; hlavičkový odkaz na „pas" ikonu teď směřuje přihlášené
uživatele do Mého Atlasu, nepřihlášené na standalone stránku.

**Merge** (item 15, `assets/js/my-atlas.js`'s `wirePassportMergeBanner()` +
`POST /passport/merge`): po přihlášení, pokud localStorage obsahuje data A merge
ještě nebyl proveden/odmítnut, zobrazí se banner „Našli jsme váš dosavadní
Kulinářský pas… Přidat jej do Mého Atlasu?" [Přidat] [Ne, díky]. Server
(`merge_passport()`) znovu validuje KAŽDÝ klíč proti reálným postům (neplatné klíče
tiše přeskočí), zapisuje přes `merge_many()` (`INSERT IGNORE` — nikdy nepřepíše/
nesmaže existující server data, protože jde jen o presence-flag, ne o hodnotu).
`localStorage.clear()` proběhne AŽ po potvrzeném úspěšném serverovém merge, nikdy
dřív. Otestováno (harness scénáře 14–16): reálné klíče se sloučí, neplatné se
přeskočí, opakovaný merge je idempotentní (nic nového nevloží).

## G. Ratings

`Atlas_Chuti_Ratings` (`class-ratings.php`) — jeden aktivní hlas na
(recipe_key, user) NEBO (recipe_key, anon token), vynuceno `INSERT ... ON DUPLICATE
KEY UPDATE` (změna hlasu = UPDATE, nikdy druhý řádek). Anonymní identita: náhodný
32bajtový token v cookie (`atlas_chuti_rating_token`, HttpOnly, SameSite=Lax,
2 roky), server ukládá pouze SHA-256 hash (`hash_token()`) — raw token nikdy
neopouští prohlížeč. Rate limit: krátký transient na hash(IP+'rating'+salt), NE
dlouhodobá IP identita. Server-side rozsah 1–5 (`is_valid_rating()` — **opravena
reálná chyba** objevená testy: původní implementace porovnávala `int === float`,
což je v PHP vždy `false`; opraveno na `is_numeric()` + loose `!=` pro kontrolu
celočíselnosti + rozsah). Aggregate (`get_aggregate()`) je vždy počítán ze
SKUTEČNÝCH řádků (`AVG`/`COUNT`), nikdy fabrikován.

## H. Comments

Nativní WP komentáře (item 20 — audit nenašel důvod je nahrazovat vlastním
systémem). `class-comments.php` přidává `comments` support výhradně `atlas_recipe`
(ne globálně, ne budoucímu Magazínu). Přihlášení vynuceno DVOJITĚ: `comments_open`
filtr vrací `is_user_logged_in()` pro recepty, a `preprocess_comment` filtr navíc
`wp_die()`uje jakýkoli anonymní pokus, který by `comments_open` obešel (defense in
depth, ne jen UI skrytí). Nový `comments.php` template: seznam komentářů vždy
viditelný, formulář jen pro přihlášené, jasná výzva k přihlášení jinak. CZ a EN
post stejného receptu mají ÚPLNĚ oddělené komentářové proudy (nativní
`comment_post_ID` vazba) — ověřeno testem (scénář 30).

## I. Photos

Kontrolovaný upload endpoint (`class-photos.php`'s `upload()`), volaný z
`POST /photos` (REST) NEBO `admin_post_atlas_photo_upload` (bez-JS fallback) —
NIKDY přes standardní `upload_files` capability (subscriber ji nikdy nedostane).
Server-side validace v pořadí: recipe_key musí existovat → velikost (max 5 MB) →
SKUTEČNÝ obsah souboru přes `getimagesize()` (ne jen přípona/deklarovaný MIME) →
teprve pak `wp_handle_upload()`/`wp_insert_attachment()` (post_status `private`,
BEZ post_parent na recept — viz níže). Moderace: `pending → approved/rejected`,
capability `moderate_comments` (reálná, už existující WP capabilita editorů/adminů
— subscriber ji nemá). Vlastní admin obrazovka „Fotografie ke schválení"
(submenu pod Atlas chutí). Uživatel vidí svou pending fotku v Mém Atlasu, nemůže ji
sám schválit. Schválené fotky jsou dostupné podle `recipe_key` napříč CZ i EN
(concept-level UGC) — ověřeno testem (scénář 37).

**Soukromí** (item 24): žádný veřejný seznam uživatelů; u fotky se zobrazuje
maximálně `display_name` (nikdy e-mail/login/interní ID/IP). **EXIF omezení**:
veřejně se nikdy neservíruje originální nahraný soubor — jen vygenerovaná
velikost `atlas-ugc` (1200×1200, bez tvrdého ořezu), která u GD-based zpracování
obrázků typicky EXIF již neobsahuje; u Imagick-based instalací může originál
(nikdy veřejně odkazovaný) EXIF stále nést — zdokumentované omezení, ne vlastní
EXIF parser (item 24 to výslovně zakazuje budovat bez potřeby).

## J. Multilingual

Jeden účet, jedna WP Users tabulka — CZ registrace a EN login jsou stejný účet
konstrukčně (item 30), žádná locale-scoped account logika kdekoli v `class-account.php`.
`/muj-atlas/` (CZ) a `/en/muj-atlas/` (EN, jakmile Polylang přeloží stránku vytvořenou
přes `class-page-setup.php`) zobrazují STEJNÁ `user_state`/`ratings`/`photos` data —
tabulky nejsou vůbec locale-scoped. UI labely přes `__()`/`_e()` (`atlas-chuti`
textdomain), JS stringy přes `wp_localize_script()` (`AtlasChutiInteractionsL10n`,
oddělený objekt od `AtlasChutiL10n`/`AtlasChutiShareL10n`). Lokalizovaná recipe
card v Mém Atlasu: viz sekce D.

## K. Cache/performance

**Cache-safe personalizace** (item 27): recipe action bar (Oblíbené/Uvařeno) se VŽDY
renderuje v neutrálním výchozím stavu — žádný server-side personalizovaný stav
nikdy nejde do potenciálně cacheovaného HTML. `assets/js/my-atlas.js`'s
`bootstrapRecipeState()` dotáhne skutečný stav JEDNÍM malým REST požadavkem
(`GET /state`) hned po načtení a aktualizuje tlačítka. Aggregate rating JE
server-rendered přímo do HTML (item 27 to výslovně povoluje — je to veřejná,
cacheovatelná informace); jen „moje hodnocení" se dotahuje JS. Můj Atlas samotný
(`/muj-atlas/`) je implicitně mimo běžné cachování — každá běžná WP page-cache
implementace (WP Super Cache, W3TC, WP Rocket, LiteSpeed Cache) ve výchozím stavu
NEcachuje požadavky s platnou přihlašovací cookie, takže server-rendered osobní
seznamy (oblíbené/uvařené/hodnocení/komentáře/fotky) na TÉTO stránce jsou bezpečné —
zdokumentovaný předpoklad, ne libovolné rozhodnutí (viz sekce M).

**Výkon** (item 40): žádné N+1 — `Atlas_Chuti_User_State::get_set_map()` batchuje
lookup pro celý seznam (jeden `IN (...)` dotaz), `Atlas_Chuti_Ratings::
get_aggregates_for()` stejně pro hodnocení více receptů najednou. Agregát se počítá
přímým `AVG()`/`COUNT()` dotazem přes indexovaný `recipe_key` sloupec — ne sken celé
tabulky, bez potřeby zvláštní cache vrstvy pro dnešní očekávaný objem dat.

## L. Security

Nonce na každém stavu měnícím requestu (`wp_nonce_field()` + `wp_verify_nonce()`
pro admin-post cesty; `X-WP-Nonce` header + `wp_verify_nonce($nonce,'wp_rest')`
pro REST, i na veřejné anonymous-rating route jako CSRF ochrana bez nutnosti
loginu). Permission checks: `is_user_logged_in()` pro účet-vyžadující endpointy,
`current_user_can('moderate_comments')` pro moderaci. `$wpdb->prepare()` na každém
SQL dotazu se skutečnou interpolací hodnot (žádný raw string concatenation).
`recipe_key`/ISO se vždy validuje proti reálným postům před zápisem
(`subject_exists()`) — cizí/vymyšlený klíč nikdy nevytvoří osiřelý řádek. Upload:
MIME/velikost/reálný typ obrázku server-side, nikdy jen `accept=`. Rate limiting na
register/login/lost-password/reset-password/anonymous-rating.

## M. Privacy/GDPR

`Atlas_Chuti_Privacy` (`class-privacy.php`) registruje exportér i eraser přes
`wp_privacy_personal_data_exporters`/`erasers` filtry — pokrývá favorite/cooked/
tasted, registered ratings, photo metadata (ne komentáře, které WP core už
exportuje/maže sám — item 37 to výslovně zakazuje duplikovat).

**Lifecycle při smazání účtu** (item 38, `deleted_user` hook — funguje stejně při
smazání z wp-adminu i při vlastním self-service `handle_delete_account()`):
- `atlas_user_state` (favorite/cooked/tasted): **smazáno úplně** — bez účtu nemá
  smysl.
- `atlas_ratings`: **smazáno úplně** (ne „anonymizováno" napůl — řádek bez OBOU
  identit, user_id i anon_token_hash, by nikdy neodpovídal aplikační invariantě, že
  přesně jedna identita je vždy vyplněná; smazání místo nekonzistentního
  polovičního stavu je zdokumentované, vědomé rozhodnutí). Agregát receptu se tím
  po smazání účtu přepočítá bez tohoto hlasu — akceptovaný důsledek.
- `atlas_recipe_photos`: fotky (i jejich attachmenty) se smažou.
- Self-service smazání účtu vyžaduje potvrzení aktuálním heslem, žádnou zvláštní
  capabilitu (`wp_delete_user()` voláno programově, ne přes admin UI capability
  gate) — bezpečné, protože handler explicitně ověřuje, že cílový účet JE
  přihlášený uživatel sám.

Anonymní rating token: v reportu explicitně NEPROHLAŠOVÁN za „personal data účtu" —
nemá vazbu na žádný účet, žije jen jako hash v `atlas_ratings` a jako cookie v
prohlížeči (item 37's poslední odstavec).

## N. SEO/schema

`aggregateRating` v Recipe schema (`class-seo.php`'s `add_recipe_aggregate_rating()`)
— přidáno POUZE když `ratingCount >= 1`, hodnoty vždy ze skutečného
`Atlas_Chuti_Ratings::get_aggregate()`, nikdy z klienta/DOM. Účet/login/registrace/
reset stránky: `noindex,follow` (jedna podmínka v `get_robots_directive()` — všechny
tyto sekce jedou přes `template-my-atlas.php`, takže stačí jedna kontrola šablony).
Žádný veřejný profil/adresář uživatelů nikde nevznikl. Komentáře nemění meta
description ani nevytvářejí indexovatelné profil-stránky (nativní WP komentáře,
žádná vlastní pagination-URL logika přidána).

## O. Testy

Nový `tests/harness-step-05.php` — stejný přístup jako harness-step-03/04.php
(hand-stubovaná WP API vrstva, REÁLNÝ neupravený kód pluginu/theme proti ní běží),
rozšířený o: reálnou users tabulku (`wp_insert_user`/`get_user_by`/`wp_signon`/
`wp_check_password`/role→capability mapu), zjednodušené ale funkčně věrné nonce
páry, a hlavně **zapisovatelný `Fake_WPDB`** — generický mini-SQL engine (ne
special-case mocky) podporující přesně ty tvary dotazů, které
`class-user-state.php`/`class-ratings.php`/`class-photos.php` skutečně vydávají:
`INSERT`/`INSERT IGNORE`/`INSERT … ON DUPLICATE KEY UPDATE` (s korektní MySQL
NULL-je-vždy-odlišný sémantikou pro UNIQUE klíče), `UPDATE`/`DELETE` (přes
`$wpdb->update()`/`delete()`), a `SELECT` s `WHERE` (`=`/`IN`)/`ORDER BY`/
`GROUP BY`/`LIMIT`/agregáty (`AVG`/`COUNT`).

44 číslovaných scénářů + 4 pomocné sub-checky, **0 selhání**:

| Skupina | Počet | Pokrývá |
|---|---|---|
| 1. Account | 5 | registrace, duplicitní e-mail, neplatný nonce, login, nepřihlášená akce |
| 2. Favorite | 4 | přidání, no-dup, odebrání, stejný recipe_key CZ/EN = stejný stav |
| 3. Cooked | 4 | označení, idempotence, odznačení, sdílená identita s Passportem |
| 4. Passport merge | 3 | merge reálných klíčů, no-dup do čerstvého účtu, idempotentní opakování |
| 5. Registered rating | 4 | první hlas, změna bez duplicity, rozsah 1-5, přepočet agregátu |
| 6. Anonymous rating | 5 | token vytvořen, jeden hlas na token, změna, rate limit, žádná dlouhodobá IP |
| 7. Aggregate schema | 2 | nula hlasů → bez pole, reálné hlasy → reálné pole |
| 8. Comments | 3 | přihlášený komentář prochází, anonymní blokován server-side, CZ/EN oddělené |
| 9. Photos | 7 | anon odmítnut, validní → pending, špatný MIME, moc velký, běžný user nemůže schválit, moderátor může, cross-locale dostupnost |
| 10. Můj Atlas locale | 3 | CZ resolvuje CZ, EN resolvuje EN, chybějící překlad → bezpečný fallback |
| 11. Privacy | 2 | exportér zahrnuje vlastní data, eraser nenechává osiřelé řádky |
| 12. Security/perf | 2 | validovaná recipe_key cesta, UNIQUE constraint brání souběžné duplicitě |

**Skutečná chyba nalezená a opravená testy**: `Atlas_Chuti_Ratings::is_valid_rating()`
porovnávalo `(int) $rating === (float) $rating + 0` — v PHP je `5 === 5.0` vždy
`false` (striktní porovnání různých typů), takže tato metoda by v produkci
ZAMÍTLA úplně každé hodnocení. Opraveno na `is_numeric()` + loose `!=` pro kontrolu
celočíselnosti + explicitní rozsahovou kontrolu. Bez tohoto harnesse by šlo o tichou,
snadno přehlédnutelnou produkční chybu — přesně proč item 44 zadání trvá na
testech před commitem.

**Regrese**: `harness-step-03.php` (64/64) a `harness-step-04.php` (33/33) běží
beze změny chování — ověřeno po každé úpravě.

**Co tento harness záměrně NEtestuje** (viz sekce P — staging checklist):
skutečný HTTP round-trip přes `register_rest_route()`/`WP_REST_Request`/
`admin-post.php` dispatch, opravdový `wp_handle_upload()`/`is_uploaded_file()`
upload pipeline (PHP neumí přepsat vlastní built-in `is_uploaded_file()` — proto
byla z `class-photos.php` odstraněna redundantní vlastní kontrola, viz kód a jeho
komentář: skutečnou kontrolu už dělá `wp_handle_upload()` samo), kryptograficky
reálné nonce/password hashe, a skutečné vykreslení JS (rating hvězdičky,
merge banner, upload formulář) v prohlížeči.

## P. Staging / manuální test checklist

- [ ] registrace (reálný formulář, reálný e-mail)
- [ ] login
- [ ] logout
- [ ] password reset (e-mail skutečně dorazí, odkaz funguje)
- [ ] CZ účet i EN účet ukazují STEJNÁ data
- [ ] favorite na CZ receptu → viditelné na EN variantě stejného receptu
- [ ] cooked na CZ receptu → viditelné na EN variantě
- [ ] Passport merge z localStorage (skutečný banner, skutečné REST volání)
- [ ] anonymous rating (cookie se skutečně nastaví, hlasování funguje)
- [ ] logged-in rating
- [ ] změna hodnocení (žádný duplicitní hlas)
- [ ] komentáře CZ/EN oddělené (reálné ověření ve dvou různých post threadech)
- [ ] photo upload (reálný soubor, reálný `wp_handle_upload()`)
- [ ] moderace (editor schvaluje/zamítá, subscriber nemůže)
- [ ] účet na mobilu (390 px)
- [ ] desktop
- [ ] klávesnice (tab přes celý action bar + rating hvězdičky + account nav)
- [ ] chování s reálným page cache pluginem (pokud/až bude nasazen)
- [ ] zdroj AggregateRating (skutečně z DB, ne fabrikovaný)
- [ ] noindex na account stránkách (skutečná kontrola v prohlížeči/curl)
- [ ] skutečný REST round-trip (register_rest_route dispatch, X-WP-Nonce)
- [ ] EXIF na produkčním image-processing stacku (GD vs. Imagick)

## Q. Changed files

```
NOVÉ (plugin):
  wp-content/plugins/atlas-chuti-core/includes/class-db.php
  wp-content/plugins/atlas-chuti-core/includes/class-account.php
  wp-content/plugins/atlas-chuti-core/includes/class-user-state.php
  wp-content/plugins/atlas-chuti-core/includes/class-ratings.php
  wp-content/plugins/atlas-chuti-core/includes/class-comments.php
  wp-content/plugins/atlas-chuti-core/includes/class-photos.php
  wp-content/plugins/atlas-chuti-core/includes/class-rest-api.php
  wp-content/plugins/atlas-chuti-core/includes/class-privacy.php

ZMĚNĚNÉ (plugin):
  wp-content/plugins/atlas-chuti-core/atlas-chuti-core.php
  wp-content/plugins/atlas-chuti-core/includes/class-page-setup.php
  wp-content/plugins/atlas-chuti-core/includes/class-seo.php
  wp-content/plugins/atlas-chuti-core/includes/functions.php

NOVÉ (theme):
  wp-content/themes/atlas-chuti/template-my-atlas.php
  wp-content/themes/atlas-chuti/comments.php
  wp-content/themes/atlas-chuti/inc/my-atlas.php
  wp-content/themes/atlas-chuti/inc/recipe-community.php
  wp-content/themes/atlas-chuti/assets/js/my-atlas.js

ZMĚNĚNÉ (theme):
  wp-content/themes/atlas-chuti/functions.php
  wp-content/themes/atlas-chuti/header.php
  wp-content/themes/atlas-chuti/front-page.php
  wp-content/themes/atlas-chuti/single-atlas_recipe.php
  wp-content/themes/atlas-chuti/assets/js/passport.js
  wp-content/themes/atlas-chuti/assets/css/main.css

TESTY:
  tests/harness-step-05.php (nový)

DOKUMENTACE:
  docs/implementation-reports/step-05-my-atlas-interactions.md (nový, tento soubor)
```

`production-data/` — **beze změny** (ověřeno `git diff --stat -- production-data/`
před commitem, viz finální výstup).

## R. Deferred (patří do Kroku 6+)

Přesně dle sekce 46 zadání — nic z tohoto nebylo implementováno: vlastní kolekce,
shopping list, meal planner, Cook Mode, timery, ingredient/step checkboxy, „Co mám
doma?", doporučovací engine, kvízy, notifikace, social login, followers, veřejné
profily, badges/gamifikace, fórum/Diskuze, plný Magazín, reklama/GATE, video systém.

## S. Manuální WordPress kroky

1. Nastavení stránek (Atlas chutí → Nastavení stránek) nyní obsahuje i položku
   **Můj Atlas** (`muj-atlas`, šablona `template-my-atlas.php`) — spustit
   "Vytvořit chybějící stránky" a stránku publikovat (stejně jako ostatní systémové
   stránky vznikají jako koncept).
2. Ověřit, že role `subscriber` má ve skutečné instalaci výchozí, neupravené
   capabilities (žádný plugin/theme jí dřív nepřidal `upload_files` apod.).
3. Zkontrolovat, že `moderate_comments` capability skutečně drží jen editoři/admini
   (výchozí WP chování, ale ověřit na produkčním nastavení rolí).
4. Ověřit doručení e-mailů (`wp_mail()`) na produkčním SMTP/mail transportu —
   password reset e-mail musí reálně dojít.
5. Po prvním nasazení zkontrolovat, že se 3 nové DB tabulky skutečně vytvořily
   (aktivace pluginu, nebo `plugins_loaded` na příštím requestu) —
   `wp_atlas_user_state`, `wp_atlas_ratings`, `wp_atlas_recipe_photos`.
6. Pokud/až bude nasazen page-cache plugin, explicitně ověřit, že `/muj-atlas/`
   (a jakákoli URL s platnou přihlašovací cookie) není cachována sdíleně napříč
   uživateli — u většiny pluginů je to výchozí chování, ale ověřit konfiguraci.
7. Zkontrolovat GD vs. Imagick na produkčním serveru kvůli EXIF chování u
   nahraných fotografií (sekce I/M).

---

**Produkční batch nebyl importován.**
**Produkční batch nebyl změněn.**
**Nebyl proveden hromadný překlad obsahu.**
**AggregateRating používá pouze skutečná uživatelská hodnocení.**
