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

	protected $sndrAddr;
	protected $sndrName;
	protected $rcptList;
	protected $subjLine;
	protected $textBody;
	protected $htmlBody;
	protected $attaList;

	/**
	 * Builds a mail, checking what goes into its headers.
	 *
	 * @param String  $senderAddr Address of the sender.
	 * @param String  $senderName Name shown for the sender, empty for none.
	 * @param Array   $recipients Addresses of the recipients, one at least.
	 * @param String  $subjctLine Subject of the mail.
	 * @param String  $messgeBody Plain text of the mail.
	 * @param ?String $htmlMessge HTML version of the mail, null for a plain text mail.
	 * @param Array   $attachList Paths of the files attached.
	 *
	 * @throws MailFailure When an address is not valid, there is no recipient, the subject or the name holds a line break, or an attached file cannot be read.
	 */
	public function __construct( String $senderAddr, String $senderName, Array $recipients, String $subjctLine, String $messgeBody, ?String $htmlMessge=null, Array $attachList=Array() ){
		$this->sndrAddr = self::checkAddr($senderAddr);
		$this->sndrName = self::checkLine($senderName, 'sender name');
		$this->rcptList = Array();
		foreach( $recipients as $rcpAdr ){
			$this->rcptList[] = self::checkAddr(strval($rcpAdr));
		}
		if( empty($this->rcptList) ){
			throw new MailFailure('A mail needs a recipient');
		}
		$this->subjLine = self::checkLine($subjctLine, 'subject');
		$this->textBody = $messgeBody;
		$this->htmlBody = $htmlMessge;
		$this->attaList = Array();
		foreach( $attachList as $attFle ){
			$attFle = strval($attFle);
			if( preg_match('/[\r\n\0]/', $attFle) || !is_file($attFle) || !is_readable($attFle) ){
				throw new MailFailure('Attachment not found: '.json_encode($attFle));
			}
			$this->attaList[] = $attFle;
		}
	}

	/**
	 * Checks one mail address: a valid address, with no line break.
	 *
	 * @param String $emailAddrs The address.
	 * @return String The address, without the spaces around it.
	 *
	 * @throws MailFailure When it is not a valid address.
	 */
	public static function checkAddr( String $emailAddrs ): String {
		$trimmed = trim($emailAddrs);
		if( preg_match('/[\r\n\0]/', $trimmed) || filter_var($trimmed, FILTER_VALIDATE_EMAIL)===false ){
			throw new MailFailure('Invalid mail address: '.json_encode($emailAddrs));
		}
		return $trimmed;
	}

	/**
	 * Splits a list of addresses, given as a list or as one text where
	 * they are separated by commas, leaving out the empty ones.
	 *
	 * @param Array|String $addrsGiven The addresses.
	 * @return Array The addresses, unchecked.
	 */
	public static function addrsList( Array|String $addrsGiven ): Array {
		$addrLst = is_array($addrsGiven) ? $addrsGiven : explode(',', $addrsGiven);
		return array_values( array_filter( array_map('trim', array_map('strval', $addrLst)), 'strlen' ) );
	}

	/**
	 * Refuses a line break in a text written into a header.
	 *
	 * @param String $headerText The text.
	 * @param String $textsLabel What the text is, for the message of the failure.
	 * @return String The text.
	 *
	 * @throws MailFailure When the text holds a line break.
	 */
	protected static function checkLine( String $headerText, String $textsLabel ): String {
		if( preg_match('/[\r\n\0]/', $headerText) ){
			throw new MailFailure('Line break refused in the '.$textsLabel.' of a mail');
		}
		return $headerText;
	}

	/**
	 * Gives the address of the sender.
	 *
	 * @return String The address.
	 */
	public function getSender(): String {
		return $this->sndrAddr;
	}

	/**
	 * Gives the name shown for the sender.
	 *
	 * @return String The name, empty for none.
	 */
	public function senderTag(): String {
		return $this->sndrName;
	}

	/**
	 * Gives the addresses of the recipients.
	 *
	 * @return Array The addresses.
	 */
	public function recipList(): Array {
		return $this->rcptList;
	}

	/**
	 * Gives the subject.
	 *
	 * @return String The subject.
	 */
	public function getSubjct(): String {
		return $this->subjLine;
	}

	/**
	 * Gives the plain text of the mail.
	 *
	 * @return String The text.
	 */
	public function plainBody(): String {
		return $this->textBody;
	}

	/**
	 * Gives the HTML version of the mail.
	 *
	 * @return ?String The HTML, null for a plain text mail.
	 */
	public function markupTxt(): ?String {
		return $this->htmlBody;
	}

	/**
	 * Gives the paths of the files attached.
	 *
	 * @return Array The paths.
	 */
	public function attachLst(): Array {
		return $this->attaList;
	}

	/**
	 * Writes the mail out as it is sent: its headers, then its text, or
	 * its text and HTML as two alternatives. Lines end with CRLF; the
	 * attachments are not part of it.
	 *
	 * @return String The mail.
	 */
	public function buildText(): String {
		$sndDomn = substr(strrchr($this->sndrAddr, '@'), 1);
		$headLst = Array(
			'Date: '.date('r'),
			'From: '.self::addrHeadr($this->sndrAddr, $this->sndrName),
			'To: '.implode(', ', $this->rcptList),
			'Subject: '.self::encodHead($this->subjLine),
			'Message-ID: <'.bin2hex(random_bytes(16)).'@'.$sndDomn.'>',
			'MIME-Version: 1.0',
		);
		if( $this->htmlBody===null ){
			return implode("\r\n", $headLst)."\r\n".self::buildPart($this->textBody, 'plain');
		}
		$boundry = '=_nyu_'.bin2hex(random_bytes(12));
		$headLst[] = 'Content-Type: multipart/alternative; boundary="'.$boundry.'"';
		return implode("\r\n", $headLst)."\r\n\r\n"
			.'--'.$boundry."\r\n".self::buildPart($this->textBody, 'plain')."\r\n"
			.'--'.$boundry."\r\n".self::buildPart($this->htmlBody, 'html')."\r\n"
			.'--'.$boundry.'--'."\r\n";
	}

	/**
	 * Writes one text part: its two headers, a blank line, then the text
	 * in quoted-printable.
	 *
	 * @param String $bodyOfPart The text.
	 * @param String $mimeSubtyp 'plain' or 'html'.
	 * @return String The part.
	 */
	protected static function buildPart( String $bodyOfPart, String $mimeSubtyp ): String {
		return 'Content-Type: text/'.$mimeSubtyp.'; charset=UTF-8'."\r\n"
			.'Content-Transfer-Encoding: quoted-printable'."\r\n\r\n"
			.quoted_printable_encode( self::crlfLines($bodyOfPart) );
	}

	/**
	 * Makes every line of a text end with CRLF, whatever it ended with.
	 *
	 * @param String $sourceText The text.
	 * @return String The text with CRLF line ends.
	 */
	public static function crlfLines( String $sourceText ): String {
		return str_replace( "\n", "\r\n", str_replace(Array("\r\n", "\r"), "\n", $sourceText) );
	}

	/**
	 * Writes an address with the name shown for it.
	 *
	 * @param String $emailAddrs The address.
	 * @param String $displyName The name, empty for none.
	 * @return String The address as a header writes it.
	 */
	protected static function addrHeadr( String $emailAddrs, String $displyName ): String {
		if( $displyName=='' ){
			return $emailAddrs;
		}
		if( preg_match('/[^\x20-\x7E]/', $displyName) ){
			return self::encodHead($displyName).' <'.$emailAddrs.'>';
		}
		return '"'.addcslashes($displyName, '"\\').'" <'.$emailAddrs.'>';
	}

	/**
	 * Encodes a header text that is not plain ASCII as UTF-8 encoded words
	 * (RFC 2047), each short enough for one line, never cutting a
	 * character in two. Plain ASCII is kept as it is.
	 *
	 * @param String $headerText The text.
	 * @return String The text as a header writes it.
	 */
	public static function encodHead( String $headerText ): String {
		if( !preg_match('/[^\x20-\x7E]/', $headerText) ){
			return $headerText;
		}
		$charLst = preg_split('//u', $headerText, -1, PREG_SPLIT_NO_EMPTY);
		if( $charLst===false ){
			// Not valid UTF-8: cut by bytes
			$charLst = str_split($headerText);
		}
		$chnkLst = Array('');
		foreach( $charLst as $oneChr ){
			$lastIdx = count($chnkLst)-1;
			if( strlen($chnkLst[$lastIdx].$oneChr)>45 ){
				$chnkLst[] = '';
				$lastIdx++;
			}
			$chnkLst[$lastIdx] .= $oneChr;
		}
		$wordLst = Array();
		foreach( $chnkLst as $oneChk ){
			$wordLst[] = '=?UTF-8?B?'.base64_encode($oneChk).'?=';
		}
		return implode("\r\n ", $wordLst);
	}
}
