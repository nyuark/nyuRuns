<?php

// Config cache (Setup\ConfCache): written by the first request of a preset
// when CNF_CACH is TRUE, read by the next ones in place of the preset, the
// config files and the route files; removed by what writes in conf and by
// "setting clrCache"; seen as stale by "install checkAll". Each request runs in a
// child process (test/lib/cacheChild.php) on a project folder of its own.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

use nyu\Setup\ConfCache;
use nyu\Setup\Loader;

$projDir = makeProjectTxt('confcache', Array(
	'conf/system.ini'=>buildSystemIni("CNF_CACH = TRUE\nTBL_CACH = FALSE\n"),
	'conf/presets/probe.rps'=>'{ "presetId": "probe", "settings": { "confType": "all" }, "security": { "required": false, "isPublic": true }, "dispatch": { "detectBy": "manually" } }',
	'conf/routes/probe.rte'=>'{ "one": { "pointsTo": { "appToRun": "probe", "doAction": "sanitize" } } }',
	'conf/probe.json'=>'{ "value": "env:NYU_PROBE" }',
	'.env'=>"NYU_PROBE=from-the-env-file\n",
	// The Runner installs the error handler from the project it runs
	'src/Error/handling.php'=>strval(file_get_contents(TOP_DIR.DS.'src'.DS.'Error'.DS.'handling.php'))
));
$cchPath = $projDir.DS.'temp'.DS.'cache'.DS.'config-probe.cache';
$runArgs = Array(TST_DIR.DS.'lib'.DS.'cacheChild.php', $projDir, 'probe', '/one');

// The first request reads the files, and caches them
$outcome = runScriptArray($runArgs);
checkIdentical('a first request reads the files and answers', trim($outcome['text']), '{"ok":true}');
clearstatcache();
checkIdentical('it writes the cache of its preset', is_file($cchPath), true);
$cchText = is_file($cchPath) ? strval(file_get_contents($cchPath)) : '';
checkIdentical('the cache holds the env: values as written, never what the environment file gives', Array(strpos($cchText, 'env:NYU_PROBE')!==false, strpos($cchText, 'from-the-env-file')!==false), Array(true, false));

// The next ones read the cache: a route file changed by hand is not seen, until the cache is removed
writeInProject($projDir, 'conf/routes/probe.rte', '{ "one": { "pointsTo": { "appToRun": "probe", "doAction": "signsOut" } } }');
touch($projDir.DS.'conf'.DS.'routes'.DS.'probe.rte', time()+60);
checkIdentical('the next request reads the cache, not the files', trim(runScriptArray($runArgs)['text']), '{"ok":true}');
$rowList = array_column(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), 'state', 'check');
checkIdentical('deploy check sees a cache older than a file changed since', $rowList['config cache'] ?? null, 'warn');
$runVals = callShellArray($projDir, Array('setting', 'clrCache'));
clearstatcache();
checkIdentical('config cache removes it', Array($runVals['code'], is_file($cchPath), strpos($runVals['text'], 'cache files removed')!==false), Array(0, false, true));
checkPregMatch('it is traced', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Config: cache removed, 1 files/');
clearstatcache();
checkIdentical('a config file newer than the request: the files are read, and not cached', Array(trim(runScriptArray($runArgs)['text']), is_file($cchPath)), Array('{"left":true}', false));
// The route file was dated a minute ahead, to be newer than the first cache: it is dated back
touch($projDir.DS.'conf'.DS.'routes'.DS.'probe.rte', time()-60);
clearstatcache();
checkIdentical('the next request reads the files again, and caches them', Array(trim(runScriptArray($runArgs)['text']), is_file($cchPath)), Array('{"left":true}', true));
$rowList = array_column(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), 'state', 'check');
checkIdentical('deploy check finds it up to date', $rowList['config cache'] ?? null, 'fine');

// What writes in conf removes it
clearstatcache();
checkIdentical('config write removes it', Array(callShellArray($projDir, Array('setting', 'setValue', 'coreConf', 'DEF_ROWS', '50'))['code'], is_file($cchPath)), Array(0, false));
runScriptArray($runArgs);
checkIdentical('CNF_CACH is checked', callShellArray($projDir, Array('setting', 'setValue', 'coreConf', 'CNF_CACH', 'maybe'))['code']!=0, true);
clearstatcache();
checkIdentical('and the cache was left as it was', is_file($cchPath), true);
callShellArray($projDir, Array('setting', 'setValue', 'coreConf', 'CNF_CACH', 'FALSE'));
runScriptArray($runArgs);
clearstatcache();
checkIdentical('with CNF_CACH FALSE, nothing is cached', is_file($cchPath), false);
checkIdentical('TBL_CACH is checked too', callShellArray($projDir, Array('setting', 'setValue', 'coreConf', 'TBL_CACH', 'maybe'))['code']!=0, true);
callShellArray($projDir, Array('setting', 'setValue', 'coreConf', 'TBL_CACH', 'TRUE'));
$rowList = array_column(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), 'state', 'check');
checkIdentical('deploy check warns about TBL_CACH without CNF_CACH', $rowList['table cache'] ?? null, 'warn');

// The values of the cache, as the Loader takes them
putenv('NYU_CACHE_TEST=from-the-process');
$cchLoad = new Loader( Array('settings'=>Array('confType'=>'all', 'envrFile'=>'no-such-env')), Array('preset'=>Array(), 'config'=>Array('PROBE'=>Array('value'=>'env:NYU_CACHE_TEST')), 'routes'=>Array()), 'probe' );
checkIdentical('the env: values of the cache are filled on each request', ($cchLoad->readConfigRegr()->readValueByKey('probe') ?: Array())['value'] ?? null, 'from-the-process');
checkIdentical('a cache read is never written again', $cchLoad->saveConfgCache(), false);
putenv('NYU_CACHE_TEST');

// A config file that cannot be read: the request stops, the file is named in the log and by the health check
$badPrjt = makeProjectTxt('confbroken', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/access.json'=>'{ "tryLimit": { "maxTries": 0 } }',
	'conf/presets/probe.rps'=>'{ "presetId": "probe", "settings": { "confType": "all" }, "security": { "required": false, "isPublic": true }, "dispatch": { "detectBy": "manually" } }',
	'conf/routes/probe.rte'=>'{ "one": { "pointsTo": { "appToRun": "probe", "doAction": "sanitize" } } }',
	// A preset that leaves out the keys with a default: the kind of config files read, the fields a source keeps
	'conf/presets/bare.rps'=>'{ "presetId": "bare", "settings": { "confList": "any" }, "security": { "required": false, "isPublic": true }, "inputSet": [ { "sourceId": "QUERY", "readFrom": "QUERY" } ], "dispatch": { "detectBy": "manually" } }',
	// One whose source names no registry: refused when it loads, which the health check tells
	'conf/presets/unfed.rps'=>'{ "presetId": "unfed", "settings": { "confType": "all" }, "inputSet": [ { "readFrom": "QUERY" } ] }',
	'src/Error/handling.php'=>strval(file_get_contents(TOP_DIR.DS.'src'.DS.'Error'.DS.'handling.php'))
));
checkIdentical('a preset without confType or fieldSet: every config file is read, every field kept, nothing else is printed', trim(runScriptArray(Array(TST_DIR.DS.'lib'.DS.'cacheChild.php', $badPrjt, 'bare', '/one'))['text']), '{"ok":true}');
$rowList = array_column(runShellAsJson($badPrjt, Array('install', 'checkAll'))['rows'] ?? Array(), null, 'check');
checkIdentical('the health check: that preset loads, one whose source names no registry does not', Array($rowList['preset bare']['state'] ?? null, $rowList['preset unfed']['state'] ?? null, $rowList['preset unfed']['found'] ?? null), Array('fine', 'fail', 'Preset "unfed" holds an input source without its sourceId'));
$badArgs =Array(TST_DIR.DS.'lib'.DS.'cacheChild.php', $badPrjt, 'probe', '/one');
checkIdentical('every config file read: the request answers, the health check says so', Array(trim(runScriptArray($badArgs)['text']), array_column(runShellAsJson($badPrjt, Array('install', 'checkAll'))['rows'] ?? Array(), 'state', 'check')['config files'] ?? null), Array('{"ok":true}', 'fine'));
foreach( Array('access.json'=>'{ not json', 'database.json'=>'{ not json', 'mailer.json'=>'{ not json', 'system.ini'=>buildSystemIni("DEF_LANG = FRENCH!\n")) as $oneNme => $oneTxt ){
	$keptTxt = fetchInProject($badPrjt, 'conf/'.$oneNme);
	writeInProject($badPrjt, 'conf/'.$oneNme, $oneTxt);
	$outText = runScriptArray($badArgs)['text'];
	checkIdentical('conf/'.$oneNme.' that cannot be read: a 500 that says so, the file named in the log only', Array(strpos($outText, '{"error":"Configuration unreadable","code":500}')!==false, strpos($outText, 'start-up failure')!==false && strpos($outText, 'conf/'.$oneNme.' cannot be read: ')!==false, substr_count($outText, $oneNme)), Array(true, true, 1));
	$rowList = array_column(runShellAsJson($badPrjt, Array('install', 'checkAll'))['rows'] ?? Array(), 'found', 'check');
	checkIdentical('conf/'.$oneNme.': the health check fails a row naming it, and no longer blames the access config', Array(strpos(strval($rowList['config files'] ?? ''), 'conf/'.$oneNme.' cannot be read: ')===0, $rowList['access'] ?? null), Array(true, 'not checked: the config cannot be read (see config files)'));
	checkIdentical('conf/'.$oneNme.': a command is told the file', strpos(callShellArray($badPrjt, Array('cronjob', 'probe/sanitize', '--presetId=probe'))['errs'], 'conf/'.$oneNme.' cannot be read: ')!==false, true);
	if( $keptTxt===null ){
		unlink($badPrjt.DS.'conf'.DS.$oneNme);
	}else{
		writeInProject($badPrjt, 'conf/'.$oneNme, $keptTxt);
	}
}

// What is not the name of a preset
checkIdentical('a name that is not one of a preset is neither read nor written', Array(ConfCache::readCacheArray('../conf/x'), ConfCache::saveWholeCache('../conf/x', Array('preset'=>Array(), 'config'=>Array(), 'routes'=>Array()))), Array(null, false));

finishChecking();
