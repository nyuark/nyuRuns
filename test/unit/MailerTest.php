<?php

// Data\Mail\Mailer and Data\Mail\Message : the accounts of the 'mailer' config, the
// checks of a mail, how it is written out, and the 'tofile' account.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Mail\Mailer;
use nyu\Data\Mail\MailFailure;
use nyu\Data\Mail\Message;
use nyu\Tools\Registry;

/**
 * Reads the settings of an account from a given 'mailer' config.
 *
 * @param mixed  $mailConfig The 'mailer' config, as the loader gives it.
 * @param String $acntLabel  Name of the account, '' for the default one.
 * @return mixed The settings, or a 'THROWN ...' string.
 */
function acntOf( Mixed $mailConfig, String $acntLabel='' ): Mixed {
	$GLOBALS['nyuConfig'] = new Registry( ($mailConfig===null) ? Array() : Array('mailer'=>$mailConfig) );
	Mailer::dropCache();
	return runSafely(function() use ($acntLabel){ return Mailer::acntSetup($acntLabel); });
}

// The accounts of the config
$mainSet = Array('type'=>'native', 'host'=>'smtp.example.com', 'port'=>'587', 'user'=>'u', 'pass'=>'p', 'from'=>'app@example.com', 'name'=>'App', 'wait'=>'10', 'fold'=>'', 'copy'=>'');
$fileSet = Array('type'=>'tofile', 'host'=>'', 'port'=>'', 'user'=>'', 'pass'=>'', 'from'=>'dev@example.com', 'name'=>'', 'wait'=>'', 'fold'=>'temp/mails', 'copy'=>'');
checkSame('single block: it is the default account', acntOf(array_change_key_case($mainSet, CASE_UPPER)), $mainSet);
checkSame('single block: settings left out are empty', acntOf(Array('TYPE'=>'tofile', 'FROM'=>'dev@example.com', 'MORE'=>'x')), array_merge(array_fill_keys(Mailer::KEYSLIST, ''), Array('type'=>'tofile', 'from'=>'dev@example.com')));
checkLike('single block: it has no name to ask for', acntOf(array_change_key_case($mainSet, CASE_UPPER), 'main'), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: Unknown mail account: "main"/');
checkSame('named blocks: the first one is the default', acntOf(Array('NYURUNS'=>$mainSet, 'DEVBOX'=>$fileSet)), $mainSet);
checkSame('named blocks: one asked by its name, whatever its case', acntOf(Array('NYURUNS'=>$mainSet, 'DEVBOX'=>$fileSet), 'DevBox'), $fileSet);
checkLike('named blocks: an unknown name is refused', acntOf(Array('NYURUNS'=>$mainSet), 'other'), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: Unknown mail account/');
checkLike('no mailer block', acntOf(null), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: No mail account is configured/');
checkSame('the failure is a RuntimeException', is_subclass_of(MailFailure::class, RuntimeException::class), true);

// The accounts that cannot work
checkLike('an unknown type is refused', runSafely(function(){ return new Mailer('x', Array('type'=>'sendmail', 'from'=>'a@example.com')); }), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: Unknown mail type: "sendmail"/');
checkLike('an account without sender is refused', runSafely(function(){ return new Mailer('x', Array('type'=>'native', 'from'=>'')); }), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: The mail account "x" has no sender address/');
checkLike('a sender that is not an address is refused', runSafely(function(){ return new Mailer('x', Array('type'=>'native', 'from'=>'nobody')); }), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: Invalid mail address: "nobody"/');
if( class_exists('\PHPMailer\PHPMailer\PHPMailer') ){
	checkTrue('phmail: the account is built when PHPMailer is installed', runSafely(function(){ return new Mailer('x', Array('type'=>'phmail', 'from'=>'a@example.com')); }) instanceof Mailer);
}else{
	checkLike('phmail: the account is refused without PHPMailer', runSafely(function(){ return new Mailer('x', Array('type'=>'phmail', 'from'=>'a@example.com')); }), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: PHPMailer is not installed/');
}

// What a mail is checked for
$newMail = function( Array $mailParts ){
	return runSafely(function() use ($mailParts){
		$mailParts += Array('from'=>'app@example.com', 'name'=>'App', 'to'=>Array('ann@example.com'), 'subj'=>'Hi', 'text'=>'Hello', 'html'=>null, 'atts'=>Array());
		return new Message($mailParts['from'], $mailParts['name'], $mailParts['to'], $mailParts['subj'], $mailParts['text'], $mailParts['html'], $mailParts['atts']);
	});
};
checkTrue('a plain mail is built', $newMail(Array()) instanceof Message);
checkLike('a recipient that is not an address', $newMail(Array('to'=>Array('ann'))), '/MailFailure: Invalid mail address: "ann"/');
checkLike('a recipient adding a header', $newMail(Array('to'=>Array("ann@example.com\r\nBcc: eve@example.com"))), '/MailFailure: Invalid mail address/');
checkLike('a subject adding a header', $newMail(Array('subj'=>"Hi\r\nBcc: eve@example.com")), '/MailFailure: Line break refused in the subject of a mail/');
checkLike('a sender name adding a header', $newMail(Array('name'=>"App\nBcc: eve@example.com")), '/MailFailure: Line break refused in the sender name of a mail/');
checkLike('no recipient', $newMail(Array('to'=>Array())), '/MailFailure: A mail needs a recipient/');
checkLike('an attachment that does not exist', $newMail(Array('atts'=>Array(TST_DIR.DS.'nothing.pdf'))), '/MailFailure: Attachment not found/');
checkSame('addresses: a list or a text with commas', Array(Message::addrsList(' a@x.ch , ,b@x.ch'), Message::addrsList(Array('a@x.ch', ' ')), Message::addrsList('')), Array(Array('a@x.ch', 'b@x.ch'), Array('a@x.ch'), Array()));

// Header encoding
checkSame('header: plain ASCII is kept', Message::encodHead('Hello "you"'), 'Hello "you"');
$longSbj = str_repeat('Été à Genève, ', 8);
$codText = Message::encodHead($longSbj);
$wordLst = explode("\r\n ", $codText);
$decText = '';
foreach( $wordLst as $oneWrd ){
	$decText .= preg_match('/^=\?UTF-8\?B\?(.*)\?=$/', $oneWrd, $wrdFnd) ? base64_decode($wrdFnd[1]) : '?';
}
checkSame('header: encoded words give the text back', $decText, $longSbj);
checkSame('header: every word holds whole characters', count(array_filter($wordLst, function( String $oneWrd ){ return preg_match('//u', base64_decode(substr($oneWrd, 10, -2)))!==1; })), 0);
checkSame('header: no word is longer than a line', max(array_map('strlen', $wordLst))<=75, true);
checkSame('line ends: CRLF whatever they were', Message::crlfLines("a\nb\r\nc\rd"), "a\r\nb\r\nc\r\nd");

// A plain text mail
$txtMail = $newMail(Array('name'=>'Ünï', 'to'=>Array('ann@example.com', 'bob@example.com'), 'subj'=>'Plain', 'text'=>"Line one\nCafé"))->buildText();
checkLike('plain: the headers', $txtMail, '/^Date: .+\r\nFrom: =\?UTF-8\?B\?'.preg_quote(base64_encode('Ünï'), '/').'\?= <app@example\.com>\r\nTo: ann@example\.com, bob@example\.com\r\nSubject: Plain\r\nMessage-ID: <[0-9a-f]{32}@example\.com>\r\nMIME-Version: 1\.0\r\nContent-Type: text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n/');
checkSame('plain: the body, quoted-printable with CRLF', substr($txtMail, strpos($txtMail, "\r\n\r\n")+4), "Line one\r\nCaf=C3=A9");
checkLike('plain: an ASCII sender name is quoted', $newMail(Array('name'=>'The "App"'))->buildText(), '/\r\nFrom: "The \\\\"App\\\\"" <app@example\.com>\r\n/');

// A mail in text and HTML
$altMail = $newMail(Array('text'=>'Hi', 'html'=>'<p>Hi</p>'))->buildText();
checkLike('html: two alternatives', $altMail, '/\r\nContent-Type: multipart\/alternative; boundary="(=_nyu_[0-9a-f]{24})"\r\n\r\n--\1\r\nContent-Type: text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nHi\r\n--\1\r\nContent-Type: text\/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<p>Hi<\/p>\r\n--\1--\r\n$/');

// The 'tofile' account, with a copy
$foldNme = 'temp'.DS.'mailtest_'.getmypid();
$GLOBALS['nyuConfig'] = new Registry( Array('mailer'=>Array('DEVBOX'=>array_merge($fileSet, Array('fold'=>'/'.str_replace(DS, '/', $foldNme), 'copy'=>'backup@example.com, archive@example.com')))) );
Mailer::dropCache();
$sentRes = runSafely(function(){ return Mailer::getMailer('devbox')->sendEmail(Array('ann@example.com'), 'Report', 'See attached', null, Array(__FILE__)); });
checkSame('tofile: the mail is written', $sentRes, true);
$fleList = glob(TOP_DIR.DS.$foldNme.DS.'*.eml') ?: Array();
sort($fleList);
checkSame('tofile: one file for the mail, one for its copy', count($fleList), 2);
$envList = Array();
foreach( $fleList as $oneFle ){
	$fleText = strval(file_get_contents($oneFle));
	$envList[] = preg_match('/^X-Envelope-To: (.*)\r\n/', $fleText, $envFnd) ? $envFnd[1] : '';
	checkLike('tofile: the attachments are named', $fleText, '/\r\nX-Attachments: MailerTest\.php\r\nDate: /');
	checkLike('tofile: the copy is the same mail', $fleText, '/\r\nTo: ann@example\.com\r\nSubject: Report\r\n/');
}
sort($envList);
checkSame('tofile: who each file was for', $envList, Array('ann@example.com', 'backup@example.com, archive@example.com'));
checkSame('tofile: the same mailer is given again', Mailer::getMailer('DEVBOX')===Mailer::getMailer('devbox'), true);
checkLike('tofile: a copy address that is not one is refused', runSafely(function() use ($fileSet){
	$GLOBALS['nyuConfig'] = new Registry( Array('mailer'=>array_merge($fileSet, Array('copy'=>'backup'))) );
	Mailer::dropCache();
	return Mailer::getMailer()->sendEmail('ann@example.com', 'Hi', 'Hello');
}), '/MailFailure: Invalid mail address: "backup"/');
dropFoldr(TOP_DIR.DS.$foldNme);

// The example config: the keys of every account, and its types
$distCnf = json_decode(strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'mailer.json.dist')), true);
checkSame('mailer.json.dist: every account has every key', array_values(array_unique(array_map(function( Array $oneAct ){ return implode(',', array_keys($oneAct)); }, $distCnf))), Array(implode(',', Mailer::KEYSLIST)));
checkSame('mailer.json.dist: its types', array_column($distCnf, 'type'), array_keys(Mailer::TYPELIST));

endChecks();
