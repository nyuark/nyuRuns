<?php

// Access\Limiter by account : the wrong credentials of each user of the
// users file (userFile) and of the htpasswd file (htpasswd) counted in the
// folder of the limits, the account held after 'maxTries' of them, released
// after 'holdSecs', cleared by a right login; the counters hold no name.

require __DIR__.'/../lib/harness.php';

use nyu\Access\Auth\PassList;
use nyu\Access\Auth\UserList;
use nyu\Access\Limiter;
use nyu\Tools\Logger;
use nyu\Tools\Registry;

$lmtFldr = 'temp'.DS.'acctlimits_'.getmypid();
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TRYLIMIT'=>Array('maxTries'=>0, 'dataPath'=>$lmtFldr))) );
$lstConf = Array('listFile'=>'test/fixtures/users.lst', 'maxTries'=>3, 'holdSecs'=>600);
$htpConf = Array('passFile'=>'test/fixtures/users.htpasswd', 'maxTries'=>3, 'holdSecs'=>600);

/**
 * Tells what a credential check answers.
 *
 * @param Object $storeObjct The check.
 * @param String $userString The name given.
 * @param String $passwdText The password given.
 * @return Array The user or false, the message and whether it counts against the address.
 */
function answerOf( Object $storeObjct, String $userString, String $passwdText ): Array {
	$idntArr = $storeObjct->checkSecretDct( Array('user'=>$userString, 'pass'=>$passwdText) );
	return Array( is_array($idntArr) ? ($idntArr['userName'] ?? null) : false, $storeObjct->getLastErrText(), $storeObjct->hasWrongSecret() );
}

// The limiter alone
$userLmt = new Limiter( Array('maxTries'=>2, 'holdSecs'=>100, 'dataPath'=>$lmtFldr) );
$userLmt->countLimitUser('anna', 1000);
checkIdentical('limiter: one failure does not hold the account', $userLmt->readUsrHoldEnd('anna', 1001), 0);
$userLmt->countLimitUser('anna', 1010);
checkIdentical('limiter: as many as allowed hold it for holdSecs after the last one', $userLmt->readUsrHoldEnd('anna', 1011), 1110);
checkIdentical('limiter: another account is not held, nor an address', Array($userLmt->readUsrHoldEnd('Anna', 1011), $userLmt->readAdrHoldEnd('127.0.0.1', 1011)), Array(0, 0));
$cntFles = glob(TOP_DIR.DS.$lmtFldr.DS.'*.usr') ?: Array();
checkIdentical('limiter: one counter, named by a hash, without the name', Array(count($cntFles), preg_match('/^[0-9a-f]{64}\.usr$/', basename(strval($cntFles[0] ?? ''))), strpos(strval(@file_get_contents(strval($cntFles[0] ?? ''))), 'anna')), Array(1, 1, false));
checkIdentical('limiter: the addresses listed are not the accounts', $userLmt->listLimitHolds(1011), Array());
checkIdentical('limiter: after the hold, the account is free, its counter removed', Array($userLmt->readUsrHoldEnd('anna', 1111), count(glob(TOP_DIR.DS.$lmtFldr.DS.'*.usr') ?: Array())), Array(0, 0));
$userLmt->countLimitUser('anna', 2000);
$userLmt->countLimitUser('anna', 2001);
$userLmt->clearLimitUser('anna');
checkIdentical('limiter: clearUser forgets the failures', $userLmt->readUsrHoldEnd('anna', 2002), 0);
$userLmt->countLimitUser('ben', 3000);
checkIdentical('limiter: dropStale and freeHolds remove the counters of the accounts too', Array($userLmt->dropStaleCount(3101), (function() use ( $userLmt ){ $userLmt->countLimitUser('ben'); return $userLmt->dropHoldsCount(); })()), Array(1, 1));
checkIdentical('limiter: maxTries 0 counts nothing', (function() use ( $lmtFldr ){ $noLimit = new Limiter(Array('maxTries'=>0, 'dataPath'=>$lmtFldr)); $noLimit->countLimitUser('anna'); return count(glob(TOP_DIR.DS.$lmtFldr.DS.'*.usr') ?: Array()); })(), 0);

// The users file
Logger::loadLogsConfig( Array('path'=>inTemporaryDir('logs')) );
$usrList = new UserList( $lstConf );
checkIdentical('userFile: two wrong passwords, then the right one: logged in', Array(answerOf($usrList, 'ben', 'x')[0], answerOf($usrList, 'ben', 'y')[0], answerOf($usrList, 'ben', 'pw-ben')[0]), Array(false, false, 'ben'));
checkIdentical('userFile: the right login cleared the failures', count(glob(TOP_DIR.DS.$lmtFldr.DS.'*.usr') ?: Array()), 0);
foreach( Array('x', 'y', 'z') as $wrngPw ){
	answerOf($usrList, 'ben', $wrngPw);
}
checkIdentical('userFile: after maxTries, the right password is refused as a wrong one, and counts against the address', answerOf($usrList, 'ben', 'pw-ben'), Array(false, 'Invalid credentials', true));
checkIdentical('userFile: another user is not held', answerOf($usrList, 'anna', 'pw-anna')[0], 'anna');
checkIdentical('userFile: an unknown name has no counter', (function() use ( $usrList, $lmtFldr ){ answerOf($usrList, 'zed', 'x'); return count(glob(TOP_DIR.DS.$lmtFldr.DS.'*.usr') ?: Array()); })(), 1);
checkIdentical('userFile: maxTries 0 holds nobody', answerOf(new UserList(Array('maxTries'=>0) + $lstConf), 'ben', 'pw-ben')[0], 'ben');
(new Limiter(Array('dataPath'=>$lmtFldr)))->dropHoldsCount();
$emmaLst = new UserList( $lstConf );
foreach( Array('000000', '111111', '222222') as $wrngCd ){
	$emmaLst->checkSecretDct( Array('user'=>'emma', 'pass'=>'pw-emma', 'code'=>$wrngCd) );
}
checkIdentical('userFile: wrong 2FA codes count too', answerOf($emmaLst, 'emma', 'pw-emma'), Array(false, 'Invalid credentials', true));

// The htpasswd file
$htpList = new PassList( $htpConf );
checkIdentical('htpasswd: a right password clears, wrong ones hold the account', Array(answerOf($htpList, 'alice', 'x')[0], answerOf($htpList, 'alice', 's3cret')[0], answerOf($htpList, 'alice', 'x')[0], answerOf($htpList, 'alice', 'y')[0], answerOf($htpList, 'alice', 'z')[0], answerOf($htpList, 'alice', 's3cret')), Array(false, 'alice', false, false, false, Array(false, 'Invalid credentials', true)));
checkIdentical('htpasswd: another user is not held', answerOf($htpList, 'hank', 'pw-hank')[0], 'hank');
Logger::loadLogsConfig( Array() );
$logText = strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log'));
checkIdentical('the holds are told in the log', Array(preg_match('/\[WARNING\] Credential refused: user "ben" is held after too many attempts/', $logText), preg_match('/\[WARNING\] Credential refused: user "alice" is held after too many attempts/', $logText)), Array(1, 1));

dropTestFolder( TOP_DIR.DS.$lmtFldr );
finishChecking();
