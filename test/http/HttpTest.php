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
}

proc_terminate($server);
proc_close($server);

endChecks();
