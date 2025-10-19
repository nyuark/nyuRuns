<?php

// Data\DBRec : loading, finding, existence, saving and relations,
// against the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('DBRec', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Data\DBRec;
use nyu\Data\DBCon;

buildData( inTempDir('record.sqlite') );
useSqlite( inTempDir('record.sqlite') );

/**
 * Loads a show straight from the database, in a fresh record.
 *
 * @param String $primaryKey Key of the show.
 * @return mixed The row, or false when it does not exist.
 */
function loadAShow( String $primaryKey ): Mixed {
	$showRec = new DBRec('shows');
	return $showRec->loadRecrd($primaryKey);
}

// Loading and finding
checkSame('loadRecrd found',   runSafely(function(){ return loadAShow('2'); }), Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>'Hall B'));
checkSame('loadRecrd missing', runSafely(function(){ return loadAShow('99'); }), false);
checkSame('findRecrd with a wildcard', runSafely(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecrd(Array('title~'=>'Beta*'));
}), Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>'Hall B'));
checkTrue('findRecrd missing gives nothing', runSafely(function(){
	$showRec = new DBRec('shows');
	return empty($showRec->findRecrd(Array('title'=>'Nope')));
}));
checkSame('findRecrd on a field left unset', runSafely(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecrd(Array('venue?'=>false))['id_show'] ?? null;
}), 5);
checkSame('findRecrd with a null value finds nothing', runSafely(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecrd(Array('venue'=>null));
}), Array());
checkSame('findRecrd with a strict comparison', runSafely(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecrd(Array('id_show>'=>4))['id_show'] ?? null;
}), 5);
checkSame('findRecrd with a list', runSafely(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecrd(Array('id_show'=>Array(4, 99)))['title'] ?? null;
}), 'Solo Gig');
checkSame('findRecrd without a filter gives the first record', runSafely(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecrd(Array())['id_show'] ?? null;
}), 1);
checkLike('findRecrd with an invalid field name throws', runSafely(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecrd(Array('venue` = 1 OR `title'=>'x'));
}), '/^THROWN RuntimeException: Invalid table or field name/');

// Existence
$showRec = new DBRec('shows');
checkSame('exstRecrd existing, integer key', runSafely(function() use ($showRec){ return $showRec->exstRecrd(1); }), true);
checkSame('exstRecrd existing, string key',  runSafely(function() use ($showRec){ return $showRec->exstRecrd('1'); }), true);
checkSame('exstRecrd missing',               runSafely(function() use ($showRec){ return $showRec->exstRecrd(99); }), false);
checkSame('exstRecrd key 0',                 runSafely(function() use ($showRec){ return $showRec->exstRecrd(0); }), false);
checkSame('exstRecrd null key',              runSafely(function() use ($showRec){ return $showRec->exstRecrd(null); }), false);
checkSame('exstRecrd empty key',             runSafely(function() use ($showRec){ return $showRec->exstRecrd(''); }), false);
checkSame('exstRecrd non-numeric key',       runSafely(function() use ($showRec){ return $showRec->exstRecrd('abc'); }), false);

// Updating
checkSame('update keeps the key and escapes quotes', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('2');
	$showRec->bindArray(Array('venue'=>"O'Hall", 'bogus'=>'ignored'));
	$savedKy = $showRec->saveRecrd();
	return Array($savedKy, loadAShow('2'));
}), Array('2', Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>"O'Hall")));

checkSame('update of a row holding NULL keeps the NULL', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('5');
	$showRec->bindArray(Array('title'=>'Empty Stage II'));
	$showRec->saveRecrd();
	return loadAShow('5');
}), Array('id_show'=>5, 'title'=>'Empty Stage II', 'venue'=>null));

checkSame('update can set a column to NULL', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('3');
	$showRec->bindArray(Array('venue'=>null));
	$showRec->saveRecrd();
	return loadAShow('3');
}), Array('id_show'=>3, 'title'=>'Alpha Matinee', 'venue'=>null));

checkSame('saveRecrd can leave fields out', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('4');
	$showRec->bindArray(Array('title'=>'Solo Gig II', 'venue'=>'Elsewhere'));
	$showRec->saveRecrd(Array('venue'));
	return loadAShow('4');
}), Array('id_show'=>4, 'title'=>'Solo Gig II', 'venue'=>'Hall S'));

checkSame('a record loaded with its relations can be saved', runSafely(function(){
	$showRec = new DBRec('shows', Array('events'));
	$showRec->loadRecrd('1');
	$showRec->bindArray(Array('venue'=>'Hall X'));
	$savedKy = $showRec->saveRecrd();
	$evntLst = new nyu\Data\DBLst('events');
	$evntLst->lstSearch(Array('show_id'=>'1'));
	return Array($savedKy, loadAShow('1'), count($evntLst->readItems()));
}), Array('1', Array('id_show'=>1, 'title'=>'Alpha Night', 'venue'=>'Hall X'), 2));

checkSame('an update with nothing to save returns the key', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('4');
	$showRec->bindArray(Array('title'=>'Never Saved'));
	$savedKy = $showRec->saveRecrd(Array('title', 'venue'));
	return Array($savedKy, loadAShow('4'));
}), Array('4', Array('id_show'=>4, 'title'=>'Solo Gig II', 'venue'=>'Hall S')));

// Inserting
checkSame('insert without a key returns the new key', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->bindArray(Array('title'=>'Gamma', 'venue'=>'Hall G'));
	$savedKy = $showRec->saveRecrd();
	return Array($savedKy, loadAShow($savedKy));
}), Array('6', Array('id_show'=>6, 'title'=>'Gamma', 'venue'=>'Hall G')));

checkSame('insert with a NULL value and a quote', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->bindArray(Array('title'=>"Epsilon's", 'venue'=>null));
	return loadAShow($showRec->saveRecrd());
}), Array('id_show'=>7, 'title'=>"Epsilon's", 'venue'=>null));

checkSame('the literal string NULL stays a string', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->bindArray(Array('title'=>'NULL', 'venue'=>'NULL'));
	return loadAShow($showRec->saveRecrd());
}), Array('id_show'=>8, 'title'=>'NULL', 'venue'=>'NULL'));

checkSame('insert with a supplied key uses that key', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->bindArray(Array('id_show'=>50, 'title'=>'Delta', 'venue'=>'Hall D'));
	$savedKy = $showRec->saveRecrd();
	return Array($savedKy, loadAShow('50'));
}), Array(50, Array('id_show'=>50, 'title'=>'Delta', 'venue'=>'Hall D')));

checkSame('saving again with the same key updates instead of inserting', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->bindArray(Array('id_show'=>50, 'title'=>'Delta II'));
	$savedKy = $showRec->saveRecrd();
	$allRows = new nyu\Data\DBLst('shows');
	$allRows->lstSearch(Array('title~'=>'Delta*'));
	return Array($savedKy, $allRows->readItems());
}), Array(50, Array(Array('id_show'=>50, 'title'=>'Delta II', 'venue'=>'Hall D'))));

// Blank record
checkSame('without initRecrd, a second item overwrites the loaded row', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('50');
	$showRec->bindArray(Array('title'=>'Delta III'));
	return Array($showRec->saveRecrd(), loadAShow('50')['title']);
}), Array('50', 'Delta III'));

checkSame('after initRecrd, a second item is a new row', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('50');
	$showRec->initRecrd();
	$showRec->bindArray(Array('title'=>'Zeta', 'venue'=>'Hall Z'));
	$savedKy = $showRec->saveRecrd();
	return Array($savedKy, loadAShow('50')['title'], loadAShow($savedKy));
}), Array('51', 'Delta III', Array('id_show'=>51, 'title'=>'Zeta', 'venue'=>'Hall Z')));

// Relations
checkSame('childList returns the record when asked', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('1');
	$recVals = $showRec->childList('events', true, '', '', 'date ASC');
	return array_column($recVals['Events'], 'date');
}), Array('2026-12-28', '2027-01-03'));

checkSame('childList returns nothing otherwise', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('1');
	return $showRec->childList('items');
}), null);

checkSame('childList with a single child is a list of one', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('4');
	return $showRec->childList('events', true)['Events'];
}), Array(Array('id_event'=>7, 'show_id'=>4, 'date'=>'2026-12-01', 'time'=>'21:00')));

checkSame('parentRec', runSafely(function(){
	$evntRec = new DBRec('events');
	$evntRec->loadRecrd('3');
	$recVals = $evntRec->parentRec('shows', true, 'id_show', 'show_id');
	return $recVals['Show']['id_show'] ?? null;
}), 2);

checkSame('relations declared in the constructor are loaded', runSafely(function(){
	$showRec = new DBRec('shows', Array('items'));
	$recVals = $showRec->loadRecrd('1');
	return array_column($recVals['Items'], 'type');
}), Array('music', 'dance'));

// A table with a single column
$dbHandl = DBCon::getInstnc('sqlte', '', '', '', inTempDir('record.sqlite'))->getConnex();
$dbHandl->sendQuery("CREATE TABLE `tags` (`label` TEXT PRIMARY KEY);", true);
$dbHandl->sendQuery("INSERT INTO `tags` (`label`) VALUES ('rock');", true);

checkSame('one-column table: loadRecrd', runSafely(function(){
	$tagsRec = new DBRec('tags');
	return $tagsRec->loadRecrd('rock');
}), Array('label'=>'rock'));
checkSame('one-column table: exstRecrd', runSafely(function(){
	$tagsRec = new DBRec('tags');
	return Array($tagsRec->exstRecrd('rock'), $tagsRec->exstRecrd('jazz'));
}), Array(true, false));
checkSame('one-column table: findRecrd', runSafely(function(){
	$tagsRec = new DBRec('tags');
	return Array($tagsRec->findRecrd(Array('label~'=>'ro*')), $tagsRec->findRecrd(Array('label'=>'jazz')));
}), Array(Array('label'=>'rock'), Array()));
checkSame('a key holding a quote is looked up as plain text', runSafely(function() use ($dbHandl){
	$dbHandl->sendQuery("INSERT INTO `tags` (`label`) VALUES (?);", true, Array("rock'n'roll"));
	$tagsRec = new DBRec('tags');
	return Array($tagsRec->loadRecrd("rock'n'roll"), $tagsRec->exstRecrd("rock'n'roll"), $tagsRec->exstRecrd("x' OR '1'='1"));
}), Array(Array('label'=>"rock'n'roll"), true, false));

checkSame('a key holding a wildcard character is not read as a wildcard', runSafely(function(){
	$tagsRec = new DBRec('tags');
	return Array($tagsRec->loadRecrd('ro*'), $tagsRec->exstRecrd('%'), $tagsRec->exstRecrd('rock'));
}), Array(false, false, true));

// Empty and missing tables
$dbHandl->sendQuery("CREATE TABLE `notes` (`id_note` INTEGER PRIMARY KEY, `body` TEXT);", true);

checkSame('empty table: loadRecrd finds nothing', runSafely(function(){
	$noteRec = new DBRec('notes');
	return $noteRec->loadRecrd('1');
}), false);
checkSame('empty table: a first row can be inserted', runSafely(function(){
	$noteRec = new DBRec('notes');
	$noteRec->bindArray(Array('body'=>'First'));
	$savedKy = $noteRec->saveRecrd();
	return Array($savedKy, $noteRec->loadRecrd($savedKy));
}), Array('1', Array('id_note'=>1, 'body'=>'First')));
checkThrw('missing table throws', function(){ new DBRec('nowhere'); }, 'RuntimeException');
checkLike('a table name that is not a plain name is refused', runSafely(function(){ return new DBRec('shows` WHERE 1=1; --'); }), '/^THROWN RuntimeException: Invalid table or field name/');

// Relations of a record that is missing, or that has no key value
checkTrue('findRecrd missing, with declared relations, gives nothing', runSafely(function(){
	$showRec = new DBRec('shows', Array('events'));
	return $showRec->findRecrd(Array('title'=>'Nope'))===Array();
}));
checkSame('a record without a key value gets no children, not the orphan rows', runSafely(function() use ($dbHandl){
	$dbHandl->sendQuery("INSERT INTO `events` (`id_event`,`show_id`,`date`,`time`) VALUES (90,NULL,'2027-02-01','18:00');", true);
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('5');
	return $showRec->childList('events', true, 'show_id', 'venue')['Events'];
}), Array());

// Deleting
/**
 * Counts the rows of a table matching a filter.
 *
 * @param String $tableLabel Name of the table.
 * @param Array  $whereArray Filter array, empty for every row.
 * @return Int The number of rows.
 */
function countRow( String $tableLabel, Array $whereArray=Array() ): Int {
	$rowList = new nyu\Data\DBLst($tableLabel);
	$rowList->lstSearch($whereArray);
	return count($rowList->readItems());
}

// Every removal below is traced on the 'runner' channel, signed with the actor of the request
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );
nyu\Tools\Logger::plugActor('bob', '10.0.0.1');

checkSame('remvRecrd alone deletes the record and leaves its children', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('4');
	return Array($showRec->remvRecrd(), loadAShow('4'), countRow('events', Array('show_id'=>4)), countRow('items', Array('show_id'=>4)));
}), Array(true, false, 1, 1));

checkSame('remvRecrd with named children deletes those rows, and only those', runSafely(function(){
	$evntAll = countRow('events');
	$itemAll = countRow('items');
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('3');
	$remDone = $showRec->remvRecrd(Array('events', 'items'=>'show_id'));
	return Array($remDone, loadAShow('3'), countRow('events', Array('show_id'=>3)), countRow('items', Array('show_id'=>3)), $evntAll - countRow('events'), $itemAll - countRow('items'));
}), Array(true, false, 0, 0, 2, 2));

checkSame('remvRecrd empties the record: a second call deletes nothing', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('51');
	$showAll = countRow('shows');
	return Array($showRec->remvRecrd(), $showRec->remvRecrd(), $showAll - countRow('shows'));
}), Array(true, false, 1));

checkSame('remvRecrd with nothing loaded deletes nothing', runSafely(function(){
	$evntAll = countRow('events');
	$showRec = new DBRec('shows');
	return Array($showRec->remvRecrd(Array('events')), $evntAll - countRow('events'));
}), Array(false, 0));

checkSame('remvRecrd of a row already gone restores the children it removed', runSafely(function(){
	$frstRec = new DBRec('shows');
	$frstRec->loadRecrd('2');
	$scndRec = new DBRec('shows');
	$scndRec->loadRecrd('2');
	$frstRec->remvRecrd();
	return Array($scndRec->remvRecrd(Array('events')), countRow('events', Array('show_id'=>2)));
}), Array(false, 2));

checkLike('remvRecrd with an invalid child name throws', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('1');
	return $showRec->remvRecrd(Array('events', 'items; DROP TABLE shows'));
}), '/^THROWN RuntimeException: Invalid table or field name/');
checkSame('...before anything was deleted', runSafely(function(){
	return Array(loadAShow('1')['id_show'] ?? null, countRow('events', Array('show_id'=>1)));
}), Array(1, 2));

checkSame('remvRecrd failing on a child table cancels the whole deletion', runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd('1');
	return Array($showRec->remvRecrd(Array('events', 'nowhere')), loadAShow('1')['id_show'] ?? null, countRow('events', Array('show_id'=>1)));
}), Array(false, 1, 2));

// The trace of the removals above : what was removed and by whom, and what the database refused
nyu\Tools\Logger::plugSetup( Array() );
nyu\Tools\Logger::plugActor('', '');
$remLogs = file_exists(inTempDir('logs').DS.'runner.log') ? file(inTempDir('logs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array();
checkSame('removal log: one entry per removal done or refused by the database, none for a record that is not there', $remLogs, Array(
	'['.date('Y').'] [NOTICE] Record removed: shows #4 by user "bob" from 10.0.0.1',
	'['.date('Y').'] [NOTICE] Record removed: shows #3, with its rows in events, items by user "bob" from 10.0.0.1',
	'['.date('Y').'] [NOTICE] Record removed: shows #51 by user "bob" from 10.0.0.1',
	'['.date('Y').'] [NOTICE] Record removed: shows #2 by user "bob" from 10.0.0.1',
	'['.date('Y').'] [WARNING] Record removal failed: shows #1 by user "bob" from 10.0.0.1',
));

// Connection
checkSame('getConnex returns the handler', runSafely(function(){
	return get_class( DBCon::getInstnc('sqlte', '', '', '', '')->getConnex() );
}), 'nyu\Data\DBHndlr\SQLite');

endChecks();
