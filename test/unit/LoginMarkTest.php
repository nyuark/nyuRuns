<?php

// Access\LoginMark : the mark of a client already used by a user, which
// the hold of the account does not stop; its failures counted on the mark,
// refused in its turn; a mark of another user, forged, ended or without a
// key helps nobody. On the users file (userFile) and the htpasswd file.

require __DIR__.'/../lib/harness.php';

use nyu\Access\LoginMark;
use nyu\Access\Auth\PassList;
use nyu\Access\Auth\UserList;
use nyu\Tools\Registry;

$lmtFldr = 'temp'.DS.'markslimits_'.getmypid();
$mrkKeys = str_repeat('m', 40);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TRYLIMIT'=>Array('maxTries'=>0, 'dataPath'=>$lmtFldr, 'markSecr'=>$mrkKeys, 'markDays'=>90))) );
$markObj = LoginMark::fromLimitBlock();

/**
 * Tells what a check answers to a login, with or without a mark.
 *
 * @param Object $storeObjct The check.
 * @param String $userString The name.
 * @param String $passwdText The password.
 * @param String $markString The mark sent; '' for none.
 * @return Mixed The user, or false.
 */
function logsInto( Object $storeObjct, String $userString, String $passwdText, String $markString='' ): Mixed {
	$idntArr = $storeObjct->chckCredential( Array('user'=>$userString, 'pass'=>$passwdText, 'mark'=>$markString) );
	return is_array($idntArr) ? ($idntArr['user'] ?? null) : false;
}

// The mark itself
$annaMrk = $markObj->createUserMark('anna');
checkIdentical('a mark names its user, its time and its own id, signed', preg_match('/^[\w-]+\.\d+\.[0-9a-f]{16}\.[\w-]{43}$/D', $annaMrk), 1);
checkIdentical('read for its user: the key it is counted under', preg_match('/^mark:[0-9a-f]{16}$/D', $markObj->readClientMark($annaMrk, 'anna')), 1);
checkIdentical('another user, a forged signature, another key: no mark', Array($markObj->readClientMark($annaMrk, 'ben'), $markObj->readClientMark(substr($annaMrk, 0, -2).'xx', 'anna'), (new LoginMark(Array('markSecr'=>str_repeat('o', 40))))->readClientMark($annaMrk, 'anna')), Array('', '', ''));
$oldPart = explode('.', $annaMrk);
$oldText = $oldPart[0].'.'.(time() - 91*86400).'.'.$oldPart[2];
$oldMark = $oldText.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $oldText, $mrkKeys, true)), '+/', '-_'), '=');
checkIdentical('a mark older than markDays, or made before the password was last set, is no mark', Array($markObj->readClientMark($oldMark, 'anna'), $markObj->readClientMark($annaMrk, 'anna', time() + 10)), Array('', ''));
checkIdentical('without a key of 32 characters, no mark is made nor read', Array((new LoginMark(Array('markSecr'=>'short')))->createUserMark('anna'), (new LoginMark(Array()))->readClientMark($annaMrk, 'anna')), Array('', ''));

// The users file : 5 wrong passwords hold anna for the clients she never used
$lstConf = Array('listFile'=>'test/fixtures/users.lst', 'maxTries'=>5, 'holdSecs'=>600);
$usrList = new UserList( $lstConf );
for( $idx=0; $idx<5; $idx++ ){
	logsInto($usrList, 'anna', 'wrong-'.$idx);
}
checkIdentical('userFile: anna is held for a client without her mark', logsInto($usrList, 'anna', 'pw-anna'), false);
checkIdentical('userFile: a client holding her mark logs in, she being held', logsInto($usrList, 'anna', 'pw-anna', $annaMrk), 'anna');
checkIdentical('userFile: a mark of ben does not help anna', logsInto($usrList, 'anna', 'pw-anna', $markObj->createUserMark('ben')), false);
// 5 wrong passwords with the mark : the mark is refused, not the account
$benMark = $markObj->createUserMark('ben');
for( $idx=0; $idx<5; $idx++ ){
	logsInto($usrList, 'ben', 'wrong-'.$idx, $benMark);
}
checkIdentical('userFile: 5 wrong passwords with a mark leave the account free: ben logs in', logsInto($usrList, 'ben', 'pw-ben'), 'ben');
for( $idx=0; $idx<5; $idx++ ){
	logsInto($usrList, 'ben', 'other-'.$idx);
}
checkIdentical('userFile: ben then held, the mark refused no longer lets its client in; a mark not refused does', Array(logsInto($usrList, 'ben', 'pw-ben', $benMark), logsInto($usrList, 'ben', 'pw-ben', $markObj->createUserMark('ben'))), Array(false, 'ben'));
checkIdentical('userFile: a name written as a counter of a mark is the name of nobody', logsInto($usrList, 'mark:0011223344556677', 'x'), false);

// The htpasswd file
$htpList = new PassList( Array('passFile'=>'test/fixtures/users.htpasswd', 'maxTries'=>5, 'holdSecs'=>600) );
for( $idx=0; $idx<5; $idx++ ){
	logsInto($htpList, 'alice', 'wrong-'.$idx);
}
checkIdentical('htpasswd: alice held, her mark lets her client in', Array(logsInto($htpList, 'alice', 's3cret'), logsInto($htpList, 'alice', 's3cret', $markObj->createUserMark('alice'))), Array(false, 'alice'));

dropTestFolder( TOP_DIR.DS.$lmtFldr );
finishChecking();
