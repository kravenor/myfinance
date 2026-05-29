# Finance

Web application personale per la gestione delle finanze: conti, transazioni, categorie,
budget mensili, transazioni ricorrenti, dashboard con report/grafici e import/export.
Stack: **Laravel 11 + Vue 3 (TS) + MySQL 8 + Redis + Docker**.

## Prerequisiti

- Docker Desktop (o Docker Engine + Compose v2)
- `make`

## Quickstart (post-clone)

```bash
git clone <repo>
cd Finance
make bootstrap
```

`make bootstrap` esegue tutto il setup (copia `.env`, build immagini, `up`, `composer install`,
`key:generate`, `migrate --seed`) e stampa URL e credenziali demo. A fine procedura
l'app è su [http://localhost:8080](http://localhost:8080).

Credenziali demo: **`demo@finance.local`** / **`password`**.

> macOS: dopo il primo bootstrap allinea UID/GID in `.env` (`UID=$(id -u) GID=$(id -g)`) e `make build`.
> Dettagli ed eventuale troubleshooting in [`AGENTS.md`](AGENTS.md) §4.1.

## Comandi utili

```bash
make up / down / restart      # gestione stack
make migrate / fresh / seed   # database
make test                     # PHPUnit
make check                    # pint + stan + test + lint + type-check
```

`make help` per la lista completa.

## Funzionalità principali

- CRUD conti, categorie, tag, transazioni (con transfer), budget e ricorrenti.
- Dashboard e report (`/reports`, `/stats`): KPI, grafici, confronto periodi, trend, forecast cash-flow.
- Import/Export: export CSV; **import CSV, OFX e QIF** con preview, mapping e categoria suggerita.
- Auto-categorizzazione: regole pattern→categoria applicate in import e **retroattivamente** alle transazioni esistenti.

## Documentazione

- **[`AGENTS.md`](AGENTS.md)** — mappa del progetto (stack, struttura, endpoint, convenzioni).
- **[`CLAUDE.md`](CLAUDE.md)** — istruzioni specifiche per Claude Code.
- **`docs/`** — ADR e documenti di design.

## Stato

Tutte le fasi della roadmap (1–9) e le estensioni (statistiche avanzate,
auto-categorizzazione, import OFX/QIF, applicazione retroattiva regole) sono **completate**.
Vedi [`AGENTS.md`](AGENTS.md) §7 per il dettaglio.
