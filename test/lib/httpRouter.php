<?php

// Router script for PHP's built-in server, used by test/http/HttpTest.php:
//
//   php -S 127.0.0.1:<port> test/lib/httpRouter.php
//
// Every request is served by the framework exactly as www/index.php would,
// except that the shared database connection is opened beforehand on the
// sample SQLite file named by the NYU_TEST_DB environment variable.
//
// X-Test-* request headers switch on preset overloads, through the Runner's
// own $overLoaded argument:
//   X-Test-Auth: <carrier code>      access control required, with that session carrier
//   X-Test-Store: <store code>       credential check of that access control (regkey by default)
//   X-Test-Confkey: <block name>     access.json block of that access control (none by default)
//   X-Test-Conf: <folder>            config files read from test/fixtures/<folder> instead of conf
//   X-Test-Public: <app/action,...>  public routes of that access control (with X-Test-Auth)
//   X-Test-Tmpl: <template>          render template
//   X-Test-Return: <format>          output format
//   X-Test-Ctype: <value>            preset Content-Type header (name from X-Test-Ctname)
//   X-Test-Routes: 1                 declared routes read from test/fixtures/routes, their files from test/fixtures/pages
// X-Test-Preset names the preset to load instead of the default one,
// and X-Test-Runs serves the applications of test/fixtures/runs instead of runs/.

$topDir = dirname(__DIR__, 2);
$sqlFile = getenv('NYU_TEST_DB');

chdir($topDir.DIRECTORY_SEPARATOR.'www');
require $topDir.'/vendor/autoload.php';
require __DIR__.'/sample.php';
if( !file_exists($sqlFile) ){
	buildData($sqlFile);
}
// DBCon is a singleton that ignores later arguments, so this decides the connection for the request
nyu\Data\DBCon::getInstnc('sqlte', '', '', '', $sqlFile);

// X-Test-Cert: <result>;<name>;<issuer> stands in for the web server reporting a TLS client certificate
if( isset($_SERVER['HTTP_X_TEST_CERT']) ){
	$certPrt = explode(';', $_SERVER['HTTP_X_TEST_CERT']) + Array('', '', '');
	$_SERVER['SSL_CLIENT_VERIFY'] = $certPrt[0];
	$_SERVER['SSL_CLIENT_S_DN_CN'] = $certPrt[1];
	$_SERVER['SSL_CLIENT_I_DN_CN'] = $certPrt[2];
}

$overArr = Array();
if( isset($_SERVER['HTTP_X_TEST_AUTH']) ){
	$overArr['auths'] = Array('needed'=>true, 'sessby'=>$_SERVER['HTTP_X_TEST_AUTH'], 'authby'=>$_SERVER['HTTP_X_TEST_STORE'] ?? 'regkey', 'sesscf'=>$_SERVER['HTTP_X_TEST_CONFKEY'] ?? 'none');
	if( isset($_SERVER['HTTP_X_TEST_PUBLIC']) ){
		$overArr['auths']['openrt'] = array_map('trim', explode(',', $_SERVER['HTTP_X_TEST_PUBLIC']));
	}
}
if( isset($_SERVER['HTTP_X_TEST_CONF']) && preg_match('/^\w+$/', $_SERVER['HTTP_X_TEST_CONF']) ){
	$overArr['setup']['folder'] = 'test'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.$_SERVER['HTTP_X_TEST_CONF'];
}
if( isset($_SERVER['HTTP_X_TEST_TMPL']) ){
	$overArr['rnder']['tmplte'] = $_SERVER['HTTP_X_TEST_TMPL'];
}
if( isset($_SERVER['HTTP_X_TEST_RETURN']) ){
	$overArr['rnder']['return'] = $_SERVER['HTTP_X_TEST_RETURN'];
}
if( isset($_SERVER['HTTP_X_TEST_CTYPE']) ){
	$overArr['rnder']['headrs'] = Array( ($_SERVER['HTTP_X_TEST_CTNAME'] ?? 'Content-Type') => $_SERVER['HTTP_X_TEST_CTYPE'] );
}
if( isset($_SERVER['HTTP_X_TEST_ROUTES']) ){
	$overArr['route']['folder'] = 'test'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'routes';
	$overArr['route']['htroot'] = 'test'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'pages';
}

$useFixtr = isset($_SERVER['HTTP_X_TEST_RUNS']);

$presetNm = $_SERVER['HTTP_X_TEST_PRESET'] ?? '';

if( empty($overArr) && !$useFixtr && $presetNm=='' ){
	require $topDir.'/www/index.php';
	return;
}

// Same bootstrap as www/index.php, with the overloads applied
define('DS', DIRECTORY_SEPARATOR);
define('TOP_DIR', realpath($topDir));
define('WEB_DIR', TOP_DIR.DS.'www');
define('RUN_DIR', $useFixtr ? TOP_DIR.DS.'test'.DS.'fixtures'.DS.'runs' : TOP_DIR.DS.'runs');
$nyuRun = new \nyu\Runner($presetNm, $overArr);
