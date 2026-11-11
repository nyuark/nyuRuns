-- Native userbase (Access\Auth\Native), SQLite.
-- The table name is the 'useTable' value of the strategy's config block in conf/access.json.
-- systemName is matched with case: 'Anna' and 'anna' are two users.
-- createDate is given by the clock of the database, in UTC, the other dates by the one of PHP.

CREATE TABLE `users` (
	`id_user`    INTEGER PRIMARY KEY,
	-- Login name
	`systemName` TEXT    NOT NULL,
	-- bcrypt hash, as given by password_hash()
	`passwdHash` TEXT    NOT NULL,
	-- Time the password was last set: the sessions and tokens issued before it are refused
	`passwdDate` TEXT    NULL,
	-- standard, advanced, delegate or managers, in that order
	`accessRole` TEXT    NOT NULL DEFAULT 'standard',
	-- awaiting (its activation), inReview (signed up, a manager to approve it), approved or disabled
	`userStatus` TEXT    NOT NULL DEFAULT 'awaiting',
	-- Mail address, in lower case; one account each
	`contactEml` TEXT    NULL,
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
	`twoFactPos` INTEGER NULL,
	-- Second factor sent by mail ('emailOtp'); an authenticator when empty and twoFactKey is set
	`twoFactWay` TEXT    NULL,
	-- SHA-256 hash of the code mailed for a login, and its end; cleared once used
	`twoFactHsh` TEXT    NULL,
	`twoFactExp` TEXT    NULL,
	-- Failed attempts of the current window, and time of the last one
	`failsCount` INTEGER NOT NULL DEFAULT 0,
	`failedTime` TEXT    NULL,
	`createDate` TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	-- Last authenticated request; a user seen within the idle time is online
	`accessDate` TEXT    NULL,
	UNIQUE (`systemName`),
	UNIQUE (`bearerHash`),
	UNIQUE (`contactEml`),
	CHECK (`accessRole` IN ('standard', 'advanced', 'delegate', 'managers')),
	CHECK (`userStatus` IN ('awaiting', 'inReview', 'approved', 'disabled')),
	CHECK (`twoFactWay` IN ('emailOtp'))
);
