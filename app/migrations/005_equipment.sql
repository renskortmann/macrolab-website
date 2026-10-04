-- Macrolab website - "equipment" instead of "resource" and "machine".
--
-- The lab calls what it books equipment, so the schema does too. Nothing about
-- the data changes: the table and its column are renamed, the indexes and the
-- foreign key follow, and so do the words in the audit log. Who did what and
-- when stays exactly as recorded, only the name of the action reads
-- equipment_added instead of machine_added.
--
-- The description of the machine that 001 seeded is shortened only if it is
-- still the original text, so a description the administrator wrote is left
-- alone.
--
-- Migrator::split() breaks this file on every semicolon, so the comments here
-- contain none.

RENAME TABLE resources TO equipment;

ALTER TABLE equipment RENAME INDEX uq_resources_slug TO uq_equipment_slug;

ALTER TABLE bookings DROP FOREIGN KEY fk_bookings_resource;

ALTER TABLE bookings
    RENAME COLUMN resource_id TO equipment_id,
    RENAME INDEX ix_bookings_resource_window TO ix_bookings_equipment_window;

ALTER TABLE bookings ADD CONSTRAINT fk_bookings_equipment
    FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE RESTRICT;

UPDATE equipment SET description = 'LUNA OD6'
 WHERE slug = 'luna-od6' AND description = 'LUNA OD6 machine';

UPDATE audit_log SET action = REPLACE(action, 'machine_', 'equipment_')
 WHERE action LIKE 'machine\_%';

UPDATE audit_log SET target_type = 'equipment' WHERE target_type = 'resource';
