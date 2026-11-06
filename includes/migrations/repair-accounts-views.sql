-- Rebuilds the two views that the Accounts section reads. Safe to run more than once:
-- they hold no data of their own, because every figure comes from sales, stock and
-- journal entries. Select the database in phpMyAdmin, then import this file.
SET @drop = COALESCE((SELECT CONCAT(IF(TABLE_TYPE = 'VIEW', 'DROP VIEW ', 'DROP TABLE '), TABLE_NAME)
                     FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger'), 'DO 0');
PREPARE statement FROM @drop;
EXECUTE statement;
DEALLOCATE PREPARE statement;
SET @drop = COALESCE((SELECT CONCAT(IF(TABLE_TYPE = 'VIEW', 'DROP VIEW ', 'DROP TABLE '), TABLE_NAME)
                     FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_amounts'), 'DO 0');
PREPARE statement FROM @drop;
EXECUTE statement;
DEALLOCATE PREPARE statement;

CREATE OR REPLACE VIEW sale_amounts AS
SELECT s.id, s.date, s.cash_status,
       s.status NOT IN ('Cancelled', 'Returned') AS sold,
       COALESCE(NULLIF(s.invoice_no, ''), NULLIF(s.order_no, ''), NULLIF(s.waybill_no, ''), CONCAT('Sale ', s.id)) AS reference,
       CONCAT('Sale to ', s.customer_name) AS memo,
       t.subtotal, s.delivery_charge, t.subtotal + s.delivery_charge AS total, t.cost
FROM sales s
JOIN (SELECT si.sale_id, SUM(ROUND(si.qty * si.unit_price, 2)) AS subtotal,
             SUM(ROUND(si.qty * COALESCE(si.unit_cost, i.cost_price, 0), 2)) AS cost
      FROM sale_items si JOIN items i ON i.id = si.item_id
      GROUP BY si.sale_id) t ON t.sale_id = s.id;

CREATE OR REPLACE VIEW ledger AS
SELECT 'journal' AS source, e.id AS source_id, e.date, e.reference, e.memo, l.account_id, l.debit, l.credit
FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
UNION ALL
SELECT 'sale', id, date, reference, memo, (SELECT id FROM accounts WHERE code = '4000'), 0, subtotal
FROM sale_amounts WHERE sold AND subtotal <> 0
UNION ALL
SELECT 'sale', id, date, reference, memo, (SELECT id FROM accounts WHERE code = '4100'), 0, delivery_charge
FROM sale_amounts WHERE sold AND delivery_charge <> 0
UNION ALL
SELECT 'sale', id, date, reference, memo,
       (SELECT id FROM accounts WHERE code = CASE cash_status WHEN 'Received' THEN '1000' WHEN 'Refund' THEN '4200' ELSE '1100' END),
       total, 0
FROM sale_amounts WHERE sold AND total <> 0
UNION ALL
SELECT 'sale', id, date, reference, memo, (SELECT id FROM accounts WHERE code = '5000'), cost, 0
FROM sale_amounts WHERE sold AND cost <> 0
UNION ALL
SELECT 'sale', id, date, reference, memo, (SELECT id FROM accounts WHERE code = '1200'), 0, cost
FROM sale_amounts WHERE sold AND cost <> 0
UNION ALL
SELECT 'sale', id, date, reference, memo, (SELECT id FROM accounts WHERE code = '1000'), total, 0
FROM sale_amounts WHERE NOT sold AND cash_status = 'Received' AND total <> 0
UNION ALL
SELECT 'sale', id, date, reference, memo, (SELECT id FROM accounts WHERE code = '2100'), 0, total
FROM sale_amounts WHERE NOT sold AND cash_status = 'Received' AND total <> 0
UNION ALL
SELECT 'stock', st.id, st.date, i.code, COALESCE(NULLIF(st.note, ''), 'Stock purchase'),
       (SELECT id FROM accounts WHERE code = '1200'), st.total_price, 0
FROM stock st JOIN items i ON i.id = st.item_id WHERE st.unit_price IS NOT NULL AND st.total_price <> 0
UNION ALL
SELECT 'stock', st.id, st.date, i.code, COALESCE(NULLIF(st.note, ''), 'Stock purchase'),
       (SELECT id FROM accounts WHERE code = '1000'), 0, st.total_price
FROM stock st JOIN items i ON i.id = st.item_id WHERE st.unit_price IS NOT NULL AND st.total_price <> 0
UNION ALL
SELECT 'stock', st.id, st.date, i.code, st.note,
       (SELECT id FROM accounts WHERE code = IF(st.qty < 0, '5100', '1200')),
       ROUND(ABS(st.qty) * COALESCE(i.cost_price, 0), 2), 0
FROM stock st JOIN items i ON i.id = st.item_id WHERE st.unit_price IS NULL AND COALESCE(i.cost_price, 0) <> 0
UNION ALL
SELECT 'stock', st.id, st.date, i.code, st.note,
       (SELECT id FROM accounts WHERE code = IF(st.qty < 0, '1200', '5100')),
       0, ROUND(ABS(st.qty) * COALESCE(i.cost_price, 0), 2)
FROM stock st JOIN items i ON i.id = st.item_id WHERE st.unit_price IS NULL AND COALESCE(i.cost_price, 0) <> 0;

CREATE TABLE IF NOT EXISTS settings (
    name  VARCHAR(50)  NOT NULL PRIMARY KEY,
    value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (name, value) VALUES ('financial_year_start_month', '4');
