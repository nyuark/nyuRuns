<?php

// Command line, deploy store : the users moved from the native userbase to the
// users file and back, with their passwords, roles, statuses, tokens and 2FA
// secrets, on SQLite databases (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';
require __DIR__.'/../lib/shellKit.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Shell store', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Access\Totp;

$natSeal = 'native-key-of-the-2FA-secrets-0123456789';
$lstSeal = 'file-key-of-the-2FA-secrets-9876543210ab';
$twoFaKy = 'JBSWY3DPEHPK3PXP';
$canSeal = function_exists('openssl_encrypt');

// From the native userbase to the users file
$basePth = inTempDir('store.sqlite');
buildData($basePth);
$sqlLink = new PDO('sqlite:'.$basePth);
$sqlLink->exec("UPDATE users SET actvCode='code-of-cleo' WHERE userName='cleo'");
$sqlLink->exec("UPDATE users SET userMail='ben@example.ch', resetCod='reset-of-ben', codeDate='2026-11-04 10:00:00' WHERE userName='ben'");
$sqlLink->exec("INSERT INTO users (id_user, userName, passHash, userRole, userStat, userMail, signDate) VALUES (5, 'erin', '".password_hash('pw-erin', PASSWORD_BCRYPT, Array('cost'=>4))."', 'member', 'review', 'erin@example.ch', '2026-11-04 09:00:00')");
$sqlLink->prepare("UPDATE users SET twoFaKey=?, tokenKey=? WHERE userName='anna'")->execute( Array(Totp::sealSecrt($twoFaKy, $natSeal), hash('sha256', 'token-of-anna')) );
$sqlLink->exec("INSERT INTO devices (devcName, devcRole, tokenKey, user_id) VALUES ('Phone', 'app', '".hash('sha256', 'phone')."', 2)");
$hashLst = $sqlLink->query('SELECT userName, passHash FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
$projDir = makeProjt('storenat', Array(
	'conf/system.ini'=>sampleIni(),
	'conf/database.json'=>json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>$basePth, 'user'=>'', 'pass'=>'')),
	'conf/access.json'=>json_encode(Array(
		'login'=>Array('authby'=>'native', 'authcf'=>'native', 'sessby'=>'jtoken', 'sesscf'=>'dftokn'),
		'native'=>Array('tblnme'=>'users', 'tfakey'=>$natSeal),
		'usrlst'=>Array('usrfle'=>'conf/users.lst', 'tfakey'=>$lstSeal),
		'device'=>Array('tblnme'=>'devices', 'usrtbl'=>'users')
	), JSON_PRETTY_PRINT)
));
checkSame('a store that is not one, a missing word', Array(runShell($projDir, Array('deploy', 'store', 'ldap'))['code'], runShell($projDir, Array('deploy', 'store'))['code']), Array(2, 2));
checkSame('the store the login already uses', runShell($projDir, Array('deploy', 'store', 'native'))['code'], 3);
$runVals = runShell($projDir, Array('deploy', 'store', 'usrlst', '--agree'));
checkSame('active devices stop the move', Array($runVals['code'], strpos($runVals['errs'], 'active devices need the native userbase (ben/Phone)')!==false, is_file($projDir.DS.'conf'.DS.'users.lst')), Array(3, true, false));
checkSame('without --agree, --noask moves nothing', Array(runShell($projDir, Array('deploy', 'store', 'usrlst', '--drops', '--noask'))['code'], is_file($projDir.DS.'conf'.DS.'users.lst')), Array(3, false));
$jsnVals = jsonShell($projDir, Array('deploy', 'store', 'usrlst', '--drops', '--agree'));
$axsVals = json_decode(strval(readInPrj($projDir, 'conf/access.json')), true);
$usrList = array_column(json_decode(strval(readInPrj($projDir, 'conf/users.lst')), true)['users'] ?? Array(), null, 'userName');
checkSame('the users go to the file with their identifier, role and status; the login follows', Array($jsnVals['exit'] ?? null, $jsnVals['moved'] ?? null, array_map(function( Array $userValues ): String { return $userValues['id_user'].'/'.$userValues['userRole'].'/'.$userValues['userStat']; }, $usrList), $axsVals['login']['authby'] ?? null, $axsVals['login']['authcf'] ?? null),
	Array(0, 5, Array('anna'=>'1/manage/active', 'ben'=>'2/member/active', 'cleo'=>'3/member/pendng', 'dave'=>'4/system/locked', 'erin'=>'5/member/review'), 'usrlst', 'usrlst'));
checkSame('the addresses go along', array_column($usrList, 'userMail', 'userName'), Array('ben'=>'ben@example.ch', 'erin'=>'erin@example.ch'));
checkSame('the passwords and the personal token go along unchanged', Array(array_column($usrList, 'passHash', 'userName'), $usrList['anna']['tokenKey'] ?? null), Array($hashLst, hash('sha256', 'token-of-anna')));
checkSame('what is not kept is told', array_column($jsnVals['rows'] ?? Array(), 'details', 'not kept'), Array(
	'activation codes'=>'cleo: pending until made active, or given a password, with manage',
	'reset codes'=>'ben: the resets asked are forgotten, the passwords kept',
	'sign-ups to review'=>'erin: kept in review, which the users file cannot approve; manage unlck makes them active',
	'presence and 2FA steps'=>'the users file is only read: last seen, failed attempts and the step of the last 2FA code are not kept',
	'devices'=>'ben/Phone: revoked'
));
if( $canSeal ){
	checkSame('the 2FA secret is encrypted again with the key of the file', Array(Totp::openSecrt(strval($usrList['anna']['twoFaKey'] ?? ''), $lstSeal), Totp::openSecrt(strval($usrList['anna']['twoFaKey'] ?? ''), $natSeal)), Array($twoFaKy, null));
}
checkSame('the userbase left is kept, the device revoked', Array(intval($sqlLink->query('SELECT COUNT(*) FROM users')->fetchColumn()), $sqlLink->query('SELECT revkDate FROM devices')->fetchColumn()!==null), Array(5, true));
checkLike('the move is traced', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Deploy: 5 users moved from native to usrlst, 1 devices revoked, the login points at usrlst by user "cli:/');
checkSame('back to a userbase that holds users: refused', Array(runShell($projDir, Array('deploy', 'store', 'native', '--agree', '--noask'))['code'], json_decode(strval(readInPrj($projDir, 'conf/access.json')), true)['login']['authby'] ?? null), Array(3, 'usrlst'));
$sqlLink = null;

// From the users file to a new native userbase, its table created
$newBase = inTempDir('storenew.sqlite');
if( file_exists($newBase) ){
	unlink($newBase);
}
$fleUsrs = strval(file_get_contents(TST_DIR.DS.'fixtures'.DS.'users.lst'));
$projDir = makeProjt('storelst', Array(
	'conf/system.ini'=>sampleIni(),
	'conf/database.json'=>json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>$newBase, 'user'=>'', 'pass'=>'')),
	'conf/schema/users.sqlte.sql'=>strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql')),
	'conf/access.json'=>json_encode(Array(
		'login'=>Array('authby'=>'usrlst', 'authcf'=>'usrlst', 'sessby'=>'jtoken', 'sesscf'=>'dftokn'),
		'usrlst'=>Array('usrfle'=>'conf/users.lst')
	), JSON_PRETTY_PRINT),
	'conf/users.lst'=>$fleUsrs
));
$jsnVals = jsonShell($projDir, Array('deploy', 'store', 'native', '--agree', '--noask'));
$newLink = new PDO('sqlite:'.$newBase);
$rowList = $newLink->query('SELECT id_user, userName, passHash, userRole, userStat, tokenKey, twoFaKey FROM users ORDER BY id_user')->fetchAll(PDO::FETCH_ASSOC);
$axsVals = json_decode(strval(readInPrj($projDir, 'conf/access.json')), true);
checkSame('the table is created and the users written; a user without an identifier gets the next one, the role and status the file leaves out', Array($jsnVals['exit'] ?? null, $jsnVals['moved'] ?? null, array_map(function( Array $userValues ): String { return $userValues['id_user'].'/'.$userValues['userName'].'/'.$userValues['userRole'].'/'.$userValues['userStat']; }, $rowList)),
	Array(0, 5, Array('1/anna/manage/active', '2/ben/member/active', '3/cleo/member/pendng', '4/dave/system/locked', '5/emma/member/active')));
$lstVals = array_column(json_decode($fleUsrs, true)['users'], null, 'userName');
checkSame('the hashes, the token and the 2FA secret go along', Array(array_column($rowList, 'passHash', 'userName'), $rowList[0]['tokenKey'], $rowList[4]['twoFaKey']), Array(array_column($lstVals, 'passHash', 'userName'), $lstVals['anna']['tokenKey'], $lstVals['emma']['twoFaKey']));
checkSame('the login follows, the native block is made, the file is kept', Array($axsVals['login']['authby'] ?? null, $axsVals['native']['tblnme'] ?? null, readInPrj($projDir, 'conf/users.lst')), Array('native', 'users', str_replace("\r\n", "\n", $fleUsrs)));
$newLink->exec("INSERT INTO users (userName, passHash) VALUES ('fred', '!')");
checkSame('a user created next takes the identifier after them', intval($newLink->lastInsertId()), 6);
$newLink = null;

// A user without a password hash
$projDir = makeProjt('storenop', Array(
	'conf/system.ini'=>sampleIni(),
	'conf/database.json'=>json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>inTempDir('storenop.sqlite'), 'user'=>'', 'pass'=>'')),
	'conf/schema/users.sqlte.sql'=>strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql')),
	'conf/access.json'=>json_encode(Array('login'=>Array('authby'=>'usrlst', 'authcf'=>'usrlst'), 'usrlst'=>Array('usrfle'=>'conf/users.lst'))),
	'conf/users.lst'=>'{ "users": [ { "userName": "zoe", "userRole": "member" } ] }'
));
$runVals = runShell($projDir, Array('deploy', 'store', 'native', '--agree', '--noask'));
checkSame('a user without a password hash stops the move', Array($runVals['code'], $runVals['errs']), Array(3, "Nothing was moved: users without a password hash (zoe); give them a password with manage first\n"));

endChecks();
