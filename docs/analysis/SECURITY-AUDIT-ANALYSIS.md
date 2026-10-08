# Analisi audit di sicurezza (ottobre 2026)

Audit dell'intera applicazione su `master` @ `76c580f` (2026-10-08), in sola lettura, secondo OWASP Top 10:2021 (ADV-TEC-SEC-001). Quattro aree: controllo degli accessi; autenticazione e sessioni; input, import ed export, chiamate server-side; configurazione, dipendenze, segreti e log. I finding principali sono stati ricontrollati nel codice.

## 1. Flusso attuale

**Esito complessivo**: nessun finding critico o alto. Nessun utente autenticato legge o modifica dati di un altro utente, le dipendenze di produzione non hanno advisory aperte (`composer audit`, `npm audit --omit=dev`), lo storico git non contiene segreti reali. Il rischio principale riguarda **limiti e sovraccarico**: le API non hanno un throttle globale (`throttleApi()` non è attivo in [bootstrap/app.php](../../backend/bootstrap/app.php)), solo login, 2FA, reset password e test email sono limitati.

### Media

| # | Cat. | Problema | Dove |
|---|---|---|---|
| 1 | A04/A07 | Rigenerare i codici di recupero chiede solo la password: chi ha una sessione aperta e la password ottiene 8 codici permanenti, aggirando la 2FA | [AuthController.php:209](../../backend/app/Http/Controllers/Auth/AuthController.php) |
| 2 | A04 | `from`/`to` dei report senza validazione né tetto: `net-worth` su migliaia di anni fa centinaia di migliaia di query; `from=abc` dà 500 | [ReportController.php:167](../../backend/app/Http/Controllers/ReportController.php) |
| 3 | A04 | Import senza limite di righe e colonne, inserimenti senza transazione: un CSV da 5MB crea ~350k transazioni, un header molto largo esaurisce la memoria in anteprima | [TransactionImportService.php:113](../../backend/app/Services/TransactionImportService.php), [CsvReader.php](../../backend/app/Services/Import/CsvReader.php) |
| 4 | A04 | `refresh-prices` sincrono, senza throttle, sui simboli di tutti gli utenti (una richiesta esterna per simbolo, 15s di timeout): in loop fa bloccare i provider per tutti | [InvestmentController.php:27](../../backend/app/Http/Controllers/InvestmentController.php) |
| 5 | A03 | Formula injection nell'export CSV: descrizioni importate dalla banca (scritte dalla controparte) diventano formule attive in Excel | [TransactionExportService.php:53](../../backend/app/Services/TransactionExportService.php) |
| 6 | A07 | Login limitato solo per coppia email+IP (5/min): ~7.200 tentativi al giorno per IP, nessun limite per account | [LoginRequest.php:100](../../backend/app/Http/Requests/Auth/LoginRequest.php) |
| 7 | A07 | Enumerazione utenti: forgot e reset password restituiscono un messaggio diverso se l'email non esiste (`passwords.user`) o se è in throttle | [AuthController.php:97,123](../../backend/app/Http/Controllers/Auth/AuthController.php) |
| 8 | A09 | Nessun evento di sicurezza registrato: login riusciti e falliti, lockout, 2FA, cambio e reset password | nessun listener in `app/` |
| 9 | A08 | `appleboy/ssh-action@v1` riceve la chiave SSH di deploy con un tag spostabile; nessuna action fissata a SHA, nessun blocco `permissions:` | [deploy.yml:20](../../.github/workflows/deploy.yml), [ci.yml](../../.github/workflows/ci.yml) |
| 10 | A06 | Il deploy fa `up -d --build` senza pull: le immagini base non ricevono patch; nginx 1.27 è un ramo chiuso; dependabot non copre `docker` | [deploy.yml:48](../../.github/workflows/deploy.yml) |
| 11 | A02 | Stack Raspberry solo HTTP: password, TOTP e cookie in chiaro sulla LAN | [docker-compose.prod.yml](../../docker-compose.prod.yml) |

### Bassa

| # | Cat. | Problema | Dove |
|---|---|---|---|
| 12 | A07 | `PUT /auth/password` senza throttle: oracolo della password attuale da una sessione aperta | [routes/api.php:35](../../backend/routes/api.php) |
| 13 | A07 | Policy password `min:8`, nessun `uncompromised()` | Form Request in `app/Http/Requests/Auth/` |
| 14 | A05 | `trustProxies` su tutte le reti private con tutti gli header `X-Forwarded-*` (Host compreso), nessun `trustHosts`: IP falsificabile per aggirare il throttle | [bootstrap/app.php:24](../../backend/bootstrap/app.php) |
| 15 | A04 | `per_page` senza tetto in 13 controller; `-1` toglie il `LIMIT` | es. [TransactionController.php:76](../../backend/app/Http/Controllers/TransactionController.php) |
| 16 | A04 | `starts_on` di una ricorrente senza limite inferiore: il job notturno recupera tutto l'arretrato in una transazione (es. ~375k righe), ritardando gli altri utenti | [StoreRecurringTransactionRequest.php](../../backend/app/Http/Requests/RecurringTransaction/StoreRecurringTransactionRequest.php) |
| 17 | A04/A09 | L'import non applica le regole di `StoreTransactionRequest` (lunghezze, importo 0, valuta del conto) e restituisce al client il testo SQL delle eccezioni | [TransactionImportService.php:189](../../backend/app/Services/TransactionImportService.php) |
| 18 | A03 | Markdown nelle descrizioni diventa link nelle email di notifica (manca `Markdown::withSecuredEncoding()`) | [ChannelsFromPreferences.php:56](../../backend/app/Notifications/Concerns/ChannelsFromPreferences.php) |
| 19 | A04 | `email_address` delle notifiche modificabile senza password né verifica: dirottamento dei riepiloghi, relay di spam | [UpdateNotificationPreferencesRequest.php](../../backend/app/Http/Requests/User/UpdateNotificationPreferencesRequest.php) |
| 20 | A08 | Prezzi globali per simbolo: il provider dipende dall'`asset_type` di un holding qualsiasi (anche di altri utenti); batch CoinGecko non spezzato | [InvestmentPriceFetcher.php:117](../../backend/app/Services/InvestmentPriceFetcher.php) |
| 21 | A07 | Il logout non revoca il cookie «Ricordami» (durata 400 giorni, token unico per utente) | [AuthController.php:241](../../backend/app/Http/Controllers/Auth/AuthController.php) |
| 22 | A05 | Backup 0644, non cifrati, solo locali | [scripts/backup.sh](../../scripts/backup.sh) |
| 23 | A05 | CSP solo `frame-ancestors`; `server_tokens` attivo; password root MySQL nella riga di comando dell'healthcheck; `MAIL_MAILER=log` nel template di produzione (link di reset nei log) | [docker/nginx/prod.conf](../../docker/nginx/prod.conf), compose |
| 24 | A03 | Regex utente: in PATCH non validata se manca `match_type`; nessuna difesa dal backtracking (solo auto-danno) | [UpdateCategorizationRuleRequest.php](../../backend/app/Http/Requests/CategorizationRule/UpdateCategorizationRuleRequest.php) |

**Info**: registrazione `true` di default se manca la variabile (il template di produzione la mette a `false`); `sw.js` accetta URL assoluti nel payload push (non sfruttabile: payload firmato VAPID); redirect seguiti da Yahoo e CoinGecko; `$request->date()` su input non valido dà 500; seeder con utente demo `password`; `npm audit` completo: 8 advisory solo in devDependencies (build-time). `AGENTS.md` §12 afferma che `.env.production` tracciato conteneva segreti in chiaro: erano solo segnaposto `CHANGE_ME`.

**Bug non di sicurezza**: `TransactionImportService::parseAmount` legge `"1,234.56"` (formato US) come 1,23.

## 2. Modifiche da apportare

Quattro branch, in quest'ordine:

1. **`fix/auth-hardening`** (1, 6, 7, 12): secondo fattore per rigenerare i codici, limite per email sul login, messaggio uniforme in forgot/reset, throttle sul cambio password.
2. **`fix/request-limits`** (2, 3, 4, 15, 16, 17): `throttleApi()`, validazione delle date dei report, tetti e transazione nell'import, `refresh-prices` limitato all'utente e con throttle, tetto su `per_page`, limite su `starts_on`.
3. **`fix/output-escaping`** (5, 18, 24): neutralizzazione delle formule nel CSV, markdown sicuro nelle email, validazione regex in PATCH.
4. **`chore/infra-hardening`** (9, 10, 14, 22, 23): action a SHA e `permissions`, pull delle immagini nel deploy, nginx 1.28 con `server_tokens off` e CSP, `trustProxies` ristretto con `trustHosts`, `umask 077` nei backup, healthcheck senza password in chiaro.

Da decidere con il proprietario prima di intervenire: **11** (CA locale e certificati sui dispositivi), **13** (`uncompromised()` chiama Have I Been Pwned: va dichiarato nelle pagine Privacy e Cookie), **20**, **21**. **11** è rinviato: lo stack Raspberry non è in uso. **19** deciso: password più avviso al vecchio indirizzo (branch `fix/notification-email-change`). **8** deciso: canale di log dedicato, 90 giorni (branch `fix/security-logging`).

## 3. Dettaglio dei fix

### Branch 1, `fix/auth-hardening`
- **1**: `regenerateRecoveryCodes` valida `current_password` più `SECOND_FACTOR_RULES` e chiama `verifySecondFactor`, come `disableTwoFactor`. La card della 2FA nel frontend chiede il codice.
- **6**: oltre alla chiave `email|ip`, una seconda chiave per sola email con finestra lunga (10 tentativi in 15 minuti). Blocca il brute force distribuito su un account; il prezzo è che un attaccante può bloccare temporaneamente l'accesso del proprietario, accettabile con la finestra breve.
- **7**: forgot password risponde sempre con `passwords.sent`; reset password mappa `INVALID_USER` sullo stesso messaggio di `INVALID_TOKEN`.
- **12**: `PUT /auth/password` dentro il gruppo `throttle:5,1`.

### Branch 2, `fix/request-limits`
- **Throttle globale**: `throttleApi()` con limiter `api` a 300 richieste al minuto per utente (o IP). Il valore è largo perché Dashboard e Statistiche fanno più chiamate in parallelo; serve a fermare i loop, le rotte costose hanno limiti propri. Nel frontend un 429 fuori da `/auth/*` mostra un toast.
- **2**: `ReportController::range()` valida `from`/`to` come date e rifiuta intervalli invertiti o oltre 10 anni (422 su `from`); ReportsView mostra il messaggio.
- **3**: massimo 5.000 righe per import (letta una riga in più per accorgersene senza leggere tutto il file) e 100 colonne per i CSV, controllate già in preview. Il ciclo di import gira in `DB::transaction`.
- **4**: `refresh-prices` aggiorna solo i simboli dell'utente (`fetchLatest($symbols)`, con guard sulla lista vuota che altrimenti significherebbe «tutti»); `throttle:2,1,prices-refresh`. `investments/lookup` ha `throttle:10,1,prices-lookup`. I prefissi separano i contatori: i `throttle` anonimi di uno stesso utente condividono la chiave.
- **15**: `Controller::perPage()` limita `per_page` tra 1 e 200 (200 è il massimo che chiede il frontend per riempire le select).
- **16**: `starts_on` in creazione e `next_run_at` in modifica al massimo 5 anni nel passato. `starts_on` in modifica non è limitato perché non sposta la prossima scadenza e il form lo rimanda sempre (le ricorrenti vecchie non diventerebbero più modificabili).
- **17**: ogni riga importata rispetta i limiti di `StoreTransactionRequest`: importo diverso da 0 e entro il massimo, `external_id` ≤ 255; descrizione e note vengono accorciate invece di scartare la riga, perché le causali bancarie lunghe sono comuni. La valuta è sempre quella del conto (il parametro `currency` è stato tolto, il frontend non lo inviava). Errori attesi con `ImportRowException`; ogni altra eccezione va nei log e al client arriva «Riga non importabile.».

### Branch 3, `fix/output-escaping`
- **5**: `TransactionExportService::cell()` antepone un apostrofo alle celle di testo (conti, categoria, descrizione, note, `external_id`) che iniziano con `= + - @`, tab o CR. Data, tipo, importo e valuta non passano dall'helper: sono generati dall'app.
- **18**: `Markdown::withSecuredEncoding()` in `AppServiceProvider::boot`: nei valori interpolati nelle email `[` diventa `\[`, `<` e `>` diventano entità, quindi una descrizione `[testo](url)` resta testo. Nessuna notifica attuale usa link markdown nelle righe (verificato).
- **24**: `UpdateCategorizationRuleRequest` legge `match_type` e `pattern` dalla regola quando mancano nel payload, quindi `PATCH {pattern}` su una regola regex viene validato. La difesa dal backtracking non è stata aggiunta: con JIT PCRE ferma i pattern patologici in circa 1ms e il danno resta all'utente stesso.

### Branch `fix/notification-email-change` (finding 19)
- `UpdateNotificationPreferencesRequest` richiede `current_password` solo se `email_address` cambia (confronto senza maiuscole e spazi); `current_password` non finisce nelle preferenze (`safe()->except`).
- Se l'indirizzo effettivo cambia (personalizzato o, se vuoto, quello dell'account), al vecchio parte `NotificationAddressChangedNotification`, in coda, anche con le email disattivate nelle preferenze.
- Scartata la conferma via link al nuovo indirizzo: eviterebbe errori di battitura e relay di spam, ma non ferma chi controlla il nuovo indirizzo, e costa uno stato «in attesa» con link firmato e UI.
- Frontend: in Impostazioni il campo «Password attuale» compare solo quando l'indirizzo è diverso da quello salvato.

### Branch `fix/security-logging` (finding 8)
- Canale `security` in `config/logging.php`: file giornaliero `storage/logs/security-*.log`, livello `info`, `LOG_SECURITY_DAYS` (default 90). Sul VPS `storage/logs` è già sul volume `laravel_logs`, quindi il registro sopravvive ai deploy senza toccare il compose.
- Helper `App\Support\SecurityLog::record()` con IP, browser (max 200 caratteri) e nome della rotta; scarta i valori vuoti.
- Eventi registrati con chiamate esplicite in `AuthController`, `LoginRequest`, `NotificationPreferenceController` e nel command `user:two-factor-disable` (che prima scriveva nel log applicativo). Un listener su `Illuminate\Auth\Events\Login` è stato scartato: `rotateRememberToken` rifà `login()` al cambio password e all'attivazione 2FA.
- `login.failed` registra l'email tentata anche se non esiste: serve a riconoscere il password spraying. È un dato personale di chi l'ha digitata, coperto dalla stessa retention.
- Non registrati: richieste di reset password (email arbitrarie di chiunque), logout, 403/404 di autorizzazione (gli id altrui danno 404 dal global scope, rumore senza segnale).
- Privacy policy aggiornata (dati, base giuridica, conservazione) e `LEGAL.updatedAt`.
- Da valutare in `chore/infra-hardening`: montare `storage/logs` su una cartella dell'host per far leggere `security-*.log` a fail2ban sul VPS.

### Branch 4
Da dettagliare all'avvio, partendo dalle righe indicate nelle tabelle della sezione 1.

## 4. Impatti e possibili regressioni
Riferimento: `master`.
- **Branch 1**: la UI della 2FA cambia (codice richiesto per rigenerare i codici di recupero); il messaggio di forgot password è sempre lo stesso anche per email inesistenti; un account bersagliato può restare bloccato fino a 15 minuti dopo 10 password errate.
- **Branch 2**: con 300 richieste al minuto l'uso normale non dovrebbe mai arrivare al limite; se succede, compare un toast. Gli import oltre 5.000 righe vanno divisi. Report con periodo personalizzato oltre 10 anni rifiutati. Le ricorrenti nuove non possono partire più di 5 anni fa. Il pulsante «Aggiorna quotazioni» vale 2 volte al minuto e non aggiorna più i simboli degli altri utenti (lo fa lo scheduler).
- **Branch 3**: le celle CSV di testo che iniziano con `= + - @` ricevono un apostrofo iniziale, visibile riaprendo il file in un editor di testo. Reimportando nell'app un export con un `external_id` che inizia con quei caratteri, il dedup non lo riconosce (caso teorico: gli ID bancari sono alfanumerici). Nelle email le parentesi quadre del testo compaiono come tali e non diventano link.
- **Branch 4**: la CSP può bloccare lo script inline del tema in `index.html` se l'hash non è allineato; il cambio di `trustProxies` va provato su VPS e Raspberry (redirect HTTPS, link nelle email).
