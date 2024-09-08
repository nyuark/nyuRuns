<?php

// Data\DBStore\SqlStore : the Store contract over an SQL handler, against
// the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Store', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Data\DBCon;
use nyu\Data\DBStore\SqlStore;
use nyu\Data\DBStore\Store;

buildData( inTempDir('store.sqlite') );
useSqlite( inTempDir('store.sqlite') );

$dbStore = new SqlStore( DBCon::linkNamed()->getConnex() );

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
	return runSafely(function() use ($storeObjc, $tableLabel, $whereArray, $findOption, $fieldLabel){
		$fndRows = $storeObjc->findItems($tableLabel, $whereArray, $findOption);
		return is_array($fndRows) ? array_column($fndRows, $fieldLabel) : $fndRows;
	});
}

checkTrue('the SQL store follows the contract', $dbStore instanceof Store);

// Names and description
checkSame('checkName gives the name back', runSafely(function() use ($dbStore){ return $dbStore->checkName('shows.title'); }), 'shows.title');
checkThrw('checkName refuses anything else', function() use ($dbStore){ $dbStore->checkName('title; DROP'); }, 'RuntimeException');
checkSame('tableInfo', runSafely(function() use ($dbStore){ return $dbStore->tableInfo('shows'); }), Array('flds'=>Array('id_show', 'title', 'venue'), 'pkey'=>'id_show', 'blob'=>Array()));

// Reading: the options every store honours
checkSame('find: every row', foundCol($dbStore, 'shows', Array(), Array(), 'id_show'), Array(1, 2, 3, 4, 5));
checkSame('find: a filter', foundCol($dbStore, 'shows', Array('title'=>'Alpha*', 'id_show>'=>1), Array(), 'id_show'), Array(3));
checkSame('find: chosen fields', runSafely(function() use ($dbStore){ return $dbStore->findItems('shows', Array('id_show'=>2), Array('field'=>Array('title', 'venue'))); }), Array(Array('title'=>'Beta Evening', 'venue'=>'Hall B')));
checkSame('find: a field under another name', runSafely(function() use ($dbStore){ return $dbStore->findItems('shows', Array('id_show'=>2), Array('field'=>Array(Array('field'=>'title', 'alias'=>'name')))); }), Array(Array('name'=>'Beta Evening')));
checkSame('find: order', foundCol($dbStore, 'shows', Array(), Array('order'=>Array(Array('title', false), Array('id_show', true))), 'id_show'), Array(3, 1, 2, 5, 4));
checkSame('find: descending order, limit and offset', foundCol($dbStore, 'shows', Array(), Array('order'=>Array(Array('id_show', true)), 'limit'=>2, 'offst'=>1), 'id_show'), Array(4, 3));
checkSame('find: a limit alone', foundCol($dbStore, 'shows', Array(), Array('order'=>Array(Array('id_show', false)), 'limit'=>2), 'id_show'), Array(1, 2));
checkSame('find: no match gives an empty list', foundCol($dbStore, 'shows', Array('title'=>'Zzz'), Array(), 'id_show'), Array());
checkSame('find: a missing table gives false, with an error', runSafely(function() use ($dbStore){ return Array($dbStore->findItems('nowhere'), is_string($dbStore->lastError())); }), Array(false, true));
checkLike('find: an invalid field name is refused', foundCol($dbStore, 'shows', Array(), Array('field'=>Array('title` FROM users --')), 'title'), '/^THROWN RuntimeException: Invalid table or field name/');
checkLike('find: an invalid order field is refused', foundCol($dbStore, 'shows', Array(), Array('order'=>Array(Array('title; DROP', false))), 'title'), '/^THROWN RuntimeException: Invalid table or field name/');
checkSame('find: a limit that is not a number becomes one', foundCol($dbStore, 'shows', Array(), Array('order'=>Array(Array('id_show', false)), 'limit'=>'2; DROP TABLE shows'), 'id_show'), Array(1, 2));

// Reading: the options of the SQL types
checkSame('find: functions and group', runSafely(function() use ($dbStore){
	return $dbStore->findItems('items', Array(), Array('field'=>Array('type'), 'funcs'=>Array(Array('funct'=>'count', 'field'=>'*', 'alias'=>'nb')), 'group'=>Array('type'), 'order'=>Array(Array('type', false)), 'limit'=>2));
}), Array(Array('type'=>'circus', 'nb'=>1), Array('type'=>'dance', 'nb'=>2)));
checkLike('find: an invalid function name is refused', runSafely(function() use ($dbStore){
	return $dbStore->findItems('items', Array(), Array('funcs'=>Array(Array('funct'=>'count(*) FROM users; --', 'field'=>'*'))));
}), '/^THROWN RuntimeException: Invalid function name/');
checkSame('find: join', runSafely(function() use ($dbStore){
	return $dbStore->findItems('events', Array('events.id_event'=>7), Array('field'=>Array('shows.title', 'events.date'), 'joins'=>Array('shows', 'events.show_id', 'shows.id_show')));
}), Array(Array('title'=>'Solo Gig', 'date'=>'2026-12-01')));
checkLike('find: a join without its two fields is refused', runSafely(function() use ($dbStore){
	return $dbStore->findItems('events', Array(), Array('joins'=>Array('shows', 'show_id')));
}), '/^THROWN RuntimeException: A join needs a table and two field names/');
checkSame('find: fragments written by the developer', runSafely(function() use ($dbStore){
	return $dbStore->findItems('events', Array(), Array('devel'=>Array('field'=>'date', 'where'=>"`show_id` = '1'", 'order'=>'date DESC', 'limit'=>'1')));
}), Array(Array('date'=>'2027-01-03')));
checkSame('find: a developer where clause adds to the filter', foundCol($dbStore, 'events', Array('date>'=>'2026-12-31'), Array('devel'=>Array('where'=>"`show_id` = '1'")), 'id_event'), Array(2));

// Adding
checkSame('insert returns the key given by the database', runSafely(function() use ($dbStore){
	$newKey = $dbStore->insertRow('shows', Array('title'=>"Gamma's", 'venue'=>null));
	return Array($newKey, $dbStore->findItems('shows', Array('id_show'=>6)));
}), Array('6', Array(Array('id_show'=>6, 'title'=>"Gamma's", 'venue'=>null))));
checkSame('insert on a missing table gives false', runSafely(function() use ($dbStore){ return $dbStore->insertRow('nowhere', Array('title'=>'x')); }), false);
checkLike('insert with an invalid field name is refused', runSafely(function() use ($dbStore){ return $dbStore->insertRow('shows', Array('title`) VALUES (1); --'=>'x')); }), '/^THROWN RuntimeException: Invalid table or field name/');

// Changing
checkSame('update changes the rows of the filter, and counts them', runSafely(function() use ($dbStore){
	$rowsDne = $dbStore->updateRow('shows', Array('title'=>'Alpha*'), Array('venue'=>'Hall X'));
	return Array($rowsDne, array_column($dbStore->findItems('shows', Array('venue'=>'Hall X')), 'id_show'));
}), Array(2, Array(1, 3)));
checkSame('update matching nothing gives 0', runSafely(function() use ($dbStore){ return $dbStore->updateRow('shows', Array('id_show'=>999), Array('venue'=>'Hall Y')); }), 0);
checkSame('update with nothing to set gives 0', runSafely(function() use ($dbStore){ return $dbStore->updateRow('shows', Array('id_show'=>1), Array()); }), 0);
checkLike('update without a filter is refused', runSafely(function() use ($dbStore){ return $dbStore->updateRow('shows', Array(), Array('venue'=>'Everywhere')); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkLike('update with a filter that is always true is refused', runSafely(function() use ($dbStore){ return $dbStore->updateRow('shows', Array('id_show!'=>Array()), Array('venue'=>'Everywhere')); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkSame('...and nothing was changed', foundCol($dbStore, 'shows', Array('venue'=>'Everywhere'), Array(), 'id_show'), Array());

// Deleting
checkSame('delete removes the rows of the filter, and counts them', runSafely(function() use ($dbStore){
	return Array($dbStore->deleteRow('events', Array('show_id'=>3)), count($dbStore->findItems('events')));
}), Array(2, 5));
checkSame('delete matching nothing gives 0', runSafely(function() use ($dbStore){ return $dbStore->deleteRow('events', Array('show_id'=>999)); }), 0);
checkLike('delete without a filter is refused', runSafely(function() use ($dbStore){ return $dbStore->deleteRow('events', Array()); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkLike('delete with a filter that is always true is refused', runSafely(function() use ($dbStore){ return $dbStore->deleteRow('events', Array('id_event!'=>Array(), 'show_id!'=>Array())); }), '/^THROWN RuntimeException: A change or a deletion needs a filter/');
checkSame('...and nothing was deleted', runSafely(function() use ($dbStore){ return count($dbStore->findItems('events')); }), 5);
checkSame('delete on a missing table gives false', runSafely(function() use ($dbStore){ return $dbStore->deleteRow('nowhere', Array('id'=>1)); }), false);

// Transactions
checkSame('a removed transaction cancels the changes of the store', runSafely(function() use ($dbStore){
	$opnDone = $dbStore->opnTrnsct();
	$dbStore->deleteRow('items', Array('show_id'=>1));
	$midRows = count($dbStore->findItems('items'));
	return Array($opnDone, $midRows, $dbStore->remTrnsct(), count($dbStore->findItems('items')), $dbStore->setTrnsct());
}), Array(true, 5, true, 7, false));

endChecks();
