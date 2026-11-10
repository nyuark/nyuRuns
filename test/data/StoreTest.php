<?php

// Data\DBStore\SqlStore : the Store contract over an SQL handler, against
// the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Store', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Data\DBCon;
use nyu\Data\DBStore\SqlStore;
use nyu\Data\DBStore\Store;

createSampleDb( inTemporaryDir('store.sqlite') );
switchToSqlite( inTemporaryDir('store.sqlite') );

$dbStore = new SqlStore( DBCon::linkNamedDbCon()->readConnection() );

/**
 * Reads one field of the rows a store finds.
 *
 * @param Store  $storeObjc  The store.
 * @param String $tableLabel Name of the table.
 * @param Array  $whereArray Filter array.
 * @param Array  $findOption Options of the reading.
 * @param String $fieldLabel Field to return from each row.
 * @return mixed The values, or a 'THROWN ...' string.
 */
function foundCol( Store $storeObjc, String $tableLabel, Array $whereArray, Array $findOption, String $fieldLabel ): Mixed {
	return runCatchThrown(function() use ($storeObjc, $tableLabel, $whereArray, $findOption, $fieldLabel){
		$fndRows = $storeObjc->findItemsArray($tableLabel, $whereArray, $findOption);
		return is_array($fndRows) ? array_column($fndRows, $fieldLabel) : $fndRows;
	});
}

checkTrueValue('the SQL store follows the contract', $dbStore instanceof Store);

// Names and description
checkIdentical('checkName gives the name back', runCatchThrown(function() use ($dbStore){ return $dbStore->checkNameChars('shows.title'); }), 'shows.title');
checkForThrows('checkName refuses anything else', function() use ($dbStore){ $dbStore->checkNameChars('title; DROP'); }, 'RuntimeException');
checkIdentical('tableInfoArray', runCatchThrown(function() use ($dbStore){ return $dbStore->tableInfoArray('shows'); }), Array('flds'=>Array('id_show', 'title', 'venue'), 'pkey'=>'id_show', 'blob'=>Array()));

// Reading: the options every store honours
checkIdentical('find: every row', foundCol($dbStore, 'shows', Array(), Array(), 'id_show'), Array(1, 2, 3, 4, 5));
checkIdentical('find: a filter', foundCol($dbStore, 'shows', Array('title~'=>'Alpha*', 'id_show>'=>1), Array(), 'id_show'), Array(3));
checkIdentical('find: chosen fields', runCatchThrown(function() use ($dbStore){ return $dbStore->findItemsArray('shows', Array('id_show'=>2), Array('field'=>Array('title', 'venue'))); }), Array(Array('title'=>'Beta Evening', 'venue'=>'Hall B')));
checkIdentical('find: a field under another name', runCatchThrown(function() use ($dbStore){ return $dbStore->findItemsArray('shows', Array('id_show'=>2), Array('field'=>Array(Array('field'=>'title', 'alias'=>'name')))); }), Array(Array('name'=>'Beta Evening')));
checkIdentical('find: order', foundCol($dbStore, 'shows', Array(), Array('order'=>Array(Array('title', false), Array('id_show', true))), 'id_show'), Array(3, 1, 2, 5, 4));
checkIdentical('find: descending order, limit and offset', foundCol($dbStore, 'shows', Array(), Array('order'=>Array(Array('id_show', true)), 'limit'=>2, 'offst'=>1), 'id_show'), Array(4, 3));
checkIdentical('find: a limit alone', foundCol($dbStore, 'shows', Array(), Array('order'=>Array(Array('id_show', false)), 'limit'=>2), 'id_show'), Array(1, 2));
checkIdentical('find: no match gives an empty list', foundCol($dbStore, 'shows', Array('title'=>'Zzz'), Array(), 'id_show'), Array());
checkIdentical('find: a missing table gives false, with an error', runCatchThrown(function() use ($dbStore){ return Array($dbStore->findItemsArray('nowhere'), is_string($dbStore->finalErrorText())); }), Array(false, true));
checkPregMatch('find: an invalid field name is refused', foundCol($dbStore, 'shows', Array(), Array('field'=>Array('title` FROM users --')), 'title'), '/^THROWN RuntimeException: Invalid table or field name/');
checkPregMatch('find: an invalid order field is refused', foundCol($dbStore, 'shows', Array(), Array('order'=>Array(Array('title; DROP', false))), 'title'), '/^THROWN RuntimeException: Invalid table or field name/');
checkIdentical('find: a limit that is not a number becomes one', foundCol($dbStore, 'shows', Array(), Array('order'=>Array(Array('id_show', false)), 'limit'=>'2; DROP TABLE shows'), 'id_show'), Array(1, 2));

// Reading: the options of the SQL types
checkIdentical('find: functions and group', runCatchThrown(function() use ($dbStore){
	return $dbStore->findItemsArray('items', Array(), Array('field'=>Array('type'), 'funcs'=>Array(Array('funct'=>'count', 'field'=>'*', 'alias'=>'nb')), 'group'=>Array('type'), 'order'=>Array(Array('type', false)), 'limit'=>2));
}), Array(Array('type'=>'circus', 'nb'=>1), Array('type'=>'dance', 'nb'=>2)));
checkPregMatch('find: an invalid function name is refused', runCatchThrown(function() use ($dbStore){
	return $dbStore->findItemsArray('items', Array(), Array('funcs'=>Array(Array('funct'=>'count(*) FROM users; --', 'field'=>'*'))));
}), '/^THROWN RuntimeException: Invalid function name/');
checkIdentical('find: join', runCatchThrown(function() use ($dbStore){
	return $dbStore->findItemsArray('events', Array('events.id_event'=>7), Array('field'=>Array('shows.title', 'events.date'), 'joins'=>Array('shows', 'events.show_id', 'shows.id_show')));
}), Array(Array('title'=>'Solo Gig', 'date'=>'2026-12-01')));
checkPregMatch('find: a join without its two fields is refused', runCatchThrown(function() use ($dbStore){
	return $dbStore->findItemsArray('events', Array(), Array('joins'=>Array('shows', 'show_id')));
}), '/^THROWN RuntimeException: A join needs a table and two field names/');
checkIdentical('find: fragments written by the developer', runCatchThrown(function() use ($dbStore){
	return $dbStore->findItemsArray('events', Array(), Array('devel'=>Array('field'=>'date', 'where'=>"`show_id` = '1'", 'order'=>'date DESC', 'limit'=>'1')));
}), Array(Array('date'=>'2027-01-03')));
checkIdentical('find: a developer where clause adds to the filter', foundCol($dbStore, 'events', Array('date>'=>'2026-12-31'), Array('devel'=>Array('where'=>"`show_id` = '1'")), 'id_event'), Array(2));

// Adding
checkIdentical('insert returns the key given by the database', runCatchThrown(function() use ($dbStore){
	$newKey = $dbStore->insertTableRow('shows', Array('title'=>"Gamma's", 'venue'=>null));
	return Array($newKey, $dbStore->findItemsArray('shows', Array('id_show'=>6)));
}), Array('6', Array(Array('id_show'=>6, 'title'=>"Gamma's", 'venue'=>null))));
checkIdentical('insert on a missing table gives false', runCatchThrown(function() use ($dbStore){ return $dbStore->insertTableRow('nowhere', Array('title'=>'x')); }), false);
checkPregMatch('insert with an invalid field name is refused', runCatchThrown(function() use ($dbStore){ return $dbStore->insertTableRow('shows', Array('title`) VALUES (1); --'=>'x')); }), '/^THROWN RuntimeException: Invalid table or field name/');

// Changing
checkIdentical('update changes the rows of the filter, and counts them', runCatchThrown(function() use ($dbStore){
	$rowsDne = $dbStore->updateRowCount('shows', Array('title~'=>'Alpha*'), Array('venue'=>'Hall X'));
	return Array($rowsDne, array_column($dbStore->findItemsArray('shows', Array('venue'=>'Hall X')), 'id_show'));
}), Array(2, Array(1, 3)));
checkIdentical('update matching nothing gives 0', runCatchThrown(function() use ($dbStore){ return $dbStore->updateRowCount('shows', Array('id_show'=>999), Array('venue'=>'Hall Y')); }), 0);
checkIdentical('update with nothing to set gives 0', runCatchThrown(function() use ($dbStore){ return $dbStore->updateRowCount('shows', Array('id_show'=>1), Array()); }), 0);
checkPregMatch('update without a filter is refused', runCatchThrown(function() use ($dbStore){ return $dbStore->updateRowCount('shows', Array(), Array('venue'=>'Everywhere')); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkPregMatch('update with a filter that is always true is refused', runCatchThrown(function() use ($dbStore){ return $dbStore->updateRowCount('shows', Array('id_show!'=>Array()), Array('venue'=>'Everywhere')); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkIdentical('...and nothing was changed', foundCol($dbStore, 'shows', Array('venue'=>'Everywhere'), Array(), 'id_show'), Array());

// Deleting
checkIdentical('delete removes the rows of the filter, and counts them', runCatchThrown(function() use ($dbStore){
	return Array($dbStore->deleteRowCount('events', Array('show_id'=>3)), count($dbStore->findItemsArray('events')));
}), Array(2, 5));
checkIdentical('delete matching nothing gives 0', runCatchThrown(function() use ($dbStore){ return $dbStore->deleteRowCount('events', Array('show_id'=>999)); }), 0);
checkPregMatch('delete without a filter is refused', runCatchThrown(function() use ($dbStore){ return $dbStore->deleteRowCount('events', Array()); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkPregMatch('delete with a filter that is always true is refused', runCatchThrown(function() use ($dbStore){ return $dbStore->deleteRowCount('events', Array('id_event!'=>Array(), 'show_id!'=>Array())); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkIdentical('...and nothing was deleted', runCatchThrown(function() use ($dbStore){ return count($dbStore->findItemsArray('events')); }), 5);
checkIdentical('delete on a missing table gives false', runCatchThrown(function() use ($dbStore){ return $dbStore->deleteRowCount('nowhere', Array('id'=>1)); }), false);

// Transactions
checkIdentical('a removed transaction cancels the changes of the store', runCatchThrown(function() use ($dbStore){
	$opnDone = $dbStore->newTransaction();
	$dbStore->deleteRowCount('items', Array('show_id'=>1));
	$midRows = count($dbStore->findItemsArray('items'));
	return Array($opnDone, $midRows, $dbStore->delTransaction(), count($dbStore->findItemsArray('items')), $dbStore->setTransaction());
}), Array(true, 5, true, 7, false));

finishChecking();
