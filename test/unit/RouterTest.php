<?php

// Tools\Router : the declared routes that answer without an action
// (redirection, static file or folder, HTML page), the safety of the paths
// asked for in a static folder, the links to the declared routes, and the
// Actionset helpers built on them. Routes come from test/fixtures/routes,
// their files from test/fixtures/pages.

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');
require __DIR__.'/../lib/harness.php';
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
	$request = fakeReqst();
	foreach( Array('inMethod'=>$httpMethod, 'queryURI'=>Array('reqstURI'=>$urlAddress)) as $prpNme=>$prpVal ){
		$propObj = new ReflectionProperty($request, $prpNme);
		$propObj->setValue($request, $prpVal);
	}
	return new Router( $routeConfg + Array('detect'=>'autopath', 'mapper'=>'$app/$action/$param', 'folder'=>'test'.DS.'fixtures'.DS.'routes', 'htroot'=>'test'.DS.'fixtures'.DS.'pages'), $request );
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
	return runSafely(function() use ( $urlAddress, $wantedKeys, $httpMethod, $routeConfg ){
		$rteReg = madeRoutr($urlAddress, $httpMethod, $routeConfg)->findRoute();
		return array_map(function( String $keyNme ) use ( $rteReg ){ return $rteReg->readValue($keyNme); }, $wantedKeys);
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
	return strval(runSafely(function() use ( $routeShape, $routeConfg ){
		$actnObj = new nyu\Runs\Mounted\Actions( new nyu\App\RunContext('linking', $routeShape, new Registry(Array()), null, fakeReqst(), new Response(), madeRoutr('/', 'GET', $routeConfg), null, 'Mounted', RUN_DIR.DS.'Mounted') );
		$actnObj->runAction();
		return $actnObj->asOutput;
	}));
}

$pageDir = realpath(TST_DIR.DS.'fixtures'.DS.'pages');
$moveKey = Array('rteKind', 'rteGoal', 'rteCode');
$fileKey = Array('rteKind', 'rteGoal', 'rteType');
$statKey = Array('statCode', 'statMssg');
$notFile = Array(404, 'Not Found');
$txtType = 'text/plain; charset=utf-8';

// Action routes still resolve, and tell their kind and their declared path
checkSame('action: kind and declared path', foundVals('/items/42', Array('app', 'action', 'param', 'rteKind', 'rtePath')), Array('probe', 'mirror', '42', 'action', 'items/$id'));
checkSame('action: exact key', foundVals('/ping', Array('app', 'rteKind', 'rtePath')), Array('probe', 'action', 'ping'));
checkSame('action: a refused method keeps the declared path', foundVals('/ping', Array('statCode', 'allowed', 'rtePath'), 'POST'), Array(405, 'GET', 'ping'));
checkSame('action: an entry without target falls back to autopath', foundVals('/broken', Array('app', 'rteKind')), Array('broken', null));

// Redirections
checkSame('move: address and status', foundVals('/old/42', $moveKey), Array('redrct', '/items/42', 301));
checkSame('move: no application is named', foundVals('/old/42', Array('app', 'action', 'statCode')), Array(null, null, null));
checkSame('move: a captured value is encoded again', foundVals('/old/a%20b', $moveKey), Array('redrct', '/items/a%20b', 301));
checkSame('move: a captured value stays one part of the path', foundVals('/old/..%2F..%2Fx', $moveKey), Array('redrct', '/items/..%2F..%2Fx', 301));
checkSame('move: a captured address cannot become the target', foundVals('/old/%2F%2Fexample.org', $moveKey), Array('redrct', '/items/%2F%2Fexample.org', 301));
checkSame('move: 302 by default, to a full address', foundVals('/away', $moveKey), Array('redrct', 'https://example.org/docs', 302));
checkSame('move: HEAD is served as GET', foundVals('/away', $moveKey, 'HEAD'), Array('redrct', 'https://example.org/docs', 302));
checkSame('move: another method is refused', foundVals('/away', Array('statCode', 'allowed'), 'POST'), Array(405, 'GET, HEAD'));
checkSame('move: allows opens it to another method', foundVals('/resend', $moveKey, 'POST'), Array('redrct', '/both', 307));
checkSame('move: and to that one only', foundVals('/resend', Array('statCode', 'allowed')), Array(405, 'POST'));
checkLike('move: a status that is not a redirection stops the run', foundVals('/badcode', $moveKey), '/^THROWN RuntimeException: Route misconfigured/');
checkLike('move: an address without scheme stops the run', foundVals('/badaddr', $moveKey), '/^THROWN RuntimeException: Route misconfigured/');

// Addresses a redirection accepts
foreach( Array('/'=>true, '/a/b?c=d#e'=>true, 'https://example.org'=>true, 'HTTP://example.org/a'=>true,
	''=>false, 'a/b'=>false, '//example.org'=>false, '/\\example.org'=>false, 'javascript:alert(1)'=>false, 'ftp://example.org'=>false,
	"/a\r\nSet-Cookie: x=1"=>false, '/a b'=>false, 'https:///path'=>false) as $adrVal=>$adrWant ){
	checkSame('address: '.showValue($adrVal), Router::validAddr(strval($adrVal)), $adrWant);
}

// Static file
checkSame('file: a declared file', foundVals('/style', $fileKey), Array('static', $pageDir.DS.'site.css', 'text/css; charset=utf-8'));
checkSame('file: a missing file is a 404', foundVals('/nofile', $statKey), $notFile);
checkLike('file: a file outside the root stops the run', foundVals('/secret', $fileKey), '/^THROWN RuntimeException: Route misconfigured/');
checkSame('file: nothing is served without a root', foundVals('/style', $statKey, 'GET', Array('htroot'=>'')), $notFile);
checkSame('file: nor with a root that does not exist', foundVals('/style', $statKey, 'GET', Array('htroot'=>'test'.DS.'nosuchfolder')), $notFile);
checkSame('file: POST is refused', foundVals('/style', Array('statCode', 'allowed'), 'POST'), Array(405, 'GET, HEAD'));

// Static folder
checkSame('folder: a file', foundVals('/docs/guide.txt', $fileKey), Array('static', $pageDir.DS.'docs'.DS.'guide.txt', $txtType));
checkSame('folder: a file of a sub-folder', foundVals('/docs/deep/note.txt', $fileKey), Array('static', $pageDir.DS.'docs'.DS.'deep'.DS.'note.txt', $txtType));
checkSame('folder: the folder itself gives its index', foundVals('/docs', $fileKey), Array('static', $pageDir.DS.'docs'.DS.'index.html', 'text/html; charset=utf-8'));
checkSame('folder: with a trailing slash too', foundVals('/docs/', $fileKey), Array('static', $pageDir.DS.'docs'.DS.'index.html', 'text/html; charset=utf-8'));
checkSame('folder: the query string is not part of the name', foundVals('/docs/guide.txt?v=2', $fileKey), Array('static', $pageDir.DS.'docs'.DS.'guide.txt', $txtType));
checkSame('folder: its declared path', foundVals('/docs/guide.txt', Array('rtePath')), Array('docs/*'));
checkSame('folder: a sub-folder without index is never listed', foundVals('/docs/deep', $statKey), $notFile);
checkSame('folder: a missing file', foundVals('/docs/nosuch.txt', $statKey), $notFile);

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
	checkSame('no way out: '.$tryNme, foundVals($tryUrl, $statKey), $notFile);
}

// HTML page
checkSame('page: the file and the captured values', foundVals('/hello/7', Array('rteKind', 'rteGoal', 'pathVars')), Array('htpage', $pageDir.DS.'hello.html', Array('id'=>'7')));
checkLike('page: a file that is not HTML stops the run', foundVals('/notpage', $fileKey), '/^THROWN RuntimeException: Route misconfigured/');
checkSame('page: one kind per method, GET', foundVals('/enter', Array('rteKind', 'app')), Array('htpage', null));
checkSame('page: one kind per method, POST', foundVals('/enter', Array('rteKind', 'app', 'action', 'rtePath'), 'POST'), Array('action', 'probe', 'clean', 'enter'));
checkSame('page: one kind per method, HEAD as GET', foundVals('/enter', Array('rteKind'), 'HEAD'), Array('htpage'));
checkSame('page: one kind per method, the rest refused', foundVals('/enter', Array('statCode', 'allowed'), 'PUT'), Array(405, 'GET, POST, HEAD'));

// Links to the declared routes
$routObj = madeRoutr('/');
checkSame('link: a plain route', $routObj->buildLink('ping'), '/ping');
checkSame('link: a named part', $routObj->buildLink('items/$id', Array('id'=>42)), '/items/42');
checkSame('link: slashes around the path are ignored', $routObj->buildLink('/items/$id/', Array('id'=>42)), '/items/42');
checkSame('link: a value is encoded', $routObj->buildLink('items/$id', Array('id'=>'a b/c?d')), '/items/a%20b%2Fc%3Fd');
checkSame('link: several named parts', $routObj->buildLink('items/$id/parts/$part', Array('part'=>'p', 'id'=>'i')), '/items/i/parts/p');
checkSame('link: a query string', $routObj->buildLink('items/$id', Array('id'=>1), Array('tab'=>'x y', 'n'=>2)), '/items/1?tab=x%20y&n=2');
checkSame('link: the rest of a folder route', $routObj->buildLink('docs/*', Array('*'=>'deep/my note.txt')), '/docs/deep/my%20note.txt');
checkSame('link: a folder route itself', $routObj->buildLink('docs/*'), '/docs/');
checkSame('link: an undeclared path is taken as it is in autopath mode', $routObj->buildLink('shows/view/12'), '/shows/view/12');
checkThrw('link: but not in manual mode', function(){ madeRoutr('/', 'GET', Array('detect'=>'manually'))->buildLink('shows/view/12'); }, 'RuntimeException');
checkThrw('link: an undeclared pattern', function() use ( $routObj ){ $routObj->buildLink('nosuch/$id', Array('id'=>1)); }, 'RuntimeException');
checkThrw('link: a named part without value', function() use ( $routObj ){ $routObj->buildLink('items/$id'); }, 'RuntimeException');
checkThrw('link: a named part with an empty value', function() use ( $routObj ){ $routObj->buildLink('items/$id', Array('id'=>'')); }, 'RuntimeException');
$idxRout = madeRoutr('/', 'GET', Array('folder'=>'conf'.DS.'routes'));
checkSame('link: the index route is the root', $idxRout->buildLink('index'), '/');

/**
 * Runs an action of the Probe application with a response and a router,
 * and returns its status, what would be sent and the headers it set.
 *
 * @param String  $actionName Action to run.
 * @param ?String $actnOption Parameter of the action.
 * @return Array|String The status, message, body and headers, or what was thrown.
 */
function ranAction( String $actionName, ?String $actnOption=null ): Array|String {
	return runSafely(function() use ( $actionName, $actnOption ){
		$respons = new Response();
		$actnObj = new nyu\Runs\Probe\Actions( new nyu\App\RunContext($actionName, $actnOption, new Registry(Array()), null, fakeReqst(), $respons, madeRoutr('/'), null, 'Probe', RUN_DIR.DS.'Probe') );
		$actnObj->runAction();
		$headPrp = new ReflectionProperty($respons, 'headrArr');
		ob_start();
		$respons->sendOutpt();
		return Array($actnObj->statCode, strval($actnObj->statMssg), ob_get_clean(), $headPrp->getValue($respons));
	});
}

// Actionset helpers
checkSame('helper: a redirection ends the action', ranAction('moved'), Array(302, '', '', Array('Location'=>'/probe/clean')));
checkSame('helper: a redirection elsewhere, with its status', ranAction('moved', 'out'), Array(303, '', '', Array('Location'=>'https://example.org/')));
checkLike('helper: an address without scheme is refused', ranAction('moved', 'bad'), '/^THROWN RuntimeException: Redirection refused/');
checkLike('helper: a status that is not a redirection is refused', ranAction('moved', 'code'), '/^THROWN RuntimeException: Redirection refused/');
$pageRun = (array)ranAction('page');
checkSame('helper: a page, with its status', array_slice($pageRun, 0, 2), Array(200, ''));
checkSame('helper: its values encoded and its link built', strpos(strval($pageRun[2] ?? ''), '<h1>&lt;b&gt;Tom &amp; &quot;Jerry&quot;&lt;/b&gt;</h1><a href="/items/a%20b?tab=x%20y">next</a>')!==false, true);
checkSame('helper: a page with its own status', array_slice((array)ranAction('page', 'gone'), 0, 2), Array(404, ''));
checkSame('helper: a missing page is a 404', ranAction('page', 'none'), Array(404, 'Not Found', '', Array()));
checkSame('helper: a name cannot lead out of the pages', ranAction('page', 'out'), Array(404, 'Not Found', '', Array()));
checkLike('helper: a link to an undeclared route fails', ranAction('link', 'x'), '/^THROWN RuntimeException: No route is declared/');

// The Runs' own routes (test/fixtures/runs/Mounted, Clashed), mounted under the name of the Run in lower case
$runKeys = Array('app', 'action', 'rtePath', 'rteOwnr');
checkSame('run route: under its default mount, with its owner', foundVals('/mounted/listed', $runKeys), Array('Mounted', 'listed', 'mounted/listed', 'Mounted'));
checkSame('run route: a site route tells its owner too', foundVals('/items/42', Array('rteOwnr')), Array('site'));
checkSame('run route: a path with a leading slash is from the root', foundVals('/rooted', $runKeys), Array('Mounted', 'rooted', 'rooted', 'Mounted'));
checkSame('run route: its methods apply', foundVals('/mounted/listed', Array('statCode', 'allowed'), 'POST'), Array(405, 'GET'));
checkSame('run route: a prefix replaces the name, whatever the case it is written in', foundVals('/space/listed', $runKeys, 'GET', Array('mounts'=>Array('MOUNTED'=>'/space/'))), Array('Mounted', 'listed', 'space/listed', 'Mounted'));
checkSame('run route: the name is then no mount', foundVals('/mounted/listed', $statKey, 'GET', Array('mounts'=>Array('mounted'=>'space'))), $notFile);
checkSame('run route: the root path is never moved', foundVals('/rooted', Array('action'), 'GET', Array('mounts'=>Array('mounted'=>'space'))), Array('rooted'));
checkSame('run route: an empty prefix mounts at the root', foundVals('/listed', Array('app', 'rtePath'), 'GET', Array('mounts'=>Array('mounted'=>''))), Array('Mounted', 'listed'));
checkSame('run route: false serves none of them', Array(foundVals('/mounted/listed', Array('statCode', 'rteOwnr'), 'GET', Array('mounts'=>Array('mounted'=>false))), foundVals('/rooted', Array('rteOwnr'), 'GET', Array('mounts'=>Array('mounted'=>false)))), Array(Array(404, null), Array(null)));
checkSame('run route: another Run keeps its default mount', foundVals('/clashed/listed', Array('app', 'action'), 'GET', Array('mounts'=>Array('mounted'=>false))), Array('Clashed', 'clashed'));

// A Run links to its own routes as its file declares them, whatever its mount
checkSame('run link: under its default mount', linkFrom('listed'), '/mounted/listed');
checkSame('run link: under a renamed mount', Array(linkFrom('listed', Array('mounts'=>Array('mounted'=>'space'))), linkFrom('items/$id', Array('mounts'=>Array('mounted'=>'space/deep')))), Array('/space/listed', '/space/deep/items/7'));
checkSame('run link: a route from the root, written with or without its slash', Array(linkFrom('/rooted'), linkFrom('rooted'), linkFrom('rooted', Array('mounts'=>Array('mounted'=>'space')))), Array('/rooted', '/rooted', '/rooted'));
checkSame('run link: its $name parts filled', linkFrom('items/$id'), '/mounted/items/7');
checkSame('run link: a path the Run does not declare is a final path of the site', linkFrom('hello/$id'), '/hello/7');
checkSame('run link: a leading slash is always a final path', linkFrom('/items/$id'), '/items/7');
checkLike('run link: a route the Run does not serve is not found (manually)', linkFrom('listed', Array('mounts'=>Array('mounted'=>false), 'detect'=>'manually')), '/^THROWN RuntimeException: No route is declared as "listed"/');

// A Run declaring its routes is closed : none of its actions is reached by its path, a 404 logged on runner
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('runlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
checkSame('closed run: an action its file does not list', foundVals('/mounted/unlisted', Array('app', 'statCode', 'statMssg')), Array(null, 404, 'Not Found'));
checkSame('closed run: nor one it lists, by its path', foundVals('/mounted/rooted', $statKey), $notFile);
checkSame('closed run: whatever the case of its name', foundVals('/MOUNTED/unlisted', $statKey), $notFile);
checkSame('open run: one without a routes file stays on autopath', foundVals('/probe/whoami/x', Array('app', 'action', 'param')), Array('probe', 'whoami', 'x'));
nyu\Tools\Logger::plugSetup( Array() );
checkLike('closed run: the reason is logged on runner', implode("\n", file_exists(inTempDir('runlogs').DS.'runner.log') ? file(inTempDir('runlogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array()), '#Not found: "mounted/unlisted": not listed in the routes of the application#');

// A site route on the path of a Run route wins, with a warning; two Runs on one path: the first by name answers, an error
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('clashlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
checkSame('site over run: the site route answers', foundVals('/ping', Array('app', 'action', 'rteOwnr'), 'GET', Array('mounts'=>Array('mounted'=>''))), Array('probe', 'clean', 'site'));
checkSame('run clash: the first Run in name order answers', foundVals('/mounted/listed', $runKeys, 'GET', Array('mounts'=>Array('clashed'=>'mounted'))), Array('Clashed', 'clashed', 'mounted/listed', 'Clashed'));
nyu\Tools\Logger::plugSetup( Array() );
$clshLog = implode("\n", file_exists(inTempDir('clashlogs').DS.'runner.log') ? file(inTempDir('clashlogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array());
checkLike('site over run: a warning on runner', $clshLog, '#\[WARNING\] Route "/ping" of the application Mounted is also declared by the site: the site\'s answers#');
checkLike('run clash: an error on runner', $clshLog, '#\[ERROR\] Route "/mounted/listed" is declared by the applications Clashed and Mounted: Clashed answers#');

// A site route answering with an action is an alias : its methods apply to it only, it closes nothing
$alsFldr = 'temp'.DS.'alias_'.getmypid();
@mkdir(TOP_DIR.DS.$alsFldr, 0775, true);
file_put_contents(TOP_DIR.DS.$alsFldr.DS.'alias.rte', '{ "hidden": { "allows": "POST", "target": { "app": "mounted", "act": "unlisted" } }, "mirrors": { "allows": "POST", "target": { "app": "probe", "act": "mirror" } } }');
checkSame('alias: reaches an action its Run does not list', foundVals('/hidden', Array('app', 'action', 'rteOwnr'), 'POST', Array('folder'=>$alsFldr)), Array('mounted', 'unlisted', 'site'));
checkSame('alias: its methods apply to it', foundVals('/hidden', Array('statCode'), 'GET', Array('folder'=>$alsFldr)), Array(405));
checkSame('alias: the action stays reached by its own path, with any method', foundVals('/probe/mirror/x', Array('app', 'action'), 'GET', Array('folder'=>$alsFldr)), Array('probe', 'mirror'));
dropFoldr(TOP_DIR.DS.$alsFldr);

// The cache holds the Run routes, mounted: a router given them reads no file
$cchRout = madeRoutr('/space/listed', 'GET', Array('mounts'=>Array('mounted'=>'space')));
$cchList = $cchRout->getRoutes();
checkSame('cache: the routes given hold the Run routes, by their mounted path, with their owner', Array($cchList['space/listed']['rteOwnr'] ?? null, $cchList['rooted']['target']['app'] ?? null, isset($cchList['listed'])), Array('Mounted', 'Mounted', false));
$cchRout = madeRoutr('/space/listed', 'GET', Array('folder'=>'test'.DS.'nosuchfolder', 'mounts'=>Array('mounted'=>false)));
$cchRout->setRoutes($cchList);
checkSame('cache: a router given them resolves a Run route without its file', Array($cchRout->findRoute()->readValue('app'), $cchRout->findRoute()->readValue('rteOwnr')), Array('Mounted', 'Mounted'));

// Keys of a declared route that nothing reads are told in the log
$rteFldr = 'temp'.DS.'routes_test_'.getmypid();
mkdir(TOP_DIR.DS.$rteFldr, 0775, true);
file_put_contents(TOP_DIR.DS.$rteFldr.DS.'odd.rte', '{ "fine": { "allows": "GET", "target": { "app": "probe", "act": "clean" } },'
	.' "aged": { "limited": { "call": 1 }, "auth": "x", "target": { "app": "probe", "act": "clean", "role": "manage" } },'
	.' "both": { "target": { "GET": { "pge": "hello.html", "code": 200 }, "POST": { "app": "probe" } } } }');
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('rtelogs'), 'mode'=>'BASE', 'pref'=>'Y') );
$oddRoutr = madeRoutr('/fine', 'GET', Array('folder'=>$rteFldr));
checkSame('unknown keys: the routes are still read', $oddRoutr->findRoute()->readValue('app'), 'probe');
$oddRoutr->allRoutes();
nyu\Tools\Logger::plugSetup( Array() );
dropFoldr(TOP_DIR.DS.$rteFldr);
checkSame('unknown keys: each is told once, with its route and its file', file_exists(inTempDir('rtelogs').DS.'runner.log') ? file(inTempDir('rtelogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array(), Array(
	'['.date('Y').'] [WARNING] Route "aged" of odd.rte holds an unknown key: limited',
	'['.date('Y').'] [WARNING] Route "aged" of odd.rte holds an unknown key: auth',
	'['.date('Y').'] [WARNING] Route "aged" of odd.rte holds an unknown key: target.role',
	'['.date('Y').'] [WARNING] Route "both" of odd.rte holds an unknown key: target.code'
));

// The page a site answers an error with
$errRoutr = madeRoutr('/nothing', 'GET', Array('htroot'=>'test'.DS.'fixtures'.DS.'errpages'));
checkSame('error page: the one of the status, in the errors folder of the pages', $errRoutr->errorPage(404), realpath(TST_DIR.DS.'fixtures'.DS.'errpages'.DS.'errors'.DS.'404.html'));
checkSame('error page: none for a status without a page, nor without a folder of pages', Array($errRoutr->errorPage(500), madeRoutr('/nothing')->errorPage(404), madeRoutr('/nothing', 'GET', Array('htroot'=>''))->errorPage(404)), Array(null, null, null));
checkSame('static: a file that is not found is told as a static reply', foundVals('/nofile', Array('statCode', 'rteKind')), Array(404, 'static'));

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
file_put_contents(TOP_DIR.DS.$shtFldr.DS.'short.rte', '{ "box/*": { "target": { "dir": "files" } } }');
$shrtNme = '';
if( DS=='\\' ){
	foreach( (array)explode("\n", strval(shell_exec('cmd /c dir /x /a "'.$sysFldr.DS.'files"'))) as $dirLin ){
		if( preg_match('/\s(\S+~\d\S*)\s+\.secret\.txt\s*$/i', $dirLin, $shrtPrt) ){
			$shrtNme = $shrtPrt[1];
		}
	}
}
$shrtCnf = Array('folder'=>$shtFldr, 'htroot'=>$shtFldr.DS.'site');
checkSame('folder route: a plain file is served', foundVals('/box/shown.txt', Array('rteKind'), 'GET', $shrtCnf), Array('static'));
if( $shrtNme!=='' ){
	checkSame('folder route: a hidden file asked for by its short name is the same 404', foundVals('/box/'.$shrtNme, $statKey, 'GET', $shrtCnf), $notFile);
}else{
	skipCheck('folder route: a hidden file asked for by its short name', 'no 8.3 short names in the temporary folder of the system');
}
// The link goes first, leaving what it points to; then the files themselves
if( DS=='\\' ){
	@rmdir(TOP_DIR.DS.$shtFldr.DS.'site');
}else{
	@unlink(TOP_DIR.DS.$shtFldr.DS.'site');
}
dropFoldr(TOP_DIR.DS.$shtFldr);
dropFoldr($sysFldr);
endChecks();
