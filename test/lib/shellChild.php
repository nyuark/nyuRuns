<?php

// Child process for the command line tests. Runs the command line on a
// project folder of its own, so that its config, users and temporary
// files can be written and removed:
//
//   php shellChild.php <project folder> <command> <sub-command> ...
//
// The answers to its questions are read from the standard input.

$topDir = dirname(__DIR__, 2);
$projDir = $argv[1];

define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', $projDir);
define('WEB_DIR', TOP_DIR.DS.'www');
define('RUN_DIR', is_dir(TOP_DIR.DS.'runs') ? TOP_DIR.DS.'runs' : $topDir.DS.'test'.DS.'fixtures'.DS.'runs');
require $topDir.'/vendor/autoload.php';

// The error log is the standard error stream, whatever the machine's php.ini says
ini_set('log_errors', '1');
ini_set('error_log', '');

$nyuShell = new \nyu\Shell\Console( array_slice($argv, 2) );
$nyuShell->plugError();
exit( $nyuShell->runCommnd() );
