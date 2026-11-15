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
 * @param String $typeLabelTxt Type code of the server: 'mysql', 'pgsql' or 'mongo'.
 * @return Array The 'hostName', 'portNumb', 'baseName', 'userName' and 'passWord' settings, plus 'skip': the reason not to use the server, or ''.
 */
function serverSettings( String $typeLabelTxt ): Array {
	$srvrLod = new nyu\Setup\Loader( Array('settings'=>Array('confType'=>'all', 'confPath'=>'test'.DS.'fixtures'.DS.'confsrv')) );
	$hostCfg = array_map('strval', (array)$srvrLod->readConfigRegr()->getNestedValue('SERVERS', $typeLabelTxt));
	if( ($hostCfg['userName'] ?? '')=='' ){
		// No test account : the account of the project is used, on the test database
		$loadObj = new nyu\Setup\Loader( Array('settings'=>Array('confType'=>'all', 'confList'=>'database')) );
		$keepCnf = $GLOBALS['nyuConfig'] ?? null;
		$GLOBALS['nyuConfig'] = $loadObj->readConfigRegr();
		try{
			$projCfg = nyu\Data\DBCon::readDbaseConfg();
		}catch( RuntimeException $e ){
			$projCfg = Array();
		}
		$GLOBALS['nyuConfig'] = $keepCnf;
		if( strtolower(strval($projCfg['dbEngine'] ?? ''))==Array('mysql'=>'mysql_db', 'pgsql'=>'postgres', 'mongo'=>'mongo_db')[$typeLabelTxt] ){
			foreach( Array('hostName', 'portNumb', 'userName', 'passWord') as $cnfKey ){
				$hostCfg[$cnfKey] = strval($projCfg[$cnfKey] ?? '');
			}
		}
	}
	$hostCfg += Array('hostName'=>'', 'portNumb'=>'', 'baseName'=>'', 'userName'=>'', 'passWord'=>'');
	if( $hostCfg['hostName']=='' ) $hostCfg['hostName'] = '127.0.0.1';
	if( $hostCfg['baseName']=='' ) $hostCfg['baseName'] = 'nyuruns_test';
	$hostCfg['skip'] = '';
	if( $hostCfg['userName']=='' && $typeLabelTxt!='mongo' ){
		// A local MongoDB server often asks for no account: it is tried as it is
		$hostCfg['skip'] = 'no '.$typeLabelTxt.' test server is configured (see .env.example)';
	}elseif( !preg_match('/^\w+$/D', $hostCfg['baseName']) || stripos($hostCfg['baseName'], 'test')===false ){
		$hostCfg['skip'] = 'the name of the test database must be a plain name containing "test"';
	}elseif( $hostCfg['portNumb']!='' && !ctype_digit($hostCfg['portNumb']) ){
		$hostCfg['skip'] = 'the port of the test server is not a number';
	}
	return $hostCfg;
}

/**
 * Creates the test database when it does not exist, and fills it with the
 * sample tables (the shows, events and items of test/lib/sample.php).
 *
 * @param String $typeLabelTxt  Type code of the server: 'mysql' or 'pgsql'.
 * @param Array  $serverConfig Settings returned by serverSettings().
 * @return String '' when the database is ready, or the reason it is not.
 */
function generateServer( String $typeLabelTxt, Array $serverConfig ): String {
	$dsnHead = $typeLabelTxt.':host='.$serverConfig['hostName'].($serverConfig['portNumb']!='' ? ';port='.$serverConfig['portNumb'] : '');
	$pdoArgs = Array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>3);
	$baseNme = $serverConfig['baseName'];
	try{
		if( $typeLabelTxt=='mysql' ){
			$admLink = new PDO($dsnHead, $serverConfig['userName'], $serverConfig['passWord'], $pdoArgs);
			$admLink->exec('CREATE DATABASE IF NOT EXISTS `'.$baseNme.'` CHARACTER SET utf8');
			$admLink = null;
			$autoKey = 'INT AUTO_INCREMENT PRIMARY KEY';
			$tblTail = ' ENGINE=InnoDB';
			$nameQut = '`';
		}elseif( $typeLabelTxt=='pgsql' ){
			$admLink = new PDO($dsnHead.';dbname=postgres', $serverConfig['userName'], $serverConfig['passWord'], $pdoArgs);
			$hasBase = $admLink->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
			$hasBase->execute( Array($baseNme) );
			if( $hasBase->fetchColumn()===false ){
				$admLink->exec('CREATE DATABASE "'.$baseNme.'"');
			}
			$hasBase = null;
			$admLink = null;
			$autoKey = 'SERIAL PRIMARY KEY';
			$tblTail = '';
			$nameQut = '"';
		}else{
			return 'no sample data is defined for '.$typeLabelTxt;
		}
		$pdoLink = new PDO($dsnHead.';dbname='.$baseNme, $serverConfig['userName'], $serverConfig['passWord'], $pdoArgs);
		foreach( Array('items', 'events', 'shows') as $tblNme ){
			$pdoLink->exec('DROP TABLE IF EXISTS '.$nameQut.$tblNme.$nameQut);
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
			$pdoLink->exec( str_replace('"', $nameQut, $sqlTxt) );
		}
		$pdoLink = null;
	}catch( PDOException $e ){
		return 'the '.$typeLabelTxt.' server did not accept the test database: '.$e->getMessage();
	}
	return '';
}

/**
 * Points the framework's database configuration at a test server.
 *
 * @param String $typeLabelTxt  Type code of the server.
 * @param Array  $serverConfig Settings returned by serverSettings().
 */
function switchToServer( String $typeLabelTxt, Array $serverConfig ): Void {
	$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
		'database'=>Array('DBENGINE'=>Array('mysql'=>'mysql_db', 'pgsql'=>'postgres', 'mongo'=>'mongo_db')[$typeLabelTxt], 'HOSTNAME'=>$serverConfig['hostName'], 'PORTNUMB'=>$serverConfig['portNumb'], 'USERNAME'=>$serverConfig['userName'], 'PASSWORD'=>$serverConfig['passWord'], 'BASENAME'=>$serverConfig['baseName'], 'ENCODING'=>'', 'AUTHBASE'=>'', 'WAITSECS'=>'5')
	) );
}
