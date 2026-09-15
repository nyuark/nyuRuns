<?php

// Tools\Router : the declared routes that answer without an action
// (redirection, static file or folder, HTML page), the safety of the paths
// asked for in a static folder, the links to the declared routes, and the
// Actionset helpers built on them. Routes come from test/fixtures/routes,
// their files from test/fixtures/pages.

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');
require __DIR__.'/../lib/harness.php';
require RUN_DIR.DS.'Probe'.DS.'Actions.php';

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
		$propObj->setAccessible(true);
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

$pageDir = realpath(TST_DIR.DS.'fixtures'.DS.'pages');
$moveKey = Array('rteKind', 'rteGoal', 'rteCode');
$fileKey = Array('rteKind', 'rteGoal', 'rteType');
$statKey = Array('statCode', 'statMssg');
$notFile = Array(404, 'File not found');
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
		$actnObj = new nyu\Runs\Probe\Actions( $actionName, $actnOption, new Registry(Array()), null, null, fakeReqst(), $respons, madeRoutr('/') );
		$headPrp = new ReflectionProperty($respons, 'headrArr');
		$headPrp->setAccessible(true);
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
checkSame('helper: a missing page is a 404', ranAction('page', 'none'), Array(404, 'Page not found', '', Array()));
checkSame('helper: a name cannot lead out of the pages', ranAction('page', 'out'), Array(404, 'Page not found', '', Array()));
checkLike('helper: a link to an undeclared route fails', ranAction('link', 'x'), '/^THROWN RuntimeException: No route is declared/');

// An action a declared route answers with is closed to the path : it is only reached through its route
$autoRtr = madeRoutr('/probe/mirror/x');
$autoRtr->findRoute();
checkSame('shut: an action found from the path, the target of a declared route', Array($autoRtr->shutsActn('Probe', 'mirror'), $autoRtr->shutsActn('probe', 'MIRROR'), $autoRtr->shutsActn('PROBE', 'clean')), Array(true, true, true));
checkSame('shut: an action no route answers with, another application', Array($autoRtr->shutsActn('Probe', 'whoami'), $autoRtr->shutsActn('Shows', 'mirror'), $autoRtr->shutsActn('Probe', '')), Array(false, false, false));
checkSame('shut: not for an action whose routes limit no method', $autoRtr->shutsActn('Probe', 'text'), false);
$declRtr = madeRoutr('/items/42');
$declRtr->findRoute();
checkSame('shut: never when the request came through the declared route', $declRtr->shutsActn('Probe', 'mirror'), false);
checkSame('shut: never when no route was looked for', madeRoutr('/probe/mirror/x')->shutsActn('Probe', 'mirror'), false);
$manuRtr = madeRoutr('/probe/mirror/x', 'GET', Array('detect'=>'manually'));
$manuRtr->findRoute();
checkSame('shut: never where the path finds no action', $manuRtr->shutsActn('Probe', 'mirror'), false);

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

endChecks();
