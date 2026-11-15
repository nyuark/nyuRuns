<?php

// Setup\ConfCache with TBL_CACH: the descriptions of the tables are taken
// from the config cache in place of asking the database, kept by database,
// noted when asked, and the cache is removed when a query fails on a table
// it described. On two sample SQLite files (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Table descriptions in the config cache', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Data\DBCon;
use nyu\Setup\ConfCache;
use nyu\Setup\Loader;
use nyu\Tools\Registry;

createSampleDb( inTemporaryDir('tblmain.sqlite') );
createSampleDb( inTemporaryDir('tblarch.sqlite') );
$GLOBALS['nyuConfig'] = new Registry( Array('database'=>Array(
	'NYURUNS'=>Array('dbEngine'=>'sqlitedb', 'baseName'=>inTemporaryDir('tblmain.sqlite')),
	'ARCHIVE'=>Array('dbEngine'=>'sqlitedb', 'baseName'=>inTemporaryDir('tblarch.sqlite'))
)) );
$realDsc = Array('flds'=>Array('id_show', 'title', 'venue'), 'pkey'=>'id_show', 'blob'=>Array());
$fakeDsc = Array('flds'=>Array('id_show', 'title', 'venue', 'gonecol'), 'pkey'=>'id_show', 'blob'=>Array());

// Not kept: the database is asked, and nothing is noted
ConfCache::$tableInfos = null;
checkIdentical('without TBL_CACH, the database describes the table', runCatchThrown(function(){ return DBCon::linkNamedDbCon()->getStoreObject()->tableInfoArray('shows'); }), $realDsc);
checkIdentical('...and nothing is noted', Array(ConfCache::$tableInfos, ConfCache::$isTblAdded), Array(null, false));

// Kept: a description asked is noted, under its database
ConfCache::$tableInfos = Array();
checkIdentical('a table described is noted, under the name of its database', runCatchThrown(function(){
	DBCon::linkNamedDbCon('archive')->getStoreObject()->tableInfoArray('events');
	return Array(array_keys(ConfCache::$tableInfos), array_keys(ConfCache::$tableInfos['archive'] ?? Array()), ConfCache::$isTblAdded);
}), Array(Array('archive'), Array('events'), true));

// A description the cache holds stands for the question, for its own database only
ConfCache::$tableInfos = Array('archive'=>Array('shows'=>$fakeDsc));
ConfCache::$isTblAdded = false;
checkIdentical('the description the cache holds is taken, the database is not asked', runCatchThrown(function(){ return DBCon::linkNamedDbCon('archive')->getStoreObject()->tableInfoArray('shows'); }), $fakeDsc);
checkIdentical('...and nothing new is noted', ConfCache::$isTblAdded, false);
checkIdentical('another database does not take it', runCatchThrown(function(){ return DBCon::linkNamedDbCon()->getStoreObject()->tableInfoArray('shows'); }), $realDsc);

// A query failing on a table the cache described removes the cache
ConfCache::$isTblAdded = false;
checkIdentical('a query that fails on a table described from the cache', runCatchThrown(function(){ return DBCon::linkNamedDbCon('archive')->getStoreObject()->findItemsArray('shows', Array('gonecol='=>'x')); }), false);
checkIdentical('...removes the descriptions, and nothing is written again', Array(ConfCache::$tableInfos, ConfCache::$isTblAdded), Array(Array(), false));
ConfCache::$tableInfos = Array('archive'=>Array('items'=>$realDsc));
checkIdentical('a query that fails on a table the database described', runCatchThrown(function(){ return DBCon::linkNamedDbCon()->getStoreObject()->findItemsArray('shows', Array('nocolumn='=>'x')); }), false);
checkIdentical('...leaves the cache as it is', array_keys(ConfCache::$tableInfos['archive'] ?? Array()), Array('items'));

// The Loader keeps them only with TBL_CACH and CNF_CACH
$stupArr = Array('settings'=>Array('confType'=>'all', 'envrFile'=>'no-such-env'));
$cchArr = function( String $tblFlag, String $cnfFlag ) use ( $realDsc ): Array {
	return Array('preset'=>Array(), 'config'=>Array('SYSTEM'=>Array('CNF_CACH'=>$cnfFlag, 'TBL_CACH'=>$tblFlag)), 'routes'=>Array(), 'tables'=>Array(''=>Array('shows'=>$realDsc)));
};
(new Loader($stupArr, $cchArr('TRUE', 'TRUE'), 'probe'))->retrieveConfig();
checkIdentical('the Loader hands the cached descriptions over with TBL_CACH and CNF_CACH', ConfCache::tableInfoArray('', 'shows'), $realDsc);
(new Loader($stupArr, $cchArr('FALSE', 'TRUE'), 'probe'))->retrieveConfig();
checkIdentical('not without TBL_CACH', Array(ConfCache::$tableInfos, ConfCache::tableInfoArray('', 'shows')), Array(null, null));
(new Loader($stupArr, $cchArr('TRUE', 'FALSE'), 'probe'))->retrieveConfig();
checkIdentical('nor without CNF_CACH', ConfCache::$tableInfos, null);

ConfCache::$tableInfos = null;
ConfCache::$isTblAdded = false;
finishChecking();
