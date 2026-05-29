# Analisi — Alert budget sforati

> Scope: segnalare le categorie il cui speso del mese si avvicina (warning) o supera (exceeded)
> il budget impostato. Backend espone gli alert calcolati; il frontend li mostra come banner in
> Dashboard e arricchisce la tabella in BudgetsView. Nessuna nuova tabella né migration.

## 1. Flusso attuale

- [BudgetController](../../backend/app/Http/Controllers/BudgetController.php) calcola `spent` per ogni budget (`attachSpent`, una query `SUM` per budget) sommando le `Transaction` `expense` della categoria nel mese.
- [BudgetResource](../../backend/app/Http/Resources/BudgetResource.php) espone `spent` formattato.
- [BudgetsView.vue](../../frontend/src/views/BudgetsView.vue) mostra una barra di progresso (`spent/amount`), rossa al 100%.
- [DashboardView.vue](../../frontend/src/views/DashboardView.vue) non mostra nulla sui budget.
- Non esiste alcun concetto di "alert": l'utente deve aprire la pagina Budget e leggere le barre.

## 2. Modifiche da apportare

1. `BudgetAlertService`: calcola per un periodo (anno/mese) gli alert dei budget, con un'unica query `SUM ... GROUP BY category_id`. Soglie: `warning >= 80%`, `exceeded >= 100%`; gli `ok` sono esclusi.
2. Endpoint `GET /api/budgets/alerts?year=&month=` (default mese corrente), registrato **prima** di `apiResource('budgets')`.
3. Frontend Dashboard: sezione "Alert budget" (banner) se ci sono alert del mese corrente.
4. Frontend BudgetsView: colore barra ambra a `>=80%`, rosso a `>=100%`, + badge stato.
5. Tipi frontend `BudgetAlert` + tipi reports.
6. Test del service/endpoint. Aggiornamento `AGENTS.md`.

## 3. Dettaglio dei fix

### 3.1 BudgetAlertService (`backend/app/Services/BudgetAlertService.php`)
- `const WARNING_THRESHOLD = 80.0;`
- `alerts(int $year, int $month): array`:
  - carica i `Budget` del periodo con `category`;
  - `SUM(amount) GROUP BY category_id` sulle `Transaction` `expense` del mese (whereIn sulle categorie dei budget);
  - per ogni budget: `percent = amount>0 ? round(spent/amount*100,1) : (spent>0?100:0)`; `status` = exceeded/warning/ok;
  - esclude `ok`, ordina per `percent` desc;
  - output: `{budget_id, category_id, category_name, category_color, year, month, amount, spent, percent, status}`.
- Scoping per-utente automatico via global scope su `Budget`/`Transaction`.

### 3.2 Endpoint (`BudgetController::alerts`)
- `authorize('viewAny', Budget::class)`; periodo da query o `Carbon::now()`; ritorna `['data' => $service->alerts(...)]`.
- Rotta: `Route::get('budgets/alerts', [BudgetController::class, 'alerts'])->name('budgets.alerts')` **prima** dell'`apiResource('budgets')` (evita conflitto con `/{budget}`).

### 3.3 Frontend
- `types`: `BudgetAlert` con `status: 'warning'|'exceeded'`.
- Dashboard: `GET /budgets/alerts`; se non vuoto, banner in cima con righe per categoria (percentuale + speso/budget), colore per status.
- BudgetsView: funzione `status(b)`; barra ambra `>=80`, rossa `>=100`; badge testuale.

### 3.4 Test (`tests/Feature/Api/BudgetAlertTest.php`)
- warning a 80–99%, exceeded a `>=100%`, `ok` (<80%) escluso.
- ordinamento per percent desc; periodo di default = mese corrente.
- scoping per utente; budget con `amount=0` e speso>0 → exceeded.

## 4. Impatti e possibili regressioni

> Branch di riferimento: **`master`**.

- API: 1 nuovo endpoint additivo; nessuna modifica a quelli esistenti. Nessuna migration.
- `attachSpent` resta invariato (la pagina Budget continua a funzionare uguale).
- Regressione possibile: ordine rotte — `budgets/alerts` deve precedere `apiResource` o verrebbe interpretato come `budgets/{budget}` con `budget=alerts` → 404/validation. Test esplicito.
- Coerenza `spent`: il service somma le sole `expense` della `category_id` esatta (come `attachSpent`), niente sottocategorie — comportamento già esistente, mantenuto.
- Performance: una sola query aggregata per periodo (meglio dell'N+1 di `attachSpent`).
