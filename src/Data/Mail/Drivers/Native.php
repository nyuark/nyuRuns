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
	public function deliverMessage( Message $emailMessage, Array $recipientLst ): ?String {
		if( !empty($emailMessage->getAttachments()) ){
			throw new MailFailure('A native mail account sends no attachment: use a phmail account');
		}
		try{
			$this->connectAndAuth();
			$this->deliverCommand('MAIL FROM:<'.$emailMessage->retrieveSender().'>', Array(250));
			foreach( $recipientLst as $toAddr ){
				$this->deliverCommand('RCPT TO:<'.$toAddr.'>', Array(250, 251));
			}
			$this->deliverCommand('DATA', Array(354));
			// A line starting with a dot gets a second one: a lone dot ends the mail
			$mailTxt = preg_replace('/^\./m', '..', Message::endLinesInCrlf($emailMessage->writeWholeMail()));
			$this->deliverCommand(rtrim($mailTxt, "\r\n")."\r\n.", Array(250), 'the mail');
			$this->deliverCommand('QUIT', Array(221));
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
	protected function connectAndAuth(): Void {
		$srvrHst = strval($this->setupArray['hostName'] ?? '');
		$portNum = strval($this->setupArray['portNumb'] ?? '');
		$portNum = ($portNum=='') ? 587 : intval($portNum);
		$waitSec = intval($this->setupArray['waitSecs'] ?? '') ?: 10;
		if( $srvrHst=='' || $portNum<=0 ){
			throw new MailFailure('the account has no server');
		}
		$tlsConf = Array('ssl'=>Array('verify_peer'=>true, 'verify_peer_name'=>true, 'peer_name'=>$srvrHst, 'SNI_enabled'=>true));
		$hostAdr = (strpos($srvrHst, ':')!==false) ? '['.trim($srvrHst, '[]').']' : $srvrHst;
		$errCode = 0;
		$errText = '';
		$scktLnk = @stream_socket_client( (($portNum==465) ? 'tls://' : 'tcp://').$hostAdr.':'.$portNum, $errCode, $errText, $waitSec, STREAM_CLIENT_CONNECT, stream_context_create($tlsConf) );
		if( $scktLnk===false ){
			throw new MailFailure('no connection to '.$srvrHst.':'.$portNum.' ('.trim($errCode.' '.$errText).')');
		}
		$this->socketLink = $scktLnk;
		stream_set_timeout($this->socketLink, $waitSec);
		$this->getReplyString(Array(220));
		$ehloTxt = $this->deliverCommand('EHLO '.$this->getMachineName(), Array(250));
		if( $portNum!=465 ){
			if( preg_match('/^\d{3}[ -]STARTTLS\b/mi', $ehloTxt) ){
				$this->deliverCommand('STARTTLS', Array(220));
				$cryptos = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
				if( @stream_socket_enable_crypto($this->socketLink, true, $cryptos)!==true ){
					throw new MailFailure('the encryption of the link failed');
				}
				$ehloTxt = $this->deliverCommand('EHLO '.$this->getMachineName(), Array(250));
			}elseif( !self::localServerFlg($srvrHst) ){
				throw new MailFailure('the server offers no STARTTLS: mail is never sent unencrypted');
			}
		}
		$this->signInToServer($ehloTxt);
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
		$userNme = strval($this->setupArray['userName'] ?? '');
		if( $userNme=='' ){
			return;
		}
		$passTxt = strval($this->setupArray['passWord'] ?? '');
		$authLst = Array();
		if( preg_match('/^\d{3}[ -]AUTH[ =](.*)$/mi', $serverAnswer, $authMth) ){
			$authLst = preg_split('/\s+/', strtoupper(trim($authMth[1])));
		}
		if( in_array('PLAIN', $authLst, true) ){
			$this->deliverCommand('AUTH PLAIN '.base64_encode("\0".$userNme."\0".$passTxt), Array(235), 'AUTH PLAIN');
		}elseif( in_array('LOGIN', $authLst, true) ){
			$this->deliverCommand('AUTH LOGIN', Array(334));
			$this->deliverCommand(base64_encode($userNme), Array(334), 'the user name');
			$this->deliverCommand(base64_encode($passTxt), Array(235), 'the password');
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
	protected function deliverCommand( String $plainCommand, Array $successCodes, ?String $displayLabel=null ): String {
		if( @fwrite($this->socketLink, $plainCommand."\r\n")===false ){
			throw new MailFailure('the link broke');
		}
		$command = ($displayLabel!==null) ? $displayLabel : strtok($plainCommand, "\r\n");
		return $this->getReplyString($successCodes, $command);
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
		$rplyTxt = '';
		do{
			$rplyLin = fgets($this->socketLink, 1024);
			if( $rplyLin===false ){
				$metaArr = stream_get_meta_data($this->socketLink);
				throw new MailFailure( !empty($metaArr['timed_out']) ? 'the server did not answer in time' : 'the link broke' );
			}
			$rplyTxt .= $rplyLin;
		}while( strlen($rplyLin)>=4 && $rplyLin[3]=='-' );
		if( !in_array(intval(substr($rplyLin, 0, 3)), $successCodes, true) ){
			throw new MailFailure( json_encode($commandLabel).' refused: '.trim($rplyLin) );
		}
		return $rplyTxt;
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
		$hostNme = strval(gethostname());
		return preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/D', $hostNme) ? $hostNme : 'localhost';
	}
}
