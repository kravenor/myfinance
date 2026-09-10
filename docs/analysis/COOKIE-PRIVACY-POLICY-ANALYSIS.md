# Analisi — Cookie policy e Privacy policy

> Scope: due pagine informative pubbliche nella SPA (`/privacy`, `/cookie`) + link nei punti di
> ingresso. **Nessun cookie banner**: l'app usa solo cookie tecnici di prima parte, esenti da
> consenso (Linee guida Garante Privacy 10/06/2021, §4 — obbligo di sola informativa).
> Nessuna modifica al backend, nessuna migration.

## 1. Flusso attuale

- **Rotte SPA** ([frontend/src/router/index.ts](../../frontend/src/router/index.ts)): 4 rotte `meta.guest`
  (login, register, forgot/reset-password), tutto il resto sotto `AppLayout` con `meta.requiresAuth`.
  Catch-all finale `/:pathMatch(.*)*` → redirect a `/`. Nessuna rotta pubblica accessibile
  *anche* da utente autenticato.
- **Cookie effettivamente impostati** (tutti di prima parte, tutti tecnici):
  | Cookie | Origine | Scopo | Durata |
  |---|---|---|---|
  | `laravel_session` | Laravel session (`SESSION_DRIVER=redis`) | ID di sessione, mantiene l'autenticazione | `SESSION_LIFETIME=120` min |
  | `XSRF-TOKEN` | Sanctum `/sanctum/csrf-cookie` | protezione CSRF | durata sessione |
  | `remember_web_*` | flag "Ricordami" ([LoginRequest.php:35](../../backend/app/Http/Requests/Auth/LoginRequest.php)) | login persistente opzionale | 5 anni (default Laravel) |
  Config in [config/session.php](../../backend/config/session.php): `http_only=true`, `same_site=lax`.
- **localStorage** (non cookie, ma va dichiarato): `src/stores/menu.ts:37` (stato sidebar) e
  `src/views/ReportsView.vue:45` (filtri report). Solo preferenze UI, nessun dato personale.
- **Nessuna terza parte lato browser**: [frontend/index.html](../../frontend/index.html) non carica
  script, font o risorse esterne; `public/sw.js` è un service worker vuoto senza cache; nessun
  analytics/tag manager.
- **Terze parti lato server** (chiamate dal backend, mai dal browser, senza dati personali —
  solo ticker/ISIN): `api.frankfurter.app`, `query1.finance.yahoo.com`, `api.coingecko.com`,
  `www.borsaitaliana.it` ([config/finance.php](../../backend/config/finance.php)).
- **Dati personali trattati**: tabella `users` (nome, email, hash password, `currency`, `locale`,
  `date_format`, `month_start_day`, preferenze notifiche), tabella `sessions` (`ip_address`,
  `user_agent`), `password_reset_tokens` (email + token, 60 min), più tutti i dati finanziari
  (conti, transazioni, budget, investimenti…) scoped per `user_id`.
- **Diritti già coperti dal prodotto**: portabilità via export CSV
  ([ImportExportView.vue](../../frontend/src/views/ImportExportView.vue)). **Non** coperta la
  cancellazione self-service dell'account (vedi §4).

## 2. Modifiche da apportare

1. `frontend/src/views/PrivacyView.vue` — informativa privacy (testo statico).
2. `frontend/src/views/CookieView.vue` — cookie policy con la tabella dei 3 cookie tecnici + localStorage.
3. Router: rotte `/privacy` e `/cookie` **senza** `meta.guest` e **senza** `requiresAuth`, inserite prima del catch-all.
4. `frontend/src/components/LegalLinks.vue` — riga di link riusabile (3 punti di utilizzo).
5. Link in `LoginView.vue`, `RegisterView.vue` (+ frase informativa sotto il submit) e nel footer di `AppLayout.vue`.
6. `AGENTS.md`: §3 (nuovi file), §7 (fase completata + data).
7. **Da fornire dall'utente** prima della stesura definitiva: nominativo/ragione sociale del titolare, email di contatto, dominio pubblico, provider di hosting e provider SMTP (una volta uscito da `MAIL_MAILER=log`).

## 3. Dettaglio dei fix

### 3.1 Niente cookie banner
I tre cookie sono strettamente necessari all'erogazione del servizio (sessione, CSRF, login
persistente su scelta esplicita dell'utente). Nessun cookie di profilazione, di analytics o di
terza parte → nessun consenso preventivo, nessun blocco preventivo degli script, nessuna libreria
di consent management. Serve solo l'informativa raggiungibile da ogni pagina.
Se in futuro si aggiunge analytics (anche self-hosted con cookie), il banner diventa obbligatorio:
è l'unico trigger da tenere a mente.

### 3.2 Rotte (`frontend/src/router/index.ts`)
```ts
{ path: '/privacy', name: 'privacy', component: () => import('@/views/PrivacyView.vue') },
{ path: '/cookie', name: 'cookie', component: () => import('@/views/CookieView.vue') },
```
Da inserire **prima** di `{ path: '/:pathMatch(.*)*', redirect: '/' }`.
Attenzione: **non** mettere `meta.guest` (altrimenti l'utente loggato viene rimbalzato in dashboard)
né `requiresAuth` (le pagine devono essere leggibili prima della registrazione). Il `beforeEach`
esistente esegue comunque `auth.fetchMe()` alla prima navigazione: innocuo, ritorna 401 e prosegue.

### 3.3 Contenuto informativa privacy (`PrivacyView.vue`)
Struttura minima conforme agli artt. 13-14 GDPR:
1. **Titolare del trattamento** e contatti (dato da fornire, §2.7).
2. **Dati trattati**: quelli elencati in §1 — anagrafici minimi (nome, email), credenziali (hash),
   preferenze, dati tecnici di sessione (IP, user agent), dati finanziari inseriti dall'utente.
3. **Finalità e base giuridica**: erogazione del servizio ed esecuzione del contratto (art. 6.1.b);
   sicurezza e prevenzione abusi (art. 6.1.f) per IP/user agent; nessun marketing, nessuna profilazione.
4. **Natura del conferimento**: necessario per la registrazione; i dati finanziari sono facoltativi
   e inseriti dall'utente.
5. **Destinatari/responsabili**: provider di hosting, provider SMTP (email di reset password e alert
   budget). Le fonti di mercato (Frankfurter, Yahoo Finance, CoinGecko, Borsa Italiana) ricevono solo
   identificativi di strumenti finanziari, **nessun dato personale**.
6. **Trasferimenti extra-UE**: da dichiarare in base al provider scelto (Yahoo/CoinGecko sono US, ma
   ricevono solo ticker → non è un trasferimento di dati personali).
7. **Conservazione**: dati dell'account fino alla richiesta di cancellazione; sessioni 120 minuti
   (`SESSION_LIFETIME`); token di reset password 60 minuti; `remember_web_*` fino a 5 anni o logout.
8. **Diritti dell'interessato** (artt. 15-22): accesso, rettifica, cancellazione, limitazione,
   portabilità (l'export CSV in Import/Export copre già l'art. 20), opposizione, reclamo al Garante.
   Modalità di esercizio: email al titolare.
9. **Sicurezza**: password con hash bcrypt, cookie `HttpOnly`, protezione CSRF, HTTPS in produzione,
   scoping dei dati per utente.
10. **Data di ultimo aggiornamento** in fondo alla pagina (stringa hardcoded, aggiornata a mano).

### 3.4 Contenuto cookie policy (`CookieView.vue`)
- Cos'è un cookie, in 3 righe.
- Tabella dei 3 cookie tecnici (nome, finalità, durata) copiata da §1.
- Sezione **localStorage**: `finance.menu` e i filtri report — preferenze di interfaccia, restano
  sul dispositivo, non vengono inviate al server.
- Dichiarazione esplicita: nessun cookie di profilazione, analitico o di terza parte → per i cookie
  tecnici non è richiesto consenso; si possono comunque bloccare dal browser, con la conseguenza che
  login e sicurezza CSRF non funzionano.
- Rimando alla privacy policy.

### 3.5 Link (`LegalLinks.vue` + 3 punti)
```vue
<!-- LegalLinks.vue -->
<template>
  <p class="text-xs text-slate-500 text-center space-x-3">
    <RouterLink to="/privacy" class="hover:underline">Privacy policy</RouterLink>
    <RouterLink to="/cookie" class="hover:underline">Cookie policy</RouterLink>
  </p>
</template>
```
- [LoginView.vue](../../frontend/src/views/LoginView.vue): sotto il blocco "Registrati" (riga ~59).
- [RegisterView.vue](../../frontend/src/views/RegisterView.vue): sotto il link "Accedi" (riga ~61),
  con la frase «Registrandoti dichiari di aver letto l'informativa privacy». **Nessuna checkbox di
  consenso**: la base giuridica è il contratto (art. 6.1.b), non il consenso — una checkbox
  obbligatoria sarebbe giuridicamente sbagliata oltre che inutile.
- [AppLayout.vue](../../frontend/src/components/AppLayout.vue): dentro `<main>` (riga 125-127), dopo
  `<RouterView />`, come footer leggero.

### 3.6 Test
Nessuno: pagine statiche senza logica. `npm run type-check` e `npm run lint` sono la verifica
sufficiente. Se in futuro le rotte prendono logica (es. versioning dell'informativa), lì serve un test.

## 4. Impatti e possibili regressioni

Riferimento: branch di base `master`.

- **Router**: se le nuove rotte finissero *dopo* il catch-all `/:pathMatch(.*)*` verrebbero
  silenziosamente redirette a `/`. Verificare l'ordine e provare `/privacy` da utente **loggato** e
  **non loggato**.
- **Guard `beforeEach`**: la prima navigazione su `/privacy` da anonimo scatena `fetchMe()` → 401.
  Verificare che non finisca in un loop di redirect verso `/login` e che l'interceptor axios non
  mostri un errore all'utente.
- **AppLayout**: l'inserimento del footer dentro `<main>` può alterare le altezze di viste con
  scroll o grafici a piena altezza (Dashboard, Reports, Stats). Controllare a mobile, dove la PWA
  gira a schermo pieno.
- **PWA**: `public/sw.js` non ha cache, quindi le nuove pagine non richiedono invalidazioni.
  Nessun impatto sul manifest.
- **Nessun impatto backend**: nessuna rotta, nessuna migration, nessuna modifica ai cookie esistenti.
  Se in futuro si abbassa `remember_web_*` sotto i 5 anni, la cookie policy va aggiornata.
- **Disallineamento documentale**: ogni nuovo servizio esterno, provider SMTP o dato raccolto rende
  l'informativa inesatta. La coppia di pagine va rivista quando cambiano §2/§6 di `AGENTS.md`.
- **Gap noto — cancellazione account**: non esiste una funzione self-service; l'informativa dichiarerà
  la cancellazione su richiesta via email. Se serve l'automatismo (art. 17), è un task a parte
  (endpoint `DELETE /api/auth/account` + cascata sulle tabelle scoped).
