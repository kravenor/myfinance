# 1. Ordine di sviluppo: auto-fetch prezzi prima del multitenant

- **Stato:** Accettato
- **Data:** 2026-06-27
- **Contesto correlato:** [Analisi auto-fetch prezzi](../analysis/INVESTMENT_PRICE_AUTOFETCH_ANALYSIS.md), [Analisi multitenant](../analysis/MULTITENANT_ANALYSIS.md)

## Contesto

Sono in valutazione due estensioni indipendenti:

1. **Auto-fetch prezzi investimenti** — recupero automatico delle quotazioni via `symbol` (oggi manuali).
2. **Multitenant** — passaggio da scoping per-utente (`user_id`) a workspace condivisi (`tenant_id`).

Esiste una **tensione di licenza** tra le due (reg.11 dell'analisi auto-fetch): i provider di quotazioni gratuiti — **Yahoo Finance** (stock/etf/fund) e **CoinGecko** (crypto) — sono **non ufficiali/free per uso personale, non-commerciale, senza ridistribuzione**. Un'app **multitenant** (multi-utente) fa scattare l'uso commerciale → il free tier/ToS personale decade.

> **Aggiornamento (2026-06-27):** il provider stock/etf/fund inizialmente scelto (EODHD, D2) è stato sostituito da **Yahoo Finance** in fase di implementazione (il free tier EODHD copriva solo i mercati USA — vedi errata in [analisi §3.8](../analysis/INVESTMENT_PRICE_AUTOFETCH_ANALYSIS.md)). La sostanza di questo ADR non cambia: il provider resta pluggable via config e la tensione di licenza vale identica per Yahoo.

La domanda è: in quale ordine affrontarle, dato il conflitto?

## Decisione

**Implementare prima l'auto-fetch prezzi, sul free tier. Trattare il multitenant come progetto separato, da decidere sui propri meriti.**

Razionale:

- **Disaccoppiamento tecnico.** Il design dell'auto-fetch (opzione B: tabella globale `instrument_prices`, fetcher con `withoutGlobalScopes`) è già tenant-agnostico: i prezzi sono un fatto globale e il fetcher resta globale sia con scoping `user_id` sia `tenant_id`. Costruire l'auto-fetch prima **non genera codice da rifare** quando/se il multitenant arriva.
- **Il conflitto è commerciale, non di codice.** L'unico effetto del multitenant sull'auto-fetch è il decadimento del free tier/ToS personale. Poiché il provider è **pluggable e guidato da config**, passare a un provider con licenza commerciale (es. EODHD EOD All World ~$20/mese, o l'API ufficiale a pagamento equivalente) è un **cambio di config/provider**, non una riscrittura.
- **Proporzioni.** L'auto-fetch è un lavoro piccolo, isolato e utile da subito; il multitenant è un rewrite ampio e rischioso (12 tabelle, swap dello scope, adeguamento di tutti i test, fan-out notifiche). Il costo dei dati a pagamento (~$20/mese) è trascurabile rispetto all'ingegneria del multitenant.

Ordine **da evitare**: multitenant prima, auto-fetch dopo — si farebbe il rewrite grosso prima del feature piccolo e si pagherebbero i dati da subito senza mai sfruttare il free tier, senza alcun vantaggio.

## Conseguenze

- L'auto-fetch viene sviluppato sul free tier (uso personale, single-tenant attuale).
- Il percorso di upgrade è documentato nella config [config/finance.php](../../backend/config/finance.php) (sezione `prices`, nota su uso personale/non commerciale + rimando a questo ADR).
- Se in futuro si procede col multitenant: prima di andare multi-utente, commutare verso un provider con licenza commerciale (config) e rivedere i vincoli di ridistribuzione/cancellazione dati.
- Le decisioni aperte residue dell'auto-fetch (D1, D3, D4, D5) restano da chiudere prima della fase P1.
