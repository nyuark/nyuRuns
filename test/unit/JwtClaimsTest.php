<?php

// Access\Sess\JWT : the issuer and the audiences of the tokens ('iss',
// 'aud') issued and checked, the reasons in the log, the previous key still
// checking the tokens it signed, the public keys published as a JWK Set
// (auth/keyset), and the issuer and audience of the calls of applications.

require __DIR__.'/../lib/harness.php';
require_once TOP_DIR.DS.'runs'.DS.'Auth'.DS.'Actions.php';

use nyu\Access\AppKey;
use nyu\Access\Sess\JWT;
use nyu\Output\Response;
use nyu\Tools\Logger;
use nyu\Tools\Registry;
use Firebase\JWT\JWT as JwtCodec;

if( !function_exists('openssl_pkey_new') ){
	recordSkipping('JWT claims', 'the openssl extension is not loaded');
	finishChecking();
}

/**
 * Makes an RSA key pair of 2048 bits and writes it in the temporary folder of the script.
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
 * Checks a token through a carrier, as a request bearing it.
 *
 * @param JWT    $jwtCarrier The carrier.
 * @param String $tokenValue The token.
 * @return Mixed The user of the token, or false with the message and whether it counts as forged.
 */
function readsTokn( JWT $jwtCarrier, String $tokenValue ): Mixed {
	$idntArr = $jwtCarrier->verifyRqstList( mockRequestObj(Array('Authorization'=>'Bearer '.$tokenValue)) );
	return is_array($idntArr) ? ($idntArr['user'] ?? null) : Array(false, $jwtCarrier->finalErrorText(), $jwtCarrier->wrngCredential());
}

/**
 * Reads a part of a token: its header (0) or its claims (1).
 *
 * @param String $tokenValue The token.
 * @param Int    $partNumber  The part.
 * @return Array Its values.
 */
function partOfTkn( String $tokenValue, Int $partNumber ): Array {
	return (array)json_decode( strval(base64_decode(strtr(explode('.', $tokenValue)[$partNumber] ?? '', '-_', '+/'))), true );
}

list($prvPath, $pubPath) = makePairs('main');
list($newPriv, $newPubl) = makePairs('next');
list($othrPrv, $othrPub) = makePairs('other');
$hsSecrt = str_repeat('s', 40);
$bndConf = Array('algrtm'=>'RS256', 'prvkey'=>$prvPath, 'pubkey'=>$pubPath, 'issuer'=>'https://auth.example.org', 'audnce'=>Array('api.example.org'));
$bndJwt = new JWT( $bndConf );

// Issued : the issuer and the audiences of the block, never those of the identity
$rawTokn = $bndJwt->issueTokenText( Array('user'=>'anna', 'role'=>'member', 'iss'=>'forged', 'aud'=>'elsewhere') );
$clmVals = partOfTkn($rawTokn, 1);
checkIdentical('issued: one audience is a text, the issuer the block\'s', Array($clmVals['iss'] ?? null, $clmVals['aud'] ?? null), Array('https://auth.example.org', 'api.example.org'));
$twoJwt = new JWT( Array('audnce'=>Array('app.example.org', 'api.example.org')) + $bndConf );
checkIdentical('issued: several audiences are a list', partOfTkn($twoJwt->issueTokenText(Array('user'=>'anna')), 1)['aud'] ?? null, Array('app.example.org', 'api.example.org'));
checkIdentical('issued: a block naming neither puts neither', array_intersect_key(partOfTkn((new JWT(Array('algrtm'=>'RS256', 'prvkey'=>$prvPath)))->issueTokenText(Array('user'=>'anna', 'iss'=>'x', 'aud'=>'y')), 1), Array('iss'=>1, 'aud'=>1)), Array());
checkIdentical('issued: an audience given as a text', partOfTkn((new JWT(Array('audnce'=>' api.example.org ') + $bndConf))->issueTokenText(Array('user'=>'anna')), 1)['aud'] ?? null, 'api.example.org');

// Checked
Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs'), 'mode'=>'FULL') );
checkIdentical('checked: the token of the block passes', readsTokn($bndJwt, $rawTokn), 'anna');
checkIdentical('checked: one audience of the token among those of the block is enough', readsTokn(new JWT(Array('audnce'=>Array('other.example.org', 'api.example.org')) + $bndConf), $rawTokn), 'anna');
checkIdentical('checked: a token naming several audiences, one of them the block\'s', readsTokn($bndJwt, $twoJwt->issueTokenText(Array('user'=>'anna'))), 'anna');
$prvKeys = Array(strval(file_get_contents($prvPath)), 'RS256');
$tknBase = Array('user'=>'anna', 'exp'=>time()+60);
checkIdentical('refused, not counted: no issuer', readsTokn($bndJwt, JwtCodec::encode($tknBase + Array('aud'=>'api.example.org'), ...$prvKeys)), Array(false, 'Invalid token', false));
checkIdentical('refused, not counted: another issuer', readsTokn($bndJwt, JwtCodec::encode($tknBase + Array('iss'=>'https://other.example.org', 'aud'=>'api.example.org'), ...$prvKeys)), Array(false, 'Invalid token', false));
checkIdentical('refused, not counted: no audience', readsTokn($bndJwt, JwtCodec::encode($tknBase + Array('iss'=>'https://auth.example.org'), ...$prvKeys)), Array(false, 'Invalid token', false));
checkIdentical('refused, not counted: another audience', readsTokn($bndJwt, JwtCodec::encode($tknBase + Array('iss'=>'https://auth.example.org', 'aud'=>Array('x.example.org', 'y.example.org')), ...$prvKeys)), Array(false, 'Invalid token', false));
checkIdentical('refused, not counted: an issuer that is not a text', readsTokn($bndJwt, JwtCodec::encode($tknBase + Array('iss'=>Array('https://auth.example.org'), 'aud'=>'api.example.org'), ...$prvKeys)), Array(false, 'Invalid token', false));
checkIdentical('a token issued before the change is refused', readsTokn($bndJwt, (new JWT(Array('algrtm'=>'RS256', 'prvkey'=>$prvPath)))->issueTokenText(Array('user'=>'anna'))), Array(false, 'Invalid token', false));
checkIdentical('a forged token is still counted first', readsTokn($bndJwt, (new JWT(Array('prvkey'=>$othrPrv) + $bndConf))->issueTokenText(Array('user'=>'eve'))), Array(false, 'Invalid token', true));
checkIdentical('an ended token is still told as ended', readsTokn($bndJwt, JwtCodec::encode(Array('user'=>'anna', 'exp'=>time()-10, 'iss'=>'x'), ...$prvKeys)), Array(false, 'Expired token', false));
checkIdentical('a block naming neither checks neither', readsTokn(new JWT(Array('algrtm'=>'RS256', 'pubkey'=>$pubPath)), JwtCodec::encode($tknBase + Array('iss'=>'x', 'aud'=>'y'), ...$prvKeys)), 'anna');
$hsBound = new JWT( Array('secret'=>$hsSecrt, 'issuer'=>'nyu', 'audnce'=>'api') );
checkIdentical('HS256 issues and checks them too', Array(readsTokn($hsBound, $hsBound->issueTokenText(Array('user'=>'ben'))), readsTokn($hsBound, (new JWT(Array('secret'=>$hsSecrt)))->issueTokenText(Array('user'=>'ben')))), Array('ben', Array(false, 'Invalid token', false)));
Logger::plugLogsConfig( Array() );
$logText = strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log'));
checkIdentical('the reasons are in the log, at the debug level', Array(
	preg_match('/\[DEBUG\] Token refused: no issuer\s*$/m', $logText),
	preg_match('/\[DEBUG\] Token refused: issuer "https:\\\\\/\\\\\/other\.example\.org" is not "https:\\\\\/\\\\\/auth\.example\.org"\s*$/m', $logText),
	preg_match('/\[DEBUG\] Token refused: no audience\s*$/m', $logText),
	preg_match('/\[DEBUG\] Token refused: audience \["x\.example\.org","y\.example\.org"\] is not one of \["api\.example\.org"\]\s*$/m', $logText)
), Array(1, 1, 1, 1));
Logger::plugLogsConfig( Array('path'=>inTemporaryDir('minilogs'), 'mode'=>'MINI') );
readsTokn($bndJwt, JwtCodec::encode($tknBase + Array('aud'=>'api.example.org'), ...$prvKeys));
Logger::plugLogsConfig( Array() );
checkIdentical('nothing of it in the log of production', strval(@file_get_contents(inTemporaryDir('minilogs').DS.'access.log')), '');

// The previous key : the tokens it signed are accepted until they end, found by their kid
$oldTokn = $rawTokn;
// Retired a moment from now : the tokens of the previous key were issued before
$rotConf = Array('prvkey'=>$newPriv, 'pubkey'=>$newPubl, 'oldpub'=>$pubPath, 'retire'=>time() + 5) + $bndConf;
$rotJwt = new JWT( $rotConf );
$newTokn = $rotJwt->issueTokenText( Array('user'=>'carl') );
checkIdentical('replaced keys: the tokens of the previous key pass, those of the new one too', Array(readsTokn($rotJwt, $oldTokn), readsTokn($rotJwt, $newTokn)), Array('anna', 'carl'));
checkIdentical('replaced keys: the new tokens name the new key', partOfTkn($newTokn, 0)['kid'] ?? null, partOfTkn((new JWT(Array('algrtm'=>'RS256', 'prvkey'=>$newPriv)))->issueTokenText(Array('user'=>'x')), 0)['kid'] ?? '');
unset($rotConf['oldpub']);
checkIdentical('replaced keys: without oldpub the previous key\'s tokens are forged', readsTokn(new JWT($rotConf), $oldTokn), Array(false, 'Invalid token', true));
$kidTokn = JwtCodec::encode( $tknBase + Array('iss'=>'https://auth.example.org', 'aud'=>'api.example.org'), strval(file_get_contents($othrPrv)), 'RS256', partOfTkn($oldTokn, 0)['kid'] ?? null );
checkIdentical('replaced keys: another key naming the previous kid is forged', readsTokn($rotJwt, $kidTokn), Array(false, 'Invalid token', true));
checkIdentical('replaced keys: a token without a kid is checked with the current key', readsTokn($rotJwt, JwtCodec::encode($tknBase + Array('iss'=>'https://auth.example.org', 'aud'=>'api.example.org'), strval(file_get_contents($newPriv)), 'RS256')), 'anna');

// The key set
$keysSet = $rotJwt->publicSetArray();
$keyList = $keysSet['keys'] ?? Array();
checkIdentical('key set: the current key, then the previous one, named as the tokens name them', array_column($keyList, 'kid'), Array(partOfTkn($newTokn, 0)['kid'] ?? '', partOfTkn($oldTokn, 0)['kid'] ?? ''));
checkIdentical('key set: the members of a key', array_keys($keyList[0] ?? Array()), Array('kty', 'use', 'alg', 'kid', 'n', 'e'));
checkIdentical('key set: what a key says', Array($keyList[0]['kty'] ?? null, $keyList[0]['use'] ?? null, $keyList[0]['alg'] ?? null, $keyList[0]['e'] ?? null), Array('RSA', 'sig', 'RS256', 'AQAB'));
$jwkKeys = runCatchThrown(function() use ( $keysSet ){ return Firebase\JWT\JWK::parseKeySet($keysSet); });
checkIdentical('key set: another project checks the tokens with it alone', is_array($jwkKeys) ? (array)JwtCodec::decode($newTokn, $jwkKeys) : $jwkKeys, (array)JwtCodec::decode($newTokn, new Firebase\JWT\Key(strval(file_get_contents($newPubl)), 'RS256')));
checkIdentical('key set: the previous key checks the tokens it signed', is_array($jwkKeys) ? (JwtCodec::decode($oldTokn, $jwkKeys)->user ?? null) : $jwkKeys, 'anna');
checkIdentical('key set: no private part', preg_match('/"(d|p|q|dp|dq|qi)"/', strval(json_encode($keysSet))), 0);
checkIdentical('key set: a previous key that is the current one is listed once', count((new JWT(Array('oldpub'=>$pubPath) + $bndConf))->publicSetArray()['keys']), 1);
checkIdentical('key set: nothing with HS256', Array((new JWT(Array('secret'=>$hsSecrt)))->publicSetArray(), $hsBound->publicSetArray()), Array(Array('keys'=>Array()), Array('keys'=>Array())));
checkIdentical('key set: nothing from a block that only checks tokens', (new JWT(Array('algrtm'=>'RS256', 'pubkey'=>$pubPath)))->publicSetArray(), Array('keys'=>Array()));
file_put_contents(inTemporaryDir('broken.pub'), 'not a key');
checkIdentical('key set: a previous key that is not one is left out, and checks nothing', Array(count((new JWT(Array('oldpub'=>inTemporaryDir('broken.pub')) + $rotConf))->publicSetArray()['keys']), readsTokn(new JWT(Array('oldpub'=>inTemporaryDir('broken.pub')) + $rotConf), $newTokn)), Array(1, 'carl'));

// auth/keyset : the set of the block of the login, JSON, kept five minutes; a 404 when there is none
/**
 * Runs auth/keyset with the given block of tokens as the one of the login.
 *
 * @param Array $tokenBlock The block.
 * @return Array The status, the message, the output and the headers.
 */
function askKeyset( Array $tokenBlock ): Array {
	$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array(
		'LOGIN'=>Array('authby'=>'htpass', 'authcf'=>'dfpass', 'sessby'=>'jtoken', 'sesscf'=>'dftokn'),
		'DFTOKN'=>$tokenBlock
	)) );
	return runCatchThrown(function(): Array {
		$respObj = new Response( Array('format'=>'yaml', 'headrs'=>Array('Cache-Control'=>'no-cache, no-store', 'Expires'=>'Mon, 26 Jul 1997 05:00:00 GMT')) );
		$actnSet = new nyu\Runs\Auth\Actions( new nyu\App\RunContext('keyset', null, new Registry(Array()), new Registry(Array('logged'=>false, 'user'=>'', 'role'=>null)), mockRequestObj(), $respObj, null, null, 'Auth', RUN_DIR.DS.'Auth') );
		$actnSet->runAskedAction();
		$respObj->convertOutFlag( $actnSet->replyValue ?? Array() );
		return Array($actnSet->statusCode, strval($actnSet->statusMssg), $actnSet->replyValue, $respObj->readHeadsArray());
	});
}
$setRply = askKeyset( $rotConf + Array('oldpub'=>$pubPath) );
checkIdentical('auth/keyset: the set of the login\'s block', Array($setRply[0] ?? null, array_column($setRply[2]['keys'] ?? Array(), 'kid')), Array(200, array_column($keyList, 'kid')));
checkIdentical('auth/keyset: may be kept five minutes', Array($setRply[3]['Cache-Control'] ?? null, strtotime(strval($setRply[3]['Expires'] ?? ''))>time()), Array('public, max-age=300', true));
checkIdentical('auth/keyset: a 404 with HS256, or for the keys of another server', Array(array_slice(askKeyset(Array('secret'=>$hsSecrt)), 0, 2), array_slice(askKeyset(Array('algrtm'=>'RS256', 'pubkey'=>$pubPath)), 0, 2)), Array(Array(404, 'Not Found'), Array(404, 'Not Found')));

// The calls of applications : once the block names this project's audiences, the token names them and its application
$appTokn = new JWT( Array('algrtm'=>'RS256', 'prvkey'=>$othrPrv, 'issuer'=>'portal', 'audnce'=>'nyu-api') );
$appKeys = Array( 'header'=>'X-App-Key', 'applst'=>Array(
	'portal'=>Array('keyhsh'=>hash('sha256', 'key-portal'), 'routes'=>Array('contacts/*'), 'pubkey'=>$othrPub),
	'mobile'=>Array('keyhsh'=>hash('sha256', 'key-mobile'), 'routes'=>Array('contacts/*'), 'pubkey'=>$othrPub, 'issuer'=>'portal')
) );
/**
 * Tells what an application key check answers to a call.
 *
 * @param Array  $keysConfig  The block of the application keys.
 * @param String $appKeyText The key sent.
 * @param String $tokenValue The token sent.
 * @return Mixed The user, or false with the message and whether it counts.
 */
function callsApp( Array $keysConfig, String $appKeyText, String $tokenValue ): Mixed {
	$keyChck = new AppKey( $keysConfig );
	$idntArr = $keyChck->verifyAppArray( mockRequestObj(Array('X-App-Key'=>$appKeyText, 'Authorization'=>'Bearer '.$tokenValue)) );
	return is_array($idntArr) ? ($idntArr['user'] ?? null) : Array(false, $keyChck->finalErrorText(), $keyChck->wrngCredential());
}
$bareTkn = (new JWT( Array('algrtm'=>'RS256', 'prvkey'=>$othrPrv) ))->issueTokenText( Array('user'=>'ann') );
checkIdentical('app call: without audnce, neither is checked', callsApp($appKeys, 'key-portal', $bareTkn), 'ann');
$appKeys['audnce'] = Array('nyu-api');
checkIdentical('app call: its name as issuer and this project as audience', callsApp($appKeys, 'key-portal', $appTokn->issueTokenText(Array('user'=>'ann'))), 'ann');
checkIdentical('app call: a token without them is refused, not counted', callsApp($appKeys, 'key-portal', $bareTkn), Array(false, 'Invalid token', false));
checkIdentical('app call: the issuer of the entry replaces its name', callsApp($appKeys, 'key-mobile', $appTokn->issueTokenText(Array('user'=>'ann'))), 'ann');
checkIdentical('app call: another application\'s name as issuer', callsApp($appKeys, 'key-portal', (new JWT(Array('algrtm'=>'RS256', 'prvkey'=>$othrPrv, 'issuer'=>'mobile', 'audnce'=>'nyu-api')))->issueTokenText(Array('user'=>'ann'))), Array(false, 'Invalid token', false));
checkIdentical('app call: a token meant for another project', callsApp($appKeys, 'key-portal', (new JWT(Array('algrtm'=>'RS256', 'prvkey'=>$othrPrv, 'issuer'=>'portal', 'audnce'=>'other-api')))->issueTokenText(Array('user'=>'ann'))), Array(false, 'Invalid token', false));

finishChecking();
