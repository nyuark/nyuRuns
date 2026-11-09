<?php

namespace nyu\Data\Writers;

use nyu\Data\Archv;
use nyu\Data\Shape;

/**
 * Books (EPUB 3) written from text blocks: the text cut into chapters at
 * its headings, each chapter a page of XHTML written by the HTML writer;
 * a table of contents made of those headings, in the two ways readers
 * look for it; and what tells of the book. A book has no pages of its
 * own: its reader lays it out. It holds no script, and points to nothing
 * it would fetch. Its pictures and its cover are those of a folder it is
 * given, and no other.
 */
class EPub extends Page {

	// The kind of file, as its first entry tells it
	const FILE_KIND = 'application/epub+zip';
	// The folder of the archive the book stands in
	const BOOK_ROOT = 'OEBPS/';
	// What every chapter looks like, before what the styles of the content add to it
	const BASE_STYL = "body{margin:5%;line-height:1.4}\nh1,h2,h3,h4,h5,h6{line-height:1.2;page-break-after:avoid;break-after:avoid}\nh1{font-size:1.6em}\nh2{font-size:1.35em}\nh3{font-size:1.15em}\nh4,h5,h6{font-size:1em}\n"
		."table{border-collapse:collapse;margin:1em 0}\nth,td{border:1px solid #808080;padding:0.2em 0.5em;vertical-align:top}\nth{background:#f2f2f2}\nblockquote{margin:1em 2em;font-style:italic}\ncite{display:block;text-align:right;font-style:normal}\n"
		."pre{background:#f2f2f2;padding:0.5em;white-space:pre-wrap;font-size:0.9em}\naside{font-size:0.9em;margin:0.5em 0}\nimg{max-width:100%;height:auto}\nhr.break{border:0;page-break-after:always;break-after:page}\n"
		."body.cover{margin:0;text-align:center}\nbody.cover img{max-width:100%;max-height:100%}\n";

	// The pictures of the book, each written once: what tells its content apart => its name in the book, its bytes
	protected $mediaFiles = Array();

	/**
	 * Writes text blocks, or sheets, as a book.
	 *
	 * Options:
	 *   'split' => the level of the headings that start a chapter: 1
	 *              when left out, 0 for a book of one chapter;
	 *   'depth' => how many levels of headings the table of contents
	 *              shows: 2 when left out;
	 *   'langg' => the language of the book, as 'en' or 'fr-CH', when
	 *              what is told of the book does not give it; 'en' when
	 *              neither does;
	 *   'media' => the folder the pictures of the book are taken from:
	 *              an image whose file is in it is written into the
	 *              book, any other is written as the text that describes
	 *              it. No picture is ever fetched;
	 *   'cover' => the file of the cover of the book, in that folder;
	 *              the one told of the book under 'cover' when left out.
	 *
	 * What is told of the book is written with it: its title under
	 * 'title', its author under 'authr', its language under 'langg', its
	 * other properties under 'props' (the one named 'Identifier' is the
	 * identifier of the book: without it, one is made from its content),
	 * and the styles its paragraphs name under 'frmat'.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options: 'split', 'depth', 'langg', 'media', 'cover'.
	 * @return String The bytes of the book.
	 *
	 * @throws WriteError When the shape is neither 'block' nor 'sheet', or the folder of the pictures cannot be read.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$shapeAs = $this->checkShapeText($dataEnvelope, Array('block', 'sheet'), 'a book');
		$this->readPictureDir($writeOptions);
		$this->mediaFiles = Array();
		$infoArr = (array)($dataEnvelope['infos'] ?? Array());
		$blckLst = ($shapeAs=='sheet') ? $this->writeAllSheets((array)($dataEnvelope['items'] ?? Array())) : (array)($dataEnvelope['items'] ?? Array());
		$propLst = (array)($infoArr['props'] ?? Array());
		$bookTtl = trim(strval(is_scalar($infoArr['title'] ?? null) ? $infoArr['title'] : (is_scalar($propLst['Title'] ?? null) ? $propLst['Title'] : '')));
		$bookAut = trim(strval(is_scalar($infoArr['authr'] ?? null) ? $infoArr['authr'] : (is_scalar($propLst['Author'] ?? null) ? $propLst['Author'] : '')));
		$langKey = '';
		foreach(Array($infoArr['langg'] ?? '', $propLst['Language'] ?? '', $writeOptions['langg'] ?? '', 'en') as $lngTxt){
			if( $langKey==='' && is_scalar($lngTxt) && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/D', strval($lngTxt)) ){
				$langKey = strval($lngTxt);
			}
		}
		// The notes are taken out of the text: each ends the chapter that refers to it
		$noteLst = $this->takeNotesArray($blckLst);
		$noteLst = $noteLst['fnote'] + $noteLst['enote'];
		$headNum = 0;
		$blckLst = $this->prepareBlckArr(array_values($blckLst), $headNum);
		$chapLst = $this->chaptersOfList($blckLst, max(0, min(6, intval($writeOptions['split'] ?? 1))), ($bookTtl==='') ? 'Book' : $bookTtl);
		$usedLst = Array();
		foreach($chapLst as $chpIdx=>$chpSet){
			foreach($this->notesUsedArray($chpSet['blcks']) as $nteNme){
				if( isset($noteLst[$nteNme]) && !isset($usedLst[$nteNme]) ){
					$usedLst[$nteNme] = true;
					$chapLst[$chpIdx]['blcks'][] = $this->prepareBlckArr(Array($noteLst[$nteNme]), $headNum)[0];
				}
			}
		}
		// A note the text never referred to is not lost: it ends the book
		foreach(array_diff_key($noteLst, $usedLst) as $nteBlk){
			$chapLst[count($chapLst)-1]['blcks'][] = $this->prepareBlckArr(Array($nteBlk), $headNum)[0];
		}
		// Where each named place stands, for the links that point to a place of another chapter
		$plcList = Array();
		foreach($chapLst as $chpIdx=>$chpSet){
			foreach($this->listNamedPlace($chpSet['blcks']) as $plcNme){
				$plcList[$plcNme] = $plcList[$plcNme] ?? 'chap'.($chpIdx+1).'.xhtml';
			}
		}
		$htmlObj = new Html();
		$fileLst = Array();
		$itemTxt = '';
		$spinTxt = '';
		$pageTop = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<!DOCTYPE html>'."\n".'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="'.$langKey.'" xml:lang="'.$langKey.'">'."\n".'<head>'."\n".'<meta charset="utf-8"/>'."\n";
		// The cover: a picture of the folder, shown on a page of its own before the first chapter
		$covrSet = null;
		foreach(Array($writeOptions['cover'] ?? '', $infoArr['cover'] ?? '') as $cvrNme){
			if( $covrSet===null && is_scalar($cvrNme) && strval($cvrNme)!=='' ){
				$covrSet = $this->pictureReadArr(strval($cvrNme));
			}
		}
		$cvrName = ($covrSet===null) ? '' : $this->givePictureNme($covrSet);
		if( $cvrName!=='' ){
			$fileLst[self::BOOK_ROOT.'cover.xhtml'] = $pageTop.'<title>'.$this->escapeXmlValue(($bookTtl==='') ? 'Book' : $bookTtl).'</title>'."\n".'<link rel="stylesheet" type="text/css" href="style.css"/>'."\n".'</head>'."\n"
				.'<body class="cover" epub:type="cover">'."\n".'<img src="'.$cvrName.'" alt="'.$this->escapeXmlValue(($bookTtl==='') ? 'Cover' : $bookTtl).'"/>'."\n".'</body>'."\n".'</html>'."\n";
			$itemTxt .= '<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>';
			$spinTxt .= '<itemref idref="cover"/>';
		}
		$headLst = Array();
		$tocDeep = max(1, min(6, intval($writeOptions['depth'] ?? 2)));
		foreach($chapLst as $chpIdx=>$chpSet){
			$pageNme = 'chap'.($chpIdx+1).'.xhtml';
			$chapter = $this->linkPlaceArray($chpSet['blcks'], $plcList, $pageNme);
			$bodyHtm = $htmlObj->renderDocument(Array('shape'=>'block', 'infos'=>Array('frmat'=>(array)($infoArr['frmat'] ?? Array())), 'items'=>$chapter), Array('xhtml'=>true, 'frmat'=>false));
			// A reference to a note and the note itself tell what they are: a reader that knows how opens the note in place
			$bodyHtm = strval(preg_replace(Array('/<a href="([^"#]*#[fe]note\d+)">/', '/<aside id="fnote(\d+)"/', '/<aside id="enote(\d+)"/'), Array('<a epub:type="noteref" href="$1">', '<aside epub:type="footnote" id="fnote$1"', '<aside epub:type="endnote" id="enote$1"'), $bodyHtm));
			$fileLst[self::BOOK_ROOT.$pageNme] = $pageTop.'<title>'.$this->escapeXmlValue($chpSet['title']).'</title>'."\n".'<link rel="stylesheet" type="text/css" href="style.css"/>'."\n".'</head>'."\n".'<body>'."\n".$bodyHtm.'</body>'."\n".'</html>'."\n";
			$itemTxt .= '<item id="chap'.($chpIdx+1).'" href="'.$pageNme.'" media-type="application/xhtml+xml"/>';
			$spinTxt .= '<itemref idref="chap'.($chpIdx+1).'"/>';
			// The headings of the chapter, for the table of contents
			foreach($chapter as $oneBlk){
				if( $oneBlk['block']=='headr' && intval($oneBlk['attrs']['level'] ?? 1)<=$tocDeep && trim($oneBlk['plain'])!=='' ){
					$headLst[] = Array('level'=>max(1, intval($oneBlk['attrs']['level'] ?? 1)), 'title'=>trim(strval(preg_replace('/\s+/', ' ', $oneBlk['plain']))), 'links'=>$pageNme.'#'.$oneBlk['attrs']['tagid']);
				}
			}
		}
		// A book without headings still has a table of contents: its chapters
		if( empty($headLst) ){
			foreach($chapLst as $chpIdx=>$chpSet){
				$headLst[] = Array('level'=>1, 'title'=>$chpSet['title'], 'links'=>'chap'.($chpIdx+1).'.xhtml');
			}
		}
		$hedTree = $this->headsTreeArray($headLst);
		// The pictures, each written once, and told for what it is; the cover is told as such, in the two ways readers look for it
		$cvrIdnt = '';
		foreach(array_values($this->mediaFiles) as $medIdx=>$medSet){
			$fileLst[self::BOOK_ROOT.$medSet[0]] = $medSet[1];
			$itemTxt .= '<item id="img'.($medIdx+1).'" href="'.$medSet[0].'" media-type="'.self::PICT_LIST[substr($medSet[0], intval(strrpos($medSet[0], '.'))+1)].'"'.(($medSet[0]===$cvrName) ? ' properties="cover-image"' : '').'/>';
			$cvrIdnt = ($medSet[0]===$cvrName) ? 'img'.($medIdx+1) : $cvrIdnt;
		}
		$bokIdnt = $this->bookIdntString($propLst, $bookTtl.'|'.$bookAut.'|'.Shape::documentAsText($blckLst));
		$headXml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$metaTxt = '<dc:identifier id="bookid">'.$this->escapeXmlValue($bokIdnt).'</dc:identifier><dc:title>'.$this->escapeXmlValue(($bookTtl==='') ? 'Book' : $bookTtl).'</dc:title><dc:language>'.$langKey.'</dc:language>'
			.(($bookAut==='') ? '' : '<dc:creator>'.$this->escapeXmlValue($bookAut).'</dc:creator>');
		foreach(Array('Subject'=>'dc:subject', 'Comments'=>'dc:description', 'Publisher'=>'dc:publisher', 'Rights'=>'dc:rights') as $prpNme=>$xmlTag){
			$metaTxt .= (is_scalar($propLst[$prpNme] ?? null) && trim(strval($propLst[$prpNme]))!=='') ? '<'.$xmlTag.'>'.$this->escapeXmlValue(trim(strval($propLst[$prpNme]))).'</'.$xmlTag.'>' : '';
		}
		foreach(is_scalar($propLst['Keywords'] ?? null) ? array_filter(array_map('trim', explode(',', strval($propLst['Keywords']))), 'strlen') : Array() as $keyWrd){
			$metaTxt .= '<dc:subject>'.$this->escapeXmlValue($keyWrd).'</dc:subject>';
		}
		$fileLst = Array(
			'mimetype'=>self::FILE_KIND,
			'META-INF/container.xml'=>$headXml.'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="'.self::BOOK_ROOT.'content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
			self::BOOK_ROOT.'content.opf'=>$headXml.'<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="'.$langKey.'"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'.$metaTxt
				.'<meta property="dcterms:modified">'.gmdate('Y-m-d\TH:i:s\Z').'</meta>'.(($cvrIdnt==='') ? '' : '<meta name="cover" content="'.$cvrIdnt.'"/>').'</metadata>'
				.'<manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/><item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/><item id="css" href="style.css" media-type="text/css"/>'.$itemTxt.'</manifest>'
				.'<spine toc="ncx">'.$spinTxt.'</spine></package>',
			self::BOOK_ROOT.'nav.xhtml'=>$headXml.'<!DOCTYPE html>'."\n".'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="'.$langKey.'" xml:lang="'.$langKey.'">'."\n"
				.'<head>'."\n".'<meta charset="utf-8"/>'."\n".'<title>'.$this->escapeXmlValue(($bookTtl==='') ? 'Book' : $bookTtl).'</title>'."\n".'<link rel="stylesheet" type="text/css" href="style.css"/>'."\n".'</head>'."\n"
				.'<body>'."\n".'<nav epub:type="toc" id="toc">'."\n".'<h1>'.$this->escapeXmlValue(($bookTtl==='') ? 'Book' : $bookTtl).'</h1>'."\n".$this->navigationPart($hedTree).'</nav>'."\n".'</body>'."\n".'</html>'."\n",
			self::BOOK_ROOT.'toc.ncx'=>$headXml.'<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head><meta name="dtb:uid" content="'.$this->escapeXmlValue($bokIdnt).'"/><meta name="dtb:depth" content="'.$tocDeep.'"/><meta name="dtb:totalPageCount" content="0"/><meta name="dtb:maxPageNumber" content="0"/></head>'
				.'<docTitle><text>'.$this->escapeXmlValue(($bookTtl==='') ? 'Book' : $bookTtl).'</text></docTitle><navMap>'.$this->ncxOfPieceText($hedTree).'</navMap></ncx>',
			self::BOOK_ROOT.'style.css'=>$this->stylePieceText((array)($infoArr['frmat'] ?? Array()))
		) + $fileLst;
		try{
			// The first entry tells what the file is, and is kept as it is
			return Archv::newBytesString($fileLst, Array('mimetype'));
		}catch( \RuntimeException $e ){
			throw new WriteError('Book that cannot be written: '.$e->getMessage(), 0, $e);
		}
	}

	/**
	 * Gives the identifier of the book: the one told of it, or one made
	 * from its content, so that the same book always bears the same.
	 *
	 * @param Array  $propertyList The properties of the book: its identifier under 'Identifier', when it has one.
	 * @param String $bookContents What the book holds, for the identifier made from it.
	 * @return String The identifier.
	 */
	protected function bookIdntString(Array $propertyList, String $bookContents): String {
		$tldIdnt = is_scalar($propertyList['Identifier'] ?? null) ? trim(strval(preg_replace('/[\x00-\x20]+/', ' ', strval($propertyList['Identifier'])))) : '';
		if( $tldIdnt!=='' ){
			return substr($tldIdnt, 0, 200);
		}
		// An identifier written as those made from a name are: five groups of digits, the third and the fourth telling so
		$hashTxt = sha1('nyuRuns book|'.$bookContents);
		return 'urn:uuid:'.substr($hashTxt, 0, 8).'-'.substr($hashTxt, 8, 4).'-5'.substr($hashTxt, 13, 3).'-'.dechex((hexdec($hashTxt[16]) & 0x3) | 0x8).substr($hashTxt, 17, 3).'-'.substr($hashTxt, 20, 12);
	}

	/**
	 * Makes blocks ready for a book. A heading is given a name, for the
	 * table of contents to point to; a box to tick is written as the sign
	 * of one; a field is the text it last showed, since a book has no
	 * pages to count; and a picture is one of the book when its file is
	 * in the folder the book was given, the text that describes it
	 * otherwise.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @param Int   $headingCount The number of headings named so far; receives the new one.
	 * @return Array The blocks, ready.
	 */
	protected function prepareBlckArr(Array $bookBlockSet, Int &$headingCount): Array {
		$doneLst = Array();
		foreach($bookBlockSet as $oneBlk){
			if( !is_array($oneBlk) || !isset($oneBlk['block']) ){
				continue;
			}
			$blckKnd = strval($oneBlk['block']);
			$attrSet = (array)($oneBlk['attrs'] ?? Array());
			$spanLst = Array();
			foreach((array)($oneBlk['spans'] ?? Array()) as $oneSpn){
				if( is_array($oneSpn) ){
					$spanLst[] = Shape::makeASpanArray(strval($oneSpn['texts'] ?? ''), (array)($oneSpn['marks'] ?? Array()), is_scalar($oneSpn['links'] ?? '') ? strval($oneSpn['links'] ?? '') : '');
				}
			}
			if( $blckKnd=='headr' && !preg_match('/^[A-Za-z][\w\-.]*$/D', strval($attrSet['tagid'] ?? '')) ){
				$headingCount++;
				$attrSet['tagid'] = 'sec-'.$headingCount;
			}
			if( $blckKnd=='entry' && isset($attrSet['check']) ){
				array_unshift($spanLst, Shape::makeASpanArray(empty($attrSet['check']) ? "\u{2610} " : "\u{2611} "));
				unset($attrSet['check']);
			}
			if( $blckKnd=='image' ){
				$pctrSet = $this->pictureReadArr(is_scalar($attrSet['sourc'] ?? '') ? strval($attrSet['sourc'] ?? '') : '');
				$pctrNme = ($pctrSet===null) ? '' : $this->givePictureNme($pctrSet);
				if( $pctrNme==='' ){
					$blckKnd = 'parag';
					unset($attrSet['sourc']);
				}else{
					$attrSet['sourc'] = $pctrNme;
				}
			}
			// A name a page of XHTML would refuse is not written
			if( isset($attrSet['tagid']) && !preg_match('/^[A-Za-z][\w\-.]*$/D', strval($attrSet['tagid'])) ){
				unset($attrSet['tagid']);
			}
			$doneLst[] = Shape::makeBlockArray($blckKnd, $spanLst, $this->prepareBlckArr((array)($oneBlk['items'] ?? Array()), $headingCount), $attrSet);
		}
		return $doneLst;
	}

	/**
	 * Gives a picture its name in the book, where it is written once
	 * however often it is shown.
	 *
	 * @param Array $pictureArray The picture, as pictureReadArr() gives it.
	 * @return String Its name in the book; '' when the pictures of the book would then be more than a book may hold.
	 */
	protected function givePictureNme(Array $pictureArray): String {
		$pctrKey = sha1($pictureArray['bytes']);
		if( !isset($this->mediaFiles[$pctrKey]) ){
			if( array_sum(array_map(function(Array $mediaItemArr){ return strlen($mediaItemArr[1]); }, $this->mediaFiles))+strlen($pictureArray['bytes'])>self::MEDI_MAXI ){
				return '';
			}
			$this->mediaFiles[$pctrKey] = Array('images/image'.(count($this->mediaFiles)+1).'.'.$pictureArray['kinds'], $pictureArray['bytes']);
		}
		return $this->mediaFiles[$pctrKey][0];
	}

	/**
	 * Cuts the blocks of a book into its chapters: a chapter starts at
	 * each heading of the level asked for, or above it.
	 *
	 * @param Array  $bookBlockSet The blocks.
	 * @param Int    $splitLevelNb The level of the headings that start a chapter, 0 for a book of one chapter.
	 * @param String $bookTitleTxt The title of the book, given to a chapter that starts with no heading.
	 * @return Array The chapters, each with its title under 'title' and its blocks under 'blcks'; one at least.
	 */
	protected function chaptersOfList(Array $bookBlockSet, Int $splitLevelNb, String $bookTitleTxt): Array {
		$chapLst = Array();
		foreach($bookBlockSet as $oneBlk){
			$isStart = ($oneBlk['block']=='headr' && $splitLevelNb>0 && intval($oneBlk['attrs']['level'] ?? 1)<=$splitLevelNb && trim($oneBlk['plain'])!=='');
			if( $isStart || empty($chapLst) ){
				$chapLst[] = Array('title'=>$isStart ? trim(strval(preg_replace('/\s+/', ' ', $oneBlk['plain']))) : $bookTitleTxt, 'blcks'=>Array());
			}
			$chapLst[count($chapLst)-1]['blcks'][] = $oneBlk;
		}
		return empty($chapLst) ? Array(Array('title'=>$bookTitleTxt, 'blcks'=>Array())) : $chapLst;
	}

	/**
	 * Lists the notes some blocks refer to, in the order they do.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return Array The names of the notes.
	 */
	protected function notesUsedArray(Array $bookBlockSet): Array {
		$nameLst = Array();
		foreach($bookBlockSet as $oneBlk){
			foreach($oneBlk['spans'] as $oneSpn){
				if( preg_match('/^#([fe]note\d+)$/D', $oneSpn['links']) ){
					$nameLst[] = substr($oneSpn['links'], 1);
				}
			}
			$nameLst = array_merge($nameLst, $this->notesUsedArray($oneBlk['items']));
		}
		return $nameLst;
	}

	/**
	 * Lists the places some blocks name.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return Array The names of the places.
	 */
	protected function listNamedPlace(Array $bookBlockSet): Array {
		$nameLst = Array();
		foreach($bookBlockSet as $oneBlk){
			if( strval($oneBlk['attrs']['tagid'] ?? '')!=='' ){
				$nameLst[] = strval($oneBlk['attrs']['tagid']);
			}
			$nameLst = array_merge($nameLst, $this->listNamedPlace($oneBlk['items']));
		}
		return $nameLst;
	}

	/**
	 * Makes the links of a chapter point where their place stands: a
	 * place of another chapter is told with the page of that chapter.
	 *
	 * @param Array  $bookBlockSet The blocks of the chapter.
	 * @param Array  $placePageMap Where each named place stands: its name => the page of its chapter.
	 * @param String $thisPageName The page of this chapter.
	 * @return Array The blocks.
	 */
	protected function linkPlaceArray(Array $bookBlockSet, Array $placePageMap, String $thisPageName): Array {
		foreach($bookBlockSet as $blkIdx=>$oneBlk){
			foreach($oneBlk['spans'] as $spnIdx=>$oneSpn){
				$plcName = (substr($oneSpn['links'], 0, 1)=='#') ? substr($oneSpn['links'], 1) : '';
				if( $plcName!=='' && isset($placePageMap[$plcName]) && $placePageMap[$plcName]!==$thisPageName ){
					$bookBlockSet[$blkIdx]['spans'][$spnIdx]['links'] = $placePageMap[$plcName].'#'.$plcName;
				}
			}
			$bookBlockSet[$blkIdx]['items'] = $this->linkPlaceArray($oneBlk['items'], $placePageMap, $thisPageName);
		}
		return $bookBlockSet;
	}

	/**
	 * Lays the headings of a book out as a tree: a heading holds those of
	 * the levels under it that follow it.
	 *
	 * @param Array $headingsList The headings, in order, each with its 'level', its 'title' and where it stands under 'links'.
	 * @return Array The headings of the highest level met, each with those it holds under 'holds'.
	 */
	protected function headsTreeArray(Array $headingsList): Array {
		$treeLst = Array();
		// The way down to the heading being filled: each step is the list a heading of that depth goes into
		$pathLst = Array(&$treeLst);
		$lvlsLst = Array();
		foreach($headingsList as $oneHdr){
			while( !empty($lvlsLst) && end($lvlsLst)>=$oneHdr['level'] ){
				array_pop($lvlsLst);
				array_pop($pathLst);
			}
			$nowList = &$pathLst[count($pathLst)-1];
			$nowList[] = Array('title'=>$oneHdr['title'], 'links'=>$oneHdr['links'], 'holds'=>Array());
			$lvlsLst[] = $oneHdr['level'];
			$pathLst[] = &$nowList[count($nowList)-1]['holds'];
			unset($nowList);
		}
		return $treeLst;
	}

	/**
	 * Writes the table of contents as the readers of today look for it:
	 * lists of links, one in another.
	 *
	 * @param Array $headingsTree The headings, as a tree.
	 * @return String The lists.
	 */
	protected function navigationPart(Array $headingsTree): String {
		$listTxt = '<ol>'."\n";
		foreach($headingsTree as $oneHdr){
			$listTxt .= '<li><a href="'.$this->escapeXmlValue($oneHdr['links']).'">'.$this->escapeXmlValue($oneHdr['title']).'</a>'.(empty($oneHdr['holds']) ? '' : "\n".$this->navigationPart($oneHdr['holds'])).'</li>'."\n";
		}
		return $listTxt.'</ol>'."\n";
	}

	/**
	 * Writes the table of contents as older readers look for it: points,
	 * one in another, each telling its place in the order of reading.
	 *
	 * @param Array $headingsTree The headings, as a tree.
	 * @param Int   $playOrderNum The number of points written so far; receives the new one.
	 * @return String The points.
	 */
	protected function ncxOfPieceText(Array $headingsTree, Int &$playOrderNum=0): String {
		$pntsTxt = '';
		foreach($headingsTree as $oneHdr){
			$playOrderNum++;
			$pntsTxt .= '<navPoint id="np'.$playOrderNum.'" playOrder="'.$playOrderNum.'"><navLabel><text>'.$this->escapeXmlValue($oneHdr['title']).'</text></navLabel><content src="'.$this->escapeXmlValue($oneHdr['links']).'"/>'.$this->ncxOfPieceText($oneHdr['holds'], $playOrderNum).'</navPoint>';
		}
		return $pntsTxt;
	}

	/**
	 * Writes the style sheet of the book: what every chapter looks like,
	 * then what the styles its paragraphs name give, as the HTML writer
	 * checks and writes them.
	 *
	 * @param Array $styleFormats The styles the content tells of.
	 * @return String The style sheet.
	 */
	protected function stylePieceText(Array $styleFormats): String {
		$cssList = Array();
		$cssText = (new Html())->renderDocument(Array('shape'=>'block', 'infos'=>Array('frmat'=>$styleFormats), 'items'=>Array()), Array('xhtml'=>true, 'frmat'=>true));
		return self::BASE_STYL.(preg_match('/<style>\n(.*)\n<\/style>/s', $cssText, $cssList) ? $cssList[1]."\n" : '');
	}

}
