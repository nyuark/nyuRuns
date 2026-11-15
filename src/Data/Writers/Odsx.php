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
	const MERG_MAXI = 100000;
	// The kind of file, as its first entry tells it
	const FILE_KIND = 'application/vnd.oasis.opendocument.spreadsheet';
	// The addresses of the format, as every part declares them
	const NAME_LIST = ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0" xmlns:ooo="http://openoffice.org/2004/office" office:version="1.2"';

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
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'; 'infos' may give the title of the document under 'title' and its author under 'authr'.
	 * @param Array $writeOptions Options: 'heads', 'dates'.
	 * @return String The bytes of the spreadsheet.
	 *
	 * @throws WriteError When the shape is not 'sheet', or a sheet is larger than a sheet can be.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$this->checkShapeText($dataEnvelope, Array('sheet'), 'a spreadsheet');
		$withHdrs = !isset($writeOptions['heads']) || !empty($writeOptions['heads']);
		$hasDates = !empty($writeOptions['dates']);
		$sheetLst = array_values((array)($dataEnvelope['items'] ?? Array()));
		// A spreadsheet holds one sheet at least
		if( empty($sheetLst) ){
			$sheetLst[] = Array('title'=>'Sheet1', 'attrs'=>Array(), 'cells'=>Array());
		}
		$shownLst = $this->listShownSheet($sheetLst);
		$nameList = Array();
		$bodyText = '';
		$wideText = '';
		$viewText = '';
		foreach($sheetLst as $shtIdx=>$shtArr){
			$sheetNme = $this->cleanSheetName(strval($shtArr['title'] ?? ''), $shtIdx+1, $nameList);
			$nameList[] = $sheetNme;
			$hasHeadr = false;
			$bodyText .= '<table:table table:name="'.$this->escapeXmlValue($sheetNme).'" table:style-name="'.($shownLst[$shtIdx] ? 'ta1' : 'ta2').'">'.$this->sheetPieceText((array)$shtArr, $withHdrs, $hasDates, 's'.($shtIdx+1), $wideText, $hasHeadr).'</table:table>';
			// The row of names is kept in sight: the view of the sheet is split under it. The settings are only read when
			// the part declares the address their name starts with (ooo), which the addresses of the format hold
			if( $hasHeadr ){
				$viewText .= '<config:config-item-map-entry config:name="'.$this->escapeXmlValue($sheetNme).'"><config:config-item config:name="CursorPositionX" config:type="int">0</config:config-item><config:config-item config:name="CursorPositionY" config:type="int">1</config:config-item><config:config-item config:name="HorizontalSplitMode" config:type="short">0</config:config-item><config:config-item config:name="VerticalSplitMode" config:type="short">2</config:config-item><config:config-item config:name="HorizontalSplitPosition" config:type="int">0</config:config-item>'
					.'<config:config-item config:name="VerticalSplitPosition" config:type="int">1</config:config-item><config:config-item config:name="ActiveSplitRange" config:type="short">2</config:config-item><config:config-item config:name="PositionLeft" config:type="int">0</config:config-item><config:config-item config:name="PositionRight" config:type="int">0</config:config-item><config:config-item config:name="PositionTop" config:type="int">0</config:config-item><config:config-item config:name="PositionBottom" config:type="int">1</config:config-item></config:config-item-map-entry>';
			}
		}
		$headBrut = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$infoList = (array)($dataEnvelope['infos'] ?? Array());
		$dateFrmt = '<number:year number:style="long"/><number:text>-</number:text><number:month number:style="long"/><number:text>-</number:text><number:day number:style="long"/>';
		$fileList = Array(
			'mimetype'=>self::FILE_KIND,
			'META-INF/manifest.xml'=>$headBrut.'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2"><manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="'.self::FILE_KIND.'"/>'
				.'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
				.'<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="settings.xml" manifest:media-type="text/xml"/></manifest:manifest>',
			// The styles of the tables (shown, hidden), of the cells (names of the columns, a date, a date with its time) and of the columns (their widths)
			'content.xml'=>$headBrut.'<office:document-content'.self::NAME_LIST.'><office:automatic-styles>'
				.'<style:style style:name="ta1" style:family="table"><style:table-properties table:display="true"/></style:style><style:style style:name="ta2" style:family="table"><style:table-properties table:display="false"/></style:style>'
				.'<number:date-style style:name="N1">'.$dateFrmt.'</number:date-style><number:date-style style:name="N2">'.$dateFrmt.'<number:text> </number:text><number:hours number:style="long"/><number:text>:</number:text><number:minutes number:style="long"/><number:text>:</number:text><number:seconds number:style="long"/></number:date-style>'
				.'<style:style style:name="ce1" style:family="table-cell"><style:text-properties fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/></style:style>'
				.'<style:style style:name="ce2" style:family="table-cell" style:data-style-name="N1"/><style:style style:name="ce3" style:family="table-cell" style:data-style-name="N2"/>'.$wideText
				.'</office:automatic-styles><office:body><office:spreadsheet>'.$bodyText.'</office:spreadsheet></office:body></office:document-content>',
			'styles.xml'=>$headBrut.'<office:document-styles'.self::NAME_LIST.'><office:styles><style:default-style style:family="table-cell"><style:text-properties fo:font-size="10pt"/></style:default-style></office:styles></office:document-styles>',
			'meta.xml'=>$headBrut.'<office:document-meta'.self::NAME_LIST.'><office:meta><dc:title>'.$this->escapeXmlValue(is_scalar($infoList['title'] ?? '') ? strval($infoList['title'] ?? '') : '').'</dc:title>'
				.'<meta:initial-creator>'.$this->escapeXmlValue(is_scalar($infoList['authr'] ?? '') ? strval($infoList['authr'] ?? '') : '').'</meta:initial-creator><meta:creation-date>'.gmdate('Y-m-d\TH:i:s').'</meta:creation-date></office:meta></office:document-meta>',
			'settings.xml'=>$headBrut.'<office:document-settings'.self::NAME_LIST.'><office:settings><config:config-item-set config:name="ooo:view-settings"><config:config-item-map-indexed config:name="Views"><config:config-item-map-entry>'
				.'<config:config-item config:name="ViewId" config:type="string">view1</config:config-item>'.(($viewText==='') ? '' : '<config:config-item-map-named config:name="Tables">'.$viewText.'</config:config-item-map-named>')
				.'<config:config-item config:name="ActiveTable" config:type="string">'.$this->escapeXmlValue($nameList[intval(array_search(true, $shownLst, true))]).'</config:config-item>'
				.'</config:config-item-map-entry></config:config-item-map-indexed></config:config-item-set></office:settings></office:document-settings>'
		);
		try{
			// The first entry tells what the file is, and is kept as it is
			return Archv::newBytesString($fileList, Array('mimetype'));
		}catch( \RuntimeException $e ){
			throw new WriteError('Spreadsheet that cannot be written: '.$e->getMessage(), 0, $e);
		}
	}

	/**
	 * Writes the columns and the rows of a table.
	 *
	 * @param Array  $sheetDetails The sheet: 'title', 'attrs', 'cells'.
	 * @param Bool   $writeHeaders Whether the names of the columns are written as the first row.
	 * @param Bool   $writeAsDates Whether the texts that are dates are written as dates.
	 * @param String $columnPrefix What the names of the styles of its columns start with.
	 * @param String $columnStyles Receives the styles of its columns, added to those already there.
	 * @param Bool   $hasHeaderRow Receives whether the names of the columns were written.
	 * @return String What the table holds.
	 *
	 * @throws WriteError When the sheet is larger than a sheet can be.
	 */
	protected function sheetPieceText(Array $sheetDetails, Bool $writeHeaders, Bool $writeAsDates, String $columnPrefix, String &$columnStyles, Bool &$hasHeaderRow): String {
		$gridDict = $this->sheetGridArray($sheetDetails, $writeHeaders);
		$hasHeaderRow = !empty($gridDict['heads']);
		$rowsList = $hasHeaderRow ? array_merge(Array($gridDict['heads']), $gridDict['cells']) : $gridDict['cells'];
		// The merged areas: where each starts, and the cells each covers
		$spanList = Array();
		$coverLst = Array();
		foreach((array)($sheetDetails['attrs']['merge'] ?? Array()) as $mrgArr){
			$mrgArr = array_map('intval', (array)$mrgArr) + Array('atrow'=>-1, 'atcol'=>-1, 'rowsp'=>1, 'colsp'=>1);
			// An area of one cell is no merge; one leaving the sheet, or covering more than is reasonable, is not written
			if( $mrgArr['atrow']<0 || $mrgArr['atcol']<0 || $mrgArr['rowsp']<1 || $mrgArr['colsp']<1 || ($mrgArr['rowsp']==1 && $mrgArr['colsp']==1) || $mrgArr['atrow']+$mrgArr['rowsp']>self::ROWS_MAXI || $mrgArr['atcol']+$mrgArr['colsp']>self::COLS_MAXI || $mrgArr['rowsp']*$mrgArr['colsp']>self::MERG_MAXI || isset($coverLst[$mrgArr['atrow'].':'.$mrgArr['atcol']]) ){
				continue;
			}
			$spanList[$mrgArr['atrow'].':'.$mrgArr['atcol']] = Array($mrgArr['rowsp'], $mrgArr['colsp']);
			for($rowIdx=$mrgArr['atrow']; $rowIdx<$mrgArr['atrow']+$mrgArr['rowsp']; $rowIdx++){
				// The rows and the cells an area covers are written, even when they hold nothing
				$rowsList = array_pad($rowsList, $rowIdx+1, Array());
				$rowsList[$rowIdx] = array_pad($rowsList[$rowIdx], $mrgArr['atcol']+$mrgArr['colsp'], null);
				for($colInc=$mrgArr['atcol']; $colInc<$mrgArr['atcol']+$mrgArr['colsp']; $colInc++){
					$coverLst[$rowIdx.':'.$colInc] = true;
				}
			}
			unset($coverLst[$mrgArr['atrow'].':'.$mrgArr['atcol']]);
		}
		$dataText = '';
		$wideList = Array();
		$voidNumb = 0;
		foreach($rowsList as $rowIdx=>$oneRow){
			$rowStrng = '';
			$gapsNumb = 0;
			foreach($oneRow as $colInc=>$oneVal){
				$cellClef = $rowIdx.':'.$colInc;
				$cellText = isset($coverLst[$cellClef]) ? '<table:covered-table-cell/>' : $this->cellsPieceText($oneVal, $writeAsDates, ($hasHeaderRow && $rowIdx==0), $spanList[$cellClef] ?? Array(1, 1));
				// Cells holding nothing are counted, and written as one when a cell follows them
				if( $cellText==='' ){
					$gapsNumb++;
					continue;
				}
				$rowStrng .= (($gapsNumb>0) ? '<table:table-cell'.(($gapsNumb>1) ? ' table:number-columns-repeated="'.$gapsNumb.'"' : '').'/>' : '').$cellText;
				$gapsNumb = 0;
				$wideList[$colInc] = max($wideList[$colInc] ?? 0, is_scalar($oneVal) ? strlen(strval($oneVal)) : 0);
			}
			// So are the rows holding nothing
			if( $rowStrng==='' ){
				$voidNumb++;
				continue;
			}
			$dataText .= (($voidNumb>0) ? '<table:table-row'.(($voidNumb>1) ? ' table:number-rows-repeated="'.$voidNumb.'"' : '').'><table:table-cell/></table:table-row>' : '').'<table:table-row>'.$rowStrng.'</table:table-row>';
			$voidNumb = 0;
		}
		// The columns are as wide as what they hold, within reason
		$colsText = '';
		$colsNumb = empty($wideList) ? 0 : max(array_keys($wideList))+1;
		for($colInc=0; $colInc<$colsNumb; $colInc++){
			$columnStyles .= '<style:style style:name="'.$columnPrefix.'c'.($colInc+1).'" style:family="table-column"><style:table-column-properties style:column-width="'.sprintf('%.2F', max(8, min(60, ($wideList[$colInc] ?? 0)+2))*0.21).'cm"/></style:style>';
			$colsText .= '<table:table-column table:style-name="'.$columnPrefix.'c'.($colInc+1).'"/>';
		}
		// A table holds one column and one row at least
		return (($colsText==='') ? '<table:table-column/>' : $colsText).(($dataText==='') ? '<table:table-row><table:table-cell/></table:table-row>' : $dataText);
	}

	/**
	 * Writes a cell with the type of its value: boolean, number, date or text.
	 *
	 * @param mixed $valueToWrite What the cell holds.
	 * @param Bool  $writeAsDates Whether a text that is a date is written as a date.
	 * @param Bool  $isHeaderCell Whether the cell is the name of a column.
	 * @param Array $cellSpanPair The rows and the columns the cell covers: 1 and 1 for a cell alone.
	 * @return String The cell; '' for a cell holding nothing and covering no other.
	 */
	protected function cellsPieceText(Mixed $valueToWrite, Bool $writeAsDates, Bool $isHeaderCell, Array $cellSpanPair): String {
		$spanText = (($cellSpanPair[1]>1 || $cellSpanPair[0]>1) ? ' table:number-columns-spanned="'.$cellSpanPair[1].'" table:number-rows-spanned="'.$cellSpanPair[0].'"' : '');
		$valueToWrite = $this->simpleCellText($valueToWrite);
		if( $valueToWrite===null ){
			return ($spanText==='') ? '' : '<table:table-cell'.$spanText.'/>';
		}
		$openText = '<table:table-cell'.($isHeaderCell ? ' table:style-name="ce1"' : '').$spanText;
		if( is_bool($valueToWrite) ){
			return $openText.' office:value-type="boolean" office:boolean-value="'.($valueToWrite ? 'true' : 'false').'"><text:p>'.($valueToWrite ? 'TRUE' : 'FALSE').'</text:p></table:table-cell>';
		}
		if( is_int($valueToWrite) || is_float($valueToWrite) ){
			return $openText.' office:value-type="float" office:value="'.$this->writeNumberTxt($valueToWrite).'"><text:p>'.$this->writeNumberTxt($valueToWrite).'</text:p></table:table-cell>';
		}
		// A date is written as it is told, with the style that shows it so
		$dateList = ($writeAsDates && !$isHeaderCell) ? $this->datePartsArray($valueToWrite) : null;
		if( $dateList!==null ){
			$dateText = sprintf('%04d-%02d-%02d', $dateList[0], $dateList[1], $dateList[2]);
			$timeText = isset($dateList[3]) ? sprintf('%02d:%02d:%02d', $dateList[3], $dateList[4], $dateList[5]) : '';
			return '<table:table-cell table:style-name="'.(($timeText==='') ? 'ce2' : 'ce3').'"'.$spanText.' office:value-type="date" office:date-value="'.$dateText.(($timeText==='') ? '' : 'T'.$timeText).'"><text:p>'.$dateText.(($timeText==='') ? '' : ' '.$timeText).'</text:p></table:table-cell>';
		}
		// A text is written as text, a paragraph for each of its lines: nothing in it is ever run
		$prgrText = '';
		foreach(explode("\n", str_replace(Array("\r\n", "\r"), "\n", $valueToWrite)) as $oneLin){
			$prgrText .= '<text:p>'.$this->plainToOdfText($oneLin).'</text:p>';
		}
		return $openText.' office:value-type="string">'.(($valueToWrite==='') ? '' : $prgrText).'</table:table-cell>';
	}

	/**
	 * Writes a line of text. A document of this kind takes several spaces
	 * for one, and those at the ends of a line for none: they are written
	 * as what they are, and so are the tabs.
	 *
	 * @param String $sourceString The line.
	 * @return String The line, ready to stand in a paragraph.
	 */
	protected function plainToOdfText(String $sourceString): String {
		if( !preg_match('/\t|^ | $| {2}/', $sourceString) ){
			return $this->escapeXmlValue($sourceString);
		}
		$lineText = '';
		foreach(preg_split('/(\t|^ +| +$| {2,})/', $sourceString, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $onePrt){
			if( $onePrt==="\t" ){
				$lineText .= '<text:tab/>';
			}elseif( trim($onePrt, ' ')==='' ){
				$lineText .= '<text:s'.((strlen($onePrt)>1) ? ' text:c="'.strlen($onePrt).'"' : '').'/>';
			}else{
				$lineText .= '<text:span>'.$this->escapeXmlValue($onePrt).'</text:span>';
			}
		}
		return $lineText;
	}

}
