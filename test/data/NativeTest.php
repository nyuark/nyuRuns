<?php

// Access\Auth\Native : credentials checked against the `users` table of
// the sample SQLite database (see test/lib/sample.php).

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Native', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Access\Auth\Native;

buildData( inTempDir('native.sqlite') );
useSqlite( inTempDir('native.sqlite') );

$natAuth = new Native( Array('tblnme'=>'users') );
checkSame('Native active user',          $natAuth->checkCred(Array('user'=>'anna', 'pass'=>'pw-anna')), Array('user'=>'anna', 'role'=>'manage', 'id'=>1));
checkSame('Native active member',        $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben')), Array('user'=>'ben', 'role'=>'member', 'id'=>2));
checkSame('Native wrong password',       $natAuth->checkCred(Array('user'=>'anna', 'pass'=>'nope')), false);
checkSame('Native wrong password message', $natAuth->lastError(), 'Invalid credentials');
checkSame('Native unknown user',         $natAuth->checkCred(Array('user'=>'zed', 'pass'=>'pw-zed')), false);
checkSame('Native unknown user message', $natAuth->lastError(), 'Invalid credentials');
checkSame('Native no user',              $natAuth->checkCred(Array('pass'=>'pw-anna')), false);
checkSame('Native pending user',         $natAuth->checkCred(Array('user'=>'cleo', 'pass'=>'pw-cleo')), false);
checkSame('Native pending user: refused as a wrong password is', Array($natAuth->lastError(), $natAuth->wrongCred()), Array('Invalid credentials', true));
checkSame('Native locked user',          $natAuth->checkCred(Array('user'=>'dave', 'pass'=>'pw-dave')), false);
checkSame('Native locked user: refused the same way', Array($natAuth->lastError(), $natAuth->wrongCred()), Array('Invalid credentials', true));
checkSame('Native a user that is not active has no failure counted for it', intval((new PDO('sqlite:'.inTempDir('native.sqlite')))->query("SELECT SUM(failNmbr) FROM users WHERE userName IN ('cleo', 'dave')")->fetchColumn()), 0);
checkSame('Native locked user, wrong password: the status is not told', Array($natAuth->checkCred(Array('user'=>'dave', 'pass'=>'nope')), $natAuth->lastError()), Array(false, 'Invalid credentials'));
checkSame('Native name with a quote',    $natAuth->checkCred(Array('user'=>"anna' OR '1'='1", 'pass'=>'pw-anna')), false);
checkSame('Native name with a quote message', $natAuth->lastError(), 'Invalid credentials');
checkSame('Native name with a quote: tokens and stamps are refused too', runSafely(function() use ($natAuth){
	return Array($natAuth->makeToken("anna' OR '1'='1"), $natAuth->makeTwoFa("anna' --"), $natAuth->keepAlive("anna' OR '1'='1"));
}), Array(false, false, false));

$dfltNat = new Native( Array() );
checkSame('Native default table is users', $dfltNat->checkCred(Array('user'=>'anna', 'pass'=>'pw-anna'))['user'] ?? null, 'anna');

$noTable = new Native( Array('tblnme'=>'nousers') );
checkSame('Native missing table',        runSafely(function() use ($noTable){ return $noTable->checkCred(Array('user'=>'anna', 'pass'=>'pw-anna')); }), false);
checkSame('Native missing table message', $noTable->lastError(), 'Credential store unavailable');
$badName = new Native( Array('tblnme'=>'users; DROP TABLE users') );
checkSame('Native unusable table name',  $badName->checkCred(Array('user'=>'anna', 'pass'=>'pw-anna')), false);
checkSame('Native unusable table name message', $badName->lastError(), 'Credential store unavailable');
$brkName = new Native( Array('tblnme'=>"users\n") );
checkSame('Native table name ending with a line break', Array($brkName->checkCred(Array('user'=>'anna', 'pass'=>'pw-anna')), $brkName->lastError()), Array(false, 'Credential store unavailable'));
checkSame('Native: the users table is still there', $natAuth->checkCred(Array('user'=>'anna', 'pass'=>'pw-anna'))['user'] ?? null, 'anna');

// Presence : keepAlive stamps lastSeen, onlineNow lists the active users seen within the idle time
$sqlLink = new PDO('sqlite:'.inTempDir('native.sqlite'));
checkSame('Native nobody online at first', $natAuth->onlineNow(), Array());
checkSame('Native keepAlive stamps an active user', $natAuth->keepAlive('anna'), true);
checkSame('Native the stamped user is online', $natAuth->onlineNow(), Array('anna'));
checkSame('Native keepAlive does not stamp twice within the gap', $natAuth->keepAlive('anna'), false);
checkSame('Native keepAlive leaves a pending user alone', $natAuth->keepAlive('cleo'), false);
checkSame('Native keepAlive leaves a locked user alone', $natAuth->keepAlive('dave'), false);
checkSame('Native keepAlive on an unknown user', $natAuth->keepAlive('zed'), false);
checkSame('Native only the stamped user is online', $natAuth->onlineNow(), Array('anna'));

$sqlLink->exec("UPDATE users SET lastSeen='".date('Y-m-d H:i:s', time()-120)."' WHERE userName='anna'");
checkSame('Native seen two minutes ago: still online with the default idle time', $natAuth->onlineNow(), Array('anna'));
$shrtNat = new Native( Array('idletm'=>90) );
checkSame('Native seen two minutes ago: not online with a 90 second idle time', $shrtNat->onlineNow(), Array());
checkSame('Native keepAlive stamps again once the gap is over', $natAuth->keepAlive('anna'), true);
checkSame('Native online again after the new stamp', $shrtNat->onlineNow(), Array('anna'));
checkSame('Native presence on a missing table', Array($noTable->keepAlive('anna'), $noTable->onlineNow()), Array(false, false));

// Checker : a verified request stamps the user when the credential store tracks presence
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>inTempDir('native.sqlite')),
	'access'=>Array('TESTNAT'=>Array('tblnme'=>'users'))
) );
$natConf = Array('needed'=>true, 'sessby'=>'hbasic', 'authby'=>'native', 'sesscf'=>'testnat');
$checker = new nyu\Access\Checker( $natConf, fakeReqst(Array('Authorization'=>'Basic '.base64_encode('ben:pw-ben'))) );
$axsRslt = $checker->verifyAxs();
checkSame('Checker with Native: granted with the stored role', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('user'), $axsRslt->readValue('role')), Array(200, 'ben', 'member'));
checkSame('Checker with Native: the verified user is online', $natAuth->onlineNow(), Array('anna', 'ben'));
$checker = new nyu\Access\Checker( $natConf, fakeReqst(Array('Authorization'=>'Basic '.base64_encode('cleo:pw-cleo'))) );
checkSame('Checker with Native: a pending user is refused', $checker->verifyAxs()->readValue('statCode'), 401);
checkSame('Checker with Native: a refused user is not online', $natAuth->onlineNow(), Array('anna', 'ben'));

// userIdent : the current identity of a user known by other means
checkSame('Native userIdent of an active user', $natAuth->userIdent('anna'), Array('user'=>'anna', 'role'=>'manage', 'id'=>1));
checkSame('Native userIdent of a pending user', Array($natAuth->userIdent('cleo'), $natAuth->lastError()), Array(false, 'Account not active'));
checkSame('Native userIdent of an unknown user', Array($natAuth->userIdent('zed'), $natAuth->lastError()), Array(false, 'Account not active'));
checkSame('Native userIdent without a name', $natAuth->userIdent(''), false);
checkSame('Native userIdent on a missing table', Array($noTable->userIdent('anna'), $noTable->lastError()), Array(false, 'Credential store unavailable'));

// Checker : a token is confirmed against the users table when the preset names it as the credential store
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>inTempDir('native.sqlite')),
	'access'=>Array('TESTNAT'=>Array('tblnme'=>'users'), 'TESTJWT'=>Array('secret'=>str_repeat('s', 40)), 'OTHERTB'=>Array('tblnme'=>'nousers'))
) );
$jwtSess = new nyu\Access\Sess\JWT( Array('secret'=>str_repeat('s', 40)) );
$annaHed = Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'anna', 'role'=>'member')));
$tknConf = Array('needed'=>true, 'sessby'=>'jtoken', 'sesscf'=>'testjwt', 'authby'=>'native', 'authcf'=>'testnat');

$axsRslt = (new nyu\Access\Checker( $tknConf, fakeReqst($annaHed) ))->verifyAxs();
checkSame('Checker token with a store: the role comes from the table, not from the token', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('user'), $axsRslt->readValue('role')), Array(200, 'anna', 'manage'));

$sqlLink->exec("UPDATE users SET userStat='locked' WHERE userName='anna'");
$axsRslt = (new nyu\Access\Checker( $tknConf, fakeReqst($annaHed) ))->verifyAxs();
checkSame('Checker token with a store: a locked user is refused at once', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg')), Array(401, 'Account not active'));

$axsRslt = (new nyu\Access\Checker( Array('needed'=>true, 'sessby'=>'jtoken', 'sesscf'=>'testjwt'), fakeReqst($annaHed) ))->verifyAxs();
checkSame('Checker token without a store: the token alone decides', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('role')), Array(200, 'member'));
$sqlLink->exec("UPDATE users SET userStat='active' WHERE userName='anna'");

$zedHead = Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'zed')));
checkSame('Checker token with a store: a user missing from the table is refused', (new nyu\Access\Checker( $tknConf, fakeReqst($zedHead) ))->verifyAxs()->readValue('statCode'), 401);

$axsRslt = (new nyu\Access\Checker( Array('authcf'=>'othertb') + $tknConf, fakeReqst($annaHed) ))->verifyAxs();
checkSame('Checker authcf names the block of the store', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg')), Array(401, 'Credential store unavailable'));

// Personal API tokens : only the SHA-256 hash is stored, one token per user
$annaTkn = $natAuth->makeToken('anna');
checkLike('Native makeToken gives a 64 character token', $annaTkn, '/^[0-9a-f]{64}$/');
checkSame('Native the token itself is not stored', $sqlLink->query("SELECT tokenKey FROM users WHERE userName='anna'")->fetchColumn(), hash('sha256', strval($annaTkn)));
checkSame('Native token accepted, with the user and role', $natAuth->checkCred(Array('key'=>$annaTkn)), Array('user'=>'anna', 'role'=>'manage', 'id'=>1));
checkSame('Native wrong token',          Array($natAuth->checkCred(Array('key'=>str_repeat('0', 64))), $natAuth->lastError()), Array(false, 'Invalid API key'));
checkSame('Native empty token',          Array($natAuth->checkCred(Array('key'=>'')), $natAuth->lastError()), Array(false, 'No API key provided'));
checkSame('Native the stored hash is not a token', $natAuth->checkCred(Array('key'=>hash('sha256', strval($annaTkn)))), false);
$nextTkn = $natAuth->makeToken('anna');
checkSame('Native a new token replaces the previous one', Array($natAuth->checkCred(Array('key'=>$annaTkn)), $natAuth->checkCred(Array('key'=>$nextTkn))['user'] ?? null), Array(false, 'anna'));
checkSame('Native makeToken refused for a pending user', $natAuth->makeToken('cleo'), false);
checkSame('Native makeToken refused for an unknown user', $natAuth->makeToken('zed'), false);
$sqlLink->exec("UPDATE users SET userStat='locked' WHERE userName='anna'");
checkSame('Native token of a locked user', Array($natAuth->checkCred(Array('key'=>$nextTkn)), $natAuth->lastError()), Array(false, 'Invalid API key'));
$sqlLink->exec("UPDATE users SET userStat='active' WHERE userName='anna'");
checkSame('Native token on a missing table', Array($noTable->checkCred(Array('key'=>$nextTkn)), $noTable->lastError(), $noTable->makeToken('anna')), Array(false, 'Credential store unavailable', false));

// Checker : the token travels in the API key header and identifies its user
$keyConf = Array('needed'=>true, 'sessby'=>'header', 'authby'=>'native', 'sesscf'=>'testnat');
$axsRslt = (new nyu\Access\Checker( $keyConf, fakeReqst(Array('X-Api-Key'=>$nextTkn)) ))->verifyAxs();
checkSame('Checker personal token: user and role', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('user'), $axsRslt->readValue('role')), Array(200, 'anna', 'manage'));
$axsRslt = (new nyu\Access\Checker( $keyConf, fakeReqst(Array('X-Api-Key'=>'nope')) ))->verifyAxs();
checkSame('Checker personal token: wrong token', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg')), Array(401, 'Invalid API key'));

// 2FA : a user with a secret must give the current code of its authenticator with the password
checkSame('Native 2FA: no secret, no code asked', $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben'))['user'] ?? null, 'ben');
$benScrt = $natAuth->makeTwoFa('ben');
checkLike('Native makeTwoFa gives a base32 secret', $benScrt, '/^[A-Z2-7]{32}$/');
checkSame('Native 2FA: password alone', Array($natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben')), $natAuth->lastError()), Array(false, 'Verification code required'));
checkSame('Native 2FA: wrong code',     Array($natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>'000000')), $natAuth->lastError()), Array(false, 'Invalid verification code'));
checkSame('Native 2FA: valid code',     $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTme(strval($benScrt), time()))), Array('user'=>'ben', 'role'=>'member', 'id'=>2));
// A code is accepted once : its step is remembered, and nothing of that step or before passes again
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('logs')) );
checkSame('Native 2FA: the same code a second time', Array($natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTme(strval($benScrt), time()))), $natAuth->lastError(), $natAuth->wrongCred()), Array(false, 'Invalid verification code', true));
nyu\Tools\Logger::plugSetup( Array() );
checkLike('Native 2FA: the replay is told in the access log', strval(@file_get_contents(inTempDir('logs').DS.'access.log')), '/\[WARNING\] Verification code refused: already used by user "ben"/');
checkSame('Native 2FA: the replay counts as a failed attempt', failsOf($sqlLink, 'ben'), 1);
$sqlLink->exec("UPDATE users SET twoFaStp=".(intdiv(time(), 30)+5)." WHERE userName='ben'");
checkSame('Native 2FA: a code older than the last one accepted', $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTme(strval($benScrt), time()))), false);
$benScrt = $natAuth->makeTwoFa('ben');
checkSame('Native makeTwoFa forgets the last step', $sqlLink->query("SELECT twoFaStp FROM users WHERE userName='ben'")->fetchColumn(), null);
checkSame('Native 2FA: the code of the new secret', $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTme(strval($benScrt), time())))['user'] ?? null, 'ben');
checkSame('Native 2FA: its step is remembered', intval($sqlLink->query("SELECT twoFaStp FROM users WHERE userName='ben'")->fetchColumn())>=intdiv(time(), 30)-1, true);
// A users table made before the step was remembered : a user with 2FA is refused until the table is upgraded
$sqlLink->exec("CREATE TABLE oldusers AS SELECT id_user, userName, passHash, userRole, userStat, actvCode, tokenKey, twoFaKey, failNmbr, failDate, madeDate, lastSeen FROM users");
$oldAuth = new Native( Array('tblnme'=>'oldusers') );
checkSame('Native 2FA: a table without the step column', Array(runSafely(function() use ($oldAuth, $benScrt){ return $oldAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTme(strval($benScrt), time()))); }), $oldAuth->lastError(), $oldAuth->wrongCred()), Array(false, 'Credential store unavailable', false));
checkSame('Native 2FA: a table without the step column, user without a secret', $oldAuth->checkCred(Array('user'=>'anna', 'pass'=>'pw-anna'))['user'] ?? null, 'anna');
$sqlLink->exec("DROP TABLE oldusers");
// The secret is stored encrypted when the block gives a key, and readable otherwise, which is logged
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('logs')) );
$benScrt = $natAuth->makeTwoFa('ben');
$keyAuth = new Native( Array('tblnme'=>'users', 'tfakey'=>str_repeat('k', 40)) );
$annaSct = strval($keyAuth->makeTwoFa('anna'));
$annaCod = Array('user'=>'anna', 'pass'=>'pw-anna', 'code'=>nyu\Access\Totp::codeAtTme($annaSct, time()));
checkLike('Native 2FA: with a key, the secret is stored encrypted', strval($sqlLink->query("SELECT twoFaKey FROM users WHERE userName='anna'")->fetchColumn()), '/^enc1:[A-Za-z0-9+\/=]{80}$/D');
checkLike('Native 2FA: the secret given back is the one the authenticator takes', $annaSct, '/^[A-Z2-7]{32}$/');
checkSame('Native 2FA: without the key, the user is refused as a store that cannot be read', Array($natAuth->checkCred($annaCod), $natAuth->lastError(), $natAuth->wrongCred()), Array(false, 'Credential store unavailable', false));
checkSame('Native 2FA: with another key too', Array((new Native(Array('tfakey'=>str_repeat('x', 40))))->checkCred($annaCod), failsOf($sqlLink, 'anna')), Array(false, 0));
checkSame('Native 2FA: with the key, the code passes', $keyAuth->checkCred($annaCod)['user'] ?? null, 'anna');
checkSame('Native 2FA: a secret stored readable is still read with a key set', $keyAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben'))!==false || $keyAuth->lastError()=='Verification code required', true);
$sqlLink->exec("UPDATE users SET twoFaKey=NULL, twoFaStp=NULL WHERE userName='anna'");
nyu\Tools\Logger::plugSetup( Array() );
$logText = strval(@file_get_contents(inTempDir('logs').DS.'access.log'));
checkLike('Native 2FA: a secret that cannot be read is told in the access log', $logText, '/\[ERROR\] 2FA secret of user "anna" cannot be read: tfakey is missing or was changed/');
checkSame('Native 2FA: a secret stored readable is told in the access log, an encrypted one is not', Array(preg_match('/\[WARNING\] 2FA secret of user "ben" stored readable: no tfakey of 32 characters is set/', $logText), preg_match('/secret of user "anna" stored readable/', $logText)), Array(1, 0));
checkSame('Native 2FA: wrong password, the code is not asked for', Array($natAuth->checkCred(Array('user'=>'ben', 'pass'=>'nope')), $natAuth->lastError()), Array(false, 'Invalid credentials'));
checkSame('Native 2FA: valid code, wrong password', $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'nope', 'code'=>nyu\Access\Totp::codeAtTme(strval($benScrt), time()))), false);
checkSame('Native makeTwoFa refused for a pending user', $natAuth->makeTwoFa('cleo'), false);
checkSame('Native 2FA: a personal token is not asked for a code', $natAuth->checkCred(Array('key'=>$natAuth->makeToken('ben')))['user'] ?? null, 'ben');

// Attempt throttling : 3 failures hold the account for 600 seconds by default
/**
 * Reads the failed attempts counted for a user.
 *
 * @param PDO    $sqliteLink Connection to the sample database.
 * @param String $userString Login name of the user.
 * @return Int The value of failNmbr.
 */
function failsOf( PDO $sqliteLink, String $userString ): Int {
	return intval( $sqliteLink->query("SELECT failNmbr FROM users WHERE userName='".$userString."'")->fetchColumn() );
}
$annaPwd = Array('user'=>'anna', 'pass'=>'pw-anna');
$annaBad = Array('user'=>'anna', 'pass'=>'nope');
$sqlLink->exec("UPDATE users SET failNmbr=0, failDate=NULL");
$thrAuth = new Native( Array('tblnme'=>'users', 'maxtry'=>3) );
$thrAuth->checkCred($annaBad);
$thrAuth->checkCred($annaBad);
checkSame('Throttle two failures are counted', failsOf($sqlLink, 'anna'), 2);
checkSame('Throttle two failures: the right password still passes', $thrAuth->checkCred($annaPwd)['user'] ?? null, 'anna');
checkSame('Throttle a success forgets the failures', failsOf($sqlLink, 'anna'), 0);
$thrAuth->checkCred($annaBad);
$thrAuth->checkCred($annaBad);
checkSame('Throttle the third failure is still told as a wrong password', Array($thrAuth->checkCred($annaBad), $thrAuth->lastError()), Array(false, 'Invalid credentials'));
// The hold is told in the log only : the reply is the one of a wrong password
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('logs')) );
checkSame('Throttle held: the right password is refused, as a wrong one would be', Array($thrAuth->checkCred($annaPwd), $thrAuth->lastError(), $thrAuth->wrongCred()), Array(false, 'Invalid credentials', true));
checkSame('Throttle held: a wrong password is refused the same way', Array($thrAuth->checkCred($annaBad), $thrAuth->lastError()), Array(false, 'Invalid credentials'));
nyu\Tools\Logger::plugSetup( Array() );
checkLike('Throttle held: the hold is told in the access log, with the name', strval(@file_get_contents(inTempDir('logs').DS.'access.log')), '/\[WARNING\] Credential refused: user "anna" is held after too many attempts/');
checkSame('Throttle held: nothing more is counted', failsOf($sqlLink, 'anna'), 3);
$sqlLink->exec("UPDATE users SET twoFaStp=NULL WHERE userName='ben'");
checkSame('Throttle held: other users are not affected', $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>nyu\Access\Totp::codeAtTme(strval($benScrt), time())))['user'] ?? null, 'ben');
checkSame('Throttle held: a personal token still passes', $natAuth->checkCred(Array('key'=>$nextTkn))['user'] ?? null, 'anna');

$sqlLink->exec("UPDATE users SET failDate='".date('Y-m-d H:i:s', time()-601)."' WHERE userName='anna'");
checkSame('Throttle the hold is over after its time', $natAuth->checkCred($annaPwd)['user'] ?? null, 'anna');
checkSame('Throttle the count is forgotten once back in', failsOf($sqlLink, 'anna'), 0);

$sqlLink->exec("UPDATE users SET failNmbr=2, failDate='".date('Y-m-d H:i:s', time()-601)."' WHERE userName='anna'");
$natAuth->checkCred($annaBad);
checkSame('Throttle old failures expire: the count restarts at 1', failsOf($sqlLink, 'anna'), 1);

$oneTry = new Native( Array('maxtry'=>1, 'holdtm'=>60) );
$sqlLink->exec("UPDATE users SET failNmbr=0, failDate=NULL");
$oneTry->checkCred($annaBad);
checkSame('Throttle maxtry is read from the config', Array($oneTry->checkCred($annaPwd), $oneTry->lastError()), Array(false, 'Invalid credentials'));
$sqlLink->exec("UPDATE users SET failDate='".date('Y-m-d H:i:s', time()-120)."' WHERE userName='anna'");
checkSame('Throttle holdtm is read from the config', $oneTry->checkCred($annaPwd)['user'] ?? null, 'anna');
checkSame('Throttle by default, four failures do not hold', Array($sqlLink->exec("UPDATE users SET failNmbr=4, failDate='".date('Y-m-d H:i:s', time()-120)."' WHERE userName='anna'"), $natAuth->checkCred($annaPwd)['user'] ?? null), Array(1, 'anna'));
checkSame('Throttle by default, five failures hold, and the same failure still holds with the default time', Array($sqlLink->exec("UPDATE users SET failNmbr=5, failDate='".date('Y-m-d H:i:s', time()-120)."' WHERE userName='anna'"), $natAuth->checkCred($annaPwd)), Array(1, false));

// 2FA and throttling : a missing code is not a failure, a wrong code is
$sqlLink->exec("UPDATE users SET failNmbr=0, failDate=NULL");
$natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben'));
checkSame('Throttle a missing code is not counted', failsOf($sqlLink, 'ben'), 0);
$natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>'000000'));
checkSame('Throttle a wrong code is counted', failsOf($sqlLink, 'ben'), 1);
checkSame('Throttle an unknown user is not counted and raises nothing', Array($natAuth->checkCred(Array('user'=>'zed', 'pass'=>'nope')), $natAuth->lastError()), Array(false, 'Invalid credentials'));

// Passwords : setPasswd replaces the hash and forgets the failures, whatever the status
// The sample passwords are short : the shortest length is brought down to theirs
$lowAuth = new Native( Array('tblnme'=>'users', 'minlen'=>6) );
$sqlLink->exec("UPDATE users SET failNmbr=2, failDate='".date('Y-m-d H:i:s')."' WHERE userName='anna'");
checkSame('Native setPasswd on a known user', $lowAuth->setPasswd('anna', 'new-anna'), true);
checkSame('Native setPasswd: the new password is accepted', $natAuth->checkCred(Array('user'=>'anna', 'pass'=>'new-anna'))['user'] ?? null, 'anna');
checkSame('Native setPasswd: the previous password is refused', Array($natAuth->checkCred($annaPwd), $natAuth->lastError()), Array(false, 'Invalid credentials'));
$sqlLink->exec("UPDATE users SET failNmbr=2, failDate='".date('Y-m-d H:i:s')."' WHERE userName='anna'");
checkSame('Native setPasswd forgets the failures', Array($lowAuth->setPasswd('anna', 'pw-anna'), failsOf($sqlLink, 'anna')), Array(true, 0));
checkSame('Native setPasswd leaves the status as it is', Array($lowAuth->setPasswd('cleo', 'new-cleo'), $natAuth->checkCred(Array('user'=>'cleo', 'pass'=>'new-cleo')), $natAuth->lastError()), Array(true, false, 'Invalid credentials'));
checkSame('Native setPasswd on an unknown user', $lowAuth->setPasswd('zed', 'pw-zed'), false);
checkSame('Native setPasswd refuses an empty password', Array($lowAuth->setPasswd('anna', ''), $natAuth->checkCred($annaPwd)['user'] ?? null), Array(false, 'anna'));
checkSame('Native setPasswd with a name holding a quote', $lowAuth->setPasswd("anna' OR '1'='1", 'nopass'), false);
checkSame('Native setPasswd on a missing table', $noTable->setPasswd('anna', 'pw-anna-long'), false);

// Password rules : 10 characters by default, 72 bytes at most, no control character, not the user name
checkSame('Rules nine characters are too short by default', Array($natAuth->setPasswd('anna', 'ninechars'), $natAuth->lastError()), Array(false, 'Password too short: 10 characters at least'));
checkSame('Rules ten characters pass, whatever they are', $natAuth->setPasswd('anna', 'aaaaaaaaaa'), true);
checkSame('Rules characters are counted, not bytes', Array($natAuth->setPasswd('anna', 'ééééé'), $natAuth->setPasswd('anna', 'éééééééééé')), Array(false, true));
checkSame('Rules 72 bytes pass', $natAuth->setPasswd('anna', str_repeat('a', 72)), true);
checkSame('Rules 73 bytes are refused', Array($natAuth->setPasswd('anna', str_repeat('a', 73)), $natAuth->lastError()), Array(false, 'Password too long: 72 bytes at most'));
checkSame('Rules a control character is refused', Array($natAuth->setPasswd('anna', "with\0a nul byte"), $natAuth->setPasswd('anna', "with a\nline break"), $natAuth->lastError()), Array(false, false, 'Password holding a control character'));
checkSame('Rules the user name is refused, whatever the case', Array((new Native(Array('minlen'=>4)))->setPasswd('anna', 'ANNA'), $lowAuth->setPasswd('anna', 'pw-anna')), Array(false, true));
checkSame('Rules a password is kept as given, spaces and quotes included', Array($natAuth->setPasswd('anna', '  it\'s "<b>" &  '), $natAuth->checkCred(Array('user'=>'anna', 'pass'=>'  it\'s "<b>" &  '))['user'] ?? null, $natAuth->checkCred(Array('user'=>'anna', 'pass'=>'it\'s "<b>" &'))), Array(true, 'anna', false));
checkSame('Rules a refused password changes nothing', Array($natAuth->setPasswd('anna', 'short'), $natAuth->checkCred(Array('user'=>'anna', 'pass'=>'  it\'s "<b>" &  '))['user'] ?? null), Array(false, 'anna'));
$sqlLink = null;

endChecks();
