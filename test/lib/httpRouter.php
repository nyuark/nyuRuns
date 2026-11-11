<?php

// Router script for PHP's built-in server, used by test/http/HttpTest.php:
//
//   php -S 127.0.0.1:<port> test/lib/httpRouter.php
//
// Every request is served by the framework as www/index.php would, except
// that the shared database connection is opened beforehand on the sample
// SQLite file named by the NYU_TEST_DB environment variable, and that a
// request naming no X-Test-Auth, on the default preset or on mapping, is
// served open ("required": false): both presets are closed.
//
// X-Test-* request headers switch on preset overloads, through the Runner's
// own $overLoaded argument:
//   X-Test-Auth: <carrier code>      access control required, with that session carrier
//   X-Test-Store: <store code>       credential check of that access control (regdKeys by default)
//   X-Test-Confkey: <block name>     access.json block of that access control (none by default)
//   X-Test-Conf: <folder>            config files read from test/fixtures/<folder> instead of conf
//   X-Test-Public: <app/action,...>  public routes of that access control (with X-Test-Auth)
//   X-Test-Signin: <path>            sign-in route of that access control (with X-Test-Auth)
//   X-Test-Roles: <route=role,...>   roles the routes of that access control ask for (with X-Test-Auth)
//   X-Test-Format: <format>          output format (response.outputAs)
//   X-Test-Ctype: <value>            preset Content-Type header (name from X-Test-Ctname)
//   X-Test-Origns: <origin,...>      origins the preset allows other sites to call from
//   X-Test-Routes: 1               declared routes read from test/fixtures/routes, their files from test/fixtures/pages
//   X-Test-Htroot: <folder>          files and pages of the declared routes read from test/fixtures/<folder>
// X-Test-Preset names the preset to load instead of the default one,
// and X-Test-Runs serves the applications of test/fixtures/runs instead of runs/.

$topDir = dirname(__DIR__, 2);
$sqlFile = getenv('NYU_TEST_DB');

chdir($topDir.DIRECTORY_SEPARATOR.'www');
require $topDir.'/vendor/autoload.php';
require __DIR__.'/sample.php';
if( !file_exists($sqlFile) ){
	createSampleDb($sqlFile);
}
// DBCon is a singleton that ignores later arguments, so this decides the connection for the request
nyu\Data\DBCon::obtainInstance('sqlitedb', '', '', '', $sqlFile);

// X-Test-Cert: <result>;<name>;<issuer> stands in for the web server reporting a TLS client certificate
if( isset($_SERVER['HTTP_X_TEST_CERT']) ){
	$crtPart = explode(';', $_SERVER['HTTP_X_TEST_CERT']) + Array('', '', '');
	$_SERVER['SSL_CLIENT_VERIFY'] = $crtPart[0];
	$_SERVER['SSL_CLIENT_S_DN_CN'] = $crtPart[1];
	$_SERVER['SSL_CLIENT_I_DN_CN'] = $crtPart[2];
}

$overArr = Array();
// default and mapping are closed : a request naming no access control, on either of them, is served open, as the tests were written for
if( !isset($_SERVER['HTTP_X_TEST_AUTH']) && in_array($_SERVER['HTTP_X_TEST_PRESET'] ?? '', Array('', 'default', 'mapping'), true) ){
	// The roles of the preset's routes go with it: roles asked for while access is not required refuse every request
	$overArr['security'] = Array('required'=>false, 'rteRoles'=>Array());
}
if( isset($_SERVER['HTTP_X_TEST_AUTH']) ){
	$overArr['security'] = Array('required'=>true, 'sessWith'=>$_SERVER['HTTP_X_TEST_AUTH'], 'authWith'=>$_SERVER['HTTP_X_TEST_STORE'] ?? 'regdKeys', 'sessConf'=>$_SERVER['HTTP_X_TEST_CONFKEY'] ?? 'none');
	if( isset($_SERVER['HTTP_X_TEST_PUBLIC']) ){
		$overArr['security']['openList'] = array_map('trim', explode(',', $_SERVER['HTTP_X_TEST_PUBLIC']));
	}
	if( isset($_SERVER['HTTP_X_TEST_SIGNIN']) ){
		$overArr['security']['signRout'] = $_SERVER['HTTP_X_TEST_SIGNIN'];
	}
	if( isset($_SERVER['HTTP_X_TEST_ROLES']) ){
		foreach( explode(',', $_SERVER['HTTP_X_TEST_ROLES']) as $rolPar ){
			$pairArr = explode('=', $rolPar, 2) + Array('', '');
			$overArr['security']['rteRoles'][trim($pairArr[0])] = trim($pairArr[1]);
		}
	}
}
if( isset($_SERVER['HTTP_X_TEST_CONF']) && preg_match('/^\w+$/', $_SERVER['HTTP_X_TEST_CONF']) ){
	$overArr['settings']['confPath'] = 'test'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.$_SERVER['HTTP_X_TEST_CONF'];
}
if( isset($_SERVER['HTTP_X_TEST_FORMAT']) ){
	$overArr['response']['outputAs'] = $_SERVER['HTTP_X_TEST_FORMAT'];
}
if( isset($_SERVER['HTTP_X_TEST_CTYPE']) ){
	$overArr['response']['headList'] = Array( ($_SERVER['HTTP_X_TEST_CTNAME'] ?? 'Content-Type') => $_SERVER['HTTP_X_TEST_CTYPE'] );
}
if( isset($_SERVER['HTTP_X_TEST_ORIGNS']) ){
	$overArr['response']['corsList'] = array_map('trim', explode(',', $_SERVER['HTTP_X_TEST_ORIGNS']));
}
if( isset($_SERVER['HTTP_X_TEST_ROUTES']) ){
	$overArr['routings']['routeDir'] = 'test'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'routes';
	$overArr['routings']['htmlRoot'] = 'test'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'pages';
}
if( isset($_SERVER['HTTP_X_TEST_HTROOT']) && preg_match('/^\w+$/', $_SERVER['HTTP_X_TEST_HTROOT']) ){
	$overArr['routings']['htmlRoot'] = 'test'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.$_SERVER['HTTP_X_TEST_HTROOT'];
}

$useFxtr = isset($_SERVER['HTTP_X_TEST_RUNS']);

$presetNm = $_SERVER['HTTP_X_TEST_PRESET'] ?? '';

if( empty($overArr) && !$useFxtr && $presetNm=='' ){
	require $topDir.'/www/index.php';
	return;
}

// Same bootstrap as www/index.php, with the overloads applied
define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', realpath($topDir));
define('WEB_DIR', TOP_DIR.DS.'www');
define('RUN_DIR', $useFxtr ? TOP_DIR.DS.'test'.DS.'fixtures'.DS.'runs' : TOP_DIR.DS.'runs');
$nyuRun = new \nyu\Runner($presetNm, $overArr);
