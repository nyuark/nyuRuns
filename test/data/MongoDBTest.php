<?php

// Data\DBStore\MongoStore : the store contract, a List and a Record on a
// real MongoDB server. Optional: the test skips itself when the mongodb
// extension is missing or when the server does not answer (see
// test/lib/servers.php and .env.example). It works in a database of its
// own, whose name must contain "test", and empties the shows and events
// collections it uses there.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/servers.php';

use nyu\Data\DBCon;
use nyu\Data\DBLst;
use nyu\Data\DBRec;

if( !extension_loaded('mongodb') ){
	skipCheck('MongoDB', 'the mongodb extension is not available');
	endChecks();
}

$srvConf = serverCnf('mongo');
if( $srvConf['skip']!='' ){
	skipCheck('MongoDB', $srvConf['skip']);
	endChecks();
}
$prtNmbr = ($srvConf['port']!='') ? intval($srvConf['port']) : 27017;
$tryLink = @fsockopen($srvConf['host'], $prtNmbr, $errNmbr, $errText, 2);
if( !$tryLink ){
	skipCheck('MongoDB', 'no server answers on '.$srvConf['host'].':'.$prtNmbr);
	endChecks();
}
fclose($tryLink);

useServer('mongo', $srvConf);
$dbStore = runSafely(function(){ return DBCon::linkNamed()->dataStore(); });
if( is_string($dbStore) ){
	skipCheck('MongoDB', 'the store could not be opened: '.$dbStore);
	endChecks();
}

// Empty the collections of the test: every document has an _id
foreach( Array('shows', 'events') as $tblNme ){
	if( $dbStore->deleteRow($tblNme, Array('_id?'=>true))===false ){
		skipCheck('MongoDB', 'the server did not accept the test database: '.$dbStore->lastError());
		endChecks();
	}
}

$showKey = Array();
foreach( Array(
	Array('title'=>'Alpha Night',   'venue'=>'Hall A', 'seats'=>120),
	Array('title'=>'Beta Evening',  'venue'=>'Hall B', 'seats'=>80),
	Array('title'=>'Alpha Matinee', 'venue'=>'Hall C', 'seats'=>80),
	Array('title'=>'Empty Stage',   'venue'=>null,     'seats'=>0)
) as $showRow ){
	$showKey[] = $dbStore->insertRow('shows', $showRow);
}
foreach( Array(Array($showKey[0], '2026-12-28'), Array($showKey[0], '2026-11-03'), Array($showKey[1], '2026-11-02')) as $evntRow ){
	$dbStore->insertRow('events', Array('show_id'=>$evntRow[0], 'date'=>$evntRow[1]));
}

/**
 * Reads the titles a list gives, in title order.
 *
 * @param Array $whereArray Filter array.
 * @return mixed The titles, or a 'THROWN ...' string.
 */
function docTitle( Array $whereArray ): Mixed {
	return runSafely(function() use ($whereArray){
		$showLst = new DBLst('shows');
		$showLst->lstSearch($whereArray);
		$showLst->listOrder('title');
		$fndRows = $showLst->readItems();
		return is_array($fndRows) ? array_column($fndRows, 'title') : $fndRows;
	});
}

// Connection and store
checkSame('the mongo type gives the MongoDB store, and no SQL handler', Array(get_class($dbStore), DBCon::linkNamed()->getConnex()), Array('nyu\Data\DBStore\MongoStore', null));
checkSame('the keys given by the server are 24 hexadecimal characters', array_map(function($givenValue){ return is_string($givenValue) && preg_match('/^[0-9a-f]{24}$/', $givenValue)==1; }, $showKey), Array(true, true, true, true));
checkSame('tableInfo', $dbStore->tableInfo('shows'), Array('flds'=>Array(), 'pkey'=>'_id', 'blob'=>Array()));
checkSame('there are no transactions', Array($dbStore->canTrnsct(), $dbStore->opnTrnsct()), Array(false, false));

// Lists
checkSame('list: every row', docTitle(Array()), Array('Alpha Matinee', 'Alpha Night', 'Beta Evening', 'Empty Stage'));
checkSame('list: equal', docTitle(Array('venue'=>'Hall B')), Array('Beta Evening'));
checkSame('list: not equal leaves out the rows left unset, as SQL does', docTitle(Array('venue!'=>'Hall B')), Array('Alpha Matinee', 'Alpha Night'));
checkSame('list: wildcard, whatever the case', docTitle(Array('title~'=>'alpha*')), Array('Alpha Matinee', 'Alpha Night'));
checkSame('list: negated wildcard', docTitle(Array('title!~'=>'Alpha*')), Array('Beta Evening', 'Empty Stage'));
checkSame('list: an exact value keeps its wildcard characters', docTitle(Array('title='=>'Alpha*')), Array());
checkSame('list: comparison and list of values', docTitle(Array('seats>='=>80, 'venue'=>Array('Hall B', 'Hall C'))), Array('Alpha Matinee', 'Beta Evening'));
checkSame('list: excluded list of values', docTitle(Array('venue!'=>Array('Hall A', 'Hall B'))), Array('Alpha Matinee'));
checkSame('list: strict comparisons on one field', docTitle(Array('seats>'=>0, 'seats<'=>120)), Array('Alpha Matinee', 'Beta Evening'));
checkSame('list: field left unset, and set', Array(docTitle(Array('venue?'=>false)), docTitle(Array('venue?'=>true))), Array(Array('Empty Stage'), Array('Alpha Matinee', 'Alpha Night', 'Beta Evening')));
checkSame('list: a null value matches nothing', docTitle(Array('venue'=>null)), Array());
checkSame('list: values are compared with their type', Array(docTitle(Array('seats'=>120)), docTitle(Array('seats'=>'120'))), Array(Array('Alpha Night'), Array()));
checkSame('list: an operator sent as a value matches nothing', docTitle(Array('title'=>Array('$ne'=>''))), Array());
checkSame('list: a row found by its key', docTitle(Array('_id'=>$showKey[1])), Array('Beta Evening'));
checkSame('list: rows found by a list of keys', docTitle(Array('_id'=>Array($showKey[0], $showKey[3]))), Array('Alpha Night', 'Empty Stage'));
checkSame('list: fields, alias, order, limit and offset', runSafely(function(){
	$showLst = new DBLst('shows');
	$showLst->fieldScop('title as name, seats');
	$showLst->listOrder(Array('^seats', 'title'));
	$showLst->listLimit('2,1');
	return $showLst->readItems();
}), Array(Array('seats'=>80, 'name'=>'Alpha Matinee'), Array('seats'=>80, 'name'=>'Beta Evening')));
checkSame('list: an order written as plain field names in the constructor', runSafely(function(){
	$evntLst = new DBLst('events', null, null, 'date DESC', '2');
	return array_column($evntLst->readItems(), 'date');
}), Array('2026-12-28', '2026-11-03'));
checkSame('list: child lists', runSafely(function(){
	$showLst = new DBLst('shows');
	$showLst->lstSearch(Array('title~'=>'*a Night'));
	$showLst->readItems();
	$evntLst = $showLst->childList('events', 'date', 'show_id', '_id')[0]['events'];
	sort($evntLst);
	return $evntLst;
}), Array('2026-11-03', '2026-12-28'));
checkLike('list: a function is refused', runSafely(function(){ $showLst = new DBLst('shows'); $showLst->fieldScop('count(*) as nb'); return $showLst->readItems(); }), '/^THROWN RuntimeException: Functions are not available on a document store/');
checkLike('list: a join is refused', runSafely(function(){ $evntLst = new DBLst('events'); $evntLst->tableJoin(Array('shows', 'show_id', '_id')); return $evntLst->readItems(); }), '/^THROWN RuntimeException: Joins are not available on a document store/');

// Records
$newShow = runSafely(function(){
	$showRec = new DBRec('shows');
	$showRec->bindArray(Array('title'=>'Gamma', 'venue'=>"O'Hall", 'seats'=>50, 'tags'=>Array('new', 'small'), 'not a field'=>'x'));
	return $showRec->saveRecrd();
});
checkLike('record: an insert returns the key given by the server', $newShow, '/^[0-9a-f]{24}$/');
checkSame('record: loaded by its key, with every field it was given', runSafely(function() use ($newShow){
	$showRec = new DBRec('shows');
	return $showRec->loadRecrd(strval($newShow));
}), Array('_id'=>$newShow, 'title'=>'Gamma', 'venue'=>"O'Hall", 'seats'=>50, 'tags'=>Array('new', 'small')));
checkSame('record: update by key, linked rows are read and not saved', runSafely(function() use ($showKey){
	$showRec = new DBRec('shows', Array('events'=>Array('show_id', '_id', 'date ASC')));
	$recVals = $showRec->loadRecrd($showKey[0]);
	$showRec->bindArray(Array('venue'=>'Hall X'));
	$savedKy = $showRec->saveRecrd();
	$readRec = new DBRec('shows');
	return Array(array_column($recVals['Events'], 'date'), $savedKy, $readRec->loadRecrd($showKey[0]));
}), Array(Array('2026-11-03', '2026-12-28'), $showKey[0], Array('_id'=>$showKey[0], 'title'=>'Alpha Night', 'venue'=>'Hall X', 'seats'=>120)));
checkSame('record: saving without any change still succeeds', runSafely(function() use ($showKey){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd($showKey[1]);
	return $showRec->saveRecrd();
}), $showKey[1]);
checkSame('record: find, existing and missing keys', runSafely(function() use ($showKey){
	$showRec = new DBRec('shows');
	return Array($showRec->findRecrd(Array('title~'=>'beta*'))['_id'] ?? null, $showRec->exstRecrd($showKey[1]), $showRec->loadRecrd('000000000000000000000000'), $showRec->loadRecrd('not-a-key'), $showRec->exstRecrd('*'));
}), Array($showKey[1], true, false, false, false));
checkSame('record: deleted alone, without transactions', runSafely(function() use ($showKey){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd($showKey[2]);
	return Array($showRec->remvRecrd(), $showRec->remvRecrd(), docTitle(Array('title'=>'Alpha Matinee')));
}), Array(true, false, Array()));
checkLike('record: deleting with children is refused without transactions', runSafely(function() use ($showKey){
	$showRec = new DBRec('shows');
	$showRec->loadRecrd($showKey[0]);
	return $showRec->remvRecrd(Array('events'=>'show_id'));
}), '/^THROWN RuntimeException: Deleting a record with its children needs transactions/');
checkSame('...and nothing was deleted', runSafely(function() use ($dbStore, $showKey){ return Array(count($dbStore->findItems('events')), count($dbStore->findItems('shows', Array('_id='=>$showKey[0])))); }), Array(3, 1));

// Changes through the store
checkSame('store: update counts the rows of the filter', runSafely(function() use ($dbStore){
	return Array($dbStore->updateRow('shows', Array('seats'=>80), Array('venue'=>'Hall M')), $dbStore->updateRow('shows', Array('seats'=>999), Array('venue'=>'Hall M')), docTitle(Array('venue'=>'Hall M')));
}), Array(1, 0, Array('Beta Evening')));
checkSame('store: delete counts the rows of the filter', runSafely(function() use ($dbStore, $showKey){
	return Array($dbStore->deleteRow('events', Array('show_id'=>$showKey[1])), count($dbStore->findItems('events')));
}), Array(1, 2));
checkLike('store: a change without a filter is refused', runSafely(function() use ($dbStore){ return $dbStore->updateRow('shows', Array(), Array('venue'=>'Everywhere')); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkLike('store: a deletion with a filter that is always true is refused', runSafely(function() use ($dbStore){ return $dbStore->deleteRow('events', Array('show_id!'=>Array())); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkSame('...and nothing was changed', Array(docTitle(Array('venue'=>'Everywhere')), runSafely(function() use ($dbStore){ return count($dbStore->findItems('events')); })), Array(Array(), 2));

endChecks();
