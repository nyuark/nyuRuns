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
		$bookPath = 'xl/workbook.xml';
		foreach($this->relsOfFragment('') as $relSet){
			if( substr($relSet['types'], -15)=='/officeDocument' ){
				$bookPath = $relSet['paths'];
			}
		}
		$bookList = $this->partNodesArray($bookPath);
		if( $bookList===null ){
			throw new ParseError('Invalid archive: part '.$bookPath.' is empty');
		}
		$bookRltn = $this->relsOfFragment($bookPath);
		$this->sharedText = Array();
		$this->dateStyles = Array();
		$this->addedCells = 0;
		$this->is1904Base = in_array(strtolower(strval($this->findChildArray($bookList, Array('workbookPr'))['attrs']['date1904'] ?? '')), Array('1', 'true'));
		foreach($bookRltn as $relSet){
			if( substr($relSet['types'], -14)=='/sharedStrings' ){
				$this->loadSharedText($relSet['paths']);
			}elseif( substr($relSet['types'], -7)=='/styles' ){
				$this->findDateStyles($relSet['paths']);
			}
		}
		$nameList = Array();
		$pathList = Array();
		$shownLst = Array();
		foreach($this->kidsNamedArray($this->findChildArray($bookList, Array('sheets')) ?? Array('nodes'=>Array()), 'sheet') as $shtArr){
			$sheetRel = '';
			foreach($shtArr['attrs'] as $atrNme=>$atrVal){
				// The id of the relation, whatever prefix the workbook gives it
				if( $atrNme=='r:id' || substr($atrNme, -3)==':id' ){
					$sheetRel = $atrVal;
				}
			}
			$nameList[] = strval($shtArr['attrs']['name'] ?? '');
			$pathList[] = strval($bookRltn[$sheetRel]['paths'] ?? '');
			// A sheet is hidden, or hidden so that only a program can show it again
			$shownLst[] = !in_array(strtolower(strval($shtArr['attrs']['state'] ?? '')), Array('hidden', 'veryhidden'));
		}
		$sheetLst = Array();
		$headDict = Array();
		foreach($this->pickSheetArray($nameList, $shownLst, $parseOptions) as $shtIdx){
			$mergeLst = Array();
			$headList = Array();
			$rowsList = $this->sheetRowsArray($pathList[$shtIdx], $mergeLst);
			$sheetLst[] = $this->doneSheetArray($nameList[$shtIdx], $rowsList, Array('shown'=>$shownLst[$shtIdx], 'merge'=>$mergeLst), $parseOptions, $headList);
			if( !empty($parseOptions['heads']) ){
				$headDict[$nameList[$shtIdx]] = $headList;
			}
		}
		$this->sharedText = Array();
		return $this->makeShapeArray('sheet', $sheetLst, Array('names'=>$nameList, 'count'=>count($nameList), 'heads'=>$headDict));
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
		$fullText = '';
		foreach($elementArray['nodes'] as $chlArr){
			$childNme = substr($chlArr['tagnm'], intval(strrpos(':'.$chlArr['tagnm'], ':')));
			if( $childNme=='t' ){
				$fullText .= $this->collectAllText($chlArr);
			}elseif( $childNme=='r' ){
				$fullText .= $this->textOfRichCell($chlArr);
			}
		}
		return $fullText;
	}

	/**
	 * Finds the cell styles that show a date or a time: in a workbook, a
	 * date is a number, and only its style says it is one.
	 *
	 * @param String $fullPathName Name of the part holding the styles.
	 */
	protected function findDateStyles(String $fullPathName): Void {
		$styleLst = $this->partNodesArray($fullPathName, false);
		if( $styleLst===null ){
			return;
		}
		// The formats the workbook defines itself: a date when they hold a letter of date or time
		$ownFrmts = Array();
		foreach($this->kidsNamedArray($this->findChildArray($styleLst, Array('numFmts')) ?? Array('nodes'=>Array()), 'numFmt') as $oneFmt){
			$bareFrmt = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', strval($oneFmt['attrs']['formatCode'] ?? ''));
			$ownFrmts[intval($oneFmt['attrs']['numFmtId'] ?? -1)] = (stripos($bareFrmt, 'General')===false && preg_match('/[dmyhs]/i', $bareFrmt)===1);
		}
		foreach($this->kidsNamedArray($this->findChildArray($styleLst, Array('cellXfs')) ?? Array('nodes'=>Array()), 'xf') as $xfIndx=>$xfsArr){
			$stylIdnt = intval($xfsArr['attrs']['numFmtId'] ?? 0);
			$this->dateStyles[$xfIndx] = ($ownFrmts[$stylIdnt] ?? in_array($stylIdnt, self::DATE_FRMT));
		}
	}

	/**
	 * Gives the number of a column from the place of a cell.
	 *
	 * @param String $cellLocation The place, as 'B4' or 'AA10'.
	 * @return Array The column and the row, both starting from 0; -1 for what the place does not tell.
	 */
	protected function placeOfItArray(String $cellLocation): Array {
		$placePar = Array();
		if( !preg_match('/^\$?([A-Za-z]{1,3})\$?(\d{1,7})$/D', $cellLocation, $placePar) ){
			return Array(-1, -1);
		}
		$clmnNumb = 0;
		foreach(str_split(strtoupper($placePar[1])) as $letter){
			$clmnNumb = $clmnNumb*26+(ord($letter)-64);
		}
		return Array($clmnNumb-1, intval($placePar[2])-1);
	}

	/**
	 * Gives a date kept as a number of days as ISO text: a date, a date
	 * and a time, or a time alone when the number is under one day.
	 *
	 * @param Float $daysAsNumber The number of days, as the workbook keeps it.
	 * @return String The date, as '2026-12-28', '2026-12-28 20:30:00' or '20:30:00'.
	 */
	protected function serialToIsoTxt(Float $daysAsNumber): String {
		$secsNumb = intval(round(($daysAsNumber-floor($daysAsNumber))*86400));
		$daysNumb = intval(floor($daysAsNumber))+($this->is1904Base ? 1462 : 0);
		if( $secsNumb>=86400 ){
			$secsNumb = 0;
			$daysNumb++;
		}
		$timeText = sprintf('%02d:%02d:%02d', intdiv($secsNumb, 3600), intdiv($secsNumb%3600, 60), $secsNumb%60);
		if( $daysAsNumber<1 && $daysAsNumber>=0 ){
			return $timeText;
		}
		// Day 25569 is the first of January 1970
		$dateText = gmdate('Y-m-d', ($daysNumb-25569)*86400);
		return ($secsNumb==0) ? $dateText : $dateText.' '.$timeText;
	}

	/**
	 * Gives the value of a cell.
	 *
	 * @param Array $elementArray The element of the cell.
	 * @return String|Int|Float|Bool|Null The value; null for an empty cell.
	 */
	protected function typedCellValue(Array $elementArray): String|Int|Float|Bool|Null {
		$cellCode = strval($elementArray['attrs']['t'] ?? 'n');
		if( $cellCode=='inlineStr' ){
			$inlnList = $this->findChildArray($elementArray, Array('is'));
			return ($inlnList===null) ? null : $this->textOfRichCell($inlnList);
		}
		$valueLst = $this->findChildArray($elementArray, Array('v'));
		if( $valueLst===null ){
			return null;
		}
		$rawValue = $this->collectAllText($valueLst);
		if( $cellCode=='s' ){
			return $this->sharedText[intval($rawValue)] ?? '';
		}
		if( $cellCode=='b' ){
			return ($rawValue=='1' || strtolower($rawValue)=='true');
		}
		// A text given by a formula, an error such as #DIV/0!, or a date already written as one
		if( $cellCode!='n' ){
			return $rawValue;
		}
		if( $rawValue==='' || !is_numeric($rawValue) ){
			return ($rawValue==='') ? null : $rawValue;
		}
		if( !empty($this->dateStyles[intval($elementArray['attrs']['s'] ?? 0)]) ){
			return $this->serialToIsoTxt(floatval($rawValue));
		}
		return preg_match('/^-?\d{1,18}$/D', $rawValue) ? intval($rawValue) : floatval($rawValue);
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
		$sheetLst = $this->partNodesArray($fullPathName);
		$rowsList = Array();
		$mergedRanges = Array();
		foreach($this->kidsNamedArray($this->findChildArray($sheetLst ?? Array('nodes'=>Array()), Array('mergeCells')) ?? Array('nodes'=>Array()), 'mergeCell') as $mrgArr){
			$areaPart = explode(':', strval($mrgArr['attrs']['ref'] ?? '')) + Array('', '');
			$firstPlc = $this->placeOfItArray($areaPart[0]);
			$lastPlce = $this->placeOfItArray($areaPart[1]);
			if( $firstPlc[0]>=0 && $lastPlce[0]>=$firstPlc[0] && $lastPlce[1]>=$firstPlc[1] ){
				$mergedRanges[] = Array('atrow'=>$firstPlc[1], 'atcol'=>$firstPlc[0], 'rowsp'=>$lastPlce[1]-$firstPlc[1]+1, 'colsp'=>$lastPlce[0]-$firstPlc[0]+1);
			}
		}
		foreach($this->kidsNamedArray($this->findChildArray($sheetLst ?? Array('nodes'=>Array()), Array('sheetData')) ?? Array('nodes'=>Array()), 'row') as $rowArr){
			// A row tells its number, or follows the one before it
			$rowIndex = preg_match('/^[1-9]\d{0,6}$/D', strval($rowArr['attrs']['r'] ?? '')) ? intval($rowArr['attrs']['r'])-1 : count($rowsList);
			$cellList = Array();
			foreach($this->kidsNamedArray($rowArr, 'c') as $celArr){
				$clmnIndx = $this->placeOfItArray(strval($celArr['attrs']['r'] ?? ''))[0];
				if( $clmnIndx<count($cellList) ){
					$clmnIndx = count($cellList);
				}
				$this->addedCells += $clmnIndx-count($cellList);
				if( $this->addedCells>self::PADS_MAXI ){
					throw new ParseError('Invalid workbook: a sheet too sparse to be laid out');
				}
				$cellList = array_pad($cellList, $clmnIndx, null);
				$cellList[] = $this->typedCellValue($celArr);
			}
			// The cells left empty at the end of a row are not part of it
			while( !empty($cellList) && end($cellList)===null ){
				array_pop($cellList);
			}
			if( $rowIndex<count($rowsList) ){
				$rowIndex = count($rowsList);
			}
			$this->addedCells += $rowIndex-count($rowsList);
			if( $this->addedCells>self::PADS_MAXI ){
				throw new ParseError('Invalid workbook: a sheet too sparse to be laid out');
			}
			$rowsList = array_pad($rowsList, $rowIndex, Array());
			$rowsList[] = $cellList;
		}
		// Nor are the rows left empty at the end of a sheet
		while( !empty($rowsList) && empty(end($rowsList)) ){
			array_pop($rowsList);
		}
		return $rowsList;
	}

}
