<?php

// Data\DBCon : the choice of the database handler from its type, without
// opening any connection.

require __DIR__.'/../lib/harness.php';

use nyu\Data\DBCon;

checkLike('an unknown type is refused', runSafely(function(){ return DBCon::getInstnc('Oracle', '', '', '', ''); }), '/^THROWN RuntimeException: Unknown database type: Oracle/');
checkLike('a class that is not a handler is refused', runSafely(function(){ return DBCon::getInstnc('Handler', '', '', '', ''); }), '/^THROWN RuntimeException: Unknown database type: Handler/');
checkLike('a type that is not a plain name is refused', runSafely(function(){ return DBCon::getInstnc('..\\..\\Tools\\Logger', '', '', '', ''); }), '/^THROWN RuntimeException: Unknown database type/');
checkLike('an empty type is refused', runSafely(function(){ return DBCon::getInstnc('', '', '', '', ''); }), '/^THROWN RuntimeException: Unknown database type/');

// The handlers themselves
checkSame('the PDO base cannot be used on its own', (new ReflectionClass('nyu\Data\DBHs\PDO'))->isAbstract(), true);
checkSame('the MySQL handler is complete', (new ReflectionClass('nyu\Data\DBHs\MySQL'))->isInstantiable(), true);
checkSame('the SQLite handler is complete', (new ReflectionClass('nyu\Data\DBHs\SQLite'))->isInstantiable(), true);

/**
 * Calls a protected method of a handler, without opening a connection.
 *
 * @param String $handlerCls Class of the handler.
 * @param String $methodName Method to call.
 * @param Array  $callValues Arguments of the call.
 * @return mixed What the method returns, or a 'THROWN ...' string.
 */
function askHandlr( String $handlerCls, String $methodName, Array $callValues ): Mixed {
	return runSafely(function() use ($handlerCls, $methodName, $callValues){
		$clsRflx = new ReflectionClass($handlerCls);
		$mthRflx = $clsRflx->getMethod($methodName);
		$mthRflx->setAccessible(true);
		return $mthRflx->invokeArgs($clsRflx->newInstanceWithoutConstructor(), $callValues);
	});
}

// MySQL connection string
$mysqlCl = 'nyu\Data\DBHs\MySQL';
checkSame('MySQL: host and base only',       askHandlr($mysqlCl, 'dsnString', Array('localhost', 'shop')), 'mysql:host=localhost;dbname=shop');
checkSame('MySQL: empty settings add nothing', askHandlr($mysqlCl, 'dsnString', Array('localhost', 'shop', Array('port'=>'', 'char'=>''))), 'mysql:host=localhost;dbname=shop');
checkSame('MySQL: port and character set',   askHandlr($mysqlCl, 'dsnString', Array('localhost', 'shop', Array('port'=>'8889', 'char'=>'utf8'))), 'mysql:host=localhost;dbname=shop;port=8889;charset=utf8');
checkSame('MySQL: a port given as a number', askHandlr($mysqlCl, 'dsnString', Array('localhost', 'shop', Array('port'=>3307))), 'mysql:host=localhost;dbname=shop;port=3307');
checkLike('MySQL: a port that is not a number is refused', askHandlr($mysqlCl, 'dsnString', Array('localhost', 'shop', Array('port'=>'33o6'))), '/^THROWN RuntimeException: Invalid database port/');
checkLike('MySQL: a collation is not a character set',     askHandlr($mysqlCl, 'dsnString', Array('localhost', 'shop', Array('char'=>'utf8_general_ci'))), '/^THROWN RuntimeException: Invalid database character set/');
checkLike('MySQL: a character set cannot add settings',    askHandlr($mysqlCl, 'dsnString', Array('localhost', 'shop', Array('char'=>'utf8;host=evil'))), '/^THROWN RuntimeException: Invalid database character set/');

// PostgreSQL connection string, quoting and wildcard operator
$pgsqlCl = 'nyu\Data\DBHs\PgSQL';
checkSame('the PostgreSQL handler is complete', (new ReflectionClass($pgsqlCl))->isInstantiable(), true);
checkSame('PostgreSQL: host and base only',     askHandlr($pgsqlCl, 'dsnString', Array('localhost', 'shop')), 'pgsql:host=localhost;dbname=shop');
checkSame('PostgreSQL: port and character set', askHandlr($pgsqlCl, 'dsnString', Array('localhost', 'shop', Array('port'=>'5433', 'char'=>'UTF8'))), "pgsql:host=localhost;dbname=shop;port=5433;options='--client_encoding=UTF8'");
checkLike('PostgreSQL: a character set cannot add settings', askHandlr($pgsqlCl, 'dsnString', Array('localhost', 'shop', Array('char'=>"UTF8' -c x=y"))), '/^THROWN RuntimeException: Invalid database character set/');
checkSame('PostgreSQL: names take double quotes', askHandlr($pgsqlCl, 'quoteName', Array('shows.userName')), '"shows"."userName"');
checkSame('PostgreSQL: wildcard searches ignore the case', Array(askHandlr($pgsqlCl, 'likeOprtr', Array()), askHandlr($mysqlCl, 'likeOprtr', Array()), askHandlr('nyu\Data\DBHs\SQLite', 'likeOprtr', Array())), Array('ILIKE', 'LIKE', 'LIKE'));

// SQLite file path
$sqlteCl = 'nyu\Data\DBHs\SQLite';
checkSame('SQLite: a relative path starts from the project root', askHandlr($sqlteCl, 'locateFle', Array('data'.DS.'app.sqlite')), TOP_DIR.DS.'data'.DS.'app.sqlite');
checkSame('SQLite: an absolute path is kept',  askHandlr($sqlteCl, 'locateFle', Array(inTempDir('app.sqlite'))), inTempDir('app.sqlite'));
checkSame('SQLite: a rooted path is kept',     askHandlr($sqlteCl, 'locateFle', Array('/var/data/app.sqlite')), '/var/data/app.sqlite');
checkSame('SQLite: the in-memory database is kept', askHandlr($sqlteCl, 'locateFle', Array(':memory:')), ':memory:');

// Settings of a configured database
use nyu\Tools\Registry;

/**
 * Reads the settings of a database from a given 'database' config.
 *
 * @param mixed  $baseConfig The 'database' config, as the loader gives it.
 * @param String $baseLabel  Name of the database, '' for the default one.
 * @return mixed The settings, or a 'THROWN ...' string.
 */
function setupOf( Mixed $baseConfig, String $baseLabel='' ): Mixed {
	$GLOBALS['nyuConfig'] = new Registry( ($baseConfig===null) ? Array() : Array('database'=>$baseConfig) );
	return runSafely(function() use ($baseLabel){ return DBCon::baseSetup($baseLabel); });
}

$mainSet = Array('type'=>'mysql', 'host'=>'localhost', 'port'=>'', 'base'=>'shop', 'user'=>'web', 'pass'=>'s3', 'char'=>'utf8');
$statSet = Array('type'=>'pgsql', 'host'=>'10.0.0.5', 'port'=>'5433', 'base'=>'stats', 'user'=>'ro', 'pass'=>'', 'char'=>'');
$twoBase = Array('NYURUNS'=>$mainSet, 'SHPSTAT'=>$statSet);

checkSame('single block: it is the default database', setupOf(array_change_key_case($mainSet, CASE_UPPER)), $mainSet);
checkSame('single block: settings left out are empty', setupOf(Array('TYPE'=>'sqlte', 'BASE'=>'data/app.sqlite')), Array('type'=>'sqlte', 'host'=>'', 'port'=>'', 'base'=>'data/app.sqlite', 'user'=>'', 'pass'=>'', 'char'=>''));
checkSame('single block: anything else in it is left aside', setupOf(Array('TYPE'=>'sqlte', 'BASE'=>'app.sqlite', 'MORE'=>Array('ftypes'=>'all'), 'EXTRA'=>'x'))['base'] ?? null, 'app.sqlite');
checkLike('single block: it has no name to ask for', setupOf(array_change_key_case($mainSet, CASE_UPPER), 'nyuRuns'), '/^THROWN RuntimeException: Unknown database: nyuRuns/');
checkSame('named blocks: the first one is the default', setupOf($twoBase), $mainSet);
checkSame('named blocks: one asked by its name', setupOf($twoBase, 'shpStat'), $statSet);
checkSame('named blocks: the name is matched whatever its case', setupOf($twoBase, 'NYURUNS'), $mainSet);
checkLike('named blocks: an unknown name is refused', setupOf($twoBase, 'archive'), '/^THROWN RuntimeException: Unknown database: archive/');
checkLike('no database block', setupOf(null), '/^THROWN RuntimeException: No database is configured/');
checkLike('an empty database block', setupOf(Array()), '/^THROWN RuntimeException: No database is configured/');

// The project's own configuration, as the loader reads it
$prjLoad = new nyu\Setup\Loader( Array('setup'=>Array('ftypes'=>'all', 'fnames'=>'database')) );
$prjBase = $prjLoad->getConfig()->readValue('database');
checkSame('conf/database.json: the default database is nyuRuns', is_array($prjBase) ? strtolower(strval(array_key_first($prjBase))) : null, 'nyuruns');
checkSame('conf/database.json: the keys of its block', is_array($prjBase) ? array_keys((array)current($prjBase)) : null, Array('type', 'host', 'port', 'base', 'user', 'pass', 'char'));
$GLOBALS['nyuConfig'] = $prjLoad->getConfig();
checkTrue('conf/database.json: the type is a known code', in_array(runSafely(function(){ return DBCon::baseSetup()['type']; }), Array('mysql', 'sqlte', 'pgsql'), true));

endChecks();
