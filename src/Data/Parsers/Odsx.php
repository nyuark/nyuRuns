<?php

namespace nyu\Data\Parsers;

/**
 * OpenDocument spreadsheets (ods): one sheet per table, its cells at the
 * place they have in the document. Numbers are given as numbers, booleans
 * as booleans, dates and times as ISO text, empty cells as null, and a
 * formula as the result the document stored for it.
 */
class Odsx extends Pack {

	const SHPE_OUTS = 'sheet';
	// Greatest number of cells added by the rows and the cells written once for many
	const PADS_MAXI = 1000000;

	// Cells added so far
	protected $addedCells = 0;

	/**
	 * Reads the spreadsheet of an opened archive.
	 *
	 * Options:
	 *   'sheet' => reads one sheet only: its name, or its position
	 *              starting from 1; every sheet when left out;
	 *   'heads' => takes the first row of each sheet as the names of its
	 *              columns: every other row is then keyed by them;
	 *   'shown' => leaves out the sheets the document hides;
	 *   'merge' => 'keeps' leaves the value of merged cells in the first
	 *              of them, 'fills' copies it into each; 'keeps' when
	 *              left out.
	 *
	 * @param Array $parseOptions Options: 'sheet', 'heads', 'shown', 'merge'.
	 * @return Array The envelope, of shape 'sheet'; 'infos' holds the names of all the sheets of the document under 'names', their number under 'count' and, with the 'heads' option, the names of the columns of each sheet read under 'heads'.
	 *
	 * @throws ParseError When the archive is not a valid spreadsheet, is protected by a password, the sheet asked for is not in it, or a sheet is too sparse to be laid out.
	 */
	protected function readArchvArray(Array $parseOptions): Array {
		$this->refuseIfLocked();
		$docuTree = $this->partNodesArray('content.xml', true, true);
		$bookList = $this->findChildArray($docuTree ?? Array('nodes'=>Array()), Array('office:body', 'office:spreadsheet'));
		if( $bookList===null ){
			throw new ParseError('Invalid archive: the document is not a spreadsheet');
		}
		// A sheet is hidden by its style
		$hideList = Array();
		foreach($this->kidsNamedArray($this->findChildArray($docuTree, Array('office:automatic-styles')) ?? Array('nodes'=>Array()), 'style:style') as $stlArr){
			if( strval($this->findChildArray($stlArr, Array('style:table-properties'))['attrs']['table:display'] ?? '')=='false' ){
				$hideList[] = strval($stlArr['attrs']['style:name'] ?? '');
			}
		}
		$tblsList = $this->kidsNamedArray($bookList, 'table:table');
		$nameList = Array();
		$shownLst = Array();
		foreach($tblsList as $tblArr){
			$nameList[] = strval($tblArr['attrs']['table:name'] ?? '');
			$shownLst[] = !in_array(strval($tblArr['attrs']['table:style-name'] ?? ''), $hideList, true);
		}
		$this->addedCells = 0;
		$sheetLst = Array();
		$headDict = Array();
		foreach($this->pickSheetArray($nameList, $shownLst, $parseOptions) as $shtIdx){
			$mergeLst = Array();
			$headList = Array();
			$rowsList = $this->sheetRowsArray($tblsList[$shtIdx], $mergeLst);
			$sheetLst[] = $this->doneSheetArray($nameList[$shtIdx], $rowsList, Array('shown'=>$shownLst[$shtIdx], 'merge'=>$mergeLst), $parseOptions, $headList);
			if( !empty($parseOptions['heads']) ){
				$headDict[$nameList[$shtIdx]] = $headList;
			}
		}
		return $this->makeShapeArray('sheet', $sheetLst, Array('names'=>$nameList, 'count'=>count($nameList), 'heads'=>$headDict));
	}

	/**
	 * Counts the cells added to a sheet, and refuses the sheet when they
	 * are too many.
	 *
	 * @param Int $addedCellCnt Number of cells being added.
	 *
	 * @throws ParseError When the sheet is too sparse to be laid out.
	 */
	protected function countFillCells(Int $addedCellCnt): Void {
		$this->addedCells += max(0, $addedCellCnt);
		if( $this->addedCells>self::PADS_MAXI ){
			throw new ParseError('Invalid spreadsheet: a sheet too sparse to be laid out');
		}
	}

	/**
	 * Gives the rows of a table, whatever groups they are written in.
	 *
	 * @param Array $elementArray The table, or a group of its rows.
	 * @return Array The elements of the rows, in order.
	 */
	protected function rowsOfTableArr(Array $elementArray): Array {
		$rowsList = Array();
		foreach($elementArray['nodes'] as $chlArr){
			if( $chlArr['tagnm']=='table:table-row' ){
				$rowsList[] = $chlArr;
			}elseif( in_array($chlArr['tagnm'], Array('table:table-header-rows', 'table:table-rows', 'table:table-row-group')) ){
				$rowsList = array_merge($rowsList, $this->rowsOfTableArr($chlArr));
			}
		}
		return $rowsList;
	}

	/**
	 * Reads the rows of a table. A row or a cell written once for many
	 * is given as many times; the empty ones ending a row or a sheet,
	 * which a spreadsheet writes by the thousand, are left out.
	 *
	 * @param Array $elementArray The element of the table.
	 * @param Array $mergedRanges Receives the areas of merged cells of the table.
	 * @return Array The rows, each a list of values.
	 *
	 * @throws ParseError When the table is too sparse to be laid out.
	 */
	protected function sheetRowsArray(Array $elementArray, Array &$mergedRanges): Array {
		$rowsList = Array();
		$mergedRanges = Array();
		// Empty rows met, not yet known to be followed by a row holding something
		$voidRows = 0;
		foreach($this->rowsOfTableArr($elementArray) as $rowArr){
			$rpetNumb = max(1, intval($rowArr['attrs']['table:number-rows-repeated'] ?? 1));
			$cellList = Array();
			$voidClss = 0;
			foreach($rowArr['nodes'] as $celArr){
				if( $celArr['tagnm']!='table:table-cell' && $celArr['tagnm']!='table:covered-table-cell' ){
					continue;
				}
				$cellRpet = max(1, intval($celArr['attrs']['table:number-columns-repeated'] ?? 1));
				$clmnSpan = intval($celArr['attrs']['table:number-columns-spanned'] ?? 1);
				$rowRange = intval($celArr['attrs']['table:number-rows-spanned'] ?? 1);
				if( $celArr['tagnm']=='table:table-cell' && ($clmnSpan>1 || $rowRange>1) ){
					$mergedRanges[] = Array('atrow'=>count($rowsList)+$voidRows, 'atcol'=>count($cellList)+$voidClss, 'rowsp'=>max(1, $rowRange), 'colsp'=>max(1, $clmnSpan));
				}
				// A cell covered by a merged area holds nothing
				$cellVals = ($celArr['tagnm']=='table:table-cell') ? $this->typedCellValue($celArr) : null;
				if( $cellVals===null ){
					$voidClss += $cellRpet;
					continue;
				}
				$this->countFillCells($voidClss+$cellRpet-1);
				$cellList = array_pad($cellList, count($cellList)+$voidClss, null);
				$voidClss = 0;
				for($rptIdx=0; $rptIdx<$cellRpet; $rptIdx++){
					$cellList[] = $cellVals;
				}
			}
			if( empty($cellList) ){
				$voidRows += $rpetNumb;
				continue;
			}
			$this->countFillCells($voidRows+($rpetNumb-1)*count($cellList));
			$rowsList = array_pad($rowsList, count($rowsList)+$voidRows, Array());
			$voidRows = 0;
			for($rptIdx=0; $rptIdx<$rpetNumb; $rptIdx++){
				$rowsList[] = $cellList;
			}
		}
		return $rowsList;
	}

	/**
	 * Gives the value of a cell, from the type the document gives it.
	 *
	 * @param Array $elementArray The element of the cell.
	 * @return String|Int|Float|Bool|Null The value; null for an empty cell.
	 */
	protected function typedCellValue(Array $elementArray): String|Int|Float|Bool|Null {
		$attrDict = $elementArray['attrs'];
		$cellCode = strval($attrDict['office:value-type'] ?? '');
		$valuePar = Array();
		if( in_array($cellCode, Array('float', 'percentage', 'currency')) ){
			$rawValue = strval($attrDict['office:value'] ?? '');
			if( !is_numeric($rawValue) ){
				return null;
			}
			return preg_match('/^-?\d{1,18}$/D', $rawValue) ? intval($rawValue) : floatval($rawValue);
		}
		if( $cellCode=='boolean' ){
			return (strval($attrDict['office:boolean-value'] ?? '')=='true');
		}
		if( $cellCode=='date' && preg_match('/^(-?\d{4,}-\d\d-\d\d)(?:T(\d\d:\d\d)(:\d\d)?)?/', strval($attrDict['office:date-value'] ?? ''), $valuePar) ){
			return isset($valuePar[2]) ? $valuePar[1].' '.$valuePar[2].($valuePar[3] ?? ':00') : $valuePar[1];
		}
		if( $cellCode=='time' && preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:[.,]\d+)?S)?$/D', strval($attrDict['office:time-value'] ?? ''), $valuePar) ){
			return sprintf('%02d:%02d:%02d', intval($valuePar[1] ?? 0), intval($valuePar[2] ?? 0), intval($valuePar[3] ?? 0));
		}
		if( isset($attrDict['office:string-value']) ){
			return $attrDict['office:string-value'];
		}
		// A text: the paragraphs of the cell, one per line; the notes attached to it are not part of it
		$prgrList = Array();
		foreach($this->kidsNamedArray($elementArray, 'text:p') as $parArr){
			$prgrList[] = $this->odfPieceAsText($parArr);
		}
		if( empty($prgrList) ){
			return ($cellCode=='string') ? '' : null;
		}
		return implode("\n", $prgrList);
	}

}
