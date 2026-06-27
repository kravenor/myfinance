# Analisi — Auto-fetch prezzi investimenti (sezione 17)

> Obiettivo: recuperare automaticamente il **prezzo corrente** delle holding via `symbol`, oggi inserito a mano. L'architettura è dichiarata "pronta" ([InvestmentHolding.php:13-14](../../backend/app/Models/InvestmentHolding.php)) ma non implementata.
> Branch di base: `master`. Documento di sola analisi — **nessuna implementazione**.
> **Fuori scope** (item distinto, sez. 17 di AGENTS.md): lo *storico prezzi* per il net worth storico. Vedi §4 nota CRITICA.

---

## 1. Flusso attuale

**Modello prezzo** ([InvestmentHolding.php](../../backend/app/Models/InvestmentHolding.php)):
- `symbol` string(40) **nullable** (migration L16, model L20, fillable L38). Presente in model/resource/request/factory e basta: **nessuna logica di quotazione lo usa** (grep `symbol`, `fetch`, `quote` su `backend/app` → zero match in servizi/command/job).
- `last_price` decimal(24,8) nullable (migration L23, cast L53) — aggiornato **solo manualmente**.
- `last_price_at` timestamp nullable (migration L24, cast L54).
- `currency` string(3) default `EUR` (migration L19) — valuta dell'asset.
- `asset_type` enum `stock/etf/fund/bond/crypto/commodity/cash/other` (migration L17-18).
- `effectivePrice()` (L62-65) = `last_price ?? avg_cost` → se manca il prezzo, **ripiega silenziosamente sul costo** (parità, P/L = 0).
- `marketValue()` (L67-70) = `quantity × effectivePrice()` — **nessuna conversione di valuta** qui dentro.

**Consumatori del prezzo** (la conversione valuta avviene *a valle*):
- [InvestmentService::overview](../../backend/app/Services/InvestmentService.php) (L20-88): `convert(h->marketValue(), h->currency, base, now)` (L34-36) — assume `marketValue()` già nella valuta dell'holding, converte alla base **al tasso di oggi**.
- [ReportService::investmentMarketValues](../../backend/app/Services/ReportService.php) (L481-498): `convert(h->marketValue(), h->currency, accountCurrency, $upTo)` (L494). I conti `investment` non hanno saldo transazionale: il loro saldo **è** il market value (rawAccountBalances L465-466).
- [InvestmentHoldingResource](../../backend/app/Http/Resources/InvestmentHoldingResource.php): espone `last_price` (L32) e i calcolati cost_basis/market_value/unrealized_pl (L19-21, 37-39).

**Frontend** ([InvestmentsView.vue](../../frontend/src/views/InvestmentsView.vue)): input manuale `last_price` (L215, label "Prezzo corrente ({{ currency }})"), `onSubmit` setta `last_price_at = new Date().toISOString()` **solo** se il prezzo non è vuoto (L88); display ripiega su `avg_cost` (L254). Tipo in [types/api.ts](../../frontend/src/types/api.ts) L175-193.

**Pattern di fetch esterno già esistente** (il template naturale da rispecchiare): tassi di cambio.
- [ExchangeRateProvider](../../backend/app/Services/ExchangeRateProvider.php): `fetchLatest` (L24-29), `fetchForDate` (L35-40), `fetchRange` (L46-56), `store` upsert su unique `(date, currency)` (L61-89), `request` con `Http::timeout(15s)` e `RuntimeException` su HTTP ≠ 2xx (L94-111).
- [FetchExchangeRates](../../backend/app/Console/Commands/FetchExchangeRates.php): comando `exchange-rates:fetch [--backfill] [--from=] [--to=]` (L12-15), try/catch + log, ritorna SUCCESS/FAILURE (L19-39).
- Schedule: `dailyAt('06:00')` in [routes/console.php:15](../../backend/routes/console.php).
- Config in [config/finance.php](../../backend/config/finance.php) L21-25 (`provider_url` Frankfurter **keyless**, `history_start`, `timeout`).
- Dato **globale**: [`exchange_rates`](../../backend/app/Models/ExchangeRate.php) non ha `user_id` né global scope.

**Scoping holding**: `InvestmentHolding` usa [BelongsToUser](../../backend/app/Models/Concerns/BelongsToUser.php) (L32) → ogni query è filtrata per `user_id` ([UserScope](../../backend/app/Models/Scopes/UserScope.php)). Lo **stesso symbol** (es. `VWCE`) compare nelle holding di più utenti.

---

## 2. Modifiche da apportare

Decisione centrale (§3.1): il prezzo di un titolo è un **fatto globale per `symbol`**, esattamente come un tasso di cambio. Quindi lo si modella come l'`exchange_rates`, **non** scrivendolo dentro ogni holding.

1. **Nuova tabella globale `instrument_prices`** (`symbol`, `currency`, `price`, `as_of`), unique `(symbol, as_of)` — niente `user_id` (come `exchange_rates`).
2. **Provider pluggable per `asset_type`**: interfaccia `PriceProvider` + factory (sullo stampo di `ImportReader`/`ImportReaderFactory`), con almeno un provider stock/ETF e uno crypto.
3. **Servizio `InvestmentPriceFetcher`**: raccoglie i `symbol` **distinti su tutte le holding** (bypass del global scope) e fa upsert in `instrument_prices`.
4. **Comando + schedule**: `prices:fetch` schedulato giornaliero (es. 06:30, dopo i cambi).
5. **Lettura del prezzo**: `effectivePrice()` legge l'ultima quota da `instrument_prices` per il symbol (con **conversione dalla valuta della quota a quella dell'holding**), poi ripiega su `last_price` manuale, poi `avg_cost`.
6. **Gestione valuta della quota** (obbligatoria, §3.5): la quota ha la sua valuta; va convertita, non assunta uguale a quella dell'holding.
7. **Config**: sezione `finance.prices` (provider per asset_type, eventuali api_key da `.env`, rate-limit, timeout).
8. **Indice** su `investment_holdings.symbol` per la raccolta dei distinti.
9. **Frontend**: badge "prezzo automatico/aggiornato il…", il campo manuale diventa override opzionale.
10. **Test**: fetcher (con HTTP fake), conversione valuta quota→holding, fallback a manuale/costo.

---

## 3. Dettaglio dei fix

### 3.1 Decisione di modello — DOVE vive il prezzo

| Opzione | Cosa fa | Pro | Contro |
|---------|---------|-----|--------|
| **A — scrivere su `holding.last_price`** | il fetch aggiorna `last_price`/`last_price_at` di ogni holding con quel symbol | zero nuove tabelle, riusa il flusso attuale | **sovrascrive il prezzo manuale**; duplica la stessa quota su N holding/utenti; conflagra "quota di mercato" e "valore inserito"; non abilita lo storico |
| **B — tabella globale `instrument_prices`** *(raccomandata)* | quota per `(symbol, as_of)` globale; le holding la leggono per symbol | separa quota da holding; **non tocca il manuale**; una sola fetch per symbol; con `(symbol, as_of)` accumula **storico** naturale (abilita l'altro item); stesso pattern collaudato di `exchange_rates` | una tabella + un punto di lettura nuovo in `effectivePrice()` |

Raccomando **B**. È la scelta corretta *e* coerente con `exchange_rates`; il piccolo costo extra ripaga subito (non sovrascrive i prezzi inseriti a mano, prepara lo storico). → ADR.

### 3.2 Schema `instrument_prices` (opzione B)
- `id`, `symbol` (string, indicizzato), `currency` (3, valuta della quota), `price` decimal(24,8), `as_of` date, timestamps. Unique `(symbol, as_of)`. **No `user_id`**, **no global scope** (replica esatta del contratto `exchange_rates`).
- (Opzionale) `asset_type`/`source` se lo stesso ticker può collidere tra mercati diversi.
- **Formato `symbol`**: per EODHD si usa `TICKER.EXCHANGE` (es. `VWCE.XETRA`, `AAPL.US`). Gli ETF UCITS europei vanno indirizzati su **Xetra (`.XETRA`)**, **non** su Borsa Italiana (`.MI` non è coperto, vedi §3.8). Per crypto, CoinGecko usa lo *slug* (`bitcoin`), non il ticker → serve una mappatura ticker→id (endpoint `/coins/list`). Implica una validazione/normalizzazione del campo `symbol` per `asset_type`.

### 3.3 Provider pluggable
- `App\Services\Pricing\PriceProvider` (interfaccia): `supports(string $assetType): bool`, `fetch(array $symbols): array` (→ `[symbol => ['price','currency','as_of']]`).
- `PriceProviderFactory` per `asset_type` (come [ImportReaderFactory](../../backend/app/Services/Import/ImportReaderFactory.php)).
- Fonti scelte (D2, vedi §3.8): **`EodhdProvider`** per `stock/etf/fund`, **`CoinGeckoProvider`** per `crypto`. `bond/commodity`: opzionali, ripiegano sul manuale.

### 3.4 Fetcher + comando + schedule
- `InvestmentPriceFetcher`: `InvestmentHolding::withoutGlobalScopes()->whereNotNull('symbol')->distinct()->pluck('symbol', ... )` raggruppati per `asset_type` → chiama il provider giusto → upsert in `instrument_prices`. Errore su un symbol/provider **non** blocca gli altri (a differenza dell'attuale `RuntimeException` che ferma tutto — vedi §4 reg. 4).
- Comando `php artisan prices:fetch [--symbol=] [--backfill] [--from=] [--to=]`, try/catch + log, come [FetchExchangeRates](../../backend/app/Console/Commands/FetchExchangeRates.php).
- Schedule giornaliero in [routes/console.php](../../backend/routes/console.php) (es. `dailyAt('06:30')`).

### 3.5 Lettura prezzo + conversione valuta (OBBLIGATORIA)
Oggi `marketValue() = quantity × (last_price ?? avg_cost)` **assume che il prezzo sia nella valuta dell'holding**, e i consumatori convertono da `h->currency`. Una quota presa da un provider è invece nella **valuta nativa del titolo** (es. AAPL → USD).

Fix: `effectivePrice()` deve restituire un prezzo **nella valuta dell'holding**, convertendo la quota:
```
prezzoQuota(symbol) in quoteCurrency
→ convert(prezzoQuota, quoteCurrency, holding.currency, as_of)   // via CurrencyConverter esistente
→ fallback: last_price (manuale, già in holding.currency) → avg_cost
```
Così `marketValue()` e i consumatori a valle restano invariati (continuano a convertire da `h->currency` a base). Senza questo passaggio l'auto-fetch **corrompe silenziosamente** il valore di mercato (vedi §4 reg. 1).

### 3.6 Config (`config/finance.php`)
Nuova sezione `prices`: `providers` per asset_type, `api_key` (da `.env`, fuori dal commit), `rate_limit`, `timeout`. Nessuna chiave hardcoded. [config/services.php](../../backend/config/services.php) oggi ha solo mail/slack.

### 3.7 Frontend
- Mostrare fonte/data quota (badge "auto · aggiornato il {as_of}") leggendo i calcolati dal resource.
- Il campo `last_price` resta come **override manuale** opzionale (la precedenza fallback è definita in §3.5).

### 3.8 Scelta provider (D2 — CHIUSA)

Ricerca comparata verificata contro i doc ufficiali (giugno 2026). Caso d'uso: portafoglio personale con **ETF UCITS europei** (es. VWCE), pochi simboli, solo **EOD una volta al giorno**, prezzi **salvati in DB**, preferenza free tier.

**Vincolo dirimente — copertura ETF UCITS europei nel free tier**: quasi tutti i provider gratuiti sono **US-only**. Un solo free tier copre davvero gli UCITS europei: **EODHD** (verificato live: `VWCE.XETRA` quotato).

| Provider | Free tier | ETF UCITS EU (free) | Storage in DB | Verdetto |
|----------|-----------|---------------------|---------------|----------|
| **EODHD** | 20 call/g, 1000/min, storico **solo 1 anno** | ✅ **sì, via `.XETRA`** | ✅ uso personale (cancellare entro 1 mese da disdetta) | **scelto** |
| Twelve Data | 800/g, 8/min | ❌ Xetra=`Grow+`, Milano=`Pro+` | ⚠️ ok personale, cache non quantificata | scartato (free) |
| FMP | 250/g, storico 5 anni | ❌ free = solo US | ⚠️ cancellare a disdetta | scartato |
| Alpha Vantage | **25/g** (ridotto da 500) | ❓ incerto (solo azioni; Xetra=`.DEX`) | ✅ nessun divieto esplicito | scartato |
| Finnhub | 60/min | ❌ EU premium; EOD candle ora premium anche US | ⚠️ cancellare a fine abbon. | scartato |
| Tiingo | 1000/g (non da doc ufficiali) | ❌ solo US+CN | ✅ "internal use" | scartato |
| Marketstack | 100 **o** 1000/mese (doc ufficiali contraddittori) | ❓ non confermato | ⚠️ no commercial sul free | scartato |
| **CoinGecko** (crypto) | 10k/mese, 100/min | — solo crypto | ⚠️ refresh cache ≤24h + attribuzione "Powered by CoinGecko" | **scelto (crypto)** |
| CoinPaprika (crypto) | 20k/mese, keyless | — solo crypto | ✅ termini storage più permissivi | alternativa crypto |

**Decisione:**
- **stock/etf/fund → EODHD (free)**, endpoint `GET https://eodhd.com/api/eod/{SYM}.{EXCH}?api_token=…&fmt=json`. Ticker **`.XETRA`/`.F`**, **non `.MI`**. Limiti free: ~20 simboli/giorno, storico 1 anno → se i simboli superano ~20 o serve storico completo, upgrade naturale **EOD All World ~$19,99/mese** (100k call/giorno).
- **crypto → CoinGecko Demo** (key gratuita), `GET /api/v3/simple/price?ids=…&vs_currencies=eur` schedulato a fine giornata. Alternativa **CoinPaprika** se la licenza di storage diventa un vincolo forte.

**Caveat di licenza (impatta il multitenant)**: tutti questi free tier sono **uso personale, non-commerciale, niente ridistribuzione**, e diversi (EODHD, FMP, Finnhub) impongono la **cancellazione dei dati alla cessazione**. Se l'app diventa **multitenant** ([analisi dedicata](MULTITENANT_ANALYSIS.md)), il free tier EODHD **decade** (uso commerciale/multi-utente) → servirebbe licenza a pagamento. Vedi §4 reg. 11.

---

## 4. Impatti e possibili regressioni

Analisi rispetto al branch di base **`master`**.

### File impattati
| Area | File | Intervento |
|------|------|-----------|
| Schema | nuova migration `instrument_prices` + indice su `investment_holdings.symbol` | nuove |
| Lettura prezzo | [InvestmentHolding.php:62-70](../../backend/app/Models/InvestmentHolding.php) (`effectivePrice`/`marketValue`) | conversione quota→holding currency |
| Provider | nuovi `Pricing/PriceProvider` + factory + impl | nuovi |
| Fetch | nuovo `InvestmentPriceFetcher` + comando `prices:fetch` + schedule | nuovi |
| Config | [config/finance.php](../../backend/config/finance.php), `.env(.example)` | sezione `prices` |
| Consumatori | [InvestmentService](../../backend/app/Services/InvestmentService.php), [ReportService](../../backend/app/Services/ReportService.php) | verifica (dovrebbero restare invariati se §3.5 è corretto) |
| Resource/UI | [InvestmentHoldingResource](../../backend/app/Http/Resources/InvestmentHoldingResource.php), [InvestmentsView.vue](../../frontend/src/views/InvestmentsView.vue), [types/api.ts](../../frontend/src/types/api.ts) | badge fonte/data |

### Regressioni da verificare
1. **Currency mismatch (MEDIO, verificato)** — se la quota è in valuta diversa dall'holding e non si converte (§3.5), `marketValue` moltiplica quantità×prezzo in valute diverse e i consumatori applicano una **seconda** conversione: P/L e patrimonio gonfiati/deflazionati (≈1.10x nel caso USD/EUR), **in silenzio**. È il rischio n.1: la conversione in §3.5 è la mitigazione.
2. **Net worth storico (CRITICO, verificato) — fuori scope ma da dichiarare** — [ReportService::netWorth](../../backend/app/Services/ReportService.php) (L388-400) calcola ogni mese passato con il prezzo **corrente** (legge la riga holding di oggi). L'auto-fetch aggiorna la quota *corrente*: **non** ricostruisce lo storico. Con l'opzione B la tabella `(symbol, as_of)` *accumula* le quote nel tempo, ma cablare il net worth storico a leggere la quota alla data è **l'altro item** ("storico prezzi"), non incluso qui. Da non promettere come risolto.
3. **Sovrascrittura del prezzo manuale** — evitata dall'opzione B (la quota auto sta in tabella separata; `last_price` manuale resta override). Con l'opzione A invece va gestita una precedenza esplicita o si perde l'inserimento manuale.
4. **Fetch fragile / parziale** — il pattern attuale lancia `RuntimeException` e **ferma tutto** al primo errore HTTP ([ExchangeRateProvider:94-111](../../backend/app/Services/ExchangeRateProvider.php)). Con N symbol e API azionarie soggette a rate-limit/timeout, un fallback per-symbol (isola il singolo errore) è necessario, altrimenti un titolo rotto azzera l'intero refresh.
5. **Scoping / leak** — la raccolta globale dei symbol richiede `withoutGlobalScopes()` (o query grezza): va circoscritta al solo fetcher e mai esposta su path utente. Si lega all'analisi [multitenant](MULTITENANT_ANALYSIS.md): se lo scoping passerà a `tenant_id`, il fetcher resta comunque **globale** (i prezzi non sono per-tenant) — anzi B invecchia meglio.
6. **API key & segreti** — chiavi solo in `.env`, mai nel repo; documentare in `.env.example` e AGENTS.md sez. 2/6.
7. **`symbol` non normalizzato** — il formato dipende dal provider scelto (§3.8): EODHD vuole `TICKER.EXCHANGE` con gli UCITS europei su **`.XETRA`** (NON `.MI`, che dà 404), CoinGecko vuole lo *slug* non il ticker. Stesso titolo scritto in modi diversi → quote mancanti o duplicate. Serve normalizzazione/validazione del campo `symbol` per `asset_type` (e migrazione dei symbol già inseriti a mano).
8. **Test (PHPUnit, SQLite in-memory)** — aggiungere: fetcher con `Http::fake`, conversione quota→holding, catena fallback (quota→manuale→costo). Verificare che i test investimenti esistenti ([InvestmentHoldingTest](../../backend/tests)) restino verdi dopo il cambio di `effectivePrice()`.
9. **Larastan livello 5** — annotare i nuovi servizi/model; il nuovo accesso a `instrument_prices` in `effectivePrice()` introduce una dipendenza (preferire un service iniettato a una query nel model, per testabilità).
10. **Performance** — indice su `symbol` (§3.2) per i distinti; cache per-richiesta della quota più recente per symbol nei report (come fa già [CurrencyConverter](../../backend/app/Services/CurrencyConverter.php)).
11. **Licenza dati & multitenant (verificato)** — i free tier scelti (EODHD, CoinGecko) sono **uso personale, non-commerciale, no ridistribuzione**; EODHD impone la **cancellazione dei dati entro 1 mese dalla disdetta** e CoinGecko il **refresh cache ≤24h** + attribuzione obbligatoria. Conseguenze concrete: (a) `instrument_prices` è uno storico legato all'abbonamento, non un archivio perpetuo "di proprietà"; (b) il passaggio a **multitenant** ([analisi](MULTITENANT_ANALYSIS.md)) fa scattare l'uso commerciale/multi-utente → free tier non più valido, serve piano a pagamento. **I due lavori sono in tensione: decidere l'ordine.**

### Fasi proposte
| Fase | Contenuto |
|------|-----------|
| **P1 — Infra dato** | tabella `instrument_prices`, indice symbol, config `prices`, `effectivePrice()` con conversione (§3.5) + fallback |
| **P2 — Fetch** | `PriceProvider` + factory + 1 provider stock/ETF + 1 crypto, `InvestmentPriceFetcher`, comando `prices:fetch`, schedule |
| **P3 — UI & hardening** | badge fonte/data in UI, fallback per-symbol, test, Larastan, ADR, aggiornamento AGENTS.md |

### Decisioni aperte (da confermare prima di P1)
- **D1** — Modello prezzo: tabella globale `instrument_prices` (raccomandato) **o** scrittura diretta su `holding.last_price`?
- ~~**D2** — Provider stock/ETF: quale fonte? Crypto: provider keyless separato?~~ **CHIUSA (§3.8): EODHD per stock/etf/fund, CoinGecko per crypto.** Ticker UCITS su `.XETRA`. Vincolo: free tier solo per uso personale → incompatibile col multitenant.
- **D3** — Frequenza refresh: giornaliera (come i cambi) basta, o serve intraday?
- **D4** — Asset coperti subito: solo `stock/etf/crypto`, o anche `fund/bond/commodity` (spesso senza fonte gratuita affidabile)?
- **D5** — Precedenza: la quota auto **prevale** sul prezzo manuale, o il manuale è un override che vince sempre?

> Decisione architetturale rilevante → creare ADR `docs/adr/NNNN-investment-price-autofetch.md` (sez. 9.6 AGENTS.md). Lo storico prezzi resta un item separato, **abilitato** ma non implementato da questa analisi.
