<?php

// The presets served (Runner::pickPresetName, conf/doorways.lst): the line
// of the map a request takes, by its host and the start of its path; the
// basePath taken off the path before routing and put back in front of the
// links and redirections; no map, an empty one or no line: the default
// preset; a Runner given a preset never reads the map. Each request runs
// through the Runner in a child process (test/lib/cacheChild.php) on a project
// folder of its own; the Router is checked here, the sign-in route in AccessTest.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

use nyu\Tools\Router;

// Three presets telling which one answers: alpha gives a link (routLinkString), beta and default answer their own way
$prstSet = Array(
	'conf/system.ini'=>buildSystemIni("CNF_CACH = FALSE\n"),
	'conf/presets/alpha.rps'=>'{ "presetId": "alpha", "settings": { "confType": "all" }, "security": { "required": false, "isPublic": true }, "dispatch": { "detectBy": "manually", "routeDir": "conf/routes/alpha" } }',
	'conf/presets/beta.rps'=>'{ "presetId": "beta", "settings": { "confType": "all" }, "security": { "required": false, "isPublic": true }, "dispatch": { "detectBy": "manually", "routeDir": "conf/routes/beta" } }',
	'conf/presets/default.rps'=>'{ "presetId": "default", "settings": { "confType": "all" }, "security": { "required": false, "isPublic": true }, "dispatch": { "detectBy": "manually", "routeDir": "conf/routes/dflt" } }',
	'conf/routes/alpha/alpha.rte'=>'{ "where": { "pointsTo": { "appToRun": "probe", "doAction": "routeUrl" } }, "things/$id": { "pointsTo": { "appToRun": "probe", "doAction": "mirrored" } } }',
	'conf/routes/beta/beta.rte'=>'{ "where": { "pointsTo": { "appToRun": "probe", "doAction": "sanitize" } }, "apix/where": { "pointsTo": { "appToRun": "probe", "doAction": "sanitize" } } }',
	'conf/routes/dflt/dflt.rte'=>'{ "where": { "pointsTo": { "appToRun": "probe", "doAction": "txtProbe" } } }',
	// The Runner installs the error handler from the project it runs
	'src/Error/handling.php'=>strval(file_get_contents(TOP_DIR.DS.'src'.DS.'Error'.DS.'handling.php'))
);
$doorDir = makeProjectTxt('doorways', $prstSet + Array(
	'conf/doorways.lst'=>'{ "doorways": [ { "hostName": "API.example.org", "basePath": "/", "presetId": "beta" }, { "basePath": "/api", "presetId": "alpha" }, { "basePath": "/", "presetId": "beta" }, { "basePath": "/api/v2/", "presetId": "beta" } ] }'
));

/**
 * Runs one GET request through the Runner on a project folder.
 *
 * @param String $projectRoot  The project folder.
 * @param String $urlAddress   The path asked for.
 * @param String $hostAddress  The host the request names.
 * @param String $presetNamed  The preset the Runner is given; '' for the map to pick it.
 * @return String What the request answered.
 */
function askDoor( String $projectRoot, String $urlAddress, String $hostAddress='www.example.org', String $presetNamed='' ): String {
	return trim( runScriptArray(Array(TST_DIR.DS.'lib'.DS.'cacheChild.php', $projectRoot, $presetNamed, $urlAddress, $hostAddress))['text'] );
}

$alphaLk = '{"link":"\/api\/things\/7"}';
$betaRpl = '{"ok":true}';
$dfltRpl = '"plain text"';
$notFond = '{"error":"Not Found","code":404}';

checkIdentical('the longest basePath answers, the basePath taken off before routing and put back in front of the link', askDoor($doorDir, '/api/where?x=1'), $alphaLk);
checkIdentical('a longer basePath wins, written with or without its final slash', askDoor($doorDir, '/api/v2/where'), $betaRpl);
checkIdentical('a basePath matches whole parts of the path: /apix is not /api', askDoor($doorDir, '/apix/where'), $betaRpl);
checkIdentical('a line naming the host comes before a longer basePath, whatever the case and the port of the host', Array(askDoor($doorDir, '/api/where', 'api.example.org'), askDoor($doorDir, '/api/where', 'Api.Example.org:8443')), Array($notFond, $notFond));
checkIdentical('the basePath alone reaches the root of the preset', askDoor($doorDir, '/api'), $notFond);
checkIdentical('a Runner given a preset does not read the map: nothing is taken off the path', Array(askDoor($doorDir, '/where', 'www.example.org', 'alpha'), askDoor($doorDir, '/api/where', 'www.example.org', 'default')), Array('{"link":"\/things\/7"}', $notFond));

// No map, an empty one, no line matching : the default preset
$noneDir = makeProjectTxt('doornone', $prstSet);
$voidDir = makeProjectTxt('doorvoid', $prstSet + Array('conf/doorways.lst'=>'{ "doorways": [] }'));
$elseDir = makeProjectTxt('doorelse', $prstSet + Array('conf/doorways.lst'=>'{ "doorways": [ { "hostName": "other.example.org", "basePath": "/", "presetId": "beta" } ] }'));
checkIdentical('no map, an empty map, no line matching: the default preset, as before', Array(askDoor($noneDir, '/where'), askDoor($voidDir, '/where'), askDoor($elseDir, '/where')), Array($dfltRpl, $dfltRpl, $dfltRpl));

// A map that cannot be read never serves another preset
$jsonErr = '{"error":"Internal Server Error","code":500}';
$brknLst = Array();
foreach( Array('{ "doorways": ', '{ "doorways": [ { "basePath": "api", "presetId": "beta" } ] }', '{ "doorways": [ { "basePath": "/", "presetId": "beta", "unknown": 1 } ] }', '{ "doorways": [ { "basePath": "/" } ] }', '{ "doorways": [ { "basePath": "/", "presetId": "nosuchpreset" } ] }') as $mapText ){
	$brknDir = makeProjectTxt('doorbrkn', $prstSet + Array('conf/doorways.lst'=>$mapText));
	$brknLst[] = substr(askDoor($brknDir, '/where'), -strlen($jsonErr));
}
checkIdentical('a map that is not JSON, a line that is not whole, a preset that does not exist: a 500', $brknLst, array_fill(0, count($brknLst), $jsonErr));

// The Router : the basePath taken off, and put back in front of the links and the redirections it builds
/**
 * Builds a router on the fixture routes for a path, under a basePath.
 *
 * @param String $urlAddress  The path asked for.
 * @param String $basePathTxt The basePath of the line of the map chosen.
 * @return Router The router.
 */
function baseRoutr( String $urlAddress, String $basePathTxt ): Router {
	$request = mockRequestObj();
	(new ReflectionProperty($request, 'uriDetails'))->setValue($request, Array('fullPath'=>$urlAddress));
	(new ReflectionProperty($request, 'reqtInMthd'))->setValue($request, 'GET');
	return new Router( Array('detectBy'=>'manually', 'routeDir'=>'test'.DS.'fixtures'.DS.'routes', 'fileRoot'=>'test'.DS.'fixtures'.DS.'pages'), $request, $basePathTxt );
}
$pingReg = baseRoutr('/api/ping', '/api')->locateRouteReg();
checkIdentical('router: the route found once the basePath is taken off', Array($pingReg->readValueByKey('appToRun'), $pingReg->readValueByKey('doAction')), Array('probe', 'sanitize'));
checkIdentical('router: a path outside the basePath is not cut', baseRoutr('/apix/ping', '/api')->locateRouteReg()->readValueByKey('appToRun'), null);
checkIdentical('router: a redirection to a path of the site goes under the basePath, a full address is left as it is', Array(baseRoutr('/api/old/5', '/api')->locateRouteReg()->readValueByKey('routTarg'), baseRoutr('/api/away', '/api/')->locateRouteReg()->readValueByKey('routTarg')), Array('/api/items/5', 'https://example.org/docs'));
checkIdentical('router: a link goes under the basePath; a root basePath changes nothing', Array(baseRoutr('/api/ping', '/api')->buildRouteLink('items/$id', Array('id'=>7)), baseRoutr('/ping', '')->buildRouteLink('items/$id', Array('id'=>7)), baseRoutr('/ping', '/')->buildRouteLink('items/$id', Array('id'=>7))), Array('/api/items/7', '/items/7', '/items/7'));

finishChecking();
