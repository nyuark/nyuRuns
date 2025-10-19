<?php

// Data\Image on drawings (SVG) : what a drawing is, a drawing cleaned of
// what runs and of what reaches outside it, and a drawing made pixels.

require __DIR__.'/../lib/harness.php';

use nyu\Data\Dcmnt;
use nyu\Data\Fluxd;
use nyu\Data\Image;
use nyu\Data\Parsers\Bina;
use nyu\Data\Parsers\Docx;

const SVGSPACE = 'xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"';

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

/**
 * Cleans a drawing and returns what is left of it, without the line
 * saying it is XML nor the line ends.
 *
 * @param String $drawingXml The XML of what the drawing holds.
 * @param String $rootsAttrs Attributes to give its root.
 * @return String The cleaned drawing, or a 'THROWN ...' string.
 */
function cleaned( String $drawingXml, String $rootsAttrs='' ): String {
	return runSafely(function() use ($drawingXml, $rootsAttrs){
		$outText = Image::openFluxd(Fluxd::openBytes('<svg '.SVGSPACE.' width="40" height="20"'.$rootsAttrs.'>'.$drawingXml.'</svg>'))->cleanedUp();
		return trim(str_replace(Array('<?xml version="1.0" encoding="UTF-8"?>', "\n", ' '.SVGSPACE.' width="40" height="20"'), '', $outText));
	});
}

$quadSvg = '<svg '.SVGSPACE.' width="40" height="20" viewBox="0 0 40 20"><rect width="20" height="20" fill="#ff0000"/><rect x="20" width="20" height="20" fill="#0000ff"/></svg>';

// Telling a drawing from its content
checkSame('a drawing is told from its content, whatever stands before its first element', array_map(function($headString){ return Bina::signature($headString); }, Array(
	'<svg xmlns="http://www.w3.org/2000/svg"/>', '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<svg width="1">', "\xEF\xBB\xBF<?xml version=\"1.0\"?><!-- made by a tool --><!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"x.dtd\">\n<svg>", '<svg:svg xmlns:svg="http://www.w3.org/2000/svg">', "  \n<SVG >",
	'<?xml version="1.0"?><show><svg/></show>', '<svgs>', '<html><body><svg/></body></html>', 'svg'
)), Array('svg', 'svg', 'svg', 'svg', 'svg', 'xml', '', 'html', ''));

// What a drawing is
checkSame('what a drawing is: its kind, and the size it tells in pixels', imgOpen($quadSvg)->imageInfo(), Array('kinds'=>'svg', 'width'=>40, 'heght'=>20, 'bytes'=>strlen($quadSvg), 'turns'=>1, 'exifs'=>Array()));
checkSame('its size: in pixels, told or not as such, from its box when it tells another unit or none, and given to it when it tells nothing', array_map(function($rootsAttrs){
	$infoArr = imgOpen('<svg '.SVGSPACE.' '.$rootsAttrs.'/>')->imageInfo();
	return $infoArr['width'].'x'.$infoArr['heght'];
}, Array('width="300" height="150"', 'width="300px" height="150.4px"', 'width="10cm" height="5cm" viewBox="0 0 800 400"', 'viewBox="0 0 64 32"', 'viewBox="0,0,24,48"', 'width="100%" height="100%"', 'width="100%" viewBox="0 0 200 100"', '', 'width="0" height="-5" viewBox="0 0 0 0"', 'width="300" viewBox="0 0 10 40"')),
	Array('300x150', '300x151', '800x400', '64x32', '24x48', '512x512', '200x100', '512x512', '512x512', '300x40'));
checkLike('a drawing telling more pixels than accepted is refused', imgOpen('<svg '.SVGSPACE.' width="60000" height="60000"/>'), '/^THROWN RuntimeException: Picture larger than the 32000000 pixels allowed/');
checkSame('the limit can be raised', imgOpen('<svg '.SVGSPACE.' width="60000" height="60000"/>', 4000000000)->imageInfo()['width'], 60000);
checkLike('a drawing that is not well-formed', imgOpen('<svg '.SVGSPACE.'><rect></svg>'), '/^THROWN RuntimeException: Not a picture of a known kind/');
checkLike('a drawing declaring entities in another encoding', imgOpen("\xFF\xFE".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE svg [<!ENTITY x "y">]><svg '.SVGSPACE.'><text>&x;</text></svg>', 'UTF-16LE', 'UTF-8')), '/^THROWN RuntimeException: Not a picture of a known kind/');
checkLike('a drawing declaring entities, the way a local file is read or memory filled', imgOpen('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg '.SVGSPACE.'><text>&x;</text></svg>'), '/^THROWN RuntimeException: Not a picture of a known kind/');

// Cleaning: what runs
checkSame('scripts, and the elements holding a document of another kind, are taken out with what they hold', cleaned('<rect width="1" height="1"/><script>alert(1)</script><SCRIPT xlink:href="evil.js"/><foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="x"/></body></foreignObject><g><iframe/><object/><embed/><audio/><video/><circle r="1"/></g>'), '<svg><rect width="1" height="1"/><g><circle r="1"/></g></svg>');
checkSame('what is run when something happens to an element is taken off it, whatever its case', cleaned('<rect width="1" height="1" onclick="alert(1)" ONLOAD="x()" onmouseover="y()" fill="red" id="r" on="kept? no"/>', ' onload="alert(0)"'), '<svg><rect width="1" height="1" fill="red" id="r"/></svg>');
checkSame('what would write an address or a script later on is taken out; what only moves or fades is kept', cleaned('<a><set attributeName="xlink:href" to="javascript:alert(1)"/><animate attributeName="href" values="javascript:alert(1)"/><animate attributeName="onclick" to="x"/><animate attributeName="opacity" from="0" to="1" dur="1s"/><animateTransform attributeName="transform" type="rotate"/></a>'), '<svg><a><animate attributeName="opacity" from="0" to="1" dur="1s"/><animateTransform attributeName="transform" type="rotate"/></a></svg>');

// Cleaning: what reaches outside
checkSame('an address stays inside the drawing: what points elsewhere loses its address', cleaned('<defs><linearGradient id="g"/><symbol id="s"/></defs><use xlink:href="#s"/><use href="#s"/><use xlink:href="other.svg#s"/><use href="https://evil.test/x.svg#s"/><rect fill="url(#g)"/><rect fill="url(https://evil.test/g)"/><rect style="fill:url(\'#g\')"/><rect style="background:url(//evil.test/x)"/><textPath xlink:href="//evil.test/p"/>'),
	'<svg><defs><linearGradient id="g"/><symbol id="s"/></defs><use xlink:href="#s"/><use href="#s"/><use/><use/><rect fill="url(#g)"/><rect/><rect style="fill:url(\'#g\')"/><rect/><textPath/></svg>');
checkSame('a picture inside a drawing: kept when it is written in place, taken off when it is fetched, or is itself a drawing', cleaned('<image width="1" height="1" xlink:href="data:image/png;base64,iVBORw0KGgo="/><image href="data:image/jpeg;base64,/9j/4AAQ"/><image xlink:href="https://evil.test/track.png"/><image href="file:///etc/passwd"/><image href="data:image/svg+xml;base64,PHN2Zy8+"/><image href="data:text/html,x"/>'),
	'<svg><image width="1" height="1" xlink:href="data:image/png;base64,iVBORw0KGgo="/><image href="data:image/jpeg;base64,/9j/4AAQ"/><image/><image/><image/><image/></svg>');
checkSame('a link to a page is kept when it is safe to follow', cleaned('<a xlink:href="https://nyuark.test/a?b=1">ok</a><a href="/venues/a">ok</a><a href="#top">ok</a><a xlink:href="javascript:alert(1)">no</a><a href=" JaVa&#10;ScRiPt:alert(1)">no</a><a href="data:text/html,x">no</a><a xlink:href="vbscript:x">no</a>'),
	'<svg><a xlink:href="https://nyuark.test/a?b=1">ok</a><a href="/venues/a">ok</a><a href="#top">ok</a><a>no</a><a>no</a><a>no</a><a>no</a></svg>');
checkSame('a style is kept as long as it reaches nowhere and runs nothing', cleaned('<style>.a { fill: red; } .b { fill: url(#g); }</style><style>@import url("https://evil.test/x.css");</style><style>.a { background: url(https://evil.test/x); }</style><style>.a { width: expression(alert(1)); }</style><rect style="fill:red;stroke:blue"/><rect style="x:expression(alert(1))"/><rect style="behavior:url(x.htc)"/>'),
	'<svg><style>.a { fill: red; } .b { fill: url(#g); }</style><rect style="fill:red;stroke:blue"/><rect/><rect/></svg>');
checkSame('what stands around the drawing is taken off: what it asks a browser to load, its comments, the kind of document it says it is', runSafely(function(){
	$outText = Image::openFluxd(Fluxd::openBytes('<?xml version="1.0" encoding="UTF-8"?><?xml-stylesheet href="https://evil.test/x.css"?><!-- top --><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://127.0.0.1:9/never.dtd"><svg '.SVGSPACE.'><!-- in --><rect width="1"/><?php echo 1; ?></svg>'))->cleanedUp();
	return str_replace("\n", '', $outText);
}), '<?xml version="1.0" encoding="UTF-8"?><svg '.SVGSPACE.'><rect width="1"/></svg>');
checkSame('what is drawn stays as it is: shapes, paths, text with its accents, gradients, groups, what tools add of their own', cleaned('<title>Les Enchaînés</title><defs><linearGradient id="g" x1="0" x2="1"><stop offset="0" stop-color="#f00"/></linearGradient></defs><g transform="translate(1 2)" id="layer" class="a"><path d="M0 0L10 10z" fill="url(#g)" stroke-width="1.5"/><text x="1" y="2" font-family="Arial">Été &amp; &lt;b&gt;</text></g><metadata><x:note xmlns:x="urn:x" x:kept="yes">tool data</x:note></metadata>'),
	'<svg><title>Les Enchaînés</title><defs><linearGradient id="g" x1="0" x2="1"><stop offset="0" stop-color="#f00"/></linearGradient></defs><g transform="translate(1 2)" id="layer" class="a"><path d="M0 0L10 10z" fill="url(#g)" stroke-width="1.5"/><text x="1" y="2" font-family="Arial">Été &amp; &lt;b&gt;</text></g><metadata><x:note xmlns:x="urn:x" x:kept="yes">tool data</x:note></metadata></svg>');
checkSame('a drawing cleaned once is unchanged when cleaned again; giving it back as a drawing gives it cleaned', runSafely(function(){
	$evilSvg = '<svg '.SVGSPACE.' width="40" height="20" onload="x()"><script>alert(1)</script><rect width="1" height="1" onclick="y()"/><image href="https://evil.test/t.png"/></svg>';
	$onceTxt = Image::openFluxd(Fluxd::openBytes($evilSvg))->cleanedUp();
	$srceImg = Image::openFluxd(Fluxd::openBytes($evilSvg));
	return Array(Image::openFluxd(Fluxd::openBytes($onceTxt))->cleanedUp()===$onceTxt, $srceImg->giveBytes()===$onceTxt, $srceImg->giveBytes('SVG')===$onceTxt, strpos($onceTxt, 'alert'), strpos($onceTxt, 'evil'), strpos($onceTxt, 'onclick'), Image::openFluxd(Fluxd::openBytes($onceTxt))->imageInfo()['kinds']);
}), Array(true, true, true, false, false, false, 'svg'));
checkLike('only a drawing is cleaned up', runSafely(function(){ return Image::openFluxd(Fluxd::openBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')))->cleanedUp(); }), '/^THROWN RuntimeException: Only a drawing is cleaned up/');
checkLike('pixels cannot be given back as a drawing', runSafely(function(){ return Image::openFluxd(Fluxd::openBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==')))->giveBytes('svg'); }), '/^THROWN RuntimeException: Only a drawing is cleaned up/');

// Saving a drawing
checkSame('a drawing saved to a file is saved cleaned', runSafely(function(){
	$savePth = Image::openFluxd(Fluxd::openBytes('<svg '.SVGSPACE.' width="4" height="2"><script>alert(1)</script><rect width="1"/></svg>'))->saveLocal(inTempDir('logo.svg'));
	return Array(strpos(file_get_contents($savePth), 'script'), Image::openFluxd(Fluxd::openLocal($savePth))->imageInfo()['width']);
}), Array(false, 4));

// Through documents
checkSame('a drawing file is still read as the XML it is, by whoever asks for its elements', runSafely(function() use ($quadSvg){
	$docPath = inTempDir('quad.svg');
	$barePth = inTempDir('drawing');
	file_put_contents($docPath, $quadSvg);
	file_put_contents($barePth, $quadSvg);
	$docFile = new Dcmnt($docPath);
	$bareDoc = new Dcmnt($barePth);
	return Array($docFile->readValue('sig'), $docFile->readValue('shp'), $docFile->readValue()['tagnm'], count($docFile->readValue()['nodes']), $bareDoc->readValue('ext'), $bareDoc->readValue()['tagnm']);
}), Array('svg', 'nodes', 'svg', 2, 'svg', 'svg'));
if( class_exists('ZipArchive') ){
	checkSame('a drawing held by a document is saved cleaned, as the drawing it is', runSafely(function(){
		$wordSpc = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';
		$zipPath = inTempDir('logo.docx');
		$zipArch = new ZipArchive();
		$zipArch->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$zipArch->addFromString('word/document.xml', '<w:document '.$wordSpc.'><w:body><w:p><w:r><w:drawing><wp:inline><wp:docPr id="1" name="L" descr="Logo"/><a:graphic><a:graphicData><a:blip r:embed="rIdL"/></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p></w:body></w:document>');
		$zipArch->addFromString('word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdL" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.png"/></Relationships>');
		$zipArch->addFromString('word/media/logo.png', '<svg '.SVGSPACE.' width="8" height="8"><script>alert(1)</script><circle r="4"/></svg>');
		$zipArch->close();
		$mdiaDir = inTempDir('media');
		mkdir($mdiaDir);
		$envlope = (new Docx())->parseFlux(Fluxd::openLocal($zipPath), Array('media'=>$mdiaDir));
		$fileNme = $envlope['items'][0]['attrs']['sourc'];
		return Array(preg_match('/^[0-9a-f]{20}\.svg$/D', $fileNme), strpos(file_get_contents($mdiaDir.DS.$fileNme), 'script'), strpos(file_get_contents($mdiaDir.DS.$fileNme), '<circle')!==false);
	}), Array(1, false, true));
}

// Drawing into pixels
if( !class_exists('Imagick') || !function_exists('imagecreatefromstring') ){
	checkLike('drawing into pixels asks for the extensions that do it', runSafely(function() use ($quadSvg){ return Image::openFluxd(Fluxd::openBytes($quadSvg))->resizeFit(20, 20); }), '/^THROWN RuntimeException: (A drawing needs the imagick extension of PHP to be drawn into pixels|Pictures need the gd extension of PHP to be changed)/');
	skipCheck('drawings made pixels', 'the imagick and gd extensions are not both loaded');
	checkSame('no PHP warning along the way', takeNotes(), Array());
	endChecks();
}

/**
 * Names the colours of some points of a picture.
 *
 * @param String $rawContent The bytes of the picture.
 * @param Array  $pointsList The points, each as a part of the width and a part of the height, from 0 to 1.
 * @return String Its size, then the colour of each point: R, G, B, W, K for black, ? for any other, and - for a see-through one.
 */
function colours( String $rawContent, Array $pointsList=Array(Array(0.25, 0.5), Array(0.75, 0.5)) ): String {
	$gdImage = imagecreatefromstring($rawContent);
	if( !imageistruecolor($gdImage) ){
		imagepalettetotruecolor($gdImage);
	}
	$colrTxt = imagesx($gdImage).'x'.imagesy($gdImage).' ';
	foreach( $pointsList as $onePnt ){
		$colrSet = imagecolorsforindex($gdImage, imagecolorat($gdImage, intval($onePnt[0]*(imagesx($gdImage)-1)), intval($onePnt[1]*(imagesy($gdImage)-1))));
		$bitsTxt = (($colrSet['red']>128) ? '1' : '0').(($colrSet['green']>128) ? '1' : '0').(($colrSet['blue']>128) ? '1' : '0');
		$colrTxt .= ($colrSet['alpha']>100) ? '-' : (Array('100'=>'R', '010'=>'G', '001'=>'B', '111'=>'W', '000'=>'K')[$bitsTxt] ?? '?');
	}
	return $colrTxt;
}

$quadImg = imgOpen($quadSvg);
checkSame('a drawing given as pixels: of the size it tells, what it draws where it draws it', Array(colours($quadImg->giveBytes('png')), colours($quadImg->giveBytes('jpg')), colours($quadImg->giveBytes('webp'))), Array('40x20 RB', '40x20 RB', '40x20 RB'));
checkSame('a drawing resized, cut and turned as any picture is; the picture made is made of pixels', Array(colours($quadImg->resizeFit(20, 20)->giveBytes()), colours($quadImg->resizeFit(400, 400, true)->giveBytes()), colours($quadImg->cropCover(10, 10)->giveBytes()), colours($quadImg->turnRight(180)->giveBytes()), $quadImg->resizeFit(20, 20)->imageInfo()), Array('20x10 RB', '400x200 RB', '10x10 RB', '40x20 BR', Array('kinds'=>'png', 'width'=>20, 'heght'=>10, 'bytes'=>0, 'turns'=>1, 'exifs'=>Array())));
checkSame('what a drawing does not draw on is see-through, and laid on white in the kinds that cannot say it', runSafely(function(){
	$halfImg = imgOpen('<svg '.SVGSPACE.' width="40" height="20"><rect width="20" height="20" fill="#ff0000"/></svg>');
	return Array(colours($halfImg->giveBytes('png')), colours($halfImg->giveBytes('jpg')));
}), Array('40x20 R-', '40x20 RW'));
checkSame('a drawing telling its size by its box, in another unit than pixels, or not at all, is drawn at the size it is given', Array(
	colours(imgOpen('<svg '.SVGSPACE.' viewBox="0 0 40 20"><rect width="20" height="20" fill="#ff0000"/><rect x="20" width="20" height="20" fill="#0000ff"/></svg>')->giveBytes('png')),
	colours(imgOpen('<svg '.SVGSPACE.' width="10cm" height="5cm" viewBox="0 0 80 40"><rect width="40" height="40" fill="#ff0000"/><rect x="40" width="40" height="40" fill="#0000ff"/></svg>')->giveBytes('png')),
	colours(imgOpen('<svg '.SVGSPACE.'><rect width="600" height="600" fill="#ff0000"/></svg>')->giveBytes('png'))
), Array('40x20 RB', '80x40 RB', '512x512 RR'));
checkSame('a drawing is cleaned before it is drawn: what it points to outside is not fetched, and it is drawn without it', runSafely(function(){
	$scrtPth = inTempDir('secret.png');
	$gdImage = imagecreatetruecolor(40, 20);
	imagefill($gdImage, 0, 0, imagecolorallocate($gdImage, 0, 255, 0));
	imagepng($gdImage, $scrtPth);
	$evilImg = imgOpen('<svg '.SVGSPACE.' width="40" height="20"><rect width="40" height="20" fill="#ff0000"/><image width="40" height="20" xlink:href="'.str_replace('\\', '/', $scrtPth).'"/><image width="40" height="20" href="file:///'.str_replace('\\', '/', $scrtPth).'"/><image width="40" height="20" xlink:href="http://127.0.0.1:9/never.png"/></svg>');
	return colours($evilImg->giveBytes('png'));
}), '40x20 RR');
checkLike('a drawing that cannot be drawn is refused', runSafely(function(){ return imgOpen('<svg '.SVGSPACE.' width="40" height="20"><path d="this is not a path" transform="matrix(nonsense)"/><rect width="abc"/></svg>')->giveBytes('png')==='' ? 'empty' : 'drawn'; }), '/^(drawn|THROWN RuntimeException: Drawing that cannot be drawn into pixels)/');
checkSame('no PHP warning along the way', takeNotes(), Array());

endChecks();
