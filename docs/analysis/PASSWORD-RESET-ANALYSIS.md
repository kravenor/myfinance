# Analisi — Recupero password

> Scope: flusso "password dimenticata" via email, sfruttando il Password broker di Laravel.
> Due endpoint pubblici (richiesta link + reset con token) e due viste SPA. Nessuna migration:
> la tabella `password_reset_tokens` esiste già e `User` eredita `CanResetPassword` da
> `Illuminate\Foundation\Auth\User`.

## 1. Flusso attuale

- Auth: [AuthController](../../backend/app/Http/Controllers/Auth/AuthController.php) gestisce register/login/logout/me (Sanctum SPA cookie). Nessun recupero password.
- `password_reset_tokens` già creata nella migration `create_users_table`. Broker `users` configurato in `config/auth.php` (`expire=60`, `throttle=60`).
- Mail: `MAIL_MAILER=log` → le email finiscono in `storage/logs/laravel.log` (sufficiente in dev; in prod va configurato SMTP).
- Frontend: [LoginView](../../frontend/src/views/LoginView.vue) ha solo link a registrazione. Router con meta `guest`/`requiresAuth`.

## 2. Modifiche da apportare

1. `config/app.php`: aggiungere `frontend_url` (da `FRONTEND_URL`, fallback `APP_URL`) — già presente in `.env`.
2. `AppServiceProvider::boot`: `ResetPassword::createUrlUsing(...)` per puntare alla rotta SPA `/reset-password?token=…&email=…`.
3. FormRequest `ForgotPasswordRequest` (email) e `ResetPasswordRequest` (token, email, password confermata).
4. `AuthController::forgotPassword` (usa `Password::sendResetLink`) e `resetPassword` (usa `Password::reset`).
5. Rotte pubbliche `POST /api/auth/forgot-password` e `POST /api/auth/reset-password`.
6. Frontend: viste `ForgotPasswordView` e `ResetPasswordView`, rotte `guest`, metodi store, link in LoginView.
7. Test + `AGENTS.md` §8.1.

## 3. Dettaglio dei fix

### 3.1 Reset URL SPA (`AppServiceProvider`)
```php
ResetPassword::createUrlUsing(function (object $user, string $token): string {
    $base = rtrim((string) config('app.frontend_url'), '/');
    return $base.'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset());
});
```
`config('app.frontend_url') = env('FRONTEND_URL', env('APP_URL'))`.

### 3.2 Endpoint (`AuthController`)
- `forgotPassword(ForgotPasswordRequest)`:
  ```php
  $status = Password::sendResetLink($request->only('email'));
  // risposta generica per non rivelare l'esistenza dell'email
  return response()->json(['message' => __($status)], 200);
  ```
  Se `$status` è di throttle, Laravel ritorna comunque una stringa; manteniamo 200 con messaggio. (Niente enumeration: stessa risposta per email esistente/inesistente.)
- `resetPassword(ResetPasswordRequest)`:
  ```php
  $status = Password::reset($request->only('email','password','password_confirmation','token'),
      function (User $user, string $password) {
          $user->forceFill(['password' => Hash::make($password)])->setRememberToken(Str::random(60));
          $user->save();
          event(new PasswordReset($user));
      });
  return $status === Password::PasswordReset
      ? response()->json(['message' => __($status)])
      : response()->json(['message' => __($status)], 422);
  ```

### 3.3 FormRequest
- `ForgotPasswordRequest`: `email => [required, string, email]`.
- `ResetPasswordRequest`: `token => [required, string]`, `email => [required, string, email]`, `password => [required, confirmed, Password::defaults()]` (min 8).

### 3.4 Rotte (`routes/api.php`, pubbliche, sopra il gruppo auth:sanctum)
```php
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
```

### 3.5 Frontend
- Router: `/forgot-password` (ForgotPasswordView), `/reset-password` (ResetPasswordView), entrambe `meta.guest`.
- `ForgotPasswordView`: input email → `POST /auth/forgot-password` → messaggio generico "se l'email esiste, riceverai un link".
- `ResetPasswordView`: legge `token`/`email` da query → form password+conferma → `POST /auth/reset-password` → su successo redirect a `/login` con avviso.
- Store `auth`: `forgotPassword(email)`, `resetPassword(payload)`.
- LoginView: link "Password dimenticata?" → `/forgot-password`.

### 3.6 Test (`tests/Feature/Auth/PasswordResetTest.php`)
- `Notification::fake()` + forgot-password con email esistente → `assertSentTo($user, ResetPassword::class)`, 200.
- forgot-password con email inesistente → 200 (nessuna enumeration), nessuna notifica.
- reset-password con token valido (`Password::createToken`) → 200, login con nuova password ok.
- reset-password con token errato → 422.
- validazione: password non confermata → 422.

## 4. Impatti e possibili regressioni

> Branch di riferimento: **`master`**.

- API additiva: 2 nuovi endpoint pubblici. Nessuna modifica a register/login/logout/me. Nessuna migration.
- Enumeration: la risposta di forgot-password è generica e indipendente dall'esistenza dell'email.
- Throttle: il broker limita a 1 invio/60s per email (`config/auth.php`), oltre al rate limit globale.
- Mail in `log`: in dev il link è nel log; in prod serve SMTP (`MAIL_*`). Documentare in `AGENTS.md`/README come nota.
- CSRF/Sanctum: gli endpoint sono pubblici come login/register; il frontend usa `ensureCsrf()` già esistente.
- Sicurezza token: token hashed in tabella, scadenza 60 min (default Laravel). Reset invalida le sessioni? `setRememberToken` rigenera il remember token; le sessioni attive restano finché non scadono — comportamento standard Laravel, accettabile.
