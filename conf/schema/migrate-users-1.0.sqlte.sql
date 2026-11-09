-- Users table, from 0.9 to 1.0, SQLite.
-- For a users table made by conf/schema/users.sqlte.sql of 0.9: it adds the
-- columns of the access mails (userMail, userLang, resetCod, codeDate, signDate), those of
-- the codes mailed as a second factor (twoFaWay, mailCode, mailDate), the time of the last
-- password (passDate) and the status 'review'
-- of a self-registration awaiting a manager.
--
-- SQLite cannot change a CHECK constraint: the table is made again, as
-- conf/schema/users.sqlte.sql of 1.0 makes it, and its rows copied over with
-- their identifiers. The foreign keys are turned off for the time of it, so
-- that the devices of the users stay where they are.
--
-- First a copy of the database file. Then this file, on it:
--   sqlite3 <database file> < migrate-users-1.0.sqlte.sql
-- All of it is done, or none. The table name is the 'useTable' value of
-- conf/access.json: change `users` below when it is another.

PRAGMA foreign_keys = OFF;

BEGIN;

CREATE TABLE `users_1_0` (
	`id_user`  INTEGER PRIMARY KEY,
	`userName` TEXT    NOT NULL,
	`passHash` TEXT    NOT NULL,
	`passDate` TEXT    NULL,
	`userRole` TEXT    NOT NULL DEFAULT 'member',
	`userStat` TEXT    NOT NULL DEFAULT 'pendng',
	`userMail` TEXT    NULL,
	`userLang` TEXT    NULL,
	`actvCode` TEXT    NULL,
	`resetCod` TEXT    NULL,
	`codeDate` TEXT    NULL,
	`signDate` TEXT    NULL,
	`tokenKey` TEXT    NULL,
	`twoFaKey` TEXT    NULL,
	`twoFaStp` INTEGER NULL,
	`twoFaWay` TEXT    NULL,
	`mailCode` TEXT    NULL,
	`mailDate` TEXT    NULL,
	`failNmbr` INTEGER NOT NULL DEFAULT 0,
	`failDate` TEXT    NULL,
	`madeDate` TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`lastSeen` TEXT    NULL,
	UNIQUE (`userName`),
	UNIQUE (`tokenKey`),
	UNIQUE (`userMail`),
	CHECK (`userRole` IN ('member', 'system', 'manage')),
	CHECK (`userStat` IN ('pendng', 'review', 'active', 'locked')),
	CHECK (`twoFaWay` IN ('mailed'))
);

INSERT INTO `users_1_0` (`id_user`, `userName`, `passHash`, `userRole`, `userStat`, `actvCode`, `tokenKey`, `twoFaKey`, `twoFaStp`, `failNmbr`, `failDate`, `madeDate`, `lastSeen`)
	SELECT `id_user`, `userName`, `passHash`, `userRole`, `userStat`, `actvCode`, `tokenKey`, `twoFaKey`, `twoFaStp`, `failNmbr`, `failDate`, `madeDate`, `lastSeen` FROM `users`;

DROP TABLE `users`;

ALTER TABLE `users_1_0` RENAME TO `users`;

COMMIT;

PRAGMA foreign_keys = ON;


-- A table already moved to 1.0 before the mailed codes and the time of the last password
-- (an early copy of 1.0) only needs their columns, without the check of twoFaWay, which SQLite cannot add:
--
-- ALTER TABLE `users` ADD COLUMN `twoFaWay` TEXT NULL;
-- ALTER TABLE `users` ADD COLUMN `mailCode` TEXT NULL;
-- ALTER TABLE `users` ADD COLUMN `mailDate` TEXT NULL;
-- ALTER TABLE `users` ADD COLUMN `passDate` TEXT NULL;
