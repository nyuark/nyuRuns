<?php

// Access\Auth\UserBase : the users of the native userbase, managed in a
// SQLite table built from the packaged schema (conf/schema/users.sqlte.sql).
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
	skipCheck('UserBase', 'pdo_sqlite is not available');
	endChecks();
}

use nyu\Access\Auth\Native;
use nyu\Access\Auth\UserBase;
use nyu\Tools\Logger;

$sqlLink = new PDO('sqlite:'.inTempDir('userbase.sqlite'));
$sqlLink->exec( strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'schema'.DS.'users.sqlte.sql')) );
$usrStmt = $sqlLink->prepare("INSERT INTO users (id_user, userName, passHash, userRole, userStat, madeDate) VALUES (?,?,?,?,?,'2026-10-01 09:00:00')");
foreach( Array(Array(1,'anna','manage','active'), Array(2,'ben','member','active'), Array(3,'cleo','member','pendng'), Array(4,'dave','system','locked')) as $usrRow ){
	$usrStmt->execute( Array($usrRow[0], $usrRow[1], password_hash('pass-'.$usrRow[1].'-1', PASSWORD_BCRYPT, Array('cost'=>4)), $usrRow[2], $usrRow[3]) );
}
$usrStmt = null;
useSqlite( inTempDir('userbase.sqlite') );

/**
 * Reads one field of a user, straight from the table.
 *
 * @param PDO    $sqliteLink Connection to the test database.
 * @param String $userString Login name of the user.
 * @param String $fieldNamed Field to read.
 * @return mixed Its value, false when there is no such user.
 */
function fieldOf( PDO $sqliteLink, String $userString, String $fieldNamed ): Mixed {
	$fldStmt = $sqliteLink->prepare('SELECT '.$fieldNamed.' FROM users WHERE userName=?');
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
	return runSafely(function() use ($usersBase, $methodName, $methodArgs){
		return Array( $usersBase->$methodName(...$methodArgs), $usersBase->lastState(), $usersBase->lastError() );
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
	$logText = strval(@file_get_contents(inTempDir('logs').DS.'access.log'));
	$newText = substr($logText, $readLen);
	$readLen = strlen($logText);
	return $newText;
}

Logger::plugSetup( Array('path'=>inTempDir('logs')) );
Logger::plugActor( 'anna', '192.0.2.7' );
$usrBase = new UserBase( Array() );
$natAuth = new Native( Array() );

// 1. enrolUser : a pending user, without a password, with an activation code
$emmaCod = $usrBase->enrolUser('emma');
checkLike('enrol: the code is 10 characters that are not taken for one another, as XXXX-XXXX-XX', $emmaCod, '/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D');
checkSame('enrol: pending, member by default, no password', Array(fieldOf($sqlLink, 'emma', 'userStat'), fieldOf($sqlLink, 'emma', 'userRole'), fieldOf($sqlLink, 'emma', 'passHash')), Array('pendng', 'member', '!'));
checkSame('enrol: only the hash of the code is stored, without its dashes', fieldOf($sqlLink, 'emma', 'actvCode'), hash('sha256', str_replace('-', '', strval($emmaCod))));
checkSame('enrol: the code handed over lasts 300 minutes', abs(strtotime(strval(fieldOf($sqlLink, 'emma', 'codeDate'))) - (time()+300*60))<=5, true);
checkLike('enrol: the creation date is given by the database', fieldOf($sqlLink, 'emma', 'madeDate'), '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/');
checkLike('enrol: logged as a notice, signed', readLogs(), '/\[NOTICE\] User "emma" created \(member\) by user "anna" from 192\.0\.2\.7/');
checkSame('enrol: a pending user cannot log in, whatever it gives', Array($natAuth->checkCred(Array('user'=>'emma', 'pass'=>'!')), $natAuth->checkCred(Array('user'=>'emma', 'pass'=>'')), $natAuth->checkCred(Array('user'=>'emma', 'pass'=>strval($emmaCod)))), Array(false, false, false));
checkLike('enrol: with a role', $usrBase->enrolUser('Fred.Opr-2', 'system'), '/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D');
checkSame('enrol: the role is stored', fieldOf($sqlLink, 'Fred.Opr-2', 'userRole'), 'system');
checkSame('enrol: two codes are never the same', $emmaCod===$usrBase->enrolUser('gina'), false);
readLogs();
checkSame('enrol: name taken', operates($usrBase, 'enrolUser', Array('ben')), Array(false, 409, 'User name already taken'));
checkSame('enrol: name taken, in another case', operates($usrBase, 'enrolUser', Array('BEN')), Array(false, 409, 'User name already taken'));
foreach( Array('too short'=>'ab', 'too long'=>str_repeat('a', 33), 'starting with a digit'=>'1abc', 'with a space'=>'ab cd', 'with a quote'=>"ab'cd", 'with a line break'=>"abcd\n", 'empty'=>'', 'root'=>'root', 'Admin'=>'Admin') as $whyBad=>$badNme ){
	checkSame('enrol: name refused, '.$whyBad, operates($usrBase, 'enrolUser', Array($badNme)), Array(false, 400, 'Invalid user name'));
}
checkSame('enrol: unknown role', operates($usrBase, 'enrolUser', Array('henry', 'owner')), Array(false, 400, 'Invalid role'));
checkSame('enrol: a refused creation writes nothing', Array(fieldOf($sqlLink, 'henry', 'userName'), readLogs()), Array(false, ''));
checkSame('enrol: on a missing table', operates(new UserBase(Array('tblnme'=>'nousers')), 'enrolUser', Array('henry')), Array(false, 500, 'Credential store unavailable'));

// 2. actvtUser : the code, once, gives the password and the active status
Logger::plugActor( '', '192.0.2.9' );
checkSame('activate: wrong code', operates($usrBase, 'actvtUser', Array('emma', str_repeat('0', 32), 'pass-emma-1')), Array(false, 401, 'Invalid activation'));
checkSame('activate: a wrong code counts against the address', $usrBase->wrongCred(), true);
checkLike('activate: the refusal is logged as a warning', readLogs(), '/\[WARNING\] Activation refused for user "emma" from 192\.0\.2\.9/');
checkSame('activate: empty code', operates($usrBase, 'actvtUser', Array('emma', '', 'pass-emma-1')), Array(false, 401, 'Invalid activation'));
checkSame('activate: the stored hash is not a code', operates($usrBase, 'actvtUser', Array('emma', hash('sha256', strval($emmaCod)), 'pass-emma-1')), Array(false, 401, 'Invalid activation'));
checkSame('activate: the code of another user', operates($usrBase, 'actvtUser', Array('gina', strval($emmaCod), 'pass-gina-1')), Array(false, 401, 'Invalid activation'));
checkSame('activate: unknown user, same refusal', operates($usrBase, 'actvtUser', Array('zed', strval($emmaCod), 'pass-zed-01')), Array(false, 401, 'Invalid activation'));
checkSame('activate: a password breaking a rule', operates($usrBase, 'actvtUser', Array('emma', strval($emmaCod), 'short')), Array(false, 400, 'Password too short: 10 characters at least'));
checkSame('activate: a rule broken does not count against the address', $usrBase->wrongCred(), false);
checkSame('activate: still pending after the refusals', fieldOf($sqlLink, 'emma', 'userStat'), 'pendng');
readLogs();
checkSame('activate: the right code', operates($usrBase, 'actvtUser', Array('emma', strval($emmaCod), 'pass-emma-1')), Array(true, 200, ''));
checkSame('activate: active, the code cleared', Array(fieldOf($sqlLink, 'emma', 'userStat'), fieldOf($sqlLink, 'emma', 'actvCode')), Array('active', null));
checkLike('activate: logged as an info', readLogs(), '/\[INFO\] User "emma" activated from 192\.0\.2\.9/');
checkSame('activate: the user logs in with its password', $natAuth->checkCred(Array('user'=>'emma', 'pass'=>'pass-emma-1'))['role'] ?? null, 'member');
checkSame('activate: the code is used once', operates($usrBase, 'actvtUser', Array('emma', strval($emmaCod), 'pass-emma-2')), Array(false, 401, 'Invalid activation'));
checkSame('activate: the password of the first use stays', $natAuth->checkCred(Array('user'=>'emma', 'pass'=>'pass-emma-1'))['user'] ?? null, 'emma');
checkSame('activate: a pending user without a code stays pending', Array(operates($usrBase, 'actvtUser', Array('cleo', '', 'pass-cleo-2')), fieldOf($sqlLink, 'cleo', 'userStat')), Array(Array(false, 401, 'Invalid activation'), 'pendng'));
checkSame('activate: a locked or active user is not activated', Array($usrBase->actvtUser('dave', '', 'pass-dave-2'), $usrBase->actvtUser('ben', '', 'pass-ben-22')), Array(false, false));
checkSame('activate: on a missing table', operates(new UserBase(Array('tblnme'=>'nousers')), 'actvtUser', Array('emma', strval($emmaCod), 'pass-emma-1')), Array(false, 500, 'Credential store unavailable'));

// 3. chgPasswd : the current password is the proof
Logger::plugActor( 'ben', '192.0.2.9' );
readLogs();
checkSame('password: wrong current one', operates($usrBase, 'chgPasswd', Array('ben', 'nope', 'pass-ben-22')), Array(false, 401, 'Invalid credentials'));
checkSame('password: a wrong current one is a failed attempt', Array(intval(fieldOf($sqlLink, 'ben', 'failNmbr')), $usrBase->wrongCred()), Array(1, true));
checkLike('password: the refusal is logged as a warning', readLogs(), '/\[WARNING\] Password change refused for user "ben": wrong current password by user "ben" from 192\.0\.2\.9/');
checkSame('password: a new one breaking a rule', operates($usrBase, 'chgPasswd', Array('ben', 'pass-ben-1', 'short')), Array(false, 400, 'Password too short: 10 characters at least'));
checkSame('password: the same as the current one', operates($usrBase, 'chgPasswd', Array('ben', 'pass-ben-1', 'pass-ben-1')), Array(false, 400, 'Password identical to the current one'));
checkSame('password: changed', operates($usrBase, 'chgPasswd', Array('ben', 'pass-ben-1', 'pass-ben-22')), Array(true, 200, ''));
checkLike('password: logged as an info, without any password', $logText = readLogs(), '/\[INFO\] Password changed for user "ben" by user "ben"/');
checkSame('password: no password in the log', preg_match('/pass-ben/', $logText), 0);
checkSame('password: the failures are forgotten by the change', intval(fieldOf($sqlLink, 'ben', 'failNmbr')), 0);
checkSame('password: the new one logs in, the former does not', Array($natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pass-ben-22'))['user'] ?? null, $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pass-ben-1'))), Array('ben', false));
$sqlLink->exec("UPDATE users SET failNmbr=0, failDate=NULL");
$sqlLink->exec("UPDATE users SET tokenKey='tk', twoFaKey='2f' WHERE userName='ben'");
$usrBase->chgPasswd('ben', 'pass-ben-22', 'pass-ben-1');
checkSame('password: token and 2FA secret are left as they are', Array(fieldOf($sqlLink, 'ben', 'tokenKey'), fieldOf($sqlLink, 'ben', 'twoFaKey')), Array('tk', '2f'));
$sqlLink->exec("UPDATE users SET tokenKey=NULL, twoFaKey=NULL WHERE userName='ben'");
checkSame('password: a locked user', operates($usrBase, 'chgPasswd', Array('dave', 'pass-dave-1', 'pass-dave-2')), Array(false, 401, 'Account not active'));
checkSame('password: a pending user', operates($usrBase, 'chgPasswd', Array('cleo', 'pass-cleo-1', 'pass-cleo-2')), Array(false, 401, 'Account not active'));
checkSame('password: an unknown user', operates($usrBase, 'chgPasswd', Array('zed', 'pass-zed-01', 'pass-zed-02')), Array(false, 401, 'Account not active'));
$sqlLink->exec("UPDATE users SET failNmbr=5, failDate='".date('Y-m-d H:i:s')."' WHERE userName='ben'");
checkSame('password: a held user is refused with the right password', operates($usrBase, 'chgPasswd', Array('ben', 'pass-ben-1', 'pass-ben-22')), Array(false, 401, 'Invalid credentials'));
checkSame('password: and keeps its password', password_verify('pass-ben-1', strval(fieldOf($sqlLink, 'ben', 'passHash'))), true);
$sqlLink->exec("UPDATE users SET failNmbr=0, failDate=NULL");

// 4. setStatus : lock and unlock
Logger::plugActor( 'anna', '192.0.2.7' );
readLogs();
checkSame('lock: an active user', operates($usrBase, 'setStatus', Array('ben', 'locked')), Array(true, 200, ''));
checkLike('lock: logged as a notice', readLogs(), '/\[NOTICE\] User "ben" locked by user "anna" from 192\.0\.2\.7/');
checkSame('lock: the user no longer logs in', Array($natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pass-ben-1')), $natAuth->lastError(), $natAuth->userIdent('ben')), Array(false, 'Invalid credentials', false));
checkSame('lock: already locked', operates($usrBase, 'setStatus', Array('ben', 'locked')), Array(false, 409, 'User already locked'));
$sqlLink->exec("UPDATE users SET failNmbr=3, failDate='".date('Y-m-d H:i:s')."' WHERE userName='ben'");
checkSame('unlock: a locked user', operates($usrBase, 'setStatus', Array('ben', 'active')), Array(true, 200, ''));
checkLike('unlock: logged as a notice', readLogs(), '/\[NOTICE\] User "ben" unlocked by user "anna"/');
checkSame('unlock: the failures are forgotten', Array(intval(fieldOf($sqlLink, 'ben', 'failNmbr')), fieldOf($sqlLink, 'ben', 'failDate')), Array(0, null));
checkSame('unlock: the user logs in again', $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pass-ben-1'))['user'] ?? null, 'ben');
checkSame('unlock: already active', operates($usrBase, 'setStatus', Array('ben', 'active')), Array(false, 409, 'User already active'));
checkSame('lock: a pending user', operates($usrBase, 'setStatus', Array('cleo', 'locked')), Array(false, 409, 'A pending user is neither locked nor unlocked'));
checkSame('unlock: a pending user is not activated that way', Array(operates($usrBase, 'setStatus', Array('cleo', 'active')), fieldOf($sqlLink, 'cleo', 'userStat')), Array(Array(false, 409, 'A pending user is neither locked nor unlocked'), 'pendng'));
checkSame('lock: an unknown user', operates($usrBase, 'setStatus', Array('zed', 'locked')), Array(false, 404, 'User not found'));
checkSame('lock: a status that is not one of the two', Array(operates($usrBase, 'setStatus', Array('ben', 'pendng')), operates($usrBase, 'setStatus', Array('ben', 'online'))), Array(Array(false, 400, 'Invalid status'), Array(false, 400, 'Invalid status')));
checkSame('lock: the last active manager', Array(operates($usrBase, 'setStatus', Array('anna', 'locked')), fieldOf($sqlLink, 'anna', 'userStat')), Array(Array(false, 409, 'The last active manager cannot be locked'), 'active'));
checkSame('lock: a name holding a quote', operates($usrBase, 'setStatus', Array("ben' OR '1'='1", 'locked')), Array(false, 404, 'User not found'));

// 5. changRole
readLogs();
checkSame('role: changed', operates($usrBase, 'changRole', Array('ben', 'system')), Array(true, 200, ''));
checkLike('role: logged as a notice, with both roles', readLogs(), '/\[NOTICE\] Role of user "ben" changed from member to system by user "anna"/');
checkSame('role: the next login carries it', $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pass-ben-1'))['role'] ?? null, 'system');
checkSame('role: already that one', operates($usrBase, 'changRole', Array('ben', 'system')), Array(false, 409, 'User already of that role'));
checkSame('role: unknown role', Array(operates($usrBase, 'changRole', Array('ben', 'owner')), operates($usrBase, 'changRole', Array('ben', ''))), Array(Array(false, 400, 'Invalid role'), Array(false, 400, 'Invalid role')));
checkSame('role: unknown user', operates($usrBase, 'changRole', Array('zed', 'system')), Array(false, 404, 'User not found'));
checkSame('role: the last active manager keeps its role', Array(operates($usrBase, 'changRole', Array('anna', 'member')), fieldOf($sqlLink, 'anna', 'userRole')), Array(Array(false, 409, 'The last active manager cannot be given another role'), 'manage'));
checkSame('role: a pending or locked user can be given one', Array($usrBase->changRole('cleo', 'system'), $usrBase->changRole('dave', 'manage')), Array(true, true));
checkSame('role: a locked manager does not make the active one less the last', operates($usrBase, 'changRole', Array('anna', 'member')), Array(false, 409, 'The last active manager cannot be given another role'));
checkSame('role: with a second active manager, the first can be demoted', Array($usrBase->changRole('ben', 'manage'), $usrBase->changRole('anna', 'system'), $usrBase->changRole('anna', 'manage')), Array(true, true, true));
$usrBase->changRole('ben', 'member');
$usrBase->changRole('cleo', 'member');
$usrBase->changRole('dave', 'system');

// 7. listUsers
$allList = $usrBase->listUsers();
checkSame('list: every user, by name', array_column((array)$allList, 'userName'), Array('Fred.Opr-2', 'anna', 'ben', 'cleo', 'dave', 'emma', 'gina'));
checkSame('list: only what may be told', array_keys($allList[1] ?? Array()), Array('id_user', 'userName', 'userRole', 'userStat', 'userMail', 'signDate', 'madeDate', 'lastSeen'));
checkSame('list: a row', $allList[1] ?? null, Array('id_user'=>1, 'userName'=>'anna', 'userRole'=>'manage', 'userStat'=>'active', 'userMail'=>null, 'signDate'=>null, 'madeDate'=>'2026-10-01 09:00:00', 'lastSeen'=>null));
checkSame('list: by status', array_column((array)$usrBase->listUsers(Array('userStat'=>'pendng')), 'userName'), Array('Fred.Opr-2', 'cleo', 'gina'));
checkSame('list: by role', array_column((array)$usrBase->listUsers(Array('userRole'=>'system')), 'userName'), Array('Fred.Opr-2', 'dave'));
checkSame('list: by status and role', array_column((array)$usrBase->listUsers(Array('userStat'=>'active', 'userRole'=>'member')), 'userName'), Array('ben', 'emma'));
checkSame('list: empty filters are left out', count((array)$usrBase->listUsers(Array('userStat'=>'', 'userRole'=>null))), 7);
checkSame('list: nobody', $usrBase->listUsers(Array('userStat'=>'locked', 'userRole'=>'manage')), Array());
checkSame('list: limit and offset', array_column((array)$usrBase->listUsers(Array(), 2, 1), 'userName'), Array('anna', 'ben'));
checkSame('list: an offset beyond the end', $usrBase->listUsers(Array(), 2, 50), Array());
checkSame('list: a filter that is not a status', operates($usrBase, 'listUsers', Array(Array('userStat'=>"active' OR '1'='1"))), Array(false, 400, 'Invalid filter'));
checkSame('list: a filter given as an array', operates($usrBase, 'listUsers', Array(Array('userRole'=>Array('member')))), Array(false, 400, 'Invalid filter'));
checkSame('list: another field is not a filter', count((array)$usrBase->listUsers(Array('passHash'=>'!'))), 7);
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>inTempDir('userbase.sqlite')),
	'system'=>Array('LST_ROWS'=>3)
) );
checkSame('list: the default number of rows is LST_ROWS', count((array)$usrBase->listUsers()), 3);
useSqlite( inTempDir('userbase.sqlite') );
checkSame('list: on a missing table', operates(new UserBase(Array('tblnme'=>'nousers')), 'listUsers', Array()), Array(false, 500, 'Credential store unavailable'));

// 8. userInfos
$sqlLink->exec("UPDATE users SET tokenKey='tk', twoFaKey='2f' WHERE userName='ben'");
checkSame('read: one user, without hash, code, token or secret', $usrBase->userInfos('ben'), Array('id_user'=>2, 'userName'=>'ben', 'userRole'=>'member', 'userStat'=>'active', 'userMail'=>null, 'signDate'=>null, 'madeDate'=>'2026-10-01 09:00:00', 'lastSeen'=>null));
$sqlLink->exec("UPDATE users SET twoFaStp=58000000 WHERE userName='ben'");
checkSame('2FA: the secret is taken away with the step of its last code', Array($usrBase->dropTwoFa('ben'), $sqlLink->query("SELECT twoFaKey, twoFaStp FROM users WHERE userName='ben'")->fetch(PDO::FETCH_NUM)), Array(true, Array(null, null)));
checkSame('2FA: nothing to take away from an unknown user', operates($usrBase, 'dropTwoFa', Array('zed')), Array(false, 404, 'User not found'));
checkSame('read: a pending user', $usrBase->userInfos('gina')['userStat'] ?? null, 'pendng');
checkSame('read: unknown user', operates($usrBase, 'userInfos', Array('zed')), Array(false, 404, 'User not found'));
checkSame('read: no name', operates($usrBase, 'userInfos', Array('')), Array(false, 404, 'User not found'));
checkSame('read: the name is matched with its case', operates($usrBase, 'userInfos', Array('BEN')), Array(false, 404, 'User not found'));
checkSame('read: on a missing table', operates(new UserBase(Array('tblnme'=>'nousers')), 'userInfos', Array('ben')), Array(false, 500, 'Credential store unavailable'));

// 9. resetUser : back to pending, with a new code
readLogs();
$benCode = $usrBase->resetUser('ben');
checkLike('reset: gives a new code', $benCode, '/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D');
checkSame('reset: pending, no password, no token, role kept', Array(fieldOf($sqlLink, 'ben', 'userStat'), fieldOf($sqlLink, 'ben', 'passHash'), fieldOf($sqlLink, 'ben', 'tokenKey'), fieldOf($sqlLink, 'ben', 'userRole')), Array('pendng', '!', null, 'member'));
checkLike('reset: logged as a notice, without the code', $logText = readLogs(), '/\[NOTICE\] User "ben" reset by user "anna"/');
checkSame('reset: no code in the log', strpos($logText, strval($benCode)), false);
checkSame('reset: the former password no longer logs in', $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pass-ben-1')), false);
$nextCod = $usrBase->resetUser('ben');
checkSame('reset: a pending user gets a new code, the previous one is void', Array($usrBase->actvtUser('ben', strval($benCode), 'pass-ben-33'), $usrBase->actvtUser('ben', strval($nextCod), 'pass-ben-33')), Array(false, true));
checkSame('reset: the user is back with its new password', $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pass-ben-33'))['user'] ?? null, 'ben');
checkSame('reset: a locked user', Array(operates($usrBase, 'resetUser', Array('dave')), fieldOf($sqlLink, 'dave', 'userStat')), Array(Array(false, 409, 'A locked user is unlocked before it is reset'), 'locked'));
checkSame('reset: the last active manager', Array(operates($usrBase, 'resetUser', Array('anna')), fieldOf($sqlLink, 'anna', 'userStat')), Array(Array(false, 409, 'The last active manager cannot be reset'), 'active'));
checkSame('reset: unknown user', operates($usrBase, 'resetUser', Array('zed')), Array(false, 404, 'User not found'));

// 6. removUser
readLogs();
checkSame('remove: a user', Array(operates($usrBase, 'removUser', Array('gina')), fieldOf($sqlLink, 'gina', 'userName')), Array(Array(true, 200, ''), false));
checkLike('remove: logged as a notice, with the role it had', readLogs(), '/\[NOTICE\] User "gina" removed \(member\) by user "anna" from 192\.0\.2\.7/');
checkSame('remove: already gone', operates($usrBase, 'removUser', Array('gina')), Array(false, 404, 'User not found'));
checkSame('remove: a locked user', $usrBase->removUser('dave'), true);
checkSame('remove: the last active manager', Array(operates($usrBase, 'removUser', Array('anna')), fieldOf($sqlLink, 'anna', 'userName')), Array(Array(false, 409, 'The last active manager cannot be removed'), 'anna'));
checkSame('remove: a name holding a quote removes nobody', Array($usrBase->removUser("x' OR '1'='1"), count((array)$usrBase->listUsers())), Array(false, 5));
checkSame('remove: the name is free again', is_string($usrBase->enrolUser('gina')), true);
checkSame('remove: on a missing table', operates(new UserBase(Array('tblnme'=>'nousers')), 'removUser', Array('ben')), Array(false, 500, 'Credential store unavailable'));

// 10. Addresses : given at the creation or later, in lower case, one account each
readLogs();
$hanaCod = $usrBase->enrolUser('hana', '', 'Hana@Example.CH');
checkSame('address: given at the creation, in lower case', Array(is_string($hanaCod), fieldOf($sqlLink, 'hana', 'userMail')), Array(true, 'hana@example.ch'));
checkSame('address: one account each, whatever its case', operates($usrBase, 'enrolUser', Array('hugo', '', 'HANA@example.ch')), Array(false, 409, 'Mail address already used'));
checkSame('address: one that is not', Array(operates($usrBase, 'enrolUser', Array('hugo', '', 'hugo@')), operates($usrBase, 'enrolUser', Array('hugo', '', "hugo@example.ch\r\nBcc: x@y.z"))), Array(Array(false, 400, 'Invalid mail address'), Array(false, 400, 'Invalid mail address')));
checkSame('address: an empty one is none', Array(is_string($usrBase->enrolUser('hugo', '', '', 0)), fieldOf($sqlLink, 'hugo', 'userMail'), fieldOf($sqlLink, 'hugo', 'codeDate')), Array(true, null, null));
checkSame('address: given later', Array(operates($usrBase, 'chngeMail', Array('ben', ' Ben@Example.ch ')), fieldOf($sqlLink, 'ben', 'userMail')), Array(Array(true, 200, ''), 'ben@example.ch'));
checkSame('address: given again, the same', operates($usrBase, 'chngeMail', Array('ben', 'ben@example.ch')), Array(true, 200, ''));
checkSame('address: held by another user', operates($usrBase, 'chngeMail', Array('ben', 'hana@example.ch')), Array(false, 409, 'Mail address already used'));
checkSame('address: not one, unknown user', Array(operates($usrBase, 'chngeMail', Array('ben', 'not an address')), operates($usrBase, 'chngeMail', Array('zed', 'zed@example.ch'))), Array(Array(false, 400, 'Invalid mail address'), Array(false, 404, 'User not found')));
checkSame('address: taken away', Array($usrBase->chngeMail('hugo', 'hugo@example.ch'), $usrBase->chngeMail('hugo', null), fieldOf($sqlLink, 'hugo', 'userMail')), Array(true, true, null));
checkLike('address: logged as a notice, without the address', $logText = readLogs(), '/\[NOTICE\] Address of user "ben" set by user "anna"/');
checkSame('address: never in the log', preg_match('/@example\.ch/i', $logText), 0);

// 11. Codes : read whatever their case and dashes, until their end; those of 0.9 still read
checkSame('code: in lower case, without its dashes', $usrBase->actvtUser('hana', strtolower(str_replace('-', ' ', strval($hanaCod))), 'pass-hana-1'), true);
$hugoCod = strval($usrBase->resetUser('hugo'));
$sqlLink->exec("UPDATE users SET codeDate='".date('Y-m-d H:i:s', time()-60)."' WHERE userName='hugo'");
checkSame('code: refused once ended, as a wrong one', Array(operates($usrBase, 'actvtUser', Array('hugo', $hugoCod, 'pass-hugo-1')), fieldOf($sqlLink, 'hugo', 'userStat')), Array(Array(false, 401, 'Invalid activation'), 'pendng'));
$hugoCod = strval($usrBase->resetUser('hugo', 0));
checkSame('code: without an end', Array(fieldOf($sqlLink, 'hugo', 'codeDate'), $usrBase->actvtUser('hugo', $hugoCod, 'pass-hugo-1')), Array(null, true));
$sqlLink->exec("INSERT INTO users (userName, passHash, userStat, actvCode) VALUES ('mona', '!', 'pendng', '".hash('sha256', 'abcdef0123456789abcdef0123456789')."')");
checkSame('code: one made by 0.9', $usrBase->actvtUser('mona', 'abcdef0123456789abcdef0123456789', 'pass-mona-1'), true);

// 12. signsUser : pending with the address, then in review, then approved
$sgnBase = new UserBase( Array('codexp'=>30) );
checkSame('codexp: the minutes of a code sent by mail', Array($usrBase->mailedFor(), $sgnBase->mailedFor()), Array(15, 30));
readLogs();
Logger::plugActor( '', '192.0.2.11' );
$ivanCod = $sgnBase->signsUser('ivan', 'Ivan@Example.ch');
checkLike('sign-up: a code', $ivanCod, '/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D');
checkSame('sign-up: pending, a member, with its address and the time of the sign-up', Array(fieldOf($sqlLink, 'ivan', 'userStat'), fieldOf($sqlLink, 'ivan', 'userRole'), fieldOf($sqlLink, 'ivan', 'userMail'), fieldOf($sqlLink, 'ivan', 'passHash'), abs(strtotime(strval(fieldOf($sqlLink, 'ivan', 'signDate'))) - time())<=5), Array('pendng', 'member', 'ivan@example.ch', '!', true));
checkSame('sign-up: the code lasts the minutes of codexp', abs(strtotime(strval(fieldOf($sqlLink, 'ivan', 'codeDate'))) - (time()+30*60))<=5, true);
checkLike('sign-up: logged as a notice', readLogs(), '/\[NOTICE\] User "ivan" signed up from 192\.0\.2\.11/');
$usrNmbr = count((array)$usrBase->listUsers());
checkSame('sign-up: a name taken, whatever its case, is not a refusal', operates($sgnBase, 'signsUser', Array('IVAN', 'other@example.ch')), Array('', 200, ''));
checkSame('sign-up: nor is an address taken', operates($sgnBase, 'signsUser', Array('jack', 'BEN@example.ch')), Array('', 200, ''));
checkSame('sign-up: neither writes anything', count((array)$usrBase->listUsers()), $usrNmbr);
checkLike('sign-up: a name or an address taken is logged as an info', readLogs(), '/\[INFO\] Sign-up as "IVAN": the name or the address is taken from 192\.0\.2\.11/');
checkSame('sign-up: names and addresses that are not', Array(operates($sgnBase, 'signsUser', Array('admin', 'jack@example.ch')), operates($sgnBase, 'signsUser', Array('jack', 'jack'))), Array(Array(false, 400, 'Invalid user name'), Array(false, 400, 'Invalid mail address')));
checkSame('sign-up: confirmed with its code, it awaits review', Array($usrBase->actvtUser('ivan', strval($ivanCod), 'pass-ivan-1'), fieldOf($sqlLink, 'ivan', 'userStat')), Array(true, 'review'));
checkLike('sign-up: the confirmation is logged as a notice', readLogs(), '/\[NOTICE\] User "ivan" confirmed its address, awaits review/');
checkSame('review: no login yet', $natAuth->checkCred(Array('user'=>'ivan', 'pass'=>'pass-ivan-1')), false);
checkSame('review: neither locked nor unlocked', Array(operates($usrBase, 'setStatus', Array('ivan', 'active')), operates($usrBase, 'setStatus', Array('ivan', 'locked'))), Array(Array(false, 409, 'A user in review is approved or removed'), Array(false, 409, 'A user in review is approved or removed')));
checkSame('review: listed by its status', array_column((array)$usrBase->listUsers(Array('userStat'=>'review')), 'userName'), Array('ivan'));
Logger::plugActor( 'anna', '192.0.2.7' );
checkSame('approve: a user in review', Array(operates($usrBase, 'proveUser', Array('ivan')), fieldOf($sqlLink, 'ivan', 'userStat'), fieldOf($sqlLink, 'ivan', 'signDate')), Array(Array(true, 200, ''), 'active', null));
checkLike('approve: logged as a notice, signed', readLogs(), '/\[NOTICE\] User "ivan" approved by user "anna" from 192\.0\.2\.7/');
checkSame('approve: the user logs in', $natAuth->checkCred(Array('user'=>'ivan', 'pass'=>'pass-ivan-1'))['user'] ?? null, 'ivan');
checkSame('approve: only a user in review', Array(operates($usrBase, 'proveUser', Array('ivan')), operates($usrBase, 'proveUser', Array('cleo')), operates($usrBase, 'proveUser', Array('zed'))), Array(Array(false, 409, 'User not awaiting review'), Array(false, 409, 'User not awaiting review'), Array(false, 404, 'User not found')));
$ivanCod = strval($usrBase->resetUser('ivan'));
checkSame('approve: a reset afterwards activates without a review', Array($usrBase->actvtUser('ivan', $ivanCod, 'pass-ivan-2'), fieldOf($sqlLink, 'ivan', 'userStat')), Array(true, 'active'));
$kateCod = strval($sgnBase->signsUser('kate', 'kate@example.ch'));
$sqlLink->exec("UPDATE users SET codeDate='".date('Y-m-d H:i:s', time()-60)."' WHERE userName='kate'");
checkSame('sign-up: its code ended, it is not confirmed', operates($usrBase, 'actvtUser', Array('kate', $kateCod, 'pass-kate-1')), Array(false, 401, 'Invalid activation'));
readLogs();
checkSame('sign-up: the next sign-up removes it, its name and address are free again', Array(is_string($sgnBase->signsUser('lena', 'lena@example.ch')), fieldOf($sqlLink, 'kate', 'userName'), is_string($sgnBase->signsUser('kate', 'kate@example.ch')) && $sgnBase->lastState()==200), Array(true, false, true));
checkLike('sign-up: the removal is logged as an info', readLogs(), '/\[INFO\] 1 sign-ups not confirmed in time removed/');
$sqlLink->exec('BEGIN');
$sgnStmt = $sqlLink->prepare("INSERT INTO users (userName, passHash, userStat, userMail, signDate) VALUES (?, '!', 'review', ?, '2026-11-04 08:00:00')");
for( $idx=0; $idx<UserBase::SIGNSCAP; $idx++ ){
	$sgnStmt->execute( Array('wait'.$idx, 'wait'.$idx.'@example.ch') );
}
$sqlLink->exec('COMMIT');
checkSame('sign-up: closed for a while when too many are waiting', operates($sgnBase, 'signsUser', Array('nina', 'nina@example.ch')), Array(false, 503, 'Sign-ups closed for now'));
checkSame('sign-up: the cap is the one given', Array($usrBase->waitCount()>=UserBase::SIGNSCAP, operates($sgnBase, 'signsUser', Array('nina', 'nina@example.ch', UserBase::SIGNSCAP+10))[1]), Array(true, 200));
$sqlLink->exec("DELETE FROM users WHERE userName LIKE 'wait%' OR userName='nina'");
$waitNum = $usrBase->waitCount();
checkSame('waitCount: the sign-ups not confirmed, and those in review', Array($waitNum, $usrBase->waitCount()), Array(intval($sqlLink->query("SELECT COUNT(*) FROM users WHERE signDate IS NOT NULL AND userStat IN ('pendng', 'review')")->fetchColumn()), $waitNum));
checkSame('sign-up: refused at a lower cap', operates($sgnBase, 'signsUser', Array('olga', 'olga@example.ch', $waitNum)), Array(false, 503, 'Sign-ups closed for now'));
$sqlLink->exec("UPDATE users SET userMail='anna@example.ch' WHERE userName='anna'");
checkSame('managMail: the addresses of the active managers', $usrBase->managMail(), Array('anna@example.ch'));
checkSame('waitCount and managMail on a missing table', Array(operates(new UserBase(Array('tblnme'=>'nousers')), 'waitCount', Array()), operates(new UserBase(Array('tblnme'=>'nousers')), 'managMail', Array())), Array(Array(false, 500, 'Credential store unavailable'), Array(false, 500, 'Credential store unavailable')));
$sgnStmt = null;

// 13. resetPass and recovUser : a reset asked for by the user, its password kept until it is done
readLogs();
Logger::plugActor( '', '192.0.2.12' );
$rstVals = $usrBase->resetPass('ben');
checkSame('reset asked: by name, the address and a code', Array($rstVals['user'] ?? null, $rstVals['mail'] ?? null, preg_match('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{2}$/D', strval($rstVals['code'] ?? ''))), Array('ben', 'ben@example.ch', 1));
checkSame('reset asked: the hash of the code stored, for 15 minutes, the password kept', Array(fieldOf($sqlLink, 'ben', 'resetCod'), abs(strtotime(strval(fieldOf($sqlLink, 'ben', 'codeDate'))) - (time()+15*60))<=5, $natAuth->checkCred(Array('user'=>'ben', 'pass'=>'pass-ben-33'))['user'] ?? null), Array(hash('sha256', str_replace('-', '', strval($rstVals['code'] ?? ''))), true, 'ben'));
$scndRst = $usrBase->resetPass('BEN@example.CH');
checkSame('reset asked: by address, whatever its case; the new code replaces the former', Array($scndRst['user'] ?? null, operates($usrBase, 'recovUser', Array('ben', strval($rstVals['code'] ?? ''), 'pass-ben-44'))), Array('ben', Array(false, 401, 'Invalid code')));
checkSame('reset: a wrong code counts against the address', $usrBase->wrongCred(), true);
checkSame('reset asked: unknown, without an address, not active', Array(operates($usrBase, 'resetPass', Array('zed')), operates($usrBase, 'resetPass', Array('nobody@example.ch')), operates($usrBase, 'resetPass', Array('emma')), operates($usrBase, 'resetPass', Array('kate')), operates($usrBase, 'resetPass', Array(''))),
	Array(Array(false, 404, 'User not found'), Array(false, 404, 'User not found'), Array(false, 409, 'No reset for this user'), Array(false, 409, 'No reset for this user'), Array(false, 404, 'User not found')));
checkSame('reset: a new password breaking a rule', operates($usrBase, 'recovUser', Array('ben', strval($scndRst['code'] ?? ''), 'short')), Array(false, 400, 'Password too short: 10 characters at least'));
checkSame('reset: the code of another user', operates($usrBase, 'recovUser', Array('ivan', strval($scndRst['code'] ?? ''), 'pass-ivan-44')), Array(false, 401, 'Invalid code'));
$sqlLink->exec("UPDATE users SET failNmbr=5, failDate='".date('Y-m-d H:i:s')."', tokenKey='tk', twoFaKey='2f' WHERE userName='ben'");
checkSame('reset: done with the code', operates($usrBase, 'recovUser', Array('ben', strval($scndRst['code'] ?? ''), 'pass-ben-44')), Array(true, 200, ''));
checkSame('reset: the new password, the code cleared, the failures forgotten, token and secret kept', Array(password_verify('pass-ben-44', strval(fieldOf($sqlLink, 'ben', 'passHash'))), fieldOf($sqlLink, 'ben', 'resetCod'), fieldOf($sqlLink, 'ben', 'codeDate'), intval(fieldOf($sqlLink, 'ben', 'failNmbr')), fieldOf($sqlLink, 'ben', 'tokenKey'), fieldOf($sqlLink, 'ben', 'twoFaKey')), Array(true, null, null, 0, 'tk', '2f'));
$sqlLink->exec("UPDATE users SET tokenKey=NULL, twoFaKey=NULL WHERE userName='ben'");
checkSame('reset: the code is used once', operates($usrBase, 'recovUser', Array('ben', strval($scndRst['code'] ?? ''), 'pass-ben-55')), Array(false, 401, 'Invalid code'));
$thrdRst = $usrBase->resetPass('ben');
$sqlLink->exec("UPDATE users SET codeDate='".date('Y-m-d H:i:s', time()-60)."' WHERE userName='ben'");
checkSame('reset: a code ended', operates($usrBase, 'recovUser', Array('ben', strval($thrdRst['code'] ?? ''), 'pass-ben-55')), Array(false, 401, 'Invalid code'));
$logText = readLogs();
checkLike('reset: asked, refused and done are logged', $logText, '/\[NOTICE\] Reset asked for user "ben" from 192\.0\.2\.12.*\[WARNING\] Password reset refused for user "ben".*\[INFO\] Password of user "ben" reset with its code/s');
checkSame('reset: neither a code nor an address in the log', preg_match('/'.preg_quote(strval($scndRst['code'] ?? ''), '/').'|@example/', $logText), 0);
$frthRst = $usrBase->resetPass('ben');
$usrBase->chngeMail('ben', 'ben.new@example.ch');
checkSame('reset: a new address forgets the code sent to the former', Array(fieldOf($sqlLink, 'ben', 'resetCod'), operates($usrBase, 'recovUser', Array('ben', strval($frthRst['code'] ?? ''), 'pass-ben-55'))), Array(null, Array(false, 401, 'Invalid code')));
$usrBase->resetPass('ben');
$benCode = strval($usrBase->resetUser('ben'));
checkSame('reset by a manager: the code asked for by the user is forgotten', Array(fieldOf($sqlLink, 'ben', 'resetCod'), $usrBase->actvtUser('ben', $benCode, 'pass-ben-33')), Array(null, true));
Logger::plugActor( 'anna', '192.0.2.7' );

// fromLogin : the userbase the login uses
checkSame('fromLogin: without a login block', UserBase::fromLogin(), null);
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>inTempDir('userbase.sqlite')),
	'access'=>Array('LOGIN'=>Array('authby'=>'htpass'), 'NATIVE'=>Array('tblnme'=>'users'), 'OTHERS'=>Array('tblnme'=>'nousers'))
) );
checkSame('fromLogin: a login on another credential check', UserBase::fromLogin(), null);
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>inTempDir('userbase.sqlite')),
	'access'=>Array('LOGIN'=>Array('authby'=>'native'), 'NATIVE'=>Array('tblnme'=>'users', 'minlen'=>12), 'OTHERS'=>Array('tblnme'=>'nousers'))
) );
$lgnBase = UserBase::fromLogin();
checkSame('fromLogin: the native block by default', ($lgnBase!==null) ? $lgnBase->userInfos('anna')['userRole'] ?? null : null, 'manage');
checkSame('fromLogin: the block gives its password length', ($lgnBase!==null) ? operates($lgnBase, 'chgPasswd', Array('anna', 'pass-anna-1', 'pass-anna-2')) : null, Array(false, 400, 'Password too short: 12 characters at least'));
$GLOBALS['nyuConfig'] = new nyu\Tools\Registry( Array(
	'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>inTempDir('userbase.sqlite')),
	'access'=>Array('LOGIN'=>Array('authby'=>'native', 'authcf'=>'others'), 'NATIVE'=>Array('tblnme'=>'users'), 'OTHERS'=>Array('tblnme'=>'nousers'))
) );
checkSame('fromLogin: the block authcf names', operates(UserBase::fromLogin(), 'userInfos', Array('anna')), Array(false, 500, 'Credential store unavailable'));

Logger::plugSetup( Array() );
Logger::plugActor( '', '' );
$sqlLink = null;

endChecks();
