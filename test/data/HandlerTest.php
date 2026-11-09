<?php

// Data\DBHndlr\PDO : errors, writes and result reading of the database handler,
// against the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Handler', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Data\DBCon;

createSampleDb( inTemporaryDir('handler.sqlite') );
switchToSqlite( inTemporaryDir('handler.sqlite') );

$dbHandl = DBCon::obtainInstance('sqlte', '', '', '', inTemporaryDir('handler.sqlite'))->readConnection();

// Errors
checkIdentical('lastError before any error', runCatchThrown(function() use ($dbHandl){ return $dbHandl->finalErrorText(); }), false);
checkIdentical('sendQuery on a missing table returns false', runCatchThrown(function() use ($dbHandl){ return $dbHandl->sendBuiltQuery('SELECT * FROM `nowhere`'); }), false);
checkPregMatch('lastError tells the failure', (string)$dbHandl->finalErrorText(), '/^PDO Error: .*nowhere/');

// Writes
checkIdentical('a write matching no row succeeds, with a count of 0', runCatchThrown(function() use ($dbHandl){
	return Array($dbHandl->sendBuiltQuery("UPDATE `shows` SET `venue`='Hall Z' WHERE `id_show`=999;", true), $dbHandl->countLastWrite());
}), Array(true, 0));
checkIdentical('a write matching a row gives a count of 1', runCatchThrown(function() use ($dbHandl){
	return Array($dbHandl->sendBuiltQuery("UPDATE `shows` SET `venue`='Hall Z' WHERE `id_show`=1;", true), $dbHandl->countLastWrite());
}), Array(true, 1));
checkIdentical('lastCount after a read is 0', runCatchThrown(function() use ($dbHandl){
	$dbHandl->sendBuiltQuery('SELECT * FROM `shows`');
	return $dbHandl->countLastWrite();
}), 0);

// Bound values
checkIdentical('a read with a bound value', runCatchThrown(function() use ($dbHandl){
	$dbHandl->sendBuiltQuery('SELECT `title` FROM `shows` WHERE `id_show`=?', false, Array(2));
	return $dbHandl->readLastResult(true);
}), Array(Array('title'=>'Beta Evening')));
checkIdentical('a bound read matching nothing gives an empty list', runCatchThrown(function() use ($dbHandl){
	return Array($dbHandl->sendBuiltQuery('SELECT `title` FROM `shows` WHERE `id_show`=?', false, Array(999)), $dbHandl->readLastResult(true));
}), Array(true, Array()));
checkIdentical('a bound write keeps quotes and SQL as plain text', runCatchThrown(function() use ($dbHandl){
	$sentOky = $dbHandl->sendBuiltQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array("O'Hall'; DROP TABLE `shows`; --", 2));
	$rowsDne = $dbHandl->countLastWrite();
	$dbHandl->sendBuiltQuery('SELECT `venue` FROM `shows` WHERE `id_show`=?', false, Array(2));
	return Array($sentOky, $rowsDne, $dbHandl->readLastResult(true));
}), Array(true, 1, Array(Array('venue'=>"O'Hall'; DROP TABLE `shows`; --"))));
checkIdentical('a bound NULL is written as NULL', runCatchThrown(function() use ($dbHandl){
	$dbHandl->sendBuiltQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array(null, 3));
	$dbHandl->sendBuiltQuery('SELECT `venue` FROM `shows` WHERE `id_show`=?', false, Array(3));
	return $dbHandl->readLastResult(true);
}), Array(Array('venue'=>null)));
checkIdentical('more values than placeholders fails', runCatchThrown(function() use ($dbHandl){
	return $dbHandl->sendBuiltQuery('SELECT `title` FROM `shows` WHERE `id_show`=?', false, Array(1, 2));
}), false);

// Table and field names
checkIdentical('quoteName of a plain name', runCatchThrown(function() use ($dbHandl){ return $dbHandl->quoteFieldName('id_show'); }), '`id_show`');
checkIdentical('quoteName of a table.field name', runCatchThrown(function() use ($dbHandl){ return $dbHandl->quoteFieldName('shows.title'); }), '`shows`.`title`');
checkForThrows('quoteName refuses a backtick', function() use ($dbHandl){ $dbHandl->quoteFieldName('title` = 1 OR `venue'); }, 'RuntimeException');
checkForThrows('quoteName refuses a space', function() use ($dbHandl){ $dbHandl->quoteFieldName('title venue'); }, 'RuntimeException');
checkForThrows('quoteName refuses an empty name', function() use ($dbHandl){ $dbHandl->quoteFieldName(''); }, 'RuntimeException');
checkForThrows('quoteName refuses a trailing line break', function() use ($dbHandl){ $dbHandl->quoteFieldName("title\n"); }, 'RuntimeException');

// Table description
checkIdentical('tableInfo gives the fields and the key', runCatchThrown(function() use ($dbHandl){ return $dbHandl->tableInfoArray('shows'); }), Array('flds'=>Array('id_show', 'title', 'venue'), 'pkey'=>'id_show', 'blob'=>Array()));
checkIdentical('tableInfo of a table without a key', runCatchThrown(function() use ($dbHandl){
	$dbHandl->sendBuiltQuery('CREATE TABLE `notes` (`body` TEXT, `madeDate` TEXT);', true);
	return $dbHandl->tableInfoArray('notes');
}), Array('flds'=>Array('body', 'madeDate'), 'pkey'=>'', 'blob'=>Array()));
checkIdentical('tableInfo of a missing table', runCatchThrown(function() use ($dbHandl){ return $dbHandl->tableInfoArray('nowhere'); }), false);
checkForThrows('tableInfo refuses an invalid table name', function() use ($dbHandl){ $dbHandl->tableInfoArray("shows'); DROP TABLE shows; --"); }, 'RuntimeException');

// Errors do not outlive the call that raised them
checkIdentical('lastError is cleared by the next successful query', runCatchThrown(function() use ($dbHandl){
	$dbHandl->sendBuiltQuery('SELECT * FROM `nowhere`');
	$failMsg = $dbHandl->finalErrorText();
	$dbHandl->sendBuiltQuery('SELECT * FROM `shows`');
	return Array(is_string($failMsg), $dbHandl->finalErrorText());
}), Array(true, false));
checkIdentical('readQuery after a failed query gives false', runCatchThrown(function() use ($dbHandl){
	$dbHandl->sendBuiltQuery('SELECT * FROM `nowhere`');
	return $dbHandl->readLastResult(true);
}), false);
checkIdentical('readQuery after a write that changed nothing is empty, without an error', runCatchThrown(function() use ($dbHandl){
	$dbHandl->sendBuiltQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Nowhere', 999));
	return Array($dbHandl->readLastResult(true), $dbHandl->finalErrorText());
}), Array(Array(), false));
checkIdentical('lastError is cleared by a transaction call', runCatchThrown(function() use ($dbHandl){
	$dbHandl->sendBuiltQuery('SELECT * FROM `nowhere`');
	$dbHandl->opnTransaction();
	$opnMssg = $dbHandl->finalErrorText();
	$dbHandl->remTransaction();
	return $opnMssg;
}), false);

// Transactions
/**
 * Reads the venue of show 4 straight from the database.
 *
 * @return mixed The venue.
 */
function venueOf4(): Mixed {
	$dbHandl = DBCon::obtainInstance('sqlte', '', '', '', inTemporaryDir('handler.sqlite'))->readConnection();
	$dbHandl->sendBuiltQuery('SELECT `venue` FROM `shows` WHERE `id_show`=?', false, Array(4));
	return $dbHandl->readLastResult(true)[0]['venue'] ?? null;
}

checkIdentical('nothing to set or remove without a transaction', runCatchThrown(function() use ($dbHandl){
	return Array($dbHandl->setTransaction(), $dbHandl->remTransaction());
}), Array(false, false));
checkIdentical('a removed transaction leaves the table unchanged', runCatchThrown(function() use ($dbHandl){
	$opnDone = $dbHandl->opnTransaction();
	$dbHandl->sendBuiltQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Undone', 4));
	$midRead = venueOf4();
	return Array($opnDone, $midRead, $dbHandl->remTransaction(), venueOf4());
}), Array(true, 'Undone', true, 'Hall S'));
checkIdentical('a set transaction is kept', runCatchThrown(function() use ($dbHandl){
	$dbHandl->opnTransaction();
	$dbHandl->sendBuiltQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Kept', 4));
	return Array($dbHandl->setTransaction(), venueOf4(), $dbHandl->remTransaction());
}), Array(true, 'Kept', false));
checkIdentical('an inner set followed by an outer remove keeps nothing', runCatchThrown(function() use ($dbHandl){
	$dbHandl->opnTransaction();
	$dbHandl->opnTransaction();
	$dbHandl->sendBuiltQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Inner', 4));
	$innrSet = $dbHandl->setTransaction();
	return Array($innrSet, $dbHandl->remTransaction(), venueOf4());
}), Array(true, true, 'Kept'));
checkIdentical('an inner remove makes the outer set fail', runCatchThrown(function() use ($dbHandl){
	$dbHandl->opnTransaction();
	$dbHandl->opnTransaction();
	$dbHandl->sendBuiltQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Inner', 4));
	$innrRem = $dbHandl->remTransaction();
	return Array($innrRem, $dbHandl->setTransaction(), venueOf4());
}), Array(true, false, 'Kept'));
checkIdentical('both levels set: the change is written', runCatchThrown(function() use ($dbHandl){
	$dbHandl->opnTransaction();
	$dbHandl->opnTransaction();
	$dbHandl->sendBuiltQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Both', 4));
	return Array($dbHandl->setTransaction(), $dbHandl->setTransaction(), $dbHandl->setTransaction(), venueOf4());
}), Array(true, true, false, 'Both'));

finishChecking();
