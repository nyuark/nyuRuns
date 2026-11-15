<?php

// Data\DBCon : several configured databases, each with its own connection,
// on two sample SQLite files (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Several databases', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Data\DBCon;
use nyu\Tools\Registry;

createSampleDb( inTemporaryDir('main.sqlite') );
createSampleDb( inTemporaryDir('arch.sqlite') );

// The archive only keeps one show, so that the two files can be told apart
$archLnk = new PDO('sqlite:'.inTemporaryDir('arch.sqlite'));
$archLnk->exec('DELETE FROM shows WHERE id_show>1');
$archLnk->exec('DELETE FROM events WHERE id_event>1');
$archLnk = null;

$GLOBALS['nyuConfig'] = new Registry( Array('database'=>Array(
	'NYURUNS'=>Array('dbEngine'=>'sqlitedb', 'baseName'=>inTemporaryDir('main.sqlite')),
	'ARCHIVE'=>Array('dbEngine'=>'sqlitedb', 'baseName'=>inTemporaryDir('arch.sqlite'), 'waitSecs'=>'2'),
	'BROKEN7'=>Array('dbEngine'=>'oracle', 'baseName'=>'nowhere')
)) );

/**
 * Counts the shows a connection sees.
 *
 * @param DBCon $handleLink The connection.
 * @return Int The number of shows.
 */
function showsOn( DBCon $handleLink ): Int {
	$handleLink->readConnection()->sendBuiltQuery('SELECT * FROM `shows`');
	return count($handleLink->readConnection()->readLastResult(true));
}

checkIdentical('the default database is the first block', runCatchThrown(function(){ return showsOn(DBCon::linkNamedDbCon()); }), 5);
checkIdentical('a database asked by its name', runCatchThrown(function(){ return showsOn(DBCon::linkNamedDbCon('archive')); }), 1);
checkTrueValue('each database has its own connection', runCatchThrown(function(){ return DBCon::linkNamedDbCon()!==DBCon::linkNamedDbCon('archive'); }));
checkTrueValue('a database is connected once', runCatchThrown(function(){ return DBCon::linkNamedDbCon('archive')===DBCon::linkNamedDbCon('ARCHIVE'); }));
checkTrueValue('the default database, by its name, is the same connection', runCatchThrown(function(){ return DBCon::linkNamedDbCon('nyuRuns')===DBCon::linkNamedDbCon(); }));
checkTrueValue('getInstnc returns the default connection already open', runCatchThrown(function(){ return DBCon::obtainInstance('sqlitedb', '', '', '', inTemporaryDir('arch.sqlite'))===DBCon::linkNamedDbCon(); }));
checkIdentical('...whatever settings it is given', runCatchThrown(function(){ return showsOn(DBCon::obtainInstance('sqlitedb', '', '', '', inTemporaryDir('arch.sqlite'))); }), 5);
checkPregMatch('an unknown name is refused', runCatchThrown(function(){ return DBCon::linkNamedDbCon('nowhere'); }), '/^THROWN RuntimeException: Unknown database: nowhere/');
checkPregMatch('a database is only opened when asked for: a faulty one does not stop the others', runCatchThrown(function(){ return DBCon::linkNamedDbCon('broken7'); }), '/^THROWN RuntimeException: Unknown database type: oracle/');

// A transaction belongs to its connection
checkIdentical('a transaction on one database leaves the other alone', runCatchThrown(function(){
	$mainHdl = DBCon::linkNamedDbCon()->readConnection();
	$archHdl = DBCon::linkNamedDbCon('archive')->readConnection();
	$mainHdl->newTransaction();
	$mainHdl->sendBuiltQuery('DELETE FROM `shows` WHERE `id_show`=?', true, Array(5));
	$archHdl->sendBuiltQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Vault', 1));
	$mainHdl->delTransaction();
	$archHdl->sendBuiltQuery('SELECT `venue` FROM `shows` WHERE `id_show`=?', false, Array(1));
	return Array(showsOn(DBCon::linkNamedDbCon()), $archHdl->readLastResult(true)[0]['venue']);
}), Array(5, 'Vault'));

// Models naming their database
class MainShow extends nyu\Data\DBLst {
	/**
	 * A list of shows that names no database.
	 */
	public function __construct(){
		parent::__construct('shows');
	}
}
class ArchShow extends nyu\Data\DBLst {
	protected $dbLinkName = 'archive';
	/**
	 * A list of shows in the archive, unless a connection is given.
	 *
	 * @param ?DBCon $handleLink Connection to use instead.
	 */
	public function __construct( ?DBCon $handleLink=null ){
		parent::__construct('shows', null, null, null, null, $handleLink);
	}
}
class ArchRecd extends nyu\Data\DBRec {
	protected $dbLinkName = 'archive';
	/**
	 * A show of the archive, with its events.
	 */
	public function __construct(){
		parent::__construct('shows', Array('events'));
	}
}
class LostShow extends nyu\Data\DBLst {
	protected $dbLinkName = 'nowhere';
	/**
	 * A list naming a database that is not configured.
	 */
	public function __construct(){
		parent::__construct('shows');
	}
}

checkIdentical('a model naming no database reads the default one', runCatchThrown(function(){ $showLst = new MainShow(); return count($showLst->readRowsOfList()); }), 5);
checkIdentical('a model reads the database it names', runCatchThrown(function(){ $showLst = new ArchShow(); return count($showLst->readRowsOfList()); }), 1);
checkIdentical('a connection given to the model wins over its name', runCatchThrown(function(){ $showLst = new ArchShow(DBCon::linkNamedDbCon()); return count($showLst->readRowsOfList()); }), 5);
checkIdentical('a plain list on a given connection', runCatchThrown(function(){
	$showLst = new nyu\Data\DBLst('shows', null, null, null, null, DBCon::linkNamedDbCon('archive'));
	return count($showLst->readRowsOfList());
}), 1);
checkPregMatch('a model naming an unknown database is refused', runCatchThrown(function(){ return new LostShow(); }), '/^THROWN RuntimeException: Unknown database: nowhere/');
checkIdentical('the children of a record come from its own database', runCatchThrown(function(){
	$archRec = new ArchRecd();
	$mainRec = new nyu\Data\DBRec('shows', Array('events'));
	return Array(count($archRec->loadRecordList('1')['Events']), count($mainRec->loadRecordList('1')['Events']));
}), Array(1, 2));
checkIdentical('a record saved in one database leaves the other unchanged', runCatchThrown(function(){
	$archRec = new ArchRecd();
	$archRec->loadRecordList('1');
	$archRec->bindFieldArray(Array('title'=>'Alpha Night (archived)'));
	$archRec->insertOrUpdate();
	$mainRec = new nyu\Data\DBRec('shows');
	$readRec = new ArchRecd();
	return Array($mainRec->loadRecordList('1')['title'], $readRec->loadRecordList('1')['title']);
}), Array('Alpha Night', 'Alpha Night (archived)'));
checkIdentical('a record deleted with its children, in its own database only', runCatchThrown(function(){
	$archRec = new ArchRecd();
	$archRec->loadRecordList('1');
	$remDone = $archRec->removeRecdFlag(Array('events'));
	$archEvt = new nyu\Data\DBLst('events', null, null, null, null, DBCon::linkNamedDbCon('archive'));
	$mainEvt = new nyu\Data\DBLst('events');
	return Array($remDone, count($archEvt->readRowsOfList()), count($mainEvt->readRowsOfList()), showsOn(DBCon::linkNamedDbCon()));
}), Array(true, 0, 7, 5));

// The native userbase in a named database
use nyu\Access\Auth\Native;

// The archive has lost ben, so that the two users tables can be told apart
DBCon::linkNamedDbCon('archive')->readConnection()->sendBuiltQuery('DELETE FROM `users` WHERE `systemName`=?', true, Array('ben'));
$benCred = Array('user'=>'ben', 'pass'=>'pw-ben');
$annCred = Array('user'=>'anna', 'pass'=>'pw-anna');

checkIdentical('Native without linkName reads the default database', runCatchThrown(function() use ($benCred){
	$natAuth = new Native( Array('useTable'=>'users') );
	return $natAuth->checkSecretDct($benCred)['userName'] ?? null;
}), 'ben');
checkIdentical('Native with an empty linkName reads the default database', runCatchThrown(function() use ($benCred){
	$natAuth = new Native( Array('linkName'=>'', 'useTable'=>'users') );
	return $natAuth->checkSecretDct($benCred)['userName'] ?? null;
}), 'ben');
checkIdentical('Native reads the database linkName names', runCatchThrown(function() use ($benCred, $annCred){
	$natAuth = new Native( Array('linkName'=>'archive', 'useTable'=>'users') );
	return Array($natAuth->checkSecretDct($annCred)['userName'] ?? null, $natAuth->checkSecretDct($benCred), $natAuth->getLastErrText());
}), Array('anna', false, 'Invalid credentials'));
checkIdentical('Native on an unknown database tells nothing more', runCatchThrown(function() use ($annCred){
	$natAuth = new Native( Array('linkName'=>'nowhere', 'useTable'=>'users') );
	return Array($natAuth->checkSecretDct($annCred), $natAuth->getLastErrText(), $natAuth->makeToknString('anna'), $natAuth->listOnlineRecs());
}), Array(false, 'Credential store unavailable', false, false));
checkIdentical('a token made in one database is unknown to the other', runCatchThrown(function(){
	$archNat = new Native( Array('linkName'=>'archive') );
	$mainNat = new Native( Array() );
	$newTokn = $archNat->makeToknString('anna');
	return Array(is_string($newTokn), $archNat->checkSecretDct(Array('key'=>$newTokn))['userName'] ?? null, $mainNat->checkSecretDct(Array('key'=>$newTokn)));
}), Array(true, 'anna', false));

// The project's own access configuration: conf/access.json is kept out of
// the repository, so it is only checked where it exists
$axsLoad = new nyu\Setup\Loader( Array('settings'=>Array('confType'=>'all', 'confList'=>'access,database')) );
$axsConf = $axsLoad->readConfigRegr();
$natConf = is_file(TOP_DIR.DS.'conf'.DS.'access.json') ? $axsConf->getNestedValue('ACCESS', 'NATIVEDB') : null;
if( !is_array($natConf) ){
	recordSkipping('conf/access.json', 'the file, or its native block, is not there');
}else{
	checkIdentical('conf/access.json: the native block names its database first', array_slice(array_keys($natConf), 0, 6), Array('linkName', 'useTable', 'idleSecs', 'maxTries', 'holdSecs', 'minChars'));
	checkTrueValue('conf/access.json: that database is configured', runCatchThrown(function() use ($axsConf, $natConf){
		$GLOBALS['nyuConfig'] = $axsConf;
		return DBCon::baseSetupArray(strval($natConf['linkName'] ?? ''))['dbEngine']!='';
	}));
}

finishChecking();
