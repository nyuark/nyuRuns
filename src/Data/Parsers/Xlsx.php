<?php

namespace nyu\Data\Parsers;

/**
 * Excel workbooks (xlsx): one sheet per worksheet, its cells at the place
 * they have in the workbook. Numbers are given as numbers, booleans as
 * booleans, dates as ISO text, empty cells as null, and a formula as the
 * result the workbook stored for it.
 */
class Xlsx extends Pack {

	const SHPE_OUTS = 'sheet';
	// Number formats of Excel that are dates or times, by their id
	const DATE_FRMT = Array(14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58);
	// Greatest number of empty rows and cells added to keep the cells at their place
	const PADS_MAXI = 1000000;

	// The texts shared by the cells, the styles that show a date, and whether the workbook counts its days from 1904
	protected $sharedText = Array();
	protected $dateStyles = Array();
	protected $is1904Base = false;
	// Empty rows and cells added so far
	protected $addedCells = 0;

	/**
	 * Reads the workbook of an opened archive.
	 *
	 * Options:
	 *   'sheet' => reads one sheet only: its name, or its position
	 *              starting from 1; every sheet when left out;
	 *   'heads' => takes the first row of each sheet as the names of its
	 *              columns: every other row is then keyed by them;
	 *   'shown' => leaves out the sheets the workbook hides;
	 *   'merge' => 'keeps' leaves the value of merged cells in the first
	 *              of them, 'fills' copies it into each; 'keeps' when
	 *              left out.
	 *
	 * @param Array $parseOptions Options: 'sheet', 'heads', 'shown', 'merge'.
	 * @return Array The envelope, of shape 'sheet'; 'infos' holds the names of all the sheets of the workbook under 'names', their number under 'count' and, with the 'heads' option, the names of the columns of each sheet read under 'heads'.
	 *
	 * @throws ParseError When the archive is not a valid workbook, the sheet asked for is not in it, or a sheet is too sparse to be laid out.
	 */
	protected function readArchvArray(Array $parseOptions): Array {
		// The archive says where its workbook is; it is in xl/workbook.xml unless told otherwise
		$bokPath = 'xl/workbook.xml';
		foreach($this->relsOfFragment('') as $relSet){
			if( substr($relSet['types'], -15)=='/officeDocument' ){
				$bokPath = $relSet['paths'];
			}
		}
		$bookArr = $this->partNodesArray($bokPath);
		if( $bookArr===null ){
			throw new ParseError('Invalid archive: part '.$bokPath.' is empty');
		}
		$bookRel = $this->relsOfFragment($bokPath);
		$this->sharedText = Array();
		$this->dateStyles = Array();
		$this->addedCells = 0;
		$this->is1904Base = in_array(strtolower(strval($this->findChildArray($bookArr, Array('workbookPr'))['attrs']['date1904'] ?? '')), Array('1', 'true'));
		foreach($bookRel as $relSet){
			if( substr($relSet['types'], -14)=='/sharedStrings' ){
				$this->loadSharedText($relSet['paths']);
			}elseif( substr($relSet['types'], -7)=='/styles' ){
				$this->findDateStyles($relSet['paths']);
			}
		}
		$nameLst = Array();
		$pathLst = Array();
		$shwnLst = Array();
		foreach($this->kidsNamedArray($this->findChildArray($bookArr, Array('sheets')) ?? Array('nodes'=>Array()), 'sheet') as $shtArr){
			$shtRltn = '';
			foreach($shtArr['attrs'] as $atrNme=>$atrVal){
				// The id of the relation, whatever prefix the workbook gives it
				if( $atrNme=='r:id' || substr($atrNme, -3)==':id' ){
					$shtRltn = $atrVal;
				}
			}
			$nameLst[] = strval($shtArr['attrs']['name'] ?? '');
			$pathLst[] = strval($bookRel[$shtRltn]['paths'] ?? '');
			// A sheet is hidden, or hidden so that only a program can show it again
			$shwnLst[] = !in_array(strtolower(strval($shtArr['attrs']['state'] ?? '')), Array('hidden', 'veryhidden'));
		}
		$shetLst = Array();
		$headSet = Array();
		foreach($this->pickSheetArray($nameLst, $shwnLst, $parseOptions) as $shtIdx){
			$mrgList = Array();
			$headLst = Array();
			$rowsLst = $this->sheetRowsArray($pathLst[$shtIdx], $mrgList);
			$shetLst[] = $this->doneSheetArray($nameLst[$shtIdx], $rowsLst, Array('shown'=>$shwnLst[$shtIdx], 'merge'=>$mrgList), $parseOptions, $headLst);
			if( !empty($parseOptions['heads']) ){
				$headSet[$nameLst[$shtIdx]] = $headLst;
			}
		}
		$this->sharedText = Array();
		return $this->makeShapeArray('sheet', $shetLst, Array('names'=>$nameLst, 'count'=>count($nameLst), 'heads'=>$headSet));
	}

	/**
	 * Reads the texts shared by the cells of the workbook.
	 *
	 * @param String $fullPathName Name of the part holding them.
	 */
	protected function loadSharedText(String $fullPathName): Void {
		foreach($this->kidsNamedArray($this->partNodesArray($fullPathName, false) ?? Array('nodes'=>Array()), 'si') as $txtArr){
			$this->sharedText[] = $this->textOfRichCell($txtArr);
		}
	}

	/**
	 * Gives the text of a cell written as rich text: its pieces, without
	 * the reading aids some languages add to them.
	 *
	 * @param Array $elementArray The element holding the text.
	 * @return String The text.
	 */
	protected function textOfRichCell(Array $elementArray): String {
		$fullTxt = '';
		foreach($elementArray['nodes'] as $chlArr){
			$chldNme = substr($chlArr['tagnm'], intval(strrpos(':'.$chlArr['tagnm'], ':')));
			if( $chldNme=='t' ){
				$fullTxt .= $this->collectAllText($chlArr);
			}elseif( $chldNme=='r' ){
				$fullTxt .= $this->textOfRichCell($chlArr);
			}
		}
		return $fullTxt;
	}

	/**
	 * Finds the cell styles that show a date or a time: in a workbook, a
	 * date is a number, and only its style says it is one.
	 *
	 * @param String $fullPathName Name of the part holding the styles.
	 */
	protected function findDateStyles(String $fullPathName): Void {
		$stylArr = $this->partNodesArray($fullPathName, false);
		if( $stylArr===null ){
			return;
		}
		// The formats the workbook defines itself: a date when they hold a letter of date or time
		$ownFmts = Array();
		foreach($this->kidsNamedArray($this->findChildArray($stylArr, Array('numFmts')) ?? Array('nodes'=>Array()), 'numFmt') as $oneFmt){
			$bareFmt = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', strval($oneFmt['attrs']['formatCode'] ?? ''));
			$ownFmts[intval($oneFmt['attrs']['numFmtId'] ?? -1)] = (stripos($bareFmt, 'General')===false && preg_match('/[dmyhs]/i', $bareFmt)===1);
		}
		foreach($this->kidsNamedArray($this->findChildArray($stylArr, Array('cellXfs')) ?? Array('nodes'=>Array()), 'xf') as $xfIndx=>$xfsArr){
			$styleId = intval($xfsArr['attrs']['numFmtId'] ?? 0);
			$this->dateStyles[$xfIndx] = ($ownFmts[$styleId] ?? in_array($styleId, self::DATE_FRMT));
		}
	}

	/**
	 * Gives the number of a column from the place of a cell.
	 *
	 * @param String $cellLocation The place, as 'B4' or 'AA10'.
	 * @return Array The column and the row, both starting from 0; -1 for what the place does not tell.
	 */
	protected function placeOfItArray(String $cellLocation): Array {
		$plcPart = Array();
		if( !preg_match('/^\$?([A-Za-z]{1,3})\$?(\d{1,7})$/D', $cellLocation, $plcPart) ){
			return Array(-1, -1);
		}
		$clmnNum = 0;
		foreach(str_split(strtoupper($plcPart[1])) as $letter){
			$clmnNum = $clmnNum*26+(ord($letter)-64);
		}
		return Array($clmnNum-1, intval($plcPart[2])-1);
	}

	/**
	 * Gives a date kept as a number of days as ISO text: a date, a date
	 * and a time, or a time alone when the number is under one day.
	 *
	 * @param Float $daysAsNumber The number of days, as the workbook keeps it.
	 * @return String The date, as '2026-12-28', '2026-12-28 20:30:00' or '20:30:00'.
	 */
	protected function serialToIsoTxt(Float $daysAsNumber): String {
		$secsNum = intval(round(($daysAsNumber-floor($daysAsNumber))*86400));
		$daysNum = intval(floor($daysAsNumber))+($this->is1904Base ? 1462 : 0);
		if( $secsNum>=86400 ){
			$secsNum = 0;
			$daysNum++;
		}
		$timeTxt = sprintf('%02d:%02d:%02d', intdiv($secsNum, 3600), intdiv($secsNum%3600, 60), $secsNum%60);
		if( $daysAsNumber<1 && $daysAsNumber>=0 ){
			return $timeTxt;
		}
		// Day 25569 is the first of January 1970
		$dateTxt = gmdate('Y-m-d', ($daysNum-25569)*86400);
		return ($secsNum==0) ? $dateTxt : $dateTxt.' '.$timeTxt;
	}

	/**
	 * Gives the value of a cell.
	 *
	 * @param Array $elementArray The element of the cell.
	 * @return String|Int|Float|Bool|Null The value; null for an empty cell.
	 */
	protected function typedCellValue(Array $elementArray): String|Int|Float|Bool|Null {
		$cellTyp = strval($elementArray['attrs']['t'] ?? 'n');
		if( $cellTyp=='inlineStr' ){
			$inlnArr = $this->findChildArray($elementArray, Array('is'));
			return ($inlnArr===null) ? null : $this->textOfRichCell($inlnArr);
		}
		$valArry = $this->findChildArray($elementArray, Array('v'));
		if( $valArry===null ){
			return null;
		}
		$rawValu = $this->collectAllText($valArry);
		if( $cellTyp=='s' ){
			return $this->sharedText[intval($rawValu)] ?? '';
		}
		if( $cellTyp=='b' ){
			return ($rawValu=='1' || strtolower($rawValu)=='true');
		}
		// A text given by a formula, an error such as #DIV/0!, or a date already written as one
		if( $cellTyp!='n' ){
			return $rawValu;
		}
		if( $rawValu==='' || !is_numeric($rawValu) ){
			return ($rawValu==='') ? null : $rawValu;
		}
		if( !empty($this->dateStyles[intval($elementArray['attrs']['s'] ?? 0)]) ){
			return $this->serialToIsoTxt(floatval($rawValu));
		}
		return preg_match('/^-?\d{1,18}$/D', $rawValu) ? intval($rawValu) : floatval($rawValu);
	}

	/**
	 * Reads the rows of a worksheet. Every cell stands at its place: the
	 * rows and the cells the workbook leaves out are given empty, up to
	 * the last one written.
	 *
	 * @param String $fullPathName Name of the part holding the worksheet.
	 * @param Array  $mergedRanges Receives the areas of merged cells of the worksheet.
	 * @return Array The rows, each a list of values.
	 *
	 * @throws ParseError When the worksheet is missing, or too sparse to be laid out.
	 */
	protected function sheetRowsArray(String $fullPathName, Array &$mergedRanges): Array {
		$shetArr = $this->partNodesArray($fullPathName);
		$rowsLst = Array();
		$mergedRanges = Array();
		foreach($this->kidsNamedArray($this->findChildArray($shetArr ?? Array('nodes'=>Array()), Array('mergeCells')) ?? Array('nodes'=>Array()), 'mergeCell') as $mrgArr){
			$areaPrt = explode(':', strval($mrgArr['attrs']['ref'] ?? '')) + Array('', '');
			$frstPlc = $this->placeOfItArray($areaPrt[0]);
			$lastPlc = $this->placeOfItArray($areaPrt[1]);
			if( $frstPlc[0]>=0 && $lastPlc[0]>=$frstPlc[0] && $lastPlc[1]>=$frstPlc[1] ){
				$mergedRanges[] = Array('atrow'=>$frstPlc[1], 'atcol'=>$frstPlc[0], 'rowsp'=>$lastPlc[1]-$frstPlc[1]+1, 'colsp'=>$lastPlc[0]-$frstPlc[0]+1);
			}
		}
		foreach($this->kidsNamedArray($this->findChildArray($shetArr ?? Array('nodes'=>Array()), Array('sheetData')) ?? Array('nodes'=>Array()), 'row') as $rowArr){
			// A row tells its number, or follows the one before it
			$rowIndx = preg_match('/^[1-9]\d{0,6}$/D', strval($rowArr['attrs']['r'] ?? '')) ? intval($rowArr['attrs']['r'])-1 : count($rowsLst);
			$cellLst = Array();
			foreach($this->kidsNamedArray($rowArr, 'c') as $celArr){
				$clmnIdx = $this->placeOfItArray(strval($celArr['attrs']['r'] ?? ''))[0];
				if( $clmnIdx<count($cellLst) ){
					$clmnIdx = count($cellLst);
				}
				$this->addedCells += $clmnIdx-count($cellLst);
				if( $this->addedCells>self::PADS_MAXI ){
					throw new ParseError('Invalid workbook: a sheet too sparse to be laid out');
				}
				$cellLst = array_pad($cellLst, $clmnIdx, null);
				$cellLst[] = $this->typedCellValue($celArr);
			}
			// The cells left empty at the end of a row are not part of it
			while( !empty($cellLst) && end($cellLst)===null ){
				array_pop($cellLst);
			}
			if( $rowIndx<count($rowsLst) ){
				$rowIndx = count($rowsLst);
			}
			$this->addedCells += $rowIndx-count($rowsLst);
			if( $this->addedCells>self::PADS_MAXI ){
				throw new ParseError('Invalid workbook: a sheet too sparse to be laid out');
			}
			$rowsLst = array_pad($rowsLst, $rowIndx, Array());
			$rowsLst[] = $cellLst;
		}
		// Nor are the rows left empty at the end of a sheet
		while( !empty($rowsLst) && empty(end($rowsLst)) ){
			array_pop($rowsLst);
		}
		return $rowsLst;
	}

}
