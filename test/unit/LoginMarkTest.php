<?php

// Access\LoginMark : the mark of a client already used by a user, which
// the hold of the account does not stop; its failures counted on the mark,
// refused in its turn; a mark of another user, forged, ended or without a
// key helps nobody. On the users file (usrlst) and the htpasswd file.

require __DIR__.'/../lib/harness.php';

use nyu\Access\LoginMark;
use nyu\Access\Auth\PassList;
use nyu\Access\Auth\UserList;
use nyu\Tools\Registry;

$lmtFldr = 'temp'.DS.'markslimits_'.getmypid();
$mrkKeys = str_repeat('m', 40);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('LIMITS'=>Array('maxtry'=>0, 'folder'=>$lmtFldr, 'mrkkey'=>$mrkKeys, 'mrkday'=>90))) );
$markObj = LoginMark::fromLimit();

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
	$idntArr = $storeObjct->checkCred( Array('user'=>$userString, 'pass'=>$passwdText, 'mark'=>$markString) );
	return is_array($idntArr) ? ($idntArr['user'] ?? null) : false;
}

// The mark itself
$annaMrk = $markObj->makesMark('anna');
checkSame('a mark names its user, its time and its own id, signed', preg_match('/^[\w-]+\.\d+\.[0-9a-f]{16}\.[\w-]{43}$/D', $annaMrk), 1);
checkSame('read for its user: the key it is counted under', preg_match('/^mark:[0-9a-f]{16}$/D', $markObj->readsMark($annaMrk, 'anna')), 1);
checkSame('another user, a forged signature, another key: no mark', Array($markObj->readsMark($annaMrk, 'ben'), $markObj->readsMark(substr($annaMrk, 0, -2).'xx', 'anna'), (new LoginMark(Array('mrkkey'=>str_repeat('o', 40))))->readsMark($annaMrk, 'anna')), Array('', '', ''));
$oldPart = explode('.', $annaMrk);
$oldText = $oldPart[0].'.'.(time() - 91*86400).'.'.$oldPart[2];
$oldMark = $oldText.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $oldText, $mrkKeys, true)), '+/', '-_'), '=');
checkSame('a mark older than mrkday, or made before the password was last set, is no mark', Array($markObj->readsMark($oldMark, 'anna'), $markObj->readsMark($annaMrk, 'anna', time() + 10)), Array('', ''));
checkSame('without a key of 32 characters, no mark is made nor read', Array((new LoginMark(Array('mrkkey'=>'short')))->makesMark('anna'), (new LoginMark(Array()))->readsMark($annaMrk, 'anna')), Array('', ''));

// The users file : 5 wrong passwords hold anna for the clients she never used
$lstConf = Array('usrfle'=>'test/fixtures/users.lst', 'maxtry'=>5, 'holdtm'=>600);
$usrList = new UserList( $lstConf );
for( $idx=0; $idx<5; $idx++ ){
	logsInto($usrList, 'anna', 'wrong-'.$idx);
}
checkSame('usrlst: anna is held for a client without her mark', logsInto($usrList, 'anna', 'pw-anna'), false);
checkSame('usrlst: a client holding her mark logs in, she being held', logsInto($usrList, 'anna', 'pw-anna', $annaMrk), 'anna');
checkSame('usrlst: a mark of ben does not help anna', logsInto($usrList, 'anna', 'pw-anna', $markObj->makesMark('ben')), false);
// 5 wrong passwords with the mark : the mark is refused, not the account
$benMark = $markObj->makesMark('ben');
for( $idx=0; $idx<5; $idx++ ){
	logsInto($usrList, 'ben', 'wrong-'.$idx, $benMark);
}
checkSame('usrlst: 5 wrong passwords with a mark leave the account free: ben logs in', logsInto($usrList, 'ben', 'pw-ben'), 'ben');
for( $idx=0; $idx<5; $idx++ ){
	logsInto($usrList, 'ben', 'other-'.$idx);
}
checkSame('usrlst: ben then held, the mark refused no longer lets its client in; a mark not refused does', Array(logsInto($usrList, 'ben', 'pw-ben', $benMark), logsInto($usrList, 'ben', 'pw-ben', $markObj->makesMark('ben'))), Array(false, 'ben'));
checkSame('usrlst: a name written as a counter of a mark is the name of nobody', logsInto($usrList, 'mark:0011223344556677', 'x'), false);

// The htpasswd file
$htpList = new PassList( Array('htpasswd'=>'test/fixtures/users.htpasswd', 'maxtry'=>5, 'holdtm'=>600) );
for( $idx=0; $idx<5; $idx++ ){
	logsInto($htpList, 'alice', 'wrong-'.$idx);
}
checkSame('htpass: alice held, her mark lets her client in', Array(logsInto($htpList, 'alice', 's3cret'), logsInto($htpList, 'alice', 's3cret', $markObj->makesMark('alice'))), Array(false, 'alice'));

dropFoldr( TOP_DIR.DS.$lmtFldr );
endChecks();
