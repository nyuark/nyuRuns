<?php

// Optional database servers for the dialect tests (test/data/MySQLTest.php).
// A server is only used when it is configured and answers: otherwise the
// test skips itself. The tests work in a database of their own, whose name
// must contain "test", and never touch any other.

/**
 * Reads the settings of an optional test server: the NYU_TEST_<TYPE>_*
 * values of the environment file first, then the project's own database
 * block when it is of the same type.
 *
 * @param String $typeLabel Type code of the server: 'mysql', 'pgsql' or 'mongo'.
 * @return Array The 'host', 'port', 'base', 'user' and 'pass' settings, plus 'skip': the reason not to use the server, or ''.
 */
function serverCnf( String $typeLabel ): Array {
	$srvLoad = new nyu\Setup\Loader( Array('setup'=>Array('ftypes'=>'all', 'folder'=>'test'.DS.'fixtures'.DS.'confsrv')) );
	$srvConf = array_map('strval', (array)$srvLoad->getConfig()->getRowVal('SERVERS', $typeLabel));
	if( ($srvConf['user'] ?? '')=='' ){
		// No test account : the account of the project is used, on the test database
		$prjLoad = new nyu\Setup\Loader( Array('setup'=>Array('ftypes'=>'all', 'fnames'=>'database')) );
		$keepCnf = $GLOBALS['nyuConfig'] ?? null;
		$GLOBALS['nyuConfig'] = $prjLoad->getConfig();
		try{
			$prjConf = nyu\Data\DBCon::baseSetup();
		}catch( RuntimeException $e ){
			$prjConf = Array();
		}
		$GLOBALS['nyuConfig'] = $keepCnf;
		if( strtolower(strval($prjConf['type'] ?? ''))==$typeLabel ){
			foreach( Array('host', 'port', 'user', 'pass') as $cnfKey ){
				$srvConf[$cnfKey] = strval($prjConf[$cnfKey] ?? '');
			}
		}
	}
	$srvConf += Array('host'=>'', 'port'=>'', 'base'=>'', 'user'=>'', 'pass'=>'');
	if( $srvConf['host']=='' ) $srvConf['host'] = '127.0.0.1';
	if( $srvConf['base']=='' ) $srvConf['base'] = 'nyuruns_test';
	$srvConf['skip'] = '';
	if( $srvConf['user']=='' && $typeLabel!='mongo' ){
		// A local MongoDB server often asks for no account: it is tried as it is
		$srvConf['skip'] = 'no '.$typeLabel.' test server is configured (see .env.example)';
	}elseif( !preg_match('/^\w+$/D', $srvConf['base']) || stripos($srvConf['base'], 'test')===false ){
		$srvConf['skip'] = 'the name of the test database must be a plain name containing "test"';
	}elseif( $srvConf['port']!='' && !ctype_digit($srvConf['port']) ){
		$srvConf['skip'] = 'the port of the test server is not a number';
	}
	return $srvConf;
}

/**
 * Creates the test database when it does not exist, and fills it with the
 * sample tables (the shows, events and items of test/lib/sample.php).
 *
 * @param String $typeLabel  Type code of the server: 'mysql' or 'pgsql'.
 * @param Array  $serverConf Settings returned by serverCnf().
 * @return String '' when the database is ready, or the reason it is not.
 */
function buildSrvr( String $typeLabel, Array $serverConf ): String {
	$dsnHead = $typeLabel.':host='.$serverConf['host'].($serverConf['port']!='' ? ';port='.$serverConf['port'] : '');
	$pdoOpts = Array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3);
	$baseNme = $serverConf['base'];
	try{
		if( $typeLabel=='mysql' ){
			$admLink = new PDO($dsnHead, $serverConf['user'], $serverConf['pass'], $pdoOpts);
			$admLink->exec('CREATE DATABASE IF NOT EXISTS `'.$baseNme.'` CHARACTER SET utf8');
			$admLink = null;
			$autoKey = 'INT AUTO_INCREMENT PRIMARY KEY';
			$tblTail = ' ENGINE=InnoDB';
			$nameQte = '`';
		}elseif( $typeLabel=='pgsql' ){
			$admLink = new PDO($dsnHead.';dbname=postgres', $serverConf['user'], $serverConf['pass'], $pdoOpts);
			$hasBase = $admLink->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
			$hasBase->execute( Array($baseNme) );
			if( $hasBase->fetchColumn()===false ){
				$admLink->exec('CREATE DATABASE "'.$baseNme.'"');
			}
			$hasBase = null;
			$admLink = null;
			$autoKey = 'SERIAL PRIMARY KEY';
			$tblTail = '';
			$nameQte = '"';
		}else{
			return 'no sample data is defined for '.$typeLabel;
		}
		$pdoLink = new PDO($dsnHead.';dbname='.$baseNme, $serverConf['user'], $serverConf['pass'], $pdoOpts);
		foreach( Array('items', 'events', 'shows') as $tblNme ){
			$pdoLink->exec('DROP TABLE IF EXISTS '.$nameQte.$tblNme.$nameQte);
		}
		// The quote character of the server replaces the double quotes below
		$sqlList = Array(
			'CREATE TABLE "shows" ("id_show" '.$autoKey.', "title" VARCHAR(100), "venue" VARCHAR(100))'.$tblTail,
			'CREATE TABLE "events" ("id_event" '.$autoKey.', "show_id" INT, "date" VARCHAR(10), "time" VARCHAR(5))'.$tblTail,
			'CREATE TABLE "items" ("id_item" '.$autoKey.', "show_id" INT, "type" VARCHAR(20))'.$tblTail,
			'INSERT INTO "shows" ("title", "venue") VALUES (\'Alpha Night\',\'Hall A\'),(\'Beta Evening\',\'Hall B\'),(\'Alpha Matinee\',\'Hall C\'),(\'Solo Gig\',\'Hall S\'),(\'Empty Stage\',NULL)',
			'INSERT INTO "events" ("show_id", "date", "time") VALUES (1,\'2026-12-28\',\'20:00\'),(1,\'2027-01-03\',\'20:00\'),(2,\'2026-11-02\',\'19:00\'),(2,\'2026-11-09\',\'19:00\'),(3,\'2026-10-05\',\'14:00\'),(3,\'2026-10-06\',\'14:00\'),(4,\'2026-12-01\',\'21:00\')',
			'INSERT INTO "items" ("show_id", "type") VALUES (1,\'music\'),(1,\'dance\'),(2,\'theatre\'),(2,\'music\'),(3,\'dance\'),(3,\'circus\'),(4,\'solo\')'
		);
		foreach( $sqlList as $sqlTxt ){
			$pdoLink->exec( str_replace('"', $nameQte, $sqlTxt) );
		}
		$pdoLink = null;
	}catch( PDOException $e ){
		return 'the '.$typeLabel.' server did not accept the test database: '.$e->getMessage();
	}
	return '';
}

/**
 * Points the framework's database configuration at a test server.
 *
 * @param String $typeLabel  Type code of the server.
 * @param Array  $serverConf Settings returned by serverCnf().
 */
function useServer( String $typeLabel, Array $serverConf ): Void {
	$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
		'database'=>Array('TYPE'=>$typeLabel, 'HOST'=>$serverConf['host'], 'PORT'=>$serverConf['port'], 'USER'=>$serverConf['user'], 'PASS'=>$serverConf['pass'], 'BASE'=>$serverConf['base'], 'CHAR'=>'', 'AUTH'=>'', 'WAIT'=>'5')
	) );
}
