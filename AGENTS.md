# AGENTS.md — Mappa del progetto per agenti AI

> Questo documento è la **fonte di verità** per qualsiasi agente AI (Claude Code, Codex, Cursor, ecc.) che lavora su questo repository.
> Mantienilo aggiornato a ogni modifica strutturale, ogni nuova fase completata, ogni nuova convenzione introdotta.

Ultimo aggiornamento: **2026-05-29**
Fase corrente: **Estensione — Recupero password (COMPLETATA)**

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
│   ├── app/Models/        # User, Account, Category, Transaction, Budget, RecurringTransaction, Tag, CategorizationRule
│   │   ├── Concerns/      # BelongsToUser (trait: global scope + autofill user_id)
│   │   └── Scopes/        # UserScope (global scope su Auth::id())
│   ├── app/Http/
│   │   ├── Controllers/        # Account, Category, Tag, Transaction, Budget, RecurringTransaction, CategorizationRule
│   │   ├── Controllers/Auth/   # AuthController (register/login/logout/me)
│   │   ├── Requests/Auth/      # RegisterRequest, LoginRequest (con throttle)
│   │   ├── Requests/Account/   # Store/UpdateAccountRequest
│   │   ├── Requests/Category/  # Store/UpdateCategoryRequest (validazione parent_id + ciclo)
│   │   ├── Requests/Tag/       # Store/UpdateTagRequest (unique per user)
│   │   ├── Requests/Transaction/  # Store/UpdateTransactionRequest (transfer rules, owned-by-user)
│   │   ├── Requests/Budget/    # Store/UpdateBudgetRequest (unique categoria/anno/mese)
│   │   ├── Requests/RecurringTransaction/  # Store/UpdateRecurringTransactionRequest (transfer + cadence)
│   │   ├── Requests/CategorizationRule/    # Store/UpdateCategorizationRuleRequest (validazione regex)
│   │   └── Resources/          # UserResource + Account/Category/Tag/Transaction/Budget/RecurringTransaction/CategorizationRuleResource
│   ├── app/Services/           # RecurringTransactionRunner, CategorizationRuleMatcher, CategorizationRuleApplier, BudgetAlertService
│   │   └── Import/             # ImportReader (abstract) + CsvReader/OfxReader/QifReader + ImportReaderFactory
│   ├── app/Console/Commands/   # RunRecurringTransactions (`recurring:run`), ApplyCategorizationRules (`rules:apply`)
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
│       └── views/             # Login, Register, Dashboard, Accounts, Categories, Tags, CategorizationRules, Transactions, Budgets, Recurring, Reports, Stats, ImportExport
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

## 4.1 Setup da zero (post-clone)

Dopo aver clonato il repo su una nuova macchina:

```bash
git clone <repo>
cd Finance

# (opzionale) allinea UID/GID all'utente host
#   Linux user standard: UID=1000 GID=1000 (già default)
#   macOS: edit .env dopo bootstrap → UID=$(id -u) GID=$(id -g) (tipicamente 501/20)

make bootstrap
```

`make bootstrap` esegue in ordine:
1. `cp .env.example .env` e `cp backend/.env.example backend/.env` (solo se mancanti).
2. `docker compose build` (UID/GID nel .env diventano args del build).
3. `docker compose up -d` (tutti i servizi).
4. `composer install` nel container php.
5. `php artisan key:generate` (popola `APP_KEY` in `backend/.env`).
6. `php artisan migrate --seed` (crea schema + utente demo + categorie).
7. Stampa URL e credenziali demo (`demo@finance.local` / `password`).

A fine bootstrap, `http://localhost:${APP_PORT:-8080}` è pronto.

**Comandi atomici** se serve solo un pezzo:
- `make key-generate` — rigenera `APP_KEY` se hai cancellato/sostituito `backend/.env`.
- `make build` — rebuild immagini (necessario dopo modifiche ai Dockerfile o cambi UID/GID).
- `make fresh` — `migrate:fresh --seed` per ripartire da DB vuoto.

**Troubleshooting comune**
- *Errore `No application encryption key has been specified`* → `make key-generate`.
- *Errore `EACCES` nel container `node`* sui `node_modules` → l'entrypoint script chowna il volume al boot, ma serve un'immagine aggiornata: `make build` (o `docker compose build --no-cache node`) dopo un `git pull`.
- *502 Bad Gateway sulla root* → il container `node` non sta servendo Vite. `docker compose logs node` per diagnosi.

## 5. Comandi essenziali

Tutti via `make`:

```bash
make bootstrap       # setup completo post-clone (vedi 4.1)
make up              # avvia stack
make down            # ferma stack
make restart         # riavvia stack
make build           # rebuild immagini
make logs            # tail log
make ps              # stato container
make shell-php       # shell nel container PHP
make shell-node      # shell nel container Node
make shell-mysql     # client MySQL

make key-generate    # genera APP_KEY Laravel

make composer-install
make migrate
make fresh           # migrate:fresh --seed
make seed
make test            # PHPUnit feature tests
make pint            # formatter PHP
make stan            # Larastan / PHPStan
make lint            # ESLint frontend
make type-check      # vue-tsc
make check           # pipeline completa (pint + stan + test + lint + type-check)

make prod-build      # build immagini produzione
make prod-up         # avvia stack produzione
make prod-down       # ferma stack produzione
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
- **Responsive / mobile**: layout mobile-first. Sidebar di `AppLayout` diventa drawer < `lg` con topbar + hamburger; le griglie usano breakpoint `sm/md/lg`. Le tabelle dati sono wrappate in `.table-responsive` (utility in `style.css`) che sotto `md` collassa le righe in card stack: ogni `<td>` deve avere `data-label="…"`, la cella delle azioni la classe `actions-cell`. Gli `input/select/textarea` partono da 16px (no zoom iOS), si riducono da `sm` in su.

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
- [x] **Fase 9** — Qualità, CI, deploy (Larastan livello 5, ESLint/Prettier, GitHub Actions, stack produzione Docker)
- [x] **Estensione** — Statistiche avanzate (saving rate, confronto periodi, trend categorie, cash-flow forecast, top transazioni)
- [x] **Estensione** — Auto-categorizzazione import (regole pattern→categoria applicate in fase di import CSV)
- [x] **Estensione** — Import OFX/QIF (parser dedicati con mapping bloccato) + applicazione retroattiva regole alle transazioni esistenti
- [x] **Estensione** — Alert budget sforati (endpoint `/budgets/alerts`, banner Dashboard, badge colorati in BudgetsView)
- [x] **Estensione** — Dedup import via `external_id` (skip righe già importate o ripetute nel file, counter `duplicates`)
- [x] **Estensione** — Recupero password (endpoint forgot/reset, link SPA, viste dedicate)

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
| POST | `/api/auth/forgot-password` | — | Invia link reset (Password broker). Risposta generica (no enumeration), 200 |
| POST | `/api/auth/reset-password` | — | `token`, `email`, `password` (confirmed). 200 su successo, 422 su token/email non validi |
| POST | `/api/auth/logout` | `auth:sanctum` | Logout web + sanctum, invalida sessione, 204 |
| GET | `/api/auth/me` | `auth:sanctum` | Ritorna utente corrente |

**Recupero password**: usa il Password broker di Laravel (tabella `password_reset_tokens` già presente, `User` eredita `CanResetPassword`). Il link di reset punta alla SPA (`{FRONTEND_URL}/reset-password?token=…&email=…`) via `ResetPassword::createUrlUsing` in [AppServiceProvider](backend/app/Providers/AppServiceProvider.php); config `app.frontend_url`. Email in `MAIL_MAILER=log` in dev (finiscono in `storage/logs/laravel.log`); in produzione configurare SMTP. Frontend: viste [ForgotPasswordView](frontend/src/views/ForgotPasswordView.vue) (`/forgot-password`) e [ResetPasswordView](frontend/src/views/ResetPasswordView.vue) (`/reset-password`), link in LoginView.

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
| GET | `/api/transactions` | filtri `account_id` (anche transfer_account_id), `category_id`, `type`, `from`, `to` (date), `tag_id`, `search` (parole chiave su `description`, AND tra i termini, `LIKE` con escape). Sort `occurred_at` DESC, eager-load `tags`. Paginato (`per_page` default 25, `page`) |
| POST | `/api/transactions` | `account_id`, `type`, `amount` (>0), `occurred_at`, `category_id?`, `transfer_account_id?` (richiesto se `type=transfer`, diverso da `account_id`), `tag_ids?` |
| PATCH | `/api/transactions/{transaction}` | sync `tags` se `tag_ids` presente nel body |
| DELETE | `/api/transactions/{transaction}` | 204 |

Validazione di appartenenza: tutti i `*_id` riferiti a risorse di dominio passano per `Rule::exists` filtrato su `Auth::id()`.

## 8.3 Endpoint Budget & Ricorrenti (Fase 5)

### Budgets — `apiResource('budgets')`
| Metodo | Path | Note |
|--------|------|------|
| GET | `/api/budgets` | filtri `year`, `month`, `category_id`. Ogni risorsa include `spent` = somma `expense` per (category, year, month) |
| GET | `/api/budgets/alerts` | filtri `year`, `month` (default mese corrente). Ritorna `[{budget_id, category_id, category_name, category_color, year, month, amount, spent, percent, status}]` per i soli budget in `warning` (≥80%) o `exceeded` (≥100%), ordinati per `percent` desc. Rotta registrata **prima** dell'`apiResource` |
| POST | `/api/budgets` | unique (`user_id`, `category_id`, `year`, `month`) verificato in validation + DB |
| PATCH | `/api/budgets/{budget}` | stessa unicità in update |
| DELETE | `/api/budgets/{budget}` | 204 |

Alert calcolati da [BudgetAlertService](backend/app/Services/BudgetAlertService.php) (`WARNING_THRESHOLD = 80`): unica query `SUM ... GROUP BY category_id` sulle `expense` del mese; `amount=0` con speso>0 → `exceeded`. Frontend: banner "Alert budget" in [DashboardView](frontend/src/views/DashboardView.vue) (mese corrente) + barra/badge colorati (ambra ≥80%, rosso ≥100%) in [BudgetsView](frontend/src/views/BudgetsView.vue).

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
| `/transactions` | TransactionsView | CRUD + filtri (conto, type, range date, ricerca descrizione, tag), paginazione (prev/next), supporto transfer. Tag associabili nel form (chip multi-selezione → `tag_ids`) e mostrati come badge colorati in tabella |
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
| GET | `/api/reports/by-tag?type=expense\|income` | `[{tag_id, tag_name, tag_color, total}]` ordinato per total desc. Join su `tag_transaction`, somma per tag delle transazioni del tipo nel range |
| GET | `/api/reports/timeline` | `[{period: "YYYY-MM", income, expense, net}]` |
| GET | `/api/reports/net-worth` | `[{period: "YYYY-MM", net_worth}]` cumulato (initial_balance + Σ income - Σ expense fino a fine mese) |

Logica in [ReportService](backend/app/Services/ReportService.php). Saldo per conto = `initial_balance + Σ income (account_id) - Σ (expense+transfer con account_id) + Σ transfer con transfer_account_id`. Le transfer si compensano nel net worth aggregato e quindi sono escluse dal cumulato.

### Frontend
- Libreria: `chart.js` + `vue-chartjs`.
- [DashboardView](frontend/src/views/DashboardView.vue): 4 KPI cards (income/expense/net mese + patrimonio netto), saldi conti, donut categorie del mese, bar income vs expense 12 mesi.
- [ReportsView](frontend/src/views/ReportsView.vue) (`/reports`): filtri data + type categoria, donut by-category, donut by-tag (usa il `color` del tag), bar timeline, line net-worth, tabella categorie + tabella tag. Il selettore type (`expense`/`income`) filtra sia by-category sia by-tag. Toggle "Report visibili" (4 gruppi: Categorie/Tag/Income vs Expense/Patrimonio netto) per mostrare/nascondere ogni report; scelta persistita in `localStorage` (`reports.visible`). I dati vengono comunque caricati: i toggle agiscono solo sulla visualizzazione.

## 11. Import / Export CSV (Fase 8)

### Endpoint (`auth:sanctum`)
| Metodo | Path | Risposta / Body |
|--------|------|-----------------|
| GET | `/api/transactions/export` | Stream `text/csv` con header `Content-Disposition: attachment`. Filtri: `account_id`, `type`, `from`, `to`. Colonne: `occurred_at,type,amount,currency,account,transfer_account,category,description,notes,external_id` |
| POST | `/api/transactions/import/preview` | multipart `file` (CSV/OFX/QIF ≤ 5MB). Ritorna `{format, mapping_locked, headers, sample (max 10 righe), suggested: {...}}`. Per OFX/QIF `mapping_locked=true` e `suggested` mappa i campi fissi del formato |
| POST | `/api/transactions/import` | multipart `file`, `account_id`, `mapping[date]`, `mapping[amount]`, `mapping[description]?`, `mapping[type]?`, `mapping[category]?`, `date_format?` (default `Y-m-d`), `currency?`. Ritorna `{imported, skipped, duplicates, auto_categorized, errors: [{row, message}]}` |

### Formati supportati
**CSV + OFX 2.x/SGML + QIF.** Il rilevamento del formato avviene per estensione (`.csv`/`.ofx`/`.qfx`/`.qif`) con fallback su content-sniff dei primi 512 byte ([ImportReaderFactory](backend/app/Services/Import/ImportReaderFactory.php)). Ogni formato ha un reader dedicato che estende [ImportReader](backend/app/Services/Import/ImportReader.php):
- [CsvReader](backend/app/Services/Import/CsvReader.php): auto-detect delimitatore, mapping colonne scelto dall'utente (`mapping_locked=false`).
- [OfxReader](backend/app/Services/Import/OfxReader.php): estrae i blocchi `<STMTTRN>` (regex robusta su XML e SGML), normalizza `DTPOSTED→date` (ISO), `TRNAMT→amount`, `NAME/MEMO→description`, `TRNTYPE→type` (fallback dal segno), `FITID→external_id`. `mapping_locked=true`.
- [QifReader](backend/app/Services/Import/QifReader.php): parsing line-oriented (`D/T/P/M`, terminatore `^`), date in formati eterogenei (europeo prima dell'americano, ISO se vuoto→riga scartata), `notes` dal memo. `mapping_locked=true`.

I file non UTF-8 (ISO-8859-1) vengono convertiti. Validazione MIME estesa nei 3 endpoint import.

**Dedup**: in `import()` le righe con `external_id` non vuoto già presente per l'utente (preload via `pluck`+`flip`, global scope per-utente) o ripetuto nello stesso file vengono saltate e contate in `duplicates` (distinto da `skipped`, che resta per i soli errori). Le righe senza `external_id` (CSV non mappato, QIF) non sono deduplicate.

### Logica
- [TransactionExportService](backend/app/Services/TransactionExportService.php): stream via `php://output` con `fputcsv`, chunk 500, scoping per user via global scope.
- [TransactionImportService](backend/app/Services/TransactionImportService.php): delega la lettura al reader della factory; quando `mapping_locked` forza il mapping ai campi normalizzati e `date_format=Y-m-d`. Parse importo in stile italiano (`1.234,56`) e standard, inferenza `type` da segno, match categoria per nome (case-insensitive), popolamento `external_id`/`notes` quando disponibili. Righe vuote ignorate, errori per riga raccolti senza interrompere il batch.
- `mapping` suggerito su euristica per chiavi `data/date/occurred`, `importo/amount/value`, `descrizione/description/causale/memo`, `tipo/type`, `categoria/category`.

### Frontend
- [ImportExportView.vue](frontend/src/views/ImportExportView.vue) — accessibile da `/import-export` nella sidebar.
- Export: filtri (conto/tipo/range), download diretto del blob CSV.
- Import: upload file → analizza → preview tabella + select mapping per ogni campo → conferma → mostra count import/skip + dettaglio errori per riga.

## 12. Qualità & CI (Fase 9)

### Static analysis & lint
- **Backend**: `larastan/larastan` con `backend/phpstan.neon` livello 5. Modelli annotati con `@property`, Resource con `@mixin {Model}`. Eseguito via `make stan`.
- **Frontend**: ESLint 9 (flat config) + `@vue/eslint-config-typescript` + `@vue/eslint-config-prettier` (`frontend/eslint.config.js`), Prettier (`frontend/.prettierrc.json`). Script `npm run lint`, `lint:fix`, `format`, `type-check`. Esposti via `make lint` / `make type-check`.
- Aggregato: **`make check`** lancia pint → stan → test → lint → type-check.

### CI — GitHub Actions
[.github/workflows/ci.yml](.github/workflows/ci.yml) — trigger su `push`/`pull_request` su `main`. Due job:
- **backend**: PHP 8.3 + estensioni, cache vendor, `pint --test`, `phpstan analyse`, `php artisan test` (SQLite in-memory da `phpunit.xml`).
- **frontend**: Node 20 con cache npm, `npm ci`, `type-check`, `lint`, `build`.

### Stack produzione
File:
- [docker/php/Dockerfile.prod](docker/php/Dockerfile.prod) — multi-stage (vendor install + runtime), opcache + JIT in [php.prod.ini](docker/php/php.prod.ini), composer `--no-dev --classmap-authoritative`, codice copiato (no volume mount).
- [docker/nginx/Dockerfile.prod](docker/nginx/Dockerfile.prod) — multi-stage: stage 1 builda la SPA (`npm ci && npm run build`), stage 2 nginx serve `dist/` + proxy FastCGI verso PHP.
- [docker/nginx/prod.conf](docker/nginx/prod.conf) — SPA fallback su `index.html`, cache 30d su `/assets/*` (asset Vite hash-immutable), API routing identico al dev.
- [docker-compose.prod.yml](docker-compose.prod.yml) — servizi `nginx`, `php`, `scheduler` (`php artisan schedule:work`), `mysql`, `redis`. Volume `laravel_app` condiviso tra php/nginx/scheduler (read-only su nginx). Niente container `node` in prod.
- [.env.production.example](.env.production.example) — template (rinominare in `.env.production`).

Target Makefile: `make prod-build`, `make prod-up`, `make prod-down`.

### Cron host (alternativa a scheduler container)
Su VM senza container scheduler, usare cron host:
```
* * * * * docker compose -f /path/to/docker-compose.prod.yml exec -T php php artisan schedule:run >> /dev/null 2>&1
```

### Note open
- HTTPS termination: aggiungere reverse proxy (Caddy/Traefik/Nginx host) davanti al container nginx, oppure montare certificati e ascoltare 443.
- Backup MySQL e Redis: scriptare dump giornaliero (fuori scope di questa fase).

## 13. Statistiche avanzate (estensione)

### Endpoint `auth:sanctum`
| Metodo | Path | Risposta |
|--------|------|----------|
| GET | `/api/reports/period-comparison?unit=month\|year&reference=YYYY-MM-DD` | `{unit, current, previous, delta: {income, income_pct, expense, expense_pct, net}}`. Default `unit=month`, `reference=now`. |
| GET | `/api/reports/category-trend?from=&to=&type=expense\|income&top=5` | `{periods: ["YYYY-MM",…], categories: [{category_id, category_name, values: [string,…]}]}` per top N categorie. |
| GET | `/api/reports/top-transactions?from=&to=&type=&limit=10` | `[{id, occurred_at, type, amount, currency, account_name, category_name, description}]` ordinato per amount desc. `type` opzionale (income/expense/transfer). |
| GET | `/api/reports/cash-flow-forecast?months=6` | `[{period, income, expense, net, projected_net_worth}]` — proiezione mensile basata sulle ricorrenti income/expense attive (ignora transfer). Patrimonio proiettato = patrimonio attuale + Σ net mensili. |

Inoltre `summary` ora include `saving_rate` = `(income - expense) / income * 100` (formato `xx.xx`, `0.00` se income = 0).

### Logica (in [ReportService](backend/app/Services/ReportService.php))
- `periodComparison`: confronta totali income/expense/net del periodo `current` con il `previous` equivalente. Calcola delta assoluto e percentuale (`null` se previous = 0). Per `month` usa `start/endOfMonth` + `subMonthNoOverflow`; per `year` `start/endOfYear` + `subYearNoOverflow`.
- `categoryTrend`: identifica le top N categorie per totale nel range (SQL `GROUP BY category_id ORDER BY SUM DESC LIMIT N`), poi crea una serie mensile per ognuna con bucket inizializzati a 0 (mesi senza dati = 0). Le 12 colorate via palette frontend.
- `topTransactions`: ordina per `amount DESC`, opzionalmente filtra per `type`. Risolve `account_name` e `category_name` con un singolo `whereIn`.
- `cashFlowForecast`: parte dal mese corrente per N mesi (1–24). Itera ogni ricorrente attiva chiamando `advance($cadence, $interval)` (match esaustivo come il runner), incrementa i buckets mensili. Patrimonio proiettato cumulato a partire da `cumulativeBalance(now - 1 day)`.

### Frontend
- [StatsView.vue](frontend/src/views/StatsView.vue) (`/stats` in sidebar):
  1. **Confronto periodi** — 3 KPI card (income/expense/net) con valore corrente, precedente, delta assoluto e %, colore semantico (spese in verde se calano, in rosso se salgono).
  2. **Trend top 5 categorie** — Line chart multi-serie con switch type expense/income.
  3. **Cash flow forecast** — Line con 2 assi: net mensile previsto (sx) e patrimonio proiettato (dx). Selector 1–24 mesi.
  4. **Top transazioni del mese** — tabella ordinata, filtro type.

## 14. Auto-categorizzazione import (estensione)

### Endpoint `auth:sanctum`
| Metodo | Path | Risposta / Body |
|--------|------|-----------------|
| GET | `/api/categorization-rules` | Lista paginata. Filtri: `is_active`, `category_id`. Ordine `priority asc, id asc`. Eager-load `category` |
| POST | `/api/categorization-rules` | `category_id`, `name`, `match_type` (contains/starts_with/equals/regex), `pattern`, `applies_to_type?` (any/income/expense), `priority?`, `is_active?` |
| GET | `/api/categorization-rules/{id}` | — |
| PATCH | `/api/categorization-rules/{id}` | Campi `sometimes`. Stessa validazione regex su update |
| DELETE | `/api/categorization-rules/{id}` | 204 |
| POST | `/api/transactions/import/preview-predictions` | multipart `file` + `mapping[*]`. Ritorna `[{category_id, category_name, rule_id}]` per le prime 50 righe — usato dalla UI per mostrare la colonna "Categoria suggerita" |
| POST | `/api/categorization-rules/apply` | Applicazione retroattiva. Body: `{dry_run: bool, only_uncategorized?: bool, account_id?, from?, to?}`. Ritorna `{matched, updated, by_rule: [{rule_id, name, count}], sample (max 50)}`. Rotta registrata **prima** dell'`apiResource` per non collidere con `/{id}` |

### Schema `categorization_rules`
- `user_id` (cascade), `category_id` (cascade), `name (120)`, `match_type` enum, `pattern (255)`, `applies_to_type` enum default `any`, `priority` smallint default 100, `is_active` boolean default true, `times_applied` unsigned int, `last_applied_at` timestamp nullable.
- Indice `(user_id, is_active, priority)` per il path di matching.

### Logica
- [CategorizationRuleMatcher](backend/app/Services/CategorizationRuleMatcher.php): carica una volta le regole attive ordinate per `priority asc`, per ogni descrizione confronta in mb_strtolower con `str_contains`/`str_starts_with`/`equals`/regex (`/.../iu`). Filtra per `applies_to_type` quando `≠ any`. Espone `recordHit(rule)` + `flushHits()` per aggregare gli increment di `times_applied` (single `UPDATE` per regola a fine import).
- [TransactionImportService::import()](backend/app/Services/TransactionImportService.php) usa il matcher come **fallback** quando il mapping CSV non risolve già la categoria. Ritorno arricchito con `auto_categorized`.
- La validazione regex avviene a livello di FormRequest (`Store/UpdateCategorizationRuleRequest`): pattern malformato → `422` con errore su `pattern`.
- [CategorizationRuleApplier](backend/app/Services/CategorizationRuleApplier.php): applica le regole alle transazioni esistenti (filtri `only_uncategorized` default true, `account_id`, `from`, `to`). `chunk(500)`, aggiornamento in batch raggruppato per `category_id`, `times_applied` incrementato solo in commit (non dry-run). Dry-run non scrive nulla e ritorna `matched` + `by_rule` + `sample`.
- Comando artisan `php artisan rules:apply [--dry-run] [--only-uncategorized=true] [--user=] [--account=] [--from=] [--to=]`: itera sugli utenti con `Auth::loginUsingId` + `Auth::logout` tra iterazioni per non fare leak del global scope.

### Frontend
- [CategorizationRulesView.vue](frontend/src/views/CategorizationRulesView.vue) (`/categorization-rules` in sidebar tra Tag e Budget) — CRUD inline, select categoria filtrata per `applies_to_type`, swatch colore, toggle attiva, contatore `times_applied`. Bottone "Applica alle transazioni esistenti" → modale con filtri (only_uncategorized/conto/range) → dry-run (tabella `by_rule` + sample) → conferma commit con reload lista.
- [ImportExportView.vue](frontend/src/views/ImportExportView.vue) — dopo la preview chiama `preview-predictions` e mostra una colonna "Categoria suggerita" nella tabella sample; il watcher ricalcola le predictions quando l'utente modifica il mapping. Riepilogo finale include `auto_categorized` + link a `/categorization-rules`. Per OFX/QIF (`mapping_locked`) nasconde il form di mapping e mostra il formato rilevato.

## 9. Per gli agenti: regole operative

1. **Prima di modificare**: leggi sempre questo file e lo stato delle fasi.
2. **Dopo modifiche strutturali**: aggiorna sezioni 3, 4, 7 e la data in cima.
3. **Convenzioni di stack**: non introdurre librerie alternative senza una nota in sezione 6.
4. **Niente fuori scope**: lavora sulla fase corrente, non anticipare fasi successive senza richiesta esplicita.
5. **Comandi**: usa sempre il `Makefile` come riferimento per i comandi standard.
6. **Decisioni architetturali rilevanti**: crea un ADR in `docs/adr/NNNN-titolo.md`.
