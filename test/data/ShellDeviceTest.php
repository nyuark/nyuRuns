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
	'conf/database.json'=>json_encode(Array('dbEngine'=>'sqlitedb', 'hostName'=>'', 'baseName'=>$basePth, 'userName'=>'', 'passWord'=>''))
));

// Tokens
$jsnVals = runShellAsJson($projDir, Array('devices', 'newToken', 'Home', 'anna', 'home'));
$homeTkn = strval($jsnVals['secret']['Token of Home'] ?? '');
checkIdentical('token gives a device a token, shown once, only its hash stored', Array($jsnVals['token made'] ?? null, strlen($homeTkn), $sqlLink->query("SELECT deviceName, deviceRole, bearerHash, user_id FROM devices")->fetchAll(PDO::FETCH_NUM)), Array('Home', 64, Array(Array('Home', 'home', hash('sha256', $homeTkn), 1))));
checkIdentical('token: a name the user already gives a device, another user giving the same name', Array(callShellArray($projDir, Array('devices', 'newToken', 'Home', 'anna', 'home'))['code'], strlen(strval(runShellAsJson($projDir, Array('devices', 'newToken', 'Home', 'ben', 'app'))['secret']['Token of Home'] ?? ''))), Array(3, 64));
checkIdentical('token: a pending, a locked, an unknown user', Array(callShellArray($projDir, Array('devices', 'newToken', 'Tab', 'cleo', 'app'))['code'], callShellArray($projDir, Array('devices', 'newToken', 'Tab', 'dave', 'app'))['code'], callShellArray($projDir, Array('devices', 'newToken', 'Tab', 'zed', 'app'))['code']), Array(1, 1, 1));
checkIdentical('token: a role that is not a word of 8 characters at most, a missing word', Array(callShellArray($projDir, Array('devices', 'newToken', 'Tab', 'anna', 'Home Box'))['code'], callShellArray($projDir, Array('devices', 'newToken', 'Tab', 'anna', 'aboxtoolong'))['code'], callShellArray($projDir, Array('devices', 'newToken', 'Tab', 'anna'))['code']), Array(2, 2, 2));

// The cap : standard when not given, never above the user's role, one of the roles
checkIdentical('token: capped at standard when not given; at the role --roleTops tells, up to the user\'s own', Array(runShellAsJson($projDir, Array('devices', 'newToken', 'Desk', 'anna', 'app', '--roleTops=advanced'))['token made'] ?? null, $sqlLink->query("SELECT deviceName, deviceTops FROM devices WHERE user_id=1 ORDER BY id_device")->fetchAll(PDO::FETCH_KEY_PAIR)), Array('Desk', Array('Home'=>'standard', 'Desk'=>'advanced')));
$abovRun = callShellArray($projDir, Array('devices', 'newToken', 'Tab', 'ben', 'app', '--roleTops=managers'));
checkIdentical('token: a cap above the user\'s role is refused, never lowered; one that is not a role too', Array($abovRun['code'], trim($abovRun['errs']), callShellArray($projDir, Array('devices', 'newToken', 'Tab', 'anna', 'app', '--roleTops=root'))['code'], intval($sqlLink->query("SELECT COUNT(*) FROM devices WHERE deviceName='Tab'")->fetchColumn())), Array(3, 'No token made: the cap managers is above the role of "ben", standard: give it standard at most', 2, 0));
checkIdentical('lists the cap of each device', array_column(runShellAsJson($projDir, Array('devices', 'fullList', 'anna'))['rows'] ?? Array(), 'cap', 'device'), Array('Desk'=>'advanced', 'Home'=>'standard'));
// install checkAll : the devices of a user above standard without a cap are told
writeInProject($projDir, 'conf/access.json', '{ "dvcToken": { "useTable": "devices" } }');
$dvcCaps = function() use ( $projDir ): Array { return array_values(array_filter(runShellAsJson($projDir, Array('install', 'checkAll'))['rows'] ?? Array(), function( Array $checkRowArr ): Bool { return $checkRowArr['check']=='device caps'; })); };
checkIdentical('checkAll: every device capped, fine', array_column($dvcCaps(), 'state'), Array('fine'));
$sqlLink->exec("UPDATE devices SET deviceTops=NULL WHERE deviceName='Desk' OR user_id=2");
checkIdentical('checkAll: a device without a cap whose user is above standard is told, not one of a standard user', array_map(function( Array $checkRowArr ): Array { return Array($checkRowArr['state'], str_contains($checkRowArr['found'], 'anna/Desk (managers)'), str_contains($checkRowArr['found'], 'ben/')); }, $dvcCaps()), Array(Array('warn', true, false)));
$sqlLink->exec("UPDATE devices SET deviceTops='advanced' WHERE deviceName='Desk'");
@unlink($projDir.DS.'conf'.DS.'access.json');
// Listing
checkIdentical('lists the devices of every user, by user then by name', array_map(function(Array $tableRow){ return $tableRow['user'].'/'.$tableRow['device'].'/'.$tableRow['role']; }, runShellAsJson($projDir, Array('devices', 'fullList'))['rows'] ?? Array()), Array('anna/Desk/app', 'anna/Home/home', 'ben/Home/app'));
checkIdentical('lists those of one user', array_column(runShellAsJson($projDir, Array('devices', 'fullList', 'ben'))['rows'] ?? Array(), 'device'), Array('Home'));
checkIdentical('lists never shows a token', strpos(callShellArray($projDir, Array('devices', 'fullList'))['text'], hash('sha256', $homeTkn)), false);

// Revoking
checkIdentical('revok revokes the device of that name of that user only', Array(callShellArray($projDir, Array('devices', 'revokeIt', 'Home', 'anna'))['code'], $sqlLink->query("SELECT user_id FROM devices WHERE revokeDate IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN)), Array(0, Array(1)));
checkPregMatch('revok: kept in the list, with the time of its revocation', strval(array_column(runShellAsJson($projDir, Array('devices', 'fullList', 'anna'))['rows'] ?? Array(), 'revoked', 'device')['Home'] ?? ''), '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/');
checkIdentical('revok: twice, an unknown device, a missing word', Array(callShellArray($projDir, Array('devices', 'revokeIt', 'Home', 'anna'))['code'], callShellArray($projDir, Array('devices', 'revokeIt', 'Robot', 'anna'))['code'], callShellArray($projDir, Array('devices', 'revokeIt', 'Home'))['code']), Array(3, 3, 2));
checkIdentical('token: once revoked, the name can be given again', strlen(strval(runShellAsJson($projDir, Array('devices', 'newToken', 'Home', 'anna', 'home'))['secret']['Token of Home'] ?? '')), 64);

// A project without a database
$bareDir = makeProjectTxt('nodevice', Array('conf/system.ini'=>buildSystemIni()));
checkIdentical('a project whose devices cannot be read is told so', callShellArray($bareDir, Array('devices', 'fullList'))['errs'], "The devices cannot be read\n");
$sqlLink = null;

finishChecking();