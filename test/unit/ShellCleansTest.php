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
		$this->countsFold = $folderPath;
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

$projDir = makeProjectTxt('cleans', Array(
	'conf/system.ini'=>buildSystemIni(),
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
$limiter->countFailedTry('203.0.113.7');
$limiter->countFailedTry('203.0.113.7');
$limiter->countFailedTry('198.51.100.1');
$limiter->countFailedTry('2001:db8:1:2:aaaa:bbbb:cccc:dddd');

// The limiter itself
$heldLst = $limiter->listLimitHolds();
usort($heldLst, function( Array $firstHold, Array $otherHold ): Int { return strcmp($firstHold['addrs'], $otherHold['addrs']); });
checkIdentical('the counters tell their address, IPv6 by its /64 network', array_column($heldLst, 'addrs'), Array('198.51.100.1', '2001:db8:1:2::', '203.0.113.7'));
checkIdentical('an address is held from as many failures as allowed', Array(array_column($heldLst, 'fails'), array_map(function( Array $oneHold ): Bool { return $oneHold['until']>time(); }, $heldLst)), Array(Array(1, 1, 2), Array(false, false, true)));
checkIdentical('a counter no longer in use is not listed', count($limiter->listLimitHolds(time()+601)), 0);
checkIdentical('a counter written without its address is still read', (function() use ($limiter, $projDir): Array {
	file_put_contents($projDir.DS.'temp'.DS.'limits'.DS.str_repeat('b', 64).'.cnt', '5 '.time());
	$oldVals = array_values(array_filter($limiter->listLimitHolds(), function( Array $oneHold ): Bool { return $oneHold['addrs']==''; }));
	unlink($projDir.DS.'temp'.DS.'limits'.DS.str_repeat('b', 64).'.cnt');
	return Array(count($oldVals), $oldVals[0]['fails'] ?? null);
})(), Array(1, 5));

// holds, frees
$jsnVals = runShellAsJson($projDir, Array('cleans', 'holds'));
$rowList = $jsnVals['rows'] ?? Array();
usort($rowList, function( Array $firstHold, Array $otherHold ): Int { return strcmp($firstHold['address'], $otherHold['address']); });
checkIdentical('holds lists the addresses and which are held', array_map(function( Array $oneHold ): Array { return Array($oneHold['address'], $oneHold['failures'], $oneHold['held until']!=''); }, $rowList), Array(Array('198.51.100.1', 1, false), Array('2001:db8:1:2::', 1, false), Array('203.0.113.7', 2, true)));
checkIdentical('frees needs a confirmation', Array(callShellArray($projDir, Array('cleans', 'frees', '203.0.113.7', '--noask'))['code'], $limiter->heldUntilCount('203.0.113.7')>0), Array(3, true));
checkIdentical('what is not an address is a wrong usage', callShellArray($projDir, Array('cleans', 'frees', 'someone', '--agree'))['code'], 2);
checkIdentical('frees releases an address', Array(callShellArray($projDir, Array('cleans', 'frees', '203.0.113.7'), "y\n")['text'], $limiter->heldUntilCount('203.0.113.7'), count($limiter->listLimitHolds())), Array("released: 1\n", 0, 2));
checkIdentical('an IPv6 address releases its network', Array(callShellArray($projDir, Array('cleans', 'frees', '2001:db8:1:2::99', '--agree'))['text'], count($limiter->listLimitHolds())), Array("released: 1\n", 1));
checkIdentical('frees all releases every address', Array(runShellAsJson($projDir, Array('cleans', 'frees', 'all', '--agree'))['released'] ?? null, count($limiter->listLimitHolds())), Array(1, 0));
checkPregMatch('a release is traced, signed with the user of the system', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Limiter: address 203\.0\.113\.7 released by user "cli:[\w.\-]+"\n/');

// trace
checkIdentical('trace shows the last lines of a channel', callShellArray($projDir, Array('cleans', 'trace', 'access', '--lasts=2'))['text'], "[2026-10-01 10:01] [INFO] two\n[2026-10-01 10:02] [WARNING] three\n");
checkIdentical('trace shows 20 lines when not told', substr_count(callShellArray($projDir, Array('cleans', 'trace', 'access'))['text'], "\n"), 3);
checkIdentical('a channel without a log', callShellArray($projDir, Array('cleans', 'trace', 'nothing'))['code'], 3);
checkIdentical('a channel is a plain name', callShellArray($projDir, Array('cleans', 'trace', '../conf/system'))['code'], 2);

// purge
checkIdentical('purge needs to be told what', callShellArray($projDir, Array('cleans', 'purge', '--agree'))['code'], 2);
checkIdentical('purge knows the folders of temp only', callShellArray($projDir, Array('cleans', 'purge', 'cache', 'conf', '--agree'))['code'], 2);
checkIdentical('purge needs a confirmation', Array(callShellArray($projDir, Array('cleans', 'purge', 'cache', '--noask'))['code'], callShellArray($projDir, Array('cleans', 'purge', 'cache'), "n\n")['code'], count(filesIn($projDir.DS.'temp'.DS.'cache'))), Array(3, 3, 3));
checkIdentical('purge by age removes the old files only', Array(runShellAsJson($projDir, Array('cleans', 'purge', 'logs', '--older=30', '--agree'))['rows'] ?? null, filesIn($projDir.DS.'temp'.DS.'logs')), Array(Array(Array('folder'=>'logs', 'removed'=>1, 'state'=>'purged')), Array('access.log', 'runner.log', 'shell.log')));
checkIdentical('purge removes the files, and keeps the folders', Array(callShellArray($projDir, Array('cleans', 'purge', 'cache', 'backup'), "yes\n")['code'], filesIn($projDir.DS.'temp'.DS.'cache'), is_dir($projDir.DS.'temp'.DS.'cache'.DS.'sub'), filesIn($projDir.DS.'temp'.DS.'backup')), Array(0, Array('.gitkeep'), true, Array()));
$limiter->countFailedTry('203.0.113.9');
checkIdentical('purge all', Array(callShellArray($projDir, Array('cleans', 'purge', 'all', '--agree', '--quiet'))['code'], filesIn($projDir.DS.'temp'.DS.'limits'), fetchInProject($projDir, 'outside/keep.txt')), Array(0, Array(), 'kept'));
checkPregMatch('a purge is traced', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/Purge of limits: 1 files removed by user "cli:/');

// A folder set outside temp is left alone
writeInProject($projDir, 'conf/system.ini', str_replace('LOG_PATH = /temp/logs', 'LOG_PATH = /outside', buildSystemIni()));
checkIdentical('logs kept outside temp are not purged', Array(runShellAsJson($projDir, Array('cleans', 'purge', 'logs', '--agree'))['rows'][0]['removed'] ?? null, is_file($projDir.DS.'outside'.DS.'keep.txt')), Array(0, true));

finishChecking();
