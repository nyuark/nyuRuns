<?php

// The routes of the Runs that the Router leaves out or reports: a target that is not an action, a site pattern over
// a Run route (the Auth routes under a site auth/*), and the mounts a preset may give, checked when it loads.
// The Runs lie in a folder of the script's own, so that what they make the Router log stays out of the other tests.

define('RUN_DIR', sys_get_temp_dir().DIRECTORY_SEPARATOR.'nyuruns_test_'.getmypid().DIRECTORY_SEPARATOR.'runs');
require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

use nyu\Tools\Router;
use nyu\Tools\Logger;

/**
 * Builds a router for a request, on the site routes of a folder of the project.
 *
 * @param String $urlAddress Path of the request, with its leading slash.
 * @param String $httpMethod Method of the request.
 * @param Array  $routeConfg Values replacing those of the route configuration.
 * @return Router The router.
 */
function madeRoutr( String $urlAddress, String $httpMethod='GET', Array $routeConfg=Array() ): Router {
	$request = fakeReqst();
	foreach( Array('inMethod'=>$httpMethod, 'queryURI'=>Array('reqstURI'=>$urlAddress)) as $prpNme=>$prpVal ){
		(new ReflectionProperty($request, $prpNme))->setValue($request, $prpVal);
	}
	return new Router( $routeConfg + Array('detect'=>'autopath', 'mapper'=>'$app/$action/$param', 'folder'=>'test'.DS.'nosuchfolder'), $request );
}

@mkdir(RUN_DIR.DS.'Mixed', 0775, true);
@mkdir(RUN_DIR.DS.'Auth', 0775, true);
file_put_contents(RUN_DIR.DS.'Mixed'.DS.'routes.rte', '{ "away": { "target": { "url": "/elsewhere" } }, "kept": { "target": { "act": "kept" } },'
	.' "split": { "target": { "GET": { "pge": "hello.html" }, "POST": { "act": "kept" } } } }');
copy(TOP_DIR.DS.'runs'.DS.'Auth'.DS.'routes.rte', RUN_DIR.DS.'Auth'.DS.'routes.rte');
$keyList = Array('app', 'action', 'statCode', 'rteOwnr');

// A Run serves its own actions only: a target naming anything else is warned and left out
Logger::plugSetup( Array('path'=>inTempDir('mixlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
$mixReg = madeRoutr('/mixed/away')->findRoute();
Logger::plugSetup( Array() );
checkSame('not an action: the route is not served, a 404 of the closed Run', Array($mixReg->readValue('app'), $mixReg->readValue('statCode')), Array(null, 404));
$mixLog = implode("\n", file_exists(inTempDir('mixlogs').DS.'runner.log') ? file(inTempDir('mixlogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array());
checkLike('not an action: its key is warned on runner', $mixLog, '#\[WARNING\] Route "away" of Mixed/routes.rte holds an unknown key: target\.url#');
checkLike('not an action: in a block per method too', $mixLog, '#\[WARNING\] Route "split" of Mixed/routes.rte holds an unknown key: target\.pge#');
$mixReg = madeRoutr('/mixed/kept')->findRoute();
checkSame('not an action: its act sibling still answers', Array($mixReg->readValue('app'), $mixReg->readValue('action'), $mixReg->readValue('rteOwnr')), Array('Mixed', 'kept', 'Mixed'));
$mixReg = madeRoutr('/mixed/split', 'POST')->findRoute();
checkSame('block per method: the action of its other method answers', Array($mixReg->readValue('app'), $mixReg->readValue('action')), Array('Mixed', 'kept'));
$mixReg = madeRoutr('/mixed/split', 'GET')->findRoute();
checkSame('block per method: the method left out is not served', Array($mixReg->readValue('statCode'), $mixReg->readValue('allowed')), Array(405, 'POST'));
checkSame('not an action: none of it is listed', array_column(array_filter(madeRoutr('/')->allRoutes(), function( Array $routeEntry ): Bool { return $routeEntry['rteOwnr']=='Mixed'; }), 'rtePath'), Array('mixed/kept', 'mixed/split'));

// A site pattern over a Run route: the site answers, with a warning
$siteDir = 'temp'.DS.'runroutes_'.getmypid();
@mkdir(TOP_DIR.DS.$siteDir, 0775, true);
file_put_contents(TOP_DIR.DS.$siteDir.DS.'covers.rte', '{ "auth/*": { "target": { "url": "/elsewhere" } } }');
Logger::plugSetup( Array('path'=>inTempDir('patlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
$patReg = madeRoutr('/auth/login', 'GET', Array('folder'=>$siteDir))->findRoute();
Logger::plugSetup( Array() );
dropFoldr(TOP_DIR.DS.$siteDir);
checkSame('site pattern: the site answers', Array($patReg->readValue('rteKind'), $patReg->readValue('rteOwnr'), $patReg->readValue('rtePath')), Array('redrct', 'site', 'auth/*'));
$patLog = implode("\n", file_exists(inTempDir('patlogs').DS.'runner.log') ? file(inTempDir('patlogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array());
checkLike('site pattern: the Auth routes under it are warned', $patLog, '#\[WARNING\] Route "/auth/login" of the application Auth falls under the site route "/auth/\*": the site\'s answers#');
checkSame('site pattern: a route from the root is not under it', strpos($patLog, '"/.well-known/jwks.json"'), false);

// deploy check: the route shadow row, and the mounts a preset may give
$mntText = function( String $presetName, String $mountsJson ): String {
	return '{ "label": "'.$presetName.'", "setup": { "ftypes": "all" }, "auths": { "needed": false, "public": true }, "route": { "detect": "autopath", "mapper": "$app/$action/$param", "folder": "conf/routes/'.$presetName.'", "mounts": '.$mountsJson.' } }';
};
$projDir = makeProjt('runroutes', Array(
	'conf/system.ini'=>sampleIni(),
	'conf/presets/covers.rps'=>$mntText('covers', '{}'),
	'conf/routes/covers/site.rte'=>'{ "auth/*": { "target": { "url": "/elsewhere" } } }',
	'conf/presets/mntyes.rps'=>$mntText('mntyes', '{ "auth": true }'),
	'conf/presets/mntnum.rps'=>$mntText('mntnum', '{ "auth": 12 }'),
	'conf/presets/mntarr.rps'=>$mntText('mntarr', '{ "auth": ["account"] }'),
	'conf/presets/mntemp.rps'=>$mntText('mntemp', '{ "auth": "" }'),
	'runs/Auth/routes.rte'=>strval(file_get_contents(TOP_DIR.DS.'runs'.DS.'Auth'.DS.'routes.rte'))
));
$chkRows = array_column(jsonShell($projDir, Array('deploy', 'check'))['rows'] ?? Array(), null, 'check');
checkSame('route shadow: a site pattern over the Auth routes is a warning', $chkRows['route shadow']['state'] ?? null, 'warn');
checkLike('route shadow: naming the preset, the route and the pattern', strval($chkRows['route shadow']['found'] ?? ''), '#covers: /auth/login of Auth under /auth/\*#');
foreach( Array('mntyes'=>'true', 'mntnum'=>'12', 'mntarr'=>'\["account"\]') as $rpsName=>$badText ){
	checkSame('mounts: '.$rpsName.' does not load', $chkRows['preset '.$rpsName]['state'] ?? null, 'fail');
	checkLike('mounts: '.$rpsName.' says why, naming the Run', strval($chkRows['preset '.$rpsName]['found'] ?? ''), '#holds a mount that is not false or a path prefix for the application "auth": '.$badText.'#');
}
checkSame('mounts: "" loads, mounting at the root', $chkRows['preset mntemp']['state'] ?? null, 'fine');
$rootRtr = madeRoutr('/login', 'POST', Array('mounts'=>Array('auth'=>'')));
checkSame('mounts: "" serves the routes from the root', Array($rootRtr->findRoute()->readValue('app'), $rootRtr->findRoute()->readValue('rtePath')), Array('Auth', 'login'));

endChecks();
