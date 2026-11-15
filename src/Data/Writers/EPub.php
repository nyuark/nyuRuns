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
		$shapeNme = $this->checkShapeText($dataEnvelope, Array('block', 'sheet'), 'a book');
		$this->readPictureDir($writeOptions);
		$this->mediaFiles = Array();
		$infoList = (array)($dataEnvelope['infos'] ?? Array());
		$blockLst = ($shapeNme=='sheet') ? $this->getSheetBlocks((array)($dataEnvelope['items'] ?? Array())) : (array)($dataEnvelope['items'] ?? Array());
		$propList = (array)($infoList['props'] ?? Array());
		$bookTitl = trim(strval(is_scalar($infoList['title'] ?? null) ? $infoList['title'] : (is_scalar($propList['Title'] ?? null) ? $propList['Title'] : '')));
		$bookAuth = trim(strval(is_scalar($infoList['authr'] ?? null) ? $infoList['authr'] : (is_scalar($propList['Author'] ?? null) ? $propList['Author'] : '')));
		$langClef = '';
		foreach(Array($infoList['langg'] ?? '', $propList['Language'] ?? '', $writeOptions['langg'] ?? '', 'en') as $lngTxt){
			if( $langClef==='' && is_scalar($lngTxt) && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/D', strval($lngTxt)) ){
				$langClef = strval($lngTxt);
			}
		}
		// The notes are taken out of the text: each ends the chapter that refers to it
		$noteList = $this->splitNotesDict($blockLst);
		$noteList = $noteList['fnote'] + $noteList['enote'];
		$headNumb = 0;
		$blockLst = $this->makeBookBlocks(array_values($blockLst), $headNumb);
		$chapList = $this->splitIntoChapt($blockLst, max(0, min(6, intval($writeOptions['split'] ?? 1))), ($bookTitl==='') ? 'Book' : $bookTitl);
		$usedList = Array();
		foreach($chapList as $chpIdx=>$chpSet){
			foreach($this->listNotesCited($chpSet['blcks']) as $nteNme){
				if( isset($noteList[$nteNme]) && !isset($usedList[$nteNme]) ){
					$usedList[$nteNme] = true;
					$chapList[$chpIdx]['blcks'][] = $this->makeBookBlocks(Array($noteList[$nteNme]), $headNumb)[0];
				}
			}
		}
		// A note the text never referred to is not lost: it ends the book
		foreach(array_diff_key($noteList, $usedList) as $nteBlk){
			$chapList[count($chapList)-1]['blcks'][] = $this->makeBookBlocks(Array($nteBlk), $headNumb)[0];
		}
		// Where each named place stands, for the links that point to a place of another chapter
		$placeLst = Array();
		foreach($chapList as $chpIdx=>$chpSet){
			foreach($this->listNamedPlace($chpSet['blcks']) as $plcNme){
				$placeLst[$plcNme] = $placeLst[$plcNme] ?? 'chap'.($chpIdx+1).'.xhtml';
			}
		}
		$htmlInst = new Html();
		$fileList = Array();
		$itemText = '';
		$spineTxt = '';
		$pageHead = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<!DOCTYPE html>'."\n".'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="'.$langClef.'" xml:lang="'.$langClef.'">'."\n".'<head>'."\n".'<meta charset="utf-8"/>'."\n";
		// The cover: a picture of the folder, shown on a page of its own before the first chapter
		$covrDict = null;
		foreach(Array($writeOptions['cover'] ?? '', $infoList['cover'] ?? '') as $cvrNme){
			if( $covrDict===null && is_scalar($cvrNme) && strval($cvrNme)!=='' ){
				$covrDict = $this->readPictureDct(strval($cvrNme));
			}
		}
		$coverNme = ($covrDict===null) ? '' : $this->getPictureName($covrDict);
		if( $coverNme!=='' ){
			$fileList[self::BOOK_ROOT.'cover.xhtml'] = $pageHead.'<title>'.$this->escapeXmlValue(($bookTitl==='') ? 'Book' : $bookTitl).'</title>'."\n".'<link rel="stylesheet" type="text/css" href="style.css"/>'."\n".'</head>'."\n"
				.'<body class="cover" epub:type="cover">'."\n".'<img src="'.$coverNme.'" alt="'.$this->escapeXmlValue(($bookTitl==='') ? 'Cover' : $bookTitl).'"/>'."\n".'</body>'."\n".'</html>'."\n";
			$itemText .= '<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>';
			$spineTxt .= '<itemref idref="cover"/>';
		}
		$headList = Array();
		$tocDepth = max(1, min(6, intval($writeOptions['depth'] ?? 2)));
		foreach($chapList as $chpIdx=>$chpSet){
			$pageName = 'chap'.($chpIdx+1).'.xhtml';
			$chptDict = $this->linkPlaceArray($chpSet['blcks'], $placeLst, $pageName);
			$bodyHtml = $htmlInst->renderDocument(Array('shape'=>'block', 'infos'=>Array('frmat'=>(array)($infoList['frmat'] ?? Array())), 'items'=>$chptDict), Array('xhtml'=>true, 'frmat'=>false));
			// A reference to a note and the note itself tell what they are: a reader that knows how opens the note in place
			$bodyHtml = strval(preg_replace(Array('/<a href="([^"#]*#[fe]note\d+)">/', '/<aside id="fnote(\d+)"/', '/<aside id="enote(\d+)"/'), Array('<a epub:type="noteref" href="$1">', '<aside epub:type="footnote" id="fnote$1"', '<aside epub:type="endnote" id="enote$1"'), $bodyHtml));
			$fileList[self::BOOK_ROOT.$pageName] = $pageHead.'<title>'.$this->escapeXmlValue($chpSet['title']).'</title>'."\n".'<link rel="stylesheet" type="text/css" href="style.css"/>'."\n".'</head>'."\n".'<body>'."\n".$bodyHtml.'</body>'."\n".'</html>'."\n";
			$itemText .= '<item id="chap'.($chpIdx+1).'" href="'.$pageName.'" media-type="application/xhtml+xml"/>';
			$spineTxt .= '<itemref idref="chap'.($chpIdx+1).'"/>';
			// The headings of the chapter, for the table of contents
			foreach($chptDict as $oneBlk){
				if( $oneBlk['block']=='headline' && intval($oneBlk['attrs']['level'] ?? 1)<=$tocDepth && trim($oneBlk['plain'])!=='' ){
					$headList[] = Array('level'=>max(1, intval($oneBlk['attrs']['level'] ?? 1)), 'title'=>trim(strval(preg_replace('/\s+/', ' ', $oneBlk['plain']))), 'links'=>$pageName.'#'.$oneBlk['attrs']['tagid']);
				}
			}
		}
		// A book without headings still has a table of contents: its chapters
		if( empty($headList) ){
			foreach($chapList as $chpIdx=>$chpSet){
				$headList[] = Array('level'=>1, 'title'=>$chpSet['title'], 'links'=>'chap'.($chpIdx+1).'.xhtml');
			}
		}
		$headTree = $this->getHeadingTree($headList);
		// The pictures, each written once, and told for what it is; the cover is told as such, in the two ways readers look for it
		$covrIdnt = '';
		foreach(array_values($this->mediaFiles) as $medIdx=>$medSet){
			$fileList[self::BOOK_ROOT.$medSet[0]] = $medSet[1];
			$itemText .= '<item id="img'.($medIdx+1).'" href="'.$medSet[0].'" media-type="'.self::PICT_LIST[substr($medSet[0], intval(strrpos($medSet[0], '.'))+1)].'"'.(($medSet[0]===$coverNme) ? ' properties="cover-image"' : '').'/>';
			$covrIdnt = ($medSet[0]===$coverNme) ? 'img'.($medIdx+1) : $covrIdnt;
		}
		$bookIdnt = $this->getBookIdntTxt($propList, $bookTitl.'|'.$bookAuth.'|'.Shape::getTextOfDcmnt($blockLst));
		$headBrut = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$metaText = '<dc:identifier id="bookid">'.$this->escapeXmlValue($bookIdnt).'</dc:identifier><dc:title>'.$this->escapeXmlValue(($bookTitl==='') ? 'Book' : $bookTitl).'</dc:title><dc:language>'.$langClef.'</dc:language>'
			.(($bookAuth==='') ? '' : '<dc:creator>'.$this->escapeXmlValue($bookAuth).'</dc:creator>');
		foreach(Array('Subject'=>'dc:subject', 'Comments'=>'dc:description', 'Publisher'=>'dc:publisher', 'Rights'=>'dc:rights') as $prpNme=>$xmlTag){
			$metaText .= (is_scalar($propList[$prpNme] ?? null) && trim(strval($propList[$prpNme]))!=='') ? '<'.$xmlTag.'>'.$this->escapeXmlValue(trim(strval($propList[$prpNme]))).'</'.$xmlTag.'>' : '';
		}
		foreach(is_scalar($propList['Keywords'] ?? null) ? array_filter(array_map('trim', explode(',', strval($propList['Keywords']))), 'strlen') : Array() as $keyWrd){
			$metaText .= '<dc:subject>'.$this->escapeXmlValue($keyWrd).'</dc:subject>';
		}
		$fileList = Array(
			'mimetype'=>self::FILE_KIND,
			'META-INF/container.xml'=>$headBrut.'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="'.self::BOOK_ROOT.'content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
			self::BOOK_ROOT.'content.opf'=>$headBrut.'<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="'.$langClef.'"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'.$metaText
				.'<meta property="dcterms:modified">'.gmdate('Y-m-d\TH:i:s\Z').'</meta>'.(($covrIdnt==='') ? '' : '<meta name="cover" content="'.$covrIdnt.'"/>').'</metadata>'
				.'<manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/><item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/><item id="css" href="style.css" media-type="text/css"/>'.$itemText.'</manifest>'
				.'<spine toc="ncx">'.$spineTxt.'</spine></package>',
			self::BOOK_ROOT.'nav.xhtml'=>$headBrut.'<!DOCTYPE html>'."\n".'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="'.$langClef.'" xml:lang="'.$langClef.'">'."\n"
				.'<head>'."\n".'<meta charset="utf-8"/>'."\n".'<title>'.$this->escapeXmlValue(($bookTitl==='') ? 'Book' : $bookTitl).'</title>'."\n".'<link rel="stylesheet" type="text/css" href="style.css"/>'."\n".'</head>'."\n"
				.'<body>'."\n".'<nav epub:type="toc" id="toc">'."\n".'<h1>'.$this->escapeXmlValue(($bookTitl==='') ? 'Book' : $bookTitl).'</h1>'."\n".$this->writeTocAsHtml($headTree).'</nav>'."\n".'</body>'."\n".'</html>'."\n",
			self::BOOK_ROOT.'toc.ncx'=>$headBrut.'<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head><meta name="dtb:uid" content="'.$this->escapeXmlValue($bookIdnt).'"/><meta name="dtb:depth" content="'.$tocDepth.'"/><meta name="dtb:totalPageCount" content="0"/><meta name="dtb:maxPageNumber" content="0"/></head>'
				.'<docTitle><text>'.$this->escapeXmlValue(($bookTitl==='') ? 'Book' : $bookTitl).'</text></docTitle><navMap>'.$this->writeNcxPoints($headTree).'</navMap></ncx>',
			self::BOOK_ROOT.'style.css'=>$this->writeStylePart((array)($infoList['frmat'] ?? Array()))
		) + $fileList;
		try{
			// The first entry tells what the file is, and is kept as it is
			return Archv::makeZipFromLst($fileList, Array('mimetype'));
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
	protected function getBookIdntTxt(Array $propertyList, String $bookContents): String {
		$tldIdent = is_scalar($propertyList['Identifier'] ?? null) ? trim(strval(preg_replace('/[\x00-\x20]+/', ' ', strval($propertyList['Identifier'])))) : '';
		if( $tldIdent!=='' ){
			return substr($tldIdent, 0, 200);
		}
		// An identifier written as those made from a name are: five groups of digits, the third and the fourth telling so
		$hashText = sha1('nyuRuns book|'.$bookContents);
		return 'urn:uuid:'.substr($hashText, 0, 8).'-'.substr($hashText, 8, 4).'-5'.substr($hashText, 13, 3).'-'.dechex((hexdec($hashText[16]) & 0x3) | 0x8).substr($hashText, 17, 3).'-'.substr($hashText, 20, 12);
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
	protected function makeBookBlocks(Array $bookBlockSet, Int &$headingCount): Array {
		$doneList = Array();
		foreach($bookBlockSet as $oneBlk){
			if( !is_array($oneBlk) || !isset($oneBlk['block']) ){
				continue;
			}
			$blockCod = strval($oneBlk['block']);
			$attrDict = (array)($oneBlk['attrs'] ?? Array());
			$spanList = Array();
			foreach((array)($oneBlk['spans'] ?? Array()) as $oneSpn){
				if( is_array($oneSpn) ){
					$spanList[] = Shape::makeSpanOfText(strval($oneSpn['texts'] ?? ''), (array)($oneSpn['marks'] ?? Array()), is_scalar($oneSpn['links'] ?? '') ? strval($oneSpn['links'] ?? '') : '');
				}
			}
			if( $blockCod=='headline' && !preg_match('/^[A-Za-z][\w\-.]*$/D', strval($attrDict['tagid'] ?? '')) ){
				$headingCount++;
				$attrDict['tagid'] = 'sec-'.$headingCount;
			}
			if( $blockCod=='listItem' && isset($attrDict['check']) ){
				array_unshift($spanList, Shape::makeSpanOfText(empty($attrDict['check']) ? "\u{2610} " : "\u{2611} "));
				unset($attrDict['check']);
			}
			if( $blockCod=='imageBox' ){
				$pictDict = $this->readPictureDct(is_scalar($attrDict['sourc'] ?? '') ? strval($attrDict['sourc'] ?? '') : '');
				$pictName = ($pictDict===null) ? '' : $this->getPictureName($pictDict);
				if( $pictName==='' ){
					$blockCod = 'paragrph';
					unset($attrDict['sourc']);
				}else{
					$attrDict['sourc'] = $pictName;
				}
			}
			// A name a page of XHTML would refuse is not written
			if( isset($attrDict['tagid']) && !preg_match('/^[A-Za-z][\w\-.]*$/D', strval($attrDict['tagid'])) ){
				unset($attrDict['tagid']);
			}
			$doneList[] = Shape::makeBlockArray($blockCod, $spanList, $this->makeBookBlocks((array)($oneBlk['items'] ?? Array()), $headingCount), $attrDict);
		}
		return $doneList;
	}

	/**
	 * Gives a picture its name in the book, where it is written once
	 * however often it is shown.
	 *
	 * @param Array $pictureArray The picture, as readPictureDct() gives it.
	 * @return String Its name in the book; '' when the pictures of the book would then be more than a book may hold.
	 */
	protected function getPictureName(Array $pictureArray): String {
		$pictClef = sha1($pictureArray['bytes']);
		if( !isset($this->mediaFiles[$pictClef]) ){
			if( array_sum(array_map(function(Array $mediaItemLst){ return strlen($mediaItemLst[1]); }, $this->mediaFiles))+strlen($pictureArray['bytes'])>self::MEDI_MAXI ){
				return '';
			}
			$this->mediaFiles[$pictClef] = Array('images/image'.(count($this->mediaFiles)+1).'.'.$pictureArray['kinds'], $pictureArray['bytes']);
		}
		return $this->mediaFiles[$pictClef][0];
	}

	/**
	 * Cuts the blocks of a book into its chapters: a chapter starts at
	 * each heading of the level asked for, or above it.
	 *
	 * @param Array  $bookBlockSet The blocks.
	 * @param Int    $splitLvlNumb The level of the headings that start a chapter, 0 for a book of one chapter.
	 * @param String $bookTitleTxt The title of the book, given to a chapter that starts with no heading.
	 * @return Array The chapters, each with its title under 'title' and its blocks under 'blcks'; one at least.
	 */
	protected function splitIntoChapt(Array $bookBlockSet, Int $splitLvlNumb, String $bookTitleTxt): Array {
		$chapList = Array();
		foreach($bookBlockSet as $oneBlk){
			$isNewFle = ($oneBlk['block']=='headline' && $splitLvlNumb>0 && intval($oneBlk['attrs']['level'] ?? 1)<=$splitLvlNumb && trim($oneBlk['plain'])!=='');
			if( $isNewFle || empty($chapList) ){
				$chapList[] = Array('title'=>$isNewFle ? trim(strval(preg_replace('/\s+/', ' ', $oneBlk['plain']))) : $bookTitleTxt, 'blcks'=>Array());
			}
			$chapList[count($chapList)-1]['blcks'][] = $oneBlk;
		}
		return empty($chapList) ? Array(Array('title'=>$bookTitleTxt, 'blcks'=>Array())) : $chapList;
	}

	/**
	 * Lists the notes some blocks refer to, in the order they do.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return Array The names of the notes.
	 */
	protected function listNotesCited(Array $bookBlockSet): Array {
		$nameList = Array();
		foreach($bookBlockSet as $oneBlk){
			foreach($oneBlk['spans'] as $oneSpn){
				if( preg_match('/^#([fe]note\d+)$/D', $oneSpn['links']) ){
					$nameList[] = substr($oneSpn['links'], 1);
				}
			}
			$nameList = array_merge($nameList, $this->listNotesCited($oneBlk['items']));
		}
		return $nameList;
	}

	/**
	 * Lists the places some blocks name.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return Array The names of the places.
	 */
	protected function listNamedPlace(Array $bookBlockSet): Array {
		$nameList = Array();
		foreach($bookBlockSet as $oneBlk){
			if( strval($oneBlk['attrs']['tagid'] ?? '')!=='' ){
				$nameList[] = strval($oneBlk['attrs']['tagid']);
			}
			$nameList = array_merge($nameList, $this->listNamedPlace($oneBlk['items']));
		}
		return $nameList;
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
				$placeNme = (substr($oneSpn['links'], 0, 1)=='#') ? substr($oneSpn['links'], 1) : '';
				if( $placeNme!=='' && isset($placePageMap[$placeNme]) && $placePageMap[$placeNme]!==$thisPageName ){
					$bookBlockSet[$blkIdx]['spans'][$spnIdx]['links'] = $placePageMap[$placeNme].'#'.$placeNme;
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
	protected function getHeadingTree(Array $headingsList): Array {
		$treeList = Array();
		// The way down to the heading being filled: each step is the list a heading of that depth goes into
		$pathList = Array(&$treeList);
		$lvlsList = Array();
		foreach($headingsList as $oneHdr){
			while( !empty($lvlsList) && end($lvlsList)>=$oneHdr['level'] ){
				array_pop($lvlsList);
				array_pop($pathList);
			}
			$nowLstng = &$pathList[count($pathList)-1];
			$nowLstng[] = Array('title'=>$oneHdr['title'], 'links'=>$oneHdr['links'], 'holds'=>Array());
			$lvlsList[] = $oneHdr['level'];
			$pathList[] = &$nowLstng[count($nowLstng)-1]['holds'];
			unset($nowLstng);
		}
		return $treeList;
	}

	/**
	 * Writes the table of contents as the readers of today look for it:
	 * lists of links, one in another.
	 *
	 * @param Array $headingsTree The headings, as a tree.
	 * @return String The lists.
	 */
	protected function writeTocAsHtml(Array $headingsTree): String {
		$listText = '<ol>'."\n";
		foreach($headingsTree as $oneHdr){
			$listText .= '<li><a href="'.$this->escapeXmlValue($oneHdr['links']).'">'.$this->escapeXmlValue($oneHdr['title']).'</a>'.(empty($oneHdr['holds']) ? '' : "\n".$this->writeTocAsHtml($oneHdr['holds'])).'</li>'."\n";
		}
		return $listText.'</ol>'."\n";
	}

	/**
	 * Writes the table of contents as older readers look for it: points,
	 * one in another, each telling its place in the order of reading.
	 *
	 * @param Array $headingsTree The headings, as a tree.
	 * @param Int   $playOrderNum The number of points written so far; receives the new one.
	 * @return String The points.
	 */
	protected function writeNcxPoints(Array $headingsTree, Int &$playOrderNum=0): String {
		$pntsText = '';
		foreach($headingsTree as $oneHdr){
			$playOrderNum++;
			$pntsText .= '<navPoint id="np'.$playOrderNum.'" playOrder="'.$playOrderNum.'"><navLabel><text>'.$this->escapeXmlValue($oneHdr['title']).'</text></navLabel><content src="'.$this->escapeXmlValue($oneHdr['links']).'"/>'.$this->writeNcxPoints($oneHdr['holds'], $playOrderNum).'</navPoint>';
		}
		return $pntsText;
	}

	/**
	 * Writes the style sheet of the book: what every chapter looks like,
	 * then what the styles its paragraphs name give, as the HTML writer
	 * checks and writes them.
	 *
	 * @param Array $styleFormats The styles the content tells of.
	 * @return String The style sheet.
	 */
	protected function writeStylePart(Array $styleFormats): String {
		$cssRules = Array();
		$cssStrng = (new Html())->renderDocument(Array('shape'=>'block', 'infos'=>Array('frmat'=>$styleFormats), 'items'=>Array()), Array('xhtml'=>true, 'frmat'=>true));
		return self::BASE_STYL.(preg_match('/<style>\n(.*)\n<\/style>/s', $cssStrng, $cssRules) ? $cssRules[1]."\n" : '');
	}

}
