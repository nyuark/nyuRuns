<?php

// The checks every database type must pass, run on a real server by the
// dialect tests (test/data/MySQLTest.php). The sample tables of
// test/lib/servers.php must be in place, and the framework's database
// configuration pointed at them.

use nyu\Data\DBCon;
use nyu\Data\DBLst;
use nyu\Data\DBRec;

/**
 * Reads the keys of the shows matching a filter, in key order.
 *
 * @param Array $whereArray Filter array.
 * @return mixed The keys, as integers, or a 'THROWN ...' string.
 */
function showKeys( Array $whereArray ): Mixed {
	return runSafely(function() use ($whereArray){
		$showLst = new DBLst('shows');
		$showLst->lstSearch($whereArray);
		$showLst->listOrder('id_show');
		return array_map('intval', array_column($showLst->readItems(), 'id_show'));
	});
}

/**
 * Runs the dialect checks.
 *
 * @param String $handlerCls Class the handler is expected to be.
 */
function runDialct( String $handlerCls ): Void {
	$nyuBase = DBCon::baseSetup();
	$dbHandl = DBCon::getInstnc($nyuBase['type'], $nyuBase['host'], $nyuBase['user'], $nyuBase['pass'], $nyuBase['base'], Array('port'=>$nyuBase['port'], 'char'=>$nyuBase['char']))->getConnex();

	// Handler
	checkSame('the handler of the type is used', get_class($dbHandl), $handlerCls);
	checkSame('tableInfo gives the fields and the key', runSafely(function() use ($dbHandl){ return $dbHandl->tableInfo('shows'); }), Array('flds'=>Array('id_show', 'title', 'venue'), 'pkey'=>'id_show', 'blob'=>Array()));
	checkSame('tableInfo of a missing table', runSafely(function() use ($dbHandl){ return $dbHandl->tableInfo('nowhere'); }), false);
	checkSame('a failed query gives false and an error', runSafely(function() use ($dbHandl){
		return Array($dbHandl->sendQuery('SELECT * FROM '.$dbHandl->quoteName('nowhere')), is_string($dbHandl->lastError()));
	}), Array(false, true));
	checkSame('a read with a bound value', runSafely(function() use ($dbHandl){
		$dbHandl->sendQuery('SELECT '.$dbHandl->quoteName('title').' FROM '.$dbHandl->quoteName('shows').' WHERE '.$dbHandl->quoteName('id_show').'=?', false, Array(2));
		return $dbHandl->readQuery(true);
	}), Array(Array('title'=>'Beta Evening')));
	checkSame('a write counts the rows it changed', runSafely(function() use ($dbHandl){
		$updQury = 'UPDATE '.$dbHandl->quoteName('shows').' SET '.$dbHandl->quoteName('venue').'=? WHERE '.$dbHandl->quoteName('id_show').'=?';
		return Array($dbHandl->sendQuery($updQury, true, Array('Hall Z', 4)), $dbHandl->lastCount(), $dbHandl->sendQuery($updQury, true, Array('Hall Z', 999)), $dbHandl->lastCount());
	}), Array(true, 1, true, 0));
	checkSame('a removed transaction leaves the table unchanged', runSafely(function() use ($dbHandl){
		$dbHandl->opnTrnsct();
		$dbHandl->sendQuery('UPDATE '.$dbHandl->quoteName('shows').' SET '.$dbHandl->quoteName('venue').'=? WHERE '.$dbHandl->quoteName('id_show').'=?', true, Array('Undone', 4));
		$dbHandl->remTrnsct();
		$showRec = new DBRec('shows');
		return $showRec->loadRecrd('4')['venue'];
	}), 'Hall Z');

	// Filters
	checkSame('filter: equal',                 showKeys(Array('venue'=>'Hall B')), Array(2));
	checkSame('filter: wildcard, whatever the case', showKeys(Array('title~'=>'alpha*')), Array(1, 3));
	checkSame('filter: negated wildcard',      showKeys(Array('title!~'=>'Alpha*')), Array(2, 4, 5));
	checkSame('filter: list and comparison',   showKeys(Array('id_show'=>Array(1, 2, 3), 'id_show>'=>1)), Array(2, 3));
	checkSame('filter: field left unset',      showKeys(Array('venue?'=>false)), Array(5));
	checkSame('filter: a null value matches nothing', showKeys(Array('venue'=>null)), Array());
	checkSame('filter: a value holding a quote', showKeys(Array('venue'=>"Hall A' OR '1'='1")), Array());

	// Lists
	checkSame('list: order, limit and offset', runSafely(function(){
		$showLst = new DBLst('shows');
		$showLst->listOrder('^id_show');
		$showLst->listLimit('2,1');
		return array_map('intval', array_column($showLst->readItems(), 'id_show'));
	}), Array(4, 3));
	checkSame('list: grouped count', runSafely(function(){
		$itemLst = new DBLst('items');
		$itemLst->fieldScop('type, count(*) as nb');
		$itemLst->grpFields('type');
		$itemLst->listOrder('type');
		return array_map(function($listedRow){ return $listedRow['type'].'='.intval($listedRow['nb']); }, $itemLst->readItems());
	}), Array('circus=1', 'dance=2', 'music=2', 'solo=1', 'theatre=1'));
	checkSame('list: join', runSafely(function(){
		$evntLst = new DBLst('events');
		$evntLst->fieldScop('shows.title, events.date');
		$evntLst->tableJoin(Array('shows', 'events.show_id', 'shows.id_show'));
		$evntLst->lstSearch(Array('events.id_event'=>7));
		return $evntLst->readItems();
	}), Array(Array('title'=>'Solo Gig', 'date'=>'2026-12-01')));

	// Records
	checkSame('record: load with its children', runSafely(function(){
		$showRec = new DBRec('shows', Array('events'));
		$recVals = $showRec->loadRecrd('2');
		return Array($recVals['title'], array_column($recVals['Events'], 'date'));
	}), Array('Beta Evening', Array('2026-11-02', '2026-11-09')));
	checkSame('record: find, whatever the case', runSafely(function(){
		$showRec = new DBRec('shows');
		return $showRec->findRecrd(Array('title~'=>'beta*'))['venue'] ?? null;
	}), 'Hall B');
	checkSame('record: an update keeps quotes and sets NULL', runSafely(function(){
		$showRec = new DBRec('shows');
		$showRec->loadRecrd('3');
		$showRec->bindArray(Array('title'=>"O'Matinee", 'venue'=>null));
		$savedKy = $showRec->saveRecrd();
		$readRec = new DBRec('shows');
		$recVals = $readRec->loadRecrd('3');
		return Array(strval($savedKy), $recVals['title'], $recVals['venue']);
	}), Array('3', "O'Matinee", null));
	checkSame('record: saving without any change still succeeds', runSafely(function(){
		$showRec = new DBRec('shows');
		$showRec->loadRecrd('1');
		return strval($showRec->saveRecrd());
	}), '1');
	checkSame('record: an insert returns the new key', runSafely(function(){
		$showRec = new DBRec('shows');
		$showRec->bindArray(Array('title'=>'Gamma', 'venue'=>'Hall G'));
		$savedKy = $showRec->saveRecrd();
		$readRec = new DBRec('shows');
		return Array(strval($savedKy), $readRec->loadRecrd(strval($savedKy))['title'] ?? null);
	}), Array('6', 'Gamma'));
	checkSame('record: delete with its named children, and only those', runSafely(function(){
		$showRec = new DBRec('shows');
		$showRec->loadRecrd('1');
		$remDone = $showRec->remvRecrd(Array('events'));
		$evntLst = new DBLst('events');
		$itemLst = new DBLst('items');
		$itemLst->lstSearch(Array('show_id'=>1));
		return Array($remDone, showKeys(Array('id_show'=>1)), count($evntLst->readItems()), count($itemLst->readItems()));
	}), Array(true, Array(), 5, 2));
	checkSame('record: a failing child table cancels the deletion', runSafely(function(){
		$showRec = new DBRec('shows');
		$showRec->loadRecrd('2');
		$evntLst = new DBLst('events');
		$evntLst->lstSearch(Array('show_id'=>2));
		return Array($showRec->remvRecrd(Array('events', 'nowhere')), showKeys(Array('id_show'=>2)), count($evntLst->readItems()));
	}), Array(false, Array(2), 2));
}
