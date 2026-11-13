-- Adds the Waybills page: ranges of waybill numbers that sales pick from.
-- Import once, before uploading the new code. Importing it again is harmless.

CREATE TABLE IF NOT EXISTS waybill_ranges (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_no   VARCHAR(50)  NOT NULL,
    last_no    VARCHAR(50)  NOT NULL,
    note       VARCHAR(255) NOT NULL DEFAULT '',
    created_by VARCHAR(50)  NOT NULL DEFAULT '',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS waybills (
    number   VARCHAR(50)  NOT NULL PRIMARY KEY,
    range_id INT UNSIGNED NOT NULL,
    FOREIGN KEY (range_id) REFERENCES waybill_ranges(id) ON DELETE CASCADE
) ENGINE=InnoDB;
