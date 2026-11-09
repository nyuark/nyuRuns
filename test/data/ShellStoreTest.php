<?php

// Command line, installs moveUser : the users moved from the native userbase to the
// users file and back, with their passwords, roles, statuses, tokens and 2FA
// secrets, on SQLite databases (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';
require __DIR__.'/../lib/shellKit.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Shell store', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Access\Totp;

$natSeal = 'native-key-of-the-2FA-secrets-0123456789';
$lstSeal = 'file-key-of-the-2FA-secrets-9876543210ab';
$twoFaKy = 'JBSWY3DPEHPK3PXP';
$canSeal = function_exists('openssl_encrypt');

// From the native userbase to the users file
$basePth = inTemporaryDir('store.sqlite');
createSampleDb($basePth);
$sqlLink = new PDO('sqlite:'.$basePth);
$sqlLink->exec("UPDATE users SET actvtnCode='code-of-cleo' WHERE userHandle='cleo'");
$sqlLink->exec("UPDATE users SET mailboxAdr='ben@example.ch', userLocale='fr-ch', recovrCode='reset-of-ben', codeExpiry='2026-11-04 10:00:00' WHERE userHandle='ben'");
$sqlLink->exec("INSERT INTO users (id_user, userHandle, passwdHash, accessRole, userStatus, mailboxAdr, signupDate) VALUES (5, 'erin', '".password_hash('pw-erin', PASSWORD_BCRYPT, Array('cost'=>4))."', 'member', 'review', 'erin@example.ch', '2026-11-04 09:00:00')");
$sqlLink->prepare("UPDATE users SET twoFactKey=?, bearerHash=? WHERE userHandle='anna'")->execute( Array(Totp::sealSecretText($twoFaKy, $natSeal), hash('sha256', 'token-of-anna')) );
$sqlLink->exec("INSERT INTO devices (deviceName, deviceRole, bearerHash, user_id) VALUES ('Phone', 'app', '".hash('sha256', 'phone')."', 2)");
$hashLst = $sqlLink->query('SELECT userHandle, passwdHash FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
$projDir = makeProjectTxt('storenat', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/database.json'=>json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>$basePth, 'user'=>'', 'pass'=>'')),
	'conf/access.json'=>json_encode(Array(
		'loginSet'=>Array('authWith'=>'nativeDb', 'authConf'=>'nativeDb', 'sessWith'=>'jwtToken', 'sessConf'=>'jwtToken'),
		'nativeDb'=>Array('useTable'=>'users', 'cryptKey'=>$natSeal),
		'userFile'=>Array('listFile'=>'conf/users.lst', 'cryptKey'=>$lstSeal),
		'devToken'=>Array('useTable'=>'devices', 'usersTbl'=>'users')
	), JSON_PRETTY_PRINT)
));
checkIdentical('a store that is not one, a missing word', Array(callShellArray($projDir, Array('installs', 'moveUser', 'ldap'))['code'], callShellArray($projDir, Array('installs', 'moveUser'))['code']), Array(2, 2));
checkIdentical('the store the login already uses', callShellArray($projDir, Array('installs', 'moveUser', 'nativeDb'))['code'], 3);
$runVals = callShellArray($projDir, Array('installs', 'moveUser', 'userFile', '--yesToAll'));
checkIdentical('active devices stop the move', Array($runVals['code'], strpos($runVals['errs'], 'active devices need the native userbase (ben/Phone)')!==false, is_file($projDir.DS.'conf'.DS.'users.lst')), Array(3, true, false));
checkIdentical('without --yesToAll, --noPrompt moves nothing', Array(callShellArray($projDir, Array('installs', 'moveUser', 'userFile', '--dropsAll', '--noPrompt'))['code'], is_file($projDir.DS.'conf'.DS.'users.lst')), Array(3, false));
$jsnVals = runShellAsJson($projDir, Array('installs', 'moveUser', 'userFile', '--dropsAll', '--yesToAll'));
$axsVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
$usrList = array_column(json_decode(strval(fetchInProject($projDir, 'conf/users.lst')), true)['users'] ?? Array(), null, 'userHandle');
checkIdentical('the users go to the file with their identifier, role and status; the login follows', Array($jsnVals['exit'] ?? null, $jsnVals['moved'] ?? null, array_map(function( Array $userValues ): String { return $userValues['id_user'].'/'.$userValues['accessRole'].'/'.$userValues['userStatus']; }, $usrList), $axsVals['loginSet']['authWith'] ?? null, $axsVals['loginSet']['authConf'] ?? null),
	Array(0, 5, Array('anna'=>'1/manage/active', 'ben'=>'2/member/active', 'cleo'=>'3/member/pendng', 'dave'=>'4/system/locked', 'erin'=>'5/member/review'), 'userFile', 'userFile'));
checkIdentical('the addresses go along', array_column($usrList, 'mailboxAdr', 'userHandle'), Array('ben'=>'ben@example.ch', 'erin'=>'erin@example.ch'));
checkIdentical('the languages go along', array_column($usrList, 'userLocale', 'userHandle'), Array('ben'=>'fr-ch'));
checkIdentical('the passwords and the personal token go along unchanged', Array(array_column($usrList, 'passwdHash', 'userHandle'), $usrList['anna']['bearerHash'] ?? null), Array($hashLst, hash('sha256', 'token-of-anna')));
checkIdentical('what is not kept is told', array_column($jsnVals['rows'] ?? Array(), 'details', 'not kept'), Array(
	'activation codes'=>'cleo: pending until made active, or given a password, with manage',
	'reset codes'=>'ben: the resets asked are forgotten, the passwords kept',
	'sign-ups to review'=>'erin: kept in review, which the users file cannot approve; accounts freeUser makes them active',
	'presence and 2FA steps'=>'the users file is only read: last seen, failed attempts and the step of the last 2FA code are not kept',
	'devices'=>'ben/Phone: revoked'
));
if( $canSeal ){
	checkIdentical('the 2FA secret is encrypted again with the key of the file', Array(Totp::openSecretText(strval($usrList['anna']['twoFactKey'] ?? ''), $lstSeal), Totp::openSecretText(strval($usrList['anna']['twoFactKey'] ?? ''), $natSeal)), Array($twoFaKy, null));
}
checkIdentical('the userbase left is kept, the device revoked', Array(intval($sqlLink->query('SELECT COUNT(*) FROM users')->fetchColumn()), $sqlLink->query('SELECT revokeDate FROM devices')->fetchColumn()!==null), Array(5, true));
checkPregMatch('the move is traced', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Deploy: 5 users moved from nativeDb to userFile, 1 devices revoked, the login points at userFile by user "cli:/');
checkIdentical('back to a userbase that holds users: refused', Array(callShellArray($projDir, Array('installs', 'moveUser', 'nativeDb', '--yesToAll', '--noPrompt'))['code'], json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true)['loginSet']['authWith'] ?? null), Array(3, 'userFile'));
$sqlLink = null;

// From the users file to a new native userbase, its table created
$newBase = inTemporaryDir('storenew.sqlite');
if( file_exists($newBase) ){
	unlink($newBase);
}
$fleUsrs = strval(file_get_contents(TST_DIR.DS.'fixtures'.DS.'users.lst'));
$projDir = makeProjectTxt('storelst', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/database.json'=>json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>$newBase, 'user'=>'', 'pass'=>'')),
	'conf/schema/users.sqlte.sql'=>strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql')),
	'conf/access.json'=>json_encode(Array(
		'loginSet'=>Array('authWith'=>'userFile', 'authConf'=>'userFile', 'sessWith'=>'jwtToken', 'sessConf'=>'jwtToken'),
		'userFile'=>Array('listFile'=>'conf/users.lst')
	), JSON_PRETTY_PRINT),
	'conf/users.lst'=>$fleUsrs
));
$jsnVals = runShellAsJson($projDir, Array('installs', 'moveUser', 'nativeDb', '--yesToAll', '--noPrompt'));
$newLink = new PDO('sqlite:'.$newBase);
$rowList = $newLink->query('SELECT id_user, userHandle, passwdHash, accessRole, userStatus, bearerHash, twoFactKey FROM users ORDER BY id_user')->fetchAll(PDO::FETCH_ASSOC);
$axsVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
checkIdentical('the table is created and the users written; a user without an identifier gets the next one, the role and status the file leaves out', Array($jsnVals['exit'] ?? null, $jsnVals['moved'] ?? null, array_map(function( Array $userValues ): String { return $userValues['id_user'].'/'.$userValues['userHandle'].'/'.$userValues['accessRole'].'/'.$userValues['userStatus']; }, $rowList)),
	Array(0, 5, Array('1/anna/manage/active', '2/ben/member/active', '3/cleo/member/pendng', '4/dave/system/locked', '5/emma/member/active')));
$lstVals = array_column(json_decode($fleUsrs, true)['users'], null, 'userHandle');
checkIdentical('the hashes, the token and the 2FA secret go along', Array(array_column($rowList, 'passwdHash', 'userHandle'), $rowList[0]['bearerHash'], $rowList[4]['twoFactKey']), Array(array_column($lstVals, 'passwdHash', 'userHandle'), $lstVals['anna']['bearerHash'], $lstVals['emma']['twoFactKey']));
checkIdentical('the login follows, the native block is made, the file is kept', Array($axsVals['loginSet']['authWith'] ?? null, $axsVals['nativeDb']['useTable'] ?? null, fetchInProject($projDir, 'conf/users.lst')), Array('nativeDb', 'users', str_replace("\r\n", "\n", $fleUsrs)));
$newLink->exec("INSERT INTO users (userHandle, passwdHash) VALUES ('fred', '!')");
checkIdentical('a user created next takes the identifier after them', intval($newLink->lastInsertId()), 6);
$newLink = null;

// A user without a password hash
$projDir = makeProjectTxt('storenop', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/database.json'=>json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>inTemporaryDir('storenop.sqlite'), 'user'=>'', 'pass'=>'')),
	'conf/schema/users.sqlte.sql'=>strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql')),
	'conf/access.json'=>json_encode(Array('loginSet'=>Array('authWith'=>'userFile', 'authConf'=>'userFile'), 'userFile'=>Array('listFile'=>'conf/users.lst'))),
	'conf/users.lst'=>'{ "users": [ { "userHandle": "zoe", "accessRole": "member" } ] }'
));
$runVals = callShellArray($projDir, Array('installs', 'moveUser', 'nativeDb', '--yesToAll', '--noPrompt'));
checkIdentical('a user without a password hash stops the move', Array($runVals['code'], $runVals['errs']), Array(3, "Nothing was moved: users without a password hash (zoe); give them a password with manage first\n"));

finishChecking();
