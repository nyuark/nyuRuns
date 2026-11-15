<?php

// Data\Writers\Docx : tables, notes, what stands at the top and the bottom
// of the pages, fields, named styles, properties, and sheets as tables.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Fluxd;
use nyu\Data\Parsers\Docx as DocxRead;
use nyu\Data\Shape;
use nyu\Data\Writers\Docx;

/**
 * Writes a content as a document, or returns the reason it was refused.
 *
 * @param Array  $itemsArray The blocks, or the sheets.
 * @param Array  $infosArray What is told of the document.
 * @param String $shapeLabel The shape of the content.
 * @return mixed The bytes, or a 'THROWN ...' string.
 */
function dcmWrite( Array $itemsArray, Array $infosArray=Array(), String $shapeLabel='block' ): Mixed {
	return runCatchThrown(function() use ($itemsArray, $infosArray, $shapeLabel){
		return (new Docx())->renderDocument(Array('shape'=>$shapeLabel, 'infos'=>$infosArray, 'items'=>$itemsArray));
	});
}

/**
 * Gives a part of a document, read without the zip extension.
 *
 * @param String $archvBytes The bytes of the archive.
 * @param String $insideName Name of the part.
 * @return String The part, '' when it is not found.
 */
function dcmPart( String $archvBytes, String $insideName ): String {
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

/**
 * Gives the body of the document written from some blocks.
 *
 * @param Array $blocksList The blocks.
 * @return String What stands in the body, before the layout of its pages.
 */
function dcmBody( Array $blocksList ): String {
	$bodyPrt = Array();
	return preg_match('/<w:body>(.*)<w:sectPr>/s', dcmPart(strval(dcmWrite($blocksList)), 'word/document.xml'), $bodyPrt) ? $bodyPrt[1] : '';
}

/**
 * Shows blocks in short: their kind, what tells them apart, their text
 * with its formatting, its links and its fields, and the blocks they hold.
 *
 * @param Array $blocksList The blocks.
 * @return Array A line for each block.
 */
function dcmShow( Array $blocksList ): Array {
	$showLst = Array();
	foreach($blocksList as $blkArr){
		$attrTxt = '';
		foreach(Array('level', 'order', 'align', 'tagid', 'numbr', 'colsp', 'rowsp', 'style') as $atrKey){
			$attrTxt .= (isset($blkArr['attrs'][$atrKey]) && !in_array($blkArr['attrs'][$atrKey], Array('Normal', 'List Paragraph'), true)) ? ' '.$atrKey.'='.json_encode($blkArr['attrs'][$atrKey]) : '';
		}
		$spanTxt = '';
		foreach($blkArr['spans'] as $oneSpn){
			$spanTxt .= '{'.$oneSpn['texts'].(empty($oneSpn['marks']) ? '' : '|'.implode(',', $oneSpn['marks'])).(($oneSpn['links']==='') ? '' : '|'.$oneSpn['links']).(($oneSpn['field']==='') ? '' : '|'.$oneSpn['field']).'}';
		}
		$showLst[] = $blkArr['block'].$attrTxt.(($spanTxt==='') ? '' : ' '.$spanTxt);
		foreach(dcmShow($blkArr['items']) as $subLne){
			$showLst[] = '  '.$subLne;
		}
	}
	return $showLst;
}

/**
 * Writes a content as a document, and reads it back.
 *
 * @param Array  $itemsArray The blocks, or the sheets.
 * @param Array  $infosArray What is told of the document.
 * @param String $shapeLabel The shape of the content.
 * @return Array The envelope read.
 */
function dcmBack( Array $itemsArray, Array $infosArray=Array(), String $shapeLabel='block' ): Array {
	return (new DocxRead())->parseFluxArray(Fluxd::openBytesFluxd((new Docx())->renderDocument(Array('shape'=>$shapeLabel, 'infos'=>$infosArray, 'items'=>$itemsArray))));
}

$mkSpan = Array(Shape::class, 'makeSpanOfText');
$mkBlck = Array(Shape::class, 'makeBlockArray');
$mkCell = function(String $cellsText, Array $attrsArray=Array(), Array $itemsArray=Array()) use ($mkSpan, $mkBlck){ return $mkBlck('cellText', ($cellsText==='') ? Array() : Array($mkSpan($cellsText)), $itemsArray, $attrsArray); };

// Tables, as Word is told of them
$tblBlck = $mkBlck('tableSet', Array(), Array(
	$mkBlck('tableTop', Array(), Array($mkCell('Show'), $mkCell('Seats', Array('align'=>'alignRgt')))),
	$mkBlck('tableRow', Array(), Array($mkCell('Alpha & <Beta>'), $mkCell('120', Array('align'=>'alignRgt'))))
));
$tblText = dcmBody(Array($tblBlck));
checkPregMatch('a table takes the width of the page, with its lines and its columns', $tblText, '/^<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"\/><w:tblBorders><w:top w:val="single" w:sz="4" w:space="0" w:color="808080"\/>.*<\/w:tblBorders>.*<\/w:tblPr><w:tblGrid><w:gridCol\/><w:gridCol\/><\/w:tblGrid><w:tr><w:trPr>/');
checkPregMatch('a header row is repeated on each page, its cells in the style of the headings of tables', $tblText, '/<w:tr><w:trPr><w:cantSplit\/><w:tblHeader\/><\/w:trPr><w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"\/><w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"\/><\/w:tcPr><w:p><w:pPr><w:pStyle w:val="TableHeading"\/><\/w:pPr><w:r><w:t xml:space="preserve">Show<\/w:t><\/w:r><\/w:p><\/w:tc>/');
checkPregMatch('a cell: its text, as it is aligned', $tblText, '/<w:tr><w:trPr><w:cantSplit\/><\/w:trPr><w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"\/><\/w:tcPr><w:p><w:r><w:t xml:space="preserve">Alpha &amp; &lt;Beta&gt;<\/w:t><\/w:r><\/w:p><\/w:tc><w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"\/><\/w:tcPr><w:p><w:pPr><w:jc w:val="right"\/><\/w:pPr><w:r><w:t xml:space="preserve">120<\/w:t><\/w:r><\/w:p><\/w:tc><\/w:tr><\/w:tbl><w:p\/>$/');
$spanTbl = $mkBlck('tableSet', Array(), Array(
	$mkBlck('tableRow', Array(), Array($mkCell('tall', Array('rowsp'=>3)), $mkCell('wide', Array('colsp'=>2)), $mkCell('both', Array('rowsp'=>2, 'colsp'=>2)))),
	$mkBlck('tableRow', Array(), Array($mkCell('b1'), $mkCell('b2'))),
	$mkBlck('tableRow', Array(), Array($mkCell('c1'), $mkCell('c2'), $mkCell('c3'), $mkCell('c4')))
));
$spanTxt = dcmBody(Array($spanTbl));
checkPregMatch('a cell over several columns says so, a cell over several rows starts an area', $spanTxt, '/<w:tblGrid>(<w:gridCol\/>){5}<\/w:tblGrid><w:tr><w:trPr><w:cantSplit\/><\/w:trPr><w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"\/><w:vMerge w:val="restart"\/><\/w:tcPr>.*?<\/w:tc><w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"\/><w:gridSpan w:val="2"\/><\/w:tcPr>.*?<\/w:tc><w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"\/><w:gridSpan w:val="2"\/><w:vMerge w:val="restart"\/><\/w:tcPr>/');
checkPregMatch('the rows under it go on with the area, wherever it stands in them', $spanTxt, '/<\/w:tr><w:tr><w:trPr><w:cantSplit\/><\/w:trPr><w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"\/><w:vMerge\/><\/w:tcPr><w:p\/><\/w:tc><w:tc>.*?b1.*?<\/w:tc><w:tc>.*?b2.*?<\/w:tc><w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"\/><w:gridSpan w:val="2"\/><w:vMerge\/><\/w:tcPr><w:p\/><\/w:tc><\/w:tr><w:tr><w:trPr><w:cantSplit\/><\/w:trPr><w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"\/><w:vMerge\/><\/w:tcPr><w:p\/><\/w:tc><w:tc>.*?c1/');
$innrTbl = $mkBlck('tableSet', Array(), Array($mkBlck('tableRow', Array(), Array($mkCell('inner')))));
$outrTbl = $mkBlck('tableSet', Array(), Array($mkBlck('tableRow', Array(), Array($mkCell('', Array(), Array($innrTbl))))));
$nextTbl = $mkBlck('tableSet', Array(), Array($mkBlck('tableRow', Array(), Array($mkCell('second')))));
checkPregMatch('a paragraph stands between two tables, and ends a cell holding one', dcmBody(Array($outrTbl, $nextTbl)), '/inner<\/w:t><\/w:r><\/w:p><\/w:tc><\/w:tr><\/w:tbl><w:p\/><\/w:tc><\/w:tr><\/w:tbl><w:p\/><w:tbl>.*second.*<\/w:tbl><w:p\/>$/');
checkIdentical('a table without rows is not written', dcmBody(Array($mkBlck('tableSet'))), '');

// Notes
$noteLst = Array(
	$mkBlck('paragrph', Array($mkSpan('Text'), $mkSpan('1', Array(), '#fnote1'), $mkSpan(' more'), $mkSpan('1', Array(), '#enote1'), $mkSpan(' again'), $mkSpan('1', Array(), '#fnote1'), $mkSpan(' and nowhere'), $mkSpan('7', Array(), '#fnote7'))),
	$mkBlck('footnote', Array($mkSpan('  A note with a '), $mkSpan('link', Array(), 'https://nyuark.test/note')), Array($mkBlck('paragrph', Array($mkSpan('Second paragraph')))), Array('tagid'=>'fnote1', 'numbr'=>1)),
	$mkBlck('footnote', Array($mkSpan('A note of the end')), Array(), Array('tagid'=>'enote1', 'numbr'=>1)),
	$mkBlck('footnote', Array($mkSpan('Never referred to')), Array(), Array('tagid'=>'fnote5', 'numbr'=>5))
);
$noteDoc = dcmWrite($noteLst);
checkPregMatch('a note is referred to once, by the number Word knows it by; a second reference and one to no note are text', dcmPart($noteDoc, 'word/document.xml'), '/<w:body><w:p><w:r><w:t xml:space="preserve">Text<\/w:t><\/w:r><w:r><w:rPr><w:rStyle w:val="FootnoteReference"\/><\/w:rPr><w:footnoteReference w:id="1"\/><\/w:r><w:r><w:t xml:space="preserve"> more<\/w:t><\/w:r><w:r><w:rPr><w:rStyle w:val="EndnoteReference"\/><\/w:rPr><w:endnoteReference w:id="1"\/><\/w:r><w:r><w:t xml:space="preserve"> again<\/w:t><\/w:r><w:r><w:t xml:space="preserve">1<\/w:t><\/w:r><w:r><w:t xml:space="preserve"> and nowhere<\/w:t><\/w:r><w:hyperlink w:anchor="fnote7" w:history="1">/');
checkPregMatch('a note nothing refers to ends the text, and is not lost', dcmPart($noteDoc, 'word/document.xml'), '/<w:p><w:r><w:t xml:space="preserve">Never referred to<\/w:t><\/w:r><\/w:p><w:sectPr>/');
checkPregMatch('the notes of the pages: the lines Word draws, then each note, its number, a space and its text', dcmPart($noteDoc, 'word/footnotes.xml'), '/<w:footnotes [^>]*><w:footnote w:type="separator" w:id="-1"><w:p><w:r><w:separator\/><\/w:r><\/w:p><\/w:footnote><w:footnote w:type="continuationSeparator" w:id="0">.*<\/w:footnote><w:footnote w:id="1"><w:p><w:pPr><w:pStyle w:val="FootnoteText"\/><\/w:pPr><w:r><w:rPr><w:rStyle w:val="FootnoteReference"\/><\/w:rPr><w:footnoteRef\/><\/w:r><w:r><w:t xml:space="preserve"> <\/w:t><\/w:r><w:r><w:t xml:space="preserve">A note with a <\/w:t><\/w:r><w:hyperlink r:id="rLk1" w:history="1">.*<\/w:hyperlink><\/w:p><w:p><w:r><w:t xml:space="preserve">Second paragraph<\/w:t><\/w:r><\/w:p><\/w:footnote><\/w:footnotes>$/');
checkPregMatch('the addresses a note points to are among the relations of the notes, not of the text', dcmPart($noteDoc, 'word/_rels/footnotes.xml.rels').'|'.dcmPart($noteDoc, 'word/_rels/document.xml.rels'), '/<Relationship Id="rLk1" Type="[^"]*\/hyperlink" Target="https:\/\/nyuark\.test\/note" TargetMode="External"\/><\/Relationships>\|.*<Relationship Id="rId2" [^>]*\/><Relationship Id="rId5" Type="[^"]*\/footnotes" Target="footnotes\.xml"\/><Relationship Id="rId6" Type="[^"]*\/endnotes" Target="endnotes\.xml"\/><\/Relationships>$/s');
checkPregMatch('the notes of the end', dcmPart($noteDoc, 'word/endnotes.xml'), '/<w:endnote w:id="1"><w:p><w:pPr><w:pStyle w:val="EndnoteText"\/><\/w:pPr><w:r><w:rPr><w:rStyle w:val="EndnoteReference"\/><\/w:rPr><w:endnoteRef\/><\/w:r>.*A note of the end/');
checkIdentical('a document without notes has no part for them', Array(dcmPart(dcmWrite(Array($mkBlck('paragrph', Array($mkSpan('x'))))), 'word/footnotes.xml'), strpos(dcmPart(dcmWrite(Array($mkBlck('paragrph', Array($mkSpan('x'))))), '[Content_Types].xml'), 'footnotes')), Array('', false));

// The top and the bottom of the pages, and fields
$pageDoc = dcmWrite(Array($mkBlck('paragrph', Array($mkSpan('Body'), $mkSpan('1', Array(), '#fnote1'))), $mkBlck('footnote', Array($mkSpan('Note')), Array(), Array('tagid'=>'fnote1'))), Array(
	'phead'=>Array($mkBlck('paragrph', Array($mkSpan('Top '), $mkSpan('old title', Array(), '', 'props:Title'), $mkSpan(' '), $mkSpan('site', Array(), 'https://nyuark.test/top'), $mkSpan('1', Array(), '#fnote1')), Array(), Array('align'=>'alignRgt'))),
	'pfoot'=>Array($mkBlck('paragrph', Array($mkSpan('Page '), $mkSpan('3', Array('boldFace'), '', 'pageNumb'), $mkSpan(' of '), $mkSpan('', Array(), '', 'pageTotl'), $mkSpan(' '), $mkSpan('x', Array(), '', 'props:My "Client"\\ name'), $mkSpan(' '), $mkSpan('2026', Array(), '', 'dateTime'), $mkSpan(' '), $mkSpan('kept', Array(), '', 'other'))), $mkBlck('tableSet', Array(), Array($mkBlck('tableRow', Array(), Array($mkCell('in the footer'))))))
));
checkPregMatch('the pages are told what stands at their top and at their bottom', dcmPart($pageDoc, 'word/document.xml'), '/<w:sectPr><w:headerReference w:type="default" r:id="rId3"\/><w:footerReference w:type="default" r:id="rId4"\/><w:pgSz /');
checkPregMatch('the top of the pages: a part of its own, with its own addresses; a note is not referred to from there', dcmPart($pageDoc, 'word/header1.xml').'|'.dcmPart($pageDoc, 'word/_rels/header1.xml.rels'), '/<w:hdr [^>]*><w:p><w:pPr><w:jc w:val="right"\/><\/w:pPr><w:r><w:t xml:space="preserve">Top <\/w:t><\/w:r><w:fldSimple w:instr=" TITLE "><w:r><w:t xml:space="preserve">old title<\/w:t><\/w:r><\/w:fldSimple>.*<w:hyperlink r:id="rLk1" w:history="1">.*<\/w:hyperlink><w:r><w:t xml:space="preserve">1<\/w:t><\/w:r><\/w:p><\/w:hdr>\|.*Target="https:\/\/nyuark\.test\/top" TargetMode="External"\/><\/Relationships>$/s');
checkPregMatch('fields: what each asks Word for, around what it last showed; a field unknown is its text', dcmPart($pageDoc, 'word/footer1.xml'), '/<w:fldSimple w:instr=" PAGE "><w:r><w:rPr><w:b\/><w:bCs\/><\/w:rPr><w:t xml:space="preserve">3<\/w:t><\/w:r><\/w:fldSimple>.*<w:fldSimple w:instr=" NUMPAGES "><w:r><w:t xml:space="preserve">1<\/w:t><\/w:r><\/w:fldSimple>.*<w:fldSimple w:instr=" DOCPROPERTY &quot;My Client name&quot; "><w:r><w:t xml:space="preserve">x<\/w:t><\/w:r><\/w:fldSimple>.*<w:fldSimple w:instr=" DATE "><w:r><w:t xml:space="preserve">2026<\/w:t><\/w:r><\/w:fldSimple><w:r><w:t xml:space="preserve"> <\/w:t><\/w:r><w:r><w:t xml:space="preserve">kept<\/w:t><\/w:r><\/w:p>/');
checkPregMatch('the bottom of the pages ends with a paragraph, even after a table', dcmPart($pageDoc, 'word/footer1.xml'), '/<\/w:tbl><w:p\/><\/w:ftr>$/');
checkTrueValue('every part is well-formed', array_reduce(Array('[Content_Types].xml', 'word/document.xml', 'word/_rels/document.xml.rels', 'word/styles.xml', 'word/footnotes.xml', 'word/_rels/footnotes.xml.rels', 'word/header1.xml', 'word/_rels/header1.xml.rels', 'word/footer1.xml', 'word/_rels/footer1.xml.rels'), function($allAreGood, $insideName) use ($pageDoc){ return $allAreGood && @simplexml_load_string(dcmPart($pageDoc, $insideName))!==false; }, true));
checkPregMatch('each part is told for what it is', dcmPart($pageDoc, '[Content_Types].xml'), '/<Override PartName="\/word\/header1\.xml" ContentType="[^"]*wordprocessingml\.header\+xml"\/><Override PartName="\/word\/footer1\.xml" ContentType="[^"]*wordprocessingml\.footer\+xml"\/><Override PartName="\/word\/footnotes\.xml" ContentType="[^"]*wordprocessingml\.footnotes\+xml"\/>/');

// Properties
$propDoc = dcmWrite(Array(), Array('title'=>'The <title>', 'authr'=>'Zoé', 'props'=>Array('Title'=>'overridden', 'Subject'=>'A subject', 'Keywords'=>'a, b', 'Comments'=>'Some comments', 'Category'=>'Tests', 'LastSavedBy'=>'Me', 'Client'=>'Théâtre & <Test>', 'Revision'=>7, 'Large'=>5000000000, 'Approved'=>true, 'Ratio'=>0.1, ''=>'no name', 'List'=>Array(1, 2), 'Nothing'=>null)));
checkPregMatch('the properties Word keeps a place for; the title and the author told of the document come first', dcmPart($propDoc, 'docProps/core.xml'), '/<dc:title>The &lt;title&gt;<\/dc:title><dc:creator>Zoé<\/dc:creator><dc:subject>A subject<\/dc:subject><cp:keywords>a, b<\/cp:keywords><dc:description>Some comments<\/dc:description><cp:category>Tests<\/cp:category><cp:lastModifiedBy>Me<\/cp:lastModifiedBy><dcterms:created/');
checkPregMatch('the other properties, each with the kind of its value', dcmPart($propDoc, 'docProps/custom.xml'), '/<Properties [^>]*><property fmtid="\{D5CDD505-2E9C-101B-9397-08002B2CF9AE\}" pid="2" name="Client"><vt:lpwstr>Théâtre &amp; &lt;Test&gt;<\/vt:lpwstr><\/property><property [^>]* pid="3" name="Revision"><vt:i4>7<\/vt:i4><\/property><property [^>]* pid="4" name="Large"><vt:i8>5000000000<\/vt:i8><\/property><property [^>]* pid="5" name="Approved"><vt:bool>true<\/vt:bool><\/property><property [^>]* pid="6" name="Ratio"><vt:r8>0\.1<\/vt:r8><\/property><\/Properties>$/');
checkIdentical('a document without such properties has no part for them', Array(dcmPart(dcmWrite(Array(), Array('title'=>'x')), 'docProps/custom.xml'), strpos(dcmPart(dcmWrite(Array(), Array('title'=>'x')), '_rels/.rels'), 'custom')), Array('', false));

// Named styles
$stylDoc = dcmWrite(Array(
	$mkBlck('paragrph', Array($mkSpan('one')), Array(), Array('style'=>'Highlight')),
	$mkBlck('paragrph', Array($mkSpan('two')), Array(), Array('style'=>'Only "Named" <style>')),
	$mkBlck('paragrph', Array($mkSpan('three')), Array(), Array('style'=>'HIGHLIGHT')),
	$mkBlck('paragrph', Array($mkSpan('four')), Array(), Array('style'=>'Normal')),
	$mkBlck('headline', Array($mkSpan('five')), Array(), Array('level'=>2, 'style'=>'Highlight'))
), Array('frmat'=>Array('Highlight'=>Array('fonts'=>'Georgia "x"', 'sizes'=>14.5, 'color'=>'#c00000', 'marks'=>Array('undrLine', 'boldFace', 'emphasis', 'crossOut'), 'align'=>'alignCtr'), 'Unused'=>Array('sizes'=>'huge', 'color'=>'red;'), 'heading 1'=>Array('sizes'=>99))));
checkPregMatch('a style the content tells of is a style of the document, what it gives written in the order Word reads it', dcmPart($stylDoc, 'word/styles.xml'), '/<w:style w:type="paragraph" w:customStyle="1" w:styleId="nyu1Highlight"><w:name w:val="Highlight"\/><w:basedOn w:val="Normal"\/><w:qFormat\/><w:pPr><w:jc w:val="center"\/><\/w:pPr><w:rPr><w:rFonts w:ascii="Georgia &quot;x&quot;" w:hAnsi="Georgia &quot;x&quot;" w:cs="Georgia &quot;x&quot;"\/><w:b\/><w:bCs\/><w:i\/><w:iCs\/><w:strike\/><w:color w:val="C00000"\/><w:sz w:val="29"\/><w:szCs w:val="29"\/><w:u w:val="single"\/><\/w:rPr><\/w:style>/');
checkPregMatch('what is not a size or a colour is left out, a style only named is defined, the styles of the document are not defined twice', dcmPart($stylDoc, 'word/styles.xml'), '/<w:style w:type="paragraph" w:customStyle="1" w:styleId="nyu2Unused"><w:name w:val="Unused"\/><w:basedOn w:val="Normal"\/><w:qFormat\/><\/w:style><w:style w:type="paragraph" w:customStyle="1" w:styleId="nyu3OnlyNamedstyle"><w:name w:val="Only &quot;Named&quot; &lt;style&gt;"\/><w:basedOn w:val="Normal"\/><w:qFormat\/><\/w:style><\/w:styles>$/');
checkPregMatch('a paragraph is given the style it names, whatever the case; a heading keeps the style of its level', dcmPart($stylDoc, 'word/document.xml'), '/<w:body><w:p><w:pPr><w:pStyle w:val="nyu1Highlight"\/><\/w:pPr>.*?<\/w:p><w:p><w:pPr><w:pStyle w:val="nyu3OnlyNamedstyle"\/><\/w:pPr>.*?<\/w:p><w:p><w:pPr><w:pStyle w:val="nyu1Highlight"\/><\/w:pPr>.*?<\/w:p><w:p><w:r><w:t xml:space="preserve">four<\/w:t><\/w:r><\/w:p><w:p><w:pPr><w:pStyle w:val="Heading2"\/><\/w:pPr>/');
checkIdentical('the heading styles of the document are defined once', substr_count(dcmPart($stylDoc, 'word/styles.xml'), '<w:name w:val="heading 1"/>'), 1);

// Sheets as tables
$shetLst = Array(
	Array('title'=>'Shows', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('Show'=>'Alpha', 'Seats'=>120, 'Price'=>9.9, 'Open'=>true, 'Note'=>null), Array('Show'=>'=SUM(B2)', 'Seats'=>-3, 'Price'=>0.1, 'Open'=>false, 'Note'=>Array('a'=>1)))),
	Array('title'=>'Merged', 'attrs'=>Array('shown'=>true, 'merge'=>Array(Array('atrow'=>0, 'atcol'=>0, 'rowsp'=>1, 'colsp'=>3), Array('atrow'=>2, 'atcol'=>0, 'rowsp'=>2, 'colsp'=>1))), 'cells'=>Array(Array('Title', null, null), Array('a', 'b', 'c'), Array('tall', 1, 2), Array(null, 3))),
	Array('title'=>'Hidden', 'attrs'=>Array('shown'=>false, 'merge'=>Array()), 'cells'=>Array(Array('secret'))),
	Array('title'=>'', 'attrs'=>Array(), 'cells'=>Array())
);
$shetDoc = dcmPart(dcmWrite($shetLst, Array(), 'sheet'), 'word/document.xml');
checkIdentical('a hidden sheet is not written, nor a sheet holding nothing', Array(strpos($shetDoc, 'secret'), strpos($shetDoc, 'Hidden'), substr_count($shetDoc, '<w:tbl>')), Array(false, false, 2));
checkPregMatch('a sheet: a heading bearing its name, then a table whose first row names its columns', $shetDoc, '/<w:body><w:p><w:pPr><w:pStyle w:val="Heading2"\/><\/w:pPr><w:r><w:t xml:space="preserve">Shows<\/w:t><\/w:r><\/w:p><w:tbl>.*?<w:tr><w:trPr><w:cantSplit\/><w:tblHeader\/><\/w:trPr><w:tc>.*?<w:pStyle w:val="TableHeading"\/>.*?Show<\/w:t>/');

if( !class_exists('ZipArchive') ){
	recordSkipping('documents written, then read back', 'the zip extension is not loaded');
	finishChecking();
}

// Written, then read back
checkIdentical('a table: its header row, its cells, what they cover', dcmShow(dcmBack(Array($tblBlck, $spanTbl))['items']), Array(
	'tableSet', '  tableTop', '    cellText style="Table Heading" {Show}', '    cellText align="alignRgt" style="Table Heading" {Seats}', '  tableRow', '    cellText {Alpha & <Beta>}', '    cellText align="alignRgt" {120}', 'paragrph',
	'tableSet', '  tableRow', '    cellText rowsp=3 {tall}', '    cellText colsp=2 {wide}', '    cellText colsp=2 rowsp=2 {both}', '  tableRow', '    cellText {b1}', '    cellText {b2}', '  tableRow', '    cellText {c1}', '    cellText {c2}', '    cellText {c3}', '    cellText {c4}', 'paragrph'
));
$listCel = $mkCell('with a list', Array(), Array($mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('one')))), Array('order'=>true))));
$holdTbl = $mkBlck('tableSet', Array(), Array($mkBlck('tableRow', Array(), Array($listCel, $mkCell('', Array(), Array($innrTbl))))));
checkIdentical('a cell holding a list and a table', dcmShow(dcmBack(Array($holdTbl))['items']), Array('tableSet', '  tableRow', '    cellText {with a list}', '      itemList order=true', '        listItem {one}', '    cellText', '      tableSet', '        tableRow', '          cellText {inner}', 'paragrph'));
$noteSet = dcmBack($noteLst);
checkIdentical('notes: read back where the text refers to them, numbered again, the one never referred to as text', dcmShow($noteSet['items']), Array(
	'paragrph {Text}{1|#fnote1}{ more}{1|#enote1}{ again1 and nowhere}{7|undrLine|#fnote7}', 'paragrph {Never referred to}',
	'footnote tagid="fnote1" numbr=1 style="footnote text" { A note with a }{link|undrLine|https://nyuark.test/note}', '  paragrph {Second paragraph}', 'footnote tagid="enote1" numbr=1 style="endnote text" { A note of the end}'
));
$twiceSt = dcmBack($noteSet['items'], $noteSet['infos']);
checkIdentical('written and read a second time, the notes are the same: the space after their number does not grow', dcmShow($twiceSt['items']), dcmShow($noteSet['items']));
$pageSet = dcmBack(Array($mkBlck('paragrph', Array($mkSpan('Body')))), Array(
	'title'=>'A title', 'props'=>Array('Client'=>'Théâtre', 'Revision'=>7, 'Approved'=>true, 'Ratio'=>0.5, 'Subject'=>'Tests'),
	'phead'=>Array($mkBlck('paragrph', Array($mkSpan('Top '), $mkSpan('old', Array(), '', 'props:Title')), Array(), Array('align'=>'alignRgt'))),
	'pfoot'=>Array($mkBlck('paragrph', Array($mkSpan('Page '), $mkSpan('3', Array(), '', 'pageNumb'), $mkSpan(' of '), $mkSpan('9', Array(), '', 'pageTotl'), $mkSpan(' '), $mkSpan('x', Array(), '', 'props:Client'), $mkSpan(' '), $mkSpan('2026', Array(), '', 'dateTime'))))
));
checkIdentical('the top and the bottom of the pages, with their fields', Array(dcmShow($pageSet['infos']['phead']), dcmShow($pageSet['infos']['pfoot'])), Array(Array('paragrph align="alignRgt" {Top }{old|props:Title}'), Array('paragrph {Page }{3|pageNumb}{ of }{9|pageTotl}{ }{x|props:Client}{ }{2026|dateTime}')));
checkIdentical('the properties, each with the kind of its value', $pageSet['infos']['props'], Array('Title'=>'A title', 'Subject'=>'Tests', 'Client'=>'Théâtre', 'Revision'=>7, 'Approved'=>true, 'Ratio'=>0.5));
$stylSet = dcmBack(Array($mkBlck('paragrph', Array($mkSpan('one')), Array(), Array('style'=>'Highlight')), $mkBlck('paragrph', Array($mkSpan('two')), Array(), Array('style'=>'Only Named'))), Array('frmat'=>Array('Highlight'=>Array('fonts'=>'Georgia', 'sizes'=>14, 'color'=>'#C00000', 'marks'=>Array('boldFace', 'emphasis'), 'align'=>'alignCtr'))));
checkIdentical('named styles: the paragraphs name them, the document tells what they give', Array(dcmShow($stylSet['items']), $stylSet['infos']['frmat']['Highlight'], $stylSet['infos']['frmat']['Only Named']), Array(
	Array('paragrph style="Highlight" {one}', 'paragrph style="Only Named" {two}'),
	Array('class'=>'highlight', 'fonts'=>'Georgia', 'sizes'=>14, 'color'=>'#C00000', 'marks'=>Array('boldFace', 'emphasis'), 'align'=>'alignCtr'),
	Array('class'=>'only-named', 'fonts'=>'Calibri', 'sizes'=>11)
));
checkIdentical('sheets: a table each, the numbers on the right, what is merged', dcmShow(dcmBack($shetLst, Array(), 'sheet')['items']), Array(
	'headline level=2 style="heading 2" {Shows}', 'tableSet', '  tableTop', '    cellText style="Table Heading" {Show}', '    cellText style="Table Heading" {Seats}', '    cellText style="Table Heading" {Price}', '    cellText style="Table Heading" {Open}', '    cellText style="Table Heading" {Note}',
	'  tableRow', '    cellText {Alpha}', '    cellText align="alignRgt" {120}', '    cellText align="alignRgt" {9.9}', '    cellText {TRUE}', '    cellText',
	'  tableRow', '    cellText {=SUM(B2)}', '    cellText align="alignRgt" {-3}', '    cellText align="alignRgt" {0.1}', '    cellText {FALSE}', '    cellText {{"a":1}}',
	'headline level=2 style="heading 2" {Merged}', 'tableSet', '  tableRow', '    cellText colsp=3 {Title}', '  tableRow', '    cellText {a}', '    cellText {b}', '    cellText {c}', '  tableRow', '    cellText rowsp=2 {tall}', '    cellText align="alignRgt" {1}', '    cellText align="alignRgt" {2}', '  tableRow', '    cellText align="alignRgt" {3}', '    cellText', 'paragrph'
));
// Documents of Word, read, written, and read again: their tables are still tables
$smplSet = (new DocxRead())->parseFluxArray(Fluxd::openLocalFluxd(TST_DIR.DS.'fixtures'.DS.'office'.DS.'TestTableCellAlign.docx'));
$againSt = dcmBack($smplSet['items'], $smplSet['infos']);
$kindsOf = function(Array $blocksList) use (&$kindsOf){ $kindLst = Array(); foreach($blocksList as $blkArr){ $kindLst[] = $blkArr['block'].((empty($blkArr['attrs']['colsp']) ? '' : 'c'.$blkArr['attrs']['colsp']).(empty($blkArr['attrs']['rowsp']) ? '' : 'r'.$blkArr['attrs']['rowsp'])); $kindLst = array_merge($kindLst, $kindsOf($blkArr['items'])); } return $kindLst; };
checkIdentical('a document of Word holding tables read, written and read again: the same tables, the same text', Array(array_values(array_diff($kindsOf($againSt['items']), Array('paragrph')))===array_values(array_diff($kindsOf($smplSet['items']), Array('paragrph'))), in_array('tableSet', $kindsOf($smplSet['items'])), preg_replace('/\s+/', ' ', Shape::getTextOfDcmnt($againSt['items']))===preg_replace('/\s+/', ' ', Shape::getTextOfDcmnt($smplSet['items']))), Array(true, true, true));

finishChecking();
