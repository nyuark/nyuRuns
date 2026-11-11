<?php

// Access\Auth\Native : credentials checked against the `users` table of
// the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Native', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Access\Auth\Native;

createSampleDb( inTemporaryDir('nativeDb.sqlite') );
switchToSqlite( inTemporaryDir('nativeDb.sqlite') );

$natAuth = new Native( Array('useTable'=>'users') );
checkIdentical('Native active user',          $natAuth->checkSecretArr(Array('user'=>'anna', 'pass'=>'pw-anna')), Array('userName'=>'anna', 'userRole'=>'sysadmin', 'idOfUser'=>1));
checkIdentical('Native active member',        $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben')), Array('userName'=>'ben', 'userRole'=>'internal', 'idOfUser'=>2));
checkIdentical('Native wrong password',       $natAuth->checkSecretArr(Array('user'=>'anna', 'pass'=>'nope')), false);
checkIdentical('Native wrong password message', $natAuth->finalErrorText(), 'Invalid credentials');
checkIdentical('Native unknown user',         $natAuth->checkSecretArr(Array('user'=>'zed', 'pass'=>'pw-zed')), false);
checkIdentical('Native unknown user message', $natAuth->finalErrorText(), 'Invalid credentials');
checkIdentical('Native no user',              $natAuth->checkSecretArr(Array('pass'=>'pw-anna')), false);
checkIdentical('Native pending user',         $natAuth->checkSecretArr(Array('user'=>'cleo', 'pass'=>'pw-cleo')), false);
checkIdentical('Native pending user: refused as a wrong password is', Array($natAuth->finalErrorText(), $natAuth->hasWrongSecret()), Array('Invalid credentials', true));
checkIdentical('Native locked user',          $natAuth->checkSecretArr(Array('user'=>'dave', 'pass'=>'pw-dave')), false);
checkIdentical('Native locked user: refused the same way', Array($natAuth->finalErrorText(), $natAuth->hasWrongSecret()), Array('Invalid credentials', true));
checkIdentical('Native a user that is not active has no failure counted for it', intval((new PDO('sqlite:'.inTemporaryDir('nativeDb.sqlite')))->query("SELECT SUM(failsCount) FROM users WHERE systemName IN ('cleo', 'dave')")->fetchColumn()), 0);
checkIdentical('Native locked user, wrong password: the status is not told', Array($natAuth->checkSecretArr(Array('user'=>'dave', 'pass'=>'nope')), $natAuth->finalErrorText()), Array(false, 'Invalid credentials'));
checkIdentical('Native name with a quote',    $natAuth->checkSecretArr(Array('user'=>"anna' OR '1'='1", 'pass'=>'pw-anna')), false);
checkIdentical('Native name with a quote message', $natAuth->finalErrorText(), 'Invalid credentials');
checkIdentical('Native name with a quote: tokens and stamps are refused too', runCatchThrown(function() use ($natAuth){
	return Array($natAuth->makeToknString("anna' OR '1'='1"), $natAuth->buildTwoFactor("anna' --"), $natAuth->retainAliveFlg("anna' OR '1'='1"));
}), Array(false, false, false));

$dfltNat = new Native( Array() );
checkIdentical('Native default table is users', $dfltNat->checkSecretArr(Array('user'=>'anna', 'pass'=>'pw-anna'))['userName'] ?? null, 'anna');

$noTable = new Native( Array('useTable'=>'nousers') );
checkIdentical('Native missing table',        runCatchThrown(function() use ($noTable){ return $noTable->checkSecretArr(Array('user'=>'anna', 'pass'=>'pw-anna')); }), false);
checkIdentical('Native missing table message', $noTable->finalErrorText(), 'Credential store unavailable');
$badName = new Native( Array('useTable'=>'users; DROP TABLE users') );
checkIdentical('Native unusable table name',  $badName->checkSecretArr(Array('user'=>'anna', 'pass'=>'pw-anna')), false);
checkIdentical('Native unusable table name message', $badName->finalErrorText(), 'Credential store unavailable');
$brkName = new Native( Array('useTable'=>"users\n") );
checkIdentical('Native table name ending with a line break', Array($brkName->checkSecretArr(Array('user'=>'anna', 'pass'=>'pw-anna')), $brkName->finalErrorText()), Array(false, 'Credential store unavailable'));
checkIdentical('Native: the users table is still there', $natAuth->checkSecretArr(Array('user'=>'anna', 'pass'=>'pw-anna'))['userName'] ?? null, 'anna');

// Presence : keepAlive stamps accessDate, onlineNow lists the active users seen within the idle time
$sqlLink = new PDO('sqlite:'.inTemporaryDir('nativeDb.sqlite'));
checkIdentical('Native nobody online at first', $natAuth->onlineNowArray(), Array());
checkIdentical('Native keepAlive stamps an active user', $natAuth->retainAliveFlg('anna'), true);
checkIdentical('Native the stamped user is online', $natAuth->onlineNowArray(), Array('anna'));
checkIdentical('Native keepAlive does not stamp twice within the gap', $natAuth->retainAliveFlg('anna'), false);
checkIdentical('Native keepAlive leaves a pending user alone', $natAuth->retainAliveFlg('cleo'), false);
checkIdentical('Native keepAlive leaves a locked user alone', $natAuth->retainAliveFlg('dave'), false);
checkIdentical('Native keepAlive on an unknown user', $natAuth->retainAliveFlg('zed'), false);
checkIdentical('Native only the stamped user is online', $natAuth->onlineNowArray(), Array('anna'));

$sqlLink->exec("UPDATE users SET accessDate='".date('Y-m-d H:i:s', time()-120)."' WHERE systemName='anna'");
checkIdentical('Native seen two minutes ago: still online with the default idle time', $natAuth->onlineNowArray(), Array('anna'));
$shrtNat = new Native( Array('idleSecs'=>90) );
checkIdentical('Native seen two minutes ago: not online with a 90 second idle time', $shrtNat->onlineNowArray(), Array());
checkIdentical('Native keepAlive stamps again once the gap is over', $natAuth->retainAliveFlg('anna'), true);
checkIdentical('Native online again after the new stamp', $shrtNat->onlineNowArray(), Array('anna'));
checkIdentical('Native presence on a missing table', Array($noTable->retainAliveFlg('anna'), $noTable->onlineNowArray()), Array(false, false));

// Checker : a verified request stamps the user when the credential store tracks presence
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>inTemporaryDir('nativeDb.sqlite')),
	'access'=>Array('TESTNAT'=>Array('useTable'=>'users'))
) );
$natConf = Array('required'=>true, 'sessWith'=>'httpBasc', 'authWith'=>'nativeDb', 'sessConf'=>'testnat');
$checker = new nyu\Access\Checker( $natConf, mockRequestObj(Array('Authorization'=>'Basic '.base64_encode('ben:pw-ben'))) );
$axsRslt = $checker->validateAccess();
checkIdentical('Checker with Native: granted with the stored role', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('userName'), $axsRslt->readValueByKey('userRole')), Array(200, 'ben', 'internal'));
checkIdentical('Checker with Native: the verified user is online', $natAuth->onlineNowArray(), Array('anna', 'ben'));
$checker = new nyu\Access\Checker( $natConf, mockRequestObj(Array('Authorization'=>'Basic '.base64_encode('cleo:pw-cleo'))) );
checkIdentical('Checker with Native: a pending user is refused', $checker->validateAccess()->readValueByKey('statCode'), 401);
checkIdentical('Checker with Native: a refused user is not online', $natAuth->onlineNowArray(), Array('anna', 'ben'));

// userIdent : the current identity of a user known by other means
checkIdentical('Native userIdent of an active user', $natAuth->usrIdentityArr('anna'), Array('userName'=>'anna', 'userRole'=>'sysadmin', 'idOfUser'=>1));
checkIdentical('Native userIdent of a pending user', Array($natAuth->usrIdentityArr('cleo'), $natAuth->finalErrorText()), Array(false, 'Account not active'));
checkIdentical('Native userIdent of an unknown user', Array($natAuth->usrIdentityArr('zed'), $natAuth->finalErrorText()), Array(false, 'Account not active'));
checkIdentical('Native userIdent without a name', $natAuth->usrIdentityArr(''), false);
checkIdentical('Native userIdent on a missing table', Array($noTable->usrIdentityArr('anna'), $noTable->finalErrorText()), Array(false, 'Credential store unavailable'));

// Checker : a token is confirmed against the users table when the preset names it as the credential store
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>inTemporaryDir('nativeDb.sqlite')),
	'access'=>Array('TESTNAT'=>Array('useTable'=>'users'), 'TESTJWT'=>Array('hmacSecr'=>str_repeat('s', 40)), 'OTHERTB'=>Array('useTable'=>'nousers'))
) );
$jwtSess = new nyu\Access\Sess\JWT( Array('hmacSecr'=>str_repeat('s', 40)) );
$annaHed = Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'anna', 'userRole'=>'internal')));
$tknConf = Array('required'=>true, 'sessWith'=>'jwtToken', 'sessConf'=>'testjwt', 'authWith'=>'nativeDb', 'authConf'=>'testnat');

$axsRslt = (new nyu\Access\Checker( $tknConf, mockRequestObj($annaHed) ))->validateAccess();
checkIdentical('Checker token with a store: the role comes from the table, not from the token', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('userName'), $axsRslt->readValueByKey('userRole')), Array(200, 'anna', 'sysadmin'));

$sqlLink->exec("UPDATE users SET userStatus='disabled' WHERE systemName='anna'");
$axsRslt = (new nyu\Access\Checker( $tknConf, mockRequestObj($annaHed) ))->validateAccess();
checkIdentical('Checker token with a store: a locked user is refused at once', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('statMssg')), Array(401, 'Account not active'));

$axsRslt = (new nyu\Access\Checker( Array('required'=>true, 'sessWith'=>'jwtToken', 'sessConf'=>'testjwt'), mockRequestObj($annaHed) ))->validateAccess();
checkIdentical('Checker token without a store: the token alone decides', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('userRole')), Array(200, 'internal'));
$sqlLink->exec("UPDATE users SET userStatus='approved' WHERE systemName='anna'");

$zedHead = Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'zed')));
checkIdentical('Checker token with a store: a user missing from the table is refused', (new nyu\Access\Checker( $tknConf, mockRequestObj($zedHead) ))->validateAccess()->readValueByKey('statCode'), 401);

$axsRslt = (new nyu\Access\Checker( Array('authConf'=>'othertb') + $tknConf, mockRequestObj($annaHed) ))->validateAccess();
checkIdentical('Checker authConf names the block of the store', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('statMssg')), Array(401, 'Credential store unavailable'));

// Personal API tokens : only the SHA-256 hash is stored, one token per user
$annaTkn = $natAuth->makeToknString('anna');
checkPregMatch('Native makeToken gives a 64 character token', $annaTkn, '/^[0-9a-f]{64}$/');
checkIdentical('Native the token itself is not stored', $sqlLink->query("SELECT bearerHash FROM users WHERE systemName='anna'")->fetchColumn(), hash('sha256', strval($annaTkn)));
checkIdentical('Native token accepted, with the user and role', $natAuth->checkSecretArr(Array('key'=>$annaTkn)), Array('userName'=>'anna', 'userRole'=>'sysadmin', 'idOfUser'=>1));
checkIdentical('Native wrong token',          Array($natAuth->checkSecretArr(Array('key'=>str_repeat('0', 64))), $natAuth->finalErrorText()), Array(false, 'Invalid API key'));
checkIdentical('Native empty token',          Array($natAuth->checkSecretArr(Array('key'=>'')), $natAuth->finalErrorText()), Array(false, 'No API key provided'));
checkIdentical('Native the stored hash is not a token', $natAuth->checkSecretArr(Array('key'=>hash('sha256', strval($annaTkn)))), false);
$nextTkn = $natAuth->makeToknString('anna');
checkIdentical('Native a new token replaces the previous one', Array($natAuth->checkSecretArr(Array('key'=>$annaTkn)), $natAuth->checkSecretArr(Array('key'=>$nextTkn))['userName'] ?? null), Array(false, 'anna'));
checkIdentical('Native makeToken refused for a pending user', $natAuth->makeToknString('cleo'), false);
checkIdentical('Native makeToken refused for an unknown user', $natAuth->makeToknString('zed'), false);
$sqlLink->exec("UPDATE users SET userStatus='disabled' WHERE systemName='anna'");
checkIdentical('Native token of a locked user', Array($natAuth->checkSecretArr(Array('key'=>$nextTkn)), $natAuth->finalErrorText()), Array(false, 'Invalid API key'));
$sqlLink->exec("UPDATE users SET userStatus='approved' WHERE systemName='anna'");
checkIdentical('Native token on a missing table', Array($noTable->checkSecretArr(Array('key'=>$nextTkn)), $noTable->finalErrorText(), $noTable->makeToknString('anna')), Array(false, 'Credential store unavailable', false));

// Checker : the token travels in the API key header and identifies its user
$keyConf = Array('required'=>true, 'sessWith'=>'apiHeadr', 'authWith'=>'nativeDb', 'sessConf'=>'testnat');
$axsRslt = (new nyu\Access\Checker( $keyConf, mockRequestObj(Array('X-Api-Key'=>$nextTkn)) ))->validateAccess();
checkIdentical('Checker personal token: user and role', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('userName'), $axsRslt->readValueByKey('userRole')), Array(200, 'anna', 'sysadmin'));
$axsRslt = (new nyu\Access\Checker( $keyConf, mockRequestObj(Array('X-Api-Key'=>'nope')) ))->validateAccess();
checkIdentical('Checker personal token: wrong token', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('statMssg')), Array(401, 'Invalid API key'));

// 2FA : a user with a secret must give the current code of its authenticator with the password
checkIdentical('Native 2FA: no secret, no code asked', $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben'))['userName'] ?? null, 'ben');
$benScrt = $natAuth->buildTwoFactor('ben');
checkPregMatch('Native makeTwoFa gives a base32 secret', $benScrt, '/^[A-Z2-7]{32}$/');
checkIdentical('Native 2FA: password alone', Array($natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben')), $natAuth->finalErrorText()), Array(false, 'Verification code required'));
checkIdentical('Native 2FA: wrong code',     Array($natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>'000000')), $natAuth->finalErrorText()), Array(false, 'Invalid verification code'));
checkIdentical('Native 2FA: valid code',     $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTimeText(strval($benScrt), time()))), Array('userName'=>'ben', 'userRole'=>'internal', 'idOfUser'=>2));
// A code is accepted once : its step is remembered, and nothing of that step or before passes again
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs')) );
checkIdentical('Native 2FA: the same code a second time', Array($natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTimeText(strval($benScrt), time()))), $natAuth->finalErrorText(), $natAuth->hasWrongSecret()), Array(false, 'Invalid verification code', true));
nyu\Tools\Logger::plugLogsConfig( Array() );
checkPregMatch('Native 2FA: the replay is told in the access log', strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log')), '/\[WARNING\] Verification code refused: already used by user "ben"/');
checkIdentical('Native 2FA: the replay counts as a failed attempt', failsOf($sqlLink, 'ben'), 1);
$sqlLink->exec("UPDATE users SET twoFactPos=".(intdiv(time(), 30)+5)." WHERE systemName='ben'");
checkIdentical('Native 2FA: a code older than the last one accepted', $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTimeText(strval($benScrt), time()))), false);
$benScrt = $natAuth->buildTwoFactor('ben');
checkIdentical('Native makeTwoFa forgets the last step', $sqlLink->query("SELECT twoFactPos FROM users WHERE systemName='ben'")->fetchColumn(), null);
checkIdentical('Native 2FA: the code of the new secret', $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTimeText(strval($benScrt), time())))['userName'] ?? null, 'ben');
checkIdentical('Native 2FA: its step is remembered', intval($sqlLink->query("SELECT twoFactPos FROM users WHERE systemName='ben'")->fetchColumn())>=intdiv(time(), 30)-1, true);
// A users table made before the step was remembered : a user with 2FA is refused until the table is upgraded
$sqlLink->exec("CREATE TABLE oldusers AS SELECT id_user, systemName, passwdHash, accessRole, userStatus, actvtnCode, bearerHash, twoFactKey, failsCount, failedTime, createDate, accessDate FROM users");
$oldAuth = new Native( Array('useTable'=>'oldusers') );
checkIdentical('Native 2FA: a table without the step column', Array(runCatchThrown(function() use ($oldAuth, $benScrt){ return $oldAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTimeText(strval($benScrt), time()))); }), $oldAuth->finalErrorText(), $oldAuth->hasWrongSecret()), Array(false, 'Credential store unavailable', false));
checkIdentical('Native 2FA: a table without the step column, user without a secret', $oldAuth->checkSecretArr(Array('user'=>'anna', 'pass'=>'pw-anna'))['userName'] ?? null, 'anna');
$sqlLink->exec("DROP TABLE oldusers");
// The secret is stored encrypted when the block gives a key, and readable otherwise, which is logged
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs')) );
$benScrt = $natAuth->buildTwoFactor('ben');
$canSeal = function_exists('openssl_encrypt');
if( $canSeal ){
	$keyAuth = new Native( Array('useTable'=>'users', 'tfaCrypt'=>str_repeat('k', 40)) );
	$annaSct = strval($keyAuth->buildTwoFactor('anna'));
	$annaCod = Array('user'=>'anna', 'pass'=>'pw-anna', 'code'=>nyu\Access\Totp::codeAtTimeText($annaSct, time()));
	checkPregMatch('Native 2FA: with a key, the secret is stored encrypted', strval($sqlLink->query("SELECT twoFactKey FROM users WHERE systemName='anna'")->fetchColumn()), '/^enc1:[A-Za-z0-9+\/=]{80}$/D');
	checkPregMatch('Native 2FA: the secret given back is the one the authenticator takes', $annaSct, '/^[A-Z2-7]{32}$/');
	checkIdentical('Native 2FA: without the key, the user is refused as a store that cannot be read', Array($natAuth->checkSecretArr($annaCod), $natAuth->finalErrorText(), $natAuth->hasWrongSecret()), Array(false, 'Credential store unavailable', false));
	checkIdentical('Native 2FA: with another key too', Array((new Native(Array('tfaCrypt'=>str_repeat('x', 40))))->checkSecretArr($annaCod), failsOf($sqlLink, 'anna')), Array(false, 0));
	checkIdentical('Native 2FA: with the key, the code passes', $keyAuth->checkSecretArr($annaCod)['userName'] ?? null, 'anna');
	checkIdentical('Native 2FA: a secret stored readable is still read with a key set', $keyAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben'))!==false || $keyAuth->finalErrorText()=='Verification code required', true);
	$sqlLink->exec("UPDATE users SET twoFactKey=NULL, twoFactPos=NULL WHERE systemName='anna'");
}else{
	recordSkipping('Native 2FA: secrets stored encrypted', 'the openssl extension is not loaded');
}
nyu\Tools\Logger::plugLogsConfig( Array() );
$logText = strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log'));
if( $canSeal ){
	checkPregMatch('Native 2FA: a secret that cannot be read is told in the access log', $logText, '/\[ERROR\] 2FA secret of user "anna" cannot be read: tfaCrypt is missing or was changed/');
	checkIdentical('Native 2FA: a secret stored readable is told in the access log, an encrypted one is not', Array(preg_match('/\[WARNING\] 2FA secret of user "ben" stored readable: no tfaCrypt of 32 characters is set/', $logText), preg_match('/secret of user "anna" stored readable/', $logText)), Array(1, 0));
}
checkIdentical('Native 2FA: wrong password, the code is not asked for', Array($natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'nope')), $natAuth->finalErrorText()), Array(false, 'Invalid credentials'));
checkIdentical('Native 2FA: valid code, wrong password', $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'nope', 'code'=>nyu\Access\Totp::codeAtTimeText(strval($benScrt), time()))), false);
checkIdentical('Native makeTwoFa refused for a pending user', $natAuth->buildTwoFactor('cleo'), false);
checkIdentical('Native 2FA: a personal token is not asked for a code', $natAuth->checkSecretArr(Array('key'=>$natAuth->makeToknString('ben')))['userName'] ?? null, 'ben');

// Attempt throttling : 3 failures hold the account for 600 seconds by default
/**
 * Reads the failed attempts counted for a user.
 *
 * @param PDO    $sqliteLink Connection to the sample database.
 * @param String $userString Login name of the user.
 * @return Int The value of failsCount.
 */
function failsOf( PDO $sqliteLink, String $userString ): Int {
	return intval( $sqliteLink->query("SELECT failsCount FROM users WHERE systemName='".$userString."'")->fetchColumn() );
}
$annaPwd = Array('user'=>'anna', 'pass'=>'pw-anna');
$annaBad = Array('user'=>'anna', 'pass'=>'nope');
$sqlLink->exec("UPDATE users SET failsCount=0, failedTime=NULL");
$thrAuth = new Native( Array('useTable'=>'users', 'maxTries'=>3) );
$thrAuth->checkSecretArr($annaBad);
$thrAuth->checkSecretArr($annaBad);
checkIdentical('Throttle two failures are counted', failsOf($sqlLink, 'anna'), 2);
checkIdentical('Throttle two failures: the right password still passes', $thrAuth->checkSecretArr($annaPwd)['userName'] ?? null, 'anna');
checkIdentical('Throttle a success forgets the failures', failsOf($sqlLink, 'anna'), 0);
$thrAuth->checkSecretArr($annaBad);
$thrAuth->checkSecretArr($annaBad);
checkIdentical('Throttle the third failure is still told as a wrong password', Array($thrAuth->checkSecretArr($annaBad), $thrAuth->finalErrorText()), Array(false, 'Invalid credentials'));
// The hold is told in the log only : the reply is the one of a wrong password
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs')) );
checkIdentical('Throttle held: the right password is refused, as a wrong one would be', Array($thrAuth->checkSecretArr($annaPwd), $thrAuth->finalErrorText(), $thrAuth->hasWrongSecret()), Array(false, 'Invalid credentials', true));
checkIdentical('Throttle held: a wrong password is refused the same way', Array($thrAuth->checkSecretArr($annaBad), $thrAuth->finalErrorText()), Array(false, 'Invalid credentials'));
nyu\Tools\Logger::plugLogsConfig( Array() );
checkPregMatch('Throttle held: the hold is told in the access log, with the name', strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log')), '/\[WARNING\] Credential refused: user "anna" is held after too many attempts/');
checkIdentical('Throttle held: nothing more is counted', failsOf($sqlLink, 'anna'), 3);
$sqlLink->exec("UPDATE users SET twoFactPos=NULL WHERE systemName='ben'");
checkIdentical('Throttle held: other users are not affected', $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTimeText(strval($benScrt), time())))['userName'] ?? null, 'ben');
checkIdentical('Throttle held: a personal token still passes', $natAuth->checkSecretArr(Array('key'=>$nextTkn))['userName'] ?? null, 'anna');

$sqlLink->exec("UPDATE users SET failedTime='".date('Y-m-d H:i:s', time()-601)."' WHERE systemName='anna'");
checkIdentical('Throttle the hold is over after its time', $natAuth->checkSecretArr($annaPwd)['userName'] ?? null, 'anna');
checkIdentical('Throttle the count is forgotten once back in', failsOf($sqlLink, 'anna'), 0);

$sqlLink->exec("UPDATE users SET failsCount=2, failedTime='".date('Y-m-d H:i:s', time()-601)."' WHERE systemName='anna'");
$natAuth->checkSecretArr($annaBad);
checkIdentical('Throttle old failures expire: the count restarts at 1', failsOf($sqlLink, 'anna'), 1);

$oneTry = new Native( Array('maxTries'=>1, 'holdSecs'=>60) );
$sqlLink->exec("UPDATE users SET failsCount=0, failedTime=NULL");
$oneTry->checkSecretArr($annaBad);
checkIdentical('Throttle maxTries is read from the config', Array($oneTry->checkSecretArr($annaPwd), $oneTry->finalErrorText()), Array(false, 'Invalid credentials'));
$sqlLink->exec("UPDATE users SET failedTime='".date('Y-m-d H:i:s', time()-120)."' WHERE systemName='anna'");
checkIdentical('Throttle holdSecs is read from the config', $oneTry->checkSecretArr($annaPwd)['userName'] ?? null, 'anna');
checkIdentical('Throttle by default, four failures do not hold', Array($sqlLink->exec("UPDATE users SET failsCount=4, failedTime='".date('Y-m-d H:i:s', time()-120)."' WHERE systemName='anna'"), $natAuth->checkSecretArr($annaPwd)['userName'] ?? null), Array(1, 'anna'));
checkIdentical('Throttle by default, five failures hold, and the same failure still holds with the default time', Array($sqlLink->exec("UPDATE users SET failsCount=5, failedTime='".date('Y-m-d H:i:s', time()-120)."' WHERE systemName='anna'"), $natAuth->checkSecretArr($annaPwd)), Array(1, false));

// 2FA and throttling : a missing code is not a failure, a wrong code is
$sqlLink->exec("UPDATE users SET failsCount=0, failedTime=NULL");
$natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben'));
checkIdentical('Throttle a missing code is not counted', failsOf($sqlLink, 'ben'), 0);
$natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>'000000'));
checkIdentical('Throttle a wrong code is counted', failsOf($sqlLink, 'ben'), 1);
checkIdentical('Throttle an unknown user is not counted and raises nothing', Array($natAuth->checkSecretArr(Array('user'=>'zed', 'pass'=>'nope')), $natAuth->finalErrorText()), Array(false, 'Invalid credentials'));

// Passwords : setPasswd replaces the hash and forgets the failures, whatever the status
// The sample passwords are short : the shortest length is brought down to theirs
$lowAuth = new Native( Array('useTable'=>'users', 'minChars'=>6) );
$sqlLink->exec("UPDATE users SET failsCount=2, failedTime='".date('Y-m-d H:i:s')."' WHERE systemName='anna'");
checkIdentical('Native setPasswd on a known user', $lowAuth->savePasswdFlag('anna', 'new-anna'), true);
checkIdentical('Native setPasswd: the new password is accepted', $natAuth->checkSecretArr(Array('user'=>'anna', 'pass'=>'new-anna'))['userName'] ?? null, 'anna');
checkIdentical('Native setPasswd: the previous password is refused', Array($natAuth->checkSecretArr($annaPwd), $natAuth->finalErrorText()), Array(false, 'Invalid credentials'));
$sqlLink->exec("UPDATE users SET failsCount=2, failedTime='".date('Y-m-d H:i:s')."' WHERE systemName='anna'");
checkIdentical('Native setPasswd forgets the failures', Array($lowAuth->savePasswdFlag('anna', 'pw-anna'), failsOf($sqlLink, 'anna')), Array(true, 0));
checkIdentical('Native setPasswd leaves the status as it is', Array($lowAuth->savePasswdFlag('cleo', 'new-cleo'), $natAuth->checkSecretArr(Array('user'=>'cleo', 'pass'=>'new-cleo')), $natAuth->finalErrorText()), Array(true, false, 'Invalid credentials'));
checkIdentical('Native setPasswd on an unknown user', $lowAuth->savePasswdFlag('zed', 'pw-zed'), false);
checkIdentical('Native setPasswd refuses an empty password', Array($lowAuth->savePasswdFlag('anna', ''), $natAuth->checkSecretArr($annaPwd)['userName'] ?? null), Array(false, 'anna'));
checkIdentical('Native setPasswd with a name holding a quote', $lowAuth->savePasswdFlag("anna' OR '1'='1", 'nopass'), false);
checkIdentical('Native setPasswd on a missing table', $noTable->savePasswdFlag('anna', 'pw-anna-long'), false);

// Password rules : 10 characters by default, 72 bytes at most, no control character, not the user name
checkIdentical('Rules nine characters are too short by default', Array($natAuth->savePasswdFlag('anna', 'ninechars'), $natAuth->finalErrorText()), Array(false, 'Password too short: 10 characters at least'));
checkIdentical('Rules ten characters pass, whatever they are', $natAuth->savePasswdFlag('anna', 'aaaaaaaaaa'), true);
checkIdentical('Rules characters are counted, not bytes', Array($natAuth->savePasswdFlag('anna', 'ééééé'), $natAuth->savePasswdFlag('anna', 'éééééééééé')), Array(false, true));
checkIdentical('Rules 72 bytes pass', $natAuth->savePasswdFlag('anna', str_repeat('a', 72)), true);
checkIdentical('Rules 73 bytes are refused', Array($natAuth->savePasswdFlag('anna', str_repeat('a', 73)), $natAuth->finalErrorText()), Array(false, 'Password too long: 72 bytes at most'));
checkIdentical('Rules a control character is refused', Array($natAuth->savePasswdFlag('anna', "with\0a nul byte"), $natAuth->savePasswdFlag('anna', "with a\nline break"), $natAuth->finalErrorText()), Array(false, false, 'Password holding a control character'));
checkIdentical('Rules the user name is refused, whatever the case', Array((new Native(Array('minChars'=>4)))->savePasswdFlag('anna', 'ANNA'), $lowAuth->savePasswdFlag('anna', 'pw-anna')), Array(false, true));
checkIdentical('Rules a password is kept as given, spaces and quotes included', Array($natAuth->savePasswdFlag('anna', '  it\'s "<b>" &  '), $natAuth->checkSecretArr(Array('user'=>'anna', 'pass'=>'  it\'s "<b>" &  '))['userName'] ?? null, $natAuth->checkSecretArr(Array('user'=>'anna', 'pass'=>'it\'s "<b>" &'))), Array(true, 'anna', false));
checkIdentical('Rules a refused password changes nothing', Array($natAuth->savePasswdFlag('anna', 'short'), $natAuth->checkSecretArr(Array('user'=>'anna', 'pass'=>'  it\'s "<b>" &  '))['userName'] ?? null), Array(false, 'anna'));
$sqlLink = null;

finishChecking();
