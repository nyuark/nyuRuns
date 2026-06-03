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
	skipCheck('Pairs', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Access\Checker;
use nyu\Access\Limiter;
use nyu\Access\Auth\Native;
use nyu\Access\Sess\JWT;
use nyu\Tools\Registry;

// The counters of the address limit lie under the project root : in a folder of this script's own
$lmtFldr = 'temp'.DS.'limits_test_'.getmypid();
$lmtConf = Array('maxtry'=>2, 'holdtm'=>600, 'folder'=>$lmtFldr);

/**
 * Plugs the configuration the Checker and the login read.
 *
 * @param Int   $triesLimit Wrong credentials allowed from one address, 0 for no limit.
 * @param Array $loginBlock The 'login' block.
 */
function plugConf( Int $triesLimit=0, Array $loginBlock=Array() ): Void {
	$GLOBALS['nyuConfig'] = new Registry( Array(
		'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>inTempDir('pairs.sqlite')),
		'access'=>Array(
			'LOGIN'=>$loginBlock,
			'TSNATV'=>Array('tblnme'=>'users', 'maxtry'=>5),
			'TSDEVC'=>Array('stmptm'=>0),
			'TSTOKN'=>Array('secret'=>str_repeat('s', 40), 'lifetm'=>60),
			'LIMITS'=>Array('maxtry'=>$triesLimit) + $GLOBALS['lmtConf']
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
 * @param Array  $moreConfig Other keys of the 'auths' block.
 * @param Array  $routeArray The resolved route.
 * @return Array The status, the message, the user and the role.
 */
function pairReply( String $carrierStr, String $checkNamed, Array $headersArr=Array(), Array $serverVals=Array(), Array $moreConfig=Array(), Array $routeArray=Array() ): Array {
	$axsConf = $moreConfig + Array('needed'=>true, 'sessby'=>$carrierStr, 'sesscf'=>($carrierStr=='jtoken') ? 'tstokn' : 'nothing', 'authby'=>$checkNamed, 'authcf'=>($checkNamed=='native') ? 'tsnatv' : 'tsdevc');
	$axsRslt = (new Checker( $axsConf, fakeReqst($headersArr, $serverVals + Array('REMOTE_ADDR'=>'203.0.113.7')), new Registry($routeArray) ))->verifyAxs();
	return Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg'), $axsRslt->readValue('user'), $axsRslt->readValue('role'));
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
	return (new Limiter($GLOBALS['lmtConf']))->heldUntil($clientAddr)>0;
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
	return Array('Authorization'=>'Bearer '.(new JWT(Array('secret'=>str_repeat('s', 40), 'lifetm'=>60)))->issueTokn($identArray));
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

buildData( inTempDir('pairs.sqlite') );
plugConf();
$sqlLink = new PDO('sqlite:'.inTempDir('pairs.sqlite'));
$dvcAuth = new nyu\Access\Auth\Device( Array() );
$phonTkn = strval($dvcAuth->addDevice('anna', 'Phone', 'app'));
$annaTkn = strval((new Native(Array()))->makeToken('anna'));

// clcert x native : the certificate names the user, the table tells whether it is let in, and its role
checkSame('clcert x native: the role of the table', pairReply('clcert', 'native', Array(), certVals('anna')), Array(200, '', 'anna', 'manage'));
checkSame('clcert x native: a locked user is refused', pairReply('clcert', 'native', Array(), certVals('dave')), Array(401, 'Account not active', '', null));
checkSame('clcert x native: a pending user is refused', pairReply('clcert', 'native', Array(), certVals('cleo'))[0], 401);
checkSame('clcert x native: a name with a quote is no user', pairReply('clcert', 'native', Array(), certVals("anna' OR '1'='1")), Array(401, 'Account not active', '', null));
checkSame('clcert x native: the user of the certificate is stamped as seen', (new Native(Array()))->onlineNow(), Array('anna'));
$sqlLink->exec("UPDATE users SET lastSeen=NULL");

// device : a check of keys, which knows its users through its tokens only
checkSame('header x device: the token of a device, with the role of its user', pairReply('header', 'device', Array('X-Api-Key'=>$phonTkn)), Array(200, '', 'anna', 'manage'));
checkSame('header x device: the personal token of the user is not a device', pairReply('header', 'device', Array('X-Api-Key'=>$annaTkn)), Array(401, 'Invalid API key', '', null));
checkSame('header x native: the token of a device is not a personal token', pairReply('header', 'native', Array('X-Api-Key'=>$phonTkn)), Array(401, 'Invalid API key', '', null));
checkSame('hbasic x device: a check of tokens takes no password: the pair refuses every request', pairReply('hbasic', 'device', basicHead('anna', $phonTkn)), Array(500, 'Access misconfigured', '', null));
checkSame('jtoken x device: the token alone decides, with the role it was issued with', pairReply('jtoken', 'device', tokenHead(Array('user'=>'dave', 'role'=>'system'))), Array(200, '', 'dave', 'system'));
checkSame('clcert x device: the certificate alone decides', pairReply('clcert', 'device', Array(), certVals('dave')), Array(200, '', 'dave', 'member'));

// Roles : measured against the role of the users table
$rolConf = Array('rolert'=>Array('shows/*'=>'system'));
$anyRoute = Array('app'=>'shows', 'action'=>'index');
checkSame('role: hbasic x native, by the role of the table', Array(pairReply('hbasic', 'native', basicHead('ben', 'pw-ben'), Array(), $rolConf, $anyRoute), pairReply('hbasic', 'native', basicHead('anna', 'pw-anna'), Array(), $rolConf, $anyRoute)), Array(Array(403, 'Forbidden', 'ben', 'member'), Array(200, '', 'anna', 'manage')));
checkSame('role: header x native, the personal token has the role of its user', pairReply('header', 'native', Array('X-Api-Key'=>$annaTkn), Array(), $rolConf, $anyRoute)[0], 200);
checkSame('role: header x device, the device has the role of its user', pairReply('header', 'device', Array('X-Api-Key'=>$phonTkn), Array(), $rolConf, $anyRoute)[0], 200);
checkSame('role: clcert x native, by the role of the table', Array(pairReply('clcert', 'native', Array(), certVals('ben'), $rolConf, $anyRoute)[0], pairReply('clcert', 'native', Array(), certVals('anna'), $rolConf, $anyRoute)[0]), Array(403, 200));
$sqlLink->exec("UPDATE users SET userRole='member' WHERE userName='anna'");
checkSame('role: a role taken back applies to the device and the certificate at once', Array(pairReply('header', 'device', Array('X-Api-Key'=>$phonTkn), Array(), $rolConf, $anyRoute)[0], pairReply('clcert', 'native', Array(), certVals('anna'), $rolConf, $anyRoute)[0]), Array(403, 403));
$sqlLink->exec("UPDATE users SET userRole='manage' WHERE userName='anna'");

// Limit by address : what each pair counts against the address (two wrong credentials allowed)
plugConf(2);
checkSame('limit: hbasic x native, a wrong password is counted', Array(sendTrice('198.51.100.1', 'hbasic', 'native', basicHead('ben', 'nope')), heldAddr('198.51.100.1')), Array(Array(401, 401, 429), true));
checkSame('limit: the account itself is not held yet, from another address', pairReply('hbasic', 'native', basicHead('ben', 'pw-ben'), Array('REMOTE_ADDR'=>'198.51.100.2'))[0], 200);
checkSame('limit: hbasic x native, an account not active is counted, as a wrong password is', Array(sendTrice('198.51.100.3', 'hbasic', 'native', basicHead('cleo', 'pw-cleo')), heldAddr('198.51.100.3')), Array(Array(401, 401, 429), true));
checkSame('limit: header x native, a wrong personal token is counted', Array(sendTrice('198.51.100.4', 'header', 'native', Array('X-Api-Key'=>'nope')), heldAddr('198.51.100.4')), Array(Array(401, 401, 429), true));
checkSame('limit: header x device, a wrong device token is counted', Array(sendTrice('198.51.100.5', 'header', 'device', Array('X-Api-Key'=>'nope')), heldAddr('198.51.100.5')), Array(Array(401, 401, 429), true));
checkSame('limit: the held address is refused with the right device token', pairReply('header', 'device', Array('X-Api-Key'=>$phonTkn), Array('REMOTE_ADDR'=>'198.51.100.5'))[0], 429);
checkSame('limit: the certificate of a locked user is not counted', Array(sendTrice('198.51.100.6', 'clcert', 'native', Array(), certVals('dave')), heldAddr('198.51.100.6')), Array(Array(401, 401, 401), false));
checkSame('limit: the token of a locked user is not counted', Array(sendTrice('198.51.100.7', 'jtoken', 'native', tokenHead(Array('user'=>'dave'))), heldAddr('198.51.100.7')), Array(Array(401, 401, 401), false));
checkSame('limit: a pair that cannot work is not counted', Array(sendTrice('198.51.100.8', 'hbasic', 'device', basicHead('anna', $phonTkn)), heldAddr('198.51.100.8')), Array(Array(500, 500, 500), false));
dropFoldr(TOP_DIR.DS.$lmtFldr);

// Login : a check of device tokens takes no password
plugConf( 0, Array('authby'=>'device', 'authcf'=>'tsdevc', 'sesscf'=>'tstokn') );
$request = fakeReqst( Array(), Array('REMOTE_ADDR'=>'203.0.113.7') );
$mthProp = new ReflectionProperty('nyu\Tools\Request', 'inMethod');
$mthProp->setAccessible(true);
$mthProp->setValue($request, 'POST');
$actnSet = new nyu\Runs\Auth\Actions( 'login', null, new Registry(Array('user'=>'anna', 'pass'=>$phonTkn)), null, new Registry(Array('logged'=>false, 'user'=>'', 'role'=>null)), $request );
checkSame('login x device: no token is issued for a device token sent as a password', Array($actnSet->statCode, $actnSet->statMssg, $actnSet->asOutput), Array(401, 'No API key provided', null));
$sqlLink = null;

endChecks();
