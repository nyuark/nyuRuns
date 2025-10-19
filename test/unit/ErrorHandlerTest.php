<?php

// Error/handling.php : what gets logged, at which level, and what is shown.
// Each scenario runs in a child process (test/lib/handlerChild.php).

require __DIR__.'/../lib/harness.php';

/**
 * Runs one error-handler scenario in a child process.
 *
 * @param String $runModeNme Scenario name understood by handlerChild.php.
 * @param String $extraValue Optional extra argument for the scenario.
 * @return Array The child's output under 'text' and its log lines under 'logs'.
 */
function handlerOf( String $runModeNme, String $extraValue='' ): Array {
	$logsDir = inTempDir('hndl_'.$runModeNme.$extraValue);
	$outcome = runScript( Array(TST_DIR.DS.'lib'.DS.'handlerChild.php', $runModeNme, $logsDir, $extraValue) );
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

// The trace of an exception is logged without the arguments of its calls
$outcome = handlerOf('trace');
$traceLn = strval($outcome['logs'][0] ?? '');
$traceAr = json_decode(substr($traceLn, intval(strpos($traceLn, ' [{'))), true);
checkLike('trace: the entry tells the exception', $traceLn, '/^\[\d{4}\] \[ERROR\] E_EXCEPTION: RuntimeException \(code 7\): store down in .*handlerChild\.php on line \d+ \[\{/');
checkSame('trace: the call is told by its name, file and line', Array($traceAr[0]['function'] ?? null, basename(strval($traceAr[0]['file'] ?? '')), is_int($traceAr[0]['line'] ?? null), array_keys($traceAr[0] ?? Array())), Array('checksIn', 'handlerChild.php', true, Array('file', 'line', 'function')));
checkSame('trace: no argument of a call is written', Array(strpos($traceLn, 'hunter2'), strpos($traceLn, 'root'), strpos($traceLn, '"args"')), Array(false, false, false));

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
	// In production nothing of an error is shown, whatever ERR_SHOW says
	checkSame('production: the reply is the generic message, though ERR_SHOW is on', Array($replies['responder, show on, in production']['sent'], $replies['responder, show on, in production']['echo']), Array(Array('Internal Server Error'), ''));
	checkSame('production: without a responder, nothing is echoed', $replies['no responder, show on, in production'], Array('sent'=>Array(), 'echo'=>''));
	checkLike('preview: ERR_SHOW is still followed', $replies['responder, show on, in preview']['sent'][0] ?? null, '/^RuntimeException \(code 42\): boom in /');
	checkTrue('reply: every case is logged, production included', count($outcome['logs'])>=8);
}

// Warnings inside a framework request are kept for the response body instead of echoed
$outcome = handlerOf('stack');
$stacked = json_decode($outcome['text'], true);
if( !is_array($stacked) ){
	failCheck('stack: the child returns its results', '        output:   '.showValue($outcome['text'])."\n");
}else{
	checkSame('stack: nothing echoed when ERR_SHOW is on', $stacked['show on']['echo'], '');
	checkSame('stack: both errors are kept when ERR_SHOW is on', $stacked['show on']['first'], Array(
		'E_USER_WARNING: kept warning in file.php on line 12',
		'E_NOTICE: kept notice in file.php on line 13'
	));
	checkSame('stack: taking the errors empties the stack', $stacked['show on']['again'], Array());
	checkSame('stack: nothing kept when ERR_SHOW is off', Array($stacked['show off']['echo'], $stacked['show off']['first']), Array('', Array()));
	checkLike('stack: without a responder the error is echoed', $stacked['no responder']['echo'], '/E_USER_WARNING.*shown warning/');
	checkSame('stack: without a responder nothing is kept', $stacked['no responder']['first'], Array());
	checkSame('stack: in production nothing is kept nor echoed, though ERR_SHOW is on', $stacked['production'], Array('echo'=>'', 'first'=>Array()));
	checkSame('stack: every error is logged', count($outcome['logs']), 6);
}

// The HTML display : a stack table, in the one block showError() and pushError() both echo
$outcome = handlerOf('block');
$blckArr = json_decode($outcome['text'], true);
if( !is_array($blckArr) ){
	failCheck('block: the child returns its results', '        output:   '.showValue($outcome['text'])."\n");
}else{
	checkLike('table: a header row', $blckArr['table'], '#^<table class="ErrorStack"[^>]*>\s*<tr><th>\#</th><th>Call</th><th>File</th><th>Line</th></tr>#');
	checkSame('table: one row per frame', substr_count($blckArr['table'], '<tr><td>'), 3);
	checkLike('table: class, type and function make the call, HTML-encoded', $blckArr['table'], '#<tr><td>\#0</td><td>Auth&lt;i&gt;-&gt;login\(\)</td><td>C:\\\\app\\\\&lt;b&gt;run&lt;/b&gt;\.php</td><td>12</td></tr>#');
	checkLike('table: a plain function', $blckArr['table'], '#<tr><td>\#1</td><td>main\(\)</td><td>/srv/index\.php</td><td>3</td></tr>#');
	checkLike('table: a frame without a file or a line', $blckArr['table'], '#<tr><td>\#2</td><td>\{closure\}\(\)</td><td></td><td></td></tr>#');
	checkTrue('table: the values of the arguments are never shown', strpos($blckArr['table'], 'hunter2')===false && strpos($blckArr['table'], 'root')===false);
	checkSame('table: nothing for an empty trace', $blckArr['empty'], '');

	checkTrue('block: a trace is shown as the table', strpos($blckArr['trace'], $blckArr['table'])!==false);
	checkLike('block: the message is HTML-encoded, its line breaks kept', $blckArr['trace'], '#<span class="ErrorValue">&lt;script&gt;x&lt;/script&gt;<br/>\r\nsecond</span>#');
	checkLike('block: the file is HTML-encoded', $blckArr['trace'], '#<span class="ErrorValue">\'a&amp;b\.php\'</span>#');
	checkLike('block: level and line', $blckArr['trace'], '#^<div class="E_EXCEPTION".*<span class="ErrorValue">E_EXCEPTION</span>.*Line:</span> <span class="ErrorValue">7</span>#s');
	checkLike('block: a text context is shown encoded, one line per frame', $blckArr['text'], '#Context:</span><span class="ErrorValue"><br/>\r\n\#0 first &lt;x&gt;<br/>\r\n\#1 second</span>#');
	checkTrue('block: no file and no line when there is none', strpos($blckArr['text'], 'File:')===false && strpos($blckArr['text'], 'Line:')===false);
	checkTrue('block: an array that is not a trace is not shown', strpos($blckArr['scope'], 'hunter2')===false && strpos($blckArr['scope'], 'Context:')===false && strpos($blckArr['scope'], 'Stack:')===false);
	checkSame('block: showError() echoes it', $blckArr['show'], $blckArr['same']);
	checkSame('block: pushError() echoes the same one', $blckArr['push'], $blckArr['same']);
	checkSame('block: nothing logged without a logger', $outcome['logs'], Array());
}

// A real fatal error answers through the responder too
$outcome = handlerOf('memreply', 'on');
checkLike('fatal reply: message, file and line when ERR_SHOW is on', $outcome['text'], '/REPLY:Allowed memory size of \d+ bytes exhausted .* in .*handlerChild\.php on line \d+/');
checkSame('fatal reply: logged once', count($outcome['logs']), 1);
$outcome = handlerOf('memreply', 'off');
checkLike('fatal reply: generic message when ERR_SHOW is off', $outcome['text'], '/REPLY:Internal Server Error/');

endChecks();
