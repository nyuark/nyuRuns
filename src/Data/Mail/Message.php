<?php

namespace nyu\Data\Mail;

/**
 * One mail as it is asked for: its sender, recipients, subject, plain
 * text, optional HTML and attachments. Every address, the subject and the
 * sender name are checked when it is built: a line break in any of them
 * would add headers nobody wrote. The mail is written out (buildText) as
 * UTF-8, quoted-printable, in text and HTML when both are given.
 */
class Message {

	protected $senderAddr;
	protected $senderName;
	protected $recipients;
	protected $subjectTxt;
	protected $bodyAsText;
	protected $bodyAsHtml;
	protected $addedFiles;

	/**
	 * Builds a mail, checking what goes into its headers.
	 *
	 * @param String  $fromEmailAdr Address of the sender.
	 * @param String  $nameOfSender Name shown for the sender, empty for none.
	 * @param Array   $recipientLst Addresses of the recipients, one at least.
	 * @param String  $emailSubject Subject of the mail.
	 * @param String  $plainMailTxt Plain text of the mail.
	 * @param ?String $htmlMailText HTML version of the mail, null for a plain text mail.
	 * @param Array   $attachedList Paths of the files attached.
	 *
	 * @throws MailFailure When an address is not valid, there is no recipient, the subject or the name holds a line break, or an attached file cannot be read.
	 */
	public function __construct( String $fromEmailAdr, String $nameOfSender, Array $recipientLst, String $emailSubject, String $plainMailTxt, ?String $htmlMailText=null, Array $attachedList=Array() ){
		$this->senderAddr = self::checkEmailAddr($fromEmailAdr);
		$this->senderName = self::testLineString($nameOfSender, 'sender name');
		$this->recipients = Array();
		foreach( $recipientLst as $toAddr ){
			$this->recipients[] = self::checkEmailAddr(strval($toAddr));
		}
		if( empty($this->recipients) ){
			throw new MailFailure('A mail needs a recipient');
		}
		$this->subjectTxt = self::testLineString($emailSubject, 'subject');
		$this->bodyAsText = $plainMailTxt;
		$this->bodyAsHtml = $htmlMailText;
		$this->addedFiles = Array();
		foreach( $attachedList as $attFil ){
			$attFil = strval($attFil);
			if( preg_match('/[\r\n\0]/', $attFil) || !is_file($attFil) || !is_readable($attFil) ){
				throw new MailFailure('Attachment not found: '.json_encode($attFil));
			}
			$this->addedFiles[] = $attFil;
		}
	}

	/**
	 * Checks one mail address: a valid address, with no line break.
	 *
	 * @param String $emlAddresses The address.
	 * @return String The address, without the spaces around it.
	 *
	 * @throws MailFailure When it is not a valid address.
	 */
	public static function checkEmailAddr( String $emlAddresses ): String {
		$trimdTxt = trim($emlAddresses);
		if( preg_match('/[\r\n\0]/', $trimdTxt) || filter_var($trimdTxt, FILTER_VALIDATE_EMAIL)===false ){
			throw new MailFailure('Invalid mail address: '.json_encode($emlAddresses));
		}
		return $trimdTxt;
	}

	/**
	 * Splits a list of addresses, given as a list or as one text where
	 * they are separated by commas, leaving out the empty ones.
	 *
	 * @param Array|String $givenAddrLst The addresses.
	 * @return Array The addresses, unchecked.
	 */
	public static function splitAddresses( Array|String $givenAddrLst ): Array {
		$addrList = is_array($givenAddrLst) ? $givenAddrLst : explode(',', $givenAddrLst);
		return array_values( array_filter( array_map('trim', array_map('strval', $addrList)), 'strlen' ) );
	}

	/**
	 * Refuses a line break in a text written into a header.
	 *
	 * @param String $textOfHeader The text.
	 * @param String $failureLabel What the text is, for the message of the failure.
	 * @return String The text.
	 *
	 * @throws MailFailure When the text holds a line break.
	 */
	protected static function testLineString( String $textOfHeader, String $failureLabel ): String {
		if( preg_match('/[\r\n\0]/', $textOfHeader) ){
			throw new MailFailure('Line break refused in the '.$failureLabel.' of a mail');
		}
		return $textOfHeader;
	}

	/**
	 * Gives the address of the sender.
	 *
	 * @return String The address.
	 */
	public function retrieveSender(): String {
		return $this->senderAddr;
	}

	/**
	 * Gives the name shown for the sender.
	 *
	 * @return String The name, empty for none.
	 */
	public function getSenderLabel(): String {
		return $this->senderName;
	}

	/**
	 * Gives the addresses of the recipients.
	 *
	 * @return Array The addresses.
	 */
	public function listRecipients(): Array {
		return $this->recipients;
	}

	/**
	 * Gives the subject.
	 *
	 * @return String The subject.
	 */
	public function getSubjectText(): String {
		return $this->subjectTxt;
	}

	/**
	 * Gives the plain text of the mail.
	 *
	 * @return String The text.
	 */
	public function simpleBodyText(): String {
		return $this->bodyAsText;
	}

	/**
	 * Gives the HTML version of the mail.
	 *
	 * @return ?String The HTML, null for a plain text mail.
	 */
	public function getHtmlVersion(): ?String {
		return $this->bodyAsHtml;
	}

	/**
	 * Gives the paths of the files attached.
	 *
	 * @return Array The paths.
	 */
	public function getAttachments(): Array {
		return $this->addedFiles;
	}

	/**
	 * Writes the mail out as it is sent: its headers, then its text, or
	 * its text and HTML as two alternatives. Lines end with CRLF; the
	 * attachments are not part of it.
	 *
	 * @return String The mail.
	 */
	public function writeWholeMail(): String {
		$sndrDomn = substr(strrchr($this->senderAddr, '@'), 1);
		$headList = Array(
			'Date: '.date('r'),
			'From: '.self::writeNamedAddr($this->senderAddr, $this->senderName),
			'To: '.implode(', ', $this->recipients),
			'Subject: '.self::encodeHeadText($this->subjectTxt),
			'Message-ID: <'.bin2hex(random_bytes(16)).'@'.$sndrDomn.'>',
			'MIME-Version: 1.0',
		);
		if( $this->bodyAsHtml===null ){
			return implode("\r\n", $headList)."\r\n".self::makePartString($this->bodyAsText, 'plain');
		}
		$boundary = '=_nyu_'.bin2hex(random_bytes(12));
		$headList[] = 'Content-Type: multipart/alternative; boundary="'.$boundary.'"';
		return implode("\r\n", $headList)."\r\n\r\n"
			.'--'.$boundary."\r\n".self::makePartString($this->bodyAsText, 'plain')."\r\n"
			.'--'.$boundary."\r\n".self::makePartString($this->bodyAsHtml, 'html')."\r\n"
			.'--'.$boundary.'--'."\r\n";
	}

	/**
	 * Writes one text part: its two headers, a blank line, then the text
	 * in quoted-printable.
	 *
	 * @param String $partBodyText The text.
	 * @param String $mimeTextType 'plain' or 'html'.
	 * @return String The part.
	 */
	protected static function makePartString( String $partBodyText, String $mimeTextType ): String {
		return 'Content-Type: text/'.$mimeTextType.'; charset=UTF-8'."\r\n"
			.'Content-Transfer-Encoding: quoted-printable'."\r\n\r\n"
			.quoted_printable_encode( self::endLinesInCrlf($partBodyText) );
	}

	/**
	 * Makes every line of a text end with CRLF, whatever it ended with.
	 *
	 * @param String $textToChange The text.
	 * @return String The text with CRLF line ends.
	 */
	public static function endLinesInCrlf( String $textToChange ): String {
		return str_replace( "\n", "\r\n", str_replace(Array("\r\n", "\r"), "\n", $textToChange) );
	}

	/**
	 * Writes an address with the name shown for it.
	 *
	 * @param String $emlAddresses The address.
	 * @param String $personalName The name, empty for none.
	 * @return String The address as a header writes it.
	 */
	protected static function writeNamedAddr( String $emlAddresses, String $personalName ): String {
		if( $personalName=='' ){
			return $emlAddresses;
		}
		if( preg_match('/[^\x20-\x7E]/', $personalName) ){
			return self::encodeHeadText($personalName).' <'.$emlAddresses.'>';
		}
		return '"'.addcslashes($personalName, '"\\').'" <'.$emlAddresses.'>';
	}

	/**
	 * Encodes a header text that is not plain ASCII as UTF-8 encoded words
	 * (RFC 2047), each short enough for one line, never cutting a
	 * character in two. Plain ASCII is kept as it is.
	 *
	 * @param String $textOfHeader The text.
	 * @return String The text as a header writes it.
	 */
	public static function encodeHeadText( String $textOfHeader ): String {
		if( !preg_match('/[^\x20-\x7E]/', $textOfHeader) ){
			return $textOfHeader;
		}
		$charList = preg_split('//u', $textOfHeader, -1, PREG_SPLIT_NO_EMPTY);
		if( $charList===false ){
			// Not valid UTF-8: cut by bytes
			$charList = str_split($textOfHeader);
		}
		$chunkLst = Array('');
		foreach( $charList as $letter ){
			$lastIndx = count($chunkLst)-1;
			if( strlen($chunkLst[$lastIndx].$letter)>45 ){
				$chunkLst[] = '';
				$lastIndx++;
			}
			$chunkLst[$lastIndx] .= $letter;
		}
		$wordList = Array();
		foreach( $chunkLst as $oneChk ){
			$wordList[] = '=?UTF-8?B?'.base64_encode($oneChk).'?=';
		}
		return implode("\r\n ", $wordList);
	}
}
