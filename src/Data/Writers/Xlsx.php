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
		$withHds = !isset($writeOptions['heads']) || !empty($writeOptions['heads']);
		$asDates = !empty($writeOptions['dates']);
		$shetLst = array_values((array)($dataEnvelope['items'] ?? Array()));
		// A workbook holds one sheet at least
		if( empty($shetLst) ){
			$shetLst[] = Array('title'=>'Sheet1', 'attrs'=>Array(), 'cells'=>Array());
		}
		$nameLst = Array();
		$partLst = Array();
		$bookTxt = '';
		$relsTxt = '';
		$typeTxt = '';
		$shwnLst = $this->listShownSheet($shetLst);
		foreach($shetLst as $shtIdx=>$shtArr){
			$shtName = $this->cleanSheetName(strval($shtArr['title'] ?? ''), $shtIdx+1, $nameLst);
			$nameLst[] = $shtName;
			$partLst['xl/worksheets/sheet'.($shtIdx+1).'.xml'] = $this->sheetPieceText((array)$shtArr, $withHds, $asDates, $shtIdx==array_search(true, $shwnLst, true));
			$bookTxt .= '<sheet name="'.$this->escapeXmlValue($shtName).'" sheetId="'.($shtIdx+1).'"'.($shwnLst[$shtIdx] ? '' : ' state="hidden"').' r:id="rId'.($shtIdx+1).'"/>';
			$relsTxt .= '<Relationship Id="rId'.($shtIdx+1).'" Type="'.self::RELS_NAME.'/worksheet" Target="worksheets/sheet'.($shtIdx+1).'.xml"/>';
			$typeTxt .= '<Override PartName="/xl/worksheets/sheet'.($shtIdx+1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$headXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
		$infoArr = (array)($dataEnvelope['infos'] ?? Array());
		$styleNb = count($shetLst)+1;
		$fileLst = Array(
			'[Content_Types].xml'=>$headXml.'<Types xmlns="'.self::PACK_NAME.'/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
				.'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$typeTxt
				.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
				.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
			'_rels/.rels'=>$headXml.'<Relationships xmlns="'.self::PACK_NAME.'/relationships"><Relationship Id="rId1" Type="'.self::RELS_NAME.'/officeDocument" Target="xl/workbook.xml"/>'
				.'<Relationship Id="rId2" Type="'.self::PACK_NAME.'/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
			'docProps/core.xml'=>$headXml.'<cp:coreProperties xmlns:cp="'.self::PACK_NAME.'/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
				.'<dc:title>'.$this->escapeXmlValue(is_scalar($infoArr['title'] ?? '') ? strval($infoArr['title'] ?? '') : '').'</dc:title><dc:creator>'.$this->escapeXmlValue(is_scalar($infoArr['authr'] ?? '') ? strval($infoArr['authr'] ?? '') : '').'</dc:creator>'
				.'<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>',
			'xl/workbook.xml'=>$headXml.'<workbook xmlns="'.self::MAIN_NAME.'" xmlns:r="'.self::RELS_NAME.'"><bookViews><workbookView activeTab="'.intval(array_search(true, $shwnLst, true)).'"/></bookViews><sheets>'.$bookTxt.'</sheets></workbook>',
			'xl/_rels/workbook.xml.rels'=>$headXml.'<Relationships xmlns="'.self::PACK_NAME.'/relationships">'.$relsTxt.'<Relationship Id="rId'.$styleNb.'" Type="'.self::RELS_NAME.'/styles" Target="styles.xml"/></Relationships>',
			// The styles: plain, bold for the names of the columns, a date, a date with its time
			'xl/styles.xml'=>$headXml.'<styleSheet xmlns="'.self::MAIN_NAME.'"><numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="165" formatCode="yyyy\-mm\-dd\ hh:mm:ss"/></numFmts>'
				.'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
				.'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
				.'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
				.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
				.'<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
				.'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
				.'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>'
		);
		try{
			return Archv::newBytesString($fileLst + $partLst);
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
	protected function columnCharText(Int $columnNumber): String {
		$lttrTxt = '';
		for($clmnNb=$columnNumber+1; $clmnNb>0; $clmnNb=intdiv($clmnNb-1, 26)){
			$lttrTxt = chr(65+($clmnNb-1)%26).$lttrTxt;
		}
		return $lttrTxt;
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
	protected function sheetPieceText(Array $sheetDetails, Bool $writeHeaders, Bool $writeAsDates, Bool $isShownFirst): String {
		$gridSet = $this->sheetGridArray($sheetDetails, $writeHeaders);
		$headLst = $gridSet['heads'];
		$hasHead = !empty($headLst);
		$dataTxt = '';
		$wideLst = Array();
		$rowNumb = 0;
		if( $hasHead ){
			$rowNumb = 1;
			$dataTxt .= '<row r="1">';
			foreach($headLst as $colInc=>$column){
				$dataTxt .= $this->cellsPieceText($column, $this->columnCharText($colInc).'1', false, self::STYL_LIST['headCell']);
				$wideLst[$colInc] = strlen($column);
			}
			$dataTxt .= '</row>';
		}
		foreach($gridSet['cells'] as $oneRow){
			$rowNumb++;
			$rowText = '';
			foreach($oneRow as $colInc=>$oneVal){
				$cellTxt = $this->cellsPieceText($oneVal, $this->columnCharText($colInc).$rowNumb, $writeAsDates, 0);
				if( $cellTxt!=='' ){
					$rowText .= $cellTxt;
					$wideLst[$colInc] = max($wideLst[$colInc] ?? 0, is_scalar($oneVal) ? strlen(strval($oneVal)) : 0);
				}
			}
			// A row holding nothing is not written: the next one tells its number
			if( $rowText!=='' ){
				$dataTxt .= '<row r="'.$rowNumb.'">'.$rowText.'</row>';
			}
		}
		// The columns are as wide as what they hold, within reason
		$colsTxt = '';
		ksort($wideLst);
		foreach($wideLst as $colInc=>$wdthNb){
			$colsTxt .= '<col min="'.($colInc+1).'" max="'.($colInc+1).'" width="'.max(8, min(60, $wdthNb+2)).'" customWidth="1"/>';
		}
		$mrgText = '';
		$mergeNb = 0;
		foreach((array)($sheetDetails['attrs']['merge'] ?? Array()) as $mrgArr){
			$mrgArr = array_map('intval', (array)$mrgArr) + Array('atrow'=>-1, 'atcol'=>-1, 'rowsp'=>1, 'colsp'=>1);
			// An area of one cell is no merge, and one leaving the worksheet is not written
			if( $mrgArr['atrow']<0 || $mrgArr['atcol']<0 || $mrgArr['rowsp']<1 || $mrgArr['colsp']<1 || ($mrgArr['rowsp']==1 && $mrgArr['colsp']==1) || $mrgArr['atrow']+$mrgArr['rowsp']>self::ROWS_MAXI || $mrgArr['atcol']+$mrgArr['colsp']>self::COLS_MAXI ){
				continue;
			}
			$mrgText .= '<mergeCell ref="'.$this->columnCharText($mrgArr['atcol']).($mrgArr['atrow']+1).':'.$this->columnCharText($mrgArr['atcol']+$mrgArr['colsp']-1).($mrgArr['atrow']+$mrgArr['rowsp']).'"/>';
			$mergeNb++;
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<worksheet xmlns="'.self::MAIN_NAME.'">'
			.'<sheetViews><sheetView workbookViewId="0"'.($isShownFirst ? ' tabSelected="1"' : '').'>'.($hasHead ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '').'</sheetView></sheetViews>'
			.(($colsTxt==='') ? '' : '<cols>'.$colsTxt.'</cols>')
			.'<sheetData>'.$dataTxt.'</sheetData>'
			.(($mergeNb==0) ? '' : '<mergeCells count="'.$mergeNb.'">'.$mrgText.'</mergeCells>')
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
	protected function cellsPieceText(Mixed $valueToWrite, String $cellLocation, Bool $writeAsDates, Int $stylesNumber): String {
		$openTxt = '<c r="'.$cellLocation.'"'.(($stylesNumber>0) ? ' s="'.$stylesNumber.'"' : '');
		$valueToWrite = $this->simpleCellText($valueToWrite);
		if( $valueToWrite===null ){
			return '';
		}
		if( is_bool($valueToWrite) ){
			return $openTxt.' t="b"><v>'.($valueToWrite ? '1' : '0').'</v></c>';
		}
		if( is_int($valueToWrite) || is_float($valueToWrite) ){
			return $openTxt.'><v>'.$this->writeNumberTxt($valueToWrite).'</v></c>';
		}
		$textVal = $valueToWrite;
		// A date is the number of its days since the end of 1899, and of the part of the day its time is
		$dateLst = $writeAsDates ? $this->datePartsArray($textVal) : null;
		if( $dateLst!==null && $dateLst[0]>1900 ){
			$daysNum = intdiv(gmmktime(0, 0, 0, $dateLst[1], $dateLst[2], $dateLst[0]), 86400)+25569;
			if( !isset($dateLst[3]) ){
				return '<c r="'.$cellLocation.'" s="'.self::STYL_LIST['dateCell'].'"><v>'.$daysNum.'</v></c>';
			}
			return '<c r="'.$cellLocation.'" s="'.self::STYL_LIST['timeCell'].'"><v>'.$daysNum.'.'.substr(sprintf('%.10F', ($dateLst[3]*3600+$dateLst[4]*60+$dateLst[5])/86400), 2).'</v></c>';
		}
		// A text is written as text, in the cell itself: nothing in it is ever run
		return $openTxt.' t="inlineStr"><is><t xml:space="preserve">'.$this->escapeXmlValue($textVal).'</t></is></c>';
	}

}
