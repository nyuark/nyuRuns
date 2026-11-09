-- Users table, from 0.9 to 1.0, PostgreSQL.
-- For a users table made by conf/schema/users.pgsql.sql of 0.9: it adds the
-- columns of the access mails ("userMail", "userLang", "resetCod", "codeDate", "signDate"),
-- those of the codes mailed as a second factor ("twoFaWay", "mailCode", "mailDate") and the
-- status 'review' of a self-registration awaiting a manager. A table already moved to 1.0
-- before the mailed codes (an early copy of 1.0) only needs the lines of those three.
--
-- First a backup of the table:
--   pg_dump -U <user> -t '"users"' <database> > users-0.9.sql
-- Then this file, on the database of the application:
--   psql -U <user> -d <database> -f migrate-users-1.0.pgsql.sql
-- All of it is done, or none. The table name is the 'tblnme' value of
-- conf/access.json: change "users" below when it is another, and the name
-- of the check with it ("<table>_userStat_check").

BEGIN;

-- The new columns, and one account for each address. No row is changed.
ALTER TABLE "users"
	ADD COLUMN "userMail" VARCHAR(254) NULL,
	ADD COLUMN "userLang" VARCHAR(16)  NULL,
	ADD COLUMN "resetCod" VARCHAR(64)  NULL,
	ADD COLUMN "codeDate" TIMESTAMP(0) NULL,
	ADD COLUMN "signDate" TIMESTAMP(0) NULL,
	ADD COLUMN "twoFaWay" CHAR(6)      NULL CHECK ("twoFaWay" IN ('mailed')),
	ADD COLUMN "mailCode" CHAR(64)     NULL,
	ADD COLUMN "mailDate" TIMESTAMP(0) NULL,
	ADD UNIQUE ("userMail");

-- The status 'review': the check of "userStat" is replaced.
ALTER TABLE "users" DROP CONSTRAINT "users_userStat_check";
ALTER TABLE "users" ADD CONSTRAINT "users_userStat_check" CHECK ("userStat" IN ('pendng', 'review', 'active', 'locked'));

COMMIT;


-- Going back to 0.9, if needed (no user may be in 'review'):
--
-- BEGIN;
-- ALTER TABLE "users" DROP CONSTRAINT "users_userStat_check";
-- ALTER TABLE "users" ADD CONSTRAINT "users_userStat_check" CHECK ("userStat" IN ('pendng', 'active', 'locked'));
-- ALTER TABLE "users" DROP COLUMN "userMail", DROP COLUMN "userLang", DROP COLUMN "resetCod", DROP COLUMN "codeDate", DROP COLUMN "signDate", DROP COLUMN "twoFaWay", DROP COLUMN "mailCode", DROP COLUMN "mailDate";
-- COMMIT;
