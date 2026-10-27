<?php

// Setup\ConfCache with TBL_CACH: the descriptions of the tables are taken
// from the config cache in place of asking the database, kept by database,
// noted when asked, and the cache is removed when a query fails on a table
// it described. On two sample SQLite files (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Table descriptions in the config cache', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Data\DBCon;
use nyu\Setup\ConfCache;
use nyu\Setup\Loader;
use nyu\Tools\Registry;

buildData( inTempDir('tblmain.sqlite') );
buildData( inTempDir('tblarch.sqlite') );
$GLOBALS['nyuConfig'] = new Registry( Array('database'=>Array(
	'NYURUNS'=>Array('type'=>'sqlte', 'base'=>inTempDir('tblmain.sqlite')),
	'ARCHIVE'=>Array('type'=>'sqlte', 'base'=>inTempDir('tblarch.sqlite'))
)) );
$realDsc = Array('flds'=>Array('id_show', 'title', 'venue'), 'pkey'=>'id_show', 'blob'=>Array());
$fakeDsc = Array('flds'=>Array('id_show', 'title', 'venue', 'gonecol'), 'pkey'=>'id_show', 'blob'=>Array());

// Not kept: the database is asked, and nothing is noted
ConfCache::$tblInfos = null;
checkSame('without TBL_CACH, the database describes the table', runSafely(function(){ return DBCon::linkNamed()->dataStore()->tableInfo('shows'); }), $realDsc);
checkSame('...and nothing is noted', Array(ConfCache::$tblInfos, ConfCache::$tblAdded), Array(null, false));

// Kept: a description asked is noted, under its database
ConfCache::$tblInfos = Array();
checkSame('a table described is noted, under the name of its database', runSafely(function(){
	DBCon::linkNamed('archive')->dataStore()->tableInfo('events');
	return Array(array_keys(ConfCache::$tblInfos), array_keys(ConfCache::$tblInfos['archive'] ?? Array()), ConfCache::$tblAdded);
}), Array(Array('archive'), Array('events'), true));

// A description the cache holds stands for the question, for its own database only
ConfCache::$tblInfos = Array('archive'=>Array('shows'=>$fakeDsc));
ConfCache::$tblAdded = false;
checkSame('the description the cache holds is taken, the database is not asked', runSafely(function(){ return DBCon::linkNamed('archive')->dataStore()->tableInfo('shows'); }), $fakeDsc);
checkSame('...and nothing new is noted', ConfCache::$tblAdded, false);
checkSame('another database does not take it', runSafely(function(){ return DBCon::linkNamed()->dataStore()->tableInfo('shows'); }), $realDsc);

// A query failing on a table the cache described removes the cache
ConfCache::$tblAdded = false;
checkSame('a query that fails on a table described from the cache', runSafely(function(){ return DBCon::linkNamed('archive')->dataStore()->findItems('shows', Array('gonecol='=>'x')); }), false);
checkSame('...removes the descriptions, and nothing is written again', Array(ConfCache::$tblInfos, ConfCache::$tblAdded), Array(Array(), false));
ConfCache::$tblInfos = Array('archive'=>Array('items'=>$realDsc));
checkSame('a query that fails on a table the database described', runSafely(function(){ return DBCon::linkNamed()->dataStore()->findItems('shows', Array('nocolumn='=>'x')); }), false);
checkSame('...leaves the cache as it is', array_keys(ConfCache::$tblInfos['archive'] ?? Array()), Array('items'));

// The Loader keeps them only with TBL_CACH and CNF_CACH
$stupArr = Array('setup'=>Array('ftypes'=>'all', 'envfle'=>'no-such-env'));
$cchArr = function( String $tblFlag, String $cnfFlag ) use ( $realDsc ): Array {
	return Array('preset'=>Array(), 'config'=>Array('SYSTEM'=>Array('CNF_CACH'=>$cnfFlag, 'TBL_CACH'=>$tblFlag)), 'routes'=>Array(), 'tables'=>Array(''=>Array('shows'=>$realDsc)));
};
(new Loader($stupArr, $cchArr('TRUE', 'TRUE'), 'probe'))->getConfig();
checkSame('the Loader hands the cached descriptions over with TBL_CACH and CNF_CACH', ConfCache::tableInfo('', 'shows'), $realDsc);
(new Loader($stupArr, $cchArr('FALSE', 'TRUE'), 'probe'))->getConfig();
checkSame('not without TBL_CACH', Array(ConfCache::$tblInfos, ConfCache::tableInfo('', 'shows')), Array(null, null));
(new Loader($stupArr, $cchArr('TRUE', 'FALSE'), 'probe'))->getConfig();
checkSame('nor without CNF_CACH', ConfCache::$tblInfos, null);

ConfCache::$tblInfos = null;
ConfCache::$tblAdded = false;
endChecks();
