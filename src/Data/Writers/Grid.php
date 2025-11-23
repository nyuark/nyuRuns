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
	const ROWLIMIT = 1048576;
	const COLLIMIT = 16384;

	/**
	 * Gives a sheet a name every spreadsheet accepts: 31 characters at
	 * most, none of those they keep for themselves, and no name twice.
	 *
	 * @param String $sheetTitle The name asked for.
	 * @param Int    $sheetPlace The place of the sheet, starting from 1, for a sheet without a name.
	 * @param Array  $namesTaken The names already given.
	 * @return String The name.
	 */
	protected function sheetName(String $sheetTitle, Int $sheetPlace, Array $namesTaken): String {
		$cutsTxt = function(String $anyOfTexts, Int $lengthsMax){ return function_exists('mb_substr') ? mb_substr($anyOfTexts, 0, $lengthsMax, 'UTF-8') : substr($anyOfTexts, 0, $lengthsMax); };
		$nameTxt = trim(strval(preg_replace('/[\[\]:*?\/\\\\\x00-\x1F]+/', ' ', preg_match('//u', $sheetTitle) ? $sheetTitle : '')), " '");
		$nameTxt = rtrim($cutsTxt(($nameTxt==='') ? 'Sheet'.$sheetPlace : $nameTxt, 31));
		$lowrLst = array_map('strtolower', $namesTaken);
		$baseTxt = $nameTxt;
		for($tryIdx=2; in_array(strtolower($nameTxt), $lowrLst, true); $tryIdx++){
			$nameTxt = $cutsTxt($baseTxt, 31-strlen(' ('.$tryIdx.')')).' ('.$tryIdx.')';
		}
		return $nameTxt;
	}

	/**
	 * Tells which sheets are shown. A spreadsheet shows one sheet at
	 * least: when all are hidden, the first is shown.
	 *
	 * @param Array $sheetsList The sheets.
	 * @return Array True or false for each sheet, in their order.
	 */
	protected function shownList(Array $sheetsList): Array {
		$shwnLst = Array();
		foreach($sheetsList as $shtArr){
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
	 * @param Array $sheetArray The sheet: 'title', 'attrs', 'cells'.
	 * @param Bool  $writeHeads Whether the names of the columns are written as the first row.
	 * @return Array The names written as the first row under 'heads', none when no such row is written; the rows under 'cells', each a list of values, without that row.
	 *
	 * @throws WriteError When the sheet is larger than a sheet can be.
	 */
	protected function sheetGrid(Array $sheetArray, Bool $writeHeads): Array {
		$rowsLst = array_values((array)($sheetArray['cells'] ?? Array()));
		// Every name met, in the order they are met, makes a column
		$headLst = Array();
		$isKeyed = false;
		foreach($rowsLst as $oneRow){
			$isKeyed = ($isKeyed || !$this->isAListOf((array)$oneRow));
		}
		if( $isKeyed ){
			foreach($rowsLst as $oneRow){
				foreach(array_keys((array)$oneRow) as $colKey){
					if( !in_array(strval($colKey), $headLst, true) ){
						$headLst[] = strval($colKey);
					}
				}
			}
		}
		$gridLst = Array();
		foreach($rowsLst as $oneRow){
			$cellLst = Array();
			if( $isKeyed ){
				foreach($headLst as $colKey){
					$cellLst[] = ((array)$oneRow)[$colKey] ?? null;
				}
			}else{
				$cellLst = array_values((array)$oneRow);
			}
			$gridLst[] = $cellLst;
			if( count($gridLst)+(($isKeyed && $writeHeads) ? 1 : 0)>self::ROWLIMIT || count($cellLst)>self::COLLIMIT ){
				throw new WriteError('Sheet larger than a sheet can be: '.self::ROWLIMIT.' rows of '.self::COLLIMIT.' cells');
			}
		}
		return Array('heads'=>($isKeyed && $writeHeads) ? $headLst : Array(), 'cells'=>$gridLst);
	}

	/**
	 * Gives what a cell holds as what a spreadsheet can hold: a number,
	 * true or false, a text, or nothing.
	 *
	 * @param mixed $valueToPut What the cell was given.
	 * @return String|Int|Float|Bool|Null The value: null for nothing, a binary value or what is no number; a list as its JSON text.
	 */
	protected function plainCell(Mixed $valueToPut): String|Int|Float|Bool|Null {
		if( $valueToPut===null || $valueToPut instanceof Fluxd || (is_float($valueToPut) && !is_finite($valueToPut)) ){
			return null;
		}
		if( is_array($valueToPut) || is_object($valueToPut) ){
			return strval(json_encode(Fluxd::dropFluxd(is_array($valueToPut) ? $valueToPut : (array)$valueToPut), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
		}
		return (is_bool($valueToPut) || is_int($valueToPut) || is_float($valueToPut)) ? $valueToPut : strval($valueToPut);
	}

	/**
	 * Tells whether a text is a date: '2026-10-11', or with its time
	 * '2026-10-11 14:30:00', the seconds being left out at will.
	 *
	 * @param String $plainTexts The text.
	 * @return ?Array The year, the month and the day, then the hours, the minutes and the seconds when it tells a time; null when it is not a date that exists.
	 */
	protected function dateParts(String $plainTexts): ?Array {
		$datePrt = Array();
		if( !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/D', $plainTexts, $datePrt) ){
			return null;
		}
		$partLst = array_map('intval', array_slice($datePrt, 1));
		if( !checkdate($partLst[1], $partLst[2], $partLst[0]) || ($partLst[3] ?? 0)>23 || ($partLst[4] ?? 0)>59 || ($partLst[5] ?? 0)>59 ){
			return null;
		}
		return isset($partLst[3]) ? Array($partLst[0], $partLst[1], $partLst[2], $partLst[3], $partLst[4], $partLst[5] ?? 0) : $partLst;
	}

}
