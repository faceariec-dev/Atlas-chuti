# Checkpoint 10F.1 — ephemerní lokální WordPress preview + screenshoty

## A. Starting state

```text
branch: claude/new-session-0gc4ib
HEAD:   7edd9ed — Docs: audit visual preview environment (no staging exists yet)
git status: nothing to commit, working tree clean
```

Přesně odpovídá očekávání checkpointu.

## B. Dostupné lokální nástroje (audit před volbou WP cesty)

```text
php:      8.4.19 (CLI) — moduly gd, mysqli, PDO, pdo_mysql, pdo_pgsql,
          pdo_sqlite, sqlite3, zip všechny dostupné
curl/wget: dostupné
composer: dostupný
node:     dostupný (v22), playwright@1.56.1 nainstalován, Chromium
          pre-installed (/opt/pw-browsers)
mysql/mariadb: NENÍ nainstalováno, žádný DB proces neběží
docker:   binary existuje, ale daemon nedostupný
          (`docker ps` → "no such file or directory" na
          /var/run/docker.sock)
```

Podle briefu měl být preferován: (1) standardní WordPress core ve
ephemerním adresáři, (2) lokální DB (SQLite fallback povolen, protože
MySQL chybí), (3) symlink/copy pouze aktuálního theme/plugin kódu z repa.
Body 2–3 byly technicky připravené (SQLite dostupné přes `pdo_sqlite`,
theme/plugin kód je v repu). **Bod 1 se ukázal jako neproveditelný — viz
sekce C.**

## C. Blocker: WordPress core nelze do tohoto prostředí stáhnout

Vyzkoušeny tři nezávislé, legitimní cesty k získání spustitelného
WordPress core (repozitář sám WP core záměrně nedrží — viz `.gitignore`):

1. **`wordpress.org/latest.zip`** (oficiální zdroj) — zamítnuto přímo na
   síťové proxy vrstvě této session:
   ```text
   gateway answered 403 to CONNECT (policy denial)
   host: wordpress.org:443
   ```
2. **GitHub-hosted mirror** (`WordPress/WordPress` repo, i nepřímo přes
   Packagist balíček `johnpbloch/wordpress-core`, jehož `dist` cílí na
   `api.github.com/repos/johnpbloch/wordpress-core/zipball/...`) —
   zamítnuto GitHub přístupovou vrstvou této session, ne sítí:
   ```text
   "GitHub access to this repository is not enabled for this session.
   Use add_repo to request access..."
   ```
   GitHub přístup této session je omezen výhradně na
   `faceariec-dev/Atlas-chuti` (viz Repository Scope tohoto session) —
   `WordPress/WordPress` a `johnpbloch/wordpress-core` jsou nesouvisející
   veřejné repozitáře třetí strany, mimo tento rozsah. `add_repo` je
   určen pro repozitáře propojené s účtem uživatele, ne pro obecné
   stahování cizího veřejného kódu, takže nebyl použit.
3. **WordPress Playground** (`playground.wordpress.net`) — WASM verze
   WordPressu běžící v prohlížeči, která by obešla nutnost stahovat WP
   core přes standardní cestu (prohlížeč by si WP natáhl sám). I tato
   doména je stejným způsobem zamítnuta na proxy vrstvě:
   ```text
   gateway answered 403 to CONNECT (policy denial)
   host: playground.wordpress.net:443
   ```

Všechny tři nálezy jsou zdokumentované síťovou proxy vrstvou tohoto
prostředí (`/root/.ccr/__agentproxy/status`'s `recentRelayFailures`) jako
`connect_rejected` — organizační policy denial, ne přechodná chyba.
Dokumentace proxy k tomu explicitně říká: "do not retry organization
policy denials (403/407) — report them instead." V souladu s tím nebyly
zkoušeny další zrcadlové domény (routing okolo policy by bylo přesně to,
co dokumentace zakazuje).

**Závěr: v tomto konkrétním execution containeru neexistuje ŽÁDNÁ síťová
cesta k získání spustitelného WordPress core.** Nejde o chybějící
techniku (PHP/SQLite/Playwright vše připraveno) ani o nedostatek snahy —
jde o síťovou/přístupovou politiku tohoto konkrétního prostředí, kterou
nelze zevnitř session obejít ani opravit.

Podle briefu (sekce 3, poslední odstavec): "Pokud není možné vytvořit
funkční WP ani tímto způsobem, zastav se a přesně reportuj blocker." —
přesně to tento report dělá.

## D. Co NEBYLO provedeno (protože WP core nešel získat)

- Žádný WordPress core nebyl stažen ani nainstalován.
- Žádná lokální/disposable databáze (SQLite ani jiná) nebyla vytvořena —
  nebylo co k ní připojit.
- Žádný preview server neběžel, žádný port nebyl otevřen.
- Žádná preview fixture data (recept, země, glosář, magazine post,
  discussion topic) nebyla vytvořena — bez běžícího WP by neměla kam jít.
- Žádný screenshot nebyl pořízen (Chromium/Playwright byly technicky
  připravené a funkční, ale neměly co fotografovat).
- Žádný soubor mimo tento report nebyl vytvořen ani upraven.

## E. Regression (spuštěno navzdory blockeru — ověřuje, že kód, který by preview napájel, je zdravý)

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

git diff --stat -- production-data/  → prázdný (Checkpoint 10D integrity)
```

Žádný z těchto testů nezapisuje do žádné WordPress databáze (in-memory
stub environment, stejná konvence jako každý předchozí checkpoint).

## F. Production untouched

```text
SSH na FORPSI proběhlo?                → ne
.github/workflows/deploy.yml spuštěn?  → ne
rsync do produkčního webrootu?         → ne
Produkční DB credentials použity?      → ne (nikdy nebyly k dispozici)
Produkční DB zkopírována?              → ne
DNS změněno?                           → ne
production-data/europe-1/ importováno? → ne
Obrázek importován?                    → ne
```

## G. Next step

Bez zásahu mimo tuto session (kterou nemám prostředky provést sám) zbývají
dvě reálné cesty:

1. **Povolit v egress policy této session alespoň jednu WP core doménu**
   (`wordpress.org`, nebo `playground.wordpress.net`, nebo GitHub scope
   rozšířit o `WordPress/WordPress`) — pak by ephemerní lokální preview z
   tohoto checkpointu šel dokončit přesně podle plánu (PHP + SQLite +
   Playwright screenshoty, žádný production zásah).
2. **Nahrát WordPress core přímo do session jako soubor** (např. ZIP
   uploadem stejným mechanismem, jakým přišly briefy tohoto checkpointu) —
   pak by šlo pokračovat bez jakékoli síťové změny, jen s lokálním
   rozbalením.

Bez jedné z těchto dvou věcí není v tomto konkrétním prostředí možné
vizuální preview vytvořit — ani ephemerní, ani jakýkoli jiný.

---

```text
Produkční web nebyl změněn.
Produkční databáze nebyla změněna.
Production-data/europe-1 nebyla importována.
Nebyl importován žádný receptový obrázek.
Žádná preview fixture data nebyla vytvořena — WordPress core se nepodařilo
do tohoto prostředí získat (síťová/přístupová policy blokuje jediné tři
prověřené legitimní zdroje: wordpress.org, GitHub-hosted mirror,
playground.wordpress.net), takže nevzniklo nic, do čeho by fixtures šly.
```
