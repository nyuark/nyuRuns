<?php

// PHP session carrier (Access\Sess\PHPSess) : the session a login opens, the
// cookie that names it on the next requests, its lifetime, the store that
// confirms its user and the logout. The sessions are kept in a folder of the
// tests. What goes out with the reply (the Set-Cookie line, the id a client
// came with) is checked over HTTP, in test/http/HttpTest.php.

require __DIR__.'/../lib/harness.php';

use nyu\Access\Checker;
use nyu\Access\Sess\PHPSess;
use nyu\Access\Auth\UserList;
use nyu\Access\Auth\RegKey;
use nyu\Tools\Registry;

// A session cannot start once something was printed : what the checks print is held back until the end
ob_start();
$sesFldr = inTempDir('sess');
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
	$request = fakeReqst( Array(), Array('REMOTE_ADDR'=>'203.0.113.7') );
	$cookPrp = new ReflectionProperty('nyu\Tools\Request', 'cookVals');
	$cookPrp->setAccessible(true);
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
	return $carrierObj->issueTokn($identArray);
}

/**
 * Gives the cookie settings a carrier is built with.
 *
 * @param Array $confValues Config block of the carrier.
 * @param Int   $expiryTime Expiry time of the cookie; 0 for the browser session.
 * @return Array The settings, as PHPSess::cookieSet() gives them.
 */
function cookSets( Array $confValues, Int $expiryTime=0 ): Array {
	$setMthd = new ReflectionMethod('nyu\Access\Sess\PHPSess', 'cookieSet');
	$setMthd->setAccessible(true);
	return $setMthd->invoke( new PHPSess($confValues), $expiryTime );
}

$annaIdn = Array('user'=>'anna', 'role'=>'manage', 'id'=>1);
$sessCar = new PHPSess( Array('lifetm'=>300, 'secure'=>false) );

// The login opens a session, its id names it on the next requests
$sessIdn = $sessCar->issueTokn($annaIdn);
checkLike('issueTokn gives the id of a new session', $sessIdn, '/^[A-Za-z0-9,-]{20,128}$/');
checkSame('one session is kept', sessCount(), 1);
checkSame('the id of the cookie identifies its user', $sessCar->verifyReq(cookReqst($sessIdn)), $annaIdn);
checkSame('the session stays open on the next request', $sessCar->verifyReq(cookReqst($sessIdn)), $annaIdn);
$nextIdn = openSessn($sessCar, Array('user'=>'ben'));
checkSame('another login opens another session', Array($nextIdn!='' && $nextIdn!=$sessIdn, sessCount(), $sessCar->verifyReq(cookReqst($nextIdn))), Array(true, 2, Array('user'=>'ben')));
// A login made by a request its session already identified : the session is replaced, not kept beside the new one
$sessCar->verifyReq(cookReqst($nextIdn));
$thrdIdn = $sessCar->issueTokn(Array('user'=>'ben'));
checkSame('a login from an identified request replaces its session', Array($thrdIdn!=$nextIdn, $sessCar->verifyReq(cookReqst($nextIdn)), $sessCar->verifyReq(cookReqst($thrdIdn)), sessCount()), Array(true, false, Array('user'=>'ben'), 2));
checkSame('the id only travels in the cookie, and PHP adopts none it did not issue', Array(ini_get('session.use_strict_mode'), ini_get('session.use_only_cookies'), ini_get('session.use_trans_sid')), Array('1', '1', '0'));

// What is not the cookie of an open session
checkSame('no cookie', Array($sessCar->verifyReq(cookReqst(null)), $sessCar->lastError()), Array(false, 'No active session'));
checkSame('a cookie of another name', $sessCar->verifyReq(cookReqst($sessIdn, 'PHPSESSID')), false);
checkSame('a cookie sent as a list', $sessCar->verifyReq(cookReqst(Array($sessIdn))), false);
foreach( Array('a path'=>'../../etc/passwd', 'too short'=>'abc', 'too long'=>str_repeat('a', 129), 'with a space'=>$sessIdn.' ', 'empty'=>'') as $badNme=>$badIdn ){
	checkSame('an id that is not shaped like one, '.$badNme, Array($sessCar->verifyReq(cookReqst($badIdn)), $sessCar->lastError()), Array(false, 'No active session'));
}
checkSame('an id that was never issued is not adopted', Array($sessCar->verifyReq(cookReqst(str_repeat('a', 32))), $sessCar->lastError()), Array(false, 'No active session'));
checkSame('and leaves no session behind', sessCount(), 2);

// Lifetime : the time a session may stay unused, counted from its last request
editSessn($sessIdn, Array('nyuStamp'=>time()-200));
checkSame('a session used within its lifetime is open', $sessCar->verifyReq(cookReqst($sessIdn)), $annaIdn);
checkTrue('each request starts its lifetime again', editSessn($sessIdn)['nyuStamp']>=time()-5);
editSessn($sessIdn, Array('nyuStamp'=>time()-301));
checkSame('a session left unused for longer is expired', Array($sessCar->verifyReq(cookReqst($sessIdn)), $sessCar->lastError()), Array(false, 'Session expired'));
checkSame('an expired session is destroyed', Array($sessCar->verifyReq(cookReqst($sessIdn)), $sessCar->lastError(), sessCount()), Array(false, 'No active session', 1));
$shrtCar = new PHPSess( Array('lifetm'=>0) );
$shrtIdn = openSessn($shrtCar, $annaIdn);
checkSame('the shortest lifetime is one second', $shrtCar->verifyReq(cookReqst($shrtIdn)), $annaIdn);
$shrtCar->dropSessn(cookReqst($shrtIdn));

// Whole life : a session ends once it was opened for longer than it may live, however much it is used
$fullCar = new PHPSess( Array('lifetm'=>300, 'fulltm'=>1000, 'secure'=>false) );
$fullIdn = openSessn($fullCar, $annaIdn);
checkTrue('a session records when it was opened', editSessn($fullIdn)['nyuBirth']>=time()-5);
editSessn($fullIdn, Array('nyuBirth'=>time()-990));
checkSame('a session opened within its whole life is open', $fullCar->verifyReq(cookReqst($fullIdn)), $annaIdn);
checkTrue('using it does not move the time it was opened', editSessn($fullIdn)['nyuBirth']<=time()-985);
editSessn($fullIdn, Array('nyuBirth'=>time()-1001, 'nyuStamp'=>time()));
checkSame('a session opened for longer is expired, though just used', Array($fullCar->verifyReq(cookReqst($fullIdn)), $fullCar->lastError()), Array(false, 'Session expired'));
checkSame('and is destroyed', Array($fullCar->verifyReq(cookReqst($fullIdn)), $fullCar->lastError()), Array(false, 'No active session'));
$fullIdn = openSessn($fullCar, $annaIdn);
editSessn($fullIdn, Array('nyuBirth'=>null));
checkSame('a session that does not tell when it was opened is expired', Array($fullCar->verifyReq(cookReqst($fullIdn)), $fullCar->lastError()), Array(false, 'Session expired'));
$evrCarr = new PHPSess( Array('lifetm'=>300, 'fulltm'=>0, 'secure'=>false) );
$evrIdnt = openSessn($evrCarr, $annaIdn);
editSessn($evrIdnt, Array('nyuBirth'=>time()-9000000));
checkSame('a whole life of 0 never ends a session that is used', $evrCarr->verifyReq(cookReqst($evrIdnt)), $annaIdn);
editSessn($evrIdnt, Array('nyuBirth'=>time()-43100));
checkSame('by default, a session lives twelve hours', Array($sessCar->verifyReq(cookReqst($evrIdnt)), editSessn($evrIdnt, Array('nyuBirth'=>time()-43300))!==Array(), $sessCar->verifyReq(cookReqst($evrIdnt))), Array($annaIdn, true, false));

// A session that holds no identity
$brknIdn = openSessn($sessCar, $annaIdn);
editSessn($brknIdn, Array('nyuIdent'=>'anna'));
checkSame('a session whose identity is not a block is refused, and destroyed', Array($sessCar->verifyReq(cookReqst($brknIdn)), $sessCar->lastError(), sessCount()), Array(false, 'No active session', 1));

// With a store that knows its users : the user is confirmed on every request
$usrList = new UserList( Array('usrfle'=>'test'.DS.'fixtures'.DS.'users.lst') );
$strSess = new PHPSess( Array('lifetm'=>300), $usrList );
$sessIdn = openSessn($strSess, Array('user'=>'anna', 'role'=>'member'));
checkSame('with a store: the role is the one of the store', $strSess->verifyReq(cookReqst($sessIdn)), $annaIdn);
checkSame('without a store: the role is the one of the session', $sessCar->verifyReq(cookReqst($sessIdn)), Array('user'=>'anna', 'role'=>'member'));
$daveIdn = openSessn($strSess, Array('user'=>'dave', 'role'=>'system'));
checkSame('with a store: a locked user is refused', Array($strSess->verifyReq(cookReqst($daveIdn)), $strSess->lastError()), Array(false, 'Account not active'));
checkSame('with a store: the refused session is destroyed', Array($sessCar->verifyReq(cookReqst($daveIdn)), $sessCar->lastError()), Array(false, 'No active session'));
$zedIdnt = openSessn($strSess, Array('role'=>'manage'));
checkSame('with a store: a session naming no user is refused', $strSess->verifyReq(cookReqst($zedIdnt)), false);
$keySess = new PHPSess( Array('lifetm'=>300), new RegKey(Array('keylst'=>Array('k-1'))) );
$zedIdnt = openSessn($keySess, Array('user'=>'zed', 'role'=>'system'));
checkSame('with a check that does not know its users: the session alone decides', $keySess->verifyReq(cookReqst($zedIdnt)), Array('user'=>'zed', 'role'=>'system'));

// Logout
$keptNmb = sessCount();
$sessCar->dropSessn(cookReqst($zedIdnt));
checkSame('dropSessn destroys the session of the cookie', Array($sessCar->verifyReq(cookReqst($zedIdnt)), $sessCar->lastError(), sessCount()), Array(false, 'No active session', $keptNmb-1));
checkSame('the other sessions are left open', $strSess->verifyReq(cookReqst($sessIdn)), $annaIdn);
checkSame('dropSessn without a cookie, or with an id never issued, does nothing', runSafely(function() use ($sessCar){
	$sessCar->dropSessn(cookReqst(null));
	$sessCar->dropSessn(cookReqst(str_repeat('b', 32)));
	$sessCar->dropSessn(cookReqst('../../etc/passwd'));
	return sessCount();
}), $keptNmb-1);

// Through the Checker : phsess is the short name of the carrier
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TSSESS'=>Array('lifetm'=>300), 'TSUSRS'=>Array('usrfle'=>'test'.DS.'fixtures'.DS.'users.lst'), 'LIMITS'=>Array('maxtry'=>0))) );
/**
 * Runs the access check of a request carrying a session cookie.
 *
 * @param ?String $sessnIdent Value of the cookie, null for a request without one.
 * @param Array   $moreConfig Other keys of the 'auths' block.
 * @param Array   $routeArray The resolved route.
 * @return Array The status, the message, the user, the role and the challenge.
 */
function sessReply( ?String $sessnIdent, Array $moreConfig=Array(), Array $routeArray=Array() ): Array {
	$axsRslt = (new Checker( $moreConfig + Array('needed'=>true, 'sessby'=>'phsess', 'sesscf'=>'tssess'), cookReqst($sessnIdent), new Registry($routeArray) ))->verifyAxs();
	return Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg'), $axsRslt->readValue('user'), $axsRslt->readValue('role'), $axsRslt->readValue('wwwAuth'));
}
$usrStor = Array('authby'=>'usrlst', 'authcf'=>'tsusrs');
checkSame('phsess is the short name of PHPSess', Checker::sessClass('phsess'), '\\nyu\\Access\\Sess\\PHPSess');
checkSame('Checker phsess without a store: the role of the session', sessReply($sessIdn), Array(200, '', 'anna', 'member', null));
checkSame('Checker phsess x usrlst: the role of the file', sessReply($sessIdn, $usrStor), Array(200, '', 'anna', 'manage', null));
checkSame('Checker phsess x regkey: the session alone decides', sessReply($sessIdn, Array('authby'=>'regkey'))[3], 'member');
checkSame('Checker phsess: no cookie is a 401, without a challenge', sessReply(null), Array(401, 'No active session', '', null, null));
checkSame('Checker phsess: an unknown role makes a member', sessReply(openSessn($sessCar, Array('user'=>'erin', 'role'=>'root')))[3], 'member');
$rolConf = Array('rolert'=>Array('shows/*'=>'system'));
checkSame('Checker phsess: the role a route asks for', Array(sessReply($sessIdn, $rolConf, Array('app'=>'shows', 'action'=>'index'))[0], sessReply($sessIdn, $rolConf + $usrStor, Array('app'=>'shows', 'action'=>'index'))[0]), Array(403, 200));
checkSame('Checker phsess: a refusal for the role leaves the session open', sessReply($sessIdn)[0], 200);
$lockIdn = openSessn($sessCar, Array('user'=>'dave', 'role'=>'system'));
checkSame('Checker phsess x usrlst: a locked user is refused, its session destroyed', Array(sessReply($lockIdn, $usrStor), sessReply($lockIdn)[1]), Array(Array(401, 'Account not active', '', null, null), 'No active session'));

// Settings of the cookie
$dfltSet = Array('path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict', 'lifetime'=>0);
checkSame('cookie: for the whole site, over HTTPS only, out of reach of scripts, of this site only', cookSets(Array()), $dfltSet);
checkSame('cookie: relaxd is SameSite=Lax', cookSets(Array('samest'=>'relaxd'))['samesite'], 'Lax');
checkSame('cookie: an unknown policy is the strict one', Array(cookSets(Array('samest'=>'none'))['samesite'], cookSets(Array('samest'=>'Lax'))['samesite']), Array('Strict', 'Strict'));
checkSame('cookie: only false sends it over plain HTTP', Array(cookSets(Array('secure'=>false))['secure'], cookSets(Array('secure'=>0))['secure'], cookSets(Array('secure'=>'no'))['secure']), Array(false, true, true));
checkSame('cookie: expired with a date, not a lifetime', cookSets(Array(), 1), Array('path'=>'/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict', 'expires'=>1));
$namePrp = new ReflectionProperty('nyu\Access\Sess\PHPSess', 'cookName');
$namePrp->setAccessible(true);
checkSame('cookie: named nyuSessn by default, and when the name given cannot be one', Array($namePrp->getValue(new PHPSess(Array())), $namePrp->getValue(new PHPSess(Array('cookie'=>'my cookie; Path=/'))), $namePrp->getValue(new PHPSess(Array('cookie'=>'9lives'))), $namePrp->getValue(new PHPSess(Array('cookie'=>'nyu_Tests1')))), Array('nyuSessn', 'nyuSessn', 'nyuSessn', 'nyu_Tests1'));

// The cookie of one carrier is not read by another
$othrCar = new PHPSess( Array('cookie'=>'nyuOther', 'lifetm'=>300) );
checkSame('a carrier does not read the cookie of another name', $othrCar->verifyReq(cookReqst($sessIdn)), false);

// A folder of its own : the sessions are kept under the project root, away from PHP's own folder
$ownName = 'temp/sess_test_'.getmypid();
$ownFldr = TOP_DIR.DS.'temp'.DS.'sess_test_'.getmypid();
$ownCarr = new PHPSess( Array('lifetm'=>300, 'secure'=>false, 'folder'=>'/'.$ownName.'/') );
$keptNmb = sessCount();
$ownIdnt = openSessn($ownCarr, $annaIdn);
checkSame('folder: the session is kept in it, and not in PHP\'s own', Array(is_file($ownFldr.DS.'sess_'.$ownIdnt), sessCount(), $ownCarr->verifyReq(cookReqst($ownIdnt))), Array(true, $keptNmb, $annaIdn));
$ownCarr->dropSessn(cookReqst($ownIdnt));
checkSame('folder: and is removed from it at the logout', count((array)glob($ownFldr.DS.'sess_*')), 0);
dropFoldr($ownFldr);
ini_set('session.save_path', $sesFldr);

endChecks();
