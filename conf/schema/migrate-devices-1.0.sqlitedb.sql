-- Devices table, from 0.9 to 1.0, SQLite 3.25 at least.
-- For a devices table made by conf/schema/devices.sqlte.sql of 0.9: its columns
-- take their names of 1.0 (10 characters). Run it after migrate-users-1.0.sqlitedb.sql.
--
-- First a copy of the database file. Then this file, on it:
--   sqlite3 <database file> < migrate-devices-1.0.sqlitedb.sql
-- All of it is done, or none. The table name is the 'useTable' value of the
-- device block of conf/access.json: change `devices` below when it is another.

BEGIN;

ALTER TABLE `devices` RENAME COLUMN `devcName` TO `deviceName`;
ALTER TABLE `devices` RENAME COLUMN `devcRole` TO `deviceRole`;
ALTER TABLE `devices` RENAME COLUMN `tokenKey` TO `bearerHash`;
ALTER TABLE `devices` RENAME COLUMN `lastSeen` TO `accessDate`;
ALTER TABLE `devices` RENAME COLUMN `revkDate` TO `revokeDate`;
ALTER TABLE `devices` RENAME COLUMN `madeDate` TO `createDate`;

COMMIT;

-- The cap of the devices (deviceTops, Access\Auth\Device), added in 1.0: the highest role
-- a device acts with. A table moved above gets it with this last statement; a table
-- already at 1.0 runs this statement alone. Empty, a device acts with its user's
-- role, as before; php nyuRun install checkAll tells the devices to give a cap (device caps).

ALTER TABLE `devices` ADD COLUMN `deviceTops` TEXT NULL CHECK (`deviceTops` IS NULL OR `deviceTops` IN ('standard', 'advanced', 'delegate', 'managers'));
