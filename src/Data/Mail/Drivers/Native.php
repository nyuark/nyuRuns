<?php

namespace nyu\Data\Mail\Drivers;

use \nyu\Data\Mail\Message;
use \nyu\Data\Mail\MailFailure;

/**
 * The framework's own SMTP client: text and HTML mails, no attachment.
 * The encryption follows the port: 465 is TLS from the first byte; on any
 * other port the server must offer STARTTLS, or the mail is not sent,
 * except to this machine. Certificates are always verified. The account
 * logs in (AUTH PLAIN, else LOGIN) when it has a 'user'.
 */
class Native extends Driver {

	protected $socketLink;

	/**
	 * Sends a mail through the SMTP server of the account.
	 *
	 * @param Message $emailMessage The mail.
	 * @param Array   $recipientLst Checked addresses it is delivered to.
	 * @return ?String Null when the server took it, or why it was not sent.
	 *
	 * @throws MailFailure When the mail has attachments.
	 */
	public function sendRawMessage( Message $emailMessage, Array $recipientLst ): ?String {
		if( !empty($emailMessage->getAttachments()) ){
			throw new MailFailure('A native mail account sends no attachment: use a phmail account');
		}
		try{
			$this->openSmtpAsUser();
			$this->sendCommandTxt('MAIL FROM:<'.$emailMessage->getSenderEmail().'>', Array(250));
			foreach( $recipientLst as $oneAdr ){
				$this->sendCommandTxt('RCPT TO:<'.$oneAdr.'>', Array(250, 251));
			}
			$this->sendCommandTxt('DATA', Array(354));
			// A line starting with a dot gets a second one: a lone dot ends the mail
			$mailText = preg_replace('/^\./m', '..', Message::castLnesToCrlf($emailMessage->writeWholeMail()));
			$this->sendCommandTxt(rtrim($mailText, "\r\n")."\r\n.", Array(250), 'the mail');
			$this->sendCommandTxt('QUIT', Array(221));
		}catch( MailFailure $e ){
			return $e->getMessage();
		}finally{
			$this->dropServerLink();
		}
		return null;
	}

	/**
	 * Connects to the server, greets it, encrypts the link and logs in.
	 *
	 * @throws MailFailure When any step fails.
	 */
	protected function openSmtpAsUser(): Void {
		$srvrHost = strval($this->setupArray['hostName'] ?? '');
		$portNumb = strval($this->setupArray['portNumb'] ?? '');
		$portNumb = ($portNumb=='') ? 587 : intval($portNumb);
		$waitSecs = intval($this->setupArray['waitSecs'] ?? '') ?: 10;
		if( $srvrHost=='' || $portNumb<=0 ){
			throw new MailFailure('the account has no server');
		}
		$tlsConfg = Array('ssl'=>Array('verify_peer'=>true, 'verify_peer_name'=>true, 'peer_name'=>$srvrHost, 'SNI_enabled'=>true));
		$hostAddr = (strpos($srvrHost, ':')!==false) ? '['.trim($srvrHost, '[]').']' : $srvrHost;
		$errorCod = 0;
		$errorTxt = '';
		$scktLink = @stream_socket_client( (($portNumb==465) ? 'tls://' : 'tcp://').$hostAddr.':'.$portNumb, $errorCod, $errorTxt, $waitSecs, STREAM_CLIENT_CONNECT, stream_context_create($tlsConfg) );
		if( $scktLink===false ){
			throw new MailFailure('no connection to '.$srvrHost.':'.$portNumb.' ('.trim($errorCod.' '.$errorTxt).')');
		}
		$this->socketLink = $scktLink;
		stream_set_timeout($this->socketLink, $waitSecs);
		$this->getReplyString(Array(220));
		$ehloText = $this->sendCommandTxt('EHLO '.$this->getMachineName(), Array(250));
		if( $portNumb!=465 ){
			if( preg_match('/^\d{3}[ -]STARTTLS\b/mi', $ehloText) ){
				$this->sendCommandTxt('STARTTLS', Array(220));
				$crypMask = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
				if( @stream_socket_enable_crypto($this->socketLink, true, $crypMask)!==true ){
					throw new MailFailure('the encryption of the link failed');
				}
				$ehloText = $this->sendCommandTxt('EHLO '.$this->getMachineName(), Array(250));
			}elseif( !self::isLocalMachine($srvrHost) ){
				throw new MailFailure('the server offers no STARTTLS: mail is never sent unencrypted');
			}
		}
		$this->signInToServer($ehloText);
	}

	/**
	 * Logs in, when the account has a user: AUTH PLAIN when the server
	 * offers it, else AUTH LOGIN.
	 *
	 * @param String $serverAnswer The answer of the server to EHLO.
	 *
	 * @throws MailFailure When the server offers neither, or refuses the login.
	 */
	protected function signInToServer( String $serverAnswer ): Void {
		$userName = strval($this->setupArray['userName'] ?? '');
		if( $userName=='' ){
			return;
		}
		$passText = strval($this->setupArray['passWord'] ?? '');
		$authList = Array();
		if( preg_match('/^\d{3}[ -]AUTH[ =](.*)$/mi', $serverAnswer, $authMthd) ){
			$authList = preg_split('/\s+/', strtoupper(trim($authMthd[1])));
		}
		if( in_array('PLAIN', $authList, true) ){
			$this->sendCommandTxt('AUTH PLAIN '.base64_encode("\0".$userName."\0".$passText), Array(235), 'AUTH PLAIN');
		}elseif( in_array('LOGIN', $authList, true) ){
			$this->sendCommandTxt('AUTH LOGIN', Array(334));
			$this->sendCommandTxt(base64_encode($userName), Array(334), 'the user name');
			$this->sendCommandTxt(base64_encode($passText), Array(235), 'the password');
		}else{
			throw new MailFailure('the server offers no login the account can use (PLAIN or LOGIN)');
		}
	}

	/**
	 * Sends one command and reads the answer.
	 *
	 * @param String  $plainCommand  The command, without its line end.
	 * @param Array   $successCodes The answer codes that mean it was taken.
	 * @param ?String $displayLabel What the command is called in a failure, for a command carrying a secret; null to show the command itself.
	 * @return String The answer of the server.
	 *
	 * @throws MailFailure When the link breaks, or the answer is another code.
	 */
	protected function sendCommandTxt( String $plainCommand, Array $successCodes, ?String $displayLabel=null ): String {
		if( @fwrite($this->socketLink, $plainCommand."\r\n")===false ){
			throw new MailFailure('the link broke');
		}
		$cmndText = ($displayLabel!==null) ? $displayLabel : strtok($plainCommand, "\r\n");
		return $this->getReplyString($successCodes, $cmndText);
	}

	/**
	 * Reads one answer of the server, all of its lines.
	 *
	 * @param Array  $successCodes The answer codes that mean the last command was taken.
	 * @param String $commandLabel What the last command is called in a failure.
	 * @return String The answer.
	 *
	 * @throws MailFailure When the link breaks or times out, or the answer is another code.
	 */
	protected function getReplyString( Array $successCodes, String $commandLabel='the greeting' ): String {
		$replyTxt = '';
		do{
			$replyLne = fgets($this->socketLink, 1024);
			if( $replyLne===false ){
				$metaList = stream_get_meta_data($this->socketLink);
				throw new MailFailure( !empty($metaList['timed_out']) ? 'the server did not answer in time' : 'the link broke' );
			}
			$replyTxt .= $replyLne;
		}while( strlen($replyLne)>=4 && $replyLne[3]=='-' );
		if( !in_array(intval(substr($replyLne, 0, 3)), $successCodes, true) ){
			throw new MailFailure( json_encode($commandLabel).' refused: '.trim($replyLne) );
		}
		return $replyTxt;
	}

	/**
	 * Closes the link to the server, when one is open.
	 */
	protected function dropServerLink(): Void {
		if( is_resource($this->socketLink) ){
			@fclose($this->socketLink);
		}
		$this->socketLink = null;
	}

	/**
	 * Gives the name this machine greets the server with.
	 *
	 * @return String The host name, or 'localhost' when it is not a plain one.
	 */
	protected function getMachineName(): String {
		$hostName = strval(gethostname());
		return preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/D', $hostName) ? $hostName : 'localhost';
	}
}
