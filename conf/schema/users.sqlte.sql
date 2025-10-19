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
	-- member, system or manage
	`userRole` TEXT    NOT NULL DEFAULT 'member',
	-- pendng (awaiting activation), active or locked
	`userStat` TEXT    NOT NULL DEFAULT 'pendng',
	-- Activation code, cleared once used
	`actvCode` TEXT    NULL,
	-- SHA-256 hash of the user's personal API token
	`tokenKey` TEXT    NULL,
	-- 2FA secret; a user holding one must give its code
	`twoFaKey` TEXT    NULL,
	-- Step of the last 2FA code accepted: neither it nor an earlier one is accepted again
	`twoFaStp` INTEGER NULL,
	-- Failed attempts of the current window, and time of the last one
	`failNmbr` INTEGER NOT NULL DEFAULT 0,
	`failDate` TEXT    NULL,
	`madeDate` TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	-- Last authenticated request; a user seen within the idle time is online
	`lastSeen` TEXT    NULL,
	UNIQUE (`userName`),
	UNIQUE (`tokenKey`),
	CHECK (`userRole` IN ('member', 'system', 'manage')),
	CHECK (`userStat` IN ('pendng', 'active', 'locked'))
);
