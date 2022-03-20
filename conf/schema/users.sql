-- Native userbase (Access\Auth\Native), MySQL.
-- The table name is the 'tblnme' value of the strategy's config block in conf/access.json.

CREATE TABLE `users` (
	`id_user`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
	-- Login name
	`userName` VARCHAR(64)  NOT NULL,
	-- bcrypt hash, as given by password_hash()
	`passHash` VARCHAR(255) NOT NULL,
	-- membr, systm or admin
	`userRole` VARCHAR(5)   NOT NULL DEFAULT 'membr',
	-- pendng (awaiting activation), active or locked
	`userStat` VARCHAR(6)   NOT NULL DEFAULT 'pendng',
	-- Activation code, cleared once used
	`actvCode` VARCHAR(64)  NULL,
	-- SHA-256 hash of the user's personal API token
	`tokenKey` CHAR(64)     NULL,
	-- 2FA secret (reserved)
	`twoFaKey` VARCHAR(64)  NULL,
	-- Failed attempts of the current window, and time of the last one
	`failNmbr` INT UNSIGNED NOT NULL DEFAULT 0,
	`failDate` DATETIME     NULL,
	`madeDate` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	-- Last authenticated request; a user seen within the idle time is online
	`lastSeen` DATETIME     NULL,
	PRIMARY KEY (`id_user`),
	UNIQUE KEY `userName` (`userName`),
	UNIQUE KEY `tokenKey` (`tokenKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
