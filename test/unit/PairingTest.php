<?php

// Carriers and checks, pair by pair, through the Checker : each session
// carrier (httpBasc, header, jwtToken, clntCert, a plug-in) with each credential
// check that needs no database (htpasswd, regdKeys, userFile, a plug-in, none).
// The pairs of the users table and of the device plug-in are in
// test/data/PairBaseTest.php, the PHP session carrier in PHPSessTest.php.
// A pair that cannot work must fail closed : nobody goes through.
//
// The plug-ins are those of test/fixtures/runs/Shared : the check "sample"
// (the key "sample-key" alone) and the carrier "carier" (an X-Carier header).

define('RUN_DIR', dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'runs');
require __DIR__.'/../lib/harness.php';

use nyu\Access\Checker;
use nyu\Access\Limiter;
use nyu\Access\Carr\Header;
use nyu\Access\Carr\JWT;
use nyu\Access\Carr\ClCert;
use nyu\Tools\Registry;

// The counters of the address limit lie under the project root : in a folder of this script's own
$lmtFldr = 'temp'.DS.'limits_test_'.getmypid();
$lmtConf = Array('maxTries'=>2, 'holdSecs'=>600, 'dataPath'=>$lmtFldr);

/**
 * Plugs the configuration the Checker reads.
 *
 * @param Int $triesLimit Wrong credentials allowed from one address, 0 for no limit.
 */
function plugConf( Int $triesLimit=0 ): Void {
	$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array(
		'PLUGLIBS'=>Array('authWith'=>Array('sample'), 'carrWith'=>Array('carier')),
		'TESTPASS'=>Array('passFile'=>'test'.DS.'fixtures'.DS.'users.htpasswd'),
		'TESTKEYS'=>Array('keysList'=>Array('k-1')),
		'TESTFILE'=>Array('listFile'=>'test'.DS.'fixtures'.DS.'users.lst'),
		'TESTTOKN'=>Array('hmacSecr'=>str_repeat('s', 40), 'lifeSecs'=>60),
		'TSCERT'=>Array('issuerId'=>'Test CA'),
		'TRYLIMIT'=>Array('maxTries'=>$triesLimit) + $GLOBALS['lmtConf']
	)) );
}

/**
 * Runs the access check of a request, for a carrier and a check.
 *
 * @param String  $carrierStr Short name of the carrier.
 * @param ?String $checkNamed Short name of the credential check, null for none.
 * @param Array   $headersArr Headers of the request.
 * @param Array   $serverVals Server values of the request.
 * @param Array   $moreConfig Other keys of the 'security' block.
 * @param Array   $routeArray The resolved route.
 * @return Array The status, the message, the user and the role.
 */
function pairReply( String $carrierStr, ?String $checkNamed, Array $headersArr=Array(), Array $serverVals=Array(), Array $moreConfig=Array(), Array $routeArray=Array() ): Array {
	$blkList = Array('htpasswd'=>'testPass', 'regdKeys'=>'testKeys', 'userFile'=>'testFile', 'sample'=>'nothing');
	$axsConf = $moreConfig + Array('required'=>true, 'carrWith'=>$carrierStr, 'carrConf'=>($carrierStr=='jwtToken') ? 'testTokn' : 'nothing');
	if( $checkNamed!==null ){
		$axsConf += Array('authWith'=>$checkNamed, 'authConf'=>$blkList[$checkNamed]);
	}
	$axsRslt = (new Checker( $axsConf, mockRequestObj($headersArr, $serverVals + Array('REMOTE_ADDR'=>'203.0.113.7')), new Registry($routeArray) ))->checkRqtAccess();
	return Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('statMssg'), $axsRslt->readValueByKey('userName'), $axsRslt->readValueByKey('userRole'));
}

/**
 * Tells whether an address is held by the limit of the tests.
 *
 * @param String $clientAddr Address of the client.
 * @return Bool Whether the address is held.
 */
function heldAddr( String $clientAddr ): Bool {
	return (new Limiter($GLOBALS['lmtConf']))->readAdrHoldEnd($clientAddr)>0;
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
 * @param String $issuerName Common name of its issuer.
 * @return Array The server values.
 */
function certVals( String $userString, String $issuerName='Test CA' ): Array {
	return Array('SSL_CLIENT_VERIFY'=>'SUCCESS', 'SSL_CLIENT_S_DN_CN'=>$userString, 'SSL_CLIENT_I_DN_CN'=>$issuerName);
}

plugConf();

// httpBasc : a name and a password, which only a check of passwords can take
checkIdentical('httpBasc x htpasswd: granted, as a member', pairReply('httpBasc', 'htpasswd', basicHead('alice', 's3cret')), Array(200, '', 'alice', 'standard'));
checkIdentical('httpBasc x htpasswd: wrong password', pairReply('httpBasc', 'htpasswd', basicHead('alice', 'nope')), Array(401, 'Invalid credentials', '', null));
checkIdentical('httpBasc x htpasswd: no header', pairReply('httpBasc', 'htpasswd'), Array(401, 'Missing Basic Authorization header', '', null));
checkIdentical('httpBasc x regdKeys: a check of keys takes no password, the pair is a mistake of the config', pairReply('httpBasc', 'regdKeys', basicHead('k-1', 'k-1')), Array(500, 'Access misconfigured', '', null));
checkIdentical('httpBasc x plug-in check of keys: refused the same way', pairReply('httpBasc', 'sample', basicHead('sample-key', 'sample-key'))[0], 401);
checkIdentical('httpBasc without a check: nobody goes through, the config is told wrong', pairReply('httpBasc', null, basicHead('alice', 's3cret')), Array(500, 'Access misconfigured', '', null));
$axsRslt = (new Checker( Array('required'=>true, 'carrWith'=>'httpBasc', 'authWith'=>'htpasswd', 'authConf'=>'testPass'), mockRequestObj(basicHead('alice', 'nope')) ))->checkRqtAccess();
checkIdentical('httpBasc: a refusal sends the challenge, with its realm', $axsRslt->readValueByKey('wwwHeadr'), 'Basic realm="nyuRuns", charset="UTF-8"');
checkIdentical('httpBasc x htpasswd: an empty name is a wrong credential, not a store that cannot be read', pairReply('httpBasc', 'htpasswd', basicHead('', 's3cret')), Array(401, 'Invalid credentials', '', null));

// header : a key, which only a check of keys can take
checkIdentical('apiHeadr x htpasswd: a check of passwords takes no key, the pair is a mistake of the config', pairReply('apiHeadr', 'htpasswd', Array('X-Api-Key'=>'alice')), Array(500, 'Access misconfigured', '', null));
checkIdentical('apiHeadr x plug-in check: granted, as a member', pairReply('apiHeadr', 'sample', Array('X-Api-Key'=>'sample-key')), Array(200, '', 'sampleUser', 'standard'));
checkIdentical('apiHeadr x plug-in check: wrong key', pairReply('apiHeadr', 'sample', Array('X-Api-Key'=>'k-1')), Array(401, 'Invalid sample key', '', null));
checkIdentical('apiHeadr without a check: nobody goes through, the config is told wrong', pairReply('apiHeadr', null, Array('X-Api-Key'=>'k-1')), Array(500, 'Access misconfigured', '', null));
$noCheck = new Header( Array() );
checkIdentical('Header without an auth handler', Array($noCheck->checkReqstDict(mockRequestObj(Array('X-Api-Key'=>'k-1'))), $noCheck->getLastErrText()), Array(false, 'No credential handler configured'));

// jwtToken : the token decides alone, unless the check knows its users (userFile, native)
foreach( Array('htpasswd', 'regdKeys', 'sample') as $chkNme ){
	checkIdentical('jwtToken x '.$chkNme.': the token alone decides, with the role it was issued with', pairReply('jwtToken', $chkNme, tokenHead(Array('userName'=>'zed', 'userRole'=>'advanced'))), Array(200, '', 'zed', 'advanced'));
	checkIdentical('jwtToken x '.$chkNme.': a forged token is refused', pairReply('jwtToken', $chkNme, Array('Authorization'=>'Bearer a.b.c'))[0], 401);
}
checkIdentical('jwtToken x userFile: the file decides the role', pairReply('jwtToken', 'userFile', tokenHead(Array('userName'=>'anna', 'userRole'=>'standard'))), Array(200, '', 'anna', 'managers'));

// clntCert : the certificate decides alone, unless the check knows its users
checkIdentical('clntCert without a check: granted, as a member', pairReply('clntCert', null, Array(), certVals('carol')), Array(200, '', 'carol', 'standard'));
foreach( Array('htpasswd', 'regdKeys', 'sample') as $chkNme ){
	checkIdentical('clntCert x '.$chkNme.': the certificate alone decides', pairReply('clntCert', $chkNme, Array(), certVals('carol')), Array(200, '', 'carol', 'standard'));
}
checkIdentical('clntCert x userFile: the file gives the role', pairReply('clntCert', 'userFile', Array(), certVals('anna')), Array(200, '', 'anna', 'managers'));
checkIdentical('clntCert x userFile: a locked user is refused', pairReply('clntCert', 'userFile', Array(), certVals('dave')), Array(401, 'Account not active', '', null));
checkIdentical('clntCert x userFile: a pending user is refused', pairReply('clntCert', 'userFile', Array(), certVals('cleo'))[0], 401);
checkIdentical('clntCert x userFile: a name that is not in the file is refused', pairReply('clntCert', 'userFile', Array(), certVals('zed')), Array(401, 'Account not active', '', null));
checkIdentical('clntCert: no certificate', pairReply('clntCert', 'userFile'), Array(401, 'No verified client certificate', '', null));
$axsRslt = (new Checker( Array('required'=>true, 'carrWith'=>'clntCert'), mockRequestObj() ))->checkRqtAccess();
checkIdentical('clntCert: a refusal carries no challenge', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('wwwHeadr')), Array(401, null));
checkIdentical('clntCert: the issuer of its config block is accepted', pairReply('clntCert', null, Array(), certVals('carol'), Array('carrConf'=>'tscert')), Array(200, '', 'carol', 'standard'));
checkIdentical('clntCert: another issuer is refused', pairReply('clntCert', null, Array(), certVals('carol', 'Other CA'), Array('carrConf'=>'tscert')), Array(401, 'Certificate issuer not accepted', '', null));
checkIdentical('clntCert: a certificate without an issuer is refused when one is asked for', pairReply('clntCert', null, Array(), Array('SSL_CLIENT_VERIFY'=>'SUCCESS', 'SSL_CLIENT_S_DN_CN'=>'carol'), Array('carrConf'=>'tscert'))[0], 401);

// ClCert : only the exact word of a verified certificate is accepted
$certSes = new ClCert( Array() );
foreach( Array('success', 'GENEROUS', 'SUCCESS ', '1', '') as $vrfWord ){
	checkIdentical('ClCert '.json_encode($vrfWord).' is not a verified certificate', $certSes->checkReqstDict(mockRequestObj(Array(), Array('SSL_CLIENT_VERIFY'=>$vrfWord) + certVals('carol'))), false);
}
checkIdentical('ClCert the name is taken without the spaces around it', $certSes->checkReqstDict(mockRequestObj(Array(), certVals('  carol '))), Array('userName'=>'carol'));
$noVarbl = new ClCert( Array('checkVar'=>'', 'ownerVar'=>'') );
checkIdentical('ClCert variables left empty in the config: nobody goes through', $noVarbl->checkReqstDict(mockRequestObj(Array(), certVals('carol'))), false);

// A plug-in carrier, alone or with a check it does not use
checkIdentical('plug-in carrier: granted, as a member', pairReply('carier', null, Array('X-Carier'=>'dora')), Array(200, '', 'dora', 'standard'));
checkIdentical('plug-in carrier: refused without its header', pairReply('carier', null), Array(401, 'Missing X-Carier header', '', null));
checkIdentical('plug-in carrier x plug-in check', pairReply('carier', 'sample', Array('X-Carier'=>'dora')), Array(200, '', 'dora', 'standard'));
checkIdentical('a plug-in that is not listed is a misconfiguration', pairReply('sample', null, Array('X-Api-Key'=>'sample-key')), Array(500, 'Access misconfigured', '', null));

// Roles : what each pair gives is measured against the role a route asks for
$rolConf = Array('minRoles'=>Array('shows/*'=>'advanced'));
$anyRoute = Array('appToRun'=>'shows', 'doAction'=>'index');
checkIdentical('role: httpBasc x htpasswd gives a member', pairReply('httpBasc', 'htpasswd', basicHead('alice', 's3cret'), Array(), $rolConf, $anyRoute), Array(404, 'Not Found', 'alice', 'standard'));
checkIdentical('role: apiHeadr x plug-in check gives a member', pairReply('apiHeadr', 'sample', Array('X-Api-Key'=>'sample-key'), Array(), $rolConf, $anyRoute)[0], 404);
checkIdentical('role: a certificate without a store gives a member', pairReply('clntCert', null, Array(), certVals('carol'), $rolConf, $anyRoute), Array(404, 'Not Found', 'carol', 'standard'));
checkIdentical('role: a certificate with a store gives the role of the store', pairReply('clntCert', 'userFile', Array(), certVals('anna'), $rolConf, $anyRoute), Array(200, '', 'anna', 'managers'));
checkIdentical('role: a plug-in carrier gives a member', pairReply('carier', null, Array('X-Carier'=>'dora'), Array(), $rolConf, $anyRoute)[0], 404);
checkIdentical('role: a token without a store keeps the role it was issued with', Array(pairReply('jwtToken', 'htpasswd', tokenHead(Array('userName'=>'zed', 'userRole'=>'advanced')), Array(), $rolConf, $anyRoute)[0], pairReply('jwtToken', 'htpasswd', tokenHead(Array('userName'=>'zed', 'userRole'=>'standard')), Array(), $rolConf, $anyRoute)[0]), Array(200, 404));
checkIdentical('role: Basic access on the users file, by its role', Array(pairReply('httpBasc', 'userFile', basicHead('ben', 'pw-ben'), Array(), $rolConf, $anyRoute)[0], pairReply('httpBasc', 'userFile', basicHead('anna', 'pw-anna'), Array(), $rolConf, $anyRoute)[0]), Array(404, 200));

// Limit by address : what each pair counts against the address (two wrong credentials allowed)
plugConf(2);
/**
 * Sends the same request three times, each from the given address.
 *
 * @param String  $clientAddr Address the requests come from.
 * @param String  $carrierStr Short name of the carrier.
 * @param ?String $checkNamed Short name of the credential check, null for none.
 * @param Array   $headersArr Headers of the requests.
 * @param Array   $serverVals Server values of the requests.
 * @param Array   $moreConfig Other keys of the 'security' block.
 * @param Array   $routeArray The resolved route.
 * @return Array The status of each request.
 */
function sendTrice( String $clientAddr, String $carrierStr, ?String $checkNamed, Array $headersArr=Array(), Array $serverVals=Array(), Array $moreConfig=Array(), Array $routeArray=Array() ): Array {
	$codeLst = Array();
	for( $idx=0; $idx<3; $idx++ ){
		$codeLst[] = pairReply($carrierStr, $checkNamed, $headersArr, Array('REMOTE_ADDR'=>$clientAddr) + $serverVals, $moreConfig, $routeArray)[0];
	}
	return $codeLst;
}
checkIdentical('limit: httpBasc x htpasswd, a wrong password is counted', Array(sendTrice('198.51.100.1', 'httpBasc', 'htpasswd', basicHead('alice', 'nope')), heldAddr('198.51.100.1')), Array(Array(401, 401, 429), true));
checkIdentical('limit: the held address is refused with the right password', pairReply('httpBasc', 'htpasswd', basicHead('alice', 's3cret'), Array('REMOTE_ADDR'=>'198.51.100.1')), Array(429, 'Too many attempts', '', null));
checkIdentical('limit: httpBasc x htpasswd, an unknown name is counted', Array(sendTrice('198.51.100.2', 'httpBasc', 'htpasswd', basicHead('nobody', 'nope')), heldAddr('198.51.100.2')), Array(Array(401, 401, 429), true));
checkIdentical('limit: malformed Basic credentials are not counted', Array(sendTrice('198.51.100.3', 'httpBasc', 'htpasswd', Array('Authorization'=>'Basic !!!')), heldAddr('198.51.100.3')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a pair that cannot work is not counted', Array(sendTrice('198.51.100.4', 'apiHeadr', 'htpasswd', Array('X-Api-Key'=>'alice')), sendTrice('198.51.100.4', 'httpBasc', 'regdKeys', basicHead('k-1', 'k-1')), heldAddr('198.51.100.4')), Array(Array(500, 500, 500), Array(500, 500, 500), false));
checkIdentical('limit: a plug-in check that does not tell a wrong credential has each refusal counted', Array(sendTrice('198.51.100.5', 'apiHeadr', 'sample', Array('X-Api-Key'=>'k-1')), heldAddr('198.51.100.5')), Array(Array(401, 401, 429), true));
checkIdentical('limit: a forged token is counted, without a store', Array(sendTrice('198.51.100.6', 'jwtToken', null, Array('Authorization'=>'Bearer a.b.c')), heldAddr('198.51.100.6')), Array(Array(401, 401, 429), true));
checkIdentical('limit: and with one', Array(sendTrice('198.51.100.7', 'jwtToken', 'userFile', Array('Authorization'=>'Bearer a.b.c')), heldAddr('198.51.100.7')), Array(Array(401, 401, 429), true));
checkIdentical('limit: a token signed with another secret is counted', Array(sendTrice('198.51.100.12', 'jwtToken', null, Array('Authorization'=>'Bearer '.Firebase\JWT\JWT::encode(Array('userName'=>'anna', 'exp'=>time()+60), str_repeat('x', 40), 'HS256'))), heldAddr('198.51.100.12')), Array(Array(401, 401, 429), true));
checkIdentical('limit: a token that ended is not counted', Array(sendTrice('198.51.100.13', 'jwtToken', null, Array('Authorization'=>'Bearer '.Firebase\JWT\JWT::encode(Array('userName'=>'anna', 'exp'=>time()-10), str_repeat('s', 40), 'HS256'))), heldAddr('198.51.100.13')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a token signed here without an expiry is not counted', Array(sendTrice('198.51.100.14', 'jwtToken', null, Array('Authorization'=>'Bearer '.Firebase\JWT\JWT::encode(Array('userName'=>'anna'), str_repeat('s', 40), 'HS256'))), heldAddr('198.51.100.14')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a request without a token is not counted', Array(sendTrice('198.51.100.15', 'jwtToken', null), heldAddr('198.51.100.15')), Array(Array(401, 401, 401), false));
checkIdentical('limit: the token of a locked user is not counted', Array(sendTrice('198.51.100.8', 'jwtToken', 'userFile', tokenHead(Array('userName'=>'dave'))), heldAddr('198.51.100.8')), Array(Array(401, 401, 401), false));
checkIdentical('limit: the certificate of an unknown user is not counted', Array(sendTrice('198.51.100.9', 'clntCert', 'userFile', Array(), certVals('zed')), heldAddr('198.51.100.9')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a plug-in carrier counts nothing', Array(sendTrice('198.51.100.10', 'carier', null), heldAddr('198.51.100.10')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a refusal for the role is not counted', Array(sendTrice('198.51.100.11', 'httpBasc', 'htpasswd', basicHead('alice', 's3cret'), Array(), $rolConf, $anyRoute), heldAddr('198.51.100.11')), Array(Array(404, 404, 404), false));

// A held address : what needs no check is still served
checkIdentical('limit: a held address keeps the open routes', pairReply('httpBasc', 'htpasswd', Array(), Array('REMOTE_ADDR'=>'198.51.100.1'), Array('openList'=>Array('auth/login')), Array('appToRun'=>'auth', 'doAction'=>'login')), Array(200, '', '', null));
checkIdentical('limit: and its other routes stay refused', pairReply('httpBasc', 'htpasswd', basicHead('alice', 's3cret'), Array('REMOTE_ADDR'=>'198.51.100.1'), Array('openList'=>Array('auth/login')), Array('appToRun'=>'shows', 'doAction'=>'index'))[0], 429);
checkIdentical('limit: a certificate does not lift the hold of its address', pairReply('clntCert', null, Array(), Array('REMOTE_ADDR'=>'198.51.100.1') + certVals('carol'))[0], 429);
$axsRslt = (new Checker( Array('required'=>false), mockRequestObj(Array(), Array('REMOTE_ADDR'=>'198.51.100.1')) ))->checkRqtAccess();
checkIdentical('limit: nothing is held where access is not required', $axsRslt->readValueByKey('statCode'), 200);
plugConf();

// An open route : credentials sent with the request are not read
$axsRslt = (new Checker( Array('required'=>true, 'carrWith'=>'apiHeadr', 'authWith'=>'regdKeys', 'carrConf'=>'testKeys', 'openList'=>Array('auth/login')), mockRequestObj(Array('X-Api-Key'=>'k-1')), new Registry(Array('appToRun'=>'auth', 'doAction'=>'login')) ))->checkRqtAccess();
checkIdentical('open route: a valid key does not log its holder', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('loggedIn'), $axsRslt->readValueByKey('userRole')), Array(200, false, null));
$axsRslt = (new Checker( Array('required'=>true, 'carrWith'=>'apiHeadr', 'authWith'=>'regdKeys', 'carrConf'=>'testKeys', 'openList'=>Array('auth/login')), mockRequestObj(Array('X-Api-Key'=>'bad')), new Registry(Array('appToRun'=>'auth', 'doAction'=>'login')) ))->checkRqtAccess();
checkIdentical('open route: nor is a wrong key refused', $axsRslt->readValueByKey('statCode'), 200);

dropTestFolder(TOP_DIR.DS.$lmtFldr);

finishChecking();
