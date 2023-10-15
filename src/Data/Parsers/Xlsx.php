<?php

namespace nyu\Data\Parsers;

// Excel workbooks (xlsx): one sheet per worksheet, its cells at the place
// they have in the workbook. Numbers are given as numbers, booleans as
// booleans, dates as ISO text, empty cells as null, and a formula as the
// result the workbook stored for it.
class Xlsx extends Pack {

	const SHAPEOUT = 'sheet';
	// Number formats of Excel that are dates or times, by their id
	const DATEFMTS = Array(14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58);
	// Greatest number of empty rows and cells added to keep the cells at their place
	const PADLIMIT = 1000000;

	// The texts shared by the cells, the styles that show a date, and whether the workbook counts its days from 1904
	protected $textList = Array();
	protected $dateList = Array();
	protected $from1904 = false;
	// Empty rows and cells added so far
	protected $padCount = 0;

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
	 * @param Array $parseOptns Options: 'sheet', 'heads', 'shown', 'merge'.
	 * @return Array The envelope, of shape 'sheet'; 'infos' holds the names of all the sheets of the workbook under 'names', their number under 'count' and, with the 'heads' option, the names of the columns of each sheet read under 'heads'.
	 *
	 * @throws ParseError When the archive is not a valid workbook, the sheet asked for is not in it, or a sheet is too sparse to be laid out.
	 */
	protected function readArchv(Array $parseOptns): Array {
		// The archive says where its workbook is; it is in xl/workbook.xml unless told otherwise
		$bookPth = 'xl/workbook.xml';
		foreach($this->relsOfPrt('') as $relSet){
			if( substr($relSet['types'], -15)=='/officeDocument' ){
				$bookPth = $relSet['paths'];
			}
		}
		$bookArr = $this->partNodes($bookPth);
		if( $bookArr===null ){
			throw new ParseError('Invalid archive: part '.$bookPth.' is empty');
		}
		$bookRel = $this->relsOfPrt($bookPth);
		$this->textList = Array();
		$this->dateList = Array();
		$this->padCount = 0;
		$this->from1904 = in_array(strtolower(strval($this->findChild($bookArr, Array('workbookPr'))['attrs']['date1904'] ?? '')), Array('1', 'true'));
		foreach($bookRel as $relSet){
			if( substr($relSet['types'], -14)=='/sharedStrings' ){
				$this->sharedTxt($relSet['paths']);
			}elseif( substr($relSet['types'], -7)=='/styles' ){
				$this->dateStyls($relSet['paths']);
			}
		}
		$nameLst = Array();
		$pathLst = Array();
		$shwnLst = Array();
		foreach($this->kidsNamed($this->findChild($bookArr, Array('sheets')) ?? Array('nodes'=>Array()), 'sheet') as $shtArr){
			$shtRelt = '';
			foreach($shtArr['attrs'] as $atrNme=>$atrVal){
				// The id of the relation, whatever prefix the workbook gives it
				if( $atrNme=='r:id' || substr($atrNme, -3)==':id' ){
					$shtRelt = $atrVal;
				}
			}
			$nameLst[] = strval($shtArr['attrs']['name'] ?? '');
			$pathLst[] = strval($bookRel[$shtRelt]['paths'] ?? '');
			// A sheet is hidden, or hidden so that only a program can show it again
			$shwnLst[] = !in_array(strtolower(strval($shtArr['attrs']['state'] ?? '')), Array('hidden', 'veryhidden'));
		}
		$shetLst = Array();
		$headSet = Array();
		foreach($this->pickSheet($nameLst, $shwnLst, $parseOptns) as $shtIdx){
			$mergLst = Array();
			$headLst = Array();
			$rowsLst = $this->sheetRows($pathLst[$shtIdx], $mergLst);
			$shetLst[] = $this->doneSheet($nameLst[$shtIdx], $rowsLst, Array('shown'=>$shwnLst[$shtIdx], 'merge'=>$mergLst), $parseOptns, $headLst);
			if( !empty($parseOptns['heads']) ){
				$headSet[$nameLst[$shtIdx]] = $headLst;
			}
		}
		$this->textList = Array();
		return $this->makeShape('sheet', $shetLst, Array('names'=>$nameLst, 'count'=>count($nameLst), 'heads'=>$headSet));
	}

	/**
	 * Reads the texts shared by the cells of the workbook.
	 *
	 * @param String $insideName Name of the part holding them.
	 */
	protected function sharedTxt(String $insideName): Void {
		foreach($this->kidsNamed($this->partNodes($insideName, false) ?? Array('nodes'=>Array()), 'si') as $txtArr){
			$this->textList[] = $this->richTexts($txtArr);
		}
	}

	/**
	 * Gives the text of a cell written as rich text: its pieces, without
	 * the reading aids some languages add to them.
	 *
	 * @param Array $nodeValues The element holding the text.
	 * @return String The text.
	 */
	protected function richTexts(Array $nodeValues): String {
		$fullTxt = '';
		foreach($nodeValues['nodes'] as $chlArr){
			$chldNme = substr($chlArr['tagnm'], intval(strrpos(':'.$chlArr['tagnm'], ':')));
			if( $chldNme=='t' ){
				$fullTxt .= $this->textOfTag($chlArr);
			}elseif( $chldNme=='r' ){
				$fullTxt .= $this->richTexts($chlArr);
			}
		}
		return $fullTxt;
	}

	/**
	 * Finds the cell styles that show a date or a time: in a workbook, a
	 * date is a number, and only its style says it is one.
	 *
	 * @param String $insideName Name of the part holding the styles.
	 */
	protected function dateStyls(String $insideName): Void {
		$stylArr = $this->partNodes($insideName, false);
		if( $stylArr===null ){
			return;
		}
		// The formats the workbook defines itself: a date when they hold a letter of date or time
		$ownFmts = Array();
		foreach($this->kidsNamed($this->findChild($stylArr, Array('numFmts')) ?? Array('nodes'=>Array()), 'numFmt') as $fmtArr){
			$bareFmt = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', strval($fmtArr['attrs']['formatCode'] ?? ''));
			$ownFmts[intval($fmtArr['attrs']['numFmtId'] ?? -1)] = (stripos($bareFmt, 'General')===false && preg_match('/[dmyhs]/i', $bareFmt)===1);
		}
		foreach($this->kidsNamed($this->findChild($stylArr, Array('cellXfs')) ?? Array('nodes'=>Array()), 'xf') as $xfIndx=>$xfsArr){
			$fmtIdnt = intval($xfsArr['attrs']['numFmtId'] ?? 0);
			$this->dateList[$xfIndx] = ($ownFmts[$fmtIdnt] ?? in_array($fmtIdnt, self::DATEFMTS));
		}
	}

	/**
	 * Gives the number of a column from the place of a cell.
	 *
	 * @param String $cellsPlace The place, as 'B4' or 'AA10'.
	 * @return Array The column and the row, both starting from 0; -1 for what the place does not tell.
	 */
	protected function placeOfIt(String $cellsPlace): Array {
		$plcePrt = Array();
		if( !preg_match('/^\$?([A-Za-z]{1,3})\$?(\d{1,7})$/D', $cellsPlace, $plcePrt) ){
			return Array(-1, -1);
		}
		$colNmbr = 0;
		foreach(str_split(strtoupper($plcePrt[1])) as $oneLtr){
			$colNmbr = $colNmbr*26+(ord($oneLtr)-64);
		}
		return Array($colNmbr-1, intval($plcePrt[2])-1);
	}

	/**
	 * Gives a date kept as a number of days as ISO text: a date, a date
	 * and a time, or a time alone when the number is under one day.
	 *
	 * @param Float $serialDays The number of days, as the workbook keeps it.
	 * @return String The date, as '2026-12-28', '2026-12-28 20:30:00' or '20:30:00'.
	 */
	protected function serialIso(Float $serialDays): String {
		$secsNbr = intval(round(($serialDays-floor($serialDays))*86400));
		$daysNbr = intval(floor($serialDays))+($this->from1904 ? 1462 : 0);
		if( $secsNbr>=86400 ){
			$secsNbr = 0;
			$daysNbr++;
		}
		$timeTxt = sprintf('%02d:%02d:%02d', intdiv($secsNbr, 3600), intdiv($secsNbr%3600, 60), $secsNbr%60);
		if( $serialDays<1 && $serialDays>=0 ){
			return $timeTxt;
		}
		// Day 25569 is the first of January 1970
		$dateTxt = gmdate('Y-m-d', ($daysNbr-25569)*86400);
		return ($secsNbr==0) ? $dateTxt : $dateTxt.' '.$timeTxt;
	}

	/**
	 * Gives the value of a cell.
	 *
	 * @param Array $nodeValues The element of the cell.
	 * @return String|Int|Float|Bool|Null The value; null for an empty cell.
	 */
	protected function cellValue(Array $nodeValues): String|Int|Float|Bool|Null {
		$cellTyp = strval($nodeValues['attrs']['t'] ?? 'n');
		if( $cellTyp=='inlineStr' ){
			$inlnArr = $this->findChild($nodeValues, Array('is'));
			return ($inlnArr===null) ? null : $this->richTexts($inlnArr);
		}
		$valuArr = $this->findChild($nodeValues, Array('v'));
		if( $valuArr===null ){
			return null;
		}
		$rawValu = $this->textOfTag($valuArr);
		if( $cellTyp=='s' ){
			return $this->textList[intval($rawValu)] ?? '';
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
		if( !empty($this->dateList[intval($nodeValues['attrs']['s'] ?? 0)]) ){
			return $this->serialIso(floatval($rawValu));
		}
		return preg_match('/^-?\d{1,18}$/D', $rawValu) ? intval($rawValu) : floatval($rawValu);
	}

	/**
	 * Reads the rows of a worksheet. Every cell stands at its place: the
	 * rows and the cells the workbook leaves out are given empty, up to
	 * the last one written.
	 *
	 * @param String $insideName Name of the part holding the worksheet.
	 * @param Array  $mergeAreas Receives the areas of merged cells of the worksheet.
	 * @return Array The rows, each a list of values.
	 *
	 * @throws ParseError When the worksheet is missing, or too sparse to be laid out.
	 */
	protected function sheetRows(String $insideName, Array &$mergeAreas): Array {
		$shetArr = $this->partNodes($insideName);
		$rowsLst = Array();
		$mergeAreas = Array();
		foreach($this->kidsNamed($this->findChild($shetArr ?? Array('nodes'=>Array()), Array('mergeCells')) ?? Array('nodes'=>Array()), 'mergeCell') as $mrgArr){
			$areaPrt = explode(':', strval($mrgArr['attrs']['ref'] ?? '')) + Array('', '');
			$frstPlc = $this->placeOfIt($areaPrt[0]);
			$lastPlc = $this->placeOfIt($areaPrt[1]);
			if( $frstPlc[0]>=0 && $lastPlc[0]>=$frstPlc[0] && $lastPlc[1]>=$frstPlc[1] ){
				$mergeAreas[] = Array('atrow'=>$frstPlc[1], 'atcol'=>$frstPlc[0], 'rowsp'=>$lastPlc[1]-$frstPlc[1]+1, 'colsp'=>$lastPlc[0]-$frstPlc[0]+1);
			}
		}
		foreach($this->kidsNamed($this->findChild($shetArr ?? Array('nodes'=>Array()), Array('sheetData')) ?? Array('nodes'=>Array()), 'row') as $rowArr){
			// A row tells its number, or follows the one before it
			$rowIndx = preg_match('/^[1-9]\d{0,6}$/D', strval($rowArr['attrs']['r'] ?? '')) ? intval($rowArr['attrs']['r'])-1 : count($rowsLst);
			$cellLst = Array();
			foreach($this->kidsNamed($rowArr, 'c') as $celArr){
				$colIndx = $this->placeOfIt(strval($celArr['attrs']['r'] ?? ''))[0];
				if( $colIndx<count($cellLst) ){
					$colIndx = count($cellLst);
				}
				$this->padCount += $colIndx-count($cellLst);
				if( $this->padCount>self::PADLIMIT ){
					throw new ParseError('Invalid workbook: a sheet too sparse to be laid out');
				}
				$cellLst = array_pad($cellLst, $colIndx, null);
				$cellLst[] = $this->cellValue($celArr);
			}
			// The cells left empty at the end of a row are not part of it
			while( !empty($cellLst) && end($cellLst)===null ){
				array_pop($cellLst);
			}
			if( $rowIndx<count($rowsLst) ){
				$rowIndx = count($rowsLst);
			}
			$this->padCount += $rowIndx-count($rowsLst);
			if( $this->padCount>self::PADLIMIT ){
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
