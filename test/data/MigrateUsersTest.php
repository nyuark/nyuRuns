<?php

// conf/schema/migrate-users-1.0.sqlitedb.sql, then migrate-devices-1.0.sqlitedb.sql :
// a users table of 0.9, with a device, made again as 1.0 makes it, its
// columns renamed; the users and their devices stay.

require __DIR__.'/../lib/harness.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('migration of the users table', 'the pdo_sqlite extension is not loaded');
	finishChecking();
}

// The users table as conf/schema/users.sqlte.sql of 0.9 made it
$oldSchm = <<<'SQL'
CREATE TABLE `users` (
	`id_user`  INTEGER PRIMARY KEY,
	`userName` TEXT    NOT NULL,
	`passHash` TEXT    NOT NULL,
	`userRole` TEXT    NOT NULL DEFAULT 'member',
	`userStat` TEXT    NOT NULL DEFAULT 'pendng',
	`actvCode` TEXT    NULL,
	`tokenKey` TEXT    NULL,
	`twoFaKey` TEXT    NULL,
	`twoFaStp` INTEGER NULL,
	`failNmbr` INTEGER NOT NULL DEFAULT 0,
	`failDate` TEXT    NULL,
	`madeDate` TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`lastSeen` TEXT    NULL,
	UNIQUE (`userName`),
	UNIQUE (`tokenKey`),
	CHECK (`userRole` IN ('member', 'system', 'manage')),
	CHECK (`userStat` IN ('pendng', 'active', 'locked'))
);
SQL;

$dbsFile = inTemporaryDir('migrate.sqlite');
$sqlLink = new PDO('sqlite:'.$dbsFile);
$sqlLink->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlLink->exec('PRAGMA foreign_keys = ON;');
$sqlLink->exec($oldSchm);
// The devices table as conf/schema/devices.sqlte.sql of 0.9 made it
$sqlLink->exec("CREATE TABLE `devices` (`id_device` INTEGER PRIMARY KEY, `devcName` TEXT NOT NULL, `devcRole` TEXT NOT NULL, `tokenKey` TEXT NOT NULL, `user_id` INTEGER NOT NULL, `lastSeen` TEXT NULL, `revkDate` TEXT NULL, `madeDate` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (`tokenKey`), FOREIGN KEY (`user_id`) REFERENCES `users` (`id_user`) ON DELETE CASCADE)");
$sqlLink->exec("INSERT INTO users (id_user, userName, passHash, userRole, userStat, actvCode, tokenKey, twoFaKey, twoFaStp, failNmbr, madeDate) VALUES
	(3, 'anna', 'hash-a', 'manage', 'active', NULL, 'tok-a', 'key-a', 41, 2, '2026-01-02 03:04:05'),
	(7, 'cleo', '!', 'member', 'pendng', 'code-c', NULL, NULL, NULL, 0, '2026-02-03 04:05:06')");
$sqlLink->exec("INSERT INTO devices (devcName, devcRole, tokenKey, user_id) VALUES ('phone', 'reader', 'dev-tok', 3)");
$oldRows = $sqlLink->query('SELECT * FROM users ORDER BY id_user')->fetchAll(PDO::FETCH_ASSOC);
checkForThrows('0.9 refuses the status review', function() use ( $sqlLink ){ $sqlLink->exec("UPDATE users SET userStat='review' WHERE id_user=7"); }, PDOException::class);

$sqlLink->exec( strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'migrate-users-1.0.sqlitedb.sql')) );
$sqlLink->exec( strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'migrate-devices-1.0.sqlitedb.sql')) );

$newCols = array_column( $sqlLink->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name' );
$freshDb = new PDO('sqlite::memory:');
$freshDb->exec( strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlitedb.sql')) );
checkIdentical('the columns are those of the 1.0 schema, in its order', $newCols, array_column( $freshDb->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name' ));
$newRows = $sqlLink->query('SELECT * FROM users ORDER BY id_user')->fetchAll(PDO::FETCH_ASSOC);
// The values of 0.9 under the names of 1.0
$colMap = Array('userName'=>'systemName', 'passHash'=>'passwdHash', 'userRole'=>'accessRole', 'userStat'=>'userStatus', 'actvCode'=>'actvtnCode', 'tokenKey'=>'bearerHash', 'twoFaKey'=>'twoFactKey', 'twoFaStp'=>'twoFactPos', 'failNmbr'=>'failsCount', 'failDate'=>'failedTime', 'madeDate'=>'createDate', 'lastSeen'=>'accessDate');
$oldRows = array_map(function( Array $userValues ) use ( $colMap ): Array { return array_combine(array_map(function( String $colName ) use ( $colMap ): String { return $colMap[$colName] ?? $colName; }, array_keys($userValues)), array_values($userValues)); }, $oldRows);
// and the codes of 1.0 for their roles and statuses
$codeMap = Array('member'=>'internal', 'system'=>'operator', 'manage'=>'sysadmin', 'pendng'=>'awaiting', 'active'=>'approved', 'locked'=>'disabled');
$oldRows = array_map(function( Array $userValues ) use ( $codeMap ): Array { return array_replace($userValues, Array('accessRole'=>$codeMap[$userValues['accessRole']], 'userStatus'=>$codeMap[$userValues['userStatus']])); }, $oldRows);
$keptRow = array_map(function( Array $userValues ): Array { return array_diff_key($userValues, array_flip(Array('contactEml', 'userLocale', 'recovrCode', 'codeExpiry', 'signupDate', 'twoFactWay', 'twoFactHsh', 'twoFactExp', 'passwdDate'))); }, $newRows);
checkIdentical('every user is kept, with its identifier and its values', $keptRow, $oldRows);
checkIdentical('the new columns are empty', array_unique(array_merge(...array_map(function( Array $userValues ): Array { return Array($userValues['contactEml'], $userValues['userLocale'], $userValues['recovrCode'], $userValues['codeExpiry'], $userValues['signupDate'], $userValues['twoFactWay'], $userValues['twoFactHsh'], $userValues['twoFactExp'], $userValues['passwdDate']); }, $newRows))), Array(null));
checkIdentical('the device is still there', $sqlLink->query('SELECT deviceName, user_id FROM devices')->fetchAll(PDO::FETCH_ASSOC), Array(Array('deviceName'=>'phone', 'user_id'=>3)));
checkIdentical('the foreign keys are on again', intval($sqlLink->query('PRAGMA foreign_keys')->fetchColumn()), 1);
checkIdentical('no table is left over', $sqlLink->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN), Array('devices', 'users'));

$sqlLink->exec("UPDATE users SET userStatus='inReview', contactEml='cleo@example.ch' WHERE id_user=7");
checkIdentical('1.0 takes the status inReview', $sqlLink->query('SELECT userStatus FROM users WHERE id_user=7')->fetchColumn(), 'inReview');
checkForThrows('an address is one account\'s', function() use ( $sqlLink ){ $sqlLink->exec("UPDATE users SET contactEml='cleo@example.ch' WHERE id_user=3"); }, PDOException::class);
checkForThrows('an unknown status is still refused', function() use ( $sqlLink ){ $sqlLink->exec("UPDATE users SET userStatus='online' WHERE id_user=3"); }, PDOException::class);
$sqlLink->exec('DELETE FROM users WHERE id_user=3');
checkIdentical('removing a user still takes its devices along', intval($sqlLink->query('SELECT COUNT(*) FROM devices')->fetchColumn()), 0);

$sqlLink = null;
$freshDb = null;
dropTestFolder(inTemporaryDir(''));
finishChecking();
