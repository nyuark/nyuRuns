<?php

// Data\DBCon : several configured databases, each with its own connection,
// on two sample SQLite files (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Several databases', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Data\DBCon;
use nyu\Tools\Registry;

buildData( inTempDir('main.sqlite') );
buildData( inTempDir('arch.sqlite') );

// The archive only keeps one show, so that the two files can be told apart
$archLnk = new PDO('sqlite:'.inTempDir('arch.sqlite'));
$archLnk->exec('DELETE FROM shows WHERE id_show>1');
$archLnk->exec('DELETE FROM events WHERE id_event>1');
$archLnk = null;

$GLOBALS['nyuConfig'] = new Registry( Array('database'=>Array(
	'NYURUNS'=>Array('type'=>'sqlte', 'base'=>inTempDir('main.sqlite')),
	'ARCHIVE'=>Array('type'=>'sqlte', 'base'=>inTempDir('arch.sqlite'), 'wait'=>'2'),
	'BROKEN7'=>Array('type'=>'oracle', 'base'=>'nowhere')
)) );

/**
 * Counts the shows a connection sees.
 *
 * @param DBCon $handleLink The connection.
 * @return Int The number of shows.
 */
function showsOn( DBCon $handleLink ): Int {
	$handleLink->getConnex()->sendQuery('SELECT * FROM `shows`');
	return count($handleLink->getConnex()->readQuery(true));
}

checkSame('the default database is the first block', runSafely(function(){ return showsOn(DBCon::linkNamed()); }), 5);
checkSame('a database asked by its name', runSafely(function(){ return showsOn(DBCon::linkNamed('archive')); }), 1);
checkTrue('each database has its own connection', runSafely(function(){ return DBCon::linkNamed()!==DBCon::linkNamed('archive'); }));
checkTrue('a database is connected once', runSafely(function(){ return DBCon::linkNamed('archive')===DBCon::linkNamed('ARCHIVE'); }));
checkTrue('the default database, by its name, is the same connection', runSafely(function(){ return DBCon::linkNamed('nyuRuns')===DBCon::linkNamed(); }));
checkTrue('getInstnc returns the default connection already open', runSafely(function(){ return DBCon::getInstnc('sqlte', '', '', '', inTempDir('arch.sqlite'))===DBCon::linkNamed(); }));
checkSame('...whatever settings it is given', runSafely(function(){ return showsOn(DBCon::getInstnc('sqlte', '', '', '', inTempDir('arch.sqlite'))); }), 5);
checkLike('an unknown name is refused', runSafely(function(){ return DBCon::linkNamed('nowhere'); }), '/^THROWN RuntimeException: Unknown database: nowhere/');
checkLike('a database is only opened when asked for: a faulty one does not stop the others', runSafely(function(){ return DBCon::linkNamed('broken7'); }), '/^THROWN RuntimeException: Unknown database type: oracle/');

// A transaction belongs to its connection
checkSame('a transaction on one database leaves the other alone', runSafely(function(){
	$mainHdl = DBCon::linkNamed()->getConnex();
	$archHdl = DBCon::linkNamed('archive')->getConnex();
	$mainHdl->opnTrnsct();
	$mainHdl->sendQuery('DELETE FROM `shows` WHERE `id_show`=?', true, Array(5));
	$archHdl->sendQuery('UPDATE `shows` SET `venue`=? WHERE `id_show`=?', true, Array('Vault', 1));
	$mainHdl->remTrnsct();
	$archHdl->sendQuery('SELECT `venue` FROM `shows` WHERE `id_show`=?', false, Array(1));
	return Array(showsOn(DBCon::linkNamed()), $archHdl->readQuery(true)[0]['venue']);
}), Array(5, 'Vault'));

// Models naming their database
class MainShow extends nyu\Data\DBList {
	/**
	 * A list of shows that names no database.
	 */
	public function __construct(){
		parent::__construct('shows');
	}
}
class ArchShow extends nyu\Data\DBList {
	protected $baseName = 'archive';
	/**
	 * A list of shows in the archive, unless a connection is given.
	 *
	 * @param ?DBCon $handleLink Connection to use instead.
	 */
	public function __construct( ?DBCon $handleLink=null ){
		parent::__construct('shows', null, null, null, null, $handleLink);
	}
}
class ArchRecd extends nyu\Data\DBRecord {
	protected $baseName = 'archive';
	/**
	 * A show of the archive, with its events.
	 */
	public function __construct(){
		parent::__construct('shows', Array('events'));
	}
}
class LostShow extends nyu\Data\DBList {
	protected $baseName = 'nowhere';
	/**
	 * A list naming a database that is not configured.
	 */
	public function __construct(){
		parent::__construct('shows');
	}
}

checkSame('a model naming no database reads the default one', runSafely(function(){ $showLst = new MainShow(); return count($showLst->readItems()); }), 5);
checkSame('a model reads the database it names', runSafely(function(){ $showLst = new ArchShow(); return count($showLst->readItems()); }), 1);
checkSame('a connection given to the model wins over its name', runSafely(function(){ $showLst = new ArchShow(DBCon::linkNamed()); return count($showLst->readItems()); }), 5);
checkSame('a plain list on a given connection', runSafely(function(){
	$showLst = new nyu\Data\DBList('shows', null, null, null, null, DBCon::linkNamed('archive'));
	return count($showLst->readItems());
}), 1);
checkLike('a model naming an unknown database is refused', runSafely(function(){ return new LostShow(); }), '/^THROWN RuntimeException: Unknown database: nowhere/');
checkSame('the children of a record come from its own database', runSafely(function(){
	$archRec = new ArchRecd();
	$mainRec = new nyu\Data\DBRecord('shows', Array('events'));
	return Array(count($archRec->loadRecrd('1')['Events']), count($mainRec->loadRecrd('1')['Events']));
}), Array(1, 2));
checkSame('a record saved in one database leaves the other unchanged', runSafely(function(){
	$archRec = new ArchRecd();
	$archRec->loadRecrd('1');
	$archRec->bindArray(Array('title'=>'Alpha Night (archived)'));
	$archRec->saveRecrd();
	$mainRec = new nyu\Data\DBRecord('shows');
	$readRec = new ArchRecd();
	return Array($mainRec->loadRecrd('1')['title'], $readRec->loadRecrd('1')['title']);
}), Array('Alpha Night', 'Alpha Night (archived)'));
checkSame('a record deleted with its children, in its own database only', runSafely(function(){
	$archRec = new ArchRecd();
	$archRec->loadRecrd('1');
	$remDone = $archRec->remvRecrd(Array('events'));
	$archEvt = new nyu\Data\DBList('events', null, null, null, null, DBCon::linkNamed('archive'));
	$mainEvt = new nyu\Data\DBList('events');
	return Array($remDone, count($archEvt->readItems()), count($mainEvt->readItems()), showsOn(DBCon::linkNamed()));
}), Array(true, 0, 7, 5));

// The native userbase in a named database
use nyu\Access\Auth\Native;

// The archive has lost ben, so that the two users tables can be told apart
DBCon::linkNamed('archive')->getConnex()->sendQuery('DELETE FROM `users` WHERE `userName`=?', true, Array('ben'));
$benCred = Array('user'=>'ben', 'pass'=>'pw-ben');
$annCred = Array('user'=>'anna', 'pass'=>'pw-anna');

checkSame('Native without basnme reads the default database', runSafely(function() use ($benCred){
	$natAuth = new Native( Array('tblnme'=>'users') );
	return $natAuth->checkCred($benCred)['user'] ?? null;
}), 'ben');
checkSame('Native with an empty basnme reads the default database', runSafely(function() use ($benCred){
	$natAuth = new Native( Array('basnme'=>'', 'tblnme'=>'users') );
	return $natAuth->checkCred($benCred)['user'] ?? null;
}), 'ben');
checkSame('Native reads the database basnme names', runSafely(function() use ($benCred, $annCred){
	$natAuth = new Native( Array('basnme'=>'archive', 'tblnme'=>'users') );
	return Array($natAuth->checkCred($annCred)['user'] ?? null, $natAuth->checkCred($benCred), $natAuth->lastError());
}), Array('anna', false, 'Invalid credentials'));
checkSame('Native on an unknown database tells nothing more', runSafely(function() use ($annCred){
	$natAuth = new Native( Array('basnme'=>'nowhere', 'tblnme'=>'users') );
	return Array($natAuth->checkCred($annCred), $natAuth->lastError(), $natAuth->makeToken('anna'), $natAuth->onlineNow());
}), Array(false, 'Credential store unavailable', false, false));
checkSame('a token made in one database is unknown to the other', runSafely(function(){
	$archNat = new Native( Array('basnme'=>'archive') );
	$mainNat = new Native( Array() );
	$newTokn = $archNat->makeToken('anna');
	return Array(is_string($newTokn), $archNat->checkCred(Array('key'=>$newTokn))['user'] ?? null, $mainNat->checkCred(Array('key'=>$newTokn)));
}), Array(true, 'anna', false));

// The project's own access configuration: conf/access.json is kept out of
// the repository, so it is only checked where it exists
$axsLoad = new nyu\Setup\Loader( Array('setup'=>Array('ftypes'=>'all', 'fnames'=>'access,database')) );
$axsConf = $axsLoad->getConfig();
$natConf = is_file(TOP_DIR.DS.'conf'.DS.'access.json') ? $axsConf->getRowVal('ACCESS', 'NATIVE') : null;
if( !is_array($natConf) ){
	skipCheck('conf/access.json', 'the file, or its native block, is not there');
}else{
	checkSame('conf/access.json: the native block names its database first', array_keys($natConf), Array('basnme', 'tblnme', 'idletm', 'maxtry', 'holdtm'));
	checkTrue('conf/access.json: that database is configured', runSafely(function() use ($axsConf, $natConf){
		$GLOBALS['nyuConfig'] = $axsConf;
		return DBCon::baseSetup(strval($natConf['basnme'] ?? ''))['type']!='';
	}));
}

endChecks();
