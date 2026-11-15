<?php

// Access\AppKey and Checker::verifyAppCalls : the call of an application for one
// of its users, its key and the user's token signed by the application, the
// routes of the key, the highest role of its users, the logs.

require __DIR__.'/../lib/harness.php';

use nyu\Access\AppKey;
use nyu\Access\Checker;
use nyu\Access\Carr\JWT;
use nyu\Tools\Registry;

if( !function_exists('openssl_pkey_new') ){
	recordSkipping('application keys', 'the openssl extension is not loaded');
	finishChecking();
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
	$prvPath = inTemporaryDir($namePrefix.'.key');
	$pubPath = inTemporaryDir($namePrefix.'.pub');
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
	$request = mockRequestObj( $headersArr, Array('REQUEST_METHOD'=>$httpMethod, 'REMOTE_ADDR'=>'10.0.0.7') );
	$mthProp = new ReflectionProperty('nyu\Tools\Request', 'reqtInMthd');
	$mthProp->setValue($request, $httpMethod);
	return $request;
}

/**
 * Checks a request through the Checker, on a route.
 *
 * @param Array  $accessConf The 'security' block.
 * @param Array  $headersArr Headers of the request.
 * @param Array  $routeArray The resolved route.
 * @param String $httpMethod Method of the request.
 * @return Array The status, the message, the user, the role and the application.
 */
function appsReply( Array $accessConf, Array $headersArr, Array $routeArray, String $httpMethod='GET' ): Array {
	$axsRslt = (new Checker( $accessConf, appsReqst($headersArr, $httpMethod), new Registry($routeArray) ))->validateAccess();
	return Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('statMssg'), $axsRslt->readValueByKey('userName'), $axsRslt->readValueByKey('userRole'), $axsRslt->readValueByKey('appsName'));
}

list($appPriv, $appPubl) = makePairs('portal');
list($ownPriv, $ownPubl) = makePairs('own');
$appTokn = new JWT( Array('signAlgo'=>'RS256', 'privFile'=>$appPriv) );
$ownTokn = new JWT( Array('signAlgo'=>'RS256', 'privFile'=>$ownPriv) );
$appKeys = Array(
	'headName'=>'X-App-Key',
	'appsList'=>Array(
		'portal'=>Array('keyDigst'=>hash('sha256', 'key-portal'), 'routeSet'=>Array('contacts/*', '/members/save'), 'publFile'=>$appPubl),
		'mobile'=>Array('keyDigst'=>hash('sha256', 'key-mobile'), 'routeSet'=>Array('contacts/view'), 'publFile'=>$appPubl, 'roleTops'=>'advanced'),
		'broken'=>Array('keyDigst'=>hash('sha256', 'key-broke'), 'routeSet'=>Array('contacts/*'), 'publFile'=>$appPubl, 'roleTops'=>'root'),
		'nofile'=>Array('keyDigst'=>hash('sha256', 'key-nofle'), 'routeSet'=>Array('contacts/*'), 'publFile'=>inTemporaryDir('none.pub')),
		'nohash'=>Array('routeSet'=>Array('contacts/*'), 'publFile'=>$appPubl)
	)
);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TRYLIMIT'=>Array('maxTries'=>0), 'APPSKEYS'=>$appKeys, 'OWNTOKEN'=>Array('signAlgo'=>'RS256', 'publFile'=>$ownPubl))) );
$axsConf = Array('required'=>true, 'carrWith'=>'jwtToken', 'carrConf'=>'ownToken', 'appsConf'=>'appsKeys');
$cntView = Array('appToRun'=>'Contacts', 'doAction'=>'view');
$bearsOf = function( JWT $jwtCarrier, String $loggedUser, String $loggedRole ): Array {
	return Array('Authorization'=>'Bearer '.$jwtCarrier->issueTokenText(Array('userName'=>$loggedUser, 'userRole'=>$loggedRole)));
};

// AppKey alone
$keyChck = new AppKey( $appKeys );
checkIdentical('app key: none sent, not the call of an application', $keyChck->verifyAppArray(appsReqst(Array())), null);
$idntArr = $keyChck->verifyAppArray( appsReqst(Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'standard')) );
checkIdentical('app key: the user of the token, with the application', Array($idntArr['userName'] ?? null, $idntArr['userRole'] ?? null, $idntArr['appsName'] ?? null), Array('ann', 'standard', 'portal'));
checkIdentical('app key: a wrong key is refused and counted', Array($keyChck->verifyAppArray(appsReqst(Array('X-App-Key'=>'key-other') + $bearsOf($appTokn, 'ann', 'standard'))), $keyChck->finalErrorText(), $keyChck->hasWrongSecret()), Array(false, 'Invalid app key', true));
checkIdentical('app key: an empty key is no key', $keyChck->verifyAppArray(appsReqst(Array('X-App-Key'=>'') + $bearsOf($appTokn, 'ann', 'standard'))), null);
checkIdentical('app key: the right key without a token, not counted', Array($keyChck->verifyAppArray(appsReqst(Array('X-App-Key'=>'key-portal'))), $keyChck->finalErrorText(), $keyChck->hasWrongSecret()), Array(false, 'Missing Bearer Authorization header', false));
checkIdentical('app key: a token the application did not sign is forged', Array($keyChck->verifyAppArray(appsReqst(Array('X-App-Key'=>'key-portal') + $bearsOf($ownTokn, 'ann', 'standard'))), $keyChck->finalErrorText(), $keyChck->hasWrongSecret()), Array(false, 'Invalid token', true));
checkIdentical('app key: a refused call leaves no route', $keyChck->allowsRouteFlg(new Registry($cntView)), false);
$keyChck->verifyAppArray( appsReqst(Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'standard')) );
checkIdentical('app key: the routes, as openList writes them', Array(
	$keyChck->allowsRouteFlg(new Registry($cntView)), $keyChck->allowsRouteFlg(new Registry(Array('appToRun'=>'contacts', 'doAction'=>''))),
	$keyChck->allowsRouteFlg(new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routPath'=>'members/save'))), $keyChck->allowsRouteFlg(new Registry(Array('appToRun'=>'Members', 'doAction'=>'save')))
), Array(true, true, true, false));
$keyChck->verifyAppArray( appsReqst(Array('X-App-Key'=>'key-mobile') + $bearsOf($appTokn, 'ann', 'standard')) );
checkIdentical('app key: an action alone, not its application without one', Array($keyChck->allowsRouteFlg(new Registry($cntView)), $keyChck->allowsRouteFlg(new Registry(Array('appToRun'=>'contacts', 'doAction'=>'')))), Array(true, false));
checkIdentical('app key: no route before a key is verified', (new AppKey($appKeys))->allowsRouteFlg(new Registry($cntView)), false);

// Through the Checker
checkIdentical('call: granted, the role of the token kept up to roleTops', appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'standard'), $cntView), Array(200, '', 'ann', 'standard', 'portal'));
checkIdentical('call: a manager of the application is a member here', appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'bob', 'managers'), $cntView), Array(200, '', 'bob', 'standard', 'portal'));
checkIdentical('call: roleTops system keeps a system user', appsReply($axsConf, Array('X-App-Key'=>'key-mobile') + $bearsOf($appTokn, 'bob', 'advanced'), $cntView)[3], 'advanced');
checkIdentical('call: a route the key does not list is a 404, as a route that does not exist', appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'standard'), Array('appToRun'=>'Orders', 'doAction'=>'index')), Array(404, 'Not Found', 'ann', 'standard', 'portal'));
checkIdentical('call: the role the route asks for still applies', appsReply($axsConf + Array('minRoles'=>Array('contacts/*'=>'advanced')), Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'bob', 'managers'), $cntView)[0], 404);
checkIdentical('call: a wrong key is a 401', appsReply($axsConf, Array('X-App-Key'=>'key-other') + $bearsOf($appTokn, 'ann', 'standard'), $cntView), Array(401, 'Invalid app key', '', null, null));
checkIdentical('call: the token of the application without its key is refused', appsReply($axsConf, $bearsOf($appTokn, 'ann', 'standard'), $cntView), Array(401, 'Invalid token', '', null, null));
checkIdentical('call: the project\'s own token still goes through its carrier', appsReply($axsConf, $bearsOf($ownTokn, 'carl', 'standard'), $cntView), Array(200, '', 'carl', 'standard', null));
checkIdentical('call: an unknown roleTops is a 500', appsReply($axsConf, Array('X-App-Key'=>'key-broke') + $bearsOf($appTokn, 'ann', 'standard'), $cntView), Array(500, 'Access misconfigured', '', null, null));
checkIdentical('call: a missing key file is a 500', appsReply($axsConf, Array('X-App-Key'=>'key-nofle') + $bearsOf($appTokn, 'ann', 'standard'), $cntView), Array(500, 'Access misconfigured', '', null, null));
checkIdentical('call: an open route ignores the key', appsReply($axsConf + Array('openList'=>Array('contacts/view')), Array('X-App-Key'=>'key-other'), $cntView), Array(200, '', '', null, null));
$noKeyCf = $axsConf;
unset($noKeyCf['appsConf']);
checkIdentical('call: without appkey, the key is not read', appsReply($noKeyCf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'standard'), $cntView), Array(401, 'Invalid token', '', null, null));

// The logs : a write is a notice, a read an info, a route refused a warning
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );
appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'standard'), $cntView, 'POST');
appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'standard'), $cntView);
appsReply($axsConf, Array('X-App-Key'=>'key-portal') + $bearsOf($appTokn, 'ann', 'standard'), Array('appToRun'=>'Orders', 'doAction'=>'index'), 'DELETE');
nyu\Tools\Logger::plugLogsConfig( Array() );
$axsLogs = file_exists(inTemporaryDir('logs').DS.'access.log') ? file(inTemporaryDir('logs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkIdentical('log: one line per call', count($axsLogs), 3);
checkIdentical('log: a write is a notice', $axsLogs[0] ?? null, '['.date('Y').'] [NOTICE] App call granted for app "portal", user "ann" (standard) from 10.0.0.7 to Contacts/view (POST)');
checkIdentical('log: a read is an info', $axsLogs[1] ?? null, '['.date('Y').'] [INFO] App call granted for app "portal", user "ann" (standard) from 10.0.0.7 to Contacts/view (GET)');
checkIdentical('log: a route refused is a warning', $axsLogs[2] ?? null, '['.date('Y').'] [WARNING] App call refused for app "portal", user "ann" from 10.0.0.7 to Orders/index: not a route of the application');

finishChecking();
