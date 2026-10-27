<?php

// Config cache (Setup\ConfCache): written by the first request of a preset
// when CNF_CACH is TRUE, read by the next ones in place of the preset, the
// config files and the route files; removed by what writes in conf and by
// "config cache"; seen as stale by "deploy check". Each request runs in a
// child process (test/lib/cacheChild.php) on a project folder of its own.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

use nyu\Setup\ConfCache;
use nyu\Setup\Loader;

$projDir = makeProjt('confcache', Array(
	'conf/system.ini'=>sampleIni("CNF_CACH = TRUE\nTBL_CACH = FALSE\n"),
	'conf/presets/probe.rps'=>'{ "label": "probe", "setup": { "ftypes": "all" }, "auths": { "needed": false }, "route": { "detect": "manually" } }',
	'conf/routes/probe.rte'=>'{ "one": { "target": { "app": "probe", "act": "clean" } } }',
	'conf/probe.json'=>'{ "value": "env:NYU_PROBE" }',
	'.env'=>"NYU_PROBE=from-the-env-file\n",
	// The Runner installs the error handler from the project it runs
	'src/Error/handling.php'=>strval(file_get_contents(TOP_DIR.DS.'src'.DS.'Error'.DS.'handling.php'))
));
$cchPath = $projDir.DS.'temp'.DS.'cache'.DS.'config-probe.cache';
$runArgs = Array(TST_DIR.DS.'lib'.DS.'cacheChild.php', $projDir, 'probe', '/one');

// The first request reads the files, and caches them
$outcome = runScript($runArgs);
checkSame('a first request reads the files and answers', trim($outcome['text']), '{"ok":true}');
clearstatcache();
checkSame('it writes the cache of its preset', is_file($cchPath), true);
$cchText = is_file($cchPath) ? strval(file_get_contents($cchPath)) : '';
checkSame('the cache holds the env: values as written, never what the environment file gives', Array(strpos($cchText, 'env:NYU_PROBE')!==false, strpos($cchText, 'from-the-env-file')!==false), Array(true, false));

// The next ones read the cache: a route file changed by hand is not seen, until the cache is removed
putInProj($projDir, 'conf/routes/probe.rte', '{ "one": { "target": { "app": "probe", "act": "leave" } } }');
touch($projDir.DS.'conf'.DS.'routes'.DS.'probe.rte', time()+60);
checkSame('the next request reads the cache, not the files', trim(runScript($runArgs)['text']), '{"ok":true}');
$rowList = array_column(jsonShell($projDir, Array('deploy', 'check'))['rows'] ?? Array(), 'state', 'check');
checkSame('deploy check sees a cache older than a file changed since', $rowList['config cache'] ?? null, 'warn');
$runVals = runShell($projDir, Array('config', 'cache'));
clearstatcache();
checkSame('config cache removes it', Array($runVals['code'], is_file($cchPath), strpos($runVals['text'], 'cache files removed')!==false), Array(0, false, true));
checkLike('it is traced', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Config: cache removed, 1 files/');
clearstatcache();
checkSame('a config file newer than the request: the files are read, and not cached', Array(trim(runScript($runArgs)['text']), is_file($cchPath)), Array('{"left":true}', false));
// The route file was dated a minute ahead, to be newer than the first cache: it is dated back
touch($projDir.DS.'conf'.DS.'routes'.DS.'probe.rte', time()-60);
clearstatcache();
checkSame('the next request reads the files again, and caches them', Array(trim(runScript($runArgs)['text']), is_file($cchPath)), Array('{"left":true}', true));
$rowList = array_column(jsonShell($projDir, Array('deploy', 'check'))['rows'] ?? Array(), 'state', 'check');
checkSame('deploy check finds it up to date', $rowList['config cache'] ?? null, 'fine');

// What writes in conf removes it
clearstatcache();
checkSame('config write removes it', Array(runShell($projDir, Array('config', 'write', 'system', 'LST_ROWS', '50'))['code'], is_file($cchPath)), Array(0, false));
runScript($runArgs);
checkSame('CNF_CACH is checked', runShell($projDir, Array('config', 'write', 'system', 'CNF_CACH', 'maybe'))['code']!=0, true);
clearstatcache();
checkSame('and the cache was left as it was', is_file($cchPath), true);
runShell($projDir, Array('config', 'write', 'system', 'CNF_CACH', 'FALSE'));
runScript($runArgs);
clearstatcache();
checkSame('with CNF_CACH FALSE, nothing is cached', is_file($cchPath), false);
checkSame('TBL_CACH is checked too', runShell($projDir, Array('config', 'write', 'system', 'TBL_CACH', 'maybe'))['code']!=0, true);
runShell($projDir, Array('config', 'write', 'system', 'TBL_CACH', 'TRUE'));
$rowList = array_column(jsonShell($projDir, Array('deploy', 'check'))['rows'] ?? Array(), 'state', 'check');
checkSame('deploy check warns about TBL_CACH without CNF_CACH', $rowList['table cache'] ?? null, 'warn');

// The values of the cache, as the Loader takes them
putenv('NYU_CACHE_TEST=from-the-process');
$cchLoad = new Loader( Array('setup'=>Array('ftypes'=>'all', 'envfle'=>'no-such-env')), Array('preset'=>Array(), 'config'=>Array('PROBE'=>Array('value'=>'env:NYU_CACHE_TEST')), 'routes'=>Array()), 'probe' );
checkSame('the env: values of the cache are filled on each request', ($cchLoad->getConfig()->readValue('probe') ?: Array())['value'] ?? null, 'from-the-process');
checkSame('a cache read is never written again', $cchLoad->keepCache(), false);
putenv('NYU_CACHE_TEST');

// What is not the name of a preset
checkSame('a name that is not one of a preset is neither read nor written', Array(ConfCache::readCache('../conf/x'), ConfCache::makeCache('../conf/x', Array('preset'=>Array(), 'config'=>Array(), 'routes'=>Array()))), Array(null, false));

endChecks();
