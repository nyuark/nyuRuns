<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

/**
 * OpenDocument texts (odt), read into text blocks the way Word documents
 * are: headings, paragraphs, lists, tables, images, page breaks and
 * notes, with what is bold, italic, underlined or struck, and the links.
 *
 * Every block tells the style of its paragraph under 'style', and what
 * each style used gives is listed once, under 'frmat', in the details of
 * the document. A field, such as a page number or a property of the
 * document, is read as the text last shown for it, its span telling what
 * it stands for under 'field'.
 *
 * The headers and the footers of the pages, the properties of the
 * document and, when asked for, the comments of its reviewers are given
 * in the details of the document, not among its blocks. The text a
 * tracked change deleted is left out.
 */
class Odtx extends Pack {

	// The alignments, as the document writes them
	const ALGN_LIST = Array('start'=>'alignLft', 'left'=>'alignLft', 'end'=>'alignRgt', 'right'=>'alignRgt', 'center'=>'alignCtr', 'justify'=>'alignJst');
	// The styles that make another kind of block than a paragraph, by their name in lower case
	const KIND_LIST = Array('quotations'=>'citation', 'quote'=>'citation', 'preformatted text'=>'codeBlck');
	// The fields, and what each stands for
	const FLDS_CODE = Array(
		'text:page-number'=>'pageNumb', 'text:page-count'=>'pageTotl',
		'text:date'=>'dateTime', 'text:time'=>'dateTime', 'text:creation-date'=>'dateTime', 'text:creation-time'=>'dateTime', 'text:modification-date'=>'dateTime', 'text:modification-time'=>'dateTime', 'text:print-date'=>'dateTime', 'text:print-time'=>'dateTime',
		'text:title'=>'props:Title', 'text:subject'=>'props:Subject', 'text:keywords'=>'props:Keywords', 'text:description'=>'props:Comments', 'text:initial-creator'=>'props:Author', 'text:author-name'=>'props:Author', 'text:creator'=>'props:LastSavedBy'
	);
	// Elements that only group blocks
	const WRAP_LIST = Array('text:section', 'text:index-body', 'text:index-title', 'text:table-of-content', 'text:alphabetical-index', 'text:illustration-index', 'text:table-index', 'text:object-index', 'text:user-index', 'text:bibliography');
	// Elements of a paragraph that hold no text of it
	const SKIP_LIST = Array('text:bookmark', 'text:bookmark-start', 'text:bookmark-end', 'text:soft-page-break', 'text:change', 'text:change-start', 'text:change-end', 'text:reference-mark', 'text:reference-mark-start', 'text:reference-mark-end', 'text:tracked-changes', 'text:ruby-text');

	// The styles the document names, by family then by name; the styles it makes for what is formatted by hand; whether each level of its lists is numbered
	protected $namedStyle = Array();
	protected $autoStyles = Array();
	protected $listStyles = Array();
	// The styles met, and whether a block of the text was already read
	protected $stylesUsed = Array();
	protected $isInsdBody = false;
	// The notes read, by kind
	protected $noteBlocks = Array('fnote'=>Array(), 'enote'=>Array());
	// Whether empty paragraphs are kept, whether comments are read, the comments read, and the place in that list of those whose passage is still running, by name
	protected $isVoidKept = true;
	protected $isCmntRead = false;
	protected $commentLst = Array();
	protected $openedList = Array();

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
	 * @throws ParseError When the archive is not a valid text document, or is protected by a password.
	 */
	protected function readArchvArray(Array $parseOptions): Array {
		$this->denyLockedFile();
		$docuTree = $this->readPartAsTree('content.xml', true, true) ?? Array('nodes'=>Array());
		$bodyList = $this->findChildArray($docuTree, Array('office:body', 'office:text'));
		if( $bodyList===null ){
			throw new ParseError('Invalid archive: the document is not a text document');
		}
		$stylList = $this->readPartAsTree('styles.xml', false, true) ?? Array('nodes'=>Array());
		$this->namedStyle = Array('paragraph'=>Array(), 'text'=>Array());
		$this->listStyles = Array();
		$this->stylesUsed = Array();
		$this->isInsdBody = false;
		$this->noteBlocks = Array('fnote'=>Array(), 'enote'=>Array());
		$this->isVoidKept = !isset($parseOptions['empty']) || !empty($parseOptions['empty']);
		$this->isCmntRead = !empty($parseOptions['cmnts']);
		$this->commentLst = Array();
		$this->openedList = Array();
		$this->loadNamedStyle($this->findChildArray($stylList, Array('office:styles')) ?? Array('nodes'=>Array()));
		// The styles made for what is formatted by hand: those of the pages are apart from those of the text
		$pageStyl = $this->loadAutosArray($this->findChildArray($stylList, Array('office:automatic-styles')) ?? Array('nodes'=>Array()));
		$this->autoStyles = $this->loadAutosArray($this->findChildArray($docuTree, Array('office:automatic-styles')) ?? Array('nodes'=>Array()));
		$blockLst = $this->readBodyBlocks($bodyList, 0, '');
		$blockLst = array_merge($blockLst, $this->noteBlocks['fnote'], $this->noteBlocks['enote']);
		$propList = $this->readDocMetaDct();
		$infoList = Array('title'=>strval($propList['Title'] ?? ''), 'authr'=>strval($propList['Author'] ?? ''), 'props'=>$propList, 'frmat'=>Array(), 'phead'=>Array(), 'pfoot'=>Array());
		// What stands outside the text is read without its empty paragraphs
		$this->isVoidKept = false;
		$this->autoStyles = $pageStyl;
		$seenList = Array();
		foreach($this->listNamedNodes($this->findChildArray($stylList, Array('office:master-styles')) ?? Array('nodes'=>Array()), 'style:master-page') as $pgeArr){
			foreach($pgeArr['nodes'] as $prtArr){
				$infoClef = (strpos($prtArr['tagnm'], 'style:header')===0) ? 'phead' : ((strpos($prtArr['tagnm'], 'style:footer')===0) ? 'pfoot' : '');
				if( $infoClef==='' ){
					continue;
				}
				// A document may have several: each one that differs is given once
				$partBloc = $this->readBodyBlocks($prtArr, 0, '');
				$partClef = $infoClef.md5(serialize($partBloc));
				if( !empty($partBloc) && !isset($seenList[$partClef]) ){
					$seenList[$partClef] = true;
					$infoList[$infoClef] = array_merge($infoList[$infoClef], $partBloc);
				}
			}
		}
		if( $this->isCmntRead ){
			$infoList['cmnts'] = Array();
			foreach($this->commentLst as $cmtSet){
				$cmtSet['about'] = trim($cmtSet['about']);
				$infoList['cmnts'][] = $cmtSet;
			}
		}
		foreach(array_keys($this->stylesUsed) as $stlKey){
			$infoList['frmat'][$this->namedStyle['paragraph'][$stlKey]['names']] = $this->getStyleFormat($this->namedStyle['paragraph'][$stlKey]);
		}
		$this->namedStyle = Array();
		$this->autoStyles = Array();
		$this->commentLst = Array();
		return $this->makeShapeArray('block', $blockLst, $infoList);
	}

	/**
	 * Reads what a style tells of itself, without what it takes from the
	 * style it is based on.
	 *
	 * @param Array $elementArray The element of the style.
	 * @return Array The style it is based on under 'based', its name as shown under 'names', its level as a heading under 'level', then 'marks', 'fonts', 'sizes', 'color' and 'align', whether a page break stands before and after its paragraphs under 'prior' and 'after', and whether it starts another kind of page under 'pages'.
	 */
	protected function readStyleArray(Array $elementArray): Array {
		$attrDict = $elementArray['attrs'];
		$textAttr = $this->findChildArray($elementArray, Array('style:text-properties'))['attrs'] ?? Array();
		$prgrAttr = $this->findChildArray($elementArray, Array('style:paragraph-properties'))['attrs'] ?? Array();
		$markList = Array();
		if( strtolower(strval($textAttr['fo:font-weight'] ?? ''))=='bold' || intval($textAttr['fo:font-weight'] ?? 0)>=600 ){
			$markList[] = 'boldFace';
		}
		if( in_array(strtolower(strval($textAttr['fo:font-style'] ?? '')), Array('italic', 'oblique')) ){
			$markList[] = 'emphasis';
		}
		if( !in_array(strtolower(strval($textAttr['style:text-underline-style'] ?? 'none')), Array('none', '')) ){
			$markList[] = 'undrLine';
		}
		if( !in_array(strtolower(strval($textAttr['style:text-line-through-style'] ?? $textAttr['style:text-line-through-type'] ?? 'none')), Array('none', '')) ){
			$markList[] = 'crossOut';
		}
		$sizePart = Array();
		$colorVal = strval($textAttr['fo:color'] ?? '');
		// A name is written with what is not a letter as its code between two underscores: Text_20_body
		$shownNme = strval($attrDict['style:display-name'] ?? preg_replace_callback('/_([0-9a-fA-F]{2})_/', function($locatedParts){ return chr(hexdec($locatedParts[1])); }, strval($attrDict['style:name'] ?? '')));
		return Array(
			'based'=>strval($attrDict['style:parent-style-name'] ?? ''), 'names'=>$shownNme, 'level'=>min(6, max(0, intval($attrDict['style:default-outline-level'] ?? 0))), 'marks'=>$markList,
			'fonts'=>trim(strval($textAttr['style:font-name'] ?? $textAttr['fo:font-family'] ?? ''), " '\""),
			'sizes'=>preg_match('/^(\d+(?:\.\d+)?)pt$/D', strval($textAttr['fo:font-size'] ?? ''), $sizePart) ? floatval($sizePart[1]) : 0,
			'color'=>preg_match('/^#[0-9A-Fa-f]{6}$/D', $colorVal) ? strtoupper($colorVal) : '',
			'align'=>strval(self::ALGN_LIST[strtolower(strval($prgrAttr['fo:text-align'] ?? ''))] ?? ''),
			'prior'=>(strtolower(strval($prgrAttr['fo:break-before'] ?? ''))=='page'), 'after'=>(strtolower(strval($prgrAttr['fo:break-after'] ?? ''))=='page'),
			'pages'=>(strval($attrDict['style:master-page-name'] ?? '')!=='')
		);
	}

	/**
	 * Reads the lists a set of styles defines: for each of them, and each
	 * of its levels, whether it is numbered or only marked.
	 *
	 * @param Array $elementArray The element holding the styles.
	 */
	protected function loadListStyles(Array $elementArray): Void {
		foreach($this->listNamedNodes($elementArray, 'text:list-style') as $lstArr){
			foreach($lstArr['nodes'] as $lvlArr){
				// A level is numbered when it is written as such, and shows a number
				$this->listStyles[strval($lstArr['attrs']['style:name'] ?? '')][intval($lvlArr['attrs']['text:level'] ?? 1)] = ($lvlArr['tagnm']=='text:list-level-style-number' && strval($lvlArr['attrs']['style:num-format'] ?? '1')!=='');
			}
		}
	}

	/**
	 * Reads the styles the document names. A style takes what it does not
	 * tell from the one it is based on, then from what the document gives
	 * every text.
	 *
	 * @param Array $elementArray The element holding them.
	 */
	protected function loadNamedStyle(Array $elementArray): Void {
		$this->loadListStyles($elementArray);
		$baseDict = Array('fonts'=>'', 'sizes'=>0, 'color'=>'');
		foreach($this->listNamedNodes($elementArray, 'style:default-style') as $dflArr){
			if( strval($dflArr['attrs']['style:family'] ?? '')=='paragraph' ){
				$baseDict = array_intersect_key($this->readStyleArray($dflArr), $baseDict);
			}
		}
		foreach($this->listNamedNodes($elementArray, 'style:style') as $stlArr){
			$famiName = strval($stlArr['attrs']['style:family'] ?? '');
			if( isset($this->namedStyle[$famiName]) ){
				$this->namedStyle[$famiName][strval($stlArr['attrs']['style:name'] ?? '')] = $this->readStyleArray($stlArr);
			}
		}
		foreach(array_keys($this->namedStyle) as $family){
			foreach(array_keys($this->namedStyle[$family]) as $stlKey){
				$prntClef = $this->namedStyle[$family][$stlKey]['based'];
				// Twenty styles based one on the other is more than any document has, and stops a circle
				for($hopIdx=0; $hopIdx<21; $hopIdx++){
					$prntDict = $this->namedStyle[$family][$prntClef] ?? null;
					if( $prntDict===null || $hopIdx==20 ){
						$prntDict = $baseDict + Array('level'=>0, 'align'=>'', 'marks'=>Array(), 'based'=>'', 'prior'=>false, 'after'=>false);
						$hopIdx = 20;
					}
					foreach(Array('level', 'align', 'fonts', 'sizes', 'color', 'prior', 'after') as $prpKey){
						if( empty($this->namedStyle[$family][$stlKey][$prpKey]) ){
							$this->namedStyle[$family][$stlKey][$prpKey] = $prntDict[$prpKey];
						}
					}
					$this->namedStyle[$family][$stlKey]['marks'] = array_values(array_intersect(Shape::MARK_LIST, array_merge($this->namedStyle[$family][$stlKey]['marks'], $prntDict['marks'])));
					$prntClef = $prntDict['based'];
				}
			}
		}
	}

	/**
	 * Reads the styles a part makes for what is formatted by hand.
	 *
	 * @param Array $elementArray The element holding them.
	 * @return Array Those styles, by family then by name, each as readStyleArray() gives it.
	 */
	protected function loadAutosArray(Array $elementArray): Array {
		$this->loadListStyles($elementArray);
		$autoList = Array('paragraph'=>Array(), 'text'=>Array());
		foreach($this->listNamedNodes($elementArray, 'style:style') as $stlArr){
			$famiName = strval($stlArr['attrs']['style:family'] ?? '');
			if( isset($autoList[$famiName]) ){
				$autoList[$famiName][strval($stlArr['attrs']['style:name'] ?? '')] = $this->readStyleArray($stlArr);
			}
		}
		return $autoList;
	}

	/**
	 * Reads the properties of the document: the ones every document has,
	 * by the name Word gives them, then the ones added to it.
	 *
	 * @return Array The properties; those that are empty are left out.
	 */
	protected function readDocMetaDct(): Array {
		$metaList = $this->findChildArray($this->readPartAsTree('meta.xml', false) ?? Array('nodes'=>Array()), Array('office:meta')) ?? Array('nodes'=>Array());
		$propList = Array();
		$wordList = Array();
		foreach(Array('dc:title'=>'Title', 'meta:initial-creator'=>'Author', 'dc:subject'=>'Subject', 'dc:description'=>'Comments', 'dc:creator'=>'LastSavedBy') as $tagNme=>$prpNme){
			$propVals = trim(strval($this->findChildArray($metaList, Array($tagNme))['value'] ?? ''));
			if( $propVals!=='' ){
				$propList[$prpNme] = $propVals;
			}
		}
		foreach($this->listNamedNodes($metaList, 'meta:keyword') as $wrdArr){
			$wordList[] = trim($wrdArr['value']);
		}
		if( !empty(array_filter($wordList)) ){
			$propList['Keywords'] = implode(', ', array_filter($wordList));
		}
		// A document saved without its first author still has one who saved it
		if( !isset($propList['Author']) && isset($propList['LastSavedBy']) ){
			$propList['Author'] = $propList['LastSavedBy'];
		}
		foreach($this->listNamedNodes($metaList, 'meta:user-defined') as $prpArr){
			$propName = strval($prpArr['attrs']['meta:name'] ?? '');
			$typeName = strval($prpArr['attrs']['meta:value-type'] ?? 'string');
			$rawValue = $prpArr['value'];
			if( $propName==='' ){
				continue;
			}
			if( $typeName=='boolean' ){
				$propList[$propName] = (strtolower($rawValue)=='true');
			}elseif( $typeName=='float' && is_numeric($rawValue) ){
				$propList[$propName] = preg_match('/^-?\d{1,18}$/D', $rawValue) ? intval($rawValue) : floatval($rawValue);
			}else{
				$propList[$propName] = $rawValue;
			}
		}
		return $propList;
	}

	/**
	 * Reads what holds paragraphs, lists and tables: the text of the
	 * document, an entry of a list, a cell of a table, a note, a header.
	 * The lines of code that follow one another make one block.
	 *
	 * @param Array  $elementArray The element.
	 * @param Int    $listsLvlNumb Level of the list the element is in, 0 outside any.
	 * @param String $listStyleNme Style of that list, '' outside any.
	 * @return Array The blocks.
	 */
	protected function readBodyBlocks(Array $elementArray, Int $listsLvlNumb, String $listStyleNme): Array {
		$blockLst = Array();
		$codeBuff = Array();
		$bodyNods = $elementArray['nodes'];
		// An end is added to the nodes, so that code ending them is closed like any other
		$bodyNods[] = Array('tagnm'=>'#end', 'attrs'=>Array(), 'nodes'=>Array());
		foreach($bodyNods as $chlArr){
			$childNme = $chlArr['tagnm'];
			$prgrList = ($childNme=='text:p' || $childNme=='text:h') ? $this->readPargrParts($chlArr) : null;
			if( ($prgrList['kinds'] ?? '')!='codeBlck' && !empty($codeBuff) ){
				$blockLst = array_merge($blockLst, $this->joinCodeAsBloc($codeBuff));
				$codeBuff = Array();
			}
			if( $prgrList!==null && $prgrList['kinds']=='codeBlck' ){
				$codeBuff[] = $prgrList;
			}elseif( $prgrList!==null ){
				$blockLst = array_merge($blockLst, $this->getBlocksOfPrg($prgrList));
			}elseif( $childNme=='text:list' ){
				$blockLst = array_merge($blockLst, $this->parseListBlock($chlArr, $listsLvlNumb+1, $listStyleNme));
			}elseif( $childNme=='table:table' ){
				$rowsList = $this->getRowsOfTable($chlArr, false);
				if( !empty($rowsList) ){
					$blockLst[] = Shape::makeBlockArray('tableSet', Array(), $rowsList);
				}
			}elseif( in_array($childNme, self::WRAP_LIST) ){
				$blockLst = array_merge($blockLst, $this->readBodyBlocks($chlArr, $listsLvlNumb, $listStyleNme));
			}
		}
		return $blockLst;
	}

	/**
	 * Reads a list: its entries, each holding its paragraphs and the
	 * lists inside it.
	 *
	 * @param Array  $elementArray The element of the list.
	 * @param Int    $listsLvlNumb Its level, from 1.
	 * @param String $listStyleNme Style of the list it is in, which it follows unless it names its own.
	 * @return Array The list, as a block, in a list; an empty list when it has no entry.
	 */
	protected function parseListBlock(Array $elementArray, Int $listsLvlNumb, String $listStyleNme): Array {
		$ownStyle = strval($elementArray['attrs']['text:style-name'] ?? $listStyleNme);
		$itemList = Array();
		foreach($elementArray['nodes'] as $itmArr){
			if( $itmArr['tagnm']!='text:list-item' && $itmArr['tagnm']!='text:list-header' ){
				continue;
			}
			$itemBloc = $this->readBodyBlocks($itmArr, $listsLvlNumb, $ownStyle);
			if( !empty($itemBloc) ){
				$itemList[] = $this->makeOuterBlock('listItem', $itemBloc);
			}
		}
		return empty($itemList) ? Array() : Array(Shape::makeBlockArray('itemList', Array(), $itemList, Array('order'=>!empty($this->listStyles[$ownStyle][$listsLvlNumb]))));
	}

	/**
	 * Reads the rows of a table, whatever groups they are written in.
	 * The rows repeated at the top of each page are header rows.
	 *
	 * @param Array $elementArray The table, or a group of its rows.
	 * @param Bool  $innerHeadFlg Whether the rows are header rows.
	 * @return Array The rows, as blocks.
	 */
	protected function getRowsOfTable(Array $elementArray, Bool $innerHeadFlg): Array {
		$rowsList = Array();
		foreach($elementArray['nodes'] as $rowArr){
			if( in_array($rowArr['tagnm'], Array('table:table-header-rows', 'table:table-rows', 'table:table-row-group')) ){
				$rowsList = array_merge($rowsList, $this->getRowsOfTable($rowArr, $innerHeadFlg || $rowArr['tagnm']=='table:table-header-rows'));
				continue;
			}
			if( $rowArr['tagnm']!='table:table-row' ){
				continue;
			}
			$cellList = Array();
			// A cell covered by a merged one is not a cell of its own
			foreach($this->listNamedNodes($rowArr, 'table:table-cell') as $celArr){
				$spanAttr = Array();
				foreach(Array('table:number-columns-spanned'=>'colsp', 'table:number-rows-spanned'=>'rowsp') as $atrNme=>$atrKey){
					if( intval($celArr['attrs'][$atrNme] ?? 1)>1 ){
						$spanAttr[$atrKey] = intval($celArr['attrs'][$atrNme]);
					}
				}
				$cellList[] = $this->makeOuterBlock('cellText', $this->readBodyBlocks($celArr, 0, ''), $spanAttr);
			}
			if( !empty($cellList) ){
				$rowsList[] = Shape::makeBlockArray($innerHeadFlg ? 'tableTop' : 'tableRow', Array(), $cellList);
			}
		}
		return $rowsList;
	}

	/**
	 * Reads a paragraph: what it is, and what it holds.
	 *
	 * @param Array $elementArray The element of the paragraph, or of the heading.
	 * @return Array What it is under 'kinds' (headline, citation, codeBlck or paragrph), its level as a heading under 'level', its style, its alignment and its id under 'attrs', its text under 'spans' and as written under 'plain', the images and the page breaks it holds under 'holds', all of it in order, as blocks, under 'flows', and whether a page break stands before or after it under 'prior' and 'after'.
	 */
	protected function readPargrParts(Array $elementArray): Array {
		// The style of a paragraph formatted by hand is one the document made for it, based on the one it names
		$styleKey = strval($elementArray['attrs']['text:style-name'] ?? '');
		$handDict = $this->autoStyles['paragraph'][$styleKey] ?? null;
		if( $handDict!==null ){
			$styleKey = $handDict['based'];
		}
		$styleDct = $this->namedStyle['paragraph'][$styleKey] ?? null;
		$headLevl = 0;
		if( $elementArray['tagnm']=='text:h' ){
			$headLevl = min(6, max(1, intval($elementArray['attrs']['text:outline-level'] ?? 1)));
		}elseif( $styleDct!==null ){
			// A paragraph written in a heading style is a heading, by the level of the style or by its name
			$levelPar = Array();
			$headLevl = (strtolower($styleDct['names'])=='title') ? 1 : $styleDct['level'];
			if( $headLevl==0 && preg_match('/^heading ([1-9])$/Di', $styleDct['names'], $levelPar) ){
				$headLevl = min(6, intval($levelPar[1]));
			}
		}
		// A passage commented over several paragraphs has a space between two of them
		foreach($this->openedList as $cmtIdx){
			$this->commentLst[$cmtIdx]['about'] .= ($this->commentLst[$cmtIdx]['about']==='') ? '' : ' ';
		}
		$pictList = Array();
		$this->readParaPieces($elementArray, $handDict['marks'] ?? Array(), '', $pictList);
		$flowDict = $this->splitFlowParts($pictList, $this->isVoidKept);
		$attrDict = Array();
		if( $styleDct!==null ){
			$attrDict['style'] = $styleDct['names'];
			$this->stylesUsed[$styleKey] = true;
		}
		// The alignment is told when the paragraph does not follow its style
		$ownAlign = strval($handDict['align'] ?? '');
		$styleAln = strval($styleDct['align'] ?? '');
		if( $ownAlign!=='' && $ownAlign!==$styleAln && !($ownAlign=='alignLft' && $styleAln==='') ){
			$attrDict['align'] = $ownAlign;
		}
		// A bookmark starting the paragraph names it; those the document adds for itself start with an underscore
		foreach($elementArray['nodes'] as $chlArr){
			$bkmrName = in_array($chlArr['tagnm'], Array('text:bookmark', 'text:bookmark-start')) ? strval($chlArr['attrs']['text:name'] ?? '') : '';
			if( $bkmrName!=='' && $bkmrName[0]!='_' && !isset($attrDict['tagid']) ){
				$attrDict['tagid'] = $bkmrName;
			}
		}
		$prgrCode = 'paragrph';
		$kindFind = strval(self::KIND_LIST[strtolower(strval($styleDct['names'] ?? ''))] ?? '');
		if( $headLevl>0 && !empty($flowDict['spans']) ){
			$prgrCode = 'headline';
		}elseif( $headLevl==0 && $kindFind!=='' && (!empty($flowDict['spans']) || $kindFind=='codeBlck') ){
			$prgrCode = $kindFind;
		}
		// A page break is asked for by the style, or by a change of the kind of page after the text has started
		$brekPrev = (!empty($handDict['prior']) || !empty($styleDct['prior']) || (!empty($handDict['pages']) && $this->isInsdBody));
		$this->isInsdBody = true;
		return Array(
			'kinds'=>$prgrCode, 'level'=>$headLevl, 'attrs'=>$attrDict,
			'spans'=>$flowDict['spans'], 'plain'=>$flowDict['plain'], 'holds'=>$flowDict['holds'], 'flows'=>$flowDict['flows'],
			'prior'=>$brekPrev, 'after'=>(!empty($handDict['after']) || !empty($styleDct['after']))
		);
	}

	/**
	 * Adds a piece of text to those of a paragraph, and to the passages
	 * being commented.
	 *
	 * @param String $templateText The text.
	 * @param Array  $runFormatSet Its formatting.
	 * @param String $linkGoalText The address it points to, '' when it is not a link.
	 * @param String $fldCodesText What it stands for, '' for plain text.
	 * @param Array  $outputPieces Receives the piece.
	 */
	protected function addTextSegment(String $templateText, Array $runFormatSet, String $linkGoalText, String $fldCodesText, Array &$outputPieces): Void {
		if( $templateText==='' ){
			return;
		}
		$outputPieces[] = Shape::makeSpanOfText($templateText, $runFormatSet, $linkGoalText, $fldCodesText);
		foreach($this->openedList as $cmtIdx){
			$this->commentLst[$cmtIdx]['about'] .= $templateText;
		}
	}

	/**
	 * Reads the pieces of a paragraph: its text, with its formatting and
	 * its links, its fields, its notes, its images and what its frames
	 * hold.
	 *
	 * @param Array  $elementArray The paragraph, or an element inside it.
	 * @param Array  $runFormatSet Formatting given by what is around it.
	 * @param String $linkGoalText Address given by a link around it, '' when there is none.
	 * @param Array  $outputPieces Receives the pieces, in order: spans, and images and page breaks as blocks.
	 */
	protected function readParaPieces(Array $elementArray, Array $runFormatSet, String $linkGoalText, Array &$outputPieces): Void {
		// An element holding text only has it as its value
		$this->addTextSegment($elementArray['value'], $runFormatSet, $linkGoalText, '', $outputPieces);
		foreach($elementArray['nodes'] as $chlArr){
			$childNme = $chlArr['tagnm'];
			if( $childNme=='#text' ){
				$this->addTextSegment($chlArr['value'], $runFormatSet, $linkGoalText, '', $outputPieces);
			}elseif( $childNme=='text:span' ){
				// The style of a piece of text is made for it, or named: either way, its formatting was set by hand
				$spanClef = strval($chlArr['attrs']['text:style-name'] ?? '');
				$spanDict = $this->autoStyles['text'][$spanClef] ?? Array('marks'=>Array(), 'based'=>$spanClef);
				$spanMark = array_merge($runFormatSet, $spanDict['marks'], $this->namedStyle['text'][$spanDict['based']]['marks'] ?? Array());
				$this->readParaPieces($chlArr, array_values(array_intersect(Shape::MARK_LIST, $spanMark)), $linkGoalText, $outputPieces);
			}elseif( $childNme=='text:a' ){
				$linkAddr = Shape::wipeUnsafeAddr(strval($chlArr['attrs']['xlink:href'] ?? ''));
				$this->readParaPieces($chlArr, $runFormatSet, ($linkAddr!=='') ? $linkAddr : $linkGoalText, $outputPieces);
			}elseif( $childNme=='text:s' ){
				$this->addTextSegment(str_repeat(' ', max(1, min(10000, intval($chlArr['attrs']['text:c'] ?? 1)))), $runFormatSet, $linkGoalText, '', $outputPieces);
			}elseif( $childNme=='text:tab' || $childNme=='text:line-break' ){
				$this->addTextSegment(($childNme=='text:tab') ? "\t" : "\n", $runFormatSet, $linkGoalText, '', $outputPieces);
			}elseif( isset(self::FLDS_CODE[$childNme]) || $childNme=='text:user-defined' ){
				$fieldCod = ($childNme=='text:user-defined') ? 'props:'.strval($chlArr['attrs']['text:name'] ?? '') : self::FLDS_CODE[$childNme];
				$this->addTextSegment($this->getOdfPieceTxt($chlArr), $runFormatSet, $linkGoalText, $fieldCod, $outputPieces);
			}elseif( $childNme=='text:note' ){
				$this->readNoteAndRef($chlArr, $outputPieces);
			}elseif( $childNme=='office:annotation' || $childNme=='office:annotation-end' ){
				$this->commentPortion($chlArr);
			}elseif( $childNme=='draw:frame' ){
				$this->readFrameBlock($chlArr, $outputPieces);
			}elseif( !in_array($childNme, self::SKIP_LIST) ){
				$this->readParaPieces($chlArr, $runFormatSet, $linkGoalText, $outputPieces);
			}
		}
	}

	/**
	 * Reads a note: its reference becomes a link standing in the text,
	 * and the note itself is kept for the end of the document.
	 *
	 * @param Array $elementArray The element of the note.
	 * @param Array $outputPieces Receives the reference.
	 */
	protected function readNoteAndRef(Array $elementArray, Array &$outputPieces): Void {
		$noteCode = (strtolower(strval($elementArray['attrs']['text:note-class'] ?? 'footnote'))=='endnote') ? 'enote' : 'fnote';
		$noteNumb = count($this->noteBlocks[$noteCode])+1;
		// The place is taken before the note is read, for a note may hold another
		$this->noteBlocks[$noteCode][$noteNumb-1] = Array();
		$keptVoid = $this->isVoidKept;
		$this->isVoidKept = false;
		$bodyBloc = $this->readBodyBlocks($this->findChildArray($elementArray, Array('text:note-body')) ?? Array('nodes'=>Array()), 0, '');
		$this->isVoidKept = $keptVoid;
		$this->noteBlocks[$noteCode][$noteNumb-1] = $this->makeOuterBlock('footnote', $bodyBloc, Array('tagid'=>$noteCode.$noteNumb, 'numbr'=>$noteNumb));
		$outputPieces[] = Shape::makeSpanOfText(strval($noteNumb), Array(), '#'.$noteCode.$noteNumb);
	}

	/**
	 * Reads a comment, or the mark ending the passage it is about. A
	 * comment is kept only when the comments are asked for.
	 *
	 * @param Array $elementArray The element of the comment, or of its end.
	 */
	protected function commentPortion(Array $elementArray): Void {
		$noteName = strval($elementArray['attrs']['office:name'] ?? '');
		if( $elementArray['tagnm']=='office:annotation-end' ){
			unset($this->openedList[$noteName]);
			return;
		}
		if( !$this->isCmntRead ){
			return;
		}
		// What a comment says is not part of the passages being commented
		$keptOpen = $this->openedList;
		$keptVoid = $this->isVoidKept;
		$this->openedList = Array();
		$this->isVoidKept = false;
		$noteText = Shape::getTextOfDcmnt($this->readBodyBlocks($elementArray, 0, ''));
		$this->openedList = $keptOpen;
		$this->isVoidKept = $keptVoid;
		$this->commentLst[] = Array('authr'=>trim(strval($this->findChildArray($elementArray, Array('dc:creator'))['value'] ?? '')), 'dated'=>trim(strval($this->findChildArray($elementArray, Array('dc:date'))['value'] ?? '')), 'plain'=>$noteText, 'about'=>'');
		if( $noteName!=='' ){
			$this->openedList[$noteName] = count($this->commentLst)-1;
		}
	}

	/**
	 * Reads a frame placed in a paragraph: an image, or a box of text
	 * whose blocks follow the paragraph.
	 *
	 * @param Array $elementArray The element of the frame.
	 * @param Array $outputPieces Receives the image, or the blocks of the box.
	 */
	protected function readFrameBlock(Array $elementArray, Array &$outputPieces): Void {
		$imageLst = $this->findChildArray($elementArray, Array('draw:image'));
		if( $imageLst!==null ){
			// The address of an image kept in the archive is its name there
			$imageLnk = Shape::wipeUnsafeAddr(preg_replace('/^\.\//', '', strval($imageLst['attrs']['xlink:href'] ?? '')));
			$imageTxt = trim($this->getOdfPieceTxt($this->findChildArray($elementArray, Array('svg:desc')) ?? $this->findChildArray($elementArray, Array('svg:title')) ?? Array('value'=>'', 'nodes'=>Array())));
			if( $imageLnk!=='' ){
				$outputPieces[] = Shape::makeBlockArray('imageBox', ($imageTxt!=='') ? Array(Shape::makeSpanOfText($imageTxt)) : Array(), Array(), Array('sourc'=>$imageLnk));
			}
			return;
		}
		$keptVoid = $this->isVoidKept;
		$this->isVoidKept = false;
		foreach($this->readBodyBlocks($this->findChildArray($elementArray, Array('draw:text-box')) ?? Array('nodes'=>Array()), 0, '') as $boxBlk){
			$outputPieces[] = $boxBlk;
		}
		$this->isVoidKept = $keptVoid;
	}

}
