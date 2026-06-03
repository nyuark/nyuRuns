<?php

// Command line, device : the devices of the users of the native userbase, given
// a token, listed and revoked, on the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';
require __DIR__.'/../lib/shellKit.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Shell device', 'pdo_sqlite is not available');
	endChecks();
}

$basePth = inTempDir('shelldvc.sqlite');
buildData($basePth);
$sqlLink = new PDO('sqlite:'.$basePth);
$projDir = makeProjt('device', Array(
	'conf/system.ini'=>sampleIni(),
	'conf/database.json'=>json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>$basePth, 'user'=>'', 'pass'=>''))
));

// Tokens
$jsnVals = jsonShell($projDir, Array('device', 'token', 'Home', 'anna', 'home'));
$homeTkn = strval($jsnVals['secret']['Token of Home'] ?? '');
checkSame('token gives a device a token, shown once, only its hash stored', Array($jsnVals['token made'] ?? null, strlen($homeTkn), $sqlLink->query("SELECT name, role, token_hash, user_id FROM devices")->fetchAll(PDO::FETCH_NUM)), Array('Home', 64, Array(Array('Home', 'home', hash('sha256', $homeTkn), 1))));
checkSame('token: a name the user already gives a device, another user giving the same name', Array(runShell($projDir, Array('device', 'token', 'Home', 'anna', 'home'))['code'], strlen(strval(jsonShell($projDir, Array('device', 'token', 'Home', 'ben', 'app'))['secret']['Token of Home'] ?? ''))), Array(3, 64));
checkSame('token: a pending, a locked, an unknown user', Array(runShell($projDir, Array('device', 'token', 'Tab', 'cleo', 'app'))['code'], runShell($projDir, Array('device', 'token', 'Tab', 'dave', 'app'))['code'], runShell($projDir, Array('device', 'token', 'Tab', 'zed', 'app'))['code']), Array(1, 1, 1));
checkSame('token: a role that is not a word of 8 characters at most, a missing word', Array(runShell($projDir, Array('device', 'token', 'Tab', 'anna', 'Home Box'))['code'], runShell($projDir, Array('device', 'token', 'Tab', 'anna', 'aboxtoolong'))['code'], runShell($projDir, Array('device', 'token', 'Tab', 'anna'))['code']), Array(2, 2, 2));

// Listing
checkSame('lists the devices of every user, by user then by name', array_map(function(Array $tableRow){ return $tableRow['user'].'/'.$tableRow['device'].'/'.$tableRow['role']; }, jsonShell($projDir, Array('device', 'lists'))['rows'] ?? Array()), Array('anna/Home/home', 'ben/Home/app'));
checkSame('lists those of one user', array_column(jsonShell($projDir, Array('device', 'lists', 'ben'))['rows'] ?? Array(), 'device'), Array('Home'));
checkSame('lists never shows a token', strpos(runShell($projDir, Array('device', 'lists'))['text'], hash('sha256', $homeTkn)), false);

// Revoking
checkSame('revok revokes the device of that name of that user only', Array(runShell($projDir, Array('device', 'revok', 'Home', 'anna'))['code'], $sqlLink->query("SELECT user_id FROM devices WHERE revoked_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN)), Array(0, Array(1)));
checkLike('revok: kept in the list, with the time of its revocation', strval(jsonShell($projDir, Array('device', 'lists', 'anna'))['rows'][0]['revoked'] ?? ''), '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/');
checkSame('revok: twice, an unknown device, a missing word', Array(runShell($projDir, Array('device', 'revok', 'Home', 'anna'))['code'], runShell($projDir, Array('device', 'revok', 'Robot', 'anna'))['code'], runShell($projDir, Array('device', 'revok', 'Home'))['code']), Array(3, 3, 2));
checkSame('token: once revoked, the name can be given again', strlen(strval(jsonShell($projDir, Array('device', 'token', 'Home', 'anna', 'home'))['secret']['Token of Home'] ?? '')), 64);

// A project without a database
$bareDir = makeProjt('nodevice', Array('conf/system.ini'=>sampleIni()));
checkSame('a project whose devices cannot be read is told so', runShell($bareDir, Array('device', 'lists'))['errs'], "The devices cannot be read\n");
$sqlLink = null;

endChecks();