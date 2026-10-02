# Analisi — «Ricordami» che si perde (soprattutto nella PWA)

> Branch `fix/remember-me`. Nessuna migration, nessun cambio frontend oltre a un testo.

## 1. Flusso attuale

- Il login con «Ricordami» ([LoginRequest](../../backend/app/Http/Requests/Auth/LoginRequest.php)) usa `Auth::attempt(..., remember)`: Laravel imposta il cookie `remember_web_*` per 576.000 minuti (400 giorni, default di `SessionGuard`, pari al tetto di Chrome) con dentro il `remember_token` dell'utente.
- `users.remember_token` è **uno solo per utente**: tutti i dispositivi con «Ricordami» (browser, PWA) condividono lo stesso valore.
- [AuthController::logout](../../backend/app/Http/Controllers/Auth/AuthController.php) chiama `Auth::guard('web')->logout()`, che **rigenera** il `remember_token` (`cycleRememberToken`).
- Effetto: uscire da un dispositivo invalida il «Ricordami» di tutti gli altri. Ci si accorge solo quando, sull'altro dispositivo, la sessione (120 minuti di inattività) scade e il cookie non vale più: la PWA rimanda al login «a caso».
- [updatePassword](../../backend/app/Http/Controllers/Auth/AuthController.php) invece **non** rigenera il token: dopo un cambio password gli altri dispositivi restano collegati per sempre. (Il reset password via email lo rigenera già.)

## 2. Modifiche da apportare

1. `logout`: `logoutCurrentDevice()` — chiude sessione e cookie solo del dispositivo corrente, non tocca il token.
2. `updatePassword`: nuovo `remember_token` e, se la richiesta aveva il cookie «Ricordami», nuovo cookie per il dispositivo corrente (`$guard->login($user, true)`).
3. Hint in Impostazioni → Password sul comportamento verso gli altri dispositivi.
4. `RememberMeTest` (3 casi), `AGENTS.md`.

## 3. Dettaglio dei fix

- `Auth::guard('web')` è tipizzato come contratto: annotato `/** @var SessionGuard $guard */` per `logoutCurrentDevice()` e `getRecallerName()` (Larastan).
- In `updatePassword` il cookie in ingresso si legge prima di salvare; `login()` riusa il token appena salvato (`ensureRememberTokenIsSet` non lo sovrascrive) e rigenera l'id di sessione.
- Senza il middleware `AuthenticateSession`, una sessione già attiva su un altro dispositivo resta valida fino a 2 ore di inattività; dopo, il vecchio cookie «Ricordami» non vale più. Uscita immediata ovunque = task a parte (`AuthenticateSession` nello stack di Sanctum).
- Test: le richieste simulano la SPA (`Referer` stateful + `withCredentials()`), altrimenti Sanctum non avvia sessione e cookie e il test JSON non invia cookie.

## 4. Impatti e possibili regressioni

Branch di riferimento: `master`.

- **Comportamento di «Esci»**: ora vale per il solo dispositivo. Per uscire ovunque si cambia password.
- **Cambio password**: il dispositivo corrente riceve una nuova sessione (id rigenerato) e, se usava «Ricordami», un nuovo cookie; nessun impatto sul frontend (axios già `withCredentials`).
- **Reset password via email**: invariato, rigenera già il token.
- **iOS**: la PWA installata ha cookie separati da Safari; non è un bug dell'app.
- **Cookie policy** ("fino a 400 giorni, o fino all'uscita"): ora corretta dispositivo per dispositivo.

## 5. Aggiornamento — i command schedulati

Dopo il primo fix è emersa la causa principale: `notifications:scan` (ogni mattina alle 07:00) e `rules:apply` impersonano ogni utente con `Auth::loginUsingId()` e chiudevano l'iterazione con `Auth::logout()`, che **rigenera il `remember_token`**. Ogni mattina tutti i dispositivi con «Ricordami» perdevano il token e tornavano al login alla prima scadenza della sessione: ecco perché la PWA «si scollegava da sola». Fix (branch `fix/commands-remember-token`): `Auth::forgetUser()`, che azzera l'utente del guard senza toccare sessione né token; test `test_background_commands_keep_the_remember_token` in `RememberMeTest`.
