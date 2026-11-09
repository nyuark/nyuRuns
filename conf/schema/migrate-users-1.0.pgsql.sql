-- Users table, from 0.9 to 1.0, PostgreSQL.
-- For a users table made by conf/schema/users.pgsql.sql of 0.9: its columns take
-- their names of 1.0 (10 characters: "userName" becomes "userHandle", "passHash"
-- "passwdHash", "userRole" "accessRole", "userStat" "userStatus", "actvCode"
-- "actvtnCode", "tokenKey" "bearerHash", "twoFaKey" "twoFactKey", "twoFaStp"
-- "twoFactStp", "failNmbr" "failsCount", "failDate" "failedDate", "madeDate"
-- "createDate", "lastSeen" "accessDate"), and it adds the columns of the access
-- mails ("mailboxAdr", "userLocale", "recovrCode", "codeExpiry", "signupDate"),
-- those of the codes mailed as a second factor ("twoFactWay", "mailedCode",
-- "mailExpiry"), the time of the last password ("passwdDate") and the status
-- 'review' of a self-registration awaiting a manager.
-- The devices table is moved by migrate-devices-1.0.pgsql.sql, after this one.
--
-- A table already moved to an early copy of 1.0 (with "userMail", "userLang",
-- "resetCod", "codeDate", "signDate", and maybe "twoFaWay", "mailCode",
-- "mailDate" and "passDate") has those columns renamed instead of added: a
-- line ALTER TABLE "users" RENAME COLUMN "userMail" TO "mailboxAdr"; (and so
-- on) in place of each ADD COLUMN of a column it has.
--
-- First a backup of the table:
--   pg_dump -U <user> -t '"users"' <database> > users-0.9.sql
-- Then this file, on the database of the application:
--   psql -U <user> -d <database> -f migrate-users-1.0.pgsql.sql
-- All of it is done, or none. The table name is the 'useTable' value of
-- conf/access.json: change "users" below when it is another, and the name
-- of the checks with it ("<table>_userStat_check").

BEGIN;

-- The columns renamed. No row is changed.
ALTER TABLE "users" RENAME COLUMN "userName" TO "userHandle";
ALTER TABLE "users" RENAME COLUMN "passHash" TO "passwdHash";
ALTER TABLE "users" RENAME COLUMN "userRole" TO "accessRole";
ALTER TABLE "users" RENAME COLUMN "userStat" TO "userStatus";
ALTER TABLE "users" RENAME COLUMN "actvCode" TO "actvtnCode";
ALTER TABLE "users" RENAME COLUMN "tokenKey" TO "bearerHash";
ALTER TABLE "users" RENAME COLUMN "twoFaKey" TO "twoFactKey";
ALTER TABLE "users" RENAME COLUMN "twoFaStp" TO "twoFactStp";
ALTER TABLE "users" RENAME COLUMN "failNmbr" TO "failsCount";
ALTER TABLE "users" RENAME COLUMN "failDate" TO "failedDate";
ALTER TABLE "users" RENAME COLUMN "madeDate" TO "createDate";
ALTER TABLE "users" RENAME COLUMN "lastSeen" TO "accessDate";

-- The new columns, and one account for each address. No row is changed.
ALTER TABLE "users"
	ADD COLUMN "passwdDate" TIMESTAMP(0) NULL,
	ADD COLUMN "mailboxAdr" VARCHAR(254) NULL,
	ADD COLUMN "userLocale" VARCHAR(16)  NULL,
	ADD COLUMN "recovrCode" VARCHAR(64)  NULL,
	ADD COLUMN "codeExpiry" TIMESTAMP(0) NULL,
	ADD COLUMN "signupDate" TIMESTAMP(0) NULL,
	ADD COLUMN "twoFactWay" CHAR(6)      NULL CHECK ("twoFactWay" IN ('mailed')),
	ADD COLUMN "mailedCode" CHAR(64)     NULL,
	ADD COLUMN "mailExpiry" TIMESTAMP(0) NULL,
	ADD UNIQUE ("mailboxAdr");

-- The status 'review': the check of "userStatus" is replaced, under the name 1.0 gives it.
ALTER TABLE "users" DROP CONSTRAINT "users_userStat_check";
ALTER TABLE "users" ADD CONSTRAINT "users_userStatus_check" CHECK ("userStatus" IN ('pendng', 'review', 'active', 'locked'));

COMMIT;


-- Going back to 0.9, if needed (no user may be in 'review'):
--
-- BEGIN;
-- ALTER TABLE "users" DROP CONSTRAINT "users_userStatus_check";
-- ALTER TABLE "users" DROP COLUMN "mailboxAdr", DROP COLUMN "userLocale", DROP COLUMN "recovrCode", DROP COLUMN "codeExpiry", DROP COLUMN "signupDate", DROP COLUMN "twoFactWay", DROP COLUMN "mailedCode", DROP COLUMN "mailExpiry", DROP COLUMN "passwdDate";
-- ALTER TABLE "users" RENAME COLUMN "userHandle" TO "userName";
-- ALTER TABLE "users" RENAME COLUMN "passwdHash" TO "passHash";
-- ALTER TABLE "users" RENAME COLUMN "accessRole" TO "userRole";
-- ALTER TABLE "users" RENAME COLUMN "userStatus" TO "userStat";
-- ALTER TABLE "users" RENAME COLUMN "actvtnCode" TO "actvCode";
-- ALTER TABLE "users" RENAME COLUMN "bearerHash" TO "tokenKey";
-- ALTER TABLE "users" RENAME COLUMN "twoFactKey" TO "twoFaKey";
-- ALTER TABLE "users" RENAME COLUMN "twoFactStp" TO "twoFaStp";
-- ALTER TABLE "users" RENAME COLUMN "failsCount" TO "failNmbr";
-- ALTER TABLE "users" RENAME COLUMN "failedDate" TO "failDate";
-- ALTER TABLE "users" RENAME COLUMN "createDate" TO "madeDate";
-- ALTER TABLE "users" RENAME COLUMN "accessDate" TO "lastSeen";
-- ALTER TABLE "users" ADD CONSTRAINT "users_userStat_check" CHECK ("userStat" IN ('pendng', 'active', 'locked'));
-- COMMIT;
