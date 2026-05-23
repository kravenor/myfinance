# AGENTS.md — Mappa del progetto per agenti AI

> Questo documento è la **fonte di verità** per qualsiasi agente AI (Claude Code, Codex, Cursor, ecc.) che lavora su questo repository.
> Mantienilo aggiornato a ogni modifica strutturale, ogni nuova fase completata, ogni nuova convenzione introdotta.

Ultimo aggiornamento: **2026-05-23**
Fase corrente: **Fase 8 — Import/Export CSV (COMPLETATA)**

---

## 1. Visione

Web application personale per la gestione delle finanze:
- Tracking conti, transazioni, categorie
- Budget mensili e transazioni ricorrenti
- Dashboard con report e grafici
- Import/Export (CSV estratti conto)

Uso single-tenant (un utente principale), ma con multi-user scoping già a livello di dati.

## 2. Stack tecnologico

| Componente | Tecnologia | Versione |
|------------|------------|----------|
| Backend API | Laravel | 11.x |
| Auth | Laravel Sanctum (SPA cookie) | — |
| Frontend | Vue 3 + TypeScript | — |
| Build frontend | Vite | — |
| State management | Pinia | — |
| Router | Vue Router | — |
| CSS | TailwindCSS | — |
| DB | MySQL | 8.4 |
| Cache/Queue | Redis | 7 |
| Web server | Nginx | 1.27 |
| PHP runtime | PHP-FPM | 8.3 (Alpine) |
| Node runtime | Node | 20 (Alpine) |
| Orchestrazione | Docker Compose | v2 |

## 3. Struttura del repository

```
Finance/
├── AGENTS.md              # ← questo file (mappa per AI)
├── CLAUDE.md              # ← istruzioni specifiche per Claude Code
├── README.md              # documentazione utente
├── Makefile               # comandi shortcut
├── docker-compose.yml     # orchestrazione servizi
├── .env.example           # template variabili host
├── .env                   # variabili reali (gitignored)
├── .gitignore
│
├── backend/               # progetto Laravel 11
│   ├── app/Models/        # User, Account, Category, Transaction, Budget, RecurringTransaction, Tag
│   │   ├── Concerns/      # BelongsToUser (trait: global scope + autofill user_id)
│   │   └── Scopes/        # UserScope (global scope su Auth::id())
│   ├── app/Http/
│   │   ├── Controllers/        # Account, Category, Tag, Transaction, Budget, RecurringTransaction
│   │   ├── Controllers/Auth/   # AuthController (register/login/logout/me)
│   │   ├── Requests/Auth/      # RegisterRequest, LoginRequest (con throttle)
│   │   ├── Requests/Account/   # Store/UpdateAccountRequest
│   │   ├── Requests/Category/  # Store/UpdateCategoryRequest (validazione parent_id + ciclo)
│   │   ├── Requests/Tag/       # Store/UpdateTagRequest (unique per user)
│   │   ├── Requests/Transaction/  # Store/UpdateTransactionRequest (transfer rules, owned-by-user)
│   │   ├── Requests/Budget/    # Store/UpdateBudgetRequest (unique categoria/anno/mese)
│   │   ├── Requests/RecurringTransaction/  # Store/UpdateRecurringTransactionRequest (transfer + cadence)
│   │   └── Resources/          # UserResource + Account/Category/Tag/Transaction/Budget/RecurringTransactionResource
│   ├── app/Services/           # RecurringTransactionRunner (materializza ricorrenti maturate)
│   ├── app/Console/Commands/   # RunRecurringTransactions (`recurring:run [--date=]`)
│   ├── app/Policies/      # OwnedByUserPolicy + per-model policies
│   ├── database/migrations/
│   ├── database/factories/  # User/Account/Category/Tag/Transaction/Budget/RecurringTransactionFactory
│   ├── database/seeders/  # DatabaseSeeder, CategorySeeder (seedFor pubblico)
│   ├── routes/api.php     # rotte API (Sanctum SPA)
│   ├── bootstrap/app.php  # statefulApi() abilitato
│   ├── .env               # config (mysql, redis, sanctum)
│   └── ...
│
├── frontend/              # progetto Vue 3 + TypeScript + Vite
│   ├── Dockerfile         # immagine Node per dev
│   ├── package.json       # vue, vue-router, pinia, axios, tailwindcss
│   ├── vite.config.ts     # alias @, host 0.0.0.0, HMR via nginx :8080
│   ├── tailwind.config.js
│   ├── postcss.config.js
│   ├── tsconfig.json
│   ├── index.html
│   └── src/
│       ├── main.ts            # bootstrap (Pinia + Router)
│       ├── App.vue            # root + onMounted fetchMe
│       ├── style.css          # Tailwind directives + componenti (btn, input, card, table)
│       ├── lib/api.ts         # axios client (withCredentials, withXSRFToken, ensureCsrf)
│       ├── types/api.ts       # tipi: User, Account, Category, Tag, Transaction, Budget, RecurringTransaction, Paginated
│       ├── stores/auth.ts     # Pinia: user, login, register, logout, fetchMe
│       ├── composables/useCrud.ts  # list/create/update/destroy generico
│       ├── router/index.ts    # routes lazy + guard requiresAuth/guest
│       ├── components/AppLayout.vue
│       └── views/             # Login, Register, Dashboard, Accounts, Categories, Tags, Transactions, Budgets, Recurring
│
├── docker/
│   ├── php/
│   │   ├── Dockerfile     # immagine PHP-FPM custom
│   │   └── php.ini        # tuning PHP
│   ├── nginx/
│   │   └── default.conf   # vhost reverse-proxy + Laravel
│   └── mysql/             # eventuali init.sql/conf
│
└── docs/                  # documentazione aggiuntiva (ADR, schema DB)
```

## 4. Servizi Docker

| Servizio | Container | Porta host | Porta interna | Ruolo |
|----------|-----------|------------|---------------|-------|
| `nginx` | `finance_nginx` | `${APP_PORT:-8080}` | 80 | Entry point HTTP, reverse-proxy a Vite + FastCGI a PHP |
| `php` | `finance_php` | — | 9000 | PHP-FPM, Laravel |
| `node` | `finance_node` | — | 5173 | Vite dev server (proxato da nginx) |
| `mysql` | `finance_mysql` | `${DB_PORT:-3306}` | 3306 | Database |
| `redis` | `finance_redis` | — | 6379 | Cache, queue, sessioni |

**Routing nginx**:
- `/api/*`, `/sanctum/*`, `/storage/*` → Laravel (PHP-FPM)
- Tutto il resto → Vite dev server (Vue SPA)

In **produzione** sostituire il proxy a Vite con il servizio di file statici buildati da `npm run build` (da pianificare in Fase 9).

## 5. Comandi essenziali

Tutti via `make`:

```bash
make up              # avvia stack
make down            # ferma stack
make logs            # tail log
make shell-php       # shell nel container PHP
make shell-node      # shell nel container Node
make shell-mysql     # client MySQL

make laravel-new     # bootstrap Laravel in backend/ (una tantum)
make vue-new         # bootstrap Vue in frontend/ (una tantum)

make composer-install
make migrate
make fresh           # migrate:fresh --seed
make seed
make test
make pint
```

## 6. Convenzioni di sviluppo

### Backend (Laravel)
- **Architettura**: Controller sottile → Service (business logic) → Repository/Eloquent
- **API**: tutte le rotte sotto `/api`, versionate `routes/api.php`
- **Validazione**: Form Request, mai inline nel controller
- **Response**: API Resources, niente array grezzi
- **Auth**: Sanctum SPA cookie (no token bearer per il frontend principale). Nei controller proteggere `session()` con `$request->hasSession()` per supportare client non-stateful e test.
- **Scoping**: modelli di dominio usano il trait `App\Models\Concerns\BelongsToUser` che applica `UserScope` (filtra per `Auth::id()` se autenticato) e auto-popola `user_id` in creazione. Le policy estendono `App\Policies\OwnedByUserPolicy`.
- **Code style**: Laravel Pint (preset `laravel`)
- **Test**: PHPUnit / Pest, feature test per ogni endpoint. Test DB su SQLite in-memory (vedi `phpunit.xml`).

### Frontend (Vue)
- **TypeScript** obbligatorio
- **Composition API** + `<script setup>`
- **State**: Pinia store per dominio (auth, accounts, transactions, …)
- **HTTP**: client axios centralizzato in `src/lib/api.ts` con interceptor CSRF e gestione errori
- **Routing**: lazy import per ogni route
- **Stile**: Tailwind utility-first, componenti riusabili in `src/components/ui/`

### Git
- Branch: `main` (stabile), feature branch `feat/...`, `fix/...`
- Commit: conventional commits (`feat:`, `fix:`, `chore:`, `docs:`, `refactor:`)

## 7. Stato delle fasi (roadmap)

- [x] **Fase 1** — Setup infrastruttura Docker
- [x] **Fase 2** — Backend foundation (Laravel 11, Sanctum, migrazioni base, model, seeder)
- [x] **Fase 3** — Auth & utenti (controller register/login/logout/me, policy, global scope per user_id)
- [x] **Fase 4** — CRUD conti e transazioni (Account, Category, Tag, Transaction con filtri, tags sync, transfer rules)
- [x] **Fase 5** — Budget & transazioni ricorrenti (CRUD + RecurringTransactionRunner + schedule `recurring:run` giornaliero)
- [x] **Fase 6** — Frontend Vue (bootstrap, auth flow Sanctum SPA, layout + pagine CRUD per tutte le entità)
- [x] **Fase 7** — Dashboard & report (endpoint /api/reports/*, Dashboard KPI + grafici, /reports view)
- [x] **Fase 8** — Import/Export (CSV export, import con preview + mapping colonne)
- [ ] **Fase 9** — Qualità, CI, deploy

## 8. Schema dati (implementato in Fase 2)

Tutte le tabelle di dominio hanno `user_id` con `cascadeOnDelete`. Importi `decimal(15,2)`.

| Tabella | Campi principali |
|---------|------------------|
| `users` | `name`, `email` (unique), `password`, `currency` (default `EUR`), `locale` (default `it`) |
| `personal_access_tokens` | Sanctum |
| `accounts` | `name`, `type` (cash/bank/card/investment/other), `currency`, `initial_balance`, `color`, `icon`, `is_archived`, `include_in_net_worth`, `notes` |
| `categories` | `parent_id` (self), `name`, `type` (income/expense), `color`, `icon`, `is_archived`, `sort_order` |
| `tags` | `name`, `color` — unique per `(user_id, name)` |
| `recurring_transactions` | `account_id`, `category_id`, `transfer_account_id`, `type`, `amount`, `currency`, `description`, `cadence` (daily/weekly/biweekly/monthly/quarterly/yearly), `interval`, `starts_on`, `ends_on`, `next_run_at`, `last_run_at`, `is_active` |
| `transactions` | `account_id`, `category_id`, `transfer_account_id`, `recurring_transaction_id`, `type`, `amount`, `currency`, `occurred_at`, `description`, `notes`, `external_id` |
| `budgets` | `category_id`, `year`, `month`, `amount` — unique per `(user_id, category_id, year, month)` |
| `tag_transaction` | pivot `transaction_id` + `tag_id` (convenzione Laravel alfabetica) |

### Eloquent models e relazioni

- **User** → hasMany Account, Category, Transaction, Budget, RecurringTransaction, Tag
- **Account** → belongsTo User, hasMany Transaction
- **Category** → belongsTo User, parent (self), hasMany children/transactions/budgets
- **Transaction** → belongsTo User/Account/Category/transferAccount/recurringTransaction, belongsToMany Tag
- **Budget** → belongsTo User/Category
- **RecurringTransaction** → belongsTo User/Account/Category/transferAccount, hasMany Transaction
- **Tag** → belongsTo User, belongsToMany Transaction

### Seeder

`CategorySeeder` popola 11 categorie di spesa + 5 di entrata per ogni utente. Espone `seedFor(User)` riusato da `AuthController::register`.
`DatabaseSeeder` crea un utente demo `demo@finance.local` / `password` + 2 conti di esempio.

## 8.1 Endpoint Auth (Fase 3)

| Metodo | Path | Middleware | Note |
|--------|------|------------|------|
| GET | `/sanctum/csrf-cookie` | — | Pre-flight CSRF (gestito da Sanctum) |
| POST | `/api/auth/register` | — | Crea utente, esegue `CategorySeeder::seedFor`, fa login, ritorna `UserResource` (201) |
| POST | `/api/auth/login` | — | Throttle 5 tentativi/IP+email, ritorna `UserResource` |
| POST | `/api/auth/logout` | `auth:sanctum` | Logout web + sanctum, invalida sessione, 204 |
| GET | `/api/auth/me` | `auth:sanctum` | Ritorna utente corrente |

## 8.2 Endpoint CRUD (Fase 4)

Tutte le rotte sotto `auth:sanctum`. Index in paginazione (default 25, override `?per_page`).

### Accounts — `apiResource('accounts')`
| Metodo | Path | Query / Body |
|--------|------|--------------|
| GET | `/api/accounts` | filtri `type`, `archived` (bool) |
| POST | `/api/accounts` | `name`, `type` (cash/bank/card/investment/other), `currency`, `initial_balance`, ... |
| GET | `/api/accounts/{account}` | — |
| PATCH/PUT | `/api/accounts/{account}` | campi `sometimes` |
| DELETE | `/api/accounts/{account}` | 204 |

### Categories — `apiResource('categories')`
| Metodo | Path | Note |
|--------|------|------|
| GET | `/api/categories` | filtri `type` (income/expense), `archived`, `parent_id` |
| POST | `/api/categories` | `parent_id` deve essere stesso utente + stesso `type` |
| PATCH | `/api/categories/{category}` | impedito `parent_id == self` e cicli gerarchici |
| DELETE | `/api/categories/{category}` | transazioni associate mantengono `category_id` null (FK `nullOnDelete`) |

### Tags — `apiResource('tags')`
| Metodo | Path | Note |
|--------|------|------|
| GET | `/api/tags` | — |
| POST/PATCH | `/api/tags`, `/api/tags/{tag}` | `name` unique per `user_id` |
| DELETE | `/api/tags/{tag}` | rimuove le associazioni pivot a cascata |

### Transactions — `apiResource('transactions')`
| Metodo | Path | Note |
|--------|------|------|
| GET | `/api/transactions` | filtri `account_id` (anche transfer_account_id), `category_id`, `type`, `from`, `to` (date), `tag_id`. Sort `occurred_at` DESC, eager-load `tags` |
| POST | `/api/transactions` | `account_id`, `type`, `amount` (>0), `occurred_at`, `category_id?`, `transfer_account_id?` (richiesto se `type=transfer`, diverso da `account_id`), `tag_ids?` |
| PATCH | `/api/transactions/{transaction}` | sync `tags` se `tag_ids` presente nel body |
| DELETE | `/api/transactions/{transaction}` | 204 |

Validazione di appartenenza: tutti i `*_id` riferiti a risorse di dominio passano per `Rule::exists` filtrato su `Auth::id()`.

## 8.3 Endpoint Budget & Ricorrenti (Fase 5)

### Budgets — `apiResource('budgets')`
| Metodo | Path | Note |
|--------|------|------|
| GET | `/api/budgets` | filtri `year`, `month`, `category_id`. Ogni risorsa include `spent` = somma `expense` per (category, year, month) |
| POST | `/api/budgets` | unique (`user_id`, `category_id`, `year`, `month`) verificato in validation + DB |
| PATCH | `/api/budgets/{budget}` | stessa unicità in update |
| DELETE | `/api/budgets/{budget}` | 204 |

### Recurring transactions — `apiResource('recurring-transactions')`
| Metodo | Path | Note |
|--------|------|------|
| GET | `/api/recurring-transactions` | filtri `account_id`, `type`, `active`. Ordinato per `next_run_at` |
| POST | `/api/recurring-transactions` | obbligatori `account_id`, `type`, `amount`, `cadence`, `starts_on`. `interval` default 1, `next_run_at` default `starts_on`, `is_active` default true. Stesse regole transfer di Transaction |
| PATCH | `/api/recurring-transactions/{recurring_transaction}` | parametro di rotta `recurring_transaction` |
| DELETE | `/api/recurring-transactions/{recurring_transaction}` | 204 |

### Runner ricorrenti

- Service `App\Services\RecurringTransactionRunner::run(?Carbon $until)`: cicla su tutte le ricorrenti attive con `next_run_at <= $until`, materializza Transaction collegate (`recurring_transaction_id` impostato), aggiorna `last_run_at`, calcola `next_run_at` secondo `cadence`/`interval` (`daily/weekly/biweekly/monthly/quarterly/yearly`, `*NoOverflow` per evitare salti di mese). Se `ends_on` superato → `is_active=false`. Itera finché c'è backlog.
- Command Artisan `php artisan recurring:run [--date=YYYY-MM-DD]`.
- Schedule giornaliero in [routes/console.php](backend/routes/console.php) alle 02:00 (richiede `php artisan schedule:work` o cron `php artisan schedule:run` ogni minuto in produzione — da pianificare in Fase 9).

## 9. Frontend (Fase 6)

### Stack runtime
- Vue 3 + `<script setup>` + TypeScript, Vite 5, Pinia, Vue Router 4, Axios, TailwindCSS 3.
- Container `node` espone `:5173`, nginx proxa la root a Vite e `/api/*`,`/sanctum/*` a Laravel.

### Auth (Sanctum SPA cookie)
1. Allo startup `App.vue` chiama `auth.fetchMe()` per ripristinare la sessione.
2. Il primo POST/PUT/PATCH/DELETE invoca `ensureCsrf()` che fa GET `/sanctum/csrf-cookie`.
3. axios è configurato con `withCredentials: true` e `withXSRFToken: true`: invia automaticamente `X-XSRF-TOKEN` letto dal cookie.
4. Login/register settano `user` nello store, logout azzera lo stato.
5. Router guard:
   - `requiresAuth` → redirect a `/login?redirect=…` se non autenticato.
   - `guest` (login/register) → redirect a `/` se già autenticato.

### Rotte frontend
| Path | View | Note |
|------|------|------|
| `/login` | LoginView | precompila `demo@finance.local` / `password` per il seed locale |
| `/register` | RegisterView | conferma password obbligatoria |
| `/` | DashboardView | cards conti + ultime 5 transazioni |
| `/accounts` | AccountsView | CRUD inline |
| `/categories` | CategoriesView | CRUD + parent select filtrato per type |
| `/tags` | TagsView | CRUD + swatch colore |
| `/transactions` | TransactionsView | CRUD + filtri (conto, type, range date), supporto transfer |
| `/budgets` | BudgetsView | filtro anno/mese, progresso barra con `spent / amount` |
| `/recurring` | RecurringView | CRUD ricorrenti, mostra `next_run_at` e flag `is_active` |

### Fix backend collegati
- `routes/web.php` espone una rotta nominata `login` che ritorna JSON 401 (evita `RouteNotFoundException` quando `auth:sanctum` cerca di redirigere richieste non-JSON).
- `bootstrap/app.php`: `shouldRenderJsonWhen` e custom render per `AuthenticationException` su path `api/*`.

## 10. Report & dashboard (Fase 7)

### Endpoint (`auth:sanctum`)
Tutti i range accettano `?from=YYYY-MM-DD&to=YYYY-MM-DD`; se omessi: default mese corrente (summary/by-category) o ultimi 12 mesi (timeline/net-worth).

| Metodo | Path | Risposta |
|--------|------|----------|
| GET | `/api/reports/summary` | `{from, to, income, expense, net, net_worth, accounts: [{id, name, currency, balance}]}` |
| GET | `/api/reports/by-category?type=expense\|income` | `[{category_id, category_name, total}]` ordinato per total desc |
| GET | `/api/reports/timeline` | `[{period: "YYYY-MM", income, expense, net}]` |
| GET | `/api/reports/net-worth` | `[{period: "YYYY-MM", net_worth}]` cumulato (initial_balance + Σ income - Σ expense fino a fine mese) |

Logica in [ReportService](backend/app/Services/ReportService.php). Saldo per conto = `initial_balance + Σ income (account_id) - Σ (expense+transfer con account_id) + Σ transfer con transfer_account_id`. Le transfer si compensano nel net worth aggregato e quindi sono escluse dal cumulato.

### Frontend
- Libreria: `chart.js` + `vue-chartjs`.
- [DashboardView](frontend/src/views/DashboardView.vue): 4 KPI cards (income/expense/net mese + patrimonio netto), saldi conti, donut categorie del mese, bar income vs expense 12 mesi.
- [ReportsView](frontend/src/views/ReportsView.vue) (`/reports`): filtri data + type categoria, donut by-category, bar timeline, line net-worth, tabella categorie.

## 11. Import / Export CSV (Fase 8)

### Endpoint (`auth:sanctum`)
| Metodo | Path | Risposta / Body |
|--------|------|-----------------|
| GET | `/api/transactions/export` | Stream `text/csv` con header `Content-Disposition: attachment`. Filtri: `account_id`, `type`, `from`, `to`. Colonne: `occurred_at,type,amount,currency,account,transfer_account,category,description,notes,external_id` |
| POST | `/api/transactions/import/preview` | multipart `file` (CSV ≤ 5MB). Ritorna `{headers, sample (max 10 righe), suggested: {date, amount, description, type, category}}` |
| POST | `/api/transactions/import` | multipart `file`, `account_id`, `mapping[date]`, `mapping[amount]`, `mapping[description]?`, `mapping[type]?`, `mapping[category]?`, `date_format?` (default `Y-m-d`), `currency?`. Ritorna `{imported, skipped, errors: [{row, message}]}` |

### Logica
- [TransactionExportService](backend/app/Services/TransactionExportService.php): stream via `php://output` con `fputcsv`, chunk 500, scoping per user via global scope.
- [TransactionImportService](backend/app/Services/TransactionImportService.php): auto-detect delimitatore (`,`, `;`, `\t`), parse importo in stile italiano (`1.234,56`) e standard, inferenza `type` da segno (negativo→expense, positivo→income), match categoria per nome (case-insensitive) sull'utente corrente. Righe vuote ignorate, errori per riga raccolti senza interrompere il batch.
- `mapping` suggerito su euristica per chiavi `data/date/occurred`, `importo/amount/value`, `descrizione/description/causale/memo`, `tipo/type`, `categoria/category`.

### Frontend
- [ImportExportView.vue](frontend/src/views/ImportExportView.vue) — accessibile da `/import-export` nella sidebar.
- Export: filtri (conto/tipo/range), download diretto del blob CSV.
- Import: upload file → analizza → preview tabella + select mapping per ogni campo → conferma → mostra count import/skip + dettaglio errori per riga.

## 9. Per gli agenti: regole operative

1. **Prima di modificare**: leggi sempre questo file e lo stato delle fasi.
2. **Dopo modifiche strutturali**: aggiorna sezioni 3, 4, 7 e la data in cima.
3. **Convenzioni di stack**: non introdurre librerie alternative senza una nota in sezione 6.
4. **Niente fuori scope**: lavora sulla fase corrente, non anticipare fasi successive senza richiesta esplicita.
5. **Comandi**: usa sempre il `Makefile` come riferimento per i comandi standard.
6. **Decisioni architetturali rilevanti**: crea un ADR in `docs/adr/NNNN-titolo.md`.
