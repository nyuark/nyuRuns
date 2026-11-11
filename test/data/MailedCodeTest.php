<?php

// Access\Auth\Native and UserBase : codes mailed as a second factor, set
// with emailTwoFactor(), mailed at the login through a 'emlFiles' account,
// accepted once before their end, at most one mail a minute; and the
// commands that set them (accounts setTwoFa --emailOtp) or refuse them.

require __DIR__.'/../lib/harness.php';
require __DIR__.'/../lib/sample.php';

if( !extension_loaded('pdo_sqlite') ){
	recordSkipping('Mailed codes', 'pdo_sqlite is not available');
	finishChecking();
}

use nyu\Access\Auth\Native;
use nyu\Access\Auth\UserBase;
use nyu\Data\Mail\Mailer;
use nyu\Tools\Logger;
use nyu\Tools\Registry;

$sqlFile = inTemporaryDir('mailed.sqlite');
createSampleDb( $sqlFile );
$fldrNme = 'temp'.DS.'lgmails_'.getmypid();
$natConf = Array('useTable'=>'users', 'mailAcnt'=>'devbox', 'codeMins'=>15, 'linkTmpl'=>'https://app.example.ch/access?code=%code%');

/**
 * Sets the config: the sample database, a 'emlFiles' account, and a login on the native userbase.
 *
 * @param Array $nativeConf The block of the native userbase.
 */
function setsConfg( Array $nativeConf ): Void {
	global $sqlFile, $fldrNme;
	$GLOBALS['nyuConfig'] = new Registry( Array(
		'database'=>Array('DBENGINE'=>'sqlitedb', 'HOSTNAME'=>'', 'USERNAME'=>'', 'PASSWORD'=>'', 'BASENAME'=>$sqlFile),
		'mailer'=>Array('DEVBOX'=>Array('sendWith'=>'emlFiles', 'fromAddr'=>'no-reply@example.com', 'fromName'=>'nyuRuns', 'mailsDir'=>'/'.str_replace(DS, '/', $fldrNme))),
		'access'=>Array('LOGINSET'=>Array('authWith'=>'nativeDb', 'authConf'=>'nativeDb'), 'NATIVEDB'=>$nativeConf)
	) );
	Mailer::clearMailCache();
}

/**
 * Reads the mails written since the previous call, and removes them.
 *
 * @return Array The mails, their encodings undone.
 */
function takeMails(): Array {
	global $fldrNme;
	$fleList = glob(TOP_DIR.DS.$fldrNme.DS.'*.eml') ?: Array();
	sort($fleList);
	$mailLst = Array();
	foreach( $fleList as $fleNme ){
		$mailLst[] = preg_replace_callback('/Content-Transfer-Encoding: (base64|quoted-printable)\r\n\r\n(.*?)(?=\r\n--|$)/s', function( Array $matchedArr ): String {
			return ($matchedArr[1]=='base64') ? base64_decode(str_replace("\r\n", '', $matchedArr[2])) : quoted_printable_decode($matchedArr[2]);
		}, strval(file_get_contents($fleNme)));
		unlink($fleNme);
	}
	return $mailLst;
}

/**
 * Gives the code a mail holds.
 *
 * @param String $mailString The mail.
 * @return String The code, empty when there is none.
 */
function codeOfMl( String $mailString ): String {
	return preg_match('/\b([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{2})\b/', $mailString, $codeMatch) ? $codeMatch[1] : '';
}

setsConfg( $natConf );
$sqlLink = new PDO('sqlite:'.$sqlFile);
$sqlLink->exec("UPDATE users SET contactEml='ben@example.ch' WHERE systemName='ben'");
$sqlLink->exec("UPDATE users SET contactEml='anna@example.ch', userLocale='fr' WHERE systemName='anna'");
$usrBase = new UserBase( $natConf );
$natAuth = new Native( $natConf );
$benLgin = Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>'');

// Set : an active user with an address, a mailer to send the codes
checkIdentical('mailTwoFa: an active user with an address', $usrBase->emailTwoFactor('ben'), true);
checkIdentical('mailTwoFa: the way is stored, nothing else yet', $sqlLink->query("SELECT twoFactWay, twoFactHsh, twoFactExp FROM users WHERE systemName='ben'")->fetch(PDO::FETCH_NUM), Array('emailOtp', null, null));
checkIdentical('mailTwoFa: refused for a user that is not active, unknown, or without an address', Array(
	Array($usrBase->emailTwoFactor('cleo'), $usrBase->lastStateCount(), $usrBase->finalErrorText()),
	Array($usrBase->emailTwoFactor('zed'), $usrBase->lastStateCount()),
	(function() use ( $sqlLink, $usrBase ){ $sqlLink->exec("UPDATE users SET contactEml=NULL WHERE systemName='dave'"); $sqlLink->exec("UPDATE users SET userStatus='approved' WHERE systemName='dave'"); $rslt = Array($usrBase->emailTwoFactor('dave'), $usrBase->lastStateCount(), $usrBase->finalErrorText()); $sqlLink->exec("UPDATE users SET userStatus='disabled' WHERE systemName='dave'"); return $rslt; })()
), Array(Array(false, 409, 'User not active'), Array(false, 404), Array(false, 409, 'User without a mail address')));
setsConfg( array_diff_key($natConf, Array('mailAcnt'=>1)) );
checkIdentical('mailTwoFa: refused without a mailer', Array($usrBase->emailTwoFactor('anna'), $usrBase->lastStateCount(), $usrBase->finalErrorText()), Array(false, 409, 'No mail account sends the codes: mailer is not set'));
setsConfg( $natConf );

// The login : the password alone has a code mailed, the code ends it
Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs'), 'mode'=>'FULL') );
takeMails();
checkIdentical('login: the password alone asks for the code', Array($natAuth->checkSecretArr($benLgin), $natAuth->finalErrorText(), $natAuth->hasWrongSecret()), Array(false, 'Verification code required', false));
$mailLst = takeMails();
$benCode = codeOfMl($mailLst[0] ?? '');
checkIdentical('login: one mail, to the address of the user, with a code', Array(count($mailLst), preg_match('/\r\nTo: ben@example\.ch\r\nSubject: Your login code\r\n/', $mailLst[0] ?? ''), strlen($benCode)), Array(1, 1, 12));
checkIdentical('login: the mail has no link, and tells the minutes', Array(strpos($mailLst[0] ?? '', 'https://'), preg_match('/within 15 minutes/', $mailLst[0] ?? '')), Array(false, 1));
checkIdentical('login: only the hash of the code is stored, with its end', Array($sqlLink->query("SELECT twoFactHsh FROM users WHERE systemName='ben'")->fetchColumn()===hash('sha256', str_replace('-', '', $benCode)), abs(strtotime(strval($sqlLink->query("SELECT twoFactExp FROM users WHERE systemName='ben'")->fetchColumn())) - time() - 900)<5), Array(true, true));
checkIdentical('login: asked again within the minute, the same code, no mail', Array($natAuth->checkSecretArr($benLgin), $natAuth->finalErrorText(), count(takeMails()), $sqlLink->query("SELECT twoFactHsh FROM users WHERE systemName='ben'")->fetchColumn()===hash('sha256', str_replace('-', '', $benCode))), Array(false, 'Verification code required', 0, true));
checkIdentical('login: a Basic header carries no code: nothing is mailed', Array($natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'pw-ben')), $natAuth->finalErrorText(), count(takeMails())), Array(false, 'Verification code required', 0));
checkIdentical('login: a wrong password mails nothing', Array($natAuth->checkSecretArr(Array('user'=>'ben', 'pass'=>'nope', 'code'=>'')), $natAuth->finalErrorText(), count(takeMails())), Array(false, 'Invalid credentials', 0));
checkIdentical('login: a wrong code is refused and counted, after the wrong password', Array($natAuth->checkSecretArr(Array('code'=>'AAAA-BBBB-CC') + $benLgin), $natAuth->finalErrorText(), $natAuth->hasWrongSecret(), intval($sqlLink->query("SELECT failsCount FROM users WHERE systemName='ben'")->fetchColumn())), Array(false, 'Invalid verification code', true, 2));
checkIdentical('login: the code, whatever its case and dashes, ends the login', $natAuth->checkSecretArr(Array('code'=>strtolower(str_replace('-', '', $benCode))) + $benLgin), Array('userName'=>'ben', 'userRole'=>'standard', 'idOfUser'=>2));
checkIdentical('login: the code is cleared, and the failures with it', Array($sqlLink->query("SELECT twoFactHsh, twoFactExp, failsCount FROM users WHERE systemName='ben'")->fetch(PDO::FETCH_NUM)), Array(Array(null, null, 0)));
checkIdentical('login: the same code a second time is refused', Array($natAuth->checkSecretArr(Array('code'=>$benCode) + $benLgin), $natAuth->finalErrorText()), Array(false, 'Invalid verification code'));
// The minute passed, a new code replaces the former one
$natAuth->checkSecretArr($benLgin);
$fstCode = codeOfMl(takeMails()[0] ?? '');
$sqlLink->exec("UPDATE users SET twoFactExp='".date('Y-m-d H:i:s', time() + 900 - 61)."' WHERE systemName='ben'");
$natAuth->checkSecretArr($benLgin);
$sndCode = codeOfMl(takeMails()[0] ?? '');
checkIdentical('login: after a minute, a new code is mailed and the former one no longer works', Array($sndCode!='' && $sndCode!==$fstCode, $natAuth->checkSecretArr(Array('code'=>$fstCode) + $benLgin)), Array(true, false));
$sqlLink->exec("UPDATE users SET twoFactExp='".date('Y-m-d H:i:s', time() - 1)."' WHERE systemName='ben'");
checkIdentical('login: an ended code is refused', Array($natAuth->checkSecretArr(Array('code'=>$sndCode) + $benLgin), $natAuth->finalErrorText()), Array(false, 'Invalid verification code'));
// The language of the user
$usrBase->emailTwoFactor('anna');
$natAuth->checkSecretArr( Array('user'=>'anna', 'pass'=>'pw-anna', 'code'=>'') );
checkPregMatch('login: the mail goes in the language of the user', takeMails()[0] ?? '', '/\r\nSubject: Votre code de connexion\r\n/');
// No mailer : the login of such a user cannot end
setsConfg( array_diff_key($natConf, Array('mailAcnt'=>1)) );
checkIdentical('login: without a mailer, the store is unavailable for the user', Array($natAuth->checkSecretArr($benLgin), $natAuth->finalErrorText(), $natAuth->hasWrongSecret()), Array(false, 'Credential store unavailable', false));
setsConfg( $natConf );
$sqlLink->exec("UPDATE users SET contactEml=NULL WHERE systemName='anna'");
checkIdentical('login: nor without an address', Array($natAuth->checkSecretArr(Array('user'=>'anna', 'pass'=>'pw-anna', 'code'=>'')), $natAuth->finalErrorText()), Array(false, 'Credential store unavailable'));
Logger::plugLogsConfig( Array() );
$logText = strval(@file_get_contents(inTemporaryDir('logs').DS.'access.log'));
checkIdentical('the log tells the mails, the refusals and what cannot be sent', Array(
	preg_match('/\[INFO\] Verification code mailed to user "ben"/', $logText),
	preg_match('/\[WARNING\] Verification code refused: wrong or ended for user "ben"/', $logText),
	preg_match('/\[ERROR\] Verification code of user "anna" not sent: no mailer or no address/', $logText),
	preg_match('/\[NOTICE\] Mailed codes set for user "anna"/', $logText),
	strpos($logText, $benCode)
), Array(1, 1, 1, 1, false));

// The authenticator and mailed codes : one or the other
$benScrt = $usrBase->buildTwoFactor('ben');
checkIdentical('a new authenticator secret ends the mailed codes', Array($sqlLink->query("SELECT twoFactWay FROM users WHERE systemName='ben'")->fetchColumn(), $natAuth->checkSecretArr(Array('code'=>nyu\Access\Totp::codeAtTimeText(strval($benScrt), time())) + $benLgin)['userName'] ?? null), Array(null, 'ben'));
$usrBase->emailTwoFactor('ben');
checkIdentical('mailed codes end the authenticator secret', $sqlLink->query("SELECT twoFactKey, twoFactPos FROM users WHERE systemName='ben'")->fetch(PDO::FETCH_NUM), Array(null, null));
$natAuth->checkSecretArr($benLgin);
takeMails();
checkIdentical('dropTwoFa takes either away', Array($usrBase->clearTwoFactor('ben'), $sqlLink->query("SELECT twoFactWay, twoFactHsh, twoFactExp FROM users WHERE systemName='ben'")->fetch(PDO::FETCH_NUM), $natAuth->checkSecretArr($benLgin)['userName'] ?? null), Array(true, Array(null, null, null), 'ben'));

dropTestFolder( TOP_DIR.DS.$fldrNme );
finishChecking();
