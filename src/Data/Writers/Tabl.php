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
		$divider = strval($writeOptions['separ'] ?? 'commaSep');
		// The separator whatever its case, as SEPR_LIST writes it
		$divider = strval( array_values(preg_grep('/^'.preg_quote($divider, '/').'$/iD', array_keys(self::SEPR_LIST)))[0] ?? $divider );
		if( !isset(self::SEPR_LIST[$divider]) ){
			throw new WriteError('Invalid table option: unknown separator '.$divider);
		}
		$shetLst = array_values((array)($dataEnvelope['items'] ?? Array()));
		$shetIdx = 0;
		if( isset($writeOptions['sheet']) && $writeOptions['sheet']!=='' ){
			// By its name first, by its position otherwise
			$askdSht = strval($writeOptions['sheet']);
			$shetIdx = array_search($askdSht, array_map('strval', array_column($shetLst, 'title')), true);
			if( $shetIdx===false && preg_match('/^[1-9]\d*$/D', $askdSht) && isset($shetLst[intval($askdSht)-1]) ){
				$shetIdx = intval($askdSht)-1;
			}
			if( $shetIdx===false ){
				throw new WriteError('Invalid table option: no sheet '.$askdSht);
			}
		}
		if( !isset($shetLst[$shetIdx]) ){
			return '';
		}
		$rowsLst = array_values((array)($shetLst[$shetIdx]['cells'] ?? Array()));
		$wthGrid = !isset($writeOptions['guard']) || !empty($writeOptions['guard']);
		// Rows keyed by the names of their columns: every name met, in the order they are met, makes a column
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
		$tblText = '';
		if( $isKeyed && (!isset($writeOptions['heads']) || !empty($writeOptions['heads'])) ){
			$tblText .= $this->writeRowAsLine($headLst, self::SEPR_LIST[$divider], $wthGrid);
		}
		foreach($rowsLst as $oneRow){
			$cellLst = (array)$oneRow;
			if( $isKeyed ){
				// A row that lacks a column has nothing in it
				$cellLst = Array();
				foreach($headLst as $column){
					$cellLst[] = ((array)$oneRow)[$column] ?? null;
				}
			}
			$tblText .= $this->writeRowAsLine($cellLst, self::SEPR_LIST[$divider], $wthGrid);
		}
		return ((!empty($writeOptions['bomrk']) && $tblText!=='') ? "\xEF\xBB\xBF" : '').$tblText;
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
		$textLst = Array();
		foreach($sheetRowList as $oneVal){
			if( is_bool($oneVal) ){
				$textLst[] = $oneVal ? 'true' : 'false';
			}elseif( is_int($oneVal) || is_float($oneVal) ){
				// A number is written with the digits it needs and no more, whatever the server is set to
				$keptPrc = ini_set('serialize_precision', '-1');
				$textLst[] = is_finite(floatval($oneVal)) ? strval(json_encode($oneVal)) : '';
				if( $keptPrc!==false ){
					ini_set('serialize_precision', $keptPrc);
				}
			}elseif( !is_string($oneVal) ){
				$textLst[] = '';
			}else{
				$cellTxt = ($guardFormula && $oneVal!=='' && strpos("=+-@\t\r", $oneVal[0])!==false) ? "'".$oneVal : $oneVal;
				$needQut = (strpbrk($cellTxt, $separatorVal."\"\r\n")!==false || trim($cellTxt)!==$cellTxt);
				$textLst[] = $needQut ? '"'.str_replace('"', '""', $cellTxt).'"' : $cellTxt;
			}
		}
		return implode($separatorVal, $textLst)."\r\n";
	}

}
