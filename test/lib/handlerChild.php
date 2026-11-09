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

if( $runMode!='nologger' && $runMode!='block' ){
	// The 'trace' scenario reads the context of an entry, which only the FULL mode writes
	$GLOBALS['nyuLogger'] = new nyu\Tools\Logger($logsDir, 'err', ($runMode=='trace') ? 'FULL' : 'BASE', 0, 'NUMBER', 'Y');
}
require $topDir.'/src/Error/handling.php';

if( $runMode=='block' ){
	// The HTML display of an error : the stack table, and the one block both displays echo
	$traceAr = Array(
		Array('file'=>'C:\\app\\<b>run</b>.php', 'line'=>12, 'function'=>'login', 'class'=>'Auth<i>', 'type'=>'->', 'args'=>Array('root', 'hunter2')),
		Array('file'=>'/srv/index.php', 'line'=>3, 'function'=>'main', 'args'=>Array(Array('secret'=>'hunter2'))),
		Array('function'=>'{closure}'),
	);
	$results = Array(
		'table'=>stackTableText($traceAr),
		'empty'=>stackTableText(Array()),
		'trace'=>errorBlockText('E_EXCEPTION', "<script>x</script>\r\nsecond", 'a&b.php', 7, $traceAr),
		'text'=>errorBlockText(E_USER_WARNING, 'm', null, null, '#0 first <x>#1 second'),
		'scope'=>errorBlockText(E_WARNING, 'm', 'f.php', 1, Array('secret'=>'hunter2')),
	);
	ob_start();
	printErrorHtml('E_EXCEPTION', 'boom', 'f.php', 9, $traceAr);
	$results['show'] = ob_get_clean();
	ob_start();
	$GLOBALS['ErrorHandler']->handleOneError('E_EXCEPTION', 'boom', 'f.php', 9, $traceAr);
	$results['push'] = ob_get_clean();
	$results['same'] = errorBlockText('E_EXCEPTION', 'boom', 'f.php', 9, $traceAr);
	echo json_encode($results);
	exit(0);
}

if( $runMode=='reply' ){
	// Exception -> formatted reply path. Nothing may be printed before the
	// end: headers_sent() turns true on the command line as soon as there is output.
	$results = Array();
	$sentLst = Array();
	$replier = function( String $errorMessage ) use (&$sentLst){ $sentLst[] = $errorMessage; };
	$failing = function( String $errorMessage ){ throw new LogicException('responder broken'); };
	$caseLst = Array(
		'responder, show off'    => Array(false, $replier),
		'responder, show on'     => Array(true, $replier),
		'no responder, show on'  => Array(true, null),
		'no responder, show off' => Array(false, null),
		'broken responder, show on' => Array(true, $failing),
		'responder, show on, in production' => Array(true, $replier, 'prod'),
		'no responder, show on, in production' => Array(true, null, 'PROD'),
		'responder, show on, in preview' => Array(true, $replier, 'PREV'),
	);
	foreach( $caseLst as $casNme=>$casSet ){
		$sentLst = Array();
		$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array('system'=>Array('ERR_SHOW'=>$casSet[0], 'ENV_NAME'=>$casSet[2] ?? 'DEV')) );
		unset($GLOBALS['ErrorResponse']);
		if( $casSet[1]!==null ){
			$GLOBALS['ErrorResponse'] = $casSet[1];
		}
		ob_start();
		treatException( new RuntimeException('boom', 42) );
		$results[$casNme] = Array('sent'=>$sentLst, 'echo'=>trim(preg_replace('/\s+/', ' ', strip_tags(ob_get_clean()))));
	}
	echo json_encode($results);
	exit(0);
}

if( $runMode=='stack' ){
	// Warnings inside a framework request (a responder is registered) are kept
	// for the response body instead of echoed
	$results = Array();
	foreach( Array('show on'=>true, 'show off'=>false) as $casNme=>$shwVal ){
		$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array('system'=>Array('ERR_SHOW'=>$shwVal)) );
		$GLOBALS['ErrorResponse'] = function( String $errorMessage ){};
		ob_start();
		handleErrorFlg(E_USER_WARNING, 'kept warning', 'file.php', 12);
		handleErrorFlg(E_NOTICE, 'kept notice', 'file.php', 13);
		$results[$casNme] = Array(
			'echo'=>trim(ob_get_clean()),
			'first'=>$GLOBALS['ErrorHandler']->takeStackArray(),
			'again'=>$GLOBALS['ErrorHandler']->takeStackArray()
		);
	}
	// In production, nothing is kept for the response body, whatever ERR_SHOW says
	$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array('system'=>Array('ERR_SHOW'=>true, 'ENV_NAME'=>'PROD')) );
	$GLOBALS['ErrorResponse'] = function( String $errorMessage ){};
	ob_start();
	handleErrorFlg(E_USER_WARNING, 'production warning', 'file.php', 15);
	$results['production'] = Array('echo'=>trim(ob_get_clean()), 'first'=>$GLOBALS['ErrorHandler']->takeStackArray());
	$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array('system'=>Array('ERR_SHOW'=>true)) );
	unset($GLOBALS['ErrorResponse']);
	ob_start();
	handleErrorFlg(E_USER_WARNING, 'shown warning', 'file.php', 14);
	$results['no responder'] = Array(
		'echo'=>trim(preg_replace('/\s+/', ' ', strip_tags(ob_get_clean()))),
		'first'=>$GLOBALS['ErrorHandler']->takeStackArray()
	);
	echo json_encode($results);
	exit(0);
}

if( $runMode=='memreply' ){
	// Real fatal error with a responder registered: argv[3] is 'on' or 'off' for ERR_SHOW
	$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array('system'=>Array('ERR_SHOW'=>(($argv[3] ?? 'off')=='on'))) );
	$GLOBALS['ErrorResponse'] = function( String $errorMessage ){ echo 'REPLY:'.$errorMessage; };
	installHandler();
	ini_set('memory_limit', '16M');
	$bigText = str_repeat('x', 64*1024*1024);
	exit(0);
}

installHandler();

if( $runMode=='warning' ){
	trigger_error('custom warning', E_USER_WARNING);
	$emptyAr = Array();
	$missing = $emptyAr['missing'];
	echo 'execution continued';
}elseif( $runMode=='exception' ){
	throw new RuntimeException('boom', 42);
}elseif( $runMode=='trace' ){
	// An exception thrown under a call given a secret, where PHP keeps the arguments of the calls
	ini_set('zend.exception_ignore_args', '0');
	/**
	 * Fails while checking credentials.
	 *
	 * @param String $namedUserTxt Login name.
	 * @param String $usrPasswdTxt Password.
	 */
	function failCredential( String $namedUserTxt, String $usrPasswdTxt ): Void {
		throw new RuntimeException('store down', 7);
	}
	failCredential('root', 'hunter2-top-secret');
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
