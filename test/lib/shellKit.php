<?php

// Helpers of the command line tests: a project folder of their own, and the
// command line run on it (see shellChild.php).

/**
 * Creates a project folder in the temporary folder of the script, with
 * the files given.
 *
 * @param String $folderName Name of the folder.
 * @param Array  $filesTexts Content of each file, by its path from the root of the project, written with slashes.
 * @return String The full path of the project.
 */
function makeProjt( String $folderName, Array $filesTexts=Array() ): String {
	$projDir = inTempDir($folderName);
	foreach( Array('conf', 'temp') as $fldNme ){
		if( !is_dir($projDir.DS.$fldNme) ){
			mkdir($projDir.DS.$fldNme, 0775, true);
		}
	}
	foreach( $filesTexts as $flePth => $fleTxt ){
		putInProj($projDir, strval($flePth), strval($fleTxt));
	}
	return $projDir;
}

/**
 * Writes a file of a project, creating its folder when needed.
 *
 * @param String $projctRoot Full path of the project.
 * @param String $insidePath Path of the file from the root of the project, written with slashes.
 * @param String $newContent Content of the file.
 * @return String The full path of the file.
 */
function putInProj( String $projctRoot, String $insidePath, String $newContent ): String {
	$fullPth = $projctRoot.DS.str_replace('/', DS, $insidePath);
	if( !is_dir(dirname($fullPth)) ){
		mkdir(dirname($fullPth), 0775, true);
	}
	file_put_contents($fullPth, $newContent);
	return $fullPth;
}

/**
 * Reads a file of a project.
 *
 * @param String $projctRoot Full path of the project.
 * @param String $insidePath Path of the file from the root of the project, written with slashes.
 * @return ?String Its content, line ends as \n; null when there is no such file.
 */
function readInPrj( String $projctRoot, String $insidePath ): ?String {
	$fullPth = $projctRoot.DS.str_replace('/', DS, $insidePath);
	return is_file($fullPth) ? str_replace("\r\n", "\n", strval(file_get_contents($fullPth))) : null;
}

/**
 * Runs the command line on a project, in a process of its own.
 *
 * @param String $projctRoot Full path of the project.
 * @param Array  $shellWords The arguments of the command line.
 * @param String $inputsText What is typed as answers.
 * @return Array The exit code under 'code', the output under 'text', the questions and errors under 'errs'; line ends as \n.
 */
function runShell( String $projctRoot, Array $shellWords, String $inputsText='' ): Array {
	// A PHP given its database extension on its own command line gives it to the child the same way
	$extOpts = Array();
	if( php_ini_loaded_file()===false && extension_loaded('pdo_sqlite') ){
		$extOpts = Array('-d', 'extension_dir='.ini_get('extension_dir'), '-d', 'extension=pdo_sqlite');
	}
	$cmdLine = array_merge( Array(PHP_BINARY), $extOpts, Array(TST_DIR.DS.'lib'.DS.'shellChild.php', $projctRoot), $shellWords );
	$pipeArr = Array();
	$process = proc_open( $cmdLine, Array(0=>Array('pipe', 'r'), 1=>Array('pipe', 'w'), 2=>Array('pipe', 'w')), $pipeArr, TOP_DIR );
	fwrite($pipeArr[0], $inputsText);
	fclose($pipeArr[0]);
	$outText = stream_get_contents($pipeArr[1]);
	$errText = stream_get_contents($pipeArr[2]);
	fclose($pipeArr[1]);
	fclose($pipeArr[2]);
	$exitNum = proc_close($process);
	return Array('code'=>$exitNum, 'text'=>str_replace("\r\n", "\n", strval($outText)), 'errs'=>str_replace("\r\n", "\n", strval($errText)));
}

/**
 * Runs the command line on a project and reads its output as JSON.
 *
 * @param String $projctRoot Full path of the project.
 * @param Array  $shellWords The arguments of the command line; --shape=json is added.
 * @param String $inputsText What is typed as answers.
 * @return mixed The decoded object, or the raw output and errors when it is not JSON.
 */
function jsonShell( String $projctRoot, Array $shellWords, String $inputsText='' ): Mixed {
	$runVals = runShell( $projctRoot, array_merge($shellWords, Array('--shape=json')), $inputsText );
	return json_decode($runVals['text'], true) ?? $runVals;
}

/**
 * The system.ini of a test project: logs written in full, in temp/logs.
 *
 * @param String $moreLines Lines added at its end.
 * @return String The content of the file.
 */
function sampleIni( String $moreLines='' ): String {
	return "\n; System Configuration file\n\n; Environment platform (DEV, TEST, PREV, PROD)\nENV_NAME = DEV\n\n; LOGGING\n; Logging mode\nLOG_MODE = FULL\nLOG_PATH = /temp/logs\nLOG_SIZE = 1024\nLOG_ROTA = NUMBER\nLOG_PREF = Y-m-d H:i\n\n; Errors reported\nERR_LEVL = E_ALL\n; Show errors (TRUE/FALSE)\nERR_SHOW = TRUE\nLST_ROWS = 100\n".$moreLines;
}
