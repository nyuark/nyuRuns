-- Native userbase (Access\Auth\Native), SQLite.
-- The table name is the 'tblnme' value of the strategy's config block in conf/access.json.
-- userName is matched with case: 'Anna' and 'anna' are two users.
-- madeDate is given by the clock of the database, in UTC, the other dates by the one of PHP.

CREATE TABLE `users` (
	`id_user`  INTEGER PRIMARY KEY,
	-- Login name
	`userName` TEXT    NOT NULL,
	-- bcrypt hash, as given by password_hash()
	`passHash` TEXT    NOT NULL,
	-- Time the password was last set: the sessions and tokens issued before it are refused
	`passDate` TEXT    NULL,
	-- member, system or manage
	`userRole` TEXT    NOT NULL DEFAULT 'member',
	-- pendng (awaiting activation), review (signed up, awaiting a manager), active or locked
	`userStat` TEXT    NOT NULL DEFAULT 'pendng',
	-- Mail address, in lower case; one account each
	`userMail` TEXT    NULL,
	-- Language of the mails sent to the user, a tag in lower case ('fr', 'fr-ch'); the default one when empty
	`userLang` TEXT    NULL,
	-- SHA-256 hash of the activation code, cleared once used
	`actvCode` TEXT    NULL,
	-- SHA-256 hash of a reset code asked by the user; its password works until the reset is done
	`resetCod` TEXT    NULL,
	-- End of the activation or reset code (no end when empty)
	`codeDate` TEXT    NULL,
	-- Time of a self-registration, cleared once a manager approves it
	`signDate` TEXT    NULL,
	-- SHA-256 hash of the user's personal API token
	`tokenKey` TEXT    NULL,
	-- 2FA secret; a user holding one must give its code
	`twoFaKey` TEXT    NULL,
	-- Step of the last 2FA code accepted: neither it nor an earlier one is accepted again
	`twoFaStp` INTEGER NULL,
	-- Second factor sent by mail ('mailed'); an authenticator when empty and twoFaKey is set
	`twoFaWay` TEXT    NULL,
	-- SHA-256 hash of the code mailed for a login, and its end; cleared once used
	`mailCode` TEXT    NULL,
	`mailDate` TEXT    NULL,
	-- Failed attempts of the current window, and time of the last one
	`failNmbr` INTEGER NOT NULL DEFAULT 0,
	`failDate` TEXT    NULL,
	`madeDate` TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	-- Last authenticated request; a user seen within the idle time is online
	`lastSeen` TEXT    NULL,
	UNIQUE (`userName`),
	UNIQUE (`tokenKey`),
	UNIQUE (`userMail`),
	CHECK (`userRole` IN ('member', 'system', 'manage')),
	CHECK (`userStat` IN ('pendng', 'review', 'active', 'locked')),
	CHECK (`twoFaWay` IN ('mailed'))
);
