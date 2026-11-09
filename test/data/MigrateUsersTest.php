<?php

// conf/schema/migrate-users-1.0.sqlte.sql : a users table of 0.9, with a
// device, made again as 1.0 makes it; the users and their devices stay.

require __DIR__.'/../lib/harness.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('migration of the users table', 'the pdo_sqlite extension is not loaded');
	endChecks();
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

$dbsFile = inTempDir('migrate.sqlite');
$sqlLink = new PDO('sqlite:'.$dbsFile);
$sqlLink->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlLink->exec('PRAGMA foreign_keys = ON;');
$sqlLink->exec($oldSchm);
$sqlLink->exec( strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'devices.sqlte.sql')) );
$sqlLink->exec("INSERT INTO users (id_user, userName, passHash, userRole, userStat, actvCode, tokenKey, twoFaKey, twoFaStp, failNmbr, madeDate) VALUES
	(3, 'anna', 'hash-a', 'manage', 'active', NULL, 'tok-a', 'key-a', 41, 2, '2026-01-02 03:04:05'),
	(7, 'cleo', '!', 'member', 'pendng', 'code-c', NULL, NULL, NULL, 0, '2026-02-03 04:05:06')");
$sqlLink->exec("INSERT INTO devices (devcName, devcRole, tokenKey, user_id) VALUES ('phone', 'reader', 'dev-tok', 3)");
$oldRows = $sqlLink->query('SELECT * FROM users ORDER BY id_user')->fetchAll(PDO::FETCH_ASSOC);
checkThrw('0.9 refuses the status review', function() use ( $sqlLink ){ $sqlLink->exec("UPDATE users SET userStat='review' WHERE id_user=7"); }, PDOException::class);

$sqlLink->exec( strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'migrate-users-1.0.sqlte.sql')) );

$newCols = array_column( $sqlLink->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name' );
$freshDb = new PDO('sqlite::memory:');
$freshDb->exec( strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql')) );
checkSame('the columns are those of the 1.0 schema, in its order', $newCols, array_column( $freshDb->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name' ));
$newRows = $sqlLink->query('SELECT * FROM users ORDER BY id_user')->fetchAll(PDO::FETCH_ASSOC);
$keptRow = array_map(function( Array $userValues ): Array { return array_diff_key($userValues, array_flip(Array('userMail', 'userLang', 'resetCod', 'codeDate', 'signDate', 'twoFaWay', 'mailCode', 'mailDate'))); }, $newRows);
checkSame('every user is kept, with its identifier and its values', $keptRow, $oldRows);
checkSame('the new columns are empty', array_unique(array_merge(...array_map(function( Array $userValues ): Array { return Array($userValues['userMail'], $userValues['userLang'], $userValues['resetCod'], $userValues['codeDate'], $userValues['signDate'], $userValues['twoFaWay'], $userValues['mailCode'], $userValues['mailDate']); }, $newRows))), Array(null));
checkSame('the device is still there', $sqlLink->query('SELECT devcName, user_id FROM devices')->fetchAll(PDO::FETCH_ASSOC), Array(Array('devcName'=>'phone', 'user_id'=>3)));
checkSame('the foreign keys are on again', intval($sqlLink->query('PRAGMA foreign_keys')->fetchColumn()), 1);
checkSame('no table is left over', $sqlLink->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN), Array('devices', 'users'));

$sqlLink->exec("UPDATE users SET userStat='review', userMail='cleo@example.ch' WHERE id_user=7");
checkSame('1.0 takes the status review', $sqlLink->query('SELECT userStat FROM users WHERE id_user=7')->fetchColumn(), 'review');
checkThrw('an address is one account\'s', function() use ( $sqlLink ){ $sqlLink->exec("UPDATE users SET userMail='cleo@example.ch' WHERE id_user=3"); }, PDOException::class);
checkThrw('an unknown status is still refused', function() use ( $sqlLink ){ $sqlLink->exec("UPDATE users SET userStat='online' WHERE id_user=3"); }, PDOException::class);
$sqlLink->exec('DELETE FROM users WHERE id_user=3');
checkSame('removing a user still takes its devices along', intval($sqlLink->query('SELECT COUNT(*) FROM devices')->fetchColumn()), 0);

$sqlLink = null;
$freshDb = null;
dropFoldr(inTempDir(''));
endChecks();
