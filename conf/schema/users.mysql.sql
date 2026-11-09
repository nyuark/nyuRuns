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
	-- Time the password was last set: the sessions and tokens issued before it are refused
	`passDate` DATETIME     NULL,
	-- member, system or manage
	`userRole` VARCHAR(6)   NOT NULL DEFAULT 'member',
	-- pendng (awaiting activation), review (signed up, awaiting a manager), active or locked
	`userStat` VARCHAR(6)   NOT NULL DEFAULT 'pendng',
	-- Mail address, in lower case; one account each
	`userMail` VARCHAR(254) NULL,
	-- Language of the mails sent to the user, a tag in lower case ('fr', 'fr-ch'); the default one when empty
	`userLang` VARCHAR(16)  NULL,
	-- SHA-256 hash of the activation code, cleared once used
	`actvCode` VARCHAR(64)  NULL,
	-- SHA-256 hash of a reset code asked by the user; its password works until the reset is done
	`resetCod` VARCHAR(64)  NULL,
	-- End of the activation or reset code (no end when empty)
	`codeDate` DATETIME     NULL,
	-- Time of a self-registration, cleared once a manager approves it
	`signDate` DATETIME     NULL,
	-- SHA-256 hash of the user's personal API token
	`tokenKey` CHAR(64)     NULL,
	-- 2FA secret; a user holding one must give its code
	`twoFaKey` VARCHAR(128) NULL,
	-- Step of the last 2FA code accepted: neither it nor an earlier one is accepted again
	`twoFaStp` INT UNSIGNED NULL,
	-- Second factor sent by mail ('mailed'); an authenticator when empty and twoFaKey is set
	`twoFaWay` CHAR(6)      NULL,
	-- SHA-256 hash of the code mailed for a login, and its end; cleared once used
	`mailCode` CHAR(64)     NULL,
	`mailDate` DATETIME     NULL,
	-- Failed attempts of the current window, and time of the last one
	`failNmbr` INT UNSIGNED NOT NULL DEFAULT 0,
	`failDate` DATETIME     NULL,
	`madeDate` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	-- Last authenticated request; a user seen within the idle time is online
	`lastSeen` DATETIME     NULL,
	PRIMARY KEY (`id_user`),
	UNIQUE KEY `userName` (`userName`),
	UNIQUE KEY `tokenKey` (`tokenKey`),
	UNIQUE KEY `userMail` (`userMail`),
	CHECK (`userRole` IN ('member', 'system', 'manage')),
	CHECK (`userStat` IN ('pendng', 'review', 'active', 'locked')),
	CHECK (`twoFaWay` IN ('mailed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
