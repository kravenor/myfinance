-- Diagnosi coerenza dati prima del rilascio di fix/data-consistency. Solo lettura (SELECT).
-- Dalla cartella del progetto sul VPS:
--   docker compose -f docker-compose.vps.yml exec -T mysql sh -c \
--     'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --table "$MYSQL_DATABASE"' < scripts/diagnose-data-consistency.sql
-- Riepilogo tutto a 0 = niente da correggere. Dettagli in docs/analysis/DATA-CONSISTENCY-ANALYSIS.md §4.

-- 1) Riepilogo
SELECT 'transazioni con categoria del tipo sbagliato' AS controllo, COUNT(*) AS righe
  FROM transactions t JOIN categories c ON c.id = t.category_id
 WHERE t.type IN ('income', 'expense') AND c.type <> t.type
UNION ALL
SELECT 'giroconti con categoria', COUNT(*)
  FROM transactions WHERE type = 'transfer' AND category_id IS NOT NULL
UNION ALL
SELECT 'ricorrenti con categoria del tipo sbagliato', COUNT(*)
  FROM recurring_transactions r JOIN categories c ON c.id = r.category_id
 WHERE r.type IN ('income', 'expense') AND c.type <> r.type
UNION ALL
SELECT 'ricorrenti giroconto con categoria', COUNT(*)
  FROM recurring_transactions WHERE type = 'transfer' AND category_id IS NOT NULL
UNION ALL
SELECT 'obiettivi con valuta diversa dal conto', COUNT(*)
  FROM savings_goals g JOIN accounts a ON a.id = g.account_id
 WHERE g.currency <> a.currency;

-- 2) Dettaglio transazioni da correggere (vuoto = niente da fare)
SELECT t.user_id, t.id, t.occurred_at, t.type, t.amount, t.description,
       c.name AS categoria, c.type AS tipo_categoria
  FROM transactions t JOIN categories c ON c.id = t.category_id
 WHERE (t.type IN ('income', 'expense') AND c.type <> t.type) OR t.type = 'transfer'
 ORDER BY t.user_id, t.occurred_at;

-- 3) Dettaglio ricorrenti da correggere
SELECT r.user_id, r.id, r.description, r.type, c.name AS categoria, c.type AS tipo_categoria
  FROM recurring_transactions r JOIN categories c ON c.id = r.category_id
 WHERE (r.type IN ('income', 'expense') AND c.type <> r.type) OR r.type = 'transfer'
 ORDER BY r.user_id, r.id;

-- 4) Obiettivi il cui risparmiato cambierà (ora convertito nella valuta dell'obiettivo)
SELECT g.user_id, g.id, g.name, g.currency AS valuta_obiettivo, a.name AS conto, a.currency AS valuta_conto
  FROM savings_goals g JOIN accounts a ON a.id = g.account_id
 WHERE g.currency <> a.currency;
