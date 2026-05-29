# Analisi — Dedup import via external_id

> Scope: evitare transazioni duplicate quando si reimporta lo stesso estratto (OFX/QIF con FITID,
> o CSV che mappa una colonna su `external_id`). Le righe il cui `external_id` esiste già per
> l'utente — o è ripetuto nello stesso file — vengono saltate e contate a parte (`duplicates`).
> Nessuna migration: `transactions.external_id` è già `nullable` + indicizzato.

## 1. Flusso attuale

- [TransactionImportService::import()](../../backend/app/Services/TransactionImportService.php) cicla le righe, crea una `Transaction` per ognuna e popola `external_id` quando il mapping lo fornisce (sempre per OFX via `FITID`).
- Ritorno: `{imported, skipped, auto_categorized, errors}`. `skipped` conta **solo** le righe finite in errore (data/importo non validi).
- Reimportando lo stesso file si creano **duplicati**: nessun controllo su `external_id`.
- Righe senza `external_id` (CSV senza mappatura, QIF) non hanno identificatore stabile.

## 2. Modifiche da apportare

1. In `import()`: precaricare gli `external_id` già presenti per l'utente; tenere un set degli `external_id` visti nel batch corrente.
2. Se una riga ha `external_id` non vuoto già noto (DB o batch) → **skip come duplicato**, incrementa `duplicates`, non crea la transazione.
3. Righe senza `external_id` → comportamento invariato (nessun dedup possibile).
4. Aggiungere `duplicates` al valore di ritorno.
5. Frontend: mostrare il conteggio `duplicates` nel riepilogo import.
6. Test + `AGENTS.md`.

## 3. Dettaglio dei fix

### 3.1 `TransactionImportService::import()`
- Prima del loop:
  ```php
  $existingExternalIds = Transaction::query()
      ->whereNotNull('external_id')
      ->pluck('external_id')
      ->flip(); // map per lookup O(1) (isset)
  $seenInBatch = [];
  $duplicates = 0;
  ```
  (il global scope `BelongsToUser` filtra già per utente corrente.)
- Dentro il loop, dopo aver risolto `$externalId` e prima del `Transaction::create`:
  ```php
  if ($externalId !== null) {
      if (isset($existingExternalIds[$externalId]) || isset($seenInBatch[$externalId])) {
          $duplicates++;
          continue; // non incrementa imported né skipped
      }
      $seenInBatch[$externalId] = true;
  }
  ```
- Spostare la risoluzione di `$externalId` **prima** del `create` (già lì) e prima del matching categoria non importa.
- Ritorno: aggiungere `'duplicates' => $duplicates`.

Nota: `pluck('external_id')->flip()` su storici molto grandi carica molti valori; accettabile per uso single-tenant. In alternativa si potrebbe fare una query `whereIn` sugli external_id del file — ma il file può non avere external_id e il set è comunque limitato. Manteniamo il preload, semplice.

### 3.2 Endpoint
Nessuna modifica di firma: il controller [TransactionImportExportController::importCommit](../../backend/app/Http/Controllers/TransactionImportExportController.php) ritorna direttamente il risultato del service (ora con `duplicates`).

### 3.3 Frontend
- [ImportExportView.vue](../../frontend/src/views/ImportExportView.vue): `ImportResult` aggiunge `duplicates: number`; riepilogo mostra "N duplicate ignorate".

### 3.4 Test (`tests/Feature/Api/TransactionImportDedupTest.php`)
- Reimport dello stesso OFX → 2ª volta `duplicates = N`, `imported = 0`, nessuna nuova riga in DB.
- Duplicato intra-file (due `STMTTRN` con stesso `FITID`) → 1 importata, 1 `duplicates`.
- CSV senza colonna mappata su `external_id` → due righe identiche entrambe importate (`duplicates = 0`).
- Dedup scoping: un altro utente con lo stesso `external_id` non blocca l'import (global scope).

## 4. Impatti e possibili regressioni

> Branch di riferimento: **`master`**.

- Comportamento CSV esistente invariato finché non si mappa `external_id` (i test attuali non lo mappano). Verificare `TransactionImportExportTest`/`...AutoCategorizationTest` ancora verdi.
- `skipped` mantiene il significato originale (solo errori); i duplicati sono un contatore separato → nessuna ambiguità.
- Possibile regressione semantica: se l'utente vuole *davvero* reimportare (es. dopo cancellazione), il dedup si basa sul DB corrente — coerente, perché se le righe sono state cancellate l'external_id non è più presente.
- Cross-account: `FITID` è unico per istituzione/conto; il dedup per-utente è un superset sicuro (non crea falsi duplicati legittimi nella pratica). Annotato come scelta.
- Performance: un `pluck` aggiuntivo per import. Trascurabile per volumi personali.
