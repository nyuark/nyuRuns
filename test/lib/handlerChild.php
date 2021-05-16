<?php

// Child process for test/unit/ErrorHandlerTest.php. The error handler takes
// over PHP's error, exception and shutdown handling, so each scenario runs in
// its own process:
//
//   php handlerChild.php <mode> <log folder>

$topDir = dirname(__DIR__, 2);
$runMode = $argv[1];
$logsDir = $argv[2];

define('DS', DIRECTORY_SEPARATOR);
require $topDir.'/vendor/autoload.php';

if( $runMode!='nologger' ){
	$GLOBALS['nyuLogger'] = new nyu\Tools\Logger($logsDir, 'err', 'BASE', 0, 'NUMBER', 'Y');
}
require $topDir.'/src/Error/handling.php';

if( $runMode=='reply' ){
	// Exception -> formatted reply path. Nothing may be printed before the
	// end: headers_sent() turns true on the command line as soon as there is output.
	$results = Array();
	$sentLst = Array();
	$replyFn = function( String $errMessage ) use (&$sentLst){ $sentLst[] = $errMessage; };
	$brokeFn = function( String $errMessage ){ throw new LogicException('responder broken'); };
	$caseLst = Array(
		'responder, show off'    => Array(false, $replyFn),
		'responder, show on'     => Array(true, $replyFn),
		'no responder, show on'  => Array(true, null),
		'no responder, show off' => Array(false, null),
		'broken responder, show on' => Array(true, $brokeFn),
	);
	foreach( $caseLst as $casNme=>$casSet ){
		$sentLst = Array();
		$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array('system'=>Array('ERR_SHOW'=>$casSet[0])) );
		unset($GLOBALS['ErrorResponse']);
		if( $casSet[1]!==null ){
			$GLOBALS['ErrorResponse'] = $casSet[1];
		}
		ob_start();
		hndlExcpt( new RuntimeException('boom', 42) );
		$results[$casNme] = Array('sent'=>$sentLst, 'echo'=>trim(preg_replace('/\s+/', ' ', strip_tags(ob_get_clean()))));
	}
	echo json_encode($results);
	exit(0);
}

initError();

if( $runMode=='warning' ){
	trigger_error('custom warning', E_USER_WARNING);
	$emptyAr = Array();
	$missing = $emptyAr['missing'];
	echo 'execution continued';
}elseif( $runMode=='exception' ){
	throw new RuntimeException('boom', 42);
}elseif( $runMode=='pdoexception' ){
	$pdoLink = new PDO('sqlite::memory:');
	$pdoLink->query('SELECT * FROM no_such_table');
}elseif( $runMode=='fatal' ){
	undefined_function_call();
}elseif( $runMode=='memfatal' ){
	ini_set('memory_limit', '16M');
	$bigText = str_repeat('x', 64*1024*1024);
}elseif( $runMode=='nologger' ){
	trigger_error('shown inline', E_USER_NOTICE);
}
