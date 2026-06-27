# Analisi — Multitenant (workspace condivisi)

> Obiettivo: passare da isolamento **per-utente** a isolamento **per-tenant/workspace**, dove più utenti possono condividere gli stessi dati finanziari (conti, transazioni, budget, ecc.) con ruoli.
> Branch di base del progetto: `master`. Documento di sola analisi — **nessuna implementazione**.

---

## 1. Flusso attuale

Oggi l'app è **multi-utente con isolamento row-level su un solo asse: `user_id`**. Non esiste alcun concetto di tenant/team/workspace: ogni utente vede esclusivamente i propri dati.

I pilastri dello scoping sono **due file**:

- [BelongsToUser](../../backend/app/Models/Concerns/BelongsToUser.php): trait montato su ogni model di dominio. Fa due cose:
  1. `addGlobalScope(new UserScope)` in boot;
  2. su `creating`, se manca `user_id` e c'è un utente autenticato, lo popola con `Auth::id()`.
- [UserScope](../../backend/app/Models/Scopes/UserScope.php): global scope che, se `Auth::check()`, aggiunge `where('<table>.user_id', Auth::id())`.

**Model che usano il trait (12):** Account, Category, Tag, Transaction, Budget, RecurringTransaction, CategorizationRule, SavingsGoal, SavingsGoalMovement, InvestmentHolding, Scenario, ScenarioItem.

**Tabelle con `user_id` (12 di dominio + ausiliarie):** le 12 sopra. Eccezioni: `exchange_rates` è **globale** (no `user_id`, no scope); `tag_transaction` è un pivot (scoping ereditato da tag/transaction); `notifications` usa il morph standard Laravel su `User`.

**Autorizzazione:** [OwnedByUserPolicy](../../backend/app/Policies/OwnedByUserPolicy.php) → confronto `model.user_id === user.id`. Tutte le policy di dominio la estendono.

**Validazione di appartenenza:** ~20 FormRequest + 2 controller usano `Rule::exists(...)->where('user_id', Auth::id())` per garantire che gli `*_id` nel body appartengano all'utente (es. [StoreTransactionRequest](../../backend/app/Http/Requests/Transaction/StoreTransactionRequest.php), [TransactionImportExportController:76](../../backend/app/Http/Controllers/TransactionImportExportController.php), [CategorizationRuleController:96](../../backend/app/Http/Controllers/CategorizationRuleController.php)).

**Valuta base = `users.currency`:** la valuta di riferimento per *tutti* i report è una proprietà dell'**utente**, letta in 3 servizi:
- [ReportService:544](../../backend/app/Services/ReportService.php) — `Auth::user()->currency`
- [InvestmentService:22](../../backend/app/Services/InvestmentService.php)
- [ExpenseForecastService:84](../../backend/app/Services/ExpenseForecastService.php)

**Comandi schedulati** che iterano gli utenti per attivare il global scope: [ApplyCategorizationRules:31](../../backend/app/Console/Commands/ApplyCategorizationRules.php) e [ScanNotifications:25](../../backend/app/Console/Commands/ScanNotifications.php) usano `Auth::loginUsingId($userId)` / `Auth::logout()` tra iterazioni.

**Registrazione:** [AuthController::register](../../backend/app/Http/Controllers/Auth/AuthController.php) crea l'utente, esegue `CategorySeeder::seedFor($user)` (16 categorie default) e fa `Auth::login`.

**Vincoli unique che includono `user_id`:**
- `tags (user_id, name)`
- `budgets (user_id, category_id, year, month)`

**Frontend:** lo scoping è interamente server-side, quindi le view CRUD non conoscono `user_id`. L'unico punto rilevante è `users.currency` come valuta base, e lo store [auth.ts](../../frontend/src/stores/auth.ts) + tipo `User` in [types/api.ts](../../frontend/src/types/api.ts).

---

## 2. Modifiche da apportare

Approccio raccomandato: **single database, scoping row-level per `tenant_id`** (evoluzione naturale dell'attuale `user_id`). **No** DB-per-tenant / librerie pesanti (stancl/tenancy): lo scoping è già centralizzato in 2 file, il costo/beneficio non lo giustifica.

Schema sintetico delle modifiche:

1. **Nuove tabelle**: `tenants` (con `currency` spostata qui) e `tenant_user` (membership con `role`).
2. **Nuovo asse di scoping**: rinominare `user_id` → `tenant_id` su tutte le 12 tabelle di dominio (migration con backfill).
3. **Contesto "tenant corrente"**: singleton `CurrentTenant` + middleware che lo risolve dalla sessione e ne valida la membership.
4. **Swap dello scoping**: `BelongsToUser` → `BelongsToTenant`, `UserScope` → `TenantScope` (legge `CurrentTenant` invece di `Auth::id()`).
5. **Policy**: `OwnedByUserPolicy` → `OwnedByTenantPolicy` (confronto su `tenant_id` + verifica membership/ruolo).
6. **Validazione**: i ~20 `Rule::exists(...)->where('user_id', ...)` → `->where('tenant_id', currentTenantId())`.
7. **Valuta base**: i 3 servizi passano da `Auth::user()->currency` a `currentTenant->currency`.
8. **Comandi schedulati**: il loop "per utente + `loginUsingId`" diventa "per tenant + `setCurrentTenant`".
9. **Registrazione & migrazione dati**: ogni utente esistente ottiene un **tenant personale** (lui owner) con i suoi dati ribattezzati; il seeder categorie passa al tenant.
10. **API tenant**: endpoint per leggere le membership, cambiare tenant attivo, gestire membri/ruoli.
11. **Frontend**: store con `tenants` + `activeTenant`, selettore tenant, valuta base dal tenant, pagina gestione membri.
12. **Inviti** (fase a parte / differibile): flusso invito via email + accettazione.

---

## 3. Dettaglio dei fix

### 3.1 Schema dati

**`tenants`**
- `id`, `name`, `currency` (3, default `EUR`) ← spostata da `users`, `created_at/updated_at`.
- (Opzionale futuro) `locale`, settings JSON.

**`tenant_user`** (pivot membership)
- `tenant_id` (cascade), `user_id` (cascade), `role` enum (`owner`/`member`; eventuale `viewer`), unique `(tenant_id, user_id)`.

**12 tabelle di dominio**: rinominare `user_id` → `tenant_id` (FK su `tenants`, `cascadeOnDelete`). Migration in due tempi per non perdere dati:
1. crea `tenants` + `tenant_user`;
2. per ogni `user` crea un tenant personale, inserisce membership `owner`, copia `users.currency` nel tenant;
3. aggiunge `tenant_id` alle 12 tabelle, lo popola dal vecchio `user_id` (mappa user→tenant personale), poi droppa `user_id`.

> **Decisione**: rinominare (asse unico, più pulito) vs. affiancare `tenant_id` lasciando `user_id` come `created_by`. Raccomando **rinominare**; aggiungere `created_by` (nullable) **solo se** serve audit/“chi ha creato” in UI — altrimenti YAGNI.

**Vincoli unique**: `tags (user_id, name)` → `(tenant_id, name)`; `budgets (user_id, category_id, year, month)` → `(tenant_id, ...)`.

**Invariati**: `exchange_rates` (globale), `tag_transaction` (pivot), `notifications` (resta per-**utente**: ogni membro ha la sua lista), `users.notification_preferences` (resta per-utente).

### 3.2 Contesto tenant corrente

- **`App\Tenancy\CurrentTenant`**: singleton bindato nel container, espone `id()`, `get()`, `set(Tenant)`, `forget()`.
- **Middleware `SetCurrentTenant`** (nel gruppo `auth:sanctum`): legge `session('tenant_id')`, verifica che l'utente sia membro (`tenant_user`), e setta `CurrentTenant`. Se assente/non valido → fallback al primo tenant dell'utente (o 409/403 se l'utente non ha tenant). Per richieste API senza tenant valido → risposta JSON 409 “tenant non selezionato”.

### 3.3 Swap dello scoping (cuore della modifica, 2 file)

- **`TenantScope`** (da `UserScope`): `if ($tid = app(CurrentTenant::class)->id()) $builder->where('<table>.tenant_id', $tid);`
- **`BelongsToTenant`** (da `BelongsToUser`): global scope `TenantScope` + su `creating` autofill `tenant_id` dal `CurrentTenant`. La relazione `user()` diventa `tenant()`.
- Aggiornare i 12 model (cambio `use`), e ovunque si usi `->user()` su questi model per risalire al proprietario.

### 3.4 Policy

- `OwnedByTenantPolicy`: `owns()` confronta `model.tenant_id === currentTenant.id` **e** verifica che l'utente sia membro. Per le azioni di scrittura (`update`/`delete`) eventualmente richiedere ruolo ≥ `member` (un `viewer` sarebbe read-only).

### 3.5 Validazione FormRequest / controller

- Sostituire in ~22 punti `->where('user_id', Auth::id())` con `->where('tenant_id', app(CurrentTenant::class)->id())`. Elenco file in sez. 4.

### 3.6 Valuta base

- I 3 servizi leggono `app(CurrentTenant::class)->get()->currency` invece di `Auth::user()->currency`. Tutta la catena report/forecast/investment eredita automaticamente.
- `summary`/`periodComparison` che espongono `base_currency` → dalla valuta del tenant.

### 3.7 Comandi schedulati

- [ApplyCategorizationRules](../../backend/app/Console/Commands/ApplyCategorizationRules.php) e [ScanNotifications](../../backend/app/Console/Commands/ScanNotifications.php): il loop passa da “per utente” a “per tenant” con `CurrentTenant::set($tenant)` / `forget()`.
- **ScanNotifications — decisione**: oggi notifica l'utente. Con i tenant, per ogni tenant a rischio budget/goal **a chi** mando la notifica? Raccomando: a **tutti i membri** del tenant che hanno il toggle relativo attivo (`notification_preferences` resta per-utente). Da confermare.

### 3.8 Registrazione

- [AuthController::register](../../backend/app/Http/Controllers/Auth/AuthController.php): dopo aver creato l'utente, creare un **tenant personale**, inserire membership `owner`, settare `CurrentTenant`, eseguire `CategorySeeder::seedFor($tenant)` (firma da adeguare a tenant), settare `session('tenant_id')`.
- `CategorySeeder::seedFor` e `DatabaseSeeder` (utente demo + 2 conti): adeguare a tenant.

### 3.9 API tenant (nuove rotte)

- `GET /api/tenants` — tenant di cui l'utente è membro (con ruolo).
- `POST /api/tenants/switch` — `{tenant_id}`, valida membership, scrive `session('tenant_id')`.
- `GET/POST/PATCH/DELETE /api/tenants` — CRUD tenant (rename, currency); solo `owner`.
- `GET/POST/DELETE /api/tenants/{tenant}/members` — gestione membri/ruoli; solo `owner`.
- `/api/auth/me` arricchito con `tenants[]` + `active_tenant`.

### 3.10 Frontend

- [types/api.ts](../../frontend/src/types/api.ts): nuovo tipo `Tenant`/`Membership`; `User` con `tenants[]` + `active_tenant`; valuta base presa dal tenant attivo, non più da `user.currency`.
- [auth.ts](../../frontend/src/stores/auth.ts): stato `tenants` + `activeTenant`, azione `switchTenant`.
- Selettore tenant (in [AppLayout](../../frontend/src/components/AppLayout.vue), es. dropdown in topbar). Dopo lo switch → reload dei dati (gli store di dominio vanno invalidati).
- [SettingsView](../../frontend/src/views/SettingsView.vue): card gestione tenant (nome, valuta) + membri/ruoli (e inviti, se inclusi).

### 3.11 Inviti (fase differibile)

- Tabella `tenant_invitations` (`tenant_id`, `email`, `role`, `token`, `expires_at`, `accepted_at`).
- Flusso: owner invita per email → notifica con link SPA → destinatario (registrato o no) accetta → crea membership.
- **MVP senza inviti**: l'owner aggiunge un membro indicando l'email di un utente **già registrato** → membership immediata. Più semplice, copre il caso “famiglia” se sono già tutti registrati.

---

## 4. Impatti e possibili regressioni

Analisi rispetto al branch di base **`master`**.

### File impattati (inventario)

| Area | File | Tipo intervento |
|------|------|-----------------|
| Scoping core | [BelongsToUser](../../backend/app/Models/Concerns/BelongsToUser.php), [UserScope](../../backend/app/Models/Scopes/UserScope.php) | Riscrittura → tenant |
| Model (12) | Account, Category, Tag, Transaction, Budget, RecurringTransaction, CategorizationRule, SavingsGoal, SavingsGoalMovement, InvestmentHolding, Scenario, ScenarioItem | Cambio trait + relazione |
| Policy | [OwnedByUserPolicy](../../backend/app/Policies/OwnedByUserPolicy.php) + per-model | `tenant_id` + membership |
| FormRequest (~20) | Budget, Category, CategorizationRule, InvestmentHolding, RecurringTransaction, SavingsGoal(+Movement), Scenario(+Item), Tag, Transaction (Store+Update) | `Rule::exists` → `tenant_id` |
| Controller | [TransactionImportExportController:76](../../backend/app/Http/Controllers/TransactionImportExportController.php), [CategorizationRuleController:96](../../backend/app/Http/Controllers/CategorizationRuleController.php) | `Rule::exists` → `tenant_id` |
| Servizi | [ReportService:544](../../backend/app/Services/ReportService.php), [InvestmentService:22](../../backend/app/Services/InvestmentService.php), [ExpenseForecastService:84](../../backend/app/Services/ExpenseForecastService.php) | Valuta base dal tenant |
| Comandi | [ApplyCategorizationRules](../../backend/app/Console/Commands/ApplyCategorizationRules.php), [ScanNotifications](../../backend/app/Console/Commands/ScanNotifications.php) | Loop per tenant |
| Auth | [AuthController](../../backend/app/Http/Controllers/Auth/AuthController.php) | Crea tenant personale al register |
| Migrazioni | 12 create-table + nuova migration tenants/membership/backfill | Rinomina colonna + backfill |
| Seeder | CategorySeeder, DatabaseSeeder | Firma a tenant |
| Bootstrap | [bootstrap/app.php](../../backend/bootstrap/app.php) | Registrare middleware `SetCurrentTenant` |
| Frontend | types/api.ts, stores/auth.ts, AppLayout.vue, SettingsView.vue | Tenant switch + valuta base |

### Regressioni da verificare

1. **Leak cross-tenant** (rischio massimo): se il `CurrentTenant` non è settato (job, comando, test, richiesta senza middleware), il `TenantScope` non filtra → un model potrebbe vedere/scrivere dati di tutti. Mitigazione: in contesto non-HTTP **fallire esplicitamente** se non c'è tenant attivo (a differenza dell'attuale `if Auth::check()` che semplicemente non filtra). Da decidere caso per caso (i comandi setteranno il tenant nel loop).
2. **Backfill migration**: la copia `user_id → tenant_id` deve essere atomica e idempotente; un errore lascia dati orfani. Testare su dump reale prima del deploy. Prevedere `down()`.
3. **Autofill in creazione**: oggi `BelongsToUser` riempie `user_id` da `Auth::id()`. Con tenant, se il `CurrentTenant` non è settato in qualche path di creazione (es. seeder, command) si crea un record senza `tenant_id` → errore FK o record invisibile.
4. **Notifiche** (`notifications` resta per-utente, ma lo **scanner** gira per tenant): rischio doppioni o invii al membro sbagliato. La dedup key attuale (`budget:{status}:{id}:{period}`) non include l'utente: con più membri va estesa a `{tenant}:{user}:...` o la dedup va per-utente.
5. **Sessione & Sanctum**: `session('tenant_id')` deve sopravvivere ai vari path (login, switch). Dopo `switchTenant` il frontend deve invalidare tutte le cache/store di dominio, altrimenti mostra dati del tenant precedente.
6. **Vincoli unique**: budget/tag passano a `(tenant_id, ...)`; verificare che il backfill non generi collisioni (improbabile, ma due utenti con tag uguale finiti in tenant diversi = ok; stesso tenant = collisione da gestire).
7. **Valuta base**: ogni punto che mostra `base_currency` o converte deve usare la valuta del tenant; un utente membro di più tenant con valute diverse vedrà numeri diversi a seconda del tenant attivo — atteso, ma da comunicare nella UI.
8. **Test (PHPUnit, SQLite in-memory)**: tutti i feature test presuppongono scoping per-utente con `actingAs`. Andranno adeguati a creare un tenant + membership e settare il `CurrentTenant`. È la quota di lavoro più sottovalutata: ~tutti i feature test esistenti vanno ritoccati.
9. **`/api/auth/me` e router guard frontend**: un utente senza tenant (caso limite post-migrazione o invito revocato) non deve restare bloccato; serve uno stato “nessun workspace”.
10. **Larastan livello 5**: i nuovi `app(CurrentTenant::class)` e i cambi di relazione (`user()`→`tenant()`) richiedono annotazioni `@property` aggiornate sui model.

### Stima per fasi (proposta)

| Fase | Contenuto | Note |
|------|-----------|------|
| **M1 — Schema & contesto** | tabelle tenants/membership, `CurrentTenant`, middleware, migration+backfill, swap scope (2 file), policy | Cuore: senza UI, app funziona “1 tenant per utente” |
| **M2 — API & frontend tenant** | rotte tenant/switch/members, `me` arricchito, store + selettore tenant, valuta base dal tenant | Rende usabile il multi-membership |
| **M3 — Inviti** (opzionale) | invitations + flusso email + accettazione | Differibile: MVP = aggiungi membro già registrato |
| **M4 — Hardening** | adeguamento test, dedup notifiche per-utente, Larastan, ADR | Necessaria prima del merge |

### Decisioni aperte (da confermare prima di M1)

- **D1** — Rinominare `user_id`→`tenant_id` (raccomandato) **oppure** affiancare `tenant_id` + `created_by`?
- **D2** — Valuta base sul **tenant** (raccomandato) o resta sull'utente?
- **D3** — Ruoli: solo `owner`/`member` (raccomandato) o anche `viewer` read-only?
- **D4** — Scanner notifiche: notificare **tutti i membri** (raccomandato) o solo l'owner?
- **D5** — Inviti in scope ora (M3) o MVP “aggiungi utente già registrato”?

> Decisione architetturale rilevante: alla conferma dell'approccio creare un ADR in `docs/adr/NNNN-multitenant.md` (sez. 9.6 di AGENTS.md).
