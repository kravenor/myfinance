# 2. Registro movimenti investimenti (PAC): posizione derivata e serie storica in avanti

- **Stato:** Accettato
- **Data:** 2026-09-09
- **Contesto correlato:** [HoldingPositionRecalculator](../../backend/app/Services/HoldingPositionRecalculator.php), [InvestmentHistoryService](../../backend/app/Services/InvestmentHistoryService.php), [ADR 0001](0001-ordine-autofetch-vs-multitenant.md)

> Questo ADR è il **registro vivo delle decisioni** sull'area investimenti/PAC.
> Ogni modifica successiva aggiunge una decisione numerata (D11, D12, …) invece
> di riscrivere le esistenti: se una decisione viene ribaltata si marca
> *Superata da Dxx* e si lascia in fila, così resta leggibile il perché.

## Contesto

Un holding era una **fotografia**: `quantity` e `avg_cost` scritti a mano dal form, nessuno storico. Il valore di mercato si aggiornava da solo (`prices:fetch`), la quantità no. Gestire un PAC — un versamento al mese che compra nuove quote — significava riaprire l'holding ogni mese e ricalcolarsi la media ponderata a mano.

Sono state valutate tre profondità:

| | Cosa | Costo | Limite |
|---|---|---|---|
| **A** | Azione "Versamento": endpoint che ricalcola `quantity`/`avg_cost` in modo incrementale | 1 endpoint + 1 modale | Nessuno storico: un versamento sbagliato non è annullabile, "quanto ho versato" non esiste |
| **B** | Registro `investment_transactions`, posizione derivata | migration + backfill + CRUD + ricalcolo | Il backfill tocca dati reali |
| **C** | PAC schedulato che genera i versamenti da solo | tabella piani + command | Non è alternativo: sta sopra ad A o B |

**Scelta: B.** Il senso di un PAC è l'accumulo nel tempo; il confronto *capitale versato vs valore attuale* è la lettura per cui esiste, ed è irrecuperabile senza lo storico. A non ci sarebbe mai arrivato. C resta un livello successivo, e ha senso solo sopra B (vedi U3).

## Decisioni

### D1 — Il registro è la sola fonte della posizione

`investment_transactions` (buy/sell con data, quantità, prezzo, commissioni) è l'unico posto dove si scrive. `quantity` e `avg_cost` sull'holding non sono più dati inseriti ma **risultati**.

*Conseguenza pratica:* qualsiasi nuova funzione che debba cambiare la posizione crea un movimento, non aggiorna le colonne.

### D2 — Metodo di costo: media ponderata, non FIFO

Una vendita **non muove** `avg_cost`: scarica il costo medio delle quote vendute e realizza la differenza in `realized_pl`.

*Perché:* è già la convenzione del resto dell'app (il vecchio `avg_cost` era una media), è quella che serve per leggere un PAC, ed è indipendente dall'ordine dei lotti. FIFO servirebbe solo per la dichiarazione fiscale italiana sui titoli — se un giorno serve, vedi U4.

### D3 — Le quattro colonne derivate restano persistite come cache

`quantity`, `avg_cost`, `realized_pl`, `net_invested` sono ricalcolate a ogni scrittura e **salvate** sull'holding, invece di essere calcolate al volo.

*Perché:* `overview`, `index` e il patrimonio netto in [ReportService](../../backend/app/Services/ReportService.php) leggono la posizione di ogni holding. Derivarla a runtime significherebbe riattraversare il registro una volta per holding a ogni richiesta — un N+1 su un percorso caldo. La cache costa una riga di `UPDATE` per movimento scritto, cioè quasi mai.

*Invariante da non rompere:* **l'unico scrittore di quelle quattro colonne è `HoldingPositionRecalculator`.** Se un domani qualcos'altro le tocca, divergono in silenzio.

### D4 — `quantity` e `avg_cost` non sono più accettati in `PATCH /investment-holdings/{id}`

Sono usciti da `UpdateInvestmentHoldingRequest`. In `POST` restano, ma diventano il **movimento di apertura** (vedi D6), non una scrittura diretta.

*Perché:* lasciarli accettati significava avere due strade per cambiare la stessa cosa, di cui una sovrascriveva l'altra fino al ricalcolo successivo. Il form frontend li nasconde in modifica e mostra il rimando al registro.

### D5 — La coerenza è garantita dal ricalcolo dentro la transazione DB, non da una validazione a monte

Ogni store/update/destroy sul registro gira in `DB::transaction`: si scrive, poi si ricalcola. Se il ricalcolo trova la quantità sotto zero in un qualsiasi punto della sequenza solleva `ValidationException` su `quantity` e la scrittura viene annullata.

*Perché:* una validazione a monte dovrebbe simulare l'intero registro per sapere se la scrittura è lecita — cioè rifare il ricalcolo. Farlo una volta sola, dopo, e lasciare che sia il rollback a proteggere, copre gratis anche il caso difficile: la **vendita retrodatata** che sarebbe valida oggi ma non alla sua data.

### D6 — Le holding preesistenti ricevono un movimento di apertura

La migration di backfill crea per ognuna un `buy` con quantità e prezzo attuali, datato al `created_at` dell'holding, nota `Posizione iniziale`.

*Perché:* senza, al primo ricalcolo le posizioni esistenti andrebbero a zero. È l'unico punto della feature che tocca dati reali, ed è coperto da un test che esegue davvero la migration e poi ne ricalcola il risultato.

*Limite noto:* la data di apertura è quella di creazione dell'holding, non quella del vero primo acquisto. Chi vuole lo storico reale lo corregge a mano dal pannello movimenti.

### D7 — La serie storica va in avanti dal primo movimento, senza backfill delle quotazioni

`GET /api/investments/history` parte dal primo movimento del registro. Non si ricostruisce nulla prima.

*Perché:* `instrument_prices` accumula **in avanti** dal giorno in cui è partito `prices:fetch` (`PriceProvider::fetch()` prende solo l'ultima quotazione, non un range). Ricostruire il passato richiederebbe un import storico — fattibile su Yahoo per gli ETF, non su Borsa Italiana per i BTP, quindi una curva a copertura disomogenea. Decisione dell'utente: *«se parto oggi con l'investimento non ho bisogno di sapere quanto valeva ieri»*.

### D8 — Fallback di prezzo storico sul costo medio del momento, mai su `last_price`

Nel punto della serie, il prezzo di un holding è l'ultima quotazione con `as_of <= punto`. Se non ne esiste ancora una, si usa il **costo medio a quella data**.

*Perché:* `last_price` è un prezzo inserito a mano **oggi** e applicarlo a un mese passato produce un numero inventato. Il costo medio invece, all'inizio di un PAC, è il prezzo a cui hai appena comprato: valore e versato coincidono, che è la verità e non un artefatto. Man mano che le quotazioni si accumulano il fallback sparisce da solo.

### D9 — L'ultimo punto della serie è oggi, non fine mese

*Perché:* così l'ultimo valore del grafico combacia con i totali di `overview`. Un punto a fine mese futuro darebbe due numeri diversi per la stessa cosa nella stessa schermata.

### D10 — Nessun aggancio automatico alla transazione di cassa

Il bonifico verso il conto investment resta una `transfer` (o una ricorrente) registrata a parte. Non c'è `transaction_id` sul movimento.

*Perché non è solo pigrizia:* in un PAC reale il collegamento **non è 1:1**. Il bonifico parte il 25, l'eseguito arriva il 2 del mese dopo; a volte un versamento copre due acquisti. Una foreign key su una relazione che nella realtà è molti-a-molti e sfasata nel tempo peggiorerebbe il modello. E il costo vero non è creare il link ma **tenerlo sincronizzato**: ogni percorso di modifica e cancellazione raddoppia, con una domanda di design aperta (chi comanda, il movimento o la transazione?) che se risolta male fa divergere i due registri in silenzio.

*Non c'è doppio conteggio:* in [ReportService](../../backend/app/Services/ReportService.php) il saldo di un conto `investment` è il valore di mercato delle holding, non la somma delle sue transazioni.

### D11 — Il registro non ha una policy propria: autorizza quella dell'holding

`InvestmentTransactionController` chiama sempre `authorize('view'|'update', $investmentHolding)`. Un `InvestmentTransactionPolicy` era stato creato e poi rimosso: non veniva invocato da nessuna parte.

*Perché:* è la convenzione già in uso per le risorse annidate (`ScenarioItem` non ha una policy, comanda `ScenarioPolicy`). È il possesso del padre a dare accesso ai figli, e duplicare il controllo sul figlio aggiunge un file che nessuno chiama — quindi che nessuno tiene aggiornato.

*Nota:* la prima difesa resta comunque `UserScope`, che rende invisibile l'holding di un altro utente e fa rispondere **404** prima ancora che la policy entri in gioco. La policy copre i casi che lo scope non vede: `viewAny`/`create` (nessun record da filtrare) e le query con `withoutGlobalScopes()`.

## Percorsi di upgrade aperti

Ordinati per rapporto valore/costo. Ognuno indica cosa toccare.

- **U1 — Backfill storico delle quotazioni.** Sblocca la curva del valore prima dell'attivazione del fetch. Serve un metodo a range su `PriceProvider` (oggi `fetch(array $symbols)` prende solo l'ultima) e un import una tantum. Copre gli ETF via Yahoo; per i BTP su Borsa Italiana lo storico non è disponibile allo stesso modo, quindi la curva resterebbe disomogenea per asset type. Ribalta D7 solo in parte: la serie continuerebbe a partire dal primo movimento.
- **U2 — Rendimento annualizzato (XIRR/TWR).** I dati ci sono già tutti: il registro ha i flussi con le date, la serie ha i valori. È solo un calcolo da aggiungere a `InvestmentHistoryService`. Nessuna decisione da ribaltare.
- **U3 — PAC schedulato (opzione C).** Tabella `investment_plans` (holding, importo, cadenza, `next_run_at`) e command `investments:run-plans`, ricalcando `recurring_transactions` + `RecurringTransactionRunner`. Il prezzo del giorno è già disponibile in `instrument_prices`. **Attenzione:** il prezzo di esecuzione reale di un PAC su fondo non è l'EOD e arriva con giorni di ritardo, quindi il movimento generato sarà sempre da correggere sull'eseguito del broker — cosa che con il registro (D1) costa una modifica, mentre con l'opzione A sarebbe stata un ricalcolo a mano.
- **U4 — FIFO come metodo di costo alternativo.** Ribalterebbe D2. Richiede di tenere i lotti aperti, non solo la media: il ricalcolo diventa una coda di lotti invece di due accumulatori. Da fare solo se l'app deve servire la dichiarazione fiscale.
- **U5 — Aggancio alla transazione di cassa.** Ribalta D10. Prima di implementarlo va sciolta la domanda "chi comanda" e va deciso il comportamento su ognuno dei percorsi (elimina movimento, modifica importo, elimina la transazione dall'altra pagina). Se si fa, la relazione va pensata molti-a-molti, non `transaction_id` singolo.
- **U6 — Grafico per singolo holding.** Oggi la serie è di portafoglio. `InvestmentHistoryService::monthly()` calcola già per holding e poi somma: esporre il dettaglio è aggiungere un livello all'output, non riscrivere.
- **U7 — Filtro di periodo sulla serie.** Oggi l'endpoint non prende parametri e restituisce tutto (granularità mensile, quindi 10 anni = 120 punti). Se serve, `from`/`to` opzionali.

## Note per chi tocca questo codice

- Aggiungere un campo al movimento (es. una tassa, un rateo) significa **quasi sempre toccare anche `HoldingPositionRecalculator`**: è lì che si decide se entra nel costo, nel realizzato o in nessuno dei due.
- La serie storica ripercorre il registro con un cursore per holding che avanza insieme ai mesi: costo O(mesi + movimenti), non il prodotto. Chi la modifica non reintroduca un ciclo annidato sui movimenti.
- `CurrencyConverter` ha la cache **per-istanza**: va risolto dal container una volta per richiesta, mai istanziato dentro un ciclo.
