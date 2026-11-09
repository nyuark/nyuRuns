<?php

// Carriers and checks, pair by pair, through the Checker : each session
// carrier (hbasic, header, jtoken, clcert, a plug-in) with each credential
// check that needs no database (htpass, regkey, usrlst, a plug-in, none).
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
use nyu\Access\Sess\Header;
use nyu\Access\Sess\JWT;
use nyu\Access\Sess\ClCert;
use nyu\Tools\Registry;

// The counters of the address limit lie under the project root : in a folder of this script's own
$lmtFldr = 'temp'.DS.'limits_test_'.getmypid();
$lmtConf = Array('maxtry'=>2, 'holdtm'=>600, 'folder'=>$lmtFldr);

/**
 * Plugs the configuration the Checker reads.
 *
 * @param Int $triesLimit Wrong credentials allowed from one address, 0 for no limit.
 */
function plugConf( Int $triesLimit=0 ): Void {
	$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array(
		'PLUGIN'=>Array('authby'=>Array('sample'), 'sessby'=>Array('carier')),
		'TSPASS'=>Array('htpasswd'=>'test'.DS.'fixtures'.DS.'users.htpasswd'),
		'TSKEYS'=>Array('keylst'=>Array('k-1')),
		'TSUSRS'=>Array('usrfle'=>'test'.DS.'fixtures'.DS.'users.lst'),
		'TSTOKN'=>Array('secret'=>str_repeat('s', 40), 'lifetm'=>60),
		'TSCERT'=>Array('issuer'=>'Test CA'),
		'LIMITS'=>Array('maxtry'=>$triesLimit) + $GLOBALS['lmtConf']
	)) );
}

/**
 * Runs the access check of a request, for a carrier and a check.
 *
 * @param String  $carrierStr Short name of the session carrier.
 * @param ?String $checkNamed Short name of the credential check, null for none.
 * @param Array   $headersArr Headers of the request.
 * @param Array   $serverVals Server values of the request.
 * @param Array   $moreConfig Other keys of the 'auths' block.
 * @param Array   $routeArray The resolved route.
 * @return Array The status, the message, the user and the role.
 */
function pairReply( String $carrierStr, ?String $checkNamed, Array $headersArr=Array(), Array $serverVals=Array(), Array $moreConfig=Array(), Array $routeArray=Array() ): Array {
	$blkList = Array('htpass'=>'tspass', 'regkey'=>'tskeys', 'usrlst'=>'tsusrs', 'sample'=>'nothing');
	$axsConf = $moreConfig + Array('needed'=>true, 'sessby'=>$carrierStr, 'sesscf'=>($carrierStr=='jtoken') ? 'tstokn' : 'nothing');
	if( $checkNamed!==null ){
		$axsConf += Array('authby'=>$checkNamed, 'authcf'=>$blkList[$checkNamed]);
	}
	$axsRslt = (new Checker( $axsConf, mockRequestObj($headersArr, $serverVals + Array('REMOTE_ADDR'=>'203.0.113.7')), new Registry($routeArray) ))->validateAccess();
	return Array($axsRslt->readFileDetail('statCode'), $axsRslt->readFileDetail('statMssg'), $axsRslt->readFileDetail('user'), $axsRslt->readFileDetail('role'));
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
	return Array('Authorization'=>'Bearer '.(new JWT(Array('secret'=>str_repeat('s', 40), 'lifetm'=>60)))->issueTokenText($identArray));
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

// hbasic : a name and a password, which only a check of passwords can take
checkIdentical('hbasic x htpass: granted, as a member', pairReply('hbasic', 'htpass', basicHead('alice', 's3cret')), Array(200, '', 'alice', 'member'));
checkIdentical('hbasic x htpass: wrong password', pairReply('hbasic', 'htpass', basicHead('alice', 'nope')), Array(401, 'Invalid credentials', '', null));
checkIdentical('hbasic x htpass: no header', pairReply('hbasic', 'htpass'), Array(401, 'Missing Basic Authorization header', '', null));
checkIdentical('hbasic x regkey: a check of keys takes no password, the pair is a mistake of the config', pairReply('hbasic', 'regkey', basicHead('k-1', 'k-1')), Array(500, 'Access misconfigured', '', null));
checkIdentical('hbasic x plug-in check of keys: refused the same way', pairReply('hbasic', 'sample', basicHead('sample-key', 'sample-key'))[0], 401);
checkIdentical('hbasic without a check: nobody goes through, the config is told wrong', pairReply('hbasic', null, basicHead('alice', 's3cret')), Array(500, 'Access misconfigured', '', null));
$axsRslt = (new Checker( Array('needed'=>true, 'sessby'=>'hbasic', 'authby'=>'htpass', 'authcf'=>'tspass'), mockRequestObj(basicHead('alice', 'nope')) ))->validateAccess();
checkIdentical('hbasic: a refusal sends the challenge, with its realm', $axsRslt->readFileDetail('wwwAuth'), 'Basic realm="nyuRuns", charset="UTF-8"');
checkIdentical('hbasic x htpass: an empty name is a wrong credential, not a store that cannot be read', pairReply('hbasic', 'htpass', basicHead('', 's3cret')), Array(401, 'Invalid credentials', '', null));

// header : a key, which only a check of keys can take
checkIdentical('header x htpass: a check of passwords takes no key, the pair is a mistake of the config', pairReply('header', 'htpass', Array('X-Api-Key'=>'alice')), Array(500, 'Access misconfigured', '', null));
checkIdentical('header x plug-in check: granted, as a member', pairReply('header', 'sample', Array('X-Api-Key'=>'sample-key')), Array(200, '', 'sampleUser', 'member'));
checkIdentical('header x plug-in check: wrong key', pairReply('header', 'sample', Array('X-Api-Key'=>'k-1')), Array(401, 'Invalid sample key', '', null));
checkIdentical('header without a check: nobody goes through, the config is told wrong', pairReply('header', null, Array('X-Api-Key'=>'k-1')), Array(500, 'Access misconfigured', '', null));
$noCheck = new Header( Array() );
checkIdentical('Header without an auth handler', Array($noCheck->verifyRqstList(mockRequestObj(Array('X-Api-Key'=>'k-1'))), $noCheck->finalErrorText()), Array(false, 'No credential handler configured'));

// jtoken : the token decides alone, unless the check knows its users (usrlst, native)
foreach( Array('htpass', 'regkey', 'sample') as $chkNme ){
	checkIdentical('jtoken x '.$chkNme.': the token alone decides, with the role it was issued with', pairReply('jtoken', $chkNme, tokenHead(Array('user'=>'zed', 'role'=>'system'))), Array(200, '', 'zed', 'system'));
	checkIdentical('jtoken x '.$chkNme.': a forged token is refused', pairReply('jtoken', $chkNme, Array('Authorization'=>'Bearer a.b.c'))[0], 401);
}
checkIdentical('jtoken x usrlst: the file decides the role', pairReply('jtoken', 'usrlst', tokenHead(Array('user'=>'anna', 'role'=>'member'))), Array(200, '', 'anna', 'manage'));

// clcert : the certificate decides alone, unless the check knows its users
checkIdentical('clcert without a check: granted, as a member', pairReply('clcert', null, Array(), certVals('carol')), Array(200, '', 'carol', 'member'));
foreach( Array('htpass', 'regkey', 'sample') as $chkNme ){
	checkIdentical('clcert x '.$chkNme.': the certificate alone decides', pairReply('clcert', $chkNme, Array(), certVals('carol')), Array(200, '', 'carol', 'member'));
}
checkIdentical('clcert x usrlst: the file gives the role', pairReply('clcert', 'usrlst', Array(), certVals('anna')), Array(200, '', 'anna', 'manage'));
checkIdentical('clcert x usrlst: a locked user is refused', pairReply('clcert', 'usrlst', Array(), certVals('dave')), Array(401, 'Account not active', '', null));
checkIdentical('clcert x usrlst: a pending user is refused', pairReply('clcert', 'usrlst', Array(), certVals('cleo'))[0], 401);
checkIdentical('clcert x usrlst: a name that is not in the file is refused', pairReply('clcert', 'usrlst', Array(), certVals('zed')), Array(401, 'Account not active', '', null));
checkIdentical('clcert: no certificate', pairReply('clcert', 'usrlst'), Array(401, 'No verified client certificate', '', null));
$axsRslt = (new Checker( Array('needed'=>true, 'sessby'=>'clcert'), mockRequestObj() ))->validateAccess();
checkIdentical('clcert: a refusal carries no challenge', Array($axsRslt->readFileDetail('statCode'), $axsRslt->readFileDetail('wwwAuth')), Array(401, null));
checkIdentical('clcert: the issuer of its config block is accepted', pairReply('clcert', null, Array(), certVals('carol'), Array('sesscf'=>'tscert')), Array(200, '', 'carol', 'member'));
checkIdentical('clcert: another issuer is refused', pairReply('clcert', null, Array(), certVals('carol', 'Other CA'), Array('sesscf'=>'tscert')), Array(401, 'Certificate issuer not accepted', '', null));
checkIdentical('clcert: a certificate without an issuer is refused when one is asked for', pairReply('clcert', null, Array(), Array('SSL_CLIENT_VERIFY'=>'SUCCESS', 'SSL_CLIENT_S_DN_CN'=>'carol'), Array('sesscf'=>'tscert'))[0], 401);

// ClCert : only the exact word of a verified certificate is accepted
$certSes = new ClCert( Array() );
foreach( Array('success', 'GENEROUS', 'SUCCESS ', '1', '') as $vrfWord ){
	checkIdentical('ClCert '.json_encode($vrfWord).' is not a verified certificate', $certSes->verifyRqstList(mockRequestObj(Array(), Array('SSL_CLIENT_VERIFY'=>$vrfWord) + certVals('carol'))), false);
}
checkIdentical('ClCert the name is taken without the spaces around it', $certSes->verifyRqstList(mockRequestObj(Array(), certVals('  carol '))), Array('user'=>'carol'));
$noVarbl = new ClCert( Array('chkvar'=>'', 'usrvar'=>'') );
checkIdentical('ClCert variables left empty in the config: nobody goes through', $noVarbl->verifyRqstList(mockRequestObj(Array(), certVals('carol'))), false);

// A plug-in carrier, alone or with a check it does not use
checkIdentical('plug-in carrier: granted, as a member', pairReply('carier', null, Array('X-Carier'=>'dora')), Array(200, '', 'dora', 'member'));
checkIdentical('plug-in carrier: refused without its header', pairReply('carier', null), Array(401, 'Missing X-Carier header', '', null));
checkIdentical('plug-in carrier x plug-in check', pairReply('carier', 'sample', Array('X-Carier'=>'dora')), Array(200, '', 'dora', 'member'));
checkIdentical('a plug-in that is not listed is a misconfiguration', pairReply('sample', null, Array('X-Api-Key'=>'sample-key')), Array(500, 'Access misconfigured', '', null));

// Roles : what each pair gives is measured against the role a route asks for
$rolConf = Array('rolert'=>Array('shows/*'=>'system'));
$anyRoute = Array('app'=>'shows', 'action'=>'index');
checkIdentical('role: hbasic x htpass gives a member', pairReply('hbasic', 'htpass', basicHead('alice', 's3cret'), Array(), $rolConf, $anyRoute), Array(404, 'Not Found', 'alice', 'member'));
checkIdentical('role: header x plug-in check gives a member', pairReply('header', 'sample', Array('X-Api-Key'=>'sample-key'), Array(), $rolConf, $anyRoute)[0], 404);
checkIdentical('role: a certificate without a store gives a member', pairReply('clcert', null, Array(), certVals('carol'), $rolConf, $anyRoute), Array(404, 'Not Found', 'carol', 'member'));
checkIdentical('role: a certificate with a store gives the role of the store', pairReply('clcert', 'usrlst', Array(), certVals('anna'), $rolConf, $anyRoute), Array(200, '', 'anna', 'manage'));
checkIdentical('role: a plug-in carrier gives a member', pairReply('carier', null, Array('X-Carier'=>'dora'), Array(), $rolConf, $anyRoute)[0], 404);
checkIdentical('role: a token without a store keeps the role it was issued with', Array(pairReply('jtoken', 'htpass', tokenHead(Array('user'=>'zed', 'role'=>'system')), Array(), $rolConf, $anyRoute)[0], pairReply('jtoken', 'htpass', tokenHead(Array('user'=>'zed', 'role'=>'member')), Array(), $rolConf, $anyRoute)[0]), Array(200, 404));
checkIdentical('role: Basic access on the users file, by its role', Array(pairReply('hbasic', 'usrlst', basicHead('ben', 'pw-ben'), Array(), $rolConf, $anyRoute)[0], pairReply('hbasic', 'usrlst', basicHead('anna', 'pw-anna'), Array(), $rolConf, $anyRoute)[0]), Array(404, 200));

// Limit by address : what each pair counts against the address (two wrong credentials allowed)
plugConf(2);
/**
 * Sends the same request three times, each from the given address.
 *
 * @param String  $clientAddr Address the requests come from.
 * @param String  $carrierStr Short name of the session carrier.
 * @param ?String $checkNamed Short name of the credential check, null for none.
 * @param Array   $headersArr Headers of the requests.
 * @param Array   $serverVals Server values of the requests.
 * @param Array   $moreConfig Other keys of the 'auths' block.
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
checkIdentical('limit: hbasic x htpass, a wrong password is counted', Array(sendTrice('198.51.100.1', 'hbasic', 'htpass', basicHead('alice', 'nope')), heldAddr('198.51.100.1')), Array(Array(401, 401, 429), true));
checkIdentical('limit: the held address is refused with the right password', pairReply('hbasic', 'htpass', basicHead('alice', 's3cret'), Array('REMOTE_ADDR'=>'198.51.100.1')), Array(429, 'Too many attempts', '', null));
checkIdentical('limit: hbasic x htpass, an unknown name is counted', Array(sendTrice('198.51.100.2', 'hbasic', 'htpass', basicHead('nobody', 'nope')), heldAddr('198.51.100.2')), Array(Array(401, 401, 429), true));
checkIdentical('limit: malformed Basic credentials are not counted', Array(sendTrice('198.51.100.3', 'hbasic', 'htpass', Array('Authorization'=>'Basic !!!')), heldAddr('198.51.100.3')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a pair that cannot work is not counted', Array(sendTrice('198.51.100.4', 'header', 'htpass', Array('X-Api-Key'=>'alice')), sendTrice('198.51.100.4', 'hbasic', 'regkey', basicHead('k-1', 'k-1')), heldAddr('198.51.100.4')), Array(Array(500, 500, 500), Array(500, 500, 500), false));
checkIdentical('limit: a plug-in check that does not tell a wrong credential has each refusal counted', Array(sendTrice('198.51.100.5', 'header', 'sample', Array('X-Api-Key'=>'k-1')), heldAddr('198.51.100.5')), Array(Array(401, 401, 429), true));
checkIdentical('limit: a forged token is counted, without a store', Array(sendTrice('198.51.100.6', 'jtoken', null, Array('Authorization'=>'Bearer a.b.c')), heldAddr('198.51.100.6')), Array(Array(401, 401, 429), true));
checkIdentical('limit: and with one', Array(sendTrice('198.51.100.7', 'jtoken', 'usrlst', Array('Authorization'=>'Bearer a.b.c')), heldAddr('198.51.100.7')), Array(Array(401, 401, 429), true));
checkIdentical('limit: a token signed with another secret is counted', Array(sendTrice('198.51.100.12', 'jtoken', null, Array('Authorization'=>'Bearer '.Firebase\JWT\JWT::encode(Array('user'=>'anna', 'exp'=>time()+60), str_repeat('x', 40), 'HS256'))), heldAddr('198.51.100.12')), Array(Array(401, 401, 429), true));
checkIdentical('limit: a token that ended is not counted', Array(sendTrice('198.51.100.13', 'jtoken', null, Array('Authorization'=>'Bearer '.Firebase\JWT\JWT::encode(Array('user'=>'anna', 'exp'=>time()-10), str_repeat('s', 40), 'HS256'))), heldAddr('198.51.100.13')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a token signed here without an expiry is not counted', Array(sendTrice('198.51.100.14', 'jtoken', null, Array('Authorization'=>'Bearer '.Firebase\JWT\JWT::encode(Array('user'=>'anna'), str_repeat('s', 40), 'HS256'))), heldAddr('198.51.100.14')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a request without a token is not counted', Array(sendTrice('198.51.100.15', 'jtoken', null), heldAddr('198.51.100.15')), Array(Array(401, 401, 401), false));
checkIdentical('limit: the token of a locked user is not counted', Array(sendTrice('198.51.100.8', 'jtoken', 'usrlst', tokenHead(Array('user'=>'dave'))), heldAddr('198.51.100.8')), Array(Array(401, 401, 401), false));
checkIdentical('limit: the certificate of an unknown user is not counted', Array(sendTrice('198.51.100.9', 'clcert', 'usrlst', Array(), certVals('zed')), heldAddr('198.51.100.9')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a plug-in carrier counts nothing', Array(sendTrice('198.51.100.10', 'carier', null), heldAddr('198.51.100.10')), Array(Array(401, 401, 401), false));
checkIdentical('limit: a refusal for the role is not counted', Array(sendTrice('198.51.100.11', 'hbasic', 'htpass', basicHead('alice', 's3cret'), Array(), $rolConf, $anyRoute), heldAddr('198.51.100.11')), Array(Array(404, 404, 404), false));

// A held address : what needs no check is still served
checkIdentical('limit: a held address keeps the open routes', pairReply('hbasic', 'htpass', Array(), Array('REMOTE_ADDR'=>'198.51.100.1'), Array('openrt'=>Array('auth/login')), Array('app'=>'auth', 'action'=>'login')), Array(200, '', '', null));
checkIdentical('limit: and its other routes stay refused', pairReply('hbasic', 'htpass', basicHead('alice', 's3cret'), Array('REMOTE_ADDR'=>'198.51.100.1'), Array('openrt'=>Array('auth/login')), Array('app'=>'shows', 'action'=>'index'))[0], 429);
checkIdentical('limit: a certificate does not lift the hold of its address', pairReply('clcert', null, Array(), Array('REMOTE_ADDR'=>'198.51.100.1') + certVals('carol'))[0], 429);
$axsRslt = (new Checker( Array('needed'=>false), mockRequestObj(Array(), Array('REMOTE_ADDR'=>'198.51.100.1')) ))->validateAccess();
checkIdentical('limit: nothing is held where access is not required', $axsRslt->readFileDetail('statCode'), 200);
plugConf();

// An open route : credentials sent with the request are not read
$axsRslt = (new Checker( Array('needed'=>true, 'sessby'=>'header', 'authby'=>'regkey', 'sesscf'=>'tskeys', 'openrt'=>Array('auth/login')), mockRequestObj(Array('X-Api-Key'=>'k-1')), new Registry(Array('app'=>'auth', 'action'=>'login')) ))->validateAccess();
checkIdentical('open route: a valid key does not log its holder', Array($axsRslt->readFileDetail('statCode'), $axsRslt->readFileDetail('logged'), $axsRslt->readFileDetail('role')), Array(200, false, null));
$axsRslt = (new Checker( Array('needed'=>true, 'sessby'=>'header', 'authby'=>'regkey', 'sesscf'=>'tskeys', 'openrt'=>Array('auth/login')), mockRequestObj(Array('X-Api-Key'=>'bad')), new Registry(Array('app'=>'auth', 'action'=>'login')) ))->validateAccess();
checkIdentical('open route: nor is a wrong key refused', $axsRslt->readFileDetail('statCode'), 200);

dropTestFolder(TOP_DIR.DS.$lmtFldr);

finishChecking();
