<?php

// Command line, manage : the users of the native userbase, on the sample
// SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';
require __DIR__.'/../lib/shellKit.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Shell users', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Access\Auth\Native;
use nyu\Access\Totp;

$basePth = inTempDir('shell.sqlite');
buildData($basePth);
useSqlite($basePth);
$projDir = makeProjt('users', Array(
	'conf/system.ini'=>sampleIni(),
	'conf/database.json'=>json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>$basePth, 'user'=>'', 'pass'=>'')),
	'conf/access.json'=>"{\n\t\"login\":  { \"authby\": \"native\", \"authcf\": \"tsnatv\", \"sessby\": \"jtoken\", \"sesscf\": \"dftokn\" },\n\t\"tsnatv\": { \"tblnme\": \"users\", \"minlen\": 8 },\n\t\"usrlst\": { \"usrfle\": \"conf/users.lst\" }\n}\n"
));
$natAuth = new Native( Array('tblnme'=>'users') );

// Reading
$jsnVals = jsonShell($projDir, Array('manage', 'lists'));
checkSame('lists the users of the userbase the login names', Array(array_column($jsnVals['rows'] ?? Array(), 'userName'), array_keys($jsnVals['rows'][0] ?? Array())), Array(Array('anna', 'ben', 'cleo', 'dave'), Array('id_user', 'userName', 'userRole', 'userStat', 'madeDate', 'lastSeen')));
checkSame('lists by status', array_column(jsonShell($projDir, Array('manage', 'lists', '--ustat=locked'))['rows'] ?? Array(), 'userName'), Array('dave'));
checkSame('views a user; an unknown one', Array(jsonShell($projDir, Array('manage', 'views', 'ben'))['userRole'] ?? null, runShell($projDir, Array('manage', 'views', 'nobody'))['errs']), Array('member', "User not found\n"));

// Creation and activation
$jsnVals = jsonShell($projDir, Array('manage', 'enrol', 'erin', 'system'));
$actCode = strval($jsnVals['secret']['Activation code of erin'] ?? '');
checkSame('enrol creates a pending user and shows its activation code once', Array($jsnVals['created'] ?? null, strlen($actCode), jsonShell($projDir, Array('manage', 'views', 'erin'))['userStat'] ?? null), Array('erin', 32, 'pendng'));
checkSame('a name already taken', runShell($projDir, Array('manage', 'enrol', 'Erin'))['errs'], "User name already taken\n");
checkSame('a wrong code does not activate', runShell($projDir, Array('manage', 'actvt', 'erin', 'wrong', '--typed'), "pw-erin-long\n")['code'], 3);
checkSame('actvt activates with the code and a typed password', Array(runShell($projDir, Array('manage', 'actvt', 'erin', $actCode, '--typed'), "pw-erin-long\n")['text'], $natAuth->checkCred(Array('user'=>'erin', 'pass'=>'pw-erin-long'))), Array("activated: erin\n", Array('user'=>'erin', 'role'=>'system', 'id'=>5)));

// Status, role
checkSame('locks and unlocks', Array(runShell($projDir, Array('manage', 'locks', 'erin'))['text'], $natAuth->checkCred(Array('user'=>'erin', 'pass'=>'pw-erin-long')), runShell($projDir, Array('manage', 'unlck', 'erin'))['text'], $natAuth->checkCred(Array('user'=>'erin', 'pass'=>'pw-erin-long'))!==false), Array("locked: erin\n", false, "unlocked: erin\n", true));
checkSame('the last active manager is kept as it is', Array(runShell($projDir, Array('manage', 'locks', 'anna'))['errs'], runShell($projDir, Array('manage', 'remov', 'anna', '--agree'))['code']), Array("The last active manager cannot be locked\n", 3));
checkSame('roles gives another role', Array(runShell($projDir, Array('manage', 'roles', 'erin', 'manage'))['text'], $natAuth->userIdent('erin')['role'] ?? null), Array("erin: manage\n", 'manage'));

// Password, token, 2FA
$jsnVals = jsonShell($projDir, Array('manage', 'passw', 'ben'));
checkSame('passw gives a new password, shown once', Array($natAuth->checkCred(Array('user'=>'ben', 'pass'=>strval($jsnVals['secret']['Password of ben'] ?? '')))['user'] ?? null, $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben'))), Array('ben', false));
checkSame('a typed password follows the rules; an unknown user', Array(runShell($projDir, Array('manage', 'passw', 'ben', '--typed'), "short\n")['errs'], runShell($projDir, Array('manage', 'passw', 'nobody'))['code']), Array("Password of ben: Password too short: 8 characters at least\n", 3));
$jsnVals = jsonShell($projDir, Array('manage', 'token', 'ben'));
$usrTokn = strval($jsnVals['secret']['Personal token of ben'] ?? '');
checkSame('token gives a personal token that is accepted', Array(strlen($usrTokn), $natAuth->checkCred(Array('key'=>$usrTokn))['user'] ?? null), Array(64, 'ben'));
checkSame('token --clear takes it away', Array(runShell($projDir, Array('manage', 'token', 'ben', '--clear', '--agree'))['text'], $natAuth->checkCred(Array('key'=>$usrTokn))), Array("token removed: ben\n", false));
$jsnVals = jsonShell($projDir, Array('manage', 'twofa', 'erin'));
$usrScrt = strval($jsnVals['secret']['2FA secret of erin'] ?? '');
checkSame('twofa gives a secret: the password alone is no longer enough', Array(strlen($usrScrt), $natAuth->checkCred(Array('user'=>'erin', 'pass'=>'pw-erin-long')), $natAuth->lastError(), $natAuth->checkCred(Array('user'=>'erin', 'pass'=>'pw-erin-long', 'code'=>Totp::codeAtTme($usrScrt, time())))['user'] ?? null), Array(32, false, 'Verification code required', 'erin'));
checkSame('a lost authenticator: twofa --clear lets the password log in again', Array(runShell($projDir, Array('manage', 'twofa', 'erin', '--clear', '--noask'))['code'], runShell($projDir, Array('manage', 'twofa', 'erin', '--clear'), "y\n")['text'], $natAuth->checkCred(Array('user'=>'erin', 'pass'=>'pw-erin-long'))['user'] ?? null), Array(3, "2FA removed: erin\n", 'erin'));
checkSame('a locked or unknown user is given neither', Array(runShell($projDir, Array('manage', 'token', 'dave'))['code'], runShell($projDir, Array('manage', 'twofa', 'nobody'))['code'], runShell($projDir, Array('manage', 'twofa', 'nobody', '--clear', '--agree'))['errs']), Array(3, 3, "User not found\n"));

// Reset, removal
$jsnVals = jsonShell($projDir, Array('manage', 'reset', 'ben'));
checkSame('reset puts the user back to pending, with a new code', Array(strlen($jsnVals['secret']['Activation code of ben'] ?? ''), jsonShell($projDir, Array('manage', 'views', 'ben'))['userStat'] ?? null), Array(32, 'pendng'));
checkSame('removal needs a confirmation', Array(runShell($projDir, Array('manage', 'remov', 'ben', '--noask'))['code'], runShell($projDir, Array('manage', 'remov', 'ben', '--agree'))['text'], runShell($projDir, Array('manage', 'views', 'ben'))['code']), Array(3, "removed: ben\n", 3));

// The other store, and the traces
checkSame('--store names the users file in place of the userbase', runShell($projDir, Array('manage', 'lists', '--store=usrlst'))['text'], "userName\nNo user.\n");
$logText = strval(readInPrj($projDir, 'temp/logs/access.log'));
checkSame('the changes are traced on the access channel, signed', Array(preg_match('/\[NOTICE\] User "erin" created \(system\) by user "cli:[\w.\-]+"\n/', $logText), preg_match('/\[NOTICE\] 2FA secret of user "erin" removed by user "cli:/', $logText), preg_match('/\[NOTICE\] User "ben" removed \(member\) by user "cli:/', $logText), strpos($logText, $usrTokn), strpos($logText, 'pw-erin-long')), Array(1, 1, 1, false, false));
checkLike('what the userbase does not trace itself is traced by the command', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/\[NOTICE\] Personal token of user "ben" made by user "cli:/');

// A database that does not answer
putInProj($projDir, 'conf/database.json', json_encode(Array('type'=>'sqlte', 'host'=>'', 'base'=>inTempDir('nofolder').DS.'none.sqlite', 'user'=>'', 'pass'=>'')));
checkSame('a userbase that cannot be read is a failure', runShell($projDir, Array('manage', 'lists'))['code'], 1);

// deploy : the users table and the basic users of a new database
$newBase = inTempDir('deploy.sqlite');
$projDir = makeProjt('install', Array(
	'.env.example'=>"NYU_JWT_SECRET=\nNYU_API_KEY=\nNYU_DB_PASS=\n",
	'conf/system.ini'=>sampleIni(),
	'conf/access.json.dist'=>strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'access.json.dist')),
	'conf/database.json'=>"{\n\t\"nyuRuns\": {\n\t\t\"type\": \"sqlte\",\n\t\t\"host\": \"\",\n\t\t\"base\": ".json_encode($newBase, JSON_UNESCAPED_SLASHES).",\n\t\t\"user\": \"\",\n\t\t\"pass\": \"\"\n\t}\n}\n",
	'conf/schema/users.sqlte.sql'=>strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql')),
	'conf/presets/dfault.rps'=>'{ "label": "dfault", "setup": { "ftypes": "all" }, "auths": { "needed": false } }'
));
$runVals = runShell($projDir, Array('deploy', 'start', '--shape=json'), "\n\n\n\n");
$jsnVals = json_decode($runVals['text'], true);
$hashLst = (new PDO('sqlite:'.$newBase))->query('SELECT userName, passHash FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
checkSame('start asks where the database is, each value proposed as the config has it', $runVals['errs'], 'Environment (DEV, TEST, PREV, PROD) [DEV]: Store of the users (native, usrlst, none) [native]: Database type (mysql, pgsql, sqlte) [sqlte]: Database file ['.$newBase.']: ');
checkSame('the users table is created with the basic users, their passwords shown once', Array($jsnVals['exit'] ?? null, $jsnVals['fail'] ?? null, array_column(jsonShell($projDir, Array('manage', 'lists'))['rows'] ?? Array(), 'userRole', 'userName'), password_verify($jsnVals['secret']['Password of managr (manage)'] ?? '', $hashLst['managr'] ?? ''), password_verify($jsnVals['secret']['Password of system (system)'] ?? '', $hashLst['system'] ?? '')), Array(0, 0, Array('managr'=>'manage', 'member'=>'member', 'system'=>'system'), true, true));
checkSame('the check finds the database and the users', array_intersect_key(array_column($jsnVals['rows'] ?? Array(), 'state', 'check'), Array('database nyuruns'=>1, 'users'=>1, 'secret dftokn'=>1)), Array('secret dftokn'=>'fine', 'database nyuruns'=>'fine', 'users'=>'fine'));
checkSame('users alone: a table that holds users is left as it is', Array(runShell($projDir, Array('deploy', 'users'))['text'], count((new PDO('sqlite:'.$newBase))->query('SELECT userName FROM users')->fetchAll())), Array("The table users already holds users: nothing was changed.\n", 3));
unlink($projDir.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql');
(new PDO('sqlite:'.$newBase))->exec('DROP TABLE users');
$jsnVals = jsonShell($projDir, Array('deploy', 'check'));
checkSame('a missing users table fails the check, and cannot be created without its schema', Array($jsnVals['exit'] ?? null, array_column($jsnVals['rows'] ?? Array(), 'state', 'check')['users'] ?? null, runShell($projDir, Array('deploy', 'users'))['code']), Array(4, 'fail', 3));

endChecks();
