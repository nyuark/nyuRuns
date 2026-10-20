<?php

// Access\AppKey and Checker::appsCalls : the call of an application for one
// of its users, its key and the user's token signed by the application, the
// routes of the key, the highest role of its users, the logs.

require __DIR__.'/../lib/harness.php';

use nyu\Access\AppKey;
use nyu\Access\Checker;
use nyu\Access\Sess\JWT;
use nyu\Tools\Registry;

if( !function_exists('openssl_pkey_new') ){
	skipCheck('application keys', 'the openssl extension is not loaded');
	endChecks();
}

/**
 * Makes an RSA key pair and writes it in the temporary folder of the script.
 *
 * @param String $namePrefix Name of the files, without their extension.
 * @return Array The paths of the private and public keys.
 */
function makePairs( String $namePrefix ): Array {
	$cnfOpts = Array('config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf');
	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA) + $cnfOpts );
	$prvText = '';
	openssl_pkey_export($keyObjc, $prvText, null, $cnfOpts);
	$prvPath = inTempDir($namePrefix.'.key');
	$pubPath = inTempDir($namePrefix.'.pub');
	file_put_contents($prvPath, $prvText);
	file_put_contents($pubPath, strval(openssl_pkey_get_details($keyObjc)['key']));
	return Array($prvPath, $pubPath);
}

/**
 * Builds a request with its headers, its method and the address of its client.
 *
 * @param Array  $headersArr Headers of the request.
 * @param String $httpMethod Method of the request.
 * @return nyu\Tools\Request The request.
 */
function appsReqst( Array $headersArr, String $httpMethod='GET' ): nyu\Tools\Request {
	$request = fakeReqst( $headersArr, Array('REQUEST_METHOD'=>$httpMethod, 'REMOTE_ADDR'=>'10.0.0.7') );
	$mthProp = new ReflectionProperty('nyu\Tools\Request', 'inMethod');
	$mthProp->setValue($request, $httpMethod);
	return $request;
}

/**
 * Checks a request through the Checker, on a route.
 *
 * @param Array  $accessConf The 'auths' block.
 * @param Array  $headersArr Headers of the request.
 * @param Array  $routeArray The resolved route.
 * @param String $httpMethod Method of the request.
 * @return Array The status, the message, the user, the role and the application.
 */
function appsReply( Array $accessConf, Array $headersArr, Array $routeArray, String $httpMethod='GET' ): Array {
	$axsRslt = (new Checker( $accessConf, appsReqst($headersArr, $httpMethod), new Registry($routeArray) ))->verifyAxs();
	return Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg'), $axsRslt->readValue('user'), $axsRslt->readValue('role'), $axsRslt->readValue('fromApp'));
}

list($appPriv, $appPubl) = makePairs('portal');
list($ownPriv, $ownPubl) = makePairs('own');
$appTokn = new JWT( Array('algrtm'=>'RS256', 'prvkey'=>$appPriv) );
$ownTokn = new JWT( Array('algrtm'=>'RS256', 'prvkey'=>$ownPriv) );
$appKeys = Array(
	'header'=>'X-App-Key',
	'applst'=>Array(
		'portal'=>Array('keyhsh'=>hash('sha256', 'key-portal'), 'routes'=>Array('contacts/*', '/members/save'), 'pubkey'=>$appPubl),
		'mobile'=>Array('keyhsh'=>hash('sha256', 'key-mobile'), 'routes'=>Array('contacts/view'), 'pubkey'=>$appPubl, 'maxrol'=>'system'),
		'broken'=>Array('keyhsh'=>hash('sha256', 'key-broke'), 'routes'=>Array('contacts/*'), 'pubkey'=>$appPubl, 'maxrol'=>'root'),
		'nofile'=>Array('keyhsh'=>hash('sha256', 'key-nofle'), 'routes'=>Array('contacts/*'), 'pubkey'=>inTempDir('none.pub')),
		'nohash'=>Array('routes'=>Array('contacts/*'), 'pubkey'=>$appPubl)
	)
);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('LIMITS'=>Array('maxtry'=>0), 'APKEYS'=>$appKeys, 'OWNTOKN'=>Array('algrtm'=>'RS256', 'pubkey'=>$ownPubl))) );
$axsConf = Array('needed'=>true, 'sessby'=>'jtoken', 'sesscf'=>'owntokn', 'appkey'=>'apkeys');
$cntView = Array('app'=>'Contacts', 'action'=>'view');
$bearsOf = function( JWT $jwtCarrier, String $loggedUser, String $loggedRole ): Array {
	return Array('Authorization'=>'Bearer '.$jwtCarrier->issueTokn(Array('user'=>$loggedUser, 'role'=>$loggedRole)));
};

// AppKey alone
$keyChck = new AppKey( $appKeys );
checkSame('app key: none sent, not the call of an application', $keyChck->verifyApp(appsReqst(Array())), null);
$idntArr = $keyChck->verifyApp( appsReqst(Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'member')) );
checkSame('app key: the user of the token, with the application', Array($idntArr['user'] ?? null, $idntArr['role'] ?? null, $idntArr['fromApp'] ?? null), Array('ann', 'member', 'portal'));
checkSame('app key: a wrong key is refused and counted', Array($keyChck->verifyApp(appsReqst(Array('X-App-Key'=>'key-other') + $bearsOf($appTokn, 'ann', 'member'))), $keyChck->lastError(), $keyChck->wrongCred()), Array(false, 'Invalid app key', true));
checkSame('app key: an empty key is no key', $keyChck->verifyApp(appsReqst(Array('X-App-Key'=>'') + $bearsOf($appTokn, 'ann', 'member'))), null);
checkSame('app key: the right key without a token, not counted', Array($keyChck->verifyApp(appsReqst(Array('X-App-Key'=>'key-portal'))), $keyChck->lastError(), $keyChck->wrongCred()), Array(false, 'Missing Bearer Authorization header', false));
checkSame('app key: a token the application did not sign is forged', Array($keyChck->verifyApp(appsReqst(Array('X-App-Key'=>'key-portal') + $bearsOf($ownTokn, 'ann', 'member'))), $keyChck->lastError(), $keyChck->wrongCred()), Array(false, 'Invalid token', true));
checkSame('app key: a refused call leaves no route', $keyChck->allowsRte(new Registry($cntView)), false);
$keyChck->verifyApp( appsReqst(Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'member')) );
checkSame('app key: the routes, as openrt writes them', Array(
	$keyChck->allowsRte(new Registry($cntView)), $keyChck->allowsRte(new Registry(Array('app'=>'contacts', 'action'=>''))),
	$keyChck->allowsRte(new Registry(Array('app'=>null, 'action'=>null, 'rtePath'=>'members/save'))), $keyChck->allowsRte(new Registry(Array('app'=>'Members', 'action'=>'save')))
), Array(true, true, true, false));
$keyChck->verifyApp( appsReqst(Array('X-App-Key'=>'key-mobile') + $bearsOf($appTokn, 'ann', 'member')) );
checkSame('app key: an action alone, not its application without one', Array($keyChck->allowsRte(new Registry($cntView)), $keyChck->allowsRte(new Registry(Array('app'=>'contacts', 'action'=>'')))), Array(true, false));
checkSame('app key: no route before a key is verified', (new AppKey($appKeys))->allowsRte(new Registry($cntView)), false);

// Through the Checker
checkSame('call: granted, the role of the token kept up to maxrol', appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'member'), $cntView), Array(200, '', 'ann', 'member', 'portal'));
checkSame('call: a manager of the application is a member here', appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'bob', 'manage'), $cntView), Array(200, '', 'bob', 'member', 'portal'));
checkSame('call: maxrol system keeps a system user', appsReply($axsConf, Array('X-App-Key'=>'key-mobile') + $bearsOf($appTokn, 'bob', 'system'), $cntView)[3], 'system');
checkSame('call: a route the key does not list is a 403', appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'member'), Array('app'=>'Orders', 'action'=>'index')), Array(403, 'Forbidden', 'ann', 'member', 'portal'));
checkSame('call: the role the route asks for still applies', appsReply($axsConf + Array('rolert'=>Array('contacts/*'=>'system')), Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'bob', 'manage'), $cntView)[0], 403);
checkSame('call: a wrong key is a 401', appsReply($axsConf, Array('X-App-Key'=>'key-other') + $bearsOf($appTokn, 'ann', 'member'), $cntView), Array(401, 'Invalid app key', '', null, null));
checkSame('call: the token of the application without its key is refused', appsReply($axsConf, $bearsOf($appTokn, 'ann', 'member'), $cntView), Array(401, 'Invalid token', '', null, null));
checkSame('call: the project\'s own token still goes through its carrier', appsReply($axsConf, $bearsOf($ownTokn, 'carl', 'member'), $cntView), Array(200, '', 'carl', 'member', null));
checkSame('call: an unknown maxrol is a 500', appsReply($axsConf, Array('X-App-Key'=>'key-broke') + $bearsOf($appTokn, 'ann', 'member'), $cntView), Array(500, 'Access misconfigured', '', null, null));
checkSame('call: a missing key file is a 500', appsReply($axsConf, Array('X-App-Key'=>'key-nofle') + $bearsOf($appTokn, 'ann', 'member'), $cntView), Array(500, 'Access misconfigured', '', null, null));
checkSame('call: an open route ignores the key', appsReply($axsConf + Array('openrt'=>Array('contacts/view')), Array('X-App-Key'=>'key-other'), $cntView), Array(200, '', '', null, null));
$noKeyCf = $axsConf;
unset($noKeyCf['appkey']);
checkSame('call: without appkey, the key is not read', appsReply($noKeyCf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'member'), $cntView), Array(401, 'Invalid token', '', null, null));

// The logs : a write is a notice, a read an info, a route refused a warning
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );
appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'member'), $cntView, 'POST');
appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'member'), $cntView);
appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'member'), Array('app'=>'Orders', 'action'=>'index'), 'DELETE');
nyu\Tools\Logger::plugSetup( Array() );
$axsLogs = file_exists(inTempDir('logs').DS.'access.log') ? file(inTempDir('logs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkSame('log: one line per call', count($axsLogs), 3);
checkSame('log: a write is a notice', $axsLogs[0] ?? null, '['.date('Y').'] [NOTICE] App call granted for app "portal", user "ann" (member) from 10.0.0.7 to Contacts/view (POST)');
checkSame('log: a read is an info', $axsLogs[1] ?? null, '['.date('Y').'] [INFO] App call granted for app "portal", user "ann" (member) from 10.0.0.7 to Contacts/view (GET)');
checkSame('log: a route refused is a warning', $axsLogs[2] ?? null, '['.date('Y').'] [WARNING] App call refused for app "portal", user "ann" from 10.0.0.7 to Orders/index: not a route of the application');

endChecks();
