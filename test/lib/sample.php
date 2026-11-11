<?php

// Sample data shared by the data and http tests: a small `shows` database
// with its `events` and `items` child tables.
//
//   show 1  Alpha Night    two events across a year end, two items
//   show 2  Beta Evening   two events in one month, two items
//   show 3  Alpha Matinee  two events, two items
//   show 4  Solo Gig       exactly one event and one item
//   show 5  Empty Stage    no venue (NULL), no events, no items
//
// and a `users` table for the native userbase (conf/schema/users.sqlitedb.sql), password pw-<name> :
//
//   user 1  anna   manage  active
//   user 2  ben    member  active
//   user 3  cleo   member  pendng
//   user 4  dave   system  locked
//
// and an empty `devices` table (conf/schema/devices.sqlitedb.sql), each device linked to a user.

/**
 * Creates the sample SQLite database, replacing any previous file.
 *
 * @param String $sqliteDbPath Path of the SQLite file to create.
 */
function createSampleDb( String $sqliteDbPath ): Void {
	if( file_exists($sqliteDbPath) ){
		unlink($sqliteDbPath);
	}
	$pdoLink = new PDO('sqlite:'.$sqliteDbPath);
	$pdoLink->exec("CREATE TABLE shows (id_show INTEGER PRIMARY KEY, title TEXT, venue TEXT)");
	$pdoLink->exec("CREATE TABLE events (id_event INTEGER PRIMARY KEY, show_id INTEGER, date TEXT, time TEXT)");
	$pdoLink->exec("CREATE TABLE items (id_item INTEGER PRIMARY KEY, show_id INTEGER, type TEXT)");
	$pdoLink->exec("INSERT INTO shows VALUES (1,'Alpha Night','Hall A'),(2,'Beta Evening','Hall B'),(3,'Alpha Matinee','Hall C'),(4,'Solo Gig','Hall S'),(5,'Empty Stage',NULL)");
	$pdoLink->exec("INSERT INTO events VALUES (1,1,'2026-12-28','20:00'),(2,1,'2027-01-03','20:00'),(3,2,'2026-11-02','19:00'),(4,2,'2026-11-09','19:00'),(5,3,'2026-10-05','14:00'),(6,3,'2026-10-06','14:00'),(7,4,'2026-12-01','21:00')");
	$pdoLink->exec("INSERT INTO items VALUES (1,1,'music'),(2,1,'dance'),(3,2,'theatre'),(4,2,'music'),(5,3,'dance'),(6,3,'circus'),(7,4,'solo')");
	// The users table is the one the framework delivers
	$pdoLink->exec( strval(file_get_contents(__DIR__.'/../../conf/schema/users.sqlitedb.sql')) );
	$userSql = $pdoLink->prepare("INSERT INTO users (id_user, systemName, passwdHash, accessRole, userStatus, createDate) VALUES (?,?,?,?,?,'2026-10-01 09:00:00')");
	foreach( Array(Array(1,'anna','sysadmin','approved'), Array(2,'ben','internal','approved'), Array(3,'cleo','internal','awaiting'), Array(4,'dave','operator','disabled')) as $usrRow ){
		// Passwords are pw-<name>, hashed at the lowest cost to keep the tests fast
		$userSql->execute( Array($usrRow[0], $usrRow[1], password_hash('pw-'.$usrRow[1], PASSWORD_BCRYPT, Array('cost'=>4)), $usrRow[2], $usrRow[3]) );
	}
	// The devices table is the one the framework delivers
	$pdoLink->exec( strval(file_get_contents(__DIR__.'/../../conf/schema/devices.sqlitedb.sql')) );
	$userSql = null;
	$pdoLink = null;
}

/**
 * Points the framework's database configuration at a SQLite file, through
 * the framework's SQLite handler.
 *
 * @param String $sqliteDbPath Path of the SQLite file to use.
 */
function switchToSqlite( String $sqliteDbPath ): Void {
	$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
		'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>$sqliteDbPath)
	) );
}
