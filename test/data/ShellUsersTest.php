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
	'conf/database.json'=>json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>$basePth, 'user'=>'', 'pass'=>'')),
	'conf/access.json'=>"{\n\t\"login\":  { \"authby\": \"native\", \"authcf\": \"tsnatv\", \"sessby\": \"jtoken\", \"sesscf\": \"dftokn\" },\n\t\"tsnatv\": { \"tblnme\": \"users\", \"minlen\": 8 },\n\t\"usrlst\": { \"usrfle\": \"conf/users.lst\" }\n}\n"
));
$natAuth = new Native( Array('tblnme'=>'users') );

// Reading
$jsnVals = runShellAsJson($projDir, Array('manage', 'lists'));
checkIdentical('lists the users of the userbase the login names', Array(array_column($jsnVals['rows'] ?? Array(), 'userName'), array_keys($jsnVals['rows'][0] ?? Array())), Array(Array('anna', 'ben', 'cleo', 'dave'), Array('id_user', 'userName', 'userRole', 'userStat', 'userMail', 'userLang', 'signDate', 'madeDate', 'lastSeen')));
checkIdentical('lists by status', array_column(runShellAsJson($projDir, Array('manage', 'lists', '--ustat=locked'))['rows'] ?? Array(), 'userName'), Array('dave'));
checkIdentical('views a user; an unknown one', Array(runShellAsJson($projDir, Array('manage', 'views', 'ben'))['userRole'] ?? null, callShellArray($projDir, Array('manage', 'views', 'nobody'))['errs']), Array('member', "User not found\n"));

// Creation and activation
$jsnVals = runShellAsJson($projDir, Array('manage', 'enrol', 'erin', 'system'));
$actCode = strval($jsnVals['secret']['Activation code of erin'] ?? '');
checkIdentical('enrol creates a pending user and shows its activation code once', Array($jsnVals['created'] ?? null, strlen($actCode), runShellAsJson($projDir, Array('manage', 'views', 'erin'))['userStat'] ?? null), Array('erin', 12, 'pendng'));
checkIdentical('a name already taken', callShellArray($projDir, Array('manage', 'enrol', 'Erin'))['errs'], "User name already taken\n");
checkIdentical('a wrong code does not activate', callShellArray($projDir, Array('manage', 'actvt', 'erin', 'wrong', '--typed'), "pw-erin-long\n")['code'], 3);
checkIdentical('actvt activates with the code and a typed password', Array(callShellArray($projDir, Array('manage', 'actvt', 'erin', $actCode, '--typed'), "pw-erin-long\n")['text'], $natAuth->chckCredential(Array('user'=>'erin', 'pass'=>'pw-erin-long'))), Array("activated: erin\n", Array('user'=>'erin', 'role'=>'system', 'id'=>5)));

// Status, role
checkIdentical('locks and unlocks', Array(callShellArray($projDir, Array('manage', 'locks', 'erin'))['text'], $natAuth->chckCredential(Array('user'=>'erin', 'pass'=>'pw-erin-long')), callShellArray($projDir, Array('manage', 'unlck', 'erin'))['text'], $natAuth->chckCredential(Array('user'=>'erin', 'pass'=>'pw-erin-long'))!==false), Array("locked: erin\n", false, "unlocked: erin\n", true));
checkIdentical('the last active manager is kept as it is', Array(callShellArray($projDir, Array('manage', 'locks', 'anna'))['errs'], callShellArray($projDir, Array('manage', 'remov', 'anna', '--agree'))['code']), Array("The last active manager cannot be locked\n", 3));
checkIdentical('roles gives another role', Array(callShellArray($projDir, Array('manage', 'roles', 'erin', 'manage'))['text'], $natAuth->usrIdentityArr('erin')['role'] ?? null), Array("erin: manage\n", 'manage'));

// Password, token, 2FA
$jsnVals = runShellAsJson($projDir, Array('manage', 'passw', 'ben'));
checkIdentical('passw gives a new password, shown once', Array($natAuth->chckCredential(Array('user'=>'ben', 'pass'=>strval($jsnVals['secret']['Password of ben'] ?? '')))['user'] ?? null, $natAuth->chckCredential(Array('user'=>'ben', 'pass'=>'pw-ben'))), Array('ben', false));
checkIdentical('a typed password follows the rules; an unknown user', Array(callShellArray($projDir, Array('manage', 'passw', 'ben', '--typed'), "short\n")['errs'], callShellArray($projDir, Array('manage', 'passw', 'nobody'))['code']), Array("Password of ben: Password too short: 8 characters at least\n", 3));
$jsnVals = runShellAsJson($projDir, Array('manage', 'token', 'ben'));
$usrTokn = strval($jsnVals['secret']['Personal token of ben'] ?? '');
checkIdentical('token gives a personal token that is accepted', Array(strlen($usrTokn), $natAuth->chckCredential(Array('key'=>$usrTokn))['user'] ?? null), Array(64, 'ben'));
checkIdentical('token --clear takes it away', Array(callShellArray($projDir, Array('manage', 'token', 'ben', '--clear', '--agree'))['text'], $natAuth->chckCredential(Array('key'=>$usrTokn))), Array("token removed: ben\n", false));
$jsnVals = runShellAsJson($projDir, Array('manage', 'twofa', 'erin'));
$usrScrt = strval($jsnVals['secret']['2FA secret of erin'] ?? '');
checkIdentical('twofa gives a secret: the password alone is no longer enough', Array(strlen($usrScrt), $natAuth->chckCredential(Array('user'=>'erin', 'pass'=>'pw-erin-long')), $natAuth->finalErrorText(), $natAuth->chckCredential(Array('user'=>'erin', 'pass'=>'pw-erin-long', 'code'=>Totp::codeAtTimeText($usrScrt, time())))['user'] ?? null), Array(32, false, 'Verification code required', 'erin'));
checkIdentical('a lost authenticator: twofa --clear lets the password log in again', Array(callShellArray($projDir, Array('manage', 'twofa', 'erin', '--clear', '--noask'))['code'], callShellArray($projDir, Array('manage', 'twofa', 'erin', '--clear'), "y\n")['text'], $natAuth->chckCredential(Array('user'=>'erin', 'pass'=>'pw-erin-long'))['user'] ?? null), Array(3, "2FA removed: erin\n", 'erin'));
checkIdentical('a locked or unknown user is given neither', Array(callShellArray($projDir, Array('manage', 'token', 'dave'))['code'], callShellArray($projDir, Array('manage', 'twofa', 'nobody'))['code'], callShellArray($projDir, Array('manage', 'twofa', 'nobody', '--clear', '--agree'))['errs']), Array(3, 3, "User not found\n"));
// Codes by mail : a mail account, and an address for the user
callShellArray($projDir, Array('manage', 'email', 'erin', 'erin@example.ch'));
checkIdentical('twofa --email without a mailer is refused', callShellArray($projDir, Array('manage', 'twofa', 'erin', '--email'))['errs'], "No mail account sends the codes: mailer is not set\n");
writeInProject($projDir, 'conf/mailer.json', '{ "devbox": { "type": "tofile", "from": "no-reply@example.com", "fold": "temp/mails" } }');
callShellArray($projDir, Array('config', 'write', 'access', 'tsnatv.mailer', 'devbox', '--force'));
checkIdentical('twofa --email: the user gives a mailed code at each login', Array(callShellArray($projDir, Array('manage', 'twofa', 'erin', '--email'))['text'], (new PDO('sqlite:'.$basePth))->query("SELECT twoFaWay FROM users WHERE userName='erin'")->fetchColumn()), Array("2FA set: erin (codes by mail)\n", 'mailed'));
checkPregMatch('twofa --email is traced', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Mailed codes set for user "erin" by user "cli:/');
checkIdentical('a move to the users file is refused while a user has mailed codes', Array(callShellArray($projDir, Array('deploy', 'store', 'usrlst', '--agree'))['errs'], is_file($projDir.DS.'conf'.DS.'users.lst')), Array("Nothing was moved: codes by mail need the native userbase (erin); \"manage twofa <user> --clear\" takes them away\n", false));
checkIdentical('twofa --clear takes them away', Array(callShellArray($projDir, Array('manage', 'twofa', 'erin', '--clear', '--agree'))['text'], (new PDO('sqlite:'.$basePth))->query("SELECT twoFaWay FROM users WHERE userName='erin'")->fetchColumn()), Array("2FA removed: erin\n", null));

// Reset, removal
$jsnVals = runShellAsJson($projDir, Array('manage', 'reset', 'ben'));
checkIdentical('reset puts the user back to pending, with a new code', Array(strlen($jsnVals['secret']['Activation code of ben'] ?? ''), runShellAsJson($projDir, Array('manage', 'views', 'ben'))['userStat'] ?? null), Array(12, 'pendng'));
$sqlLink = new PDO('sqlite:'.$basePth);
checkIdentical('a code shown here lasts 300 minutes', abs(strtotime(strval($sqlLink->query("SELECT codeDate FROM users WHERE userName='ben'")->fetchColumn())) - (time()+300*60))<=5, true);
callShellArray($projDir, Array('manage', 'reset', 'ben', '--noexp'));
callShellArray($projDir, Array('manage', 'enrol', 'gail', '--noexp'));
checkIdentical('--noexp: no end, at a reset or a creation', $sqlLink->query("SELECT codeDate FROM users WHERE userName IN ('ben', 'gail')")->fetchAll(PDO::FETCH_COLUMN), Array(null, null));

// Addresses, sign-ups awaiting review
checkIdentical('email gives an address, in lower case', Array(callShellArray($projDir, Array('manage', 'email', 'erin', 'Erin@Example.ch'))['text'], runShellAsJson($projDir, Array('manage', 'views', 'erin'))['userMail'] ?? null), Array("address set: erin\n", 'erin@example.ch'));
checkIdentical('an address taken, one that is not', Array(callShellArray($projDir, Array('manage', 'email', 'anna', 'erin@example.ch'))['errs'], callShellArray($projDir, Array('manage', 'email', 'anna', 'anna'))['errs']), Array("Mail address already used\n", "Invalid mail address\n"));
checkIdentical('email without an address takes it away', Array(callShellArray($projDir, Array('manage', 'email', 'erin'))['text'], array_key_exists('userMail', $viewArr = runShellAsJson($projDir, Array('manage', 'views', 'erin'))) ? $viewArr['userMail'] : 'missing'), Array("address removed: erin\n", null));
checkIdentical('langs gives a language, shows it, takes it away', Array(callShellArray($projDir, Array('manage', 'langs', 'erin', 'FR_ch'))['text'], callShellArray($projDir, Array('manage', 'langs', 'erin'))['text'], callShellArray($projDir, Array('manage', 'langs', 'erin', ''))['text'], callShellArray($projDir, Array('manage', 'langs', 'erin'))['text']), Array("language set: erin\n", "erin: fr-ch\n", "language removed: erin\n", "erin: (default)\n"));
checkIdentical('langs: one that is not', callShellArray($projDir, Array('manage', 'langs', 'erin', 'french'))['errs'], "Invalid language\n");
callShellArray($projDir, Array('manage', 'enrol', 'hank', '--ulang=de-CH'));
checkIdentical('--ulang: the language of a user created', Array($sqlLink->query("SELECT userLang FROM users WHERE userName='hank'")->fetchColumn(), callShellArray($projDir, Array('manage', 'enrol', 'hugh', '--ulang=german'))['errs']), Array('de-ch', "Invalid language\n"));
checkIdentical('prove without a user: nobody awaits review', callShellArray($projDir, Array('manage', 'prove'))['text'], "userName\nNo user awaits review.\n");
$sqlLink->exec("INSERT INTO users (userName, passHash, userStat, userMail, signDate) VALUES ('ivan', '!', 'review', 'ivan@example.ch', '2026-11-04 08:00:00')");
checkIdentical('prove without a user lists those awaiting review', array_column(runShellAsJson($projDir, Array('manage', 'prove'))['rows'] ?? Array(), 'userName'), Array('ivan'));
checkIdentical('prove approves one; then it is no longer awaiting', Array(callShellArray($projDir, Array('manage', 'prove', 'ivan'))['text'], runShellAsJson($projDir, Array('manage', 'views', 'ivan'))['userStat'] ?? null, callShellArray($projDir, Array('manage', 'prove', 'ivan'))['errs']), Array("approved: ivan\n", 'active', "User not awaiting review\n"));
checkIdentical('prove on the users file', callShellArray($projDir, Array('manage', 'prove', '--store=usrlst'))['code'], 3);
$sqlLink = null;
checkIdentical('removal needs a confirmation', Array(callShellArray($projDir, Array('manage', 'remov', 'ben', '--noask'))['code'], callShellArray($projDir, Array('manage', 'remov', 'ben', '--agree'))['text'], callShellArray($projDir, Array('manage', 'views', 'ben'))['code']), Array(3, "removed: ben\n", 3));

// The other store, and the traces
checkIdentical('--store names the users file in place of the userbase', callShellArray($projDir, Array('manage', 'lists', '--store=usrlst'))['text'], "userName\nNo user.\n");
$logText = strval(fetchInProject($projDir, 'temp/logs/access.log'));
checkIdentical('the changes are traced on the access channel, signed', Array(preg_match('/\[NOTICE\] User "erin" created \(system\) by user "cli:[\w.\-]+"\n/', $logText), preg_match('/\[NOTICE\] 2FA secret of user "erin" removed by user "cli:/', $logText), preg_match('/\[NOTICE\] User "ben" removed \(member\) by user "cli:/', $logText), strpos($logText, $usrTokn), strpos($logText, 'pw-erin-long')), Array(1, 1, 1, false, false));
checkPregMatch('what the userbase does not trace itself is traced by the command', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Personal token of user "ben" made by user "cli:/');

// A database that does not answer
writeInProject($projDir, 'conf/database.json', json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>inTemporaryDir('nofolder').DS.'none.sqlite', 'user'=>'', 'pass'=>'')));
checkIdentical('a userbase that cannot be read is a failure', callShellArray($projDir, Array('manage', 'lists'))['code'], 1);

// deploy : the users table and the basic users of a new database
$tknRow = function_exists('openssl_pkey_new') ? 'token keys dftokn' : 'secret dftokn';
$newBase = inTemporaryDir('deploy.sqlite');
$projDir = makeProjectTxt('install', Array(
	'.env.example'=>"NYU_JWT_SECRET=\nNYU_API_KEY=\nNYU_DB_PASS=\n",
	'conf/system.ini'=>buildSystemIni(),
	// Without openssl, which RS256 needs to make its keys, the tokens are signed with a secret
	'conf/access.json.dist'=>str_replace( function_exists('openssl_pkey_new') ? '' : '"algrtm": "RS256"', '"algrtm": "HS256"', strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json.dist')) ),
	'conf/database.json'=>"{\n\t\"nyuRuns\": {\n\t\t\"type\": \"sqlte\",\n\t\t\"host\": \"\",\n\t\t\"base\": ".json_encode($newBase, JSON_UNESCAPED_SLASHES).",\n\t\t\"user\": \"\",\n\t\t\"pass\": \"\"\n\t}\n}\n",
	'conf/schema/users.sqlte.sql'=>strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql')),
	'conf/presets/dfault.rps'=>'{ "label": "dfault", "setup": { "ftypes": "all" }, "auths": { "needed": false } }'
));
$runVals = callShellArray($projDir, Array('deploy', 'start', '--shape=json'), "\nnative\n\n\n");
$jsnVals = json_decode($runVals['text'], true);
$hashLst = (new PDO('sqlite:'.$newBase))->query('SELECT userName, passHash FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
checkIdentical('start asks where the database is, each value proposed as the config has it', $runVals['errs'], 'Environment (DEV, TEST, PREV, PROD) [DEV]: Store of the users (native, usrlst, none) [usrlst]: Database type (mysql, pgsql, sqlte) [sqlte]: Database file ['.$newBase.']: ');
checkIdentical('the users table is created with the basic users, their passwords shown once', Array($jsnVals['exit'] ?? null, $jsnVals['fail'] ?? null, array_column(runShellAsJson($projDir, Array('manage', 'lists'))['rows'] ?? Array(), 'userRole', 'userName'), password_verify($jsnVals['secret']['Password of managr (manage)'] ?? '', $hashLst['managr'] ?? ''), password_verify($jsnVals['secret']['Password of system (system)'] ?? '', $hashLst['system'] ?? '')), Array(0, 0, Array('managr'=>'manage', 'member'=>'member', 'system'=>'system'), true, true));
checkIdentical('the check finds the database and the users', array_intersect_key(array_column($jsnVals['rows'] ?? Array(), 'state', 'check'), Array('database nyuruns'=>1, 'users'=>1, $tknRow=>1)), Array($tknRow=>'fine', 'database nyuruns'=>'fine', 'users'=>'fine'));
checkIdentical('users alone: a table that holds users is left as it is', Array(callShellArray($projDir, Array('deploy', 'users'))['text'], count((new PDO('sqlite:'.$newBase))->query('SELECT userName FROM users')->fetchAll())), Array("The table users already holds users: nothing was changed.\n", 3));
// The devices table, only when the access config holds a device block
$dvcQury = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='devices'";
checkIdentical('without a device block, no devices table', intval((new PDO('sqlite:'.$newBase))->query($dvcQury)->fetchColumn()), 0);
writeInProject($projDir, 'conf/schema/devices.sqlte.sql', strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'devices.sqlte.sql')));
$axsVals = json_decode(strval(fetchInProject($projDir, 'conf/access.json')), true);
$axsVals['device'] = Array('basnme'=>'nyuRuns', 'tblnme'=>'devices', 'usrtbl'=>'users', 'stmptm'=>60);
writeInProject($projDir, 'conf/access.json', json_encode($axsVals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
checkIdentical('with a device block, users creates the devices table, linked to the users', Array(callShellArray($projDir, Array('deploy', 'users'))['text'], intval((new PDO('sqlite:'.$newBase))->query($dvcQury)->fetchColumn()), (new PDO('sqlite:'.$newBase))->query("SELECT \"table\", \"from\", \"to\", on_delete FROM pragma_foreign_key_list('devices')")->fetchAll(PDO::FETCH_NUM)), Array("The table users already holds users: nothing was changed.\nTable devices created.\n", 1, Array(Array('users', 'user_id', 'id_user', 'CASCADE'))));
checkIdentical('a devices table that exists is left as it is', callShellArray($projDir, Array('deploy', 'users'))['text'], "The table users already holds users: nothing was changed.\n");
(new PDO('sqlite:'.$newBase))->exec('DROP TABLE devices');
unlink($projDir.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql');
(new PDO('sqlite:'.$newBase))->exec('DROP TABLE users');
$jsnVals = runShellAsJson($projDir, Array('deploy', 'check'));
checkIdentical('a missing users table fails the check, and cannot be created without its schema', Array($jsnVals['exit'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'state', 'check')['users'] ?? null, callShellArray($projDir, Array('deploy', 'users'))['code']), Array(4, 'fail', 3));

finishChecking();
