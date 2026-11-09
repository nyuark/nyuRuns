<?php

// Access\Auth\Native and UserBase : codes mailed as a second factor, set
// with emailTwoFactor(), mailed at the login through a 'tofile' account,
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
$foldNme = 'temp'.DS.'lgmails_'.getmypid();
$natConf = Array('tblnme'=>'users', 'mailer'=>'devbox', 'codexp'=>15, 'lnkurl'=>'https://app.example.ch/access?code=%code%');

/**
 * Sets the config: the sample database, a 'tofile' account, and a login on the native userbase.
 *
 * @param Array $nativeConf The block of the native userbase.
 */
function setsConfg( Array $nativeConf ): Void {
	global $sqlFile, $foldNme;
	$GLOBALS['nyuConfig'] = new Registry( Array(
		'database'=>Array('TYPE'=>'sqlte', 'HOST'=>'', 'USER'=>'', 'PASS'=>'', 'BASE'=>$sqlFile),
		'mailer'=>Array('DEVBOX'=>Array('type'=>'tofile', 'from'=>'no-reply@example.com', 'name'=>'nyuRuns', 'fold'=>'/'.str_replace(DS, '/', $foldNme))),
		'access'=>Array('LOGIN'=>Array('authby'=>'native', 'authcf'=>'native'), 'NATIVE'=>$nativeConf)
	) );
	Mailer::clearMailCache();
}

/**
 * Reads the mails written since the previous call, and removes them.
 *
 * @return Array The mails, their encodings undone.
 */
function takeMails(): Array {
	global $foldNme;
	$fleList = glob(TOP_DIR.DS.$foldNme.DS.'*.eml') ?: Array();
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
$sqlLink->exec("UPDATE users SET userMail='ben@example.ch' WHERE userName='ben'");
$sqlLink->exec("UPDATE users SET userMail='anna@example.ch', userLang='fr' WHERE userName='anna'");
$usrBase = new UserBase( $natConf );
$natAuth = new Native( $natConf );
$benLgin = Array('user'=>'ben', 'pass'=>'pw-ben', 'code'=>'');

// Set : an active user with an address, a mailer to send the codes
checkIdentical('mailTwoFa: an active user with an address', $usrBase->emailTwoFactor('ben'), true);
checkIdentical('mailTwoFa: the way is stored, nothing else yet', $sqlLink->query("SELECT twoFaWay, mailCode, mailDate FROM users WHERE userName='ben'")->fetch(PDO::FETCH_NUM), Array('mailed', null, null));
checkIdentical('mailTwoFa: refused for a user that is not active, unknown, or without an address', Array(
	Array($usrBase->emailTwoFactor('cleo'), $usrBase->lastStateCount(), $usrBase->finalErrorText()),
	Array($usrBase->emailTwoFactor('zed'), $usrBase->lastStateCount()),
	(function() use ( $sqlLink, $usrBase ){ $sqlLink->exec("UPDATE users SET userMail=NULL WHERE userName='dave'"); $sqlLink->exec("UPDATE users SET userStat='active' WHERE userName='dave'"); $rslt = Array($usrBase->emailTwoFactor('dave'), $usrBase->lastStateCount(), $usrBase->finalErrorText()); $sqlLink->exec("UPDATE users SET userStat='locked' WHERE userName='dave'"); return $rslt; })()
), Array(Array(false, 409, 'User not active'), Array(false, 404), Array(false, 409, 'User without a mail address')));
setsConfg( array_diff_key($natConf, Array('mailer'=>1)) );
checkIdentical('mailTwoFa: refused without a mailer', Array($usrBase->emailTwoFactor('anna'), $usrBase->lastStateCount(), $usrBase->finalErrorText()), Array(false, 409, 'No mail account sends the codes: mailer is not set'));
setsConfg( $natConf );

// The login : the password alone has a code mailed, the code ends it
Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs'), 'mode'=>'FULL') );
takeMails();
checkIdentical('login: the password alone asks for the code', Array($natAuth->chckCredential($benLgin), $natAuth->finalErrorText(), $natAuth->wrngCredential()), Array(false, 'Verification code required', false));
$mailLst = takeMails();
$benCode = codeOfMl($mailLst[0] ?? '');
checkIdentical('login: one mail, to the address of the user, with a code', Array(count($mailLst), preg_match('/\r\nTo: ben@example\.ch\r\nSubject: Your login code\r\n/', $mailLst[0] ?? ''), strlen($benCode)), Array(1, 1, 12));
checkIdentical('login: the mail has no link, and tells the minutes', Array(strpos($mailLst[0] ?? '', 'https://'), preg_match('/within 15 minutes/', $mailLst[0] ?? '')), Array(false, 1));
checkIdentical('login: only the hash of the code is stored, with its end', Array($sqlLink->query("SELECT mailCode FROM users WHERE userName='ben'")->fetchColumn()===hash('sha256', str_replace('-', '', $benCode)), abs(strtotime(strval($sqlLink->query("SELECT mailDate FROM users WHERE userName='ben'")->fetchColumn())) - time() - 900)<5), Array(true, true));
checkIdentical('login: asked again within the minute, the same code, no mail', Array($natAuth->chckCredential($benLgin), $natAuth->finalErrorText(), count(takeMails()), $sqlLink->query("SELECT mailCode FROM users WHERE userName='ben'")->fetchColumn()===hash('sha256', str_replace('-', '', $benCode))), Array(false, 'Verification code required', 0, true));
checkIdentical('login: a Basic header carries no code: nothing is mailed', Array($natAuth->chckCredential(Array('user'=>'ben', 'pass'=>'pw-ben')), $natAuth->finalErrorText(), count(takeMails())), Array(false, 'Verification code required', 0));
checkIdentical('login: a wrong password mails nothing', Array($natAuth->chckCredential(Array('user'=>'ben', 'pass'=>'nope', 'code'=>'')), $natAuth->finalErrorText(), count(takeMails())), Array(false, 'Invalid credentials', 0));
checkIdentical('login: a wrong code is refused and counted, after the wrong password', Array($natAuth->chckCredential(Array('code'=>'AAAA-BBBB-CC') + $benLgin), $natAuth->finalErrorText(), $natAuth->wrngCredential(), intval($sqlLink->query("SELECT failNmbr FROM users WHERE userName='ben'")->fetchColumn())), Array(false, 'Invalid verification code', true, 2));
checkIdentical('login: the code, whatever its case and dashes, ends the login', $natAuth->chckCredential(Array('code'=>strtolower(str_replace('-', '', $benCode))) + $benLgin), Array('user'=>'ben', 'role'=>'member', 'id'=>2));
checkIdentical('login: the code is cleared, and the failures with it', Array($sqlLink->query("SELECT mailCode, mailDate, failNmbr FROM users WHERE userName='ben'")->fetch(PDO::FETCH_NUM)), Array(Array(null, null, 0)));
checkIdentical('login: the same code a second time is refused', Array($natAuth->chckCredential(Array('code'=>$benCode) + $benLgin), $natAuth->finalErrorText()), Array(false, 'Invalid verification code'));
// The minute passed, a new code replaces the former one
$natAuth->chckCredential($benLgin);
$fstCode = codeOfMl(takeMails()[0] ?? '');
$sqlLink->exec("UPDATE users SET mailDate='".date('Y-m-d H:i:s', time() + 900 - 61)."' WHERE userName='ben'");
$natAuth->chckCredential($benLgin);
$sndCode = codeOfMl(takeMails()[0] ?? '');
checkIdentical('login: after a minute, a new code is mailed and the former one no longer works', Array($sndCode!='' && $sndCode!==$fstCode, $natAuth->chckCredential(Array('code'=>$fstCode) + $benLgin)), Array(true, false));
$sqlLink->exec("UPDATE users SET mailDate='".date('Y-m-d H:i:s', time() - 1)."' WHERE userName='ben'");
checkIdentical('login: an ended code is refused', Array($natAuth->chckCredential(Array('code'=>$sndCode) + $benLgin), $natAuth->finalErrorText()), Array(false, 'Invalid verification code'));
// The language of the user
$usrBase->emailTwoFactor('anna');
$natAuth->chckCredential( Array('user'=>'anna', 'pass'=>'pw-anna', 'code'=>'') );
checkPregMatch('login: the mail goes in the language of the user', takeMails()[0] ?? '', '/\r\nSubject: Votre code de connexion\r\n/');
// No mailer : the login of such a user cannot end
setsConfg( array_diff_key($natConf, Array('mailer'=>1)) );
checkIdentical('login: without a mailer, the store is unavailable for the user', Array($natAuth->chckCredential($benLgin), $natAuth->finalErrorText(), $natAuth->wrngCredential()), Array(false, 'Credential store unavailable', false));
setsConfg( $natConf );
$sqlLink->exec("UPDATE users SET userMail=NULL WHERE userName='anna'");
checkIdentical('login: nor without an address', Array($natAuth->chckCredential(Array('user'=>'anna', 'pass'=>'pw-anna', 'code'=>'')), $natAuth->finalErrorText()), Array(false, 'Credential store unavailable'));
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
checkIdentical('a new authenticator secret ends the mailed codes', Array($sqlLink->query("SELECT twoFaWay FROM users WHERE userName='ben'")->fetchColumn(), $natAuth->chckCredential(Array('code'=>nyu\Access\Totp::codeAtTimeText(strval($benScrt), time())) + $benLgin)['user'] ?? null), Array(null, 'ben'));
$usrBase->emailTwoFactor('ben');
checkIdentical('mailed codes end the authenticator secret', $sqlLink->query("SELECT twoFaKey, twoFaStp FROM users WHERE userName='ben'")->fetch(PDO::FETCH_NUM), Array(null, null));
$natAuth->chckCredential($benLgin);
takeMails();
checkIdentical('dropTwoFa takes either away', Array($usrBase->clearTwoFactor('ben'), $sqlLink->query("SELECT twoFaWay, mailCode, mailDate FROM users WHERE userName='ben'")->fetch(PDO::FETCH_NUM), $natAuth->chckCredential($benLgin)['user'] ?? null), Array(true, Array(null, null, null), 'ben'));

dropTestFolder( TOP_DIR.DS.$foldNme );
finishChecking();
