<?php

// Sample data shared by the data and http tests: a small `shows` database
// with its `events` and `items` child tables.
//
//   show 1  Alpha Night    two events across a year end, two items
//   show 2  Beta Evening   two events in one month, two items
//   show 3  Alpha Matinee  two events, two items
//   show 4  Solo Gig       exactly one event and one item
//   show 5  Empty Stage    no venue (NULL), no events, no items

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
	$pdoLink = null;
}

/**
 * Points the framework's database configuration at a SQLite file, through
 * the test-only Lite handler.
 *
 * @param String $sqliteFile Path of the SQLite file to use.
 */
function useSqlite( String $sqliteFile ): Void {
	require_once __DIR__.DIRECTORY_SEPARATOR.'LiteHandler.php';
	$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
		'database'=>Array('TYPE'=>'Lite', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>$sqliteFile)
	) );
}
