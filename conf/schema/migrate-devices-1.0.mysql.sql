-- Devices table, from 0.9 to 1.0, MySQL 8.0 or MariaDB 10.5 at least.
-- On MySQL 5.7, which has no RENAME COLUMN, use the variant
-- written below it instead: comment the first ALTER TABLE out, and the variant in.
-- For a devices table made by conf/schema/devices.mysql.sql of 0.9: its columns
-- take their names of 1.0 (10 characters). Run it after migrate-users-1.0.mysql.sql.
--
-- First a backup of the table:
--   mysqldump -u <user> -p <database> devices > devices-0.9.sql
-- Then this file, on the database of the application:
--   mysql -u <user> -p <database> < migrate-devices-1.0.mysql.sql
-- The table name is the 'useTable' value of the device block of
-- conf/access.json: change `devices` below when it is another.

-- The columns and the unique key, renamed. No row is changed.

ALTER TABLE `devices`
	RENAME COLUMN `devcName` TO `deviceName`,
	RENAME COLUMN `devcRole` TO `deviceRole`,
	RENAME COLUMN `tokenKey` TO `bearerHash`,
	RENAME COLUMN `lastSeen` TO `accessDate`,
	RENAME COLUMN `revkDate` TO `revokeDate`,
	RENAME COLUMN `madeDate` TO `createDate`,
	RENAME INDEX  `tokenKey` TO `bearerHash`;

-- MySQL 5.7. Each column is named again with its whole definition, the one of
-- the 0.9 schema; no row is changed.
--
-- ALTER TABLE `devices`
-- 	CHANGE COLUMN `devcName` `deviceName` VARCHAR(64)  NOT NULL,
-- 	CHANGE COLUMN `devcRole` `deviceRole` VARCHAR(8)   NOT NULL,
-- 	CHANGE COLUMN `tokenKey` `bearerHash` CHAR(64)     NOT NULL,
-- 	CHANGE COLUMN `lastSeen` `accessDate` DATETIME     NULL,
-- 	CHANGE COLUMN `revkDate` `revokeDate` DATETIME     NULL,
-- 	CHANGE COLUMN `madeDate` `createDate` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
-- 	RENAME INDEX  `tokenKey` TO `bearerHash`;


-- Going back to 0.9, if needed:
--
-- ALTER TABLE `devices`
-- 	RENAME COLUMN `deviceName` TO `devcName`,
-- 	RENAME COLUMN `deviceRole` TO `devcRole`,
-- 	RENAME COLUMN `bearerHash` TO `tokenKey`,
-- 	RENAME COLUMN `accessDate` TO `lastSeen`,
-- 	RENAME COLUMN `revokeDate` TO `revkDate`,
-- 	RENAME COLUMN `createDate` TO `madeDate`,
-- 	RENAME INDEX  `bearerHash` TO `tokenKey`;
