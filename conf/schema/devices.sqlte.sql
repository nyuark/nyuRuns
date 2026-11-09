-- Devices (Access\Auth\Device), SQLite: the machines allowed in with a token, each in the name of a user.
-- The table name is the 'tblnme' value of the device block in conf/access.json, the users table its 'usrtbl' value.
-- Created by php nyuRun installs userBase, after the users table, when conf/access.json holds a device block.
-- The removal of a user takes its devices along: the framework's SQLite handler turns the foreign keys on.
-- madeDate is given by the clock of the database, in UTC, the other dates by the one of PHP.

CREATE TABLE `devices` (
	`id_device` INTEGER PRIMARY KEY,
	-- Name the user gives the device; one name per device still standing
	`devcName`  TEXT    NOT NULL,
	-- Role of the device, as the application understands it: a word of 8 characters at most
	`devcRole`  TEXT    NOT NULL,
	-- SHA-256 hash of the device's token
	`tokenKey`  TEXT    NOT NULL,
	-- The user the device works for: its devices are removed with it
	`user_id`   INTEGER NOT NULL,
	-- Last authenticated request
	`lastSeen`  TEXT    NULL,
	-- Time of the revocation: a revoked device is kept
	`revkDate`  TEXT    NULL,
	`madeDate`  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE (`tokenKey`),
	FOREIGN KEY (`user_id`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
);
