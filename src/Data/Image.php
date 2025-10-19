<?php

namespace nyu\Data;

use nyu\Data\Parsers\Bina;

// A picture: what it is, told from its content, and the pictures that
// can be made of it, smaller, cut, turned or of another kind.
//
// A picture is never changed: resizing, cutting or turning it gives
// another one. A picture given back is written anew, without what the
// camera recorded in it: neither the date nor the place it was taken.
// The gd extension of PHP makes the pictures; telling what a picture is
// does not need it.
//
// A drawing (SVG) is a picture too. It is text that may carry scripts
// and point outside itself: it is given back cleaned of both, and it is
// cleaned before it is drawn. Drawing it into pixels, to resize it or to
// give it as another kind, needs the imagick extension.
class Image {

	// Greatest number of pixels a picture may have unless told otherwise: 32 millions
	const MAXPIXEL = 32000000;
	// Greatest size read from a source unless told otherwise: 32 MB
	const MAXBYTES = 33554432;
	// The kinds of picture read, by the number PHP gives them
	const KINDLIST = Array(1=>'gif', 2=>'jpg', 3=>'png', 6=>'bmp', 18=>'webp', 19=>'avif');
	// The longer side given to a drawing that tells no size, in pixels
	const DFLTSIDE = 512;
	// The elements taken out of a drawing: what runs, and what holds a document of another kind
	const DROPLIST = Array('script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'handler', 'listener', 'set');
	// What makes a style, or a value, reach outside the drawing or run
	const RISKPATT = '/@import|expression\s*\(|behavior\s*:|-moz-binding|j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t\s*:|url\s*\(\s*(?!["\']?\s*#)/i';

	protected $rawBytes;
	protected $infoList;
	protected $gdHandle;
	protected $pixelMax;

	/**
	 * Private: a picture is opened through openFluxd(), or made from
	 * another one.
	 *
	 * @param String $rawContent The bytes of the picture, '' for one made from another.
	 * @param Array  $infosArray What the picture is, as imageInfo() gives it.
	 * @param mixed  $gdResource The picture as gd holds it, null as long as it is not needed.
	 * @param Int    $pixelLimit Greatest number of pixels accepted.
	 */
	private function __construct(String $rawContent, Array $infosArray, Mixed $gdResource, Int $pixelLimit){
		$this->rawBytes = $rawContent;
		$this->infoList = $infosArray;
		$this->gdHandle = $gdResource;
		$this->pixelMax = $pixelLimit;
	}

	/**
	 * Opens a picture from a source. What it is, and its size, are read
	 * from its first bytes: a picture larger than the limit is refused
	 * before anything of it is unpacked.
	 *
	 * @param Fluxd $sourceFlux The source.
	 * @param Int   $pixelLimit Greatest number of pixels accepted, its width multiplied by its height.
	 * @param Int   $bytesLimit Greatest size read from the source, in bytes.
	 * @return Image The opened picture.
	 *
	 * @throws \RuntimeException When the source is larger than the limit, is not a picture of a known kind, or holds more pixels than accepted.
	 */
	public static function openFluxd(Fluxd $sourceFlux, Int $pixelLimit=self::MAXPIXEL, Int $bytesLimit=self::MAXBYTES): Image {
		$rawText = $sourceFlux->readWhole($bytesLimit);
		if( Bina::signature(substr($rawText, 0, 512))=='svg' ){
			return self::openADraw($rawText, $pixelLimit);
		}
		$sizeArr = ($rawText==='') ? false : @getimagesizefromstring($rawText);
		if( $sizeArr===false || !isset(self::KINDLIST[$sizeArr[2]]) || $sizeArr[0]<1 || $sizeArr[1]<1 ){
			throw new \RuntimeException('Not a picture of a known kind');
		}
		if( $sizeArr[0]*$sizeArr[1]>$pixelLimit ){
			throw new \RuntimeException('Picture larger than the '.$pixelLimit.' pixels allowed');
		}
		$infoArr = Array('kinds'=>self::KINDLIST[$sizeArr[2]], 'width'=>intval($sizeArr[0]), 'heght'=>intval($sizeArr[1]), 'bytes'=>strlen($rawText), 'turns'=>1, 'exifs'=>Array());
		// What the camera recorded, when the picture is of a kind that holds it and PHP can read it
		if( $infoArr['kinds']=='jpg' && function_exists('exif_read_data') ){
			$memStrm = fopen('php://memory', 'r+b');
			fwrite($memStrm, $rawText);
			rewind($memStrm);
			$exifArr = @exif_read_data($memStrm);
			fclose($memStrm);
			if( is_array($exifArr) ){
				$turnVal = intval($exifArr['Orientation'] ?? 1);
				$infoArr['turns'] = ($turnVal>=1 && $turnVal<=8) ? $turnVal : 1;
				$infoArr['exifs'] = self::exifFacts($exifArr);
			}
		}
		return new Image($rawText, $infoArr, null, $pixelLimit);
	}

	/**
	 * Loads a drawing as XML, as strictly as any XML is read here.
	 *
	 * @param String $rawContent The drawing.
	 * @return \DOMDocument The loaded drawing.
	 *
	 * @throws \RuntimeException When the drawing is not well-formed, declares entities, or is not a drawing.
	 */
	protected static function loadADraw(String $rawContent): \DOMDocument {
		// Declared entities are how an XML document reads local files or grows without end.
		// They are looked for in the text once decoded : another encoding would hide them
		$xmlText = Parsers\Base::cleanText($rawContent);
		if( !class_exists('DOMDocument') || stripos($xmlText, '<!ENTITY')!==false ){
			throw new \RuntimeException('Not a picture of a known kind');
		}
		$domDocu = new \DOMDocument();
		$oldMode = libxml_use_internal_errors(true);
		$loadOky = $domDocu->loadXML($xmlText, LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($oldMode);
		// Whatever the text looked like, a drawing that declares entities once read is refused
		if( $loadOky && $domDocu->doctype!==null && $domDocu->doctype->entities!==null && $domDocu->doctype->entities->length>0 ){
			throw new \RuntimeException('Not a picture of a known kind');
		}
		if( !$loadOky || $domDocu->documentElement===null || strtolower(strval($domDocu->documentElement->localName))!='svg' ){
			throw new \RuntimeException('Not a picture of a known kind');
		}
		return $domDocu;
	}
	
	/**
	 * Opens a drawing: its size is the one it tells in pixels, or else
	 * the one of the box it is drawn in, or else a size given to it.
	 *
	 * @param String $rawContent The drawing.
	 * @param Int    $pixelLimit Greatest number of pixels accepted.
	 * @return Image The opened drawing.
	 *
	 * @throws \RuntimeException When the content is not a drawing, or tells more pixels than accepted.
	 */
	protected static function openADraw(String $rawContent, Int $pixelLimit): Image {
		$rootElm = self::loadADraw($rawContent)->documentElement;
		$sizeLst = Array();
		$boxPrts = preg_split('/[\s,]+/', trim($rootElm->getAttribute('viewBox')));
		foreach(Array('width'=>2, 'height'=>3) as $atrNme=>$boxIdx){
			$sizePrt = Array();
			// A size in pixels is the size; in any other unit, the box says how the drawing is proportioned
			if( preg_match('/^(\d+(?:\.\d+)?)(?:px)?$/D', trim($rootElm->getAttribute($atrNme)), $sizePrt) ){
				$sizeLst[] = floatval($sizePrt[1]);
			}elseif( count($boxPrts)==4 && is_numeric($boxPrts[$boxIdx]) ){
				$sizeLst[] = floatval($boxPrts[$boxIdx]);
			}else{
				$sizeLst[] = 0.0;
			}
		}
		if( $sizeLst[0]<=0 || $sizeLst[1]<=0 ){
			// No size told: its longer side is given one, its proportions being those of its box when it has one
			$boxRato = (count($boxPrts)==4 && floatval($boxPrts[2])>0 && floatval($boxPrts[3])>0) ? floatval($boxPrts[2])/floatval($boxPrts[3]) : 1.0;
			$sizeLst = ($boxRato>=1) ? Array(self::DFLTSIDE, self::DFLTSIDE/$boxRato) : Array(self::DFLTSIDE*$boxRato, self::DFLTSIDE);
		}
		$wideNbr = max(1, intval(ceil($sizeLst[0])));
		$tallNbr = max(1, intval(ceil($sizeLst[1])));
		if( $wideNbr*$tallNbr>$pixelLimit ){
			throw new \RuntimeException('Picture larger than the '.$pixelLimit.' pixels allowed');
		}
		return new Image($rawContent, Array('kinds'=>'svg', 'width'=>$wideNbr, 'heght'=>$tallNbr, 'bytes'=>strlen($rawContent), 'turns'=>1, 'exifs'=>Array()), null, $pixelLimit);
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
	public function cleanedUp(): String {
		if( $this->infoList['kinds']!='svg' || $this->rawBytes==='' ){
			throw new \RuntimeException('Only a drawing is cleaned up: any other picture is written anew when it is given back');
		}
		$domDocu = self::loadADraw($this->rawBytes);
		// What stands around the drawing: the kind of document it says it is, and what it asks a browser to load
		$dropLst = Array();
		foreach((new \DOMXPath($domDocu))->query('//processing-instruction()|//comment()') as $oneNod){
			$dropLst[] = $oneNod;
		}
		if( $domDocu->doctype!==null ){
			$dropLst[] = $domDocu->doctype;
		}
		foreach($domDocu->getElementsByTagName('*') as $oneElm){
			$elemNme = strtolower(strval($oneElm->localName));
			$animAtr = strtolower($oneElm->getAttribute('attributeName'));
			// What runs, what holds another document, a style reaching outside, and what would write an address or a script later on
			if( in_array($elemNme, self::DROPLIST) || ($elemNme=='style' && preg_match(self::RISKPATT, $oneElm->textContent)) || ($animAtr!=='' && preg_match('/^on|href$/', $animAtr)) ){
				$dropLst[] = $oneElm;
				continue;
			}
			$attrLst = Array();
			foreach($oneElm->attributes as $oneAtr){
				$attrLst[] = $oneAtr;
			}
			foreach($attrLst as $oneAtr){
				$attrNme = strtolower(strval($oneAtr->localName));
				$attrVal = trim($oneAtr->value);
				$dropAtr = (strncmp($attrNme, 'on', 2)===0 || preg_match(self::RISKPATT, $attrVal)===1);
				if( !$dropAtr && ($attrNme=='href' || $attrNme=='src') ){
					// An address stays inside the drawing; a link may lead to a page, a picture may be written in place
					$dropAtr = !(strncmp($attrVal, '#', 1)===0 || ($elemNme=='a' && Shape::cleanLink($attrVal)!=='') || ($elemNme=='image' && preg_match('/^data:image\/(?:png|jpeg|gif|webp);base64,[A-Za-z0-9+\/=\s]+$/D', $attrVal)));
				}
				if( $dropAtr ){
					$oneElm->removeAttributeNode($oneAtr);
				}
			}
		}
		foreach($dropLst as $oneNod){
			if( $oneNod->parentNode!==null ){
				$oneNod->parentNode->removeChild($oneNod);
			}
		}
		// The drawing is written in UTF-8, its accents as they are
		$domDocu->encoding = 'UTF-8';
		return strval($domDocu->saveXML());
	}
	
	/**
	 * Keeps, of what a camera recorded, what is worth telling.
	 *
	 * @param Array $exifsArray What PHP read from the picture.
	 * @return Array The date it was taken under 'dated', the camera under 'maker' and 'model', and the place under 'gpsla' and 'gpslo', in degrees; each only when the picture tells it.
	 */
	protected static function exifFacts(Array $exifsArray): Array {
		$factLst = Array();
		$datePrt = Array();
		if( preg_match('/^(\d{4}):(\d\d):(\d\d) (\d\d:\d\d:\d\d)$/D', strval($exifsArray['DateTimeOriginal'] ?? $exifsArray['DateTime'] ?? ''), $datePrt) ){
			$factLst['dated'] = $datePrt[1].'-'.$datePrt[2].'-'.$datePrt[3].' '.$datePrt[4];
		}
		foreach(Array('Make'=>'maker', 'Model'=>'model') as $exfKey=>$fctKey){
			$factTxt = trim(preg_replace('/[\x00-\x1F\x7F]/', '', strval(is_scalar($exifsArray[$exfKey] ?? null) ? $exifsArray[$exfKey] : '')));
			if( $factTxt!=='' ){
				$factLst[$fctKey] = $factTxt;
			}
		}
		// A place is written as degrees, minutes and seconds, each a fraction, and the side of the globe it is on
		foreach(Array('GPSLatitude'=>'gpsla', 'GPSLongitude'=>'gpslo') as $exfKey=>$fctKey){
			$partLst = $exifsArray[$exfKey] ?? null;
			if( !is_array($partLst) || count($partLst)!=3 ){
				continue;
			}
			$degrees = 0.0;
			foreach(array_values($partLst) as $prtIdx=>$prtTxt){
				$fracPrt = explode('/', strval($prtTxt)) + Array('0', '1');
				$degrees += (floatval($fracPrt[1])==0.0) ? 0.0 : floatval($fracPrt[0])/floatval($fracPrt[1])/pow(60, $prtIdx);
			}
			$factLst[$fctKey] = round(in_array(strtoupper(strval($exifsArray[$exfKey.'Ref'] ?? '')), Array('S', 'W')) ? -$degrees : $degrees, 6);
		}
		return $factLst;
	}

	/**
	 * Tells what the picture is.
	 *
	 * @return Array Its kind under 'kinds' (png, jpg, gif, webp, bmp, avif or svg), its width and its height in pixels under 'width' and 'heght', the size of the content it was opened from under 'bytes' (0 for a picture made from another), the way up the camera recorded under 'turns' (1 when it stands the right way up, or tells nothing), and what else the camera recorded under 'exifs'.
	 */
	public function imageInfo(): Array {
		return $this->infoList;
	}

	/**
	 * Gives the picture as gd holds it, unpacking it the first time.
	 *
	 * @return mixed The picture.
	 *
	 * @throws \RuntimeException When the gd extension is not loaded, or the picture cannot be unpacked.
	 */
	protected function gdOfImage(): Mixed {
		if( $this->gdHandle!==null ){
			return $this->gdHandle;
		}
		if( !function_exists('imagecreatefromstring') ){
			throw new \RuntimeException('Pictures need the gd extension of PHP to be changed');
		}
		$pixlTxt = $this->rawBytes;
		if( $this->infoList['kinds']=='svg' ){
			// A drawing is drawn into pixels first, once cleaned: what draws it must find nothing to fetch
			if( !class_exists('Imagick') ){
				throw new \RuntimeException('A drawing needs the imagick extension of PHP to be drawn into pixels');
			}
			try{
				$drawObj = new \Imagick();
				$drawObj->setBackgroundColor(new \ImagickPixel('transparent'));
				// What draws it is told the size to draw it at: a drawing may tell none, or tell it in another unit than pixels
				$sizdDoc = self::loadADraw($this->cleanedUp());
				$sizdDoc->documentElement->setAttribute('width', strval($this->infoList['width']));
				$sizdDoc->documentElement->setAttribute('height', strval($this->infoList['heght']));
				$sizdDoc->encoding = 'UTF-8';
				$drawObj->readImageBlob(strval($sizdDoc->saveXML()));
				$drawObj->setImageFormat('png32');
				if( $drawObj->getImageWidth()*$drawObj->getImageHeight()>$this->pixelMax ){
					throw new \RuntimeException('Picture larger than the '.$this->pixelMax.' pixels allowed');
				}
				$pixlTxt = $drawObj->getImageBlob();
				$drawObj->clear();
			}catch( \ImagickException $e ){
				throw new \RuntimeException('Drawing that cannot be drawn into pixels', 0, $e);
			}
		}
		$gdImage = @imagecreatefromstring($pixlTxt);
		if( $gdImage===false ){
			throw new \RuntimeException('Picture that cannot be read: its content is damaged, or of a kind this server does not read');
		}
		// A picture drawn with a few colours is brought to full colour, so that it can be resized without losing them
		if( !imageistruecolor($gdImage) ){
			imagepalettetotruecolor($gdImage);
		}
		imagealphablending($gdImage, false);
		imagesavealpha($gdImage, true);
		$this->gdHandle = $gdImage;
		if( $this->infoList['kinds']=='svg' && (imagesx($gdImage)!=$this->infoList['width'] || imagesy($gdImage)!=$this->infoList['heght']) ){
			// What drew it chose another size than the one the drawing tells: it is brought to that size
			$sizdImg = $this->makesFrom($this->infoList['width'], $this->infoList['heght'], 0, 0, imagesx($gdImage), imagesy($gdImage));
			$this->gdHandle = $sizdImg->gdHandle;
		}
		return $this->gdHandle;
	}

	/**
	 * Makes a picture of a size from a part of this one.
	 *
	 * @param Int $widthAsked Width of the picture to make.
	 * @param Int $heghtAsked Height of the picture to make.
	 * @param Int $sourceLeft Left of the part taken from this one.
	 * @param Int $sourceTops Top of that part.
	 * @param Int $sourceWide Width of that part.
	 * @param Int $sourceTall Height of that part.
	 * @return Image The picture made.
	 *
	 * @throws \RuntimeException When a size is not positive, or the picture to make holds more pixels than accepted.
	 */
	protected function makesFrom(Int $widthAsked, Int $heghtAsked, Int $sourceLeft, Int $sourceTops, Int $sourceWide, Int $sourceTall): Image {
		if( $widthAsked<1 || $heghtAsked<1 ){
			throw new \RuntimeException('A picture is at least one pixel wide and one pixel high');
		}
		if( $widthAsked*$heghtAsked>$this->pixelMax ){
			throw new \RuntimeException('Picture larger than the '.$this->pixelMax.' pixels allowed');
		}
		$srceImg = $this->gdOfImage();
		$madeImg = imagecreatetruecolor($widthAsked, $heghtAsked);
		// What is see-through stays so
		imagealphablending($madeImg, false);
		imagesavealpha($madeImg, true);
		imagefill($madeImg, 0, 0, imagecolorallocatealpha($madeImg, 0, 0, 0, 127));
		imagecopyresampled($madeImg, $srceImg, 0, 0, $sourceLeft, $sourceTops, $widthAsked, $heghtAsked, max(1, $sourceWide), max(1, $sourceTall));
		// What was known of this picture is known of the one made from it
		// A picture made from a drawing is made of pixels
		return new Image('', array_merge($this->infoList, Array('kinds'=>($this->infoList['kinds']=='svg') ? 'png' : $this->infoList['kinds'], 'width'=>$widthAsked, 'heght'=>$heghtAsked, 'bytes'=>0)), $madeImg, $this->pixelMax);
	}

	/**
	 * Makes a picture that fits inside a width and a height, its
	 * proportions kept. A picture that already fits is given as it is,
	 * unless it may be enlarged.
	 *
	 * @param Int  $widthLimit Greatest width of the picture to make.
	 * @param Int  $heghtLimit Greatest height of the picture to make.
	 * @param Bool $mayEnlarge Whether a smaller picture is enlarged to fill the width or the height.
	 * @return Image The picture made.
	 *
	 * @throws \RuntimeException When a limit is not positive, the picture to make is too large, or gd is missing.
	 */
	public function resizeFit(Int $widthLimit, Int $heghtLimit, Bool $mayEnlarge=false): Image {
		if( $widthLimit<1 || $heghtLimit<1 ){
			throw new \RuntimeException('A picture is at least one pixel wide and one pixel high');
		}
		$fitScal = min($widthLimit/$this->infoList['width'], $heghtLimit/$this->infoList['heght']);
		if( $fitScal>=1 && !$mayEnlarge ){
			$fitScal = 1;
		}
		return $this->makesFrom(max(1, intval(round($this->infoList['width']*$fitScal))), max(1, intval(round($this->infoList['heght']*$fitScal))), 0, 0, $this->infoList['width'], $this->infoList['heght']);
	}

	/**
	 * Makes a picture of exactly a width and a height: this one is
	 * brought to cover them, and what goes over is cut off evenly on
	 * both sides.
	 *
	 * @param Int $widthAsked Width of the picture to make.
	 * @param Int $heghtAsked Height of the picture to make.
	 * @return Image The picture made.
	 *
	 * @throws \RuntimeException When a size is not positive, the picture to make is too large, or gd is missing.
	 */
	public function cropCover(Int $widthAsked, Int $heghtAsked): Image {
		if( $widthAsked<1 || $heghtAsked<1 ){
			throw new \RuntimeException('A picture is at least one pixel wide and one pixel high');
		}
		$covrScl = max($widthAsked/$this->infoList['width'], $heghtAsked/$this->infoList['heght']);
		$partWde = min($this->infoList['width'], max(1, intval(round($widthAsked/$covrScl))));
		$partTll = min($this->infoList['heght'], max(1, intval(round($heghtAsked/$covrScl))));
		return $this->makesFrom($widthAsked, $heghtAsked, intdiv($this->infoList['width']-$partWde, 2), intdiv($this->infoList['heght']-$partTll, 2), $partWde, $partTll);
	}

	/**
	 * Makes a picture of this one turned to the right, the way the hands
	 * of a clock go.
	 *
	 * @param Int $degreesNum The quarter turns, in degrees: 90, 180 or 270, or any of them more or less whole turns.
	 * @return Image The picture made.
	 *
	 * @throws \RuntimeException When the degrees are not quarter turns, or gd is missing.
	 */
	public function turnRight(Int $degreesNum): Image {
		if( $degreesNum%90!=0 ){
			throw new \RuntimeException('A picture is turned by quarter turns: 90, 180 or 270 degrees');
		}
		$turnDeg = (($degreesNum%360)+360)%360;
		$madeImg = $this->makesFrom($this->infoList['width'], $this->infoList['heght'], 0, 0, $this->infoList['width'], $this->infoList['heght']);
		if( $turnDeg==0 ){
			return $madeImg;
		}
		// gd turns to the left: a turn to the right is the same turn the other way
		$turnImg = imagerotate($madeImg->gdHandle, 360-$turnDeg, 0);
		imagealphablending($turnImg, false);
		imagesavealpha($turnImg, true);
		return new Image('', array_merge($madeImg->infoList, Array('width'=>imagesx($turnImg), 'heght'=>imagesy($turnImg))), $turnImg, $this->pixelMax);
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
	public function setUprght(): Image {
		$turnVal = intval($this->infoList['turns']);
		// The eight ways up: the quarter turns to the right that set each one upright, and whether it is then seen in a mirror
		$turnSet = Array(1=>Array(0, false), 2=>Array(0, true), 3=>Array(180, false), 4=>Array(180, true), 5=>Array(90, true), 6=>Array(90, false), 7=>Array(270, true), 8=>Array(270, false))[$turnVal] ?? Array(0, false);
		$madeImg = $this->turnRight($turnSet[0]);
		if( $turnSet[1] ){
			imageflip($madeImg->gdHandle, IMG_FLIP_HORIZONTAL);
		}
		$madeImg->infoList['turns'] = 1;
		return $madeImg;
	}

	/**
	 * Gives the picture as the bytes of a kind. It is written anew:
	 * nothing the camera recorded is kept.
	 *
	 * @param String $imageKinds Kind to write: png, jpg, gif, webp, bmp or avif, or svg for a drawing; the kind of the picture when left out.
	 * @param Int    $qualityPct For the kinds that lose some of the picture to weigh less, how much of it is kept, from 1 to 100.
	 * @return String The bytes.
	 *
	 * @throws \RuntimeException When the kind is not one this server writes, or gd is missing.
	 */
	public function giveBytes(String $imageKinds='', Int $qualityPct=85): String {
		$kindKey = strtolower(($imageKinds==='') ? $this->infoList['kinds'] : $imageKinds);
		$kindKey = ($kindKey=='jpeg') ? 'jpg' : $kindKey;
		if( $kindKey=='svg' ){
			// A drawing is given back as the drawing it is, cleaned; pixels cannot be made a drawing again
			return $this->cleanedUp();
		}
		$funcLst = Array('png'=>'imagepng', 'jpg'=>'imagejpeg', 'gif'=>'imagegif', 'webp'=>'imagewebp', 'bmp'=>'imagebmp', 'avif'=>'imageavif');
		$gdImage = $this->gdOfImage();
		if( !isset($funcLst[$kindKey]) || !function_exists($funcLst[$kindKey]) ){
			throw new \RuntimeException('Kind of picture this server does not write: '.$kindKey);
		}
		$keptPct = max(1, min(100, $qualityPct));
		if( $kindKey=='jpg' || $kindKey=='bmp' ){
			// These kinds have nothing see-through: the picture is laid on white
			$flatImg = imagecreatetruecolor(imagesx($gdImage), imagesy($gdImage));
			imagefill($flatImg, 0, 0, imagecolorallocate($flatImg, 255, 255, 255));
			imagecopy($flatImg, $gdImage, 0, 0, 0, 0, imagesx($gdImage), imagesy($gdImage));
			$gdImage = $flatImg;
		}
		ob_start();
		if( $kindKey=='jpg' || $kindKey=='webp' || $kindKey=='avif' ){
			$doneOky = $funcLst[$kindKey]($gdImage, null, $keptPct);
		}else{
			$doneOky = $funcLst[$kindKey]($gdImage);
		}
		$outText = strval(ob_get_clean());
		if( !$doneOky || $outText==='' ){
			throw new \RuntimeException('Picture that cannot be written as '.$kindKey);
		}
		return $outText;
	}

	/**
	 * Writes the picture to a file.
	 *
	 * @param String $targetPath Path of the file to write.
	 * @param String $imageKinds Kind to write; the kind of the picture when left out.
	 * @param Int    $qualityPct How much of the picture is kept, from 1 to 100, for the kinds that lose some.
	 * @return String The path of the file.
	 *
	 * @throws \RuntimeException When the file cannot be written, the kind is not one this server writes, or gd is missing.
	 */
	public function saveLocal(String $targetPath, String $imageKinds='', Int $qualityPct=85): String {
		if( @file_put_contents($targetPath, $this->giveBytes($imageKinds, $qualityPct))===false ){
			throw new \RuntimeException('File cannot be written: '.basename($targetPath));
		}
		return $targetPath;
	}

}
