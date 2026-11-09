<?php

// Child process for test/unit/PluginTest.php. Resolves the class of an
// access strategy with test/fixtures/runs as the applications folder:
//
//   php plugChild.php <auth|sess> <short name> [<listed names, comma separated>]
//
// Prints the class the Checker resolves, or "null". The listed names fill
// both lists of the 'plugin' block.

$topDir = dirname(__DIR__, 2);

define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', $topDir);
define('WEB_DIR', TOP_DIR.DS.'www');
define('RUN_DIR', TOP_DIR.DS.'test'.DS.'fixtures'.DS.'runs');
require $topDir.'/vendor/autoload.php';

$listCds = array_values( array_filter(explode(',', $argv[3] ?? ''), 'strlen') );
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array('access'=>Array('PLUGIN'=>Array('authby'=>$listCds, 'sessby'=>$listCds))) );

$clsName = ($argv[1]=='sess') ? nyu\Access\Checker::sessnClassText($argv[2]) : nyu\Access\Checker::authClssString($argv[2]);
echo ($clsName===null) ? 'null' : $clsName;
