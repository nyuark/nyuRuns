<?php

namespace nyu\Data\Writers;

use nyu\Data\Archv;

/**
 * Excel workbooks (xlsx) written from sheets: every sheet of the content
 * becomes a worksheet, its cells at the place they have. Numbers are
 * written as numbers, true and false as such, everything else as text: a
 * text is never written as a formula, whatever it starts with.
 */
class Xlsx extends Grid {

	// The styles of the cells, by their place in the styles of the workbook
	const STYL_LIST = Array('headCell'=>1, 'dateCell'=>2, 'timeCell'=>3);
	// The address of the format, and of what ties its parts together
	const MAIN_NAME = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	const RELS_NAME = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const PACK_NAME = 'http://schemas.openxmlformats.org/package/2006';

	/**
	 * Writes sheets as a workbook.
	 *
	 * Options:
	 *   'heads' => writes the names of the columns as the first row, bold
	 *              and kept in sight, when the rows are keyed by them; on
	 *              unless told otherwise;
	 *   'dates' => writes the texts that are dates ('2026-10-11' or
	 *              '2026-10-11 14:30:00') as dates a spreadsheet can
	 *              count with; off unless told otherwise.
	 *
	 * What is particular to a sheet is written with it: a sheet that is
	 * not 'shown' is hidden, and its 'merge' areas are merged, their place
	 * counted in the rows as they are written, the row of names included.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'; 'infos' may give the title of the workbook under 'title' and its author under 'authr'.
	 * @param Array $writeOptions Options: 'heads', 'dates'.
	 * @return String The bytes of the workbook.
	 *
	 * @throws WriteError When the shape is not 'sheet', or a sheet is larger than a worksheet can be.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('sheet'), 'a workbook');
		$withHdrs = !isset($writeOptions['heads']) || !empty($writeOptions['heads']);
		$hasDates = !empty($writeOptions['dates']);
		$sheetLst = array_values((array)($dataEnvelope['items'] ?? Array()));
		// A workbook holds one sheet at least
		if( empty($sheetLst) ){
			$sheetLst[] = Array('title'=>'Sheet1', 'attrs'=>Array(), 'cells'=>Array());
		}
		$nameList = Array();
		$partList = Array();
		$bookText = '';
		$rltnText = '';
		$typeText = '';
		$shownLst = $this->listShownSheet($sheetLst);
		foreach($sheetLst as $shtIdx=>$shtArr){
			$sheetNme = $this->wipeSheetTitle(strval($shtArr['title'] ?? ''), $shtIdx+1, $nameList);
			$nameList[] = $sheetNme;
			$partList['xl/worksheets/sheet'.($shtIdx+1).'.xml'] = $this->writeSheetPart((array)$shtArr, $withHdrs, $hasDates, $shtIdx==array_search(true, $shownLst, true));
			$bookText .= '<sheet name="'.$this->escapeXmlValue($sheetNme).'" sheetId="'.($shtIdx+1).'"'.($shownLst[$shtIdx] ? '' : ' state="hidden"').' r:id="rId'.($shtIdx+1).'"/>';
			$rltnText .= '<Relationship Id="rId'.($shtIdx+1).'" Type="'.self::RELS_NAME.'/worksheet" Target="worksheets/sheet'.($shtIdx+1).'.xml"/>';
			$typeText .= '<Override PartName="/xl/worksheets/sheet'.($shtIdx+1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$headBrut = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
		$infoList = (array)($dataEnvelope['infos'] ?? Array());
		$styleCnt = count($sheetLst)+1;
		$fileList = Array(
			'[Content_Types].xml'=>$headBrut.'<Types xmlns="'.self::PACK_NAME.'/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
				.'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$typeText
				.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
				.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
			'_rels/.rels'=>$headBrut.'<Relationships xmlns="'.self::PACK_NAME.'/relationships"><Relationship Id="rId1" Type="'.self::RELS_NAME.'/officeDocument" Target="xl/workbook.xml"/>'
				.'<Relationship Id="rId2" Type="'.self::PACK_NAME.'/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
			'docProps/core.xml'=>$headBrut.'<cp:coreProperties xmlns:cp="'.self::PACK_NAME.'/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
				.'<dc:title>'.$this->escapeXmlValue(is_scalar($infoList['title'] ?? '') ? strval($infoList['title'] ?? '') : '').'</dc:title><dc:creator>'.$this->escapeXmlValue(is_scalar($infoList['authr'] ?? '') ? strval($infoList['authr'] ?? '') : '').'</dc:creator>'
				.'<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>',
			'xl/workbook.xml'=>$headBrut.'<workbook xmlns="'.self::MAIN_NAME.'" xmlns:r="'.self::RELS_NAME.'"><bookViews><workbookView activeTab="'.intval(array_search(true, $shownLst, true)).'"/></bookViews><sheets>'.$bookText.'</sheets></workbook>',
			'xl/_rels/workbook.xml.rels'=>$headBrut.'<Relationships xmlns="'.self::PACK_NAME.'/relationships">'.$rltnText.'<Relationship Id="rId'.$styleCnt.'" Type="'.self::RELS_NAME.'/styles" Target="styles.xml"/></Relationships>',
			// The styles: plain, bold for the names of the columns, a date, a date with its time
			'xl/styles.xml'=>$headBrut.'<styleSheet xmlns="'.self::MAIN_NAME.'"><numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="165" formatCode="yyyy\-mm\-dd\ hh:mm:ss"/></numFmts>'
				.'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
				.'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
				.'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
				.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
				.'<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
				.'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
				.'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>'
		);
		try{
			return Archv::makeZipFromLst($fileList + $partList);
		}catch( \RuntimeException $e ){
			throw new WriteError('Workbook that cannot be written: '.$e->getMessage(), 0, $e);
		}
	}

	/**
	 * Gives the letters of a column from its number.
	 *
	 * @param Int $columnNumber The number of the column, starting from 0.
	 * @return String Its letters: A, B, and after Z, AA.
	 */
	protected function getColumnChars(Int $columnNumber): String {
		$clmnLttr = '';
		for($clmnNb=$columnNumber+1; $clmnNb>0; $clmnNb=intdiv($clmnNb-1, 26)){
			$clmnLttr = chr(65+($clmnNb-1)%26).$clmnLttr;
		}
		return $clmnLttr;
	}

	/**
	 * Writes a worksheet: the names of the columns when asked, a row for each row of cells, the widths of the columns and the merged areas.
	 *
	 * @param Array $sheetDetails The sheet: 'title', 'attrs', 'cells'.
	 * @param Bool  $writeHeaders Whether the names of the columns are written as the first row.
	 * @param Bool  $writeAsDates Whether the texts that are dates are written as dates.
	 * @param Bool  $isShownFirst Whether the sheet is the one the workbook opens on.
	 * @return String The part of the worksheet.
	 *
	 * @throws WriteError When the sheet is larger than a worksheet can be.
	 */
	protected function writeSheetPart(Array $sheetDetails, Bool $writeHeaders, Bool $writeAsDates, Bool $isShownFirst): String {
		$gridDict = $this->castSheetToGrd($sheetDetails, $writeHeaders);
		$headList = $gridDict['heads'];
		$hasHeadr = !empty($headList);
		$dataText = '';
		$wideList = Array();
		$rowCount = 0;
		if( $hasHeadr ){
			$rowCount = 1;
			$dataText .= '<row r="1">';
			foreach($headList as $colInc=>$column){
				$dataText .= $this->writeTypedCell($column, $this->getColumnChars($colInc).'1', false, self::STYL_LIST['headCell']);
				$wideList[$colInc] = strlen($column);
			}
			$dataText .= '</row>';
		}
		foreach($gridDict['cells'] as $oneRow){
			$rowCount++;
			$rowStrng = '';
			foreach($oneRow as $colInc=>$oneVal){
				$cellText = $this->writeTypedCell($oneVal, $this->getColumnChars($colInc).$rowCount, $writeAsDates, 0);
				if( $cellText!=='' ){
					$rowStrng .= $cellText;
					$wideList[$colInc] = max($wideList[$colInc] ?? 0, is_scalar($oneVal) ? strlen(strval($oneVal)) : 0);
				}
			}
			// A row holding nothing is not written: the next one tells its number
			if( $rowStrng!=='' ){
				$dataText .= '<row r="'.$rowCount.'">'.$rowStrng.'</row>';
			}
		}
		// The columns are as wide as what they hold, within reason
		$colsText = '';
		ksort($wideList);
		foreach($wideList as $colInc=>$wdthNb){
			$colsText .= '<col min="'.($colInc+1).'" max="'.($colInc+1).'" width="'.max(8, min(60, $wdthNb+2)).'" customWidth="1"/>';
		}
		$mergeTxt = '';
		$mergeCnt = 0;
		foreach((array)($sheetDetails['attrs']['merge'] ?? Array()) as $mrgArr){
			$mrgArr = array_map('intval', (array)$mrgArr) + Array('atrow'=>-1, 'atcol'=>-1, 'rowsp'=>1, 'colsp'=>1);
			// An area of one cell is no merge, and one leaving the worksheet is not written
			if( $mrgArr['atrow']<0 || $mrgArr['atcol']<0 || $mrgArr['rowsp']<1 || $mrgArr['colsp']<1 || ($mrgArr['rowsp']==1 && $mrgArr['colsp']==1) || $mrgArr['atrow']+$mrgArr['rowsp']>self::ROWS_MAXI || $mrgArr['atcol']+$mrgArr['colsp']>self::COLS_MAXI ){
				continue;
			}
			$mergeTxt .= '<mergeCell ref="'.$this->getColumnChars($mrgArr['atcol']).($mrgArr['atrow']+1).':'.$this->getColumnChars($mrgArr['atcol']+$mrgArr['colsp']-1).($mrgArr['atrow']+$mrgArr['rowsp']).'"/>';
			$mergeCnt++;
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<worksheet xmlns="'.self::MAIN_NAME.'">'
			.'<sheetViews><sheetView workbookViewId="0"'.($isShownFirst ? ' tabSelected="1"' : '').'>'.($hasHeadr ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '').'</sheetView></sheetViews>'
			.(($colsText==='') ? '' : '<cols>'.$colsText.'</cols>')
			.'<sheetData>'.$dataText.'</sheetData>'
			.(($mergeCnt==0) ? '' : '<mergeCells count="'.$mergeCnt.'">'.$mergeTxt.'</mergeCells>')
			.'</worksheet>';
	}

	/**
	 * Writes a cell with the type of its value: boolean, number, date or text.
	 *
	 * @param mixed  $valueToWrite What the cell holds.
	 * @param String $cellLocation Its place, as 'B4'.
	 * @param Bool   $writeAsDates Whether a text that is a date is written as a date.
	 * @param Int    $stylesNumber The style of the cell, 0 for none.
	 * @return String The cell; '' for a cell holding nothing.
	 */
	protected function writeTypedCell(Mixed $valueToWrite, String $cellLocation, Bool $writeAsDates, Int $stylesNumber): String {
		$openText = '<c r="'.$cellLocation.'"'.(($stylesNumber>0) ? ' s="'.$stylesNumber.'"' : '');
		$valueToWrite = $this->castCellScalar($valueToWrite);
		if( $valueToWrite===null ){
			return '';
		}
		if( is_bool($valueToWrite) ){
			return $openText.' t="b"><v>'.($valueToWrite ? '1' : '0').'</v></c>';
		}
		if( is_int($valueToWrite) || is_float($valueToWrite) ){
			return $openText.'><v>'.$this->writeNumberTxt($valueToWrite).'</v></c>';
		}
		$textVals = $valueToWrite;
		// A date is the number of its days since the end of 1899, and of the part of the day its time is
		$dateList = $writeAsDates ? $this->parseDateParts($textVals) : null;
		if( $dateList!==null && $dateList[0]>1900 ){
			$daysNumb = intdiv(gmmktime(0, 0, 0, $dateList[1], $dateList[2], $dateList[0]), 86400)+25569;
			if( !isset($dateList[3]) ){
				return '<c r="'.$cellLocation.'" s="'.self::STYL_LIST['dateCell'].'"><v>'.$daysNumb.'</v></c>';
			}
			return '<c r="'.$cellLocation.'" s="'.self::STYL_LIST['timeCell'].'"><v>'.$daysNumb.'.'.substr(sprintf('%.10F', ($dateList[3]*3600+$dateList[4]*60+$dateList[5])/86400), 2).'</v></c>';
		}
		// A text is written as text, in the cell itself: nothing in it is ever run
		return $openText.' t="inlineStr"><is><t xml:space="preserve">'.$this->escapeXmlValue($textVals).'</t></is></c>';
	}

}
