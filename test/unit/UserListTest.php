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
	 * @param Array $confValues Config block, its 'usrfle' being the full path of the users file.
	 */
	function __construct( Array $confValues ){
		parent::__construct( Array('tfakey'=>$confValues['tfakey'] ?? '') );
		$this->usrsFile = strval($confValues['usrfle'] ?? '');
	}
}

/**
 * Writes a users file of the tests and checks one login against it.
 *
 * @param String $fileString Content of the file.
 * @return Array What the check answers, its message and whether it was a wrong credential.
 */
function listReply( String $fileString ): Array {
	file_put_contents(inTempDir('users.lst'), $fileString);
	$tmpList = new PathUserList( Array('usrfle'=>inTempDir('users.lst')) );
	return Array($tmpList->checkCred(Array('user'=>'anna', 'pass'=>'pw-anna')), $tmpList->lastError(), $tmpList->wrongCred());
}

$fxtPath = 'test'.DS.'fixtures'.DS.'users.lst';
$usrList = new UserList( Array('usrfle'=>$fxtPath) );
$annaPwd = Array('user'=>'anna', 'pass'=>'pw-anna');
$annaIdn = Array('user'=>'anna', 'role'=>'manage', 'id'=>1);

// The short name of the check
checkSame('usrlst is the short name of UserList', Checker::authClass('usrlst'), '\\nyu\\Access\\Auth\\UserList');

// Passwords : the same answers as the native userbase
checkSame('UserList right password: identity with role and id', $usrList->checkCred($annaPwd), $annaIdn);
checkSame('UserList right password is not a wrong credential', $usrList->wrongCred(), false);
checkSame('UserList wrong password', Array($usrList->checkCred(Array('user'=>'anna', 'pass'=>'nope')), $usrList->lastError(), $usrList->wrongCred()), Array(false, 'Invalid credentials', true));
checkSame('UserList unknown user', Array($usrList->checkCred(Array('user'=>'zed', 'pass'=>'pw-zed')), $usrList->lastError(), $usrList->wrongCred()), Array(false, 'Invalid credentials', true));
checkSame('UserList names are matched with case', $usrList->checkCred(Array('user'=>'Anna', 'pass'=>'pw-anna')), false);
checkSame('UserList no name', Array($usrList->checkCred(Array('user'=>'', 'pass'=>'pw-anna')), $usrList->lastError()), Array(false, 'Invalid credentials'));
checkSame('UserList pending user: refused as a wrong password is', Array($usrList->checkCred(Array('user'=>'cleo', 'pass'=>'pw-cleo')), $usrList->lastError(), $usrList->wrongCred()), Array(false, 'Invalid credentials', true));
checkSame('UserList locked user: refused the same way', Array($usrList->checkCred(Array('user'=>'dave', 'pass'=>'pw-dave')), $usrList->lastError()), Array(false, 'Invalid credentials'));
checkSame('UserList the status is only told to who has the password', Array($usrList->checkCred(Array('user'=>'dave', 'pass'=>'nope')), $usrList->lastError()), Array(false, 'Invalid credentials'));
checkSame('UserList the path can start with a slash', (new UserList(Array('usrfle'=>'/'.$fxtPath)))->checkCred($annaPwd), $annaIdn);

// Fields left out : a member, active, without an id
$tfaScrt = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$emmaPwd = Array('user'=>'emma', 'pass'=>'pw-emma');
checkSame('UserList role, status and id left out', $usrList->checkCred($emmaPwd + Array('code'=>Totp::codeAtTme($tfaScrt, time()))), Array('user'=>'emma', 'role'=>'member', 'id'=>null));

// 2FA : a user with a secret must give the code of its authenticator
checkSame('UserList 2FA: password alone', Array($usrList->checkCred($emmaPwd), $usrList->lastError(), $usrList->wrongCred()), Array(false, 'Verification code required', false));
checkSame('UserList 2FA: wrong code', Array($usrList->checkCred($emmaPwd + Array('code'=>'000000')), $usrList->lastError(), $usrList->wrongCred()), Array(false, 'Invalid verification code', true));
checkSame('UserList 2FA: wrong password, whatever the code', Array($usrList->checkCred(Array('user'=>'emma', 'pass'=>'nope', 'code'=>Totp::codeAtTme($tfaScrt, time()))), $usrList->lastError()), Array(false, 'Invalid credentials'));

// A secret written encrypted is read with the key of the block
$sealKey = str_repeat('k', 32);
$emmaCod = $emmaPwd + Array('code'=>Totp::codeAtTme($tfaScrt, time()));
if( function_exists('openssl_encrypt') ){
	file_put_contents(inTempDir('sealed.lst'), json_encode(Array('users'=>Array(Array('userName'=>'emma', 'passHash'=>password_hash('pw-emma', PASSWORD_BCRYPT, Array('cost'=>4)), 'twoFaKey'=>Totp::sealSecrt($tfaScrt, $sealKey))))));
	checkSame('UserList 2FA: an encrypted secret, read with the key', (new PathUserList(Array('usrfle'=>inTempDir('sealed.lst'), 'tfakey'=>$sealKey)))->checkCred($emmaCod)['user'] ?? null, 'emma');
	$noKeyLs = new PathUserList( Array('usrfle'=>inTempDir('sealed.lst')) );
	checkSame('UserList 2FA: an encrypted secret, without the key', Array($noKeyLs->checkCred($emmaCod), $noKeyLs->lastError(), $noKeyLs->wrongCred()), Array(false, 'Credential store unavailable', false));
}else{
	skipCheck('UserList 2FA: secrets stored encrypted', 'the openssl extension is not loaded');
}
checkSame('UserList 2FA: a readable secret is still read with a key set', (new UserList(Array('usrfle'=>$fxtPath, 'tfakey'=>$sealKey)))->checkCred($emmaCod)['user'] ?? null, 'emma');

// Personal API token : its SHA-256 hash is the tokenKey of an active user
checkSame('UserList personal token', $usrList->checkCred(Array('key'=>'list-test-token')), $annaIdn);
checkSame('UserList wrong token', Array($usrList->checkCred(Array('key'=>'another-token')), $usrList->lastError(), $usrList->wrongCred()), Array(false, 'Invalid API key', true));
checkSame('UserList empty token', Array($usrList->checkCred(Array('key'=>'')), $usrList->lastError(), $usrList->wrongCred()), Array(false, 'No API key provided', false));
checkSame('UserList the hash itself is not a token', $usrList->checkCred(Array('key'=>hash('sha256', 'list-test-token'))), false);
$tknRows = Array('users'=>Array(
	Array('userName'=>'anna', 'passHash'=>'x', 'userStat'=>'locked', 'tokenKey'=>strtoupper(hash('sha256', 'tk-anna'))),
	Array('userName'=>'ben', 'passHash'=>'x', 'tokenKey'=>strtoupper(hash('sha256', 'tk-ben')))
));
file_put_contents(inTempDir('tokens.lst'), json_encode($tknRows));
$tknList = new PathUserList( Array('usrfle'=>inTempDir('tokens.lst')) );
checkSame('UserList the token of a locked user is refused', Array($tknList->checkCred(Array('key'=>'tk-anna')), $tknList->lastError()), Array(false, 'Invalid API key'));
checkSame('UserList a tokenKey written in upper case', $tknList->checkCred(Array('key'=>'tk-ben')), Array('user'=>'ben', 'role'=>'member', 'id'=>null));

// userIdent : the current identity of a user known by other means
checkSame('UserList userIdent of an active user', $usrList->userIdent('anna'), $annaIdn);
checkSame('UserList userIdent of a pending user', Array($usrList->userIdent('cleo'), $usrList->lastError()), Array(false, 'Account not active'));
checkSame('UserList userIdent of an unknown user', Array($usrList->userIdent('zed'), $usrList->lastError()), Array(false, 'Account not active'));
checkSame('UserList userIdent without a name', $usrList->userIdent(''), false);

// What needs a write is answered false, and the file is left as it is
$fxtHash = hash_file('sha256', TOP_DIR.DS.$fxtPath);
checkSame('UserList keepAlive stamps nothing', $usrList->keepAlive('anna'), false);
checkSame('UserList onlineNow is not known', $usrList->onlineNow(), false);
checkSame('UserList makeToken makes none', $usrList->makeToken('anna'), false);
checkSame('UserList makeTwoFa makes none', $usrList->makeTwoFa('anna'), false);
checkSame('UserList setPasswd changes none', Array($usrList->setPasswd('anna', 'new-anna'), $usrList->checkCred($annaPwd)), Array(false, $annaIdn));
checkSame('UserList the file is never written', hash_file('sha256', TOP_DIR.DS.$fxtPath), $fxtHash);

// A file that cannot be used is refused as a whole, and is not a wrong credential
$unavail = Array(false, 'Credential store unavailable', false);
$annaHsh = password_hash('pw-anna', PASSWORD_BCRYPT, Array('cost'=>4));
$annaRow = Array('userName'=>'anna', 'passHash'=>$annaHsh);
Logger::plugSetup( Array('path'=>inTempDir('logs'), 'mode'=>'BASE', 'pref'=>'Y') );
checkSame('UserList a file with its two required fields', listReply(json_encode(Array('users'=>Array($annaRow)))), Array(Array('user'=>'anna', 'role'=>'member', 'id'=>null), '', false));
checkSame('UserList no file named', Array((new UserList(Array()))->checkCred($annaPwd), (new UserList(Array('usrfle'=>Array('x'))))->checkCred($annaPwd)), Array(false, false));
$noneLst = new UserList( Array('usrfle'=>'test'.DS.'fixtures'.DS.'missing.lst') );
checkSame('UserList missing file', Array($noneLst->checkCred($annaPwd), $noneLst->lastError(), $noneLst->wrongCred()), $unavail);
checkSame('UserList missing file: token and userIdent', Array($noneLst->checkCred(Array('key'=>'list-test-token')), $noneLst->lastError(), $noneLst->userIdent('anna'), $noneLst->lastError()), Array(false, 'Credential store unavailable', false, 'Credential store unavailable'));
checkSame('UserList not a JSON text', listReply('anna:'.$annaHsh), $unavail);
checkSame('UserList no users list', listReply(json_encode(Array($annaRow))), $unavail);
checkSame('UserList a user without a passHash', listReply(json_encode(Array('users'=>Array($annaRow, Array('userName'=>'ben'))))), $unavail);
checkSame('UserList a user with an empty passHash', listReply(json_encode(Array('users'=>Array($annaRow, Array('userName'=>'ben', 'passHash'=>''))))), $unavail);
checkSame('UserList a user without a userName', listReply(json_encode(Array('users'=>Array($annaRow, Array('passHash'=>$annaHsh))))), $unavail);
checkSame('UserList a user that is not a block', listReply(json_encode(Array('users'=>Array($annaRow, 'ben')))), $unavail);
checkSame('UserList a role that is not a text', listReply(json_encode(Array('users'=>Array($annaRow + Array('userRole'=>Array('manage')))))), $unavail);
checkSame('UserList an id that is not a number or a text', listReply(json_encode(Array('users'=>Array($annaRow + Array('id_user'=>Array(1)))))), $unavail);
checkSame('UserList a user listed twice', listReply(json_encode(Array('users'=>Array($annaRow, Array('userName'=>'anna', 'passHash'=>$annaHsh, 'userRole'=>'manage'))))), $unavail);
checkSame('UserList a tokenKey shared by two users', listReply(json_encode(Array('users'=>Array($annaRow + Array('tokenKey'=>'ab12'), Array('userName'=>'ben', 'passHash'=>$annaHsh, 'tokenKey'=>'AB12'))))), $unavail);
checkSame('UserList an unknown status is not active', listReply(json_encode(Array('users'=>Array($annaRow + Array('userStat'=>'online'))))), Array(false, 'Invalid credentials', true));
checkSame('UserList an unknown role is given as written', listReply(json_encode(Array('users'=>Array($annaRow + Array('userRole'=>'root')))))[0], Array('user'=>'anna', 'role'=>'root', 'id'=>null));
Logger::plugSetup( Array() );
$axsLogs = file_exists(inTempDir('logs').DS.'access.log') ? file(inTempDir('logs').DS.'access.log', FILE_IGNORE_NEW_LINES) : Array();
checkSame('access log: one error per file refused, none for a missing one', count(preg_grep('/\[ERROR\] Users file /', $axsLogs)), 10);
checkSame('access log: the status of a user that is not active is told there only', array_values(preg_grep('/is not active/', $axsLogs)), Array('['.date('Y').'] [WARNING] Credential refused: user "anna" is not active (online)'));
checkSame('access log: why the file is refused', $axsLogs[8] ?? null, '['.date('Y').'] [ERROR] Users file "users.lst" cannot be used: user "anna" is listed twice');
checkSame('access log: neither a hash nor a token is told', count(preg_grep('/\$2y\$|ab12/i', $axsLogs)), 0);

// Through the Checker : Basic credentials, a personal token, and a JWT confirmed against the file
$jwtConf = Array('secret'=>str_repeat('s', 40), 'lifetm'=>60);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TSUSRS'=>Array('usrfle'=>$fxtPath), 'TSTOKN'=>$jwtConf, 'LIMITS'=>Array('maxtry'=>0))) );
/**
 * Runs the access check of a request against the users file.
 *
 * @param String $carrierStr Short name of the session carrier.
 * @param Array  $headersArr Headers of the request.
 * @param String $carrierCnf Config block of the carrier.
 * @return Array The status, the message, the user and the role.
 */
function listAccess( String $carrierStr, Array $headersArr, String $carrierCnf='tsusrs' ): Array {
	$axsConf = Array('needed'=>true, 'sessby'=>$carrierStr, 'sesscf'=>$carrierCnf, 'authby'=>'usrlst', 'authcf'=>'tsusrs');
	$axsRslt = (new Checker( $axsConf, fakeReqst($headersArr, Array('REMOTE_ADDR'=>'203.0.113.7')) ))->verifyAxs();
	return Array($axsRslt->readValue('statCode'), $axsRslt->readValue('statMssg'), $axsRslt->readValue('user'), $axsRslt->readValue('role'));
}
checkSame('Checker with UserList: Basic access', listAccess('hbasic', Array('Authorization'=>'Basic '.base64_encode('anna:pw-anna'))), Array(200, '', 'anna', 'manage'));
checkSame('Checker with UserList: Basic access of a locked user', listAccess('hbasic', Array('Authorization'=>'Basic '.base64_encode('dave:pw-dave'))), Array(401, 'Invalid credentials', '', null));
checkSame('Checker with UserList: Basic access is closed to a user with a secret', listAccess('hbasic', Array('Authorization'=>'Basic '.base64_encode('emma:pw-emma'))), Array(401, 'Verification code required', '', null));
checkSame('Checker with UserList: personal token in the API key header', listAccess('header', Array('X-Api-Key'=>'list-test-token')), Array(200, '', 'anna', 'manage'));
checkSame('Checker with UserList: wrong personal token', listAccess('header', Array('X-Api-Key'=>'another-token')), Array(401, 'Invalid API key', '', null));
$jwtSess = new JWT( $jwtConf );
checkSame('Checker with UserList: the role of a token is the one of the file', listAccess('jtoken', Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'anna', 'role'=>'member'))), 'tstokn'), Array(200, '', 'anna', 'manage'));
checkSame('Checker with UserList: the token of a locked user is refused', listAccess('jtoken', Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'dave', 'role'=>'system'))), 'tstokn'), Array(401, 'Account not active', '', null));
checkSame('Checker with UserList: the token of a user no longer in the file is refused', listAccess('jtoken', Array('Authorization'=>'Bearer '.$jwtSess->issueTokn(Array('user'=>'zed', 'role'=>'manage'))), 'tstokn')[0], 401);

// The limit by address still applies : counters in a folder of the project, removed at the end
$prjFldr = 'temp'.DS.'limits_test_'.getmypid();
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TSUSRS'=>Array('usrfle'=>$fxtPath), 'LIMITS'=>Array('maxtry'=>2, 'holdtm'=>600, 'folder'=>$prjFldr))) );
$wrngHed = Array('Authorization'=>'Basic '.base64_encode('anna:nope'));
$rghtHed = Array('Authorization'=>'Basic '.base64_encode('anna:pw-anna'));
checkSame('Limit by address: an account not active is counted, as a wrong password is', Array(listAccess('hbasic', Array('Authorization'=>'Basic '.base64_encode('cleo:pw-cleo')))[1], listAccess('hbasic', Array('Authorization'=>'Basic '.base64_encode('cleo:pw-cleo')))[1], listAccess('hbasic', $rghtHed)[0]), Array('Invalid credentials', 'Invalid credentials', 429));
dropFoldr(TOP_DIR.DS.$prjFldr);
checkSame('Limit by address: two wrong passwords are told as such', Array(listAccess('hbasic', $wrngHed)[1], listAccess('hbasic', $wrngHed)[1]), Array('Invalid credentials', 'Invalid credentials'));
checkSame('Limit by address: the address is then held, even with the right password', Array(listAccess('hbasic', $rghtHed)[0], listAccess('hbasic', $rghtHed)[1]), Array(429, 'Too many attempts'));
dropFoldr(TOP_DIR.DS.$prjFldr);

endChecks();
