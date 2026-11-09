<?php

// Command line, appkey : the keys of the applications calling for their users,
// made, listed, given other routes and revoked in conf/access.json, and a key
// made here accepted by Access\AppKey.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/shellKit.php';

use nyu\Access\AppKey;
use nyu\Access\Sess\JWT;

if( !function_exists('openssl_pkey_new') ){
	recordSkipping('Shell appkey', 'the openssl extension is not loaded');
	finishChecking();
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
$projDir = makeProjectTxt('appkey', Array(
	'conf/system.ini'=>buildSystemIni(),
	'conf/access.json'=>"{\n\t\"limits\": { \"maxtry\": 10, \"holdtm\": 600, \"folder\": \"temp/limits\" }\n}\n",
	'conf/keys/portal.pub'=>$appPubl,
	'conf/keys/weak.pub'=>$weakPub
));
$axsJson = function() use ($projDir): Array {
	return (array)json_decode( strval(fetchInProject($projDir, 'conf/access.json')), true );
};

// Making
$jsnVals = runShellAsJson($projDir, Array('appsKeys', 'makeKeys', 'portal', '--routeSet=contacts/*, /members/save', '--publFile=conf/keys/portal.pub'));
$keyText = strval($jsnVals['secret']['Key of portal'] ?? '');
$appEntr = $axsJson()['apkeys']['applst']['portal'] ?? Array();
checkIdentical('makes shows the key once and stores its hash only', Array($jsnVals['key made'] ?? null, strlen($keyText), $appEntr['keyhsh'] ?? null, strpos(strval(fetchInProject($projDir, 'conf/access.json')), $keyText)), Array('portal', 43, hash('sha256', $keyText), false));
checkIdentical('makes: the block created with its header, the routes, the key file, member by default', Array($axsJson()['apkeys']['header'] ?? null, $appEntr['routes'] ?? null, $appEntr['pubkey'] ?? null, $appEntr['maxrol'] ?? null), Array('X-App-Key', Array('contacts/*', '/members/save'), 'conf/keys/portal.pub', 'member'));
checkPregMatch('makes: the date it was made', strval($appEntr['madedt'] ?? ''), '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/');
checkIdentical('makes: the other blocks kept', $axsJson()['limits'] ?? null, Array('maxtry'=>10, 'holdtm'=>600, 'folder'=>'temp/limits'));
checkIdentical('makes: a highest role of its own', runShellAsJson($projDir, Array('appsKeys', 'makeKeys', 'mobile', '--routeSet=contacts/view', '--publFile=conf/keys/portal.pub', '--roleTops=system'))['key made'] ?? null, 'mobile');
checkIdentical('makes: a name already listed', callShellArray($projDir, Array('appsKeys', 'makeKeys', 'portal', '--routeSet=contacts/*', '--publFile=conf/keys/portal.pub'))['code'], 3);
checkIdentical('makes: a bad name, a missing name, no routes, a bad route', Array(
	callShellArray($projDir, Array('appsKeys', 'makeKeys', 'Por Tal', '--routeSet=contacts/*', '--publFile=conf/keys/portal.pub'))['code'],
	callShellArray($projDir, Array('appsKeys', 'makeKeys'))['code'],
	callShellArray($projDir, Array('appsKeys', 'makeKeys', 'other', '--publFile=conf/keys/portal.pub'))['code'],
	callShellArray($projDir, Array('appsKeys', 'makeKeys', 'other', '--routeSet=contacts', '--publFile=conf/keys/portal.pub'))['code']
), Array(2, 2, 2, 2));
checkIdentical('makes: an unknown role, no key file, a missing file, a weak key', Array(
	callShellArray($projDir, Array('appsKeys', 'makeKeys', 'other', '--routeSet=contacts/*', '--publFile=conf/keys/portal.pub', '--roleTops=root'))['code'],
	callShellArray($projDir, Array('appsKeys', 'makeKeys', 'other', '--routeSet=contacts/*'))['code'],
	callShellArray($projDir, Array('appsKeys', 'makeKeys', 'other', '--routeSet=contacts/*', '--publFile=conf/keys/none.pub'))['code'],
	callShellArray($projDir, Array('appsKeys', 'makeKeys', 'other', '--routeSet=contacts/*', '--publFile=conf/keys/weak.pub'))['code']
), Array(2, 2, 2, 2));
checkIdentical('makes: nothing written by a refusal', array_keys($axsJson()['apkeys']['applst'] ?? Array()), Array('portal', 'mobile'));

// A key made here, checked as a request bearing it
$blkConf = $axsJson()['apkeys'];
$blkConf['applst']['portal']['pubkey'] = writeInProject($projDir, 'conf/keys/portal.pub', $appPubl);
$appTokn = (new JWT( Array('algrtm'=>'RS256', 'prvkey'=>writeInProject($projDir, 'conf/keys/portal.key', $appPriv)) ))->issueTokenText( Array('user'=>'ann', 'role'=>'member') );
$idntArr = (new AppKey($blkConf))->verifyAppArray( mockRequestObj(Array('X-App-Key'=>$keyText, 'Authorization'=>'Bearer '.$appTokn)) );
checkIdentical('the key made is accepted with a token of the application', Array($idntArr['user'] ?? null, $idntArr['fromApp'] ?? null), Array('ann', 'portal'));

// Listing
$rowList = runShellAsJson($projDir, Array('appsKeys', 'fullList'))['rows'] ?? Array();
checkIdentical('lists the applications, their routes and highest role', array_map(function(Array $tableRow){ return $tableRow['application'].' '.$tableRow['routes'].' '.$tableRow['highest role']; }, $rowList), Array('portal contacts/*, /members/save member', 'mobile contacts/view system'));
checkIdentical('lists never shows a hash', strpos(callShellArray($projDir, Array('appsKeys', 'fullList'))['text'], hash('sha256', $keyText)), false);

// Routes
checkIdentical('route replaces the routes of an application', Array(callShellArray($projDir, Array('appsKeys', 'setRoute', 'mobile', '--routeSet=contacts/*,/calendar/save'))['code'], $axsJson()['apkeys']['applst']['mobile']['routes'] ?? null), Array(0, Array('contacts/*', '/calendar/save')));
checkIdentical('route: an unknown application, a bad route', Array(callShellArray($projDir, Array('appsKeys', 'setRoute', 'nobody', '--routeSet=contacts/*'))['code'], callShellArray($projDir, Array('appsKeys', 'setRoute', 'mobile', '--routeSet=*'))['code']), Array(3, 2));

// Revoking
checkIdentical('revok removes the key of that application only', Array(callShellArray($projDir, Array('appsKeys', 'revokeIt', 'portal'))['code'], array_keys($axsJson()['apkeys']['applst'] ?? Array())), Array(0, Array('mobile')));
checkIdentical('revok: twice, a missing name', Array(callShellArray($projDir, Array('appsKeys', 'revokeIt', 'portal'))['code'], callShellArray($projDir, Array('appsKeys', 'revokeIt'))['code']), Array(3, 2));
checkPregMatch('every change traced on the shell channel', strval(fetchInProject($projDir, 'temp/logs/shell.log')), '/App key: key of application "portal" revoked/');

// A project without its access config
$bareDir = makeProjectTxt('noaccess', Array('conf/system.ini'=>buildSystemIni()));
checkPregMatch('a project without conf/access.json is told so', callShellArray($bareDir, Array('appsKeys', 'fullList'))['errs'], '/^conf\/access\.json cannot be read/');

finishChecking();
