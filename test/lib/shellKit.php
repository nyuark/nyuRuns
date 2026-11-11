<?php

// Helpers of the command line tests: a project folder of their own, and the
// command line run on it (see shellChild.php).

/**
 * Creates a project folder in the temporary folder of the script, with
 * the files given.
 *
 * @param String $nameOfFolder Name of the folder.
 * @param Array  $fileContents Content of each file, by its path from the root of the project, written with slashes.
 * @return String The full path of the project.
 */
function makeProjectTxt( String $nameOfFolder, Array $fileContents=Array() ): String {
	$project = inTemporaryDir($nameOfFolder);
	foreach( Array('conf', 'temp') as $fldNme ){
		if( !is_dir($project.DS.$fldNme) ){
			mkdir($project.DS.$fldNme, 0775, true);
		}
	}
	foreach( $fileContents as $filPth => $filTxt ){
		writeInProject($project, strval($filPth), strval($filTxt));
	}
	return $project;
}

/**
 * Writes a file of a project, creating its folder when needed.
 *
 * @param String $rootPathName Full path of the project.
 * @param String $pathFromRoot Path of the file from the root of the project, written with slashes.
 * @param String $freshContent Content of the file.
 * @return String The full path of the file.
 */
function writeInProject( String $rootPathName, String $pathFromRoot, String $freshContent ): String {
	$fullPth = $rootPathName.DS.str_replace('/', DS, $pathFromRoot);
	if( !is_dir(dirname($fullPth)) ){
		mkdir(dirname($fullPth), 0775, true);
	}
	file_put_contents($fullPth, $freshContent);
	return $fullPth;
}

/**
 * Reads a file of a project.
 *
 * @param String $rootPathName Full path of the project.
 * @param String $pathFromRoot Path of the file from the root of the project, written with slashes.
 * @return ?String Its content, line ends as \n; null when there is no such file.
 */
function fetchInProject( String $rootPathName, String $pathFromRoot ): ?String {
	$fullPth = $rootPathName.DS.str_replace('/', DS, $pathFromRoot);
	return is_file($fullPth) ? str_replace("\r\n", "\n", strval(file_get_contents($fullPth))) : null;
}

/**
 * Runs the command line on a project, in a process of its own.
 *
 * @param String $rootPathName Full path of the project.
 * @param Array  $commandWords The arguments of the command line.
 * @param String $typedAnswers What is typed as answers.
 * @return Array The exit code under 'code', the output under 'text', the questions and errors under 'errs'; line ends as \n.
 */
function callShellArray( String $rootPathName, Array $commandWords, String $typedAnswers='' ): Array {
	// A PHP given its database extension on its own command line gives it to the child the same way
	$phpArgs = Array();
	if( php_ini_loaded_file()===false && extension_loaded('pdo_sqlite') ){
		$phpArgs = Array('-d', 'extension_dir='.ini_get('extension_dir'), '-d', 'extension=pdo_sqlite');
	}
	$command = array_merge( Array(PHP_BINARY), $phpArgs, Array(TST_DIR.DS.'lib'.DS.'shellChild.php', $rootPathName), $commandWords );
	$pipeArr = Array();
	$process = proc_open( $command, Array(0=>Array('pipe', 'r'), 1=>Array('pipe', 'w'), 2=>Array('pipe', 'w')), $pipeArr, TOP_DIR );
	fwrite($pipeArr[0], $typedAnswers);
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
 * @param String $rootPathName Full path of the project.
 * @param Array  $commandWords The arguments of the command line; --outputAs=json is added.
 * @param String $typedAnswers What is typed as answers.
 * @return mixed The decoded object, or the raw output and errors when it is not JSON.
 */
function runShellAsJson( String $rootPathName, Array $commandWords, String $typedAnswers='' ): Mixed {
	$outcome = callShellArray( $rootPathName, array_merge($commandWords, Array('--outputAs=json')), $typedAnswers );
	return json_decode($outcome['text'], true) ?? $outcome;
}

/**
 * The system.ini of a test project: logs written in full, in temp/logs.
 *
 * @param String $moreLinesTxt Lines added at its end.
 * @return String The content of the file.
 */
function buildSystemIni( String $moreLinesTxt='' ): String {
	return "\n; System Configuration file\n\n; Environment platform (DEVS, TEST, PREV, PROD)\nENV_NAME = DEVS\n\n; LOGGING\n; Logging mode\nLOG_MODE = FULL\nLOG_PATH = /temp/logs\nLOG_SIZE = 1024\nLOG_ROTA = INDX\nLOG_PRFX = Y-m-d H:i\n\n; Errors reported\nERR_LEVL = E_ALL\n; Show errors (TRUE/FALSE)\nERR_SHOW = TRUE\nDEF_ROWS = 100\n".$moreLinesTxt;
}
