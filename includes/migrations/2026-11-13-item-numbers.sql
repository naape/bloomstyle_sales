-- Adds the specific number of each piece sold, such as 101 for PHL9-101.
-- Import once, after selecting the database in phpMyAdmin, and before uploading the new code.
ALTER TABLE sale_items ADD COLUMN item_no VARCHAR(255) NOT NULL DEFAULT '' AFTER item_id;
