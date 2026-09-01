# Analisi — Preferenze di periodo e formato data

> Obiettivo: due nuove preferenze utente, configurabili in **Impostazioni**:
> 1. **Giorno di inizio del mese** (`month_start_day`, 1–28) — il "mese finanziario" può partire dal giorno di stipendio (es. 27) invece che dal 1°. Tutti i calcoli mensili (budget, report, forecast, obiettivi) e i range di default devono rispettarlo.
> 2. **Formato data** (`date_format`) — scelto dall'utente e applicato **in modo coerente in tutte le viste**.
>
> Branch di base: `master`. Documento di sola analisi — **nessuna implementazione**.
> Default che preservano il comportamento attuale: `month_start_day = 1`, `date_format = d/m/Y`.

---

## 1. Flusso attuale

### Preferenze utente (pattern di storage)
- Colonne discrete su `users`: `currency` (string 3) e `locale` (string 5) — [migration 0001_01_01_000000:20-21](../../backend/database/migrations/0001_01_01_000000_create_users_table.php). Più `notification_preferences` JSON — [migration 2026_06_13_160000](../../backend/database/migrations/2026_06_13_160000_add_notification_preferences_to_users_table.php).
- [User.php](../../backend/app/Models/User.php): `fillable` L34-41, cast `notification_preferences => array` L53, default+merge via `NOTIFICATION_DEFAULTS` L26-32 e `notificationPreferences()` L62-65.
- [UserResource.php:19-27](../../backend/app/Http/Resources/UserResource.php) espone `currency`, `locale`, `notification_preferences()`. È l'unico canale verso il frontend (`/auth/me`).
- Endpoint preferenze: [NotificationPreferenceController](../../backend/app/Http/Controllers/NotificationPreferenceController.php) `show`/`update` (merge + save), validato da [UpdateNotificationPreferencesRequest](../../backend/app/Http/Requests/User/UpdateNotificationPreferencesRequest.php). **Non esiste** un endpoint generico di profilo/preferenze.
- Frontend: tipo [User in types/api.ts:9-17](../../frontend/src/types/api.ts), store [auth.ts](../../frontend/src/stores/auth.ts) (`fetchMe` → `/auth/me`), card preferenze in [SettingsView.vue](../../frontend/src/views/SettingsView.vue) (load on mount + PUT `/notification-preferences`, L39-66).

**Nessuna preferenza di periodo o di formato data esiste oggi.**

### Mese = mese di calendario (1° → ultimo giorno) — punti da intercettare
Tutti i confini mensili sono hard-coded su Carbon `startOfMonth()/endOfMonth()`:

| File | Righe | Cosa fa |
|------|-------|---------|
| [ReportService](../../backend/app/Services/ReportService.php) | 66-69 | `periodComparison`: current/previous month |
| ReportService | 196-197 | `cashFlowForecast`: start + range |
| ReportService | 395 | net worth per-mese (`endOfMonth`) |
| ReportService | 524-529 | `monthBuckets()`: helper che costruisce i bucket mensili |
| [BudgetAlertService](../../backend/app/Services/BudgetAlertService.php) | 35-36 | periodo del budget mensile |
| [BudgetController](../../backend/app/Http/Controllers/BudgetController.php) | 109-110 | `spent` del budget (`createFromDate($year,$month,1)` → `endOfMonth`) |
| [ExpenseForecastService](../../backend/app/Services/ExpenseForecastService.php) | 82-83, 107-108, 296 | start/end forecast + parsing chiavi periodo |
| [SavingsGoalProgressService](../../backend/app/Services/SavingsGoalProgressService.php) | 61 | periodo obiettivo `monthly` |
| [ReportController](../../backend/app/Http/Controllers/ReportController.php) | 170, 174 | range di default (`startOfMonth`/`endOfMonth`) |
| [RecurringTransactionRunner](../../backend/app/Services/RecurringTransactionRunner.php) | 111 | `addMonthsNoOverflow` (cadenza, **non** confine di reporting) |

**Budget** ([Budget.php:14-15](../../backend/app/Models/Budget.php)): chiave `(user_id, category_id, year, month)` unique + index — [migration 2026_05_19_170005:15-20](../../backend/database/migrations/2026_05_19_170005_create_budgets_table.php). `year/month` sono interi: oggi identificano un mese di calendario.

Frontend, "mese corrente" costruito a mano:
- [ReportsView.vue:21-28](../../frontend/src/views/ReportsView.vue) `defaultRange()` (`new Date` + `toISOString().slice(0,10)`).
- [BudgetsView.vue:11-12](../../frontend/src/views/BudgetsView.vue) `getFullYear()` / `getMonth()+1`.
- [DashboardView.vue:68-71](../../frontend/src/views/DashboardView.vue) alert + report mese implicito.

### Formato data — oggi disomogeneo
**Non esiste** un formatter centrale (a differenza di [money.ts:12-23](../../frontend/src/lib/money.ts) per la valuta). Le date sono renderizzate in modi diversi:
- [InvestmentsView.vue](../../frontend/src/views/InvestmentsView.vue): `toLocaleDateString('it-IT', {day:'2-digit',month:'2-digit',year:'numeric'})`.
- [ForecastView.vue:270-274](../../frontend/src/views/ForecastView.vue): `toLocaleString('it-IT',{month:'short',year:'2-digit'})`.
- [TransactionsView.vue](../../frontend/src/views/TransactionsView.vue) / [StatsView.vue](../../frontend/src/views/StatsView.vue): `occurred_at` stampato grezzo (ISO `YYYY-MM-DD`).
- [SavingsGoalsView.vue](../../frontend/src/views/SavingsGoalsView.vue): `target_date` grezzo.
- [NotificationsView.vue](../../frontend/src/views/NotificationsView.vue): `created_at.slice(0,10)`.

Il backend restituisce date in ISO (`toIso8601String`, `occurred_at` ecc.): il formato di **display** è quindi una responsabilità **solo frontend**.

---

## 2. Modifiche da apportare

1. **Storage**: 2 colonne discrete su `users` — `month_start_day` (tinyint, default 1) e `date_format` (string, default `d/m/Y`). Niente JSON (coerente con `currency`/`locale`).
2. **Esposizione**: aggiungere entrambe a `User.fillable` e `UserResource`.
3. **Endpoint**: estendere il pattern preferenze — nuovo `show/update` per le preferenze di profilo (o un `ProfileController`), con Form Request dedicato (validazione `month_start_day` 1–28, `date_format` whitelist).
4. **Helper di periodo backend (unico punto di verità)**: `FinancialMonth::range($ref, $startDay)` → `[Carbon $start, Carbon $end]`. Reinstradare **tutti** i call site della tabella in §1 attraverso l'helper invece di `startOfMonth/endOfMonth`.
5. **Semantica budget**: definire la convenzione `(year, month)` → range finanziario (vedi §3.4). `month_start_day=1` = identità, nessuna migrazione dati.
6. **Helper formato data frontend (unico punto di verità)**: `lib/date.ts` `formatDate(iso)` che legge `auth.user.date_format` (mirror di `money.ts`). Reinstradare tutti i render di date in §1.
7. **Range "mese corrente" frontend**: helper `currentMonthRange()` che usa `month_start_day` per Reports/Budgets/Dashboard/Stats.
8. **UI Impostazioni**: nuova card "Periodo e data" (select giorno inizio mese 1–28, select formato data) → PUT al nuovo endpoint, refresh `auth.user`.

---

## 3. Dettaglio dei fix

### 3.1 Schema e modello
- Migration: aggiunge `month_start_day` `tinyInteger` default `1` e `date_format` `string(20)` default `'d/m/Y'` a `users`.
- [User.php](../../backend/app/Models/User.php): aggiungi le 2 colonne a `$fillable` (L34-41); cast `month_start_day => integer`. Helper `monthStartDay(): int` con clamp 1–28 per robustezza.
- [UserResource.php:19-27](../../backend/app/Http/Resources/UserResource.php): aggiungi `'month_start_day'` e `'date_format'`.
- [types/api.ts:9-17](../../frontend/src/types/api.ts): aggiungi i due campi al tipo `User`.

### 3.2 Endpoint preferenze
- Nuovo `ProfilePreferenceController` (o estensione del controller esistente) con `show`/`update`, registrato in `routes/api.php` sotto `auth:sanctum`.
- Form Request: `month_start_day` `integer|between:1,28`, `date_format` `in:<whitelist>`. La whitelist dei formati (es. `d/m/Y`, `Y-m-d`, `m/d/Y`, `d.m.Y`, `d M Y`) è una costante condivisa.
- `update` fa merge/assign + `save()`, ritorna `UserResource` aggiornata.

> **Nota cap 1–28**: si limita a 28 per evitare l'ambiguità di "31" su mesi corti (un mese che parte il 31 non esiste a febbraio). 28 copre il caso reale (stipendio 27/28). `ponytail: cap a 28; alzare solo se emerge un caso reale >28`.

### 3.3 Helper di periodo backend
Classe statica `App\Support\FinancialMonth` (o macro Carbon):
- `range(Carbon $ref, int $startDay): array` → `[$start, $end]` dove `$start` è il giorno `startDay` del ciclo che **contiene** `$ref`, `$end` = giorno prima del ciclo successivo (`endOfDay`).
- `label(Carbon $start): array` → `[year, month]` per la mappatura budget (vedi §3.4).
- `startDay=1` deve restituire **esattamente** `startOfMonth/endOfMonth` (test di identità).

Reinstradare i call site:
- [ReportService](../../backend/app/Services/ReportService.php) L66-69 (period comparison), L196-197 (forecast), L395 (net worth), L524-529 (`monthBuckets`).
- [BudgetAlertService:35-36](../../backend/app/Services/BudgetAlertService.php), [BudgetController:109-110](../../backend/app/Http/Controllers/BudgetController.php).
- [ExpenseForecastService:82-83,107-108,296](../../backend/app/Services/ExpenseForecastService.php).
- [SavingsGoalProgressService:61](../../backend/app/Services/SavingsGoalProgressService.php).
- [ReportController:170,174](../../backend/app/Http/Controllers/ReportController.php) (range default).
- **Escluso**: [RecurringTransactionRunner:111](../../backend/app/Services/RecurringTransactionRunner.php) — è la cadenza di una ricorrente, non un confine di reporting; resta invariato.

Il `month_start_day` va passato all'helper dall'utente autenticato (`Auth::user()->monthStartDay()`). I servizi vanno verificati per dipendenza implicita da `Auth` (test).

### 3.4 Semantica budget (decisione da confermare)
`budgets.(year, month)` resta la **chiave/etichetta**; cambia solo il **range** che vi corrisponde.
- Convenzione proposta: il budget `(2026, 6)` con `month_start_day=27` copre il ciclo **che inizia a giugno**, cioè `27 giu → 26 lug`.
- `month_start_day=1` → identità con oggi: nessuna migrazione, nessun cambio di significato dei record esistenti.
- Cambiare `month_start_day` **non** rinumera i budget esistenti: cambia solo la finestra di calcolo dello `spent`. Va comunicato in UI (1 riga di hint).

> **D1 — CHIUSA**: il budget `(year, month)` copre il ciclo **che inizia nel mese M** (es. `27 giu → 26 lug` per `(2026,6)`, `startDay=27`). Più vicino al modello "stipendio del 27 → spese di quel mese".

### 3.5 Formato data frontend (punto di verità unico)
- Nuovo `frontend/src/lib/date.ts`: `formatDate(value: string | Date): string` che legge `auth.user?.date_format ?? 'd/m/Y'` e applica i token (`d`,`m`,`Y`,`M`…) con sostituzione manuale (no dipendenze nuove — `new Date` + padding, ~15 righe). Stessa whitelist del backend.
- Reinstradare i render: [InvestmentsView](../../frontend/src/views/InvestmentsView.vue), [ForecastView:270-274](../../frontend/src/views/ForecastView.vue) (qui resta il formato mese/anno breve — eventuale `formatMonth`), [TransactionsView](../../frontend/src/views/TransactionsView.vue), [StatsView](../../frontend/src/views/StatsView.vue), [SavingsGoalsView](../../frontend/src/views/SavingsGoalsView.vue), [NotificationsView](../../frontend/src/views/NotificationsView.vue).

> **D2 — CHIUSA**: i campi `<input type="date">` (form) restano nativi (valore ISO, display gestito dal browser/OS); `date_format` agisce **solo** sul display in lettura, non sull'input. `ponytail: non si rimpiazza l'input nativo con un date-picker custom`.

### 3.6 Range "mese corrente" frontend
- Helper `frontend/src/lib/period.ts` `currentMonthRange(startDay)` → `{from, to}` ISO.
- Sostituire: [ReportsView:21-28](../../frontend/src/views/ReportsView.vue), [BudgetsView:11-12](../../frontend/src/views/BudgetsView.vue), [DashboardView:68-71](../../frontend/src/views/DashboardView.vue), filtri Stats. Letto da `auth.user.month_start_day`.

### 3.7 UI Impostazioni
- [SettingsView.vue](../../frontend/src/views/SettingsView.vue): nuova card "Periodo e data" — select `month_start_day` (1–28) + select `date_format` con anteprima live (es. mostra `formatDate(oggi)`). Salvataggio PUT nuovo endpoint, poi `auth.fetchMe()` per propagare ovunque in modo reattivo.

---

## 4. Impatti e possibili regressioni

Branch di riferimento per le regressioni: **`master`**.

**Backend**
- **Critico — coerenza periodi**: ogni servizio che oggi usa `startOfMonth/endOfMonth` deve passare per l'helper. Una dimenticanza → un report calcola su finestra diversa da un altro (incoerenza silenziosa). Mitigazione: grep `startOfMonth|endOfMonth` deve risultare **solo** dentro `FinancialMonth` dopo il refactor.
- **Budget**: confermata la convenzione §3.4, i record esistenti restano validi con `startDay=1`. Con `startDay≠1` lo `spent` mostrato cambia retroattivamente per i budget passati (atteso, ma da spiegare in UI). La **unique key** `(user_id,category_id,year,month)` non cambia.
- **Forecast/net worth**: i bucket mensili (`monthBuckets`, `cashFlowForecast`) cambiano confine; verificare che label periodo (`Y-m`) e finestra restino allineati.
- **Dipendenza da `Auth`**: i servizi che ora ricevono `month_start_day` dall'utente loggato vanno testati nei contesti senza utente (command schedulati, test). I command (`recurring:run`, `notifications:scan`) iterano per-utente: usare lo `startDay` del singolo utente, non un default globale.
- **Test esistenti** (feature test su report/budget/forecast) assumono mese di calendario: passano invariati con default `startDay=1`; aggiungere casi con `startDay=27`.

**Frontend**
- **Coerenza formato**: il rischio è lasciare un render fuori dal formatter. Dopo il refactor, grep `toLocaleDateString|toLocaleString|\.slice(0, 10)` sulle date deve essere vuoto (salvo `formatMonth` in ForecastView).
- `auth.user` deve essere caricato prima del primo render delle date: già garantito da `App.vue onMounted fetchMe`, ma gestire il fallback al default quando `user` è null.
- `<input type="date">`: nessun impatto (resta ISO), vedi D2.

**Decisioni chiuse**: D1 = etichetta budget `(year,month)` → ciclo che *inizia* nel mese M; D2 = `<input type="date">` nativi invariati (formato solo in display).

**Documentazione**: aggiornare `AGENTS.md` §8 (nuove colonne `users`), §8.x (nuovo endpoint preferenze) e §6/§9 (helper `FinancialMonth`, `lib/date.ts`) a implementazione fatta.
