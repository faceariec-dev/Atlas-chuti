# Checkpoint 10F — vizuální preview bez importu obsahu a obrázků

## A. Starting state

```text
branch: claude/new-session-0gc4ib
HEAD:   a3c6069 — Content: curate World Classics and version image manifest
git status: nothing to commit, working tree clean
```

Přesně odpovídá očekávání checkpointu. Žádná odchylka, žádný důvod
zastavit se na kroku 1.

## B. Preview environment discovered

Systematický audit repozitáře a tohoto execution containeru:

| Ověřováno | Nález |
|---|---|
| `wp-config.php` kdekoliv v repu/containeru | neexistuje (`find / -maxdepth 3 -iname wp-config.php` — 0 výsledků) |
| WP-CLI (`wp` binary) | není nainstalováno |
| `mysql`/`mariadb` binary nebo běžící DB | není nainstalováno, žádný proces neběží |
| `docker-compose.yml`/`docker/` konfigurace v repu | neexistuje |
| Docker daemon dostupný v tomto containeru | `docker ps` selhává — `/var/run/docker.sock` neexistuje |
| Dokumentovaná staging URL/credentials | žádné (`README.md`, `docs/` prohledáno) |
| Deploy workflow | `.github/workflows/deploy.yml` — **jediný** existující deploy mechanismus, `workflow_dispatch` (ruční), přímo SSH na produkční FORPSI webroot, žádný staging/preview target parametr |
| Repo obsahuje WP core soubory | Ne — `.gitignore` explicitně vylučuje `/wp-admin/`, `/wp-includes/`, `/wp-*.php` atd. (repo drží jen custom theme + core plugin, jak README sám popisuje) |

**Závěr: žádné staging/dev WordPress prostředí neexistuje** — ani v repu,
ani zdokumentované, ani spustitelné v tomto sandboxovaném prostředí. Jediná
existující cesta k živému WordPressu je přímý produkční deploy, který je
tímto checkpointem výslovně zakázaný.

## C. Deployment method used

Žádný. Nebyl proveden žádný deploy, žádný SSH přenos, žádná změna
produkčního webrootu (`/web/htdocs/www.atlaschuti.cz/home/www`) ani jeho
DNS/DB/`wp-config.php`.

## D. Exact files deployed

Žádné — staging neexistuje, deploy neproběhl.

## E. Content import

```text
Imported recipes: 0
Imported countries: 0
Imported glossary entries: 0
```

`production-data/europe-1/` a `production-data/europe-1-en/` zůstaly
výhradně jako read-only reference — nebyl spuštěn žádný importer, žádný
seed skript, žádný `wp import`/WP-CLI příkaz.

## F. Image import

```text
wp atlas image-import ... — nespuštěno
```

Žádný nový attachment, žádný stažený/vygenerovaný/fake obrázek.

## G. DB modifications

Žádné — v tomto prostředí neexistuje žádná WordPress databáze, do které by
šlo zapisovat. Žádný soubor mimo `docs/implementation-reports/` a tento
report nebyl vytvořen ani upraven.

## H. Pages reviewed

**Žádné živé stránky nebylo možné vizuálně otevřít** — bez běžícího
WordPressu (PHP+DB+WP core) neexistuje URL, na kterou by šlo přistoupit
prohlížečem nebo screenshot nástrojem. Toto NENÍ obcházeno předstíráním
výsledku.

Místo toho byl ověřen **zdrojový stav kódu**, který by takový preview
napájel, statickou/harness cestou (viz sekce I "Regression minimum"):
domain resolver (`Atlas_Chuti_Domain_Map`), homepage editorial split
(Czech-first `.cz` / global-first `.com`), World Classics blok (žádné
hardcoded karty — `atlas_chuti_home_world_classics()` vrací jen recepty,
které v DB skutečně existují jako publikované posty daného locale; bez DB
vrací prázdné pole, ne fake karty), a napříč theme/pluginem nebyl nalezen
žádný pozůstatek hardcoded `/en/` cesty (`grep -rn "'/en/'"` nad celým
theme+pluginem — 0 výsledků mimo testy).

## I. Regression minimum (žádný test nezapisuje do produkční DB — všechny běží proti in-memory stub environmentu, stejná konvence jako každý předchozí checkpoint)

```text
harness-step-04.php (multilingual):                     33 checks, 0 failing
harness-step-06.php (pages/discussion):                 44 checks, 0 failing
harness-step-08.php (interactive tools):                52 checks, 0 failing
harness-step-09.php (SEO):                               51 checks, 0 failing
harness-checkpoint-10b.php (domains/homepage):           37 checks, 0 failing
harness-checkpoint-10c.php (media pipeline, no import):  30 checks, 0 failing
harness-checkpoint-10c1-quality-gate.php:                12 checks, 0 failing
harness-checkpoint-10e-image-production-manifest.php:    18 checks, 0 failing
harness-checkpoint-10e1-editorial-curation.php:          21 checks, 0 failing
-----------------------------------------------------------------------------
Celkem: 298 checks, 0 failing

Checkpoint 10D integrity: `git diff --stat -- production-data/` → prázdný
```

Doménová logika (brief sekce 9) je konkrétně pokryta
`harness-checkpoint-10b.php` checky #1–3d, #11–12, #15 (CZ↔`.cz`,
EN↔`.com`, neznámý host nikdy nehádá locale, žádný cross-domain cookie
hack, `front-page.php`'s `$is_en` odvozeno z host-aware
`current_locale()`) — všechny PASS.

## J. Desktop/mobile QA

Nebylo možné provést — žádný browser/screenshot nástroj nemá k dispozici
běžící stránku (viz sekce B/H). V souladu s briefem ("Pokud
screenshot/browser tooling není dostupný, nevymýšlej výsledky") nebyl
žádný vizuální výsledek předstírán.

## K. Issues

1. **Chybějící staging prostředí** — jediná mezera bránící vizuálnímu
   review. Není bug v kódu, je to chybějící infrastruktura (viz sekce N pro
   doporučený další krok).
2. Žádná jiná zjištění — regresní sada (298 kontrol) je 100% zelená, žádná
   drobná prezentační chyba nebyla nalezena, protože nebylo možné nic
   vizuálně vykreslit ke kontrole.

## L. Production untouched

```text
git diff --stat -- production-data/          → prázdný
git status                                    → working tree clean (kromě tohoto reportu)
SSH/rsync do produkčního webrootu proběhlo?   → ne
Produkční DB/wp-config.php změněno?           → ne (neexistuje přístup, žádný pokus)
.github/workflows/deploy.yml spuštěn?         → ne
```

## M. Next step

Checkpoint se zastavuje přesně podle briefu (sekce 2, větev "pokud
staging/dev prostředí neexistuje"). Nejmenší bezpečné kroky k reálnému
vizuálnímu review, seřazené od nejlevnějšího:

1. **Ephemerní lokální WordPress preview (doporučeno jako první krok)** —
   nainstalovat WordPress core + SQLite/MySQL do tohoto (nebo podobného)
   sandboxovaného containeru, aktivovat existující theme + core plugin,
   **bez importu `production-data/`** (registruje se jen datový model přes
   plugin activation hooks — prázdné CPT archivy). To by umožnilo skutečné
   screenshoty homepage/recipe-detail/My Atlas layoutů s empty states,
   přesně jak brief žádá v sekci 6–7. Toto NENÍ produkční server a nikam
   se nenasazuje — žije jen v tomto ephemerním containeru a zanikne s ním.
   Vyžaduje explicitní souhlas uživatele, protože jde o vytvoření nové
   (byť lokální, dočasné) instance, což brief výslovně podmiňuje.
2. **Skutečné staging prostředí** — samostatná subdoména/hosting s vlastní
   DB, mimo `atlaschuti.cz`/`atlaschuti.com` produkci, s vlastním
   `wp-config.php` a přístupovými údaji zdokumentovanými v repu
   (`docs/`) pro příští checkpointy. Vyžaduje reálné hostingové
   rozhodnutí uživatele (kde, jaký účet/DNS) — mimo rozsah tohoto
   checkpointu.
3. **Bez preview prostředí** — pokračovat jen v kódové/testovací práci
   (jak dosud) a vizuální review odložit až do chvíle, kdy jedna z výše
   uvedených možností vznikne.

Žádná z těchto možností nebyla v tomto checkpointu provedena — čeká na
rozhodnutí uživatele.

---

```text
Produkční web nebyl změněn.
Produkční databáze nebyla změněna.
Nebyl importován žádný recipe/country/glossary content.
Nebyl importován žádný obrázek.
Preview nebylo možné spustit — v repozitáři ani v tomto prostředí neexistuje
žádné staging/dev WordPress prostředí (chybí WP core, databáze i staging
deploy cesta; jediný existující deploy workflow jde přímo na produkci a
nebyl spuštěn).
```
