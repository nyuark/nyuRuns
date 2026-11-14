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
		$tblText = self::recodeTextUtf8($nakedContent);
		$divider = strval($parseOptions['separ'] ?? '');
		// The separator whatever its case, as SEPR_LIST writes it
		$divider = strval( array_values(preg_grep('/^'.preg_quote($divider, '/').'$/iD', array_keys(self::SEPR_LIST)))[0] ?? $divider );
		if( $divider!=='' && !isset(self::SEPR_LIST[$divider]) ){
			throw new ParseError('Invalid table option: unknown separator '.$divider);
		}
		if( $divider==='' ){
			$divider = $this->guessSeparator($tblText);
		}
		// The marker some tools write at the start of a file is taken off the text; whether it was there is told
		$infoArr = Array('separ'=>$divider, 'heads'=>Array(), 'colms'=>0, 'lines'=>0, 'bomrk'=>in_array(substr($nakedContent, 0, 2), Array("\xFF\xFE", "\xFE\xFF"), true) || substr($nakedContent, 0, 3)==="\xEF\xBB\xBF");
		if( trim($tblText)==='' ){
			return $this->makeShapeArray('sheet', Array(), $infoArr);
		}
		$rowsLst = $this->readCellsArray($tblText, self::SEPR_LIST[$divider], !empty($parseOptions['types']));
		if( !empty($parseOptions['heads']) && !empty($rowsLst) ){
			$headLst = Array();
			$rowsLst = $this->keyedRowsArray($rowsLst, $headLst);
			$infoArr['heads'] = $headLst;
		}
		$infoArr['colms'] = empty($rowsLst) ? count($infoArr['heads']) : max(count($infoArr['heads']), max(array_map('count', $rowsLst)));
		$infoArr['lines'] = count($rowsLst);
		$shetArr = Shape::makeSheetArray('', Array());
		$shetArr['cells'] = $rowsLst;
		return $this->makeShapeArray('sheet', Array($shetArr), $infoArr);
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
		$lineLst = array_slice(array_values(array_filter(preg_split('/\r\n|\r|\n/', substr($nakedContent, 0, 65536)), function($markdownLine){ return trim($markdownLine)!==''; })), 0, 10);
		$bestKey = 'commaSep';
		$bestScr = 0;
		foreach(self::SEPR_LIST as $sprKey=>$sprChr){
			$cntList = Array();
			foreach($lineLst as $linTxt){
				// What stands between quotes is not counted
				$cntList[] = substr_count(preg_replace('/"[^"]*"/', '', $linTxt), $sprChr);
			}
			$sprScor = empty($cntList) ? 0 : ((min($cntList)>0 && min($cntList)==max($cntList)) ? 1000000 + min($cntList) : array_sum($cntList));
			if( $sprScor>$bestScr ){
				$bestKey = $sprKey;
				$bestScr = $sprScor;
			}
		}
		return $bestKey;
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
		$rowsLst = Array();
		$cellLst = Array();
		$blankRw = true;
		$txtSize = strlen($nakedContent);
		$readPos = 0;
		$lineNum = 1;
		while( $readPos<$txtSize ){
			$cellTxt = '';
			$inQuote = false;
			if( $nakedContent[$readPos]=='"' ){
				$inQuote = true;
				$readPos++;
				while( true ){
					$endPstn = strpos($nakedContent, '"', $readPos);
					if( $endPstn===false ){
						throw new ParseError('Invalid table content: quote left open on line '.$lineNum);
					}
					$cellTxt .= substr($nakedContent, $readPos, $endPstn-$readPos);
					$readPos = $endPstn+1;
					// A quote written twice is a quote of the text
					if( $readPos<$txtSize && $nakedContent[$readPos]=='"' ){
						$cellTxt .= '"';
						$readPos++;
						continue;
					}
					break;
				}
				$cellTxt = str_replace(Array("\r\n", "\r"), "\n", $cellTxt);
				$lineNum += substr_count($cellTxt, "\n");
			}
			$restLen = strcspn($nakedContent, $separatorVal."\r\n", $readPos);
			$cellTxt .= substr($nakedContent, $readPos, $restLen);
			$readPos += $restLen;
			$blankRw = ($blankRw && !$inQuote && $cellTxt==='');
			$cellLst[] = ($typedResults && !$inQuote) ? $this->castCellToType($cellTxt) : $cellTxt;
			if( $readPos<$txtSize && $nakedContent[$readPos]===$separatorVal ){
				$readPos++;
				$blankRw = false;
				if( $readPos<$txtSize ){
					continue;
				}
				// A separator ending the content is followed by an empty cell
				$cellLst[] = $typedResults ? null : '';
			}
			// The row ends here: on a line end, or with the content
			$readPos += (substr($nakedContent, $readPos, 2)==="\r\n") ? 2 : 1;
			$lineNum++;
			if( !$blankRw ){
				$rowsLst[] = $cellLst;
			}
			$cellLst = Array();
			$blankRw = true;
		}
		return $rowsLst;
	}

	/**
	 * Gives a cell as the value it writes: a number, a boolean, nothing,
	 * or its text.
	 *
	 * @param String $cellRawValue The text of the cell.
	 * @return String|Int|Float|Bool|Null The value.
	 */
	protected function castCellToType(String $cellRawValue): String|Int|Float|Bool|Null {
		$trimTxt = trim($cellRawValue);
		if( $trimTxt==='' ){
			return null;
		}
		if( preg_match('/^-?(0|[1-9]\d{0,17})$/D', $trimTxt) ){
			return intval($trimTxt);
		}
		if( preg_match('/^-?(0|[1-9]\d*)(\.\d+)?([eE][+\-]?\d+)?$/D', $trimTxt) && !preg_match('/^-?\d+$/D', $trimTxt) ){
			return floatval($trimTxt);
		}
		if( in_array(strtolower($trimTxt), Array('true', 'false')) ){
			return (strtolower($trimTxt)=='true');
		}
		return $cellRawValue;
	}

}
