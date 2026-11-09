<?php

// Shared helpers for the self-checking test scripts (see test/run.php).
// Every test script requires this file, runs its checks, then calls finishChecking().

error_reporting(E_ALL);

define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', realpath(__DIR__.DS.'..'.DS.'..'));
define('WEB_DIR', TOP_DIR.DS.'www');
// A test script may point RUN_DIR at the fixture runs before requiring this file
if( !defined('RUN_DIR') ){
	define('RUN_DIR', TOP_DIR.DS.'runs');
}
define('TST_DIR', TOP_DIR.DS.'test');
define('TMP_DIR', sys_get_temp_dir().DS.'nyuruns_test_'.getmypid());

require TOP_DIR.DS.'vendor'.DS.'autoload.php';

$GLOBALS['tstStats'] = Array('pass'=>0, 'fail'=>0, 'skip'=>0, 'note'=>Array());

// Any PHP notice or warning raised by the tested code is recorded, and
// reported as a failure by finishChecking() unless the test collected it first
set_error_handler(function( Int $errLevelNumb, String $errorMessage, String $errorFilPath, Int $errorLineNum ){
	if( !(error_reporting() & $errLevelNumb) ){
		return true;
	}
	$GLOBALS['tstStats']['note'][] = basename($errorFilPath).':'.$errorLineNum.' '.$errorMessage;
	return true;
});

/**
 * Renders a value for a failure message.
 *
 * @param mixed $valueToPrint The value to render.
 * @return String The value as compact JSON.
 */
function printValueText( Mixed $valueToPrint ): String {
	$jsonTxt = json_encode($valueToPrint, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	return ($jsonTxt===false) ? var_export($valueToPrint, true) : $jsonTxt;
}

/**
 * Records a failed check and prints its details.
 *
 * @param String $checkSubject Label of the check.
 * @param String $foundDetails What was expected and what was found.
 */
function recordFailures( String $checkSubject, String $foundDetails ): Void {
	$GLOBALS['tstStats']['fail']++;
	echo '  FAIL  '.$checkSubject."\n".$foundDetails;
}

/**
 * Checks that a value is strictly identical to the expected one.
 *
 * @param String $checkSubject Label of the check.
 * @param mixed  $actualOutput Value produced by the tested code.
 * @param mixed  $expectOutput Value it should be identical to.
 */
function checkIdentical( String $checkSubject, Mixed $actualOutput, Mixed $expectOutput ): Void {
	if( $actualOutput===$expectOutput ){
		$GLOBALS['tstStats']['pass']++;
		return;
	}
	recordFailures( $checkSubject, '        expected: '.printValueText($expectOutput)."\n".'        actual:   '.printValueText($actualOutput)."\n" );
}

/**
 * Checks that a value is strictly true.
 *
 * @param String $checkSubject Label of the check.
 * @param mixed  $actualOutput Value produced by the tested code.
 */
function checkTrueValue( String $checkSubject, Mixed $actualOutput ): Void {
	checkIdentical( $checkSubject, $actualOutput, true );
}

/**
 * Checks that a string matches a regular expression.
 *
 * @param String $checkSubject Label of the check.
 * @param mixed  $actualOutput Value produced by the tested code.
 * @param String $regexPattern Regular expression it should match.
 */
function checkPregMatch( String $checkSubject, Mixed $actualOutput, String $regexPattern ): Void {
	if( is_string($actualOutput) && preg_match($regexPattern, $actualOutput)===1 ){
		$GLOBALS['tstStats']['pass']++;
		return;
	}
	recordFailures( $checkSubject, '        pattern:  '.$regexPattern."\n".'        actual:   '.printValueText($actualOutput)."\n" );
}

/**
 * Checks that calling a function throws.
 *
 * @param String   $checkSubject Label of the check.
 * @param callable $tstdFunction Function expected to throw.
 * @param String   $expectedType Class the thrown object should be an instance of.
 */
function checkForThrows( String $checkSubject, callable $tstdFunction, String $expectedType='Throwable' ): Void {
	try{
		$tstdFunction();
	}catch( Throwable $e ){
		if( $e instanceof $expectedType ){
			$GLOBALS['tstStats']['pass']++;
		}else{
			recordFailures( $checkSubject, '        expected: '.$expectedType."\n".'        thrown:   '.get_class($e).': '.$e->getMessage()."\n" );
		}
		return;
	}
	recordFailures( $checkSubject, '        expected: '.$expectedType."\n".'        thrown:   nothing'."\n" );
}

/**
 * Runs a function and returns its result, or a 'THROWN ...' string when
 * it throws, so that one unexpected exception fails one check only.
 *
 * @param callable $tstdFunction Function to run.
 * @return mixed The function's result, or a description of what it threw.
 */
function runCatchThrown( callable $tstdFunction ): Mixed {
	try{
		return $tstdFunction();
	}catch( Throwable $e ){
		return 'THROWN '.get_class($e).': '.$e->getMessage().' @'.basename($e->getFile()).':'.$e->getLine();
	}
}

/**
 * Records a check that could not be run in this environment.
 *
 * @param String $checkSubject Label of the check.
 * @param String $reasonToSkip Why it was skipped.
 */
function recordSkipping( String $checkSubject, String $reasonToSkip ): Void {
	$GLOBALS['tstStats']['skip']++;
	echo '  SKIP  '.$checkSubject.' ('.$reasonToSkip.')'."\n";
}

/**
 * Returns the PHP notices recorded so far and forgets them, for tests
 * that expect some.
 *
 * @return Array The recorded notices, as 'file:line message' strings.
 */
function takeNotesArray(): Array {
	$noteLst = $GLOBALS['tstStats']['note'];
	$GLOBALS['tstStats']['note'] = Array();
	return $noteLst;
}

/**
 * Returns a path inside this script's own temporary folder, creating
 * the folder on first use.
 *
 * @param String $fullPathName File or folder name inside the temporary folder.
 * @return String The full path.
 */
function inTemporaryDir( String $fullPathName ): String {
	if( !is_dir(TMP_DIR) ){
		mkdir(TMP_DIR, 0775, true);
	}
	return TMP_DIR.DS.$fullPathName;
}

/**
 * Deletes a folder created by the tests, with everything in it.
 *
 * @param String $fullFoldPath Folder to delete.
 */
function dropTestFolder( String $fullFoldPath ): Void {
	if( !is_dir($fullFoldPath) ){
		return;
	}
	foreach( scandir($fullFoldPath) as $entNme ){
		if( $entNme=='.' || $entNme=='..' ){
			continue;
		}
		$subPath = $fullFoldPath.DS.$entNme;
		if( is_dir($subPath) ){
			dropTestFolder($subPath);
		}else{
			@unlink($subPath);
		}
	}
	@rmdir($fullFoldPath);
}

/**
 * Runs a PHP script in a separate process.
 *
 * @param Array $scrptArgmnts Script path followed by its arguments.
 * @return Array The exit code under 'code' and the combined output under 'text'.
 */
function runScriptArray( Array $scrptArgmnts ): Array {
	$command = array_merge( Array(PHP_BINARY), $scrptArgmnts );
	$pipeArr = Array();
	$process = proc_open( $command, Array(0=>Array('pipe','r'), 1=>Array('pipe','w'), 2=>Array('redirect',1)), $pipeArr, TOP_DIR );
	fclose($pipeArr[0]);
	$outText = stream_get_contents($pipeArr[1]);
	fclose($pipeArr[1]);
	$exitNum = proc_close($process);
	return Array('code'=>$exitNum, 'text'=>$outText);
}

/**
 * Builds a server registry holding only the given values, without
 * running its constructor: $_SERVER is neither read nor unset.
 *
 * @param Array $serverValues Server variable => value pairs the registry should hold.
 * @return nyu\Tools\ServerReg The server registry.
 */
function makeFakeServer( Array $serverValues=Array() ): nyu\Tools\ServerReg {
	$reflect = new ReflectionClass('nyu\Tools\ServerReg');
	$srvrReg = $reflect->newInstanceWithoutConstructor();
	// Keys in lower case, as the framework's own server registry holds them
	$reflect->getParentClass()->getConstructor()->invoke($srvrReg, $serverValues, true);
	return $srvrReg;
}

/**
 * Builds a Request without running its constructor, carrying only the
 * given headers and server values, and the client registry read off them.
 *
 * @param Array $headersArray Header name => value pairs the request should report.
 * @param Array $serverValues Server variable => value pairs the request should report.
 * @return nyu\Tools\Request The request object.
 */
function mockRequestObj( Array $headersArray=Array(), Array $serverValues=Array() ): nyu\Tools\Request {
	$reflect = new ReflectionClass('nyu\Tools\Request');
	$request = $reflect->newInstanceWithoutConstructor();
	$hdrProp = $reflect->getProperty('everyHeads');
	$hdrProp->setValue($request, $headersArray);
	// Keys in lower case, as the framework's own server registry holds them
	$srvProp = $reflect->getProperty('servRgstry');
	$srvProp->setValue($request, new nyu\Tools\Registry($serverValues + Array('REQUEST_METHOD'=>'GET'), true));
	$clntPrp = $reflect->getProperty('clientRgst');
	$clntPrp->setValue($request, new nyu\Tools\ClientReg(makeFakeServer($serverValues)));
	return $request;
}

/**
 * Ends a test script: reports unexpected PHP notices as failures, prints
 * the summary line, cleans the temporary folder and sets the exit code.
 */
function finishChecking(): Void {
	foreach( array_count_values($GLOBALS['tstStats']['note']) as $noteTx=>$noteNb ){
		recordFailures( 'unexpected PHP notice (x'.$noteNb.')', '        '.$noteTx."\n" );
	}
	dropTestFolder(TMP_DIR);
	$tstStts = $GLOBALS['tstStats'];
	echo '  '.$tstStts['pass'].' passed, '.$tstStts['fail'].' failed'.($tstStts['skip']>0 ? ', '.$tstStts['skip'].' skipped' : '')."\n";
	exit( $tstStts['fail']>0 ? 1 : 0 );
}
