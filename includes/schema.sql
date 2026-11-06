-- Bloomstyle database. Import once, in phpMyAdmin or with the mysql client.
CREATE DATABASE IF NOT EXISTS bloomstyle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bloomstyle;

CREATE TABLE IF NOT EXISTS items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50)   NOT NULL UNIQUE,
    description VARCHAR(255)  NOT NULL,
    cost_price    DECIMAL(10,2) NULL,
    selling_price DECIMAL(10,2) NULL,
    buyer       VARCHAR(255)  NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date        DATE          NOT NULL,
    item_id     INT UNSIGNED  NOT NULL,
    qty         INT           NOT NULL,
    unit_price  DECIMAL(10,2) NULL,
    total_price DECIMAL(16,2) NOT NULL,
    note        VARCHAR(255)  NOT NULL DEFAULT '',
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date            DATE          NOT NULL,
    order_no        VARCHAR(50)   NULL UNIQUE,
    invoice_no      VARCHAR(50)   NULL UNIQUE,
    waybill_no      VARCHAR(50)   NOT NULL DEFAULT '',
    customer_name   VARCHAR(255)  NOT NULL,
    address         VARCHAR(255)  NOT NULL DEFAULT '',
    mobile          VARCHAR(50)   NOT NULL DEFAULT '',
    city            VARCHAR(100)  NOT NULL,
    district        VARCHAR(50)   NOT NULL,
    province        VARCHAR(50)   NOT NULL,
    delivery_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
    status          VARCHAR(30)   NOT NULL,
    referral_type   VARCHAR(30)   NOT NULL,
    cash_status     VARCHAR(30)   NOT NULL,
    INDEX idx_sales_date (date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sale_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id    INT UNSIGNED  NOT NULL,
    item_id    INT UNSIGNED  NOT NULL,
    qty        INT UNSIGNED  NOT NULL,
    unit_price DECIMAL(12,4) NOT NULL,
    unit_cost  DECIMAL(12,4) NULL,
    UNIQUE KEY uq_sale_item (sale_id, item_id),
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)  NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            VARCHAR(10)  NOT NULL DEFAULT 'Staff',
    session_version INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    ip           VARCHAR(45) NOT NULL,
    attempted_at DATETIME    NOT NULL,
    INDEX idx_attempts (ip, attempted_at)
) ENGINE=InnoDB;

-- Accounts: double-entry books. Manual entries live in journal_entries/journal_lines.
-- Sales and stock are not copied here: the ledger view derives their postings, so
-- the books always match the Sales and Stock pages.
CREATE TABLE IF NOT EXISTS accounts (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(10)  NOT NULL UNIQUE,
    name      VARCHAR(100) NOT NULL,
    type      VARCHAR(10)  NOT NULL,
    is_cash   TINYINT(1)   NOT NULL DEFAULT 0,
    is_system TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journal_entries (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    date       DATE         NOT NULL,
    kind       VARCHAR(10)  NOT NULL,
    reference  VARCHAR(50)  NOT NULL DEFAULT '',
    memo       VARCHAR(255) NOT NULL DEFAULT '',
    created_by VARCHAR(50)  NOT NULL DEFAULT '',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_journal_date (date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journal_lines (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_id   INT UNSIGNED  NOT NULL,
    account_id INT UNSIGNED  NOT NULL,
    debit      DECIMAL(14,2) NOT NULL DEFAULT 0,
    credit     DECIMAL(14,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT IGNORE INTO accounts (code, name, type, is_cash, is_system) VALUES
    ('1000', 'Cash and Bank', 'Asset', 1, 1),
    ('1010', 'Petty Cash', 'Asset', 1, 0),
    ('1100', 'Accounts Receivable', 'Asset', 0, 1),
    ('1200', 'Inventory', 'Asset', 0, 1),
    ('2000', 'Accounts Payable', 'Liability', 0, 0),
    ('2100', 'Customer Deposits', 'Liability', 0, 1),
    ('3000', 'Owner Capital', 'Equity', 0, 0),
    ('3100', 'Owner Drawings', 'Equity', 0, 0),
    ('3900', 'Opening Balance Equity', 'Equity', 0, 0),
    ('4000', 'Product Sales', 'Income', 0, 1),
    ('4100', 'Delivery Income', 'Income', 0, 1),
    ('4200', 'Sales Refunds', 'Income', 0, 1),
    ('4900', 'Other Income', 'Income', 0, 0),
    ('5000', 'Cost of Goods Sold', 'Expense', 0, 1),
    ('5100', 'Stock Losses and Damages', 'Expense', 0, 1),
    ('6000', 'Courier and Delivery', 'Expense', 0, 0),
    ('6100', 'Packaging', 'Expense', 0, 0),
    ('6200', 'Advertising and Marketing', 'Expense', 0, 0),
    ('6300', 'Rent', 'Expense', 0, 0),
    ('6400', 'Salaries and Wages', 'Expense', 0, 0),
    ('6500', 'Utilities', 'Expense', 0, 0),
    ('6600', 'Phone and Internet', 'Expense', 0, 0),
    ('6700', 'Bank Charges', 'Expense', 0, 0),
    ('6800', 'Office Expenses', 'Expense', 0, 0),
    ('6900', 'Other Expenses', 'Expense', 0, 0);

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
