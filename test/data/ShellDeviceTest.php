<?php

// Command line, device : the devices of the users of the native userbase, given
// a token, listed and revoked, on the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';
require __DIR__.'/../lib/shellKit.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Shell device', 'pdo_sqlite is not available');
	finishChecking();
}

$basePth = inTemporaryDir('shelldvc.sqlite');
createSampleDb($basePth);
$sqlLink = new PDO('sqlite:'.$basePth);
$projDir = makeProjectTxt('device', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/database.json'=>json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>$basePth, 'user'=>'', 'pass'=>''))
));

// Tokens
$jsnVals = runShellAsJson($projDir, Array('device', 'token', 'Home', 'anna', 'home'));
$homeTkn = strval($jsnVals['secret']['Token of Home'] ?? '');
checkIdentical('token gives a device a token, shown once, only its hash stored', Array($jsnVals['token made'] ?? null, strlen($homeTkn), $sqlLink->query("SELECT devcName, devcRole, tokenKey, user_id FROM devices")->fetchAll(PDO::FETCH_NUM)), Array('Home', 64, Array(Array('Home', 'home', hash('sha256', $homeTkn), 1))));
checkIdentical('token: a name the user already gives a device, another user giving the same name', Array(callShellArray($projDir, Array('device', 'token', 'Home', 'anna', 'home'))['code'], strlen(strval(runShellAsJson($projDir, Array('device', 'token', 'Home', 'ben', 'app'))['secret']['Token of Home'] ?? ''))), Array(3, 64));
checkIdentical('token: a pending, a locked, an unknown user', Array(callShellArray($projDir, Array('device', 'token', 'Tab', 'cleo', 'app'))['code'], callShellArray($projDir, Array('device', 'token', 'Tab', 'dave', 'app'))['code'], callShellArray($projDir, Array('device', 'token', 'Tab', 'zed', 'app'))['code']), Array(1, 1, 1));
checkIdentical('token: a role that is not a word of 8 characters at most, a missing word', Array(callShellArray($projDir, Array('device', 'token', 'Tab', 'anna', 'Home Box'))['code'], callShellArray($projDir, Array('device', 'token', 'Tab', 'anna', 'aboxtoolong'))['code'], callShellArray($projDir, Array('device', 'token', 'Tab', 'anna'))['code']), Array(2, 2, 2));

// Listing
checkIdentical('lists the devices of every user, by user then by name', array_map(function(Array $tableRow){ return $tableRow['user'].'/'.$tableRow['device'].'/'.$tableRow['role']; }, runShellAsJson($projDir, Array('device', 'lists'))['rows'] ?? Array()), Array('anna/Home/home', 'ben/Home/app'));
checkIdentical('lists those of one user', array_column(runShellAsJson($projDir, Array('device', 'lists', 'ben'))['rows'] ?? Array(), 'device'), Array('Home'));
checkIdentical('lists never shows a token', strpos(callShellArray($projDir, Array('device', 'lists'))['text'], hash('sha256', $homeTkn)), false);

// Revoking
checkIdentical('revok revokes the device of that name of that user only', Array(callShellArray($projDir, Array('device', 'revok', 'Home', 'anna'))['code'], $sqlLink->query("SELECT user_id FROM devices WHERE revkDate IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN)), Array(0, Array(1)));
checkPregMatch('revok: kept in the list, with the time of its revocation', strval(runShellAsJson($projDir, Array('device', 'lists', 'anna'))['rows'][0]['revoked'] ?? ''), '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/');
checkIdentical('revok: twice, an unknown device, a missing word', Array(callShellArray($projDir, Array('device', 'revok', 'Home', 'anna'))['code'], callShellArray($projDir, Array('device', 'revok', 'Robot', 'anna'))['code'], callShellArray($projDir, Array('device', 'revok', 'Home'))['code']), Array(3, 3, 2));
checkIdentical('token: once revoked, the name can be given again', strlen(strval(runShellAsJson($projDir, Array('device', 'token', 'Home', 'anna', 'home'))['secret']['Token of Home'] ?? '')), 64);

// A project without a database
$bareDir = makeProjectTxt('nodevice', Array('conf/system.ini'=>buildSystemIni()));
checkIdentical('a project whose devices cannot be read is told so', callShellArray($bareDir, Array('device', 'lists'))['errs'], "The devices cannot be read\n");
$sqlLink = null;

finishChecking();