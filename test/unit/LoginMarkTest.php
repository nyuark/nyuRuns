<?php

// Access\LoginMark : the mark of a client already used by a user, which
// the hold of the account does not stop; its failures counted on the mark,
// refused in its turn; a mark of another user, forged, ended, made under
// another password or without a key helps nobody. On the users file
// (userFile) and the htpasswd file.

require __DIR__.'/../lib/harness.php';

use nyu\Access\LoginMark;
use nyu\Access\Auth\PassList;
use nyu\Access\Auth\UserList;
use nyu\Tools\Registry;

$lmtFldr = 'temp'.DS.'markslimits_'.getmypid();
$ownFldr = 'temp'.DS.'markfiles_'.getmypid();
@mkdir(TOP_DIR.DS.$ownFldr, 0775, true);
$mrkKeys = str_repeat('m', 40);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TRYLIMIT'=>Array('maxTries'=>0, 'dataPath'=>$lmtFldr, 'markSecr'=>$mrkKeys, 'markDays'=>90))) );
$markObj = LoginMark::makeByLimitCfg();
$fileVls = json_decode(strval(file_get_contents(TST_DIR.DS.'fixtures'.DS.'users.lst')), true);
$hashLst = array_column($fileVls['users'], 'passwdHash', 'systemName');

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
	$idntArr = $storeObjct->checkSecretDct( Array('user'=>$userString, 'pass'=>$passwdText, 'mark'=>$markString) );
	return is_array($idntArr) ? ($idntArr['userName'] ?? null) : false;
}

// The mark itself
$annaMrk = $markObj->makeSignedMark('anna', $hashLst['anna']);
checkIdentical('a mark names its user, its time and its own id, signed', preg_match('/^[\w-]+\.\d+\.[0-9a-f]{16}\.[\w-]{43}$/D', $annaMrk), 1);
checkIdentical('read for its user and the hash of its password: the key it is counted under', preg_match('/^mark:[0-9a-f]{16}$/D', $markObj->readClientMark($annaMrk, 'anna', $hashLst['anna'])), 1);
checkIdentical('another user, a forged signature, another key: no mark', Array($markObj->readClientMark($annaMrk, 'ben', $hashLst['anna']), $markObj->readClientMark(substr($annaMrk, 0, -2).'xx', 'anna', $hashLst['anna']), (new LoginMark(Array('markSecr'=>str_repeat('o', 40))))->readClientMark($annaMrk, 'anna', $hashLst['anna'])), Array('', '', ''));
checkIdentical('read with another hash of the password, or none: no mark', Array($markObj->readClientMark($annaMrk, 'anna', $hashLst['ben']), $markObj->readClientMark($annaMrk, 'anna', '')), Array('', ''));
checkIdentical('no part of the hash goes into the mark', strpos($annaMrk, substr($hashLst['anna'], 7, 22)), false);
$oldPart = explode('.', $annaMrk);
$oldText = $oldPart[0].'.'.(time() - 91*86400).'.'.$oldPart[2];
$oldMark = $oldText.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $oldText.'.'.$hashLst['anna'], $mrkKeys, true)), '+/', '-_'), '=');
checkIdentical('a mark older than markDays is no mark', $markObj->readClientMark($oldMark, 'anna', $hashLst['anna']), '');
checkIdentical('without a key of 32 characters, or without a hash, no mark is made nor read', Array((new LoginMark(Array('markSecr'=>'short')))->makeSignedMark('anna', $hashLst['anna']), $markObj->makeSignedMark('anna', ''), (new LoginMark(Array()))->readClientMark($annaMrk, 'anna', $hashLst['anna'])), Array('', '', ''));

// The users file : 5 wrong passwords hold anna for the clients she never used
$lstConf = Array('listFile'=>'test/fixtures/users.lst', 'maxTries'=>5, 'holdSecs'=>600);
$usrList = new UserList( $lstConf );
checkIdentical('userFile: the store makes the mark of the user it just accepted, and of nobody else', Array(logsInto($usrList, 'anna', 'pw-anna'), $usrList->makeSignedMark('anna')!=='', $usrList->makeSignedMark('ben')), Array('anna', true, ''));
logsInto($usrList, 'anna', 'pw-anna');
logsInto($usrList, 'ben', 'wrong');
checkIdentical('userFile: a check that failed leaves no mark for the user of the one before', $usrList->makeSignedMark('anna'), '');
for( $idx=0; $idx<5; $idx++ ){
	logsInto($usrList, 'anna', 'wrong-'.$idx);
}
checkIdentical('userFile: anna is held for a client without her mark', logsInto($usrList, 'anna', 'pw-anna'), false);
checkIdentical('userFile: a client holding her mark logs in, she being held', logsInto($usrList, 'anna', 'pw-anna', $annaMrk), 'anna');
checkIdentical('userFile: a mark of ben does not help anna', logsInto($usrList, 'anna', 'pw-anna', $markObj->makeSignedMark('ben', $hashLst['ben'])), false);
// 5 wrong passwords with the mark : the mark is refused, not the account
$benMark = $markObj->makeSignedMark('ben', $hashLst['ben']);
for( $idx=0; $idx<5; $idx++ ){
	logsInto($usrList, 'ben', 'wrong-'.$idx, $benMark);
}
checkIdentical('userFile: 5 wrong passwords with a mark leave the account free: ben logs in', logsInto($usrList, 'ben', 'pw-ben'), 'ben');
for( $idx=0; $idx<5; $idx++ ){
	logsInto($usrList, 'ben', 'other-'.$idx);
}
checkIdentical('userFile: ben then held, the mark refused no longer lets its client in; a mark not refused does', Array(logsInto($usrList, 'ben', 'pw-ben', $benMark), logsInto($usrList, 'ben', 'pw-ben', $markObj->makeSignedMark('ben', $hashLst['ben']))), Array(false, 'ben'));
checkIdentical('userFile: a name written as a counter of a mark is the name of nobody', logsInto($usrList, 'mark:0011223344556677', 'x'), false);

// A password changed : the marks made before it no longer lift the hold; one made after it does (a users file of the test's own)
$ownList = $ownFldr.DS.'users.lst';
file_put_contents(TOP_DIR.DS.$ownList, json_encode(Array('users'=>Array(Array('systemName'=>'gwen', 'passwdHash'=>password_hash('old-pass', PASSWORD_BCRYPT, Array('cost'=>4)))))));
$gwenLst = new UserList( Array('listFile'=>$ownList, 'maxTries'=>5, 'holdSecs'=>600) );
logsInto($gwenLst, 'gwen', 'old-pass');
$gwenOld = $gwenLst->makeSignedMark('gwen');
file_put_contents(TOP_DIR.DS.$ownList, json_encode(Array('users'=>Array(Array('systemName'=>'gwen', 'passwdHash'=>password_hash('new-pass', PASSWORD_BCRYPT, Array('cost'=>4)))))));
for( $idx=0; $idx<5; $idx++ ){
	logsInto($gwenLst, 'gwen', 'guess-'.$idx);
}
checkIdentical('userFile: gwen held, the mark made under her former password no longer lets its client in', Array(logsInto($gwenLst, 'gwen', 'new-pass'), logsInto($gwenLst, 'gwen', 'new-pass', $gwenOld)), Array(false, false));
$gwenHsh = json_decode(strval(file_get_contents(TOP_DIR.DS.$ownList)), true)['users'][0]['passwdHash'];
checkIdentical('userFile: a mark made after the change does', logsInto($gwenLst, 'gwen', 'new-pass', $markObj->makeSignedMark('gwen', $gwenHsh)), 'gwen');

// The htpasswd file
$htpList = new PassList( Array('passFile'=>'test/fixtures/users.htpasswd', 'maxTries'=>5, 'holdSecs'=>600) );
logsInto($htpList, 'alice', 's3cret');
$aliceMk = $htpList->makeSignedMark('alice');
for( $idx=0; $idx<5; $idx++ ){
	logsInto($htpList, 'alice', 'wrong-'.$idx);
}
checkIdentical('htpasswd: alice held, the mark the store made lets her client in', Array(logsInto($htpList, 'alice', 's3cret'), logsInto($htpList, 'alice', 's3cret', $aliceMk)), Array(false, 'alice'));
// A password changed in a file of the test's own
$ownPass = $ownFldr.DS.'users.htpasswd';
file_put_contents(TOP_DIR.DS.$ownPass, 'hugo:'.password_hash('old-pass', PASSWORD_BCRYPT, Array('cost'=>4))."\n");
$hugoLst = new PassList( Array('passFile'=>$ownPass, 'maxTries'=>5, 'holdSecs'=>600) );
logsInto($hugoLst, 'hugo', 'old-pass');
$hugoOld = $hugoLst->makeSignedMark('hugo');
$hugoHsh = password_hash('new-pass', PASSWORD_BCRYPT, Array('cost'=>4));
file_put_contents(TOP_DIR.DS.$ownPass, 'hugo:'.$hugoHsh."\n");
for( $idx=0; $idx<5; $idx++ ){
	logsInto($hugoLst, 'hugo', 'guess-'.$idx);
}
checkIdentical('htpasswd: hugo held, the mark made under his former password no longer lets its client in', Array($hugoOld!=='', logsInto($hugoLst, 'hugo', 'new-pass', $hugoOld)), Array(true, false));
checkIdentical('htpasswd: a mark made after the change does', logsInto($hugoLst, 'hugo', 'new-pass', $markObj->makeSignedMark('hugo', $hugoHsh)), 'hugo');

dropTestFolder( TOP_DIR.DS.$lmtFldr );
dropTestFolder( TOP_DIR.DS.$ownFldr );
finishChecking();
