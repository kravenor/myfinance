# Analisi — Patrimonio netto storico con la posizione dell'epoca

> Branch `fix/net-worth-history`. Nessuna migration, nessun cambio frontend.

## 1. Flusso attuale

- `GET /reports/net-worth` calcola il patrimonio a fine di ogni mese con [ReportService::cumulativeBalance](../../backend/app/Services/ReportService.php) → `rawAccountBalances($upTo)`: per i conti `investment` il saldo è `investmentMarketValues($upTo)`.
- `investmentMarketValues` idrata le holding con la quotazione `<= $upTo` ma moltiplica per `$holding->quantity`, la cache **di oggi**, con ripiego su `last_price`/`avg_cost` **di oggi**.
- Effetto: un PAC da 10 quote a gennaio e 20 a marzo vale 20 quote anche a gennaio e febbraio; e il valore dei mesi passati non coincide con il grafico versato/valore di [InvestmentHistoryService](../../backend/app/Services/InvestmentHistoryService.php), che invece ricostruisce la quantità dal registro e ripiega sul costo medio di quel momento (ADR 0002 D7).

## 2. Modifiche da apportare

1. `HoldingPositionRecalculator::positionAt($movements, ?$upTo, $strict)`: posizione (quantità, costo, realizzato, versato) fino a una data; `recalculate()` la usa con `strict` (eccezione sulle vendite oltre il posseduto), così la regola del costo medio resta in un solo punto.
2. `ReportService::investmentMarketValues`: per `$upTo` passato quantità e costo da `positionAt`, prezzo = quotazione `<= $upTo` o costo medio di allora; per oggi e futuro invariato. Movimenti caricati una sola volta per richiesta (`movementsByHolding`).
3. Holding senza movimenti: calcolo invariato (quantità corrente), per non azzerare dati non migrati al registro.
4. `NetWorthHistoryTest`; `AGENTS.md` §17.

## 3. Dettaglio dei fix

- "Passato" = `$upTo < oggi`. L'ultimo punto della serie (fine del mese finanziario corrente, nel futuro) e `summary` sul mese in corso restano sul calcolo attuale, coerenti con overview e Dashboard.
- `last_price` (prezzo manuale) non è usato nel passato: è un prezzo di oggi (ADR 0002 D7).
- Costo query: prima `netWorth` su 12 mesi faceva per ogni mese holding + quotazioni; ora in più **una** query sui movimenti per l'intera richiesta (memo nel service, che vive per la richiesta).

## 4. Impatti e possibili regressioni

Branch di riferimento: `master`.

- **Report → Andamento, Patrimonio netto**: i mesi passati cambiano per chi ha acquistato o venduto nel periodo (è la correzione). Mese corrente, Dashboard e overview invariati.
- **Previsioni / statistiche** che partono da `cumulativeBalance` di fine mese scorso: ora usano la posizione reale di quel giorno (differenza solo se ci sono movimenti nel mese in corso).
- **Ricalcolo posizione**: refactor senza cambi di comportamento (test investimenti e ricorrenti PAC invariati).
