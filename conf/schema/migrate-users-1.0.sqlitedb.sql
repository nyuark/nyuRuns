-- Users table, from 0.9 to 1.0, SQLite.
-- For a users table made by conf/schema/users.sqlte.sql of 0.9: its columns take
-- their names of 1.0 (10 characters: userName becomes systemName, passHash
-- passwdHash, userRole accessRole, userStat userStatus, actvCode actvtnCode,
-- tokenKey bearerHash, twoFaKey twoFactKey, twoFaStp twoFactPos, failNmbr
-- failsCount, failDate failedTime, madeDate createDate, lastSeen accessDate), and
-- it adds the columns of the access mails (contactEml, userLocale, recovrCode,
-- codeExpiry, signupDate), those of the codes mailed as a second factor
-- (twoFactWay, twoFactHsh, twoFactExp), the time of the last password
-- (passwdDate) and the status of a self-registration awaiting a manager. The roles
-- and the statuses take their codes of 1.0: member, system and manage become
-- standard, advanced and managers; pendng, review (of an early copy of 1.0),
-- active and locked become awaiting, inReview, approved and disabled; a second
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
	`systemName` TEXT    NOT NULL,
	`passwdHash` TEXT    NOT NULL,
	`passwdDate` TEXT    NULL,
	`accessRole` TEXT    NOT NULL DEFAULT 'standard',
	`userStatus` TEXT    NOT NULL DEFAULT 'awaiting',
	`contactEml` TEXT    NULL,
	`userLocale` TEXT    NULL,
	`actvtnCode` TEXT    NULL,
	`recovrCode` TEXT    NULL,
	`codeExpiry` TEXT    NULL,
	`signupDate` TEXT    NULL,
	`bearerHash` TEXT    NULL,
	`twoFactKey` TEXT    NULL,
	`twoFactPos` INTEGER NULL,
	`twoFactWay` TEXT    NULL,
	`twoFactHsh` TEXT    NULL,
	`twoFactExp` TEXT    NULL,
	`failsCount` INTEGER NOT NULL DEFAULT 0,
	`failedTime` TEXT    NULL,
	`createDate` TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`accessDate` TEXT    NULL,
	UNIQUE (`systemName`),
	UNIQUE (`bearerHash`),
	UNIQUE (`contactEml`),
	CHECK (`accessRole` IN ('standard', 'advanced', 'delegate', 'managers')),
	CHECK (`userStatus` IN ('awaiting', 'inReview', 'approved', 'disabled')),
	CHECK (`twoFactWay` IN ('emailOtp'))
);

INSERT INTO `users_1_0` (`id_user`, `systemName`, `passwdHash`, `accessRole`, `userStatus`, `actvtnCode`, `bearerHash`, `twoFactKey`, `twoFactPos`, `failsCount`, `failedTime`, `createDate`, `accessDate`)
	SELECT `id_user`, `userName`, `passHash`, CASE `userRole` WHEN 'member' THEN 'standard' WHEN 'system' THEN 'advanced' WHEN 'manage' THEN 'managers' END, CASE `userStat` WHEN 'pendng' THEN 'awaiting' WHEN 'review' THEN 'inReview' WHEN 'active' THEN 'approved' WHEN 'locked' THEN 'disabled' END, `actvCode`, `tokenKey`, `twoFaKey`, `twoFaStp`, `failNmbr`, `failDate`, `madeDate`, `lastSeen` FROM `users`;

DROP TABLE `users`;

ALTER TABLE `users_1_0` RENAME TO `users`;

COMMIT;

PRAGMA foreign_keys = ON;


-- A table already moved to an early copy of 1.0 (with userMail, userLang,
-- resetCod, codeDate, signDate, and maybe twoFaWay, mailCode, mailDate and
-- passDate) keeps those values too: in place of the INSERT above, this one,
-- leaving out of both lists the columns the table does not have yet.
--
-- INSERT INTO `users_1_0` (`id_user`, `systemName`, `passwdHash`, `passwdDate`, `accessRole`, `userStatus`, `contactEml`, `userLocale`, `actvtnCode`, `recovrCode`, `codeExpiry`, `signupDate`, `bearerHash`, `twoFactKey`, `twoFactPos`, `twoFactWay`, `twoFactHsh`, `twoFactExp`, `failsCount`, `failedTime`, `createDate`, `accessDate`)
-- 	SELECT `id_user`, `userName`, `passHash`, `passDate`, CASE `userRole` WHEN 'member' THEN 'standard' WHEN 'system' THEN 'advanced' WHEN 'manage' THEN 'managers' END, CASE `userStat` WHEN 'pendng' THEN 'awaiting' WHEN 'review' THEN 'inReview' WHEN 'active' THEN 'approved' WHEN 'locked' THEN 'disabled' END, `userMail`, `userLang`, `actvCode`, `resetCod`, `codeDate`, `signDate`, `tokenKey`, `twoFaKey`, `twoFaStp`, CASE `twoFaWay` WHEN 'mailed' THEN 'emailOtp' END, `mailCode`, `mailDate`, `failNmbr`, `failDate`, `madeDate`, `lastSeen` FROM `users`;

-- A table already at 1.0, made before userHandle, twoFactStp, failedDate,
-- mailboxAdr, mailedCode and mailExpiry took their names of 1.0 final
-- (systemName, twoFactPos, failedTime, contactEml, twoFactHsh, twoFactExp) and
-- the status checking became inReview, and the roles internal, operator and
-- sysadmin became standard, advanced and managers (delegate added between the
-- last two): none of the lines above, only these, on a copy of the
-- database file first. SQLite cannot change a CHECK constraint: the table is
-- made again, as conf/schema/users.sqlitedb.sql makes it, its rows copied over
-- with their identifiers, the foreign keys off for the time of it.
--
-- PRAGMA foreign_keys = OFF;
-- BEGIN;
-- CREATE TABLE `users_1_0` (
-- 	`id_user`    INTEGER PRIMARY KEY,
-- 	`systemName` TEXT    NOT NULL,
-- 	`passwdHash` TEXT    NOT NULL,
-- 	`passwdDate` TEXT    NULL,
-- 	`accessRole` TEXT    NOT NULL DEFAULT 'standard',
-- 	`userStatus` TEXT    NOT NULL DEFAULT 'awaiting',
-- 	`contactEml` TEXT    NULL,
-- 	`userLocale` TEXT    NULL,
-- 	`actvtnCode` TEXT    NULL,
-- 	`recovrCode` TEXT    NULL,
-- 	`codeExpiry` TEXT    NULL,
-- 	`signupDate` TEXT    NULL,
-- 	`bearerHash` TEXT    NULL,
-- 	`twoFactKey` TEXT    NULL,
-- 	`twoFactPos` INTEGER NULL,
-- 	`twoFactWay` TEXT    NULL,
-- 	`twoFactHsh` TEXT    NULL,
-- 	`twoFactExp` TEXT    NULL,
-- 	`failsCount` INTEGER NOT NULL DEFAULT 0,
-- 	`failedTime` TEXT    NULL,
-- 	`createDate` TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
-- 	`accessDate` TEXT    NULL,
-- 	UNIQUE (`systemName`),
-- 	UNIQUE (`bearerHash`),
-- 	UNIQUE (`contactEml`),
-- 	CHECK (`accessRole` IN ('standard', 'advanced', 'delegate', 'managers')),
-- 	CHECK (`userStatus` IN ('awaiting', 'inReview', 'approved', 'disabled')),
-- 	CHECK (`twoFactWay` IN ('emailOtp'))
-- );
-- INSERT INTO `users_1_0` (`id_user`, `systemName`, `passwdHash`, `passwdDate`, `accessRole`, `userStatus`, `contactEml`, `userLocale`, `actvtnCode`, `recovrCode`, `codeExpiry`, `signupDate`, `bearerHash`, `twoFactKey`, `twoFactPos`, `twoFactWay`, `twoFactHsh`, `twoFactExp`, `failsCount`, `failedTime`, `createDate`, `accessDate`)
-- 	SELECT `id_user`, `userHandle`, `passwdHash`, `passwdDate`, CASE `accessRole` WHEN 'internal' THEN 'standard' WHEN 'operator' THEN 'advanced' WHEN 'sysadmin' THEN 'managers' ELSE `accessRole` END, CASE `userStatus` WHEN 'checking' THEN 'inReview' ELSE `userStatus` END, `mailboxAdr`, `userLocale`, `actvtnCode`, `recovrCode`, `codeExpiry`, `signupDate`, `bearerHash`, `twoFactKey`, `twoFactStp`, `twoFactWay`, `mailedCode`, `mailExpiry`, `failsCount`, `failedDate`, `createDate`, `accessDate` FROM `users`;
-- DROP TABLE `users`;
-- ALTER TABLE `users_1_0` RENAME TO `users`;
-- COMMIT;
-- PRAGMA foreign_keys = ON;

