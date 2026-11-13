-- Adds the Waybills page: ranges of waybill numbers that sales pick from.
-- Import once, before uploading the new code.
--
-- The waybills table takes the same character set and collation as sales.waybill_no,
-- so the two can be compared even when the database default differs from that table.
ALTER TABLE sales ADD INDEX idx_sales_waybill (waybill_no);

CREATE TABLE IF NOT EXISTS waybill_ranges (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_no   VARCHAR(50)  NOT NULL,
    last_no    VARCHAR(50)  NOT NULL,
    note       VARCHAR(255) NOT NULL DEFAULT '',
    created_by VARCHAR(50)  NOT NULL DEFAULT '',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SELECT CHARACTER_SET_NAME, COLLATION_NAME INTO @waybill_charset, @waybill_collation
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'waybill_no';

SET @create_waybills = CONCAT(
    'CREATE TABLE IF NOT EXISTS waybills (',
    ' number VARCHAR(50) CHARACTER SET ', @waybill_charset, ' COLLATE ', @waybill_collation, ' NOT NULL PRIMARY KEY,',
    ' range_id INT UNSIGNED NOT NULL,',
    ' FOREIGN KEY (range_id) REFERENCES waybill_ranges(id) ON DELETE CASCADE',
    ') ENGINE=InnoDB');
PREPARE create_waybills FROM @create_waybills;
EXECUTE create_waybills;
DEALLOCATE PREPARE create_waybills;
