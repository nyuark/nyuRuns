<?php

// Error/handling.php : what gets logged, at which level, and what is shown.
// Each scenario runs in a child process (test/lib/handlerChild.php).

require __DIR__.'/../lib/harness.php';

/**
 * Runs one error-handler scenario in a child process.
 *
 * @param String $runModeNme Scenario name understood by handlerChild.php.
 * @return Array The child's output under 'text' and its log lines under 'logs'.
 */
function handlerOf( String $runModeNme ): Array {
	$logsDir = inTempDir('hndl_'.$runModeNme);
	$outcome = runScript( Array(TST_DIR.DS.'lib'.DS.'handlerChild.php', $runModeNme, $logsDir) );
	$logFile = $logsDir.DS.'err.log';
	return Array(
		'text'=>$outcome['text'],
		'logs'=>file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES) : Array()
	);
}

// Warnings : logged at WARNING, execution goes on
$outcome = handlerOf('warning');
checkLike('warning: execution continues', $outcome['text'], '/execution continued/');
checkSame('warning: two log lines', count($outcome['logs']), 2);
checkLike('warning: user warning line', $outcome['logs'][0] ?? null, '/^\[\d{4}\] \[WARNING\] E_USER_WARNING: custom warning in .*handlerChild\.php on line \d+$/');
checkLike('warning: PHP warning line', $outcome['logs'][1] ?? null, '/^\[\d{4}\] \[WARNING\] E_WARNING: Undefined array key "missing" in .*handlerChild\.php on line \d+$/');

// Uncaught exception : logged at ERROR under its own label, with class and code, nothing shown
$outcome = handlerOf('exception');
checkSame('exception: one log line', count($outcome['logs']), 1);
checkLike('exception: log line', $outcome['logs'][0] ?? null, '/^\[\d{4}\] \[ERROR\] E_EXCEPTION: RuntimeException \(code 42\): boom in .*handlerChild\.php on line \d+$/');
checkSame('exception: nothing shown when ERR_SHOW is not on', trim($outcome['text']), '');

// Exception with a string code
if( extension_loaded('pdo_sqlite') ){
	$outcome = handlerOf('pdoexception');
	checkLike('PDO exception: string code is kept', $outcome['logs'][0] ?? null, '/\[ERROR\] E_EXCEPTION: PDOException \(code HY000\): /');
}else{
	skipCheck('PDO exception: string code is kept', 'pdo_sqlite is not available');
}

// PHP 8 turns most fatal errors into Error exceptions
$outcome = handlerOf('fatal');
checkLike('undefined function: logged as an exception', $outcome['logs'][0] ?? null, '/\[ERROR\] E_EXCEPTION: Error \(code 0\): Call to undefined function undefined_function_call\(\) in /');

// A real fatal error is caught at shutdown and logged under its PHP level
$outcome = handlerOf('memfatal');
checkSame('fatal: one log line', count($outcome['logs']), 1);
checkLike('fatal: log line', $outcome['logs'][0] ?? null, '/^\[\d{4}\] \[ERROR\] E_ERROR: Allowed memory size of \d+ bytes exhausted/');

// Without a logger, errors are shown instead
$outcome = handlerOf('nologger');
checkSame('no logger: nothing logged', $outcome['logs'], Array());
checkLike('no logger: level shown', $outcome['text'], '/E_USER_NOTICE/');
checkLike('no logger: message shown, without a prefix', $outcome['text'], '/Message:<\/span> <span class="ErrorValue">shown inline</');

// Exception -> reply in the configured output format, through the registered responder
$outcome = handlerOf('reply');
$replies = json_decode($outcome['text'], true);
if( !is_array($replies) ){
	failCheck('reply: the child returns its results', '        output:   '.showValue($outcome['text'])."\n");
}else{
	checkSame('reply: generic message when ERR_SHOW is off', $replies['responder, show off']['sent'], Array('Internal Server Error'));
	checkSame('reply: nothing echoed when ERR_SHOW is off', $replies['responder, show off']['echo'], '');
	checkSame('reply: one message when ERR_SHOW is on', count($replies['responder, show on']['sent']), 1);
	checkLike('reply: message, file and line when ERR_SHOW is on', $replies['responder, show on']['sent'][0] ?? null, '/^RuntimeException \(code 42\): boom in .*handlerChild\.php on line \d+$/');
	checkSame('reply: nothing echoed when the responder answers', $replies['responder, show on']['echo'], '');
	checkSame('no responder: nothing sent', $replies['no responder, show on']['sent'], Array());
	checkLike('no responder: error block echoed when ERR_SHOW is on', $replies['no responder, show on']['echo'], '/E_EXCEPTION/');
	checkSame('no responder: nothing echoed when ERR_SHOW is off', $replies['no responder, show off']['echo'], '');
	checkLike('broken responder: falls back to the error block', $replies['broken responder, show on']['echo'], '/E_EXCEPTION/');
	checkTrue('reply: every case is logged', count($outcome['logs'])>=5);
}

endChecks();
