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

checkSame('lstSearch on a field left unset', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('venue?'=>false));
	return array_column($dbList->readItems(), 'id_show');
}), Array(5));

checkSame('lstSearch with a null value matches nothing', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('venue'=>null));
	return $dbList->readItems();
}), Array());

checkSame('lstSearch with a strict comparison', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('id_show>'=>3));
	return array_column($dbList->readItems(), 'id_show');
}), Array(4, 5));

checkSame('lstSearch with an empty list matches nothing', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('id_show'=>Array()));
	return $dbList->readItems();
}), Array());

checkSame('lstSearch with a value holding a quote', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('venue'=>"Hall A' OR '1'='1"));
	return $dbList->readItems();
}), Array());

checkSame('a second lstSearch replaces the first', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('id_show'=>Array(1, 2), 'venue'=>'Hall A'));
	$dbList->lstSearch(Array('venue?'=>false));
	return array_column($dbList->readItems(), 'id_show');
}), Array(5));

checkLike('lstSearch with an invalid field name throws', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('venue` = 1 OR `title'=>'x'));
	return $dbList->readItems();
}), '/^THROWN RuntimeException: Invalid table or field name/');

checkSame('listLimit with an offset', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->listOrder('id_show');
	$dbList->listLimit('2,3');
	return array_column($dbList->readItems(), 'id_show');
}), Array(4, 5));

checkSame('listLimit as an array, and as a string with spaces', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->listOrder('id_show');
	$dbList->listLimit(Array(2, 3));
	$arrRows = array_column($dbList->readItems(), 'id_show');
	$dbList->listLimit(' 2 , 1 ');
	return Array($arrRows, array_column($dbList->readItems(), 'id_show'));
}), Array(Array(4, 5), Array(2, 3)));

checkSame('listLimit keeps only numbers', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->listOrder('id_show');
	$dbList->listLimit('2,1; DROP TABLE shows');
	$cutRows = array_column($dbList->readItems(), 'id_show');
	$dbList->listLimit('1 UNION SELECT title FROM shows');
	$allRows = new DBList('shows');
	return Array($cutRows, count($dbList->readItems()), count($allRows->readItems()));
}), Array(Array(1, 2), 5, 5));

checkSame('listLimit with a negative number or nothing', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->listLimit('-3');
	$noneRow = $dbList->readItems();
	$dbList->listLimit();
	return Array($noneRow, count($dbList->readItems()));
}), Array(Array(), 5));

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

checkSame('fieldScop with an alias and a table.field name', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->fieldScop('shows.title as name, max(id_show) as last');
	return $dbList->readItems();
}), Array(Array('name'=>'Empty Stage', 'last'=>5)));

checkSame('fieldScop with a star or nothing selects every field', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('id_show'=>2));
	$dbList->fieldScop(' * ');
	$starRow = $dbList->readItems();
	$dbList->fieldScop();
	return Array(array_keys($starRow[0]), $dbList->readItems()==$starRow);
}), Array(Array('id_show', 'title', 'venue'), true));

checkLike('fieldScop refuses any other expression', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->fieldScop('title, (SELECT passHash FROM users LIMIT 1) as leak');
	return $dbList->readItems();
}), '/^THROWN RuntimeException: Invalid field selection/');

checkLike('grpFields refuses an invalid name', runSafely(function(){
	$dbList = new DBList('items');
	$dbList->grpFields('type` HAVING 1=1 --');
	return $dbList->readItems();
}), '/^THROWN RuntimeException: Invalid table or field name/');

checkLike('listOrder refuses an invalid name', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->listOrder('^title`; DROP TABLE shows');
	return $dbList->readItems();
}), '/^THROWN RuntimeException: Invalid table or field name/');

checkLike('a table name that is not a plain name is refused', runSafely(function(){ return new DBList('shows` WHERE 1=1; --'); }), '/^THROWN RuntimeException: Invalid table or field name/');

checkSame('tableJoin', runSafely(function(){
	$dbList = new DBList('events');
	$dbList->fieldScop('title');
	$dbList->tableJoin(Array('shows', 'show_id', 'id_show'));
	$dbList->lstSearch(Array('time'=>'19:00'));
	return $dbList->flatItems('title');
}), Array('Beta Evening', 'Beta Evening'));

checkSame('tableJoin with table.field names', runSafely(function(){
	$dbList = new DBList('events');
	$dbList->fieldScop('shows.title, events.date');
	$dbList->tableJoin(Array('shows', 'events.show_id', 'shows.id_show'));
	$dbList->lstSearch(Array('events.id_event'=>7));
	return $dbList->readItems();
}), Array(Array('title'=>'Solo Gig', 'date'=>'2026-12-01')));

checkLike('tableJoin refuses an invalid field name', runSafely(function(){
	$dbList = new DBList('events');
	$dbList->tableJoin(Array('shows', 'show_id', 'id_show OR 1=1'));
	return $dbList->readItems();
}), '/^THROWN RuntimeException: Invalid table or field name/');

checkLike('tableJoin refuses a join without its two fields', runSafely(function(){
	$dbList = new DBList('events');
	$dbList->tableJoin(Array('shows', 'show_id'));
	return $dbList->readItems();
}), '/^THROWN RuntimeException: A join needs a table and two field names/');

checkSame('an empty tableJoin clears the join', runSafely(function(){
	$dbList = new DBList('events');
	$dbList->tableJoin(Array('shows', 'show_id', 'id_show'));
	$dbList->tableJoin(Array());
	return array_keys($dbList->readItems()[0]);
}), Array('id_event', 'show_id', 'date', 'time'));

checkSame('childList with a single field', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->readItems();
	return array_column($dbList->childList('items', 'type'), 'items');
}), Array(Array('music', 'dance'), Array('theatre', 'music'), Array('dance', 'circus'), Array('solo'), Array()));

checkSame('constructor with where and order clauses', runSafely(function(){
	$dbList = new DBList('events', null, "`show_id` = '1'", 'date DESC');
	return array_column($dbList->readItems(), 'date');
}), Array('2027-01-03', '2026-12-28'));

checkSame('constructor fragments are replaced by the matching calls', runSafely(function(){
	$dbList = new DBList('events', 'date', "`show_id` = '1'", 'date DESC', '1');
	$frstRow = $dbList->readItems();
	$dbList->fieldScop('id_event');
	$dbList->lstSearch(Array('show_id'=>2));
	$dbList->listOrder('id_event');
	$dbList->listLimit();
	return Array($frstRow, $dbList->readItems());
}), Array(Array(Array('date'=>'2027-01-03')), Array(Array('id_event'=>3), Array('id_event'=>4))));

checkSame('rows already read are given again when asked', runSafely(function(){
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('id_show'=>1));
	$dbList->readItems();
	$dbList->lstSearch(Array('id_show'=>2));
	return Array(array_column($dbList->readItems(true), 'id_show'), array_column($dbList->readItems(), 'id_show'));
}), Array(Array(1), Array(2)));

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

checkSame('a row without a key value gets no children, not the orphan rows', runSafely(function(){
	$dbHandl = nyu\Data\DBCon::getInstnc('sqlte', '', '', '', inTempDir('list.sqlite'))->getConnex();
	$dbHandl->sendQuery("INSERT INTO `events` (`id_event`,`show_id`,`date`,`time`) VALUES (90,NULL,'2027-02-01','18:00');", true);
	$dbList = new DBList('shows');
	$dbList->lstSearch(Array('id_show'=>5));
	$dbList->readItems();
	return $dbList->childList('events', 'id_event', 'show_id', 'venue');
}), Array(Array('id_show'=>5, 'title'=>'Empty Stage', 'venue'=>null, 'events'=>Array())));

endChecks();
