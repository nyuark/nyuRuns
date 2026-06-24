<?php

namespace nyu\Data\Mail\Drivers;

use \nyu\Data\Mail\Message;
use \nyu\Data\Mail\MailFailure;

/**
 * Sends through the PHPMailer library (phpmailer/phpmailer, suggested by
 * the framework, not required): attachments, and what the native client
 * does not do. The encryption follows the same rule: 465 is TLS from the
 * first byte, any other port needs STARTTLS, except to this machine.
 */
class PHMail extends Driver {

	const MAILCLSS = '\\PHPMailer\\PHPMailer\\PHPMailer';

	/**
	 * Builds the driver, once the library is found.
	 *
	 * @param Array $acntConfig The settings of the account (see Mail\Mailer::acntSetup).
	 *
	 * @throws MailFailure When PHPMailer is not installed.
	 */
	public function __construct( Array $acntConfig ){
		if( !class_exists(self::MAILCLSS) ){
			throw new MailFailure('PHPMailer is not installed: composer require phpmailer/phpmailer');
		}
		parent::__construct($acntConfig);
	}

	/**
	 * Sends a mail through the SMTP server of the account. A copy, sent to
	 * other addresses than those of the mail, is the same mail sent again
	 * on the link of PHPMailer, with only those addresses as recipients.
	 *
	 * @param Message $mailMessge The mail.
	 * @param Array   $recipients Checked addresses it is delivered to.
	 * @return ?String Null when the server took it, or why it was not sent.
	 */
	public function sendMssge( Message $mailMessge, Array $recipients ): ?String {
		try{
			$mailLib = $this->buildMail($mailMessge);
			if( $recipients===$mailMessge->recipList() ){
				$mailLib->send();
				return null;
			}
			return $this->sendsCopy($mailLib, $recipients);
		}catch( \Exception $e ){
			return $e->getMessage();
		}
	}

	/**
	 * Builds the mail with PHPMailer, set for the server of the account.
	 *
	 * @param Message $mailMessge The mail.
	 * @return Object The PHPMailer object, ready to send.
	 */
	protected function buildMail( Message $mailMessge ): Object {
		$mailCls = self::MAILCLSS;
		$mailLib = new $mailCls(true);
		$srvPort = intval($this->setupArr['port'] ?? '') ?: 587;
		$mailLib->isSMTP();
		$mailLib->Host = strval($this->setupArr['host'] ?? '');
		$mailLib->Port = $srvPort;
		$mailLib->Timeout = intval($this->setupArr['wait'] ?? '') ?: 10;
		$mailLib->CharSet = 'UTF-8';
		if( $srvPort==465 ){
			$mailLib->SMTPSecure = 'ssl';
		}elseif( !self::localHost($mailLib->Host) ){
			$mailLib->SMTPSecure = 'tls';
		}
		$userNme = strval($this->setupArr['user'] ?? '');
		if( $userNme!='' ){
			$mailLib->SMTPAuth = true;
			$mailLib->Username = $userNme;
			$mailLib->Password = strval($this->setupArr['pass'] ?? '');
		}
		$mailLib->setFrom($mailMessge->getSender(), $mailMessge->senderTag());
		foreach( $mailMessge->recipList() as $rcpAdr ){
			$mailLib->addAddress($rcpAdr);
		}
		$mailLib->Subject = $mailMessge->getSubjct();
		if( $mailMessge->markupTxt()!==null ){
			$mailLib->isHTML(true);
			$mailLib->Body = $mailMessge->markupTxt();
			$mailLib->AltBody = $mailMessge->plainBody();
		}else{
			$mailLib->Body = $mailMessge->plainBody();
		}
		foreach( $mailMessge->attachLst() as $attFle ){
			$mailLib->addAttachment($attFle);
		}
		return $mailLib;
	}

	/**
	 * Sends the mail built by PHPMailer, as it is, to other addresses than
	 * the ones it names.
	 *
	 * @param Object $mailObject The PHPMailer object, ready to send.
	 * @param Array  $recipients Checked addresses it is delivered to.
	 * @return ?String Null when the server took it, or why it was not sent.
	 */
	protected function sendsCopy( Object $mailObject, Array $recipients ): ?String {
		$mailObject->preSend();
		if( !$mailObject->smtpConnect() ){
			return 'no connection to the server';
		}
		$smtpLnk = $mailObject->getSMTPInstance();
		$sentAll = $smtpLnk->mail($mailObject->Sender ?: $mailObject->From);
		foreach( $recipients as $rcpAdr ){
			$sentAll = $sentAll && $smtpLnk->recipient($rcpAdr);
		}
		$sentAll = $sentAll && $smtpLnk->data($mailObject->getSentMIMEMessage());
		$errInfo = $smtpLnk->getError();
		$mailObject->smtpClose();
		if( !$sentAll ){
			return trim( strval($errInfo['error'] ?? '').' '.strval($errInfo['smtp_code'] ?? '').' '.strval($errInfo['detail'] ?? '') );
		}
		return null;
	}
}
