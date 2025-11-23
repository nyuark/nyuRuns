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
	const STYLLIST = Array('heads'=>1, 'dates'=>2, 'times'=>3);
	// The address of the format, and of what ties its parts together
	const MAINNAME = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	const RELSNAME = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const PACKNAME = 'http://schemas.openxmlformats.org/package/2006';

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
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'; 'infos' may give the title of the workbook under 'title' and its author under 'authr'.
	 * @param Array $writeOptns Options: 'heads', 'dates'.
	 * @return String The bytes of the workbook.
	 *
	 * @throws WriteError When the shape is not 'sheet', or a sheet is larger than a worksheet can be.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$this->checkShpe($shapedData, Array('sheet'), 'a workbook');
		$withHds = !isset($writeOptns['heads']) || !empty($writeOptns['heads']);
		$asDates = !empty($writeOptns['dates']);
		$shetLst = array_values((array)($shapedData['items'] ?? Array()));
		// A workbook holds one sheet at least
		if( empty($shetLst) ){
			$shetLst[] = Array('title'=>'Sheet1', 'attrs'=>Array(), 'cells'=>Array());
		}
		$nameLst = Array();
		$partLst = Array();
		$bookTxt = '';
		$relsTxt = '';
		$typeTxt = '';
		$shwnLst = $this->shownList($shetLst);
		foreach($shetLst as $shtIdx=>$shtArr){
			$shtName = $this->sheetName(strval($shtArr['title'] ?? ''), $shtIdx+1, $nameLst);
			$nameLst[] = $shtName;
			$partLst['xl/worksheets/sheet'.($shtIdx+1).'.xml'] = $this->sheetPart((array)$shtArr, $withHds, $asDates, $shtIdx==array_search(true, $shwnLst, true));
			$bookTxt .= '<sheet name="'.$this->escapeTxt($shtName).'" sheetId="'.($shtIdx+1).'"'.($shwnLst[$shtIdx] ? '' : ' state="hidden"').' r:id="rId'.($shtIdx+1).'"/>';
			$relsTxt .= '<Relationship Id="rId'.($shtIdx+1).'" Type="'.self::RELSNAME.'/worksheet" Target="worksheets/sheet'.($shtIdx+1).'.xml"/>';
			$typeTxt .= '<Override PartName="/xl/worksheets/sheet'.($shtIdx+1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$headXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
		$infoArr = (array)($shapedData['infos'] ?? Array());
		$stylNbr = count($shetLst)+1;
		$fileLst = Array(
			'[Content_Types].xml'=>$headXml.'<Types xmlns="'.self::PACKNAME.'/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
				.'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$typeTxt
				.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
				.'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
			'_rels/.rels'=>$headXml.'<Relationships xmlns="'.self::PACKNAME.'/relationships"><Relationship Id="rId1" Type="'.self::RELSNAME.'/officeDocument" Target="xl/workbook.xml"/>'
				.'<Relationship Id="rId2" Type="'.self::PACKNAME.'/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
			'docProps/core.xml'=>$headXml.'<cp:coreProperties xmlns:cp="'.self::PACKNAME.'/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
				.'<dc:title>'.$this->escapeTxt(is_scalar($infoArr['title'] ?? '') ? strval($infoArr['title'] ?? '') : '').'</dc:title><dc:creator>'.$this->escapeTxt(is_scalar($infoArr['authr'] ?? '') ? strval($infoArr['authr'] ?? '') : '').'</dc:creator>'
				.'<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>',
			'xl/workbook.xml'=>$headXml.'<workbook xmlns="'.self::MAINNAME.'" xmlns:r="'.self::RELSNAME.'"><bookViews><workbookView activeTab="'.intval(array_search(true, $shwnLst, true)).'"/></bookViews><sheets>'.$bookTxt.'</sheets></workbook>',
			'xl/_rels/workbook.xml.rels'=>$headXml.'<Relationships xmlns="'.self::PACKNAME.'/relationships">'.$relsTxt.'<Relationship Id="rId'.$stylNbr.'" Type="'.self::RELSNAME.'/styles" Target="styles.xml"/></Relationships>',
			// The styles: plain, bold for the names of the columns, a date, a date with its time
			'xl/styles.xml'=>$headXml.'<styleSheet xmlns="'.self::MAINNAME.'"><numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/><numFmt numFmtId="165" formatCode="yyyy\-mm\-dd\ hh:mm:ss"/></numFmts>'
				.'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
				.'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
				.'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
				.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
				.'<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
				.'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
				.'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>'
		);
		try{
			return Archv::makeBytes($fileLst + $partLst);
		}catch( \RuntimeException $e ){
			throw new WriteError('Workbook that cannot be written: '.$e->getMessage(), 0, $e);
		}
	}

	/**
	 * Gives the letters of a column from its number.
	 *
	 * @param Int $columnNmbr The number of the column, starting from 0.
	 * @return String Its letters: A, B, and after Z, AA.
	 */
	protected function colLetter(Int $columnNmbr): String {
		$lttrTxt = '';
		for($colNbr=$columnNmbr+1; $colNbr>0; $colNbr=intdiv($colNbr-1, 26)){
			$lttrTxt = chr(65+($colNbr-1)%26).$lttrTxt;
		}
		return $lttrTxt;
	}

	/**
	 * Writes a worksheet: the names of the columns when asked, a row for each row of cells, the widths of the columns and the merged areas.
	 *
	 * @param Array $sheetArray The sheet: 'title', 'attrs', 'cells'.
	 * @param Bool  $writeHeads Whether the names of the columns are written as the first row.
	 * @param Bool  $datesAsOne Whether the texts that are dates are written as dates.
	 * @param Bool  $isSelected Whether the sheet is the one the workbook opens on.
	 * @return String The part of the worksheet.
	 *
	 * @throws WriteError When the sheet is larger than a worksheet can be.
	 */
	protected function sheetPart(Array $sheetArray, Bool $writeHeads, Bool $datesAsOne, Bool $isSelected): String {
		$gridSet = $this->sheetGrid($sheetArray, $writeHeads);
		$headLst = $gridSet['heads'];
		$hasHead = !empty($headLst);
		$dataTxt = '';
		$wideLst = Array();
		$rowNmbr = 0;
		if( $hasHead ){
			$rowNmbr = 1;
			$dataTxt .= '<row r="1">';
			foreach($headLst as $colIdx=>$colKey){
				$dataTxt .= $this->cellsPart($colKey, $this->colLetter($colIdx).'1', false, self::STYLLIST['heads']);
				$wideLst[$colIdx] = strlen($colKey);
			}
			$dataTxt .= '</row>';
		}
		foreach($gridSet['cells'] as $oneRow){
			$rowNmbr++;
			$rowText = '';
			foreach($oneRow as $colIdx=>$celVal){
				$celText = $this->cellsPart($celVal, $this->colLetter($colIdx).$rowNmbr, $datesAsOne, 0);
				if( $celText!=='' ){
					$rowText .= $celText;
					$wideLst[$colIdx] = max($wideLst[$colIdx] ?? 0, is_scalar($celVal) ? strlen(strval($celVal)) : 0);
				}
			}
			// A row holding nothing is not written: the next one tells its number
			if( $rowText!=='' ){
				$dataTxt .= '<row r="'.$rowNmbr.'">'.$rowText.'</row>';
			}
		}
		// The columns are as wide as what they hold, within reason
		$colsTxt = '';
		ksort($wideLst);
		foreach($wideLst as $colIdx=>$widNbr){
			$colsTxt .= '<col min="'.($colIdx+1).'" max="'.($colIdx+1).'" width="'.max(8, min(60, $widNbr+2)).'" customWidth="1"/>';
		}
		$mergTxt = '';
		$mergNbr = 0;
		foreach((array)($sheetArray['attrs']['merge'] ?? Array()) as $mrgArr){
			$mrgArr = array_map('intval', (array)$mrgArr) + Array('atrow'=>-1, 'atcol'=>-1, 'rowsp'=>1, 'colsp'=>1);
			// An area of one cell is no merge, and one leaving the worksheet is not written
			if( $mrgArr['atrow']<0 || $mrgArr['atcol']<0 || $mrgArr['rowsp']<1 || $mrgArr['colsp']<1 || ($mrgArr['rowsp']==1 && $mrgArr['colsp']==1) || $mrgArr['atrow']+$mrgArr['rowsp']>self::ROWLIMIT || $mrgArr['atcol']+$mrgArr['colsp']>self::COLLIMIT ){
				continue;
			}
			$mergTxt .= '<mergeCell ref="'.$this->colLetter($mrgArr['atcol']).($mrgArr['atrow']+1).':'.$this->colLetter($mrgArr['atcol']+$mrgArr['colsp']-1).($mrgArr['atrow']+$mrgArr['rowsp']).'"/>';
			$mergNbr++;
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<worksheet xmlns="'.self::MAINNAME.'">'
			.'<sheetViews><sheetView workbookViewId="0"'.($isSelected ? ' tabSelected="1"' : '').'>'.($hasHead ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '').'</sheetView></sheetViews>'
			.(($colsTxt==='') ? '' : '<cols>'.$colsTxt.'</cols>')
			.'<sheetData>'.$dataTxt.'</sheetData>'
			.(($mergNbr==0) ? '' : '<mergeCells count="'.$mergNbr.'">'.$mergTxt.'</mergeCells>')
			.'</worksheet>';
	}

	/**
	 * Writes a cell with the type of its value: boolean, number, date or text.
	 *
	 * @param mixed  $valueToPut What the cell holds.
	 * @param String $cellsPlace Its place, as 'B4'.
	 * @param Bool   $datesAsOne Whether a text that is a date is written as a date.
	 * @param Int    $stylesNmbr The style of the cell, 0 for none.
	 * @return String The cell; '' for a cell holding nothing.
	 */
	protected function cellsPart(Mixed $valueToPut, String $cellsPlace, Bool $datesAsOne, Int $stylesNmbr): String {
		$openTxt = '<c r="'.$cellsPlace.'"'.(($stylesNmbr>0) ? ' s="'.$stylesNmbr.'"' : '');
		$valueToPut = $this->plainCell($valueToPut);
		if( $valueToPut===null ){
			return '';
		}
		if( is_bool($valueToPut) ){
			return $openTxt.' t="b"><v>'.($valueToPut ? '1' : '0').'</v></c>';
		}
		if( is_int($valueToPut) || is_float($valueToPut) ){
			return $openTxt.'><v>'.$this->numbrText($valueToPut).'</v></c>';
		}
		$textVal = $valueToPut;
		// A date is the number of its days since the end of 1899, and of the part of the day its time is
		$datePrt = $datesAsOne ? $this->dateParts($textVal) : null;
		if( $datePrt!==null && $datePrt[0]>1900 ){
			$daysNbr = intdiv(gmmktime(0, 0, 0, $datePrt[1], $datePrt[2], $datePrt[0]), 86400)+25569;
			if( !isset($datePrt[3]) ){
				return '<c r="'.$cellsPlace.'" s="'.self::STYLLIST['dates'].'"><v>'.$daysNbr.'</v></c>';
			}
			return '<c r="'.$cellsPlace.'" s="'.self::STYLLIST['times'].'"><v>'.$daysNbr.'.'.substr(sprintf('%.10F', ($datePrt[3]*3600+$datePrt[4]*60+$datePrt[5])/86400), 2).'</v></c>';
		}
		// A text is written as text, in the cell itself: nothing in it is ever run
		return $openTxt.' t="inlineStr"><is><t xml:space="preserve">'.$this->escapeTxt($textVal).'</t></is></c>';
	}

}
