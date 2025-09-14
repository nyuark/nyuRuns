<?php

// Command line, cleans : the temporary files removed by kind and by age, a
// log channel shown, the addresses of the limiter listed and released.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

use nyu\Access\Limiter;

/**
 * A limiter keeping its counters in a folder of the tests, wherever it is.
 */
class FoldrLimiter extends Limiter{

	/**
	 * @param Array  $confValues Config block of the limiter.
	 * @param String $folderPath Full path of the folder keeping the counters.
	 */
	function __construct( Array $confValues, String $folderPath ){
		parent::__construct($confValues);
		$this->cntFoldr = $folderPath;
	}
}

/**
 * Lists the files of a folder of a project, with those of its sub-folders.
 *
 * @param String $folderPath Full path of the folder.
 * @param String $prefixText Path written before each name.
 * @return Array The paths, from the folder, written with slashes and sorted.
 */
function filesIn( String $folderPath, String $prefixText='' ): Array {
	$fleList = Array();
	foreach( scandir($folderPath) as $entNme ){
		if( $entNme=='.' || $entNme=='..' ){
			continue;
		}
		if( is_dir($folderPath.DS.$entNme) ){
			$fleList = array_merge($fleList, filesIn($folderPath.DS.$entNme, $prefixText.$entNme.'/'));
		}else{
			$fleList[] = $prefixText.$entNme;
		}
	}
	sort($fleList);
	return $fleList;
}

$projDir = makeProjt('cleans', Array(
	'conf/system.ini'=>sampleIni(),
	'conf/access.json'=>'{ "limits": { "maxtry": 2, "holdtm": 600, "folder": "temp/limits" } }',
	'temp/cache/a.tmp'=>'a', 'temp/cache/sub/b.tmp'=>'b', 'temp/cache/.gitkeep'=>'',
	'temp/logs/access.log'=>"[2026-10-01 10:00] [INFO] one\n[2026-10-01 10:01] [INFO] two\n[2026-10-01 10:02] [WARNING] three\n",
	'temp/logs/access.log.1'=>"old\n", 'temp/logs/runner.log'=>"run\n",
	'temp/backup/system.ini.20261001-100000'=>'x',
	'outside/keep.txt'=>'kept'
));
touch($projDir.DS.'temp'.DS.'logs'.DS.'access.log.1', time()-40*86400);
mkdir($projDir.DS.'temp'.DS.'limits');
$limiter = new FoldrLimiter( Array('maxtry'=>2, 'holdtm'=>600), $projDir.DS.'temp'.DS.'limits' );
$limiter->countFail('203.0.113.7');
$limiter->countFail('203.0.113.7');
$limiter->countFail('198.51.100.1');
$limiter->countFail('2001:db8:1:2:aaaa:bbbb:cccc:dddd');

// The limiter itself
$heldLst = $limiter->listHolds();
usort($heldLst, function( Array $firstHold, Array $otherHold ): Int { return strcmp($firstHold['addrs'], $otherHold['addrs']); });
checkSame('the counters tell their address, IPv6 by its /64 network', array_column($heldLst, 'addrs'), Array('198.51.100.1', '2001:db8:1:2::', '203.0.113.7'));
checkSame('an address is held from as many failures as allowed', Array(array_column($heldLst, 'fails'), array_map(function( Array $oneHold ): Bool { return $oneHold['until']>time(); }, $heldLst)), Array(Array(1, 1, 2), Array(false, false, true)));
checkSame('a counter no longer in use is not listed', count($limiter->listHolds(time()+601)), 0);
checkSame('a counter written without its address is still read', (function() use ($limiter, $projDir): Array {
	file_put_contents($projDir.DS.'temp'.DS.'limits'.DS.str_repeat('b', 64).'.cnt', '5 '.time());
	$oldVals = array_values(array_filter($limiter->listHolds(), function( Array $oneHold ): Bool { return $oneHold['addrs']==''; }));
	unlink($projDir.DS.'temp'.DS.'limits'.DS.str_repeat('b', 64).'.cnt');
	return Array(count($oldVals), $oldVals[0]['fails'] ?? null);
})(), Array(1, 5));

// holds, frees
$jsnVals = jsonShell($projDir, Array('cleans', 'holds'));
$rowList = $jsnVals['rows'] ?? Array();
usort($rowList, function( Array $firstHold, Array $otherHold ): Int { return strcmp($firstHold['address'], $otherHold['address']); });
checkSame('holds lists the addresses and which are held', array_map(function( Array $oneHold ): Array { return Array($oneHold['address'], $oneHold['failures'], $oneHold['held until']!=''); }, $rowList), Array(Array('198.51.100.1', 1, false), Array('2001:db8:1:2::', 1, false), Array('203.0.113.7', 2, true)));
checkSame('frees needs a confirmation', Array(runShell($projDir, Array('cleans', 'frees', '203.0.113.7', '--noask'))['code'], $limiter->heldUntil('203.0.113.7')>0), Array(3, true));
checkSame('what is not an address is a wrong usage', runShell($projDir, Array('cleans', 'frees', 'someone', '--agree'))['code'], 2);
checkSame('frees releases an address', Array(runShell($projDir, Array('cleans', 'frees', '203.0.113.7'), "y\n")['text'], $limiter->heldUntil('203.0.113.7'), count($limiter->listHolds())), Array("released: 1\n", 0, 2));
checkSame('an IPv6 address releases its network', Array(runShell($projDir, Array('cleans', 'frees', '2001:db8:1:2::99', '--agree'))['text'], count($limiter->listHolds())), Array("released: 1\n", 1));
checkSame('frees all releases every address', Array(jsonShell($projDir, Array('cleans', 'frees', 'all', '--agree'))['released'] ?? null, count($limiter->listHolds())), Array(1, 0));
checkLike('a release is traced, signed with the user of the system', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Limiter: address 203\.0\.113\.7 released by user "cli:[\w.\-]+"\n/');

// trace
checkSame('trace shows the last lines of a channel', runShell($projDir, Array('cleans', 'trace', 'access', '--lasts=2'))['text'], "[2026-10-01 10:01] [INFO] two\n[2026-10-01 10:02] [WARNING] three\n");
checkSame('trace shows 20 lines when not told', substr_count(runShell($projDir, Array('cleans', 'trace', 'access'))['text'], "\n"), 3);
checkSame('a channel without a log', runShell($projDir, Array('cleans', 'trace', 'nothing'))['code'], 3);
checkSame('a channel is a plain name', runShell($projDir, Array('cleans', 'trace', '../conf/system'))['code'], 2);

// purge
checkSame('purge needs to be told what', runShell($projDir, Array('cleans', 'purge', '--agree'))['code'], 2);
checkSame('purge knows the folders of temp only', runShell($projDir, Array('cleans', 'purge', 'cache', 'conf', '--agree'))['code'], 2);
checkSame('purge needs a confirmation', Array(runShell($projDir, Array('cleans', 'purge', 'cache', '--noask'))['code'], runShell($projDir, Array('cleans', 'purge', 'cache'), "n\n")['code'], count(filesIn($projDir.DS.'temp'.DS.'cache'))), Array(3, 3, 3));
checkSame('purge by age removes the old files only', Array(jsonShell($projDir, Array('cleans', 'purge', 'logs', '--older=30', '--agree'))['rows'] ?? null, filesIn($projDir.DS.'temp'.DS.'logs')), Array(Array(Array('folder'=>'logs', 'removed'=>1, 'state'=>'purged')), Array('access.log', 'runner.log', 'shell.log')));
checkSame('purge removes the files, and keeps the folders', Array(runShell($projDir, Array('cleans', 'purge', 'cache', 'backup'), "yes\n")['code'], filesIn($projDir.DS.'temp'.DS.'cache'), is_dir($projDir.DS.'temp'.DS.'cache'.DS.'sub'), filesIn($projDir.DS.'temp'.DS.'backup')), Array(0, Array('.gitkeep'), true, Array()));
$limiter->countFail('203.0.113.9');
checkSame('purge all', Array(runShell($projDir, Array('cleans', 'purge', 'all', '--agree', '--quiet'))['code'], filesIn($projDir.DS.'temp'.DS.'limits'), readInPrj($projDir, 'outside/keep.txt')), Array(0, Array(), 'kept'));
checkLike('a purge is traced', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/Purge of limits: 1 files removed by user "cli:/');

// A folder set outside temp is left alone
putInProj($projDir, 'conf/system.ini', str_replace('LOG_PATH = /temp/logs', 'LOG_PATH = /outside', sampleIni()));
checkSame('logs kept outside temp are not purged', Array(jsonShell($projDir, Array('cleans', 'purge', 'logs', '--agree'))['rows'][0]['removed'] ?? null, is_file($projDir.DS.'outside'.DS.'keep.txt')), Array(0, true));

endChecks();
