<?php

namespace nyu\Data\Writers;

use nyu\Data\Archv;
use nyu\Data\Shape;

// Books (EPUB 3) written from text blocks: the text cut into chapters at
// its headings, each chapter a page of XHTML written by the HTML writer;
// a table of contents made of those headings, in the two ways readers
// look for it; and what tells of the book. A book has no pages of its
// own: its reader lays it out. It holds no script, and points to nothing
// it would fetch. Its pictures and its cover are those of a folder it is
// given, and no other.
class EPub extends Page {

	// The kind of file, as its first entry tells it
	const FILEKIND = 'application/epub+zip';
	// The folder of the archive the book stands in
	const BOOKROOT = 'OEBPS/';
	// What every chapter looks like, before what the styles of the content add to it
	const BASESTYL = "body{margin:5%;line-height:1.4}\nh1,h2,h3,h4,h5,h6{line-height:1.2;page-break-after:avoid;break-after:avoid}\nh1{font-size:1.6em}\nh2{font-size:1.35em}\nh3{font-size:1.15em}\nh4,h5,h6{font-size:1em}\n"
		."table{border-collapse:collapse;margin:1em 0}\nth,td{border:1px solid #808080;padding:0.2em 0.5em;vertical-align:top}\nth{background:#f2f2f2}\nblockquote{margin:1em 2em;font-style:italic}\ncite{display:block;text-align:right;font-style:normal}\n"
		."pre{background:#f2f2f2;padding:0.5em;white-space:pre-wrap;font-size:0.9em}\naside{font-size:0.9em;margin:0.5em 0}\nimg{max-width:100%;height:auto}\nhr.break{border:0;page-break-after:always;break-after:page}\n"
		."body.cover{margin:0;text-align:center}\nbody.cover img{max-width:100%;max-height:100%}\n";

	// The pictures of the book, each written once: what tells its content apart => its name in the book, its bytes
	protected $mediaSet = Array();

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
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'split', 'depth', 'langg', 'media', 'cover'.
	 * @return String The bytes of the book.
	 *
	 * @throws WriteError When the shape is neither 'block' nor 'sheet', or the folder of the pictures cannot be read.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$shapeAs = $this->checkShpe($shapedData, Array('block', 'sheet'), 'a book');
		$this->mediaFrom($writeOptns);
		$this->mediaSet = Array();
		$infoArr = (array)($shapedData['infos'] ?? Array());
		$blckLst = ($shapeAs=='sheet') ? $this->sheetsOut((array)($shapedData['items'] ?? Array())) : (array)($shapedData['items'] ?? Array());
		$propLst = (array)($infoArr['props'] ?? Array());
		$bookTtl = trim(strval(is_scalar($infoArr['title'] ?? null) ? $infoArr['title'] : (is_scalar($propLst['Title'] ?? null) ? $propLst['Title'] : '')));
		$bookAut = trim(strval(is_scalar($infoArr['authr'] ?? null) ? $infoArr['authr'] : (is_scalar($propLst['Author'] ?? null) ? $propLst['Author'] : '')));
		$langKey = '';
		foreach(Array($infoArr['langg'] ?? '', $propLst['Language'] ?? '', $writeOptns['langg'] ?? '', 'en') as $lngTxt){
			if( $langKey==='' && is_scalar($lngTxt) && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/D', strval($lngTxt)) ){
				$langKey = strval($lngTxt);
			}
		}
		// The notes are taken out of the text: each ends the chapter that refers to it
		$noteLst = $this->takeNotes($blckLst);
		$noteLst = $noteLst['fnote'] + $noteLst['enote'];
		$headNbr = 0;
		$blckLst = $this->prepBlock(array_values($blckLst), $headNbr);
		$chapLst = $this->chaptrsOf($blckLst, max(0, min(6, intval($writeOptns['split'] ?? 1))), ($bookTtl==='') ? 'Book' : $bookTtl);
		$usedLst = Array();
		foreach($chapLst as $chpIdx=>$chpSet){
			foreach($this->notesUsed($chpSet['blcks']) as $nteNme){
				if( isset($noteLst[$nteNme]) && !isset($usedLst[$nteNme]) ){
					$usedLst[$nteNme] = true;
					$chapLst[$chpIdx]['blcks'][] = $this->prepBlock(Array($noteLst[$nteNme]), $headNbr)[0];
				}
			}
		}
		// A note the text never referred to is not lost: it ends the book
		foreach(array_diff_key($noteLst, $usedLst) as $nteBlk){
			$chapLst[count($chapLst)-1]['blcks'][] = $this->prepBlock(Array($nteBlk), $headNbr)[0];
		}
		// Where each named place stands, for the links that point to a place of another chapter
		$plceLst = Array();
		foreach($chapLst as $chpIdx=>$chpSet){
			foreach($this->placeList($chpSet['blcks']) as $plcNme){
				$plceLst[$plcNme] = $plceLst[$plcNme] ?? 'chap'.($chpIdx+1).'.xhtml';
			}
		}
		$htmWrtr = new Html();
		$fileLst = Array();
		$itemTxt = '';
		$spneTxt = '';
		$pageTop = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<!DOCTYPE html>'."\n".'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="'.$langKey.'" xml:lang="'.$langKey.'">'."\n".'<head>'."\n".'<meta charset="utf-8"/>'."\n";
		// The cover: a picture of the folder, shown on a page of its own before the first chapter
		$covrSet = null;
		foreach(Array($writeOptns['cover'] ?? '', $infoArr['cover'] ?? '') as $cvrNme){
			if( $covrSet===null && is_scalar($cvrNme) && strval($cvrNme)!=='' ){
				$covrSet = $this->pictrRead(strval($cvrNme));
			}
		}
		$covrNme = ($covrSet===null) ? '' : $this->mediaName($covrSet);
		if( $covrNme!=='' ){
			$fileLst[self::BOOKROOT.'cover.xhtml'] = $pageTop.'<title>'.$this->escapeTxt(($bookTtl==='') ? 'Book' : $bookTtl).'</title>'."\n".'<link rel="stylesheet" type="text/css" href="style.css"/>'."\n".'</head>'."\n"
				.'<body class="cover" epub:type="cover">'."\n".'<img src="'.$covrNme.'" alt="'.$this->escapeTxt(($bookTtl==='') ? 'Cover' : $bookTtl).'"/>'."\n".'</body>'."\n".'</html>'."\n";
			$itemTxt .= '<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>';
			$spneTxt .= '<itemref idref="cover"/>';
		}
		$headLst = Array();
		$tocDeep = max(1, min(6, intval($writeOptns['depth'] ?? 2)));
		foreach($chapLst as $chpIdx=>$chpSet){
			$chapNme = 'chap'.($chpIdx+1).'.xhtml';
			$chapBlk = $this->linkPlace($chpSet['blcks'], $plceLst, $chapNme);
			$bodyHtm = $htmWrtr->writeData(Array('shape'=>'block', 'infos'=>Array('frmat'=>(array)($infoArr['frmat'] ?? Array())), 'items'=>$chapBlk), Array('xhtml'=>true, 'frmat'=>false));
			// A reference to a note and the note itself tell what they are: a reader that knows how opens the note in place
			$bodyHtm = strval(preg_replace(Array('/<a href="([^"#]*#[fe]note\d+)">/', '/<aside id="fnote(\d+)"/', '/<aside id="enote(\d+)"/'), Array('<a epub:type="noteref" href="$1">', '<aside epub:type="footnote" id="fnote$1"', '<aside epub:type="endnote" id="enote$1"'), $bodyHtm));
			$fileLst[self::BOOKROOT.$chapNme] = $pageTop.'<title>'.$this->escapeTxt($chpSet['title']).'</title>'."\n".'<link rel="stylesheet" type="text/css" href="style.css"/>'."\n".'</head>'."\n".'<body>'."\n".$bodyHtm.'</body>'."\n".'</html>'."\n";
			$itemTxt .= '<item id="chap'.($chpIdx+1).'" href="'.$chapNme.'" media-type="application/xhtml+xml"/>';
			$spneTxt .= '<itemref idref="chap'.($chpIdx+1).'"/>';
			// The headings of the chapter, for the table of contents
			foreach($chapBlk as $oneBlk){
				if( $oneBlk['block']=='headr' && intval($oneBlk['attrs']['level'] ?? 1)<=$tocDeep && trim($oneBlk['plain'])!=='' ){
					$headLst[] = Array('level'=>max(1, intval($oneBlk['attrs']['level'] ?? 1)), 'title'=>trim(strval(preg_replace('/\s+/', ' ', $oneBlk['plain']))), 'links'=>$chapNme.'#'.$oneBlk['attrs']['tagid']);
				}
			}
		}
		// A book without headings still has a table of contents: its chapters
		if( empty($headLst) ){
			foreach($chapLst as $chpIdx=>$chpSet){
				$headLst[] = Array('level'=>1, 'title'=>$chpSet['title'], 'links'=>'chap'.($chpIdx+1).'.xhtml');
			}
		}
		$headTre = $this->headsTree($headLst);
		// The pictures, each written once, and told for what it is; the cover is told as such, in the two ways readers look for it
		$covrIdn = '';
		foreach(array_values($this->mediaSet) as $medIdx=>$medSet){
			$fileLst[self::BOOKROOT.$medSet[0]] = $medSet[1];
			$itemTxt .= '<item id="img'.($medIdx+1).'" href="'.$medSet[0].'" media-type="'.self::PICTLIST[substr($medSet[0], intval(strrpos($medSet[0], '.'))+1)].'"'.(($medSet[0]===$covrNme) ? ' properties="cover-image"' : '').'/>';
			$covrIdn = ($medSet[0]===$covrNme) ? 'img'.($medIdx+1) : $covrIdn;
		}
		$bookIdn = $this->bookIdent($propLst, $bookTtl.'|'.$bookAut.'|'.Shape::wholeText($blckLst));
		$headXml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$metaTxt = '<dc:identifier id="bookid">'.$this->escapeTxt($bookIdn).'</dc:identifier><dc:title>'.$this->escapeTxt(($bookTtl==='') ? 'Book' : $bookTtl).'</dc:title><dc:language>'.$langKey.'</dc:language>'
			.(($bookAut==='') ? '' : '<dc:creator>'.$this->escapeTxt($bookAut).'</dc:creator>');
		foreach(Array('Subject'=>'dc:subject', 'Comments'=>'dc:description', 'Publisher'=>'dc:publisher', 'Rights'=>'dc:rights') as $prpNme=>$prpTag){
			$metaTxt .= (is_scalar($propLst[$prpNme] ?? null) && trim(strval($propLst[$prpNme]))!=='') ? '<'.$prpTag.'>'.$this->escapeTxt(trim(strval($propLst[$prpNme]))).'</'.$prpTag.'>' : '';
		}
		foreach(is_scalar($propLst['Keywords'] ?? null) ? array_filter(array_map('trim', explode(',', strval($propLst['Keywords']))), 'strlen') : Array() as $keyWrd){
			$metaTxt .= '<dc:subject>'.$this->escapeTxt($keyWrd).'</dc:subject>';
		}
		$fileLst = Array(
			'mimetype'=>self::FILEKIND,
			'META-INF/container.xml'=>$headXml.'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="'.self::BOOKROOT.'content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
			self::BOOKROOT.'content.opf'=>$headXml.'<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="'.$langKey.'"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'.$metaTxt
				.'<meta property="dcterms:modified">'.gmdate('Y-m-d\TH:i:s\Z').'</meta>'.(($covrIdn==='') ? '' : '<meta name="cover" content="'.$covrIdn.'"/>').'</metadata>'
				.'<manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/><item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/><item id="css" href="style.css" media-type="text/css"/>'.$itemTxt.'</manifest>'
				.'<spine toc="ncx">'.$spneTxt.'</spine></package>',
			self::BOOKROOT.'nav.xhtml'=>$headXml.'<!DOCTYPE html>'."\n".'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="'.$langKey.'" xml:lang="'.$langKey.'">'."\n"
				.'<head>'."\n".'<meta charset="utf-8"/>'."\n".'<title>'.$this->escapeTxt(($bookTtl==='') ? 'Book' : $bookTtl).'</title>'."\n".'<link rel="stylesheet" type="text/css" href="style.css"/>'."\n".'</head>'."\n"
				.'<body>'."\n".'<nav epub:type="toc" id="toc">'."\n".'<h1>'.$this->escapeTxt(($bookTtl==='') ? 'Book' : $bookTtl).'</h1>'."\n".$this->navigPart($headTre).'</nav>'."\n".'</body>'."\n".'</html>'."\n",
			self::BOOKROOT.'toc.ncx'=>$headXml.'<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><head><meta name="dtb:uid" content="'.$this->escapeTxt($bookIdn).'"/><meta name="dtb:depth" content="'.$tocDeep.'"/><meta name="dtb:totalPageCount" content="0"/><meta name="dtb:maxPageNumber" content="0"/></head>'
				.'<docTitle><text>'.$this->escapeTxt(($bookTtl==='') ? 'Book' : $bookTtl).'</text></docTitle><navMap>'.$this->ncxOfPart($headTre).'</navMap></ncx>',
			self::BOOKROOT.'style.css'=>$this->stylePart((array)($infoArr['frmat'] ?? Array()))
		) + $fileLst;
		try{
			// The first entry tells what the file is, and is kept as it is
			return Archv::makeBytes($fileLst, Array('mimetype'));
		}catch( \RuntimeException $e ){
			throw new WriteError('Book that cannot be written: '.$e->getMessage(), 0, $e);
		}
	}

	/**
	 * Gives the identifier of the book: the one told of it, or one made
	 * from its content, so that the same book always bears the same.
	 *
	 * @param Array  $propsArray The properties of the book: its identifier under 'Identifier', when it has one.
	 * @param String $bookContnt What the book holds, for the identifier made from it.
	 * @return String The identifier.
	 */
	protected function bookIdent(Array $propsArray, String $bookContnt): String {
		$toldIdn = is_scalar($propsArray['Identifier'] ?? null) ? trim(strval(preg_replace('/[\x00-\x20]+/', ' ', strval($propsArray['Identifier'])))) : '';
		if( $toldIdn!=='' ){
			return substr($toldIdn, 0, 200);
		}
		// An identifier written as those made from a name are: five groups of digits, the third and the fourth telling so
		$hashTxt = sha1('nyuRuns book|'.$bookContnt);
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
	 * @param Array $blocksList The blocks.
	 * @param Int   $headsCount The number of headings named so far; receives the new one.
	 * @return Array The blocks, ready.
	 */
	protected function prepBlock(Array $blocksList, Int &$headsCount): Array {
		$doneLst = Array();
		foreach($blocksList as $oneBlk){
			if( !is_array($oneBlk) || !isset($oneBlk['block']) ){
				continue;
			}
			$blckKnd = strval($oneBlk['block']);
			$attrLst = (array)($oneBlk['attrs'] ?? Array());
			$spanLst = Array();
			foreach((array)($oneBlk['spans'] ?? Array()) as $oneSpn){
				if( is_array($oneSpn) ){
					$spanLst[] = Shape::makeASpan(strval($oneSpn['texts'] ?? ''), (array)($oneSpn['marks'] ?? Array()), is_scalar($oneSpn['links'] ?? '') ? strval($oneSpn['links'] ?? '') : '');
				}
			}
			if( $blckKnd=='headr' && !preg_match('/^[A-Za-z][\w\-.]*$/D', strval($attrLst['tagid'] ?? '')) ){
				$headsCount++;
				$attrLst['tagid'] = 'sec-'.$headsCount;
			}
			if( $blckKnd=='entry' && isset($attrLst['check']) ){
				array_unshift($spanLst, Shape::makeASpan(empty($attrLst['check']) ? "\u{2610} " : "\u{2611} "));
				unset($attrLst['check']);
			}
			if( $blckKnd=='image' ){
				$pictSet = $this->pictrRead(is_scalar($attrLst['sourc'] ?? '') ? strval($attrLst['sourc'] ?? '') : '');
				$pictNme = ($pictSet===null) ? '' : $this->mediaName($pictSet);
				if( $pictNme==='' ){
					$blckKnd = 'parag';
					unset($attrLst['sourc']);
				}else{
					$attrLst['sourc'] = $pictNme;
				}
			}
			// A name a page of XHTML would refuse is not written
			if( isset($attrLst['tagid']) && !preg_match('/^[A-Za-z][\w\-.]*$/D', strval($attrLst['tagid'])) ){
				unset($attrLst['tagid']);
			}
			$doneLst[] = Shape::makeBlock($blckKnd, $spanLst, $this->prepBlock((array)($oneBlk['items'] ?? Array()), $headsCount), $attrLst);
		}
		return $doneLst;
	}

	/**
	 * Gives a picture its name in the book, where it is written once
	 * however often it is shown.
	 *
	 * @param Array $pictrArray The picture, as pictrRead() gives it.
	 * @return String Its name in the book; '' when the pictures of the book would then be more than a book may hold.
	 */
	protected function mediaName(Array $pictrArray): String {
		$pictKey = sha1($pictrArray['bytes']);
		if( !isset($this->mediaSet[$pictKey]) ){
			if( array_sum(array_map(function(Array $mediaEntry){ return strlen($mediaEntry[1]); }, $this->mediaSet))+strlen($pictrArray['bytes'])>self::MEDIAMAX ){
				return '';
			}
			$this->mediaSet[$pictKey] = Array('images/image'.(count($this->mediaSet)+1).'.'.$pictrArray['kinds'], $pictrArray['bytes']);
		}
		return $this->mediaSet[$pictKey][0];
	}

	/**
	 * Cuts the blocks of a book into its chapters: a chapter starts at
	 * each heading of the level asked for, or above it.
	 *
	 * @param Array  $blocksList The blocks.
	 * @param Int    $splitLevel The level of the headings that start a chapter, 0 for a book of one chapter.
	 * @param String $bookTitles The title of the book, given to a chapter that starts with no heading.
	 * @return Array The chapters, each with its title under 'title' and its blocks under 'blcks'; one at least.
	 */
	protected function chaptrsOf(Array $blocksList, Int $splitLevel, String $bookTitles): Array {
		$chapLst = Array();
		foreach($blocksList as $oneBlk){
			$isStart = ($oneBlk['block']=='headr' && $splitLevel>0 && intval($oneBlk['attrs']['level'] ?? 1)<=$splitLevel && trim($oneBlk['plain'])!=='');
			if( $isStart || empty($chapLst) ){
				$chapLst[] = Array('title'=>$isStart ? trim(strval(preg_replace('/\s+/', ' ', $oneBlk['plain']))) : $bookTitles, 'blcks'=>Array());
			}
			$chapLst[count($chapLst)-1]['blcks'][] = $oneBlk;
		}
		return empty($chapLst) ? Array(Array('title'=>$bookTitles, 'blcks'=>Array())) : $chapLst;
	}

	/**
	 * Lists the notes some blocks refer to, in the order they do.
	 *
	 * @param Array $blocksList The blocks.
	 * @return Array The names of the notes.
	 */
	protected function notesUsed(Array $blocksList): Array {
		$nameLst = Array();
		foreach($blocksList as $oneBlk){
			foreach($oneBlk['spans'] as $oneSpn){
				if( preg_match('/^#([fe]note\d+)$/D', $oneSpn['links']) ){
					$nameLst[] = substr($oneSpn['links'], 1);
				}
			}
			$nameLst = array_merge($nameLst, $this->notesUsed($oneBlk['items']));
		}
		return $nameLst;
	}

	/**
	 * Lists the places some blocks name.
	 *
	 * @param Array $blocksList The blocks.
	 * @return Array The names of the places.
	 */
	protected function placeList(Array $blocksList): Array {
		$nameLst = Array();
		foreach($blocksList as $oneBlk){
			if( strval($oneBlk['attrs']['tagid'] ?? '')!=='' ){
				$nameLst[] = strval($oneBlk['attrs']['tagid']);
			}
			$nameLst = array_merge($nameLst, $this->placeList($oneBlk['items']));
		}
		return $nameLst;
	}

	/**
	 * Makes the links of a chapter point where their place stands: a
	 * place of another chapter is told with the page of that chapter.
	 *
	 * @param Array  $blocksList The blocks of the chapter.
	 * @param Array  $placesList Where each named place stands: its name => the page of its chapter.
	 * @param String $chapterNme The page of this chapter.
	 * @return Array The blocks.
	 */
	protected function linkPlace(Array $blocksList, Array $placesList, String $chapterNme): Array {
		foreach($blocksList as $blkIdx=>$oneBlk){
			foreach($oneBlk['spans'] as $spnIdx=>$oneSpn){
				$plceNme = (substr($oneSpn['links'], 0, 1)=='#') ? substr($oneSpn['links'], 1) : '';
				if( $plceNme!=='' && isset($placesList[$plceNme]) && $placesList[$plceNme]!==$chapterNme ){
					$blocksList[$blkIdx]['spans'][$spnIdx]['links'] = $placesList[$plceNme].'#'.$plceNme;
				}
			}
			$blocksList[$blkIdx]['items'] = $this->linkPlace($oneBlk['items'], $placesList, $chapterNme);
		}
		return $blocksList;
	}

	/**
	 * Lays the headings of a book out as a tree: a heading holds those of
	 * the levels under it that follow it.
	 *
	 * @param Array $headsArray The headings, in order, each with its 'level', its 'title' and where it stands under 'links'.
	 * @return Array The headings of the highest level met, each with those it holds under 'holds'.
	 */
	protected function headsTree(Array $headsArray): Array {
		$treeLst = Array();
		// The way down to the heading being filled: each step is the list a heading of that depth goes into
		$pathLst = Array(&$treeLst);
		$lvlsLst = Array();
		foreach($headsArray as $oneHdr){
			while( !empty($lvlsLst) && end($lvlsLst)>=$oneHdr['level'] ){
				array_pop($lvlsLst);
				array_pop($pathLst);
			}
			$nowLst = &$pathLst[count($pathLst)-1];
			$nowLst[] = Array('title'=>$oneHdr['title'], 'links'=>$oneHdr['links'], 'holds'=>Array());
			$lvlsLst[] = $oneHdr['level'];
			$pathLst[] = &$nowLst[count($nowLst)-1]['holds'];
			unset($nowLst);
		}
		return $treeLst;
	}

	/**
	 * Writes the table of contents as the readers of today look for it:
	 * lists of links, one in another.
	 *
	 * @param Array $headsTrees The headings, as a tree.
	 * @return String The lists.
	 */
	protected function navigPart(Array $headsTrees): String {
		$listTxt = '<ol>'."\n";
		foreach($headsTrees as $oneHdr){
			$listTxt .= '<li><a href="'.$this->escapeTxt($oneHdr['links']).'">'.$this->escapeTxt($oneHdr['title']).'</a>'.(empty($oneHdr['holds']) ? '' : "\n".$this->navigPart($oneHdr['holds'])).'</li>'."\n";
		}
		return $listTxt.'</ol>'."\n";
	}

	/**
	 * Writes the table of contents as older readers look for it: points,
	 * one in another, each telling its place in the order of reading.
	 *
	 * @param Array $headsTrees The headings, as a tree.
	 * @param Int   $orderCount The number of points written so far; receives the new one.
	 * @return String The points.
	 */
	protected function ncxOfPart(Array $headsTrees, Int &$orderCount=0): String {
		$pntsTxt = '';
		foreach($headsTrees as $oneHdr){
			$orderCount++;
			$pntsTxt .= '<navPoint id="np'.$orderCount.'" playOrder="'.$orderCount.'"><navLabel><text>'.$this->escapeTxt($oneHdr['title']).'</text></navLabel><content src="'.$this->escapeTxt($oneHdr['links']).'"/>'.$this->ncxOfPart($oneHdr['holds'], $orderCount).'</navPoint>';
		}
		return $pntsTxt;
	}

	/**
	 * Writes the style sheet of the book: what every chapter looks like,
	 * then what the styles its paragraphs name give, as the HTML writer
	 * checks and writes them.
	 *
	 * @param Array $formatList The styles the content tells of.
	 * @return String The style sheet.
	 */
	protected function stylePart(Array $formatList): String {
		$stylPrt = Array();
		$stylHtm = (new Html())->writeData(Array('shape'=>'block', 'infos'=>Array('frmat'=>$formatList), 'items'=>Array()), Array('xhtml'=>true, 'frmat'=>true));
		return self::BASESTYL.(preg_match('/<style>\n(.*)\n<\/style>/s', $stylHtm, $stylPrt) ? $stylPrt[1]."\n" : '');
	}

}
