<?php

// Data\Image : what a picture is, and the pictures made from it.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Fluxd;
use nyu\Data\Image;

// A picture of one pixel, as PNG: enough to tell what a picture is without the gd extension
$dotPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

/**
 * Opens a picture from its bytes, or returns the reason it was refused.
 *
 * @param String $rawContent The bytes.
 * @param Int    $pixelLimit Greatest number of pixels accepted.
 * @return mixed The picture, or a 'THROWN ...' string.
 */
function imgOpen( String $rawContent, Int $pixelLimit=Image::MAXPIXEL ): Mixed {
	return runSafely(function() use ($rawContent, $pixelLimit){
		return Image::openFluxd(Fluxd::openBytes($rawContent), $pixelLimit);
	});
}

// Telling what a picture is does not need gd
checkSame('what a picture is, told from its content', imgOpen($dotPng)->imageInfo(), Array('kinds'=>'png', 'width'=>1, 'heght'=>1, 'bytes'=>strlen($dotPng), 'turns'=>1, 'exifs'=>Array()));
checkLike('a content that is not a picture', imgOpen('<html><body><p>not a picture</p></body></html>'), '/^THROWN RuntimeException: Not a picture of a known kind/');
checkLike('an empty content', imgOpen(''), '/^THROWN RuntimeException: Not a picture of a known kind/');
checkLike('a picture cut short', imgOpen(substr($dotPng, 0, 12)), '/^THROWN RuntimeException: Not a picture of a known kind/');
checkLike('a source larger than the size accepted', runSafely(function() use ($dotPng){ return Image::openFluxd(Fluxd::openBytes($dotPng), Image::MAXPIXEL, 20); }), '/^THROWN RuntimeException: Content larger than the 20 bytes allowed/');
// A picture claiming a huge size in a few bytes: refused from its first bytes, before anything is unpacked
$bombPng = substr($dotPng, 0, 16).pack('NN', 60000, 60000).substr($dotPng, 24);
checkLike('a picture holding more pixels than accepted is refused before it is unpacked', imgOpen($bombPng), '/^THROWN RuntimeException: Picture larger than the 32000000 pixels allowed/');
checkSame('the limit can be raised, or lowered', Array(imgOpen($bombPng, 4000000000)->imageInfo()['width'], preg_replace('/ @.*$/', '', imgOpen($dotPng, 0))), Array(60000, 'THROWN RuntimeException: Picture larger than the 0 pixels allowed'));

if( !function_exists('imagecreatetruecolor') ){
	checkLike('changing a picture asks for the gd extension', runSafely(function() use ($dotPng){ return Image::openFluxd(Fluxd::openBytes($dotPng))->resizeFit(10, 10); }), '/^THROWN RuntimeException: Pictures need the gd extension of PHP to be changed/');
	skipCheck('pictures made from pictures', 'the gd extension is not loaded');
	endChecks();
}

/**
 * Draws a picture whose four quarters are of four colours: red top left,
 * green top right, blue bottom left, white bottom right.
 *
 * @param Int    $widthAsked Its width.
 * @param Int    $heghtAsked Its height.
 * @param String $imageKinds Its kind.
 * @return String The bytes of the picture.
 */
function drawQuad( Int $widthAsked, Int $heghtAsked, String $imageKinds='png' ): String {
	$gdImage = imagecreatetruecolor($widthAsked, $heghtAsked);
	$halfWde = intdiv($widthAsked, 2);
	$halfTll = intdiv($heghtAsked, 2);
	imagefilledrectangle($gdImage, 0, 0, $halfWde-1, $halfTll-1, imagecolorallocate($gdImage, 255, 0, 0));
	imagefilledrectangle($gdImage, $halfWde, 0, $widthAsked-1, $halfTll-1, imagecolorallocate($gdImage, 0, 255, 0));
	imagefilledrectangle($gdImage, 0, $halfTll, $halfWde-1, $heghtAsked-1, imagecolorallocate($gdImage, 0, 0, 255));
	imagefilledrectangle($gdImage, $halfWde, $halfTll, $widthAsked-1, $heghtAsked-1, imagecolorallocate($gdImage, 255, 255, 255));
	ob_start();
	Array('png'=>'imagepng', 'jpg'=>'imagejpeg', 'gif'=>'imagegif', 'webp'=>'imagewebp', 'bmp'=>'imagebmp')[$imageKinds]($gdImage);
	return strval(ob_get_clean());
}

/**
 * Names the colours of the four corners of a picture.
 *
 * @param String $rawContent The bytes of the picture.
 * @return String Its size, then the colours of its corners, top left, top right, bottom left, bottom right: R, G, B, W, or ? for any other, and - for a see-through one.
 */
function corners( String $rawContent ): String {
	$gdImage = imagecreatefromstring($rawContent);
	if( !imageistruecolor($gdImage) ){
		imagepalettetotruecolor($gdImage);
	}
	$cornTxt = imagesx($gdImage).'x'.imagesy($gdImage).' ';
	foreach( Array(Array(1, 1), Array(imagesx($gdImage)-2, 1), Array(1, imagesy($gdImage)-2), Array(imagesx($gdImage)-2, imagesy($gdImage)-2)) as $onePnt ){
		$colrSet = imagecolorsforindex($gdImage, imagecolorat($gdImage, $onePnt[0], $onePnt[1]));
		$bitsTxt = (($colrSet['red']>128) ? '1' : '0').(($colrSet['green']>128) ? '1' : '0').(($colrSet['blue']>128) ? '1' : '0');
		$cornTxt .= ($colrSet['alpha']>100) ? '-' : (Array('100'=>'R', '010'=>'G', '001'=>'B', '111'=>'W')[$bitsTxt] ?? '?');
	}
	return $cornTxt;
}

/**
 * Adds to a JPEG picture what a camera records: the way up it was held,
 * a date, a camera and a place.
 *
 * @param String $rawContent The bytes of the picture.
 * @param Int    $turnsValue The way up, from 1 to 8.
 * @return String The bytes of the picture, with that record.
 */
function withExif( String $rawContent, Int $turnsValue ): String {
	$textLst = Array('make'=>"nyuArk\0", 'modl'=>"Test Camera\0", 'date'=>"2026:12:28 20:30:00\0");
	// The record: a table of entries, each naming what it tells, its type, its length, and its value or where its value stands
	$headLen = 8;
	$mainNbr = 6;
	$dataPos = $headLen+2+$mainNbr*12+4;
	$makePos = $dataPos;
	$modlPos = $makePos+strlen($textLst['make']);
	$datePos = $modlPos+strlen($textLst['modl']);
	$gpsPosn = $datePos+strlen($textLst['date']);
	$gpsData = $gpsPosn+2+4*12+4;
	$mainTbl = pack('n', $mainNbr)
		.pack('nnNN', 0x010F, 2, strlen($textLst['make']), $makePos).pack('nnNN', 0x0110, 2, strlen($textLst['modl']), $modlPos)
		.pack('nnNnn', 0x0112, 3, 1, $turnsValue, 0).pack('nnNN', 0x0132, 2, strlen($textLst['date']), $datePos)
		.pack('nnNN', 0x8825, 4, 1, $gpsPosn).pack('nnNN', 0x0131, 2, 4, 0x6E797500)
		.pack('N', 0);
	$gpsTble = pack('n', 4)
		.pack('nnNa4', 1, 2, 2, "N\0\0\0").pack('nnNN', 2, 5, 3, $gpsData)
		.pack('nnNa4', 3, 2, 2, "W\0\0\0").pack('nnNN', 4, 5, 3, $gpsData+24)
		.pack('N', 0);
	// 45° 45' 36" north, 4° 49' 48" west
	$gpsVals = pack('N6', 45, 1, 45, 1, 36, 1).pack('N6', 4, 1, 49, 1, 48, 1);
	$tiffTxt = "MM\0*".pack('N', 8).$mainTbl.$textLst['make'].$textLst['modl'].$textLst['date'].$gpsTble.$gpsVals;
	$exifSeg = "Exif\0\0".$tiffTxt;
	return substr($rawContent, 0, 2)."\xFF\xE1".pack('n', strlen($exifSeg)+2).$exifSeg.substr($rawContent, 2);
}

// What a picture is, for every kind
checkSame('the kinds of picture, told from their content', array_map(function($imageKinds){
	$infoArr = imgOpen(drawQuad(40, 20, $imageKinds))->imageInfo();
	return $infoArr['kinds'].' '.$infoArr['width'].'x'.$infoArr['heght'].' '.$infoArr['turns'];
}, Array('png', 'jpg', 'gif', 'webp', 'bmp')), Array('png 40x20 1', 'jpg 40x20 1', 'gif 40x20 1', 'webp 40x20 1', 'bmp 40x20 1'));

// Resizing
$quadImg = imgOpen(drawQuad(400, 200));
checkSame('fitting inside a width and a height keeps the proportions: bound by the width, by the height, by both', Array(corners($quadImg->resizeFit(100, 100)->giveBytes()), corners($quadImg->resizeFit(1000, 50)->giveBytes()), corners($quadImg->resizeFit(40, 20)->giveBytes()), corners($quadImg->resizeFit(30, 10)->giveBytes())), Array('100x50 RGBW', '100x50 RGBW', '40x20 RGBW', '20x10 RGBW'));
checkSame('a picture that already fits is not enlarged, unless told so', Array(corners($quadImg->resizeFit(1000, 1000)->giveBytes()), corners($quadImg->resizeFit(1000, 1000, true)->giveBytes()), corners($quadImg->resizeFit(800, 1000, true)->giveBytes())), Array('400x200 RGBW', '1000x500 RGBW', '800x400 RGBW'));
checkSame('a picture made from another tells its own size, and that it was not opened from a content', $quadImg->resizeFit(100, 100)->imageInfo(), Array('kinds'=>'png', 'width'=>100, 'heght'=>50, 'bytes'=>0, 'turns'=>1, 'exifs'=>Array()));
checkSame('the picture it was made from is unchanged', Array($quadImg->imageInfo()['width'], corners($quadImg->giveBytes())), Array(400, '400x200 RGBW'));
checkSame('a very thin picture keeps at least one pixel', imgOpen(drawQuad(400, 4))->resizeFit(20, 20)->imageInfo()['heght'], 1);
checkLike('a size that is not positive is refused', runSafely(function() use ($quadImg){ return $quadImg->resizeFit(0, 100); }), '/^THROWN RuntimeException: A picture is at least one pixel wide and one pixel high/');
checkLike('a picture enlarged past the pixels accepted is refused', runSafely(function(){ return imgOpen(drawQuad(40, 20), 10000)->resizeFit(4000, 4000, true); }), '/^THROWN RuntimeException: Picture larger than the 10000 pixels allowed/');

// Cutting
checkSame('covering a width and a height exactly: what goes over is cut off evenly; a square, a tall one, a wide one, a larger one', Array(corners($quadImg->cropCover(100, 100)->giveBytes()), corners($quadImg->cropCover(50, 200)->giveBytes()), corners($quadImg->cropCover(200, 20)->giveBytes()), corners($quadImg->cropCover(600, 600)->giveBytes())), Array('100x100 RGBW', '50x200 RGBW', '200x20 RGBW', '600x600 RGBW'));
checkSame('what is cut off is what stands at the sides: the middle of a wide picture is kept', runSafely(function(){
	// Three stripes: red, green, blue. A square cut from the middle is green, edge to edge
	$gdImage = imagecreatetruecolor(300, 100);
	imagefilledrectangle($gdImage, 0, 0, 99, 99, imagecolorallocate($gdImage, 255, 0, 0));
	imagefilledrectangle($gdImage, 100, 0, 199, 99, imagecolorallocate($gdImage, 0, 255, 0));
	imagefilledrectangle($gdImage, 200, 0, 299, 99, imagecolorallocate($gdImage, 0, 0, 255));
	ob_start();
	imagepng($gdImage);
	return corners(imgOpen(strval(ob_get_clean()))->cropCover(50, 50)->giveBytes());
}), '50x50 GGGG');

// Turning
checkSame('turning to the right by quarter turns: the corners move the way the hands of a clock go', array_map(function($degreesNum) use ($quadImg){ return corners($quadImg->turnRight($degreesNum)->giveBytes()); }, Array(90, 180, 270, 0, 360, -90, 450)), Array('200x400 BRWG', '400x200 WBGR', '200x400 GWRB', '400x200 RGBW', '400x200 RGBW', '200x400 GWRB', '200x400 BRWG'));
checkLike('a turn that is not a quarter turn is refused', runSafely(function() use ($quadImg){ return $quadImg->turnRight(45); }), '/^THROWN RuntimeException: A picture is turned by quarter turns/');

// What the camera recorded
$turnPic = withExif(drawQuad(400, 200, 'jpg'), 6);
checkSame('what the camera recorded: the way up, the date, the camera, the place', imgOpen($turnPic)->imageInfo(), function_exists('exif_read_data') ? Array('kinds'=>'jpg', 'width'=>400, 'heght'=>200, 'bytes'=>strlen($turnPic), 'turns'=>6, 'exifs'=>Array('dated'=>'2026-12-28 20:30:00', 'maker'=>'nyuArk', 'model'=>'Test Camera', 'gpsla'=>45.76, 'gpslo'=>-4.83)) : Array('kinds'=>'jpg', 'width'=>400, 'heght'=>200, 'bytes'=>strlen($turnPic), 'turns'=>1, 'exifs'=>Array()));
if( function_exists('exif_read_data') ){
	// What each of the eight ways up must give once set upright, the picture being drawn RGBW before the camera turned or mirrored it
	checkSame('setting upright, from each of the eight ways up a camera records', array_map(function($turnsValue){
		$madeImg = imgOpen(withExif(drawQuad(400, 200, 'jpg'), $turnsValue))->setUprght();
		return $turnsValue.' '.corners($madeImg->giveBytes('png')).' '.$madeImg->imageInfo()['turns'];
	}, range(1, 8)), Array('1 400x200 RGBW 1', '2 400x200 GRWB 1', '3 400x200 WBGR 1', '4 400x200 BWRG 1', '5 200x400 RBGW 1', '6 200x400 BRWG 1', '7 200x400 WGBR 1', '8 200x400 GWRB 1'));
	checkSame('a picture written anew holds nothing of what the camera recorded: neither as JPEG nor as any other kind', runSafely(function() use ($turnPic){
		$srceImg = imgOpen($turnPic);
		$backLst = Array();
		foreach( Array('jpg', 'png', 'webp') as $kndKey ){
			$outBytes = $srceImg->giveBytes($kndKey);
			$backLst[] = Array(imgOpen($outBytes)->imageInfo()['exifs'], imgOpen($outBytes)->imageInfo()['turns'], strpos($outBytes, 'Test Camera'), strpos($outBytes, 'Exif'));
		}
		return Array($backLst, strpos($turnPic, 'Test Camera')!==false);
	}), Array(array_fill(0, 3, Array(Array(), 1, false, false)), true));
	checkSame('a way up that is none, and a record that is damaged, are read as nothing', Array(imgOpen(withExif(drawQuad(40, 20, 'jpg'), 9))->imageInfo()['turns'], imgOpen(substr($turnPic, 0, 30).str_repeat("\xFF", 40).substr($turnPic, 70))->imageInfo()['kinds']), Array(1, 'jpg'));
}else{
	skipCheck('what the camera recorded', 'the exif extension is not loaded');
}

// Giving a picture back
checkSame('a picture given back in each kind is a picture of that kind, of the same size', array_map(function($imageKinds) use ($quadImg){
	$infoArr = imgOpen($quadImg->giveBytes($imageKinds))->imageInfo();
	return $infoArr['kinds'].' '.$infoArr['width'].'x'.$infoArr['heght'];
}, Array('png', 'jpg', 'JPEG', 'gif', 'webp', 'bmp', '')), Array('png 400x200', 'jpg 400x200', 'jpg 400x200', 'gif 400x200', 'webp 400x200', 'bmp 400x200', 'png 400x200'));
checkSame('the less of a picture is kept, the less it weighs', runSafely(function(){
	// A picture of many colours, for the kinds that lose some of it to weigh less
	$gdImage = imagecreatetruecolor(200, 200);
	for( $pxlIdx=0; $pxlIdx<40000; $pxlIdx++ ){
		imagesetpixel($gdImage, $pxlIdx%200, intdiv($pxlIdx, 200), imagecolorallocate($gdImage, ($pxlIdx*7)%256, ($pxlIdx*13)%256, ($pxlIdx*29)%256));
	}
	ob_start();
	imagepng($gdImage);
	$srceImg = imgOpen(strval(ob_get_clean()));
	return Array(strlen($srceImg->giveBytes('jpg', 20))<strlen($srceImg->giveBytes('jpg', 95)), strlen($srceImg->giveBytes('jpg', 0))===strlen($srceImg->giveBytes('jpg', 1)), strlen($srceImg->giveBytes('jpg', 500))===strlen($srceImg->giveBytes('jpg', 100)));
}), Array(true, true, true));
checkSame('what is see-through stays so in the kinds that can say it, and is laid on white in the others', runSafely(function(){
	$gdImage = imagecreatetruecolor(40, 40);
	imagealphablending($gdImage, false);
	imagesavealpha($gdImage, true);
	imagefill($gdImage, 0, 0, imagecolorallocatealpha($gdImage, 0, 0, 0, 127));
	imagefilledrectangle($gdImage, 0, 0, 19, 19, imagecolorallocate($gdImage, 255, 0, 0));
	ob_start();
	imagepng($gdImage);
	$srceImg = imgOpen(strval(ob_get_clean()));
	return Array(corners($srceImg->giveBytes('png')), corners($srceImg->resizeFit(20, 20)->giveBytes('png')), corners($srceImg->giveBytes('webp')), corners($srceImg->giveBytes('jpg')), corners($srceImg->turnRight(90)->giveBytes('png')));
}), Array('40x40 R---', '20x20 R---', '40x40 R---', '40x40 RWWW', '40x40 -R--'));
checkLike('a kind that is not a known one is refused', runSafely(function() use ($quadImg){ return $quadImg->giveBytes('tiff'); }), '/^THROWN RuntimeException: Kind of picture this server does not write: tiff/');
checkSame('an animated picture gives its first image', runSafely(function(){
	// Two images of one pixel, red then blue, in one GIF
	$gifAnim = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xFF\x00\x00\x00\x00\xFF"."!\xF9\x04\x00\x0A\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00"."!\xF9\x04\x00\x0A\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02L\x01\x00;";
	$gdImage = imagecreatefromstring(imgOpen($gifAnim)->giveBytes('png'));
	return Array(imgOpen($gifAnim)->imageInfo()['kinds'], imagecolorsforindex($gdImage, imagecolorat($gdImage, 0, 0))['red']>128);
}), Array('gif', true));

// Saving
checkSame('a picture saved to a file is a picture of the kind asked for', runSafely(function() use ($quadImg){
	$savePth = $quadImg->resizeFit(50, 50)->saveLocal(inTempDir('thumb.jpg'), 'jpg', 80);
	$backInf = Image::openFluxd(Fluxd::openLocal($savePth))->imageInfo();
	return Array(basename($savePth), $backInf['kinds'], $backInf['width'], $backInf['heght'], $backInf['bytes']===filesize($savePth));
}), Array('thumb.jpg', 'jpg', 50, 25, true));
checkLike('a file that cannot be written is refused, without its full path', runSafely(function() use ($quadImg){ return $quadImg->saveLocal(inTempDir('nowhere'.DS.'deep'.DS.'x.png')); }), '/^THROWN RuntimeException: File cannot be written: x\.png/');

// Damaged pictures
checkLike('a picture whose first bytes are right and whose content is damaged', runSafely(function(){
	$quadPng = drawQuad(400, 200);
	return imgOpen(substr($quadPng, 0, 60).str_repeat("\x00", 200))->resizeFit(10, 10);
}), '/^THROWN RuntimeException: Picture that cannot be read/');
checkSame('no PHP warning is left behind, by a refusal or otherwise', takeNotes(), Array());

endChecks();
