<?php

// Command line, appkey : the keys of the applications calling for their users,
// made, listed, given other routes and revoked in conf/access.json, and a key
// made here accepted by Access\AppKey.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

use nyu\Access\AppKey;
use nyu\Access\Sess\JWT;

if( !function_exists('openssl_pkey_new') ){
	skipCheck('Shell appkey', 'the openssl extension is not loaded');
	endChecks();
}

/**
 * Makes an RSA key and gives its private and public keys as PEM.
 *
 * @param Int $keysLength Size of the key, in bits.
 * @return Array The private and the public key.
 */
function makesPair( Int $keysLength ): Array {
	$cnfOpts = Array('config'=>TOP_DIR.DS.'src'.DS.'Shell'.DS.'keygen.cnf');
	$keyObjc = openssl_pkey_new( Array('private_key_bits'=>$keysLength, 'private_key_type'=>OPENSSL_KEYTYPE_RSA) + $cnfOpts );
	$prvText = '';
	openssl_pkey_export($keyObjc, $prvText, null, $cnfOpts);
	return Array($prvText, strval(openssl_pkey_get_details($keyObjc)['key']));
}

list($appPriv, $appPubl) = makesPair(2048);
list(, $weakPub) = makesPair(1024);
$projDir = makeProjt('appkey', Array(
	'conf/system.ini'=>sampleIni(),
	'conf/access.json'=>"{\n\t\"limits\": { \"maxtry\": 10, \"holdtm\": 600, \"folder\": \"temp/limits\" }\n}\n",
	'conf/keys/portal.pub'=>$appPubl,
	'conf/keys/weak.pub'=>$weakPub
));
$axsJson = function() use ($projDir): Array {
	return (array)json_decode( strval(readInPrj($projDir, 'conf/access.json')), true );
};

// Making
$jsnVals = jsonShell($projDir, Array('appkey', 'makes', 'portal', '--routs=contacts/*, /members/save', '--pubky=conf/keys/portal.pub'));
$keyText = strval($jsnVals['secret']['Key of portal'] ?? '');
$appEntr = $axsJson()['apkeys']['applst']['portal'] ?? Array();
checkSame('makes shows the key once and stores its hash only', Array($jsnVals['key made'] ?? null, strlen($keyText), $appEntr['keyhsh'] ?? null, strpos(strval(readInPrj($projDir, 'conf/access.json')), $keyText)), Array('portal', 43, hash('sha256', $keyText), false));
checkSame('makes: the block created with its header, the routes, the key file, member by default', Array($axsJson()['apkeys']['header'] ?? null, $appEntr['routes'] ?? null, $appEntr['pubkey'] ?? null, $appEntr['maxrol'] ?? null), Array('X-App-Key', Array('contacts/*', '/members/save'), 'conf/keys/portal.pub', 'member'));
checkLike('makes: the date it was made', strval($appEntr['madedt'] ?? ''), '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/');
checkSame('makes: the other blocks kept', $axsJson()['limits'] ?? null, Array('maxtry'=>10, 'holdtm'=>600, 'folder'=>'temp/limits'));
checkSame('makes: a highest role of its own', jsonShell($projDir, Array('appkey', 'makes', 'mobile', '--routs=contacts/view', '--pubky=conf/keys/portal.pub', '--urole=system'))['key made'] ?? null, 'mobile');
checkSame('makes: a name already listed', runShell($projDir, Array('appkey', 'makes', 'portal', '--routs=contacts/*', '--pubky=conf/keys/portal.pub'))['code'], 3);
checkSame('makes: a bad name, a missing name, no routes, a bad route', Array(
	runShell($projDir, Array('appkey', 'makes', 'Por Tal', '--routs=contacts/*', '--pubky=conf/keys/portal.pub'))['code'],
	runShell($projDir, Array('appkey', 'makes'))['code'],
	runShell($projDir, Array('appkey', 'makes', 'other', '--pubky=conf/keys/portal.pub'))['code'],
	runShell($projDir, Array('appkey', 'makes', 'other', '--routs=contacts', '--pubky=conf/keys/portal.pub'))['code']
), Array(2, 2, 2, 2));
checkSame('makes: an unknown role, no key file, a missing file, a weak key', Array(
	runShell($projDir, Array('appkey', 'makes', 'other', '--routs=contacts/*', '--pubky=conf/keys/portal.pub', '--urole=root'))['code'],
	runShell($projDir, Array('appkey', 'makes', 'other', '--routs=contacts/*'))['code'],
	runShell($projDir, Array('appkey', 'makes', 'other', '--routs=contacts/*', '--pubky=conf/keys/none.pub'))['code'],
	runShell($projDir, Array('appkey', 'makes', 'other', '--routs=contacts/*', '--pubky=conf/keys/weak.pub'))['code']
), Array(2, 2, 2, 2));
checkSame('makes: nothing written by a refusal', array_keys($axsJson()['apkeys']['applst'] ?? Array()), Array('portal', 'mobile'));

// A key made here, checked as a request bearing it
$blkConf = $axsJson()['apkeys'];
$blkConf['applst']['portal']['pubkey'] = putInProj($projDir, 'conf/keys/portal.pub', $appPubl);
$appTokn = (new JWT( Array('algrtm'=>'RS256', 'prvkey'=>putInProj($projDir, 'conf/keys/portal.key', $appPriv)) ))->issueTokn( Array('user'=>'ann', 'role'=>'member') );
$idntArr = (new AppKey($blkConf))->verifyApp( fakeReqst(Array('X-App-Key'=>$keyText, 'Authorization'=>'Bearer '.$appTokn)) );
checkSame('the key made is accepted with a token of the application', Array($idntArr['user'] ?? null, $idntArr['fromApp'] ?? null), Array('ann', 'portal'));

// Listing
$rowList = jsonShell($projDir, Array('appkey', 'lists'))['rows'] ?? Array();
checkSame('lists the applications, their routes and highest role', array_map(function(Array $tableRow){ return $tableRow['application'].' '.$tableRow['routes'].' '.$tableRow['highest role']; }, $rowList), Array('portal contacts/*, /members/save member', 'mobile contacts/view system'));
checkSame('lists never shows a hash', strpos(runShell($projDir, Array('appkey', 'lists'))['text'], hash('sha256', $keyText)), false);

// Routes
checkSame('route replaces the routes of an application', Array(runShell($projDir, Array('appkey', 'route', 'mobile', '--routs=contacts/*,/calendar/save'))['code'], $axsJson()['apkeys']['applst']['mobile']['routes'] ?? null), Array(0, Array('contacts/*', '/calendar/save')));
checkSame('route: an unknown application, a bad route', Array(runShell($projDir, Array('appkey', 'route', 'nobody', '--routs=contacts/*'))['code'], runShell($projDir, Array('appkey', 'route', 'mobile', '--routs=*'))['code']), Array(3, 2));

// Revoking
checkSame('revok removes the key of that application only', Array(runShell($projDir, Array('appkey', 'revok', 'portal'))['code'], array_keys($axsJson()['apkeys']['applst'] ?? Array())), Array(0, Array('mobile')));
checkSame('revok: twice, a missing name', Array(runShell($projDir, Array('appkey', 'revok', 'portal'))['code'], runShell($projDir, Array('appkey', 'revok'))['code']), Array(3, 2));
checkLike('every change traced on the shell channel', strval(readInPrj($projDir, 'temp/logs/shell.log')), '/App key: key of application "portal" revoked/');

// A project without its access config
$bareDir = makeProjt('noaccess', Array('conf/system.ini'=>sampleIni()));
checkLike('a project without conf/access.json is told so', runShell($bareDir, Array('appkey', 'lists'))['errs'], '/^conf\/access\.json cannot be read/');

endChecks();
