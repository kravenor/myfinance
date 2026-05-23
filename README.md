# Finance

Web application personale per la gestione delle finanze.
Stack: **Laravel 11 + Vue 3 + MySQL 8 + Docker**.

## Prerequisiti

- Docker Desktop (o Docker Engine + Compose v2)
- `make`

## Quickstart

```bash
cp .env.example .env
make up
```

App disponibile su [http://localhost:8080](http://localhost:8080).

### Bootstrap iniziale (una tantum)

Quando `backend/` e `frontend/` sono vuoti (Fase 1 attuale):

```bash
make laravel-new   # crea progetto Laravel in backend/
make vue-new       # crea progetto Vue in frontend/
make composer-install
make migrate
```

## Comandi utili

`make help` per la lista completa.

## Documentazione

- **`AGENTS.md`** — mappa del progetto per agenti AI (e per umani che vogliono il quadro completo).
- **`CLAUDE.md`** — istruzioni specifiche per Claude Code.
- **`docs/`** — ADR e documenti di design.

## Stato

Fase corrente: **Fase 1 — Setup infrastruttura ✓**
Prossima: Fase 2 — Backend foundation (bootstrap Laravel, migrazioni base).
