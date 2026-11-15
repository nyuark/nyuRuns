<?php

// Data\Writers\Odsx : OpenDocument spreadsheets written from sheets.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Archv;
use nyu\Data\Fluxd;
use nyu\Data\Parsers\Odsx as OdsxRead;
use nyu\Data\Shape;
use nyu\Data\Writers\Index;
use nyu\Data\Writers\Odsx;

/**
 * Writes sheets as a spreadsheet, or returns the reason it was refused.
 *
 * @param Array $sheetsList The sheets.
 * @param Array $writeOptns Options of the writer.
 * @param Array $infosArray What is told of the document.
 * @return mixed The bytes, or a 'THROWN ...' string.
 */
function odsWrite( Array $sheetsList, Array $writeOptns=Array(), Array $infosArray=Array() ): Mixed {
	return runCatchThrown(function() use ($sheetsList, $writeOptns, $infosArray){
		return (new Odsx())->renderDocument(Array('shape'=>'sheet', 'infos'=>$infosArray, 'items'=>$sheetsList), $writeOptns);
	});
}

/**
 * Writes sheets as a spreadsheet, then reads the spreadsheet back.
 *
 * @param Array $sheetsList The sheets.
 * @param Array $writeOptns Options of the writer.
 * @param Array $parseOptns Options of the parser.
 * @return mixed The sheets read, or a 'THROWN ...' string.
 */
function odsBack( Array $sheetsList, Array $writeOptns=Array(), Array $parseOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($sheetsList, $writeOptns, $parseOptns){
		$bookTxt = (new Odsx())->renderDocument(Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>$sheetsList), $writeOptns);
		return (new OdsxRead())->parseFluxArray(Fluxd::openBytesFluxd($bookTxt), $parseOptns)['items'];
	});
}

/**
 * Gives a part of a document, read without the zip extension: the parts
 * are found by their names in the bytes of the archive.
 *
 * @param String $archvBytes The bytes of the archive.
 * @param String $insideName Name of the part.
 * @return String The part, '' when it is not found.
 */
function odsPart( String $archvBytes, String $insideName ): String {
	$headPos = strpos($archvBytes, "PK\x03\x04");
	while( $headPos!==false ){
		$headSet = unpack('vvers/vflag/vmthd/vtime/vdate/Vcrcs/Vpack/Vsize/vname/vxtra', substr($archvBytes, $headPos+4, 26));
		$dataTxt = substr($archvBytes, $headPos+30+$headSet['name']+$headSet['xtra'], $headSet['pack']);
		if( substr($archvBytes, $headPos+30, $headSet['name'])===$insideName ){
			return ($headSet['mthd']==8) ? strval(gzinflate($dataTxt)) : $dataTxt;
		}
		$headPos = strpos($archvBytes, "PK\x03\x04", $headPos+30+$headSet['name']+$headSet['xtra']+$headSet['pack']);
	}
	return '';
}

// The document, part by part
$rowsLst = Array(Array('name'=>'Alpha & <Beta>', 'seats'=>120, 'price'=>12.5, 'open'=>true, 'note'=>null), Array('name'=>'=SUM(A1:A9)', 'seats'=>-3, 'price'=>0.1, 'open'=>false, 'note'=>"two\nlines"));
$showSht = Array('title'=>'Shows', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>$rowsLst);
$bookTxt = odsWrite(Array($showSht), Array(), Array('title'=>'Season <2026>', 'authr'=>'Zoé'));
checkIdentical('a spreadsheet starts with what it is, kept as it is', Array(substr($bookTxt, 0, 4), unpack('v', substr($bookTxt, 8, 2))[1], substr($bookTxt, 30, 8), substr($bookTxt, 38, 46)), Array("PK\x03\x04", 0, 'mimetype', 'application/vnd.oasis.opendocument.spreadsheet'));
checkTrueValue('every part is well-formed', array_reduce(Array('META-INF/manifest.xml', 'content.xml', 'styles.xml', 'meta.xml', 'settings.xml'), function($allAreGood, $insideName) use ($bookTxt){ return $allAreGood && @simplexml_load_string(odsPart($bookTxt, $insideName))!==false; }, true));
$bodyXml = odsPart($bookTxt, 'content.xml');
checkPregMatch('the names of the columns are the first row, bold', $bodyXml, '/<table:table table:name="Shows" table:style-name="ta1"><table:table-column table:style-name="s1c1"\/>.*?<table:table-row><table:table-cell table:style-name="ce1" office:value-type="string"><text:p>name<\/text:p><\/table:table-cell><table:table-cell table:style-name="ce1" office:value-type="string"><text:p>seats<\/text:p>/s');
checkPregMatch('the settings declare the address their name starts with, without which they are not read', odsPart($bookTxt, 'settings.xml'), '/<office:document-settings[^>]* xmlns:ooo="http:\/\/openoffice\.org\/2004\/office"[^>]*><office:settings><config:config-item-set config:name="ooo:view-settings">/');
checkPregMatch('and kept in sight', odsPart($bookTxt, 'settings.xml'), '/<config:config-item-map-named config:name="Tables"><config:config-item-map-entry config:name="Shows">.*<config:config-item config:name="VerticalSplitMode" config:type="short">2<\/config:config-item><config:config-item config:name="HorizontalSplitPosition" config:type="int">0<\/config:config-item><config:config-item config:name="VerticalSplitPosition" config:type="int">1<\/config:config-item>.*<config:config-item config:name="ActiveTable" config:type="string">Shows<\/config:config-item>/s');
checkPregMatch('a text keeps its signs, numbers are numbers, true is true, an empty cell ending a row is not written', $bodyXml, '/<table:table-row><table:table-cell office:value-type="string"><text:p>Alpha &amp; &lt;Beta&gt;<\/text:p><\/table:table-cell><table:table-cell office:value-type="float" office:value="120"><text:p>120<\/text:p><\/table:table-cell><table:table-cell office:value-type="float" office:value="12\.5"><text:p>12\.5<\/text:p><\/table:table-cell><table:table-cell office:value-type="boolean" office:boolean-value="true"><text:p>TRUE<\/text:p><\/table:table-cell><\/table:table-row>/');
checkPregMatch('a text that looks like a formula is text, a text of two lines is two paragraphs', $bodyXml, '/<table:table-cell office:value-type="string"><text:p>=SUM\(A1:A9\)<\/text:p><\/table:table-cell>.*<table:table-cell office:value-type="string"><text:p>two<\/text:p><text:p>lines<\/text:p><\/table:table-cell><\/table:table-row>/s');
checkIdentical('no cell holds a formula', strpos($bodyXml, 'table:formula'), false);
checkPregMatch('the title and the author of the document', odsPart($bookTxt, 'meta.xml'), '/<dc:title>Season &lt;2026&gt;<\/dc:title><meta:initial-creator>Zoé<\/meta:initial-creator>/');
checkPregMatch('the columns are as wide as what they hold', $bodyXml, '/<style:style style:name="s1c1" style:family="table-column"><style:table-column-properties style:column-width="3\.36cm"\/><\/style:style><style:style style:name="s1c2" style:family="table-column"><style:table-column-properties style:column-width="1\.68cm"\/>/');

// Sheets: names, hidden ones, none at all
$bodyXml = odsPart(odsWrite(Array(Shape::makeSheetArray('Sales [2026]: Q1/Q2?*', Array(Array(1))), Shape::makeSheetArray('', Array(Array(2)), Array('shown'=>false)), Shape::makeSheetArray('data', Array(Array(4))), Shape::makeSheetArray('DATA', Array(Array(5))))), 'content.xml');
checkIdentical('names every spreadsheet accepts, and the sheet that is hidden', preg_match_all('/<table:table table:name="([^"]*)" table:style-name="(ta\d)">/', $bodyXml, $tblsFnd) ? array_map(null, $tblsFnd[1], $tblsFnd[2]) : null, Array(Array('Sales  2026  Q1 Q2', 'ta1'), Array('Sheet2', 'ta2'), Array('data', 'ta1'), Array('DATA (2)', 'ta1')));
checkPregMatch('no sheet at all gives a spreadsheet of one empty sheet', odsPart(odsWrite(Array()), 'content.xml'), '/<table:table table:name="Sheet1" table:style-name="ta1"><table:table-column\/><table:table-row><table:table-cell\/><\/table:table-row><\/table:table>/');
checkPregMatch('a shape that is not sheets', runCatchThrown(function(){ return (new Odsx())->renderDocument(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as a spreadsheet: nodes/');
checkPregMatch('a row of more cells than a sheet holds', odsWrite(Array(Shape::makeSheetArray('Wide', Array(array_fill(0, 16385, 1))))), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Sheet larger than a sheet can be/');
checkIdentical('the writer of the ods extension', Array(get_class(Index::getExtnsWriter('ODS')), Index::getMimeForExtn('ods')), Array('nyu\\Data\\Writers\\Odsx', 'application/vnd.oasis.opendocument.spreadsheet'));

// Cells holding nothing, rows holding nothing, merged cells
$gridLst = Array(Array('Title', null, null, 'Total'), Array(), Array(), Array(1, 2.25), Array(null, null, 'far'));
$mergLst = Array(Array('atrow'=>0, 'atcol'=>0, 'rowsp'=>1, 'colsp'=>3), Array('atrow'=>3, 'atcol'=>4, 'rowsp'=>3, 'colsp'=>2));
$bodyXml = odsPart(odsWrite(Array(Shape::makeSheetArray('Grid', $gridLst, Array('shown'=>true, 'merge'=>$mergLst)))), 'content.xml');
checkPregMatch('a merged area tells what it covers, and the cells it covers are told covered', $bodyXml, '/<table:table-row><table:table-cell table:number-columns-spanned="3" table:number-rows-spanned="1" office:value-type="string"><text:p>Title<\/text:p><\/table:table-cell><table:covered-table-cell\/><table:covered-table-cell\/><table:table-cell office:value-type="string"><text:p>Total<\/text:p><\/table:table-cell><\/table:table-row>/');
checkPregMatch('rows holding nothing are written as one', $bodyXml, '/<\/table:table-row><table:table-row table:number-rows-repeated="2"><table:table-cell\/><\/table:table-row><table:table-row><table:table-cell office:value-type="float" office:value="1">/');
checkPregMatch('a merged area starting at a cell holding nothing, past the end of its row', $bodyXml, '/<text:p>2\.25<\/text:p><\/table:table-cell><table:table-cell table:number-columns-repeated="2"\/><table:table-cell table:number-columns-spanned="2" table:number-rows-spanned="3"\/><table:covered-table-cell\/><\/table:table-row>/');
checkPregMatch('cells holding nothing before one that holds something are written as one', $bodyXml, '/<table:table-row><table:table-cell table:number-columns-repeated="2"\/><table:table-cell office:value-type="string"><text:p>far<\/text:p><\/table:table-cell><table:table-cell\/><table:covered-table-cell\/><table:covered-table-cell\/><\/table:table-row><table:table-row><table:table-cell table:number-columns-repeated="4"\/><table:covered-table-cell\/><table:covered-table-cell\/><\/table:table-row>/');

// Dates and spaces
$bodyXml = odsPart(odsWrite(Array(Shape::makeSheetArray('Dates', Array(Array('2026-10-11', '2026-10-11 14:30:00', '2026-02-30', '1815-06-18', 'on 2026-10-11', '2026-10-11T06:00')))), Array('dates'=>true)), 'content.xml');
checkPregMatch('texts that are dates, written as dates', $bodyXml, '/<table:table-cell table:style-name="ce2" office:value-type="date" office:date-value="2026-10-11"><text:p>2026-10-11<\/text:p><\/table:table-cell><table:table-cell table:style-name="ce3" office:value-type="date" office:date-value="2026-10-11T14:30:00"><text:p>2026-10-11 14:30:00<\/text:p><\/table:table-cell><table:table-cell office:value-type="string"><text:p>2026-02-30<\/text:p><\/table:table-cell><table:table-cell table:style-name="ce2" office:value-type="date" office:date-value="1815-06-18">.*<table:table-cell office:value-type="string"><text:p>on 2026-10-11<\/text:p><\/table:table-cell><table:table-cell table:style-name="ce3" office:value-type="date" office:date-value="2026-10-11T06:00:00">/s');
checkPregMatch('without the option, a date stays a text', odsPart(odsWrite(Array(Shape::makeSheetArray('Dates', Array(Array('2026-10-11'))))), 'content.xml'), '/<table:table-cell office:value-type="string"><text:p>2026-10-11<\/text:p>/');
checkPregMatch('several spaces, those at the ends and the tabs are written as what they are', odsPart(odsWrite(Array(Shape::makeSheetArray('Spaces', Array(Array(" two  spaces\tand a tab "))))), 'content.xml'), '/<text:p><text:s\/><text:span>two<\/text:span><text:s text:c="2"\/><text:span>spaces<\/text:span><text:tab\/><text:span>and a tab<\/text:span><text:s\/><\/text:p>/');

if( !class_exists('ZipArchive') ){
	recordSkipping('spreadsheets written, then read back', 'the zip extension is not loaded');
	finishChecking();
}

// Written, then read back
checkIdentical('rows keyed by the names of their columns, read back with them', odsBack(Array($showSht), Array(), Array('heads'=>true))[0], Array('title'=>'Shows', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('name'=>'Alpha & <Beta>', 'seats'=>120, 'price'=>12.5, 'open'=>true, 'note'=>null), Array('name'=>'=SUM(A1:A9)', 'seats'=>-3, 'price'=>0.1, 'open'=>false, 'note'=>"two\nlines"))));
checkIdentical('without the names of the columns', odsBack(Array($showSht), Array('heads'=>false))[0]['cells'][0], Array('Alpha & <Beta>', 120, 12.5, true));
$backLst = odsBack(Array(Shape::makeSheetArray('Grid', $gridLst, Array('shown'=>true, 'merge'=>$mergLst)), Shape::makeSheetArray('Hidden one', Array(Array('secret')), Array('shown'=>false, 'merge'=>Array())), Shape::makeSheetArray('Empty', Array())));
checkIdentical('several sheets, their cells at their place, the hidden one and the merged cells', $backLst, Array(
	Array('title'=>'Grid', 'attrs'=>Array('shown'=>true, 'merge'=>$mergLst), 'cells'=>$gridLst),
	Array('title'=>'Hidden one', 'attrs'=>Array('shown'=>false, 'merge'=>Array()), 'cells'=>Array(Array('secret'))),
	Array('title'=>'Empty', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array())
));
checkIdentical('a spreadsheet whose sheets are all hidden shows its first', array_column(array_column(odsBack(Array(Shape::makeSheetArray('One', Array(Array(1)), Array('shown'=>false)), Shape::makeSheetArray('Two', Array(Array(2)), Array('shown'=>false)))), 'attrs'), 'shown'), Array(true, false));
checkIdentical('dates written as dates are read back as the same texts', odsBack(Array(Shape::makeSheetArray('Dates', Array(Array('2026-10-11', '2026-10-11 14:30:00', '1815-06-18', '2026-12-31 23:59:59', 'not a date')))), Array('dates'=>true))[0]['cells'][0], Array('2026-10-11', '2026-10-11 14:30:00', '1815-06-18', '2026-12-31 23:59:59', 'not a date'));
checkIdentical('large and small numbers', odsBack(Array(Shape::makeSheetArray('Numbers', Array(Array(0, 123456789012, -0.5, 1.0E-7, 1.5E+20, 3.14159265358979)))))[0]['cells'][0], Array(0, 123456789012, -0.5, 1.0E-7, 1.5E+20, 3.14159265358979));
checkIdentical('texts of every kind', odsBack(Array(Shape::makeSheetArray('Texts', Array(Array('', ' ', 'Ünïcödé 日本語 😀', "tab\there", '0123', '1e5', 'TRUE', " two  spaces\tand a tab ", "line one\n\nline three")))))[0]['cells'][0], Array('', ' ', 'Ünïcödé 日本語 😀', "tab\there", '0123', '1e5', 'TRUE', " two  spaces\tand a tab ", "line one\n\nline three"));
checkIdentical('what cannot be written is written as nothing, a list as text', odsBack(Array(Shape::makeSheetArray('Odd', Array(Array("bell\x07 and\x00 nul", Array('a'=>1), Fluxd::openBytesFluxd('binary'), NAN, 'last')))))[0]['cells'][0], Array('bell and nul', '{"a":1}', null, null, 'last'));
// A spreadsheet read is written back as it was read
$smplSet = (new OdsxRead())->parseFluxArray(Fluxd::openLocalFluxd(TST_DIR.DS.'fixtures'.DS.'office'.DS.'sample.ods'));
$againSt = (new OdsxRead())->parseFluxArray(Fluxd::openBytesFluxd((new Odsx())->renderDocument($smplSet, Array('dates'=>true))));
checkIdentical('a spreadsheet of LibreOffice read, written and read again gives the same sheets', Array($againSt['items']==$smplSet['items'], $againSt['infos']['names'], count($againSt['items'])>0), Array(true, $smplSet['infos']['names'], true));
// The same content, written as a workbook of Excel and as a spreadsheet, is read the same
$bothLst = Array(Shape::makeSheetArray('Grid', $gridLst, Array('shown'=>true, 'merge'=>$mergLst)), Shape::makeSheetArray('Hidden one', Array(Array('secret', 12, true, '2026-10-11')), Array('shown'=>false, 'merge'=>Array())));
$xlsxSet = (new nyu\Data\Parsers\Xlsx())->parseFluxArray(Fluxd::openBytesFluxd((new nyu\Data\Writers\Xlsx())->renderDocument(Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>$bothLst), Array('dates'=>true))));
checkIdentical('one content, two formats, the same sheets', odsBack($bothLst, Array('dates'=>true)), $xlsxSet['items']);

finishChecking();
