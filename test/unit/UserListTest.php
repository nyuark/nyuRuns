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
use nyu\Access\Sess\Basic;
use nyu\Access\Sess\JWT;
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
		parent::__construct( Array('cryptKey'=>$confValues['cryptKey'] ?? '', 'maxTries'=>0) );
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
	return Array($tmpList->chckCredential(Array('user'=>'anna', 'pass'=>'pw-anna')), $tmpList->finalErrorText(), $tmpList->wrngCredential());
}

$fxtPath = 'test'.DS.'fixtures'.DS.'users.lst';
$usrList = new UserList( Array('maxTries'=>0, 'listFile'=>$fxtPath) );
$annaPwd = Array('user'=>'anna', 'pass'=>'pw-anna');
$annaIdn = Array('user'=>'anna', 'role'=>'manage', 'id'=>1);

// The short name of the check
checkIdentical('userFile is the short name of UserList', Checker::authClssString('userFile'), '\\nyu\\Access\\Auth\\UserList');

// Passwords : the same answers as the native userbase
checkIdentical('UserList right password: identity with role and id', $usrList->chckCredential($annaPwd), $annaIdn);
checkIdentical('UserList right password is not a wrong credential', $usrList->wrngCredential(), false);
checkIdentical('UserList wrong password', Array($usrList->chckCredential(Array('user'=>'anna', 'pass'=>'nope')), $usrList->finalErrorText(), $usrList->wrngCredential()), Array(false, 'Invalid credentials', true));
checkIdentical('UserList unknown user', Array($usrList->chckCredential(Array('user'=>'zed', 'pass'=>'pw-zed')), $usrList->finalErrorText(), $usrList->wrngCredential()), Array(false, 'Invalid credentials', true));
checkIdentical('UserList names are matched with case', $usrList->chckCredential(Array('user'=>'Anna', 'pass'=>'pw-anna')), false);
checkIdentical('UserList no name', Array($usrList->chckCredential(Array('user'=>'', 'pass'=>'pw-anna')), $usrList->finalErrorText()), Array(false, 'Invalid credentials'));
checkIdentical('UserList pending user: refused as a wrong password is', Array($usrList->chckCredential(Array('user'=>'cleo', 'pass'=>'pw-cleo')), $usrList->finalErrorText(), $usrList->wrngCredential()), Array(false, 'Invalid credentials', true));
checkIdentical('UserList locked user: refused the same way', Array($usrList->chckCredential(Array('user'=>'dave', 'pass'=>'pw-dave')), $usrList->finalErrorText()), Array(false, 'Invalid credentials'));
checkIdentical('UserList the status is only told to who has the password', Array($usrList->chckCredential(Array('user'=>'dave', 'pass'=>'nope')), $usrList->finalErrorText()), Array(false, 'Invalid credentials'));
checkIdentical('UserList the path can start with a slash', (new UserList(Array('maxTries'=>0, 'listFile'=>'/'.$fxtPath)))->chckCredential($annaPwd), $annaIdn);

// Fields left out : a member, active, without an id
$tfaScrt = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$emmaPwd = Array('user'=>'emma', 'pass'=>'pw-emma');
checkIdentical('UserList role, status and id left out', $usrList->chckCredential($emmaPwd + Array('code'=>Totp::codeAtTimeText($tfaScrt, time()))), Array('user'=>'emma', 'role'=>'member', 'id'=>null));

// 2FA : a user with a secret must give the code of its authenticator
checkIdentical('UserList 2FA: password alone', Array($usrList->chckCredential($emmaPwd), $usrList->finalErrorText(), $usrList->wrngCredential()), Array(false, 'Verification code required', false));
checkIdentical('UserList 2FA: wrong code', Array($usrList->chckCredential($emmaPwd + Array('code'=>'000000')), $usrList->finalErrorText(), $usrList->wrngCredential()), Array(false, 'Invalid verification code', true));
checkIdentical('UserList 2FA: wrong password, whatever the code', Array($usrList->chckCredential(Array('user'=>'emma', 'pass'=>'nope', 'code'=>Totp::codeAtTimeText($tfaScrt, time()))), $usrList->finalErrorText()), Array(false, 'Invalid credentials'));

// A secret written encrypted is read with the key of the block
$sealKey = str_repeat('k', 32);
$emmaCod = $emmaPwd + Array('code'=>Totp::codeAtTimeText($tfaScrt, time()));
if( function_exists('openssl_encrypt') ){
	file_put_contents(inTemporaryDir('sealed.lst'), json_encode(Array('users'=>Array(Array('userName'=>'emma', 'passHash'=>password_hash('pw-emma', PASSWORD_BCRYPT, Array('cost'=>4)), 'twoFaKey'=>Totp::sealSecretText($tfaScrt, $sealKey))))));
	checkIdentical('UserList 2FA: an encrypted secret, read with the key', (new PathUserList(Array('listFile'=>inTemporaryDir('sealed.lst'), 'cryptKey'=>$sealKey)))->chckCredential($emmaCod)['user'] ?? null, 'emma');
	$noKeyLs = new PathUserList( Array('listFile'=>inTemporaryDir('sealed.lst')) );
	checkIdentical('UserList 2FA: an encrypted secret, without the key', Array($noKeyLs->chckCredential($emmaCod), $noKeyLs->finalErrorText(), $noKeyLs->wrngCredential()), Array(false, 'Credential store unavailable', false));
}else{
	recordSkipping('UserList 2FA: secrets stored encrypted', 'the openssl extension is not loaded');
}
checkIdentical('UserList 2FA: a readable secret is still read with a key set', (new UserList(Array('maxTries'=>0, 'listFile'=>$fxtPath, 'cryptKey'=>$sealKey)))->chckCredential($emmaCod)['user'] ?? null, 'emma');

// Personal API token : its SHA-256 hash is the tokenKey of an active user
checkIdentical('UserList personal token', $usrList->chckCredential(Array('key'=>'list-test-token')), $annaIdn);
checkIdentical('UserList wrong token', Array($usrList->chckCredential(Array('key'=>'another-token')), $usrList->finalErrorText(), $usrList->wrngCredential()), Array(false, 'Invalid API key', true));
checkIdentical('UserList empty token', Array($usrList->chckCredential(Array('key'=>'')), $usrList->finalErrorText(), $usrList->wrngCredential()), Array(false, 'No API key provided', false));
checkIdentical('UserList the hash itself is not a token', $usrList->chckCredential(Array('key'=>hash('sha256', 'list-test-token'))), false);
$tknRows = Array('users'=>Array(
	Array('userName'=>'anna', 'passHash'=>'x', 'userStat'=>'locked', 'tokenKey'=>strtoupper(hash('sha256', 'tk-anna'))),
	Array('userName'=>'ben', 'passHash'=>'x', 'tokenKey'=>strtoupper(hash('sha256', 'tk-ben')))
));
file_put_contents(inTemporaryDir('tokens.lst'), json_encode($tknRows));
$tknList = new PathUserList( Array('listFile'=>inTemporaryDir('tokens.lst')) );
checkIdentical('UserList the token of a locked user is refused', Array($tknList->chckCredential(Array('key'=>'tk-anna')), $tknList->finalErrorText()), Array(false, 'Invalid API key'));
checkIdentical('UserList a tokenKey written in upper case', $tknList->chckCredential(Array('key'=>'tk-ben')), Array('user'=>'ben', 'role'=>'member', 'id'=>null));

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
checkIdentical('UserList setPasswd changes none', Array($usrList->savePasswdFlag('anna', 'new-anna'), $usrList->chckCredential($annaPwd)), Array(false, $annaIdn));
checkIdentical('UserList the file is never written', hash_file('sha256', TOP_DIR.DS.$fxtPath), $fxtHash);

// A file that cannot be used is refused as a whole, and is not a wrong credential
$unavail = Array(false, 'Credential store unavailable', false);
$annaHsh = password_hash('pw-anna', PASSWORD_BCRYPT, Array('cost'=>4));
$annaRow = Array('userName'=>'anna', 'passHash'=>$annaHsh);
Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );
checkIdentical('UserList a file with its two required fields', listReply(json_encode(Array('users'=>Array($annaRow)))), Array(Array('user'=>'anna', 'role'=>'member', 'id'=>null), '', false));
checkIdentical('UserList no file named', Array((new UserList(Array()))->chckCredential($annaPwd), (new UserList(Array('listFile'=>Array('x'))))->chckCredential($annaPwd)), Array(false, false));
$noneLst = new UserList( Array('listFile'=>'test'.DS.'fixtures'.DS.'missing.lst') );
checkIdentical('UserList missing file', Array($noneLst->chckCredential($annaPwd), $noneLst->finalErrorText(), $noneLst->wrngCredential()), $unavail);
checkIdentical('UserList missing file: token and userIdent', Array($noneLst->chckCredential(Array('key'=>'list-test-token')), $noneLst->finalErrorText(), $noneLst->usrIdentityArr('anna'), $noneLst->finalErrorText()), Array(false, 'Credential store unavailable', false, 'Credential store unavailable'));
checkIdentical('UserList not a JSON text', listReply('anna:'.$annaHsh), $unavail);
checkIdentical('UserList no users list', listReply(json_encode(Array($annaRow))), $unavail);
checkIdentical('UserList a user without a passHash', listReply(json_encode(Array('users'=>Array($annaRow, Array('userName'=>'ben'))))), $unavail);
checkIdentical('UserList a user with an empty passHash', listReply(json_encode(Array('users'=>Array($annaRow, Array('userName'=>'ben', 'passHash'=>''))))), $unavail);
checkIdentical('UserList a user without a userName', listReply(json_encode(Array('users'=>Array($annaRow, Array('passHash'=>$annaHsh))))), $unavail);
checkIdentical('UserList a user that is not a block', listReply(json_encode(Array('users'=>Array($annaRow, 'ben')))), $unavail);
checkIdentical('UserList a role that is not a text', listReply(json_encode(Array('users'=>Array($annaRow + Array('userRole'=>Array('manage')))))), $unavail);
checkIdentical('UserList a language that is not a text', listReply(json_encode(Array('users'=>Array($annaRow + Array('userLang'=>Array('fr')))))), $unavail);
checkIdentical('UserList an id that is not a number or a text', listReply(json_encode(Array('users'=>Array($annaRow + Array('id_user'=>Array(1)))))), $unavail);
checkIdentical('UserList a user listed twice', listReply(json_encode(Array('users'=>Array($annaRow, Array('userName'=>'anna', 'passHash'=>$annaHsh, 'userRole'=>'manage'))))), $unavail);
checkIdentical('UserList an address shared by two users, whatever its case', listReply(json_encode(Array('users'=>Array($annaRow + Array('userMail'=>'anna@example.ch'), Array('userName'=>'ben', 'passHash'=>$annaHsh, 'userMail'=>'Anna@Example.CH'))))), $unavail);
checkIdentical('UserList a tokenKey shared by two users', listReply(json_encode(Array('users'=>Array($annaRow + Array('tokenKey'=>'ab12'), Array('userName'=>'ben', 'passHash'=>$annaHsh, 'tokenKey'=>'AB12'))))), $unavail);
checkIdentical('UserList an unknown status is not active', listReply(json_encode(Array('users'=>Array($annaRow + Array('userStat'=>'online'))))), Array(false, 'Invalid credentials', true));
checkIdentical('UserList an unknown role is given as written', listReply(json_encode(Array('users'=>Array($annaRow + Array('userRole'=>'root')))))[0], Array('user'=>'anna', 'role'=>'root', 'id'=>null));
Logger::plugLogsConfig( Array() );
$axsLogs = file_exists(inTemporaryDir('logs').DS.'access.log') ? file(inTemporaryDir('logs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkIdentical('access log: one error per file refused, none for a missing one', count(preg_grep('/\[ERROR\] Users file /', $axsLogs)), 12);
checkIdentical('access log: the status of a user that is not active is told there only', array_values(preg_grep('/is not active/', $axsLogs)), Array('['.date('Y').'] [WARNING] Credential refused: user "anna" is not active (online)'));
checkIdentical('access log: why the file is refused', $axsLogs[9] ?? null, '['.date('Y').'] [ERROR] Users file "users.lst" cannot be used: user "anna" is listed twice');
checkIdentical('access log: a language that is not a text', count(preg_grep('/cannot be used: the userLang of user \"anna\" is not a text$/', $axsLogs)), 1);
checkIdentical('access log: an address shared', count(preg_grep('/cannot be used: the userMail of user \"ben\" is the one of another user$/', $axsLogs)), 1);
checkIdentical('access log: neither a hash nor a token is told', count(preg_grep('/\$2y\$|ab12/i', $axsLogs)), 0);

// Through the Checker : Basic credentials, a personal token, and a JWT confirmed against the file
$jwtConf = Array('hmacSecr'=>str_repeat('s', 40), 'lifeSecs'=>60);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TESTFILE'=>Array('maxTries'=>0, 'listFile'=>$fxtPath), 'TESTTOKN'=>$jwtConf, 'TRYLIMIT'=>Array('maxTries'=>0))) );
/**
 * Runs the access check of a request against the users file.
 *
 * @param String $carrierStr Short name of the session carrier.
 * @param Array  $headersArr Headers of the request.
 * @param String $carrierCnf Config block of the carrier.
 * @return Array The status, the message, the user and the role.
 */
function listAccess( String $carrierStr, Array $headersArr, String $carrierCnf='testFile' ): Array {
	$axsConf = Array('required'=>true, 'sessWith'=>$carrierStr, 'sessConf'=>$carrierCnf, 'authWith'=>'userFile', 'authConf'=>'testFile');
	$axsRslt = (new Checker( $axsConf, mockRequestObj($headersArr, Array('REMOTE_ADDR'=>'203.0.113.7')) ))->validateAccess();
	return Array($axsRslt->readFileDetail('statCode'), $axsRslt->readFileDetail('statMssg'), $axsRslt->readFileDetail('user'), $axsRslt->readFileDetail('role'));
}
checkIdentical('Checker with UserList: Basic access', listAccess('httpBasc', Array('Authorization'=>'Basic '.base64_encode('anna:pw-anna'))), Array(200, '', 'anna', 'manage'));
checkIdentical('Checker with UserList: Basic access of a locked user', listAccess('httpBasc', Array('Authorization'=>'Basic '.base64_encode('dave:pw-dave'))), Array(401, 'Invalid credentials', '', null));
checkIdentical('Checker with UserList: Basic access is closed to a user with a secret', listAccess('httpBasc', Array('Authorization'=>'Basic '.base64_encode('emma:pw-emma'))), Array(401, 'Verification code required', '', null));
checkIdentical('Checker with UserList: personal token in the API key header', listAccess('apiHeadr', Array('X-Api-Key'=>'list-test-token')), Array(200, '', 'anna', 'manage'));
checkIdentical('Checker with UserList: wrong personal token', listAccess('apiHeadr', Array('X-Api-Key'=>'another-token')), Array(401, 'Invalid API key', '', null));
$jwtSess = new JWT( $jwtConf );
checkIdentical('Checker with UserList: the role of a token is the one of the file', listAccess('jwtToken', Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('user'=>'anna', 'role'=>'member'))), 'testTokn'), Array(200, '', 'anna', 'manage'));
checkIdentical('Checker with UserList: the token of a locked user is refused', listAccess('jwtToken', Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('user'=>'dave', 'role'=>'system'))), 'testTokn'), Array(401, 'Account not active', '', null));
checkIdentical('Checker with UserList: the token of a user no longer in the file is refused', listAccess('jwtToken', Array('Authorization'=>'Bearer '.$jwtSess->issueTokenText(Array('user'=>'zed', 'role'=>'manage'))), 'testTokn')[0], 401);

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
