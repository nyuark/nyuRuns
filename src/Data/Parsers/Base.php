<?php

namespace nyu\Data\Parsers;

use nyu\Data\Fluxd;

// What the parsers of text formats share: reading a source within a size
// limit, bringing its text to UTF-8, and building the envelope.
abstract class Base implements Parser {

	// Greatest size read from a source when no 'limit' option is given: 16 MB
	const DFLTSIZE = 16777216;

	/**
	 * Parses the content of a source, read whole within the 'limit'
	 * option, in bytes.
	 *
	 * @param Fluxd $sourceFlux The source.
	 * @param Array $parseOptns Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the content is larger than the limit, or is not valid.
	 */
	public function parseFlux(Fluxd $sourceFlux, Array $parseOptns=Array()): Array {
		$maxSize = intval($parseOptns['limit'] ?? self::DFLTSIZE);
		try{
			$fullTxt = $sourceFlux->readWhole($maxSize);
		}catch( \RuntimeException $e ){
			throw new ParseError($e->getMessage(), 0, $e);
		}
		return $this->parseData($fullTxt, $parseOptns);
	}

	/**
	 * Builds the envelope a parser returns.
	 *
	 * @param String $shapeLabel Shape of the content: 'nodes', 'sheet' or 'block'.
	 * @param mixed  $itemsValue The content.
	 * @param Array  $infosArray Details about the source.
	 * @return Array The envelope.
	 */
	protected function makeShape(String $shapeLabel, Mixed $itemsValue, Array $infosArray=Array()): Array {
		return Array('shape'=>$shapeLabel, 'infos'=>$infosArray, 'items'=>$itemsValue);
	}

	/**
	 * Brings a text to UTF-8, without the marker some editors write at
	 * its start. A UTF-16 text is converted from its marker; a text that
	 * is not valid UTF-8 is read as Windows-1252, the usual encoding of
	 * the files saved by older tools.
	 *
	 * @param String $rawContent The text, as read from its source.
	 * @return String The text in UTF-8.
	 */
	public static function cleanText(String $rawContent): String {
		if( substr($rawContent, 0, 3)==="\xEF\xBB\xBF" ){
			return substr($rawContent, 3);
		}
		if( substr($rawContent, 0, 2)==="\xFF\xFE" ){
			return strval(iconv('UTF-16LE', 'UTF-8//IGNORE', substr($rawContent, 2)));
		}
		if( substr($rawContent, 0, 2)==="\xFE\xFF" ){
			return strval(iconv('UTF-16BE', 'UTF-8//IGNORE', substr($rawContent, 2)));
		}
		if( $rawContent!=='' && !preg_match('//u', $rawContent) ){
			return strval(iconv('Windows-1252', 'UTF-8//IGNORE', $rawContent));
		}
		return $rawContent;
	}

	/**
	 * Upper-cases the keys of the first level of an array.
	 *
	 * @param Array $valueArray The array.
	 * @return Array The same values, under upper-cased keys.
	 */
	protected function upperKeys(Array $valueArray): Array {
		$uprVals = Array();
		foreach($valueArray as $valKey=>$valItm){
			$uprVals[is_string($valKey) ? strtoupper($valKey) : $valKey] = $valItm;
		}
		return $uprVals;
	}

}
