-- Native userbase (Access\Auth\Native), SQLite.
-- The table name is the 'useTable' value of the strategy's config block in conf/access.json.
-- userHandle is matched with case: 'Anna' and 'anna' are two users.
-- createDate is given by the clock of the database, in UTC, the other dates by the one of PHP.

CREATE TABLE `users` (
	`id_user`    INTEGER PRIMARY KEY,
	-- Login name
	`userHandle` TEXT    NOT NULL,
	-- bcrypt hash, as given by password_hash()
	`passwdHash` TEXT    NOT NULL,
	-- Time the password was last set: the sessions and tokens issued before it are refused
	`passwdDate` TEXT    NULL,
	-- member, system or manage
	`accessRole` TEXT    NOT NULL DEFAULT 'member',
	-- pendng (awaiting activation), review (signed up, awaiting a manager), active or locked
	`userStatus` TEXT    NOT NULL DEFAULT 'pendng',
	-- Mail address, in lower case; one account each
	`mailboxAdr` TEXT    NULL,
	-- Language of the mails sent to the user, a tag in lower case ('fr', 'fr-ch'); the default one when empty
	`userLocale` TEXT    NULL,
	-- SHA-256 hash of the activation code, cleared once used
	`actvtnCode` TEXT    NULL,
	-- SHA-256 hash of a reset code asked by the user; its password works until the reset is done
	`recovrCode` TEXT    NULL,
	-- End of the activation or reset code (no end when empty)
	`codeExpiry` TEXT    NULL,
	-- Time of a self-registration, cleared once a manager approves it
	`signupDate` TEXT    NULL,
	-- SHA-256 hash of the user's personal API token
	`bearerHash` TEXT    NULL,
	-- 2FA secret; a user holding one must give its code
	`twoFactKey` TEXT    NULL,
	-- Step of the last 2FA code accepted: neither it nor an earlier one is accepted again
	`twoFactStp` INTEGER NULL,
	-- Second factor sent by mail ('mailed'); an authenticator when empty and twoFactKey is set
	`twoFactWay` TEXT    NULL,
	-- SHA-256 hash of the code mailed for a login, and its end; cleared once used
	`mailedCode` TEXT    NULL,
	`mailExpiry` TEXT    NULL,
	-- Failed attempts of the current window, and time of the last one
	`failsCount` INTEGER NOT NULL DEFAULT 0,
	`failedDate` TEXT    NULL,
	`createDate` TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	-- Last authenticated request; a user seen within the idle time is online
	`accessDate` TEXT    NULL,
	UNIQUE (`userHandle`),
	UNIQUE (`bearerHash`),
	UNIQUE (`mailboxAdr`),
	CHECK (`accessRole` IN ('member', 'system', 'manage')),
	CHECK (`userStatus` IN ('pendng', 'review', 'active', 'locked')),
	CHECK (`twoFactWay` IN ('mailed'))
);
