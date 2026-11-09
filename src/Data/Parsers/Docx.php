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
	const PROP_LIST = Array('title'=>'Title', 'creator'=>'Author', 'subject'=>'Subject', 'keywords'=>'Keywords', 'description'=>'Comments', 'category'=>'Category', 'lastModifiedBy'=>'LastSavedBy', 'Company'=>'Company', 'Manager'=>'Manager');
	// Elements that only group pieces of a paragraph
	const WRAP_LIST = Array('w:ins', 'w:moveTo', 'w:smartTag', 'w:customXml', 'w:dir', 'w:bdo');
	// The alignments, as Word writes them
	const ALGN_LIST = Array('left'=>'alignLft', 'start'=>'alignLft', 'right'=>'alignRgt', 'end'=>'alignRgt', 'center'=>'alignCtr', 'both'=>'alignJst', 'distribute'=>'alignJst');
	// The styles of Word that make another kind of block than a paragraph, by their name in lower case
	const KIND_LIST = Array('quote'=>'citation', 'intense quote'=>'citation', 'html preformatted'=>'codeBlck', 'plain text'=>'codeBlck');
	// The fields that show a property Word defines, and the fields that show a date
	const FLDS_PROP = Array('TITLE'=>'Title', 'AUTHOR'=>'Author', 'SUBJECT'=>'Subject', 'KEYWORDS'=>'Keywords', 'COMMENTS'=>'Comments', 'LASTSAVEDBY'=>'LastSavedBy');
	const FLDS_DATE = Array('DATE', 'TIME', 'CREATEDATE', 'SAVEDATE', 'PRINTDATE');

	// The relations of the part being read, the styles of the document, the style of the paragraphs that name none,
	// the styles met, and whether each level of its lists is numbered
	protected $docRelsMap = Array();
	protected $styleTable = Array();
	protected $defltStyle = '';
	protected $stylesUsed = Array();
	protected $numberList = Array();
	// The fonts of the theme of the document: the one of its text under 'minor', the one of its headings under 'major'
	protected $themeFonts = Array();
	// The field being read: 0 outside one, 1 in what it asks for, 2 in what it shows;
	// then what it asks for, the address it gives, and what it stands for
	protected $fieldState = 0;
	protected $fieldInstr = '';
	protected $fieldLinks = '';
	protected $fieldCodes = '';
	// The notes met, by kind: their id in the document => their number
	protected $noteNumbrs = Array('fnote'=>Array(), 'enote'=>Array());
	// Whether empty paragraphs are kept, whether comments are read, the passages being commented and those that were
	protected $keepsEmpty = true;
	protected $redComment = false;
	protected $commntOpen = Array();
	protected $commentTxt = Array();

	/**
	 * Reads the document of an opened archive.
	 *
	 * Options:
	 *   'empty' => keeps the paragraphs holding nothing, as blocks
	 *              without text; on unless told otherwise;
	 *   'cmnts' => reads the comments of the reviewers; off unless told
	 *              otherwise.
	 *
	 * @param Array $parseOptions Options: 'empty', 'cmnts'.
	 * @return Array The envelope, of shape 'block'; 'infos' holds the title of the document under 'title', its author under 'authr', its properties under 'props', what each style used gives under 'frmat', the blocks of the headers and of the footers of its pages under 'phead' and 'pfoot' and, with the 'cmnts' option, its comments under 'cmnts'.
	 *
	 * @throws ParseError When the archive is not a valid text document.
	 */
	protected function readArchvArray(Array $parseOptions): Array {
		// The archive says where its document is; it is in word/document.xml unless told otherwise
		$docPath = 'word/document.xml';
		foreach($this->relsOfFragment('') as $relSet){
			if( substr($relSet['types'], -15)=='/officeDocument' ){
				$docPath = $relSet['paths'];
			}
		}
		$bodyArr = $this->findChildArray($this->partNodesArray($docPath) ?? Array('nodes'=>Array()), Array('w:body'));
		if( $bodyArr===null ){
			throw new ParseError('Invalid archive: the document is not a text document');
		}
		$docRels = $this->relsOfFragment($docPath);
		$this->docRelsMap = $docRels;
		$this->styleTable = Array();
		$this->defltStyle = '';
		$this->stylesUsed = Array();
		$this->numberList = Array();
		$this->fieldState = 0;
		$this->fieldLinks = '';
		$this->fieldCodes = '';
		$this->noteNumbrs = Array('fnote'=>Array(), 'enote'=>Array());
		$this->keepsEmpty = !isset($parseOptions['empty']) || !empty($parseOptions['empty']);
		$this->redComment = !empty($parseOptions['cmnts']);
		$this->commntOpen = Array();
		$this->commentTxt = Array();
		// A style may name its font through the theme: the theme is read before the styles
		$this->themeFonts = Array();
		foreach($docRels as $relSet){
			if( substr($relSet['types'], -6)=='/theme' ){
				$fontArr = $this->deepNamedArray($this->partNodesArray($relSet['paths'], false) ?? Array('nodes'=>Array()), Array('a:fontScheme')) ?? Array('nodes'=>Array());
				foreach(Array('minor'=>'a:minorFont', 'major'=>'a:majorFont') as $fntKey=>$fntTag){
					$this->themeFonts[$fntKey] = strval($this->findChildArray($fontArr, Array($fntTag, 'a:latin'))['attrs']['typeface'] ?? '');
				}
			}
		}
		foreach($docRels as $relSet){
			if( substr($relSet['types'], -7)=='/styles' ){
				$this->readDocxStyles($relSet['paths']);
			}elseif( substr($relSet['types'], -10)=='/numbering' ){
				$this->readListLevels($relSet['paths']);
			}
		}
		$blckLst = $this->bodyBlocksList($bodyArr);
		// What stands outside the text is read without its empty paragraphs
		$this->keepsEmpty = false;
		$blckLst = array_merge($blckLst, $this->notesReadArray($docRels));
		$propLst = $this->propsReadArray();
		$infoArr = Array('title'=>strval($propLst['Title'] ?? ''), 'authr'=>strval($propLst['Author'] ?? ''), 'props'=>$propLst, 'frmat'=>Array());
		$infoArr['phead'] = $this->pagesReadArray($docRels, '/header');
		$infoArr['pfoot'] = $this->pagesReadArray($docRels, '/footer');
		if( $this->redComment ){
			$infoArr['cmnts'] = $this->gatherComments($docRels);
		}
		foreach(array_keys($this->stylesUsed) as $stlKey){
			$infoArr['frmat'][$this->styleTable[$stlKey]['names']] = $this->frmatOfItArray($this->styleTable[$stlKey]);
		}
		$this->docRelsMap = Array();
		$this->styleTable = Array();
		$this->commentTxt = Array();
		return $this->makeShapeArray('block', $blckLst, $infoArr);
	}

	/**
	 * Tells whether a property written as an element is set: it is when
	 * the element is there, unless it says otherwise.
	 *
	 * @param ?Array $elementArray The element, null when it is not there.
	 * @return Bool True when the property is set.
	 */
	protected function isElementSetOn(?Array $elementArray): Bool {
		return ($elementArray!==null && !in_array(strtolower(strval($elementArray['attrs']['w:val'] ?? '1')), Array('0', 'false', 'off', 'none')));
	}

	/**
	 * Gives the formatting the properties of a run, or of a style, ask for.
	 *
	 * @param Array $elementArray The element holding those properties.
	 * @return Array The formatting: boldFace, emphasis, undrLine, crossOut.
	 */
	protected function marksOfRnArray(Array $elementArray): Array {
		$propArr = $this->findChildArray($elementArray, Array('w:rPr'));
		$markLst = Array();
		if( $propArr===null ){
			return $markLst;
		}
		foreach(Array('w:b'=>'boldFace', 'w:i'=>'emphasis', 'w:u'=>'undrLine', 'w:strike'=>'crossOut', 'w:dstrike'=>'crossOut') as $prpNme=>$mrkKey){
			if( $this->isElementSetOn($this->findChildArray($propArr, Array($prpNme))) && !in_array($mrkKey, $markLst) ){
				$markLst[] = $mrkKey;
			}
		}
		return $markLst;
	}

	/**
	 * Gives the font, the size and the colour the properties of a style
	 * ask for.
	 *
	 * @param Array $elementArray The element holding those properties.
	 * @return Array The font under 'fonts', the size in points under 'sizes' and the colour under 'color'; '' or 0 for what is not told.
	 */
	protected function fontOfStyleArr(Array $elementArray): Array {
		$propArr = $this->findChildArray($elementArray, Array('w:rPr')) ?? Array('nodes'=>Array());
		$fontArr = $this->findChildArray($propArr, Array('w:rFonts'))['attrs'] ?? Array();
		$sizeVal = strval($this->findChildArray($propArr, Array('w:sz'))['attrs']['w:val'] ?? '');
		$colrVal = strval($this->findChildArray($propArr, Array('w:color'))['attrs']['w:val'] ?? '');
		return Array(
			'fonts'=>strval($fontArr['w:ascii'] ?? $fontArr['w:hAnsi'] ?? (isset($fontArr['w:asciiTheme']) ? ($this->themeFonts[substr(strtolower($fontArr['w:asciiTheme']), 0, 5)] ?? '') : ($fontArr['w:cs'] ?? ''))),
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
	 * @param String $fullPathName Name of the part holding the styles.
	 */
	protected function readDocxStyles(String $fullPathName): Void {
		$stlsArr = $this->partNodesArray($fullPathName, false) ?? Array('nodes'=>Array());
		foreach($this->kidsNamedArray($stlsArr, 'w:style') as $stlArr){
			$stylNme = strval($this->findChildArray($stlArr, Array('w:name'))['attrs']['w:val'] ?? '');
			$stylKey = strval($stlArr['attrs']['w:styleId'] ?? '');
			$lvlPart = Array();
			$headLvl = 0;
			// Word names its heading styles the same in every language
			if( preg_match('/^heading ([1-9])$/Di', $stylNme, $lvlPart) ){
				$headLvl = min(6, intval($lvlPart[1]));
			}elseif( strtolower($stylNme)=='title' ){
				$headLvl = 1;
			}
			$otlnLvl = strval($this->findChildArray($stlArr, Array('w:pPr', 'w:outlineLvl'))['attrs']['w:val'] ?? '');
			if( $headLvl==0 && preg_match('/^[0-8]$/D', $otlnLvl) ){
				$headLvl = min(6, intval($otlnLvl)+1);
			}
			$numbArr = $this->findChildArray($stlArr, Array('w:pPr', 'w:numPr')) ?? Array('nodes'=>Array());
			$this->styleTable[$stylKey] = Array(
				'names'=>$stylNme, 'level'=>$headLvl, 'marks'=>$this->marksOfRnArray($stlArr),
				'numid'=>strval($this->findChildArray($numbArr, Array('w:numId'))['attrs']['w:val'] ?? ''), 'ilevl'=>intval($this->findChildArray($numbArr, Array('w:ilvl'))['attrs']['w:val'] ?? 0),
				'align'=>strval(self::ALGN_LIST[strtolower(strval($this->findChildArray($stlArr, Array('w:pPr', 'w:jc'))['attrs']['w:val'] ?? ''))] ?? ''),
				'based'=>strval($this->findChildArray($stlArr, Array('w:basedOn'))['attrs']['w:val'] ?? '')
			) + $this->fontOfStyleArr($stlArr);
			if( strtolower(strval($stlArr['attrs']['w:type'] ?? ''))=='paragraph' && in_array(strtolower(strval($stlArr['attrs']['w:default'] ?? '')), Array('1', 'true')) ){
				$this->defltStyle = $stylKey;
			}
		}
		$baseSet = $this->fontOfStyleArr($this->findChildArray($stlsArr, Array('w:docDefaults', 'w:rPrDefault')) ?? Array('nodes'=>Array()));
		foreach(array_keys($this->styleTable) as $stlKey){
			$prntKey = $this->styleTable[$stlKey]['based'];
			// Twenty styles based one on the other is more than any document has, and stops a circle
			for($hopIdx=0; $hopIdx<21; $hopIdx++){
				$prntSet = $this->styleTable[$prntKey] ?? null;
				if( $prntSet===null || $hopIdx==20 ){
					$prntSet = $baseSet + Array('level'=>0, 'numid'=>'', 'ilevl'=>0, 'align'=>'', 'marks'=>Array(), 'based'=>'');
					$hopIdx = 20;
				}
				if( $this->styleTable[$stlKey]['level']==0 ){
					$this->styleTable[$stlKey]['level'] = $prntSet['level'];
				}
				if( $this->styleTable[$stlKey]['numid']==='' ){
					$this->styleTable[$stlKey]['numid'] = $prntSet['numid'];
					$this->styleTable[$stlKey]['ilevl'] = $prntSet['ilevl'];
				}
				foreach(Array('align', 'fonts', 'sizes', 'color') as $prpKey){
					if( empty($this->styleTable[$stlKey][$prpKey]) ){
						$this->styleTable[$stlKey][$prpKey] = $prntSet[$prpKey];
					}
				}
				$this->styleTable[$stlKey]['marks'] = array_values(array_intersect(Shape::MARK_LIST, array_merge($this->styleTable[$stlKey]['marks'], $prntSet['marks'])));
				$prntKey = $prntSet['based'];
			}
		}
	}

	/**
	 * Reads the lists the document defines: for each of them, and each of
	 * its levels, whether it is numbered or only marked.
	 *
	 * @param String $fullPathName Name of the part holding them.
	 */
	protected function readListLevels(String $fullPathName): Void {
		$numbArr = $this->partNodesArray($fullPathName, false) ?? Array('nodes'=>Array());
		$abstLst = Array();
		foreach($this->kidsNamedArray($numbArr, 'w:abstractNum') as $absArr){
			foreach($this->kidsNamedArray($absArr, 'w:lvl') as $lvlArr){
				$numFrmt = strtolower(strval($this->findChildArray($lvlArr, Array('w:numFmt'))['attrs']['w:val'] ?? 'decimal'));
				$abstLst[strval($absArr['attrs']['w:abstractNumId'] ?? '')][intval($lvlArr['attrs']['w:ilvl'] ?? 0)] = ($numFrmt!='bullet' && $numFrmt!='none');
			}
		}
		foreach($this->kidsNamedArray($numbArr, 'w:num') as $numArr){
			$this->numberList[strval($numArr['attrs']['w:numId'] ?? '')] = $abstLst[strval($this->findChildArray($numArr, Array('w:abstractNumId'))['attrs']['w:val'] ?? '')] ?? Array();
		}
	}

	/**
	 * Reads the properties of the document: the ones Word defines, then
	 * the ones added to it.
	 *
	 * @return Array The properties, by the name Word gives them in its fields; those that are empty are left out.
	 */
	protected function propsReadArray(): Array {
		$propLst = Array();
		foreach(Array('docProps/core.xml', 'docProps/app.xml') as $prtNme){
			foreach(($this->partNodesArray($prtNme, false) ?? Array('nodes'=>Array()))['nodes'] as $prpArr){
				$bareNme = substr($prpArr['tagnm'], intval(strrpos(':'.$prpArr['tagnm'], ':')));
				if( isset(self::PROP_LIST[$bareNme]) && trim($prpArr['value'])!=='' ){
					$propLst[self::PROP_LIST[$bareNme]] = trim($prpArr['value']);
				}
			}
		}
		foreach($this->kidsNamedArray($this->partNodesArray('docProps/custom.xml', false) ?? Array('nodes'=>Array()), 'property') as $prpArr){
			$valArry = $prpArr['nodes'][0] ?? null;
			$propNme = strval($prpArr['attrs']['name'] ?? '');
			if( $valArry===null || $propNme==='' ){
				continue;
			}
			// A property tells its type by the name of the element holding its value
			$typeNme = substr($valArry['tagnm'], intval(strrpos(':'.$valArry['tagnm'], ':')));
			$rawValu = $this->collectAllText($valArry);
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
	 * @param String $fullPathName Name of the part.
	 * @param ?Array $elementArray The element of that part holding the blocks, null for its root.
	 * @return Array The blocks.
	 */
	protected function fragmentBlocks(String $fullPathName, ?Array $elementArray=null): Array {
		$keptRel = $this->docRelsMap;
		$this->docRelsMap = $this->relsOfFragment($fullPathName);
		$blckLst = $this->bodyBlocksList($elementArray ?? $this->partNodesArray($fullPathName, false) ?? Array('nodes'=>Array()));
		$this->docRelsMap = $keptRel;
		return $blckLst;
	}

	/**
	 * Reads the notes the text refers to, in the order it refers to
	 * them: the notes of the pages first, then those of the end.
	 *
	 * @param Array $documntRltns The relations of the document.
	 * @return Array The notes, as blocks.
	 */
	protected function notesReadArray(Array $documntRltns): Array {
		$blckLst = Array();
		foreach(Array('fnote'=>Array('/footnotes', 'w:footnote'), 'enote'=>Array('/endnotes', 'w:endnote')) as $nteKnd=>$nteSet){
			$numList = $this->noteNumbrs[$nteKnd];
			foreach($documntRltns as $relSet){
				if( substr($relSet['types'], -strlen($nteSet[0]))!==$nteSet[0] || empty($numList) ){
					continue;
				}
				$noteLst = Array();
				foreach($this->kidsNamedArray($this->partNodesArray($relSet['paths'], false) ?? Array('nodes'=>Array()), $nteSet[1]) as $nteArr){
					$noteLst[strval($nteArr['attrs']['w:id'] ?? '')] = $nteArr;
				}
				foreach($numList as $noteId=>$noteNb){
					if( isset($noteLst[$noteId]) ){
						$blckLst[] = $this->holdBlockArray('footnote', $this->fragmentBlocks($relSet['paths'], $noteLst[$noteId]), Array('tagid'=>$nteKnd.$noteNb, 'numbr'=>$noteNb));
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
	 * @param Array  $documntRltns The relations of the document.
	 * @param String $suffixOfKind What ends the kind of the relations to read: '/header' or '/footer'.
	 * @return Array Their blocks.
	 */
	protected function pagesReadArray(Array $documntRltns, String $suffixOfKind): Array {
		$blckLst = Array();
		$seenLst = Array();
		foreach($documntRltns as $relSet){
			if( substr($relSet['types'], -strlen($suffixOfKind))!==$suffixOfKind ){
				continue;
			}
			$partBlk = $this->fragmentBlocks($relSet['paths']);
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
	 * @param Array $documntRltns The relations of the document.
	 * @return Array The comments, each with its author under 'authr', its date under 'dated', its text under 'plain' and the passage it is about under 'about'.
	 */
	protected function gatherComments(Array $documntRltns): Array {
		$noteLst = Array();
		foreach($documntRltns as $relSet){
			if( substr($relSet['types'], -9)!=='/comments' ){
				continue;
			}
			foreach($this->kidsNamedArray($this->partNodesArray($relSet['paths'], false) ?? Array('nodes'=>Array()), 'w:comment') as $remark){
				$noteLst[] = Array(
					'authr'=>strval($remark['attrs']['w:author'] ?? ''), 'dated'=>strval($remark['attrs']['w:date'] ?? ''),
					'plain'=>Shape::documentAsText($this->fragmentBlocks($relSet['paths'], $remark)),
					'about'=>trim(strval($this->commentTxt[strval($remark['attrs']['w:id'] ?? '')] ?? ''))
				);
			}
		}
		return $noteLst;
	}

	/**
	 * Follows the marks that open and close the passage a comment is
	 * about.
	 *
	 * @param Array $elementArray An element met in the text.
	 * @return Bool True when the element was such a mark.
	 */
	protected function remarkMarksFlg(Array $elementArray): Bool {
		if( $elementArray['tagnm']!='w:commentRangeStart' && $elementArray['tagnm']!='w:commentRangeEnd' ){
			return false;
		}
		$cmntIdn = strval($elementArray['attrs']['w:id'] ?? '');
		if( $this->redComment && $elementArray['tagnm']=='w:commentRangeStart' ){
			$this->commntOpen[$cmntIdn] = '';
		}elseif( isset($this->commntOpen[$cmntIdn]) ){
			$this->commentTxt[$cmntIdn] = $this->commntOpen[$cmntIdn];
			unset($this->commntOpen[$cmntIdn]);
		}
		return true;
	}

	/**
	 * Reads what holds paragraphs and tables: the body of the document,
	 * a cell of a table, a note, a header. The paragraphs of a list that
	 * follow one another make that list, and so do the lines of code.
	 *
	 * @param Array $elementArray The element.
	 * @return Array The blocks.
	 */
	protected function bodyBlocksList(Array $elementArray): Array {
		$blckLst = Array();
		$lstBffr = Array();
		$codBffr = Array();
		$bodyNds = $elementArray['nodes'];
		// An end is added to the nodes, so that a list ending them is closed like any other
		$bodyNds[] = Array('tagnm'=>'#end', 'attrs'=>Array(), 'nodes'=>Array());
		foreach($bodyNds as $chlArr){
			$paraLst = ($chlArr['tagnm']=='w:p') ? $this->paragraphParts($chlArr) : null;
			$paraKnd = strval($paraLst['kinds'] ?? '');
			if( $paraKnd!='listItem' && !empty($lstBffr) ){
				$itmPstn = 0;
				$blckLst = array_merge($blckLst, $this->buildListBlock($lstBffr, $itmPstn, 0));
				$lstBffr = Array();
			}
			if( $paraKnd!='codeBlck' && !empty($codBffr) ){
				$blckLst = array_merge($blckLst, $this->codeBlockArray($codBffr));
				$codBffr = Array();
			}
			if( $paraKnd=='listItem' ){
				if( $this->keepsEmpty || !empty($paraLst['spans']) || !empty($paraLst['holds']) ){
					$lstBffr[] = $paraLst;
				}
			}elseif( $paraKnd=='codeBlck' ){
				$codBffr[] = $paraLst;
			}elseif( $paraLst!==null ){
				$blckLst = array_merge($blckLst, $this->paragrphBlocks($paraLst));
			}elseif( $chlArr['tagnm']=='w:tbl' ){
				$tblBlck = $this->tableBlockList($chlArr);
				if( !empty($tblBlck['items']) ){
					$blckLst[] = $tblBlck;
				}
			}elseif( $chlArr['tagnm']=='w:sdt' ){
				$blckLst = array_merge($blckLst, $this->bodyBlocksList($this->findChildArray($chlArr, Array('w:sdtContent')) ?? Array('nodes'=>Array())));
			}else{
				$this->remarkMarksFlg($chlArr);
			}
		}
		return $blckLst;
	}

	/**
	 * Reads a paragraph: what it is, and what it holds.
	 *
	 * @param Array $elementArray The element of the paragraph.
	 * @return Array What it is under 'kinds' (headline, listItem, citation, codeBlck or paragrph), its level as a heading under 'level', the list it belongs to under 'numid', its level in it under 'ilevl' and whether that level is numbered under 'order', its style, its alignment and its id under 'attrs', its text under 'spans' and as written under 'plain', the images and the page breaks it holds under 'holds', all of it in order, as blocks, under 'flows', and whether a page break stands before or after it under 'prior' and 'after'.
	 */
	protected function paragraphParts(Array $elementArray): Array {
		$propArr = $this->findChildArray($elementArray, Array('w:pPr')) ?? Array('nodes'=>Array());
		$stylKey = strval($this->findChildArray($propArr, Array('w:pStyle'))['attrs']['w:val'] ?? '');
		if( !isset($this->styleTable[$stylKey]) ){
			$stylKey = $this->defltStyle;
		}
		$stylSet = $this->styleTable[$stylKey] ?? Array('names'=>'', 'level'=>0, 'numid'=>'', 'ilevl'=>0, 'align'=>'');
		$headLvl = $stylSet['level'];
		$otlnLvl = strval($this->findChildArray($propArr, Array('w:outlineLvl'))['attrs']['w:val'] ?? '');
		if( preg_match('/^[0-8]$/D', $otlnLvl) ){
			$headLvl = min(6, intval($otlnLvl)+1);
		}
		// What the paragraph says of its list comes before what its style says
		$numbArr = $this->findChildArray($propArr, Array('w:numPr'));
		$numIdnt = ($numbArr===null) ? $stylSet['numid'] : strval($this->findChildArray($numbArr, Array('w:numId'))['attrs']['w:val'] ?? '');
		$nbLevel = ($numbArr===null) ? $stylSet['ilevl'] : intval($this->findChildArray($numbArr, Array('w:ilvl'))['attrs']['w:val'] ?? 0);
		// A passage commented over several paragraphs has a space between two of them
		foreach($this->commntOpen as $cmtIdn=>$cmtTxt){
			$this->commntOpen[$cmtIdn] = ($cmtTxt==='') ? '' : $cmtTxt.' ';
		}
		$picList = Array();
		$this->readParaPieces($elementArray, '', $picList);
		$flowSet = $this->flowPartsArray($picList, $this->keepsEmpty);
		$spnsAll = $flowSet['spans'];
		$attrSet = Array();
		if( $stylSet['names']!=='' ){
			$attrSet['style'] = $stylSet['names'];
			$this->stylesUsed[$stylKey] = true;
		}
		// The alignment is told when the paragraph does not follow its style
		$ownAlgn = strval(self::ALGN_LIST[strtolower(strval($this->findChildArray($propArr, Array('w:jc'))['attrs']['w:val'] ?? ''))] ?? '');
		if( $ownAlgn!=='' && $ownAlgn!==$stylSet['align'] && !($ownAlgn=='alignLft' && $stylSet['align']==='') ){
			$attrSet['align'] = $ownAlgn;
		}
		// A bookmark starting the paragraph names it; those Word adds for itself start with an underscore
		foreach($this->kidsNamedArray($elementArray, 'w:bookmarkStart') as $bkmArr){
			$bkmName = strval($bkmArr['attrs']['w:name'] ?? '');
			if( $bkmName!=='' && $bkmName[0]!='_' && !isset($attrSet['tagid']) ){
				$attrSet['tagid'] = $bkmName;
			}
		}
		$paraKnd = 'paragrph';
		if( $headLvl>0 && !empty($spnsAll) ){
			$paraKnd = 'headline';
		}elseif( $headLvl==0 && $numIdnt!=='' && $numIdnt!=='0' ){
			$paraKnd = 'listItem';
		}elseif( $headLvl==0 && isset(self::KIND_LIST[strtolower($stylSet['names'])]) && (!empty($spnsAll) || self::KIND_LIST[strtolower($stylSet['names'])]=='codeBlck') ){
			$paraKnd = self::KIND_LIST[strtolower($stylSet['names'])];
		}
		$sctnArr = $this->findChildArray($propArr, Array('w:sectPr'));
		return Array(
			'kinds'=>$paraKnd, 'level'=>$headLvl,
			'numid'=>$numIdnt, 'ilevl'=>$nbLevel, 'order'=>!empty($this->numberList[$numIdnt][$nbLevel]),
			'attrs'=>$attrSet, 'spans'=>$spnsAll, 'plain'=>$flowSet['plain'], 'holds'=>$flowSet['holds'], 'flows'=>$flowSet['flows'],
			'prior'=>$this->isElementSetOn($this->findChildArray($propArr, Array('w:pageBreakBefore'))),
			// A section that starts on a new page ends this one
			'after'=>($sctnArr!==null && strtolower(strval($this->findChildArray($sctnArr, Array('w:type'))['attrs']['w:val'] ?? 'nextPage'))!='continuous')
		);
	}

	/**
	 * Tells what a field stands for, from what it asks for.
	 *
	 * @param String $fldInstrText What the field asks for, as Word writes it.
	 * @return String 'pageNumb', 'pageTotl', 'dateTime', 'props:' followed by the name of a property, or '' for any other field.
	 */
	protected function parseFieldCode(String $fldInstrText): String {
		$codeLst = Array();
		if( !preg_match('/^\s*([A-Za-z]+)(?:\s+(?:"([^"]*)"|([^\s\\\\]+)))?/', $fldInstrText, $codeLst) ){
			return '';
		}
		$fldName = strtoupper($codeLst[1]);
		if( $fldName=='PAGE' ){
			return 'pageNumb';
		}
		if( $fldName=='NUMPAGES' || $fldName=='SECTIONPAGES' ){
			return 'pageTotl';
		}
		if( in_array($fldName, self::FLDS_DATE) ){
			return 'dateTime';
		}
		if( isset(self::FLDS_PROP[$fldName]) ){
			return 'props:'.self::FLDS_PROP[$fldName];
		}
		$propNme = strval(($codeLst[2] ?? '')!=='' ? $codeLst[2] : ($codeLst[3] ?? ''));
		return ($fldName=='DOCPROPERTY' && $propNme!=='') ? 'props:'.$propNme : '';
	}

	/**
	 * Reads the pieces of a paragraph: its runs of text, with their
	 * formatting and their link, its images and its page breaks.
	 *
	 * @param Array  $elementArray The paragraph, or an element grouping some of its pieces.
	 * @param String $linkGoalText Address given by a link around them, '' when there is none.
	 * @param Array  $outputPieces Receives the pieces, in order: spans, and images and page breaks as blocks.
	 */
	protected function readParaPieces(Array $elementArray, String $linkGoalText, Array &$outputPieces): Void {
		foreach($elementArray['nodes'] as $chlArr){
			$chldNme = $chlArr['tagnm'];
			if( $chldNme=='w:hyperlink' ){
				// A link points to an address kept in the relations of the document, or to a bookmark of the document itself
				$relsMap = $this->docRelsMap[strval($chlArr['attrs']['r:id'] ?? '')] ?? null;
				$anchrTx = strval($chlArr['attrs']['w:anchor'] ?? '');
				$linkAdr = Shape::tidyLinkString((($relsMap!==null) ? $relsMap['links'] : '').(($anchrTx!=='') ? '#'.$anchrTx : ''));
				$this->readParaPieces($chlArr, ($linkAdr!=='') ? $linkAdr : $linkGoalText, $outputPieces);
			}elseif( $chldNme=='w:fldSimple' ){
				// A field written in one piece: what it asks for is told beside what it shows
				$kptCode = $this->fieldCodes;
				$this->fieldCodes = $this->parseFieldCode(strval($chlArr['attrs']['w:instr'] ?? ''));
				$this->readParaPieces($chlArr, $linkGoalText, $outputPieces);
				$this->fieldCodes = $kptCode;
			}elseif( in_array($chldNme, self::WRAP_LIST) ){
				$this->readParaPieces($chlArr, $linkGoalText, $outputPieces);
			}elseif( $chldNme=='w:sdt' ){
				$this->readParaPieces($this->findChildArray($chlArr, Array('w:sdtContent')) ?? Array('nodes'=>Array()), $linkGoalText, $outputPieces);
			}elseif( $chldNme=='w:r' ){
				$markLst = array_values(array_intersect(Shape::MARK_LIST, array_merge($this->styleTable[strval($this->findChildArray($chlArr, Array('w:rPr', 'w:rStyle'))['attrs']['w:val'] ?? '')]['marks'] ?? Array(), $this->marksOfRnArray($chlArr))));
				foreach($chlArr['nodes'] as $oneRun){
					$this->readOneRunPart($oneRun, $markLst, $linkGoalText, $outputPieces);
				}
			}else{
				$this->remarkMarksFlg($chlArr);
			}
		}
	}

	/**
	 * Reads one piece of a run: text, a tabulation, a line break, a page
	 * break, an image, the reference to a note, or a mark of a field. A
	 * field is read as the text Word last showed for it: what it asks
	 * for is left out, apart from what it tells of that text.
	 *
	 * @param Array  $elementArray The element of the piece.
	 * @param Array  $runFormatSet Formatting of the run.
	 * @param String $linkGoalText Address given by a link around the run, '' when there is none.
	 * @param Array  $outputPieces Receives the piece.
	 */
	protected function readOneRunPart(Array $elementArray, Array $runFormatSet, String $linkGoalText, Array &$outputPieces): Void {
		$picName = $elementArray['tagnm'];
		if( $picName=='w:fldChar' ){
			$chrType = strtolower(strval($elementArray['attrs']['w:fldCharType'] ?? ''));
			$codeLst = Array();
			$this->fieldLinks = '';
			$this->fieldCodes = '';
			if( $chrType=='begin' ){
				$this->fieldState = 1;
				$this->fieldInstr = '';
			}elseif( $chrType=='separate' ){
				$this->fieldState = 2;
				$this->fieldCodes = $this->parseFieldCode($this->fieldInstr);
				if( preg_match('/^\s*HYPERLINK\s+(\\\\l\s+)?"([^"]*)"/i', $this->fieldInstr, $codeLst) ){
					$this->fieldLinks = Shape::tidyLinkString((($codeLst[1]!=='') ? '#' : '').$codeLst[2]);
				}
			}else{
				$this->fieldState = 0;
			}
			return;
		}
		if( $this->fieldState==1 ){
			$this->fieldInstr .= ($picName=='w:instrText') ? $this->collectAllText($elementArray) : '';
			return;
		}
		$picText = '';
		$picLink = ($linkGoalText!=='') ? $linkGoalText : $this->fieldLinks;
		if( $picName=='w:t' ){
			$picText = $this->collectAllText($elementArray);
		}elseif( $picName=='w:tab' ){
			$picText = "\t";
		}elseif( $picName=='w:br' && strtolower(strval($elementArray['attrs']['w:type'] ?? ''))=='page' ){
			$outputPieces[] = Shape::makeBlockArray('ruleLine', Array(), Array(), Array('break'=>true));
		}elseif( $picName=='w:cr' || ($picName=='w:br' && strtolower(strval($elementArray['attrs']['w:type'] ?? ''))!='column') ){
			$picText = "\n";
		}elseif( $picName=='w:noBreakHyphen' ){
			$picText = "\u{2011}";
		}elseif( $picName=='w:footnoteReference' || $picName=='w:endnoteReference' ){
			// A note is numbered by the place of its first reference in the text
			$noteKnd = ($picName=='w:footnoteReference') ? 'fnote' : 'enote';
			$noteRef = strval($elementArray['attrs']['w:id'] ?? '');
			if( !isset($this->noteNumbrs[$noteKnd][$noteRef]) ){
				$this->noteNumbrs[$noteKnd][$noteRef] = count($this->noteNumbrs[$noteKnd])+1;
			}
			$outputPieces[] = Shape::makeASpanArray(strval($this->noteNumbrs[$noteKnd][$noteRef]), Array(), '#'.$noteKnd.$this->noteNumbrs[$noteKnd][$noteRef]);
			return;
		}elseif( in_array($picName, Array('w:drawing', 'w:pict', 'w:object')) ){
			$imgBlck = $this->imageBlockList($elementArray);
			if( $imgBlck!==null ){
				$outputPieces[] = $imgBlck;
			}
		}
		if( $picText!=='' ){
			$outputPieces[] = Shape::makeASpanArray($picText, $runFormatSet, $picLink, $this->fieldCodes);
			foreach(array_keys($this->commntOpen) as $cmtIdn){
				$this->commntOpen[$cmtIdn] .= $picText;
			}
		}
	}

	/**
	 * Reads an image placed in a paragraph.
	 *
	 * @param Array $elementArray The element of the drawing.
	 * @return ?Array The image, as a block: its address, in the archive or outside it, and its description as text. Null when the drawing holds no image.
	 */
	protected function imageBlockList(Array $elementArray): ?Array {
		$blipArr = $this->deepNamedArray($elementArray, Array('a:blip', 'v:imagedata'));
		if( $blipArr===null ){
			return null;
		}
		$relsMap = $this->docRelsMap[strval($blipArr['attrs']['r:embed'] ?? $blipArr['attrs']['r:link'] ?? $blipArr['attrs']['r:id'] ?? '')] ?? null;
		if( $relsMap===null ){
			return null;
		}
		$imgLink = Shape::tidyLinkString($relsMap['outer'] ? $relsMap['links'] : $relsMap['paths']);
		if( $imgLink==='' ){
			return null;
		}
		$altText = $this->deepNamedArray($elementArray, Array('wp:docPr'));
		$imgText = trim(strval($altText['attrs']['descr'] ?? $blipArr['attrs']['o:title'] ?? ''));
		return Shape::makeBlockArray('imageBox', ($imgText!=='') ? Array(Shape::makeASpanArray($imgText)) : Array(), Array(), Array('sourc'=>$imgLink));
	}

	/**
	 * Builds the lists of some paragraphs that follow one another, from
	 * the level each has: a paragraph further in belongs to the entry
	 * before it, and a change of list at one level starts a new one.
	 *
	 * @param Array $paragraphSet The paragraphs, as paragraphParts() gives them.
	 * @param Int   $itemOffsetNb The paragraph to start from; receives the one following the lists built.
	 * @param Int   $levelValNumb The level of the lists to build.
	 * @return Array The lists, as blocks.
	 */
	protected function buildListBlock(Array $paragraphSet, Int &$itemOffsetNb, Int $levelValNumb): Array {
		$listLst = Array();
		$itemLst = Array();
		$nowNumb = '';
		$nowOrdr = false;
		while( isset($paragraphSet[$itemOffsetNb]) && $paragraphSet[$itemOffsetNb]['ilevl']>=$levelValNumb ){
			$itemSet = $paragraphSet[$itemOffsetNb];
			if( $itemSet['ilevl']>$levelValNumb ){
				// Further in: the lists of that level go into the entry before them, an empty one when there is none
				$deepLst = $this->buildListBlock($paragraphSet, $itemOffsetNb, $itemSet['ilevl']);
				$lastEnt = empty($itemLst) ? Shape::makeBlockArray('listItem') : array_pop($itemLst);
				$itemLst[] = Shape::makeBlockArray('listItem', $lastEnt['spans'], array_merge($lastEnt['items'], $deepLst), $lastEnt['attrs']);
				continue;
			}
			if( !empty($itemLst) && $itemSet['numid']!==$nowNumb ){
				$listLst[] = Shape::makeBlockArray('itemList', Array(), $itemLst, Array('order'=>$nowOrdr));
				$itemLst = Array();
			}
			if( empty($itemLst) ){
				$nowNumb = $itemSet['numid'];
				$nowOrdr = $itemSet['order'];
			}
			$itemLst[] = Shape::makeBlockArray('listItem', $itemSet['spans'], $itemSet['holds'], $itemSet['attrs']);
			$itemOffsetNb++;
		}
		if( !empty($itemLst) ){
			$listLst[] = Shape::makeBlockArray('itemList', Array(), $itemLst, Array('order'=>$nowOrdr));
		}
		return $listLst;
	}

	/**
	 * Reads a table. A cell merged with the ones under it covers their
	 * rows; a row repeated at the top of each page is a header row.
	 *
	 * @param Array $elementArray The element of the table.
	 * @return Array The table, as a block.
	 */
	protected function tableBlockList(Array $elementArray): Array {
		$rwsData = Array();
		// The cells still running down their column: column => the row and the place of the cell
		$openSpn = Array();
		foreach($this->kidsNamedArray($elementArray, 'w:tr') as $rowArr){
			$cellArr = Array();
			$clmnIdx = 0;
			foreach($this->kidsNamedArray($rowArr, 'w:tc') as $celArr){
				$propArr = $this->findChildArray($celArr, Array('w:tcPr')) ?? Array('nodes'=>Array());
				$clmnSpn = max(1, intval($this->findChildArray($propArr, Array('w:gridSpan'))['attrs']['w:val'] ?? 1));
				$mrgdArr = $this->findChildArray($propArr, Array('w:vMerge'));
				$mrgdVal = ($mrgdArr===null) ? '' : strtolower(strval($mrgdArr['attrs']['w:val'] ?? 'continue'));
				if( $mrgdVal=='continue' && isset($openSpn[$clmnIdx]) ){
					// Covered by the cell above: that cell grows by one row, and this one is not a cell of its own
					$rwsData[$openSpn[$clmnIdx][0]]['cells'][$openSpn[$clmnIdx][1]]['attrs']['rowsp']++;
					$clmnIdx += $clmnSpn;
					continue;
				}
				unset($openSpn[$clmnIdx]);
				if( $mrgdVal=='restart' ){
					$openSpn[$clmnIdx] = Array(count($rwsData), count($cellArr));
				}
				$cellBlk = $this->bodyBlocksList($celArr);
				// Word ends every cell with a paragraph: an empty one after something else is only that
				$lastBlk = end($cellBlk);
				if( count($cellBlk)>1 && $lastBlk['block']=='paragrph' && empty($lastBlk['spans']) ){
					array_pop($cellBlk);
				}
				$cellArr[] = Array('blcks'=>$cellBlk, 'attrs'=>Array('colsp'=>$clmnSpn, 'rowsp'=>1));
				$clmnIdx += $clmnSpn;
			}
			$rwsData[] = Array('thead'=>$this->isElementSetOn($this->findChildArray($rowArr, Array('w:trPr', 'w:tblHeader'))), 'cells'=>$cellArr);
		}
		$rowsLst = Array();
		foreach($rwsData as $rowSet){
			$cellLst = Array();
			foreach($rowSet['cells'] as $celSet){
				// Only what a cell covers beyond itself is worth telling; the paragraph that is its text tells its style
				$colSpan = array_filter($celSet['attrs'], function($colSpanCount){ return $colSpanCount>1; });
				$frstBlk = $celSet['blcks'][0] ?? Array('block'=>'', 'attrs'=>Array());
				$cellLst[] = $this->holdBlockArray('cellText', $celSet['blcks'], $colSpan + (($frstBlk['block']=='paragrph') ? $frstBlk['attrs'] : Array()));
			}
			if( !empty($cellLst) ){
				$rowsLst[] = Shape::makeBlockArray($rowSet['thead'] ? 'tableTop' : 'tableRow', Array(), $cellLst);
			}
		}
		return Shape::makeBlockArray('tableSet', Array(), $rowsLst);
	}

}
