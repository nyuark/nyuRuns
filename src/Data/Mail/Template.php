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
		$textRead = is_file($planTemplate) ? @file_get_contents($planTemplate) : false;
		$htmlRead = ($htmlFilePath===null) ? null : ( is_file($htmlFilePath) ? @file_get_contents($htmlFilePath) : false );
		if( $textRead===false || $htmlRead===false ){
			throw new \RuntimeException( 'Mail template unreadable: '.basename($textRead===false ? $planTemplate : strval($htmlFilePath)) );
		}
		$textLnes = explode( "\n", ltrim(str_replace("\r\n", "\n", $textRead)), 2 );
		$this->subjectTxt = $textLnes[0];
		$this->bodyString = ltrim( strval($textLnes[1] ?? ''), "\n" );
		$this->htmlString = ($htmlRead===null) ? null : str_replace("\r\n", "\n", $htmlRead);
	}

	/**
	 * Gives the subject, its placeholders filled, on one line.
	 *
	 * @param Array $placeholders Values of the placeholders, by name.
	 * @return String The subject.
	 */
	public function getSubjectText( Array $placeholders ): String {
		$subStrng = self::fillNamedSlots( $this->wipeEmptyParts($this->subjectTxt, $placeholders), $placeholders, false );
		return trim( str_replace(Array("\r", "\n"), ' ', $subStrng) );
	}

	/**
	 * Gives the text body, its placeholders filled.
	 *
	 * @param Array $placeholders Values of the placeholders, by name.
	 * @return String The body.
	 */
	public function getPlainEmlTxt( Array $placeholders ): String {
		return self::fillNamedSlots( $this->wipeEmptyParts($this->bodyString, $placeholders), $placeholders, false );
	}

	/**
	 * Gives the HTML body, its placeholders filled and HTML-encoded.
	 *
	 * @param Array $placeholders Values of the placeholders, by name.
	 * @return ?String The HTML, or null when the mail has none.
	 */
	public function getHtmlVersion( Array $placeholders ): ?String {
		return ($this->htmlString===null) ? null : self::fillNamedSlots( $this->wipeEmptyParts($this->htmlString, $placeholders), $placeholders, true );
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
			$fillVals = $placeholders[$matchedArray[1]] ?? null;
			if( !is_scalar($fillVals) ){
				return $matchedArray[0];
			}
			return $escapeAsHtml ? htmlspecialchars(strval($fillVals), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : strval($fillVals);
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
	protected function wipeEmptyParts( String $templateText, Array $placeholders ): String {
		return strval( preg_replace_callback('#\[(\w+)\](.*?)\[/\1\]\n?#s', function( Array $matchedArray ) use ( $placeholders ): String {
			$fillVals = $placeholders[$matchedArray[1]] ?? null;
			return ( is_scalar($fillVals) && strval($fillVals)!=='' ) ? $matchedArray[2] : '';
		}, $templateText) );
	}
}
