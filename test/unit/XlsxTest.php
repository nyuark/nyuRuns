<?php

// Data\Parsers\Xlsx : Excel workbooks read into sheets.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Fluxd;
use nyu\Data\Parsers\Index;
use nyu\Data\Parsers\Xlsx;
use nyu\Data\Writers\Html;

if( !class_exists('ZipArchive') ){
	recordSkipping('workbooks', 'the zip extension is not loaded');
	finishChecking();
}

const XLSXMAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const XLSXRELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

/**
 * Builds a workbook in the temporary folder.
 *
 * @param String $archivName Name of the file to make.
 * @param Array  $sheetsData Its sheets, name => XML of the rows.
 * @param Array  $extraParts Parts to add or to replace, name => content; null leaves a part out.
 * @return String The path of the workbook.
 */
function makeXlsx( String $archivName, Array $sheetsData, Array $extraParts=Array() ): String {
	$shtsXml = '';
	$relsXml = '<Relationship Id="rIdT" Type="'.XLSXRELS.'/sharedStrings" Target="sharedStrings.xml"/><Relationship Id="rIdS" Type="'.XLSXRELS.'/styles" Target="styles.xml"/>';
	$partLst = Array();
	$shtNmbr = 0;
	foreach( $sheetsData as $shtNme=>$rowsXml ){
		$shtNmbr++;
		$shtsXml .= '<sheet name="'.htmlspecialchars(strval($shtNme)).'" sheetId="'.$shtNmbr.'" r:id="rId'.$shtNmbr.'"/>';
		$relsXml .= '<Relationship Id="rId'.$shtNmbr.'" Type="'.XLSXRELS.'/worksheet" Target="worksheets/sheet'.$shtNmbr.'.xml"/>';
		$partLst['xl/worksheets/sheet'.$shtNmbr.'.xml'] = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="'.XLSXMAIN.'"><sheetData>'.$rowsXml.'</sheetData></worksheet>';
	}
	$partLst = Array(
		'_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.XLSXRELS.'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
		'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="'.XLSXMAIN.'" xmlns:r="'.XLSXRELS.'"><sheets>'.$shtsXml.'</sheets></workbook>',
		'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relsXml.'</Relationships>',
		'xl/sharedStrings.xml'=>'<sst xmlns="'.XLSXMAIN.'"><si><t>Title</t></si><si><t>Les Enchaînés</t></si><si><r><t>Rich </t></r><r><rPr><b/></rPr><t xml:space="preserve">text </t></r><r><t>here</t></r><rPh><t>aid</t></rPh></si><si><t xml:space="preserve">  kept  </t></si></sst>',
		// Styles: 0 plain, 1 a date (format 14), 2 a date and a time (22), 3 a format of the workbook that is a date, 4 one that is not, 5 a time (20)
		'xl/styles.xml'=>'<styleSheet xmlns="'.XLSXMAIN.'"><numFmts><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="#,##0.00 &quot;days&quot;"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="22"/><xf numFmtId="164"/><xf numFmtId="165"/><xf numFmtId="20"/></cellXfs></styleSheet>'
	) + $partLst;
	$zipPath = inTemporaryDir($archivName);
	$zipArch = new ZipArchive();
	$zipArch->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	foreach( array_merge($partLst, $extraParts) as $prtNme=>$prtTxt ){
		if( $prtTxt!==null ){
			$zipArch->addFromString($prtNme, $prtTxt);
		}
	}
	$zipArch->close();
	return $zipPath;
}

/**
 * Parses a workbook and returns its envelope, or the reason it was refused.
 *
 * @param String $sourcePath Path of the workbook.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The envelope, or a 'THROWN ...' string.
 */
function xlsRead( String $sourcePath, Array $parseOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($sourcePath, $parseOptns){
		$prsrObj = new Xlsx();
		return $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($sourcePath), $parseOptns);
	});
}

/**
 * Parses a workbook and returns the rows of its first sheet.
 *
 * @param String $sourcePath Path of the workbook.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The rows, or a 'THROWN ...' string.
 */
function xlsRows( String $sourcePath, Array $parseOptns=Array() ): Mixed {
	$envlope = xlsRead($sourcePath, $parseOptns);
	return is_array($envlope) ? $envlope['items'][0]['cells'] : $envlope;
}

// A workbook saved by Excel
$smplPth = TST_DIR.DS.'fixtures'.DS.'office'.DS.'SampleSS.xlsx';
checkIdentical('a workbook saved by Excel: its sheets, their names, their cells at their place, a formula as its result', xlsRead($smplPth), Array(
	'shape'=>'sheet',
	'infos'=>Array('names'=>Array('First Sheet', 'Sheet Number 2', 'Sheet3'), 'count'=>3, 'heads'=>Array()),
	'items'=>Array(
		Array('title'=>'First Sheet', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('Test spreadsheet'), Array('2nd row', '2nd row 2nd column'), Array(), Array('This one is red'))),
		Array('title'=>'Sheet Number 2', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('Start of 2nd sheet'), Array('Sheet 2 row 2'), Array(), Array("I'm in bold blue, on a yellow background"), Array(), Array('cb=1', 'cb=10', 'cb=2', 'cb=sum'), Array(1, 10, 2, 13))),
		Array('title'=>'Sheet3', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array())
	)
));
checkIdentical('the same workbook given as bytes', runCatchThrown(function() use ($smplPth){ return (new Xlsx())->parseDataArray(file_get_contents($smplPth))['items'][0]['cells'][1]; }), Array('2nd row', '2nd row 2nd column'));
checkIdentical('an empty content gives no sheet', runCatchThrown(function(){ return (new Xlsx())->parseDataArray(''); }), Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array()));

// One sheet
checkIdentical('sheet: one sheet by its name, or by its position', Array(
	array_column(xlsRead($smplPth, Array('sheet'=>'Sheet Number 2'))['items'], 'title'), xlsRead($smplPth, Array('sheet'=>'Sheet Number 2'))['infos']['names'],
	array_column(xlsRead($smplPth, Array('sheet'=>1))['items'], 'title'), array_column(xlsRead($smplPth, Array('sheet'=>'3'))['items'], 'title')
), Array(Array('Sheet Number 2'), Array('First Sheet', 'Sheet Number 2', 'Sheet3'), Array('First Sheet'), Array('Sheet3')));
checkIdentical('sheet: a name made of digits is a name first', array_column(xlsRead(makeXlsx('years.xlsx', Array('2026'=>'', '2'=>'', 'Notes'=>'')), Array('sheet'=>'2'))['items'], 'title'), Array('2'));
checkPregMatch('sheet: a sheet that is not in the workbook', xlsRead($smplPth, Array('sheet'=>'Nowhere')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid workbook option: no sheet Nowhere/');
checkPregMatch('sheet: a position past the last sheet', xlsRead($smplPth, Array('sheet'=>4)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid workbook option: no sheet 4/');

// Values
$valsPth = makeXlsx('values.xlsx', Array('Values'=>
	'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
	.'<row r="2"><c r="A2"><v>120</v></c><c r="B2"><v>-7</v></c><c r="C2"><v>12.5</v></c><c r="D2"><v>1.5E-3</v></c><c r="E2"><v>12345678901234567890</v></c><c r="F2" t="n"><v>0</v></c></row>'
	.'<row r="3"><c r="A3" t="b"><v>1</v></c><c r="B3" t="b"><v>0</v></c><c r="C3" t="e"><v>#DIV/0!</v></c><c r="D3" t="str"><f>A1&amp;"x"</f><v>Titlex</v></c><c r="E3" t="inlineStr"><is><t>inline</t></is></c><c r="F3" t="inlineStr"><is><r><t>in</t></r><r><t>line</t></r></is></c></row>'
	.'<row r="4"><c r="A4"/><c r="B4" s="1"/><c r="C4" t="s"><v>99</v></c><c r="D4"><f>1/3</f></c><c r="E4" t="str"><v></v></c><c r="F4"><v>x</v></c></row>'
));
checkIdentical('texts: shared, with accents, written in pieces, with their spaces', xlsRows($valsPth)[0], Array('Title', 'Les Enchaînés', 'Rich text here', '  kept  '));
checkIdentical('numbers: whole ones as Int, the others as Float', xlsRows($valsPth)[1], Array(120, -7, 12.5, 0.0015, 1.2345678901234567E+19, 0));
checkIdentical('booleans, an error, the text of a formula, texts written in the cell itself', xlsRows($valsPth)[2], Array(true, false, '#DIV/0!', 'Titlex', 'inline', 'inline'));
checkIdentical('cells without value are empty; a text the workbook does not have is empty text', xlsRows($valsPth)[3], Array(null, null, '', null, '', 'x'));

// Dates
$datePth = makeXlsx('dates.xlsx', Array('Dates'=>
	'<row r="1"><c r="A1" s="1"><v>46384</v></c><c r="B1" s="2"><v>46384.854166666664</v></c><c r="C1" s="3"><v>46384</v></c><c r="D1" s="4"><v>46384</v></c><c r="E1"><v>46384</v></c></row>'
	.'<row r="2"><c r="A2" s="5"><v>0.854166666664</v></c><c r="B2" s="1"><v>1</v></c><c r="C2" s="1"><v>25569</v></c><c r="D2" s="2"><v>46384.99999999</v></c><c r="E2" t="d"><v>2026-12-28T20:30:00</v></c><c r="F2" s="1" t="s"><v>0</v></c></row>'
));
checkIdentical('a number shown as a date is given as ISO text; the same number shown as a number stays one', xlsRows($datePth)[0], Array('2026-12-28', '2026-12-28 20:30:00', '2026-12-28', 46384, 46384));
checkIdentical('a time alone, the first days, a second short of midnight, a date written as one, a text styled as a date', xlsRows($datePth)[1], Array('20:30:00', '1899-12-31', '1970-01-01', '2026-12-29', '2026-12-28T20:30:00', 'Title'));
checkIdentical('a workbook counting its days from 1904', xlsRows(makeXlsx('mac.xlsx', Array('S'=>'<row r="1"><c r="A1" s="1"><v>44922</v></c></row>'), Array('xl/workbook.xml'=>'<workbook xmlns="'.XLSXMAIN.'" xmlns:r="'.XLSXRELS.'"><workbookPr date1904="1"/><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>'))), Array(Array('2026-12-28')));

// The place of the cells
$gridPth = makeXlsx('grid.xlsx', Array('Grid'=>
	'<row r="2"><c r="B2"><v>1</v></c><c r="D2"><v>2</v></c><c r="F2"/></row>'
	.'<row r="5"><c r="AA5"><v>3</v></c></row>'
	.'<row r="6"><c r="A6"/></row><row r="7"/>',
	'Free'=>'<row><c><v>1</v></c><c><v>2</v></c></row><row><c><v>3</v></c></row>'
));
checkIdentical('cells stand at their place: rows and cells left out are given empty, those ending a row or a sheet are not', runCatchThrown(function() use ($gridPth){
	$rowsLst = xlsRows($gridPth);
	return Array(count($rowsLst), $rowsLst[0], $rowsLst[1], $rowsLst[2], $rowsLst[3], count($rowsLst[4]), $rowsLst[4][25], $rowsLst[4][26]);
}), Array(5, Array(), Array(null, 1, null, 2), Array(), Array(), 27, null, 3));
checkIdentical('cells that do not tell their place follow one another', xlsRead($gridPth, Array('sheet'=>'Free'))['items'][0]['cells'], Array(Array(1, 2), Array(3)));
checkPregMatch('a sheet too sparse to be laid out is refused', xlsRead(makeXlsx('sparse.xlsx', Array('S'=>'<row r="1"><c r="A1"><v>1</v></c></row><row r="1048576"><c r="XFD1048576"><v>2</v></c></row>'))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid workbook: a sheet too sparse to be laid out/');

// Column names
$headPth = makeXlsx('heads.xlsx', Array(
	'Shows'=>'<row r="1"><c r="A1" t="inlineStr"><is><t>Title</t></is></c><c r="B1" t="inlineStr"><is><t>Seats</t></is></c><c r="C1" t="inlineStr"><is><t>Title</t></is></c></row><row r="2"><c r="A2" t="s"><v>1</v></c><c r="B2"><v>80</v></c></row><row r="3"><c r="B3"><v>120</v></c><c r="D3"><v>9</v></c></row>',
	'Empty'=>''
));
checkIdentical('heads: the first row of each sheet names its columns', xlsRead($headPth, Array('heads'=>true)), Array(
	'shape'=>'sheet',
	'infos'=>Array('names'=>Array('Shows', 'Empty'), 'count'=>2, 'heads'=>Array('Shows'=>Array('Title', 'Seats', 'Title_3'), 'Empty'=>Array())),
	'items'=>Array(
		Array('title'=>'Shows', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('Title'=>'Les Enchaînés', 'Seats'=>80, 'Title_3'=>null), Array('Title'=>null, 'Seats'=>120, 'Title_3'=>null, '4'=>9))),
		Array('title'=>'Empty', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array())
	)
));

// Workbooks written another way
checkIdentical('a workbook whose elements carry a prefix', xlsRows(makeXlsx('prefix.xlsx', Array(), Array(
	'xl/workbook.xml'=>'<x:workbook xmlns:x="'.XLSXMAIN.'" xmlns:rel="'.XLSXRELS.'"><x:sheets><x:sheet name="P" sheetId="1" rel:id="rIdP"/></x:sheets></x:workbook>',
	'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdP" Type="'.XLSXRELS.'/worksheet" Target="/xl/worksheets/p.xml"/><Relationship Id="rIdT" Type="'.XLSXRELS.'/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
	'xl/worksheets/p.xml'=>'<x:worksheet xmlns:x="'.XLSXMAIN.'"><x:sheetData><x:row r="1"><x:c r="A1" t="s"><x:v>1</x:v></x:c><x:c r="B1"><x:v>5</x:v></x:c></x:row></x:sheetData></x:worksheet>',
	'xl/sharedStrings.xml'=>'<x:sst xmlns:x="'.XLSXMAIN.'"><x:si><x:t>zero</x:t></x:si><x:si><x:r><x:t>one</x:t></x:r></x:si></x:sst>'
))), Array(Array('one', 5)));
checkIdentical('a workbook kept elsewhere in its archive, without shared texts nor styles', xlsRows(makeXlsx('moved.xlsx', Array(), Array(
	'_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.XLSXRELS.'/officeDocument" Target="book/main.xml"/></Relationships>',
	'xl/workbook.xml'=>null, 'xl/_rels/workbook.xml.rels'=>null, 'xl/sharedStrings.xml'=>null, 'xl/styles.xml'=>null,
	'book/main.xml'=>'<workbook xmlns="'.XLSXMAIN.'" xmlns:r="'.XLSXRELS.'"><sheets><sheet name="M" sheetId="1" r:id="rId1"/></sheets></workbook>',
	'book/_rels/main.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.XLSXRELS.'/worksheet" Target="../data/s.xml"/></Relationships>',
	'data/s.xml'=>'<worksheet xmlns="'.XLSXMAIN.'"><sheetData><row r="1"><c r="A1" s="1"><v>46384</v></c><c r="B1" t="inlineStr"><is><t>ok</t></is></c></row></sheetData></worksheet>'
))), Array(Array(46384, 'ok')));

// What is refused
checkPregMatch('an archive that is not a workbook', xlsRead(TST_DIR.DS.'fixtures'.DS.'office'.DS.'sample.ods'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part xl\/workbook\.xml is missing/');
checkPregMatch('a content that is not an archive', runCatchThrown(function(){ return (new Xlsx())->parseDataArray('Title,Seats'); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: Not a zip archive/');
checkPregMatch('a workbook naming a sheet it does not hold', xlsRead(makeXlsx('lost.xlsx', Array('S'=>''), Array('xl/worksheets/sheet1.xml'=>null))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part xl\/worksheets\/sheet1\.xml is missing/');
checkPregMatch('a sheet that is not valid XML', xlsRead(makeXlsx('broken.xlsx', Array('S'=>'<row><c></row>'))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part xl\/worksheets\/sheet1\.xml: Invalid XML content/');
checkPregMatch('a sheet reading a local file through an entity', xlsRead(makeXlsx('entity.xlsx', Array('S'=>''), Array('xl/worksheets/sheet1.xml'=>'<?xml version="1.0"?><!DOCTYPE w [<!ENTITY x SYSTEM "file:///etc/passwd">]><worksheet xmlns="'.XLSXMAIN.'"><sheetData><row><c t="inlineStr"><is><t>&x;</t></is></c></row></sheetData></worksheet>'))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part xl\/worksheets\/sheet1\.xml: Invalid XML content: entity declarations are not accepted/');
checkPregMatch('a sheet larger than the limit option', xlsRead($smplPth, Array('limit'=>700)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: Entry larger than the 700 bytes allowed/');
checkIdentical('one parser, several workbooks', runCatchThrown(function() use ($smplPth, $datePth){
	$prsrObj = new Xlsx();
	$frstRow = $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($datePth))['items'][0]['cells'][0][0];
	return Array($frstRow, $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($smplPth))['items'][1]['cells'][6]);
}), Array('2026-12-28', Array(1, 10, 2, 13)));
checkIdentical('no PHP warning along the way', takePhpNotices(), Array());

// Through the index, Dcmnt and the HTML writer
checkIdentical('index: workbooks by extension and by content type', Array(get_class(Index::forExtnsParser('XLSX')), get_class(Index::findTypeParser('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))), Array('nyu\Data\Parsers\Xlsx', 'nyu\Data\Parsers\Xlsx'));
checkIdentical('a workbook through Dcmnt', runCatchThrown(function() use ($smplPth){
	$docFile = new Dcmnt($smplPth);
	return Array($docFile->readFileDetail('sig'), $docFile->readFileDetail('shp'), $docFile->readFileDetail('inf')['names'], $docFile->readFileDetail()[1]['cells'][6]);
}), Array('zip', 'sheet', Array('First Sheet', 'Sheet Number 2', 'Sheet3'), Array(1, 10, 2, 13)));
checkPregMatch('text under the name of a workbook is refused', runCatchThrown(function(){
	$docPath = inTemporaryDir('fake.xlsx');
	file_put_contents($docPath, '<?xml version="1.0"?><workbook/>');
	return new Dcmnt($docPath);
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: fake\.xlsx: the content is not what the name announces/');
checkIdentical('a sheet read then written as HTML', runCatchThrown(function() use ($smplPth){
	return (new Html())->renderDocument((new Xlsx())->parseFluxArray(Fluxd::openLocalFluxd($smplPth), Array('sheet'=>1)));
}), "<table>\n<caption>First Sheet</caption>\n<tbody>\n<tr><td>Test spreadsheet</td></tr>\n<tr><td>2nd row</td><td>2nd row 2nd column</td></tr>\n<tr></tr>\n<tr><td>This one is red</td></tr>\n</tbody>\n</table>\n");

finishChecking();
