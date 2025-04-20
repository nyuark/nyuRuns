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
		$this->cntFoldr = $folderPath;
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
		$limiterObj->countFail($clientAddr, $unixMoment+$idx);
	}
}

$cntFldr = inTempDir('limits');
$limiter = new FoldrLimiter( Array('maxtry'=>3, 'holdtm'=>600), $cntFldr );

// Counting, holding, and the end of the hold
checkSame('an address that never failed is not held', $limiter->heldUntil('203.0.113.7', 1000), 0);
failTimes($limiter, '203.0.113.7', 2, 1000);
checkSame('fewer failures than allowed do not hold', $limiter->heldUntil('203.0.113.7', 1002), 0);
$limiter->countFail('203.0.113.7', 1002);
checkSame('as many failures as allowed hold the address, from the last one', $limiter->heldUntil('203.0.113.7', 1003), 1602);
checkSame('the hold lasts until its last second', $limiter->heldUntil('203.0.113.7', 1601), 1602);
checkSame('the hold is over once its time has passed', $limiter->heldUntil('203.0.113.7', 1602), 0);
checkSame('another address is not held', $limiter->heldUntil('203.0.113.8', 1003), 0);
$limiter->countFail('203.0.113.7', 1700);
checkSame('after the hold, the count starts again', $limiter->heldUntil('203.0.113.7', 1701), 0);
failTimes($limiter, '198.51.100.1', 2, 1000);
$limiter->countFail('198.51.100.1', 1700);
checkSame('failures older than the hold time no longer add up', $limiter->heldUntil('198.51.100.1', 1701), 0);

// IPv6 : counted by /64 network
failTimes($limiter, '2001:db8:1:2::1', 2, 1000);
$limiter->countFail('2001:db8:1:2:aaaa:bbbb:cccc:dddd', 1002);
checkSame('IPv6 addresses of one /64 network are counted together', $limiter->heldUntil('2001:db8:1:2::99', 1003), 1602);
checkSame('another /64 network is not held', $limiter->heldUntil('2001:db8:1:3::1', 1003), 0);

// What is kept
$cntList = array_map('basename', glob($cntFldr.DS.'*'));
checkSame('one counter per address, named by a hash and not by the address', Array(count($cntList), count(preg_grep('/^[0-9a-f]{64}\.cnt$/D', $cntList))), Array(3, 3));
$limiter->countFail('not an address', 1000);
$limiter->countFail('', 1000);
checkSame('what is not an address is neither counted nor held', Array(count(glob($cntFldr.DS.'*')), $limiter->heldUntil('not an address', 1001), $limiter->heldUntil('', 1001)), Array(3, 0, 0));

// Counters no longer in use
file_put_contents($cntFldr.DS.str_repeat('a', 64).'.cnt', 'not a counter');
checkSame('the counter whose hold time has passed is removed, and what is not a counter', $limiter->dropStale(1650), 2);
checkSame('a counter still in use is kept', Array(count(glob($cntFldr.DS.'*.cnt')), $limiter->heldUntil('203.0.113.7', 1701)), Array(2, 0));
checkSame('nothing left once every hold time has passed', Array($limiter->dropStale(5000), count(glob($cntFldr.DS.'*.cnt'))), Array(2, 0));

// Settings
$offLimit = new FoldrLimiter( Array('maxtry'=>0), inTempDir('limits_off') );
failTimes($offLimit, '203.0.113.7', 20, 1000);
checkSame('maxtry 0 : no limit, and nothing kept', Array($offLimit->heldUntil('203.0.113.7', 1020), is_dir(inTempDir('limits_off'))), Array(0, false));
$dfltLmt = new FoldrLimiter( Array(), inTempDir('limits_dflt') );
failTimes($dfltLmt, '203.0.113.7', 9, 1000);
checkSame('by default, nine failures do not hold', $dfltLmt->heldUntil('203.0.113.7', 1009), 0);
$dfltLmt->countFail('203.0.113.7', 1009);
checkSame('by default, ten failures hold for ten minutes', $dfltLmt->heldUntil('203.0.113.7', 1010), 1609);
$foldrPrp = new ReflectionProperty('nyu\Access\Limiter', 'cntFoldr');
$foldrPrp->setAccessible(true);
checkSame('the folder is read from the project root, temp/limits by default', Array($foldrPrp->getValue(new Limiter(Array())), $foldrPrp->getValue(new Limiter(Array('folder'=>'/var/tries/')))), Array(TOP_DIR.DS.'temp'.DS.'limits', TOP_DIR.DS.'var/tries'));

// A folder that cannot be written limits nothing, and breaks nothing
file_put_contents(inTempDir('limits_file'), 'in the way');
$brknLmt = new FoldrLimiter( Array('maxtry'=>1), inTempDir('limits_file').DS.'under' );
checkSame('counters that cannot be written leave the address free', runSafely(function() use ($brknLmt){
	failTimes($brknLmt, '203.0.113.7', 3, 1000);
	return $brknLmt->heldUntil('203.0.113.7', 1003);
}), 0);

// The credential checks tell a wrong credential from the rest
$passLst = new PassList( Array('htpasswd'=>'test'.DS.'fixtures'.DS.'users.htpasswd') );
$passLst->checkCred(Array('user'=>'alice', 'pass'=>'nope'));
checkSame('PassList : a wrong password is a wrong credential', $passLst->wrongCred(), true);
$passLst->checkCred(Array('user'=>'alice', 'pass'=>'s3cret'));
checkSame('PassList : a right password is not', $passLst->wrongCred(), false);
$nonePss = new PassList( Array('htpasswd'=>'test'.DS.'fixtures'.DS.'missing.htpasswd') );
$nonePss->checkCred(Array('user'=>'alice', 'pass'=>'s3cret'));
checkSame('PassList : a file that cannot be read is not', Array($nonePss->lastError(), $nonePss->wrongCred()), Array('Credential store unavailable', false));
$keysLst = new RegKey( Array('keylst'=>Array('k-1')) );
$keysLst->checkCred(Array('key'=>'bad'));
checkSame('RegKey : a wrong key is a wrong credential', $keysLst->wrongCred(), true);
$keysLst->checkCred(Array());
checkSame('RegKey : a missing key is not', $keysLst->wrongCred(), false);

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
	$axsRslt = (new Checker( $GLOBALS['hdrConf'], fakeReqst($headLst, Array('REMOTE_ADDR'=>$clientAddr)) ))->verifyAxs();
	return Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg'), $axsRslt->readValue('retryAft'));
}
checkSame('Checker : a request without a key is refused, not counted', Array(keyAccess(null, '203.0.113.7'), keyAccess(null, '203.0.113.7'), keyAccess(null, '203.0.113.7')), array_fill(0, 3, Array(401, 'Missing X-Api-Key header', null)));
checkSame('Checker : a wrong key is refused as before', keyAccess('bad', '203.0.113.7'), Array(401, 'Invalid API key', null));
checkSame('Checker : a second wrong key is still answered 401', keyAccess('bad', '203.0.113.7'), Array(401, 'Invalid API key', null));
$heldRsl = keyAccess('bad', '203.0.113.7');
checkSame('Checker : the address is then answered 429', Array($heldRsl[0], $heldRsl[1]), Array(429, 'Too many attempts'));
checkTrue('Checker : with the seconds to wait', is_int($heldRsl[2]) && $heldRsl[2]>=1 && $heldRsl[2]<=600);
checkSame('Checker : a held address is refused even with the right key', keyAccess('k-1', '203.0.113.7')[0], 429);
checkSame('Checker : another address goes through', keyAccess('k-1', '203.0.113.8')[0], 200);
checkSame('Checker : a right key does not clear the failures of its address', Array(keyAccess('bad', '198.51.100.1')[0], keyAccess('k-1', '198.51.100.1')[0], keyAccess('bad', '198.51.100.1')[0], keyAccess('k-1', '198.51.100.1')[0]), Array(401, 200, 401, 429));
dropFoldr(TOP_DIR.DS.$prjFldr);

endChecks();
