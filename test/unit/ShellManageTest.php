<?php

// Command line, manage : the users of the users file created, changed and
// removed, the file written as the application reads it, and the actions
// of a run called from the command line.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

/**
 * Reads the users of the users file of a project.
 *
 * @param String $projctRoot Full path of the project.
 * @return Array The users, by name.
 */
function fileUsers( String $projctRoot ): Array {
	$fleVals = json_decode(strval(fetchInProject($projctRoot, 'conf/users.lst')), true);
	return array_column($fleVals['users'] ?? Array(), null, 'systemName');
}

$rndrTxt = '"incoming": [ { "nameFrom": "QUERY", "dataFrom": "QUERY", "fieldSet": "all" } ], "dispatch": { "detectBy": "autopath", "pathForm": "$appToRun/$doAction/$argument" }, "response": { "outputAs": "jsonData", "headList": {} }';
$projDir = makeProjectTxt('sysadmin', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/access.json'=>"{\n\t\"loginSet\":  { \"authWith\": \"userFile\", \"authConf\": \"userFile\", \"sessWith\": \"jwtToken\", \"sessConf\": \"jwtToken\" },\n\t\"userFile\": { \"listFile\": \"conf/users.lst\", \"minChars\": 8 },\n\t\"jwtToken\": { \"hmacSecr\": \"test-only-secret-0123456789-abcdefghij\", \"lifeSecs\": 300, \"signAlgo\": \"HS256\" }\n}\n",
	'conf/presets/default.rps'=>'{ "presetId": "default", "settings": { "confType": "all" }, "security": { "required": false }, '.$rndrTxt.' }',
	'conf/presets/secure.rps'=>'{ "presetId": "secure", "settings": { "confType": "all" }, "security": { "sessWith": "jwtToken", "sessConf": "jwtToken", "authWith": "userFile", "authConf": "userFile", "rteRoles": { "probe/identify": "sysadmin" } }, '.$rndrTxt.' }',
	'test/fixtures/letters.txt'=>strval(file_get_contents(TST_DIR.DS.'fixtures'.DS.'letters.txt'))
));

// The users file : creation
checkIdentical('no file yet is no user', callShellArray($projDir, Array('accounts', 'fullList')), Array('code'=>0, 'text'=>"systemName\nNo user.\n", 'errs'=>''));
$jsnVals = runShellAsJson($projDir, Array('accounts', 'makeUser', 'ann', 'sysadmin'));
$usrList = fileUsers($projDir);
checkIdentical('enrol creates an active user, its password made and shown once', Array($jsnVals['created'] ?? null, strlen($jsnVals['secret']['Password of ann'] ?? ''), password_verify($jsnVals['secret']['Password of ann'] ?? '', $usrList['ann']['passwdHash'] ?? ''), array_diff_key($usrList['ann'] ?? Array(), Array('passwdHash'=>1))), Array('ann', 20, true, Array('id_user'=>1, 'systemName'=>'ann', 'accessRole'=>'sysadmin', 'userStatus'=>'approved')));
checkPregMatch('the file is written one user per line', strval(fetchInProject($projDir, 'conf/users.lst')), '/^\{\n\t"users": \[\n\t\t\{ "id_user": 1, "systemName": "ann", "passwdHash": "[^"]+", "accessRole": "sysadmin", "userStatus": "approved" \}\n\t\]\n\}\n$/D');
checkIdentical('a name taken whatever its case, a name no user is given, a role that is not one', Array(callShellArray($projDir, Array('accounts', 'makeUser', 'Ann'))['errs'], callShellArray($projDir, Array('accounts', 'makeUser', 'root'))['code'], callShellArray($projDir, Array('accounts', 'makeUser', 'bob', 'boss'))['errs']), Array("User name already taken\n", 3, "Invalid role\n"));
checkIdentical('a typed password follows the rules', Array(callShellArray($projDir, Array('accounts', 'makeUser', 'bob', '--typeItIn'), "short\n")['errs'], count(fileUsers($projDir))), Array("Password of bob: Password too short: 8 characters at least\n", 1));
$runVals = callShellArray($projDir, Array('accounts', 'makeUser', 'bob', '--typeItIn'), "pw-bob-long\n");
$usrList = fileUsers($projDir);
checkIdentical('a typed password is not shown; the user is a member when no role is told', Array($runVals['text'], password_verify('pw-bob-long', $usrList['bob']['passwdHash'] ?? ''), $usrList['bob']['accessRole'] ?? null, $usrList['bob']['id_user'] ?? null), Array("created: bob\n", true, 'internal', 2));

// Reading
checkIdentical('lists the users by name, never a hash', runShellAsJson($projDir, Array('accounts', 'fullList'))['rows'] ?? null, Array(
	Array('id_user'=>1, 'systemName'=>'ann', 'accessRole'=>'sysadmin', 'userStatus'=>'approved', 'mailboxAdr'=>'', 'userLocale'=>'', 'hasToken'=>false, 'hasTwoFa'=>false),
	Array('id_user'=>2, 'systemName'=>'bob', 'accessRole'=>'internal', 'userStatus'=>'approved', 'mailboxAdr'=>'', 'userLocale'=>'', 'hasToken'=>false, 'hasTwoFa'=>false)
));
checkIdentical('lists by role, by count; a role that is not one', Array(array_column(runShellAsJson($projDir, Array('accounts', 'fullList', '--roleName=sysadmin'))['rows'] ?? Array(), 'systemName'), array_column(runShellAsJson($projDir, Array('accounts', 'fullList', '--rowCount=1', '--startRow=1'))['rows'] ?? Array(), 'systemName'), callShellArray($projDir, Array('accounts', 'fullList', '--roleName=boss'))['code']), Array(Array('ann'), Array('bob'), 3));
checkIdentical('views a user', callShellArray($projDir, Array('accounts', 'viewUser', 'bob'))['text'], "id_user: 2\nsystemName: bob\naccessRole: internal\nuserStatus: approved\nmailboxAdr: \nuserLocale: \nhasToken: false\nhasTwoFa: false\n");
checkIdentical('email gives an address in the file, in lower case', Array(callShellArray($projDir, Array('accounts', 'setEmail', 'bob', 'Bob@Example.ch'))['text'], fileUsers($projDir)['bob']['mailboxAdr'] ?? null), Array("address set: bob\n", 'bob@example.ch'));
checkIdentical('an address taken, one that is not', Array(callShellArray($projDir, Array('accounts', 'setEmail', 'ann', 'BOB@example.ch'))['errs'], callShellArray($projDir, Array('accounts', 'setEmail', 'ann', 'ann'))['errs']), Array("Mail address already used\n", "Invalid mail address\n"));
checkIdentical('email without an address takes it away from the file', Array(callShellArray($projDir, Array('accounts', 'setEmail', 'bob'))['text'], isset(fileUsers($projDir)['bob']['mailboxAdr'])), Array("address removed: bob\n", false));
checkIdentical('langs gives a language in the file, takes it away', Array(callShellArray($projDir, Array('accounts', 'language', 'bob', 'FR'))['text'], fileUsers($projDir)['bob']['userLocale'] ?? null, callShellArray($projDir, Array('accounts', 'language', 'bob', ''))['text'], isset(fileUsers($projDir)['bob']['userLocale']), callShellArray($projDir, Array('accounts', 'language', 'bob', 'f'))['errs']), Array("language set: bob\n", 'fr', "language removed: bob\n", false, "Invalid language\n"));
callShellArray($projDir, Array('accounts', 'makeUser', 'cid', '--language=it'));
checkIdentical('--language: in the file', fileUsers($projDir)['cid']['userLocale'] ?? null, 'it');
callShellArray($projDir, Array('accounts', 'dropUser', 'cid', '--yesToAll'));
checkIdentical('an unknown user', callShellArray($projDir, Array('accounts', 'viewUser', 'nobody')), Array('code'=>3, 'text'=>'', 'errs'=>"User not found\n"));

// Status, role, removal
checkIdentical('the last active manager is kept as it is', Array(callShellArray($projDir, Array('accounts', 'lockUser', 'ann'))['errs'], callShellArray($projDir, Array('accounts', 'giveRole', 'ann', 'internal'))['code'], callShellArray($projDir, Array('accounts', 'dropUser', 'ann', '--yesToAll'))['code'], count(fileUsers($projDir))), Array("The last active manager cannot be locked\n", 3, 3, 2));
checkIdentical('locks, then unlocks', Array(callShellArray($projDir, Array('accounts', 'lockUser', 'bob'))['text'], fileUsers($projDir)['bob']['userStatus'], callShellArray($projDir, Array('accounts', 'lockUser', 'bob'))['errs'], callShellArray($projDir, Array('accounts', 'freeUser', 'bob'))['text'], fileUsers($projDir)['bob']['userStatus']), Array("locked: bob\n", 'disabled', "User already disabled\n", "unlocked: bob\n", 'approved'));
checkIdentical('roles gives another role', Array(callShellArray($projDir, Array('accounts', 'giveRole', 'bob', 'operator'))['text'], fileUsers($projDir)['bob']['accessRole'], callShellArray($projDir, Array('accounts', 'giveRole', 'bob', 'boss'))['code'], callShellArray($projDir, Array('accounts', 'giveRole', 'bob'))['code']), Array("bob: operator\n", 'operator', 3, 2));

// Password, token, 2FA
$jsnVals = runShellAsJson($projDir, Array('accounts', 'password', 'bob'));
checkIdentical('passw gives a new password, shown once', Array(password_verify($jsnVals['secret']['Password of bob'] ?? '', fileUsers($projDir)['bob']['passwdHash']), password_verify('pw-bob-long', fileUsers($projDir)['bob']['passwdHash'])), Array(true, false));
checkIdentical('reset gives a new password too, and there is no activation', Array(callShellArray($projDir, Array('accounts', 'resetPwd', 'bob', '--typeItIn'), "another-pass\n")['text'], password_verify('another-pass', fileUsers($projDir)['bob']['passwdHash']), callShellArray($projDir, Array('accounts', 'activate', 'bob', 'code'))['code']), Array("password set: bob\n", true, 3));
$jsnVals = runShellAsJson($projDir, Array('accounts', 'apiToken', 'bob'));
checkIdentical('token gives a personal token, its hash alone written', Array(strlen($jsnVals['secret']['Personal token of bob'] ?? ''), fileUsers($projDir)['bob']['bearerHash'] ?? null, runShellAsJson($projDir, Array('accounts', 'viewUser', 'bob'))['hasToken'] ?? null), Array(64, hash('sha256', $jsnVals['secret']['Personal token of bob'] ?? ''), true));
checkIdentical('a token is only taken away when confirmed', Array(callShellArray($projDir, Array('accounts', 'apiToken', 'bob', '--removeIt', '--noPrompt'))['code'], isset(fileUsers($projDir)['bob']['bearerHash']), callShellArray($projDir, Array('accounts', 'apiToken', 'bob', '--removeIt'), "y\n")['text'], isset(fileUsers($projDir)['bob']['bearerHash'])), Array(3, true, "token removed: bob\n", false));
$jsnVals = runShellAsJson($projDir, Array('accounts', 'setTwoFa', 'bob'));
checkIdentical('twofa gives a 2FA secret, with the address an authenticator reads', Array(preg_match('/^[A-Z2-7]{32}$/D', $jsnVals['secret']['2FA secret of bob'] ?? ''), fileUsers($projDir)['bob']['twoFactKey'] ?? null, $jsnVals['secret']['Address for the authenticator'] ?? null), Array(1, $jsnVals['secret']['2FA secret of bob'] ?? '', 'otpauth://totp/nyuRuns:bob?secret='.($jsnVals['secret']['2FA secret of bob'] ?? '').'&issuer=nyuRuns'));
$txtOut = callShellArray($projDir, Array('accounts', 'setTwoFa', 'bob'))['text'];
preg_match('/^Address for the authenticator \(shown this once\): (\S+)\n/m', $txtOut, $adrMtc);
$qrcArt = (new nyu\Data\QrCode($adrMtc[1] ?? 'none', 'M'))->drawAsTerminal(2);
checkIdentical('twofa shows the address as a QR code, after it', Array(str_ends_with($txtOut, "\n".$qrcArt), substr_count($txtOut, '█')>100), Array(true, true));
checkIdentical('twofa --noQrCode leaves the QR code out', str_contains(callShellArray($projDir, Array('accounts', 'setTwoFa', 'bob', '--noQrCode'))['text'], '█'), false);
checkIdentical('a lost authenticator: the secret is taken away', Array(callShellArray($projDir, Array('accounts', 'setTwoFa', 'bob', '--removeIt', '--yesToAll'))['text'], isset(fileUsers($projDir)['bob']['twoFactKey'])), Array("2FA removed: bob\n", false));
// With a key in the block, the secret is written encrypted, and the one shown is still the authenticator's
if( function_exists('openssl_encrypt') ){
	$axsText = strval(fetchInProject($projDir, 'conf/access.json'));
	writeInProject($projDir, 'conf/access.json', str_replace('"minChars": 8 }', '"minChars": 8, "tfaCrypt": "'.str_repeat('k', 40).'" }', $axsText));
	$jsnVals = runShellAsJson($projDir, Array('accounts', 'setTwoFa', 'bob'));
	$sealTxt = strval(fileUsers($projDir)['bob']['twoFactKey'] ?? '');
	checkIdentical('twofa writes the secret encrypted when the block gives a key', Array(preg_match('/^enc1:[A-Za-z0-9+\/=]{80}$/D', $sealTxt), nyu\Access\Totp::openSecretText($sealTxt, str_repeat('k', 40)), runShellAsJson($projDir, Array('accounts', 'viewUser', 'bob'))['hasTwoFa'] ?? null), Array(1, $jsnVals['secret']['2FA secret of bob'] ?? '', true));
	callShellArray($projDir, Array('accounts', 'setTwoFa', 'bob', '--removeIt', '--yesToAll'));
	writeInProject($projDir, 'conf/access.json', $axsText);
}else{
	recordSkipping('twofa: the secret written encrypted', 'the openssl extension is not loaded');
}
callShellArray($projDir, Array('accounts', 'lockUser', 'bob'));
checkIdentical('a locked user is given neither a token nor a secret', Array(callShellArray($projDir, Array('accounts', 'apiToken', 'bob'))['errs'], callShellArray($projDir, Array('accounts', 'setTwoFa', 'bob'))['code']), Array("User not active\n", 3));

// What a person wrote in the file is kept; a file that cannot be used is not written
$fleText = strval(fetchInProject($projDir, 'conf/users.lst'));
writeInProject($projDir, 'conf/users.lst', str_replace('"systemName": "bob"', '"systemName": "bob", "note": "kept"', $fleText));
checkIdentical('a field added by hand is kept, after the others', Array(callShellArray($projDir, Array('accounts', 'freeUser', 'bob'))['code'], fileUsers($projDir)['bob']['note'] ?? null, array_slice(array_keys(fileUsers($projDir)['bob']), -1)), Array(0, 'kept', Array('note')));
checkIdentical('removal needs a confirmation', Array(callShellArray($projDir, Array('accounts', 'dropUser', 'bob', '--noPrompt'))['code'], callShellArray($projDir, Array('accounts', 'dropUser', 'bob'), "no\n")['code'], callShellArray($projDir, Array('accounts', 'dropUser', 'bob'), "yes\n")['text'], array_keys(fileUsers($projDir))), Array(3, 3, "removed: bob\n", Array('ann')));
checkIdentical('the former files are in temp/archives, the newest ones only', count(glob($projDir.DS.'temp'.DS.'archives'.DS.'users.lst.*')), nyu\Shell\ConfFile::KEEP_COPY);
$logText = strval(fetchInProject($projDir, 'temp/logs/access.log'));
checkIdentical('the changes are traced on the access channel, signed', Array(preg_match('/\[NOTICE\] User "bob" created \(internal\) in the users file by user "cli:[\w.\-]+"\n/', $logText), preg_match('/\[NOTICE\] 2FA secret of user "bob" removed in the users file by user/', $logText), preg_match('/\[NOTICE\] User "bob" removed in the users file by user/', $logText), strpos($logText, 'another-pass')), Array(1, 1, 1, false));
checkIdentical('a secret written readable is told, once a write (three): the one written encrypted is not', preg_match_all('/\[WARNING\] 2FA secret of user "bob" stored readable: no tfaCrypt of 32 characters is set/', $logText), 3);
$fleText = strval(fetchInProject($projDir, 'conf/users.lst'));
writeInProject($projDir, 'conf/users.lst', '{ "users": "none" }');
checkIdentical('a file that is not a list of users is a failure, and is left alone', Array(callShellArray($projDir, Array('accounts', 'fullList'))['code'], callShellArray($projDir, Array('accounts', 'makeUser', 'carl'))['code'], fetchInProject($projDir, 'conf/users.lst')), Array(1, 1, '{ "users": "none" }'));
writeInProject($projDir, 'conf/users.lst', $fleText);
checkIdentical('a store the config does not have', Array(callShellArray($projDir, Array('accounts', 'fullList', '--useStore=nativeDb'))['code'], callShellArray($projDir, Array('accounts', 'fullList', '--useStore=other'))['code'], callShellArray($projDir, Array('accounts', 'fullList', '--useBlock=nothing'))['code']), Array(3, 2, 3));

// Calling an action
checkIdentical('calls runs an action and writes its reply', callShellArray($projDir, Array('accounts', 'doAction', 'probe/mirrored/hello')), Array('code'=>0, 'text'=>"{\"got\":\"hello\"}\n", 'errs'=>''));
checkIdentical('the call runs as the user of the system, with the role system', Array(preg_match('/^cli:[\w.\-]+$/D', strval(runShellAsJson($projDir, Array('accounts', 'doAction', 'probe/identify'))['reply']['user'] ?? '')), runShellAsJson($projDir, Array('accounts', 'doAction', 'probe/identify'))['reply']['role'] ?? null, runShellAsJson($projDir, Array('accounts', 'doAction', 'probe/roleList'))['reply'] ?? null), Array(1, 'operator', Array('internal'=>true, 'operator'=>true, 'sysadmin'=>false)));
checkIdentical('--roleName gives it another role; a role the action asks for applies', Array(runShellAsJson($projDir, Array('accounts', 'doAction', 'probe/roleList/sysadmin', '--roleName=sysadmin'))['reply']['sysadmin'] ?? null, callShellArray($projDir, Array('accounts', 'doAction', 'probe/roleList/sysadmin'))['code'], callShellArray($projDir, Array('accounts', 'doAction', 'probe/roleList', '--roleName=boss'))['code']), Array(true, 3, 2));
$runVals = callShellArray($projDir, Array('accounts', 'doAction', 'probe/identify', '--presetId=secure'));
checkIdentical('the role a preset asks for applies', Array($runVals['code'], $runVals['errs'], runShellAsJson($projDir, Array('accounts', 'doAction', 'probe/identify', '--presetId=secure', '--roleName=sysadmin'))['reply']['logged'] ?? null), Array(3, "403 Forbidden\n", true));
$inpVals = runShellAsJson($projDir, Array('accounts', 'doAction', 'forms/transmit', 'name=Ann', 'filters[search]=jazz', 'other=dropped'))['reply'] ?? Array();
checkIdentical('the inputs go through the definition of the action', Array($inpVals['NAME'] ?? null, $inpVals['FILTERS'] ?? null, array_key_exists('OTHER', $inpVals)), Array('Ann', Array('search'=>'jazz'), false));
$inpVals = runShellAsJson($projDir, Array('accounts', 'doAction', 'forms/freeForm', 'b=2', '--bodyFrom=-'), '{"a": 1, "b": 1}')['reply'] ?? Array();
checkIdentical('inputs read as JSON, the words written over them', Array($inpVals['A'] ?? null, $inpVals['B'] ?? null), Array(1, '2'));
checkIdentical('what is not an input, or not an action', Array(callShellArray($projDir, Array('accounts', 'doAction', 'forms/freeForm', 'novalue'))['code'], callShellArray($projDir, Array('accounts', 'doAction', 'forms'))['code'], callShellArray($projDir, Array('accounts', 'doAction', 'forms/freeForm', '--httpVerb=FETCH'))['code'], callShellArray($projDir, Array('accounts', 'doAction', 'forms/freeForm', '--bodyFrom='.$projDir.DS.'nofile.json'))['code']), Array(2, 2, 2, 2));
$runVals = callShellArray($projDir, Array('accounts', 'doAction', 'probe/stopping/fail'));
checkIdentical('a refusal of the action is told, with its exit code', $runVals, Array('code'=>3, 'text'=>"{\"error\":\"Stopped early\",\"code\":404}\n", 'errs'=>"404 Stopped early\n"));
checkIdentical('an unknown application or action: the same refusal', Array(callShellArray($projDir, Array('accounts', 'doAction', 'nothing/here'))['code'], callShellArray($projDir, Array('accounts', 'doAction', 'probe/nothing'))['code']), Array(3, 3));
checkIdentical('a reply without content, a redirection', Array(callShellArray($projDir, Array('accounts', 'doAction', 'probe/stopping/none')), callShellArray($projDir, Array('accounts', 'doAction', 'probe/redirect/out'))), Array(Array('code'=>0, 'text'=>'', 'errs'=>''), Array('code'=>0, 'text'=>"moved: https://example.org/\n", 'errs'=>'')));
$savePth = inTemporaryDir('reply.bin');
checkIdentical('a file reply is written where --saveInto tells', Array(callShellArray($projDir, Array('accounts', 'doAction', 'probe/sendFile', '--saveInto='.$savePth))['code'], is_file($savePth) ? file_get_contents($savePth) : null), Array(0, file_get_contents(TST_DIR.DS.'fixtures'.DS.'letters.txt')));
checkIdentical('a method the request is said to use', runShellAsJson($projDir, Array('accounts', 'doAction', 'probe/userPass', 'user=a', 'pass=b', '--httpVerb=POST'))['code'] ?? null, 401);
checkPregMatch('a call is traced on the access channel', strval(fetchInProject($projDir, 'temp/logs/access.log')), '/\[NOTICE\] Action probe\/mirrored called from the command line by user "cli:[\w.\-]+" \(operator\)\n/');

finishChecking();
