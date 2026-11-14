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
	 * @param Int    $sheetPlaceNb The place of the sheet, starting from 1, for a sheet without a name.
	 * @param Array  $usedNameList The names already given.
	 * @return String The name.
	 */
	protected function cleanSheetName(String $sheetHeading, Int $sheetPlaceNb, Array $usedNameList): String {
		$cutsTxt = function(String $textToShrink, Int $lengthsMaxNb){ return function_exists('mb_substr') ? mb_substr($textToShrink, 0, $lengthsMaxNb, 'UTF-8') : substr($textToShrink, 0, $lengthsMaxNb); };
		$nameTxt = trim(strval(preg_replace('/[\[\]:*?\/\\\\\x00-\x1F]+/', ' ', preg_match('//u', $sheetHeading) ? $sheetHeading : '')), " '");
		$nameTxt = rtrim($cutsTxt(($nameTxt==='') ? 'Sheet'.$sheetPlaceNb : $nameTxt, 31));
		$lwrList = array_map('strtolower', $usedNameList);
		$baseTxt = $nameTxt;
		for($tryIdx=2; in_array(strtolower($nameTxt), $lwrList, true); $tryIdx++){
			$nameTxt = $cutsTxt($baseTxt, 31-strlen(' ('.$tryIdx.')')).' ('.$tryIdx.')';
		}
		return $nameTxt;
	}

	/**
	 * Tells which sheets are shown. A spreadsheet shows one sheet at
	 * least: when all are hidden, the first is shown.
	 *
	 * @param Array $sheetEntries The sheets.
	 * @return Array True or false for each sheet, in their order.
	 */
	protected function listShownSheet(Array $sheetEntries): Array {
		$shwnLst = Array();
		foreach($sheetEntries as $shtArr){
			$shwnLst[] = !isset($shtArr['attrs']['shown']) || !empty($shtArr['attrs']['shown']);
		}
		if( !empty($shwnLst) && !in_array(true, $shwnLst, true) ){
			$shwnLst[0] = true;
		}
		return $shwnLst;
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
	protected function sheetGridArray(Array $sheetDetails, Bool $writeHeaders): Array {
		$rowsLst = array_values((array)($sheetDetails['cells'] ?? Array()));
		// Every name met, in the order they are met, makes a column
		$headLst = Array();
		$isKeyed = false;
		foreach($rowsLst as $oneRow){
			$isKeyed = ($isKeyed || !$this->validateALstOf((array)$oneRow));
		}
		if( $isKeyed ){
			foreach($rowsLst as $oneRow){
				foreach(array_keys((array)$oneRow) as $column){
					if( !in_array(strval($column), $headLst, true) ){
						$headLst[] = strval($column);
					}
				}
			}
		}
		$gridLst = Array();
		foreach($rowsLst as $oneRow){
			$cellLst = Array();
			if( $isKeyed ){
				foreach($headLst as $column){
					$cellLst[] = ((array)$oneRow)[$column] ?? null;
				}
			}else{
				$cellLst = array_values((array)$oneRow);
			}
			$gridLst[] = $cellLst;
			if( count($gridLst)+(($isKeyed && $writeHeaders) ? 1 : 0)>self::ROWS_MAXI || count($cellLst)>self::COLS_MAXI ){
				throw new WriteError('Sheet larger than a sheet can be: '.self::ROWS_MAXI.' rows of '.self::COLS_MAXI.' cells');
			}
		}
		return Array('heads'=>($isKeyed && $writeHeaders) ? $headLst : Array(), 'cells'=>$gridLst);
	}

	/**
	 * Gives what a cell holds as what a spreadsheet can hold: a number,
	 * true or false, a text, or nothing.
	 *
	 * @param mixed $valueToWrite What the cell was given.
	 * @return String|Int|Float|Bool|Null The value: null for nothing, a binary value or what is no number; a list as its JSON text.
	 */
	protected function simpleCellText(Mixed $valueToWrite): String|Int|Float|Bool|Null {
		if( $valueToWrite===null || $valueToWrite instanceof Fluxd || (is_float($valueToWrite) && !is_finite($valueToWrite)) ){
			return null;
		}
		if( is_array($valueToWrite) || is_object($valueToWrite) ){
			return strval(json_encode(Fluxd::nullifySources(is_array($valueToWrite) ? $valueToWrite : (array)$valueToWrite), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
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
	protected function datePartsArray(String $sourceString): ?Array {
		$dateLst = Array();
		if( !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/D', $sourceString, $dateLst) ){
			return null;
		}
		$partLst = array_map('intval', array_slice($dateLst, 1));
		if( !checkdate($partLst[1], $partLst[2], $partLst[0]) || ($partLst[3] ?? 0)>23 || ($partLst[4] ?? 0)>59 || ($partLst[5] ?? 0)>59 ){
			return null;
		}
		return isset($partLst[3]) ? Array($partLst[0], $partLst[1], $partLst[2], $partLst[3], $partLst[4], $partLst[5] ?? 0) : $partLst;
	}

}
