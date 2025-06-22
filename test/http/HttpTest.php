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
	$keyMthd->setAccessible(true);
	$pthMthd = new ReflectionMethod($limiter, 'pathOfKey');
	$pthMthd->setAccessible(true);
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

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login');
	checkSame('login without credentials: 400', Array($replyAr['code'], $replyAr['body']), Array(400, '{"error":"Missing or invalid input: user, pass","code":400}'));

	$formHed = Array('Content-Type'=>'application/x-www-form-urlencoded');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed, 'user[]=nobody&pass=x');
	checkSame('login with a user that is not a string: 400', $replyAr['code'], 400);

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed, 'user=nobody-here&pass=wrong');
	checkSame('login with unknown credentials: 401', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', Array('Content-Type'=>'application/json'), '{"user":"nobody-here","pass":"wrong"}');
	checkSame('login with a JSON body: 401', $replyAr['code'], 401);

	$replyAr = httpQuery('GET', $baseUrl.'/auth/login?user=nobody-here&pass=wrong');
	checkSame('login by GET: 405', Array($replyAr['code'], $replyAr['body']), Array(405, '{"error":"Method Not Allowed","code":405}'));
	checkTrue('login by GET: POST is announced', in_array('Allow: POST', $replyAr['head']));

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

	$replyAr = httpQuery('POST', $baseUrl.'/auth');
	checkSame('no action, no declared default: the first action (login) runs', Array($replyAr['code'], $replyAr['body']), Array(400, '{"error":"Missing or invalid input: user, pass","code":400}'));

	// The login action refuses anything but a POST by itself, whatever the path that leads to it
	$replyAr = httpQuery('GET', $baseUrl.'/auth?user=nobody-here&pass=wrong');
	checkSame('login by GET through the default action: 405', Array($replyAr['code'], $replyAr['body']), Array(405, '{"error":"Method Not Allowed","code":405}'));
	$replyAr = httpQuery('GET', $baseUrl.'/Auth/login?user=nobody-here&pass=wrong');
	checkSame('login by GET through another case of the path: 405', Array($replyAr['code'], $replyAr['body']), Array(405, '{"error":"Method Not Allowed","code":405}'));

	$replyAr = httpQuery('GET', $baseUrl.'/nothing/here');
	checkSame('unknown application: 404', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"Unknown Application","code":404}'));

	// Uncaught exception (view called without its parameter)
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view');
	$errVals = json_decode($replyAr['body'], true);
	checkSame('uncaught exception: 500 in the configured format', Array($replyAr['code'], $replyAr['type'], $errVals['code'] ?? null), Array(500, $jsonTyp, 500));
	checkTrue('uncaught exception: carries an error message', is_string($errVals['error'] ?? null) && $errVals['error']!='');

	// Access control
	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'header'));
	checkSame('access denied: 401 with an error body', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Missing X-Api-Key header","code":401}'));

	$replyAr = httpQuery('GET', $baseUrl.'/shows/view/1', Array('X-Test-Auth'=>'hbasic'));
	checkSame('Basic access denied: 401', $replyAr['code'], 401);
	checkTrue('Basic access denied: challenge header', in_array('WWW-Authenticate: Basic', $replyAr['head']));

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

	// Identity handed to the application
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Runs'=>'1'));
	checkSame('identity: no access control, not logged', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":false,"user":"","role":null}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Runs'=>'1', 'X-Test-Auth'=>'jtoken', 'X-Test-Public'=>'probe/whoami'));
	checkSame('identity: public route, not logged', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":false,"user":"","role":null}'));

	// The API key block of test/fixtures/conf/access.json
	$keyHead = Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'conf', 'X-Test-Auth'=>'header', 'X-Test-Confkey'=>'tskeys');
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $keyHead + Array('X-Api-Key'=>'test-only-key-1'));
	checkSame('identity: valid API key, logged as a member', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"","role":"membr"}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $keyHead + Array('X-Api-Key'=>'not-the-key'));
	checkSame('identity: invalid API key, the action does not run', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid API key","code":401}'));

	// Login round trip : htpasswd store and JWT of test/fixtures/conf
	$whoHead = Array('X-Test-Runs'=>'1', 'X-Test-Auth'=>'jtoken', 'X-Test-Confkey'=>'tstokn');
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'conf'), 'user=alice&pass=s3cret');
	$jwToken = strval(json_decode($replyAr['body'], true)['token'] ?? '');
	checkSame('round trip: login answers 200', $replyAr['code'], 200);
	checkLike('round trip: login gives a token', $jwToken, '/^[\w-]+\.[\w-]+\.[\w-]+$/');

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $whoHead + Array('X-Test-Conf'=>'conf', 'Authorization'=>'Bearer '.$jwToken));
	checkSame('round trip: the token identifies its user', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"alice","role":"membr"}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $whoHead + Array('X-Test-Conf'=>'conf', 'Authorization'=>'Bearer '.$jwToken.'x'));
	checkSame('round trip: a tampered token is refused', $replyAr['code'], 401);

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'conf'), 'user=alice&pass=nope');
	checkSame('round trip: wrong password, no token', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Invalid credentials","code":401}'));

	// Login round trip : native userbase of the sample database (test/fixtures/confnat)
	$replyAr = httpQuery('GET', $baseUrl.'/probe/online', Array('X-Test-Runs'=>'1'));
	checkSame('presence: nobody is online before any login', Array($replyAr['code'], $replyAr['body']), Array(200, '{"online":[]}'));

	$logMark = strlen(readsLogs('access'));
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'confnat'), 'user=anna&pass=pw-anna');
	$jwToken = strval(json_decode($replyAr['body'], true)['token'] ?? '');
	checkSame('native round trip: login answers 200', $replyAr['code'], 200);

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $whoHead + Array('X-Test-Conf'=>'confnat', 'Authorization'=>'Bearer '.$jwToken));
	checkSame('native round trip: the role of the users table reaches the action', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"admin"}'));

	// The login and the access of a privileged user are notices of the access log
	$newLogs = substr(readsLogs('access'), $logMark);
	checkLike('access log: privileged login', $newLogs, '/\[NOTICE\] Privileged login granted for user "anna" \(admin\) from \S+/');
	checkLike('access log: privileged access, with what was asked for', $newLogs, '/\[NOTICE\] Privileged access granted for user "anna" \(admin\) from \S+ to probe\/whoami/i');

	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'confnat'), 'user=cleo&pass=pw-cleo');
	checkSame('native round trip: a pending user gets no token', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Account not active","code":401}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/online', Array('X-Test-Runs'=>'1'));
	checkSame('presence: the user who logged in is online', $replyAr['body'], '{"online":["anna"]}');

	// A request verified by Basic credentials against the users table stamps its user too
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'confnat', 'X-Test-Auth'=>'hbasic', 'X-Test-Store'=>'native', 'X-Test-Confkey'=>'tsnatv', 'Authorization'=>'Basic '.base64_encode('ben:pw-ben')));
	checkSame('presence: Basic access on the users table', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"ben","role":"membr"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/online', Array('X-Test-Runs'=>'1'));
	checkSame('presence: the user of a verified request is online', $replyAr['body'], '{"online":["anna","ben"]}');

	// A token confirmed against the users table : a role change or a lock applies to a token already issued
	$sqlLink = new PDO('sqlite:'.$sqlFile);
	$storHed = $whoHead + Array('X-Test-Conf'=>'confnat', 'X-Test-Store'=>'native', 'Authorization'=>'Bearer '.$jwToken);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $storHed);
	checkSame('token with a store: accepted', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"admin"}'));

	$sqlLink->exec("UPDATE users SET userRole='systm' WHERE userName='anna'");
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $storHed);
	checkSame('token with a store: a role change applies at once', $replyAr['body'], '{"logged":true,"user":"anna","role":"systm"}');

	$sqlLink->exec("UPDATE users SET userStat='locked' WHERE userName='anna'");
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $storHed);
	checkSame('token with a store: a locked user is refused at once', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Account not active","code":401}'));

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $whoHead + Array('X-Test-Conf'=>'confnat', 'Authorization'=>'Bearer '.$jwToken));
	checkSame('token without a store: still accepted until it expires', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"admin"}'));
	$sqlLink->exec("UPDATE users SET userStat='active', userRole='admin' WHERE userName='anna'");

	// Personal API token of the users table, sent in the API key header
	$sqlLink->exec("UPDATE users SET tokenKey='".hash('sha256', 'http-test-token')."' WHERE userName='anna'");
	$tknHead = Array('X-Test-Runs'=>'1', 'X-Test-Conf'=>'confnat', 'X-Test-Auth'=>'header', 'X-Test-Store'=>'native', 'X-Test-Confkey'=>'tsnatv');
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $tknHead + Array('X-Api-Key'=>'http-test-token'));
	checkSame('personal token: identifies its user', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"admin"}'));
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
	checkSame('session: the cookie identifies its user', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"admin"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>$sessCok));
	checkSame('session: it stays open on the next request', $replyAr['code'], 200);

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead);
	checkSame('session: no cookie', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"No active session","code":401}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>'nyuTests='.str_repeat('a', 32)));
	checkSame('session: an id that was never issued is not adopted', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"No active session","code":401}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $sesHead + Array('Cookie'=>'nyuTests=../../etc/passwd'));
	checkSame('session: a malformed id is refused', $replyAr['code'], 401);

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
	checkSame('certificate: a verified certificate names its user', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"membr"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead);
	checkSame('certificate: none presented', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"No verified client certificate","code":401}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead + Array('X-Test-Cert'=>'FAILED:certificate has expired;anna;Test CA'));
	checkSame('certificate: failed verification', $replyAr['code'], 401);
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead + Array('SSL-Client-Verify'=>'SUCCESS', 'SSL-Client-S-DN-CN'=>'anna', 'SSL_CLIENT_VERIFY'=>'SUCCESS', 'SSL_CLIENT_S_DN_CN'=>'anna'));
	checkSame('certificate: the same values sent as request headers are ignored', $replyAr['code'], 401);

	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead + Array('X-Test-Conf'=>'confnat', 'X-Test-Store'=>'native', 'X-Test-Confkey'=>'tsnatv', 'X-Test-Cert'=>'SUCCESS;anna;Test CA'));
	checkSame('certificate with a store: the role comes from the users table', Array($replyAr['code'], $replyAr['body']), Array(200, '{"logged":true,"user":"anna","role":"admin"}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/whoami', $crtHead + Array('X-Test-Conf'=>'confnat', 'X-Test-Store'=>'native', 'X-Test-Confkey'=>'tsnatv', 'X-Test-Cert'=>'SUCCESS;zed;Test CA'));
	checkSame('certificate with a store: a name that is not a user', Array($replyAr['code'], $replyAr['body']), Array(401, '{"error":"Account not active","code":401}'));
	$sqlLink = null;

	// Valid credentials but a JWT secret that cannot sign (test/fixtures/confbad)
	$replyAr = httpQuery('POST', $baseUrl.'/auth/login', $formHed + Array('X-Test-Conf'=>'confbad'), 'user=alice&pass=s3cret');
	checkSame('login with an unusable secret: 500', Array($replyAr['code'], $replyAr['body']), Array(500, '{"error":"Login unavailable","code":500}'));

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
		checkLike($mthNme.' upload: traced with its name, field, type and size', substr(readsLogs('runner'), $logMark), '/\[INFO\] File received: "'.preg_quote($fleName, '/').'" as "attach" \(text\/plain, 5 bytes\) from \S+/');
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

	// Outputs that are not arrays, and data that cannot be encoded
	$replyAr = httpQuery('GET', $baseUrl.'/probe/text', $fixHead);
	checkSame('output: a bare string is sent as JSON', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(200, $jsonTyp, '"plain text"'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/badutf', $fixHead);
	checkSame('output: unencodable data is a JSON 500', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(500, $jsonTyp, '{"error":"Response could not be encoded","code":500}'));

	// File replies : the probe's media action serves the 26 letters of test/fixtures/letters.txt
	$headOf = function( Array $replyArray, String $headerName ): ?String {
		foreach( $replyArray['head'] as $hedLin ){
			if( stripos($hedLin, $headerName.':')===0 ){
				return trim(substr($hedLin, strlen($headerName)+1));
			}
		}
		return null;
	};
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
	checkSame('file: a missing file is a JSON 404', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(404, $jsonTyp, '{"error":"File not found","code":404}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/mediafail', $fixHead + Array('Range'=>'bytes=0-4'));
	checkSame('file: an error status wins over the file', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(403, $jsonTyp, '{"error":"Not yours","code":403}'));
	$replyAr = httpQuery('GET', $baseUrl.'/probe/media', $fixHead);
	checkSame('file: the client is told not to guess another type', $headOf($replyAr, 'X-Content-Type-Options'), 'nosniff');

	// Declared routes that run no action : redirection, static file and folder, HTML page
	$htmlTyp = 'text/html; charset=utf-8';
	$noFile = Array(404, $jsonTyp, '{"error":"File not found","code":404}');

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
	checkSame('public path: an open folder still has no way out', Array($replyAr['code'], $replyAr['body']), Array(404, '{"error":"File not found","code":404}'));
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
	checkSame('helper: a missing page is a JSON 404', Array($replyAr['code'], $replyAr['type'], $replyAr['body']), Array(404, $jsonTyp, '{"error":"Page not found","code":404}'));
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
}
dropLocal();

if( $server!==null ){
	proc_terminate($server);
	proc_close($server);
}

endChecks();
