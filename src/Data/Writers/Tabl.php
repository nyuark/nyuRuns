<?php

namespace nyu\Data\Writers;

// Tables written as text: CSV, TSV and their kin. One sheet is written,
// one row per line; a cell holding the separator, a quote, a line end or
// a space at one of its ends is written between double quotes.
class Tabl extends Base {

	// Separators, by the code that asks for them
	const SEPRLIST = Array('comma'=>',', 'semic'=>';', 'tabul'=>"\t", 'pipes'=>'|');

	/**
	 * Writes a sheet as a table.
	 *
	 * Options:
	 *   'separ' => the separator: 'comma', 'semic', 'tabul' or 'pipes';
	 *              'comma' when left out;
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
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'separ', 'sheet', 'heads', 'guard', 'bomrk'.
	 * @return String The table, its lines ending as the format asks; '' when the content has no sheet.
	 *
	 * @throws WriteError When the shape is not 'sheet', the separator is not a known one, or the sheet asked for is not in the content.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$this->checkShpe($shapedData, Array('sheet'), 'a table');
		$seprKey = strtolower(strval($writeOptns['separ'] ?? 'comma'));
		if( !isset(self::SEPRLIST[$seprKey]) ){
			throw new WriteError('Invalid table option: unknown separator '.$seprKey);
		}
		$shetLst = array_values((array)($shapedData['items'] ?? Array()));
		$shetIdx = 0;
		if( isset($writeOptns['sheet']) && $writeOptns['sheet']!=='' ){
			// By its name first, by its position otherwise
			$askdSht = strval($writeOptns['sheet']);
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
		$withGrd = !isset($writeOptns['guard']) || !empty($writeOptns['guard']);
		// Rows keyed by the names of their columns: every name met, in the order they are met, makes a column
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
		$tblText = '';
		if( $isKeyed && (!isset($writeOptns['heads']) || !empty($writeOptns['heads'])) ){
			$tblText .= $this->lineOfRow($headLst, self::SEPRLIST[$seprKey], $withGrd);
		}
		foreach($rowsLst as $oneRow){
			$cellLst = (array)$oneRow;
			if( $isKeyed ){
				// A row that lacks a column has nothing in it
				$cellLst = Array();
				foreach($headLst as $colKey){
					$cellLst[] = ((array)$oneRow)[$colKey] ?? null;
				}
			}
			$tblText .= $this->lineOfRow($cellLst, self::SEPRLIST[$seprKey], $withGrd);
		}
		return ((!empty($writeOptns['bomrk']) && $tblText!=='') ? "\xEF\xBB\xBF" : '').$tblText;
	}

	/**
	 * Writes a row as a line.
	 *
	 * @param Array  $cellsArray The values of its cells.
	 * @param String $separValue The separator.
	 * @param Bool   $guardTexts Whether the texts a spreadsheet would run as a formula are written after an apostrophe.
	 * @return String The line, with its line end.
	 */
	protected function lineOfRow(Array $cellsArray, String $separValue, Bool $guardTexts): String {
		$textLst = Array();
		foreach($cellsArray as $celVal){
			if( is_bool($celVal) ){
				$textLst[] = $celVal ? 'true' : 'false';
			}elseif( is_int($celVal) || is_float($celVal) ){
				// A number is written with the digits it needs and no more, whatever the server is set to
				$keptPrc = ini_set('serialize_precision', '-1');
				$textLst[] = is_finite(floatval($celVal)) ? strval(json_encode($celVal)) : '';
				if( $keptPrc!==false ){
					ini_set('serialize_precision', $keptPrc);
				}
			}elseif( !is_string($celVal) ){
				$textLst[] = '';
			}else{
				$celText = ($guardTexts && $celVal!=='' && strpos("=+-@\t\r", $celVal[0])!==false) ? "'".$celVal : $celVal;
				$needQte = (strpbrk($celText, $separValue."\"\r\n")!==false || trim($celText)!==$celText);
				$textLst[] = $needQte ? '"'.str_replace('"', '""', $celText).'"' : $celText;
			}
		}
		return implode($separValue, $textLst)."\r\n";
	}

}
