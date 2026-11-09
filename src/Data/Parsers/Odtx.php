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
	const ALGN_LIST = Array('start'=>'lefts', 'left'=>'lefts', 'end'=>'right', 'right'=>'right', 'center'=>'centr', 'justify'=>'justf');
	// The styles that make another kind of block than a paragraph, by their name in lower case
	const KIND_LIST = Array('quotations'=>'quote', 'quote'=>'quote', 'preformatted text'=>'codes');
	// The fields, and what each stands for
	const FLDS_CODE = Array(
		'text:page-number'=>'pgnum', 'text:page-count'=>'pgtot',
		'text:date'=>'dates', 'text:time'=>'dates', 'text:creation-date'=>'dates', 'text:creation-time'=>'dates', 'text:modification-date'=>'dates', 'text:modification-time'=>'dates', 'text:print-date'=>'dates', 'text:print-time'=>'dates',
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
	protected $insideBody = false;
	// The notes read, by kind
	protected $noteBlocks = Array('fnote'=>Array(), 'enote'=>Array());
	// Whether empty paragraphs are kept, whether comments are read, the comments read, and the place in that list of those whose passage is still running, by name
	protected $keepsEmpty = true;
	protected $redComment = false;
	protected $commentLst = Array();
	protected $commntOpen = Array();

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
		$this->refuseIfLocked();
		$docTree = $this->partNodesArray('content.xml', true, true) ?? Array('nodes'=>Array());
		$bodyArr = $this->findChildArray($docTree, Array('office:body', 'office:text'));
		if( $bodyArr===null ){
			throw new ParseError('Invalid archive: the document is not a text document');
		}
		$stlsArr = $this->partNodesArray('styles.xml', false, true) ?? Array('nodes'=>Array());
		$this->namedStyle = Array('paragraph'=>Array(), 'text'=>Array());
		$this->listStyles = Array();
		$this->stylesUsed = Array();
		$this->insideBody = false;
		$this->noteBlocks = Array('fnote'=>Array(), 'enote'=>Array());
		$this->keepsEmpty = !isset($parseOptions['empty']) || !empty($parseOptions['empty']);
		$this->redComment = !empty($parseOptions['cmnts']);
		$this->commentLst = Array();
		$this->commntOpen = Array();
		$this->loadNamedStyle($this->findChildArray($stlsArr, Array('office:styles')) ?? Array('nodes'=>Array()));
		// The styles made for what is formatted by hand: those of the pages are apart from those of the text
		$pgeStyl = $this->loadAutosArray($this->findChildArray($stlsArr, Array('office:automatic-styles')) ?? Array('nodes'=>Array()));
		$this->autoStyles = $this->loadAutosArray($this->findChildArray($docTree, Array('office:automatic-styles')) ?? Array('nodes'=>Array()));
		$blckLst = $this->bodyBlocksList($bodyArr, 0, '');
		$blckLst = array_merge($blckLst, $this->noteBlocks['fnote'], $this->noteBlocks['enote']);
		$propLst = $this->propsReadArray();
		$infoArr = Array('title'=>strval($propLst['Title'] ?? ''), 'authr'=>strval($propLst['Author'] ?? ''), 'props'=>$propLst, 'frmat'=>Array(), 'phead'=>Array(), 'pfoot'=>Array());
		// What stands outside the text is read without its empty paragraphs
		$this->keepsEmpty = false;
		$this->autoStyles = $pgeStyl;
		$seenLst = Array();
		foreach($this->kidsNamedArray($this->findChildArray($stlsArr, Array('office:master-styles')) ?? Array('nodes'=>Array()), 'style:master-page') as $pgeArr){
			foreach($pgeArr['nodes'] as $prtArr){
				$infoKey = (strpos($prtArr['tagnm'], 'style:header')===0) ? 'phead' : ((strpos($prtArr['tagnm'], 'style:footer')===0) ? 'pfoot' : '');
				if( $infoKey==='' ){
					continue;
				}
				// A document may have several: each one that differs is given once
				$partBlk = $this->bodyBlocksList($prtArr, 0, '');
				$partKey = $infoKey.md5(serialize($partBlk));
				if( !empty($partBlk) && !isset($seenLst[$partKey]) ){
					$seenLst[$partKey] = true;
					$infoArr[$infoKey] = array_merge($infoArr[$infoKey], $partBlk);
				}
			}
		}
		if( $this->redComment ){
			$infoArr['cmnts'] = Array();
			foreach($this->commentLst as $cmtSet){
				$cmtSet['about'] = trim($cmtSet['about']);
				$infoArr['cmnts'][] = $cmtSet;
			}
		}
		foreach(array_keys($this->stylesUsed) as $stlKey){
			$infoArr['frmat'][$this->namedStyle['paragraph'][$stlKey]['names']] = $this->frmatOfItArray($this->namedStyle['paragraph'][$stlKey]);
		}
		$this->namedStyle = Array();
		$this->autoStyles = Array();
		$this->commentLst = Array();
		return $this->makeShapeArray('block', $blckLst, $infoArr);
	}

	/**
	 * Reads what a style tells of itself, without what it takes from the
	 * style it is based on.
	 *
	 * @param Array $elementArray The element of the style.
	 * @return Array The style it is based on under 'based', its name as shown under 'names', its level as a heading under 'level', then 'marks', 'fonts', 'sizes', 'color' and 'align', whether a page break stands before and after its paragraphs under 'prior' and 'after', and whether it starts another kind of page under 'pages'.
	 */
	protected function styleReadArray(Array $elementArray): Array {
		$attrSet = $elementArray['attrs'];
		$textAtr = $this->findChildArray($elementArray, Array('style:text-properties'))['attrs'] ?? Array();
		$paraAtr = $this->findChildArray($elementArray, Array('style:paragraph-properties'))['attrs'] ?? Array();
		$markLst = Array();
		if( strtolower(strval($textAtr['fo:font-weight'] ?? ''))=='bold' || intval($textAtr['fo:font-weight'] ?? 0)>=600 ){
			$markLst[] = 'bold';
		}
		if( in_array(strtolower(strval($textAtr['fo:font-style'] ?? '')), Array('italic', 'oblique')) ){
			$markLst[] = 'ital';
		}
		if( !in_array(strtolower(strval($textAtr['style:text-underline-style'] ?? 'none')), Array('none', '')) ){
			$markLst[] = 'undr';
		}
		if( !in_array(strtolower(strval($textAtr['style:text-line-through-style'] ?? $textAtr['style:text-line-through-type'] ?? 'none')), Array('none', '')) ){
			$markLst[] = 'strk';
		}
		$sizPart = Array();
		$colrVal = strval($textAtr['fo:color'] ?? '');
		// A name is written with what is not a letter as its code between two underscores: Text_20_body
		$shwnNme = strval($attrSet['style:display-name'] ?? preg_replace_callback('/_([0-9a-fA-F]{2})_/', function($locatedParts){ return chr(hexdec($locatedParts[1])); }, strval($attrSet['style:name'] ?? '')));
		return Array(
			'based'=>strval($attrSet['style:parent-style-name'] ?? ''), 'names'=>$shwnNme, 'level'=>min(6, max(0, intval($attrSet['style:default-outline-level'] ?? 0))), 'marks'=>$markLst,
			'fonts'=>trim(strval($textAtr['style:font-name'] ?? $textAtr['fo:font-family'] ?? ''), " '\""),
			'sizes'=>preg_match('/^(\d+(?:\.\d+)?)pt$/D', strval($textAtr['fo:font-size'] ?? ''), $sizPart) ? floatval($sizPart[1]) : 0,
			'color'=>preg_match('/^#[0-9A-Fa-f]{6}$/D', $colrVal) ? strtoupper($colrVal) : '',
			'align'=>strval(self::ALGN_LIST[strtolower(strval($paraAtr['fo:text-align'] ?? ''))] ?? ''),
			'prior'=>(strtolower(strval($paraAtr['fo:break-before'] ?? ''))=='page'), 'after'=>(strtolower(strval($paraAtr['fo:break-after'] ?? ''))=='page'),
			'pages'=>(strval($attrSet['style:master-page-name'] ?? '')!=='')
		);
	}

	/**
	 * Reads the lists a set of styles defines: for each of them, and each
	 * of its levels, whether it is numbered or only marked.
	 *
	 * @param Array $elementArray The element holding the styles.
	 */
	protected function loadListStyles(Array $elementArray): Void {
		foreach($this->kidsNamedArray($elementArray, 'text:list-style') as $lstArr){
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
		$baseSet = Array('fonts'=>'', 'sizes'=>0, 'color'=>'');
		foreach($this->kidsNamedArray($elementArray, 'style:default-style') as $dflArr){
			if( strval($dflArr['attrs']['style:family'] ?? '')=='paragraph' ){
				$baseSet = array_intersect_key($this->styleReadArray($dflArr), $baseSet);
			}
		}
		foreach($this->kidsNamedArray($elementArray, 'style:style') as $stlArr){
			$famName = strval($stlArr['attrs']['style:family'] ?? '');
			if( isset($this->namedStyle[$famName]) ){
				$this->namedStyle[$famName][strval($stlArr['attrs']['style:name'] ?? '')] = $this->styleReadArray($stlArr);
			}
		}
		foreach(array_keys($this->namedStyle) as $family){
			foreach(array_keys($this->namedStyle[$family]) as $stlKey){
				$prntKey = $this->namedStyle[$family][$stlKey]['based'];
				// Twenty styles based one on the other is more than any document has, and stops a circle
				for($hopIdx=0; $hopIdx<21; $hopIdx++){
					$prntSet = $this->namedStyle[$family][$prntKey] ?? null;
					if( $prntSet===null || $hopIdx==20 ){
						$prntSet = $baseSet + Array('level'=>0, 'align'=>'', 'marks'=>Array(), 'based'=>'', 'prior'=>false, 'after'=>false);
						$hopIdx = 20;
					}
					foreach(Array('level', 'align', 'fonts', 'sizes', 'color', 'prior', 'after') as $prpKey){
						if( empty($this->namedStyle[$family][$stlKey][$prpKey]) ){
							$this->namedStyle[$family][$stlKey][$prpKey] = $prntSet[$prpKey];
						}
					}
					$this->namedStyle[$family][$stlKey]['marks'] = array_values(array_intersect(Shape::MARK_LIST, array_merge($this->namedStyle[$family][$stlKey]['marks'], $prntSet['marks'])));
					$prntKey = $prntSet['based'];
				}
			}
		}
	}

	/**
	 * Reads the styles a part makes for what is formatted by hand.
	 *
	 * @param Array $elementArray The element holding them.
	 * @return Array Those styles, by family then by name, each as styleReadArray() gives it.
	 */
	protected function loadAutosArray(Array $elementArray): Array {
		$this->loadListStyles($elementArray);
		$autoLst = Array('paragraph'=>Array(), 'text'=>Array());
		foreach($this->kidsNamedArray($elementArray, 'style:style') as $stlArr){
			$famName = strval($stlArr['attrs']['style:family'] ?? '');
			if( isset($autoLst[$famName]) ){
				$autoLst[$famName][strval($stlArr['attrs']['style:name'] ?? '')] = $this->styleReadArray($stlArr);
			}
		}
		return $autoLst;
	}

	/**
	 * Reads the properties of the document: the ones every document has,
	 * by the name Word gives them, then the ones added to it.
	 *
	 * @return Array The properties; those that are empty are left out.
	 */
	protected function propsReadArray(): Array {
		$metaArr = $this->findChildArray($this->partNodesArray('meta.xml', false) ?? Array('nodes'=>Array()), Array('office:meta')) ?? Array('nodes'=>Array());
		$propLst = Array();
		$wordLst = Array();
		foreach(Array('dc:title'=>'Title', 'meta:initial-creator'=>'Author', 'dc:subject'=>'Subject', 'dc:description'=>'Comments', 'dc:creator'=>'LastSavedBy') as $tagNme=>$prpNme){
			$propVal = trim(strval($this->findChildArray($metaArr, Array($tagNme))['value'] ?? ''));
			if( $propVal!=='' ){
				$propLst[$prpNme] = $propVal;
			}
		}
		foreach($this->kidsNamedArray($metaArr, 'meta:keyword') as $wrdArr){
			$wordLst[] = trim($wrdArr['value']);
		}
		if( !empty(array_filter($wordLst)) ){
			$propLst['Keywords'] = implode(', ', array_filter($wordLst));
		}
		// A document saved without its first author still has one who saved it
		if( !isset($propLst['Author']) && isset($propLst['LastSavedBy']) ){
			$propLst['Author'] = $propLst['LastSavedBy'];
		}
		foreach($this->kidsNamedArray($metaArr, 'meta:user-defined') as $prpArr){
			$propNme = strval($prpArr['attrs']['meta:name'] ?? '');
			$typeNme = strval($prpArr['attrs']['meta:value-type'] ?? 'string');
			$rawValu = $prpArr['value'];
			if( $propNme==='' ){
				continue;
			}
			if( $typeNme=='boolean' ){
				$propLst[$propNme] = (strtolower($rawValu)=='true');
			}elseif( $typeNme=='float' && is_numeric($rawValu) ){
				$propLst[$propNme] = preg_match('/^-?\d{1,18}$/D', $rawValu) ? intval($rawValu) : floatval($rawValu);
			}else{
				$propLst[$propNme] = $rawValu;
			}
		}
		return $propLst;
	}

	/**
	 * Reads what holds paragraphs, lists and tables: the text of the
	 * document, an entry of a list, a cell of a table, a note, a header.
	 * The lines of code that follow one another make one block.
	 *
	 * @param Array  $elementArray The element.
	 * @param Int    $listsLevelNb Level of the list the element is in, 0 outside any.
	 * @param String $listStyleNme Style of that list, '' outside any.
	 * @return Array The blocks.
	 */
	protected function bodyBlocksList(Array $elementArray, Int $listsLevelNb, String $listStyleNme): Array {
		$blckLst = Array();
		$codBffr = Array();
		$bodyNds = $elementArray['nodes'];
		// An end is added to the nodes, so that code ending them is closed like any other
		$bodyNds[] = Array('tagnm'=>'#end', 'attrs'=>Array(), 'nodes'=>Array());
		foreach($bodyNds as $chlArr){
			$chldNme = $chlArr['tagnm'];
			$paraLst = ($chldNme=='text:p' || $chldNme=='text:h') ? $this->paragraphParts($chlArr) : null;
			if( ($paraLst['kinds'] ?? '')!='codes' && !empty($codBffr) ){
				$blckLst = array_merge($blckLst, $this->codeBlockArray($codBffr));
				$codBffr = Array();
			}
			if( $paraLst!==null && $paraLst['kinds']=='codes' ){
				$codBffr[] = $paraLst;
			}elseif( $paraLst!==null ){
				$blckLst = array_merge($blckLst, $this->paragrphBlocks($paraLst));
			}elseif( $chldNme=='text:list' ){
				$blckLst = array_merge($blckLst, $this->parseListBlock($chlArr, $listsLevelNb+1, $listStyleNme));
			}elseif( $chldNme=='table:table' ){
				$rowsLst = $this->rowsOfTableArr($chlArr, false);
				if( !empty($rowsLst) ){
					$blckLst[] = Shape::makeBlockArray('table', Array(), $rowsLst);
				}
			}elseif( in_array($chldNme, self::WRAP_LIST) ){
				$blckLst = array_merge($blckLst, $this->bodyBlocksList($chlArr, $listsLevelNb, $listStyleNme));
			}
		}
		return $blckLst;
	}

	/**
	 * Reads a list: its entries, each holding its paragraphs and the
	 * lists inside it.
	 *
	 * @param Array  $elementArray The element of the list.
	 * @param Int    $listsLevelNb Its level, from 1.
	 * @param String $listStyleNme Style of the list it is in, which it follows unless it names its own.
	 * @return Array The list, as a block, in a list; an empty list when it has no entry.
	 */
	protected function parseListBlock(Array $elementArray, Int $listsLevelNb, String $listStyleNme): Array {
		$ownStyl = strval($elementArray['attrs']['text:style-name'] ?? $listStyleNme);
		$itemLst = Array();
		foreach($elementArray['nodes'] as $itmArr){
			if( $itmArr['tagnm']!='text:list-item' && $itmArr['tagnm']!='text:list-header' ){
				continue;
			}
			$itmBlck = $this->bodyBlocksList($itmArr, $listsLevelNb, $ownStyl);
			if( !empty($itmBlck) ){
				$itemLst[] = $this->holdBlockArray('entry', $itmBlck);
			}
		}
		return empty($itemLst) ? Array() : Array(Shape::makeBlockArray('lists', Array(), $itemLst, Array('order'=>!empty($this->listStyles[$ownStyl][$listsLevelNb]))));
	}

	/**
	 * Reads the rows of a table, whatever groups they are written in.
	 * The rows repeated at the top of each page are header rows.
	 *
	 * @param Array $elementArray The table, or a group of its rows.
	 * @param Bool  $innerHeadFlg Whether the rows are header rows.
	 * @return Array The rows, as blocks.
	 */
	protected function rowsOfTableArr(Array $elementArray, Bool $innerHeadFlg): Array {
		$rowsLst = Array();
		foreach($elementArray['nodes'] as $rowArr){
			if( in_array($rowArr['tagnm'], Array('table:table-header-rows', 'table:table-rows', 'table:table-row-group')) ){
				$rowsLst = array_merge($rowsLst, $this->rowsOfTableArr($rowArr, $innerHeadFlg || $rowArr['tagnm']=='table:table-header-rows'));
				continue;
			}
			if( $rowArr['tagnm']!='table:table-row' ){
				continue;
			}
			$cellLst = Array();
			// A cell covered by a merged one is not a cell of its own
			foreach($this->kidsNamedArray($rowArr, 'table:table-cell') as $celArr){
				$colSpan = Array();
				foreach(Array('table:number-columns-spanned'=>'colsp', 'table:number-rows-spanned'=>'rowsp') as $atrNme=>$atrKey){
					if( intval($celArr['attrs'][$atrNme] ?? 1)>1 ){
						$colSpan[$atrKey] = intval($celArr['attrs'][$atrNme]);
					}
				}
				$cellLst[] = $this->holdBlockArray('tcell', $this->bodyBlocksList($celArr, 0, ''), $colSpan);
			}
			if( !empty($cellLst) ){
				$rowsLst[] = Shape::makeBlockArray($innerHeadFlg ? 'thead' : 'tbrow', Array(), $cellLst);
			}
		}
		return $rowsLst;
	}

	/**
	 * Reads a paragraph: what it is, and what it holds.
	 *
	 * @param Array $elementArray The element of the paragraph, or of the heading.
	 * @return Array What it is under 'kinds' (headr, quote, codes or parag), its level as a heading under 'level', its style, its alignment and its id under 'attrs', its text under 'spans' and as written under 'plain', the images and the page breaks it holds under 'holds', all of it in order, as blocks, under 'flows', and whether a page break stands before or after it under 'prior' and 'after'.
	 */
	protected function paragraphParts(Array $elementArray): Array {
		// The style of a paragraph formatted by hand is one the document made for it, based on the one it names
		$stylKey = strval($elementArray['attrs']['text:style-name'] ?? '');
		$handSet = $this->autoStyles['paragraph'][$stylKey] ?? null;
		if( $handSet!==null ){
			$stylKey = $handSet['based'];
		}
		$stylSet = $this->namedStyle['paragraph'][$stylKey] ?? null;
		$headLvl = 0;
		if( $elementArray['tagnm']=='text:h' ){
			$headLvl = min(6, max(1, intval($elementArray['attrs']['text:outline-level'] ?? 1)));
		}elseif( $stylSet!==null ){
			// A paragraph written in a heading style is a heading, by the level of the style or by its name
			$lvlPart = Array();
			$headLvl = (strtolower($stylSet['names'])=='title') ? 1 : $stylSet['level'];
			if( $headLvl==0 && preg_match('/^heading ([1-9])$/Di', $stylSet['names'], $lvlPart) ){
				$headLvl = min(6, intval($lvlPart[1]));
			}
		}
		// A passage commented over several paragraphs has a space between two of them
		foreach($this->commntOpen as $cmtIdx){
			$this->commentLst[$cmtIdx]['about'] .= ($this->commentLst[$cmtIdx]['about']==='') ? '' : ' ';
		}
		$picList = Array();
		$this->readParaPieces($elementArray, $handSet['marks'] ?? Array(), '', $picList);
		$flowSet = $this->flowPartsArray($picList, $this->keepsEmpty);
		$attrSet = Array();
		if( $stylSet!==null ){
			$attrSet['style'] = $stylSet['names'];
			$this->stylesUsed[$stylKey] = true;
		}
		// The alignment is told when the paragraph does not follow its style
		$ownAlgn = strval($handSet['align'] ?? '');
		$stlAlgn = strval($stylSet['align'] ?? '');
		if( $ownAlgn!=='' && $ownAlgn!==$stlAlgn && !($ownAlgn=='lefts' && $stlAlgn==='') ){
			$attrSet['align'] = $ownAlgn;
		}
		// A bookmark starting the paragraph names it; those the document adds for itself start with an underscore
		foreach($elementArray['nodes'] as $chlArr){
			$bkmName = in_array($chlArr['tagnm'], Array('text:bookmark', 'text:bookmark-start')) ? strval($chlArr['attrs']['text:name'] ?? '') : '';
			if( $bkmName!=='' && $bkmName[0]!='_' && !isset($attrSet['tagid']) ){
				$attrSet['tagid'] = $bkmName;
			}
		}
		$paraKnd = 'parag';
		$kindFnd = strval(self::KIND_LIST[strtolower(strval($stylSet['names'] ?? ''))] ?? '');
		if( $headLvl>0 && !empty($flowSet['spans']) ){
			$paraKnd = 'headr';
		}elseif( $headLvl==0 && $kindFnd!=='' && (!empty($flowSet['spans']) || $kindFnd=='codes') ){
			$paraKnd = $kindFnd;
		}
		// A page break is asked for by the style, or by a change of the kind of page after the text has started
		$brkPrev = (!empty($handSet['prior']) || !empty($stylSet['prior']) || (!empty($handSet['pages']) && $this->insideBody));
		$this->insideBody = true;
		return Array(
			'kinds'=>$paraKnd, 'level'=>$headLvl, 'attrs'=>$attrSet,
			'spans'=>$flowSet['spans'], 'plain'=>$flowSet['plain'], 'holds'=>$flowSet['holds'], 'flows'=>$flowSet['flows'],
			'prior'=>$brkPrev, 'after'=>(!empty($handSet['after']) || !empty($stylSet['after']))
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
		$outputPieces[] = Shape::makeASpanArray($templateText, $runFormatSet, $linkGoalText, $fldCodesText);
		foreach($this->commntOpen as $cmtIdx){
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
			$chldNme = $chlArr['tagnm'];
			if( $chldNme=='#text' ){
				$this->addTextSegment($chlArr['value'], $runFormatSet, $linkGoalText, '', $outputPieces);
			}elseif( $chldNme=='text:span' ){
				// The style of a piece of text is made for it, or named: either way, its formatting was set by hand
				$spanKey = strval($chlArr['attrs']['text:style-name'] ?? '');
				$spanSet = $this->autoStyles['text'][$spanKey] ?? Array('marks'=>Array(), 'based'=>$spanKey);
				$spnMark = array_merge($runFormatSet, $spanSet['marks'], $this->namedStyle['text'][$spanSet['based']]['marks'] ?? Array());
				$this->readParaPieces($chlArr, array_values(array_intersect(Shape::MARK_LIST, $spnMark)), $linkGoalText, $outputPieces);
			}elseif( $chldNme=='text:a' ){
				$linkAdr = Shape::tidyLinkString(strval($chlArr['attrs']['xlink:href'] ?? ''));
				$this->readParaPieces($chlArr, $runFormatSet, ($linkAdr!=='') ? $linkAdr : $linkGoalText, $outputPieces);
			}elseif( $chldNme=='text:s' ){
				$this->addTextSegment(str_repeat(' ', max(1, min(10000, intval($chlArr['attrs']['text:c'] ?? 1)))), $runFormatSet, $linkGoalText, '', $outputPieces);
			}elseif( $chldNme=='text:tab' || $chldNme=='text:line-break' ){
				$this->addTextSegment(($chldNme=='text:tab') ? "\t" : "\n", $runFormatSet, $linkGoalText, '', $outputPieces);
			}elseif( isset(self::FLDS_CODE[$chldNme]) || $chldNme=='text:user-defined' ){
				$fldCode = ($chldNme=='text:user-defined') ? 'props:'.strval($chlArr['attrs']['text:name'] ?? '') : self::FLDS_CODE[$chldNme];
				$this->addTextSegment($this->odfPieceAsText($chlArr), $runFormatSet, $linkGoalText, $fldCode, $outputPieces);
			}elseif( $chldNme=='text:note' ){
				$this->readNoteAndRef($chlArr, $outputPieces);
			}elseif( $chldNme=='office:annotation' || $chldNme=='office:annotation-end' ){
				$this->commentPortion($chlArr);
			}elseif( $chldNme=='draw:frame' ){
				$this->readFrameBlock($chlArr, $outputPieces);
			}elseif( !in_array($chldNme, self::SKIP_LIST) ){
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
		$noteKnd = (strtolower(strval($elementArray['attrs']['text:note-class'] ?? 'footnote'))=='endnote') ? 'enote' : 'fnote';
		$noteNum = count($this->noteBlocks[$noteKnd])+1;
		// The place is taken before the note is read, for a note may hold another
		$this->noteBlocks[$noteKnd][$noteNum-1] = Array();
		$keptVod = $this->keepsEmpty;
		$this->keepsEmpty = false;
		$bdyBlck = $this->bodyBlocksList($this->findChildArray($elementArray, Array('text:note-body')) ?? Array('nodes'=>Array()), 0, '');
		$this->keepsEmpty = $keptVod;
		$this->noteBlocks[$noteKnd][$noteNum-1] = $this->holdBlockArray('fnote', $bdyBlck, Array('tagid'=>$noteKnd.$noteNum, 'numbr'=>$noteNum));
		$outputPieces[] = Shape::makeASpanArray(strval($noteNum), Array(), '#'.$noteKnd.$noteNum);
	}

	/**
	 * Reads a comment, or the mark ending the passage it is about. A
	 * comment is kept only when the comments are asked for.
	 *
	 * @param Array $elementArray The element of the comment, or of its end.
	 */
	protected function commentPortion(Array $elementArray): Void {
		$noteNme = strval($elementArray['attrs']['office:name'] ?? '');
		if( $elementArray['tagnm']=='office:annotation-end' ){
			unset($this->commntOpen[$noteNme]);
			return;
		}
		if( !$this->redComment ){
			return;
		}
		// What a comment says is not part of the passages being commented
		$kptOpen = $this->commntOpen;
		$keptVod = $this->keepsEmpty;
		$this->commntOpen = Array();
		$this->keepsEmpty = false;
		$noteTxt = Shape::documentAsText($this->bodyBlocksList($elementArray, 0, ''));
		$this->commntOpen = $kptOpen;
		$this->keepsEmpty = $keptVod;
		$this->commentLst[] = Array('authr'=>trim(strval($this->findChildArray($elementArray, Array('dc:creator'))['value'] ?? '')), 'dated'=>trim(strval($this->findChildArray($elementArray, Array('dc:date'))['value'] ?? '')), 'plain'=>$noteTxt, 'about'=>'');
		if( $noteNme!=='' ){
			$this->commntOpen[$noteNme] = count($this->commentLst)-1;
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
		$imgArry = $this->findChildArray($elementArray, Array('draw:image'));
		if( $imgArry!==null ){
			// The address of an image kept in the archive is its name there
			$imgLink = Shape::tidyLinkString(preg_replace('/^\.\//', '', strval($imgArry['attrs']['xlink:href'] ?? '')));
			$imgText = trim($this->odfPieceAsText($this->findChildArray($elementArray, Array('svg:desc')) ?? $this->findChildArray($elementArray, Array('svg:title')) ?? Array('value'=>'', 'nodes'=>Array())));
			if( $imgLink!=='' ){
				$outputPieces[] = Shape::makeBlockArray('image', ($imgText!=='') ? Array(Shape::makeASpanArray($imgText)) : Array(), Array(), Array('sourc'=>$imgLink));
			}
			return;
		}
		$keptVod = $this->keepsEmpty;
		$this->keepsEmpty = false;
		foreach($this->bodyBlocksList($this->findChildArray($elementArray, Array('draw:text-box')) ?? Array('nodes'=>Array()), 0, '') as $boxBlk){
			$outputPieces[] = $boxBlk;
		}
		$this->keepsEmpty = $keptVod;
	}

}
