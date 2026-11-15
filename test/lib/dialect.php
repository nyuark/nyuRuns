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
 * @param Array $filterFields Filter array.
 * @return mixed The keys, as integers, or a 'THROWN ...' string.
 */
function fetchShowsKeys( Array $filterFields ): Mixed {
	return runCatchThrown(function() use ($filterFields){
		$showLst = new DBLst('shows');
		$showLst->replaceFilters($filterFields);
		$showLst->setOrderFields('id_show');
		return array_map('intval', array_column($showLst->readRowsOfList(), 'id_show'));
	});
}

/**
 * Runs the dialect checks.
 *
 * @param String $handlerClass Class the handler is expected to be.
 */
function executeDialect( String $handlerClass ): Void {
	$nyuBase = DBCon::baseSetupArray();
	$dbHndlr = DBCon::obtainInstance($nyuBase['dbEngine'], $nyuBase['hostName'], $nyuBase['userName'], $nyuBase['passWord'], $nyuBase['baseName'], Array('portNumb'=>$nyuBase['portNumb'], 'encoding'=>$nyuBase['encoding']))->readConnection();

	// Handler
	checkIdentical('the handler of the type is used', get_class($dbHndlr), $handlerClass);
	checkIdentical('tableInfo gives the fields and the key', runCatchThrown(function() use ($dbHndlr){ return $dbHndlr->tableInfoArray('shows'); }), Array('flds'=>Array('id_show', 'title', 'venue'), 'pkey'=>'id_show', 'blob'=>Array()));
	checkIdentical('tableInfo of a missing table', runCatchThrown(function() use ($dbHndlr){ return $dbHndlr->tableInfoArray('nowhere'); }), false);
	checkIdentical('a failed query gives false and an error', runCatchThrown(function() use ($dbHndlr){
		return Array($dbHndlr->sendBuiltQuery('SELECT * FROM '.$dbHndlr->quoteFieldName('nowhere')), is_string($dbHndlr->getLastErrText()));
	}), Array(false, true));
	checkIdentical('a read with a bound value', runCatchThrown(function() use ($dbHndlr){
		$dbHndlr->sendBuiltQuery('SELECT '.$dbHndlr->quoteFieldName('title').' FROM '.$dbHndlr->quoteFieldName('shows').' WHERE '.$dbHndlr->quoteFieldName('id_show').'=?', false, Array(2));
		return $dbHndlr->readLastResult(true);
	}), Array(Array('title'=>'Beta Evening')));
	checkIdentical('a write counts the rows it changed', runCatchThrown(function() use ($dbHndlr){
		$updtQry = 'UPDATE '.$dbHndlr->quoteFieldName('shows').' SET '.$dbHndlr->quoteFieldName('venue').'=? WHERE '.$dbHndlr->quoteFieldName('id_show').'=?';
		return Array($dbHndlr->sendBuiltQuery($updtQry, true, Array('Hall Z', 4)), $dbHndlr->countLastWrite(), $dbHndlr->sendBuiltQuery($updtQry, true, Array('Hall Z', 999)), $dbHndlr->countLastWrite());
	}), Array(true, 1, true, 0));
	checkIdentical('a removed transaction leaves the table unchanged', runCatchThrown(function() use ($dbHndlr){
		$dbHndlr->newTransaction();
		$dbHndlr->sendBuiltQuery('UPDATE '.$dbHndlr->quoteFieldName('shows').' SET '.$dbHndlr->quoteFieldName('venue').'=? WHERE '.$dbHndlr->quoteFieldName('id_show').'=?', true, Array('Undone', 4));
		$dbHndlr->delTransaction();
		$showRec = new DBRec('shows');
		return $showRec->loadRecordList('4')['venue'];
	}), 'Hall Z');

	// Filters
	checkIdentical('filter: equal',                 fetchShowsKeys(Array('venue'=>'Hall B')), Array(2));
	checkIdentical('filter: wildcard, whatever the case', fetchShowsKeys(Array('title~'=>'alpha*')), Array(1, 3));
	checkIdentical('filter: negated wildcard',      fetchShowsKeys(Array('title!~'=>'Alpha*')), Array(2, 4, 5));
	checkIdentical('filter: list and comparison',   fetchShowsKeys(Array('id_show'=>Array(1, 2, 3), 'id_show>'=>1)), Array(2, 3));
	checkIdentical('filter: field left unset',      fetchShowsKeys(Array('venue?'=>false)), Array(5));
	checkIdentical('filter: a null value matches nothing', fetchShowsKeys(Array('venue'=>null)), Array());
	checkIdentical('filter: a value holding a quote', fetchShowsKeys(Array('venue'=>"Hall A' OR '1'='1")), Array());

	// Lists
	checkIdentical('list: order, limit and offset', runCatchThrown(function(){
		$showLst = new DBLst('shows');
		$showLst->setOrderFields('^id_show');
		$showLst->setLimitOffset('2,1');
		return array_map('intval', array_column($showLst->readRowsOfList(), 'id_show'));
	}), Array(4, 3));
	checkIdentical('list: grouped count', runCatchThrown(function(){
		$itemLst = new DBLst('items');
		$itemLst->pickReadFields('type, count(*) as nb');
		$itemLst->setGroupFields('type');
		$itemLst->setOrderFields('type');
		return array_map(function($listedRecord){ return $listedRecord['type'].'='.intval($listedRecord['nb']); }, $itemLst->readRowsOfList());
	}), Array('circus=1', 'dance=2', 'music=2', 'solo=1', 'theatre=1'));
	checkIdentical('list: join', runCatchThrown(function(){
		$evntLst = new DBLst('events');
		$evntLst->pickReadFields('shows.title, events.date');
		$evntLst->joinOtherTable(Array('shows', 'events.show_id', 'shows.id_show'));
		$evntLst->replaceFilters(Array('events.id_event'=>7));
		return $evntLst->readRowsOfList();
	}), Array(Array('title'=>'Solo Gig', 'date'=>'2026-12-01')));

	// Records
	checkIdentical('record: load with its children', runCatchThrown(function(){
		$showRec = new DBRec('shows', Array('events'));
		$recdVls = $showRec->loadRecordList('2');
		return Array($recdVls['title'], array_column($recdVls['Events'], 'date'));
	}), Array('Beta Evening', Array('2026-11-02', '2026-11-09')));
	checkIdentical('record: find, whatever the case', runCatchThrown(function(){
		$showRec = new DBRec('shows');
		return $showRec->findRecordList(Array('title~'=>'beta*'))['venue'] ?? null;
	}), 'Hall B');
	checkIdentical('record: an update keeps quotes and sets NULL', runCatchThrown(function(){
		$showRec = new DBRec('shows');
		$showRec->loadRecordList('3');
		$showRec->bindFieldArray(Array('title'=>"O'Matinee", 'venue'=>null));
		$savdKey = $showRec->insertOrUpdate();
		$readRec = new DBRec('shows');
		$recdVls = $readRec->loadRecordList('3');
		return Array(strval($savdKey), $recdVls['title'], $recdVls['venue']);
	}), Array('3', "O'Matinee", null));
	checkIdentical('record: saving without any change still succeeds', runCatchThrown(function(){
		$showRec = new DBRec('shows');
		$showRec->loadRecordList('1');
		return strval($showRec->insertOrUpdate());
	}), '1');
	checkIdentical('record: an insert returns the new key', runCatchThrown(function(){
		$showRec = new DBRec('shows');
		$showRec->bindFieldArray(Array('title'=>'Gamma', 'venue'=>'Hall G'));
		$savdKey = $showRec->insertOrUpdate();
		$readRec = new DBRec('shows');
		return Array(strval($savdKey), $readRec->loadRecordList(strval($savdKey))['title'] ?? null);
	}), Array('6', 'Gamma'));
	checkIdentical('record: delete with its named children, and only those', runCatchThrown(function(){
		$showRec = new DBRec('shows');
		$showRec->loadRecordList('1');
		$remDone = $showRec->removeRecdFlag(Array('events'));
		$evntLst = new DBLst('events');
		$itemLst = new DBLst('items');
		$itemLst->replaceFilters(Array('show_id'=>1));
		return Array($remDone, fetchShowsKeys(Array('id_show'=>1)), count($evntLst->readRowsOfList()), count($itemLst->readRowsOfList()));
	}), Array(true, Array(), 5, 2));
	checkIdentical('record: a failing child table cancels the deletion', runCatchThrown(function(){
		$showRec = new DBRec('shows');
		$showRec->loadRecordList('2');
		$evntLst = new DBLst('events');
		$evntLst->replaceFilters(Array('show_id'=>2));
		return Array($showRec->removeRecdFlag(Array('events', 'nowhere')), fetchShowsKeys(Array('id_show'=>2)), count($evntLst->readRowsOfList()));
	}), Array(false, Array(2), 2));
}
