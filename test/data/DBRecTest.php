<?php

// Data\DBRec : loading, finding, existence, saving and relations,
// against the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('DBRec', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Data\DBRec;
use nyu\Data\DBCon;

createSampleDb( inTemporaryDir('record.sqlite') );
switchToSqlite( inTemporaryDir('record.sqlite') );

/**
 * Loads a show straight from the database, in a fresh record.
 *
 * @param String $primaryKey Key of the show.
 * @return mixed The row, or false when it does not exist.
 */
function loadAShow( String $primaryKey ): Mixed {
	$showRec = new DBRec('shows');
	return $showRec->loadRecordList($primaryKey);
}

// Loading and finding
checkIdentical('loadRecrd found',   runCatchThrown(function(){ return loadAShow('2'); }), Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>'Hall B'));
checkIdentical('loadRecrd missing', runCatchThrown(function(){ return loadAShow('99'); }), false);
checkIdentical('findRecrd with a wildcard', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecordList(Array('title~'=>'Beta*'));
}), Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>'Hall B'));
checkTrueValue('findRecrd missing gives nothing', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	return empty($showRec->findRecordList(Array('title'=>'Nope')));
}));
checkIdentical('findRecrd on a field left unset', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecordList(Array('venue?'=>false))['id_show'] ?? null;
}), 5);
checkIdentical('findRecrd with a null value finds nothing', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecordList(Array('venue'=>null));
}), Array());
checkIdentical('findRecrd with a strict comparison', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecordList(Array('id_show>'=>4))['id_show'] ?? null;
}), 5);
checkIdentical('findRecrd with a list', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecordList(Array('id_show'=>Array(4, 99)))['title'] ?? null;
}), 'Solo Gig');
checkIdentical('findRecrd without a filter gives the first record', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecordList(Array())['id_show'] ?? null;
}), 1);
checkPregMatch('findRecrd with an invalid field name throws', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	return $showRec->findRecordList(Array('venue` = 1 OR `title'=>'x'));
}), '/^THROWN RuntimeException: Invalid table or field name/');

// Existence
$showRec = new DBRec('shows');
checkIdentical('exstRecrd existing, integer key', runCatchThrown(function() use ($showRec){ return $showRec->existsRecdFlag(1); }), true);
checkIdentical('exstRecrd existing, string key',  runCatchThrown(function() use ($showRec){ return $showRec->existsRecdFlag('1'); }), true);
checkIdentical('exstRecrd missing',               runCatchThrown(function() use ($showRec){ return $showRec->existsRecdFlag(99); }), false);
checkIdentical('exstRecrd key 0',                 runCatchThrown(function() use ($showRec){ return $showRec->existsRecdFlag(0); }), false);
checkIdentical('exstRecrd null key',              runCatchThrown(function() use ($showRec){ return $showRec->existsRecdFlag(null); }), false);
checkIdentical('exstRecrd empty key',             runCatchThrown(function() use ($showRec){ return $showRec->existsRecdFlag(''); }), false);
checkIdentical('exstRecrd non-numeric key',       runCatchThrown(function() use ($showRec){ return $showRec->existsRecdFlag('abc'); }), false);

// Updating
checkIdentical('update keeps the key and escapes quotes', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('2');
	$showRec->bindFieldArray(Array('venue'=>"O'Hall", 'bogus'=>'ignored'));
	$savedKy = $showRec->insertOrUpdate();
	return Array($savedKy, loadAShow('2'));
}), Array(2, Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>"O'Hall")));

checkIdentical('update of a row holding NULL keeps the NULL', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('5');
	$showRec->bindFieldArray(Array('title'=>'Empty Stage II'));
	$showRec->insertOrUpdate();
	return loadAShow('5');
}), Array('id_show'=>5, 'title'=>'Empty Stage II', 'venue'=>null));

checkIdentical('update can set a column to NULL', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('3');
	$showRec->bindFieldArray(Array('venue'=>null));
	$showRec->insertOrUpdate();
	return loadAShow('3');
}), Array('id_show'=>3, 'title'=>'Alpha Matinee', 'venue'=>null));

checkIdentical('saveRecrd can leave fields out', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('4');
	$showRec->bindFieldArray(Array('title'=>'Solo Gig II', 'venue'=>'Elsewhere'));
	$showRec->insertOrUpdate(Array('venue'));
	return loadAShow('4');
}), Array('id_show'=>4, 'title'=>'Solo Gig II', 'venue'=>'Hall S'));

checkIdentical('a record loaded with its relations can be saved', runCatchThrown(function(){
	$showRec = new DBRec('shows', Array('events'));
	$showRec->loadRecordList('1');
	$showRec->bindFieldArray(Array('venue'=>'Hall X'));
	$savedKy = $showRec->insertOrUpdate();
	$evntLst = new nyu\Data\DBLst('events');
	$evntLst->replaceFilters(Array('show_id'=>'1'));
	return Array($savedKy, loadAShow('1'), count($evntLst->readRowsOfList()));
}), Array(1, Array('id_show'=>1, 'title'=>'Alpha Night', 'venue'=>'Hall X'), 2));

checkIdentical('an update with nothing to save returns the key', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('4');
	$showRec->bindFieldArray(Array('title'=>'Never Saved'));
	$savedKy = $showRec->insertOrUpdate(Array('title', 'venue'));
	return Array($savedKy, loadAShow('4'));
}), Array(4, Array('id_show'=>4, 'title'=>'Solo Gig II', 'venue'=>'Hall S')));

// Inserting
checkIdentical('insert without a key returns the new key', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->bindFieldArray(Array('title'=>'Gamma', 'venue'=>'Hall G'));
	$savedKy = $showRec->insertOrUpdate();
	return Array($savedKy, loadAShow($savedKy));
}), Array(6, Array('id_show'=>6, 'title'=>'Gamma', 'venue'=>'Hall G')));

checkIdentical('insert with a NULL value and a quote', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->bindFieldArray(Array('title'=>"Epsilon's", 'venue'=>null));
	return loadAShow($showRec->insertOrUpdate());
}), Array('id_show'=>7, 'title'=>"Epsilon's", 'venue'=>null));

checkIdentical('the literal string NULL stays a string', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->bindFieldArray(Array('title'=>'NULL', 'venue'=>'NULL'));
	return loadAShow($showRec->insertOrUpdate());
}), Array('id_show'=>8, 'title'=>'NULL', 'venue'=>'NULL'));

checkIdentical('insert with a supplied key uses that key', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->bindFieldArray(Array('id_show'=>50, 'title'=>'Delta', 'venue'=>'Hall D'));
	$savedKy = $showRec->insertOrUpdate();
	return Array($savedKy, loadAShow('50'));
}), Array(50, Array('id_show'=>50, 'title'=>'Delta', 'venue'=>'Hall D')));

checkIdentical('saving again with the same key updates instead of inserting', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->bindFieldArray(Array('id_show'=>50, 'title'=>'Delta II'));
	$savedKy = $showRec->insertOrUpdate();
	$allRows = new nyu\Data\DBLst('shows');
	$allRows->replaceFilters(Array('title~'=>'Delta*'));
	return Array($savedKy, $allRows->readRowsOfList());
}), Array(50, Array(Array('id_show'=>50, 'title'=>'Delta II', 'venue'=>'Hall D'))));

// Blank record
checkIdentical('without initRecrd, a second item overwrites the loaded row', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('50');
	$showRec->bindFieldArray(Array('title'=>'Delta III'));
	return Array($showRec->insertOrUpdate(), loadAShow('50')['title']);
}), Array(50, 'Delta III'));

checkIdentical('after initRecrd, a second item is a new row', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('50');
	$showRec->initiateRecord();
	$showRec->bindFieldArray(Array('title'=>'Zeta', 'venue'=>'Hall Z'));
	$savedKy = $showRec->insertOrUpdate();
	return Array($savedKy, loadAShow('50')['title'], loadAShow($savedKy));
}), Array(51, 'Delta III', Array('id_show'=>51, 'title'=>'Zeta', 'venue'=>'Hall Z')));

// Relations
checkIdentical('childList returns the record when asked', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('1');
	$recVals = $showRec->attachChildren('events', true, '', '', 'date ASC');
	return array_column($recVals['Events'], 'date');
}), Array('2026-12-28', '2027-01-03'));

checkIdentical('childList sorts through listOrder: a direction, or ^ for a descending one', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('1');
	return Array(array_column($showRec->attachChildren('events', true, '', '', 'date DESC')['Events'], 'date'), array_column($showRec->attachChildren('events', true, '', '', '^date')['Events'], 'date'));
}), Array(Array('2027-01-03', '2026-12-28'), Array('2027-01-03', '2026-12-28')));

checkIdentical('childList refuses a sort that is not field names', preg_replace('/ @\S+$/', '', Array(
	runCatchThrown(function(){ $showRec = new DBRec('shows'); $showRec->loadRecordList('1'); return $showRec->attachChildren('events', true, '', '', '(SELECT 1)'); }),
	runCatchThrown(function(){ $showRec = new DBRec('shows'); $showRec->loadRecordList('1'); return $showRec->attachChildren('events', true, '', '', 'date ASC NULLS'); })
)), Array('THROWN RuntimeException: Invalid sort of the children: (SELECT 1)', 'THROWN RuntimeException: Invalid sort of the children: date ASC NULLS'));

checkIdentical('childList returns nothing otherwise', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('1');
	return $showRec->attachChildren('items');
}), null);

checkIdentical('childList with a single child is a list of one', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('4');
	return $showRec->attachChildren('events', true)['Events'];
}), Array(Array('id_event'=>7, 'show_id'=>4, 'date'=>'2026-12-01', 'time'=>'21:00')));

checkIdentical('parentRecdList', runCatchThrown(function(){
	$evntRec = new DBRec('events');
	$evntRec->loadRecordList('3');
	$recVals = $evntRec->parentRecdList('shows', true, 'id_show', 'show_id');
	return $recVals['Show']['id_show'] ?? null;
}), 2);

checkIdentical('relations declared in the constructor are loaded', runCatchThrown(function(){
	$showRec = new DBRec('shows', Array('items'));
	$recVals = $showRec->loadRecordList('1');
	return array_column($recVals['Items'], 'type');
}), Array('music', 'dance'));

// A table with a single column
$dbHandl = DBCon::obtainInstance('sqlitedb', '', '', '', inTemporaryDir('record.sqlite'))->readConnection();
$dbHandl->sendBuiltQuery("CREATE TABLE `tags` (`label` TEXT PRIMARY KEY);", true);
$dbHandl->sendBuiltQuery("INSERT INTO `tags` (`label`) VALUES ('rock');", true);

checkIdentical('one-column table: loadRecrd', runCatchThrown(function(){
	$tagsRec = new DBRec('tags');
	return $tagsRec->loadRecordList('rock');
}), Array('label'=>'rock'));
checkIdentical('one-column table: exstRecrd', runCatchThrown(function(){
	$tagsRec = new DBRec('tags');
	return Array($tagsRec->existsRecdFlag('rock'), $tagsRec->existsRecdFlag('jazz'));
}), Array(true, false));
checkIdentical('one-column table: findRecrd', runCatchThrown(function(){
	$tagsRec = new DBRec('tags');
	return Array($tagsRec->findRecordList(Array('label~'=>'ro*')), $tagsRec->findRecordList(Array('label'=>'jazz')));
}), Array(Array('label'=>'rock'), Array()));
checkIdentical('a key holding a quote is looked up as plain text', runCatchThrown(function() use ($dbHandl){
	$dbHandl->sendBuiltQuery("INSERT INTO `tags` (`label`) VALUES (?);", true, Array("rock'n'roll"));
	$tagsRec = new DBRec('tags');
	return Array($tagsRec->loadRecordList("rock'n'roll"), $tagsRec->existsRecdFlag("rock'n'roll"), $tagsRec->existsRecdFlag("x' OR '1'='1"));
}), Array(Array('label'=>"rock'n'roll"), true, false));

checkIdentical('a key holding a wildcard character is not read as a wildcard', runCatchThrown(function(){
	$tagsRec = new DBRec('tags');
	return Array($tagsRec->loadRecordList('ro*'), $tagsRec->existsRecdFlag('%'), $tagsRec->existsRecdFlag('rock'));
}), Array(false, false, true));

// Empty and missing tables
$dbHandl->sendBuiltQuery("CREATE TABLE `notes` (`id_note` INTEGER PRIMARY KEY, `body` TEXT);", true);

checkIdentical('empty table: loadRecrd finds nothing', runCatchThrown(function(){
	$noteRec = new DBRec('notes');
	return $noteRec->loadRecordList('1');
}), false);
checkIdentical('empty table: a first row can be inserted', runCatchThrown(function(){
	$noteRec = new DBRec('notes');
	$noteRec->bindFieldArray(Array('body'=>'First'));
	$savedKy = $noteRec->insertOrUpdate();
	return Array($savedKy, $noteRec->loadRecordList($savedKy));
}), Array(1, Array('id_note'=>1, 'body'=>'First')));
checkForThrows('missing table throws', function(){ new DBRec('nowhere'); }, 'RuntimeException');
checkPregMatch('a table name that is not a plain name is refused', runCatchThrown(function(){ return new DBRec('shows` WHERE 1=1; --'); }), '/^THROWN RuntimeException: Invalid table or field name/');

// Relations of a record that is missing, or that has no key value
checkTrueValue('findRecrd missing, with declared relations, gives nothing', runCatchThrown(function(){
	$showRec = new DBRec('shows', Array('events'));
	return $showRec->findRecordList(Array('title'=>'Nope'))===Array();
}));
checkIdentical('a record without a key value gets no children, not the orphan rows', runCatchThrown(function() use ($dbHandl){
	$dbHandl->sendBuiltQuery("INSERT INTO `events` (`id_event`,`show_id`,`date`,`time`) VALUES (90,NULL,'2027-02-01','18:00');", true);
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('5');
	return $showRec->attachChildren('events', true, 'show_id', 'venue')['Events'];
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
	$rowList->replaceFilters($whereArray);
	return count($rowList->readRowsOfList());
}

// Every removal below is traced on the 'runner' channel, signed with the actor of the request
nyu\Tools\Logger::loadLogsConfig( Array('path'=>inTemporaryDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );
nyu\Tools\Logger::setCurrentUser('bob', '10.0.0.1');

checkIdentical('remvRecrd alone deletes the record and leaves its children', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('4');
	return Array($showRec->removeRecdFlag(), loadAShow('4'), countRow('events', Array('show_id'=>4)), countRow('items', Array('show_id'=>4)));
}), Array(true, false, 1, 1));

checkIdentical('remvRecrd with named children deletes those rows, and only those', runCatchThrown(function(){
	$evntAll = countRow('events');
	$itemAll = countRow('items');
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('3');
	$remDone = $showRec->removeRecdFlag(Array('events', 'items'=>'show_id'));
	return Array($remDone, loadAShow('3'), countRow('events', Array('show_id'=>3)), countRow('items', Array('show_id'=>3)), $evntAll - countRow('events'), $itemAll - countRow('items'));
}), Array(true, false, 0, 0, 2, 2));

checkIdentical('remvRecrd empties the record: a second call deletes nothing', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('51');
	$showAll = countRow('shows');
	return Array($showRec->removeRecdFlag(), $showRec->removeRecdFlag(), $showAll - countRow('shows'));
}), Array(true, false, 1));

checkIdentical('remvRecrd with nothing loaded deletes nothing', runCatchThrown(function(){
	$evntAll = countRow('events');
	$showRec = new DBRec('shows');
	return Array($showRec->removeRecdFlag(Array('events')), $evntAll - countRow('events'));
}), Array(false, 0));

checkIdentical('remvRecrd of a row already gone restores the children it removed', runCatchThrown(function(){
	$frstRec = new DBRec('shows');
	$frstRec->loadRecordList('2');
	$scndRec = new DBRec('shows');
	$scndRec->loadRecordList('2');
	$frstRec->removeRecdFlag();
	return Array($scndRec->removeRecdFlag(Array('events')), countRow('events', Array('show_id'=>2)));
}), Array(false, 2));

checkPregMatch('remvRecrd with an invalid child name throws', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('1');
	return $showRec->removeRecdFlag(Array('events', 'items; DROP TABLE shows'));
}), '/^THROWN RuntimeException: Invalid table or field name/');
checkIdentical('...before anything was deleted', runCatchThrown(function(){
	return Array(loadAShow('1')['id_show'] ?? null, countRow('events', Array('show_id'=>1)));
}), Array(1, 2));

checkIdentical('remvRecrd failing on a child table cancels the whole deletion', runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList('1');
	return Array($showRec->removeRecdFlag(Array('events', 'nowhere')), loadAShow('1')['id_show'] ?? null, countRow('events', Array('show_id'=>1)));
}), Array(false, 1, 2));

// The trace of the removals above : what was removed and by whom, and what the database refused
nyu\Tools\Logger::loadLogsConfig( Array() );
nyu\Tools\Logger::setCurrentUser('', '');
$remLogs = file_exists(inTemporaryDir('logs').DS.'runner.log') ? file(inTemporaryDir('logs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array();
checkIdentical('removal log: one entry per removal done or refused by the database, none for a record that is not there', $remLogs, Array(
	'['.date('Y').'] [NOTICE] Record removed: shows #"4" by user "bob" from 10.0.0.1',
	'['.date('Y').'] [NOTICE] Record removed: shows #"3", with its rows in events, items by user "bob" from 10.0.0.1',
	'['.date('Y').'] [NOTICE] Record removed: shows #"51" by user "bob" from 10.0.0.1',
	'['.date('Y').'] [NOTICE] Record removed: shows #"2" by user "bob" from 10.0.0.1',
	'['.date('Y').'] [WARNING] Record removal failed: shows #"1" by user "bob" from 10.0.0.1',
));

// Connection
checkIdentical('getConnex returns the handler', runCatchThrown(function(){
	return get_class( DBCon::obtainInstance('sqlitedb', '', '', '', '')->readConnection() );
}), 'nyu\Data\DBHndlr\SQLite');

finishChecking();
