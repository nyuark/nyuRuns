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
	$fleVals = json_decode(strval(readInPrj($projctRoot, 'conf/users.lst')), true);
	return array_column($fleVals['users'] ?? Array(), null, 'userName');
}

$rndrTxt = '"input": [ { "naming": "QUERY", "source": "QUERY", "fields": "all" } ], "route": { "detect": "autopath", "mapper": "$app/$action/$param" }, "rnder": { "format": "json", "headrs": {} }';
$projDir = makeProjt('manage', Array(
	'conf/system.ini'=>sampleIni(),
	'conf/access.json'=>"{\n\t\"login\":  { \"authby\": \"usrlst\", \"authcf\": \"usrlst\", \"sessby\": \"jtoken\", \"sesscf\": \"dftokn\" },\n\t\"usrlst\": { \"usrfle\": \"conf/users.lst\", \"minlen\": 8 },\n\t\"dftokn\": { \"secret\": \"test-only-secret-0123456789-abcdefghij\", \"lifetm\": 300, \"algrtm\": \"HS256\" }\n}\n",
	'conf/presets/dfault.rps'=>'{ "label": "dfault", "setup": { "ftypes": "all" }, "auths": { "needed": false }, '.$rndrTxt.' }',
	'conf/presets/secure.rps'=>'{ "label": "secure", "setup": { "ftypes": "all" }, "auths": { "sessby": "jtoken", "sesscf": "dftokn", "authby": "usrlst", "authcf": "usrlst", "rolert": { "probe/whoami": "manage" } }, '.$rndrTxt.' }',
	'test/fixtures/letters.txt'=>strval(file_get_contents(TST_DIR.DS.'fixtures'.DS.'letters.txt'))
));

// The users file : creation
checkSame('no file yet is no user', runShell($projDir, Array('manage', 'lists')), Array('code'=>0, 'text'=>"userName\nNo user.\n", 'errs'=>''));
$jsnVals = jsonShell($projDir, Array('manage', 'enrol', 'ann', 'manage'));
$usrList = fileUsers($projDir);
checkSame('enrol creates an active user, its password made and shown once', Array($jsnVals['created'] ?? null, strlen($jsnVals['secret']['Password of ann'] ?? ''), password_verify($jsnVals['secret']['Password of ann'] ?? '', $usrList['ann']['passHash'] ?? ''), array_diff_key($usrList['ann'] ?? Array(), Array('passHash'=>1))), Array('ann', 20, true, Array('id_user'=>1, 'userName'=>'ann', 'userRole'=>'manage', 'userStat'=>'active')));
checkLike('the file is written one user per line', strval(readInPrj($projDir, 'conf/users.lst')), '/^\{\n\t"users": \[\n\t\t\{ "id_user": 1, "userName": "ann", "passHash": "[^"]+", "userRole": "manage", "userStat": "active" \}\n\t\]\n\}\n$/D');
checkSame('a name taken whatever its case, a name no user is given, a role that is not one', Array(runShell($projDir, Array('manage', 'enrol', 'Ann'))['errs'], runShell($projDir, Array('manage', 'enrol', 'root'))['code'], runShell($projDir, Array('manage', 'enrol', 'bob', 'boss'))['errs']), Array("User name already taken\n", 3, "Invalid role\n"));
checkSame('a typed password follows the rules', Array(runShell($projDir, Array('manage', 'enrol', 'bob', '--typed'), "short\n")['errs'], count(fileUsers($projDir))), Array("Password of bob: Password too short: 8 characters at least\n", 1));
$runVals = runShell($projDir, Array('manage', 'enrol', 'bob', '--typed'), "pw-bob-long\n");
$usrList = fileUsers($projDir);
checkSame('a typed password is not shown; the user is a member when no role is told', Array($runVals['text'], password_verify('pw-bob-long', $usrList['bob']['passHash'] ?? ''), $usrList['bob']['userRole'] ?? null, $usrList['bob']['id_user'] ?? null), Array("created: bob\n", true, 'member', 2));

// Reading
checkSame('lists the users by name, never a hash', jsonShell($projDir, Array('manage', 'lists'))['rows'] ?? null, Array(
	Array('id_user'=>1, 'userName'=>'ann', 'userRole'=>'manage', 'userStat'=>'active', 'hasToken'=>false, 'hasTwoFa'=>false),
	Array('id_user'=>2, 'userName'=>'bob', 'userRole'=>'member', 'userStat'=>'active', 'hasToken'=>false, 'hasTwoFa'=>false)
));
checkSame('lists by role, by count; a role that is not one', Array(array_column(jsonShell($projDir, Array('manage', 'lists', '--urole=manage'))['rows'] ?? Array(), 'userName'), array_column(jsonShell($projDir, Array('manage', 'lists', '--count=1', '--start=1'))['rows'] ?? Array(), 'userName'), runShell($projDir, Array('manage', 'lists', '--urole=boss'))['code']), Array(Array('ann'), Array('bob'), 3));
checkSame('views a user', runShell($projDir, Array('manage', 'views', 'bob'))['text'], "id_user: 2\nuserName: bob\nuserRole: member\nuserStat: active\nhasToken: false\nhasTwoFa: false\n");
checkSame('an unknown user', runShell($projDir, Array('manage', 'views', 'nobody')), Array('code'=>3, 'text'=>'', 'errs'=>"User not found\n"));

// Status, role, removal
checkSame('the last active manager is kept as it is', Array(runShell($projDir, Array('manage', 'locks', 'ann'))['errs'], runShell($projDir, Array('manage', 'roles', 'ann', 'member'))['code'], runShell($projDir, Array('manage', 'remov', 'ann', '--agree'))['code'], count(fileUsers($projDir))), Array("The last active manager cannot be locked\n", 3, 3, 2));
checkSame('locks, then unlocks', Array(runShell($projDir, Array('manage', 'locks', 'bob'))['text'], fileUsers($projDir)['bob']['userStat'], runShell($projDir, Array('manage', 'locks', 'bob'))['errs'], runShell($projDir, Array('manage', 'unlck', 'bob'))['text'], fileUsers($projDir)['bob']['userStat']), Array("locked: bob\n", 'locked', "User already locked\n", "unlocked: bob\n", 'active'));
checkSame('roles gives another role', Array(runShell($projDir, Array('manage', 'roles', 'bob', 'system'))['text'], fileUsers($projDir)['bob']['userRole'], runShell($projDir, Array('manage', 'roles', 'bob', 'boss'))['code'], runShell($projDir, Array('manage', 'roles', 'bob'))['code']), Array("bob: system\n", 'system', 3, 2));

// Password, token, 2FA
$jsnVals = jsonShell($projDir, Array('manage', 'passw', 'bob'));
checkSame('passw gives a new password, shown once', Array(password_verify($jsnVals['secret']['Password of bob'] ?? '', fileUsers($projDir)['bob']['passHash']), password_verify('pw-bob-long', fileUsers($projDir)['bob']['passHash'])), Array(true, false));
checkSame('reset gives a new password too, and there is no activation', Array(runShell($projDir, Array('manage', 'reset', 'bob', '--typed'), "another-pass\n")['text'], password_verify('another-pass', fileUsers($projDir)['bob']['passHash']), runShell($projDir, Array('manage', 'actvt', 'bob', 'code'))['code']), Array("password set: bob\n", true, 3));
$jsnVals = jsonShell($projDir, Array('manage', 'token', 'bob'));
checkSame('token gives a personal token, its hash alone written', Array(strlen($jsnVals['secret']['Personal token of bob'] ?? ''), fileUsers($projDir)['bob']['tokenKey'] ?? null, jsonShell($projDir, Array('manage', 'views', 'bob'))['hasToken'] ?? null), Array(64, hash('sha256', $jsnVals['secret']['Personal token of bob'] ?? ''), true));
checkSame('a token is only taken away when confirmed', Array(runShell($projDir, Array('manage', 'token', 'bob', '--clear', '--noask'))['code'], isset(fileUsers($projDir)['bob']['tokenKey']), runShell($projDir, Array('manage', 'token', 'bob', '--clear'), "y\n")['text'], isset(fileUsers($projDir)['bob']['tokenKey'])), Array(3, true, "token removed: bob\n", false));
$jsnVals = jsonShell($projDir, Array('manage', 'twofa', 'bob'));
checkSame('twofa gives a 2FA secret, with the address an authenticator reads', Array(preg_match('/^[A-Z2-7]{32}$/D', $jsnVals['secret']['2FA secret of bob'] ?? ''), fileUsers($projDir)['bob']['twoFaKey'] ?? null, $jsnVals['secret']['Address for the authenticator'] ?? null), Array(1, $jsnVals['secret']['2FA secret of bob'] ?? '', 'otpauth://totp/nyuRuns:bob?secret='.($jsnVals['secret']['2FA secret of bob'] ?? '').'&issuer=nyuRuns'));
checkSame('a lost authenticator: the secret is taken away', Array(runShell($projDir, Array('manage', 'twofa', 'bob', '--clear', '--agree'))['text'], isset(fileUsers($projDir)['bob']['twoFaKey'])), Array("2FA removed: bob\n", false));
// With a key in the block, the secret is written encrypted, and the one shown is still the authenticator's
if( function_exists('openssl_encrypt') ){
	$axsText = strval(readInPrj($projDir, 'conf/access.json'));
	putInProj($projDir, 'conf/access.json', str_replace('"minlen": 8 }', '"minlen": 8, "tfakey": "'.str_repeat('k', 40).'" }', $axsText));
	$jsnVals = jsonShell($projDir, Array('manage', 'twofa', 'bob'));
	$sealTxt = strval(fileUsers($projDir)['bob']['twoFaKey'] ?? '');
	checkSame('twofa writes the secret encrypted when the block gives a key', Array(preg_match('/^enc1:[A-Za-z0-9+\/=]{80}$/D', $sealTxt), nyu\Access\Totp::openSecrt($sealTxt, str_repeat('k', 40)), jsonShell($projDir, Array('manage', 'views', 'bob'))['hasTwoFa'] ?? null), Array(1, $jsnVals['secret']['2FA secret of bob'] ?? '', true));
	runShell($projDir, Array('manage', 'twofa', 'bob', '--clear', '--agree'));
	putInProj($projDir, 'conf/access.json', $axsText);
}else{
	skipCheck('twofa: the secret written encrypted', 'the openssl extension is not loaded');
}
runShell($projDir, Array('manage', 'locks', 'bob'));
checkSame('a locked user is given neither a token nor a secret', Array(runShell($projDir, Array('manage', 'token', 'bob'))['errs'], runShell($projDir, Array('manage', 'twofa', 'bob'))['code']), Array("User not active\n", 3));

// What a person wrote in the file is kept; a file that cannot be used is not written
$fleText = strval(readInPrj($projDir, 'conf/users.lst'));
putInProj($projDir, 'conf/users.lst', str_replace('"userName": "bob"', '"userName": "bob", "note": "kept"', $fleText));
checkSame('a field added by hand is kept, after the others', Array(runShell($projDir, Array('manage', 'unlck', 'bob'))['code'], fileUsers($projDir)['bob']['note'] ?? null, array_slice(array_keys(fileUsers($projDir)['bob']), -1)), Array(0, 'kept', Array('note')));
checkSame('removal needs a confirmation', Array(runShell($projDir, Array('manage', 'remov', 'bob', '--noask'))['code'], runShell($projDir, Array('manage', 'remov', 'bob'), "no\n")['code'], runShell($projDir, Array('manage', 'remov', 'bob'), "yes\n")['text'], array_keys(fileUsers($projDir))), Array(3, 3, "removed: bob\n", Array('ann')));
checkSame('the former files are in temp/backup', count(glob($projDir.DS.'temp'.DS.'backup'.DS.'users.lst.*'))>5, true);
$logText = strval(readInPrj($projDir, 'temp/logs/access.log'));
checkSame('the changes are traced on the access channel, signed', Array(preg_match('/\[NOTICE\] User "bob" created \(member\) in the users file by user "cli:[\w.\-]+"\n/', $logText), preg_match('/\[NOTICE\] 2FA secret of user "bob" removed in the users file by user/', $logText), preg_match('/\[NOTICE\] User "bob" removed in the users file by user/', $logText), strpos($logText, 'another-pass')), Array(1, 1, 1, false));
checkSame('a secret written readable is told, once: the one written encrypted is not', preg_match_all('/\[WARNING\] 2FA secret of user "bob" stored readable: no tfakey of 32 characters is set/', $logText), 1);
$fleText = strval(readInPrj($projDir, 'conf/users.lst'));
putInProj($projDir, 'conf/users.lst', '{ "users": "none" }');
checkSame('a file that is not a list of users is a failure, and is left alone', Array(runShell($projDir, Array('manage', 'lists'))['code'], runShell($projDir, Array('manage', 'enrol', 'carl'))['code'], readInPrj($projDir, 'conf/users.lst')), Array(1, 1, '{ "users": "none" }'));
putInProj($projDir, 'conf/users.lst', $fleText);
checkSame('a store the config does not have', Array(runShell($projDir, Array('manage', 'lists', '--store=native'))['code'], runShell($projDir, Array('manage', 'lists', '--store=other'))['code'], runShell($projDir, Array('manage', 'lists', '--block=nothing'))['code']), Array(3, 2, 3));

// Calling an action
checkSame('calls runs an action and writes its reply', runShell($projDir, Array('manage', 'calls', 'probe/mirror/hello')), Array('code'=>0, 'text'=>"{\"got\":\"hello\"}\n", 'errs'=>''));
checkSame('the call runs as the user of the system, with the role system', Array(preg_match('/^cli:[\w.\-]+$/D', strval(jsonShell($projDir, Array('manage', 'calls', 'probe/whoami'))['reply']['user'] ?? '')), jsonShell($projDir, Array('manage', 'calls', 'probe/whoami'))['reply']['role'] ?? null, jsonShell($projDir, Array('manage', 'calls', 'probe/roles'))['reply'] ?? null), Array(1, 'system', Array('member'=>true, 'system'=>true, 'manage'=>false)));
checkSame('--urole gives it another role; a role the action asks for applies', Array(jsonShell($projDir, Array('manage', 'calls', 'probe/roles/manage', '--urole=manage'))['reply']['manage'] ?? null, runShell($projDir, Array('manage', 'calls', 'probe/roles/manage'))['code'], runShell($projDir, Array('manage', 'calls', 'probe/roles', '--urole=boss'))['code']), Array(true, 3, 2));
$runVals = runShell($projDir, Array('manage', 'calls', 'probe/whoami', '--prset=secure'));
checkSame('the role a preset asks for applies', Array($runVals['code'], $runVals['errs'], jsonShell($projDir, Array('manage', 'calls', 'probe/whoami', '--prset=secure', '--urole=manage'))['reply']['logged'] ?? null), Array(3, "403 Forbidden\n", true));
$inpVals = jsonShell($projDir, Array('manage', 'calls', 'forms/send', 'name=Ann', 'filters[search]=jazz', 'other=dropped'))['reply'] ?? Array();
checkSame('the inputs go through the definition of the action', Array($inpVals['NAME'] ?? null, $inpVals['FILTERS'] ?? null, array_key_exists('OTHER', $inpVals)), Array('Ann', Array('search'=>'jazz'), false));
$inpVals = jsonShell($projDir, Array('manage', 'calls', 'forms/free', 'b=2', '--input=-'), '{"a": 1, "b": 1}')['reply'] ?? Array();
checkSame('inputs read as JSON, the words written over them', Array($inpVals['A'] ?? null, $inpVals['B'] ?? null), Array(1, '2'));
checkSame('what is not an input, or not an action', Array(runShell($projDir, Array('manage', 'calls', 'forms/free', 'novalue'))['code'], runShell($projDir, Array('manage', 'calls', 'forms'))['code'], runShell($projDir, Array('manage', 'calls', 'forms/free', '--mthod=FETCH'))['code'], runShell($projDir, Array('manage', 'calls', 'forms/free', '--input='.$projDir.DS.'nofile.json'))['code']), Array(2, 2, 2, 2));
$runVals = runShell($projDir, Array('manage', 'calls', 'probe/halt/fail'));
checkSame('a refusal of the action is told, with its exit code', $runVals, Array('code'=>3, 'text'=>"{\"error\":\"Stopped early\",\"code\":404}\n", 'errs'=>"404 Stopped early\n"));
checkSame('an unknown application or action', Array(runShell($projDir, Array('manage', 'calls', 'nothing/here'))['code'], runShell($projDir, Array('manage', 'calls', 'probe/nothing'))['code']), Array(3, 1));
checkSame('a reply without content, a redirection', Array(runShell($projDir, Array('manage', 'calls', 'probe/halt/none')), runShell($projDir, Array('manage', 'calls', 'probe/moved/out'))), Array(Array('code'=>0, 'text'=>'', 'errs'=>''), Array('code'=>0, 'text'=>"moved: https://example.org/\n", 'errs'=>'')));
$savePth = inTempDir('reply.bin');
checkSame('a file reply is written where --saved tells', Array(runShell($projDir, Array('manage', 'calls', 'probe/media', '--saved='.$savePth))['code'], is_file($savePth) ? file_get_contents($savePth) : null), Array(0, file_get_contents(TST_DIR.DS.'fixtures'.DS.'letters.txt')));
checkSame('a method the request is said to use', jsonShell($projDir, Array('manage', 'calls', 'probe/creds', 'user=a', 'pass=b', '--mthod=POST'))['code'] ?? null, 401);
checkLike('a call is traced on the access channel', strval(readInPrj($projDir, 'temp/logs/access.log')), '/\[NOTICE\] Action probe\/mirror called from the command line by user "cli:[\w.\-]+" \(system\)\n/');

endChecks();
