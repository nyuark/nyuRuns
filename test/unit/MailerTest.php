<?php

// Data\Mail\Mailer and Data\Mail\Message : the accounts of the 'mailer' config, the
// checks of a mail, how it is written out, and the 'emlFiles' account.

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
	Mailer::clearMailCache();
	return runCatchThrown(function() use ($acntLabel){ return Mailer::acntSetupArray($acntLabel); });
}

// The accounts of the config
$mainSet = Array('sendWith'=>'selfSmtp', 'hostName'=>'smtp.example.com', 'portNumb'=>'587', 'userName'=>'u', 'passWord'=>'p', 'fromAddr'=>'app@example.com', 'fromName'=>'App', 'waitSecs'=>'10', 'mailsDir'=>'', 'copiesTo'=>'');
$fileSet = Array('sendWith'=>'emlFiles', 'hostName'=>'', 'portNumb'=>'', 'userName'=>'', 'passWord'=>'', 'fromAddr'=>'dev@example.com', 'fromName'=>'', 'waitSecs'=>'', 'mailsDir'=>'temp/mails', 'copiesTo'=>'');
checkIdentical('single block: it is the default account', acntOf(array_change_key_case($mainSet, CASE_UPPER)), $mainSet);
checkIdentical('single block: settings left out are empty', acntOf(Array('SENDWITH'=>'emlFiles', 'FROMADDR'=>'dev@example.com', 'MORE'=>'x')), array_merge(array_fill_keys(Mailer::KEYS_LIST, ''), Array('sendWith'=>'emlFiles', 'fromAddr'=>'dev@example.com')));
checkPregMatch('single block: it has no name to ask for', acntOf(array_change_key_case($mainSet, CASE_UPPER), 'main'), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: Unknown mail account: "main"/');
checkIdentical('named blocks: the first one is the default', acntOf(Array('NYURUNS'=>$mainSet, 'DEVBOX'=>$fileSet)), $mainSet);
checkIdentical('named blocks: one asked by its name, whatever its case', acntOf(Array('NYURUNS'=>$mainSet, 'DEVBOX'=>$fileSet), 'DevBox'), $fileSet);
checkPregMatch('named blocks: an unknown name is refused', acntOf(Array('NYURUNS'=>$mainSet), 'other'), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: Unknown mail account/');
checkPregMatch('no mailer block', acntOf(null), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: No mail account is configured/');
checkIdentical('the failure is a RuntimeException', is_subclass_of(MailFailure::class, RuntimeException::class), true);

// The accounts that cannot work
checkPregMatch('an unknown type is refused', runCatchThrown(function(){ return new Mailer('x', Array('sendWith'=>'sendmail', 'fromAddr'=>'a@example.com')); }), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: Unknown mail type: "sendmail"/');
checkPregMatch('an account without sender is refused', runCatchThrown(function(){ return new Mailer('x', Array('sendWith'=>'selfSmtp', 'fromAddr'=>'')); }), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: The mail account "x" has no sender address/');
checkPregMatch('a sender that is not an address is refused', runCatchThrown(function(){ return new Mailer('x', Array('sendWith'=>'selfSmtp', 'fromAddr'=>'nobody')); }), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: Invalid mail address: "nobody"/');
if( class_exists('\PHPMailer\PHPMailer\PHPMailer') ){
	checkTrueValue('phpMailr: the account is built when PHPMailer is installed', runCatchThrown(function(){ return new Mailer('x', Array('sendWith'=>'phpMailr', 'fromAddr'=>'a@example.com')); }) instanceof Mailer);
}else{
	checkPregMatch('phpMailr: the account is refused without PHPMailer', runCatchThrown(function(){ return new Mailer('x', Array('sendWith'=>'phpMailr', 'fromAddr'=>'a@example.com')); }), '/^THROWN nyu\\\\Data\\\\Mail\\\\MailFailure: PHPMailer is not installed/');
}

// What a mail is checked for
$newMail = function( Array $mailParts ){
	return runCatchThrown(function() use ($mailParts){
		$mailParts += Array('from'=>'app@example.com', 'name'=>'App', 'to'=>Array('ann@example.com'), 'subj'=>'Hi', 'text'=>'Hello', 'html'=>null, 'atts'=>Array());
		return new Message($mailParts['from'], $mailParts['name'], $mailParts['to'], $mailParts['subj'], $mailParts['text'], $mailParts['html'], $mailParts['atts']);
	});
};
checkTrueValue('a plain mail is built', $newMail(Array()) instanceof Message);
checkPregMatch('a recipient that is not an address', $newMail(Array('to'=>Array('ann'))), '/MailFailure: Invalid mail address: "ann"/');
checkPregMatch('a recipient adding a header', $newMail(Array('to'=>Array("ann@example.com\r\nBcc: eve@example.com"))), '/MailFailure: Invalid mail address/');
checkPregMatch('a subject adding a header', $newMail(Array('subj'=>"Hi\r\nBcc: eve@example.com")), '/MailFailure: Line break refused in the subject of a mail/');
checkPregMatch('a sender name adding a header', $newMail(Array('name'=>"App\nBcc: eve@example.com")), '/MailFailure: Line break refused in the sender name of a mail/');
checkPregMatch('no recipient', $newMail(Array('to'=>Array())), '/MailFailure: A mail needs a recipient/');
checkPregMatch('an attachment that does not exist', $newMail(Array('atts'=>Array(TST_DIR.DS.'nothing.pdf'))), '/MailFailure: Attachment not found/');
checkIdentical('addresses: a list or a text with commas', Array(Message::splitAddresses(' a@x.ch , ,b@x.ch'), Message::splitAddresses(Array('a@x.ch', ' ')), Message::splitAddresses('')), Array(Array('a@x.ch', 'b@x.ch'), Array('a@x.ch'), Array()));

// Header encoding
checkIdentical('header: plain ASCII is kept', Message::encodeHeadText('Hello "you"'), 'Hello "you"');
$longSbj = str_repeat('Été à Genève, ', 8);
$codText = Message::encodeHeadText($longSbj);
$wordLst = explode("\r\n ", $codText);
$decText = '';
foreach( $wordLst as $oneWrd ){
	$decText .= preg_match('/^=\?UTF-8\?B\?(.*)\?=$/', $oneWrd, $wrdFnd) ? base64_decode($wrdFnd[1]) : '?';
}
checkIdentical('header: encoded words give the text back', $decText, $longSbj);
checkIdentical('header: every word holds whole characters', count(array_filter($wordLst, function( String $oneWrd ){ return preg_match('//u', base64_decode(substr($oneWrd, 10, -2)))!==1; })), 0);
checkIdentical('header: no word is longer than a line', max(array_map('strlen', $wordLst))<=75, true);
checkIdentical('line ends: CRLF whatever they were', Message::endLinesInCrlf("a\nb\r\nc\rd"), "a\r\nb\r\nc\r\nd");

// A plain text mail
$txtMail = $newMail(Array('name'=>'Ünï', 'to'=>Array('ann@example.com', 'bob@example.com'), 'subj'=>'Plain', 'text'=>"Line one\nCafé"))->writeWholeMail();
checkPregMatch('plain: the headers', $txtMail, '/^Date: .+\r\nFrom: =\?UTF-8\?B\?'.preg_quote(base64_encode('Ünï'), '/').'\?= <app@example\.com>\r\nTo: ann@example\.com, bob@example\.com\r\nSubject: Plain\r\nMessage-ID: <[0-9a-f]{32}@example\.com>\r\nMIME-Version: 1\.0\r\nContent-Type: text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n/');
checkIdentical('plain: the body, quoted-printable with CRLF', substr($txtMail, strpos($txtMail, "\r\n\r\n")+4), "Line one\r\nCaf=C3=A9");
checkPregMatch('plain: an ASCII sender name is quoted', $newMail(Array('name'=>'The "App"'))->writeWholeMail(), '/\r\nFrom: "The \\\\"App\\\\"" <app@example\.com>\r\n/');

// A mail in text and HTML
$altMail = $newMail(Array('text'=>'Hi', 'html'=>'<p>Hi</p>'))->writeWholeMail();
checkPregMatch('html: two alternatives', $altMail, '/\r\nContent-Type: multipart\/alternative; boundary="(=_nyu_[0-9a-f]{24})"\r\n\r\n--\1\r\nContent-Type: text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nHi\r\n--\1\r\nContent-Type: text\/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<p>Hi<\/p>\r\n--\1--\r\n$/');

// The 'emlFiles' account, with a copy
$fldrNme = 'temp'.DS.'mailtest_'.getmypid();
$GLOBALS['nyuConfig'] = new Registry( Array('mailer'=>Array('DEVBOX'=>array_merge($fileSet, Array('mailsDir'=>'/'.str_replace(DS, '/', $fldrNme), 'copiesTo'=>'backup@example.com, archive@example.com')))) );
Mailer::clearMailCache();
$sentRes = runCatchThrown(function(){ return Mailer::retrieveMailer('devbox')->deliverEmlFlag(Array('ann@example.com'), 'Report', 'See attached', null, Array(__FILE__)); });
checkIdentical('tofile: the mail is written', $sentRes, true);
$fleList = glob(TOP_DIR.DS.$fldrNme.DS.'*.eml') ?: Array();
sort($fleList);
checkIdentical('tofile: one file for the mail, one for its copy', count($fleList), 2);
$envList = Array();
foreach( $fleList as $oneFle ){
	$fleText = strval(file_get_contents($oneFle));
	$envList[] = preg_match('/^X-Envelope-To: (.*)\r\n/', $fleText, $envFnd) ? $envFnd[1] : '';
	checkPregMatch('tofile: the attachments are named', $fleText, '/\r\nX-Attachments: MailerTest\.php\r\nDate: /');
	checkPregMatch('tofile: the copy is the same mail', $fleText, '/\r\nTo: ann@example\.com\r\nSubject: Report\r\n/');
}
sort($envList);
checkIdentical('tofile: who each file was for', $envList, Array('ann@example.com', 'backup@example.com, archive@example.com'));
checkIdentical('tofile: the same mailer is given again', Mailer::retrieveMailer('DEVBOX')===Mailer::retrieveMailer('devbox'), true);
checkPregMatch('tofile: a copy address that is not one is refused', runCatchThrown(function() use ($fileSet){
	$GLOBALS['nyuConfig'] = new Registry( Array('mailer'=>array_merge($fileSet, Array('copiesTo'=>'backup'))) );
	Mailer::clearMailCache();
	return Mailer::retrieveMailer()->deliverEmlFlag('ann@example.com', 'Hi', 'Hello');
}), '/MailFailure: Invalid mail address: "backup"/');
dropTestFolder(TOP_DIR.DS.$fldrNme);

// The example config: the keys of every account, and its types
$distCnf = json_decode(strval(file_get_contents(TOP_DIR.DS.'conf'.DS.'mailer.json.dist')), true);
checkIdentical('mailer.json.dist: every account has every key', array_values(array_unique(array_map(function( Array $oneAct ){ return implode(',', array_keys($oneAct)); }, $distCnf))), Array(implode(',', Mailer::KEYS_LIST)));
checkIdentical('mailer.json.dist: its types', array_column($distCnf, 'sendWith'), array_keys(Mailer::TYPE_LIST));

finishChecking();
