<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

/**
 * Tables written as text: CSV, TSV and their kin. One row per line, the
 * cells separated by one character; a cell between double quotes may hold
 * the separator, line ends, and a quote written twice.
 */
class Tabl extends Base {

	// Separators, by the code that asks for them. When two of them fit a table
	// as well, the first of this list is the one: a tabulation is rarely text
	const SEPR_LIST = Array('tabulSep'=>"\t", 'semicSep'=>';', 'commaSep'=>',', 'pipesSep'=>'|');

	/**
	 * Parses a table. An empty content gives no sheet. Empty lines are
	 * left out, and the rows keep the number of cells they were written
	 * with.
	 *
	 * Options:
	 *   'separ' => the separator: 'commaSep', 'semicSep', 'tabulSep' or 'pipesSep';
	 *              told from the first lines when left out;
	 *   'heads' => takes the first row as the names of the columns: every
	 *              other row is then keyed by them. A column without name
	 *              is keyed by its position, a name met twice gets its
	 *              position after it, and a cell a row does not have is
	 *              null;
	 *   'types' => gives numbers as Int or Float, true and false as Bool
	 *              and an empty cell as null, instead of text. A cell
	 *              written between quotes stays text, and so does a
	 *              number starting with a zero.
	 *
	 * @param String $nakedContent The table.
	 * @param Array  $parseOptions Options: 'separ', 'heads', 'types'.
	 * @return Array The envelope, of shape 'sheet', holding one sheet; 'infos' gives the separator under 'separ', the names of the columns under 'heads', the number of columns under 'colms' and of rows under 'lines', and whether the table started with the marker some tools write there under 'bomrk'.
	 *
	 * @throws ParseError When the separator asked for is not a known one, or a quote is left open.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		$tableTxt = self::recodeTextUtf8($nakedContent);
		$dvdrText = strval($parseOptions['separ'] ?? '');
		// The separator whatever its case, as SEPR_LIST writes it
		$dvdrText = strval( array_values(preg_grep('/^'.preg_quote($dvdrText, '/').'$/iD', array_keys(self::SEPR_LIST)))[0] ?? $dvdrText );
		if( $dvdrText!=='' && !isset(self::SEPR_LIST[$dvdrText]) ){
			throw new ParseError('Invalid table option: unknown separator '.$dvdrText);
		}
		if( $dvdrText==='' ){
			$dvdrText = $this->guessSeparator($tableTxt);
		}
		// The marker some tools write at the start of a file is taken off the text; whether it was there is told
		$infoList = Array('separ'=>$dvdrText, 'heads'=>Array(), 'colms'=>0, 'lines'=>0, 'bomrk'=>in_array(substr($nakedContent, 0, 2), Array("\xFF\xFE", "\xFE\xFF"), true) || substr($nakedContent, 0, 3)==="\xEF\xBB\xBF");
		if( trim($tableTxt)==='' ){
			return $this->makeShapeArray('sheet', Array(), $infoList);
		}
		$rowsList = $this->readCellsArray($tableTxt, self::SEPR_LIST[$dvdrText], !empty($parseOptions['types']));
		if( !empty($parseOptions['heads']) && !empty($rowsList) ){
			$headList = Array();
			$rowsList = $this->keyedRowsArray($rowsList, $headList);
			$infoList['heads'] = $headList;
		}
		$infoList['colms'] = empty($rowsList) ? count($infoList['heads']) : max(count($infoList['heads']), max(array_map('count', $rowsList)));
		$infoList['lines'] = count($rowsList);
		$sheetLst = Shape::makeSheetArray('', Array());
		$sheetLst['cells'] = $rowsList;
		return $this->makeShapeArray('sheet', Array($sheetLst), $infoList);
	}

	/**
	 * Tells the separator of a table from its first lines: the one that
	 * cuts every line into the same number of cells, or else the one met
	 * the most.
	 *
	 * @param String $nakedContent The table, in UTF-8.
	 * @return String The code of the separator; 'commaSep' when none is met.
	 */
	protected function guessSeparator(String $nakedContent): String {
		$lineList = array_slice(array_values(array_filter(preg_split('/\r\n|\r|\n/', substr($nakedContent, 0, 65536)), function($markdownLine){ return trim($markdownLine)!==''; })), 0, 10);
		$bestClef = 'commaSep';
		$bestScor = 0;
		foreach(self::SEPR_LIST as $sprKey=>$sprChr){
			$countLst = Array();
			foreach($lineList as $linTxt){
				// What stands between quotes is not counted
				$countLst[] = substr_count(preg_replace('/"[^"]*"/', '', $linTxt), $sprChr);
			}
			$seprScor = empty($countLst) ? 0 : ((min($countLst)>0 && min($countLst)==max($countLst)) ? 1000000 + min($countLst) : array_sum($countLst));
			if( $seprScor>$bestScor ){
				$bestClef = $sprKey;
				$bestScor = $seprScor;
			}
		}
		return $bestClef;
	}

	/**
	 * Reads the rows of a table.
	 *
	 * @param String $nakedContent The table, in UTF-8.
	 * @param String $separatorVal The separator.
	 * @param Bool   $typedResults Whether numbers, booleans and empty cells are given as such.
	 * @return Array The rows, each a list of cells.
	 *
	 * @throws ParseError When a quote is left open.
	 */
	protected function readCellsArray(String $nakedContent, String $separatorVal, Bool $typedResults): Array {
		$rowsList = Array();
		$cellList = Array();
		$blankRow = true;
		$textSize = strlen($nakedContent);
		$readIndx = 0;
		$lineNumb = 1;
		while( $readIndx<$textSize ){
			$cellText = '';
			$isQuoted = false;
			if( $nakedContent[$readIndx]=='"' ){
				$isQuoted = true;
				$readIndx++;
				while( true ){
					$quotePos = strpos($nakedContent, '"', $readIndx);
					if( $quotePos===false ){
						throw new ParseError('Invalid table content: quote left open on line '.$lineNumb);
					}
					$cellText .= substr($nakedContent, $readIndx, $quotePos-$readIndx);
					$readIndx = $quotePos+1;
					// A quote written twice is a quote of the text
					if( $readIndx<$textSize && $nakedContent[$readIndx]=='"' ){
						$cellText .= '"';
						$readIndx++;
						continue;
					}
					break;
				}
				$cellText = str_replace(Array("\r\n", "\r"), "\n", $cellText);
				$lineNumb += substr_count($cellText, "\n");
			}
			$restSize = strcspn($nakedContent, $separatorVal."\r\n", $readIndx);
			$cellText .= substr($nakedContent, $readIndx, $restSize);
			$readIndx += $restSize;
			$blankRow = ($blankRow && !$isQuoted && $cellText==='');
			$cellList[] = ($typedResults && !$isQuoted) ? $this->castCellToType($cellText) : $cellText;
			if( $readIndx<$textSize && $nakedContent[$readIndx]===$separatorVal ){
				$readIndx++;
				$blankRow = false;
				if( $readIndx<$textSize ){
					continue;
				}
				// A separator ending the content is followed by an empty cell
				$cellList[] = $typedResults ? null : '';
			}
			// The row ends here: on a line end, or with the content
			$readIndx += (substr($nakedContent, $readIndx, 2)==="\r\n") ? 2 : 1;
			$lineNumb++;
			if( !$blankRow ){
				$rowsList[] = $cellList;
			}
			$cellList = Array();
			$blankRow = true;
		}
		return $rowsList;
	}

	/**
	 * Gives a cell as the value it writes: a number, a boolean, nothing,
	 * or its text.
	 *
	 * @param String $cellRawValue The text of the cell.
	 * @return String|Int|Float|Bool|Null The value.
	 */
	protected function castCellToType(String $cellRawValue): String|Int|Float|Bool|Null {
		$trimText = trim($cellRawValue);
		if( $trimText==='' ){
			return null;
		}
		if( preg_match('/^-?(0|[1-9]\d{0,17})$/D', $trimText) ){
			return intval($trimText);
		}
		if( preg_match('/^-?(0|[1-9]\d*)(\.\d+)?([eE][+\-]?\d+)?$/D', $trimText) && !preg_match('/^-?\d+$/D', $trimText) ){
			return floatval($trimText);
		}
		if( in_array(strtolower($trimText), Array('true', 'false')) ){
			return (strtolower($trimText)=='true');
		}
		return $cellRawValue;
	}

}
