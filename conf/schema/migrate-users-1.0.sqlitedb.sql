-- Users table, from 0.9 to 1.0, SQLite.
-- For a users table made by conf/schema/users.sqlte.sql of 0.9: its columns take
-- their names of 1.0 (10 characters: userName becomes userHandle, passHash
-- passwdHash, userRole accessRole, userStat userStatus, actvCode actvtnCode,
-- tokenKey bearerHash, twoFaKey twoFactKey, twoFaStp twoFactStp, failNmbr
-- failsCount, failDate failedDate, madeDate createDate, lastSeen accessDate), and
-- it adds the columns of the access mails (mailboxAdr, userLocale, recovrCode,
-- codeExpiry, signupDate), those of the codes mailed as a second factor
-- (twoFactWay, mailedCode, mailExpiry), the time of the last password
-- (passwdDate) and the status of a self-registration awaiting a manager. The roles
-- and the statuses take their codes of 1.0: member, system and manage become
-- internal, operator and sysadmin; pendng, review (of an early copy of 1.0),
-- active and locked become awaiting, checking, approved and disabled; a second
-- factor mailed becomes emailOtp.
-- The devices table is moved by migrate-devices-1.0.sqlitedb.sql, after this one.
--
-- SQLite cannot change a CHECK constraint: the table is made again, as
-- conf/schema/users.sqlitedb.sql of 1.0 makes it, and its rows copied over with
-- their identifiers. The foreign keys are turned off for the time of it, so
-- that the devices of the users stay where they are.
--
-- First a copy of the database file. Then this file, on it:
--   sqlite3 <database file> < migrate-users-1.0.sqlitedb.sql
-- All of it is done, or none. The table name is the 'useTable' value of
-- conf/access.json: change `users` below when it is another.

PRAGMA foreign_keys = OFF;

BEGIN;

CREATE TABLE `users_1_0` (
	`id_user`    INTEGER PRIMARY KEY,
	`userHandle` TEXT    NOT NULL,
	`passwdHash` TEXT    NOT NULL,
	`passwdDate` TEXT    NULL,
	`accessRole` TEXT    NOT NULL DEFAULT 'internal',
	`userStatus` TEXT    NOT NULL DEFAULT 'awaiting',
	`mailboxAdr` TEXT    NULL,
	`userLocale` TEXT    NULL,
	`actvtnCode` TEXT    NULL,
	`recovrCode` TEXT    NULL,
	`codeExpiry` TEXT    NULL,
	`signupDate` TEXT    NULL,
	`bearerHash` TEXT    NULL,
	`twoFactKey` TEXT    NULL,
	`twoFactStp` INTEGER NULL,
	`twoFactWay` TEXT    NULL,
	`mailedCode` TEXT    NULL,
	`mailExpiry` TEXT    NULL,
	`failsCount` INTEGER NOT NULL DEFAULT 0,
	`failedDate` TEXT    NULL,
	`createDate` TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`accessDate` TEXT    NULL,
	UNIQUE (`userHandle`),
	UNIQUE (`bearerHash`),
	UNIQUE (`mailboxAdr`),
	CHECK (`accessRole` IN ('internal', 'operator', 'sysadmin')),
	CHECK (`userStatus` IN ('awaiting', 'checking', 'approved', 'disabled')),
	CHECK (`twoFactWay` IN ('emailOtp'))
);

INSERT INTO `users_1_0` (`id_user`, `userHandle`, `passwdHash`, `accessRole`, `userStatus`, `actvtnCode`, `bearerHash`, `twoFactKey`, `twoFactStp`, `failsCount`, `failedDate`, `createDate`, `accessDate`)
	SELECT `id_user`, `userName`, `passHash`, CASE `userRole` WHEN 'member' THEN 'internal' WHEN 'system' THEN 'operator' WHEN 'manage' THEN 'sysadmin' END, CASE `userStat` WHEN 'pendng' THEN 'awaiting' WHEN 'review' THEN 'checking' WHEN 'active' THEN 'approved' WHEN 'locked' THEN 'disabled' END, `actvCode`, `tokenKey`, `twoFaKey`, `twoFaStp`, `failNmbr`, `failDate`, `madeDate`, `lastSeen` FROM `users`;

DROP TABLE `users`;

ALTER TABLE `users_1_0` RENAME TO `users`;

COMMIT;

PRAGMA foreign_keys = ON;


-- A table already moved to an early copy of 1.0 (with userMail, userLang,
-- resetCod, codeDate, signDate, and maybe twoFaWay, mailCode, mailDate and
-- passDate) keeps those values too: in place of the INSERT above, this one,
-- leaving out of both lists the columns the table does not have yet.
--
-- INSERT INTO `users_1_0` (`id_user`, `userHandle`, `passwdHash`, `passwdDate`, `accessRole`, `userStatus`, `mailboxAdr`, `userLocale`, `actvtnCode`, `recovrCode`, `codeExpiry`, `signupDate`, `bearerHash`, `twoFactKey`, `twoFactStp`, `twoFactWay`, `mailedCode`, `mailExpiry`, `failsCount`, `failedDate`, `createDate`, `accessDate`)
-- 	SELECT `id_user`, `userName`, `passHash`, `passDate`, CASE `userRole` WHEN 'member' THEN 'internal' WHEN 'system' THEN 'operator' WHEN 'manage' THEN 'sysadmin' END, CASE `userStat` WHEN 'pendng' THEN 'awaiting' WHEN 'review' THEN 'checking' WHEN 'active' THEN 'approved' WHEN 'locked' THEN 'disabled' END, `userMail`, `userLang`, `actvCode`, `resetCod`, `codeDate`, `signDate`, `tokenKey`, `twoFaKey`, `twoFaStp`, CASE `twoFaWay` WHEN 'mailed' THEN 'emailOtp' END, `mailCode`, `mailDate`, `failNmbr`, `failDate`, `madeDate`, `lastSeen` FROM `users`;
