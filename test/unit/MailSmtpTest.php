<?php

// Data\Mail\Drivers\Native : the framework's own SMTP client, talking to a fake
// server run as a child process on this machine (test/lib/smtpChild.php).

require __DIR__.'/../lib/harness.php';

use nyu\Data\Mail\Mailer;
use nyu\Data\Mail\MailFailure;
use nyu\Tools\Logger;
use nyu\Tools\Registry;

/**
 * Starts the fake SMTP server.
 *
 * @param String $authsOffer Logins it offers: 'PLAIN LOGIN', 'LOGIN' or 'none'.
 * @param Int    $connsCount Connections it answers before it exits.
 * @param String $listenAddr Address it listens on.
 * @return Array The process under 'proc', its 'pipes', its 'port' (0 when it could not listen) and its transcript 'file'.
 */
function startSmtp( String $authsOffer, Int $connsCount, String $listenAddr='127.0.0.1' ): Array {
	static $runNmbr = 0;
	$runNmbr++;
	$trnFile = inTemporaryDir('smtp'.$runNmbr.'.txt');
	file_put_contents($trnFile, '');
	$pipeArr = Array();
	$process = proc_open( Array(PHP_BINARY, TST_DIR.DS.'lib'.DS.'smtpChild.php', $trnFile, $authsOffer, strval($connsCount), $listenAddr), Array(0=>Array('pipe','r'), 1=>Array('pipe','w'), 2=>Array('pipe','w')), $pipeArr, TOP_DIR );
	fclose($pipeArr[0]);
	// The port is the first line that is a number: PHP may print its own warnings first
	$portNum = 0;
	while( ($outLine = fgets($pipeArr[1]))!==false ){
		if( preg_match('/^\d+$/', trim($outLine)) ){
			$portNum = intval($outLine);
			break;
		}
	}
	return Array('proc'=>$process, 'pipes'=>$pipeArr, 'port'=>$portNum, 'file'=>$trnFile);
}

/**
 * Waits for the fake SMTP server to end, and reads what it was sent.
 *
 * @param Array $smtpServer What startSmtp() returned.
 * @return String Everything the client sent.
 */
function stopsSmtp( Array $smtpServer ): String {
	fclose($smtpServer['pipes'][1]);
	fclose($smtpServer['pipes'][2]);
	proc_close($smtpServer['proc']);
	return strval(file_get_contents($smtpServer['file']));
}

/**
 * Gives the mailer of a native account on the fake server.
 *
 * @param Array $moreSetup Settings replacing those of the account.
 * @return Mailer The mailer.
 */
function mailerFor( Array $moreSetup ): Mailer {
	$GLOBALS['nyuConfig'] = new Registry( Array('mailer'=>Array('TESTS'=>array_merge(Array('sendWith'=>'selfSmtp', 'hostName'=>'127.0.0.1', 'userName'=>'user', 'password'=>'secret', 'fromAddr'=>'app@example.com', 'fromName'=>'App', 'waitSecs'=>'5'), $moreSetup))) );
	Mailer::clearMailCache();
	return Mailer::retrieveMailer();
}

// Log of the warnings, in a folder of this test
$logPath = inTemporaryDir('logs');
Logger::plugLogsConfig( Array('path'=>$logPath, 'mode'=>'FULL', 'size'=>0, 'rota'=>'NUMB', 'pref'=>'Y-m-d H:i') );

// A mail, its copy, AUTH PLAIN
$smtpSrv = startSmtp('PLAIN LOGIN', 2);
if( $smtpSrv['port']==0 ){
	recordSkipping('native client', 'the fake server could not listen on 127.0.0.1');
}else{
	$mailRes = runCatchThrown(function() use ($smtpSrv){
		return mailerFor(Array('portNumb'=>strval($smtpSrv['port']), 'copiesTo'=>'backup@example.com'))->deliverEmlFlag('ann@example.com, bob@example.com', 'Résumé', "Hello\n.hidden line\nBye");
	});
	$trnText = stopsSmtp($smtpSrv);
	checkIdentical('PLAIN: the mail is sent', $mailRes, true);
	checkTrueValue('PLAIN: the client logs in with AUTH PLAIN', strpos($trnText, 'AUTH PLAIN '.base64_encode("\0user\0secret")."\r\n")!==false);
	checkIdentical('PLAIN: the sender, twice (mail and copy)', substr_count($trnText, "MAIL FROM:<app@example.com>\r\n"), 2);
	checkTrueValue('PLAIN: both recipients of the mail', strpos($trnText, "RCPT TO:<ann@example.com>\r\nRCPT TO:<bob@example.com>\r\nDATA")!==false);
	checkTrueValue('PLAIN: the copy goes to the backup address only', strpos($trnText, "RCPT TO:<backup@example.com>\r\nDATA")!==false);
	checkIdentical('PLAIN: the copy keeps the To of the mail', substr_count($trnText, "To: ann@example.com, bob@example.com\r\n"), 2);
	checkTrueValue('PLAIN: a line starting with a dot gets a second one', strpos($trnText, "\r\n..hidden line\r\n")!==false);
	checkTrueValue('PLAIN: the subject is encoded', strpos($trnText, 'Subject: =?UTF-8?B?'.base64_encode('Résumé').'?=')!==false);
	checkIdentical('PLAIN: the client says goodbye', substr_count($trnText, "QUIT\r\n"), 2);
}

// AUTH LOGIN, when the server offers no PLAIN
$smtpSrv = startSmtp('LOGIN', 1);
$mailRes = runCatchThrown(function() use ($smtpSrv){ return mailerFor(Array('portNumb'=>strval($smtpSrv['port'])))->deliverEmlFlag('ann@example.com', 'Hi', 'Hello'); });
$trnText = stopsSmtp($smtpSrv);
checkIdentical('LOGIN: the mail is sent', $mailRes, true);
checkTrueValue('LOGIN: user and password are sent apart', strpos($trnText, "AUTH LOGIN\r\n".base64_encode('user')."\r\n".base64_encode('secret')."\r\n")!==false);

// A wrong password
$smtpSrv = startSmtp('PLAIN LOGIN', 1);
$mailRes = runCatchThrown(function() use ($smtpSrv){ return mailerFor(Array('portNumb'=>strval($smtpSrv['port']), 'password'=>'wrong'))->deliverEmlFlag('ann@example.com', 'Hi', 'Hello'); });
$trnText = stopsSmtp($smtpSrv);
checkIdentical('wrong password: not sent', $mailRes, false);
checkIdentical('wrong password: nothing goes past the login', strpos($trnText, 'MAIL FROM'), false);

// No login offered while the account has a user
$smtpSrv = startSmtp('none', 1);
$mailRes = runCatchThrown(function() use ($smtpSrv){ return mailerFor(Array('portNumb'=>strval($smtpSrv['port'])))->deliverEmlFlag('ann@example.com', 'Hi', 'Hello'); });
stopsSmtp($smtpSrv);
checkIdentical('no login offered: not sent', $mailRes, false);

// No user: no login
$smtpSrv = startSmtp('none', 1);
$mailRes = runCatchThrown(function() use ($smtpSrv){ return mailerFor(Array('portNumb'=>strval($smtpSrv['port']), 'userName'=>'', 'password'=>''))->deliverEmlFlag('ann@example.com', 'Hi', 'Hello'); });
$trnText = stopsSmtp($smtpSrv);
checkIdentical('no user: sent without login', Array($mailRes, strpos($trnText, 'AUTH')), Array(true, false));

// A recipient refused: logged, answered false
$smtpSrv = startSmtp('PLAIN LOGIN', 1);
$mailRes = runCatchThrown(function() use ($smtpSrv){ return mailerFor(Array('portNumb'=>strval($smtpSrv['port'])))->deliverEmlFlag('refused@example.com', 'Hi', 'Hello'); });
stopsSmtp($smtpSrv);
checkIdentical('refused recipient: not sent', $mailRes, false);
$logText = strval(@file_get_contents($logPath.DS.'runner.log'));
checkPregMatch('refused recipient: a warning names the reply', $logText, '/\[WARNING\] Mail not sent to \["refused@example\.com"\] through account "default": "RCPT TO:<refused@example\.com>" refused: 550 no such user/');
checkIdentical('the password is never logged', strpos($logText, 'secret'), false);

// A server that is not this machine and offers no STARTTLS
$smtpSrv = startSmtp('PLAIN LOGIN', 1, '127.0.0.2');
if( $smtpSrv['port']==0 ){
	stopsSmtp($smtpSrv);
	recordSkipping('no STARTTLS on another machine', 'the fake server could not listen on 127.0.0.2');
}else{
	$mailRes = runCatchThrown(function() use ($smtpSrv){ return mailerFor(Array('hostName'=>'127.0.0.2', 'portNumb'=>strval($smtpSrv['port'])))->deliverEmlFlag('ann@example.com', 'Hi', 'Hello'); });
	$trnText = stopsSmtp($smtpSrv);
	checkIdentical('no STARTTLS: not sent, the password never sent', Array($mailRes, strpos($trnText, 'AUTH')), Array(false, false));
	checkPregMatch('no STARTTLS: the warning says why', strval(@file_get_contents($logPath.DS.'runner.log')), '/the server offers no STARTTLS/');
}

// Nobody listening
$mailRes = runCatchThrown(function() use ($smtpSrv){ return mailerFor(Array('portNumb'=>strval($smtpSrv['port'] ?: 1), 'waitSecs'=>'2'))->deliverEmlFlag('ann@example.com', 'Hi', 'Hello'); });
checkIdentical('no server: not sent', $mailRes, false);
checkPregMatch('no server: the warning says so', strval(@file_get_contents($logPath.DS.'runner.log')), '/no connection to 127\.0\.0\.1:/');

// Attachments are not for a native account
checkForThrows('selfSmtp: an attachment is refused', function(){ mailerFor(Array('portNumb'=>'1'))->deliverEmlFlag('ann@example.com', 'Hi', 'Hello', null, Array(__FILE__)); }, MailFailure::class);

Logger::plugLogsConfig( Array() );
finishChecking();
