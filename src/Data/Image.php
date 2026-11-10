<?php

namespace nyu\Data;

use nyu\Data\Parsers\Bina;

/**
 * A picture: what it is, told from its content, and the pictures that
 * can be made of it, smaller, cut, turned or of another kind.
 *
 * A picture is never changed: resizing, cutting or turning it gives
 * another one. A picture given back is written anew, without what the
 * camera recorded in it: neither the date nor the place it was taken.
 * The gd extension of PHP makes the pictures; telling what a picture is
 * does not need it.
 *
 * A drawing (SVG) is a picture too. It is text that may carry scripts
 * and point outside itself: it is given back cleaned of both, and it is
 * cleaned before it is drawn. Drawing it into pixels, to resize it or to
 * give it as another kind, needs the imagick extension.
 */
class Image {

	// Greatest number of pixels a picture may have unless told otherwise: 32 millions
	const MAXI_PIXL = 32000000;
	// Greatest size read from a source unless told otherwise: 32 MB
	const MAXI_BYTE = 33554432;
	// The kinds of picture read, by the number PHP gives them
	const KIND_LIST = Array(1=>'gif', 2=>'jpg', 3=>'png', 6=>'bmp', 18=>'webp', 19=>'avif');
	// The longer side given to a drawing that tells no size, in pixels
	const DFLT_SIDE = 512;
	// The elements taken out of a drawing: what runs, and what holds a document of another kind
	const DROP_LIST = Array('script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'handler', 'listener', 'set', 'form', 'input', 'button', 'meta');
	// What makes a style, or a value, reach outside the drawing or run
	const RISK_PATT = '/@import|expression\s*\(|behavior\s*:|-moz-binding|j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t\s*:|url\s*\(\s*(?!["\']?\s*#)|image-set\s*\(/i';

	protected $nakedBytes;
	protected $detailList;
	protected $gdInstance;
	protected $pixelLimit;

	/**
	 * Private: a picture is opened through openFluxdImage(), or made from
	 * another one.
	 *
	 * @param String $nakedContent The bytes of the picture, '' for one made from another.
	 * @param Array  $mediaDetails What the picture is, as imageInfoArray() gives it.
	 * @param mixed  $gdPictureObj The picture as gd holds it, null as long as it is not needed.
	 * @param Int    $pixelCeiling Greatest number of pixels accepted.
	 */
	private function __construct(String $nakedContent, Array $mediaDetails, Mixed $gdPictureObj, Int $pixelCeiling){
		$this->nakedBytes = $nakedContent;
		$this->detailList = $mediaDetails;
		$this->gdInstance = $gdPictureObj;
		$this->pixelLimit = $pixelCeiling;
	}

	/**
	 * Opens a picture from a source. What it is, and its size, are read
	 * from its first bytes: a picture larger than the limit is refused
	 * before anything of it is unpacked.
	 *
	 * @param Fluxd $sourceStream The source.
	 * @param Int   $pixelCeiling Greatest number of pixels accepted, its width multiplied by its height.
	 * @param Int   $bytesCeiling Greatest size read from the source, in bytes.
	 * @return Image The opened picture.
	 *
	 * @throws \RuntimeException When the source is larger than the limit, is not a picture of a known kind, or holds more pixels than accepted.
	 */
	public static function openFluxdImage(Fluxd $sourceStream, Int $pixelCeiling=self::MAXI_PIXL, Int $bytesCeiling=self::MAXI_BYTE): Image {
		$rawText = $sourceStream->getWholeString($bytesCeiling);
		if( Bina::detectFileKind(substr($rawText, 0, 512))=='svg' ){
			return self::openADrawImage($rawText, $pixelCeiling);
		}
		$sizeArr = ($rawText==='') ? false : @getimagesizefromstring($rawText);
		if( $sizeArr===false || !isset(self::KIND_LIST[$sizeArr[2]]) || $sizeArr[0]<1 || $sizeArr[1]<1 ){
			throw new \RuntimeException('Not a picture of a known kind');
		}
		if( $sizeArr[0]*$sizeArr[1]>$pixelCeiling ){
			throw new \RuntimeException('Picture larger than the '.$pixelCeiling.' pixels allowed');
		}
		$infoArr = Array('kinds'=>self::KIND_LIST[$sizeArr[2]], 'width'=>intval($sizeArr[0]), 'heght'=>intval($sizeArr[1]), 'bytes'=>strlen($rawText), 'turns'=>1, 'exifs'=>Array());
		// What the camera recorded, when the picture is of a kind that holds it and PHP can read it
		if( $infoArr['kinds']=='jpg' && function_exists('exif_read_data') ){
			$memFile = fopen('php://memory', 'r+b');
			fwrite($memFile, $rawText);
			rewind($memFile);
			$exifArr = @exif_read_data($memFile);
			fclose($memFile);
			if( is_array($exifArr) ){
				$turnVal = intval($exifArr['Orientation'] ?? 1);
				$infoArr['turns'] = ($turnVal>=1 && $turnVal<=8) ? $turnVal : 1;
				$infoArr['exifs'] = self::exifFactsArray($exifArr);
			}
		}
		return new Image($rawText, $infoArr, null, $pixelCeiling);
	}

	/**
	 * Loads a drawing as XML, as strictly as any XML is read here.
	 *
	 * @param String $nakedContent The drawing.
	 * @return \DOMDocument The loaded drawing.
	 *
	 * @throws \RuntimeException When the drawing is not well-formed, declares entities, or is not a drawing.
	 */
	protected static function loadDrawingXml(String $nakedContent): \DOMDocument {
		// Declared entities are how an XML document reads local files or grows without end.
		// They are looked for in the text once decoded : another encoding would hide them
		$xmlText = Parsers\Base::recodeTextUtf8($nakedContent);
		if( !class_exists('DOMDocument') || stripos($xmlText, '<!ENTITY')!==false ){
			throw new \RuntimeException('Not a picture of a known kind');
		}
		$domTree = new \DOMDocument();
		$oldMode = libxml_use_internal_errors(true);
		$lodOkay = $domTree->loadXML($xmlText, LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($oldMode);
		// Whatever the text looked like, a drawing that declares entities once read is refused
		if( $lodOkay && $domTree->doctype!==null && $domTree->doctype->entities!==null && $domTree->doctype->entities->length>0 ){
			throw new \RuntimeException('Not a picture of a known kind');
		}
		if( !$lodOkay || $domTree->documentElement===null || strtolower(strval($domTree->documentElement->localName))!='svg' ){
			throw new \RuntimeException('Not a picture of a known kind');
		}
		return $domTree;
	}
	
	/**
	 * Opens a drawing: its size is the one it tells in pixels, or else
	 * the one of the box it is drawn in, or else a size given to it.
	 *
	 * @param String $nakedContent The drawing.
	 * @param Int    $pixelCeiling Greatest number of pixels accepted.
	 * @return Image The opened drawing.
	 *
	 * @throws \RuntimeException When the content is not a drawing, or tells more pixels than accepted.
	 */
	protected static function openADrawImage(String $nakedContent, Int $pixelCeiling): Image {
		$rootTag = self::loadDrawingXml($nakedContent)->documentElement;
		$sizeLst = Array();
		$boxPrts = preg_split('/[\s,]+/', trim($rootTag->getAttribute('viewBox')));
		foreach(Array('width'=>2, 'height'=>3) as $atrNme=>$boxIdx){
			$sizPart = Array();
			// A size in pixels is the size; in any other unit, the box says how the drawing is proportioned
			if( preg_match('/^(\d+(?:\.\d+)?)(?:px)?$/D', trim($rootTag->getAttribute($atrNme)), $sizPart) ){
				$sizeLst[] = floatval($sizPart[1]);
			}elseif( count($boxPrts)==4 && is_numeric($boxPrts[$boxIdx]) ){
				$sizeLst[] = floatval($boxPrts[$boxIdx]);
			}else{
				$sizeLst[] = 0.0;
			}
		}
		if( $sizeLst[0]<=0 || $sizeLst[1]<=0 ){
			// No size told: its longer side is given one, its proportions being those of its box when it has one
			$boxRati = (count($boxPrts)==4 && floatval($boxPrts[2])>0 && floatval($boxPrts[3])>0) ? floatval($boxPrts[2])/floatval($boxPrts[3]) : 1.0;
			$sizeLst = ($boxRati>=1) ? Array(self::DFLT_SIDE, self::DFLT_SIDE/$boxRati) : Array(self::DFLT_SIDE*$boxRati, self::DFLT_SIDE);
		}
		$wideNum = max(1, intval(ceil($sizeLst[0])));
		$tallNum = max(1, intval(ceil($sizeLst[1])));
		if( $wideNum*$tallNum>$pixelCeiling ){
			throw new \RuntimeException('Picture larger than the '.$pixelCeiling.' pixels allowed');
		}
		return new Image($nakedContent, Array('kinds'=>'svg', 'width'=>$wideNum, 'heght'=>$tallNum, 'bytes'=>strlen($nakedContent), 'turns'=>1, 'exifs'=>Array()), null, $pixelCeiling);
	}
	
	/**
	 * Gives a drawing cleaned of what runs and of what reaches outside
	 * it: scripts and the elements holding a document of another kind,
	 * what is run when something happens to an element, the addresses
	 * and the styles pointing elsewhere than inside the drawing. A link
	 * to a page is kept when it is safe to follow, and so is a picture
	 * written inside the drawing itself.
	 *
	 * @return String The drawing, as XML.
	 *
	 * @throws \RuntimeException When the picture is not a drawing.
	 */
	public function dropRiskyParts(): String {
		if( $this->detailList['kinds']!='svg' || $this->nakedBytes==='' ){
			throw new \RuntimeException('Only a drawing is cleaned up: any other picture is written anew when it is given back');
		}
		$domTree = self::loadDrawingXml($this->nakedBytes);
		// What stands around the drawing: the kind of document it says it is, and what it asks a browser to load
		$dropLst = Array();
		foreach((new \DOMXPath($domTree))->query('//processing-instruction()|//comment()') as $oneNod){
			$dropLst[] = $oneNod;
		}
		if( $domTree->doctype!==null ){
			$dropLst[] = $domTree->doctype;
		}
		foreach($domTree->getElementsByTagName('*') as $oneTag){
			$nodeNme = strtolower(strval($oneTag->localName));
			$animAtr = strtolower($oneTag->getAttribute('attributeName'));
			// What runs, what holds another document, a style reaching outside, and what would write an address or a script later on
			if( in_array($nodeNme, self::DROP_LIST) || ($nodeNme=='style' && preg_match(self::RISK_PATT, self::decodeStyleTxt($oneTag->textContent))) || ($animAtr!=='' && preg_match('/^on|href$/', $animAtr)) ){
				$dropLst[] = $oneTag;
				continue;
			}
			$attrSet = Array();
			foreach($oneTag->attributes as $attrib){
				$attrSet[] = $attrib;
			}
			foreach($attrSet as $attrib){
				$attrKey = strtolower(strval($attrib->localName));
				$attrTxt = trim($attrib->value);
				$badAttr = (strncmp($attrKey, 'on', 2)===0 || preg_match(self::RISK_PATT, self::decodeStyleTxt($attrTxt))===1);
				// What a form or a ping would send elsewhere : kept only when it points inside the drawing
				if( !$badAttr && in_array($attrKey, Array('action', 'formaction', 'ping'), true) ){
					$badAttr = (strncmp($attrTxt, '#', 1)!==0);
				}
				if( !$badAttr && ($attrKey=='href' || $attrKey=='src') ){
					// An address stays inside the drawing; a link may lead to a page, a picture may be written in place
					$badAttr = !(strncmp($attrTxt, '#', 1)===0 || ($nodeNme=='a' && Shape::tidyLinkString($attrTxt)!=='') || ($nodeNme=='image' && preg_match('/^data:image\/(?:png|jpeg|gif|webp);base64,[A-Za-z0-9+\/=\s]+$/D', $attrTxt)));
				}
				if( $badAttr ){
					$oneTag->removeAttributeNode($attrib);
				}
			}
		}
		foreach($dropLst as $oneNod){
			if( $oneNod->parentNode!==null ){
				$oneNod->parentNode->removeChild($oneNod);
			}
		}
		// The drawing is written in UTF-8, its accents as they are
		$domTree->encoding = 'UTF-8';
		return strval($domTree->saveXML());
	}
	
	/**
	 * Gives a style as a browser reads it, for RISKPATT to be matched on:
	 * its comments removed, and its escapes decoded (a backslash followed
	 * by 1 to 6 hexadecimal digits and one optional space, or by any other
	 * character), so that 'u\72l(' is read as 'url('.
	 *
	 * @param String $styleOrValue The style, or the value of an attribute.
	 * @return String The style, plain.
	 */
	protected static function decodeStyleTxt( String $styleOrValue ): String {
		$cssText = strval( preg_replace('#/\*.*?(\*/|$)#s', '', $styleOrValue) );
		return strval( preg_replace_callback('/\\\\(?:([0-9a-fA-F]{1,6})[ \t\r\n\f]?|(.))/su', function( Array $escapeGroups ): String {
			if( ($escapeGroups[1] ?? '')!=='' ){
				// Written as an entity and decoded : no need of mbstring
				$codePnt = intval(hexdec($escapeGroups[1]));
				return ($codePnt>0 && $codePnt<=0x10FFFF) ? html_entity_decode('&#'.$codePnt.';', ENT_QUOTES | ENT_HTML5, 'UTF-8') : "\u{FFFD}";
			}
			return strval($escapeGroups[2] ?? '');
		}, $cssText) );
	}

	/**
	 * Keeps, of what a camera recorded, what is worth telling.
	 *
	 * @param Array $exifReadings What PHP read from the picture.
	 * @return Array The date it was taken under 'dated', the camera under 'maker' and 'model', and the place under 'gpsla' and 'gpslo', in degrees; each only when the picture tells it.
	 */
	protected static function exifFactsArray(Array $exifReadings): Array {
		$factLst = Array();
		$dateLst = Array();
		if( preg_match('/^(\d{4}):(\d\d):(\d\d) (\d\d:\d\d:\d\d)$/D', strval($exifReadings['DateTimeOriginal'] ?? $exifReadings['DateTime'] ?? ''), $dateLst) ){
			$factLst['dated'] = $dateLst[1].'-'.$dateLst[2].'-'.$dateLst[3].' '.$dateLst[4];
		}
		foreach(Array('Make'=>'maker', 'Model'=>'model') as $tagKey=>$factId){
			$factTxt = trim(preg_replace('/[\x00-\x1F\x7F]/', '', strval(is_scalar($exifReadings[$tagKey] ?? null) ? $exifReadings[$tagKey] : '')));
			if( $factTxt!=='' ){
				$factLst[$factId] = $factTxt;
			}
		}
		// A place is written as degrees, minutes and seconds, each a fraction, and the side of the globe it is on
		foreach(Array('GPSLatitude'=>'gpsla', 'GPSLongitude'=>'gpslo') as $tagKey=>$factId){
			$partLst = $exifReadings[$tagKey] ?? null;
			if( !is_array($partLst) || count($partLst)!=3 ){
				continue;
			}
			$degrees = 0.0;
			foreach(array_values($partLst) as $prtInc=>$subTxt){
				$decimal = explode('/', strval($subTxt)) + Array('0', '1');
				$degrees += (floatval($decimal[1])==0.0) ? 0.0 : floatval($decimal[0])/floatval($decimal[1])/pow(60, $prtInc);
			}
			$factLst[$factId] = round(in_array(strtoupper(strval($exifReadings[$tagKey.'Ref'] ?? '')), Array('S', 'W')) ? -$degrees : $degrees, 6);
		}
		return $factLst;
	}

	/**
	 * Tells what the picture is.
	 *
	 * @return Array Its kind under 'kinds' (png, jpg, gif, webp, bmp, avif or svg), its width and its height in pixels under 'width' and 'heght', the size of the content it was opened from under 'bytes' (0 for a picture made from another), the way up the camera recorded under 'turns' (1 when it stands the right way up, or tells nothing), and what else the camera recorded under 'exifs'.
	 */
	public function imageInfoArray(): Array {
		return $this->detailList;
	}

	/**
	 * Gives the picture as gd holds it, unpacking it the first time.
	 *
	 * @return mixed The picture.
	 *
	 * @throws \RuntimeException When the gd extension is not loaded, or the picture cannot be unpacked.
	 */
	protected function getGdOfPicture(): Mixed {
		if( $this->gdInstance!==null ){
			return $this->gdInstance;
		}
		if( !function_exists('imagecreatefromstring') ){
			throw new \RuntimeException('Pictures need the gd extension of PHP to be changed');
		}
		$pxlText = $this->nakedBytes;
		if( $this->detailList['kinds']=='svg' ){
			// A drawing is drawn into pixels first, once cleaned: what draws it must find nothing to fetch
			if( !class_exists('Imagick') ){
				throw new \RuntimeException('A drawing needs the imagick extension of PHP to be drawn into pixels');
			}
			try{
				$drawObj = new \Imagick();
				$drawObj->setBackgroundColor(new \ImagickPixel('transparent'));
				// What draws it is told the size to draw it at: a drawing may tell none, or tell it in another unit than pixels
				$sizdDoc = self::loadDrawingXml($this->dropRiskyParts());
				$sizdDoc->documentElement->setAttribute('width', strval($this->detailList['width']));
				$sizdDoc->documentElement->setAttribute('height', strval($this->detailList['heght']));
				$sizdDoc->encoding = 'UTF-8';
				$drawObj->readImageBlob(strval($sizdDoc->saveXML()));
				$drawObj->setImageFormat('png32');
				if( $drawObj->getImageWidth()*$drawObj->getImageHeight()>$this->pixelLimit ){
					throw new \RuntimeException('Picture larger than the '.$this->pixelLimit.' pixels allowed');
				}
				$pxlText = $drawObj->getImageBlob();
				$drawObj->clear();
			}catch( \ImagickException $e ){
				throw new \RuntimeException('Drawing that cannot be drawn into pixels', 0, $e);
			}
		}
		$gdImage = @imagecreatefromstring($pxlText);
		if( $gdImage===false ){
			throw new \RuntimeException('Picture that cannot be read: its content is damaged, or of a kind this server does not read');
		}
		// A picture drawn with a few colours is brought to full colour, so that it can be resized without losing them
		if( !imageistruecolor($gdImage) ){
			imagepalettetotruecolor($gdImage);
		}
		imagealphablending($gdImage, false);
		imagesavealpha($gdImage, true);
		$this->gdInstance = $gdImage;
		if( $this->detailList['kinds']=='svg' && (imagesx($gdImage)!=$this->detailList['width'] || imagesy($gdImage)!=$this->detailList['heght']) ){
			// What drew it chose another size than the one the drawing tells: it is brought to that size
			$sizdImg = $this->makesFromImage($this->detailList['width'], $this->detailList['heght'], 0, 0, imagesx($gdImage), imagesy($gdImage));
			$this->gdInstance = $sizdImg->gdInstance;
		}
		return $this->gdInstance;
	}

	/**
	 * Makes a picture of a size from a part of this one.
	 *
	 * @param Int $widthAskedNb Width of the picture to make.
	 * @param Int $heightWanted Height of the picture to make.
	 * @param Int $sourceLftNum Left of the part taken from this one.
	 * @param Int $sourceTopsNb Top of that part.
	 * @param Int $sourceWideNb Width of that part.
	 * @param Int $sourceTallNb Height of that part.
	 * @return Image The picture made.
	 *
	 * @throws \RuntimeException When a size is not positive, or the picture to make holds more pixels than accepted.
	 */
	protected function makesFromImage(Int $widthAskedNb, Int $heightWanted, Int $sourceLftNum, Int $sourceTopsNb, Int $sourceWideNb, Int $sourceTallNb): Image {
		if( $widthAskedNb<1 || $heightWanted<1 ){
			throw new \RuntimeException('A picture is at least one pixel wide and one pixel high');
		}
		if( $widthAskedNb*$heightWanted>$this->pixelLimit ){
			throw new \RuntimeException('Picture larger than the '.$this->pixelLimit.' pixels allowed');
		}
		$fromImg = $this->getGdOfPicture();
		$madeImg = imagecreatetruecolor($widthAskedNb, $heightWanted);
		// What is see-through stays so
		imagealphablending($madeImg, false);
		imagesavealpha($madeImg, true);
		imagefill($madeImg, 0, 0, imagecolorallocatealpha($madeImg, 0, 0, 0, 127));
		imagecopyresampled($madeImg, $fromImg, 0, 0, $sourceLftNum, $sourceTopsNb, $widthAskedNb, $heightWanted, max(1, $sourceWideNb), max(1, $sourceTallNb));
		// What was known of this picture is known of the one made from it
		// A picture made from a drawing is made of pixels
		return new Image('', array_merge($this->detailList, Array('kinds'=>($this->detailList['kinds']=='svg') ? 'png' : $this->detailList['kinds'], 'width'=>$widthAskedNb, 'heght'=>$heightWanted, 'bytes'=>0)), $madeImg, $this->pixelLimit);
	}

	/**
	 * Makes a picture that fits inside a width and a height, its
	 * proportions kept. A picture that already fits is given as it is,
	 * unless it may be enlarged.
	 *
	 * @param Int  $widthCeiling Greatest width of the picture to make.
	 * @param Int  $heightCapNum Greatest height of the picture to make.
	 * @param Bool $allowEnlarge Whether a smaller picture is enlarged to fill the width or the height.
	 * @return Image The picture made.
	 *
	 * @throws \RuntimeException When a limit is not positive, the picture to make is too large, or gd is missing.
	 */
	public function resizeFitImage(Int $widthCeiling, Int $heightCapNum, Bool $allowEnlarge=false): Image {
		if( $widthCeiling<1 || $heightCapNum<1 ){
			throw new \RuntimeException('A picture is at least one pixel wide and one pixel high');
		}
		$scaleTo = min($widthCeiling/$this->detailList['width'], $heightCapNum/$this->detailList['heght']);
		if( $scaleTo>=1 && !$allowEnlarge ){
			$scaleTo = 1;
		}
		return $this->makesFromImage(max(1, intval(round($this->detailList['width']*$scaleTo))), max(1, intval(round($this->detailList['heght']*$scaleTo))), 0, 0, $this->detailList['width'], $this->detailList['heght']);
	}

	/**
	 * Makes a picture of exactly a width and a height: this one is
	 * brought to cover them, and what goes over is cut off evenly on
	 * both sides.
	 *
	 * @param Int $widthAskedNb Width of the picture to make.
	 * @param Int $heightWanted Height of the picture to make.
	 * @return Image The picture made.
	 *
	 * @throws \RuntimeException When a size is not positive, the picture to make is too large, or gd is missing.
	 */
	public function cropCoverImage(Int $widthAskedNb, Int $heightWanted): Image {
		if( $widthAskedNb<1 || $heightWanted<1 ){
			throw new \RuntimeException('A picture is at least one pixel wide and one pixel high');
		}
		$scaleBy = max($widthAskedNb/$this->detailList['width'], $heightWanted/$this->detailList['heght']);
		$partWde = min($this->detailList['width'], max(1, intval(round($widthAskedNb/$scaleBy))));
		$partTll = min($this->detailList['heght'], max(1, intval(round($heightWanted/$scaleBy))));
		return $this->makesFromImage($widthAskedNb, $heightWanted, intdiv($this->detailList['width']-$partWde, 2), intdiv($this->detailList['heght']-$partTll, 2), $partWde, $partTll);
	}

	/**
	 * Makes a picture of this one turned to the right, the way the hands
	 * of a clock go.
	 *
	 * @param Int $angleDegrees The quarter turns, in degrees: 90, 180 or 270, or any of them more or less whole turns.
	 * @return Image The picture made.
	 *
	 * @throws \RuntimeException When the degrees are not quarter turns, or gd is missing.
	 */
	public function turnRightImage(Int $angleDegrees): Image {
		if( $angleDegrees%90!=0 ){
			throw new \RuntimeException('A picture is turned by quarter turns: 90, 180 or 270 degrees');
		}
		$turnDgr = (($angleDegrees%360)+360)%360;
		$madeImg = $this->makesFromImage($this->detailList['width'], $this->detailList['heght'], 0, 0, $this->detailList['width'], $this->detailList['heght']);
		if( $turnDgr==0 ){
			return $madeImg;
		}
		// gd turns to the left: a turn to the right is the same turn the other way
		$turnImg = imagerotate($madeImg->gdInstance, 360-$turnDgr, 0);
		imagealphablending($turnImg, false);
		imagesavealpha($turnImg, true);
		return new Image('', array_merge($madeImg->detailList, Array('width'=>imagesx($turnImg), 'heght'=>imagesy($turnImg))), $turnImg, $this->pixelLimit);
	}

	/**
	 * Makes a picture standing the right way up, from the way up the
	 * camera recorded. A picture that tells nothing, or already stands
	 * right, is given as it is.
	 *
	 * @return Image The picture made, its 'turns' being 1.
	 *
	 * @throws \RuntimeException When gd is missing.
	 */
	public function fixOrientation(): Image {
		$turnVal = intval($this->detailList['turns']);
		// The eight ways up: the quarter turns to the right that set each one upright, and whether it is then seen in a mirror
		$turnSet = Array(1=>Array(0, false), 2=>Array(0, true), 3=>Array(180, false), 4=>Array(180, true), 5=>Array(90, true), 6=>Array(90, false), 7=>Array(270, true), 8=>Array(270, false))[$turnVal] ?? Array(0, false);
		$madeImg = $this->turnRightImage($turnSet[0]);
		if( $turnSet[1] ){
			imageflip($madeImg->gdInstance, IMG_FLIP_HORIZONTAL);
		}
		$madeImg->detailList['turns'] = 1;
		return $madeImg;
	}

	/**
	 * Gives the picture as the bytes of a kind. It is written anew:
	 * nothing the camera recorded is kept.
	 *
	 * @param String $pictureKinds Kind to write: png, jpg, gif, webp, bmp or avif, or svg for a drawing; the kind of the picture when left out.
	 * @param Int    $qualityLevel For the kinds that lose some of the picture to weigh less, how much of it is kept, from 1 to 100.
	 * @return String The bytes.
	 *
	 * @throws \RuntimeException When the kind is not one this server writes, or gd is missing.
	 */
	public function grantBytesText(String $pictureKinds='', Int $qualityLevel=85): String {
		$kindKey = strtolower(($pictureKinds==='') ? $this->detailList['kinds'] : $pictureKinds);
		$kindKey = ($kindKey=='jpeg') ? 'jpg' : $kindKey;
		if( $kindKey=='svg' ){
			// A drawing is given back as the drawing it is, cleaned; pixels cannot be made a drawing again
			return $this->dropRiskyParts();
		}
		$gdFuncs = Array('png'=>'imagepng', 'jpg'=>'imagejpeg', 'gif'=>'imagegif', 'webp'=>'imagewebp', 'bmp'=>'imagebmp', 'avif'=>'imageavif');
		$gdImage = $this->getGdOfPicture();
		if( !isset($gdFuncs[$kindKey]) || !function_exists($gdFuncs[$kindKey]) ){
			throw new \RuntimeException('Kind of picture this server does not write: '.$kindKey);
		}
		$quality = max(1, min(100, $qualityLevel));
		if( $kindKey=='jpg' || $kindKey=='bmp' ){
			// These kinds have nothing see-through: the picture is laid on white
			$flatImg = imagecreatetruecolor(imagesx($gdImage), imagesy($gdImage));
			imagefill($flatImg, 0, 0, imagecolorallocate($flatImg, 255, 255, 255));
			imagecopy($flatImg, $gdImage, 0, 0, 0, 0, imagesx($gdImage), imagesy($gdImage));
			$gdImage = $flatImg;
		}
		ob_start();
		if( $kindKey=='jpg' || $kindKey=='webp' || $kindKey=='avif' ){
			$donOkay = $gdFuncs[$kindKey]($gdImage, null, $quality);
		}else{
			$donOkay = $gdFuncs[$kindKey]($gdImage);
		}
		$outText = strval(ob_get_clean());
		if( !$donOkay || $outText==='' ){
			throw new \RuntimeException('Picture that cannot be written as '.$kindKey);
		}
		return $outText;
	}

	/**
	 * Writes the picture to a file.
	 *
	 * @param String $saveFilePath Path of the file to write.
	 * @param String $pictureKinds Kind to write; the kind of the picture when left out.
	 * @param Int    $qualityLevel How much of the picture is kept, from 1 to 100, for the kinds that lose some.
	 * @return String The path of the file.
	 *
	 * @throws \RuntimeException When the file cannot be written, the kind is not one this server writes, or gd is missing.
	 */
	public function writeLocalFile(String $saveFilePath, String $pictureKinds='', Int $qualityLevel=85): String {
		if( @file_put_contents($saveFilePath, $this->grantBytesText($pictureKinds, $qualityLevel))===false ){
			throw new \RuntimeException('File cannot be written: '.basename($saveFilePath));
		}
		return $saveFilePath;
	}

}
