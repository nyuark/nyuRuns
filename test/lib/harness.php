<?php

// Shared helpers for the self-checking test scripts (see test/run.php).
// Every test script requires this file, runs its checks, then calls endChecks().

error_reporting(E_ALL);

define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', realpath(__DIR__.DS.'..'.DS.'..'));
define('WEB_DIR', TOP_DIR.DS.'www');
define('RUN_DIR', TOP_DIR.DS.'runs');
define('TST_DIR', TOP_DIR.DS.'test');
define('TMP_DIR', sys_get_temp_dir().DS.'nyuruns_test_'.getmypid());

require TOP_DIR.DS.'vendor'.DS.'autoload.php';

$GLOBALS['tstStats'] = Array('pass'=>0, 'fail'=>0, 'skip'=>0, 'note'=>Array());

// Any PHP notice or warning raised by the tested code is recorded, and
// reported as a failure by endChecks() unless the test collected it first
set_error_handler(function( Int $errorLevel, String $errMessage, String $errFilePth, Int $errLineNum ){
	if( !(error_reporting() & $errorLevel) ){
		return true;
	}
	$GLOBALS['tstStats']['note'][] = basename($errFilePth).':'.$errLineNum.' '.$errMessage;
	return true;
});

/**
 * Renders a value for a failure message.
 *
 * @param mixed $shownValue The value to render.
 * @return String The value as compact JSON.
 */
function showValue( Mixed $shownValue ): String {
	$jsnText = json_encode($shownValue, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	return ($jsnText===false) ? var_export($shownValue, true) : $jsnText;
}

/**
 * Records a failed check and prints its details.
 *
 * @param String $checkLabel Label of the check.
 * @param String $detailText What was expected and what was found.
 */
function failCheck( String $checkLabel, String $detailText ): Void {
	$GLOBALS['tstStats']['fail']++;
	echo '  FAIL  '.$checkLabel."\n".$detailText;
}

/**
 * Checks that a value is strictly identical to the expected one.
 *
 * @param String $checkLabel Label of the check.
 * @param mixed  $actualData Value produced by the tested code.
 * @param mixed  $expectData Value it should be identical to.
 */
function checkSame( String $checkLabel, Mixed $actualData, Mixed $expectData ): Void {
	if( $actualData===$expectData ){
		$GLOBALS['tstStats']['pass']++;
		return;
	}
	failCheck( $checkLabel, '        expected: '.showValue($expectData)."\n".'        actual:   '.showValue($actualData)."\n" );
}

/**
 * Checks that a value is strictly true.
 *
 * @param String $checkLabel Label of the check.
 * @param mixed  $actualData Value produced by the tested code.
 */
function checkTrue( String $checkLabel, Mixed $actualData ): Void {
	checkSame( $checkLabel, $actualData, true );
}

/**
 * Checks that a string matches a regular expression.
 *
 * @param String $checkLabel Label of the check.
 * @param mixed  $actualData Value produced by the tested code.
 * @param String $regexPattn Regular expression it should match.
 */
function checkLike( String $checkLabel, Mixed $actualData, String $regexPattn ): Void {
	if( is_string($actualData) && preg_match($regexPattn, $actualData)===1 ){
		$GLOBALS['tstStats']['pass']++;
		return;
	}
	failCheck( $checkLabel, '        pattern:  '.$regexPattn."\n".'        actual:   '.showValue($actualData)."\n" );
}

/**
 * Checks that calling a function throws.
 *
 * @param String   $checkLabel Label of the check.
 * @param callable $testedFunc Function expected to throw.
 * @param String   $thrownType Class the thrown object should be an instance of.
 */
function checkThrw( String $checkLabel, callable $testedFunc, String $thrownType='Throwable' ): Void {
	try{
		$testedFunc();
	}catch( Throwable $e ){
		if( $e instanceof $thrownType ){
			$GLOBALS['tstStats']['pass']++;
		}else{
			failCheck( $checkLabel, '        expected: '.$thrownType."\n".'        thrown:   '.get_class($e).': '.$e->getMessage()."\n" );
		}
		return;
	}
	failCheck( $checkLabel, '        expected: '.$thrownType."\n".'        thrown:   nothing'."\n" );
}

/**
 * Runs a function and returns its result, or a 'THROWN ...' string when
 * it throws, so that one unexpected exception fails one check only.
 *
 * @param callable $testedFunc Function to run.
 * @return mixed The function's result, or a description of what it threw.
 */
function runSafely( callable $testedFunc ): Mixed {
	try{
		return $testedFunc();
	}catch( Throwable $e ){
		return 'THROWN '.get_class($e).': '.$e->getMessage().' @'.basename($e->getFile()).':'.$e->getLine();
	}
}

/**
 * Records a check that could not be run in this environment.
 *
 * @param String $checkLabel Label of the check.
 * @param String $skipReason Why it was skipped.
 */
function skipCheck( String $checkLabel, String $skipReason ): Void {
	$GLOBALS['tstStats']['skip']++;
	echo '  SKIP  '.$checkLabel.' ('.$skipReason.')'."\n";
}

/**
 * Returns the PHP notices recorded so far and forgets them, for tests
 * that expect some.
 *
 * @return Array The recorded notices, as 'file:line message' strings.
 */
function takeNotes(): Array {
	$noteLst = $GLOBALS['tstStats']['note'];
	$GLOBALS['tstStats']['note'] = Array();
	return $noteLst;
}

/**
 * Returns a path inside this script's own temporary folder, creating
 * the folder on first use.
 *
 * @param String $insideName File or folder name inside the temporary folder.
 * @return String The full path.
 */
function inTempDir( String $insideName ): String {
	if( !is_dir(TMP_DIR) ){
		mkdir(TMP_DIR, 0775, true);
	}
	return TMP_DIR.DS.$insideName;
}

/**
 * Deletes a folder created by the tests, with everything in it.
 *
 * @param String $folderPath Folder to delete.
 */
function dropFoldr( String $folderPath ): Void {
	if( !is_dir($folderPath) ){
		return;
	}
	foreach( scandir($folderPath) as $entNme ){
		if( $entNme=='.' || $entNme=='..' ){
			continue;
		}
		$entPath = $folderPath.DS.$entNme;
		if( is_dir($entPath) ){
			dropFoldr($entPath);
		}else{
			@unlink($entPath);
		}
	}
	@rmdir($folderPath);
}

/**
 * Runs a PHP script in a separate process.
 *
 * @param Array $scriptArgs Script path followed by its arguments.
 * @return Array The exit code under 'code' and the combined output under 'text'.
 */
function runScript( Array $scriptArgs ): Array {
	$cmdLine = array_merge( Array(PHP_BINARY), $scriptArgs );
	$pipeArr = Array();
	$process = proc_open( $cmdLine, Array(0=>Array('pipe','r'), 1=>Array('pipe','w'), 2=>Array('redirect',1)), $pipeArr, TOP_DIR );
	fclose($pipeArr[0]);
	$outText = stream_get_contents($pipeArr[1]);
	fclose($pipeArr[1]);
	$exitNum = proc_close($process);
	return Array('code'=>$exitNum, 'text'=>$outText);
}

/**
 * Builds a Request without running its constructor, carrying only the
 * given headers.
 *
 * @param Array $headersArr Header name => value pairs the request should report.
 * @return nyu\Tools\Request The request object.
 */
function fakeReqst( Array $headersArr=Array() ): nyu\Tools\Request {
	$reflect = new ReflectionClass('nyu\Tools\Request');
	$request = $reflect->newInstanceWithoutConstructor();
	$headPrp = $reflect->getProperty('allHeads');
	$headPrp->setAccessible(true);
	$headPrp->setValue($request, $headersArr);
	return $request;
}

/**
 * Ends a test script: reports unexpected PHP notices as failures, prints
 * the summary line, cleans the temporary folder and sets the exit code.
 */
function endChecks(): Void {
	foreach( array_count_values($GLOBALS['tstStats']['note']) as $noteTx=>$noteNb ){
		failCheck( 'unexpected PHP notice (x'.$noteNb.')', '        '.$noteTx."\n" );
	}
	dropFoldr(TMP_DIR);
	$tstStat = $GLOBALS['tstStats'];
	echo '  '.$tstStat['pass'].' passed, '.$tstStat['fail'].' failed'.($tstStat['skip']>0 ? ', '.$tstStat['skip'].' skipped' : '')."\n";
	exit( $tstStat['fail']>0 ? 1 : 0 );
}
