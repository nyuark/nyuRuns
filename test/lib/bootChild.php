<?php

// Child process for test/unit/RunnerBootTest.php. Starts a Runner on a
// project folder of its own, so that a preset can be missing or broken:
//
//   php bootChild.php <preset name> <project folder>
//
// The folder is given a conf/presets/broken.rps file that is not valid JSON.

$topDir = dirname(__DIR__, 2);
$presetNm = $argv[1];
$projDir = $argv[2];

define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', $projDir);
define('WEB_DIR', TOP_DIR.DS.'www');
define('RUN_DIR', TOP_DIR.DS.'runs');
require $topDir.'/vendor/autoload.php';

mkdir(TOP_DIR.DS.'conf'.DS.'presets', 0775, true);
file_put_contents(TOP_DIR.DS.'conf'.DS.'presets'.DS.'broken.rps', '{ "name": "broken", ');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/shows/index';

$nyuRun = new \nyu\Runner($presetNm);
echo 'the runner went on';
