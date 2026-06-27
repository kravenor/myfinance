# 1. Ordine di sviluppo: auto-fetch prezzi prima del multitenant

- **Stato:** Accettato
- **Data:** 2026-06-27
- **Contesto correlato:** [Analisi auto-fetch prezzi](../analysis/INVESTMENT_PRICE_AUTOFETCH_ANALYSIS.md), [Analisi multitenant](../analysis/MULTITENANT_ANALYSIS.md)

## Contesto

Sono in valutazione due estensioni indipendenti:

1. **Auto-fetch prezzi investimenti** — recupero automatico delle quotazioni via `symbol` (oggi manuali).
2. **Multitenant** — passaggio da scoping per-utente (`user_id`) a workspace condivisi (`tenant_id`).

Esiste una **tensione di licenza** tra le due (reg.11 dell'analisi auto-fetch): i provider di quotazioni gratuiti scelti per D2 — **EODHD** (stock/etf/fund) e **CoinGecko** (crypto) — hanno free tier **solo per uso personale, non-commerciale, senza ridistribuzione**, con obbligo di cancellazione dei dati alla cessazione. Un'app **multitenant** (multi-utente) fa scattare l'uso commerciale → il free tier decade.

La domanda è: in quale ordine affrontarle, dato il conflitto?

## Decisione

**Implementare prima l'auto-fetch prezzi, sul free tier. Trattare il multitenant come progetto separato, da decidere sui propri meriti.**

Razionale:

- **Disaccoppiamento tecnico.** Il design dell'auto-fetch (opzione B: tabella globale `instrument_prices`, fetcher con `withoutGlobalScopes`) è già tenant-agnostico: i prezzi sono un fatto globale e il fetcher resta globale sia con scoping `user_id` sia `tenant_id`. Costruire l'auto-fetch prima **non genera codice da rifare** quando/se il multitenant arriva.
- **Il conflitto è commerciale, non di codice.** L'unico effetto del multitenant sull'auto-fetch è il decadimento del free tier. Poiché il provider è **pluggable e guidato da config**, passare al piano a pagamento (EODHD EOD All World, ~$20/mese) è un **cambio in `.env`**, non una riscrittura.
- **Proporzioni.** L'auto-fetch è un lavoro piccolo, isolato e utile da subito; il multitenant è un rewrite ampio e rischioso (12 tabelle, swap dello scope, adeguamento di tutti i test, fan-out notifiche). Il costo dei dati a pagamento (~$20/mese) è trascurabile rispetto all'ingegneria del multitenant.

Ordine **da evitare**: multitenant prima, auto-fetch dopo — si farebbe il rewrite grosso prima del feature piccolo e si pagherebbero i dati da subito senza mai sfruttare il free tier, senza alcun vantaggio.

## Conseguenze

- L'auto-fetch viene sviluppato sul free tier (uso personale, single-tenant attuale).
- Lasciare un marcatore esplicito del percorso di upgrade nel codice del provider:
  `// ponytail: free tier EODHD (uso personale); passare a EOD All World (~$20/mo) se l'app diventa multi-utente`.
- Se in futuro si procede col multitenant: prima di andare multi-utente, commutare EODHD/CoinGecko sui piani a pagamento (config) e rivedere i vincoli di ridistribuzione/cancellazione dati.
- Le decisioni aperte residue dell'auto-fetch (D1, D3, D4, D5) restano da chiudere prima della fase P1.
