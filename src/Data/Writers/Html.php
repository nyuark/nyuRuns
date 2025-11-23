<?php

namespace nyu\Data\Writers;

use nyu\Data\Shape;

/**
 * HTML: text blocks as headings, paragraphs, lists and tables, sheets as
 * tables, a tree of values as indented text. Every piece of text is
 * escaped, an address is written only when it is safe to follow, and
 * nothing of a document reaches a style sheet without being checked.
 *
 * The same content is written as XHTML when asked: the HTML an XML
 * reader takes, which a book (EPUB) is made of.
 */
class Html extends Base {

	// HTML element of each formatting, from the outermost to the innermost
	const MARKTAGS = Array('bold'=>'strong', 'ital'=>'em', 'undr'=>'u', 'strk'=>'s', 'code'=>'code');
	// The alignments, as a style sheet writes them
	const ALGNLIST = Array('lefts'=>'left', 'right'=>'right', 'centr'=>'center', 'justf'=>'justify');

	// What each style of the content gives, by its name, and whether the styles are written as classes
	protected $frmtList = Array();
	protected $withClss = true;
	// Whether the content is written as XHTML
	protected $xhtmlOut = false;

	/**
	 * Writes a content as HTML.
	 *
	 * Options:
	 *   'whole' => writes a whole page: its head with the title and the
	 *              language given by 'infos', then the headers of the
	 *              pages, the content, and the footers;
	 *   'class' => writes the style of each block as a class; on unless
	 *              told otherwise;
	 *   'frmat' => writes the style sheet of those classes, from what
	 *              'infos' tells of each style; on with 'whole' unless
	 *              told otherwise, off without;
	 *   'heads' => for sheets, writes the first row of each as its header;
	 *   'shown' => for sheets, leaves out those their workbook hides;
	 *   'xhtml' => writes XHTML: every element closed, every attribute
	 *              with its value, and nothing an XML reader refuses.
	 *
	 * @param Array $shapedData The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptns Options: 'whole', 'class', 'frmat', 'heads', 'shown', 'xhtml'.
	 * @return String The HTML.
	 *
	 * @throws WriteError When the shape is not 'block', 'sheet' or 'nodes', or a block is of an unknown kind.
	 */
	public function writeData(Array $shapedData, Array $writeOptns=Array()): String {
		$shapeAs = $this->checkShpe($shapedData, Array('block', 'sheet', 'nodes'), 'HTML');
		$itemLst = $shapedData['items'] ?? Array();
		$infoArr = (array)($shapedData['infos'] ?? Array());
		$this->frmtList = is_array($infoArr['frmat'] ?? null) ? $infoArr['frmat'] : Array();
		$this->withClss = !isset($writeOptns['class']) || !empty($writeOptns['class']);
		$this->xhtmlOut = !empty($writeOptns['xhtml']);
		if( $shapeAs=='block' ){
			$htmBody = $this->blocksOut((array)$itemLst);
		}elseif( $shapeAs=='sheet' ){
			$htmBody = $this->sheetsOut((array)$itemLst, !empty($writeOptns['heads']), !empty($writeOptns['shown']));
		}else{
			$htmBody = '<pre>'.$this->escapeTxt(strval(json_encode($itemLst, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR))).'</pre>'."\n";
		}
		$isWhole = !empty($writeOptns['whole']);
		$pageTop = '';
		$pageEnd = '';
		if( $isWhole && $shapeAs=='block' ){
			// The headers and the footers of the pages of a document stand once, around its content
			$pageTop = empty($infoArr['phead']) ? '' : "<header>\n".$this->blocksOut((array)$infoArr['phead'])."</header>\n";
			$pageEnd = empty($infoArr['pfoot']) ? '' : "<footer>\n".$this->blocksOut((array)$infoArr['pfoot'])."</footer>\n";
		}
		$stylHtm = (isset($writeOptns['frmat']) ? !empty($writeOptns['frmat']) : $isWhole) ? $this->stylesOut(strpos($pageTop.$htmBody.$pageEnd, '<hr class="break"')!==false) : '';
		$this->frmtList = Array();
		if( !$isWhole ){
			return $stylHtm.$htmBody;
		}
		$langAtr = preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/D', strval($infoArr['langg'] ?? '')) ? ' lang="'.$infoArr['langg'].'"' : '';
		if( $this->xhtmlOut ){
			// An XML reader is told what the page is written in, and in what language, its own way
			$langAtr = ' xmlns="http://www.w3.org/1999/xhtml"'.$langAtr.str_replace(' lang=', ' xml:lang=', $langAtr);
			return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE html>\n<html".$langAtr.">\n<head>\n<meta charset=\"utf-8\"/>\n<title>".$this->escapeTxt(strval($infoArr['title'] ?? ''))."</title>\n".$stylHtm."</head>\n<body>\n".$pageTop.$htmBody.$pageEnd."</body>\n</html>\n";
		}
		return "<!DOCTYPE html>\n<html".$langAtr.">\n<head>\n<meta charset=\"utf-8\">\n<title>".$this->escapeTxt(strval($infoArr['title'] ?? ''))."</title>\n".$stylHtm."</head>\n<body>\n".$pageTop.$htmBody.$pageEnd."</body>\n</html>\n";
	}

	/**
	 * Escapes a text for HTML, or for XHTML, in content or in an attribute.
	 *
	 * @param String $textString The text.
	 * @return String The text, safe to write.
	 */
	protected function escapeTxt(String $textString): String {
		// XHTML is XML: what XML cannot hold is left out, as for any part of a document made of it
		if( $this->xhtmlOut ){
			return parent::escapeTxt($textString);
		}
		return htmlspecialchars($textString, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}

	/**
	 * Gives the end of an element that holds nothing: closed in XHTML,
	 * as XML asks, left open in HTML.
	 *
	 * @return String The end of the element.
	 */
	protected function closeVoid(): String {
		return $this->xhtmlOut ? '/>' : '>';
	}

	/**
	 * Gives the class a style is written as: the one the content tells
	 * for it, when it is fit for a style sheet, or one made of its name.
	 *
	 * @param String $styleNames Name of the style.
	 * @return String The class, '' when the name gives none.
	 */
	protected function classOfIt(String $styleNames): String {
		$toldCls = strval($this->frmtList[$styleNames]['class'] ?? '');
		if( preg_match('/^[a-z][a-z0-9\-]*$/D', $toldCls) ){
			return $toldCls;
		}
		$madeCls = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($styleNames)), '-');
		return ($madeCls==='') ? '' : (preg_match('/^[a-z]/', $madeCls) ? $madeCls : 's-'.$madeCls);
	}

	/**
	 * Writes the style sheet of the styles the content tells of. Every
	 * value is checked before it is written: a font is a plain name, a
	 * size a number, a colour six digits.
	 *
	 * @param Bool $withBreaks Whether the content holds page breaks.
	 * @return String The style element, '' when there is nothing to write in it.
	 */
	protected function stylesOut(Bool $withBreaks): String {
		$ruleLst = Array();
		foreach($this->frmtList as $stlNme=>$stlSet){
			$stlClss = $this->classOfIt(strval($stlNme));
			$propLst = Array();
			if( !is_array($stlSet) || $stlClss==='' ){
				continue;
			}
			if( preg_match('/^[\p{L}\p{N} \-_.]{1,80}$/Du', strval($stlSet['fonts'] ?? '')) ){
				$propLst[] = 'font-family:"'.$stlSet['fonts'].'"';
			}
			if( is_numeric($stlSet['sizes'] ?? '') && $stlSet['sizes']>0 && $stlSet['sizes']<1000 ){
				$propLst[] = 'font-size:'.floatval($stlSet['sizes']).'pt';
			}
			if( preg_match('/^#[0-9A-Fa-f]{6}$/D', strval($stlSet['color'] ?? '')) ){
				$propLst[] = 'color:'.$stlSet['color'];
			}
			$markLst = (array)($stlSet['marks'] ?? Array());
			if( in_array('bold', $markLst) ){
				$propLst[] = 'font-weight:bold';
			}
			if( in_array('ital', $markLst) ){
				$propLst[] = 'font-style:italic';
			}
			if( in_array('undr', $markLst) || in_array('strk', $markLst) ){
				$propLst[] = 'text-decoration:'.trim((in_array('undr', $markLst) ? 'underline ' : '').(in_array('strk', $markLst) ? 'line-through' : ''));
			}
			if( isset(self::ALGNLIST[strval($stlSet['align'] ?? '')]) ){
				$propLst[] = 'text-align:'.self::ALGNLIST[$stlSet['align']];
			}
			if( !empty($propLst) ){
				$ruleLst[] = '.'.$stlClss.'{'.implode(';', $propLst).'}';
			}
		}
		// A page break shows as nothing on a screen, and starts a new page on paper
		if( $withBreaks ){
			$ruleLst[] = 'hr.break{border:0;break-after:page;page-break-after:always}';
		}
		return empty($ruleLst) ? '' : "<style>\n".implode("\n", $ruleLst)."\n</style>\n";
	}

	/**
	 * Writes what is particular to a block as attributes: its id, the
	 * class of its style, and its alignment when it has one of its own.
	 *
	 * @param Array $blockArray The block.
	 * @return String The attributes, each with its leading space, or ''.
	 */
	protected function blockAttr(Array $blockArray): String {
		$attrLst = (array)($blockArray['attrs'] ?? Array());
		$tagIdnt = strval($attrLst['tagid'] ?? '');
		$attrHtm = preg_match('/^[\w\-:.]+$/D', $tagIdnt) ? ' id="'.$this->escapeTxt($tagIdnt).'"' : '';
		$stlClss = ($this->withClss && strval($attrLst['style'] ?? '')!=='') ? $this->classOfIt(strval($attrLst['style'])) : '';
		if( $stlClss!=='' ){
			$attrHtm .= ' class="'.$stlClss.'"';
		}
		if( isset(self::ALGNLIST[strval($attrLst['align'] ?? '')]) ){
			$attrHtm .= ' style="text-align:'.self::ALGNLIST[$attrLst['align']].'"';
		}
		return $attrHtm;
	}

	/**
	 * Writes spans: each text escaped, inside the elements of its
	 * formatting and of its link, its line breaks as br elements. A
	 * field tells what it stands for, so that it can be found and
	 * replaced; the reference to a note is raised.
	 *
	 * @param Array $spansArray The spans.
	 * @return String The HTML.
	 */
	protected function inlineOut(Array $spansArray): String {
		$htmText = '';
		foreach($spansArray as $oneSpn){
			$spanHtm = str_replace("\n", '<br'.$this->closeVoid()."\n", $this->escapeTxt(strval($oneSpn['texts'] ?? '')));
			foreach(array_reverse(self::MARKTAGS) as $mrkKey=>$mrkTag){
				if( in_array($mrkKey, (array)($oneSpn['marks'] ?? Array())) ){
					$spanHtm = '<'.$mrkTag.'>'.$spanHtm.'</'.$mrkTag.'>';
				}
			}
			if( strval($oneSpn['field'] ?? '')!=='' ){
				$spanHtm = '<span data-field="'.$this->escapeTxt(strval($oneSpn['field'])).'">'.$spanHtm.'</span>';
			}
			$linkAdr = Shape::cleanLink(strval($oneSpn['links'] ?? ''));
			if( $linkAdr!=='' ){
				$spanHtm = '<a href="'.$this->escapeTxt($linkAdr).'">'.$spanHtm.'</a>';
			}
			// The reference to a note, in this page or in another
			if( preg_match('/^[^#]*#[fe]note\d+$/D', $linkAdr) ){
				$spanHtm = '<sup>'.$spanHtm.'</sup>';
			}
			$htmText .= $spanHtm;
		}
		return $htmText;
	}

	/**
	 * Writes what an entry, a cell, a quote or a note holds: its own
	 * text, then the blocks it contains.
	 *
	 * @param Array $blockArray The block.
	 * @return String The HTML.
	 */
	protected function insideOut(Array $blockArray): String {
		$ownHtml = $this->inlineOut($blockArray['spans']);
		if( empty($blockArray['items']) ){
			return $ownHtml;
		}
		return $ownHtml."\n".$this->blocksOut($blockArray['items']);
	}

	/**
	 * Writes a list of blocks, one after the other.
	 *
	 * @param Array $blocksList The blocks.
	 * @return String The HTML.
	 *
	 * @throws WriteError When a block is of an unknown kind.
	 */
	protected function blocksOut(Array $blocksList): String {
		$htmText = '';
		foreach($blocksList as $oneBlk){
			$blckKnd = strval($oneBlk['block'] ?? '');
			$blckAtr = $this->blockAttr($oneBlk);
			if( $blckKnd=='headr' ){
				$hdrLevl = min(6, max(1, intval($oneBlk['attrs']['level'] ?? 1)));
				$htmText .= '<h'.$hdrLevl.$blckAtr.'>'.$this->inlineOut($oneBlk['spans']).'</h'.$hdrLevl.">\n";
			}elseif( $blckKnd=='parag' ){
				$htmText .= '<p'.$blckAtr.'>'.$this->inlineOut($oneBlk['spans'])."</p>\n";
			}elseif( $blckKnd=='lists' ){
				$listTag = empty($oneBlk['attrs']['order']) ? 'ul' : 'ol';
				$htmText .= '<'.$listTag.$blckAtr.">\n";
				foreach($oneBlk['items'] as $entBlk){
					// A task shows the box that tells whether it is done
					$taskHtm = isset($entBlk['attrs']['check']) ? '<input type="checkbox" disabled'.($this->xhtmlOut ? '="disabled"' : '').(empty($entBlk['attrs']['check']) ? '' : ' checked'.($this->xhtmlOut ? '="checked"' : '')).$this->closeVoid().' ' : '';
					$htmText .= '<li'.$this->blockAttr($entBlk).'>'.$taskHtm.$this->insideOut($entBlk)."</li>\n";
				}
				$htmText .= '</'.$listTag.">\n";
			}elseif( $blckKnd=='table' ){
				$htmText .= '<table'.$blckAtr.">\n".$this->tblRowOut($oneBlk['items'])."</table>\n";
			}elseif( $blckKnd=='fnote' ){
				$noteNum = intval($oneBlk['attrs']['numbr'] ?? 0);
				$htmText .= '<aside'.$blckAtr.'>'.(($noteNum>0) ? '<sup>'.$noteNum.'</sup> ' : '').$this->insideOut($oneBlk)."</aside>\n";
			}elseif( $blckKnd=='quote' ){
				// Who is quoted is told under the quotation
				$qteHtml = $this->insideOut($oneBlk);
				$citeTxt = strval($oneBlk['attrs']['cites'] ?? '');
				if( $citeTxt!=='' ){
					$qteHtml .= ((substr($qteHtml, -1)=="\n") ? '' : "\n").'<cite>'.$this->escapeTxt($citeTxt)."</cite>\n";
				}
				$htmText .= '<blockquote'.$blckAtr.'>'.$qteHtml."</blockquote>\n";
			}elseif( $blckKnd=='codes' ){
				$codeLng = strval($oneBlk['attrs']['langg'] ?? '');
				$htmText .= '<pre'.$blckAtr.'><code'.(preg_match('/^[\w+#.\-]+$/D', $codeLng) ? ' class="language-'.$this->escapeTxt($codeLng).'"' : '').'>'.$this->escapeTxt($oneBlk['plain'])."</code></pre>\n";
			}elseif( $blckKnd=='image' ){
				$imgSrce = Shape::cleanLink(strval($oneBlk['attrs']['sourc'] ?? ''));
				if( $imgSrce!=='' ){
					$htmText .= '<img'.$blckAtr.' src="'.$this->escapeTxt($imgSrce).'" alt="'.$this->escapeTxt($oneBlk['plain']).'"'.$this->closeVoid()."\n";
				}
			}elseif( $blckKnd=='rules' ){
				$htmText .= (empty($oneBlk['attrs']['break']) ? '<hr'.$blckAtr : '<hr class="break"').$this->closeVoid()."\n";
			}elseif( in_array($blckKnd, Array('entry', 'tcell')) ){
				// An entry or a cell met outside its list or its table: what it holds
				$htmText .= $this->insideOut($oneBlk)."\n";
			}elseif( in_array($blckKnd, Array('thead', 'tbrow')) ){
				$htmText .= "<table>\n".$this->tblRowOut(Array($oneBlk))."</table>\n";
			}else{
				throw new WriteError('Unknown kind of block: '.$blckKnd);
			}
		}
		return $htmText;
	}

	/**
	 * Writes the rows of a table: the header rows in a thead element,
	 * the others in a tbody.
	 *
	 * @param Array $rowsBlocks The rows, as 'thead' and 'tbrow' blocks.
	 * @return String The HTML.
	 */
	protected function tblRowOut(Array $rowsBlocks): String {
		$partHtm = Array('thead'=>'', 'tbody'=>'');
		foreach($rowsBlocks as $rowBlk){
			$isHeadr = (strval($rowBlk['block'] ?? '')=='thead');
			$rowHtml = '<tr>';
			foreach($rowBlk['items'] as $celBlk){
				$cellTag = ($isHeadr || !empty($celBlk['attrs']['headr'])) ? 'th' : 'td';
				$spanAtr = '';
				foreach(Array('colsp'=>'colspan', 'rowsp'=>'rowspan') as $atrKey=>$atrNme){
					if( intval($celBlk['attrs'][$atrKey] ?? 1)>1 ){
						$spanAtr .= ' '.$atrNme.'="'.intval($celBlk['attrs'][$atrKey]).'"';
					}
				}
				$rowHtml .= '<'.$cellTag.$this->blockAttr($celBlk).$spanAtr.'>'.$this->insideOut($celBlk).'</'.$cellTag.'>';
			}
			$partHtm[$isHeadr ? 'thead' : 'tbody'] .= $rowHtml."</tr>\n";
		}
		return (($partHtm['thead']!=='') ? "<thead>\n".$partHtm['thead']."</thead>\n" : '').(($partHtm['tbody']!=='') ? "<tbody>\n".$partHtm['tbody']."</tbody>\n" : '');
	}

	/**
	 * Writes sheets, each as a table with its title as caption. The rows
	 * keyed by the names of their columns have those names as header;
	 * merged cells are written as one cell over the others.
	 *
	 * @param Array $sheetsList The sheets, each with its 'title', its 'attrs' and its 'cells'.
	 * @param Bool  $firstHeads Whether the first row of each sheet is its header.
	 * @param Bool  $shownsOnly Whether the sheets their workbook hides are left out.
	 * @return String The HTML.
	 */
	protected function sheetsOut(Array $sheetsList, Bool $firstHeads, Bool $shownsOnly): String {
		$htmText = '';
		foreach($sheetsList as $oneSht){
			if( $shownsOnly && isset($oneSht['attrs']['shown']) && $oneSht['attrs']['shown']===false ){
				continue;
			}
			$rowsLst = array_values((array)($oneSht['cells'] ?? Array()));
			// Rows keyed by names had their header taken off when they were read: it is written back, from those names
			$frstRow = (array)($rowsLst[0] ?? Array());
			$isKeyed = (!empty($frstRow) && array_keys($frstRow)!==range(0, count($frstRow)-1));
			$headRow = $isKeyed ? array_map('strval', array_keys($frstRow)) : null;
			if( !$isKeyed && $firstHeads && !empty($rowsLst) ){
				$headRow = array_values((array)array_shift($rowsLst));
			}
			// The merged cells, by the place of their first cell in the rows of the body, and the cells each covers
			$spanLst = Array();
			$coverLs = Array();
			foreach((array)($oneSht['attrs']['merge'] ?? Array()) as $oneMrg){
				$mrgdRow = intval($oneMrg['atrow'] ?? 0)-(($headRow!==null) ? 1 : 0);
				$mrgdCol = intval($oneMrg['atcol'] ?? 0);
				$rowSpan = max(1, intval($oneMrg['rowsp'] ?? 1));
				$colSpan = max(1, intval($oneMrg['colsp'] ?? 1));
				// An area starting in the header is left as it is; one running past the last row stops there
				if( $mrgdRow<0 || !isset($rowsLst[$mrgdRow]) ){
					continue;
				}
				$rowSpan = min($rowSpan, count($rowsLst)-$mrgdRow);
				$spanLst[$mrgdRow][$mrgdCol] = (($colSpan>1) ? ' colspan="'.$colSpan.'"' : '').(($rowSpan>1) ? ' rowspan="'.$rowSpan.'"' : '');
				for($rowIdx=$mrgdRow; $rowIdx<$mrgdRow+$rowSpan; $rowIdx++){
					for($colIdx=$mrgdCol; $colIdx<$mrgdCol+$colSpan && $colIdx<$mrgdCol+16384; $colIdx++){
						$coverLs[$rowIdx][$colIdx] = ($rowIdx!=$mrgdRow || $colIdx!=$mrgdCol);
					}
				}
			}
			$htmText .= "<table>\n";
			if( strval($oneSht['title'] ?? '')!=='' ){
				$htmText .= '<caption>'.$this->escapeTxt(strval($oneSht['title']))."</caption>\n";
			}
			if( $headRow!==null ){
				$htmText .= "<thead>\n<tr>";
				foreach($headRow as $celVal){
					$htmText .= '<th>'.$this->escapeTxt($this->cellTexts($celVal)).'</th>';
				}
				$htmText .= "</tr>\n</thead>\n";
			}
			if( !empty($rowsLst) ){
				$htmText .= "<tbody>\n";
				foreach($rowsLst as $rowIdx=>$oneRow){
					$htmText .= '<tr>';
					foreach(array_values((array)$oneRow) as $colIdx=>$celVal){
						if( empty($coverLs[$rowIdx][$colIdx]) ){
							$htmText .= '<td'.strval($spanLst[$rowIdx][$colIdx] ?? '').'>'.$this->escapeTxt($this->cellTexts($celVal)).'</td>';
						}
					}
					$htmText .= "</tr>\n";
				}
				$htmText .= "</tbody>\n";
			}
			$htmText .= "</table>\n";
		}
		return $htmText;
	}

	/**
	 * Gives the value of a cell as text.
	 *
	 * @param mixed $cellValues The value.
	 * @return String Its text: true or false for a boolean, nothing for what is not a single value.
	 */
	protected function cellTexts(Mixed $cellValues): String {
		return is_bool($cellValues) ? ($cellValues ? 'true' : 'false') : (is_scalar($cellValues) ? strval($cellValues) : '');
	}

}
