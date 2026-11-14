<?php

namespace nyu\Data\Parsers;

use nyu\Data\Shape;

/**
 * XML and HTML documents.
 *
 * Read as a tree (shape 'nodes'), every element has the same five keys,
 * in the order of the document:
 *
 * - 'tagnm' => the name of the element, with its prefix when it has one;
 * - 'tagid' => its id (the 'id' attribute, or 'xml:id'), '' when it has none;
 * - 'attrs' => its attributes, name => value; in XML, the addresses it
 *              gives to the prefixes of names (xmlns, xmlns:x) among them;
 * - 'value' => its text, when it holds text only;
 * - 'nodes' => its child elements.
 *
 * An element mixing text and elements keeps their order: its pieces of
 * text stand among its 'nodes' under the name '#text', and its 'value'
 * is empty. Comments and processing instructions are left out.
 *
 * Read as text blocks (shape 'block'), the document gives what it says,
 * as the blocks of nyu\Data\Shape: the elements that only group others
 * (div, section, ...) disappear, scripts, styles and forms are left out.
 *
 * An XML document is read as a tree and an HTML one as text blocks,
 * unless the 'shape' option asks otherwise.
 */
class XHml extends Base {

	// HTML elements whose content is not part of the text
	const SKIP_LIST = Array('script', 'style', 'head', 'title', 'meta', 'link', 'noscript', 'template', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'canvas', 'audio', 'video', 'map');
	// HTML elements that start a block, or hold blocks
	const BLCK_LIST = Array(
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'blockquote', 'pre', 'hr', 'img',
		'html', 'body', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav', 'figure', 'figcaption', 'dl', 'dt', 'dd', 'details', 'summary', 'address', 'fieldset', 'center'
	);
	// Formatting given by the inline HTML elements
	const MARK_TAGS = Array('b'=>'boldFace', 'strong'=>'boldFace', 'i'=>'emphasis', 'em'=>'emphasis', 'u'=>'undrLine', 'ins'=>'undrLine', 's'=>'crossOut', 'strike'=>'crossOut', 'del'=>'crossOut', 'code'=>'codeSpan', 'kbd'=>'codeSpan', 'samp'=>'codeSpan', 'tt'=>'codeSpan');
	// Stands for a line break while the spacing of a text is being tidied
	const LINE_MARK = "\x1E";

	/**
	 * Parses an XML or HTML document. An empty content gives an empty
	 * result. The text is always given in UTF-8: an encoding the document
	 * declares is followed, and a document that declares none and is not
	 * UTF-8 is read as Windows-1252.
	 *
	 * Options:
	 *   'reads' => 'xml' or 'html': what the content is; told from its
	 *              first bytes when left out, XML being the default;
	 *   'shape' => 'nodes' for the tree of elements, 'block' for the text
	 *              blocks; XML gives the tree and HTML the blocks when
	 *              left out;
	 *   'short' => for a tree, gives the compact form instead: root name
	 *              => content, attributes under '@name', an element's own
	 *              text under '#text', and the elements of one name
	 *              always as a list;
	 *   'depth' => deepest nesting accepted, 256 by default;
	 *   'space' => for a tree, keeps the spacing written between two
	 *              elements as '#text' nodes, for the documents where a
	 *              space between two pieces of text counts.
	 *
	 * @param String $nakedContent The document.
	 * @param Array  $parseOptions Options: 'reads', 'shape', 'short', 'depth', 'space'.
	 * @return Array The envelope, of shape 'nodes' or 'block'; 'infos' holds what the content was read as under 'reads', the encoding it declared under 'encod', for HTML the title of the page under 'title', and for an XML document that tells its kind, the name of that kind, its public name and where its rules are told under 'dtype' ('names', 'publc', 'systm').
	 *
	 * @throws ParseError When an XML content is not well-formed or declares entities, or the document is nested too deep.
	 */
	public function parseDataArray(String $nakedContent, Array $parseOptions=Array()): Array {
		$readCode = strtolower(strval($parseOptions['reads'] ?? ''));
		if( $readCode!='html' && $readCode!='xml' ){
			$readCode = (Bina::detectFileKind(substr($nakedContent, 0, 512))=='html') ? 'html' : 'xml';
		}
		$shapeNme = strtolower(strval($parseOptions['shape'] ?? ''));
		if( $shapeNme!='nodes' && $shapeNme!='block' ){
			$shapeNme = ($readCode=='html') ? 'block' : 'nodes';
		}
		$infoList = Array('reads'=>$readCode, 'encod'=>'UTF-8');
		if( $readCode=='html' ){
			$infoList['title'] = '';
		}
		if( trim($nakedContent)==='' ){
			return $this->makeShapeArray($shapeNme, Array(), $infoList);
		}
		$encoding = 'UTF-8';
		$domDcmnt = ($readCode=='html') ? $this->loadHtmlSource($nakedContent, $encoding) : $this->loadXmlContent($nakedContent, $encoding);
		$infoList['encod'] = $encoding;
		// The kind of document an XML content says it is, and where the rules of that kind are told
		if( $readCode=='xml' && $domDcmnt->doctype!==null ){
			$infoList['dtype'] = Array('names'=>strval($domDcmnt->doctype->name), 'publc'=>strval($domDcmnt->doctype->publicId), 'systm'=>strval($domDcmnt->doctype->systemId));
		}
		if( $domDcmnt->documentElement===null ){
			return $this->makeShapeArray($shapeNme, Array(), $infoList);
		}
		$rootList = $this->elementToArray($domDcmnt->documentElement, max(1, intval($parseOptions['depth'] ?? 256)), $readCode=='html', !empty($parseOptions['space']));
		if( $readCode=='html' ){
			$infoList['title'] = $this->locateTitleTxt($rootList);
		}
		if( $shapeNme=='block' ){
			return $this->makeShapeArray('block', $this->tagBlocksArray($rootList), $infoList);
		}
		if( !empty($parseOptions['short']) ){
			$rootList = Array($rootList['tagnm']=>$this->compactElement($rootList));
		}
		return $this->makeShapeArray('nodes', $rootList, $infoList);
	}

	/**
	 * Loads an XML document.
	 *
	 * @param String $nakedContent The document.
	 * @param String $foundCharset Receives the encoding the document declared, in upper case; 'UTF-8' when it declared none.
	 * @return \DOMDocument The loaded document.
	 *
	 * @throws ParseError When the content is not well-formed XML, or declares entities or an unknown encoding.
	 */
	protected function loadXmlContent(String $nakedContent, String &$foundCharset): \DOMDocument {
		// The text is brought to UTF-8 here, from the encoding the document declares when it
		// declares one, so that the result does not depend on what the XML reader supports
		$declRgxp = '/^(\s*<\?xml[^>]*?\sencoding\s*=\s*["\'])([\w.:\-]+)(["\'])/i';
		$declPart = Array();
		$hasBom16 = in_array(substr($nakedContent, 0, 2), Array("\xFF\xFE", "\xFE\xFF"), true);
		if( !$hasBom16 && preg_match($declRgxp, substr($nakedContent, 0, 200), $declPart) && !in_array(strtoupper($declPart[2]), Array('UTF-8', 'UTF8')) ){
			// A declared encoding is followed, whatever it is
			$xmlStrng = @iconv(strtoupper($declPart[2]), 'UTF-8//IGNORE', $nakedContent);
			if( $xmlStrng===false ){
				throw new ParseError('Invalid XML content: unknown encoding '.strtoupper($declPart[2]));
			}
		}else{
			// A marker at the start, UTF-8, or nothing declared: the common rules apply
			$xmlStrng = self::recodeTextUtf8($nakedContent);
		}
		if( preg_match($declRgxp, substr($xmlStrng, 0, 200), $declPart) ){
			$foundCharset = strtoupper($declPart[2]);
			$xmlStrng = preg_replace($declRgxp, '${1}UTF-8${3}', $xmlStrng, 1);
		}
		// Declared entities are how an XML document reads local files or grows without end.
		// They are looked for in the text once decoded : another encoding would hide them
		if( stripos($xmlStrng, '<!ENTITY')!==false ){
			throw new ParseError('Invalid XML content: entity declarations are not accepted');
		}
		$domDcmnt = new \DOMDocument();
		$oldCodig = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$loadOkay = $domDcmnt->loadXML($xmlStrng, LIBXML_NONET | LIBXML_COMPACT);
		$errorLst = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($oldCodig);
		if( !$loadOkay || $domDcmnt->documentElement===null ){
			$firstErr = $errorLst[0] ?? null;
			throw new ParseError('Invalid XML content: '.($firstErr ? trim($firstErr->message).' on line '.$firstErr->line : 'not well-formed'));
		}
		// Whatever the text looked like, a document that declares entities once read is refused
		if( $domDcmnt->doctype!==null && $domDcmnt->doctype->entities!==null && $domDcmnt->doctype->entities->length>0 ){
			throw new ParseError('Invalid XML content: entity declarations are not accepted');
		}
		return $domDcmnt;
	}

	/**
	 * Loads an HTML document. HTML is read as browsers read it: what is
	 * not well-formed is repaired, never refused.
	 *
	 * @param String $nakedContent The document.
	 * @param String $foundCharset Receives the encoding the page declared, in upper case; 'UTF-8' when it declared none.
	 * @return \DOMDocument The loaded document.
	 */
	protected function loadHtmlSource(String $nakedContent, String &$foundCharset): \DOMDocument {
		$metaRgxp = '/(<meta\b[^>]*?\bcharset\s*=\s*["\']?)([\w.:\-]+)/i';
		$metaPart = Array();
		$htmlText = self::recodeTextUtf8($nakedContent);
		if( preg_match($metaRgxp, substr($nakedContent, 0, 2048), $metaPart) ){
			$foundCharset = strtoupper($metaPart[2]);
			// A declared encoding is followed when the bytes are not already UTF-8
			if( !preg_match('//u', $nakedContent) && !in_array($foundCharset, Array('UTF-8', 'UTF8')) ){
				$utf8Text = @iconv($foundCharset, 'UTF-8//IGNORE', $nakedContent);
				$htmlText = ($utf8Text===false) ? $htmlText : $utf8Text;
			}
			// The text is UTF-8 from here on: the page must not tell the reader otherwise
			$htmlText = preg_replace($metaRgxp, '${1}UTF-8', $htmlText);
		}
		$domDcmnt = new \DOMDocument();
		$oldCodig = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$domDcmnt->loadHTML('<?xml encoding="UTF-8">'.$htmlText, LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($oldCodig);
		return $domDcmnt;
	}

	/**
	 * Reads an element and what it holds into its five keys.
	 *
	 * @param \DOMElement $elementGiven The element.
	 * @param Int         $depthsLftNum Levels of nesting still accepted.
	 * @param Bool        $isHtmlSource Whether the element is one of an HTML page, where a space between two pieces of text counts.
	 * @param Bool        $keepsSpacing Whether the spacing between two elements is kept, whatever they are.
	 * @return Array The element: 'tagnm', 'tagid', 'attrs', 'value', 'nodes'.
	 *
	 * @throws ParseError When the nesting goes deeper than accepted.
	 */
	protected function elementToArray(\DOMElement $elementGiven, Int $depthsLftNum, Bool $isHtmlSource=false, Bool $keepsSpacing=false): Array {
		if( $depthsLftNum<1 ){
			throw new ParseError('Invalid '.($isHtmlSource ? 'HTML' : 'XML').' content: nested deeper than allowed');
		}
		$attrDict = Array();
		// The addresses an XML element gives to the prefixes of its names are attributes of it, though not listed as such
		if( !$isHtmlSource && function_exists('simplexml_import_dom') ){
			foreach((array)simplexml_import_dom($elementGiven)->getDocNamespaces(false, false) as $nmsKey=>$nmsAdr){
				$attrDict[($nmsKey==='') ? 'xmlns' : 'xmlns:'.$nmsKey] = strval($nmsAdr);
			}
		}
		foreach($elementGiven->attributes as $atrNod){
			$attrDict[$atrNod->nodeName] = $atrNod->nodeValue;
		}
		$childLst = Array();
		$textList = Array();
		$hasNodes = false;
		$isInline = false;
		foreach($elementGiven->childNodes as $chlNod){
			if( $chlNod instanceof \DOMElement ){
				$hasNodes = true;
				$isInline = ($isInline || ($isHtmlSource && !in_array($chlNod->nodeName, self::BLCK_LIST) && !in_array($chlNod->nodeName, self::SKIP_LIST)));
				$childLst[] = $this->elementToArray($chlNod, $depthsLftNum-1, $isHtmlSource, $keepsSpacing);
			}elseif( $chlNod instanceof \DOMText ){
				// Text and CDATA; what is only spacing between elements is left out below
				$textList[] = $chlNod->nodeValue;
				$childLst[] = Array('tagnm'=>'#text', 'tagid'=>'', 'attrs'=>Array(), 'value'=>$chlNod->nodeValue, 'nodes'=>Array());
			}
		}
		$ownStrng = implode('', $textList);
		$nodeList = Array('tagnm'=>$elementGiven->nodeName, 'tagid'=>strval($attrDict['id'] ?? $attrDict['xml:id'] ?? ''), 'attrs'=>$attrDict, 'value'=>'', 'nodes'=>Array());
		if( !$hasNodes ){
			// Text only: it is the value of the element
			$nodeList['value'] = $ownStrng;
			return $nodeList;
		}
		if( trim($ownStrng)==='' && !$isInline && !$keepsSpacing ){
			// Elements only: the spacing around them is not content, unless it
			// stands between two pieces of text of an HTML page
			$childLst = array_values(array_filter($childLst, function($childrenList){ return $childrenList['tagnm']!=='#text'; }));
		}
		$nodeList['nodes'] = $childLst;
		return $nodeList;
	}

	/**
	 * Gives the compact form of an element: its text alone when it has
	 * nothing else, otherwise its attributes under '@name', its own text
	 * under '#text', and its child elements grouped by name, each name
	 * always holding a list.
	 *
	 * @param Array $elementArray The element, as elementToArray() gives it.
	 * @return mixed The text of the element, or its compact array.
	 */
	protected function compactElement(Array $elementArray): Mixed {
		if( empty($elementArray['attrs']) && empty($elementArray['nodes']) ){
			return $elementArray['value'];
		}
		$shortLst = Array();
		foreach($elementArray['attrs'] as $atrNme=>$atrVal){
			$shortLst['@'.$atrNme] = $atrVal;
		}
		$ownStrng = $elementArray['value'];
		foreach($elementArray['nodes'] as $chlArr){
			if( $chlArr['tagnm']==='#text' ){
				$ownStrng .= $chlArr['value'];
			}else{
				$shortLst[$chlArr['tagnm']][] = $this->compactElement($chlArr);
			}
		}
		if( trim($ownStrng)!=='' ){
			$shortLst['#text'] = $ownStrng;
		}
		return $shortLst;
	}

	/**
	 * Finds the title of an HTML page: the first title element of its tree.
	 *
	 * @param Array $elementArray An element of the tree.
	 * @return String The title, its spacing tidied, '' when the page has none.
	 */
	protected function locateTitleTxt(Array $elementArray): String {
		if( $elementArray['tagnm']=='title' ){
			return trim(preg_replace('/\s+/', ' ', $elementArray['value']));
		}
		foreach($elementArray['nodes'] as $chlArr){
			// The title of a page is in its head: the body, and a drawing, may hold others
			if( in_array($chlArr['tagnm'], Array('body', 'svg', '#text')) ){
				continue;
			}
			$foundTit = $this->locateTitleTxt($chlArr);
			if( $foundTit!=='' ){
				return $foundTit;
			}
		}
		return '';
	}

	/**
	 * Gives what an element holds as a list of nodes, its own text being
	 * one of them.
	 *
	 * @param Array $elementArray The element.
	 * @return Array Its child nodes, in order.
	 */
	protected function kidsOfTagArray(Array $elementArray): Array {
		if( empty($elementArray['nodes']) && $elementArray['value']!=='' ){
			return Array(Array('tagnm'=>'#text', 'tagid'=>'', 'attrs'=>Array(), 'value'=>$elementArray['value'], 'nodes'=>Array()));
		}
		return $elementArray['nodes'];
	}

	/**
	 * Tells whether an element holds a block, at any depth.
	 *
	 * @param Array $elementArray The element.
	 * @return Bool True when one of the elements inside it starts a block.
	 */
	protected function holdsBlockFlag(Array $elementArray): Bool {
		foreach($elementArray['nodes'] as $chlArr){
			if( in_array($chlArr['tagnm'], self::BLCK_LIST) || $this->holdsBlockFlag($chlArr) ){
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a run of nodes into blocks: each block element gives its
	 * blocks, and the text and inline elements between two of them
	 * become a paragraph.
	 *
	 * @param Array $childrenList The nodes, in order.
	 * @return Array The blocks.
	 */
	protected function readFlowsArray(Array $childrenList): Array {
		$blockLst = Array();
		$spanBuff = Array();
		foreach($childrenList as $chlArr){
			$tagTitle = $chlArr['tagnm'];
			if( in_array($tagTitle, self::SKIP_LIST) ){
				continue;
			}
			if( !in_array($tagTitle, self::BLCK_LIST) && !$this->holdsBlockFlag($chlArr) ){
				$spanBuff = array_merge($spanBuff, $this->inlineSpansArr($chlArr, Array(), ''));
				continue;
			}
			// A block starts: the text gathered so far is a paragraph of its own
			$tidySpan = $this->tidySpansArray($spanBuff);
			$spanBuff = Array();
			if( !empty($tidySpan) ){
				$blockLst[] = Shape::makeBlockArray('paragrph', $tidySpan);
			}
			$blockLst = array_merge($blockLst, in_array($tagTitle, self::BLCK_LIST) ? $this->tagBlocksArray($chlArr) : $this->readFlowsArray($this->kidsOfTagArray($chlArr)));
		}
		$tidySpan = $this->tidySpansArray($spanBuff);
		if( !empty($tidySpan) ){
			$blockLst[] = Shape::makeBlockArray('paragrph', $tidySpan);
		}
		return $blockLst;
	}

	/**
	 * Reads an inline node into spans, each carrying the formatting and
	 * the link of the elements around it.
	 *
	 * @param Array  $elementArray The node.
	 * @param Array  $runFormatSet Formatting given by the elements around it.
	 * @param String $linkGoalText Address given by a link around it, '' when there is none.
	 * @return Array The spans, their spacing as written.
	 */
	protected function inlineSpansArr(Array $elementArray, Array $runFormatSet, String $linkGoalText): Array {
		$tagTitle = $elementArray['tagnm'];
		if( $tagTitle=='#text' ){
			return Array(Shape::makeASpanArray($elementArray['value'], $runFormatSet, $linkGoalText));
		}
		if( $tagTitle=='br' ){
			return Array(Shape::makeASpanArray(self::LINE_MARK, $runFormatSet, $linkGoalText));
		}
		if( in_array($tagTitle, self::SKIP_LIST) ){
			return Array();
		}
		if( isset(self::MARK_TAGS[$tagTitle]) ){
			$runFormatSet[] = self::MARK_TAGS[$tagTitle];
		}
		if( $tagTitle=='a' && isset($elementArray['attrs']['href']) ){
			$linkGoalText = Shape::tidyLinkString($elementArray['attrs']['href']);
		}
		$spansLst = Array();
		foreach($this->kidsOfTagArray($elementArray) as $chlArr){
			$spansLst = array_merge($spansLst, $this->inlineSpansArr($chlArr, $runFormatSet, $linkGoalText));
		}
		return $spansLst;
	}

	/**
	 * Tidies the spans of a block the way a browser shows them: any run
	 * of spaces and line ends is one space, none is left at the start or
	 * at the end, a line break stays a line break, and two neighbours of
	 * the same formatting are one span.
	 *
	 * @param Array $textSpanList The spans, their spacing as written.
	 * @return Array The tidied spans; none when there is no text.
	 */
	protected function tidySpansArray(Array $textSpanList): Array {
		$tidyList = Array();
		foreach($textSpanList as $oneSpn){
			$spanText = preg_replace('/\s+/', ' ', $oneSpn['texts']);
			$lastIndx = count($tidyList)-1;
			$prevText = ($lastIndx>=0) ? $tidyList[$lastIndx]['texts'] : '';
			// No space at the start of the block, after a space, or after a line break
			if( $prevText==='' || in_array(substr($prevText, -1), Array(' ', self::LINE_MARK)) ){
				$spanText = ltrim($spanText, ' ');
			}
			if( $spanText==='' ){
				continue;
			}
			if( $lastIndx>=0 && $tidyList[$lastIndx]['marks']===$oneSpn['marks'] && $tidyList[$lastIndx]['links']===$oneSpn['links'] ){
				$tidyList[$lastIndx]['texts'] .= $spanText;
			}else{
				$tidyList[] = Array('texts'=>$spanText, 'marks'=>$oneSpn['marks'], 'links'=>$oneSpn['links'], 'field'=>$oneSpn['field']);
			}
		}
		// Nothing is left at the end either, and the line breaks take their real form
		while( !empty($tidyList) ){
			$lastIndx = count($tidyList)-1;
			$tidyList[$lastIndx]['texts'] = rtrim($tidyList[$lastIndx]['texts'], ' '.self::LINE_MARK);
			if( $tidyList[$lastIndx]['texts']!=='' ){
				break;
			}
			array_pop($tidyList);
		}
		foreach($tidyList as $spnIdx=>$oneSpn){
			$tidyList[$spnIdx]['texts'] = str_replace(Array(' '.self::LINE_MARK, self::LINE_MARK), "\n", $oneSpn['texts']);
		}
		return $tidyList;
	}

	/**
	 * Splits what an entry, a cell or a quote holds between its own text
	 * and the blocks it contains: a first paragraph is its text.
	 *
	 * @param Array $bookBlockSet The blocks read from it.
	 * @return Array Its spans first, then the blocks it contains.
	 */
	protected function splitHoldArray(Array $bookBlockSet): Array {
		if( !empty($bookBlockSet) && $bookBlockSet[0]['block']=='paragrph' ){
			return Array($bookBlockSet[0]['spans'], array_slice($bookBlockSet, 1));
		}
		return Array(Array(), $bookBlockSet);
	}

	/**
	 * Reads the rows of an HTML table.
	 *
	 * @param Array $elementArray The table element, or one of its row groups.
	 * @param Bool  $innerHeadFlg Whether the rows are those of a thead element.
	 * @return Array The rows, as 'tableTop' and 'tableRow' blocks.
	 */
	protected function rowsOfTableArr(Array $elementArray, Bool $innerHeadFlg=false): Array {
		$rowsList = Array();
		foreach($elementArray['nodes'] as $chlArr){
			if( in_array($chlArr['tagnm'], Array('thead', 'tbody', 'tfoot')) ){
				$rowsList = array_merge($rowsList, $this->rowsOfTableArr($chlArr, $chlArr['tagnm']=='thead'));
				continue;
			}
			if( $chlArr['tagnm']!='tr' ){
				continue;
			}
			$cellList = Array();
			$headNumb = 0;
			foreach($chlArr['nodes'] as $celArr){
				if( $celArr['tagnm']!='td' && $celArr['tagnm']!='th' ){
					continue;
				}
				$cellAttr = Array();
				if( $celArr['tagnm']=='th' ){
					$headNumb++;
					$cellAttr['headr'] = true;
				}
				foreach(Array('colspan'=>'colsp', 'rowspan'=>'rowsp') as $atrNme=>$atrKey){
					if( intval($celArr['attrs'][$atrNme] ?? 1)>1 ){
						$cellAttr[$atrKey] = intval($celArr['attrs'][$atrNme]);
					}
				}
				$cellPart = $this->splitHoldArray($this->readFlowsArray($this->kidsOfTagArray($celArr)));
				$cellList[] = Array($cellPart[0], $cellPart[1], $cellAttr);
			}
			// A row of the head, or a row made of header cells only, is a header row
			$headNtry = ($innerHeadFlg || ($headNumb>0 && $headNumb==count($cellList)));
			$cellBloc = Array();
			foreach($cellList as $celSet){
				if( $headNtry ){
					unset($celSet[2]['headr']);
				}
				$cellBloc[] = Shape::makeBlockArray('cellText', $celSet[0], $celSet[1], $celSet[2]);
			}
			$rowsList[] = Shape::makeBlockArray($headNtry ? 'tableTop' : 'tableRow', Array(), $cellBloc);
		}
		return $rowsList;
	}

	/**
	 * Gives the text of an element as it is written, spacing included.
	 *
	 * @param Array $elementArray The element.
	 * @return String Its text, with the text of what it holds.
	 */
	protected function elementRawText(Array $elementArray): String {
		if( $elementArray['tagnm']=='br' ){
			return "\n";
		}
		$rawStrng = $elementArray['value'];
		foreach($elementArray['nodes'] as $chlArr){
			$rawStrng .= $this->elementRawText($chlArr);
		}
		return $rawStrng;
	}

	/**
	 * Reads a block element into the blocks it gives: one for a heading
	 * or a list, several for an element that only groups others, none
	 * when it holds nothing.
	 *
	 * @param Array $elementArray The element.
	 * @return Array The blocks.
	 */
	protected function tagBlocksArray(Array $elementArray): Array {
		$tagTitle = $elementArray['tagnm'];
		$tgidVals = ($elementArray['tagid']!=='') ? Array('tagid'=>$elementArray['tagid']) : Array();
		$blockLst = Array();
		if( preg_match('/^h([1-6])$/D', $tagTitle, $hedrPart) ){
			$hedrSpns = $this->tidySpansArray($this->inlineSpansArr(Array('tagnm'=>'span')+$elementArray, Array(), ''));
			if( !empty($hedrSpns) ){
				$blockLst[] = Shape::makeBlockArray('headline', $hedrSpns, Array(), Array('level'=>intval($hedrPart[1])) + $tgidVals);
			}
		}elseif( $tagTitle=='ul' || $tagTitle=='ol' ){
			$itemList = Array();
			foreach($elementArray['nodes'] as $chlArr){
				if( $chlArr['tagnm']!='li' ){
					continue;
				}
				$entryPar = $this->splitHoldArray($this->readFlowsArray($this->kidsOfTagArray($chlArr)));
				$itemList[] = Shape::makeBlockArray('listItem', $entryPar[0], $entryPar[1], ($chlArr['tagid']!=='') ? Array('tagid'=>$chlArr['tagid']) : Array());
			}
			if( !empty($itemList) ){
				$blockLst[] = Shape::makeBlockArray('itemList', Array(), $itemList, Array('order'=>($tagTitle=='ol')) + $tgidVals);
			}
		}elseif( $tagTitle=='table' ){
			$rowsList = $this->rowsOfTableArr($elementArray);
			if( !empty($rowsList) ){
				$blockLst[] = Shape::makeBlockArray('tableSet', Array(), $rowsList, $tgidVals);
			}
		}elseif( $tagTitle=='blockquote' ){
			$quotePar = $this->splitHoldArray($this->readFlowsArray($this->kidsOfTagArray($elementArray)));
			if( !empty($quotePar[0]) || !empty($quotePar[1]) ){
				$blockLst[] = Shape::makeBlockArray('citation', $quotePar[0], $quotePar[1], $tgidVals);
			}
		}elseif( $tagTitle=='pre' ){
			$codeText = trim($this->elementRawText($elementArray), "\r\n");
			$langPart = Array();
			$codeAttr = $tgidVals;
			if( preg_match('/\blang(?:uage)?-([\w+#.\-]+)/', strval($elementArray['nodes'][0]['attrs']['class'] ?? $elementArray['attrs']['class'] ?? ''), $langPart) ){
				$codeAttr = Array('langg'=>$langPart[1]) + $codeAttr;
			}
			if( $codeText!=='' ){
				$blockLst[] = Shape::makeBlockArray('codeBlck', Array(Shape::makeASpanArray($codeText)), Array(), $codeAttr);
			}
		}elseif( $tagTitle=='hr' ){
			$blockLst[] = Shape::makeBlockArray('ruleLine', Array(), Array(), $tgidVals);
		}elseif( $tagTitle=='img' ){
			$imageLnk = Shape::tidyLinkString(strval($elementArray['attrs']['src'] ?? ''));
			$imageTxt = trim(strval($elementArray['attrs']['alt'] ?? ''));
			if( $imageLnk!=='' ){
				$blockLst[] = Shape::makeBlockArray('imageBox', ($imageTxt!=='') ? Array(Shape::makeASpanArray($imageTxt)) : Array(), Array(), Array('sourc'=>$imageLnk) + $tgidVals);
			}
		}else{
			// A paragraph, or an element that only groups others: what it holds, in order
			$blockLst = $this->readFlowsArray($this->kidsOfTagArray($elementArray));
			if( !empty($blockLst) && !empty($tgidVals) && !isset($blockLst[0]['attrs']['tagid']) && $tagTitle=='p' ){
				$blockLst[0]['attrs'] = $blockLst[0]['attrs'] + $tgidVals;
			}
		}
		return $blockLst;
	}

}
