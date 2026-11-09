<?php

// Access\Auth\Native and Access\LoginMark : a client holding the mark of a
// user logs in while the account is held; its failures count on the mark;
// a password set after the mark was made ends it.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Native marks', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Access\LoginMark;
use nyu\Access\Auth\Native;
use nyu\Tools\Registry;

$sqlFile = inTemporaryDir('marks.sqlite');
createSampleDb( $sqlFile );
$lmtFldr = 'temp'.DS.'nativemarks_'.getmypid();
$GLOBALS['nyuConfig'] = new Registry( Array(
	'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>$sqlFile),
	'access'=>Array('TRYLIMIT'=>Array('maxTries'=>0, 'dataPath'=>$lmtFldr, 'markSecr'=>str_repeat('k', 40)))
) );
$natAuth = new Native( Array('useTable'=>'users', 'maxTries'=>5, 'holdSecs'=>600, 'minChars'=>8) );
$sqlLink = new PDO('sqlite:'.$sqlFile);
$benMark = LoginMark::fromLimitBlock()->createUserMark('ben');

/**
 * Tells what the userbase answers to a login.
 *
 * @param String $passwdText The password of ben.
 * @param String $markString The mark sent; '' for none.
 * @return Mixed The user, or false.
 */
function benLogin( String $passwdText, String $markString='' ): Mixed {
	global $natAuth;
	$idntArr = $natAuth->chckCredential( Array('user'=>'ben', 'pass'=>$passwdText, 'mark'=>$markString) );
	return is_array($idntArr) ? ($idntArr['user'] ?? null) : false;
}

for( $idx=0; $idx<5; $idx++ ){
	benLogin('wrong-'.$idx);
}
checkIdentical('native: ben held for a client without his mark', benLogin('pw-ben'), false);
checkIdentical('native: his mark lets its client in, the account held', benLogin('pw-ben', $benMark), 'ben');
$befFail = intval($sqlLink->query("SELECT failsCount FROM users WHERE userHandle='ben'")->fetchColumn());
benLogin('wrong-x', $benMark);
checkIdentical('native: a failure with the mark is not counted on the account', intval($sqlLink->query("SELECT failsCount FROM users WHERE userHandle='ben'")->fetchColumn()), $befFail);
checkIdentical('native: the owner logged in with his mark, the failures of the account are forgotten', intval($sqlLink->query("SELECT failsCount FROM users WHERE userHandle='ben'")->fetchColumn()), 0);
// A password set after the mark was made ends it : held again, the mark no longer lets its client in
$sqlLink->exec("UPDATE users SET passwdDate='".date('Y-m-d H:i:s', time() + 5)."' WHERE userHandle='ben'");
for( $idx=0; $idx<5; $idx++ ){
	benLogin('again-'.$idx);
}
checkIdentical('native: a mark made before the password was last set is no mark', benLogin('pw-ben', $benMark), false);

dropTestFolder( TOP_DIR.DS.$lmtFldr );
finishChecking();
