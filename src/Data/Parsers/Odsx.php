<?php

namespace nyu\Data\Parsers;

/**
 * OpenDocument spreadsheets (ods): one sheet per table, its cells at the
 * place they have in the document. Numbers are given as numbers, booleans
 * as booleans, dates and times as ISO text, empty cells as null, and a
 * formula as the result the document stored for it.
 */
class Odsx extends Pack {

	const SHAPEOUT = 'sheet';
	// Greatest number of cells added by the rows and the cells written once for many
	const PADLIMIT = 1000000;

	// Cells added so far
	protected $padCount = 0;

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
	 * @param Array $parseOptns Options: 'sheet', 'heads', 'shown', 'merge'.
	 * @return Array The envelope, of shape 'sheet'; 'infos' holds the names of all the sheets of the document under 'names', their number under 'count' and, with the 'heads' option, the names of the columns of each sheet read under 'heads'.
	 *
	 * @throws ParseError When the archive is not a valid spreadsheet, is protected by a password, the sheet asked for is not in it, or a sheet is too sparse to be laid out.
	 */
	protected function readArchv(Array $parseOptns): Array {
		$this->checkLock();
		$docuArr = $this->partNodes('content.xml', true, true);
		$bookArr = $this->findChild($docuArr ?? Array('nodes'=>Array()), Array('office:body', 'office:spreadsheet'));
		if( $bookArr===null ){
			throw new ParseError('Invalid archive: the document is not a spreadsheet');
		}
		// A sheet is hidden by its style
		$hideLst = Array();
		foreach($this->kidsNamed($this->findChild($docuArr, Array('office:automatic-styles')) ?? Array('nodes'=>Array()), 'style:style') as $stlArr){
			if( strval($this->findChild($stlArr, Array('style:table-properties'))['attrs']['table:display'] ?? '')=='false' ){
				$hideLst[] = strval($stlArr['attrs']['style:name'] ?? '');
			}
		}
		$tblsLst = $this->kidsNamed($bookArr, 'table:table');
		$nameLst = Array();
		$shwnLst = Array();
		foreach($tblsLst as $tblArr){
			$nameLst[] = strval($tblArr['attrs']['table:name'] ?? '');
			$shwnLst[] = !in_array(strval($tblArr['attrs']['table:style-name'] ?? ''), $hideLst, true);
		}
		$this->padCount = 0;
		$shetLst = Array();
		$headSet = Array();
		foreach($this->pickSheet($nameLst, $shwnLst, $parseOptns) as $shtIdx){
			$mergLst = Array();
			$headLst = Array();
			$rowsLst = $this->sheetRows($tblsLst[$shtIdx], $mergLst);
			$shetLst[] = $this->doneSheet($nameLst[$shtIdx], $rowsLst, Array('shown'=>$shwnLst[$shtIdx], 'merge'=>$mergLst), $parseOptns, $headLst);
			if( !empty($parseOptns['heads']) ){
				$headSet[$nameLst[$shtIdx]] = $headLst;
			}
		}
		return $this->makeShape('sheet', $shetLst, Array('names'=>$nameLst, 'count'=>count($nameLst), 'heads'=>$headSet));
	}

	/**
	 * Counts the cells added to a sheet, and refuses the sheet when they
	 * are too many.
	 *
	 * @param Int $addedCells Number of cells being added.
	 *
	 * @throws ParseError When the sheet is too sparse to be laid out.
	 */
	protected function countPads(Int $addedCells): Void {
		$this->padCount += max(0, $addedCells);
		if( $this->padCount>self::PADLIMIT ){
			throw new ParseError('Invalid spreadsheet: a sheet too sparse to be laid out');
		}
	}

	/**
	 * Gives the rows of a table, whatever groups they are written in.
	 *
	 * @param Array $nodeValues The table, or a group of its rows.
	 * @return Array The elements of the rows, in order.
	 */
	protected function rowsOfTbl(Array $nodeValues): Array {
		$rowsLst = Array();
		foreach($nodeValues['nodes'] as $chlArr){
			if( $chlArr['tagnm']=='table:table-row' ){
				$rowsLst[] = $chlArr;
			}elseif( in_array($chlArr['tagnm'], Array('table:table-header-rows', 'table:table-rows', 'table:table-row-group')) ){
				$rowsLst = array_merge($rowsLst, $this->rowsOfTbl($chlArr));
			}
		}
		return $rowsLst;
	}

	/**
	 * Reads the rows of a table. A row or a cell written once for many
	 * is given as many times; the empty ones ending a row or a sheet,
	 * which a spreadsheet writes by the thousand, are left out.
	 *
	 * @param Array $nodeValues The element of the table.
	 * @param Array $mergeAreas Receives the areas of merged cells of the table.
	 * @return Array The rows, each a list of values.
	 *
	 * @throws ParseError When the table is too sparse to be laid out.
	 */
	protected function sheetRows(Array $nodeValues, Array &$mergeAreas): Array {
		$rowsLst = Array();
		$mergeAreas = Array();
		// Empty rows met, not yet known to be followed by a row holding something
		$voidRws = 0;
		foreach($this->rowsOfTbl($nodeValues) as $rowArr){
			$rowRept = max(1, intval($rowArr['attrs']['table:number-rows-repeated'] ?? 1));
			$cellLst = Array();
			$voidCls = 0;
			foreach($rowArr['nodes'] as $celArr){
				if( $celArr['tagnm']!='table:table-cell' && $celArr['tagnm']!='table:covered-table-cell' ){
					continue;
				}
				$celRept = max(1, intval($celArr['attrs']['table:number-columns-repeated'] ?? 1));
				$colSpan = intval($celArr['attrs']['table:number-columns-spanned'] ?? 1);
				$rowSpan = intval($celArr['attrs']['table:number-rows-spanned'] ?? 1);
				if( $celArr['tagnm']=='table:table-cell' && ($colSpan>1 || $rowSpan>1) ){
					$mergeAreas[] = Array('atrow'=>count($rowsLst)+$voidRws, 'atcol'=>count($cellLst)+$voidCls, 'rowsp'=>max(1, $rowSpan), 'colsp'=>max(1, $colSpan));
				}
				// A cell covered by a merged area holds nothing
				$celValu = ($celArr['tagnm']=='table:table-cell') ? $this->cellValue($celArr) : null;
				if( $celValu===null ){
					$voidCls += $celRept;
					continue;
				}
				$this->countPads($voidCls+$celRept-1);
				$cellLst = array_pad($cellLst, count($cellLst)+$voidCls, null);
				$voidCls = 0;
				for($rptIdx=0; $rptIdx<$celRept; $rptIdx++){
					$cellLst[] = $celValu;
				}
			}
			if( empty($cellLst) ){
				$voidRws += $rowRept;
				continue;
			}
			$this->countPads($voidRws+($rowRept-1)*count($cellLst));
			$rowsLst = array_pad($rowsLst, count($rowsLst)+$voidRws, Array());
			$voidRws = 0;
			for($rptIdx=0; $rptIdx<$rowRept; $rptIdx++){
				$rowsLst[] = $cellLst;
			}
		}
		return $rowsLst;
	}

	/**
	 * Gives the value of a cell, from the type the document gives it.
	 *
	 * @param Array $nodeValues The element of the cell.
	 * @return String|Int|Float|Bool|Null The value; null for an empty cell.
	 */
	protected function cellValue(Array $nodeValues): String|Int|Float|Bool|Null {
		$attrLst = $nodeValues['attrs'];
		$cellTyp = strval($attrLst['office:value-type'] ?? '');
		$valPrts = Array();
		if( in_array($cellTyp, Array('float', 'percentage', 'currency')) ){
			$rawValu = strval($attrLst['office:value'] ?? '');
			if( !is_numeric($rawValu) ){
				return null;
			}
			return preg_match('/^-?\d{1,18}$/D', $rawValu) ? intval($rawValu) : floatval($rawValu);
		}
		if( $cellTyp=='boolean' ){
			return (strval($attrLst['office:boolean-value'] ?? '')=='true');
		}
		if( $cellTyp=='date' && preg_match('/^(-?\d{4,}-\d\d-\d\d)(?:T(\d\d:\d\d)(:\d\d)?)?/', strval($attrLst['office:date-value'] ?? ''), $valPrts) ){
			return isset($valPrts[2]) ? $valPrts[1].' '.$valPrts[2].($valPrts[3] ?? ':00') : $valPrts[1];
		}
		if( $cellTyp=='time' && preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:[.,]\d+)?S)?$/D', strval($attrLst['office:time-value'] ?? ''), $valPrts) ){
			return sprintf('%02d:%02d:%02d', intval($valPrts[1] ?? 0), intval($valPrts[2] ?? 0), intval($valPrts[3] ?? 0));
		}
		if( isset($attrLst['office:string-value']) ){
			return $attrLst['office:string-value'];
		}
		// A text: the paragraphs of the cell, one per line; the notes attached to it are not part of it
		$paraLst = Array();
		foreach($this->kidsNamed($nodeValues, 'text:p') as $parArr){
			$paraLst[] = $this->odfOfText($parArr);
		}
		if( empty($paraLst) ){
			return ($cellTyp=='string') ? '' : null;
		}
		return implode("\n", $paraLst);
	}

}
