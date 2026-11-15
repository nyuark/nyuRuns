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
	protected $defaultKey = '';
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
	protected $noteNumArr = Array('fnote'=>Array(), 'enote'=>Array());
	// Whether empty paragraphs are kept, whether comments are read, the passages being commented and those that were
	protected $keepsEmpty = true;
	protected $redComment = false;
	protected $openedList = Array();
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
		$docuPath = 'word/document.xml';
		foreach($this->relsOfFragment('') as $relSet){
			if( substr($relSet['types'], -15)=='/officeDocument' ){
				$docuPath = $relSet['paths'];
			}
		}
		$bodyList = $this->findChildArray($this->partNodesArray($docuPath) ?? Array('nodes'=>Array()), Array('w:body'));
		if( $bodyList===null ){
			throw new ParseError('Invalid archive: the document is not a text document');
		}
		$docuRltn = $this->relsOfFragment($docuPath);
		$this->docRelsMap = $docuRltn;
		$this->styleTable = Array();
		$this->defaultKey = '';
		$this->stylesUsed = Array();
		$this->numberList = Array();
		$this->fieldState = 0;
		$this->fieldLinks = '';
		$this->fieldCodes = '';
		$this->noteNumArr = Array('fnote'=>Array(), 'enote'=>Array());
		$this->keepsEmpty = !isset($parseOptions['empty']) || !empty($parseOptions['empty']);
		$this->redComment = !empty($parseOptions['cmnts']);
		$this->openedList = Array();
		$this->commentTxt = Array();
		// A style may name its font through the theme: the theme is read before the styles
		$this->themeFonts = Array();
		foreach($docuRltn as $relSet){
			if( substr($relSet['types'], -6)=='/theme' ){
				$fontList = $this->deepNamedArray($this->partNodesArray($relSet['paths'], false) ?? Array('nodes'=>Array()), Array('a:fontScheme')) ?? Array('nodes'=>Array());
				foreach(Array('minor'=>'a:minorFont', 'major'=>'a:majorFont') as $fntKey=>$fntTag){
					$this->themeFonts[$fntKey] = strval($this->findChildArray($fontList, Array($fntTag, 'a:latin'))['attrs']['typeface'] ?? '');
				}
			}
		}
		foreach($docuRltn as $relSet){
			if( substr($relSet['types'], -7)=='/styles' ){
				$this->readDocxStyles($relSet['paths']);
			}elseif( substr($relSet['types'], -10)=='/numbering' ){
				$this->readListLevels($relSet['paths']);
			}
		}
		$blockLst = $this->bodyBlocksList($bodyList);
		// What stands outside the text is read without its empty paragraphs
		$this->keepsEmpty = false;
		$blockLst = array_merge($blockLst, $this->notesReadArray($docuRltn));
		$propList = $this->propsReadArray();
		$infoList = Array('title'=>strval($propList['Title'] ?? ''), 'authr'=>strval($propList['Author'] ?? ''), 'props'=>$propList, 'frmat'=>Array());
		$infoList['phead'] = $this->pagesReadArray($docuRltn, '/header');
		$infoList['pfoot'] = $this->pagesReadArray($docuRltn, '/footer');
		if( $this->redComment ){
			$infoList['cmnts'] = $this->gatherComments($docuRltn);
		}
		foreach(array_keys($this->stylesUsed) as $stlKey){
			$infoList['frmat'][$this->styleTable[$stlKey]['names']] = $this->styleFormatArr($this->styleTable[$stlKey]);
		}
		$this->docRelsMap = Array();
		$this->styleTable = Array();
		$this->commentTxt = Array();
		return $this->makeShapeArray('block', $blockLst, $infoList);
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
	protected function marksOfRunList(Array $elementArray): Array {
		$propList = $this->findChildArray($elementArray, Array('w:rPr'));
		$markList = Array();
		if( $propList===null ){
			return $markList;
		}
		foreach(Array('w:b'=>'boldFace', 'w:i'=>'emphasis', 'w:u'=>'undrLine', 'w:strike'=>'crossOut', 'w:dstrike'=>'crossOut') as $prpNme=>$mrkKey){
			if( $this->isElementSetOn($this->findChildArray($propList, Array($prpNme))) && !in_array($mrkKey, $markList) ){
				$markList[] = $mrkKey;
			}
		}
		return $markList;
	}

	/**
	 * Gives the font, the size and the colour the properties of a style
	 * ask for.
	 *
	 * @param Array $elementArray The element holding those properties.
	 * @return Array The font under 'fonts', the size in points under 'sizes' and the colour under 'color'; '' or 0 for what is not told.
	 */
	protected function fontOfStyleArr(Array $elementArray): Array {
		$propList = $this->findChildArray($elementArray, Array('w:rPr')) ?? Array('nodes'=>Array());
		$fontList = $this->findChildArray($propList, Array('w:rFonts'))['attrs'] ?? Array();
		$sizeVals = strval($this->findChildArray($propList, Array('w:sz'))['attrs']['w:val'] ?? '');
		$colorVal = strval($this->findChildArray($propList, Array('w:color'))['attrs']['w:val'] ?? '');
		return Array(
			'fonts'=>strval($fontList['w:ascii'] ?? $fontList['w:hAnsi'] ?? (isset($fontList['w:asciiTheme']) ? ($this->themeFonts[substr(strtolower($fontList['w:asciiTheme']), 0, 5)] ?? '') : ($fontList['w:cs'] ?? ''))),
			// Word counts the sizes in half points
			'sizes'=>is_numeric($sizeVals) ? floatval($sizeVals)/2 : 0,
			'color'=>preg_match('/^[0-9A-Fa-f]{6}$/D', $colorVal) ? '#'.strtoupper($colorVal) : ''
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
		$stylList = $this->partNodesArray($fullPathName, false) ?? Array('nodes'=>Array());
		foreach($this->kidsNamedArray($stylList, 'w:style') as $stlArr){
			$styleNme = strval($this->findChildArray($stlArr, Array('w:name'))['attrs']['w:val'] ?? '');
			$styleKey = strval($stlArr['attrs']['w:styleId'] ?? '');
			$levelPar = Array();
			$headLevl = 0;
			// Word names its heading styles the same in every language
			if( preg_match('/^heading ([1-9])$/Di', $styleNme, $levelPar) ){
				$headLevl = min(6, intval($levelPar[1]));
			}elseif( strtolower($styleNme)=='title' ){
				$headLevl = 1;
			}
			$outlLevl = strval($this->findChildArray($stlArr, Array('w:pPr', 'w:outlineLvl'))['attrs']['w:val'] ?? '');
			if( $headLevl==0 && preg_match('/^[0-8]$/D', $outlLevl) ){
				$headLevl = min(6, intval($outlLevl)+1);
			}
			$numbList = $this->findChildArray($stlArr, Array('w:pPr', 'w:numPr')) ?? Array('nodes'=>Array());
			$this->styleTable[$styleKey] = Array(
				'names'=>$styleNme, 'level'=>$headLevl, 'marks'=>$this->marksOfRunList($stlArr),
				'numid'=>strval($this->findChildArray($numbList, Array('w:numId'))['attrs']['w:val'] ?? ''), 'ilevl'=>intval($this->findChildArray($numbList, Array('w:ilvl'))['attrs']['w:val'] ?? 0),
				'align'=>strval(self::ALGN_LIST[strtolower(strval($this->findChildArray($stlArr, Array('w:pPr', 'w:jc'))['attrs']['w:val'] ?? ''))] ?? ''),
				'based'=>strval($this->findChildArray($stlArr, Array('w:basedOn'))['attrs']['w:val'] ?? '')
			) + $this->fontOfStyleArr($stlArr);
			if( strtolower(strval($stlArr['attrs']['w:type'] ?? ''))=='paragraph' && in_array(strtolower(strval($stlArr['attrs']['w:default'] ?? '')), Array('1', 'true')) ){
				$this->defaultKey = $styleKey;
			}
		}
		$baseDict = $this->fontOfStyleArr($this->findChildArray($stylList, Array('w:docDefaults', 'w:rPrDefault')) ?? Array('nodes'=>Array()));
		foreach(array_keys($this->styleTable) as $stlKey){
			$prntClef = $this->styleTable[$stlKey]['based'];
			// Twenty styles based one on the other is more than any document has, and stops a circle
			for($hopIdx=0; $hopIdx<21; $hopIdx++){
				$prntDict = $this->styleTable[$prntClef] ?? null;
				if( $prntDict===null || $hopIdx==20 ){
					$prntDict = $baseDict + Array('level'=>0, 'numid'=>'', 'ilevl'=>0, 'align'=>'', 'marks'=>Array(), 'based'=>'');
					$hopIdx = 20;
				}
				if( $this->styleTable[$stlKey]['level']==0 ){
					$this->styleTable[$stlKey]['level'] = $prntDict['level'];
				}
				if( $this->styleTable[$stlKey]['numid']==='' ){
					$this->styleTable[$stlKey]['numid'] = $prntDict['numid'];
					$this->styleTable[$stlKey]['ilevl'] = $prntDict['ilevl'];
				}
				foreach(Array('align', 'fonts', 'sizes', 'color') as $prpKey){
					if( empty($this->styleTable[$stlKey][$prpKey]) ){
						$this->styleTable[$stlKey][$prpKey] = $prntDict[$prpKey];
					}
				}
				$this->styleTable[$stlKey]['marks'] = array_values(array_intersect(Shape::MARK_LIST, array_merge($this->styleTable[$stlKey]['marks'], $prntDict['marks'])));
				$prntClef = $prntDict['based'];
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
		$numbList = $this->partNodesArray($fullPathName, false) ?? Array('nodes'=>Array());
		$abstrLst = Array();
		foreach($this->kidsNamedArray($numbList, 'w:abstractNum') as $absArr){
			foreach($this->kidsNamedArray($absArr, 'w:lvl') as $lvlArr){
				$numbFrmt = strtolower(strval($this->findChildArray($lvlArr, Array('w:numFmt'))['attrs']['w:val'] ?? 'decimal'));
				$abstrLst[strval($absArr['attrs']['w:abstractNumId'] ?? '')][intval($lvlArr['attrs']['w:ilvl'] ?? 0)] = ($numbFrmt!='bullet' && $numbFrmt!='none');
			}
		}
		foreach($this->kidsNamedArray($numbList, 'w:num') as $numArr){
			$this->numberList[strval($numArr['attrs']['w:numId'] ?? '')] = $abstrLst[strval($this->findChildArray($numArr, Array('w:abstractNumId'))['attrs']['w:val'] ?? '')] ?? Array();
		}
	}

	/**
	 * Reads the properties of the document: the ones Word defines, then
	 * the ones added to it.
	 *
	 * @return Array The properties, by the name Word gives them in its fields; those that are empty are left out.
	 */
	protected function propsReadArray(): Array {
		$propList = Array();
		foreach(Array('docProps/core.xml', 'docProps/app.xml') as $prtNme){
			foreach(($this->partNodesArray($prtNme, false) ?? Array('nodes'=>Array()))['nodes'] as $prpArr){
				$bareName = substr($prpArr['tagnm'], intval(strrpos(':'.$prpArr['tagnm'], ':')));
				if( isset(self::PROP_LIST[$bareName]) && trim($prpArr['value'])!=='' ){
					$propList[self::PROP_LIST[$bareName]] = trim($prpArr['value']);
				}
			}
		}
		foreach($this->kidsNamedArray($this->partNodesArray('docProps/custom.xml', false) ?? Array('nodes'=>Array()), 'property') as $prpArr){
			$valueLst = $prpArr['nodes'][0] ?? null;
			$propName = strval($prpArr['attrs']['name'] ?? '');
			if( $valueLst===null || $propName==='' ){
				continue;
			}
			// A property tells its type by the name of the element holding its value
			$typeName = substr($valueLst['tagnm'], intval(strrpos(':'.$valueLst['tagnm'], ':')));
			$rawValue = $this->collectAllText($valueLst);
			if( $typeName=='bool' ){
				$propList[$propName] = (strtolower($rawValue)=='true' || $rawValue=='1');
			}elseif( preg_match('/^(u?i[1248]|u?int)$/D', $typeName) && preg_match('/^-?\d{1,18}$/D', $rawValue) ){
				$propList[$propName] = intval($rawValue);
			}elseif( in_array($typeName, Array('r4', 'r8', 'decimal')) && is_numeric($rawValue) ){
				$propList[$propName] = floatval($rawValue);
			}else{
				$propList[$propName] = $rawValue;
			}
		}
		return $propList;
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
		$keptRltn = $this->docRelsMap;
		$this->docRelsMap = $this->relsOfFragment($fullPathName);
		$blockLst = $this->bodyBlocksList($elementArray ?? $this->partNodesArray($fullPathName, false) ?? Array('nodes'=>Array()));
		$this->docRelsMap = $keptRltn;
		return $blockLst;
	}

	/**
	 * Reads the notes the text refers to, in the order it refers to
	 * them: the notes of the pages first, then those of the end.
	 *
	 * @param Array $relationList The relations of the document.
	 * @return Array The notes, as blocks.
	 */
	protected function notesReadArray(Array $relationList): Array {
		$blockLst = Array();
		foreach(Array('fnote'=>Array('/footnotes', 'w:footnote'), 'enote'=>Array('/endnotes', 'w:endnote')) as $nteKnd=>$nteSet){
			$numbList = $this->noteNumArr[$nteKnd];
			foreach($relationList as $relSet){
				if( substr($relSet['types'], -strlen($nteSet[0]))!==$nteSet[0] || empty($numbList) ){
					continue;
				}
				$noteList = Array();
				foreach($this->kidsNamedArray($this->partNodesArray($relSet['paths'], false) ?? Array('nodes'=>Array()), $nteSet[1]) as $nteArr){
					$noteList[strval($nteArr['attrs']['w:id'] ?? '')] = $nteArr;
				}
				foreach($numbList as $noteId=>$noteNb){
					if( isset($noteList[$noteId]) ){
						$blockLst[] = $this->holdBlockArray('footnote', $this->fragmentBlocks($relSet['paths'], $noteList[$noteId]), Array('tagid'=>$nteKnd.$noteNb, 'numbr'=>$noteNb));
					}
				}
			}
		}
		return $blockLst;
	}

	/**
	 * Reads the headers, or the footers, of the pages. A document may
	 * have several: each one that differs is given once.
	 *
	 * @param Array  $relationList The relations of the document.
	 * @param String $suffixOfType What ends the kind of the relations to read: '/header' or '/footer'.
	 * @return Array Their blocks.
	 */
	protected function pagesReadArray(Array $relationList, String $suffixOfType): Array {
		$blockLst = Array();
		$seenList = Array();
		foreach($relationList as $relSet){
			if( substr($relSet['types'], -strlen($suffixOfType))!==$suffixOfType ){
				continue;
			}
			$partBloc = $this->fragmentBlocks($relSet['paths']);
			$partClef = md5(serialize($partBloc));
			if( !empty($partBloc) && !isset($seenList[$partClef]) ){
				$seenList[$partClef] = true;
				$blockLst = array_merge($blockLst, $partBloc);
			}
		}
		return $blockLst;
	}

	/**
	 * Reads the comments of the reviewers.
	 *
	 * @param Array $relationList The relations of the document.
	 * @return Array The comments, each with its author under 'authr', its date under 'dated', its text under 'plain' and the passage it is about under 'about'.
	 */
	protected function gatherComments(Array $relationList): Array {
		$noteList = Array();
		foreach($relationList as $relSet){
			if( substr($relSet['types'], -9)!=='/comments' ){
				continue;
			}
			foreach($this->kidsNamedArray($this->partNodesArray($relSet['paths'], false) ?? Array('nodes'=>Array()), 'w:comment') as $remark){
				$noteList[] = Array(
					'authr'=>strval($remark['attrs']['w:author'] ?? ''), 'dated'=>strval($remark['attrs']['w:date'] ?? ''),
					'plain'=>Shape::documentAsText($this->fragmentBlocks($relSet['paths'], $remark)),
					'about'=>trim(strval($this->commentTxt[strval($remark['attrs']['w:id'] ?? '')] ?? ''))
				);
			}
		}
		return $noteList;
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
		$cmntIdnt = strval($elementArray['attrs']['w:id'] ?? '');
		if( $this->redComment && $elementArray['tagnm']=='w:commentRangeStart' ){
			$this->openedList[$cmntIdnt] = '';
		}elseif( isset($this->openedList[$cmntIdnt]) ){
			$this->commentTxt[$cmntIdnt] = $this->openedList[$cmntIdnt];
			unset($this->openedList[$cmntIdnt]);
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
		$blockLst = Array();
		$listBuff = Array();
		$codeBuff = Array();
		$bodyNods = $elementArray['nodes'];
		// An end is added to the nodes, so that a list ending them is closed like any other
		$bodyNods[] = Array('tagnm'=>'#end', 'attrs'=>Array(), 'nodes'=>Array());
		foreach($bodyNods as $chlArr){
			$prgrList = ($chlArr['tagnm']=='w:p') ? $this->paragraphParts($chlArr) : null;
			$prgrCode = strval($prgrList['kinds'] ?? '');
			if( $prgrCode!='listItem' && !empty($listBuff) ){
				$entryPos = 0;
				$blockLst = array_merge($blockLst, $this->buildListBlock($listBuff, $entryPos, 0));
				$listBuff = Array();
			}
			if( $prgrCode!='codeBlck' && !empty($codeBuff) ){
				$blockLst = array_merge($blockLst, $this->codeBlockArray($codeBuff));
				$codeBuff = Array();
			}
			if( $prgrCode=='listItem' ){
				if( $this->keepsEmpty || !empty($prgrList['spans']) || !empty($prgrList['holds']) ){
					$listBuff[] = $prgrList;
				}
			}elseif( $prgrCode=='codeBlck' ){
				$codeBuff[] = $prgrList;
			}elseif( $prgrList!==null ){
				$blockLst = array_merge($blockLst, $this->paragrphBlocks($prgrList));
			}elseif( $chlArr['tagnm']=='w:tbl' ){
				$tableBlc = $this->tableBlockList($chlArr);
				if( !empty($tableBlc['items']) ){
					$blockLst[] = $tableBlc;
				}
			}elseif( $chlArr['tagnm']=='w:sdt' ){
				$blockLst = array_merge($blockLst, $this->bodyBlocksList($this->findChildArray($chlArr, Array('w:sdtContent')) ?? Array('nodes'=>Array())));
			}else{
				$this->remarkMarksFlg($chlArr);
			}
		}
		return $blockLst;
	}

	/**
	 * Reads a paragraph: what it is, and what it holds.
	 *
	 * @param Array $elementArray The element of the paragraph.
	 * @return Array What it is under 'kinds' (headline, listItem, citation, codeBlck or paragrph), its level as a heading under 'level', the list it belongs to under 'numid', its level in it under 'ilevl' and whether that level is numbered under 'order', its style, its alignment and its id under 'attrs', its text under 'spans' and as written under 'plain', the images and the page breaks it holds under 'holds', all of it in order, as blocks, under 'flows', and whether a page break stands before or after it under 'prior' and 'after'.
	 */
	protected function paragraphParts(Array $elementArray): Array {
		$propList = $this->findChildArray($elementArray, Array('w:pPr')) ?? Array('nodes'=>Array());
		$styleKey = strval($this->findChildArray($propList, Array('w:pStyle'))['attrs']['w:val'] ?? '');
		if( !isset($this->styleTable[$styleKey]) ){
			$styleKey = $this->defaultKey;
		}
		$styleDct = $this->styleTable[$styleKey] ?? Array('names'=>'', 'level'=>0, 'numid'=>'', 'ilevl'=>0, 'align'=>'');
		$headLevl = $styleDct['level'];
		$outlLevl = strval($this->findChildArray($propList, Array('w:outlineLvl'))['attrs']['w:val'] ?? '');
		if( preg_match('/^[0-8]$/D', $outlLevl) ){
			$headLevl = min(6, intval($outlLevl)+1);
		}
		// What the paragraph says of its list comes before what its style says
		$numbList = $this->findChildArray($propList, Array('w:numPr'));
		$numbIdnt = ($numbList===null) ? $styleDct['numid'] : strval($this->findChildArray($numbList, Array('w:numId'))['attrs']['w:val'] ?? '');
		$numbLevl = ($numbList===null) ? $styleDct['ilevl'] : intval($this->findChildArray($numbList, Array('w:ilvl'))['attrs']['w:val'] ?? 0);
		// A passage commented over several paragraphs has a space between two of them
		foreach($this->openedList as $cmtIdn=>$cmtTxt){
			$this->openedList[$cmtIdn] = ($cmtTxt==='') ? '' : $cmtTxt.' ';
		}
		$pictList = Array();
		$this->readParaPieces($elementArray, '', $pictList);
		$flowDict = $this->flowPartsArray($pictList, $this->keepsEmpty);
		$spansAll = $flowDict['spans'];
		$attrDict = Array();
		if( $styleDct['names']!=='' ){
			$attrDict['style'] = $styleDct['names'];
			$this->stylesUsed[$styleKey] = true;
		}
		// The alignment is told when the paragraph does not follow its style
		$ownAlign = strval(self::ALGN_LIST[strtolower(strval($this->findChildArray($propList, Array('w:jc'))['attrs']['w:val'] ?? ''))] ?? '');
		if( $ownAlign!=='' && $ownAlign!==$styleDct['align'] && !($ownAlign=='alignLft' && $styleDct['align']==='') ){
			$attrDict['align'] = $ownAlign;
		}
		// A bookmark starting the paragraph names it; those Word adds for itself start with an underscore
		foreach($this->kidsNamedArray($elementArray, 'w:bookmarkStart') as $bkmArr){
			$bkmrName = strval($bkmArr['attrs']['w:name'] ?? '');
			if( $bkmrName!=='' && $bkmrName[0]!='_' && !isset($attrDict['tagid']) ){
				$attrDict['tagid'] = $bkmrName;
			}
		}
		$prgrCode = 'paragrph';
		if( $headLevl>0 && !empty($spansAll) ){
			$prgrCode = 'headline';
		}elseif( $headLevl==0 && $numbIdnt!=='' && $numbIdnt!=='0' ){
			$prgrCode = 'listItem';
		}elseif( $headLevl==0 && isset(self::KIND_LIST[strtolower($styleDct['names'])]) && (!empty($spansAll) || self::KIND_LIST[strtolower($styleDct['names'])]=='codeBlck') ){
			$prgrCode = self::KIND_LIST[strtolower($styleDct['names'])];
		}
		$sctnList = $this->findChildArray($propList, Array('w:sectPr'));
		return Array(
			'kinds'=>$prgrCode, 'level'=>$headLevl,
			'numid'=>$numbIdnt, 'ilevl'=>$numbLevl, 'order'=>!empty($this->numberList[$numbIdnt][$numbLevl]),
			'attrs'=>$attrDict, 'spans'=>$spansAll, 'plain'=>$flowDict['plain'], 'holds'=>$flowDict['holds'], 'flows'=>$flowDict['flows'],
			'prior'=>$this->isElementSetOn($this->findChildArray($propList, Array('w:pageBreakBefore'))),
			// A section that starts on a new page ends this one
			'after'=>($sctnList!==null && strtolower(strval($this->findChildArray($sctnList, Array('w:type'))['attrs']['w:val'] ?? 'nextPage'))!='continuous')
		);
	}

	/**
	 * Tells what a field stands for, from what it asks for.
	 *
	 * @param String $fieldCodeTxt What the field asks for, as Word writes it.
	 * @return String 'pageNumb', 'pageTotl', 'dateTime', 'props:' followed by the name of a property, or '' for any other field.
	 */
	protected function parseFieldCode(String $fieldCodeTxt): String {
		$codeList = Array();
		if( !preg_match('/^\s*([A-Za-z]+)(?:\s+(?:"([^"]*)"|([^\s\\\\]+)))?/', $fieldCodeTxt, $codeList) ){
			return '';
		}
		$fieldNme = strtoupper($codeList[1]);
		if( $fieldNme=='PAGE' ){
			return 'pageNumb';
		}
		if( $fieldNme=='NUMPAGES' || $fieldNme=='SECTIONPAGES' ){
			return 'pageTotl';
		}
		if( in_array($fieldNme, self::FLDS_DATE) ){
			return 'dateTime';
		}
		if( isset(self::FLDS_PROP[$fieldNme]) ){
			return 'props:'.self::FLDS_PROP[$fieldNme];
		}
		$propName = strval(($codeList[2] ?? '')!=='' ? $codeList[2] : ($codeList[3] ?? ''));
		return ($fieldNme=='DOCPROPERTY' && $propName!=='') ? 'props:'.$propName : '';
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
			$childNme = $chlArr['tagnm'];
			if( $childNme=='w:hyperlink' ){
				// A link points to an address kept in the relations of the document, or to a bookmark of the document itself
				$rltnDict = $this->docRelsMap[strval($chlArr['attrs']['r:id'] ?? '')] ?? null;
				$anchText = strval($chlArr['attrs']['w:anchor'] ?? '');
				$linkAddr = Shape::tidyLinkString((($rltnDict!==null) ? $rltnDict['links'] : '').(($anchText!=='') ? '#'.$anchText : ''));
				$this->readParaPieces($chlArr, ($linkAddr!=='') ? $linkAddr : $linkGoalText, $outputPieces);
			}elseif( $childNme=='w:fldSimple' ){
				// A field written in one piece: what it asks for is told beside what it shows
				$keptCode = $this->fieldCodes;
				$this->fieldCodes = $this->parseFieldCode(strval($chlArr['attrs']['w:instr'] ?? ''));
				$this->readParaPieces($chlArr, $linkGoalText, $outputPieces);
				$this->fieldCodes = $keptCode;
			}elseif( in_array($childNme, self::WRAP_LIST) ){
				$this->readParaPieces($chlArr, $linkGoalText, $outputPieces);
			}elseif( $childNme=='w:sdt' ){
				$this->readParaPieces($this->findChildArray($chlArr, Array('w:sdtContent')) ?? Array('nodes'=>Array()), $linkGoalText, $outputPieces);
			}elseif( $childNme=='w:r' ){
				$markList = array_values(array_intersect(Shape::MARK_LIST, array_merge($this->styleTable[strval($this->findChildArray($chlArr, Array('w:rPr', 'w:rStyle'))['attrs']['w:val'] ?? '')]['marks'] ?? Array(), $this->marksOfRunList($chlArr))));
				foreach($chlArr['nodes'] as $oneRun){
					$this->readOneRunPart($oneRun, $markList, $linkGoalText, $outputPieces);
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
		$pictName = $elementArray['tagnm'];
		if( $pictName=='w:fldChar' ){
			$charCode = strtolower(strval($elementArray['attrs']['w:fldCharType'] ?? ''));
			$codeList = Array();
			$this->fieldLinks = '';
			$this->fieldCodes = '';
			if( $charCode=='begin' ){
				$this->fieldState = 1;
				$this->fieldInstr = '';
			}elseif( $charCode=='separate' ){
				$this->fieldState = 2;
				$this->fieldCodes = $this->parseFieldCode($this->fieldInstr);
				if( preg_match('/^\s*HYPERLINK\s+(\\\\l\s+)?"([^"]*)"/i', $this->fieldInstr, $codeList) ){
					$this->fieldLinks = Shape::tidyLinkString((($codeList[1]!=='') ? '#' : '').$codeList[2]);
				}
			}else{
				$this->fieldState = 0;
			}
			return;
		}
		if( $this->fieldState==1 ){
			$this->fieldInstr .= ($pictName=='w:instrText') ? $this->collectAllText($elementArray) : '';
			return;
		}
		$pictText = '';
		$pictLink = ($linkGoalText!=='') ? $linkGoalText : $this->fieldLinks;
		if( $pictName=='w:t' ){
			$pictText = $this->collectAllText($elementArray);
		}elseif( $pictName=='w:tab' ){
			$pictText = "\t";
		}elseif( $pictName=='w:br' && strtolower(strval($elementArray['attrs']['w:type'] ?? ''))=='page' ){
			$outputPieces[] = Shape::makeBlockArray('ruleLine', Array(), Array(), Array('break'=>true));
		}elseif( $pictName=='w:cr' || ($pictName=='w:br' && strtolower(strval($elementArray['attrs']['w:type'] ?? ''))!='column') ){
			$pictText = "\n";
		}elseif( $pictName=='w:noBreakHyphen' ){
			$pictText = "\u{2011}";
		}elseif( $pictName=='w:footnoteReference' || $pictName=='w:endnoteReference' ){
			// A note is numbered by the place of its first reference in the text
			$noteCode = ($pictName=='w:footnoteReference') ? 'fnote' : 'enote';
			$noteRefr = strval($elementArray['attrs']['w:id'] ?? '');
			if( !isset($this->noteNumArr[$noteCode][$noteRefr]) ){
				$this->noteNumArr[$noteCode][$noteRefr] = count($this->noteNumArr[$noteCode])+1;
			}
			$outputPieces[] = Shape::makeASpanArray(strval($this->noteNumArr[$noteCode][$noteRefr]), Array(), '#'.$noteCode.$this->noteNumArr[$noteCode][$noteRefr]);
			return;
		}elseif( in_array($pictName, Array('w:drawing', 'w:pict', 'w:object')) ){
			$imageBlc = $this->imageBlockList($elementArray);
			if( $imageBlc!==null ){
				$outputPieces[] = $imageBlc;
			}
		}
		if( $pictText!=='' ){
			$outputPieces[] = Shape::makeASpanArray($pictText, $runFormatSet, $pictLink, $this->fieldCodes);
			foreach(array_keys($this->openedList) as $cmtIdn){
				$this->openedList[$cmtIdn] .= $pictText;
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
		$blipList = $this->deepNamedArray($elementArray, Array('a:blip', 'v:imagedata'));
		if( $blipList===null ){
			return null;
		}
		$rltnDict = $this->docRelsMap[strval($blipList['attrs']['r:embed'] ?? $blipList['attrs']['r:link'] ?? $blipList['attrs']['r:id'] ?? '')] ?? null;
		if( $rltnDict===null ){
			return null;
		}
		$imageLnk = Shape::tidyLinkString($rltnDict['outer'] ? $rltnDict['links'] : $rltnDict['paths']);
		if( $imageLnk==='' ){
			return null;
		}
		$altStrng = $this->deepNamedArray($elementArray, Array('wp:docPr'));
		$imageTxt = trim(strval($altStrng['attrs']['descr'] ?? $blipList['attrs']['o:title'] ?? ''));
		return Shape::makeBlockArray('imageBox', ($imageTxt!=='') ? Array(Shape::makeASpanArray($imageTxt)) : Array(), Array(), Array('sourc'=>$imageLnk));
	}

	/**
	 * Builds the lists of some paragraphs that follow one another, from
	 * the level each has: a paragraph further in belongs to the entry
	 * before it, and a change of list at one level starts a new one.
	 *
	 * @param Array $paragraphSet The paragraphs, as paragraphParts() gives them.
	 * @param Int   $itmOffsetNum The paragraph to start from; receives the one following the lists built.
	 * @param Int   $levelValNumb The level of the lists to build.
	 * @return Array The lists, as blocks.
	 */
	protected function buildListBlock(Array $paragraphSet, Int &$itmOffsetNum, Int $levelValNumb): Array {
		$listList = Array();
		$itemList = Array();
		$nowCount = '';
		$nowOrder = false;
		while( isset($paragraphSet[$itmOffsetNum]) && $paragraphSet[$itmOffsetNum]['ilevl']>=$levelValNumb ){
			$itemDict = $paragraphSet[$itmOffsetNum];
			if( $itemDict['ilevl']>$levelValNumb ){
				// Further in: the lists of that level go into the entry before them, an empty one when there is none
				$deepList = $this->buildListBlock($paragraphSet, $itmOffsetNum, $itemDict['ilevl']);
				$lastEntr = empty($itemList) ? Shape::makeBlockArray('listItem') : array_pop($itemList);
				$itemList[] = Shape::makeBlockArray('listItem', $lastEntr['spans'], array_merge($lastEntr['items'], $deepList), $lastEntr['attrs']);
				continue;
			}
			if( !empty($itemList) && $itemDict['numid']!==$nowCount ){
				$listList[] = Shape::makeBlockArray('itemList', Array(), $itemList, Array('order'=>$nowOrder));
				$itemList = Array();
			}
			if( empty($itemList) ){
				$nowCount = $itemDict['numid'];
				$nowOrder = $itemDict['order'];
			}
			$itemList[] = Shape::makeBlockArray('listItem', $itemDict['spans'], $itemDict['holds'], $itemDict['attrs']);
			$itmOffsetNum++;
		}
		if( !empty($itemList) ){
			$listList[] = Shape::makeBlockArray('itemList', Array(), $itemList, Array('order'=>$nowOrder));
		}
		return $listList;
	}

	/**
	 * Reads a table. A cell merged with the ones under it covers their
	 * rows; a row repeated at the top of each page is a header row.
	 *
	 * @param Array $elementArray The element of the table.
	 * @return Array The table, as a block.
	 */
	protected function tableBlockList(Array $elementArray): Array {
		$rowsDict = Array();
		// The cells still running down their column: column => the row and the place of the cell
		$openSpan = Array();
		foreach($this->kidsNamedArray($elementArray, 'w:tr') as $rowArr){
			$rowCells = Array();
			$clmnIndx = 0;
			foreach($this->kidsNamedArray($rowArr, 'w:tc') as $celArr){
				$propList = $this->findChildArray($celArr, Array('w:tcPr')) ?? Array('nodes'=>Array());
				$clmnSpan = max(1, intval($this->findChildArray($propList, Array('w:gridSpan'))['attrs']['w:val'] ?? 1));
				$mrgdList = $this->findChildArray($propList, Array('w:vMerge'));
				$mrgdVals = ($mrgdList===null) ? '' : strtolower(strval($mrgdList['attrs']['w:val'] ?? 'continue'));
				if( $mrgdVals=='continue' && isset($openSpan[$clmnIndx]) ){
					// Covered by the cell above: that cell grows by one row, and this one is not a cell of its own
					$rowsDict[$openSpan[$clmnIndx][0]]['cells'][$openSpan[$clmnIndx][1]]['attrs']['rowsp']++;
					$clmnIndx += $clmnSpan;
					continue;
				}
				unset($openSpan[$clmnIndx]);
				if( $mrgdVals=='restart' ){
					$openSpan[$clmnIndx] = Array(count($rowsDict), count($rowCells));
				}
				$cellBloc = $this->bodyBlocksList($celArr);
				// Word ends every cell with a paragraph: an empty one after something else is only that
				$lastBloc = end($cellBloc);
				if( count($cellBloc)>1 && $lastBloc['block']=='paragrph' && empty($lastBloc['spans']) ){
					array_pop($cellBloc);
				}
				$rowCells[] = Array('blcks'=>$cellBloc, 'attrs'=>Array('colsp'=>$clmnSpan, 'rowsp'=>1));
				$clmnIndx += $clmnSpan;
			}
			$rowsDict[] = Array('thead'=>$this->isElementSetOn($this->findChildArray($rowArr, Array('w:trPr', 'w:tblHeader'))), 'cells'=>$rowCells);
		}
		$rowsList = Array();
		foreach($rowsDict as $rowSet){
			$cellList = Array();
			foreach($rowSet['cells'] as $celSet){
				// Only what a cell covers beyond itself is worth telling; the paragraph that is its text tells its style
				$spanAttr = array_filter($celSet['attrs'], function($colSpanCount){ return $colSpanCount>1; });
				$firstBlc = $celSet['blcks'][0] ?? Array('block'=>'', 'attrs'=>Array());
				$cellList[] = $this->holdBlockArray('cellText', $celSet['blcks'], $spanAttr + (($firstBlc['block']=='paragrph') ? $firstBlc['attrs'] : Array()));
			}
			if( !empty($cellList) ){
				$rowsList[] = Shape::makeBlockArray($rowSet['thead'] ? 'tableTop' : 'tableRow', Array(), $cellList);
			}
		}
		return Shape::makeBlockArray('tableSet', Array(), $rowsList);
	}

}
