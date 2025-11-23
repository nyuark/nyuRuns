<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

/**
 * Word documents (docx), read into text blocks: headings by their style,
 * paragraphs, lists, tables, images, page breaks and notes, with what is
 * bold, italic, underlined or struck, and the links.
 *
 * Every block tells the style of its paragraph under 'style', and what
 * each style used gives is listed once, under 'frmat', in the details of
 * the document. A field, such as a page number or a reference to a
 * property of the document, is read as the text Word last showed for
 * it, its span telling what it stands for under 'field'.
 *
 * The headers and the footers of the pages, the properties of the
 * document and, when asked for, the comments of its reviewers are given
 * in the details of the document, not among its blocks. The text a
 * tracked change deleted is left out.
 */
class Docx extends Pack {

	// Properties of a document, by the element holding them: the name Word gives them in its fields
	const PROPLIST = Array('title'=>'Title', 'creator'=>'Author', 'subject'=>'Subject', 'keywords'=>'Keywords', 'description'=>'Comments', 'category'=>'Category', 'lastModifiedBy'=>'LastSavedBy', 'Company'=>'Company', 'Manager'=>'Manager');
	// Elements that only group pieces of a paragraph
	const WRAPLIST = Array('w:ins', 'w:moveTo', 'w:smartTag', 'w:customXml', 'w:dir', 'w:bdo');
	// The alignments, as Word writes them
	const ALGNLIST = Array('left'=>'lefts', 'start'=>'lefts', 'right'=>'right', 'end'=>'right', 'center'=>'centr', 'both'=>'justf', 'distribute'=>'justf');
	// The styles of Word that make another kind of block than a paragraph, by their name in lower case
	const KINDLIST = Array('quote'=>'quote', 'intense quote'=>'quote', 'html preformatted'=>'codes', 'plain text'=>'codes');
	// The fields that show a property Word defines, and the fields that show a date
	const FLDPROPS = Array('TITLE'=>'Title', 'AUTHOR'=>'Author', 'SUBJECT'=>'Subject', 'KEYWORDS'=>'Keywords', 'COMMENTS'=>'Comments', 'LASTSAVEDBY'=>'LastSavedBy');
	const FLDDATES = Array('DATE', 'TIME', 'CREATEDATE', 'SAVEDATE', 'PRINTDATE');

	// The relations of the part being read, the styles of the document, the style of the paragraphs that name none,
	// the styles met, and whether each level of its lists is numbered
	protected $relsList = Array();
	protected $stylList = Array();
	protected $dfltStyl = '';
	protected $usedStyl = Array();
	protected $numbList = Array();
	// The fonts of the theme of the document: the one of its text under 'minor', the one of its headings under 'major'
	protected $themeFnt = Array();
	// The field being read: 0 outside one, 1 in what it asks for, 2 in what it shows;
	// then what it asks for, the address it gives, and what it stands for
	protected $fldState = 0;
	protected $fldInstr = '';
	protected $fldLinks = '';
	protected $fldCodes = '';
	// The notes met, by kind: their id in the document => their number
	protected $noteNums = Array('fnote'=>Array(), 'enote'=>Array());
	// Whether empty paragraphs are kept, whether comments are read, the passages being commented and those that were
	protected $keepVoid = true;
	protected $readCmnt = false;
	protected $cmntOpen = Array();
	protected $cmntText = Array();

	/**
	 * Reads the document of an opened archive.
	 *
	 * Options:
	 *   'empty' => keeps the paragraphs holding nothing, as blocks
	 *              without text; on unless told otherwise;
	 *   'cmnts' => reads the comments of the reviewers; off unless told
	 *              otherwise.
	 *
	 * @param Array $parseOptns Options: 'empty', 'cmnts'.
	 * @return Array The envelope, of shape 'block'; 'infos' holds the title of the document under 'title', its author under 'authr', its properties under 'props', what each style used gives under 'frmat', the blocks of the headers and of the footers of its pages under 'phead' and 'pfoot' and, with the 'cmnts' option, its comments under 'cmnts'.
	 *
	 * @throws ParseError When the archive is not a valid text document.
	 */
	protected function readArchv(Array $parseOptns): Array {
		// The archive says where its document is; it is in word/document.xml unless told otherwise
		$docuPth = 'word/document.xml';
		foreach($this->relsOfPrt('') as $relSet){
			if( substr($relSet['types'], -15)=='/officeDocument' ){
				$docuPth = $relSet['paths'];
			}
		}
		$bodyArr = $this->findChild($this->partNodes($docuPth) ?? Array('nodes'=>Array()), Array('w:body'));
		if( $bodyArr===null ){
			throw new ParseError('Invalid archive: the document is not a text document');
		}
		$docuRel = $this->relsOfPrt($docuPth);
		$this->relsList = $docuRel;
		$this->stylList = Array();
		$this->dfltStyl = '';
		$this->usedStyl = Array();
		$this->numbList = Array();
		$this->fldState = 0;
		$this->fldLinks = '';
		$this->fldCodes = '';
		$this->noteNums = Array('fnote'=>Array(), 'enote'=>Array());
		$this->keepVoid = !isset($parseOptns['empty']) || !empty($parseOptns['empty']);
		$this->readCmnt = !empty($parseOptns['cmnts']);
		$this->cmntOpen = Array();
		$this->cmntText = Array();
		// A style may name its font through the theme: the theme is read before the styles
		$this->themeFnt = Array();
		foreach($docuRel as $relSet){
			if( substr($relSet['types'], -6)=='/theme' ){
				$fontArr = $this->deepNamed($this->partNodes($relSet['paths'], false) ?? Array('nodes'=>Array()), Array('a:fontScheme')) ?? Array('nodes'=>Array());
				foreach(Array('minor'=>'a:minorFont', 'major'=>'a:majorFont') as $fntKey=>$fntTag){
					$this->themeFnt[$fntKey] = strval($this->findChild($fontArr, Array($fntTag, 'a:latin'))['attrs']['typeface'] ?? '');
				}
			}
		}
		foreach($docuRel as $relSet){
			if( substr($relSet['types'], -7)=='/styles' ){
				$this->loadStyls($relSet['paths']);
			}elseif( substr($relSet['types'], -10)=='/numbering' ){
				$this->loadNumbr($relSet['paths']);
			}
		}
		$blckLst = $this->bodyBlcks($bodyArr);
		// What stands outside the text is read without its empty paragraphs
		$this->keepVoid = false;
		$blckLst = array_merge($blckLst, $this->notesRead($docuRel));
		$propLst = $this->propsRead();
		$infoArr = Array('title'=>strval($propLst['Title'] ?? ''), 'authr'=>strval($propLst['Author'] ?? ''), 'props'=>$propLst, 'frmat'=>Array());
		$infoArr['phead'] = $this->pagesRead($docuRel, '/header');
		$infoArr['pfoot'] = $this->pagesRead($docuRel, '/footer');
		if( $this->readCmnt ){
			$infoArr['cmnts'] = $this->cmntsRead($docuRel);
		}
		foreach(array_keys($this->usedStyl) as $stlKey){
			$infoArr['frmat'][$this->stylList[$stlKey]['names']] = $this->frmatOfIt($this->stylList[$stlKey]);
		}
		$this->relsList = Array();
		$this->stylList = Array();
		$this->cmntText = Array();
		return $this->makeShape('block', $blckLst, $infoArr);
	}

	/**
	 * Tells whether a property written as an element is set: it is when
	 * the element is there, unless it says otherwise.
	 *
	 * @param ?Array $nodeValues The element, null when it is not there.
	 * @return Bool True when the property is set.
	 */
	protected function toggledOn(?Array $nodeValues): Bool {
		return ($nodeValues!==null && !in_array(strtolower(strval($nodeValues['attrs']['w:val'] ?? '1')), Array('0', 'false', 'off', 'none')));
	}

	/**
	 * Gives the formatting the properties of a run, or of a style, ask for.
	 *
	 * @param Array $nodeValues The element holding those properties.
	 * @return Array The formatting: bold, ital, undr, strk.
	 */
	protected function marksOfRn(Array $nodeValues): Array {
		$propArr = $this->findChild($nodeValues, Array('w:rPr'));
		$markLst = Array();
		if( $propArr===null ){
			return $markLst;
		}
		foreach(Array('w:b'=>'bold', 'w:i'=>'ital', 'w:u'=>'undr', 'w:strike'=>'strk', 'w:dstrike'=>'strk') as $prpNme=>$mrkKey){
			if( $this->toggledOn($this->findChild($propArr, Array($prpNme))) && !in_array($mrkKey, $markLst) ){
				$markLst[] = $mrkKey;
			}
		}
		return $markLst;
	}

	/**
	 * Gives the font, the size and the colour the properties of a style
	 * ask for.
	 *
	 * @param Array $nodeValues The element holding those properties.
	 * @return Array The font under 'fonts', the size in points under 'sizes' and the colour under 'color'; '' or 0 for what is not told.
	 */
	protected function fontOfSty(Array $nodeValues): Array {
		$propArr = $this->findChild($nodeValues, Array('w:rPr')) ?? Array('nodes'=>Array());
		$fontArr = $this->findChild($propArr, Array('w:rFonts'))['attrs'] ?? Array();
		$sizeVal = strval($this->findChild($propArr, Array('w:sz'))['attrs']['w:val'] ?? '');
		$colrVal = strval($this->findChild($propArr, Array('w:color'))['attrs']['w:val'] ?? '');
		return Array(
			'fonts'=>strval($fontArr['w:ascii'] ?? $fontArr['w:hAnsi'] ?? (isset($fontArr['w:asciiTheme']) ? ($this->themeFnt[substr(strtolower($fontArr['w:asciiTheme']), 0, 5)] ?? '') : ($fontArr['w:cs'] ?? ''))),
			// Word counts the sizes in half points
			'sizes'=>is_numeric($sizeVal) ? floatval($sizeVal)/2 : 0,
			'color'=>preg_match('/^[0-9A-Fa-f]{6}$/D', $colrVal) ? '#'.strtoupper($colrVal) : ''
		);
	}

	/**
	 * Reads the styles of the document: which ones make a heading, and
	 * of what level, which ones belong to a list, and the formatting each
	 * gives. A style takes what it does not tell from the one it is based
	 * on, then from what the document gives every text.
	 *
	 * @param String $insideName Name of the part holding the styles.
	 */
	protected function loadStyls(String $insideName): Void {
		$stlsArr = $this->partNodes($insideName, false) ?? Array('nodes'=>Array());
		foreach($this->kidsNamed($stlsArr, 'w:style') as $stlArr){
			$stylNme = strval($this->findChild($stlArr, Array('w:name'))['attrs']['w:val'] ?? '');
			$stylKey = strval($stlArr['attrs']['w:styleId'] ?? '');
			$levlPrt = Array();
			$hdrLevl = 0;
			// Word names its heading styles the same in every language
			if( preg_match('/^heading ([1-9])$/Di', $stylNme, $levlPrt) ){
				$hdrLevl = min(6, intval($levlPrt[1]));
			}elseif( strtolower($stylNme)=='title' ){
				$hdrLevl = 1;
			}
			$outlLvl = strval($this->findChild($stlArr, Array('w:pPr', 'w:outlineLvl'))['attrs']['w:val'] ?? '');
			if( $hdrLevl==0 && preg_match('/^[0-8]$/D', $outlLvl) ){
				$hdrLevl = min(6, intval($outlLvl)+1);
			}
			$numbArr = $this->findChild($stlArr, Array('w:pPr', 'w:numPr')) ?? Array('nodes'=>Array());
			$this->stylList[$stylKey] = Array(
				'names'=>$stylNme, 'level'=>$hdrLevl, 'marks'=>$this->marksOfRn($stlArr),
				'numid'=>strval($this->findChild($numbArr, Array('w:numId'))['attrs']['w:val'] ?? ''), 'ilevl'=>intval($this->findChild($numbArr, Array('w:ilvl'))['attrs']['w:val'] ?? 0),
				'align'=>strval(self::ALGNLIST[strtolower(strval($this->findChild($stlArr, Array('w:pPr', 'w:jc'))['attrs']['w:val'] ?? ''))] ?? ''),
				'based'=>strval($this->findChild($stlArr, Array('w:basedOn'))['attrs']['w:val'] ?? '')
			) + $this->fontOfSty($stlArr);
			if( strtolower(strval($stlArr['attrs']['w:type'] ?? ''))=='paragraph' && in_array(strtolower(strval($stlArr['attrs']['w:default'] ?? '')), Array('1', 'true')) ){
				$this->dfltStyl = $stylKey;
			}
		}
		$baseSet = $this->fontOfSty($this->findChild($stlsArr, Array('w:docDefaults', 'w:rPrDefault')) ?? Array('nodes'=>Array()));
		foreach(array_keys($this->stylList) as $stlKey){
			$prntKey = $this->stylList[$stlKey]['based'];
			// Twenty styles based one on the other is more than any document has, and stops a circle
			for($hopIdx=0; $hopIdx<21; $hopIdx++){
				$prntSet = $this->stylList[$prntKey] ?? null;
				if( $prntSet===null || $hopIdx==20 ){
					$prntSet = $baseSet + Array('level'=>0, 'numid'=>'', 'ilevl'=>0, 'align'=>'', 'marks'=>Array(), 'based'=>'');
					$hopIdx = 20;
				}
				if( $this->stylList[$stlKey]['level']==0 ){
					$this->stylList[$stlKey]['level'] = $prntSet['level'];
				}
				if( $this->stylList[$stlKey]['numid']==='' ){
					$this->stylList[$stlKey]['numid'] = $prntSet['numid'];
					$this->stylList[$stlKey]['ilevl'] = $prntSet['ilevl'];
				}
				foreach(Array('align', 'fonts', 'sizes', 'color') as $prpKey){
					if( empty($this->stylList[$stlKey][$prpKey]) ){
						$this->stylList[$stlKey][$prpKey] = $prntSet[$prpKey];
					}
				}
				$this->stylList[$stlKey]['marks'] = array_values(array_intersect(Shape::MARKLIST, array_merge($this->stylList[$stlKey]['marks'], $prntSet['marks'])));
				$prntKey = $prntSet['based'];
			}
		}
	}

	/**
	 * Reads the lists the document defines: for each of them, and each of
	 * its levels, whether it is numbered or only marked.
	 *
	 * @param String $insideName Name of the part holding them.
	 */
	protected function loadNumbr(String $insideName): Void {
		$numbArr = $this->partNodes($insideName, false) ?? Array('nodes'=>Array());
		$abstLst = Array();
		foreach($this->kidsNamed($numbArr, 'w:abstractNum') as $absArr){
			foreach($this->kidsNamed($absArr, 'w:lvl') as $lvlArr){
				$numbFmt = strtolower(strval($this->findChild($lvlArr, Array('w:numFmt'))['attrs']['w:val'] ?? 'decimal'));
				$abstLst[strval($absArr['attrs']['w:abstractNumId'] ?? '')][intval($lvlArr['attrs']['w:ilvl'] ?? 0)] = ($numbFmt!='bullet' && $numbFmt!='none');
			}
		}
		foreach($this->kidsNamed($numbArr, 'w:num') as $numArr){
			$this->numbList[strval($numArr['attrs']['w:numId'] ?? '')] = $abstLst[strval($this->findChild($numArr, Array('w:abstractNumId'))['attrs']['w:val'] ?? '')] ?? Array();
		}
	}

	/**
	 * Reads the properties of the document: the ones Word defines, then
	 * the ones added to it.
	 *
	 * @return Array The properties, by the name Word gives them in its fields; those that are empty are left out.
	 */
	protected function propsRead(): Array {
		$propLst = Array();
		foreach(Array('docProps/core.xml', 'docProps/app.xml') as $prtNme){
			foreach(($this->partNodes($prtNme, false) ?? Array('nodes'=>Array()))['nodes'] as $prpArr){
				$bareNme = substr($prpArr['tagnm'], intval(strrpos(':'.$prpArr['tagnm'], ':')));
				if( isset(self::PROPLIST[$bareNme]) && trim($prpArr['value'])!=='' ){
					$propLst[self::PROPLIST[$bareNme]] = trim($prpArr['value']);
				}
			}
		}
		foreach($this->kidsNamed($this->partNodes('docProps/custom.xml', false) ?? Array('nodes'=>Array()), 'property') as $prpArr){
			$valuArr = $prpArr['nodes'][0] ?? null;
			$propNme = strval($prpArr['attrs']['name'] ?? '');
			if( $valuArr===null || $propNme==='' ){
				continue;
			}
			// A property tells its type by the name of the element holding its value
			$typeNme = substr($valuArr['tagnm'], intval(strrpos(':'.$valuArr['tagnm'], ':')));
			$rawValu = $this->textOfTag($valuArr);
			if( $typeNme=='bool' ){
				$propLst[$propNme] = (strtolower($rawValu)=='true' || $rawValu=='1');
			}elseif( preg_match('/^(u?i[1248]|u?int)$/D', $typeNme) && preg_match('/^-?\d{1,18}$/D', $rawValu) ){
				$propLst[$propNme] = intval($rawValu);
			}elseif( in_array($typeNme, Array('r4', 'r8', 'decimal')) && is_numeric($rawValu) ){
				$propLst[$propNme] = floatval($rawValu);
			}else{
				$propLst[$propNme] = $rawValu;
			}
		}
		return $propLst;
	}

	/**
	 * Reads the blocks of a part of the document other than its body,
	 * with the relations that part has of its own.
	 *
	 * @param String $insideName Name of the part.
	 * @param ?Array $nodeValues The element of that part holding the blocks, null for its root.
	 * @return Array The blocks.
	 */
	protected function partBlcks(String $insideName, ?Array $nodeValues=null): Array {
		$keptRel = $this->relsList;
		$this->relsList = $this->relsOfPrt($insideName);
		$blckLst = $this->bodyBlcks($nodeValues ?? $this->partNodes($insideName, false) ?? Array('nodes'=>Array()));
		$this->relsList = $keptRel;
		return $blckLst;
	}

	/**
	 * Reads the notes the text refers to, in the order it refers to
	 * them: the notes of the pages first, then those of the end.
	 *
	 * @param Array $docuRelats The relations of the document.
	 * @return Array The notes, as blocks.
	 */
	protected function notesRead(Array $docuRelats): Array {
		$blckLst = Array();
		foreach(Array('fnote'=>Array('/footnotes', 'w:footnote'), 'enote'=>Array('/endnotes', 'w:endnote')) as $nteKnd=>$nteSet){
			$numsLst = $this->noteNums[$nteKnd];
			foreach($docuRelats as $relSet){
				if( substr($relSet['types'], -strlen($nteSet[0]))!==$nteSet[0] || empty($numsLst) ){
					continue;
				}
				$noteLst = Array();
				foreach($this->kidsNamed($this->partNodes($relSet['paths'], false) ?? Array('nodes'=>Array()), $nteSet[1]) as $nteArr){
					$noteLst[strval($nteArr['attrs']['w:id'] ?? '')] = $nteArr;
				}
				foreach($numsLst as $nteIdn=>$nteNum){
					if( isset($noteLst[$nteIdn]) ){
						$blckLst[] = $this->holdBlock('fnote', $this->partBlcks($relSet['paths'], $noteLst[$nteIdn]), Array('tagid'=>$nteKnd.$nteNum, 'numbr'=>$nteNum));
					}
				}
			}
		}
		return $blckLst;
	}

	/**
	 * Reads the headers, or the footers, of the pages. A document may
	 * have several: each one that differs is given once.
	 *
	 * @param Array  $docuRelats The relations of the document.
	 * @param String $typeEnding What ends the kind of the relations to read: '/header' or '/footer'.
	 * @return Array Their blocks.
	 */
	protected function pagesRead(Array $docuRelats, String $typeEnding): Array {
		$blckLst = Array();
		$seenLst = Array();
		foreach($docuRelats as $relSet){
			if( substr($relSet['types'], -strlen($typeEnding))!==$typeEnding ){
				continue;
			}
			$partBlk = $this->partBlcks($relSet['paths']);
			$partKey = md5(serialize($partBlk));
			if( !empty($partBlk) && !isset($seenLst[$partKey]) ){
				$seenLst[$partKey] = true;
				$blckLst = array_merge($blckLst, $partBlk);
			}
		}
		return $blckLst;
	}

	/**
	 * Reads the comments of the reviewers.
	 *
	 * @param Array $docuRelats The relations of the document.
	 * @return Array The comments, each with its author under 'authr', its date under 'dated', its text under 'plain' and the passage it is about under 'about'.
	 */
	protected function cmntsRead(Array $docuRelats): Array {
		$cmntLst = Array();
		foreach($docuRelats as $relSet){
			if( substr($relSet['types'], -9)!=='/comments' ){
				continue;
			}
			foreach($this->kidsNamed($this->partNodes($relSet['paths'], false) ?? Array('nodes'=>Array()), 'w:comment') as $cmtArr){
				$cmntLst[] = Array(
					'authr'=>strval($cmtArr['attrs']['w:author'] ?? ''), 'dated'=>strval($cmtArr['attrs']['w:date'] ?? ''),
					'plain'=>Shape::wholeText($this->partBlcks($relSet['paths'], $cmtArr)),
					'about'=>trim(strval($this->cmntText[strval($cmtArr['attrs']['w:id'] ?? '')] ?? ''))
				);
			}
		}
		return $cmntLst;
	}

	/**
	 * Follows the marks that open and close the passage a comment is
	 * about.
	 *
	 * @param Array $nodeValues An element met in the text.
	 * @return Bool True when the element was such a mark.
	 */
	protected function cmntMarks(Array $nodeValues): Bool {
		if( $nodeValues['tagnm']!='w:commentRangeStart' && $nodeValues['tagnm']!='w:commentRangeEnd' ){
			return false;
		}
		$cmntIdn = strval($nodeValues['attrs']['w:id'] ?? '');
		if( $this->readCmnt && $nodeValues['tagnm']=='w:commentRangeStart' ){
			$this->cmntOpen[$cmntIdn] = '';
		}elseif( isset($this->cmntOpen[$cmntIdn]) ){
			$this->cmntText[$cmntIdn] = $this->cmntOpen[$cmntIdn];
			unset($this->cmntOpen[$cmntIdn]);
		}
		return true;
	}

	/**
	 * Reads what holds paragraphs and tables: the body of the document,
	 * a cell of a table, a note, a header. The paragraphs of a list that
	 * follow one another make that list, and so do the lines of code.
	 *
	 * @param Array $nodeValues The element.
	 * @return Array The blocks.
	 */
	protected function bodyBlcks(Array $nodeValues): Array {
		$blckLst = Array();
		$listBuf = Array();
		$codeBuf = Array();
		$bodyNds = $nodeValues['nodes'];
		// An end is added to the nodes, so that a list ending them is closed like any other
		$bodyNds[] = Array('tagnm'=>'#end', 'attrs'=>Array(), 'nodes'=>Array());
		foreach($bodyNds as $chlArr){
			$paraSet = ($chlArr['tagnm']=='w:p') ? $this->paraParts($chlArr) : null;
			$paraKnd = strval($paraSet['kinds'] ?? '');
			if( $paraKnd!='entry' && !empty($listBuf) ){
				$itemPos = 0;
				$blckLst = array_merge($blckLst, $this->listBlcks($listBuf, $itemPos, 0));
				$listBuf = Array();
			}
			if( $paraKnd!='codes' && !empty($codeBuf) ){
				$blckLst = array_merge($blckLst, $this->codeBlock($codeBuf));
				$codeBuf = Array();
			}
			if( $paraKnd=='entry' ){
				if( $this->keepVoid || !empty($paraSet['spans']) || !empty($paraSet['holds']) ){
					$listBuf[] = $paraSet;
				}
			}elseif( $paraKnd=='codes' ){
				$codeBuf[] = $paraSet;
			}elseif( $paraSet!==null ){
				$blckLst = array_merge($blckLst, $this->paraBlcks($paraSet));
			}elseif( $chlArr['tagnm']=='w:tbl' ){
				$tblBlck = $this->tableBlck($chlArr);
				if( !empty($tblBlck['items']) ){
					$blckLst[] = $tblBlck;
				}
			}elseif( $chlArr['tagnm']=='w:sdt' ){
				$blckLst = array_merge($blckLst, $this->bodyBlcks($this->findChild($chlArr, Array('w:sdtContent')) ?? Array('nodes'=>Array())));
			}else{
				$this->cmntMarks($chlArr);
			}
		}
		return $blckLst;
	}

	/**
	 * Reads a paragraph: what it is, and what it holds.
	 *
	 * @param Array $nodeValues The element of the paragraph.
	 * @return Array What it is under 'kinds' (headr, entry, quote, codes or parag), its level as a heading under 'level', the list it belongs to under 'numid', its level in it under 'ilevl' and whether that level is numbered under 'order', its style, its alignment and its id under 'attrs', its text under 'spans' and as written under 'plain', the images and the page breaks it holds under 'holds', all of it in order, as blocks, under 'flows', and whether a page break stands before or after it under 'prior' and 'after'.
	 */
	protected function paraParts(Array $nodeValues): Array {
		$propArr = $this->findChild($nodeValues, Array('w:pPr')) ?? Array('nodes'=>Array());
		$stylKey = strval($this->findChild($propArr, Array('w:pStyle'))['attrs']['w:val'] ?? '');
		if( !isset($this->stylList[$stylKey]) ){
			$stylKey = $this->dfltStyl;
		}
		$stylSet = $this->stylList[$stylKey] ?? Array('names'=>'', 'level'=>0, 'numid'=>'', 'ilevl'=>0, 'align'=>'');
		$hdrLevl = $stylSet['level'];
		$outlLvl = strval($this->findChild($propArr, Array('w:outlineLvl'))['attrs']['w:val'] ?? '');
		if( preg_match('/^[0-8]$/D', $outlLvl) ){
			$hdrLevl = min(6, intval($outlLvl)+1);
		}
		// What the paragraph says of its list comes before what its style says
		$numbArr = $this->findChild($propArr, Array('w:numPr'));
		$numIdnt = ($numbArr===null) ? $stylSet['numid'] : strval($this->findChild($numbArr, Array('w:numId'))['attrs']['w:val'] ?? '');
		$numLevl = ($numbArr===null) ? $stylSet['ilevl'] : intval($this->findChild($numbArr, Array('w:ilvl'))['attrs']['w:val'] ?? 0);
		// A passage commented over several paragraphs has a space between two of them
		foreach($this->cmntOpen as $cmtIdn=>$cmtTxt){
			$this->cmntOpen[$cmtIdn] = ($cmtTxt==='') ? '' : $cmtTxt.' ';
		}
		$pceList = Array();
		$this->runsParts($nodeValues, '', $pceList);
		$flowSet = $this->flowParts($pceList, $this->keepVoid);
		$spnsAll = $flowSet['spans'];
		$attrLst = Array();
		if( $stylSet['names']!=='' ){
			$attrLst['style'] = $stylSet['names'];
			$this->usedStyl[$stylKey] = true;
		}
		// The alignment is told when the paragraph does not follow its style
		$ownAlgn = strval(self::ALGNLIST[strtolower(strval($this->findChild($propArr, Array('w:jc'))['attrs']['w:val'] ?? ''))] ?? '');
		if( $ownAlgn!=='' && $ownAlgn!==$stylSet['align'] && !($ownAlgn=='lefts' && $stylSet['align']==='') ){
			$attrLst['align'] = $ownAlgn;
		}
		// A bookmark starting the paragraph names it; those Word adds for itself start with an underscore
		foreach($this->kidsNamed($nodeValues, 'w:bookmarkStart') as $bkmArr){
			$bkmName = strval($bkmArr['attrs']['w:name'] ?? '');
			if( $bkmName!=='' && $bkmName[0]!='_' && !isset($attrLst['tagid']) ){
				$attrLst['tagid'] = $bkmName;
			}
		}
		$paraKnd = 'parag';
		if( $hdrLevl>0 && !empty($spnsAll) ){
			$paraKnd = 'headr';
		}elseif( $hdrLevl==0 && $numIdnt!=='' && $numIdnt!=='0' ){
			$paraKnd = 'entry';
		}elseif( $hdrLevl==0 && isset(self::KINDLIST[strtolower($stylSet['names'])]) && (!empty($spnsAll) || self::KINDLIST[strtolower($stylSet['names'])]=='codes') ){
			$paraKnd = self::KINDLIST[strtolower($stylSet['names'])];
		}
		$sectArr = $this->findChild($propArr, Array('w:sectPr'));
		return Array(
			'kinds'=>$paraKnd, 'level'=>$hdrLevl,
			'numid'=>$numIdnt, 'ilevl'=>$numLevl, 'order'=>!empty($this->numbList[$numIdnt][$numLevl]),
			'attrs'=>$attrLst, 'spans'=>$spnsAll, 'plain'=>$flowSet['plain'], 'holds'=>$flowSet['holds'], 'flows'=>$flowSet['flows'],
			'prior'=>$this->toggledOn($this->findChild($propArr, Array('w:pageBreakBefore'))),
			// A section that starts on a new page ends this one
			'after'=>($sectArr!==null && strtolower(strval($this->findChild($sectArr, Array('w:type'))['attrs']['w:val'] ?? 'nextPage'))!='continuous')
		);
	}

	/**
	 * Tells what a field stands for, from what it asks for.
	 *
	 * @param String $fieldInstr What the field asks for, as Word writes it.
	 * @return String 'pgnum', 'pgtot', 'dates', 'props:' followed by the name of a property, or '' for any other field.
	 */
	protected function fieldCode(String $fieldInstr): String {
		$instPrt = Array();
		if( !preg_match('/^\s*([A-Za-z]+)(?:\s+(?:"([^"]*)"|([^\s\\\\]+)))?/', $fieldInstr, $instPrt) ){
			return '';
		}
		$fldName = strtoupper($instPrt[1]);
		if( $fldName=='PAGE' ){
			return 'pgnum';
		}
		if( $fldName=='NUMPAGES' || $fldName=='SECTIONPAGES' ){
			return 'pgtot';
		}
		if( in_array($fldName, self::FLDDATES) ){
			return 'dates';
		}
		if( isset(self::FLDPROPS[$fldName]) ){
			return 'props:'.self::FLDPROPS[$fldName];
		}
		$propNme = strval(($instPrt[2] ?? '')!=='' ? $instPrt[2] : ($instPrt[3] ?? ''));
		return ($fldName=='DOCPROPERTY' && $propNme!=='') ? 'props:'.$propNme : '';
	}

	/**
	 * Reads the pieces of a paragraph: its runs of text, with their
	 * formatting and their link, its images and its page breaks.
	 *
	 * @param Array  $nodeValues The paragraph, or an element grouping some of its pieces.
	 * @param String $linkTarget Address given by a link around them, '' when there is none.
	 * @param Array  $piecesList Receives the pieces, in order: spans, and images and page breaks as blocks.
	 */
	protected function runsParts(Array $nodeValues, String $linkTarget, Array &$piecesList): Void {
		foreach($nodeValues['nodes'] as $chlArr){
			$chldNme = $chlArr['tagnm'];
			if( $chldNme=='w:hyperlink' ){
				// A link points to an address kept in the relations of the document, or to a bookmark of the document itself
				$relSet = $this->relsList[strval($chlArr['attrs']['r:id'] ?? '')] ?? null;
				$anchrTx = strval($chlArr['attrs']['w:anchor'] ?? '');
				$linkAdr = Shape::cleanLink((($relSet!==null) ? $relSet['links'] : '').(($anchrTx!=='') ? '#'.$anchrTx : ''));
				$this->runsParts($chlArr, ($linkAdr!=='') ? $linkAdr : $linkTarget, $piecesList);
			}elseif( $chldNme=='w:fldSimple' ){
				// A field written in one piece: what it asks for is told beside what it shows
				$keptCod = $this->fldCodes;
				$this->fldCodes = $this->fieldCode(strval($chlArr['attrs']['w:instr'] ?? ''));
				$this->runsParts($chlArr, $linkTarget, $piecesList);
				$this->fldCodes = $keptCod;
			}elseif( in_array($chldNme, self::WRAPLIST) ){
				$this->runsParts($chlArr, $linkTarget, $piecesList);
			}elseif( $chldNme=='w:sdt' ){
				$this->runsParts($this->findChild($chlArr, Array('w:sdtContent')) ?? Array('nodes'=>Array()), $linkTarget, $piecesList);
			}elseif( $chldNme=='w:r' ){
				$markLst = array_values(array_intersect(Shape::MARKLIST, array_merge($this->stylList[strval($this->findChild($chlArr, Array('w:rPr', 'w:rStyle'))['attrs']['w:val'] ?? '')]['marks'] ?? Array(), $this->marksOfRn($chlArr))));
				foreach($chlArr['nodes'] as $runPrt){
					$this->runsPiece($runPrt, $markLst, $linkTarget, $piecesList);
				}
			}else{
				$this->cmntMarks($chlArr);
			}
		}
	}

	/**
	 * Reads one piece of a run: text, a tabulation, a line break, a page
	 * break, an image, the reference to a note, or a mark of a field. A
	 * field is read as the text Word last showed for it: what it asks
	 * for is left out, apart from what it tells of that text.
	 *
	 * @param Array  $nodeValues The element of the piece.
	 * @param Array  $marksArray Formatting of the run.
	 * @param String $linkTarget Address given by a link around the run, '' when there is none.
	 * @param Array  $piecesList Receives the piece.
	 */
	protected function runsPiece(Array $nodeValues, Array $marksArray, String $linkTarget, Array &$piecesList): Void {
		$pceName = $nodeValues['tagnm'];
		if( $pceName=='w:fldChar' ){
			$charTyp = strtolower(strval($nodeValues['attrs']['w:fldCharType'] ?? ''));
			$instPrt = Array();
			$this->fldLinks = '';
			$this->fldCodes = '';
			if( $charTyp=='begin' ){
				$this->fldState = 1;
				$this->fldInstr = '';
			}elseif( $charTyp=='separate' ){
				$this->fldState = 2;
				$this->fldCodes = $this->fieldCode($this->fldInstr);
				if( preg_match('/^\s*HYPERLINK\s+(\\\\l\s+)?"([^"]*)"/i', $this->fldInstr, $instPrt) ){
					$this->fldLinks = Shape::cleanLink((($instPrt[1]!=='') ? '#' : '').$instPrt[2]);
				}
			}else{
				$this->fldState = 0;
			}
			return;
		}
		if( $this->fldState==1 ){
			$this->fldInstr .= ($pceName=='w:instrText') ? $this->textOfTag($nodeValues) : '';
			return;
		}
		$pceText = '';
		$pceLink = ($linkTarget!=='') ? $linkTarget : $this->fldLinks;
		if( $pceName=='w:t' ){
			$pceText = $this->textOfTag($nodeValues);
		}elseif( $pceName=='w:tab' ){
			$pceText = "\t";
		}elseif( $pceName=='w:br' && strtolower(strval($nodeValues['attrs']['w:type'] ?? ''))=='page' ){
			$piecesList[] = Shape::makeBlock('rules', Array(), Array(), Array('break'=>true));
		}elseif( $pceName=='w:cr' || ($pceName=='w:br' && strtolower(strval($nodeValues['attrs']['w:type'] ?? ''))!='column') ){
			$pceText = "\n";
		}elseif( $pceName=='w:noBreakHyphen' ){
			$pceText = "\u{2011}";
		}elseif( $pceName=='w:footnoteReference' || $pceName=='w:endnoteReference' ){
			// A note is numbered by the place of its first reference in the text
			$noteKnd = ($pceName=='w:footnoteReference') ? 'fnote' : 'enote';
			$noteIdn = strval($nodeValues['attrs']['w:id'] ?? '');
			if( !isset($this->noteNums[$noteKnd][$noteIdn]) ){
				$this->noteNums[$noteKnd][$noteIdn] = count($this->noteNums[$noteKnd])+1;
			}
			$piecesList[] = Shape::makeASpan(strval($this->noteNums[$noteKnd][$noteIdn]), Array(), '#'.$noteKnd.$this->noteNums[$noteKnd][$noteIdn]);
			return;
		}elseif( in_array($pceName, Array('w:drawing', 'w:pict', 'w:object')) ){
			$imgBlck = $this->imageBlck($nodeValues);
			if( $imgBlck!==null ){
				$piecesList[] = $imgBlck;
			}
		}
		if( $pceText!=='' ){
			$piecesList[] = Shape::makeASpan($pceText, $marksArray, $pceLink, $this->fldCodes);
			foreach(array_keys($this->cmntOpen) as $cmtIdn){
				$this->cmntOpen[$cmtIdn] .= $pceText;
			}
		}
	}

	/**
	 * Reads an image placed in a paragraph.
	 *
	 * @param Array $nodeValues The element of the drawing.
	 * @return ?Array The image, as a block: its address, in the archive or outside it, and its description as text. Null when the drawing holds no image.
	 */
	protected function imageBlck(Array $nodeValues): ?Array {
		$blipArr = $this->deepNamed($nodeValues, Array('a:blip', 'v:imagedata'));
		if( $blipArr===null ){
			return null;
		}
		$relSet = $this->relsList[strval($blipArr['attrs']['r:embed'] ?? $blipArr['attrs']['r:link'] ?? $blipArr['attrs']['r:id'] ?? '')] ?? null;
		if( $relSet===null ){
			return null;
		}
		$imgSrce = Shape::cleanLink($relSet['outer'] ? $relSet['links'] : $relSet['paths']);
		if( $imgSrce==='' ){
			return null;
		}
		$descArr = $this->deepNamed($nodeValues, Array('wp:docPr'));
		$imgText = trim(strval($descArr['attrs']['descr'] ?? $blipArr['attrs']['o:title'] ?? ''));
		return Shape::makeBlock('image', ($imgText!=='') ? Array(Shape::makeASpan($imgText)) : Array(), Array(), Array('sourc'=>$imgSrce));
	}

	/**
	 * Builds the lists of some paragraphs that follow one another, from
	 * the level each has: a paragraph further in belongs to the entry
	 * before it, and a change of list at one level starts a new one.
	 *
	 * @param Array $itemsArray The paragraphs, as paraParts() gives them.
	 * @param Int   $itemOffset The paragraph to start from; receives the one following the lists built.
	 * @param Int   $levelValue The level of the lists to build.
	 * @return Array The lists, as blocks.
	 */
	protected function listBlcks(Array $itemsArray, Int &$itemOffset, Int $levelValue): Array {
		$listLst = Array();
		$entrLst = Array();
		$nowNumb = '';
		$nowOrdr = false;
		while( isset($itemsArray[$itemOffset]) && $itemsArray[$itemOffset]['ilevl']>=$levelValue ){
			$itemSet = $itemsArray[$itemOffset];
			if( $itemSet['ilevl']>$levelValue ){
				// Further in: the lists of that level go into the entry before them, an empty one when there is none
				$deepLst = $this->listBlcks($itemsArray, $itemOffset, $itemSet['ilevl']);
				$lastEnt = empty($entrLst) ? Shape::makeBlock('entry') : array_pop($entrLst);
				$entrLst[] = Shape::makeBlock('entry', $lastEnt['spans'], array_merge($lastEnt['items'], $deepLst), $lastEnt['attrs']);
				continue;
			}
			if( !empty($entrLst) && $itemSet['numid']!==$nowNumb ){
				$listLst[] = Shape::makeBlock('lists', Array(), $entrLst, Array('order'=>$nowOrdr));
				$entrLst = Array();
			}
			if( empty($entrLst) ){
				$nowNumb = $itemSet['numid'];
				$nowOrdr = $itemSet['order'];
			}
			$entrLst[] = Shape::makeBlock('entry', $itemSet['spans'], $itemSet['holds'], $itemSet['attrs']);
			$itemOffset++;
		}
		if( !empty($entrLst) ){
			$listLst[] = Shape::makeBlock('lists', Array(), $entrLst, Array('order'=>$nowOrdr));
		}
		return $listLst;
	}

	/**
	 * Reads a table. A cell merged with the ones under it covers their
	 * rows; a row repeated at the top of each page is a header row.
	 *
	 * @param Array $nodeValues The element of the table.
	 * @return Array The table, as a block.
	 */
	protected function tableBlck(Array $nodeValues): Array {
		$rowsDat = Array();
		// The cells still running down their column: column => the row and the place of the cell
		$openSpn = Array();
		foreach($this->kidsNamed($nodeValues, 'w:tr') as $rowArr){
			$cellDat = Array();
			$colIndx = 0;
			foreach($this->kidsNamed($rowArr, 'w:tc') as $celArr){
				$propArr = $this->findChild($celArr, Array('w:tcPr')) ?? Array('nodes'=>Array());
				$colSpan = max(1, intval($this->findChild($propArr, Array('w:gridSpan'))['attrs']['w:val'] ?? 1));
				$mrgdArr = $this->findChild($propArr, Array('w:vMerge'));
				$mrgdVal = ($mrgdArr===null) ? '' : strtolower(strval($mrgdArr['attrs']['w:val'] ?? 'continue'));
				if( $mrgdVal=='continue' && isset($openSpn[$colIndx]) ){
					// Covered by the cell above: that cell grows by one row, and this one is not a cell of its own
					$rowsDat[$openSpn[$colIndx][0]]['cells'][$openSpn[$colIndx][1]]['attrs']['rowsp']++;
					$colIndx += $colSpan;
					continue;
				}
				unset($openSpn[$colIndx]);
				if( $mrgdVal=='restart' ){
					$openSpn[$colIndx] = Array(count($rowsDat), count($cellDat));
				}
				$cellBlk = $this->bodyBlcks($celArr);
				// Word ends every cell with a paragraph: an empty one after something else is only that
				$lastBlk = end($cellBlk);
				if( count($cellBlk)>1 && $lastBlk['block']=='parag' && empty($lastBlk['spans']) ){
					array_pop($cellBlk);
				}
				$cellDat[] = Array('blcks'=>$cellBlk, 'attrs'=>Array('colsp'=>$colSpan, 'rowsp'=>1));
				$colIndx += $colSpan;
			}
			$rowsDat[] = Array('thead'=>$this->toggledOn($this->findChild($rowArr, Array('w:trPr', 'w:tblHeader'))), 'cells'=>$cellDat);
		}
		$rowsLst = Array();
		foreach($rowsDat as $rowSet){
			$cellLst = Array();
			foreach($rowSet['cells'] as $celSet){
				// Only what a cell covers beyond itself is worth telling; the paragraph that is its text tells its style
				$spanAtr = array_filter($celSet['attrs'], function($spanNumber){ return $spanNumber>1; });
				$frstBlk = $celSet['blcks'][0] ?? Array('block'=>'', 'attrs'=>Array());
				$cellLst[] = $this->holdBlock('tcell', $celSet['blcks'], $spanAtr + (($frstBlk['block']=='parag') ? $frstBlk['attrs'] : Array()));
			}
			if( !empty($cellLst) ){
				$rowsLst[] = Shape::makeBlock($rowSet['thead'] ? 'thead' : 'tbrow', Array(), $cellLst);
			}
		}
		return Shape::makeBlock('table', Array(), $rowsLst);
	}

}
