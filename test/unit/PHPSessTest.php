<?php

// PHP session carrier (Access\Carr\PHPSess) : the session a login opens, the
// cookie that names it on the next requests, its lifetime, the store that
// confirms its user and the logout. The sessions are kept in a folder of the
// tests. What goes out with the reply (the Set-Cookie line, the id a client
// came with) is checked over HTTP, in test/http/HttpTest.php.

require __DIR__.'/../lib/harness.php';

use nyu\Access\Checker;
use nyu\Access\Carr\PHPSess;
use nyu\Access\Auth\UserList;
use nyu\Access\Auth\RegKey;
use nyu\Tools\Registry;

// A session cannot start once something was printed : what the checks print is held back until the end
ob_start();
$sesFldr = inTemporaryDir('sess');
mkdir($sesFldr);
ini_set('session.save_path', $sesFldr);

/**
 * Builds a request carrying a session cookie. PHP keeps the id of the last
 * session for the rest of the process : it is forgotten here, as it is
 * between two real requests.
 *
 * @param mixed  $sessnIdent Value of the cookie, null for a request without one.
 * @param String $cookieName Name of the cookie.
 * @return nyu\Tools\Request The request object.
 */
function cookReqst( Mixed $sessnIdent, String $cookieName='nyuSessn' ): nyu\Tools\Request {
	session_id('');
	$request = mockRequestObj( Array(), Array('REMOTE_ADDR'=>'203.0.113.7') );
	$cookPrp = new ReflectionProperty('nyu\Tools\Request', 'ckieValues');
	$cookPrp->setValue($request, ($sessnIdent===null) ? Array() : Array($cookieName=>$sessnIdent));
	return $request;
}

/**
 * Opens a stored session, changes the given values and returns what it holds.
 *
 * @param String $sessnIdent Id of the session.
 * @param Array  $sessValues Values to write in it.
 * @return Array What the session holds.
 */
function editSessn( String $sessnIdent, Array $sessValues=Array() ): Array {
	session_id($sessnIdent);
	session_start();
	foreach( $sessValues as $sesKey=>$sesVal ){
		$_SESSION[$sesKey] = $sesVal;
	}
	$heldArr = $_SESSION;
	session_write_close();
	return $heldArr;
}

/**
 * Counts the sessions kept in the folder of the tests.
 *
 * @return Int The number of session files.
 */
function sessCount(): Int {
	return count( glob($GLOBALS['sesFldr'].DS.'sess_*') );
}

/**
 * Opens a session as the login of a new request does.
 *
 * @param PHPSess $carrierObj The carrier.
 * @param Array   $identArray The identity to keep in the session.
 * @return String The session id.
 */
function openSessn( PHPSess $carrierObj, Array $identArray ): String {
	session_id('');
	return $carrierObj->issueTokenText($identArray);
}

/**
 * Gives the cookie settings a carrier is built with.
 *
 * @param Array $confValues Config block of the carrier.
 * @param Int   $expiryTime Expiry time of the cookie; 0 for the browser session.
 * @return Array The settings, as PHPSess::makeCookieDict() gives them.
 */
function cookSets( Array $confValues, Int $expiryTime=0 ): Array {
	$setMthd = new ReflectionMethod('nyu\Access\Carr\PHPSess', 'makeCookieDict');
	return $setMthd->invoke( new PHPSess($confValues), $expiryTime );
}

$annaIdn = Array('userName'=>'anna', 'userRole'=>'managers', 'idOfUser'=>1);
$sessCar = new PHPSess( Array('lifeSecs'=>300, 'secureOn'=>false) );

// The login opens a session, its id names it on the next requests
$sessIdn = $sessCar->issueTokenText($annaIdn);
checkPregMatch('issueTokn gives the id of a new session', $sessIdn, '/^[A-Za-z0-9,-]{20,128}$/');
checkIdentical('one session is kept', sessCount(), 1);
checkIdentical('the id of the cookie identifies its user', $sessCar->checkReqstDict(cookReqst($sessIdn)), $annaIdn);
checkIdentical('the session stays open on the next request', $sessCar->checkReqstDict(cookReqst($sessIdn)), $annaIdn);
$nextIdn = openSessn($sessCar, Array('userName'=>'ben'));
checkIdentical('another login opens another session', Array($nextIdn!='' && $nextIdn!=$sessIdn, sessCount(), $sessCar->checkReqstDict(cookReqst($nextIdn))), Array(true, 2, Array('userName'=>'ben')));
// A login made by a request its session already identified : the session is replaced, not kept beside the new one
$sessCar->checkReqstDict(cookReqst($nextIdn));
$thrdIdn = $sessCar->issueTokenText(Array('userName'=>'ben'));
checkIdentical('a login from an identified request replaces its session', Array($thrdIdn!=$nextIdn, $sessCar->checkReqstDict(cookReqst($nextIdn)), $sessCar->checkReqstDict(cookReqst($thrdIdn)), sessCount()), Array(true, false, Array('userName'=>'ben'), 2));
checkIdentical('the id only travels in the cookie, and PHP adopts none it did not issue', Array(ini_get('session.use_strict_mode'), ini_get('session.use_only_cookies'), ini_get('session.use_trans_sid')), Array('1', '1', '0'));

// What is not the cookie of an open session
checkIdentical('no cookie', Array($sessCar->checkReqstDict(cookReqst(null)), $sessCar->getLastErrText()), Array(false, 'No active session'));
checkIdentical('a cookie of another name', $sessCar->checkReqstDict(cookReqst($sessIdn, 'PHPSESSID')), false);
checkIdentical('a cookie sent as a list', $sessCar->checkReqstDict(cookReqst(Array($sessIdn))), false);
foreach( Array('a path'=>'../../etc/passwd', 'too short'=>'abc', 'too long'=>str_repeat('a', 129), 'with a space'=>$sessIdn.' ', 'empty'=>'') as $badNme=>$badIdn ){
	checkIdentical('an id that is not shaped like one, '.$badNme, Array($sessCar->checkReqstDict(cookReqst($badIdn)), $sessCar->getLastErrText()), Array(false, 'No active session'));
}
checkIdentical('an id that was never issued is not adopted', Array($sessCar->checkReqstDict(cookReqst(str_repeat('a', 32))), $sessCar->getLastErrText()), Array(false, 'No active session'));
checkIdentical('and leaves no session behind', sessCount(), 2);

// Lifetime : the time a session may stay unused, counted from its last request
editSessn($sessIdn, Array('nyuStamp'=>time()-200));
checkIdentical('a session used within its lifetime is open', $sessCar->checkReqstDict(cookReqst($sessIdn)), $annaIdn);
checkTrueValue('each request starts its lifetime again', editSessn($sessIdn)['nyuStamp']>=time()-5);
editSessn($sessIdn, Array('nyuStamp'=>time()-301));
checkIdentical('a session left unused for longer is expired', Array($sessCar->checkReqstDict(cookReqst($sessIdn)), $sessCar->getLastErrText()), Array(false, 'Session expired'));
checkIdentical('an expired session is destroyed', Array($sessCar->checkReqstDict(cookReqst($sessIdn)), $sessCar->getLastErrText(), sessCount()), Array(false, 'No active session', 1));
$shrtCar = new PHPSess( Array('lifeSecs'=>0) );
$shrtIdn = openSessn($shrtCar, $annaIdn);
checkIdentical('the shortest lifetime is one second', $shrtCar->checkReqstDict(cookReqst($shrtIdn)), $annaIdn);
$shrtCar->dropUsrSession(cookReqst($shrtIdn));

// Whole life : a session ends once it was opened for longer than it may live, however much it is used
$fullCar = new PHPSess( Array('lifeSecs'=>300, 'fullSecs'=>1000, 'secureOn'=>false) );
$fullIdn = openSessn($fullCar, $annaIdn);
checkTrueValue('a session records when it was opened', editSessn($fullIdn)['nyuBirth']>=time()-5);
editSessn($fullIdn, Array('nyuBirth'=>time()-990));
checkIdentical('a session opened within its whole life is open', $fullCar->checkReqstDict(cookReqst($fullIdn)), $annaIdn);
checkTrueValue('using it does not move the time it was opened', editSessn($fullIdn)['nyuBirth']<=time()-985);
editSessn($fullIdn, Array('nyuBirth'=>time()-1001, 'nyuStamp'=>time()));
checkIdentical('a session opened for longer is expired, though just used', Array($fullCar->checkReqstDict(cookReqst($fullIdn)), $fullCar->getLastErrText()), Array(false, 'Session expired'));
checkIdentical('and is destroyed', Array($fullCar->checkReqstDict(cookReqst($fullIdn)), $fullCar->getLastErrText()), Array(false, 'No active session'));
$fullIdn = openSessn($fullCar, $annaIdn);
editSessn($fullIdn, Array('nyuBirth'=>null));
checkIdentical('a session that does not tell when it was opened is expired', Array($fullCar->checkReqstDict(cookReqst($fullIdn)), $fullCar->getLastErrText()), Array(false, 'Session expired'));
$evrCarr = new PHPSess( Array('lifeSecs'=>300, 'fullSecs'=>0, 'secureOn'=>false) );
$evrIdnt = openSessn($evrCarr, $annaIdn);
editSessn($evrIdnt, Array('nyuBirth'=>time()-9000000));
checkIdentical('a whole life of 0 never ends a session that is used', $evrCarr->checkReqstDict(cookReqst($evrIdnt)), $annaIdn);
editSessn($evrIdnt, Array('nyuBirth'=>time()-43100));
checkIdentical('by default, a session lives twelve hours', Array($sessCar->checkReqstDict(cookReqst($evrIdnt)), editSessn($evrIdnt, Array('nyuBirth'=>time()-43300))!==Array(), $sessCar->checkReqstDict(cookReqst($evrIdnt))), Array($annaIdn, true, false));

// A session that holds no identity
$brknIdn = openSessn($sessCar, $annaIdn);
editSessn($brknIdn, Array('nyuIdent'=>'anna'));
checkIdentical('a session whose identity is not a block is refused, and destroyed', Array($sessCar->checkReqstDict(cookReqst($brknIdn)), $sessCar->getLastErrText(), sessCount()), Array(false, 'No active session', 1));

// With a store that knows its users : the user is confirmed on every request
$usrList = new UserList( Array('listFile'=>'test'.DS.'fixtures'.DS.'users.lst') );
$strSess = new PHPSess( Array('lifeSecs'=>300), $usrList );
$sessIdn = openSessn($strSess, Array('userName'=>'anna', 'userRole'=>'standard'));
checkIdentical('with a store: the role is the one of the store', $strSess->checkReqstDict(cookReqst($sessIdn)), $annaIdn);
checkIdentical('without a store: the role is the one of the session', $sessCar->checkReqstDict(cookReqst($sessIdn)), Array('userName'=>'anna', 'userRole'=>'standard'));
$daveIdn = openSessn($strSess, Array('userName'=>'dave', 'userRole'=>'advanced'));
checkIdentical('with a store: a locked user is refused', Array($strSess->checkReqstDict(cookReqst($daveIdn)), $strSess->getLastErrText()), Array(false, 'Account not active'));
checkIdentical('with a store: the refused session is destroyed', Array($sessCar->checkReqstDict(cookReqst($daveIdn)), $sessCar->getLastErrText()), Array(false, 'No active session'));
$zedIdnt = openSessn($strSess, Array('userRole'=>'managers'));
checkIdentical('with a store: a session naming no user is refused', $strSess->checkReqstDict(cookReqst($zedIdnt)), false);
$keySess = new PHPSess( Array('lifeSecs'=>300), new RegKey(Array('keysList'=>Array('k-1'))) );
$zedIdnt = openSessn($keySess, Array('userName'=>'zed', 'userRole'=>'advanced'));
checkIdentical('with a check that does not know its users: the session alone decides', $keySess->checkReqstDict(cookReqst($zedIdnt)), Array('userName'=>'zed', 'userRole'=>'advanced'));

// Logout
$keptNmb = sessCount();
$sessCar->dropUsrSession(cookReqst($zedIdnt));
checkIdentical('dropSessn destroys the session of the cookie', Array($sessCar->checkReqstDict(cookReqst($zedIdnt)), $sessCar->getLastErrText(), sessCount()), Array(false, 'No active session', $keptNmb-1));
checkIdentical('the other sessions are left open', $strSess->checkReqstDict(cookReqst($sessIdn)), $annaIdn);
checkIdentical('dropSessn without a cookie, or with an id never issued, does nothing', runCatchThrown(function() use ($sessCar){
	$sessCar->dropUsrSession(cookReqst(null));
	$sessCar->dropUsrSession(cookReqst(str_repeat('b', 32)));
	$sessCar->dropUsrSession(cookReqst('../../etc/passwd'));
	return sessCount();
}), $keptNmb-1);

// Through the Checker : phpSessn is the short name of the carrier
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TESTSESS'=>Array('lifeSecs'=>300), 'TESTFILE'=>Array('listFile'=>'test'.DS.'fixtures'.DS.'users.lst'), 'TRYLIMIT'=>Array('maxTries'=>0))) );
/**
 * Runs the access check of a request carrying a session cookie.
 *
 * @param ?String $sessnIdent Value of the cookie, null for a request without one.
 * @param Array   $moreConfig Other keys of the 'security' block.
 * @param Array   $routeArray The resolved route.
 * @return Array The status, the message, the user, the role and the challenge.
 */
function sessReply( ?String $sessnIdent, Array $moreConfig=Array(), Array $routeArray=Array() ): Array {
	$axsRslt = (new Checker( $moreConfig + Array('required'=>true, 'carrWith'=>'phpSessn', 'carrConf'=>'testSess'), cookReqst($sessnIdent), new Registry($routeArray) ))->checkRqtAccess();
	return Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('statMssg'), $axsRslt->readValueByKey('userName'), $axsRslt->readValueByKey('userRole'), $axsRslt->readValueByKey('wwwHeadr'));
}
$usrStor = Array('authWith'=>'userFile', 'authConf'=>'testFile');
checkIdentical('phpSessn is the short name of PHPSess', Checker::findCarrierCls('phpSessn'), '\\nyu\\Access\\Carr\\PHPSess');
checkIdentical('Checker phpSessn without a store: the role of the session', sessReply($sessIdn), Array(200, '', 'anna', 'standard', null));
checkIdentical('Checker phpSessn x userFile: the role of the file', sessReply($sessIdn, $usrStor), Array(200, '', 'anna', 'managers', null));
checkIdentical('Checker phpSessn x regdKeys: the session alone decides', sessReply($sessIdn, Array('authWith'=>'regdKeys'))[3], 'standard');
checkIdentical('Checker phpSessn: no cookie is a 401, without a challenge', sessReply(null), Array(401, 'No active session', '', null, null));
checkIdentical('Checker phpSessn: an unknown role makes a member', sessReply(openSessn($sessCar, Array('userName'=>'erin', 'userRole'=>'root')))[3], 'standard');
$rolConf = Array('minRoles'=>Array('shows/*'=>'advanced'));
checkIdentical('Checker phpSessn: the role a route asks for', Array(sessReply($sessIdn, $rolConf, Array('appToRun'=>'shows', 'doAction'=>'index'))[0], sessReply($sessIdn, $rolConf + $usrStor, Array('appToRun'=>'shows', 'doAction'=>'index'))[0]), Array(404, 200));
checkIdentical('Checker phpSessn: a refusal for the role leaves the session open', sessReply($sessIdn)[0], 200);
$lockIdn = openSessn($sessCar, Array('userName'=>'dave', 'userRole'=>'advanced'));
checkIdentical('Checker phpSessn x userFile: a locked user is refused, its session destroyed', Array(sessReply($lockIdn, $usrStor), sessReply($lockIdn)[1]), Array(Array(401, 'Account not active', '', null, null), 'No active session'));

// Settings of the cookie
$dfltSet = Array('path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict', 'lifetime'=>0);
checkIdentical('cookie: for the whole site, over HTTPS only, out of reach of scripts, of this site only', cookSets(Array()), $dfltSet);
checkIdentical('cookie: lax is SameSite=Lax', cookSets(Array('sameSite'=>'lax'))['samesite'], 'Lax');
checkIdentical('cookie: an unknown policy is the strict one', Array(cookSets(Array('sameSite'=>'none'))['samesite'], cookSets(Array('sameSite'=>'Lax'))['samesite']), Array('Strict', 'Strict'));
checkIdentical('cookie: only false sends it over plain HTTP', Array(cookSets(Array('secureOn'=>false))['secure'], cookSets(Array('secureOn'=>0))['secure'], cookSets(Array('secureOn'=>'no'))['secure']), Array(false, true, true));
checkIdentical('cookie: expired with a date, not a lifetime', cookSets(Array(), 1), Array('path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict', 'expires'=>1));
$namePrp = new ReflectionProperty('nyu\Access\Carr\PHPSess', 'cookieName');
checkIdentical('cookie: named nyuSessn by default, and when the name given cannot be one', Array($namePrp->getValue(new PHPSess(Array())), $namePrp->getValue(new PHPSess(Array('cookName'=>'my cookie; Path=/'))), $namePrp->getValue(new PHPSess(Array('cookName'=>'9lives'))), $namePrp->getValue(new PHPSess(Array('cookName'=>'nyu_Tests1')))), Array('nyuSessn', 'nyuSessn', 'nyuSessn', 'nyu_Tests1'));

// The cookie of one carrier is not read by another
$othrCar = new PHPSess( Array('cookName'=>'nyuOther', 'lifeSecs'=>300) );
checkIdentical('a carrier does not read the cookie of another name', $othrCar->checkReqstDict(cookReqst($sessIdn)), false);

// A folder of its own : the sessions are kept under the project root, away from PHP's own folder
$ownName = 'temp/sess_test_'.getmypid();
$ownFldr = TOP_DIR.DS.'temp'.DS.'sess_test_'.getmypid();
$ownCarr = new PHPSess( Array('lifeSecs'=>300, 'secureOn'=>false, 'dataPath'=>'/'.$ownName.'/') );
$keptNmb = sessCount();
$ownIdnt = openSessn($ownCarr, $annaIdn);
checkIdentical('folder: the session is kept in it, and not in PHP\'s own', Array(is_file($ownFldr.DS.'sess_'.$ownIdnt), sessCount(), $ownCarr->checkReqstDict(cookReqst($ownIdnt))), Array(true, $keptNmb, $annaIdn));
$ownCarr->dropUsrSession(cookReqst($ownIdnt));
checkIdentical('folder: and is removed from it at the logout', count((array)glob($ownFldr.DS.'sess_*')), 0);
dropTestFolder($ownFldr);
ini_set('session.save_path', $sesFldr);

finishChecking();
