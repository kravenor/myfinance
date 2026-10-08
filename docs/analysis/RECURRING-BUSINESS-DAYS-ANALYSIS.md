# Analisi fix/recurring-business-days

## 1. Flusso attuale
- [RecurringTransactionRunner](../../backend/app/Services/RecurringTransactionRunner.php) seleziona le ricorrenti attive con `next_run_at <= oggi` e per ogni scadenza crea una `Transaction` con `occurred_at = next_run_at`, poi calcola la scadenza successiva con `advance()` (giorni/settimane/mesi `*NoOverflow`).
- Nessun controllo su weekend o festivi: una scadenza di sabato genera un movimento di sabato, anche per la rata PAC (quotazione di un giorno a mercato chiuso).
- [ExpenseForecastService](../../backend/app/Services/ExpenseForecastService.php) (`incomePerMonth`, `aggregateRecurring`) e [ReportService](../../backend/app/Services/ReportService.php) (cash-flow forecast) proiettano le stesse scadenze con un `advance()` duplicato, assegnandole al mese finanziario con `FinancialMonth::key()`.
- La UI ([RecurringView](../../frontend/src/views/RecurringView.vue)) mostra come «prossima» il `next_run_at`.

## 2. Modifiche da apportare
- Nuovo helper `App\Support\BusinessDay` (`next`, `isBusinessDay`) con weekend e festivi nazionali per paese (oggi `IT`).
- Runner: data del movimento = `BusinessDay::next(next_run_at)`, registrato solo quando quella data è arrivata; cadenza che avanza dalla data teorica.
- Forecast e report: stessa data spostata per mese di competenza, tasso di cambio e confini dell'orizzonte.
- Resource: nuovo campo `next_occurs_on`, mostrato in UI al posto di `next_run_at`, con una frase nel testo di aiuto della data di inizio.
- Test: `BusinessDayTest` e un nuovo caso nel test del runner; aggiornati due test che usavano il 1/1/2026 (festivo).

## 3. Dettaglio dei fix
1. **[BusinessDay](../../backend/app/Support/BusinessDay.php)**: avanza di un giorno finché la data cade nel weekend o in un festivo. Festivi in due costanti per paese: `FIXED` (`m-d`) ed `EASTER` (giorni di distanza da Pasqua, `1` = Pasquetta). La Pasqua è calcolata con l'algoritmo gregoriano anonimo perché `easter_date()` richiede `ext-calendar`, che non è installata in `docker/php/Dockerfile` (verificata uguale a `easter_days()` per gli anni dal 1900 al 2300). Cache statica per paese e anno. Un paese sconosciuto salta solo i weekend.
2. **Runner**: `next_run_at` resta l'ancora della cadenza. Se si spostasse anche quella, una ricorrente del 14 caduta di sabato diventerebbe «il 16» per sempre. La query di selezione resta `next_run_at <= oggi`: la data spostata è sempre successiva o uguale a quella teorica, quindi il filtro sulla data spostata si fa nel loop. Sabato e domenica non viene creato nulla, lunedì sì. `last_run_at` registra la data effettiva.
3. **Proiezioni**: nei tre loop il cursore resta la data teorica (avanzamento e `ends_on`), mentre `$on = BusinessDay::next($cursor)` decide mese finanziario, conversione e appartenenza all'orizzonte `[start, end]`.
4. **Resource/UI**: `next_occurs_on` evita che la lista mostri una data (es. sabato 14) diversa da quella del movimento che verrà creato (lunedì 16).
5. **Più nazioni**: il paese è già un parametro di `BusinessDay`. Per abilitarne un'altra basta aggiungere le voci in `FIXED`/`EASTER` e passare il paese dai chiamanti. Fonte consigliata: una colonna `accounts.country`, perché conta il calendario della banca del conto, non la valuta. Non introdotta ora.

## 4. Impatti e possibili regressioni
Riferimento: `master`.
- **Date dei movimenti**: le scadenze in weekend o festivi ora producono movimenti datati al primo lavorativo successivo. Le transazioni già create non cambiano.
- **Mese finanziario**: una scadenza a cavallo del confine (es. `month_start_day = 1` e scadenza sabato 31) ora conta nel mese successivo in budget, report e previsioni. È voluto: rispecchia quando il movimento avviene davvero.
- **Cadenza giornaliera**: le scadenze di sabato e domenica slittano tutte a lunedì, che riceve tre movimenti. È coerente con la regola.
- **PAC**: la rata usa la quotazione del giorno lavorativo, che è un miglioramento. Le notifiche PAC riportano la data effettiva (`last_run_at`).
- **API**: campo aggiunto `next_occurs_on`; `next_run_at` invariato, quindi nessuna rottura per i client.
- **Preesistente, fuori scope**: `addMonthsNoOverflow` applicato a `next_run_at` porta una mensile del 31 al 28 dopo febbraio (31/1 → 28/2 → 28/3). Non è introdotto da questo fix ed è annotato nella coda di AGENTS.md §7.
- **Da verificare a mano**: lista Ricorrenti (colonna «Prossima»), cash-flow forecast in Statistiche e pagina Previsioni con una ricorrente che scade in un weekend.
