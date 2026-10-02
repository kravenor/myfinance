# Analisi — Storico delle quotazioni (ADR 0002, U1)

> Branch `feat/price-history-backfill`. Nessuna migration (la tabella `instrument_prices` ha già `unique(symbol, as_of)`).

## 1. Flusso attuale

- `prices:fetch` (schedulato ogni mattina) → [InvestmentPriceFetcher::fetchLatest](../../backend/app/Services/InvestmentPriceFetcher.php) → `PriceProvider::fetch($symbols)`: **solo l'ultima** quotazione per simbolo, salvata in `instrument_prices` con upsert su `(symbol, as_of)`.
- [InvestmentHistoryService](../../backend/app/Services/InvestmentHistoryService.php) (grafico versato vs valore) e, dopo `fix/net-worth-history`, il patrimonio dei [Report](../../backend/app/Services/ReportService.php) valutano ogni fine mese con "la quotazione più recente `<= fine mese`" e, se non ce n'è, con il **costo medio** di allora (D7).
- Conseguenza: per i mesi precedenti all'attivazione del fetch automatico (giugno 2026) non esistono quotazioni, quindi la curva del valore coincide con il versato e il P/L di quei mesi è sempre zero.

## 2. Modifiche da apportare

1. `Prices\HistoricalPriceProvider` (interfaccia separata, ISP): `history(string $symbol, Carbon $from, Carbon $to): list<quote>`, **un punto per mese** (ultima chiusura del mese).
2. `YahooFinanceProvider` la implementa (endpoint chart con `period1`/`period2`, `interval=1d`, si tiene l'ultimo `close` valido di ogni mese). Borsa Italiana, Teleborsa e CoinGecko no (vedi §3.3).
3. `InvestmentPriceFetcher::backfill($only = [], $force = false)`: per ogni simbolo con provider storico, dal mese del primo movimento a oggi; salta i simboli già coperti (prima quotazione `<=` fine del mese del primo movimento) se non `--force`.
4. Comando `prices:backfill {--symbol=*} {--force}` + schedule giornaliero dopo `prices:fetch`.
5. Test con `Http::fake`; `AGENTS.md` §17, ADR 0002 U1 marcato implementato, target `make prices-backfill`.

## 3. Dettaglio dei fix

### 3.1 Un punto al mese
I consumatori leggono solo "ultima quotazione `<=` fine mese": salvare i ~21 valori giornalieri di ogni mese non cambia nessun numero e moltiplica le righe. Si salva l'ultimo `close` non nullo di ogni mese con la sua data reale (ultimo giorno di borsa). Per il mese in corso l'ultimo punto è la chiusura più recente, che il fetch giornaliero poi aggiorna.

### 3.2 Yahoo
- `GET /v8/finance/chart/{symbol}?period1={from}&period2={to}&interval=1d` (verificato live su `CSSPX.MI`: `timestamp[]`, `indicators.quote[0].close[]` con possibili `null`, `meta.currency`, `meta.gmtoffset`).
- Data del punto = `timestamp + gmtoffset` (orario di apertura della borsa, quindi il giorno è stabile anche a cavallo dell'ora legale).
- `close` e non `adjclose`: è il prezzo scambiato, coerente con `regularMarketPrice` del fetch giornaliero.
- Stesso limite già noto: `GBp` (pence) non convertito.

### 3.3 Provider senza storico
- **Borsa Italiana / Teleborsa** (BTP, certificati): scraping della scheda corrente, lo storico non è esposto allo stesso modo → per questi strumenti la curva continua a ripiegare sul costo medio prima del fetch (come già scritto in U1).
- **CoinGecko**: lo storico gratuito copre al più 365 giorni e oggi non ci sono crypto nel portafoglio → non implementato (YAGNI); si aggiunge implementando l'interfaccia.

### 3.4 Solo i buchi, quindi schedulabile
- Primo movimento per simbolo: `MIN(occurred_at)` sugli `investment_transactions` di tutti gli holding con quel simbolo (senza scope utente, come `fetchLatest`).
- Coperto se esiste una quotazione con `as_of <= fine del mese del primo movimento`: nessuna richiesta HTTP. Così lo schedule giornaliero costa una query per simbolo e scarica solo quando compare un holding nuovo o un PAC inserito a posteriori.
- `--force` riscarica comunque (es. dopo una correzione dei dati del provider).

## 4. Impatti e possibili regressioni

Branch di riferimento: `master`.

- **Grafico versato vs valore e patrimonio dei Report**: i mesi passati passano dal costo medio alla quotazione reale; il P/L storico smette di essere zero. È l'effetto voluto.
- **XIRR**: invariato: i flussi vengono dal registro e il valore finale è quello di oggi, dal fetch giornaliero.
- **Volume dati**: ~12 righe/anno per simbolo.
- **Rete**: una richiesta per simbolo non coperto; un errore su un simbolo non blocca gli altri (come `fetchLatest`). Licenza Yahoo: uso personale (ADR 0001), invariato.
- **Upsert**: un punto storico nello stesso giorno di una quotazione giornaliera la sovrascrive con lo stesso prezzo di chiusura.
