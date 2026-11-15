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
	const MARK_TAGS = Array('boldFace'=>'strong', 'emphasis'=>'em', 'undrLine'=>'u', 'crossOut'=>'s', 'codeSpan'=>'code');
	// The alignments, as a style sheet writes them
	const ALGN_LIST = Array('alignLft'=>'left', 'alignRgt'=>'right', 'alignCtr'=>'center', 'alignJst'=>'justify');

	// What each style of the content gives, by its name, and whether the styles are written as classes
	protected $formatList = Array();
	protected $hasClasses = true;
	// Whether the content is written as XHTML
	protected $isXhtmlDoc = false;
	// The anchors given to the headings of the page, null when they are not written
	protected $anchorList = null;

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
	 *              with its value, and nothing an XML reader refuses;
	 *   'anchr' => gives every heading an id made of its text, as GitHub
	 *              makes the anchors of a Markdown page, so that the links
	 *              written for it lead to the same places; a heading that
	 *              has its own id keeps it.
	 *
	 * @param Array $dataEnvelope The envelope: 'shape', 'infos' and 'items'.
	 * @param Array $writeOptions Options: 'whole', 'class', 'frmat', 'heads', 'shown', 'xhtml', 'anchr'.
	 * @return String The HTML.
	 *
	 * @throws WriteError When the shape is not 'block', 'sheet' or 'nodes', or a block is of an unknown kind.
	 */
	public function renderDocument(Array $dataEnvelope, Array $writeOptions=Array()): String {
		$shapeNme = $this->checkShapeText($dataEnvelope, Array('block', 'sheet', 'nodes'), 'HTML');
		$itemList = $dataEnvelope['items'] ?? Array();
		$infoList = (array)($dataEnvelope['infos'] ?? Array());
		$this->formatList = is_array($infoList['frmat'] ?? null) ? $infoList['frmat'] : Array();
		$this->hasClasses = !isset($writeOptions['class']) || !empty($writeOptions['class']);
		$this->isXhtmlDoc = !empty($writeOptions['xhtml']);
		$this->anchorList = empty($writeOptions['anchr']) ? null : Array();
		if( $shapeNme=='block' ){
			$htmlBrut = $this->writeAllBlocks((array)$itemList);
		}elseif( $shapeNme=='sheet' ){
			$htmlBrut = $this->writeAllSheets((array)$itemList, !empty($writeOptions['heads']), !empty($writeOptions['shown']));
		}else{
			$htmlBrut = '<pre>'.$this->escapeHtmlText(strval(json_encode($itemList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR))).'</pre>'."\n";
		}
		$isAllDay = !empty($writeOptions['whole']);
		$pageHead = '';
		$pageTail = '';
		if( $isAllDay && $shapeNme=='block' ){
			// The headers and the footers of the pages of a document stand once, around its content
			$pageHead = empty($infoList['phead']) ? '' : "<header>\n".$this->writeAllBlocks((array)$infoList['phead'])."</header>\n";
			$pageTail = empty($infoList['pfoot']) ? '' : "<footer>\n".$this->writeAllBlocks((array)$infoList['pfoot'])."</footer>\n";
		}
		$cssStrng = (isset($writeOptions['frmat']) ? !empty($writeOptions['frmat']) : $isAllDay) ? $this->writeCssStyles(strpos($pageHead.$htmlBrut.$pageTail, '<hr class="break"')!==false) : '';
		$this->formatList = Array();
		$this->anchorList = null;
		if( !$isAllDay ){
			return $cssStrng.$htmlBrut;
		}
		$langAttr = preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/D', strval($infoList['langg'] ?? '')) ? ' lang="'.$infoList['langg'].'"' : '';
		if( $this->isXhtmlDoc ){
			// An XML reader is told what the page is written in, and in what language, its own way
			$langAttr = ' xmlns="http://www.w3.org/1999/xhtml"'.$langAttr.str_replace(' lang=', ' xml:lang=', $langAttr);
			return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE html>\n<html".$langAttr.">\n<head>\n<meta charset=\"utf-8\"/>\n<title>".$this->escapeHtmlText(strval($infoList['title'] ?? ''))."</title>\n".$cssStrng."</head>\n<body>\n".$pageHead.$htmlBrut.$pageTail."</body>\n</html>\n";
		}
		return "<!DOCTYPE html>\n<html".$langAttr.">\n<head>\n<meta charset=\"utf-8\">\n<title>".$this->escapeHtmlText(strval($infoList['title'] ?? ''))."</title>\n".$cssStrng."</head>\n<body>\n".$pageHead.$htmlBrut.$pageTail."</body>\n</html>\n";
	}

	/**
	 * Escapes a text for HTML, or for XHTML, in content or in an attribute.
	 *
	 * @param String $templateText The text.
	 * @return String The text, safe to write.
	 */
	protected function escapeHtmlText(String $templateText): String {
		// XHTML is XML: what XML cannot hold is left out, as for any part of a document made of it
		if( $this->isXhtmlDoc ){
			return parent::escapeXmlValue($templateText);
		}
		return htmlspecialchars($templateText, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}

	/**
	 * Gives the end of an element that holds nothing: closed in XHTML,
	 * as XML asks, left open in HTML.
	 *
	 * @return String The end of the element.
	 */
	protected function shutVoidString(): String {
		return $this->isXhtmlDoc ? '/>' : '>';
	}

	/**
	 * Gives the class a style is written as: the one the content tells
	 * for it, when it is fit for a style sheet, or one made of its name.
	 *
	 * @param String $styleNameTxt Name of the style.
	 * @return String The class, '' when the name gives none.
	 */
	protected function clssOfItString(String $styleNameTxt): String {
		$toldClss = strval($this->formatList[$styleNameTxt]['class'] ?? '');
		if( preg_match('/^[a-z][a-z0-9\-]*$/D', $toldClss) ){
			return $toldClss;
		}
		$madeClss = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($styleNameTxt)), '-');
		return ($madeClss==='') ? '' : (preg_match('/^[a-z]/', $madeClss) ? $madeClss : 's-'.$madeClss);
	}

	/**
	 * Writes the style sheet of the styles the content tells of. Every
	 * value is checked before it is written: a font is a plain name, a
	 * size a number, a colour six digits.
	 *
	 * @param Bool $hasPageBreak Whether the content holds page breaks.
	 * @return String The style element, '' when there is nothing to write in it.
	 */
	protected function writeCssStyles(Bool $hasPageBreak): String {
		$ruleList = Array();
		foreach($this->formatList as $oneNme=>$stlSet){
			$styleCls = $this->clssOfItString(strval($oneNme));
			$propList = Array();
			if( !is_array($stlSet) || $styleCls==='' ){
				continue;
			}
			if( preg_match('/^[\p{L}\p{N} \-_.]{1,80}$/Du', strval($stlSet['fonts'] ?? '')) ){
				$propList[] = 'font-family:"'.$stlSet['fonts'].'"';
			}
			if( is_numeric($stlSet['sizes'] ?? '') && $stlSet['sizes']>0 && $stlSet['sizes']<1000 ){
				$propList[] = 'font-size:'.floatval($stlSet['sizes']).'pt';
			}
			if( preg_match('/^#[0-9A-Fa-f]{6}$/D', strval($stlSet['color'] ?? '')) ){
				$propList[] = 'color:'.$stlSet['color'];
			}
			$markList = (array)($stlSet['marks'] ?? Array());
			if( in_array('boldFace', $markList) ){
				$propList[] = 'font-weight:bold';
			}
			if( in_array('emphasis', $markList) ){
				$propList[] = 'font-style:italic';
			}
			if( in_array('undrLine', $markList) || in_array('crossOut', $markList) ){
				$propList[] = 'text-decoration:'.trim((in_array('undrLine', $markList) ? 'underline ' : '').(in_array('crossOut', $markList) ? 'line-through' : ''));
			}
			if( isset(self::ALGN_LIST[strval($stlSet['align'] ?? '')]) ){
				$propList[] = 'text-align:'.self::ALGN_LIST[$stlSet['align']];
			}
			if( !empty($propList) ){
				$ruleList[] = '.'.$styleCls.'{'.implode(';', $propList).'}';
			}
		}
		// A page break shows as nothing on a screen, and starts a new page on paper
		if( $hasPageBreak ){
			$ruleList[] = 'hr.break{border:0;break-after:page;page-break-after:always}';
		}
		return empty($ruleList) ? '' : "<style>\n".implode("\n", $ruleList)."\n</style>\n";
	}

	/**
	 * Writes what is particular to a block as attributes: its id, the
	 * class of its style, and its alignment when it has one of its own.
	 *
	 * @param Array $contentBlock The block.
	 * @return String The attributes, each with its leading space, or ''.
	 */
	protected function blockAttribute(Array $contentBlock): String {
		$attrDict = (array)($contentBlock['attrs'] ?? Array());
		$tagIdent = strval($attrDict['tagid'] ?? '');
		$htmlAttr = preg_match('/^[\w\-:.]+$/D', $tagIdent) ? ' id="'.$this->escapeHtmlText($tagIdent).'"' : '';
		$styleCls = ($this->hasClasses && strval($attrDict['style'] ?? '')!=='') ? $this->clssOfItString(strval($attrDict['style'])) : '';
		if( $styleCls!=='' ){
			$htmlAttr .= ' class="'.$styleCls.'"';
		}
		if( isset(self::ALGN_LIST[strval($attrDict['align'] ?? '')]) ){
			$htmlAttr .= ' style="text-align:'.self::ALGN_LIST[$attrDict['align']].'"';
		}
		return $htmlAttr;
	}

	/**
	 * Gives the anchor of a heading as GitHub makes it for a Markdown page:
	 * its text in lower case, without what is not a letter, a digit, a
	 * space, a dash or an underscore, its spaces as dashes. A text already
	 * given in the page is followed by -1, -2, and so on.
	 *
	 * @param String $plainHeading The text of the heading, without its formatting.
	 * @return String The anchor, unique in the page.
	 */
	protected function slugHeadingTxt(String $plainHeading): String {
		$slugText = str_replace(' ', '-', strval(preg_replace('/[^\p{L}\p{N}\- _]/u', '', mb_strtolower(trim($plainHeading)))));
		$fullSlug = $slugText;
		$sameNumb = 0;
		while( in_array($fullSlug, (array)$this->anchorList, true) ){
			$sameNumb++;
			$fullSlug = $slugText.'-'.$sameNumb;
		}
		$this->anchorList[] = $fullSlug;
		return $fullSlug;
	}

	/**
	 * Writes spans: each text escaped, inside the elements of its
	 * formatting and of its link, its line breaks as br elements. A
	 * field tells what it stands for, so that it can be found and
	 * replaced; the reference to a note is raised.
	 *
	 * @param Array $textSpanList The spans.
	 * @return String The HTML.
	 */
	protected function writeInlineTxt(Array $textSpanList): String {
		$htmlText = '';
		foreach($textSpanList as $oneSpn){
			$spanHtml = str_replace("\n", '<br'.$this->shutVoidString()."\n", $this->escapeHtmlText(strval($oneSpn['texts'] ?? '')));
			foreach(array_reverse(self::MARK_TAGS) as $mrkKey=>$mrkTag){
				if( in_array($mrkKey, (array)($oneSpn['marks'] ?? Array())) ){
					$spanHtml = '<'.$mrkTag.'>'.$spanHtml.'</'.$mrkTag.'>';
				}
			}
			if( strval($oneSpn['field'] ?? '')!=='' ){
				$spanHtml = '<span data-field="'.$this->escapeHtmlText(strval($oneSpn['field'])).'">'.$spanHtml.'</span>';
			}
			$linkAddr = Shape::tidyLinkString(strval($oneSpn['links'] ?? ''));
			if( $linkAddr!=='' ){
				$spanHtml = '<a href="'.$this->escapeHtmlText($linkAddr).'">'.$spanHtml.'</a>';
			}
			// The reference to a note, in this page or in another
			if( preg_match('/^[^#]*#[fe]note\d+$/D', $linkAddr) ){
				$spanHtml = '<sup>'.$spanHtml.'</sup>';
			}
			$htmlText .= $spanHtml;
		}
		return $htmlText;
	}

	/**
	 * Writes what an entry, a cell, a quote or a note holds: its own
	 * text, then the blocks it contains.
	 *
	 * @param Array $contentBlock The block.
	 * @return String The HTML.
	 */
	protected function innerOutString(Array $contentBlock): String {
		$ownCrude = $this->writeInlineTxt($contentBlock['spans']);
		if( empty($contentBlock['items']) ){
			return $ownCrude;
		}
		return $ownCrude."\n".$this->writeAllBlocks($contentBlock['items']);
	}

	/**
	 * Writes a list of blocks, one after the other.
	 *
	 * @param Array $bookBlockSet The blocks.
	 * @return String The HTML.
	 *
	 * @throws WriteError When a block is of an unknown kind.
	 */
	protected function writeAllBlocks(Array $bookBlockSet): String {
		$htmlText = '';
		foreach($bookBlockSet as $oneBlk){
			$blockCod = strval($oneBlk['block'] ?? '');
			$blockAtr = $this->blockAttribute($oneBlk);
			if( $blockCod=='headline' ){
				$headLevl = min(6, max(1, intval($oneBlk['attrs']['level'] ?? 1)));
				if( $this->anchorList!==null && strpos($blockAtr, ' id="')===false ){
					$blockAtr = ' id="'.$this->escapeHtmlText($this->slugHeadingTxt(strval($oneBlk['plain'] ?? ''))).'"'.$blockAtr;
				}
				$htmlText .= '<h'.$headLevl.$blockAtr.'>'.$this->writeInlineTxt($oneBlk['spans']).'</h'.$headLevl.">\n";
			}elseif( $blockCod=='paragrph' ){
				$htmlText .= '<p'.$blockAtr.'>'.$this->writeInlineTxt($oneBlk['spans'])."</p>\n";
			}elseif( $blockCod=='itemList' ){
				$lstngTag = empty($oneBlk['attrs']['order']) ? 'ul' : 'ol';
				$htmlText .= '<'.$lstngTag.$blockAtr.">\n";
				foreach($oneBlk['items'] as $entBlk){
					// A task shows the box that tells whether it is done
					$taskHtml = isset($entBlk['attrs']['check']) ? '<input type="checkbox" disabled'.($this->isXhtmlDoc ? '="disabled"' : '').(empty($entBlk['attrs']['check']) ? '' : ' checked'.($this->isXhtmlDoc ? '="checked"' : '')).$this->shutVoidString().' ' : '';
					$htmlText .= '<li'.$this->blockAttribute($entBlk).'>'.$taskHtml.$this->innerOutString($entBlk)."</li>\n";
				}
				$htmlText .= '</'.$lstngTag.">\n";
			}elseif( $blockCod=='tableSet' ){
				$htmlText .= '<table'.$blockAtr.">\n".$this->tableRowOutput($oneBlk['items'])."</table>\n";
			}elseif( $blockCod=='footnote' ){
				$noteNumb = intval($oneBlk['attrs']['numbr'] ?? 0);
				$htmlText .= '<aside'.$blockAtr.'>'.(($noteNumb>0) ? '<sup>'.$noteNumb.'</sup> ' : '').$this->innerOutString($oneBlk)."</aside>\n";
			}elseif( $blockCod=='citation' ){
				// Who is quoted is told under the quotation
				$quoteHtm = $this->innerOutString($oneBlk);
				$citeText = strval($oneBlk['attrs']['cites'] ?? '');
				if( $citeText!=='' ){
					$quoteHtm .= ((substr($quoteHtm, -1)=="\n") ? '' : "\n").'<cite>'.$this->escapeHtmlText($citeText)."</cite>\n";
				}
				$htmlText .= '<blockquote'.$blockAtr.'>'.$quoteHtm."</blockquote>\n";
			}elseif( $blockCod=='codeBlck' ){
				$codeLang = strval($oneBlk['attrs']['langg'] ?? '');
				$htmlText .= '<pre'.$blockAtr.'><code'.(preg_match('/^[\w+#.\-]+$/D', $codeLang) ? ' class="language-'.$this->escapeHtmlText($codeLang).'"' : '').'>'.$this->escapeHtmlText($oneBlk['plain'])."</code></pre>\n";
			}elseif( $blockCod=='imageBox' ){
				$imageLnk = Shape::tidyLinkString(strval($oneBlk['attrs']['sourc'] ?? ''));
				if( $imageLnk!=='' ){
					$htmlText .= '<img'.$blockAtr.' src="'.$this->escapeHtmlText($imageLnk).'" alt="'.$this->escapeHtmlText($oneBlk['plain']).'"'.$this->shutVoidString()."\n";
				}
			}elseif( $blockCod=='ruleLine' ){
				$htmlText .= (empty($oneBlk['attrs']['break']) ? '<hr'.$blockAtr : '<hr class="break"').$this->shutVoidString()."\n";
			}elseif( in_array($blockCod, Array('listItem', 'cellText')) ){
				// An entry or a cell met outside its list or its table: what it holds
				$htmlText .= $this->innerOutString($oneBlk)."\n";
			}elseif( in_array($blockCod, Array('tableTop', 'tableRow')) ){
				$htmlText .= "<table>\n".$this->tableRowOutput(Array($oneBlk))."</table>\n";
			}else{
				throw new WriteError('Unknown kind of block: '.$blockCod);
			}
		}
		return $htmlText;
	}

	/**
	 * Writes the rows of a table: the header rows in a thead element,
	 * the others in a tbody.
	 *
	 * @param Array $rowBlockList The rows, as 'tableTop' and 'tableRow' blocks.
	 * @return String The HTML.
	 */
	protected function tableRowOutput(Array $rowBlockList): String {
		$partHtml = Array('thead'=>'', 'tbody'=>'');
		foreach($rowBlockList as $oneRow){
			$isTopRow = (strval($oneRow['block'] ?? '')=='tableTop');
			$ntryHtml = '<tr>';
			foreach($oneRow['items'] as $celBlk){
				$celdaTag = ($isTopRow || !empty($celBlk['attrs']['headr'])) ? 'th' : 'td';
				$spanAttr = '';
				foreach(Array('colsp'=>'colspan', 'rowsp'=>'rowspan') as $atrKey=>$atrNme){
					if( intval($celBlk['attrs'][$atrKey] ?? 1)>1 ){
						$spanAttr .= ' '.$atrNme.'="'.intval($celBlk['attrs'][$atrKey]).'"';
					}
				}
				$ntryHtml .= '<'.$celdaTag.$this->blockAttribute($celBlk).$spanAttr.'>'.$this->innerOutString($celBlk).'</'.$celdaTag.'>';
			}
			$partHtml[$isTopRow ? 'thead' : 'tbody'] .= $ntryHtml."</tr>\n";
		}
		return (($partHtml['thead']!=='') ? "<thead>\n".$partHtml['thead']."</thead>\n" : '').(($partHtml['tbody']!=='') ? "<tbody>\n".$partHtml['tbody']."</tbody>\n" : '');
	}

	/**
	 * Writes sheets, each as a table with its title as caption. The rows
	 * keyed by the names of their columns have those names as header;
	 * merged cells are written as one cell over the others.
	 *
	 * @param Array $sheetEntries The sheets, each with its 'title', its 'attrs' and its 'cells'.
	 * @param Bool  $initialHeads Whether the first row of each sheet is its header.
	 * @param Bool  $shownOnlyFlg Whether the sheets their workbook hides are left out.
	 * @return String The HTML.
	 */
	protected function writeAllSheets(Array $sheetEntries, Bool $initialHeads, Bool $shownOnlyFlg): String {
		$htmlText = '';
		foreach($sheetEntries as $oneSht){
			if( $shownOnlyFlg && isset($oneSht['attrs']['shown']) && $oneSht['attrs']['shown']===false ){
				continue;
			}
			$rowsList = array_values((array)($oneSht['cells'] ?? Array()));
			// Rows keyed by names had their header taken off when they were read: it is written back, from those names
			$firstRow = (array)($rowsList[0] ?? Array());
			$isByKeys = (!empty($firstRow) && array_keys($firstRow)!==range(0, count($firstRow)-1));
			$headNtry = $isByKeys ? array_map('strval', array_keys($firstRow)) : null;
			if( !$isByKeys && $initialHeads && !empty($rowsList) ){
				$headNtry = array_values((array)array_shift($rowsList));
			}
			// The merged cells, by the place of their first cell in the rows of the body, and the cells each covers
			$spanList = Array();
			$coverLst = Array();
			foreach((array)($oneSht['attrs']['merge'] ?? Array()) as $oneMrg){
				$mrgdNtry = intval($oneMrg['atrow'] ?? 0)-(($headNtry!==null) ? 1 : 0);
				$spanAttr = intval($oneMrg['atcol'] ?? 0);
				$rowRange = max(1, intval($oneMrg['rowsp'] ?? 1));
				$clmnSpan = max(1, intval($oneMrg['colsp'] ?? 1));
				// An area starting in the header is left as it is; one running past the last row stops there
				if( $mrgdNtry<0 || !isset($rowsList[$mrgdNtry]) ){
					continue;
				}
				$rowRange = min($rowRange, count($rowsList)-$mrgdNtry);
				$spanList[$mrgdNtry][$spanAttr] = (($clmnSpan>1) ? ' colspan="'.$clmnSpan.'"' : '').(($rowRange>1) ? ' rowspan="'.$rowRange.'"' : '');
				for($rowIdx=$mrgdNtry; $rowIdx<$mrgdNtry+$rowRange; $rowIdx++){
					for($colInc=$spanAttr; $colInc<$spanAttr+$clmnSpan && $colInc<$spanAttr+16384; $colInc++){
						$coverLst[$rowIdx][$colInc] = ($rowIdx!=$mrgdNtry || $colInc!=$spanAttr);
					}
				}
			}
			$htmlText .= "<table>\n";
			if( strval($oneSht['title'] ?? '')!=='' ){
				$htmlText .= '<caption>'.$this->escapeHtmlText(strval($oneSht['title']))."</caption>\n";
			}
			if( $headNtry!==null ){
				$htmlText .= "<thead>\n<tr>";
				foreach($headNtry as $oneVal){
					$htmlText .= '<th>'.$this->escapeHtmlText($this->writeCellValue($oneVal)).'</th>';
				}
				$htmlText .= "</tr>\n</thead>\n";
			}
			if( !empty($rowsList) ){
				$htmlText .= "<tbody>\n";
				foreach($rowsList as $rowIdx=>$oneRow){
					$htmlText .= '<tr>';
					foreach(array_values((array)$oneRow) as $colInc=>$oneVal){
						if( empty($coverLst[$rowIdx][$colInc]) ){
							$htmlText .= '<td'.strval($spanList[$rowIdx][$colInc] ?? '').'>'.$this->escapeHtmlText($this->writeCellValue($oneVal)).'</td>';
						}
					}
					$htmlText .= "</tr>\n";
				}
				$htmlText .= "</tbody>\n";
			}
			$htmlText .= "</table>\n";
		}
		return $htmlText;
	}

	/**
	 * Gives the value of a cell as text.
	 *
	 * @param mixed $cellContents The value.
	 * @return String Its text: true or false for a boolean, nothing for what is not a single value.
	 */
	protected function writeCellValue(Mixed $cellContents): String {
		return is_bool($cellContents) ? ($cellContents ? 'true' : 'false') : (is_scalar($cellContents) ? strval($cellContents) : '');
	}

}
