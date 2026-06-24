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

	protected $sockLink;

	/**
	 * Sends a mail through the SMTP server of the account.
	 *
	 * @param Message $mailMessge The mail.
	 * @param Array   $recipients Checked addresses it is delivered to.
	 * @return ?String Null when the server took it, or why it was not sent.
	 *
	 * @throws MailFailure When the mail has attachments.
	 */
	public function sendMssge( Message $mailMessge, Array $recipients ): ?String {
		if( !empty($mailMessge->attachLst()) ){
			throw new MailFailure('A native mail account sends no attachment: use a phmail account');
		}
		try{
			$this->connectTo();
			$this->sendCmmnd('MAIL FROM:<'.$mailMessge->getSender().'>', Array(250));
			foreach( $recipients as $rcpAdr ){
				$this->sendCmmnd('RCPT TO:<'.$rcpAdr.'>', Array(250, 251));
			}
			$this->sendCmmnd('DATA', Array(354));
			// A line starting with a dot gets a second one: a lone dot ends the mail
			$mailTxt = preg_replace('/^\./m', '..', Message::crlfLines($mailMessge->buildText()));
			$this->sendCmmnd(rtrim($mailTxt, "\r\n")."\r\n.", Array(250), 'the mail');
			$this->sendCmmnd('QUIT', Array(221));
		}catch( MailFailure $e ){
			return $e->getMessage();
		}finally{
			$this->closeLink();
		}
		return null;
	}

	/**
	 * Connects to the server, greets it, encrypts the link and logs in.
	 *
	 * @throws MailFailure When any step fails.
	 */
	protected function connectTo(): Void {
		$srvHost = strval($this->setupArr['host'] ?? '');
		$srvPort = strval($this->setupArr['port'] ?? '');
		$srvPort = ($srvPort=='') ? 587 : intval($srvPort);
		$waitSec = intval($this->setupArr['wait'] ?? '') ?: 10;
		if( $srvHost=='' || $srvPort<=0 ){
			throw new MailFailure('the account has no server');
		}
		$sslOpts = Array('ssl'=>Array('verify_peer'=>true, 'verify_peer_name'=>true, 'peer_name'=>$srvHost, 'SNI_enabled'=>true));
		$srvAddr = (strpos($srvHost, ':')!==false) ? '['.trim($srvHost, '[]').']' : $srvHost;
		$errCode = 0;
		$errText = '';
		$sckLink = @stream_socket_client( (($srvPort==465) ? 'tls://' : 'tcp://').$srvAddr.':'.$srvPort, $errCode, $errText, $waitSec, STREAM_CLIENT_CONNECT, stream_context_create($sslOpts) );
		if( $sckLink===false ){
			throw new MailFailure('no connection to '.$srvHost.':'.$srvPort.' ('.trim($errCode.' '.$errText).')');
		}
		$this->sockLink = $sckLink;
		stream_set_timeout($this->sockLink, $waitSec);
		$this->readReply(Array(220));
		$ehloTxt = $this->sendCmmnd('EHLO '.$this->localName(), Array(250));
		if( $srvPort!=465 ){
			if( preg_match('/^\d{3}[ -]STARTTLS\b/mi', $ehloTxt) ){
				$this->sendCmmnd('STARTTLS', Array(220));
				$cryptos = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
				if( @stream_socket_enable_crypto($this->sockLink, true, $cryptos)!==true ){
					throw new MailFailure('the encryption of the link failed');
				}
				$ehloTxt = $this->sendCmmnd('EHLO '.$this->localName(), Array(250));
			}elseif( !self::localHost($srvHost) ){
				throw new MailFailure('the server offers no STARTTLS: mail is never sent unencrypted');
			}
		}
		$this->logInSrvr($ehloTxt);
	}

	/**
	 * Logs in, when the account has a user: AUTH PLAIN when the server
	 * offers it, else AUTH LOGIN.
	 *
	 * @param String $ehloAnswer The answer of the server to EHLO.
	 *
	 * @throws MailFailure When the server offers neither, or refuses the login.
	 */
	protected function logInSrvr( String $ehloAnswer ): Void {
		$userNme = strval($this->setupArr['user'] ?? '');
		if( $userNme=='' ){
			return;
		}
		$passTxt = strval($this->setupArr['pass'] ?? '');
		$authLst = Array();
		if( preg_match('/^\d{3}[ -]AUTH[ =](.*)$/mi', $ehloAnswer, $authFnd) ){
			$authLst = preg_split('/\s+/', strtoupper(trim($authFnd[1])));
		}
		if( in_array('PLAIN', $authLst, true) ){
			$this->sendCmmnd('AUTH PLAIN '.base64_encode("\0".$userNme."\0".$passTxt), Array(235), 'AUTH PLAIN');
		}elseif( in_array('LOGIN', $authLst, true) ){
			$this->sendCmmnd('AUTH LOGIN', Array(334));
			$this->sendCmmnd(base64_encode($userNme), Array(334), 'the user name');
			$this->sendCmmnd(base64_encode($passTxt), Array(235), 'the password');
		}else{
			throw new MailFailure('the server offers no login the account can use (PLAIN or LOGIN)');
		}
	}

	/**
	 * Sends one command and reads the answer.
	 *
	 * @param String  $commndLine  The command, without its line end.
	 * @param Array   $expctCodes The answer codes that mean it was taken.
	 * @param ?String $shownLabel What the command is called in a failure, for a command carrying a secret; null to show the command itself.
	 * @return String The answer of the server.
	 *
	 * @throws MailFailure When the link breaks, or the answer is another code.
	 */
	protected function sendCmmnd( String $commndLine, Array $expctCodes, ?String $shownLabel=null ): String {
		if( @fwrite($this->sockLink, $commndLine."\r\n")===false ){
			throw new MailFailure('the link broke');
		}
		$cmdName = ($shownLabel!==null) ? $shownLabel : strtok($commndLine, "\r\n");
		return $this->readReply($expctCodes, $cmdName);
	}

	/**
	 * Reads one answer of the server, all of its lines.
	 *
	 * @param Array  $expctCodes The answer codes that mean the last command was taken.
	 * @param String $commndLabl What the last command is called in a failure.
	 * @return String The answer.
	 *
	 * @throws MailFailure When the link breaks or times out, or the answer is another code.
	 */
	protected function readReply( Array $expctCodes, String $commndLabl='the greeting' ): String {
		$rplText = '';
		do{
			$rplLine = fgets($this->sockLink, 1024);
			if( $rplLine===false ){
				$metaArr = stream_get_meta_data($this->sockLink);
				throw new MailFailure( !empty($metaArr['timed_out']) ? 'the server did not answer in time' : 'the link broke' );
			}
			$rplText .= $rplLine;
		}while( strlen($rplLine)>=4 && $rplLine[3]=='-' );
		if( !in_array(intval(substr($rplLine, 0, 3)), $expctCodes, true) ){
			throw new MailFailure( json_encode($commndLabl).' refused: '.trim($rplLine) );
		}
		return $rplText;
	}

	/**
	 * Closes the link to the server, when one is open.
	 */
	protected function closeLink(): Void {
		if( is_resource($this->sockLink) ){
			@fclose($this->sockLink);
		}
		$this->sockLink = null;
	}

	/**
	 * Gives the name this machine greets the server with.
	 *
	 * @return String The host name, or 'localhost' when it is not a plain one.
	 */
	protected function localName(): String {
		$hstName = strval(gethostname());
		return preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/D', $hstName) ? $hstName : 'localhost';
	}
}
