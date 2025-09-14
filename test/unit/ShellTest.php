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
	$exitNum = (new Console($shellWords, $outStrm, $errStrm, $inpStrm))->runCommnd();
	// The logs of the project are not the tests' to write
	Logger::plugSetup( Array() );
	rewind($outStrm);
	rewind($errStrm);
	return Array('code'=>$exitNum, 'text'=>str_replace("\r\n", "\n", stream_get_contents($outStrm)), 'errs'=>str_replace("\r\n", "\n", stream_get_contents($errStrm)));
}

// Words and options
$optnObj = new Options( Array('manage', '--store=usrlst', 'lists', '--noask', 'a=b', '--', '--kept', '--x=1') );
checkSame('words are read in order', Array($optnObj->shiftWord(), $optnObj->shiftWord(), $optnObj->restWords()), Array('manage', 'lists', Array('a=b', '--kept', '--x=1')));
checkSame('an option with a value, one without, one not written', Array($optnObj->getOption('store'), $optnObj->hasOption('noask'), $optnObj->getOption('noask', 'dflt'), $optnObj->hasOption('kept'), $optnObj->getOption('kept', 'none')), Array('usrlst', true, 'dflt', false, 'none'));
checkSame('no word left', (new Options(Array('--only')))->shiftWord(), null);

// The list of commands, the version
$runVals = shellRun( Array() );
checkSame('without a command, the commands are listed', Array($runVals['code'], preg_match('/^tester\s+Runs the tests/m', $runVals['text']), preg_match('/^probe\s+Probe of the command line/m', $runVals['text'])), Array(0, 1, 1));
checkSame('the version', shellRun(Array('--versn')), Array('code'=>0, 'text'=>'nyuRuns: '.Console::NYUVERSN."\n", 'errs'=>''));
checkSame('an unknown command is a wrong usage', shellRun(Array('nothing')), Array('code'=>2, 'text'=>'', 'errs'=>"Unknown command: nothing\n"));
checkSame('a name that is not one of a command', shellRun(Array('../probe'))['code'], 2);

// The command of an application
$runVals = shellRun( Array('probe') );
checkSame('without a sub-command, they are listed', Array($runVals['code'], preg_match('/^hello\s+<name>\s+greets$/m', $runVals['text'])), Array(0, 1));
checkSame('--usage lists them too', shellRun(Array('probe', 'hello', '--usage'))['text'], $runVals['text']);
checkSame('a sub-command, its words and options', shellRun(Array('probe', 'hello', 'Ann', 'more', '--tone=warm')), Array('code'=>0, 'text'=>"Hello Ann\nrest: [\"more\"]\ntone: warm\nname      size\na         1\nlong one  22\n", 'errs'=>''));
checkSame('the same as JSON', json_decode(shellRun(Array('probe', 'hello', 'Ann', '--shape=json'))['text'], true), Array('done'=>true, 'exit'=>0, 'notes'=>Array('Hello Ann'), 'rest'=>Array(), 'tone'=>'plain', 'rows'=>Array(Array('name'=>'a', 'size'=>1), Array('name'=>'long one', 'size'=>22))));
checkSame('--quiet leaves the remarks out', shellRun(Array('probe', 'hello', 'Ann', '--quiet'))['text'], "rest: []\ntone: plain\nname      size\na         1\nlong one  22\n");
checkSame('a missing argument is a wrong usage', shellRun(Array('probe', 'hello')), Array('code'=>2, 'text'=>'', 'errs'=>"Missing argument: name\n"));
checkSame('an unknown sub-command is a wrong usage', shellRun(Array('probe', 'other')), Array('code'=>2, 'text'=>'', 'errs'=>"Unknown sub-command: other\n"));
checkSame('a refusal has its own exit code', shellRun(Array('probe', 'stops')), Array('code'=>3, 'text'=>'', 'errs'=>"Not allowed here\n"));
checkSame('a refusal as JSON', json_decode(shellRun(Array('probe', 'stops', '--shape=json'))['text'], true), Array('done'=>false, 'exit'=>3, 'error'=>'Not allowed here'));
checkSame('a sub-command declared and not written fails', shellRun(Array('probe', 'ghost'))['code'], 1);

// Questions
checkSame('answers are read, the questions asked on the error stream', shellRun(Array('probe', 'asked'), "typed\nyes\ns3cret\n"), Array('code'=>0, 'text'=>"value: typed\nagree: true\ntyped (shown this once): s3cret\n", 'errs'=>'Value [dflt]: Remove it [y/N]: Secret: '));
checkSame('Enter takes the default, and is not a yes', shellRun(Array('probe', 'asked'), "\n\n\n")['text'], "value: dflt\nagree: false\ntyped (shown this once): \n");
checkSame('--noask asks nothing and takes the defaults', shellRun(Array('probe', 'asked', '--noask'), "typed\nyes\npw\n"), Array('code'=>0, 'text'=>"value: dflt\nagree: false\ntyped (shown this once): typed\n", 'errs'=>'Secret: '));
checkSame('--agree is the yes given beforehand', shellRun(Array('probe', 'asked', '--noask', '--agree'), "pw\n")['text'], "value: dflt\nagree: true\ntyped (shown this once): pw\n");

// The entry point, and the tester command
$runVals = runScript( Array(TOP_DIR.DS.'nyuRun', 'tester', 'StrKitTest', '--shape=json', '--quiet') );
$jsnVals = json_decode($runVals['text'], true);
checkSame('the tester runs the scripts named and tells their result', Array($runVals['code'], $jsnVals['done'] ?? null, ($jsnVals['passed'] ?? 0)>0, $jsnVals['failed'] ?? null), Array(0, true, true, 0));
$runVals = runScript( Array(TOP_DIR.DS.'nyuRun', 'tester', 'NoSuchScriptAnywhere') );
checkSame('no script run is not a failure', Array($runVals['code'], preg_match('/0 checks passed, 0 failed/', $runVals['text'])), Array(0, 1));

endChecks();
