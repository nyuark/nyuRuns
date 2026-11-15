<?php

// Data\Writers\Odtx : OpenDocument texts written from text blocks.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Fluxd;
use nyu\Data\Parsers\Docx as DocxRead;
use nyu\Data\Parsers\Odtx as OdtxRead;
use nyu\Data\Shape;
use nyu\Data\Writers\Docx;
use nyu\Data\Writers\Index;
use nyu\Data\Writers\Odtx;

/**
 * Writes a content as a document, or returns the reason it was refused.
 *
 * @param Array  $itemsArray The blocks, or the sheets.
 * @param Array  $infosArray What is told of the document.
 * @param Array  $writeOptns Options of the writer.
 * @param String $shapeLabel The shape of the content.
 * @return mixed The bytes, or a 'THROWN ...' string.
 */
function odtWrite( Array $itemsArray, Array $infosArray=Array(), Array $writeOptns=Array(), String $shapeLabel='block' ): Mixed {
	return runCatchThrown(function() use ($itemsArray, $infosArray, $writeOptns, $shapeLabel){
		return (new Odtx())->renderDocument(Array('shape'=>$shapeLabel, 'infos'=>$infosArray, 'items'=>$itemsArray), $writeOptns);
	});
}

/**
 * Gives a part of a document, read without the zip extension.
 *
 * @param String $archvBytes The bytes of the archive.
 * @param String $insideName Name of the part.
 * @return String The part, '' when it is not found.
 */
function odtPart( String $archvBytes, String $insideName ): String {
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
 * Gives the text of the document written from some blocks.
 *
 * @param Array $blocksList The blocks.
 * @return String What stands in the text of the document.
 */
function odtBody( Array $blocksList ): String {
	$bodyPrt = Array();
	return preg_match('/<office:text>(.*)<\/office:text>/s', odtPart(strval(odtWrite($blocksList)), 'content.xml'), $bodyPrt) ? $bodyPrt[1] : '';
}

/**
 * Shows blocks in short: their kind, what tells them apart, their text
 * with its formatting and its links, and the blocks they hold.
 *
 * @param Array $blocksList The blocks.
 * @param Bool  $withStyles Whether the styles of the blocks are shown.
 * @return Array A line for each block.
 */
function odtShow( Array $blocksList, Bool $withStyles=true ): Array {
	$showLst = Array();
	foreach($blocksList as $blkArr){
		$attrTxt = '';
		foreach(Array('level', 'order', 'align', 'tagid', 'numbr', 'colsp', 'rowsp', 'break', 'style') as $atrKey){
			$attrTxt .= (isset($blkArr['attrs'][$atrKey]) && ($atrKey!='style' || ($withStyles && $blkArr['attrs'][$atrKey]!='Standard'))) ? ' '.$atrKey.'='.json_encode($blkArr['attrs'][$atrKey]) : '';
		}
		$spanTxt = '';
		foreach($blkArr['spans'] as $oneSpn){
			$spanTxt .= '{'.$oneSpn['texts'].(empty($oneSpn['marks']) ? '' : '|'.implode(',', $oneSpn['marks'])).(($oneSpn['links']==='') ? '' : '|'.$oneSpn['links']).'}';
		}
		$showLst[] = $blkArr['block'].$attrTxt.(($spanTxt==='') ? '' : ' '.$spanTxt);
		foreach(odtShow($blkArr['items'], $withStyles) as $subLne){
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
function odtBack( Array $itemsArray, Array $infosArray=Array(), String $shapeLabel='block' ): Array {
	return (new OdtxRead())->parseFluxArray(Fluxd::openBytesFluxd((new Odtx())->renderDocument(Array('shape'=>$shapeLabel, 'infos'=>$infosArray, 'items'=>$itemsArray))));
}

$mkSpan = Array(Shape::class, 'makeSpanOfText');
$mkBlck = Array(Shape::class, 'makeBlockArray');
$mkCell = function(String $cellsText, Array $attrsArray=Array(), Array $itemsArray=Array()) use ($mkSpan, $mkBlck){ return $mkBlck('cellText', ($cellsText==='') ? Array() : Array($mkSpan($cellsText)), $itemsArray, $attrsArray); };

// The document, part by part
$bookTxt = odtWrite(Array($mkBlck('headline', Array($mkSpan('Title')), Array(), Array('level'=>1)), $mkBlck('paragrph', Array($mkSpan('Text')))), Array('title'=>'Season <2026>', 'authr'=>'Zoé', 'props'=>Array('Subject'=>'A subject', 'Keywords'=>'one, two ,, three', 'Comments'=>'Some comments', 'LastSavedBy'=>'Me', 'Category'=>'Tests', 'Client'=>'Théâtre & <Test>', 'Revision'=>7, 'Approved'=>true, 'Ratio'=>0.1, 'List'=>Array(1))));
checkIdentical('a document starts with what it is, kept as it is', Array(substr($bookTxt, 0, 4), unpack('v', substr($bookTxt, 8, 2))[1], substr($bookTxt, 30, 8), substr($bookTxt, 38, 39)), Array("PK\x03\x04", 0, 'mimetype', 'application/vnd.oasis.opendocument.text'));
checkTrueValue('every part is well-formed', array_reduce(Array('META-INF/manifest.xml', 'content.xml', 'styles.xml', 'meta.xml'), function($allAreGood, $insideName) use ($bookTxt){ return $allAreGood && @simplexml_load_string(odtPart($bookTxt, $insideName))!==false; }, true));
checkPregMatch('the properties every document has a place for, the key words one by one', odtPart($bookTxt, 'meta.xml'), '/<office:meta><dc:title>Season &lt;2026&gt;<\/dc:title><meta:initial-creator>Zoé<\/meta:initial-creator><dc:subject>A subject<\/dc:subject><dc:description>Some comments<\/dc:description><dc:creator>Me<\/dc:creator><meta:keyword>one<\/meta:keyword><meta:keyword>two<\/meta:keyword><meta:keyword>three<\/meta:keyword><meta:creation-date>/');
checkPregMatch('the other properties, each with the kind of its value', odtPart($bookTxt, 'meta.xml'), '/<meta:user-defined meta:name="Category" meta:value-type="string">Tests<\/meta:user-defined><meta:user-defined meta:name="Client" meta:value-type="string">Théâtre &amp; &lt;Test&gt;<\/meta:user-defined><meta:user-defined meta:name="Revision" meta:value-type="float">7<\/meta:user-defined><meta:user-defined meta:name="Approved" meta:value-type="boolean">true<\/meta:user-defined><meta:user-defined meta:name="Ratio" meta:value-type="float">0\.1<\/meta:user-defined><\/office:meta>/');
checkPregMatch('the pages: A4, standing, unless told otherwise', odtPart($bookTxt, 'styles.xml'), '/<style:page-layout style:name="pm1"><style:page-layout-properties fo:page-width="21\.001cm" fo:page-height="29\.700cm" style:print-orientation="portrait" fo:margin-top="2\.54cm"/');
checkPregMatch('another size of paper, lying', odtPart(odtWrite(Array(), Array(), Array('paper'=>'usLetter', 'lands'=>true)), 'styles.xml'), '/fo:page-width="27\.940cm" fo:page-height="21\.590cm" style:print-orientation="landscape"/');
checkPregMatch('a size of paper that is not one', odtWrite(Array(), Array(), Array('paper'=>'huge')), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Invalid document option: unknown paper huge/');
checkPregMatch('a shape that is not blocks', odtWrite(Array(), Array(), Array(), 'nodes'), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as a document: nodes/');
checkIdentical('the writer of the odt extension', Array(get_class(Index::getExtnsWriter('ODT')), Index::getMimeForExtn('odt')), Array('nyu\\Data\\Writers\\Odtx', 'application/vnd.oasis.opendocument.text'));
checkPregMatch('the styles a reader finds its way by, under the names LibreOffice gives them', odtPart($bookTxt, 'styles.xml'), '/<style:style style:name="Standard" style:family="paragraph" style:class="text"\/><style:style style:name="Heading_20_1" style:display-name="Heading 1" style:family="paragraph" style:parent-style-name="Standard" style:next-style-name="Standard" style:default-outline-level="1".*style:name="Heading_20_6".*style:name="Quotations".*style:display-name="Preformatted Text".*style:display-name="Table Heading".*style:name="Footnote".*style:name="Endnote".*style:display-name="Internet Link"/s');

// Blocks, as the document is told of them
checkIdentical('a heading, with its level', odtBody(Array($mkBlck('headline', Array($mkSpan('Chapter')), Array(), Array('level'=>2)))), '<text:h text:style-name="Heading_20_2" text:outline-level="2">Chapter</text:h>');
checkIdentical('signs stay text; line ends, tabs and spaces are written as what they are', odtBody(Array($mkBlck('paragrph', Array($mkSpan(" a & <b>\nnext\ttab   end "))))), '<text:p text:style-name="Standard"><text:s/>a &amp; &lt;b&gt;<text:line-break/>next<text:tab/>tab<text:s text:c="3"/>end<text:s/></text:p>');
$markDoc = odtPart(odtWrite(Array($mkBlck('paragrph', Array($mkSpan('plain '), $mkSpan('all', Array('codeSpan', 'undrLine', 'crossOut', 'emphasis', 'boldFace')), $mkSpan('bold', Array('boldFace')), $mkSpan('again', Array('boldFace')))))), 'content.xml');
checkPregMatch('formatting: a style is made for each kind of it, once', $markDoc, '/<style:style style:name="T1" style:family="text"><style:text-properties fo:font-weight="bold" [^>]*fo:font-style="italic" [^>]*style:text-underline-style="solid" [^>]*style:text-line-through-style="solid" [^>]*fo:font-family="\'Courier New\'"[^>]*\/><\/style:style><style:style style:name="T2" style:family="text"><style:text-properties fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"\/><\/style:style><text:list-style/');
checkPregMatch('and the text names the style of its formatting', $markDoc, '/<text:p text:style-name="Standard">plain<text:s\/><text:span text:style-name="T1">all<\/text:span><text:span text:style-name="T2">bold<\/text:span><text:span text:style-name="T2">again<\/text:span><\/text:p>/');
$handDoc = odtPart(odtWrite(Array($mkBlck('paragrph', Array($mkSpan('x')), Array(), Array('align'=>'alignJst', 'tagid'=>'my place!')), $mkBlck('paragrph', Array($mkSpan('y')), Array(), Array('align'=>'alignJst')), $mkBlck('ruleLine'), $mkBlck('ruleLine', Array(), Array(), Array('break'=>true)), $mkBlck('headline', Array($mkSpan('Next page')), Array(), Array('level'=>1)))), 'content.xml');
checkPregMatch('what a paragraph adds to its style makes a style of its own, once: how it is aligned, the line under it, the page it starts', $handDoc, '/<office:automatic-styles><style:style style:name="P1" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:text-align="justify"\/><\/style:style><style:style style:name="P2" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:border-bottom="0\.5pt solid #000000" fo:padding-bottom="0\.035cm"\/><\/style:style><style:style style:name="P3" style:family="paragraph" style:parent-style-name="Heading_20_1"><style:paragraph-properties fo:break-before="page"\/><\/style:style><text:list-style/');
checkPregMatch('the name of a place, a line, and the end of a page told by the paragraph that follows it', $handDoc, '/<office:text><text:p text:style-name="P1"><text:bookmark text:name="my_place"\/>x<\/text:p><text:p text:style-name="P1">y<\/text:p><text:p text:style-name="P2"><\/text:p><text:h text:style-name="P3" text:outline-level="1">Next page<\/text:h><\/office:text>/');
checkPregMatch('a page that ends the document still starts', odtBody(Array($mkBlck('paragrph', Array($mkSpan('x'))), $mkBlck('ruleLine', Array(), Array(), Array('break'=>true)))), '/^<text:p text:style-name="Standard">x<\/text:p><text:p text:style-name="P1"><\/text:p>$/');
checkIdentical('code: a paragraph for each line; a quote and who is quoted', odtBody(Array($mkBlck('codeBlck', Array($mkSpan("if (a) {\n  b();\n}"))), $mkBlck('citation', Array($mkSpan('Stay text.')), Array(), Array('cites'=>'Someone')))), '<text:p text:style-name="Preformatted_20_Text">if (a) {</text:p><text:p text:style-name="Preformatted_20_Text"><text:s text:c="2"/>b();</text:p><text:p text:style-name="Preformatted_20_Text">}</text:p><text:p text:style-name="Quotations">Stay text.</text:p><text:p text:style-name="P1">— Someone</text:p>');

// Links
$linkTxt = odtBody(Array($mkBlck('paragrph', Array($mkSpan('site', Array('boldFace'), 'https://nyuark.test/?a=1&b=2'), $mkSpan('place', Array(), '#my place!'), $mkSpan('script', Array(), 'javascript:alert(1)'), $mkSpan('data', Array(), 'data:text/html;base64,AAAA')))));
checkIdentical('links, a link to a place of the document, and addresses that are dropped', $linkTxt, '<text:p text:style-name="Standard"><text:a xlink:type="simple" xlink:href="https://nyuark.test/?a=1&amp;b=2" text:style-name="Internet_20_link" text:visited-style-name="Internet_20_link"><text:span text:style-name="T1">site</text:span></text:a><text:a xlink:type="simple" xlink:href="#my_place" text:style-name="Internet_20_link" text:visited-style-name="Internet_20_link">place</text:a>scriptdata</text:p>');

// Lists
$listLst = Array(
	$mkBlck('itemList', Array(), Array(
		$mkBlck('listItem', Array($mkSpan('One'))),
		$mkBlck('listItem', Array($mkSpan('Two')), Array($mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('Two, first'))), $mkBlck('listItem', Array($mkSpan('Two, second')), Array($mkBlck('paragrph', Array($mkSpan('with a paragraph of its own')))))), Array('order'=>true)))),
		$mkBlck('listItem', Array($mkSpan('Three')))
	), Array('order'=>false)),
	$mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('First'))), $mkBlck('listItem', Array($mkSpan('Done')), Array(), Array('check'=>true)), $mkBlck('listItem', Array($mkSpan('Open')), Array(), Array('check'=>false))), Array('order'=>true))
);
checkIdentical('itemList: marked or numbered, one in an entry of another, an entry holding more than its text', odtBody($listLst), '<text:list text:style-name="LB"><text:list-item><text:p text:style-name="Standard">One</text:p></text:list-item><text:list-item><text:p text:style-name="Standard">Two</text:p><text:list text:style-name="LN"><text:list-item><text:p text:style-name="Standard">Two, first</text:p></text:list-item><text:list-item><text:p text:style-name="Standard">Two, second</text:p><text:p text:style-name="Standard">with a paragraph of its own</text:p></text:list-item></text:list></text:list-item><text:list-item><text:p text:style-name="Standard">Three</text:p></text:list-item></text:list>'
	.'<text:list text:style-name="LN"><text:list-item><text:p text:style-name="Standard">First</text:p></text:list-item><text:list-item><text:p text:style-name="Standard">☑<text:s/>Done</text:p></text:list-item><text:list-item><text:p text:style-name="Standard">☐<text:s/>Open</text:p></text:list-item></text:list>');
checkPregMatch('the two kinds of list tell what they look like at every level', odtPart(odtWrite($listLst), 'content.xml'), '/<text:list-style style:name="LB"><text:list-level-style-bullet text:level="1" text:bullet-char="•">.*<text:list-level-style-bullet text:level="10" [^>]*>.*<\/text:list-style><text:list-style style:name="LN"><text:list-level-style-number text:level="1" style:num-suffix="\." style:num-format="1">.*<text:list-level-style-number text:level="2" style:num-suffix="\." style:num-format="a">/s');

// Tables
$innrTbl = $mkBlck('tableSet', Array(), Array($mkBlck('tableRow', Array(), Array($mkCell('inner')))));
$spanTbl = $mkBlck('tableSet', Array(), Array(
	$mkBlck('tableTop', Array(), Array($mkCell('Show'), $mkCell('Seats', Array('align'=>'alignRgt')), $mkCell('More', Array('colsp'=>3)))),
	$mkBlck('tableRow', Array(), Array($mkCell('tall', Array('rowsp'=>3)), $mkCell('wide', Array('colsp'=>2)), $mkCell('both', Array('rowsp'=>2, 'colsp'=>2)))),
	$mkBlck('tableRow', Array(), Array($mkCell('b1'), $mkCell('b2'))),
	$mkBlck('tableRow', Array(), Array($mkCell('c1'), $mkCell(''), $mkCell('c3'), $mkCell('', Array(), Array($innrTbl)))),
	$mkBlck('tableTop', Array(), Array($mkCell('A header row met further down')))
));
$tblText = odtBody(Array($spanTbl));
checkPregMatch('a table: its columns, the header rows that start it, repeated on each page', $tblText, '/^<table:table table:name="Table\d" table:style-name="Tbl"><table:table-column table:number-columns-repeated="5"\/><table:table-header-rows><table:table-row><table:table-cell table:style-name="CelH" office:value-type="string"><text:p text:style-name="Table_20_Heading">Show<\/text:p><\/table:table-cell><table:table-cell table:style-name="CelH" office:value-type="string"><text:p text:style-name="P1">Seats<\/text:p><\/table:table-cell><table:table-cell table:style-name="CelH" office:value-type="string" table:number-columns-spanned="3">.*?<\/table:table-cell><table:covered-table-cell\/><table:covered-table-cell\/><\/table:table-row><\/table:table-header-rows><table:table-row>/');
checkPregMatch('a cell over several columns or rows says so, and what it covers is told covered, in its row and in those under it', $tblText, '/<\/table:table-header-rows><table:table-row><table:table-cell table:style-name="Cel" office:value-type="string" table:number-rows-spanned="3">.*?<\/table:table-cell><table:table-cell table:style-name="Cel" office:value-type="string" table:number-columns-spanned="2">.*?<\/table:table-cell><table:covered-table-cell\/><table:table-cell table:style-name="Cel" office:value-type="string" table:number-columns-spanned="2" table:number-rows-spanned="2">.*?<\/table:table-cell><table:covered-table-cell\/><\/table:table-row><table:table-row><table:covered-table-cell\/><table:table-cell [^>]*><text:p text:style-name="Standard">b1<\/text:p><\/table:table-cell><table:table-cell [^>]*>.*?b2.*?<\/table:table-cell><table:covered-table-cell\/><table:covered-table-cell\/><\/table:table-row><table:table-row><table:covered-table-cell\/><table:table-cell/');
checkPregMatch('a cell holding nothing holds a paragraph; a cell holding a table holds that table; a header row met further down is a row', $tblText, '/<table:table-cell table:style-name="Cel" office:value-type="string"><text:p text:style-name="Standard"><\/text:p><\/table:table-cell>.*<table:table-cell table:style-name="Cel" office:value-type="string"><table:table table:name="Table\d" table:style-name="Tbl">.*inner.*<\/table:table><\/table:table-cell><\/table:table-row><table:table-row><table:table-cell table:style-name="CelH" office:value-type="string"><text:p text:style-name="Table_20_Heading">A header row met further down<\/text:p><\/table:table-cell><\/table:table-row><\/table:table>$/');
checkIdentical('a table without rows is not written', odtBody(Array($mkBlck('tableSet'))), '');
checkPregMatch('a table starts no page by itself: a paragraph before it does', odtBody(Array($mkBlck('ruleLine', Array(), Array(), Array('break'=>true)), $mkBlck('tableSet', Array(), Array($mkBlck('tableRow', Array(), Array($mkCell('x'))))))), '/^<text:p text:style-name="P1"><\/text:p><table:table /');

// Notes
$noteLst = Array(
	$mkBlck('paragrph', Array($mkSpan('Text'), $mkSpan('1', Array(), '#fnote1'), $mkSpan(' more'), $mkSpan('1', Array(), '#enote1'), $mkSpan(' again'), $mkSpan('1', Array(), '#fnote1'), $mkSpan(' and nowhere'), $mkSpan('7', Array(), '#fnote7'))),
	$mkBlck('footnote', Array($mkSpan('A note with a '), $mkSpan('link', Array(), 'https://nyuark.test/note'), $mkSpan(' and a note in it'), $mkSpan('1', Array(), '#enote1')), Array($mkBlck('paragrph', Array($mkSpan('Second paragraph')))), Array('tagid'=>'fnote1', 'numbr'=>1)),
	$mkBlck('footnote', Array($mkSpan('A note of the end')), Array(), Array('tagid'=>'enote1', 'numbr'=>1)),
	$mkBlck('footnote', Array($mkSpan('Never referred to')), Array(), Array('tagid'=>'fnote5', 'numbr'=>5))
);
checkIdentical('a note is written where the text first refers to it; a note holds no other; a second reference is text; a note never referred to ends the text', odtBody($noteLst), '<text:p text:style-name="Standard">Text<text:note text:id="ftn1" text:note-class="footnote"><text:note-citation>1</text:note-citation><text:note-body><text:p text:style-name="Footnote">A note with a<text:s/><text:a xlink:type="simple" xlink:href="https://nyuark.test/note" text:style-name="Internet_20_link" text:visited-style-name="Internet_20_link">link</text:a><text:s/>and a note in it1</text:p><text:p text:style-name="Standard">Second paragraph</text:p></text:note-body></text:note><text:s/>more<text:note text:id="edn1" text:note-class="endnote"><text:note-citation>1</text:note-citation><text:note-body><text:p text:style-name="Endnote">A note of the end</text:p></text:note-body></text:note><text:s/>again1<text:s/>and nowhere<text:a xlink:type="simple" xlink:href="#fnote7" text:style-name="Internet_20_link" text:visited-style-name="Internet_20_link">7</text:a></text:p><text:p text:style-name="Standard">Never referred to</text:p>');

// Named styles
$stylDoc = odtWrite(Array(
	$mkBlck('paragrph', Array($mkSpan('one')), Array(), Array('style'=>'Highlight')),
	$mkBlck('paragrph', Array($mkSpan('two')), Array(), Array('style'=>'Only "Named" <style>', 'align'=>'alignRgt')),
	$mkBlck('paragrph', Array($mkSpan('three')), Array(), Array('style'=>'Normal')),
	$mkBlck('headline', Array($mkSpan('four')), Array(), Array('level'=>2, 'style'=>'Highlight'))
), Array('frmat'=>Array('Highlight'=>Array('fonts'=>'Georgia "x"', 'sizes'=>14.5, 'color'=>'#C00000', 'marks'=>Array('undrLine', 'boldFace', 'emphasis', 'crossOut'), 'align'=>'alignCtr'), 'Unused'=>Array('sizes'=>'huge', 'color'=>'red;'), 'heading 1'=>Array('sizes'=>99))));
checkPregMatch('a style the content tells of is a style of the document, with what it gives', odtPart($stylDoc, 'styles.xml'), '/<style:style style:name="nyu1_Highlight" style:display-name="Highlight" style:family="paragraph" style:parent-style-name="Standard" style:class="text"><style:paragraph-properties fo:text-align="center"\/><style:text-properties fo:font-family="\'Georgia x\'" fo:font-size="14\.5pt" [^>]*fo:color="#c00000" fo:font-weight="bold" [^>]*fo:font-style="italic" [^>]*style:text-underline-style="solid" [^>]*style:text-line-through-style="solid" [^>]*\/><\/style:style>/');
checkPregMatch('what is not a size or a colour is left out, a style only named is defined, its name written as a document writes names', odtPart($stylDoc, 'styles.xml'), '/<style:style style:name="nyu2_Unused" style:display-name="Unused" style:family="paragraph" style:parent-style-name="Standard" style:class="text"><\/style:style><style:style style:name="nyu3_Only_20__22_Named_22__20__3c_style_3e_" style:display-name="Only &quot;Named&quot; &lt;style&gt;" style:family="paragraph" style:parent-style-name="Standard" style:class="text"><\/style:style><\/office:styles>/');
checkPregMatch('a paragraph is given the style it names, a style of its own based on it when it adds to it; a heading keeps the style of its level', odtPart($stylDoc, 'content.xml'), '/<style:style style:name="P1" style:family="paragraph" style:parent-style-name="nyu3_Only_20__22_Named_22__20__3c_style_3e_"><style:paragraph-properties fo:text-align="end"\/><\/style:style>.*<office:text><text:p text:style-name="nyu1_Highlight">one<\/text:p><text:p text:style-name="P1">two<\/text:p><text:p text:style-name="Standard">three<\/text:p><text:h text:style-name="Heading_20_2" text:outline-level="2">four<\/text:h>/s');

if( !class_exists('ZipArchive') ){
	recordSkipping('documents written, then read back', 'the zip extension is not loaded');
	finishChecking();
}

// Written, then read back
checkIdentical('headings of every level', odtShow(odtBack(array_map(function(Int $levelValue) use ($mkBlck, $mkSpan){ return $mkBlck('headline', Array($mkSpan('Level '.$levelValue)), Array(), Array('level'=>$levelValue)); }, range(1, 6)))['items'], false), Array('headline level=1 {Level 1}', 'headline level=2 {Level 2}', 'headline level=3 {Level 3}', 'headline level=4 {Level 4}', 'headline level=5 {Level 5}', 'headline level=6 {Level 6}'));
$textLst = Array(
	$mkBlck('paragrph', Array($mkSpan('plain '), $mkSpan('bold', Array('boldFace')), $mkSpan(' '), $mkSpan('both', Array('boldFace', 'emphasis')), $mkSpan(' '), $mkSpan('under', Array('undrLine')), $mkSpan(' '), $mkSpan('struck', Array('crossOut')))),
	$mkBlck('paragrph', Array($mkSpan('centred')), Array(), Array('align'=>'alignCtr', 'tagid'=>'middle')),
	$mkBlck('paragrph', Array($mkSpan(" Café & <b> — 日本語 😀\nline two\ttabbed   spaced "))),
	$mkBlck('paragrph'),
	$mkBlck('paragrph', Array($mkSpan('see '), $mkSpan('the site', Array(), 'https://nyuark.test/?a=1&b=2'), $mkSpan(' or '), $mkSpan('that place', Array('emphasis'), '#middle'), $mkSpan(' or '), $mkSpan('nothing', Array(), 'javascript:alert(1)'))),
	$mkBlck('citation', Array($mkSpan('Stay text.')), Array(), Array('cites'=>'Someone')),
	$mkBlck('codeBlck', Array($mkSpan("function a() {\n\treturn 1;   // kept\n}"))),
	$mkBlck('ruleLine'),
	$mkBlck('paragrph', Array($mkSpan('before'))),
	$mkBlck('ruleLine', Array(), Array(), Array('break'=>true)),
	$mkBlck('paragrph', Array($mkSpan('after')))
);
$textShw = Array(
	'paragrph {plain }{bold|boldFace}{ }{both|boldFace,emphasis}{ }{under|undrLine}{ }{struck|crossOut}', 'paragrph align="alignCtr" tagid="middle" {centred}', "paragrph { Café & <b> — 日本語 😀\nline two\ttabbed   spaced }", 'paragrph',
	'paragrph {see }{the site|https://nyuark.test/?a=1&b=2}{ or }{that place|emphasis|#middle}{ or nothing}', 'citation {Stay text.}', 'citation align="alignRgt" {— Someone}', "codeBlck {function a() {\n\treturn 1;   // kept\n}}", 'paragrph', 'paragrph {before}', 'ruleLine break=true', 'paragrph {after}'
);
checkIdentical('text: formatting, alignment, places, links, a quote, code, a line, the end of a page', odtShow(odtBack($textLst)['items'], false), $textShw);
checkIdentical('itemList: read back as they were given', odtShow(odtBack($listLst)['items'], false), Array('itemList order=false', '  listItem {One}', '  listItem {Two}', '    itemList order=true', '      listItem {Two, first}', '      listItem {Two, second}', '        paragrph {with a paragraph of its own}', '  listItem {Three}', 'itemList order=true', '  listItem {First}', '  listItem {☑ Done}', '  listItem {☐ Open}'));
checkIdentical('a table: its header rows, its cells, what they cover, what they hold', odtShow(odtBack(Array($spanTbl))['items']), Array(
	'tableSet', '  tableTop', '    cellText style="Table Heading" {Show}', '    cellText align="alignRgt" style="Table Heading" {Seats}', '    cellText colsp=3 style="Table Heading" {More}',
	'  tableRow', '    cellText rowsp=3 {tall}', '    cellText colsp=2 {wide}', '    cellText colsp=2 rowsp=2 {both}', '  tableRow', '    cellText {b1}', '    cellText {b2}',
	'  tableRow', '    cellText {c1}', '    cellText', '    cellText {c3}', '    cellText', '      tableSet', '        tableRow', '          cellText {inner}', '  tableRow', '    cellText style="Table Heading" {A header row met further down}'
));
$noteSet = odtBack($noteLst);
checkIdentical('notes: read back where the text refers to them, the one never referred to as text', odtShow($noteSet['items']), Array(
	'paragrph {Text}{1|#fnote1}{ more}{1|#enote1}{ again1 and nowhere}{7|#fnote7}', 'paragrph {Never referred to}',
	'footnote tagid="fnote1" numbr=1 style="Footnote" {A note with a }{link|https://nyuark.test/note}{ and a note in it1}', '  paragrph {Second paragraph}', 'footnote tagid="enote1" numbr=1 style="Endnote" {A note of the end}'
));
checkIdentical('written and read a second time, the document is the same', odtShow(odtBack($noteSet['items'], $noteSet['infos'])['items']), odtShow($noteSet['items']));
$propSet = odtBack(Array($mkBlck('paragrph', Array($mkSpan('one')), Array(), Array('style'=>'Highlight')), $mkBlck('paragrph', Array($mkSpan('two')), Array(), Array('style'=>'Only Named'))), Array(
	'title'=>'A title', 'authr'=>'An author', 'props'=>Array('Subject'=>'Tests', 'Keywords'=>'one, two', 'Client'=>'Théâtre', 'Revision'=>7, 'Approved'=>true, 'Ratio'=>0.5),
	'frmat'=>Array('Highlight'=>Array('fonts'=>'Georgia', 'sizes'=>14, 'color'=>'#C00000', 'marks'=>Array('boldFace', 'emphasis'), 'align'=>'alignCtr'))
));
checkIdentical('the properties, each with the kind of its value', Array($propSet['infos']['title'], $propSet['infos']['authr'], $propSet['infos']['props']), Array('A title', 'An author', Array('Title'=>'A title', 'Author'=>'An author', 'Subject'=>'Tests', 'Keywords'=>'one, two', 'Client'=>'Théâtre', 'Revision'=>7, 'Approved'=>true, 'Ratio'=>0.5)));
checkIdentical('named styles: the paragraphs name them, the document tells what they give', Array(odtShow($propSet['items']), $propSet['infos']['frmat']['Highlight'], $propSet['infos']['frmat']['Only Named']), Array(
	Array('paragrph style="Highlight" {one}', 'paragrph style="Only Named" {two}'),
	Array('class'=>'highlight', 'fonts'=>'Georgia', 'sizes'=>14, 'color'=>'#C00000', 'marks'=>Array('boldFace', 'emphasis'), 'align'=>'alignCtr'),
	Array('class'=>'only-named', 'fonts'=>'Calibri', 'sizes'=>11)
));
$shetLst = Array(
	Array('title'=>'Shows', 'attrs'=>Array('shown'=>true, 'merge'=>Array()), 'cells'=>Array(Array('Show'=>'Alpha', 'Seats'=>120, 'Open'=>true, 'Note'=>null), Array('Show'=>'=SUM(B2)', 'Seats'=>0.1, 'Open'=>false, 'Note'=>'x'))),
	Array('title'=>'Hidden', 'attrs'=>Array('shown'=>false, 'merge'=>Array()), 'cells'=>Array(Array('secret')))
);
checkIdentical('sheets: a table each, under a heading bearing its name; a hidden one is not written', odtShow(odtBack($shetLst, Array(), 'sheet')['items'], false), Array('headline level=2 {Shows}', 'tableSet', '  tableTop', '    cellText {Show}', '    cellText {Seats}', '    cellText {Open}', '    cellText {Note}', '  tableRow', '    cellText {Alpha}', '    cellText align="alignRgt" {120}', '    cellText {TRUE}', '    cellText', '  tableRow', '    cellText {=SUM(B2)}', '    cellText align="alignRgt" {0.1}', '    cellText {FALSE}', '    cellText {x}'));

// One content, two formats: the same blocks, but for what each format adds. (What only one of them can tell is left out
// of this content: an entry holding a paragraph, which Word reads as the end of its list, and a header row further down a table.)
$bothLst = array_merge($textLst, Array(
	$mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('One'))), $mkBlck('listItem', Array($mkSpan('Two')), Array($mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('Deep')))), Array('order'=>true)))), $mkBlck('listItem', Array($mkSpan('Done')), Array(), Array('check'=>true))), Array('order'=>false)),
	$mkBlck('tableSet', Array(), Array(
		$mkBlck('tableTop', Array(), Array($mkCell('Show'), $mkCell('Seats', Array('align'=>'alignRgt')), $mkCell('More', Array('colsp'=>2)))),
		$mkBlck('tableRow', Array(), Array($mkCell('tall', Array('rowsp'=>2)), $mkCell('120', Array('align'=>'alignRgt')), $mkCell('a'), $mkCell('', Array(), Array($innrTbl)))),
		$mkBlck('tableRow', Array(), Array($mkCell('80', Array('align'=>'alignRgt')), $mkCell('wide', Array('colsp'=>2))))
	)),
	$mkBlck('paragrph', Array($mkSpan('The end.')))
));
$wordShw = odtShow((new DocxRead())->parseFluxArray(Fluxd::openBytesFluxd((new Docx())->renderDocument(Array('shape'=>'block', 'infos'=>Array(), 'items'=>$bothLst))))['items'], false);
$openShw = odtShow(odtBack($bothLst)['items'], false);
// Word shows its links underlined, and reads them so
checkIdentical('the same content written as docx and as odt is read the same, but for the links Word underlines', str_replace(Array('|undrLine|', '|emphasis,undrLine|'), Array('|', '|emphasis|'), $wordShw), $openShw);
checkTrueValue('and that content holds every kind of block', count($openShw)>30 && in_array('          cellText {inner}', $openShw, true) && in_array('ruleLine break=true', $openShw, true));
// A document of LibreOffice, read, written, and read again: the same text
$smplSet = (new OdtxRead())->parseFluxArray(Fluxd::openLocalFluxd(TST_DIR.DS.'fixtures'.DS.'office'.DS.'sample.odt'));
$againSt = odtBack($smplSet['items'], $smplSet['infos']);
checkIdentical('a document of LibreOffice read, written and read again keeps its text', Array(preg_replace('/\s+/', ' ', Shape::getTextOfDcmnt($againSt['items']))===preg_replace('/\s+/', ' ', Shape::getTextOfDcmnt($smplSet['items'])), strlen(Shape::getTextOfDcmnt($smplSet['items']))>0), Array(true, true));

finishChecking();
