-- Native userbase (Access\Auth\Native), MySQL.
-- The table name is the 'useTable' value of the strategy's config block in conf/access.json.
-- With the default collation, userHandle is matched without case: 'Anna' and 'anna' are the same user.
-- createDate is given by the clock of the database, the other dates by the one of PHP.

CREATE TABLE `users` (
	`id_user`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
	-- Login name
	`userHandle` VARCHAR(64)  NOT NULL,
	-- bcrypt hash, as given by password_hash()
	`passwdHash` VARCHAR(255) NOT NULL,
	-- Time the password was last set: the sessions and tokens issued before it are refused
	`passwdDate` DATETIME     NULL,
	-- member, system or manage
	`accessRole` VARCHAR(6)   NOT NULL DEFAULT 'member',
	-- pendng (awaiting activation), review (signed up, awaiting a manager), active or locked
	`userStatus` VARCHAR(6)   NOT NULL DEFAULT 'pendng',
	-- Mail address, in lower case; one account each
	`mailboxAdr` VARCHAR(254) NULL,
	-- Language of the mails sent to the user, a tag in lower case ('fr', 'fr-ch'); the default one when empty
	`userLocale` VARCHAR(16)  NULL,
	-- SHA-256 hash of the activation code, cleared once used
	`actvtnCode` VARCHAR(64)  NULL,
	-- SHA-256 hash of a reset code asked by the user; its password works until the reset is done
	`recovrCode` VARCHAR(64)  NULL,
	-- End of the activation or reset code (no end when empty)
	`codeExpiry` DATETIME     NULL,
	-- Time of a self-registration, cleared once a manager approves it
	`signupDate` DATETIME     NULL,
	-- SHA-256 hash of the user's personal API token
	`bearerHash` CHAR(64)     NULL,
	-- 2FA secret; a user holding one must give its code
	`twoFactKey` VARCHAR(128) NULL,
	-- Step of the last 2FA code accepted: neither it nor an earlier one is accepted again
	`twoFactStp` INT UNSIGNED NULL,
	-- Second factor sent by mail ('mailed'); an authenticator when empty and twoFactKey is set
	`twoFactWay` CHAR(6)      NULL,
	-- SHA-256 hash of the code mailed for a login, and its end; cleared once used
	`mailedCode` CHAR(64)     NULL,
	`mailExpiry` DATETIME     NULL,
	-- Failed attempts of the current window, and time of the last one
	`failsCount` INT UNSIGNED NOT NULL DEFAULT 0,
	`failedDate` DATETIME     NULL,
	`createDate` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	-- Last authenticated request; a user seen within the idle time is online
	`accessDate` DATETIME     NULL,
	PRIMARY KEY (`id_user`),
	UNIQUE KEY `userHandle` (`userHandle`),
	UNIQUE KEY `bearerHash` (`bearerHash`),
	UNIQUE KEY `mailboxAdr` (`mailboxAdr`),
	CHECK (`accessRole` IN ('member', 'system', 'manage')),
	CHECK (`userStatus` IN ('pendng', 'review', 'active', 'locked')),
	CHECK (`twoFactWay` IN ('mailed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
