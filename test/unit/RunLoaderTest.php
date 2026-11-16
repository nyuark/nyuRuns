<?php

// The loader of the classes of the Runs (nyu\App\RunLoader, registered by src/App/loadruns.php),
// on a fixture of its own (test/fixtures/loadruns), copied to a temporary folder set as RUN_DIR
// before the harness: the loader reads RUN_DIR when a class is asked for.

$fixtDir = dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'loadruns';
$runsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nyuruns_load_'.getmypid();
foreach( new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $oneFil ){
	$copyPth = $runsDir.substr($oneFil->getPathname(), strlen($fixtDir));
	if( $oneFil->isDir() ){
		mkdir($copyPth, 0775, true);
	}else{
		if( !is_dir(dirname($copyPth)) ){
			mkdir(dirname($copyPth), 0775, true);
		}
		copy($oneFil->getPathname(), $copyPth);
	}
}
define('RUN_DIR', $runsDir);

require __DIR__.'/../lib/harness.php';

// The fixtures' RUN_DIR is the one read
checkIdentical('the loader is registered after Composer\'s own', in_array(Array('nyu\\App\\RunLoader', 'loadsRunsClass'), spl_autoload_functions(), true), true);
checkIdentical('RUN_DIR is the folder of the fixtures: a Run of the project is no class there', Array(RUN_DIR===$runsDir, is_file(TOP_DIR.DS.'runs'.DS.'Auth'.DS.'Actions.php'), class_exists('nyu\\Runs\\Auth\\Actions')), Array(true, true, false));

// Base classes, with no require_once
checkIdentical('the actions of a Run extend a base class of Shared, written as <Name>.php', Array(class_exists('nyu\\Runs\\Depot\\Actions'), get_parent_class('nyu\\Runs\\Depot\\Actions'), class_exists('nyu\\Runs\\Shared\\RootActs', false)), Array(true, 'nyu\\Runs\\Shared\\RootActs', true));
checkIdentical('a model in mods as <Name>Model.php extends a shared base, written as <Name>Model.php', Array(class_exists('nyu\\Runs\\Depot\\mods\\Crate'), get_parent_class('nyu\\Runs\\Depot\\mods\\Crate'), class_exists('nyu\\Runs\\Shared\\Core', false)), Array(true, 'nyu\\Runs\\Shared\\Core', true));
checkIdentical('with <Name>Model.php and <Name>.php both there, <Name>Model.php is the one read', isset($GLOBALS['readDecoy']), false);
checkIdentical('a model in mods as <Name>.php', class_exists('nyu\\Runs\\Depot\\mods\\Pallet'), true);

// The folder of the Run, whatever the case of its name
checkIdentical('a Run folder asked for in another letter case is found, for a class in a file of its name', Array(class_exists('nyu\\Runs\\DEPOT\\Helper'), class_exists('nyu\\Runs\\Depot\\Helper', false)), Array(true, true));
if( !is_dir($runsDir.DS.'DEPOT') ){
	foreach( Array('Twins', 'TWINS') as $oneNme ){
		mkdir($runsDir.DS.$oneNme);
		file_put_contents($runsDir.DS.$oneNme.DS.'Actions.php', "<?php\nnamespace nyu\\Runs\\Twins;\nclass Actions {\n}\n");
	}
	nyu\App\Controller::clearFldrCache();
	checkIdentical('two folders differing by their case alone load nothing', Array(class_exists('nyu\\Runs\\Twins\\Actions'), class_exists('nyu\\Runs\\TWINS\\Actions'), class_exists('nyu\\Runs\\twins\\Actions')), Array(false, false, false));
}else{
	recordSkipping('two Run folders differing by their case alone', 'this disk does not tell the case of a name apart');
}

// What is not in the table loads nothing, and throws nothing
$lostList = Array(
	'a part that is not letters and digits'=>'nyu\\Runs\\Dep-ot\\Actions',
	'a part leaving the folder'=>'nyu\\Runs\\Depot\\..\\Shared\\RootActs',
	'a deeper name'=>'nyu\\Runs\\Depot\\mods\\deep\\Crate',
	'a folder of the Run that is not mods'=>'nyu\\Runs\\Depot\\other\\Crate',
	'a folder under Shared'=>'nyu\\Runs\\Shared\\mods\\Core',
	'a file that is not there'=>'nyu\\Runs\\Depot\\Missing',
	'a Run that is not there'=>'nyu\\Runs\\Nowhere\\Actions',
	'a name with nothing after the Run'=>'nyu\\Runs\\Depot',
	'another namespace'=>'nyu\\Other\\Depot\\Actions'
);
foreach( $lostList as $oneKey => $oneNme ){
	checkIdentical('nothing loaded, nothing thrown: '.$oneKey, runCatchThrown(function() use ( $oneNme ){ return class_exists($oneNme); }), false);
}
checkIdentical('the decoy is still not read', isset($GLOBALS['readDecoy']), false);

// A file its Run required itself, under another writing of its path, then asked for through the loader: declared once.
// On Windows the path is also written in another letter case.
$oddsPath = $runsDir.DS.'Depot'.DS.'mods'.DS.'..'.'/'.'Shell.php';
if( DS==='\\' ){
	$oddsPath = strtoupper($runsDir).DS.'DEPOT'.DS.'mods'.DS.'..'.'/'.'shell.PHP';
}
require_once $oddsPath;
checkIdentical('a file required by its Run declares its class', class_exists('nyu\\Runs\\Depot\\Shell', false), true);
nyu\App\RunLoader::loadsRunsClass('nyu\\Runs\\Depot\\Shell');
nyu\App\RunLoader::loadsRunsClass('nyu\\Runs\\DEPOT\\Shell');
checkIdentical('asked for through the loader afterwards, it is not declared twice ('.PHP_OS_FAMILY.')', Array(class_exists('nyu\\Runs\\Depot\\Shell'), count(preg_grep('/Shell\.php$/i', get_included_files()))), Array(true, 1));

dropTestFolder($runsDir);
finishChecking();
