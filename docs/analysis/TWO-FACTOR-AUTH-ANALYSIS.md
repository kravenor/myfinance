# Analisi — Autenticazione a due fattori (2FA)

> Branch proposto `feat/two-factor-auth`, base `master`. Facoltativa per utente: chi non la attiva continua ad accedere come oggi.
>
> **Libreria:** `pragmarx/google2fa` (TOTP, RFC 6238) + `bacon/bacon-qr-code` (QR in SVG lato server). Sono le stesse che usa Laravel Fortify al suo interno. **Fortify scartato** perché registra le proprie rotte e il proprio flusso per login, registrazione e reset password, che qui esistono già in [AuthController](../../backend/app/Http/Controllers/Auth/AuthController.php). La sua 2FA funziona solo passando dalla sua rotta di login, quindi avremmo dovuto rifare il login su Fortify o tenere due flussi in parallelo. Nessuna libreria frontend: il QR arriva come SVG.

## 1. Flusso attuale

- **Login** — `POST /api/auth/login` ([routes/api.php:27](../../backend/routes/api.php)) → [AuthController::login](../../backend/app/Http/Controllers/Auth/AuthController.php) (riga 58) → [LoginRequest::authenticate](../../backend/app/Http/Requests/Auth/LoginRequest.php) (riga 31): `Auth::attempt(email, password, remember)` con rate limit di 5 tentativi per email+IP. Se va a buon fine, rigenera la sessione e restituisce `UserResource`. Il login riuscito è un unico passaggio: la password basta.
- **«Ricordami»** — `remember` → cookie `remember_web_*` di 400 giorni con il `remember_token` (unico per utente). Su quel dispositivo la sessione si riapre da sola senza password (vedi [REMEMBER-ME-ANALYSIS](REMEMBER-ME-ANALYSIS.md)).
- **Recupero password** — `forgot-password` / `reset-password` (righe 75–105): il reset **non** fa entrare l'utente, che poi deve rifare il login.
- **Cambio password** — `PUT /auth/password` (riga 107): chiede `current_password`, rigenera il `remember_token` e ricollega il dispositivo corrente.
- **Frontend** — store [auth.ts](../../frontend/src/stores/auth.ts) (`login()` riga 24 si aspetta sempre `{data: User}`), [LoginView](../../frontend/src/views/LoginView.vue) con un solo form, [SettingsView](../../frontend/src/views/SettingsView.vue) con la card «Password» (riga 334). L'interceptor in [api.ts](../../frontend/src/lib/api.ts) (riga 47) lascia gestire alle chiamate `/auth/*` i propri 401.
- **Utente** — [User](../../backend/app/Models/User.php): `$hidden` = password e remember_token. [UserResource](../../backend/app/Http/Resources/UserResource.php) non dice nulla sulla sicurezza dell'account.

Oggi chi conosce la password entra. Non esiste un secondo fattore e l'API non ha nessun concetto di «login a metà».

## 2. Modifiche da apportare

1. `composer require pragmarx/google2fa bacon/bacon-qr-code` (nel container `php`).
2. Migration su `users`: `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `two_factor_last_timestep`.
3. `User`: cast `encrypted` e `encrypted:array`, campi in `$hidden`, `hasTwoFactor()`.
4. Service `TwoFactorAuthenticator`: genera il segreto, crea il QR, verifica un codice con protezione dal riuso, genera e consuma i codici di recupero.
5. Login in due passi: con password giusta e 2FA attiva, nessun login. L'id dell'utente in attesa va in sessione e la risposta è `{two_factor: true}`.
6. Nuova rotta `POST /auth/two-factor-challenge` (codice o codice di recupero, throttle) che completa il login rispettando `remember`.
7. Rotte di gestione (`auth:sanctum`): attiva, conferma, disattiva, rigenera codici di recupero. Attiva, disattiva e rigenera chiedono la password attuale.
8. Attivazione confermata → nuovo `remember_token` (come il cambio password) e ricollegamento del dispositivo corrente.
9. `UserResource`: campo `two_factor_enabled`.
10. Command `user:two-factor-disable {email}` per l'utente che ha perso telefono e codici di recupero.
11. Frontend: secondo passaggio in LoginView, card «Verifica in due passaggi» in Impostazioni, metodi nello store auth.
12. Test `TwoFactorTest`, aggiornamento di `AGENTS.md` (§2, §8.1, §7).

## 3. Dettaglio dei fix

### 3.1 Dati

| Colonna | Tipo | Cast | Note |
|---------|------|------|------|
| `two_factor_secret` | `text` null | `encrypted` | Segreto base32. Esiste anche prima della conferma |
| `two_factor_recovery_codes` | `text` null | `encrypted:array` | 8 hash **sha256** dei codici. I codici in chiaro si mostrano una volta sola |
| `two_factor_confirmed_at` | `timestamp` null | `datetime` | La 2FA conta solo se valorizzato |
| `two_factor_last_timestep` | `unsignedBigInteger` null | `integer` | Ultimo intervallo da 30 s accettato: un codice già usato viene rifiutato |

- La 2FA è attiva solo se `two_factor_confirmed_at` è valorizzato. Così un'attivazione lasciata a metà (QR mostrato ma mai confermato) non blocca l'accesso.
- Per i codici di recupero basta sha256: sono casuali (10 caratteri `Str::random`, formato `xxxxx-xxxxx`), quindi bcrypt non aggiunge nulla e costerebbe 8 verifiche lente a ogni uso. Il confronto usa `hash_equals`. Un codice usato viene tolto dall'array.
- Tutte e quattro le colonne vanno in `$hidden` e **non** in `$fillable`: si scrivono solo con `forceFill` dal service.

### 3.2 Service `App\Services\TwoFactorAuthenticator`

- `enable(User)`: `Google2FA::generateSecretKey()` (160 bit), salvato e non confermato. Restituisce `secret`, `otpauth_url` (`getQRCodeUrl(config('app.name'), email, secret)`) e `qr_svg` (`ImageRenderer` + `SvgImageBackEnd` di bacon). Rilanciarlo sostituisce il segreto, ma solo finché non è confermato.
- `verify(User, string $code): bool`: `verifyKeyNewer($secret, $code, $user->two_factor_last_timestep, window: 1)` (±30 s di tolleranza sull'orologio). Se va bene salva il nuovo timestep, così lo stesso codice non vale due volte.
- `confirm(User, string $code)`: verifica, imposta `confirmed_at`, genera e restituisce 8 codici di recupero.
- `useRecoveryCode(User, string $code): bool`: cerca l'hash e lo consuma.
- `disable(User)`: azzera le quattro colonne.

Il segreto è cifrato con `APP_KEY`. Se si ruota la chiave va messa la vecchia in `APP_PREVIOUS_KEYS`, altrimenti la 2FA di tutti gli utenti smette di funzionare. Vale anche per un restore da backup su un ambiente con una chiave diversa.

### 3.3 Login in due passi

[LoginRequest::authenticate](../../backend/app/Http/Requests/Auth/LoginRequest.php) cambia così:

```php
if (! Auth::validate($credentials)) { /* hit + errore come oggi */ }
RateLimiter::clear(...);
$user = Auth::getLastAttempted();
if ($user->hasTwoFactor()) {
    session()->put('login.2fa', ['id' => $user->id, 'remember' => $this->boolean('remember'), 'expires' => now()->addMinutes(5)->timestamp]);
    return false;          // controller: 200 {two_factor: true}, nessun utente in risposta
}
Auth::login($user, $this->boolean('remember'));
return true;
```

- `Auth::validate()` controlla la password senza aprire la sessione. `getLastAttempted()` restituisce l'utente senza una seconda query.
- Il controller risponde `{"two_factor": true}` con stato 200. Non usa 401 né 422, così l'interceptor e la gestione errori del form non lo prendono per un errore.
- `POST /auth/two-factor-challenge` (pubblica, `throttle:5,1`, nessun `auth:sanctum`): legge `login.2fa` dalla sessione. Se manca o è scaduto → 422 «Accedi di nuovo». Altrimenti verifica `code` oppure `recovery_code`. Se va bene: `Auth::loginUsingId($id, $remember)`, `session()->forget('login.2fa')`, `session()->regenerate()` (contro la session fixation), risposta `UserResource`.
- **IDOR**: l'utente da completare arriva solo dalla sessione lato server, mai dal body. Chi chiama la challenge senza aver prima superato la password non ha `login.2fa` e riceve 422.
- **«Ricordami»**: il cookie viene emesso solo dopo la challenge. Da lì quel dispositivo non chiede più il codice per 400 giorni, come succede con Fortify e con i servizi più comuni (il dispositivo diventa fidato). Si revoca cambiando la password, che rigenera il token. Un «non chiedere più su questo dispositivo» separato dal «Ricordami» non serve.

### 3.4 Gestione da Impostazioni (`auth:sanctum`)

| Metodo | Path | Body | Risposta |
|--------|------|------|----------|
| POST | `/api/auth/two-factor` | `current_password` | `{secret, otpauth_url, qr_svg}`. 409 se già confermata |
| POST | `/api/auth/two-factor/confirm` | `code` | `{recovery_codes: [...]}` (unica volta in chiaro) + nuovo `remember_token` e `guard->login($user, $remembered)` come in `updatePassword` |
| DELETE | `/api/auth/two-factor` | `current_password` | 204 |
| POST | `/api/auth/two-factor/recovery-codes` | `current_password` | `{recovery_codes: [...]}`, i vecchi non valgono più |

- `current_password` usa la regola `current_password` già usata in `UpdatePasswordRequest`. Così chi ha rubato una sessione non può attivare la 2FA al posto del proprietario (chiudendolo fuori) né disattivarla.
- Rotte con `throttle:5,1` come quelle di prova email e password.
- Il nuovo `remember_token` alla conferma serve a far rientrare dalla porta col codice anche i dispositivi già collegati con «Ricordami». Il ricollegamento del dispositivo corrente riusa il blocco di `updatePassword` (righe 112–123): conviene estrarlo in un metodo privato `rotateRememberToken(Request, User)`.

### 3.5 Recupero d'emergenza

`php artisan user:two-factor-disable {email}` (via `docker compose exec php`) chiama `disable()` e scrive una riga nel log. Senza questo, chi perde telefono e codici di recupero resta fuori. Un percorso «disattiva via email» non va fatto: la casella email è proprio il fattore che la 2FA serve a non rendere sufficiente da solo.

### 3.6 Frontend

- [auth.ts](../../frontend/src/stores/auth.ts): `login()` restituisce `'ok' | 'two_factor'` e imposta l'utente solo nel primo caso. Nuovo `twoFactorChallenge({code} | {recovery_code})`, più `enableTwoFactor`, `confirmTwoFactor`, `disableTwoFactor` e `regenerateRecoveryCodes`.
- [LoginView](../../frontend/src/views/LoginView.vue): se la risposta è `two_factor`, mostra il secondo passaggio nella stessa vista (niente nuova rotta). Campo con `inputmode="numeric"`, `autocomplete="one-time-code"`, `maxlength=6` e focus automatico, link «Usa un codice di recupero» che cambia il campo, «Indietro» per tornare al form. Il redirect `?redirect=` resta valido dopo la challenge.
- [SettingsView](../../frontend/src/views/SettingsView.vue): card «Verifica in due passaggi» dopo «Password».
  - Disattiva: si chiede la password, poi si mostrano il QR come `<img :src="'data:image/svg+xml;base64,' + btoa(svg)">` (niente `v-html`) e il segreto in chiaro con un pulsante copia, per chi aggiunge l'account a mano. Poi il campo del codice e «Conferma».
  - Dopo la conferma: i codici di recupero con «Copia» e «Scarica .txt» e un avviso che non verranno più mostrati.
  - Attiva: stato, «Rigenera codici di recupero», «Disattiva» (entrambi chiedono la password e «Disattiva» chiede conferma).
- `UserResource.two_factor_enabled` va nel tipo `User` del frontend.

### 3.7 Test (`backend/tests/Feature/Auth/TwoFactorTest.php`)

Codici generati nel test con `Google2FA::getCurrentOtp($secret)`. Richieste come la SPA (`Referer` stateful + `withCredentials()`), come in `RememberMeTest`.

- Utente senza 2FA: login invariato.
- Attivazione → conferma con un codice sbagliato (422) e con quello giusto (8 codici di recupero).
- Login con 2FA: la risposta è `two_factor: true` e `/auth/me` dà 401 finché la challenge non è superata.
- Challenge: codice giusto, codice sbagliato, **stesso codice riusato** (rifiutato), codice di recupero (valido una volta sola), richiesta senza password prima (422), oltre 5 minuti (422).
- `remember` mantenuto attraverso la challenge (cookie recaller presente).
- Disattivazione e rigenerazione dei codici senza la password giusta → 422.
- Command `user:two-factor-disable`.

## 4. Impatti e possibili regressioni

Branch di riferimento: `master`.

- **Login per chi non usa la 2FA**: il percorso cambia (`validate` + `login` al posto di `attempt`) ma il risultato è lo stesso. Rate limit, messaggi `auth.failed`/`auth.throttle` ed evento `Lockout` restano. L'evento `Attempting` di `attempt()` non viene più emesso, ma nessun listener in `backend/app` lo usa (verificato). I test di login esistenti in `tests/Feature/Auth` devono passare senza modifiche.
- **Forma della risposta di `/auth/login`**: per chi ha la 2FA arriva `{two_factor: true}` senza `data`. Solo lo store auth la legge. La PWA non tiene in cache il JS (service worker vuoto), quindi non ci sono client vecchi.
- **«Ricordami» e PWA**: i dispositivi già collegati devono rifare il login, col codice, dopo l'attivazione (per via del nuovo `remember_token`). È voluto ma va detto nel testo della card. I dispositivi già fidati non chiedono il codice finché il cookie è valido.
- **Recupero password**: non cambia e non scavalca la 2FA, perché il reset non fa entrare.
- **Command schedulati** (`notifications:scan`, `rules:apply`): usano `loginUsingId` e `forgetUser`, che non passano dal login. Nessun impatto.
- **`APP_KEY` e backup**: il segreto è cifrato, quindi un restore su un ambiente con una chiave diversa rende inutilizzabile la 2FA (si sblocca col command di §3.5). Da aggiungere alla nota sul deploy in `AGENTS.md`.
- **Orologio del VPS**: il TOTP dipende dall'ora del server. Il container usa l'ora dell'host, quindi va controllato che NTP sia attivo sul VPS. `window: 1` assorbe fino a ±30 s.
- **Dipendenze**: due pacchetti composer (`pragmarx/google2fa` ha già come dipendenza `paragonie/constant_time_encoding`; `bacon/bacon-qr-code` ha `dasprove/enum`). Larastan livello 5: vanno annotati i tipi delle colonne in `User`.
- **Sicurezza**: la challenge ha il suo throttle (5/min per IP) oltre al lockout della password. Il segreto non esce mai da `UserResource`. Il QR passa solo nella risposta di `enable` all'utente autenticato che ha appena reinserito la password.
