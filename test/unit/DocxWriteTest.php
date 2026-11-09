<?php

// Data\Writers\Docx : Word documents written from text blocks.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Fluxd;
use nyu\Data\Parsers\Docx as DocxRead;
use nyu\Data\Shape;
use nyu\Data\Writers\Docx;
use nyu\Data\Writers\Index;

/**
 * Writes blocks as a document, or returns the reason it was refused.
 *
 * @param Array $blocksList The blocks.
 * @param Array $writeOptns Options of the writer.
 * @param Array $infosArray What is told of the document.
 * @return mixed The bytes, or a 'THROWN ...' string.
 */
function docWrite( Array $blocksList, Array $writeOptns=Array(), Array $infosArray=Array() ): Mixed {
	return runCatchThrown(function() use ($blocksList, $writeOptns, $infosArray){
		return (new Docx())->renderDocument(Array('shape'=>'block', 'infos'=>$infosArray, 'items'=>$blocksList), $writeOptns);
	});
}

/**
 * Gives a part of a document, read without the zip extension.
 *
 * @param String $archvBytes The bytes of the archive.
 * @param String $insideName Name of the part.
 * @return String The part, '' when it is not found.
 */
function docPart( String $archvBytes, String $insideName ): String {
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
function docBody( Array $blocksList ): String {
	$bodyPrt = Array();
	return preg_match('/<w:body>(.*)<w:sectPr>/s', docPart(strval(docWrite($blocksList)), 'word/document.xml'), $bodyPrt) ? $bodyPrt[1] : '';
}

/**
 * Shows blocks in short: their kind, what tells them apart, their text
 * with its formatting and its links, and the blocks they hold.
 *
 * @param Array $blocksList The blocks.
 * @return Array A line for each block.
 */
function docShow( Array $blocksList ): Array {
	$showLst = Array();
	foreach($blocksList as $blkArr){
		$attrTxt = '';
		foreach(Array('level', 'order', 'align', 'tagid', 'break') as $atrKey){
			$attrTxt .= isset($blkArr['attrs'][$atrKey]) ? ' '.$atrKey.'='.json_encode($blkArr['attrs'][$atrKey]) : '';
		}
		$spanTxt = '';
		foreach($blkArr['spans'] as $oneSpn){
			$spanTxt .= '{'.$oneSpn['texts'].(empty($oneSpn['marks']) ? '' : '|'.implode(',', $oneSpn['marks'])).(($oneSpn['links']==='') ? '' : '|'.$oneSpn['links']).'}';
		}
		$showLst[] = $blkArr['block'].$attrTxt.(($spanTxt==='') ? '' : ' '.$spanTxt);
		foreach(docShow($blkArr['items']) as $subLne){
			$showLst[] = '  '.$subLne;
		}
	}
	return $showLst;
}

/**
 * Writes blocks as a document, reads it back, and shows it in short.
 *
 * @param Array $blocksList The blocks.
 * @return mixed A line for each block read, or a 'THROWN ...' string.
 */
function docBack( Array $blocksList ): Mixed {
	return runCatchThrown(function() use ($blocksList){
		return docShow((new DocxRead())->parseFluxArray(Fluxd::openBytesFluxd((new Docx())->renderDocument(Array('shape'=>'block', 'infos'=>Array(), 'items'=>$blocksList))))['items']);
	});
}

$mkSpan = Array(Shape::class, 'makeASpanArray');
$mkBlck = Array(Shape::class, 'makeBlockArray');

// The document, part by part
$bookTxt = docWrite(Array($mkBlck('headline', Array($mkSpan('Title')), Array(), Array('level'=>1)), $mkBlck('paragrph', Array($mkSpan('Text')))), Array(), Array('title'=>'Season <2026>', 'authr'=>'Zoé'));
checkIdentical('a document is a zip archive', substr($bookTxt, 0, 2), 'PK');
checkTrueValue('every part is well-formed', array_reduce(Array('[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'word/document.xml', 'word/_rels/document.xml.rels', 'word/styles.xml', 'word/numbering.xml'), function($allAreGood, $insideName) use ($bookTxt){ return $allAreGood && @simplexml_load_string(docPart($bookTxt, $insideName))!==false; }, true));
checkPregMatch('the title and the author of the document', docPart($bookTxt, 'docProps/core.xml'), '/<dc:title>Season &lt;2026&gt;<\/dc:title><dc:creator>Zoé<\/dc:creator>/');
checkPregMatch('the pages: A4, standing, unless told otherwise', docPart($bookTxt, 'word/document.xml'), '/<w:sectPr><w:pgSz w:w="11906" w:h="16838"\/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/');
checkPregMatch('another size of paper, lying', docPart(docWrite(Array(), Array('paper'=>'USLETTER', 'lands'=>true)), 'word/document.xml'), '/<w:body><w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"\/>/');
checkPregMatch('a size of paper that is not one', docWrite(Array(), Array('paper'=>'huge')), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Invalid document option: unknown paper huge/');
checkPregMatch('a shape that is not blocks', runCatchThrown(function(){ return (new Docx())->renderDocument(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>Array())); }), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as a document: nodes/');
checkIdentical('the writer of the docx extension', Array(get_class(Index::forExtnsParser('DOCX')), Index::contentTypeFor('docx')), Array('nyu\\Data\\Writers\\Docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
checkPregMatch('the styles Word finds its way by, under the names it gives them', docPart($bookTxt, 'word/styles.xml'), '/<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"\/>.*<w:outlineLvl w:val="0"\/>.*w:styleId="Heading6"><w:name w:val="heading 6"\/>.*<w:name w:val="List Paragraph"\/>.*<w:name w:val="Quote"\/>.*<w:name w:val="HTML Preformatted"\/>.*<w:name w:val="Hyperlink"\/>/s');

// Blocks, as Word is told of them
checkIdentical('a heading, with the style of its level', docBody(Array($mkBlck('headline', Array($mkSpan('Chapter')), Array(), Array('level'=>2)))), '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t xml:space="preserve">Chapter</w:t></w:r></w:p>');
checkIdentical('a level out of those Word has is brought back among them', Array(substr(docBody(Array($mkBlck('headline', Array($mkSpan('x')), Array(), Array('level'=>9)))), 0, 41), substr(docBody(Array($mkBlck('headline', Array($mkSpan('x')), Array(), Array('level'=>0)))), 0, 41)), Array('<w:p><w:pPr><w:pStyle w:val="Heading6"/><', '<w:p><w:pPr><w:pStyle w:val="Heading1"/><'));
checkIdentical('formatting, written in the order Word reads it', docBody(Array($mkBlck('paragrph', Array($mkSpan('all', Array('codeSpan', 'undrLine', 'crossOut', 'emphasis', 'boldFace')))))), '<w:p><w:r><w:rPr><w:rFonts w:ascii="Courier New" w:hAnsi="Courier New" w:cs="Courier New"/><w:b/><w:bCs/><w:i/><w:iCs/><w:strike/><w:u w:val="single"/></w:rPr><w:t xml:space="preserve">all</w:t></w:r></w:p>');
checkIdentical('signs stay text, line ends and tabs are written as what they are, spaces are kept', docBody(Array($mkBlck('paragrph', Array($mkSpan("a & <b>\nnext\ttab   end "))))), '<w:p><w:r><w:t xml:space="preserve">a &amp; &lt;b&gt;</w:t><w:br/><w:t xml:space="preserve">next</w:t><w:tab/><w:t xml:space="preserve">tab   end </w:t></w:r></w:p>');
checkIdentical('a paragraph holding nothing is kept', docBody(Array($mkBlck('paragrph'))), '<w:p></w:p>');
checkIdentical('how a paragraph is aligned, and the name of its place', docBody(Array($mkBlck('paragrph', Array($mkSpan('x')), Array(), Array('align'=>'alignJst', 'tagid'=>'my place!')))), '<w:p><w:pPr><w:jc w:val="both"/></w:pPr><w:bookmarkStart w:id="1" w:name="my_place"/><w:bookmarkEnd w:id="1"/><w:r><w:t xml:space="preserve">x</w:t></w:r></w:p>');
checkIdentical('a page ends, a line is drawn', docBody(Array($mkBlck('ruleLine', Array(), Array(), Array('break'=>true)), $mkBlck('ruleLine'))), '<w:p><w:r><w:br w:type="page"/></w:r></w:p><w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr></w:pPr></w:p>');
checkIdentical('code: a paragraph for each line', docBody(Array($mkBlck('codeBlck', Array($mkSpan("if (a) {\n  b();\n}"))))), '<w:p><w:pPr><w:pStyle w:val="HTMLPreformatted"/></w:pPr><w:r><w:t xml:space="preserve">if (a) {</w:t></w:r></w:p><w:p><w:pPr><w:pStyle w:val="HTMLPreformatted"/></w:pPr><w:r><w:t xml:space="preserve">  b();</w:t></w:r></w:p><w:p><w:pPr><w:pStyle w:val="HTMLPreformatted"/></w:pPr><w:r><w:t xml:space="preserve">}</w:t></w:r></w:p>');

// Links
$linkDoc = docWrite(Array($mkBlck('paragrph', Array($mkSpan('site', Array(), 'https://nyuark.test/?a=1&b=2'), $mkSpan('again', Array('boldFace'), 'https://nyuark.test/?a=1&b=2'), $mkSpan('mail', Array(), 'mailto:dev@nyuark.test'), $mkSpan('place', Array(), '#my place!'), $mkSpan('script', Array(), 'javascript:alert(1)'), $mkSpan('data', Array(), 'data:text/html;base64,AAAA')))));
checkPregMatch('an address is kept among the relations of the document, once, and never in its text', docPart($linkDoc, 'word/_rels/document.xml.rels'), '/<Relationship Id="rLk1" Type="[^"]*\/hyperlink" Target="https:\/\/nyuark\.test\/\?a=1&amp;b=2" TargetMode="External"\/><Relationship Id="rLk2" Type="[^"]*\/hyperlink" Target="mailto:dev@nyuark\.test" TargetMode="External"\/><\/Relationships>$/');
checkPregMatch('links, a link to a place of the document, and addresses that are dropped', docPart($linkDoc, 'word/document.xml'), '/<w:hyperlink r:id="rLk1" w:history="1"><w:r><w:rPr><w:rStyle w:val="Hyperlink"\/><\/w:rPr><w:t xml:space="preserve">site<\/w:t><\/w:r><\/w:hyperlink><w:hyperlink r:id="rLk1" w:history="1"><w:r><w:rPr><w:rStyle w:val="Hyperlink"\/><w:b\/><w:bCs\/><\/w:rPr><w:t xml:space="preserve">again<\/w:t><\/w:r><\/w:hyperlink><w:hyperlink r:id="rLk2" w:history="1">.*<\/w:hyperlink><w:hyperlink w:anchor="my_place" w:history="1">.*<\/w:hyperlink><w:r><w:t xml:space="preserve">script<\/w:t><\/w:r><w:r><w:t xml:space="preserve">data<\/w:t><\/w:r><\/w:p>/');
checkIdentical('nothing of a dropped address is written anywhere', Array(strpos(docPart($linkDoc, 'word/document.xml').docPart($linkDoc, 'word/_rels/document.xml.rels'), 'javascript'), strpos(docPart($linkDoc, 'word/document.xml').docPart($linkDoc, 'word/_rels/document.xml.rels'), 'base64')), Array(false, false));

// Lists
$listLst = Array(
	$mkBlck('itemList', Array(), Array(
		$mkBlck('listItem', Array($mkSpan('One'))),
		$mkBlck('listItem', Array($mkSpan('Two')), Array($mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('Two, first'))), $mkBlck('listItem', Array($mkSpan('Two, second')), Array($mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('Deep')))), Array('order'=>false))))), Array('order'=>true)))),
		$mkBlck('listItem', Array($mkSpan('Three')))
	), Array('order'=>false)),
	$mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('First'))), $mkBlck('listItem', Array($mkSpan('Second')))), Array('order'=>true)),
	$mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('Again first')))), Array('order'=>true)),
	$mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('Done')), Array(), Array('check'=>true)), $mkBlck('listItem', Array($mkSpan('Open')), Array(), Array('check'=>false))), Array('order'=>false))
);
$listDoc = docWrite($listLst);
checkPregMatch('an entry is a paragraph of a list, at its level', docPart($listDoc, 'word/document.xml'), '/<w:p><w:pPr><w:pStyle w:val="ListParagraph"\/><w:numPr><w:ilvl w:val="0"\/><w:numId w:val="1"\/><\/w:numPr><\/w:pPr><w:r><w:t xml:space="preserve">One<\/w:t><\/w:r><\/w:p>.*<w:numPr><w:ilvl w:val="1"\/><w:numId w:val="2"\/><\/w:numPr>.*<w:numPr><w:ilvl w:val="2"\/><w:numId w:val="3"\/><\/w:numPr>.*<w:numPr><w:ilvl w:val="0"\/><w:numId w:val="1"\/><\/w:numPr><\/w:pPr><w:r><w:t xml:space="preserve">Three<\/w:t>/s');
checkPregMatch('every list is one for Word: marked or numbered, the numbered ones starting again', docPart($listDoc, 'word/numbering.xml'), '/<w:num w:numId="1"><w:abstractNumId w:val="0"\/><\/w:num><w:num w:numId="2"><w:abstractNumId w:val="1"\/><w:lvlOverride w:ilvl="0"><w:startOverride w:val="1"\/><\/w:lvlOverride>.*<w:num w:numId="3"><w:abstractNumId w:val="0"\/><\/w:num><w:num w:numId="4"><w:abstractNumId w:val="1"\/>.*<w:num w:numId="5"><w:abstractNumId w:val="1"\/>.*<w:num w:numId="6"><w:abstractNumId w:val="0"\/><\/w:num><\/w:numbering>$/s');
checkPregMatch('a box to tick is the sign of one', docPart($listDoc, 'word/document.xml'), '/<w:t xml:space="preserve">☑ <\/w:t><\/w:r><w:r><w:t xml:space="preserve">Done<\/w:t>.*<w:t xml:space="preserve">☐ <\/w:t><\/w:r><w:r><w:t xml:space="preserve">Open<\/w:t>/s');

// A kind not written as such yet gives its text
checkIdentical('a picture is written as what describes it for now', docBody(Array($mkBlck('imageBox', Array($mkSpan('A red square')), Array(), Array('sourc'=>'media/red.png')))), '<w:p><w:r><w:t xml:space="preserve">A red square</w:t></w:r></w:p>');

if( !class_exists('ZipArchive') ){
	recordSkipping('documents written, then read back', 'the zip extension is not loaded');
	finishChecking();
}

// Written, then read back
checkIdentical('headings of every level', docBack(array_map(function(Int $levelValue) use ($mkBlck, $mkSpan){ return $mkBlck('headline', Array($mkSpan('Level '.$levelValue)), Array(), Array('level'=>$levelValue)); }, range(1, 6))), Array('headline level=1 {Level 1}', 'headline level=2 {Level 2}', 'headline level=3 {Level 3}', 'headline level=4 {Level 4}', 'headline level=5 {Level 5}', 'headline level=6 {Level 6}'));
checkIdentical('paragraphs: formatting, alignment, the name of a place, text of every kind', docBack(Array(
	$mkBlck('paragrph', Array($mkSpan('plain '), $mkSpan('bold', Array('boldFace')), $mkSpan(' '), $mkSpan('both', Array('boldFace', 'emphasis')), $mkSpan(' '), $mkSpan('under', Array('undrLine')), $mkSpan(' '), $mkSpan('struck', Array('crossOut')))),
	$mkBlck('paragrph', Array($mkSpan('centred')), Array(), Array('align'=>'alignCtr', 'tagid'=>'middle')),
	$mkBlck('paragrph', Array($mkSpan("Café & <b> — 日本語 😀\nline two\ttabbed   spaced"))),
	$mkBlck('paragrph'),
	$mkBlck('paragrph', Array($mkSpan('=SUM(A1) {MERGEFIELD x}')))
)), Array('paragrph {plain }{bold|boldFace}{ }{both|boldFace,emphasis}{ }{under|undrLine}{ }{struck|crossOut}', 'paragrph align="alignCtr" tagid="middle" {centred}', "paragrph {Café & <b> — 日本語 😀\nline two\ttabbed   spaced}", 'paragrph', 'paragrph {=SUM(A1) {MERGEFIELD x}}'));
checkIdentical('links: Word shows them underlined, and they are read so', docBack(Array($mkBlck('paragrph', Array($mkSpan('see '), $mkSpan('the site', Array(), 'https://nyuark.test/?a=1&b=2'), $mkSpan(' or '), $mkSpan('that place', Array(), '#middle'), $mkSpan(' or '), $mkSpan('nothing', Array(), 'javascript:alert(1)'))))), Array('paragrph {see }{the site|undrLine|https://nyuark.test/?a=1&b=2}{ or }{that place|undrLine|#middle}{ or nothing}'));
checkIdentical('itemList: marked and numbered, one in another, one after another', docBack($listLst), Array(
	'itemList order=false', '  listItem {One}', '  listItem {Two}', '    itemList order=true', '      listItem {Two, first}', '      listItem {Two, second}', '        itemList order=false', '          listItem {Deep}', '  listItem {Three}',
	'itemList order=true', '  listItem {First}', '  listItem {Second}', 'itemList order=true', '  listItem {Again first}', 'itemList order=false', '  listItem {☑ Done}', '  listItem {☐ Open}'
));
checkIdentical('a quote and who is quoted, code, a line, the end of a page', docBack(Array(
	$mkBlck('citation', Array($mkSpan('Stay text.')), Array(), Array('cites'=>'Someone')),
	$mkBlck('codeBlck', Array($mkSpan("function a() {\n\treturn 1;   // kept\n}")), Array(), Array('langg'=>'php')),
	$mkBlck('ruleLine'),
	$mkBlck('paragrph', Array($mkSpan('before'))),
	$mkBlck('ruleLine', Array(), Array(), Array('break'=>true)),
	$mkBlck('paragrph', Array($mkSpan('after')))
)), Array('citation {Stay text.}', 'citation align="alignRgt" {— Someone}', "codeBlck {function a() {\n\treturn 1;   // kept\n}}", 'paragrph', 'paragrph {before}', 'ruleLine break=true', 'paragrph {after}'));
$backSet = (new DocxRead())->parseFluxArray(Fluxd::openBytesFluxd(docWrite(Array($mkBlck('paragrph', Array($mkSpan('x')))), Array(), Array('title'=>'A title', 'authr'=>'An author'))));
checkIdentical('the title and the author are read back', Array($backSet['infos']['title'], $backSet['infos']['authr']), Array('A title', 'An author'));
// Documents of Word, read, written, and read again: the text is the same
foreach(Array('SampleDoc.docx', 'heading123.docx', 'Numbering.docx') as $smplNme){
	$smplSet = (new DocxRead())->parseFluxArray(Fluxd::openLocalFluxd(TST_DIR.DS.'fixtures'.DS.'office'.DS.$smplNme));
	$againSt = (new DocxRead())->parseFluxArray(Fluxd::openBytesFluxd((new Docx())->renderDocument($smplSet)));
	checkIdentical('a document of Word read, written and read again keeps its text: '.$smplNme, Array(preg_replace('/\s+/', ' ', Shape::documentAsText($againSt['items']))===preg_replace('/\s+/', ' ', Shape::documentAsText($smplSet['items'])), strlen(Shape::documentAsText($smplSet['items']))>0), Array(true, true));
}

finishChecking();
