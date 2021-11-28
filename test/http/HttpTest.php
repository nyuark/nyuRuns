<?php

// The whole framework over real HTTP : status codes, content types and
// bodies, served by PHP's built-in server through test/lib/httpRouter.php
// on the sample SQLite database.
//
// These requests go through the project's own configuration, so they write
// to the log file configured in conf/system.ini like any other request.

require __DIR__.'/../lib/harness.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('HTTP', 'pdo_sqlite is not available');
	endChecks();
}

/**
 * Sends one HTTP request to the test server.
 *
 * @param String $httpMethod HTTP method.
 * @param String $urlAddress Full URL.
 * @param Array  $headersArr Header name => value pairs to send.
 * @param String $bodyString Request body.
 * @return Array The response 'code', content 'type', 'body' and 'head' lines.
 */
function httpQuery( String $httpMethod, String $urlAddress, Array $headersArr=Array(), String $bodyString='' ): Array {
	$headTxt = '';
	foreach( $headersArr as $hedKey=>$hedVal ){
		$headTxt .= $hedKey.': '.$hedVal."\r\n";
	}
	$context = stream_context_create( Array('http'=>Array(
		'method'=>$httpMethod, 'header'=>$headTxt, 'content'=>$bodyString, 'ignore_errors'=>true, 'timeout'=>20
	)) );
	$bodyTxt = @file_get_contents($urlAddress, false, $context);
	$headLst = $http_response_header ?? Array();
	$outcome = Array('code'=>0, 'type'=>'', 'body'=>($bodyTxt===false ? '' : $bodyTxt), 'head'=>$headLst);
	foreach( $headLst as $hedLin ){
		if( preg_match('#^HTTP/\S+ (\d+)#', $hedLin, $codVals) ){
			$outcome['code'] = intval($codVals[1]);
		}elseif( stripos($hedLin, 'Content-Type:')===0 ){
			$outcome['type'] = trim(substr($hedLin, 13));
		}
	}
	return $outcome;
}

// Start the built-in server on a free local port
$portSck = stream_socket_server('tcp://127.0.0.1:0');
$portNum = intval(substr(strrchr(stream_socket_get_name($portSck, false), ':'), 1));
fclose($portSck);

putenv('NYU_TEST_DB='.inTempDir('http.sqlite'));
$nullDev = (DS=='\\') ? 'NUL' : '/dev/null';
$pipeArr = Array();
$server = proc_open(
	Array(PHP_BINARY, '-S', '127.0.0.1:'.$portNum, TST_DIR.DS.'lib'.DS.'httpRouter.php'),
	Array(0=>Array('file', $nullDev, 'r'), 1=>Array('file', inTempDir('server.out'), 'w'), 2=>Array('file', inTempDir('server.err'), 'w')),
	$pipeArr, WEB_DIR
);
$baseUrl = 'http://127.0.0.1:'.$portNum;
$isReady = false;
for( $idx=0; $idx<50 && !$isReady; $idx++ ){
	$tryLink = @fsockopen('127.0.0.1', $portNum, $errNmbr, $errText, 0.2);
	if( $tryLink ){
		fclose($tryLink);
		$isReady = true;
	}else{
		usleep(100000);
	}
}

if( !$isReady ){
	skipCheck('HTTP', 'the built-in PHP server did not start');
}else{
	$jsonTyp = 'application/json; charset=utf-8';

	// Successful responses
	$replyAr = httpQuery('GET', $baseUrl.'/shows/index');
	checkSame('index: status', $replyAr['code'], 200);
	checkSame('index: content type', $replyAr['type'], $jsonTyp);
	checkSame('index: titles', array_column(json_decode($replyAr['body'], true) ?? Array(), 'title'), Array('Alpha Night', 'Beta Evening', 'Alpha Matinee', 'Solo Gig', 'Empty Stage'));
	checkSame('index: the body starts with the JSON itself', substr($replyAr['body'], 0, 1), '[');

	$replyAr = httpQuery('GET', $baseUrl.'/shows/index?filters[search]=Beta*');
	checkSame('index with one match', array_column(json_decode($replyAr['body'], true) ?? Array(), 'title'), Array('Beta Evening'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/index?filters[search]=Zzz*');
	checkSame('index with no match: 204 is sent as 200 with the empty list', Array($replyAr['code'], $replyAr['body']), Array(200, '[]'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1');
	checkSame('view: status', $replyAr['code'], 200);
	checkSame('view: record', json_decode($replyAr['body'], true)['title'] ?? null, 'Alpha Night');

	// Statuses set by actions
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/99');
	checkSame('missing show: 404 with an error body', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(404, $jsonTyp, '{"error":"Show not found","code":404}'));

	$replyAr = httpQuery('GET', $baseUrl.'/auth/login');
	checkSame('login without credentials: 400', Array($replyAr['code'], $replyAr['body']), Array(400, '{"error":"Missing user or pass","code":400}'));

	$replyAr = httpQuery('GET', $baseUrl.'/auth/login?user=nobody&pass=wrong');
	checkSame('login with wrong credentials: 401', Array($replyAr['code'], json_decode($replyAr['body'], true)['code'] ?? null), Array(401, 401));

	// Routing failures
	$replyAr = httpQuery('GET', $baseUrl.'/auth/nope');
	checkSame('unknown action: 500', Array($replyAr['code'], $replyAr['body']), Array(500, '{"error":"Unknown Action","code":500}'));

	// Default action : the one the application names in $dfltActn, otherwise its first declared action
	$replyAr = httpQuery('GET', $baseUrl.'/shows');
	checkSame('no action: the declared default (index) runs', Array($replyAr['code'], count(json_decode($replyAr['body'], true) ?? Array())), Array(200, 5));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/');
	checkSame('no action, trailing slash', Array($replyAr['code'], count(json_decode($replyAr['body'], true) ?? Array())), Array(200, 5));

	$replyAr = httpQuery('GET', $baseUrl.'/shows?filters[search]=Beta*');
	checkSame('no action, with a query string', array_column(json_decode($replyAr['body'], true) ?? Array(), 'title'), Array('Beta Evening'));

	$replyAr = httpQuery('GET', $baseUrl.'/auth');
	checkSame('no action, no declared default: the first action (login) runs', Array($replyAr['code'], $replyAr['body']), Array(400, '{"error":"Missing user or pass","code":400}'));

	$replyAr = httpQuery('GET', $baseUrl.'/nothing/here');
	checkSame('unknown application: 404', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Unknown Application","code":404}'));

	// Uncaught exception (view called without its parameter)
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view');
	$errVals = json_decode($replyAr['body'], true);
	checkSame('uncaught exception: 500 in the configured format', Array($replyAr['code'], $replyAr['type'], $errVals['code'] ?? null), Array(500, $jsonTyp, 500));
	checkTrue('uncaught exception: carries an error message', is_string($errVals['error'] ?? null) && $errVals['error']!='');

	// Access control
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'Header'));
	checkSame('access denied: 401 with an error body', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Missing X-Api-Key header","code":401}'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'Basic'));
	checkSame('Basic access denied: 401', $replyAr['code'], 401);
	checkTrue('Basic access denied: challenge header', in_array('WWW-Authenticate: Basic', $replyAr['head']));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'Bogus'));
	checkSame('misconfigured access: 500', Array($replyAr['code'], $replyAr['body']), Array(500, '{"error":"Access misconfigured","code":500}'));

	// Content types
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Return'=>'php'));
	checkSame('other output format: text/plain', $replyAr['type'], 'text/plain; charset=utf-8');
	checkSame('other output format: serialized body', unserialize($replyAr['body'])['title'] ?? null, 'Alpha Night');

	foreach( Array('excel'=>'application/vnd.ms-excel', 'mswrd'=>'application/msword', 'optab'=>'application/vnd.oasis.opendocument.spreadsheet') as $tplNme=>$tplTyp ){
		$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Tmpl'=>$tplNme));
		checkSame($tplNme.' template: content type', $replyAr['type'], $tplTyp);
		checkSame($tplNme.' template: the document starts at its first byte', substr($replyAr['body'], 0, 5), '<html');
	}

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Tmpl'=>'nosuchtemplate'));
	checkSame('unknown template: the record is still sent', Array($replyAr['code'], $replyAr['type'], json_decode($replyAr['body'], true)['title'] ?? null), Array(200, $jsonTyp, 'Alpha Night'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/99', Array('X-Test-Tmpl'=>'nosuchtemplate'));
	checkSame('unknown template: an error status keeps its body', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Show not found","code":404}'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Ctype'=>'application/problem+json'));
	checkSame('a Content-Type set by the preset is kept', $replyAr['type'], 'application/problem+json');

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Ctype'=>'text/csv', 'X-Test-Ctname'=>'content-type'));
	checkLike('a lower-case preset header is not doubled', $replyAr['type'], '#^text/csv#');

	// Request bodies : "Invalid credentials"-style 401 means user and pass were read from the body, 400 that they were not
	$multiBd = "------TestBoundary123\r\nContent-Disposition: form-data; name=\"user\"\r\n\r\nnobody\r\n------TestBoundary123\r\nContent-Disposition: form-data; name=\"pass\"\r\n\r\nwrong\r\n------TestBoundary123--\r\n";
	$bodyLst = Array(
		Array('PUT',   'application/x-www-form-urlencoded', 'user=nobody&pass=wrong', 401),
		Array('PATCH', 'application/x-www-form-urlencoded', 'user=nobody&pass=wrong', 401),
		Array('POST',  'application/x-www-form-urlencoded', 'user=nobody&pass=wrong', 401),
		Array('PUT',   'multipart/form-data; boundary=----TestBoundary123', $multiBd, 401),
		Array('PUT',   'application/json', '{"user":"nobody","pass":"wrong"}', 401),
		Array('PUT',   'text/plain', "user=nobody\npass=wrong", 400),
	);
	foreach( $bodyLst as $oneBdy ){
		$replyAr = httpQuery($oneBdy[0], $baseUrl.'/auth/login', Array('Content-Type'=>$oneBdy[1]), $oneBdy[2]);
		checkSame($oneBdy[0].' '.strtok($oneBdy[1], ';').' body', $replyAr['code'], $oneBdy[3]);
	}

	// Header names are case-insensitive : the body is read whatever the case of Content-Type
	$replyAr = httpQuery('PUT', $baseUrl.'/auth/login', Array('content-type'=>'application/json'), '{"user":"nobody","pass":"wrong"}');
	checkSame('PUT body with a lower-case content-type header', $replyAr['code'], 401);

	// Misbehaving actions, from the fixture applications in test/fixtures/runs.
	// What a client sees of a warning depends on ERR_SHOW in conf/system.ini.
	$fixHead = Array('X-Test-Runs'=>'1');
	$sysConf = @parse_ini_file(TOP_DIR.DS.'conf'.DS.'system.ini');
	$errShow = !empty($sysConf['ERR_SHOW']);
	$warnPtn = '/^E_USER_WARNING: probe warning in .*Actions\.php on line \d+$/';

	$replyAr = httpQuery('GET', $baseUrl.'/probe/clean', $fixHead);
	checkSame('fixture: clean action', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe', $fixHead);
	checkSame('fixture: first declared action is the default', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));

	$replyAr = httpQuery('GET', $baseUrl.'/lost', $fixHead);
	checkSame('fixture: a default action that does not exist is 500', Array($replyAr['code'], $replyAr['body']), Array(500, '{"error":"Unknown Action","code":500}'));
	$replyAr = httpQuery('GET', $baseUrl.'/lost/here', $fixHead);
	checkSame('fixture: its real action still answers', Array($replyAr['code'], $replyAr['body']), Array(200, '{"found":true}'));

	// Declared routes (test/fixtures/routes) : checked before autopath, exact keys then patterns
	$rteHead = Array('X-Test-Runs'=>'1', 'X-Test-Routes'=>'1');
	$noAppli = Array(404, '{"error":"Unknown Application","code":404}');

	$replyAr = httpQuery('GET', $baseUrl.'/ping', $rteHead);
	checkSame('route: exact key', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));
	$replyAr = httpQuery('GET', $baseUrl.'/ping', $fixHead);
	checkSame('route: not declared outside its routes folder', Array($replyAr['code'], $replyAr['body']), $noAppli);

	$replyAr = httpQuery('GET', $baseUrl.'/items/42', $rteHead);
	checkSame('route: a captured value is the action parameter', Array($replyAr['code'], $replyAr['body']), Array(200, '{"got":"42"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/items/42/', $rteHead);
	checkSame('route: trailing slash is ignored', Array($replyAr['code'], $replyAr['body']), Array(200, '{"got":"42"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/items/a%20b', $rteHead);
	checkSame('route: a captured value is URL-decoded', Array($replyAr['code'], $replyAr['body']), Array(200, '{"got":"a b"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/items/42/parts/7', $rteHead);
	checkSame('route: the first captured value is the parameter', Array($replyAr['code'], $replyAr['body']), Array(200, '{"got":"42"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/items/42/extra', $rteHead);
	checkSame('route: a longer path does not match the pattern', Array($replyAr['code'], $replyAr['body']), $noAppli);

	$replyAr = httpQuery('GET', $baseUrl.'/probe/warnfail', $rteHead);
	checkSame('route: a declared route wins over autopath', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/clean', $rteHead);
	checkSame('route: autopath is the fallback', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));
	$replyAr = httpQuery('GET', $baseUrl.'/broken', $rteHead);
	checkSame('route: an entry without application is skipped', Array($replyAr['code'], $replyAr['body']), $noAppli);

	// Methods of declared routes : GET for a general target, unless 'allows' lists others
	$allowOf = function( Array $replyArray ): ?String {
		foreach( $replyArray['head'] as $hedLin ){
			if( stripos($hedLin, 'Allow:')===0 ){
				return trim(substr($hedLin, 6));
			}
		}
		return null;
	};
	$refused = '{"error":"Method Not Allowed","code":405}';

	$replyAr = httpQuery('POST', $baseUrl.'/ping', $rteHead);
	checkSame('method: a general target only accepts GET', Array($replyAr['code'], $replyAr['body'], $allowOf($replyAr)), Array(405, $refused, 'GET'));

	$replyAr = httpQuery('GET', $baseUrl.'/gets', $rteHead);
	checkSame('method: GET stays accepted when allows adds HEAD and OPTIONS', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));
	$replyAr = httpQuery('HEAD', $baseUrl.'/gets', $rteHead);
	checkSame('method: an allowed HEAD is served by the GET target, without body', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(200, $jsonTyp, ''));
	$replyAr = httpQuery('OPTIONS', $baseUrl.'/gets', $rteHead);
	checkSame('method: an allowed OPTIONS answers with the accepted methods', Array($replyAr['code'], $replyAr['body'], $allowOf($replyAr)), Array(200, '[]', 'GET, HEAD, OPTIONS'));
	$replyAr = httpQuery('POST', $baseUrl.'/gets', $rteHead);
	checkSame('method: refused with the full accepted list', Array($replyAr['code'], $replyAr['body'], $allowOf($replyAr)), Array(405, $refused, 'GET, HEAD, OPTIONS'));
	$replyAr = httpQuery('HEAD', $baseUrl.'/ping', $rteHead);
	checkSame('method: HEAD is refused unless allowed', Array($replyAr['code'], $allowOf($replyAr)), Array(405, 'GET'));

	$replyAr = httpQuery('POST', $baseUrl.'/both', $rteHead);
	checkSame('method: allows opens a general target to other methods', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));
	$replyAr = httpQuery('PUT', $baseUrl.'/both', $rteHead);
	checkSame('method: and to those only', Array($replyAr['code'], $allowOf($replyAr)), Array(405, 'GET, POST'));

	$replyAr = httpQuery('GET', $baseUrl.'/things/7', $rteHead);
	checkSame('method: a target keyed by method, GET', Array($replyAr['code'], $replyAr['body']), Array(200, '{"got":"7"}'));
	$replyAr = httpQuery('DELETE', $baseUrl.'/things/7', $rteHead);
	checkSame('method: a target keyed by method, DELETE (key case is ignored)', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));
	$replyAr = httpQuery('POST', $baseUrl.'/things/7', $rteHead);
	checkSame('method: a method without target is refused', Array($replyAr['code'], $replyAr['body'], $allowOf($replyAr)), Array(405, $refused, 'GET, DELETE'));

	$replyAr = httpQuery('POST', $baseUrl.'/probe/clean', $rteHead);
	checkSame('method: autopath accepts any method', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));

	// The project's own routes (conf/routes) : index is a general target
	$replyAr = httpQuery('POST', $baseUrl.'/');
	checkSame('method: POST on the index route is refused', Array($replyAr['code'], $allowOf($replyAr)), Array(405, 'GET'));

	// A failure before the error handler is installed : plain JSON 500, without details
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Preset'=>'nosuchpreset'));
	checkSame('missing preset: generic JSON 500', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(500, $jsonTyp, '{"error":"Internal Server Error","code":500}'));

	$replyAr = httpQuery('GET', $baseUrl.'/blank/here', $fixHead);
	checkSame('fixture: an Actions.php without its actionset is 404', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Unknown Application","code":404}'));

	// Only the application's own public methods are actions
	foreach( Array('/probe/hidden', '/probe/fixed', '/probe/__construct', '/probe/checkList', '/probe/runAction/clean', '/probe/ownAction/clean') as $notAct ){
		$replyAr = httpQuery('GET', $baseUrl.$notAct, $fixHead);
		checkSame('fixture: '.$notAct.' is not an action', Array($replyAr['code'], $replyAr['body']), Array(500, '{"error":"Unknown Action","code":500}'));
	}
	$replyAr = httpQuery('GET', $baseUrl.'/probe/CLEAN', $fixHead);
	checkSame('fixture: action names are not case-sensitive', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/warn', $fixHead);
	$bodyArr = json_decode($replyAr['body'], true);
	checkSame('warning: status and type are untouched', Array($replyAr['code'], $replyAr['type']), Array(200, $jsonTyp));
	if( $errShow ){
		checkSame('warning: output is wrapped under data', $bodyArr['data'] ?? null, Array(1, 2, 3));
		checkSame('warning: both warnings are listed', count($bodyArr['warnings'] ?? Array()), 2);
		checkLike('warning: text of the first one', $bodyArr['warnings'][0] ?? null, $warnPtn);
	}else{
		checkSame('warning: not shown when ERR_SHOW is off', $bodyArr, Array(1, 2, 3));
	}

	$replyAr = httpQuery('GET', $baseUrl.'/probe/warnfail', $fixHead);
	$bodyArr = json_decode($replyAr['body'], true);
	checkSame('warning + error status: status and message', Array($replyAr['code'], $bodyArr['error'] ?? null, $bodyArr['code'] ?? null), Array(404, 'Nothing here', 404));
	checkSame('warning + error status: warnings beside the error', count($bodyArr['warnings'] ?? Array()), $errShow ? 1 : 0);

	$replyAr = httpQuery('GET', $baseUrl.'/probe/boom', $fixHead);
	$bodyArr = json_decode($replyAr['body'], true);
	checkSame('warning + exception: 500 in the configured format', Array($replyAr['code'], $replyAr['type'], $bodyArr['code'] ?? null), Array(500, $jsonTyp, 500));
	if( $errShow ){
		checkLike('warning + exception: exception message', $bodyArr['error'] ?? null, '/^RuntimeException \(code 7\): probe failure in .*Actions\.php on line \d+$/');
		checkLike('warning + exception: the earlier warning is kept', $bodyArr['warnings'][0] ?? null, $warnPtn);
	}else{
		checkSame('warning + exception: generic message when ERR_SHOW is off', $bodyArr, Array('error'=>'Internal Server Error', 'code'=>500));
	}

	$replyAr = httpQuery('GET', $baseUrl.'/probe/fatal', $fixHead);
	$bodyArr = json_decode($replyAr['body'], true);
	checkSame('fatal error: 500 in the configured format', Array($replyAr['code'], $replyAr['type'], $bodyArr['code'] ?? null), Array(500, $jsonTyp, 500));
	checkLike('fatal error: message', $bodyArr['error'] ?? null, $errShow ? '/^Allowed memory size of \d+ bytes exhausted/' : '/^Internal Server Error$/');
}

proc_terminate($server);
proc_close($server);

endChecks();
