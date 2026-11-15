<?php

namespace nyu\Data\Writers;

use nyu\Data\Fluxd;

/**
 * What the writers of spreadsheets share: the rows of a sheet laid out
 * as a grid, with the names of its columns; the names a sheet may bear;
 * what a cell holds, told apart; and the text written into their parts.
 */
abstract class Grid extends Base {

	// The limits of a sheet, in every spreadsheet
	const ROWS_MAXI = 1048576;
	const COLS_MAXI = 16384;

	/**
	 * Gives a sheet a name every spreadsheet accepts: 31 characters at
	 * most, none of those they keep for themselves, and no name twice.
	 *
	 * @param String $sheetHeading The name asked for.
	 * @param Int    $sheetPlcNumb The place of the sheet, starting from 1, for a sheet without a name.
	 * @param Array  $usedNameList The names already given.
	 * @return String The name.
	 */
	protected function wipeSheetTitle(String $sheetHeading, Int $sheetPlcNumb, Array $usedNameList): String {
		$cutsText = function(String $textToShrink, Int $keptCharsCnt){ return function_exists('mb_substr') ? mb_substr($textToShrink, 0, $keptCharsCnt, 'UTF-8') : substr($textToShrink, 0, $keptCharsCnt); };
		$nameText = trim(strval(preg_replace('/[\[\]:*?\/\\\\\x00-\x1F]+/', ' ', preg_match('//u', $sheetHeading) ? $sheetHeading : '')), " '");
		$nameText = rtrim($cutsText(($nameText==='') ? 'Sheet'.$sheetPlcNumb : $nameText, 31));
		$lowerLst = array_map('strtolower', $usedNameList);
		$baseText = $nameText;
		for($nthNum=2; in_array(strtolower($nameText), $lowerLst, true); $nthNum++){
			$nameText = $cutsText($baseText, 31-strlen(' ('.$nthNum.')')).' ('.$nthNum.')';
		}
		return $nameText;
	}

	/**
	 * Tells which sheets are shown. A spreadsheet shows one sheet at
	 * least: when all are hidden, the first is shown.
	 *
	 * @param Array $sheetEntries The sheets.
	 * @return Array True or false for each sheet, in their order.
	 */
	protected function listShownSheet(Array $sheetEntries): Array {
		$shownLst = Array();
		foreach($sheetEntries as $oneTbl){
			$shownLst[] = !isset($oneTbl['attrs']['shown']) || !empty($oneTbl['attrs']['shown']);
		}
		if( !empty($shownLst) && !in_array(true, $shownLst, true) ){
			$shownLst[0] = true;
		}
		return $shownLst;
	}

	/**
	 * Lays the rows of a sheet out as a grid. Rows keyed by the names of
	 * their columns are put in the order the names are met; the names
	 * make a row of their own when asked.
	 *
	 * @param Array $sheetDetails The sheet: 'title', 'attrs', 'cells'.
	 * @param Bool  $writeHeaders Whether the names of the columns are written as the first row.
	 * @return Array The names written as the first row under 'heads', none when no such row is written; the rows under 'cells', each a list of values, without that row.
	 *
	 * @throws WriteError When the sheet is larger than a sheet can be.
	 */
	protected function castSheetToGrd(Array $sheetDetails, Bool $writeHeaders): Array {
		$rowsList = array_values((array)($sheetDetails['cells'] ?? Array()));
		// Every name met, in the order they are met, makes a column
		$headList = Array();
		$isByKeys = false;
		foreach($rowsList as $oneRow){
			$isByKeys = ($isByKeys || !$this->isSequenceList((array)$oneRow));
		}
		if( $isByKeys ){
			foreach($rowsList as $oneRow){
				foreach(array_keys((array)$oneRow) as $oneCol){
					if( !in_array(strval($oneCol), $headList, true) ){
						$headList[] = strval($oneCol);
					}
				}
			}
		}
		$gridList = Array();
		foreach($rowsList as $oneRow){
			$cellList = Array();
			if( $isByKeys ){
				foreach($headList as $oneCol){
					$cellList[] = ((array)$oneRow)[$oneCol] ?? null;
				}
			}else{
				$cellList = array_values((array)$oneRow);
			}
			$gridList[] = $cellList;
			if( count($gridList)+(($isByKeys && $writeHeaders) ? 1 : 0)>self::ROWS_MAXI || count($cellList)>self::COLS_MAXI ){
				throw new WriteError('Sheet larger than a sheet can be: '.self::ROWS_MAXI.' rows of '.self::COLS_MAXI.' cells');
			}
		}
		return Array('heads'=>($isByKeys && $writeHeaders) ? $headList : Array(), 'cells'=>$gridList);
	}

	/**
	 * Gives what a cell holds as what a spreadsheet can hold: a number,
	 * true or false, a text, or nothing.
	 *
	 * @param mixed $valueToWrite What the cell was given.
	 * @return String|Int|Float|Bool|Null The value: null for nothing, a binary value or what is no number; a list as its JSON text.
	 */
	protected function castCellScalar(Mixed $valueToWrite): String|Int|Float|Bool|Null {
		if( $valueToWrite===null || $valueToWrite instanceof Fluxd || (is_float($valueToWrite) && !is_finite($valueToWrite)) ){
			return null;
		}
		if( is_array($valueToWrite) || is_object($valueToWrite) ){
			return strval(json_encode(Fluxd::dropAllSources(is_array($valueToWrite) ? $valueToWrite : (array)$valueToWrite), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
		}
		return (is_bool($valueToWrite) || is_int($valueToWrite) || is_float($valueToWrite)) ? $valueToWrite : strval($valueToWrite);
	}

	/**
	 * Tells whether a text is a date: '2026-10-11', or with its time
	 * '2026-10-11 14:30:00', the seconds being left out at will.
	 *
	 * @param String $sourceString The text.
	 * @return ?Array The year, the month and the day, then the hours, the minutes and the seconds when it tells a time; null when it is not a date that exists.
	 */
	protected function parseDateParts(String $sourceString): ?Array {
		$dateList = Array();
		if( !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/D', $sourceString, $dateList) ){
			return null;
		}
		$partList = array_map('intval', array_slice($dateList, 1));
		if( !checkdate($partList[1], $partList[2], $partList[0]) || ($partList[3] ?? 0)>23 || ($partList[4] ?? 0)>59 || ($partList[5] ?? 0)>59 ){
			return null;
		}
		return isset($partList[3]) ? Array($partList[0], $partList[1], $partList[2], $partList[3], $partList[4], $partList[5] ?? 0) : $partList;
	}

}
