<?php

// runs/Users and the activate / password actions of runs/Auth : the users
// of the native userbase managed through the framework's Controller, so
// that the input definitions apply, against a SQLite table built from the
// packaged schema (conf/schema/users.sqlitedb.sql).
//
//   user 1  anna   manage  active
//   user 2  ben    member  active
//   user 3  dave   system  locked
//
// Passwords are pass-<name>-1.

require __DIR__.'/../lib/harness.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Users app', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\App\Controller;
use nyu\Tools\Logger;
use nyu\Tools\Registry;

$sqlLink = new PDO('sqlite:'.inTemporaryDir('usersapp.sqlite'));
$sqlLink->exec( strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlitedb.sql')) );
$usrStmt = $sqlLink->prepare("INSERT INTO users (id_user, systemName, passwdHash, accessRole, userStatus, createDate) VALUES (?,?,?,?,?,'2026-10-01 09:00:00')");
foreach( Array(Array(1,'anna','managers','approved'), Array(2,'ben','standard','approved'), Array(3,'dave','advanced','disabled')) as $usrRow ){
	$usrStmt->execute( Array($usrRow[0], $usrRow[1], password_hash('pass-'.$usrRow[1].'-1', PASSWORD_BCRYPT, Array('cost'=>4)), $usrRow[2], $usrRow[3]) );
}
$usrStmt = null;

// The counters of the address limit lie under the project root : in a folder of this script's own
$lmtFldr = 'temp/tstlim_'.getmypid();
// The mails of a 'emlFiles' account : in a folder of its own too
$mailDir = 'temp/tstmail_'.getmypid();

/**
 * Plugs the configuration the actions read.
 *
 * @param String $authbyName Credential check of the login block.
 * @param Int    $triesLimit Wrong credentials allowed from one address, 0 for no limit.
 * @param Array  $nativeMore Keys added to the block of the native userbase.
 * @param Array  $limitsMore Keys added to the limits block.
 */
function plugConf( String $authbyName='nativeDb', Int $triesLimit=0, Array $nativeMore=Array(), Array $limitsMore=Array() ): Void {
	$GLOBALS['nyuConfig'] = new Registry( Array(
		'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>inTemporaryDir('usersapp.sqlite')),
		'access'=>Array(
			'LOGINSET'=>Array('authWith'=>$authbyName, 'authConf'=>'nativeDb'),
			'NATIVEDB'=>Array('useTable'=>'users') + $nativeMore,
			'TRYLIMIT'=>Array('maxTries'=>$triesLimit, 'holdSecs'=>600, 'dataPath'=>$GLOBALS['lmtFldr']) + $limitsMore
		),
		'mailer'=>Array('DEVBOX'=>Array('sendWith'=>'emlFiles', 'fromAddr'=>'no-reply@example.com', 'mailsDir'=>$GLOBALS['mailDir']))
	) );
	nyu\Data\Mail\Mailer::clearMailCache();
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
function callActn( String $routeNamed, Array $inputArray=Array(), String $loggedUser='anna', String $loggedRole='managers', String $httpMethod='POST' ): Mixed {
	return runCatchThrown(function() use ($routeNamed, $inputArray, $loggedUser, $loggedRole, $httpMethod){
		$request = mockRequestObj( Array(), Array('REMOTE_ADDR'=>'192.0.2.50', 'HTTP_ACCEPT_LANGUAGE'=>strval($GLOBALS['acptLang'] ?? '')) );
		$mthProp = new ReflectionProperty('nyu\Tools\Request', 'rqstInMthd');
		$mthProp->setValue($request, $httpMethod);
		$axssReg = new Registry( ($loggedUser!='')
			? Array('loggedIn'=>true, 'userName'=>$loggedUser, 'userRole'=>$loggedRole, 'statCode'=>200, 'statMssg'=>'')
			: Array('loggedIn'=>false, 'userName'=>'', 'userRole'=>null, 'statCode'=>200, 'statMssg'=>'') );
		Logger::setCurrentUser( $loggedUser, '192.0.2.50' );
		$rteName = explode('/', $routeNamed);
		// The user the path names (users/<action>/<user>) is the option of the action, as a route gives it
		$control = new Controller( $rteName[0], $rteName[1], new Registry($inputArray), $rteName[2] ?? null, $axssReg, $request );
		$statArr = $control->retrieveStatus();
		return Array('code'=>$statArr['code'], 'mssg'=>$statArr['mssg'], 'out'=>$control->retrieveOutput());
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

Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs')) );
plugConf();

// Who may call : a logged user of the highest role, and nobody else
foreach( Array('index', 'view', 'create', 'lock', 'unlock', 'role', 'remove', 'reset', 'approve', 'setmail') as $actNme ){
	$inptArr = Array('userName'=>'ben', 'saveRole'=>'advanced');
	checkIdentical('guard: users/'.$actNme.' refused to a member', statusOf(callActn('users/'.$actNme.'/ben', $inptArr, 'ben', 'standard')), Array(404, 'Not Found'));
	checkIdentical('guard: users/'.$actNme.' refused to the system role', statusOf(callActn('users/'.$actNme.'/ben', $inptArr, 'dave', 'advanced')), Array(404, 'Not Found'));
	checkIdentical('guard: users/'.$actNme.' refused when nobody is logged', statusOf(callActn('users/'.$actNme.'/ben', $inptArr, '', '')), Array(404, 'Not Found'));
}
checkIdentical('guard: a refusal changed nothing', $sqlLink->query("SELECT systemName, accessRole, userStatus FROM users ORDER BY id_user")->fetchAll(PDO::FETCH_NUM), Array(Array('anna', 'managers', 'approved'), Array('ben', 'standard', 'approved'), Array('dave', 'advanced', 'disabled')));
checkPregMatch('guard: a refusal is logged as a warning, with the role needed', strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log')), '/\[WARNING\] Access refused for user "ben" \(standard\) from 192\.0\.2\.50 to users\/index: role managers needed/');
checkIdentical('guard: the helpers are not actions', Array(statusOf(callActn('users/setupForAction', Array(), 'anna', 'managers')), statusOf(callActn('users/givenUsrString', Array(), 'anna', 'managers'))), Array(Array(404, 'Not Found'), Array(404, 'Not Found')));
plugConf('htpasswd');
checkIdentical('guard: a login on another credential check', statusOf(callActn('users/index', Array(), 'anna', 'managers', 'GET')), Array(500, 'User management unavailable'));
plugConf();

// create
$outcome = callActn('users/create', Array('userName'=>'emma'));
$emmaCod = strval($outcome['out']['code'] ?? '');
checkIdentical('create: 201 with the name', Array($outcome['code'] ?? $outcome, $outcome['out']['user'] ?? null), Array(201, 'emma'));
checkPregMatch('create: the code is given', $emmaCod, '/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D');
checkIdentical('create: with a role', callActn('users/create', Array('userName'=>'fred', 'saveRole'=>'advanced'))['code'] ?? null, 201);
checkIdentical('create: name taken', statusOf(callActn('users/create', Array('userName'=>'Emma'))), Array(409, 'User name already taken'));
checkIdentical('create: invalid name', statusOf(callActn('users/create', Array('userName'=>'<b>'))), Array(400, 'Invalid user name'));
checkIdentical('create: invalid role', statusOf(callActn('users/create', Array('userName'=>'gina', 'saveRole'=>'owner'))), Array(400, 'Invalid role'));
checkIdentical('create: no name', statusOf(callActn('users/create', Array('saveRole'=>'standard'))), Array(400, 'Missing or invalid input: userName'));
checkIdentical('create: a name that is not a text', statusOf(callActn('users/create', Array('userName'=>Array('emma')))), Array(400, 'Invalid userName'));

// index and view
$outcome = callActn('users/index', Array(), 'anna', 'managers', 'GET');
checkIdentical('index: every user, by name', Array($outcome['code'] ?? $outcome, array_column($outcome['out'] ?? Array(), 'systemName')), Array(200, Array('anna', 'ben', 'dave', 'emma', 'fred')));
checkIdentical('index: only what may be told', array_keys($outcome['out'][0] ?? Array()), Array('id_user', 'systemName', 'accessRole', 'userStatus', 'contactEml', 'userLocale', 'signupDate', 'createDate', 'accessDate'));
checkIdentical('index: by default action', callActn('users/', Array(), 'anna', 'managers', 'GET')['code'] ?? null, 200);
checkIdentical('index: filters', array_column(callActn('users/index', Array('currStat'=>'awaiting', 'saveRole'=>'advanced'), 'anna', 'managers', 'GET')['out'] ?? Array(), 'systemName'), Array('fred'));
checkIdentical('index: limit and offset, as sent in a query', array_column(callActn('users/index', Array('limitVal'=>'2', 'rowIndex'=>'1'), 'anna', 'managers', 'GET')['out'] ?? Array(), 'systemName'), Array('ben', 'dave'));
checkIdentical('index: nobody is 204', callActn('users/index', Array('currStat'=>'disabled', 'saveRole'=>'managers'), 'anna', 'managers', 'GET')['code'] ?? null, 204);
checkIdentical('index: invalid filter', statusOf(callActn('users/index', Array('currStat'=>'online'), 'anna', 'managers', 'GET')), Array(400, 'Invalid filter'));
$outcome = callActn('users/view/ben', Array(), 'anna', 'managers', 'GET');
checkIdentical('view: one user', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('id_user'=>2, 'systemName'=>'ben', 'accessRole'=>'standard', 'userStatus'=>'approved', 'contactEml'=>null, 'userLocale'=>null, 'signupDate'=>null, 'createDate'=>'2026-10-01 09:00:00', 'accessDate'=>null)));
checkIdentical('view: unknown user', statusOf(callActn('users/view/zed', Array(), 'anna', 'managers', 'GET')), Array(404, 'User not found'));
checkIdentical('view: no user in the path', statusOf(callActn('users/view', Array(), 'anna', 'managers', 'GET')), Array(400, 'Invalid user'));

// auth/active : open to anyone holding the code
checkIdentical('activate: wrong code', statusOf(callActn('auth/active', Array('userName'=>'emma', 'sentCode'=>str_repeat('0', 32), 'passWord'=>'pass-emma-1'), '', '')), Array(401, 'Invalid activation'));
checkIdentical('activate: unknown user, same reply', statusOf(callActn('auth/active', Array('userName'=>'zed', 'sentCode'=>$emmaCod, 'passWord'=>'pass-zed-01'), '', '')), Array(401, 'Invalid activation'));
checkIdentical('activate: a password breaking a rule', statusOf(callActn('auth/active', Array('userName'=>'emma', 'sentCode'=>$emmaCod, 'passWord'=>'short'), '', '')), Array(400, 'Password too short: 10 characters at least'));
checkIdentical('activate: no password', statusOf(callActn('auth/active', Array('userName'=>'emma', 'sentCode'=>$emmaCod), '', '')), Array(400, 'Missing or invalid input: passWord'));
$outcome = callActn('auth/active', Array('userName'=>'emma', 'sentCode'=>$emmaCod, 'passWord'=>'  <pass> "emma" & co  '), '', '');
checkIdentical('activate: the right code', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('active'=>true, 'stat'=>'approved')));
checkIdentical('activate: the password is kept as sent', password_verify('  <pass> "emma" & co  ', strval($sqlLink->query("SELECT passwdHash FROM users WHERE systemName='emma'")->fetchColumn())), true);
checkIdentical('activate: the code is used once', statusOf(callActn('auth/active', Array('userName'=>'emma', 'sentCode'=>$emmaCod, 'passWord'=>'pass-emma-2'), '', '')), Array(401, 'Invalid activation'));
plugConf('htpasswd');
checkIdentical('activate: a login on another credential check', statusOf(callActn('auth/active', Array('userName'=>'emma', 'sentCode'=>$emmaCod, 'passWord'=>'pass-emma-2'), '', '')), Array(500, 'User management unavailable'));

// auth/active : wrong codes count against the address
plugConf('nativeDb', 2);
$fredInp = Array('userName'=>'fred', 'sentCode'=>str_repeat('0', 32), 'passWord'=>'pass-fred-1');
checkIdentical('activate limit: a broken rule is not counted', Array(statusOf(callActn('auth/active', Array('passWord'=>'short') + $fredInp, '', '')), statusOf(callActn('auth/active', Array('passWord'=>'short') + $fredInp, '', '')), callActn('auth/active', Array('passWord'=>'short') + $fredInp, '', '')['code'] ?? null), Array(Array(400, 'Password too short: 10 characters at least'), Array(400, 'Password too short: 10 characters at least'), 400));
checkIdentical('activate limit: two wrong codes are answered 401', Array(callActn('auth/active', $fredInp, '', '')['code'] ?? null, callActn('auth/active', $fredInp, '', '')['code'] ?? null), Array(401, 401));
checkIdentical('activate limit: the address is then held', statusOf(callActn('auth/active', $fredInp, '', '')), Array(429, 'Too many attempts'));
dropTestFolder( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );

// auth/setpass : the logged user, for itself
checkIdentical('password: wrong current one', statusOf(callActn('auth/setpass', Array('oldValue'=>'nope', 'passWord'=>'pass-ben-22'), 'ben', 'standard')), Array(401, 'Invalid credentials'));
checkIdentical('password: a wrong current one counts against the address', count(glob(TOP_DIR.DS.str_replace('/', DS, $lmtFldr).DS.'*.cnt') ?: Array()), 1);
dropTestFolder( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
plugConf();
checkIdentical('password: nobody logged', statusOf(callActn('auth/setpass', Array('oldValue'=>'pass-ben-1', 'passWord'=>'pass-ben-22'), '', '')), Array(401, 'Not logged in'));
checkIdentical('password: a name sent is not read', Array(statusOf(callActn('auth/setpass', Array('userName'=>'anna', 'oldValue'=>'pass-anna-1', 'passWord'=>'pass-anna-2'), 'ben', 'standard')), password_verify('pass-anna-1', strval($sqlLink->query("SELECT passwdHash FROM users WHERE systemName='anna'")->fetchColumn()))), Array(Array(401, 'Invalid credentials'), true));
checkIdentical('password: a new one breaking a rule', statusOf(callActn('auth/setpass', Array('oldValue'=>'pass-ben-1', 'passWord'=>'short'), 'ben', 'standard')), Array(400, 'Password too short: 10 characters at least'));
checkIdentical('password: no new one', statusOf(callActn('auth/setpass', Array('oldValue'=>'pass-ben-1'), 'ben', 'standard')), Array(400, 'Missing or invalid input: passWord'));
$sqlLink->exec("UPDATE users SET failsCount=0, failedTime=NULL");
$outcome = callActn('auth/setpass', Array('oldValue'=>'pass-ben-1', 'passWord'=>'pass-ben-22'), 'ben', 'standard');
checkIdentical('password: changed', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('changed'=>true)));
checkIdentical('password: the new one is stored', password_verify('pass-ben-22', strval($sqlLink->query("SELECT passwdHash FROM users WHERE systemName='ben'")->fetchColumn())), true);

// lock, unlock, role, remove, reset
$outcome = callActn('users/lock/ben', Array());
checkIdentical('lock: locked', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'ben', 'stat'=>'disabled')));
checkIdentical('lock: already locked', statusOf(callActn('users/lock/ben', Array())), Array(409, 'User already disabled'));
checkIdentical('lock: unknown user', statusOf(callActn('users/lock/zed', Array())), Array(404, 'User not found'));
checkIdentical('lock: a manager cannot lock itself', statusOf(callActn('users/lock/anna', Array())), Array(409, 'A manager cannot do this to its own account'));
$outcome = callActn('users/unlock/ben', Array());
checkIdentical('unlock: active again', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'ben', 'stat'=>'approved')));
checkIdentical('unlock: a pending user', statusOf(callActn('users/unlock/fred', Array())), Array(409, 'A pending user is neither locked nor unlocked'));

$outcome = callActn('users/role/ben', Array('saveRole'=>'managers'));
checkIdentical('role: changed', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'ben', 'role'=>'managers')));
checkIdentical('role: invalid role', statusOf(callActn('users/role/ben', Array('saveRole'=>'owner'))), Array(400, 'Invalid role'));
checkIdentical('role: no role', statusOf(callActn('users/role/ben', Array())), Array(400, 'Missing or invalid input: saveRole'));
checkIdentical('role: a manager cannot change its own, even with another manager beside it', statusOf(callActn('users/role/anna', Array('saveRole'=>'standard'))), Array(409, 'A manager cannot do this to its own account'));
checkIdentical('role: another manager can', callActn('users/role/anna', Array('saveRole'=>'advanced'), 'ben', 'managers')['code'] ?? null, 200);
checkIdentical('role: and is then the last one', statusOf(callActn('users/role/ben', Array('saveRole'=>'standard'), 'emma', 'managers')), Array(409, 'The last active manager cannot be given another role'));
callActn('users/role/anna', Array('saveRole'=>'managers'), 'ben', 'managers');
callActn('users/role/ben', Array('saveRole'=>'standard'));

$outcome = callActn('users/reset/ben', Array());
checkIdentical('reset: 200 with the name', Array($outcome['code'] ?? $outcome, $outcome['out']['user'] ?? null), Array(200, 'ben'));
checkIdentical('reset: the new code activates the user', callActn('auth/active', Array('userName'=>'ben', 'sentCode'=>strval($outcome['out']['code'] ?? ''), 'passWord'=>'pass-ben-33'), '', '')['code'] ?? null, 200);
checkIdentical('reset: a locked user', statusOf(callActn('users/reset/dave', Array())), Array(409, 'A locked user is unlocked before it is reset'));
checkIdentical('reset: a manager cannot reset itself', statusOf(callActn('users/reset/anna', Array())), Array(409, 'A manager cannot do this to its own account'));

$outcome = callActn('users/remove/fred', Array());
checkIdentical('remove: removed', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'fred', 'removed'=>true)));
checkIdentical('remove: already gone', statusOf(callActn('users/remove/fred', Array())), Array(404, 'User not found'));
checkIdentical('remove: a manager cannot remove itself', statusOf(callActn('users/remove/anna', Array())), Array(409, 'A manager cannot do this to its own account'));
checkIdentical('remove: who is left', array_column(callActn('users/index', Array(), 'anna', 'managers', 'GET')['out'] ?? Array(), 'systemName'), Array('anna', 'ben', 'dave', 'emma'));

// Access mails : without a mailer, the codes are given in the replies, as above
$mailCnf = Array('mailAcnt'=>'devbox', 'linkTmpl'=>'https://app.example.ch/a?do=%mailKind%&user=%userName%&code=%sentCode%');
plugConf('nativeDb', 0, $mailCnf);
sentMail();
$outcome = callActn('users/create', Array('userName'=>'hana', 'userMail'=>'Hana@Example.ch'));
$mailLst = sentMail();
checkIdentical('create, mailed: 201, the code not in the reply', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(201, Array('user'=>'hana', 'mailed'=>true)));
checkIdentical('create, mailed: the code goes to the address, with its link', Array(count($mailLst), $mailLst[0]['to'] ?? null, $mailLst[0]['subject'] ?? null, strpos($mailLst[0]['text'] ?? '', 'https://app.example.ch/a?do=activate&user=hana&code='.($mailLst[0]['code'] ?? '-'))!==false), Array(1, 'hana@example.ch', 'Your account: choose your password', true));
checkIdentical('create, mailed: the code lasts the minutes of a mailed code', abs(strtotime(strval($sqlLink->query("SELECT codeExpiry FROM users WHERE systemName='hana'")->fetchColumn())) - (time()+15*60))<=5, true);
$outcome = callActn('auth/active', Array('userName'=>'hana', 'sentCode'=>strval($mailLst[0]['code'] ?? ''), 'passWord'=>'pass-hana-1'), '', '');
checkIdentical('create, mailed: the code activates', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('active'=>true, 'stat'=>'approved')));
checkIdentical('create: an address taken', statusOf(callActn('users/create', Array('userName'=>'hugo', 'userMail'=>'HANA@example.ch'))), Array(409, 'Mail address already used'));
$outcome = callActn('users/create', Array('userName'=>'hugo'));
checkIdentical('create without an address: the code in the reply', Array($outcome['code'] ?? $outcome, isset($outcome['out']['code']), sentMail()), Array(201, true, Array()));
$outcome = callActn('users/setmail/ben', Array('userMail'=>' Ben@Example.ch '));
checkIdentical('email: an address given, in lower case', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'ben', 'mail'=>'ben@example.ch')));
checkIdentical('email: an address taken, one that is not', Array(statusOf(callActn('users/setmail/hugo', Array('userMail'=>'ben@example.ch'))), statusOf(callActn('users/setmail/hugo', Array('userMail'=>'hugo')))), Array(Array(409, 'Mail address already used'), Array(400, 'Invalid mail address')));
checkIdentical('email: taken away', Array(callActn('users/setmail/hugo', Array('userMail'=>'hugo@example.ch'))['code'] ?? null, callActn('users/setmail/hugo', Array())['out'] ?? null), Array(200, Array('user'=>'hugo', 'mail'=>null)));

// users/langs and auth/usrlang : the language of the mails, given by a manager or by the user itself
$outcome = callActn('users/langs/hugo', Array('saveLang'=>'FR_ch'));
checkIdentical('langs: a manager gives one, in lower case', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'hugo', 'lang'=>'fr-ch')));
checkIdentical('langs: one that is not, then taken away', Array(statusOf(callActn('users/langs/hugo', Array('saveLang'=>'french'))), callActn('users/langs/hugo', Array())['out'] ?? null), Array(Array(400, 'Invalid language'), Array('user'=>'hugo', 'lang'=>null)));
checkIdentical('langs: a member is not a manager', statusOf(callActn('users/langs/hugo', Array('saveLang'=>'fr'), 'ben', 'standard')), Array(404, 'Not Found'));
$outcome = callActn('auth/usrlang', Array('userName'=>'hugo', 'saveLang'=>'de'), 'ben', 'standard');
checkIdentical('auth/usrlang: the logged user, for itself; a name sent is not read', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null, $sqlLink->query("SELECT systemName, userLocale FROM users WHERE systemName IN ('ben', 'hugo') ORDER BY systemName")->fetchAll(PDO::FETCH_KEY_PAIR)), Array(200, Array('lang'=>'de'), Array('ben'=>'de', 'hugo'=>null)));
checkIdentical('auth/usrlang: nobody logged, one that is not', Array(statusOf(callActn('auth/usrlang', Array('saveLang'=>'de'), '', '')), statusOf(callActn('auth/usrlang', Array('saveLang'=>'*'), 'ben', 'standard'))), Array(Array(401, 'Not logged in'), Array(400, 'Invalid language')));
callActn('auth/usrlang', Array(), 'ben', 'standard');
$outcome = callActn('users/create', Array('userName'=>'xena', 'saveLang'=>'it'));
checkIdentical('create: with the language of its mails', Array($outcome['code'] ?? $outcome, $sqlLink->query("SELECT userLocale FROM users WHERE systemName='xena'")->fetchColumn(), statusOf(callActn('users/create', Array('userName'=>'xavi', 'saveLang'=>'italian')))), Array(201, 'it', Array(400, 'Invalid language')));
$outcome = callActn('users/reset/ben', Array());
$mailLst = sentMail();
checkIdentical('reset, mailed: the code goes to the address of the user', Array($outcome['out'] ?? null, $mailLst[0]['to'] ?? null), Array(Array('user'=>'ben', 'mailed'=>true), 'ben@example.ch'));
checkIdentical('reset, mailed: the code activates', callActn('auth/active', Array('userName'=>'ben', 'sentCode'=>strval($mailLst[0]['code'] ?? ''), 'passWord'=>'pass-ben-44'), '', '')['code'] ?? null, 200);
checkIdentical('reset of a user without an address: the code in the reply', isset(callActn('users/reset/hugo', Array())['out']['code']), true);

// auth/signup : open when the block says so, a manager approves
checkIdentical('signup: closed unless the block opens it', statusOf(callActn('auth/signup', Array('userName'=>'ivan', 'userMail'=>'ivan@example.ch'), '', '')), Array(404, 'Not Found'));
plugConf('nativeDb', 0, Array('signupOn'=>true));
checkIdentical('signup: closed without a mailer', statusOf(callActn('auth/signup', Array('userName'=>'ivan', 'userMail'=>'ivan@example.ch'), '', '')), Array(404, 'Not Found'));
plugConf('nativeDb', 0, $mailCnf + Array('signupOn'=>true));
$outcome = callActn('auth/signup', Array('userName'=>'ivan', 'userMail'=>'Ivan@Example.ch'), '', '');
$mailLst = sentMail();
checkIdentical('signup: 202', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(202, Array('asked'=>true)));
checkIdentical('signup: the code goes to the address', Array($mailLst[0]['to'] ?? null, $mailLst[0]['subject'] ?? null, strpos($mailLst[0]['text'] ?? '', 'do=register&user=ivan&code=')!==false), Array('ivan@example.ch', 'Your sign-up: confirm your address', true));
$outcome = callActn('auth/signup', Array('userName'=>'Ivan', 'userMail'=>'other@example.ch'), '', '');
$takenMl = sentMail();
checkIdentical('signup: a name taken gets the same reply, and the address, of no account, is only told to choose another name', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null, $takenMl[0]['to'] ?? null, $takenMl[0]['subject'] ?? null, $takenMl[0]['code'] ?? null, strpos($takenMl[0]['text'] ?? '', 'account')), Array(202, Array('asked'=>true), 'other@example.ch', 'Your sign-up: choose another name', '', false));
$outcome = callActn('auth/signup', Array('userName'=>'jack', 'userMail'=>'ben@example.ch'), '', '');
$takenMl = sentMail();
checkIdentical('signup: an address taken is told to its owner', Array($outcome['code'] ?? null, count($takenMl), $takenMl[0]['to'] ?? null, $takenMl[0]['subject'] ?? null), Array(202, 1, 'ben@example.ch', 'Your sign-up'));
checkIdentical('signup: a name or an address that is not one', Array(statusOf(callActn('auth/signup', Array('userName'=>'a b', 'userMail'=>'jack@example.ch'), '', '')), statusOf(callActn('auth/signup', Array('userName'=>'jack', 'userMail'=>'jack'), '', '')), statusOf(callActn('auth/signup', Array('userName'=>'jack'), '', ''))), Array(Array(400, 'Invalid user name'), Array(400, 'Invalid mail address'), Array(400, 'Missing or invalid input: userMail')));
$outcome = callActn('auth/active', Array('userName'=>'ivan', 'sentCode'=>strval($mailLst[0]['code'] ?? ''), 'passWord'=>'pass-ivan-1'), '', '');
checkIdentical('signup: confirmed, it awaits review', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('active'=>false, 'stat'=>'inReview')));
checkIdentical('signup: listed by its status', array_column(callActn('users/index', Array('currStat'=>'inReview'), 'anna', 'managers', 'GET')['out'] ?? Array(), 'systemName'), Array('ivan'));
$outcome = callActn('users/approve/ivan', Array());
checkIdentical('approve: active', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null), Array(200, Array('user'=>'ivan', 'stat'=>'approved')));
checkIdentical('approve: only a user in review', statusOf(callActn('users/approve/ivan', Array())), Array(409, 'User not awaiting review'));
// The language of the mails : the one of the request for the one who asks, the one of the owner for an address taken
$GLOBALS['acptLang'] = 'fr-CH, fr;q=0.9';
sentMail();
callActn('auth/signup', Array('userName'=>'yann', 'userMail'=>'yann@example.ch'), '', '');
checkIdentical('language: a sign-up is written in the language of its request, which it keeps', Array(sentMail()[0]['subject'] ?? null, $sqlLink->query("SELECT userLocale FROM users WHERE systemName='yann'")->fetchColumn()), Array('Votre inscription : confirmez votre adresse', 'fr-ch'));
$sqlLink->exec("UPDATE users SET userLocale='en' WHERE systemName='ben'");
callActn('auth/signup', Array('userName'=>'zoe', 'userMail'=>'ben@example.ch'), '', '');
checkIdentical('language: an address taken is told in the language of its owner', sentMail()[0]['subject'] ?? null, 'Your sign-up');
callActn('auth/signup', Array('userName'=>'ivan', 'userMail'=>'nobody@example.ch'), '', '');
checkIdentical('language: a name taken, in the language of the request', sentMail()[0]['subject'] ?? null, 'Votre inscription : choisissez un autre nom');
$GLOBALS['acptLang'] = '';
dropTestFolder( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
plugConf('nativeDb', 0, $mailCnf + Array('signupOn'=>true), Array('maxPerIp'=>2));
checkIdentical('signup: maxPerIp sign-ups from one address', Array(callActn('auth/signup', Array('userName'=>'kate', 'userMail'=>'kate@example.ch'), '', '')['code'] ?? null, callActn('auth/signup', Array('userName'=>'lena', 'userMail'=>'lena@example.ch'), '', '')['code'] ?? null, statusOf(callActn('auth/signup', Array('userName'=>'mona', 'userMail'=>'mona@example.ch'), '', ''))), Array(202, 202, Array(429, 'Too many attempts')));
checkIdentical('signup: counted apart from the wrong credentials', Array(is_dir(TOP_DIR.DS.str_replace('/', DS, $lmtFldr).DS.'signup'), count(glob(TOP_DIR.DS.str_replace('/', DS, $lmtFldr).DS.'*.cnt') ?: Array())), Array(true, 0));
dropTestFolder( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
plugConf('nativeDb', 1, $mailCnf + Array('signupOn'=>true));
callActn('auth/recover', Array('userName'=>'ben', 'sentCode'=>'AAAA-BBBB-CC', 'passWord'=>'pass-ben-66'), '', '');
checkIdentical('signup: an address held for wrong credentials is refused', statusOf(callActn('auth/signup', Array('userName'=>'mona', 'userMail'=>'mona@example.ch'), '', '')), Array(429, 'Too many attempts'));
dropTestFolder( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
sentMail();
$sqlLink->exec("UPDATE users SET contactEml='anna@example.ch' WHERE systemName='anna'");
$waitNow = intval($sqlLink->query("SELECT COUNT(*) FROM users WHERE signupDate IS NOT NULL AND userStatus IN ('awaiting', 'inReview')")->fetchColumn());
plugConf('nativeDb', 0, $mailCnf + Array('signupOn'=>true), Array('maxQueue'=>$waitNow+2, 'alertsAt'=>Array($waitNow+1, $waitNow+2)));
callActn('auth/signup', Array('userName'=>'mona', 'userMail'=>'mona@example.ch'), '', '');
$mailLst = array_values(array_filter(sentMail(), function( Array $mailValue ): Bool { return $mailValue['to']=='anna@example.ch'; }));
checkIdentical('signup: the managers are told at a count of alertsAt', Array(count($mailLst), $mailLst[0]['subject'] ?? null, strpos($mailLst[0]['text'] ?? '', 'When '.($waitNow+2).' are waiting')!==false), Array(1, 'Sign-ups waiting for review: '.($waitNow+1), true));
callActn('auth/signup', Array('userName'=>'nora', 'userMail'=>'nora@example.ch'), '', '');
checkIdentical('signup: and at maxQueue, then closed', Array(count(array_filter(sentMail(), function( Array $mailValue ): Bool { return $mailValue['to']=='anna@example.ch'; })), statusOf(callActn('auth/signup', Array('userName'=>'olga', 'userMail'=>'olga@example.ch'), '', ''))), Array(1, Array(503, 'Sign-ups closed for now')));
checkPregMatch('signup: the counts are logged', strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log')), '/\[NOTICE\] '.($waitNow+1).' sign-ups are waiting, '.($waitNow+2).' at most.*\[WARNING\] '.($waitNow+2).' sign-ups are waiting: the sign-ups are closed/s');
dropTestFolder( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
sentMail();

// auth/forgot and auth/recover : a reset asked for by the user, by mail
plugConf('nativeDb', 0, $mailCnf);
$outcome = callActn('auth/forgot', Array('userName'=>'ben'), '', '');
$mailLst = sentMail();
checkIdentical('forgot: 202, the code goes to the address', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null, $mailLst[0]['to'] ?? null, $mailLst[0]['subject'] ?? null, strpos($mailLst[0]['text'] ?? '', 'do=password&user=ben&code=')!==false), Array(202, Array('asked'=>true), 'ben@example.ch', 'Your password: choose a new one', true));
checkIdentical('forgot: unknown or without an address, the same reply and no mail', Array(callActn('auth/forgot', Array('userName'=>'zed'), '', '')['code'] ?? null, callActn('auth/forgot', Array('userName'=>'nobody@example.ch'), '', '')['code'] ?? null, callActn('auth/forgot', Array('userName'=>'hugo'), '', '')['code'] ?? null, sentMail()), Array(202, 202, 202, Array()));
checkIdentical('forgot: the password still works', password_verify('pass-ben-44', strval($sqlLink->query("SELECT passwdHash FROM users WHERE systemName='ben'")->fetchColumn())), true);
checkIdentical('recovr: a wrong code', statusOf(callActn('auth/recover', Array('userName'=>'ben', 'sentCode'=>'AAAA-BBBB-CC', 'passWord'=>'pass-ben-55'), '', '')), Array(401, 'Invalid code'));
checkIdentical('recovr: a password breaking a rule, and none', Array(statusOf(callActn('auth/recover', Array('userName'=>'ben', 'sentCode'=>strval($mailLst[0]['code'] ?? ''), 'passWord'=>'short'), '', '')), statusOf(callActn('auth/recover', Array('userName'=>'ben', 'sentCode'=>strval($mailLst[0]['code'] ?? '')), '', ''))), Array(Array(400, 'Password too short: 10 characters at least'), Array(400, 'Missing or invalid input: passWord')));
$outcome = callActn('auth/recover', Array('userName'=>'ben', 'sentCode'=>strval($mailLst[0]['code'] ?? ''), 'passWord'=>'pass-ben-55'), '', '');
checkIdentical('recovr: the new password', Array($outcome['code'] ?? $outcome, $outcome['out'] ?? null, password_verify('pass-ben-55', strval($sqlLink->query("SELECT passwdHash FROM users WHERE systemName='ben'")->fetchColumn()))), Array(200, Array('changed'=>true), true));
checkIdentical('recovr: the code is used once', statusOf(callActn('auth/recover', Array('userName'=>'ben', 'sentCode'=>strval($mailLst[0]['code'] ?? ''), 'passWord'=>'pass-ben-66'), '', '')), Array(401, 'Invalid code'));
plugConf('nativeDb', 1, $mailCnf);
checkIdentical('recovr: wrong codes count against the address', Array(callActn('auth/recover', Array('userName'=>'ben', 'sentCode'=>'AAAA-BBBB-CC', 'passWord'=>'pass-ben-66'), '', '')['code'] ?? null, statusOf(callActn('auth/recover', Array('userName'=>'ben', 'sentCode'=>'AAAA-BBBB-CC', 'passWord'=>'pass-ben-66'), '', ''))), Array(401, Array(429, 'Too many attempts')));
dropTestFolder( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
plugConf();
checkIdentical('forgot: no mail account, no reset by mail', statusOf(callActn('auth/forgot', Array('userName'=>'ben'), '', '')), Array(404, 'Not Found'));
dropTestFolder( TOP_DIR.DS.str_replace('/', DS, $mailDir) );

// What the log keeps : every change, signed, and no password or code
$logText = strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log'));
foreach( Array('User "emma" created \(standard\) by user "anna" from 192\.0\.2\.50', 'User "emma" activated from 192\.0\.2\.50', 'Password changed for user "ben" by user "ben"', 'User "ben" locked by user "anna"', 'User "ben" unlocked by user "anna"', 'Role of user "ben" changed from standard to managers by user "anna"', 'User "ben" reset by user "anna"', 'User "fred" removed \(advanced\) by user "anna"') as $logLne ){
	checkPregMatch('log: '.stripslashes($logLne), $logText, '/'.$logLne.'/');
}
checkIdentical('log: no password and no code', Array(preg_match('/pass-|<pass>/', $logText), strpos($logText, $emmaCod)), Array(0, false));

Logger::plugLogsConfig( Array() );
Logger::setCurrentUser( '', '' );
dropTestFolder( TOP_DIR.DS.str_replace('/', DS, $lmtFldr) );
$sqlLink = null;

finishChecking();
