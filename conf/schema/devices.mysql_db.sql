-- Devices (Access\Auth\Device), MySQL: the machines allowed in with a token, each in the name of a user.
-- The table name is the 'useTable' value of the device block in conf/access.json, the users table its 'usersTbl' value.
-- Created by php nyuRun install userBase, after the users table, when conf/access.json holds a device block.
-- createDate is given by the clock of the database, the other dates by the one of PHP.

CREATE TABLE `devices` (
	`id_device`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
	-- Name the user gives the device; one name per device still standing
	`deviceName` VARCHAR(64)  NOT NULL,
	-- Role of the device, as the application understands it: a word of 8 characters at most
	`deviceRole` VARCHAR(8)   NOT NULL,
	-- SHA-256 hash of the device's token
	`bearerHash` CHAR(64)     NOT NULL,
	-- The user the device works for: its devices are removed with it
	`user_id`    INT UNSIGNED NOT NULL,
	-- Last authenticated request
	`accessDate` DATETIME     NULL,
	-- Time of the revocation: a revoked device is kept
	`revokeDate` DATETIME     NULL,
	`createDate` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id_device`),
	UNIQUE KEY `bearerHash` (`bearerHash`),
	KEY `user_id` (`user_id`),
	FOREIGN KEY (`user_id`) REFERENCES `users` (`id_user`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
