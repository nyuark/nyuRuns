<?php

// Access\Auth\UserBase : the users of the native userbase, managed in a
// SQLite table built from the packaged schema (conf/schema/users.sqlitedb.sql).
//
//   user 1  anna   manage  active
//   user 2  ben    member  active
//   user 3  cleo   member  pendng
//   user 4  dave   system  locked
//
// Passwords are pass-<name>-1.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('UserBase', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Access\Auth\Native;
use nyu\Access\Auth\UserBase;
use nyu\Tools\Logger;

$sqlLink = new PDO('sqlite:'.inTemporaryDir('userbase.sqlite'));
$sqlLink->exec( strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlitedb.sql')) );
$usrStmt = $sqlLink->prepare("INSERT INTO users (id_user, systemName, passwdHash, accessRole, userStatus, createDate) VALUES (?,?,?,?,?,'2026-10-01 09:00:00')");
foreach( Array(Array(1,'anna','sysadmin','approved'), Array(2,'ben','internal','approved'), Array(3,'cleo','internal','awaiting'), Array(4,'dave','operator','disabled')) as $usrRow ){
	$usrStmt->execute( Array($usrRow[0], $usrRow[1], password_hash('pass-'.$usrRow[1].'-1', PASSWORD_BCRYPT, Array('cost'=>4)), $usrRow[2], $usrRow[3]) );
}
$usrStmt = null;
switchToSqlite( inTemporaryDir('userbase.sqlite') );

/**
 * Reads one field of a user, straight from the table.
 *
 * @param PDO    $sqliteLink Connection to the test database.
 * @param String $userString Login name of the user.
 * @param String $fieldNamed Field to read.
 * @return mixed Its value, false when there is no such user.
 */
function fieldOf( PDO $sqliteLink, String $userString, String $fieldNamed ): Mixed {
	$fldStmt = $sqliteLink->prepare('SELECT '.$fieldNamed.' FROM users WHERE systemName=?');
	$fldStmt->execute( Array($userString) );
	return $fldStmt->fetchColumn();
}

/**
 * Runs an operation and gives its result with the refusal it left.
 *
 * @param UserBase $usersBase The manager of the userbase.
 * @param String   $methodName Operation to run.
 * @param Array    $methodArgs Its arguments.
 * @return mixed The result, the status and the message, or a 'THROWN ...' string.
 */
function operates( UserBase $usersBase, String $methodName, Array $methodArgs ): Mixed {
	return runCatchThrown(function() use ($usersBase, $methodName, $methodArgs){
		return Array( $usersBase->$methodName(...$methodArgs), $usersBase->lastStateCount(), $usersBase->finalErrorText() );
	});
}

/**
 * Reads the access log written since the previous call.
 *
 * @return String The new lines.
 */
function readLogs(): String {
	static $readLen = 0;
	clearstatcache();
	$logText = strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log'));
	$newText = substr($logText, $readLen);
	$readLen = strlen($logText);
	return $newText;
}

Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs')) );
Logger::setCurrentUser( 'anna', '192.0.2.7' );
$usrBase = new UserBase( Array() );
$natAuth = new Native( Array() );

// 1. enrolUser : a pending user, without a password, with an activation code
$emmaCod = $usrBase->enrolUsrString('emma');
checkPregMatch('enrol: the code is 10 characters that are not taken for one another, as XXXX-XXXX-XX', $emmaCod, '/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D');
checkIdentical('enrol: pending, member by default, no password', Array(fieldOf($sqlLink, 'emma', 'userStatus'), fieldOf($sqlLink, 'emma', 'accessRole'), fieldOf($sqlLink, 'emma', 'passwdHash')), Array('awaiting', 'internal', '!'));
checkIdentical('enrol: only the hash of the code is stored, without its dashes', fieldOf($sqlLink, 'emma', 'actvtnCode'), hash('sha256', str_replace('-', '', strval($emmaCod))));
checkIdentical('enrol: the code handed over lasts 300 minutes', abs(strtotime(strval(fieldOf($sqlLink, 'emma', 'codeExpiry'))) - (time()+300*60))<=5, true);
checkPregMatch('enrol: the creation date is given by the database', fieldOf($sqlLink, 'emma', 'createDate'), '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/');
checkPregMatch('enrol: logged as a notice, signed', readLogs(), '/\[NOTICE\] User "emma" created \(internal\) by user "anna" from 192\.0\.2\.7/');
checkIdentical('enrol: a pending user cannot log in, whatever it gives', Array($natAuth->checkSecretArr(Array('user'=>'emma', 'pass'=>'!')), $natAuth->checkSecretArr(Array('user'=>'emma', 'pass'=>'')), $natAuth->checkSecretArr(Array('user'=>'emma', 'pass'=>strval($emmaCod)))), Array(false, false, false));
checkPregMatch('enrol: with a role', $usrBase->enrolUsrString('Fred.Opr-2', 'operator'), '/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D');
checkIdentical('enrol: the role is stored', fieldOf($sqlLink, 'Fred.Opr-2', 'accessRole'), 'operator');
checkIdentical('enrol: two codes are never the same', $emmaCod===$usrBase->enrolUsrString('gina'), false);
readLogs();
checkIdentical('enrol: name taken', operates($usrBase, 'enrolUsrString', Array('ben')), Array(false, 409, 'User name already taken'));
checkIdentical('enrol: name taken, in another case', operates($usrBase, 'enrolUsrString', Array('BEN')), Array(false, 409, 'User name already taken'));
foreach( Array('too short'=>'ab', 'too long'=>str_repeat('a', 33), 'starting with a digit'=>'1abc', 'with a space'=>'ab cd', 'with a quote'=>"ab'cd", 'with a line break'=>"abcd\n", 'empty'=>'', 'root'=>'root', 'Admin'=>'Admin') as $whyBad=>$badNme ){
	checkIdentical('enrol: name refused, '.$whyBad, operates($usrBase, 'enrolUsrString', Array($badNme)), Array(false, 400, 'Invalid user name'));
}
checkIdentical('enrol: unknown role', operates($usrBase, 'enrolUsrString', Array('henry', 'owner')), Array(false, 400, 'Invalid role'));
checkIdentical('enrol: a refused creation writes nothing', Array(fieldOf($sqlLink, 'henry', 'systemName'), readLogs()), Array(false, ''));
checkIdentical('enrol: on a missing table', operates(new UserBase(Array('useTable'=>'nousers')), 'enrolUsrString', Array('henry')), Array(false, 500, 'Credential store unavailable'));

// 2. actvtUser : the code, once, gives the password and the active status
Logger::setCurrentUser( '', '192.0.2.9' );
checkIdentical('activate: wrong code', operates($usrBase, 'activateUsrFlg', Array('emma', str_repeat('0', 32), 'pass-emma-1')), Array(false, 401, 'Invalid activation'));
checkIdentical('activate: a wrong code counts against the address', $usrBase->hasWrongSecret(), true);
checkPregMatch('activate: the refusal is logged as a warning', readLogs(), '/\[WARNING\] Activation refused for user "emma" from 192\.0\.2\.9/');
checkIdentical('activate: empty code', operates($usrBase, 'activateUsrFlg', Array('emma', '', 'pass-emma-1')), Array(false, 401, 'Invalid activation'));
checkIdentical('activate: the stored hash is not a code', operates($usrBase, 'activateUsrFlg', Array('emma', hash('sha256', strval($emmaCod)), 'pass-emma-1')), Array(false, 401, 'Invalid activation'));
checkIdentical('activate: the code of another user', operates($usrBase, 'activateUsrFlg', Array('gina', strval($emmaCod), 'pass-gina-1')), Array(false, 401, 'Invalid activation'));
checkIdentical('activate: unknown user, same refusal', operates($usrBase, 'activateUsrFlg', Array('zed', strval($emmaCod), 'pass-zed-01')), Array(false, 401, 'Invalid activation'));
checkIdentical('activate: a password breaking a rule', operates($usrBase, 'activateUsrFlg', Array('emma', strval($emmaCod), 'short')), Array(false, 400, 'Password too short: 10 characters at least'));
checkIdentical('activate: a rule broken does not count against the address', $usrBase->hasWrongSecret(), false);
checkIdentical('activate: still pending after the refusals', fieldOf($sqlLink, 'emma', 'userStatus'), 'awaiting');
readLogs();
checkIdentical('activate: the right code', operates($usrBase, 'activateUsrFlg', Array('emma', strval($emmaCod), 'pass-emma-1')), Array(true, 200, ''));
checkIdentical('activate: active, the code cleared', Array(fieldOf($sqlLink, 'emma', 'userStatus'), fieldOf($sqlLink, 'emma', 'actvtnCode')), Array('approved', null));
checkPregMatch('activate: logged as a notice, which production writes', readLogs(), '/\[NOTICE\] User "emma" activated from 192\.0\.2\.9/');
checkIdentical('activate: the user logs in with its password', $natAuth->checkSecretArr(Array('user'=>'emma', 'pass'=>'pass-emma-1'))['userRole'] ?? null, 'internal');
checkIdentical('activate: the code is used once', operates($usrBase, 'activateUsrFlg', Array('emma', strval($emmaCod), 'pass-emma-2')), Array(false, 401, 'Invalid activation'));
checkIdentical('activate: the password of the first use stays', $natAuth->checkSecretArr(Array('user'=>'emma', 'pass'=>'pass-emma-1'))['userName'] ?? null, 'emma');
checkIdentical('activate: a pending user without a code stays pending', Array(operates($usrBase, 'activateUsrFlg', Array('cleo', '', 'pass-cleo-2')), fieldOf($sqlLink, 'cleo', 'userStatus')), Array(Array(false, 401, 'Invalid activation'), 'awaiting'));
checkIdentical('activate: a locked or active user is not activated', Array($usrBase->activateUsrFlg('dave', '', 'pass-dave-2'), $usrBase->activateUsrFlg('ben', '', 'pass-ben-22')), Array(false, false));
checkIdentical('activate: on a missing table', operates(new UserBase(Array('useTable'=>'nousers')), 'activateUsrFlg', Array('emma', strval($emmaCod), 'pass-emma-1')), Array(false, 500, 'Credential store unavailable'));

// 3. chgPasswd : the current password is the proof
Logger::setCurrentUser( 'ben', '192.0.2.9' );
readLogs();
checkIdentical('password: wrong current one', operates($usrBase, 'editPasswdFlag', Array('ben', 'nope', 'pass-ben-22')), Array(false, 401, 'Invalid credentials'));
checkIdentical('password: a wrong current one is a failed attempt', Array(intval(fieldOf($sqlLink, 'ben', 'failsCount')), $usrBase->hasWrongSecret()), Array(1, true));
checkPregMatch('password: the refusal is logged as a warning', readLogs(), '/\[WARNING\] Password change refused for user "ben": wrong current password by user "ben" from 192\.0\.2\.9/');
checkIdentical('password: a new one breaking a rule', operates($usrBase, 'editPasswdFlag', Array('ben', 'pass-ben-1', 'short')), Array(false, 400, 'Password too short: 10 characters at least'));
checkIdentical('password: the same as the current one', operates($usrBase, 'editPasswdFlag', Array('ben', 'pass-ben-1', 'pass-ben-1')), Array(false, 400, 'Password identical to the current one'));
checkIdentical('password: changed', operates($usrBase, 'editPasswdFlag', Array('ben', 'pass-ben-1', 'pass-ben-22')), Array(true, 200, ''));
checkPregMatch('password: logged as a notice, without any password', $logText = readLogs(), '/\[NOTICE\] Password changed for user "ben" by user "ben"/');
checkIdentical('password: no password in the log', preg_match('/pass-ben/', $logText), 0);
checkIdentical('password: the failures are forgotten by the change', intval(fieldOf($sqlLink, 'ben', 'failsCount')), 0);
checkIdentical('password: the new one logs in, the former does not', Array($natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pass-ben-22'))['userName'] ?? null, $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pass-ben-1'))), Array('ben', false));
$sqlLink->exec("UPDATE users SET failsCount=0, failedTime=NULL");
$sqlLink->exec("UPDATE users SET bearerHash='tk', twoFactKey='2f' WHERE systemName='ben'");
$usrBase->editPasswdFlag('ben', 'pass-ben-22', 'pass-ben-1');
checkIdentical('password: the personal token is taken away, the 2FA secret kept', Array(fieldOf($sqlLink, 'ben', 'bearerHash'), fieldOf($sqlLink, 'ben', 'twoFactKey')), Array(null, '2f'));
$sqlLink->exec("UPDATE users SET bearerHash=NULL, twoFactKey=NULL WHERE systemName='ben'");
checkIdentical('password: a locked user', operates($usrBase, 'editPasswdFlag', Array('dave', 'pass-dave-1', 'pass-dave-2')), Array(false, 401, 'Account not active'));
checkIdentical('password: a pending user', operates($usrBase, 'editPasswdFlag', Array('cleo', 'pass-cleo-1', 'pass-cleo-2')), Array(false, 401, 'Account not active'));
checkIdentical('password: an unknown user', operates($usrBase, 'editPasswdFlag', Array('zed', 'pass-zed-01', 'pass-zed-02')), Array(false, 401, 'Account not active'));
$sqlLink->exec("UPDATE users SET failsCount=5, failedTime='".date('Y-m-d H:i:s')."' WHERE systemName='ben'");
checkIdentical('password: a held user is refused with the right password', operates($usrBase, 'editPasswdFlag', Array('ben', 'pass-ben-1', 'pass-ben-22')), Array(false, 401, 'Invalid credentials'));
checkIdentical('password: and keeps its password', password_verify('pass-ben-1', strval(fieldOf($sqlLink, 'ben', 'passwdHash'))), true);
$sqlLink->exec("UPDATE users SET failsCount=0, failedTime=NULL");

// 4. setStatus : lock and unlock
Logger::setCurrentUser( 'anna', '192.0.2.7' );
readLogs();
checkIdentical('lock: an active user', operates($usrBase, 'saveStatusFlag', Array('ben', 'disabled')), Array(true, 200, ''));
checkPregMatch('lock: logged as a notice', readLogs(), '/\[NOTICE\] User "ben" locked by user "anna" from 192\.0\.2\.7/');
checkIdentical('lock: the user no longer logs in', Array($natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pass-ben-1')), $natAuth->finalErrorText(), $natAuth->usrIdentityArr('ben')), Array(false, 'Invalid credentials', false));
checkIdentical('lock: already locked', operates($usrBase, 'saveStatusFlag', Array('ben', 'disabled')), Array(false, 409, 'User already disabled'));
$sqlLink->exec("UPDATE users SET failsCount=3, failedTime='".date('Y-m-d H:i:s')."' WHERE systemName='ben'");
checkIdentical('unlock: a locked user', operates($usrBase, 'saveStatusFlag', Array('ben', 'approved')), Array(true, 200, ''));
checkPregMatch('unlock: logged as a notice', readLogs(), '/\[NOTICE\] User "ben" unlocked by user "anna"/');
checkIdentical('unlock: the failures are forgotten', Array(intval(fieldOf($sqlLink, 'ben', 'failsCount')), fieldOf($sqlLink, 'ben', 'failedTime')), Array(0, null));
checkIdentical('unlock: the user logs in again', $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pass-ben-1'))['userName'] ?? null, 'ben');
checkIdentical('unlock: already active', operates($usrBase, 'saveStatusFlag', Array('ben', 'approved')), Array(false, 409, 'User already approved'));
checkIdentical('lock: a pending user', operates($usrBase, 'saveStatusFlag', Array('cleo', 'disabled')), Array(false, 409, 'A pending user is neither locked nor unlocked'));
checkIdentical('unlock: a pending user is not activated that way', Array(operates($usrBase, 'saveStatusFlag', Array('cleo', 'approved')), fieldOf($sqlLink, 'cleo', 'userStatus')), Array(Array(false, 409, 'A pending user is neither locked nor unlocked'), 'awaiting'));
checkIdentical('lock: an unknown user', operates($usrBase, 'saveStatusFlag', Array('zed', 'disabled')), Array(false, 404, 'User not found'));
checkIdentical('lock: a status that is not one of the two', Array(operates($usrBase, 'saveStatusFlag', Array('ben', 'awaiting')), operates($usrBase, 'saveStatusFlag', Array('ben', 'online'))), Array(Array(false, 400, 'Invalid status'), Array(false, 400, 'Invalid status')));
checkIdentical('lock: the last active manager', Array(operates($usrBase, 'saveStatusFlag', Array('anna', 'disabled')), fieldOf($sqlLink, 'anna', 'userStatus')), Array(Array(false, 409, 'The last active manager cannot be locked'), 'approved'));
checkIdentical('lock: a name holding a quote', operates($usrBase, 'saveStatusFlag', Array("ben' OR '1'='1", 'disabled')), Array(false, 404, 'User not found'));

// 5. changRole
readLogs();
checkIdentical('role: changed', operates($usrBase, 'changeRoleFlag', Array('ben', 'operator')), Array(true, 200, ''));
checkPregMatch('role: logged as a notice, with both roles', readLogs(), '/\[NOTICE\] Role of user "ben" changed from internal to operator by user "anna"/');
checkIdentical('role: the next login carries it', $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pass-ben-1'))['userRole'] ?? null, 'operator');
checkIdentical('role: already that one', operates($usrBase, 'changeRoleFlag', Array('ben', 'operator')), Array(false, 409, 'User already of that role'));
checkIdentical('role: unknown role', Array(operates($usrBase, 'changeRoleFlag', Array('ben', 'owner')), operates($usrBase, 'changeRoleFlag', Array('ben', ''))), Array(Array(false, 400, 'Invalid role'), Array(false, 400, 'Invalid role')));
checkIdentical('role: unknown user', operates($usrBase, 'changeRoleFlag', Array('zed', 'operator')), Array(false, 404, 'User not found'));
checkIdentical('role: the last active manager keeps its role', Array(operates($usrBase, 'changeRoleFlag', Array('anna', 'internal')), fieldOf($sqlLink, 'anna', 'accessRole')), Array(Array(false, 409, 'The last active manager cannot be given another role'), 'sysadmin'));
checkIdentical('role: a pending or locked user can be given one', Array($usrBase->changeRoleFlag('cleo', 'operator'), $usrBase->changeRoleFlag('dave', 'sysadmin')), Array(true, true));
checkIdentical('role: a locked manager does not make the active one less the last', operates($usrBase, 'changeRoleFlag', Array('anna', 'internal')), Array(false, 409, 'The last active manager cannot be given another role'));
checkIdentical('role: with a second active manager, the first can be demoted', Array($usrBase->changeRoleFlag('ben', 'sysadmin'), $usrBase->changeRoleFlag('anna', 'operator'), $usrBase->changeRoleFlag('anna', 'sysadmin')), Array(true, true, true));
$usrBase->changeRoleFlag('ben', 'internal');
$usrBase->changeRoleFlag('cleo', 'internal');
$usrBase->changeRoleFlag('dave', 'operator');

// 7. listUsers
$allList = $usrBase->listUsersNamed();
checkIdentical('list: every user, by name', array_column((array)$allList, 'systemName'), Array('Fred.Opr-2', 'anna', 'ben', 'cleo', 'dave', 'emma', 'gina'));
checkIdentical('list: only what may be told', array_keys($allList[1] ?? Array()), Array('id_user', 'systemName', 'accessRole', 'userStatus', 'contactEml', 'userLocale', 'signupDate', 'createDate', 'accessDate'));
checkIdentical('list: a row', $allList[1] ?? null, Array('id_user'=>1, 'systemName'=>'anna', 'accessRole'=>'sysadmin', 'userStatus'=>'approved', 'contactEml'=>null, 'userLocale'=>null, 'signupDate'=>null, 'createDate'=>'2026-10-01 09:00:00', 'accessDate'=>null));
checkIdentical('list: by status', array_column((array)$usrBase->listUsersNamed(Array('userStatus'=>'awaiting')), 'systemName'), Array('Fred.Opr-2', 'cleo', 'gina'));
checkIdentical('list: by role', array_column((array)$usrBase->listUsersNamed(Array('accessRole'=>'operator')), 'systemName'), Array('Fred.Opr-2', 'dave'));
checkIdentical('list: by status and role', array_column((array)$usrBase->listUsersNamed(Array('userStatus'=>'approved', 'accessRole'=>'internal')), 'systemName'), Array('ben', 'emma'));
checkIdentical('list: empty filters are left out', count((array)$usrBase->listUsersNamed(Array('userStatus'=>'', 'accessRole'=>null))), 7);
checkIdentical('list: nobody', $usrBase->listUsersNamed(Array('userStatus'=>'disabled', 'accessRole'=>'sysadmin')), Array());
checkIdentical('list: limit and offset', array_column((array)$usrBase->listUsersNamed(Array(), 2, 1), 'systemName'), Array('anna', 'ben'));
checkIdentical('list: an offset beyond the end', $usrBase->listUsersNamed(Array(), 2, 50), Array());
checkIdentical('list: a filter that is not a status', operates($usrBase, 'listUsersNamed', Array(Array('userStatus'=>"active' OR '1'='1"))), Array(false, 400, 'Invalid filter'));
checkIdentical('list: a filter given as an array', operates($usrBase, 'listUsersNamed', Array(Array('accessRole'=>Array('internal')))), Array(false, 400, 'Invalid filter'));
checkIdentical('list: another field is not a filter', count((array)$usrBase->listUsersNamed(Array('passwdHash'=>'!'))), 7);
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>inTemporaryDir('userbase.sqlite')),
	'system'=>Array('LST_ROWS'=>3)
) );
checkIdentical('list: the default number of rows is LST_ROWS', count((array)$usrBase->listUsersNamed()), 3);
switchToSqlite( inTemporaryDir('userbase.sqlite') );
checkIdentical('list: on a missing table', operates(new UserBase(Array('useTable'=>'nousers')), 'listUsersNamed', Array()), Array(false, 500, 'Credential store unavailable'));

// 8. userInfos
$sqlLink->exec("UPDATE users SET bearerHash='tk', twoFactKey='2f' WHERE systemName='ben'");
checkIdentical('read: one user, without hash, code, token or secret', $usrBase->userInfosArray('ben'), Array('id_user'=>2, 'systemName'=>'ben', 'accessRole'=>'internal', 'userStatus'=>'approved', 'contactEml'=>null, 'userLocale'=>null, 'signupDate'=>null, 'createDate'=>'2026-10-01 09:00:00', 'accessDate'=>null));
$sqlLink->exec("UPDATE users SET twoFactPos=58000000 WHERE systemName='ben'");
checkIdentical('2FA: the secret is taken away with the step of its last code', Array($usrBase->clearTwoFactor('ben'), $sqlLink->query("SELECT twoFactKey, twoFactPos FROM users WHERE systemName='ben'")->fetch(PDO::FETCH_NUM)), Array(true, Array(null, null)));
checkIdentical('2FA: nothing to take away from an unknown user', operates($usrBase, 'clearTwoFactor', Array('zed')), Array(false, 404, 'User not found'));
checkIdentical('read: a pending user', $usrBase->userInfosArray('gina')['userStatus'] ?? null, 'awaiting');
checkIdentical('read: unknown user', operates($usrBase, 'userInfosArray', Array('zed')), Array(false, 404, 'User not found'));
checkIdentical('read: no name', operates($usrBase, 'userInfosArray', Array('')), Array(false, 404, 'User not found'));
checkIdentical('read: the name is matched with its case', operates($usrBase, 'userInfosArray', Array('BEN')), Array(false, 404, 'User not found'));
checkIdentical('read: on a missing table', operates(new UserBase(Array('useTable'=>'nousers')), 'userInfosArray', Array('ben')), Array(false, 500, 'Credential store unavailable'));

// 9. resetUser : back to pending, with a new code
readLogs();
$benCode = $usrBase->resetUsrString('ben');
checkPregMatch('reset: gives a new code', $benCode, '/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D');
checkIdentical('reset: pending, no password, no token, role kept', Array(fieldOf($sqlLink, 'ben', 'userStatus'), fieldOf($sqlLink, 'ben', 'passwdHash'), fieldOf($sqlLink, 'ben', 'bearerHash'), fieldOf($sqlLink, 'ben', 'accessRole')), Array('awaiting', '!', null, 'internal'));
checkPregMatch('reset: logged as a notice, without the code', $logText = readLogs(), '/\[NOTICE\] User "ben" reset by user "anna"/');
checkIdentical('reset: no code in the log', strpos($logText, strval($benCode)), false);
checkIdentical('reset: the former password no longer logs in', $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pass-ben-1')), false);
$nextCod = $usrBase->resetUsrString('ben');
checkIdentical('reset: a pending user gets a new code, the previous one is void', Array($usrBase->activateUsrFlg('ben', strval($benCode), 'pass-ben-33'), $usrBase->activateUsrFlg('ben', strval($nextCod), 'pass-ben-33')), Array(false, true));
checkIdentical('reset: the user is back with its new password', $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pass-ben-33'))['userName'] ?? null, 'ben');
checkIdentical('reset: a locked user', Array(operates($usrBase, 'resetUsrString', Array('dave')), fieldOf($sqlLink, 'dave', 'userStatus')), Array(Array(false, 409, 'A locked user is unlocked before it is reset'), 'disabled'));
checkIdentical('reset: the last active manager', Array(operates($usrBase, 'resetUsrString', Array('anna')), fieldOf($sqlLink, 'anna', 'userStatus')), Array(Array(false, 409, 'The last active manager cannot be reset'), 'approved'));
checkIdentical('reset: unknown user', operates($usrBase, 'resetUsrString', Array('zed')), Array(false, 404, 'User not found'));

// 6. removUser
readLogs();
checkIdentical('remove: a user', Array(operates($usrBase, 'removeUserFlag', Array('gina')), fieldOf($sqlLink, 'gina', 'systemName')), Array(Array(true, 200, ''), false));
checkPregMatch('remove: logged as a notice, with the role it had', readLogs(), '/\[NOTICE\] User "gina" removed \(internal\) by user "anna" from 192\.0\.2\.7/');
checkIdentical('remove: already gone', operates($usrBase, 'removeUserFlag', Array('gina')), Array(false, 404, 'User not found'));
checkIdentical('remove: a locked user', $usrBase->removeUserFlag('dave'), true);
checkIdentical('remove: the last active manager', Array(operates($usrBase, 'removeUserFlag', Array('anna')), fieldOf($sqlLink, 'anna', 'systemName')), Array(Array(false, 409, 'The last active manager cannot be removed'), 'anna'));
checkIdentical('remove: a name holding a quote removes nobody', Array($usrBase->removeUserFlag("x' OR '1'='1"), count((array)$usrBase->listUsersNamed())), Array(false, 5));
checkIdentical('remove: the name is free again', is_string($usrBase->enrolUsrString('gina')), true);
checkIdentical('remove: on a missing table', operates(new UserBase(Array('useTable'=>'nousers')), 'removeUserFlag', Array('ben')), Array(false, 500, 'Credential store unavailable'));

// 10. Addresses : given at the creation or later, in lower case, one account each
readLogs();
$hanaCod = $usrBase->enrolUsrString('hana', '', 'Hana@Example.CH');
checkIdentical('address: given at the creation, in lower case', Array(is_string($hanaCod), fieldOf($sqlLink, 'hana', 'contactEml')), Array(true, 'hana@example.ch'));
checkIdentical('address: one account each, whatever its case', operates($usrBase, 'enrolUsrString', Array('hugo', '', 'HANA@example.ch')), Array(false, 409, 'Mail address already used'));
checkIdentical('address: one that is not', Array(operates($usrBase, 'enrolUsrString', Array('hugo', '', 'hugo@')), operates($usrBase, 'enrolUsrString', Array('hugo', '', "hugo@example.ch\r\nBcc: x@y.z"))), Array(Array(false, 400, 'Invalid mail address'), Array(false, 400, 'Invalid mail address')));
checkIdentical('address: an empty one is none', Array(is_string($usrBase->enrolUsrString('hugo', '', '', 0)), fieldOf($sqlLink, 'hugo', 'contactEml'), fieldOf($sqlLink, 'hugo', 'codeExpiry')), Array(true, null, null));
checkIdentical('address: given later', Array(operates($usrBase, 'changeMailFlag', Array('ben', ' Ben@Example.ch ')), fieldOf($sqlLink, 'ben', 'contactEml')), Array(Array(true, 200, ''), 'ben@example.ch'));
checkIdentical('address: given again, the same', operates($usrBase, 'changeMailFlag', Array('ben', 'ben@example.ch')), Array(true, 200, ''));
checkIdentical('address: held by another user', operates($usrBase, 'changeMailFlag', Array('ben', 'hana@example.ch')), Array(false, 409, 'Mail address already used'));
checkIdentical('address: not one, unknown user', Array(operates($usrBase, 'changeMailFlag', Array('ben', 'not an address')), operates($usrBase, 'changeMailFlag', Array('zed', 'zed@example.ch'))), Array(Array(false, 400, 'Invalid mail address'), Array(false, 404, 'User not found')));
checkIdentical('address: taken away', Array($usrBase->changeMailFlag('hugo', 'hugo@example.ch'), $usrBase->changeMailFlag('hugo', null), fieldOf($sqlLink, 'hugo', 'contactEml')), Array(true, true, null));
checkPregMatch('address: logged as a notice, without the address', $logText = readLogs(), '/\[NOTICE\] Address of user "ben" set by user "anna"/');
checkIdentical('address: never in the log', preg_match('/@example\.ch/i', $logText), 0);

// 11. Codes : read whatever their case and dashes, until their end; those of 0.9 still read
checkIdentical('code: in lower case, without its dashes', $usrBase->activateUsrFlg('hana', strtolower(str_replace('-', ' ', strval($hanaCod))), 'pass-hana-1'), true);
$hugoCod = strval($usrBase->resetUsrString('hugo'));
$sqlLink->exec("UPDATE users SET codeExpiry='".date('Y-m-d H:i:s', time()-60)."' WHERE systemName='hugo'");
checkIdentical('code: refused once ended, as a wrong one', Array(operates($usrBase, 'activateUsrFlg', Array('hugo', $hugoCod, 'pass-hugo-1')), fieldOf($sqlLink, 'hugo', 'userStatus')), Array(Array(false, 401, 'Invalid activation'), 'awaiting'));
$hugoCod = strval($usrBase->resetUsrString('hugo', 0));
checkIdentical('code: without an end', Array(fieldOf($sqlLink, 'hugo', 'codeExpiry'), $usrBase->activateUsrFlg('hugo', $hugoCod, 'pass-hugo-1')), Array(null, true));
$sqlLink->exec("INSERT INTO users (systemName, passwdHash, userStatus, actvtnCode) VALUES ('mona', '!', 'awaiting', '".hash('sha256', 'abcdef0123456789abcdef0123456789')."')");
checkIdentical('code: one made by 0.9', $usrBase->activateUsrFlg('mona', 'abcdef0123456789abcdef0123456789', 'pass-mona-1'), true);

// 12. signsUser : pending with the address, then in review, then approved
$sgnBase = new UserBase( Array('codeMins'=>30) );
checkIdentical('codeMins: the minutes of a code sent by mail', Array($usrBase->mailedForCount(), $sgnBase->mailedForCount()), Array(15, 30));
readLogs();
Logger::setCurrentUser( '', '192.0.2.11' );
$ivanCod = $sgnBase->signsUsrString('ivan', 'Ivan@Example.ch');
checkPregMatch('sign-up: a code', $ivanCod, '/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D');
checkIdentical('sign-up: pending, a member, with its address and the time of the sign-up', Array(fieldOf($sqlLink, 'ivan', 'userStatus'), fieldOf($sqlLink, 'ivan', 'accessRole'), fieldOf($sqlLink, 'ivan', 'contactEml'), fieldOf($sqlLink, 'ivan', 'passwdHash'), abs(strtotime(strval(fieldOf($sqlLink, 'ivan', 'signupDate'))) - time())<=5), Array('awaiting', 'internal', 'ivan@example.ch', '!', true));
checkIdentical('sign-up: the code lasts the minutes of codeMins', abs(strtotime(strval(fieldOf($sqlLink, 'ivan', 'codeExpiry'))) - (time()+30*60))<=5, true);
checkPregMatch('sign-up: logged as a notice', readLogs(), '/\[NOTICE\] User "ivan" signed up from 192\.0\.2\.11/');
$usrNmbr = count((array)$usrBase->listUsersNamed());
checkIdentical('sign-up: a name taken, whatever its case, is not a refusal', operates($sgnBase, 'signsUsrString', Array('IVAN', 'other@example.ch')), Array('', 200, ''));
checkIdentical('sign-up: nor is an address taken', operates($sgnBase, 'signsUsrString', Array('jack', 'BEN@example.ch')), Array('', 200, ''));
checkIdentical('sign-up: neither writes anything', count((array)$usrBase->listUsersNamed()), $usrNmbr);
checkPregMatch('sign-up: a name or an address taken is logged as a notice', readLogs(), '/\[NOTICE\] Sign-up as "IVAN": the name or the address is taken from 192\.0\.2\.11/');
checkIdentical('sign-up: names and addresses that are not', Array(operates($sgnBase, 'signsUsrString', Array('admin', 'jack@example.ch')), operates($sgnBase, 'signsUsrString', Array('jack', 'jack'))), Array(Array(false, 400, 'Invalid user name'), Array(false, 400, 'Invalid mail address')));
checkIdentical('sign-up: confirmed with its code, it awaits review', Array($usrBase->activateUsrFlg('ivan', strval($ivanCod), 'pass-ivan-1'), fieldOf($sqlLink, 'ivan', 'userStatus')), Array(true, 'inReview'));
checkPregMatch('sign-up: the confirmation is logged as a notice', readLogs(), '/\[NOTICE\] User "ivan" confirmed its address, awaits review/');
checkIdentical('review: no login yet', $natAuth->checkSecretArr(Array('user'=>'ivan', 'pass'=>'pass-ivan-1')), false);
checkIdentical('review: neither locked nor unlocked', Array(operates($usrBase, 'saveStatusFlag', Array('ivan', 'approved')), operates($usrBase, 'saveStatusFlag', Array('ivan', 'disabled'))), Array(Array(false, 409, 'A user in review is approved or removed'), Array(false, 409, 'A user in review is approved or removed')));
checkIdentical('review: listed by its status', array_column((array)$usrBase->listUsersNamed(Array('userStatus'=>'inReview')), 'systemName'), Array('ivan'));
Logger::setCurrentUser( 'anna', '192.0.2.7' );
checkIdentical('approve: a user in review', Array(operates($usrBase, 'approveNewUser', Array('ivan')), fieldOf($sqlLink, 'ivan', 'userStatus'), fieldOf($sqlLink, 'ivan', 'signupDate')), Array(Array(true, 200, ''), 'approved', null));
checkPregMatch('approve: logged as a notice, signed', readLogs(), '/\[NOTICE\] User "ivan" approved by user "anna" from 192\.0\.2\.7/');
checkIdentical('approve: the user logs in', $natAuth->checkSecretArr(Array('user'=>'ivan', 'pass'=>'pass-ivan-1'))['userName'] ?? null, 'ivan');
checkIdentical('approve: only a user in review', Array(operates($usrBase, 'approveNewUser', Array('ivan')), operates($usrBase, 'approveNewUser', Array('cleo')), operates($usrBase, 'approveNewUser', Array('zed'))), Array(Array(false, 409, 'User not awaiting review'), Array(false, 409, 'User not awaiting review'), Array(false, 404, 'User not found')));
$ivanCod = strval($usrBase->resetUsrString('ivan'));
checkIdentical('approve: a reset afterwards activates without a review', Array($usrBase->activateUsrFlg('ivan', $ivanCod, 'pass-ivan-2'), fieldOf($sqlLink, 'ivan', 'userStatus')), Array(true, 'approved'));
$kateCod = strval($sgnBase->signsUsrString('kate', 'kate@example.ch'));
$sqlLink->exec("UPDATE users SET codeExpiry='".date('Y-m-d H:i:s', time()-60)."' WHERE systemName='kate'");
checkIdentical('sign-up: its code ended, it is not confirmed', operates($usrBase, 'activateUsrFlg', Array('kate', $kateCod, 'pass-kate-1')), Array(false, 401, 'Invalid activation'));
readLogs();
checkIdentical('sign-up: the next sign-up removes it, its name and address are free again', Array(is_string($sgnBase->signsUsrString('lena', 'lena@example.ch')), fieldOf($sqlLink, 'kate', 'systemName'), is_string($sgnBase->signsUsrString('kate', 'kate@example.ch')) && $sgnBase->lastStateCount()==200), Array(true, false, true));
checkPregMatch('sign-up: the removal is logged as an info', readLogs(), '/\[INFO\] 1 sign-ups not confirmed in time removed/');
$sqlLink->exec('BEGIN');
$sgnStmt = $sqlLink->prepare("INSERT INTO users (systemName, passwdHash, userStatus, contactEml, signupDate) VALUES (?, '!', 'inReview', ?, '2026-11-04 08:00:00')");
for( $idx=0; $idx<UserBase::SIGN_MAXI; $idx++ ){
	$sgnStmt->execute( Array('wait'.$idx, 'wait'.$idx.'@example.ch') );
}
$sqlLink->exec('COMMIT');
checkIdentical('sign-up: closed for a while when too many are waiting', operates($sgnBase, 'signsUsrString', Array('nina', 'nina@example.ch')), Array(false, 503, 'Sign-ups closed for now'));
checkIdentical('sign-up: the cap is the one given', Array($usrBase->waitingSignups()>=UserBase::SIGN_MAXI, operates($sgnBase, 'signsUsrString', Array('nina', 'nina@example.ch', UserBase::SIGN_MAXI+10))[1]), Array(true, 200));
$sqlLink->exec("DELETE FROM users WHERE systemName LIKE 'wait%' OR systemName='nina'");
$waitNum = $usrBase->waitingSignups();
checkIdentical('waitCount: the sign-ups not confirmed, and those in review', Array($waitNum, $usrBase->waitingSignups()), Array(intval($sqlLink->query("SELECT COUNT(*) FROM users WHERE signupDate IS NOT NULL AND userStatus IN ('awaiting', 'inReview')")->fetchColumn()), $waitNum));
checkIdentical('sign-up: refused at a lower cap', operates($sgnBase, 'signsUsrString', Array('olga', 'olga@example.ch', $waitNum)), Array(false, 503, 'Sign-ups closed for now'));
$sqlLink->exec("UPDATE users SET contactEml='anna@example.ch' WHERE systemName='anna'");
checkIdentical('managMail: the addresses of the active managers, with their language', $usrBase->manageMailList(), Array('anna@example.ch'=>null));
$sqlLink->exec("UPDATE users SET userLocale='fr' WHERE systemName='anna'");
checkIdentical('managMail: a manager with a language', $usrBase->manageMailList(), Array('anna@example.ch'=>'fr'));
$sqlLink->exec("UPDATE users SET userLocale=NULL WHERE systemName='anna'");

// 13. Languages : given at the creation, by a sign-up, at the activation, or later
readLogs();
checkIdentical('language: given at the creation, in lower case', Array(is_string($usrBase->enrolUsrString('paul', '', null, 0, 'FR_ch')), fieldOf($sqlLink, 'paul', 'userLocale')), Array(true, 'fr-ch'));
checkIdentical('language: one that is not, refused at the creation', operates($usrBase, 'enrolUsrString', Array('pete', '', null, 0, 'french')), Array(false, 400, 'Invalid language'));
checkIdentical('language: none at the creation', Array(is_string($peteCod = $usrBase->enrolUsrString('pete', '', null, 0)), fieldOf($sqlLink, 'pete', 'userLocale')), Array(true, null));
checkIdentical('language: the one of the activation, for a user who has none', Array($usrBase->activateUsrFlg('pete', strval($peteCod), 'pass-pete-1', 'de-AT'), fieldOf($sqlLink, 'pete', 'userLocale')), Array(true, 'de-at'));
$paulCod = strval($usrBase->resetUsrString('paul', 0));
checkIdentical('language: the activation keeps the one a user has', Array($usrBase->activateUsrFlg('paul', $paulCod, 'pass-paul-1', 'it'), fieldOf($sqlLink, 'paul', 'userLocale')), Array(true, 'fr-ch'));
checkIdentical('language: the one of a sign-up; one that is not a tag is left out', Array(is_string($sgnBase->signsUsrString('quinn', 'quinn@example.ch', UserBase::SIGN_MAXI, 'pt-BR')), fieldOf($sqlLink, 'quinn', 'userLocale'), is_string($sgnBase->signsUsrString('rita', 'rita@example.ch', UserBase::SIGN_MAXI, '*')), fieldOf($sqlLink, 'rita', 'userLocale')), Array(true, 'pt-br', true, null));
checkIdentical('language: given later, taken away', Array(operates($usrBase, 'changeLanguage', Array('ben', 'es')), fieldOf($sqlLink, 'ben', 'userLocale'), $usrBase->changeLanguage('ben', ''), fieldOf($sqlLink, 'ben', 'userLocale')), Array(Array(true, 200, ''), 'es', true, null));
checkIdentical('language: not one, unknown user', Array(operates($usrBase, 'changeLanguage', Array('ben', 'es_ES_x')), operates($usrBase, 'changeLanguage', Array('zed', 'es'))), Array(Array(false, 400, 'Invalid language'), Array(false, 404, 'User not found')));
checkPregMatch('language: logged as an info', readLogs(), '/\[INFO\] Language of user "ben" set to es by user "anna"/');
$usrBase->changeLanguage('ben', 'fr');
checkIdentical('language: the one of a reset asked by mail', ($usrBase->resetPassArray('ben@example.ch') ?: Array())['lang'] ?? 'none', 'fr');
checkIdentical('listUsers: by address, in lower case', array_column((array)$usrBase->listUsersNamed(Array('contactEml'=>' BEN@example.ch ')), 'userLocale', 'systemName'), Array('ben'=>'fr'));
checkIdentical('listUsers: an address that is not a text', operates($usrBase, 'listUsersNamed', Array(Array('contactEml'=>Array('x')))), Array(false, 400, 'Invalid filter'));
checkIdentical('waitCount and managMail on a missing table', Array(operates(new UserBase(Array('useTable'=>'nousers')), 'waitingSignups', Array()), operates(new UserBase(Array('useTable'=>'nousers')), 'manageMailList', Array())), Array(Array(false, 500, 'Credential store unavailable'), Array(false, 500, 'Credential store unavailable')));
$sgnStmt = null;

// 13. resetPass and recovUser : a reset asked for by the user, its password kept until it is done
readLogs();
Logger::setCurrentUser( '', '192.0.2.12' );
$rstVals = $usrBase->resetPassArray('ben');
checkIdentical('reset asked: by name, the address and a code', Array($rstVals['user'] ?? null, $rstVals['mail'] ?? null, preg_match('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D', strval($rstVals['code'] ?? ''))), Array('ben', 'ben@example.ch', 1));
checkIdentical('reset asked: the hash of the code stored, for 15 minutes, the password kept', Array(fieldOf($sqlLink, 'ben', 'recovrCode'), abs(strtotime(strval(fieldOf($sqlLink, 'ben', 'codeExpiry'))) - (time()+15*60))<=5, $natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pass-ben-33'))['userName'] ?? null), Array(hash('sha256', str_replace('-', '', strval($rstVals['code'] ?? ''))), true, 'ben'));
$scndRst = $usrBase->resetPassArray('BEN@example.CH');
checkIdentical('reset asked: by address, whatever its case; the new code replaces the former', Array($scndRst['user'] ?? null, operates($usrBase, 'recoverUserFlg', Array('ben', strval($rstVals['code'] ?? ''), 'pass-ben-44'))), Array('ben', Array(false, 401, 'Invalid code')));
checkIdentical('reset: a wrong code counts against the address', $usrBase->hasWrongSecret(), true);
checkIdentical('reset asked: unknown, without an address, not active', Array(operates($usrBase, 'resetPassArray', Array('zed')), operates($usrBase, 'resetPassArray', Array('nobody@example.ch')), operates($usrBase, 'resetPassArray', Array('emma')), operates($usrBase, 'resetPassArray', Array('kate')), operates($usrBase, 'resetPassArray', Array(''))),
	Array(Array(false, 404, 'User not found'), Array(false, 404, 'User not found'), Array(false, 409, 'No reset for this user'), Array(false, 409, 'No reset for this user'), Array(false, 404, 'User not found')));
checkIdentical('reset: a new password breaking a rule', operates($usrBase, 'recoverUserFlg', Array('ben', strval($scndRst['code'] ?? ''), 'short')), Array(false, 400, 'Password too short: 10 characters at least'));
checkIdentical('reset: the code of another user', operates($usrBase, 'recoverUserFlg', Array('ivan', strval($scndRst['code'] ?? ''), 'pass-ivan-44')), Array(false, 401, 'Invalid code'));
$sqlLink->exec("UPDATE users SET failsCount=5, failedTime='".date('Y-m-d H:i:s')."', bearerHash='tk', twoFactKey='2f' WHERE systemName='ben'");
checkIdentical('reset: done with the code', operates($usrBase, 'recoverUserFlg', Array('ben', strval($scndRst['code'] ?? ''), 'pass-ben-44')), Array(true, 200, ''));
checkIdentical('reset: the new password, the code cleared, the failures forgotten, the token taken away, the secret kept', Array(password_verify('pass-ben-44', strval(fieldOf($sqlLink, 'ben', 'passwdHash'))), fieldOf($sqlLink, 'ben', 'recovrCode'), fieldOf($sqlLink, 'ben', 'codeExpiry'), intval(fieldOf($sqlLink, 'ben', 'failsCount')), fieldOf($sqlLink, 'ben', 'bearerHash'), fieldOf($sqlLink, 'ben', 'twoFactKey')), Array(true, null, null, 0, null, '2f'));
$sqlLink->exec("UPDATE users SET bearerHash=NULL, twoFactKey=NULL WHERE systemName='ben'");
checkIdentical('reset: the code is used once', operates($usrBase, 'recoverUserFlg', Array('ben', strval($scndRst['code'] ?? ''), 'pass-ben-55')), Array(false, 401, 'Invalid code'));
$thrdRst = $usrBase->resetPassArray('ben');
$sqlLink->exec("UPDATE users SET codeExpiry='".date('Y-m-d H:i:s', time()-60)."' WHERE systemName='ben'");
checkIdentical('reset: a code ended', operates($usrBase, 'recoverUserFlg', Array('ben', strval($thrdRst['code'] ?? ''), 'pass-ben-55')), Array(false, 401, 'Invalid code'));
$logText = readLogs();
checkPregMatch('reset: asked, refused and done are logged', $logText, '/\[NOTICE\] Reset asked for user "ben" from 192\.0\.2\.12.*\[WARNING\] Password reset refused for user "ben".*\[NOTICE\] Reset asked for an unknown user.*\[NOTICE\] Password of user "ben" reset with its code/s');
checkIdentical('reset: neither a code nor an address in the log', preg_match('/'.preg_quote(strval($scndRst['code'] ?? ''), '/').'|@example/', $logText), 0);
$frthRst = $usrBase->resetPassArray('ben');
$usrBase->changeMailFlag('ben', 'ben.new@example.ch');
checkIdentical('reset: a new address forgets the code sent to the former', Array(fieldOf($sqlLink, 'ben', 'recovrCode'), operates($usrBase, 'recoverUserFlg', Array('ben', strval($frthRst['code'] ?? ''), 'pass-ben-55'))), Array(null, Array(false, 401, 'Invalid code')));
$usrBase->resetPassArray('ben');
$benCode = strval($usrBase->resetUsrString('ben'));
checkIdentical('reset by a manager: the code asked for by the user is forgotten', Array(fieldOf($sqlLink, 'ben', 'recovrCode'), $usrBase->activateUsrFlg('ben', $benCode, 'pass-ben-33')), Array(null, true));
Logger::setCurrentUser( 'anna', '192.0.2.7' );

// fromLogin : the userbase the login uses
checkIdentical('fromLogin: without a login block', UserBase::fromLoginBlock(), null);
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>inTemporaryDir('userbase.sqlite')),
	'access'=>Array('LOGINSET'=>Array('authWith'=>'htpasswd'), 'NATIVEDB'=>Array('useTable'=>'users'), 'OTHERS'=>Array('useTable'=>'nousers'))
) );
checkIdentical('fromLogin: a login on another credential check', UserBase::fromLoginBlock(), null);
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>inTemporaryDir('userbase.sqlite')),
	'access'=>Array('LOGINSET'=>Array('authWith'=>'nativeDb'), 'NATIVEDB'=>Array('useTable'=>'users', 'minChars'=>12), 'OTHERS'=>Array('useTable'=>'nousers'))
) );
$lgnBase = UserBase::fromLoginBlock();
checkIdentical('fromLogin: the native block by default', ($lgnBase!==null) ? $lgnBase->userInfosArray('anna')['accessRole'] ?? null : null, 'sysadmin');
checkIdentical('fromLogin: the block gives its password length', ($lgnBase!==null) ? operates($lgnBase, 'editPasswdFlag', Array('anna', 'pass-anna-1', 'pass-anna-2')) : null, Array(false, 400, 'Password too short: 12 characters at least'));
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>inTemporaryDir('userbase.sqlite')),
	'access'=>Array('LOGINSET'=>Array('authWith'=>'nativeDb', 'authConf'=>'others'), 'NATIVEDB'=>Array('useTable'=>'users'), 'OTHERS'=>Array('useTable'=>'nousers'))
) );
checkIdentical('fromLogin: the block authConf names', operates(UserBase::fromLoginBlock(), 'userInfosArray', Array('anna')), Array(false, 500, 'Credential store unavailable'));

Logger::plugLogsConfig( Array() );
Logger::setCurrentUser( '', '' );
$sqlLink = null;

finishChecking();
