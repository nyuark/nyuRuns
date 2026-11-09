<?php

// Data\DBStore\Filter and the makeWhere of the handlers : the WHERE clause built from a filter array, and the rows
// it selects in the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Filter', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Data\DBCon;
use nyu\Data\DBStore\Filter;

createSampleDb( inTemporaryDir('filter.sqlite') );
switchToSqlite( inTemporaryDir('filter.sqlite') );

/**
 * Builds a filter and runs it on the shows table.
 *
 * @param Array $whereArray The filter array.
 * @return mixed Array of the clause, its values and the keys of the shows it selects, or a 'THROWN ...' string.
 */
function pickShow( Array $whereArray ): Mixed {
	return runCatchThrown(function() use ($whereArray){
		$dbHandl = DBCon::obtainInstance('sqlte', '', '', '', inTemporaryDir('filter.sqlite'))->readConnection();
		$whrPart = $dbHandl->makeWhereArray($whereArray);
		$slctQry = 'SELECT `id_show` FROM `shows`'.($whrPart['text']!='' ? ' WHERE '.$whrPart['text'] : '').' ORDER BY `id_show`';
		if( !$dbHandl->sendBuiltQuery($slctQry, false, $whrPart['vals']) ){
			return 'FAILED '.$dbHandl->finalErrorText();
		}
		return Array($whrPart['text'], $whrPart['vals'], array_column($dbHandl->readLastResult(true), 'id_show'));
	});
}

// Comparisons
checkIdentical('equal',            pickShow(Array('venue'=>'Hall B')),  Array('`venue` = ?',  Array('Hall B'), Array(2)));
checkIdentical('not equal',        pickShow(Array('venue!'=>'Hall B')), Array('`venue` != ?', Array('Hall B'), Array(1, 3, 4)));
checkIdentical('lower or equal',   pickShow(Array('id_show<='=>2)),     Array('`id_show` <= ?', Array(2), Array(1, 2)));
checkIdentical('greater or equal', pickShow(Array('id_show>='=>4)),     Array('`id_show` >= ?', Array(4), Array(4, 5)));
checkIdentical('lower',            pickShow(Array('id_show<'=>2)),      Array('`id_show` < ?',  Array(2), Array(1)));
checkIdentical('greater',          pickShow(Array('id_show>'=>4)),      Array('`id_show` > ?',  Array(4), Array(5)));

// Wildcards : asked for by the name of the field, * being the only one
checkIdentical('wildcard',         pickShow(Array('title~'=>'Alpha*')),  Array("`title` LIKE ? ESCAPE '!'",     Array('Alpha%'), Array(1, 3)));
checkIdentical('negated wildcard', pickShow(Array('title!~'=>'Alpha*')), Array("`title` NOT LIKE ? ESCAPE '!'", Array('Alpha%'), Array(2, 4, 5)));
checkIdentical('wildcard: in the middle and at the start', Array(pickShow(Array('title~'=>'*a N*'))[2] ?? null, pickShow(Array('title~'=>'*Gig'))[2] ?? null), Array(Array(1), Array(4)));
checkIdentical('wildcard: without a *, the whole text is matched', pickShow(Array('title~'=>'Solo Gig')), Array("`title` LIKE ? ESCAPE '!'", Array('Solo Gig'), Array(4)));
checkIdentical('wildcard: % and _ are plain text, escaped for the database', pickShow(Array('title~'=>'%_!*')), Array("`title` LIKE ? ESCAPE '!'", Array('!%!_!!%'), Array()));
checkIdentical('wildcard: a _ does not stand for any character', Array(pickShow(Array('title~'=>'Solo_Gig'))[2] ?? null, pickShow(Array('title~'=>'Alpha%'))[2] ?? null), Array(Array(), Array()));
checkIdentical('wildcard: a list is still a list', pickShow(Array('id_show~'=>Array(1, 2), 'title!~'=>Array('Solo Gig'))), Array('`id_show` IN (?,?) AND `title` NOT IN (?)', Array(1, 2, 'Solo Gig'), Array(1, 2)));

// Exact values : what a value holds is never a wildcard
checkIdentical('a value holding * or % is compared as it is', Array(pickShow(Array('title'=>'Alpha*')), pickShow(Array('title'=>'%'))), Array(Array('`title` = ?', Array('Alpha*'), Array()), Array('`title` = ?', Array('%'), Array())));
checkIdentical('a negated value holding * is compared as it is', pickShow(Array('title!'=>'Alpha*')), Array('`title` != ?', Array('Alpha*'), Array(1, 2, 3, 4, 5)));
checkIdentical('a written = keeps wildcard characters as plain text', pickShow(Array('title='=>'Alpha*')), Array('`title` = ?', Array('Alpha*'), Array()));
checkIdentical('a written = finds the exact value',                 pickShow(Array('title='=>'Beta Evening')), Array('`title` = ?', Array('Beta Evening'), Array(2)));
checkIdentical('<= and >= are not read as a written =',             pickShow(Array('id_show<='=>1, 'id_show>='=>1)), Array('`id_show` <= ? AND `id_show` >= ?', Array(1, 1), Array(1)));

// Lists
checkIdentical('list',                  pickShow(Array('id_show'=>Array(1, 2))),  Array('`id_show` IN (?,?)',     Array(1, 2), Array(1, 2)));
checkIdentical('negated list',          pickShow(Array('id_show!'=>Array(1, 2))), Array('`id_show` NOT IN (?,?)', Array(1, 2), Array(3, 4, 5)));
checkIdentical('empty list',            pickShow(Array('id_show'=>Array())),      Array('1=0', Array(), Array()));
checkIdentical('negated empty list',    pickShow(Array('id_show!'=>Array())),     Array('1=1', Array(), Array(1, 2, 3, 4, 5)));
checkIdentical('a zero stays in a list', pickShow(Array('id_show'=>Array(0, 1))), Array('`id_show` IN (?,?)', Array(0, 1), Array(1)));
checkIdentical('null and empty strings leave a list', pickShow(Array('id_show'=>Array(null, '', 3))), Array('`id_show` IN (?)', Array(3), Array(3)));

// Fields left unset
checkIdentical('not set, asked by name', pickShow(Array('venue?'=>false)), Array('`venue` IS NULL',     Array(), Array(5)));
checkIdentical('set, asked by name',     pickShow(Array('venue?'=>true)),  Array('`venue` IS NOT NULL', Array(), Array(1, 2, 3, 4)));
checkIdentical('a null value matches nothing',         pickShow(Array('venue'=>null)),  Array('1=0', Array(), Array()));
checkIdentical('a negated null value matches nothing', pickShow(Array('venue!'=>null)), Array('1=0', Array(), Array()));
checkIdentical('a null value among other entries',     pickShow(Array('title~'=>'Empty*', 'venue'=>null)), Array("`title` LIKE ? ESCAPE '!' AND 1=0", Array('Empty%'), Array()));
checkPregMatch('the ? suffix refuses a null value',    pickShow(Array('venue?'=>null)), '/^THROWN RuntimeException: The \? suffix takes true or false/');
checkPregMatch('the ? suffix refuses a text value',    pickShow(Array('venue?'=>'false')), '/^THROWN RuntimeException: The \? suffix takes true or false/');

// Combinations and names
checkIdentical('several entries are joined by AND', pickShow(Array('title~'=>'Alpha*', 'venue!'=>'Hall A')), Array("`title` LIKE ? ESCAPE '!' AND `venue` != ?", Array('Alpha%', 'Hall A'), Array(3)));
checkIdentical('an empty filter gives no clause',   pickShow(Array()), Array('', Array(), Array(1, 2, 3, 4, 5)));
checkIdentical('a table.field name',                pickShow(Array('shows.venue'=>'Hall B')), Array('`shows`.`venue` = ?', Array('Hall B'), Array(2)));
checkIdentical('a value holding a quote is plain text', pickShow(Array('venue'=>"O'Hall' OR '1'='1")), Array('`venue` = ?', Array("O'Hall' OR '1'='1"), Array()));
checkPregMatch('an invalid field name throws', pickShow(Array('venue` = 1 OR `title'=>'x')), '/^THROWN RuntimeException: Invalid table or field name/');

// The reading alone, as every kind of database receives it
checkIdentical('readWhere gives one condition per entry', runCatchThrown(function(){
	return Filter::readWhereArray(Array('venue'=>'Hall B', 'id_show>='=>2, 'title!~'=>'Alpha*', 'id_show'=>Array(1, '', 2), 'venue?'=>false, 'title='=>'50%', 'venue~'=>'Hall %*', 'title!'=>'a*', 'venue!~'=>12));
}), Array(
	Array('field'=>'venue',   'opert'=>'equal', 'value'=>'Hall B'),
	Array('field'=>'id_show', 'opert'=>'aboeq', 'value'=>2),
	Array('field'=>'title',   'opert'=>'nmtch', 'value'=>'Alpha*'),
	Array('field'=>'id_show', 'opert'=>'among', 'value'=>Array(1, 2)),
	Array('field'=>'venue',   'opert'=>'unset', 'value'=>null),
	Array('field'=>'title',   'opert'=>'equal', 'value'=>'50%'),
	Array('field'=>'venue',   'opert'=>'match', 'value'=>'Hall %*'),
	Array('field'=>'title',   'opert'=>'diffr', 'value'=>'a*'),
	Array('field'=>'venue',   'opert'=>'nmtch', 'value'=>'12')
));
checkIdentical('readWhere: what matches nothing or everything names no field', runCatchThrown(function(){
	return array_column(Filter::readWhereArray(Array('a'=>null, 'b'=>Array(), 'c!'=>Array(), 'd?'=>true)), 'opert');
}), Array('never', 'never', 'every', 'isset'));
checkPregMatch('readWhere refuses an invalid field name by itself', runCatchThrown(function(){ return Filter::readWhereArray(Array('venue` OR 1=1 --'=>'x')); }), '/^THROWN RuntimeException: Invalid table or field name/');
checkIdentical('readWhere of an empty filter', runCatchThrown(function(){ return Filter::readWhereArray(Array()); }), Array());

finishChecking();
