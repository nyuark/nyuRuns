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

	const MAIL_CLSS = '\\PHPMailer\\PHPMailer\\PHPMailer';

	/**
	 * Builds the driver, once the library is found.
	 *
	 * @param Array $accontConfig The settings of the account (see Mail\Mailer::acntSetupArray).
	 *
	 * @throws MailFailure When PHPMailer is not installed.
	 */
	public function __construct( Array $accontConfig ){
		if( !class_exists(self::MAIL_CLSS) ){
			throw new MailFailure('PHPMailer is not installed: composer require phpmailer/phpmailer');
		}
		parent::__construct($accontConfig);
	}

	/**
	 * Sends a mail through the SMTP server of the account. A copy, sent to
	 * other addresses than those of the mail, is the same mail sent again
	 * on the link of PHPMailer, with only those addresses as recipients.
	 *
	 * @param Message $emailMessage The mail.
	 * @param Array   $recipientLst Checked addresses it is delivered to.
	 * @return ?String Null when the server took it, or why it was not sent.
	 */
	public function deliverMessage( Message $emailMessage, Array $recipientLst ): ?String {
		try{
			$phpMail = $this->buildMailArray($emailMessage);
			if( $recipientLst===$emailMessage->listRecipients() ){
				$phpMail->send();
				return null;
			}
			return $this->sendMailCopies($phpMail, $recipientLst);
		}catch( \Exception $e ){
			return $e->getMessage();
		}
	}

	/**
	 * Builds the mail with PHPMailer, set for the server of the account.
	 *
	 * @param Message $emailMessage The mail.
	 * @return Object The PHPMailer object, ready to send.
	 */
	protected function buildMailArray( Message $emailMessage ): Object {
		$mailCls = self::MAIL_CLSS;
		$phpMail = new $mailCls(true);
		$portNum = intval($this->setupArray['portNumb'] ?? '') ?: 587;
		$phpMail->isSMTP();
		$phpMail->Host = strval($this->setupArray['hostName'] ?? '');
		$phpMail->Port = $portNum;
		$phpMail->Timeout = intval($this->setupArray['waitSecs'] ?? '') ?: 10;
		$phpMail->CharSet = 'UTF-8';
		if( $portNum==465 ){
			$phpMail->SMTPSecure = 'ssl';
		}elseif( !self::localServerFlg($phpMail->Host) ){
			$phpMail->SMTPSecure = 'tls';
		}
		$userNme = strval($this->setupArray['userName'] ?? '');
		if( $userNme!='' ){
			$phpMail->SMTPAuth = true;
			$phpMail->Username = $userNme;
			$phpMail->Password = strval($this->setupArray['password'] ?? '');
		}
		$phpMail->setFrom($emailMessage->retrieveSender(), $emailMessage->getSenderLabel());
		foreach( $emailMessage->listRecipients() as $toAddr ){
			$phpMail->addAddress($toAddr);
		}
		$phpMail->Subject = $emailMessage->getSubjectText();
		if( $emailMessage->getHtmlVersion()!==null ){
			$phpMail->isHTML(true);
			$phpMail->Body = $emailMessage->getHtmlVersion();
			$phpMail->AltBody = $emailMessage->simpleBodyText();
		}else{
			$phpMail->Body = $emailMessage->simpleBodyText();
		}
		foreach( $emailMessage->getAttachments() as $attFil ){
			$phpMail->addAttachment($attFil);
		}
		return $phpMail;
	}

	/**
	 * Sends the mail built by PHPMailer, as it is, to other addresses than
	 * the ones it names.
	 *
	 * @param Object $phpMailerObj The PHPMailer object, ready to send.
	 * @param Array  $recipientLst Checked addresses it is delivered to.
	 * @return ?String Null when the server took it, or why it was not sent.
	 */
	protected function sendMailCopies( Object $phpMailerObj, Array $recipientLst ): ?String {
		$phpMailerObj->preSend();
		if( !$phpMailerObj->smtpConnect() ){
			return 'no connection to the server';
		}
		$smtpLnk = $phpMailerObj->getSMTPInstance();
		$sentAll = $smtpLnk->mail($phpMailerObj->Sender ?: $phpMailerObj->From);
		foreach( $recipientLst as $toAddr ){
			$sentAll = $sentAll && $smtpLnk->recipient($toAddr);
		}
		$sentAll = $sentAll && $smtpLnk->data($phpMailerObj->getSentMIMEMessage());
		$errInfo = $smtpLnk->getError();
		$phpMailerObj->smtpClose();
		if( !$sentAll ){
			return trim( strval($errInfo['error'] ?? '').' '.strval($errInfo['smtp_code'] ?? '').' '.strval($errInfo['detail'] ?? '') );
		}
		return null;
	}
}
