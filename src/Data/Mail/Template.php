<?php

namespace nyu\Data\Mail;

/**
 * A mail made of its templates: a text file, whose first line is the
 * subject and the rest the body, and the HTML file of the same mail, if
 * any.
 *
 * %name% placeholders are filled with the values given, as they are in the
 * text and the subject, HTML-encoded in the HTML; a placeholder without a
 * value, or whose value is not a text or a number, is left as written. A
 * part written between [name] and [/name] is kept only when the value of
 * that name is given and not empty ([link]...[/link] for a mail that may
 * have no link).
 */
class Template {

	protected $subjectTxt;
	protected $bodyString;
	protected $htmlString;

	/**
	 * Reads the templates of a mail.
	 *
	 * @param String  $planTemplate The text file: the subject on its first line, then the body.
	 * @param ?String $htmlFilePath The HTML file, if any.
	 *
	 * @throws \RuntimeException When a file cannot be read.
	 */
	function __construct( String $planTemplate, ?String $htmlFilePath=null ){
		$txtRead = is_file($planTemplate) ? @file_get_contents($planTemplate) : false;
		$htmRead = ($htmlFilePath===null) ? null : ( is_file($htmlFilePath) ? @file_get_contents($htmlFilePath) : false );
		if( $txtRead===false || $htmRead===false ){
			throw new \RuntimeException( 'Mail template unreadable: '.basename($txtRead===false ? $planTemplate : strval($htmlFilePath)) );
		}
		$textLns = explode( "\n", ltrim(str_replace("\r\n", "\n", $txtRead)), 2 );
		$this->subjectTxt = $textLns[0];
		$this->bodyString = ltrim( strval($textLns[1] ?? ''), "\n" );
		$this->htmlString = ($htmRead===null) ? null : str_replace("\r\n", "\n", $htmRead);
	}

	/**
	 * Gives the subject, its placeholders filled, on one line.
	 *
	 * @param Array $placeholders Values of the placeholders, by name.
	 * @return String The subject.
	 */
	public function getSubjectText( Array $placeholders ): String {
		$subText = self::fillNamedSlots( $this->retainPartsTxt($this->subjectTxt, $placeholders), $placeholders, false );
		return trim( str_replace(Array("\r", "\n"), ' ', $subText) );
	}

	/**
	 * Gives the text body, its placeholders filled.
	 *
	 * @param Array $placeholders Values of the placeholders, by name.
	 * @return String The body.
	 */
	public function simpleBodyText( Array $placeholders ): String {
		return self::fillNamedSlots( $this->retainPartsTxt($this->bodyString, $placeholders), $placeholders, false );
	}

	/**
	 * Gives the HTML body, its placeholders filled and HTML-encoded.
	 *
	 * @param Array $placeholders Values of the placeholders, by name.
	 * @return ?String The HTML, or null when the mail has none.
	 */
	public function getHtmlVersion( Array $placeholders ): ?String {
		return ($this->htmlString===null) ? null : self::fillNamedSlots( $this->retainPartsTxt($this->htmlString, $placeholders), $placeholders, true );
	}

	/**
	 * Fills the %name% placeholders of a text. A placeholder without a
	 * value, or whose value is not a text or a number, is left as written.
	 *
	 * @param String $templateText The text.
	 * @param Array  $placeholders Values of the placeholders, by name.
	 * @param Bool   $escapeAsHtml Whether the values are HTML-encoded.
	 * @return String The filled text.
	 */
	public static function fillNamedSlots( String $templateText, Array $placeholders, Bool $escapeAsHtml ): String {
		return strval( preg_replace_callback('/%(\w+)%/', function( Array $matchedArray ) use ( $placeholders, $escapeAsHtml ): String {
			$fillVal = $placeholders[$matchedArray[1]] ?? null;
			if( !is_scalar($fillVal) ){
				return $matchedArray[0];
			}
			return $escapeAsHtml ? htmlspecialchars(strval($fillVal), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : strval($fillVal);
		}, $templateText) );
	}

	/**
	 * Keeps the parts written between [name] and [/name] whose value is
	 * given and not empty, without their markers, and drops the others
	 * with the line break that ends them.
	 *
	 * @param String $templateText The template.
	 * @param Array  $placeholders Values of the placeholders, by name.
	 * @return String The template, its parts kept or dropped.
	 */
	protected function retainPartsTxt( String $templateText, Array $placeholders ): String {
		return strval( preg_replace_callback('#\[(\w+)\](.*?)\[/\1\]\n?#s', function( Array $matchedArray ) use ( $placeholders ): String {
			$fillVal = $placeholders[$matchedArray[1]] ?? null;
			return ( is_scalar($fillVal) && strval($fillVal)!=='' ) ? $matchedArray[2] : '';
		}, $templateText) );
	}
}
