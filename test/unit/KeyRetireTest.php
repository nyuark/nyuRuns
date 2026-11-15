<?php

// Access\Carr\JWT : the previous key ('prevFile') checks only the tokens it
// signed before it was retired ('retireAt'), ending within 'lifeSecs' of it;
// past that time it is neither used nor published.

require __DIR__.'/../lib/harness.php';

use nyu\Access\Carr\JWT;
use Firebase\JWT\JWT as JwtCodec;

if( !function_exists('openssl_pkey_new') ){
	recordSkipping('previous key retired', 'the openssl extension is not loaded');
	finishChecking();
}

/**
 * Makes an RSA key pair of 2048 bits in the temporary folder of the script.
 *
 * @param String $namePrefix Name of the files, without their extension.
 * @return Array The paths of the private and public keys.
 */
function makePairs( String $namePrefix ): Array {
	$cnfOpts = Array('config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf');
	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA) + $cnfOpts );
	$prvText = '';
	openssl_pkey_export($keyObjc, $prvText, null, $cnfOpts);
	file_put_contents(inTemporaryDir($namePrefix.'.key'), $prvText);
	file_put_contents(inTemporaryDir($namePrefix.'.pub'), strval(openssl_pkey_get_details($keyObjc)['key']));
	return Array(inTemporaryDir($namePrefix.'.key'), inTemporaryDir($namePrefix.'.pub'));
}

/**
 * Signs a token with the previous key, naming it by its kid.
 *
 * @param Int $issuedUnix Its 'iat'.
 * @param Int $endingUnix Its 'exp'.
 * @return String The token.
 */
function oldToken( Int $issuedUnix, Int $endingUnix ): String {
	global $oldPriv, $oldKid;
	return JwtCodec::encode( Array('userName'=>'anna', 'iat'=>$issuedUnix, 'exp'=>$endingUnix), strval(file_get_contents($oldPriv)), 'RS256', $oldKid );
}

/**
 * Tells what a carrier answers to a token.
 *
 * @param JWT    $jwtCarrier The carrier.
 * @param String $tokenValue The token.
 * @return Mixed The user, or false with the message and whether it counts.
 */
function readsTokn( JWT $jwtCarrier, String $tokenValue ): Mixed {
	$idntArr = $jwtCarrier->checkReqstDict( mockRequestObj(Array('Authorization'=>'Bearer '.$tokenValue)) );
	return is_array($idntArr) ? ($idntArr['userName'] ?? null) : Array(false, $jwtCarrier->getLastErrText(), $jwtCarrier->hasWrongSecret());
}

list($oldPriv, $oldPubl) = makePairs('former');
list($newPriv, $newPubl) = makePairs('current');
$oldKid = strval( json_decode(base64_decode(strtr(explode('.', (new JWT(Array('signAlgo'=>'RS256', 'privFile'=>$oldPriv)))->issueTokenText(Array('userName'=>'x')))[0], '-_', '+/')), true)['kid'] ?? '' );
$nowTime = time();
$retired = $nowTime - 100;
$rotConf = Array('signAlgo'=>'RS256', 'privFile'=>$newPriv, 'publFile'=>$newPubl, 'prevFile'=>$oldPubl, 'retireAt'=>$retired, 'lifeSecs'=>600);
$rotJwt = new JWT( $rotConf );

checkIdentical('previous key: a token issued before the rotation, ending within lifeSecs of it, passes', readsTokn($rotJwt, oldToken($retired - 50, $retired + 500)), 'anna');
checkIdentical('previous key: one issued after the rotation is refused, and counted', readsTokn($rotJwt, oldToken($retired + 10, $retired + 500)), Array(false, 'Invalid token', true));
checkIdentical('previous key: one ending beyond the rotation and lifeSecs is refused, and counted', readsTokn($rotJwt, oldToken($retired - 50, $retired + 601)), Array(false, 'Invalid token', true));
checkIdentical('previous key: one without iat is refused', readsTokn($rotJwt, JwtCodec::encode(Array('userName'=>'anna', 'exp'=>$retired + 500), strval(file_get_contents($oldPriv)), 'RS256', $oldKid)), Array(false, 'Invalid token', true));
checkIdentical('previous key: published while its tokens may last', count($rotJwt->getJwksOfBlock()['keys']), 2);
checkIdentical('the current key is not bound by the rotation', readsTokn($rotJwt, (new JWT($rotConf))->issueTokenText(Array('userName'=>'ben'))), 'ben');

// Past the rotation and lifeSecs, the previous key is neither used nor published
$endConf = Array('retireAt'=>$nowTime - 700) + $rotConf;
$endJwt = new JWT( $endConf );
checkIdentical('past its time: not published', count($endJwt->getJwksOfBlock()['keys']), 1);
checkIdentical('past its time: what it signed is forged', readsTokn($endJwt, oldToken($nowTime - 800, $nowTime + 100)), Array(false, 'Invalid token', true));
checkIdentical('without a retire time, it is not used either', Array(count((new JWT(array_diff_key($rotConf, Array('retireAt'=>1))))->getJwksOfBlock()['keys']), readsTokn(new JWT(array_diff_key($rotConf, Array('retireAt'=>1))), oldToken($retired - 50, $retired + 500))), Array(1, Array(false, 'Invalid token', true)));

finishChecking();
