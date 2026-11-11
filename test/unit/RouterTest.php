<?php

// Tools\Router : the declared routes that answer without an action
// (redirection, static file or folder, HTML page), the safety of the paths
// asked for in a static folder, the links to the declared routes, and the
// Actionset helpers built on them. Routes come from test/fixtures/routes,
// their files from test/fixtures/pages.

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');
require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';
require RUN_DIR.DS.'Probe'.DS.'Actions.php';
require RUN_DIR.DS.'Mounted'.DS.'Actions.php';

use nyu\Tools\Router;
use nyu\Tools\Registry;
use nyu\Output\Response;

/**
 * Builds a router for a request on the fixture routes.
 *
 * @param String $urlAddress Path of the request, with its leading slash.
 * @param String $httpMethod Method of the request.
 * @param Array  $routeConfg Values replacing those of the route configuration.
 * @return Router The router.
 */
function madeRoutr( String $urlAddress, String $httpMethod='GET', Array $routeConfg=Array() ): Router {
	$request = mockRequestObj();
	foreach( Array('rqstInMthd'=>$httpMethod, 'uriDetails'=>Array('fullPath'=>$urlAddress)) as $prpNme=>$prpVal ){
		$propObj = new ReflectionProperty($request, $prpNme);
		$propObj->setValue($request, $prpVal);
	}
	return new Router( $routeConfg + Array('detectBy'=>'autopath', 'pathForm'=>'$appToRun/$doAction/$argument', 'routeDir'=>'test'.DS.'fixtures'.DS.'routes', 'fileRoot'=>'test'.DS.'fixtures'.DS.'pages'), $request );
}

/**
 * Resolves a request on the fixture routes and returns some of the route values.
 *
 * @param String $urlAddress Path of the request, with its leading slash.
 * @param Array  $wantedKeys Route values to return.
 * @param String $httpMethod Method of the request.
 * @param Array  $routeConfg Values replacing those of the route configuration.
 * @return Array|String The values in the order asked, or what was thrown.
 */
function foundVals( String $urlAddress, Array $wantedKeys, String $httpMethod='GET', Array $routeConfg=Array() ): Array|String {
	return runCatchThrown(function() use ( $urlAddress, $wantedKeys, $httpMethod, $routeConfg ){
		$rteReg = madeRoutr($urlAddress, $httpMethod, $routeConfg)->locateRouteReg();
		return array_map(function( String $keyNme ) use ( $rteReg ){ return $rteReg->readValueByKey($keyNme); }, $wantedKeys);
	});
}

/**
 * Builds a link from an action of the Mounted application (see routeLink), its $id given as 7.
 *
 * @param String $routeShape The path of the route: as Mounted declares it, or its final path.
 * @param Array  $routeConfg Values replacing those of the route configuration.
 * @return String The link, or what was thrown.
 */
function linkFrom( String $routeShape, Array $routeConfg=Array() ): String {
	return strval(runCatchThrown(function() use ( $routeShape, $routeConfg ){
		$actnObj = new nyu\Runs\Mounted\Actions( new nyu\App\RunContext('makeLink', $routeShape, new Registry(Array()), null, mockRequestObj(), new Response(), madeRoutr('/', 'GET', $routeConfg), null, 'Mounted', RUN_DIR.DS.'Mounted') );
		$actnObj->runAskedAction();
		return $actnObj->replyValue;
	}));
}

$pageDir = realpath(TST_DIR.DS.'fixtures'.DS.'pages');
$moveKey = Array('routKind', 'routTarg', 'routStat');
$fileKey = Array('routKind', 'routTarg', 'routType');
$statKey = Array('statCode', 'statMssg');
$notFile = Array(404, 'Not Found');
$txtType = 'text/plain; charset=utf-8';

// Action routes still resolve, and tell their kind and their declared path
checkIdentical('action: kind and declared path', foundVals('/items/42', Array('appToRun', 'doAction', 'argument', 'routKind', 'routPath')), Array('probe', 'mirrored', '42', 'toAction', 'items/$id'));
checkIdentical('action: exact key', foundVals('/ping', Array('appToRun', 'routKind', 'routPath')), Array('probe', 'toAction', 'ping'));
checkIdentical('action: a refused method keeps the declared path', foundVals('/ping', Array('statCode', 'allowHdr', 'routPath'), 'POST'), Array(405, 'GET', 'ping'));
checkIdentical('action: an entry without target falls back to autopath', foundVals('/broken', Array('appToRun', 'routKind')), Array('broken', null));

// Redirections
checkIdentical('move: address and status', foundVals('/old/42', $moveKey), Array('redirect', '/items/42', 301));
checkIdentical('move: no application is named', foundVals('/old/42', Array('appToRun', 'doAction', 'statCode')), Array(null, null, null));
checkIdentical('move: a captured value is encoded again', foundVals('/old/a%20b', $moveKey), Array('redirect', '/items/a%20b', 301));
checkIdentical('move: a captured value stays one part of the path', foundVals('/old/..%2F..%2Fx', $moveKey), Array('redirect', '/items/..%2F..%2Fx', 301));
checkIdentical('move: a captured address cannot become the target', foundVals('/old/%2F%2Fexample.org', $moveKey), Array('redirect', '/items/%2F%2Fexample.org', 301));
checkIdentical('move: 302 by default, to a full address', foundVals('/away', $moveKey), Array('redirect', 'https://example.org/docs', 302));
checkIdentical('move: HEAD is served as GET', foundVals('/away', $moveKey, 'HEAD'), Array('redirect', 'https://example.org/docs', 302));
checkIdentical('move: another method is refused', foundVals('/away', Array('statCode', 'allowHdr'), 'POST'), Array(405, 'GET, HEAD'));
checkIdentical('move: allows opens it to another method', foundVals('/resend', $moveKey, 'POST'), Array('redirect', '/both', 307));
checkIdentical('move: and to that one only', foundVals('/resend', Array('statCode', 'allowHdr')), Array(405, 'POST'));
checkPregMatch('move: a status that is not a redirection stops the run', foundVals('/badcode', $moveKey), '/^THROWN RuntimeException: Route misconfigured/');
checkPregMatch('move: an address without scheme stops the run', foundVals('/badaddr', $moveKey), '/^THROWN RuntimeException: Route misconfigured/');

// Addresses a redirection accepts
foreach( Array('/'=>true, '/a/b?c=d#e'=>true, 'https://example.org'=>true, 'HTTP://example.org/a'=>true,
	''=>false, 'a/b'=>false, '//example.org'=>false, '/\\example.org'=>false, 'javascript:alert(1)'=>false, 'ftp://example.org'=>false,
	"/a\r\nSet-Cookie: x=1"=>false, '/a b'=>false, 'https:///path'=>false) as $adrVal=>$adrWant ){
	checkIdentical('address: '.printValueText($adrVal), Router::validRouteAddr(strval($adrVal)), $adrWant);
}

// Static file
checkIdentical('file: a declared file', foundVals('/style', $fileKey), Array('toStatic', $pageDir.DS.'site.css', 'text/css; charset=utf-8'));
checkIdentical('file: a missing file is a 404', foundVals('/nofile', $statKey), $notFile);
checkPregMatch('file: a file outside the root stops the run', foundVals('/secret', $fileKey), '/^THROWN RuntimeException: Route misconfigured/');
checkIdentical('file: nothing is served without a root', foundVals('/style', $statKey, 'GET', Array('fileRoot'=>'')), $notFile);
checkIdentical('file: nor with a root that does not exist', foundVals('/style', $statKey, 'GET', Array('fileRoot'=>'test'.DS.'nosuchfolder')), $notFile);
checkIdentical('file: POST is refused', foundVals('/style', Array('statCode', 'allowHdr'), 'POST'), Array(405, 'GET, HEAD'));

// Static folder
checkIdentical('folder: a file', foundVals('/docs/guide.txt', $fileKey), Array('toStatic', $pageDir.DS.'docs'.DS.'guide.txt', $txtType));
checkIdentical('folder: a file of a sub-folder', foundVals('/docs/deep/note.txt', $fileKey), Array('toStatic', $pageDir.DS.'docs'.DS.'deep'.DS.'note.txt', $txtType));
checkIdentical('folder: the folder itself gives its index', foundVals('/docs', $fileKey), Array('toStatic', $pageDir.DS.'docs'.DS.'index.html', 'text/html; charset=utf-8'));
checkIdentical('folder: with a trailing slash too', foundVals('/docs/', $fileKey), Array('toStatic', $pageDir.DS.'docs'.DS.'index.html', 'text/html; charset=utf-8'));
checkIdentical('folder: the query string is not part of the name', foundVals('/docs/guide.txt?v=2', $fileKey), Array('toStatic', $pageDir.DS.'docs'.DS.'guide.txt', $txtType));
checkIdentical('folder: its declared path', foundVals('/docs/guide.txt', Array('routPath')), Array('docs/*'));
checkIdentical('folder: a sub-folder without index is never listed', foundVals('/docs/deep', $statKey), $notFile);
checkIdentical('folder: a missing file', foundVals('/docs/nosuch.txt', $statKey), $notFile);

// No way out of the folder, and nothing but plain files with a known type
foreach( Array(
	'parent folder'=>'/docs/../site.css',
	'encoded parent folder'=>'/docs/%2e%2e/site.css',
	'encoded slash'=>'/docs/..%2Fsite.css',
	'encoded backslash'=>'/docs/..%5Csite.css',
	'double encoding'=>'/docs/%252e%252e/site.css',
	'parent folder further down'=>'/docs/deep/../../site.css',
	'up to the fixtures'=>'/docs/../../letters.txt',
	'current folder'=>'/docs/./guide.txt',
	'empty part'=>'/docs/deep//note.txt',
	'backslash as a separator'=>'/docs/deep%5Cnote.txt',
	'drive letter'=>'/docs/C:%5CWindows%5Cwin.ini',
	'alternate stream'=>'/docs/guide.txt::$DATA',
	'null byte'=>'/docs/guide.txt%00.html',
	'line break'=>'/docs/guide.txt%0A',
	'hidden file'=>'/docs/.hidden.txt',
	'script'=>'/docs/script.php',
	'extension without type'=>'/docs/data.ini'
) as $tryNme=>$tryUrl ){
	checkIdentical('no way out: '.$tryNme, foundVals($tryUrl, $statKey), $notFile);
}

// HTML page
checkIdentical('page: the file and the captured values', foundVals('/hello/7', Array('routKind', 'routTarg', 'pathVars')), Array('htmlPage', $pageDir.DS.'hello.html', Array('id'=>'7')));
checkPregMatch('page: a file that is not HTML stops the run', foundVals('/notpage', $fileKey), '/^THROWN RuntimeException: Route misconfigured/');
checkIdentical('page: one kind per method, GET', foundVals('/enter', Array('routKind', 'appToRun')), Array('htmlPage', null));
checkIdentical('page: one kind per method, POST', foundVals('/enter', Array('routKind', 'appToRun', 'doAction', 'routPath'), 'POST'), Array('toAction', 'probe', 'sanitize', 'enter'));
checkIdentical('page: one kind per method, HEAD as GET', foundVals('/enter', Array('routKind'), 'HEAD'), Array('htmlPage'));
checkIdentical('page: one kind per method, the rest refused', foundVals('/enter', Array('statCode', 'allowHdr'), 'PUT'), Array(405, 'GET, POST, HEAD'));

// Links to the declared routes
$routObj = madeRoutr('/');
checkIdentical('link: a plain route', $routObj->buildRouteLink('ping'), '/ping');
checkIdentical('link: a named part', $routObj->buildRouteLink('items/$id', Array('id'=>42)), '/items/42');
checkIdentical('link: slashes around the path are ignored', $routObj->buildRouteLink('/items/$id/', Array('id'=>42)), '/items/42');
checkIdentical('link: a value is encoded', $routObj->buildRouteLink('items/$id', Array('id'=>'a b/c?d')), '/items/a%20b%2Fc%3Fd');
checkIdentical('link: several named parts', $routObj->buildRouteLink('items/$id/parts/$part', Array('part'=>'p', 'id'=>'i')), '/items/i/parts/p');
checkIdentical('link: a query string', $routObj->buildRouteLink('items/$id', Array('id'=>1), Array('tab'=>'x y', 'n'=>2)), '/items/1?tab=x%20y&n=2');
checkIdentical('link: the rest of a folder route', $routObj->buildRouteLink('docs/*', Array('*'=>'deep/my note.txt')), '/docs/deep/my%20note.txt');
checkIdentical('link: a folder route itself', $routObj->buildRouteLink('docs/*'), '/docs/');
checkIdentical('link: an undeclared path is taken as it is in autopath mode', $routObj->buildRouteLink('shows/view/12'), '/shows/view/12');
checkForThrows('link: but not in manual mode', function(){ madeRoutr('/', 'GET', Array('detectBy'=>'manually'))->buildRouteLink('shows/view/12'); }, 'RuntimeException');
checkForThrows('link: an undeclared pattern', function() use ( $routObj ){ $routObj->buildRouteLink('nosuch/$id', Array('id'=>1)); }, 'RuntimeException');
checkForThrows('link: a named part without value', function() use ( $routObj ){ $routObj->buildRouteLink('items/$id'); }, 'RuntimeException');
checkForThrows('link: a named part with an empty value', function() use ( $routObj ){ $routObj->buildRouteLink('items/$id', Array('id'=>'')); }, 'RuntimeException');
$idxRout = madeRoutr('/', 'GET', Array('routeDir'=>'conf'.DS.'routes'));
checkIdentical('link: the index route is the root', $idxRout->buildRouteLink('index'), '/');

/**
 * Runs an action of the Probe application with a response and a router,
 * and returns its status, what would be sent and the headers it set.
 *
 * @param String  $actionName Action to run.
 * @param ?String $runAppOption Parameter of the action.
 * @return Array|String The status, message, body and headers, or what was thrown.
 */
function ranAction( String $actionName, ?String $runAppOption=null ): Array|String {
	return runCatchThrown(function() use ( $actionName, $runAppOption ){
		$respons = new Response();
		$actnObj = new nyu\Runs\Probe\Actions( new nyu\App\RunContext($actionName, $runAppOption, new Registry(Array()), null, mockRequestObj(), $respons, madeRoutr('/'), null, 'Probe', RUN_DIR.DS.'Probe') );
		$actnObj->runAskedAction();
		$headPrp = new ReflectionProperty($respons, 'headrArray');
		ob_start();
		$respons->transmitOutput();
		return Array($actnObj->statusCode, strval($actnObj->statusMssg), ob_get_clean(), $headPrp->getValue($respons));
	});
}

// Actionset helpers
checkIdentical('helper: a redirection ends the action', ranAction('redirect'), Array(302, '', '', Array('Location'=>'/probe/sanitize')));
checkIdentical('helper: a redirection elsewhere, with its status', ranAction('redirect', 'out'), Array(303, '', '', Array('Location'=>'https://example.org/')));
checkPregMatch('helper: an address without scheme is refused', ranAction('redirect', 'bad'), '/^THROWN RuntimeException: Redirection refused/');
checkPregMatch('helper: a status that is not a redirection is refused', ranAction('redirect', 'code'), '/^THROWN RuntimeException: Redirection refused/');
$pageRun = (array)ranAction('pgeProbe');
checkIdentical('helper: a page, with its status', array_slice($pageRun, 0, 2), Array(200, ''));
checkIdentical('helper: its values encoded and its link built', strpos(strval($pageRun[2] ?? ''), '<h1>&lt;b&gt;Tom &amp; &quot;Jerry&quot;&lt;/b&gt;</h1><a href="/items/a%20b?tab=x%20y">next</a>')!==false, true);
checkIdentical('helper: a page with its own status', array_slice((array)ranAction('pgeProbe', 'gone'), 0, 2), Array(404, ''));
checkIdentical('helper: a missing page is a 404', ranAction('pgeProbe', 'none'), Array(404, 'Not Found', '', Array()));
checkIdentical('helper: a name cannot lead out of the pages', ranAction('pgeProbe', 'out'), Array(404, 'Not Found', '', Array()));
checkPregMatch('helper: a link to an undeclared route fails', ranAction('routeUrl', 'x'), '/^THROWN RuntimeException: No route is declared/');

// The Runs' own routes (test/fixtures/runs/Mounted, Clashed), mounted under the name of the Run in lower case
$runKeys = Array('appToRun', 'doAction', 'routPath', 'routOwnr');
checkIdentical('run route: under its default mount, with its owner', foundVals('/mounted/listed', $runKeys), Array('Mounted', 'listed', 'mounted/listed', 'Mounted'));
checkIdentical('run route: a site route tells its owner too', foundVals('/items/42', Array('routOwnr')), Array('site'));
checkIdentical('run route: a path with a leading slash is from the root', foundVals('/fromRoot', $runKeys), Array('Mounted', 'fromRoot', 'fromRoot', 'Mounted'));
checkIdentical('run route: its methods apply', foundVals('/mounted/listed', Array('statCode', 'allowHdr'), 'POST'), Array(405, 'GET'));
checkIdentical('run route: a prefix replaces the name, whatever the case it is written in', foundVals('/space/listed', $runKeys, 'GET', Array('runMount'=>Array('MOUNTED'=>'/space/'))), Array('Mounted', 'listed', 'space/listed', 'Mounted'));
checkIdentical('run route: the name is then no mount', foundVals('/mounted/listed', $statKey, 'GET', Array('runMount'=>Array('mounted'=>'space'))), $notFile);
checkIdentical('run route: the root path is never moved', foundVals('/fromRoot', Array('doAction'), 'GET', Array('runMount'=>Array('mounted'=>'space'))), Array('fromRoot'));
checkIdentical('run route: an empty prefix mounts at the root', foundVals('/listed', Array('appToRun', 'routPath'), 'GET', Array('runMount'=>Array('mounted'=>''))), Array('Mounted', 'listed'));
checkIdentical('run route: false serves none of them', Array(foundVals('/mounted/listed', Array('statCode', 'routOwnr'), 'GET', Array('runMount'=>Array('mounted'=>false))), foundVals('/fromRoot', Array('routOwnr'), 'GET', Array('runMount'=>Array('mounted'=>false)))), Array(Array(404, null), Array(null)));
checkIdentical('run route: another Run keeps its default mount', foundVals('/clashed/listed', Array('appToRun', 'doAction'), 'GET', Array('runMount'=>Array('mounted'=>false))), Array('Clashed', 'clashing'));

// A Run links to its own routes as its file declares them, whatever its mount
checkIdentical('run link: under its default mount', linkFrom('listed'), '/mounted/listed');
checkIdentical('run link: under a renamed mount', Array(linkFrom('listed', Array('runMount'=>Array('mounted'=>'space'))), linkFrom('items/$id', Array('runMount'=>Array('mounted'=>'space/deep')))), Array('/space/listed', '/space/deep/items/7'));
checkIdentical('run link: a route from the root, written with or without its slash', Array(linkFrom('/fromRoot'), linkFrom('fromRoot'), linkFrom('fromRoot', Array('runMount'=>Array('mounted'=>'space')))), Array('/fromRoot', '/fromRoot', '/fromRoot'));
checkIdentical('run link: its $name parts filled', linkFrom('items/$id'), '/mounted/items/7');
checkIdentical('run link: a path the Run does not declare is a final path of the site', linkFrom('hello/$id'), '/hello/7');
checkIdentical('run link: a leading slash is always a final path', linkFrom('/items/$id'), '/items/7');
checkPregMatch('run link: a route the Run does not serve is not found (manually)', linkFrom('listed', Array('runMount'=>Array('mounted'=>false), 'detectBy'=>'manually')), '/^THROWN RuntimeException: No route is declared as "listed"/');

// A Run declaring its routes is closed : none of its actions is reached by its path, a 404 logged on runner
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('runlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
checkIdentical('closed run: an action its file does not list', foundVals('/mounted/unlisted', Array('appToRun', 'statCode', 'statMssg')), Array(null, 404, 'Not Found'));
checkIdentical('closed run: nor one it lists, by its path', foundVals('/mounted/fromRoot', $statKey), $notFile);
checkIdentical('closed run: whatever the case of its name', Array(foundVals('/MOUNTED/unlisted', $statKey), foundVals('/mOunted/unlisted', $statKey)), Array($notFile, $notFile));
checkIdentical('open run: one without a routes file stays on autopath', foundVals('/probe/identify/x', Array('appToRun', 'doAction', 'argument')), Array('probe', 'identify', 'x'));
nyu\Tools\Logger::plugLogsConfig( Array() );
checkPregMatch('closed run: the reason is logged on runner', implode("\n", file_exists(inTemporaryDir('runlogs').DS.'runner.log') ? file(inTemporaryDir('runlogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array()), '#Not found: "mounted/unlisted": not listed in the routes of the application#');

// The folder of a Run, found whatever the case of its name (Controller::runFoldrString), as the Router, the Controller and the Checker find it
$twinDir = inTemporaryDir('twinruns');
mkdir($twinDir.DS.'Twins', 0775, true);
mkdir($twinDir.DS.'Alone');
$caseFlg = !is_dir($twinDir.DS.'TWINS');
checkIdentical('run folder: found whatever the case of its name, as the disk writes it; a name that is not one is none', Array(nyu\App\Controller::runFoldrString('ALONE', $twinDir), nyu\App\Controller::runFoldrString('alone', $twinDir), nyu\App\Controller::runFoldrString('nowhere', $twinDir), nyu\App\Controller::runFoldrString('al/one', $twinDir)), Array('Alone', 'Alone', '', ''));
if( $caseFlg ){
	mkdir($twinDir.DS.'TWINS');
	nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('twinlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
	checkIdentical('run folder: two folders differing by their case alone are neither, whatever the case asked', Array(nyu\App\Controller::runFoldrString('twins', $twinDir), nyu\App\Controller::runFoldrString('Twins', $twinDir), nyu\App\Controller::runFoldrString('TWINS', $twinDir)), Array('', '', ''));
	nyu\Tools\Logger::plugLogsConfig( Array() );
	checkIdentical('run folder: the twins are logged as an error on runner, once', count(preg_grep('#\[ERROR\] Run folders \["(Twins","TWINS|TWINS","Twins)"\] differ by their case alone: neither is served#', file_exists(inTemporaryDir('twinlogs').DS.'runner.log') ? file(inTemporaryDir('twinlogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array())), 1);
}else{
	recordSkipping('run folders differing by their case alone', 'this disk does not tell the case of a name apart');
}

// End to end, through the Runner (test/lib/cacheChild.php): a closed Run named in another case never runs its action
$caseDir = makeProjectTxt('caserun', Array(
	'conf/system.ini'=>buildSystemIni("CNF_CACH = FALSE\n"),
	'conf/presets/caserun.rps'=>'{ "presetId": "caserun", "settings": { "confType": "all" }, "security": { "required": false }, "dispatch": { "detectBy": "autopath", "pathForm": "$appToRun/$doAction/$argument" } }',
	// The Runner installs the error handler from the project it runs
	'src/Error/handling.php'=>strval(file_get_contents(TOP_DIR.DS.'src'.DS.'Error'.DS.'handling.php'))
));
$caseRun = Array(TST_DIR.DS.'lib'.DS.'cacheChild.php', $caseDir, 'caserun');
checkIdentical('closed run, end to end: its listed route answers, an action it does not list is never run, whatever the case of its name', array_map(function( String $urlAddress ) use ( $caseRun ){ return trim(runScriptArray(array_merge($caseRun, Array($urlAddress)))['text']); }, Array('/mounted/listed', '/MOUNTED/unlisted', '/mOunted/unlisted')), Array('"mounted\/listed"', '{"error":"Not Found","code":404}', '{"error":"Not Found","code":404}'));

// A site route on the path of a Run route wins, with a warning; two Runs on one path: the first by name answers, an error
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('clashlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
checkIdentical('site over run: the site route answers', foundVals('/ping', Array('appToRun', 'doAction', 'routOwnr'), 'GET', Array('runMount'=>Array('mounted'=>''))), Array('probe', 'sanitize', 'site'));
checkIdentical('run clash: the first Run in name order answers', foundVals('/mounted/listed', $runKeys, 'GET', Array('runMount'=>Array('clashed'=>'mounted'))), Array('Clashed', 'clashing', 'mounted/listed', 'Clashed'));
nyu\Tools\Logger::plugLogsConfig( Array() );
$clshLog = implode("\n", file_exists(inTemporaryDir('clashlogs').DS.'runner.log') ? file(inTemporaryDir('clashlogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array());
checkPregMatch('site over run: a warning on runner', $clshLog, '#\[WARNING\] Route "/ping" of the application Mounted is also declared by the site: the site\'s answers#');
checkPregMatch('run clash: an error on runner', $clshLog, '#\[ERROR\] Route "/mounted/listed" is declared by the applications Clashed and Mounted: Clashed answers#');

// A site route answering with an action is an alias : its methods apply to it only, it closes nothing
$alsFldr = 'temp'.DS.'alias_'.getmypid();
@mkdir(TOP_DIR.DS.$alsFldr, 0775, true);
file_put_contents(TOP_DIR.DS.$alsFldr.DS.'alias.rte', '{ "hidden": { "mthdList": "POST", "pointsTo": { "appToRun": "mounted", "doAction": "unlisted" } }, "mirrors": { "mthdList": "POST", "pointsTo": { "appToRun": "probe", "doAction": "mirrored" } } }');
checkIdentical('alias: reaches an action its Run does not list', foundVals('/hidden', Array('appToRun', 'doAction', 'routOwnr'), 'POST', Array('routeDir'=>$alsFldr)), Array('mounted', 'unlisted', 'site'));
checkIdentical('alias: its methods apply to it', foundVals('/hidden', Array('statCode'), 'GET', Array('routeDir'=>$alsFldr)), Array(405));
checkIdentical('alias: the action stays reached by its own path, with any method', foundVals('/probe/mirrored/x', Array('appToRun', 'doAction'), 'GET', Array('routeDir'=>$alsFldr)), Array('probe', 'mirrored'));
dropTestFolder(TOP_DIR.DS.$alsFldr);

// The cache holds the Run routes, mounted: a router given them reads no file
$cchRout = madeRoutr('/space/listed', 'GET', Array('runMount'=>Array('mounted'=>'space')));
$cchList = $cchRout->retrieveRoutes();
checkIdentical('cache: the routes given hold the Run routes, by their mounted path, with their owner', Array($cchList['space/listed']['routOwnr'] ?? null, $cchList['fromRoot']['pointsTo']['appToRun'] ?? null, isset($cchList['listed'])), Array('Mounted', 'Mounted', false));
$cchRout = madeRoutr('/space/listed', 'GET', Array('routeDir'=>'test'.DS.'nosuchfolder', 'runMount'=>Array('mounted'=>false)));
$cchRout->assignRouteSet($cchList);
checkIdentical('cache: a router given them resolves a Run route without its file', Array($cchRout->locateRouteReg()->readValueByKey('appToRun'), $cchRout->locateRouteReg()->readValueByKey('routOwnr')), Array('Mounted', 'Mounted'));

// Keys of a declared route that nothing reads are told in the log
$rteFldr = 'temp'.DS.'routes_test_'.getmypid();
mkdir(TOP_DIR.DS.$rteFldr, 0775, true);
file_put_contents(TOP_DIR.DS.$rteFldr.DS.'odd.rte', '{ "fine": { "mthdList": "GET", "pointsTo": { "appToRun": "probe", "doAction": "sanitize" } },'
	.' "aged": { "limited": { "call": 1 }, "auth": "x", "pointsTo": { "appToRun": "probe", "doAction": "sanitize", "role": "sysadmin" } },'
	.' "both": { "pointsTo": { "GET": { "pageFile": "hello.html", "code": 200 }, "POST": { "appToRun": "probe" } } } }');
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('rtelogs'), 'mode'=>'BASE', 'pref'=>'Y') );
$oddRoutr = madeRoutr('/fine', 'GET', Array('routeDir'=>$rteFldr));
checkIdentical('unknown keys: the routes are still read', $oddRoutr->locateRouteReg()->readValueByKey('appToRun'), 'probe');
$oddRoutr->allRoutesArray();
nyu\Tools\Logger::plugLogsConfig( Array() );
dropTestFolder(TOP_DIR.DS.$rteFldr);
checkIdentical('unknown keys: each is told once, with its route and its file', file_exists(inTemporaryDir('rtelogs').DS.'runner.log') ? file(inTemporaryDir('rtelogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array(), Array(
	'['.date('Y').'] [WARNING] Route "aged" of odd.rte holds an unknown key: limited',
	'['.date('Y').'] [WARNING] Route "aged" of odd.rte holds an unknown key: auth',
	'['.date('Y').'] [WARNING] Route "aged" of odd.rte holds an unknown key: pointsTo.role',
	'['.date('Y').'] [WARNING] Route "both" of odd.rte holds an unknown key: pointsTo.code'
));

// The page a site answers an error with
$errRoutr = madeRoutr('/nothing', 'GET', Array('fileRoot'=>'test'.DS.'fixtures'.DS.'errpages'));
checkIdentical('error page: the one of the status, in the errors folder of the pages', $errRoutr->errorPgeString(404), realpath(TST_DIR.DS.'fixtures'.DS.'errpages'.DS.'errors'.DS.'404.html'));
checkIdentical('error page: none for a status without a page, nor without a folder of pages', Array($errRoutr->errorPgeString(500), madeRoutr('/nothing')->errorPgeString(404), madeRoutr('/nothing', 'GET', Array('fileRoot'=>''))->errorPgeString(404)), Array(null, null, null));
checkIdentical('static: a file that is not found is told as a static reply', foundVals('/nofile', Array('statCode', 'routKind')), Array(404, 'toStatic'));

// A hidden file reached by its short name (Windows, 8.3 names on): the real name is checked too.
// The site lies in the temporary folder of the system, linked into the project: the volume of the project may make no short names
$shtFldr = 'temp'.DS.'shortnm_'.getmypid();
@mkdir(TOP_DIR.DS.$shtFldr, 0775, true);
$sysFldr = rtrim(sys_get_temp_dir(), '/\\').DS.'nyu_shortnm_'.getmypid();
@mkdir($sysFldr.DS.'files', 0775, true);
file_put_contents($sysFldr.DS.'files'.DS.'.secret.txt', 'hidden');
file_put_contents($sysFldr.DS.'files'.DS.'shown.txt', 'shown');
if( DS=='\\' ){
	shell_exec('cmd /c mklink /J "'.TOP_DIR.DS.$shtFldr.DS.'site" "'.$sysFldr.'"');
}else{
	@symlink($sysFldr, TOP_DIR.DS.$shtFldr.DS.'site');
}
file_put_contents(TOP_DIR.DS.$shtFldr.DS.'short.rte', '{ "box/*": { "pointsTo": { "fldrPath": "files" } } }');
$shrtNme = '';
if( DS=='\\' ){
	foreach( (array)explode("\n", strval(shell_exec('cmd /c dir /x /a "'.$sysFldr.DS.'files"'))) as $dirLin ){
		if( preg_match('/\s(\S+~\d\S*)\s+\.secret\.txt\s*$/i', $dirLin, $shrtPrt) ){
			$shrtNme = $shrtPrt[1];
		}
	}
}
$shrtCnf = Array('routeDir'=>$shtFldr, 'fileRoot'=>$shtFldr.DS.'site');
checkIdentical('folder route: a plain file is served', foundVals('/box/shown.txt', Array('routKind'), 'GET', $shrtCnf), Array('toStatic'));
if( $shrtNme!=='' ){
	checkIdentical('folder route: a hidden file asked for by its short name is the same 404', foundVals('/box/'.$shrtNme, $statKey, 'GET', $shrtCnf), $notFile);
}else{
	recordSkipping('folder route: a hidden file asked for by its short name', 'no 8.3 short names in the temporary folder of the system');
}
// The link goes first, leaving what it points to; then the files themselves
if( DS=='\\' ){
	@rmdir(TOP_DIR.DS.$shtFldr.DS.'site');
}else{
	@unlink(TOP_DIR.DS.$shtFldr.DS.'site');
}
dropTestFolder(TOP_DIR.DS.$shtFldr);
dropTestFolder($sysFldr);
finishChecking();
