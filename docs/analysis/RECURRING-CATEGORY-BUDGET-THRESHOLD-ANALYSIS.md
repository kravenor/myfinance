# Analisi — Categoria nelle ricorrenti, soglia budget unica, coerenza voci scenario

> Scope: tre punti aperti emersi in [DATA-CONSISTENCY-ANALYSIS](DATA-CONSISTENCY-ANALYSIS.md) §4 "Fuori perimetro".
> Branch `feat/recurring-category-budget-threshold`. Nessuna migration.

## 1. Flusso attuale

### 1.1 Categoria delle ricorrenti
- `recurring_transactions.category_id` esiste, l'API lo accetta e lo valida ([Store](../../backend/app/Http/Requests/RecurringTransaction/StoreRecurringTransactionRequest.php)/[UpdateRecurringTransactionRequest](../../backend/app/Http/Requests/RecurringTransaction/UpdateRecurringTransactionRequest.php) con `CategoryTypeCheck`), [RecurringTransactionResource](../../backend/app/Http/Resources/RecurringTransactionResource.php) lo restituisce e [RecurringTransactionRunner](../../backend/app/Services/RecurringTransactionRunner.php) lo copia sulla transazione generata.
- [RecurringView](../../frontend/src/views/RecurringView.vue) però non ha il campo e non invia `category_id`: ogni ricorrente creata da interfaccia è senza categoria.
- Conseguenze: le transazioni generate ogni mese nascono senza categoria (vanno categorizzate a mano o con le regole), e la baseline di [Previsioni](../../frontend/src/views/ForecastView.vue) ignora le uscite ricorrenti, perché [ExpenseForecastService](../../backend/app/Services/ExpenseForecastService.php) usa solo quelle con categoria.

### 1.2 Soglia di allerta dei budget
- La soglia è una preferenza utente (`notification_preferences.budget_threshold`, default 80, modificabile in Impostazioni).
- La usano `GET /budgets/alerts` (banner/card in Dashboard) e [NotificationScanner](../../backend/app/Services/NotificationScanner.php).
- [BudgetsView](../../frontend/src/views/BudgetsView.vue) invece ha `80` fisso in `status()` e nell'hint del campo Importo: con soglia 90 la Dashboard non segnala un budget al 85% mentre la pagina Budget lo colora di ambra.

### 1.3 Voci degli scenari
- [Store](../../backend/app/Http/Requests/Scenario/StoreScenarioItemRequest.php)/[UpdateScenarioItemRequest](../../backend/app/Http/Requests/Scenario/UpdateScenarioItemRequest.php) accettano `type` (expense/income, default expense) e `category_id` senza controllarne la coerenza: una voce di entrata con una categoria di uscita passa e finisce nel dettaglio per categoria della previsione come spesa.
- Il frontend azzera la categoria quando il tipo diventa entrata, ma l'API non lo garantisce.

## 2. Modifiche da apportare

1. `lib/categories.ts`: `categoryOptions(categories, type)` (albero padre → figli indentati), estratto da TransactionsView e riusato in RecurringView.
2. RecurringView: select Categoria (nascosta per i giroconti), azzerata se incompatibile al cambio di tipo, inviata come `category_id` (null per i giroconti); categoria mostrata nella lista (card mobile e tabella).
3. BudgetsView: soglia da `auth.user.notification_preferences.budget_threshold` (fallback 80) in `status()` e nell'hint.
4. SettingsView: hint della soglia aggiornato ("vale per notifiche, Dashboard e pagina Budget").
5. Store/UpdateScenarioItemRequest: `CategoryTypeCheck` nel `withValidator`, come per transazioni e ricorrenti.
6. Test feature per ricorrente con categoria e voce scenario incoerente; `AGENTS.md` aggiornato.

## 3. Dettaglio dei fix

### 3.1 Categoria nelle ricorrenti (fix 1-2)
- `categoryOptions` restituisce `{ id, label }[]` per il tipo dato (`[]` per `transfer`), con la stessa logica oggi inline in TransactionsView (radici ordinate per `sort_order`/nome, figli con prefisso `↳`).
- RecurringView carica le categorie insieme a conti e holding (una chiamata in più nel `Promise.all` di `onMounted`, `per_page: 200`).
- Form: `category_id: null as number | null`; `startEdit` lo legge da `r.category_id`; nel payload `category_id: form.type === 'transfer' ? null : form.category_id`.
- Stesso `watch` di TransactionsView: se le opzioni cambiano e la categoria scelta non c'è più, `category_id = null`.
- Hint: "Viene copiata su ogni transazione generata ed è quella che Previsioni usa per stimare le uscite."
- Lista: nella card mobile la categoria va nella riga meta; in tabella una colonna "Categoria".

### 3.2 Soglia unica (fix 3-4)
- `const threshold = computed(() => auth.user?.notification_preferences?.budget_threshold ?? 80)`; `status()` usa `p >= threshold.value`.
- Hint Importo: "dal {threshold}% (soglia delle Impostazioni) la barra diventa ambra, dal 100% rossa."
- Nessun cambio backend: la preferenza è già nel payload di `/auth/me`.

### 3.3 Voci scenario coerenti (fix 5)
- Store: `CategoryTypeCheck::error($this->input('category_id'), $this->input('type', 'expense'))` (il default del modello è `expense`).
- Update: tipo e categoria dal payload o dal record (`$this->route('item')`), come per transazioni e ricorrenti.
- Errore su `category_id`.

## 4. Impatti e possibili regressioni

Branch di riferimento: `master`.

- **Ricorrenti esistenti**: restano senza categoria finché non vengono modificate; nessuna migrazione dei dati. Assegnare la categoria cambia subito la baseline di Previsioni (è l'effetto voluto) e le transazioni generate dalla scadenza successiva.
- **Runner**: invariato; copia già `category_id`.
- **Soglia**: utenti con soglia ≠ 80 vedono cambiare i colori in Budget (allineati a Dashboard e notifiche). Senza `notification_preferences` nel payload si usa 80 come prima.
- **Voci scenario incoerenti già salvate**: non bloccano la lettura; diventano non salvabili finché non si corregge la categoria (stesso comportamento delle transazioni).
- **Refactor di `categoryOptions`**: TransactionsView deve produrre le stesse opzioni di prima (verifica manuale del select e del filtro per tipo).
