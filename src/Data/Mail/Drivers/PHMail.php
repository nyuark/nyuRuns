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
	 * @param Array $acntSetupArr The settings of the account (see Mail\Mailer::acntSetupArray).
	 *
	 * @throws MailFailure When PHPMailer is not installed.
	 */
	public function __construct( Array $acntSetupArr ){
		if( !class_exists(self::MAIL_CLSS) ){
			throw new MailFailure('PHPMailer is not installed: composer require phpmailer/phpmailer');
		}
		parent::__construct($acntSetupArr);
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
			$phpEmail = $this->buildMailArray($emailMessage);
			if( $recipientLst===$emailMessage->listRecipients() ){
				$phpEmail->send();
				return null;
			}
			return $this->sendMailCopies($phpEmail, $recipientLst);
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
		$mailClss = self::MAIL_CLSS;
		$phpEmail = new $mailClss(true);
		$portNumb = intval($this->setupArray['portNumb'] ?? '') ?: 587;
		$phpEmail->isSMTP();
		$phpEmail->Host = strval($this->setupArray['hostName'] ?? '');
		$phpEmail->Port = $portNumb;
		$phpEmail->Timeout = intval($this->setupArray['waitSecs'] ?? '') ?: 10;
		$phpEmail->CharSet = 'UTF-8';
		if( $portNumb==465 ){
			$phpEmail->SMTPSecure = 'ssl';
		}elseif( !self::localServerFlg($phpEmail->Host) ){
			$phpEmail->SMTPSecure = 'tls';
		}
		$userName = strval($this->setupArray['userName'] ?? '');
		if( $userName!='' ){
			$phpEmail->SMTPAuth = true;
			$phpEmail->Username = $userName;
			$phpEmail->Password = strval($this->setupArray['passWord'] ?? '');
		}
		$phpEmail->setFrom($emailMessage->retrieveSender(), $emailMessage->getSenderLabel());
		foreach( $emailMessage->listRecipients() as $toAddr ){
			$phpEmail->addAddress($toAddr);
		}
		$phpEmail->Subject = $emailMessage->getSubjectText();
		if( $emailMessage->getHtmlVersion()!==null ){
			$phpEmail->isHTML(true);
			$phpEmail->Body = $emailMessage->getHtmlVersion();
			$phpEmail->AltBody = $emailMessage->simpleBodyText();
		}else{
			$phpEmail->Body = $emailMessage->simpleBodyText();
		}
		foreach( $emailMessage->getAttachments() as $attFil ){
			$phpEmail->addAttachment($attFil);
		}
		return $phpEmail;
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
		$smtpLink = $phpMailerObj->getSMTPInstance();
		$isMailOk = $smtpLink->mail($phpMailerObj->Sender ?: $phpMailerObj->From);
		foreach( $recipientLst as $toAddr ){
			$isMailOk = $isMailOk && $smtpLink->recipient($toAddr);
		}
		$isMailOk = $isMailOk && $smtpLink->data($phpMailerObj->getSentMIMEMessage());
		$errorDct = $smtpLink->getError();
		$phpMailerObj->smtpClose();
		if( !$isMailOk ){
			return trim( strval($errorDct['error'] ?? '').' '.strval($errorDct['smtp_code'] ?? '').' '.strval($errorDct['detail'] ?? '') );
		}
		return null;
	}
}
