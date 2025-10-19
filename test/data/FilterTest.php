<?php

// Data\DBStore\Filter and the makeWhere of the handlers : the WHERE clause built from a filter array, and the rows
// it selects in the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Filter', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Data\DBCon;
use nyu\Data\DBStore\Filter;

buildData( inTempDir('filter.sqlite') );
useSqlite( inTempDir('filter.sqlite') );

/**
 * Builds a filter and runs it on the shows table.
 *
 * @param Array $whereArray The filter array.
 * @return mixed Array of the clause, its values and the keys of the shows it selects, or a 'THROWN ...' string.
 */
function pickShow( Array $whereArray ): Mixed {
	return runSafely(function() use ($whereArray){
		$dbHandl = DBCon::getInstnc('sqlte', '', '', '', inTempDir('filter.sqlite'))->getConnex();
		$whrPart = $dbHandl->makeWhere($whereArray);
		$slctQry = 'SELECT `id_show` FROM `shows`'.($whrPart['text']!='' ? ' WHERE '.$whrPart['text'] : '').' ORDER BY `id_show`';
		if( !$dbHandl->sendQuery($slctQry, false, $whrPart['vals']) ){
			return 'FAILED '.$dbHandl->lastError();
		}
		return Array($whrPart['text'], $whrPart['vals'], array_column($dbHandl->readQuery(true), 'id_show'));
	});
}

// Comparisons
checkSame('equal',            pickShow(Array('venue'=>'Hall B')),  Array('`venue` = ?',  Array('Hall B'), Array(2)));
checkSame('not equal',        pickShow(Array('venue!'=>'Hall B')), Array('`venue` != ?', Array('Hall B'), Array(1, 3, 4)));
checkSame('lower or equal',   pickShow(Array('id_show<='=>2)),     Array('`id_show` <= ?', Array(2), Array(1, 2)));
checkSame('greater or equal', pickShow(Array('id_show>='=>4)),     Array('`id_show` >= ?', Array(4), Array(4, 5)));
checkSame('lower',            pickShow(Array('id_show<'=>2)),      Array('`id_show` < ?',  Array(2), Array(1)));
checkSame('greater',          pickShow(Array('id_show>'=>4)),      Array('`id_show` > ?',  Array(4), Array(5)));

// Wildcards : asked for by the name of the field, * being the only one
checkSame('wildcard',         pickShow(Array('title~'=>'Alpha*')),  Array("`title` LIKE ? ESCAPE '!'",     Array('Alpha%'), Array(1, 3)));
checkSame('negated wildcard', pickShow(Array('title!~'=>'Alpha*')), Array("`title` NOT LIKE ? ESCAPE '!'", Array('Alpha%'), Array(2, 4, 5)));
checkSame('wildcard: in the middle and at the start', Array(pickShow(Array('title~'=>'*a N*'))[2] ?? null, pickShow(Array('title~'=>'*Gig'))[2] ?? null), Array(Array(1), Array(4)));
checkSame('wildcard: without a *, the whole text is matched', pickShow(Array('title~'=>'Solo Gig')), Array("`title` LIKE ? ESCAPE '!'", Array('Solo Gig'), Array(4)));
checkSame('wildcard: % and _ are plain text, escaped for the database', pickShow(Array('title~'=>'%_!*')), Array("`title` LIKE ? ESCAPE '!'", Array('!%!_!!%'), Array()));
checkSame('wildcard: a _ does not stand for any character', Array(pickShow(Array('title~'=>'Solo_Gig'))[2] ?? null, pickShow(Array('title~'=>'Alpha%'))[2] ?? null), Array(Array(), Array()));
checkSame('wildcard: a list is still a list', pickShow(Array('id_show~'=>Array(1, 2), 'title!~'=>Array('Solo Gig'))), Array('`id_show` IN (?,?) AND `title` NOT IN (?)', Array(1, 2, 'Solo Gig'), Array(1, 2)));

// Exact values : what a value holds is never a wildcard
checkSame('a value holding * or % is compared as it is', Array(pickShow(Array('title'=>'Alpha*')), pickShow(Array('title'=>'%'))), Array(Array('`title` = ?', Array('Alpha*'), Array()), Array('`title` = ?', Array('%'), Array())));
checkSame('a negated value holding * is compared as it is', pickShow(Array('title!'=>'Alpha*')), Array('`title` != ?', Array('Alpha*'), Array(1, 2, 3, 4, 5)));
checkSame('a written = keeps wildcard characters as plain text', pickShow(Array('title='=>'Alpha*')), Array('`title` = ?', Array('Alpha*'), Array()));
checkSame('a written = finds the exact value',                 pickShow(Array('title='=>'Beta Evening')), Array('`title` = ?', Array('Beta Evening'), Array(2)));
checkSame('<= and >= are not read as a written =',             pickShow(Array('id_show<='=>1, 'id_show>='=>1)), Array('`id_show` <= ? AND `id_show` >= ?', Array(1, 1), Array(1)));

// Lists
checkSame('list',                  pickShow(Array('id_show'=>Array(1, 2))),  Array('`id_show` IN (?,?)',     Array(1, 2), Array(1, 2)));
checkSame('negated list',          pickShow(Array('id_show!'=>Array(1, 2))), Array('`id_show` NOT IN (?,?)', Array(1, 2), Array(3, 4, 5)));
checkSame('empty list',            pickShow(Array('id_show'=>Array())),      Array('1=0', Array(), Array()));
checkSame('negated empty list',    pickShow(Array('id_show!'=>Array())),     Array('1=1', Array(), Array(1, 2, 3, 4, 5)));
checkSame('a zero stays in a list', pickShow(Array('id_show'=>Array(0, 1))), Array('`id_show` IN (?,?)', Array(0, 1), Array(1)));
checkSame('null and empty strings leave a list', pickShow(Array('id_show'=>Array(null, '', 3))), Array('`id_show` IN (?)', Array(3), Array(3)));

// Fields left unset
checkSame('not set, asked by name', pickShow(Array('venue?'=>false)), Array('`venue` IS NULL',     Array(), Array(5)));
checkSame('set, asked by name',     pickShow(Array('venue?'=>true)),  Array('`venue` IS NOT NULL', Array(), Array(1, 2, 3, 4)));
checkSame('a null value matches nothing',         pickShow(Array('venue'=>null)),  Array('1=0', Array(), Array()));
checkSame('a negated null value matches nothing', pickShow(Array('venue!'=>null)), Array('1=0', Array(), Array()));
checkSame('a null value among other entries',     pickShow(Array('title~'=>'Empty*', 'venue'=>null)), Array("`title` LIKE ? ESCAPE '!' AND 1=0", Array('Empty%'), Array()));
checkLike('the ? suffix refuses a null value',    pickShow(Array('venue?'=>null)), '/^THROWN RuntimeException: The \? suffix takes true or false/');
checkLike('the ? suffix refuses a text value',    pickShow(Array('venue?'=>'false')), '/^THROWN RuntimeException: The \? suffix takes true or false/');

// Combinations and names
checkSame('several entries are joined by AND', pickShow(Array('title~'=>'Alpha*', 'venue!'=>'Hall A')), Array("`title` LIKE ? ESCAPE '!' AND `venue` != ?", Array('Alpha%', 'Hall A'), Array(3)));
checkSame('an empty filter gives no clause',   pickShow(Array()), Array('', Array(), Array(1, 2, 3, 4, 5)));
checkSame('a table.field name',                pickShow(Array('shows.venue'=>'Hall B')), Array('`shows`.`venue` = ?', Array('Hall B'), Array(2)));
checkSame('a value holding a quote is plain text', pickShow(Array('venue'=>"O'Hall' OR '1'='1")), Array('`venue` = ?', Array("O'Hall' OR '1'='1"), Array()));
checkLike('an invalid field name throws', pickShow(Array('venue` = 1 OR `title'=>'x')), '/^THROWN RuntimeException: Invalid table or field name/');

// The reading alone, as every kind of database receives it
checkSame('readWhere gives one condition per entry', runSafely(function(){
	return Filter::readWhere(Array('venue'=>'Hall B', 'id_show>='=>2, 'title!~'=>'Alpha*', 'id_show'=>Array(1, '', 2), 'venue?'=>false, 'title='=>'50%', 'venue~'=>'Hall %*', 'title!'=>'a*', 'venue!~'=>12));
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
checkSame('readWhere: what matches nothing or everything names no field', runSafely(function(){
	return array_column(Filter::readWhere(Array('a'=>null, 'b'=>Array(), 'c!'=>Array(), 'd?'=>true)), 'opert');
}), Array('never', 'never', 'every', 'isset'));
checkLike('readWhere refuses an invalid field name by itself', runSafely(function(){ return Filter::readWhere(Array('venue` OR 1=1 --'=>'x')); }), '/^THROWN RuntimeException: Invalid table or field name/');
checkSame('readWhere of an empty filter', runSafely(function(){ return Filter::readWhere(Array()); }), Array());

endChecks();
