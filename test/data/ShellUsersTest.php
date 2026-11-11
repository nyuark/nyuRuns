<?php

// Command line, manage : the users of the native userbase, on the sample
// SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';
require __DIR__.'/../lib/shellKit.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Shell users', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Access\Auth\Native;
use nyu\Access\Totp;

$basePth = inTemporaryDir('shell.sqlite');
createSampleDb($basePth);
switchToSqlite($basePth);
$projDir = makeProjectTxt('users', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/database.json'=>json_encode(Array('dbEngine'=>'sqlitedb', 'hostName'=>'', 'baseName'=>$basePth, 'userName'=>'', 'passWord'=>'')),
	'conf/access.json'=>"{\n\t\"loginSet\":  { \"authWith\": \"nativeDb\", \"authConf\": \"testBase\", \"carrWith\": \"jwtToken\", \"carrConf\": \"jwtToken\" },\n\t\"testBase\": { \"useTable\": \"users\", \"minChars\": 8 },\n\t\"userFile\": { \"listFile\": \"conf/users.lst\" }\n}\n"
));
$natAuth = new Native( Array('useTable'=>'users') );

// Reading
$jsnVals = runShellAsJson($projDir, Array('account', 'fullList'));
checkIdentical('lists the users of the userbase the login names', Array(array_column($jsnVals['rows'] ?? Array(), 'systemName'), array_keys($jsnVals['rows'][0] ?? Array())), Array(Array('anna', 'ben', 'cleo', 'dave'), Array('id_user', 'systemName', 'accessRole', 'userStatus', 'contactEml', 'userLocale', 'signupDate', 'createDate', 'accessDate')));
checkIdentical('lists by status', array_column(runShellAsJson($projDir, Array('account', 'fullList', '--statusIs=disabled'))['rows'] ?? Array(), 'systemName'), Array('dave'));
checkIdentical('views a user; an unknown one', Array(runShellAsJson($projDir, Array('account', 'viewUser', 'ben'))['accessRole'] ?? null, callShellArray($projDir, Array('account', 'viewUser', 'nobody'))['errs']), Array('standard', "User not found\n"));

// Creation and activation
$jsnVals = runShellAsJson($projDir, Array('account', 'makeUser', 'erin', 'advanced'));
$actCode = strval($jsnVals['secret']['Activation code of erin'] ?? '');
checkIdentical('enrol creates a pending user and shows its activation code once', Array($jsnVals['created'] ?? null, strlen($actCode), runShellAsJson($projDir, Array('account', 'viewUser', 'erin'))['userStatus'] ?? null), Array('erin', 12, 'awaiting'));
checkIdentical('a name already taken', callShellArray($projDir, Array('account', 'makeUser', 'Erin'))['errs'], "User name already taken\n");
checkIdentical('a wrong code does not activate', callShellArray($projDir, Array('account', 'activate', 'erin', 'wrong', '--typeItIn'), "pw-erin-long\n")['code'], 3);
checkIdentical('actvt activates with the code and a typed password', Array(callShellArray($projDir, Array('account', 'activate', 'erin', $actCode, '--typeItIn'), "pw-erin-long\n")['text'], $natAuth->checkSecretArr(Array('user'=>'erin', 'pass'=>'pw-erin-long'))), Array("activated: erin\n", Array('userName'=>'erin', 'userRole'=>'advanced', 'idOfUser'=>5)));

// Status, role
checkIdentical('locks and unlocks', Array(callShellArray($projDir, Array('account', 'lockUser', 'erin'))['text'], $natAuth->checkSecretArr(Array('user'=>'erin', 'pass'=>'pw-erin-long')), callShellArray($projDir, Array('account', 'freeUser', 'erin'))['text'], $natAuth->checkSecretArr(Array('user'=>'erin', 'pass'=>'pw-erin-long'))!==false), Array("locked: erin\n", false, "unlocked: erin\n", true));
checkIdentical('the last active manager is kept as it is', Array(callShellArray($projDir, Array('account', 'lockUser', 'anna'))['errs'], callShellArray($projDir, Array('account', 'dropUser', 'anna', '--yesToAll'))['code']), Array("The last active manager cannot be locked\n", 3));
checkIdentical('roles gives another role', Array(callShellArray($projDir, Array('account', 'giveRole', 'erin', 'managers'))['text'], $natAuth->usrIdentityArr('erin')['userRole'] ?? null), Array("erin: managers\n", 'managers'));

// Password, token, 2FA
$jsnVals = runShellAsJson($projDir, Array('account', 'password', 'ben'));
checkIdentical('passw gives a new password, shown once', Array($natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>strval($jsnVals['secret']['Password of ben'] ?? '')))['userName'] ?? null, $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben'))), Array('ben', false));
checkIdentical('a typed password follows the rules; an unknown user', Array(callShellArray($projDir, Array('account', 'password', 'ben', '--typeItIn'), "short\n")['errs'], callShellArray($projDir, Array('account', 'password', 'nobody'))['code']), Array("Password of ben: Password too short: 8 characters at least\n", 3));
$jsnVals = runShellAsJson($projDir, Array('account', 'apiToken', 'ben'));
$usrTokn = strval($jsnVals['secret']['Personal token of ben'] ?? '');
checkIdentical('token gives a personal token that is accepted', Array(strlen($usrTokn), $natAuth->checkSecretArr(Array('key'=>$usrTokn))['userName'] ?? null), Array(64, 'ben'));
checkIdentical('token --removeIt takes it away', Array(callShellArray($projDir, Array('account', 'apiToken', 'ben', '--removeIt', '--yesToAll'))['text'], $natAuth->checkSecretArr(Array('key'=>$usrTokn))), Array("token removed: ben\n", false));
$jsnVals = runShellAsJson($projDir, Array('account', 'setTwoFa', 'erin'));
$usrScrt = strval($jsnVals['secret']['2FA secret of erin'] ?? '');
checkIdentical('twofa gives a secret: the password alone is no longer enough', Array(strlen($usrScrt), $natAuth->checkSecretArr(Array('user'=>'erin', 'pass'=>'pw-erin-long')), $natAuth->finalErrorText(), $natAuth->checkSecretArr(Array('user'=>'erin', 'pass'=>'pw-erin-long', 'code'=>Totp::codeAtTimeText($usrScrt, time())))['userName'] ?? null), Array(32, false, 'Verification code required', 'erin'));
checkIdentical('a lost authenticator: twofa --removeIt lets the password log in again', Array(callShellArray($projDir, Array('account', 'setTwoFa', 'erin', '--removeIt', '--noPrompt'))['code'], callShellArray($projDir, Array('account', 'setTwoFa', 'erin', '--removeIt'), "y\n")['text'], $natAuth->checkSecretArr(Array('user'=>'erin', 'pass'=>'pw-erin-long'))['userName'] ?? null), Array(3, "2FA removed: erin\n", 'erin'));
checkIdentical('a locked or unknown user is given neither', Array(callShellArray($projDir, Array('account', 'apiToken', 'dave'))['code'], callShellArray($projDir, Array('account', 'setTwoFa', 'nobody'))['code'], callShellArray($projDir, Array('account', 'setTwoFa', 'nobody', '--removeIt', '--yesToAll'))['errs']), Array(3, 3, "User not found\n"));
// Codes by mail : a mail account, and an address for the user
callShellArray($projDir, Array('account', 'setEmail', 'erin', 'erin@example.ch'));
checkIdentical('twofa --emailOtp without a mailer is refused', callShellArray($projDir, Array('account', 'setTwoFa', 'erin', '--emailOtp'))['errs'], "No mail account sends the codes: mailer is not set\n");
writeInProject($projDir, 'conf/mailer.json', '{ "devbox": { "sendWith": "emlFiles", "fromAddr": "no-reply@example.com", "mailsDir": "temp/mails" } }');
callShellArray($projDir, Array('setting', 'setValue', 'accsConf', 'testBase.mailAcnt', 'devbox', '--overrule'));
checkIdentical('twofa --emailOtp: the user gives a mailed code at each login', Array(callShellArray($projDir, Array('account', 'setTwoFa', 'erin', '--emailOtp'))['text'], (new PDO('sqlite:'.$basePth))->query("SELECT twoFactWay FROM users WHERE systemName='erin'")->fetchColumn()), Array("2FA set: erin (codes by mail)\n", 'emailOtp'));
checkPregMatch('twofa --emailOtp is traced', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Mailed codes set for user "erin" by user "cli:/');
checkIdentical('a move to the users file is refused while a user has mailed codes', Array(callShellArray($projDir, Array('install', 'moveUser', 'userFile', '--yesToAll'))['errs'], is_file($projDir.DS.'conf'.DS.'users.lst')), Array("Nothing was moved: codes by mail need the native userbase (erin); \"account setTwoFa <user> --removeIt\" takes them away\n", false));
checkIdentical('twofa --removeIt takes them away', Array(callShellArray($projDir, Array('account', 'setTwoFa', 'erin', '--removeIt', '--yesToAll'))['text'], (new PDO('sqlite:'.$basePth))->query("SELECT twoFactWay FROM users WHERE systemName='erin'")->fetchColumn()), Array("2FA removed: erin\n", null));

// Reset, removal
$jsnVals = runShellAsJson($projDir, Array('account', 'resetPwd', 'ben'));
checkIdentical('reset puts the user back to pending, with a new code', Array(strlen($jsnVals['secret']['Activation code of ben'] ?? ''), runShellAsJson($projDir, Array('account', 'viewUser', 'ben'))['userStatus'] ?? null), Array(12, 'awaiting'));
$sqlLink = new PDO('sqlite:'.$basePth);
checkIdentical('a code shown here lasts 300 minutes', abs(strtotime(strval($sqlLink->query("SELECT codeExpiry FROM users WHERE systemName='ben'")->fetchColumn())) - (time()+300*60))<=5, true);
callShellArray($projDir, Array('account', 'resetPwd', 'ben', '--noExpiry'));
callShellArray($projDir, Array('account', 'makeUser', 'gail', '--noExpiry'));
checkIdentical('--noExpiry: no end, at a reset or a creation', $sqlLink->query("SELECT codeExpiry FROM users WHERE systemName IN ('ben', 'gail')")->fetchAll(PDO::FETCH_COLUMN), Array(null, null));

// Addresses, sign-ups awaiting review
checkIdentical('email gives an address, in lower case', Array(callShellArray($projDir, Array('account', 'setEmail', 'erin', 'Erin@Example.ch'))['text'], runShellAsJson($projDir, Array('account', 'viewUser', 'erin'))['contactEml'] ?? null), Array("address set: erin\n", 'erin@example.ch'));
checkIdentical('an address taken, one that is not', Array(callShellArray($projDir, Array('account', 'setEmail', 'anna', 'erin@example.ch'))['errs'], callShellArray($projDir, Array('account', 'setEmail', 'anna', 'anna'))['errs']), Array("Mail address already used\n", "Invalid mail address\n"));
checkIdentical('email without an address takes it away', Array(callShellArray($projDir, Array('account', 'setEmail', 'erin'))['text'], array_key_exists('contactEml', $viewArr = runShellAsJson($projDir, Array('account', 'viewUser', 'erin'))) ? $viewArr['contactEml'] : 'missing'), Array("address removed: erin\n", null));
checkIdentical('langs gives a language, shows it, takes it away', Array(callShellArray($projDir, Array('account', 'language', 'erin', 'FR_ch'))['text'], callShellArray($projDir, Array('account', 'language', 'erin'))['text'], callShellArray($projDir, Array('account', 'language', 'erin', ''))['text'], callShellArray($projDir, Array('account', 'language', 'erin'))['text']), Array("language set: erin\n", "erin: fr-ch\n", "language removed: erin\n", "erin: (default)\n"));
checkIdentical('langs: one that is not', callShellArray($projDir, Array('account', 'language', 'erin', 'french'))['errs'], "Invalid language\n");
callShellArray($projDir, Array('account', 'makeUser', 'hank', '--language=de-CH'));
checkIdentical('--language: the language of a user created', Array($sqlLink->query("SELECT userLocale FROM users WHERE systemName='hank'")->fetchColumn(), callShellArray($projDir, Array('account', 'makeUser', 'hugh', '--language=german'))['errs']), Array('de-ch', "Invalid language\n"));
checkIdentical('prove without a user: nobody awaits review', callShellArray($projDir, Array('account', 'approves'))['text'], "systemName\nNo user awaits review.\n");
$sqlLink->exec("INSERT INTO users (systemName, passwdHash, userStatus, contactEml, signupDate) VALUES ('ivan', '!', 'inReview', 'ivan@example.ch', '2026-11-04 08:00:00')");
checkIdentical('prove without a user lists those awaiting review', array_column(runShellAsJson($projDir, Array('account', 'approves'))['rows'] ?? Array(), 'systemName'), Array('ivan'));
checkIdentical('prove approves one; then it is no longer awaiting', Array(callShellArray($projDir, Array('account', 'approves', 'ivan'))['text'], runShellAsJson($projDir, Array('account', 'viewUser', 'ivan'))['userStatus'] ?? null, callShellArray($projDir, Array('account', 'approves', 'ivan'))['errs']), Array("approved: ivan\n", 'approved', "User not awaiting review\n"));
checkIdentical('prove on the users file', callShellArray($projDir, Array('account', 'approves', '--useStore=userFile'))['code'], 3);
$sqlLink = null;
checkIdentical('removal needs a confirmation', Array(callShellArray($projDir, Array('account', 'dropUser', 'ben', '--noPrompt'))['code'], callShellArray($projDir, Array('account', 'dropUser', 'ben', '--yesToAll'))['text'], callShellArray($projDir, Array('account', 'viewUser', 'ben'))['code']), Array(3, "removed: ben\n", 3));

// The other store, and the traces
checkIdentical('--useStore names the users file in place of the userbase', callShellArray($projDir, Array('account', 'fullList', '--useStore=userFile'))['text'], "systemName\nNo user.\n");
$logText = strval(fetchInProject($projDir, 'temp/logs/access.log'));
checkIdentical('the changes are traced on the access channel, signed', Array(preg_match('/\[NOTICE\] User "erin" created \(advanced\) by user "cli:[\w.\-]+"\n/', $logText), preg_match('/\[NOTICE\] 2FA secret of user "erin" removed by user "cli:/', $logText), preg_match('/\[NOTICE\] User "ben" removed \(standard\) by user "cli:/', $logText), strpos($logText, $usrTokn), strpos($logText, 'pw-erin-long')), Array(1, 1, 1, false, false));
checkPregMatch('what the userbase does not trace itself is traced by the command', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Personal token of user "ben" made by user "cli:/');

// A database that does not answer
writeInProject($projDir, 'conf/database.json', json_encode(Array('dbEngine'=>'sqlitedb', 'hostName'=>'', 'baseName'=>inTemporaryDir('nofolder').DS.'none.sqlite', 'userName'=>'', 'passWord'=>'')));
checkIdentical('a userbase that cannot be read is a failure', callShellArray($projDir, Array('account', 'fullList'))['code'], 1);

// deploy : the users table and the basic users of a new database
$tknRow = function_exists('openssl_pkey_new') ? 'token keys jwtToken' : 'secret jwtToken';
$newBase = inTemporaryDir('deploy.sqlite');
$projDir = makeProjectTxt('install', Array(
	'.env.example'=>"NYU_JWT_SECRET=\nNYU_API_KEY=\nNYU_DB_PASS=\n",
	'conf/system.ini'=>buildSystemIni(),
	// Without openssl, which RS256 needs to make its keys, the tokens are signed with a secret
	'conf/access.json.dist'=>str_replace( function_exists('openssl_pkey_new') ? '' : '"signAlgo": "RS256"', '"signAlgo": "HS256"', strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json.dist')) ),
	'conf/database.json'=>"{\n\t\"nyuRuns\": {\n\t\t\"dbEngine\": \"sqlitedb\",\n\t\t\"hostName\": \"\",\n\t\t\"baseName\": ".json_encode($newBase, JSON_UNESCAPED_SLASHES).",\n\t\t\"userName\": \"\",\n\t\t\"password\": \"\"\n\t}\n}\n",
	'conf/schema/users.sqlitedb.sql'=>strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlitedb.sql')),
	'conf/presets/default.rps'=>'{ "presetId": "default", "settings": { "confType": "all" }, "security": { "required": false } }'
));
$runVals = callShellArray($projDir, Array('install', 'allSteps', '--outputAs=json'), "\nnativeDb\n\n\nfull\n");
$jsnVals = json_decode($runVals['text'], true);
$hashLst = (new PDO('sqlite:'.$newBase))->query('SELECT systemName, passwdHash FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
checkIdentical('start asks where the database is, each value proposed as the config has it', $runVals['errs'], 'Environment (DEVS, TEST, PREV, PROD) [DEVS]: Store of the users (nativeDb, userFile, none) [userFile]: Database type (mysql_db, postgres, sqlitedb) [sqlitedb]: Database file ['.$newBase.']: Accounts to create: simple (userDemo and bossDemo) or full (userDemo, powrDemo, deptDemo, bossDemo) [simple]: Name of the standard account [userDemo]: Name of the advanced account [powrDemo]: Name of the delegate account [deptDemo]: Name of the managers account [bossDemo]: ');
checkIdentical('the users table is created with the basic users of the full version, their passwords shown once', Array($jsnVals['exit'] ?? null, $jsnVals['fail'] ?? null, array_column(runShellAsJson($projDir, Array('account', 'fullList'))['rows'] ?? Array(), 'accessRole', 'systemName'), password_verify($jsnVals['secret']['Password of bossDemo (managers)'] ?? '', $hashLst['bossDemo'] ?? ''), password_verify($jsnVals['secret']['Password of powrDemo (advanced)'] ?? '', $hashLst['powrDemo'] ?? '')), Array(0, 0, Array('bossDemo'=>'managers', 'deptDemo'=>'delegate', 'powrDemo'=>'advanced', 'userDemo'=>'standard'), true, true));
checkIdentical('the check finds the database and the users', array_intersect_key(array_column($jsnVals['rows'] ?? Array(), 'state', 'check'), Array('database nyuruns'=>1, 'users'=>1, $tknRow=>1)), Array($tknRow=>'fine', 'database nyuruns'=>'fine', 'users'=>'fine'));
checkIdentical('users alone: a table that holds users is left as it is', Array(callShellArray($projDir, Array('install', 'userBase'))['text'], count((new PDO('sqlite:'.$newBase))->query('SELECT systemName FROM users')->fetchAll())), Array("The table users already holds users: nothing was changed.\n", 4));
// The devices table, only when the access config holds a device block
$dvcQury = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='devices'";
checkIdentical('without a device block, no devices table', intval((new PDO('sqlite:'.$newBase))->query($dvcQury)->fetchColumn()), 0);
writeInProject($projDir, 'conf/schema/devices.sqlitedb.sql', strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'devices.sqlitedb.sql')));
$axsVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
$axsVals['dvcToken'] = Array('linkName'=>'nyuRuns', 'useTable'=>'devices', 'usersTbl'=>'users', 'seenSecs'=>60);
writeInProject($projDir, 'conf/access.json', json_encode($axsVals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
checkIdentical('with a device block, users creates the devices table, linked to the users', Array(callShellArray($projDir, Array('install', 'userBase'))['text'], intval((new PDO('sqlite:'.$newBase))->query($dvcQury)->fetchColumn()), (new PDO('sqlite:'.$newBase))->query("SELECT \"table\", \"from\", \"to\", on_delete FROM pragma_foreign_key_list('devices')")->fetchAll(PDO::FETCH_NUM)), Array("The table users already holds users: nothing was changed.\nTable devices created.\n", 1, Array(Array('users', 'user_id', 'id_user', 'CASCADE'))));
checkIdentical('a devices table that exists is left as it is', callShellArray($projDir, Array('install', 'userBase'))['text'], "The table users already holds users: nothing was changed.\n");
(new PDO('sqlite:'.$newBase))->exec('DROP TABLE devices');
unlink($projDir.DS.'conf'.DS.'schema'.DS.'users.sqlitedb.sql');
(new PDO('sqlite:'.$newBase))->exec('DROP TABLE users');
$jsnVals = runShellAsJson($projDir, Array('install', 'checkAll'));
checkIdentical('a missing users table fails the check, and cannot be created without its schema', Array($jsnVals['exit'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'state', 'check')['users'] ?? null, callShellArray($projDir, Array('install', 'userBase'))['code']), Array(4, 'fail', 3));

finishChecking();
