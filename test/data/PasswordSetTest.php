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
use nyu\Access\Sess\JWT;
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
	$idntArr = $jwtSess->verifyRqstList( mockRequestObj(Array('Authorization'=>'Bearer '.$tokenValue)) );
	return is_array($idntArr) ? ($idntArr['user'] ?? null) : Array(false, $jwtSess->finalErrorText());
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
	return $sqlLink->query("SELECT ".$fieldNamed." FROM users WHERE userHandle='".$userString."'")->fetchColumn();
}

// M2 : a password changed ends the sessions and tokens issued before it, the one that changed it included
$benTkn1 = pastTokn('ben', 5);
$benTkn2 = pastTokn('ben', 3);
checkIdentical('two tokens of the same user pass before the change', Array(tokenFor($benTkn1), tokenFor($benTkn2)), Array('ben', 'ben'));
$natAuth->makeToknString('ben');
checkIdentical('chgPasswd: the password is changed', $usrBase->editPasswdFlag('ben', 'pw-ben', 'new-pass-ben'), true);
checkIdentical('chgPasswd: both tokens are refused, told to sign in again', Array(tokenFor($benTkn1), tokenFor($benTkn2)), Array(Array(false, 'Password changed: sign in again'), Array(false, 'Password changed: sign in again')));
checkIdentical('chgPasswd: the personal token is taken away, the time is stored', Array(fieldOf('ben', 'bearerHash'), abs(strtotime(strval(fieldOf('ben', 'passwdDate'))) - time())<5), Array(null, true));
checkIdentical('a token issued after the change passes', tokenFor($jwtSess->issueTokenText(Array('user'=>'ben'))), 'ben');
checkIdentical('userIdent: a session started before is refused, one started after passes, none to compare passes', Array($natAuth->usrIdentityArr('ben', time() - 10), $natAuth->usrIdentityArr('ben', time() + 1)['user'] ?? null, $natAuth->usrIdentityArr('ben')['user'] ?? null), Array(false, 'ben', 'ben'));
// A reset asked by the user, used with its code
$natAuth->makeToknString('anna');
$sqlLink->exec("UPDATE users SET mailboxAdr='anna@example.ch' WHERE userHandle='anna'");
$rsetVal = $usrBase->resetPassArray('anna');
$annaTkn = pastTokn('anna', 5);
checkIdentical('recovUser: the password is set with the code', $usrBase->recoverUserFlg('anna', strval($rsetVal['code'] ?? ''), 'new-pass-anna'), true);
checkIdentical('recovUser: the tokens before and the personal token are refused', Array(tokenFor($annaTkn), fieldOf('anna', 'bearerHash'), $natAuth->chckCredential(Array('key'=>'any'))), Array(Array(false, 'Password changed: sign in again'), null, false));
// A password given by the command line, and a reset by a manager
$thrdTkn = pastTokn('ben', 5);
checkIdentical('setPasswd: the tokens before are refused too', Array($natAuth->savePasswdFlag('ben', 'third-pass-ben'), tokenFor($thrdTkn)), Array(true, Array(false, 'Password changed: sign in again')));
checkIdentical('resetUser: the time is stored', Array(is_string($usrBase->resetUsrString('ben')), abs(strtotime(strval(fieldOf('ben', 'passwdDate'))) - time())<5), Array(true, true));
// A table without the column
$sqlLink->exec("CREATE TABLE oldusers AS SELECT id_user, userHandle, passwdHash, accessRole, userStatus, bearerHash, failsCount, failedDate, accessDate FROM users");
$oldAuth = new Native( Array('useTable'=>'oldusers', 'minChars'=>8) );
checkIdentical('a table without passwdDate: sessions are compared with nothing, a password cannot be written', Array($oldAuth->usrIdentityArr('anna', time() - 10)['user'] ?? null, runCatchThrown(function() use ( $oldAuth ){ return $oldAuth->savePasswdFlag('anna', 'pass-of-anna'); })), Array('anna', false));
$sqlLink->exec("DROP TABLE oldusers");

// M3 : the dummy hash, for an unknown name and for an account without a password
$fakProp = new ReflectionProperty(Native::class, 'fakeBcrypt');
$fakProp->setValue(null, null);
$natAuth->chckCredential( Array('user'=>'zed', 'pass'=>'whatever') );
$dmyHash = $fakProp->getValue();
checkIdentical('an unknown name is checked against the dummy hash, made at the cost of new hashes', Array(is_string($dmyHash), password_get_info(strval($dmyHash))['options']['cost'] ?? null), Array(true, password_get_info(password_hash('x', PASSWORD_BCRYPT))['options']['cost'] ?? null));
$fakProp->setValue(null, null);
$sqlLink->exec("UPDATE users SET passwdHash='!' WHERE userHandle='cleo'");
checkIdentical('an account without a password is checked against it too', Array($natAuth->chckCredential(Array('user'=>'cleo', 'pass'=>'!')), is_string($fakProp->getValue())), Array(false, true));
$fakProp->setValue(null, null);
(new UserList( Array('listFile'=>'test/fixtures/users.lst', 'maxTries'=>0) ))->chckCredential( Array('user'=>'zed', 'pass'=>'x') );
$lstUsed = is_string($fakProp->getValue());
$fakProp->setValue(null, null);
(new PassList( Array('passFile'=>'test/fixtures/users.htpasswd', 'maxTries'=>0) ))->chckCredential( Array('user'=>'zed', 'pass'=>'x') );
checkIdentical('the users file and the htpasswd file use the same one', Array($lstUsed, is_string($fakProp->getValue())), Array(true, true));
checkIdentical('one dummy hash for the process', Native::getDummyBcrypt(), Native::getDummyBcrypt());
// The rehash after a good login
$natAuth->savePasswdFlag('anna', 'pw-anna-cost');
$sqlLink->exec("UPDATE users SET passwdHash='".password_hash('pw-anna-cost', PASSWORD_BCRYPT, Array('cost'=>4))."' WHERE userHandle='anna'");
$befDate = fieldOf('anna', 'passwdDate');
checkIdentical('a good login', $natAuth->chckCredential(Array('user'=>'anna', 'pass'=>'pw-anna-cost'))['user'] ?? null, 'anna');
$newHash = strval(fieldOf('anna', 'passwdHash'));
checkIdentical('a hash of a lower cost is made again at the current cost, the time of the password kept', Array(password_get_info($newHash)['options']['cost'] ?? null, password_verify('pw-anna-cost', $newHash), fieldOf('anna', 'passwdDate')===$befDate), Array(password_get_info(password_hash('x', PASSWORD_BCRYPT))['options']['cost'] ?? null, true, true));
checkIdentical('a wrong password rehashes nothing', (function() use ( $natAuth, $sqlLink, $newHash ){ $sqlLink->exec("UPDATE users SET passwdHash='".password_hash('pw-anna-cost', PASSWORD_BCRYPT, Array('cost'=>4))."' WHERE userHandle='anna'"); $natAuth->chckCredential(Array('user'=>'anna', 'pass'=>'nope')); return password_get_info(strval(fieldOf('anna', 'passwdHash')))['options']['cost'] ?? null; })(), 4);

finishChecking();
