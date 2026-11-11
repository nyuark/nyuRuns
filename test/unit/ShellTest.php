<?php

// Command line : the words and options, the output as text or JSON, the
// questions, the exit codes, the command an application brings, and the
// tester command.

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');
require __DIR__.'/../lib/harness.php';

use nyu\Shell\Console;
use nyu\Shell\Options;
use nyu\Tools\Logger;

/**
 * Runs the command line in this process, on streams of its own.
 *
 * @param Array  $shellWords The arguments.
 * @param String $inputsText What is typed as answers.
 * @return Array The exit code under 'code', the output under 'text', the questions and errors under 'errs'.
 */
function shellRun( Array $shellWords, String $inputsText='' ): Array {
	$outStrm = fopen('php://memory', 'w+');
	$errStrm = fopen('php://memory', 'w+');
	$inpStrm = fopen('php://memory', 'w+');
	fwrite($inpStrm, $inputsText);
	rewind($inpStrm);
	$exitNum = (new Console($shellWords, $outStrm, $errStrm, $inpStrm))->executeCommand();
	// The logs of the project are not the tests' to write
	Logger::plugLogsConfig( Array() );
	rewind($outStrm);
	rewind($errStrm);
	return Array('code'=>$exitNum, 'text'=>str_replace("\r\n", "\n", stream_get_contents($outStrm)), 'errs'=>str_replace("\r\n", "\n", stream_get_contents($errStrm)));
}

// Words and options
$optnObj = new Options( Array('managers', '--store=userFile', 'lists', '--noask', 'a=b', '--', '--kept', '--x=1') );
checkIdentical('words are read in order', Array($optnObj->shiftFirstWord(), $optnObj->shiftFirstWord(), $optnObj->restWordsArray()), Array('managers', 'lists', Array('a=b', '--kept', '--x=1')));
checkIdentical('an option with a value, one without, one not written', Array($optnObj->retrieveOption('store'), $optnObj->validateOption('noask'), $optnObj->retrieveOption('noask', 'dflt'), $optnObj->validateOption('kept'), $optnObj->retrieveOption('kept', 'none')), Array('userFile', true, 'dflt', false, 'none'));
checkIdentical('no word left', (new Options(Array('--only')))->shiftFirstWord(), null);

// The list of commands, the version
$runVals = shellRun( Array() );
checkIdentical('without a command, the commands are listed', Array($runVals['code'], preg_match('/^runTests\s+Runs the tests/m', $runVals['text']), preg_match('/^probe\s+Probe of the command line/m', $runVals['text'])), Array(0, 1, 1));
checkIdentical('the version', shellRun(Array('--versions')), Array('code'=>0, 'text'=>'nyuRuns: '.Console::NYUR_VERS."\n", 'errs'=>''));
checkIdentical('an unknown command is a wrong usage', shellRun(Array('nothing')), Array('code'=>2, 'text'=>'', 'errs'=>"Unknown command: nothing\n"));
checkIdentical('a name that is not one of a command', shellRun(Array('../probe'))['code'], 2);

// The command of an application
$runVals = shellRun( Array('probe') );
checkIdentical('without a sub-command, they are listed', Array($runVals['code'], preg_match('/^sayHello\s+<name>\s+greets$/m', $runVals['text'])), Array(0, 1));
checkIdentical('--helpText lists them too', shellRun(Array('probe', 'sayHello', '--helpText'))['text'], $runVals['text']);
checkIdentical('a sub-command, its words and options', shellRun(Array('probe', 'sayHello', 'Ann', 'more', '--greeting=warm')), Array('code'=>0, 'text'=>"Hello Ann\nrest: [\"more\"]\ntone: warm\nname      size\na         1\nlong one  22\n", 'errs'=>''));
checkIdentical('the same as JSON', json_decode(shellRun(Array('probe', 'sayHello', 'Ann', '--outputAs=json'))['text'], true), Array('done'=>true, 'exit'=>0, 'notes'=>Array('Hello Ann'), 'rest'=>Array(), 'tone'=>'plain', 'rows'=>Array(Array('name'=>'a', 'size'=>1), Array('name'=>'long one', 'size'=>22))));
checkIdentical('--silently leaves the remarks out', shellRun(Array('probe', 'sayHello', 'Ann', '--silently'))['text'], "rest: []\ntone: plain\nname      size\na         1\nlong one  22\n");
checkIdentical('a missing argument is a wrong usage', shellRun(Array('probe', 'sayHello')), Array('code'=>2, 'text'=>'', 'errs'=>"Missing argument: name\n"));
checkIdentical('an unknown sub-command is a wrong usage', shellRun(Array('probe', 'other')), Array('code'=>2, 'text'=>'', 'errs'=>"Unknown sub-command: other\n"));
checkIdentical('a refusal has its own exit code', shellRun(Array('probe', 'refusing')), Array('code'=>3, 'text'=>'', 'errs'=>"Not allowed here\n"));
checkIdentical('a refusal as JSON', json_decode(shellRun(Array('probe', 'refusing', '--outputAs=json'))['text'], true), Array('done'=>false, 'exit'=>3, 'error'=>'Not allowed here'));
checkIdentical('a sub-command declared and not written fails', shellRun(Array('probe', 'ghostSub'))['code'], 1);

// Questions
checkIdentical('answers are read, the questions asked on the error stream', shellRun(Array('probe', 'askInput'), "typed\nyes\ns3cret\n"), Array('code'=>0, 'text'=>"value: typed\nagree: true\ntyped (shown this once): s3cret\n", 'errs'=>'Value [dflt]: Remove it [y/N]: Secret: '));
checkIdentical('Enter takes the default, and is not a yes', shellRun(Array('probe', 'askInput'), "\n\n\n")['text'], "value: dflt\nagree: false\ntyped (shown this once): \n");
checkIdentical('--noPrompt asks nothing and takes the defaults', shellRun(Array('probe', 'askInput', '--noPrompt'), "typed\nyes\npw\n"), Array('code'=>0, 'text'=>"value: dflt\nagree: false\ntyped (shown this once): typed\n", 'errs'=>'Secret: '));
checkIdentical('--yesToAll is the yes given beforehand', shellRun(Array('probe', 'askInput', '--noPrompt', '--yesToAll'), "pw\n")['text'], "value: dflt\nagree: true\ntyped (shown this once): pw\n");

// The entry point, and the tester command
$runVals = runScriptArray( Array(TOP_DIR.DS.'nyuRun', 'runTests', 'StrKitTest', '--outputAs=json', '--silently') );
$jsnVals = json_decode($runVals['text'], true);
checkIdentical('the tester runs the scripts named and tells their result', Array($runVals['code'], $jsnVals['done'] ?? null, ($jsnVals['passed'] ?? 0)>0, $jsnVals['failed'] ?? null), Array(0, true, true, 0));
$runVals = runScriptArray( Array(TOP_DIR.DS.'nyuRun', 'runTests', 'NoSuchScriptAnywhere') );
checkIdentical('no script run is not a failure', Array($runVals['code'], preg_match('/0 checks passed, 0 failed/', $runVals['text'])), Array(0, 1));

finishChecking();
