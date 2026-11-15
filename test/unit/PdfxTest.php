<?php

// Data\Writers\Pdfx and Data\Parsers\Pdfx : contents written as PDF
// documents, and PDF documents read into text blocks.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Shape;
use nyu\Data\Parsers\Index as ReadIndex;
use nyu\Data\Parsers\Pdfx as PdfxParser;
use nyu\Data\Writers\Index as WriteIndex;
use nyu\Data\Writers\Pdfx;

if( !class_exists('\Dompdf\Dompdf') || !class_exists('\Smalot\PdfParser\Parser') ){
	recordSkipping('PDF documents', 'the dompdf/dompdf and smalot/pdfparser libraries are not installed');
	finishChecking();
}
if( !extension_loaded('mbstring') ){
	// Without the extension, both are refused with a message saying what is missing
	checkPregMatch('writing asks for the mbstring extension', runCatchThrown(function(){ return (new Pdfx())->renderDocument(Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Writing PDF documents needs the mbstring extension of PHP/');
	checkPregMatch('reading asks for the mbstring extension', runCatchThrown(function(){ return (new PdfxParser())->parseDataArray("%PDF-1.7\n"); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Reading PDF documents needs the mbstring extension of PHP/');
	recordSkipping('PDF documents', 'the mbstring extension is not loaded');
	finishChecking();
}

/**
 * Builds a block holding one plain piece of text.
 *
 * @param String $blockLabel Kind of the block.
 * @param String $textString Its text.
 * @param Array  $attrsArray Its attributes.
 * @return Array The block.
 */
function textBlk( String $blockLabel, String $textString, Array $attrsArray=Array() ): Array {
	return Shape::makeBlockArray($blockLabel, Array(Shape::makeSpanOfText($textString)), Array(), $attrsArray);
}

/**
 * Writes a content as PDF, or returns the reason it was refused.
 *
 * @param Array  $itemsArray The content.
 * @param Array  $writeOptns Options of the writer.
 * @param Array  $infosArray Details of the content.
 * @param String $shapeLabel Its shape.
 * @return String The bytes of the document, or a 'THROWN ...' string.
 */
function pdfOut( Array $itemsArray, Array $writeOptns=Array(), Array $infosArray=Array(), String $shapeLabel='block' ): String {
	return runCatchThrown(function() use ($itemsArray, $writeOptns, $infosArray, $shapeLabel){
		return (new Pdfx())->renderDocument(Array('shape'=>$shapeLabel, 'infos'=>$infosArray, 'items'=>$itemsArray), $writeOptns);
	});
}

/**
 * Reads a PDF document, or returns the reason it was refused.
 *
 * @param String $rawContent The bytes of the document.
 * @return mixed The envelope, or a 'THROWN ...' string.
 */
function pdfRead( String $rawContent ): Mixed {
	return runCatchThrown(function() use ($rawContent){
		return (new PdfxParser())->parseDataArray($rawContent);
	});
}

/**
 * Gives the size of the first page of a PDF document, as it tells it.
 *
 * @param String $rawContent The bytes of the document.
 * @return Array Its width and its height, in whole points.
 */
function pageSize( String $rawContent ): Array {
	$sizePrt = Array();
	preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $rawContent, $sizePrt);
	return Array(intval(round(floatval($sizePrt[1] ?? 0))), intval(round(floatval($sizePrt[2] ?? 0))));
}

$showBlk = Array(
	textBlk('headline', 'Les Enchaînés, été', Array('level'=>1, 'style'=>'heading 1')),
	Shape::makeBlockArray('paragrph', Array(Shape::makeSpanOfText('First paragraph, with '), Shape::makeSpanOfText('bold', Array('boldFace')), Shape::makeSpanOfText(' and a '), Shape::makeSpanOfText('link', Array(), 'https://nyuark.test/a'), Shape::makeSpanOfText('.'))),
	Shape::makeBlockArray('itemList', Array(), Array(textBlk('listItem', 'Music'), textBlk('listItem', 'Dance'))),
	Shape::makeBlockArray('ruleLine', Array(), Array(), Array('break'=>true)),
	textBlk('paragrph', 'Second page.'),
	Shape::makeBlockArray('ruleLine', Array(), Array(), Array('break'=>true)),
	textBlk('paragrph', 'Third page: 12 € & <b>not markup</b>.')
);
$showInf = Array('title'=>'Alpha Night', 'frmat'=>Array('heading 1'=>Array('class'=>'heading-1', 'fonts'=>'Calibri', 'sizes'=>18, 'marks'=>Array('boldFace'), 'align'=>'alignCtr')));
$showPdf = pdfOut($showBlk, Array(), $showInf);

// Written, then read
checkIdentical('a content written as PDF is a PDF document', Array(substr($showPdf, 0, 5), strlen($showPdf)>2000, substr(rtrim($showPdf), -5)), Array('%PDF-', true, '%%EOF'));
checkIdentical('read back: its pages, a page break between two of them, its text with its accents, what was markup-like as text', runCatchThrown(function() use ($showPdf){
	$envlope = pdfRead($showPdf);
	return is_array($envlope) ? Array($envlope['shape'], array_column($envlope['items'], 'block'), array_column($envlope['items'], 'plain'), $envlope['items'][1]['attrs']) : $envlope;
}), Array('block', Array('paragrph', 'ruleLine', 'paragrph', 'ruleLine', 'paragrph'), Array("Les Enchaînés, été\nFirst paragraph, with bold and a link.\nMusic\nDance", '', 'Second page.', '', 'Third page: 12 € & <b>not markup</b>.'), Array('break'=>true)));
checkIdentical('read back: its details, with its title and the number of its pages', runCatchThrown(function() use ($showPdf){
	$infoArr = pdfRead($showPdf)['infos'];
	return Array($infoArr['title'], $infoArr['authr'], $infoArr['pages'], $infoArr['props']['Title'] ?? null, $infoArr['props']['Pages'] ?? null, array_keys($infoArr));
}), Array('Alpha Night', '', 3, 'Alpha Night', 3, Array('title', 'authr', 'pages', 'props')));
checkIdentical('every block read has its five keys, every span its four', pdfRead($showPdf)['items'][2], Array('block'=>'paragrph', 'attrs'=>Array(), 'spans'=>Array(Array('texts'=>'Second page.', 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'Second page.'));

// Paper
checkIdentical('paper: A4 upright unless told otherwise; the other sizes; lying on its long side', Array(pageSize($showPdf), pageSize(pdfOut($showBlk, Array('paper'=>'isoPgeA5'))), pageSize(pdfOut($showBlk, Array('paper'=>'USLETTER'))), pageSize(pdfOut($showBlk, Array('paper'=>'isoPgeA4', 'lands'=>true))), pageSize(pdfOut($showBlk, Array('paper'=>'legalUsa'))), pageSize(pdfOut($showBlk, Array('paper'=>'isoPgeA3')))), Array(Array(595, 842), Array(420, 595), Array(612, 792), Array(842, 595), Array(612, 1008), Array(842, 1191)));
checkPregMatch('paper: a size that is not a known one is refused', pdfOut($showBlk, Array('paper'=>'huge')), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Invalid PDF option: unknown paper huge/');

// Other shapes
checkIdentical('a sheet is written as a table, its hidden sheets left out when told so; read back, the cells of a row stand against one another', runCatchThrown(function(){
	$shetLst = Array(Shape::makeSheetArray('Shows', Array(Array('Title', 'Seats'), Array('Alpha Night', 120))), Shape::makeSheetArray('Hidden', Array(Array('secret')), Array('shown'=>false)));
	$seenTxt = pdfRead(pdfOut($shetLst, Array('heads'=>true), Array(), 'sheet'));
	$lessTxt = pdfRead(pdfOut($shetLst, Array('shown'=>true), Array(), 'sheet'));
	return Array(preg_replace('/\s+/', ' ', Shape::getTextOfDcmnt($seenTxt['items'])), strpos(Shape::getTextOfDcmnt($lessTxt['items']), 'secret'));
}), Array('Shows TitleSeats Alpha Night120 Hidden secret', false));
checkIdentical('values are written as they are shown; an empty content is a document of one empty page', Array(preg_replace('/\s+/', ' ', Shape::getTextOfDcmnt(pdfRead(pdfOut(Array('name'=>'Alpha', 'seats'=>120), Array(), Array(), 'nodes'))['items'])), pdfRead(pdfOut(Array()))['infos']['pages'], pdfRead(pdfOut(Array()))['items']), Array('{ "name": "Alpha", "seats": 120 }', 1, Array()));
checkPregMatch('a shape that is not a known one is refused', pdfOut(Array(), Array(), Array(), 'cloud'), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as PDF: cloud/');

// Nothing is fetched while the document is made
checkIdentical('an image is not fetched, from the network nor from the disk: its description is written in its stead, and a file of the server is not even named', runCatchThrown(function(){
	$scrtPth = inTemporaryDir('secret.png');
	file_put_contents($scrtPth, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
	// The path as Linux gives it, a drive letter left out: every system takes the same way
	$diskSrc = preg_replace('#^[A-Za-z]:#', '', str_replace('\\', '/', $scrtPth));
	$pdfText = pdfOut(Array(textBlk('paragrph', 'Before'), textBlk('imageBox', 'The hall', Array('sourc'=>'http://127.0.0.1:9/never.png')), textBlk('imageBox', 'Local', Array('sourc'=>$diskSrc)), textBlk('paragrph', 'After')));
	return Array(substr($diskSrc, 0, 1), substr($pdfText, 0, 5), preg_replace('/\s+/', ' ', Shape::getTextOfDcmnt(pdfRead($pdfText)['items'])), strpos($pdfText, 'secret'), strpos($pdfText, '/Subtype /Image'));
}), Array('/', '%PDF-', 'Before The hall Local After', false, false));

// Reading: what is refused
checkIdentical('an empty content gives no block', pdfRead(''), Array('shape'=>'block', 'infos'=>Array('title'=>'', 'authr'=>'', 'pages'=>0, 'props'=>Array()), 'items'=>Array()));
checkPregMatch('a content that is not a PDF document', pdfRead('<p>not a pdf</p>'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid PDF content: the content is not a PDF document/');
checkPregMatch('a PDF document cut short, or damaged', pdfRead(substr($showPdf, 0, 400)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid PDF content: /');
checkPregMatch('a PDF document holding nothing but its first line', pdfRead("%PDF-1.7\n%%EOF\n"), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid PDF content: /');
checkIdentical('no PHP warning is left behind, by a refusal or otherwise', takePhpNotices(), Array());

// Through the indexes and Dcmnt
checkIdentical('index: PDF documents by extension and by content type, to read and to write', Array(get_class(ReadIndex::getExtnsParser('PDF')), get_class(ReadIndex::findTypeParser('application/pdf')), get_class(WriteIndex::getExtnsWriter('pdf')), WriteIndex::getMimeForExtn('pdf')), Array('nyu\Data\Parsers\Pdfx', 'nyu\Data\Parsers\Pdfx', 'nyu\Data\Writers\Pdfx', 'application/pdf'));
checkIdentical('a PDF file through Dcmnt, with and without its extension', runCatchThrown(function() use ($showPdf){
	$docPath = inTemporaryDir('show.pdf');
	$barePth = inTemporaryDir('document');
	file_put_contents($docPath, $showPdf);
	file_put_contents($barePth, $showPdf);
	$docFile = new Dcmnt($docPath);
	$bareDoc = new Dcmnt($barePth);
	return Array($docFile->readFileDetail('sig'), $docFile->readFileDetail('shp'), $docFile->readFileDetail('inf')['pages'], $docFile->readFileDetail()[2]['plain'], $bareDoc->readFileDetail('ext'), $bareDoc->readFileDetail('inf')['title']);
}), Array('pdf', 'block', 3, 'Second page.', 'pdf', 'Alpha Night'));
checkPregMatch('text under the name of a PDF document is refused', runCatchThrown(function(){
	$docPath = inTemporaryDir('fake.pdf');
	file_put_contents($docPath, '<?xml version="1.0"?><a/>');
	return new Dcmnt($docPath);
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: fake\.pdf: the content is not what the name announces/');
checkPregMatch('a PDF document under the name of a text file, or of a zipped document, is refused', runCatchThrown(function() use ($showPdf){
	$docPath = inTemporaryDir('show.json');
	$zipPath = inTemporaryDir('show.docx');
	file_put_contents($docPath, $showPdf);
	file_put_contents($zipPath, $showPdf);
	return runCatchThrown(function() use ($docPath){ return new Dcmnt($docPath); }).' | '.runCatchThrown(function() use ($zipPath){ return new Dcmnt($zipPath); });
}), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: show\.json: the content is not what the name announces.* \| THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: show\.docx: the content is not what the name announces/');

finishChecking();
