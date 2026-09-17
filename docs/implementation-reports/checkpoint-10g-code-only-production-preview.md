# Checkpoint 10G — kontrolovaný code-only preview deploy na FORPSI bez importu obsahu

## A. Starting state

```text
branch: claude/new-session-0gc4ib
HEAD:   f981b4b — Docs: report blocked ephemeral WordPress preview attempt (10F.1)
git status: nothing to commit, working tree clean
```

Přesně odpovídá očekávání checkpointu. Žádný důvod zastavit se na kroku 1.

## B. Noindex verification — BLOCKER, checkpoint se zastavuje zde

Podle briefu (sekce 2): "Než cokoliv nasadíš, ověř aktuální veřejný
response/source... Nevěř pouze slovnímu tvrzení; ověř skutečný output."

Pokus o ověření selhal — ne proto, že produkce NENÍ noindex, ale protože
**tato session nemá k produkčním doménám žádný síťový přístup vůbec**,
ověřeno dvěma nezávislými mechanismy:

1. **`curl` přes session proxy:**
   ```text
   $ curl -sS -i https://atlaschuti.cz/
   curl: (56) CONNECT tunnel failed, response 403
   HTTP/1.1 403 Forbidden

   $ curl -sS -i https://atlaschuti.com/
   curl: (56) CONNECT tunnel failed, response 403
   HTTP/1.1 403 Forbidden
   ```

2. **`WebFetch` tool** (nezávislá cesta, jiný mechanismus než lokální
   proxy):
   ```text
   WebFetch(https://atlaschuti.cz/)
   → {"error_type":"EGRESS_BLOCKED","domain":"atlaschuti.cz",
      "message":"Access to atlaschuti.cz is blocked by the network
      egress proxy."}
   ```

3. **Proxy status log** (`$HTTPS_PROXY/__agentproxy/status` →
   `recentRelayFailures`) potvrzuje obě domény jako `connect_rejected`
   policy denial, stejná kategorie jako dřívější `wordpress.org` a
   `playground.wordpress.net` nález z Checkpointu 10F.1:
   ```text
   { "kind": "connect_rejected", "host": "atlaschuti.cz:443",
     "detail": "gateway answered 403 to CONNECT (policy denial or upstream failure)" }
   { "kind": "connect_rejected", "host": "atlaschuti.com:443",
     "detail": "gateway answered 403 to CONNECT (policy denial or upstream failure)" }
   ```

**Důsledek:** noindex stav produkce nelze z této session ověřit —
ani pozitivně, ani negativně. Brief explicitně vyžaduje ověření
*před* jakýmkoli nasazením a zakazuje spolehnout se na slovní tvrzení.
Nemožnost ověřit není totéž jako úspěšné ověření "je noindex" — je to
selhání gate samo o sobě, a proto se checkpoint zastavuje přesně zde,
**bez jakéhokoli zásahu do produkce**.

**Doplňující zjištění, důležité pro rozhodnutí uživatele:** stejné síťové
omezení by znemožnilo i kroky 7–8 briefu (ověření webu po deployi,
vizuální QA screenshoty) — tato session nemá k `atlaschuti.cz`/`.com`
přístup vůbec, takže **i kdyby k deployi došlo**, nebyl bych z této
session schopen ověřit, že web po nasazení odpovídá bez fatální chyby,
ani pořídit jediný screenshot. Účel checkpointu ("vizuálně zkontrolovat
web") je tedy z této session nedosažitelný nezávisle na tom, zda k
deployi dojde — to je nutné vzít v úvahu při rozhodování o dalším kroku
(sekce N).

## C. Deploy workflow audit (proveden nezávisle na síťovém blockeru — nevyžaduje přístup k produkci)

Znovu otevřen a ověřen `.github/workflows/deploy.yml`:

| Požadavek briefu | Nález |
|---|---|
| Cílí pouze na `wp-content/themes/atlas-chuti/` | Ano — `rsync` target sanity-checked (`case "$target" in */wp-content/themes/atlas-chuti/) ;; *) exit 1 ;; esac`) |
| Cílí pouze na `wp-content/plugins/atlas-chuti-core/` | Ano — stejný sanity-check vzor pro plugin target |
| Nepřenáší WordPress core | Ano — žádný krok mimo tyto dva `rsync` bloky |
| Nepřenáší uploads | Ano — `uploads/` není nikde v workflow zmíněno |
| Nemaže nic mimo přesné targety | `--delete` je scoped na sanity-checked `$target`, ne na `$FORPSI_WEBROOT` samotný |
| Nepouští DB migraci/import | Ano — workflow neobsahuje `wp`, `mysql`, žádný import příkaz |
| Nepouští content/media import | Ano — stejné, žádný importer krok |
| Trigger | `workflow_dispatch: {}` — pouze ruční, žádný `push`/`schedule` |
| Concurrency guard | `deploy-atlas-chuti-forpsi`, `cancel-in-progress: false` — brání souběžným deployům |
| Pre-deploy validace | `validate` job (PHP `php -l` na theme+plugin, JS `node --check`) musí projít, jinak `deploy` job vůbec neběží |

**Závěr auditu: 100% odpovídá briefu.** Workflow samotný je bezpečný a
scoped přesně na theme+plugin. Toto NENÍ důvod pokračovat — blocker je v
sekci B, ne zde.

## D. Backup / rollback point

**Nevytvořen.** Deploy neproběhl (zastaveno na gate B), takže není co
zálohovat/rollbackovat.

## E. Files deployed

Žádné. Deploy nebyl spuštěn.

## F. Content import verification

```text
Recipes imported: 0
Countries imported: 0
Glossary imported: 0
```

Žádný importer nebyl spuštěn — deploy samotný neproběhl.

## G. Media import verification

```text
Images imported: 0
```

## H. DB effects

Žádné. Žádný deploy, žádná aktivace/upgrade pluginu tímto checkpointem.

## I. Live visual QA

Neprovedeno — nemožné z důvodu popsaného v sekci B (žádný síťový přístup
k `atlaschuti.cz`/`.com` z této session, potvrzeno i pro `WebFetch`
nástroj nezávisle na lokální proxy).

## J. Screenshots / URLs

Žádné screenshoty nebyly pořízeny — bez deploye a bez síťového přístupu
k produkci nebylo co fotografovat.

## K. Issues

1. **Blokující:** tato session nemá síťový přístup k `atlaschuti.cz` ani
   `atlaschuti.com` (proxy policy denial, potvrzeno dvěma nezávislými
   mechanismy). Brání to jak povinnému pre-deploy noindex ověření (brief
   sekce 2), tak i post-deploy vizuálnímu ověření (sekce 7–8) — i kdyby
   k deployi došlo, výsledek by nešlo z této session zkontrolovat.
2. Workflow `.github/workflows/deploy.yml` sám o sobě je v pořádku a
   bezpečný (sekce C) — není zdrojem problému.
3. Žádný jiný nález — do kódu ani produkčních dat nebyl proveden žádný
   zásah tímto checkpointem.

## L. Production state after deploy

Beze změny — deploy neproběhl.

```text
git diff --stat -- wp-content/themes/atlas-chuti/ wp-content/plugins/atlas-chuti-core/  → prázdný
git diff --stat -- production-data/                                                     → prázdný
```

## M. Rollback instructions

Není co rollbackovat — produkce nebyla tímto checkpointem změněna.

## N. Next step

Bez zásahu mimo tuto session zbývají dvě reálné cesty k dokončení tohoto
checkpointu:

1. **Povolit v egress policy této session `atlaschuti.cz` a
   `atlaschuti.com`** (analogicky k doporučení z Checkpointu 10F.1 pro
   `wordpress.org`). Pak by šlo dokončit jak pre-deploy noindex ověření,
   tak post-deploy vizuální QA (screenshoty) v rámci téhož checkpointu —
   `.github/workflows/deploy.yml` je již ověřený jako bezpečný (sekce C)
   a deploy samotný by šel spustit ihned poté.
2. **Uživatel provede noindex ověření sám** (otevře `view-source:
   https://atlaschuti.cz/` ve vlastním prohlížeči a potvrdí přítomnost
   `<meta name="robots" content="noindex...">`) **a zároveň přijme, že
   post-deploy vizuální QA z této session nebude možné** — deploy by pak
   šlo spustit (trigger `.github/workflows/deploy.yml` přes
   `workflow_dispatch`), ale vizuální kontrolu výsledku by musel provést
   uživatel sám ve vlastním prohlížeči, ne tato session.

Bez jedné z těchto dvou věcí nelze tento checkpoint bezpečně dokončit —
gate v sekci 2 briefu je explicitní a záměrně tvrdý ("Nevěř pouze
slovnímu tvrzení").

---

```text
Deploy nebyl proveden — checkpoint se zastavil na povinném pre-deploy
noindex ověření (brief sekce 2), protože tato session nemá žádný síťový
přístup k atlaschuti.cz ani atlaschuti.com (potvrzeno curl přes proxy i
nezávislým WebFetch nástrojem, oba vrací explicitní policy denial).
Produkční web nebyl změněn.
Produkční databáze nebyla změněna.
Nebyl importován žádný recipe/country/glossary content.
Nebyl importován žádný obrázek.
WordPress core a uploads nebyly změněny.
Rollback point nebyl vytvořen — nebylo co zálohovat.
```
