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
	recordSkipping('MongoDB', 'the mongodb extension is not available');
	finishChecking();
}

$srvConf = serverSettings('mongo');
if( $srvConf['skip']!='' ){
	recordSkipping('MongoDB', $srvConf['skip']);
	finishChecking();
}
$prtNmbr = ($srvConf['portNumb']!='') ? intval($srvConf['portNumb']) : 27017;
$tryLink = @fsockopen($srvConf['hostName'], $prtNmbr, $errNmbr, $errText, 2);
if( !$tryLink ){
	recordSkipping('MongoDB', 'no server answers on '.$srvConf['hostName'].':'.$prtNmbr);
	finishChecking();
}
fclose($tryLink);

switchToServer('mongo', $srvConf);
$dbStore = runCatchThrown(function(){ return DBCon::linkNamedDbCon()->getStoreObject(); });
if( is_string($dbStore) ){
	recordSkipping('MongoDB', 'the store could not be opened: '.$dbStore);
	finishChecking();
}

// Empty the collections of the test: every document has an _id
foreach( Array('shows', 'events') as $tblNme ){
	if( $dbStore->deleteRowCount($tblNme, Array('_id?'=>true))===false ){
		recordSkipping('MongoDB', 'the server did not accept the test database: '.$dbStore->getLastErrText());
		finishChecking();
	}
}

$showKey = Array();
foreach( Array(
	Array('title'=>'Alpha Night',   'venue'=>'Hall A', 'seats'=>120),
	Array('title'=>'Beta Evening',  'venue'=>'Hall B', 'seats'=>80),
	Array('title'=>'Alpha Matinee', 'venue'=>'Hall C', 'seats'=>80),
	Array('title'=>'Empty Stage',   'venue'=>null,     'seats'=>0)
) as $showRow ){
	$showKey[] = $dbStore->insertTableRow('shows', $showRow);
}
foreach( Array(Array($showKey[0], '2026-12-28'), Array($showKey[0], '2026-11-03'), Array($showKey[1], '2026-11-02')) as $evntRow ){
	$dbStore->insertTableRow('events', Array('show_id'=>$evntRow[0], 'date'=>$evntRow[1]));
}

/**
 * Reads the titles a list gives, in title order.
 *
 * @param Array $whereArray Filter array.
 * @return mixed The titles, or a 'THROWN ...' string.
 */
function docTitle( Array $whereArray ): Mixed {
	return runCatchThrown(function() use ($whereArray){
		$showLst = new DBLst('shows');
		$showLst->setListFilters($whereArray);
		$showLst->setOrderFields('title');
		$fndRows = $showLst->readRowsOfList();
		return is_array($fndRows) ? array_column($fndRows, 'title') : $fndRows;
	});
}

// Connection and store
checkIdentical('the mongo type gives the MongoDB store, and no SQL handler', Array(get_class($dbStore), DBCon::linkNamedDbCon()->readConnection()), Array('nyu\Data\DBStore\MongoStore', null));
checkIdentical('the keys given by the server are 24 hexadecimal characters', array_map(function($givenValue){ return is_string($givenValue) && preg_match('/^[0-9a-f]{24}$/', $givenValue)==1; }, $showKey), Array(true, true, true, true));
checkIdentical('getInfoOfTable', $dbStore->getInfoOfTable('shows'), Array('flds'=>Array(), 'pkey'=>'_id', 'blob'=>Array()));
checkIdentical('there are no transactions', Array($dbStore->canTransaction(), $dbStore->addTransaction()), Array(false, false));

// Lists
checkIdentical('list: every row', docTitle(Array()), Array('Alpha Matinee', 'Alpha Night', 'Beta Evening', 'Empty Stage'));
checkIdentical('list: equal', docTitle(Array('venue'=>'Hall B')), Array('Beta Evening'));
checkIdentical('list: not equal leaves out the rows left unset, as SQL does', docTitle(Array('venue!'=>'Hall B')), Array('Alpha Matinee', 'Alpha Night'));
checkIdentical('list: wildcard, whatever the case', docTitle(Array('title~'=>'alpha*')), Array('Alpha Matinee', 'Alpha Night'));
checkIdentical('list: negated wildcard', docTitle(Array('title!~'=>'Alpha*')), Array('Beta Evening', 'Empty Stage'));
checkIdentical('list: an exact value keeps its wildcard characters', docTitle(Array('title='=>'Alpha*')), Array());
checkIdentical('list: comparison and list of values', docTitle(Array('seats>='=>80, 'venue'=>Array('Hall B', 'Hall C'))), Array('Alpha Matinee', 'Beta Evening'));
checkIdentical('list: excluded list of values', docTitle(Array('venue!'=>Array('Hall A', 'Hall B'))), Array('Alpha Matinee'));
checkIdentical('list: strict comparisons on one field', docTitle(Array('seats>'=>0, 'seats<'=>120)), Array('Alpha Matinee', 'Beta Evening'));
checkIdentical('list: field left unset, and set', Array(docTitle(Array('venue?'=>false)), docTitle(Array('venue?'=>true))), Array(Array('Empty Stage'), Array('Alpha Matinee', 'Alpha Night', 'Beta Evening')));
checkIdentical('list: a null value matches nothing', docTitle(Array('venue'=>null)), Array());
checkIdentical('list: values are compared with their type', Array(docTitle(Array('seats'=>120)), docTitle(Array('seats'=>'120'))), Array(Array('Alpha Night'), Array()));
checkIdentical('list: an operator sent as a value matches nothing', docTitle(Array('title'=>Array('$ne'=>''))), Array());
checkIdentical('list: a row found by its key', docTitle(Array('_id'=>$showKey[1])), Array('Beta Evening'));
checkIdentical('list: rows found by a list of keys', docTitle(Array('_id'=>Array($showKey[0], $showKey[3]))), Array('Alpha Night', 'Empty Stage'));
checkIdentical('list: fields, alias, order, limit and offset', runCatchThrown(function(){
	$showLst = new DBLst('shows');
	$showLst->setReadColumns('title as name, seats');
	$showLst->setOrderFields(Array('^seats', 'title'));
	$showLst->setLimitOffset('2,1');
	return $showLst->readRowsOfList();
}), Array(Array('seats'=>80, 'name'=>'Alpha Matinee'), Array('seats'=>80, 'name'=>'Beta Evening')));
checkIdentical('list: an order written as plain field names in the constructor', runCatchThrown(function(){
	$evntLst = new DBLst('events', null, null, 'date DESC', '2');
	return array_column($evntLst->readRowsOfList(), 'date');
}), Array('2026-12-28', '2026-11-03'));
checkIdentical('list: child lists', runCatchThrown(function(){
	$showLst = new DBLst('shows');
	$showLst->setListFilters(Array('title~'=>'*a Night'));
	$showLst->readRowsOfList();
	$evntLst = $showLst->loadChildRecrd('events', 'date', 'show_id', '_id')[0]['events'];
	sort($evntLst);
	return $evntLst;
}), Array('2026-11-03', '2026-12-28'));
checkPregMatch('list: a function is refused', runCatchThrown(function(){ $showLst = new DBLst('shows'); $showLst->setReadColumns('count(*) as nb'); return $showLst->readRowsOfList(); }), '/^THROWN RuntimeException: Functions are not available on a document store/');
checkPregMatch('list: a join is refused', runCatchThrown(function(){ $evntLst = new DBLst('events'); $evntLst->joinOtherTable(Array('shows', 'show_id', '_id')); return $evntLst->readRowsOfList(); }), '/^THROWN RuntimeException: Joins are not available on a document store/');

// Records
$newShow = runCatchThrown(function(){
	$showRec = new DBRec('shows');
	$showRec->setFieldsArray(Array('title'=>'Gamma', 'venue'=>"O'Hall", 'seats'=>50, 'tags'=>Array('new', 'small'), 'not a field'=>'x'));
	return $showRec->insertOrUpdate();
});
checkPregMatch('record: an insert returns the key given by the server', $newShow, '/^[0-9a-f]{24}$/');
checkIdentical('record: loaded by its key, with every field it was given', runCatchThrown(function() use ($newShow){
	$showRec = new DBRec('shows');
	return $showRec->loadRecordList(strval($newShow));
}), Array('_id'=>$newShow, 'title'=>'Gamma', 'venue'=>"O'Hall", 'seats'=>50, 'tags'=>Array('new', 'small')));
checkIdentical('record: update by key, linked rows are read and not saved', runCatchThrown(function() use ($showKey){
	$showRec = new DBRec('shows', Array('events'=>Array('show_id', '_id', 'date ASC')));
	$recVals = $showRec->loadRecordList($showKey[0]);
	$showRec->setFieldsArray(Array('venue'=>'Hall X'));
	$savedKy = $showRec->insertOrUpdate();
	$readRec = new DBRec('shows');
	return Array(array_column($recVals['Events'], 'date'), $savedKy, $readRec->loadRecordList($showKey[0]));
}), Array(Array('2026-11-03', '2026-12-28'), $showKey[0], Array('_id'=>$showKey[0], 'title'=>'Alpha Night', 'venue'=>'Hall X', 'seats'=>120)));
checkIdentical('record: saving without any change still succeeds', runCatchThrown(function() use ($showKey){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList($showKey[1]);
	return $showRec->insertOrUpdate();
}), $showKey[1]);
checkIdentical('record: find, existing and missing keys', runCatchThrown(function() use ($showKey){
	$showRec = new DBRec('shows');
	return Array($showRec->findRecordList(Array('title~'=>'beta*'))['_id'] ?? null, $showRec->hasRecordOfKey($showKey[1]), $showRec->loadRecordList('000000000000000000000000'), $showRec->loadRecordList('not-a-key'), $showRec->hasRecordOfKey('*'));
}), Array($showKey[1], true, false, false, false));
checkIdentical('record: deleted alone, without transactions', runCatchThrown(function() use ($showKey){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList($showKey[2]);
	return Array($showRec->dropThisRecord(), $showRec->dropThisRecord(), docTitle(Array('title'=>'Alpha Matinee')));
}), Array(true, false, Array()));
checkPregMatch('record: deleting with children is refused without transactions', runCatchThrown(function() use ($showKey){
	$showRec = new DBRec('shows');
	$showRec->loadRecordList($showKey[0]);
	return $showRec->dropThisRecord(Array('events'=>'show_id'));
}), '/^THROWN RuntimeException: Deleting a record with its children needs transactions/');
checkIdentical('...and nothing was deleted', runCatchThrown(function() use ($dbStore, $showKey){ return Array(count($dbStore->findItemsArray('events')), count($dbStore->findItemsArray('shows', Array('_id='=>$showKey[0])))); }), Array(3, 1));

// Changes through the store
checkIdentical('store: update counts the rows of the filter', runCatchThrown(function() use ($dbStore){
	return Array($dbStore->updateRowCount('shows', Array('seats'=>80), Array('venue'=>'Hall M')), $dbStore->updateRowCount('shows', Array('seats'=>999), Array('venue'=>'Hall M')), docTitle(Array('venue'=>'Hall M')));
}), Array(1, 0, Array('Beta Evening')));
checkIdentical('store: delete counts the rows of the filter', runCatchThrown(function() use ($dbStore, $showKey){
	return Array($dbStore->deleteRowCount('events', Array('show_id'=>$showKey[1])), count($dbStore->findItemsArray('events')));
}), Array(1, 2));
checkPregMatch('store: a change without a filter is refused', runCatchThrown(function() use ($dbStore){ return $dbStore->updateRowCount('shows', Array(), Array('venue'=>'Everywhere')); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkPregMatch('store: a deletion with a filter that is always true is refused', runCatchThrown(function() use ($dbStore){ return $dbStore->deleteRowCount('events', Array('show_id!'=>Array())); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkIdentical('...and nothing was changed', Array(docTitle(Array('venue'=>'Everywhere')), runCatchThrown(function() use ($dbStore){ return count($dbStore->findItemsArray('events')); })), Array(Array(), 2));

finishChecking();
