-- Devices (Access\Auth\Device), SQLite: the machines allowed in with a token, each in the name of a user.
-- The table name is the 'useTable' value of the device block in conf/access.json, the users table its 'usersTbl' value.
-- Created by php nyuRun install userBase, after the users table, when conf/access.json holds a device block.
-- The removal of a user takes its devices along: the framework's SQLite handler turns the foreign keys on.
-- createDate is given by the clock of the database, in UTC, the other dates by the one of PHP.

CREATE TABLE `devices` (
	`id_device`  INTEGER PRIMARY KEY,
	-- Name the user gives the device; one name per device still standing
	`deviceName` TEXT    NOT NULL,
	-- Role of the device, as the application understands it: a word of 8 characters at most
	`deviceRole` TEXT    NOT NULL,
	-- Highest role the device acts with: the lower of it and its user's role applies; empty only for a device made before it, which acts with its user's role
	`deviceTops` TEXT    NULL CHECK (`deviceTops` IS NULL OR `deviceTops` IN ('standard', 'advanced', 'delegate', 'managers')),
	-- SHA-256 hash of the device's token
	`bearerHash` TEXT    NOT NULL,
	-- The user the device works for: its devices are removed with it
	`user_id`    INTEGER NOT NULL,
	-- Last authenticated request
	`accessDate` TEXT    NULL,
	-- Time of the revocation: a revoked device is kept
	`revokeDate` TEXT    NULL,
	`createDate` TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE (`bearerHash`),
	FOREIGN KEY (`user_id`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
);
