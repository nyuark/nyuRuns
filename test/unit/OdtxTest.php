<?php

// Data\Parsers\Odtx : OpenDocument texts read into text blocks.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Fluxd;
use nyu\Data\Shape;
use nyu\Data\Parsers\Index;
use nyu\Data\Parsers\Odtx;
use nyu\Data\Writers\Html;

if( !class_exists('ZipArchive') ){
	skipCheck('documents', 'the zip extension is not loaded');
	endChecks();
}

const ODFSPACE = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"';

// The styles the test documents name: a body style, headings, a title, a quote, code, a style of characters
const ODFNAMED = '<style:default-style style:family="paragraph"><style:text-properties style:font-name="Times New Roman" fo:font-size="12pt"/></style:default-style>'
	.'<style:style style:name="Standard" style:family="paragraph"/>'
	.'<style:style style:name="Text_20_body" style:display-name="Text body" style:family="paragraph" style:parent-style-name="Standard"/>'
	.'<style:style style:name="Heading" style:family="paragraph" style:parent-style-name="Standard"><style:text-properties style:font-name="Albany" fo:font-size="14pt"/></style:style>'
	.'<style:style style:name="Heading_20_1" style:display-name="Heading 1" style:family="paragraph" style:parent-style-name="Heading" style:default-outline-level="1"><style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-size="18pt" fo:font-weight="bold" fo:color="#2f5496"/></style:style>'
	.'<style:style style:name="Heading_20_2" style:display-name="Heading 2" style:family="paragraph" style:parent-style-name="Heading"><style:text-properties fo:font-size="16.5pt" fo:font-weight="bold" fo:font-style="italic"/></style:style>'
	.'<style:style style:name="Title" style:family="paragraph" style:parent-style-name="Heading"/>'
	.'<style:style style:name="Quotations" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:text-align="justify"/><style:text-properties fo:font-style="italic" style:text-underline-style="solid"/></style:style>'
	.'<style:style style:name="Preformatted_20_Text" style:display-name="Preformatted Text" style:family="paragraph" style:parent-style-name="Standard"><style:text-properties style:font-name="Courier New" fo:font-size="10pt"/></style:style>'
	.'<style:style style:name="Chapter_20_break" style:display-name="Chapter break" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:break-before="page"/></style:style>'
	.'<style:style style:name="_33__20_-_20_Client" style:family="paragraph" style:parent-style-name="Nowhere"/>'
	.'<style:style style:name="Never_20_used" style:family="paragraph"/>'
	.'<style:style style:name="Strong_20_Emphasis" style:display-name="Strong Emphasis" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
	.'<text:list-style style:name="Numbered"><text:list-level-style-number text:level="1" style:num-format="1"/><text:list-level-style-bullet text:level="2" text:bullet-char="-"/><text:list-level-style-number text:level="3" style:num-format="a"/></text:list-style>';

// The styles the test documents make for what is formatted by hand
const ODFAUTOS = '<style:style style:name="P1" style:family="paragraph" style:parent-style-name="Text_20_body"><style:paragraph-properties fo:text-align="center"/></style:style>'
	.'<style:style style:name="P2" style:family="paragraph" style:parent-style-name="Text_20_body"><style:text-properties fo:font-weight="bold"/></style:style>'
	.'<style:style style:name="P3" style:family="paragraph" style:parent-style-name="Text_20_body"><style:paragraph-properties fo:break-before="page"/></style:style>'
	.'<style:style style:name="P4" style:family="paragraph" style:parent-style-name="Text_20_body"><style:paragraph-properties fo:break-after="page"/></style:style>'
	.'<style:style style:name="P5" style:family="paragraph" style:parent-style-name="Text_20_body" style:master-page-name="Landscape"/>'
	.'<style:style style:name="P6" style:family="paragraph" style:parent-style-name="Heading_20_1"><style:paragraph-properties fo:text-align="start"/></style:style>'
	.'<style:style style:name="P7" style:family="paragraph" style:parent-style-name="Heading_20_1"><style:paragraph-properties fo:text-align="center"/></style:style>'
	.'<style:style style:name="P8" style:family="paragraph" style:parent-style-name="Text_20_body"><style:paragraph-properties fo:text-align="left"/></style:style>'
	.'<style:style style:name="T1" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style><style:style style:name="T2" style:family="text"><style:text-properties fo:font-style="italic"/></style:style>'
	.'<style:style style:name="T3" style:family="text"><style:text-properties style:text-underline-style="solid" style:text-line-through-style="solid" fo:font-weight="700"/></style:style>'
	.'<style:style style:name="T4" style:family="text"><style:text-properties fo:font-weight="normal" fo:font-style="normal" style:text-underline-style="none" style:text-line-through-style="none" fo:color="#ff0000"/></style:style>'
	.'<text:list-style style:name="L1"><text:list-level-style-bullet text:level="1" text:bullet-char="•"/><text:list-level-style-bullet text:level="2" text:bullet-char="o"/></text:list-style>'
	.'<text:list-style style:name="L2"><text:list-level-style-number text:level="1" style:num-format="1"/><text:list-level-style-number text:level="2" style:num-format=""/></text:list-style>';

/**
 * Builds an OpenDocument text in the temporary folder, with the styles
 * of ODFNAMED and of ODFAUTOS.
 *
 * @param String $archivName Name of the file to make.
 * @param String $bodyOfDocu The XML of its text.
 * @param Array  $extraParts Parts to add or to replace, name => content.
 * @param String $masterPage The XML of its pages: their headers and footers.
 * @return String The path of the document.
 */
function makeOdt( String $archivName, String $bodyOfDocu, Array $extraParts=Array(), String $masterPage='' ): String {
	$partLst = Array(
		'mimetype'=>'application/vnd.oasis.opendocument.text',
		'content.xml'=>'<?xml version="1.0" encoding="UTF-8"?><office:document-content '.ODFSPACE.'><office:automatic-styles>'.ODFAUTOS.'</office:automatic-styles><office:body><office:text>'.$bodyOfDocu.'</office:text></office:body></office:document-content>',
		'styles.xml'=>'<?xml version="1.0" encoding="UTF-8"?><office:document-styles '.ODFSPACE.'><office:styles>'.ODFNAMED.'</office:styles><office:automatic-styles><style:style style:name="P1" style:family="paragraph" style:parent-style-name="Title"><style:paragraph-properties fo:text-align="end"/></style:style></office:automatic-styles><office:master-styles>'.$masterPage.'</office:master-styles></office:document-styles>'
	);
	$zipPath = inTempDir($archivName);
	$zipArch = new ZipArchive();
	$zipArch->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	foreach( array_merge($partLst, $extraParts) as $prtNme=>$prtTxt ){
		$zipArch->addFromString($prtNme, $prtTxt);
	}
	$zipArch->close();
	return $zipPath;
}

/**
 * Writes the XML of a paragraph.
 *
 * @param String $textOfPara The XML of what it holds.
 * @param String $styleNames The style it names, 'Text_20_body' unless told otherwise.
 * @return String The XML.
 */
function oPar( String $textOfPara, String $styleNames='Text_20_body' ): String {
	return '<text:p'.(($styleNames!=='') ? ' text:style-name="'.$styleNames.'"' : '').'>'.$textOfPara.'</text:p>';
}

/**
 * Writes blocks in a short form, one per line: the kind, the attributes
 * when there are some apart from the style of the body, then each span
 * as [text|marks|>link|@field]. The blocks a block contains follow it,
 * indented.
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
		// The style of nearly every paragraph of the tests is left out, to keep the lines short
		$attrLst = $oneBlk['attrs'];
		if( ($attrLst['style'] ?? '')=='Text body' ){
			unset($attrLst['style']);
		}
		$lineLst[] = rtrim($indentText.$oneBlk['block'].' '.(empty($attrLst) ? '' : showValue($attrLst).' ').$spanTxt);
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
function odtRead( String $sourcePath, Array $parseOptns=Array() ): Mixed {
	return runSafely(function() use ($sourcePath, $parseOptns){
		$prsrObj = new Odtx();
		return $prsrObj->parseFlux(Fluxd::openLocal($sourcePath), $parseOptns);
	});
}

/**
 * Builds a document from the XML of its text, parses it, and returns its
 * blocks in the short form.
 *
 * @param String $bodyOfDocu The XML of the text.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The lines, or a 'THROWN ...' string.
 */
function odtLines( String $bodyOfDocu, Array $parseOptns=Array() ): Mixed {
	$envlope = odtRead(makeOdt('body.odt', $bodyOfDocu), $parseOptns);
	return is_array($envlope) ? blkLines($envlope['items']) : $envlope;
}

$offcDir = TST_DIR.DS.'fixtures'.DS.'office'.DS;

// A document saved by OpenOffice
checkSame('a document saved by OpenOffice: headings, formatting and a link, lists marked and numbered, a table, accents', blkLines(odtRead($offcDir.'sample.odt')['items']), Array(
	'headr {"level":1,"style":"Heading 1"} [Sample document]',
	'parag [First paragraph, with ][bold|bold][ and ][italic|ital][ words and a ][link|>https://example.org/shows][.]',
	'headr {"level":2,"style":"Heading 2"} [A list]',
	'lists {"lines":3,"bytes":31,"order":false}', '  entry [First item]', '  entry [Second item]', '  entry [Third item]',
	'lists {"lines":2,"bytes":16,"order":true}', '  entry [Step one]', '  entry [Step two]',
	'headr {"level":2,"style":"Heading 2"} [A table]',
	'table {"heads":[],"colms":3,"lines":3,"bytes":58}',
	'  tbrow', '    tcell {"style":"Table Heading"} [Title]', '    tcell {"style":"Table Heading"} [Venue]', '    tcell {"style":"Table Heading"} [Seats]',
	'  tbrow', '    tcell {"style":"Table Contents"} [Alpha Night]', '    tcell {"style":"Table Contents"} [Hall A]', '    tcell {"style":"Table Contents"} [120]',
	'  tbrow', '    tcell {"style":"Table Contents"} [Les Enchaînés]', '    tcell {"style":"Table Contents"} [Hall B]', '    tcell {"style":"Table Contents"} [80]',
	'headr {"level":2,"style":"Heading 2"} [Last section]',
	"parag [Accents: à l'été, où ça ?]"
));
checkSame('its details: its title, and what each style used gives', odtRead($offcDir.'sample.odt')['infos'], Array(
	'title'=>'Sample document', 'authr'=>'', 'props'=>Array('Title'=>'Sample document'),
	'frmat'=>Array(
		'Heading 1'=>Array('class'=>'heading-1', 'fonts'=>'Thorndale', 'sizes'=>24, 'marks'=>Array('bold')), 'Text body'=>Array('class'=>'text-body', 'fonts'=>'Times New Roman', 'sizes'=>12),
		'Heading 2'=>Array('class'=>'heading-2', 'fonts'=>'Times New Roman', 'sizes'=>18, 'marks'=>Array('bold')),
		'Table Heading'=>Array('class'=>'table-heading', 'fonts'=>'Times New Roman', 'sizes'=>12, 'marks'=>Array('bold'), 'align'=>'centr'), 'Table Contents'=>Array('class'=>'table-contents', 'fonts'=>'Times New Roman', 'sizes'=>12)
	),
	'phead'=>Array(), 'pfoot'=>Array()
));
checkSame('the same document given as bytes, and an empty content', Array(
	runSafely(function() use ($offcDir){ return (new Odtx())->parseData(file_get_contents($offcDir.'sample.odt'))['items'][0]['plain']; }),
	runSafely(function(){ return (new Odtx())->parseData(''); })
), Array('Sample document', Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array())));

// Headings
checkSame('headings: written as such with their level, or paragraphs in a heading style, by its level, its name, or the title', odtLines(
	'<text:h text:style-name="Heading_20_1" text:outline-level="1">One</text:h><text:h text:style-name="Heading_20_2" text:outline-level="2">Two</text:h><text:h text:outline-level="9">Nine</text:h><text:h>No level</text:h>'
	.oPar('By level', 'Heading_20_1').oPar('By name', 'Heading_20_2').oPar('Title', 'Title').oPar('Made by hand', 'P6').'<text:h text:style-name="Heading_20_1" text:outline-level="1"></text:h>'.oPar('Body')
), Array('headr {"level":1,"style":"Heading 1"} [One]', 'headr {"level":2,"style":"Heading 2"} [Two]', 'headr {"level":6} [Nine]', 'headr {"level":1} [No level]', 'headr {"level":1,"style":"Heading 1"} [By level]', 'headr {"level":2,"style":"Heading 2"} [By name]', 'headr {"level":1,"style":"Title"} [Title]', 'headr {"level":1,"style":"Heading 1","align":"lefts"} [Made by hand]', 'parag {"style":"Heading 1"}', 'parag [Body]'));
checkSame('a bookmark starting a block names it; those the document adds for itself do not', odtLines(
	'<text:h text:outline-level="2"><text:bookmark-start text:name="__RefHeading__12"/><text:bookmark text:name="dates"/><text:bookmark text:name="other"/>Dates<text:bookmark-end text:name="__RefHeading__12"/></text:h>'.oPar('<text:bookmark-start text:name="intro"/>Text<text:bookmark-end text:name="intro"/>').oPar('<text:bookmark text:name="_hidden"/>Plain')
), Array('headr {"level":2,"tagid":"dates"} [Dates]', 'parag {"tagid":"intro"} [Text]', 'parag [Plain]'));

// Text and formatting set by hand
checkSame('bold, italic, underlined, struck, by a style made for the text or by a named one, and what switches them off', odtLines(oPar(
	'plain <text:span text:style-name="T1">b</text:span> <text:span text:style-name="T2">i</text:span> <text:span text:style-name="T3">all three</text:span> <text:span text:style-name="T4">off</text:span> <text:span text:style-name="Strong_20_Emphasis">named</text:span> <text:span text:style-name="T1">out <text:span text:style-name="T2">in</text:span></text:span> <text:span text:style-name="Nowhere">lost</text:span>'
)), Array('parag [plain ][b|bold][ ][i|ital][ ][all three|bold,undr,strk][ off ][named|bold][ ][out |bold][in|bold,ital][ lost]'));
checkSame('a paragraph made bold by hand has its text bold; one in a bold style has not', odtLines(oPar('All <text:span text:style-name="T2">of</text:span> it', 'P2').oPar('Styled <text:span text:style-name="T1">text</text:span>', 'Quotations')), Array('parag [All |bold][of|bold,ital][ it|bold]', 'quote {"style":"Quotations"} [Styled ][text|bold]'));
checkSame('spaces, tabulations, line breaks and accents are kept; the space between two pieces too', odtRead(makeOdt('text.odt', oPar('Les <text:span text:style-name="T1">Enchaînés</text:span> <text:span text:style-name="T1">deux</text:span><text:s/><text:s text:c="3"/>far<text:tab/>tab<text:line-break/>next<text:soft-page-break/>.')))['items'][0]['plain'], "Les Enchaînés deux    far\ttab\nnext.");
checkSame('links: to an address, to a place of the document, and one that would run a script', odtLines(oPar(
	'See <text:a xlink:type="simple" xlink:href="https://nyuark.test/a?x=1&amp;y=2">the <text:span text:style-name="T1">site</text:span></text:a>, <text:a xlink:href="#dates">dates</text:a>, <text:a xlink:href="mailto:a@nyuark.test">mail</text:a>, <text:a xlink:href="javascript:alert(1)">bad</text:a>, <text:a>none</text:a>'
)), Array('parag [See ][the |>https://nyuark.test/a?x=1&y=2][site|bold|>https://nyuark.test/a?x=1&y=2][, ][dates|>#dates][, ][mail|>mailto:a@nyuark.test][, bad, none]'));
checkSame('what a tracked change deleted is not read, what it added is', odtLines('<text:tracked-changes><text:changed-region text:id="c1"><text:deletion><office:change-info><dc:creator>a</dc:creator></office:change-info>'.oPar('deleted text').'</text:deletion></text:changed-region></text:tracked-changes>'.oPar('Kept <text:change text:change-id="c1"/><text:change-start text:change-id="c2"/>added<text:change-end text:change-id="c2"/> text')), Array('parag [Kept added text]'));

// Empty paragraphs
$voidXml = oPar('').oPar('<text:s text:c="3"/>').oPar('kept').'<text:h text:outline-level="1"/>'.'<text:list text:style-name="L1"><text:list-item>'.oPar('').'</text:list-item><text:list-item>'.oPar('entry').'</text:list-item></text:list><text:p/>';
checkSame('paragraphs holding nothing, or spaces only, are blocks without text: a heading and an entry too', odtLines($voidXml), Array('parag', 'parag', 'parag [kept]', 'parag', 'lists {"lines":2,"bytes":5,"order":false}', '  entry', '  entry [entry]', 'parag'));
checkSame('empty: they are left out when told so', odtLines($voidXml, Array('empty'=>false)), Array('parag [kept]', 'lists {"lines":1,"bytes":5,"order":false}', '  entry [entry]'));

// Page breaks
checkSame('page breaks: asked before or after a paragraph by hand or by its style, and where the kind of page changes after the text has started', odtLines(
	oPar('first, on a kind of page of its own', 'P5').oPar('before', 'P3').oPar('after', 'P4').oPar('by style', 'Chapter_20_break').oPar('other kind of page', 'P5').oPar('last')
), Array('parag [first, on a kind of page of its own]', 'rules {"break":true}', 'parag [before]', 'parag [after]', 'rules {"break":true}', 'rules {"break":true}', 'parag {"style":"Chapter break"} [by style]', 'rules {"break":true}', 'parag [other kind of page]', 'parag [last]'));

// Fields
checkSame('a field is the text last shown for it, and tells what it stands for: the page, the pages, a date, a property', odtLines(
	oPar('Page <text:page-number text:select-page="current">3</text:page-number> of <text:page-count>12</text:page-count>, <text:date text:date-value="2026-12-28">28/12/26</text:date> <text:time>20:30</text:time>.')
	.oPar('<text:title>Alpha Night</text:title> by <text:initial-creator>J. J.</text:initial-creator>, about <text:subject>Shows</text:subject>, ref <text:user-defined text:name="Client Reference"><text:span text:style-name="T1">NYU</text:span>-2026-014</text:user-defined>.')
	.oPar('Other fields are their text: <text:bookmark-ref text:ref-name="dates">Dates</text:bookmark-ref>, <text:chapter>One</text:chapter>, <text:sequence text:name="Table">1</text:sequence>.')
), Array(
	'parag [Page ][3|@pgnum][ of ][12|@pgtot][, ][28/12/26|@dates][ ][20:30|@dates][.]',
	'parag [Alpha Night|@props:Title][ by ][J. J.|@props:Author][, about ][Shows|@props:Subject][, ref ][NYU-2026-014|@props:Client Reference][.]',
	'parag [Other fields are their text: Dates, One, 1.]'
));

// Lists
checkSame('lists: marked or numbered by level, an entry holding paragraphs and lists, a list following the style of the one it is in', odtLines(
	'<text:list text:style-name="Numbered"><text:list-header>'.oPar('header').'</text:list-header><text:list-item>'.oPar('one').oPar('second paragraph').'<text:list><text:list-item>'.oPar('one-a').'<text:list><text:list-item>'.oPar('one-a-i', 'P2').'</text:list-item></text:list></text:list-item><text:list-item>'.oPar('one-b').'</text:list-item></text:list></text:list-item>'
	.'<text:list-item><text:list text:style-name="L1"><text:list-item>'.oPar('only a list').'</text:list-item></text:list></text:list-item><text:list-item/></text:list>'
	.'<text:list text:style-name="L2"><text:list-item>'.oPar('numbered').'<text:list><text:list-item>'.oPar('without number').'</text:list-item></text:list></text:list-item></text:list><text:list text:style-name="Nowhere"><text:list-item>'.oPar('unknown style').'</text:list-item></text:list><text:list text:style-name="L1"/>'
), Array(
	'lists {"lines":3,"bytes":53,"order":true}',
	'  entry [header]',
	'  entry [one]',
	'    parag [second paragraph]',
	'    lists {"lines":2,"bytes":17,"order":false}',
	'      entry [one-a]',
	'        lists {"lines":1,"bytes":7,"order":true}',
	'          entry [one-a-i|bold]',
	'      entry [one-b]',
	'  entry',
	'    lists {"lines":1,"bytes":11,"order":false}',
	'      entry [only a list]',
	'lists {"lines":1,"bytes":22,"order":true}',
	'  entry [numbered]',
	'    lists {"lines":1,"bytes":14,"order":false}',
	'      entry [without number]',
	'lists {"lines":1,"bytes":13,"order":false}',
	'  entry [unknown style]'
));

// Tables
$oCell = function( String $cellXml, String $attrsXml='' ){ return '<table:table-cell'.$attrsXml.'>'.$cellXml.'</table:table-cell>'; };
checkSame('a table: its header rows, cells over several columns and rows, a cell holding blocks, a table in a cell', odtLines('<table:table table:name="T"><table:table-column table:number-columns-repeated="3"/>'
	.'<table:table-header-rows><table:table-row>'.$oCell(oPar('Title')).$oCell(oPar('Seats')).$oCell(oPar('Notes')).'</table:table-row></table:table-header-rows>'
	.'<table:table-row>'.$oCell(oPar('<text:span text:style-name="T1">Season</text:span>'), ' table:number-columns-spanned="2"').'<table:covered-table-cell/>'.$oCell(oPar('long', 'P1'), ' table:number-rows-spanned="2"').'</table:table-row>'
	.'<table:table-rows><table:table-row>'.$oCell(oPar('Alpha').oPar('second paragraph').'<text:list text:style-name="L1"><text:list-item>'.oPar('entry').'</text:list-item></text:list>').$oCell(oPar('')).'<table:covered-table-cell/></table:table-row></table:table-rows>'
	.'<table:table-row>'.$oCell('<table:table><table:table-row>'.$oCell(oPar('inner')).'</table:table-row></table:table>').$oCell('').$oCell(oPar('By level', 'Heading_20_1')).'</table:table-row>'
	.'</table:table><table:table><table:table-row/></table:table>'.oPar('after')
), Array(
	'table {"heads":["Title","Seats","Notes"],"colms":3,"lines":3,"bytes":64}',
	'  thead', '    tcell [Title]', '    tcell [Seats]', '    tcell [Notes]',
	'  tbrow', '    tcell {"colsp":2} [Season|bold]', '    tcell {"rowsp":2,"align":"centr"} [long]',
	'  tbrow', '    tcell [Alpha]', '      parag [second paragraph]', '      lists {"lines":1,"bytes":5,"order":false}', '        entry [entry]', '    tcell',
	'  tbrow', '    tcell', '      table {"heads":[],"colms":1,"lines":1,"bytes":5}', '        tbrow', '          tcell [inner]', '    tcell', '    tcell', '      headr {"level":1,"style":"Heading 1"} [By level]',
	'parag [after]'
));

// Images and frames
checkSame('an image stands between the two halves of its paragraph: kept in the archive, kept elsewhere, an unsafe one left out; a box of text gives its blocks', odtLines(
	oPar('Before <draw:frame draw:name="Image1"><draw:image xlink:href="Pictures/hall.png" xlink:type="simple"/><svg:title>Title</svg:title><svg:desc>The hall</svg:desc></draw:frame> after')
	.oPar('<draw:frame><draw:image xlink:href="https://nyuark.test/far.png"/><svg:title>Only a title</svg:title></draw:frame>').oPar('<draw:frame><draw:image xlink:href="./Pictures/dot.png"/></draw:frame><draw:frame><draw:image xlink:href="javascript:alert(1)"/></draw:frame>')
	.oPar('Framed: <draw:frame><draw:text-box>'.oPar('<draw:frame><draw:image xlink:href="Pictures/boxed.png"/></draw:frame>').oPar('Caption <text:span text:style-name="T2">one</text:span>').oPar('').'</draw:text-box></draw:frame>')
), Array('parag [Before ]', 'image {"sourc":"Pictures/hall.png"} [The hall]', 'parag [ after]', 'image {"sourc":"https://nyuark.test/far.png"} [Only a title]', 'image {"sourc":"Pictures/dot.png"}', 'parag [Framed: ]', 'image {"sourc":"Pictures/boxed.png"}', 'parag [Caption ][one|ital]'));

// Styles
$stylPth = makeOdt('styles.odt', '<text:h text:style-name="Heading_20_1" text:outline-level="1">Alpha Night</text:h>'.oPar('Plain text.').oPar('Centred by hand.', 'P1').oPar('Left by hand, as its style.', 'P8').oPar('Centred, as its style.', 'P7').oPar('No style at all.', '').oPar('A style the document does not have.', 'Nowhere')
	.oPar('A night to remember.', 'Quotations').oPar('Client says.', '_33__20_-_20_Client').oPar('if( $a ){', 'Preformatted_20_Text').oPar('<text:s text:c="4"/>echo <text:span text:style-name="T1">1</text:span>;', 'Preformatted_20_Text').oPar('', 'Preformatted_20_Text').oPar('}', 'Preformatted_20_Text').oPar('', 'Preformatted_20_Text').oPar('end'));
checkSame('every block tells its style; the alignment is told when it is not that of the style; a quote and code by their style', runSafely(function() use ($stylPth){
	return array_map(function($blockArray){ return rtrim($blockArray['block'].' '.showValue($blockArray['attrs']).' '.$blockArray['plain']); }, odtRead($stylPth)['items']);
}), Array(
	'headr {"level":1,"style":"Heading 1"} Alpha Night', 'parag {"style":"Text body"} Plain text.', 'parag {"style":"Text body","align":"centr"} Centred by hand.', 'parag {"style":"Text body"} Left by hand, as its style.', 'headr {"level":1,"style":"Heading 1"} Centred, as its style.',
	'parag [] No style at all.', 'parag [] A style the document does not have.', 'quote {"style":"Quotations"} A night to remember.', 'parag {"style":"3 - Client"} Client says.', "codes {\"style\":\"Preformatted Text\"} if( \$a ){\n    echo 1;\n\n}", 'parag {"style":"Text body"} end'
));
checkSame('frmat: what each style used gives, once, with what it takes from the style it is based on and from the document', odtRead($stylPth)['infos']['frmat'], Array(
	'Heading 1'=>Array('class'=>'heading-1', 'fonts'=>'Albany', 'sizes'=>18, 'color'=>'#2F5496', 'marks'=>Array('bold'), 'align'=>'centr'),
	'Text body'=>Array('class'=>'text-body', 'fonts'=>'Times New Roman', 'sizes'=>12),
	'Quotations'=>Array('class'=>'quotations', 'fonts'=>'Times New Roman', 'sizes'=>12, 'marks'=>Array('ital', 'undr'), 'align'=>'justf'),
	'3 - Client'=>Array('class'=>'s-3-client', 'fonts'=>'Times New Roman', 'sizes'=>12),
	'Preformatted Text'=>Array('class'=>'preformatted-text', 'fonts'=>'Courier New', 'sizes'=>10)
));
checkSame('a size that is not a whole number of points', odtRead(makeOdt('size.odt', oPar('x', 'Heading_20_2')))['infos']['frmat'], Array('Heading 2'=>Array('class'=>'heading-2', 'fonts'=>'Albany', 'sizes'=>16.5, 'marks'=>Array('bold', 'ital'))));

// Headers and footers
$pagePth = makeOdt('pages.odt', oPar('Body', 'P1'), Array(),
	'<style:master-page style:name="Standard"><style:header>'.oPar('nyuArk, season 2026', 'P1').oPar('').'</style:header><style:header-left>'.oPar('nyuArk, season 2026', 'P1').'</style:header-left>'
	.'<style:footer>'.oPar('Ref. <text:user-defined text:name="Reference">NYU-2026-014</text:user-defined>, page <text:page-number>1</text:page-number> of <text:page-count>2</text:page-count>, <text:a xlink:href="https://nyuark.test/footer">site</text:a>').'<table:table><table:table-row><table:table-cell>'.oPar('cell').'</table:table-cell></table:table-row></table:table></style:footer></style:master-page>'
	.'<style:master-page style:name="First"><style:header>'.oPar('First page only').'</style:header><style:footer>'.oPar('').'</style:footer></style:master-page><style:master-page style:name="Bare"/>'
);
checkSame('the headers and the footers of the pages stand in the details: each that differs once, with the styles made for them and their fields', runSafely(function() use ($pagePth){
	$envlope = odtRead($pagePth);
	return Array(blkLines($envlope['items']), blkLines($envlope['infos']['phead']), blkLines($envlope['infos']['pfoot']), array_keys($envlope['infos']['frmat']));
}), Array(
	Array('parag {"align":"centr"} [Body]'),
	Array('headr {"level":1,"style":"Title","align":"right"} [nyuArk, season 2026]', 'parag [First page only]'),
	Array('parag [Ref. ][NYU-2026-014|@props:Reference][, page ][1|@pgnum][ of ][2|@pgtot][, ][site|>https://nyuark.test/footer]', 'table {"heads":[],"colms":1,"lines":1,"bytes":4}', '  tbrow', '    tcell [cell]'),
	Array('Text body', 'Title')
));

// Notes
$oNote = function( String $noteClass, String $noteBody ){ return '<text:note text:id="ftn1" text:note-class="'.$noteClass.'"><text:note-citation>i</text:note-citation><text:note-body>'.$noteBody.'</text:note-body></text:note>'; };
$notePth = makeOdt('notes.odt',
	oPar('Playing on 28 December'.$oNote('footnote', oPar('Doors open at <text:span text:style-name="T1">8 pm</text:span>.').oPar('').oPar('See <text:a xlink:href="https://nyuark.test/note">the site</text:a>')).' at Hall A'.$oNote('endnote', oPar('At the very end.')).'.')
	.oPar('Again'.$oNote('footnote', oPar('Second note'.$oNote('footnote', oPar('A note in a note.')).'.')).' and a note without text'.$oNote('footnote', ''))
);
checkSame('notes: numbered in the order of the text, the reference a link to them, the notes after the text, those of the end last', blkLines(odtRead($notePth)['items']), Array(
	'parag [Playing on 28 December][1|>#fnote1][ at Hall A][1|>#enote1][.]',
	'parag [Again][2|>#fnote2][ and a note without text][4|>#fnote4]',
	'fnote {"tagid":"fnote1","numbr":1} [Doors open at ][8 pm|bold][.]',
	'  parag [See ][the site|>https://nyuark.test/note]',
	'fnote {"tagid":"fnote2","numbr":2} [Second note][3|>#fnote3][.]',
	'fnote {"tagid":"fnote3","numbr":3} [A note in a note.]',
	'fnote {"tagid":"fnote4","numbr":4}',
	'fnote {"tagid":"enote1","numbr":1} [At the very end.]'
));

// Comments
$oCmnt = function( String $cmntName, String $cmntBody ){ return '<office:annotation'.(($cmntName!=='') ? ' office:name="'.$cmntName.'"' : '').'>'.$cmntBody.'</office:annotation>'; };
$cmntPth = makeOdt('comments.odt',
	oPar('Playing on '.$oCmnt('c1', '<dc:creator>A. Martin</dc:creator><dc:date>2026-10-02T14:05:00</dc:date>'.oPar('Check this <text:span text:style-name="T2">date</text:span>').oPar('Second line')).'28 <text:span text:style-name="T1">December</text:span><office:annotation-end office:name="c1"/> at '.$oCmnt('c2', '<dc:creator>Les Enchaînés</dc:creator>'.oPar('Both?')).'Hall A')
	.oPar('and Hall B<office:annotation-end office:name="c2"/>.'.$oCmnt('', oPar('About a place, not a passage')))
);
checkSame('cmnts: the comments of the reviewers, with the passage each is about, in the details and never in the text', runSafely(function() use ($cmntPth){
	$envlope = odtRead($cmntPth, Array('cmnts'=>true));
	return Array(blkLines($envlope['items']), $envlope['infos']['cmnts']);
}), Array(
	Array('parag [Playing on 28 ][December|bold][ at Hall A]', 'parag [and Hall B.]'),
	Array(
		Array('authr'=>'A. Martin', 'dated'=>'2026-10-02T14:05:00', 'plain'=>"Check this date\n\nSecond line", 'about'=>'28 December'),
		Array('authr'=>'Les Enchaînés', 'dated'=>'', 'plain'=>'Both?', 'about'=>'Hall A and Hall B'),
		Array('authr'=>'', 'dated'=>'', 'plain'=>'About a place, not a passage', 'about'=>'')
	)
));
checkSame('without the option, the comments are not read at all', runSafely(function() use ($cmntPth){
	$envlope = odtRead($cmntPth);
	return Array(array_keys($envlope['infos']), blkLines($envlope['items']), strpos(showValue($envlope), 'Check this'));
}), Array(Array('title', 'authr', 'props', 'frmat', 'phead', 'pfoot'), Array('parag [Playing on 28 ][December|bold][ at Hall A]', 'parag [and Hall B.]'), false));

// Properties
checkSame('properties: the ones every document has, by the name Word gives them, then the ones added to it, with their type', odtRead(makeOdt('props.odt', oPar('x'), Array(
	'meta.xml'=>'<office:document-meta '.ODFSPACE.'><office:meta><dc:title> Alpha Night </dc:title><meta:initial-creator>Les Enchaînés</meta:initial-creator><dc:creator>J. J.</dc:creator><dc:subject></dc:subject><dc:description>About shows</dc:description><meta:keyword>shows</meta:keyword><meta:keyword>2026</meta:keyword><meta:generator>Test</meta:generator>'
		.'<meta:user-defined meta:name="Reference">NYU-2026-014</meta:user-defined><meta:user-defined meta:name="Seats" meta:value-type="float">120</meta:user-defined><meta:user-defined meta:name="Price" meta:value-type="float">12.5</meta:user-defined><meta:user-defined meta:name="Open" meta:value-type="boolean">true</meta:user-defined><meta:user-defined meta:name="Since" meta:value-type="date">2026-12-28</meta:user-defined><meta:user-defined meta:name="">nameless</meta:user-defined></office:meta></office:document-meta>'
)))['infos'], Array('title'=>'Alpha Night', 'authr'=>'Les Enchaînés', 'props'=>Array('Title'=>'Alpha Night', 'Author'=>'Les Enchaînés', 'Comments'=>'About shows', 'LastSavedBy'=>'J. J.', 'Keywords'=>'shows, 2026', 'Reference'=>'NYU-2026-014', 'Seats'=>120, 'Price'=>12.5, 'Open'=>true, 'Since'=>'2026-12-28'), 'frmat'=>Array('Text body'=>Array('class'=>'text-body', 'fonts'=>'Times New Roman', 'sizes'=>12)), 'phead'=>Array(), 'pfoot'=>Array()));
checkSame('a document saved without its first author has the one who saved it; one without properties has none', Array(
	odtRead(makeOdt('saved.odt', oPar('x'), Array('meta.xml'=>'<office:document-meta '.ODFSPACE.'><office:meta><dc:creator>J. J.</dc:creator></office:meta></office:document-meta>')))['infos']['authr'],
	odtRead(makeOdt('bare.odt', oPar('x', '')))['infos']
), Array('J. J.', Array('title'=>'', 'authr'=>'', 'props'=>Array(), 'frmat'=>Array(), 'phead'=>Array(), 'pfoot'=>Array())));

// Sections and indexes
checkSame('sections and tables of contents are read through', odtLines('<text:section text:name="S">'.oPar('In a section').'<text:section>'.oPar('Deeper').'</text:section></text:section><text:table-of-content><text:table-of-content-source/><text:index-body><text:index-title>'.oPar('Contents').'</text:index-title>'.oPar('One<text:tab/>1').'</text:index-body></text:table-of-content><text:sequence-decls/>'), Array('parag [In a section]', 'parag [Deeper]', 'parag [Contents]', "parag [One\t1]"));

// What is refused
checkLike('a spreadsheet under the name of a text', odtRead($offcDir.'sample.ods'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: the document is not a text document/');
checkLike('an archive that is not an OpenDocument file', odtRead($offcDir.'SampleDoc.docx'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part content\.xml is missing/');
checkLike('a document protected by a password', odtRead(makeOdt('locked.odt', '', Array('content.xml'=>random_bytes(200), 'META-INF/manifest.xml'=>'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"><manifest:file-entry manifest:full-path="content.xml"><manifest:encryption-data manifest:checksum="x"/></manifest:file-entry></manifest:manifest>'))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: the document is protected by a password/');
checkLike('a content that is not valid XML', odtRead(makeOdt('broken.odt', '', Array('content.xml'=>'<a><b></a>'))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part content\.xml: Invalid XML content/');
checkSame('one parser, several documents: a note, a comment or an option of one does not reach the next', runSafely(function() use ($notePth, $cmntPth){
	$prsrObj = new Odtx();
	$prsrObj->parseFlux(Fluxd::openLocal($notePth), Array('empty'=>false));
	$prsrObj->parseFlux(Fluxd::openLocal(makeOdt('open.odt', oPar('a<office:annotation office:name="left open">'.oPar('never closed').'</office:annotation>b'))), Array('cmnts'=>true));
	$envlope = $prsrObj->parseFlux(Fluxd::openLocal(makeOdt('next.odt', oPar('c<text:note text:note-class="footnote"><text:note-body>'.oPar('n').'</text:note-body></text:note>').oPar(''))));
	return Array(blkLines($envlope['items']), array_keys($envlope['infos']));
}), Array(Array('parag [c][1|>#fnote1]', 'parag', 'fnote {"tagid":"fnote1","numbr":1} [n]'), Array('title', 'authr', 'props', 'frmat', 'phead', 'pfoot')));
checkSame('no PHP warning along the way', takeNotes(), Array());

// Through the index, Dcmnt and the HTML writer
checkSame('index: texts by extension and by content type', Array(get_class(Index::forExtnsn('ODT')), get_class(Index::forCnType('application/vnd.oasis.opendocument.text'))), Array('nyu\Data\Parsers\Odtx', 'nyu\Data\Parsers\Odtx'));
checkSame('a text through Dcmnt, with and without its extension', runSafely(function() use ($offcDir){
	$barePth = inTempDir('document');
	copy($offcDir.'sample.odt', $barePth);
	$docFile = new Dcmnt($offcDir.'sample.odt');
	$bareDoc = new Dcmnt($barePth);
	return Array($docFile->readValue('sig'), $docFile->readValue('shp'), $docFile->readValue('inf')['title'], $docFile->readValue()[2]['plain'], $bareDoc->readValue('ext'), count($bareDoc->readValue()), Shape::bySection($bareDoc->readValue())[0]['nodes'][1]['title']);
}), Array('odt', 'block', 'Sample document', 'A list', 'odt', 9, 'A table'));
checkSame('a text read then written as HTML', runSafely(function() use ($offcDir){
	$htmText = (new Html())->writeData((new Odtx())->parseFlux(Fluxd::openLocal($offcDir.'sample.odt')));
	return substr($htmText, 0, strpos($htmText, '</ol>')+6);
}), "<h1 class=\"heading-1\">Sample document</h1>\n<p class=\"text-body\">First paragraph, with <strong>bold</strong> and <em>italic</em> words and a <a href=\"https://example.org/shows\">link</a>.</p>\n<h2 class=\"heading-2\">A list</h2>\n<ul>\n<li class=\"text-body\">First item</li>\n<li class=\"text-body\">Second item</li>\n<li class=\"text-body\">Third item</li>\n</ul>\n<ol>\n<li class=\"text-body\">Step one</li>\n<li class=\"text-body\">Step two</li>\n</ol>\n");

endChecks();
