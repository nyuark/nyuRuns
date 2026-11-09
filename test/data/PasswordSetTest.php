<?php

// Access\Auth\Native and UserBase : a password set ends the sessions and
// tokens issued before it and takes the personal token away (passDate);
// an unknown name or an account without a password is checked against the
// dummy hash; a hash of a lower cost is made again after a good login.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	skipCheck('Password set', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Access\Auth\Native;
use nyu\Access\Auth\PassList;
use nyu\Access\Auth\UserBase;
use nyu\Access\Auth\UserList;
use nyu\Access\Sess\JWT;
use Firebase\JWT\JWT as JwtCodec;

$sqlFile = inTempDir('passset.sqlite');
buildData( $sqlFile );
useSqlite( $sqlFile );
$sqlLink = new PDO('sqlite:'.$sqlFile);
$natConf = Array('tblnme'=>'users', 'minlen'=>8, 'maxtry'=>50);
$usrBase = new UserBase( $natConf );
$natAuth = new Native( $natConf );
$jwtKeys = Array(str_repeat('s', 40), 'HS256');
$jwtSess = new JWT( Array('secret'=>$jwtKeys[0]), $natAuth );

/**
 * Makes a token of a user, issued some seconds ago.
 *
 * @param String $userString The user.
 * @param Int    $secondsAgo When it was issued.
 * @return String The token.
 */
function pastTokn( String $userString, Int $secondsAgo ): String {
	global $jwtKeys;
	return JwtCodec::encode( Array('user'=>$userString, 'iat'=>time() - $secondsAgo, 'exp'=>time() + 600), ...$jwtKeys );
}

/**
 * Tells what the token carrier answers to a token.
 *
 * @param String $tokenValue The token.
 * @return Mixed The user, or false with the message.
 */
function tokenFor( String $tokenValue ): Mixed {
	global $jwtSess;
	$idntArr = $jwtSess->verifyReq( fakeReqst(Array('Authorization'=>'Bearer '.$tokenValue)) );
	return is_array($idntArr) ? ($idntArr['user'] ?? null) : Array(false, $jwtSess->lastError());
}

/**
 * Reads a value of a user's row.
 *
 * @param String $userString The user.
 * @param String $fieldNamed The field.
 * @return Mixed The value.
 */
function fieldOf( String $userString, String $fieldNamed ): Mixed {
	global $sqlLink;
	return $sqlLink->query("SELECT ".$fieldNamed." FROM users WHERE userName='".$userString."'")->fetchColumn();
}

// M2 : a password changed ends the sessions and tokens issued before it, the one that changed it included
$benTkn1 = pastTokn('ben', 5);
$benTkn2 = pastTokn('ben', 3);
checkSame('two tokens of the same user pass before the change', Array(tokenFor($benTkn1), tokenFor($benTkn2)), Array('ben', 'ben'));
$natAuth->makeToken('ben');
checkSame('chgPasswd: the password is changed', $usrBase->chgPasswd('ben', 'pw-ben', 'new-pass-ben'), true);
checkSame('chgPasswd: both tokens are refused, told to sign in again', Array(tokenFor($benTkn1), tokenFor($benTkn2)), Array(Array(false, 'Password changed: sign in again'), Array(false, 'Password changed: sign in again')));
checkSame('chgPasswd: the personal token is taken away, the time is stored', Array(fieldOf('ben', 'tokenKey'), abs(strtotime(strval(fieldOf('ben', 'passDate'))) - time())<5), Array(null, true));
checkSame('a token issued after the change passes', tokenFor($jwtSess->issueTokn(Array('user'=>'ben'))), 'ben');
checkSame('userIdent: a session started before is refused, one started after passes, none to compare passes', Array($natAuth->userIdent('ben', time() - 10), $natAuth->userIdent('ben', time() + 1)['user'] ?? null, $natAuth->userIdent('ben')['user'] ?? null), Array(false, 'ben', 'ben'));
// A reset asked by the user, used with its code
$natAuth->makeToken('anna');
$sqlLink->exec("UPDATE users SET userMail='anna@example.ch' WHERE userName='anna'");
$rsetVal = $usrBase->resetPass('anna');
$annaTkn = pastTokn('anna', 5);
checkSame('recovUser: the password is set with the code', $usrBase->recovUser('anna', strval($rsetVal['code'] ?? ''), 'new-pass-anna'), true);
checkSame('recovUser: the tokens before and the personal token are refused', Array(tokenFor($annaTkn), fieldOf('anna', 'tokenKey'), $natAuth->checkCred(Array('key'=>'any'))), Array(Array(false, 'Password changed: sign in again'), null, false));
// A password given by the command line, and a reset by a manager
$thrdTkn = pastTokn('ben', 5);
checkSame('setPasswd: the tokens before are refused too', Array($natAuth->setPasswd('ben', 'third-pass-ben'), tokenFor($thrdTkn)), Array(true, Array(false, 'Password changed: sign in again')));
checkSame('resetUser: the time is stored', Array(is_string($usrBase->resetUser('ben')), abs(strtotime(strval(fieldOf('ben', 'passDate'))) - time())<5), Array(true, true));
// A table without the column
$sqlLink->exec("CREATE TABLE oldusers AS SELECT id_user, userName, passHash, userRole, userStat, tokenKey, failNmbr, failDate, lastSeen FROM users");
$oldAuth = new Native( Array('tblnme'=>'oldusers', 'minlen'=>8) );
checkSame('a table without passDate: sessions are compared with nothing, a password cannot be written', Array($oldAuth->userIdent('anna', time() - 10)['user'] ?? null, runSafely(function() use ( $oldAuth ){ return $oldAuth->setPasswd('anna', 'pass-of-anna'); })), Array('anna', false));
$sqlLink->exec("DROP TABLE oldusers");

// M3 : the dummy hash, for an unknown name and for an account without a password
$fakProp = new ReflectionProperty(Native::class, 'fakeHash');
$fakProp->setValue(null, null);
$natAuth->checkCred( Array('user'=>'zed', 'pass'=>'whatever') );
$dmyHash = $fakProp->getValue();
checkSame('an unknown name is checked against the dummy hash, made at the cost of new hashes', Array(is_string($dmyHash), password_get_info(strval($dmyHash))['options']['cost'] ?? null), Array(true, password_get_info(password_hash('x', PASSWORD_BCRYPT))['options']['cost'] ?? null));
$fakProp->setValue(null, null);
$sqlLink->exec("UPDATE users SET passHash='!' WHERE userName='cleo'");
checkSame('an account without a password is checked against it too', Array($natAuth->checkCred(Array('user'=>'cleo', 'pass'=>'!')), is_string($fakProp->getValue())), Array(false, true));
$fakProp->setValue(null, null);
(new UserList( Array('usrfle'=>'test/fixtures/users.lst', 'maxtry'=>0) ))->checkCred( Array('user'=>'zed', 'pass'=>'x') );
$lstUsed = is_string($fakProp->getValue());
$fakProp->setValue(null, null);
(new PassList( Array('htpasswd'=>'test/fixtures/users.htpasswd', 'maxtry'=>0) ))->checkCred( Array('user'=>'zed', 'pass'=>'x') );
checkSame('the users file and the htpasswd file use the same one', Array($lstUsed, is_string($fakProp->getValue())), Array(true, true));
checkSame('one dummy hash for the process', Native::dummyHash(), Native::dummyHash());
// The rehash after a good login
$natAuth->setPasswd('anna', 'pw-anna-cost');
$sqlLink->exec("UPDATE users SET passHash='".password_hash('pw-anna-cost', PASSWORD_BCRYPT, Array('cost'=>4))."' WHERE userName='anna'");
$befDate = fieldOf('anna', 'passDate');
checkSame('a good login', $natAuth->checkCred(Array('user'=>'anna', 'pass'=>'pw-anna-cost'))['user'] ?? null, 'anna');
$newHash = strval(fieldOf('anna', 'passHash'));
checkSame('a hash of a lower cost is made again at the current cost, the time of the password kept', Array(password_get_info($newHash)['options']['cost'] ?? null, password_verify('pw-anna-cost', $newHash), fieldOf('anna', 'passDate')===$befDate), Array(password_get_info(password_hash('x', PASSWORD_BCRYPT))['options']['cost'] ?? null, true, true));
checkSame('a wrong password rehashes nothing', (function() use ( $natAuth, $sqlLink, $newHash ){ $sqlLink->exec("UPDATE users SET passHash='".password_hash('pw-anna-cost', PASSWORD_BCRYPT, Array('cost'=>4))."' WHERE userName='anna'"); $natAuth->checkCred(Array('user'=>'anna', 'pass'=>'nope')); return password_get_info(strval(fieldOf('anna', 'passHash')))['options']['cost'] ?? null; })(), 4);

endChecks();
