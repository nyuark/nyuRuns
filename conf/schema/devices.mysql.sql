-- Devices (Access\Auth\Device), MySQL: the machines allowed in with a token, each in the name of a user.
-- The table name is the 'useTable' value of the device block in conf/access.json, the users table its 'usersTbl' value.
-- Created by php nyuRun installs userBase, after the users table, when conf/access.json holds a device block.
-- madeDate is given by the clock of the database, the other dates by the one of PHP.

CREATE TABLE `devices` (
	`id_device` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	-- Name the user gives the device; one name per device still standing
	`devcName`  VARCHAR(64)  NOT NULL,
	-- Role of the device, as the application understands it: a word of 8 characters at most
	`devcRole`  VARCHAR(8)   NOT NULL,
	-- SHA-256 hash of the device's token
	`tokenKey`  CHAR(64)     NOT NULL,
	-- The user the device works for: its devices are removed with it
	`user_id`   INT UNSIGNED NOT NULL,
	-- Last authenticated request
	`lastSeen`  DATETIME     NULL,
	-- Time of the revocation: a revoked device is kept
	`revkDate`  DATETIME     NULL,
	`madeDate`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id_device`),
	UNIQUE KEY `tokenKey` (`tokenKey`),
	KEY `user_id` (`user_id`),
	FOREIGN KEY (`user_id`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
