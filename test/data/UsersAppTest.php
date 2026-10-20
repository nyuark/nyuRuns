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
// The mails of a 'tofile' account : in a folder of its own too
$mailDir = 'temp/tstmail_'.getmypid();

/**
 * Plugs the configuration the actions read.
 *
 * @param String $authbyName Credential check of the login block.
 * @param Int    $triesLimit Wrong credentials allowed from one address, 0 for no limit.
 * @param Array  $nativeMore Keys added to the block of the native userbase.
 * @param Array  $limitsMore Keys added to the limits block.
 */
function plugConf( String $authbyName='native', Int $triesLimit=0, Array $nativeMore=Array(), Array $limitsMore=Array() ): Void {
	$GLOBALS['nyuConfig'] = new Registry( Array(
		'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>inTempDir('usersapp.sqlite')),
		'access'=>Array(
			'LOGIN'=>Array('authby'=>$authbyName, 'authcf'=>'native'),
			'NATIVE'=>Array('tblnme'=>'users') + $nativeMore,
			'LIMITS'=>Array('maxtry'=>$triesLimit, 'holdtm'=>600, 'folder'=>$GLOBALS['lmtFldr']) + $limitsMore
		),
		'mailer'=>Array('DEVBOX'=>Array('type'=>'tofile', 'from'=>'no-reply@example.com', 'fold'=>$GLOBALS['mailDir']))
	) );
	nyu\Data\Mail\Mailer::dropCache();
}

/**
 * Reads the mails written since the previous call, and removes them.
 *
 * @return Array Each mail, its encodings undone, with the address it went to ('to') and the code it holds ('code').
 */
function sentMail(): Array {
	$mailLst = Array();
	$fleList = glob(TOP_DIR.DS.str_replace('/', DS, $GLOBALS['mailDir']).DS.'*.eml') ?: Array();
	foreach( $fleList as $fleNme ){
		$rawText = strval(file_get_contents($fleNme));
		unlink($fleNme);
		$fullTxt = preg_replace_callback('/Content-Transfer-Encoding: (base64|quoted-printable)\r\n\r\n(.*?)(?=\r\n--|$)/s', function( Array $matchedArr ): String {
			return ($matchedArr[1]=='base64') ? base64_decode(str_replace("\r\n", '', $matchedArr[2])) : quoted_printable_decode($matchedArr[2]);
		}, $rawText);
		$mailLst[] = Array(
			'to'=>preg_match('/\r\nTo: (\S+)\r\n/', $rawText, $toMatch) ? $toMatch[1] : '',
			'subject'=>preg_match('/\r\nSubject: ([^\r\n]+)\r\n/', $rawText, $sbMatch) ? $sbMatch[1] : '',
			'code'=>preg_match('/\b([A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2})\b/', $fullTxt, $cdMatch) ? $cdMatch[1] : '',
			'text'=>$fullTxt
		);
	}
	return $mailLst;
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
		$request = fakeReqst( Array(), Array('REMOTE_ADDR'=>'192.0.2.50', 'HTTP_ACCEPT_LANGUAGE'=>strval($GLOBALS['acptLang'] ?? '')) );
		$mthProp = new ReflectionProperty('nyu\Tools\Request', 'inMethod');
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
foreach( Array('index'=>'GET', 'view'=>'GET', 'create'=>'POST', 'lock'=>'POST', 'unlock'=>'POST', 'role'=>'POST', 'remove'=>'POST', 'reset'=>'POST', 'approve'=>'POST', 'email'=>'POST') as $actNme=>$mthNme ){
	$inptArr = Array('user'=>'ben', 'role'=>'system');
	checkSame('guard: users/'.$actNme.' refused to a member', statusOf(callActn('users/'.$actNme, $inptArr, 'ben', 'member', $mthNme)), Array(404, 'Not Found'));
	checkSame('guard: users/'.$actNme.' refused to the system role', statusOf(callActn('users/'.$actNme, $inptArr, 'dave', 'system', $mthNme)), Array(404, 'Not Found'));
	checkSame('guard: users/'.$actNme.' refused when nobody is logged', statusOf(callActn('users/'.$actNme, $inptArr, '', '', $mthNme)), Array(404, 'Not Found'));
	checkSame('guard: users/'.$actNme.' answers to '.$mthNme.' only', statusOf(callActn('users/'.$actNme, $inptArr, 'anna', 'manage', ($mthNme=='GET') ? 'POST' : 'GET')), Array(405, 'Method Not Allowed'));
}
checkSame('guard: a refusal changed nothing', $sqlLink->query("SELECT userName, userRole, userStat FROM users ORDER BY id_user")->fetchAll(PDO::FETCH_NUM), Array(Array('anna', 'manage', 'active'), Array('ben', 'member', 'active'), Array('dave', 'system', 'locked')));
checkLike('guard: a refusal is logged as a warning, with the role needed', strval(@file_get_contents(inTempDir('logs').DS.'access.log')), '/\[WARNING\] Access refused for user "ben" \(member\) from 192\.0\.2\.50 to users\/index: role manage needed/');
checkSame('guard: the helpers are not actions', Array(statusOf(callActn('users/startCall', Array(), 'anna', 'manage')), statusOf(callActn('users/givenUser', Array(), 'anna', 'manage'))), Array(Array(404, 'Not Found'), Array(404, 'Not Found')));
plugConf('htpass');
checkSame('guard: a login on another credential check', statusOf(callActn('users/index', Array(), 'anna', 'manage', 'GET')), Array(500, 'User management unavailable'));
plugConf();

// create
$outcome = callActn('users/create', Array('user'=>'emma'));
$emmaCod = strval($outcome['out']['code'] ?? '');
checkSame('create: 201 with the name', Array($outcome['code'] ?? $outcome, $outcome['out']['user'] ?? null), Array(201, 'emma'));
checkLike('create: the code is given', $emmaCod, '/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D');
checkSame('create: with a role', callActn('users/create', Array('user'=>'fred', 'role'=>'system'))['code'] ?? null, 201);
checkSame('create: name taken', statusOf(callActn('users/create', Array('user'=>'Emma'))), Array(409, 'User name already taken'));
checkSame('create: invalid name', statusOf(callActn('users/create', Array('user'=>'<b>'))), Array(400, 'Invalid user name'));
checkSame('create: invalid role', statusOf(callActn('users/create', Array('user'=>'gina', 'role'=>'owner'))), Array(400, 'Invalid role'));
checkSame('create: no name', statusOf(callActn('users/create', Array('role'=>'member'))), Array(400, 'Missing or invalid input: user'));
checkSame('create: a name that is not a text', statusOf(callActn('users/create', Array('user'=>Array('emma')))), Array(400, 'Invalid user'));

// index and view
$outcome = callActn('users/index', Array(), 'anna', 'manage', 'GET');
checkSame('index: every user, by name', Array($outcome['code'] ?? $outcome, array_column($outcome['out'] ?? Array(), 'userName')), Array(200, Array('anna', 'ben', 'dave', 'emma', 'fred')));
checkSame('index: only what may be told', array_keys($outcome['out'][0] ?? Array()), Array('id_user', 'userName', 'userRole', 'userStat', 'userMail', 'userLang', 'signDate', 'madeDate', 'lastSeen'));
checkSame('index: by default action', callActn('users/', Array(), 'anna', 'manage', 'GET')['code'] ?? null, 200);
checkSame('index: filters', array_column(callActn('users/index', Array('stat'=>'pendng', 'role'=>'system'), 'anna', 'manage', 'GET')['out'] ?? Array(), 'userName'), Array('fred'));
checkSame('index: limit and offset, as sent in a query', array_column(callActn('users/index', Array('limit'=>'2', 'offset'=>'1'), 'anna', 'manage', 'GET')['out'] ?? Array(), 'userName'), Array('ben', 'dave'));
checkSame('index: nobody is 204', callActn('users/index', Array('stat'=>'locked', 'role'=>'manage'), 'anna', 'manage', 'GET')['code'] ?? null, 204);
checkSame('index: invalid filter', statusOf(callActn('users/index', Array('stat'=>'online'), 'anna', 'manage', 'GET')), Array(400, 'Invalid filter'));
$outcome = callActn('users/view', Array('user'=>'ben'), 'anna', 'manage', 'GET');
checkSame('view: one user', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('id_user'=>2, 'userName'=>'ben', 'userRole'=>'member', 'userStat'=>'active', 'userMail'=>null, 'userLang'=>null, 'signDate'=>null, 'madeDate'=>'2026-10-01 09:00:00', 'lastSeen'=>null)));
checkSame('view: unknown user', statusOf(callActn('users/view', Array('user'=>'zed'), 'anna', 'manage', 'GET')), Array(404, 'User not found'));
checkSame('view: no name', statusOf(callActn('users/view', Array(), 'anna', 'manage', 'GET')), Array(400, 'Missing or invalid input: user'));

// auth/activate : open to anyone holding the code
checkSame('activate: wrong code', statusOf(callActn('auth/activate', Array('user'=>'emma', 'code'=>str_repeat('0', 32), 'pass'=>'pass-emma-1'), '', '')), Array(401, 'Invalid activation'));
checkSame('activate: unknown user, same reply', statusOf(callActn('auth/activate', Array('user'=>'zed', 'code'=>$emmaCod, 'pass'=>'pass-zed-01'), '', '')), Array(401, 'Invalid activation'));
checkSame('activate: a password breaking a rule', statusOf(callActn('auth/activate', Array('user'=>'emma', 'code'=>$emmaCod, 'pass'=>'short'), '', '')), Array(400, 'Password too short: 10 characters at least'));
checkSame('activate: no password', statusOf(callActn('auth/activate', Array('user'=>'emma', 'code'=>$emmaCod), '', '')), Array(400, 'Missing or invalid input: pass'));
checkSame('activate: a GET is refused', statusOf(callActn('auth/activate', Array('user'=>'emma', 'code'=>$emmaCod, 'pass'=>'pass-emma-1'), '', '', 'GET')), Array(405, 'Method Not Allowed'));
$outcome = callActn('auth/activate', Array('user'=>'emma', 'code'=>$emmaCod, 'pass'=>'  <pass> "emma" & co  '), '', '');
checkSame('activate: the right code', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('active'=>true, 'stat'=>'active')));
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

// Access mails : without a mailer, the codes are given in the replies, as above
$mailCnf = Array('mailer'=>'devbox', 'lnkurl'=>'https://app.example.ch/a?do=%kind%&user=%user%&code=%code%');
plugConf('native', 0, $mailCnf);
sentMail();
$outcome = callActn('users/create', Array('user'=>'hana', 'mail'=>'Hana@Example.ch'));
$mailLst = sentMail();
checkSame('create, mailed: 201, the code not in the reply', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(201, Array('user'=>'hana', 'mailed'=>true)));
checkSame('create, mailed: the code goes to the address, with its link', Array(count($mailLst), $mailLst[0]['to'] ?? null, $mailLst[0]['subject'] ?? null, strpos($mailLst[0]['text'] ?? '', 'https://app.example.ch/a?do=actvte&user=hana&code='.($mailLst[0]['code'] ?? '-'))!==false), Array(1, 'hana@example.ch', 'Your account: choose your password', true));
checkSame('create, mailed: the code lasts the minutes of a mailed code', abs(strtotime(strval($sqlLink->query("SELECT codeDate FROM users WHERE userName='hana'")->fetchColumn())) - (time()+15*60))<=5, true);
$outcome = callActn('auth/activate', Array('user'=>'hana', 'code'=>strval($mailLst[0]['code'] ?? ''), 'pass'=>'pass-hana-1'), '', '');
checkSame('create, mailed: the code activates', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('active'=>true, 'stat'=>'active')));
checkSame('create: an address taken', statusOf(callActn('users/create', Array('user'=>'hugo', 'mail'=>'HANA@example.ch'))), Array(409, 'Mail address already used'));
$outcome = callActn('users/create', Array('user'=>'hugo'));
checkSame('create without an address: the code in the reply', Array($outcome['code'] ?? $outcome, isset($outcome['out']['code']), sentMail()), Array(201, true, Array()));
$outcome = callActn('users/email', Array('user'=>'ben', 'mail'=>' Ben@Example.ch '));
checkSame('email: an address given, in lower case', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'ben', 'mail'=>'ben@example.ch')));
checkSame('email: an address taken, one that is not', Array(statusOf(callActn('users/email', Array('user'=>'hugo', 'mail'=>'ben@example.ch'))), statusOf(callActn('users/email', Array('user'=>'hugo', 'mail'=>'hugo')))), Array(Array(409, 'Mail address already used'), Array(400, 'Invalid mail address')));
checkSame('email: taken away', Array(callActn('users/email', Array('user'=>'hugo', 'mail'=>'hugo@example.ch'))['code'] ?? null, callActn('users/email', Array('user'=>'hugo'))['out'] ?? null), Array(200, Array('user'=>'hugo', 'mail'=>null)));

// users/langs and auth/langs : the language of the mails, given by a manager or by the user itself
$outcome = callActn('users/langs', Array('user'=>'hugo', 'lang'=>'FR_ch'));
checkSame('langs: a manager gives one, in lower case', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'hugo', 'lang'=>'fr-ch')));
checkSame('langs: one that is not, then taken away', Array(statusOf(callActn('users/langs', Array('user'=>'hugo', 'lang'=>'french'))), callActn('users/langs', Array('user'=>'hugo'))['out'] ?? null), Array(Array(400, 'Invalid language'), Array('user'=>'hugo', 'lang'=>null)));
checkSame('langs: a member is not a manager', statusOf(callActn('users/langs', Array('user'=>'hugo', 'lang'=>'fr'), 'ben', 'member')), Array(404, 'Not Found'));
$outcome = callActn('auth/langs', Array('user'=>'hugo', 'lang'=>'de'), 'ben', 'member');
checkSame('auth/langs: the logged user, for itself; a name sent is not read', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null, $sqlLink->query("SELECT userName, userLang FROM users WHERE userName IN ('ben', 'hugo') ORDER BY userName")->fetchAll(PDO::FETCH_KEY_PAIR)), Array(200, Array('lang'=>'de'), Array('ben'=>'de', 'hugo'=>null)));
checkSame('auth/langs: nobody logged, a GET, one that is not', Array(statusOf(callActn('auth/langs', Array('lang'=>'de'), '', '')), statusOf(callActn('auth/langs', Array('lang'=>'de'), 'ben', 'member', 'GET')), statusOf(callActn('auth/langs', Array('lang'=>'*'), 'ben', 'member'))), Array(Array(401, 'Not logged in'), Array(405, 'Method Not Allowed'), Array(400, 'Invalid language')));
callActn('auth/langs', Array(), 'ben', 'member');
$outcome = callActn('users/create', Array('user'=>'xena', 'lang'=>'it'));
checkSame('create: with the language of its mails', Array($outcome['code'] ?? $outcome, $sqlLink->query("SELECT userLang FROM users WHERE userName='xena'")->fetchColumn(), statusOf(callActn('users/create', Array('user'=>'xavi', 'lang'=>'italian')))), Array(201, 'it', Array(400, 'Invalid language')));
$outcome = callActn('users/reset', Array('user'=>'ben'));
$mailLst = sentMail();
checkSame('reset, mailed: the code goes to the address of the user', Array($outcome['out'] ?? null, $mailLst[0]['to'] ?? null), Array(Array('user'=>'ben', 'mailed'=>true), 'ben@example.ch'));
checkSame('reset, mailed: the code activates', callActn('auth/activate', Array('user'=>'ben', 'code'=>strval($mailLst[0]['code'] ?? ''), 'pass'=>'pass-ben-44'), '', '')['code'] ?? null, 200);
checkSame('reset of a user without an address: the code in the reply', isset(callActn('users/reset', Array('user'=>'hugo'))['out']['code']), true);

// auth/signup : open when the block says so, a manager approves
checkSame('signup: closed unless the block opens it', statusOf(callActn('auth/signup', Array('user'=>'ivan', 'mail'=>'ivan@example.ch'), '', '')), Array(404, 'Not Found'));
plugConf('native', 0, Array('signup'=>true));
checkSame('signup: closed without a mailer', statusOf(callActn('auth/signup', Array('user'=>'ivan', 'mail'=>'ivan@example.ch'), '', '')), Array(404, 'Not Found'));
plugConf('native', 0, $mailCnf + Array('signup'=>true));
checkSame('signup: a GET is refused', statusOf(callActn('auth/signup', Array('user'=>'ivan', 'mail'=>'ivan@example.ch'), '', '', 'GET')), Array(405, 'Method Not Allowed'));
$outcome = callActn('auth/signup', Array('user'=>'ivan', 'mail'=>'Ivan@Example.ch'), '', '');
$mailLst = sentMail();
checkSame('signup: 202', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(202, Array('asked'=>true)));
checkSame('signup: the code goes to the address', Array($mailLst[0]['to'] ?? null, $mailLst[0]['subject'] ?? null, strpos($mailLst[0]['text'] ?? '', 'do=signup&user=ivan&code=')!==false), Array('ivan@example.ch', 'Your sign-up: confirm your address', true));
$outcome = callActn('auth/signup', Array('user'=>'Ivan', 'mail'=>'other@example.ch'), '', '');
$takenMl = sentMail();
checkSame('signup: a name taken gets the same reply, and the address a notice', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null, $takenMl[0]['to'] ?? null, $takenMl[0]['subject'] ?? null, $takenMl[0]['code'] ?? null), Array(202, Array('asked'=>true), 'other@example.ch', 'Your sign-up', ''));
checkSame('signup: an address taken too', Array(callActn('auth/signup', Array('user'=>'jack', 'mail'=>'ben@example.ch'), '', '')['code'] ?? null, sentMail()[0]['to'] ?? null), Array(202, 'ben@example.ch'));
checkSame('signup: a name or an address that is not one', Array(statusOf(callActn('auth/signup', Array('user'=>'a b', 'mail'=>'jack@example.ch'), '', '')), statusOf(callActn('auth/signup', Array('user'=>'jack', 'mail'=>'jack'), '', '')), statusOf(callActn('auth/signup', Array('user'=>'jack'), '', ''))), Array(Array(400, 'Invalid user name'), Array(400, 'Invalid mail address'), Array(400, 'Missing or invalid input: mail')));
$outcome = callActn('auth/activate', Array('user'=>'ivan', 'code'=>strval($mailLst[0]['code'] ?? ''), 'pass'=>'pass-ivan-1'), '', '');
checkSame('signup: confirmed, it awaits review', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('active'=>false, 'stat'=>'review')));
checkSame('signup: listed by its status', array_column(callActn('users/index', Array('stat'=>'review'), 'anna', 'manage', 'GET')['out'] ?? Array(), 'userName'), Array('ivan'));
$outcome = callActn('users/approve', Array('user'=>'ivan'));
checkSame('approve: active', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'ivan', 'stat'=>'active')));
checkSame('approve: only a user in review', statusOf(callActn('users/approve', Array('user'=>'ivan'))), Array(409, 'User not awaiting review'));
// The language of the mails : the one of the request for the one who asks, the one of the owner for an address taken
$GLOBALS['acptLang'] = 'fr-CH, fr;q=0.9';
sentMail();
callActn('auth/signup', Array('user'=>'yann', 'mail'=>'yann@example.ch'), '', '');
checkSame('language: a sign-up is written in the language of its request, which it keeps', Array(sentMail()[0]['subject'] ?? null, $sqlLink->query("SELECT userLang FROM users WHERE userName='yann'")->fetchColumn()), Array('Votre inscription : confirmez votre adresse', 'fr-ch'));
$sqlLink->exec("UPDATE users SET userLang='en' WHERE userName='ben'");
callActn('auth/signup', Array('user'=>'zoe', 'mail'=>'ben@example.ch'), '', '');
checkSame('language: an address taken is told in the language of its owner', sentMail()[0]['subject'] ?? null, 'Your sign-up');
callActn('auth/signup', Array('user'=>'ivan', 'mail'=>'nobody@example.ch'), '', '');
checkSame('language: a name taken, in the language of the request', sentMail()[0]['subject'] ?? null, 'Votre inscription');
$GLOBALS['acptLang'] = '';
dropFoldr( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
plugConf('native', 0, $mailCnf + Array('signup'=>true), Array('regmax'=>2));
checkSame('signup: regmax sign-ups from one address', Array(callActn('auth/signup', Array('user'=>'kate', 'mail'=>'kate@example.ch'), '', '')['code'] ?? null, callActn('auth/signup', Array('user'=>'lena', 'mail'=>'lena@example.ch'), '', '')['code'] ?? null, statusOf(callActn('auth/signup', Array('user'=>'mona', 'mail'=>'mona@example.ch'), '', ''))), Array(202, 202, Array(429, 'Too many attempts')));
checkSame('signup: counted apart from the wrong credentials', Array(is_dir(TOP_DIR.DS.str_replace('/', DS, $lmtFldr).DS.'signup'), count(glob(TOP_DIR.DS.str_replace('/', DS, $lmtFldr).DS.'*.cnt') ?: Array())), Array(true, 0));
dropFoldr( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
plugConf('native', 1, $mailCnf + Array('signup'=>true));
callActn('auth/recovr', Array('user'=>'ben', 'code'=>'AAAA-BBBB-CC', 'pass'=>'pass-ben-66'), '', '');
checkSame('signup: an address held for wrong credentials is refused', statusOf(callActn('auth/signup', Array('user'=>'mona', 'mail'=>'mona@example.ch'), '', '')), Array(429, 'Too many attempts'));
dropFoldr( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
sentMail();
$sqlLink->exec("UPDATE users SET userMail='anna@example.ch' WHERE userName='anna'");
$waitNow = intval($sqlLink->query("SELECT COUNT(*) FROM users WHERE signDate IS NOT NULL AND userStat IN ('pendng', 'review')")->fetchColumn());
plugConf('native', 0, $mailCnf + Array('signup'=>true), Array('regtop'=>$waitNow+2, 'regwrn'=>Array($waitNow+1, $waitNow+2)));
callActn('auth/signup', Array('user'=>'mona', 'mail'=>'mona@example.ch'), '', '');
$mailLst = array_values(array_filter(sentMail(), function( Array $mailValue ): Bool { return $mailValue['to']=='anna@example.ch'; }));
checkSame('signup: the managers are told at a count of regwrn', Array(count($mailLst), $mailLst[0]['subject'] ?? null, strpos($mailLst[0]['text'] ?? '', 'When '.($waitNow+2).' are waiting')!==false), Array(1, 'Sign-ups waiting for review: '.($waitNow+1), true));
callActn('auth/signup', Array('user'=>'nora', 'mail'=>'nora@example.ch'), '', '');
checkSame('signup: and at regtop, then closed', Array(count(array_filter(sentMail(), function( Array $mailValue ): Bool { return $mailValue['to']=='anna@example.ch'; })), statusOf(callActn('auth/signup', Array('user'=>'olga', 'mail'=>'olga@example.ch'), '', ''))), Array(1, Array(503, 'Sign-ups closed for now')));
checkLike('signup: the counts are logged', strval(@file_get_contents(inTempDir('logs').DS.'access.log')), '/\[NOTICE\] '.($waitNow+1).' sign-ups are waiting, '.($waitNow+2).' at most.*\[WARNING\] '.($waitNow+2).' sign-ups are waiting: the sign-ups are closed/s');
dropFoldr( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
sentMail();

// auth/forgot and auth/recovr : a reset asked for by the user, by mail
plugConf('native', 0, $mailCnf);
checkSame('forgot: a GET is refused', statusOf(callActn('auth/forgot', Array('user'=>'ben'), '', '', 'GET')), Array(405, 'Method Not Allowed'));
$outcome = callActn('auth/forgot', Array('user'=>'ben'), '', '');
$mailLst = sentMail();
checkSame('forgot: 202, the code goes to the address', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null, $mailLst[0]['to'] ?? null, $mailLst[0]['subject'] ?? null, strpos($mailLst[0]['text'] ?? '', 'do=pwdset&user=ben&code=')!==false), Array(202, Array('asked'=>true), 'ben@example.ch', 'Your password: choose a new one', true));
checkSame('forgot: unknown or without an address, the same reply and no mail', Array(callActn('auth/forgot', Array('user'=>'zed'), '', '')['code'] ?? null, callActn('auth/forgot', Array('user'=>'nobody@example.ch'), '', '')['code'] ?? null, callActn('auth/forgot', Array('user'=>'hugo'), '', '')['code'] ?? null, sentMail()), Array(202, 202, 202, Array()));
checkSame('forgot: the password still works', password_verify('pass-ben-44', strval($sqlLink->query("SELECT passHash FROM users WHERE userName='ben'")->fetchColumn())), true);
checkSame('recovr: a wrong code', statusOf(callActn('auth/recovr', Array('user'=>'ben', 'code'=>'AAAA-BBBB-CC', 'pass'=>'pass-ben-55'), '', '')), Array(401, 'Invalid code'));
checkSame('recovr: a password breaking a rule, and none', Array(statusOf(callActn('auth/recovr', Array('user'=>'ben', 'code'=>strval($mailLst[0]['code'] ?? ''), 'pass'=>'short'), '', '')), statusOf(callActn('auth/recovr', Array('user'=>'ben', 'code'=>strval($mailLst[0]['code'] ?? '')), '', ''))), Array(Array(400, 'Password too short: 10 characters at least'), Array(400, 'Missing or invalid input: pass')));
$outcome = callActn('auth/recovr', Array('user'=>'ben', 'code'=>strval($mailLst[0]['code'] ?? ''), 'pass'=>'pass-ben-55'), '', '');
checkSame('recovr: the new password', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null, password_verify('pass-ben-55', strval($sqlLink->query("SELECT passHash FROM users WHERE userName='ben'")->fetchColumn()))), Array(200, Array('changed'=>true), true));
checkSame('recovr: the code is used once', statusOf(callActn('auth/recovr', Array('user'=>'ben', 'code'=>strval($mailLst[0]['code'] ?? ''), 'pass'=>'pass-ben-66'), '', '')), Array(401, 'Invalid code'));
plugConf('native', 1, $mailCnf);
checkSame('recovr: wrong codes count against the address', Array(callActn('auth/recovr', Array('user'=>'ben', 'code'=>'AAAA-BBBB-CC', 'pass'=>'pass-ben-66'), '', '')['code'] ?? null, statusOf(callActn('auth/recovr', Array('user'=>'ben', 'code'=>'AAAA-BBBB-CC', 'pass'=>'pass-ben-66'), '', ''))), Array(401, Array(429, 'Too many attempts')));
dropFoldr( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
plugConf();
checkSame('forgot: no mail account, no reset by mail', statusOf(callActn('auth/forgot', Array('user'=>'ben'), '', '')), Array(404, 'Not Found'));
dropFoldr( TOP_DIR.DS.str_replace('/', DS, $mailDir) );

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
