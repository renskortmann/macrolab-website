-- Macrolab website - member roles.
--
-- lab_user        books machines
-- lab_technician  books machines and registers their own time
-- lab_manager     as a technician, and also sees and exports everyone's time
--
-- Accounts that exist when this runs become lab_technician, because until now
-- every member could register time and nobody should lose that silently. The
-- default for accounts added afterwards is lab_user, the least access.
--
-- Migrator::split() breaks this file on every semicolon, so the comments here
-- contain none.

ALTER TABLE users
    ADD COLUMN role ENUM('lab_user','lab_technician','lab_manager')
        NOT NULL DEFAULT 'lab_technician' AFTER status;

ALTER TABLE users ALTER COLUMN role SET DEFAULT 'lab_user';
