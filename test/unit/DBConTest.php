<?php

// Data\DBCon : the choice of the database handler from its type, without
// opening any connection.

require __DIR__.'/../lib/harness.php';

use nyu\Data\DBCon;

checkPregMatch('an unknown type is refused', runCatchThrown(function(){ return DBCon::obtainInstance('Oracle', '', '', '', ''); }), '/^THROWN RuntimeException: Unknown database type: Oracle/');
checkPregMatch('a class that is not a handler is refused', runCatchThrown(function(){ return DBCon::obtainInstance('Handler', '', '', '', ''); }), '/^THROWN RuntimeException: Unknown database type: Handler/');
checkPregMatch('a type that is not a plain name is refused', runCatchThrown(function(){ return DBCon::obtainInstance('..\\..\\Tools\\Logger', '', '', '', ''); }), '/^THROWN RuntimeException: Unknown database type/');
checkPregMatch('an empty type is refused', runCatchThrown(function(){ return DBCon::obtainInstance('', '', '', '', ''); }), '/^THROWN RuntimeException: Unknown database type/');

// The handlers themselves
checkIdentical('the PDO base cannot be used on its own', (new ReflectionClass('nyu\Data\DBHndlr\PDO'))->isAbstract(), true);
checkIdentical('the MySQL handler is complete', (new ReflectionClass('nyu\Data\DBHndlr\MySQL'))->isInstantiable(), true);
checkIdentical('the SQLite handler is complete', (new ReflectionClass('nyu\Data\DBHndlr\SQLite'))->isInstantiable(), true);

/**
 * Calls a protected method of a handler, without opening a connection.
 *
 * @param String $handlerCls Class of the handler.
 * @param String $methodName Method to call.
 * @param Array  $callValues Arguments of the call.
 * @return mixed What the method returns, or a 'THROWN ...' string.
 */
function askHandlr( String $handlerCls, String $methodName, Array $callValues ): Mixed {
	return runCatchThrown(function() use ($handlerCls, $methodName, $callValues){
		$clsRflx = new ReflectionClass($handlerCls);
		$mthRflx = $clsRflx->getMethod($methodName);
		return $mthRflx->invokeArgs($clsRflx->newInstanceWithoutConstructor(), $callValues);
	});
}

// MySQL connection string
$mysqlCl = 'nyu\Data\DBHndlr\MySQL';
checkIdentical('MySQL: host and base only',       askHandlr($mysqlCl, 'buildDsnString', Array('localhost', 'shop')), 'mysql:host=localhost;dbname=shop');
checkIdentical('MySQL: empty settings add nothing', askHandlr($mysqlCl, 'buildDsnString', Array('localhost', 'shop', Array('portNumb'=>'', 'encoding'=>''))), 'mysql:host=localhost;dbname=shop');
checkIdentical('MySQL: port and character set',   askHandlr($mysqlCl, 'buildDsnString', Array('localhost', 'shop', Array('portNumb'=>'8889', 'encoding'=>'utf8'))), 'mysql:host=localhost;dbname=shop;port=8889;charset=utf8');
checkIdentical('MySQL: a port given as a number', askHandlr($mysqlCl, 'buildDsnString', Array('localhost', 'shop', Array('portNumb'=>3307))), 'mysql:host=localhost;dbname=shop;port=3307');
checkPregMatch('MySQL: a port that is not a number is refused', askHandlr($mysqlCl, 'buildDsnString', Array('localhost', 'shop', Array('portNumb'=>'33o6'))), '/^THROWN RuntimeException: Invalid database port/');
checkPregMatch('MySQL: a collation is not a character set',     askHandlr($mysqlCl, 'buildDsnString', Array('localhost', 'shop', Array('encoding'=>'utf8_general_ci'))), '/^THROWN RuntimeException: Invalid database character set/');
checkPregMatch('MySQL: a character set cannot add settings',    askHandlr($mysqlCl, 'buildDsnString', Array('localhost', 'shop', Array('encoding'=>'utf8;host=evil'))), '/^THROWN RuntimeException: Invalid database character set/');

// PostgreSQL connection string, quoting and wildcard operator
$pgsqlCl = 'nyu\Data\DBHndlr\PgSQL';
checkIdentical('the PostgreSQL handler is complete', (new ReflectionClass($pgsqlCl))->isInstantiable(), true);
checkIdentical('PostgreSQL: host and base only',     askHandlr($pgsqlCl, 'buildDsnString', Array('localhost', 'shop')), 'pgsql:host=localhost;dbname=shop');
checkIdentical('PostgreSQL: port and character set', askHandlr($pgsqlCl, 'buildDsnString', Array('localhost', 'shop', Array('portNumb'=>'5433', 'encoding'=>'UTF8'))), "pgsql:host=localhost;dbname=shop;port=5433;options='--client_encoding=UTF8'");
checkPregMatch('PostgreSQL: a character set cannot add settings', askHandlr($pgsqlCl, 'buildDsnString', Array('localhost', 'shop', Array('encoding'=>"UTF8' -c x=y"))), '/^THROWN RuntimeException: Invalid database character set/');
checkIdentical('PostgreSQL: names take double quotes', askHandlr($pgsqlCl, 'quoteFieldName', Array('shows.userHandle')), '"shows"."userHandle"');
checkIdentical('PostgreSQL: wildcard searches ignore the case', Array(askHandlr($pgsqlCl, 'getLikeKeyword', Array()), askHandlr($mysqlCl, 'getLikeKeyword', Array()), askHandlr('nyu\Data\DBHndlr\SQLite', 'getLikeKeyword', Array())), Array('ILIKE', 'LIKE', 'LIKE'));

// SQLite file path
$sqlteCl = 'nyu\Data\DBHndlr\SQLite';
checkIdentical('SQLite: a relative path starts from the project root', askHandlr($sqlteCl, 'locateBaseFile', Array('data'.DS.'app.sqlite')), TOP_DIR.DS.'data'.DS.'app.sqlite');
checkIdentical('SQLite: an absolute path is kept',  askHandlr($sqlteCl, 'locateBaseFile', Array(inTemporaryDir('app.sqlite'))), inTemporaryDir('app.sqlite'));
checkIdentical('SQLite: a rooted path is kept',     askHandlr($sqlteCl, 'locateBaseFile', Array('/var/data/app.sqlite')), '/var/data/app.sqlite');
checkIdentical('SQLite: the in-memory database is kept', askHandlr($sqlteCl, 'locateBaseFile', Array(':memory:')), ':memory:');

// Waiting time, shared by the SQL handlers
checkIdentical('wait: no option when left out',  askHandlr($mysqlCl, 'pdoOptionArray', Array(Array('portNumb'=>'3306'))), Array());
checkIdentical('wait: seconds, as a PDO option', Array(askHandlr($mysqlCl, 'pdoOptionArray', Array(Array('waitSecs'=>'5'))), askHandlr($pgsqlCl, 'pdoOptionArray', Array(Array('waitSecs'=>3))), askHandlr($sqlteCl, 'pdoOptionArray', Array(Array('waitSecs'=>'10')))), Array(Array(PDO::ATTR_TIMEOUT=>5), Array(PDO::ATTR_TIMEOUT=>3), Array(PDO::ATTR_TIMEOUT=>10)));
checkPregMatch('wait: anything but a number is refused', askHandlr($mysqlCl, 'pdoOptionArray', Array(Array('waitSecs'=>'5s'))), '/^THROWN RuntimeException: Invalid database waiting time/');

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
	return runCatchThrown(function() use ($baseLabel){ return DBCon::baseSetupArray($baseLabel); });
}

$mainSet = Array('dbEngine'=>'mysql_db', 'hostName'=>'localhost', 'portNumb'=>'', 'baseName'=>'shop', 'userName'=>'web', 'password'=>'s3', 'encoding'=>'utf8', 'authBase'=>'', 'waitSecs'=>'');
$statSet = Array('dbEngine'=>'postgres', 'hostName'=>'10.0.0.5', 'portNumb'=>'5433', 'baseName'=>'stats', 'userName'=>'ro', 'password'=>'', 'encoding'=>'', 'authBase'=>'admin', 'waitSecs'=>'5');
$twoBase = Array('NYURUNS'=>$mainSet, 'SHPSTAT'=>$statSet);

checkIdentical('single block: it is the default database', setupOf(array_change_key_case($mainSet, CASE_UPPER)), $mainSet);
checkIdentical('single block: settings left out are empty', setupOf(Array('DBENGINE'=>'sqlitedb', 'BASENAME'=>'data/app.sqlite')), Array('dbEngine'=>'sqlitedb', 'hostName'=>'', 'portNumb'=>'', 'baseName'=>'data/app.sqlite', 'userName'=>'', 'password'=>'', 'encoding'=>'', 'authBase'=>'', 'waitSecs'=>''));
checkIdentical('single block: anything else in it is left aside', setupOf(Array('DBENGINE'=>'sqlitedb', 'BASENAME'=>'app.sqlite', 'MORE'=>Array('fileType'=>'all'), 'EXTRA'=>'x'))['baseName'] ?? null, 'app.sqlite');
checkPregMatch('single block: it has no name to ask for', setupOf(array_change_key_case($mainSet, CASE_UPPER), 'nyuRuns'), '/^THROWN RuntimeException: Unknown database: nyuRuns/');
checkIdentical('named blocks: the first one is the default', setupOf($twoBase), $mainSet);
checkIdentical('named blocks: one asked by its name', setupOf($twoBase, 'shpStat'), $statSet);
checkIdentical('named blocks: the name is matched whatever its case', setupOf($twoBase, 'NYURUNS'), $mainSet);
checkPregMatch('named blocks: an unknown name is refused', setupOf($twoBase, 'archive'), '/^THROWN RuntimeException: Unknown database: archive/');
checkPregMatch('no database block', setupOf(null), '/^THROWN RuntimeException: No database is configured/');
checkPregMatch('an empty database block', setupOf(Array()), '/^THROWN RuntimeException: No database is configured/');

// The project's own configuration, as the loader reads it: conf/database.json is kept out of
// the repository, so it is only checked where it exists
$prjLoad = new nyu\Setup\Loader( Array('settings'=>Array('fileType'=>'all', 'fileName'=>'database')) );
$prjBase = $prjLoad->retrieveConfig()->readValueByKey('database');
if( !is_file(TOP_DIR.DS.'conf'.DS.'database.json') ){
	recordSkipping('conf/database.json', 'the file is not there: "php nyuRun installs makeConf" creates it');
}else{
	checkIdentical('conf/database.json: the default database is nyuRuns', is_array($prjBase) ? strtolower(strval(array_key_first($prjBase))) : null, 'nyuruns');
	checkIdentical('conf/database.json: the keys of its block', is_array($prjBase) ? array_keys((array)current($prjBase)) : null, Array('dbEngine', 'hostName', 'portNumb', 'baseName', 'userName', 'password', 'encoding'));
	$GLOBALS['nyuConfig'] = $prjLoad->retrieveConfig();
	checkTrueValue('conf/database.json: the type is a known code', in_array(runCatchThrown(function(){ return DBCon::baseSetupArray()['dbEngine']; }), Array('mysql_db', 'sqlitedb', 'postgres'), true));
}

finishChecking();
