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
$passLst = new PassList( Array('htpasswd'=>'test'.DS.'fixtures'.DS.'users.htpasswd') );
checkSame('PassList bcrypt hash',        $passLst->checkCred(Array('user'=>'alice', 'pass'=>'s3cret')), Array('user'=>'alice'));
checkSame('PassList legacy crypt hash refused by default', $passLst->checkCred(Array('user'=>'bob', 'pass'=>'hunter2')), false);
checkSame('PassList apr1 hash',          $passLst->checkCred(Array('user'=>'erin', 'pass'=>'tr0ub4dor')), Array('user'=>'erin'));
checkSame('PassList apr1 wrong password', $passLst->checkCred(Array('user'=>'erin', 'pass'=>'nope')), false);
checkSame('PassList SHA hash refused by default', $passLst->checkCred(Array('user'=>'frank', 'pass'=>'letmein')), false);
checkSame('PassList SHA hash refused message', $passLst->lastError(), 'Invalid credentials');
$weakLst = new PassList( Array('htpasswd'=>'test'.DS.'fixtures'.DS.'users.htpasswd', 'unsecure'=>true) );
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
checkTrue('JWT tampered token message',  $jwtSess->lastError()!='');
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
		return ($userString=='carol') ? Array('user'=>'carol', 'role'=>'admin', 'id'=>7) : false;
	}
	public function lastError(): String {
		return 'Account not active';
	}
};
$strSess = new ClCert( Array(), $fakeStr );
checkSame('ClCert with a store: known user, with its role', $strSess->verifyReq(fakeReqst(Array(), $crtVals)), Array('user'=>'carol', 'role'=>'admin', 'id'=>7));
checkSame('ClCert with a store: unknown user', Array($strSess->verifyReq(fakeReqst(Array(), Array('SSL_CLIENT_S_DN_CN'=>'zed') + $crtVals)), $strSess->lastError()), Array(false, 'Account not active'));

// Checker : reads the strategy pair from the 'auths' block, and its config from the ACCESS registry row
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TESTKEYS'=>Array('keylst'=>Array('k-1')))) );
$hdrConf = Array('needed'=>true, 'sessby'=>'header', 'authby'=>'regkey', 'sesscf'=>'testkeys');

$checker = new Checker( Array('needed'=>false), fakeReqst() );
checkSame('Checker not required: goes through, not logged', Array($checker->verifyAxs()->readValue('statCode'), $checker->verifyAxs()->readValue('logged'), $checker->verifyAxs()->readValue('role')), Array(200, false, null));
$checker = new Checker( Array(), fakeReqst() );
checkSame('Checker empty config',        $checker->verifyAxs()->readValue('statCode'), 200);

$checker = new Checker( $hdrConf, fakeReqst(Array('X-Api-Key'=>'k-1')) );
$axsRslt = $checker->verifyAxs();
checkSame('Checker granted',             $axsRslt->readValue('logged'), true);
checkSame('Checker granted status',      $axsRslt->readValue('statCode'), 200);
checkSame('Checker granted: member role by default', $axsRslt->readValue('role'), 'membr');

$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TESTJWT'=>Array('secret'=>str_repeat('s', 40)))) );
$jwtConf = Array('needed'=>true, 'sessby'=>'jtoken', 'sesscf'=>'testjwt');
$checker = new Checker( $jwtConf, fakeReqst(Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'carol', 'role'=>'admin')))) );
checkSame('Checker keeps a known role',  $checker->verifyAxs()->readValue('role'), 'admin');
$checker = new Checker( $jwtConf, fakeReqst(Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'carol', 'role'=>'root')))) );
checkSame('Checker turns an unknown role into member', $checker->verifyAxs()->readValue('role'), 'membr');

// The access log : a privileged user is a notice of its own, with its role and what was asked for
nyu\Tools\Logger::plugSetup( Array('path'=>inTempDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );
$admHead = Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'carol', 'role'=>'admin')));
$adrVals = Array('REMOTE_ADDR'=>'10.0.0.1');
(new Checker( $jwtConf, fakeReqst($admHead, $adrVals), new Registry(Array('app'=>'Users', 'action'=>'remove')) ))->verifyAxs();
(new Checker( $jwtConf, fakeReqst(Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'dave', 'role'=>'systm'))), $adrVals), new Registry(Array('app'=>null, 'action'=>null, 'rtePath'=>'docs/*')) ))->verifyAxs();
(new Checker( $jwtConf, fakeReqst(Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'erin', 'role'=>'membr'))), $adrVals) ))->verifyAxs();
(new Checker( $jwtConf, fakeReqst(Array('Authorization'=>'Bearer nope'), $adrVals) ))->verifyAxs();
nyu\Tools\Logger::plugSetup( Array() );
$axsLogs = file_exists(inTempDir('logs').DS.'access.log') ? file(inTempDir('logs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkSame('access log: one line per check', count($axsLogs), 4);
checkSame('access log: privileged access to an action', $axsLogs[0] ?? null, '['.date('Y').'] [NOTICE] Privileged access granted for user "carol" (admin) from 10.0.0.1 to Users/remove');
checkSame('access log: privileged access to a declared route', $axsLogs[1] ?? null, '['.date('Y').'] [NOTICE] Privileged access granted for user "dave" (systm) from 10.0.0.1 to /docs/*');
checkSame('access log: a member stays an info', $axsLogs[2] ?? null, '['.date('Y').'] [INFO] Access granted for user "erin" from 10.0.0.1');
checkLike('access log: a denial stays a warning', $axsLogs[3] ?? null, '/^\['.date('Y').'\] \[WARNING\] Access denied from 10\.0\.0\.1: /');
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TESTKEYS'=>Array('keylst'=>Array('k-1')))) );

$checker = new Checker( $hdrConf, fakeReqst(Array('X-Api-Key'=>'bad')) );
$axsRslt = $checker->verifyAxs();
checkSame('Checker denied',              $axsRslt->readValue('logged'), false);
checkSame('Checker denied status',       $axsRslt->readValue('statCode'), 401);
checkSame('Checker denied message',      $axsRslt->readValue('statMssg'), 'Invalid API key');
checkSame('Checker denied, no Basic challenge', $axsRslt->readValue('wwwAuth'), null);

$checker = new Checker( Array('sessby'=>'hbasic', 'authby'=>'regkey', 'sesscf'=>'testkeys'), fakeReqst() );
$axsRslt = $checker->verifyAxs();
checkSame('Checker Basic denied status', $axsRslt->readValue('statCode'), 401);
checkSame('Checker Basic challenge',     $axsRslt->readValue('wwwAuth'), 'Basic');

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
$askPrpt->setAccessible(true);
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

endChecks();
