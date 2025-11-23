<?php

namespace nyu\Data\Writers;

use nyu\Data\Archv;

/**
 * OpenDocument spreadsheets (ods) written from sheets: every sheet of
 * the content becomes a table, its cells at the place they have. Numbers
 * are written as numbers, true and false as such, everything else as
 * text: a text is never written as a formula, whatever it starts with.
 */
class Odsx extends Grid {

	// Greatest number of cells a merged area may cover to be written
	const MERGEMAX = 100000;
	// The kind of file, as its first entry tells it
	const FILEKIND = 'application/vnd.oasis.opendocument.spreadsheet';
	// The addresses of the format, as every part declares them
	const NAMELIST = ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0" xmlns:ooo="http://openoffice.org/2004/office" office:version="1.2"';

	/**
	 * Writes sheets as a spreadsheet.
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
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'; 'infos' may give the title of the document under 'title' and its author under 'authr'.
	 * @param Array $writeOptns Options: 'heads', 'dates'.
	 * @return String The bytes of the spreadsheet.
	 *
	 * @throws WriteError When the shape is not 'sheet', or a sheet is larger than a sheet can be.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$this->checkShpe($shapedData, Array('sheet'), 'a spreadsheet');
		$withHds = !isset($writeOptns['heads']) || !empty($writeOptns['heads']);
		$asDates = !empty($writeOptns['dates']);
		$shetLst = array_values((array)($shapedData['items'] ?? Array()));
		// A spreadsheet holds one sheet at least
		if( empty($shetLst) ){
			$shetLst[] = Array('title'=>'Sheet1', 'attrs'=>Array(), 'cells'=>Array());
		}
		$shwnLst = $this->shownList($shetLst);
		$nameLst = Array();
		$bodyTxt = '';
		$wideTxt = '';
		$viewTxt = '';
		foreach($shetLst as $shtIdx=>$shtArr){
			$shtName = $this->sheetName(strval($shtArr['title'] ?? ''), $shtIdx+1, $nameLst);
			$nameLst[] = $shtName;
			$hasHead = false;
			$bodyTxt .= '<table:table table:name="'.$this->escapeTxt($shtName).'" table:style-name="'.($shwnLst[$shtIdx] ? 'ta1' : 'ta2').'">'.$this->sheetPart((array)$shtArr, $withHds, $asDates, 's'.($shtIdx+1), $wideTxt, $hasHead).'</table:table>';
			// The row of names is kept in sight: the view of the sheet is split under it. The settings are only read when
			// the part declares the address their name starts with (ooo), which the addresses of the format hold
			if( $hasHead ){
				$viewTxt .= '<config:config-item-map-entry config:name="'.$this->escapeTxt($shtName).'"><config:config-item config:name="CursorPositionX" config:type="int">0</config:config-item><config:config-item config:name="CursorPositionY" config:type="int">1</config:config-item><config:config-item config:name="HorizontalSplitMode" config:type="short">0</config:config-item><config:config-item config:name="VerticalSplitMode" config:type="short">2</config:config-item><config:config-item config:name="HorizontalSplitPosition" config:type="int">0</config:config-item>'
					.'<config:config-item config:name="VerticalSplitPosition" config:type="int">1</config:config-item><config:config-item config:name="ActiveSplitRange" config:type="short">2</config:config-item><config:config-item config:name="PositionLeft" config:type="int">0</config:config-item><config:config-item config:name="PositionRight" config:type="int">0</config:config-item><config:config-item config:name="PositionTop" config:type="int">0</config:config-item><config:config-item config:name="PositionBottom" config:type="int">1</config:config-item></config:config-item-map-entry>';
			}
		}
		$headXml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$infoArr = (array)($shapedData['infos'] ?? Array());
		$dateFmt = '<number:year number:style="long"/><number:text>-</number:text><number:month number:style="long"/><number:text>-</number:text><number:day number:style="long"/>';
		$fileLst = Array(
			'mimetype'=>self::FILEKIND,
			'META-INF/manifest.xml'=>$headXml.'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2"><manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="'.self::FILEKIND.'"/>'
				.'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
				.'<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="settings.xml" manifest:media-type="text/xml"/></manifest:manifest>',
			// The styles of the tables (shown, hidden), of the cells (names of the columns, a date, a date with its time) and of the columns (their widths)
			'content.xml'=>$headXml.'<office:document-content'.self::NAMELIST.'><office:automatic-styles>'
				.'<style:style style:name="ta1" style:family="table"><style:table-properties table:display="true"/></style:style><style:style style:name="ta2" style:family="table"><style:table-properties table:display="false"/></style:style>'
				.'<number:date-style style:name="N1">'.$dateFmt.'</number:date-style><number:date-style style:name="N2">'.$dateFmt.'<number:text> </number:text><number:hours number:style="long"/><number:text>:</number:text><number:minutes number:style="long"/><number:text>:</number:text><number:seconds number:style="long"/></number:date-style>'
				.'<style:style style:name="ce1" style:family="table-cell"><style:text-properties fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/></style:style>'
				.'<style:style style:name="ce2" style:family="table-cell" style:data-style-name="N1"/><style:style style:name="ce3" style:family="table-cell" style:data-style-name="N2"/>'.$wideTxt
				.'</office:automatic-styles><office:body><office:spreadsheet>'.$bodyTxt.'</office:spreadsheet></office:body></office:document-content>',
			'styles.xml'=>$headXml.'<office:document-styles'.self::NAMELIST.'><office:styles><style:default-style style:family="table-cell"><style:text-properties fo:font-size="10pt"/></style:default-style></office:styles></office:document-styles>',
			'meta.xml'=>$headXml.'<office:document-meta'.self::NAMELIST.'><office:meta><dc:title>'.$this->escapeTxt(is_scalar($infoArr['title'] ?? '') ? strval($infoArr['title'] ?? '') : '').'</dc:title>'
				.'<meta:initial-creator>'.$this->escapeTxt(is_scalar($infoArr['authr'] ?? '') ? strval($infoArr['authr'] ?? '') : '').'</meta:initial-creator><meta:creation-date>'.gmdate('Y-m-d\TH:i:s').'</meta:creation-date></office:meta></office:document-meta>',
			'settings.xml'=>$headXml.'<office:document-settings'.self::NAMELIST.'><office:settings><config:config-item-set config:name="ooo:view-settings"><config:config-item-map-indexed config:name="Views"><config:config-item-map-entry>'
				.'<config:config-item config:name="ViewId" config:type="string">view1</config:config-item>'.(($viewTxt==='') ? '' : '<config:config-item-map-named config:name="Tables">'.$viewTxt.'</config:config-item-map-named>')
				.'<config:config-item config:name="ActiveTable" config:type="string">'.$this->escapeTxt($nameLst[intval(array_search(true, $shwnLst, true))]).'</config:config-item>'
				.'</config:config-item-map-entry></config:config-item-map-indexed></config:config-item-set></office:settings></office:document-settings>'
		);
		try{
			// The first entry tells what the file is, and is kept as it is
			return Archv::makeBytes($fileLst, Array('mimetype'));
		}catch( \RuntimeException $e ){
			throw new WriteError('Spreadsheet that cannot be written: '.$e->getMessage(), 0, $e);
		}
	}

	/**
	 * Writes the columns and the rows of a table.
	 *
	 * @param Array  $sheetArray The sheet: 'title', 'attrs', 'cells'.
	 * @param Bool   $writeHeads Whether the names of the columns are written as the first row.
	 * @param Bool   $datesAsOne Whether the texts that are dates are written as dates.
	 * @param String $stylePrefx What the names of the styles of its columns start with.
	 * @param String $widthStyle Receives the styles of its columns, added to those already there.
	 * @param Bool   $hasHeadRow Receives whether the names of the columns were written.
	 * @return String What the table holds.
	 *
	 * @throws WriteError When the sheet is larger than a sheet can be.
	 */
	protected function sheetPart(Array $sheetArray, Bool $writeHeads, Bool $datesAsOne, String $stylePrefx, String &$widthStyle, Bool &$hasHeadRow): String {
		$gridSet = $this->sheetGrid($sheetArray, $writeHeads);
		$hasHeadRow = !empty($gridSet['heads']);
		$rowsLst = $hasHeadRow ? array_merge(Array($gridSet['heads']), $gridSet['cells']) : $gridSet['cells'];
		// The merged areas: where each starts, and the cells each covers
		$spanLst = Array();
		$covrLst = Array();
		foreach((array)($sheetArray['attrs']['merge'] ?? Array()) as $mrgArr){
			$mrgArr = array_map('intval', (array)$mrgArr) + Array('atrow'=>-1, 'atcol'=>-1, 'rowsp'=>1, 'colsp'=>1);
			// An area of one cell is no merge; one leaving the sheet, or covering more than is reasonable, is not written
			if( $mrgArr['atrow']<0 || $mrgArr['atcol']<0 || $mrgArr['rowsp']<1 || $mrgArr['colsp']<1 || ($mrgArr['rowsp']==1 && $mrgArr['colsp']==1) || $mrgArr['atrow']+$mrgArr['rowsp']>self::ROWLIMIT || $mrgArr['atcol']+$mrgArr['colsp']>self::COLLIMIT || $mrgArr['rowsp']*$mrgArr['colsp']>self::MERGEMAX || isset($covrLst[$mrgArr['atrow'].':'.$mrgArr['atcol']]) ){
				continue;
			}
			$spanLst[$mrgArr['atrow'].':'.$mrgArr['atcol']] = Array($mrgArr['rowsp'], $mrgArr['colsp']);
			for($rowIdx=$mrgArr['atrow']; $rowIdx<$mrgArr['atrow']+$mrgArr['rowsp']; $rowIdx++){
				// The rows and the cells an area covers are written, even when they hold nothing
				$rowsLst = array_pad($rowsLst, $rowIdx+1, Array());
				$rowsLst[$rowIdx] = array_pad($rowsLst[$rowIdx], $mrgArr['atcol']+$mrgArr['colsp'], null);
				for($colIdx=$mrgArr['atcol']; $colIdx<$mrgArr['atcol']+$mrgArr['colsp']; $colIdx++){
					$covrLst[$rowIdx.':'.$colIdx] = true;
				}
			}
			unset($covrLst[$mrgArr['atrow'].':'.$mrgArr['atcol']]);
		}
		$dataTxt = '';
		$wideLst = Array();
		$voidNbr = 0;
		foreach($rowsLst as $rowIdx=>$oneRow){
			$rowText = '';
			$gapsNbr = 0;
			foreach($oneRow as $colIdx=>$celVal){
				$placeKy = $rowIdx.':'.$colIdx;
				$celText = isset($covrLst[$placeKy]) ? '<table:covered-table-cell/>' : $this->cellsPart($celVal, $datesAsOne, ($hasHeadRow && $rowIdx==0), $spanLst[$placeKy] ?? Array(1, 1));
				// Cells holding nothing are counted, and written as one when a cell follows them
				if( $celText==='' ){
					$gapsNbr++;
					continue;
				}
				$rowText .= (($gapsNbr>0) ? '<table:table-cell'.(($gapsNbr>1) ? ' table:number-columns-repeated="'.$gapsNbr.'"' : '').'/>' : '').$celText;
				$gapsNbr = 0;
				$wideLst[$colIdx] = max($wideLst[$colIdx] ?? 0, is_scalar($celVal) ? strlen(strval($celVal)) : 0);
			}
			// So are the rows holding nothing
			if( $rowText==='' ){
				$voidNbr++;
				continue;
			}
			$dataTxt .= (($voidNbr>0) ? '<table:table-row'.(($voidNbr>1) ? ' table:number-rows-repeated="'.$voidNbr.'"' : '').'><table:table-cell/></table:table-row>' : '').'<table:table-row>'.$rowText.'</table:table-row>';
			$voidNbr = 0;
		}
		// The columns are as wide as what they hold, within reason
		$colsTxt = '';
		$colsNbr = empty($wideLst) ? 0 : max(array_keys($wideLst))+1;
		for($colIdx=0; $colIdx<$colsNbr; $colIdx++){
			$widthStyle .= '<style:style style:name="'.$stylePrefx.'c'.($colIdx+1).'" style:family="table-column"><style:table-column-properties style:column-width="'.sprintf('%.2F', max(8, min(60, ($wideLst[$colIdx] ?? 0)+2))*0.21).'cm"/></style:style>';
			$colsTxt .= '<table:table-column table:style-name="'.$stylePrefx.'c'.($colIdx+1).'"/>';
		}
		// A table holds one column and one row at least
		return (($colsTxt==='') ? '<table:table-column/>' : $colsTxt).(($dataTxt==='') ? '<table:table-row><table:table-cell/></table:table-row>' : $dataTxt);
	}

	/**
	 * Writes a cell with the type of its value: boolean, number, date or text.
	 *
	 * @param mixed $valueToPut What the cell holds.
	 * @param Bool  $datesAsOne Whether a text that is a date is written as a date.
	 * @param Bool  $isHeadCell Whether the cell is the name of a column.
	 * @param Array $spansOfOne The rows and the columns the cell covers: 1 and 1 for a cell alone.
	 * @return String The cell; '' for a cell holding nothing and covering no other.
	 */
	protected function cellsPart(Mixed $valueToPut, Bool $datesAsOne, Bool $isHeadCell, Array $spansOfOne): String {
		$spanTxt = (($spansOfOne[1]>1 || $spansOfOne[0]>1) ? ' table:number-columns-spanned="'.$spansOfOne[1].'" table:number-rows-spanned="'.$spansOfOne[0].'"' : '');
		$valueToPut = $this->plainCell($valueToPut);
		if( $valueToPut===null ){
			return ($spanTxt==='') ? '' : '<table:table-cell'.$spanTxt.'/>';
		}
		$openTxt = '<table:table-cell'.($isHeadCell ? ' table:style-name="ce1"' : '').$spanTxt;
		if( is_bool($valueToPut) ){
			return $openTxt.' office:value-type="boolean" office:boolean-value="'.($valueToPut ? 'true' : 'false').'"><text:p>'.($valueToPut ? 'TRUE' : 'FALSE').'</text:p></table:table-cell>';
		}
		if( is_int($valueToPut) || is_float($valueToPut) ){
			return $openTxt.' office:value-type="float" office:value="'.$this->numbrText($valueToPut).'"><text:p>'.$this->numbrText($valueToPut).'</text:p></table:table-cell>';
		}
		// A date is written as it is told, with the style that shows it so
		$datePrt = ($datesAsOne && !$isHeadCell) ? $this->dateParts($valueToPut) : null;
		if( $datePrt!==null ){
			$dateTxt = sprintf('%04d-%02d-%02d', $datePrt[0], $datePrt[1], $datePrt[2]);
			$timeTxt = isset($datePrt[3]) ? sprintf('%02d:%02d:%02d', $datePrt[3], $datePrt[4], $datePrt[5]) : '';
			return '<table:table-cell table:style-name="'.(($timeTxt==='') ? 'ce2' : 'ce3').'"'.$spanTxt.' office:value-type="date" office:date-value="'.$dateTxt.(($timeTxt==='') ? '' : 'T'.$timeTxt).'"><text:p>'.$dateTxt.(($timeTxt==='') ? '' : ' '.$timeTxt).'</text:p></table:table-cell>';
		}
		// A text is written as text, a paragraph for each of its lines: nothing in it is ever run
		$paraTxt = '';
		foreach(explode("\n", str_replace(Array("\r\n", "\r"), "\n", $valueToPut)) as $oneLne){
			$paraTxt .= '<text:p>'.$this->textsPart($oneLne).'</text:p>';
		}
		return $openTxt.' office:value-type="string">'.(($valueToPut==='') ? '' : $paraTxt).'</table:table-cell>';
	}

	/**
	 * Writes a line of text. A document of this kind takes several spaces
	 * for one, and those at the ends of a line for none: they are written
	 * as what they are, and so are the tabs.
	 *
	 * @param String $plainTexts The line.
	 * @return String The line, ready to stand in a paragraph.
	 */
	protected function textsPart(String $plainTexts): String {
		if( !preg_match('/\t|^ | $| {2}/', $plainTexts) ){
			return $this->escapeTxt($plainTexts);
		}
		$lineTxt = '';
		foreach(preg_split('/(\t|^ +| +$| {2,})/', $plainTexts, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $onePrt){
			if( $onePrt==="\t" ){
				$lineTxt .= '<text:tab/>';
			}elseif( trim($onePrt, ' ')==='' ){
				$lineTxt .= '<text:s'.((strlen($onePrt)>1) ? ' text:c="'.strlen($onePrt).'"' : '').'/>';
			}else{
				$lineTxt .= '<text:span>'.$this->escapeTxt($onePrt).'</text:span>';
			}
		}
		return $lineTxt;
	}

}
