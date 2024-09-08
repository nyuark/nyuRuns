<?php

// Data\DBHndlr\PDO : errors, writes and result reading of the database handler,
// against the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Handler', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Data\DBCon;

buildData( inTempDir('handler.sqlite') );
useSqlite( inTempDir('handler.sqlite') );

$dbHandl = DBCon::getInstnc('sqlte', '', '', '', inTempDir('handler.sqlite'))->getConnex();

// Errors
checkSame('lastError before any error', runSafely(function() use ($dbHandl){ return $dbHandl->lastError(); }), false);
checkSame('sendQuery on a missing table returns false', runSafely(function() use ($dbHandl){ return $dbHandl->sendQuery('SELECT * FROM `nowhere`'); }), false);
checkLike('lastError tells the failure', (string)$dbHandl->lastError(), '/^PDO Error: .*nowhere/');

// Writes
checkSame('a write matching no row succeeds, with a count of 0', runSafely(function() use ($dbHandl){
	return Array($dbHandl->sendQuery("UPDATE `shows` SET `venue`='Hall Z' WHERE `id_show`=999;", true), $dbHandl->lastCount());
}), Array(true, 0));
checkSame('a write matching a row gives a count of 1', runSafely(function() use ($dbHandl){
	return Array($dbHandl->sendQuery("UPDATE `shows` SET `venue`='Hall Z' WHERE `id_show`=1;", true), $dbHandl->lastCount());
}), Array(true, 1));
checkSame('lastCount after a read is 0', runSafely(function() use ($dbHandl){
	$dbHandl->sendQuery('SELECT * FROM `shows`');
	return $dbHandl->lastCount();
}), 0);

// Bound values
checkSame('a read with a bound value', runSafely(function() use ($dbHandl){
	$dbHandl->sendQuery('SELECT `title` FROM `shows` WHERE `id_show`=?', false, Array(2));
	return $dbHandl->readQuery(true);
}), Array(Array('title'=>'Beta Evening')));
checkSame('a bound read matching nothing gives an empty list', runSafely(function() use ($dbHandl){
	return Array($dbHandl->sendQuery('SELECT `title` FROM `shows` WHERE `id_show`=?', false, Array(999)), $dbHandl->readQuery(true));
}), Array(true, Array()));
checkSame('a bound write keeps quotes and SQL as plain text', runSafely(function() use ($dbHandl){
	$sentOky = $dbHandl->sendQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array("O'Hall'; DROP TABLE `shows`; --", 2));
	$rowsDne = $dbHandl->lastCount();
	$dbHandl->sendQuery('SELECT `venue` FROM `shows` WHERE `id_show`=?', false, Array(2));
	return Array($sentOky, $rowsDne, $dbHandl->readQuery(true));
}), Array(true, 1, Array(Array('venue'=>"O'Hall'; DROP TABLE `shows`; --"))));
checkSame('a bound NULL is written as NULL', runSafely(function() use ($dbHandl){
	$dbHandl->sendQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array(null, 3));
	$dbHandl->sendQuery('SELECT `venue` FROM `shows` WHERE `id_show`=?', false, Array(3));
	return $dbHandl->readQuery(true);
}), Array(Array('venue'=>null)));
checkSame('more values than placeholders fails', runSafely(function() use ($dbHandl){
	return $dbHandl->sendQuery('SELECT `title` FROM `shows` WHERE `id_show`=?', false, Array(1, 2));
}), false);

// Table and field names
checkSame('quoteName of a plain name', runSafely(function() use ($dbHandl){ return $dbHandl->quoteName('id_show'); }), '`id_show`');
checkSame('quoteName of a table.field name', runSafely(function() use ($dbHandl){ return $dbHandl->quoteName('shows.title'); }), '`shows`.`title`');
checkThrw('quoteName refuses a backtick', function() use ($dbHandl){ $dbHandl->quoteName('title` = 1 OR `venue'); }, 'RuntimeException');
checkThrw('quoteName refuses a space', function() use ($dbHandl){ $dbHandl->quoteName('title venue'); }, 'RuntimeException');
checkThrw('quoteName refuses an empty name', function() use ($dbHandl){ $dbHandl->quoteName(''); }, 'RuntimeException');
checkThrw('quoteName refuses a trailing line break', function() use ($dbHandl){ $dbHandl->quoteName("title\n"); }, 'RuntimeException');

// Table description
checkSame('tableInfo gives the fields and the key', runSafely(function() use ($dbHandl){ return $dbHandl->tableInfo('shows'); }), Array('flds'=>Array('id_show', 'title', 'venue'), 'pkey'=>'id_show', 'blob'=>Array()));
checkSame('tableInfo of a table without a key', runSafely(function() use ($dbHandl){
	$dbHandl->sendQuery('CREATE TABLE `notes` (`body` TEXT, `madeDate` TEXT);', true);
	return $dbHandl->tableInfo('notes');
}), Array('flds'=>Array('body', 'madeDate'), 'pkey'=>'', 'blob'=>Array()));
checkSame('tableInfo of a missing table', runSafely(function() use ($dbHandl){ return $dbHandl->tableInfo('nowhere'); }), false);
checkThrw('tableInfo refuses an invalid table name', function() use ($dbHandl){ $dbHandl->tableInfo("shows'); DROP TABLE shows; --"); }, 'RuntimeException');

// Errors do not outlive the call that raised them
checkSame('lastError is cleared by the next successful query', runSafely(function() use ($dbHandl){
	$dbHandl->sendQuery('SELECT * FROM `nowhere`');
	$failMsg = $dbHandl->lastError();
	$dbHandl->sendQuery('SELECT * FROM `shows`');
	return Array(is_string($failMsg), $dbHandl->lastError());
}), Array(true, false));
checkSame('readQuery after a failed query gives false', runSafely(function() use ($dbHandl){
	$dbHandl->sendQuery('SELECT * FROM `nowhere`');
	return $dbHandl->readQuery(true);
}), false);
checkSame('readQuery after a write that changed nothing is empty, without an error', runSafely(function() use ($dbHandl){
	$dbHandl->sendQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Nowhere', 999));
	return Array($dbHandl->readQuery(true), $dbHandl->lastError());
}), Array(Array(), false));
checkSame('lastError is cleared by a transaction call', runSafely(function() use ($dbHandl){
	$dbHandl->sendQuery('SELECT * FROM `nowhere`');
	$dbHandl->opnTrnsct();
	$opnMssg = $dbHandl->lastError();
	$dbHandl->remTrnsct();
	return $opnMssg;
}), false);

// Transactions
/**
 * Reads the venue of show 4 straight from the database.
 *
 * @return mixed The venue.
 */
function venueOf4(): Mixed {
	$dbHandl = DBCon::getInstnc('sqlte', '', '', '', inTempDir('handler.sqlite'))->getConnex();
	$dbHandl->sendQuery('SELECT `venue` FROM `shows` WHERE `id_show`=?', false, Array(4));
	return $dbHandl->readQuery(true)[0]['venue'] ?? null;
}

checkSame('nothing to set or remove without a transaction', runSafely(function() use ($dbHandl){
	return Array($dbHandl->setTrnsct(), $dbHandl->remTrnsct());
}), Array(false, false));
checkSame('a removed transaction leaves the table unchanged', runSafely(function() use ($dbHandl){
	$opnDone = $dbHandl->opnTrnsct();
	$dbHandl->sendQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Undone', 4));
	$midRead = venueOf4();
	return Array($opnDone, $midRead, $dbHandl->remTrnsct(), venueOf4());
}), Array(true, 'Undone', true, 'Hall S'));
checkSame('a set transaction is kept', runSafely(function() use ($dbHandl){
	$dbHandl->opnTrnsct();
	$dbHandl->sendQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Kept', 4));
	return Array($dbHandl->setTrnsct(), venueOf4(), $dbHandl->remTrnsct());
}), Array(true, 'Kept', false));
checkSame('an inner set followed by an outer remove keeps nothing', runSafely(function() use ($dbHandl){
	$dbHandl->opnTrnsct();
	$dbHandl->opnTrnsct();
	$dbHandl->sendQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Inner', 4));
	$innrSet = $dbHandl->setTrnsct();
	return Array($innrSet, $dbHandl->remTrnsct(), venueOf4());
}), Array(true, true, 'Kept'));
checkSame('an inner remove makes the outer set fail', runSafely(function() use ($dbHandl){
	$dbHandl->opnTrnsct();
	$dbHandl->opnTrnsct();
	$dbHandl->sendQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Inner', 4));
	$innrRem = $dbHandl->remTrnsct();
	return Array($innrRem, $dbHandl->setTrnsct(), venueOf4());
}), Array(true, false, 'Kept'));
checkSame('both levels set: the change is written', runSafely(function() use ($dbHandl){
	$dbHandl->opnTrnsct();
	$dbHandl->opnTrnsct();
	$dbHandl->sendQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Both', 4));
	return Array($dbHandl->setTrnsct(), $dbHandl->setTrnsct(), $dbHandl->setTrnsct(), venueOf4());
}), Array(true, true, false, 'Both'));

endChecks();
