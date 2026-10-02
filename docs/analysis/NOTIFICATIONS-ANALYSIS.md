# Analisi — Notifiche: tempestività, push, nuovi avvisi, limiti

> Quattro passi in ordine, uno per branch: (1) avvisi tempestivi, (2) push sulla PWA, (3) nuovi tipi di avviso, (4) limiti attuali.
> Riferimento: [AGENTS.md §18](../../AGENTS.md).

> **Decisioni (2026-10-02):** push con `laravel-notification-channels/webpush`; nuovi avvisi: rata PAC, quotazioni ferme, spesa importante, riepilogo mensile. «Budget sforato» esiste già: dal passo 1 arriva in tempo reale (da chiarire al passo 3 se serve un'opzione «solo sforato»).

## 1. Flusso attuale

- **Un solo produttore**: `notifications:scan` alle 07:00 ([ScanNotifications](../../backend/app/Console/Commands/ScanNotifications.php) → [NotificationScanner::scan](../../backend/app/Services/NotificationScanner.php)), per ogni utente: budget del mese corrente sopra soglia ([BudgetAlertService](../../backend/app/Services/BudgetAlertService.php)) e obiettivi attivi con scadenza `behind`/`overdue`.
- **Due tipi**: [BudgetThresholdNotification](../../backend/app/Notifications/BudgetThresholdNotification.php), [SavingsGoalRiskNotification](../../backend/app/Notifications/SavingsGoalRiskNotification.php); `via()` da [ChannelsFromPreferences](../../backend/app/Notifications/Concerns/ChannelsFromPreferences.php): `database` sempre, `mail` se preferenza utente + kill-switch `FINANCE_NOTIFY_MAIL`.
- **Dedup**: `dispatch()` salta se esiste già una notifica dell'utente con lo stesso `data->key` (`budget:{status}:{id}:{Y-m}`, `goal:{status}:{id}:{Y-m}`).
- **Invio sincrono**: le notification non implementano `ShouldQueue`; mail inviate dentro la scansione.
- **Frontend**: [stores/notifications.ts](../../frontend/src/stores/notifications.ts) caricato una volta al mount di [AppLayout](../../frontend/src/components/AppLayout.vue); [NotificationsView](../../frontend/src/views/NotificationsView.vue) segna lette/elimina (DELETE cancella la riga).
- **Infrastruttura**: `QUEUE_CONNECTION=redis` ovunque; worker `queue` solo in `docker-compose.vps.yml` (in sviluppo nulla consuma la coda). [public/sw.js](../../frontend/public/sw.js) vuoto di proposito. `.env.production` locale con `MAIL_MAILER=log`.

### Limiti
1. Uno sforamento delle 10 arriva come avviso la mattina dopo.
2. Nessuna notifica fuori dall'app (push).
3. Solo budget e obiettivi.
4. a) eliminare una notifica la fa tornare alla scansione successiva (la dedup guarda le righe esistenti); b) il badge non si aggiorna con l'app aperta; c) email non configurate in produzione; d) invio sincrono; e) le notifiche non vengono mai cancellate.

## 2. Modifiche da apportare

### Passo 1 — Avvisi tempestivi
1. `NotificationScanner::scanAfterResponse(User)`: `dispatch(fn () => $this->scan($user))->afterResponse()` — eseguito dopo l'invio della risposta, nello stesso processo (non serve un worker), una volta per richiesta.
2. Chiamato dove cambiano i dati che gli avvisi leggono: creazione/modifica/eliminazione di transazioni, commit dell'import, creazione/modifica di budget e obiettivi, salvataggio delle preferenze notifiche.
3. La scansione delle 07:00 resta: cattura i cambi dovuti solo al tempo (un obiettivo che diventa «in ritardo» senza nuove transazioni) e le ricorrenti generate di notte.

### Passo 2 — Push sulla PWA (Web Push)
1. Dipendenza `laravel-notification-channels/webpush` (canale Laravel su `minishlink/web-push`; richiede `bcmath`, già presente). **Da confermare** (CLAUDE.md: niente librerie fuori stack senza conferma).
2. Chiavi VAPID in `.env` (`php artisan webpush:vapid`), tabella `push_subscriptions` della libreria, endpoint `POST/DELETE /api/push-subscriptions`.
3. `sw.js`: handler `push` (mostra la notifica) e `notificationclick` (apre/porta in primo piano l'URL della notifica).
4. Impostazioni → card «Notifiche su questo dispositivo»: attiva/disattiva (il permesso va chiesto da un gesto dell'utente), stato del permesso, avviso per iOS (serve la PWA installata, iOS 16.4+).
5. `ChannelsFromPreferences`: canale push se l'utente ha almeno una sottoscrizione; le sottoscrizioni scadute (410) si cancellano.

### Passo 3 — Nuovi tipi di avviso (da scegliere)
Candidati, ognuno con un toggle in Impostazioni e la stessa dedup:
- **Rata PAC registrata** (o registrata solo come movimento di cassa per quotazione mancante), dal runner delle ricorrenti.
- **Ricorrenti eseguite**: riepilogo giornaliero («3 ricorrenti registrate oggi»), non una notifica per ricorrente.
- **Quotazioni ferme**: strumento in portafoglio con ultima quotazione più vecchia di 7 giorni (provider rotto o simbolo sbagliato).
- **Spesa importante**: transazione di uscita oltre una soglia impostabile.
- **Riepilogo mensile**: a inizio mese entrate, uscite e risparmio del mese chiuso.

### Passo 4 — Limiti
1. **Eliminate che tornano**: colonna `dismissed_at` su `notifications`; DELETE la valorizza invece di cancellare; lista e conteggio escludono le nascoste; la dedup continua a vederle.
2. **Badge**: refresh ogni 5 minuti a pagina visibile, al ritorno in primo piano (`visibilitychange`) e all'arrivo di una push (messaggio dal service worker).
3. **SMTP**: configurazione OVH nel `.env.production` del VPS (a cura tua) + pulsante «Invia email di prova» in Impostazioni (`POST /api/notification-preferences/test-email`) per verificarla.
4. **Asincrono**: notification `ShouldQueue` con `viaConnections()` = `database` sincrono, `mail`/push in coda; servizio `queue` anche nel `docker-compose.yml` di sviluppo.
5. **Pulizia**: comando `notifications:prune --days=180` settimanale (le chiavi di dedup contengono il mese, oltre 180 giorni non servono più).

## 3. Dettaglio dei fix

Dettagli implementativi per passo nelle sezioni dei commit; scelte trasversali:
- Un branch per passo, in ordine; ogni passo è rilasciabile da solo.
- Dedup invariata come concetto (chiave per stato/periodo); con il passo 4 diventa robusta all'eliminazione.
- Lo scope utente nelle scansioni dopo la risposta è quello della richiesta (`Auth::user()` già impostato); nei command resta `loginUsingId` + `forgetUser`.

## 4. Impatti e possibili regressioni

Branch di riferimento: `master`.

- **Passo 1**: ogni scrittura di transazione/budget/obiettivo aggiunge, dopo la risposta, una scansione dell'utente (1 query budget + 1 per obiettivo con conto). Latenza percepita invariata; import di centinaia di righe = una sola scansione. Con mail sincrone (fino al passo 4) un SMTP lento rallenta solo il processo PHP dopo la risposta.
- **Passo 2**: nuova dipendenza e chiavi VAPID da impostare sul VPS; senza chiavi il canale push resta spento. iOS solo da PWA installata.
- **Passo 3**: più notifiche; ogni tipo disattivabile.
- **Passo 4**: migration su `notifications`; con `ShouldQueue` le mail dipendono dal worker `queue` (presente sul VPS, da aggiungere in sviluppo).
