-- Users table, from 0.9 to 1.0, PostgreSQL.
-- For a users table made by conf/schema/users.pgsql.sql of 0.9: its columns take
-- their names of 1.0 (10 characters: "userName" becomes "systemName", "passHash"
-- "passwdHash", "userRole" "accessRole", "userStat" "userStatus", "actvCode"
-- "actvtnCode", "tokenKey" "bearerHash", "twoFaKey" "twoFactKey", "twoFaStp"
-- "twoFactPos", "failNmbr" "failsCount", "failDate" "failedTime", "madeDate"
-- "createDate", "lastSeen" "accessDate"), and it adds the columns of the access
-- mails ("mailboxAdr", "userLocale", "recovrCode", "codeExpiry", "signupDate"),
-- those of the codes mailed as a second factor ("twoFactWay", "mailedCode",
-- "mailExpiry"), the time of the last password ("passwdDate") and the status
-- of a self-registration awaiting a manager. The roles and the statuses take their
-- codes of 1.0: member, system and manage become internal, operator and sysadmin;
-- pendng, active and locked become awaiting, approved and disabled, and 'checking'
-- is the new status of a self-registration; a second factor mailed becomes emailOtp.
-- The devices table is moved by migrate-devices-1.0.postgres.sql, after this one.
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
--   psql -U <user> -d <database> -f migrate-users-1.0.postgres.sql
-- All of it is done, or none. The table name is the 'useTable' value of
-- conf/access.json: change "users" below when it is another, and the name
-- of the checks with it ("<table>_userRole_check", "<table>_userStat_check").

BEGIN;

-- The columns renamed. No row is changed.
ALTER TABLE "users" RENAME COLUMN "userName" TO "systemName";
ALTER TABLE "users" RENAME COLUMN "passHash" TO "passwdHash";
ALTER TABLE "users" RENAME COLUMN "userRole" TO "accessRole";
ALTER TABLE "users" RENAME COLUMN "userStat" TO "userStatus";
ALTER TABLE "users" RENAME COLUMN "actvCode" TO "actvtnCode";
ALTER TABLE "users" RENAME COLUMN "tokenKey" TO "bearerHash";
ALTER TABLE "users" RENAME COLUMN "twoFaKey" TO "twoFactKey";
ALTER TABLE "users" RENAME COLUMN "twoFaStp" TO "twoFactPos";
ALTER TABLE "users" RENAME COLUMN "failNmbr" TO "failsCount";
ALTER TABLE "users" RENAME COLUMN "failDate" TO "failedTime";
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
	ADD COLUMN "twoFactWay" CHAR(8)      NULL CHECK ("twoFactWay" IN ('emailOtp')),
	ADD COLUMN "mailedCode" CHAR(64)     NULL,
	ADD COLUMN "mailExpiry" TIMESTAMP(0) NULL,
	ADD UNIQUE ("mailboxAdr");

-- The codes of 1.0: the checks of 0.9 are dropped, the columns of the role and of the
-- status widened to 8 characters, every row given the code of its role and status,
-- and the checks of 1.0 added, under the names 1.0 gives them.
ALTER TABLE "users" DROP CONSTRAINT "users_userRole_check";
ALTER TABLE "users" DROP CONSTRAINT "users_userStat_check";
ALTER TABLE "users"
	ALTER COLUMN "accessRole" TYPE VARCHAR(8),
	ALTER COLUMN "accessRole" SET DEFAULT 'internal',
	ALTER COLUMN "userStatus" TYPE VARCHAR(8),
	ALTER COLUMN "userStatus" SET DEFAULT 'awaiting';
UPDATE "users" SET
	"accessRole" = CASE "accessRole" WHEN 'member' THEN 'internal' WHEN 'system' THEN 'operator' WHEN 'manage' THEN 'sysadmin' ELSE "accessRole" END,
	"userStatus" = CASE "userStatus" WHEN 'pendng' THEN 'awaiting' WHEN 'review' THEN 'checking' WHEN 'active' THEN 'approved' WHEN 'locked' THEN 'disabled' ELSE "userStatus" END;
ALTER TABLE "users" ADD CONSTRAINT "users_accessRole_check" CHECK ("accessRole" IN ('internal', 'operator', 'sysadmin'));
ALTER TABLE "users" ADD CONSTRAINT "users_userStatus_check" CHECK ("userStatus" IN ('awaiting', 'checking', 'approved', 'disabled'));

COMMIT;


-- Going back to 0.9, if needed (no user may be in 'checking'):
--
-- BEGIN;
-- ALTER TABLE "users" DROP CONSTRAINT "users_userStatus_check";
-- ALTER TABLE "users" DROP CONSTRAINT "users_accessRole_check";
-- UPDATE "users" SET "accessRole" = CASE "accessRole" WHEN 'internal' THEN 'member' WHEN 'operator' THEN 'system' WHEN 'sysadmin' THEN 'manage' ELSE "accessRole" END, "userStatus" = CASE "userStatus" WHEN 'awaiting' THEN 'pendng' WHEN 'approved' THEN 'active' WHEN 'disabled' THEN 'locked' ELSE "userStatus" END;
-- ALTER TABLE "users" ALTER COLUMN "accessRole" SET DEFAULT 'member', ALTER COLUMN "userStatus" SET DEFAULT 'pendng';
-- ALTER TABLE "users" DROP COLUMN "mailboxAdr", DROP COLUMN "userLocale", DROP COLUMN "recovrCode", DROP COLUMN "codeExpiry", DROP COLUMN "signupDate", DROP COLUMN "twoFactWay", DROP COLUMN "mailedCode", DROP COLUMN "mailExpiry", DROP COLUMN "passwdDate";
-- ALTER TABLE "users" RENAME COLUMN "systemName" TO "userName";
-- ALTER TABLE "users" RENAME COLUMN "passwdHash" TO "passHash";
-- ALTER TABLE "users" RENAME COLUMN "accessRole" TO "userRole";
-- ALTER TABLE "users" RENAME COLUMN "userStatus" TO "userStat";
-- ALTER TABLE "users" RENAME COLUMN "actvtnCode" TO "actvCode";
-- ALTER TABLE "users" RENAME COLUMN "bearerHash" TO "tokenKey";
-- ALTER TABLE "users" RENAME COLUMN "twoFactKey" TO "twoFaKey";
-- ALTER TABLE "users" RENAME COLUMN "twoFactPos" TO "twoFaStp";
-- ALTER TABLE "users" RENAME COLUMN "failsCount" TO "failNmbr";
-- ALTER TABLE "users" RENAME COLUMN "failedTime" TO "failDate";
-- ALTER TABLE "users" RENAME COLUMN "createDate" TO "madeDate";
-- ALTER TABLE "users" RENAME COLUMN "accessDate" TO "lastSeen";
-- ALTER TABLE "users" ADD CONSTRAINT "users_userRole_check" CHECK ("userRole" IN ('member', 'system', 'manage'));
-- ALTER TABLE "users" ADD CONSTRAINT "users_userStat_check" CHECK ("userStat" IN ('pendng', 'active', 'locked'));
-- COMMIT;
