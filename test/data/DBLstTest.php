<?php

// Data\DBLst : filtering, ordering, limiting, scopes, joins and child lists,
// against the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('DBLst', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Data\DBLst;

createSampleDb( inTemporaryDir('list.sqlite') );
switchToSqlite( inTemporaryDir('list.sqlite') );

checkIdentical('readItems returns every row', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	return array_column($dbList->readRowsOfList(), 'title');
}), Array('Alpha Night', 'Beta Evening', 'Alpha Matinee', 'Solo Gig', 'Empty Stage'));

checkIdentical('lstSearch with a wildcard, descending order', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('title~'=>'Alpha*'));
	$dbList->setOrderFields('^id_show');
	return array_column($dbList->readRowsOfList(), 'id_show');
}), Array(3, 1));

checkIdentical('lstSearch negated wildcard', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('title!~'=>'Alpha*'));
	return array_column($dbList->readRowsOfList(), 'id_show');
}), Array(2, 4, 5));

checkIdentical('lstSearch with a list of values, ordered and limited', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('id_show'=>Array(1, 2, 3)));
	$dbList->setOrderFields(Array('title'));
	$dbList->setLimitOffset('2');
	return array_column($dbList->readRowsOfList(), 'title');
}), Array('Alpha Matinee', 'Alpha Night'));

checkIdentical('lstSearch excluding a list of values', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('id_show!'=>Array(1, 2, 3)));
	return array_column($dbList->readRowsOfList(), 'id_show');
}), Array(4, 5));

checkIdentical('lstSearch on two fields', runCatchThrown(function(){
	$dbList = new DBLst('events');
	$dbList->replaceFilters(Array('show_id'=>'1', 'date>='=>'2027-01-01'));
	return array_column($dbList->readRowsOfList(), 'id_event');
}), Array(2));

checkIdentical('lstSearch on a field left unset', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('venue?'=>false));
	return array_column($dbList->readRowsOfList(), 'id_show');
}), Array(5));

checkIdentical('lstSearch with a null value matches nothing', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('venue'=>null));
	return $dbList->readRowsOfList();
}), Array());

checkIdentical('lstSearch with a strict comparison', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('id_show>'=>3));
	return array_column($dbList->readRowsOfList(), 'id_show');
}), Array(4, 5));

checkIdentical('lstSearch with an empty list matches nothing', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('id_show'=>Array()));
	return $dbList->readRowsOfList();
}), Array());

checkIdentical('lstSearch with a value holding a quote', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('venue'=>"Hall A' OR '1'='1"));
	return $dbList->readRowsOfList();
}), Array());

checkIdentical('a second lstSearch replaces the first', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('id_show'=>Array(1, 2), 'venue'=>'Hall A'));
	$dbList->replaceFilters(Array('venue?'=>false));
	return array_column($dbList->readRowsOfList(), 'id_show');
}), Array(5));

checkPregMatch('lstSearch with an invalid field name throws', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('venue` = 1 OR `title'=>'x'));
	return $dbList->readRowsOfList();
}), '/^THROWN RuntimeException: Invalid table or field name/');

checkIdentical('listLimit with an offset', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->setOrderFields('id_show');
	$dbList->setLimitOffset('2,3');
	return array_column($dbList->readRowsOfList(), 'id_show');
}), Array(4, 5));

checkIdentical('listLimit as an array, and as a string with spaces', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->setOrderFields('id_show');
	$dbList->setLimitOffset(Array(2, 3));
	$arrRows = array_column($dbList->readRowsOfList(), 'id_show');
	$dbList->setLimitOffset(' 2 , 1 ');
	return Array($arrRows, array_column($dbList->readRowsOfList(), 'id_show'));
}), Array(Array(4, 5), Array(2, 3)));

checkIdentical('listLimit keeps only numbers', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->setOrderFields('id_show');
	$dbList->setLimitOffset('2,1; DROP TABLE shows');
	$cutRows = array_column($dbList->readRowsOfList(), 'id_show');
	$dbList->setLimitOffset('1 UNION SELECT title FROM shows');
	$allRows = new DBLst('shows');
	return Array($cutRows, count($dbList->readRowsOfList()), count($allRows->readRowsOfList()));
}), Array(Array(1, 2), 5, 5));

checkIdentical('listLimit with a negative number or nothing', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->setLimitOffset('-3');
	$noneRow = $dbList->readRowsOfList();
	$dbList->setLimitOffset();
	return Array($noneRow, count($dbList->readRowsOfList()));
}), Array(Array(), 5));

checkIdentical('flatItems skips NULL values', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	return $dbList->flatItemsArray('venue');
}), Array('Hall A', 'Hall B', 'Hall C', 'Hall S'));

checkIdentical('fieldScop with an expression, grouped', runCatchThrown(function(){
	$dbList = new DBLst('items');
	$dbList->pickReadFields('type, count(*) as nb');
	$dbList->setGroupFields('type');
	$dbList->setOrderFields('type');
	return $dbList->readRowsOfList();
}), Array(
	Array('type'=>'circus', 'nb'=>1), Array('type'=>'dance', 'nb'=>2), Array('type'=>'music', 'nb'=>2),
	Array('type'=>'solo', 'nb'=>1), Array('type'=>'theatre', 'nb'=>1)
));

checkIdentical('fieldScop with an alias and a table.field name', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->pickReadFields('shows.title as name, max(id_show) as last');
	return $dbList->readRowsOfList();
}), Array(Array('name'=>'Empty Stage', 'last'=>5)));

checkIdentical('fieldScop with a star or nothing selects every field', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('id_show'=>2));
	$dbList->pickReadFields(' * ');
	$starRow = $dbList->readRowsOfList();
	$dbList->pickReadFields();
	return Array(array_keys($starRow[0]), $dbList->readRowsOfList()==$starRow);
}), Array(Array('id_show', 'title', 'venue'), true));

checkPregMatch('fieldScop refuses any other expression', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->pickReadFields('title, (SELECT passwdHash FROM users LIMIT 1) as leak');
	return $dbList->readRowsOfList();
}), '/^THROWN RuntimeException: Invalid field selection/');

checkIdentical('fieldScop takes the aggregates of the list only, whatever their case, and * for a count alone', array_map(function( Mixed $rsltVal ): Mixed { return is_string($rsltVal) ? preg_replace('/ @\S+$/', '', $rsltVal) : $rsltVal; }, Array(
	runCatchThrown(function(){ $dbList = new DBLst('shows'); $dbList->pickReadFields('COUNT(*) as nb, Min(id_show) as first, sum(id_show) as total, avg(id_show) as mean'); return array_keys($dbList->readRowsOfList()[0] ?? Array()); }),
	runCatchThrown(function(){ $dbList = new DBLst('shows'); $dbList->pickReadFields('sleep(1) as x'); return $dbList->readRowsOfList(); }),
	runCatchThrown(function(){ $dbList = new DBLst('shows'); $dbList->pickReadFields('lower(title)'); return $dbList->readRowsOfList(); }),
	runCatchThrown(function(){ $dbList = new DBLst('shows'); $dbList->pickReadFields('max(*)'); return $dbList->readRowsOfList(); })
)), Array(Array('nb', 'first', 'total', 'mean'), 'THROWN RuntimeException: Invalid field selection: sleep(1) as x', 'THROWN RuntimeException: Invalid field selection: lower(title)', 'THROWN RuntimeException: Invalid field selection: max(*)'));

checkPregMatch('the store refuses another function, called without the list', runCatchThrown(function(){
	return nyu\Data\DBCon::linkNamedDbCon('')->getStoreObject()->findItemsArray('shows', Array(), Array('funcs'=>Array(Array('funct'=>'load_file', 'field'=>'title', 'alias'=>'x'))));
}), '/^THROWN RuntimeException: Invalid function name: load_file /');

checkPregMatch('an order written as SQL is kept, and told on runner', runCatchThrown(function(){
	nyu\Tools\Logger::loadLogsConfig( Array('path'=>inTemporaryDir('lstlogs')) );
	$dbList = new DBLst('shows', null, null, 'length(title) DESC');
	$rowList = $dbList->flatItemsArray('title');
	nyu\Tools\Logger::loadLogsConfig( Array() );
	return ($rowList[0] ?? '').' | '.strval(@file_get_contents(inTemporaryDir('lstlogs').DS.'runner.log'));
}), '/^Alpha Matinee \| .*\[NOTICE\] List of "shows": order written as SQL, kept as given/s');

checkPregMatch('grpFields refuses an invalid name', runCatchThrown(function(){
	$dbList = new DBLst('items');
	$dbList->setGroupFields('type` HAVING 1=1 --');
	return $dbList->readRowsOfList();
}), '/^THROWN RuntimeException: Invalid table or field name/');

checkPregMatch('listOrder refuses an invalid name', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->setOrderFields('^title`; DROP TABLE shows');
	return $dbList->readRowsOfList();
}), '/^THROWN RuntimeException: Invalid table or field name/');

checkPregMatch('a table name that is not a plain name is refused', runCatchThrown(function(){ return new DBLst('shows` WHERE 1=1; --'); }), '/^THROWN RuntimeException: Invalid table or field name/');

checkIdentical('joinOtherTable', runCatchThrown(function(){
	$dbList = new DBLst('events');
	$dbList->pickReadFields('title');
	$dbList->joinOtherTable(Array('shows', 'show_id', 'id_show'));
	$dbList->replaceFilters(Array('time'=>'19:00'));
	return $dbList->flatItemsArray('title');
}), Array('Beta Evening', 'Beta Evening'));

checkIdentical('tableJoin with table.field names', runCatchThrown(function(){
	$dbList = new DBLst('events');
	$dbList->pickReadFields('shows.title, events.date');
	$dbList->joinOtherTable(Array('shows', 'events.show_id', 'shows.id_show'));
	$dbList->replaceFilters(Array('events.id_event'=>7));
	return $dbList->readRowsOfList();
}), Array(Array('title'=>'Solo Gig', 'date'=>'2026-12-01')));

checkPregMatch('tableJoin refuses an invalid field name', runCatchThrown(function(){
	$dbList = new DBLst('events');
	$dbList->joinOtherTable(Array('shows', 'show_id', 'id_show OR 1=1'));
	return $dbList->readRowsOfList();
}), '/^THROWN RuntimeException: Invalid table or field name/');

checkPregMatch('tableJoin refuses a join without its two fields', runCatchThrown(function(){
	$dbList = new DBLst('events');
	$dbList->joinOtherTable(Array('shows', 'show_id'));
	return $dbList->readRowsOfList();
}), '/^THROWN RuntimeException: A join needs a table and two field names/');

checkIdentical('an empty tableJoin clears the join', runCatchThrown(function(){
	$dbList = new DBLst('events');
	$dbList->joinOtherTable(Array('shows', 'show_id', 'id_show'));
	$dbList->joinOtherTable(Array());
	return array_keys($dbList->readRowsOfList()[0]);
}), Array('id_event', 'show_id', 'date', 'time'));

checkIdentical('childList with a single field', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->readRowsOfList();
	return array_column($dbList->attachChildren('items', 'type'), 'items');
}), Array(Array('music', 'dance'), Array('theatre', 'music'), Array('dance', 'circus'), Array('solo'), Array()));

checkIdentical('constructor with where and order clauses', runCatchThrown(function(){
	$dbList = new DBLst('events', null, "`show_id` = '1'", 'date DESC');
	return array_column($dbList->readRowsOfList(), 'date');
}), Array('2027-01-03', '2026-12-28'));

checkIdentical('constructor fragments are replaced by the matching calls', runCatchThrown(function(){
	$dbList = new DBLst('events', 'date', "`show_id` = '1'", 'date DESC', '1');
	$frstRow = $dbList->readRowsOfList();
	$dbList->pickReadFields('id_event');
	$dbList->replaceFilters(Array('show_id'=>2));
	$dbList->setOrderFields('id_event');
	$dbList->setLimitOffset();
	return Array($frstRow, $dbList->readRowsOfList());
}), Array(Array(Array('date'=>'2027-01-03')), Array(Array('id_event'=>3), Array('id_event'=>4))));

checkIdentical('rows already read are given again when asked', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('id_show'=>1));
	$dbList->readRowsOfList();
	$dbList->replaceFilters(Array('id_show'=>2));
	return Array(array_column($dbList->readRowsOfList(true), 'id_show'), array_column($dbList->readRowsOfList(), 'id_show'));
}), Array(Array(1), Array(2)));

checkIdentical('no match gives an empty list', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('title~'=>'Zzz*'));
	return $dbList->readRowsOfList();
}), Array());

// A single matching row must still come back as a list of rows
checkIdentical('one row is a list of one row', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('title~'=>'Beta*'));
	return $dbList->readRowsOfList();
}), Array(Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>'Hall B')));

checkIdentical('one row with one column is a list of one row', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->pickReadFields('title');
	$dbList->replaceFilters(Array('title~'=>'Beta*'));
	return $dbList->readRowsOfList();
}), Array(Array('title'=>'Beta Evening')));

checkIdentical('flatItems on one row', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('title~'=>'Beta*'));
	return $dbList->flatItemsArray('venue');
}), Array('Hall B'));

checkIdentical('childList with a single child', runCatchThrown(function(){
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('title~'=>'Solo*'));
	$dbList->readRowsOfList();
	return $dbList->attachChildren('items');
}), Array(Array('id_show'=>4, 'title'=>'Solo Gig', 'venue'=>'Hall S', 'items'=>Array(Array('id_item'=>7, 'show_id'=>4, 'type'=>'solo')))));

checkIdentical('a row without a key value gets no children, not the orphan rows', runCatchThrown(function(){
	$dbHandl = nyu\Data\DBCon::obtainInstance('sqlitedb', '', '', '', inTemporaryDir('list.sqlite'))->readConnection();
	$dbHandl->sendBuiltQuery("INSERT INTO `events` (`id_event`,`show_id`,`date`,`time`) VALUES (90,NULL,'2027-02-01','18:00');", true);
	$dbList = new DBLst('shows');
	$dbList->replaceFilters(Array('id_show'=>5));
	$dbList->readRowsOfList();
	return $dbList->attachChildren('events', 'id_event', 'show_id', 'venue');
}), Array(Array('id_show'=>5, 'title'=>'Empty Stage', 'venue'=>null, 'events'=>Array())));

finishChecking();
