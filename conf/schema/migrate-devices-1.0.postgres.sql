-- Devices table, from 0.9 to 1.0, PostgreSQL.
-- For a devices table made by conf/schema/devices.pgsql.sql of 0.9: its columns
-- take their names of 1.0 (10 characters). Run it after migrate-users-1.0.postgres.sql.
--
-- First a backup of the table:
--   pg_dump -U <user> -t '"devices"' <database> > devices-0.9.sql
-- Then this file, on the database of the application:
--   psql -U <user> -d <database> -f migrate-devices-1.0.postgres.sql
-- All of it is done, or none. The table name is the 'useTable' value of the
-- device block of conf/access.json: change "devices" below when it is another.

BEGIN;

ALTER TABLE "devices" RENAME COLUMN "devcName" TO "deviceName";
ALTER TABLE "devices" RENAME COLUMN "devcRole" TO "deviceRole";
ALTER TABLE "devices" RENAME COLUMN "tokenKey" TO "bearerHash";
ALTER TABLE "devices" RENAME COLUMN "lastSeen" TO "accessDate";
ALTER TABLE "devices" RENAME COLUMN "revkDate" TO "revokeDate";
ALTER TABLE "devices" RENAME COLUMN "madeDate" TO "createDate";

COMMIT;


-- Going back to 0.9, if needed:
--
-- BEGIN;
-- ALTER TABLE "devices" RENAME COLUMN "deviceName" TO "devcName";
-- ALTER TABLE "devices" RENAME COLUMN "deviceRole" TO "devcRole";
-- ALTER TABLE "devices" RENAME COLUMN "bearerHash" TO "tokenKey";
-- ALTER TABLE "devices" RENAME COLUMN "accessDate" TO "lastSeen";
-- ALTER TABLE "devices" RENAME COLUMN "revokeDate" TO "revkDate";
-- ALTER TABLE "devices" RENAME COLUMN "createDate" TO "madeDate";
-- COMMIT;
