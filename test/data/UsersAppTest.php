<?php

// runs/Users and the activate / password actions of runs/Auth : the users
// of the native userbase managed through the framework's Controller, so
// that the input definitions apply, against a SQLite table built from the
// packaged schema (conf/schema/users.sqlte.sql).
//
//   user 1  anna   manage  active
//   user 2  ben    member  active
//   user 3  dave   system  locked
//
// Passwords are pass-<name>-1.

require __DIR__.'/../lib/harness.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Users app', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\App\Controller;
use nyu\Tools\Logger;
use nyu\Tools\Registry;

$sqlLink = new PDO('sqlite:'.inTempDir('usersapp.sqlite'));
$sqlLink->exec( strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql')) );
$usrStmt = $sqlLink->prepare("INSERT INTO users (id_user, userName, passHash, userRole, userStat, madeDate) VALUES (?,?,?,?,?,'2026-10-01 09:00:00')");
foreach( Array(Array(1,'anna','manage','active'), Array(2,'ben','member','active'), Array(3,'dave','system','locked')) as $usrRow ){
	$usrStmt->execute( Array($usrRow[0], $usrRow[1], password_hash('pass-'.$usrRow[1].'-1', PASSWORD_BCRYPT, Array('cost'=>4)), $usrRow[2], $usrRow[3]) );
}
$usrStmt = null;

// The counters of the address limit lie under the project root : in a folder of this script's own
$lmtFldr = 'temp/tstlim_'.getmypid();

/**
 * Plugs the configuration the actions read.
 *
 * @param String $authbyName Credential check of the login block.
 * @param Int    $triesLimit Wrong credentials allowed from one address, 0 for no limit.
 */
function plugConf( String $authbyName='native', Int $triesLimit=0 ): Void {
	$GLOBALS['nyuConfig'] = new Registry( Array(
		'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>inTempDir('usersapp.sqlite')),
		'access'=>Array(
			'LOGIN'=>Array('authby'=>$authbyName, 'authcf'=>'native'),
			'NATIVE'=>Array('tblnme'=>'users'),
			'LIMITS'=>Array('maxtry'=>$triesLimit, 'holdtm'=>600, 'folder'=>$GLOBALS['lmtFldr'])
		)
	) );
}

/**
 * Runs an action through the Controller, as a request of the given
 * method coming from the given user.
 *
 * @param String $routeNamed The action, as 'app/action'.
 * @param Array  $inputArray Input values sent.
 * @param String $loggedUser Name of the logged user, empty for nobody.
 * @param String $loggedRole Its role.
 * @param String $httpMethod Method of the request.
 * @return mixed Array with 'code', 'mssg' and 'out', or a 'THROWN ...' string.
 */
function callActn( String $routeNamed, Array $inputArray=Array(), String $loggedUser='anna', String $loggedRole='manage', String $httpMethod='POST' ): Mixed {
	return runSafely(function() use ($routeNamed, $inputArray, $loggedUser, $loggedRole, $httpMethod){
		$request = fakeReqst( Array(), Array('REMOTE_ADDR'=>'192.0.2.50') );
		$mthProp = new ReflectionProperty('nyu\Tools\Request', 'inMethod');
		$mthProp->setAccessible(true);
		$mthProp->setValue($request, $httpMethod);
		$axssReg = new Registry( ($loggedUser!='')
			? Array('logged'=>true, 'user'=>$loggedUser, 'role'=>$loggedRole, 'statCode'=>200, 'statMssg'=>'')
			: Array('logged'=>false, 'user'=>'', 'role'=>null, 'statCode'=>200, 'statMssg'=>'') );
		Logger::plugActor( $loggedUser, '192.0.2.50' );
		$rteName = explode('/', $routeNamed);
		$control = new Controller( $rteName[0], $rteName[1], new Registry($inputArray), null, $axssReg, $request );
		$statArr = $control->getStatus();
		return Array('code'=>$statArr['code'], 'mssg'=>$statArr['mssg'], 'out'=>$control->getOutput());
	});
}

/**
 * Reduces the result of an action to its status and message.
 *
 * @param mixed $actnResult Result of callActn().
 * @return mixed The status and the message, or the result itself when the action threw.
 */
function statusOf( Mixed $actnResult ): Mixed {
	return is_array($actnResult) ? Array($actnResult['code'], $actnResult['mssg']) : $actnResult;
}

Logger::plugSetup( Array('path'=>inTempDir('logs')) );
plugConf();

// Who may call : a logged user of the highest role, and nobody else
foreach( Array('index'=>'GET', 'view'=>'GET', 'create'=>'POST', 'lock'=>'POST', 'unlock'=>'POST', 'role'=>'POST', 'remove'=>'POST', 'reset'=>'POST') as $actNme=>$mthNme ){
	$inptArr = Array('user'=>'ben', 'role'=>'system');
	checkSame('guard: users/'.$actNme.' refused to a member', statusOf(callActn('users/'.$actNme, $inptArr, 'ben', 'member', $mthNme)), Array(403, 'Forbidden'));
	checkSame('guard: users/'.$actNme.' refused to the system role', statusOf(callActn('users/'.$actNme, $inptArr, 'dave', 'system', $mthNme)), Array(403, 'Forbidden'));
	checkSame('guard: users/'.$actNme.' refused when nobody is logged', statusOf(callActn('users/'.$actNme, $inptArr, '', '', $mthNme)), Array(403, 'Forbidden'));
	checkSame('guard: users/'.$actNme.' answers to '.$mthNme.' only', statusOf(callActn('users/'.$actNme, $inptArr, 'anna', 'manage', ($mthNme=='GET') ? 'POST' : 'GET')), Array(405, 'Method Not Allowed'));
}
checkSame('guard: a refusal changed nothing', $sqlLink->query("SELECT userName, userRole, userStat FROM users ORDER BY id_user")->fetchAll(PDO::FETCH_NUM), Array(Array('anna', 'manage', 'active'), Array('ben', 'member', 'active'), Array('dave', 'system', 'locked')));
checkLike('guard: a refusal is logged as a warning, with the role needed', strval(@file_get_contents(inTempDir('logs').DS.'access.log')), '/\[WARNING\] Access refused for user "ben" \(member\) from 192\.0\.2\.50 to users\/index: role manage needed/');
checkSame('guard: the helpers are not actions', Array(statusOf(callActn('users/startCall', Array(), 'anna', 'manage')), statusOf(callActn('users/givenUser', Array(), 'anna', 'manage'))), Array(Array(500, 'Unknown Action'), Array(500, 'Unknown Action')));
plugConf('htpass');
checkSame('guard: a login on another credential check', statusOf(callActn('users/index', Array(), 'anna', 'manage', 'GET')), Array(500, 'User management unavailable'));
plugConf();

// create
$outcome = callActn('users/create', Array('user'=>'emma'));
$emmaCod = strval($outcome['out']['code'] ?? '');
checkSame('create: 201 with the name', Array($outcome['code'] ?? $outcome, $outcome['out']['user'] ?? null), Array(201, 'emma'));
checkLike('create: the code is given', $emmaCod, '/^[0-9a-f]{32}$/');
checkSame('create: with a role', callActn('users/create', Array('user'=>'fred', 'role'=>'system'))['code'] ?? null, 201);
checkSame('create: name taken', statusOf(callActn('users/create', Array('user'=>'Emma'))), Array(409, 'User name already taken'));
checkSame('create: invalid name', statusOf(callActn('users/create', Array('user'=>'<b>'))), Array(400, 'Invalid user name'));
checkSame('create: invalid role', statusOf(callActn('users/create', Array('user'=>'gina', 'role'=>'owner'))), Array(400, 'Invalid role'));
checkSame('create: no name', statusOf(callActn('users/create', Array('role'=>'member'))), Array(400, 'Missing or invalid input: user'));
checkSame('create: a name that is not a text', statusOf(callActn('users/create', Array('user'=>Array('emma')))), Array(400, 'Invalid user'));

// index and view
$outcome = callActn('users/index', Array(), 'anna', 'manage', 'GET');
checkSame('index: every user, by name', Array($outcome['code'] ?? $outcome, array_column($outcome['out'] ?? Array(), 'userName')), Array(200, Array('anna', 'ben', 'dave', 'emma', 'fred')));
checkSame('index: only what may be told', array_keys($outcome['out'][0] ?? Array()), Array('id_user', 'userName', 'userRole', 'userStat', 'madeDate', 'lastSeen'));
checkSame('index: by default action', callActn('users/', Array(), 'anna', 'manage', 'GET')['code'] ?? null, 200);
checkSame('index: filters', array_column(callActn('users/index', Array('stat'=>'pendng', 'role'=>'system'), 'anna', 'manage', 'GET')['out'] ?? Array(), 'userName'), Array('fred'));
checkSame('index: limit and offset, as sent in a query', array_column(callActn('users/index', Array('limit'=>'2', 'offset'=>'1'), 'anna', 'manage', 'GET')['out'] ?? Array(), 'userName'), Array('ben', 'dave'));
checkSame('index: nobody is 204', callActn('users/index', Array('stat'=>'locked', 'role'=>'manage'), 'anna', 'manage', 'GET')['code'] ?? null, 204);
checkSame('index: invalid filter', statusOf(callActn('users/index', Array('stat'=>'online'), 'anna', 'manage', 'GET')), Array(400, 'Invalid filter'));
$outcome = callActn('users/view', Array('user'=>'ben'), 'anna', 'manage', 'GET');
checkSame('view: one user', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('id_user'=>2, 'userName'=>'ben', 'userRole'=>'member', 'userStat'=>'active', 'madeDate'=>'2026-10-01 09:00:00', 'lastSeen'=>null)));
checkSame('view: unknown user', statusOf(callActn('users/view', Array('user'=>'zed'), 'anna', 'manage', 'GET')), Array(404, 'User not found'));
checkSame('view: no name', statusOf(callActn('users/view', Array(), 'anna', 'manage', 'GET')), Array(400, 'Missing or invalid input: user'));

// auth/activate : open to anyone holding the code
checkSame('activate: wrong code', statusOf(callActn('auth/activate', Array('user'=>'emma', 'code'=>str_repeat('0', 32), 'pass'=>'pass-emma-1'), '', '')), Array(401, 'Invalid activation'));
checkSame('activate: unknown user, same reply', statusOf(callActn('auth/activate', Array('user'=>'zed', 'code'=>$emmaCod, 'pass'=>'pass-zed-01'), '', '')), Array(401, 'Invalid activation'));
checkSame('activate: a password breaking a rule', statusOf(callActn('auth/activate', Array('user'=>'emma', 'code'=>$emmaCod, 'pass'=>'short'), '', '')), Array(400, 'Password too short: 10 characters at least'));
checkSame('activate: no password', statusOf(callActn('auth/activate', Array('user'=>'emma', 'code'=>$emmaCod), '', '')), Array(400, 'Missing or invalid input: pass'));
checkSame('activate: a GET is refused', statusOf(callActn('auth/activate', Array('user'=>'emma', 'code'=>$emmaCod, 'pass'=>'pass-emma-1'), '', '', 'GET')), Array(405, 'Method Not Allowed'));
$outcome = callActn('auth/activate', Array('user'=>'emma', 'code'=>$emmaCod, 'pass'=>'  <pass> "emma" & co  '), '', '');
checkSame('activate: the right code', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('active'=>true)));
checkSame('activate: the password is kept as sent', password_verify('  <pass> "emma" & co  ', strval($sqlLink->query("SELECT passHash FROM users WHERE userName='emma'")->fetchColumn())), true);
checkSame('activate: the code is used once', statusOf(callActn('auth/activate', Array('user'=>'emma', 'code'=>$emmaCod, 'pass'=>'pass-emma-2'), '', '')), Array(401, 'Invalid activation'));
plugConf('htpass');
checkSame('activate: a login on another credential check', statusOf(callActn('auth/activate', Array('user'=>'emma', 'code'=>$emmaCod, 'pass'=>'pass-emma-2'), '', '')), Array(500, 'User management unavailable'));

// auth/activate : wrong codes count against the address
plugConf('native', 2);
$fredInp = Array('user'=>'fred', 'code'=>str_repeat('0', 32), 'pass'=>'pass-fred-1');
checkSame('activate limit: a broken rule is not counted', Array(statusOf(callActn('auth/activate', Array('pass'=>'short') + $fredInp, '', '')), statusOf(callActn('auth/activate', Array('pass'=>'short') + $fredInp, '', '')), callActn('auth/activate', Array('pass'=>'short') + $fredInp, '', '')['code'] ?? null), Array(Array(400, 'Password too short: 10 characters at least'), Array(400, 'Password too short: 10 characters at least'), 400));
checkSame('activate limit: two wrong codes are answered 401', Array(callActn('auth/activate', $fredInp, '', '')['code'] ?? null, callActn('auth/activate', $fredInp, '', '')['code'] ?? null), Array(401, 401));
checkSame('activate limit: the address is then held', statusOf(callActn('auth/activate', $fredInp, '', '')), Array(429, 'Too many attempts'));
dropFoldr( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );

// auth/password : the logged user, for itself
checkSame('password: wrong current one', statusOf(callActn('auth/password', Array('former'=>'nope', 'pass'=>'pass-ben-22'), 'ben', 'member')), Array(401, 'Invalid credentials'));
checkSame('password: a wrong current one counts against the address', count(glob(TOP_DIR.DS.str_replace('/', DS, $lmtFldr).DS.'*.cnt') ?: Array()), 1);
dropFoldr( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
plugConf();
checkSame('password: nobody logged', statusOf(callActn('auth/password', Array('former'=>'pass-ben-1', 'pass'=>'pass-ben-22'), '', '')), Array(401, 'Not logged in'));
checkSame('password: a name sent is not read', Array(statusOf(callActn('auth/password', Array('user'=>'anna', 'former'=>'pass-anna-1', 'pass'=>'pass-anna-2'), 'ben', 'member')), password_verify('pass-anna-1', strval($sqlLink->query("SELECT passHash FROM users WHERE userName='anna'")->fetchColumn()))), Array(Array(401, 'Invalid credentials'), true));
checkSame('password: a new one breaking a rule', statusOf(callActn('auth/password', Array('former'=>'pass-ben-1', 'pass'=>'short'), 'ben', 'member')), Array(400, 'Password too short: 10 characters at least'));
checkSame('password: no new one', statusOf(callActn('auth/password', Array('former'=>'pass-ben-1'), 'ben', 'member')), Array(400, 'Missing or invalid input: pass'));
checkSame('password: a GET is refused', statusOf(callActn('auth/password', Array('former'=>'pass-ben-1', 'pass'=>'pass-ben-22'), 'ben', 'member', 'GET')), Array(405, 'Method Not Allowed'));
$sqlLink->exec("UPDATE users SET failNmbr=0, failDate=NULL");
$outcome = callActn('auth/password', Array('former'=>'pass-ben-1', 'pass'=>'pass-ben-22'), 'ben', 'member');
checkSame('password: changed', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('changed'=>true)));
checkSame('password: the new one is stored', password_verify('pass-ben-22', strval($sqlLink->query("SELECT passHash FROM users WHERE userName='ben'")->fetchColumn())), true);

// lock, unlock, role, remove, reset
$outcome = callActn('users/lock', Array('user'=>'ben'));
checkSame('lock: locked', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'ben', 'stat'=>'locked')));
checkSame('lock: already locked', statusOf(callActn('users/lock', Array('user'=>'ben'))), Array(409, 'User already locked'));
checkSame('lock: unknown user', statusOf(callActn('users/lock', Array('user'=>'zed'))), Array(404, 'User not found'));
checkSame('lock: a manager cannot lock itself', statusOf(callActn('users/lock', Array('user'=>'anna'))), Array(409, 'A manager cannot do this to its own account'));
$outcome = callActn('users/unlock', Array('user'=>'ben'));
checkSame('unlock: active again', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'ben', 'stat'=>'active')));
checkSame('unlock: a pending user', statusOf(callActn('users/unlock', Array('user'=>'fred'))), Array(409, 'A pending user is neither locked nor unlocked'));

$outcome = callActn('users/role', Array('user'=>'ben', 'role'=>'manage'));
checkSame('role: changed', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'ben', 'role'=>'manage')));
checkSame('role: invalid role', statusOf(callActn('users/role', Array('user'=>'ben', 'role'=>'owner'))), Array(400, 'Invalid role'));
checkSame('role: no role', statusOf(callActn('users/role', Array('user'=>'ben'))), Array(400, 'Missing or invalid input: role'));
checkSame('role: a manager cannot change its own, even with another manager beside it', statusOf(callActn('users/role', Array('user'=>'anna', 'role'=>'member'))), Array(409, 'A manager cannot do this to its own account'));
checkSame('role: another manager can', callActn('users/role', Array('user'=>'anna', 'role'=>'system'), 'ben', 'manage')['code'] ?? null, 200);
checkSame('role: and is then the last one', statusOf(callActn('users/role', Array('user'=>'ben', 'role'=>'member'), 'emma', 'manage')), Array(409, 'The last active manager cannot be given another role'));
callActn('users/role', Array('user'=>'anna', 'role'=>'manage'), 'ben', 'manage');
callActn('users/role', Array('user'=>'ben', 'role'=>'member'));

$outcome = callActn('users/reset', Array('user'=>'ben'));
checkSame('reset: 200 with the name', Array($outcome['code'] ?? $outcome, $outcome['out']['user'] ?? null), Array(200, 'ben'));
checkSame('reset: the new code activates the user', callActn('auth/activate', Array('user'=>'ben', 'code'=>strval($outcome['out']['code'] ?? ''), 'pass'=>'pass-ben-33'), '', '')['code'] ?? null, 200);
checkSame('reset: a locked user', statusOf(callActn('users/reset', Array('user'=>'dave'))), Array(409, 'A locked user is unlocked before it is reset'));
checkSame('reset: a manager cannot reset itself', statusOf(callActn('users/reset', Array('user'=>'anna'))), Array(409, 'A manager cannot do this to its own account'));

$outcome = callActn('users/remove', Array('user'=>'fred'));
checkSame('remove: removed', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'fred', 'removed'=>true)));
checkSame('remove: already gone', statusOf(callActn('users/remove', Array('user'=>'fred'))), Array(404, 'User not found'));
checkSame('remove: a manager cannot remove itself', statusOf(callActn('users/remove', Array('user'=>'anna'))), Array(409, 'A manager cannot do this to its own account'));
checkSame('remove: who is left', array_column(callActn('users/index', Array(), 'anna', 'manage', 'GET')['out'] ?? Array(), 'userName'), Array('anna', 'ben', 'dave', 'emma'));

// What the log keeps : every change, signed, and no password or code
$logText = strval(@file_get_contents(inTempDir('logs').DS.'access.log'));
foreach( Array('User "emma" created \(member\) by user "anna" from 192\.0\.2\.50', 'User "emma" activated from 192\.0\.2\.50', 'Password changed for user "ben" by user "ben"', 'User "ben" locked by user "anna"', 'User "ben" unlocked by user "anna"', 'Role of user "ben" changed from member to manage by user "anna"', 'User "ben" reset by user "anna"', 'User "fred" removed \(system\) by user "anna"') as $logLne ){
	checkLike('log: '.stripslashes($logLne), $logText, '/'.$logLne.'/');
}
checkSame('log: no password and no code', Array(preg_match('/pass-|<pass>/', $logText), strpos($logText, $emmaCod)), Array(0, false));

Logger::plugSetup( Array() );
Logger::plugActor( '', '' );
dropFoldr( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
$sqlLink = null;

endChecks();
