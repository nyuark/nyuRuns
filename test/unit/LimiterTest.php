<?php

// Limit by client address : the wrong credentials one address may present,
// the hold that follows, and how the Checker applies it.

require __DIR__.'/../lib/harness.php';

use nyu\Access\Limiter;
use nyu\Access\Checker;
use nyu\Access\Auth\PassList;
use nyu\Access\Auth\RegKey;
use nyu\Tools\Registry;

/**
 * A limiter keeping its counters in a folder of the tests, wherever it is.
 */
class FoldrLimiter extends Limiter{

	/**
	 * @param Array  $confValues Config block of the limiter.
	 * @param String $folderPath Full path of the folder keeping the counters.
	 */
	function __construct( Array $confValues, String $folderPath ){
		parent::__construct($confValues);
		$this->countsFold = $folderPath;
	}
}

/**
 * Counts several wrong credentials for an address, one second apart.
 *
 * @param Limiter $limiterObj The limiter.
 * @param String  $clientAddr Address of the client.
 * @param Int     $failsCount How many to count.
 * @param Int     $unixMoment Unix time of the first one.
 */
function failTimes( Limiter $limiterObj, String $clientAddr, Int $failsCount, Int $unixMoment ): Void {
	for( $idx=0; $idx<$failsCount; $idx++ ){
		$limiterObj->countFailedTry($clientAddr, $unixMoment+$idx);
	}
}

$cntFldr = inTemporaryDir('limits');
$limiter = new FoldrLimiter( Array('maxtry'=>3, 'holdtm'=>600), $cntFldr );

// Counting, holding, and the end of the hold
checkIdentical('an address that never failed is not held', $limiter->heldUntilCount('203.0.113.7', 1000), 0);
failTimes($limiter, '203.0.113.7', 2, 1000);
checkIdentical('fewer failures than allowed do not hold', $limiter->heldUntilCount('203.0.113.7', 1002), 0);
$limiter->countFailedTry('203.0.113.7', 1002);
checkIdentical('as many failures as allowed hold the address, from the last one', $limiter->heldUntilCount('203.0.113.7', 1003), 1602);
checkIdentical('the hold lasts until its last second', $limiter->heldUntilCount('203.0.113.7', 1601), 1602);
checkIdentical('the hold is over once its time has passed', $limiter->heldUntilCount('203.0.113.7', 1602), 0);
checkIdentical('the counter whose hold time has passed is removed when read', count(glob($cntFldr.DS.'*.cnt')), 0);
checkIdentical('another address is not held', $limiter->heldUntilCount('203.0.113.8', 1003), 0);
$limiter->countFailedTry('203.0.113.7', 1700);
checkIdentical('after the hold, the count starts again', $limiter->heldUntilCount('203.0.113.7', 1701), 0);
failTimes($limiter, '198.51.100.1', 2, 1000);
$limiter->countFailedTry('198.51.100.1', 1700);
checkIdentical('failures older than the hold time no longer add up', $limiter->heldUntilCount('198.51.100.1', 1701), 0);
checkIdentical('a counter still in use is kept when read', count(glob($cntFldr.DS.'*.cnt')), 2);

// IPv6 : counted by /64 network
failTimes($limiter, '2001:db8:1:2::1', 2, 1000);
$limiter->countFailedTry('2001:db8:1:2:aaaa:bbbb:cccc:dddd', 1002);
checkIdentical('IPv6 addresses of one /64 network are counted together', $limiter->heldUntilCount('2001:db8:1:2::99', 1003), 1602);
checkIdentical('another /64 network is not held', $limiter->heldUntilCount('2001:db8:1:3::1', 1003), 0);

// What is kept
$cntList = array_map('basename', glob($cntFldr.DS.'*'));
checkIdentical('one counter per address, named by a hash and not by the address', Array(count($cntList), count(preg_grep('/^[0-9a-f]{64}\.cnt$/D', $cntList))), Array(3, 3));
$limiter->countFailedTry('not an address', 1000);
$limiter->countFailedTry('', 1000);
checkIdentical('what is not an address is neither counted nor held', Array(count(glob($cntFldr.DS.'*')), $limiter->heldUntilCount('not an address', 1001), $limiter->heldUntilCount('', 1001)), Array(3, 0, 0));

// Counters no longer in use
file_put_contents($cntFldr.DS.str_repeat('a', 64).'.cnt', 'not a counter');
checkIdentical('the counter whose hold time has passed is removed, and what is not a counter', $limiter->dropStaleCount(1650), 2);
checkIdentical('a counter still in use is kept', Array(count(glob($cntFldr.DS.'*.cnt')), $limiter->heldUntilCount('203.0.113.7', 1701)), Array(2, 0));
checkIdentical('nothing left once every hold time has passed', Array($limiter->dropStaleCount(5000), count(glob($cntFldr.DS.'*.cnt'))), Array(2, 0));

// Settings
$offLimit = new FoldrLimiter( Array('maxtry'=>0), inTemporaryDir('limits_off') );
failTimes($offLimit, '203.0.113.7', 20, 1000);
checkIdentical('maxtry 0 : no limit, and nothing kept', Array($offLimit->heldUntilCount('203.0.113.7', 1020), is_dir(inTemporaryDir('limits_off'))), Array(0, false));
$dfltLmt = new FoldrLimiter( Array(), inTemporaryDir('limits_dflt') );
failTimes($dfltLmt, '203.0.113.7', 9, 1000);
checkIdentical('by default, nine failures do not hold', $dfltLmt->heldUntilCount('203.0.113.7', 1009), 0);
$dfltLmt->countFailedTry('203.0.113.7', 1009);
checkIdentical('by default, ten failures hold for ten minutes', $dfltLmt->heldUntilCount('203.0.113.7', 1010), 1609);
$foldrPrp = new ReflectionProperty('nyu\Access\Limiter', 'countsFold');
checkIdentical('the folder is read from the project root, temp/limits by default', Array($foldrPrp->getValue(new Limiter(Array())), $foldrPrp->getValue(new Limiter(Array('folder'=>'/var/tries/')))), Array(TOP_DIR.DS.'temp'.DS.'limits', TOP_DIR.DS.'var/tries'));

// A folder that cannot be written limits nothing, and breaks nothing
file_put_contents(inTemporaryDir('limits_file'), 'in the way');
$brknLmt = new FoldrLimiter( Array('maxtry'=>1), inTemporaryDir('limits_file').DS.'under' );
checkIdentical('counters that cannot be written leave the address free', runCatchThrown(function() use ($brknLmt){
	failTimes($brknLmt, '203.0.113.7', 3, 1000);
	return $brknLmt->heldUntilCount('203.0.113.7', 1003);
}), 0);

// The credential checks tell a wrong credential from the rest
$passLst = new PassList( Array('htpasswd'=>'test'.DS.'fixtures'.DS.'users.htpasswd') );
$passLst->chckCredential(Array('user'=>'alice', 'pass'=>'nope'));
checkIdentical('PassList : a wrong password is a wrong credential', $passLst->wrngCredential(), true);
$passLst->chckCredential(Array('user'=>'alice', 'pass'=>'s3cret'));
checkIdentical('PassList : a right password is not', $passLst->wrngCredential(), false);
$nonePss = new PassList( Array('htpasswd'=>'test'.DS.'fixtures'.DS.'missing.htpasswd') );
$nonePss->chckCredential(Array('user'=>'alice', 'pass'=>'s3cret'));
checkIdentical('PassList : a file that cannot be read is not', Array($nonePss->finalErrorText(), $nonePss->wrngCredential()), Array('Credential store unavailable', false));
$keysLst = new RegKey( Array('keylst'=>Array('k-1')) );
$keysLst->chckCredential(Array('key'=>'bad'));
checkIdentical('RegKey : a wrong key is a wrong credential', $keysLst->wrngCredential(), true);
$keysLst->chckCredential(Array());
checkIdentical('RegKey : a missing key is not', $keysLst->wrngCredential(), false);

// Through the Checker : counters in a folder of the project, removed at the end
$prjFldr = 'temp'.DS.'limits_test_'.getmypid();
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TESTKEYS'=>Array('keylst'=>Array('k-1')), 'LIMITS'=>Array('maxtry'=>2, 'holdtm'=>600, 'folder'=>$prjFldr))) );
$hdrConf = Array('needed'=>true, 'sessby'=>'header', 'authby'=>'regkey', 'sesscf'=>'testkeys');
/**
 * Runs the access check of a request carrying an API key.
 *
 * @param ?String $apiKeyText The key, null for a request without one.
 * @param String  $clientAddr Address the request comes from.
 * @return Array The status, the message and the seconds to wait.
 */
function keyAccess( ?String $apiKeyText, String $clientAddr ): Array {
	$headLst = ($apiKeyText===null) ? Array() : Array('X-Api-Key'=>$apiKeyText);
	$axsRslt = (new Checker( $GLOBALS['hdrConf'], mockRequestObj($headLst, Array('REMOTE_ADDR'=>$clientAddr)) ))->validateAccess();
	return Array($axsRslt->readFileDetail('statCode'), $axsRslt->readFileDetail('statMssg'), $axsRslt->readFileDetail('retryAft'));
}
checkIdentical('Checker : a request without a key is refused, not counted', Array(keyAccess(null, '203.0.113.7'), keyAccess(null, '203.0.113.7'), keyAccess(null, '203.0.113.7')), array_fill(0, 3, Array(401, 'Missing X-Api-Key header', null)));
checkIdentical('Checker : a wrong key is refused as before', keyAccess('bad', '203.0.113.7'), Array(401, 'Invalid API key', null));
checkIdentical('Checker : a second wrong key is still answered 401', keyAccess('bad', '203.0.113.7'), Array(401, 'Invalid API key', null));
$heldRsl = keyAccess('bad', '203.0.113.7');
checkIdentical('Checker : the address is then answered 429', Array($heldRsl[0], $heldRsl[1]), Array(429, 'Too many attempts'));
checkTrueValue('Checker : with the seconds to wait', is_int($heldRsl[2]) && $heldRsl[2]>=1 && $heldRsl[2]<=600);
checkIdentical('Checker : a held address is refused even with the right key', keyAccess('k-1', '203.0.113.7')[0], 429);
checkIdentical('Checker : another address goes through', keyAccess('k-1', '203.0.113.8')[0], 200);
checkIdentical('Checker : a right key does not clear the failures of its address', Array(keyAccess('bad', '198.51.100.1')[0], keyAccess('k-1', '198.51.100.1')[0], keyAccess('bad', '198.51.100.1')[0], keyAccess('k-1', '198.51.100.1')[0]), Array(401, 200, 401, 429));
dropTestFolder(TOP_DIR.DS.$prjFldr);

finishChecking();
