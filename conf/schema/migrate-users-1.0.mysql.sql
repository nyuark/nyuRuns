-- Users table, from 0.9 to 1.0, MySQL 5.7 or later, MariaDB 10.5 or later.
-- For a users table made by conf/schema/users.mysql.sql of 0.9: it adds the
-- columns of the access mails (userMail, userLang, resetCod, codeDate, signDate), those of
-- the codes mailed as a second factor (twoFaWay, mailCode, mailDate), the time of the last
-- password (passDate) and the status 'review' of a self-registration awaiting a manager. A
-- table already moved to 1.0 before them (an early copy of 1.0) only needs the lines of
-- passDate, twoFaWay, mailCode and mailDate, and the check of twoFaWay.
--
-- First a backup of the table:
--   mysqldump -u <user> -p <database> users > users-0.9.sql
-- Then this file, on the database of the application:
--   mysql -u <user> -p <database> < migrate-users-1.0.mysql.sql
-- It runs part 1; part 2 is commented out, as its line depends on the server.
-- The table name is the 'tblnme' value of conf/access.json: change `users`
-- below when it is another.


-- 1. The new columns, and one account for each address. No row is changed.

ALTER TABLE `users`
	ADD COLUMN `passDate` DATETIME     NULL AFTER `passHash`,
	ADD COLUMN `userMail` VARCHAR(254) NULL AFTER `userStat`,
	ADD COLUMN `userLang` VARCHAR(16)  NULL AFTER `userMail`,
	ADD COLUMN `resetCod` VARCHAR(64)  NULL AFTER `actvCode`,
	ADD COLUMN `codeDate` DATETIME     NULL AFTER `resetCod`,
	ADD COLUMN `signDate` DATETIME     NULL AFTER `codeDate`,
	ADD COLUMN `twoFaWay` CHAR(6)      NULL AFTER `twoFaStp`,
	ADD COLUMN `mailCode` CHAR(64)     NULL AFTER `twoFaWay`,
	ADD COLUMN `mailDate` DATETIME     NULL AFTER `mailCode`,
	ADD CONSTRAINT `twoFaWay` CHECK (`twoFaWay` IN ('mailed')),
	ADD UNIQUE KEY `userMail` (`userMail`);


-- 2. The status 'review'. MySQL 5.7 does not enforce CHECK constraints: nothing
--    to do there. MySQL 8.0.16 or later and MariaDB do: the check of userStat is
--    replaced. Its name is given by
--      SHOW CREATE TABLE `users`;
--    (the second CHECK, on userStat): users_chk_2 on MySQL, CONSTRAINT_2 on
--    MariaDB, for a table made by the 0.9 schema. Run the line of your server,
--    with that name.
--
-- MySQL 8:
-- ALTER TABLE `users` DROP CHECK `users_chk_2`, ADD CONSTRAINT `userStat` CHECK (`userStat` IN ('pendng', 'review', 'active', 'locked'));
--
-- MariaDB:
-- ALTER TABLE `users` DROP CONSTRAINT `CONSTRAINT_2`, ADD CONSTRAINT `userStat` CHECK (`userStat` IN ('pendng', 'review', 'active', 'locked'));


-- Going back to 0.9, if needed (no user may be in 'review'; on MySQL 8 or
-- MariaDB, put the former check back as well):
--
-- ALTER TABLE `users`
-- 	DROP INDEX  `userMail`,
-- 	DROP COLUMN `userMail`,
-- 	DROP COLUMN `userLang`,
-- 	DROP COLUMN `resetCod`,
-- 	DROP COLUMN `codeDate`,
-- 	DROP COLUMN `signDate`,
-- 	DROP CHECK `twoFaWay`,
-- 	DROP COLUMN `twoFaWay`,
-- 	DROP COLUMN `mailCode`,
-- 	DROP COLUMN `mailDate`,
-- 	DROP COLUMN `passDate`;
