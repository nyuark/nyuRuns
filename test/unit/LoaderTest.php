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
	return runSafely(function() use ($setupBlock){
		$loadObj = new Loader( Array('setup'=>$setupBlock) );
		$nameLst = array_keys( $loadObj->getConfig()->readLines() );
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
checkSame('fnames any: every file',            loadedCnf(Array('ftypes'=>'all', 'fnames'=>'any')), $allCnfs);
checkSame('fnames missing: every file',        loadedCnf(Array('ftypes'=>'all')), $allCnfs);
checkSame('fnames with one name',              loadedCnf(Array('ftypes'=>'all', 'fnames'=>'system')), Array('SYSTEM'));
checkSame('ftypes keeps one extension',        loadedCnf(Array('ftypes'=>'ini', 'fnames'=>'any')), $iniCnfs);

// Config folder named by the preset
$fxtFldr = 'test'.DS.'fixtures'.DS.'confenv';
checkSame('folder: the files of another folder', loadedCnf(Array('ftypes'=>'all', 'folder'=>$fxtFldr)), Array('SECRETS'));

/**
 * Reads one value of the fixture's secrets file, as a setup block loads it.
 *
 * @param Array  $setupBlock The 'setup' block of a preset.
 * @param String $secretName Name of the value.
 * @return mixed The loaded value, or a 'THROWN ...' string.
 */
function loadedVal( Array $setupBlock, String $secretName ): Mixed {
	return runSafely(function() use ($setupBlock, $secretName){
		$loadObj = new Loader( Array('setup'=>$setupBlock) );
		return $loadObj->getConfig()->getRowVal('SECRETS', $secretName);
	});
}

// Values written env:NAME : environment file first, then the environment of the process
putenv('NYU_TEST_TOKEN=from-the-process');
putenv('NYU_TEST_PROCESS=only-in-the-process');
$envSetp = Array('ftypes'=>'all', 'folder'=>$fxtFldr, 'envfle'=>'test'.DS.'fixtures'.DS.'test.env');
checkSame('env: a plain value is kept',             loadedVal($envSetp, 'plain'), 'kept as written');
checkSame('env: only a value starting with env: is replaced', loadedVal($envSetp, 'inside'), 'not at the start env:NYU_TEST_TOKEN');
checkSame('env: value of the file, before the process', loadedVal($envSetp, 'token'), 'abc123');
checkSame('env: quotes are removed, the rest is kept', loadedVal($envSetp, 'quoted'), 'with spaces = and equals');
checkSame('env: spaces around name and value are trimmed', loadedVal($envSetp, 'spaced'), 'padded');
checkSame('env: a commented line defines nothing',  loadedVal($envSetp, 'commented'), '');
checkSame('env: value of the process when the file has none', loadedVal($envSetp, 'process'), 'only-in-the-process');
checkSame('env: an unknown name gives an empty value', loadedVal($envSetp, 'missing'), '');
checkSame('env: values are replaced at any depth',  loadedVal($envSetp, 'nested'), Array('keys'=>Array('abc123', 'literal'), 'ttl'=>60));
checkSame('env: without the file, the process is used', loadedVal(Array('ftypes'=>'all', 'folder'=>$fxtFldr, 'envfle'=>'test'.DS.'fixtures'.DS.'none.env'), 'token'), 'from-the-process');

endChecks();
