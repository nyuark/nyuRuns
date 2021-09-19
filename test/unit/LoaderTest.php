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
checkTrue('conf/ holds the system and global files', in_array('SYSTEM', $allCnfs) && in_array('GLOBAL', $allCnfs) && count($allCnfs)>2);

checkSame('fnames any: every file',            loadedCnf(Array('ftypes'=>'all', 'fnames'=>'any')), $allCnfs);
checkSame('fnames missing: every file',        loadedCnf(Array('ftypes'=>'all')), $allCnfs);
checkSame('fnames with one name',              loadedCnf(Array('ftypes'=>'all', 'fnames'=>'system')), Array('SYSTEM'));
checkSame('fnames with a list',                loadedCnf(Array('ftypes'=>'all', 'fnames'=>'system,global')), Array('GLOBAL', 'SYSTEM'));
checkSame('fnames with spaces in the list',    loadedCnf(Array('ftypes'=>'all', 'fnames'=>' system , global ')), Array('GLOBAL', 'SYSTEM'));
checkSame('fnames is matched with its case',   loadedCnf(Array('ftypes'=>'all', 'fnames'=>'system,GLOBAL')), Array('SYSTEM'));
checkSame('ftypes keeps one extension',        loadedCnf(Array('ftypes'=>'ini', 'fnames'=>'any')), $iniCnfs);

endChecks();
