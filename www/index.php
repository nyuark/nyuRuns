<?php

include_once('../vendor/autoload.php');

// Base Dir Setup
define('DS',DIRECTORY_SEPARATOR);
define('TOP_DIR',realpath(__DIR__.DS.'..'));
define('WEB_DIR',TOP_DIR.DS.'www');
define('RUN_DIR',TOP_DIR.DS.'runs');

//$preset = new nyu\Tools\Preset();
// TODO : DIContainer

//ini_set('display_errors','1');
//file_put_contents('call.log', date('Y-m-d H:i:s ').var_export($_REQUEST, true).var_export($_SERVER, true)."\n");
$nyuRun = new \nyu\Runner(); 
