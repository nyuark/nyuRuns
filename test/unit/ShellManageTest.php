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
	return array_column($fleVals['users'] ?? Array(), null, 'userName');
}

$rndrTxt = '"input": [ { "naming": "QUERY", "source": "QUERY", "fields": "all" } ], "route": { "detect": "autopath", "mapper": "$app/$action/$param" }, "rnder": { "format": "json", "headrs": {} }';
$projDir = makeProjectTxt('manage', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/access.json'=>"{\n\t\"login\":  { \"authby\": \"usrlst\", \"authcf\": \"usrlst\", \"sessby\": \"jtoken\", \"sesscf\": \"dftokn\" },\n\t\"usrlst\": { \"usrfle\": \"conf/users.lst\", \"minlen\": 8 },\n\t\"dftokn\": { \"secret\": \"test-only-secret-0123456789-abcdefghij\", \"lifetm\": 300, \"algrtm\": \"HS256\" }\n}\n",
	'conf/presets/dfault.rps'=>'{ "label": "dfault", "setup": { "ftypes": "all" }, "auths": { "needed": false }, '.$rndrTxt.' }',
	'conf/presets/secure.rps'=>'{ "label": "secure", "setup": { "ftypes": "all" }, "auths": { "sessby": "jtoken", "sesscf": "dftokn", "authby": "usrlst", "authcf": "usrlst", "rolert": { "probe/identify": "manage" } }, '.$rndrTxt.' }',
	'test/fixtures/letters.txt'=>strval(file_get_contents(TST_DIR.DS.'fixtures'.DS.'letters.txt'))
));

// The users file : creation
checkIdentical('no file yet is no user', callShellArray($projDir, Array('manage', 'lists')), Array('code'=>0, 'text'=>"userName\nNo user.\n", 'errs'=>''));
$jsnVals = runShellAsJson($projDir, Array('manage', 'enrol', 'ann', 'manage'));
$usrList = fileUsers($projDir);
checkIdentical('enrol creates an active user, its password made and shown once', Array($jsnVals['created'] ?? null, strlen($jsnVals['secret']['Password of ann'] ?? ''), password_verify($jsnVals['secret']['Password of ann'] ?? '', $usrList['ann']['passHash'] ?? ''), array_diff_key($usrList['ann'] ?? Array(), Array('passHash'=>1))), Array('ann', 20, true, Array('id_user'=>1, 'userName'=>'ann', 'userRole'=>'manage', 'userStat'=>'active')));
checkPregMatch('the file is written one user per line', strval(fetchInProject($projDir, 'conf/users.lst')), '/^\{\n\t"users": \[\n\t\t\{ "id_user": 1, "userName": "ann", "passHash": "[^"]+", "userRole": "manage", "userStat": "active" \}\n\t\]\n\}\n$/D');
checkIdentical('a name taken whatever its case, a name no user is given, a role that is not one', Array(callShellArray($projDir, Array('manage', 'enrol', 'Ann'))['errs'], callShellArray($projDir, Array('manage', 'enrol', 'root'))['code'], callShellArray($projDir, Array('manage', 'enrol', 'bob', 'boss'))['errs']), Array("User name already taken\n", 3, "Invalid role\n"));
checkIdentical('a typed password follows the rules', Array(callShellArray($projDir, Array('manage', 'enrol', 'bob', '--typed'), "short\n")['errs'], count(fileUsers($projDir))), Array("Password of bob: Password too short: 8 characters at least\n", 1));
$runVals = callShellArray($projDir, Array('manage', 'enrol', 'bob', '--typed'), "pw-bob-long\n");
$usrList = fileUsers($projDir);
checkIdentical('a typed password is not shown; the user is a member when no role is told', Array($runVals['text'], password_verify('pw-bob-long', $usrList['bob']['passHash'] ?? ''), $usrList['bob']['userRole'] ?? null, $usrList['bob']['id_user'] ?? null), Array("created: bob\n", true, 'member', 2));

// Reading
checkIdentical('lists the users by name, never a hash', runShellAsJson($projDir, Array('manage', 'lists'))['rows'] ?? null, Array(
	Array('id_user'=>1, 'userName'=>'ann', 'userRole'=>'manage', 'userStat'=>'active', 'userMail'=>'', 'userLang'=>'', 'hasToken'=>false, 'hasTwoFa'=>false),
	Array('id_user'=>2, 'userName'=>'bob', 'userRole'=>'member', 'userStat'=>'active', 'userMail'=>'', 'userLang'=>'', 'hasToken'=>false, 'hasTwoFa'=>false)
));
checkIdentical('lists by role, by count; a role that is not one', Array(array_column(runShellAsJson($projDir, Array('manage', 'lists', '--urole=manage'))['rows'] ?? Array(), 'userName'), array_column(runShellAsJson($projDir, Array('manage', 'lists', '--count=1', '--start=1'))['rows'] ?? Array(), 'userName'), callShellArray($projDir, Array('manage', 'lists', '--urole=boss'))['code']), Array(Array('ann'), Array('bob'), 3));
checkIdentical('views a user', callShellArray($projDir, Array('manage', 'views', 'bob'))['text'], "id_user: 2\nuserName: bob\nuserRole: member\nuserStat: active\nuserMail: \nuserLang: \nhasToken: false\nhasTwoFa: false\n");
checkIdentical('email gives an address in the file, in lower case', Array(callShellArray($projDir, Array('manage', 'email', 'bob', 'Bob@Example.ch'))['text'], fileUsers($projDir)['bob']['userMail'] ?? null), Array("address set: bob\n", 'bob@example.ch'));
checkIdentical('an address taken, one that is not', Array(callShellArray($projDir, Array('manage', 'email', 'ann', 'BOB@example.ch'))['errs'], callShellArray($projDir, Array('manage', 'email', 'ann', 'ann'))['errs']), Array("Mail address already used\n", "Invalid mail address\n"));
checkIdentical('email without an address takes it away from the file', Array(callShellArray($projDir, Array('manage', 'email', 'bob'))['text'], isset(fileUsers($projDir)['bob']['userMail'])), Array("address removed: bob\n", false));
checkIdentical('langs gives a language in the file, takes it away', Array(callShellArray($projDir, Array('manage', 'langs', 'bob', 'FR'))['text'], fileUsers($projDir)['bob']['userLang'] ?? null, callShellArray($projDir, Array('manage', 'langs', 'bob', ''))['text'], isset(fileUsers($projDir)['bob']['userLang']), callShellArray($projDir, Array('manage', 'langs', 'bob', 'f'))['errs']), Array("language set: bob\n", 'fr', "language removed: bob\n", false, "Invalid language\n"));
callShellArray($projDir, Array('manage', 'enrol', 'cid', '--ulang=it'));
checkIdentical('--ulang: in the file', fileUsers($projDir)['cid']['userLang'] ?? null, 'it');
callShellArray($projDir, Array('manage', 'remov', 'cid', '--agree'));
checkIdentical('an unknown user', callShellArray($projDir, Array('manage', 'views', 'nobody')), Array('code'=>3, 'text'=>'', 'errs'=>"User not found\n"));

// Status, role, removal
checkIdentical('the last active manager is kept as it is', Array(callShellArray($projDir, Array('manage', 'locks', 'ann'))['errs'], callShellArray($projDir, Array('manage', 'roles', 'ann', 'member'))['code'], callShellArray($projDir, Array('manage', 'remov', 'ann', '--agree'))['code'], count(fileUsers($projDir))), Array("The last active manager cannot be locked\n", 3, 3, 2));
checkIdentical('locks, then unlocks', Array(callShellArray($projDir, Array('manage', 'locks', 'bob'))['text'], fileUsers($projDir)['bob']['userStat'], callShellArray($projDir, Array('manage', 'locks', 'bob'))['errs'], callShellArray($projDir, Array('manage', 'unlck', 'bob'))['text'], fileUsers($projDir)['bob']['userStat']), Array("locked: bob\n", 'locked', "User already locked\n", "unlocked: bob\n", 'active'));
checkIdentical('roles gives another role', Array(callShellArray($projDir, Array('manage', 'roles', 'bob', 'system'))['text'], fileUsers($projDir)['bob']['userRole'], callShellArray($projDir, Array('manage', 'roles', 'bob', 'boss'))['code'], callShellArray($projDir, Array('manage', 'roles', 'bob'))['code']), Array("bob: system\n", 'system', 3, 2));

// Password, token, 2FA
$jsnVals = runShellAsJson($projDir, Array('manage', 'passw', 'bob'));
checkIdentical('passw gives a new password, shown once', Array(password_verify($jsnVals['secret']['Password of bob'] ?? '', fileUsers($projDir)['bob']['passHash']), password_verify('pw-bob-long', fileUsers($projDir)['bob']['passHash'])), Array(true, false));
checkIdentical('reset gives a new password too, and there is no activation', Array(callShellArray($projDir, Array('manage', 'reset', 'bob', '--typed'), "another-pass\n")['text'], password_verify('another-pass', fileUsers($projDir)['bob']['passHash']), callShellArray($projDir, Array('manage', 'actvt', 'bob', 'code'))['code']), Array("password set: bob\n", true, 3));
$jsnVals = runShellAsJson($projDir, Array('manage', 'token', 'bob'));
checkIdentical('token gives a personal token, its hash alone written', Array(strlen($jsnVals['secret']['Personal token of bob'] ?? ''), fileUsers($projDir)['bob']['tokenKey'] ?? null, runShellAsJson($projDir, Array('manage', 'views', 'bob'))['hasToken'] ?? null), Array(64, hash('sha256', $jsnVals['secret']['Personal token of bob'] ?? ''), true));
checkIdentical('a token is only taken away when confirmed', Array(callShellArray($projDir, Array('manage', 'token', 'bob', '--clear', '--noask'))['code'], isset(fileUsers($projDir)['bob']['tokenKey']), callShellArray($projDir, Array('manage', 'token', 'bob', '--clear'), "y\n")['text'], isset(fileUsers($projDir)['bob']['tokenKey'])), Array(3, true, "token removed: bob\n", false));
$jsnVals = runShellAsJson($projDir, Array('manage', 'twofa', 'bob'));
checkIdentical('twofa gives a 2FA secret, with the address an authenticator reads', Array(preg_match('/^[A-Z2-7]{32}$/D', $jsnVals['secret']['2FA secret of bob'] ?? ''), fileUsers($projDir)['bob']['twoFaKey'] ?? null, $jsnVals['secret']['Address for the authenticator'] ?? null), Array(1, $jsnVals['secret']['2FA secret of bob'] ?? '', 'otpauth://totp/nyuRuns:bob?secret='.($jsnVals['secret']['2FA secret of bob'] ?? '').'&issuer=nyuRuns'));
$txtOut = callShellArray($projDir, Array('manage', 'twofa', 'bob'))['text'];
preg_match('/^Address for the authenticator \(shown this once\): (\S+)\n/m', $txtOut, $adrMtc);
$qrcArt = (new nyu\Data\QrCode($adrMtc[1] ?? 'none', 'M'))->drawAsTerminal(2);
checkIdentical('twofa shows the address as a QR code, after it', Array(str_ends_with($txtOut, "\n".$qrcArt), substr_count($txtOut, '█')>100), Array(true, true));
checkIdentical('twofa --plain leaves the QR code out', str_contains(callShellArray($projDir, Array('manage', 'twofa', 'bob', '--plain'))['text'], '█'), false);
checkIdentical('a lost authenticator: the secret is taken away', Array(callShellArray($projDir, Array('manage', 'twofa', 'bob', '--clear', '--agree'))['text'], isset(fileUsers($projDir)['bob']['twoFaKey'])), Array("2FA removed: bob\n", false));
// With a key in the block, the secret is written encrypted, and the one shown is still the authenticator's
if( function_exists('openssl_encrypt') ){
	$axsText = strval(fetchInProject($projDir, 'conf/access.json'));
	writeInProject($projDir, 'conf/access.json', str_replace('"minlen": 8 }', '"minlen": 8, "tfakey": "'.str_repeat('k', 40).'" }', $axsText));
	$jsnVals = runShellAsJson($projDir, Array('manage', 'twofa', 'bob'));
	$sealTxt = strval(fileUsers($projDir)['bob']['twoFaKey'] ?? '');
	checkIdentical('twofa writes the secret encrypted when the block gives a key', Array(preg_match('/^enc1:[A-Za-z0-9+\/=]{80}$/D', $sealTxt), nyu\Access\Totp::openSecretText($sealTxt, str_repeat('k', 40)), runShellAsJson($projDir, Array('manage', 'views', 'bob'))['hasTwoFa'] ?? null), Array(1, $jsnVals['secret']['2FA secret of bob'] ?? '', true));
	callShellArray($projDir, Array('manage', 'twofa', 'bob', '--clear', '--agree'));
	writeInProject($projDir, 'conf/access.json', $axsText);
}else{
	recordSkipping('twofa: the secret written encrypted', 'the openssl extension is not loaded');
}
callShellArray($projDir, Array('manage', 'locks', 'bob'));
checkIdentical('a locked user is given neither a token nor a secret', Array(callShellArray($projDir, Array('manage', 'token', 'bob'))['errs'], callShellArray($projDir, Array('manage', 'twofa', 'bob'))['code']), Array("User not active\n", 3));

// What a person wrote in the file is kept; a file that cannot be used is not written
$fleText = strval(fetchInProject($projDir, 'conf/users.lst'));
writeInProject($projDir, 'conf/users.lst', str_replace('"userName": "bob"', '"userName": "bob", "note": "kept"', $fleText));
checkIdentical('a field added by hand is kept, after the others', Array(callShellArray($projDir, Array('manage', 'unlck', 'bob'))['code'], fileUsers($projDir)['bob']['note'] ?? null, array_slice(array_keys(fileUsers($projDir)['bob']), -1)), Array(0, 'kept', Array('note')));
checkIdentical('removal needs a confirmation', Array(callShellArray($projDir, Array('manage', 'remov', 'bob', '--noask'))['code'], callShellArray($projDir, Array('manage', 'remov', 'bob'), "no\n")['code'], callShellArray($projDir, Array('manage', 'remov', 'bob'), "yes\n")['text'], array_keys(fileUsers($projDir))), Array(3, 3, "removed: bob\n", Array('ann')));
checkIdentical('the former files are in temp/backup, the newest ones only', count(glob($projDir.DS.'temp'.DS.'backup'.DS.'users.lst.*')), nyu\Shell\ConfFile::KEEP_COPY);
$logText = strval(fetchInProject($projDir, 'temp/logs/access.log'));
checkIdentical('the changes are traced on the access channel, signed', Array(preg_match('/\[NOTICE\] User "bob" created \(member\) in the users file by user "cli:[\w.\-]+"\n/', $logText), preg_match('/\[NOTICE\] 2FA secret of user "bob" removed in the users file by user/', $logText), preg_match('/\[NOTICE\] User "bob" removed in the users file by user/', $logText), strpos($logText, 'another-pass')), Array(1, 1, 1, false));
checkIdentical('a secret written readable is told, once a write (three): the one written encrypted is not', preg_match_all('/\[WARNING\] 2FA secret of user "bob" stored readable: no tfakey of 32 characters is set/', $logText), 3);
$fleText = strval(fetchInProject($projDir, 'conf/users.lst'));
writeInProject($projDir, 'conf/users.lst', '{ "users": "none" }');
checkIdentical('a file that is not a list of users is a failure, and is left alone', Array(callShellArray($projDir, Array('manage', 'lists'))['code'], callShellArray($projDir, Array('manage', 'enrol', 'carl'))['code'], fetchInProject($projDir, 'conf/users.lst')), Array(1, 1, '{ "users": "none" }'));
writeInProject($projDir, 'conf/users.lst', $fleText);
checkIdentical('a store the config does not have', Array(callShellArray($projDir, Array('manage', 'lists', '--store=native'))['code'], callShellArray($projDir, Array('manage', 'lists', '--store=other'))['code'], callShellArray($projDir, Array('manage', 'lists', '--block=nothing'))['code']), Array(3, 2, 3));

// Calling an action
checkIdentical('calls runs an action and writes its reply', callShellArray($projDir, Array('manage', 'calls', 'probe/mirrored/hello')), Array('code'=>0, 'text'=>"{\"got\":\"hello\"}\n", 'errs'=>''));
checkIdentical('the call runs as the user of the system, with the role system', Array(preg_match('/^cli:[\w.\-]+$/D', strval(runShellAsJson($projDir, Array('manage', 'calls', 'probe/identify'))['reply']['user'] ?? '')), runShellAsJson($projDir, Array('manage', 'calls', 'probe/identify'))['reply']['role'] ?? null, runShellAsJson($projDir, Array('manage', 'calls', 'probe/roleList'))['reply'] ?? null), Array(1, 'system', Array('member'=>true, 'system'=>true, 'manage'=>false)));
checkIdentical('--urole gives it another role; a role the action asks for applies', Array(runShellAsJson($projDir, Array('manage', 'calls', 'probe/roleList/manage', '--urole=manage'))['reply']['manage'] ?? null, callShellArray($projDir, Array('manage', 'calls', 'probe/roleList/manage'))['code'], callShellArray($projDir, Array('manage', 'calls', 'probe/roleList', '--urole=boss'))['code']), Array(true, 3, 2));
$runVals = callShellArray($projDir, Array('manage', 'calls', 'probe/identify', '--prset=secure'));
checkIdentical('the role a preset asks for applies', Array($runVals['code'], $runVals['errs'], runShellAsJson($projDir, Array('manage', 'calls', 'probe/identify', '--prset=secure', '--urole=manage'))['reply']['logged'] ?? null), Array(3, "403 Forbidden\n", true));
$inpVals = runShellAsJson($projDir, Array('manage', 'calls', 'forms/transmit', 'name=Ann', 'filters[search]=jazz', 'other=dropped'))['reply'] ?? Array();
checkIdentical('the inputs go through the definition of the action', Array($inpVals['NAME'] ?? null, $inpVals['FILTERS'] ?? null, array_key_exists('OTHER', $inpVals)), Array('Ann', Array('search'=>'jazz'), false));
$inpVals = runShellAsJson($projDir, Array('manage', 'calls', 'forms/freeForm', 'b=2', '--input=-'), '{"a": 1, "b": 1}')['reply'] ?? Array();
checkIdentical('inputs read as JSON, the words written over them', Array($inpVals['A'] ?? null, $inpVals['B'] ?? null), Array(1, '2'));
checkIdentical('what is not an input, or not an action', Array(callShellArray($projDir, Array('manage', 'calls', 'forms/freeForm', 'novalue'))['code'], callShellArray($projDir, Array('manage', 'calls', 'forms'))['code'], callShellArray($projDir, Array('manage', 'calls', 'forms/freeForm', '--mthod=FETCH'))['code'], callShellArray($projDir, Array('manage', 'calls', 'forms/freeForm', '--input='.$projDir.DS.'nofile.json'))['code']), Array(2, 2, 2, 2));
$runVals = callShellArray($projDir, Array('manage', 'calls', 'probe/stopping/fail'));
checkIdentical('a refusal of the action is told, with its exit code', $runVals, Array('code'=>3, 'text'=>"{\"error\":\"Stopped early\",\"code\":404}\n", 'errs'=>"404 Stopped early\n"));
checkIdentical('an unknown application or action: the same refusal', Array(callShellArray($projDir, Array('manage', 'calls', 'nothing/here'))['code'], callShellArray($projDir, Array('manage', 'calls', 'probe/nothing'))['code']), Array(3, 3));
checkIdentical('a reply without content, a redirection', Array(callShellArray($projDir, Array('manage', 'calls', 'probe/stopping/none')), callShellArray($projDir, Array('manage', 'calls', 'probe/redirect/out'))), Array(Array('code'=>0, 'text'=>'', 'errs'=>''), Array('code'=>0, 'text'=>"moved: https://example.org/\n", 'errs'=>'')));
$savePth = inTemporaryDir('reply.bin');
checkIdentical('a file reply is written where --saved tells', Array(callShellArray($projDir, Array('manage', 'calls', 'probe/sendFile', '--saved='.$savePth))['code'], is_file($savePth) ? file_get_contents($savePth) : null), Array(0, file_get_contents(TST_DIR.DS.'fixtures'.DS.'letters.txt')));
checkIdentical('a method the request is said to use', runShellAsJson($projDir, Array('manage', 'calls', 'probe/userPass', 'user=a', 'pass=b', '--mthod=POST'))['code'] ?? null, 401);
checkPregMatch('a call is traced on the access channel', strval(fetchInProject($projDir, 'temp/logs/access.log')), '/\[NOTICE\] Action probe\/mirrored called from the command line by user "cli:[\w.\-]+" \(system\)\n/');

finishChecking();
