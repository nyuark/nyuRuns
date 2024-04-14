<?php

namespace nyu\Data\Parsers;

use nyu\Data\Fluxd;
use nyu\Data\Shape;

// What the parsers of text formats share: reading a source within a size
// limit, bringing its text to UTF-8, and building the envelope.
abstract class Base implements Parser {

	// Greatest size read from a source when no 'limit' option is given: 16 MB
	const DFLTSIZE = 16777216;

	/**
	 * Tells the kinds of content the parser reads, as Bina::signature()
	 * names them: a file whose first bytes show another kind is not what
	 * its name announces.
	 *
	 * @return Array The kinds; those that are text unless the parser says otherwise.
	 */
	public function signsRead(): Array {
		return Bina::TEXTLIST;
	}
	
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
	 * Builds a block that holds others: when the first of them is a
	 * paragraph, it is the text of the block itself, and what is particular
	 * to it (its style, its alignment, its id) is particular to the block.
	 *
	 * @param String $blockLabel Kind of the block: entry or quote.
	 * @param Array  $blocksList The blocks read from what it holds.
	 * @param Array  $attrsArray What is particular to it.
	 * @return Array The block.
	 */
	protected function holdBlock(String $blockLabel, Array $blocksList, Array $attrsArray=Array()): Array {
		if( !empty($blocksList) && $blocksList[0]['block']=='parag' ){
			return Shape::makeBlock($blockLabel, $blocksList[0]['spans'], array_slice($blocksList, 1), $attrsArray + $blocksList[0]['attrs']);
		}
		return Shape::makeBlock($blockLabel, Array(), $blocksList, $attrsArray);
	}

	/**
	 * Takes the first row of a table as the names of its columns, and
	 * keys every other row by them. A column without name is keyed by
	 * its position, a name met twice gets its position after it, a cell
	 * a row does not have is null, and the cells a row has beyond the
	 * named columns keep their position.
	 *
	 * @param Array $rowsOfGrid The rows, each a list of cells, the first one holding the names.
	 * @param Array $headsFound Receives the names of the columns.
	 * @return Array The other rows, keyed by those names.
	 */
	protected function keyedRows(Array $rowsOfGrid, Array &$headsFound): Array {
		$headsFound = Array();
		$rowsOfGrid = array_values($rowsOfGrid);
		if( empty($rowsOfGrid) ){
			return Array();
		}
		foreach(array_values((array)array_shift($rowsOfGrid)) as $colIdx=>$colNme){
			$nameTxt = is_scalar($colNme) ? trim(strval($colNme)) : '';
			// A column must have a key of its own, whatever its name
			if( $nameTxt==='' ){
				$nameTxt = strval($colIdx+1);
			}
			$headsFound[] = in_array($nameTxt, $headsFound, true) ? $nameTxt.'_'.($colIdx+1) : $nameTxt;
		}
		foreach($rowsOfGrid as $rowIdx=>$oneRow){
			$oneRow = array_values((array)$oneRow);
			$keyedRw = Array();
			foreach($headsFound as $colIdx=>$colNme){
				$keyedRw[$colNme] = $oneRow[$colIdx] ?? null;
			}
			for($colIdx=count($headsFound); $colIdx<count($oneRow); $colIdx++){
				$keyedRw[strval($colIdx+1)] = $oneRow[$colIdx];
			}
			$rowsOfGrid[$rowIdx] = $keyedRw;
		}
		return $rowsOfGrid;
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
