# Analisi — Coerenza dei dati (4 fix emersi dal restyling)

> Scope: quattro difetti trovati verificando i testi di aiuto del branch `feat/ui-ux-redesign`.
> Branch `fix/data-consistency`, creato da `feat/ui-ux-redesign` perché due fix toccano view già riscritte lì.
> Nessuna migration.

## 1. Flusso attuale

### 1.1 Valuta degli obiettivi di risparmio
- [SavingsGoalProgressService](../../backend/app/Services/SavingsGoalProgressService.php) calcola `saved` sommando i movimenti del conto collegato (`savedForGoal`): entrate e uscite sul conto, giroconti in entrata (`transfer_amount`) e in uscita (`amount`). Tutti questi importi sono nella **valuta del conto**.
- L'obiettivo ha però una sua `currency` (scelta nel form, default la valuta dell'utente) e [SavingsGoalsView](../../frontend/src/views/SavingsGoalsView.vue) mostra `saved`, `remaining`, `target_amount` e il ritmo in quella valuta.
- Se conto e obiettivo hanno valute diverse il risparmiato è sbagliato (es. 1.000 USD mostrati come 1.000 €), e con lui progresso, ritmo e notifiche "a rischio" di [NotificationScanner](../../backend/app/Services/NotificationScanner.php).

### 1.2 Duplicati nell'import CSV
- [TransactionImportService](../../backend/app/Services/TransactionImportService.php) scarta le righe con un `external_id` già presente (o ripetuto nel file), letto dalla colonna indicata in `mapping[external_id]`.
- OFX ha il campo fisso (FITID); per i CSV la colonna va mappata, ma [ImportExportView](../../frontend/src/views/ImportExportView.vue) offre solo Data, Importo, Descrizione, Tipo, Categoria e [CsvReader::suggestedMapping](../../backend/app/Services/Import/CsvReader.php) non la propone.
- Risultato: un CSV non viene mai deduplicato, nemmeno reimportando un export dell'app stessa, che ha la colonna `external_id` ([TransactionExportService](../../backend/app/Services/TransactionExportService.php)).

### 1.3 Regole di categorizzazione sui giroconti
- [CategorizationRuleMatcher::match](../../backend/app/Services/CategorizationRuleMatcher.php) salta solo le regole il cui `applies_to_type` non è `any` e differisce dal tipo; una regola `any` corrisponde anche a `type = transfer`.
- All'import non succede (si creano solo entrate/uscite), ma [CategorizationRuleApplier](../../backend/app/Services/CategorizationRuleApplier.php) ("Applica alle transazioni esistenti") legge tutte le transazioni, giroconti compresi, e può assegnare loro una categoria. Un giroconto categorizzato è incoerente con il resto dell'app (il form lo salva sempre senza categoria).

### 1.4 Tipo della categoria non validato
- [StoreTransactionRequest](../../backend/app/Http/Requests/Transaction/StoreTransactionRequest.php) e [UpdateTransactionRequest](../../backend/app/Http/Requests/Transaction/UpdateTransactionRequest.php) controllano solo che la categoria appartenga all'utente: un'uscita con una categoria di entrata (o un giroconto con categoria) passa.
- Lo stesso vale per [Store](../../backend/app/Http/Requests/RecurringTransaction/StoreRecurringTransactionRequest.php)/[UpdateRecurringTransactionRequest](../../backend/app/Http/Requests/RecurringTransaction/UpdateRecurringTransactionRequest.php).
- La coerenza oggi è garantita solo dal filtro della select in TransactionsView; report e budget sommano per categoria e per tipo, quindi un dato incoerente sposta i totali.

## 2. Modifiche da apportare

1. `SavingsGoalProgressService`: se la valuta del conto collegato è diversa da quella dell'obiettivo, converte `saved` nella valuta dell'obiettivo con `CurrencyConverter` (tasso di oggi); conti caricati con una sola query.
2. `SavingsGoalsView`: scegliendo il conto collegato, la valuta dell'obiettivo si allinea a quella del conto (modificabile), con hint.
3. `CsvReader::suggestedMapping`: propone la colonna `external_id`.
4. `ImportExportView`: campo di mapping "ID univoco" (facoltativo) inviato come `mapping[external_id]`; validazione `mapping.external_id` nelle due rotte di import.
5. `CategorizationRuleMatcher::match`: nessuna regola per i giroconti.
6. Nuovo helper `App\Support\CategoryTypeCheck` (giroconto → nessuna categoria; entrata/uscita → categoria dello stesso tipo), chiamato nel `withValidator` delle 4 Form Request con tipo e categoria effettivi (payload, altrimenti il record in update).
7. `TransactionsView`: cambiando tipo, una categoria non più compatibile si azzera (prima restava selezionata ma invisibile nella select).
8. Test feature per ognuno dei 4 fix; `AGENTS.md` aggiornato.

## 3. Dettaglio dei fix

### 3.1 Conversione del risparmiato (fix 1)
- `attachProgress()` riceve un array di modelli: `(new EloquentCollection($goals))->loadMissing('account')` carica i conti in una query (evita N+1 nella lista e in `NotificationScanner`).
- Dopo `savedForGoal()`: se `$goal->account` esiste e `account->currency !== goal->currency`, `saved = converter->convert(saved, account->currency, goal->currency, $now)`. Il tasso è quello di oggi: il risparmiato è un saldo attuale, non una somma di flussi a date diverse da rivalutare.
- `CurrencyConverter` iniettato nel costruttore (il service è già risolto dal container in controller e scanner).
- Nessun cambio di schema né di dati: gli obiettivi esistenti con valute diverse diventano corretti senza migration.

### 3.2 Valuta proposta dal conto (fix 2)
- In `SavingsGoalsView` un `watch` su `form.account_id`: se il conto ha una valuta, `form.currency = account.currency`. Resta modificabile (es. obiettivo in EUR alimentato da un conto USD, ora convertito correttamente dal fix 1).
- Hint sul campo Valuta: "Di solito è quella del conto collegato; se è diversa il risparmiato viene convertito al cambio di oggi."

### 3.3 Mapping `external_id` (fix 3-4)
- `CsvReader::suggestedMapping()`: `'external_id' => $find(['external_id', 'id univoco', 'id operazione'])` (parole chiave strette: un generico "id" catturerebbe colonne sbagliate).
- `TransactionImportExportController`: `'mapping.external_id' => ['nullable', 'string']` in `importPreviewPredictions` e `importCommit` (oggi passa senza validazione).
- `ImportExportView`: `external_id` in `MAPPING_FIELD_LABEL` ("ID univoco"), precompilato da `suggested.external_id`, inviato solo se scelto; hint: "Colonna con un codice diverso per ogni movimento (es. l'export di questa app): le righe già importate vengono saltate." Il "Come funziona" dell'import va aggiornato di conseguenza.

### 3.4 Giroconti esclusi dalle regole (fix 5)
- In `match()`: `if ($type === 'transfer') return null;` prima del ciclo. Copre applier, anteprima e qualunque chiamante futuro.
- Le transazioni di tipo transfer già categorizzate da un'applicazione passata restano come sono (nessuna pulizia automatica dei dati); vedi §4.

### 3.5 `CategoryTypeCheck` (fix 6)
- `App\Support\CategoryTypeCheck::error(?int $categoryId, string $type): ?string` restituisce il messaggio o `null`:
  - tipo `transfer` e categoria valorizzata → "Un giroconto non ha categoria."
  - categoria di tipo diverso → "La categoria «X» è di entrata: non si può usare per un'uscita." (e viceversa)
  - categoria `null` → nessun errore.
- Chiamato nell'`after` di `withValidator` (dove le 4 richieste già controllano `transfer_account_id`), solo se le regole di campo sono passate, con:
  - store: `type` e `category_id` del payload;
  - update: `input('type', record->type)` e `has('category_id') ? input('category_id') : record->category_id`. Così cambiare solo il tipo di una transazione con categoria viene controllato anche se `category_id` non è inviato (una regola sul campo, con `sometimes`, non scatterebbe).
- Una query (`Category::query()->whereKey(...)->first(['name', 'type'])`) per richiesta; lo scope utente è garantito dal global scope del modello e dalla regola `exists` già presente.
- Errore sulla chiave `category_id`, quindi mostrato sotto il campo Categoria.
- Prevenzione lato interfaccia: in `TransactionsView` un `watch` su `categoryOptions` azzera `category_id` se la categoria scelta non è più tra le opzioni del tipo corrente. Senza, la select appariva vuota ma il valore restava, e l'errore del server non era comprensibile.

## 4. Impatti e possibili regressioni

Branch di riferimento: `master` (il progetto non è nella tabella dei progetti metrics); il branch parte da `feat/ui-ux-redesign`, da mergiare prima.

- **Dati esistenti incoerenti** (fix 6): una transazione salvata in passato con una categoria del tipo sbagliato non si potrà più salvare senza correggere la categoria. È voluto, ma l'errore deve essere chiaro e comparire sotto il campo Categoria (verificare in TransactionsView). Facoltativo: query di diagnosi `transactions JOIN categories ON type <> categories.type` da lanciare in produzione prima del rilascio.
- **Giroconti già categorizzati** (fix 5): non vengono ripuliti. Se la query di diagnosi ne trova, va deciso se azzerarne la categoria con una migration dedicata.
- **Import da API/script esterni** che inviano categorie incoerenti riceveranno 422: verificare che il frontend sia l'unico client (sì per ora).
- **Conversione del risparmiato** (fix 1): senza tassi per una valuta `CurrencyConverter` usa la parità 1:1 (comportamento già esistente nei report); progresso e notifiche cambiano per gli obiettivi con valute diverse, che è la correzione.
- **Dedup CSV** (fix 3-4): reimportare un export dell'app ora salta le righe già presenti; un CSV bancario senza codici univoci si comporta come prima.
- **Test esistenti**: `TransactionTest`, `RecurringTransactionTest`, `RetroactiveRuleApplyTest`, `SavingsGoalTest`, `TransactionImport*Test` da rieseguire; fixture che creano transazioni con categorie di tipo diverso andrebbero corrette.

### Fuori perimetro (segnalati, non trattati qui)
- Il form delle ricorrenti non invia `category_id`: una ricorrente creata da interfaccia non ha mai categoria, quindi non entra nella baseline di Previsioni (che usa le uscite ricorrenti per categoria).
- `ScenarioItem` ha tipo e categoria con lo stesso rischio di incoerenza del fix 6.
- Patrimonio netto dei mesi passati nei report calcolato con le quantità di oggi degli investimenti.
- Soglia di allerta della barra in BudgetsView fissa all'80% mentre notifiche e Dashboard usano quella delle Impostazioni.
