# CLAUDE.md

Istruzioni specifiche per Claude Code che lavora su questo repository.

## Lettura obbligatoria

**Prima di qualsiasi azione**, leggi sempre `AGENTS.md`: contiene la mappa aggiornata del progetto, lo stack, lo stato delle fasi e le convenzioni.

## Lingua

Comunica con l'utente in **italiano**, mantenendo termini tecnici in inglese (commit, pull request, migration, store, ecc.).

## Manutenzione della mappa

Dopo ogni modifica significativa (nuova fase, nuova dipendenza, nuovo servizio Docker, cambio di convenzione) **aggiorna `AGENTS.md`**:
- Sezione 3 se cambia la struttura di cartelle
- Sezione 4 se cambia un servizio Docker
- Sezione 6 se introduci una convenzione
- Sezione 7 spuntando la fase completata e aggiornando la data in cima

## Workflow consigliato

1. Crea/aggiorna task con `TaskCreate`/`TaskUpdate` per lavori multi-step.
2. Esegui i comandi tramite `Makefile` quando esiste un target adatto.
3. Per modifiche al DB: sempre via migration Laravel, mai SQL diretto.
4. Per nuove dipendenze: documenta in `AGENTS.md` sezione 2 o 6.

## Cosa NON fare

- Non creare file di documentazione/markdown aggiuntivi se non esplicitamente richiesto.
- Non introdurre librerie fuori dallo stack dichiarato senza chiedere conferma.
- Non eseguire `composer create-project` o `npm create vite` direttamente sull'host: passa sempre dai container (`make laravel-new`, `make vue-new`).
- Non committare il file `.env`.
