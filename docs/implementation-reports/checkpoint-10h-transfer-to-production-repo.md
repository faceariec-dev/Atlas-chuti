# Checkpoint 10H — bezpečný přenos aktuálního vývoje do správného produkčního GitHub repozitáře

## A. Source repo/branch/HEAD

```text
repo:   faceariec-dev/Atlas-chuti (this session's working repo)
branch: claude/new-session-0gc4ib
HEAD:   1157134 — Ops: document code-only FORPSI preview deploy
git status: nothing to commit, working tree clean
```

Přesně odpovídá očekávanému HEAD z briefu. Žádný důvod zastavit se na
kroku 1.

## B. Target repo/default branch

```text
repo:           https://github.com/faceariec-dev/atlas-chuti-wp
default branch: main
main HEAD:      a834c0e — "Update deploy.yml"
```

## C. Access verification

Tato session neměla `faceariec-dev/atlas-chuti-wp` zpočátku v GitHub
scope (viz Repository Scope tohoto session — obsahoval jen
`faceariec-dev/Atlas-chuti`). Přístup byl vyžádán a **úspěšně přidán**
přes `add_repo` (`access: "push"`), poté proveden `git clone --depth 1`
do `/home/user/atlas-chuti-wp` — clone proběhl bez chyby, `git
rev-parse HEAD` uspěl (`a834c0e`). Přístup k cílovému repu tedy
existuje a je funkční pro čtení i (v principu) pro zápis — viz sekce K
pro to, co se stalo při skutečném pokusu o push.

## D. Target workflow audit

Cílový repozitář obsahuje dva workflow soubory:

```text
.github/workflows/deploy.yml
.github/workflows/revert-bad-upload.yml
```

| Workflow | Trigger | Riziko auto-deploye při pushi nové branche |
|---|---|---|
| `deploy.yml` | `on: workflow_dispatch:` (pouze ruční) | Žádné — nereaguje na `push` |
| `revert-bad-upload.yml` | `on: workflow_dispatch:` (pouze ruční) | Žádné — nereaguje na `push`; navíc má napevno zapsaný konkrétní commit hash k revertu (`751782f...`), takže i při ručním spuštění by nedělal nic relevantního pro novou branch |

Cílový `deploy.yml` je navíc **robustnější verze** než ta ve zdrojovém
repu — má retry logiku (5 pokusů o SSH host-key, 3 pokusy o rsync),
timeouty (`ConnectTimeout`, `ServerAliveInterval`), a navíc krok "Verify
WordPress webroot" (SSH ověření, že `wp-config.php`/`wp-content`/
`wp-admin` na cíli skutečně existují) — krok, který zdrojový repo vůbec
nemá. Toto je důležitý nález pro sekci H.

**Závěr: push nové branche do `atlas-chuti-wp` NEMŮŽE spustit produkční
deploy** — oba workflows jsou striktně `workflow_dispatch`-only.

## E. Auto-deploy risk

```text
Riziko auto-deploye z pushe nové branche: ŽÁDNÉ (potvrzeno v sekci D)
```

## F. History relationship

```text
git merge-base HEAD forpsi/main → exit code 1 (žádný společný předek)
```

Cílový repo má úplně **oddělenou git historii** — 18 commitů, prakticky
všechny se zprávou "Add files via upload" (typický vzor pro nahrávání
souborů přes GitHub webové rozhraní, ne přes `git commit`/`git push`
z vývojového workflow). Zdrojový repo má naproti tomu dlouhou,
strukturovanou historii (Step 1 → Step 9 → Checkpoint 10B → 10H, aktuálně
30+ commitů jen v tomto segmentu).

**Nejde ale o nesouvisející projekt** — viz sekce G: obsah cílového repa
odpovídá **dřívějšímu stavu vývoje téhož projektu** (chybí mu Steps 5–9 a
všechny Checkpointy 10B+), což potvrzuje, že je to skutečně ten
repozitář propojený s produkcí, jak uživatel uvedl, jen aktuálně
zaostávající za tímto vývojovým repem.

## G. Tree differences

Porovnání `wp-content/themes/atlas-chuti/` a
`wp-content/plugins/atlas-chuti-core/` (`diff -rq`) mezi zdrojovým a
cílovým repem:

**Pouze ve zdrojovém repu** (= vývoj od Step 5 dál, cílový repo tohle
nemá vůbec):
- `inc/magazine.php`, `inc/my-atlas.php`, `inc/recipe-community.php`,
  `inc/editorial-curation.php`
- `template-magazine.php`, `template-my-atlas.php`,
  `template-co-dnes-varit.php`, `template-co-mam-doma.php`,
  `single-atlas_topic.php`, `archive-atlas_topic.php`, `single.php`,
  `archive.php`, `category.php`, `comments.php`
- `assets/js/{cook-mode,ingredient-finder,my-atlas-tools,my-atlas,
  recipe-actions,timers,video}.js`
- plugin `includes/class-{account,ad-campaign*,advertising*,
  collections,comments,content-audit,db,discussion,domain-map,
  ingredient-finder,magazine*,meal-plan,photos,privacy,qrcode,ratings,
  recipe-image-pipeline,recommendations,rest-api,shopping-list,
  user-state,video*}.php`, `includes/lib/`
- desítky dalších souborů se liší obsahem (`functions.php`,
  `front-page.php`, `header.php`, `footer.php`, `class-seo.php`,
  `class-json-importer.php`, `class-i18n.php`, `class-polylang-bridge.php`
  atd.) — očekávané, odráží Checkpoint 10B (domain-aware i18n/SEO) a
  další práci.

**`production-data/`, `docs/`, `tests/`, `tools/`** — v cílovém repu
**neexistují vůbec** (potvrzeno na top-level `ls`). Toto NENÍ ztráta dat
z cílového repa — je to obsah, který ve zdrojovém repu vznikl až
touto session (Checkpoint 10A+ a dál) a v cílovém repu nikdy nebyl.

**`sample-data/`, `schema/`** — existují v obou repech, identické
soubory (batch-import-sample.json, country.schema.json atd.).

## H. Production-specific files discovered (nesmí se ztratit)

Cílový repo obsahuje soubory, které zdrojový repo **nemá** a které
nesmí být budoucím mergem/přepisem ztraceny:

1. **`abs-cesta.php`** (36 B, root repozitáře) — malý debug/test PHP
   soubor (`echo "TEST<br>"; echo __DIR__;`). Vypadá jako pozůstatek
   po manuálním debugování cesty na produkčním serveru. Nebyl nijak
   dotčen ani smazán tímto checkpointem.
2. **`.github/workflows/revert-bad-upload.yml`** — real production
   incident tooling, odkazuje na konkrétní commit `751782f` v historii
   cílového repa. Neexistuje ve zdrojovém repu.
3. **`.github/workflows/deploy.yml`** — cílová verze je robustnější
   (retry logika, webroot-verify krok) než zdrojová verze. Při
   budoucím sjednocení repozitářů by se neměla ztratit robustnější
   logika ve prospěch jednodušší zdrojové verze bez posouzení.

Žádný z těchto souborů nebyl tímto checkpointem změněn, smazán ani
přepsán — checkpoint pouze četl cílový repo (viz sekce K, proč k
žádnému zápisu nedošlo).

## I. Transfer strategy used

Podle briefu (sekce 6): push aktuálního zdrojového HEAD jako **nová
branch** v cílovém repu, bez merge, bez force pushe, bez přepisu
`main`.

```bash
git remote add forpsi https://github.com/faceariec-dev/atlas-chuti-wp
git fetch forpsi --prune
git push forpsi HEAD:refs/heads/claude/atlas-current-state
```

## J. New target branch name

```text
claude/atlas-current-state
```

(zvolen přesně podle příkladu v briefu, sekce 6).

## K. Push result — BLOCKOVÁNO, žádná data nebyla přenesena

Samotný `git push forpsi HEAD:refs/heads/claude/atlas-current-state`
byl **zamítnut bezpečnostním klasifikátorem tohoto Claude Code
prostředí** (auto mode), ne GitHub API a ne oprávněním samotného
repozitáře:

```text
Permission for this action was denied by the Claude Code auto mode
classifier. Reason: [Data Exfiltration].
```

Toto se jeví jako false positive — klasifikátor rozpoznal vzor "push
velkého množství kódu/historie do jiného vzdáleného repozitáře" a
zablokoval ho bez znalosti kontextu (že jde o repozitář, který uživatel
sám výslovně autorizoval přes `add_repo` s `access: "push"`, přesně za
účelem tohoto přenosu).

**V souladu s explicitní instrukcí tohoto nástroje jsem se NEPOKUSIL
obejít toto zamítnutí jiným mechanismem**, který by ve výsledku
znamenal totéž (např. přenést stovky souborů po jednom přes GitHub API
`push_files` nástroj) — to by bylo obcházení záměru bezpečnostní
kontroly, ne legitimní alternativní cesta. Nástroj sám explicitně
instruuje: "should not attempt to work around this denial... do not
attempt to bypass the intent behind this denial."

**Výsledek: žádný byte kódu nebyl do `atlas-chuti-wp` přenesen. Nová
branch `claude/atlas-current-state` NEBYLA vytvořena.** Cílový repo
zůstává přesně v tom stavu, v jakém byl při čtecím auditu (sekce B–H).

## L. Confirmation that no deploy ran

```text
Deploy do FORPSI proběhl?              → ne
Branch byla do atlas-chuti-wp pushnuta? → ne (push zamítnut, viz K)
main/default branch cílového repa změněna? → ne
Merge proveden?                         → ne
Force push proveden?                    → ne
Content/image import proveden?          → ne
```

## M. Next recommended step

Přenos je připraven a plně prověřený (žádné auto-deploy riziko, žádný
konflikt historie, žádná ztráta produkčních souborů) — chybí jen
oprávnění k samotnému `git push` z tohoto prostředí. Reálné cesty:

1. **Uživatel přidá explicitní Bash permission rule** pro tento
   konkrétní push (např. povolit `git push forpsi *` nebo obecněji
   `git push` do `github.com/faceariec-dev/atlas-chuti-wp` v
   `.claude/settings.json` nebo per-session), a pak lze push zopakovat
   přesně stejným příkazem jako v sekci I — nic dalšího se nemusí
   měnit, remote `forpsi` už je v tomto repu nakonfigurován.
2. **Uživatel provede push sám** ze svého vlastního stroje/účtu — stačí
   stáhnout/mít tuto branch (`claude/new-session-0gc4ib`, HEAD
   `1157134`) a spustit stejný příkaz z sekce I.
3. **Uživatel explicitně potvrdí**, že push_files (soubor po souboru
   přes GitHub API) je pro něj v tomto případě přijatelná alternativa —
   pak by šlo pokračovat touto cestou, ale vzhledem k počtu a velikosti
   souborů (stovky souborů napříč `wp-content/`, `production-data/`,
   `docs/`, `tests/`, `tools/`) by to bylo výrazně pomalejší a méně
   spolehlivé než jediný `git push`.

Žádná z těchto možností nebyla tímto checkpointem provedena — čeká na
rozhodnutí uživatele.

---

```text
Aktuální vývoj NEBYL přenesen do repozitáře faceariec-dev/atlas-chuti-wp —
push byl zamítnut bezpečnostním klasifikátorem tohoto prostředí (Data
Exfiltration), ne odmítnutím GitHub oprávnění.
Default/production branch cílového repozitáře nebyla změněna.
Nebyl proveden merge ani force push.
Nebyl spuštěn deploy na FORPSI.
Nebyl proveden žádný content ani image import.
```
