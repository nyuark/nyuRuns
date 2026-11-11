<?php

// Flat file of users (Access\Auth\UserList) : the native userbase read from
// a file, without any database. The file is only read.
// Users come from test/fixtures/users.lst, password pw-<name> :
//
//   anna   manage  active   id 1, personal token list-test-token
//   ben    member  active   id 2
//   cleo   member  pendng   id 3
//   dave   system  locked   id 4
//   emma   no role, no status, no id : a member, active; with a 2FA secret

require __DIR__.'/../lib/harness.php';

use nyu\Access\Auth\UserList;
use nyu\Access\Carr\Basic;
use nyu\Access\Carr\JWT;
use nyu\Access\Checker;
use nyu\Access\Totp;
use nyu\Tools\Registry;
use nyu\Tools\Logger;

/**
 * A users list reading a file of the tests, wherever it is.
 */
class PathUserList extends UserList{

	/**
	 * @param Array $confValues Config block, its 'listFile' being the full path of the users file.
	 */
	function __construct( Array $confValues ){
		parent::__construct( Array('tfaCrypt'=>$confValues['tfaCrypt'] ?? '', 'maxTries'=>0) );
		$this->usrLstPath = strval($confValues['listFile'] ?? '');
	}
}

/**
 * Writes a users file of the tests and checks one login against it.
 *
 * @param String $fileString Content of the file.
 * @return Array What the check answers, its message and whether it was a wrong credential.
 */
function listReply( String $fileString ): Array {
	file_put_contents(inTemporaryDir('users.lst'), $fileString);
	$tmpList = new PathUserList( Array('listFile'=>inTemporaryDir('users.lst')) );
	return Array($tmpList->checkSecretArr(Array('user'=>'anna', 'pass'=>'pw-anna')), $tmpList->finalErrorText(), $tmpList->hasWrongSecret());
}

$fxtPath = 'test'.DS.'fixtures'.DS.'users.lst';
$usrList = new UserList( Array('maxTries'=>0, 'listFile'=>$fxtPath) );
$annaPwd = Array('user'=>'anna', 'pass'=>'pw-anna');
$annaIdn = Array('userName'=>'anna', 'userRole'=>'sysadmin', 'idOfUser'=>1);

// The short name of the check
checkIdentical('userFile is the short name of UserList', Checker::authClssString('userFile'), '\\nyu\\Access\\Auth\\UserList');

// Passwords : the same answers as the native userbase
checkIdentical('UserList right password: identity with role and id', $usrList->checkSecretArr($annaPwd), $annaIdn);
checkIdentical('UserList right password is not a wrong credential', $usrList->hasWrongSecret(), false);
checkIdentical('UserList wrong password', Array($usrList->checkSecretArr(Array('user'=>'anna', 'pass'=>'nope')), $usrList->finalErrorText(), $usrList->hasWrongSecret()), Array(false, 'Invalid credentials', true));
checkIdentical('UserList unknown user', Array($usrList->checkSecretArr(Array('user'=>'zed', 'pass'=>'pw-zed')), $usrList->finalErrorText(), $usrList->hasWrongSecret()), Array(false, 'Invalid credentials', true));
checkIdentical('UserList names are matched with case', $usrList->checkSecretArr(Array('user'=>'Anna', 'pass'=>'pw-anna')), false);
checkIdentical('UserList no name', Array($usrList->checkSecretArr(Array('user'=>'', 'pass'=>'pw-anna')), $usrList->finalErrorText()), Array(false, 'Invalid credentials'));
checkIdentical('UserList pending user: refused as a wrong password is', Array($usrList->checkSecretArr(Array('user'=>'cleo', 'pass'=>'pw-cleo')), $usrList->finalErrorText(), $usrList->hasWrongSecret()), Array(false, 'Invalid credentials', true));
checkIdentical('UserList locked user: refused the same way', Array($usrList->checkSecretArr(Array('user'=>'dave', 'pass'=>'pw-dave')), $usrList->finalErrorText()), Array(false, 'Invalid credentials'));
checkIdentical('UserList the status is only told to who has the password', Array($usrList->checkSecretArr(Array('user'=>'dave', 'pass'=>'nope')), $usrList->finalErrorText()), Array(false, 'Invalid credentials'));
checkIdentical('UserList the path can start with a slash', (new UserList(Array('maxTries'=>0, 'listFile'=>'/'.$fxtPath)))->checkSecretArr($annaPwd), $annaIdn);

// Fields left out : a member, active, without an id
$tfaScrt = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$emmaPwd = Array('user'=>'emma', 'pass'=>'pw-emma');
checkIdentical('UserList role, status and id left out', $usrList->checkSecretArr($emmaPwd + Array('code'=>Totp::codeAtTimeText($tfaScrt, time()))), Array('userName'=>'emma', 'userRole'=>'internal', 'idOfUser'=>null));

// 2FA : a user with a secret must give the code of its authenticator
checkIdentical('UserList 2FA: password alone', Array($usrList->checkSecretArr($emmaPwd), $usrList->finalErrorText(), $usrList->hasWrongSecret()), Array(false, 'Verification code required', false));
checkIdentical('UserList 2FA: wrong code', Array($usrList->checkSecretArr($emmaPwd + Array('code'=>'000000')), $usrList->finalErrorText(), $usrList->hasWrongSecret()), Array(false, 'Invalid verification code', true));
checkIdentical('UserList 2FA: wrong password, whatever the code', Array($usrList->checkSecretArr(Array('user'=>'emma', 'pass'=>'nope', 'code'=>Totp::codeAtTimeText($tfaScrt, time()))), $usrList->finalErrorText()), Array(false, 'Invalid credentials'));

// A secret written encrypted is read with the key of the block
$sealKey = str_repeat('k', 32);
$emmaCod = $emmaPwd + Array('code'=>Totp::codeAtTimeText($tfaScrt, time()));
if( function_exists('openssl_encrypt') ){
	file_put_contents(inTemporaryDir('sealed.lst'), json_encode(Array('users'=>Array(Array('systemName'=>'emma', 'passwdHash'=>password_hash('pw-emma', PASSWORD_BCRYPT, Array('cost'=>4)), 'twoFactKey'=>Totp::sealSecretText($tfaScrt, $sealKey))))));
	checkIdentical('UserList 2FA: an encrypted secret, read with the key', (new PathUserList(Array('listFile'=>inTemporaryDir('sealed.lst'), 'tfaCrypt'=>$sealKey)))->checkSecretArr($emmaCod)['userName'] ?? null, 'emma');
	$noKeyLs = new PathUserList( Array('listFile'=>inTemporaryDir('sealed.lst')) );
	checkIdentical('UserList 2FA: an encrypted secret, without the key', Array($noKeyLs->checkSecretArr($emmaCod), $noKeyLs->finalErrorText(), $noKeyLs->hasWrongSecret()), Array(false, 'Credential store unavailable', false));
}else{
	recordSkipping('UserList 2FA: secrets stored encrypted', 'the openssl extension is not loaded');
}
checkIdentical('UserList 2FA: a readable secret is still read with a key set', (new UserList(Array('maxTries'=>0, 'listFile'=>$fxtPath, 'tfaCrypt'=>$sealKey)))->checkSecretArr($emmaCod)['userName'] ?? null, 'emma');

// Personal API token : its SHA-256 hash is the bearerHash of an active user
checkIdentical('UserList personal token', $usrList->checkSecretArr(Array('key'=>'list-test-token')), $annaIdn);
checkIdentical('UserList wrong token', Array($usrList->checkSecretArr(Array('key'=>'another-token')), $usrList->finalErrorText(), $usrList->hasWrongSecret()), Array(false, 'Invalid API key', true));
checkIdentical('UserList empty token', Array($usrList->checkSecretArr(Array('key'=>'')), $usrList->finalErrorText(), $usrList->hasWrongSecret()), Array(false, 'No API key provided', false));
checkIdentical('UserList the hash itself is not a token', $usrList->checkSecretArr(Array('key'=>hash('sha256', 'list-test-token'))), false);
$tknRows = Array('users'=>Array(
	Array('systemName'=>'anna', 'passwdHash'=>'x', 'userStatus'=>'disabled', 'bearerHash'=>strtoupper(hash('sha256', 'tk-anna'))),
	Array('systemName'=>'ben', 'passwdHash'=>'x', 'bearerHash'=>strtoupper(hash('sha256', 'tk-ben')))
));
file_put_contents(inTemporaryDir('tokens.lst'), json_encode($tknRows));
$tknList = new PathUserList( Array('listFile'=>inTemporaryDir('tokens.lst')) );
checkIdentical('UserList the token of a locked user is refused', Array($tknList->checkSecretArr(Array('key'=>'tk-anna')), $tknList->finalErrorText()), Array(false, 'Invalid API key'));
checkIdentical('UserList a bearerHash written in upper case', $tknList->checkSecretArr(Array('key'=>'tk-ben')), Array('userName'=>'ben', 'userRole'=>'internal', 'idOfUser'=>null));

// userIdent : the current identity of a user known by other means
checkIdentical('UserList userIdent of an active user', $usrList->usrIdentityArr('anna'), $annaIdn);
checkIdentical('UserList userIdent of a pending user', Array($usrList->usrIdentityArr('cleo'), $usrList->finalErrorText()), Array(false, 'Account not active'));
checkIdentical('UserList userIdent of an unknown user', Array($usrList->usrIdentityArr('zed'), $usrList->finalErrorText()), Array(false, 'Account not active'));
checkIdentical('UserList userIdent without a name', $usrList->usrIdentityArr(''), false);

// What needs a write is answered false, and the file is left as it is
$fxtHash = hash_file('sha256', TOP_DIR.DS.$fxtPath);
checkIdentical('UserList keepAlive stamps nothing', $usrList->retainAliveFlg('anna'), false);
checkIdentical('UserList onlineNow is not known', $usrList->onlineNowArray(), false);
checkIdentical('UserList makeToken makes none', $usrList->makeToknString('anna'), false);
checkIdentical('UserList makeTwoFa makes none', $usrList->buildTwoFactor('anna'), false);
checkIdentical('UserList setPasswd changes none', Array($usrList->savePasswdFlag('anna', 'new-anna'), $usrList->checkSecretArr($annaPwd)), Array(false, $annaIdn));
checkIdentical('UserList the file is never written', hash_file('sha256', TOP_DIR.DS.$fxtPath), $fxtHash);

// A file that cannot be used is refused as a whole, and is not a wrong credential
$unavail = Array(false, 'Credential store unavailable', false);
$annaHsh = password_hash('pw-anna', PASSWORD_BCRYPT, Array('cost'=>4));
$annaRow = Array('systemName'=>'anna', 'passwdHash'=>$annaHsh);
Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );
checkIdentical('UserList a file with its two required fields', listReply(json_encode(Array('users'=>Array($annaRow)))), Array(Array('userName'=>'anna', 'userRole'=>'internal', 'idOfUser'=>null), '', false));
checkIdentical('UserList no file named', Array((new UserList(Array()))->checkSecretArr($annaPwd), (new UserList(Array('listFile'=>Array('x'))))->checkSecretArr($annaPwd)), Array(false, false));
$noneLst = new UserList( Array('listFile'=>'test'.DS.'fixtures'.DS.'missing.lst') );
checkIdentical('UserList missing file', Array($noneLst->checkSecretArr($annaPwd), $noneLst->finalErrorText(), $noneLst->hasWrongSecret()), $unavail);
checkIdentical('UserList missing file: token and userIdent', Array($noneLst->checkSecretArr(Array('key'=>'list-test-token')), $noneLst->finalErrorText(), $noneLst->usrIdentityArr('anna'), $noneLst->finalErrorText()), Array(false, 'Credential store unavailable', false, 'Credential store unavailable'));
checkIdentical('UserList not a JSON text', listReply('anna:'.$annaHsh), $unavail);
checkIdentical('UserList no users list', listReply(json_encode(Array($annaRow))), $unavail);
checkIdentical('UserList a user without a passwdHash', listReply(json_encode(Array('users'=>Array($annaRow, Array('systemName'=>'ben'))))), $unavail);
checkIdentical('UserList a user with an empty passwdHash', listReply(json_encode(Array('users'=>Array($annaRow, Array('systemName'=>'ben', 'passwdHash'=>''))))), $unavail);
checkIdentical('UserList a user without a systemName', listReply(json_encode(Array('users'=>Array($annaRow, Array('passwdHash'=>$annaHsh))))), $unavail);
checkIdentical('UserList a user that is not a block', listReply(json_encode(Array('users'=>Array($annaRow, 'ben')))), $unavail);
checkIdentical('UserList a role that is not a text', listReply(json_encode(Array('users'=>Array($annaRow + Array('accessRole'=>Array('sysadmin')))))), $unavail);
checkIdentical('UserList a language that is not a text', listReply(json_encode(Array('users'=>Array($annaRow + Array('userLocale'=>Array('fr')))))), $unavail);
checkIdentical('UserList an id that is not a number or a text', listReply(json_encode(Array('users'=>Array($annaRow + Array('id_user'=>Array(1)))))), $unavail);
checkIdentical('UserList a user listed twice', listReply(json_encode(Array('users'=>Array($annaRow, Array('systemName'=>'anna', 'passwdHash'=>$annaHsh, 'accessRole'=>'sysadmin'))))), $unavail);
checkIdentical('UserList an address shared by two users, whatever its case', listReply(json_encode(Array('users'=>Array($annaRow + Array('contactEml'=>'anna@example.ch'), Array('systemName'=>'ben', 'passwdHash'=>$annaHsh, 'contactEml'=>'Anna@Example.CH'))))), $unavail);
checkIdentical('UserList a bearerHash shared by two users', listReply(json_encode(Array('users'=>Array($annaRow + Array('bearerHash'=>'ab12'), Array('systemName'=>'ben', 'passwdHash'=>$annaHsh, 'bearerHash'=>'AB12'))))), $unavail);
checkIdentical('UserList an unknown status is not active', listReply(json_encode(Array('users'=>Array($annaRow + Array('userStatus'=>'online'))))), Array(false, 'Invalid credentials', true));
checkIdentical('UserList an unknown role is given as written', listReply(json_encode(Array('users'=>Array($annaRow + Array('accessRole'=>'root')))))[0], Array('userName'=>'anna', 'userRole'=>'root', 'idOfUser'=>null));
Logger::plugLogsConfig( Array() );
$axsLogs = file_exists(inTemporaryDir('logs').DS.'access.log') ? file(inTemporaryDir('logs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkIdentical('access log: one error per file refused, none for a missing one', count(preg_grep('/\[ERROR\] Users file /', $axsLogs)), 12);
checkIdentical('access log: the status of a user that is not active is told there only', array_values(preg_grep('/is not active/', $axsLogs)), Array('['.date('Y').'] [WARNING] Credential refused: user "anna" is not active (online)'));
checkIdentical('access log: why the file is refused', $axsLogs[9] ?? null, '['.date('Y').'] [ERROR] Users file "users.lst" cannot be used: user "anna" is listed twice');
checkIdentical('access log: a language that is not a text', count(preg_grep('/cannot be used: the userLocale of user \"anna\" is not a text$/', $axsLogs)), 1);
checkIdentical('access log: an address shared', count(preg_grep('/cannot be used: the contactEml of user \"ben\" is the one of another user$/', $axsLogs)), 1);
checkIdentical('access log: neither a hash nor a token is told', count(preg_grep('/\$2y\$|ab12/i', $axsLogs)), 0);

// Through the Checker : Basic credentials, a personal token, and a JWT confirmed against the file
$jwtConf = Array('hmacSecr'=>str_repeat('s', 40), 'lifeSecs'=>60);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TESTFILE'=>Array('maxTries'=>0, 'listFile'=>$fxtPath), 'TESTTOKN'=>$jwtConf, 'TRYLIMIT'=>Array('maxTries'=>0))) );
/**
 * Runs the access check of a request against the users file.
 *
 * @param String $carrierStr Short name of the carrier.
 * @param Array  $headersArr Headers of the request.
 * @param String $carrierCnf Config block of the carrier.
 * @return Array The status, the message, the user and the role.
 */
function listAccess( String $carrierStr, Array $headersArr, String $carrierCnf='testFile' ): Array {
	$axsConf = Array('required'=>true, 'carrWith'=>$carrierStr, 'carrConf'=>$carrierCnf, 'authWith'=>'userFile', 'authConf'=>'testFile');
	$axsRslt = (new Checker( $axsConf, mockRequestObj($headersArr, Array('REMOTE_ADDR'=>'203.0.113.7')) ))->validateAccess();
	return Array($axsRslt->readValueByKey('statCode'), $axsRslt->readValueByKey('statMssg'), $axsRslt->readValueByKey('userName'), $axsRslt->readValueByKey('userRole'));
}
checkIdentical('Checker with UserList: Basic access', listAccess('httpBasc', Array('Authorization'=>'Basic '.base64_encode('anna:pw-anna'))), Array(200, '', 'anna', 'sysadmin'));
checkIdentical('Checker with UserList: Basic access of a locked user', listAccess('httpBasc', Array('Authorization'=>'Basic '.base64_encode('dave:pw-dave'))), Array(401, 'Invalid credentials', '', null));
checkIdentical('Checker with UserList: Basic access is closed to a user with a secret', listAccess('httpBasc', Array('Authorization'=>'Basic '.base64_encode('emma:pw-emma'))), Array(401, 'Verification code required', '', null));
checkIdentical('Checker with UserList: personal token in the API key header', listAccess('apiHeadr', Array('X-Api-Key'=>'list-test-token')), Array(200, '', 'anna', 'sysadmin'));
checkIdentical('Checker with UserList: wrong personal token', listAccess('apiHeadr', Array('X-Api-Key'=>'another-token')), Array(401, 'Invalid API key', '', null));
$jwtSess = new JWT( $jwtConf );
checkIdentical('Checker with UserList: the role of a token is the one of the file', listAccess('jwtToken', Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'anna', 'userRole'=>'internal'))), 'testTokn'), Array(200, '', 'anna', 'sysadmin'));
checkIdentical('Checker with UserList: the token of a locked user is refused', listAccess('jwtToken', Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'dave', 'userRole'=>'operator'))), 'testTokn'), Array(401, 'Account not active', '', null));
checkIdentical('Checker with UserList: the token of a user no longer in the file is refused', listAccess('jwtToken', Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('userName'=>'zed', 'userRole'=>'sysadmin'))), 'testTokn')[0], 401);

// The limit by address still applies : counters in a folder of the project, removed at the end
$prjFldr = 'temp'.DS.'limits_test_'.getmypid();
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TESTFILE'=>Array('maxTries'=>0, 'listFile'=>$fxtPath), 'TRYLIMIT'=>Array('maxTries'=>2, 'holdSecs'=>600, 'dataPath'=>$prjFldr))) );
$wrngHed = Array('Authorization'=>'Basic '.base64_encode('anna:nope'));
$rghtHed = Array('Authorization'=>'Basic '.base64_encode('anna:pw-anna'));
checkIdentical('Limit by address: an account not active is counted, as a wrong password is', Array(listAccess('httpBasc', Array('Authorization'=>'Basic '.base64_encode('cleo:pw-cleo')))[1], listAccess('httpBasc', Array('Authorization'=>'Basic '.base64_encode('cleo:pw-cleo')))[1], listAccess('httpBasc', $rghtHed)[0]), Array('Invalid credentials', 'Invalid credentials', 429));
dropTestFolder(TOP_DIR.DS.$prjFldr);
checkIdentical('Limit by address: two wrong passwords are told as such', Array(listAccess('httpBasc', $wrngHed)[1], listAccess('httpBasc', $wrngHed)[1]), Array('Invalid credentials', 'Invalid credentials'));
checkIdentical('Limit by address: the address is then held, even with the right password', Array(listAccess('httpBasc', $rghtHed)[0], listAccess('httpBasc', $rghtHed)[1]), Array(429, 'Too many attempts'));
dropTestFolder(TOP_DIR.DS.$prjFldr);

finishChecking();
