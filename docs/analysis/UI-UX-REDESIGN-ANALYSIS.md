# Analisi — Restyling grafico e usabilità del frontend

> Scope: rendere l'interfaccia coerente, leggibile e più rapida da usare tutti i giorni, senza
> cambiare backend né API. Nessuna migration. Riferimento regole: ADV-TEC-UXI-007 (UX/UI enterprise).
> Vincolo di progetto: niente librerie fuori dallo stack dichiarato (Vue 3, Tailwind 3, Chart.js)
> senza conferma — le proposte sotto restano dentro lo stack salvo dove indicato come **opzionale**.

## 1. Flusso attuale

### Fondamenta grafiche
- [tailwind.config.js](../../frontend/tailwind.config.js) ha `theme.extend` vuoto: nessun token (colori semantici, font, raggi, ombre). Tutto è `slate` + `indigo` di default.
- [style.css](../../frontend/src/style.css) definisce poche classi componente (`btn-*`, `input`, `label`, `card`, `table`, `icon-btn`) più le utility responsive `filter-panel` e `table-responsive`.
- Nessun font dichiarato ([index.html](../../frontend/index.html) usa il font di sistema di Tailwind), nessun `tabular-nums` sugli importi (0 occorrenze), nessuna dark mode (0 classi `dark:`).
- 70 colori esadecimali scritti a mano nelle view; la palette dei grafici è duplicata in [DashboardView](../../frontend/src/views/DashboardView.vue), [ReportsView](../../frontend/src/views/ReportsView.vue) e [StatsView](../../frontend/src/views/StatsView.vue).
- Testo a 10px (`text-[10px]`) per i badge tag e le label delle card mobile: sotto la soglia di 12px.

### Navigazione
- [AppLayout.vue](../../frontend/src/components/AppLayout.vue): sidebar scura con **16 voci piatte** ([stores/menu.ts](../../frontend/src/stores/menu.ts) `NAV_ITEMS`), senza icone né raggruppamenti. Voci di configurazione usate di rado (Categorie, Tag, Regole categoria) stanno allo stesso livello di Transazioni.
- Da desktop non c'è topbar: nessun titolo di contesto, nessun accesso rapido (nuova transazione, notifiche, utente).
- Il pulsante "Esci" è un `btn-secondary` bianco in fondo alla sidebar scura: è l'elemento più vistoso del menu.

### Dashboard
- [DashboardView.vue](../../frontend/src/views/DashboardView.vue): 4 KPI con etichette in inglese ("Income mese", "Expense mese", "Net mese") e grafico con dataset "Income"/"Expense".
- I KPI non hanno confronto col mese precedente; non ci sono le ultime transazioni (AGENTS §9 le cita, il codice no); nessuna azione rapida.
- Gerarchia piatta: alert budget, KPI, saldi conti e grafici hanno tutti lo stesso peso visivo.

### View CRUD (esempio [TransactionsView.vue](../../frontend/src/views/TransactionsView.vue), stesso schema in altre 8 view con `showForm`)
- Il form di creazione/modifica è **inline**, inserito tra i filtri e la lista. `startEdit` (riga 132) apre il form in cima alla pagina senza scroll: modificando una riga in fondo alla lista, il form compare fuori dallo schermo.
- `onSubmit` (riga 148) non ha `try/catch`: un 422 di validazione viene perso in silenzio, nessun messaggio, nessun errore per campo. Nessuno stato "in corso" sul pulsante → possibile doppio invio. Solo 7 view su 20 gestiscono un errore, e lo mostrano come testo generico.
- Le `<option>` del tipo mostrano i valori grezzi `income` / `expense` / `transfer`; in tabella desktop il tipo è il valore inglese con `capitalize`.
- Incoerenza mobile/desktop: su mobile l'importo è colorato e con segno `+`/`−`, su desktop è nero e senza segno.
- Eliminazione con `confirm()` nativo (13 occorrenze nel progetto), nessun annullamento.
- Filtri applicati solo con il pulsante "Filtra" e non salvati nell'URL: ricaricando o tornando indietro si perdono.
- Paginazione solo precedente/successiva.
- Caricamento come testo "Caricamento…" (15 view), stati vuoti generici ("Nessuna transazione.") senza distinguere "non c'è ancora nulla" da "nessun risultato per questi filtri".
- Nessun feedback dopo il salvataggio (nessun toast), nessuna scorciatoia da tastiera, nessun avviso di modifiche non salvate.

### Cosa funziona già e va conservato
- Convenzioni mobile di AGENTS §6: drawer < `lg`, card stack (`table-responsive` o doppio markup), `filter-panel` collassabile, FAB, touch target 44px, input a 16px.
- Formattazione centralizzata in [lib/money.ts](../../frontend/src/lib/money.ts) e [lib/date.ts](../../frontend/src/lib/date.ts).
- [RowActions.vue](../../frontend/src/components/ui/RowActions.vue) come componente unico per le azioni di riga.

## 2. Modifiche da apportare

1. **Design token** in `tailwind.config.js`: colori semantici (`primary`, `income`, `expense`, `transfer`, `warning`, `danger`, `surface`, `muted`), font, `tabular-nums` sugli importi.
2. **Palette grafici unica** in `src/lib/chartTheme.ts` (colori + opzioni Chart.js condivise), rimossa dalle 3 view.
3. **Componenti `ui/`** minimi: `AppModal` (su `<dialog>` nativo), `ConfirmDialog`, `ToastHost` + store `toast`, `EmptyState`, `Skeleton`, `PageHeader`, `Amount` (segno + colore + tabular), `TypeBadge`.
4. **Etichette italiane** per i tipi transazione da una mappa unica (`lib/labels.ts`), usata in select, tabelle, grafici, KPI.
5. **Navigazione raggruppata** con icone inline SVG: *Panoramica · Movimenti · Pianificazione · Patrimonio · Analisi · Configurazione*; topbar desktop con titolo pagina, "+ Transazione", campanella notifiche, menu utente (Esci dentro).
6. **Form in modale/drawer** al posto del form inline (risolve il "form fuori schermo" in modifica), con stato `submitting`, errori 422 per campo e toast di conferma.
7. **Gestione errori centralizzata**: interceptor axios per 401/419/5xx + helper `fieldErrors` in `useCrud`.
8. **Eliminazione** con `ConfirmDialog` (testo che dice cosa si elimina) e, dove possibile, toast con "Annulla".
9. **Filtri reattivi e nell'URL** (`route.query`), debounce sulla ricerca, niente pulsante "Filtra".
10. **Dashboard ridisegnata**: patrimonio netto in evidenza, entrate/uscite/risparmio del mese con delta vs mese precedente, budget del mese, ultime transazioni, azione rapida.
11. **Coerenza importi**: `Amount` ovunque (desktop = mobile), cifre tabulari, allineamento a destra.
12. **Stati**: skeleton al caricamento, empty state con CTA, distinzione vuoto/nessun risultato.
13. **Accessibilità**: focus visibile, testo ≥ 12px, contrasto AA, colore mai unico veicolo (segno e icona accanto al verde/rosso), toast in `aria-live`.
14. **Opzionale**: dark mode (`darkMode: 'class'` + token) e scorciatoie (`n` nuova transazione, `/` ricerca, `Esc` chiude modale).
15. Aggiornamento `AGENTS.md` §3 (nuovi file `ui/` e `lib/`), §6 (convenzioni: token, modale per i form, `Amount`, toast, filtri in URL).

## 3. Dettaglio dei fix

### 3.1 Design token
In `tailwind.config.js`, `theme.extend.colors` con alias semantici sopra la palette Tailwind (nessuna nuova dipendenza):

```js
colors: {
  primary: colors.indigo,
  income: colors.emerald,
  expense: colors.rose,
  transfer: colors.sky,
  warning: colors.amber,
  danger: colors.red,
}
```

- Le view usano `text-income-600`, `bg-expense-50`, ecc. invece di `green-600`/`red-600` sparsi: cambiare tono in futuro è una riga.
- Font: lo stack di sistema resta il default più leggero (nessun download). **Opzionale** `Inter` via `@fontsource/inter` (nuova dipendenza → da confermare); in alternativa `<link>` Google Fonts in `index.html`.
- In `style.css` una classe `.num { @apply tabular-nums text-right; }` per importi e quantità.
- Raggi e ombre uniformati (`rounded-lg`, una sola ombra per le card) dentro `.card`, `.btn`, `.input`.
- `.label` resta sopra il campo; aggiungere `.field-error` (testo rosso 12px sotto il campo).

### 3.2 Palette grafici unica
`src/lib/chartTheme.ts` esporta `CATEGORY_PALETTE`, `INCOME_COLOR`, `EXPENSE_COLOR` (gli stessi esadecimali dei token) e `baseOptions` (legenda in basso, tooltip con `formatCurrency`, griglia leggera). Dashboard, Report e Statistiche importano da lì: elimina tre copie della palette e rende i grafici visivamente coerenti tra loro.

### 3.3 Componenti `src/components/ui/`
Solo quelli usati in più view; niente libreria esterna.

| Componente | Base | Sostituisce |
|---|---|---|
| `AppModal.vue` | `<dialog>` nativo (`showModal()`: focus trap ed `Esc` gratis), drawer full-screen sotto `sm` | form inline con `showForm` |
| `ConfirmDialog.vue` + `useConfirm()` | `AppModal` | 13 `confirm()` nativi |
| `ToastHost.vue` + `stores/toast.ts` | `aria-live="polite"`, auto-dismiss, azione opzionale "Annulla" | nessun feedback |
| `EmptyState.vue` | icona + testo + CTA, prop `filtered` | "Nessun …" generici |
| `Skeleton.vue` | righe grigie `animate-pulse` | "Caricamento…" |
| `PageHeader.vue` | titolo + sottotitolo + slot azioni | `<h1>` + bottone ripetuti in 20 view |
| `Amount.vue` | `formatCurrency` + segno + colore per tipo + `tabular-nums` | logica `txAmountClass`/`txAmountSign` sparsa |
| `TypeBadge.vue` | etichetta italiana + icona per tipo | `{{ tx.type }}` grezzo |

### 3.4 Etichette italiane
`src/lib/labels.ts` con `TX_TYPE_LABEL = { income: 'Entrata', expense: 'Uscita', transfer: 'Giroconto' }` (valori API invariati). Da usare in: select filtro/form di [TransactionsView](../../frontend/src/views/TransactionsView.vue), [RecurringView](../../frontend/src/views/RecurringView.vue), [CategoriesView](../../frontend/src/views/CategoriesView.vue), KPI e dataset dei grafici di [DashboardView](../../frontend/src/views/DashboardView.vue). Dashboard: "Entrate del mese", "Uscite del mese", "Risparmio del mese".

### 3.5 Navigazione
- `NAV_ITEMS` in [stores/menu.ts](../../frontend/src/stores/menu.ts) ottiene `group` e `icon` (nome di un SVG inline in una mappa `ui/icons.ts`, ~16 path). **I `name` restano invariati**: `menu.hidden` in `localStorage` continua a funzionare.
- Gruppi proposti:
  - **Panoramica**: Dashboard, Notifiche
  - **Movimenti**: Transazioni, Ricorrenti, Import / Export
  - **Pianificazione**: Budget, Obiettivi, Previsioni
  - **Patrimonio**: Conti, Investimenti
  - **Analisi**: Report, Statistiche
  - **Configurazione**: Categorie, Tag, Regole categoria, Impostazioni
- [AppLayout.vue](../../frontend/src/components/AppLayout.vue): sidebar chiara (`bg-white` + bordo) o scura più sobria, titoli di gruppo in `text-xs muted`, voce attiva con barra laterale `primary`. Topbar sticky anche su desktop: titolo pagina, "+ Transazione" (apre la modale globale), campanella con badge, menu utente con email ed "Esci".
- **Opzionale** mobile: bottom bar con 4 voci frequenti (Dashboard, Transazioni, +, Budget) e "Altro" che apre il drawer. Va valutato insieme al FAB attuale per non duplicare il "+".

### 3.6 Form in modale e gestione errori
- Le 9 view con `showForm` spostano il form dentro `<AppModal>`: in modifica la modale si apre sopra la riga cliccata, il problema dello scroll sparisce, la lista resta visibile sotto. Il FAB mobile e il pulsante in `PageHeader` aprono la stessa modale; la regola `pb-20 lg:pb-0` resta per il FAB.
- [useCrud.ts](../../frontend/src/composables/useCrud.ts): aggiunge `submitting` e `fieldErrors` (da `response.data.errors` sul 422); `create`/`update` li valorizzano e rilanciano. La view mostra `fieldErrors.amount?.[0]` sotto il campo e lascia la modale aperta coi dati inseriti (ADV-TEC-UXI-007 §4: mai perdere l'input).
- Il pulsante submit: `:disabled="submitting"` + testo "Salvataggio…" (§6, doppio invio).
- Dopo il successo: chiusura modale + toast "Transazione salvata".
- [lib/api.ts](../../frontend/src/lib/api.ts): interceptor di risposta per `401` (redirect a login con `redirect`), `419` (reset `csrfReady` e un retry), `5xx`/rete (toast "Il server non risponde, riprova tra poco. Non dipende da te."). Nessun dettaglio tecnico mostrato.
- Modifiche non salvate: `onBeforeRouteLeave` + chiusura modale con conferma solo se il form è "dirty".

### 3.7 Eliminazione
- `const confirm = useConfirm()` → `await confirm({ title: 'Eliminare la transazione?', message: '«Spesa Esselunga» del 12/09, −45,20 €. L\'operazione non si può annullare.', danger: true })`. Pulsante distruttivo a destra, non preselezionato (§6).
- Annullamento vero ("Annulla" nel toast) richiede soft-delete lato backend: **fuori scope**, da valutare in seguito.

### 3.8 Filtri e liste
- Filtri letti da e scritti su `route.query` con `router.replace` (§5: filtri conservati nell'indirizzo); `watch` sui filtri con debounce 300ms sulla ricerca → niente pulsante "Filtra".
- Riga "1–25 di 1.340" già presente, resta; paginazione con numeri di pagina (prima, …, corrente ±1, …, ultima).
- Su desktop la colonna Importo usa `Amount` (colore + segno), la colonna Tipo usa `TypeBadge`.
- `EmptyState` con `filtered = filtri attivi`: "Nessuna transazione con questi filtri · Azzera filtri" vs "Non hai ancora registrato transazioni · Aggiungi la prima".

### 3.9 Dashboard
Nuovo ordine per peso visivo (endpoint esistenti, una sola chiamata in più a `/transactions?per_page=5`, già in `Promise.all`):
1. **Hero**: patrimonio netto grande + variazione vs mese precedente (dalla serie `/reports/net-worth`, già disponibile in Report).
2. **Mese corrente**: Entrate / Uscite / Risparmio con delta % vs mese precedente (ultimi due punti di `/reports/timeline`, già caricata: nessuna query extra).
3. **Budget del mese**: barre compatte delle categorie con alert al posto dell'attuale banner, link a Budget.
4. **Ultime transazioni** (5) con `Amount`, link a Transazioni.
5. Saldi conti come lista compatta (non card grandi), grafici sotto.

### 3.10 Accessibilità e dettagli
- `text-[10px]` → `text-xs` (12px) per badge tag e label card mobile.
- Focus: `focus-visible:ring-2 ring-primary-500` già nei `btn`, da estendere alle voci di menu e ai chip tag.
- Verde/rosso sempre accompagnati da segno `+`/`−` o icona (`Amount`, `TypeBadge`).
- Contrasto: `text-slate-400` su bianco (≈2,6:1) usato per testi informativi → `text-slate-500` (≈4,8:1).
- `manifest.webmanifest` `theme_color` allineato a `primary-600` se il primario cambia.

### 3.11 Opzionali
- **Dark mode**: `darkMode: 'class'`, toggle in Impostazioni (localStorage, come `menu.hidden`), varianti `dark:` concentrate nelle classi componente di `style.css` per non toccare ogni view; i grafici leggono i colori da `chartTheme.ts`.
- **Scorciatoie**: un listener `keydown` in `AppLayout` (`n` nuova transazione, `/` focus ricerca), dichiarate nel `title` dei pulsanti.
- **Libreria componenti** (PrimeVue, Headless UI, shadcn-vue): ridurrebbe il codice di modale/toast/select ma è fuori stack e pesa sul bundle; con `<dialog>` nativo e due componenti fatti in casa non serve. Da riconsiderare solo se servono combobox/datepicker complessi.

### Piano di rilascio consigliato (PR piccole, ognuna utilizzabile da sola)
1. Token + `chartTheme` + `labels` + `Amount`/`TypeBadge` (impatto visivo immediato, rischio basso).
2. Toast + interceptor + `fieldErrors`/`submitting` in `useCrud` (correttezza: oggi gli errori si perdono).
3. `AppModal` + `ConfirmDialog`, migrazione delle 9 view CRUD una alla volta, partendo da Transazioni.
4. Navigazione raggruppata + topbar.
5. Dashboard.
6. Filtri in URL, empty state, skeleton, accessibilità.
7. Opzionali.

## 4. Impatti e possibili regressioni

Branch di riferimento: `master` (il progetto non è nella tabella dei progetti metrics; il branch base del repository è `master`).

- **Convenzioni mobile (AGENTS §6)**: `table-responsive`, doppio markup card/tabella, `filter-panel`, FAB e touch target 44px vanno preservati. Verificare su 360px ogni view migrata alla modale (drawer full-screen, tastiera virtuale che non copre il submit).
- **`menu.hidden` / `reports.visible`**: i `name` delle rotte e le chiavi `localStorage` non cambiano; verificare che le voci nascoste restino nascoste anche dentro i gruppi e che un gruppo con tutte le voci nascoste sparisca.
- **`<dialog>` nativo**: supportato da Safari ≥ 15.4; verificare la PWA su iOS (standalone) per scroll del body bloccato e `Esc`/swipe.
- **Interceptor 401/419**: rischio di loop di redirect su `/auth/me` all'avvio (`App.vue` `fetchMe`) e sulle viste guest (login, reset password). Escludere le rotte auth dall'interceptor; verificare "Ricordami".
- **`useCrud` rilancia gli errori**: le view che oggi chiamano `create`/`update` senza `try` continuano a lanciare come prima, ma ora l'errore va gestito; controllare le 18 view che usano `useCrud`.
- **Filtri in URL**: la paginazione e `applyFilters(resetPage)` di TransactionsView cambiano trigger; verificare che il cambio filtro torni a pagina 1 e che il back del browser ripristini i filtri senza doppie richieste.
- **Dashboard**: la chiamata in più a `/transactions` va in `Promise.all` (nessun waterfall); controllare che i delta gestiscano mese precedente senza dati e `month_start_day ≠ 1` (`financialMonthRange`, AGENTS §6).
- **Colori grafici**: categorie/tag con `color` proprio continuano a usarlo; la palette condivisa vale solo come fallback.
- **Etichette**: solo display; i valori inviati all'API (`income`/`expense`/`transfer`) restano invariati. Verificare export CSV e import (mapping colonne) che non devono usare le etichette tradotte.
- **Contrasto**: passare da `slate-400` a `slate-500` cambia l'aspetto di molti testi secondari; controllo visivo su tutte le view.
- **Lint/type-check**: `npm run lint` con `--max-warnings 0` e `vue-tsc` in CI devono passare a ogni PR.
- **Documentazione**: aggiornare AGENTS §3 e §6 a ogni PR che introduce un componente o una convenzione.
