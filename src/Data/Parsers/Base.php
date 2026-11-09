<?php

namespace nyu\Data\Parsers;

use nyu\Data\Fluxd;
use nyu\Data\Shape;

/**
 * What the parsers of text formats share: reading a source within a size
 * limit, bringing its text to UTF-8, and building the envelope.
 */
abstract class Base implements Parser {

	// Greatest size read from a source when no 'limit' option is given: 16 MB
	const DFLT_SIZE = 16777216;

	/**
	 * Tells the kinds of content the parser reads, as Bina::detectFileKind()
	 * names them: a file whose first bytes show another kind is not what
	 * its name announces.
	 *
	 * @return Array The kinds; those that are text unless the parser says otherwise.
	 */
	public function signsReadArray(): Array {
		return Bina::TEXT_LIST;
	}
	
	/**
	 * Parses the content of a source, read whole within the 'limit'
	 * option, in bytes.
	 *
	 * @param Fluxd $sourceStream The source.
	 * @param Array $parseOptions Options of the parser.
	 * @return Array The envelope: 'shape', 'infos' and 'items'.
	 *
	 * @throws ParseError When the content is larger than the limit, or is not valid.
	 */
	public function parseFluxArray(Fluxd $sourceStream, Array $parseOptions=Array()): Array {
		$maxSize = intval($parseOptions['limit'] ?? self::DFLT_SIZE);
		try{
			$fullTxt = $sourceStream->getWholeString($maxSize);
		}catch( \RuntimeException $e ){
			throw new ParseError($e->getMessage(), 0, $e);
		}
		return $this->parseDataArray($fullTxt, $parseOptions);
	}

	/**
	 * Builds the envelope a parser returns.
	 *
	 * @param String $contentShape Shape of the content: 'nodes', 'sheet' or 'block'.
	 * @param mixed  $contentValue The content.
	 * @param Array  $mediaDetails Details about the source.
	 * @return Array The envelope.
	 */
	protected function makeShapeArray(String $contentShape, Mixed $contentValue, Array $mediaDetails=Array()): Array {
		return Array('shape'=>$contentShape, 'infos'=>$mediaDetails, 'items'=>$contentValue);
	}

	/**
	 * Builds a block that holds others: when the first of them is a
	 * paragraph, it is the text of the block itself, and what is particular
	 * to it (its style, its alignment, its id) is particular to the block.
	 *
	 * @param String $blckLabelTxt Kind of the block: entry or quote.
	 * @param Array  $bookBlockSet The blocks read from what it holds.
	 * @param Array  $attributeSet What is particular to it.
	 * @return Array The block.
	 */
	protected function holdBlockArray(String $blckLabelTxt, Array $bookBlockSet, Array $attributeSet=Array()): Array {
		if( !empty($bookBlockSet) && $bookBlockSet[0]['block']=='paragrph' ){
			return Shape::makeBlockArray($blckLabelTxt, $bookBlockSet[0]['spans'], array_slice($bookBlockSet, 1), $attributeSet + $bookBlockSet[0]['attrs']);
		}
		return Shape::makeBlockArray($blckLabelTxt, Array(), $bookBlockSet, $attributeSet);
	}

	/**
	 * Takes the first row of a table as the names of its columns, and
	 * keys every other row by them. A column without name is keyed by
	 * its position, a name met twice gets its position after it, a cell
	 * a row does not have is null, and the cells a row has beyond the
	 * named columns keep their position.
	 *
	 * @param Array $cellRowsList The rows, each a list of cells, the first one holding the names.
	 * @param Array $columnTitles Receives the names of the columns.
	 * @return Array The other rows, keyed by those names.
	 */
	protected function keyedRowsArray(Array $cellRowsList, Array &$columnTitles): Array {
		$columnTitles = Array();
		$cellRowsList = array_values($cellRowsList);
		if( empty($cellRowsList) ){
			return Array();
		}
		foreach(array_values((array)array_shift($cellRowsList)) as $colInc=>$header){
			$nameTxt = is_scalar($header) ? trim(strval($header)) : '';
			// A column must have a key of its own, whatever its name
			if( $nameTxt==='' ){
				$nameTxt = strval($colInc+1);
			}
			$columnTitles[] = in_array($nameTxt, $columnTitles, true) ? $nameTxt.'_'.($colInc+1) : $nameTxt;
		}
		foreach($cellRowsList as $rowIdx=>$oneRow){
			$oneRow = array_values((array)$oneRow);
			$keyedRw = Array();
			foreach($columnTitles as $colInc=>$header){
				$keyedRw[$header] = $oneRow[$colInc] ?? null;
			}
			for($colInc=count($columnTitles); $colInc<count($oneRow); $colInc++){
				$keyedRw[strval($colInc+1)] = $oneRow[$colInc];
			}
			$cellRowsList[$rowIdx] = $keyedRw;
		}
		return $cellRowsList;
	}

	/**
	 * Brings a text to UTF-8, without the marker some editors write at
	 * its start. A UTF-16 text is converted from its marker; a text that
	 * is not valid UTF-8 is read as Windows-1252, the usual encoding of
	 * the files saved by older tools.
	 *
	 * @param String $nakedContent The text, as read from its source.
	 * @return String The text in UTF-8.
	 */
	public static function recodeTextUtf8(String $nakedContent): String {
		if( substr($nakedContent, 0, 3)==="\xEF\xBB\xBF" ){
			return substr($nakedContent, 3);
		}
		if( substr($nakedContent, 0, 2)==="\xFF\xFE" ){
			return strval(iconv('UTF-16LE', 'UTF-8//IGNORE', substr($nakedContent, 2)));
		}
		if( substr($nakedContent, 0, 2)==="\xFE\xFF" ){
			return strval(iconv('UTF-16BE', 'UTF-8//IGNORE', substr($nakedContent, 2)));
		}
		if( $nakedContent!=='' && !preg_match('//u', $nakedContent) ){
			return strval(iconv('Windows-1252', 'UTF-8//IGNORE', $nakedContent));
		}
		return $nakedContent;
	}

	/**
	 * Upper-cases the keys of the first level of an array.
	 *
	 * @param Array $fieldValList The array.
	 * @return Array The same values, under upper-cased keys.
	 */
	protected function upperKeysArray(Array $fieldValList): Array {
		$upprVls = Array();
		foreach($fieldValList as $valKey=>$valItm){
			$upprVls[is_string($valKey) ? strtoupper($valKey) : $valKey] = $valItm;
		}
		return $upprVls;
	}

}
