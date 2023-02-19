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

$docStor = new ArrayStore();
$docLink = DBCon::fromStore($docStor);

foreach( Array(
	Array('title'=>'Alpha Night',   'venue'=>'Hall A', 'seats'=>120),
	Array('title'=>'Beta Evening',  'venue'=>'Hall B', 'seats'=>80),
	Array('title'=>'Alpha Matinee', 'venue'=>'Hall C', 'seats'=>80),
	Array('title'=>'Empty Stage',   'venue'=>null,     'seats'=>0)
) as $showRow ){
	$docStor->insertRow('shows', $showRow);
}
foreach( Array(Array('show_id'=>'key1', 'date'=>'2026-12-28'), Array('show_id'=>'key1', 'date'=>'2026-11-03'), Array('show_id'=>'key2', 'date'=>'2026-11-02')) as $evntRow ){
	$docStor->insertRow('events', $evntRow);
}

/**
 * Reads the titles a list gives, on the in-memory store.
 *
 * @param Array $whereArray Filter array.
 * @return mixed The titles, in title order, or a 'THROWN ...' string.
 */
function docTitle( Array $whereArray ): Mixed {
	return runSafely(function() use ($whereArray){
		$showLst = new DBLst('shows', null, null, null, null, $GLOBALS['docLink']);
		$showLst->lstSearch($whereArray);
		$showLst->listOrder('title');
		return array_column($showLst->readItems(), 'title');
	});
}

// Lists
checkSame('list: every row', docTitle(Array()), Array('Alpha Matinee', 'Alpha Night', 'Beta Evening', 'Empty Stage'));
checkSame('list: equal', docTitle(Array('venue'=>'Hall B')), Array('Beta Evening'));
checkSame('list: not equal leaves out the rows left unset, as SQL does', docTitle(Array('venue!'=>'Hall B')), Array('Alpha Matinee', 'Alpha Night'));
checkSame('list: wildcard, whatever the case', docTitle(Array('title'=>'alpha*')), Array('Alpha Matinee', 'Alpha Night'));
checkSame('list: comparison and list of values', docTitle(Array('seats>='=>80, 'venue'=>Array('Hall B', 'Hall C'))), Array('Alpha Matinee', 'Beta Evening'));
checkSame('list: field left unset', docTitle(Array('venue?'=>false)), Array('Empty Stage'));
checkSame('list: a null value matches nothing', docTitle(Array('venue'=>null)), Array());
checkSame('list: values are compared with their type', Array(docTitle(Array('seats'=>120)), docTitle(Array('seats'=>'120'))), Array(Array('Alpha Night'), Array()));
checkSame('list: fields, alias, order, limit and offset', runSafely(function() use ($docLink){
	$showLst = new DBLst('shows', null, null, null, null, $docLink);
	$showLst->fieldScop('title as name, seats');
	$showLst->listOrder(Array('^seats', 'title'));
	$showLst->listLimit('2,1');
	return $showLst->readItems();
}), Array(Array('name'=>'Alpha Matinee', 'seats'=>80), Array('name'=>'Beta Evening', 'seats'=>80)));
checkSame('list: an order written as plain field names in the constructor works', runSafely(function() use ($docLink){
	$evntLst = new DBLst('events', null, null, 'date DESC', '2', $docLink);
	return array_column($evntLst->readItems(), 'date');
}), Array('2026-12-28', '2026-11-03'));
checkSame('list: child lists', runSafely(function() use ($docLink){
	$showLst = new DBLst('shows', null, null, null, null, $docLink);
	$showLst->lstSearch(Array('title'=>'*a Night'));
	$showLst->readItems();
	return count($showLst->childList('events', 'date', 'show_id', '_id')[0]['events']);
}), 2);

// What only the SQL types do
checkLike('list: a function is refused', runSafely(function() use ($docLink){ $showLst = new DBLst('shows', null, null, null, null, $docLink); $showLst->fieldScop('count(*) as nb'); return $showLst->readItems(); }), '/^THROWN RuntimeException: Functions are not available on a document store/');
checkLike('list: a join is refused', runSafely(function() use ($docLink){ $showLst = new DBLst('events', null, null, null, null, $docLink); $showLst->tableJoin(Array('shows', 'show_id', '_id')); return $showLst->readItems(); }), '/^THROWN RuntimeException: Joins are not available on a document store/');
checkLike('list: a group is refused', runSafely(function() use ($docLink){ $showLst = new DBLst('shows', null, null, null, null, $docLink); $showLst->grpFields('venue'); return $showLst->readItems(); }), '/^THROWN RuntimeException: Groups are not available on a document store/');
checkLike('list: a where clause written as SQL is refused', runSafely(function() use ($docLink){ $showLst = new DBLst('shows', null, "venue = 'Hall A'", null, null, $docLink); return $showLst->readItems(); }), '/^THROWN RuntimeException: Fragments written as SQL are not available on a document store/');

// Records
checkSame('record: the key is _id, and any plain field is taken', runSafely(function() use ($docLink){
	$showRec = new DBRec('shows', Array(), Array(), $docLink);
	$showRec->bindArray(Array('title'=>'Gamma', 'venue'=>'Hall G', 'seats'=>50, 'tags'=>Array('new'), 'not a field'=>'x'));
	$savedKy = $showRec->saveRecrd();
	$readRec = new DBRec('shows', Array(), Array(), $docLink);
	return Array($savedKy, $readRec->loadRecrd($savedKy));
}), Array('key8', Array('_id'=>'key8', 'title'=>'Gamma', 'venue'=>'Hall G', 'seats'=>50, 'tags'=>Array('new'))));
checkSame('record: update by key, linked rows are not saved', runSafely(function() use ($docLink){
	$showRec = new DBRec('shows', Array('events'=>Array('show_id', '_id', 'date ASC')), Array(), $docLink);
	$recVals = $showRec->loadRecrd('key1');
	$showRec->bindArray(Array('venue'=>'Hall X'));
	$savedKy = $showRec->saveRecrd();
	$readRec = new DBRec('shows', Array(), Array(), $docLink);
	return Array(array_column($recVals['Events'], 'date'), $savedKy, $readRec->loadRecrd('key1'));
}), Array(Array('2026-11-03', '2026-12-28'), 'key1', Array('_id'=>'key1', 'title'=>'Alpha Night', 'venue'=>'Hall X', 'seats'=>120)));
checkSame('record: find, and a missing key', runSafely(function() use ($docLink){
	$showRec = new DBRec('shows', Array(), Array(), $docLink);
	return Array($showRec->findRecrd(Array('title'=>'beta*'))['_id'] ?? null, $showRec->loadRecrd('key99'), $showRec->exstRecrd('key2'), $showRec->exstRecrd('key*'));
}), Array('key2', false, true, false));
checkSame('record: deleted alone, without transactions', runSafely(function() use ($docLink){
	$showRec = new DBRec('shows', Array(), Array(), $docLink);
	$showRec->loadRecrd('key3');
	return Array($showRec->remvRecrd(), $showRec->remvRecrd(), docTitle(Array('title'=>'Alpha Matinee')));
}), Array(true, false, Array()));
checkLike('record: deleting with children is refused without transactions', runSafely(function() use ($docLink){
	$showRec = new DBRec('shows', Array(), Array(), $docLink);
	$showRec->loadRecrd('key1');
	return $showRec->remvRecrd(Array('events'=>'show_id'));
}), '/^THROWN RuntimeException: Deleting a record with its children needs transactions/');
checkSame('...and nothing was deleted', runSafely(function() use ($docStor){ return Array(count($docStor->findItems('events')), count($docStor->findItems('shows', Array('_id='=>'key1')))); }), Array(3, 1));
checkLike('store: a deletion without a filter is refused', runSafely(function() use ($docStor){ return $docStor->deleteRow('events', Array()); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');

// MongoDB store: the query written from a filter
checkSame('mongo: an empty filter is an empty query', runSafely(function(){ return MongoStore::makeQuery(Array()); }), Array());
checkSame('mongo: comparisons', runSafely(function(){
	return MongoStore::makeQuery(Array('venue'=>'Hall B', 'seats>='=>80, 'seats<'=>200, 'title='=>'50%'));
}), Array('$and'=>Array(
	Array('venue'=>Array('$eq'=>'Hall B')),
	Array('seats'=>Array('$gte'=>80)),
	Array('seats'=>Array('$lt'=>200)),
	Array('title'=>Array('$eq'=>'50%'))
)));
checkSame('mongo: not equal and lists leave out the rows left unset', runSafely(function(){
	return MongoStore::makeQuery(Array('venue!'=>'Hall B', 'seats'=>Array(80, 120), 'seats!'=>Array(0)));
}), Array('$and'=>Array(
	Array('venue'=>Array('$nin'=>Array('Hall B', null))),
	Array('seats'=>Array('$in'=>Array(80, 120))),
	Array('seats'=>Array('$nin'=>Array(0, null)))
)));
checkSame('mongo: wildcards become anchored expressions that ignore the case', runSafely(function(){
	return MongoStore::makeQuery(Array('title'=>'Alpha*', 'venue!'=>'Hall (A)%'));
}), Array('$and'=>Array(
	Array('title'=>Array('$regex'=>'^Alpha.*$', '$options'=>'is')),
	Array('venue'=>Array('$not'=>Array('$regex'=>'^Hall \(A\).*$', '$options'=>'is'), '$ne'=>null))
)));
checkSame('mongo: set and unset fields', runSafely(function(){
	return MongoStore::makeQuery(Array('venue?'=>false, 'title?'=>true));
}), Array('$and'=>Array(Array('venue'=>Array('$eq'=>null)), Array('title'=>Array('$ne'=>null)))));
checkSame('mongo: what matches nothing, and what matches everything', runSafely(function(){
	$noneQry = MongoStore::makeQuery(Array('venue'=>null));
	return Array(array_keys($noneQry['$and'][0]), count($noneQry['$and'][0]['$nor']), MongoStore::makeQuery(Array('seats!'=>Array())));
}), Array(Array('$nor'), 1, Array()));
checkLike('mongo: a field name cannot carry an operator', runSafely(function(){ return MongoStore::makeQuery(Array('$where'=>'sleep(1000)')); }), '/^THROWN RuntimeException: Invalid table or field name/');
checkSame('mongo: a value is never read as an operator', runSafely(function(){ return MongoStore::makeQuery(Array('pass'=>Array('$ne'=>'x')))['$and'][0]; }), Array('pass'=>Array('$in'=>Array('x'))));
checkSame('mongo: the usual injection through a value matches nothing', runSafely(function(){ return array_keys(MongoStore::makeQuery(Array('pass'=>Array('$ne'=>'')))['$and'][0]); }), Array('$nor'));

// MongoDB store: connection string and availability
checkSame('mongo: connection string', runSafely(function(){ return MongoStore::dsnString('', '', '', 'shop'); }), 'mongodb://localhost/shop');
checkSame('mongo: connection string with an account and a port', runSafely(function(){ return MongoStore::dsnString('db.local', 'web@app', 'p:ss/w?rd', 'shop', Array('port'=>'27018')); }), 'mongodb://web%40app:p%3Ass%2Fw%3Frd@db.local:27018/shop');
checkSame('mongo: connection string with the database of the account and a waiting time', runSafely(function(){ return MongoStore::dsnString('localhost', 'web', 'pw', 'shop', Array('auth'=>'admin', 'wait'=>'5')); }), 'mongodb://web:pw@localhost/shop?authSource=admin&serverSelectionTimeoutMS=5000');
checkSame('mongo: settings of the SQL types are left aside', runSafely(function(){ return MongoStore::dsnString('localhost', '', '', 'shop', Array('port'=>'', 'char'=>'utf8', 'auth'=>'', 'wait'=>'')); }), 'mongodb://localhost/shop');
checkLike('mongo: an account database that is not a plain name is refused', runSafely(function(){ return MongoStore::dsnString('localhost', 'web', 'pw', 'shop', Array('auth'=>'admin&tls=false')); }), '/^THROWN RuntimeException: Invalid account database name/');
checkLike('mongo: a waiting time that is not a number is refused', runSafely(function(){ return MongoStore::dsnString('localhost', '', '', 'shop', Array('wait'=>'5&x=1')); }), '/^THROWN RuntimeException: Invalid database waiting time/');
checkLike('mongo: a database name that is not a plain name is refused', runSafely(function(){ return MongoStore::dsnString('localhost', '', '', 'shop?authSource=admin'); }), '/^THROWN RuntimeException: Invalid database name/');
checkLike('mongo: a host carrying anything else is refused', runSafely(function(){ return MongoStore::dsnString('localhost,evil.example/x', '', '', 'shop'); }), '/^THROWN RuntimeException: Invalid database host/');
if( extension_loaded('mongodb') ){
	skipCheck('mongo without its extension', 'the mongodb extension is loaded');
}else{
	checkLike('mongo: without the extension, the type says what is missing', runSafely(function(){ return DBCon::getInstnc('mongo', 'localhost', '', '', 'shop'); }), '/^THROWN RuntimeException: The mongodb extension of PHP is not loaded/');
}

endChecks();
