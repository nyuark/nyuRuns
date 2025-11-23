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
	const SEPRLIST = Array('tabul'=>"\t", 'semic'=>';', 'comma'=>',', 'pipes'=>'|');

	/**
	 * Parses a table. An empty content gives no sheet. Empty lines are
	 * left out, and the rows keep the number of cells they were written
	 * with.
	 *
	 * Options:
	 *   'separ' => the separator: 'comma', 'semic', 'tabul' or 'pipes';
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
	 * @param String $rawContent The table.
	 * @param Array  $parseOptns Options: 'separ', 'heads', 'types'.
	 * @return Array The envelope, of shape 'sheet', holding one sheet; 'infos' gives the separator under 'separ', the names of the columns under 'heads', the number of columns under 'colms' and of rows under 'lines', and whether the table started with the marker some tools write there under 'bomrk'.
	 *
	 * @throws ParseError When the separator asked for is not a known one, or a quote is left open.
	 */
	public function parseData(String $rawContent, Array $parseOptns=Array()): Array {
		$tblText = self::cleanText($rawContent);
		$seprKey = strtolower(strval($parseOptns['separ'] ?? ''));
		if( $seprKey!=='' && !isset(self::SEPRLIST[$seprKey]) ){
			throw new ParseError('Invalid table option: unknown separator '.$seprKey);
		}
		if( $seprKey==='' ){
			$seprKey = $this->findSepar($tblText);
		}
		// The marker some tools write at the start of a file is taken off the text; whether it was there is told
		$infoArr = Array('separ'=>$seprKey, 'heads'=>Array(), 'colms'=>0, 'lines'=>0, 'bomrk'=>in_array(substr($rawContent, 0, 2), Array("\xFF\xFE", "\xFE\xFF"), true) || substr($rawContent, 0, 3)==="\xEF\xBB\xBF");
		if( trim($tblText)==='' ){
			return $this->makeShape('sheet', Array(), $infoArr);
		}
		$rowsLst = $this->readCells($tblText, self::SEPRLIST[$seprKey], !empty($parseOptns['types']));
		if( !empty($parseOptns['heads']) && !empty($rowsLst) ){
			$headLst = Array();
			$rowsLst = $this->keyedRows($rowsLst, $headLst);
			$infoArr['heads'] = $headLst;
		}
		$infoArr['colms'] = empty($rowsLst) ? count($infoArr['heads']) : max(count($infoArr['heads']), max(array_map('count', $rowsLst)));
		$infoArr['lines'] = count($rowsLst);
		$shetArr = Shape::makeSheet('', Array());
		$shetArr['cells'] = $rowsLst;
		return $this->makeShape('sheet', Array($shetArr), $infoArr);
	}

	/**
	 * Tells the separator of a table from its first lines: the one that
	 * cuts every line into the same number of cells, or else the one met
	 * the most.
	 *
	 * @param String $rawContent The table, in UTF-8.
	 * @return String The code of the separator; 'comma' when none is met.
	 */
	protected function findSepar(String $rawContent): String {
		$lineLst = array_slice(array_values(array_filter(preg_split('/\r\n|\r|\n/', substr($rawContent, 0, 65536)), function($lineString){ return trim($lineString)!==''; })), 0, 10);
		$bestKey = 'comma';
		$bestScr = 0;
		foreach(self::SEPRLIST as $sprKey=>$sprChr){
			$cntLst = Array();
			foreach($lineLst as $lneTxt){
				// What stands between quotes is not counted
				$cntLst[] = substr_count(preg_replace('/"[^"]*"/', '', $lneTxt), $sprChr);
			}
			$sprScor = empty($cntLst) ? 0 : ((min($cntLst)>0 && min($cntLst)==max($cntLst)) ? 1000000 + min($cntLst) : array_sum($cntLst));
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
	 * @param String $rawContent The table, in UTF-8.
	 * @param String $separValue The separator.
	 * @param Bool   $typesAsked Whether numbers, booleans and empty cells are given as such.
	 * @return Array The rows, each a list of cells.
	 *
	 * @throws ParseError When a quote is left open.
	 */
	protected function readCells(String $rawContent, String $separValue, Bool $typesAsked): Array {
		$rowsLst = Array();
		$cellLst = Array();
		$blankRw = true;
		$textLen = strlen($rawContent);
		$curPosn = 0;
		$lineNbr = 1;
		while( $curPosn<$textLen ){
			$cellTxt = '';
			$isQuotd = false;
			if( $rawContent[$curPosn]=='"' ){
				$isQuotd = true;
				$curPosn++;
				while( true ){
					$endPosn = strpos($rawContent, '"', $curPosn);
					if( $endPosn===false ){
						throw new ParseError('Invalid table content: quote left open on line '.$lineNbr);
					}
					$cellTxt .= substr($rawContent, $curPosn, $endPosn-$curPosn);
					$curPosn = $endPosn+1;
					// A quote written twice is a quote of the text
					if( $curPosn<$textLen && $rawContent[$curPosn]=='"' ){
						$cellTxt .= '"';
						$curPosn++;
						continue;
					}
					break;
				}
				$cellTxt = str_replace(Array("\r\n", "\r"), "\n", $cellTxt);
				$lineNbr += substr_count($cellTxt, "\n");
			}
			$restLen = strcspn($rawContent, $separValue."\r\n", $curPosn);
			$cellTxt .= substr($rawContent, $curPosn, $restLen);
			$curPosn += $restLen;
			$blankRw = ($blankRw && !$isQuotd && $cellTxt==='');
			$cellLst[] = ($typesAsked && !$isQuotd) ? $this->typedCell($cellTxt) : $cellTxt;
			if( $curPosn<$textLen && $rawContent[$curPosn]===$separValue ){
				$curPosn++;
				$blankRw = false;
				if( $curPosn<$textLen ){
					continue;
				}
				// A separator ending the content is followed by an empty cell
				$cellLst[] = $typesAsked ? null : '';
			}
			// The row ends here: on a line end, or with the content
			$curPosn += (substr($rawContent, $curPosn, 2)==="\r\n") ? 2 : 1;
			$lineNbr++;
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
	 * @param String $cellString The text of the cell.
	 * @return String|Int|Float|Bool|Null The value.
	 */
	protected function typedCell(String $cellString): String|Int|Float|Bool|Null {
		$trimTxt = trim($cellString);
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
		return $cellString;
	}

}
