<?php

// Access\Auth\Native and UserBase : a password set ends the sessions and
// tokens issued before it and takes the personal token away (passwdDate);
// an unknown name or an account without a password is checked against the
// dummy hash; a hash of a lower cost is made again after a good login.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Password set', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Access\Auth\Native;
use nyu\Access\Auth\PassList;
use nyu\Access\Auth\UserBase;
use nyu\Access\Auth\UserList;
use nyu\Access\Carr\JWT;
use Firebase\JWT\JWT as JwtCodec;

$sqlFile = inTemporaryDir('passset.sqlite');
createSampleDb( $sqlFile );
switchToSqlite( $sqlFile );
$sqlLink = new PDO('sqlite:'.$sqlFile);
$natConf = Array('useTable'=>'users', 'minChars'=>8, 'maxTries'=>50);
$usrBase = new UserBase( $natConf );
$natAuth = new Native( $natConf );
$jwtKeys = Array(str_repeat('s', 40), 'HS256');
$jwtSess = new JWT( Array('hmacSecr'=>$jwtKeys[0]), $natAuth );

/**
 * Makes a token of a user, issued some seconds ago.
 *
 * @param String $userString The user.
 * @param Int    $secondsAgo When it was issued.
 * @return String The token.
 */
function pastTokn( String $userString, Int $secondsAgo ): String {
	global $jwtKeys;
	return JwtCodec::encode( Array('userName'=>$userString, 'iat'=>time() - $secondsAgo, 'exp'=>time() + 600), ...$jwtKeys );
}

/**
 * Tells what the token carrier answers to a token.
 *
 * @param String $tokenValue The token.
 * @return Mixed The user, or false with the message.
 */
function tokenFor( String $tokenValue ): Mixed {
	global $jwtSess;
	$idntArr = $jwtSess->checkReqstDict( mockRequestObj(Array('Authorization'=>'Bearer '.$tokenValue)) );
	return is_array($idntArr) ? ($idntArr['userName'] ?? null) : Array(false, $jwtSess->getLastErrText());
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
	return $sqlLink->query("SELECT ".$fieldNamed." FROM users WHERE systemName='".$userString."'")->fetchColumn();
}

// M2 : a password changed ends the sessions and tokens issued before it, the one that changed it included
$benTkn1 = pastTokn('ben', 5);
$benTkn2 = pastTokn('ben', 3);
checkIdentical('two tokens of the same user pass before the change', Array(tokenFor($benTkn1), tokenFor($benTkn2)), Array('ben', 'ben'));
$natAuth->makeToknString('ben');
checkIdentical('chgPasswd: the password is changed', $usrBase->alterPassByOld('ben', 'pw-ben', 'new-pass-ben'), true);
checkIdentical('chgPasswd: both tokens are refused, told to sign in again', Array(tokenFor($benTkn1), tokenFor($benTkn2)), Array(Array(false, 'Password changed: sign in again'), Array(false, 'Password changed: sign in again')));
checkIdentical('chgPasswd: the personal token is taken away, the time is stored', Array(fieldOf('ben', 'bearerHash'), abs(strtotime(strval(fieldOf('ben', 'passwdDate'))) - time())<5), Array(null, true));
checkIdentical('a token issued after the change passes', tokenFor($jwtSess->issueTokenText(Array('userName'=>'ben'))), 'ben');
checkIdentical('userIdent: a session started before is refused, one started after passes, none to compare passes', Array($natAuth->readIdntOfUser('ben', time() - 10), $natAuth->readIdntOfUser('ben', time() + 1)['userName'] ?? null, $natAuth->readIdntOfUser('ben')['userName'] ?? null), Array(false, 'ben', 'ben'));
// A reset asked by the user, used with its code
$natAuth->makeToknString('anna');
$sqlLink->exec("UPDATE users SET contactEml='anna@example.ch' WHERE systemName='anna'");
$rsetVal = $usrBase->makeRecovrCode('anna');
$annaTkn = pastTokn('anna', 5);
checkIdentical('recovUser: the password is set with the code', $usrBase->recoverAccount('anna', strval($rsetVal['code'] ?? ''), 'new-pass-anna'), true);
checkIdentical('recovUser: the tokens before and the personal token are refused', Array(tokenFor($annaTkn), fieldOf('anna', 'bearerHash'), $natAuth->checkSecretDct(Array('key'=>'any'))), Array(Array(false, 'Password changed: sign in again'), null, false));
// A password given by the command line, and a reset by a manager
$thrdTkn = pastTokn('ben', 5);
checkIdentical('setPasswd: the tokens before are refused too', Array($natAuth->savePassOfUser('ben', 'third-pass-ben'), tokenFor($thrdTkn)), Array(true, Array(false, 'Password changed: sign in again')));
$befHash = fieldOf('ben', 'passwdHash');
checkIdentical('resetUser: the time is stored, and the hash changed (the login marks made before end with it)', Array(is_string($usrBase->clearUserAccnt('ben')), abs(strtotime(strval(fieldOf('ben', 'passwdDate'))) - time())<5, fieldOf('ben', 'passwdHash')!==$befHash), Array(true, true, true));
// A table without the column
$sqlLink->exec("CREATE TABLE oldusers AS SELECT id_user, systemName, passwdHash, accessRole, userStatus, bearerHash, failsCount, failedTime, accessDate FROM users");
$oldAuth = new Native( Array('useTable'=>'oldusers', 'minChars'=>8) );
checkIdentical('a table without passwdDate: sessions are compared with nothing, a password cannot be written', Array($oldAuth->readIdntOfUser('anna', time() - 10)['userName'] ?? null, runCatchThrown(function() use ( $oldAuth ){ return $oldAuth->savePassOfUser('anna', 'pass-of-anna'); })), Array('anna', false));
$sqlLink->exec("DROP TABLE oldusers");

// M3 : the dummy hash, for an unknown name and for an account without a password : a constant at the cost of new hashes (no hash made, see unit/FakeHashTest)
$dmyHash = Native::getDummyBcrypt();
checkIdentical('the dummy hash is a constant, at the cost of new hashes', Array(in_array($dmyHash, Native::FAKE_HASH, true), password_get_info($dmyHash)['options']['cost'] ?? null), Array(true, password_get_info(password_hash('x', PASSWORD_BCRYPT))['options']['cost'] ?? null));
$sqlLink->exec("UPDATE users SET passwdHash='!' WHERE systemName='cleo'");
checkIdentical('an unknown name and an account without a password are refused', Array($natAuth->checkSecretDct(Array('user'=>'zed', 'pass'=>'whatever')), $natAuth->checkSecretDct(Array('user'=>'cleo', 'pass'=>'!'))), Array(false, false));
// The rehash after a good login
$natAuth->savePassOfUser('anna', 'pw-anna-cost');
$sqlLink->exec("UPDATE users SET passwdHash='".password_hash('pw-anna-cost', PASSWORD_BCRYPT, Array('cost'=>4))."' WHERE systemName='anna'");
$befDate = fieldOf('anna', 'passwdDate');
checkIdentical('a good login', $natAuth->checkSecretDct(Array('user'=>'anna', 'pass'=>'pw-anna-cost'))['userName'] ?? null, 'anna');
$newHash = strval(fieldOf('anna', 'passwdHash'));
checkIdentical('a hash of a lower cost is made again at the current cost, the time of the password kept', Array(password_get_info($newHash)['options']['cost'] ?? null, password_verify('pw-anna-cost', $newHash), fieldOf('anna', 'passwdDate')===$befDate), Array(password_get_info(password_hash('x', PASSWORD_BCRYPT))['options']['cost'] ?? null, true, true));
checkIdentical('a wrong password rehashes nothing', (function() use ( $natAuth, $sqlLink, $newHash ){ $sqlLink->exec("UPDATE users SET passwdHash='".password_hash('pw-anna-cost', PASSWORD_BCRYPT, Array('cost'=>4))."' WHERE systemName='anna'"); $natAuth->checkSecretDct(Array('user'=>'anna', 'pass'=>'nope')); return password_get_info(strval(fieldOf('anna', 'passwdHash')))['options']['cost'] ?? null; })(), 4);

finishChecking();
