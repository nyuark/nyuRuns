<?php

// Access\AccessMail : the mails of the access flows, written by a 'tofile'
// account, from the templates of conf/mails or of the application.

require __DIR__.'/../lib/harness.php';

use nyu\Access\AccessMail;
use nyu\Data\Mail\Mailer;
use nyu\Tools\Logger;
use nyu\Tools\Registry;

$foldNme = 'temp'.DS.'accmails_'.getmypid();
$fileSet = Array('type'=>'tofile', 'from'=>'no-reply@example.com', 'name'=>'nyuRuns', 'fold'=>'/'.str_replace(DS, '/', $foldNme));
$natConf = Array('tblnme'=>'users', 'mailer'=>'devbox', 'codexp'=>20, 'lnkurl'=>'https://app.example.ch/access?do=%kind%&user=%user%&code=%code%');

/**
 * Sets the config: a 'tofile' account, and a login on the native userbase.
 *
 * @param Array $nativeConf The block of the native userbase.
 */
function setsConfg( Array $nativeConf ): Void {
	global $fileSet;
	$GLOBALS['nyuConfig'] = new Registry( Array(
		'mailer'=>Array('DEVBOX'=>$fileSet),
		'access'=>Array('LOGIN'=>Array('authby'=>'native', 'authcf'=>'native'), 'NATIVE'=>$nativeConf)
	) );
	Mailer::clearMailCache();
}

/**
 * Reads the mails written since the previous call, and removes them.
 *
 * @return Array The mails, as written.
 */
function takeMails(): Array {
	global $foldNme;
	$fleList = glob(TOP_DIR.DS.$foldNme.DS.'*.eml') ?: Array();
	sort($fleList);
	$mailLst = Array();
	foreach( $fleList as $fleNme ){
		$mailLst[] = strval(file_get_contents($fleNme));
		unlink($fleNme);
	}
	return $mailLst;
}

/**
 * Gives the parts of a written mail, its encodings undone.
 *
 * @param String $mailString The mail, as written.
 * @return String Its headers and the decoded text of its parts.
 */
function readMail( String $mailString ): String {
	return preg_replace_callback('/Content-Transfer-Encoding: (base64|quoted-printable)\r\n\r\n(.*?)(?=\r\n--|$)/s', function( Array $matchedArr ): String {
		return ($matchedArr[1]=='base64') ? base64_decode(str_replace("\r\n", '', $matchedArr[2])) : quoted_printable_decode($matchedArr[2]);
	}, $mailString);
}

// Who sends : only a login on the native userbase whose block names an account
setsConfg($natConf);
checkTrueValue('fromLogin: a block naming an account', AccessMail::fromLoginBlock() instanceof AccessMail);
setsConfg( array_diff_key($natConf, Array('mailer'=>1)) );
checkIdentical('fromLogin: no mailer, no mail', AccessMail::fromLoginBlock(), null);
setsConfg( Array('mailer'=>'') + $natConf );
checkIdentical('fromLogin: an empty mailer, no mail', AccessMail::fromLoginBlock(), null);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('LOGIN'=>Array('authby'=>'usrlst', 'authcf'=>'usrlst'), 'USRLST'=>$natConf)) );
checkIdentical('fromLogin: another credential check', AccessMail::fromLoginBlock(), null);
setsConfg( Array('signup'=>true) + $natConf );
checkIdentical('signupsOn: signup and a mailer', AccessMail::signupsAllowed(), true);
setsConfg( Array('signup'=>true, 'mailer'=>'') + $natConf );
checkIdentical('signupsOn: without a mailer', AccessMail::signupsAllowed(), false);
setsConfg( Array('signup'=>'yes') + $natConf );
checkIdentical('signupsOn: only true opens them', AccessMail::signupsAllowed(), false);
setsConfg($natConf);
checkIdentical('signupsOn: left out', AccessMail::signupsAllowed(), false);

// A code, with its link
$accMail = AccessMail::fromLoginBlock();
takeMails();
checkIdentical('sendCodes: sent', $accMail->sendCodeToUser('signup', 'Ann.B', 'ann@example.ch', 'K7QM-3XRP-WD'), true);
$mailLst = takeMails();
$mailTxt = readMail($mailLst[0] ?? '');
checkIdentical('sendCodes: one mail', count($mailLst), 1);
checkPregMatch('sendCodes: to the address, the first line of the template as subject', $mailTxt, '/\r\nTo: ann@example\.ch\r\nSubject: Your sign-up: confirm your address\r\n/');
checkPregMatch('sendCodes: the code and the minutes of codexp', $mailTxt, '/    K7QM-3XRP-WD\r?\n.*within 20 minutes/s');
checkPregMatch('sendCodes: the link, its values encoded', $mailTxt, '/https:\/\/app\.example\.ch\/access\?do=signup&user=Ann\.B&code=K7QM-3XRP-WD/');
checkPregMatch('sendCodes: an HTML part with the link', $mailTxt, '/<a href="https:\/\/app\.example\.ch\/access\?do=signup&amp;user=Ann\.B&amp;code=K7QM-3XRP-WD">this link<\/a>/');
checkIdentical('sendCodes: no marker left', preg_match('/\[\/?link\]|%(user|code|link|mins)%/', $mailTxt), 0);
checkIdentical('sendCodes: a mail that is not one of its kinds', $accMail->sendCodeToUser('taken', 'ann', 'ann@example.ch', 'X'), false);
foreach( Array('actvte'=>'Your account: choose your password', 'pwdset'=>'Your password: choose a new one') as $mailKnd=>$subjTxt ){
	$accMail->sendCodeToUser($mailKnd, 'ann', 'ann@example.ch', 'AAAA-BBBB-CC');
	checkPregMatch('sendCodes: the mail '.$mailKnd, readMail(takeMails()[0] ?? ''), '/Subject: '.preg_quote($subjTxt, '/').'\r\n.*do='.$mailKnd.'&user=ann&code=AAAA-BBBB-CC/s');
}

// The code of a login : typed where it was asked, never with a link
$accMail->sendCodeToUser('lgcode', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC');
$mailTxt = readMail(takeMails()[0] ?? '');
checkIdentical('sendCodes: the code of a login, without a link though lnkurl is set', Array(preg_match('/Subject: Your login code\r\n/', $mailTxt), strpos($mailTxt, 'AAAA-BBBB-CC')!==false, strpos($mailTxt, 'https://'), preg_match('/within 20 minutes/', $mailTxt)), Array(1, true, false, 1));
checkPregMatch('sendCodes: the code of a login, in French', readMail($accMail->sendCodeToUser('lgcode', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC', 'fr') ? (takeMails()[0] ?? '') : ''), '/Subject: Votre code de connexion\r\n.*dans les 20 minutes/s');

// Without a link, the code alone
$accMail = new AccessMail( array_diff_key($natConf, Array('lnkurl'=>1)) );
$accMail->sendCodeToUser('pwdset', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC');
$mailTxt = readMail(takeMails()[0] ?? '');
checkIdentical('no lnkurl: the code, neither a link nor its sentence', Array(strpos($mailTxt, 'AAAA-BBBB-CC')!==false, strpos($mailTxt, 'open this link'), strpos($mailTxt, 'https://')), Array(true, false, false));
checkPregMatch('no lnkurl: the default minutes of a mailed code', readMail(strval((new AccessMail(Array('mailer'=>'devbox')))->sendCodeToUser('pwdset', 'a', 'a@example.ch', 'C') ? (takeMails()[0] ?? '') : '')), '/within 15 minutes/');

// A name taken, told to an address of no account
$accMail = new AccessMail($natConf);
checkIdentical('sendNamed: sent', $accMail->sendNameExists('new@example.ch', 'ann'), true);
$mailTxt = readMail(takeMails()[0] ?? '');
checkIdentical('sendNamed: to the address, the name asked for, nothing of an account', Array(preg_match('/\r\nTo: new@example\.ch\r\nSubject: Your sign-up: choose another name\r\n/', $mailTxt), strpos($mailTxt, 'as ann with')!==false, stripos($mailTxt, 'account')), Array(1, true, false));
checkPregMatch('sendNamed: in French', readMail($accMail->sendNameExists('new@example.ch', 'ann', 'fr') ? (takeMails()[0] ?? '') : ''), '/Subject: Votre inscription : choisissez un autre nom\r\n/');

// A name or an address taken
$accMail = new AccessMail($natConf);
checkIdentical('sendTaken: sent', $accMail->sendAddrExists('ann@example.ch', '<b>ann</b>'), true);
$mailTxt = readMail(takeMails()[0] ?? '');
checkPregMatch('sendTaken: its subject, the name asked for, encoded in the HTML part', $mailTxt, '/Subject: Your sign-up\r\n.*sign up as <b>ann<\/b> with.*<strong>&lt;b&gt;ann&lt;\/b&gt;<\/strong>/s');

// The managers told that sign-ups pile up : one mail each
$accMail = new AccessMail($natConf);
checkIdentical('sendWatch: sent', $accMail->sendQueueAlert(Array('anna@example.ch', 'ben@example.ch'), 128, 256), true);
$mailLst = array_map('readMail', takeMails());
$sentTo = array_map(function( String $mailString ): String { return preg_match('/\r\nTo: (\S+)\r\n/', $mailString, $toMatch) ? $toMatch[1] : ''; }, $mailLst);
sort($sentTo);
checkIdentical('sendWatch: one mail for each manager', $sentTo, Array('anna@example.ch', 'ben@example.ch'));
checkPregMatch('sendWatch: the count and the limit', $mailLst[0] ?? '', '/Subject: Sign-ups waiting for review: 128\r\n.*When 256 are waiting/s');
checkIdentical('sendWatch: nobody to tell', $accMail->sendQueueAlert(Array(), 128, 256), true);

// The language of the user, along its chain
$accMail = new AccessMail($natConf);
$accMail->sendCodeToUser('signup', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC', 'fr-ch');
$mailTxt = readMail(takeMails()[0] ?? '');
checkPregMatch('language: fr-ch is written in fr, text and HTML', $mailTxt, '/Subject: Votre inscription : confirmez votre adresse\r\n.*Quelqu\'un \(vous, on l\'esp.*<a href="https:\/\/app\.example\.ch\/access\?do=signup&amp;user=ann&amp;code=AAAA-BBBB-CC">ce lien<\/a>/s');
checkPregMatch('language: one that is not delivered falls back to the default', readMail($accMail->sendAddrExists('ann@example.ch', 'ann', 'de-at') ? (takeMails()[0] ?? '') : ''), '/Subject: Your sign-up\r\n/');
checkPregMatch('language: one that is not a tag is the default', readMail($accMail->sendAddrExists('ann@example.ch', 'ann', '../fr') ? (takeMails()[0] ?? '') : ''), '/Subject: Your sign-up\r\n/');
$GLOBALS['nyuConfig'] = new Registry( Array('mailer'=>Array('DEVBOX'=>$fileSet), 'system'=>Array('LNG_DFLT'=>'fr')) );
checkPregMatch('language: LNG_DFLT when the user has none', readMail((new AccessMail($natConf))->sendAddrExists('ann@example.ch', 'ann') ? (takeMails()[0] ?? '') : ''), '/Subject: Votre inscription\r\n/');
setsConfg($natConf);
$accMail = new AccessMail($natConf);
$sentOk = $accMail->sendQueueAlert(Array('anna@example.ch'=>'fr', 'ben@example.ch'=>null), 3, 9);
$sentTo = array_map(function( String $mailString ): String { return preg_match('/\r\nTo: (\S+)\r\n.*?(attente|waiting)/s', readMail($mailString), $toMatch) ? $toMatch[1].' '.$toMatch[2] : ''; }, takeMails());
sort($sentTo);
checkIdentical('sendWatch: the language of each manager', Array($sentOk, $sentTo), Array(true, Array('anna@example.ch attente', 'ben@example.ch waiting')));

// The templates of the application come first, language by language
$appFldr = inTemporaryDir('Probe');
mkdir($appFldr.DS.'mails'.DS.'fr', 0777, true);
file_put_contents($appFldr.DS.'mails'.DS.'fr'.DS.'pwdset.txt', "Mot de passe\nBonjour %user%, votre code : %code%\n");
$accMail = new AccessMail($natConf, $appFldr);
$accMail->sendCodeToUser('pwdset', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC', 'fr');
$mailTxt = takeMails()[0] ?? '';
checkIdentical('application template: its subject and text, no HTML part without its .html', Array(preg_match('/Subject: Mot de passe\r\n/', $mailTxt), strpos(readMail($mailTxt), 'Bonjour ann, votre code : AAAA-BBBB-CC')!==false, strpos($mailTxt, 'text/html')), Array(1, true, false));
$accMail->sendCodeToUser('actvte', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC', 'fr');
checkPregMatch('application template: conf/mails in the same language for the others', readMail(takeMails()[0] ?? ''), '/Subject: Votre compte : choisissez votre mot de passe\r\n/');
$accMail->sendCodeToUser('pwdset', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC');
checkPregMatch('application template: another language is not given its text', readMail(takeMails()[0] ?? ''), '/Subject: Your password: choose a new one/');

// After the reply : kept, then sent at the end of the request
$accMail = new AccessMail($natConf, '', true);
checkIdentical('after the reply: kept', Array($accMail->sendCodeToUser('signup', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC'), $accMail->sendAddrExists('bob@example.ch', 'bob'), count(takeMails())), Array(true, true, 0));
ob_start();
$accMail->sendQueuedMail();
$sentTo = array_map(function( String $mailString ): String { return preg_match('/\r\nTo: (\S+)\r\n/', $mailString, $toMatch) ? $toMatch[1] : ''; }, takeMails());
sort($sentTo);
checkIdentical('after the reply: sent', $sentTo, Array('ann@example.ch', 'bob@example.ch'));

// What cannot be sent is logged, never thrown
Logger::plugLogsConfig( Array('path'=>inTemporaryDir('logs')) );
$accMail = new AccessMail( Array('mailer'=>'nobody') );
checkIdentical('an unknown account: not sent', $accMail->sendCodeToUser('signup', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC'), false);
$accMail = new AccessMail( Array('mailer'=>'devbox'), inTemporaryDir('Empty') );
setsConfg($natConf);
checkIdentical('a missing template: not sent', (new AccessMail(Array('mailer'=>'devbox')))->sendCodeToUser('signup', 'ann', 'not an address', 'C'), false);
Logger::plugLogsConfig( Array() );
$logText = strval(@file_get_contents(inTemporaryDir('logs').DS.'runner.log'));
checkPregMatch('the account is logged', $logText, '/\[WARNING\] Access mail not sent through account "nobody": Unknown mail account: "nobody"/');
checkPregMatch('the address refused is logged', $logText, '/\[WARNING\] Access mail not sent through account "devbox": Invalid mail address/');

dropTestFolder(TOP_DIR.DS.$foldNme);
finishChecking();
