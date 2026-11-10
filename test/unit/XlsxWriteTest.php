<?php

// Data\Archv::newBytesString and Data\Writers\Xlsx : archives made without the
// zip extension, and the workbooks written into them.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Archv;
use nyu\Data\Fluxd;
use nyu\Data\Parsers\Xlsx as XlsxRead;
use nyu\Data\Shape;
use nyu\Data\Writers\Index;
use nyu\Data\Writers\Xlsx;

/**
 * Writes sheets as a workbook, or returns the reason it was refused.
 *
 * @param Array $sheetsList The sheets.
 * @param Array $writeOptns Options of the writer.
 * @param Array $infosArray What is told of the workbook.
 * @return mixed The bytes, or a 'THROWN ...' string.
 */
function xlsWrite( Array $sheetsList, Array $writeOptns=Array(), Array $infosArray=Array() ): Mixed {
	return runCatchThrown(function() use ($sheetsList, $writeOptns, $infosArray){
		return (new Xlsx())->renderDocument(Array('shape'=>'sheet', 'infos'=>$infosArray, 'items'=>$sheetsList), $writeOptns);
	});
}

/**
 * Writes sheets as a workbook, then reads the workbook back.
 *
 * @param Array $sheetsList The sheets.
 * @param Array $writeOptns Options of the writer.
 * @param Array $parseOptns Options of the parser.
 * @return mixed The sheets read, or a 'THROWN ...' string.
 */
function xlsBack( Array $sheetsList, Array $writeOptns=Array(), Array $parseOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($sheetsList, $writeOptns, $parseOptns){
		$bookTxt = (new Xlsx())->renderDocument(Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>$sheetsList), $writeOptns);
		return (new XlsxRead())->parseFluxArray(Fluxd::openBytesFluxd($bookTxt), $parseOptns)['items'];
	});
}

/**
 * Gives a part of a workbook, read without the zip extension: the parts
 * are found by their names in the bytes of the archive.
 *
 * @param String $archvBytes The bytes of the archive.
 * @param String $insideName Name of the part.
 * @return String The part, '' when it is not found.
 */
function xlsPart( String $archvBytes, String $insideName ): String {
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

// An archive made from its entries
$longTxt = str_repeat('A line of text that packs well. ', 200);
$archTxt = Archv::newBytesString(Array('mimetype'=>'application/test', 'folder/long.txt'=>$longTxt, 'accentué.txt'=>'été', 'empty.txt'=>''), Array('mimetype'));
checkIdentical('an archive starts with its first entry, kept as it is', Array(substr($archTxt, 0, 4), unpack('v', substr($archTxt, 8, 2))[1], substr($archTxt, 30, 8), substr($archTxt, 38, 16)), Array("PK\x03\x04", 0, 'mimetype', 'application/test'));
checkIdentical('an entry is packed when it gains from it', Array(strlen($archTxt)<strlen($longTxt), xlsPart($archTxt, 'folder/long.txt')===$longTxt), Array(true, true));
checkPregMatch('a name leaving the archive is refused', runCatchThrown(function(){ return Archv::newBytesString(Array('../outside.txt'=>'x')); }), '/^THROWN RuntimeException: Archive given an entry whose name is not safe/');
checkPregMatch('so is a name starting from the root, or told with a drive', Array(runCatchThrown(function(){ return Archv::newBytesString(Array('/etc/x'=>'x')); }), runCatchThrown(function(){ return Archv::newBytesString(Array('C:\\x'=>'x')); }))[0].Array(runCatchThrown(function(){ return Archv::newBytesString(Array('/etc/x'=>'x')); }), runCatchThrown(function(){ return Archv::newBytesString(Array('C:\\x'=>'x')); }))[1], '/^THROWN RuntimeException: Archive given an entry whose name is not safe.*THROWN RuntimeException: Archive given an entry whose name is not safe/s');
checkIdentical('an archive without entries is still one', substr(Archv::newBytesString(Array()), 0, 4), "PK\x05\x06");

// The workbook, part by part
$rowsLst = Array(Array('name'=>'Alpha & <Beta>', 'seats'=>120, 'price'=>12.5, 'open'=>true, 'note'=>null), Array('name'=>'=SUM(A1:A9)', 'seats'=>-3, 'price'=>0.1, 'open'=>false, 'note'=>"two\nlines"));
$showSht = Array('title'=>'Shows', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>$rowsLst);
$bookTxt = xlsWrite(Array($showSht), Array(), Array('title'=>'Season <2026>', 'authr'=>'Zoé'));
checkIdentical('a workbook is a zip archive', substr($bookTxt, 0, 2), 'PK');
$shetXml = xlsPart($bookTxt, 'xl/worksheets/sheet1.xml');
checkTrueValue('every part is well-formed', array_reduce(Array('[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'), function($allAreGood, $insideName) use ($bookTxt){ return $allAreGood && @simplexml_load_string(xlsPart($bookTxt, $insideName))!==false; }, true));
checkPregMatch('the names of the columns are the first row, bold and kept in sight', $shetXml, '/<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"\/>.*<row r="1"><c r="A1" s="1" t="inlineStr"><is><t xml:space="preserve">name<\/t><\/is><\/c><c r="B1" s="1" t="inlineStr">/s');
checkPregMatch('a text keeps its signs as text', $shetXml, '/<c r="A2" t="inlineStr"><is><t xml:space="preserve">Alpha &amp; &lt;Beta&gt;<\/t><\/is><\/c>/');
checkPregMatch('numbers are numbers, true and false are such, an empty cell is not written', $shetXml, '/<c r="B2"><v>120<\/v><\/c><c r="C2"><v>12\.5<\/v><\/c><c r="D2" t="b"><v>1<\/v><\/c><\/row>/');
checkPregMatch('a text that looks like a formula is text, never a formula', $shetXml, '/<c r="A3" t="inlineStr"><is><t xml:space="preserve">=SUM\(A1:A9\)<\/t><\/is><\/c><c r="B3"><v>-3<\/v><\/c><c r="C3"><v>0\.1<\/v><\/c><c r="D3" t="b"><v>0<\/v><\/c>/');
checkIdentical('no cell holds a formula', strpos($shetXml, '<f>'), false);
checkPregMatch('the title and the author of the workbook', xlsPart($bookTxt, 'docProps/core.xml'), '/<dc:title>Season &lt;2026&gt;<\/dc:title><dc:creator>Zoé<\/dc:creator>/');
checkPregMatch('the columns are as wide as what they hold', $shetXml, '/<cols><col min="1" max="1" width="16" customWidth="1"\/><col min="2" max="2" width="8" customWidth="1"\/>/');

// Names of sheets
$bookTxt = xlsWrite(Array(Shape::makeSheetArray('Sales [2026]: Q1/Q2?*', Array(Array(1))), Shape::makeSheetArray('', Array(Array(2))), Shape::makeSheetArray('A very long name of sheet that goes past the limit', Array(Array(3))), Shape::makeSheetArray('data', Array(Array(4))), Shape::makeSheetArray('DATA', Array(Array(5)))));
checkPregMatch('names a workbook accepts: cleaned, cut, given when missing, never twice', xlsPart($bookTxt, 'xl/workbook.xml'), '/<sheet name="Sales  2026  Q1 Q2" sheetId="1" r:id="rId1"\/><sheet name="Sheet2" sheetId="2" r:id="rId2"\/><sheet name="A very long name of sheet that" sheetId="3" r:id="rId3"\/><sheet name="data" sheetId="4" r:id="rId4"\/><sheet name="DATA \(2\)" sheetId="5" r:id="rId5"\/>/');

// What is refused, and what is written anyway
checkPregMatch('a shape that is not sheets', runCatchThrown(function(){ return (new Xlsx())->renderDocument(Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as a workbook: block/');
checkPregMatch('a row of more cells than a worksheet holds', xlsWrite(Array(Shape::makeSheetArray('Wide', Array(array_fill(0, 16385, 1))))), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Sheet larger than a sheet can be/');
checkPregMatch('no sheet at all gives a workbook of one empty sheet', xlsPart(xlsWrite(Array()), 'xl/workbook.xml'), '/<sheet name="Sheet1" sheetId="1" r:id="rId1"\/>/');
$oddsXml = xlsPart(xlsWrite(Array(Shape::makeSheetArray('Odd', Array(Array("bell\x07 and\x00 nul", Array('a'=>1, 'b'=>Array(2)), Fluxd::openBytesFluxd('binary'), NAN, "caf\xE9", 'last'))))), 'xl/worksheets/sheet1.xml');
checkPregMatch('what a part cannot hold is dropped, a list is written as text, a binary value and what is no number as nothing', $oddsXml, '/<row r="1"><c r="A1" t="inlineStr"><is><t xml:space="preserve">bell and nul<\/t><\/is><\/c><c r="B1" t="inlineStr"><is><t xml:space="preserve">\{&quot;a&quot;:1,&quot;b&quot;:\[2\]\}<\/t><\/is><\/c><c r="E1" t="inlineStr"><is><t xml:space="preserve">café<\/t><\/is><\/c><c r="F1" t="inlineStr">/');
checkIdentical('the writer of the xlsx extension', Array(get_class(Index::forExtnsWriter('XLSX')), Index::contentTypeFor('xlsx')), Array('nyu\\Data\\Writers\\Xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));

// Dates
$dateXml = xlsPart(xlsWrite(Array(Shape::makeSheetArray('Dates', Array(Array('2026-10-11', '2026-10-11 14:30:00', '2026-02-30', '1899-12-31', 'on 2026-10-11', '2026-10-11T06:00')))), Array('dates'=>true)), 'xl/worksheets/sheet1.xml');
checkPregMatch('texts that are dates, written as dates: day 46306 is 11 October 2026', $dateXml, '/<c r="A1" s="2"><v>46306<\/v><\/c><c r="B1" s="3"><v>46306\.6041666667<\/v><\/c><c r="C1" t="inlineStr"><is><t xml:space="preserve">2026-02-30<\/t><\/is><\/c><c r="D1" t="inlineStr"><is><t xml:space="preserve">1899-12-31<\/t><\/is><\/c><c r="E1" t="inlineStr"><is><t xml:space="preserve">on 2026-10-11<\/t><\/is><\/c><c r="F1" s="3"><v>46306\.2500000000<\/v><\/c>/');
checkPregMatch('without the option, a date stays a text', xlsPart(xlsWrite(Array(Shape::makeSheetArray('Dates', Array(Array('2026-10-11'))))), 'xl/worksheets/sheet1.xml'), '/<c r="A1" t="inlineStr"><is><t xml:space="preserve">2026-10-11<\/t>/');

if( !class_exists('ZipArchive') ){
	recordSkipping('workbooks written, then read back', 'the zip extension is not loaded');
	finishChecking();
}

// The archive, read by the zip extension
$archObj = Archv::openFluxdArchv(Fluxd::openBytesFluxd($archTxt));
checkIdentical('an archive made here is read by the zip extension', Array($archObj->listEntryNames(), $archObj->getEntryString('folder/long.txt')===$longTxt, $archObj->getEntryString('accentué.txt'), $archObj->getEntryString('empty.txt')), Array(Array('mimetype'=>16, 'folder/long.txt'=>strlen($longTxt), 'accentué.txt'=>5, 'empty.txt'=>0), true, 'été', ''));
$archObj->releaseArchive();

// Written, then read back
checkIdentical('rows keyed by the names of their columns, read back with them', xlsBack(Array($showSht), Array(), Array('heads'=>true))[0], Array('title'=>'Shows', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('name'=>'Alpha & <Beta>', 'seats'=>120, 'price'=>12.5, 'open'=>true, 'note'=>null), Array('name'=>'=SUM(A1:A9)', 'seats'=>-3, 'price'=>0.1, 'open'=>false, 'note'=>"two\nlines"))));
checkIdentical('without the names of the columns', xlsBack(Array($showSht), Array('heads'=>false))[0]['cells'][0], Array('Alpha & <Beta>', 120, 12.5, true));
$gridLst = Array(Array('Title', null, 'Total'), Array(), Array(1, 2.25, ' kept spaces '), Array(null, null, 'far'));
$mergLst = Array(Array('atrow'=>0, 'atcol'=>0, 'rowsp'=>1, 'colsp'=>2), Array('atrow'=>2, 'atcol'=>3, 'rowsp'=>3, 'colsp'=>1));
$backLst = xlsBack(Array(Shape::makeSheetArray('Grid', $gridLst, Array('shown'=>true, 'merge'=>$mergLst)), Shape::makeSheetArray('Hidden one', Array(Array('secret')), Array('shown'=>false, 'merge'=>Array())), Shape::makeSheetArray('Empty', Array())));
checkIdentical('several sheets, their cells at their place, the hidden one and the merged cells', $backLst, Array(
	Array('title'=>'Grid', 'attrs'=>Array('shown'=>true, 'merge'=>$mergLst), 'cells'=>$gridLst),
	Array('title'=>'Hidden one', 'attrs'=>Array('shown'=>false, 'merge'=>Array()), 'cells'=>Array(Array('secret'))),
	Array('title'=>'Empty', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array())
));
checkIdentical('a workbook whose sheets are all hidden shows its first', array_column(array_column(xlsBack(Array(Shape::makeSheetArray('One', Array(Array(1)), Array('shown'=>false)), Shape::makeSheetArray('Two', Array(Array(2)), Array('shown'=>false)))), 'attrs'), 'shown'), Array(true, false));
checkIdentical('dates written as dates are read back as the same texts', xlsBack(Array(Shape::makeSheetArray('Dates', Array(Array('2026-10-11', '2026-10-11 14:30:00', '1970-01-01', '2026-12-31 23:59:59', 'not a date')))), Array('dates'=>true))[0]['cells'][0], Array('2026-10-11', '2026-10-11 14:30:00', '1970-01-01', '2026-12-31 23:59:59', 'not a date'));
checkIdentical('large and small numbers', xlsBack(Array(Shape::makeSheetArray('Numbers', Array(Array(0, 123456789012, -0.5, 1.0E-7, 1.5E+20, 3.14159265358979)))))[0]['cells'][0], Array(0, 123456789012, -0.5, 1.0E-7, 1.5E+20, 3.14159265358979));
checkIdentical('texts of every kind', xlsBack(Array(Shape::makeSheetArray('Texts', Array(Array('', ' ', 'Ünïcödé 日本語 😀', "tab\there", '0123', '1e5', 'TRUE')))))[0]['cells'][0], Array('', ' ', 'Ünïcödé 日本語 😀', "tab\there", '0123', '1e5', 'TRUE'));
// A workbook read is written back as it was read
$smplPth = TST_DIR.DS.'fixtures'.DS.'office'.DS.'SampleSS.xlsx';
$smplSet = (new XlsxRead())->parseFluxArray(Fluxd::openLocalFluxd($smplPth));
$againSt = (new XlsxRead())->parseFluxArray(Fluxd::openBytesFluxd((new Xlsx())->renderDocument($smplSet, Array('dates'=>true))));
checkIdentical('a workbook of Excel read, written and read again gives the same sheets', Array($againSt['items']==$smplSet['items'], $againSt['infos']['names'], count($againSt['items'])>0), Array(true, $smplSet['infos']['names'], true));

finishChecking();
