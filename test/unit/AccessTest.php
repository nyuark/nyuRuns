<?php

// Access layer : the Auth strategies (PassList, RegKey), the Sess carriers
// (Basic, Header, JWT) and the Checker that pairs them.
// Credentials come from test/fixtures/users.htpasswd (alice / s3cret, bob / hunter2,
// erin / tr0ub4dor, frank / letmein, gina / pw-gina, hank / pw-hank).

require __DIR__.'/../lib/harness.php';

use nyu\Access\Auth\PassList;
use nyu\Access\Auth\RegKey;
use nyu\Access\Sess\Basic;
use nyu\Access\Sess\Header;
use nyu\Access\Sess\JWT;
use nyu\Access\Sess\ClCert;
use nyu\Access\Checker;
use nyu\Tools\Registry;

/**
 * Builds the Authorization header value for Basic credentials.
 *
 * @param String $userString User name.
 * @param String $passString Password.
 * @return Array Headers carrying the Basic credentials.
 */
function basicHead( String $userString, String $passString ): Array {
	return Array('Authorization'=>'Basic '.base64_encode($userString.':'.$passString));
}

// PassList : .htpasswd file, path relative to the project root
$passLst = new PassList( Array('maxtry'=>0, 'htpasswd'=>'test'.DS.'fixtures'.DS.'users.htpasswd') );
checkSame('PassList bcrypt hash',        $passLst->checkCred(Array('user'=>'alice', 'pass'=>'s3cret')), Array('user'=>'alice'));
checkSame('PassList legacy crypt hash refused by default', $passLst->checkCred(Array('user'=>'bob', 'pass'=>'hunter2')), false);
checkSame('PassList apr1 hash',          $passLst->checkCred(Array('user'=>'erin', 'pass'=>'tr0ub4dor')), Array('user'=>'erin'));
checkSame('PassList apr1 wrong password', $passLst->checkCred(Array('user'=>'erin', 'pass'=>'nope')), false);
checkSame('PassList SHA hash refused by default', $passLst->checkCred(Array('user'=>'frank', 'pass'=>'letmein')), false);
checkSame('PassList SHA hash refused message', $passLst->lastError(), 'Invalid credentials');
$weakLst = new PassList( Array('maxtry'=>0, 'htpasswd'=>'test'.DS.'fixtures'.DS.'users.htpasswd', 'unsecure'=>true) );
checkSame('PassList SHA hash in unsecure mode', $weakLst->checkCred(Array('user'=>'frank', 'pass'=>'letmein')), Array('user'=>'frank'));
checkSame('PassList SHA wrong password in unsecure mode', $weakLst->checkCred(Array('user'=>'frank', 'pass'=>'nope')), false);
checkSame('PassList MD5 crypt hash refused by default', Array($passLst->checkCred(Array('user'=>'gina', 'pass'=>'pw-gina')), $passLst->lastError()), Array(false, 'Invalid credentials'));
checkSame('PassList MD5 crypt hash in unsecure mode', $weakLst->checkCred(Array('user'=>'gina', 'pass'=>'pw-gina')), Array('user'=>'gina'));
checkSame('PassList MD5 crypt wrong password in unsecure mode', $weakLst->checkCred(Array('user'=>'gina', 'pass'=>'nope')), false);
checkSame('PassList SHA-512 crypt hash is accepted as it is', $passLst->checkCred(Array('user'=>'hank', 'pass'=>'pw-hank')), Array('user'=>'hank'));
checkSame('PassList SHA-512 crypt wrong password', $passLst->checkCred(Array('user'=>'hank', 'pass'=>'nope')), false);
checkSame('PassList legacy crypt hash in unsecure mode', $weakLst->checkCred(Array('user'=>'bob', 'pass'=>'hunter2')), Array('user'=>'bob'));
checkSame('PassList legacy crypt wrong password in unsecure mode', $weakLst->checkCred(Array('user'=>'bob', 'pass'=>'nope')), false);
checkSame('PassList wrong password',     $passLst->checkCred(Array('user'=>'alice', 'pass'=>'nope')), false);
checkSame('PassList wrong password message', $passLst->lastError(), 'Invalid credentials');
checkSame('PassList unknown user',       $passLst->checkCred(Array('user'=>'carol', 'pass'=>'x')), false);
checkSame('PassList comment line is not a user', $passLst->checkCred(Array('user'=>'# Test credentials only', 'pass'=>'x')), false);
$noStore = new PassList( Array('htpasswd'=>'test'.DS.'fixtures'.DS.'missing.htpasswd') );
checkSame('PassList missing file',       $noStore->checkCred(Array('user'=>'alice', 'pass'=>'s3cret')), false);
checkSame('PassList missing file message', $noStore->lastError(), 'Credential store unavailable');

// RegKey : flat list of API keys
$keysLst = new RegKey( Array('keylst'=>Array('k-111', 'k-222')) );
checkSame('RegKey valid key',            $keysLst->checkCred(Array('key'=>'k-222')), Array('user'=>''));
checkSame('RegKey invalid key',          $keysLst->checkCred(Array('key'=>'zzz')), false);
checkSame('RegKey invalid key message',  $keysLst->lastError(), 'Invalid API key');
checkSame('RegKey no key',               $keysLst->checkCred(Array()), false);
checkSame('RegKey no key message',       $keysLst->lastError(), 'No API key provided');

// Basic : Authorization header, checked by PassList
$basicSs = new Basic( Array(), $passLst );
checkSame('Basic valid',                 $basicSs->verifyReq(fakeReqst(basicHead('alice', 's3cret'))), Array('user'=>'alice'));
checkSame('Basic wrong password',        $basicSs->verifyReq(fakeReqst(basicHead('alice', 'nope'))), false);
checkSame('Basic wrong password message', $basicSs->lastError(), 'Invalid credentials');
checkSame('Basic password containing a colon', $basicSs->verifyReq(fakeReqst(basicHead('alice', 'a:b'))), false);
checkSame('Basic no header',             $basicSs->verifyReq(fakeReqst()), false);
checkSame('Basic no header message',     $basicSs->lastError(), 'Missing Basic Authorization header');
checkSame('Basic malformed',             $basicSs->verifyReq(fakeReqst(Array('Authorization'=>'Basic !!!'))), false);
checkSame('Basic malformed message',     $basicSs->lastError(), 'Malformed Basic credentials');
checkSame('Basic issues no token',       $basicSs->issueTokn(Array('user'=>'x')), '');
$noAuth = new Basic( Array() );
checkSame('Basic without an auth handler', $noAuth->verifyReq(fakeReqst(basicHead('alice', 's3cret'))), false);
checkSame('Basic without an auth handler message', $noAuth->lastError(), 'No credential handler configured');

// Header : API key in a configurable header, checked by RegKey
$headrSs = new Header( Array('header'=>'X-Test-Key'), $keysLst );
checkSame('Header valid',                $headrSs->verifyReq(fakeReqst(Array('X-Test-Key'=>'k-111'))), Array('user'=>''));
checkSame('Header invalid key',          $headrSs->verifyReq(fakeReqst(Array('X-Test-Key'=>'zzz'))), false);
checkSame('Header invalid key message',  $headrSs->lastError(), 'Invalid API key');
checkSame('Header missing',              $headrSs->verifyReq(fakeReqst()), false);
checkSame('Header missing message',      $headrSs->lastError(), 'Missing X-Test-Key header');
$dfltHdr = new Header( Array(), $keysLst );
checkSame('Header default name is X-Api-Key', $dfltHdr->verifyReq(fakeReqst(Array('X-Api-Key'=>'k-111'))), Array('user'=>''));

// Header with a scheme : "Authorization: Bearer <key>", only the key is checked
$bearrSs = new Header( Array('header'=>'Authorization', 'scheme'=>'Bearer'), $keysLst );
checkSame('Header scheme valid',         $bearrSs->verifyReq(fakeReqst(Array('Authorization'=>'Bearer k-111'))), Array('user'=>''));
checkSame('Header scheme in another case', $bearrSs->verifyReq(fakeReqst(Array('Authorization'=>'bearer k-111'))), Array('user'=>''));
checkSame('Header scheme missing from the value', Array($bearrSs->verifyReq(fakeReqst(Array('Authorization'=>'k-111'))), $bearrSs->lastError()), Array(false, 'Missing Bearer Authorization header'));
checkSame('Header another scheme',       $bearrSs->verifyReq(fakeReqst(Array('Authorization'=>'Basic k-111'))), false);
checkSame('Header scheme without a key', Array($bearrSs->verifyReq(fakeReqst(Array('Authorization'=>'Bearer '))), $bearrSs->lastError()), Array(false, 'No API key provided'));

// JWT : self-verifying bearer token
$jwtSess = new JWT( Array('secret'=>str_repeat('s', 40), 'lifetm'=>60) );
$jwToken = $jwtSess->issueTokn(Array('user'=>'carol'));
checkLike('JWT token shape',             $jwToken, '/^[\w-]+\.[\w-]+\.[\w-]+$/');
$payload = $jwtSess->verifyReq(fakeReqst(Array('Authorization'=>'Bearer '.$jwToken)));
checkSame('JWT round trip keeps the identity', $payload['user'] ?? null, 'carol');
checkSame('JWT expiry follows the ttl',  ($payload['exp'] ?? 0) - ($payload['iat'] ?? 0), 60);
checkSame('JWT tampered token',          $jwtSess->verifyReq(fakeReqst(Array('Authorization'=>'Bearer '.$jwToken.'x'))), false);
checkSame('JWT tampered token message',  $jwtSess->lastError(), 'Invalid token');
// What the library found wrong is never told to the client : two messages, and the reason in the log
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('jwtlogs'), 'mode'=>'FULL') );
$jwtKeys = Array(str_repeat('s', 40), 'HS256');
/**
 * Tells what a JWT carrier answers to a token.
 *
 * @param JWT    $carrierObj The carrier.
 * @param String $tokenValue The token, as the client would send it.
 * @return Array What verifyReq() gives, and the message.
 */
function tokenReply( JWT $carrierObj, String $tokenValue ): Array {
	$idntArr = $carrierObj->verifyReq(fakeReqst(Array('Authorization'=>'Bearer '.$tokenValue)));
	return Array(is_array($idntArr) ? ($idntArr['user'] ?? null) : $idntArr, $carrierObj->lastError());
}
checkSame('JWT not a token at all',      tokenReply($jwtSess, 'nope'), Array(false, 'Invalid token'));
checkSame('JWT what is not a token is a wrong credential', $jwtSess->wrongCred(), true);
checkSame('JWT a token that ended is not one', Array(tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('user'=>'carol', 'exp'=>time()-10), ...$jwtKeys))[1], $jwtSess->wrongCred()), Array('Expired token', false));
checkSame('JWT nor is a token signed here without its expiry', Array(tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('user'=>'carol'), ...$jwtKeys))[1], $jwtSess->wrongCred()), Array('Invalid token', false));
checkSame('JWT nor is a missing token', Array($jwtSess->verifyReq(fakeReqst()), $jwtSess->wrongCred()), Array(false, false));
checkSame('JWT signed with another algorithm', tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('user'=>'carol', 'exp'=>time()+60), str_repeat('s', 64), 'HS512')), Array(false, 'Invalid token'));
checkSame('JWT not valid yet',           tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('user'=>'carol', 'exp'=>time()+600, 'nbf'=>time()+300), ...$jwtKeys)), Array(false, 'Invalid token'));
checkSame('JWT expired',                 tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('user'=>'carol', 'exp'=>time()-10), ...$jwtKeys)), Array(false, 'Expired token'));
checkSame('JWT signed, without an expiry', tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('user'=>'carol'), ...$jwtKeys)), Array(false, 'Invalid token'));
checkSame('JWT signed, without a user',  tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('role'=>'manage', 'exp'=>time()+60), ...$jwtKeys)), Array(false, 'Invalid token'));
checkSame('JWT signed, with an empty user', tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('user'=>'', 'exp'=>time()+60), ...$jwtKeys)), Array(false, 'Invalid token'));
checkSame('JWT signed, with a user that is not a text', tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('user'=>Array('carol'), 'exp'=>time()+60), ...$jwtKeys)), Array(false, 'Invalid token'));
checkSame('JWT signed here, with its expiry and its user', tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('user'=>'carol', 'exp'=>time()+60), ...$jwtKeys))[0], 'carol');
nyu\Tools\Logger::plugSetup( Array() );
$jwtLogs = strval(@file_get_contents(inTempDir('jwtlogs').DS.'access.log'));
checkSame('JWT the reason is told in the log, at the debug level', Array(preg_match('/\[DEBUG\] Token refused: UnexpectedValueException: Wrong number of segments/', $jwtLogs), preg_match('/\[DEBUG\] Token refused: signed, but without an expiry or a user/', $jwtLogs), substr_count($jwtLogs, 'Token refused')), Array(1, 1, 8));
checkSame('JWT no header',               $jwtSess->verifyReq(fakeReqst()), false);
checkSame('JWT no header message',       $jwtSess->lastError(), 'Missing Bearer Authorization header');
$othrJwt = new JWT( Array('secret'=>str_repeat('o', 40)) );
checkSame('JWT signed with another secret', $othrJwt->verifyReq(fakeReqst(Array('Authorization'=>'Bearer '.$jwToken))), false);
$noScrt = new JWT( Array() );
checkSame('JWT without a secret',        $noScrt->verifyReq(fakeReqst(Array('Authorization'=>'Bearer '.$jwToken))), false);
checkSame('JWT without a secret message', $noScrt->lastError(), 'JWT secret not configured');
$shrtJwt = new JWT( Array('secret'=>'REPLACE_ME') );
checkSame('JWT with a short secret',     $shrtJwt->verifyReq(fakeReqst(Array('Authorization'=>'Bearer '.$jwToken))), false);
checkSame('JWT with a short secret message', $shrtJwt->lastError(), 'JWT secret not configured');
checkSame('JWT with a short secret issues no token', $shrtJwt->issueTokn(Array('user'=>'x')), '');

// ClCert : reads what the web server reported about the TLS client certificate
$crtVals = Array('SSL_CLIENT_VERIFY'=>'SUCCESS', 'SSL_CLIENT_S_DN_CN'=>'carol', 'SSL_CLIENT_I_DN_CN'=>'Test CA');
$certSes = new ClCert( Array() );
checkSame('ClCert verified certificate',  $certSes->verifyReq(fakeReqst(Array(), $crtVals)), Array('user'=>'carol'));
checkSame('ClCert no certificate',        Array($certSes->verifyReq(fakeReqst()), $certSes->lastError()), Array(false, 'No verified client certificate'));
checkSame('ClCert not presented',         $certSes->verifyReq(fakeReqst(Array(), Array('SSL_CLIENT_VERIFY'=>'NONE') + $crtVals)), false);
checkSame('ClCert failed verification',   $certSes->verifyReq(fakeReqst(Array(), Array('SSL_CLIENT_VERIFY'=>'FAILED:certificate has expired') + $crtVals)), false);
checkSame('ClCert verified, without a name', Array($certSes->verifyReq(fakeReqst(Array(), Array('SSL_CLIENT_S_DN_CN'=>'  ') + $crtVals)), $certSes->lastError()), Array(false, 'Certificate names no user'));
checkSame('ClCert the same values sent as headers are ignored', $certSes->verifyReq(fakeReqst(Array('SSL-Client-Verify'=>'SUCCESS', 'SSL-Client-S-DN-CN'=>'carol'), Array('HTTP_SSL_CLIENT_VERIFY'=>'SUCCESS', 'HTTP_SSL_CLIENT_S_DN_CN'=>'carol'))), false);
checkSame('ClCert issues no token',       $certSes->issueTokn(Array('user'=>'x')), '');

$pinSess = new ClCert( Array('issuer'=>'Test CA') );
checkSame('ClCert accepted issuer',       $pinSess->verifyReq(fakeReqst(Array(), $crtVals)), Array('user'=>'carol'));
$pinSess = new ClCert( Array('issuer'=>'Other CA') );
checkSame('ClCert other issuer',          Array($pinSess->verifyReq(fakeReqst(Array(), $crtVals)), $pinSess->lastError()), Array(false, 'Certificate issuer not accepted'));

$nginxSs = new ClCert( Array('chkvar'=>'TLS_VERIFIED', 'usrvar'=>'TLS_SUBJECT') );
checkSame('ClCert other variable names',  $nginxSs->verifyReq(fakeReqst(Array(), Array('TLS_VERIFIED'=>'SUCCESS', 'TLS_SUBJECT'=>'dora'))), Array('user'=>'dora'));
$hdrSess = new ClCert( Array('chkvar'=>'HTTP_X_SSL_VERIFY', 'usrvar'=>'HTTP_X_SSL_CN') );
checkSame('ClCert variables that are request headers are refused', Array($hdrSess->verifyReq(fakeReqst(Array(), Array('HTTP_X_SSL_VERIFY'=>'SUCCESS', 'HTTP_X_SSL_CN'=>'carol'))), $hdrSess->lastError()), Array(false, 'Certificate variables misconfigured'));

// ClCert with a credential store : the certificate's user must be one of its users
$fakeStr = new class(Array()) implements nyu\Access\Auth\Handler {
	public function __construct( Array $confValues ){
	}
	public function checkCred( Array $credsArray ): Array|Bool {
		return false;
	}
	public function userIdent( String $userString ): Array|Bool {
		return ($userString=='carol') ? Array('user'=>'carol', 'role'=>'manage', 'id'=>7) : false;
	}
	public function lastError(): String {
		return 'Account not active';
	}
};
$strSess = new ClCert( Array(), $fakeStr );
checkSame('ClCert with a store: known user, with its role', $strSess->verifyReq(fakeReqst(Array(), $crtVals)), Array('user'=>'carol', 'role'=>'manage', 'id'=>7));
checkSame('ClCert with a store: unknown user', Array($strSess->verifyReq(fakeReqst(Array(), Array('SSL_CLIENT_S_DN_CN'=>'zed') + $crtVals)), $strSess->lastError()), Array(false, 'Account not active'));

// Checker : reads the strategy pair from the 'auths' block, and its config from the ACCESS registry row
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('LIMITS'=>Array('maxtry'=>0),'TESTKEYS'=>Array('keylst'=>Array('k-1')))) );
$hdrConf = Array('needed'=>true, 'sessby'=>'header', 'authby'=>'regkey', 'sesscf'=>'testkeys');

$checker = new Checker( Array('needed'=>false), fakeReqst() );
checkSame('Checker not required: goes through, not logged', Array($checker->verifyAxs()->readValue('statCode'), $checker->verifyAxs()->readValue('logged'), $checker->verifyAxs()->readValue('role')), Array(200, false, null));
$checker = new Checker( Array(), fakeReqst() );
checkSame('Checker empty config',        $checker->verifyAxs()->readValue('statCode'), 200);

$checker = new Checker( $hdrConf, fakeReqst(Array('X-Api-Key'=>'k-1')) );
$axsRslt = $checker->verifyAxs();
checkSame('Checker granted',             $axsRslt->readValue('logged'), true);
checkSame('Checker granted status',      $axsRslt->readValue('statCode'), 200);
checkSame('Checker granted: member role by default', $axsRslt->readValue('role'), 'member');

$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('LIMITS'=>Array('maxtry'=>0),'TESTJWT'=>Array('secret'=>str_repeat('s', 40)))) );
$jwtConf = Array('needed'=>true, 'sessby'=>'jtoken', 'sesscf'=>'testjwt');
$checker = new Checker( $jwtConf, fakeReqst(Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'carol', 'role'=>'manage')))) );
checkSame('Checker keeps a known role',  $checker->verifyAxs()->readValue('role'), 'manage');
$checker = new Checker( $jwtConf, fakeReqst(Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'carol', 'role'=>'root')))) );
checkSame('Checker turns an unknown role into member', $checker->verifyAxs()->readValue('role'), 'member');

// The access log : a privileged user is a notice of its own, with its role and what was asked for
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );
$admHead = Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'carol', 'role'=>'manage')));
$adrVals = Array('REMOTE_ADDR'=>'10.0.0.1');
(new Checker( $jwtConf, fakeReqst($admHead, $adrVals), new Registry(Array('app'=>'Users', 'action'=>'remove')) ))->verifyAxs();
(new Checker( $jwtConf, fakeReqst(Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'dave', 'role'=>'system'))), $adrVals), new Registry(Array('app'=>null, 'action'=>null, 'rtePath'=>'docs/*')) ))->verifyAxs();
(new Checker( $jwtConf, fakeReqst(Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'erin', 'role'=>'member'))), $adrVals) ))->verifyAxs();
(new Checker( $jwtConf, fakeReqst(Array('Authorization'=>'Bearer nope'), $adrVals) ))->verifyAxs();
nyu\Tools\Logger::plugSetup( Array() );
$axsLogs = file_exists(inTempDir('logs').DS.'access.log') ? file(inTempDir('logs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkSame('access log: one line per check', count($axsLogs), 4);
checkSame('access log: privileged access to an action', $axsLogs[0] ?? null, '['.date('Y').'] [NOTICE] Privileged access granted for user "carol" (manage) from 10.0.0.1 to Users/remove');
checkSame('access log: privileged access to a declared route', $axsLogs[1] ?? null, '['.date('Y').'] [NOTICE] Privileged access granted for user "dave" (system) from 10.0.0.1 to /docs/*');
checkSame('access log: a member stays an info', $axsLogs[2] ?? null, '['.date('Y').'] [INFO] Access granted for user "erin" from 10.0.0.1');
checkLike('access log: a denial stays a warning', $axsLogs[3] ?? null, '/^\['.date('Y').'\] \[WARNING\] Access denied from 10\.0\.0\.1: /');
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('LIMITS'=>Array('maxtry'=>0),'TESTKEYS'=>Array('keylst'=>Array('k-1')))) );

$checker = new Checker( $hdrConf, fakeReqst(Array('X-Api-Key'=>'bad')) );
$axsRslt = $checker->verifyAxs();
checkSame('Checker denied',              $axsRslt->readValue('logged'), false);
checkSame('Checker denied status',       $axsRslt->readValue('statCode'), 401);
checkSame('Checker denied message',      $axsRslt->readValue('statMssg'), 'Invalid API key');
checkSame('Checker denied, no Basic challenge', $axsRslt->readValue('wwwAuth'), null);

$checker = new Checker( Array('sessby'=>'hbasic', 'authby'=>'htpass', 'sesscf'=>'testkeys'), fakeReqst() );
$axsRslt = $checker->verifyAxs();
checkSame('Checker Basic denied status', $axsRslt->readValue('statCode'), 401);
checkSame('Checker Basic challenge, with its realm', $axsRslt->readValue('wwwAuth'), 'Basic realm="nyuRuns", charset="UTF-8"');

// A carrier and a check that cannot work together : a mistake of the config, told as such
checkSame('pairsFlaw: the pairs that work', Array(
	(new Checker(Array('sessby'=>'hbasic', 'authby'=>'htpass'), fakeReqst()))->pairsFlaw(), (new Checker(Array('sessby'=>'hbasic', 'authby'=>'native'), fakeReqst()))->pairsFlaw(),
	(new Checker(Array('sessby'=>'header', 'authby'=>'regkey'), fakeReqst()))->pairsFlaw(), (new Checker(Array('sessby'=>'header', 'authby'=>'usrlst'), fakeReqst()))->pairsFlaw(),
	(new Checker(Array('sessby'=>'jtoken'), fakeReqst()))->pairsFlaw(), (new Checker(Array('sessby'=>'phsess', 'authby'=>'htpass'), fakeReqst()))->pairsFlaw(),
	(new Checker(Array('sessby'=>'clcert', 'authby'=>'regkey'), fakeReqst()))->pairsFlaw(), (new Checker(Array('sessby'=>'header', 'authby'=>'device'), fakeReqst()))->pairsFlaw()
), array_fill(0, 8, ''));
checkSame('pairsFlaw: a name and a password handed to a check of keys', (new Checker(Array('sessby'=>'hbasic', 'authby'=>'regkey'), fakeReqst()))->pairsFlaw(), 'the credential check "regkey" cannot check what the carrier "hbasic" hands it');
checkSame('pairsFlaw: a name and a password handed to the check of the device tokens', (new Checker(Array('sessby'=>'hbasic', 'authby'=>'device'), fakeReqst()))->pairsFlaw(), 'the credential check "device" cannot check what the carrier "hbasic" hands it');
checkSame('pairsFlaw: a key handed to a check of passwords', (new Checker(Array('sessby'=>'header', 'authby'=>'htpass'), fakeReqst()))->pairsFlaw(), 'the credential check "htpass" cannot check what the carrier "header" hands it');
checkSame('pairsFlaw: a carrier left without a check', Array((new Checker(Array('sessby'=>'hbasic'), fakeReqst()))->pairsFlaw(), (new Checker(Array('sessby'=>'header', 'authby'=>''), fakeReqst()))->pairsFlaw()), Array('the carrier "hbasic" needs a credential check (authby)', 'the carrier "header" needs a credential check (authby)'));
$axsRslt = (new Checker( Array('sessby'=>'hbasic', 'authby'=>'regkey', 'sesscf'=>'testkeys'), fakeReqst(Array('Authorization'=>'Basic '.base64_encode('k-1:k-1'))) ))->verifyAxs();
checkSame('Checker: such a pair refuses every request with a 500, without a challenge', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg'), $axsRslt->readValue('wwwAuth')), Array(500, 'Access misconfigured', null));

$checker = new Checker( Array('sessby'=>'jtoken', 'sesscf'=>'testkeys'), fakeReqst() );
$axsRslt = $checker->verifyAxs();
checkSame('Checker JWT denied status',   $axsRslt->readValue('statCode'), 401);
checkSame('Checker JWT challenge',       $axsRslt->readValue('wwwAuth'), 'Bearer');

// Checker : public routes, matched on the resolved application and action
$pubConf = Array('needed'=>true, 'sessby'=>'header', 'authby'=>'regkey', 'sesscf'=>'testkeys', 'openrt'=>Array('auth/login', 'Open/*'));
$checker = new Checker( $pubConf, fakeReqst(), new Registry(Array('app'=>'auth', 'action'=>'login')) );
checkSame('Checker public action',       $checker->verifyAxs()->readValue('statCode'), 200);
checkSame('Checker public action: not logged', $checker->verifyAxs()->readValue('logged'), false);
$checker = new Checker( $pubConf, fakeReqst(), new Registry(Array('app'=>'Auth', 'action'=>'LOGIN')) );
checkSame('Checker public action, other case', $checker->verifyAxs()->readValue('statCode'), 200);
$checker = new Checker( $pubConf, fakeReqst(), new Registry(Array('app'=>'open', 'action'=>'anything')) );
checkSame('Checker public application',  $checker->verifyAxs()->readValue('statCode'), 200);
$checker = new Checker( $pubConf, fakeReqst(), new Registry(Array('app'=>'auth', 'action'=>'other')) );
checkSame('Checker unlisted action stays closed', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( $pubConf, fakeReqst(), new Registry(Array('app'=>'auth', 'action'=>null)) );
checkSame('Checker default action stays closed', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( $pubConf, fakeReqst(), new Registry(Array('app'=>'shows', 'action'=>'login')) );
checkSame('Checker other application stays closed', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( $pubConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'statCode'=>204)) );
checkSame('Checker route-answered OPTIONS is open', $checker->verifyAxs()->readValue('statCode'), 200);
$checker = new Checker( $pubConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'statCode'=>405)) );
checkSame('Checker route-answered refusal stays closed', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( $pubConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null)) );
checkSame('Checker unresolved route stays closed', $checker->verifyAxs()->readValue('statCode'), 401);

// Checker : public routes, named by the path they are declared with
$pthConf = Array('openrt'=>Array('/login', '/tokchk/', '/items/$id', '/docs/*', '/Auth/Other')) + $pubConf;
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'htpage', 'rtePath'=>'login')) );
checkSame('Checker public path: a page',  $checker->verifyAxs()->readValue('statCode'), 200);
checkSame('Checker public path: not logged', $checker->verifyAxs()->readValue('logged'), false);
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>'auth', 'action'=>'check', 'rteKind'=>'action', 'rtePath'=>'tokchk')) );
checkSame('Checker public path: an action', $checker->verifyAxs()->readValue('statCode'), 200);
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'statCode'=>405, 'rtePath'=>'login')) );
checkSame('Checker public path: its refusals are open too', $checker->verifyAxs()->readValue('statCode'), 200);
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>'probe', 'action'=>'mirror', 'rtePath'=>'items/$id')) );
checkSame('Checker public path: a pattern, as it is declared', $checker->verifyAxs()->readValue('statCode'), 200);
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'static', 'rtePath'=>'docs/*')) );
checkSame('Checker public path: a folder route, as it is declared', $checker->verifyAxs()->readValue('statCode'), 200);
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'static', 'rtePath'=>'docs/private/*')) );
checkSame('Checker public path: its * does not open another folder route', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'static', 'rtePath'=>'docs/guide.txt')) );
checkSame('Checker public path: nor a route declared under it', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( Array('openrt'=>Array('/docs')) + $pubConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'static', 'rtePath'=>'docs/*')) );
checkSame('Checker public path: a folder route needs its *', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'htpage', 'rtePath'=>'Login')) );
checkSame('Checker public path: the case counts', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'htpage', 'rtePath'=>'other')) );
checkSame('Checker public path: another page stays closed', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>'login', 'action'=>null)) );
checkSame('Checker public path: is not an application name', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( $pthConf, fakeReqst(), new Registry(Array('app'=>'auth', 'action'=>'other')) );
checkSame('Checker public path: is not an action either', $checker->verifyAxs()->readValue('statCode'), 401);
$checker = new Checker( Array('openrt'=>Array('login')) + $pubConf, fakeReqst(), new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'htpage', 'rtePath'=>'login')) );
checkSame('Checker public path: needs its leading slash', $checker->verifyAxs()->readValue('statCode'), 401);

// Checker : a request denied an HTML page is given the sign-in route to go to
$sgnConf = Array('signin'=>'/login') + $pthConf;
$askReqs = fakeReqst();
$askPrpt = new ReflectionProperty('nyu\Tools\Request', 'queryURI');
$askPrpt->setValue($askReqs, Array('reqstURI'=>'/hello/7?tab=a b'));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'htpage', 'rtePath'=>'hello/$id')) );
$axsRslt = $checker->verifyAxs();
checkSame('Checker sign-in: a denied page is still a 401', $axsRslt->readValue('statCode'), 401);
checkSame('Checker sign-in: with the route to go to, and the page asked for', $axsRslt->readValue('denyGoal'), '/login?back=%2Fhello%2F7%3Ftab%3Da%20b');
$askPrpt->setValue($askReqs, Array('reqstURI'=>'/'));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'htpage', 'rtePath'=>'index')) );
checkSame('Checker sign-in: the home page is not carried back', $checker->verifyAxs()->readValue('denyGoal'), '/login');
$askPrpt->setValue($askReqs, Array('reqstURI'=>'/shows/view/1'));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('app'=>'shows', 'action'=>'view', 'rteKind'=>'action', 'rtePath'=>'')) );
checkSame('Checker sign-in: a denied action keeps its 401', Array($checker->verifyAxs()->readValue('statCode'), $checker->verifyAxs()->readValue('denyGoal')), Array(401, null));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'static', 'rtePath'=>'style')) );
checkSame('Checker sign-in: so does a denied file', Array($checker->verifyAxs()->readValue('statCode'), $checker->verifyAxs()->readValue('denyGoal')), Array(401, null));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('app'=>null, 'action'=>null)) );
checkSame('Checker sign-in: and an unresolved route', $checker->verifyAxs()->readValue('denyGoal'), null);
$checker = new Checker( Array('signin'=>'/enter') + $pubConf, $askReqs, new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'htpage', 'rtePath'=>'enter')) );
checkSame('Checker sign-in: a sign-in route left closed is not sent to itself', Array($checker->verifyAxs()->readValue('statCode'), $checker->verifyAxs()->readValue('denyGoal')), Array(401, null));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'htpage', 'rtePath'=>'login')) );
checkSame('Checker sign-in: the open sign-in route is served', $checker->verifyAxs()->readValue('statCode'), 200);
foreach( Array('https://example.org/login', '//example.org/login', 'login', '/log in', Array('/login')) as $badRte ){
	$checker = new Checker( Array('signin'=>$badRte) + $pthConf, $askReqs, new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'htpage', 'rtePath'=>'other')) );
	$axsRslt = $checker->verifyAxs();
	checkSame('Checker sign-in: '.json_encode($badRte).' is not a path of this site', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('denyGoal')), Array(500, null));
}

$checker = new Checker( Array('sessby'=>'Nope'), fakeReqst() );
checkSame('Checker unknown sess strategy', $checker->verifyAxs()->readValue('statCode'), 500);
$checker = new Checker( Array('sessby'=>'header', 'authby'=>'Nope'), fakeReqst() );
checkSame('Checker unknown auth strategy', $checker->verifyAxs()->readValue('statCode'), 500);

// The Probe fixture application, for the helpers of an action
require_once TST_DIR.DS.'fixtures'.DS.'runs'.DS.'Probe'.DS.'Actions.php';

// Checker : the roles are ordered, each one includes those before it
checkSame('roleMeets: a role includes those before it', Array(Checker::roleMeets('manage', 'member'), Checker::roleMeets('system', 'system'), Checker::roleMeets('member', 'system'), Checker::roleMeets('system', 'manage')), Array(true, true, false, false));
checkSame('roleMeets: no role, or an unknown one, meets nothing', Array(Checker::roleMeets(null, 'member'), Checker::roleMeets('root', 'member'), Checker::roleMeets('manage', 'root')), Array(false, false, false));

/**
 * Runs the access check of a route for the user carol, logged with a
 * token carrying the given role.
 *
 * @param Array  $accessConf The 'auths' block.
 * @param String $loggedRole Role of the token, empty for a request without a token.
 * @param Array  $routeArray The resolved route.
 * @return Array The status and the message of the check.
 */
function roleReply( Array $accessConf, String $loggedRole, Array $routeArray ): Array {
	$tknHead = ($loggedRole!='') ? Array('Authorization'=>'Bearer '.$GLOBALS['jwtSess']->issueTokn(Array('user'=>'carol', 'role'=>$loggedRole))) : Array();
	$axsRslt = (new Checker( $accessConf, fakeReqst($tknHead, Array('REMOTE_ADDR'=>'10.0.0.1')), new Registry($routeArray) ))->verifyAxs();
	return Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg'));
}

// Checker : the role a route asks for ('rolert'), written as the routes of 'openrt'
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('LIMITS'=>Array('maxtry'=>0),'TESTKEYS'=>Array('keylst'=>Array('k-1')), 'TESTJWT'=>Array('secret'=>str_repeat('s', 40)))) );
$rolConf = Array('rolert'=>Array('users/*'=>'manage', 'users/view'=>'member', 'Shows/Edit'=>'system', 'shows/*'=>'member', 'probe/clean'=>'system', 'probe/mirror'=>'manage', '/docs/*'=>'system', '/tokchk'=>'manage', 'auth/check'=>'system', '/admin'=>'manage')) + $jwtConf;
checkSame('Checker role: refused to a lower role', roleReply($rolConf, 'member', Array('app'=>'users', 'action'=>'lock')), Array(404, 'Not Found'));
checkSame('Checker role: refused to the role between', roleReply($rolConf, 'system', Array('app'=>'users', 'action'=>'lock')), Array(404, 'Not Found'));
checkSame('Checker role: served to the role asked for', roleReply($rolConf, 'manage', Array('app'=>'users', 'action'=>'lock')), Array(200, ''));
checkSame('Checker role: served to a higher role', Array(roleReply($rolConf, 'system', Array('app'=>'shows', 'action'=>'edit')), roleReply($rolConf, 'manage', Array('app'=>'shows', 'action'=>'edit'))), Array(Array(200, ''), Array(200, '')));
checkSame('Checker role: the case of the request does not count', roleReply($rolConf, 'member', Array('app'=>'Users', 'action'=>'LOCK')), Array(404, 'Not Found'));
checkSame('Checker role: nor a space in the name of the application', roleReply($rolConf, 'member', Array('app'=>'us ers', 'action'=>'lock')), Array(404, 'Not Found'));
checkSame('Checker role: the highest of the entries naming the route is asked for', Array(roleReply($rolConf, 'member', Array('app'=>'shows', 'action'=>'edit')), roleReply($rolConf, 'member', Array('app'=>'shows', 'action'=>'index'))), Array(Array(404, 'Not Found'), Array(200, '')));
checkSame('Checker role: an entry cannot lower the one of its application', roleReply($rolConf, 'member', Array('app'=>'users', 'action'=>'view')), Array(404, 'Not Found'));
checkSame('Checker role: no action named, the highest of the application', Array(roleReply($rolConf, 'system', Array('app'=>'probe', 'action'=>null)), roleReply($rolConf, 'manage', Array('app'=>'probe', 'action'=>null))), Array(Array(404, 'Not Found'), Array(200, '')));
checkSame('Checker role: a route that asks for none', roleReply($rolConf, 'member', Array('app'=>'auth', 'action'=>'other')), Array(200, ''));
checkSame('Checker role: nobody logged is a 401 first', roleReply($rolConf, '', Array('app'=>'users', 'action'=>'lock'))[0], 401);
$dirRoute = Array('app'=>null, 'action'=>null, 'rteKind'=>'static', 'rtePath'=>'docs/*');
checkSame('Checker role: a declared route, by its path', Array(roleReply($rolConf, 'member', $dirRoute), roleReply($rolConf, 'system', $dirRoute)), Array(Array(404, 'Not Found'), Array(200, '')));
checkSame('Checker role: its * does not name another folder route', roleReply($rolConf, 'member', Array('rtePath'=>'docs/private/*') + $dirRoute), Array(200, ''));
$actRoute = Array('app'=>'auth', 'action'=>'check', 'rteKind'=>'action', 'rtePath'=>'tokchk');
checkSame('Checker role: named by its path and by its action, the highest', Array(roleReply($rolConf, 'system', $actRoute), roleReply($rolConf, 'manage', $actRoute)), Array(Array(404, 'Not Found'), Array(200, '')));
checkSame('Checker role: the refusal of a route that answers by itself', roleReply($rolConf, 'member', Array('statCode'=>405) + $dirRoute), Array(404, 'Not Found'));
checkSame('Checker role: its own OPTIONS answer needs no role', roleReply($rolConf, '', Array('statCode'=>204) + $dirRoute), Array(200, ''));
// A declared path whose route leaves its action open : the role follows the action reached by its own path
$pnlConf = Array('rolert'=>Array('/panel'=>'manage')) + $jwtConf;
$autoRte = Array('app'=>'reports', 'action'=>'admin', 'rteFrom'=>Array('panel'));
checkSame('Checker role: the role of a declared path applies to its action reached by its own path', Array(roleReply($pnlConf, 'member', $autoRte), roleReply($pnlConf, 'manage', $autoRte)), Array(Array(404, 'Not Found'), Array(200, '')));
checkSame('Checker role: another action of the application is not named by it', roleReply($pnlConf, 'member', Array('app'=>'reports', 'action'=>'index', 'rteFrom'=>Array())), Array(200, ''));

// The refused user stays identified, and is not sent to the sign-in route
$axsRslt = (new Checker( Array('signin'=>'/login', 'openrt'=>Array('/login')) + $rolConf, fakeReqst(Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'carol', 'role'=>'system')))), new Registry(Array('app'=>null, 'action'=>null, 'rteKind'=>'htpage', 'rtePath'=>'admin')) ))->verifyAxs();
checkSame('Checker role: a refused user is known, with no challenge and no sign-in route', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('logged'), $axsRslt->readValue('user'), $axsRslt->readValue('role'), $axsRslt->readValue('wwwAuth'), $axsRslt->readValue('denyGoal')), Array(404, true, 'carol', 'system', null, null));

// A check that gives no role makes a member
$keyConf = Array('rolert'=>Array('shows/*'=>'member', 'users/*'=>'system')) + $hdrConf;
$checker = new Checker( $keyConf, fakeReqst(Array('X-Api-Key'=>'k-1')), new Registry(Array('app'=>'shows', 'action'=>'index')) );
checkSame('Checker role: an API key meets the member role', $checker->verifyAxs()->readValue('statCode'), 200);
$checker = new Checker( $keyConf, fakeReqst(Array('X-Api-Key'=>'k-1')), new Registry(Array('app'=>'users', 'action'=>'index')) );
checkSame('Checker role: and no higher one', Array($checker->verifyAxs()->readValue('statCode'), $checker->verifyAxs()->readValue('role')), Array(404, 'member'));

// A block that cannot be applied refuses every request
$anyRoute = Array('app'=>'shows', 'action'=>'index');
checkSame('Checker role: an unknown role', roleReply(Array('rolert'=>Array('users/*'=>'admin')) + $jwtConf, 'manage', $anyRoute), Array(500, 'Access misconfigured'));
checkSame('Checker role: a role that is not a text', roleReply(Array('rolert'=>Array('users/*'=>Array('manage'))) + $jwtConf, 'manage', $anyRoute), Array(500, 'Access misconfigured'));
checkSame('Checker role: not a list of routes', roleReply(Array('rolert'=>'manage') + $jwtConf, 'manage', $anyRoute), Array(500, 'Access misconfigured'));
checkSame('Checker role: asked for while access is not required', roleReply(Array('needed'=>false, 'rolert'=>Array('users/*'=>'manage')), 'manage', $anyRoute), Array(500, 'Access misconfigured'));
checkSame('Checker role: an empty list asks for nothing', roleReply(Array('needed'=>false, 'rolert'=>Array()), '', $anyRoute), Array(200, ''));
$opnConf = Array('openrt'=>Array('users/*', '/docs/*')) + $rolConf;
checkSame('Checker role: a route both open and asking for a role', Array(roleReply($opnConf, 'manage', Array('app'=>'users', 'action'=>'lock')), roleReply($opnConf, '', $dirRoute)), Array(Array(500, 'Access misconfigured'), Array(500, 'Access misconfigured')));
checkSame('Checker role: the other open routes are served', roleReply(Array('openrt'=>Array('auth/login')) + $rolConf, '', Array('app'=>'auth', 'action'=>'login')), Array(200, ''));

// The access log : one warning for a refusal, with the role needed
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('rolelogs'), 'mode'=>'BASE', 'pref'=>'Y') );
roleReply($rolConf, 'member', Array('app'=>'users', 'action'=>'lock'));
roleReply($rolConf, 'member', $dirRoute);
roleReply(Array('rolert'=>Array('users/*'=>'admin')) + $jwtConf, 'manage', $anyRoute);
nyu\Tools\Logger::plugSetup( Array() );
$axsLogs = file_exists(inTempDir('rolelogs').DS.'access.log') ? file(inTempDir('rolelogs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkSame('access log: a refusal for the role is one line', count($axsLogs), 3);
checkSame('access log: the role needed by an action', $axsLogs[0] ?? null, '['.date('Y').'] [WARNING] Access refused for user "carol" (member) from 10.0.0.1 to users/lock: role manage needed');
checkSame('access log: the role needed by a declared route', $axsLogs[1] ?? null, '['.date('Y').'] [WARNING] Access refused for user "carol" (member) from 10.0.0.1 to /docs/*: role system needed');
checkSame('access log: a role that cannot be applied', $axsLogs[2] ?? null, '['.date('Y').'] [WARNING] Access denied from 10.0.0.1: Access misconfigured: unknown role for the route "users\/*"');

// Actionset : the helpers an action tests the role with (test/fixtures/runs/Probe, action 'roles')
/**
 * Runs the 'roles' action of the Probe application.
 *
 * @param String $loggedRole Role of the user carol, empty for nobody logged.
 * @param String $neededRole Role the action asks for, empty for none.
 * @return Array The status, the message and the output of the action.
 */
function roleActn( String $loggedRole, String $neededRole='' ): Array {
	$axssReg = new Registry( ($loggedRole!='') ? Array('logged'=>true, 'user'=>'carol', 'role'=>$loggedRole) : Array('logged'=>false, 'user'=>'', 'role'=>null) );
	$actnSet = new nyu\Runs\Probe\Actions( new nyu\App\RunContext('roles', ($neededRole!='') ? $neededRole : null, new Registry(Array()), $axssReg, fakeReqst(Array(), Array('REMOTE_ADDR'=>'10.0.0.1')), null, null, null, 'Probe', RUN_DIR.DS.'Probe') );
	$actnSet->runAction();
	return Array($actnSet->statCode, strval($actnSet->statMssg), $actnSet->asOutput);
}
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('actnlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
checkSame('holdsRole: the highest role holds them all', roleActn('manage')[2], Array('member'=>true, 'system'=>true, 'manage'=>true));
checkSame('holdsRole: a role and those before it', roleActn('system')[2], Array('member'=>true, 'system'=>true, 'manage'=>false));
checkSame('holdsRole: nobody logged holds none', roleActn('')[2], Array('member'=>false, 'system'=>false, 'manage'=>false));
checkSame('needsRole: the action goes on with the role', roleActn('system', 'system')[0], 200);
checkSame('needsRole: ends it with a 404 without', roleActn('member', 'system'), Array(404, 'Not Found', null));
checkSame('needsRole: and when nobody is logged', roleActn('', 'member'), Array(404, 'Not Found', null));
checkThrw('needsRole: an unknown role throws', function(){ roleActn('manage', 'root'); }, 'RuntimeException');
nyu\Tools\Logger::plugSetup( Array() );
$axsLogs = file_exists(inTempDir('actnlogs').DS.'access.log') ? file(inTempDir('actnlogs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkSame('needsRole: its refusals are logged as those of a route', $axsLogs, Array(
	'['.date('Y').'] [WARNING] Access refused for user "carol" (member) from 10.0.0.1 to probe/roles: role system needed',
	'['.date('Y').'] [WARNING] Access refused for user "" (no role) from 10.0.0.1 to probe/roles: role member needed'
));

// The issuer of a certificate is read from the variable the config names
$crtVals = Array('SSL_CLIENT_VERIFY'=>'SUCCESS', 'SSL_CLIENT_S_DN_CN'=>'carol', 'SSL_CLIENT_I_DN_CN'=>'Test CA');
$varSess = new ClCert( Array('issvar'=>'TLS_ISSUER', 'issuer'=>'Test CA') );
checkSame('ClCert issuer read from another variable', $varSess->verifyReq(fakeReqst(Array(), Array('TLS_ISSUER'=>'Test CA') + $crtVals)), Array('user'=>'carol'));
checkSame('ClCert the default variable is then not read', Array($varSess->verifyReq(fakeReqst(Array(), Array('TLS_ISSUER'=>'Other CA') + $crtVals)), $varSess->lastError(), $varSess->verifyReq(fakeReqst(Array(), $crtVals))), Array(false, 'Certificate issuer not accepted', false));
$hdrSess = new ClCert( Array('issvar'=>'HTTP_X_SSL_ISSUER', 'issuer'=>'Test CA') );
checkSame('ClCert an issuer variable that is a request header is refused', Array($hdrSess->verifyReq(fakeReqst(Array(), Array('HTTP_X_SSL_ISSUER'=>'Test CA') + $crtVals)), $hdrSess->lastError()), Array(false, 'Certificate variables misconfigured'));

// API keys written under a name identify their holder
$namdKey = new RegKey( Array('keylst'=>Array('orders'=>'k-ordr', 'reports'=>'k-rept', 'nobody'=>'', 'wrongs'=>Array('k-list'))) );
checkSame('RegKey a key written under a name gives the name', Array($namdKey->checkCred(Array('key'=>'k-rept')), $namdKey->checkCred(Array('key'=>'k-ordr'))), Array(Array('user'=>'reports'), Array('user'=>'orders')));
checkSame('RegKey a wrong key, with named keys', Array($namdKey->checkCred(Array('key'=>'k-none')), $namdKey->lastError(), $namdKey->wrongCred()), Array(false, 'Invalid API key', true));
checkSame('RegKey a name is not a key, nor is a key that is not a text', Array($namdKey->checkCred(Array('key'=>'reports')), $namdKey->checkCred(Array('key'=>'k-list'))), Array(false, false));
checkSame('RegKey a plain list still names nobody', (new RegKey(Array('keylst'=>Array('k-1', 'k-2'))))->checkCred(Array('key'=>'k-2')), Array('user'=>''));
checkSame('RegKey a keylst that is not a list accepts nothing', (new RegKey(Array('keylst'=>'k-1')))->checkCred(Array('key'=>'k-1')), false);
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('keylogs'), 'mode'=>'BASE', 'pref'=>'Y') );
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('LIMITS'=>Array('maxtry'=>0),'NAMEKEYS'=>Array('keylst'=>Array('reports'=>'k-rept', "eve\" from 9.9.9.9\n[".date('Y')."] [INFO] forged"=>'k-evil')))) );
$keyConf = Array('needed'=>true, 'sessby'=>'header', 'authby'=>'regkey', 'sesscf'=>'namekeys');
$axsRslt = (new Checker( $keyConf, fakeReqst(Array('X-Api-Key'=>'k-rept'), Array('REMOTE_ADDR'=>'10.0.0.1')) ))->verifyAxs();
checkSame('Checker named key: the holder is the user, a member', Array($axsRslt->readValue('statCode'), $axsRslt->readValue('user'), $axsRslt->readValue('role')), Array(200, 'reports', 'member'));
(new Checker( $keyConf, fakeReqst(Array('X-Api-Key'=>'k-evil'), Array('REMOTE_ADDR'=>'10.0.0.1')) ))->verifyAxs();
nyu\Tools\Logger::plugSetup( Array() );
checkSame('access log: a member name is written as a JSON text, and cannot forge an entry', file_exists(inTempDir('keylogs').DS.'access.log') ? file(inTempDir('keylogs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array(), Array(
	'['.date('Y').'] [INFO] Access granted for user "reports" from 10.0.0.1',
	'['.date('Y').'] [INFO] Access granted for user "eve\" from 9.9.9.9\n['.date('Y').'] [INFO] forged" from 10.0.0.1'
));

endChecks();
