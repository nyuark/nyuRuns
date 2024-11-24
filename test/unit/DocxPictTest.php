<?php

// Data\Writers\Docx : the pictures of a document, taken from a folder.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Fluxd;
use nyu\Data\Image;
use nyu\Data\Parsers\Docx as DocxRead;
use nyu\Data\Shape;
use nyu\Data\Writers\Docx;

/**
 * Writes blocks as a document, or returns the reason it was refused.
 *
 * @param Array $blocksList The blocks.
 * @param Array $writeOptns Options of the writer.
 * @param Array $infosArray What is told of the document.
 * @return mixed The bytes, or a 'THROWN ...' string.
 */
function pctWrite( Array $blocksList, Array $writeOptns=Array(), Array $infosArray=Array() ): Mixed {
	return runSafely(function() use ($blocksList, $writeOptns, $infosArray){
		return (new Docx())->writeData(Array('shape'=>'block', 'infos'=>$infosArray, 'items'=>$blocksList), $writeOptns);
	});
}

/**
 * Lists the parts of a document, read without the zip extension.
 *
 * @param String $archvBytes The bytes of the archive.
 * @return Array The parts, name => content.
 */
function pctParts( String $archvBytes ): Array {
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

$mkSpan = Array(Shape::class, 'makeASpan');
$mkBlck = Array(Shape::class, 'makeBlock');
$mkPict = function(String $sourceName, String $describedAs='', Array $attrsArray=Array()) use ($mkSpan, $mkBlck){ return $mkBlck('image', ($describedAs==='') ? Array() : Array($mkSpan($describedAs)), Array(), Array('sourc'=>$sourceName) + $attrsArray); };

// A folder of pictures, a folder beside it, and a file that is not a picture
$dotPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$baseDir = inTempDir('docxpict');
dropFoldr($baseDir);
mkdir($baseDir.'/media/sub', 0777, true);
mkdir($baseDir.'/other', 0777, true);
file_put_contents($baseDir.'/media/dot.png', $dotPng);
file_put_contents($baseDir.'/media/sub/deep.png', $dotPng);
file_put_contents($baseDir.'/media/notes.png', 'This is a text, whatever its name says.');
file_put_contents($baseDir.'/other/outside.png', $dotPng);
$mediaDr = $baseDir.'/media';

// What is not written as a picture is written as the text that describes it
$partLst = pctParts(pctWrite(Array($mkPict('dot.png', 'A dot'))));
checkSame('without a folder, a picture is the text that describes it', Array(strpos($partLst['word/document.xml'], '<w:drawing>'), strpos($partLst['word/document.xml'], '<w:p><w:r><w:t xml:space="preserve">A dot</w:t></w:r></w:p>')!==false, count(preg_grep('#^word/media/#', array_keys($partLst)))), Array(false, true, 0));
checkLike('a folder that is not one', pctWrite(Array(), Array('media'=>$baseDir.'/nowhere')), '/^THROWN nyu\\\\Data\\\\Writers\\\\WriteError: Invalid document option: the folder of the pictures cannot be read/');
$partLst = pctParts(pctWrite(Array(
	$mkPict('https://nyuark.test/picture.png', 'An address'), $mkPict('../other/outside.png', 'Outside the folder'), $mkPict($baseDir.'/other/outside.png', 'A full path'), $mkPict('missing.png', 'A file that is not there'),
	$mkPict('notes.png', 'Not a picture'), $mkPict('sub', 'A folder'), $mkPict('', 'No name'), $mkPict("dot.png\0.txt", 'A name cut short'), $mkPict('file:///'.str_replace('\\', '/', $baseDir).'/media/dot.png', 'A file address')
), Array('media'=>$mediaDr)));
checkSame('an address, a file of another folder, a missing file, a file that is not a picture: none is written', Array(strpos($partLst['word/document.xml'], '<w:drawing>'), count(preg_grep('#^word/media/#', array_keys($partLst))), substr_count($partLst['word/document.xml'], '<w:p><w:r><w:t xml:space="preserve">'), strpos($partLst['word/_rels/document.xml.rels'], 'nyuark.test')), Array(false, 0, 9, false));

// A picture of the folder
$partLst = pctParts(pctWrite(Array($mkPict('dot.png', 'A "dot" & <more>'), $mkPict('sub/deep.png', 'The same, further in', Array('align'=>'centr')), $mkPict('dot.png')), Array('media'=>$mediaDr)));
checkSame('a picture is written into the document, once however often it is shown', Array(array_values(preg_grep('#^word/media/#', array_keys($partLst))), substr_count($partLst['word/document.xml'], '<w:drawing>'), substr_count($partLst['word/_rels/document.xml.rels'], '/image"')), Array(Array('word/media/image1.png'), 3, 1));
checkSame('what is written is a picture', substr($partLst['word/media/image1.png'], 0, 8), "\x89PNG\r\n\x1A\n");
checkLike('the document is told what the picture is', $partLst['[Content_Types].xml'], '/<Default Extension="xml" ContentType="application\/xml"\/><Default Extension="png" ContentType="image\/png"\/><Override PartName="\/word\/document\.xml"/');
checkLike('the picture is one of the relations of the document', $partLst['word/_rels/document.xml.rels'], '/<Relationship Id="rIm1" Type="[^"]*\/image" Target="media\/image1\.png"\/><\/Relationships>$/');
checkLike('a picture: its size, what describes it, where its content is', $partLst['word/document.xml'], '/<w:body><w:p><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="9525" cy="9525"\/><wp:docPr id="1" name="Picture 1" descr="A &quot;dot&quot; &amp; &lt;more&gt;"\/>.*?<pic:blipFill><a:blip r:embed="rIm1"\/><a:stretch><a:fillRect\/><\/a:stretch><\/pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"\/><a:ext cx="9525" cy="9525"\/><\/a:xfrm>.*?<\/w:drawing><\/w:r><\/w:p><w:p><w:pPr><w:jc w:val="center"\/><\/w:pPr><w:r><w:drawing>.*?<wp:docPr id="2" name="Picture 2" descr="The same, further in"\/>.*?<wp:docPr id="3" name="Picture 3" descr=""\/>/s');
checkLike('the parts that show pictures declare what a picture is written with', $partLst['word/document.xml'], '/<w:document [^>]*xmlns:wp="[^"]*wordprocessingDrawing" xmlns:a="[^"]*drawingml\/2006\/main" xmlns:pic="[^"]*drawingml\/2006\/picture">/');
checkTrue('the parts are well-formed', @simplexml_load_string($partLst['word/document.xml'])!==false && @simplexml_load_string($partLst['[Content_Types].xml'])!==false && @simplexml_load_string($partLst['word/_rels/document.xml.rels'])!==false);
// A picture at the top of the pages, in a cell, in an entry of a list
$partLst = pctParts(pctWrite(Array(
	$mkBlck('table', Array(), Array($mkBlck('tbrow', Array(), Array($mkBlck('tcell', Array($mkSpan('with a picture')), Array($mkPict('dot.png', 'In a cell'))))))),
	$mkBlck('lists', Array(), Array($mkBlck('entry', Array($mkSpan('An entry')), Array($mkPict('dot.png', 'In an entry')))), Array('order'=>false))
), Array('media'=>$mediaDr), Array('phead'=>Array($mkPict('dot.png', 'At the top')))));
checkLike('each part has the relation of the pictures it shows; the picture is still written once', $partLst['word/_rels/header1.xml.rels'].'|'.$partLst['word/_rels/document.xml.rels'].'|'.implode(',', preg_grep('#^word/media/#', array_keys($partLst))), '/<Relationship Id="rIm1" Type="[^"]*\/image" Target="media\/image1\.png"\/><\/Relationships>\|.*<Relationship Id="rIm1" Type="[^"]*\/image" Target="media\/image1\.png"\/><\/Relationships>\|word\/media\/image1\.png$/s');
checkLike('a picture in a cell, and in an entry of a list, further in', $partLst['word/document.xml'], '/with a picture<\/w:t><\/w:r><\/w:p><w:p><w:r><w:drawing>.*?descr="In a cell".*?<\/w:p><\/w:tc>.*An entry<\/w:t><\/w:r><\/w:p><w:p><w:pPr><w:ind w:left="720"\/><\/w:pPr><w:r><w:drawing>.*?descr="In an entry"/s');

if( !class_exists('ZipArchive') ){
	skipCheck('documents with pictures, read back', 'the zip extension is not loaded');
	dropFoldr($baseDir);
	endChecks();
}

// Read back
$backSet = (new DocxRead())->parseFlux(Fluxd::openBytes(pctWrite(Array($mkBlck('parag', Array($mkSpan('Before'))), $mkPict('dot.png', 'A dot'), $mkBlck('parag', Array($mkSpan('After')))), Array('media'=>$mediaDr))));
checkSame('a picture is read back as an image, with what describes it', array_map(function(Array $blockArray){ return Array($blockArray['block'], $blockArray['plain'], strval($blockArray['attrs']['sourc'] ?? '')); }, $backSet['items']), Array(Array('parag', 'Before', ''), Array('image', 'A dot', 'word/media/image1.png'), Array('parag', 'After', '')));
// Read into a folder, then written from that folder: the pictures go round
mkdir($baseDir.'/round', 0777, true);
$frstTxt = pctWrite(Array($mkPict('dot.png', 'A dot'), $mkPict('sub/deep.png', 'Deep')), Array('media'=>$mediaDr));
$readSet = (new DocxRead())->parseFlux(Fluxd::openBytes($frstTxt), Array('media'=>$baseDir.'/round'));
$nextLst = pctParts(strval(runSafely(function() use ($readSet, $baseDir){ return (new Docx())->writeData($readSet, Array('media'=>$baseDir.'/round')); })));
checkSame('a document read with its pictures saved to a folder is written back with them', Array(count(glob($baseDir.'/round/*')), substr_count($nextLst['word/document.xml'] ?? '', '<w:drawing>'), array_values(preg_grep('#^word/media/#', array_keys($nextLst)))), Array(1, 2, Array('word/media/image1.png')));

if( !function_exists('imagecreatetruecolor') ){
	skipCheck('pictures written anew', 'the gd extension is not loaded');
	dropFoldr($baseDir);
	endChecks();
}

// Pictures made here: a wide one, a high one, kinds a document does not hold
$wideGd = imagecreatetruecolor(3000, 300);
imagefill($wideGd, 0, 0, imagecolorallocate($wideGd, 200, 30, 30));
imagejpeg($wideGd, $baseDir.'/media/wide.jpg', 90);
imagebmp($wideGd, $baseDir.'/media/wide.bmp');
$highGd = imagecreatetruecolor(200, 4000);
imagefill($highGd, 0, 0, imagecolorallocate($highGd, 30, 30, 200));
imagegif($highGd, $baseDir.'/media/high.gif');
$partLst = pctParts(pctWrite(Array($mkPict('wide.jpg', 'Wide'), $mkPict('high.gif', 'High'), $mkPict('wide.bmp', 'A kind a document does not hold')), Array('media'=>$mediaDr)));
checkSame('each kind is written under its name, a kind a document does not hold as PNG', Array(array_values(preg_grep('#^word/media/#', array_keys($partLst))), substr($partLst['word/media/image1.jpeg'], 0, 3)==="\xFF\xD8\xFF", substr($partLst['word/media/image2.gif'], 0, 4), substr($partLst['word/media/image3.png'], 1, 3)), Array(Array('word/media/image1.jpeg', 'word/media/image2.gif', 'word/media/image3.png'), true, 'GIF8', 'PNG'));
checkLike('the document is told of each kind', $partLst['[Content_Types].xml'], '/<Default Extension="png" ContentType="image\/png"\/><Default Extension="gif" ContentType="image\/gif"\/><Default Extension="jpeg" ContentType="image\/jpeg"\/><Override/');
// The text of an A4 page is 9026 twentieths of a point wide, 5731510 of Word's units; and leaves the pictures 7948930 units of height
checkLike('a picture wider than the text is brought down to it, its proportions kept; one too high likewise', $partLst['word/document.xml'], '/<wp:extent cx="5731510" cy="573151"\/><wp:docPr id="1" name="Picture 1" descr="Wide"\/>.*<wp:extent cx="397447" cy="7948930"\/><wp:docPr id="2" name="Picture 2" descr="High"\/>/s');
checkLike('a page lying on its long side leaves more room', pctParts(pctWrite(Array($mkPict('wide.jpg', 'Wide')), Array('media'=>$mediaDr, 'lands'=>true)))['word/document.xml'], '/<wp:extent cx="8863330" cy="886333"\/>/');
// In a table of two columns, a cell leaves a picture half the width of the text, less its margins
$cellDoc = pctParts(pctWrite(Array($mkBlck('table', Array(), Array($mkBlck('tbrow', Array(), Array($mkBlck('tcell', Array($mkSpan('beside')), Array()), $mkBlck('tcell', Array(), Array($mkPict('wide.jpg', 'Wide, in a cell')))))))), Array('media'=>$mediaDr)))['word/document.xml'];
checkLike('a picture in a cell is no wider than the cell, and its row is not cut by the end of a page', $cellDoc, '/<w:tr><w:trPr><w:cantSplit\/><\/w:trPr>.*<wp:extent cx="2721755" cy="272176"\/><wp:docPr id="1" name="Picture 1" descr="Wide, in a cell"\/>/s');
// A picture carrying what tells of who took it: written anew, it carries nothing
$jpegTxt = strval(file_get_contents($baseDir.'/media/wide.jpg'));
$exifTxt = "Exif\0\0II*\0\x08\0\0\0\x01\0\x0F\x01\x02\0\x0C\0\0\0\x1A\0\0\0\0\0\0\0SecretMaker\0";
file_put_contents($baseDir.'/media/carries.jpg', substr($jpegTxt, 0, 2)."\xFF\xE1".pack('n', strlen($exifTxt)+2).$exifTxt.substr($jpegTxt, 2));
$partLst = pctParts(pctWrite(Array($mkPict('carries.jpg', 'Carries something')), Array('media'=>$mediaDr)));
checkSame('what a picture carried does not come into the document', Array(strpos(strval(file_get_contents($baseDir.'/media/carries.jpg')), 'SecretMaker')!==false, strpos($partLst['word/media/image1.jpeg'], 'SecretMaker'), Image::openFluxd(Fluxd::openBytes($partLst['word/media/image1.jpeg']))->imageInfo()['width']), Array(true, false, 3000));

dropFoldr($baseDir);
endChecks();
