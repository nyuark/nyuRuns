<?php

// Setup\Loader : which configuration files of conf/ are loaded, depending on
// the 'fnames' and 'ftypes' keys of the preset's setup block.

require __DIR__.'/../lib/harness.php';

use nyu\Setup\Loader;

/**
 * Lists the configuration files a setup block loads.
 *
 * @param Array $setupBlock The 'setup' block of a preset.
 * @return mixed The sorted names of the loaded configurations, or a 'THROWN ...' string.
 */
function loadedCnf( Array $setupBlock ): Mixed {
	return runCatchThrown(function() use ($setupBlock){
		$loadObj = new Loader( Array('setup'=>$setupBlock) );
		$nameLst = array_keys( $loadObj->retrieveConfig()->readLinesArray() );
		sort($nameLst);
		return $nameLst;
	});
}

// What conf/ holds : every .ini and .json file, named by its upper-cased title
$allCnfs = Array();
$iniCnfs = Array();
foreach( scandir(TOP_DIR.DS.'conf') as $fleNme ){
	$extName = strtolower(pathinfo($fleNme, PATHINFO_EXTENSION));
	if( is_file(TOP_DIR.DS.'conf'.DS.$fleNme) && in_array($extName, Array('ini', 'json')) ){
		$allCnfs[] = strtoupper(pathinfo($fleNme, PATHINFO_FILENAME));
		if( $extName=='ini' ){
			$iniCnfs[] = strtoupper(pathinfo($fleNme, PATHINFO_FILENAME));
		}
	}
}
sort($allCnfs);
sort($iniCnfs);
checkIdentical('fnames any: every file',            loadedCnf(Array('ftypes'=>'all', 'fnames'=>'any')), $allCnfs);
checkIdentical('fnames missing: every file',        loadedCnf(Array('ftypes'=>'all')), $allCnfs);
checkIdentical('fnames with one name',              loadedCnf(Array('ftypes'=>'all', 'fnames'=>'system')), Array('SYSTEM'));
checkIdentical('ftypes keeps one extension',        loadedCnf(Array('ftypes'=>'ini', 'fnames'=>'any')), $iniCnfs);

// Config folder named by the preset
$fxtFldr = 'test'.DS.'fixtures'.DS.'confenv';
checkIdentical('folder: the files of another folder', loadedCnf(Array('ftypes'=>'all', 'folder'=>$fxtFldr)), Array('SECRETS'));

/**
 * Reads one value of the fixture's secrets file, as a setup block loads it.
 *
 * @param Array  $setupBlock The 'setup' block of a preset.
 * @param String $secretName Name of the value.
 * @return mixed The loaded value, or a 'THROWN ...' string.
 */
function loadedVal( Array $setupBlock, String $secretName ): Mixed {
	return runCatchThrown(function() use ($setupBlock, $secretName){
		$loadObj = new Loader( Array('setup'=>$setupBlock) );
		return $loadObj->retrieveConfig()->obtainRowValue('SECRETS', $secretName);
	});
}

// Values written env:NAME : environment file first, then the environment of the process
putenv('NYU_TEST_TOKEN=from-the-process');
putenv('NYU_TEST_PROCESS=only-in-the-process');
$envSetp = Array('ftypes'=>'all', 'folder'=>$fxtFldr, 'envfle'=>'test'.DS.'fixtures'.DS.'test.env');
checkIdentical('env: a plain value is kept',             loadedVal($envSetp, 'plain'), 'kept as written');
checkIdentical('env: only a value starting with env: is replaced', loadedVal($envSetp, 'inside'), 'not at the start env:NYU_TEST_TOKEN');
checkIdentical('env: value of the file, before the process', loadedVal($envSetp, 'token'), 'abc123');
checkIdentical('env: quotes are removed, the rest is kept', loadedVal($envSetp, 'quoted'), 'with spaces = and equals');
checkIdentical('env: spaces around name and value are trimmed', loadedVal($envSetp, 'spaced'), 'padded');
checkIdentical('env: a commented line defines nothing',  loadedVal($envSetp, 'commented'), '');
checkIdentical('env: value of the process when the file has none', loadedVal($envSetp, 'process'), 'only-in-the-process');
checkIdentical('env: an unknown name gives an empty value', loadedVal($envSetp, 'missing'), '');
checkIdentical('env: values are replaced at any depth',  loadedVal($envSetp, 'nested'), Array('keys'=>Array('abc123', 'literal'), 'ttl'=>60));
checkIdentical('env: without the file, the process is used', loadedVal(Array('ftypes'=>'all', 'folder'=>$fxtFldr, 'envfle'=>'test'.DS.'fixtures'.DS.'none.env'), 'token'), 'from-the-process');

/**
 * Reads the error level a value of ERR_LEVL gives, as system.ini hands it.
 *
 * @param mixed $levelValue The value of ERR_LEVL; null for none.
 * @return mixed The level, or a 'THROWN ...' string.
 */
function levelOf( Mixed $levelValue ): Mixed {
	$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array('system'=>($levelValue===null ? Array() : Array('ERR_LEVL'=>$levelValue))) );
	return runCatchThrown(function(){
		return (new Loader( Array('setup'=>Array('ftypes'=>'none')) ))->errorConfigNum();
	});
}

// ERR_LEVL : E_* names read one by one, never run; a value left unquoted arrives as the number PHP made of it
checkIdentical('error level: E_ALL when not set, or empty', Array(levelOf(null), levelOf('')), Array(E_ALL, E_ALL));
checkIdentical('error level: as the parser of system.ini gives it unquoted', Array(levelOf('32767'), levelOf(E_ALL & ~E_DEPRECATED)), Array(E_ALL, E_ALL & ~E_DEPRECATED));
checkIdentical('error level: names joined by & and ~, quoted', levelOf('E_ALL & ~E_DEPRECATED & ~E_NOTICE'), E_ALL & ~E_DEPRECATED & ~E_NOTICE);
checkIdentical('error level: & binds before |, as in PHP', levelOf('E_ERROR | E_WARNING & ~E_WARNING'), E_ERROR | (E_WARNING & ~E_WARNING));
checkIdentical('error level: spaces do not matter', levelOf('E_ERROR|E_WARNING'), E_ERROR | E_WARNING);
checkPregMatch('error level: an unknown name stops the start-up', strval(levelOf('E_ALL & ~E_FOO')), '/^THROWN RuntimeException: ERR_LEVL holds what is not an error level: E_FOO @Loader\.php:\d+$/');
checkPregMatch('error level: what is not a name is never run', strval(levelOf('E_ALL | phpinfo()')), '/^THROWN RuntimeException: ERR_LEVL holds what is not an error level: phpinfo\(\) @Loader\.php:\d+$/');
checkPregMatch('error level: a constant that is not one of the errors', strval(levelOf('PHP_VERSION')), '/^THROWN RuntimeException: ERR_LEVL holds what is not an error level: PHP_VERSION @Loader\.php:\d+$/');
$GLOBALS['nyuConfig'] = null;

finishChecking();
