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
		$readsAs = strtolower(strval($parseOptions['reads'] ?? ''));
		if( $readsAs!='html' && $readsAs!='xml' ){
			$readsAs = (Bina::detectFileKind(substr($nakedContent, 0, 512))=='html') ? 'html' : 'xml';
		}
		$shapeAs = strtolower(strval($parseOptions['shape'] ?? ''));
		if( $shapeAs!='nodes' && $shapeAs!='block' ){
			$shapeAs = ($readsAs=='html') ? 'block' : 'nodes';
		}
		$infoArr = Array('reads'=>$readsAs, 'encod'=>'UTF-8');
		if( $readsAs=='html' ){
			$infoArr['title'] = '';
		}
		if( trim($nakedContent)==='' ){
			return $this->makeShapeArray($shapeAs, Array(), $infoArr);
		}
		$charset = 'UTF-8';
		$domTree = ($readsAs=='html') ? $this->loadHtmlSource($nakedContent, $charset) : $this->loadXmlContent($nakedContent, $charset);
		$infoArr['encod'] = $charset;
		// The kind of document an XML content says it is, and where the rules of that kind are told
		if( $readsAs=='xml' && $domTree->doctype!==null ){
			$infoArr['dtype'] = Array('names'=>strval($domTree->doctype->name), 'publc'=>strval($domTree->doctype->publicId), 'systm'=>strval($domTree->doctype->systemId));
		}
		if( $domTree->documentElement===null ){
			return $this->makeShapeArray($shapeAs, Array(), $infoArr);
		}
		$rootArr = $this->elementToArray($domTree->documentElement, max(1, intval($parseOptions['depth'] ?? 256)), $readsAs=='html', !empty($parseOptions['space']));
		if( $readsAs=='html' ){
			$infoArr['title'] = $this->locateTitleTxt($rootArr);
		}
		if( $shapeAs=='block' ){
			return $this->makeShapeArray('block', $this->tagBlocksArray($rootArr), $infoArr);
		}
		if( !empty($parseOptions['short']) ){
			$rootArr = Array($rootArr['tagnm']=>$this->compactElement($rootArr));
		}
		return $this->makeShapeArray('nodes', $rootArr, $infoArr);
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
		$declRgx = '/^(\s*<\?xml[^>]*?\sencoding\s*=\s*["\'])([\w.:\-]+)(["\'])/i';
		$declPrt = Array();
		$utf16On = in_array(substr($nakedContent, 0, 2), Array("\xFF\xFE", "\xFE\xFF"), true);
		if( !$utf16On && preg_match($declRgx, substr($nakedContent, 0, 200), $declPrt) && !in_array(strtoupper($declPrt[2]), Array('UTF-8', 'UTF8')) ){
			// A declared encoding is followed, whatever it is
			$xmlText = @iconv(strtoupper($declPrt[2]), 'UTF-8//IGNORE', $nakedContent);
			if( $xmlText===false ){
				throw new ParseError('Invalid XML content: unknown encoding '.strtoupper($declPrt[2]));
			}
		}else{
			// A marker at the start, UTF-8, or nothing declared: the common rules apply
			$xmlText = self::recodeTextUtf8($nakedContent);
		}
		if( preg_match($declRgx, substr($xmlText, 0, 200), $declPrt) ){
			$foundCharset = strtoupper($declPrt[2]);
			$xmlText = preg_replace($declRgx, '${1}UTF-8${3}', $xmlText, 1);
		}
		// Declared entities are how an XML document reads local files or grows without end.
		// They are looked for in the text once decoded : another encoding would hide them
		if( stripos($xmlText, '<!ENTITY')!==false ){
			throw new ParseError('Invalid XML content: entity declarations are not accepted');
		}
		$domTree = new \DOMDocument();
		$oldMode = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$lodOkay = $domTree->loadXML($xmlText, LIBXML_NONET | LIBXML_COMPACT);
		$errList = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($oldMode);
		if( !$lodOkay || $domTree->documentElement===null ){
			$frstErr = $errList[0] ?? null;
			throw new ParseError('Invalid XML content: '.($frstErr ? trim($frstErr->message).' on line '.$frstErr->line : 'not well-formed'));
		}
		// Whatever the text looked like, a document that declares entities once read is refused
		if( $domTree->doctype!==null && $domTree->doctype->entities!==null && $domTree->doctype->entities->length>0 ){
			throw new ParseError('Invalid XML content: entity declarations are not accepted');
		}
		return $domTree;
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
		$metaRgx = '/(<meta\b[^>]*?\bcharset\s*=\s*["\']?)([\w.:\-]+)/i';
		$metPart = Array();
		$htmText = self::recodeTextUtf8($nakedContent);
		if( preg_match($metaRgx, substr($nakedContent, 0, 2048), $metPart) ){
			$foundCharset = strtoupper($metPart[2]);
			// A declared encoding is followed when the bytes are not already UTF-8
			if( !preg_match('//u', $nakedContent) && !in_array($foundCharset, Array('UTF-8', 'UTF8')) ){
				$utf8Txt = @iconv($foundCharset, 'UTF-8//IGNORE', $nakedContent);
				$htmText = ($utf8Txt===false) ? $htmText : $utf8Txt;
			}
			// The text is UTF-8 from here on: the page must not tell the reader otherwise
			$htmText = preg_replace($metaRgx, '${1}UTF-8', $htmText);
		}
		$domTree = new \DOMDocument();
		$oldMode = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$domTree->loadHTML('<?xml encoding="UTF-8">'.$htmText, LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($oldMode);
		return $domTree;
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
		$attrSet = Array();
		// The addresses an XML element gives to the prefixes of its names are attributes of it, though not listed as such
		if( !$isHtmlSource && function_exists('simplexml_import_dom') ){
			foreach((array)simplexml_import_dom($elementGiven)->getDocNamespaces(false, false) as $nmsKey=>$nmsAdr){
				$attrSet[($nmsKey==='') ? 'xmlns' : 'xmlns:'.$nmsKey] = strval($nmsAdr);
			}
		}
		foreach($elementGiven->attributes as $atrNod){
			$attrSet[$atrNod->nodeName] = $atrNod->nodeValue;
		}
		$chldLst = Array();
		$textLst = Array();
		$hasNode = false;
		$hasInln = false;
		foreach($elementGiven->childNodes as $chlNod){
			if( $chlNod instanceof \DOMElement ){
				$hasNode = true;
				$hasInln = ($hasInln || ($isHtmlSource && !in_array($chlNod->nodeName, self::BLCK_LIST) && !in_array($chlNod->nodeName, self::SKIP_LIST)));
				$chldLst[] = $this->elementToArray($chlNod, $depthsLftNum-1, $isHtmlSource, $keepsSpacing);
			}elseif( $chlNod instanceof \DOMText ){
				// Text and CDATA; what is only spacing between elements is left out below
				$textLst[] = $chlNod->nodeValue;
				$chldLst[] = Array('tagnm'=>'#text', 'tagid'=>'', 'attrs'=>Array(), 'value'=>$chlNod->nodeValue, 'nodes'=>Array());
			}
		}
		$ownText = implode('', $textLst);
		$nodeArr = Array('tagnm'=>$elementGiven->nodeName, 'tagid'=>strval($attrSet['id'] ?? $attrSet['xml:id'] ?? ''), 'attrs'=>$attrSet, 'value'=>'', 'nodes'=>Array());
		if( !$hasNode ){
			// Text only: it is the value of the element
			$nodeArr['value'] = $ownText;
			return $nodeArr;
		}
		if( trim($ownText)==='' && !$hasInln && !$keepsSpacing ){
			// Elements only: the spacing around them is not content, unless it
			// stands between two pieces of text of an HTML page
			$chldLst = array_values(array_filter($chldLst, function($childrenList){ return $childrenList['tagnm']!=='#text'; }));
		}
		$nodeArr['nodes'] = $chldLst;
		return $nodeArr;
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
		$shrtArr = Array();
		foreach($elementArray['attrs'] as $atrNme=>$atrVal){
			$shrtArr['@'.$atrNme] = $atrVal;
		}
		$ownText = $elementArray['value'];
		foreach($elementArray['nodes'] as $chlArr){
			if( $chlArr['tagnm']==='#text' ){
				$ownText .= $chlArr['value'];
			}else{
				$shrtArr[$chlArr['tagnm']][] = $this->compactElement($chlArr);
			}
		}
		if( trim($ownText)!=='' ){
			$shrtArr['#text'] = $ownText;
		}
		return $shrtArr;
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
			$foundTl = $this->locateTitleTxt($chlArr);
			if( $foundTl!=='' ){
				return $foundTl;
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
		$blckLst = Array();
		$spanBuf = Array();
		foreach($childrenList as $chlArr){
			$tagName = $chlArr['tagnm'];
			if( in_array($tagName, self::SKIP_LIST) ){
				continue;
			}
			if( !in_array($tagName, self::BLCK_LIST) && !$this->holdsBlockFlag($chlArr) ){
				$spanBuf = array_merge($spanBuf, $this->inlineSpansArr($chlArr, Array(), ''));
				continue;
			}
			// A block starts: the text gathered so far is a paragraph of its own
			$tidySpn = $this->tidySpansArray($spanBuf);
			$spanBuf = Array();
			if( !empty($tidySpn) ){
				$blckLst[] = Shape::makeBlockArray('paragrph', $tidySpn);
			}
			$blckLst = array_merge($blckLst, in_array($tagName, self::BLCK_LIST) ? $this->tagBlocksArray($chlArr) : $this->readFlowsArray($this->kidsOfTagArray($chlArr)));
		}
		$tidySpn = $this->tidySpansArray($spanBuf);
		if( !empty($tidySpn) ){
			$blckLst[] = Shape::makeBlockArray('paragrph', $tidySpn);
		}
		return $blckLst;
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
		$tagName = $elementArray['tagnm'];
		if( $tagName=='#text' ){
			return Array(Shape::makeASpanArray($elementArray['value'], $runFormatSet, $linkGoalText));
		}
		if( $tagName=='br' ){
			return Array(Shape::makeASpanArray(self::LINE_MARK, $runFormatSet, $linkGoalText));
		}
		if( in_array($tagName, self::SKIP_LIST) ){
			return Array();
		}
		if( isset(self::MARK_TAGS[$tagName]) ){
			$runFormatSet[] = self::MARK_TAGS[$tagName];
		}
		if( $tagName=='a' && isset($elementArray['attrs']['href']) ){
			$linkGoalText = Shape::tidyLinkString($elementArray['attrs']['href']);
		}
		$spnsLst = Array();
		foreach($this->kidsOfTagArray($elementArray) as $chlArr){
			$spnsLst = array_merge($spnsLst, $this->inlineSpansArr($chlArr, $runFormatSet, $linkGoalText));
		}
		return $spnsLst;
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
		$tidyLst = Array();
		foreach($textSpanList as $oneSpn){
			$spanTxt = preg_replace('/\s+/', ' ', $oneSpn['texts']);
			$lastIdx = count($tidyLst)-1;
			$prevTxt = ($lastIdx>=0) ? $tidyLst[$lastIdx]['texts'] : '';
			// No space at the start of the block, after a space, or after a line break
			if( $prevTxt==='' || in_array(substr($prevTxt, -1), Array(' ', self::LINE_MARK)) ){
				$spanTxt = ltrim($spanTxt, ' ');
			}
			if( $spanTxt==='' ){
				continue;
			}
			if( $lastIdx>=0 && $tidyLst[$lastIdx]['marks']===$oneSpn['marks'] && $tidyLst[$lastIdx]['links']===$oneSpn['links'] ){
				$tidyLst[$lastIdx]['texts'] .= $spanTxt;
			}else{
				$tidyLst[] = Array('texts'=>$spanTxt, 'marks'=>$oneSpn['marks'], 'links'=>$oneSpn['links'], 'field'=>$oneSpn['field']);
			}
		}
		// Nothing is left at the end either, and the line breaks take their real form
		while( !empty($tidyLst) ){
			$lastIdx = count($tidyLst)-1;
			$tidyLst[$lastIdx]['texts'] = rtrim($tidyLst[$lastIdx]['texts'], ' '.self::LINE_MARK);
			if( $tidyLst[$lastIdx]['texts']!=='' ){
				break;
			}
			array_pop($tidyLst);
		}
		foreach($tidyLst as $spnIdx=>$oneSpn){
			$tidyLst[$spnIdx]['texts'] = str_replace(Array(' '.self::LINE_MARK, self::LINE_MARK), "\n", $oneSpn['texts']);
		}
		return $tidyLst;
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
		$rowsLst = Array();
		foreach($elementArray['nodes'] as $chlArr){
			if( in_array($chlArr['tagnm'], Array('thead', 'tbody', 'tfoot')) ){
				$rowsLst = array_merge($rowsLst, $this->rowsOfTableArr($chlArr, $chlArr['tagnm']=='thead'));
				continue;
			}
			if( $chlArr['tagnm']!='tr' ){
				continue;
			}
			$cellLst = Array();
			$headNum = 0;
			foreach($chlArr['nodes'] as $celArr){
				if( $celArr['tagnm']!='td' && $celArr['tagnm']!='th' ){
					continue;
				}
				$celAttr = Array();
				if( $celArr['tagnm']=='th' ){
					$headNum++;
					$celAttr['headr'] = true;
				}
				foreach(Array('colspan'=>'colsp', 'rowspan'=>'rowsp') as $atrNme=>$atrKey){
					if( intval($celArr['attrs'][$atrNme] ?? 1)>1 ){
						$celAttr[$atrKey] = intval($celArr['attrs'][$atrNme]);
					}
				}
				$celPart = $this->splitHoldArray($this->readFlowsArray($this->kidsOfTagArray($celArr)));
				$cellLst[] = Array($celPart[0], $celPart[1], $celAttr);
			}
			// A row of the head, or a row made of header cells only, is a header row
			$headRow = ($innerHeadFlg || ($headNum>0 && $headNum==count($cellLst)));
			$cellBlk = Array();
			foreach($cellLst as $celSet){
				if( $headRow ){
					unset($celSet[2]['headr']);
				}
				$cellBlk[] = Shape::makeBlockArray('cellText', $celSet[0], $celSet[1], $celSet[2]);
			}
			$rowsLst[] = Shape::makeBlockArray($headRow ? 'tableTop' : 'tableRow', Array(), $cellBlk);
		}
		return $rowsLst;
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
		$rawText = $elementArray['value'];
		foreach($elementArray['nodes'] as $chlArr){
			$rawText .= $this->elementRawText($chlArr);
		}
		return $rawText;
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
		$tagName = $elementArray['tagnm'];
		$idValue = ($elementArray['tagid']!=='') ? Array('tagid'=>$elementArray['tagid']) : Array();
		$blckLst = Array();
		if( preg_match('/^h([1-6])$/D', $tagName, $hdrPart) ){
			$hdrSpns = $this->tidySpansArray($this->inlineSpansArr(Array('tagnm'=>'span')+$elementArray, Array(), ''));
			if( !empty($hdrSpns) ){
				$blckLst[] = Shape::makeBlockArray('headline', $hdrSpns, Array(), Array('level'=>intval($hdrPart[1])) + $idValue);
			}
		}elseif( $tagName=='ul' || $tagName=='ol' ){
			$itemLst = Array();
			foreach($elementArray['nodes'] as $chlArr){
				if( $chlArr['tagnm']!='li' ){
					continue;
				}
				$entPart = $this->splitHoldArray($this->readFlowsArray($this->kidsOfTagArray($chlArr)));
				$itemLst[] = Shape::makeBlockArray('listItem', $entPart[0], $entPart[1], ($chlArr['tagid']!=='') ? Array('tagid'=>$chlArr['tagid']) : Array());
			}
			if( !empty($itemLst) ){
				$blckLst[] = Shape::makeBlockArray('itemList', Array(), $itemLst, Array('order'=>($tagName=='ol')) + $idValue);
			}
		}elseif( $tagName=='table' ){
			$rowsLst = $this->rowsOfTableArr($elementArray);
			if( !empty($rowsLst) ){
				$blckLst[] = Shape::makeBlockArray('tableSet', Array(), $rowsLst, $idValue);
			}
		}elseif( $tagName=='blockquote' ){
			$qutPart = $this->splitHoldArray($this->readFlowsArray($this->kidsOfTagArray($elementArray)));
			if( !empty($qutPart[0]) || !empty($qutPart[1]) ){
				$blckLst[] = Shape::makeBlockArray('citation', $qutPart[0], $qutPart[1], $idValue);
			}
		}elseif( $tagName=='pre' ){
			$codeTxt = trim($this->elementRawText($elementArray), "\r\n");
			$langPrt = Array();
			$codeAtr = $idValue;
			if( preg_match('/\blang(?:uage)?-([\w+#.\-]+)/', strval($elementArray['nodes'][0]['attrs']['class'] ?? $elementArray['attrs']['class'] ?? ''), $langPrt) ){
				$codeAtr = Array('langg'=>$langPrt[1]) + $codeAtr;
			}
			if( $codeTxt!=='' ){
				$blckLst[] = Shape::makeBlockArray('codeBlck', Array(Shape::makeASpanArray($codeTxt)), Array(), $codeAtr);
			}
		}elseif( $tagName=='hr' ){
			$blckLst[] = Shape::makeBlockArray('ruleLine', Array(), Array(), $idValue);
		}elseif( $tagName=='img' ){
			$imgLink = Shape::tidyLinkString(strval($elementArray['attrs']['src'] ?? ''));
			$imgText = trim(strval($elementArray['attrs']['alt'] ?? ''));
			if( $imgLink!=='' ){
				$blckLst[] = Shape::makeBlockArray('imageBox', ($imgText!=='') ? Array(Shape::makeASpanArray($imgText)) : Array(), Array(), Array('sourc'=>$imgLink) + $idValue);
			}
		}else{
			// A paragraph, or an element that only groups others: what it holds, in order
			$blckLst = $this->readFlowsArray($this->kidsOfTagArray($elementArray));
			if( !empty($blckLst) && !empty($idValue) && !isset($blckLst[0]['attrs']['tagid']) && $tagName=='p' ){
				$blckLst[0]['attrs'] = $blckLst[0]['attrs'] + $idValue;
			}
		}
		return $blckLst;
	}

}
