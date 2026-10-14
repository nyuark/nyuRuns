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

	protected $subjText;
	protected $bodyText;
	protected $htmlText;

	/**
	 * Reads the templates of a mail.
	 *
	 * @param String  $plainTmplt The text file: the subject on its first line, then the body.
	 * @param ?String $markupPath The HTML file, if any.
	 *
	 * @throws \RuntimeException When a file cannot be read.
	 */
	function __construct( String $plainTmplt, ?String $markupPath=null ){
		$txtRead = is_file($plainTmplt) ? @file_get_contents($plainTmplt) : false;
		$htmRead = ($markupPath===null) ? null : ( is_file($markupPath) ? @file_get_contents($markupPath) : false );
		if( $txtRead===false || $htmRead===false ){
			throw new \RuntimeException( 'Mail template unreadable: '.basename($txtRead===false ? $plainTmplt : strval($markupPath)) );
		}
		$txtLins = explode( "\n", ltrim(str_replace("\r\n", "\n", $txtRead)), 2 );
		$this->subjText = $txtLins[0];
		$this->bodyText = ltrim( strval($txtLins[1] ?? ''), "\n" );
		$this->htmlText = ($htmRead===null) ? null : str_replace("\r\n", "\n", $htmRead);
	}

	/**
	 * Gives the subject, its placeholders filled, on one line.
	 *
	 * @param Array $fillValues Values of the placeholders, by name.
	 * @return String The subject.
	 */
	public function getSubjct( Array $fillValues ): String {
		$subText = self::fillPlace( $this->keepParts($this->subjText, $fillValues), $fillValues, false );
		return trim( str_replace(Array("\r", "\n"), ' ', $subText) );
	}

	/**
	 * Gives the text body, its placeholders filled.
	 *
	 * @param Array $fillValues Values of the placeholders, by name.
	 * @return String The body.
	 */
	public function plainBody( Array $fillValues ): String {
		return self::fillPlace( $this->keepParts($this->bodyText, $fillValues), $fillValues, false );
	}

	/**
	 * Gives the HTML body, its placeholders filled and HTML-encoded.
	 *
	 * @param Array $fillValues Values of the placeholders, by name.
	 * @return ?String The HTML, or null when the mail has none.
	 */
	public function markupTxt( Array $fillValues ): ?String {
		return ($this->htmlText===null) ? null : self::fillPlace( $this->keepParts($this->htmlText, $fillValues), $fillValues, true );
	}

	/**
	 * Fills the %name% placeholders of a text. A placeholder without a
	 * value, or whose value is not a text or a number, is left as written.
	 *
	 * @param String $textString The text.
	 * @param Array  $fillValues Values of the placeholders, by name.
	 * @param Bool   $encodeHtml Whether the values are HTML-encoded.
	 * @return String The filled text.
	 */
	public static function fillPlace( String $textString, Array $fillValues, Bool $encodeHtml ): String {
		return strval( preg_replace_callback('/%(\w+)%/', function( Array $matchedArr ) use ( $fillValues, $encodeHtml ): String {
			$fillVal = $fillValues[$matchedArr[1]] ?? null;
			if( !is_scalar($fillVal) ){
				return $matchedArr[0];
			}
			return $encodeHtml ? htmlspecialchars(strval($fillVal), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : strval($fillVal);
		}, $textString) );
	}

	/**
	 * Keeps the parts written between [name] and [/name] whose value is
	 * given and not empty, without their markers, and drops the others
	 * with the line break that ends them.
	 *
	 * @param String $textString The template.
	 * @param Array  $fillValues Values of the placeholders, by name.
	 * @return String The template, its parts kept or dropped.
	 */
	protected function keepParts( String $textString, Array $fillValues ): String {
		return strval( preg_replace_callback('#\[(\w+)\](.*?)\[/\1\]\n?#s', function( Array $matchedArr ) use ( $fillValues ): String {
			$fillVal = $fillValues[$matchedArr[1]] ?? null;
			return ( is_scalar($fillVal) && strval($fillVal)!=='' ) ? $matchedArr[2] : '';
		}, $textString) );
	}
}
