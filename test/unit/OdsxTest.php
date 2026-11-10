<?php

// Data\Parsers\Odsx : OpenDocument spreadsheets read into sheets.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Fluxd;
use nyu\Data\Parsers\Index;
use nyu\Data\Parsers\Odsx;
use nyu\Data\Parsers\Xlsx;

if( !class_exists('ZipArchive') ){
	recordSkipping('spreadsheets', 'the zip extension is not loaded');
	finishChecking();
}

const ODFSPACE = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"';

/**
 * Builds a spreadsheet in the temporary folder.
 *
 * @param String $archivName Name of the file to make.
 * @param String $tablesXml  The XML of its tables.
 * @param String $stylesXml  The XML of its automatic styles.
 * @param Array  $extraParts Parts to add or to replace, name => content.
 * @return String The path of the spreadsheet.
 */
function makeOds( String $archivName, String $tablesXml, String $stylesXml='', Array $extraParts=Array() ): String {
	$zipPath = inTemporaryDir($archivName);
	$zipArch = new ZipArchive();
	$zipArch->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	$partLst = Array(
		'mimetype'=>'application/vnd.oasis.opendocument.spreadsheet',
		'content.xml'=>'<?xml version="1.0" encoding="UTF-8"?><office:document-content '.ODFSPACE.'><office:automatic-styles>'.$stylesXml.'</office:automatic-styles><office:body><office:spreadsheet>'.$tablesXml.'</office:spreadsheet></office:body></office:document-content>'
	);
	foreach( array_merge($partLst, $extraParts) as $prtNme=>$prtTxt ){
		$zipArch->addFromString($prtNme, $prtTxt);
	}
	$zipArch->close();
	return $zipPath;
}

/**
 * Writes the XML of a cell holding a text.
 *
 * @param String $textString The text.
 * @param String $attrsOfTag Attributes to give the cell.
 * @return String The XML.
 */
function odsText( String $textString, String $attrsOfTag='' ): String {
	return '<table:table-cell office:value-type="string"'.$attrsOfTag.'><text:p>'.$textString.'</text:p></table:table-cell>';
}

/**
 * Parses a spreadsheet and returns its envelope, or the reason it was refused.
 *
 * @param String $sourcePath Path of the spreadsheet.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The envelope, or a 'THROWN ...' string.
 */
function odsRead( String $sourcePath, Array $parseOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($sourcePath, $parseOptns){
		$prsrObj = new Odsx();
		return $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($sourcePath), $parseOptns);
	});
}

/**
 * Parses a spreadsheet and returns the rows of its first sheet.
 *
 * @param String $sourcePath Path of the spreadsheet.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The rows, or a 'THROWN ...' string.
 */
function odsRows( String $sourcePath, Array $parseOptns=Array() ): Mixed {
	$envlope = odsRead($sourcePath, $parseOptns);
	return is_array($envlope) ? $envlope['items'][0]['cells'] : $envlope;
}

$plainAt = Array('shown'=>true, 'merge'=>Array());

// A spreadsheet saved by OpenOffice
$smplPth = TST_DIR.DS.'fixtures'.DS.'office'.DS.'sample.ods';
checkIdentical('a spreadsheet saved by OpenOffice: its sheets, their cells at their place, a formula as its result', odsRead($smplPth), Array(
	'shape'=>'sheet',
	'infos'=>Array('names'=>Array('Shows', 'Notes'), 'count'=>2, 'heads'=>Array()),
	'items'=>Array(
		Array('title'=>'Shows', 'attrs'=>$plainAt, 'cells'=>Array(
			Array('Title', 'Venue', 'Seats', 'Price', 'Date'), Array('Alpha Night', 'Hall A', 120, 12.5, '2026-12-28'), Array('Les Enchaînés', 'Hall B', 80, 9, '2026-11-02'),
			Array('Empty Stage', null, 0), Array(null, 'Total', 200)
		)),
		Array('title'=>'Notes', 'attrs'=>$plainAt, 'cells'=>Array(Array('Second sheet'), Array(), Array('Row three, after an empty row')))
	)
));
checkIdentical('the same spreadsheet given as bytes, and an empty content', Array(
	runCatchThrown(function() use ($smplPth){ return (new Odsx())->parseDataArray(file_get_contents($smplPth))['items'][1]['cells'][0]; }),
	runCatchThrown(function(){ return (new Odsx())->parseDataArray(''); })
), Array(Array('Second sheet'), Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array())));
checkIdentical('sheet: one sheet by its name, or by its position', Array(array_column(odsRead($smplPth, Array('sheet'=>'Notes'))['items'], 'title'), array_column(odsRead($smplPth, Array('sheet'=>1))['items'], 'title')), Array(Array('Notes'), Array('Shows')));
checkPregMatch('sheet: a sheet that is not in the document', odsRead($smplPth, Array('sheet'=>'Nowhere')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid workbook option: no sheet Nowhere/');
checkIdentical('heads: the first row of each sheet names its columns', runCatchThrown(function() use ($smplPth){
	$envlope = odsRead($smplPth, Array('heads'=>true));
	return Array($envlope['infos']['heads'], $envlope['items'][0]['cells'][0], $envlope['items'][0]['cells'][3], $envlope['items'][1]['cells']);
}), Array(
	Array('Shows'=>Array('Title', 'Venue', 'Seats', 'Price', 'Date'), 'Notes'=>Array('Second sheet')),
	Array('Title'=>'Alpha Night', 'Venue'=>'Hall A', 'Seats'=>120, 'Price'=>12.5, 'Date'=>'2026-12-28'),
	Array('Title'=>null, 'Venue'=>'Total', 'Seats'=>200, 'Price'=>null, 'Date'=>null),
	Array(Array('Second sheet'=>null), Array('Second sheet'=>'Row three, after an empty row'))
));

// Values
$valsPth = makeOds('values.ods', '<table:table table:name="V">'
	.'<table:table-row><table:table-cell office:value-type="float" office:value="120"><text:p>120</text:p></table:table-cell><table:table-cell office:value-type="float" office:value="-0.25"/><table:table-cell office:value-type="percentage" office:value="0.5"><text:p>50 %</text:p></table:table-cell><table:table-cell office:value-type="currency" office:currency="EUR" office:value="12.5"><text:p>12,50 €</text:p></table:table-cell><table:table-cell office:value-type="float" office:value="1E+20"/></table:table-row>'
	.'<table:table-row><table:table-cell office:value-type="boolean" office:boolean-value="true"><text:p>TRUE</text:p></table:table-cell><table:table-cell office:value-type="boolean" office:boolean-value="false"/><table:table-cell office:value-type="date" office:date-value="2026-12-28"><text:p>28/12/26</text:p></table:table-cell><table:table-cell office:value-type="date" office:date-value="2026-12-28T20:30:00"/><table:table-cell office:value-type="date" office:date-value="2026-12-28T20:30"/><table:table-cell office:value-type="time" office:time-value="PT20H30M00S"><text:p>20:30</text:p></table:table-cell><table:table-cell office:value-type="time" office:time-value="PT36H05M07.5S"/></table:table-row>'
	.'<table:table-row>'.odsText('Les Enchaînés').odsText('two<text:s/>words<text:s text:c="3"/>far<text:tab/>tab<text:line-break/>next').odsText('a <text:span>b</text:span> <text:span>c</text:span>').'<table:table-cell office:value-type="string"><text:p>first</text:p><text:p>second</text:p></table:table-cell><table:table-cell office:value-type="string" office:string-value="told"><text:p>shown</text:p></table:table-cell><table:table-cell office:value-type="string"/><table:table-cell><text:p>untyped</text:p></table:table-cell></table:table-row>'
	.'<table:table-row><table:table-cell office:value-type="string"><office:annotation><text:p>a note</text:p></office:annotation><text:p>noted</text:p></table:table-cell><table:table-cell table:formula="of:=1/0" office:value-type="string" office:string-value=""><text:p>#DIV/0!</text:p></table:table-cell><table:table-cell table:formula="of:=A1*2" office:value-type="float" office:value="240"><text:p>240</text:p></table:table-cell><table:table-cell office:value-type="float"/></table:table-row>'
	.'</table:table>');
checkIdentical('numbers: whole ones as Int, the others as Float, whatever they are shown as', odsRows($valsPth)[0], Array(120, -0.25, 0.5, 12.5, 1.0E+20));
checkIdentical('booleans, dates and times, as ISO text', odsRows($valsPth)[1], Array(true, false, '2026-12-28', '2026-12-28 20:30:00', '2026-12-28 20:30:00', '20:30:00', '36:05:07'));
checkIdentical('texts: accents, spaces, tabulations and line breaks, pieces, paragraphs, and a text told apart from what is shown', odsRows($valsPth)[2], Array('Les Enchaînés', "two words   far\ttab\nnext", 'a b c', "first\nsecond", 'told', '', 'untyped'));
checkIdentical('a note is not part of its cell; a formula is the result stored for it', odsRows($valsPth)[3], Array('noted', '', 240));

// Rows and cells written once for many
$reptPth = makeOds('repeat.ods', '<table:table table:name="R">'
	.'<table:table-row>'.odsText('a').'<table:table-cell table:number-columns-repeated="2"/>'.odsText('d').odsText('x', ' table:number-columns-repeated="3"').'<table:table-cell table:number-columns-repeated="1017"/></table:table-row>'
	.'<table:table-row table:number-rows-repeated="2"><table:table-cell table:number-columns-repeated="1024"/></table:table-row>'
	.'<table:table-row table:number-rows-repeated="3">'.odsText('same').'</table:table-row>'
	.'<table:table-row table:number-rows-repeated="1048569"><table:table-cell table:number-columns-repeated="1024"/></table:table-row>'
	.'</table:table>');
checkIdentical('repeated cells and rows are given as many times; the empty ones ending a row or a sheet are left out', odsRows($reptPth), Array(Array('a', null, null, 'd', 'x', 'x', 'x'), Array(), Array(), Array('same'), Array('same'), Array('same')));
checkIdentical('rows written in groups are read in order', odsRows(makeOds('groups.ods', '<table:table table:name="G"><table:table-header-rows><table:table-row>'.odsText('head').'</table:table-row></table:table-header-rows><table:table-row-group><table:table-row>'.odsText('in group').'</table:table-row><table:table-row-group><table:table-row>'.odsText('deeper').'</table:table-row></table:table-row-group></table:table-row-group><table:table-row>'.odsText('last').'</table:table-row></table:table>')), Array(Array('head'), Array('in group'), Array('deeper'), Array('last')));
checkPregMatch('a sheet too sparse to be laid out is refused', odsRead(makeOds('sparse.ods', '<table:table table:name="S"><table:table-row table:number-rows-repeated="1000000">'.odsText('x', ' table:number-columns-repeated="1000"').'</table:table-row></table:table>')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid spreadsheet: a sheet too sparse to be laid out/');
checkPregMatch('...and so is one with a value far after empty cells', odsRead(makeOds('far.ods', '<table:table table:name="S"><table:table-row table:number-rows-repeated="1000001"><table:table-cell/></table:table-row><table:table-row>'.odsText('x').'</table:table-row></table:table>')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid spreadsheet: a sheet too sparse to be laid out/');

// Merged cells and hidden sheets
$mrgdXml = '<table:table table:name="Season" table:style-name="ta1">'
	.'<table:table-row>'.odsText('Season 2026', ' table:number-columns-spanned="3" table:number-rows-spanned="1"').'<table:covered-table-cell table:number-columns-repeated="2"/>'.odsText('Notes').'</table:table-row>'
	.'<table:table-row>'.odsText('Title').odsText('Venue').odsText('Seats').odsText('long', ' table:number-columns-spanned="1" table:number-rows-spanned="2"').'</table:table-row>'
	.'<table:table-row>'.odsText('Alpha').odsText('Hall A').'<table:table-cell office:value-type="float" office:value="120"/><table:covered-table-cell/></table:table-row>'
	.'<table:table-row><table:table-cell table:number-columns-spanned="2" table:number-rows-spanned="1"/><table:covered-table-cell/>'.odsText('end').'</table:table-row>'
	.'</table:table>'
	.'<table:table table:name="Hidden" table:style-name="ta2"><table:table-row>'.odsText('secret').'</table:table-row></table:table>'
	.'<table:table table:name="Last" table:style-name="ta1"><table:table-row>'.odsText('shown').'</table:table-row></table:table>';
$mrgdPth = makeOds('merged.ods', $mrgdXml, '<style:style style:name="ta1" style:family="table"><style:table-properties table:display="true"/></style:style><style:style style:name="ta2" style:family="table"><style:table-properties table:display="false"/></style:style>');
$mrgdLst = Array(Array('atrow'=>0, 'atcol'=>0, 'rowsp'=>1, 'colsp'=>3), Array('atrow'=>1, 'atcol'=>3, 'rowsp'=>2, 'colsp'=>1), Array('atrow'=>3, 'atcol'=>0, 'rowsp'=>1, 'colsp'=>2));
checkIdentical('merged cells: the value stays in the first cell, the areas are told in attrs', odsRead($mrgdPth)['items'][0], Array(
	'title'=>'Season', 'attrs'=>Array('shown'=>true, 'merge'=>$mrgdLst),
	'cells'=>Array(Array('Season 2026', null, null, 'Notes'), Array('Title', 'Venue', 'Seats', 'long'), Array('Alpha', 'Hall A', 120), Array(null, null, 'end'))
));
checkIdentical('merge fills: the value is copied into every cell of its area; an empty area stays empty', odsRead($mrgdPth, Array('merge'=>'fills'))['items'][0], Array(
	'title'=>'Season', 'attrs'=>Array('shown'=>true, 'merge'=>$mrgdLst),
	'cells'=>Array(Array('Season 2026', 'Season 2026', 'Season 2026', 'Notes'), Array('Title', 'Venue', 'Seats', 'long'), Array('Alpha', 'Hall A', 120, 'long'), Array(null, null, 'end'))
));
checkIdentical('merge fills with heads: every column under a merged name has one', odsRead($mrgdPth, Array('merge'=>'FILLS', 'heads'=>true, 'sheet'=>'Season'))['infos']['heads'], Array('Season'=>Array('Season 2026', 'Season 2026_2', 'Season 2026_3', 'Notes')));
checkPregMatch('merge: a code that is not a known one', odsRead($mrgdPth, Array('merge'=>'split')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid workbook option: unknown merge split/');
checkIdentical('hidden sheets are read, and told in attrs', runCatchThrown(function() use ($mrgdPth){
	$envlope = odsRead($mrgdPth);
	return Array($envlope['infos']['names'], array_column($envlope['items'], 'title'), array_column(array_column($envlope['items'], 'attrs'), 'shown'));
}), Array(Array('Season', 'Hidden', 'Last'), Array('Season', 'Hidden', 'Last'), Array(true, false, true)));
checkIdentical('shown: hidden sheets are left out, the names still list them, and one asked for by name is read', runCatchThrown(function() use ($mrgdPth){
	$envlope = odsRead($mrgdPth, Array('shown'=>true));
	$hidnEnv = odsRead($mrgdPth, Array('shown'=>true, 'sheet'=>'Hidden'));
	return Array($envlope['infos']['names'], array_column($envlope['items'], 'title'), $hidnEnv['items'][0]['cells'], $hidnEnv['items'][0]['attrs']['shown']);
}), Array(Array('Season', 'Hidden', 'Last'), Array('Season', 'Last'), Array(Array('secret')), false));

// The same in an Excel workbook
const XLSXMAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const XLSXRELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
$xlsxPth = inTemporaryDir('merged.xlsx');
$xlsxArc = new ZipArchive();
$xlsxArc->open($xlsxPth, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$xlsxArc->addFromString('xl/workbook.xml', '<workbook xmlns="'.XLSXMAIN.'" xmlns:r="'.XLSXRELS.'"><sheets><sheet name="Season" sheetId="1" r:id="rId1"/><sheet name="Hidden" sheetId="2" state="hidden" r:id="rId2"/><sheet name="Deep" sheetId="3" state="veryHidden" r:id="rId2"/><sheet name="Last" sheetId="4" state="visible" r:id="rId2"/></sheets></workbook>');
$xlsxArc->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.XLSXRELS.'/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="'.XLSXRELS.'/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
$xlsxArc->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="'.XLSXMAIN.'"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Season 2026</t></is></c><c r="D1" t="inlineStr"><is><t>Notes</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Title</t></is></c><c r="D2"><v>7</v></c></row></sheetData><mergeCells count="4"><mergeCell ref="A1:C1"/><mergeCell ref="$D$2:$D$4"/><mergeCell ref="F6:G7"/><mergeCell ref="nonsense"/></mergeCells></worksheet>');
$xlsxArc->addFromString('xl/worksheets/sheet2.xml', '<worksheet xmlns="'.XLSXMAIN.'"><sheetData><row r="1"><c r="A1"><v>1</v></c></row></sheetData></worksheet>');
$xlsxArc->close();
$xlsxMrg = Array(Array('atrow'=>0, 'atcol'=>0, 'rowsp'=>1, 'colsp'=>3), Array('atrow'=>1, 'atcol'=>3, 'rowsp'=>3, 'colsp'=>1), Array('atrow'=>5, 'atcol'=>5, 'rowsp'=>2, 'colsp'=>2));
checkIdentical('a workbook: merged cells kept, then filled down past its last row', runCatchThrown(function() use ($xlsxPth){
	$prsrObj = new Xlsx();
	return Array($prsrObj->parseFluxArray(Fluxd::openLocalFluxd($xlsxPth), Array('sheet'=>1))['items'][0], $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($xlsxPth), Array('sheet'=>1, 'merge'=>'fills'))['items'][0]['cells']);
}), Array(
	Array('title'=>'Season', 'attrs'=>Array('shown'=>true, 'merge'=>$xlsxMrg), 'cells'=>Array(Array('Season 2026', null, null, 'Notes'), Array('Title', null, null, 7))),
	Array(Array('Season 2026', 'Season 2026', 'Season 2026', 'Notes'), Array('Title', null, null, 7), Array(null, null, null, 7), Array(null, null, null, 7))
));
checkIdentical('a workbook: hidden sheets told in attrs, and left out with shown', runCatchThrown(function() use ($xlsxPth){
	$prsrObj = new Xlsx();
	$envlope = $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($xlsxPth));
	return Array(array_column(array_column($envlope['items'], 'attrs'), 'shown'), array_column($prsrObj->parseFluxArray(Fluxd::openLocalFluxd($xlsxPth), Array('shown'=>true))['items'], 'title'), $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($xlsxPth), Array('shown'=>true))['infos']['names']);
}), Array(Array(true, false, false, true), Array('Season', 'Last'), Array('Season', 'Hidden', 'Deep', 'Last')));
checkPregMatch('merged cells too many to be filled are refused', runCatchThrown(function(){
	$hugePth = inTemporaryDir('huge.xlsx');
	$hugeArc = new ZipArchive();
	$hugeArc->open($hugePth, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	$hugeArc->addFromString('xl/workbook.xml', '<workbook xmlns="'.XLSXMAIN.'" xmlns:r="'.XLSXRELS.'"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
	$hugeArc->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.XLSXRELS.'/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
	$hugeArc->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="'.XLSXMAIN.'"><sheetData><row r="1"><c r="A1"><v>1</v></c></row></sheetData><mergeCells><mergeCell ref="A1:XFD1048576"/></mergeCells></worksheet>');
	$hugeArc->close();
	return (new Xlsx())->parseFluxArray(Fluxd::openLocalFluxd($hugePth), Array('merge'=>'fills'));
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid workbook: merged cells too many to be filled/');

// What is refused
checkPregMatch('a text document under the name of a spreadsheet', odsRead(TST_DIR.DS.'fixtures'.DS.'office'.DS.'sample.odt'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: the document is not a spreadsheet/');
checkPregMatch('an archive that is not an OpenDocument file', odsRead(TST_DIR.DS.'fixtures'.DS.'office'.DS.'SampleSS.xlsx'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part content\.xml is missing/');
checkPregMatch('a spreadsheet protected by a password', odsRead(makeOds('locked.ods', '', '', Array('content.xml'=>random_bytes(200), 'META-INF/manifest.xml'=>'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"><manifest:encryption-data manifest:checksum-type="SHA1/1K" manifest:checksum="x"/></manifest:file-entry></manifest:manifest>'))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: the document is protected by a password/');
checkPregMatch('an Office document protected by a password is not an archive any more, and is told so', runCatchThrown(function(){
	return (new Xlsx())->parseDataArray("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 600));
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: the document is protected by a password, or saved in an older Office format/');
checkPregMatch('a content that is not valid XML', odsRead(makeOds('broken.ods', '', '', Array('content.xml'=>'<a><b></a>'))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part content\.xml: Invalid XML content/');
checkIdentical('no PHP warning along the way', takePhpNotices(), Array());

// Through the index and Dcmnt
checkIdentical('index: spreadsheets by extension and by content type', Array(get_class(Index::forExtnsParser('ODS')), get_class(Index::findTypeParser('application/vnd.oasis.opendocument.spreadsheet'))), Array('nyu\Data\Parsers\Odsx', 'nyu\Data\Parsers\Odsx'));
checkIdentical('a spreadsheet through Dcmnt, with and without its extension', runCatchThrown(function() use ($smplPth){
	$barePth = inTemporaryDir('spreadsheet');
	copy($smplPth, $barePth);
	$docFile = new Dcmnt($smplPth);
	$bareDoc = new Dcmnt($barePth);
	return Array($docFile->readFileDetail('sig'), $docFile->readFileDetail('shp'), $docFile->readFileDetail()[0]['cells'][4], $bareDoc->readFileDetail('ext'), $bareDoc->readFileDetail('inf')['names']);
}), Array('ods', 'sheet', Array(null, 'Total', 200), 'ods', Array('Shows', 'Notes')));

finishChecking();
