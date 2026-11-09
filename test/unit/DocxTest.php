<?php

// Data\Parsers\Docx : Word documents read into text blocks.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Fluxd;
use nyu\Data\Shape;
use nyu\Data\Parsers\Docx;
use nyu\Data\Parsers\Index;
use nyu\Data\Writers\Html;

if( !class_exists('ZipArchive') ){
	recordSkipping('documents', 'the zip extension is not loaded');
	finishChecking();
}

const WORDMAIN = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office"';
const WORDRELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const PACKRELS = 'xmlns="http://schemas.openxmlformats.org/package/2006/relationships"';

/**
 * Builds a Word document in the temporary folder. Its styles name three
 * heading styles, a title, a style based on a heading, a style of list
 * and two styles of characters, and no style for the paragraphs that
 * name none; its lists are numbered 1 (marks) and 2 (numbers, then marks
 * one level in); its relations point to two addresses, a script and two
 * images.
 *
 * @param String $archivName Name of the file to make.
 * @param String $bodyOfDocu The XML of its body.
 * @param Array  $extraParts Parts to add or to replace, name => content; null leaves a part out.
 * @param String $extraRelts The XML of relations to add to those of the document.
 * @return String The path of the document.
 */
function makeDocx( String $archivName, String $bodyOfDocu, Array $extraParts=Array(), String $extraRelts='' ): String {
	$relsXml = '<Relationship Id="rIdS" Type="'.WORDRELS.'/styles" Target="styles.xml"/><Relationship Id="rIdN" Type="'.WORDRELS.'/numbering" Target="numbering.xml"/>'
		.'<Relationship Id="rIdL" Type="'.WORDRELS.'/hyperlink" Target="https://nyuark.test/a?x=1&amp;y=2" TargetMode="External"/><Relationship Id="rIdM" Type="'.WORDRELS.'/hyperlink" Target="mailto:a@nyuark.test" TargetMode="External"/><Relationship Id="rIdJ" Type="'.WORDRELS.'/hyperlink" Target="javascript:alert(1)" TargetMode="External"/>'
		.'<Relationship Id="rIdP" Type="'.WORDRELS.'/image" Target="media/image1.png"/><Relationship Id="rIdO" Type="'.WORDRELS.'/image" Target="https://nyuark.test/far.png" TargetMode="External"/>'.$extraRelts;
	$partLst = Array(
		'_rels/.rels'=>'<Relationships '.PACKRELS.'><Relationship Id="rId1" Type="'.WORDRELS.'/officeDocument" Target="word/document.xml"/></Relationships>',
		'word/document.xml'=>'<?xml version="1.0" encoding="UTF-8"?><w:document '.WORDMAIN.'><w:body>'.$bodyOfDocu.'<w:sectPr/></w:body></w:document>',
		'word/_rels/document.xml.rels'=>'<Relationships '.PACKRELS.'>'.$relsXml.'</Relationships>',
		'word/styles.xml'=>'<w:styles '.WORDMAIN.'>'
			.'<w:style w:type="paragraph" w:styleId="Titre1"><w:name w:val="heading 1"/><w:rPr><w:b/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Titre2"><w:name w:val="Heading 2"/></w:style><w:style w:type="paragraph" w:styleId="Titre9"><w:name w:val="heading 9"/></w:style>'
			.'<w:style w:type="paragraph" w:styleId="Titre"><w:name w:val="Title"/></w:style><w:style w:type="paragraph" w:styleId="Chapitre"><w:name w:val="Chapter"/><w:basedOn w:val="Titre2"/></w:style><w:style w:type="paragraph" w:styleId="Plan"><w:name w:val="Outline"/><w:pPr><w:outlineLvl w:val="2"/></w:pPr></w:style>'
			.'<w:style w:type="paragraph" w:styleId="Puce"><w:name w:val="List Bullet"/><w:pPr><w:numPr><w:numId w:val="1"/></w:numPr></w:pPr></w:style>'
			.'<w:style w:type="character" w:styleId="Fort"><w:name w:val="Strong"/><w:rPr><w:b/></w:rPr></w:style><w:style w:type="character" w:styleId="TresFort"><w:name w:val="Very Strong"/><w:basedOn w:val="Fort"/><w:rPr><w:i/></w:rPr></w:style>'
			.'</w:styles>',
		'word/numbering.xml'=>'<w:numbering '.WORDMAIN.'><w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum><w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="bullet"/></w:lvl><w:lvl w:ilvl="2"><w:numFmt w:val="lowerLetter"/></w:lvl></w:abstractNum><w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num></w:numbering>'
	);
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
 * Writes the XML of a run of text.
 *
 * @param String $textString The text.
 * @param String $propsOfRun The XML of its properties.
 * @return String The XML.
 */
function wRun( String $textString, String $propsOfRun='' ): String {
	return '<w:r>'.(($propsOfRun!=='') ? '<w:rPr>'.$propsOfRun.'</w:rPr>' : '').'<w:t xml:space="preserve">'.$textString.'</w:t></w:r>';
}

/**
 * Writes the XML of a paragraph.
 *
 * @param String $runsOfPara The XML of what it holds.
 * @param String $propsOfPar The XML of its properties.
 * @return String The XML.
 */
function wPar( String $runsOfPara, String $propsOfPar='' ): String {
	return '<w:p>'.(($propsOfPar!=='') ? '<w:pPr>'.$propsOfPar.'</w:pPr>' : '').$runsOfPara.'</w:p>';
}

/**
 * Writes the XML of the properties placing a paragraph in a list.
 *
 * @param Int $listNumber The list.
 * @param Int $levelValue The level in it, from 0.
 * @return String The XML.
 */
function wNum( Int $listNumber, Int $levelValue=0 ): String {
	return '<w:numPr><w:ilvl w:val="'.$levelValue.'"/><w:numId w:val="'.$listNumber.'"/></w:numPr>';
}

/**
 * Writes the XML of a field made of several runs.
 *
 * @param String $fieldInstr What the field asks for.
 * @param String $runsShown  The XML of the runs it shows.
 * @return String The XML.
 */
function wFld( String $fieldInstr, String $runsShown ): String {
	return '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve">'.$fieldInstr.'</w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>'.$runsShown.'<w:r><w:fldChar w:fldCharType="end"/></w:r>';
}

/**
 * Writes blocks in a short form, one per line: the kind, the attributes
 * when there are some, then each span as [text|marks|>link|@field]. The
 * blocks a block contains follow it, indented.
 *
 * @param Array  $blocksList The blocks.
 * @param String $indentText Indentation of this level.
 * @return Array The lines.
 */
function blkLines( Array $blocksList, String $indentText='' ): Array {
	$lineLst = Array();
	foreach( $blocksList as $oneBlk ){
		$spanTxt = '';
		foreach( $oneBlk['spans'] as $oneSpn ){
			$spanTxt .= '['.$oneSpn['texts'].(empty($oneSpn['marks']) ? '' : '|'.implode(',', $oneSpn['marks'])).(($oneSpn['links']==='') ? '' : '|>'.$oneSpn['links']).(($oneSpn['field']==='') ? '' : '|@'.$oneSpn['field']).']';
		}
		$lineLst[] = rtrim($indentText.$oneBlk['block'].' '.(empty($oneBlk['attrs']) ? '' : printValueText($oneBlk['attrs']).' ').$spanTxt);
		$lineLst = array_merge($lineLst, blkLines($oneBlk['items'], $indentText.'  '));
	}
	return $lineLst;
}

/**
 * Parses a document and returns its envelope, or the reason it was refused.
 *
 * @param String $sourcePath Path of the document.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The envelope, or a 'THROWN ...' string.
 */
function docRead( String $sourcePath, Array $parseOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($sourcePath, $parseOptns){
		$prsrObj = new Docx();
		return $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($sourcePath), $parseOptns);
	});
}

/**
 * Builds a document from the XML of its body, parses it, and returns its
 * blocks in the short form.
 *
 * @param String $bodyOfDocu The XML of the body.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The lines, or a 'THROWN ...' string.
 */
function docLines( String $bodyOfDocu, Array $parseOptns=Array() ): Mixed {
	$envlope = docRead(makeDocx('body.docx', $bodyOfDocu), $parseOptns);
	return is_array($envlope) ? blkLines($envlope['items']) : $envlope;
}

$offcDir = TST_DIR.DS.'fixtures'.DS.'office'.DS;
$noExtra = Array('frmat'=>Array(), 'phead'=>Array(), 'pfoot'=>Array());

// Documents saved by Word
checkIdentical('a document saved by Word: its paragraphs with their style, its page break, its properties, and what its style gives', docRead($offcDir.'SampleDoc.docx'), Array(
	'shape'=>'block',
	'infos'=>Array(
		'title'=>'Test Document', 'authr'=>'Nick Burch',
		'props'=>Array('Title'=>'Test Document', 'Subject'=>'This is a sample document', 'Author'=>'Nick Burch', 'Keywords'=>'Testing Sample', 'Comments'=>'This document is used for testing text and metadata extraction', 'LastSavedBy'=>'Nick Burch'),
		'frmat'=>Array('Normal'=>Array('class'=>'normal', 'fonts'=>'Calibri', 'sizes'=>11)), 'phead'=>Array(), 'pfoot'=>Array()
	),
	'items'=>array_map(function($textString){
		return ($textString==='') ? Shape::makeBlockArray('rules', Array(), Array(), Array('break'=>true)) : Shape::makeBlockArray('parag', Array(Shape::makeASpanArray($textString)), Array(), Array('style'=>'Normal'));
	}, Array('I am a test document', 'This is page 1', 'I am Calibri (Body) in font size 11', '', 'This is page two', 'It’s Arial Black in 16 point', 'It’s also in blue'))
));
checkIdentical('headings by their style, the runs of a paragraph joined into one text, and the styles used, with the font of the theme', runCatchThrown(function() use ($offcDir){
	$envlope = docRead($offcDir.'heading123.docx');
	$blckLst = $envlope['items'];
	return Array(array_column($blckLst, 'block'), array_column(array_column($blckLst, 'attrs'), 'style'), $blckLst[0]['attrs'], count($blckLst[1]['spans']), substr($blckLst[1]['plain'], 0, 30), Shape::bySectionArray($blckLst)[0]['nodes'][0]['nodes'][0]['title'], $envlope['infos']['frmat']);
}), Array(
	Array('headr', 'parag', 'headr', 'parag', 'headr', 'parag'), Array('heading 1', 'Normal', 'heading 2', 'Normal (Web)', 'heading 3', 'Normal (Web)'), Array('level'=>1, 'style'=>'heading 1'), 1, 'FffLorem ipsum dolor sit amet,', 'Third paragraph',
	Array(
		'heading 1'=>Array('class'=>'heading-1', 'fonts'=>'Calibri', 'sizes'=>16, 'color'=>'#345A8A', 'marks'=>Array('bold')), 'Normal'=>Array('class'=>'normal', 'fonts'=>'Cambria', 'sizes'=>12),
		'heading 2'=>Array('class'=>'heading-2', 'fonts'=>'Calibri', 'sizes'=>13, 'color'=>'#4F81BD', 'marks'=>Array('bold')), 'Normal (Web)'=>Array('class'=>'normal-web', 'fonts'=>'Times', 'sizes'=>10),
		'heading 3'=>Array('class'=>'heading-3', 'fonts'=>'Calibri', 'sizes'=>12, 'color'=>'#4F81BD', 'marks'=>Array('bold'))
	)
));
checkIdentical('lists saved by Word: marked or numbered, each level inside the entry before it, the empty paragraphs between them kept', runCatchThrown(function() use ($offcDir){
	$lineLst = blkLines(docRead($offcDir.'Numbering.docx')['items']);
	return Array(count($lineLst), array_slice($lineLst, 0, 4), array_slice($lineLst, 8, 2), array_slice($lineLst, 14, 4), array_slice($lineLst, -5));
}), Array(36,
	Array('lists {"lines":1,"bytes":28,"order":false}', '  entry {"style":"List Paragraph"} [Level 1]', '    lists {"lines":1,"bytes":21,"order":false}', '      entry {"style":"List Paragraph"} [Level 2]'),
	Array('lists {"lines":1,"bytes":18,"order":true}', '  entry {"style":"List Paragraph"} [Level1]'),
	Array('parag {"style":"Normal"}', 'parag {"style":"Normal"}', 'parag {"style":"Normal"}', 'lists {"lines":1,"bytes":20,"order":true}'),
	Array('lists {"lines":3,"bytes":11,"order":true}', '  entry {"style":"List Paragraph"} [One]', '  entry {"style":"List Paragraph"} [Two]', '  entry {"style":"List Paragraph"} [Three]', 'parag {"style":"List Paragraph"}')
));
checkIdentical('the same lists without the empty paragraphs', count(blkLines(docRead($offcDir.'Numbering.docx', Array('empty'=>false))['items'])), 30);
checkIdentical('a table saved by Word, its empty cell kept, and the empty paragraph after it', blkLines(docRead($offcDir.'TestTableCellAlign.docx')['items']), Array('table {"heads":[],"colms":2,"lines":2,"bytes":15}', '  tbrow', '    tcell {"style":"Normal"} [Top]', '    tcell {"style":"Normal"} [Bottom]', '  tbrow', '    tcell {"style":"Normal"} [Center]', '    tcell {"style":"Normal"}', 'parag {"style":"Normal"}'));
checkIdentical('the same document given as bytes, and an empty content', Array(
	runCatchThrown(function() use ($offcDir){ return (new Docx())->parseDataArray(file_get_contents($offcDir.'SampleDoc.docx'))['items'][0]['plain']; }),
	runCatchThrown(function(){ return (new Docx())->parseDataArray(''); })
), Array('I am a test document', Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array())));

// Headings
checkIdentical('headings: by the name Word gives the style, whatever its id, by the style it is based on, by its outline level', docLines(
	wPar(wRun('One'), '<w:pStyle w:val="Titre1"/>').wPar(wRun('Two'), '<w:pStyle w:val="Titre2"/>').wPar(wRun('Nine'), '<w:pStyle w:val="Titre9"/>').wPar(wRun('Title'), '<w:pStyle w:val="Titre"/>')
	.wPar(wRun('Chapter'), '<w:pStyle w:val="Chapitre"/>').wPar(wRun('Outline'), '<w:pStyle w:val="Plan"/>').wPar(wRun('Direct'), '<w:outlineLvl w:val="3"/>').wPar(wRun('Body'), '<w:outlineLvl w:val="9"/>').wPar(wRun('Unknown'), '<w:pStyle w:val="Nowhere"/>')
), Array('headr {"level":1,"style":"heading 1"} [One]', 'headr {"level":2,"style":"Heading 2"} [Two]', 'headr {"level":6,"style":"heading 9"} [Nine]', 'headr {"level":1,"style":"Title"} [Title]', 'headr {"level":2,"style":"Chapter"} [Chapter]', 'headr {"level":3,"style":"Outline"} [Outline]', 'headr {"level":4} [Direct]', 'parag [Body]', 'parag [Unknown]'));
checkIdentical('a bookmark starting a block names it; those Word adds for itself do not', docLines(
	wPar('<w:bookmarkStart w:id="0" w:name="_Toc123"/><w:bookmarkStart w:id="1" w:name="dates"/><w:bookmarkStart w:id="2" w:name="other"/>'.wRun('Dates').'<w:bookmarkEnd w:id="1"/>', '<w:pStyle w:val="Titre2"/>')
	.wPar('<w:bookmarkStart w:id="3" w:name="intro"/>'.wRun('Text')).wPar('<w:bookmarkStart w:id="4" w:name="_GoBack"/>'.wRun('Plain')).wPar('<w:bookmarkStart w:id="5" w:name="item"/>'.wRun('Entry'), wNum(1))
), Array('headr {"level":2,"style":"Heading 2","tagid":"dates"} [Dates]', 'parag {"tagid":"intro"} [Text]', 'parag [Plain]', 'lists {"lines":1,"bytes":5,"order":false}', '  entry {"tagid":"item"} [Entry]'));

// Formatting set by hand
checkIdentical('bold, italic, underlined, struck, and what switches them off', docLines(wPar(
	wRun('b', '<w:b/>').wRun(' ').wRun('i', '<w:i/>').wRun(' ').wRun('u', '<w:u w:val="single"/>').wRun(' ').wRun('s', '<w:strike/>').wRun('d', '<w:dstrike w:val="true"/>').wRun(' ').wRun('all', '<w:b/><w:i w:val="1"/><w:u w:val="double"/><w:strike w:val="on"/>')
	.wRun(' off', '<w:b w:val="0"/><w:i w:val="false"/><w:u w:val="none"/><w:strike w:val="off"/><w:color w:val="FF0000"/><w:sz w:val="32"/>')
)), Array('parag [b|bold][ ][i|ital][ ][u|undr][ ][sd|strk][ ][all|bold,ital,undr,strk][ off]'));
checkIdentical('a style of characters gives its formatting, with that of the style it is based on', docLines(wPar(wRun('strong', '<w:rStyle w:val="Fort"/>').wRun(' ').wRun('more', '<w:rStyle w:val="TresFort"/>').wRun(' ').wRun('both', '<w:rStyle w:val="Fort"/><w:u w:val="single"/>'))), Array('parag [strong|bold][ ][more|bold,ital][ ][both|bold,undr]'));
checkIdentical('runs of the same formatting are one span; spaces, tabulations, line breaks and accents are kept', runCatchThrown(function(){
	$blckLst = docRead(makeDocx('body.docx', wPar(wRun('Les ').wRun('Enchaînés ').wRun(' deux').'<w:r><w:tab/><w:t>tab</w:t><w:br/><w:t>line</w:t><w:cr/><w:t>cr</w:t><w:noBreakHyphen/><w:t>x</w:t><w:br w:type="column"/><w:softHyphen/><w:lastRenderedPageBreak/><w:t>y</w:t></w:r>')))['items'];
	return Array(count($blckLst), count($blckLst[0]['spans']), $blckLst[0]['plain']);
}), Array(1, 1, "Les Enchaînés  deux\ttab\nline\ncr\u{2011}xy"));

// Empty paragraphs
$voidXml = wPar('').wPar(wRun('   ')).wPar(wRun('kept')).wPar('', '<w:pStyle w:val="Titre1"/>').wPar('', wNum(1)).wPar(wRun('entry'), wNum(1)).'<w:p/>';
checkIdentical('paragraphs holding nothing, or spaces only, are blocks without text: a heading and an entry too', docLines($voidXml), Array('parag', 'parag', 'parag [kept]', 'parag {"style":"heading 1"}', 'lists {"lines":2,"bytes":5,"order":false}', '  entry', '  entry [entry]', 'parag'));
checkIdentical('empty: they are left out when told so', docLines($voidXml, Array('empty'=>false)), Array('parag [kept]', 'lists {"lines":1,"bytes":5,"order":false}', '  entry [entry]'));
checkIdentical('an empty paragraph is a whole block', docRead(makeDocx('void.docx', wPar('')))['items'], Array(Array('block'=>'parag', 'attrs'=>Array(), 'spans'=>Array(), 'items'=>Array(), 'plain'=>'')));

// Page breaks
checkIdentical('page breaks: inside a paragraph, alone in one, asked before a paragraph, and at the end of a section', docLines(
	wPar(wRun('before').'<w:r><w:br w:type="page"/></w:r>'.wRun('after')).wPar('<w:r><w:br w:type="page"/></w:r>').wPar(wRun('new page'), '<w:pageBreakBefore/>').wPar(wRun('not asked'), '<w:pageBreakBefore w:val="0"/>')
	.wPar(wRun('ends a section'), '<w:sectPr><w:type w:val="nextPage"/></w:sectPr>').wPar(wRun('ends another'), '<w:sectPr/>').wPar(wRun('same page'), '<w:sectPr><w:type w:val="continuous"/></w:sectPr>').wPar(wRun('last'))
), Array('parag [before]', 'rules {"break":true}', 'parag [after]', 'rules {"break":true}', 'rules {"break":true}', 'parag [new page]', 'parag [not asked]', 'parag [ends a section]', 'rules {"break":true}', 'parag [ends another]', 'rules {"break":true}', 'parag [same page]', 'parag [last]'));
checkIdentical('a page break in a heading or in an entry', docLines(wPar(wRun('Head').'<w:r><w:br w:type="page"/></w:r>', '<w:pStyle w:val="Titre1"/>').wPar(wRun('Entry').'<w:r><w:br w:type="page"/></w:r>', wNum(1))), Array('headr {"level":1,"style":"heading 1"} [Head]', 'rules {"break":true}', 'lists {"lines":1,"bytes":5,"order":false}', '  entry [Entry]', '    rules {"break":true}'));

// Links
checkIdentical('links: to an address, to a bookmark, to both, and one that would run a script', docLines(wPar(
	wRun('See ').'<w:hyperlink r:id="rIdL">'.wRun('the ').wRun('site', '<w:b/>').'</w:hyperlink>'.wRun(', ').'<w:hyperlink w:anchor="dates">'.wRun('dates').'</w:hyperlink>'.wRun(', ').'<w:hyperlink r:id="rIdL" w:anchor="top">'.wRun('both').'</w:hyperlink>'
	.wRun(', ').'<w:hyperlink r:id="rIdM">'.wRun('mail').'</w:hyperlink>'.wRun(', ').'<w:hyperlink r:id="rIdJ">'.wRun('bad').'</w:hyperlink>'.wRun(', ').'<w:hyperlink r:id="rIdNowhere">'.wRun('lost').'</w:hyperlink>'
)), Array('parag [See ][the |>https://nyuark.test/a?x=1&y=2][site|bold|>https://nyuark.test/a?x=1&y=2][, ][dates|>#dates][, ][both|>https://nyuark.test/a?x=1&y=2#top][, ][mail|>mailto:a@nyuark.test][, bad, lost]'));

// Fields
checkIdentical('a field is the text Word last showed for it, and tells what it stands for: a property, the page, the pages, a date', docLines(
	wPar(wRun('Title: ').wFld(' DOCPROPERTY  Title  \* MERGEFORMAT ', wRun('Alpha Night', '<w:b/>')).wRun(', page ').wFld(' PAGE ', wRun('3')).wRun(' of ').wFld('NUMPAGES \* Arabic', wRun('12')).wRun('.'))
	.wPar(wRun('Ref: ').'<w:fldSimple w:instr=" DOCPROPERTY &quot;Client Reference&quot; ">'.wRun('NYU-2026-014').'</w:fldSimple>'.wRun(' on ').'<w:fldSimple w:instr=" DATE \@ &quot;yyyy-MM-dd&quot; ">'.wRun('2026-12-28').'</w:fldSimple>'.wRun(' by ').wFld(' AUTHOR ', wRun('J. J.')))
), Array('parag [Title: ][Alpha Night|bold|@props:Title][, page ][3|@pgnum][ of ][12|@pgtot][.]', 'parag [Ref: ][NYU-2026-014|@props:Client Reference][ on ][2026-12-28|@dates][ by ][J. J.|@props:Author]'));
checkIdentical('a field stays apart from the text beside it, and two fields from one another', docRead(makeDocx('field.docx', wPar(wRun('p').wFld(' PAGE ', wRun('3')).wFld(' PAGE ', wRun('3')).wRun('.'))))['items'][0]['spans'], Array(
	Array('texts'=>'p', 'marks'=>Array(), 'links'=>'', 'field'=>''), Array('texts'=>'3', 'marks'=>Array(), 'links'=>'', 'field'=>'pgnum'), Array('texts'=>'3', 'marks'=>Array(), 'links'=>'', 'field'=>'pgnum'), Array('texts'=>'.', 'marks'=>Array(), 'links'=>'', 'field'=>'')
));
checkIdentical('a field that is a link gives its address to what it shows; one over two paragraphs, one showing nothing, one of another kind', docLines(
	wPar(wFld(' HYPERLINK "https://nyuark.test/f" ', wRun('site')).wRun(' ').wFld(' HYPERLINK \l "dates" ', wRun('dates')).wRun(' ').wFld(' HYPERLINK "javascript:alert(1)" ', wRun('bad')))
	.wPar(wRun('Contents: ').'<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> TOC \o "1-3" </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>'.wRun('One ..... 1')).wPar(wRun('Two ..... 2').'<w:r><w:fldChar w:fldCharType="end"/></w:r>'.wRun(' after'))
	.wPar(wRun('Empty').'<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> XE "index entry" </w:instrText></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>'.wRun(' field').wFld(' REF dates \h ', wRun(' ref')))
), Array('parag [site|>https://nyuark.test/f][ ][dates|>#dates][ bad]', 'parag [Contents: One ..... 1]', 'parag [Two ..... 2 after]', 'parag [Empty field ref]'));

// Tracked changes and wrappers
checkIdentical('what a tracked change added is read, what it deleted is not; content controls and smart tags are read through', docLines(
	wPar(wRun('Kept ').'<w:ins w:id="1" w:author="a">'.wRun('added ').'</w:ins><w:del w:id="2" w:author="a"><w:r><w:delText>deleted </w:delText></w:r></w:del><w:moveFrom w:id="3">'.wRun('moved away ').'</w:moveFrom><w:moveTo w:id="4">'.wRun('moved here ').'</w:moveTo><w:smartTag w:element="date">'.wRun('tagged ').'</w:smartTag><w:sdt><w:sdtPr/><w:sdtContent>'.wRun('control').'</w:sdtContent></w:sdt>')
	.'<w:sdt><w:sdtContent>'.wPar(wRun('Block control')).'</w:sdtContent></w:sdt>'
), Array('parag [Kept added moved here tagged control]', 'parag [Block control]'));

// Lists
checkIdentical('lists: by the paragraph or by its style, numbered or marked by level, a change of list starts a new one', docLines(
	wPar(wRun('one'), wNum(2)).wPar(wRun('one-a'), wNum(2, 1)).wPar(wRun('one-a-i', '<w:b/>'), wNum(2, 2)).wPar(wRun('one-b'), wNum(2, 1)).wPar(wRun('two'), wNum(2))
	.wPar(wRun('mark'), wNum(1)).wPar(wRun('by style'), '<w:pStyle w:val="Puce"/>').wPar(wRun('not a list'), '<w:pStyle w:val="Puce"/><w:numPr><w:numId w:val="0"/></w:numPr>').wPar(wRun('deep first'), wNum(2, 1))
), Array(
	'lists {"lines":2,"bytes":23,"order":true}',
	'  entry [one]',
	'    lists {"lines":2,"bytes":17,"order":false}',
	'      entry [one-a]',
	'        lists {"lines":1,"bytes":7,"order":true}',
	'          entry [one-a-i|bold]',
	'      entry [one-b]',
	'  entry [two]',
	'lists {"lines":2,"bytes":12,"order":false}',
	'  entry [mark]',
	'  entry {"style":"List Bullet"} [by style]',
	'parag {"style":"List Bullet"} [not a list]',
	'lists {"lines":1,"bytes":10,"order":false}',
	'  entry',
	'    lists {"lines":1,"bytes":10,"order":false}',
	'      entry [deep first]'
));
checkIdentical('a heading that is numbered stays a heading', docLines(wPar(wRun('Chapter'), '<w:pStyle w:val="Titre1"/>'.wNum(2)).wPar(wRun('item'), wNum(2))), Array('headr {"level":1,"style":"heading 1"} [Chapter]', 'lists {"lines":1,"bytes":4,"order":true}', '  entry [item]'));

// Tables
$wCell = function( String $cellXml, String $propsXml='' ){ return '<w:tc>'.(($propsXml!=='') ? '<w:tcPr>'.$propsXml.'</w:tcPr>' : '').$cellXml.'</w:tc>'; };
checkIdentical('a table: its header row, cells over several columns and rows, a cell holding blocks, a table in a cell', docLines('<w:tbl><w:tblPr/>'
	.'<w:tr><w:trPr><w:tblHeader/></w:trPr>'.$wCell(wPar(wRun('Title'))).$wCell(wPar(wRun('Seats'))).$wCell(wPar(wRun('Notes'))).'</w:tr>'
	.'<w:tr>'.$wCell(wPar(wRun('Season', '<w:b/>')), '<w:gridSpan w:val="2"/>').$wCell(wPar(wRun('long')), '<w:vMerge w:val="restart"/>').'</w:tr>'
	.'<w:tr>'.$wCell(wPar(wRun('Alpha')).wPar(wRun('second paragraph')).wPar(wRun('entry'), wNum(1))).$wCell(wPar('')).$wCell(wPar(''), '<w:vMerge/>').'</w:tr>'
	.'<w:tr>'.$wCell('<w:tbl><w:tr>'.$wCell(wPar(wRun('inner'))).'</w:tr></w:tbl>'.wPar('')).$wCell(wPar(wRun('80')).wPar('').wPar('')).$wCell(wPar(''), '<w:vMerge w:val="continue"/>').'</w:tr>'
	.'<w:tr>'.$wCell(wPar(wRun('last'), '<w:pStyle w:val="Chapitre"/><w:jc w:val="center"/>')).$wCell(wPar(wRun('row'), '<w:jc w:val="right"/>')).$wCell(wPar(wRun('free'))).'</w:tr>'
	.'</w:tbl>'.wPar(wRun('after'))
), Array(
	'table {"heads":["Title","Seats","Notes"],"colms":3,"lines":4,"bytes":69}',
	'  thead', '    tcell [Title]', '    tcell [Seats]', '    tcell [Notes]',
	'  tbrow', '    tcell {"colsp":2} [Season|bold]', '    tcell {"rowsp":3} [long]',
	'  tbrow', '    tcell [Alpha]', '      parag [second paragraph]', '      lists {"lines":1,"bytes":5,"order":false}', '        entry [entry]', '    tcell',
	'  tbrow', '    tcell', '      table {"heads":[],"colms":1,"lines":1,"bytes":5}', '        tbrow', '          tcell [inner]', '    tcell [80]', '      parag',
	'  tbrow', '    tcell', '      headr {"level":2,"style":"Chapter","align":"centr"} [last]', '    tcell {"align":"right"} [row]', '    tcell [free]',
	'parag [after]'
));
checkIdentical('a table without any cell gives nothing', docLines('<w:tbl><w:tr/></w:tbl>'.wPar(wRun('x'))), Array('parag [x]'));

// Images
$wDraw = function( String $relIdent, String $descText ){ return '<w:r><w:drawing><wp:inline><wp:docPr id="1" name="Picture 1" descr="'.$descText.'"/><a:graphic><a:graphicData><a:blip r:embed="'.$relIdent.'"/></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>'; };
checkIdentical('an image stands between the two halves of its paragraph: kept in the archive, kept elsewhere, drawn the old way, and one that is lost', docLines(
	wPar(wRun('Before ').$wDraw('rIdP', 'The hall').wRun(' after'), '<w:pStyle w:val="Puce"/><w:numPr><w:numId w:val="0"/></w:numPr>').wPar($wDraw('rIdO', ''), '<w:pStyle w:val="Titre"/>').wPar('<w:r><w:pict><v:shape><v:imagedata r:id="rIdP" o:title="Old way"/></v:shape></w:pict></w:r>').wPar($wDraw('rIdNowhere', 'lost').wRun('text'))
), Array('parag {"style":"List Bullet"} [Before ]', 'image {"sourc":"word/media/image1.png"} [The hall]', 'parag [ after]', 'image {"sourc":"https://nyuark.test/far.png"}', 'image {"sourc":"word/media/image1.png"} [Old way]', 'parag [text]'));
checkIdentical('images in a list entry go inside it; in a heading only the text is kept; a drawing that is not an image is nothing', docLines(wPar(wRun('Entry ').$wDraw('rIdP', 'pic'), wNum(1)).wPar(wRun('Head ').$wDraw('rIdP', 'pic'), '<w:pStyle w:val="Titre1"/>').wPar('<w:r><w:drawing><wp:inline><wp:docPr id="2" name="Chart"/></wp:inline></w:drawing></w:r>'), Array('empty'=>false)), Array('lists {"lines":1,"bytes":9,"order":false}', '  entry [Entry ]', '    image {"sourc":"word/media/image1.png"} [pic]', 'headr {"level":1,"style":"heading 1"} [Head ]'));

// Styles
$stylXml = '<w:styles '.WORDMAIN.'><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:asciiTheme="minorHAnsi" w:hAnsiTheme="minorHAnsi"/><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
	.'<w:style w:type="paragraph" w:default="1" w:styleId="Standard"><w:name w:val="Normal"/></w:style>'
	.'<w:style w:type="paragraph" w:styleId="Titre1"><w:name w:val="heading 1"/><w:basedOn w:val="Standard"/><w:pPr><w:jc w:val="center"/></w:pPr><w:rPr><w:rFonts w:asciiTheme="majorHAnsi"/><w:b/><w:color w:val="2f5496"/><w:sz w:val="32"/></w:rPr></w:style>'
	.'<w:style w:type="paragraph" w:styleId="Citation"><w:name w:val="Quote"/><w:basedOn w:val="Standard"/><w:pPr><w:jc w:val="center"/></w:pPr><w:rPr><w:i/><w:u w:val="single"/><w:color w:val="auto"/></w:rPr></w:style>'
	.'<w:style w:type="paragraph" w:styleId="CitationIntense"><w:name w:val="Intense Quote"/><w:basedOn w:val="Citation"/><w:rPr><w:b/><w:sz w:val="23"/></w:rPr></w:style>'
	.'<w:style w:type="paragraph" w:styleId="Code"><w:name w:val="HTML Preformatted"/><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="20"/></w:rPr></w:style>'
	.'<w:style w:type="paragraph" w:styleId="Client"><w:name w:val="3 - Citation client"/><w:pPr><w:jc w:val="both"/></w:pPr></w:style>'
	.'<w:style w:type="paragraph" w:styleId="Unused"><w:name w:val="Never used"/></w:style><w:style w:type="paragraph" w:styleId="Loop"><w:name w:val="Loop"/><w:basedOn w:val="Loop"/></w:style>'
	.'</w:styles>';
$stylPth = makeDocx('styles.docx',
	wPar(wRun('Alpha Night'), '<w:pStyle w:val="Titre1"/>').wPar(wRun('Plain text.')).wPar(wRun('Centred by hand.'), '<w:jc w:val="center"/>').wPar(wRun('Left, as its style.'), '<w:jc w:val="left"/>').wPar(wRun('Left, against its style.'), '<w:pStyle w:val="Titre1"/><w:jc w:val="start"/>')
	.wPar(wRun('A night to ').wRun('remember', '<w:b/>').wRun('.'), '<w:pStyle w:val="Citation"/>').wPar(wRun('Louder.'), '<w:pStyle w:val="CitationIntense"/><w:jc w:val="center"/>').wPar(wRun('Client says.'), '<w:pStyle w:val="Client"/>').wPar(wRun('loop'), '<w:pStyle w:val="Loop"/>')
	.wPar(wRun('if( $a ){'), '<w:pStyle w:val="Code"/>').wPar(wRun('    echo ').wRun('1', '<w:b/>').wRun(';'), '<w:pStyle w:val="Code"/>').wPar('', '<w:pStyle w:val="Code"/>').wPar(wRun('}'), '<w:pStyle w:val="Code"/>').wPar('', '<w:pStyle w:val="Code"/>').wPar(wRun('end')),
	Array('word/styles.xml'=>$stylXml, 'word/theme/theme1.xml'=>'<a:theme '.WORDMAIN.'><a:themeElements><a:fontScheme name="Office"><a:majorFont><a:latin typeface="Calibri Light"/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/></a:minorFont></a:fontScheme></a:themeElements></a:theme>'),
	'<Relationship Id="rIdT" Type="'.WORDRELS.'/theme" Target="theme/theme1.xml"/>'
);
checkIdentical('every block tells its style; the alignment is told when it is not that of the style; the formatting of a style is not copied to the text', blkLines(docRead($stylPth)['items']), Array(
	'headr {"level":1,"style":"heading 1"} [Alpha Night]',
	'parag {"style":"Normal"} [Plain text.]',
	'parag {"style":"Normal","align":"centr"} [Centred by hand.]',
	'parag {"style":"Normal"} [Left, as its style.]',
	'headr {"level":1,"style":"heading 1","align":"lefts"} [Left, against its style.]',
	'quote {"style":"Quote"} [A night to ][remember|bold][.]',
	'quote {"style":"Intense Quote"} [Louder.]',
	'parag {"style":"3 - Citation client"} [Client says.]',
	'parag {"style":"Loop"} [loop]',
	"codes {\"style\":\"HTML Preformatted\"} [if( \$a ){\n    echo 1;\n\n}]",
	'parag {"style":"Normal"} [end]'
));
checkIdentical('frmat: what each style used gives, once, with what it takes from the style it is based on and from the document', docRead($stylPth)['infos']['frmat'], Array(
	'heading 1'=>Array('class'=>'heading-1', 'fonts'=>'Calibri Light', 'sizes'=>16, 'color'=>'#2F5496', 'marks'=>Array('bold'), 'align'=>'centr'),
	'Normal'=>Array('class'=>'normal', 'fonts'=>'Calibri', 'sizes'=>11),
	'Quote'=>Array('class'=>'quote', 'fonts'=>'Calibri', 'sizes'=>11, 'marks'=>Array('ital', 'undr'), 'align'=>'centr'),
	'Intense Quote'=>Array('class'=>'intense-quote', 'fonts'=>'Calibri', 'sizes'=>11.5, 'marks'=>Array('bold', 'ital', 'undr'), 'align'=>'centr'),
	'3 - Citation client'=>Array('class'=>'s-3-citation-client', 'fonts'=>'Calibri', 'sizes'=>11, 'align'=>'justf'),
	'Loop'=>Array('class'=>'loop', 'fonts'=>'Calibri', 'sizes'=>11),
	'HTML Preformatted'=>Array('class'=>'html-preformatted', 'fonts'=>'Consolas', 'sizes'=>10)
));

// Headers and footers
$pagePth = makeDocx('pages.docx', wPar(wRun('Body')), Array(
	'word/header1.xml'=>'<w:hdr '.WORDMAIN.'>'.wPar(wRun('nyuArk, season 2026'), '<w:pStyle w:val="Titre"/>').wPar('').'</w:hdr>',
	'word/header2.xml'=>'<w:hdr '.WORDMAIN.'>'.wPar(wRun('nyuArk, season 2026'), '<w:pStyle w:val="Titre"/>').'</w:hdr>',
	'word/header3.xml'=>'<w:hdr '.WORDMAIN.'>'.wPar(wRun('First page only')).'</w:hdr>',
	'word/header4.xml'=>'<w:hdr '.WORDMAIN.'>'.wPar('').'</w:hdr>',
	'word/footer1.xml'=>'<w:ftr '.WORDMAIN.'>'.wPar(wRun('Ref. ').wFld(' DOCPROPERTY Reference ', wRun('NYU-2026-014')).wRun(', page ').wFld(' PAGE ', wRun('1')).wRun(' of ').wFld(' NUMPAGES ', wRun('2')).wRun(', ').'<w:hyperlink r:id="rIdF">'.wRun('site').'</w:hyperlink>', '<w:jc w:val="right"/>').'<w:tbl><w:tr><w:tc>'.wPar(wRun('cell')).'</w:tc></w:tr></w:tbl></w:ftr>',
	'word/_rels/footer1.xml.rels'=>'<Relationships '.PACKRELS.'><Relationship Id="rIdF" Type="'.WORDRELS.'/hyperlink" Target="https://nyuark.test/footer" TargetMode="External"/></Relationships>'
), '<Relationship Id="rIdH1" Type="'.WORDRELS.'/header" Target="header1.xml"/><Relationship Id="rIdH2" Type="'.WORDRELS.'/header" Target="header2.xml"/><Relationship Id="rIdH3" Type="'.WORDRELS.'/header" Target="header3.xml"/><Relationship Id="rIdH4" Type="'.WORDRELS.'/header" Target="header4.xml"/><Relationship Id="rIdF1" Type="'.WORDRELS.'/footer" Target="footer1.xml"/>');
checkIdentical('the headers and the footers of the pages stand in the details: each that differs once, their fields telling what they stand for, their links their own', runCatchThrown(function() use ($pagePth){
	$envlope = docRead($pagePth);
	return Array(blkLines($envlope['items']), blkLines($envlope['infos']['phead']), blkLines($envlope['infos']['pfoot']), array_keys($envlope['infos']['frmat']));
}), Array(
	Array('parag [Body]'),
	Array('headr {"level":1,"style":"Title"} [nyuArk, season 2026]', 'parag [First page only]'),
	Array('parag {"align":"right"} [Ref. ][NYU-2026-014|@props:Reference][, page ][1|@pgnum][ of ][2|@pgtot][, ][site|>https://nyuark.test/footer]', 'table {"heads":[],"colms":1,"lines":1,"bytes":4}', '  tbrow', '    tcell [cell]'),
	Array('Title')
));

// Notes
$noteXml = '<w:footnote w:type="separator" w:id="-1">'.wPar(wRun('sep')).'</w:footnote><w:footnote w:type="continuationSeparator" w:id="0">'.wPar(wRun('cont')).'</w:footnote>'
	.'<w:footnote w:id="2">'.wPar('<w:r><w:footnoteRef/></w:r>'.wRun(' Doors open at ').wRun('8 pm', '<w:b/>').wRun('.')).wPar('').wPar(wRun('See ').'<w:hyperlink r:id="rIdX">'.wRun('the site').'</w:hyperlink>').'</w:footnote>'
	.'<w:footnote w:id="3">'.wPar(wRun('Never referred to.')).'</w:footnote><w:footnote w:id="5">'.wPar(wRun('Second note.')).'</w:footnote>';
$notePth = makeDocx('notes.docx',
	wPar(wRun('Playing on 28 December').'<w:r><w:rPr><w:vertAlign w:val="superscript"/></w:rPr><w:footnoteReference w:id="5"/></w:r>'.wRun(' at Hall A').'<w:r><w:footnoteReference w:id="2"/></w:r>'.wRun('.').'<w:r><w:endnoteReference w:id="1"/></w:r>')
	.wPar(wRun('Again').'<w:r><w:footnoteReference w:id="5"/></w:r>'.wRun(' and lost').'<w:r><w:footnoteReference w:id="9"/></w:r>'),
	Array('word/footnotes.xml'=>'<w:footnotes '.WORDMAIN.'>'.$noteXml.'</w:footnotes>', 'word/endnotes.xml'=>'<w:endnotes '.WORDMAIN.'><w:endnote w:id="1">'.wPar(wRun('At the very end.')).'</w:endnote></w:endnotes>', 'word/_rels/footnotes.xml.rels'=>'<Relationships '.PACKRELS.'><Relationship Id="rIdX" Type="'.WORDRELS.'/hyperlink" Target="https://nyuark.test/note" TargetMode="External"/></Relationships>'),
	'<Relationship Id="rIdFN" Type="'.WORDRELS.'/footnotes" Target="footnotes.xml"/><Relationship Id="rIdEN" Type="'.WORDRELS.'/endnotes" Target="endnotes.xml"/>'
);
checkIdentical('notes: numbered by their first reference, the reference a link to them, the notes after the text, those of the end last', blkLines(docRead($notePth)['items']), Array(
	'parag [Playing on 28 December][1|>#fnote1][ at Hall A][2|>#fnote2][.][1|>#enote1]',
	'parag [Again][1|>#fnote1][ and lost][3|>#fnote3]',
	'fnote {"tagid":"fnote1","numbr":1} [Second note.]',
	'fnote {"tagid":"fnote2","numbr":2} [ Doors open at ][8 pm|bold][.]',
	'  parag [See ][the site|>https://nyuark.test/note]',
	'fnote {"tagid":"enote1","numbr":1} [At the very end.]'
));
checkIdentical('a note is a whole block, written as an aside', runCatchThrown(function() use ($notePth){
	$blckLst = docRead($notePth)['items'];
	return Array($blckLst[2], (new Html())->renderDocument(Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array($blckLst[2]))));
}), Array(Array('block'=>'fnote', 'attrs'=>Array('tagid'=>'fnote1', 'numbr'=>1), 'spans'=>Array(Array('texts'=>'Second note.', 'marks'=>Array(), 'links'=>'', 'field'=>'')), 'items'=>Array(), 'plain'=>'Second note.'), "<aside id=\"fnote1\"><sup>1</sup> Second note.</aside>\n"));

// Comments
$cmntPth = makeDocx('comments.docx',
	wPar(wRun('Playing on ').'<w:commentRangeStart w:id="0"/>'.wRun('28 ').wRun('December', '<w:b/>').'<w:commentRangeEnd w:id="0"/><w:r><w:commentReference w:id="0"/></w:r>'.wRun(' at ').'<w:commentRangeStart w:id="1"/>'.wRun('Hall A'))
	.wPar(wRun('and Hall B').'<w:commentRangeEnd w:id="1"/>'.wRun('.')).'<w:commentRangeStart w:id="2"/>'.wPar(wRun('Whole paragraph')).'<w:commentRangeEnd w:id="2"/>',
	Array('word/comments.xml'=>'<w:comments '.WORDMAIN.'><w:comment w:id="0" w:author="A. Martin" w:date="2026-10-02T14:05:00Z" w:initials="AM">'.wPar(wRun('Check this ').wRun('date', '<w:i/>')).wPar(wRun('Second line')).'</w:comment><w:comment w:id="1" w:author="Les Enchaînés">'.wPar(wRun('Both?')).'</w:comment><w:comment w:id="2" w:author="X">'.wPar(wRun('On a block')).'</w:comment><w:comment w:id="7">'.wPar(wRun('About nothing')).'</w:comment></w:comments>'),
	'<Relationship Id="rIdC" Type="'.WORDRELS.'/comments" Target="comments.xml"/>'
);
checkIdentical('cmnts: the comments of the reviewers, with the passage each is about, in the details and never in the text', runCatchThrown(function() use ($cmntPth){
	$envlope = docRead($cmntPth, Array('cmnts'=>true));
	return Array(blkLines($envlope['items']), $envlope['infos']['cmnts']);
}), Array(
	Array('parag [Playing on 28 ][December|bold][ at Hall A]', 'parag [and Hall B.]', 'parag [Whole paragraph]'),
	Array(
		Array('authr'=>'A. Martin', 'dated'=>'2026-10-02T14:05:00Z', 'plain'=>"Check this date\n\nSecond line", 'about'=>'28 December'),
		Array('authr'=>'Les Enchaînés', 'dated'=>'', 'plain'=>'Both?', 'about'=>'Hall A and Hall B'),
		Array('authr'=>'X', 'dated'=>'', 'plain'=>'On a block', 'about'=>'Whole paragraph'),
		Array('authr'=>'', 'dated'=>'', 'plain'=>'About nothing', 'about'=>'')
	)
));
checkIdentical('without the option, the comments are not read at all', runCatchThrown(function() use ($cmntPth){
	$envlope = docRead($cmntPth);
	return Array(array_keys($envlope['infos']), count($envlope['items']), strpos(printValueText($envlope), 'Check this'));
}), Array(Array('title', 'authr', 'props', 'frmat', 'phead', 'pfoot'), 3, false));

// Properties
$propPth = makeDocx('props.docx', wPar(wRun('x')), Array(
	'docProps/core.xml'=>'<cp:coreProperties xmlns:cp="urn:cp" xmlns:dc="urn:dc" xmlns:dcterms="urn:dcterms"><dc:title> Alpha Night </dc:title><dc:creator>Les Enchaînés</dc:creator><dc:subject></dc:subject><cp:category>Shows</cp:category><dcterms:created>2026-01-04T09:10:00Z</dcterms:created></cp:coreProperties>',
	'docProps/app.xml'=>'<Properties xmlns="urn:app"><Template>Normal</Template><Pages>2</Pages><Company>nyuArk</Company><Manager>J. J.</Manager></Properties>',
	'docProps/custom.xml'=>'<Properties xmlns="urn:custom" xmlns:vt="urn:vt"><property fmtid="{x}" pid="2" name="Reference"><vt:lpwstr>NYU-2026-014</vt:lpwstr></property><property pid="3" name="Seats"><vt:i4>120</vt:i4></property><property pid="4" name="Price"><vt:r8>12.5</vt:r8></property><property pid="5" name="Open"><vt:bool>true</vt:bool></property><property pid="6" name="Closed"><vt:bool>false</vt:bool></property><property pid="7" name="Since"><vt:filetime>2026-12-28T20:30:00Z</vt:filetime></property><property pid="8" name="Company"><vt:lpwstr>Overridden</vt:lpwstr></property><property pid="9" name=""><vt:lpwstr>nameless</vt:lpwstr></property><property pid="10" name="Void"/></Properties>'
));
checkIdentical('properties: the ones Word defines, by the name it gives them in its fields, then the ones added to the document, with their type', docRead($propPth)['infos'], Array('title'=>'Alpha Night', 'authr'=>'Les Enchaînés', 'props'=>Array('Title'=>'Alpha Night', 'Author'=>'Les Enchaînés', 'Category'=>'Shows', 'Company'=>'Overridden', 'Manager'=>'J. J.', 'Reference'=>'NYU-2026-014', 'Seats'=>120, 'Price'=>12.5, 'Open'=>true, 'Closed'=>false, 'Since'=>'2026-12-28T20:30:00Z')) + $noExtra);
checkIdentical('a document without properties, styles used, headers nor footers', docRead(makeDocx('bare.docx', wPar(wRun('x'))))['infos'], Array('title'=>'', 'authr'=>'', 'props'=>Array()) + $noExtra);

// Documents written another way
checkIdentical('a document kept elsewhere in its archive, without styles nor lists', blkLines(docRead(makeDocx('moved.docx', '', Array(
	'_rels/.rels'=>'<Relationships '.PACKRELS.'><Relationship Id="rId1" Type="'.WORDRELS.'/officeDocument" Target="/doc/main.xml"/></Relationships>',
	'word/document.xml'=>null, 'word/_rels/document.xml.rels'=>null, 'word/styles.xml'=>null, 'word/numbering.xml'=>null,
	'doc/main.xml'=>'<w:document '.WORDMAIN.'><w:body>'.wPar(wRun('Heading?'), '<w:pStyle w:val="Titre1"/>').wPar(wRun('item'), wNum(5)).'</w:body></w:document>'
)))['items']), Array('parag [Heading?]', 'lists {"lines":1,"bytes":4,"order":false}', '  entry [item]'));

// What is refused
checkPregMatch('a workbook under the name of a document', docRead($offcDir.'SampleSS.xlsx'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: the document is not a text document/');
checkPregMatch('an archive that is not an Office document', docRead($offcDir.'sample.odt'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part word\/document\.xml is missing/');
checkPregMatch('a document without body', docRead(makeDocx('nobody.docx', '', Array('word/document.xml'=>'<w:document '.WORDMAIN.'/>'))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: the document is not a text document/');
checkPregMatch('a document that is not valid XML', docRead(makeDocx('broken.docx', '<w:p><w:r></w:p>')), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part word\/document\.xml: Invalid XML content/');
checkPregMatch('a document protected by a password is not an archive any more, and is told so', runCatchThrown(function(){ return (new Docx())->parseDataArray("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 600)); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: the document is protected by a password, or saved in an older Office format/');
checkIdentical('one parser, several documents: a field, a note, a comment or an option of one does not reach the next', runCatchThrown(function() use ($notePth, $cmntPth){
	$prsrObj = new Docx();
	$prsrObj->parseFluxArray(Fluxd::openLocalFluxd(makeDocx('open.docx', wPar(wRun('a').'<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> HYPERLINK "https://nyuark.test/open" </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>'.wRun('b').'<w:commentRangeStart w:id="4"/>'))), Array('empty'=>false, 'cmnts'=>true));
	$prsrObj->parseFluxArray(Fluxd::openLocalFluxd($notePth));
	$prsrObj->parseFluxArray(Fluxd::openLocalFluxd($cmntPth), Array('cmnts'=>true));
	$envlope = $prsrObj->parseFluxArray(Fluxd::openLocalFluxd(makeDocx('next.docx', wPar(wRun('c').'<w:r><w:footnoteReference w:id="1"/></w:r>').wPar(''))));
	return Array(blkLines($envlope['items']), array_keys($envlope['infos']));
}), Array(Array('parag [c][1|>#fnote1]', 'parag'), Array('title', 'authr', 'props', 'frmat', 'phead', 'pfoot')));
checkIdentical('no PHP warning along the way', takeNotesArray(), Array());

// Through the index, Dcmnt and the HTML writer
checkIdentical('index: documents by extension and by content type', Array(get_class(Index::forExtnsParser('DOCX')), get_class(Index::findTypeParser('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))), Array('nyu\Data\Parsers\Docx', 'nyu\Data\Parsers\Docx'));
checkIdentical('a document through Dcmnt', runCatchThrown(function() use ($offcDir){
	$docFile = new Dcmnt($offcDir.'heading123.docx');
	return Array($docFile->readFileDetail('sig'), $docFile->readFileDetail('shp'), $docFile->readFileDetail('inf')['authr'], $docFile->readFileDetail()[2]['plain']);
}), Array('zip', 'block', 'Paolo Mottadelli', 'Second paragraph'));
checkIdentical('a document read then written as HTML', runCatchThrown(function(){
	return (new Html())->renderDocument((new Docx())->parseFluxArray(Fluxd::openLocalFluxd(makeDocx('html.docx',
		wPar('<w:bookmarkStart w:id="1" w:name="top"/>'.wRun('Alpha &lt;Night&gt;'), '<w:pStyle w:val="Titre1"/>').wPar(wRun('At ').'<w:hyperlink r:id="rIdL">'.wRun('Hall A').'</w:hyperlink>'.wRun(', ').wRun('120', '<w:b/>').wRun(' seats.').'<w:r><w:br/></w:r>'.wRun('Doors at 8.')).wPar('')
		.wPar(wRun('one'), wNum(2)).wPar(wRun('two'), wNum(2)).'<w:tbl><w:tr><w:trPr><w:tblHeader/></w:trPr><w:tc>'.wPar(wRun('T')).'</w:tc></w:tr><w:tr><w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr>'.wPar(wRun('a')).'</w:tc></w:tr></w:tbl>'.wPar('<w:r><w:br w:type="page"/></w:r>')
	))));
}), "<h1 id=\"top\" class=\"heading-1\">Alpha &lt;Night&gt;</h1>\n<p>At <a href=\"https://nyuark.test/a?x=1&amp;y=2\">Hall A</a>, <strong>120</strong> seats.<br>\nDoors at 8.</p>\n<p></p>\n<ol>\n<li>one</li>\n<li>two</li>\n</ol>\n<table>\n<thead>\n<tr><th>T</th></tr>\n</thead>\n<tbody>\n<tr><td colspan=\"2\">a</td></tr>\n</tbody>\n</table>\n<hr class=\"break\">\n");

finishChecking();
