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
	const ALGNLIST = Array('start'=>'lefts', 'left'=>'lefts', 'end'=>'right', 'right'=>'right', 'center'=>'centr', 'justify'=>'justf');
	// The styles that make another kind of block than a paragraph, by their name in lower case
	const KINDLIST = Array('quotations'=>'quote', 'quote'=>'quote', 'preformatted text'=>'codes');
	// The fields, and what each stands for
	const FLDCODES = Array(
		'text:page-number'=>'pgnum', 'text:page-count'=>'pgtot',
		'text:date'=>'dates', 'text:time'=>'dates', 'text:creation-date'=>'dates', 'text:creation-time'=>'dates', 'text:modification-date'=>'dates', 'text:modification-time'=>'dates', 'text:print-date'=>'dates', 'text:print-time'=>'dates',
		'text:title'=>'props:Title', 'text:subject'=>'props:Subject', 'text:keywords'=>'props:Keywords', 'text:description'=>'props:Comments', 'text:initial-creator'=>'props:Author', 'text:author-name'=>'props:Author', 'text:creator'=>'props:LastSavedBy'
	);
	// Elements that only group blocks
	const WRAPLIST = Array('text:section', 'text:index-body', 'text:index-title', 'text:table-of-content', 'text:alphabetical-index', 'text:illustration-index', 'text:table-index', 'text:object-index', 'text:user-index', 'text:bibliography');
	// Elements of a paragraph that hold no text of it
	const SKIPLIST = Array('text:bookmark', 'text:bookmark-start', 'text:bookmark-end', 'text:soft-page-break', 'text:change', 'text:change-start', 'text:change-end', 'text:reference-mark', 'text:reference-mark-start', 'text:reference-mark-end', 'text:tracked-changes', 'text:ruby-text');

	// The styles the document names, by family then by name; the styles it makes for what is formatted by hand; whether each level of its lists is numbered
	protected $namdStyl = Array();
	protected $autoStyl = Array();
	protected $listStyl = Array();
	// The styles met, and whether a block of the text was already read
	protected $usedStyl = Array();
	protected $bodyOpen = false;
	// The notes read, by kind
	protected $noteBlks = Array('fnote'=>Array(), 'enote'=>Array());
	// Whether empty paragraphs are kept, whether comments are read, the comments read, and the place in that list of those whose passage is still running, by name
	protected $keepVoid = true;
	protected $readCmnt = false;
	protected $cmntList = Array();
	protected $cmntOpen = Array();

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
	 * @throws ParseError When the archive is not a valid text document, or is protected by a password.
	 */
	protected function readArchv(Array $parseOptns): Array {
		$this->checkLock();
		$docuArr = $this->partNodes('content.xml', true, true) ?? Array('nodes'=>Array());
		$bodyArr = $this->findChild($docuArr, Array('office:body', 'office:text'));
		if( $bodyArr===null ){
			throw new ParseError('Invalid archive: the document is not a text document');
		}
		$stlsArr = $this->partNodes('styles.xml', false, true) ?? Array('nodes'=>Array());
		$this->namdStyl = Array('paragraph'=>Array(), 'text'=>Array());
		$this->listStyl = Array();
		$this->usedStyl = Array();
		$this->bodyOpen = false;
		$this->noteBlks = Array('fnote'=>Array(), 'enote'=>Array());
		$this->keepVoid = !isset($parseOptns['empty']) || !empty($parseOptns['empty']);
		$this->readCmnt = !empty($parseOptns['cmnts']);
		$this->cmntList = Array();
		$this->cmntOpen = Array();
		$this->loadNamed($this->findChild($stlsArr, Array('office:styles')) ?? Array('nodes'=>Array()));
		// The styles made for what is formatted by hand: those of the pages are apart from those of the text
		$pageSty = $this->loadAutos($this->findChild($stlsArr, Array('office:automatic-styles')) ?? Array('nodes'=>Array()));
		$this->autoStyl = $this->loadAutos($this->findChild($docuArr, Array('office:automatic-styles')) ?? Array('nodes'=>Array()));
		$blckLst = $this->bodyBlcks($bodyArr, 0, '');
		$blckLst = array_merge($blckLst, $this->noteBlks['fnote'], $this->noteBlks['enote']);
		$propLst = $this->propsRead();
		$infoArr = Array('title'=>strval($propLst['Title'] ?? ''), 'authr'=>strval($propLst['Author'] ?? ''), 'props'=>$propLst, 'frmat'=>Array(), 'phead'=>Array(), 'pfoot'=>Array());
		// What stands outside the text is read without its empty paragraphs
		$this->keepVoid = false;
		$this->autoStyl = $pageSty;
		$seenLst = Array();
		foreach($this->kidsNamed($this->findChild($stlsArr, Array('office:master-styles')) ?? Array('nodes'=>Array()), 'style:master-page') as $pgeArr){
			foreach($pgeArr['nodes'] as $prtArr){
				$infoKey = (strpos($prtArr['tagnm'], 'style:header')===0) ? 'phead' : ((strpos($prtArr['tagnm'], 'style:footer')===0) ? 'pfoot' : '');
				if( $infoKey==='' ){
					continue;
				}
				// A document may have several: each one that differs is given once
				$partBlk = $this->bodyBlcks($prtArr, 0, '');
				$partKey = $infoKey.md5(serialize($partBlk));
				if( !empty($partBlk) && !isset($seenLst[$partKey]) ){
					$seenLst[$partKey] = true;
					$infoArr[$infoKey] = array_merge($infoArr[$infoKey], $partBlk);
				}
			}
		}
		if( $this->readCmnt ){
			$infoArr['cmnts'] = Array();
			foreach($this->cmntList as $cmtSet){
				$cmtSet['about'] = trim($cmtSet['about']);
				$infoArr['cmnts'][] = $cmtSet;
			}
		}
		foreach(array_keys($this->usedStyl) as $stlKey){
			$infoArr['frmat'][$this->namdStyl['paragraph'][$stlKey]['names']] = $this->frmatOfIt($this->namdStyl['paragraph'][$stlKey]);
		}
		$this->namdStyl = Array();
		$this->autoStyl = Array();
		$this->cmntList = Array();
		return $this->makeShape('block', $blckLst, $infoArr);
	}

	/**
	 * Reads what a style tells of itself, without what it takes from the
	 * style it is based on.
	 *
	 * @param Array $nodeValues The element of the style.
	 * @return Array The style it is based on under 'based', its name as shown under 'names', its level as a heading under 'level', then 'marks', 'fonts', 'sizes', 'color' and 'align', whether a page break stands before and after its paragraphs under 'prior' and 'after', and whether it starts another kind of page under 'pages'.
	 */
	protected function styleRead(Array $nodeValues): Array {
		$attrLst = $nodeValues['attrs'];
		$textAtr = $this->findChild($nodeValues, Array('style:text-properties'))['attrs'] ?? Array();
		$paraAtr = $this->findChild($nodeValues, Array('style:paragraph-properties'))['attrs'] ?? Array();
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
		$sizePrt = Array();
		$colrVal = strval($textAtr['fo:color'] ?? '');
		// A name is written with what is not a letter as its code between two underscores: Text_20_body
		$shwnNme = strval($attrLst['style:display-name'] ?? preg_replace_callback('/_([0-9a-fA-F]{2})_/', function($foundParts){ return chr(hexdec($foundParts[1])); }, strval($attrLst['style:name'] ?? '')));
		return Array(
			'based'=>strval($attrLst['style:parent-style-name'] ?? ''), 'names'=>$shwnNme, 'level'=>min(6, max(0, intval($attrLst['style:default-outline-level'] ?? 0))), 'marks'=>$markLst,
			'fonts'=>trim(strval($textAtr['style:font-name'] ?? $textAtr['fo:font-family'] ?? ''), " '\""),
			'sizes'=>preg_match('/^(\d+(?:\.\d+)?)pt$/D', strval($textAtr['fo:font-size'] ?? ''), $sizePrt) ? floatval($sizePrt[1]) : 0,
			'color'=>preg_match('/^#[0-9A-Fa-f]{6}$/D', $colrVal) ? strtoupper($colrVal) : '',
			'align'=>strval(self::ALGNLIST[strtolower(strval($paraAtr['fo:text-align'] ?? ''))] ?? ''),
			'prior'=>(strtolower(strval($paraAtr['fo:break-before'] ?? ''))=='page'), 'after'=>(strtolower(strval($paraAtr['fo:break-after'] ?? ''))=='page'),
			'pages'=>(strval($attrLst['style:master-page-name'] ?? '')!=='')
		);
	}

	/**
	 * Reads the lists a set of styles defines: for each of them, and each
	 * of its levels, whether it is numbered or only marked.
	 *
	 * @param Array $nodeValues The element holding the styles.
	 */
	protected function loadLists(Array $nodeValues): Void {
		foreach($this->kidsNamed($nodeValues, 'text:list-style') as $lstArr){
			foreach($lstArr['nodes'] as $lvlArr){
				// A level is numbered when it is written as such, and shows a number
				$this->listStyl[strval($lstArr['attrs']['style:name'] ?? '')][intval($lvlArr['attrs']['text:level'] ?? 1)] = ($lvlArr['tagnm']=='text:list-level-style-number' && strval($lvlArr['attrs']['style:num-format'] ?? '1')!=='');
			}
		}
	}

	/**
	 * Reads the styles the document names. A style takes what it does not
	 * tell from the one it is based on, then from what the document gives
	 * every text.
	 *
	 * @param Array $nodeValues The element holding them.
	 */
	protected function loadNamed(Array $nodeValues): Void {
		$this->loadLists($nodeValues);
		$baseSet = Array('fonts'=>'', 'sizes'=>0, 'color'=>'');
		foreach($this->kidsNamed($nodeValues, 'style:default-style') as $dflArr){
			if( strval($dflArr['attrs']['style:family'] ?? '')=='paragraph' ){
				$baseSet = array_intersect_key($this->styleRead($dflArr), $baseSet);
			}
		}
		foreach($this->kidsNamed($nodeValues, 'style:style') as $stlArr){
			$stylFam = strval($stlArr['attrs']['style:family'] ?? '');
			if( isset($this->namdStyl[$stylFam]) ){
				$this->namdStyl[$stylFam][strval($stlArr['attrs']['style:name'] ?? '')] = $this->styleRead($stlArr);
			}
		}
		foreach(array_keys($this->namdStyl) as $famKey){
			foreach(array_keys($this->namdStyl[$famKey]) as $stlKey){
				$prntKey = $this->namdStyl[$famKey][$stlKey]['based'];
				// Twenty styles based one on the other is more than any document has, and stops a circle
				for($hopIdx=0; $hopIdx<21; $hopIdx++){
					$prntSet = $this->namdStyl[$famKey][$prntKey] ?? null;
					if( $prntSet===null || $hopIdx==20 ){
						$prntSet = $baseSet + Array('level'=>0, 'align'=>'', 'marks'=>Array(), 'based'=>'', 'prior'=>false, 'after'=>false);
						$hopIdx = 20;
					}
					foreach(Array('level', 'align', 'fonts', 'sizes', 'color', 'prior', 'after') as $prpKey){
						if( empty($this->namdStyl[$famKey][$stlKey][$prpKey]) ){
							$this->namdStyl[$famKey][$stlKey][$prpKey] = $prntSet[$prpKey];
						}
					}
					$this->namdStyl[$famKey][$stlKey]['marks'] = array_values(array_intersect(Shape::MARKLIST, array_merge($this->namdStyl[$famKey][$stlKey]['marks'], $prntSet['marks'])));
					$prntKey = $prntSet['based'];
				}
			}
		}
	}

	/**
	 * Reads the styles a part makes for what is formatted by hand.
	 *
	 * @param Array $nodeValues The element holding them.
	 * @return Array Those styles, by family then by name, each as styleRead() gives it.
	 */
	protected function loadAutos(Array $nodeValues): Array {
		$this->loadLists($nodeValues);
		$autoLst = Array('paragraph'=>Array(), 'text'=>Array());
		foreach($this->kidsNamed($nodeValues, 'style:style') as $stlArr){
			$stylFam = strval($stlArr['attrs']['style:family'] ?? '');
			if( isset($autoLst[$stylFam]) ){
				$autoLst[$stylFam][strval($stlArr['attrs']['style:name'] ?? '')] = $this->styleRead($stlArr);
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
	protected function propsRead(): Array {
		$metaArr = $this->findChild($this->partNodes('meta.xml', false) ?? Array('nodes'=>Array()), Array('office:meta')) ?? Array('nodes'=>Array());
		$propLst = Array();
		$wordLst = Array();
		foreach(Array('dc:title'=>'Title', 'meta:initial-creator'=>'Author', 'dc:subject'=>'Subject', 'dc:description'=>'Comments', 'dc:creator'=>'LastSavedBy') as $tagNme=>$prpNme){
			$propVal = trim(strval($this->findChild($metaArr, Array($tagNme))['value'] ?? ''));
			if( $propVal!=='' ){
				$propLst[$prpNme] = $propVal;
			}
		}
		foreach($this->kidsNamed($metaArr, 'meta:keyword') as $wrdArr){
			$wordLst[] = trim($wrdArr['value']);
		}
		if( !empty(array_filter($wordLst)) ){
			$propLst['Keywords'] = implode(', ', array_filter($wordLst));
		}
		// A document saved without its first author still has one who saved it
		if( !isset($propLst['Author']) && isset($propLst['LastSavedBy']) ){
			$propLst['Author'] = $propLst['LastSavedBy'];
		}
		foreach($this->kidsNamed($metaArr, 'meta:user-defined') as $prpArr){
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
	 * @param Array  $nodeValues The element.
	 * @param Int    $listsLevel Level of the list the element is in, 0 outside any.
	 * @param String $listsStyle Style of that list, '' outside any.
	 * @return Array The blocks.
	 */
	protected function bodyBlcks(Array $nodeValues, Int $listsLevel, String $listsStyle): Array {
		$blckLst = Array();
		$codeBuf = Array();
		$bodyNds = $nodeValues['nodes'];
		// An end is added to the nodes, so that code ending them is closed like any other
		$bodyNds[] = Array('tagnm'=>'#end', 'attrs'=>Array(), 'nodes'=>Array());
		foreach($bodyNds as $chlArr){
			$chldNme = $chlArr['tagnm'];
			$paraSet = ($chldNme=='text:p' || $chldNme=='text:h') ? $this->paraParts($chlArr) : null;
			if( ($paraSet['kinds'] ?? '')!='codes' && !empty($codeBuf) ){
				$blckLst = array_merge($blckLst, $this->codeBlock($codeBuf));
				$codeBuf = Array();
			}
			if( $paraSet!==null && $paraSet['kinds']=='codes' ){
				$codeBuf[] = $paraSet;
			}elseif( $paraSet!==null ){
				$blckLst = array_merge($blckLst, $this->paraBlcks($paraSet));
			}elseif( $chldNme=='text:list' ){
				$blckLst = array_merge($blckLst, $this->listBlock($chlArr, $listsLevel+1, $listsStyle));
			}elseif( $chldNme=='table:table' ){
				$rowsLst = $this->rowsOfTbl($chlArr, false);
				if( !empty($rowsLst) ){
					$blckLst[] = Shape::makeBlock('table', Array(), $rowsLst);
				}
			}elseif( in_array($chldNme, self::WRAPLIST) ){
				$blckLst = array_merge($blckLst, $this->bodyBlcks($chlArr, $listsLevel, $listsStyle));
			}
		}
		return $blckLst;
	}

	/**
	 * Reads a list: its entries, each holding its paragraphs and the
	 * lists inside it.
	 *
	 * @param Array  $nodeValues The element of the list.
	 * @param Int    $listsLevel Its level, from 1.
	 * @param String $listsStyle Style of the list it is in, which it follows unless it names its own.
	 * @return Array The list, as a block, in a list; an empty list when it has no entry.
	 */
	protected function listBlock(Array $nodeValues, Int $listsLevel, String $listsStyle): Array {
		$ownStyl = strval($nodeValues['attrs']['text:style-name'] ?? $listsStyle);
		$entrLst = Array();
		foreach($nodeValues['nodes'] as $itmArr){
			if( $itmArr['tagnm']!='text:list-item' && $itmArr['tagnm']!='text:list-header' ){
				continue;
			}
			$itemBlk = $this->bodyBlcks($itmArr, $listsLevel, $ownStyl);
			if( !empty($itemBlk) ){
				$entrLst[] = $this->holdBlock('entry', $itemBlk);
			}
		}
		return empty($entrLst) ? Array() : Array(Shape::makeBlock('lists', Array(), $entrLst, Array('order'=>!empty($this->listStyl[$ownStyl][$listsLevel]))));
	}

	/**
	 * Reads the rows of a table, whatever groups they are written in.
	 * The rows repeated at the top of each page are header rows.
	 *
	 * @param Array $nodeValues The table, or a group of its rows.
	 * @param Bool  $insideHead Whether the rows are header rows.
	 * @return Array The rows, as blocks.
	 */
	protected function rowsOfTbl(Array $nodeValues, Bool $insideHead): Array {
		$rowsLst = Array();
		foreach($nodeValues['nodes'] as $rowArr){
			if( in_array($rowArr['tagnm'], Array('table:table-header-rows', 'table:table-rows', 'table:table-row-group')) ){
				$rowsLst = array_merge($rowsLst, $this->rowsOfTbl($rowArr, $insideHead || $rowArr['tagnm']=='table:table-header-rows'));
				continue;
			}
			if( $rowArr['tagnm']!='table:table-row' ){
				continue;
			}
			$cellLst = Array();
			// A cell covered by a merged one is not a cell of its own
			foreach($this->kidsNamed($rowArr, 'table:table-cell') as $celArr){
				$spanAtr = Array();
				foreach(Array('table:number-columns-spanned'=>'colsp', 'table:number-rows-spanned'=>'rowsp') as $atrNme=>$atrKey){
					if( intval($celArr['attrs'][$atrNme] ?? 1)>1 ){
						$spanAtr[$atrKey] = intval($celArr['attrs'][$atrNme]);
					}
				}
				$cellLst[] = $this->holdBlock('tcell', $this->bodyBlcks($celArr, 0, ''), $spanAtr);
			}
			if( !empty($cellLst) ){
				$rowsLst[] = Shape::makeBlock($insideHead ? 'thead' : 'tbrow', Array(), $cellLst);
			}
		}
		return $rowsLst;
	}

	/**
	 * Reads a paragraph: what it is, and what it holds.
	 *
	 * @param Array $nodeValues The element of the paragraph, or of the heading.
	 * @return Array What it is under 'kinds' (headr, quote, codes or parag), its level as a heading under 'level', its style, its alignment and its id under 'attrs', its text under 'spans' and as written under 'plain', the images and the page breaks it holds under 'holds', all of it in order, as blocks, under 'flows', and whether a page break stands before or after it under 'prior' and 'after'.
	 */
	protected function paraParts(Array $nodeValues): Array {
		// The style of a paragraph formatted by hand is one the document made for it, based on the one it names
		$stylKey = strval($nodeValues['attrs']['text:style-name'] ?? '');
		$handSet = $this->autoStyl['paragraph'][$stylKey] ?? null;
		if( $handSet!==null ){
			$stylKey = $handSet['based'];
		}
		$stylSet = $this->namdStyl['paragraph'][$stylKey] ?? null;
		$hdrLevl = 0;
		if( $nodeValues['tagnm']=='text:h' ){
			$hdrLevl = min(6, max(1, intval($nodeValues['attrs']['text:outline-level'] ?? 1)));
		}elseif( $stylSet!==null ){
			// A paragraph written in a heading style is a heading, by the level of the style or by its name
			$levlPrt = Array();
			$hdrLevl = (strtolower($stylSet['names'])=='title') ? 1 : $stylSet['level'];
			if( $hdrLevl==0 && preg_match('/^heading ([1-9])$/Di', $stylSet['names'], $levlPrt) ){
				$hdrLevl = min(6, intval($levlPrt[1]));
			}
		}
		// A passage commented over several paragraphs has a space between two of them
		foreach($this->cmntOpen as $cmtIdx){
			$this->cmntList[$cmtIdx]['about'] .= ($this->cmntList[$cmtIdx]['about']==='') ? '' : ' ';
		}
		$pceList = Array();
		$this->runsParts($nodeValues, $handSet['marks'] ?? Array(), '', $pceList);
		$flowSet = $this->flowParts($pceList, $this->keepVoid);
		$attrLst = Array();
		if( $stylSet!==null ){
			$attrLst['style'] = $stylSet['names'];
			$this->usedStyl[$stylKey] = true;
		}
		// The alignment is told when the paragraph does not follow its style
		$ownAlgn = strval($handSet['align'] ?? '');
		$stlAlgn = strval($stylSet['align'] ?? '');
		if( $ownAlgn!=='' && $ownAlgn!==$stlAlgn && !($ownAlgn=='lefts' && $stlAlgn==='') ){
			$attrLst['align'] = $ownAlgn;
		}
		// A bookmark starting the paragraph names it; those the document adds for itself start with an underscore
		foreach($nodeValues['nodes'] as $chlArr){
			$bkmName = in_array($chlArr['tagnm'], Array('text:bookmark', 'text:bookmark-start')) ? strval($chlArr['attrs']['text:name'] ?? '') : '';
			if( $bkmName!=='' && $bkmName[0]!='_' && !isset($attrLst['tagid']) ){
				$attrLst['tagid'] = $bkmName;
			}
		}
		$paraKnd = 'parag';
		$kindFnd = strval(self::KINDLIST[strtolower(strval($stylSet['names'] ?? ''))] ?? '');
		if( $hdrLevl>0 && !empty($flowSet['spans']) ){
			$paraKnd = 'headr';
		}elseif( $hdrLevl==0 && $kindFnd!=='' && (!empty($flowSet['spans']) || $kindFnd=='codes') ){
			$paraKnd = $kindFnd;
		}
		// A page break is asked for by the style, or by a change of the kind of page after the text has started
		$brkPrev = (!empty($handSet['prior']) || !empty($stylSet['prior']) || (!empty($handSet['pages']) && $this->bodyOpen));
		$this->bodyOpen = true;
		return Array(
			'kinds'=>$paraKnd, 'level'=>$hdrLevl, 'attrs'=>$attrLst,
			'spans'=>$flowSet['spans'], 'plain'=>$flowSet['plain'], 'holds'=>$flowSet['holds'], 'flows'=>$flowSet['flows'],
			'prior'=>$brkPrev, 'after'=>(!empty($handSet['after']) || !empty($stylSet['after']))
		);
	}

	/**
	 * Adds a piece of text to those of a paragraph, and to the passages
	 * being commented.
	 *
	 * @param String $textString The text.
	 * @param Array  $marksArray Its formatting.
	 * @param String $linkTarget The address it points to, '' when it is not a link.
	 * @param String $fieldCodes What it stands for, '' for plain text.
	 * @param Array  $piecesList Receives the piece.
	 */
	protected function textPiece(String $textString, Array $marksArray, String $linkTarget, String $fieldCodes, Array &$piecesList): Void {
		if( $textString==='' ){
			return;
		}
		$piecesList[] = Shape::makeASpan($textString, $marksArray, $linkTarget, $fieldCodes);
		foreach($this->cmntOpen as $cmtIdx){
			$this->cmntList[$cmtIdx]['about'] .= $textString;
		}
	}

	/**
	 * Reads the pieces of a paragraph: its text, with its formatting and
	 * its links, its fields, its notes, its images and what its frames
	 * hold.
	 *
	 * @param Array  $nodeValues The paragraph, or an element inside it.
	 * @param Array  $marksArray Formatting given by what is around it.
	 * @param String $linkTarget Address given by a link around it, '' when there is none.
	 * @param Array  $piecesList Receives the pieces, in order: spans, and images and page breaks as blocks.
	 */
	protected function runsParts(Array $nodeValues, Array $marksArray, String $linkTarget, Array &$piecesList): Void {
		// An element holding text only has it as its value
		$this->textPiece($nodeValues['value'], $marksArray, $linkTarget, '', $piecesList);
		foreach($nodeValues['nodes'] as $chlArr){
			$chldNme = $chlArr['tagnm'];
			if( $chldNme=='#text' ){
				$this->textPiece($chlArr['value'], $marksArray, $linkTarget, '', $piecesList);
			}elseif( $chldNme=='text:span' ){
				// The style of a piece of text is made for it, or named: either way, its formatting was set by hand
				$spanKey = strval($chlArr['attrs']['text:style-name'] ?? '');
				$spanSet = $this->autoStyl['text'][$spanKey] ?? Array('marks'=>Array(), 'based'=>$spanKey);
				$spanMrk = array_merge($marksArray, $spanSet['marks'], $this->namdStyl['text'][$spanSet['based']]['marks'] ?? Array());
				$this->runsParts($chlArr, array_values(array_intersect(Shape::MARKLIST, $spanMrk)), $linkTarget, $piecesList);
			}elseif( $chldNme=='text:a' ){
				$linkAdr = Shape::cleanLink(strval($chlArr['attrs']['xlink:href'] ?? ''));
				$this->runsParts($chlArr, $marksArray, ($linkAdr!=='') ? $linkAdr : $linkTarget, $piecesList);
			}elseif( $chldNme=='text:s' ){
				$this->textPiece(str_repeat(' ', max(1, min(10000, intval($chlArr['attrs']['text:c'] ?? 1)))), $marksArray, $linkTarget, '', $piecesList);
			}elseif( $chldNme=='text:tab' || $chldNme=='text:line-break' ){
				$this->textPiece(($chldNme=='text:tab') ? "\t" : "\n", $marksArray, $linkTarget, '', $piecesList);
			}elseif( isset(self::FLDCODES[$chldNme]) || $chldNme=='text:user-defined' ){
				$fldCode = ($chldNme=='text:user-defined') ? 'props:'.strval($chlArr['attrs']['text:name'] ?? '') : self::FLDCODES[$chldNme];
				$this->textPiece($this->odfOfText($chlArr), $marksArray, $linkTarget, $fldCode, $piecesList);
			}elseif( $chldNme=='text:note' ){
				$this->notePiece($chlArr, $piecesList);
			}elseif( $chldNme=='office:annotation' || $chldNme=='office:annotation-end' ){
				$this->cmntPiece($chlArr);
			}elseif( $chldNme=='draw:frame' ){
				$this->framePart($chlArr, $piecesList);
			}elseif( !in_array($chldNme, self::SKIPLIST) ){
				$this->runsParts($chlArr, $marksArray, $linkTarget, $piecesList);
			}
		}
	}

	/**
	 * Reads a note: its reference becomes a link standing in the text,
	 * and the note itself is kept for the end of the document.
	 *
	 * @param Array $nodeValues The element of the note.
	 * @param Array $piecesList Receives the reference.
	 */
	protected function notePiece(Array $nodeValues, Array &$piecesList): Void {
		$noteKnd = (strtolower(strval($nodeValues['attrs']['text:note-class'] ?? 'footnote'))=='endnote') ? 'enote' : 'fnote';
		$noteNum = count($this->noteBlks[$noteKnd])+1;
		// The place is taken before the note is read, for a note may hold another
		$this->noteBlks[$noteKnd][$noteNum-1] = Array();
		$keptVod = $this->keepVoid;
		$this->keepVoid = false;
		$bodyBlk = $this->bodyBlcks($this->findChild($nodeValues, Array('text:note-body')) ?? Array('nodes'=>Array()), 0, '');
		$this->keepVoid = $keptVod;
		$this->noteBlks[$noteKnd][$noteNum-1] = $this->holdBlock('fnote', $bodyBlk, Array('tagid'=>$noteKnd.$noteNum, 'numbr'=>$noteNum));
		$piecesList[] = Shape::makeASpan(strval($noteNum), Array(), '#'.$noteKnd.$noteNum);
	}

	/**
	 * Reads a comment, or the mark ending the passage it is about. A
	 * comment is kept only when the comments are asked for.
	 *
	 * @param Array $nodeValues The element of the comment, or of its end.
	 */
	protected function cmntPiece(Array $nodeValues): Void {
		$cmntNme = strval($nodeValues['attrs']['office:name'] ?? '');
		if( $nodeValues['tagnm']=='office:annotation-end' ){
			unset($this->cmntOpen[$cmntNme]);
			return;
		}
		if( !$this->readCmnt ){
			return;
		}
		// What a comment says is not part of the passages being commented
		$keptOpn = $this->cmntOpen;
		$keptVod = $this->keepVoid;
		$this->cmntOpen = Array();
		$this->keepVoid = false;
		$cmntTxt = Shape::wholeText($this->bodyBlcks($nodeValues, 0, ''));
		$this->cmntOpen = $keptOpn;
		$this->keepVoid = $keptVod;
		$this->cmntList[] = Array('authr'=>trim(strval($this->findChild($nodeValues, Array('dc:creator'))['value'] ?? '')), 'dated'=>trim(strval($this->findChild($nodeValues, Array('dc:date'))['value'] ?? '')), 'plain'=>$cmntTxt, 'about'=>'');
		if( $cmntNme!=='' ){
			$this->cmntOpen[$cmntNme] = count($this->cmntList)-1;
		}
	}

	/**
	 * Reads a frame placed in a paragraph: an image, or a box of text
	 * whose blocks follow the paragraph.
	 *
	 * @param Array $nodeValues The element of the frame.
	 * @param Array $piecesList Receives the image, or the blocks of the box.
	 */
	protected function framePart(Array $nodeValues, Array &$piecesList): Void {
		$imgeArr = $this->findChild($nodeValues, Array('draw:image'));
		if( $imgeArr!==null ){
			// The address of an image kept in the archive is its name there
			$imgSrce = Shape::cleanLink(preg_replace('/^\.\//', '', strval($imgeArr['attrs']['xlink:href'] ?? '')));
			$imgText = trim($this->odfOfText($this->findChild($nodeValues, Array('svg:desc')) ?? $this->findChild($nodeValues, Array('svg:title')) ?? Array('value'=>'', 'nodes'=>Array())));
			if( $imgSrce!=='' ){
				$piecesList[] = Shape::makeBlock('image', ($imgText!=='') ? Array(Shape::makeASpan($imgText)) : Array(), Array(), Array('sourc'=>$imgSrce));
			}
			return;
		}
		$keptVod = $this->keepVoid;
		$this->keepVoid = false;
		foreach($this->bodyBlcks($this->findChild($nodeValues, Array('draw:text-box')) ?? Array('nodes'=>Array()), 0, '') as $boxBlk){
			$piecesList[] = $boxBlk;
		}
		$this->keepVoid = $keptVod;
	}

}
