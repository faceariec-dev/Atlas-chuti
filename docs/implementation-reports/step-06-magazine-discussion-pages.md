# Krok 6 — Magazín, Tipy a triky, Diskuze a obecné/patičkové stránky

## A. Audit (před změnou)

Audit provedl subagent před jakoukoli implementací (read-only), shrnutí:

**Magazín**: `Atlas_Chuti_I18N::LOCALIZED_POST_TYPES` už obsahoval `'post'` (přidáno
v Kroku 4 „pro budoucí Magazín"), ale `post` nikdy nebyl formálně zaregistrován přes
`register_post_meta()` v `class-register-meta.php` — nekonzistence, opravena (viz
sekce B). `atlas_chuti_home_magazine_posts()` (`inc/homepage.php`) už existoval a už
byl volaný z `front-page.php` i `single-atlas_recipe.php` (oba už graceful — `if
($magazine_posts):`) — Krok 1/2 už počítaly s tím, že Magazín jednou přibude.

**Diskuze**: nulová existující infrastruktura — žádný CPT, žádná fórová logika,
žádná knihovna. Potvrzeno, že doporučený lehký model z zadání (CPT `atlas_topic` +
nativní WP komentáře jako odpovědi) je opravdu nejjednodušší udržitelná cesta —
žádný důvod pro fórový plugin.

**Taxonomie**: dva zásadně odlišné multijazyčné vzory už existují v projektu:
(1) technické taxonomie (`atlas_recipe_tag`, `atlas_glossary_category`, …) — JEDEN
sdílený term + `Atlas_Chuti_Taxonomy_Labels::label()` řeší lokalizovaný název za
běhu; (2) `category` (WP/Polylang nativní) — Polylang vyžaduje DVA reálné termy (po
jednom na jazyk), spárované přes `pll_save_term_translations()`. Magazín kategorie
MUSÍ použít vzor (2), protože `category` je Polylang-nativní taxonomie; Diskuze
vlastní `atlas_topic_category` použije vzor (1), protože jde o interní technickou
taxonomii tohoto projektu (konzistentní s `atlas_recipe_tag`).

**SEO**: canonical/hreflang/og:locale mechanismus v `class-seo.php` je už generický
(`is_singular()`, ne vázaný na konkrétní CPT) — Magazín/Diskuze ho dostanou zdarma,
jen bylo potřeba doplnit specifické větve (canonical pro kategorie/Diskuze archiv,
Article schema, sitemap whitelist).

**Sitemap**: `filter_sitemap_taxonomies()` byl WHITELIST
(`array_intersect_key($taxonomies, ['atlas_continent'=>true])`) — bez úpravy by
tiše vynechal `category` ze sitemapy. Opraveno (sekce H).

**Slug kolize**: žádné — existující CPT rewrite sluggy (`recepty`, `zeme`,
`slovnicek`) a page-setup sluggy (13 položek) nekolidují s novými `diskuze`
(CPT) a `magazin` (Page).

## B. Magazine architecture

Standardní WordPress `post` — **žádný nový CPT** (item 3 zadání). Nová meta pole
(`atlas_related_recipe_keys`, `atlas_related_country_iso`,
`atlas_related_glossary_keys`) zaregistrována přes `register_post_meta()` v
`class-register-meta.php::register_magazine_relation_fields()`; oprava
nekonzistence z auditu — `post`/`atlas_topic` teď mají formální
`register_post_meta()` volání pro `atlas_locale`/`atlas_translation_group` stejně
jako ostatní lokalizované typy.

**URL struktura** (item 4): `/magazin/` (CZ) je reálná WordPress Page s vlastní
šablonou `template-magazine.php` (stejný vzor jako `template-countries.php` atd.) —
**auto-publikovaná** při vytvoření (jediná výjimka z draft-by-default konvence
ostatních systémových stránek, viz sekce F). Jednotlivé články ALE používají
WordPressův výchozí permalink pro `post` (typicky `/{slug}/`), **ne** vynucený
prefix `/magazin/{slug}/` — vynucení by vyžadovalo buď sitewide
`/%category%/%postname%/` strukturu (dopad na VŠECHNY posty/kategorie), nebo
vlastní rewrite rule, oboje zadání explicitně zakazuje jako „křehké". Toto je
vědomé omezení podle vlastní instrukce zadání „Stabilita > kosmeticky perfektní
URL" (item 4).

**Šablony** (Krok 6, fáze 2): `single.php` (článek — standardní WP template
hierarchy fallback pro `post`, protože `atlas_recipe`/`atlas_country`/
`atlas_glossary` mají vlastní `single-{typ}.php`), `category.php` (archiv
kategorie, včetně Tipů a triků s odlišným vizuálem), `archive.php` (generický
fallback — datumové/tag archivy), `template-magazine.php` (landing — lead story +
sekundární + Tipy a triky highlight + kategorijní pruhy + nejnovější, vše
podmíněné reálnými daty).

**Autor**: žádný veřejný author archive — `Atlas_Chuti_Magazine::
disable_author_archive()` (hook `template_redirect`) přesměruje `is_author()` na
homepage (item 10). Detail článku zobrazuje jen `display_name`, nikdy odkaz na
`get_author_posts_url()`.

**Komentáře**: Magazín komentáře zůstávají VYPNUTÉ (item 33) —
`Atlas_Chuti_Comments::disable_magazine_comments()`, nová `comments_open` filter
větev pro `post`, nedotýká se `atlas_recipe` (Krok 5) ani `atlas_topic` (vlastní
logika v `class-discussion.php`).

## C. Category model

`Atlas_Chuti_Magazine` (nová třída) seeduje 8 CZ + 8 EN `category` termů (Tipy a
triky/Techniky/Suroviny/Kuchyně světa/Česká kuchyně/Sezónní vaření/Příběhy a
historie jídel/Praktické návody), spárovaných přes nové metody
`Atlas_Chuti_Polylang_Bridge::assign_term_language()`/`link_term_translations()`
(zrcadlí existující `assign_language()`/`link_translations()` pro posty).
Idempotentní (option-gated), žádný obsah negenerován — jen prázdný kategorijní
strom.

`atlas_topic_category` (Diskuze) je naproti tomu technická taxonomie — sdílený
term + `Atlas_Chuti_Taxonomy_Labels::LABELS['atlas_topic_category']` (8 CZ/EN
párů), seedovaná stejným mechanismem jako `atlas_recipe_tag`
(`maybe_seed_default_terms()`, verze bumpnuta na `_v4`). `capabilities` omezuje
vytváření/mazání termů na `manage_options` — běžný uživatel nikdy nemůže vytvořit
vlastní kategorii (item 16).

## D. Article relations

Tři meta pole (recipe_key/ISO/glossary translation_group), NE post ID — stabilní
identita přežije reimport/retranslate. Klasický meta box
(`class-magazine-meta-box.php`, samostatná třída, ne
`Atlas_Chuti_Meta_Box_Base` — ta je svázaná se sdíleným `Atlas_Chuti_Meta_Fields`
katalogem importeru, který tyto 3 pole nemá důvod znát) — čárkou oddělené textové
inputy, uložené jako pole stabilních klíčů.

Theme resolvery (`inc/magazine.php`, zrcadlí `inc/my-atlas.php`'s
`atlas_chuti_resolve_recipe_key()` vzor): `atlas_chuti_magazine_related_recipes()`/
`_countries()`/`_glossary()` — neplatný/neexistující klíč se prostě přeskočí,
nikdy nevytvoří rozbitý odkaz. Detail článku zobrazuje „Související recepty/země/
pojmy" (sekce blokovaná na skutečná data).

Zpětný hook `atlas_chuti_related_magazine_articles_for_recipe_key()` — malý,
capped (limit 3), lokálně škálovaný dotaz (ne sken celého Magazínu), implementován
HNED (ne odloženo), protože resolverová infrastruktura už existovala z předchozích
kroků a mezní náklad byl malý.

## E. Discussion architecture

**Model**: CPT `atlas_topic` (téma) + nativní WP komentáře jako odpovědi (žádný
paralelní systém — item 14's vlastní doporučení, potvrzeno auditem). `has_archive
=>'diskuze'`, `rewrite=>['slug'=>'diskuze','with_front'=>false]` — stejný čistý
vzor jako `atlas_recipe`'s `/recepty/`.

**Moderace téma**: nové téma od přihlášeného uživatele publikuje IHNED
(`post_status='publish'`) — rate limiting (3 témata/10 min) + nonce + povinné
přihlášení + sanitizace obsahu je primární anti-abuse vrstva; moderátor může
reaktivně uzavřít/připnout/smazat (`Atlas_Chuti_Discussion::
handle_admin_post_moderate()`, capability-gated na `moderate_comments`).

**Odpovědi**: nativní WP komentáře na `atlas_topic` — moderace dědí site-wide
Discussion Settings beze změny. Anonymní odpověď blokována DVAKRÁT (stejný
belt-and-suspenders vzor jako Krok 5's recipe komentáře): `comments_open` filter
(`require_login_and_open_for_topic_reply`) A `preprocess_comment` filter
(`guard_topic_reply`, `wp_die()` při obejití).

**Sanitizace obsahu**: úzký `wp_kses()` allowlist (`ALLOWED_HTML` — jen `p, br,
strong, em, a, ul, ol, li, blockquote`), užší než `wp_kses_post()` — fórový
příspěvek nemá editoriální důvod pro shortcody/embedy/obrázky/nadpisy.

**Rate limiting**: transient counter (`hash(bucket+user_id+hash(IP+wp_salt))`),
stejný vzor jako Krok 5's `class-ratings.php` — 3 témata/10 min, 10 odpovědí/10
min (odpovědi přes nativní WP komentáře, limit vynucen v `guard_topic_reply()`).

**Register-logika vs. HTTP glue split** (stejný vzor jako `class-account.php`):
`create_topic($user_id,$title,$content,$category_key)` je čistá, testovatelná
metoda vracející post ID nebo `WP_Error`, NIKDY nevolá `exit()`;
`handle_admin_post_create()` je tenký HTTP wrapper (nonce → volání → redirect).
Přesně tento split umožňuje `tests/harness-step-06.php` otestovat vytváření témat
bez živého HTTP round-tripu.

**Kategorie** (item 16): 8 CZ/EN párů (`Co dnes vaříte?`/`Rady a pomoc`/`Pečení`/
`Česká kuchyně`/`Kuchyně světa`/`Suroviny`/`Spotřebiče`/`Začátečníci`), uzavřená
sada — `Atlas_Chuti_Discussion::is_valid_category()` odmítne cokoli mimo
`Atlas_Chuti_Taxonomy_Labels::keys('atlas_topic_category')`.

**Frontend**: `archive-atlas_topic.php` (seznam + kategorijní filtr + formulář
nového tématu, jen pro přihlášené — `<details>`/`<summary>` toggle, login/
registrace CTA jinak), `single-atlas_topic.php` (detail + `comments_template()`
pro odpovědi, moderační odkazy capability-gated), `template-parts/topic-row.php`
(sdílený řádek, použitý i na homepage a v archivu).

**Můj Atlas → Moje témata** (item 21): implementováno HNED (ne odloženo) —
`Atlas_Chuti_Discussion::get_user_topics()` zrcadlí Krok 5's
`get_user_recipe_comments()`, mezní náklad byl malý.

## F. General pages

`class-page-setup.php`'s `expected_pages()` rozšířen o `magazin` (Page +
`template-magazine.php`, publish override), `diskuze` (potvrzení CPT archivu,
stejně jako `recepty`/`slovnicek`) a 9 nových obecných/právních stránek (`jak-
atlas-funguje`, `redakce-autori`, `nahlasit-chybu`, `faq`, `pro-media`,
`pravidla-komunity`, `pravidla-ugc`, `autorska-prava`, `nastaveni-cookies`) —
všechny prázdné, draft, žádný finální text (item 22's vlastní zákaz).

Pole `expected_pages()` rozšířeno o volitelný 3. prvek (publish-status override) —
zpětně kompatibilní, výchozí `'draft'` pro VŠECHNY existující položky (nulová
změna chování), jen `magazin` používá override `'publish'`.

**Newsletter** a **Staňte se autorem** (item 22's vlastní `(hook only)`/`(budoucí
hook)` poznámka) — NEVYTVOŘENY jako stránky, protože zadání samo říká, že jde
zatím jen o budoucí hook. Zdokumentováno v sekci N.

## G. Footer/nav

**Nav aktivace** (item 26): 3 `is-soon` placeholder spany (`inc/template-tags.php`
`atlas_chuti_primary_nav()`) nahrazeny reálnými odkazy — Magazín
(`atlas_chuti_system_url('magazine')`), Tipy a triky
(`atlas_chuti_magazine_tips_tricks_url()`, jen pokud term reálně existuje), Diskuze
(`atlas_chuti_discussion_url()`).

**Footer redesign** (item 24): 5 skupin (Objevujte/Komunita/O Atlasu/Pro
partnery/Právní), 2 nové nav menu lokace (`footer-community`, `footer-partners`,
`footer-tools` přejmenováno na `footer-community`). `.footer-columns` je `flex-
wrap`, ne fixní grid — 5. sloupec funguje bez CSS změny.

**Draft-safe odkazy** (item 23/26 — oprava PŘED-EXISTUJÍCÍ chyby): footer dřív
odkazoval na systémové stránky (`o-projektu`, `kontakt`, …) VŽDY, i když byly
draft — reálný broken-link bug z Kroku 1/5. Nová funkce
`atlas_chuti_system_url_if_ready($key)` vrací URL JEN pokud je stránka publikovaná,
jinak `null` → `atlas_chuti_footer_nav()`'s existující `(brzy)` fallback (žádná
nová UI komponenta). Jakmile editor stránku publikuje, footer automaticky začne
odkazovat bez redeploy.

**Homepage bloky** (item 27/28): Tipy a triky highlight (guardováno na reálná
data z `category` termu), Diskuze „Nová témata" (guardováno na reálné publikované
téma — nikdy fake „nejdiskutovanější"). Oba bloky mizí bez reálného obsahu.

## H. SEO/Discover/GEO

**Article schema** (item 11): `article_schema()` — headline/description/image
(jen pokud existuje featured image)/author (display_name)/datePublished/
dateModified/mainEntityOfPage/inLanguage, vše z reálných dat, žádný fiktivní
rating/review.

**Canonical**: rozšířeno o `is_category()` a `is_post_type_archive('atlas_topic')`
větve (self-canonical, stejný vzor jako recepty/slovníček archivy), včetně
pagination-aware `get_pagenum_link()`.

**Robots**: nová větev — prázdná Magazín kategorie NEBO prázdný Diskuze archiv →
`noindex,follow` (item 34), automaticky mizí jakmile existuje reálný obsah
(`$wp_query->found_posts`).

**Sitemap**: `filter_sitemap_taxonomies()` rozšířen o `category` (Magazín má
reálné indexovatelné archivy); `atlas_topic_category` VĚDOMĚ nepřidán — nemá
vlastní indexovatelnou archive URL (filtrování kategorie na `/diskuze/` je query
var, ne term archiv, stejný vzor jako recipe filtry).

**Discussion SEO** (item 32): veřejné publikované téma je indexovatelné (žádný
noindex jen kvůli existenci) — closed jen blokuje nové odpovědi, neznamená
noindex. `DiscussionForumPosting` schema VĚDOMĚ ODLOŽENO na „Krok 9" (item 32's
vlastní povolení) — viz sekce N.

**Search** (item 30): `Atlas_Chuti_Search::POST_TYPES` rozšířeno o `post` a
`atlas_topic` — fórové ODPOVĚDI (WP komentáře) nikdy nejsou ve výsledcích, protože
tato třída dotazuje jen `wp_posts`.

**GEO/AIO**: article relations (recipe_key/ISO/glossary) jsou explicitní stabilní
klíče, žádný keyword-stuffing v taxonomy popiscích (žádné popisky vůbec —
`category` termy mají jen název).

## I. Security

- Vytvoření tématu: nonce (`atlas_topic_create`) + povinné přihlášení + rate
  limit + `wp_kses()` sanitizace s úzkým allowlistem + validace proti uzavřené
  sadě kategorií.
- Moderace: nonce (`atlas_topic_moderate_{id}`) + `current_user_can(
  'moderate_comments')` — nikdy subscriber akce.
- Odpovědi: nativní WP komentáře — spam/moderation/capability handling zdarma od
  jádra, dvojitá kontrola přihlášení (`comments_open` + `preprocess_comment`).
- Article relations: resolver vždy ověřuje existenci cíle (`find_by_recipe_key()`
  atd.) — neplatný klíč se tiše přeskočí, nikdy SQL injection vektor (parametrizované
  `meta_query`).
- Žádné zvýšení subscriber capabilities nad rámec Kroku 5 — vytváření tématu jde
  přes `Atlas_Chuti_Discussion::create_topic()`, nikdy přes standardní WP editor
  screen (subscriber nemá `edit_posts`).
- Meta box (`class-magazine-meta-box.php`): nonce + `current_user_can('edit_post')`
  + `sanitize_text_field()` na každou položku.

## J. Performance

- Zpětný hook (recipe→články) je capped `get_posts(limit=3)` s `meta_query` LIKE
  na serializované pole — jeden indexovaný dotaz, ne sken.
- Homepage bloky (Tipy a triky, Diskuze) — každý max 3-4 položky, žádný
  `posts_per_page=-1`.
- Diskuze archiv i odpovědi jsou paginované (`paginate_links()`,
  `the_comments_navigation()` nativně).
- `atlas_chuti_related_magazine_articles_for_recipe_key()` — lokálně škálovaný
  (locale scoping je automatický přes `pre_get_posts`), nikdy dotaz na celý
  Magazín.

## K. Testy

`tests/harness-step-06.php` — 36 číslovaných scénářů (44 kontrol včetně
podscénářů, stejná `1b`/`3b` konvence jako `harness-step-05.php`), **0 selhání**:

```
=== Group 1: Magazine architecture + locale isolation (5) ===
1. Magazín článek je standardní `post` — žádný nový CPT
2-3. Magazín dotaz je locale-scoped (cs-CZ OR/NOT-EXISTS fallback, en exact match)
4. Tipy a triky kategorie seeduje reálný CZ+EN `category` term pár, spárovaný přes Polylang bridge
5. opakovaný seed je idempotentní

=== Group 2: Article schema + SEO (6) ===
6. article_schema() staví Article JSON-LD z reálných dat
7. article_schema() nikdy nefabrikuje `image` bez featured image
8. sitemap taxonomy whitelist obsahuje `category`, vylučuje technické taxonomie
9-10. get_robots_directive(): prázdná kategorie/Diskuze archiv → noindex,follow
10b. Magazín komentáře jsou VYPNUTÉ

=== Group 3: Cross-linking (4) ===
11-14. related_recipes/countries resolvery + neplatný klíč se přeskočí + zpětný hook

=== Group 4: Homepage magazine block (2) ===
15-16. jen publikované posty, žádné míchání locale

=== Group 5: Diskuze topic creation (7) ===
17. anonymní create_topic() odmítnut
18. platné vytvoření uspěje, publikuje ihned
18b. locale uloženo při vytvoření
18c. rate limit odmítne po překročení
19-21. prázdný title/content/neplatná kategorie odmítnuty
22. neplatný nonce odmítnut

=== Group 6: Diskuze locale isolation + replies + moderation (8) ===
23-24. Diskuze dotaz je locale-scoped
25-26. anonymní odpověď blokována (filter i wp_die())
27. přihlášený uživatel může odpovědět na otevřené téma
28. uzavřené téma blokuje i přihlášeného
29-30. normální uživatel nemůže moderovat, editor může (pin/close round-trip)

=== Group 7: Trash visibility + search + general pages/footer (6) ===
31. smazané téma není veřejně vidět
32. publikované téma je veřejně čitelné
33. search pokrývá post + atlas_topic
34. `magazin` page-setup má publish override, ostatní zůstávají draft
35-36. atlas_chuti_system_url_if_ready() vrací null pro draft, reálnou URL po publikaci
```

**Regrese**: `harness-step-03.php` (64 kontrol, 0 selhání), `harness-step-04.php`
(33 kontrol, 0 selhání), `harness-step-05.php` (48 kontrol, 0 selhání) — všechny
stále prochází beze změny.

**Co harness NEZKOUŠÍ** (stejná hranice jako každý předchozí harness) — potřebuje
staging:

- Reálný HTTP round-trip přes `admin-post.php` (nonce ověřený proti reálné
  cookie session, `exit()` v `handle_admin_post_create()`/
  `handle_admin_post_moderate()`).
- Vykreslení šablon (`single.php`, `category.php`, `template-magazine.php`,
  `archive-atlas_topic.php`, `single-atlas_topic.php`) — vizuální/layout korektnost.
- Reálný Polylang install (bridge testován proti věrné in-memory simulaci).
- Skutečné indexování/crawlování (Google Search Console).

## L. Staging checklist

- [ ] CZ `/magazin/` — landing page se vykresluje, lead story + strips fungují
- [ ] EN magazine route (přes `atlas_chuti_system_url('magazine')` jednou je
      anglická `/magazin/` Page vytvořená a spárovaná)
- [ ] Kategorie Tipy a triky — `/category/tipy-a-triky/` — odlišný vizuál
- [ ] Detail článku — H1, perex, autor, datum, featured image, obsah, kategorie,
      breadcrumbs, související obsah
- [ ] Article schema — validace přes Google Rich Results Test
- [ ] Hreflang — jen pro reálně existující překlad (žádný fake pár)
- [ ] Homepage magazine blok — reálné posty, žádné fake karty
- [ ] CZ `/diskuze/` — archiv, filtr kategorií, formulář nového tématu
- [ ] EN `/en/discussions/` (jakmile Polylang má nastavený per-language archive
      slug pro `atlas_topic` — jinak sdílený slug, zdokumentováno jako známé
      omezení)
- [ ] Vytvoření tématu přihlášeným uživatelem — end-to-end přes admin-post.php
- [ ] Anonymní pokus o vytvoření — zablokován, CTA k přihlášení
- [ ] Odpovědi — přihlášený uživatel, anonymní blok
- [ ] Moderace — close/pin/trash jako editor/admin
- [ ] Mobile 390px — žádný horizontální scroll, žádné přetečení
- [ ] Footer CZ/EN — žádný broken/draft odkaz
- [ ] Nav odkazy — Magazín/Tipy a triky/Diskuze vedou na reálné cíle
- [ ] Prázdné stavy — Magazín bez článků, kategorie bez obsahu, Diskuze bez témat
- [ ] Žádný broken odkaz na draft legal stránku
- [ ] Search — pokrývá Magazín + Diskuze, CZ/EN se nemíchá
- [ ] Sitemap (`/wp-sitemap.xml`) — obsahuje `post`/`atlas_topic`/`category`,
      neobsahuje draft/účet/login

## M. Changed files

**Nové soubory:**
- `wp-content/plugins/atlas-chuti-core/includes/class-magazine.php`
- `wp-content/plugins/atlas-chuti-core/includes/class-magazine-meta-box.php`
- `wp-content/plugins/atlas-chuti-core/includes/class-discussion.php`
- `wp-content/themes/atlas-chuti/single.php`
- `wp-content/themes/atlas-chuti/category.php`
- `wp-content/themes/atlas-chuti/archive.php`
- `wp-content/themes/atlas-chuti/template-magazine.php`
- `wp-content/themes/atlas-chuti/archive-atlas_topic.php`
- `wp-content/themes/atlas-chuti/single-atlas_topic.php`
- `wp-content/themes/atlas-chuti/template-parts/topic-row.php`
- `wp-content/themes/atlas-chuti/inc/magazine.php`
- `tests/harness-step-06.php`

**Upravené soubory:**
- `wp-content/plugins/atlas-chuti-core/atlas-chuti-core.php` (require + init nových tříd)
- `wp-content/plugins/atlas-chuti-core/includes/class-register-meta.php` (i18n fix + relation fields)
- `wp-content/plugins/atlas-chuti-core/includes/class-i18n.php` (LOCALIZED_POST_TYPES + atlas_topic)
- `wp-content/plugins/atlas-chuti-core/includes/class-polylang-bridge.php` (term language/translation metody)
- `wp-content/plugins/atlas-chuti-core/includes/class-post-types.php` (register_topic)
- `wp-content/plugins/atlas-chuti-core/includes/class-taxonomy-labels.php` (atlas_topic_category)
- `wp-content/plugins/atlas-chuti-core/includes/class-taxonomies.php` (atlas_topic_category registrace + seed)
- `wp-content/plugins/atlas-chuti-core/includes/class-comments.php` (disable_magazine_comments)
- `wp-content/plugins/atlas-chuti-core/includes/class-search.php` (POST_TYPES rozšíření)
- `wp-content/plugins/atlas-chuti-core/includes/class-seo.php` (article_schema, canonical, robots, sitemap)
- `wp-content/plugins/atlas-chuti-core/includes/class-page-setup.php` (nové stránky + publish override)
- `wp-content/plugins/atlas-chuti-core/includes/functions.php` (system_url_if_ready, discussion_url, magazine_category_url, breadcrumbs)
- `wp-content/themes/atlas-chuti/functions.php` (nav menu lokace + inc/magazine.php require)
- `wp-content/themes/atlas-chuti/footer.php` (5 skupin, draft-safe odkazy)
- `wp-content/themes/atlas-chuti/front-page.php` (Tipy a triky + Diskuze homepage bloky)
- `wp-content/themes/atlas-chuti/search.php` (post/atlas_topic výsledky)
- `wp-content/themes/atlas-chuti/comments.php` (closed-topic stav)
- `wp-content/themes/atlas-chuti/inc/template-tags.php` (nav aktivace)
- `wp-content/themes/atlas-chuti/inc/my-atlas.php` (temata sekce)
- `wp-content/themes/atlas-chuti/template-my-atlas.php` (Moje témata UI)

## N. Deferred

- **EN Diskuze archive slug** (`/en/discussions/`) — vyžaduje Polylang nastavení
  per-language CPT archive slug (Polylang UI, ne kód); do té doby
  `atlas_chuti_discussion_url()` vrací locale-korektní URL, ale se sdíleným
  `diskuze` slugem, dokud editor nenastaví anglický ekvivalent v Polylang.
- **Newsletter / Staňte se autorem stránky** — zadání samo je označuje `(hook
  only)`/`(budoucí hook)` — žádná stránka zatím nevytvořena, jen zdokumentováno.
- **`DiscussionForumPosting` schema** — explicitně odloženo na Krok 9 (item 32's
  vlastní povolení).
- **GDPR export/erase pro Diskuze témata** — Krok 5's `class-privacy.php`
  pokrývá favorite/cooked/ratings/photos, ale ne `atlas_topic` posty (odpovědi
  jsou WP komentáře, ty už core pokrývá). Diskuze TÉMATA jako uživatelský obsah
  nejsou zatím v exporteru/eraseru — zadání Kroku 6 to explicitně nežádalo, ale
  je to reálná mezera pro budoucí krok.
- **Magazine meta box UI test** — `class-magazine-meta-box.php`'s `save()`
  logika není v harness testována přímo (vyžadovala by stub
  `add_meta_box()`/`add_meta_boxes_post` hook, mimo scope); ověřeno vizuální
  inspekcí kódu + staging.

## O. Manuální WP kroky

1. Aktivovat/reaktivovat plugin (nebo spustit „Nastavení stránek" znovu) — spustí
   `Atlas_Chuti_Magazine::maybe_seed_categories()` a `atlas_topic_category` term
   seed při prvním `init`.
2. Spustit „Atlas chutí → Nastavení stránek" — vytvoří `magazin` (publikovanou) a
   `diskuze` (potvrzení CPT archivu) + 9 nových draft stránek.
3. `flush_rewrite_rules()` (automaticky při aktivaci pluginu, nebo ručně přes
   Nastavení → Trvalé odkazy → Uložit) — aby `/diskuze/` fungovalo.
4. V Polylang nastavit anglický ekvivalent `/magazin/` Page (přeložit) a zvážit
   per-language slug pro `atlas_topic` archiv.
5. Editor postupně publikuje nové obecné/právní stránky s reálným textem — footer
   je automaticky začne odkazovat (`atlas_chuti_system_url_if_ready()`).
6. Přiřadit reálné WP menu položky k novým lokacím `footer-community`/
   `footer-partners`, pokud má editor chuť je customizovat nad rámec fallbacku.
