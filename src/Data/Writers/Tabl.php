<?php

namespace nyu\Data\Writers;

/**
 * Tables written as text: CSV, TSV and their kin. One sheet is written,
 * one row per line; a cell holding the separator, a quote, a line end or
 * a space at one of its ends is written between double quotes.
 */
class Tabl extends Base {

	// Separators, by the code that asks for them
	const SEPR_LIST = Array('commaSep'=>',', 'semicSep'=>';', 'tabulSep'=>"\t", 'pipesSep'=>'|');

	/**
	 * Writes a sheet as a table.
	 *
	 * Options:
	 *   'separ' => the separator: 'commaSep', 'semicSep', 'tabulSep' or 'pipesSep';
	 *              'commaSep' when left out;
	 *   'sheet' => the sheet to write: its name, or its position starting
	 *              from 1; the first one when left out;
	 *   'heads' => writes the names of the columns as the first row, when
	 *              the rows are keyed by them; on unless told otherwise;
	 *   'guard' => writes a text a spreadsheet would run as a formula,
	 *              one starting with =, +, - or @, after an apostrophe,
	 *              which makes it text again; on unless told otherwise;
	 *   'bomrk' => starts the table with the marker that tells a
	 *              spreadsheet its text is UTF-8; off unless told
	 *              otherwise, whatever the table was read with.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options: 'separ', 'sheet', 'heads', 'guard', 'bomrk'.
	 * @return String The table, its lines ending as the format asks; '' when the content has no sheet.
	 *
	 * @throws WriteError When the shape is not 'sheet', the separator is not a known one, or the sheet asked for is not in the content.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('sheet'), 'a table');
		$dvdrText = strval($writeOptions['separ'] ?? 'commaSep');
		// The separator whatever its case, as SEPR_LIST writes it
		$dvdrText = strval( array_values(preg_grep('/^'.preg_quote($dvdrText, '/').'$/iD', array_keys(self::SEPR_LIST)))[0] ?? $dvdrText );
		if( !isset(self::SEPR_LIST[$dvdrText]) ){
			throw new WriteError('Invalid table option: unknown separator '.$dvdrText);
		}
		$sheetLst = array_values((array)($dataEnvelope['items'] ?? Array()));
		$sheetIdx = 0;
		if( isset($writeOptions['sheet']) && $writeOptions['sheet']!=='' ){
			// By its name first, by its position otherwise
			$askdTabs = strval($writeOptions['sheet']);
			$sheetIdx = array_search($askdTabs, array_map('strval', array_column($sheetLst, 'title')), true);
			if( $sheetIdx===false && preg_match('/^[1-9]\d*$/D', $askdTabs) && isset($sheetLst[intval($askdTabs)-1]) ){
				$sheetIdx = intval($askdTabs)-1;
			}
			if( $sheetIdx===false ){
				throw new WriteError('Invalid table option: no sheet '.$askdTabs);
			}
		}
		if( !isset($sheetLst[$sheetIdx]) ){
			return '';
		}
		$rowsList = array_values((array)($sheetLst[$sheetIdx]['cells'] ?? Array()));
		$hasGuard = !isset($writeOptions['guard']) || !empty($writeOptions['guard']);
		// Rows keyed by the names of their columns: every name met, in the order they are met, makes a column
		$headList = Array();
		$isByKeys = false;
		foreach($rowsList as $oneRow){
			$isByKeys = ($isByKeys || !$this->validateALstOf((array)$oneRow));
		}
		if( $isByKeys ){
			foreach($rowsList as $oneRow){
				foreach(array_keys((array)$oneRow) as $column){
					if( !in_array(strval($column), $headList, true) ){
						$headList[] = strval($column);
					}
				}
			}
		}
		$tableTxt = '';
		if( $isByKeys && (!isset($writeOptions['heads']) || !empty($writeOptions['heads'])) ){
			$tableTxt .= $this->writeRowAsLine($headList, self::SEPR_LIST[$dvdrText], $hasGuard);
		}
		foreach($rowsList as $oneRow){
			$cellList = (array)$oneRow;
			if( $isByKeys ){
				// A row that lacks a column has nothing in it
				$cellList = Array();
				foreach($headList as $column){
					$cellList[] = ((array)$oneRow)[$column] ?? null;
				}
			}
			$tableTxt .= $this->writeRowAsLine($cellList, self::SEPR_LIST[$dvdrText], $hasGuard);
		}
		return ((!empty($writeOptions['bomrk']) && $tableTxt!=='') ? "\xEF\xBB\xBF" : '').$tableTxt;
	}

	/**
	 * Writes a row as a line.
	 *
	 * @param Array  $sheetRowList The values of its cells.
	 * @param String $separatorVal The separator.
	 * @param Bool   $guardFormula Whether the texts a spreadsheet would run as a formula are written after an apostrophe.
	 * @return String The line, with its line end.
	 */
	protected function writeRowAsLine(Array $sheetRowList, String $separatorVal, Bool $guardFormula): String {
		$textList = Array();
		foreach($sheetRowList as $oneVal){
			if( is_bool($oneVal) ){
				$textList[] = $oneVal ? 'true' : 'false';
			}elseif( is_int($oneVal) || is_float($oneVal) ){
				// A number is written with the digits it needs and no more, whatever the server is set to
				$keptPrec = ini_set('serialize_precision', '-1');
				$textList[] = is_finite(floatval($oneVal)) ? strval(json_encode($oneVal)) : '';
				if( $keptPrec!==false ){
					ini_set('serialize_precision', $keptPrec);
				}
			}elseif( !is_string($oneVal) ){
				$textList[] = '';
			}else{
				$cellText = ($guardFormula && $oneVal!=='' && strpos("=+-@\t\r", $oneVal[0])!==false) ? "'".$oneVal : $oneVal;
				$needQuot = (strpbrk($cellText, $separatorVal."\"\r\n")!==false || trim($cellText)!==$cellText);
				$textList[] = $needQuot ? '"'.str_replace('"', '""', $cellText).'"' : $cellText;
			}
		}
		return implode($separatorVal, $textList)."\r\n";
	}

}
