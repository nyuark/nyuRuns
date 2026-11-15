<?php

// Data\Writers\Odtx : what stands at the top and the bottom of the pages,
// fields, and the pictures of a document, taken from a folder.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Fluxd;
use nyu\Data\Parsers\Odtx as OdtxRead;
use nyu\Data\Shape;
use nyu\Data\Writers\Odtx;

/**
 * Writes blocks as a document, or returns the reason it was refused.
 *
 * @param Array $blocksList The blocks.
 * @param Array $infosArray What is told of the document.
 * @param Array $writeOptns Options of the writer.
 * @return mixed The bytes, or a 'THROWN ...' string.
 */
function odmWrite( Array $blocksList, Array $infosArray=Array(), Array $writeOptns=Array() ): Mixed {
	return runCatchThrown(function() use ($blocksList, $infosArray, $writeOptns){
		return (new Odtx())->renderDocument(Array('shape'=>'block', 'infos'=>$infosArray, 'items'=>$blocksList), $writeOptns);
	});
}

/**
 * Lists the parts of a document, read without the zip extension.
 *
 * @param String $archvBytes The bytes of the archive.
 * @return Array The parts, name => content.
 */
function odmParts( String $archvBytes ): Array {
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
 * Shows blocks in short: their kind, how they are aligned, their text
 * with its formatting, its links and its fields, and the blocks they hold.
 *
 * @param Array $blocksList The blocks.
 * @return Array A line for each block.
 */
function odmShow( Array $blocksList ): Array {
	$showLst = Array();
	foreach($blocksList as $blkArr){
		$spanTxt = '';
		foreach($blkArr['spans'] as $oneSpn){
			$spanTxt .= '{'.$oneSpn['texts'].(empty($oneSpn['marks']) ? '' : '|'.implode(',', $oneSpn['marks'])).(($oneSpn['links']==='') ? '' : '|'.$oneSpn['links']).(($oneSpn['field']==='') ? '' : '|'.$oneSpn['field']).'}';
		}
		$showLst[] = $blkArr['block'].(isset($blkArr['attrs']['align']) ? ' align='.$blkArr['attrs']['align'] : '').(isset($blkArr['attrs']['sourc']) ? ' sourc='.$blkArr['attrs']['sourc'] : '').(($spanTxt==='') ? '' : ' '.$spanTxt);
		foreach(odmShow($blkArr['items']) as $subLne){
			$showLst[] = '  '.$subLne;
		}
	}
	return $showLst;
}

$mkSpan = Array(Shape::class, 'makeSpanOfText');
$mkBlck = Array(Shape::class, 'makeBlockArray');
$mkPict = function(String $sourceName, String $describedAs='', Array $attrsArray=Array()) use ($mkSpan, $mkBlck){ return $mkBlck('imageBox', ($describedAs==='') ? Array() : Array($mkSpan($describedAs)), Array(), Array('sourc'=>$sourceName) + $attrsArray); };

// The top and the bottom of the pages, and fields
$pageInf = Array(
	'phead'=>Array($mkBlck('paragrph', Array($mkSpan('Top '), $mkSpan('old title', Array('boldFace'), '', 'props:Title'), $mkSpan(' '), $mkSpan('site', Array(), 'https://nyuark.test/top'), $mkSpan('1', Array(), '#fnote1')), Array(), Array('align'=>'alignRgt'))),
	'pfoot'=>Array(
		$mkBlck('paragrph', Array($mkSpan('Page '), $mkSpan('3', Array(), '', 'pageNumb'), $mkSpan(' of '), $mkSpan('', Array(), '', 'pageTotl'), $mkSpan(' '), $mkSpan('x', Array(), '', 'props:My Client'), $mkSpan(' '), $mkSpan('2026', Array(), '', 'dateTime'), $mkSpan(' '), $mkSpan('kept', Array(), '', 'other')), Array(), Array('align'=>'alignCtr')),
		$mkBlck('itemList', Array(), Array($mkBlck('listItem', Array($mkSpan('in the footer')))), Array('order'=>true))
	)
);
$partLst = odmParts(odmWrite(Array($mkBlck('paragrph', Array($mkSpan('Body '), $mkSpan('bold', Array('boldFace')), $mkSpan('1', Array(), '#fnote1')), Array(), Array('align'=>'alignCtr')), $mkBlck('footnote', Array($mkSpan('Note')), Array(), Array('tagid'=>'fnote1'))), $pageInf));
checkTrueValue('every part is well-formed', @simplexml_load_string($partLst['content.xml'])!==false && @simplexml_load_string($partLst['styles.xml'])!==false && @simplexml_load_string($partLst['META-INF/manifest.xml'])!==false);
checkPregMatch('the page tells what stands at its top and at its bottom; a note is not referred to from there', $partLst['styles.xml'], '/<office:master-styles><style:master-page style:name="Standard" style:page-layout-name="pm1"><style:header><text:p text:style-name="MP1">Top<text:s\/><text:span text:style-name="MT1"><text:title>old title<\/text:title><\/text:span><text:s\/><text:a xlink:type="simple" xlink:href="https:\/\/nyuark\.test\/top" [^>]*>site<\/text:a>1<\/text:p><\/style:header><style:footer><text:p text:style-name="MP2">Page<text:s\/>/');
checkPregMatch('fields: what each asks the document for, around what it last showed; a field unknown is its text', $partLst['styles.xml'], '/<text:page-number text:select-page="current">3<\/text:page-number><text:s\/>of<text:s\/><text:page-count>1<\/text:page-count><text:s\/><text:user-defined text:name="My Client">x<\/text:user-defined><text:s\/><text:date>2026<\/text:date><text:s\/>kept<\/text:p><text:list text:style-name="LN">/');
checkPregMatch('the styles made by hand around the pages are kept with them, under names of their own, with those their lists need', $partLst['styles.xml'], '/<office:automatic-styles><style:page-layout style:name="pm1"><style:page-layout-properties [^>]*\/><style:header-style><style:header-footer-properties fo:min-height="0\.6cm" fo:margin-bottom="0\.5cm"\/><\/style:header-style><style:footer-style><style:header-footer-properties fo:min-height="0\.6cm" fo:margin-top="0\.5cm"\/><\/style:footer-style><\/style:page-layout><style:style style:name="MP1" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:text-align="end"\/><\/style:style><style:style style:name="MP2" [^>]*><style:paragraph-properties fo:text-align="center"\/><\/style:style><style:style style:name="MT1" style:family="text">.*<text:list-style style:name="LN">/s');
checkPregMatch('those of the text are kept with the text', $partLst['content.xml'], '/<office:automatic-styles><style:style style:name="P1" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:text-align="center"\/><\/style:style><style:style style:name="T1" style:family="text">.*<office:text><text:p text:style-name="P1">Body<text:s\/><text:span text:style-name="T1">bold<\/text:span><text:note /s');
checkPregMatch('a document with nothing around its pages says nothing of it', odmParts(odmWrite(Array($mkBlck('paragrph', Array($mkSpan('x'))))))['styles.xml'], '/<office:automatic-styles><style:page-layout style:name="pm1"><style:page-layout-properties [^>]*\/><\/style:page-layout><\/office:automatic-styles><office:master-styles><style:master-page style:name="Standard" style:page-layout-name="pm1"><\/style:master-page><\/office:master-styles>/');

// Pictures: a folder of them, a folder beside it, and a file that is not a picture
$dotPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$baseDir = inTemporaryDir('odtxpict');
dropTestFolder($baseDir);
mkdir($baseDir.'/media', 0777, true);
mkdir($baseDir.'/other', 0777, true);
file_put_contents($baseDir.'/media/dot.png', $dotPng);
file_put_contents($baseDir.'/media/notes.png', 'This is a text, whatever its name says.');
file_put_contents($baseDir.'/other/outside.png', $dotPng);
$mediaDr = $baseDir.'/media';
$partLst = odmParts(odmWrite(Array($mkPict('dot.png', 'A dot'))));
checkIdentical('without a folder, a picture is the text that describes it', Array(strpos($partLst['content.xml'], '<draw:frame'), strpos($partLst['content.xml'], '<text:p text:style-name="Standard">A dot</text:p>')!==false, count(preg_grep('#^Pictures/#', array_keys($partLst)))), Array(false, true, 0));
checkPregMatch('a folder that is not one', odmWrite(Array(), Array(), Array('media'=>$baseDir.'/nowhere')), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Invalid document option: the folder of the pictures cannot be read/');
$partLst = odmParts(odmWrite(Array($mkPict('https://nyuark.test/picture.png', 'An address'), $mkPict('../other/outside.png', 'Outside the folder'), $mkPict('missing.png', 'Not there'), $mkPict('notes.png', 'Not a picture')), Array(), Array('media'=>$mediaDr)));
checkIdentical('an address, a file of another folder, a missing file, a file that is not a picture: none is written', Array(strpos($partLst['content.xml'], '<draw:frame'), count(preg_grep('#^Pictures/#', array_keys($partLst))), substr_count($partLst['content.xml'], '<text:p text:style-name="Standard">'), strpos($partLst['content.xml'].$partLst['META-INF/manifest.xml'], 'nyuark.test')), Array(false, 0, 4, false));
$partLst = odmParts(odmWrite(Array(
	$mkPict('dot.png', 'A "dot" & <more>'), $mkPict('dot.png', '', Array('align'=>'alignCtr')),
	$mkBlck('tableSet', Array(), Array($mkBlck('tableRow', Array(), Array($mkBlck('cellText', Array($mkSpan('with a picture')), Array($mkPict('dot.png', 'In a cell')))))))
), Array('phead'=>Array($mkPict('dot.png', 'At the top'))), Array('media'=>$mediaDr)));
checkIdentical('a picture is written into the document, once however often it is shown', Array(array_values(preg_grep('#^Pictures/#', array_keys($partLst))), substr_count($partLst['content.xml'], '<draw:frame'), substr_count($partLst['styles.xml'], '<draw:frame'), substr($partLst['Pictures/image1.png'], 0, 8)), Array(Array('Pictures/image1.png'), 3, 1, "\x89PNG\r\n\x1A\n"));
checkPregMatch('the document is told what the picture is', $partLst['META-INF/manifest.xml'], '/<manifest:file-entry manifest:full-path="meta\.xml" manifest:media-type="text\/xml"\/><manifest:file-entry manifest:full-path="Pictures\/image1\.png" manifest:media-type="image\/png"\/><\/manifest:manifest>$/');
checkPregMatch('a picture stands in a paragraph: its size, where its content is, what describes it', $partLst['content.xml'], '/<office:text><text:p text:style-name="Standard"><draw:frame draw:style-name="fr1" draw:name="Picture 1" text:anchor-type="as-char" svg:width="0\.026cm" svg:height="0\.026cm" draw:z-index="0"><draw:image xlink:href="Pictures\/image1\.png" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"\/><svg:desc>A &quot;dot&quot; &amp; &lt;more&gt;<\/svg:desc><\/draw:frame><\/text:p><text:p text:style-name="P1"><draw:frame draw:style-name="fr1" draw:name="Picture 2" [^>]*><draw:image [^>]*\/><\/draw:frame><\/text:p><table:table /');
checkPregMatch('a picture in a cell, after the text of the cell; one at the top of the pages, with the style pictures need', $partLst['content.xml'].'|'.$partLst['styles.xml'], '/with a picture<\/text:p><text:p text:style-name="Standard"><draw:frame [^>]*>.*<svg:desc>In a cell<\/svg:desc><\/draw:frame><\/text:p><\/table:table-cell>.*\|.*<style:style style:name="Graphics" style:family="graphic">.*<style:style style:name="fr1" style:family="graphic" style:parent-style-name="Graphics">.*<style:header><text:p text:style-name="Standard"><draw:frame [^>]*>.*<svg:desc>At the top<\/svg:desc>/s');
checkTrueValue('the parts are well-formed', @simplexml_load_string($partLst['content.xml'])!==false && @simplexml_load_string($partLst['styles.xml'])!==false && @simplexml_load_string($partLst['META-INF/manifest.xml'])!==false);

if( !class_exists('ZipArchive') ){
	recordSkipping('documents written, then read back', 'the zip extension is not loaded');
	dropTestFolder($baseDir);
	finishChecking();
}

// Read back
$backSet = (new OdtxRead())->parseFluxArray(Fluxd::openBytesFluxd(odmWrite(Array($mkBlck('paragrph', Array($mkSpan('Body')))), $pageInf)));
// The document read back here has no note: a reference to one is then a link to a place, as any other
checkIdentical('the top and the bottom of the pages, with their fields', Array(odmShow($backSet['infos']['phead']), odmShow($backSet['infos']['pfoot'])), Array(
	Array('paragrph align=alignRgt {Top }{old title|boldFace|props:Title}{ }{site|https://nyuark.test/top}{1|#fnote1}'),
	Array('paragrph align=alignCtr {Page }{3|pageNumb}{ of }{1|pageTotl}{ }{x|props:My Client}{ }{2026|dateTime}{ kept}', 'itemList', '  listItem {in the footer}')
));
$twiceSt = (new OdtxRead())->parseFluxArray(Fluxd::openBytesFluxd((new Odtx())->renderDocument($backSet)));
checkIdentical('written and read a second time, they are the same', Array(odmShow($twiceSt['infos']['phead']), odmShow($twiceSt['infos']['pfoot'])), Array(odmShow($backSet['infos']['phead']), odmShow($backSet['infos']['pfoot'])));
$backSet = (new OdtxRead())->parseFluxArray(Fluxd::openBytesFluxd(odmWrite(Array($mkBlck('paragrph', Array($mkSpan('Before'))), $mkPict('dot.png', 'A dot'), $mkBlck('paragrph', Array($mkSpan('After')))), Array(), Array('media'=>$mediaDr))));
checkIdentical('a picture is read back as an image, with what describes it', odmShow($backSet['items']), Array('paragrph {Before}', 'imageBox sourc=Pictures/image1.png {A dot}', 'paragrph {After}'));
// Read into a folder, then written from that folder: the pictures go round
mkdir($baseDir.'/round', 0777, true);
$readSet = (new OdtxRead())->parseFluxArray(Fluxd::openBytesFluxd(odmWrite(Array($mkPict('dot.png', 'A dot'), $mkPict('dot.png', 'Again')), Array(), Array('media'=>$mediaDr))), Array('media'=>$baseDir.'/round'));
$nextLst = odmParts(strval(runCatchThrown(function() use ($readSet, $baseDir){ return (new Odtx())->renderDocument($readSet, Array('media'=>$baseDir.'/round')); })));
checkIdentical('a document read with its pictures saved to a folder is written back with them', Array(count(glob($baseDir.'/round/*')), substr_count($nextLst['content.xml'] ?? '', '<draw:frame'), array_values(preg_grep('#^Pictures/#', array_keys($nextLst)))), Array(1, 2, Array('Pictures/image1.png')));

if( !function_exists('imagecreatetruecolor') ){
	recordSkipping('pictures written anew', 'the gd extension is not loaded');
	dropTestFolder($baseDir);
	finishChecking();
}

// Pictures made here: a wide one, kinds a document does not hold
$wideGd = imagecreatetruecolor(3000, 300);
imagefill($wideGd, 0, 0, imagecolorallocate($wideGd, 200, 30, 30));
imagejpeg($wideGd, $baseDir.'/media/wide.jpg', 90);
imagebmp($wideGd, $baseDir.'/media/wide.bmp');
imagegif($wideGd, $baseDir.'/media/wide.gif');
$partLst = odmParts(odmWrite(Array(
	$mkPict('wide.jpg', 'Wide'), $mkPict('wide.gif', 'A GIF'), $mkPict('wide.bmp', 'A kind a document does not hold'),
	$mkBlck('tableSet', Array(), Array($mkBlck('tableRow', Array(), Array($mkBlck('cellText', Array($mkSpan('beside'))), $mkBlck('cellText', Array(), Array($mkPict('wide.jpg', 'Wide, in a cell')))))))
), Array(), Array('media'=>$mediaDr)));
checkIdentical('each kind is written under its name, a kind a document does not hold as PNG', Array(array_values(preg_grep('#^Pictures/#', array_keys($partLst))), substr($partLst['Pictures/image1.jpg'], 0, 3)==="\xFF\xD8\xFF", substr($partLst['Pictures/image3.png'], 1, 3)), Array(Array('Pictures/image1.jpg', 'Pictures/image2.gif', 'Pictures/image3.png'), true, 'PNG'));
checkPregMatch('the document is told of each kind', $partLst['META-INF/manifest.xml'], '/full-path="Pictures\/image1\.jpg" manifest:media-type="image\/jpeg"\/><manifest:file-entry manifest:full-path="Pictures\/image2\.gif" manifest:media-type="image\/gif"\/><manifest:file-entry manifest:full-path="Pictures\/image3\.png" manifest:media-type="image\/png"\/>/');
// The text of an A4 page is 15.921 cm wide; a cell of a table of two columns leaves half of it, less its margins
checkPregMatch('a picture wider than the text is brought down to it, its proportions kept; in a cell, to the cell', $partLst['content.xml'], '/<draw:frame draw:style-name="fr1" draw:name="Picture 1" text:anchor-type="as-char" svg:width="15\.921cm" svg:height="1\.592cm" draw:z-index="0">.*<draw:frame draw:style-name="fr1" draw:name="Picture 4" text:anchor-type="as-char" svg:width="7\.560cm" svg:height="0\.756cm" draw:z-index="0">/s');

dropTestFolder($baseDir);
finishChecking();
