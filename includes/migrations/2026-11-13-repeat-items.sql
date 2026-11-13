-- Lets one sale list the same item code on more than one line.
-- Import once, after 2026-11-13-item-numbers.sql and before uploading the new code.
ALTER TABLE sale_items ADD INDEX idx_sale_items_sale (sale_id);
ALTER TABLE sale_items DROP INDEX uq_sale_item;
