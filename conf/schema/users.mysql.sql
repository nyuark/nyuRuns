-- Native userbase (Access\Auth\Native), MySQL.
-- The table name is the 'tblnme' value of the strategy's config block in conf/access.json.
-- With the default collation, userName is matched without case: 'Anna' and 'anna' are the same user.
-- madeDate is given by the clock of the database, the other dates by the one of PHP.

CREATE TABLE `users` (
	`id_user`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
	-- Login name
	`userName` VARCHAR(64)  NOT NULL,
	-- bcrypt hash, as given by password_hash()
	`passHash` VARCHAR(255) NOT NULL,
	-- member, system or manage
	`userRole` VARCHAR(6)   NOT NULL DEFAULT 'member',
	-- pendng (awaiting activation), active or locked
	`userStat` VARCHAR(6)   NOT NULL DEFAULT 'pendng',
	-- Activation code, cleared once used
	`actvCode` VARCHAR(64)  NULL,
	-- SHA-256 hash of the user's personal API token
	`tokenKey` CHAR(64)     NULL,
	-- 2FA secret; a user holding one must give its code
	`twoFaKey` VARCHAR(128) NULL,
	-- Step of the last 2FA code accepted: neither it nor an earlier one is accepted again
	`twoFaStp` INT UNSIGNED NULL,
	-- Failed attempts of the current window, and time of the last one
	`failNmbr` INT UNSIGNED NOT NULL DEFAULT 0,
	`failDate` DATETIME     NULL,
	`madeDate` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	-- Last authenticated request; a user seen within the idle time is online
	`lastSeen` DATETIME     NULL,
	PRIMARY KEY (`id_user`),
	UNIQUE KEY `userName` (`userName`),
	UNIQUE KEY `tokenKey` (`tokenKey`),
	CHECK (`userRole` IN ('member', 'system', 'manage')),
	CHECK (`userStat` IN ('pendng', 'active', 'locked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
