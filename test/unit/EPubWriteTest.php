<?php

// Data\Writers\Html in its XHTML mode, and Data\Writers\EPub : books made
// of chapters written in it.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Fluxd;
use nyu\Data\Parsers\EPub as EPubRead;
use nyu\Data\Shape;
use nyu\Data\Writers\EPub;
use nyu\Data\Writers\Html;
use nyu\Data\Writers\Index;

/**
 * Writes a content as a book, or returns the reason it was refused.
 *
 * @param Array  $itemsArray The blocks, or the sheets.
 * @param Array  $infosArray What is told of the book.
 * @param Array  $writeOptns Options of the writer.
 * @param String $shapeLabel The shape of the content.
 * @return mixed The bytes, or a 'THROWN ...' string.
 */
function bokWrite( Array $itemsArray, Array $infosArray=Array(), Array $writeOptns=Array(), String $shapeLabel='block' ): Mixed {
	return runCatchThrown(function() use ($itemsArray, $infosArray, $writeOptns, $shapeLabel){
		return (new EPub())->renderDocument(Array('shape'=>$shapeLabel, 'infos'=>$infosArray, 'items'=>$itemsArray), $writeOptns);
	});
}

/**
 * Lists the parts of a book, read without the zip extension.
 *
 * @param String $archvBytes The bytes of the archive.
 * @return Array The parts, name => content.
 */
function bokParts( String $archvBytes ): Array {
	$partLst = Array();
	$headPos = strpos($archvBytes, "PK\x03\x04");
	while( $headPos!==false ){
		$headSet = unpack('vvers/vflag/vmthd/vtime/vdate/Vcrcs/Vpack/Vsize/vname/vxtra', substr($archvBytes, $headPos+4, 26));
		$dataTxt = substr($archvBytes, $headPos+30+$headSet['name']+$headSet['xtra'], $headSet['pack']);
		$partLst[substr($archvBytes, $headPos+30, $headSet['name'])] = ($headSet['mthd']==8) ? strval(gzinflate($dataTxt)) : $dataTxt;
		$headPos = strpos($archvBytes, "PK\x03\x04", $headPos+30+$headSet['name']+$headSet['xtra']+$headSet['pack']);
	}
	return $partLst;
}

/**
 * Tells whether a text is one an XML reader takes.
 *
 * @param String $xmlContent The text.
 * @return Bool True when it is.
 */
function bokIsXml( String $xmlContent ): Bool {
	return (new DOMDocument())->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
}

$mkSpan = Array(Shape::class, 'makeASpanArray');
$mkBlck = Array(Shape::class, 'makeBlockArray');

// The HTML writer, in its XHTML mode
$htmlLst = Array(
	$mkBlck('headline', Array($mkSpan('T & <t>')), Array(), Array('level'=>1, 'tagid'=>'top')),
	$mkBlck('paragrph', Array($mkSpan("line\nbreak \x07bell 'q' \"d\"", Array('boldFace')), $mkSpan('link', Array(), 'https://nyuark.test/?a=1&b=2'), $mkSpan('1', Array(), '', 'pageNumb')), Array(), Array('align'=>'alignCtr')),
	$mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('done')), Array(), Array('check'=>true)), $mkBlck('listItem', Array($mkSpan('open')), Array(), Array('check'=>false))), Array('order'=>false)),
	$mkBlck('imageBox', Array($mkSpan('alt & "text"')), Array(), Array('sourc'=>'a.png')),
	$mkBlck('ruleLine'), $mkBlck('ruleLine', Array(), Array(), Array('break'=>true)),
	$mkBlck('codeBlck', Array($mkSpan("if (a < b && c) {\n}")), Array(), Array('langg'=>'php'))
);
$htmlSet = Array('shape'=>'block', 'infos'=>Array('title'=>'A & B', 'langg'=>'fr'), 'items'=>$htmlLst);
$plainHt = (new Html())->renderDocument($htmlSet, Array('whole'=>true));
$xhtmlTx = (new Html())->renderDocument($htmlSet, Array('whole'=>true, 'xhtml'=>true));
checkIdentical('HTML is not what an XML reader takes; XHTML is', Array(bokIsXml($plainHt), bokIsXml($xhtmlTx)), Array(false, true));
checkPregMatch('XHTML: the page tells what it is written in, and in what language, as XML asks', $xhtmlTx, '/^<\?xml version="1\.0" encoding="UTF-8"\?>\n<!DOCTYPE html>\n<html xmlns="http:\/\/www\.w3\.org\/1999\/xhtml" lang="fr" xml:lang="fr">\n<head>\n<meta charset="utf-8"\/>\n<title>A &amp; B<\/title>\n/');
checkPregMatch('XHTML: every element is closed, every attribute has its value', $xhtmlTx, '/<strong>line<br\/>\nbreak bell &apos;q&apos; &quot;d&quot;<\/strong>.*<li><input type="checkbox" disabled="disabled" checked="checked"\/> done<\/li>\n<li><input type="checkbox" disabled="disabled"\/> open<\/li>.*<img src="a\.png" alt="alt &amp; &quot;text&quot;"\/>\n<hr\/>\n<hr class="break"\/>\n<pre><code class="language-php">if \(a &lt; b &amp;&amp; c\) \{/s');
checkIdentical('XHTML: what XML cannot hold is left out', Array(strpos($xhtmlTx, "\x07"), strpos($plainHt, "\x07")!==false), Array(false, true));
checkPregMatch('HTML is written as before', $plainHt, '/^<!DOCTYPE html>\n<html lang="fr">\n<head>\n<meta charset="utf-8">\n.*<strong>line<br>\nbreak .*<input type="checkbox" disabled checked> done.*<img src="a\.png" alt="alt &amp; &quot;text&quot;">\n<hr>\n<hr class="break">\n/s');
checkIdentical('XHTML: a part of a page, sheets, and a tree of values are XML too', Array(
	bokIsXml('<div>'.(new Html())->renderDocument($htmlSet, Array('xhtml'=>true)).'</div>'),
	bokIsXml('<div>'.(new Html())->renderDocument(Array('shape'=>'sheet', 'infos'=>Array(), 'items'=>Array(Array('title'=>'S & T', 'attrs'=>Array(), 'cells'=>Array(Array('a'=>1, 'b'=>'x<y'))))), Array('xhtml'=>true)).'</div>'),
	bokIsXml('<div>'.(new Html())->renderDocument(Array('shape'=>'nodes', 'infos'=>Array(), 'items'=>Array('a'=>'<b> & c')), Array('xhtml'=>true)).'</div>')
), Array(true, true, true));

// A book, part by part
$bookLst = Array(
	$mkBlck('paragrph', Array($mkSpan('Before any heading.'))),
	$mkBlck('headline', Array($mkSpan('One & <first>')), Array(), Array('level'=>1, 'tagid'=>'one')),
	$mkBlck('paragrph', Array($mkSpan('Text with a note'), $mkSpan('1', Array(), '#fnote1'), $mkSpan(' and a link to '), $mkSpan('the second chapter', Array(), '#two'), $mkSpan(', to '), $mkSpan('this one', Array(), '#one'), $mkSpan(' and '), $mkSpan('nowhere', Array(), 'javascript:alert(1)'), $mkSpan('.'))),
	$mkBlck('headline', Array($mkSpan('One, a section')), Array(), Array('level'=>2)),
	$mkBlck('headline', Array($mkSpan('One, deeper')), Array(), Array('level'=>3)),
	$mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('Done')), Array(), Array('check'=>true)), $mkBlck('listItem', Array($mkSpan('Open')), Array(), Array('check'=>false))), Array('order'=>false)),
	$mkBlck('imageBox', Array($mkSpan('A picture described')), Array(), Array('sourc'=>'https://nyuark.test/picture.png')),
	$mkBlck('headline', Array($mkSpan("Two\nlines")), Array(), Array('level'=>1, 'tagid'=>'two')),
	$mkBlck('paragrph', Array($mkSpan('Page '), $mkSpan('3', Array(), '', 'pageNumb'), $mkSpan(', a second note'), $mkSpan('2', Array(), '#fnote2'), $mkSpan(', the first again'), $mkSpan('1', Array(), '#fnote1')), Array(), Array('style'=>'Highlight')),
	$mkBlck('headline', Array($mkSpan('Two, a section')), Array(), Array('level'=>2, 'tagid'=>'9 bad id')),
	$mkBlck('headline', Array(), Array(), Array('level'=>1)),
	$mkBlck('footnote', Array($mkSpan('First note')), Array(), Array('tagid'=>'fnote1', 'numbr'=>1)),
	$mkBlck('footnote', Array($mkSpan('Second note')), Array(), Array('tagid'=>'fnote2', 'numbr'=>2)),
	$mkBlck('footnote', Array($mkSpan('Never referred to')), Array(), Array('tagid'=>'fnote7', 'numbr'=>7))
);
$bookInf = Array('title'=>'The <Book> & more', 'authr'=>'Zoé', 'langg'=>'fr-CH', 'props'=>Array('Subject'=>'A subject', 'Comments'=>'About it', 'Keywords'=>'one, two', 'Publisher'=>'nyuArk', 'Client'=>'ignored'), 'frmat'=>Array('Highlight'=>Array('fonts'=>'Georgia', 'sizes'=>14, 'color'=>'#C00000', 'marks'=>Array('boldFace'))));
$bookTxt = bokWrite($bookLst, $bookInf);
$partLst = bokParts($bookTxt);
checkIdentical('a book starts with what it is, kept as it is', Array(substr($bookTxt, 0, 4), unpack('v', substr($bookTxt, 8, 2))[1], substr($bookTxt, 30, 8), substr($bookTxt, 38, 20)), Array("PK\x03\x04", 0, 'mimetype', 'application/epub+zip'));
checkIdentical('its parts: where the book is, what tells of it, its two tables of contents, its style sheet, its chapters', array_keys($partLst), Array('mimetype', 'META-INF/container.xml', 'OEBPS/content.opf', 'OEBPS/nav.xhtml', 'OEBPS/toc.ncx', 'OEBPS/style.css', 'OEBPS/chap1.xhtml', 'OEBPS/chap2.xhtml', 'OEBPS/chap3.xhtml'));
checkTrueValue('every part an XML reader is to take is one it takes', array_reduce(array_keys($partLst), function($allAreGood, $insideName) use ($partLst){ return $allAreGood && (in_array($insideName, Array('mimetype', 'OEBPS/style.css')) || bokIsXml($partLst[$insideName])); }, true));
checkPregMatch('where the book is', $partLst['META-INF/container.xml'], '/<rootfile full-path="OEBPS\/content\.opf" media-type="application\/oebps-package\+xml"\/>/');
checkPregMatch('what tells of the book: its identifier, its title, its language, its author, and when it was written', $partLst['OEBPS/content.opf'], '/<package xmlns="http:\/\/www\.idpf\.org\/2007\/opf" version="3\.0" unique-identifier="bookid" xml:lang="fr-CH"><metadata xmlns:dc="[^"]*"><dc:identifier id="bookid">urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}<\/dc:identifier><dc:title>The &lt;Book&gt; &amp; more<\/dc:title><dc:language>fr-CH<\/dc:language><dc:creator>Zoé<\/dc:creator><dc:subject>A subject<\/dc:subject><dc:description>About it<\/dc:description><dc:publisher>nyuArk<\/dc:publisher><dc:subject>one<\/dc:subject><dc:subject>two<\/dc:subject><meta property="dcterms:modified">\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ<\/meta><\/metadata>/');
checkPregMatch('the parts of the book, and the order its chapters are read in', $partLst['OEBPS/content.opf'], '/<manifest><item id="nav" href="nav\.xhtml" media-type="application\/xhtml\+xml" properties="nav"\/><item id="ncx" href="toc\.ncx" media-type="application\/x-dtbncx\+xml"\/><item id="css" href="style\.css" media-type="text\/css"\/><item id="chap1" href="chap1\.xhtml" media-type="application\/xhtml\+xml"\/><item id="chap2" [^>]*\/><item id="chap3" [^>]*\/><\/manifest><spine toc="ncx"><itemref idref="chap1"\/><itemref idref="chap2"\/><itemref idref="chap3"\/><\/spine><\/package>$/');
checkIdentical('the same book always bears the same identifier, another book another one, a book told its own bears it', Array(
	preg_match('/<dc:identifier id="bookid">([^<]*)</', bokParts(bokWrite($bookLst, $bookInf))['OEBPS/content.opf'], $idntOne) && preg_match('/<dc:identifier id="bookid">([^<]*)</', $partLst['OEBPS/content.opf'], $idntTwo) && $idntOne[1]===$idntTwo[1],
	preg_match('/<dc:identifier id="bookid">([^<]*)</', bokParts(bokWrite(Array($mkBlck('paragrph', Array($mkSpan('Another')))), $bookInf))['OEBPS/content.opf'], $idntOth) && $idntOth[1]!==$idntTwo[1],
	preg_match('/<dc:identifier id="bookid">([^<]*)</', bokParts(bokWrite($bookLst, Array('props'=>Array('Identifier'=>"urn:isbn:978-2-12-345680-3\n"))))['OEBPS/content.opf'], $idntOwn) ? $idntOwn[1] : ''
), Array(true, true, 'urn:isbn:978-2-12-345680-3'));

// Chapters
checkPregMatch('what stands before the first heading is a chapter, bearing the title of the book', $partLst['OEBPS/chap1.xhtml'], '/^<\?xml version="1\.0" encoding="UTF-8"\?>\n<!DOCTYPE html>\n<html xmlns="http:\/\/www\.w3\.org\/1999\/xhtml" xmlns:epub="http:\/\/www\.idpf\.org\/2007\/ops" lang="fr-CH" xml:lang="fr-CH">\n<head>\n<meta charset="utf-8"\/>\n<title>The &lt;Book&gt; &amp; more<\/title>\n<link rel="stylesheet" type="text\/css" href="style\.css"\/>\n<\/head>\n<body>\n<p>Before any heading\.<\/p>\n<\/body>\n<\/html>\n$/');
checkPregMatch('a chapter starts at a heading of the first level, and bears its title', $partLst['OEBPS/chap2.xhtml'], '/<title>One &amp; &lt;first&gt;<\/title>.*<body>\n<h1 id="one">One &amp; &lt;first&gt;<\/h1>\n<p>Text with a note/s');
checkPregMatch('a link to a place of another chapter tells the page of that chapter; one to this chapter does not; an address that is dropped is text', $partLst['OEBPS/chap2.xhtml'], '/and a link to <a href="chap3\.xhtml#two">the second chapter<\/a>, to <a href="#one">this one<\/a> and nowhere\.<\/p>/');
checkPregMatch('a heading without a name is given one; a box to tick is the sign of one; a picture not written is what describes it', $partLst['OEBPS/chap2.xhtml'], '/<h2 id="sec-1">One, a section<\/h2>\n<h3 id="sec-2">One, deeper<\/h3>\n<ul>\n<li>☑ Done<\/li>\n<li>☐ Open<\/li>\n<\/ul>\n<p>A picture described<\/p>\n/');
checkPregMatch('a note ends the chapter that first refers to it; it and its reference tell what they are, for a reader to open it in place', $partLst['OEBPS/chap2.xhtml'], '/<sup><a epub:type="noteref" href="#fnote1">1<\/a><\/sup>.*<aside epub:type="footnote" id="fnote1"><sup>1<\/sup> First note<\/aside>\n<\/body>/s');
checkPregMatch('a field is the text it showed; a note of another chapter is pointed to there; a name XHTML refuses is replaced; a note never referred to ends the book', $partLst['OEBPS/chap3.xhtml'], '/<h1 id="two">Two<br\/>\nlines<\/h1>\n<p class="highlight">Page 3, a second note<sup><a epub:type="noteref" href="#fnote2">2<\/a><\/sup>, the first again<sup><a epub:type="noteref" href="chap2\.xhtml#fnote1">1<\/a><\/sup><\/p>\n<h2 id="sec-3">Two, a section<\/h2>\n<h1 id="sec-4"><\/h1>\n<aside epub:type="footnote" id="fnote2"><sup>2<\/sup> Second note<\/aside>\n<aside epub:type="footnote" id="fnote7"><sup>7<\/sup> Never referred to<\/aside>\n<\/body>/');
checkIdentical('nothing of a dropped address, and no address of a picture, is written anywhere', Array(strpos(implode('', $partLst), 'javascript'), strpos(implode('', $partLst), 'picture.png'), strpos(implode('', $partLst), '<script'), strpos(implode('', $partLst), '<input')), Array(false, false, false, false));
checkIdentical('a book of one chapter', array_values(preg_grep('#chap\d+\.xhtml$#', array_keys(bokParts(bokWrite($bookLst, $bookInf, Array('split'=>0)))))), Array('OEBPS/chap1.xhtml'));
checkIdentical('chapters started at the headings of the second level too', count(preg_grep('#chap\d+\.xhtml$#', array_keys(bokParts(bokWrite($bookLst, $bookInf, Array('split'=>2)))))), 5);
checkIdentical('a book holding nothing still has a chapter', array_values(preg_grep('#chap\d+\.xhtml$#', array_keys(bokParts(bokWrite(Array()))))), Array('OEBPS/chap1.xhtml'));

// The tables of contents
checkPregMatch('the table of contents readers of today look for: the headings, one list in another', $partLst['OEBPS/nav.xhtml'], '/<nav epub:type="toc" id="toc">\n<h1>The &lt;Book&gt; &amp; more<\/h1>\n<ol>\n<li><a href="chap2\.xhtml#one">One &amp; &lt;first&gt;<\/a>\n<ol>\n<li><a href="chap2\.xhtml#sec-1">One, a section<\/a><\/li>\n<\/ol>\n<\/li>\n<li><a href="chap3\.xhtml#two">Two lines<\/a>\n<ol>\n<li><a href="chap3\.xhtml#sec-3">Two, a section<\/a><\/li>\n<\/ol>\n<\/li>\n<\/ol>\n<\/nav>/');
checkPregMatch('the one older readers look for: points, one in another, in the order of reading', $partLst['OEBPS/toc.ncx'], '/<docTitle><text>The &lt;Book&gt; &amp; more<\/text><\/docTitle><navMap><navPoint id="np1" playOrder="1"><navLabel><text>One &amp; &lt;first&gt;<\/text><\/navLabel><content src="chap2\.xhtml#one"\/><navPoint id="np2" playOrder="2"><navLabel><text>One, a section<\/text><\/navLabel><content src="chap2\.xhtml#sec-1"\/><\/navPoint><\/navPoint><navPoint id="np3" playOrder="3">.*<navPoint id="np4" playOrder="4">.*<\/navPoint><\/navPoint><\/navMap><\/ncx>$/');
checkIdentical('both tell the identifier of the book', preg_match('/<meta name="dtb:uid" content="([^"]*)"/', $partLst['OEBPS/toc.ncx'], $idntNcx) ? $idntNcx[1] : '', $idntTwo[1]);
checkIdentical('more levels of headings, or fewer', Array(substr_count(bokParts(bokWrite($bookLst, $bookInf, Array('depth'=>3)))['OEBPS/nav.xhtml'], '<li>'), substr_count(bokParts(bokWrite($bookLst, $bookInf, Array('depth'=>1)))['OEBPS/nav.xhtml'], '<li>')), Array(5, 2));
checkPregMatch('a book without headings has its chapters for table of contents', bokParts(bokWrite(Array($mkBlck('paragrph', Array($mkSpan('Only text')))), Array('title'=>'Plain')))['OEBPS/nav.xhtml'], '/<ol>\n<li><a href="chap1\.xhtml">Plain<\/a><\/li>\n<\/ol>/');

// The style sheet, the language, sheets
checkPregMatch('the style sheet: what every chapter looks like, then what the styles of the content give', $partLst['OEBPS/style.css'], '/^body\{margin:5%;line-height:1\.4\}\n.*hr\.break\{[^}]*\}\nbody\.cover\{[^}]*\}\nbody\.cover img\{[^}]*\}\n\.highlight\{font-family:"Georgia";font-size:14pt;color:#C00000;font-weight:bold\}\n$/s');
checkIdentical('the language: told of the book, or asked for, or English', Array(
	preg_match('/<dc:language>([^<]*)</', bokParts(bokWrite(Array(), Array(), Array('langg'=>'de')))['OEBPS/content.opf'], $langOne) ? $langOne[1] : '',
	preg_match('/<dc:language>([^<]*)</', bokParts(bokWrite(Array(), Array('langg'=>'it'), Array('langg'=>'de')))['OEBPS/content.opf'], $langTwo) ? $langTwo[1] : '',
	preg_match('/<dc:language>([^<]*)</', bokParts(bokWrite(Array(), Array('langg'=>'"><x'), Array('langg'=>'not a language')))['OEBPS/content.opf'], $langOth) ? $langOth[1] : ''
), Array('de', 'it', 'en'));
checkPregMatch('sheets: a table each, under a heading bearing its name', bokParts(bokWrite(Array(Array('title'=>'Shows', 'attrs'=>Array(), 'cells'=>Array(Array('Show'=>'Alpha', 'Seats'=>120)))), Array(), Array('split'=>2), 'sheet'))['OEBPS/chap1.xhtml'], '/<h2 id="sec-1">Shows<\/h2>\n<table>\n<thead>\n<tr><th>Show<\/th><th>Seats<\/th><\/tr>\n<\/thead>\n<tbody>\n<tr><td>Alpha<\/td><td style="text-align:right">120<\/td><\/tr>/');
checkPregMatch('a shape that is not blocks', bokWrite(Array(), Array(), Array(), 'nodes'), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Shape that cannot be written as a book: nodes/');
checkIdentical('the writer of the epub extension', Array(get_class(Index::forExtnsWriter('EPUB')), Index::contentTypeFor('epub')), Array('nyu\\Data\\Writers\\EPub', 'application/epub+zip'));

// Pictures and the cover: a folder of them, a folder beside it, and a file that is not a picture
$dotPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$dotGif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
$baseDir = inTemporaryDir('epubpict');
dropTestFolder($baseDir);
mkdir($baseDir.'/media', 0777, true);
mkdir($baseDir.'/other', 0777, true);
file_put_contents($baseDir.'/media/dot.png', $dotPng);
file_put_contents($baseDir.'/media/front.gif', $dotGif);
file_put_contents($baseDir.'/media/notes.png', 'This is a text, whatever its name says.');
file_put_contents($baseDir.'/other/outside.png', $dotPng);
$mkPict = function(String $sourceName, String $describedAs) use ($mkSpan, $mkBlck){ return $mkBlck('imageBox', Array($mkSpan($describedAs)), Array(), Array('sourc'=>$sourceName)); };
$pictLst = Array(
	$mkBlck('headline', Array($mkSpan('Pictures')), Array(), Array('level'=>1)), $mkPict('dot.png', 'A "dot" & <more>'), $mkPict('dot.png', 'The same again'),
	$mkPict('https://nyuark.test/picture.png', 'An address'), $mkPict('../other/outside.png', 'Outside the folder'), $mkPict('missing.png', 'Not there'), $mkPict('notes.png', 'Not a picture'),
	$mkBlck('headline', Array($mkSpan('Second chapter')), Array(), Array('level'=>1)), $mkBlck('tableSet', Array(), Array($mkBlck('tableRow', Array(), Array($mkBlck('cellText', Array($mkSpan('in a cell')), Array($mkPict('dot.png', 'In a cell')))))))
);
checkPregMatch('a folder that is not one', bokWrite(Array(), Array(), Array('media'=>$baseDir.'/nowhere')), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Invalid document option: the folder of the pictures cannot be read/');
$partLst = bokParts(bokWrite($pictLst, Array('title'=>'With pictures'), Array('media'=>$baseDir.'/media', 'cover'=>'front.gif')));
checkIdentical('the pictures of the folder are written into the book, each once, with its cover; no other is', Array(array_values(preg_grep('#/images/#', array_keys($partLst))), substr($partLst['OEBPS/images/image2.gif'], 0, 4), substr($partLst['OEBPS/images/image1.png'], 1, 3)), Array(Array('OEBPS/images/image1.png', 'OEBPS/images/image2.gif'), 'GIF8', 'PNG'));
checkPregMatch('a picture is shown from where it is written in the book; one that is not written is what describes it', $partLst['OEBPS/chap1.xhtml'], '/<h1 id="sec-1">Pictures<\/h1>\n<img src="images\/image1\.png" alt="A &quot;dot&quot; &amp; &lt;more&gt;"\/>\n<img src="images\/image1\.png" alt="The same again"\/>\n<p>An address<\/p>\n<p>Outside the folder<\/p>\n<p>Not there<\/p>\n<p>Not a picture<\/p>\n<\/body>/');
checkPregMatch('a picture in a cell of a table, in another chapter', $partLst['OEBPS/chap2.xhtml'], '/<td>in a cell\n<img src="images\/image1\.png" alt="In a cell"\/>\n<\/td>/');
checkPregMatch('the cover is a page of its own, read first', $partLst['OEBPS/cover.xhtml'].'|'.$partLst['OEBPS/content.opf'], '/<title>With pictures<\/title>\n<link rel="stylesheet" type="text\/css" href="style\.css"\/>\n<\/head>\n<body class="cover" epub:type="cover">\n<img src="images\/image2\.gif" alt="With pictures"\/>\n<\/body>.*\|.*<spine toc="ncx"><itemref idref="cover"\/><itemref idref="chap1"\/><itemref idref="chap2"\/><\/spine>/s');
checkPregMatch('the book is told of its pictures, and which one is its cover, in the two ways readers look for it', $partLst['OEBPS/content.opf'], '/<meta name="cover" content="img2"\/><\/metadata><manifest>.*<item id="cover" href="cover\.xhtml" media-type="application\/xhtml\+xml"\/><item id="chap1" [^>]*\/><item id="chap2" [^>]*\/><item id="img1" href="images\/image1\.png" media-type="image\/png"\/><item id="img2" href="images\/image2\.gif" media-type="image\/gif" properties="cover-image"\/><\/manifest>/');
checkTrueValue('the cover and the parts telling of the pictures are what an XML reader takes', bokIsXml($partLst['OEBPS/cover.xhtml']) && bokIsXml($partLst['OEBPS/content.opf']) && bokIsXml($partLst['OEBPS/chap1.xhtml']));
checkIdentical('nothing of an address of a picture, nor of the folders of the server, is written', Array(strpos(implode('', array_diff_key($partLst, Array('OEBPS/images/image2.gif'=>1, 'OEBPS/images/image1.png'=>1))), 'nyuark.test'), strpos(implode('', $partLst), 'epubpict')), Array(false, false));
$partLst = bokParts(bokWrite($pictLst, Array('title'=>'Cover told of the book', 'cover'=>'dot.png'), Array('media'=>$baseDir.'/media')));
checkPregMatch('the cover told of the book is taken when none is asked for; it is the picture already written when it is the same', $partLst['OEBPS/content.opf'], '/<item id="img1" href="images\/image1\.png" media-type="image\/png" properties="cover-image"\/><\/manifest>/');
$partLst = bokParts(bokWrite($pictLst, Array('cover'=>'../other/outside.png'), Array('media'=>$baseDir.'/media', 'cover'=>'https://nyuark.test/cover.png')));
checkIdentical('a cover that is not a picture of the folder is no cover', Array(isset($partLst['OEBPS/cover.xhtml']), strpos($partLst['OEBPS/content.opf'], 'cover')), Array(false, false));
$partLst = bokParts(bokWrite($pictLst, Array('cover'=>'dot.png')));
checkIdentical('without a folder, a book has no picture and no cover', Array(count(preg_grep('#/images/#', array_keys($partLst))), isset($partLst['OEBPS/cover.xhtml']), substr_count($partLst['OEBPS/chap1.xhtml'], '<img')), Array(0, false, 0));

if( !class_exists('ZipArchive') ){	recordSkipping('books written, then read back', 'the zip extension is not loaded');
	dropTestFolder($baseDir);
	finishChecking();
}

// Written, then read back
$backSet = (new EPubRead())->parseFluxArray(Fluxd::openBytesFluxd($bookTxt));
checkIdentical('what tells of the book is read back', Array($backSet['infos']['title'], $backSet['infos']['authr'], $backSet['infos']['langg'], $backSet['infos']['chaps']), Array('The <Book> & more', 'Zoé', 'fr-CH', 3));
// The parser tells where a chapter ends by a page break
checkIdentical('its text is read back, chapter after chapter', array_map(function(Array $blockArray){ return $blockArray['block'].': '.$blockArray['plain']; }, array_slice($backSet['items'], 0, 7)), Array('paragrph: Before any heading.', 'ruleLine: ', 'headline: One & <first>', 'paragrph: Text with a note1 and a link to the second chapter, to this one and nowhere.', 'headline: One, a section', 'headline: One, deeper', 'itemList: '));
checkIdentical('with its headings, their levels and their names', array_values(array_map(function(Array $blockArray){ return $blockArray['attrs']['level'].' '.strval($blockArray['attrs']['tagid'] ?? '').' '.$blockArray['plain']; }, array_filter($backSet['items'], function(Array $blockArray){ return $blockArray['block']=='headline' && $blockArray['plain']!==''; }))), Array('1 one One & <first>', '2 sec-1 One, a section', '3 sec-2 One, deeper', "1 two Two\nlines", '2 sec-3 Two, a section'));
// A book of the samples, read, written, and read again: the same text
$smplSet = (new EPubRead())->parseFluxArray(Fluxd::openBytesFluxd(bokWrite(Array_merge($bookLst, $htmlLst), $bookInf)));
$againSt = (new EPubRead())->parseFluxArray(Fluxd::openBytesFluxd((new EPub())->renderDocument($smplSet)));
checkIdentical('a book read, written and read again keeps its text and its chapters', Array(preg_replace('/\s+/', ' ', Shape::documentAsText($againSt['items']))===preg_replace('/\s+/', ' ', Shape::documentAsText($smplSet['items'])), $againSt['infos']['chaps'], $againSt['infos']['title']), Array(true, $smplSet['infos']['chaps'], 'The <Book> & more'));

// Its pictures and its cover are read back
$pictSet = (new EPubRead())->parseFluxArray(Fluxd::openBytesFluxd(bokWrite($pictLst, Array('title'=>'With pictures'), Array('media'=>$baseDir.'/media', 'cover'=>'front.gif'))));
checkIdentical('the cover, and a picture with what describes it', Array($pictSet['infos']['cover'], array_values(array_map(function(Array $blockArray){ return $blockArray['attrs']['sourc'].' '.$blockArray['plain']; }, array_filter($pictSet['items'], function(Array $blockArray){ return $blockArray['block']=='imageBox'; })))), Array('OEBPS/images/image2.gif', Array('OEBPS/images/image2.gif With pictures', 'OEBPS/images/image1.png A "dot" & <more>', 'OEBPS/images/image1.png The same again')));
// Read into a folder, then written from that folder: the pictures and the cover go round
mkdir($baseDir.'/round', 0777, true);
$readSet = (new EPubRead())->parseFluxArray(Fluxd::openBytesFluxd(bokWrite($pictLst, Array('title'=>'With pictures'), Array('media'=>$baseDir.'/media', 'cover'=>'front.gif'))), Array('media'=>$baseDir.'/round'));
$nextLst = bokParts(strval(runCatchThrown(function() use ($readSet, $baseDir){ return (new EPub())->renderDocument($readSet, Array('media'=>$baseDir.'/round')); })));
checkIdentical('a book read with its pictures saved to a folder is written back with them', Array(count(glob($baseDir.'/round/*')), count(preg_grep('#/images/#', array_keys($nextLst))), substr_count(implode('', array_intersect_key($nextLst, array_flip(preg_grep('#chap\d+\.xhtml$#', array_keys($nextLst))))), '<img')>=3), Array(2, 2, true));
dropTestFolder($baseDir);

finishChecking();
