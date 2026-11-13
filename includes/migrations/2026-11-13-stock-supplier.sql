-- Adds a Supplier field to stock entries, for purchases by supplier on the Dashboard.
-- Import once, before uploading the new code.
ALTER TABLE stock ADD COLUMN supplier VARCHAR(100) NOT NULL DEFAULT '' AFTER item_id;

-- Fill it in for earlier purchases from their notes: the name after the last " - ",
-- as in "Lanka Handloom Polyster - Janaki". Damaged and replacement stock is left blank.
UPDATE stock SET supplier = TRIM(SUBSTRING_INDEX(note, ' - ', -1))
WHERE supplier = '' AND unit_price IS NOT NULL AND note LIKE '% - %';

UPDATE stock SET supplier = TRIM(REPLACE(supplier, '(Bulk Order)', '')) WHERE supplier LIKE '%(Bulk Order)%';
UPDATE stock SET supplier = 'Kapila Wijenayaka' WHERE supplier = 'Kapila Wijenayake';
