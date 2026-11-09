<?php

// Data\Parsers\EPub : books read into text blocks.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Fluxd;
use nyu\Data\Shape;
use nyu\Data\Parsers\EPub;
use nyu\Data\Parsers\Index;
use nyu\Data\Writers\Html;

if( !class_exists('ZipArchive') ){
	recordSkipping('books', 'the zip extension is not loaded');
	finishChecking();
}

const BOOKPACK = 'xmlns="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/"';

/**
 * Writes a page of a book.
 *
 * @param String $bodyOfPage The HTML of its body.
 * @return String The page.
 */
function bookPage( String $bodyOfPage ): String {
	return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<!DOCTYPE html>'."\n".'<html xmlns="http://www.w3.org/1999/xhtml"><head><title>Page</title><style>p { color: red; }</style></head><body>'.$bodyOfPage.'</body></html>';
}

/**
 * Builds a book in the temporary folder: three chapters and a drawing in
 * its reading order, a stylesheet and images among its parts.
 *
 * @param String $archivName Name of the file to make.
 * @param Array  $extraParts Parts to add or to replace, name => content; null leaves a part out.
 * @return String The path of the book.
 */
function makeBook( String $archivName, Array $extraParts=Array() ): String {
	$partLst = Array(
		'mimetype'=>'application/epub+zip',
		'META-INF/container.xml'=>'<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
		'OEBPS/content.opf'=>'<?xml version="1.0" encoding="UTF-8"?><package '.BOOKPACK.' version="3.0" unique-identifier="id"><metadata>'
			.'<dc:identifier id="id">urn:isbn:9780000000001</dc:identifier><dc:title>  Alpha
  Night </dc:title><dc:creator>Les Enchaînés</dc:creator><dc:creator>J. J.</dc:creator><dc:language>fr</dc:language><dc:publisher>nyuArk</dc:publisher><dc:date>2026-12-28</dc:date><dc:subject>Shows</dc:subject><dc:subject>Theatre</dc:subject><dc:description>A night to remember.</dc:description><dc:rights></dc:rights><meta property="dcterms:modified">2026-12-28T20:30:00Z</meta>'
			.'</metadata><manifest>'
			.'<item id="c1" href="text/chapter1.xhtml" media-type="application/xhtml+xml"/><item id="c2" href="text/chapter%202.xhtml" media-type="application/xhtml+xml"/><item id="c3" href="chapter3.html" media-type="text/html"/><item id="c4" href="text/empty.xhtml" media-type="application/xhtml+xml"/>'
			.'<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/><item id="css" href="style.css" media-type="text/css"/><item id="pic" href="images/hall.png" media-type="image/png"/><item id="cov" href="images/cover.jpg" media-type="image/jpeg" properties="cover-image"/><item id="svg" href="images/plan.svg" media-type="image/svg+xml"/>'
			.'</manifest><spine><itemref idref="c1"/><itemref idref="svg"/><itemref idref="c2"/><itemref idref="nowhere"/><itemref idref="c4"/><itemref idref="c3" linear="no"/></spine></package>',
		'OEBPS/text/chapter1.xhtml'=>bookPage('<h1 id="one">Chapter <em>one</em></h1><p>Playing at <a href="chapter%202.xhtml#two">Hall A</a>, <b>120</b> seats.</p><p><img src="../images/hall.png" alt="The hall"/></p><script>alert(1)</script>'),
		'OEBPS/text/chapter 2.xhtml'=>bookPage('<h1 id="two">Chapter two</h1><ul><li>Music<img src="https://nyuark.test/far.png" alt="Far"/></li><li>Dance</li></ul><table><tr><td><img src="pic.png"/></td></tr></table><p><a href="javascript:alert(1)">bad</a> <a href="#one">up</a></p>'),
		'OEBPS/chapter3.html'=>bookPage('<h2>Notes</h2><p>Les Enchaînés &eacute;t&eacute;.</p><img src="/abs.png"/>'),
		'OEBPS/text/empty.xhtml'=>bookPage(''),
		'OEBPS/nav.xhtml'=>bookPage('<nav><ol><li><a href="text/chapter1.xhtml">One</a></li></ol></nav>'),
		'OEBPS/images/plan.svg'=>'<svg xmlns="http://www.w3.org/2000/svg"><text>Not a page</text></svg>'
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
 * Writes blocks in a short form, one per line: the kind, the attributes
 * when there are some, then each span as [text|marks|>link]. The blocks
 * a block contains follow it, indented.
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
			$spanTxt .= '['.$oneSpn['texts'].(empty($oneSpn['marks']) ? '' : '|'.implode(',', $oneSpn['marks'])).(($oneSpn['links']==='') ? '' : '|>'.$oneSpn['links']).']';
		}
		$lineLst[] = rtrim($indentText.$oneBlk['block'].' '.(empty($oneBlk['attrs']) ? '' : printValueText($oneBlk['attrs']).' ').$spanTxt);
		$lineLst = array_merge($lineLst, blkLines($oneBlk['items'], $indentText.'  '));
	}
	return $lineLst;
}

/**
 * Parses a book and returns its envelope, or the reason it was refused.
 *
 * @param String $sourcePath Path of the book.
 * @param Array  $parseOptns Options of the parser.
 * @return mixed The envelope, or a 'THROWN ...' string.
 */
function bookRead( String $sourcePath, Array $parseOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($sourcePath, $parseOptns){
		$prsrObj = new EPub();
		return $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($sourcePath), $parseOptns);
	});
}

$bookPth = makeBook('alpha.epub');

// The text
checkIdentical('the chapters in the order of the book, a page break between two of them, what is not a page of text left out', blkLines(bookRead($bookPth)['items']), Array(
	'headline {"level":1,"tagid":"one"} [Chapter ][one|emphasis]',
	'paragrph [Playing at ][Hall A|>chapter%202.xhtml#two][, ][120|boldFace][ seats.]',
	'imageBox {"sourc":"OEBPS/images/hall.png"} [The hall]',
	'ruleLine {"break":true}',
	'headline {"level":1,"tagid":"two"} [Chapter two]',
	'itemList {"lines":2,"bytes":13,"order":false}',
	'  listItem [Music]',
	'    imageBox {"sourc":"https://nyuark.test/far.png"} [Far]',
	'  listItem [Dance]',
	'tableSet {"heads":[],"colms":1,"lines":1,"bytes":0}',
	'  tableRow',
	'    cellText',
	'      imageBox {"sourc":"OEBPS/text/pic.png"}',
	'paragrph [bad ][up|>#one]',
	'ruleLine {"break":true}',
	'headline {"level":2} [Notes]',
	'paragrph [Les Enchaînés été.]',
	'imageBox {"sourc":"/abs.png"}'
));
checkIdentical('every span has its four keys, as any text block has', bookRead($bookPth)['items'][0]['spans'], Array(Array('texts'=>'Chapter ', 'marks'=>Array(), 'links'=>'', 'field'=>''), Array('texts'=>'one', 'marks'=>Array('emphasis'), 'links'=>'', 'field'=>'')));

// The details
checkIdentical('the details of the book: title, authors, language, chapters, cover, and all it tells of itself', bookRead($bookPth)['infos'], Array(
	'title'=>'Alpha Night', 'authr'=>'Les Enchaînés, J. J.', 'langg'=>'fr', 'chaps'=>4, 'cover'=>'OEBPS/images/cover.jpg',
	'props'=>Array('Identifier'=>'urn:isbn:9780000000001', 'Title'=>'Alpha Night', 'Author'=>'Les Enchaînés, J. J.', 'Language'=>'fr', 'Publisher'=>'nyuArk', 'Published'=>'2026-12-28', 'Keywords'=>'Shows, Theatre', 'Comments'=>'A night to remember.')
));
checkIdentical('an older book names its cover among its details, writes its elements with a prefix, and keeps its description at the root of the archive', runCatchThrown(function(){
	$envlope = bookRead(makeBook('older.epub', Array(
		'META-INF/container.xml'=>'<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="book.opf"/></rootfiles></container>',
		'OEBPS/content.opf'=>null,
		'book.opf'=>'<opf:package xmlns:opf="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/" version="2.0"><opf:metadata><dc:title>Old</dc:title><opf:meta name="cover" content="cov"/></opf:metadata><opf:manifest><opf:item id="p" href="page.htm"/><opf:item id="cov" href="img/cover.png" media-type="image/png"/><opf:item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/></opf:manifest><opf:spine toc="ncx"><opf:itemref idref="p"/><opf:itemref idref="ncx"/></opf:spine></opf:package>',
		'page.htm'=>'<html><body><p>Old <i>page</i> <img src="img/a.png"></p></body></html>'
	)));
	return Array(blkLines($envlope['items']), $envlope['infos']);
}), Array(Array('paragrph [Old ][page|emphasis]', 'imageBox {"sourc":"img/a.png"}'), Array('title'=>'Old', 'authr'=>'', 'langg'=>'', 'chaps'=>1, 'cover'=>'img/cover.png', 'props'=>Array('Title'=>'Old'))));
checkIdentical('a book without chapters, and an empty content', Array(
	bookRead(makeBook('void.epub', Array('OEBPS/content.opf'=>'<package '.BOOKPACK.'><metadata/><manifest/><spine/></package>'))),
	runCatchThrown(function(){ return (new EPub())->parseDataArray(''); })
), Array(Array('shape'=>'block', 'infos'=>Array('title'=>'', 'authr'=>'', 'langg'=>'', 'chaps'=>0, 'cover'=>'', 'props'=>Array()), 'items'=>Array()), Array('shape'=>'block', 'infos'=>Array(), 'items'=>Array())));

// With the rest
checkIdentical('a book grouped into sections, and its whole text', runCatchThrown(function() use ($bookPth){
	$blckLst = bookRead($bookPth)['items'];
	$sctnLst = Shape::bySectionArray($blckLst);
	return Array(array_column($sctnLst, 'title'), $sctnLst[1]['nodes'][0]['title'], substr(Shape::documentAsText($blckLst), 0, 52));
}), Array(Array('Chapter one', 'Chapter two'), 'Notes', "Chapter one\n\nPlaying at Hall A, 120 seats.\n\nThe hall"));
checkIdentical('a book read then written as HTML: its scripts and its unsafe links are gone', runCatchThrown(function() use ($bookPth){
	$htmText = (new Html())->renderDocument((new EPub())->parseFluxArray(Fluxd::openLocalFluxd($bookPth)));
	return Array(substr($htmText, 0, strpos($htmText, '<hr class="break">')+19), strpos($htmText, 'alert'), strpos($htmText, 'javascript'), substr_count($htmText, '<hr class="break">'));
}), Array("<h1 id=\"one\">Chapter <em>one</em></h1>\n<p>Playing at <a href=\"chapter%202.xhtml#two\">Hall A</a>, <strong>120</strong> seats.</p>\n<img src=\"OEBPS/images/hall.png\" alt=\"The hall\">\n<hr class=\"break\">\n", false, false, 2));
checkIdentical('the same book given as bytes', runCatchThrown(function() use ($bookPth){ return (new EPub())->parseDataArray(file_get_contents($bookPth))['infos']['title']; }), 'Alpha Night');

// What is refused
checkPregMatch('an archive that is not a book', bookRead(TST_DIR.DS.'fixtures'.DS.'office'.DS.'sample.odt'), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part META-INF\/container\.xml is missing/');
checkPregMatch('a book that does not tell where its description is', bookRead(makeBook('lost.epub', Array('META-INF/container.xml'=>'<container><rootfiles/></container>'))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: the book does not tell where its description is/');
checkPregMatch('a book whose description is missing', bookRead(makeBook('nopack.epub', Array('OEBPS/content.opf'=>null))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part OEBPS\/content\.opf is missing/');
checkPregMatch('a book naming a chapter it does not hold', bookRead(makeBook('nochap.epub', Array('OEBPS/text/chapter 2.xhtml'=>null))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part OEBPS\/text\/chapter 2\.xhtml is missing/');
checkPregMatch('a book whose description is not valid XML', bookRead(makeBook('broken.epub', Array('OEBPS/content.opf'=>'<package><spine></package>'))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: part OEBPS\/content\.opf: Invalid XML content/');
checkPregMatch('a chapter larger than the limit option', bookRead($bookPth, Array('limit'=>300)), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: Entry larger than the 300 bytes allowed/');
$lockXml = function( String $lockedPath ){ return '<encryption xmlns="urn:oasis:names:tc:opendocument:xmlns:container" xmlns:enc="http://www.w3.org/2001/04/xmlenc#"><enc:EncryptedData><enc:CipherData><enc:CipherReference URI="'.$lockedPath.'"/></enc:CipherData></enc:EncryptedData></encryption>'; };
checkPregMatch('a book whose text a seller locked is refused', bookRead(makeBook('locked.epub', Array('META-INF/encryption.xml'=>$lockXml('OEBPS/text/chapter%202.xhtml')))), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid archive: the book is protected against copying/');
checkIdentical('a book whose fonts only are locked is read', bookRead(makeBook('fonts.epub', Array('META-INF/encryption.xml'=>$lockXml('OEBPS/fonts/a.otf'))))['infos']['chaps'], 4);
checkIdentical('a chapter that is not well-formed is repaired, as any page is', blkLines(bookRead(makeBook('loose.epub', Array('OEBPS/text/chapter1.xhtml'=>'<p>one<p>two <b>open</p>')))['items'])[1], 'paragrph [two ][open|boldFace]');
checkIdentical('one parser, several books', runCatchThrown(function() use ($bookPth){
	$prsrObj = new EPub();
	try{ $prsrObj->parseFluxArray(Fluxd::openLocalFluxd(makeBook('bad.epub', Array('OEBPS/content.opf'=>null)))); }catch( Throwable $e ){}
	return $prsrObj->parseFluxArray(Fluxd::openLocalFluxd($bookPth))['infos']['chaps'];
}), 4);
checkIdentical('no PHP warning along the way', takeNotesArray(), Array());

// Through the index and Dcmnt
checkIdentical('index: books by extension and by content type', Array(get_class(Index::forExtnsParser('EPUB')), get_class(Index::findTypeParser('application/epub+zip'))), Array('nyu\Data\Parsers\EPub', 'nyu\Data\Parsers\EPub'));
checkIdentical('a book through Dcmnt, with and without its extension', runCatchThrown(function() use ($bookPth){
	$barePth = inTemporaryDir('book');
	copy($bookPth, $barePth);
	$docFile = new Dcmnt($bookPth);
	$bareDoc = new Dcmnt($barePth);
	return Array($docFile->readFileDetail('sig'), $docFile->readFileDetail('shp'), $docFile->readFileDetail('inf')['title'], $bareDoc->readFileDetail('ext'), $bareDoc->readFileDetail('inf')['chaps']);
}), Array('epub', 'block', 'Alpha Night', 'epub', 4));

finishChecking();
