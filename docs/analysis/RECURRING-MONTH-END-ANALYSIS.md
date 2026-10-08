# Analisi fix/recurring-month-end

## 1. Flusso attuale
- La scadenza successiva di una ricorrente si calcola dalla precedente con `addMonthsNoOverflow` / `addYearsNoOverflow`, tramite un metodo privato `advance()` copiato identico in [RecurringTransactionRunner](../../backend/app/Services/RecurringTransactionRunner.php), [ExpenseForecastService](../../backend/app/Services/ExpenseForecastService.php) (ricorrenti e voci degli scenari) e [ReportService](../../backend/app/Services/ReportService.php).
- Quando un mese più corto accorcia la data, il giorno originale si perde: una mensile del 31 fa 31/1 → 28/2 → 28/3 → 28/4. Un'annuale del 29/2 resta 28/2 anche negli anni bisestili.

## 2. Modifiche da apportare
- Nuovo helper `App\Support\Cadence::advance()` che sostituisce le tre copie di `advance()`.
- Per mensile, trimestrale e annuale il risultato torna al giorno di ancoraggio, limitato alla lunghezza del mese.
- Ancoraggio: il giorno di `starts_on` per le ricorrenti, il giorno di `starts_on` della voce per gli scenari.
- Test: `CadenceTest` e un caso nel test del runner.

## 3. Dettaglio dei fix
1. **[Cadence](../../backend/app/Support/Cadence.php)**: stessa `match` di prima. Se `anchorDay` è passato e la cadenza è a mesi, imposta il giorno a `min(anchorDay, giorni del mese)`. Giornaliera, settimanale e quindicinale ignorano l'ancoraggio. Il ramo `one_time` di `ExpenseForecastService::advance()` era irraggiungibile (`scenarioOccurrences` esce prima) ed è stato tolto.
2. **Perché `starts_on`**: è sempre presente e il form non invia mai `next_run_at`, che si imposta solo via API, quindi è già il riferimento di fatto. Cambiare il giorno di inizio in modifica cambia le scadenze dopo quella già in programma, coerente con il testo di aiuto («In modifica non sposta la prossima scadenza»).
3. **Chiamanti**: runner e le due proiezioni delle ricorrenti passano `starts_on->day`; `scenarioOccurrences` passa il giorno della data di partenza della voce.

## 4. Impatti e possibili regressioni
Riferimento: `master` (branch costruito sopra `fix/recurring-business-days`, da unire dopo).
- **Ricorrenti già spostate al 28**: si sistemano da sole dalla scadenza dopo quella già in programma, perché `starts_on` contiene ancora il giorno originale. Quella in programma resta com'è (es. 28/10 invece di 31/10). Nessuna migration dei dati.
- **`next_run_at` impostato via API con un giorno diverso da `starts_on`**: dopo la prima esecuzione le scadenze tornano al giorno di `starts_on`. Dalla UI non si può fare.
- **Previsioni e report**: per le ricorrenti di fine mese gli importi cadono nei giorni corretti; i totali mensili cambiano solo se un mese finanziario inizia tra il 29 e il 31 (non possibile: `month_start_day` è limitato a 28).
- **Scenari**: una voce mensile che parte il 31 ora torna al 31 dopo febbraio invece di restare al 28.
- **Da verificare a mano**: lista Ricorrenti con una mensile del 31, pagina Previsioni con una voce di scenario del 31.
