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
// and a `users` table for the native userbase, password pw-<name> :
//
//   user 1  anna   manage  active
//   user 2  ben    member  active
//   user 3  cleo   member  pendng
//   user 4  dave   system  locked
//
// and an empty `devices` table, each device being linked to a user.

/**
 * Creates the sample SQLite database, replacing any previous file.
 *
 * @param String $sqliteFile Path of the SQLite file to create.
 */
function buildData( String $sqliteFile ): Void {
	if( file_exists($sqliteFile) ){
		unlink($sqliteFile);
	}
	$pdoLink = new PDO('sqlite:'.$sqliteFile);
	$pdoLink->exec("CREATE TABLE shows (id_show INTEGER PRIMARY KEY, title TEXT, venue TEXT)");
	$pdoLink->exec("CREATE TABLE events (id_event INTEGER PRIMARY KEY, show_id INTEGER, date TEXT, time TEXT)");
	$pdoLink->exec("CREATE TABLE items (id_item INTEGER PRIMARY KEY, show_id INTEGER, type TEXT)");
	$pdoLink->exec("INSERT INTO shows VALUES (1,'Alpha Night','Hall A'),(2,'Beta Evening','Hall B'),(3,'Alpha Matinee','Hall C'),(4,'Solo Gig','Hall S'),(5,'Empty Stage',NULL)");
	$pdoLink->exec("INSERT INTO events VALUES (1,1,'2026-12-28','20:00'),(2,1,'2027-01-03','20:00'),(3,2,'2026-11-02','19:00'),(4,2,'2026-11-09','19:00'),(5,3,'2026-10-05','14:00'),(6,3,'2026-10-06','14:00'),(7,4,'2026-12-01','21:00')");
	$pdoLink->exec("INSERT INTO items VALUES (1,1,'music'),(2,1,'dance'),(3,2,'theatre'),(4,2,'music'),(5,3,'dance'),(6,3,'circus'),(7,4,'solo')");
	$pdoLink->exec("CREATE TABLE users (id_user INTEGER PRIMARY KEY, userName TEXT UNIQUE, passHash TEXT, userRole TEXT, userStat TEXT, actvCode TEXT, tokenKey TEXT, twoFaKey TEXT, twoFaStp INTEGER, failNmbr INTEGER DEFAULT 0, failDate TEXT, madeDate TEXT, lastSeen TEXT)");
	$usrStmt = $pdoLink->prepare("INSERT INTO users (id_user, userName, passHash, userRole, userStat, madeDate) VALUES (?,?,?,?,?,'2026-10-01 09:00:00')");
	foreach( Array(Array(1,'anna','manage','active'), Array(2,'ben','member','active'), Array(3,'cleo','member','pendng'), Array(4,'dave','system','locked')) as $usrRow ){
		// Passwords are pw-<name>, hashed at the lowest cost to keep the tests fast
		$usrStmt->execute( Array($usrRow[0], $usrRow[1], password_hash('pw-'.$usrRow[1], PASSWORD_BCRYPT, Array('cost'=>4)), $usrRow[2], $usrRow[3]) );
	}
	$pdoLink->exec("CREATE TABLE devices (id INTEGER PRIMARY KEY, name TEXT, role TEXT, token_hash TEXT UNIQUE, user_id INTEGER, last_seen_at TEXT, revoked_at TEXT, created_at TEXT)");
	$usrStmt = null;
	$pdoLink = null;
}

/**
 * Points the framework's database configuration at a SQLite file, through
 * the framework's SQLite handler.
 *
 * @param String $sqliteFile Path of the SQLite file to use.
 */
function useSqlite( String $sqliteFile ): Void {
	$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
		'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>$sqliteFile)
	) );
}
