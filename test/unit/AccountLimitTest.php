<?php

// Access\Limiter by account : the wrong credentials of each user of the
// users file (usrlst) and of the htpasswd file (htpass) counted in the
// folder of the limits, the account held after 'maxtry' of them, released
// after 'holdtm', cleared by a right login; the counters hold no name.

require __DIR__.'/../lib/harness.php';

use nyu\Access\Auth\PassList;
use nyu\Access\Auth\UserList;
use nyu\Access\Limiter;
use nyu\Tools\Logger;
use nyu\Tools\Registry;

$lmtFldr = 'temp'.DS.'acctlimits_'.getmypid();
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('LIMITS'=>Array('maxtry'=>0, 'folder'=>$lmtFldr))) );
$lstConf = Array('usrfle'=>'test/fixtures/users.lst', 'maxtry'=>3, 'holdtm'=>600);
$htpConf = Array('htpasswd'=>'test/fixtures/users.htpasswd', 'maxtry'=>3, 'holdtm'=>600);

/**
 * Tells what a credential check answers.
 *
 * @param Object $storeObjct The check.
 * @param String $userString The name given.
 * @param String $passwdText The password given.
 * @return Array The user or false, the message and whether it counts against the address.
 */
function answerOf( Object $storeObjct, String $userString, String $passwdText ): Array {
	$idntArr = $storeObjct->checkCred( Array('user'=>$userString, 'pass'=>$passwdText) );
	return Array( is_array($idntArr) ? ($idntArr['user'] ?? null) : false, $storeObjct->lastError(), $storeObjct->wrongCred() );
}

// The limiter alone
$userLmt = new Limiter( Array('maxtry'=>2, 'holdtm'=>100, 'folder'=>$lmtFldr) );
$userLmt->countUser('anna', 1000);
checkSame('limiter: one failure does not hold the account', $userLmt->heldByUsr('anna', 1001), 0);
$userLmt->countUser('anna', 1010);
checkSame('limiter: as many as allowed hold it for holdtm after the last one', $userLmt->heldByUsr('anna', 1011), 1110);
checkSame('limiter: another account is not held, nor an address', Array($userLmt->heldByUsr('Anna', 1011), $userLmt->heldUntil('127.0.0.1', 1011)), Array(0, 0));
$cntFles = glob(TOP_DIR.DS.$lmtFldr.DS.'*.usr') ?: Array();
checkSame('limiter: one counter, named by a hash, without the name', Array(count($cntFles), preg_match('/^[0-9a-f]{64}\.usr$/', basename(strval($cntFles[0] ?? ''))), strpos(strval(@file_get_contents(strval($cntFles[0] ?? ''))), 'anna')), Array(1, 1, false));
checkSame('limiter: the addresses listed are not the accounts', $userLmt->listHolds(1011), Array());
checkSame('limiter: after the hold, the account is free, its counter removed', Array($userLmt->heldByUsr('anna', 1111), count(glob(TOP_DIR.DS.$lmtFldr.DS.'*.usr') ?: Array())), Array(0, 0));
$userLmt->countUser('anna', 2000);
$userLmt->countUser('anna', 2001);
$userLmt->clearUser('anna');
checkSame('limiter: clearUser forgets the failures', $userLmt->heldByUsr('anna', 2002), 0);
$userLmt->countUser('ben', 3000);
checkSame('limiter: dropStale and freeHolds remove the counters of the accounts too', Array($userLmt->dropStale(3101), (function() use ( $userLmt ){ $userLmt->countUser('ben'); return $userLmt->freeHolds(); })()), Array(1, 1));
checkSame('limiter: maxtry 0 counts nothing', (function() use ( $lmtFldr ){ $noLimit = new Limiter(Array('maxtry'=>0, 'folder'=>$lmtFldr)); $noLimit->countUser('anna'); return count(glob(TOP_DIR.DS.$lmtFldr.DS.'*.usr') ?: Array()); })(), 0);

// The users file
Logger::plugSetup( Array('path'=>inTempDir('logs')) );
$usrList = new UserList( $lstConf );
checkSame('usrlst: two wrong passwords, then the right one: logged in', Array(answerOf($usrList, 'ben', 'x')[0], answerOf($usrList, 'ben', 'y')[0], answerOf($usrList, 'ben', 'pw-ben')[0]), Array(false, false, 'ben'));
checkSame('usrlst: the right login cleared the failures', count(glob(TOP_DIR.DS.$lmtFldr.DS.'*.usr') ?: Array()), 0);
foreach( Array('x', 'y', 'z') as $wrngPw ){
	answerOf($usrList, 'ben', $wrngPw);
}
checkSame('usrlst: after maxtry, the right password is refused as a wrong one, and counts against the address', answerOf($usrList, 'ben', 'pw-ben'), Array(false, 'Invalid credentials', true));
checkSame('usrlst: another user is not held', answerOf($usrList, 'anna', 'pw-anna')[0], 'anna');
checkSame('usrlst: an unknown name has no counter', (function() use ( $usrList, $lmtFldr ){ answerOf($usrList, 'zed', 'x'); return count(glob(TOP_DIR.DS.$lmtFldr.DS.'*.usr') ?: Array()); })(), 1);
checkSame('usrlst: maxtry 0 holds nobody', answerOf(new UserList(Array('maxtry'=>0) + $lstConf), 'ben', 'pw-ben')[0], 'ben');
(new Limiter(Array('folder'=>$lmtFldr)))->freeHolds();
$emmaLst = new UserList( $lstConf );
foreach( Array('000000', '111111', '222222') as $wrngCd ){
	$emmaLst->checkCred( Array('user'=>'emma', 'pass'=>'pw-emma', 'code'=>$wrngCd) );
}
checkSame('usrlst: wrong 2FA codes count too', answerOf($emmaLst, 'emma', 'pw-emma'), Array(false, 'Invalid credentials', true));

// The htpasswd file
$htpList = new PassList( $htpConf );
checkSame('htpass: a right password clears, wrong ones hold the account', Array(answerOf($htpList, 'alice', 'x')[0], answerOf($htpList, 'alice', 's3cret')[0], answerOf($htpList, 'alice', 'x')[0], answerOf($htpList, 'alice', 'y')[0], answerOf($htpList, 'alice', 'z')[0], answerOf($htpList, 'alice', 's3cret')), Array(false, 'alice', false, false, false, Array(false, 'Invalid credentials', true)));
checkSame('htpass: another user is not held', answerOf($htpList, 'hank', 'pw-hank')[0], 'hank');
Logger::plugSetup( Array() );
$logText = strval(@file_get_contents(inTempDir('logs').DS.'access.log'));
checkSame('the holds are told in the log', Array(preg_match('/\[WARNING\] Credential refused: user "ben" is held after too many attempts/', $logText), preg_match('/\[WARNING\] Credential refused: user "alice" is held after too many attempts/', $logText)), Array(1, 1));

dropFoldr( TOP_DIR.DS.$lmtFldr );
endChecks();
