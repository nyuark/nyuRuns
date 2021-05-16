<?php

// Data\DBRecord : loading, finding, existence, saving and relations,
// against the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('DBRecord', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Data\DBRecord;
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
	$showRec = new DBRecord('shows');
	return $showRec->loadRecrd($primaryKey);
}

// Loading and finding
checkSame('loadRecrd found',   runSafely(function(){ return loadAShow('2'); }), Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>'Hall B'));
checkSame('loadRecrd missing', runSafely(function(){ return loadAShow('99'); }), false);
checkSame('findRecrd with a wildcard', runSafely(function(){
	$showRec = new DBRecord('shows');
	return $showRec->findRecrd(Array('title'=>'Beta*'));
}), Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>'Hall B'));
checkTrue('findRecrd missing gives nothing', runSafely(function(){
	$showRec = new DBRecord('shows');
	return empty($showRec->findRecrd(Array('title'=>'Nope')));
}));

// Existence
$showRec = new DBRecord('shows');
checkSame('exstRecrd existing, integer key', runSafely(function() use ($showRec){ return $showRec->exstRecrd(1); }), true);
checkSame('exstRecrd existing, string key',  runSafely(function() use ($showRec){ return $showRec->exstRecrd('1'); }), true);
checkSame('exstRecrd missing',               runSafely(function() use ($showRec){ return $showRec->exstRecrd(99); }), false);
checkSame('exstRecrd key 0',                 runSafely(function() use ($showRec){ return $showRec->exstRecrd(0); }), false);
checkSame('exstRecrd null key',              runSafely(function() use ($showRec){ return $showRec->exstRecrd(null); }), false);
checkSame('exstRecrd empty key',             runSafely(function() use ($showRec){ return $showRec->exstRecrd(''); }), false);
checkSame('exstRecrd non-numeric key',       runSafely(function() use ($showRec){ return $showRec->exstRecrd('abc'); }), false);

// Updating
checkSame('update keeps the key and escapes quotes', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->loadRecrd('2');
	$showRec->bindArray(Array('venue'=>"O'Hall", 'bogus'=>'ignored'));
	$savedKy = $showRec->saveRecrd();
	return Array($savedKy, loadAShow('2'));
}), Array('2', Array('id_show'=>2, 'title'=>'Beta Evening', 'venue'=>"O'Hall")));

checkSame('update of a row holding NULL keeps the NULL', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->loadRecrd('5');
	$showRec->bindArray(Array('title'=>'Empty Stage II'));
	$showRec->saveRecrd();
	return loadAShow('5');
}), Array('id_show'=>5, 'title'=>'Empty Stage II', 'venue'=>null));

checkSame('update can set a column to NULL', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->loadRecrd('3');
	$showRec->bindArray(Array('venue'=>null));
	$showRec->saveRecrd();
	return loadAShow('3');
}), Array('id_show'=>3, 'title'=>'Alpha Matinee', 'venue'=>null));

checkSame('saveRecrd can leave fields out', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->loadRecrd('4');
	$showRec->bindArray(Array('title'=>'Solo Gig II', 'venue'=>'Elsewhere'));
	$showRec->saveRecrd(Array('venue'));
	return loadAShow('4');
}), Array('id_show'=>4, 'title'=>'Solo Gig II', 'venue'=>'Hall S'));

// Inserting
checkSame('insert without a key returns the new key', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->bindArray(Array('title'=>'Gamma', 'venue'=>'Hall G'));
	$savedKy = $showRec->saveRecrd();
	return Array($savedKy, loadAShow($savedKy));
}), Array('6', Array('id_show'=>6, 'title'=>'Gamma', 'venue'=>'Hall G')));

checkSame('insert with a NULL value and a quote', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->bindArray(Array('title'=>"Epsilon's", 'venue'=>null));
	return loadAShow($showRec->saveRecrd());
}), Array('id_show'=>7, 'title'=>"Epsilon's", 'venue'=>null));

checkSame('the literal string NULL stays a string', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->bindArray(Array('title'=>'NULL', 'venue'=>'NULL'));
	return loadAShow($showRec->saveRecrd());
}), Array('id_show'=>8, 'title'=>'NULL', 'venue'=>'NULL'));

checkSame('insert with a supplied key uses that key', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->bindArray(Array('id_show'=>50, 'title'=>'Delta', 'venue'=>'Hall D'));
	$savedKy = $showRec->saveRecrd();
	return Array($savedKy, loadAShow('50'));
}), Array(50, Array('id_show'=>50, 'title'=>'Delta', 'venue'=>'Hall D')));

checkSame('saving again with the same key updates instead of inserting', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->bindArray(Array('id_show'=>50, 'title'=>'Delta II'));
	$savedKy = $showRec->saveRecrd();
	$allRows = new nyu\Data\DBList('shows');
	$allRows->lstSearch(Array('title'=>'Delta*'));
	return Array($savedKy, $allRows->readItems());
}), Array(50, Array(Array('id_show'=>50, 'title'=>'Delta II', 'venue'=>'Hall D'))));

// Relations
checkSame('childList returns the record when asked', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->loadRecrd('1');
	$recVals = $showRec->childList('events', true, '', '', 'date ASC');
	return array_column($recVals['Events'], 'date');
}), Array('2026-12-28', '2027-01-03'));

checkSame('childList returns nothing otherwise', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->loadRecrd('1');
	return $showRec->childList('items');
}), null);

checkSame('childList with a single child is a list of one', runSafely(function(){
	$showRec = new DBRecord('shows');
	$showRec->loadRecrd('4');
	return $showRec->childList('events', true)['Events'];
}), Array(Array('id_event'=>7, 'show_id'=>4, 'date'=>'2026-12-01', 'time'=>'21:00')));

checkSame('parentRec', runSafely(function(){
	$evntRec = new DBRecord('events');
	$evntRec->loadRecrd('3');
	$recVals = $evntRec->parentRec('shows', true, 'id_show', 'show_id');
	return $recVals['Show']['id_show'] ?? null;
}), 2);

checkSame('relations declared in the constructor are loaded', runSafely(function(){
	$showRec = new DBRecord('shows', Array('items'));
	$recVals = $showRec->loadRecrd('1');
	return array_column($recVals['Items'], 'type');
}), Array('music', 'dance'));

// Connection
checkSame('getConnex returns the handler', runSafely(function(){
	return get_class( DBCon::getInstnc('Lite', '', '', '', '')->getConnex() );
}), 'nyu\Data\DBHs\Lite');

endChecks();
