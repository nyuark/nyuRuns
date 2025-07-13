<?php

// Sets up the native userbase (Access\Auth\Native), from the command line:
//
//   php conf/schema/setup.php [block]
//
// <block> is the name of the strategy's config block in conf/access.json
// ('native' when left out). The users table is created in the database the
// block names, from the users.<type>.sql file of its type, then given the
// basic users, one per role: member, system and managr. Each gets a random
// password, shown once: only its hash is kept.
// A table that already holds users is left as it is.

use nyu\Access\Auth\Native;
use nyu\Data\DBCon;
use nyu\Setup\Loader;

if( PHP_SAPI!=='cli' ){
	exit(1);
}

define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', dirname(__DIR__, 2));
define('WEB_DIR', TOP_DIR.DS.'www');
define('RUN_DIR', TOP_DIR.DS.'runs');
require TOP_DIR.DS.'vendor'.DS.'autoload.php';

// The basic users, and the role of each
$usrList = Array('member'=>'member', 'system'=>'system', 'managr'=>'manage');

/**
 * Stops the set-up, telling why.
 *
 * @param String $stopReason What went wrong.
 */
function stopSetup( String $stopReason ): Void {
	fwrite(STDERR, 'Set-up stopped: '.$stopReason.PHP_EOL);
	exit(1);
}

try{
	$GLOBALS['nyuConfig'] = (new Loader( Array('setup'=>Array('ftypes'=>'all')) ))->getConfig();
	$blkName = strval($argv[1] ?? 'native');
	$blkConf = $GLOBALS['nyuConfig']->getRowVal('ACCESS', strtoupper($blkName));
	if( !is_array($blkConf) ){
		stopSetup('conf/access.json has no "'.$blkName.'" block');
	}
	$baseNme = strval($blkConf['basnme'] ?? '');
	$tblName = strval($blkConf['tblnme'] ?? 'users');
	$baseTyp = strval(DBCon::baseSetup($baseNme)['type']);
	$sqlFile = __DIR__.DS.'users.'.$baseTyp.'.sql';
	if( !preg_match('/^\w+$/D', $baseTyp) || !is_file($sqlFile) ){
		stopSetup('there is no users schema for a database of type "'.$baseTyp.'"');
	}
	$dbHandl = DBCon::linkNamed($baseNme)->getConnex();
	$qtdTble = $dbHandl->quoteName($tblName);

	if( $dbHandl->tableInfo($tblName)===false ){
		// The schema names its table 'users' : it is given the configured name
		$makeSql = preg_replace('/^CREATE TABLE \S+/m', 'CREATE TABLE '.$qtdTble, strval(file_get_contents($sqlFile)), 1);
		if( !$dbHandl->sendQuery($makeSql, true) ){
			stopSetup('the table cannot be created: '.strval($dbHandl->lastError()));
		}
		echo 'Table '.$tblName.' created.'.PHP_EOL;
	}

	$rowList = $dbHandl->sendQuery('SELECT COUNT(*) AS '.$dbHandl->quoteName('usrCount').' FROM '.$qtdTble.';') ? $dbHandl->readQuery(true) : false;
	if( !is_array($rowList) ){
		stopSetup('the table cannot be read: '.strval($dbHandl->lastError()));
	}
	if( intval($rowList[0]['usrCount'] ?? 0)>0 ){
		stopSetup('table '.$tblName.' already holds users; nothing was changed');
	}

	$natAuth = new Native($blkConf);
	$passLst = Array();
	$addQury = 'INSERT INTO '.$qtdTble.' ('.$dbHandl->quoteName('userName').', '.$dbHandl->quoteName('passHash').', '.$dbHandl->quoteName('userRole').', '.$dbHandl->quoteName('userStat').") VALUES (?, '!', ?, 'active');";
	$dbHandl->opnTrnsct();
	foreach( $usrList as $usrNme=>$usrRle ){
		$passLst[$usrNme] = bin2hex(random_bytes(10));
		// The row is first written with a hash nothing matches, then given its password
		if( !$dbHandl->sendQuery($addQury, true, Array($usrNme, $usrRle)) || !$natAuth->setPasswd($usrNme, $passLst[$usrNme]) ){
			$dbHandl->remTrnsct();
			stopSetup('user '.$usrNme.' cannot be written; nothing was changed');
		}
	}
	$dbHandl->setTrnsct();
}catch( \Throwable $e ){
	stopSetup($e->getMessage());
}

echo 'Users created. Their passwords are shown this once:'.PHP_EOL;
foreach( $usrList as $usrNme=>$usrRle ){
	echo '  '.$usrNme.'  ('.$usrRle.')  '.$passLst[$usrNme].PHP_EOL;
}
