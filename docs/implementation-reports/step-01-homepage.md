# Krok 1 — redakční homepage a portálová navigace

## A. Finální stav Kroku 1

Krok 1 (redakční homepage, portálová navigace s mega-menu, mobilní spacing —
commit `7d3eb86`) je nyní kompletní včetně opravy popsané v tomto dokumentu.
Portálová struktura headeru (Recepty s mega-menu, Země, Magazín/Tipy a
triky/Diskuze jako bezpečné „brzy" placeholdery, Více, oblast Můj Atlas/
přihlášení, mobilní varianta, klávesová dostupnost) se nyní renderuje vždy —
bez ohledu na to, jestli má administrátor v `Vzhled → Menu` přiřazené vlastní
WordPress menu do polohy „primary". Žádný jiný redesign, obsahová ani
backendová změna nebyla provedena.

## B. Oprava navigace

**Problém:** `atlas_chuti_primary_nav()` (`inc/template-tags.php`) měla na
začátku funkce podmínku `if ( has_nav_menu( 'primary' ) ) { wp_nav_menu(...);
return; }`. Jakmile admin přiřadil do polohy „primary" jakékoliv vlastní
menu, tato větev **kompletně nahradila** celý obsah navigace holým výpisem
z `wp_nav_menu()` a `return`ovala dřív, než se stihl vykreslit zbytek funkce
— mega-menu Recepty, odkaz Země, „brzy" placeholdery Magazín/Tipy a
triky/Diskuze i skupina Více. Nová portálová navigace tak mohla být tiše
deaktivována jediným nastavením v adminu, aniž by to admin vůbec zamýšlel.

**Oprava:** Přepsána `atlas_chuti_primary_nav()` tak, aby pevná portálová
struktura (Recepty mega-menu → Země → tři „brzy" placeholdery → Více
mega-menu) vykreslovala **vždy, nepodmíněně**, jako první část výstupu
funkce. Podmínka `has_nav_menu( 'primary' )` zůstala, ale přesunula se **za**
tuto pevnou strukturu a už nikdy `return`uje — pokud admin vlastní menu
přiřadí, jeho položky se vykreslí jako **dodatečné odkazy připojené na konec**
téhož výstupu, stejným walkerem (`Atlas_Chuti_Nav_Walker`) a tedy stejným
plochým `<a href>` výstupem a stejným CSS stylováním jako zbytek navigace.

- **Vlastní WP menu (přiřazené):** portálová struktura se vykreslí celá,
  položky vlastního menu se přidají za ni jako další odkazy na stejné úrovni
  (`<nav class="main-nav">`). Menu tak lze využít (např. pro Kontakt, O nás
  apod.), ale nemůže nic z portálové struktury skrýt ani nahradit.
- **Fallback (bez vlastního menu):** chování beze změny oproti KROK 1 —
  stejná pevná struktura, nic se nepřidává.
- **Desktop a mobil:** `header.php` volá `atlas_chuti_primary_nav()` **jen
  jednou** uvnitř `<nav class="main-nav">`. Mobilní/desktopové zobrazení
  řeší výhradně CSS media query (`@media (max-width: 900px)` v
  `assets/css/main.css`) nad touž vykreslenou značkou — žádná druhá,
  paralelní PHP ani JS cesta pro mobil neexistuje, takže oprava platí
  identicky pro obě zobrazení.
- Soubor `assets/js/nav.js` (toggle mega-panelů, `aria-expanded`, Escape,
  klik mimo) nebyl měněn — pracuje nad CSS třídami (`.nav-toggle`,
  `.nav-mega-toggle`, `.nav-item.has-mega`), které ve funkci zůstaly beze
  změny, takže veškerá stávající klávesová/accessibility logika funguje dál
  beze změny.
- `atlas_chuti_footer_nav()` (patičkové menu location) záměrně **nebyla
  dotčena** — netýká se portálové hlavičky ani mega-menu a její úprava by
  byla nad rámec zadání („neprováděj další redesign").

Nevznikly dva paralelní navigační systémy — je to jedna funkce, jeden
výstup, jedna sada CSS/JS háčků; vlastní menu je čistě aditivní.

## C. Změněné soubory

- `wp-content/themes/atlas-chuti/inc/template-tags.php` — přepsána
  `atlas_chuti_primary_nav()` (viz sekce B). Jediný změněný soubor.

## D. Testy

```
find wp-content/themes/atlas-chuti -name '*.php' -print0 | xargs -0 -n1 php -l
find wp-content/plugins/atlas-chuti-core -name '*.php' -print0 | xargs -0 -n1 php -l
find wp-content/themes/atlas-chuti -name '*.js' -print0 | xargs -0 -n1 node --check
```
→ **Bez chyb** (celé theme i plugin, včetně `assets/js/nav.js`, který nebyl
měněn, ale byl znovu ověřen pro jistotu).

**HTML/PHP struktura:** programová kontrola párování `<div>`/`</div>` uvnitř
těla `atlas_chuti_primary_nav()` — 4 otevření, 4 uzavření, vyváženo.

**`aria-expanded`/`aria-controls`:** obě mega-menu tlačítka (Recepty, Více)
mají `aria-expanded="false"` v počátečním stavu a `aria-controls` ukazující
přesně na `id` odpovídajícího panelu (`nav-mega-recepty`, `nav-mega-more`) —
ověřeno regulárním výrazem nad vygenerovaným výstupem.

**Klávesnice / Escape / focus:** `assets/js/nav.js` nebyl měněn. Mega-menu
tlačítka jsou nativní `<button type="button">` (Enter/mezerník fungují bez
další JS logiky), `keydown` na `Escape` zavírá všechny otevřené panely, klik
mimo `.nav-item.has-mega` panely zavírá. Toto chování bylo ověřeno čtením
nezměněného souboru, nikoli v živém prohlížeči (viz sekce E).

**Funkční ověření obou scénářů (bez živého WordPressu):** Protože v tomto
prostředí není spuštěný WordPress/DB, byla `atlas_chuti_primary_nav()`
spuštěna přímo v PHP pod minimálním WP stubem (stub `has_nav_menu()`,
`wp_nav_menu()` vracející jednu testovací položku „Kontakt", `esc_url()`
apod.) — dvakrát: se `has_nav_menu( 'primary' )` vráceným jako `false` i
jako `true`. V obou bězích byly ve výstupu ověřeny: mega-menu toggle Recepty
+ `aria-expanded="false"`, odkaz Země, všechny tři „brzy" placeholdery,
mega-menu toggle Více, oba odkazy uvnitř Více; ve scénáři s vlastním menu
navíc přítomnost položky „Kontakt" **spolu s** celou portálovou strukturou
(důkaz, že jde o doplnění, ne náhradu).

```
Scenario A (bez vlastního menu):  9 passed, 0 failed
Scenario B (s vlastním menu):    10 passed, 0 failed
```

**Broken links:** všechny odkazy v `atlas_chuti_primary_nav()` procházejí
přes `esc_url()` a existující, nezměněné helpery (`atlas_chuti_system_url()`,
`get_post_type_archive_link()`, `atlas_chuti_recipe_mega_menu_items()`, která
už dřív obsahuje vlastní ochranu proti neexistujícím cílům — např. položka
„Česká kuchyně" se přidá jen když Česko jako země skutečně existuje). Odkazy
z případného vlastního WP menu pochází výhradně z toho, co si admin sám
nastavil v adminu — mimo kontrolu/odpovědnost šablony, stejně jako dřív.

## E. Omezení / rizika

- Tento test běžel bez skutečného WordPressu/databáze — jde o maximální
  statickou/funkční kontrolu v izolovaném PHP stubu, ne o vizuální ověření v
  prohlížeči. Před nasazením na produkci doporučuji rychlou vizuální
  kontrolu na živém WordPressu: (1) desktop i mobilní šířku bez vlastního
  menu, (2) totéž s libovolným vlastním menu přiřazeným do „primary", a
  (3) klávesnicí projít Tab → Enter/mezerník na obou mega-menu tlačítkách a
  Escape.
- Pokud admin do vlastního menu přidá víceúrovňovou položku (podmenu),
  `Atlas_Chuti_Nav_Walker` ji vykreslí plochu (bez vnořeného `<ul>`) — to je
  beze změny stávající chování walkeru (stejné už dřív platilo pro patičková
  menu i pro starou větev primary menu) a není součástí této opravy;
  neupravoval jsem to, protože to není součástí nahlášeného problému.

## F. Manuální kroky

Žádné.
