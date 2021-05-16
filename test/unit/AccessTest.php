<?php

// Access layer : the Auth strategies (PassList, RegKey), the Sess carriers
// (Basic, Header, JWT) and the Checker that pairs them.
// Credentials come from test/fixtures/users.htpasswd (alice / s3cret, bob / hunter2).

require __DIR__.'/../lib/harness.php';

use nyu\Access\Auth\PassList;
use nyu\Access\Auth\RegKey;
use nyu\Access\Sess\Basic;
use nyu\Access\Sess\Header;
use nyu\Access\Sess\JWT;
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
checkSame('PassList legacy crypt hash',  $passLst->checkCred(Array('user'=>'bob', 'pass'=>'hunter2')), Array('user'=>'bob'));
checkSame('PassList wrong password',     $passLst->checkCred(Array('user'=>'alice', 'pass'=>'nope')), false);
checkSame('PassList wrong password message', $passLst->lastError(), 'Invalid credentials');
checkSame('PassList unknown user',       $passLst->checkCred(Array('user'=>'carol', 'pass'=>'x')), false);
checkSame('PassList comment line is not a user', $passLst->checkCred(Array('user'=>'# Test credentials only', 'pass'=>'x')), false);
$noStore = new PassList( Array('htpasswd'=>'test'.DS.'fixtures'.DS.'missing.htpasswd') );
checkSame('PassList missing file',       $noStore->checkCred(Array('user'=>'alice', 'pass'=>'s3cret')), false);
checkSame('PassList missing file message', $noStore->lastError(), 'Credential store unavailable');

// RegKey : flat list of API keys
$keysLst = new RegKey( Array('keys'=>Array('k-111', 'k-222')) );
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

// JWT : self-verifying bearer token
$jwtSess = new JWT( Array('secret'=>str_repeat('s', 40), 'ttl'=>60) );
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

// Checker : reads the strategy pair from the 'auths' block, and its config from the ACCESS registry row
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TESTKEYS'=>Array('keys'=>Array('k-1')))) );
$hdrConf = Array('required'=>true, 'sess'=>'Header', 'auth'=>'RegKey', 'confKey'=>'testkeys');

$checker = new Checker( Array('required'=>false), fakeReqst() );
checkSame('Checker not required',        $checker->verifyAxs()->readValue('logged'), true);
$checker = new Checker( Array(), fakeReqst() );
checkSame('Checker empty config',        $checker->verifyAxs()->readValue('statCode'), 200);

$checker = new Checker( $hdrConf, fakeReqst(Array('X-Api-Key'=>'k-1')) );
$axsRslt = $checker->verifyAxs();
checkSame('Checker granted',             $axsRslt->readValue('logged'), true);
checkSame('Checker granted status',      $axsRslt->readValue('statCode'), 200);

$checker = new Checker( $hdrConf, fakeReqst(Array('X-Api-Key'=>'bad')) );
$axsRslt = $checker->verifyAxs();
checkSame('Checker denied',              $axsRslt->readValue('logged'), false);
checkSame('Checker denied status',       $axsRslt->readValue('statCode'), 401);
checkSame('Checker denied message',      $axsRslt->readValue('statMssg'), 'Invalid API key');
checkSame('Checker denied, no Basic challenge', $axsRslt->readValue('wwwAuth'), null);

$checker = new Checker( Array('sess'=>'Basic', 'auth'=>'RegKey', 'confKey'=>'testkeys'), fakeReqst() );
$axsRslt = $checker->verifyAxs();
checkSame('Checker Basic denied status', $axsRslt->readValue('statCode'), 401);
checkSame('Checker Basic challenge',     $axsRslt->readValue('wwwAuth'), 'Basic');

$checker = new Checker( Array('sess'=>'Nope'), fakeReqst() );
checkSame('Checker unknown sess strategy', $checker->verifyAxs()->readValue('statCode'), 500);
$checker = new Checker( Array('sess'=>'Header', 'auth'=>'Nope'), fakeReqst() );
checkSame('Checker unknown auth strategy', $checker->verifyAxs()->readValue('statCode'), 500);

endChecks();
