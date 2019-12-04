<?php

include_once('../autoloader.php');

//$preset = new nyu\Tools\Preset();
// TODO : DIContainer

use \nyu\Data\DBRecord; 

$GLOBALS['manualRoute'] = Array(
	'dev' => Array(
		'command' => Array(
			'app' => "client",
			'act' => "edit"
			)
		)
	);

$nyuRun = new nyu\Runner('manual');

$nyuRec = new DBRecord( 'shows' );
$nyuArr = $nyuRec->loadItem(100);
$nyuRec->bindVals( Array('title'=>'LES PIEDS DANS LES NUAGES') );
// $nyuRec->bindVals( Array('title'=>'LES PIEDS DANS - LES - NUAGES') );
var_dump($nyuRec->saveItem());
$nyuArr = $nyuRec->loadItem(100);
print_R($nyuArr);