<?php

// The 'media' option of the parsers of zipped documents: the pictures a
// document holds, saved to a folder.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Fluxd;
use nyu\Data\Image;
use nyu\Data\Parsers\Docx;
use nyu\Data\Parsers\EPub;
use nyu\Data\Parsers\Odtx;
use nyu\Data\Parsers\Xlsx;

if( !class_exists('ZipArchive') ){
	recordSkipping('pictures of documents', 'the zip extension is not loaded');
	finishChecking();
}

// A picture of one pixel, as PNG, and what gd writes of it when this server has gd
$dotPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$withGd = function_exists('imagecreatetruecolor');

/**
 * Builds an archive in the temporary folder.
 *
 * @param String $archivName Name of the file to make.
 * @param Array  $entryTexts Its entries, name => content.
 * @return String The path of the archive.
 */
function makeZip( String $archivName, Array $entryTexts ): String {
	$zipPath = inTemporaryDir($archivName);
	$zipArch = new ZipArchive();
	$zipArch->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	foreach( $entryTexts as $entNme=>$entTxt ){
		$zipArch->addFromString(strval($entNme), $entTxt);
	}
	$zipArch->close();
	return $zipPath;
}

/**
 * Makes an empty folder in the temporary folder.
 *
 * @param String $folderName Its name.
 * @return String Its path.
 */
function makeDir( String $folderName ): String {
	$dirPath = inTemporaryDir($folderName);
	dropTestFolder($dirPath);
	mkdir($dirPath, 0775, true);
	return $dirPath;
}

/**
 * Lists the image blocks of some blocks, at any depth.
 *
 * @param Array $blocksList The blocks.
 * @return Array The address of each image, in order.
 */
function srcList( Array $blocksList ): Array {
	$srceLst = Array();
	foreach( $blocksList as $oneBlk ){
		if( $oneBlk['block']=='imageBox' ){
			$srceLst[] = $oneBlk['attrs']['sourc'];
		}
		$srceLst = array_merge($srceLst, srcList($oneBlk['items']));
	}
	return $srceLst;
}

const WORDMAIN = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';
const WORDRELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
$wDraw = function( String $relIdent ){ return '<w:p><w:r><w:drawing><wp:inline><wp:docPr id="1" name="P" descr="Pic"/><a:graphic><a:graphicData><a:blip r:embed="'.$relIdent.'"/></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>'; };
$relOf = function( String $relIdent, String $relTarget, String $relKinds='image', String $relModes='' ){ return '<Relationship Id="'.$relIdent.'" Type="'.WORDRELS.'/'.$relKinds.'" Target="'.$relTarget.'"'.(($relModes!=='') ? ' TargetMode="'.$relModes.'"' : '').'/>'; };

// A second picture: a GIF of one pixel, which its document names as if it were a PNG
$twoPng = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xFF\x00\x00\x00\x00\xFF,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
$docxPth = makeZip('pics.docx', Array(
	'word/document.xml'=>'<w:document '.WORDMAIN.'><w:body><w:p><w:r><w:t>Before</w:t></w:r></w:p>'.$wDraw('rIdA').$wDraw('rIdB').$wDraw('rIdA').$wDraw('rIdFar').$wDraw('rIdEmf').$wDraw('rIdLost').'<w:tbl><w:tr><w:tc>'.$wDraw('rIdB').'</w:tc></w:tr></w:tbl><w:sectPr/></w:body></w:document>',
	'word/_rels/document.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relOf('rIdA', 'media/image1.png').$relOf('rIdB', 'media/image2.png').$relOf('rIdFar', 'https://nyuark.test/far.png', 'imageBox', 'External').$relOf('rIdEmf', 'media/drawing.emf').$relOf('rIdLost', 'media/nowhere.png').$relOf('rIdH', 'header1.xml', 'header').'</Relationships>',
	'word/header1.xml'=>'<w:hdr '.WORDMAIN.'>'.$wDraw('rIdLogo').'</w:hdr>',
	'word/_rels/header1.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relOf('rIdLogo', 'media/image1.png').'</Relationships>',
	'word/media/image1.png'=>$dotPng, 'word/media/image2.png'=>$twoPng, 'word/media/drawing.emf'=>"\x01\x00\x00\x00not a picture this server reads"
));

// Without the option, nothing is saved
checkIdentical('without the option, the images give their place in the archive, and the details list nothing', runCatchThrown(function() use ($docxPth){
	$envlope = (new Docx())->parseFluxArray(Fluxd::openLocalFluxd($docxPth), Array('empty'=>false));
	return Array(srcList($envlope['items']), isset($envlope['infos']['media']));
}), Array(Array('word/media/image1.png', 'word/media/image2.png', 'word/media/image1.png', 'https://nyuark.test/far.png', 'word/media/drawing.emf', 'word/media/nowhere.png', 'word/media/image2.png'), false));

// With it
$mdiaDir = makeDir('media');
$docxEnv = runCatchThrown(function() use ($docxPth, $mdiaDir){ return (new Docx())->parseFluxArray(Fluxd::openLocalFluxd($docxPth), Array('empty'=>false, 'media'=>$mdiaDir)); });
checkIdentical('media: each picture is saved once, under a name made from its content and of the kind it really is; the blocks showing it give that name; what is kept elsewhere, is not a picture, or is missing, is left as it is', runCatchThrown(function() use ($docxEnv, $mdiaDir){
	$srceLst = srcList($docxEnv['items']);
	$fileLst = array_values(array_diff(scandir($mdiaDir), Array('.', '..')));
	sort($fileLst);
	$usedLst = array_values(array_unique(Array($srceLst[0], $srceLst[1])));
	sort($usedLst);
	return Array(count($srceLst), $srceLst[0]===$srceLst[2], $srceLst[1]===$srceLst[6], preg_match('/^[0-9a-f]{20}\.png$/D', $srceLst[0]), preg_match('/^[0-9a-f]{20}\.gif$/D', $srceLst[1]), $srceLst[3], $srceLst[4], $srceLst[5], $fileLst===$usedLst);
}), Array(7, true, true, 1, 1, 'https://nyuark.test/far.png', 'word/media/drawing.emf', 'word/media/nowhere.png', true));
checkIdentical('media: the details list what was saved, the name of each file with its place in the archive; a picture of a header is saved too', runCatchThrown(function() use ($docxEnv){
	$srceLst = srcList($docxEnv['items']);
	return Array($docxEnv['infos']['media']===Array($srceLst[0]=>'word/media/image1.png', $srceLst[1]=>'word/media/image2.png'), srcList($docxEnv['infos']['phead'])===Array($srceLst[0]));
}), Array(true, true));
checkIdentical('media: what is saved is a picture of the kind its name tells', runCatchThrown(function() use ($docxEnv, $mdiaDir){
	$kindLst = Array();
	foreach( array_keys($docxEnv['infos']['media']) as $fleNme ){
		$infoArr = Image::openFluxdImage(Fluxd::openLocalFluxd($mdiaDir.DS.$fleNme))->imageInfoArray();
		$kindLst[] = $infoArr['kinds'].' '.$infoArr['width'].'x'.$infoArr['heght'];
	}
	sort($kindLst);
	return $kindLst;
}), Array('gif 1x1', 'png 1x1'));
checkIdentical('media: reading the same document again saves nothing new', runCatchThrown(function() use ($docxPth, $mdiaDir){
	$fileNbr = count(scandir($mdiaDir));
	(new Docx())->parseFluxArray(Fluxd::openLocalFluxd($docxPth), Array('media'=>$mdiaDir));
	return count(scandir($mdiaDir))===$fileNbr;
}), true);

// A picture taken by a camera
if( $withGd && function_exists('exif_read_data') ){
	checkIdentical('media: a photograph is saved standing the right way up, without what the camera recorded', runCatchThrown(function() use ($wDraw, $relOf){
		// A picture twice as wide as high, recorded as held on its side, with the name of a camera in it
		$gdImage = imagecreatetruecolor(40, 20);
		ob_start();
		imagejpeg($gdImage);
		$jpgText = strval(ob_get_clean());
		$tiffTxt = "MM\0*".pack('N', 8).pack('n', 2).pack('nnNN', 0x010F, 2, 12, 38).pack('nnNnn', 0x0112, 3, 1, 6, 0).pack('N', 0)."Test Camera\0";
		$exifSeg = "Exif\0\0".$tiffTxt;
		$photoJp = substr($jpgText, 0, 2)."\xFF\xE1".pack('n', strlen($exifSeg)+2).$exifSeg.substr($jpgText, 2);
		$docxPth = makeZip('photo.docx', Array(
			'word/document.xml'=>'<w:document '.WORDMAIN.'><w:body>'.$wDraw('rIdP').'</w:body></w:document>',
			'word/_rels/document.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relOf('rIdP', 'media/photo.jpeg').'</Relationships>',
			'word/media/photo.jpeg'=>$photoJp
		));
		$mdiaDir = makeDir('photos');
		$envlope = (new Docx())->parseFluxArray(Fluxd::openLocalFluxd($docxPth), Array('media'=>$mdiaDir));
		$fileNme = srcList($envlope['items'])[0];
		$infoArr = Image::openFluxdImage(Fluxd::openLocalFluxd($mdiaDir.DS.$fileNme))->imageInfoArray();
		return Array(substr($fileNme, -4), $infoArr['width'].'x'.$infoArr['heght'], $infoArr['turns'], $infoArr['exifs'], strpos(file_get_contents($mdiaDir.DS.$fileNme), 'Test Camera'), strpos($photoJp, 'Test Camera')!==false);
	}), Array('.jpg', '20x40', 1, Array(), false, true));
}else{
	recordSkipping('a photograph saved upright', 'the gd and exif extensions are not both loaded');
}

// The other kinds of document
checkIdentical('media: the pictures of an OpenDocument text', runCatchThrown(function() use ($dotPng){
	$odfSpce = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:xlink="http://www.w3.org/1999/xlink"';
	$odtPath = makeZip('pics.odt', Array('mimetype'=>'application/vnd.oasis.opendocument.text', 'content.xml'=>'<office:document-content '.$odfSpce.'><office:body><office:text><text:p>Before <draw:frame><draw:image xlink:href="Pictures/hall.png"/></draw:frame> after</text:p></office:text></office:body></office:document-content>', 'Pictures/hall.png'=>$dotPng));
	$mdiaDir = makeDir('odt');
	$envlope = (new Odtx())->parseFluxArray(Fluxd::openLocalFluxd($odtPath), Array('media'=>$mdiaDir));
	$srceLst = srcList($envlope['items']);
	return Array(preg_match('/^[0-9a-f]{20}\.png$/D', $srceLst[0]), is_file($mdiaDir.DS.$srceLst[0]), $envlope['infos']['media']===Array($srceLst[0]=>'Pictures/hall.png'));
}), Array(1, true, true));
checkIdentical('media: the pictures of a book, from whichever chapter shows them', runCatchThrown(function() use ($dotPng){
	$bookPth = makeZip('pics.epub', Array(
		'mimetype'=>'application/epub+zip',
		'META-INF/container.xml'=>'<container><rootfiles><rootfile full-path="OEBPS/content.opf"/></rootfiles></container>',
		'OEBPS/content.opf'=>'<package xmlns="http://www.idpf.org/2007/opf"><metadata/><manifest><item id="c1" href="text/one.xhtml" media-type="application/xhtml+xml"/><item id="c2" href="two.xhtml" media-type="application/xhtml+xml"/></manifest><spine><itemref idref="c1"/><itemref idref="c2"/></spine></package>',
		'OEBPS/text/one.xhtml'=>'<html><body><p>One <img src="../images/hall.png" alt="Hall"/></p></body></html>',
		'OEBPS/two.xhtml'=>'<html><body><ul><li>Two <img src="images/hall.png"/></li></ul><img src="https://nyuark.test/far.png"/></body></html>',
		'OEBPS/images/hall.png'=>$dotPng
	));
	$mdiaDir = makeDir('book');
	$envlope = (new EPub())->parseFluxArray(Fluxd::openLocalFluxd($bookPth), Array('media'=>$mdiaDir));
	$srceLst = srcList($envlope['items']);
	return Array(count($srceLst), $srceLst[0]===$srceLst[1], $srceLst[2], count(scandir($mdiaDir))-2, array_values($envlope['infos']['media']));
}), Array(3, true, 'https://nyuark.test/far.png', 1, Array('OEBPS/images/hall.png')));
checkIdentical('media: a workbook has no image blocks, and is read as it is', runCatchThrown(function(){
	$envlope = (new Xlsx())->parseFluxArray(Fluxd::openLocalFluxd(TST_DIR.DS.'fixtures'.DS.'office'.DS.'SampleSS.xlsx'), Array('media'=>makeDir('book2')));
	return Array($envlope['shape'], isset($envlope['infos']['media']));
}), Array('sheet', false));

// What is refused, and what is guarded
checkPregMatch('media: a folder that does not exist is refused', runCatchThrown(function() use ($docxPth){ return (new Docx())->parseFluxArray(Fluxd::openLocalFluxd($docxPth), Array('media'=>inTemporaryDir('nowhere'.DS.'deep'))); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid option: the folder for the pictures cannot be written to/');
checkPregMatch('media: a file is not a folder', runCatchThrown(function() use ($docxPth){ return (new Docx())->parseFluxArray(Fluxd::openLocalFluxd($docxPth), Array('media'=>$docxPth)); }), '/^THROWN nyu\\\\Data\\\\Parsers\\\\ParseError: Invalid option: the folder for the pictures cannot be written to/');
checkIdentical('media: a picture is never saved under the name its document gives it, whatever that name', runCatchThrown(function() use ($dotPng, $wDraw, $relOf){
	$evilPth = makeZip('evil.docx', Array(
		'word/document.xml'=>'<w:document '.WORDMAIN.'><w:body>'.$wDraw('rIdA').$wDraw('rIdB').'</w:body></w:document>',
		'word/_rels/document.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relOf('rIdA', 'media/shell.php').$relOf('rIdB', 'media/page.html').'</Relationships>',
		'word/media/shell.php'=>$dotPng, 'word/media/page.html'=>'<script>alert(1)</script>'
	));
	$mdiaDir = makeDir('evil');
	$envlope = (new Docx())->parseFluxArray(Fluxd::openLocalFluxd($evilPth), Array('media'=>$mdiaDir));
	$fileLst = array_values(array_diff(scandir($mdiaDir), Array('.', '..')));
	return Array(count($fileLst), preg_match('/^[0-9a-f]{20}\.png$/D', $fileLst[0] ?? ''), srcList($envlope['items'])[1], array_values($envlope['infos']['media']));
}), Array(1, 1, 'word/media/page.html', Array('word/media/shell.php')));
checkIdentical('no PHP warning along the way', takePhpNotices(), Array());

finishChecking();
