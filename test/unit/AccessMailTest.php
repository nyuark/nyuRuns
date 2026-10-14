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
	Mailer::dropCache();
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
checkTrue('fromLogin: a block naming an account', AccessMail::fromLogin() instanceof AccessMail);
setsConfg( array_diff_key($natConf, Array('mailer'=>1)) );
checkSame('fromLogin: no mailer, no mail', AccessMail::fromLogin(), null);
setsConfg( Array('mailer'=>'') + $natConf );
checkSame('fromLogin: an empty mailer, no mail', AccessMail::fromLogin(), null);
$GLOBALS['nyuConfig'] = new Registry( Array('access'=>Array('LOGIN'=>Array('authby'=>'usrlst', 'authcf'=>'usrlst'), 'USRLST'=>$natConf)) );
checkSame('fromLogin: another credential check', AccessMail::fromLogin(), null);
setsConfg( Array('signup'=>true) + $natConf );
checkSame('signupsOn: signup and a mailer', AccessMail::signupsOn(), true);
setsConfg( Array('signup'=>true, 'mailer'=>'') + $natConf );
checkSame('signupsOn: without a mailer', AccessMail::signupsOn(), false);
setsConfg( Array('signup'=>'yes') + $natConf );
checkSame('signupsOn: only true opens them', AccessMail::signupsOn(), false);
setsConfg($natConf);
checkSame('signupsOn: left out', AccessMail::signupsOn(), false);

// A code, with its link
$accMail = AccessMail::fromLogin();
takeMails();
checkSame('sendCodes: sent', $accMail->sendCodes('signup', 'Ann.B', 'ann@example.ch', 'K7QM-3XRP-WD'), true);
$mailLst = takeMails();
$mailTxt = readMail($mailLst[0] ?? '');
checkSame('sendCodes: one mail', count($mailLst), 1);
checkLike('sendCodes: to the address, the first line of the template as subject', $mailTxt, '/\r\nTo: ann@example\.ch\r\nSubject: Your sign-up: confirm your address\r\n/');
checkLike('sendCodes: the code and the minutes of codexp', $mailTxt, '/    K7QM-3XRP-WD\r?\n.*within 20 minutes/s');
checkLike('sendCodes: the link, its values encoded', $mailTxt, '/https:\/\/app\.example\.ch\/access\?do=signup&user=Ann\.B&code=K7QM-3XRP-WD/');
checkLike('sendCodes: an HTML part with the link', $mailTxt, '/<a href="https:\/\/app\.example\.ch\/access\?do=signup&amp;user=Ann\.B&amp;code=K7QM-3XRP-WD">this link<\/a>/');
checkSame('sendCodes: no marker left', preg_match('/\[\/?link\]|%(user|code|link|mins)%/', $mailTxt), 0);
checkSame('sendCodes: a mail that is not one of its kinds', $accMail->sendCodes('taken', 'ann', 'ann@example.ch', 'X'), false);
foreach( Array('actvte'=>'Your account: choose your password', 'pwdset'=>'Your password: choose a new one') as $mailKnd=>$subjTxt ){
	$accMail->sendCodes($mailKnd, 'ann', 'ann@example.ch', 'AAAA-BBBB-CC');
	checkLike('sendCodes: the mail '.$mailKnd, readMail(takeMails()[0] ?? ''), '/Subject: '.preg_quote($subjTxt, '/').'\r\n.*do='.$mailKnd.'&user=ann&code=AAAA-BBBB-CC/s');
}

// Without a link, the code alone
$accMail = new AccessMail( array_diff_key($natConf, Array('lnkurl'=>1)) );
$accMail->sendCodes('pwdset', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC');
$mailTxt = readMail(takeMails()[0] ?? '');
checkSame('no lnkurl: the code, neither a link nor its sentence', Array(strpos($mailTxt, 'AAAA-BBBB-CC')!==false, strpos($mailTxt, 'open this link'), strpos($mailTxt, 'https://')), Array(true, false, false));
checkLike('no lnkurl: the default minutes of a mailed code', readMail(strval((new AccessMail(Array('mailer'=>'devbox')))->sendCodes('pwdset', 'a', 'a@example.ch', 'C') ? (takeMails()[0] ?? '') : '')), '/within 15 minutes/');

// A name or an address taken
$accMail = new AccessMail($natConf);
checkSame('sendTaken: sent', $accMail->sendTaken('ann@example.ch', '<b>ann</b>'), true);
$mailTxt = readMail(takeMails()[0] ?? '');
checkLike('sendTaken: its subject, the name asked for, encoded in the HTML part', $mailTxt, '/Subject: Your sign-up\r\n.*sign up as <b>ann<\/b> with.*<strong>&lt;b&gt;ann&lt;\/b&gt;<\/strong>/s');

// The managers told that sign-ups pile up : one mail each
$accMail = new AccessMail($natConf);
checkSame('sendWatch: sent', $accMail->sendWatch(Array('anna@example.ch', 'ben@example.ch'), 128, 256), true);
$mailLst = array_map('readMail', takeMails());
$sentTo = array_map(function( String $mailString ): String { return preg_match('/\r\nTo: (\S+)\r\n/', $mailString, $toMatch) ? $toMatch[1] : ''; }, $mailLst);
sort($sentTo);
checkSame('sendWatch: one mail for each manager', $sentTo, Array('anna@example.ch', 'ben@example.ch'));
checkLike('sendWatch: the count and the limit', $mailLst[0] ?? '', '/Subject: Sign-ups waiting for review: 128\r\n.*When 256 are waiting/s');
checkSame('sendWatch: nobody to tell', $accMail->sendWatch(Array(), 128, 256), true);

// The language of the user, along its chain
$accMail = new AccessMail($natConf);
$accMail->sendCodes('signup', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC', 'fr-ch');
$mailTxt = readMail(takeMails()[0] ?? '');
checkLike('language: fr-ch is written in fr, text and HTML', $mailTxt, '/Subject: Votre inscription : confirmez votre adresse\r\n.*Quelqu\'un \(vous, on l\'esp.*<a href="https:\/\/app\.example\.ch\/access\?do=signup&amp;user=ann&amp;code=AAAA-BBBB-CC">ce lien<\/a>/s');
checkLike('language: one that is not delivered falls back to the default', readMail($accMail->sendTaken('ann@example.ch', 'ann', 'de-at') ? (takeMails()[0] ?? '') : ''), '/Subject: Your sign-up\r\n/');
checkLike('language: one that is not a tag is the default', readMail($accMail->sendTaken('ann@example.ch', 'ann', '../fr') ? (takeMails()[0] ?? '') : ''), '/Subject: Your sign-up\r\n/');
$GLOBALS['nyuConfig'] = new Registry( Array('mailer'=>Array('DEVBOX'=>$fileSet), 'system'=>Array('LNG_DFLT'=>'fr')) );
checkLike('language: LNG_DFLT when the user has none', readMail((new AccessMail($natConf))->sendTaken('ann@example.ch', 'ann') ? (takeMails()[0] ?? '') : ''), '/Subject: Votre inscription\r\n/');
setsConfg($natConf);
$accMail = new AccessMail($natConf);
$sentOk = $accMail->sendWatch(Array('anna@example.ch'=>'fr', 'ben@example.ch'=>null), 3, 9);
$sentTo = array_map(function( String $mailString ): String { return preg_match('/\r\nTo: (\S+)\r\n.*?(attente|waiting)/s', readMail($mailString), $toMatch) ? $toMatch[1].' '.$toMatch[2] : ''; }, takeMails());
sort($sentTo);
checkSame('sendWatch: the language of each manager', Array($sentOk, $sentTo), Array(true, Array('anna@example.ch attente', 'ben@example.ch waiting')));

// The templates of the application come first, language by language
$appFldr = inTempDir('Probe');
mkdir($appFldr.DS.'mails'.DS.'fr', 0777, true);
file_put_contents($appFldr.DS.'mails'.DS.'fr'.DS.'pwdset.txt', "Mot de passe\nBonjour %user%, votre code : %code%\n");
$accMail = new AccessMail($natConf, $appFldr);
$accMail->sendCodes('pwdset', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC', 'fr');
$mailTxt = takeMails()[0] ?? '';
checkSame('application template: its subject and text, no HTML part without its .html', Array(preg_match('/Subject: Mot de passe\r\n/', $mailTxt), strpos(readMail($mailTxt), 'Bonjour ann, votre code : AAAA-BBBB-CC')!==false, strpos($mailTxt, 'text/html')), Array(1, true, false));
$accMail->sendCodes('actvte', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC', 'fr');
checkLike('application template: conf/mails in the same language for the others', readMail(takeMails()[0] ?? ''), '/Subject: Votre compte : choisissez votre mot de passe\r\n/');
$accMail->sendCodes('pwdset', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC');
checkLike('application template: another language is not given its text', readMail(takeMails()[0] ?? ''), '/Subject: Your password: choose a new one/');

// After the reply : kept, then sent at the end of the request
$accMail = new AccessMail($natConf, '', true);
checkSame('after the reply: kept', Array($accMail->sendCodes('signup', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC'), $accMail->sendTaken('bob@example.ch', 'bob'), count(takeMails())), Array(true, true, 0));
ob_start();
$accMail->sendLater();
$sentTo = array_map(function( String $mailString ): String { return preg_match('/\r\nTo: (\S+)\r\n/', $mailString, $toMatch) ? $toMatch[1] : ''; }, takeMails());
sort($sentTo);
checkSame('after the reply: sent', $sentTo, Array('ann@example.ch', 'bob@example.ch'));

// What cannot be sent is logged, never thrown
Logger::plugSetup( Array('path'=>inTempDir('logs')) );
$accMail = new AccessMail( Array('mailer'=>'nobody') );
checkSame('an unknown account: not sent', $accMail->sendCodes('signup', 'ann', 'ann@example.ch', 'AAAA-BBBB-CC'), false);
$accMail = new AccessMail( Array('mailer'=>'devbox'), inTempDir('Empty') );
setsConfg($natConf);
checkSame('a missing template: not sent', (new AccessMail(Array('mailer'=>'devbox')))->sendCodes('signup', 'ann', 'not an address', 'C'), false);
Logger::plugSetup( Array() );
$logText = strval(@file_get_contents(inTempDir('logs').DS.'runner.log'));
checkLike('the account is logged', $logText, '/\[WARNING\] Access mail not sent through account "nobody": Unknown mail account: "nobody"/');
checkLike('the address refused is logged', $logText, '/\[WARNING\] Access mail not sent through account "devbox": Invalid mail address/');

dropFoldr(TOP_DIR.DS.$foldNme);
endChecks();
