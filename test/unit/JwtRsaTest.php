<?php

// Access\Sess\JWT with RS256 : tokens signed with a private key and checked
// with its public key, a block holding only the public key, the keys refused,
// the kid header, and the login of a project that issues no token.

require __DIR__.'/../lib/harness.php';
require_once TOP_DIR.DS.'runs'.DS.'Auth'.DS.'Actions.php';

use nyu\Access\Sess\JWT;
use nyu\Tools\Registry;
use Firebase\JWT\JWT as JwtCodec;

if( !function_exists('openssl_pkey_new') ){
	recordSkipping('JWT RS256', 'the openssl extension is not loaded');
	finishChecking();
}

/**
 * Makes an RSA key pair and writes it in the temporary folder of the script.
 *
 * @param String $namePrefix Name of the files, without their extension.
 * @param Int    $keysLength Size of the key, in bits.
 * @return Array The paths of the private and public keys.
 */
function makePairs( String $namePrefix, Int $keysLength ): Array {
	$cnfOpts = Array('config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf');
	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>$keysLength, 'private_key_type'=>OPENSSL_KEYTYPE_RSA) + $cnfOpts );
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
 * @return mixed The user of the token, or false with the message and whether it counts as forged.
 */
function readsTokn( JWT $jwtCarrier, String $tokenValue ): Mixed {
	$idntArr = $jwtCarrier->verifyRqstList( mockRequestObj(Array('Authorization'=>'Bearer '.$tokenValue)) );
	return is_array($idntArr) ? ($idntArr['userName'] ?? null) : Array(false, $jwtCarrier->finalErrorText(), $jwtCarrier->hasWrongSecret());
}

/**
 * Reads the header of a token.
 *
 * @param String $tokenValue The token.
 * @return Array Its header.
 */
function headOfTkn( String $tokenValue ): Array {
	return (array)json_decode( strval(base64_decode(strtr(explode('.', $tokenValue)[0], '-_', '+/'))), true );
}

list($prvPath, $pubPath) = makePairs('main', 2048);
list($othrPrv, $othrPub) = makePairs('other', 2048);
list($weakPrv, $weakPub) = makePairs('weak', 1024);
$fullJwt = new JWT( Array('signAlgo'=>'RS256', 'privFile'=>$prvPath, 'publFile'=>$pubPath, 'lifeSecs'=>60) );
$rawTokn = $fullJwt->issueTokenText( Array('userName'=>'anna', 'userRole'=>'sysadmin') );

// Signed with the private key, checked with the public one
checkIdentical('RS256 a token is issued and checked', readsTokn($fullJwt, $rawTokn), 'anna');
$headArr = headOfTkn($rawTokn);
checkIdentical('RS256 the token names its algorithm and its key', Array($headArr['alg'] ?? null, strlen(strval($headArr['kid'] ?? ''))), Array('RS256', 43));
$sameKid = headOfTkn( (new JWT(Array('signAlgo'=>'RS256', 'privFile'=>$prvPath)))->issueTokenText(Array('userName'=>'ben')) )['kid'] ?? null;
$othrKid = headOfTkn( (new JWT(Array('signAlgo'=>'RS256', 'privFile'=>$othrPrv)))->issueTokenText(Array('userName'=>'ben')) )['kid'] ?? null;
checkIdentical('RS256 the kid follows the key: the same for the same key, another for another', Array($sameKid===($headArr['kid'] ?? null), $othrKid!==($headArr['kid'] ?? null)), Array(true, true));

// What is refused
$othrJwt = new JWT( Array('signAlgo'=>'RS256', 'privFile'=>$othrPrv, 'publFile'=>$othrPub) );
checkIdentical('RS256 a token signed with another key is forged', readsTokn($fullJwt, strval($othrJwt->issueTokenText(Array('userName'=>'eve', 'userRole'=>'sysadmin')))), Array(false, 'Invalid token', true));
$confTkn = JwtCodec::encode( Array('userName'=>'eve', 'userRole'=>'sysadmin', 'exp'=>time()+60), strval(file_get_contents($pubPath)), 'HS256' );
checkIdentical('RS256 a token signed with HS256 and the public key as its secret is forged', readsTokn($fullJwt, $confTkn), Array(false, 'Invalid token', true));
$hsJwt = new JWT( Array('hmacSecr'=>str_repeat('s', 40)) );
checkIdentical('HS256 a token signed with RS256 is refused by a block on HS256', readsTokn($hsJwt, $rawTokn), Array(false, 'Invalid token', true));
checkIdentical('HS256 a block on HS256 still issues and checks', readsTokn($hsJwt, $hsJwt->issueTokenText(Array('userName'=>'ben'))), 'ben');

// A block holding only the public key checks the tokens another server issues
$chkOnly = new JWT( Array('signAlgo'=>'RS256', 'publFile'=>$pubPath) );
checkIdentical('check only: the tokens of the server issuing them pass', Array($chkOnly->verifyOnlyFlag(), readsTokn($chkOnly, $rawTokn)), Array(true, 'anna'));
checkIdentical('check only: it issues none', Array($chkOnly->issueTokenText(Array('userName'=>'anna')), $chkOnly->finalErrorText()), Array('', 'Tokens are issued by another server'));
$prvOnly = new JWT( Array('signAlgo'=>'RS256', 'privFile'=>$prvPath) );
checkIdentical('a block with the private key alone draws the public key from it', Array($prvOnly->verifyOnlyFlag(), readsTokn($prvOnly, $rawTokn)), Array(false, 'anna'));

// Keys that cannot be used
$noKeys = new JWT( Array('signAlgo'=>'RS256', 'privFile'=>inTemporaryDir('missing.key'), 'publFile'=>inTemporaryDir('missing.pub')) );
checkIdentical('RS256 without keys', Array($noKeys->verifyOnlyFlag(), $noKeys->issueTokenText(Array('userName'=>'anna')), $noKeys->finalErrorText(), readsTokn($noKeys, $rawTokn)), Array(false, '', 'Token keys not configured', Array(false, 'Token keys not configured', false)));
$weakJwt = new JWT( Array('signAlgo'=>'RS256', 'privFile'=>$weakPrv, 'publFile'=>$weakPub) );
checkIdentical('RS256 a key under 2048 bits is not used', Array($weakJwt->issueTokenText(Array('userName'=>'anna')), $weakJwt->finalErrorText(), $weakJwt->verifyOnlyFlag()), Array('', 'Token keys not configured', false));
file_put_contents(inTemporaryDir('broken.pub'), 'not a key');
checkIdentical('RS256 a file that is not a key', readsTokn(new JWT(Array('signAlgo'=>'RS256', 'publFile'=>inTemporaryDir('broken.pub'))), $rawTokn), Array(false, 'Token keys not configured', false));
checkIdentical('an algorithm that is not one of the list', readsTokn(new JWT(Array('signAlgo'=>'none', 'hmacSecr'=>str_repeat('s', 40))), $rawTokn), Array(false, 'JWT secret not configured', false));

// The login of a project that only checks tokens : 501, nothing checked or counted
$lmtFldr = 'temp'.DS.'limits_test_'.getmypid();
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array(
	'LOGINSET'=>Array('authWith'=>'htpasswd', 'authConf'=>'htpasswd', 'sessWith'=>'jwtToken', 'sessConf'=>'chkonl'),
	'HTPASSWD'=>Array('passFile'=>'test'.DS.'fixtures'.DS.'users.htpasswd'),
	'CHKONL'=>Array('signAlgo'=>'RS256', 'publFile'=>$pubPath),
	'TRYLIMIT'=>Array('maxTries'=>1, 'holdSecs'=>600, 'dataPath'=>$lmtFldr)
)) );
$lgnRslt = runCatchThrown(function(){
	$request = mockRequestObj( Array(), Array('REMOTE_ADDR'=>'203.0.113.9') );
	$mthProp = new ReflectionProperty('nyu\Tools\Request', 'rqstInMthd');
	$mthProp->setValue($request, 'POST');
	$actnSet = new nyu\Runs\Auth\Actions( new nyu\App\RunContext('login', null, new Registry(Array('userName'=>'alice', 'passWord'=>'wrong')), new Registry(Array('loggedIn'=>false, 'userName'=>'', 'userRole'=>null)), $request, null, null, null, 'Auth', RUN_DIR.DS.'Auth') );
	$actnSet->runAskedAction();
	return Array($actnSet->statusCode, strval($actnSet->statusMssg));
});
checkIdentical('the login of a project that only checks tokens answers 501, without checking the password', $lgnRslt, Array(501, 'Tokens are issued by another server'));
checkIdentical('nothing is counted against the address', is_dir(TOP_DIR.DS.$lmtFldr) ? count((array)glob(TOP_DIR.DS.$lmtFldr.DS.'*')) : 0, 0);
if( is_dir(TOP_DIR.DS.$lmtFldr) ){
	array_map('unlink', (array)glob(TOP_DIR.DS.$lmtFldr.DS.'*'));
	@rmdir(TOP_DIR.DS.$lmtFldr);
}

finishChecking();
