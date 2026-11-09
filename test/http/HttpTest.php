<?php

// The whole framework over real HTTP : status codes, content types and
// bodies, served by PHP's built-in server through test/lib/httpRouter.php
// on the sample SQLite database.
//
// These requests go through the project's own configuration, so they write
// to the log files of the folder configured in conf/system.ini like any other request.
//
// The same checks can be run through another web server, such as Apache,
// that serves test/lib/httpRouter.php for every address: NYU_TEST_HTTP_URL
// then gives its address, and NYU_TEST_DB the sample SQLite file that
// server was told to use. The file is created afresh by the test.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

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
		'method'=>$httpMethod, 'header'=>$headTxt, 'content'=>$bodyString, 'ignore_errors'=>true, 'timeout'=>20, 'follow_location'=>0
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

/**
 * Reads the log file of a channel, in the folder conf/system.ini names
 * from the project root (see Setup\Loader::logConfig). The fixture
 * configurations name the same one.
 *
 * @param String $loggerChan Channel name: 'error', 'access', 'runner' or an application.
 * @return String The contents of the file, empty when there is none.
 */
function readsLogs( String $loggerChan ): String {
	$sysConf = @parse_ini_file(TOP_DIR.DS.'conf'.DS.'system.ini') ?: Array();
	$fldName = trim( strval($sysConf['LOG_PATH'] ?? ''), '/\\ ' );
	clearstatcache();
	return strval( @file_get_contents(TOP_DIR.DS.($fldName!='' ? $fldName : 'temp'.DS.'logs').DS.$loggerChan.'.log') );
}

/**
 * Removes the counters of the local addresses kept under the project's own
 * configuration (see Access\Limiter): the requests that go through it
 * present wrong credentials, which would add up from one run to the next.
 */
function dropLocal(): Void {
	$axsConf = json_decode( strval(@file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json')), true );
	$limiter = new nyu\Access\Limiter( is_array($axsConf['limits'] ?? null) ? $axsConf['limits'] : Array() );
	$keyMthd = new ReflectionMethod($limiter, 'keyOfAddr');
	$pthMthd = new ReflectionMethod($limiter, 'pathOfKey');
	foreach( Array('127.0.0.1', '::1') as $adr ){
		$cntPath = $pthMthd->invoke($limiter, $keyMthd->invoke($limiter, $adr));
		if( is_file($cntPath) ){
			@unlink($cntPath);
		}
	}
}
dropLocal();

$givnUrl = rtrim(strval(getenv('NYU_TEST_HTTP_URL') ?: ''), '/');
$server = null;
$whyNot = '';
if( $givnUrl!='' ){
	// A server that is already running: it reads the sample database named by NYU_TEST_DB
	$baseUrl = $givnUrl;
	$sqlFile = strval(getenv('NYU_TEST_DB') ?: '');
	$urlPart = parse_url($baseUrl) ?: Array();
	$tryLink = ($sqlFile!='' && isset($urlPart['host'])) ? @fsockopen($urlPart['host'], $urlPart['port'] ?? 80, $errNmbr, $errText, 2) : false;
	if( $sqlFile=='' ){
		$whyNot = 'NYU_TEST_HTTP_URL is set without NYU_TEST_DB';
	}elseif( !$tryLink ){
		$whyNot = 'no server answers on '.$baseUrl;
	}else{
		fclose($tryLink);
		if( !is_dir(dirname($sqlFile)) ){
			mkdir(dirname($sqlFile), 0777, true);
		}
		buildData($sqlFile);
	}
	$isReady = ($whyNot=='');
}else{
	// Start the built-in server on a free local port
	$portSck = stream_socket_server('tcp://127.0.0.1:0');
	$portNum = intval(substr(strrchr(stream_socket_get_name($portSck, false), ':'), 1));
	fclose($portSck);

	$sqlFile = inTempDir('http.sqlite');
	putenv('NYU_TEST_DB='.$sqlFile);
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
	$whyNot = 'the built-in PHP server did not start';
}

if( !$isReady ){
	skipCheck('HTTP', $whyNot);
}else{
	$jsonTyp = 'application/json; charset=utf-8';

	// Successful responses
	$replyAr = httpQuery('GET', $baseUrl.'/');
	checkSame('index: status', $replyAr['code'], 200);
	checkSame('index: content type', $replyAr['type'], $jsonTyp);
	checkSame('index: titles', array_column(json_decode($replyAr['body'], true) ?? Array(), 'title'), Array('Alpha Night', 'Beta Evening', 'Alpha Matinee', 'Solo Gig', 'Empty Stage'));
	checkSame('index: the body starts with the JSON itself', substr($replyAr['body'], 0, 1), '[');

	$replyAr = httpQuery('GET', $baseUrl.'/?filters[search]=Beta*');
	checkSame('index with one match', array_column(json_decode($replyAr['body'], true) ?? Array(), 'title'), Array('Beta Evening'));

	$replyAr = httpQuery('GET', $baseUrl.'/?filters[search]=Zzz*');
	checkSame('index with no match: 204 is sent as 200 with the empty list', Array($replyAr['code'], $replyAr['body']), Array(200, '[]'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1');
	checkSame('view: status', $replyAr['code'], 200);
	checkSame('view: record', json_decode($replyAr['body'], true)['title'] ?? null, 'Alpha Night');

	// Statuses set by actions
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/99');
	checkSame('missing show: 404 with an error body', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(404, $jsonTyp, '{"error":"Show not found","code":404}'));

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login');
	checkSame('login without credentials: 400', Array($replyAr['code'], $replyAr['body']), Array(400, '{"error":"Missing or invalid input: user, pass","code":400}'));

	$formHed = Array('Content-Type'=>'application/x-www-form-urlencoded');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed, 'user[]=nobody&pass=x');
	checkSame('login with a user that is not a string: 400', $replyAr['code'], 400);

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed, 'user=nobody-here&pass=wrong');
	checkSame('login with unknown credentials: 401', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', Array('Content-Type'=>'application/json'), '{"user":"nobody-here","pass":"wrong"}');
	checkSame('login with a JSON body: 401', $replyAr['code'], 401);

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login?pass=wrong', $formHed, 'user=nobody-here');
	checkSame('login with the password in the query string: 400', Array($replyAr['code'], $replyAr['body']), Array(400, '{"error":"Password sent in the query string","code":400}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login?PASS=wrong', $formHed, 'user=nobody-here&pass=wrong');
	checkSame('login with the password in both: 400 too', $replyAr['code'], 400);
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login?user=nobody-here', $formHed, 'pass=wrong');
	checkSame('login with the name alone in the query string: checked as usual', $replyAr['code'], 401);

	$replyAr = httpQuery('GET', $baseUrl.'/auth/login?user=nobody-here&pass=wrong');
	checkSame('login by GET: 405', Array($replyAr['code'], $replyAr['body']), Array(405, '{"error":"Method Not Allowed","code":405}'));
	checkTrue('login by GET: POST is announced', in_array('Allow: POST', $replyAr['head']));

	// Routing failures
	$replyAr = httpQuery('GET', $baseUrl.'/auth/nope');
	checkSame('unknown action: 404, as an unknown application', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Not Found","code":404}'));

	// Default action : the one the application names in $dfltActn, otherwise its first declared action
	$replyAr = httpQuery('GET', $baseUrl.'/probe', Array('X-Test-Runs'=>'1'));
	checkSame('no action: the first declared action runs', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/', Array('X-Test-Runs'=>'1'));
	checkSame('no action, trailing slash', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows?filters[search]=Beta*');
	checkSame('no action: a default action whose route limits no method is still reached from the path', Array($replyAr['code'], httpQuery('GET', $baseUrl.'/shows/index')['code'], count(json_decode(httpQuery('GET', $baseUrl.'/')['body'], true) ?? Array())), Array(200, 200, 5));

	$replyAr = httpQuery('POST', $baseUrl.'/auth');
	checkSame('no action: the first action of auth is the login, which its declared route alone reaches', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Not Found","code":404}'));

	// The login action refuses anything but a POST by itself, whatever the path that leads to it
	$replyAr = httpQuery('GET', $baseUrl.'/auth?user=nobody-here&pass=wrong');
	checkSame('login by GET through the default action: not found', $replyAr['code'], 404);
	$replyAr = httpQuery('GET', $baseUrl.'/Auth/login?user=nobody-here&pass=wrong');
	checkSame('login by GET through another case of the path: not found', $replyAr['code'], 404);

	$replyAr = httpQuery('GET', $baseUrl.'/nothing/here');
	checkSame('unknown application: 404', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Not Found","code":404}'));

	// Uncaught exception (view called without its parameter)
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view');
	$errVals = json_decode($replyAr['body'], true);
	checkSame('uncaught exception: 500 in the configured format', Array($replyAr['code'], $replyAr['type'], $errVals['code'] ?? null), Array(500, $jsonTyp, 500));
	checkTrue('uncaught exception: carries an error message', is_string($errVals['error'] ?? null) && $errVals['error']!='');

	// Access control
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'header'));
	checkSame('access denied: 401 with an error body', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Missing X-Api-Key header","code":401}'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'hbasic', 'X-Test-Store'=>'htpass'));
	checkSame('Basic access denied: 401', $replyAr['code'], 401);
	checkTrue('Basic access denied: challenge header, with its realm', in_array('WWW-Authenticate: Basic realm="nyuRuns", charset="UTF-8"', $replyAr['head']));
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'hbasic', 'X-Test-Store'=>'regkey'));
	checkSame('Basic access with a check of keys: a mistake of the config, told as a 500', Array($replyAr['code'], $replyAr['body'], count(preg_grep('/^WWW-Authenticate:/i', $replyAr['head']))), Array(500, '{"error":"Access misconfigured","code":500}', 0));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'jtoken'));
	checkSame('JWT access denied: 401', $replyAr['code'], 401);
	checkTrue('JWT access denied: challenge header', in_array('WWW-Authenticate: Bearer', $replyAr['head']));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'Bogus'));
	checkSame('misconfigured access: 500', Array($replyAr['code'], $replyAr['body']), Array(500, '{"error":"Access misconfigured","code":500}'));

	// Public routes
	$pubHead = Array('X-Test-Auth'=>'jtoken', 'X-Test-Public'=>'auth/login');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $pubHead);
	checkSame('public route: the action runs without credentials', Array($replyAr['code'], $replyAr['body']), Array(400, '{"error":"Missing or invalid input: user, pass","code":400}'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', $pubHead);
	checkSame('public route: other routes stay closed', $replyAr['code'], 401);

	$replyAr = httpQuery('GET', $baseUrl.'/auth', $pubHead);
	checkSame('public route: the default action stays closed', $replyAr['code'], 401);

	$replyAr = httpQuery('OPTIONS', $baseUrl.'/gets', Array('X-Test-Auth'=>'jtoken', 'X-Test-Routes'=>'1', 'X-Test-Runs'=>'1'));
	checkSame('public route: an OPTIONS answered by the route needs no credentials', $replyAr['code'], 204);

	$replyAr = httpQuery('GET', $baseUrl.'/gets', Array('X-Test-Auth'=>'jtoken', 'X-Test-Routes'=>'1', 'X-Test-Runs'=>'1'));
	checkSame('public route: the same route stays closed for GET', $replyAr['code'], 401);

	// CORS preflight : answered on any path with the preset's headers, without credentials and without running the action
	$corsHed = Array('Origin'=>'https://app.example', 'Access-Control-Request-Method'=>'GET', 'Access-Control-Request-Headers'=>'authorization');
	$corsLst = Array('Access-Control-Allow-Origin: *', 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS', 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key');
	$replyAr = httpQuery('OPTIONS', $baseUrl.'/shows/view/1', $corsHed);
	checkSame('preflight: answered on an automatic route, without the record', Array($replyAr['code'], $replyAr['body']), Array(204, ''));
	checkSame('preflight: the three CORS headers are sent', array_values(array_intersect($corsLst, $replyAr['head'])), $corsLst);

	$replyAr = httpQuery('OPTIONS', $baseUrl.'/shows/view/1', $corsHed + Array('X-Test-Auth'=>'jtoken'));
	checkSame('preflight: answered without credentials under required access', Array($replyAr['code'], $replyAr['body']), Array(204, ''));

	$replyAr = httpQuery('OPTIONS', $baseUrl.'/auth/login', Array('Access-Control-Request-Method'=>'POST') + $corsHed);
	checkSame('preflight: answered on a route that only accepts POST', Array($replyAr['code'], $replyAr['body']), Array(204, ''));

	$replyAr = httpQuery('OPTIONS', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'jtoken'));
	checkSame('an OPTIONS that is not a preflight stays closed under required access', $replyAr['code'], 401);

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', $corsHed);
	checkSame('a GET carrying the preflight header is served normally', Array($replyAr['code'], json_decode($replyAr['body'], true)['title'] ?? null), Array(200, 'Alpha Night'));
	checkSame('the CORS headers go out on ordinary responses too', array_values(array_intersect($corsLst, $replyAr['head'])), $corsLst);

	// A preset listing its origins : only a listed one is named, on a preflight as on a reply
	$headOf = function( Array $replyArray, String $headerName ): ?String {
		foreach( $replyArray['head'] as $hedLin ){
			if( stripos($hedLin, $headerName.':')===0 ){
				return trim(substr($hedLin, strlen($headerName)+1));
			}
		}
		return null;
	};
	$lstHead = Array('X-Test-Origns'=>'https://app.example, https://other.example');
	$replyAr = httpQuery('OPTIONS', $baseUrl.'/shows/view/1', $corsHed + $lstHead);
	checkSame('origins: a preflight from a listed origin', Array($replyAr['code'], $headOf($replyAr, 'Access-Control-Allow-Origin'), $headOf($replyAr, 'Vary'), $headOf($replyAr, 'Access-Control-Allow-Methods')), Array(204, 'https://app.example', 'Origin', 'GET, POST, PUT, PATCH, DELETE, OPTIONS'));
	$replyAr = httpQuery('OPTIONS', $baseUrl.'/shows/view/1', Array('Origin'=>'https://evil.example') + $corsHed + $lstHead);
	checkSame('origins: a preflight from another origin names none', Array($replyAr['code'], $headOf($replyAr, 'Access-Control-Allow-Origin'), $headOf($replyAr, 'Vary')), Array(204, null, 'Origin'));
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('Origin'=>'https://other.example') + $lstHead);
	checkSame('origins: a reply to a listed origin', Array($replyAr['code'], $headOf($replyAr, 'Access-Control-Allow-Origin')), Array(200, 'https://other.example'));
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('Origin'=>'https://evil.example') + $lstHead);
	checkSame('origins: a reply to another origin is served, without the header', Array($replyAr['code'], $headOf($replyAr, 'Access-Control-Allow-Origin')), Array(200, null));
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('Origin'=>'https://evil.example', 'X-Test-Auth'=>'jtoken') + $lstHead);
	checkSame('origins: a denial follows the same rule', Array($replyAr['code'], $headOf($replyAr, 'Access-Control-Allow-Origin')), Array(401, null));

	// Every reply tells the client not to guess another type than the one announced
	$sniffLs = Array();
	foreach( Array(
		'data'=>httpQuery('GET', $baseUrl.'/shows/view/1'),
		'denial'=>httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'jtoken')),
		'unknown'=>httpQuery('GET', $baseUrl.'/nothing/here'),
		'refused method'=>httpQuery('GET', $baseUrl.'/auth/login'),
		'preflight'=>httpQuery('OPTIONS', $baseUrl.'/shows/view/1', $corsHed)
	) as $rplNme => $rplVal ){
		$sniffLs[$rplNme] = Array($rplVal['code'], $headOf($rplVal, 'X-Content-Type-Options'));
	}
	checkSame('nosniff: on a reply of data, a denial, an unknown route, a refused method and an empty reply', $sniffLs, Array('data'=>Array(200, 'nosniff'), 'denial'=>Array(401, 'nosniff'), 'unknown'=>Array(404, 'nosniff'), 'refused method'=>Array(405, 'nosniff'), 'preflight'=>Array(204, 'nosniff')));
	// An application is named with letters and digits only : anything else is no application
	$codeLst = Array();
	foreach( Array('/sho.ws/view/1', '/shows-x/view/1', '/shows%20/view/1', '/..%5Csrc/view', '/shows%2Fview/1') as $badPth ){
		$replyAr = httpQuery('GET', $baseUrl.$badPth);
		$codeLst[] = Array($replyAr['code'], $replyAr['body']);
	}
	checkSame('application name: what is not letters and digits is a 404', $codeLst, array_fill(0, 5, Array(404, '{"error":"Not Found","code":404}')));

	// An action a declared route answers with is only reached through that route (conf/routes : auth/login takes POST)
	$codeLst = Array();
	foreach( Array('/auth/login/x', '/Auth/login', '/AUTH/LOGIN', '/auth') as $sidePth ){
		$codeLst[$sidePth] = httpQuery('GET', $baseUrl.$sidePth)['code'];
	}
	checkSame('declared route: its action is not reached beside it, whatever the case or what follows', $codeLst, Array('/auth/login/x'=>404, '/Auth/login'=>404, '/AUTH/LOGIN'=>404, '/auth'=>404));
	$replyAr = httpQuery('POST', $baseUrl.'/Auth/login', $formHed, 'user=nobody-here&pass=wrong');
	checkSame('declared route: nor with the method it takes, on another path', $replyAr['code'], 404);
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed, 'user=nobody-here&pass=wrong');
	checkSame('declared route: through the route, the action runs', $replyAr['code'], 401);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/text', Array('X-Test-Runs'=>'1'));
	checkSame('an action no route answers with is still found from the path', $replyAr['code'], 200);

	// Body limits (test/fixtures/confreq : 1 KB, 2 parts)
	$reqHead = Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'confreq');
	$replyAr = httpQuery('POST', $baseUrl.'/probe/creds', $formHed + $reqHead, 'user=a&pass=b');
	checkSame('body limit: a small body is read', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Credentials read","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/probe/creds', $formHed + $reqHead, 'user=a&pass='.str_repeat('b', 2000));
	checkSame('body limit: a larger one is a 413', Array($replyAr['code'], $replyAr['body']), Array(413, '{"error":"Payload Too Large","code":413}'));
	$replyAr = httpQuery('PUT', $baseUrl.'/probe/creds', Array('Content-Type'=>'application/json') + $reqHead, json_encode(Array('user'=>'a', 'pass'=>str_repeat('b', 2000))));
	checkSame('body limit: whatever the method and the type', $replyAr['code'], 413);
	$bndText = 'TestBoundary77';
	$partFnc = function( Array $namesList ) use ( $bndText ): String {
		$bodyTxt = '';
		foreach( $namesList as $fldNme ){
			$bodyTxt .= '--'.$bndText."\r\n".'Content-Disposition: form-data; name="'.$fldNme.'"'."\r\n\r\n".'v'."\r\n";
		}
		return $bodyTxt.'--'.$bndText.'--'."\r\n";
	};
	$mltHead = Array('Content-Type'=>'multipart/form-data; boundary='.$bndText) + $reqHead;
	checkSame('body limit: a multipart body of two parts is read, one of three is a 413', Array(httpQuery('PUT', $baseUrl.'/probe/creds', $mltHead, $partFnc(Array('user', 'pass')))['code'], httpQuery('PUT', $baseUrl.'/probe/creds', $mltHead, $partFnc(Array('user', 'pass', 'more')))['code']), Array(401, 413));
	$replyAr = httpQuery('POST', $baseUrl.'/probe/creds', $formHed + Array('X-Test-Runs'=>'1'), 'user=a&pass='.str_repeat('b', 2000));
	checkSame('body limit: the usual configuration reads that body', $replyAr['code'], 401);

	// Error pages : a site answers an error with its own page, an action keeps the format of the preset
	$errHead = Array('X-Test-Preset'=>'manual', 'X-Test-Runs'=>'1', 'X-Test-Routes'=>'1', 'X-Test-Htroot'=>'errpages');
	$replyAr = httpQuery('GET', $baseUrl.'/nothing/here', $errHead);
	checkSame('error page: a path no route answers', Array($replyAr['code'], $replyAr['type'], trim(strval(strstr($replyAr['body'], '<h1>'))), $headOf($replyAr, 'X-Content-Type-Options')), Array(404, 'text/html; charset=utf-8', '<h1>404</h1><p>Nothing here: Not Found</p></body></html>', 'nosniff'));
	$replyAr = httpQuery('GET', $baseUrl.'/hello/x', $errHead);
	checkSame('error page: a page whose file is not there', Array($replyAr['code'], $replyAr['type'], strpos($replyAr['body'], 'Nothing here: Not Found')!==false), Array(404, 'text/html; charset=utf-8', true));
	$replyAr = httpQuery('GET', $baseUrl.'/nothing/here', $errHead + Array('X-Test-Auth'=>'jtoken'));
	checkSame('error page: a denial has the page of its status', Array($replyAr['code'], $replyAr['type'], strpos($replyAr['body'], '<h1>401</h1><p>Sign in first: Missing Bearer Authorization header</p>')!==false), Array(401, 'text/html; charset=utf-8', true));
	$replyAr = httpQuery('POST', $baseUrl.'/ping', $errHead);
	checkSame('error page: a status the site has no page for keeps the format of the preset', Array($replyAr['code'], $replyAr['body']), Array(405, '{"error":"Method Not Allowed","code":405}'));
	$replyAr = httpQuery('GET', $baseUrl.'/things/1', $errHead + Array('X-Test-Auth'=>'jtoken'));
	checkSame('error page: a route to an action keeps the format of the preset', Array($replyAr['code'], $replyAr['type']), Array(401, $jsonTyp));
	$replyAr = httpQuery('GET', $baseUrl.'/nothing/here', Array('X-Test-Preset'=>'manual', 'X-Test-Runs'=>'1', 'X-Test-Routes'=>'1'));
	checkSame('error page: a site without one answers as before', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Not Found","code":404}'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Ctname'=>'x-content-type-options', 'X-Test-Ctype'=>'off'));
	checkSame('nosniff: a preset cannot give the header another value', preg_grep('/^X-Content-Type-Options:/i', $replyAr['head']) ? array_values(preg_grep('/^X-Content-Type-Options:/i', $replyAr['head'])) : Array(), Array('X-Content-Type-Options: nosniff'));

	// Identity handed to the application
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Runs'=>'1'));
	checkSame('identity: no access control, not logged', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":false,"user":"","role":null}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Runs'=>'1', 'X-Test-Auth'=>'jtoken', 'X-Test-Public'=>'probe/whoami'));
	checkSame('identity: public route, not logged', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":false,"user":"","role":null}'));

	// The API key block of test/fixtures/conf/access.json
	$keyHead = Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'conf', 'X-Test-Auth'=>'header', 'X-Test-Confkey'=>'tskeys');
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $keyHead + Array('X-Api-Key'=>'test-only-key-1'));
	checkSame('identity: valid API key, logged as a member', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"","role":"member"}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $keyHead + Array('X-Api-Key'=>'not-the-key'));
	checkSame('identity: invalid API key, the action does not run', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid API key","code":401}'));

	// Login round trip : htpasswd store and JWT of test/fixtures/conf
	$whoHead = Array('X-Test-Runs'=>'1', 'X-Test-Auth'=>'jtoken', 'X-Test-Confkey'=>'tstokn');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'conf'), 'user=alice&pass=s3cret');
	$jwToken = strval(json_decode($replyAr['body'], true)['token'] ?? '');
	checkSame('round trip: login answers 200', $replyAr['code'], 200);
	checkLike('round trip: login gives a token', $jwToken, '/^[\w-]+\.[\w-]+\.[\w-]+$/');

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $whoHead + Array('X-Test-Conf'=>'conf', 'Authorization'=>'Bearer '.$jwToken));
	checkSame('round trip: the token identifies its user', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"alice","role":"member"}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $whoHead + Array('X-Test-Conf'=>'conf', 'Authorization'=>'Bearer '.$jwToken.'x'));
	checkSame('round trip: a tampered token is refused', $replyAr['code'], 401);

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'conf'), 'user=alice&pass=nope');
	checkSame('round trip: wrong password, no token', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));

	// The published keys : RS256 tokens bound to an issuer and an audience (test/fixtures/confjwk), the keys made here
	if( function_exists('openssl_pkey_new') ){
		$jwkDir = TOP_DIR.DS.'temp'.DS.'httpjwk';
		@mkdir($jwkDir, 0700, true);
		$cnfOpts = Array('config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf');
		foreach( Array('tstokn', 'tstokn.old') as $keyBase ){
			$keyObjc = openssl_pkey_new( Array('private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA) + $cnfOpts );
			$prvText = '';
			openssl_pkey_export($keyObjc, $prvText, null, $cnfOpts);
			file_put_contents($jwkDir.DS.$keyBase.'.key', $prvText);
			file_put_contents($jwkDir.DS.$keyBase.'.pub', strval(openssl_pkey_get_details($keyObjc)['key']));
		}
		$replyAr = httpQuery('GET', $baseUrl.'/.well-known/jwks.json', Array('X-Test-Conf'=>'confjwk'));
		$keysSet = json_decode($replyAr['body'], true);
		checkSame('key set: served as JSON, the current key and the previous one', Array($replyAr['code'], $replyAr['type'], count($keysSet['keys'] ?? Array())), Array(200, $jsonTyp, 2));
		checkTrue('key set: may be kept five minutes', in_array('Cache-Control: public, max-age=300', $replyAr['head']));
		checkSame('key set: open to anyone, also in a preset asking for a token', httpQuery('GET', $baseUrl.'/.well-known/jwks.json', Array('X-Test-Conf'=>'confjwk', 'X-Test-Preset'=>'webapi'))['code'], 200);
		checkSame('key set: GET only', httpQuery('POST', $baseUrl.'/.well-known/jwks.json', Array('X-Test-Conf'=>'confjwk'))['code'], 405);
		checkSame('key set: nothing published with HS256', Array(httpQuery('GET', $baseUrl.'/.well-known/jwks.json', Array('X-Test-Conf'=>'conf'))['code']), Array(404));
		$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'confjwk'), 'user=alice&pass=s3cret');
		$jwToken = strval(json_decode($replyAr['body'], true)['token'] ?? '');
		$tknClms = runSafely(function() use ( $jwToken, $keysSet ){ return (array)Firebase\JWT\JWT::decode($jwToken, Firebase\JWT\JWK::parseKeySet($keysSet)); });
		checkSame('key set: another project checks the token of the login with it alone', Array($replyAr['code'], $tknClms['user'] ?? null, $tknClms['iss'] ?? null, $tknClms['aud'] ?? null), Array(200, 'alice', 'https://auth.example.org', 'api.example.org'));
		$jwkHead = Array('X-Test-Runs'=>'1', 'X-Test-Auth'=>'jtoken', 'X-Test-Confkey'=>'tstokn', 'X-Test-Conf'=>'confjwk');
		checkSame('bound token: accepted by its audience', httpQuery('GET', $baseUrl.'/probe/whoami', $jwkHead + Array('Authorization'=>'Bearer '.$jwToken))['body'], '{"logged":true,"user":"alice","role":"member"}');
		$othTokn = Firebase\JWT\JWT::encode( Array('user'=>'alice', 'exp'=>time()+60, 'iss'=>'https://auth.example.org', 'aud'=>'other.example.org'), strval(file_get_contents($jwkDir.DS.'tstokn.key')), 'RS256' );
		checkSame('bound token: one meant for another audience is refused', Array(httpQuery('GET', $baseUrl.'/probe/whoami', $jwkHead + Array('Authorization'=>'Bearer '.$othTokn))['body']), Array('{"error":"Invalid token","code":401}'));
		dropFoldr($jwkDir);
	}else{
		skipCheck('key set', 'the openssl extension is not loaded');
	}

	// Login round trip : native userbase of the sample database (test/fixtures/confnat)
	$replyAr = httpQuery('GET', $baseUrl.'/probe/online', Array('X-Test-Runs'=>'1'));
	checkSame('presence: nobody is online before any login', Array($replyAr['code'], $replyAr['body']), Array(200, '{"online":[]}'));

	$logMark = strlen(readsLogs('access'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'confnat'), 'user=anna&pass=pw-anna');
	$jwToken = strval(json_decode($replyAr['body'], true)['token'] ?? '');
	checkSame('native round trip: login answers 200', $replyAr['code'], 200);

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $whoHead + Array('X-Test-Conf'=>'confnat', 'Authorization'=>'Bearer '.$jwToken));
	checkSame('native round trip: the role of the users table reaches the action', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"manage"}'));

	// The login and the access of a privileged user are notices of the access log
	$newLogs = substr(readsLogs('access'), $logMark);
	checkLike('access log: privileged login', $newLogs, '/\[NOTICE\] Privileged login granted for user "anna" \(manage\) from \S+/');
	checkLike('access log: privileged access, with what was asked for', $newLogs, '/\[NOTICE\] Privileged access granted for user "anna" \(manage\) from \S+ to probe\/whoami/i');

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'confnat'), 'user=cleo&pass=pw-cleo');
	checkSame('native round trip: a pending user gets no token, and is not told why', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/online', Array('X-Test-Runs'=>'1'));
	checkSame('presence: the user who logged in is online', $replyAr['body'], '{"online":["anna"]}');

	// A request verified by Basic credentials against the users table stamps its user too
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'confnat', 'X-Test-Auth'=>'hbasic', 'X-Test-Store'=>'native', 'X-Test-Confkey'=>'tsnatv', 'Authorization'=>'Basic '.base64_encode('ben:pw-ben')));
	checkSame('presence: Basic access on the users table', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"ben","role":"member"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/online', Array('X-Test-Runs'=>'1'));
	checkSame('presence: the user of a verified request is online', $replyAr['body'], '{"online":["anna","ben"]}');

	// A token confirmed against the users table : a role change or a lock applies to a token already issued
	$sqlLink = new PDO('sqlite:'.$sqlFile);
	$storHed = $whoHead + Array('X-Test-Conf'=>'confnat', 'X-Test-Store'=>'native', 'Authorization'=>'Bearer '.$jwToken);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $storHed);
	checkSame('token with a store: accepted', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"manage"}'));

	$sqlLink->exec("UPDATE users SET userRole='system' WHERE userName='anna'");
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $storHed);
	checkSame('token with a store: a role change applies at once', $replyAr['body'], '{"logged":true,"user":"anna","role":"system"}');

	$sqlLink->exec("UPDATE users SET userStat='locked' WHERE userName='anna'");
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $storHed);
	checkSame('token with a store: a locked user is refused at once', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Account not active","code":401}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $whoHead + Array('X-Test-Conf'=>'confnat', 'Authorization'=>'Bearer '.$jwToken));
	checkSame('token without a store: still accepted until it expires', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"manage"}'));
	$sqlLink->exec("UPDATE users SET userStat='active', userRole='manage' WHERE userName='anna'");

	// Personal API token of the users table, sent in the API key header
	$sqlLink->exec("UPDATE users SET tokenKey='".hash('sha256', 'http-test-token')."' WHERE userName='anna'");
	$tknHead = Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'confnat', 'X-Test-Auth'=>'header', 'X-Test-Store'=>'native', 'X-Test-Confkey'=>'tsnatv');
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $tknHead + Array('X-Api-Key'=>'http-test-token'));
	checkSame('personal token: identifies its user', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"manage"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $tknHead + Array('X-Api-Key'=>'another-token'));
	checkSame('personal token: a wrong token is refused', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid API key","code":401}'));

	// 2FA : the login of a user with a secret needs the code of its authenticator
	$tfaScrt = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
	$sqlLink->exec("UPDATE users SET twoFaKey='".$tfaScrt."' WHERE userName='ben'");
	$natHead = $formHed + Array('X-Test-Conf'=>'confnat');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $natHead, 'user=ben&pass=pw-ben');
	checkSame('2FA login: password alone', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Verification code required","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $natHead, 'user=ben&pass=pw-ben&code=000000');
	checkSame('2FA login: wrong code', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid verification code","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $natHead, 'user=ben&pass=pw-ben&code='.nyu\Access\Totp::codeAtTme($tfaScrt, time()));
	checkSame('2FA login: valid code gives a token', Array($replyAr['code'], is_string(json_decode($replyAr['body'], true)['token'] ?? null)), Array(200, true));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'confnat', 'X-Test-Auth'=>'hbasic', 'X-Test-Store'=>'native', 'X-Test-Confkey'=>'tsnatv', 'Authorization'=>'Basic '.base64_encode('ben:pw-ben')));
	checkSame('2FA: Basic access is closed to a user with a secret', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Verification code required","code":401}'));
	$sqlLink->exec("UPDATE users SET twoFaKey=NULL WHERE userName='ben'");

	// Attempt throttling : five wrong passwords hold the account, even for the right one
	$sqlLink->exec("UPDATE users SET failNmbr=0, failDate=NULL");
	for( $idx=0; $idx<5; $idx++ ){
		$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $natHead, 'user=ben&pass=nope');
	}
	checkSame('throttle: the fifth wrong password is told as such', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $natHead, 'user=ben&pass=pw-ben');
	checkSame('throttle: the right password is then refused, as a wrong one would be', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));
	$sqlLink->exec("UPDATE users SET failDate='".date('Y-m-d H:i:s', time()-601)."' WHERE userName='ben'");
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $natHead, 'user=ben&pass=pw-ben');
	checkSame('throttle: accepted again once the hold is over', $replyAr['code'], 200);

	// User management (runs/Users, auth/activate, auth/password) : a manager creates a user, who activates itself
	$benToken = strval(json_decode($replyAr['body'], true)['token'] ?? '');
	$usrHead = $formHed + Array('X-Test-Conf'=>'confnat', 'X-Test-Auth'=>'jtoken', 'X-Test-Confkey'=>'tstokn', 'X-Test-Store'=>'native', 'X-Test-Public'=>'auth/login,auth/activate');
	$mgrHead = $usrHead + Array('Authorization'=>'Bearer '.$jwToken);
	$replyAr = httpQuery('POST', $baseUrl.'/users/create', $usrHead, 'user=henry');
	checkSame('users: nothing without a session', $replyAr['code'], 401);
	$replyAr = httpQuery('POST', $baseUrl.'/users/create', $usrHead + Array('Authorization'=>'Bearer '.$benToken), 'user=henry');
	checkSame('users: refused to a member', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Not Found","code":404}'));
	$replyAr = httpQuery('GET', $baseUrl.'/users/index', $usrHead + Array('Authorization'=>'Bearer '.$benToken));
	checkSame('users: the list is refused to a member too', $replyAr['code'], 404);
	$replyAr = httpQuery('POST', $baseUrl.'/users/create', $mgrHead, 'user=henry');
	$newCode = strval(json_decode($replyAr['body'], true)['code'] ?? '');
	checkSame('users: a manager creates a user', Array($replyAr['code'], json_decode($replyAr['body'], true)['user'] ?? null, strlen($newCode)), Array(201, 'henry', 12));
	$replyAr = httpQuery('POST', $baseUrl.'/users/create', $mgrHead, 'role=member');
	checkSame('users: a name is required', Array($replyAr['code'], $replyAr['body']), Array(400, '{"error":"Missing or invalid input: user","code":400}'));
	$replyAr = httpQuery('GET', $baseUrl.'/users/index?stat=pendng', $mgrHead);
	checkSame('users: the list, filtered, tells no hash and no code', Array($replyAr['code'], array_column(json_decode($replyAr['body'], true) ?? Array(), 'userName'), array_keys(json_decode($replyAr['body'], true)[0] ?? Array())), Array(200, Array('cleo', 'henry'), Array('id_user', 'userName', 'userRole', 'userStat', 'userMail', 'userLang', 'signDate', 'madeDate', 'lastSeen')));
	$replyAr = httpQuery('GET', $baseUrl.'/users/view?user=henry', $mgrHead);
	checkSame('users: one user', Array($replyAr['code'], json_decode($replyAr['body'], true)['userStat'] ?? null), Array(200, 'pendng'));

	$replyAr = httpQuery('POST', $baseUrl.'/auth/activate', $usrHead, 'user=henry&code='.str_repeat('0', 32).'&pass=pass-henry-1');
	checkSame('activate: open without a session, a wrong code is refused', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid activation","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/activate', $usrHead, 'user=henry&code='.$newCode.'&pass=short');
	checkSame('activate: a password breaking a rule', Array($replyAr['code'], $replyAr['body']), Array(400, '{"error":"Password too short: 10 characters at least","code":400}'));
	// The route is declared for POST : another method is not the open route, and meets the access check first
	$replyAr = httpQuery('GET', $baseUrl.'/auth/activate?user=henry&code='.$newCode.'&pass=pass-henry-1', $usrHead);
	checkSame('activate: a GET is not open', $replyAr['code'], 401);
	$replyAr = httpQuery('GET', $baseUrl.'/auth/activate?user=henry&code='.$newCode.'&pass=pass-henry-1', $mgrHead);
	checkSame('activate: a GET is refused, even with a session', $replyAr['code'], 405);
	$replyAr = httpQuery('POST', $baseUrl.'/auth/activate', $usrHead, 'user=henry&code='.$newCode.'&pass='.rawurlencode('pass "henry" <1>'));
	checkSame('activate: the code gives the password', Array($replyAr['code'], $replyAr['body']), Array(200, '{"active":true,"stat":"active"}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $natHead, 'user=henry&pass='.rawurlencode('pass "henry" <1>'));
	$newToken = strval(json_decode($replyAr['body'], true)['token'] ?? '');
	checkSame('activate: the user logs in with its password, kept as sent', Array($replyAr['code'], $newToken!=''), Array(200, true));

	$replyAr = httpQuery('POST', $baseUrl.'/auth/password', $usrHead, 'former='.rawurlencode('pass "henry" <1>').'&pass=pass-henry-2');
	checkSame('password: nothing without a session', $replyAr['code'], 401);
	$replyAr = httpQuery('POST', $baseUrl.'/auth/password', $usrHead + Array('Authorization'=>'Bearer '.$newToken), 'former=nope&pass=pass-henry-2');
	checkSame('password: wrong current one', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/password', $usrHead + Array('Authorization'=>'Bearer '.$newToken), 'former='.rawurlencode('pass "henry" <1>').'&pass=pass-henry-2');
	checkSame('password: changed by its user', Array($replyAr['code'], $replyAr['body']), Array(200, '{"changed":true}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $natHead, 'user=henry&pass=pass-henry-2');
	checkSame('password: the new one logs in', $replyAr['code'], 200);

	$replyAr = httpQuery('POST', $baseUrl.'/users/role', $mgrHead, 'user=henry&role=system');
	checkSame('users: a role is given', Array($replyAr['code'], $replyAr['body']), Array(200, '{"user":"henry","role":"system"}'));
	$replyAr = httpQuery('POST', $baseUrl.'/users/lock', $mgrHead, 'user=anna');
	checkSame('users: a manager cannot lock itself', $replyAr['code'], 409);
	$replyAr = httpQuery('POST', $baseUrl.'/users/lock', $mgrHead, 'user=henry');
	checkSame('users: a user is locked', Array($replyAr['code'], $replyAr['body']), Array(200, '{"user":"henry","stat":"locked"}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/password', $usrHead + Array('Authorization'=>'Bearer '.$newToken), 'former=pass-henry-2&pass=pass-henry-3');
	checkSame('users: the lock ends the session the user held', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Account not active","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/users/remove', $mgrHead, 'user=henry');
	checkSame('users: a user is removed', Array($replyAr['code'], $sqlLink->query("SELECT COUNT(*) FROM users WHERE userName='henry'")->fetchColumn()), Array(200, 0));

	// Roles : what a route asks for ('rolert'), and the helpers of an action
	$benHead = Array('Authorization'=>'Bearer '.$benToken);
	$annHead = Array('Authorization'=>'Bearer '.$jwToken);
	$rolHead = $usrHead + Array('X-Test-Roles'=>'users/*=manage, probe/whoami=system, /docs/*=system, /hello/$id=manage');
	$logMark = strlen(readsLogs('access'));
	$replyAr = httpQuery('GET', $baseUrl.'/users/index', $rolHead + $benHead);
	checkSame('roles: a route refused to a member', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Not Found","code":404}'));
	checkSame('roles: with no challenge', count(preg_grep('/^WWW-Authenticate:/i', $replyAr['head'])), 0);
	checkLike('roles: the refusal is logged with the role needed', substr(readsLogs('access'), $logMark), '/\[WARNING\] Access refused for user "ben" \(member\) from \S+ to users\/index: role manage needed/');
	$replyAr = httpQuery('GET', $baseUrl.'/users', $rolHead + $benHead);
	checkSame('roles: so is its application, named without an action', $replyAr['code'], 404);
	$replyAr = httpQuery('GET', $baseUrl.'/users/index', $rolHead + $annHead);
	checkSame('roles: served to a manager', $replyAr['code'], 200);
	$replyAr = httpQuery('GET', $baseUrl.'/users/index', $rolHead);
	checkSame('roles: nobody logged is a 401', $replyAr['code'], 401);
	$replyAr = httpQuery('GET', $baseUrl.'/users/create?user=henry', $usrHead + $benHead);
	checkSame('roles: an action asks for the role before the method', $replyAr['code'], 404);

	$prbHead = Array('X-Test-Runs'=>'1', 'X-Test-Routes'=>'1', 'X-Test-Signin'=>'/enter') + $rolHead;
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $prbHead + $benHead);
	checkSame('roles: an action of another application', $replyAr['code'], 404);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $prbHead + $annHead);
	checkSame('roles: a manager holds the system role', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"manage"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $prbHead + $benHead);
	checkSame('roles: a file of a folder route', $replyAr['code'], 404);
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $prbHead + $annHead);
	checkSame('roles: the file is served with the role', $replyAr['code'], 200);
	$replyAr = httpQuery('GET', $baseUrl.'/hello/7', $prbHead + $benHead);
	checkSame('roles: a page is a 404, not the sign-in route', Array($replyAr['code'], count(preg_grep('/^Location:/i', $replyAr['head']))), Array(404, 0));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/roles', $prbHead + $benHead);
	checkSame('roles: what a member holds', $replyAr['body'], '{"member":true,"system":false,"manage":false}');
	$replyAr = httpQuery('GET', $baseUrl.'/probe/roles', $prbHead + $annHead);
	checkSame('roles: what a manager holds', $replyAr['body'], '{"member":true,"system":true,"manage":true}');
	$replyAr = httpQuery('GET', $baseUrl.'/probe/roles/system', $prbHead + $benHead);
	checkSame('roles: an action asking for a role itself', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Not Found","code":404}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/roles/system', $prbHead + $annHead);
	checkSame('roles: and going on with it', $replyAr['code'], 200);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/roles/root', $prbHead + $annHead);
	checkSame('roles: an action asking for an unknown role is a 500', $replyAr['code'], 500);

	$replyAr = httpQuery('GET', $baseUrl.'/probe/clean', Array('X-Test-Roles'=>'users/*=admin') + $prbHead + $annHead);
	checkSame('roles: an unknown role refuses every request', Array($replyAr['code'], $replyAr['body']), Array(500, '{"error":"Access misconfigured","code":500}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $keyHead + Array('X-Api-Key'=>'test-only-key-1', 'X-Test-Roles'=>'probe/whoami=member'));
	checkSame('roles: an API key meets the member role', $replyAr['code'], 200);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $keyHead + Array('X-Api-Key'=>'test-only-key-1', 'X-Test-Roles'=>'probe/whoami=system'));
	checkSame('roles: and no higher one', $replyAr['code'], 404);
	$sqlLink->exec("UPDATE users SET failNmbr=0, failDate=NULL");

	// Login round trip : flat file of users (test/fixtures/confusr, test/fixtures/users.lst), without the database
	$lstHead = $formHed + Array('X-Test-Conf'=>'confusr');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $lstHead, 'user=anna&pass=pw-anna');
	$jwToken = strval(json_decode($replyAr['body'], true)['token'] ?? '');
	checkSame('users file round trip: login answers 200', $replyAr['code'], 200);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $whoHead + Array('X-Test-Conf'=>'confusr', 'Authorization'=>'Bearer '.$jwToken));
	checkSame('users file round trip: the role of the file reaches the action', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"manage"}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $lstHead, 'user=anna&pass=nope');
	checkSame('users file round trip: wrong password, no token', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $lstHead, 'user=cleo&pass=pw-cleo');
	checkSame('users file round trip: a pending user gets no token, and is not told why', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $lstHead, 'user=emma&pass=pw-emma');
	checkSame('users file 2FA login: password alone', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Verification code required","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $lstHead, 'user=emma&pass=pw-emma&code='.nyu\Access\Totp::codeAtTme($tfaScrt, time()));
	checkSame('users file 2FA login: valid code gives a token', Array($replyAr['code'], is_string(json_decode($replyAr['body'], true)['token'] ?? null)), Array(200, true));
	$lstAxss = Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'confusr', 'X-Test-Store'=>'usrlst', 'X-Test-Confkey'=>'tsusrs');
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $lstAxss + Array('X-Test-Auth'=>'hbasic', 'Authorization'=>'Basic '.base64_encode('ben:pw-ben')));
	checkSame('users file: Basic access', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"ben","role":"member"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $lstAxss + Array('X-Test-Auth'=>'header', 'X-Api-Key'=>'list-test-token'));
	checkSame('users file: personal token identifies its user', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"manage"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $lstAxss + Array('X-Test-Auth'=>'header', 'X-Api-Key'=>'another-token'));
	checkSame('users file: a wrong personal token is refused', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid API key","code":401}'));

	// PHP session carrier (test/fixtures/confses) : the login opens a session, its cookie identifies the next requests
	$sesConf = $formHed + Array('X-Test-Conf'=>'confses');
	$sesHead = Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'confses', 'X-Test-Auth'=>'phsess', 'X-Test-Confkey'=>'tssess');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $sesConf, 'user=anna&pass=pw-anna');
	$cookLin = '';
	foreach( $replyAr['head'] as $hedLin ){
		if( stripos($hedLin, 'Set-Cookie: nyuTests=')===0 ){
			$cookLin = $hedLin;
		}
	}
	$sessCok = 'nyuTests='.strtok(substr($cookLin, strlen('Set-Cookie: nyuTests=')), ';');
	checkSame('session login: answers without showing the session id', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true}'));
	checkLike('session login: the cookie is HttpOnly and SameSite=Strict', $cookLin, '/^Set-Cookie: nyuTests=[A-Za-z0-9,-]{20,}; path=\/; HttpOnly; SameSite=Strict$/i');

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$sessCok));
	checkSame('session: the cookie identifies its user', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"manage"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$sessCok));
	checkSame('session: it stays open on the next request', $replyAr['code'], 200);

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead);
	checkSame('session: no cookie', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"No active session","code":401}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>'nyuTests='.str_repeat('a', 32)));
	checkSame('session: an id that was never issued is not adopted', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"No active session","code":401}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>'nyuTests=../../etc/passwd'));
	checkSame('session: a malformed id is refused', $replyAr['code'], 401);

	// A login from a browser that already holds a session closes it, and opens another
	$frstCok = $sessCok;
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $sesConf + Array('Cookie'=>$frstCok), 'user=anna&pass=pw-anna');
	foreach( $replyAr['head'] as $hedLin ){
		if( stripos($hedLin, 'Set-Cookie: nyuTests=')===0 ){
			$sessCok = 'nyuTests='.strtok(substr($hedLin, strlen('Set-Cookie: nyuTests=')), ';');
		}
	}
	checkSame('session: a second login gives another session', Array($replyAr['code'], $sessCok!=$frstCok && $sessCok!='nyuTests=deleted'), Array(200, true));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$frstCok));
	checkSame('session: the former one is closed', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"No active session","code":401}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$sessCok));
	checkSame('session: the new one is open', $replyAr['code'], 200);
	// Ending a session takes its own cookie away, not the other cookies of the reply
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $sesConf, 'user=anna&pass=pw-anna');
	$leftCok = '';
	foreach( $replyAr['head'] as $hedLin ){
		if( stripos($hedLin, 'Set-Cookie: nyuTests=')===0 ){
			$leftCok = 'nyuTests='.strtok(substr($hedLin, strlen('Set-Cookie: nyuTests=')), ';');
		}
	}
	$replyAr = httpQuery('GET', $baseUrl.'/probe/leave', Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'confses', 'Cookie'=>$leftCok));
	checkSame('session: ending it keeps the cookie the application set', Array($replyAr['code'], count(preg_grep('/^Set-Cookie: probeKept=1/i', $replyAr['head'])), count(preg_grep('/^Set-Cookie: nyuTests=deleted/i', $replyAr['head'])), count(preg_grep('/^Set-Cookie:/i', $replyAr['head']))), Array(200, 1, 1, 2));

	// With the users table named as the store, a lock ends the session at once
	$sqlLink->exec("UPDATE users SET userStat='locked' WHERE userName='anna'");
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$sessCok));
	checkSame('session without a store: still open for a locked user', $replyAr['code'], 200);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('X-Test-Store'=>'native', 'Cookie'=>$sessCok));
	checkSame('session with a store: a locked user is refused', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Account not active","code":401}'));
	$sqlLink->exec("UPDATE users SET userStat='active' WHERE userName='anna'");
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$sessCok));
	checkSame('session with a store: the refused session was destroyed', $replyAr['code'], 401);

	// Logout
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $sesConf, 'user=anna&pass=pw-anna');
	foreach( $replyAr['head'] as $hedLin ){
		if( stripos($hedLin, 'Set-Cookie: nyuTests=')===0 ){
			$sessCok = 'nyuTests='.strtok(substr($hedLin, strlen('Set-Cookie: nyuTests=')), ';');
		}
	}
	$replyAr = httpQuery('GET', $baseUrl.'/auth/logout', Array('X-Test-Conf'=>'confses', 'Cookie'=>$sessCok));
	checkSame('logout by GET: 405', $replyAr['code'], 405);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$sessCok));
	checkSame('logout by GET: the session is still open', $replyAr['code'], 200);
	$replyAr = httpQuery('POST', $baseUrl.'/auth/logout', Array('X-Test-Conf'=>'confses', 'Cookie'=>$sessCok));
	checkSame('logout: answers 200', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":false}'));
	checkTrue('logout: the cookie is expired', count(preg_grep('/^Set-Cookie: nyuTests=deleted; expires=Thu, 01[ -]Jan[ -]1970/i', $replyAr['head']))==1);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$sessCok));
	checkSame('logout: the session is gone', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"No active session","code":401}'));

	// A privileged logout on a checked route is a notice of the access log
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $sesConf, 'user=anna&pass=pw-anna');
	foreach( $replyAr['head'] as $hedLin ){
		if( stripos($hedLin, 'Set-Cookie: nyuTests=')===0 ){
			$sessCok = 'nyuTests='.strtok(substr($hedLin, strlen('Set-Cookie: nyuTests=')), ';');
		}
	}
	$logMark = strlen(readsLogs('access'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/logout', Array('X-Test-Conf'=>'confses', 'X-Test-Auth'=>'phsess', 'X-Test-Confkey'=>'tssess', 'Cookie'=>$sessCok));
	checkSame('logout on a checked route: answers 200', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":false}'));
	checkLike('access log: privileged logout', substr(readsLogs('access'), $logMark), '/\[NOTICE\] Logout of privileged user "anna" \(manage\)/');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/logout', Array('X-Test-Conf'=>'confses', 'X-Test-Auth'=>'phsess', 'X-Test-Confkey'=>'tssess', 'Cookie'=>$sessCok));
	checkSame('logout on a checked route: a second one finds no session', $replyAr['code'], 401);

	// A login never keeps an id the client came with
	$gvnIdnt = str_repeat('a', 32);
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $sesConf + Array('Cookie'=>'nyuTests='.$gvnIdnt), 'user=anna&pass=pw-anna');
	$sessCok = '';
	foreach( $replyAr['head'] as $hedLin ){
		if( stripos($hedLin, 'Set-Cookie: nyuTests=')===0 ){
			$sessCok = 'nyuTests='.strtok(substr($hedLin, strlen('Set-Cookie: nyuTests=')), ';');
		}
	}
	checkSame('session login: an id chosen by the client is replaced', Array($replyAr['code'], $sessCok!='', $sessCok!='nyuTests='.$gvnIdnt), Array(200, true, true));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>'nyuTests='.$gvnIdnt));
	checkSame('session login: the id chosen by the client opens nothing', $replyAr['code'], 401);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$sessCok));
	checkSame('session login: the id it was given does', $replyAr['code'], 200);

	// Logout with a token carrier : nothing to destroy, the token stays valid until it expires
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'conf'), 'user=alice&pass=s3cret');
	$jwToken = strval(json_decode($replyAr['body'], true)['token'] ?? '');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/logout', Array('X-Test-Conf'=>'conf', 'Authorization'=>'Bearer '.$jwToken));
	checkSame('logout with a token carrier: answers 200, without a cookie', Array($replyAr['code'], $replyAr['body'], count(preg_grep('/^Set-Cookie:/i', $replyAr['head']))), Array(200, '{"logged":false}', 0));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $whoHead + Array('X-Test-Conf'=>'conf', 'Authorization'=>'Bearer '.$jwToken));
	checkSame('logout with a token carrier: the token is still accepted', $replyAr['code'], 200);

	// Carriers that take a check of passwords or keys, on the htpasswd file : Basic access, and a key refused
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'conf', 'X-Test-Auth'=>'hbasic', 'X-Test-Store'=>'htpass', 'X-Test-Confkey'=>'tspass', 'Authorization'=>'Basic '.base64_encode('alice:s3cret')));
	checkSame('htpasswd file: Basic access, as a member', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"alice","role":"member"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'conf', 'X-Test-Auth'=>'hbasic', 'X-Test-Store'=>'htpass', 'X-Test-Confkey'=>'tspass', 'Authorization'=>'Basic '.base64_encode('alice:nope')));
	checkSame('htpasswd file: wrong password, with the challenge', Array($replyAr['code'], $replyAr['body'], in_array('WWW-Authenticate: Basic realm="nyuRuns", charset="UTF-8"', $replyAr['head'])), Array(401, '{"error":"Invalid credentials","code":401}', true));

	// A session left unused for longer than its lifetime (1 second in the tsshrt block)
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $sesConf, 'user=anna&pass=pw-anna');
	foreach( $replyAr['head'] as $hedLin ){
		if( stripos($hedLin, 'Set-Cookie: nyuTests=')===0 ){
			$sessCok = 'nyuTests='.strtok(substr($hedLin, strlen('Set-Cookie: nyuTests=')), ';');
		}
	}
	sleep(3);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Confkey'=>'tsshrt') + $sesHead + Array('Cookie'=>$sessCok));
	checkSame('session: expired after its lifetime', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Session expired","code":401}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$sessCok));
	checkSame('session: an expired session was destroyed', $replyAr['code'], 401);

	// Client certificate carrier : the test router stands in for the web server's report (X-Test-Cert)
	$crtHead = Array('X-Test-Runs'=>'1', 'X-Test-Auth'=>'clcert');
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead + Array('X-Test-Cert'=>'SUCCESS;anna;Test CA'));
	checkSame('certificate: a verified certificate names its user', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"member"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead);
	checkSame('certificate: none presented', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"No verified client certificate","code":401}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead + Array('X-Test-Cert'=>'FAILED:certificate has expired;anna;Test CA'));
	checkSame('certificate: failed verification', $replyAr['code'], 401);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead + Array('SSL-Client-Verify'=>'SUCCESS', 'SSL-Client-S-DN-CN'=>'anna', 'SSL_CLIENT_VERIFY'=>'SUCCESS', 'SSL_CLIENT_S_DN_CN'=>'anna'));
	checkSame('certificate: the same values sent as request headers are ignored', $replyAr['code'], 401);

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead + Array('X-Test-Conf'=>'confnat', 'X-Test-Store'=>'native', 'X-Test-Confkey'=>'tsnatv', 'X-Test-Cert'=>'SUCCESS;anna;Test CA'));
	checkSame('certificate with a store: the role comes from the users table', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"manage"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead + Array('X-Test-Conf'=>'confnat', 'X-Test-Store'=>'native', 'X-Test-Confkey'=>'tsnatv', 'X-Test-Cert'=>'SUCCESS;zed;Test CA'));
	checkSame('certificate with a store: a name that is not a user', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Account not active","code":401}'));
	$sqlLink = null;

	// Valid credentials but a JWT secret that cannot sign (test/fixtures/confbad)
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'confbad'), 'user=alice&pass=s3cret');
	checkSame('login with an unusable secret: 500', Array($replyAr['code'], $replyAr['body']), Array(500, '{"error":"Login unavailable","code":500}'));

	// Content types
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Format'=>'yaml'));
	checkSame('yaml format: its type and body', Array($replyAr['code'], $replyAr['type'], preg_match('/^title: Alpha Night$/m', $replyAr['body'])), Array(200, 'application/yaml; charset=utf-8', 1));
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Format'=>'html'));
	checkSame('html format: a page', Array($replyAr['code'], $replyAr['type'], strpos($replyAr['body'], '<!DOCTYPE html>')===0, strpos($replyAr['body'], '<dd><p>Alpha Night</p>')!==false), Array(200, 'text/html; charset=utf-8', true, true));
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Format'=>'tabl'));
	checkSame('tabl format: one record is not a list of them', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(500, $jsonTyp, '{"error":"Response could not be encoded","code":500}'));
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/99', Array('X-Test-Format'=>'tabl'));
	checkSame('tabl format: an error is sent as JSON', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(404, $jsonTyp, '{"error":"Show not found","code":404}'));
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/99', Array('X-Test-Format'=>'xmlx'));
	checkSame('xmlx format: an error is sent as XML', Array($replyAr['code'], $replyAr['type'], preg_match('/<error>Show not found<\/error>/', $replyAr['body'])), Array(404, 'application/xml; charset=utf-8', 1));
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Ctype'=>'application/problem+json'));
	checkSame('a Content-Type set by the preset is kept', $replyAr['type'], 'application/problem+json');

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Ctype'=>'text/csv', 'X-Test-Ctname'=>'content-type'));
	checkLike('a lower-case preset header is not doubled', $replyAr['type'], '#^text/csv#');

	// Request bodies : the probe's creds action answers 401 when user and pass were read from the body, 400 when they were not
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
		$replyAr = httpQuery($oneBdy[0], $baseUrl.'/probe/creds', Array('X-Test-Runs'=>'1', 'Content-Type'=>$oneBdy[1]), $oneBdy[2]);
		checkSame($oneBdy[0].' '.strtok($oneBdy[1], ';').' body', $replyAr['code'], $oneBdy[3]);
	}

	// Uploads are traced on the runner log, whoever read the body : PHP for a POST, the framework for a PUT
	$fleName = 'trace-'.getmypid().'.txt';
	$fileBdy = "------TestBoundary123\r\nContent-Disposition: form-data; name=\"attach\"; filename=\"".$fleName."\"\r\nContent-Type: text/plain\r\n\r\nhello\r\n------TestBoundary123--\r\n";
	foreach( Array('POST', 'PUT') as $mthNme ){
		$logMark = strlen(readsLogs('runner'));
		httpQuery($mthNme, $baseUrl.'/probe/clean', Array('X-Test-Runs'=>'1', 'Content-Type'=>'multipart/form-data; boundary=----TestBoundary123'), $fileBdy);
		checkLike($mthNme.' upload: traced with its name, field, type and size', substr(readsLogs('runner'), $logMark), '/\[INFO\] File received: "'.preg_quote($fleName, '/').'" as "attach" \("text\/plain", 5 bytes\) from \S+/');
	}

	// Header names are case-insensitive : the body is read whatever the case of Content-Type
	$replyAr = httpQuery('PUT', $baseUrl.'/probe/creds', Array('X-Test-Runs'=>'1', 'content-type'=>'application/json'), '{"user":"nobody","pass":"wrong"}');
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
	checkSame('fixture: a default action that does not exist is 500, told no more', Array($replyAr['code'], $replyAr['body']), Array(500, '{"error":"Internal Server Error","code":500}'));
	$replyAr = httpQuery('GET', $baseUrl.'/lost/here', $fixHead);
	checkSame('fixture: its real action still answers', Array($replyAr['code'], $replyAr['body']), Array(200, '{"found":true}'));

	// Declared routes (test/fixtures/routes) : checked before autopath, exact keys then patterns
	$rteHead = Array('X-Test-Runs'=>'1', 'X-Test-Routes'=>'1');
	$noAppli = Array(404, '{"error":"Not Found","code":404}');

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
	$replyAr = httpQuery('GET', $baseUrl.'/probe/text', $rteHead);
	checkSame('route: autopath is the fallback', Array($replyAr['code'], $replyAr['body']), Array(200, '"plain text"'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/clean', $rteHead);
	checkSame('route: but not for an action a declared route answers with', $replyAr['code'], 404);
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
	checkSame('method: an allowed OPTIONS answers with the accepted methods', Array($replyAr['code'], $replyAr['body'], $allowOf($replyAr)), Array(204, '', 'GET, HEAD, OPTIONS'));
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

	$replyAr = httpQuery('POST', $baseUrl.'/probe/text', $rteHead);
	checkSame('method: autopath accepts any method', Array($replyAr['code'], $replyAr['body']), Array(200, '"plain text"'));

	// The project's own routes (conf/routes) : index is a general target
	$replyAr = httpQuery('POST', $baseUrl.'/');
	checkSame('method: POST on the index route is refused', Array($replyAr['code'], $allowOf($replyAr)), Array(405, 'GET'));

	// A failure before the error handler is installed : plain JSON 500, without details
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Preset'=>'nosuchpreset'));
	checkSame('missing preset: generic JSON 500', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(500, $jsonTyp, '{"error":"Internal Server Error","code":500}'));

	$replyAr = httpQuery('GET', $baseUrl.'/blank/here', $fixHead);
	checkSame('fixture: an Actions.php without its actionset is 404', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Not Found","code":404}'));

	// Only the application's own public methods are actions
	foreach( Array('/probe/hidden', '/probe/fixed', '/probe/__construct', '/probe/checkList', '/probe/runAction/clean', '/probe/ownAction/clean') as $notAct ){
		$replyAr = httpQuery('GET', $baseUrl.$notAct, $fixHead);
		checkSame('fixture: '.$notAct.' is not an action: 404', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Not Found","code":404}'));
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

	// Outputs that are not arrays, and data that cannot be encoded
	$replyAr = httpQuery('GET', $baseUrl.'/probe/text', $fixHead);
	checkSame('output: a bare string is sent as JSON', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(200, $jsonTyp, '"plain text"'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/badutf', $fixHead);
	checkSame('output: unencodable data is a JSON 500', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(500, $jsonTyp, '{"error":"Response could not be encoded","code":500}'));

	// File replies : the probe's media action serves the 26 letters of test/fixtures/letters.txt
	$replyAr = httpQuery('GET', $baseUrl.'/probe/media', $fixHead);
	checkSame('file: whole file with its own type', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(200, 'audio/mp4', 'abcdefghijklmnopqrstuvwxyz'));
	checkSame('file: length and range support are announced', Array($headOf($replyAr, 'Content-Length'), $headOf($replyAr, 'Accept-Ranges'), $headOf($replyAr, 'Content-Range')), Array('26', 'bytes', null));
	checkSame('file: preset headers are kept', $headOf($replyAr, 'Access-Control-Allow-Origin'), '*');

	$replyAr = httpQuery('GET', $baseUrl.'/probe/media', $fixHead + Array('Range'=>'bytes=0-4'));
	checkSame('file: a range is 206 with its bytes', Array($replyAr['code'], $replyAr['body'], $headOf($replyAr, 'Content-Range'), $headOf($replyAr, 'Content-Length')), Array(206, 'abcde', 'bytes 0-4/26', '5'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/media', $fixHead + Array('Range'=>'bytes=-3'));
	checkSame('file: last bytes', Array($replyAr['code'], $replyAr['body'], $headOf($replyAr, 'Content-Range')), Array(206, 'xyz', 'bytes 23-25/26'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/media', $fixHead + Array('Range'=>'bytes=99-'));
	checkSame('file: a range outside the file is 416, without body', Array($replyAr['code'], $replyAr['body'], $headOf($replyAr, 'Content-Range')), Array(416, '', 'bytes */26'));
	$replyAr = httpQuery('HEAD', $baseUrl.'/probe/media', $fixHead + Array('Range'=>'bytes=0-4'));
	checkSame('file: HEAD gives the range headers, without body', Array($replyAr['code'], $replyAr['body'], $headOf($replyAr, 'Content-Length')), Array(206, '', '5'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/media', $fixHead + Array('X-Test-Ctype'=>'text/csv'));
	checkSame('file: its type replaces the preset Content-Type', $replyAr['type'], 'audio/mp4');

	$replyAr = httpQuery('GET', $baseUrl.'/probe/media/x', $fixHead);
	checkSame('file: a missing file is a JSON 404', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(404, $jsonTyp, '{"error":"Not Found","code":404}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/mediafail', $fixHead + Array('Range'=>'bytes=0-4'));
	checkSame('file: an error status wins over the file', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(403, $jsonTyp, '{"error":"Not yours","code":403}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/media', $fixHead);
	checkSame('file: the client is told not to guess another type', $headOf($replyAr, 'X-Content-Type-Options'), 'nosniff');

	// Declared routes that run no action : redirection, static file and folder, HTML page
	$htmlTyp = 'text/html; charset=utf-8';
	$noFile = Array(404, $jsonTyp, '{"error":"Not Found","code":404}');

	$replyAr = httpQuery('GET', $baseUrl.'/old/42', $rteHead);
	checkSame('redirection: status, address, no body and no type', Array($replyAr['code'], $headOf($replyAr, 'Location'), $replyAr['body'], $replyAr['type']), Array(301, '/items/42', '', ''));
	checkSame('redirection: preset headers are kept', $headOf($replyAr, 'Access-Control-Allow-Origin'), '*');
	$replyAr = httpQuery('GET', $baseUrl.'/old/a%20b', $rteHead);
	checkSame('redirection: a captured value is encoded again', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(301, '/items/a%20b'));
	$replyAr = httpQuery('GET', $baseUrl.'/away', $rteHead);
	checkSame('redirection: 302 by default, to a full address', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(302, 'https://example.org/docs'));
	$replyAr = httpQuery('HEAD', $baseUrl.'/away', $rteHead);
	checkSame('redirection: HEAD is served as GET', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(302, 'https://example.org/docs'));
	$replyAr = httpQuery('POST', $baseUrl.'/away', $rteHead);
	checkSame('redirection: another method is refused', Array($replyAr['code'], $allowOf($replyAr), $headOf($replyAr, 'Location')), Array(405, 'GET, HEAD', null));
	$replyAr = httpQuery('POST', $baseUrl.'/resend', $rteHead);
	checkSame('redirection: of another method, when allowed', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(307, '/both'));
	$replyAr = httpQuery('GET', $baseUrl.'/badcode', $rteHead);
	checkSame('redirection: a wrong status is a 500, without address', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(500, null));
	$replyAr = httpQuery('GET', $baseUrl.'/old/42', $rteHead + Array('X-Test-Auth'=>'jtoken'));
	checkSame('redirection: closed when access is required', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(401, null));

	$replyAr = httpQuery('GET', $baseUrl.'/style', $rteHead);
	checkSame('static: a declared file with its type', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(200, 'text/css; charset=utf-8', 'body { margin: 0; }'));
	checkSame('static: the client is told not to guess another type', $headOf($replyAr, 'X-Content-Type-Options'), 'nosniff');
	$replyAr = httpQuery('HEAD', $baseUrl.'/style', $rteHead);
	checkSame('static: HEAD gives the headers, without body', Array($replyAr['code'], $replyAr['type'], $replyAr['body'], $headOf($replyAr, 'Content-Length')), Array(200, 'text/css; charset=utf-8', '', '19'));
	$replyAr = httpQuery('POST', $baseUrl.'/style', $rteHead);
	checkSame('static: POST is refused', Array($replyAr['code'], $allowOf($replyAr)), Array(405, 'GET, HEAD'));
	$replyAr = httpQuery('GET', $baseUrl.'/nofile', $rteHead);
	checkSame('static: a missing file is a JSON 404', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), $noFile);
	$replyAr = httpQuery('GET', $baseUrl.'/secret', $rteHead);
	checkSame('static: a file declared outside the root is a 500', Array($replyAr['code'], strpos($replyAr['body'], 'abcdefghijklmnopqrstuvwxyz')), Array(500, false));
	$replyAr = httpQuery('GET', $baseUrl.'/style', $rteHead + Array('X-Test-Auth'=>'jtoken'));
	checkSame('static: closed when access is required', $replyAr['code'], 401);

	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $rteHead);
	checkSame('folder: a file with its type', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(200, 'text/plain; charset=utf-8', 'abcdefghijklmnopqrstuvwxyz'));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $rteHead + Array('Range'=>'bytes=0-4'));
	checkSame('folder: a range of a file', Array($replyAr['code'], $replyAr['body'], $headOf($replyAr, 'Content-Range')), Array(206, 'abcde', 'bytes 0-4/26'));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/deep/note.txt', $rteHead);
	checkSame('folder: a file of a sub-folder', Array($replyAr['code'], $replyAr['body']), Array(200, 'deep note'));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/', $rteHead);
	checkSame('folder: the folder itself gives its index', Array($replyAr['code'], $replyAr['type'], strpos($replyAr['body'], 'Docs index')!==false), Array(200, $htmlTyp, true));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/deep', $rteHead);
	checkSame('folder: a sub-folder without index is never listed', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), $noFile);
	foreach( Array(
		'parent folder'=>'/docs/../site.css',
		'encoded parent folder'=>'/docs/%2e%2e/site.css',
		'encoded slash'=>'/docs/..%2Fsite.css',
		'encoded backslash'=>'/docs/..%5Csite.css',
		'double encoding'=>'/docs/%252e%252e/site.css',
		'up to the fixtures'=>'/docs/../../letters.txt',
		'backslash as a separator'=>'/docs/deep%5Cnote.txt',
		'null byte'=>'/docs/guide.txt%00.html',
		'hidden file'=>'/docs/.hidden.txt',
		'script'=>'/docs/script.php',
		'extension without type'=>'/docs/data.ini'
	) as $tryNme=>$tryUrl ){
		$replyAr = httpQuery('GET', $baseUrl.$tryUrl, $rteHead);
		checkSame('folder: no way out, '.$tryNme, Array($replyAr['code'], $replyAr['type'], $replyAr['body']), $noFile);
	}

	$replyAr = httpQuery('GET', $baseUrl.'/hello/7', $rteHead);
	checkSame('page: sent as HTML, its placeholder filled', Array($replyAr['code'], $replyAr['type'], strpos($replyAr['body'], '<p>Hello 7, %unknown% at 100%</p>')!==false), Array(200, $htmlTyp, true));
	checkSame('page: the client is told not to guess another type', $headOf($replyAr, 'X-Content-Type-Options'), 'nosniff');
	$replyAr = httpQuery('GET', $baseUrl.'/hello/%3Cb%3E%22x%22', $rteHead);
	checkSame('page: a captured value is encoded', strpos($replyAr['body'], '<p>Hello &lt;b&gt;&quot;x&quot;, ')!==false, true);
	$replyAr = httpQuery('GET', $baseUrl.'/hello/7', $rteHead + Array('X-Test-Ctype'=>'text/csv'));
	checkSame('page: its type replaces the preset Content-Type', $replyAr['type'], $htmlTyp);
	$replyAr = httpQuery('HEAD', $baseUrl.'/hello/7', $rteHead);
	checkSame('page: HEAD gives the headers, without body', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(200, $htmlTyp, ''));
	$replyAr = httpQuery('GET', $baseUrl.'/notpage', $rteHead);
	checkSame('page: a file that is not HTML is a 500', $replyAr['code'], 500);
	$replyAr = httpQuery('GET', $baseUrl.'/enter', $rteHead);
	checkSame('page: one kind per method, GET gets the page', Array($replyAr['code'], $replyAr['type']), Array(200, $htmlTyp));
	$replyAr = httpQuery('POST', $baseUrl.'/enter', $rteHead);
	checkSame('page: one kind per method, POST runs the action', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(200, $jsonTyp, '{"ok":true}'));

	// A declared route opened by its path, when access is required
	$pthHead = $rteHead + Array('X-Test-Auth'=>'jtoken', 'X-Test-Public'=>'/enter, /docs/*');
	$replyAr = httpQuery('GET', $baseUrl.'/enter', $pthHead);
	checkSame('public path: the page is served without credentials', Array($replyAr['code'], $replyAr['type']), Array(200, $htmlTyp));
	$replyAr = httpQuery('POST', $baseUrl.'/enter', $pthHead);
	checkSame('public path: and so is its action', Array($replyAr['code'], $replyAr['body']), Array(200, '{"ok":true}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/clean', $pthHead);
	checkSame('public path: the same action stays closed on another path', $replyAr['code'], 401);
	$replyAr = httpQuery('GET', $baseUrl.'/hello/7', $pthHead);
	checkSame('public path: another page stays closed', $replyAr['code'], 401);
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $pthHead);
	checkSame('public path: a folder route is opened with its files', Array($replyAr['code'], $replyAr['body']), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/../site.css', $pthHead);
	checkSame('public path: an open folder still has no way out', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Not Found","code":404}'));
	$replyAr = httpQuery('GET', $baseUrl.'/style', $pthHead);
	checkSame('public path: a file outside the open folder stays closed', $replyAr['code'], 401);

	// A request denied a page is sent to the sign-in route, with the page it asked for
	$sgnHead = $pthHead + Array('X-Test-Signin'=>'/enter');
	$replyAr = httpQuery('GET', $baseUrl.'/hello/7?tab=a', $sgnHead);
	checkSame('sign-in: a denied page is a redirection, without a body', Array($replyAr['code'], $headOf($replyAr, 'Location'), $replyAr['body']), Array(302, '/enter?back=%2Fhello%2F7%3Ftab%3Da', ''));
	$replyAr = httpQuery('GET', $baseUrl.'/enter?back=%2Fhello%2F7%3Ftab%3Da', $sgnHead);
	checkSame('sign-in: its route is served', Array($replyAr['code'], $replyAr['type']), Array(200, $htmlTyp));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/clean', $sgnHead);
	checkSame('sign-in: a denied action keeps its error', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(401, null));
	$replyAr = httpQuery('GET', $baseUrl.'/style', $sgnHead);
	checkSame('sign-in: so does a denied file', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(401, null));
	$replyAr = httpQuery('GET', $baseUrl.'/enter', $rteHead + Array('X-Test-Auth'=>'jtoken', 'X-Test-Signin'=>'/enter'));
	checkSame('sign-in: a route left closed is not sent to itself', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(401, null));
	$replyAr = httpQuery('GET', $baseUrl.'/hello/7', $pthHead + Array('X-Test-Signin'=>'https://example.org/enter'));
	checkSame('sign-in: an address elsewhere is a misconfiguration', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(500, null));

	// Static files : kept by the client, which asks whether they changed
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $rteHead);
	$fileTag = strval($headOf($replyAr, 'ETag'));
	$fileDat = strval($headOf($replyAr, 'Last-Modified'));
	checkLike('cache: the file has a version', $fileTag, '/^"[0-9a-f]+-1a"$/');
	checkLike('cache: and a date', $fileDat, '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/');
	checkSame('cache: its own cache headers replace those of the preset', Array($headOf($replyAr, 'Cache-Control'), $headOf($replyAr, 'Pragma'), $headOf($replyAr, 'Expires')), Array('public, no-cache', null, null));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $rteHead + Array('If-None-Match'=>$fileTag));
	checkSame('cache: the same version is a 304, without body or type', Array($replyAr['code'], $replyAr['body'], $replyAr['type'], $headOf($replyAr, 'Content-Length')), Array(304, '', '', null));
	checkSame('cache: a 304 repeats the version and the cache headers', Array($headOf($replyAr, 'ETag'), $headOf($replyAr, 'Cache-Control')), Array($fileTag, 'public, no-cache'));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $rteHead + Array('If-None-Match'=>'"other"'));
	checkSame('cache: another version gets the file', Array($replyAr['code'], $replyAr['body']), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $rteHead + Array('If-Modified-Since'=>$fileDat));
	checkSame('cache: not modified since its date is a 304', Array($replyAr['code'], $replyAr['body']), Array(304, ''));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $rteHead + Array('If-Modified-Since'=>'Thu, 01 Jan 1970 00:00:10 GMT'));
	checkSame('cache: modified since an older date gets the file', $replyAr['code'], 200);
	$replyAr = httpQuery('HEAD', $baseUrl.'/docs/guide.txt', $rteHead + Array('If-None-Match'=>$fileTag));
	checkSame('cache: HEAD is answered 304 too', $replyAr['code'], 304);
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $rteHead + Array('Range'=>'bytes=0-4', 'If-Range'=>$fileTag));
	checkSame('cache: a range of the same version', Array($replyAr['code'], $replyAr['body']), Array(206, 'abcde'));
	$replyAr = httpQuery('GET', $baseUrl.'/docs/guide.txt', $rteHead + Array('Range'=>'bytes=0-4', 'If-Range'=>'"other"'));
	checkSame('cache: a range of another version gets the whole file', Array($replyAr['code'], $replyAr['body']), Array(200, 'abcdefghijklmnopqrstuvwxyz'));
	$replyAr = httpQuery('GET', $baseUrl.'/nofile', $rteHead + Array('If-None-Match'=>'*'));
	checkSame('cache: a missing file stays a 404', Array($replyAr['code'], $headOf($replyAr, 'ETag')), Array(404, null));
	$replyAr = httpQuery('GET', $baseUrl.'/hello/7', $rteHead);
	checkSame('cache: a page has no version', $headOf($replyAr, 'ETag'), null);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/media', $fixHead);
	checkSame('cache: a file sent by an action keeps the cache headers of the preset', Array($headOf($replyAr, 'ETag'), $headOf($replyAr, 'Pragma')), Array(null, 'no-cache'));

	// Actionset helpers : redirection, page reply, link to a route
	$actHead = $fixHead + Array('X-Test-Routes'=>'1');
	$replyAr = httpQuery('GET', $baseUrl.'/probe/moved', $actHead);
	checkSame('helper: a redirection ends the action, without body', Array($replyAr['code'], $headOf($replyAr, 'Location'), $replyAr['body'], $replyAr['type']), Array(302, '/probe/clean', '', ''));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/moved/out', $actHead);
	checkSame('helper: a redirection elsewhere, with its status', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(303, 'https://example.org/'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/moved/bad', $actHead);
	checkSame('helper: an address without scheme is a 500, without address', Array($replyAr['code'], $headOf($replyAr, 'Location')), Array(500, null));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/page', $actHead);
	checkSame('helper: a page sent as HTML', Array($replyAr['code'], $replyAr['type']), Array(200, $htmlTyp));
	checkSame('helper: its values encoded and its link built', strpos($replyAr['body'], '<h1>&lt;b&gt;Tom &amp; &quot;Jerry&quot;&lt;/b&gt;</h1><a href="/items/a%20b?tab=x%20y">next</a>')!==false, true);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/page/gone', $actHead);
	checkSame('helper: a page with its own status', Array($replyAr['code'], $replyAr['type'], strpos($replyAr['body'], '<h1>Gone</h1>')!==false), Array(404, $htmlTyp, true));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/page/none', $actHead);
	checkSame('helper: a missing page is a JSON 404', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(404, $jsonTyp, '{"error":"Not Found","code":404}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/page/fail', $actHead);
	checkSame('helper: an error status wins over the page', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(403, $jsonTyp, '{"error":"Not yours","code":403}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/link', $actHead);
	checkSame('helper: the link to a declared route', Array($replyAr['code'], $replyAr['body']), Array(200, '{"link":"\/things\/7"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/link/x', $actHead);
	checkSame('helper: a link to an undeclared route is a 500', $replyAr['code'], 500);

	// "No content" : a real 204 when the action sets no output, even after a warning
	$replyAr = httpQuery('GET', $baseUrl.'/probe/vanish', $fixHead);
	checkSame('no content: 204 without body or type', Array($replyAr['code'], $replyAr['body'], $replyAr['type']), Array(204, '', ''));
	checkSame('no content: preset headers are kept', $headOf($replyAr, 'Access-Control-Allow-Origin'), '*');

	// Stopping an action from a helper : the stop decides the status, and the output when it carries one
	$replyAr = httpQuery('GET', $baseUrl.'/probe/halt/fail', $fixHead);
	checkSame('stop: an error status with its message', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(404, $jsonTyp, '{"error":"Stopped early","code":404}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/halt/full', $fixHead);
	checkSame('stop: its own output replaces the earlier one', Array($replyAr['code'], $replyAr['body']), Array(200, '{"done":true}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/halt/part', $fixHead);
	checkSame('stop: without output, what was set so far is sent', Array($replyAr['code'], $replyAr['body']), Array(200, '{"step":1}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/halt/none', $fixHead);
	checkSame('stop: no content when nothing was set', Array($replyAr['code'], $replyAr['body'], $replyAr['type']), Array(204, '', ''));

	// Limit by address (test/fixtures/conflim : two wrong credentials allowed) : the address is then answered 429
	dropFoldr(TOP_DIR.DS.'temp'.DS.'limits_http');
	$limHead = $formHed + Array('X-Test-Conf'=>'conflim');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $limHead, 'user=alice&pass=nope');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $limHead, 'user=alice&pass=nope');
	checkSame('address limit: the second wrong password is still told as such', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $limHead, 'user=alice&pass=s3cret');
	checkSame('address limit: the login is then refused, even with the right password', Array($replyAr['code'], $replyAr['body']), Array(429, '{"error":"Too many attempts","code":429}'));
	checkTrue('address limit: the login tells how long to wait', intval($headOf($replyAr, 'Retry-After'))>=1 && intval($headOf($replyAr, 'Retry-After'))<=600);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'conflim', 'X-Test-Auth'=>'header', 'X-Test-Confkey'=>'tskeys', 'X-Api-Key'=>'test-only-key-1'));
	checkSame('address limit: a checked route is refused too, even with the right key', Array($replyAr['code'], $replyAr['body']), Array(429, '{"error":"Too many attempts","code":429}'));
	checkTrue('address limit: the checked route tells how long to wait', intval($headOf($replyAr, 'Retry-After'))>=1 && intval($headOf($replyAr, 'Retry-After'))<=600);
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'conf'), 'user=alice&pass=s3cret');
	checkSame('address limit: a configuration without a limit is not held', $replyAr['code'], 200);
	dropFoldr(TOP_DIR.DS.'temp'.DS.'limits_http');

	// Forged tokens are counted like wrong passwords; a token that ended is not
	$tknHead = Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'conflim', 'X-Test-Auth'=>'jtoken', 'X-Test-Confkey'=>'tstokn');
	$endHead = $tknHead + Array('Authorization'=>'Bearer '.Firebase\JWT\JWT::encode(Array('user'=>'alice', 'exp'=>time()-10), 'test-only-secret-0123456789-abcdefghij', 'HS256'));
	$codeLst = Array();
	for( $idx=0; $idx<3; $idx++ ){
		$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $endHead);
		$codeLst[] = $replyAr['code'];
	}
	checkSame('address limit: a token that ended is not counted', Array($codeLst, $replyAr['body']), Array(Array(401, 401, 401), '{"error":"Expired token","code":401}'));
	$codeLst = Array();
	for( $idx=0; $idx<3; $idx++ ){
		$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $tknHead + Array('Authorization'=>'Bearer '.Firebase\JWT\JWT::encode(Array('user'=>'alice', 'exp'=>time()+60), str_repeat('x', 40), 'HS256')));
		$codeLst[] = $replyAr['code'];
	}
	checkSame('address limit: forged tokens hold the address', $codeLst, Array(401, 401, 429));
	dropFoldr(TOP_DIR.DS.'temp'.DS.'limits_http');
}
dropLocal();

if( $server!==null ){
	proc_terminate($server);
	proc_close($server);
}

endChecks();
