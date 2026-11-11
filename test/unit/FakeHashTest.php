<?php

// Access\Auth : what an unknown name is checked against. The native
// userbase, a hash of Native::FAKEHASH, written in the source at the cost
// of new hashes; the users file and the htpasswd file, the hash of their
// first entry, checked as a real one is. No hash is made on that path.

// Counted before any call of the script : PHP keeps the function it resolved for each call site
eval('namespace nyu\Access\Auth; function password_hash( ...$hashArgs ){ $GLOBALS["hashMade"]++; return \password_hash(...$hashArgs); } function password_verify( String $passwdText, String $storedHash ): Bool { $GLOBALS["hashRead"][] = substr($storedHash, 0, 7); return \password_verify($passwdText, $storedHash); }');
$GLOBALS['hashMade'] = 0;
$GLOBALS['hashRead'] = Array();

require __DIR__.'/../lib/harness.php';

use nyu\Access\Auth\Native;
use nyu\Access\Auth\PassList;
use nyu\Access\Auth\UserList;
use nyu\Tools\Registry;

$lmtFldr = 'temp'.DS.'fakehash_'.getmypid();
$ownFldr = 'temp'.DS.'fakefile_'.getmypid();
@mkdir(TOP_DIR.DS.$ownFldr, 0775, true);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('TRYLIMIT'=>Array('maxTries'=>0, 'dataPath'=>$lmtFldr))) );

// The constant : one hash of each cost, the one of PHP's new hashes given
$dfltCst = password_get_info(\password_hash('x', PASSWORD_BCRYPT))['options']['cost'] ?? 0;
checkIdentical('FAKEHASH holds a bcrypt hash at cost 10 and at cost 12', Array(password_get_info(Native::FAKE_HASH[10])['options']['cost'] ?? 0, password_get_info(Native::FAKE_HASH[12])['options']['cost'] ?? 0), Array(10, 12));
checkIdentical('the dummy is the constant at the cost of new hashes, the same at each call', Array(Native::getDummyBcrypt(), Native::getDummyBcrypt()), Array_fill(0, 2, Native::FAKE_HASH[in_array($dfltCst, Array(10, 12), true) ? $dfltCst : 12]));
checkIdentical('no hash is made to give it', $GLOBALS['hashMade'], 0);

// The users file : an unknown name is checked against the hash of its first user
$usrList = new UserList( Array('listFile'=>'test/fixtures/users.lst', 'maxTries'=>0) );
$GLOBALS['hashRead'] = Array();
$usrList->checkSecretArr( Array('user'=>'nobody', 'pass'=>'x') );
checkIdentical('userFile: an unknown name is checked against the first hash of the file (cost 4), and no hash is made', Array($GLOBALS['hashRead'], $GLOBALS['hashMade']), Array(Array('$2y$04$'), 0));
file_put_contents(TOP_DIR.DS.$ownFldr.DS.'users.lst', json_encode(Array('users'=>Array(Array('systemName'=>'void', 'passwdHash'=>'!')))));
$GLOBALS['hashRead'] = Array();
(new UserList( Array('listFile'=>$ownFldr.DS.'users.lst', 'maxTries'=>0) ))->checkSecretArr( Array('user'=>'nobody', 'pass'=>'x') );
checkIdentical('userFile: without a hashed user, the dummy of the native userbase', $GLOBALS['hashRead'], Array(substr(Native::getDummyBcrypt(), 0, 7)));

// The htpasswd file : the first entry, through the same verify as a real user
/**
 * A password file that counts the apr1 hashes it computes.
 */
class CountingPass extends PassList{
	public $apr1Made = 0;

	/**
	 * Counts, then computes.
	 *
	 * @param String $usrPasswdTxt The password to hash.
	 * @param String $hashSaltText The salt.
	 * @return String The hash.
	 */
	protected function computeApr1Md5( String $usrPasswdTxt, String $hashSaltText ): String {
		$this->apr1Made++;
		return parent::computeApr1Md5($usrPasswdTxt, $hashSaltText);
	}
}
$GLOBALS['hashRead'] = Array();
(new PassList( Array('passFile'=>'test/fixtures/users.htpasswd', 'maxTries'=>0) ))->checkSecretArr( Array('user'=>'nobody', 'pass'=>'x') );
checkIdentical('htpasswd: an unknown name is checked against the first entry (bcrypt, cost 10), the comment skipped, and no hash is made', Array($GLOBALS['hashRead'], $GLOBALS['hashMade']), Array(Array('$2y$10$'), 0));
file_put_contents(TOP_DIR.DS.$ownFldr.DS.'apr1.htpasswd', "erin:\$apr1\$Qm3xT7pa\$L0.gnJFaxziiXlHqa4mWJ/\nolga:\$apr1\$Qm3xT7pa\$L0.gnJFaxziiXlHqa4mWJ/\n");
$aprList = new CountingPass( Array('passFile'=>$ownFldr.DS.'apr1.htpasswd', 'maxTries'=>0) );
$GLOBALS['hashRead'] = Array();
$aprList->checkSecretArr( Array('user'=>'nobody', 'pass'=>'x') );
checkIdentical('htpasswd of apr1 entries: an unknown name computes one apr1 hash and no bcrypt', Array($aprList->apr1Made, $GLOBALS['hashRead']), Array(1, Array()));
$aprList->checkSecretArr( Array('user'=>'erin', 'pass'=>'wrong') );
checkIdentical('htpasswd of apr1 entries: as a known name does', $aprList->apr1Made, 2);

dropTestFolder( TOP_DIR.DS.$lmtFldr );
dropTestFolder( TOP_DIR.DS.$ownFldr );
finishChecking();
