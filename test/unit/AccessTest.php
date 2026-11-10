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
use nyu\Tools\Router;

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
$passLst = new PassList( Array('maxTries'=>0, 'passFile'=>'test'.DS.'fixtures'.DS.'users.htpasswd') );
checkIdentical('PassList bcrypt hash',        $passLst->checkSecretArr(Array('user'=>'alice', 'pass'=>'s3cret')), Array('userName'=>'alice'));
checkIdentical('PassList legacy crypt hash refused by default', $passLst->checkSecretArr(Array('user'=>'bob', 'pass'=>'hunter2')), false);
checkIdentical('PassList apr1 hash',          $passLst->checkSecretArr(Array('user'=>'erin', 'pass'=>'tr0ub4dor')), Array('userName'=>'erin'));
checkIdentical('PassList apr1 wrong password', $passLst->checkSecretArr(Array('user'=>'erin', 'pass'=>'nope')), false);
checkIdentical('PassList SHA hash refused by default', $passLst->checkSecretArr(Array('user'=>'frank', 'pass'=>'letmein')), false);
checkIdentical('PassList SHA hash refused message', $passLst->finalErrorText(), 'Invalid credentials');
$weakLst = new PassList( Array('maxTries'=>0, 'passFile'=>'test'.DS.'fixtures'.DS.'users.htpasswd', 'weakHash'=>true) );
checkIdentical('PassList SHA hash in unsecure mode', $weakLst->checkSecretArr(Array('user'=>'frank', 'pass'=>'letmein')), Array('userName'=>'frank'));
checkIdentical('PassList SHA wrong password in unsecure mode', $weakLst->checkSecretArr(Array('user'=>'frank', 'pass'=>'nope')), false);
checkIdentical('PassList MD5 crypt hash refused by default', Array($passLst->checkSecretArr(Array('user'=>'gina', 'pass'=>'pw-gina')), $passLst->finalErrorText()), Array(false, 'Invalid credentials'));
checkIdentical('PassList MD5 crypt hash in unsecure mode', $weakLst->checkSecretArr(Array('user'=>'gina', 'pass'=>'pw-gina')), Array('userName'=>'gina'));
checkIdentical('PassList MD5 crypt wrong password in unsecure mode', $weakLst->checkSecretArr(Array('user'=>'gina', 'pass'=>'nope')), false);
checkIdentical('PassList SHA-512 crypt hash is accepted as it is', $passLst->checkSecretArr(Array('user'=>'hank', 'pass'=>'pw-hank')), Array('userName'=>'hank'));
checkIdentical('PassList SHA-512 crypt wrong password', $passLst->checkSecretArr(Array('user'=>'hank', 'pass'=>'nope')), false);
checkIdentical('PassList legacy crypt hash in unsecure mode', $weakLst->checkSecretArr(Array('user'=>'bob', 'pass'=>'hunter2')), Array('userName'=>'bob'));
checkIdentical('PassList legacy crypt wrong password in unsecure mode', $weakLst->checkSecretArr(Array('user'=>'bob', 'pass'=>'nope')), false);
checkIdentical('PassList wrong password',     $passLst->checkSecretArr(Array('user'=>'alice', 'pass'=>'nope')), false);
checkIdentical('PassList wrong password message', $passLst->finalErrorText(), 'Invalid credentials');
checkIdentical('PassList unknown user',       $passLst->checkSecretArr(Array('user'=>'carol', 'pass'=>'x')), false);
checkIdentical('PassList comment line is not a user', $passLst->checkSecretArr(Array('user'=>'# Test credentials only', 'pass'=>'x')), false);
$noStore = new PassList( Array('passFile'=>'test'.DS.'fixtures'.DS.'missing.htpasswd') );
checkIdentical('PassList missing file',       $noStore->checkSecretArr(Array('user'=>'alice', 'pass'=>'s3cret')), false);
checkIdentical('PassList missing file message', $noStore->finalErrorText(), 'Credential store unavailable');

// RegKey : flat list of API keys
$keysLst = new RegKey( Array('keysList'=>Array('k-111', 'k-222')) );
checkIdentical('RegKey valid key',            $keysLst->checkSecretArr(Array('key'=>'k-222')), Array('userName'=>''));
checkIdentical('RegKey invalid key',          $keysLst->checkSecretArr(Array('key'=>'zzz')), false);
checkIdentical('RegKey invalid key message',  $keysLst->finalErrorText(), 'Invalid API key');
checkIdentical('RegKey no key',               $keysLst->checkSecretArr(Array()), false);
checkIdentical('RegKey no key message',       $keysLst->finalErrorText(), 'No API key provided');

// Basic : Authorization header, checked by PassList
$basicSs = new Basic( Array(), $passLst );
checkIdentical('Basic valid',                 $basicSs->verifyRqstList(mockRequestObj(basicHead('alice', 's3cret'))), Array('userName'=>'alice'));
checkIdentical('Basic wrong password',        $basicSs->verifyRqstList(mockRequestObj(basicHead('alice', 'nope'))), false);
checkIdentical('Basic wrong password message', $basicSs->finalErrorText(), 'Invalid credentials');
checkIdentical('Basic password containing a colon', $basicSs->verifyRqstList(mockRequestObj(basicHead('alice', 'a:b'))), false);
checkIdentical('Basic no header',             $basicSs->verifyRqstList(mockRequestObj()), false);
checkIdentical('Basic no header message',     $basicSs->finalErrorText(), 'Missing Basic Authorization header');
checkIdentical('Basic malformed',             $basicSs->verifyRqstList(mockRequestObj(Array('Authorization'=>'Basic !!!'))), false);
checkIdentical('Basic malformed message',     $basicSs->finalErrorText(), 'Malformed Basic credentials');
checkIdentical('Basic issues no token',       $basicSs->issueTokenText(Array('userName'=>'x')), '');
$noAuth = new Basic( Array() );
checkIdentical('Basic without an auth handler', $noAuth->verifyRqstList(mockRequestObj(basicHead('alice', 's3cret'))), false);
checkIdentical('Basic without an auth handler message', $noAuth->finalErrorText(), 'No credential handler configured');

// Header : API key in a configurable header, checked by RegKey
$headrSs = new Header( Array('headName'=>'X-Test-Key'), $keysLst );
checkIdentical('Header valid',                $headrSs->verifyRqstList(mockRequestObj(Array('X-Test-Key'=>'k-111'))), Array('userName'=>''));
checkIdentical('Header invalid key',          $headrSs->verifyRqstList(mockRequestObj(Array('X-Test-Key'=>'zzz'))), false);
checkIdentical('Header invalid key message',  $headrSs->finalErrorText(), 'Invalid API key');
checkIdentical('Header missing',              $headrSs->verifyRqstList(mockRequestObj()), false);
checkIdentical('Header missing message',      $headrSs->finalErrorText(), 'Missing X-Test-Key header');
$dfltHdr = new Header( Array(), $keysLst );
checkIdentical('Header default name is X-Api-Key', $dfltHdr->verifyRqstList(mockRequestObj(Array('X-Api-Key'=>'k-111'))), Array('userName'=>''));

// Header with a scheme : "Authorization: Bearer <key>", only the key is checked
$bearrSs = new Header( Array('headName'=>'Authorization', 'authSchm'=>'Bearer'), $keysLst );
checkIdentical('Header scheme valid',         $bearrSs->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer k-111'))), Array('userName'=>''));
checkIdentical('Header scheme in another case', $bearrSs->verifyRqstList(mockRequestObj(Array('Authorization'=>'bearer k-111'))), Array('userName'=>''));
checkIdentical('Header scheme missing from the value', Array($bearrSs->verifyRqstList(mockRequestObj(Array('Authorization'=>'k-111'))), $bearrSs->finalErrorText()), Array(false, 'Missing Bearer Authorization header'));
checkIdentical('Header another scheme',       $bearrSs->verifyRqstList(mockRequestObj(Array('Authorization'=>'Basic k-111'))), false);
checkIdentical('Header scheme without a key', Array($bearrSs->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '))), $bearrSs->finalErrorText()), Array(false, 'No API key provided'));

// JWT : self-verifying bearer token
$jwtSess = new JWT( Array('hmacSecr'=>str_repeat('s', 40), 'lifeSecs'=>60) );
$jwToken = $jwtSess->issueTokenText(Array('userName'=>'carol'));
checkPregMatch('JWT token shape',             $jwToken, '/^[\w-]+\.[\w-]+\.[\w-]+$/');
$payload = $jwtSess->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$jwToken)));
checkIdentical('JWT round trip keeps the identity', $payload['userName'] ?? null, 'carol');
checkIdentical('JWT expiry follows the ttl',  ($payload['exp'] ?? 0) - ($payload['iat'] ?? 0), 60);
checkIdentical('JWT tampered token',          $jwtSess->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$jwToken.'x'))), false);
checkIdentical('JWT tampered token message',  $jwtSess->finalErrorText(), 'Invalid token');
// What the library found wrong is never told to the client : two messages, and the reason in the log
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('jwtlogs'), 'mode'=>'FULL') );
$jwtKeys = Array(str_repeat('s', 40), 'HS256');
/**
 * Tells what a JWT carrier answers to a token.
 *
 * @param JWT    $carrierObj The carrier.
 * @param String $tokenValue The token, as the client would send it.
 * @return Array What verifyRqstList() gives, and the message.
 */
function tokenReply( JWT $carrierObj, String $tokenValue ): Array {
	$idntArr = $carrierObj->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$tokenValue)));
	return Array(is_array($idntArr) ? ($idntArr['userName'] ?? null) : $idntArr, $carrierObj->finalErrorText());
}
checkIdentical('JWT not a token at all',      tokenReply($jwtSess, 'nope'), Array(false, 'Invalid token'));
checkIdentical('JWT what is not a token is a wrong credential', $jwtSess->hasWrongSecret(), true);
checkIdentical('JWT a token that ended is not one', Array(tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('userName'=>'carol', 'exp'=>time()-10), ...$jwtKeys))[1], $jwtSess->hasWrongSecret()), Array('Expired token', false));
checkIdentical('JWT nor is a token signed here without its expiry', Array(tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('userName'=>'carol'), ...$jwtKeys))[1], $jwtSess->hasWrongSecret()), Array('Invalid token', false));
checkIdentical('JWT nor is a missing token', Array($jwtSess->verifyRqstList(mockRequestObj()), $jwtSess->hasWrongSecret()), Array(false, false));
checkIdentical('JWT signed with another algorithm', tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('userName'=>'carol', 'exp'=>time()+60), str_repeat('s', 64), 'HS512')), Array(false, 'Invalid token'));
checkIdentical('JWT not valid yet',           tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('userName'=>'carol', 'exp'=>time()+600, 'nbf'=>time()+300), ...$jwtKeys)), Array(false, 'Invalid token'));
checkIdentical('JWT expired',                 tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('userName'=>'carol', 'exp'=>time()-10), ...$jwtKeys)), Array(false, 'Expired token'));
checkIdentical('JWT signed, without an expiry', tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('userName'=>'carol'), ...$jwtKeys)), Array(false, 'Invalid token'));
checkIdentical('JWT signed, without a user',  tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('userRole'=>'sysadmin', 'exp'=>time()+60), ...$jwtKeys)), Array(false, 'Invalid token'));
checkIdentical('JWT signed, with an empty user', tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('userName'=>'', 'exp'=>time()+60), ...$jwtKeys)), Array(false, 'Invalid token'));
checkIdentical('JWT signed, with a user that is not a text', tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('userName'=>Array('carol'), 'exp'=>time()+60), ...$jwtKeys)), Array(false, 'Invalid token'));
checkIdentical('JWT signed here, with its expiry and its user', tokenReply($jwtSess, Firebase\JWT\JWT::encode(Array('userName'=>'carol', 'exp'=>time()+60), ...$jwtKeys))[0], 'carol');
nyu\Tools\Logger::plugLogsConfig( Array() );
$jwtLogs = strval(@file_get_contents(inTemporaryDir('jwtlogs').DS.'access.log'));
checkIdentical('JWT the reason is told in the log, at the debug level', Array(preg_match('/\[DEBUG\] Token refused: UnexpectedValueException: Wrong number of segments/', $jwtLogs), preg_match('/\[DEBUG\] Token refused: signed, but without an expiry or a user/', $jwtLogs), substr_count($jwtLogs, 'Token refused')), Array(1, 1, 8));
checkIdentical('JWT no header',               $jwtSess->verifyRqstList(mockRequestObj()), false);
checkIdentical('JWT no header message',       $jwtSess->finalErrorText(), 'Missing Bearer Authorization header');
$othrJwt = new JWT( Array('hmacSecr'=>str_repeat('o', 40)) );
checkIdentical('JWT signed with another secret', $othrJwt->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$jwToken))), false);
$noScrt = new JWT( Array() );
checkIdentical('JWT without a secret',        $noScrt->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$jwToken))), false);
checkIdentical('JWT without a secret message', $noScrt->finalErrorText(), 'JWT secret not configured');
$shrtJwt = new JWT( Array('hmacSecr'=>'REPLACE_ME') );
checkIdentical('JWT with a short secret',     $shrtJwt->verifyRqstList(mockRequestObj(Array('Authorization'=>'Bearer '.$jwToken))), false);
checkIdentical('JWT with a short secret message', $shrtJwt->finalErrorText(), 'JWT secret not configured');
checkIdentical('JWT with a short secret issues no token', $shrtJwt->issueTokenText(Array('userName'=>'x')), '');

// ClCert : reads what the web server reported about the TLS client certificate
$crtVals = Array('SSL_CLIENT_VERIFY'=>'SUCCESS', 'SSL_CLIENT_S_DN_CN'=>'carol', 'SSL_CLIENT_I_DN_CN'=>'Test CA');
$certSes = new ClCert( Array() );
checkIdentical('ClCert verified certificate',  $certSes->verifyRqstList(mockRequestObj(Array(), $crtVals)), Array('userName'=>'carol'));
checkIdentical('ClCert no certificate',        Array($certSes->verifyRqstList(mockRequestObj()), $certSes->finalErrorText()), Array(false, 'No verified client certificate'));
checkIdentical('ClCert not presented',         $certSes->verifyRqstList(mockRequestObj(Array(), Array('SSL_CLIENT_VERIFY'=>'NONE') + $crtVals)), false);
checkIdentical('ClCert failed verification',   $certSes->verifyRqstList(mockRequestObj(Array(), Array('SSL_CLIENT_VERIFY'=>'FAILED:certificate has expired') + $crtVals)), false);
checkIdentical('ClCert verified, without a name', Array($certSes->verifyRqstList(mockRequestObj(Array(), Array('SSL_CLIENT_S_DN_CN'=>'  ') + $crtVals)), $certSes->finalErrorText()), Array(false, 'Certificate names no user'));
checkIdentical('ClCert the same values sent as headers are ignored', $certSes->verifyRqstList(mockRequestObj(Array('SSL-Client-Verify'=>'SUCCESS', 'SSL-Client-S-DN-CN'=>'carol'), Array('HTTP_SSL_CLIENT_VERIFY'=>'SUCCESS', 'HTTP_SSL_CLIENT_S_DN_CN'=>'carol'))), false);
checkIdentical('ClCert issues no token',       $certSes->issueTokenText(Array('userName'=>'x')), '');

$pinSess = new ClCert( Array('issuerId'=>'Test CA') );
checkIdentical('ClCert accepted issuer',       $pinSess->verifyRqstList(mockRequestObj(Array(), $crtVals)), Array('userName'=>'carol'));
$pinSess = new ClCert( Array('issuerId'=>'Other CA') );
checkIdentical('ClCert other issuer',          Array($pinSess->verifyRqstList(mockRequestObj(Array(), $crtVals)), $pinSess->finalErrorText()), Array(false, 'Certificate issuer not accepted'));

$nginxSs = new ClCert( Array('checkVar'=>'TLS_VERIFIED', 'ownerVar'=>'TLS_SUBJECT') );
checkIdentical('ClCert other variable names',  $nginxSs->verifyRqstList(mockRequestObj(Array(), Array('TLS_VERIFIED'=>'SUCCESS', 'TLS_SUBJECT'=>'dora'))), Array('userName'=>'dora'));
$hdrSess = new ClCert( Array('checkVar'=>'HTTP_X_SSL_VERIFY', 'ownerVar'=>'HTTP_X_SSL_CN') );
checkIdentical('ClCert variables that are request headers are refused', Array($hdrSess->verifyRqstList(mockRequestObj(Array(), Array('HTTP_X_SSL_VERIFY'=>'SUCCESS', 'HTTP_X_SSL_CN'=>'carol'))), $hdrSess->finalErrorText()), Array(false, 'Certificate variables misconfigured'));

// ClCert with a credential store : the certificate's user must be one of its users
$fakeStr = new class(Array()) implements nyu\Access\Auth\Handler {
	public function __construct( Array $confValues ){
	}
	public function checkSecretArr( Array $credsArray ): Array|Bool {
		return false;
	}
	public function usrIdentityArr( String $userString ): Array|Bool {
		return ($userString=='carol') ? Array('userName'=>'carol', 'userRole'=>'sysadmin', 'idOfUser'=>7) : false;
	}
	public function finalErrorText(): String {
		return 'Account not active';
	}
};
$strSess = new ClCert( Array(), $fakeStr );
checkIdentical('ClCert with a store: known user, with its role', $strSess->verifyRqstList(mockRequestObj(Array(), $crtVals)), Array('userName'=>'carol', 'userRole'=>'sysadmin', 'idOfUser'=>7));
checkIdentical('ClCert with a store: unknown user', Array($strSess->verifyRqstList(mockRequestObj(Array(), Array('SSL_CLIENT_S_DN_CN'=>'zed') + $crtVals)), $strSess->finalErrorText()), Array(false, 'Account not active'));

// Checker : reads the strategy pair from the 'security' block, and its config from the ACCESS registry row
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TRYLIMIT'=>Array('maxTries'=>0),'TESTKEYS'=>Array('keysList'=>Array('k-1')))) );
$hdrConf = Array('required'=>true, 'sessWith'=>'apiHeadr', 'authWith'=>'regdKeys', 'sessConf'=>'testkeys');

$checker = new Checker( Array('required'=>false), mockRequestObj() );
checkIdentical('Checker not required: goes through, not logged', Array($checker->validateAccess()->readValueByKey('statCode'), $checker->validateAccess()->readValueByKey('loggedIn'), $checker->validateAccess()->readValueByKey('userRole')), Array(200, false, null));
$checker = new Checker( Array(), mockRequestObj() );
checkIdentical('Checker empty config',        $checker->validateAccess()->readValueByKey('statCode'), 200);

$checker = new Checker( $hdrConf, mockRequestObj(Array('X-Api-Key'=>'k-1')) );
$axsRslt = $checker->validateAccess();
checkIdentical('Checker granted',             $axsRslt->readValueByKey('loggedIn'), true);
checkIdentical('Checker granted status',      $axsRslt->readValueByKey('statCode'), 200);
checkIdentical('Checker granted: member role by default', $axsRslt->readValueByKey('userRole'), 'internal');

$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TRYLIMIT'=>Array('maxTries'=>0),'TESTJWT'=>Array('hmacSecr'=>str_repeat('s', 40)))) );
$jwtConf = Array('required'=>true, 'sessWith'=>'jwtToken', 'sessConf'=>'testjwt');
$checker = new Checker( $jwtConf, mockRequestObj(Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'carol', 'userRole'=>'sysadmin')))) );
checkIdentical('Checker keeps a known role',  $checker->validateAccess()->readValueByKey('userRole'), 'sysadmin');
$checker = new Checker( $jwtConf, mockRequestObj(Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'carol', 'userRole'=>'root')))) );
checkIdentical('Checker turns an unknown role into member', $checker->validateAccess()->readValueByKey('userRole'), 'internal');

// The access log : a privileged user is a notice of its own, with its role and what was asked for
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );
$admHead = Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'carol', 'userRole'=>'sysadmin')));
$adrVals = Array('REMOTE_ADDR'=>'10.0.0.1');
(new Checker( $jwtConf, mockRequestObj($admHead, $adrVals), new Registry(Array('appToRun'=>'Users', 'doAction'=>'dropUser')) ))->validateAccess();
(new Checker( $jwtConf, mockRequestObj(Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'dave', 'userRole'=>'operator'))), $adrVals), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routPath'=>'docs/*')) ))->validateAccess();
(new Checker( $jwtConf, mockRequestObj(Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'erin', 'userRole'=>'internal'))), $adrVals) ))->validateAccess();
(new Checker( $jwtConf, mockRequestObj(Array('Authorization'=>'Bearer nope'), $adrVals) ))->validateAccess();
nyu\Tools\Logger::plugLogsConfig( Array() );
$axsLogs = file_exists(inTemporaryDir('logs').DS.'access.log') ? file(inTemporaryDir('logs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkIdentical('access log: one line per check', count($axsLogs), 4);
checkIdentical('access log: privileged access to an action', $axsLogs[0] ?? null, '['.date('Y').'] [NOTICE] Privileged access granted for user "carol" (sysadmin) from 10.0.0.1 to Users/dropUser');
checkIdentical('access log: privileged access to a declared route', $axsLogs[1] ?? null, '['.date('Y').'] [NOTICE] Privileged access granted for user "dave" (operator) from 10.0.0.1 to /docs/*');
checkIdentical('access log: a member stays an info', $axsLogs[2] ?? null, '['.date('Y').'] [INFO] Access granted for user "erin" from 10.0.0.1');
checkPregMatch('access log: a denial stays a warning', $axsLogs[3] ?? null, '/^\['.date('Y').'\] \[WARNING\] Access denied from 10\.0\.0\.1: /');
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TRYLIMIT'=>Array('maxTries'=>0),'TESTKEYS'=>Array('keysList'=>Array('k-1')))) );

$checker = new Checker( $hdrConf, mockRequestObj(Array('X-Api-Key'=>'bad')) );
$axsRslt = $checker->validateAccess();
checkIdentical('Checker denied',              $axsRslt->readValueByKey('loggedIn'), false);
checkIdentical('Checker denied status',       $axsRslt->readValueByKey('statCode'), 401);
checkIdentical('Checker denied message',      $axsRslt->readValueByKey('statMssg'), 'Invalid API key');
checkIdentical('Checker denied, no Basic challenge', $axsRslt->readValueByKey('wwwHeadr'), null);

$checker = new Checker( Array('sessWith'=>'httpBasc', 'authWith'=>'htpasswd', 'sessConf'=>'testkeys'), mockRequestObj() );
$axsRslt = $checker->validateAccess();
checkIdentical('Checker Basic denied status', $axsRslt->readValueByKey('statCode'), 401);
checkIdentical('Checker Basic challenge, with its realm', $axsRslt->readValueByKey('wwwHeadr'), 'Basic realm="nyuRuns", charset="UTF-8"');

// A carrier and a check that cannot work together : a mistake of the config, told as such
checkIdentical('pairsFlaw: the pairs that work', Array(
	(new Checker(Array('sessWith'=>'httpBasc', 'authWith'=>'htpasswd'), mockRequestObj()))->checkPairFlaws(), (new Checker(Array('sessWith'=>'httpBasc', 'authWith'=>'nativeDb'), mockRequestObj()))->checkPairFlaws(),
	(new Checker(Array('sessWith'=>'apiHeadr', 'authWith'=>'regdKeys'), mockRequestObj()))->checkPairFlaws(), (new Checker(Array('sessWith'=>'apiHeadr', 'authWith'=>'userFile'), mockRequestObj()))->checkPairFlaws(),
	(new Checker(Array('sessWith'=>'jwtToken'), mockRequestObj()))->checkPairFlaws(), (new Checker(Array('sessWith'=>'phpSessn', 'authWith'=>'htpasswd'), mockRequestObj()))->checkPairFlaws(),
	(new Checker(Array('sessWith'=>'clntCert', 'authWith'=>'regdKeys'), mockRequestObj()))->checkPairFlaws(), (new Checker(Array('sessWith'=>'apiHeadr', 'authWith'=>'devToken'), mockRequestObj()))->checkPairFlaws()
), array_fill(0, 8, ''));
checkIdentical('pairsFlaw: a name and a password handed to a check of keys', (new Checker(Array('sessWith'=>'httpBasc', 'authWith'=>'regdKeys'), mockRequestObj()))->checkPairFlaws(), 'the credential check "regdKeys" cannot check what the carrier "httpBasc" hands it');
checkIdentical('pairsFlaw: a name and a password handed to the check of the device tokens', (new Checker(Array('sessWith'=>'httpBasc', 'authWith'=>'devToken'), mockRequestObj()))->checkPairFlaws(), 'the credential check "devToken" cannot check what the carrier "httpBasc" hands it');
checkIdentical('pairsFlaw: a key handed to a check of passwords', (new Checker(Array('sessWith'=>'apiHeadr', 'authWith'=>'htpasswd'), mockRequestObj()))->checkPairFlaws(), 'the credential check "htpasswd" cannot check what the carrier "apiHeadr" hands it');
checkIdentical('pairsFlaw: a carrier left without a check', Array((new Checker(Array('sessWith'=>'httpBasc'), mockRequestObj()))->checkPairFlaws(), (new Checker(Array('sessWith'=>'apiHeadr', 'authWith'=>''), mockRequestObj()))->checkPairFlaws()), Array('the carrier "httpBasc" needs a credential check (authWith)', 'the carrier "apiHeadr" needs a credential check (authWith)'));
$axsRslt = (new Checker( Array('sessWith'=>'httpBasc', 'authWith'=>'regdKeys', 'sessConf'=>'testkeys'), mockRequestObj(Array('Authorization'=>'Basic '.base64_encode('k-1:k-1'))) ))->validateAccess();
checkIdentical('Checker: such a pair refuses every request with a 500, without a challenge', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('statMssg'), $axsRslt->readValueByKey('wwwHeadr')), Array(500, 'Access misconfigured', null));

$checker = new Checker( Array('sessWith'=>'jwtToken', 'sessConf'=>'testkeys'), mockRequestObj() );
$axsRslt = $checker->validateAccess();
checkIdentical('Checker JWT denied status',   $axsRslt->readValueByKey('statCode'), 401);
checkIdentical('Checker JWT challenge',       $axsRslt->readValueByKey('wwwHeadr'), 'Bearer');

// Checker : public routes, matched on the resolved application and action
$pubConf = Array('required'=>true, 'sessWith'=>'apiHeadr', 'authWith'=>'regdKeys', 'sessConf'=>'testkeys', 'openList'=>Array('auth/openSess', 'Open/*'));
$checker = new Checker( $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>'auth', 'doAction'=>'openSess')) );
checkIdentical('Checker public action',       $checker->validateAccess()->readValueByKey('statCode'), 200);
checkIdentical('Checker public action: not logged', $checker->validateAccess()->readValueByKey('loggedIn'), false);
$checker = new Checker( $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>'Auth', 'doAction'=>'OPENSESS')) );
checkIdentical('Checker public action, other case', $checker->validateAccess()->readValueByKey('statCode'), 200);
$checker = new Checker( $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>'open', 'doAction'=>'anything')) );
checkIdentical('Checker public application',  $checker->validateAccess()->readValueByKey('statCode'), 200);
$checker = new Checker( $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>'auth', 'doAction'=>'other')) );
checkIdentical('Checker unlisted action stays closed', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>'auth', 'doAction'=>null)) );
checkIdentical('Checker default action stays closed', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>'shows', 'doAction'=>'login')) );
checkIdentical('Checker other application stays closed', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'statCode'=>204)) );
checkIdentical('Checker route-answered OPTIONS is open', $checker->validateAccess()->readValueByKey('statCode'), 200);
$checker = new Checker( $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'statCode'=>405)) );
checkIdentical('Checker route-answered refusal stays closed', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null)) );
checkIdentical('Checker unresolved route stays closed', $checker->validateAccess()->readValueByKey('statCode'), 401);

// Checker : public routes, named by the path they are declared with
$pthConf = Array('openList'=>Array('/login', '/tokchk/', '/items/$id', '/docs/*', '/Auth/Other')) + $pubConf;
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'htmlPage', 'routPath'=>'login')) );
checkIdentical('Checker public path: a page',  $checker->validateAccess()->readValueByKey('statCode'), 200);
checkIdentical('Checker public path: not logged', $checker->validateAccess()->readValueByKey('loggedIn'), false);
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>'auth', 'doAction'=>'check', 'routKind'=>'toAction', 'routPath'=>'tokchk')) );
checkIdentical('Checker public path: an action', $checker->validateAccess()->readValueByKey('statCode'), 200);
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'statCode'=>405, 'routPath'=>'login')) );
checkIdentical('Checker public path: its refusals are open too', $checker->validateAccess()->readValueByKey('statCode'), 200);
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>'probe', 'doAction'=>'mirrored', 'routPath'=>'items/$id')) );
checkIdentical('Checker public path: a pattern, as it is declared', $checker->validateAccess()->readValueByKey('statCode'), 200);
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'toStatic', 'routPath'=>'docs/*')) );
checkIdentical('Checker public path: a folder route, as it is declared', $checker->validateAccess()->readValueByKey('statCode'), 200);
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'toStatic', 'routPath'=>'docs/private/*')) );
checkIdentical('Checker public path: its * does not open another folder route', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'toStatic', 'routPath'=>'docs/guide.txt')) );
checkIdentical('Checker public path: nor a route declared under it', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( Array('openList'=>Array('/docs')) + $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'toStatic', 'routPath'=>'docs/*')) );
checkIdentical('Checker public path: a folder route needs its *', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'htmlPage', 'routPath'=>'Login')) );
checkIdentical('Checker public path: the case counts', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'htmlPage', 'routPath'=>'other')) );
checkIdentical('Checker public path: another page stays closed', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>'login', 'doAction'=>null)) );
checkIdentical('Checker public path: is not an application name', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( $pthConf, mockRequestObj(), new Registry(Array('appToRun'=>'auth', 'doAction'=>'other')) );
checkIdentical('Checker public path: is not an action either', $checker->validateAccess()->readValueByKey('statCode'), 401);
$checker = new Checker( Array('openList'=>Array('login')) + $pubConf, mockRequestObj(), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'htmlPage', 'routPath'=>'login')) );
checkIdentical('Checker public path: needs its leading slash', $checker->validateAccess()->readValueByKey('statCode'), 401);

// Checker : a request denied an HTML page is given the sign-in route to go to
$sgnConf = Array('signRout'=>'/login') + $pthConf;
$askReqs = mockRequestObj();
$askPrpt = new ReflectionProperty('nyu\Tools\Request', 'uriDetails');
$askPrpt->setValue($askReqs, Array('fullPath'=>'/hello/7?tab=a b'));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'htmlPage', 'routPath'=>'hello/$id')) );
$axsRslt = $checker->validateAccess();
checkIdentical('Checker sign-in: a denied page is still a 401', $axsRslt->readValueByKey('statCode'), 401);
checkIdentical('Checker sign-in: with the route to go to, and the page asked for', $axsRslt->readValueByKey('denyGoal'), '/login?back=%2Fhello%2F7%3Ftab%3Da%20b');
$askPrpt->setValue($askReqs, Array('fullPath'=>'/'));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'htmlPage', 'routPath'=>'index')) );
checkIdentical('Checker sign-in: the home page is not carried back', $checker->validateAccess()->readValueByKey('denyGoal'), '/login');
$askPrpt->setValue($askReqs, Array('fullPath'=>'/shows/viewShow/1'));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('appToRun'=>'shows', 'doAction'=>'viewShow', 'routKind'=>'toAction', 'routPath'=>'')) );
checkIdentical('Checker sign-in: a denied action keeps its 401', Array($checker->validateAccess()->readValueByKey('statCode'), $checker->validateAccess()->readValueByKey('denyGoal')), Array(401, null));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'toStatic', 'routPath'=>'style')) );
checkIdentical('Checker sign-in: so does a denied file', Array($checker->validateAccess()->readValueByKey('statCode'), $checker->validateAccess()->readValueByKey('denyGoal')), Array(401, null));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('appToRun'=>null, 'doAction'=>null)) );
checkIdentical('Checker sign-in: and an unresolved route', $checker->validateAccess()->readValueByKey('denyGoal'), null);
$checker = new Checker( Array('signRout'=>'/enter') + $pubConf, $askReqs, new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'htmlPage', 'routPath'=>'enter')) );
checkIdentical('Checker sign-in: a sign-in route left closed is not sent to itself', Array($checker->validateAccess()->readValueByKey('statCode'), $checker->validateAccess()->readValueByKey('denyGoal')), Array(401, null));
$checker = new Checker( $sgnConf, $askReqs, new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'htmlPage', 'routPath'=>'login')) );
checkIdentical('Checker sign-in: the open sign-in route is served', $checker->validateAccess()->readValueByKey('statCode'), 200);
foreach( Array('https://example.org/login', '//example.org/login', 'login', '/log in', Array('/login')) as $badRte ){
	$checker = new Checker( Array('signRout'=>$badRte) + $pthConf, $askReqs, new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'htmlPage', 'routPath'=>'other')) );
	$axsRslt = $checker->validateAccess();
	checkIdentical('Checker sign-in: '.json_encode($badRte).' is not a path of this site', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('denyGoal')), Array(500, null));
}

$checker = new Checker( Array('sessWith'=>'Nope'), mockRequestObj() );
checkIdentical('Checker unknown sess strategy', $checker->validateAccess()->readValueByKey('statCode'), 500);
$checker = new Checker( Array('sessWith'=>'apiHeadr', 'authWith'=>'Nope'), mockRequestObj() );
checkIdentical('Checker unknown auth strategy', $checker->validateAccess()->readValueByKey('statCode'), 500);

// The Probe fixture application, for the helpers of an action
require_once TST_DIR.DS.'fixtures'.DS.'runs'.DS.'Probe'.DS.'Actions.php';

// Checker : the roles are ordered, each one includes those before it
checkIdentical('roleMeets: a role includes those before it', Array(Checker::rleAccessMeets('sysadmin', 'internal'), Checker::rleAccessMeets('operator', 'operator'), Checker::rleAccessMeets('internal', 'operator'), Checker::rleAccessMeets('operator', 'sysadmin')), Array(true, true, false, false));
checkIdentical('roleMeets: no role, or an unknown one, meets nothing', Array(Checker::rleAccessMeets(null, 'internal'), Checker::rleAccessMeets('root', 'internal'), Checker::rleAccessMeets('sysadmin', 'root')), Array(false, false, false));

/**
 * Runs the access check of a route for the user carol, logged with a
 * token carrying the given role.
 *
 * @param Array  $accessConf The 'security' block.
 * @param String $loggedRole Role of the token, empty for a request without a token.
 * @param Array  $routeArray The resolved route.
 * @return Array The status and the message of the check.
 */
function roleReply( Array $accessConf, String $loggedRole, Array $routeArray ): Array {
	$tknHead = ($loggedRole!='') ? Array('Authorization'=>'Bearer '.$GLOBALS['jwtSess']->issueTokenText(Array('userName'=>'carol', 'userRole'=>$loggedRole))) : Array();
	$axsRslt = (new Checker( $accessConf, mockRequestObj($tknHead, Array('REMOTE_ADDR'=>'10.0.0.1')), new Registry($routeArray), $GLOBALS['roleRout'] ))->validateAccess();
	return Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('statMssg'));
}

// The declared routes the paths of rteRoles name : the site's, and a route of the Run Probe under its mount
$GLOBALS['roleRout'] = new Router( Array(), mockRequestObj() );
$GLOBALS['roleRout']->assignRouteSet( Array(
	'docs/*'=>Array('routOwnr'=>'site', 'pointsTo'=>Array('fldrPath'=>'docs')),
	'tokchk'=>Array('routOwnr'=>'site', 'pointsTo'=>Array('appToRun'=>'auth', 'doAction'=>'check')),
	'admin'=>Array('routOwnr'=>'site', 'pointsTo'=>Array('pageFile'=>'admin.html')),
	'space/listed'=>Array('routOwnr'=>'Mounted', 'routDecl'=>'listed', 'pointsTo'=>Array('appToRun'=>'Mounted', 'doAction'=>'listed')),
	'panel'=>Array('routOwnr'=>'site', 'pointsTo'=>Array('appToRun'=>'reports', 'doAction'=>'admin')),
	'probes'=>Array('routOwnr'=>'site', 'pointsTo'=>Array('appToRun'=>'Probe')),
	'console'=>Array('routOwnr'=>'site', 'pointsTo'=>Array('appToRun'=>'Probe', 'doAction'=>'sanitize')),
	'probe/board'=>Array('routOwnr'=>'Probe', 'routDecl'=>'board', 'pointsTo'=>Array('appToRun'=>'Probe', 'doAction'=>'sanitize')),
	'twofold'=>Array('routOwnr'=>'site', 'pointsTo'=>Array('GET'=>Array('appToRun'=>'Probe', 'doAction'=>'mirrored'), 'POST'=>Array('appToRun'=>'Probe', 'doAction'=>'identify'))),
) );

// Checker : the role a route asks for ('rteRoles'), written as the routes of 'openList'
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TRYLIMIT'=>Array('maxTries'=>0),'TESTKEYS'=>Array('keysList'=>Array('k-1')), 'TESTJWT'=>Array('hmacSecr'=>str_repeat('s', 40)))) );
$rolConf = Array('rteRoles'=>Array('users/*'=>'sysadmin', 'users/viewUser'=>'internal', 'Shows/Edit'=>'operator', 'shows/*'=>'internal', 'probe/sanitize'=>'operator', 'probe/mirrored'=>'sysadmin', '/docs/*'=>'operator', '/tokchk'=>'sysadmin', 'auth/check'=>'operator', '/admin'=>'sysadmin')) + $jwtConf;
checkIdentical('Checker role: refused to a lower role', roleReply($rolConf, 'internal', Array('appToRun'=>'users', 'doAction'=>'lockUser')), Array(404, 'Not Found'));
checkIdentical('Checker role: refused to the role between', roleReply($rolConf, 'operator', Array('appToRun'=>'users', 'doAction'=>'lockUser')), Array(404, 'Not Found'));
checkIdentical('Checker role: served to the role asked for', roleReply($rolConf, 'sysadmin', Array('appToRun'=>'users', 'doAction'=>'lockUser')), Array(200, ''));
checkIdentical('Checker role: served to a higher role', Array(roleReply($rolConf, 'operator', Array('appToRun'=>'shows', 'doAction'=>'edit')), roleReply($rolConf, 'sysadmin', Array('appToRun'=>'shows', 'doAction'=>'edit'))), Array(Array(200, ''), Array(200, '')));
checkIdentical('Checker role: the case of the request does not count', roleReply($rolConf, 'internal', Array('appToRun'=>'Users', 'doAction'=>'LOCKUSER')), Array(404, 'Not Found'));
checkIdentical('Checker role: nor a space in the name of the application', roleReply($rolConf, 'internal', Array('appToRun'=>'us ers', 'doAction'=>'lock')), Array(404, 'Not Found'));
checkIdentical('Checker role: the highest of the entries naming the route is asked for', Array(roleReply($rolConf, 'internal', Array('appToRun'=>'shows', 'doAction'=>'edit')), roleReply($rolConf, 'internal', Array('appToRun'=>'shows', 'doAction'=>'overview'))), Array(Array(404, 'Not Found'), Array(200, '')));
checkIdentical('Checker role: an entry cannot lower the one of its application', roleReply($rolConf, 'internal', Array('appToRun'=>'users', 'doAction'=>'viewUser')), Array(404, 'Not Found'));
checkIdentical('Checker role: no action named, the highest of the application', Array(roleReply($rolConf, 'operator', Array('appToRun'=>'probe', 'doAction'=>null)), roleReply($rolConf, 'sysadmin', Array('appToRun'=>'probe', 'doAction'=>null))), Array(Array(404, 'Not Found'), Array(200, '')));
checkIdentical('Checker role: a route that asks for none', roleReply($rolConf, 'internal', Array('appToRun'=>'auth', 'doAction'=>'other')), Array(200, ''));
checkIdentical('Checker role: nobody logged is a 401 first', roleReply($rolConf, '', Array('appToRun'=>'users', 'doAction'=>'lockUser'))[0], 401);
$dirRoute = Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'toStatic', 'routPath'=>'docs/*');
checkIdentical('Checker role: a declared route, by its path', Array(roleReply($rolConf, 'internal', $dirRoute), roleReply($rolConf, 'operator', $dirRoute)), Array(Array(404, 'Not Found'), Array(200, '')));
checkIdentical('Checker role: its * does not name another folder route', roleReply($rolConf, 'internal', Array('routPath'=>'docs/private/*') + $dirRoute), Array(200, ''));
$actRoute = Array('appToRun'=>'auth', 'doAction'=>'check', 'routKind'=>'toAction', 'routPath'=>'tokchk');
checkIdentical('Checker role: named by its path and by its action, the highest', Array(roleReply($rolConf, 'operator', $actRoute), roleReply($rolConf, 'sysadmin', $actRoute)), Array(Array(404, 'Not Found'), Array(200, '')));
checkIdentical('Checker role: the refusal of a route that answers by itself', roleReply($rolConf, 'internal', Array('statCode'=>405) + $dirRoute), Array(404, 'Not Found'));
checkIdentical('Checker role: its own OPTIONS answer needs no role', roleReply($rolConf, '', Array('statCode'=>204) + $dirRoute), Array(200, ''));
// A Run route is named by its final, mounted path, in rteRoles as in openList
$mntConf = Array('rteRoles'=>Array('/space/listed'=>'sysadmin'), 'openList'=>Array('/fromRoot')) + $jwtConf;
$mntRout = Array('appToRun'=>'Mounted', 'doAction'=>'listed', 'routKind'=>'toAction', 'routPath'=>'space/listed', 'routOwnr'=>'Mounted');
checkIdentical('Checker role: a Run route, by its mounted path', Array(roleReply($mntConf, 'internal', $mntRout), roleReply($mntConf, 'sysadmin', $mntRout)), Array(Array(404, 'Not Found'), Array(200, '')));
checkIdentical('Checker role: not by the path its file declares it with, which names no route: the block cannot be applied', roleReply(Array('rteRoles'=>Array('/listed'=>'sysadmin')) + $jwtConf, 'internal', $mntRout), Array(500, 'Access misconfigured'));
$rotRout = Array('appToRun'=>'Mounted', 'doAction'=>'fromRoot', 'routKind'=>'toAction', 'routPath'=>'fromRoot', 'routOwnr'=>'Mounted');
checkIdentical('Checker open: a Run route from the root, by its path', roleReply($mntConf, '', $rotRout), Array(200, ''));
checkIdentical('Checker open: not under the mount it does not take', roleReply($mntConf, '', Array('routPath'=>'mounted/fromRoot') + $rotRout)[0], 401);
// A declared route answering with an action : its role follows the action, whatever path reaches it
$pnlConf = Array('rteRoles'=>Array('/panel'=>'sysadmin')) + $jwtConf;
checkIdentical('Checker role: the role of an alias, on its path', roleReply($pnlConf, 'internal', Array('appToRun'=>'reports', 'doAction'=>'admin', 'routKind'=>'toAction', 'routPath'=>'panel')), Array(404, 'Not Found'));
checkIdentical('Checker role: and on its action reached by its own path', Array(roleReply($pnlConf, 'internal', Array('appToRun'=>'reports', 'doAction'=>'admin')), roleReply($pnlConf, 'internal', Array('appToRun'=>'Reports', 'doAction'=>'ADMIN'))), Array(Array(404, 'Not Found'), Array(404, 'Not Found')));
$dfltAct = nyu\App\Controller::dfltActionText( new ReflectionClass('nyu\Runs\Probe\Actions') );
$prbConf = Array('rteRoles'=>Array('/probes'=>'sysadmin')) + $jwtConf;
checkIdentical('Checker role: a route to the default action of a Run, the Run asked for by its path, with or without its default action', Array($dfltAct, roleReply($prbConf, 'internal', Array('appToRun'=>'probe', 'doAction'=>null)), roleReply($prbConf, 'internal', Array('appToRun'=>'probe', 'doAction'=>$dfltAct)), roleReply($prbConf, 'sysadmin', Array('appToRun'=>'probe', 'doAction'=>$dfltAct))), Array('sanitize', Array(404, 'Not Found'), Array(404, 'Not Found'), Array(200, '')));
checkIdentical('Checker role: another action of that Run asks for nothing', roleReply($prbConf, 'internal', Array('appToRun'=>'probe', 'doAction'=>'mirrored')), Array(200, ''));
$cnsConf = Array('rteRoles'=>Array('/console'=>'sysadmin')) + $jwtConf;
$brdRout = Array('appToRun'=>'Probe', 'doAction'=>'sanitize', 'routKind'=>'toAction', 'routPath'=>'probe/board', 'routOwnr'=>'Probe');
checkIdentical('Checker role: a site alias carries its role to the route of the Run answering with the same action, and to the Run asked for with none', Array(roleReply($cnsConf, 'internal', $brdRout), roleReply($cnsConf, 'sysadmin', $brdRout), roleReply($cnsConf, 'internal', Array('appToRun'=>'probe', 'doAction'=>null))), Array(Array(404, 'Not Found'), Array(200, ''), Array(404, 'Not Found')));
checkIdentical('Checker role: an alias with no role leaves its action as it was', roleReply(Array('rteRoles'=>Array('/docs/*'=>'operator')) + $jwtConf, 'internal', $brdRout), Array(200, ''));
$twoConf = Array('rteRoles'=>Array('/twofold'=>'sysadmin')) + $jwtConf;
checkIdentical('Checker role: a path pointing each method to its own action carries its role to every one of them', Array(roleReply($twoConf, 'internal', Array('appToRun'=>'probe', 'doAction'=>'mirrored')), roleReply($twoConf, 'internal', Array('appToRun'=>'probe', 'doAction'=>'identify')), roleReply($twoConf, 'sysadmin', Array('appToRun'=>'probe', 'doAction'=>'identify')), roleReply($twoConf, 'internal', Array('appToRun'=>'probe', 'doAction'=>'userPass'))), Array(Array(404, 'Not Found'), Array(404, 'Not Found'), Array(200, ''), Array(200, '')));
checkIdentical('Checker role: an unknown application matches no route: its own 404 comes later', roleReply($prbConf, 'internal', Array('appToRun'=>'nowhere', 'doAction'=>null)), Array(200, ''));
// A path of rteRoles must name a declared route, as the router matches it; without a router, nothing can be checked
checkIdentical('Checker role: a path naming no declared route (its case differs) refuses every request', roleReply(Array('rteRoles'=>Array('/Panel'=>'sysadmin')) + $jwtConf, 'sysadmin', $anyRoute = Array('appToRun'=>'shows', 'doAction'=>'overview')), Array(500, 'Access misconfigured'));
checkIdentical('Checker role: a path without a router to read the routes from refuses every request', Array((new Checker( $pnlConf, mockRequestObj() ))->checkRoleFlaws(), (new Checker( Array('rteRoles'=>Array('/Panel'=>'sysadmin')) + $jwtConf, mockRequestObj(), null, $GLOBALS['roleRout'] ))->checkRoleFlaws()), Array('the route "/panel" of rteRoles cannot be checked: no router is given', 'the route "/Panel" of rteRoles is declared nowhere'));
checkIdentical('Checker role: an entry naming an action needs no router', (new Checker( $rolConf + Array(), mockRequestObj() ))->checkRoleFlaws()!=='' && (new Checker( Array('rteRoles'=>Array('users/*'=>'sysadmin')) + $jwtConf, mockRequestObj() ))->checkRoleFlaws()==='', true);

// The refused user stays identified, and is not sent to the sign-in route
$axsRslt = (new Checker( Array('signRout'=>'/login', 'openList'=>Array('/login')) + $rolConf, mockRequestObj(Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'carol', 'userRole'=>'operator')))), new Registry(Array('appToRun'=>null, 'doAction'=>null, 'routKind'=>'htmlPage', 'routPath'=>'admin')), $GLOBALS['roleRout'] ))->validateAccess();
checkIdentical('Checker role: a refused user is known, with no challenge and no sign-in route', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('loggedIn'), $axsRslt->readValueByKey('userName'), $axsRslt->readValueByKey('userRole'), $axsRslt->readValueByKey('wwwHeadr'), $axsRslt->readValueByKey('denyGoal')), Array(404, true, 'carol', 'operator', null, null));

// A check that gives no role makes a member
$keyConf = Array('rteRoles'=>Array('shows/*'=>'internal', 'users/*'=>'operator')) + $hdrConf;
$checker = new Checker( $keyConf, mockRequestObj(Array('X-Api-Key'=>'k-1')), new Registry(Array('appToRun'=>'shows', 'doAction'=>'overview')) );
checkIdentical('Checker role: an API key meets the member role', $checker->validateAccess()->readValueByKey('statCode'), 200);
$checker = new Checker( $keyConf, mockRequestObj(Array('X-Api-Key'=>'k-1')), new Registry(Array('appToRun'=>'users', 'doAction'=>'fullList')) );
checkIdentical('Checker role: and no higher one', Array($checker->validateAccess()->readValueByKey('statCode'), $checker->validateAccess()->readValueByKey('userRole')), Array(404, 'internal'));

// A block that cannot be applied refuses every request
$anyRoute = Array('appToRun'=>'shows', 'doAction'=>'overview');
checkIdentical('Checker role: an unknown role', roleReply(Array('rteRoles'=>Array('users/*'=>'admin')) + $jwtConf, 'sysadmin', $anyRoute), Array(500, 'Access misconfigured'));
checkIdentical('Checker role: a role that is not a text', roleReply(Array('rteRoles'=>Array('users/*'=>Array('sysadmin'))) + $jwtConf, 'sysadmin', $anyRoute), Array(500, 'Access misconfigured'));
checkIdentical('Checker role: not a list of routes', roleReply(Array('rteRoles'=>'sysadmin') + $jwtConf, 'sysadmin', $anyRoute), Array(500, 'Access misconfigured'));
checkIdentical('Checker role: asked for while access is not required', roleReply(Array('required'=>false, 'rteRoles'=>Array('users/*'=>'sysadmin')), 'sysadmin', $anyRoute), Array(500, 'Access misconfigured'));
checkIdentical('Checker role: an empty list asks for nothing', roleReply(Array('required'=>false, 'rteRoles'=>Array()), '', $anyRoute), Array(200, ''));
$opnConf = Array('openList'=>Array('users/*', '/docs/*')) + $rolConf;
checkIdentical('Checker role: a route both open and asking for a role', Array(roleReply($opnConf, 'sysadmin', Array('appToRun'=>'users', 'doAction'=>'lockUser')), roleReply($opnConf, '', $dirRoute)), Array(Array(500, 'Access misconfigured'), Array(500, 'Access misconfigured')));
checkIdentical('Checker role: the other open routes are served', roleReply(Array('openList'=>Array('auth/openSess')) + $rolConf, '', Array('appToRun'=>'auth', 'doAction'=>'openSess')), Array(200, ''));

// The access log : one warning for a refusal, with the role needed
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('rolelogs'), 'mode'=>'BASE', 'pref'=>'Y') );
roleReply($rolConf, 'internal', Array('appToRun'=>'users', 'doAction'=>'lockUser'));
roleReply($rolConf, 'internal', $dirRoute);
roleReply(Array('rteRoles'=>Array('users/*'=>'admin')) + $jwtConf, 'sysadmin', $anyRoute);
nyu\Tools\Logger::plugLogsConfig( Array() );
$axsLogs = file_exists(inTemporaryDir('rolelogs').DS.'access.log') ? file(inTemporaryDir('rolelogs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkIdentical('access log: a refusal for the role is one line', count($axsLogs), 3);
checkIdentical('access log: the role needed by an action', $axsLogs[0] ?? null, '['.date('Y').'] [WARNING] Access refused for user "carol" (internal) from 10.0.0.1 to users/lockUser: role sysadmin needed');
checkIdentical('access log: the role needed by a declared route', $axsLogs[1] ?? null, '['.date('Y').'] [WARNING] Access refused for user "carol" (internal) from 10.0.0.1 to /docs/*: role operator needed');
checkIdentical('access log: a role that cannot be applied', $axsLogs[2] ?? null, '['.date('Y').'] [WARNING] Access denied from 10.0.0.1: Access misconfigured: unknown role for the route "users\/*"');

// Actionset : the helpers an action tests the role with (test/fixtures/runs/Probe, action 'roles')
/**
 * Runs the 'roles' action of the Probe application.
 *
 * @param String $loggedRole Role of the user carol, empty for nobody logged.
 * @param String $neededRole Role the action asks for, empty for none.
 * @return Array The status, the message and the output of the action.
 */
function roleActn( String $loggedRole, String $neededRole='' ): Array {
	$axssReg = new Registry( ($loggedRole!='') ? Array('loggedIn'=>true, 'userName'=>'carol', 'userRole'=>$loggedRole) : Array('loggedIn'=>false, 'userName'=>'', 'userRole'=>null) );
	$actnSet = new nyu\Runs\Probe\Actions( new nyu\App\RunContext('roleList', ($neededRole!='') ? $neededRole : null, new Registry(Array()), $axssReg, mockRequestObj(Array(), Array('REMOTE_ADDR'=>'10.0.0.1')), null, null, null, 'Probe', RUN_DIR.DS.'Probe') );
	$actnSet->runAskedAction();
	return Array($actnSet->statusCode, strval($actnSet->statusMssg), $actnSet->replyValue);
}
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('actnlogs'), 'mode'=>'BASE', 'pref'=>'Y') );
checkIdentical('holdsRole: the highest role holds them all', roleActn('sysadmin')[2], Array('internal'=>true, 'operator'=>true, 'sysadmin'=>true));
checkIdentical('holdsRole: a role and those before it', roleActn('operator')[2], Array('internal'=>true, 'operator'=>true, 'sysadmin'=>false));
checkIdentical('holdsRole: nobody logged holds none', roleActn('')[2], Array('internal'=>false, 'operator'=>false, 'sysadmin'=>false));
checkIdentical('needsRole: the action goes on with the role', roleActn('operator', 'operator')[0], 200);
checkIdentical('needsRole: ends it with a 404 without', roleActn('internal', 'operator'), Array(404, 'Not Found', null));
checkIdentical('needsRole: and when nobody is logged', roleActn('', 'internal'), Array(404, 'Not Found', null));
checkForThrows('needsRole: an unknown role throws', function(){ roleActn('sysadmin', 'root'); }, 'RuntimeException');
nyu\Tools\Logger::plugLogsConfig( Array() );
$axsLogs = file_exists(inTemporaryDir('actnlogs').DS.'access.log') ? file(inTemporaryDir('actnlogs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkIdentical('needsRole: its refusals are logged as those of a route', $axsLogs, Array(
	'['.date('Y').'] [WARNING] Access refused for user "carol" (internal) from 10.0.0.1 to probe/roleList: role operator needed',
	'['.date('Y').'] [WARNING] Access refused for user "" (no role) from 10.0.0.1 to probe/roleList: role internal needed'
));

// The issuer of a certificate is read from the variable the config names
$crtVals = Array('SSL_CLIENT_VERIFY'=>'SUCCESS', 'SSL_CLIENT_S_DN_CN'=>'carol', 'SSL_CLIENT_I_DN_CN'=>'Test CA');
$varSess = new ClCert( Array('issueVar'=>'TLS_ISSUER', 'issuerId'=>'Test CA') );
checkIdentical('ClCert issuer read from another variable', $varSess->verifyRqstList(mockRequestObj(Array(), Array('TLS_ISSUER'=>'Test CA') + $crtVals)), Array('userName'=>'carol'));
checkIdentical('ClCert the default variable is then not read', Array($varSess->verifyRqstList(mockRequestObj(Array(), Array('TLS_ISSUER'=>'Other CA') + $crtVals)), $varSess->finalErrorText(), $varSess->verifyRqstList(mockRequestObj(Array(), $crtVals))), Array(false, 'Certificate issuer not accepted', false));
$hdrSess = new ClCert( Array('issueVar'=>'HTTP_X_SSL_ISSUER', 'issuerId'=>'Test CA') );
checkIdentical('ClCert an issuer variable that is a request header is refused', Array($hdrSess->verifyRqstList(mockRequestObj(Array(), Array('HTTP_X_SSL_ISSUER'=>'Test CA') + $crtVals)), $hdrSess->finalErrorText()), Array(false, 'Certificate variables misconfigured'));

// API keys written under a name identify their holder
$namdKey = new RegKey( Array('keysList'=>Array('orders'=>'k-ordr', 'reports'=>'k-rept', 'nobody'=>'', 'wrongs'=>Array('k-list'))) );
checkIdentical('RegKey a key written under a name gives the name', Array($namdKey->checkSecretArr(Array('key'=>'k-rept')), $namdKey->checkSecretArr(Array('key'=>'k-ordr'))), Array(Array('userName'=>'reports'), Array('userName'=>'orders')));
checkIdentical('RegKey a wrong key, with named keys', Array($namdKey->checkSecretArr(Array('key'=>'k-none')), $namdKey->finalErrorText(), $namdKey->hasWrongSecret()), Array(false, 'Invalid API key', true));
checkIdentical('RegKey a name is not a key, nor is a key that is not a text', Array($namdKey->checkSecretArr(Array('key'=>'reports')), $namdKey->checkSecretArr(Array('key'=>'k-list'))), Array(false, false));
checkIdentical('RegKey a plain list still names nobody', (new RegKey(Array('keysList'=>Array('k-1', 'k-2'))))->checkSecretArr(Array('key'=>'k-2')), Array('userName'=>''));
checkIdentical('RegKey a keysList that is not a list accepts nothing', (new RegKey(Array('keysList'=>'k-1')))->checkSecretArr(Array('key'=>'k-1')), false);
nyu\Tools\Logger::plugLogsConfig( Array('path'=>inTemporaryDir('keylogs'), 'mode'=>'BASE', 'pref'=>'Y') );
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TRYLIMIT'=>Array('maxTries'=>0),'NAMEKEYS'=>Array('keysList'=>Array('reports'=>'k-rept', "eve\" from 9.9.9.9\n[".date('Y')."] [INFO] forged"=>'k-evil')))) );
$keyConf = Array('required'=>true, 'sessWith'=>'apiHeadr', 'authWith'=>'regdKeys', 'sessConf'=>'namekeys');
$axsRslt = (new Checker( $keyConf, mockRequestObj(Array('X-Api-Key'=>'k-rept'), Array('REMOTE_ADDR'=>'10.0.0.1')) ))->validateAccess();
checkIdentical('Checker named key: the holder is the user, a member', Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('userName'), $axsRslt->readValueByKey('userRole')), Array(200, 'reports', 'internal'));
(new Checker( $keyConf, mockRequestObj(Array('X-Api-Key'=>'k-evil'), Array('REMOTE_ADDR'=>'10.0.0.1')) ))->validateAccess();
nyu\Tools\Logger::plugLogsConfig( Array() );
checkIdentical('access log: a member name is written as a JSON text, and cannot forge an entry', file_exists(inTemporaryDir('keylogs').DS.'access.log') ? file(inTemporaryDir('keylogs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array(), Array(
	'['.date('Y').'] [INFO] Access granted for user "reports" from 10.0.0.1',
	'['.date('Y').'] [INFO] Access granted for user "eve\" from 9.9.9.9\n['.date('Y').'] [INFO] forged" from 10.0.0.1'
));

finishChecking();
