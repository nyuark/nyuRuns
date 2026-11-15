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
	$request = mockRequestObj();
	foreach( Array('reqtInMthd'=>$httpMethod, 'uriDetails'=>Array('fullPath'=>$urlAddress)) as $prpNme=>$prpVal ){
		(new ReflectionProperty($request, $prpNme))->setValue($request, $prpVal);
	}
	return new Router( $routeConfg + Array('detectBy'=>'autopath', 'pathForm'=>'$appToRun/$doAction/$argument', 'routeDir'=>'test'.DS.'nosuchfolder'), $request );
}

@mkdir(RUN_DIR.DS.'Mixed', 0775, true);
@mkdir(RUN_DIR.DS.'Auth', 0775, true);
file_put_contents(RUN_DIR.DS.'Mixed'.DS.'routes.rte', '{ "away": { "pointsTo": { "redirect": "/elsewhere" } }, "kept": { "pointsTo": { "doAction": "kept" } },'
	.' "split": { "pointsTo": { "GET": { "pageFile": "hello.html" }, "POST": { "doAction": "kept" } } } }');
copy(TOP_DIR.DS.'runs'.DS.'Auth'.DS.'routes.rte', RUN_DIR.DS.'Auth'.DS.'routes.rte');
@mkdir(RUN_DIR.DS.'Users', 0775, true);
copy(TOP_DIR.DS.'runs'.DS.'Users'.DS.'routes.rte', RUN_DIR.DS.'Users'.DS.'routes.rte');
$keyList = Array('appToRun', 'doAction', 'statCode', 'routOwnr');

// A Run serves its own actions only: a target naming anything else is warned and left out
Logger::loadLogsConfig( Array('path'=>inTemporaryDir('mixlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
$mixReg = madeRoutr('/mixed/away')->findRouteOfRqt();
Logger::loadLogsConfig( Array() );
checkIdentical('not an action: the route is not served, a 404 of the closed Run', Array($mixReg->readValueByKey('appToRun'), $mixReg->readValueByKey('statCode')), Array(null, 404));
$mixLog = implode("\n", file_exists(inTemporaryDir('mixlogs').DS.'runner.log') ? file(inTemporaryDir('mixlogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array());
checkPregMatch('not an action: its key is warned on runner', $mixLog, '#\[WARNING\] Route "away" of Mixed/routes.rte holds an unknown key: pointsTo\.redirect#');
checkPregMatch('not an action: in a block per method too', $mixLog, '#\[WARNING\] Route "split" of Mixed/routes.rte holds an unknown key: pointsTo\.pageFile#');
$mixReg = madeRoutr('/mixed/kept')->findRouteOfRqt();
checkIdentical('not an action: its act sibling still answers', Array($mixReg->readValueByKey('appToRun'), $mixReg->readValueByKey('doAction'), $mixReg->readValueByKey('routOwnr')), Array('Mixed', 'kept', 'Mixed'));
$mixReg = madeRoutr('/mixed/split', 'POST')->findRouteOfRqt();
checkIdentical('block per method: the action of its other method answers', Array($mixReg->readValueByKey('appToRun'), $mixReg->readValueByKey('doAction')), Array('Mixed', 'kept'));
$mixReg = madeRoutr('/mixed/split', 'GET')->findRouteOfRqt();
checkIdentical('block per method: the method left out is not served', Array($mixReg->readValueByKey('statCode'), $mixReg->readValueByKey('allowHdr')), Array(405, 'POST'));
checkIdentical('not an action: none of it is listed', array_column(array_filter(madeRoutr('/')->listAllTargets(), function( Array $routeEntry ): Bool { return $routeEntry['routOwnr']=='Mixed'; }), 'routPath'), Array('mixed/kept', 'mixed/split'));

// A site pattern over a Run route: the site answers, with a warning
$siteDir = 'temp'.DS.'runroutes_'.getmypid();
@mkdir(TOP_DIR.DS.$siteDir, 0775, true);
file_put_contents(TOP_DIR.DS.$siteDir.DS.'covers.rte', '{ "auth/*": { "pointsTo": { "redirect": "/elsewhere" } } }');
Logger::loadLogsConfig( Array('path'=>inTemporaryDir('patlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
$patReg = madeRoutr('/auth/login', 'GET', Array('routeDir'=>$siteDir))->findRouteOfRqt();
Logger::loadLogsConfig( Array() );
dropTestFolder(TOP_DIR.DS.$siteDir);
checkIdentical('site pattern: the site answers', Array($patReg->readValueByKey('routKind'), $patReg->readValueByKey('routOwnr'), $patReg->readValueByKey('routPath')), Array('redirect', 'site', 'auth/*'));
$patLog = implode("\n", file_exists(inTemporaryDir('patlogs').DS.'runner.log') ? file(inTemporaryDir('patlogs').DS.'runner.log', FILE_IGNORE_NEW_LINES) : Array());
checkPregMatch('site pattern: the Auth routes under it are warned', $patLog, '#\[WARNING\] Route "/auth/login" of the application Auth falls under the site route "/auth/\*": the site\'s answers#');
checkIdentical('site pattern: a route from the root is not under it', strpos($patLog, '"/.well-known/jwks.json"'), false);

// install checkAll: the route shadow row, and the mounts a preset may give
$mntText = function( String $presetName, String $mountsJson ): String {
	return '{ "presetId": "'.$presetName.'", "settings": { "confType": "all" }, "security": { "required": false, "isPublic": true }, "dispatch": { "detectBy": "autopath", "pathForm": "$appToRun/$doAction/$argument", "routeDir": "conf/routes/'.$presetName.'", "runMount": '.$mountsJson.' } }';
};
$projDir = makeProjectTxt('runroutes', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/presets/covers.rps'=>$mntText('covers', '{}'),
	'conf/routes/covers/site.rte'=>'{ "auth/*": { "pointsTo": { "redirect": "/elsewhere" } } }',
	'conf/presets/mntyes.rps'=>$mntText('mntyes', '{ "auth": true }'),
	'conf/presets/mntnum.rps'=>$mntText('mntnum', '{ "auth": 12 }'),
	'conf/presets/mntarr.rps'=>$mntText('mntarr', '{ "auth": ["account"] }'),
	'conf/presets/mntemp.rps'=>$mntText('mntemp', '{ "auth": "" }'),
	'conf/presets/mnttxt.rps'=>$mntText('mnttxt', '"auth"'),
	'conf/presets/mntlst.rps'=>$mntText('mntlst', '["auth"]'),
	'runs/Auth/routes.rte'=>strval(file_get_contents(TOP_DIR.DS.'runs'.DS.'Auth'.DS.'routes.rte'))
));
$chkRows = array_column(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), null, 'check');
checkIdentical('route shadow: a site pattern over the Auth routes is a warning', $chkRows['route shadow']['state'] ?? null, 'warn');
checkPregMatch('route shadow: naming the preset, the route and the pattern', strval($chkRows['route shadow']['found'] ?? ''), '#covers: /auth/login of Auth under /auth/\*#');
foreach( Array('mntyes'=>'true', 'mntnum'=>'12', 'mntarr'=>'\["account"\]') as $rpsName=>$badText ){
	checkIdentical('mounts: '.$rpsName.' does not load', $chkRows['preset '.$rpsName]['state'] ?? null, 'fail');
	checkPregMatch('mounts: '.$rpsName.' says why, naming the Run', strval($chkRows['preset '.$rpsName]['found'] ?? ''), '#holds a mount that is not false or a path prefix for the application "auth": '.$badText.'#');
}
foreach( Array('mnttxt'=>'"auth"', 'mntlst'=>'\["auth"\]') as $rpsName=>$badText ){
	checkIdentical('mounts: '.$rpsName.', not an object, does not load', $chkRows['preset '.$rpsName]['state'] ?? null, 'fail');
	checkPregMatch('mounts: '.$rpsName.' says why', strval($chkRows['preset '.$rpsName]['found'] ?? ''), '#holds mounts that are not an object of applications: '.$badText.'#');
}
checkIdentical('mounts: "" loads, mounting at the root', $chkRows['preset mntemp']['state'] ?? null, 'fine');
$rootRtr = madeRoutr('/login', 'POST', Array('runMount'=>Array('auth'=>'')));
checkIdentical('mounts: "" serves the routes from the root', Array($rootRtr->findRouteOfRqt()->readValueByKey('appToRun'), $rootRtr->findRouteOfRqt()->readValueByKey('routPath')), Array('Auth', 'login'));

// The Users routes: the user in the path, given to the action as its option; the method chooses the action, another one is a 405
$userRte = function( String $urlAddress, String $httpMethod ): Array {
	$rteReg = madeRoutr($urlAddress, $httpMethod)->findRouteOfRqt();
	return Array($rteReg->readValueByKey('doAction'), $rteReg->readValueByKey('argument'), intval($rteReg->getValueOrDflt('statCode', 200)));
};
checkIdentical('users: the list and the creation', Array($userRte('/users', 'GET'), $userRte('/users', 'POST'), $userRte('/users', 'PUT')[2]), Array(Array('index', null, 200), Array('create', null, 200), 405));
checkIdentical('users: one user, seen or removed', Array($userRte('/users/ben', 'GET'), $userRte('/users/ben', 'DELETE'), $userRte('/users/ben', 'POST')[2]), Array(Array('view', 'ben', 200), Array('remove', 'ben', 200), 405));
checkIdentical('users: role, address, language by PUT', Array($userRte('/users/ben/role', 'PUT'), $userRte('/users/ben/email', 'PUT'), $userRte('/users/ben/langs', 'PUT'), $userRte('/users/ben/role', 'GET')[2]), Array(Array('role', 'ben', 200), Array('setmail', 'ben', 200), Array('langs', 'ben', 200), 405));
checkIdentical('users: locked by PUT, unlocked by DELETE', Array($userRte('/users/ben/lock', 'PUT'), $userRte('/users/ben/lock', 'DELETE'), $userRte('/users/ben/lock', 'POST')[2]), Array(Array('lock', 'ben', 200), Array('unlock', 'ben', 200), 405));
checkIdentical('users: reset and approved by POST', Array($userRte('/users/ben/reset', 'POST'), $userRte('/users/ben/approve', 'POST'), $userRte('/users/ben/reset', 'GET')[2]), Array(Array('reset', 'ben', 200), Array('approve', 'ben', 200), 405));
checkIdentical('users: an action its routes answer is not reached from its path', $userRte('/users/view/ben', 'GET')[2], 404);

checkIdentical('auth: the route alone sets the method, every action POST, keyset GET', Array(array_map(function( String $actnNme ): Int { return intval(madeRoutr('/auth/'.$actnNme, 'GET')->findRouteOfRqt()->getValueOrDflt('statCode', 200)); }, Array('login', 'logout', 'active', 'signup', 'forgot', 'recover', 'setpass', 'usrlang')), intval(madeRoutr('/.well-known/jwks.json', 'POST')->findRouteOfRqt()->getValueOrDflt('statCode', 200))), Array(array_fill(0, 8, 405), 405));
finishChecking();
