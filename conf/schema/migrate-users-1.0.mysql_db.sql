-- Users table, from 0.9 to 1.0, MySQL 8.0 or MariaDB 10.5 at least.
-- On MySQL 5.7, which has no RENAME COLUMN, use the variant
-- of part 1 written below it instead: comment part 1 out, and the variant in.
-- For a users table made by conf/schema/users.mysql.sql of 0.9: its columns take
-- their names of 1.0 (10 characters: userName becomes systemName, passHash
-- passwdHash, userRole accessRole, userStat userStatus, actvCode actvtnCode,
-- tokenKey bearerHash, twoFaKey twoFactKey, twoFaStp twoFactPos, failNmbr
-- failsCount, failDate failedTime, madeDate createDate, lastSeen accessDate), and
-- it adds the columns of the access mails (mailboxAdr, userLocale, recovrCode,
-- codeExpiry, signupDate), those of the codes mailed as a second factor
-- (twoFactWay, mailedCode, mailExpiry), the time of the last password
-- (passwdDate) and the status of a self-registration awaiting a manager. The roles
-- and the statuses take their codes of 1.0 (part 3): member, system and manage
-- become internal, operator and sysadmin; pendng, active and locked become
-- awaiting, approved and disabled, and 'checking' is the new status of a
-- self-registration; a second factor mailed becomes emailOtp.
-- The devices table is moved by migrate-devices-1.0.mysql_db.sql, after this one.
--
-- A table already moved to an early copy of 1.0 (with userMail, userLang,
-- resetCod, codeDate, signDate, and maybe twoFaWay, mailCode, mailDate and
-- passDate) has its columns renamed instead of added: in part 1, a line
-- RENAME COLUMN `userMail` TO `mailboxAdr` (and so on) in place of each ADD
-- COLUMN of a column it has, and RENAME INDEX `userMail` TO `mailboxAdr` in
-- place of the ADD UNIQUE KEY.
--
-- First a backup of the table:
--   mysqldump -u <user> -p <database> users > users-0.9.sql
-- Then this file, on the database of the application:
--   mysql -u <user> -p <database> < migrate-users-1.0.mysql_db.sql
-- It runs parts 1 and 3; parts 2 and 4 are commented out, as their lines depend
-- on the server.
-- The table name is the 'useTable' value of conf/access.json: change `users`
-- below when it is another.


-- 1. The columns renamed, the new ones added, and one account for each
--    address. No row is changed.

ALTER TABLE `users`
	RENAME COLUMN `userName` TO `systemName`,
	RENAME COLUMN `passHash` TO `passwdHash`,
	RENAME COLUMN `userRole` TO `accessRole`,
	RENAME COLUMN `userStat` TO `userStatus`,
	RENAME COLUMN `actvCode` TO `actvtnCode`,
	RENAME COLUMN `tokenKey` TO `bearerHash`,
	RENAME COLUMN `twoFaKey` TO `twoFactKey`,
	RENAME COLUMN `twoFaStp` TO `twoFactPos`,
	RENAME COLUMN `failNmbr` TO `failsCount`,
	RENAME COLUMN `failDate` TO `failedTime`,
	RENAME COLUMN `madeDate` TO `createDate`,
	RENAME COLUMN `lastSeen` TO `accessDate`,
	RENAME INDEX  `userName` TO `systemName`,
	RENAME INDEX  `tokenKey` TO `bearerHash`,
	ADD COLUMN `passwdDate` DATETIME     NULL AFTER `passwdHash`,
	ADD COLUMN `mailboxAdr` VARCHAR(254) NULL AFTER `userStatus`,
	ADD COLUMN `userLocale` VARCHAR(16)  NULL AFTER `mailboxAdr`,
	ADD COLUMN `recovrCode` VARCHAR(64)  NULL AFTER `actvtnCode`,
	ADD COLUMN `codeExpiry` DATETIME     NULL AFTER `recovrCode`,
	ADD COLUMN `signupDate` DATETIME     NULL AFTER `codeExpiry`,
	ADD COLUMN `twoFactWay` CHAR(8)      NULL AFTER `twoFactPos`,
	ADD COLUMN `mailedCode` CHAR(64)     NULL AFTER `twoFactWay`,
	ADD COLUMN `mailExpiry` DATETIME     NULL AFTER `mailedCode`,
	ADD CONSTRAINT `twoFactWay` CHECK (`twoFactWay` IN ('emailOtp')),
	ADD UNIQUE KEY `mailboxAdr` (`mailboxAdr`);

-- 1, MySQL 5.7. Each column is named again with its whole definition, the one
--    of the 0.9 schema; no row is changed. RENAME INDEX is there since 5.7.
--
-- ALTER TABLE `users`
-- 	CHANGE COLUMN `userName` `systemName` VARCHAR(64)  NOT NULL,
-- 	CHANGE COLUMN `passHash` `passwdHash` VARCHAR(255) NOT NULL,
-- 	CHANGE COLUMN `userRole` `accessRole` VARCHAR(6)   NOT NULL DEFAULT 'member',
-- 	CHANGE COLUMN `userStat` `userStatus` VARCHAR(6)   NOT NULL DEFAULT 'pendng',
-- 	CHANGE COLUMN `actvCode` `actvtnCode` VARCHAR(64)  NULL,
-- 	CHANGE COLUMN `tokenKey` `bearerHash` CHAR(64)     NULL,
-- 	CHANGE COLUMN `twoFaKey` `twoFactKey` VARCHAR(128) NULL,
-- 	CHANGE COLUMN `twoFaStp` `twoFactPos` INT UNSIGNED NULL,
-- 	CHANGE COLUMN `failNmbr` `failsCount` INT UNSIGNED NOT NULL DEFAULT 0,
-- 	CHANGE COLUMN `failDate` `failedTime` DATETIME     NULL,
-- 	CHANGE COLUMN `madeDate` `createDate` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
-- 	CHANGE COLUMN `lastSeen` `accessDate` DATETIME     NULL,
-- 	RENAME INDEX  `userName` TO `systemName`,
-- 	RENAME INDEX  `tokenKey` TO `bearerHash`,
-- 	ADD COLUMN `passwdDate` DATETIME     NULL AFTER `passwdHash`,
-- 	ADD COLUMN `mailboxAdr` VARCHAR(254) NULL AFTER `userStatus`,
-- 	ADD COLUMN `userLocale` VARCHAR(16)  NULL AFTER `mailboxAdr`,
-- 	ADD COLUMN `recovrCode` VARCHAR(64)  NULL AFTER `actvtnCode`,
-- 	ADD COLUMN `codeExpiry` DATETIME     NULL AFTER `recovrCode`,
-- 	ADD COLUMN `signupDate` DATETIME     NULL AFTER `codeExpiry`,
-- 	ADD COLUMN `twoFactWay` CHAR(8)      NULL AFTER `twoFactPos`,
-- 	ADD COLUMN `mailedCode` CHAR(64)     NULL AFTER `twoFactWay`,
-- 	ADD COLUMN `mailExpiry` DATETIME     NULL AFTER `mailedCode`,
-- 	ADD UNIQUE KEY `mailboxAdr` (`mailboxAdr`);


-- 2. MySQL 8.0.16 or later and MariaDB enforce CHECK constraints: the checks
--    of the roles and of the statuses of 0.9 are dropped before the codes
--    change (MySQL 5.7 does not enforce them: nothing to do there). Their names
--    are given by
--      SHOW CREATE TABLE `users`;
--    (the first two CHECK): users_chk_1 and users_chk_2 on MySQL, CONSTRAINT_1
--    and CONSTRAINT_2 on MariaDB, for a table made by the 0.9 schema. Run the
--    line of your server, with those names.
--
-- MySQL 8:
-- ALTER TABLE `users` DROP CHECK `users_chk_1`, DROP CHECK `users_chk_2`;
--
-- MariaDB:
-- ALTER TABLE `users` DROP CONSTRAINT `CONSTRAINT_1`, DROP CONSTRAINT `CONSTRAINT_2`;


-- 3. The codes of 1.0: the columns of the role and of the status are widened
--    to 8 characters, and every row takes the code of its role and status.

ALTER TABLE `users`
	MODIFY `accessRole` VARCHAR(8) NOT NULL DEFAULT 'internal',
	MODIFY `userStatus` VARCHAR(8) NOT NULL DEFAULT 'awaiting';

UPDATE `users` SET
	`accessRole` = CASE `accessRole` WHEN 'member' THEN 'internal' WHEN 'system' THEN 'operator' WHEN 'manage' THEN 'sysadmin' ELSE `accessRole` END,
	`userStatus` = CASE `userStatus` WHEN 'pendng' THEN 'awaiting' WHEN 'review' THEN 'checking' WHEN 'active' THEN 'approved' WHEN 'locked' THEN 'disabled' ELSE `userStatus` END,
	`twoFactWay` = CASE `twoFactWay` WHEN 'mailed' THEN 'emailOtp' ELSE `twoFactWay` END;


-- 4. MySQL 8 and MariaDB: the checks of 1.0.
--
-- ALTER TABLE `users`
-- 	ADD CONSTRAINT `accessRole` CHECK (`accessRole` IN ('internal', 'operator', 'sysadmin')),
-- 	ADD CONSTRAINT `userStatus` CHECK (`userStatus` IN ('awaiting', 'checking', 'approved', 'disabled'));


-- Going back to 0.9, if needed (no user may be in 'checking'; on MySQL 8 or
-- MariaDB, drop the checks of part 4 first, and put those of 0.9 back after):
--
-- UPDATE `users` SET
-- 	`accessRole` = CASE `accessRole` WHEN 'internal' THEN 'member' WHEN 'operator' THEN 'system' WHEN 'sysadmin' THEN 'manage' ELSE `accessRole` END,
-- 	`userStatus` = CASE `userStatus` WHEN 'awaiting' THEN 'pendng' WHEN 'approved' THEN 'active' WHEN 'disabled' THEN 'locked' ELSE `userStatus` END;
-- ALTER TABLE `users`
-- 	MODIFY `accessRole` VARCHAR(6) NOT NULL DEFAULT 'member',
-- 	MODIFY `userStatus` VARCHAR(6) NOT NULL DEFAULT 'pendng',
-- 	DROP INDEX  `mailboxAdr`,
-- 	DROP COLUMN `mailboxAdr`,
-- 	DROP COLUMN `userLocale`,
-- 	DROP COLUMN `recovrCode`,
-- 	DROP COLUMN `codeExpiry`,
-- 	DROP COLUMN `signupDate`,
-- 	DROP CHECK  `twoFactWay`,
-- 	DROP COLUMN `twoFactWay`,
-- 	DROP COLUMN `mailedCode`,
-- 	DROP COLUMN `mailExpiry`,
-- 	DROP COLUMN `passwdDate`,
-- 	RENAME COLUMN `systemName` TO `userName`,
-- 	RENAME COLUMN `passwdHash` TO `passHash`,
-- 	RENAME COLUMN `accessRole` TO `userRole`,
-- 	RENAME COLUMN `userStatus` TO `userStat`,
-- 	RENAME COLUMN `actvtnCode` TO `actvCode`,
-- 	RENAME COLUMN `bearerHash` TO `tokenKey`,
-- 	RENAME COLUMN `twoFactKey` TO `twoFaKey`,
-- 	RENAME COLUMN `twoFactPos` TO `twoFaStp`,
-- 	RENAME COLUMN `failsCount` TO `failNmbr`,
-- 	RENAME COLUMN `failedTime` TO `failDate`,
-- 	RENAME COLUMN `createDate` TO `madeDate`,
-- 	RENAME COLUMN `accessDate` TO `lastSeen`,
-- 	RENAME INDEX  `systemName` TO `userName`,
-- 	RENAME INDEX  `bearerHash` TO `tokenKey`;