# Analisi BTP-PRICES

## 1. Flusso attuale

Gli holding hanno `asset_type` in `['stock','etf','fund','bond','crypto','commodity','cash','other']`
([migration](../../backend/database/migrations/2026_06_13_120000_create_investment_holdings_table.php)),
ma `config/finance.php` → `prices.providers` mappa solo 4 tipi:

```php
'stock' => 'yahoo', 'etf' => 'yahoo', 'fund' => 'yahoo', 'crypto' => 'coingecko',
```

`InvestmentPriceFetcher::symbolsByProvider()` scarta gli holding il cui `asset_type` non è nella
mappa ([riga ~70](../../backend/app/Services/InvestmentPriceFetcher.php)), quindi **un BTP non viene
mai quotato**: `make prices` lo ignora e il valore resta al `last_price` inserito a mano.

Il resto della catena è già agnostico rispetto all'asset:
`InstrumentPrice(symbol, currency, price, as_of)` con unique `(symbol, as_of)`,
`InvestmentPriceResolver::hydrate($holdings, $asOf)` che risolve la quotazione alla data,
e `InvestmentHolding::marketValue() = quantity * effectivePrice()`
([modello, riga 112](../../backend/app/Models/InvestmentHolding.php)).
Serve solo un `PriceProvider` in più.

## 2. Servizi gratuiti valutati

Tutti provati con `curl` il 03/09/2026, non solo letti in documentazione.

| Servizio | Copre i BTP per ISIN? | Esito |
|---|---|---|
| **Borsa Italiana (MOT)** | **Sì** | ✅ unica opzione gratis funzionante — dettaglio sotto |
| Google Finance | No | vecchia API `/finance/info` → **404** (chiusa nel 2012); la pagina `/finance/quote/IT0005534984:BIT` risponde 200 ma "titolo non trovato": i singoli bond italiani non sono coperti |
| Yahoo Finance (già in uso) | No | `IT0003934657.MI` → 404. `.TI` (EuroTLX) risponde 200 ma **il feed è fermo al 2019** (`regularMarketTime` = 28/03/2019, `currency: null`). Inutilizzabile |
| Twelve Data | Solo a pagamento | bonds + filtro ISIN sono add-on, piano da 29 $/mese |
| EODHD | Solo a pagamento | free tier = 20 chiamate/giorno **solo US**; bond per ISIN nei piani a pagamento |
| Financial Modeling Prep | No | nessun endpoint bond per ISIN, solo *Treasury rates* USA |
| Finnhub | No | il bond price API è US corporate (FINRA TRACE) |
| MEF / Banca d'Italia | No | pubblicano curve dei rendimenti, non prezzi per ISIN |
| Teleborsa, SoldiOnline, ADVFN, rendimentibtp.it | Sì ma derivati | riportano **lo stesso dato** di Borsa Italiana (fonte Skipper Informatica): scraping dell'origine è strettamente meglio |

### Borsa Italiana — quello che ho verificato

Un solo template URL, nessuna chiave, nessun rate limit dichiarato:

```
https://www.borsaitaliana.it/borsa/obbligazioni/mot/btp/scheda/{ISIN}.html?lang=it
```

- **Il segmento di categoria è irrilevante**: `/mot/btp/scheda/` funziona anche per un titolo
  che non è un BTP (provato lo stesso ISIN sotto `/mot/bot/scheda/` → identico). Un template
  copre BTP, BOT, CCT, BTP Italia/Valore e i corporate sul MOT.
- Redirect 301 verso `{ISIN}-MOTX.html` → serve `curl -L` / `Http::withOptions(['allow_redirects' => true])`.
- Nella pagina: `Prezzo di riferimento` + `Data di riferimento` (e `Prezzo ufficiale` più preciso),
  `Valuta di Negoziazione` = EUR, più `Rateo Lordo` e i rendimenti lordo/netto.
- ISIN inesistente → **200 con la pagina senza i campi prezzo** (non un 404): il provider
  semplicemente omette il simbolo, che è già il contratto di `PriceProvider::fetch()`.

Campione reale del 03/09/2026 (chiusura 02/09):

| ISIN | Prezzo di riferimento | Prezzo ufficiale |
|---|---|---|
| IT0005534984 | 101,51 | 101,50159 |
| IT0003934657 | 98,68 | — |
| IT0003256820 | 111,11 | 111,0388 |
| IT0001312781 | 95,58 | 95,38179 |
| IT0001464194 | 89,53 | 89,53 |

**Rate limit: nessuno rilevato.** 25 richieste sequenziali senza pausa e 20 in parallelo sullo
stesso ISIN → 25/25 e 20/20 con `200`. La scheda titolo non è in `robots.txt` (l'unica cosa
esclusa sotto `/mot/btp/` è `contratti.html*page=`, l'elenco dei contratti eseguiti).

Limite: **prezzo di chiusura del giorno precedente**, non realtime. Per un tracker di
patrimonio è esattamente la granularità che serve (`instrument_prices` è già EOD, come i
cambi BCE). La lista `/mot/btp/lista.html?lang=it&page=N` è scrapeabile allo stesso modo,
utile se in futuro serve un lookup ISIN → nome tipo `YahooSymbolLookup`.

## 3. Dettaglio dell'implementazione (fatta)

1. **`app/Services/Prices/BorsaItalianaProvider.php`** — implementa `PriceProvider`: 1 GET per
   ISIN, regex su `Prezzo di riferimento` / `Data di riferimento`, conversione del formato
   italiano (`101,51` → `101.51`, `02/09/2026` → `2026-09-02`), valuta EUR fissa.
   Simboli non risolti → omessi. Stessa forma del `YahooFinanceProvider`.
2. **`config/finance.php`** — `'bond' => 'borsaitaliana'` in `prices.providers` +
   blocco `borsaitaliana` con `url` e `timeout` (come gli altri, override da env).
3. **`InvestmentPriceFetcher::provider()`** — un `match` arm in più.
4. **Prezzo diviso 100.** I bond si quotano in percentuale del nominale, mentre
   `marketValue()` fa `quantity * price`. Il provider salva `1.0151` invece di `101.51`:
   così l'utente inserisce `quantity` = **nominale in euro** (5.000) e ottiene 5.075,50 senza
   toccare il modello. Da marcare con un commento `ponytail:` nel provider, perché è
   l'unica cosa non ovvia di tutta la feature.
5. **Un test** (`BorsaItalianaProviderTest`) con `Http::fake()` su un frammento di HTML reale:
   verifica parsing, divisione per 100, data, e che un ISIN senza prezzo venga omesso.

6. **Auto-copia ISIN → symbol** per i bond: hook `saving` sul model, così l'utente compila il
   campo ISIN (quello che conosce) e non deve sapere che l'auto-fetch legge `symbol`.
   Nel model e non nelle Form Request perché in update `asset_type` può non essere nel payload.

Non serve nessuna migration: `symbol` è `varchar(40)`, un ISIN è di 12 caratteri.

## 4. Impatti e possibili regressioni

- **Nessun impatto sugli asset esistenti**: il nuovo provider è raggiungibile solo da
  `asset_type = 'bond'`, che oggi non ha provider. Yahoo e CoinGecko sono intatti.
- `InvestmentPriceFetcher` cattura già i `Throwable` per provider, quindi Borsa Italiana giù
  non blocca il fetch di stock/crypto — ma va verificato che il timeout sia basso, perché è
  una pagina HTML da ~60 KB per ISIN (1 richiesta per titolo, come Yahoo).
- **Holding `bond` con `last_price` inserito a mano**: dal primo fetch riuscito la quotazione
  automatica prende il posto del valore manuale (`InvestmentPriceResolver`). Se il simbolo non
  è un ISIN valido (es. una descrizione) resta il comportamento attuale.
- **Fragilità dello scraping**: un restyling della pagina rompe la regex. Il fallback è
  silenzioso e corretto (simbolo omesso → resta l'ultimo prezzo noto), ma senza un errore
  visibile. Da rivedere se la pagina cambia.
- **Corso secco**: il prezzo è senza rateo di interesse (la pagina espone `Rateo Lordo`
  separatamente). È quello che mostrano i broker nel portafoglio; il valore di liquidazione
  reale è leggermente superiore. Aggiungere il rateo solo se serve la precisione al centesimo.
