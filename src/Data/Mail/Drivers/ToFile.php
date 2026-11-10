<?php

namespace nyu\Data\Mail\Drivers;

use \nyu\Data\Mail\Message;

/**
 * Writes each mail as a file and sends nothing: for development and
 * tests. The folder is the account's 'fold', read from the project root
 * with or without a leading slash, temp/mails when not set. Each file is
 * the mail as it would be sent, after an X-Envelope-To header naming the
 * addresses it was for, and an X-Attachments header naming the files.
 */
class ToFile extends Driver {

	/**
	 * Writes a mail into the folder of the account.
	 *
	 * @param Message $emailMessage The mail.
	 * @param Array   $recipientLst Checked addresses it is for.
	 * @return ?String Null when it was written, or why it was not.
	 */
	public function deliverMessage( Message $emailMessage, Array $recipientLst ): ?String {
		$fldPath = $this->emailsFldrText();
		if( !is_dir($fldPath) && !@mkdir($fldPath, 0775, true) && !is_dir($fldPath) ){
			return 'the folder '.json_encode($fldPath).' cannot be created';
		}
		$headTxt = 'X-Envelope-To: '.implode(', ', $recipientLst)."\r\n";
		$attNmes = array_map('basename', $emailMessage->getAttachments());
		if( !empty($attNmes) ){
			$headTxt .= 'X-Attachments: '.Message::encodeHeadText(implode(', ', $attNmes))."\r\n";
		}
		$filPath = $fldPath.DIRECTORY_SEPARATOR.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.eml';
		if( @file_put_contents($filPath, $headTxt.$emailMessage->writeWholeMail(), LOCK_EX)===false ){
			return 'the file '.json_encode($filPath).' cannot be written';
		}
		return null;
	}

	/**
	 * Gives the folder the mails are written in.
	 *
	 * @return String Its full path.
	 */
	public function emailsFldrText(): String {
		$fldName = trim( strval($this->setupArray['mailsDir'] ?? ''), '/\\ ' );
		return TOP_DIR.DIRECTORY_SEPARATOR.( $fldName!='' ? str_replace(Array('/', '\\'), DIRECTORY_SEPARATOR, $fldName) : 'temp'.DIRECTORY_SEPARATOR.'mails' );
	}
}
