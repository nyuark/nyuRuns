<?php

// Child process for test/unit/RunnerBootTest.php. Starts a Runner on a
// project folder of its own, so that a preset can be missing or broken:
//
//   php bootChild.php <preset name> <project folder>
//
// The folder is given a conf/presets/broken.rps file that is not valid JSON,
// a conf/presets/empty.rps file that holds nothing but a comment, and
// stale.rps and nested.rps files holding a key the framework does not know.

$rootDir = dirname(__DIR__, 2);
$prstNme = $argv[1];
$project = $argv[2];

define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', $project);
define('WEB_DIR', TOP_DIR.DS.'www');
define('RUN_DIR', TOP_DIR.DS.'runs');
require $rootDir.'/vendor/autoload.php';

mkdir(TOP_DIR.DS.'conf'.DS.'presets', 0775, true);
file_put_contents(TOP_DIR.DS.'conf'.DS.'presets'.DS.'broken.rps', '{ "name": "broken", ');
file_put_contents(TOP_DIR.DS.'conf'.DS.'presets'.DS.'empty.rps', "// Nothing set\n{\n}\n");
file_put_contents(TOP_DIR.DS.'conf'.DS.'presets'.DS.'stale.rps', '{ "label": "stale", "setup": { "ftypes": "all", "config": "all" } }');
file_put_contents(TOP_DIR.DS.'conf'.DS.'presets'.DS.'nested.rps', '{ "name": "nested", "setup": { "ftypes": "all" } }');

// The error log is the standard error stream, whatever the machine's php.ini says
ini_set('log_errors', '1');
ini_set('error_log', '');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/shows/index';

$nyuRun = new \nyu\Runner($prstNme);
echo 'the runner went on';
