<?php

// A Record and a List on a store that is not SQL: the in-memory store of
// test/lib/ArrayStore.php, which behaves like a document store. Then what
// can be checked of the MongoDB store without a server: the query it
// writes from a filter, and its connection string.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/ArrayStore.php';

use nyu\Data\DBCon;
use nyu\Data\DBLst;
use nyu\Data\DBRec;
use nyu\Data\DBStore\ArrayStore;
use nyu\Data\DBStore\MongoStore;
use nyu\Data\DBStore\Filter;

$docStor = new ArrayStore();
$docLink = DBCon::makeByStoreObj($docStor);

foreach( Array(
	Array('title'=>'Alpha Night',   'venue'=>'Hall A', 'seats'=>120),
	Array('title'=>'Beta Evening',  'venue'=>'Hall B', 'seats'=>80),
	Array('title'=>'Alpha Matinee', 'venue'=>'Hall C', 'seats'=>80),
	Array('title'=>'Empty Stage',   'venue'=>null,     'seats'=>0)
) as $showRow ){
	$docStor->insertTableRow('shows', $showRow);
}
foreach( Array(Array('show_id'=>'key1', 'date'=>'2026-12-28'), Array('show_id'=>'key1', 'date'=>'2026-11-03'), Array('show_id'=>'key2', 'date'=>'2026-11-02')) as $evntRow ){
	$docStor->insertTableRow('events', $evntRow);
}

/**
 * Reads the titles a list gives, on the in-memory store.
 *
 * @param Array $whereArray Filter array.
 * @return mixed The titles, in title order, or a 'THROWN ...' string.
 */
function docTitle( Array $whereArray ): Mixed {
	return runCatchThrown(function() use ($whereArray){
		$showLst = new DBLst('shows', null, null, null, null, $GLOBALS['docLink']);
		$showLst->setListFilters($whereArray);
		$showLst->setOrderFields('title');
		return array_column($showLst->readRowsOfList(), 'title');
	});
}

// Lists
checkIdentical('list: every row', docTitle(Array()), Array('Alpha Matinee', 'Alpha Night', 'Beta Evening', 'Empty Stage'));
checkIdentical('list: equal', docTitle(Array('venue'=>'Hall B')), Array('Beta Evening'));
checkIdentical('list: not equal leaves out the rows left unset, as SQL does', docTitle(Array('venue!'=>'Hall B')), Array('Alpha Matinee', 'Alpha Night'));
checkIdentical('list: wildcard, whatever the case', docTitle(Array('title~'=>'alpha*')), Array('Alpha Matinee', 'Alpha Night'));
checkIdentical('list: comparison and list of values', docTitle(Array('seats>='=>80, 'venue'=>Array('Hall B', 'Hall C'))), Array('Alpha Matinee', 'Beta Evening'));
checkIdentical('list: field left unset', docTitle(Array('venue?'=>false)), Array('Empty Stage'));
checkIdentical('list: a null value matches nothing', docTitle(Array('venue'=>null)), Array());
checkIdentical('list: values are compared with their type', Array(docTitle(Array('seats'=>120)), docTitle(Array('seats'=>'120'))), Array(Array('Alpha Night'), Array()));
checkIdentical('list: fields, alias, order, limit and offset', runCatchThrown(function() use ($docLink){
	$showLst = new DBLst('shows', null, null, null, null, $docLink);
	$showLst->setReadColumns('title as name, seats');
	$showLst->setOrderFields(Array('^seats', 'title'));
	$showLst->setLimitOffset('2,1');
	return $showLst->readRowsOfList();
}), Array(Array('name'=>'Alpha Matinee', 'seats'=>80), Array('name'=>'Beta Evening', 'seats'=>80)));
checkIdentical('list: an order written as plain field names in the constructor works', runCatchThrown(function() use ($docLink){
	$evntLst = new DBLst('events', null, null, 'date DESC', '2', $docLink);
	return array_column($evntLst->readRowsOfList(), 'date');
}), Array('2026-12-28', '2026-11-03'));
checkIdentical('list: child lists', runCatchThrown(function() use ($docLink){
	$showLst = new DBLst('shows', null, null, null, null, $docLink);
	$showLst->setListFilters(Array('title~'=>'*a Night'));
	$showLst->readRowsOfList();
	return count($showLst->loadChildRecrd('events', 'date', 'show_id', '_id')[0]['events']);
}), 2);

// What only the SQL types do
checkPregMatch('list: a function is refused', runCatchThrown(function() use ($docLink){ $showLst = new DBLst('shows', null, null, null, null, $docLink); $showLst->setReadColumns('count(*) as nb'); return $showLst->readRowsOfList(); }), '/^THROWN RuntimeException: Functions are not available on a document store/');
checkPregMatch('list: a join is refused', runCatchThrown(function() use ($docLink){ $showLst = new DBLst('events', null, null, null, null, $docLink); $showLst->joinOtherTable(Array('shows', 'show_id', '_id')); return $showLst->readRowsOfList(); }), '/^THROWN RuntimeException: Joins are not available on a document store/');
checkPregMatch('list: a group is refused', runCatchThrown(function() use ($docLink){ $showLst = new DBLst('shows', null, null, null, null, $docLink); $showLst->setGroupFields('venue'); return $showLst->readRowsOfList(); }), '/^THROWN RuntimeException: Groups are not available on a document store/');
checkPregMatch('list: a where clause written as SQL is refused', runCatchThrown(function() use ($docLink){ $showLst = new DBLst('shows', null, "venue = 'Hall A'", null, null, $docLink); return $showLst->readRowsOfList(); }), '/^THROWN RuntimeException: Fragments written as SQL are not available on a document store/');

// Records
checkIdentical('record: the key is _id, and any plain field is taken', runCatchThrown(function() use ($docLink){
	$showRec = new DBRec('shows', Array(), Array(), $docLink);
	$showRec->setFieldsArray(Array('title'=>'Gamma', 'venue'=>'Hall G', 'seats'=>50, 'tags'=>Array('new'), 'not a field'=>'x'));
	$savedKy = $showRec->insertOrUpdate();
	$readRec = new DBRec('shows', Array(), Array(), $docLink);
	return Array($savedKy, $readRec->loadRecordList($savedKy));
}), Array('key8', Array('_id'=>'key8', 'title'=>'Gamma', 'venue'=>'Hall G', 'seats'=>50, 'tags'=>Array('new'))));
checkIdentical('record: update by key, linked rows are not saved', runCatchThrown(function() use ($docLink){
	$showRec = new DBRec('shows', Array('events'=>Array('show_id', '_id', 'date ASC')), Array(), $docLink);
	$recVals = $showRec->loadRecordList('key1');
	$showRec->setFieldsArray(Array('venue'=>'Hall X'));
	$savedKy = $showRec->insertOrUpdate();
	$readRec = new DBRec('shows', Array(), Array(), $docLink);
	return Array(array_column($recVals['Events'], 'date'), $savedKy, $readRec->loadRecordList('key1'));
}), Array(Array('2026-11-03', '2026-12-28'), 'key1', Array('_id'=>'key1', 'title'=>'Alpha Night', 'venue'=>'Hall X', 'seats'=>120)));
checkIdentical('record: find, and a missing key', runCatchThrown(function() use ($docLink){
	$showRec = new DBRec('shows', Array(), Array(), $docLink);
	return Array($showRec->findRecordList(Array('title~'=>'beta*'))['_id'] ?? null, $showRec->loadRecordList('key99'), $showRec->hasRecordOfKey('key2'), $showRec->hasRecordOfKey('key*'));
}), Array('key2', false, true, false));
checkIdentical('record: deleted alone, without transactions', runCatchThrown(function() use ($docLink){
	$showRec = new DBRec('shows', Array(), Array(), $docLink);
	$showRec->loadRecordList('key3');
	return Array($showRec->dropThisRecord(), $showRec->dropThisRecord(), docTitle(Array('title'=>'Alpha Matinee')));
}), Array(true, false, Array()));
checkPregMatch('record: deleting with children is refused without transactions', runCatchThrown(function() use ($docLink){
	$showRec = new DBRec('shows', Array(), Array(), $docLink);
	$showRec->loadRecordList('key1');
	return $showRec->dropThisRecord(Array('events'=>'show_id'));
}), '/^THROWN RuntimeException: Deleting a record with its children needs transactions/');
checkIdentical('...and nothing was deleted', runCatchThrown(function() use ($docStor){ return Array(count($docStor->findItemsArray('events')), count($docStor->findItemsArray('shows', Array('_id='=>'key1')))); }), Array(3, 1));
checkPregMatch('store: a deletion without a filter is refused', runCatchThrown(function() use ($docStor){ return $docStor->deleteRowCount('events', Array()); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');

// MongoDB store: the query written from a filter
checkIdentical('mongo: an empty filter is an empty query', runCatchThrown(function(){ return MongoStore::makeQueryArray(Array()); }), Array());
checkIdentical('mongo: comparisons', runCatchThrown(function(){
	return MongoStore::makeQueryArray(Array('venue'=>'Hall B', 'seats>='=>80, 'seats<'=>200, 'title='=>'50%'));
}), Array('$and'=>Array(
	Array('venue'=>Array('$eq'=>'Hall B')),
	Array('seats'=>Array('$gte'=>80)),
	Array('seats'=>Array('$lt'=>200)),
	Array('title'=>Array('$eq'=>'50%'))
)));
checkIdentical('mongo: not equal and lists leave out the rows left unset', runCatchThrown(function(){
	return MongoStore::makeQueryArray(Array('venue!'=>'Hall B', 'seats'=>Array(80, 120), 'seats!'=>Array(0)));
}), Array('$and'=>Array(
	Array('venue'=>Array('$nin'=>Array('Hall B', null))),
	Array('seats'=>Array('$in'=>Array(80, 120))),
	Array('seats'=>Array('$nin'=>Array(0, null)))
)));
checkIdentical('mongo: a star written \* is a star, a quoted search matches only itself', Array(MongoStore::makeQueryArray(Array('title~'=>'A\*B*'))['$and'][0]['title']['$regex'] ?? null, MongoStore::makeQueryArray(Array('title~'=>'*'.Filter::quoteMatchText('a.*').'*'))['$and'][0]['title']['$regex'] ?? null), Array('^A\*B.*$', '^.*a\.\*.*$'));
checkIdentical('mongo: wildcards become anchored expressions that ignore the case', runCatchThrown(function(){
	return MongoStore::makeQueryArray(Array('title~'=>'Alpha*', 'venue!~'=>'Hall (A)*'));
}), Array('$and'=>Array(
	Array('title'=>Array('$regex'=>'^Alpha.*$', '$options'=>'is')),
	Array('venue'=>Array('$not'=>Array('$regex'=>'^Hall \(A\).*$', '$options'=>'is'), '$ne'=>null))
)));
checkIdentical('mongo: set and unset fields', runCatchThrown(function(){
	return MongoStore::makeQueryArray(Array('venue?'=>false, 'title?'=>true));
}), Array('$and'=>Array(Array('venue'=>Array('$eq'=>null)), Array('title'=>Array('$ne'=>null)))));
checkIdentical('mongo: what matches nothing, and what matches everything', runCatchThrown(function(){
	$noneQry = MongoStore::makeQueryArray(Array('venue'=>null));
	return Array(array_keys($noneQry['$and'][0]), count($noneQry['$and'][0]['$nor']), MongoStore::makeQueryArray(Array('seats!'=>Array())));
}), Array(Array('$nor'), 1, Array()));
checkPregMatch('mongo: a field name cannot carry an operator', runCatchThrown(function(){ return MongoStore::makeQueryArray(Array('$where'=>'sleep(1000)')); }), '/^THROWN RuntimeException: Invalid table or field name/');
checkIdentical('mongo: a value is never read as an operator', runCatchThrown(function(){ return MongoStore::makeQueryArray(Array('pass'=>Array('$ne'=>'x')))['$and'][0]; }), Array('pass'=>Array('$in'=>Array('x'))));
checkIdentical('mongo: the usual injection through a value matches nothing', runCatchThrown(function(){ return array_keys(MongoStore::makeQueryArray(Array('pass'=>Array('$ne'=>'')))['$and'][0]); }), Array('$nor'));

// MongoDB store: connection string and availability
checkIdentical('mongo: connection string', runCatchThrown(function(){ return MongoStore::makeDsnForHost('', '', '', 'shop'); }), 'mongodb://localhost/shop');
checkIdentical('mongo: connection string with an account and a port', runCatchThrown(function(){ return MongoStore::makeDsnForHost('db.local', 'web@app', 'p:ss/w?rd', 'shop', Array('portNumb'=>'27018')); }), 'mongodb://web%40app:p%3Ass%2Fw%3Frd@db.local:27018/shop');
checkIdentical('mongo: connection string with the database of the account and a waiting time', runCatchThrown(function(){ return MongoStore::makeDsnForHost('localhost', 'web', 'pw', 'shop', Array('authBase'=>'admin', 'waitSecs'=>'5')); }), 'mongodb://web:pw@localhost/shop?authSource=admin&serverSelectionTimeoutMS=5000');
checkIdentical('mongo: settings of the SQL types are left aside', runCatchThrown(function(){ return MongoStore::makeDsnForHost('localhost', '', '', 'shop', Array('portNumb'=>'', 'encoding'=>'utf8', 'authBase'=>'', 'waitSecs'=>'')); }), 'mongodb://localhost/shop');
checkPregMatch('mongo: an account database that is not a plain name is refused', runCatchThrown(function(){ return MongoStore::makeDsnForHost('localhost', 'web', 'pw', 'shop', Array('authBase'=>'admin&tls=false')); }), '/^THROWN RuntimeException: Invalid account database name/');
checkPregMatch('mongo: a waiting time that is not a number is refused', runCatchThrown(function(){ return MongoStore::makeDsnForHost('localhost', '', '', 'shop', Array('waitSecs'=>'5&x=1')); }), '/^THROWN RuntimeException: Invalid database waiting time/');
checkPregMatch('mongo: a database name that is not a plain name is refused', runCatchThrown(function(){ return MongoStore::makeDsnForHost('localhost', '', '', 'shop?authSource=admin'); }), '/^THROWN RuntimeException: Invalid database name/');
checkPregMatch('mongo: a host carrying anything else is refused', runCatchThrown(function(){ return MongoStore::makeDsnForHost('localhost,evil.example/x', '', '', 'shop'); }), '/^THROWN RuntimeException: Invalid database host/');
if( extension_loaded('mongodb') ){
	recordSkipping('mongo without its extension', 'the mongodb extension is loaded');
}else{
	checkPregMatch('mongo: without the extension, the type says what is missing', runCatchThrown(function(){ return DBCon::loadDefaultCon('mongo_db', 'localhost', '', '', 'shop'); }), '/^THROWN RuntimeException: The mongodb extension of PHP is not loaded/');
}

finishChecking();
