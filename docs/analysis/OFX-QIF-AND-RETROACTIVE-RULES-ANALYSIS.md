# Analisi — Parser OFX/QIF e applicazione retroattiva regole

> Scope: due sotto-feature indipendenti ma correlate, da implementare nello stesso ciclo:
> 1. Supporto import per **OFX 2.x** e **QIF**, riusando l'infrastruttura preview/commit/predictions del CSV.
> 2. Applicazione **retroattiva** delle `CategorizationRule` alle transazioni esistenti senza categoria, esposta sia via comando artisan sia via UI con preview obbligatoria (dry-run).

## 1. Flusso attuale

### Import (solo CSV)
- [TransactionImportService](../../backend/app/Services/TransactionImportService.php) parsa CSV con auto-detect delimitatore, costruisce `[headers, rows]` (`array<string, string>`), suggerisce mapping euristico, esegue `import()` con mapping confermato + accountId + dateFormat.
- Endpoint correnti: `/api/transactions/import/preview`, `/api/transactions/import`, `/api/transactions/import/preview-predictions`.
- UI ([ImportExportView.vue](../../frontend/src/views/ImportExportView.vue)): file → preview con sample → form di mapping (5 select) → preview-predictions con colonna "categoria suggerita" → commit.

### Categorization rules
- Modello `CategorizationRule` + matcher service [CategorizationRuleMatcher](../../backend/app/Services/CategorizationRuleMatcher.php).
- Il matcher attualmente è invocato **solo** durante `TransactionImportService::import()` come fallback quando il mapping CSV non risolve la categoria.
- Le transazioni esistenti senza categoria (es. importate prima che la regola esistesse, oppure inserite manualmente senza categoria) restano `category_id = null`.

### Limiti
- Estratti conto bancari italiani spesso forniscono OFX/QIF, non CSV. L'utente deve preconvertire — frizione.
- Una nuova regola non riclassifica retroattivamente: o si riprocessa l'import o si edita riga per riga.

---

## 2. Modifiche da apportare

### Sotto-feature A — Parser OFX/QIF
A1. Refactor di `TransactionImportService` per separare il **reader di formato** dalla logica di import. Estrarre interfaccia `ImportReader` con due implementazioni nuove (`OfxReader`, `QifReader`) accanto al CSV inline.
A2. Detection del formato a partire dall'estensione + content sniff (header XML, header `!Type:` per QIF, fallback CSV).
A3. Adattare `preview()`/`import()`/`previewPredictions()` per accettare il formato e produrre output normalizzato.
A4. Per OFX/QIF il mapping è **già noto** (campi fissi del formato): la preview ritorna direttamente le righe normalizzate e la UI nasconde la sezione "form mapping".
A5. Validazione `Content-Type` + estensione nel FormRequest: aggiungere `.ofx`, `.qfx`, `.qif` ai mimetypes.
A6. Test: parser OFX, parser QIF, detection format, fallback su file ambigui.
A7. UI: gestione import per format, sostituzione della mappatura quando non serve, hint formato accanto al file input.

### Sotto-feature B — Applicazione retroattiva regole
B1. Service `CategorizationRuleApplier` che cicla sulle transazioni (filtri: `only_uncategorized` default true, `account_id?`, `from?`, `to?`), usa il `CategorizationRuleMatcher` esistente e ritorna preview o commit.
B2. Endpoint `POST /api/categorization-rules/apply` con:
   - Body: `{dry_run: bool, only_uncategorized?: bool, account_id?: int, from?: string, to?: string}`.
   - Risposta dry-run: `{matched: int, by_rule: [{rule_id, name, count}], sample: [{id, occurred_at, description, suggested_category: {id, name}}]}` (sample max 50).
   - Risposta commit: `{matched: int, updated: int, by_rule: [...]}`.
B3. Comando artisan `rules:apply` con flag `--dry-run`, `--only-uncategorized=true`, `--account=`, `--from=`, `--to=`. Stampa tabella riepilogo.
B4. UI nella view regole: bottone "Applica alle transazioni esistenti" → modale con filtri opzionali → dry-run → tabella conteggi → conferma → commit. Riepilogo finale con count.
B5. Test: dry-run non modifica nulla, commit aggiorna solo le transazioni che matchano, `only_uncategorized=false` riassegna anche le già categorizzate, `times_applied` incrementato anche in retroattivo.

### Aggiornamento documentazione
C1. Aggiornare `AGENTS.md`: sezione 3 (struttura), sezione 14 (auto-categorizzazione) con endpoint apply + comando artisan, nota su OFX/QIF nella sezione 11. Aggiornare data in cima.

---

## 3. Dettaglio dei fix

### 3.1 Refactor reader (A1, A3, A4)

Introduco `App\Services\Import\ImportReader` come **classe astratta** (non interfaccia, per condividere helper di normalizzazione):

```php
abstract class ImportReader
{
    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, mapping_locked: bool}
     */
    abstract public function read(UploadedFile $file, int $limit): array;
}
```

Implementazioni:

- **`CsvReader`** — estrae la logica di `parse()` + `detectDelimiter()` da `TransactionImportService`. `mapping_locked = false` (l'utente sceglie le colonne).
- **`OfxReader`** — parsing XML con `SimpleXMLElement` o `DOMDocument`. Estrae le `<STMTTRN>` da `BANKMSGSRSV1/STMTTRNRS/STMTRS/BANKTRANLIST` (e analogo `CREDITCARDMSGSRSV1`). Normalizza:
  - `date` ← `DTPOSTED` (formato `YYYYMMDD[HHMMSS]`, prendere i primi 8 caratteri).
  - `amount` ← `TRNAMT` (segno significativo).
  - `description` ← `NAME ?? MEMO`.
  - `type` ← `TRNTYPE` (`DEBIT|CREDIT|XFER|...` → expense/income, fallback dal segno).
  - `external_id` ← `FITID` (utile per dedup futuro, lo conserviamo nel `Transaction.external_id`).
  - Headers fissi: `['date', 'amount', 'description', 'type', 'external_id']`. `mapping_locked = true`.
- **`QifReader`** — parsing line-oriented:
  - Skippa header `!Type:...`.
  - Accumula campi finché trova `^` (terminator), poi flusha:
    - `D` → date. Formato QIF accetta `MM/DD/YY`, `MM/DD'YYYY`, `DD/MM/YYYY` ecc. Provo `m/d/Y`, `m/d/y`, `d/m/Y` con `Carbon::parse` o tentativi sequenziali.
    - `T` → amount (può avere `,` o `.`).
    - `P` → payee → description.
    - `M` → memo → notes (o description se P assente).
    - `N` → number/check.
  - Headers fissi `['date', 'amount', 'description', 'notes', 'type']`. `mapping_locked = true`.

Factory in `App\Services\Import\ImportReaderFactory`:

```php
class ImportReaderFactory
{
    public function for(UploadedFile $file, ?string $explicit = null): ImportReader
    {
        $format = $explicit ?? $this->detect($file);
        return match ($format) {
            'ofx' => new OfxReader(),
            'qif' => new QifReader(),
            default => new CsvReader(),
        };
    }

    public function detect(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['ofx', 'qfx'], true)) return 'ofx';
        if ($ext === 'qif') return 'qif';

        // content sniff sui primi 512 byte
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 512);
        if (str_contains($head, '<OFX') || str_starts_with(ltrim($head), '<?xml')) return 'ofx';
        if (str_starts_with(ltrim($head), '!Type:')) return 'qif';
        return 'csv';
    }
}
```

### 3.2 TransactionImportService (A3)

- Iniettare `ImportReaderFactory` nel costruttore (insieme al `CategorizationRuleMatcher` già presente).
- `preview()` e `import()` chiamano `factory->for($file)` invece del parser CSV inline. Output `preview()` arricchito:
  ```php
  return [
      'format' => 'csv|ofx|qif',
      'headers' => $rows['headers'],
      'sample' => $rows['rows'],
      'mapping_locked' => $rows['mapping_locked'],
      'suggested' => $this->suggestMapping(...),
  ];
  ```
  Per OFX/QIF `suggested` ritorna un mapping pre-bloccato sui campi normalizzati (`date→date`, `amount→amount`, ecc.) — la UI lo userà direttamente senza chiedere all'utente.
- `import()`: se `mapping_locked = true`, **forzare** il mapping ai campi normalizzati. Per OFX, popolare anche `external_id` su `Transaction` quando presente.

### 3.3 Endpoint adattati (A5)

- `POST /api/transactions/import/preview` — accettare `mimetypes:text/csv,text/plain,application/csv,application/xml,application/x-ofx,application/octet-stream` e `mimes:csv,txt,ofx,qfx,qif`. Niente cambio di firma esterna.
- `POST /api/transactions/import` — idem sui mimetypes. Il body può omettere `mapping[*]` se il client sa che è OFX/QIF (oppure la validazione resta `required` e la UI invia il mapping forzato — più semplice mantenere validazione invariata).
- `POST /api/transactions/import/preview-predictions` — idem.

### 3.4 Frontend (A7)

- [ImportExportView.vue](../../frontend/src/views/ImportExportView.vue):
  - Accept del file input: `accept=".csv,.ofx,.qfx,.qif,text/csv"`.
  - Tipo `PreviewResult` esteso: aggiungere `format` e `mapping_locked`.
  - Quando `mapping_locked = true`: la sezione "form mapping (5 select)" è nascosta, l'utente vede direttamente la tabella sample + colonna "categoria suggerita". Il commit usa il mapping forzato.
  - Etichetta accanto al file input: "Formato rilevato: CSV/OFX/QIF" dopo la preview.

### 3.5 Service applier (B1)

Nuovo file [backend/app/Services/CategorizationRuleApplier.php]:

```php
class CategorizationRuleApplier
{
    public function __construct(private readonly CategorizationRuleMatcher $matcher) {}

    /**
     * @param  array{only_uncategorized?: bool, account_id?: int, from?: string, to?: string}  $filters
     * @return array{matched: int, updated: int, by_rule: array<int, array{rule_id: int, name: string, count: int}>, sample: array<int, array<string, mixed>>}
     */
    public function run(array $filters, bool $dryRun): array
    {
        $this->matcher->reset();
        $this->matcher->preload();

        $query = Transaction::query();
        if (($filters['only_uncategorized'] ?? true) === true) {
            $query->whereNull('category_id');
        }
        if (!empty($filters['account_id'])) $query->where('account_id', $filters['account_id']);
        if (!empty($filters['from'])) $query->whereDate('occurred_at', '>=', $filters['from']);
        if (!empty($filters['to'])) $query->whereDate('occurred_at', '<=', $filters['to']);

        $matched = 0;
        $updated = 0;
        $byRule = [];   // rule_id => ['rule_id','name','count']
        $sample = [];   // primi 50 elementi con suggested
        $updates = [];  // tx_id => category_id da aggiornare in chunk

        $query->orderBy('id')->chunk(500, function ($txs) use (&$matched, &$updated, &$updates, &$byRule, &$sample, $dryRun) {
            foreach ($txs as $tx) {
                $rule = $this->matcher->match($tx->description, $tx->type);
                if (!$rule) continue;

                $matched++;
                $byRule[$rule->id] ??= ['rule_id' => $rule->id, 'name' => $rule->name, 'count' => 0];
                $byRule[$rule->id]['count']++;

                if (count($sample) < 50) {
                    $sample[] = [
                        'transaction_id' => $tx->id,
                        'occurred_at' => $tx->occurred_at?->toDateString(),
                        'description' => $tx->description,
                        'suggested_category_id' => $rule->category_id,
                        'rule_id' => $rule->id,
                    ];
                }

                if (!$dryRun) {
                    $updates[$tx->id] = $rule->category_id;
                    $this->matcher->recordHit($rule);
                }
            }
        });

        if (!$dryRun && $updates !== []) {
            // single UPDATE per (category_id, list of ids) — group by category
            $groupByCat = [];
            foreach ($updates as $txId => $catId) $groupByCat[$catId][] = $txId;
            foreach ($groupByCat as $catId => $ids) {
                Transaction::whereIn('id', $ids)->update(['category_id' => $catId]);
                $updated += count($ids);
            }
            $this->matcher->flushHits();
        }

        return ['matched' => $matched, 'updated' => $updated, 'by_rule' => array_values($byRule), 'sample' => $sample];
    }
}
```

Note:
- Usa `chunk(500)` per non caricare in memoria storici grandi.
- Aggiorna in batch per `category_id` invece di N update singole.
- `times_applied` viene incrementato solo se non dry-run.
- Il modello `Transaction` ha già `BelongsToUser` → lo scope globale filtra automaticamente al solo utente loggato.

### 3.6 Endpoint apply (B2)

In `CategorizationRuleController` aggiungere metodo `apply(Request $request)` con validazione:

```php
$request->validate([
    'dry_run' => ['required', 'boolean'],
    'only_uncategorized' => ['nullable', 'boolean'],
    'account_id' => ['nullable', 'integer', Rule::exists('accounts','id')->where(fn($q)=>$q->where('user_id',Auth::id()))],
    'from' => ['nullable', 'date'],
    'to' => ['nullable', 'date', 'after_or_equal:from'],
]);

$this->authorize('viewAny', CategorizationRule::class);
$result = $applier->run($request->all(), $request->boolean('dry_run'));
return response()->json(['data' => $result]);
```

Rotta in `routes/api.php`:
```php
Route::post('categorization-rules/apply', [CategorizationRuleController::class, 'apply'])
    ->name('categorization-rules.apply');
```
(Da posizionare **prima** di `apiResource` per evitare conflitto con `/{id}`.)

### 3.7 Comando artisan (B3)

Nuovo file [backend/app/Console/Commands/ApplyCategorizationRules.php]:

```php
class ApplyCategorizationRules extends Command
{
    protected $signature = 'rules:apply
        {--dry-run : Non scrive nulla, mostra solo il riepilogo}
        {--only-uncategorized=true : Limita alle transazioni senza categoria}
        {--user= : Limita a un user_id specifico (default tutti)}
        {--account= : Limita a un account_id}
        {--from= : Data minima (YYYY-MM-DD)}
        {--to=   : Data massima (YYYY-MM-DD)}';

    public function handle(CategorizationRuleApplier $applier): int
    {
        $userIds = $this->option('user')
            ? [(int) $this->option('user')]
            : User::query()->pluck('id')->all();

        foreach ($userIds as $userId) {
            Auth::loginUsingId($userId);   // attiva global scope
            $result = $applier->run([
                'only_uncategorized' => filter_var($this->option('only-uncategorized'), FILTER_VALIDATE_BOOL),
                'account_id' => $this->option('account') ? (int) $this->option('account') : null,
                'from' => $this->option('from'),
                'to' => $this->option('to'),
            ], (bool) $this->option('dry-run'));
            $this->line("User {$userId}: matched={$result['matched']}, updated={$result['updated']}");
        }
        return self::SUCCESS;
    }
}
```

### 3.8 UI bottone retroattivo (B4)

In [CategorizationRulesView.vue](../../frontend/src/views/CategorizationRulesView.vue):
- Aggiungere bottone "Applica alle transazioni esistenti" accanto a "Nuova regola".
- Modale con form: checkbox `only_uncategorized` (default on), select account (opzionale), date range from/to.
- Step 1: chiamata `apply` con `dry_run: true` → mostra tabella `by_rule` (rule name + count) + sample dei primi 50 + total `matched`.
- Step 2: bottone "Conferma e applica" → seconda chiamata con `dry_run: false` → toast riepilogo + reload lista regole (per refresh `times_applied`).

### 3.9 Test (A6, B5)

`tests/Feature/Services/OfxReaderTest.php` e `QifReaderTest.php` (unit-level, ma li metto sotto Feature per coerenza con la struttura esistente):
- Parse fixture OFX 2.x con 2 transazioni → headers normalizzati, rows corrette, type da TRNTYPE.
- Parse fixture QIF con 3 entry → date parse fallback su più formati, payee come description.

`tests/Feature/Api/TransactionImportFormatDetectionTest.php`:
- POST `.ofx` → `preview.format = 'ofx'`, `mapping_locked = true`.
- POST `.qif` → `preview.format = 'qif'`.
- POST `.csv` → `preview.format = 'csv'`.

`tests/Feature/Api/RetroactiveRuleApplyTest.php`:
- Dry-run su 3 transazioni senza categoria + 1 regola matching 2 di esse → `matched = 2`, `updated = 0`, db invariato.
- Commit (dry_run=false) → `updated = 2`, `times_applied += 2`.
- `only_uncategorized=false` matcha anche transazioni già categorizzate e le sovrascrive.
- Filtro `account_id` rispettato.

### 3.10 AGENTS.md (C1)

- Sezione 11 (Import/Export): nota su formati supportati ora `CSV + OFX 2.x + QIF`, con riferimento ai reader.
- Sezione 14: aggiungere endpoint `POST /api/categorization-rules/apply` + comando artisan `rules:apply` + nota su UI bottone.
- Data in cima.

---

## 4. Impatti e possibili regressioni

> Branch di riferimento per le regressioni: **`master`**.

### Impatti diretti
- `TransactionImportService` cambia firma costruttore (nuova dep `ImportReaderFactory`). Tutti i punti che lo iniettano sono gestiti dal container Laravel — nessuna chiamata manuale `new` nel codice attuale.
- Schema DB invariato. Nessuna migration.
- API surface: 1 nuovo endpoint (`categorization-rules/apply`). Il preview/import esistenti restano backward-compatible (ritornano `format` e `mapping_locked` come campi aggiuntivi).
- Nuova dipendenza: nessuna libreria esterna (SimpleXML e DOM sono in PHP core).
- Sidebar UI invariata. La view regole guadagna un bottone.

### Regressioni da verificare
1. **CSV esistente**: il refactor del reader non deve cambiare il comportamento percepito. Test di import CSV già presenti devono continuare a passare invariati. Validare `make test` completo.
2. **External_id da OFX**: scrivere `FITID` in `Transaction.external_id`. Verificare che il campo non sia mai obbligatorio e che il modello lo accetti.
3. **Mapping locked**: la UI deve **non** inviare il form di mapping se locked. Se per errore lo invia, il backend deve comunque applicare il mapping forzato dei campi normalizzati (server-side override).
4. **Date QIF ambigue**: `05/06/2026` può essere `m/d` o `d/m`. Strategia: provare prima `Y-m-d`/`d/m/Y` (formato europeo) poi `m/d/Y`. Per ridurre falsi positivi, il QifReader non emette eccezione su date irrecuperabili — passa una stringa vuota e la riga viene scartata in `import()` come "Data non valida".
5. **OFX con encoding non UTF-8**: alcuni estratti italiani usano ISO-8859-1. Aggiungere `iconv` di sicurezza prima del parse XML, o lasciare a `SimpleXMLElement` (che esce in errore). Decisione: provare `mb_convert_encoding` se il primo parse fallisce — feedback nel test.
6. **Loop su molti utenti nel comando**: `Auth::loginUsingId` cambia globalmente il guard; bisogna ricordarsi di `Auth::logout()` o `Auth::forgetUser()` tra iterazioni per evitare leak di scope tra utenti. Test esplicito multi-utente.
7. **Performance retroattivo su storici grandi**: la `chunk(500)` + update raggruppati per `category_id` rendono l'operazione `O(transazioni × regole)` con poche query. Ok fino a ~50k tx + 50 regole. Sopra: documentare nel comando, non blocco.
8. **Concorrenza UI**: se l'utente clicca due volte "Conferma e applica", il secondo run sulle stesse tx già aggiornate produrrebbe `0 updated` (`only_uncategorized` filtra). Idempotente di fatto.
9. **`times_applied` retroattivo**: si incrementa anche per le applicazioni retroattive — semantica corretta perché il contatore rappresenta "regola usata", indipendente dal canale. Test conferma.
10. **Larastan livello 5**: ricordare `@property` su nuovi service/model (`CategorizationRuleApplier` non è un modello, ma il phpstan richiederà tipi precisi su closure di `chunk`). Curare il docblock del callback.
11. **Pint preset Laravel**: evitare FQN nei docblock (regola `fully_qualified_strict_types` — già emersa in [feedback memoria](#)).

### Note open / decisioni da confermare prima di implementare
- **Persistere `format` nella tabella `transactions`?** Non strettamente utile, non lo aggiungo. Se serve traccia, basta `external_id` (FITID) per OFX.
- **Dedup OFX via `external_id`?** Estensione naturale ma fuori scope di questo ciclo. Annotarla come follow-up.
- **Mostrare la sample dry-run in tabella nella UI?** Sì, ma con paginazione lato client (50 sample già limitati server-side).
- **Bottone retroattivo sulla view Categorizzazione vs anche sulla view Transactions?** Per ora solo Categorizzazione (più coerente). Eventualmente in seconda iterazione un trigger contestuale "applica regole" nella view transazioni.
