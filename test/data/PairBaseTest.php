<?php

// Carriers and checks, pair by pair, through the Checker : the pairs of the
// checks that read a database, the users table (native) and the device
// tokens (device, Access\Auth\Device), on the sample SQLite
// database. The pairs that need none are in test/unit/PairingTest.php.
//
//   user 1  anna   manage  active   one device, "Phone"
//   user 2  ben    member  active
//   user 3  cleo   member  pendng
//   user 4  dave   system  locked

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';
require_once RUN_DIR.DS.'Auth'.DS.'Actions.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Pairs', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Access\Checker;
use nyu\Access\Limiter;
use nyu\Access\Auth\Native;
use nyu\Access\Sess\JWT;
use nyu\Tools\Registry;

// The counters of the address limit lie under the project root : in a folder of this script's own
$lmtFldr = 'temp'.DS.'limits_test_'.getmypid();
$lmtConf = Array('maxTries'=>2, 'holdSecs'=>600, 'dataPath'=>$lmtFldr);

/**
 * Plugs the configuration the Checker and the login read.
 *
 * @param Int   $triesLimit Wrong credentials allowed from one address, 0 for no limit.
 * @param Array $loginBlock The 'login' block.
 */
function plugConf( Int $triesLimit=0, Array $loginBlock=Array() ): Void {
	$GLOBALS['nyuConfig'] = new Registry( Array(
		'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>inTemporaryDir('pairs.sqlite')),
		'access'=>Array(
			'LOGINSET'=>$loginBlock,
			'TESTBASE'=>Array('useTable'=>'users', 'maxTries'=>5),
			'TSDEVC'=>Array('stmpSecs'=>0),
			'TESTTOKN'=>Array('hmacSecr'=>str_repeat('s', 40), 'lifeSecs'=>60),
			'TRYLIMIT'=>Array('maxTries'=>$triesLimit) + $GLOBALS['lmtConf']
		)
	) );
}

/**
 * Runs the access check of a request, for a carrier and a check.
 *
 * @param String $carrierStr Short name of the session carrier.
 * @param String $checkNamed Short name of the credential check.
 * @param Array  $headersArr Headers of the request.
 * @param Array  $serverVals Server values of the request.
 * @param Array  $moreConfig Other keys of the 'security' block.
 * @param Array  $routeArray The resolved route.
 * @return Array The status, the message, the user and the role.
 */
function pairReply( String $carrierStr, String $checkNamed, Array $headersArr=Array(), Array $serverVals=Array(), Array $moreConfig=Array(), Array $routeArray=Array() ): Array {
	$axsConf = $moreConfig + Array('required'=>true, 'sessWith'=>$carrierStr, 'sessConf'=>($carrierStr=='jwtToken') ? 'testTokn' : 'nothing', 'authWith'=>$checkNamed, 'authConf'=>($checkNamed=='nativeDb') ? 'testBase' : 'tsdevc');
	$axsRslt = (new Checker( $axsConf, mockRequestObj($headersArr, $serverVals + Array('REMOTE_ADDR'=>'203.0.113.7')), new Registry($routeArray) ))->validateAccess();
	return Array($axsRslt->readFileDetail('statCode'), $axsRslt->readFileDetail('statMssg'), $axsRslt->readFileDetail('user'), $axsRslt->readFileDetail('role'));
}

/**
 * Sends the same request three times, each from the given address.
 *
 * @param String $clientAddr Address the requests come from.
 * @param String $carrierStr Short name of the session carrier.
 * @param String $checkNamed Short name of the credential check.
 * @param Array  $headersArr Headers of the requests.
 * @param Array  $serverVals Server values of the requests.
 * @return Array The status of each request.
 */
function sendTrice( String $clientAddr, String $carrierStr, String $checkNamed, Array $headersArr=Array(), Array $serverVals=Array() ): Array {
	$codeLst = Array();
	for( $idx=0; $idx<3; $idx++ ){
		$codeLst[] = pairReply($carrierStr, $checkNamed, $headersArr, Array('REMOTE_ADDR'=>$clientAddr) + $serverVals)[0];
	}
	return $codeLst;
}

/**
 * Tells whether an address is held by the limit of the tests.
 *
 * @param String $clientAddr Address of the client.
 * @return Bool Whether the address is held.
 */
function heldAddr( String $clientAddr ): Bool {
	return (new Limiter($GLOBALS['lmtConf']))->heldUntilCount($clientAddr)>0;
}

/**
 * Builds the headers carrying Basic credentials.
 *
 * @param String $userString User name.
 * @param String $passString Password.
 * @return Array The headers.
 */
function basicHead( String $userString, String $passString ): Array {
	return Array('Authorization'=>'Basic '.base64_encode($userString.':'.$passString));
}

/**
 * Builds the headers carrying a token of the tests.
 *
 * @param Array $identArray Identity the token is issued for.
 * @return Array The headers.
 */
function tokenHead( Array $identArray ): Array {
	return Array('Authorization'=>'Bearer '.(new JWT(Array('hmacSecr'=>str_repeat('s', 40), 'lifeSecs'=>60)))->issueTokenText($identArray));
}

/**
 * Builds the server values a web server reports for a client certificate.
 *
 * @param String $userString Common name of the certificate.
 * @return Array The server values.
 */
function certVals( String $userString ): Array {
	return Array('SSL_CLIENT_VERIFY'=>'SUCCESS', 'SSL_CLIENT_S_DN_CN'=>$userString, 'SSL_CLIENT_I_DN_CN'=>'Test CA');
}

createSampleDb( inTemporaryDir('pairs.sqlite') );
plugConf();
$sqlLink = new PDO('sqlite:'.inTemporaryDir('pairs.sqlite'));
$dvcAuth = new nyu\Access\Auth\Device( Array() );
$phonTkn = strval($dvcAuth->pushDeviceText('anna', 'Phone', 'app'));
$annaTkn = strval((new Native(Array()))->makeToknString('anna'));

// clntCert x nativeDb : the certificate names the user, the table tells whether it is let in, and its role
checkIdentical('clntCert x nativeDb: the role of the table', pairReply('clntCert', 'nativeDb', Array(), certVals('anna')), Array(200, '', 'anna', 'manage'));
checkIdentical('clntCert x nativeDb: a locked user is refused', pairReply('clntCert', 'nativeDb', Array(), certVals('dave')), Array(401, 'Account not active', '', null));
checkIdentical('clntCert x nativeDb: a pending user is refused', pairReply('clntCert', 'nativeDb', Array(), certVals('cleo'))[0], 401);
checkIdentical('clntCert x nativeDb: a name with a quote is no user', pairReply('clntCert', 'nativeDb', Array(), certVals("anna' OR '1'='1")), Array(401, 'Account not active', '', null));
checkIdentical('clntCert x nativeDb: the user of the certificate is stamped as seen', (new Native(Array()))->onlineNowArray(), Array('anna'));
$sqlLink->exec("UPDATE users SET accessDate=NULL");

// device : a check of keys, which knows its users through its tokens only
checkIdentical('apiHeadr x devToken: the token of a device, with the role of its user', pairReply('apiHeadr', 'devToken', Array('X-Api-Key'=>$phonTkn)), Array(200, '', 'anna', 'manage'));
checkIdentical('apiHeadr x devToken: the personal token of the user is not a device', pairReply('apiHeadr', 'devToken', Array('X-Api-Key'=>$annaTkn)), Array(401, 'Invalid API key', '', null));
checkIdentical('apiHeadr x nativeDb: the token of a device is not a personal token', pairReply('apiHeadr', 'nativeDb', Array('X-Api-Key'=>$phonTkn)), Array(401, 'Invalid API key', '', null));
checkIdentical('httpBasc x devToken: a check of tokens takes no password: the pair refuses every request', pairReply('httpBasc', 'devToken', basicHead('anna', $phonTkn)), Array(500, 'Access misconfigured', '', null));
checkIdentical('jwtToken x devToken: the token alone decides, with the role it was issued with', pairReply('jwtToken', 'devToken', tokenHead(Array('user'=>'dave', 'role'=>'system'))), Array(200, '', 'dave', 'system'));
checkIdentical('clntCert x devToken: the certificate alone decides', pairReply('clntCert', 'devToken', Array(), certVals('dave')), Array(200, '', 'dave', 'member'));

// Roles : measured against the role of the users table
$rolConf = Array('rteRoles'=>Array('shows/*'=>'system'));
$anyRoute = Array('app'=>'shows', 'action'=>'overview');
checkIdentical('role: httpBasc x nativeDb, by the role of the table', Array(pairReply('httpBasc', 'nativeDb', basicHead('ben', 'pw-ben'), Array(), $rolConf, $anyRoute), pairReply('httpBasc', 'nativeDb', basicHead('anna', 'pw-anna'), Array(), $rolConf, $anyRoute)), Array(Array(404, 'Not Found', 'ben', 'member'), Array(200, '', 'anna', 'manage')));
checkIdentical('role: apiHeadr x nativeDb, the personal token has the role of its user', pairReply('apiHeadr', 'nativeDb', Array('X-Api-Key'=>$annaTkn), Array(), $rolConf, $anyRoute)[0], 200);
checkIdentical('role: apiHeadr x devToken, the device has the role of its user', pairReply('apiHeadr', 'devToken', Array('X-Api-Key'=>$phonTkn), Array(), $rolConf, $anyRoute)[0], 200);
checkIdentical('role: clntCert x nativeDb, by the role of the table', Array(pairReply('clntCert', 'nativeDb', Array(), certVals('ben'), $rolConf, $anyRoute)[0], pairReply('clntCert', 'nativeDb', Array(), certVals('anna'), $rolConf, $anyRoute)[0]), Array(404, 200));
$sqlLink->exec("UPDATE users SET accessRole='member' WHERE userHandle='anna'");
checkIdentical('role: a role taken back applies to the device and the certificate at once', Array(pairReply('apiHeadr', 'devToken', Array('X-Api-Key'=>$phonTkn), Array(), $rolConf, $anyRoute)[0], pairReply('clntCert', 'nativeDb', Array(), certVals('anna'), $rolConf, $anyRoute)[0]), Array(404, 404));
$sqlLink->exec("UPDATE users SET accessRole='manage' WHERE userHandle='anna'");

// Limit by address : what each pair counts against the address (two wrong credentials allowed)
plugConf(2);
checkIdentical('limit: httpBasc x nativeDb, a wrong password is counted', Array(sendTrice('198.51.100.1', 'httpBasc', 'nativeDb', basicHead('ben', 'nope')), heldAddr('198.51.100.1')), Array(Array(401, 401, 429), true));
checkIdentical('limit: the account itself is not held yet, from another address', pairReply('httpBasc', 'nativeDb', basicHead('ben', 'pw-ben'), Array('REMOTE_ADDR'=>'198.51.100.2'))[0], 200);
checkIdentical('limit: httpBasc x nativeDb, an account not active is counted, as a wrong password is', Array(sendTrice('198.51.100.3', 'httpBasc', 'nativeDb', basicHead('cleo', 'pw-cleo')), heldAddr('198.51.100.3')), Array(Array(401, 401, 429), true));
checkIdentical('limit: apiHeadr x nativeDb, a wrong personal token is counted', Array(sendTrice('198.51.100.4', 'apiHeadr', 'nativeDb', Array('X-Api-Key'=>'nope')), heldAddr('198.51.100.4')), Array(Array(401, 401, 429), true));
checkIdentical('limit: apiHeadr x devToken, a wrong device token is counted', Array(sendTrice('198.51.100.5', 'apiHeadr', 'devToken', Array('X-Api-Key'=>'nope')), heldAddr('198.51.100.5')), Array(Array(401, 401, 429), true));
checkIdentical('limit: the held address is refused with the right device token', pairReply('apiHeadr', 'devToken', Array('X-Api-Key'=>$phonTkn), Array('REMOTE_ADDR'=>'198.51.100.5'))[0], 429);
checkIdentical('limit: the certificate of a locked user is not counted', Array(sendTrice('198.51.100.6', 'clntCert', 'nativeDb', Array(), certVals('dave')), heldAddr('198.51.100.6')), Array(Array(401, 401, 401), false));
checkIdentical('limit: the token of a locked user is not counted', Array(sendTrice('198.51.100.7', 'jwtToken', 'nativeDb', tokenHead(Array('user'=>'dave'))), heldAddr('198.51.100.7')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a pair that cannot work is not counted', Array(sendTrice('198.51.100.8', 'httpBasc', 'devToken', basicHead('anna', $phonTkn)), heldAddr('198.51.100.8')), Array(Array(500, 500, 500), false));
dropTestFolder(TOP_DIR.DS.$lmtFldr);

// Login : a check of device tokens takes no password
plugConf( 0, Array('authWith'=>'devToken', 'authConf'=>'tsdevc', 'sessConf'=>'testTokn') );
$request = mockRequestObj( Array(), Array('REMOTE_ADDR'=>'203.0.113.7') );
$mthProp = new ReflectionProperty('nyu\Tools\Request', 'rqstInMthd');
$mthProp->setValue($request, 'POST');
$actnSet = new nyu\Runs\Auth\Actions( new nyu\App\RunContext('openSess', null, new Registry(Array('user'=>'anna', 'pass'=>$phonTkn)), new Registry(Array('logged'=>false, 'user'=>'', 'role'=>null)), $request, null, null, null, 'Auth', RUN_DIR.DS.'Auth') );
$actnSet->runAskedAction();
checkIdentical('login x devToken: no token is issued for a device token sent as a password', Array($actnSet->statusCode, $actnSet->statusMssg, $actnSet->replyValue), Array(401, 'No API key provided', null));
$sqlLink = null;

finishChecking();
