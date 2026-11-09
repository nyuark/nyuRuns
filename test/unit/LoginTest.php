<?php

// The login and logout actions of runs/Auth, called as the Controller calls
// them : each credential check a login can name without a database (htpasswd,
// userFile, regdKeys), each carrier it can issue with, what it counts against
// the address and what it writes on the access log.
// The login on the users table, the session it opens and its cookie are
// checked over HTTP, in test/http/HttpTest.php.

require __DIR__.'/../lib/harness.php';
require_once TOP_DIR.DS.'runs'.DS.'Auth'.DS.'Actions.php';

use nyu\Access\Sess\JWT;
use nyu\Access\Totp;
use nyu\Tools\Registry;
use nyu\Tools\Logger;

// The counters of the address limit lie under the project root : in a folder of this script's own
$lmtFldr = 'temp'.DS.'limits_test_'.getmypid();
$tknConf = Array('hmacSecr'=>str_repeat('s', 40), 'lifeSecs'=>60);

/**
 * Plugs the configuration the actions read.
 *
 * @param Array $loginBlock The 'login' block.
 * @param Int   $triesLimit Wrong credentials allowed from one address, 0 for no limit.
 */
function plugConf( Array $loginBlock, Int $triesLimit=0 ): Void {
	$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array(
		'LOGINSET'=>$loginBlock,
		'HTPASSWD'=>Array('passFile'=>'test'.DS.'fixtures'.DS.'users.htpasswd'),
		'JWTTOKEN'=>$GLOBALS['tknConf'],
		'TESTFILE'=>Array('listFile'=>'test'.DS.'fixtures'.DS.'users.lst'),
		'TESTKEYS'=>Array('keysList'=>Array('k-1')),
		'TESTSHRT'=>Array('hmacSecr'=>'REPLACE_ME'),
		'TRYLIMIT'=>Array('maxTries'=>$triesLimit, 'holdSecs'=>600, 'dataPath'=>$GLOBALS['lmtFldr'])
	)) );
}

/**
 * Runs an action of runs/Auth.
 *
 * @param String $actionName The action.
 * @param Array  $inputArray Input values sent.
 * @param String $httpMethod Method of the request.
 * @param String $clientAddr Address the request comes from.
 * @param Array  $loggedVals What the access check reported; nobody logged when empty.
 * @param Array  $queryNames Names of the inputs that came in the query string of the address, in lower case.
 * @return mixed The status, the message and the output, or a 'THROWN ...' string.
 */
function authActn( String $actionName, Array $inputArray=Array(), String $httpMethod='POST', String $clientAddr='203.0.113.7', Array $loggedVals=Array(), Array $queryNames=Array() ): Mixed {
	return runCatchThrown(function() use ($actionName, $inputArray, $httpMethod, $clientAddr, $loggedVals, $queryNames){
		$request = mockRequestObj( Array(), Array('REMOTE_ADDR'=>$clientAddr) );
		$qryProp = new ReflectionProperty('nyu\Tools\Request', 'addrValues');
		$qryProp->setValue($request, $queryNames);
		$mthProp = new ReflectionProperty('nyu\Tools\Request', 'rqstInMthd');
		$mthProp->setValue($request, $httpMethod);
		$axssReg = new Registry( $loggedVals + Array('logged'=>false, 'user'=>'', 'role'=>null) );
		$actnSet = new nyu\Runs\Auth\Actions( new nyu\App\RunContext($actionName, null, new Registry($inputArray), $axssReg, $request, null, null, null, 'Auth', RUN_DIR.DS.'Auth') );
		$actnSet->runAskedAction();
		return Array($actnSet->statusCode, strval($actnSet->statusMssg), $actnSet->replyValue);
	});
}

/**
 * Reads the identity a login answered with, from its token.
 *
 * @param mixed $actnResult Result of authActn().
 * @return mixed The user and the role of the token, or the result itself when no token was given.
 */
function tokenOf( Mixed $actnResult ): Mixed {
	if( !is_array($actnResult) || !is_string($actnResult[2]['token'] ?? null) ){
		return $actnResult;
	}
	$idntArr = (new JWT($GLOBALS['tknConf']))->verifyRqstList( mockRequestObj(Array('Authorization'=>'Bearer '.$actnResult[2]['token'])) );
	return is_array($idntArr) ? Array($idntArr['user'] ?? null, $idntArr['role'] ?? null) : $idntArr;
}

$alicePw = Array('user'=>'alice', 'pass'=>'s3cret');
$annaPwd = Array('user'=>'anna', 'pass'=>'pw-anna');
$lstLogn = Array('authWith'=>'userFile', 'authConf'=>'testFile');
$tfaScrt = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );

// Login on the htpasswd file, the token of the JWT carrier : what an empty 'login' block gives
plugConf( Array() );
$lgnRslt = authActn('openSess', $alicePw);
checkIdentical('login, by default: htpasswd and jwtToken, their htpasswd and jwtToken blocks', Array($lgnRslt[0] ?? null, tokenOf($lgnRslt)), Array(200, Array('alice', null)));
checkIdentical('login x htpasswd: wrong password', authActn('openSess', Array('user'=>'alice', 'pass'=>'nope')), Array(401, 'Invalid credentials', null));
checkIdentical('login x htpasswd: unknown user', authActn('openSess', Array('user'=>'nobody', 'pass'=>'s3cret')), Array(401, 'Invalid credentials', null));
checkIdentical('login by GET', authActn('openSess', $alicePw, 'GET'), Array(405, 'Method Not Allowed', null));
checkIdentical('login: a name or a password that is not a text', Array(authActn('openSess', Array('user'=>Array('alice'), 'pass'=>'s3cret')), authActn('openSess', Array('user'=>'alice'))), Array(Array(400, 'Invalid user or pass', null), Array(400, 'Invalid user or pass', null)));

// A password in the query string of the address is refused, wherever else it is sent
checkIdentical('login: the password in the query string', authActn('openSess', $alicePw, 'POST', '203.0.113.7', Array(), Array('user', 'pass')), Array(400, 'Password sent in the query string', null));
checkIdentical('login: a wrong one is refused the same way, before any check', authActn('openSess', Array('user'=>'alice', 'pass'=>'nope'), 'POST', '203.0.113.7', Array(), Array('pass')), Array(400, 'Password sent in the query string', null));
checkIdentical('login: the name alone in the query string', authActn('openSess', $alicePw, 'POST', '203.0.113.7', Array(), Array('user'))[0] ?? null, 200);
checkIdentical('activate: the password in the query string', authActn('activate', Array('user'=>'cleo', 'code'=>'c', 'pass'=>'pass-cleo-1'), 'POST', '203.0.113.7', Array(), Array('pass')), Array(400, 'Password sent in the query string', null));
checkIdentical('password: the current or the new one in the query string', Array(authActn('password', Array('former'=>'a', 'pass'=>'b'), 'POST', '203.0.113.7', Array(), Array('former'))[0] ?? null, authActn('password', Array('former'=>'a', 'pass'=>'b'), 'POST', '203.0.113.7', Array(), Array('pass'))[1] ?? null), Array(400, 'Password sent in the query string'));
checkPregMatch('login: the refusal is told in the access log, without the value', strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log')), '/\[WARNING\] Login refused from 203\.0\.113\.7: password sent in the query string\r?\n.*\[WARNING\] Activation refused from 203\.0\.113\.7: password sent in the query string\r?\n.*\[WARNING\] Password change refused from/s');

// Login on the users file : the role goes into the token, the status and the 2FA code are checked
plugConf( $lstLogn );
checkIdentical('login x userFile: the token carries the role of the file', tokenOf(authActn('openSess', $annaPwd)), Array('anna', 'sysadmin'));
checkIdentical('login x userFile: a pending user is refused as a wrong password is', authActn('openSess', Array('user'=>'cleo', 'pass'=>'pw-cleo')), Array(401, 'Invalid credentials', null));
checkIdentical('login x userFile: a user with a secret, password alone', authActn('openSess', Array('user'=>'emma', 'pass'=>'pw-emma')), Array(401, 'Verification code required', null));
checkIdentical('login x userFile: with the code of its authenticator', tokenOf(authActn('openSess', Array('user'=>'emma', 'pass'=>'pw-emma', 'code'=>Totp::codeAtTimeText($tfaScrt, time())))), Array('emma', 'internal'));
checkIdentical('login x userFile: a code sent as a number', tokenOf(authActn('openSess', Array('user'=>'emma', 'pass'=>'pw-emma', 'code'=>intval(Totp::codeAtTimeText($tfaScrt, time())))))[0] ?? null, (Totp::codeAtTimeText($tfaScrt, time())[0]=='0') ? 401 : 'emma');
checkIdentical('login x userFile: a code that is not a text is no code', authActn('openSess', Array('user'=>'emma', 'pass'=>'pw-emma', 'code'=>Array('1'))), Array(401, 'Verification code required', null));

// A check that takes no password, a carrier that issues nothing, a block that names neither
plugConf( Array('authWith'=>'regdKeys', 'authConf'=>'testKeys') );
checkIdentical('login x regdKeys: a check of keys takes no password, even a valid key', authActn('openSess', Array('user'=>'k-1', 'pass'=>'k-1')), Array(401, 'No API key provided', null));
foreach( Array('httpBasc', 'apiHeadr', 'clntCert') as $sesNme ){
	plugConf( Array('sessWith'=>$sesNme) );
	checkIdentical('login with '.$sesNme.': a carrier that issues nothing', authActn('openSess', $alicePw), Array(500, 'Login unavailable', null));
}
plugConf( Array('authWith'=>'nosuch') );
checkIdentical('login: an unknown credential check', authActn('openSess', $alicePw), Array(500, 'Login unavailable', null));
plugConf( Array('sessWith'=>'nosuch') );
checkIdentical('login: an unknown session carrier', authActn('openSess', $alicePw), Array(500, 'Login unavailable', null));
plugConf( Array('sessWith'=>'nosuch') );
checkIdentical('login: the misconfiguration is told before the credentials are checked', authActn('openSess', Array('user'=>'alice', 'pass'=>'nope')), Array(500, 'Login unavailable', null));
plugConf( Array('sessConf'=>'testShrt') );
checkIdentical('login: a secret that cannot sign', authActn('openSess', $alicePw), Array(500, 'Login unavailable', null));
checkIdentical('login: wrong credentials are still told as such with it', authActn('openSess', Array('user'=>'alice', 'pass'=>'nope')), Array(401, 'Invalid credentials', null));

// Limit by address : two wrong credentials allowed
plugConf( $lstLogn, 2 );
checkIdentical('limit: a code still to give is not counted', Array(
	authActn('openSess', Array('user'=>'emma', 'pass'=>'pw-emma'), 'POST', '198.51.100.1')[1] ?? null,
	authActn('openSess', Array('user'=>'emma', 'pass'=>'pw-emma'), 'POST', '198.51.100.1')[1] ?? null,
	authActn('openSess', Array('user'=>'emma', 'pass'=>'pw-emma'), 'POST', '198.51.100.1')[1] ?? null,
	authActn('openSess', $annaPwd, 'POST', '198.51.100.1')[0] ?? null
), Array('Verification code required', 'Verification code required', 'Verification code required', 200));
checkIdentical('limit: an account not active is counted, as a wrong password is', Array(
	authActn('openSess', Array('user'=>'cleo', 'pass'=>'pw-cleo'), 'POST', '198.51.100.9')[1] ?? null,
	authActn('openSess', Array('user'=>'dave', 'pass'=>'pw-dave'), 'POST', '198.51.100.9')[1] ?? null,
	authActn('openSess', $annaPwd, 'POST', '198.51.100.9')[0] ?? null
), Array('Invalid credentials', 'Invalid credentials', 429));
checkIdentical('limit: two wrong passwords are told as such', Array(authActn('openSess', Array('user'=>'anna', 'pass'=>'nope'), 'POST', '198.51.100.2'), authActn('openSess', Array('user'=>'nobody', 'pass'=>'nope'), 'POST', '198.51.100.2')), Array(Array(401, 'Invalid credentials', null), Array(401, 'Invalid credentials', null)));
checkIdentical('limit: the address is then refused, even with the right password', authActn('openSess', $annaPwd, 'POST', '198.51.100.2'), Array(429, 'Too many attempts', null));
checkIdentical('limit: another address logs in', authActn('openSess', $annaPwd, 'POST', '198.51.100.3')[0] ?? null, 200);
checkIdentical('limit: a wrong 2FA code is counted', Array(
	authActn('openSess', Array('user'=>'emma', 'pass'=>'pw-emma', 'code'=>'000000'), 'POST', '198.51.100.4')[1] ?? null,
	authActn('openSess', Array('user'=>'emma', 'pass'=>'pw-emma', 'code'=>'000000'), 'POST', '198.51.100.4')[1] ?? null,
	authActn('openSess', $annaPwd, 'POST', '198.51.100.4')[0] ?? null
), Array('Invalid verification code', 'Invalid verification code', 429));
plugConf( Array('authWith'=>'regdKeys', 'authConf'=>'testKeys'), 2 );
checkIdentical('limit: a check that takes no password counts nothing', Array(authActn('openSess', $alicePw, 'POST', '198.51.100.5')[0] ?? null, authActn('openSess', $alicePw, 'POST', '198.51.100.5')[0] ?? null, authActn('openSess', $alicePw, 'POST', '198.51.100.5')[0] ?? null), Array(401, 401, 401));
plugConf( Array('sessWith'=>'nosuch'), 2 );
checkIdentical('limit: a login that is misconfigured counts nothing', Array(authActn('openSess', $alicePw, 'POST', '198.51.100.6')[0] ?? null, authActn('openSess', $alicePw, 'POST', '198.51.100.6')[0] ?? null, authActn('openSess', $alicePw, 'POST', '198.51.100.6')[0] ?? null), Array(500, 500, 500));
dropTestFolder(TOP_DIR.DS.$lmtFldr);

// Logout : a token cannot be taken back, the client is told to forget it
plugConf( Array() );
$lgnRslt = authActn('openSess', $alicePw);
checkIdentical('logout by GET', authActn('quitSess', Array(), 'GET'), Array(405, 'Method Not Allowed', null));
checkIdentical('logout with a token carrier', authActn('quitSess'), Array(200, '', Array('logged'=>false)));
checkIdentical('logout: the token is still accepted until it expires', tokenOf($lgnRslt), Array('alice', null));
foreach( Array('httpBasc', 'apiHeadr', 'clntCert', 'nosuch') as $sesNme ){
	plugConf( Array('sessWith'=>$sesNme) );
	checkIdentical('logout with '.$sesNme.': nothing to end, the answer is the same', authActn('quitSess'), Array(200, '', Array('logged'=>false)));
}

// The access log : one line per login and logout, a notice for a privileged user
Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs2'), 'mode'=>'BASE', 'pref'=>'Y') );
plugConf( $lstLogn, 1 );
authActn('openSess', Array('user'=>'ben', 'pass'=>'pw-ben'), 'POST', '198.51.100.7');
authActn('openSess', $annaPwd, 'POST', '198.51.100.7');
authActn('openSess', Array('user'=>"ben\n[".date('Y')."] [INFO] Login granted for user \"anna\"", 'pass'=>'top-secret-word'), 'POST', '198.51.100.7');
authActn('openSess', $annaPwd, 'POST', '198.51.100.7');
authActn('quitSess', Array(), 'POST', '198.51.100.7', Array('logged'=>true, 'user'=>'ben', 'role'=>'internal'));
authActn('quitSess', Array(), 'POST', '198.51.100.7', Array('logged'=>true, 'user'=>'anna', 'role'=>'sysadmin'));
authActn('quitSess', Array(), 'POST', '198.51.100.7');
plugConf( Array('sessWith'=>'httpBasc') );
authActn('openSess', $alicePw, 'POST', '198.51.100.8');
plugConf( Array('authWith'=>'nosuch') );
authActn('openSess', $alicePw, 'POST', '198.51.100.8');
Logger::plugLogsConfig( Array() );
dropTestFolder(TOP_DIR.DS.$lmtFldr);
$yearTxt = '['.date('Y').'] ';
$axsLogs = file_exists(inTemporaryDir('logs2').DS.'access.log') ? file(inTemporaryDir('logs2').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkIdentical('access log: the logins and logouts, one line each, notices that production writes too', $axsLogs, Array(
	$yearTxt.'[NOTICE] Login granted for user "ben" from 198.51.100.7',
	$yearTxt.'[NOTICE] Privileged login granted for user "anna" (sysadmin) from 198.51.100.7',
	$yearTxt.'[WARNING] Login refused for user "ben\n['.date('Y').'] [INFO] Login granted for user \"anna\"" from 198.51.100.7: Invalid credentials',
	$yearTxt.'[WARNING] Login refused for user "anna" from 198.51.100.7: too many attempts',
	$yearTxt.'[NOTICE] Logout of user "ben" from 198.51.100.7',
	$yearTxt.'[NOTICE] Logout of privileged user "anna" (sysadmin) from 198.51.100.7',
	$yearTxt.'[NOTICE] Logout of user "" from 198.51.100.7',
	$yearTxt.'[ERROR] Login unavailable: the session carrier issues no token',
	$yearTxt.'[ERROR] Login unavailable: unknown authWith or sessWith in the login block'
));
$allLogs = '';
foreach( Array('logs', 'logs2') as $logFldr ){
	foreach( (array)glob(inTemporaryDir($logFldr).DS.'*.log') as $logFile ){
		$allLogs .= strval(file_get_contents($logFile));
	}
}
checkIdentical('access log: no password, code or token is written', preg_match('/s3cret|pw-anna|pw-emma|top-secret-word|000000|eyJ/', $allLogs), 0);

finishChecking();
