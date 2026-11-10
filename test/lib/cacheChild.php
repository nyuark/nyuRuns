<?php

// Child process for test/unit/ConfigCacheTest.php and test/unit/RouterTest.php. Runs one GET request
// through the Runner on a project folder of its own, whose temp/cache the
// config cache is written in; the applications are those of the fixtures:
//
//   php cacheChild.php <project folder> <preset> <path>

$topDir = dirname(__DIR__, 2);

define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', $argv[1]);
define('WEB_DIR', TOP_DIR.DS.'www');
define('RUN_DIR', $topDir.DS.'test'.DS.'fixtures'.DS.'runs');
require $topDir.'/vendor/autoload.php';

// The error log is the standard error stream, whatever the machine's php.ini says
ini_set('log_errors', '1');
ini_set('error_log', '');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $argv[3];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

new \nyu\Runner($argv[2]);
