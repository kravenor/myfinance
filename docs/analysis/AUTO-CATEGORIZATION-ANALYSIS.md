# Analisi — Auto-categorizzazione transazioni in fase di import

> Scope: introdurre un sistema di regole pattern→categoria applicate **solo durante l'import CSV** (estendibile in futuro a OFX/QIF). Nessun impatto sul flusso di creazione/edit manuale transazioni.

## 1. Flusso attuale

### Backend
L'import CSV passa da [TransactionImportExportController::importCommit](../../backend/app/Http/Controllers/TransactionImportExportController.php) → [TransactionImportService::import()](../../backend/app/Services/TransactionImportService.php).

Per ogni riga il servizio:
1. Parsa data (`Carbon::createFromFormat`), importo (italian/standard), descrizione.
2. Risolve il `type` da `mapping[type]` oppure dal segno dell'importo.
3. Se il mapping include la colonna `category`, prova un match **case-insensitive sul nome categoria** caricando in memoria tutte le categorie dell'utente (`Category::query()->get()->keyBy(...)`), altrimenti `category_id = null`.
4. Crea la `Transaction` (il trait `BelongsToUser` riempie `user_id` automaticamente).

L'endpoint preview (`/api/transactions/import/preview`) ritorna `headers`, `sample` e un `suggested` mapping euristico — nessuna logica di categorizzazione.

### Frontend
[ImportExportView.vue](../../frontend/src/views/ImportExportView.vue) gestisce: upload → preview → form di mapping (date/amount/description/type/category) → conferma. Il riepilogo finale mostra solo `imported` / `skipped` / `errors[]`.

### Limiti attuali
- L'auto-match esistente funziona **solo se il CSV ha una colonna categoria scritta esattamente come il nome categoria nel DB**. Negli estratti conto reali questa colonna non esiste.
- L'utente deve assegnare manualmente la categoria a ogni transazione importata, oppure usare l'edit massivo (che non esiste in frontend).
- Nessuna persistenza di "ho già visto questa descrizione → categoria X": ogni import riparte da zero.

---

## 2. Modifiche da apportare

1. Nuova tabella `categorization_rules` con scoping per `user_id`.
2. Model `CategorizationRule` + factory + policy `OwnedByUserPolicy`.
3. CRUD API `/api/categorization-rules` (apiResource).
4. Form Request `Store/UpdateCategorizationRuleRequest` con validazione del pattern.
5. Resource `CategorizationRuleResource`.
6. Service `RuleMatcher` (matcher singolo, riusabile, con cache regole per import).
7. Integrazione `RuleMatcher` in `TransactionImportService::import()` come fallback quando `category_id` non è stato già risolto dal mapping CSV.
8. Endpoint preview esteso: mostrare per ogni riga di sample la `predicted_category` (se una regola matcha) — utile per dare feedback immediato in UI.
9. Frontend: nuova view `CategorizationRulesView.vue` + voce sidebar `/categorization-rules`.
10. Frontend `ImportExportView.vue`: mostrare la categoria predetta nella preview, e nel risultato finale il count `auto_categorized`.
11. Test feature: CRUD regole + matching durante import.
12. Aggiornamento `AGENTS.md` (sezioni 3 e 11) con i nuovi endpoint, model e view.

---

## 3. Dettaglio dei fix

### 3.1 Migration `categorization_rules`

Nuova migration `database/migrations/YYYY_MM_DD_XXXXXX_create_categorization_rules_table.php`:

| Campo | Tipo | Note |
|-------|------|------|
| `id` | `bigIncrements` | PK |
| `user_id` | `foreignId` → users | `cascadeOnDelete` |
| `category_id` | `foreignId` → categories | `cascadeOnDelete` (se cancello la categoria, le sue regole spariscono) |
| `name` | `string(120)` | Label umana (es. "Supermercati Esselunga") |
| `match_type` | `enum('contains','starts_with','equals','regex')` | Strategia di matching sulla descrizione |
| `pattern` | `string(255)` | La stringa o regex (case-insensitive in fase di match) |
| `applies_to_type` | `enum('any','income','expense')` default `any` | Filtra in che `type` la regola si applica |
| `priority` | `unsignedSmallInteger` default `100` | Ordine valutazione (più basso = prima) |
| `is_active` | `boolean` default `true` | Disattivare senza cancellare |
| `times_applied` | `unsignedInteger` default `0` | Counter incrementato a ogni match per analytics |
| `last_applied_at` | `timestamp` nullable | Timestamp ultimo match |
| timestamps | — | created_at / updated_at |

Indici: `(user_id, is_active, priority)` per la query di matching, e indice singolo su `category_id` per il cascade.

### 3.2 Model `App\Models\CategorizationRule`

```php
class CategorizationRule extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'category_id', 'name', 'match_type', 'pattern',
        'applies_to_type', 'priority', 'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'times_applied' => 'integer',
        'last_applied_at' => 'datetime',
    ];

    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
}
```

Aggiungere `hasMany(CategorizationRule::class)` su `User` e su `Category` per integrità referenziale e seeders/factory.

### 3.3 Policy

`App\Policies\CategorizationRulePolicy extends OwnedByUserPolicy` — eredita le 6 ability standard. Registrare in `AuthServiceProvider` o via `enforceMorphMap` come fatto per le altre policy.

### 3.4 Form Request

`Store/UpdateCategorizationRuleRequest`:

- `category_id` → `required|integer` + `Rule::exists('categories', 'id')->where('user_id', Auth::id())`.
- `name` → `required|string|max:120`.
- `match_type` → `required|in:contains,starts_with,equals,regex`.
- `pattern` → `required|string|max:255`. Per `regex` aggiungere validazione custom (`@preg_match($pattern, '')` deve restituire diverso da `false`) → altrimenti messaggio "Espressione regolare non valida".
- `applies_to_type` → `nullable|in:any,income,expense`, default `any`.
- `priority` → `nullable|integer|min:0|max:9999`.
- `is_active` → `nullable|boolean`.

### 3.5 Resource

`CategorizationRuleResource` ritorna tutti i campi + `category` annidato (id/name/color) per evitare un fetch separato in UI:

```php
'category' => [
    'id' => $this->category_id,
    'name' => $this->whenLoaded('category', fn () => $this->category->name),
    'color' => $this->whenLoaded('category', fn () => $this->category->color),
],
```

### 3.6 Controller

`CategorizationRuleController` con `apiResource` standard:

- `index` — lista (filtro `is_active`, `category_id`, ordine `priority asc, id asc`), eager-load `category`. Paginazione default 25.
- `store/show/update/destroy` — pattern uguale agli altri controller del progetto.
- Niente endpoint custom per ora (no "test rule" — la preview import basta).

Registrare rotta in `routes/api.php` dentro il gruppo `auth:sanctum`:
```php
Route::apiResource('categorization-rules', CategorizationRuleController::class)
    ->parameter('categorization-rules', 'categorization_rule');
```

### 3.7 Service `RuleMatcher`

Nuovo file [backend/app/Services/CategorizationRuleMatcher.php]:

```php
class CategorizationRuleMatcher
{
    /** @var Collection<int, CategorizationRule>|null */
    private ?Collection $cache = null;

    public function preload(): void
    {
        $this->cache = CategorizationRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    public function match(?string $description, string $type): ?CategorizationRule
    {
        if ($description === null || trim($description) === '') {
            return null;
        }
        $this->cache ??= $this->preload();
        $desc = mb_strtolower($description);

        foreach ($this->cache as $rule) {
            if ($rule->applies_to_type !== 'any' && $rule->applies_to_type !== $type) {
                continue;
            }
            if ($this->matches($desc, $rule)) {
                return $rule;
            }
        }
        return null;
    }

    private function matches(string $desc, CategorizationRule $rule): bool
    {
        $needle = mb_strtolower($rule->pattern);
        return match ($rule->match_type) {
            'contains'    => str_contains($desc, $needle),
            'starts_with' => str_starts_with($desc, $needle),
            'equals'      => $desc === $needle,
            'regex'       => @preg_match('/'.str_replace('/', '\/', $rule->pattern).'/iu', $desc) === 1,
        };
    }
}
```

Note:
- Preload una volta sola per import (evita N+1).
- `regex` viene wrappato in `/.../iu` per case-insensitive + utf-8. Validazione preventiva nella FormRequest evita pattern rotti.
- `@preg_match` silenzia warning su regex non valide (ma con la validazione FormRequest non dovrebbe mai succedere).

### 3.8 Integrazione in `TransactionImportService`

Modifiche a [TransactionImportService::import()](../../backend/app/Services/TransactionImportService.php:34):

1. Iniettare `CategorizationRuleMatcher` via costruttore (rendere il service `readonly`-friendly).
2. Prima del loop: `$matcher->preload()`.
3. All'interno del loop, dopo aver risolto `$categoryId` dal mapping CSV:
   ```php
   $matchedRule = null;
   if ($categoryId === null && $description !== null) {
       $matchedRule = $matcher->match($description, $type);
       $categoryId = $matchedRule?->category_id;
   }
   ```
4. Dopo `Transaction::create`, se `$matchedRule` esiste:
   ```php
   $matchedRule->increment('times_applied');
   $matchedRule->forceFill(['last_applied_at' => now()])->save();
   ```
   In alternativa: aggregare gli increment a fine import (single `UPDATE ... SET times_applied = times_applied + N WHERE id IN (...)`) per evitare overhead su import grossi. **Scelta consigliata: aggregare** — conserviamo `array<int, int>` `$ruleHits[ruleId] = count` e facciamo gli update finali.
5. Estendere il return:
   ```php
   return [
       'imported' => $imported,
       'skipped' => $skipped,
       'auto_categorized' => $autoCount,   // nuovo
       'errors' => $errors,
   ];
   ```

### 3.9 Preview con predizione

Estendere `TransactionImportService::preview()` per ritornare, oltre a `headers/sample/suggested`, anche un campo `predictions` (array allineato a `sample`) con `category_id` e `category_name` predetti dalla `RuleMatcher`. Per la preview serve già conoscere il `mapping` candidato (description + type) — quindi:

**Opzione A (consigliata):** la preview ritorna solo `headers/sample/suggested` come oggi, e il frontend fa una **seconda chiamata** `POST /api/transactions/import/preview-predictions` con il mapping scelto dall'utente per ottenere le predizioni. Pulisce le responsabilità.

**Opzione B:** un singolo endpoint `preview` accetta opzionalmente il mapping e ritorna le predictions inline. Meno round-trip ma mescola due step.

Vado con **A** — richiede un endpoint in più (`POST /api/transactions/import/preview-predictions`) ma è coerente col flusso UI (mapping si forma dopo la preview).

### 3.10 Frontend — view CRUD `CategorizationRulesView.vue`

Nuova view in `frontend/src/views/CategorizationRulesView.vue`, lazy-route `/categorization-rules`, voce sidebar (icona engranaggio) dopo Tags.

Struttura:
- Tabella regole con colonne: `priority`, `name`, descrizione condizione (`match_type` + `pattern`), `applies_to_type`, `category` (con color swatch), `times_applied`, `is_active` (toggle), azioni edit/delete.
- Form inline a riga (come `TagsView`/`CategoriesView`): name, match_type select, pattern, applies_to_type select, category select (filtrato per type quando `applies_to_type ≠ any`), priority, is_active.
- Validazione client-side leggera + propagazione errori `422` come nelle altre view.

Tipi in `frontend/src/types/api.ts`:
```ts
export interface CategorizationRule {
  id: number
  category_id: number
  category: { id: number; name: string; color: string | null }
  name: string
  match_type: 'contains' | 'starts_with' | 'equals' | 'regex'
  pattern: string
  applies_to_type: 'any' | 'income' | 'expense'
  priority: number
  is_active: boolean
  times_applied: number
  last_applied_at: string | null
}
```

### 3.11 Frontend — `ImportExportView.vue`

Modifiche:
1. Dopo che l'utente ha confermato il mapping → chiamata a `POST /api/transactions/import/preview-predictions` → mostrare nella tabella di preview una colonna extra "Categoria suggerita" (swatch + nome, oppure "—" se nessun match).
2. Nel risultato finale dell'import aggiungere un riepilogo:
   `Importate: X · Auto-categorizzate: Y · Saltate: Z`.
3. Aggiungere link contestuale "Gestisci regole →" che porta a `/categorization-rules`.

### 3.12 Test feature

- `tests/Feature/CategorizationRuleControllerTest.php` — CRUD + scoping per user + validazione regex.
- `tests/Feature/TransactionImportAutoCategorizationTest.php` — import CSV con regole attive, verificare: match `contains` case-insensitive, rispetto `priority`, `applies_to_type` filter, `times_applied` incrementato, nessun match → `category_id null`.

---

## 4. Impatti e possibili regressioni

> Branch di riferimento per le regressioni: **`master`** (non rientra nelle convenzioni `metrics-*`, quindi uso il branch principale di questo repo).

### Impatti diretti
- **`TransactionImportService`** cambia firma costruttore (nuovo dep injection). Verificare che il container Laravel risolva correttamente il `CategorizationRuleMatcher` (è auto-wired dato che non ha parametri primitivi nel costruttore).
- **Schema DB**: nuova tabella, nessuna modifica a tabelle esistenti → migration additiva, no downtime.
- **API surface**: 5 nuovi endpoint REST + 1 endpoint preview-predictions. Nessuna rotta esistente cambia firma.
- **Frontend bundle**: una nuova view (~10–15 KB) + tipi. Trascurabile.
- **`AGENTS.md`** va aggiornato (sezione 3 struttura + sezione 11 con i nuovi endpoint e la view; eventualmente nuova sotto-sezione "Auto-categorizzazione").

### Regressioni da verificare
1. **Import CSV con colonna `category` nel mapping**: il comportamento attuale (match esatto per nome) deve avere **precedenza** sulle regole — già garantito dalla logica "applica regola solo se `$categoryId === null`". Test esplicito.
2. **Import senza descrizione**: se `mapping.description` è null o la cella è vuota, `RuleMatcher::match()` ritorna `null` — nessun match, comportamento attuale invariato.
3. **Performance import grossi (>10k righe)**:
   - Preload regole una sola volta ✓.
   - Aggregazione `times_applied` finale ✓.
   - Loop matching: O(righe × regole). Con 50 regole tipiche e 10k righe → 500k str_contains, trascurabile in PHP.
   - Regex: se l'utente crea 100 regex complesse, può degradare. Mitigazione: hard cap `priority` count o doc nota. Non bloccante in prima release.
4. **Validazione regex**: pattern malformato deve essere rifiutato in fase di `store`/`update`, non scoppiare a import-time. Test esplicito.
5. **Cascade delete categorie**: cancellare una categoria attualmente fa `nullOnDelete` su `transactions.category_id`. Le regole invece le elimino (`cascadeOnDelete`) — coerente perché una regola senza categoria target non ha senso. Confermare con l'utente se preferisce `nullOnDelete` + UI che mostri "categoria mancante".
6. **Scoping multi-user**: `UserScope` via `BelongsToUser` + policy OwnedByUserPolicy + `Rule::exists` su `category_id` filtrato per user. Test: utente A non può creare regola che punta a categoria utente B.
7. **Larastan livello 5**: ricordarsi annotazioni `@property` sul model e `@mixin` su `CategorizationRuleResource` per non rompere `make stan`.
8. **Frontend type-check**: il refactor di `ImportExportView` aggiunge una colonna preview — verificare che il rendering condizionale (mapping non ancora confermato vs predictions caricate) non rompa il flusso esistente.

### Note open / decisioni da confermare prima di implementare
- Cascade su categoria → regole: `cascadeOnDelete` vs `nullOnDelete`. *Default proposta: cascade.*
- Endpoint preview-predictions separato vs preview unico. *Default proposta: separato (opzione A).*
- UI delle regole: form inline (come `TagsView`) vs modale dedicata. *Default proposta: inline coerente col resto del progetto.*
