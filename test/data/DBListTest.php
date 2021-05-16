<?php

// Data\DBList : filtering, ordering, limiting, scopes, joins and child lists,
// against the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('DBList', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Data\DBList;

buildData( inTempDir('list.sqlite') );
useSqlite( inTempDir('list.sqlite') );

checkSame('readItems returns every row', runSafely(function(){
	$dbList = new DBList('shows');
	return array_column($dbList->readItems(), 'title');
}), Array('Alpha Night', 'Beta Evening', 'Alpha Matinee', 'Solo Gig', 'Empty Stage'));

checkSame('lstSearch with a wildcard, descending order', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('title'=>'Alpha*'));
	$dbList->listOrder('^id_show');
	return array_column($dbList->readItems(), 'id_show');
}), Array(3, 1));

checkSame('lstSearch negated wildcard', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('title!'=>'Alpha*'));
	return array_column($dbList->readItems(), 'id_show');
}), Array(2, 4, 5));

checkSame('lstSearch with a list of values, ordered and limited', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('id_show'=>Array(1, 2, 3)));
	$dbList->listOrder(Array('title'));
	$dbList->listLimit('2');
	return array_column($dbList->readItems(), 'title');
}), Array('Alpha Matinee', 'Alpha Night'));

checkSame('lstSearch excluding a list of values', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('id_show!'=>Array(1, 2, 3)));
	return array_column($dbList->readItems(), 'id_show');
}), Array(4, 5));

checkSame('lstSearch on two fields', runSafely(function(){
	$dbList = new DBList('events');
	$dbList->lstSearch(Array('show_id'=>'1', 'date>='=>'2027-01-01'));
	return array_column($dbList->readItems(), 'id_event');
}), Array(2));

checkSame('listLimit with an offset', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->listOrder('id_show');
	$dbList->listLimit('2,3');
	return array_column($dbList->readItems(), 'id_show');
}), Array(4, 5));

checkSame('flatItems skips NULL values', runSafely(function(){
	$dbList = new DBList('shows');
	return $dbList->flatItems('venue');
}), Array('Hall A', 'Hall B', 'Hall C', 'Hall S'));

checkSame('fieldScop with an expression, grouped', runSafely(function(){
	$dbList = new DBList('items');
	$dbList->fieldScop('type, count(*) as nb');
	$dbList->grpFields('type');
	$dbList->listOrder('type');
	return $dbList->readItems();
}), Array(
	Array('type'=>'circus', 'nb'=>1), Array('type'=>'dance', 'nb'=>2), Array('type'=>'music', 'nb'=>2),
	Array('type'=>'solo', 'nb'=>1), Array('type'=>'theatre', 'nb'=>1)
));

checkSame('tableJoin', runSafely(function(){
	$dbList = new DBList('events');
	$dbList->fieldScop('title');
	$dbList->tableJoin(Array('shows', 'show_id', 'id_show'));
	$dbList->lstSearch(Array('time'=>'19:00'));
	return $dbList->flatItems('title');
}), Array('Beta Evening', 'Beta Evening'));

checkSame('childList with a single field', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->readItems();
	return array_column($dbList->childList('items', 'type'), 'items');
}), Array(Array('music', 'dance'), Array('theatre', 'music'), Array('dance', 'circus'), Array('solo'), Array()));

checkSame('constructor with where and order clauses', runSafely(function(){
	$dbList = new DBList('events', null, "`show_id` = '1'", 'date DESC');
	return array_column($dbList->readItems(), 'date');
}), Array('2027-01-03', '2026-12-28'));

checkSame('no match gives an empty list', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('title'=>'Zzz*'));
	return $dbList->readItems();
}), Array());

// A single matching row must still come back as a list of rows
checkSame('one row is a list of one row', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('title'=>'Beta*'));
	return $dbList->readItems();
}), Array(Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>'Hall B')));

checkSame('one row with one column is a list of one row', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->fieldScop('title');
	$dbList->lstSearch(Array('title'=>'Beta*'));
	return $dbList->readItems();
}), Array(Array('title'=>'Beta Evening')));

checkSame('flatItems on one row', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('title'=>'Beta*'));
	return $dbList->flatItems('venue');
}), Array('Hall B'));

checkSame('childList with a single child', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('title'=>'Solo*'));
	$dbList->readItems();
	return $dbList->childList('items');
}), Array(Array('id_show'=>4, 'title'=>'Solo Gig', 'venue'=>'Hall S', 'items'=>Array(Array('id_item'=>7, 'show_id'=>4, 'type'=>'solo')))));

endChecks();
